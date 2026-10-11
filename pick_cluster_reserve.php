<?php
/*
 * pick_cluster_reserve.php
 *
 * submitctl.php backgrounds one submitone.php per ready row with no
 * coordination between them (trailing '&' on the shell_exec, iterating
 * straight to the next row) -- so two jobs ready in the same poll cycle can
 * each independently snapshot gfac.cluster_status, both see the same
 * cluster idle, and both pick_cluster() to it. This wraps the snapshot +
 * decision in a MySQL GET_LOCK() so concurrent callers serialize on it, then
 * nudges the chosen cluster's own load up by one immediately, in a separate
 * gfac.cluster_status_nudge ledger (not cluster_status itself), so a second
 * decision made moments later -- before the real status probe's next poll
 * -- does not read the same stale "idle" picture. A nudge ages out of that
 * ledger on its own after nudge_ttl_seconds (see pick_cluster_record_nudge()
 * in pick_cluster.php): it only needs to outlive the gap between two
 * decisions in the same burst, never the full 12-minute gap until the next
 * real probe. Left to persist that long, a burst large enough to nudge
 * every candidate past saturation_threshold used to read as "every
 * candidate saturated" for the whole gap, even once every one of those jobs
 * had already finished and the real clusters were sitting idle.
 *
 * Both halves are best-effort, deliberately: a lock that cannot be acquired
 * falls through to an unlocked decision rather than refusing to submit the
 * job outright. GET_LOCK() is scoped to this one mysqli connection, so a
 * crashed caller never leaves the lock held.
 */
require_once __DIR__ . '/pick_cluster.php';

