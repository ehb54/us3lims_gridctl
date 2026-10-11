<?php
/*
 * pick_cluster.php
 *
 * Choosing which real cluster a metascheduler-type submission actually runs
 * on. See METASCHEDULER-DESIGN.md for the full design and rationale; this
 * file is deliberately only the pure decision function and its small
 * supporting pieces, with no DB or SSH calls of its own, so every branch is a
 * plain-data unit test (tests/unit/gridctl/PickClusterTest.php).
 */

## Points added to a candidate's score when its probed status is 'warn'
## rather than 'up'. Large enough that a healthy candidate in the same tier
## always wins over a flaky one, small enough that a warn candidate is still
## preferred over falling through to the next tier -- a single bad probe on
## an otherwise-idle local cluster should not bounce a job to a billed
## national one.
const PICK_CLUSTER_WARN_PENALTY = 100000;

/**
 * True when a cluster_details entry is a metascheduler pseudo-entry (has a
 * "clusters" list to pick a real cluster from) rather than a real cluster.
 */
function pick_cluster_is_metascheduler_entry( $entry )
{
   return is_array( $entry )
          && array_key_exists( 'clusters', $entry )
          && is_array( $entry[ 'clusters' ] );
}

/**
 * Default thresholds, overridable from global_config.php. Kept as a function
 * (not a constant array) so a deployment can override individual keys via
 * $GLOBALS without having to restate every key.
 */
function pick_cluster_default_config()
{
   return array(
      ## queued+running at/above this on every local candidate means "local
      ## is busy enough to try national instead". 0 disables local overflow
      ## entirely (every job goes national), which is never the intended
      ## default; a deployment that wants that should say so explicitly.
      'saturation_threshold'   => isset( $GLOBALS[ 'global_cluster_saturation_threshold' ] )
                                  ? (int) $GLOBALS[ 'global_cluster_saturation_threshold' ] : 20,

      ## Reuses the same staleness ceiling cluster_status.php's own callers
      ## already apply to a cluster_status row, so one config key governs
      ## both "is this row too old to show in the UI" and "is this row too
      ## old to pick a cluster from" -- a deployment that retunes one case
      ## retunes the other, rather than the two silently drifting apart.
      'max_status_age_seconds' => isset( $GLOBALS[ 'global_cluster_status_max_age_seconds' ] )
                                  ? (int) $GLOBALS[ 'global_cluster_status_max_age_seconds' ] : 900,
   );
}

/**
 * One candidate's structural fit, mirroring jobsubmit.php's cluster_opt()
 * default-fallback semantics (read a per-cluster tuning key, fall back to a
 * default when the key or the cluster entry is absent) without depending on
 * that class: pick_cluster() is a free function with no object context, and
 * the lookup itself has no real logic to duplicate, just an array default.
 *
 * A requirement key that is null or absent is not checked -- the caller may
 * not have resolved job sizing yet at the point this runs, and "we don't
 * know" must never be treated as "it doesn't fit".
 */
function pick_cluster_meets_requirements( $cluster_entry, $job_requirements )
{
   $opt = function ( $key, $default ) use ( $cluster_entry )
   {
      return is_array( $cluster_entry ) && array_key_exists( $key, $cluster_entry )
             ? $cluster_entry[ $key ] : $default;
   };

   foreach ( array( 'maxproc', 'ppn', 'maxtime' ) as $key )
   {
      if ( ! isset( $job_requirements[ $key ] ) || $job_requirements[ $key ] === null )
      {
         continue;   ## not resolved yet / not asked: cannot fail this check
      }

      $limit = $opt( $key, null );

      if ( $limit !== null && (int) $job_requirements[ $key ] > (int) $limit )
      {
         return false;
      }
   }

   return true;
}

