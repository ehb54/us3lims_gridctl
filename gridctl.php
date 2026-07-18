<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
include_once "$us3bin/listen-config.php";
include_once "$us3bin/gridctl/jobmonitor/cleanup.php";   ## get_local_files()/mail_to_user()/parse_xml() used by job_cleanup()
include_once "$us3bin/gridctl/jobmonitor/cleanup_job.php";

// Global variables
$gfac_message = "";
$updateTime = 0;
$submittime = 0;
$cluster    = '';

global $status_ex, $status_gw;

// Produce some output temporarily, so cron will send me message
$now = time();
echo "Time started: " . date( 'Y-m-d H:i:s', $now ) . "\n";

write_log( "start of gridctl.php" );

// Get data from global GFAC DB 
$gLink    = mysqli_connect( $dbhost, $guser, $gpasswd, $gDB );

if ( ! $gLink )
{
   write_log( "$self: Could not select DB $gDB - " . mysqli_error() );
   mail_to_admin( "fail",
      "Internal Error: Could not select DB $gDB $dbhost $guser  Server: $servhost" );
   sleep(3);
   exit();
}
   
$query = "SELECT gfacID, us3_db, cluster, status, queue_msg, " .
                "UNIX_TIMESTAMP(time), time, autoflowAnalysisID from analysis";
$result = mysqli_query( $gLink, $query );

if ( ! $result )
{
   write_log( "$self: Query failed $query - " .  mysqli_error( $gLink ) );
   mail_to_admin( "fail", "Query failed $query\n" .  mysqli_error( $gLink ) );
   exit();
}

if ( mysqli_num_rows( $result ) == 0 )
{
//write_log( "$self: analysis read got numrows==0" );
   exit();  // Nothing to do
}
//write_log( "$loghdr    gfac-analysis rows $nrows" );

while ( list( $gfacID, $us3_db, $cluster, $status, $queue_msg, $time, $updateTime, $autoflowID ) 
            = mysqli_fetch_array( $result ) )
{
   write_log( "$self: gfacID=$gfacID gf_status=$status autoflowID=$autoflowID" );

   // Checking we need to do for each entry
echo "us3db=$us3_db  gfid=$gfacID\n";

//   $awork = array();
//   $awork = explode( "-", $gfacID );
//   $gfacLabl = $awork[0] . "-" . $awork[1] . "-" . $awork[2];
   $gfacLabl = $gfacID;
   $loghdr   = $self . ":" . $gfacLabl . "...:";
   $status_ex = $status;

   // Get local job status
   $status_gw  = $status;
   $status     = get_local_status( $gfacID );
   if ( $status_gw == 'COMPLETE'  ||  $status == 'UNKNOWN' )
      $status     = $status_gw;
echo "$loghdr status_lo=$status\n";
write_log( "$loghdr Local status=$status status_gw=$status_gw" );

   // Sometimes during testing, the us3_db entry is not set
   // If $status == 'ERROR' then the condition has been processed before
   if ( strlen( $us3_db ) == 0 && $status != 'ERROR' ) 
   {
      write_log( "$loghdr GFAC DB is NULL - $gfacID" );
      mail_to_admin( "fail", "GFAC DB is NULL\n$gfacID" );

      $query2  = "UPDATE analysis SET status='ERROR' WHERE gfacID='$gfacID'";
      $result2 = mysqli_query( $gLink, $query2 );
      $status  = 'ERROR';

      if ( ! $result2 )
         write_log( "$loghdr Query failed $query2 - " .  mysqli_error( $gLink ) );

      update_autoflow_status( 'ERROR', 'GFAC DB is NULL' );
   }

//echo "  st=$status\n";
write_log( "$loghdr switch status=$status" );
   switch ( $status )
   {
      // Already been handled
      // Later update this condition to search for gfacID?
      case "ERROR":
         cleanup();
         break;

      case "SUBMITTED": 
         submitted( $time );
         break;  

      case "SUBMIT_TIMEOUT": 
         submit_timeout( $time );
         break;  

      case "RUNNING":
      case "STARTED":
      case "STAGING":
      case "ACTIVE":
write_log( "$loghdr   RUNNING gfacID=$gfacID" );
         running( $time, $queue_msg );
         break;

      case "RUN_TIMEOUT":
         run_timeout($time );
         break;

      case "COMPLETED":
      case "COMPLETE":
write_log( "$loghdr   COMPLETE gfacID=$gfacID" );
         complete();
         break;

      case "CANCELLED":
      case "CANCELED":
      case "FAILED":
         failed();
         break;

      case "FINISHED":
      case "DONE":
         complete();
write_log( "$loghdr   FINISHED gfacID=$gfacID" );
      case "PROCESSING":
      default:
         break;
   }
}
mysqli_close( $gLink );

