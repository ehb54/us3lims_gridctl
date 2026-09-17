<?php

# functions for jobmonitor.php

## Self-sufficient rather than relying on the includer: jobmonitor.php loads
## these, but joblinkjson.php includes this file without them.
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
    ## UNREACHABLE means we could not ask the cluster, so the only defensible
    ## state is the one already recorded. Falling through to the switch on a
    ## fabricated state is what turned a site outage into a wave of ERRORs.
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
            ## complete() returns 0 when cleanup could not yet finalize the job
            ## (e.g. the cluster's UDP 'Finished' message has not arrived and the
            ## grace period has not elapsed).  Keep monitoring and retry on the
            ## next poll rather than exiting and orphaning the job.
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
            ## Pass the observed status through: a cancelled job is 'aborted'
            ## to the user and a failed one is 'failed', and collapsing them
            ## here would lose that distinction.
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
## Everything below is a shim onto job_state_machine, which holds the policy
## this daemon and the gridctl.php cron sweep must apply identically.
##
## The function names are kept because cleanup.php and cleanup_job.php call
## update_autoflow_status(), get_us3_data() and mail_to_admin() from code
## shared with the sweep, which has no idea which entry point included it.
## ---------------------------------------------------------------------- ##

/**
 * The state machine for the job this daemon is watching.
 *
 * Rebuilt on every call rather than cached, because jobmonitor.php closes and
 * reopens $db_handle whenever the connection drops, and a cached machine would
 * go on writing to the dead handle.
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

/**
 * One "job is hung" mail per job, not one per poll.
 *
 * This daemon lives as long as the job does and revisits the same timeout
 * every 30 seconds, so without this an unreachable cluster produces a mail a
 * minute for as long as the outage lasts. Only 'hang' is deduplicated: a
 * 'fail' is a distinct internal error each time and the admin needs all of
 * them. The cron sweep needs no equivalent, since it exits after one pass.
 */
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
function update_queue_messages( $message )     { return job_machine()->update_queue_messages( $message ); }
function update_db( $message )                 { return job_machine()->update_db( $message ); }
function get_us3_data()                        { return job_machine()->get_us3_data(); }
function get_local_status( $gfacID )           { return job_machine()->get_local_status(); }
function cancel_local_job( $gfacID )           { return job_machine()->cancel_local_job(); }
function update_autoflow_status( $status, $message ) { return job_machine()->update_autoflow_status( $status, $message ); }
function update_hpc_analysis_result_status( $status ) { return job_machine()->update_hpc_analysis_result_status( $status ); }

function complete( $gfacID ) {
    ## Record the completion in gfac.analysis BEFORE cleaning up.
    ##
    ## cleanup_job.php reads gfac.analysis.status and feeds it to
    ## update_autoflow_status(), and submitctl.php only advances a stage whose
    ## status is in $completed_status ("complete"/"done") or $failed_status.
    ## Without this write the row is still 'SUBMITTED' when cleanup reads it,
    ## so the autoflow request is stamped 'submitted', which matches neither
    ## list, and a multi-stage pipeline stalls there permanently.
    update_job_status( "COMPLETE", $gfacID );
    return cleanup();
}

function failed( $job_status = 'FAILED' ) {
    ## Record the terminal status BEFORE cleaning up, for the same reasons
    ## complete() does. Without this write, update_job_status() ->
    ## update_autoflow_status() -> update_hpc_analysis_result_status() never
    ## runs, HPCAnalysisResult.queueStatus keeps whatever it had, and a job
    ## that failed while still 'queued' stays 'queued' forever.
    ##
    ## queue_status_from_job() has always had the FAILED and CANCELED entries;
    ## nothing on this path ever reached them.
    ##
    ## The status is passed in rather than hardcoded because check_job() routes
    ## CANCELLED, CANCELED and FAILED here alike, and they are not the same
    ## outcome: CANCELED maps to 'aborted' for the user, FAILED to 'failed'.
    ## job_status_normalise() inside update_job_status() folds the spellings.
    global $gfacID;

    update_job_status( $job_status, $gfacID );
    return cleanup();
}

function cleanup() {
    write_logld( "cleanup called" );
    global $db_handle;
    global $gfacID;
    global $us3_db;

    ## Propagate resolve_and_cleanup_job()'s result to complete()/check_job():
    ## -1 terminal, 0 retry (not yet finalizable), 1 finalized. Without this,
    ## complete() always returns null and the COMPLETE-retry check in
    ## check_job() never fires.
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
