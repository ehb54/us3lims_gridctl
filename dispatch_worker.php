<?php
/* Dispatch worker: the submitctl.php pass that claims ready dispatch requests
 * and starts one child per claim, and the child's attempt (select, stage,
 * submission gate, sbatch, outcome). Remote work comes in through $io so the
 * coordination runs the same against real clusters and test doubles. Needs
 * common's dispatch_ledger.php and dispatch_authorization.php and
 * slurm_assessment.php loaded. PHP 7.2 compatible. */

require_once __DIR__ . '/dispatch_select.php';

const DISPATCH_WORKER_LEASE_SECONDS   = 120;
const DISPATCH_WORKER_LANE_RETRY      = 10;
const DISPATCH_WORKER_MAX_RESELECTION = 2;
const DISPATCH_WORKER_FRESHNESS       = 15;

## The receipt spool: $global_dispatch_spool_dir, else lims/etc/dispatch-spool under the service account's home.
function dispatch_spool_dir()
{
   if ( ! empty( $GLOBALS[ 'global_dispatch_spool_dir' ] ) ) {
      return (string) $GLOBALS[ 'global_dispatch_spool_dir' ];
   }
   $info = function_exists( 'posix_getpwnam' ) ? posix_getpwnam( 'us3' ) : false;
   return ( $info ? $info[ 'dir' ] : '/home/us3' ) . '/lims/etc/dispatch-spool';
}