exit();

function submitted( $updatetime )
{
   global $self;
   global $gLink;
   global $gfacID;
   global $autoflowID;
   global $loghdr;

   $now = time();

   if ( $updatetime + 600 > $now ) return; // < 10 minutes ago

   if ( $updatetime + 86400 > $now ) // Within the first 24 hours
   {
      $job_status = get_local_status( $gfacID );

      if ( ! in_array( $job_status, array( 'SUBMITTED', 'INITIALIZED', 'PENDING', 'UNKNOWN' ) ) )
      {
write_log( "$loghdr submitted:job_status=$job_status" );
         update_job_status( $job_status, $gfacID );
      }

      return;
   }

   $message = "Job listed submitted longer than 24 hours";
   write_log( "$self: $message - id: $gfacID" );
   mail_to_admin( "hang", "$message - id: $gfacID" );
   $query = "UPDATE analysis SET status='SUBMIT_TIMEOUT' WHERE gfacID='$gfacID'";
   $result = mysqli_query( $gLink, $query );

   if ( ! $result )
      write_log( "$self: Query failed $query - " .  mysqli_error( $gLink ) );

   update_queue_messages( $message );
   update_db( $message );
   update_autoflow_status( 'SUBMIT_TIMEOUT', $message );

}

function submit_timeout( $updatetime )
{
   global $self;
   global $gLink;
   global $gfacID;
   global $autoflowID;
   global $loghdr;

   $job_status = get_local_status( $gfacID );

   if ( ! in_array( $job_status, array( 'SUBMITTED', 'INITIALIZED', 'PENDING', 'UNKNOWN' ) ) )
   {
      update_job_status( $job_status, $gfacID );
      return;
   }

   $now = time();

   if ( $updatetime + 86400 > $now ) return; // < 24 hours ago ( 48 total submitted )

   $message = "Job listed submitted longer than 48 hours";
   write_log( "$self: $message - id: $gfacID" );
   mail_to_admin( "hang", "$message - id: $gfacID" );
   $query = "UPDATE analysis SET status='FAILED' WHERE gfacID='$gfacID'";
   $result = mysqli_query( $gLink, $query );

   if ( ! $result )
      write_log( "$self: Query failed $query - " .  mysqli_error( $gLink ) );

   update_queue_messages( $message );
   update_db( $message );
   update_autoflow_status( 'FAILED', $message );
}

function running( $updatetime, $queue_msg )
{
   global $self;
   global $gLink;
   global $gfacID;
   global $autoflowID;
   global $loghdr;

   $now = time();

   get_us3_data();

   update_autoflow_status( 'RUNNING', $queue_msg );

   if ( $updatetime + 600 > $now ) {
       return;   // message received < 10 minutes ago
   }

   if ( $updatetime + 86400 > $now ) // Within the first 24 hours
   {
      $job_status = get_local_status( $gfacID );

      if ( ! in_array( $job_status, array( 'ACTIVE', 'RUNNING', 'STARTED', 'UNKNOWN' ) ) ) {
         update_job_status( $job_status, $gfacID );
      }
      return;
   }

   $message = "Job listed running longer than 24 hours";
   write_log( "$self: $message - id: $gfacID" );
   mail_to_admin( "hang", "$message - id: $gfacID" );
   $query = "UPDATE analysis SET status='RUN_TIMEOUT' WHERE gfacID='$gfacID'";
   $result = mysqli_query( $gLink, $query );

   if ( ! $result )
      write_log( "$self: Query failed $query - " .  mysqli_error( $gLink ) );

   update_queue_messages( $message );
   update_db( $message );
   update_autoflow_status( 'RUN_TIMEOUT', $message );
}

