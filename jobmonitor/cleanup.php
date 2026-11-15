<?php
/*
 * cleanup.php
 *
 * Functions for staging completed Slurm results, importing them into LIMS,
 * and cleaning up the central job-tracking records.
 *
 */

require_once dirname( __DIR__ ) . '/db_params.php';

$email_address   = '';
$queuestatus     = '';
$jobtype         = '';
$db              = '';
$editXMLFilename = '';
$status          = '';

## job_cleanup()'s outcome for a connection-class failure writing a stage
## inside the finalizing span (after the gfac.analysis row is already
## deleted). Distinct from -1 ("finalized, give up") so
## resolve_and_cleanup_job() can tell the two apart: this one must leave the
## finalizing marker in place for uslims_jobs.php --restart to close out,
## since the write that marker exists for never actually happened.
const CLEANUP_FINALIZING_INTERRUPTED = -2;

## Called by the daemon's cleanup().
## Returns -2 (CLEANUP_FINALIZING_INTERRUPTED) the worker died mid-span and the
## marker survives for --restart, -1 terminal, 0 retry (not yet finalizable),
## 1 finalized / nothing to do.
function resolve_and_cleanup_job( $db_handle, $gfacID, $us3_db, $analysis_table, $log_fn, $cluster = '', $requestID = 0 )
{
   $key    = job_key( $gfacID, $cluster );
   $row_sql = job_row_condition( $db_handle, $gfacID, $cluster, $requestID, $analysis_table );
   if ( $row_sql === null )
   {
      $log_fn( "could not tell which gfac.analysis row is $gfacID's - will retry" );
      return 0;
   }
   $query  = "SELECT count(*) FROM $analysis_table WHERE gfacID = ?" . $row_sql[ 'sql' ];
   $result = db_params_query( $db_handle, $query, array_merge( array( (string) $gfacID ), $row_sql[ 'args' ] ) );

   if ( ! $result )
   {
      ## A connection-class failure says nothing about whether gfacID is
      ## still tracked, so it is retried rather than treated as terminal.
      ## Anything else is a query that can never succeed -- retrying it
      ## forever would just mail the admin every poll for a problem no
      ## retry could fix. mail_to_admin_once() dedupes the mail either
      ## way, so a real outage that does eventually clear is still only one
      ## 'fail' mail per job, not one per poll.
      $connection_class = db_error_is_connection_class( mysqli_errno( $db_handle ) );
      $log_fn( "Query failed $query - " . mysqli_error( $db_handle )
              . ( $connection_class ? " - will retry" : " - permanent, giving up" ) );
      mail_to_admin_once( "fail", "Query failed $query\n" . mysqli_error( $db_handle ) );
      return $connection_class ? 0 : -1;
   }

   list( $count ) = mysqli_fetch_array( $result );

   if ( $count == 0 )
   {
      return 1;          ## gfacID no longer tracked: nothing to do
   }

   ## job_cleanup() imports with plain INSERTs, so only the claim holder runs it.
   ## One monitor per job is the rule, but --restart decides what is unmonitored by
   ## reading the process table, which has misread it before.
   $claim = cleanup_claim_path( $us3_db, $key );

   ## 0, not 1: the claim may be left from a killed worker, and a caller that
   ## stopped here would leave the job unfinished until the claim goes stale.
   $claimed = cleanup_claim_acquire( $claim, $log_fn );
   clearstatcache( true, $claim );
   if ( ! $claimed && ! is_dir( $claim ) )
   {
      ## No claim there at all: its holder just released it, or it cannot be created, such as on a full disk.
      $claimed = cleanup_claim_acquire( $claim, $log_fn );
      clearstatcache( true, $claim );
      if ( ! $claimed && ! is_dir( $claim ) )
      {
         return job_local_fault_wait( $us3_db, $key, $gfacID, $cluster, "could not create a cleanup claim in the job log directory" );
      }
   }
   if ( ! $claimed )
   {
      $log_fn( "cleanup already claimed for $gfacID by another worker; will retry" );
      return 0;
   }

   try
   {
      $requestID = get_us3_data();
      if ( $requestID === null )
      {
         ## The database could not answer for a connection-class reason, not
         ## "no such row": retry rather than ending the monitor on a blip.
         return 0;
      }
      if ( $requestID === false )
      {
         ## A permanent query failure (get_us3_data() already mailed it,
         ## deduped). Retrying this forever cannot help.
         return -1;
      }
      if ( $requestID == 0 )
      {
         return -1;
      }

      $log_fn( "calling job_cleanup() reqID=$requestID" );
      $outcome = job_cleanup( $us3_db, $requestID, $db_handle, $cluster );
      global $work;
      job_staging_dir_remove( job_staging_dir( job_staging_root( $work, $us3_db ), $key ), $work );

      ## Terminal either way means the stage has been written, so the finalizing
      ## span is over. One place rather than every exit path in job_cleanup: what
      ## matters is that the marker survives only when the worker dies mid-span,
      ## and a death skips this as surely as it skips the release below.
      ##
      ## CLEANUP_FINALIZING_INTERRUPTED is the one outcome that is terminal for
      ## this monitor (it must stop polling: the row is already gone) without
      ## the stage ever having been written -- a connection-class failure
      ## reads exactly like a real crash to the code that would otherwise
      ## remove the marker, even though the worker is still alive and running.
      ## Leaving the marker here is what lets --restart's existing dead-worker
      ## close-out finish the job instead of it being stranded with no row,
      ## no marker, and a stage stuck at 'running' forever.
      if ( $outcome !== 0 && $outcome !== CLEANUP_FINALIZING_INTERRUPTED )
      {
         cleanup_finalizing_end( $us3_db, $gfacID, $cluster );
      }

      return $outcome;
   }
   finally
   {
      cleanup_claim_release( $claim );
   }
}

