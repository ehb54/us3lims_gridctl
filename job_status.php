<?php
/*
 * job_status.php
 *
 * The four job-status vocabularies and the maps between them:
 *
 *   SCHEDULER  transient                       what the cluster says right now
 *   JOB        gfac.analysis.status            the LIMS record of one HPC job
 *   STAGE      autoflowAnalysis.status         where a multi-stage pipeline is
 *   USER       HPCAnalysisResult.queueStatus   what the scientist sees
 *
 * Callers pass a JOB status and get the target column's own word for it.
 * Unmapped values are logged, never written.
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
## Layer 2: JOB. gfac.analysis.status, an ENUM, and the input to every map.
## The ENUM also has DATA_TIMEOUT, FAILED_DATA and CANCELLED for old rows;
## nothing writes them.
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
## Layer 3: STAGE. autoflowAnalysis.status, free text. submitctl.php matches
## these words; anything else reads as "still in progress".
## --------------------------------------------------------------------- ##

const JOB_STATUS_STAGE_COMPLETE = 'complete';   ## advance to the next stage
const JOB_STATUS_STAGE_FAILED   = 'failed';     ## stop the pipeline
const JOB_STATUS_STAGE_CANCELED = 'canceled';   ## stop the pipeline
const JOB_STATUS_STAGE_RUNNING  = 'running';    ## no match: still in progress
const JOB_STATUS_STAGE_QUEUED   = 'submitted';  ## no match: still in progress

## --------------------------------------------------------------------- ##
## Layer 4: USER. HPCAnalysisResult.queueStatus, an ENUM.
## --------------------------------------------------------------------- ##

const JOB_STATUS_USER = array( 'queued', 'running', 'aborted', 'failed', 'completed' );

/**
 * Normalise a SCHEDULER or JOB status, or an alias, into the JOB vocabulary.
 *
 * Returns null for "write nothing" (UNREACHABLE and progress chatter).
 * Anything unrecognised is logged and returns $unknown: update_job_status()
 * passes 'ERROR', the outward maps leave their column alone.
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

      ## STAGE words that reach here from older callers
      'FINISHED'       => 'COMPLETE',
      'DONE'           => 'COMPLETE',
   );

   ## Write nothing.
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
 * JOB status to the STAGE word submitctl.php acts on. Timeouts are 'failed':
 * the stage will not produce a result. statusMsg carries the reason.
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

   ## Callers normalise first, so this should not happen. Hold the stage.
   if ( is_callable( $log ) )
      $log( "job_status: no stage mapping for job status '$job_status', leaving the stage alone" );

   return null;
}

/** JOB status to the queueStatus a scientist sees. Every terminal status maps. */
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