function run_timeout( $updatetime )
{
   global $self;
   global $gLink;
   global $gfacID;
   global $autoflowID;
   global $loghdr;

   $job_status = get_local_status( $gfacID );

   if ( ! in_array( $job_status, array( 'ACTIVE', 'RUNNING', 'STARTED', 'UNKNOWN' ) ) )
   {
      update_job_status( $job_status, $gfacID );
      return;
   }

   $now = time();

   get_us3_data();

   if ( $updatetime + 172800 > $now ) return; // < 48 hours ago

   $message = "Job listed running longer than 48 hours";
   write_log( "$self: $message - id: $gfacID" );
   mail_to_admin( "hang", "$message - id: $gfacID" );
   $query = "UPDATE analysis SET status='FAILED' WHERE gfacID='$gfacID'";
   $result = mysqli_query( $gLink, $query );

   if ( ! $result )
      write_log( "$self: Query failed $query - " .  mysqli_error( $gLink ) );

   update_queue_messages( $message );
   update_db( $message );
   update_autoflow_status( 'FAILED', $message );
}

function complete()
{
   // Just cleanup
   return cleanup();
}

function failed()
{
   // Just cleanup
   cleanup();
}

function cleanup()
{
   global $self;
   global $gLink;
   global $gfacID;
   global $autoflowID;
   global $us3_db;
   global $loghdr;

   // Double check that the gfacID exists
   $query  = "SELECT count(*) FROM analysis WHERE gfacID='$gfacID'";
   $result = mysqli_query( $gLink, $query );
  
   if ( ! $result )
   {
      write_log( "$self: Query failed $query - " .  mysqli_error( $gLink ) );
      mail_to_admin( "fail", "Query failed $query\n" .  mysqli_error( $gLink ) );
      return;
   }

   list( $count ) = mysqli_fetch_array( $result );

//if ($count==0)
//write_log( "$loghdr count = $count  gfacID = $gfacID" );
   if ( $count == 0 ) return;

   // Now check the us3 instance
   $requestID = get_us3_data();
//write_log( "$loghdr requestID = $requestID  gfacID = $gfacID" );
   if ( $requestID == 0 ) return;

write_log( "$loghdr calling job_cleanup() reqID=$requestID" );
   job_cleanup( $us3_db, $requestID, $gLink );
}

