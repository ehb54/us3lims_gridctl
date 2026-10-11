<?php
/*
 * cleanup.php
 *
 * Functions for staging completed Slurm results, importing them into LIMS,
 * and cleaning up the central job-tracking records.
 *
 */

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
function resolve_and_cleanup_job( $db_handle, $gfacID, $us3_db, $analysis_table, $log_fn )
{
   $query  = "SELECT count(*) FROM $analysis_table WHERE gfacID='$gfacID'";
   $result = mysqli_query( $db_handle, $query );

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
   $claim = cleanup_claim_path( $us3_db, $gfacID );

   ## 0, not 1: the claim may be left from a killed worker, and a caller that
   ## stopped here would leave the job unfinished until the claim goes stale.
   if ( ! cleanup_claim_acquire( $claim, $log_fn ) )
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
      $outcome = job_cleanup( $us3_db, $requestID, $db_handle );

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
         cleanup_finalizing_end( $us3_db, $gfacID );
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
function cleanup_job_dir( $us3_db, $gfacID )
{
   global $ll_base_dir;

   $base = ( isset( $ll_base_dir ) && $ll_base_dir != "" )
           ? $ll_base_dir
           : ( exec( "ls -d ~us3/lims" ) . "/etc/joblog" );

   return "$base/$us3_db/$gfacID";
}

## Directory used as the cross-worker cleanup claim for one job.
## The files a job keeps in its own directory. Declared in one place so the set is
## discoverable and a call site cannot invent a fourth by typo: three of these grew
## separately, each with its own hand-built path.
##
## The values are the names on disk and must not change. A running cleanup holds
## its claim by filename, so renaming one during an upgrade would let a second
## worker take the claim and import the same results twice.
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

   if ( is_dir( $dir ) )
   {
      if ( is_callable( $log ) )
      {
         $log( "clearing a leftover staging directory $dir" );
      }

      exec( 'rm -rf ' . escapeshellarg( $dir ) );
   }

   if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0770, true ) )
   {
      return null;
   }

   return $dir;
}

function job_state_files()
{
   return array(
      'claim'         => 'cleanup.claim',   ## only this worker may finalize the job
      'finalizing'    => 'finalizing',      ## the row is gone, the stage is not written yet
      'complete_seen' => 'complete_seen',   ## when cleanup first found the job unfinalizable
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

function cleanup_finalizing_begin( $us3_db, $gfacID, $autoflowAnalysisID, $requestID )
{
   $path = cleanup_finalizing_path( $us3_db, $gfacID );
   @mkdir( dirname( $path ), 0770, true );
   @file_put_contents( $path, json_encode( array(
      'us3_db'             => $us3_db,
      'gfacID'             => $gfacID,
      'autoflowAnalysisID' => (int) $autoflowAnalysisID,
      'requestID'          => (int) $requestID,
      'pid'                => getmypid(),
      'started'            => time(),
   ) ) );
}

function cleanup_finalizing_end( $us3_db, $gfacID )
{
   @unlink( cleanup_finalizing_path( $us3_db, $gfacID ) );
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

function mail_to_user( $type, $msg )
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

   $headers  = "From: $org_name Admin<$admin_email>"     . "\n";
   ## One Cc header, to the configured admin address only.
   $headers .= "Cc: $org_name Admin<$admin_email>\n";

   ## Set the reply address
   $headers .= "Reply-To: $org_name<$admin_email>"      . "\n";
   $headers .= "Return-Path: $org_name<$admin_email>"   . "\n";

   ## Try to avoid spam filters
   $now      = time();
   $tnow     = date( 'Y-m-d H:i:s' );
   $headers .= "Message-ID: <" . $now . "cleanup@$dbhost>\n";
   $headers .= "X-Mailer: PHP v" . phpversion()         . "\n";
   $headers .= "MIME-Version: 1.0"                      . "\n";
   $headers .= "Content-Transfer-Encoding: 8bit"        . "\n";

   $subject       = "UltraScan Job Notification - $subj_status - " . substr( $gfacID, 0, 16 );
   $message       = "
   Your UltraScan job is complete:

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
   Job Status      : $status
   Job Message     : $gfac_message
   Stdout          : $stdout
   ";

   if ( $type != "success" ) $message .= "Grid Ctrl Error :  $msg\n";

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
function get_local_files( $db_handle, $cluster, $requestID, $id, $gfacID )
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
   $staging = job_staging_dir_prepare( $work, $gfacID,
                                       function ( $m ) use ( $me ) { write_logld( "$me: $m" ); } );

   if ( $staging === null )
   {
      write_logld( "$me: refusing to stage results: unusable job id '$gfacID'" );
      return 1;
   }

   chdir( $staging );

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
      sleep( $secwait );
      $num_try++;
      $secwait *= 2;
      write_logld( "$me:  tarfile not present yet: retry $num_try" );
      $tar = fetch_first_remote( $rx, $tar_candidates, 'analysis-results.tar', 'tarfile' );
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

      if ( $one[ 'class' ] === 'UNREACHABLE' )
      {
         write_logld( "$me: cluster $cluster unreachable fetching $fn for $gfacID; will retry" );
         return -1;
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

   if ( file_exists( $fn_tarfile ) )
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
   $esstde = mysqli_real_escape_string( $db_handle, $stderr );
   $esstdo = mysqli_real_escape_string( $db_handle, $stdout );
   $estarf = mysqli_real_escape_string( $db_handle, $tarfile );
$lene = strlen($esstde);
write_logld( "$me:  es-stderr size: $lene");
$leno = strlen($esstdo);
write_logld( "$me:  es-stdout size: $leno");
$lenf = strlen($estarf);
write_logld( "$me:  es-tarfile size: $lenf");
   $query = "UPDATE gfac.analysis SET " .
            "stderr='"  . $esstde . "'," .
            "stdout='"  . $esstdo . "'," .
            "tarfile='" . $estarf . "'" .
            "WHERE gfacID='$gfacID'";
            ;

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
      echo "Bad query\n";
      return( -2 );
   }

   return 1;
}

## Fetch the first of $candidates that exists on the cluster, landing it at
## $local_name in the current directory.
##
## Returns [ 'class' => 'OK' | 'MISSING' | 'UNREACHABLE' ]: MISSING means the
## cluster answered that it is not there, UNREACHABLE that we do not know.
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

         if ( ! rename( $part, $local_name ) )
         {
            write_logld( "$me: could not move $part into place as $local_name" );
            @unlink( $part );
            return array( 'class' => 'UNREACHABLE' );
         }

         write_logld( "$me: fetched $label from $path" );
         return array( 'class' => 'OK' );
      }

      @unlink( $part );

      if ( remote_exec_infra_fault( $res ) )
      {
         write_logld( "$me: $label fetch from $path: {$res['class']} after {$res['attempts']} attempt(s)" );
         return array( 'class' => 'UNREACHABLE' );
      }

      ## A reachable cluster saying "No such file" is a real answer.
      $saw_missing = true;
      write_logld( "$me: $label not at $path: {$res['stderr']}" );
   }

   return array( 'class' => $saw_missing ? 'MISSING' : 'UNREACHABLE' );
}
?>
