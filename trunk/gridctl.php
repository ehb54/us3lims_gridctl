<?php

include_once "/home/us3/bin/listen-config.php";
include "/home/us3/bin/cleanup.php";

// Global variables
$gfac_message = "";
$updateTime = 0;
$submittime = 0;
$cluster    = '';

// Produce some output temporarily, so cron will send me message
$now = time();
//echo "Time started: " . date( 'Y-m-d H:i:s', $now ) . "\n";

// Get data from global GFAC DB 
$gLink = mysql_connect( $dbhost, $guser, $gpasswd );

if ( ! mysql_select_db( $gDB, $gLink ) )
{
   write_log( "$self: Could not select DB $gDB - " . mysql_error() );
   mail_to_admin( "fail", "Internal Error: Could not select DB $gDB" );
   exit();
}
   
$query = "SELECT gfacID, us3_db, cluster, status, queue_msg, " .
                "UNIX_TIMESTAMP(time), time from analysis";
$result = mysql_query( $query, $gLink );

if ( ! $result )
{
   write_log( "$self: Query failed $query - " .  mysql_error( $gLink ) );
   mail_to_admin( "fail", "Query failed $query\n" .  mysql_error( $gLink ) );
   exit();
}

if ( mysql_num_rows( $result ) == 0 )
   exit();  // Nothing to do

while ( list( $gfacID, $us3_db, $cluster, $status, $queue_msg, $time, $updateTime ) 
            = mysql_fetch_array( $result ) )
{
   // Checking we need to do for each entry

   // Sometimes during testing, the us3_db entry is not set
   // If $status == 'ERROR' then the condition has been processed before
   if ( strlen( $us3_db ) == 0 && $status != 'ERROR' ) 
   {
      write_log( "$self: GFAC DB is NULL - $gfacID" );
      mail_to_admin( "fail", "GFAC DB is NULL\n$gfacID" );

      $query2  = "UPDATE analysis SET status='ERROR' WHERE gfacID='$gfacID'";
      $result2 = mysql_query( $query2, $gLink );
      $status  = 'ERROR';

      if ( ! $result2 )
         write_log( "$self: Query failed $query2 - " .  mysql_error( $gLink ) );

   }

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
         running( $time );
         break;

      case "RUN_TIMEOUT":
         run_timeout($time );
         break;

      case "DATA":
         wait_data( $time );
         break;

      case "DATA_TIMEOUT":
         data_timeout( $time );
         break;

      case "COMPLETE":
         complete();
         break;

      case "CANCELLED":
      case "CANCELED":
      case "FAILED":
         failed();
         break;

      default:
         break;
   }
}

exit();

function submitted( $updatetime )
{
   global $self;
   global $gLink;
   global $gfacID;

   $now = time();

   if ( $updatetime + 600 > $now ) return; // < 10 minutes ago

   if ( $updatetime + 86400 > $now ) // Within the first 24 hours
   {
      if ( ( $job_status = get_gfac_status( $gfacID ) ) === false )
         $job_status = get_local_status( $gfacID );

      if ( $job_status == 'GFAC_STATUS_UNAVAILABLE' ) 
         return;

      if ( ! in_array( $job_status, array( 'SUBMITTED', 'INITIALIZED', 'PENDING' ) ) )
         update_job_status( $job_status, $gfacID );

      return;
   }

   $message = "Job listed submitted longer than 24 hours";
   write_log( "$self: $message - id: $gfacID" );
   mail_to_admin( "hang", "$message - id: $gfacID" );
   $query = "UPDATE analysis SET status='SUBMIT_TIMEOUT' WHERE gfacID='$gfacID'";
   $result = mysql_query( $query, $gLink );

   if ( ! $result )
      write_log( "$self: Query failed $query - " .  mysql_error( $gLink ) );

   update_queue_messages( $message );
   update_db( $message );
}