// Function to update status of job
function update_job_status( $job_status, $gfacID )
{
  global $gLink;
  global $query;
  global $self;
  global $loghdr;
  
  switch ( $job_status )
  {
    case 'SUBMITTED'   :
    case 'SUBMITED'    :
    case 'INITIALIZED' :
    case 'UPDATING'    :
    case 'PENDING'     :
      $status  = 'SUBMITTED';
      $query   = "UPDATE analysis SET status='SUBMITTED' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is SUBMITTED";
      break;

    case 'STARTED'     :
    case 'RUNNING'     :
    case 'ACTIVE'      :
      $status  = 'RUNNING';
      $query   = "UPDATE analysis SET status='RUNNING' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is RUNNING";
      break;

    case 'EXECUTING'      :
      $message = "Job status request reports job is EXECUTING";
      break;

    case 'FINISHED'    :
      $status  = 'FINISHED';
      $query   = "UPDATE analysis SET status='FINISHED' WHERE gfacID='$gfacID'";
      $message = "NONE";
      break;

    case 'DONE'        :
      $status  = 'DONE';
      $query   = "UPDATE analysis SET status='DONE' WHERE gfacID='$gfacID'";
      $message = "NONE";
      break;

    case 'COMPLETED'   :
    case 'COMPLETE'   :
      $status  = 'COMPLETE';
      $query   = "UPDATE analysis SET status='COMPLETE' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is COMPLETED";
      break;

    case 'DATA'        :
      $status  = 'DATA';
      $query   = "UPDATE analysis SET status='DATA' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is COMPLETE, waiting for data";
      break;

    case 'CANCELED'    :
    case 'CANCELLED'   :
      $status  = 'CANCELED';
      $query   = "UPDATE analysis SET status='CANCELED' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is CANCELED";
      break;

    case 'FAILED'      :
      $status  = 'FAILED';
      $query   = "UPDATE analysis SET status='FAILED' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is FAILED";
      break;

    case 'UNKNOWN'     :
write_log( "$loghdr job_status='UNKNOWN', reset to 'ERROR' " );
      $status  = 'ERROR';
      $query   = "UPDATE analysis SET status='ERROR' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is not in the queue";
      break;

    default            :
      // We shouldn't ever get here
      $status  = 'ERROR';
      $query   = "UPDATE analysis SET status='ERROR' WHERE gfacID='$gfacID'";
      $message = "Job status was not recognized - $job_status";
      write_log( "$loghdr update_job_status: " .
                 "Job status was not recognized - $job_status\n" .
                 "gfacID = $gfacID\n" );
      break;

  }

   $result =  mysqli_query( $gLink, $query );
   if ( ! $result )
      write_log( "$loghdr Query failed $query - " .  mysqli_error( $gLink ) );

   if ( $message != 'NONE' )
   {
      update_queue_messages( $message );
      update_db( $message );
      update_autoflow_status( $status, $message );
   } else {
      update_autoflow_status( $status, $status );
   }
}

function get_us3_data()
{
   global $self;
   global $gfacID;
   global $autoflowID;
   global $dbhost;
   global $user;
   global $passwd;
   global $us3_db;
   global $updateTime;
   global $loghdr;

   $us3_link = mysqli_connect( $dbhost, $user, $passwd, $us3_db );

   if ( ! $us3_link )
   {
      write_log( "$loghdr could not connect: $dbhost, $user, $passwd, $us3_db" );
      mail_to_admin( "fail", "Could not connect to $dbhost : $us3_db" );
      return 0;
   }

   $query = "SELECT HPCAnalysisRequestID, UNIX_TIMESTAMP(updateTime) " .
            "FROM HPCAnalysisResult WHERE gfacID='$gfacID'";
   $result = mysqli_query( $us3_link, $query );

   if ( ! $result )
   {
      write_log( "$self: Query failed $query - " .  mysqli_error( $us3_link ) );
      mail_to_admin( "fail", "Query failed $query\n" .  mysqli_error( $us3_link ) );
      return 0;
   }

   $numrows =  mysqli_num_rows( $result );
   if ( $numrows > 1 )
   {  // Duplicate gfacIDs:  get last
      $query = "SELECT HPCAnalysisRequestID, UNIX_TIMESTAMP(updateTime) " .
               "FROM HPCAnalysisResult WHERE gfacID='$gfacID' " .
               " ORDER BY HPCAnalysisResultID DESC LIMIT 1";
      $result = mysqli_query( $us3_link, $query );
   }

   list( $requestID, $updateTime ) = mysqli_fetch_array( $result );
   mysqli_close( $us3_link );

   return $requestID;
}

