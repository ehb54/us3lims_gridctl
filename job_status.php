<?php
/*
 * job_status.php
 *
 * The four job-status vocabularies, and the maps between them.
 *
 * WHY THIS FILE EXISTS
 *
 * A job's state is recorded in four places, and each of them speaks a
 * different language for good reason:
 *
 *   SCHEDULER  transient          what the cluster says right now
 *   JOB        gfac.analysis.status            the LIMS record of one HPC job
 *   STAGE      autoflowAnalysis.status         where a multi-stage pipeline is
 *   USER       HPCAnalysisResult.queueStatus   what the scientist sees
 *
 * Collapsing them would lose information. UNREACHABLE is meaningless to a
 * scientist; 'queued' is meaningless to a scheduler. The layers are real, so
 * the boundaries between them have to be explicit: a value that leaks sideways
 * into another layer's column matches nothing there, and every reader of that
 * column then fails in its own silent way.
 *
 * THE RULES THIS FILE ENFORCES
 *
 *   1. Each vocabulary is declared here, once, in full.
 *   2. Every boundary has an explicit map, and every map is total: each source
 *      value has a defined target, including an explicit "write nothing".
 *   3. An unmapped value is logged, never silently dropped.
 *   4. No value is written to a column belonging to another layer. Callers
 *      pass a JOB status and receive the target layer's own word for it.
 *   5. Every state is actionable. A status nothing writes, or that no reader
 *      recognises, is a bug and is listed as such below rather than left in.
 */

## --------------------------------------------------------------------- ##
## Layer 1: SCHEDULER. What cluster_probe.php reports after asking the
## cluster. Transient: never stored anywhere.
## --------------------------------------------------------------------- ##

const JOB_STATUS_SCHEDULER = array(
   'SUBMITTED',    ## queued at the scheduler, not started
   'ACTIVE',       ## running
   'COMPLETED',    ## finished
   'CANCELED',     ## cancelled at the scheduler
   'FAILED',       ## finished badly
   'UNKNOWN',      ## the cluster answered and has no record of this job
   'UNREACHABLE',  ## we could not ask. NOT a job state.
);

## --------------------------------------------------------------------- ##
## Layer 2: JOB. gfac.analysis.status, an ENUM. The LIMS's own record of a
## single HPC job, and the vocabulary every other map takes as its input.
##
## The database ENUM deliberately retains the deprecated DATA_TIMEOUT,
## FAILED_DATA and CANCELLED values solely for compatibility with historical
## rows and installations. They are not canonical write values: new code uses
## the smaller vocabulary below and normalises the two-L spelling to CANCELED.
## --------------------------------------------------------------------- ##

const JOB_STATUS_JOB = array(
   'SUBMITTED',       ## accepted by the scheduler, waiting to start
   'SUBMIT_TIMEOUT',  ## queued past the configured stall window
   'RUNNING',         ## executing
   'RUN_TIMEOUT',     ## running past the configured stall window
   'DATA',            ## finished, waiting for its data to be collected
   'COMPLETE',        ## finished successfully, data in hand
   'CANCELED',        ## cancelled, by a user or by a timeout
   'FAILED',          ## the job itself failed
   'ERROR',           ## the LIMS could not make sense of the job
);

## --------------------------------------------------------------------- ##
## Layer 3: STAGE. autoflowAnalysis.status, free text, read by submitctl.php
## which lowercases it and matches against fixed lists. Only these words do
## anything; anything else reads as "still in progress".
## --------------------------------------------------------------------- ##

const JOB_STATUS_STAGE_COMPLETE = 'complete';   ## advance to the next stage
const JOB_STATUS_STAGE_FAILED   = 'failed';     ## stop the pipeline
const JOB_STATUS_STAGE_CANCELED = 'canceled';   ## stop the pipeline
const JOB_STATUS_STAGE_RUNNING  = 'running';    ## no match: still in progress
const JOB_STATUS_STAGE_QUEUED   = 'submitted';  ## no match: still in progress

## --------------------------------------------------------------------- ##
## Layer 4: USER. HPCAnalysisResult.queueStatus, an ENUM. The only one of the
## four a scientist ever sees.
## --------------------------------------------------------------------- ##

const JOB_STATUS_USER = array( 'queued', 'running', 'aborted', 'failed', 'completed' );

/**
 * Normalise anything that claims to be a job status into the JOB vocabulary.
 *
 * Takes SCHEDULER values, JOB values, and the historical aliases that reach
 * update_job_status() from older code paths. Returns null for "write nothing",
 * which is a real answer and not a failure: UNREACHABLE means we never got to
 * ask, and EXECUTING and PROCESSING are progress chatter with no JOB
 * equivalent.
 *
 * Anything unrecognised returns $unknown, and $log is always called so it is
 * never silent. The two callers want different fallbacks and say so:
 *
 *   update_job_status()  passes 'ERROR'. A status the LIMS cannot parse is
 *                        precisely what ERROR is for, and recording it is how
 *                        an operator finds out.
 *   the outward maps     take the default null and leave their column alone.
 *                        Being loud must not mean relabelling a scientist's
 *                        running job as failed on the strength of a mapping
 *                        gap; the log is where the loudness belongs.
 */