function submit_timeout( $updatetime )
{
   global $self;
   global $gLink;
   global $gfacID;

   if ( ( $job_status = get_gfac_status( $gfacID ) ) === false )
      $job_status = get_local_status( $gfacID );

   if ( $job_status == 'GFAC_STATUS_UNAVAILABLE' ) 
      return;

   if ( ! in_array( $job_status, array( 'SUBMITTED', 'INITIALIZED', 'PENDING' ) ) )
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
   $result = mysql_query( $query, $gLink );

   if ( ! $result )
      write_log( "$self: Query failed $query - " .  mysql_error( $gLink ) );

   update_queue_messages( $message );
   update_db( $message );
}

function running( $updatetime )
{
   global $self;
   global $gLink;
   global $gfacID;

   $now = time();

   get_us3_data();

   if ( $updatetime + 600 > $now ) return;   // message received < 10 minutes ago

   if ( $updatetime + 86400 > $now ) // Within the first 24 hours
   {
      if ( ( $job_status = get_gfac_status( $gfacID ) ) === false )
         $job_status = get_local_status( $gfacID );

      if ( $job_status == 'GFAC_STATUS_UNAVAILABLE' ) 
         return;

      if ( $job_status != 'ACTIVE' )
         update_job_status( $job_status, $gfacID );

      return;
   }

   $message = "Job listed running longer than 24 hours";
   write_log( "$self: $message - id: $gfacID" );
   mail_to_admin( "hang", "$message - id: $gfacID" );
   $query = "UPDATE analysis SET status='RUN_TIMEOUT' WHERE gfacID='$gfacID'";
   $result = mysql_query( $query, $gLink );

   if ( ! $result )
      write_log( "$self: Query failed $query - " .  mysql_error( $gLink ) );

   update_queue_messages( $message );
   update_db( $message );
}

function run_timeout( $updatetime )
{
   global $self;
   global $gLink;
   global $gfacID;

   if ( ( $job_status = get_gfac_status( $gfacID ) ) === false )
      $job_status = get_local_status( $gfacID );

   if ( $job_status == 'GFAC_STATUS_UNAVAILABLE' ) 
      return;

   if ( $job_status != 'ACTIVE' )
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
   $result = mysql_query( $query, $gLink );

   if ( ! $result )
      write_log( "$self: Query failed $query - " .  mysql_error( $gLink ) );

   update_queue_messages( $message );
   update_db( $message );
}

function wait_data( $updatetime )
{
   global $self;
   global $gLink;
   global $gfacID;

   $now = time();

   if ( $updatetime + 3600 > $now ) // < Within the first hour
   {
      if ( ( $job_status = get_gfac_status( $gfacID ) ) === false )
         $job_status = get_local_status( $gfacID );
      
      if ( $job_status == 'GFAC_STATUS_UNAVAILABLE' ) 
         return;

      if ( $job_status != 'DATA' )
      {
         update_job_status( $job_status, $gfacID );
         return;
      }

      // Request to resend data, but only request every 5 minutes
      $minute = date( 'i' ) * 1; // Makes it an int
      if ( $minute % 5 ) return;
   
      $output_status = get_gfac_outputs( $gfacID );

      if ( $output_status !== false )
         mail_to_admin( "debug", "wait_data/$gfacID/$output_status" );

      return;
   }

   $message = "Waiting for data longer than 1 hour";
   write_log( "$self: $message - id: $gfacID" );
   mail_to_admin( "hang", "$message - id: $gfacID" );
   $query = "UPDATE analysis SET status='DATA_TIMEOUT' WHERE gfacID='$gfacID'";
   $result = mysql_query( $query, $gLink );

   if ( ! $result )
      write_log( "$self: Query failed $query - " .  mysql_error( $gLink ) );

   update_queue_messages( $message );
   update_db( $message );
}