/**
 * Choose one real cluster from $candidates for a metascheduler submission.
 *
 * @param array    $candidates      Real cluster names, in configured order
 *                                  (the tie-break order).
 * @param array    $cluster_details The full $cluster_details array (tier,
 *                                  capability keys, per candidate).
 * @param array    $status_snapshot Rows from gfac.cluster_status, keyed by
 *                                  cluster name: ['status'=>'up'|'warn'|
 *                                  'down'|'unknown', 'queued'=>int,
 *                                  'running'=>int, 'time'=>int (unix ts)].
 *                                  A candidate with no entry here is treated
 *                                  as having no status data.
 * @param array    $job_requirements Optional structural requirements this
 *                                  job needs ('maxproc','ppn','maxtime');
 *                                  any key may be omitted or null.
 * @param array    $config          See pick_cluster_default_config().
 * @param int|null $now             Unix time to score staleness against;
 *                                  null means time(). Threaded through
 *                                  explicitly, the same way
 *                                  cluster_probe_outage_verdict() takes
 *                                  $now, so staleness is a plain unit test
 *                                  rather than something only reproducible
 *                                  by mocking time().
 * @param ?array   $authorized_clusters The submitting person's own
 *                                  cluster-shortname allowlist (the same
 *                                  list require_authorized_cluster() checks
 *                                  for a direct submission), or null to skip
 *                                  this check. Authorization is per real
 *                                  cluster, never inherited from being
 *                                  allowed to pick the metascheduler
 *                                  pseudo-entry itself -- membership in the
 *                                  pool is not membership in every machine
 *                                  behind it. Appended last, after $now, so
 *                                  existing positional callers/tests that
 *                                  predate this parameter are unaffected.
 *
 * @return array{chosen:?string, reason:string, tier:?string, scores:array}
 *         'scores' covers every candidate, eligible or not, each as
 *         ['eligible'=>bool, 'reason'=>?string, 'tier'=>?string,
 *          'status'=>?string, 'load'=>?int], so a decision is auditable from
 *         the log line alone (see pick_cluster_log_line()).
 */
function pick_cluster( array $candidates, array $cluster_details, array $status_snapshot,
                        array $job_requirements = array(), array $config = array(), $now = null,
                        $authorized_clusters = null )
{
   $config = array_merge( pick_cluster_default_config(), $config );
   $now    = $now === null ? time() : (int) $now;

   $scores = array();
   $by_tier = array( 'local' => array(), 'national' => array() );

   foreach ( $candidates as $name )
   {
      $entry = isset( $cluster_details[ $name ] ) ? $cluster_details[ $name ] : null;

      if ( $entry === null )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'not configured',
            'tier' => null, 'status' => null, 'load' => null );
         continue;
      }

      if ( array_key_exists( 'active', $entry ) && ! $entry[ 'active' ] )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'inactive',
            'tier' => isset( $entry[ 'tier' ] ) ? $entry[ 'tier' ] : null, 'status' => null, 'load' => null );
         continue;
      }

      ## Membership in the metascheduler pool is never itself authorization
      ## for a specific real cluster inside it -- each candidate is checked
      ## against the submitting person's own allowlist, same as a direct
      ## submission would be. $authorized_clusters === null means the caller
      ## chose to skip this (e.g. existing tests that predate this check);
      ## every other caller must pass the real list.
      if ( $authorized_clusters !== null && ! in_array( $name, $authorized_clusters, true ) )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'not authorized for this user',
            'tier' => isset( $entry[ 'tier' ] ) ? $entry[ 'tier' ] : null, 'status' => null, 'load' => null );
         continue;
      }

      $tier = isset( $entry[ 'tier' ] ) ? $entry[ 'tier' ] : null;

      ## Explicit metadata only (see METASCHEDULER-DESIGN.md section 1): a
      ## missing tier is a configuration gap to fix, never a guess to make.
      if ( $tier !== 'local' && $tier !== 'national' )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'tier not configured',
            'tier' => null, 'status' => null, 'load' => null );
         continue;
      }

      if ( ! pick_cluster_meets_requirements( $entry, $job_requirements ) )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'does not meet job requirements',
            'tier' => $tier, 'status' => null, 'load' => null );
         continue;
      }

      $row = isset( $status_snapshot[ $name ] ) ? $status_snapshot[ $name ] : null;

      if ( $row === null )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'no status data',
            'tier' => $tier, 'status' => null, 'load' => null );
         continue;
      }

      $status = isset( $row[ 'status' ] ) ? $row[ 'status' ] : 'unknown';

      if ( $status === 'down' )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'down',
            'tier' => $tier, 'status' => $status, 'load' => null );
         continue;
      }

      $age = $now - (int) ( isset( $row[ 'time' ] ) ? $row[ 'time' ] : 0 );

      if ( $age > $config[ 'max_status_age_seconds' ] )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'stale status',
            'tier' => $tier, 'status' => $status, 'load' => null );
         continue;
      }

      $load = (int) ( isset( $row[ 'queued' ] ) ? $row[ 'queued' ] : 0 )
            + (int) ( isset( $row[ 'running' ] ) ? $row[ 'running' ] : 0 );

      ## 'warn' stays eligible but is penalized in scoring, not excluded: a
      ## single flaky probe should not take a cluster out of rotation (the
      ## same judgment cluster_status.php's own warn-before-down ladder
      ## already encodes).
      $scored_load = $status === 'warn' ? $load + PICK_CLUSTER_WARN_PENALTY : $load;

      $scores[ $name ] = array( 'eligible' => true, 'reason' => null,
         'tier' => $tier, 'status' => $status, 'load' => $load );

      $by_tier[ $tier ][ $name ] = $scored_load;
   }

   $pool = array();
   $pool_tier = null;

   if ( ! empty( $by_tier[ 'local' ] )
        && min( $by_tier[ 'local' ] ) < $config[ 'saturation_threshold' ] )
   {
      $pool = $by_tier[ 'local' ];
      $pool_tier = 'local';
   }
   elseif ( ! empty( $by_tier[ 'national' ] ) )
   {
      $pool = $by_tier[ 'national' ];
      $pool_tier = 'national';
   }

   if ( empty( $pool ) )
   {
      return array( 'chosen' => null, 'reason' => 'no eligible cluster', 'tier' => null, 'scores' => $scores );
   }

   ## Deterministic tie-break: first-listed-wins. $pool preserves $candidates'
   ## insertion order, so the first minimum found is the first-configured one.
   $winner = null;
   $winner_score = null;

   foreach ( $pool as $name => $scored_load )
   {
      if ( $winner === null || $scored_load < $winner_score )
      {
         $winner = $name;
         $winner_score = $scored_load;
      }
   }

   return array( 'chosen' => $winner, 'reason' => 'lowest-load', 'tier' => $pool_tier, 'scores' => $scores );
}

