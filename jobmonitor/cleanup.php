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

## Called by both the cron sweep's and the daemon's cleanup().
## Returns -1 terminal, 0 retry (not yet finalizable), 1 finalized / nothing to do.
function resolve_and_cleanup_job( $db_handle, $gfacID, $us3_db, $analysis_table, $log_fn )
{
   $query  = "SELECT count(*) FROM $analysis_table WHERE gfacID='$gfacID'";
   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      $log_fn( "Query failed $query - " . mysqli_error( $db_handle ) );
      mail_to_admin( "fail", "Query failed $query\n" . mysqli_error( $db_handle ) );
      return -1;
   }

   list( $count ) = mysqli_fetch_array( $result );

   if ( $count == 0 )
   {
      return 1;          ## gfacID no longer tracked: nothing to do
   }

   ## The cron sweep and the daemon both reach here for the same job, and
   ## job_cleanup() imports with plain INSERTs, so only the claim holder runs it.
   $claim = cleanup_claim_path( $us3_db, $gfacID );

   if ( ! cleanup_claim_acquire( $claim, $log_fn ) )
   {
      $log_fn( "cleanup already claimed for $gfacID by another worker; skipping" );
      return 1;
   }

   try
   {
      $requestID = get_us3_data();
      if ( $requestID == 0 )
      {
         return -1;
      }

      $log_fn( "calling job_cleanup() reqID=$requestID" );
      return job_cleanup( $us3_db, $requestID, $db_handle );
   }
   finally
   {
      @rmdir( $claim );
   }
}

## Per-job directory under the job log tree; jobmonitor.php's $lock_dir.
## The cron sweep does not set $ll_base_dir, hence the fallback.
function cleanup_job_dir( $us3_db, $gfacID )
{
   global $ll_base_dir;

   $base = ( isset( $ll_base_dir ) && $ll_base_dir != "" )
           ? $ll_base_dir
           : ( exec( "ls -d ~us3/lims" ) . "/etc/joblog" );

   return "$base/$us3_db/$gfacID";
}

## Directory used as the cross-worker cleanup claim for one job.
function cleanup_claim_path( $us3_db, $gfacID )
{
   return cleanup_job_dir( $us3_db, $gfacID ) . "/cleanup.claim";
}

## Seconds since cleanup first found this job not yet finalizable. The first
## call starts the clock, persisted in $seen_file across polls and sweeps.
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

## Keep retrying a job whose cluster is unreachable? True until the ceiling.
function cleanup_retry_unreachable( $seen_file )
{
   return cleanup_pending_seconds( $seen_file ) <= cleanup_complete_ceiling();
}

## Atomically take the claim (mkdir). Returns true if this process now owns
## it. A claim older than an hour is assumed to be from a crashed worker and
## taken over; a slow but live cleanup can hold it for minutes.
function cleanup_claim_acquire( $claim, $log_fn )
{
   $stale_seconds = 3600;

   if ( @mkdir( $claim, 0770, true ) )
      return true;

   ## Already claimed -- take it over only if it is clearly abandoned.
   $mtime = @filemtime( $claim );

   if ( $mtime !== false  &&  ( time() - $mtime ) > $stale_seconds )
   {
      $log_fn( "removing stale cleanup claim $claim (age " . ( time() - $mtime ) . "s)" );
      @rmdir( $claim );
      return (bool) @mkdir( $claim, 0770, true );
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
$aira_details   Stdout          : $stdout
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

   $rx = cluster_probe_remote( $cluster, 'write_logld' );

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

   ## Local staging directory
   if ( ! is_dir( "$work/$gfacID" ) ) mkdir( "$work/$gfacID", 0770 );
   chdir( "$work/$gfacID" );

   ## us_mpi_analysis writes the tar at the top of the work directory;
   ## output/ is a fallback for older layouts.
   $tar_candidates = array(
      "$remoteDir/analysis-results.tar",
      "$remoteDir/output/analysis-results.tar",
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