## Instance databases holding the dispatch tables.
function dispatch_worker_instances( $link )
{
   $rows = dispatch_rows( $link, "SELECT TABLE_SCHEMA FROM information_schema.TABLES
      WHERE TABLE_NAME = 'dispatch_request' AND TABLE_SCHEMA LIKE 'uslims3\\_%' ORDER BY TABLE_SCHEMA" );
   return array_column( $rows, 'TABLE_SCHEMA' );
}

## Waiting backoff: 30s doubling to 15 minutes, plus up to 5s jitter so retries spread out.
function dispatch_worker_backoff( $waits, $jitter = null )
{
   $base = 30 * ( 1 << min( max( 0, (int) $waits ), 5 ) );
   return min( 900, $base ) + ( $jitter !== null ? (int) $jitter : random_int( 0, 5 ) );
}

/**
 * One daemon pass: replay spooled receipts, hold submissions whose worker was
 * lost, expire overdue requests, then claim work oldest first, taking turns
 * across instances so none starves the others, at most $opts['per_instance']
 * each and $opts['capacity'] in all. Recovery of held and accepted requests
 * comes before new attempts. $spawn( $db, $id, $generation ) starts the child.
 * $opts['spool'] names the receipt spool directory. Returns the claims as "db:id".
 */
function dispatch_worker_pass( $link, array $dbs, array $opts, callable $spawn )
{
   $capacity = (int) $opts[ 'capacity' ];
   $per = isset( $opts[ 'per_instance' ] ) ? (int) $opts[ 'per_instance' ] : 4;
   $lease = isset( $opts[ 'lease' ] ) ? (int) $opts[ 'lease' ] : DISPATCH_WORKER_LEASE_SECONDS;
   $gdb = isset( $opts[ 'gdb' ] ) ? $opts[ 'gdb' ] : 'gfac';

   if ( ! empty( $opts[ 'spool' ] ) ) {
      dispatch_spool_replay( $link, $gdb, $opts[ 'spool' ] );
   }

   $queues = array();
   foreach ( $dbs as $db ) {
      dispatch_hold_lost_submissions( $link, $db );
      dispatch_expire_due( $link, $db );
      if ( $capacity > 0 ) {
         $queues[ $db ] = array();
         foreach ( dispatch_recovery_ready( $link, $db, $per ) as $id ) {
            $queues[ $db ][] = array( 'recover', $id );
         }
         foreach ( dispatch_ready( $link, $db, $per ) as $id ) {
            $queues[ $db ][] = array( 'attempt', $id );
         }
      }
   }

   $claimed = array();
   while ( $capacity > 0 && $queues ) {
      foreach ( array_keys( $queues ) as $db ) {
         $next = array_shift( $queues[ $db ] );
         if ( ! $queues[ $db ] ) {
            unset( $queues[ $db ] );
         }
         if ( $next === null || $capacity <= 0 ) {
            continue;
         }
         list( $kind, $id ) = $next;
         $gen = $kind === 'recover' ? dispatch_claim_recovery( $link, $db, $id, $opts[ 'owner' ], $lease )
                                    : dispatch_claim( $link, $db, $id, $opts[ 'owner' ], $lease );
         if ( $gen > 0 ) {
            $spawn( $db, $id, $gen );
            $claimed[] = "$db:$id";
            $capacity--;
         }
      }
   }
   return $claimed;
}

/** Take every lane in $keys or none: array of key => generation, or null when one is held elsewhere. */
function dispatch_worker_take_lanes( $link, $gdb, array $keys, $owner, $lease )
{
   $held = array();
   sort( $keys );
   foreach ( array_unique( $keys ) as $key ) {
      $gen = dispatch_controller_claim( $link, $gdb, $key, $owner, $lease );
      if ( $gen === 0 ) {
         dispatch_worker_release_lanes( $link, $gdb, $held, $owner );
         return null;
      }
      $held[ $key ] = $gen;
   }
   return $held;
}

function dispatch_worker_release_lanes( $link, $gdb, array $held, $owner )
{
   foreach ( $held as $key => $gen ) {
      dispatch_controller_release( $link, $gdb, $key, $owner, $gen );
   }
}

/**
 * Run one claimed attempt to its next resting state. $io:
 *   cluster_details  configuration read for this attempt
 *   configured       the instance's configured cluster names
 *   plans            fn( array $members, array $request ) => member => candidate plan
 *   assess           fn( array $plans, $allowance ) => member => observation
 *   stage            fn( array $plan ) => array( ok, transient, reason )
 *   submit           fn( array $plan, $token ) => submission outcome (SUBMIT_* certainty)
 *   failed           fn( $reason ) runs the existing failure reporting (optional)
 *   reachable        member => false for a member known to be down (optional)
 *   operator         member => 'down' | 'draining' from down_clusters.php (optional)
 *   submission_blocked  fn() => whether the site has blocked job submission (optional)
 *   batch_preflight  fn( $member, array $children ) => array( ok, failed ): every child of the
 *                    batch planned at $member before the batch is pinned there (optional)
 *   now              fn() => local time (optional)
 * Returns the state the request was left in, or 'lost' when the claim was lost.
 */
function dispatch_attempt_run( $link, $gdb, $db, $id, $generation, $owner, array $io )
{
   $lease = isset( $io[ 'lease' ] ) ? (int) $io[ 'lease' ] : DISPATCH_WORKER_LEASE_SECONDS;
   $now = isset( $io[ 'now' ] ) ? $io[ 'now' ] : 'time';
   $lane_owner = "$owner#$db#$id#$generation";
   $lanes = array();

   $req = dispatch_get( $link, $db, $id );
   if ( ! $req || $req[ 'state' ] !== DISPATCH_ASSESSING || (int) $req[ 'claimGeneration' ] !== (int) $generation
        || $req[ 'claimOwner' ] !== $owner ) {
      return 'lost';
   }

   $settle = function ( $from, $to, $reason, array $fields = array() ) use ( $link, $db, $id, $generation, $io, &$lanes, $gdb, $lane_owner ) {
      dispatch_worker_release_lanes( $link, $gdb, $lanes, $lane_owner );
      $lanes = array();
      if ( ! dispatch_transition( $link, $db, $id, $generation, $from, $to, $reason, $fields ) ) {
         return 'lost';
      }
      if ( $to === DISPATCH_FAILED && isset( $io[ 'failed' ] ) ) {
         $io[ 'failed' ]( $reason );
      }
      return $to;
   };
   $wait = function ( $from, $reason, $code ) use ( $settle, $link, $db, $id ) {
      return $settle( $from, DISPATCH_WAITING, $reason,
                      array( 'reasonCode' => $code,
                             'retry_seconds' => dispatch_worker_backoff( dispatch_state_entries( $link, $db, $id, DISPATCH_WAITING ) ) ) );
   };

   $policy = dispatch_pool_policy( $req[ 'pool' ], $io[ 'cluster_details' ] );
   if ( empty( $policy[ 'active' ] ) && dispatch_select( array( 'policy' => $policy ) )[ 'outcome' ] === SELECT_FAILED ) {
      ## The disabled reason, applied to every never-submitted request of the pool.
      dispatch_fail_disabled_pool( $link, $db, $req[ 'pool' ] );
      if ( isset( $io[ 'failed' ] ) ) {
         $io[ 'failed' ]( "metascheduler '{$req['pool']}' is disabled (active=false)" );
      }
      return DISPATCH_FAILED;
   }

   $members = array();
   foreach ( array( 'primary', 'overflow' ) as $g ) {
      if ( ! empty( $policy[ $g ][ 'members' ] ) ) {
         $members = array_merge( $members, $policy[ $g ][ 'members' ] );
      }
   }

   ## Current authorization and the site submission block, which exempts
   ## administrators (userlevel 4 and up) as the submit pages do.
   $blocked = false;
   $authorize = function () use ( $link, $db, $req, $members, $io, &$blocked ) {
      $person = dispatch_load_person_authorization( $link, $db, $req[ 'submitterID' ] );
      if ( $person === null ) {
         return null;
      }
      $blocked = isset( $io[ 'submission_blocked' ] ) && $io[ 'submission_blocked' ]() && $person[ 'userlevel' ] < 4;
      return dispatch_member_authorizations( $members, $person[ 'userlevel' ], $io[ 'configured' ],
                                             $io[ 'cluster_details' ], $person[ 'clusters' ] );
   };
   $block_reason = 'job submission is blocked at this site; waiting until it reopens';

   $auth = $authorize();
   if ( $auth === null ) {
      return $settle( DISPATCH_ASSESSING, DISPATCH_BLOCKED, 'the submitter no longer exists',
                      array( 'reasonCode' => 'no_permission' ) );
   }
   if ( $blocked ) {
      return $wait( DISPATCH_ASSESSING, $block_reason, 'site_blocked' );
   }

   $pinned = $req[ 'pinnedCluster' ] !== null ? $req[ 'pinnedCluster' ]
                                              : dispatch_batch_pin( $link, $db, $req[ 'parentBatchKey' ] );
   $availability = $policy[ 'admission' ] === 'start_within_allowance';

   ## One rotation lane per instance, pool and policy version: rotation state is
   ## read, chosen from and spent by one process at a time. Availability decisions
   ## also hold every candidate's scheduler until the outcome is known.
   $keys = array( dispatch_lane_rotation( $db, $req[ 'pool' ], $policy[ 'version' ] ) );
   if ( $availability ) {
      foreach ( $members as $m ) {
         if ( isset( $io[ 'cluster_details' ][ $m ] ) ) {
            $keys[] = dispatch_lane_controller( dispatch_member_controller( $io[ 'cluster_details' ][ $m ] ) );
         }
      }
   }
   $lanes = dispatch_worker_take_lanes( $link, $gdb, $keys, $lane_owner, $lease );
   if ( $lanes === null ) {
      $lanes = array();
      return $settle( DISPATCH_ASSESSING, DISPATCH_WAITING, 'another dispatch decision for this pool is in progress',
                      array( 'retry_seconds' => DISPATCH_WORKER_LANE_RETRY, 'reasonCode' => 'busy_deciding' ) );
   }
   $rotation_lane = $keys[ 0 ];
   if ( $pinned === null ) {
      $pinned = dispatch_batch_pin( $link, $db, $req[ 'parentBatchKey' ] );
   }

   $usable = array_values( array_filter( $members, function ( $m ) use ( $auth, $policy, $pinned ) {
      return ! empty( $auth[ $m ][ 'authorized' ] ) && ! isset( $policy[ 'ineligible' ][ $m ] )
             && ( $pinned === null || $m === $pinned );
   } ) );
   $plans = $usable ? $io[ 'plans' ]( $usable, $req ) : array();

   $state = DISPATCH_ASSESSING;
   for ( $round = 0; ; $round++ ) {
      $observations = $availability && $pinned === null && $plans
                    ? $io[ 'assess' ]( array_filter( $plans, function ( $p ) { return ! empty( $p[ 'ok' ] ); } ),
                                       $policy[ 'wait_allowance_seconds' ] )
                    : array();
      $scopes = array();
      $rotation = array();
      foreach ( array( 'primary', 'overflow' ) as $g ) {
         $scopes[ $g ] = dispatch_rotation_scope( $db, $req[ 'pool' ], $g, $policy[ 'version' ] );
         $rotation[ $g ] = dispatch_rotation_load( $link, $gdb, $scopes[ $g ] );
      }
      $decision = dispatch_select( array(
         'policy' => $policy, 'plans' => $plans, 'observations' => $observations, 'authorized' => $auth,
         'reachable' => isset( $io[ 'reachable' ] ) ? $io[ 'reachable' ] : array(),
         'operator' => isset( $io[ 'operator' ] ) ? $io[ 'operator' ] : array(),
         'rotation' => $rotation, 'pinned' => $pinned,
      ) );
      dispatch_record_decision( $link, $gdb, $db, $id, $req[ 'pool' ], $decision, dispatch_policy_summary( $policy ) );

      if ( $decision[ 'outcome' ] === SELECT_WAIT ) {
         return $wait( $state, $decision[ 'reason' ], $decision[ 'code' ] );
      }
      if ( $decision[ 'outcome' ] === SELECT_BLOCKED ) {
         return $settle( $state, DISPATCH_BLOCKED, $decision[ 'reason' ], array( 'reasonCode' => $decision[ 'code' ] ) );
      }
      if ( $decision[ 'outcome' ] === SELECT_FAILED ) {
         return $settle( $state, DISPATCH_FAILED, $decision[ 'reason' ], array( 'reasonCode' => $decision[ 'code' ] ) );
      }

      $chosen = $decision[ 'chosen' ];
      $plan = $plans[ $chosen ];
      $why = "{$decision['reason']}: $chosen";

      ## The first child to pin a batch checks every child's plan at that
      ## member first; a member some child cannot fit is excluded and the
      ## selection runs again.
      if ( $req[ 'parentBatchKey' ] !== null && $pinned === null && isset( $io[ 'batch_preflight' ] ) ) {
         $children = dispatch_batch_children( $link, $db, $req[ 'parentBatchKey' ] );
         $check = $io[ 'batch_preflight' ]( $chosen, $children );
         if ( empty( $check[ 'ok' ] ) ) {
            $plans[ $chosen ] = array( 'ok' => false, 'cluster' => $chosen,
                                       'error' => 'a child of this batch cannot run there: '
                                                  . implode( '; ', (array) $check[ 'failed' ] ) );
            continue;
         }
      }
      if ( ! dispatch_stage( $link, $db, $id, $generation, $chosen, $plan[ 'hash' ], $policy[ 'version' ], $why ) ) {
         dispatch_worker_release_lanes( $link, $gdb, $lanes, $lane_owner );
         return 'lost';
      }
      $state = DISPATCH_STAGING;
      dispatch_renew( $link, $db, $id, $generation, $owner, $lease );

      $staged = $io[ 'stage' ]( $plan );
      if ( ! dispatch_renew( $link, $db, $id, $generation, $owner, $lease ) ) {
         dispatch_worker_release_lanes( $link, $gdb, $lanes, $lane_owner );
         return 'lost';
      }
      if ( ! $staged[ 'ok' ] ) {
         return $staged[ 'transient' ] ? $wait( DISPATCH_STAGING, $staged[ 'reason' ], 'unreachable' )
                                       : $settle( DISPATCH_STAGING, DISPATCH_FAILED, $staged[ 'reason' ],
                                                  array( 'reasonCode' => 'staging_rejected' ) );
      }

      ## An availability choice made on an observation that aged during staging is
      ## checked again. A batch child is pinned once staged and never reselects.
      $obs = isset( $observations[ $chosen ] ) ? $observations[ $chosen ] : null;
      if ( $availability && $pinned === null && $req[ 'parentBatchKey' ] === null && $obs !== null
           && ! slurm_observation_is_fresh( $obs, $now(), DISPATCH_WORKER_FRESHNESS ) ) {
         $again = $io[ 'assess' ]( array( $chosen => $plan ), $policy[ 'wait_allowance_seconds' ] );
         if ( ! isset( $again[ $chosen ] ) || $again[ $chosen ][ 'verdict' ] !== SELECT_VERDICT_READY ) {
            if ( dispatch_state_entries( $link, $db, $id, DISPATCH_ASSESSING, DISPATCH_STAGING ) < DISPATCH_WORKER_MAX_RESELECTION ) {
               if ( ! dispatch_transition( $link, $db, $id, $generation, DISPATCH_STAGING, DISPATCH_ASSESSING,
                                           "$chosen can no longer start within the allowance; reselecting" ) ) {
                  dispatch_worker_release_lanes( $link, $gdb, $lanes, $lane_owner );
                  return 'lost';
               }
               $state = DISPATCH_ASSESSING;
               continue;
            }
            if ( $policy[ 'fallback' ] !== 'queue_preferred' ) {
               return $wait( DISPATCH_STAGING, "$chosen can no longer start within the allowance", 'occupied' );
            }
         }
      }
      break;
   }

   ## Authorization can be revoked while a request stages: reassess promptly,
   ## which picks another authorized member or blocks the request.
   $auth = $authorize();
   if ( $auth !== null && $blocked ) {
      return $wait( DISPATCH_STAGING, $block_reason, 'site_blocked' );
   }
   if ( $auth === null || empty( $auth[ $chosen ][ 'authorized' ] ) ) {
      return $settle( DISPATCH_STAGING, DISPATCH_WAITING,
                      $auth === null ? 'the submitter no longer exists' : $auth[ $chosen ][ 'reason' ],
                      array( 'retry_seconds' => 0, 'reasonCode' => 'no_permission' ) );
   }

   $group = $decision[ 'group' ];
   $unit = $req[ 'parentBatchKey' ] !== null ? $req[ 'parentBatchKey' ] : $req[ 'logicalKey' ];
   $turn = null;
   if ( isset( $decision[ 'rotation' ][ $group ] ) ) {
      $old = isset( $rotation[ $group ][ 'current' ][ $chosen ] ) ? (int) $rotation[ $group ][ 'current' ][ $chosen ] : 0;
      $w = isset( $policy[ $group ][ 'weights' ][ $chosen ] ) ? (int) $policy[ $group ][ 'weights' ][ $chosen ] : 1;
      $round_total = $old + $w - (int) $decision[ 'rotation' ][ $group ][ 'current' ][ $chosen ];
      $turn = array( $gdb, $scopes[ $group ], $unit, $chosen, $decision[ 'rotation' ][ $group ], $round_total );
   }

   $artifact = isset( $staged[ 'artifact' ] ) && $staged[ 'artifact' ] !== '' ? $staged[ 'artifact' ] : null;
   $token = dispatch_submission_gate( $link, $db, $id, $generation, $chosen, $plan, $turn,
                                      DISPATCH_SUBMIT_LEASE_SECONDS, $artifact );
   ## The rotation is spent and saved; other requests of the pool may decide now.
   dispatch_controller_release( $link, $gdb, $rotation_lane, $lane_owner, $lanes[ $rotation_lane ] );
   unset( $lanes[ $rotation_lane ] );
   if ( $token === '' ) {
      dispatch_worker_release_lanes( $link, $gdb, $lanes, $lane_owner );
      return 'lost';
   }

   $outcome = $io[ 'submit' ]( $plan, $token );
   $next = ! empty( $outcome[ 'retryable' ] ) ? DISPATCH_WAITING : DISPATCH_FAILED;
   $turn_ref = $turn !== null ? array( $turn[ 0 ], $turn[ 1 ], $turn[ 2 ] ) : null;
   $retry = $next === DISPATCH_WAITING ? dispatch_worker_backoff( dispatch_state_entries( $link, $db, $id, DISPATCH_WAITING ) ) : null;
   ## The receipt is stored before any tracking is written.
   $recorded = dispatch_record_outcome( $link, $db, $token, $outcome, $next, $turn_ref, $retry );
   dispatch_worker_release_lanes( $link, $gdb, $lanes, $lane_owner );
   if ( ! $recorded ) {
      ## sbatch ran but the ledger could not take its answer: keep it where a
      ## database outage cannot lose it. Without a spool the lease runs out and
      ## the request is held for reconciliation.
      if ( ! empty( $io[ 'spool' ] ) && dispatch_spool_write( $io[ 'spool' ], $db, $token, $outcome ) ) {
         return 'spooled';
      }
      return 'lost';
   }
   if ( $outcome[ 'certainty' ] === SUBMIT_ACCEPTED ) {
      $note = isset( $outcome[ 'comment_kept' ] ) && $outcome[ 'comment_kept' ] === false
            ? "; $chosen did not keep the attempt comment, so a lost reply there cannot be reconciled" : '';
      return dispatch_worker_track( $link, $db, $id, $generation, $plan, $outcome[ 'job_id' ], $io, $note );
   }
   if ( $outcome[ 'certainty' ] === SUBMIT_UNKNOWN ) {
      return DISPATCH_SUBMISSION_UNKNOWN;
   }
   if ( $next === DISPATCH_FAILED && isset( $io[ 'failed' ] ) ) {
      $io[ 'failed' ]( $outcome[ 'reason' ] );
   }
   return $next;
}


/**
 * Write tracking for an accepted job and attach its monitor through $io['track'],
 * then move the request to MONITORED. A failure keeps it ACCEPTED for a later
 * repair; it is never a reason to submit again.
 */
function dispatch_worker_track( $link, $db, $id, $generation, array $plan, $job_id, array $io, $note = '' )
{
   $ok = isset( $io[ 'track' ] ) && $io[ 'track' ]( $plan, $job_id );
   if ( $ok && dispatch_mark_monitored( $link, $db, $id, "tracking recorded and monitor attached$note" ) ) {
      $req = dispatch_get( $link, $db, $id );
      return (int) $req[ 'cancelRequested' ] === 1
           ? dispatch_worker_deliver_cancel( $link, $db, $id, (int) $req[ 'claimGeneration' ], $plan[ 'cluster' ], $job_id, $io )
           : DISPATCH_MONITORED;
   }
   dispatch_repair_later( $link, $db, $id, $generation,
                          "accepted as {$plan['cluster']} job $job_id; restoring tracking$note" );
   return DISPATCH_ACCEPTED;
}

/**
 * One recovery check for a claimed SUBMISSION_UNKNOWN, ACCEPTED or MONITORED
 * request. A held submission is looked up at its cluster by the attempt token;
 * exactly one match records the acceptance, anything else stays held. An
 * accepted request gets its tracking and monitor repaired. A requested
 * cancellation is delivered once the job is known. $io:
 *   find   fn( $cluster, $token ) => array( ok, jobs, reason ): jobs carrying the token
 *   track  fn( array $plan, $job_id ) => bool
 *   cancel fn( $cluster, $job_id ) => whether the scheduler confirmed the cancellation
 * Returns the state the request was left in, or 'lost'.
 */
function dispatch_recover_run( $link, $gdb, $db, $id, $generation, $owner, array $io )
{
   $req = dispatch_get( $link, $db, $id );
   if ( ! $req || (int) $req[ 'claimGeneration' ] !== (int) $generation || $req[ 'claimOwner' ] !== $owner ) {
      return 'lost';
   }
   $attempt = dispatch_latest_attempt( $link, $db, $id );
   if ( ! $attempt ) {
      return 'lost';
   }
   $plan = json_decode( $attempt[ 'planJson' ], true );
   $cluster = $attempt[ 'cluster' ];

   if ( $req[ 'state' ] === DISPATCH_SUBMISSION_UNKNOWN ) {
      $found = $io[ 'find' ]( $cluster, $attempt[ 'attemptToken' ] );
      $jobs = ! empty( $found[ 'ok' ] ) ? array_values( array_unique( $found[ 'jobs' ] ) ) : array();
      if ( empty( $found[ 'ok' ] ) || count( $jobs ) !== 1 ) {
         $why = empty( $found[ 'ok' ] ) ? "$cluster could not be queried: {$found['reason']}"
              : ( $jobs ? "more than one job at $cluster carries this attempt: " . implode( ', ', $jobs )
                        : "no job at $cluster carries this attempt; acceptance is not ruled out" );
         dispatch_reconcile_unresolved( $link, $db, $id, $generation, $why );
         return DISPATCH_SUBMISSION_UNKNOWN;
      }
      $outcome = array( 'certainty' => SUBMIT_ACCEPTED, 'retryable' => false, 'job_id' => $jobs[ 0 ],
                        'reason' => 'reconciled by attempt token' );
      if ( ! dispatch_record_outcome( $link, $db, $attempt[ 'attemptToken' ], $outcome, DISPATCH_WAITING,
                                      dispatch_turn_for_token( $link, $gdb, $attempt[ 'attemptToken' ] ) ) ) {
         return 'lost';
      }
      $job = $jobs[ 0 ];
   }
   elseif ( $req[ 'state' ] === DISPATCH_ACCEPTED ) {
      $job = $attempt[ 'schedulerJobID' ];
   }
   elseif ( $req[ 'state' ] === DISPATCH_MONITORED && (int) $req[ 'cancelRequested' ] === 1 ) {
      return dispatch_worker_deliver_cancel( $link, $db, $id, $generation, $cluster, $attempt[ 'schedulerJobID' ], $io );
   }
   else {
      return 'lost';
   }
   return dispatch_worker_track( $link, $db, $id, $generation, is_array( $plan ) ? $plan : array( 'cluster' => $cluster ),
                                 $job, $io );
}

/**
 * Cancel an accepted job whose cancellation was requested while it was being
 * submitted, through the existing remote cancellation ($io['cancel']). Only a
 * confirmed cancellation is recorded; otherwise it is tried again later and
 * the job keeps its status.
 */
function dispatch_worker_deliver_cancel( $link, $db, $id, $generation, $cluster, $job, array $io )
{
   if ( isset( $io[ 'cancel' ] ) && $io[ 'cancel' ]( $cluster, $job ) ) {
      dispatch_cancel_delivered( $link, $db, $id, $generation, $job );
   }
   else {
      dispatch_affected( $link, "UPDATE `$db`.dispatch_request SET claimOwner = NULL, claimExpiresAt = NULL,
            retryAt = UTC_TIMESTAMP() + INTERVAL 60 SECOND, reason = ?, updatedAt = UTC_TIMESTAMP()
         WHERE dispatchRequestID = ? AND claimGeneration = ?",
         'sii', array( "cancellation of job $job at $cluster was not confirmed; retrying", (int) $id, (int) $generation ) );
   }
   return DISPATCH_MONITORED;
}

## Job IDs in "id|comment" squeue lines whose comment is exactly $comment.
function dispatch_worker_jobs_with_comment( array $lines, $comment )
{
   $jobs = array();
   foreach ( $lines as $line ) {
      $parts = explode( '|', trim( $line ), 2 );
      if ( count( $parts ) === 2 && $parts[ 1 ] === $comment && preg_match( '/^\d+(_\d+)?$/', $parts[ 0 ] ) ) {
         $jobs[] = $parts[ 0 ];
      }
   }
   return $jobs;
}

/**
 * Upload directories left behind by an intake that rolled back: the analysis
 * files were written but the request was never recorded. A directory
 * qualifies only when it is older than $grace_seconds, holds one request XML
 * for a dispatch instance, its name (the request GUID) matches no
 * HPCAnalysisRequest there, and no dispatch request refers to it. The
 * submission roots are the ones dispatch requests already use.
 */
function dispatch_orphan_upload_dirs( $link, array $dbs, $grace_seconds, $now = null )
{
   $now = $now === null ? time() : (int) $now;
   $roots = array();
   foreach ( $dbs as $db ) {
      foreach ( dispatch_rows( $link, "SELECT DISTINCT inputRef FROM `$db`.dispatch_request WHERE inputRef <> ''" ) as $r ) {
         $roots[ dirname( dirname( $r[ 'inputRef' ] ) ) ] = true;
      }
   }
   $orphans = array();
   foreach ( array_keys( $roots ) as $root ) {
      foreach ( glob( "$root/*", GLOB_ONLYDIR ) ?: array() as $dir ) {
         $xml = glob( "$dir/hpcrequest-*.xml" ) ?: array();
         if ( filemtime( $dir ) > $now - $grace_seconds || count( $xml ) !== 1
              || ! preg_match( '/-(uslims3_[A-Za-z0-9_]+)-\d+\.xml$/', $xml[ 0 ], $m ) || ! in_array( $m[ 1 ], $dbs, true ) ) {
            continue;
         }
         $db = $m[ 1 ];
         $known = dispatch_rows( $link, "SELECT 1 FROM `$db`.HPCAnalysisRequest WHERE HPCAnalysisRequestGUID = ? LIMIT 1",
                                 's', array( basename( $dir ) ) );
         $used = dispatch_rows( $link, "SELECT 1 FROM `$db`.dispatch_request WHERE LEFT(inputRef, ?) = ? LIMIT 1",
                                'is', array( strlen( "$dir/" ), "$dir/" ) );
         if ( ! $known && ! $used ) {
            $orphans[] = $dir;
         }
      }
   }
   return $orphans;
}

## Remove one orphan upload directory: its files, then the directory. Never recurses.
function dispatch_remove_upload_dir( $dir )
{
   foreach ( scandir( $dir ) ?: array() as $f ) {
      if ( $f !== '.' && $f !== '..' && ( is_dir( "$dir/$f" ) || ! @unlink( "$dir/$f" ) ) ) {
         return false;
      }
   }
   return @rmdir( $dir );
}