function data_timeout( $updatetime )
{
   global $self;
   global $gLink;
   global $gfacID;

   if ( ( $job_status = get_gfac_status( $gfacID ) ) === false )
      $job_status = get_local_status( $gfacID );

   if ( $job_status == 'GFAC_STATUS_UNAVAILABLE' ) 
      return;

   if ( $job_status != 'DATA' )
   {
      update_job_status( $job_status, $gfacID );
      return;
   }

   $now = time();

   if ( $updatetime + 86400 > $now ) // < 24 hours ago
   {
      // Request to resend data, but only request every 15 minutes
      $minute = date( 'i' ) * 1; // Makes it an int
      if ( $minute % 15 ) return;
   
      $output_status = get_gfac_outputs( $gfacID );

      if ( $output_status !== false )
         mail_to_admin( "debug", "data_timeout/$gfacID/$output_status" );

      return;
   }

   $message = "Waiting for data longer than 24 hours";
   write_log( "$self: $message - id: $gfacID" );
   mail_to_admin( "hang", "$message - id: $gfacID" );
   $query = "UPDATE analysis SET status='FAILED' WHERE gfacID='$gfacID'";
   $result = mysql_query( $query, $gLink );

   if ( ! $result )
      write_log( "$self: Query failed $query - " .  mysql_error( $gLink ) );

   update_queue_messages( $message );
   update_db( $message );
}

function complete()
{
   // Just cleanup
   cleanup();
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
   global $us3_db;

   // Double check that the gfacID exists
   $query  = "SELECT count(*) FROM analysis WHERE gfacID='$gfacID'";
   $result = mysql_query( $query, $gLink );
  
   if ( ! $result )
   {
      write_log( "$self: Query failed $query - " .  mysql_error( $gLink ) );
      mail_to_admin( "fail", "Query failed $query\n" .  mysql_error( $gLink ) );
      return;
   }

   list( $count ) = mysql_fetch_array( $result );

   if ( $count == 0 ) return;

   // Now check the us3 instance
   $requestID = get_us3_data();
   if ( $requestID == 0 ) return;

   gfac_cleanup( $us3_db, $requestID, $gLink );
}

// Function to update status of job
function update_job_status( $job_status, $gfacID )
{
  global $gLink;
  
  switch ( $job_status )
  {
    case 'SUBMITTED'   :
    case 'SUBMITED'    :
    case 'INITIALIZED' :
    case 'PENDING'     :
      $query   = "UPDATE analysis SET status='SUBMITTED' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is SUBMITTED";
      break;

    case 'ACTIVE'      :
      $query   = "UPDATE analysis SET status='RUNNING' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is RUNNING";
      break;

    case 'COMPLETED'   :
    case 'DONE'   :
      $query   = "UPDATE analysis SET status='COMPLETE' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is COMPLETE";
      break;

    case 'DATA'   :
      $query   = "UPDATE analysis SET status='DATA' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is COMPLETE, waiting for data";
      break;

    case 'CANCELED'    :
    case 'CANCELLED'    :
      $query   = "UPDATE analysis SET status='CANCELED' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is CANCELED";
      break;

    case 'FAILED'      :
      $query   = "UPDATE analysis SET status='FAILED' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is FAILED";
      break;

    case 'UNKNOWN'     :
      // $query   = "UPDATE analysis SET status='ERROR' WHERE gfacID='$gfacID'";
      $message = "Job status request reports job is not in the queue";
      break;

    default            :
      // We shouldn't ever get here
      $query   = "";
      $message = "Job status was not recognized - $job_status";
      write_log( "$self - update_job_status: " .
                 "Job status was not recognized - $job_status\n" .
                 "gfacID = $gfacID\n" );
      break;

  }

   $result =  mysql_query( $query, $gLink );
   if ( ! $result )
      write_log( "$self: Query failed $query - " .  mysql_error( $gLink ) );

   update_queue_messages( $message );
   update_db( $message );
}