## Per-job directory under the job log tree; jobmonitor.php's $lock_dir.
## uslims_jobs.php, which scans these directories, does not set $ll_base_dir.
## Per-job path name: the job ID alone when its execution cluster is not known
## (monitors started before clusters were passed), else "<job ID>@<cluster>",
## so the same Slurm number from two clusters never shares a directory.
function job_key( $gfacID, $cluster = '' )
{
   return (string) $cluster === '' ? (string) $gfacID : "$gfacID@$cluster";
}

## The job's own row among gfac.analysis rows sharing its job number (a reset controller reuses
## numbers, two clusters can issue the same one): its execution cluster when known and, where the
## table records the request, its request's row only. A row without a request (written before the
## column; the upgrade fills in those it can) is never this job's. An array of 'sql', appended to "WHERE gfacID = ?",
## and the 'args' it binds. null when the database could not be asked, so a caller retries instead
## of acting on another job's row.
function job_row_condition( $db_handle, $gfacID, $cluster, $requestID, $table = 'gfac.analysis' )
{
   static $has_column = array();
   if ( ! preg_match( '/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $table ) )
   {
      return null;
   }
   $cond = (string) $cluster === '' ? array( 'sql' => '', 'args' => array() )
         : array( 'sql'  => " AND COALESCE(NULLIF(metaschedulerClusterExecuting, ''), cluster) = ?",
                  'args' => array( (string) $cluster ) );
   $requestID = (int) $requestID;
   if ( $requestID <= 0 )
   {
      return $cond;
   }
   if ( ! isset( $has_column[ $table ] ) )
   {
      ## SHOW cannot be prepared; its only input is the table name checked above.
      $res = mysqli_query( $db_handle, "SHOW COLUMNS FROM $table LIKE 'HPCAnalysisRequestID'" ); // NOSONAR $table is checked above
      if ( ! $res )
      {
         return null;
      }
      $has_column[ $table ] = mysqli_num_rows( $res ) > 0;
   }
   if ( ! $has_column[ $table ] )
   {
      return $cond;
   }
   return array( 'sql'  => $cond[ 'sql' ] . " AND HPCAnalysisRequestID = ?",
                 'args' => array_merge( $cond[ 'args' ], array( $requestID ) ) );
}

function cleanup_job_dir( $us3_db, $gfacID )
{
   global $ll_base_dir;

   $base = ( isset( $ll_base_dir ) && $ll_base_dir != "" )
           ? $ll_base_dir
           : ( exec( "ls -d ~us3/lims" ) . "/etc/joblog" );

   return "$base/$us3_db/$gfacID";
}

## The files a job keeps in its own directory. Declared in one place so the set is
## discoverable and a call site cannot invent a fourth by typo: three of these grew
## separately, each with its own hand-built path.
##
## The values are the names on disk and must not change. A running cleanup holds
## its claim by filename, so renaming one during an upgrade would let a second
## worker take the claim and import the same results twice.
## The root of one instance's staging directories. Job locks and cleanup claims are per
## instance, so two instances' jobs with one number and cluster must not share a directory.
function job_staging_root( $work, $us3_db )
{
   return "$work/$us3_db";
}

## get_local_files(): this server could not store the results; the cluster is not at fault.
const JOB_FETCH_LOCAL_FAULT = -3;
## get_local_files(): the database connection failed; nothing to report unless it lasts.
const JOB_FETCH_RETRY = -4;
## The largest archive gfac.analysis.tarfile holds (MEDIUMBLOB).
const JOB_RESULTS_MAX_BYTES = 16777215;

## Free bytes under $root, or false when unknown.
if ( ! function_exists( 'job_staging_free_bytes' ) ) {
   function job_staging_free_bytes( $root )
   {
      return @disk_free_space( $root );
   }
}

## What tar's output says about a failure: BAD only when it names the archive and no fault of this
## server, since a job with a BAD archive fails and any other waits with the site admin told.
function job_results_extract_class( $detail )
{
   $archive = preg_match( '/does not look like a tar archive|Unexpected EOF in archive|Skipping to next header|Damaged tar archive/i', $detail );
   $local = preg_match( '/No space left|Disk quota|Permission denied|Read-only file system|Cannot (open|mkdir|write)/i', $detail );
   return $archive && ! $local ? 'BAD' : 'LOCAL';
}

## The first result in an analysis_files.txt listing whose variance or meniscus is not a number
## (the analysis wrote nan or inf), or null. A line without them, such as job_statistics.xml, is fine.
function job_results_invalid_fit( array $files )
{
   foreach ( $files as $file )
   {
      $fn = explode( ';', $file )[ 0 ];
      if ( preg_match( '/mdl\.tmp$/', $fn ) )
      {
         continue;
      }
      foreach ( array( 'variance', 'meniscus_value' ) as $field )
      {
         if ( preg_match( "/;$field=([^;]*)/", $file, $m ) && ! is_numeric( $m[ 1 ] ) )
         {
            return $fn;
         }
      }
   }
   return null;
}

## A failure text holding a query or a database error: for the site admin, not the submitter.
function job_fail_is_internal( $msg )
{
   return (bool) preg_match( '/^(Internal error|Bad query|Could not select)/i', (string) $msg );
}

## What a submitter reads when a job's results were not saved, and what to do about it.
function job_fail_user_text( $msg, $admin_told )
{
   $what = job_fail_is_internal( $msg ) ? 'An internal error stopped its results from being saved.' : (string) $msg;
   return "$what\n   No results were saved. Resubmit the job if you still need it."
        . ( $admin_told ? "\n   The site administrator has been notified." : '' ) . "\n";
}

## Expand $tarfile in the current directory. class: OK, or as job_results_extract_class().
if ( ! function_exists( 'job_results_extract' ) ) {
   function job_results_extract( $tarfile )
   {
      if ( @file_put_contents( 'analysis-results.tar', $tarfile ) !== strlen( $tarfile ) ) {
         return array( 'class' => 'LOCAL', 'detail' => 'could not write analysis-results.tar' );
      }
      $out = array();
      exec( 'LC_ALL=C tar -xf analysis-results.tar 2>&1', $out, $err );
      $detail = implode( "\n", $out );
      return $err === 0 ? array( 'class' => 'OK', 'detail' => '' )
                        : array( 'class' => job_results_extract_class( $detail ), 'detail' => $detail );
   }
}

## A completed job waits on a fault of this server, with its row and results at the cluster intact, for as
## long as it takes: never failed for it, and the cluster not blamed. The wait restarts the outage hold, so a
## later blip of the cluster has the whole hold again. Logged once a process; the site admin is told once a day
## per fault, not per job, since one full disk can hold many. $cause names the fault and $detail adds to the mail.
function job_local_fault_wait( $us3_db, $key, $gfacID, $cluster, $cause, $detail = '' )
{
   global $me;
   static $logged = array();
   ## A full disk can refuse the marker too, and must not mean a mail at every poll.
   static $told_here = array();

   $why = $detail === '' ? $cause : "$cause: $detail";
   if ( ! isset( $logged[ $key ] ) )
   {
      $logged[ $key ] = true;
      write_logld( "$me: a fault on this server, not the cluster, holds the results of $gfacID: $why; will retry" );
   }
   @unlink( job_state_path( $us3_db, $key, 'complete_seen' ) );
   $waiting = job_state_path( $us3_db, $key, 'local_fault_wait' );
   @mkdir( dirname( $waiting ), 0770, true );
   @touch( $waiting );
   $fault = hash( 'sha256', $cause );
   $told  = dirname( dirname( cleanup_job_dir( $us3_db, $key ) ) ) . "/.local_fault_told/$fault";
   clearstatcache( true, $told );
   $when = max( (int) @filemtime( $told ), isset( $told_here[ $fault ] ) ? $told_here[ $fault ] : 0 );
   if ( time() - $when >= 86400 )
   {
      $told_here[ $fault ] = time();
      @mkdir( dirname( $told ), 0770, true );
      @touch( $told );
      mail_to_admin( 'fail', "Completed job $gfacID in $us3_db on $cluster, and any other job with the same fault,"
                     . " is waiting on a fault of this server, not the cluster: $why."
                     . " As us3, php ~us3/lims/database/utils/uslims_jobs.php --busy lists every job left. Each finishes by itself once that is fixed. This repeats daily while it lasts." );
   }
   return 0;
}

## Whether $free bytes hold $tar_bytes of results: the archive, what it expands to, the copy kept in
## the submit directory and a margin. Unknown free space does not hold a job back.
function job_staging_has_room( $tar_bytes, $free )
{
   return $free === false || $free >= 4 * $tar_bytes;
}

## Remove a staging directory once its job's cleanup attempt is over; the row and the
## submit directory hold what is kept. Leaves the process in $work.
function job_staging_dir_remove( $staging, $work )
{
   @chdir( $work );

   if ( $staging !== null && is_dir( $staging ) )
   {
      exec( 'rm -rf ' . escapeshellarg( $staging ) );
   }
}

## The local staging directory for one job's fetched results. Named after the
## scheduler job ID, which the scheduler reuses, so the path is composed from an
## id that must be checked first: empty or containing a slash it would name the
## work root or escape it, and this directory gets emptied.
function job_staging_dir( $work, $gfacID )
{
   if ( (string) $gfacID === '' || strpos( (string) $gfacID, '/' ) !== false )
   {
      return null;
   }

   return "$work/$gfacID";
}

/**
 * Empty it before staging into it. A reused job ID means files from the previous
 * job of that ID are still there, and anything the new tar does not overwrite
 * would be imported as this job's results. Cleared here rather than once the
 * results are in hand, because by then this is the directory holding them.
 */
function job_staging_dir_prepare( $work, $gfacID, $log = null )
{
   $dir = job_staging_dir( $work, $gfacID );

   if ( $dir === null )
   {
      return null;
   }

   if ( file_exists( $dir ) || is_link( $dir ) )
   {
      if ( is_callable( $log ) )
      {
         $log( "clearing a leftover staging path $dir" );
      }

      exec( 'rm -rf ' . escapeshellarg( $dir ) );
   }

   ## is_dir() answers from the stat cache, which still holds the removed directory.
   clearstatcache();

   if ( file_exists( $dir ) || ! @mkdir( $dir, 0770, true ) || ! is_dir( $dir ) )
   {
      return null;
   }

   return $dir;
}

function job_state_files()
{
   return array(
      'claim'          => 'cleanup.claim',    ## only this worker may finalize the job
      'finalizing'     => 'finalizing',       ## the row is gone, the stage is not written yet
      'status_pending' => 'status-pending',   ## results are in; only the final status write failed
      'complete_seen'  => 'complete_seen',    ## when cleanup first found the job unfinalizable
      'local_fault_wait' => 'local_fault_wait', ## results are stored and a fault of this server holds them
   );
}

## The path of one of them. An unknown name is a programming error, not input.
function job_state_path( $us3_db, $gfacID, $what )
{
   $files = job_state_files();

   if ( ! isset( $files[ $what ] ) )
   {
      throw new InvalidArgumentException(
         "unknown job state file '$what'; known: " . implode( ', ', array_keys( $files ) ) );
   }

   return cleanup_job_dir( $us3_db, $gfacID ) . '/' . $files[ $what ];
}

## Directory used as the cross-worker cleanup claim for one job.
function cleanup_claim_path( $us3_db, $gfacID )
{
   return job_state_path( $us3_db, $gfacID, 'claim' );
}

## Marker for the span between deleting the gfac.analysis row and writing the
## job's final stage status. In that span the job has no row, so nothing restarts
## a monitor for it, and the stage would sit at 'running' for ever if the worker
## died. The marker records what a later run needs to close the stage out.
function cleanup_finalizing_path( $us3_db, $gfacID )
{
   return job_state_path( $us3_db, $gfacID, 'finalizing' );
}

## Markers store the raw job ID and its execution cluster; only the path uses job_key().
function cleanup_finalizing_begin( $us3_db, $gfacID, $autoflowAnalysisID, $requestID, $cluster = '' )
{
   $path = cleanup_finalizing_path( $us3_db, job_key( $gfacID, $cluster ) );
   @mkdir( dirname( $path ), 0770, true );
   @file_put_contents( $path, json_encode( array(
      'us3_db'             => $us3_db,
      'gfacID'             => $gfacID,
      'cluster'            => (string) $cluster,
      'autoflowAnalysisID' => (int) $autoflowAnalysisID,
      'requestID'          => (int) $requestID,
      'pid'                => getmypid(),
      'started'            => time(),
   ) ) );
}

function cleanup_finalizing_end( $us3_db, $gfacID, $cluster = '' )
{
   @unlink( cleanup_finalizing_path( $us3_db, job_key( $gfacID, $cluster ) ) );
}

## Marker for a job whose results are already fully imported but whose final
## stage-status write (update_autoflow_status()) did not land after retrying.
## Unlike the finalizing marker above, this is not evidence of a dead worker --
## the worker is still alive and returns success -- so --restart's close-out
## for this marker must retry the real recorded status, never assume FAILED.
function cleanup_status_pending_path( $us3_db, $gfacID )
{
   return job_state_path( $us3_db, $gfacID, 'status_pending' );
}

## $stage/$queue_status are the already-mapped final column values
## (stage_status_from_job()/queue_status_from_job() on the normalised job
## status), not the raw job status -- dbutils's uslims_jobs.php has no access
## to gridctl's status vocabulary maps, so the mapping happens once, here,
## and the marker carries the answer.
function cleanup_status_pending_begin( $us3_db, $gfacID, $autoflowAnalysisID, $requestID, $stage, $queue_status, $message,
                                       $cluster = '' )
{
   $path = cleanup_status_pending_path( $us3_db, job_key( $gfacID, $cluster ) );
   @mkdir( dirname( $path ), 0770, true );
   @file_put_contents( $path, json_encode( array(
      'us3_db'             => $us3_db,
      'gfacID'             => $gfacID,
      'cluster'            => (string) $cluster,
      'autoflowAnalysisID' => (int) $autoflowAnalysisID,
      'requestID'          => (int) $requestID,
      'stage'              => $stage,
      'queue_status'       => $queue_status,
      'message'            => $message,
      'pid'                => getmypid(),
      'started'            => time(),
   ) ) );
}

function cleanup_status_pending_end( $us3_db, $gfacID, $cluster = '' )
{
   @unlink( cleanup_status_pending_path( $us3_db, job_key( $gfacID, $cluster ) ) );
}

## Seconds since cleanup first found this job not yet finalizable. The first
## call starts the clock, persisted in $seen_file across polls.
function cleanup_pending_seconds( $seen_file )
{
   $seen = is_file( $seen_file ) ? (int) trim( @file_get_contents( $seen_file ) ) : 0;

   if ( $seen <= 0 )
   {
      $seen = time();
      @mkdir( dirname( $seen_file ), 0770, true );
      @file_put_contents( $seen_file, $seen );
   }

   return time() - $seen;
}

## How long cleanup retries a job before finalizing it anyway.
function cleanup_complete_ceiling()
{
   global $global_complete_max_seconds;

   return isset( $global_complete_max_seconds ) ? (int) $global_complete_max_seconds : 21600;
}

## How long to keep retrying results on an unreachable cluster: the outage
## hold, so a maintenance window does not fail a job whose results are intact.
function cleanup_unreachable_ceiling()
{
   global $global_cluster_abandon_hours;

   $hold = isset( $global_cluster_abandon_hours ) ? (int) $global_cluster_abandon_hours * 3600 : 72 * 3600;
   return max( cleanup_complete_ceiling(), $hold );
}

## Keep retrying a job whose cluster is unreachable? True until the outage hold.
function cleanup_retry_unreachable( $seen_file )
{
   return cleanup_pending_seconds( $seen_file ) <= cleanup_unreachable_ceiling();
}

## Atomically take the claim (mkdir). Returns true if this process now owns
## it. A claim older than an hour is assumed to be from a crashed worker and
## taken over; a slow but live cleanup can hold it for minutes.
function cleanup_claim_release( $claim )
{
   @unlink( "$claim/owner" );
   @rmdir( $claim );
}

## When the process at $pid started, as an opaque string, or '' when it cannot be
## told. A PID on its own is not an identity: PIDs are reused, and a claim whose
## owner had died could be held by an unrelated process for as long as that
## process lived, which on a long-running host is indefinitely.
function cleanup_process_start( $pid )
{
   $stat = @file_get_contents( "/proc/$pid/stat" );
   if ( $stat !== false )
   {
      ## Field 22 is starttime in clock ticks since boot. The comm field can
      ## contain spaces and brackets, so count from the last ')'.
      $rest   = substr( $stat, (int) strrpos( $stat, ')' ) + 2 );
      $fields = preg_split( '/\s+/', trim( $rest ) );
      if ( isset( $fields[ 19 ] ) )
      {
         return (string) $fields[ 19 ];
      }
   }

   ## No procfs: ask ps. Empty means the caller falls back to the age rule.
   $out = @shell_exec( 'ps -o lstart= -p ' . (int) $pid . ' 2>/dev/null' );

   return $out === null ? '' : trim( (string) $out );
}

## "<pid> <start>", the tag written into a claim.
function cleanup_claim_owner_tag( $pid )
{
   return $pid . ' ' . cleanup_process_start( $pid );
}

function cleanup_claim_acquire( $claim, $log_fn )
{
   $stale_seconds = 3600;

   if ( @mkdir( $claim, 0770, true ) )
   {
      ## Owner tag: a live owner keeps its claim however long a copy takes.
      @file_put_contents( "$claim/owner", cleanup_claim_owner_tag( getmypid() ) );
      return true;
   }

   ## Already claimed -- take it over only if it is clearly abandoned.
   $tag   = trim( (string) @file_get_contents( "$claim/owner" ) );
   $parts = $tag === '' ? array() : explode( ' ', $tag, 2 );
   $owner = isset( $parts[ 0 ] ) ? (int) $parts[ 0 ] : 0;
   $start = isset( $parts[ 1 ] ) ? trim( $parts[ 1 ] ) : '';
   if ( $owner > 0 )
   {
      $alive = function_exists( 'posix_kill' ) ? (bool) @posix_kill( $owner, 0 )
                                               : file_exists( "/proc/$owner" );
      if ( $alive && $start !== '' )
      {
         ## Alive at that PID is not enough: it has to be the same process.
         $now   = cleanup_process_start( $owner );
         $alive = $now === '' || $now === $start;
         if ( ! $alive )
         {
            $log_fn( "cleanup claim $claim names pid $owner, but that pid is now a"
                     . " different process; treating the claim as abandoned" );
         }
      }
      elseif ( $alive )
      {
         ## A tag written before start times were recorded has no $start to
         ## check against, so "alive" alone cannot tell this owner apart from
         ## an unrelated process that was later given the same PID. Fall back
         ## to the same age limit the no-tag-at-all case below uses, rather
         ## than trusting a live PID indefinitely.
         $mtime = @filemtime( $claim );
         $alive = $mtime === false || ( time() - $mtime ) <= $stale_seconds;
      }
      $abandoned = ! $alive;
   }
   else
   {
      $mtime     = @filemtime( $claim );
      $abandoned = $mtime !== false && ( time() - $mtime ) > $stale_seconds;
   }

   if ( $abandoned )
   {
      $log_fn( "removing abandoned cleanup claim $claim (owner " . ( $owner ?: 'unknown' ) . ")" );
      cleanup_claim_release( $claim );
      if ( @mkdir( $claim, 0770, true ) )
      {
         ## Same tag the normal acquire path writes: a bare PID here is what
         ## let the next contender fall back to "alive means still owned, no
         ## age limit" instead of verifying it is the same process.
         @file_put_contents( "$claim/owner", cleanup_claim_owner_tag( getmypid() ) );
         return true;
      }
   }

   return false;
}

function mail_to_user( $type, $msg, $admin_detail = null )
{
   ## Note to me. Just changed subject line to include a modified $status instead 
   ## of the $type variable passed. More informative than just "fail" or "success." 
   ## See how it works for awhile and then consider removing $type parameter from 
   ## function.
   global $email_address;
   global $submittime;
   global $endtime;
   global $queuestatus;
   global $status;
   global $cluster;
   global $jobtype;
   global $org_name;
   global $org_domain;
   global $servhost;
   global $admin_email;
   global $db;
   global $dbhost;
   global $requestID;
   global $gfacID;
   global $editXMLFilename;
   global $stdout;

global $me;
write_logld( "$me mail_to_user(): sending email to $email_address for $gfacID" );

   ## Read the stored job status and message.
   $gfac_message = get_gfac_message( $gfacID );
   if ( $gfac_message === false ) $gfac_message = "Job Finished";
      
   ## Create a status to put in the subject line
   switch ( $status )
   {
      case "COMPLETE":
         $subj_status = 'completed';
         break;

      case "CANCELLED":
      case "CANCELED":
         $subj_status = 'canceled';
         break;

      case "FAILED":
         $subj_status = 'failed';
         break;

      case "ERROR":
         $subj_status = 'unknown error';
         break;

      default:
         $subj_status = $status;       ## For now
         break;

   }

   ## The analysis can finish on its cluster and the job still fail here.
   $failed_here = $type == 'fail' && ! in_array( $subj_status, array( 'canceled', 'unknown error' ), true );
   if ( $failed_here )
   {
      $subj_status = 'failed';
   }

   $queuestatus = $subj_status;
   $limshost    = $dbhost;
   if ( $limshost == 'localhost' )
   {
      $limshost    = gethostname();
      if ( ! preg_match( "/\./", $limshost ) )
      {  ## no domain in hostname
         if ( isset( $org_domain ) )
            $limshost    = $limshost . "." . $org_domain;
         else if ( isset( $servhost ) )
            $limshost    = $servhost;
      }
   }

   ## Parse the editXMLFilename
   list( $runID, $editID, $dataType, $cell, $channel, $wl, $ext ) =
      explode( ".", $editXMLFilename );

   ## The site admin first, so the submitter is told so only when that mail went out.
   if ( $failed_here && $admin_detail === null && job_fail_is_internal( $msg ) )
   {
      $admin_detail = $msg;
   }
   $admin_told = $failed_here && $admin_detail !== null
                 && mail_to_admin( 'fail', "Job $gfacID, request $requestID in $db: $admin_detail" );

   ## An unset listen-config address still gives the mail a sender, as common's notices do.
   $sender = ! empty( $admin_email ) ? $admin_email : 'root@localhost';
   $headers  = "From: $org_name Admin<$sender>"     . "\n";
   ## One Cc header, to the configured admin address only, unless the admin already has this failure's detail.
   if ( ! $admin_told )
   {
      $headers .= "Cc: $org_name Admin<$sender>\n";
   }

   ## Set the reply address
   $headers .= "Reply-To: $org_name<$sender>"      . "\n";
   $headers .= "Return-Path: $org_name<$sender>"   . "\n";

   ## Try to avoid spam filters
   $now      = time();
   $tnow     = date( 'Y-m-d H:i:s' );
   $headers .= "Message-ID: <" . $now . "cleanup@$dbhost>\n";
   $headers .= "X-Mailer: PHP v" . phpversion()         . "\n";
   $headers .= "MIME-Version: 1.0"                      . "\n";
   $headers .= "Content-Transfer-Encoding: 8bit"        . "\n";

   $subject       = "UltraScan Job Notification - $subj_status - " . substr( $gfacID, 0, 16 );
   $intro         = $failed_here ? 'Your UltraScan job failed' : 'Your UltraScan job is complete';
   $job_lines     = $failed_here ? '' : "Job Status      : $status
   Job Message     : $gfac_message
   ";
   $message       = "
   $intro:

   Submission Time : $submittime
   Job End Time    : $endtime
   Mail Time       : $tnow
   LIMS Host       : $limshost
   Analysis ID     : $gfacID
   Request ID      : $requestID  ( $db )
   RunID           : $runID
   EditID          : $editID
   Data Type       : $dataType
   Cell/Channel/Wl : $cell / $channel / $wl
   Status          : $queuestatus
   Cluster         : $cluster
   Job Type        : $jobtype
   {$job_lines}Stdout          : $stdout
   ";

   if ( $failed_here )
   {
      $message .= job_fail_user_text( $msg, $admin_told );
   }
   else if ( $type != "success" )
   {
      $message .= "Grid Ctrl Error :  $msg\n";
   }

   ## Handle the error case where an error occurs before fetching the
   ## user's email address
   if ( $email_address == "" ) $email_address = $admin_email;

   mail( $email_address, $subject, $message, $headers );
}

function parse_xml( $xml, $type )
{
   $parser = new XMLReader();
   $parser->xml( $xml );

   $results = array();

   while ( $parser->read() )
   {
      if ( $parser->name == $type )
      {
         while ( $parser->moveToNextAttribute() ) 
         {
            $results[ $parser->name ] = $parser->value;
         }

         break;
      }
   }

   $parser->close();
   return $results;
}

## Recognises externally assigned analysis IDs.
function get_gfac_message( $gfacID )
{
  global $serviceURL;
  global $me;

  $hex = "[0-9a-fA-F]";
  if ( ! preg_match( "/^US3-Experiment/i", $gfacID ) &&
       ! preg_match( "/^US3-$hex{8}-$hex{4}-$hex{4}-$hex{4}-$hex{12}$/", $gfacID ) )
   {
      ## Not an external analysis-ID format.
      return false;
   }

   return $gfac_message;
}

function parse_message( $xml )
{
   global $status;
   $status       = "";
   $gfac_message = "";

   $parser = new XMLReader();
   $parser->xml( $xml );

   $results = array();

   while( $parser->read() )
   {
      $type = $parser->nodeType;

      if ( $type == XMLReader::ELEMENT )
         $name = $parser->name;

      else if ( $type == XMLReader::TEXT )
      {
         if ( $name == "status" ) 
            $status       = $parser->value;
         else 
            $gfac_message = $parser->value; 
      }
   }
      
   $parser->close();
   return $gfac_message;
}

## Ask a cluster where its work directory is.
## Returns array( 'class' => 'OK' | 'UNREACHABLE' | 'MISSING', 'path', 'detail' ).
## UNREACHABLE: we learned nothing, retry. MISSING: the cluster answered and
## there is no such directory. remote_exec::run() fences stdout, so login
## banners cannot pollute the path.
function resolve_remote_workdir( $rx, $lworkdir )
{
   $res = $rx->run( "ls -d " . escapeshellarg( $lworkdir ), array( 'label' => 'resolve workdir' ) );

   if ( remote_exec_infra_fault( $res ) )
      return array( 'class' => 'UNREACHABLE', 'path' => '', 'detail' => $res[ 'class' ] );

   if ( ! $res[ 'ok' ] || trim( $res[ 'text' ] ) === '' )
      return array( 'class' => 'MISSING', 'path' => '', 'detail' => $res[ 'stderr' ] );

   return array( 'class' => 'OK', 'path' => trim( $res[ 'text' ] ), 'detail' => '' );
}

## Stage a job's stderr, stdout and results tar into its gfac.analysis row.
## Returns 1 staged (or the cluster confirmed there is nothing to stage),
## -1 cluster unreachable, so not known yet: do not finalize on this,
## -2 can never be staged (cluster not configured, or the row rejects the tar).
function get_local_files( $db_handle, $cluster, $requestID, $id, $gfacID, $key = null )
{
   global $work;
   global $work_remote;
   global $me;
   global $db;
   global $cluster_details;

   write_logld( "$me get_local_files(): $cluster, $requestID, $id, $gfacID" );

   $stderr     = '';
   $stdout     = '';
   $tarfile    = '';

   if ( ! isset( $cluster_details[ $cluster ] ) || ! isset( $cluster_details[ $cluster ][ 'name' ] ) )
   {
      write_logld( "$me cluster $cluster missing from global_config.php \$cluster_details" );
      return -2;
   }

   ## Guarded like cluster_probe_job_status(): common's remote_exec validates
   ## ssh_host_key_policy (and the rest of the ssh options) in the
   ## constructor, so a bad cluster entry throws here rather than at run().
   ## The probe path already catches that; this, the results fetch, did not,
   ## and a thrown exception is otherwise fatal and kills the monitor.
   try {
      $rx = cluster_probe_remote( $cluster, 'write_logld' );
   } catch ( Throwable $e ) {
      write_logld( "$me cluster $cluster configuration rejected: " . $e->getMessage() . "; will retry" );
      return -1;
   }

   ## Resolve the job's work directory on the cluster.
   $lworkdir = isset( $cluster_details[ $cluster ][ 'workdir' ] )
               ? $cluster_details[ $cluster ][ 'workdir' ]
               : "$work/local";

   $resolved = resolve_remote_workdir( $rx, $lworkdir );

   if ( $resolved[ 'class' ] === 'UNREACHABLE' )
   {
      write_logld( "$me: cluster $cluster unreachable resolving workdir ({$resolved['detail']}); will retry" );
      return -1;
   }

   if ( $resolved[ 'class' ] === 'MISSING' )
   {
      write_logld( "$me: cluster $cluster has no work directory $lworkdir: {$resolved['detail']}" );
      return 1;   ## a real answer: there is nothing to fetch
   }

   $work_remote = $resolved[ 'path' ];
   $remoteDir   = sprintf( "$work_remote/$db-%06d", $requestID );
   write_logld( "$me:  remoteDir=$remoteDir" );

   ## Local staging directory, emptied first so a reused job ID cannot leave one
   ## job's files to be imported as another's.
   $root    = job_staging_root( $work, $db );
   $staging = job_staging_dir_prepare( $root, $key !== null ? $key : $gfacID,
                                       function ( $m ) use ( $me ) { write_logld( "$me: $m" ); } );

   if ( $staging === null && job_staging_dir( $root, $key !== null ? $key : $gfacID ) === null )
   {
      write_logld( "$me: refusing to stage results: unusable job id '$gfacID'" );
      return 1;
   }

   ## A local fault, not an answer from the cluster: retry rather than finalize
   ## with results staged somewhere else.
   if ( $staging === null || ! @chdir( $staging ) )
   {
      write_logld( "$me: could not get a fresh staging directory for job id '$gfacID'; will retry" );
      return JOB_FETCH_LOCAL_FAULT;
   }

   ## us_mpi_analysis changes into output/ and archives into that directory
   ## (us_mpi_analysis.cpp:340, :2515-2516) -- that is where it is on every
   ## normal job, so it goes first to avoid a guaranteed-failed scp attempt
   ## against the top-level path before falling back to the real one. The
   ## top-level name is still tried second in case a layout ever puts it there.
   $tar_candidates = array(
      "$remoteDir/output/analysis-results.tar",
      "$remoteDir/analysis-results.tar",
   );

   $tar = fetch_first_remote( $rx, $tar_candidates, 'analysis-results.tar', 'tarfile' );

   ## The tar may still be being written; retry while the cluster answers.
   $secwait = 10;
   $num_try = 0;
   while ( $tar[ 'class' ] === 'MISSING' && $num_try < 3 )
   {
      cleanup_job_sleep( $secwait );
      $num_try++;
      $secwait *= 2;
      write_logld( "$me:  tarfile not present yet: retry $num_try" );
      $tar = fetch_first_remote( $rx, $tar_candidates, 'analysis-results.tar', 'tarfile' );
   }

   if ( $tar[ 'class' ] === 'LOCAL' )
   {
      write_logld( "$me: a local fault kept the results of $gfacID from being stored in $staging; will retry" );
      return JOB_FETCH_LOCAL_FAULT;
   }

   if ( $tar[ 'class' ] === 'UNREACHABLE' )
   {
      write_logld( "$me: cluster $cluster unreachable fetching results for $gfacID; will retry" );
      return -1;
   }

   ## Missing stdout/stderr is fine; an unreachable cluster is not.
   foreach ( array( 'stdout', 'stderr' ) as $fn )
   {
      $one = fetch_first_remote( $rx, array( "$remoteDir/$fn" ), $fn, $fn );

      if ( $one[ 'class' ] === 'UNREACHABLE' || $one[ 'class' ] === 'LOCAL' )
      {
         write_logld( "$me: {$one['class']}: could not fetch $fn for $gfacID from $cluster; will retry" );
         return $one[ 'class' ] === 'LOCAL' ? JOB_FETCH_LOCAL_FAULT : -1;
      }
   }

   ## Store the staged files in the central job-tracking database.

   $lense = 0;
   if ( file_exists( "stderr"  ) )
   {
      $lense = filesize( "stderr" );
      if ( $lense > 1000000 )
      { ## Replace exceptionally large stderr with smaller version
         exec( "mv stderr stderr-orig", $output, $stat );
         exec( "head -n 5000 stderr-orig >stderr-h", $output, $stat );
         exec( "tail -n 5000 stderr-orig >stderr-t", $output, $stat );
         exec( "cat stderr-h stderr-t >stderr", $output, $stat );
      }
      $stderr  = file_get_contents( "stderr" );
   }
   else
   {
      $stderr  = "";
   }

   if ( file_exists( "stdout" ) ) $stdout  = file_get_contents( "stdout" );

   ## Both remote candidates land at this one local name.
   $fn_tarfile = "analysis-results.tar";

   ## No fix makes it fit, so it isn't read, and the job fails instead of waiting.
   $too_large = 0;
   if ( file_exists( $fn_tarfile ) && filesize( $fn_tarfile ) > JOB_RESULTS_MAX_BYTES )
      $too_large = filesize( $fn_tarfile );
   else if ( file_exists( $fn_tarfile ) )
      $tarfile = file_get_contents( $fn_tarfile );

##   $lense = strlen( $stderr );
##   if ( $lense > 1000000 )
##   { ## Replace exceptionally large stderr with smaller version
##      exec( "mv stderr stderr-orig", $output, $stat );
##      exec( "head -n 5000 stderr-orig >stderr-h", $output, $stat );
##      exec( "tail -n 5000 stderr-orig >stderr-t", $output, $stat );
##      exec( "cat stderr-h stderr-t >stderr", $output, $stat );
##      $stderr  = file_get_contents( "stderr" );
##   }
$lene = strlen( $stderr );
write_logld( "$me: stderr size: $lene  (was $lense)");
$leno = strlen( $stdout );
write_logld( "$me: stdout size: $leno");
$lent = strlen( $tarfile );
write_logld( "$me: tarfile size: $lent");
   ## What was not fetched keeps what is stored: a job waiting on this server may outlive the cluster's copy.
   $set  = array();
   $args = array();
   foreach ( array( 'stderr' => $stderr, 'stdout' => $stdout, 'tarfile' => $tarfile ) as $column => $value )
   {
      if ( strlen( (string) $value ) > 0 )
      {
         $set[]  = "$column = ?";
         $args[] = (string) $value;
      }
   }
   $too_large_failure = "Its results are " . ceil( $too_large / 1048576 ) . " MB, more than the 16 MB that can be stored";
   if ( ! $set )
   {
      if ( $too_large )
      {
         $GLOBALS[ 'job_fetch_failure' ] = $too_large_failure;
         return -2;
      }
      return 1;
   }
   $args[] = (int) $id;
   $query  = "UPDATE gfac.analysis SET " . implode( ', ', $set ) . " WHERE id = ?";
   $result = db_params_query( $db_handle, $query, $args );

   if ( ! $result )
   {
      $error = mysqli_error( $db_handle );
      write_logld( "$me: Bad query:\n$query\n$error" );
      echo "Bad query\n";
      if ( db_error_is_connection_class( mysqli_errno( $db_handle ) ) )
      {
         return JOB_FETCH_RETRY;
      }
      ## ER_DATA_TOO_LONG: this never clears either.
      if ( mysqli_errno( $db_handle ) === 1406 )
      {
         $GLOBALS[ 'job_fetch_failure' ] = "Its output is too large to store";
         return -2;
      }
      $GLOBALS[ 'job_fetch_fault' ] = $error;
      return JOB_FETCH_LOCAL_FAULT;
   }

   if ( $too_large )
   {
      $GLOBALS[ 'job_fetch_failure' ] = $too_large_failure;
      return -2;
   }
   return 1;
}

## Fetch the first of $candidates that exists on the cluster, landing it at
## $local_name in the current directory.
##
## Returns [ 'class' => 'OK' | 'MISSING' | 'UNREACHABLE' | 'LOCAL' ]: MISSING means
## the cluster answered that it is not there, UNREACHABLE that we do not know, and
## LOCAL that this host could not store it (a full disk, or a partial copy).
##
## Downloads to ".part" and renames only when scp succeeds, so an interrupted
## transfer never leaves a truncated file under the real name.
function fetch_first_remote( $rx, $candidates, $local_name, $label )
{
   global $me;

   $part = "$local_name.part";

   ## Discard anything left by an interrupted earlier attempt.
   @unlink( $part );

   $saw_missing = false;

   foreach ( $candidates as $path )
   {
      $res = $rx->copy_from( $path, $part, array( 'label' => "fetch $label" ) );

      if ( $res[ 'ok' ] && file_exists( $part ) )
      {
         @unlink( $local_name );

         if ( ! @rename( $part, $local_name ) )
         {
            write_logld( "$me: could not move $part into place as $local_name" );
            @unlink( $part );
            return array( 'class' => 'LOCAL' );
         }

         write_logld( "$me: fetched $label from $path" );
         return array( 'class' => 'OK' );
      }

      $partial = file_exists( $part ) && filesize( $part ) > 0;
      @unlink( $part );

      if ( remote_exec_infra_fault( $res ) )
      {
         write_logld( "$me: $label fetch from $path: {$res['class']} after {$res['attempts']} attempt(s)" );
         return array( 'class' => 'UNREACHABLE' );
      }

      $stderr = isset( $res[ 'stderr' ] ) ? (string) $res[ 'stderr' ] : '';
      if ( $partial || ! is_writable( '.' ) || preg_match( '/No space left on device|Disk quota exceeded|Read-only file system/i', $stderr ) )
      {
         write_logld( "$me: $label could not be stored locally from $path: $stderr" );
         return array( 'class' => 'LOCAL' );
      }

      ## A reachable cluster saying "No such file" is a real answer.
      $saw_missing = true;
      write_logld( "$me: $label not at $path: {$res['stderr']}" );
   }

   return array( 'class' => $saw_missing ? 'MISSING' : 'UNREACHABLE' );
}
?>
