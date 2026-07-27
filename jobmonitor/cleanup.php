<?php
/*
 * cleanup.php
 *
 * functions relating to copying results and cleaning up the gfac DB
 *  where the job used an Airavata interface.
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

   ## Get GFAC status and message
   ## function get_gfac_message() also sets global $status
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
### not RFC5322 compliant to have multiple duplicate headers
   $headers .= "Cc: $org_name Admin<$admin_email>, $org_name Admin<gegorbet@gmail.com>\n";
#   $headers .= "CC: $org_name Admin<alexsav.science@gmail.com>"       . "\n";
#   $headers .= "CC: $org_name Admin<gegorbet@gmail.com>"       . "\n";

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
   GFAC Status     : $status
   GFAC Message    : $gfac_message
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

## Function to get information about the current job GFAC
function get_gfac_message( $gfacID )
{
  global $serviceURL;
  global $me;

  $hex = "[0-9a-fA-F]";
  if ( ! preg_match( "/^US3-Experiment/i", $gfacID ) &&
       ! preg_match( "/^US3-$hex{8}-$hex{4}-$hex{4}-$hex{4}-$hex{12}$/", $gfacID ) )
   {
      ## Then it's not a GFAC job
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

## Run each scp candidate in turn, stopping at the first that succeeds.
## Returns the exit status of the successful command, or of the last one
## tried if none succeeded; $output collects every attempt's output.
function fetch_first_available( $cmds, &$output )
{
   $output = array();
   $stat   = 1;

   foreach ( $cmds as $cmd )
   {
      $this_output = array();
      exec( $cmd, $this_output, $stat );
      $output = array_merge( $output, array( $cmd ), $this_output );

      if ( $stat == 0 )
         return 0;
   }

   return $stat;
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

   ## Figure out job's remote work directory
   $remoteDir = sprintf( "$work_remote/$db-%06d", $requestID );

   ## Get stdout, stderr, output/analysis-results.tar
   $output = array();

   ## Always stage results over scp, co-located clusters included. The old
   ## 'localhost' branch read the job directory in place via chdir(); that
   ## made the co-located path the only one that never exercised the copy
   ## logic, and made file retrieval depend on the cleanup process happening
   ## to share a filesystem with the compute node. Copying into
   ## $work/$gfacID is what every remote cluster already does.
   $used_scp = true;

   write_logld( "$me get_local_files(): scp to get files" );

   {
      if ( !array_key_exists( $cluster, $cluster_details )
           || !array_key_exists( 'name', $cluster_details[$cluster] ) )
      {
         write_logld( "$me cluster $cluster missing from global_config.php \$cluster_details" );
         return;
      }

      ## 'login' is already a user@host string when present; otherwise
      ## default to the us3 user against the bare 'name' host.
      $remote_login = array_key_exists( 'login', $cluster_details[$cluster] )
                      ? $cluster_details[$cluster]['login']
                      : 'us3@' . $cluster_details[$cluster]['name'];

      $lworkdir = array_key_exists( 'workdir', $cluster_details[$cluster] )
                  ? $cluster_details[$cluster]['workdir']
                  : "$work/local";

      $port     = $cluster_details[$cluster]['sshport'] ?? 22;

      $cmd         = "ssh -p $port $remote_login 'ls -d $lworkdir' 2>/dev/null";
      exec( $cmd, $output, $stat );
      $work_remote = $output[ 0 ];
      $remoteDir   = sprintf( "$work_remote/$db-%06d", $requestID );
write_logld( "$me:  -LOCAL: remoteDir=$remoteDir" );

      ## Figure out local working directory
      if ( ! is_dir( "$work/$gfacID" ) ) mkdir( "$work/$gfacID", 0770 );
      $pwd = chdir( "$work/$gfacID" );

      ## us_mpi_analysis writes analysis-results.tar into the TOP LEVEL of the
      ## job's work directory: US_Archive::compress() is given a bare relative
      ## filename and the batch script cd's to $workdir before launching. It
      ## is not written under output/ -- output/ holds the individual result
      ## files that get archived into the tar and then deleted.
      ##
      ## Only the output/ path was ever fetched here, which is why remote
      ## clusters always reported "Failed data fetch". The co-located path
      ## masked the same mistake by chdir'ing into the job directory and
      ## finding the top-level file locally, without any scp.
      ##
      ## Try the top-level location first and keep output/ as a fallback so
      ## any job laid out the older way still resolves. $tarcmds is reused by
      ## the retry loop further down.
      $tarcmds = [
         "scp -P $port $remote_login:$remoteDir/analysis-results.tar . 2>&1",
         "scp -P $port $remote_login:$remoteDir/output/analysis-results.tar . 2>&1",
      ];

      $stat = fetch_first_available( $tarcmds, $output );
      if ( $stat != 0 )
      {
         write_logld( "$me: tarfile fetch failed:\n" . implode( "\n", $tarcmds ) . "\n" . implode( "\n", $output ) );
         sleep( 10 );
         write_logld( "$me: RETRY" );
         $stat = fetch_first_available( $tarcmds, $output );
         if ( $stat != 0 )
            write_logld( "$me: tarfile fetch failed again:\n" . implode( "\n", $output ) );
      }

      $cmd = "scp -P $port $remote_login:$remoteDir/stdout . 2>&1";

      exec( $cmd, $output, $stat );
      if ( $stat != 0 )
      {
         write_logld( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );
         sleep( 10 );
         write_logld( "$me: RETRY" );
         exec( $cmd, $output, $stat );
         if ( $stat != 0 )
            write_logld( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );
      }

      $cmd = "scp -P $port $remote_login:$remoteDir/stderr . 2>&1";

      exec( $cmd, $output, $stat );
      if ( $stat != 0 )
      {
         write_logld( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );
         sleep( 10 );
         write_logld( "$me: RETRY" );
         exec( $cmd, $output, $stat );
         if ( $stat != 0 )
            write_logld( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );
      }
   }


   ## Write the files to gfacDB

   $secwait    = 10;
   $num_try    = 0;
   while ( ! file_exists( "stderr" )  &&  $num_try < 3 )
   {  ## Do waits and retries to let stderr appear
      sleep( $secwait );
      $num_try++;
      $secwait   *= 2;
write_logld( "$me:  not-exist-stderr: num_try=$num_try" );
   }

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

   $fn1_tarfile = "analysis-results.tar";
   $fn2_tarfile = "output/" . $fn1_tarfile;

   ## The remote job may finish writing stdout/stderr before it finishes
   ## writing/closing analysis-results.tar, so the earlier scp of the tar
   ## can race ahead of the result being ready.  Retry with backoff before
   ## giving up, separately from the stderr wait above.
   $secwait = 10;
   $num_try = 0;
   while ( ! file_exists( $fn1_tarfile )  &&  ! file_exists( $fn2_tarfile )  &&  $num_try < 3 )
   {
      sleep( $secwait );
      if ( $used_scp )
      {
         $stat = fetch_first_available( $tarcmds, $output );
         if ( $stat != 0 )
         {
            write_logld( "$me: tarfile retry fetch failed:\n" . implode( "\n", $output ) );
         }
      }
      $num_try++;
      $secwait *= 2;
write_logld( "$me:  not-exist-tarfile: num_try=$num_try" );
   }

   if ( file_exists( $fn1_tarfile ) )
      $tarfile = file_get_contents( $fn1_tarfile );
   else if ( file_exists( $fn2_tarfile ) )
      $tarfile = file_get_contents( $fn2_tarfile );

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
}
?>