// Function to get status from local cluster
function get_local_status( $gfacID )
{
   global $cluster;
   global $self;

   $is_demel3 = preg_match( "/demeler3/",   $cluster );
   $is_demel1 = preg_match( "/demeler1/",   $cluster );
   $is_jetstr = preg_match( "/jetstream/",  $cluster );
   $is_chino  = preg_match( "/chinook/",    $cluster );
   $is_umont  = preg_match( "/umontana/",   $cluster );
   $is_us3iab = preg_match( "/us3iab/",     $cluster );
   ## slurm-head: containerized Slurm head node (dev and USiaB production)
   $is_slurm_head = preg_match( "/slurm-head/", $cluster );
   $is_slurm  = ( $is_jetstr  ||  $is_us3iab  ||  $is_slurm_head );
   $is_squeu  = ( $is_jetstr  ||  $is_chino  ||  $is_umont  ||  $is_us3iab || $is_demel1 || $is_slurm_head );
   $ruser     = "us3";

   if ( $is_squeu )
      $cmd    = "squeue -t all -j $gfacID 2>&1|tail -n 1";
   else
      $cmd    = "/usr/bin/qstat -a $gfacID 2>&1|tail -n 1";
   write_log( "$self gfacID $gfacID cluster $cluster" );

   if ( ! $is_us3iab )
   {
      if ( $is_slurm_head )
      {  ## containerized slurm-head: SSH config handles User/host, no domain needed
         $system = "slurm-head";
      }
      else
      {
         $system = "$cluster.uleth.ca";
         if ( $is_slurm )
            $system = "$cluster";
         $system = preg_replace( "/\-local/", "", $system );

         if ( $is_demel3 )
         {
           $system = "demeler3.uleth.ca";
         }
         if ( $is_chino )
         {
           $system = "chinook.hs.umt.edu";
         }
         if ( $is_umont )
         {
           $system = "login.gscc.umt.edu";
           $ruser  = "bd142854e";
         }
      }

      write_log( "$self !is_usiab system $system" );
      $cmd    = "/usr/bin/ssh -x $ruser@$system " . $cmd;
write_log( "$self  cmd: $cmd" );
   }

   $result = exec( $cmd );
echo "locstat: cmd=$cmd  result=$result\n";
write_log( "$self  locstat: cmd=$cmd  result=$result" );

   $secwait    = 2;
   $num_try    = 0;
   // Sleep and retry up to 3 times if ssh has "ssh_exchange_identification" error
   while ( preg_match( "/ssh_exchange_id/", $result )  &&  $num_try < 3 )
   {
      sleep( $secwait );
      $num_try++;
      $secwait   *= 2;
write_log( "$self:   num_try=$num_try  secwait=$secwait" );
   }

   if ( preg_match( "/^qstat: Unknown/", $result )  ||
        preg_match( "/ssh_exchange_id/", $result ) )
   {
      write_log( "$self get_local_status: Local job $gfacID unknown result=$result" );
      return 'UNKNOWN';
   }

   $values = preg_split( "/\s+/", $result );
   $jstat   = ( $is_squeu == 0 ) ? $values[ 9 ] : $values[ 5 ];
write_log( "$self: get_local_status: job status = /$jstat/");
   switch ( $jstat )
   {
      case "W" :                      // Waiting for execution time to be reached
      case "E" :                      // Job is exiting after having run
      case "R" :                      // Still running
      case "CG" :                     // Job is completing
        $status = 'ACTIVE';
        break;

      case "C" :                      // Job has completed
      case "ST" :                     // Job has disappeared
      case "CD" :                     // Job has completed
        $status = 'COMPLETED';
        break;

      case "T" :                      // Job is being moved
      case "H" :                      // Held
      case "Q" :                      // Queued
      case "PD" :                     // Queued
      case "CF" :                     // Queued
        $status = 'SUBMITTED';
        break;

      case "CA" :                     // Job has been canceled
        $status = 'CANCELED';
        break;

      case "F"  :                     // Job has failed
      case "BF" :                     // Job has failed
      case "NF" :                     // Job has failed
      case "TO" :                     // Job has timed out
      case ""   :                     // Job has disappeared
        $status = 'FAILED';
        break;

      default :
        $status = 'UNKNOWN';          // This should not occur
        break;
   }
write_log( "$self: get_local_status: status = $status");
  
   return $status;
}

