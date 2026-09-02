<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
include_once "$us3bin/listen-config.php";
include_once $class_dir . "../global_config.php";   ## $cluster_details, used by get_local_status()
include_once "$us3bin/gridctl/cluster_probe.php";  ## ask a cluster about a job
include_once "$us3bin/gridctl/job_state_machine.php";  ## the one implementation of "what happens to this job"
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

// Connect to the central job-tracking database.
$gLink    = mysqli_connect( $dbhost, $guser, $gpasswd, $gDB );

if ( ! $gLink )
{
   write_log( "$self: Could not select DB $gDB - " . mysqli_error() );
   mail_to_admin( "fail",
      "Internal Error: Could not select DB $gDB $dbhost $guser  Server: $servhost" );
   sleep(3);
   exit();
}
   
## Both forms of the same column, deliberately. The stall clocks do integer
## arithmetic on the epoch; the admin mail prints the human-readable form.
$query = "SELECT gfacID, us3_db, cluster, status, queue_msg, " .
                "UNIX_TIMESTAMP(time) AS update_epoch, time AS update_text, " .
                "autoflowAnalysisID from analysis";
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

## Read by column name, not by position.
##
## This was a list() over mysqli_fetch_array(), which destructures positionally,
## and the two time columns were bound to variables whose names said the
## opposite of what they held: $time got UNIX_TIMESTAMP(time) and $updateTime
## got the datetime string. The arithmetic below was correct only because the
## right one happened to be passed. Renaming either variable without reading
## the SELECT, or inserting a column anywhere in it, would have fed a datetime
## string into timestamp arithmetic and timed out every job on the first sweep.
##
## Naming the variables honestly does not remove that hazard, it only relabels
## it: the coupling is positional either way. Fetching associatively is what
## removes it, and it is what makes the SELECT aliases above mean something.
while ( $row = mysqli_fetch_assoc( $result ) )
{
   $gfacID       = $row[ 'gfacID' ];
   $us3_db       = $row[ 'us3_db' ];
   $cluster      = $row[ 'cluster' ];
   $status       = $row[ 'status' ];
   $queue_msg    = $row[ 'queue_msg' ];
   $update_epoch = $row[ 'update_epoch' ];        ## integer, for the stall clocks
   $updateTime   = $row[ 'update_text' ];         ## datetime string, for the admin mail
   $autoflowID   = $row[ 'autoflowAnalysisID' ];

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

   // UNREACHABLE means we could not ask the cluster, so the only defensible
   // state is the one already recorded. Falling through to the switch on a
   // fabricated state is what turned a site outage into a wave of ERRORs.
   if ( $status_gw == 'COMPLETE'  ||  $status == 'UNKNOWN'  ||  $status == GRIDCTL_UNREACHABLE )
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
         submitted( $update_epoch );
         break;  

      case "SUBMIT_TIMEOUT": 
         submit_timeout( $update_epoch );
         break;  

      case "RUNNING":
      case "STARTED":
      case "STAGING":
      case "ACTIVE":
write_log( "$loghdr   RUNNING gfacID=$gfacID" );
         running( $update_epoch, $queue_msg );
         break;

      case "RUN_TIMEOUT":
         run_timeout( $update_epoch );
         break;

      // A job that finished and is waiting for its output to be collected.
      case "DATA":
         complete();
         break;

      case "COMPLETED":
      case "COMPLETE":
write_log( "$loghdr   COMPLETE gfacID=$gfacID" );
         complete();
         break;

      case "CANCELLED":
      case "CANCELED":
      case "FAILED":
write_log( "$loghdr   $status gfacID=$gfacID" );
         // Pass the observed status through: a cancelled job is 'aborted' to
         // the user and a failed one is 'failed'.
         failed( $status );
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

## ---------------------------------------------------------------------- ##
## Everything below is a shim onto job_state_machine, which holds the policy
## this sweep and the jobmonitor daemon must apply identically.
##
## The function names are kept because cleanup.php and cleanup_job.php call
## update_autoflow_status(), get_us3_data() and mail_to_admin() from code
## shared with the daemon, which has no idea which entry point included it.
## ---------------------------------------------------------------------- ##

/**
 * The state machine for the row currently being swept.
 *
 * Rebuilt per call: the sweep walks every job in one pass, so the per-job
 * context has to be refreshed anyway, and there is no connection state worth
 * caching.
 *
 * The us3 tables are reached over their own connection. $gLink authenticates
 * as the gfac user, which is granted the gfac schema; the per-experiment us3
 * schemas belong to $user, so everything us3 goes over the us3 connection
 * rather than relying on the gfac account holding rights it should not need.
 */
function job_machine()
{
   global $gLink;
   global $gfacID;
   global $cluster;
   global $us3_db;
   global $autoflowID;
   global $dbhost;
   global $user;
   global $passwd;

   $machine = new job_state_machine(
      $gLink,
      function () use ( $dbhost, $user, $passwd, $us3_db ) {
         $link = mysqli_connect( $dbhost, $user, $passwd, $us3_db );

         if ( ! $link )
         {
            write_log( "gridctl.php: could not connect to $dbhost : $us3_db" );
            mail_to_admin( "fail", "Could not connect to $dbhost : $us3_db" );

            return null;
         }

         return $link;
      },
      '',            ## $gLink is already connected to the job-tracking database
      function ( $m ) { global $self; write_log( "$self: $m" ); },
      'mail_to_admin'
   );

   return $machine->for_job( $gfacID, $cluster, $us3_db, $autoflowID );
}

function submitted( $updatetime )              { return job_machine()->submitted( $updatetime ); }
function submit_timeout( $updatetime )         { return job_machine()->submit_timeout( $updatetime ); }
function running( $updatetime, $queue_msg )    { return job_machine()->running( $updatetime, $queue_msg ); }
function run_timeout( $updatetime )            { return job_machine()->run_timeout( $updatetime ); }
function update_job_status( $job_status, $gfacID ) { return job_machine()->update_job_status( $job_status ); }
function update_queue_messages( $message )     { return job_machine()->update_queue_messages( $message ); }
function update_db( $message )                 { return job_machine()->update_db( $message ); }
function get_us3_data()                        { return job_machine()->get_us3_data(); }
function get_local_status( $gfacID )           { return job_machine()->get_local_status(); }
function cancel_local_job( $gfacID )           { return job_machine()->cancel_local_job(); }
function update_autoflow_status( $status, $message ) { return job_machine()->update_autoflow_status( $status, $message ); }
function update_hpc_analysis_result_status( $status ) { return job_machine()->update_hpc_analysis_result_status( $status ); }

function complete()
{
   global $gfacID;

   // Record the completion in gfac.analysis BEFORE cleaning up.
   //
   // cleanup_job.php reads gfac.analysis.status and feeds it to
   // update_autoflow_status(), and submitctl.php only advances a stage whose
   // status is in $completed_status ("complete"/"done") or $failed_status.
   // Without this write the row is still 'SUBMITTED' when cleanup reads it,
   // so the autoflow request is stamped 'submitted', which matches neither
   // list, and the whole multi-stage pipeline stalls there permanently.
   update_job_status( "COMPLETE", $gfacID );

   return cleanup();
}

function failed( $job_status = 'FAILED' )
{
   global $gfacID;

   // Record the terminal status BEFORE cleaning up, for the same reasons
   // complete() does. Without this write, update_job_status() ->
   // update_autoflow_status() -> update_hpc_analysis_result_status() never
   // runs, HPCAnalysisResult.queueStatus keeps whatever it had, and a job that
   // failed while still 'queued' stays 'queued' forever.
   //
   // The status is passed in because the caller routes CANCELLED, CANCELED and
   // FAILED here alike: CANCELED is 'aborted' to the user, FAILED is 'failed'.
   update_job_status( $job_status, $gfacID );

   return cleanup();
}

function cleanup()
{
   global $gLink;
   global $gfacID;
   global $us3_db;

   return resolve_and_cleanup_job( $gLink, $gfacID, $us3_db, 'analysis', 'write_log' );
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
   $headers .= "Bcc: $org_name Admin<$admin_email>"      . "\n";

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