/** One explainable log line for a pick_cluster() result. See section 4 of the design doc. */
function pick_cluster_log_line( $pseudo_cluster, array $result )
{
   $parts = array();

   foreach ( $result[ 'scores' ] as $name => $score )
   {
      $bits = array( 'eligible: ' . ( $score[ 'eligible' ] ? 'true' : 'false' ) );

      if ( $score[ 'eligible' ] )
      {
         $bits[] = 'tier: ' . $score[ 'tier' ];
         $bits[] = 'load: ' . $score[ 'load' ];
         $bits[] = 'status: ' . $score[ 'status' ];
      }
      else
      {
         $bits[] = 'reason: ' . $score[ 'reason' ];
      }

      $parts[] = "$name: {" . implode( ', ', $bits ) . '}';
   }

   $chosen = $result[ 'chosen' ] === null ? 'null' : $result[ 'chosen' ];

   return "pick_cluster($pseudo_cluster): chosen=$chosen reason={$result['reason']} scores={"
          . implode( ', ', $parts ) . '}';
}

/**
 * Read the live gfac.cluster_status rows for $candidates. The only
 * non-pure piece in this file; kept to one tiny function so
 * pick_cluster() itself never needs a DB handle to be tested.
 *
 * @return array keyed by cluster name, in the shape pick_cluster() expects.
 */
function pick_cluster_status_snapshot( $gfac_link, array $candidates )
{
   $snapshot = array();

   if ( empty( $candidates ) )
   {
      return $snapshot;
   }

   $quoted = array_map(
      function ( $name ) use ( $gfac_link ) { return "'" . mysqli_real_escape_string( $gfac_link, $name ) . "'"; },
      $candidates
   );

   $result = mysqli_query( $gfac_link,
      "SELECT cluster, status, queued, running, UNIX_TIMESTAMP(time) AS epoch"
      . " FROM gfac.cluster_status WHERE cluster IN (" . implode( ',', $quoted ) . ")" );

   if ( ! $result )
   {
      return $snapshot;   ## no rows learned: every candidate reads as "no status data"
   }

   while ( $row = mysqli_fetch_assoc( $result ) )
   {
      $snapshot[ $row[ 'cluster' ] ] = array(
         'status'  => $row[ 'status' ],
         'queued'  => (int) $row[ 'queued' ],
         'running' => (int) $row[ 'running' ],
         'time'    => (int) $row[ 'epoch' ],
      );
   }

   return $snapshot;
}