function pick_cluster_reserve( $gfac_link, $pseudo_cluster, array $candidates, array $cluster_details,
                                array $job_requirements = array(), array $config = array(), $now = null,
                                $authorized_clusters = null, $lock_timeout_seconds = 10 )
{
   ## A pool member that names no $cluster_details entry at all (a typo, a
   ## renamed or removed cluster) must be surfaced loudly, not just silently
   ## scored "not configured" by pick_cluster() -- that reason string is
   ## identical for a cluster that is merely deactivated, which is a normal,
   ## expected state, not a configuration bug. This never blocks the pool:
   ## pick_cluster() still runs against whichever members do exist.
   foreach ( $candidates as $member )
   {
      if ( ! array_key_exists( $member, $cluster_details ) )
      {
         error_log( "pick_cluster_reserve: metascheduler '$pseudo_cluster' lists '$member' in its"
                  . " 'clusters', but no such cluster exists in \$cluster_details -- check for a typo"
                  . " or a renamed/removed entry" );
      }
   }

   ## MySQL/MariaDB GET_LOCK() names are capped at 64 bytes.
   $lock_name = substr( 'pick_cluster:' . $pseudo_cluster, 0, 64 );
   $locked    = false;

   ## Caught, not just checked for a false prepare()/execute(): see
   ## pick_cluster_status_snapshot()'s own comment -- the interactive path
   ## reaches this function without autoflow's MYSQLI_REPORT_OFF, so a real
   ## failure here throws there instead of returning false. Logged only in
   ## the catch, deliberately: $got !== 1 (another caller already holds the
   ## lock, or the timeout elapsed) is normal, expected contention, not an
   ## error, and must stay silent the way it always has -- only a genuine
   ## failure to even ask (a dead connection, a permissions change) is worth
   ## a log line, so it is distinguishable from ordinary contention instead
   ## of both reaching here as the same silent "fell through unlocked".
   try
   {
      $stmt = mysqli_prepare( $gfac_link, 'SELECT GET_LOCK(?, ?)' );
      if ( $stmt )
      {
         mysqli_stmt_bind_param( $stmt, 'si', $lock_name, $lock_timeout_seconds );
         if ( mysqli_stmt_execute( $stmt ) )
         {
            mysqli_stmt_bind_result( $stmt, $got );
            if ( mysqli_stmt_fetch( $stmt ) )
            {
               $locked = ( $got === 1 );
            }
         }
         mysqli_stmt_close( $stmt );
      }
      else
      {
         error_log( "pick_cluster_reserve: GET_LOCK prepare failed for '$lock_name': " . mysqli_error( $gfac_link )
                  . ' -- falling through to an unlocked decision' );
      }
   }
   catch ( \Throwable $e )
   {
      error_log( "pick_cluster_reserve: GET_LOCK failed for '$lock_name': " . $e->getMessage()
               . ' -- falling through to an unlocked decision' );
   }

   $now           = $now === null ? time() : (int) $now;
   $merged_config = array_merge( pick_cluster_default_config(), $config );

   ## try/finally, not the sequential run-then-unlock below it used to be: this
   ## codebase's mysqli default report mode throws on a query error, and
   ## pick_cluster_status_snapshot()/pick_cluster()/pick_cluster_record_nudge()
   ## all run queries -- an exception from any of them used to skip RELEASE_LOCK()
   ## entirely, leaving this function's only unlock path to be the caller's
   ## eventual mysqli_close() (which does auto-release every lock held on that
   ## connection, but only once the connection actually closes, not pooled or
   ## reused -- see db_connection_pool.php's own doc comment on why this
   ## connection is never given to it). Guaranteeing the explicit unlock here
   ## too means a transient query error no longer depends on that connection
   ## being closed promptly to let the next decision through.
   try
   {
      $snapshot = pick_cluster_status_snapshot( $gfac_link, $candidates, $now, $merged_config[ 'nudge_ttl_seconds' ] );
      $result   = pick_cluster( $candidates, $cluster_details, $snapshot, $job_requirements, $config, $now, $authorized_clusters );

      if ( $result[ 'chosen' ] !== null )
      {
         ## Best-effort nudge -- never fails the submission if this write fails.
         pick_cluster_record_nudge( $gfac_link, $result[ 'chosen' ], $now, $merged_config[ 'nudge_ttl_seconds' ] );
      }
   }
   finally
   {
      if ( $locked )
      {
         ## Caught, not left to propagate: this finally can already be
         ## running because the try block above threw, and an exception
         ## escaping a finally replaces that original one -- the caller
         ## would see "RELEASE_LOCK failed" and never learn what actually
         ## went wrong with the decision itself. Logged either way, since
         ## a lock that was genuinely acquired but never explicitly
         ## released still needs a trace of why, even though MySQL's own
         ## session-scoped auto-release (see this file's header comment)
         ## is the backstop that keeps it from being held forever.
         try
         {
            $unlock_stmt = mysqli_prepare( $gfac_link, 'SELECT RELEASE_LOCK(?)' );
            if ( $unlock_stmt )
            {
               mysqli_stmt_bind_param( $unlock_stmt, 's', $lock_name );
               mysqli_stmt_execute( $unlock_stmt );
               mysqli_stmt_close( $unlock_stmt );
            }
            else
            {
               error_log( "pick_cluster_reserve: RELEASE_LOCK prepare failed for '$lock_name': "
                        . mysqli_error( $gfac_link ) );
            }
         }
         catch ( \Throwable $e )
         {
            error_log( "pick_cluster_reserve: RELEASE_LOCK failed for '$lock_name': " . $e->getMessage() );
         }
      }
   }

   pick_cluster_record_decision( $gfac_link, $pseudo_cluster, $result );

   return $result;
}

## Persist one decision into gfac.metascheduler_decision so an operator can
## query "why did job X land on cluster Y" after the fact, instead of only
## having pick_cluster_log_line()'s text in a flat log file. Best-effort: a
## failed insert here never fails the submission itself.
function pick_cluster_record_decision( $gfac_link, $pseudo_cluster, array $result )
{
   ## Caught, same reason as every other best-effort write in this file: the
   ## interactive path has no MYSQLI_REPORT_OFF, so a failure here would
   ## otherwise throw instead of just skipping the record as it always has.
   try
   {
      $stmt = mysqli_prepare( $gfac_link,
         'INSERT INTO gfac.metascheduler_decision (pseudo_cluster, chosen, reason, tier, scores)'
         . ' VALUES (?, ?, ?, ?, ?)' );

      if ( ! $stmt )
      {
         return;
      }

      $chosen = $result[ 'chosen' ] ?? null;
      $reason = $result[ 'reason' ] ?? null;
      $tier   = $result[ 'tier' ] ?? null;
      $scores = json_encode( $result[ 'scores' ] ?? array() );

      mysqli_stmt_bind_param( $stmt, 'sssss', $pseudo_cluster, $chosen, $reason, $tier, $scores );
      mysqli_stmt_execute( $stmt );
      mysqli_stmt_close( $stmt );
   }
   catch ( \Throwable $e )
   {
      error_log( 'pick_cluster_record_decision: ' . $e->getMessage() );
   }
}
