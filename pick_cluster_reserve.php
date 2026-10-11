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
 * nudges the chosen cluster's own queued count up by one immediately, so a
 * second decision made moments later -- before the real status probe's next
 * poll overwrites it -- does not read the same stale "idle" picture.
 *
 * Both halves are best-effort, deliberately: a lock that cannot be acquired
 * falls through to an unlocked decision rather than refusing to submit the
 * job outright, and the nudge is overwritten by the next real probe
 * regardless. GET_LOCK() is scoped to this one mysqli connection, so a
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

   $snapshot = pick_cluster_status_snapshot( $gfac_link, $candidates );
   $result   = pick_cluster( $candidates, $cluster_details, $snapshot, $job_requirements, $config, $now, $authorized_clusters );

   if ( $result[ 'chosen' ] !== null )
   {
      ## Best-effort nudge -- never fails the submission if this update fails.
      $chosen = mysqli_real_escape_string( $gfac_link, $result[ 'chosen' ] );
      mysqli_query( $gfac_link, "UPDATE gfac.cluster_status SET queued = queued + 1 WHERE cluster = '$chosen'" );
   }

   if ( $locked )
   {
      $unlock_stmt = mysqli_prepare( $gfac_link, 'SELECT RELEASE_LOCK(?)' );
      if ( $unlock_stmt )
      {
         mysqli_stmt_bind_param( $unlock_stmt, 's', $lock_name );
         mysqli_stmt_execute( $unlock_stmt );
         mysqli_stmt_close( $unlock_stmt );
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