function job_status_normalise( $status, $log = null, $unknown = null )
{
   static $map = array(
      ## already JOB vocabulary
      'SUBMITTED'      => 'SUBMITTED',
      'SUBMIT_TIMEOUT' => 'SUBMIT_TIMEOUT',
      'RUNNING'        => 'RUNNING',
      'RUN_TIMEOUT'    => 'RUN_TIMEOUT',
      'DATA'           => 'DATA',
      'COMPLETE'       => 'COMPLETE',
      'CANCELED'       => 'CANCELED',
      'FAILED'         => 'FAILED',
      'ERROR'          => 'ERROR',

      ## SCHEDULER vocabulary
      'ACTIVE'         => 'RUNNING',
      'COMPLETED'      => 'COMPLETE',
      'UNKNOWN'        => 'ERROR',   ## the cluster has no record: that IS an answer

      ## historical aliases
      'SUBMITED'       => 'SUBMITTED',
      'INITIALIZED'    => 'SUBMITTED',
      'UPDATING'       => 'SUBMITTED',
      'PENDING'        => 'SUBMITTED',
      'STARTED'        => 'RUNNING',
      'STAGING'        => 'RUNNING',
      'CANCELLED'      => 'CANCELED',

      ## FINISHED and DONE are STAGE words, not JOB words. They were only ever
      ## written to gfac.analysis.status because update_autoflow_status() was
      ## handed the same string, and submitctl needed to see 'done'. The job
      ## they describe is complete; the stage map below says so in stage words.
      'FINISHED'       => 'COMPLETE',
      'DONE'           => 'COMPLETE',
   );

   ## Write nothing. Each of these is a real outcome, not a gap.
   static $no_write = array(
      'UNREACHABLE' => 1,   ## we could not ask, so we know nothing
      'EXECUTING'   => 1,   ## progress chatter, no JOB equivalent
      'PROCESSING'  => 1,   ## same
   );

   $status = (string) $status;

   if ( isset( $no_write[ $status ] ) )
      return null;

   if ( isset( $map[ $status ] ) )
      return $map[ $status ];

   if ( is_callable( $log ) )
      $log( "job_status: unrecognised status '$status', falling back to "
            . ( $unknown === null ? 'no change' : $unknown ) );

   return $unknown;
}

/**
 * JOB status to the STAGE word submitctl.php acts on.
 *
 * Timeouts and abandonment map to 'failed'. They are not science failures, but
 * from the pipeline's point of view the stage is over and will not produce a
 * result, which is exactly what 'failed' means to submitctl. The distinction a
 * human needs travels in statusMsg, which says which cluster stopped answering
 * and for how long.
 *
 * Returns null when the job is still in flight, which submitctl reads as "keep
 * waiting" by simply not matching any of its lists.
 */
function stage_status_from_job( $job_status, $log = null )
{
   static $map = array(
      'COMPLETE'       => JOB_STATUS_STAGE_COMPLETE,
      'FAILED'         => JOB_STATUS_STAGE_FAILED,
      'ERROR'          => JOB_STATUS_STAGE_FAILED,
      'SUBMIT_TIMEOUT' => JOB_STATUS_STAGE_FAILED,
      'RUN_TIMEOUT'    => JOB_STATUS_STAGE_FAILED,
      'CANCELED'       => JOB_STATUS_STAGE_CANCELED,
      'SUBMITTED'      => JOB_STATUS_STAGE_QUEUED,
      'RUNNING'        => JOB_STATUS_STAGE_RUNNING,
      'DATA'           => JOB_STATUS_STAGE_RUNNING,
   );

   if ( isset( $map[ $job_status ] ) )
      return $map[ $job_status ];

   ## Unreachable in practice: callers normalise first, and JOB is closed.
   ## Holding the pipeline is the safe failure here, because a stage wrongly
   ## marked failed cannot be recovered while one left waiting can.
   if ( is_callable( $log ) )
      $log( "job_status: no stage mapping for job status '$job_status', leaving the stage alone" );

   return null;
}

/**
 * JOB status to the queueStatus a scientist sees.
 *
 * Returns null while the answer would not change what is displayed.
 *
 * Every terminal JOB status maps to something here, deliberately. The previous
 * map omitted CANCELED and SUBMITTED, so a job cancelled through gridctl kept
 * whatever queueStatus it had and never left the user's queue view.
 */
function queue_status_from_job( $job_status, $log = null )
{
   static $map = array(
      'SUBMITTED'      => 'queued',
      'RUNNING'        => 'running',
      'DATA'           => 'running',   ## still working, collecting output
      'COMPLETE'       => 'completed',
      'FAILED'         => 'failed',
      'ERROR'          => 'failed',
      'CANCELED'       => 'aborted',
      'SUBMIT_TIMEOUT' => 'aborted',
      'RUN_TIMEOUT'    => 'aborted',
   );

   if ( isset( $map[ $job_status ] ) )
      return $map[ $job_status ];

   if ( is_callable( $log ) )
      $log( "job_status: no queueStatus mapping for job status '$job_status', leaving it alone" );

   return null;
}

/** True when the job will not change state again without a resubmission. */
function job_status_is_terminal( $job_status )
{
   return in_array( $job_status, array( 'COMPLETE', 'FAILED', 'ERROR', 'CANCELED',
                                        'SUBMIT_TIMEOUT', 'RUN_TIMEOUT' ), true );
}
