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

## Shared glue for gridctl.php's cron sweep and jobmonitor/gridctl.php's
## per-job watcher: both call this from their own cleanup() so the
## gfacID-exists check, requestID lookup, and job_cleanup() dispatch/return
## live in one place instead of two independently-maintained copies.
## Contract: -1 terminal, 0 retry (not yet finalizable), 1 finalized / nothing to do.
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

   ## Claim this job before doing any work. Two independent workers reach
   ## here for the same gfacID -- the per-minute gridctl.php cron sweep
   ## (/etc/cron.d/uslims, which takes no lock of its own) and the per-job
   ## jobmonitor.php daemon (whose lock only excludes other jobmonitors).
   ##
   ## Without a claim both can pass the row-exists check above and both run
   ## job_cleanup(), which issues plain INSERTs (model, noise,
   ## pcsa_modelrecs, modelPerson, HPCAnalysisResultData -- no REPLACE, no
   ## ON DUPLICATE KEY), so a double run duplicates imported result rows in
   ## the LIMS database.
   ##
   ## mkdir() is atomic on POSIX, so exactly one worker gets the claim; the
   ## loser returns "nothing to do" rather than racing.
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

## Directory used as the cross-worker cleanup claim for one job.
function cleanup_claim_path( $us3_db, $gfacID )
{
   global $ll_base_dir;

   $base = ( isset( $ll_base_dir ) && $ll_base_dir != "" )
           ? $ll_base_dir
           : ( exec( "ls -d ~us3/lims" ) . "/etc/joblog" );

   return "$base/$us3_db/$gfacID/cleanup.claim";
}

## Atomically take the claim. Returns true if this process now owns it.
##
## A crashed worker would otherwise leave the claim behind and block cleanup
## for that job forever, so a claim older than the stale threshold is taken
## over. The threshold is generous: job_cleanup() does several scp fetches
## with sleep/backoff, so a legitimately slow run can hold the claim for
## minutes.
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

   ## Read the stored job status and message. The helper retains its historical
   ## name because it is part of the gridctl compatibility surface.
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

## Compatibility helper for historical externally assigned analysis IDs.
function get_gfac_message( $gfacID )
{
  global $serviceURL;
  global $me;

  $hex = "[0-9a-fA-F]";
  if ( ! preg_match( "/^US3-Experiment/i", $gfacID ) &&
       ! preg_match( "/^US3-$hex{8}-$hex{4}-$hex{4}-$hex{4}-$hex{12}$/", $gfacID ) )
   {
      ## This is not one of the historical external analysis-ID formats.
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

## Stage a finished job's stderr, stdout and analysis-results.tar off the
## cluster and into its central job-tracking row.
##
## Returns:
##    1  files staged (or definitively absent -- the cluster answered)
##   -1  the cluster could not be reached, so we do not yet know whether the
##       results exist. The caller must NOT finalize the job on this. "No
##       results" and "could not ask" are different facts, and a retry budget
##       measured in seconds cannot outlast an outage measured in minutes, so
##       treating them alike mails users "No results tarfile" for jobs whose
##       results are sitting intact on the cluster.
/**
 * Ask a cluster where its work directory is, and read the answer.
 *
 * Separate from get_local_files() because the answer becomes the base of every
 * path this file subsequently fetches from, so it is worth being able to
 * assert on it on its own. A wrong path here does not fail loudly: the fetches
 * simply find nothing, and the job looks like one whose results never arrived.
 *
 * Returns array( 'class' => 'OK' | 'UNREACHABLE' | 'MISSING',
 *                'path'  => the directory, when class is OK,
 *                'detail'=> a short reason, otherwise ).
 *
 * UNREACHABLE means we learned nothing and the caller must retry rather than
 * conclude anything about the job. MISSING is a real answer from a reachable
 * cluster: there is no such directory, so there is nothing to fetch. Making
 * that distinction explicitly, and on its own, is the whole point of the
 * function.
 *
 * The answer is trusted as-is because remote_exec::run() fences the command's
 * own stdout, so a login node's ~/.bashrc cannot put its welcome banner where
 * this is looking for a path. See frame() there.
 */
function resolve_remote_workdir( $rx, $lworkdir )
{
   $res = $rx->run( "ls -d " . escapeshellarg( $lworkdir ), array( 'label' => 'resolve workdir' ) );

   if ( remote_exec_infra_fault( $res ) )
      return array( 'class' => 'UNREACHABLE', 'path' => '', 'detail' => $res[ 'class' ] );

   if ( ! $res[ 'ok' ] || trim( $res[ 'text' ] ) === '' )
      return array( 'class' => 'MISSING', 'path' => '', 'detail' => $res[ 'stderr' ] );

   return array( 'class' => 'OK', 'path' => trim( $res[ 'text' ] ), 'detail' => '' );
}

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
      return -1;
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

   ## us_mpi_analysis writes analysis-results.tar into the TOP LEVEL of the
   ## job's work directory: US_Archive::compress() is given a bare relative
   ## filename and the batch script cd's to $workdir before launching. It is
   ## not written under output/ -- output/ holds the individual result files
   ## that get archived into the tar and then deleted. Try the top-level
   ## location first and keep output/ as a fallback so any job laid out the
   ## older way still resolves.
   $tar_candidates = array(
      "$remoteDir/analysis-results.tar",
      "$remoteDir/output/analysis-results.tar",
   );

   $tar = fetch_first_remote( $rx, $tar_candidates, 'analysis-results.tar', 'tarfile' );

   ## The remote job may finish writing stdout/stderr before it finishes
   ## writing and closing analysis-results.tar, so a fetch can legitimately
   ## race ahead of the result being ready. Retry that race -- but only when
   ## the cluster is answering. Retrying into an outage just burns the budget
   ## and then reports the wrong conclusion.
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

   ## stdout/stderr are diagnostics. Their absence is not fatal, but an
   ## unreachable cluster still is: finalizing now would persist an empty
   ## stderr for a job whose real stderr we simply could not read.
   foreach ( array( 'stdout', 'stderr' ) as $fn )
   {
      $one = fetch_first_remote( $rx, array( "$remoteDir/$fn" ), $fn, $fn );

      if ( $one[ 'class' ] === 'UNREACHABLE' )
      {
         write_logld( "$me: cluster $cluster unreachable fetching $fn for $gfacID; will retry" );
         return -1;
      }
   }

   ## Store the staged files in the central job-tracking database. The fetch
   ## above has already retried, so there is nothing further to wait for here.

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

   ## Both remote candidates land at the same local name (fetch_first_remote
   ## chooses it), so there is only one local path to read.
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
      return( -1 );
   }

   return 1;
}

## Fetch the first of $candidates that exists on the cluster, landing it at
## $local_name in the current directory.
##
## Returns [ 'class' => 'OK' | 'MISSING' | 'UNREACHABLE' ]. The three are
## genuinely different outcomes and the caller acts differently on each:
## OK means we have the file, MISSING means the cluster answered and it is not
## there (yet), UNREACHABLE means we still do not know. Collapsing MISSING and
## UNREACHABLE into a single "failed" is what made an outage look like a job
## that produced no results.
##
## The download lands on a ".part" path and is renamed into place only after
## scp exits 0. scp is not atomic: an interrupted transfer -- exactly what a
## flaky link or a timeout(1) kill produces, and something the retry loop above
## now makes far more likely -- leaves a truncated file behind. Without the
## rename, that truncated file satisfies the caller's file_exists() check and a
## corrupt tarball is written into the job record as if it were the result.
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
