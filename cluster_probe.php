<?php
/*
 * cluster_probe.php
 *
 * Asking a cluster about a job, and acting on the answer. One implementation,
 * shared by the gridctl cron sweep and the per-job jobmonitor daemon.
 *
 * THE RULE THIS FILE ENFORCES
 *
 * A job status is a statement about the JOB. If we could not reach the
 * cluster, we have no such statement, and the correct action is to change
 * nothing and try again. That case has its own name, UNREACHABLE, and it is
 * never mapped onto a job state. Callers must treat it as "no information",
 * never as "bad news": folding transport failure into a job status marks
 * healthy queued jobs as errored for the length of a site outage, and starts
 * the stall clock running against them.
 */

## $class_dir comes from listen-config.php (gridctl) or config.php (web).
## Guarded so this file can also be pulled into a test process that has
## already loaded the class by another route.
if ( ! class_exists( 'remote_exec' ) )
{
   require_once $class_dir . 'remote_exec.php';
}

## Returned when the cluster could not be reached or did not answer in time.
## Deliberately NOT one of the job-tracking status values: this is a fact about
## the infrastructure and must never be written to a job's status column.
const GRIDCTL_UNREACHABLE = 'UNREACHABLE';

## Returned when the cluster answered and does not know this job -- it
## finished long enough ago to age out of the scheduler's records, or the ID
## is bad. This is a real answer and callers may act on it.
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
 * Ask the cluster what state a job is in.
 *
 * Returns a normalised state: SUBMITTED, ACTIVE, COMPLETED, CANCELED, FAILED,
 * GRIDCTL_UNKNOWN, or GRIDCTL_UNREACHABLE.
 *
 * Uses `squeue -h -o %T` rather than squeue's default table. The old code
 * parsed a fixed column out of the human-readable table, which coupled job
 * status to squeue's presentation format and made every transport error look
 * like a malformed job state. -h drops the header, -o %T prints the job state
 * and nothing else, so the value either is a state name or the call failed.
 */
function cluster_probe_job_status( $cluster, $gfacID, $log_fn = null )
{
   global $cluster_details;

   $log = is_callable( $log_fn ) ? $log_fn : 'error_log';

   if ( ! isset( $cluster_details[ $cluster ] ) || ! isset( $cluster_details[ $cluster ][ 'name' ] ) )
   {
      ## A configuration error, not an outage. Retrying will not help and the
      ## job cannot be tracked, so report it as an answer rather than silently
      ## holding the job open forever.
      $log( "cluster_probe: cluster '$cluster' missing from global_config.php \$cluster_details" );
      return GRIDCTL_UNKNOWN;
   }

   $rx  = cluster_probe_remote( $cluster, $log );
   $res = $rx->run( "squeue -h -o %T -t all -j " . escapeshellarg( $gfacID ), array( 'label' => "status $gfacID" ) );

   return cluster_probe_status_from_result( $res, $cluster, $gfacID, $log );
}

/**
 * Turn one remote_exec result into a job state.
 *
 * Split out from cluster_probe_job_status() so the decision table -- the part
 * that got a site's worth of jobs errored out when it was wrong -- can be
 * tested exhaustively without a cluster, a shell, or a network.
 */
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
      ## The cluster answered and refused. "Invalid job id" is Slurm's way of
      ## saying the job is not in its records at all -- a real answer.
      if ( preg_match( '/invalid job id/i', $res[ 'stderr' ] ) )
      {
         $log( "cluster_probe: $cluster reports $gfacID is not a known job" );
         return GRIDCTL_UNKNOWN;
      }

      ## Anything else from a reachable cluster is a squeue we cannot interpret.
      ## Treating it as UNREACHABLE is the conservative read: it holds the job
      ## rather than failing it on the strength of an answer we do not understand.
      $log( "cluster_probe: $cluster returned an unrecognised squeue failure for $gfacID"
            . " (exit={$res['exit_code']}): {$res['stderr']}" );
      return GRIDCTL_UNREACHABLE;
   }

   $jstat = strtoupper( trim( $res[ 'text' ] ) );

   ## Empty output from a successful squeue -t all means the job has aged out
   ## of the scheduler's records (past MinJobAge). That is genuine information
   ## -- the job is gone -- and is distinct from not having been able to ask.
   if ( $jstat === '' )
   {
      $log( "cluster_probe: $cluster has no record of $gfacID (aged out of the queue)" );
      return GRIDCTL_UNKNOWN;
   }

   return cluster_probe_normalise_state( $jstat, $log );
}

/**
 * Map a Slurm state to the vocabulary gridctl's update_job_status() speaks.
 *
 * Both the long names (`squeue -o %T`) and compact state codes are accepted,
 * so this keeps working if a call site is ever switched to `squeue -o %t`.
 */
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

   ## A state name we do not recognise came back from a reachable cluster.
   ## Hold the job rather than guessing: a new Slurm state should not cause
   ## jobs to be failed by a LIMS that has not been taught about it yet.
   $log( "cluster_probe: unrecognised job state '$jstat'; holding job" );

   return GRIDCTL_UNREACHABLE;
}

/**
 * Cancel a job on the cluster. Returns true only if scancel was actually
 * delivered -- a caller that cancels on timeout must not record the job as
 * cancelled when the cancel never reached the scheduler.
 */
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

/**
 * Is the cluster answering at all right now?
 *
 * Used to disambiguate a failed job-status call, and by the health probe.
 * Short-budgeted and unretried on purpose: a reachability check that retries
 * for a minute reports history, not the present.
 */
function cluster_probe_reachable( $cluster, $log_fn = null )
{
   $res = cluster_probe_remote( $cluster, $log_fn )->ping();

   return $res[ 'ok' ];
}

/**
 * The abandonment-ceiling decision, as a pure function of its inputs.
 *
 * Returns 'proceed', 'defer' or 'abandon'. Both gridctl copies wrap this with
 * their own logging, queue-message and database side effects; the arithmetic
 * that decides a job's fate lives here, where it can be tested without a
 * cluster, a database or a clock.
 *
 * The three cases:
 *
 *   $reachable            the cluster is answering, so elapsed time means what
 *                         it says. 'proceed' to the normal timeout handling.
 *   within the ceiling    the cluster is not answering. Elapsed time proves
 *                         nothing about the job -- during an outage the jobs
 *                         are usually fine and the LIMS is blind -- so 'defer'
 *                         and look again next pass.
 *   past the ceiling      the cluster has been silent long enough that waiting
 *                         is no longer useful. 'abandon' and close the job out.
 *
 * $abandon_hours <= 0 means "defer indefinitely", for a site that would rather
 * hold jobs forever than close any out unseen. That was the behaviour before
 * the ceiling existed, and it is a defensible choice, just not a safe default:
 * a decommissioned or renamed cluster would hold its jobs in 'submitted'
 * permanently, where no operator would ever see them.
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