function update_queue_messages( $message )
{
   global $self;
   global $gLink;
   global $gfacID;

   // Get analysis table ID
   $query  = "SELECT id FROM analysis " .
             "WHERE gfacID = '$gfacID' ";
   $result = mysqli_query( $gLink, $query );
   if ( ! $result )
   {
      write_log( "$self: Query failed $query - " .  mysqli_error( $gLink ) );
      return;
   }
   list( $analysisID ) = mysqli_fetch_array( $result );

   // Insert message into queue_message table
   $query  = "INSERT INTO queue_messages SET " .
             "message = '" . mysqli_real_escape_string( $gLink, $message ) . "', " .
             "analysisID = '$analysisID' ";
   $result = mysqli_query( $gLink, $query );
   if ( ! $result )
   {
      write_log( "$self: Query failed $query - " .  mysqli_error( $gLink ) );
      return;
   }
}

function update_db( $message )
{
   global $self;
   global $gfacID;
   global $dbhost;
   global $user;
   global $passwd;
   global $us3_db;

   $us3_link = mysqli_connect( $dbhost, $user, $passwd, $us3_db );

   if ( ! $us3_link )
   {
      write_log( "$self: could not connect: $dbhost, $user, $passwd" );
      mail_to_admin( "fail", "Could not connect to $dbhost : $us3_db" );
      return 0;
   }

   $requestID = get_us3_data();

   $query = "UPDATE HPCAnalysisResult SET " .
            "lastMessage='" . mysqli_real_escape_string( $us3_link, $message ) . "'" .
            "WHERE gfacID = '$gfacID' AND HPCAnalysisRequestID = '$requestID' ";

   mysqli_query( $us3_link, $query );
   mysqli_close( $us3_link );
}

function mail_to_admin( $type, $msg )
{
   global $updateTime;
   global $status;
   global $cluster;
   global $org_name;
   global $admin_email;
   global $dbhost;
   global $servhost;
   global $requestID;

   $headers  = "From: $org_name Admin<$admin_email>"     . "\n";
   $headers .= "Cc: $org_name Admin<$admin_email>"       . "\n";
   //   $headers .= "Cc: $org_name Admin<alexsav.science@gmail.com>"       . "\n";
   $headers .= "Bcc: $org_name Admin<$admin_email>" . "\n";     // make sure

   // Set the reply address
   $headers .= "Reply-To: $org_name<$admin_email>"      . "\n";
   $headers .= "Return-Path: $org_name<$admin_email>"   . "\n";

   // Try to avoid spam filters
   $now = time();
   $tnow = date( 'Y-m-d H:i:s' );
   $headers .= "Message-ID: <" . $now . "gridctl@$dbhost>$requestID\n";
   $headers .= "X-Mailer: PHP v" . phpversion()         . "\n";
   $headers .= "MIME-Version: 1.0"                      . "\n";
   $headers .= "Content-Transfer-Encoding: 8bit"        . "\n";

   $subject       = "US3 Error Notification";
   $message       = "
   UltraScan job error notification from gridctl.php ($servhost):

   Update Time    :  $updateTime  [ now=$tnow ]
   GFAC Status    :  $status
   Cluster        :  $cluster
   ";

   $message .= "Error Message  :  $msg\n";

   mail( $admin_email, $subject, $message, $headers );
}

function update_autoflow_status( $status, $message ) {
    global $gLink;
    global $gfacID;
    global $autoflowID;
    global $us3_db;
    global $self;

    write_log( "$self: update_autoflow_status() id $autoflowID status $status message $message" );
        
    if ( $autoflowID <= 0 ) {
        write_log( "$self: update_autoflow_status() ignored, no id" );
        return;
    }
    # escape quotes in message
    $sqlmessage = str_replace( "'", "\'", $message );
    $query = "UPDATE {$us3_db}.autoflowAnalysis SET " .
        "status='$status', " . 
        "statusMsg='$sqlmessage' " . 
        "WHERE requestID = '$autoflowID' AND currentGfacID = '$gfacID' AND NOT status RLIKE '^(failed|error|canceled)\$'";
    
    $result = mysqli_query( $gLink, $query );
    if ( ! $result ) {
        // Just log it and continue
        write_log( "$self: Bad query:\n$query\n" . mysqli_error( $gLink ) );
    }
}
   
?>
