<?php

# functions for jobmonitor.php

## joblinkjson.php includes this file without jobmonitor.php's includes.
require_once __DIR__ . '/../job_state_machine.php';

## returns true when job processing is done (regardless error or success)

function check_job() {
    write_logld( "check_job()" );
    global $gfacID;
    global $us3_db;
    global $cluster;
    global $status;
    global $queue_msg;
    global $update_epoch;
    global $updateTime;
    global $autoflowAnalysisID;
    global $db_handle;

    $gfacLabl  = $gfacID;
    $status_ex = $status;

    // Get local job status
    $status_gw  = $status;
    $status     = get_local_status( $gfacID );
    ## UNREACHABLE: we could not ask, so keep the recorded status.
    if ( $status_gw == 'COMPLETE'  ||  $status == 'UNKNOWN'  ||  $status == GRIDCTL_UNREACHABLE ) {
        $status     = $status_gw;
    }
    write_logld( "Local status=$status status_gw=$status_gw" );
    
    # Sometimes during testing, the us3_db entry is not set
    # If $status == 'ERROR' then the condition has been processed before
    if ( strlen( $us3_db ) == 0 && $status != 'ERROR' )  {

        write_logld( "GFAC DB is NULL - $gfacID" );
        mail_to_admin( "fail", "GFAC DB is NULL\n$gfacID" );

        $query2  = "UPDATE gfac.analysis SET status='ERROR' WHERE gfacID='$gfacID'";
        $result2 = mysqli_query( $db_handle, $query2 );
        $status  = 'ERROR';

        if ( ! $result2 ) {
            write_logld( "Query failed $query2 - " .  mysqli_error( $db_handle ) );
        }

        update_autoflow_status( 'ERROR', 'GFAC DB is NULL' );
        return true;
    }

    ##echo "  st=$status\n";
    write_logld( "switch status=$status" );
    switch ( $status ) {
        ## Already been handled
        ## Later update this condition to search for gfacID?
        case "ERROR":
            cleanup();
            return true;
            break;

        case "SUBMITTED": 
            submitted( $update_epoch );
            break;  

        case "SUBMIT_TIMEOUT": 
            submit_timeout( $update_epoch );
            return true;
            break;  

        case "RUNNING":
        case "STARTED":
        case "STAGING":
        case "ACTIVE":
            write_logld( "  RUNNING gfacID=$gfacID" );
            running( $update_epoch, $queue_msg );
            break;

        case "RUN_TIMEOUT":
            run_timeout( $update_epoch );
            return true;
            break;

        ## A job that finished and is waiting for its output to be collected.
        case "DATA":
            complete( $gfacID );
            return true;

        case "COMPLETED":
            case "COMPLETE":
            write_logld( "  COMPLETE gfacID=$gfacID" );
            ## 0: cleanup cannot finalize yet (e.g. still waiting for
            ## 'Finished'). Keep monitoring.
            if ( complete( $gfacID ) === 0 ) {
                write_logld( "  COMPLETE not yet finalized gfacID=$gfacID - will retry" );
                return false;
            }
            return true;
            break;

        case "CANCELLED":
            case "CANCELED":
            case "FAILED":
            write_logld( "  $status gfacID=$gfacID" );
            ## Passed through: cancelled is 'aborted' to the user, failed 'failed'.
            failed( $status );
            return true;
            break;

        case "FINISHED":
            case "DONE":
            complete( $gfacID );
            return true;
        
        case "PROCESSING":
        default:
            break;
    }
    return false;
}


## ---------------------------------------------------------------------- ##
## Shims onto job_state_machine. The shared cleanup code calls these names.
## ---------------------------------------------------------------------- ##

/**
 * The state machine for the job this daemon is watching. Not cached, because
 * jobmonitor.php reopens $db_handle when the connection drops.
 */
function job_machine()
{
   global $db_handle;
   global $gfacID;
   global $cluster;
   global $us3_db;
   global $autoflowAnalysisID;

   $machine = new job_state_machine(
      $db_handle,          ## reaches both gfac.* and {$us3_db}.*
      $db_handle,
      'gfac.',
      'write_logld',
      'mail_to_admin_once'
   );

   return $machine->for_job( $gfacID, $cluster, $us3_db, $autoflowAnalysisID );
}

/** One "job is hung" mail per job, not one per poll. 'fail' mails all go out. */
function mail_to_admin_once( $type, $msg )
{
   global $timeout_email_sent;

   if ( $type === 'hang' )
   {
      if ( isset( $timeout_email_sent ) )
         return;

      $timeout_email_sent = true;
   }

   mail_to_admin( $type, $msg );
}

function submitted( $updatetime )              { return job_machine()->submitted( $updatetime ); }
function submit_timeout( $updatetime )         { return job_machine()->submit_timeout( $updatetime ); }
function running( $updatetime, $queue_msg )    { return job_machine()->running( $updatetime, $queue_msg ); }
function run_timeout( $updatetime )            { return job_machine()->run_timeout( $updatetime ); }
function update_job_status( $job_status, $gfacID ) { return job_machine()->update_job_status( $job_status ); }
function record_job_status( $job_status, $gfacID ) { return job_machine()->record_job_status( $job_status ); }
function update_queue_messages( $message )     { return job_machine()->update_queue_messages( $message ); }
function update_db( $message )                 { return job_machine()->update_db( $message ); }
function get_us3_data()                        { return job_machine()->get_us3_data(); }
function get_local_status( $gfacID )           { return job_machine()->get_local_status(); }
function cancel_local_job( $gfacID )           { return job_machine()->cancel_local_job(); }
function update_autoflow_status( $status, $message ) { return job_machine()->update_autoflow_status( $status, $message ); }
function update_hpc_analysis_result_status( $status ) { return job_machine()->update_hpc_analysis_result_status( $status ); }

function complete( $gfacID ) {
    ## cleanup_job.php reads COMPLETE back from gfac.analysis and sets the
    ## stage status once the results are imported. See record_job_status().
    record_job_status( "COMPLETE", $gfacID );
    return cleanup();
}

function failed( $job_status = 'FAILED' ) {
    ## Record the terminal status everywhere before cleaning up. The caller
    ## passes it in: CANCELED is 'aborted' to the user, FAILED is 'failed'.
    global $gfacID;

    update_job_status( $job_status, $gfacID );
    return cleanup();
}

function cleanup() {
    write_logld( "cleanup called" );
    global $db_handle;
    global $gfacID;
    global $us3_db;

    ## -1 terminal, 0 retry (not yet finalizable), 1 finalized.
    return resolve_and_cleanup_job( $db_handle, $gfacID, $us3_db, 'gfac.analysis', 'write_logld' );
}

function mail_to_admin( $type, $msg ) {
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

   ## Set the reply address
   $headers .= "Reply-To: $org_name<$admin_email>"      . "\n";
   $headers .= "Return-Path: $org_name<$admin_email>"   . "\n";

   ## Try to avoid spam filters
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
