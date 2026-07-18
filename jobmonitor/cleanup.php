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

   $requestID = get_us3_data();
   if ( $requestID == 0 )
   {
      return -1;
   }

   $log_fn( "calling job_cleanup() reqID=$requestID" );
   return job_cleanup( $us3_db, $requestID, $db_handle );
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

   $is_local = array_key_exists( $cluster, $cluster_details )
               && array_key_exists( 'localhost', $cluster_details[$cluster] )
               && $cluster_details[$cluster]['localhost'];

   ## Figure out job's remote (or local) work directory
   $remoteDir = sprintf( "$work_remote/$db-%06d", $requestID );
##write_logld( "$me: is_local=$is_local  remoteDir=$remoteDir" );

   ## Get stdout, stderr, output/analysis-results.tar
   $output = array();

   $used_scp = ! $is_local;

   if ( $used_scp )
   {
       write_logld( "$me get_local_files(): scp to get files" );

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

      $cmd         = "ssh $remote_login 'ls -d $lworkdir' 2>/dev/null";
      exec( $cmd, $output, $stat );
      $work_remote = $output[ 0 ];
      $remoteDir   = sprintf( "$work_remote/$db-%06d", $requestID );
write_logld( "$me:  -LOCAL: remoteDir=$remoteDir" );

      ## Figure out local working directory
      if ( ! is_dir( "$work/$gfacID" ) ) mkdir( "$work/$gfacID", 0770 );
      $pwd = chdir( "$work/$gfacID" );

      $tarcmd = "scp $remote_login:$remoteDir/output/analysis-results.tar . 2>&1";

      exec( $tarcmd, $output, $stat );
      if ( $stat != 0 )
      {
         write_logld( "$me: Bad exec:\n$tarcmd\n" . implode( "\n", $output ) );
         sleep( 10 );
         write_logld( "$me: RETRY" );
         exec( $tarcmd, $output, $stat );
         if ( $stat != 0 )
            write_logld( "$me: Bad exec:\n$tarcmd\n" . implode( "\n", $output ) );
      }

      $cmd = "scp $remote_login:$remoteDir/stdout . 2>&1";

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

      $cmd = "scp $remote_login:$remoteDir/stderr . 2>&1";

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
   else
   { ## Cluster's 'localhost' config flag is set, so just change to local work directory
      $pwd = chdir( "$remoteDir" );
write_logld( "$me: IS US3IAB: pwd=$pwd $remoteDir");
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
         exec( $tarcmd, $output, $stat );
         if ( $stat != 0 )
         {
            write_logld( "$me: Bad exec:\n$tarcmd\n" . implode( "\n", $output ) );
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
