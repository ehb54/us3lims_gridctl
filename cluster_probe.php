<?php
/*
 * cluster_probe.php
 *
 * Asking a cluster about a job, shared by the cron sweep and the daemon.
 *
 * A failure to reach the cluster is reported as UNREACHABLE, never as a job
 * state. Callers treat it as "no information" and change nothing.
 */

## $class_dir comes from listen-config.php (gridctl) or config.php (web).
if ( ! class_exists( 'remote_exec' ) )
{
   require_once $class_dir . 'remote_exec.php';
}

## The cluster could not be asked. Never written to a job's status column.
const GRIDCTL_UNREACHABLE = 'UNREACHABLE';

## The cluster answered and does not know this job (aged out, or a bad ID).
const GRIDCTL_UNKNOWN = 'UNKNOWN';

/**
 * Build a remote_exec for a cluster, wired to the caller's logger.
 */
function cluster_probe_remote( $cluster, $log_fn = null )
{
   global $cluster_details;

   $log = is_callable( $log_fn ) ? $log_fn : 'error_log';

   return new remote_exec( $cluster, is_array( $cluster_details ) ? $cluster_details : array(), $log );
}

/**
 * Ask the cluster what state a job is in: SUBMITTED, ACTIVE, COMPLETED,
 * CANCELED, FAILED, GRIDCTL_UNKNOWN or GRIDCTL_UNREACHABLE.
 */
function cluster_probe_job_status( $cluster, $gfacID, $log_fn = null )
{
   global $cluster_details;

   $log = is_callable( $log_fn ) ? $log_fn : 'error_log';

   if ( ! isset( $cluster_details[ $cluster ] ) || ! isset( $cluster_details[ $cluster ][ 'name' ] ) )
   {
      ## A configuration error: retrying will not help, so this is an answer.
      $log( "cluster_probe: cluster '$cluster' missing from global_config.php \$cluster_details" );
      return GRIDCTL_UNKNOWN;
   }

   $rx  = cluster_probe_remote( $cluster, $log );
   $res = $rx->run( "squeue -h -o %T -t all -j " . escapeshellarg( $gfacID ), array( 'label' => "status $gfacID" ) );

   return cluster_probe_status_from_result( $res, $cluster, $gfacID, $log );
}

/** Turn one remote_exec result into a job state. Pure, for testing. */
function cluster_probe_status_from_result( $res, $cluster, $gfacID, $log_fn = null )
{
   $log = is_callable( $log_fn ) ? $log_fn : 'error_log';

   if ( remote_exec_infra_fault( $res ) )
   {
      $log( "cluster_probe: $cluster unreachable while checking $gfacID"
            . " ({$res['class']} after {$res['attempts']} attempt(s)): {$res['stderr']}" );
      return GRIDCTL_UNREACHABLE;
   }

   if ( ! $res[ 'ok' ] )
   {
      ## "Invalid job id": Slurm has no record of the job.
      if ( preg_match( '/invalid job id/i', $res[ 'stderr' ] ) )
      {
         $log( "cluster_probe: $cluster reports $gfacID is not a known job" );
         return GRIDCTL_UNKNOWN;
      }

      ## Any other failure we cannot interpret: hold the job rather than fail it.
      $log( "cluster_probe: $cluster returned an unrecognised squeue failure for $gfacID"
            . " (exit={$res['exit_code']}): {$res['stderr']}" );
      return GRIDCTL_UNREACHABLE;
   }

   $jstat = strtoupper( trim( $res[ 'text' ] ) );

   ## Empty output: the job has aged out of the scheduler's records.
   if ( $jstat === '' )
   {
      $log( "cluster_probe: $cluster has no record of $gfacID (aged out of the queue)" );
      return GRIDCTL_UNKNOWN;
   }

   return cluster_probe_normalise_state( $jstat, $log );
}

/** Map a Slurm state (long name or compact code) to gridctl's vocabulary. */
function cluster_probe_normalise_state( $jstat, $log_fn = null )
{
   $log = is_callable( $log_fn ) ? $log_fn : 'error_log';

   switch ( $jstat )
   {
      case 'RUNNING'    :
      case 'R'          :
      case 'COMPLETING' :
      case 'CG'         :
      case 'E'          :   ## exiting after having run
      case 'W'          :   ## waiting for its start time
         return 'ACTIVE';

      case 'COMPLETED'  :
      case 'CD'         :
      case 'C'          :
      case 'ST'         :
         return 'COMPLETED';

      case 'PENDING'    :
      case 'PD'         :
      case 'CONFIGURING':
      case 'CF'         :
      case 'SUSPENDED'  :
      case 'S'          :
      case 'H'          :   ## held
      case 'Q'          :   ## queued
      case 'T'          :   ## being moved
         return 'SUBMITTED';

      case 'CANCELLED'  :
      case 'CANCELED'   :
      case 'CA'         :
         return 'CANCELED';

      case 'FAILED'     :
      case 'F'          :
      case 'BOOT_FAIL'  :
      case 'BF'         :
      case 'NODE_FAIL'  :
      case 'NF'         :
      case 'TIMEOUT'    :
      case 'TO'         :
      case 'OUT_OF_MEMORY' :
      case 'OOM'        :
      case 'DEADLINE'   :
      case 'DL'         :
      case 'PREEMPTED'  :
      case 'PR'         :
      case 'REVOKED'    :
      case 'RV'         :
         return 'FAILED';
   }

   ## Unrecognised state: hold the job rather than guess.
   $log( "cluster_probe: unrecognised job state '$jstat'; holding job" );

   return GRIDCTL_UNREACHABLE;
}

/** Cancel a job on the cluster. True only if scancel was actually delivered. */
function cluster_probe_cancel_job( $cluster, $gfacID, $log_fn = null )
{
   $log = is_callable( $log_fn ) ? $log_fn : 'error_log';

   $rx  = cluster_probe_remote( $cluster, $log );
   $res = $rx->run( "scancel " . escapeshellarg( $gfacID ), array( 'label' => "scancel $gfacID" ) );

   if ( $res[ 'ok' ] )
   {
      $log( "cluster_probe: scancel $gfacID delivered to $cluster" );
      return true;
   }

   $log( "cluster_probe: scancel $gfacID FAILED on $cluster"
         . " (class={$res['class']} exit={$res['exit_code']}): {$res['stderr']}" );

   return false;
}

/** Is the cluster answering right now? Short and unretried on purpose. */
function cluster_probe_reachable( $cluster, $log_fn = null )
{
   $res = cluster_probe_remote( $cluster, $log_fn )->ping();

   return $res[ 'ok' ];
}

/**
 * May a stall timeout fire? Returns 'proceed', 'defer' or 'abandon'.
 *
 * An outage stops status updates, so every waiting job looks hung. While the
 * cluster is unreachable the timeout is deferred, up to $abandon_hours since
 * $updatetime; after that the job is abandoned so a cluster that never comes
 * back does not hold its jobs forever. $abandon_hours <= 0 defers forever.
 */
function cluster_probe_outage_verdict( $reachable, $updatetime, $abandon_hours, $now )
{
   if ( $reachable )
      return 'proceed';

   $ceiling = (int) $abandon_hours * 60 * 60;

   if ( $ceiling <= 0 )
      return 'defer';

   return ( (int) $now - (int) $updatetime ) >= $ceiling ? 'abandon' : 'defer';
}