function get_us3_data()
{
   global $self;
   global $gfacID;
   global $dbhost;
   global $user;
   global $passwd;
   global $us3_db;
   global $updateTime;

   $us3_link = mysql_connect( $dbhost, $user, $passwd );

   if ( ! $us3_link )
   {
      write_log( "$self: could not connect: $dbhost, $user, $passwd" );
      mail_to_admin( "fail", "Could not connect to $dbhost" );
      return 0;
   }


   $result = mysql_select_db( $us3_db, $us3_link );

   if ( ! $result )
   {
      write_log( "$self: could not select DB $us3_db" );
      mail_to_admin( "fail", "Could not select DB $us3_db, $dbhost, $user, $passwd" );
      return 0;
   }

   $query = "SELECT HPCAnalysisRequestID, UNIX_TIMESTAMP(updateTime) " .
            "FROM HPCAnalysisResult WHERE gfacID='$gfacID'";
   $result = mysql_query( $query, $us3_link );

   if ( ! $result )
   {
      write_log( "$self: Query failed $query - " .  mysql_error( $us3_link ) );
      mail_to_admin( "fail", "Query failed $query\n" .  mysql_error( $us3_link ) );
      return 0;
   }

   list( $requestID, $updateTime ) = mysql_fetch_array( $result );
   mysql_close( $us3_link );

   return $requestID;
}

// Function to determine if this is a gfac job or a local job
function is_gfac_job( $gfacID )
{
  $hex = "[0-9a-fA-F]";
  if ( ! preg_match( "/^US3-Experiment/i", $gfacID ) &&
       ! preg_match( "/^US3-$hex{8}-$hex{4}-$hex{4}-$hex{4}-$hex{12}$/", $gfacID ) )
   {
      // Then it's not a GFAC job
      return false;
   }

   return true;
}

// Function to get the current job status from GFAC
function get_gfac_status( $gfacID )
{
   global $serviceURL;

   if ( ! is_gfac_job( $gfacID ) )
      return false;

   $url = "$serviceURL/jobstatus/$gfacID";
   try
   {
      $post = new HttpRequest( $url, HttpRequest::METH_GET );
      $http = $post->send();
      $xml  = $post->getResponseBody();      
   }
   catch ( HttpException $e )
   {
      write_log( "$self: Status not available - marking failed -  $gfacID" );
      return 'GFAC_STATUS_UNAVAILABLE';
   }

   // Parse the result
   $gfac_status = parse_response( $xml );

   // This may not seem like the best place to do this, but here we have
   // the xml straight from GFAC
   $status_types = array('SUBMITTED',
                         'SUBMITED',
                         'INITIALIZED',
                         'PENDING',
                         'ACTIVE',
                         'COMPLETED',
                         'DONE',
                         'DATA',
                         'CANCELED',
                         'CANCELLED',
                         'FAILED',
                         'UNKNOWN');
   if ( ! in_array( $gfac_status, $status_types ) )
      mail_to_admin( 'debug', "gfacID: /$gfacID/\n" .
                              "XML:    /$xml/\n"    . 
                              "Status: /$gfac_status/\n" );

   return $gfac_status;
}

// Function to request data outputs from GFAC
function get_gfac_outputs( $gfacID )
{
   global $serviceURL;

   // Make sure it's a GFAC job and status is appropriate for this call
   if ( ( $job_status = get_gfac_status( $gfacID ) ) === false )
   {
      // Then it's not a GFAC job
      return false;
   }

   if ( ! in_array( $job_status, array( 'DONE', 'FAILED', 'COMPLETE' ) ) )
   {
      // Then it's not appropriate to request data
      return false;
   }

   $url = "$serviceURL/registeroutput/$gfacID";
   try
   {
      $post = new HttpRequest( $url, HttpRequest::METH_GET );
      $http = $post->send();
      $xml  = $post->getResponseBody();      
   }
   catch ( HttpException $e )
   {
      write_log( "$self: Data not available - request failed -  $gfacID" );
      return false;
   }

   mail_to_admin( "debug", "get_gfac_outputs/\n$xml/" );    // Temporary, to see what the xml looks like,
                                                            //  if we ever get one

   // Parse the result
   $gfac_status = parse_response( $xml );

   return $gfac_status;
}

function parse_response( $xml )
{
   global $gfac_message;

   $status       = "";
   $gfac_message = "";

   $parser = new XMLReader();
   $parser->xml( $xml );

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
   return $status;
}

// Function to get status from local cluster
function get_local_status( $gfacID )
{
   global $cluster;

   $system = "$cluster.uthscsa.edu";
   $system = preg_replace( "/\-local/", "", $system );
   $cmd    = "/usr/bin/ssh -x us3@$system qstat -a $gfacID 2>&1";

   $result = exec( $cmd );

   if ( $result == ""  ||  preg_match( "/^qstat: Unknown/", $result ) )
   {
      write_log( "$self get_local_status: Local job $gfacID unknown" );
      return 'UNKNOWN';
   }

   $values = preg_split( "/\s+/", $result );
//   write_log( "$self: get_local_status: job status = /{$values[9]}/");
   switch ( $values[ 9 ] )
   {
      case "W" :                      // Waiting for execution time to be reached
      case "E" :                      // Job is exiting after having run
      case "R" :                      // Still running
        $status = 'ACTIVE';
        break;

      case "C" :                      // Job has completed
        $status = 'COMPLETED';
        break;

      case "T" :                      // Job is being moved
      case "H" :                      // Held
      case "Q" :                      // Queued
        $status = 'SUBMITTED';
        break;

      default :
        $status = 'UNKNOWN';          // This should not occur
        break;
   }
  
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
   $result = mysql_query( $query, $gLink );
   if ( ! $result )
   {
      write_log( "$self: Query failed $query - " .  mysql_error( $gLink ) );
      return;
   }
   list( $analysisID ) = mysql_fetch_array( $result );

   // Insert message into queue_message table
   $query  = "INSERT INTO queue_messages SET " .
             "message = '" . mysql_real_escape_string( $message, $gLink ) . "'," .
             "analysisID = $analysisID ";
   $result = mysql_query( $query, $gLink );
   if ( ! $result )
   {
      write_log( "$self: Query failed $query - " .  mysql_error( $gLink ) );
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

   $us3_link = mysql_connect( $dbhost, $user, $passwd );

   if ( ! $us3_link )
   {
      write_log( "$self: could not connect: $dbhost, $user, $passwd" );
      mail_to_admin( "fail", "Could not connect to $dbhost" );
      return 0;
   }


   $result = mysql_select_db( $us3_db, $us3_link );

   if ( ! $result )
   {
      write_log( "$self: could not select DB $us3_db" );
      mail_to_admin( "fail", "Could not select DB $us3_db, $dbhost, $user, $passwd" );
      return 0;
   }

   $query = "UPDATE HPCAnalysisResult SET " .
            "lastMessage='" . mysql_real_escape_string( $message, $us3_link ) . "'" .
            "WHERE gfacID = '$gfacID' ";

   mysql_query( $query, $us3_link );
   mysql_close( $us3_link );
}

function mail_to_admin( $type, $msg )
{
   global $updateTime;
   global $status;
   global $cluster;
   global $org_name;
   global $admin_email;
   global $dbhost;
   global $requestID;

   $headers  = "From: $org_name Admin<$admin_email>"     . "\n";
   $headers .= "Cc: $org_name Admin<$admin_email>"       . "\n";
   $headers .= "Bcc: Dan Zollars<dzollars@gmail.com>"    . "\n";     // make sure

   // Set the reply address
   $headers .= "Reply-To: $org_name<$admin_email>"      . "\n";
   $headers .= "Return-Path: $org_name<$admin_email>"   . "\n";

   // Try to avoid spam filters
   $now = time();
   $headers .= "Message-ID: <" . $now . "gridctl@$dbhost>$requestID\n";
   $headers .= "X-Mailer: PHP v" . phpversion()         . "\n";
   $headers .= "MIME-Version: 1.0"                      . "\n";
   $headers .= "Content-Transfer-Encoding: 8bit"        . "\n";

   $subject       = "US3 Error Notification";
   $message       = "
   UltraScan job error notification from gridctl.php:

   Update Time    :  $updateTime
   GFAC Status    :  $status
   Cluster        :  $cluster
   ";

   $message .= "Error Message  :  $msg\n";

   mail( $admin_email, $subject, $message, $headers );
}
?>
