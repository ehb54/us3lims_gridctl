<?php

# functions for jobmonitor.php

require_once __DIR__ . '/../job_state_machine.php';

## returns true when job processing is done (regardless error or success)

## Pure: decides whether the stored status ($status_gw) must survive a fresh
## live probe answer ($status), or whether the probe's answer should be used.
## get_local_status() only ever returns a plain cluster state (SUBMITTED/
## ACTIVE/COMPLETED/...), never one of LIMS's own escalation markers
## (SUBMIT_TIMEOUT/RUN_TIMEOUT/FAILED-via-fire_stall). While squeue still
## lists the job as queued/running, that plain answer used to overwrite the
## marker, routing check_job()'s switch back into submitted()/running() --
## the *first* stall window -- instead of re-entering submit_timeout()/
## run_timeout(), the second window that actually decides (via reconcile())
## whether to fail the job. The marker has to survive a "still alive" answer
## the same way COMPLETE/UNKNOWN/UNREACHABLE already did, or it never reaches
## FAILED and refires every window instead.
##
## FAILED needs the same protection, but not unconditionally: fire_stall()
## writes FAILED directly (bypassing this function on the way in), and the
## very next poll's "still alive" probe answer then overwrote it right back
## to RUN_TIMEOUT/SUBMIT_TIMEOUT here, alternating forever with a scancel
## every window and never finalizing while squeue still lists the job.
## Keep FAILED against the still-alive answers; let a
## COMPLETED or CANCELED answer through, since those are the cluster
## reporting a different, equally genuine terminal outcome.
function reconcile_probed_status( $status_gw, $status ) {
    if ( $status_gw === 'FAILED' ) {
        return in_array( $status, [ 'COMPLETED', 'CANCELED' ], true ) ? $status : 'FAILED';
    }
    if ( $status_gw === 'COMPLETE'  ||  $status_gw === 'SUBMIT_TIMEOUT'  ||  $status_gw === 'RUN_TIMEOUT'
         ||  $status === 'UNKNOWN'  ||  $status === GRIDCTL_UNREACHABLE ) {
        return $status_gw;
    }
    return $status;
}

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
    $status     = reconcile_probed_status( $status_gw, get_local_status( $gfacID ) );
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

        ## Keep monitoring: submit_timeout() is the second window. It has to be
        ## re-entered until it either reconciles the job or escalates to FAILED,
        ## which is what collects the results and tells the user. Returning true
        ## here ended the monitor after one call and left the row stranded, which
        ## only the gridctl.php cron sweep used to pick up.
        case "SUBMIT_TIMEOUT":
            submit_timeout( $update_epoch );
            break;

        case "RUNNING":
        case "STARTED":
        case "STAGING":
        case "ACTIVE":
            write_logld( "  RUNNING gfacID=$gfacID" );
            running( $update_epoch, $queue_msg );
            break;

        ## Same as SUBMIT_TIMEOUT: the second window needs re-entering.
        case "RUN_TIMEOUT":
            run_timeout( $update_epoch );
            break;

        ## A job that finished and is waiting for its output to be collected.
        case "DATA":
            ## 0: not finalized yet (e.g. cluster unreachable); keep monitoring.
            return complete( $gfacID ) !== 0;

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
            ## 0: the output could not be fetched yet (cluster unreachable); keep monitoring.
            return failed( $status ) !== 0;
            break;

        case "FINISHED":
            case "DONE":
            return complete( $gfacID ) !== 0;
        
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

/** One "job is hung" and one "scancel never landed" mail per job, not one per
 *  poll. 'fail' is deduped per distinct message text instead: a blip
 *  followed by a different, permanent failure still gets two mails, but the
 *  same 'fail' text is sent only once. 'fail' used to go out unconditionally
 *  every time (e.g. a query failure during an outage), which meant the same
 *  text repeating ~2,880 times a day until someone killed the monitor by
 *  hand. */
function mail_to_admin_once( $type, $msg )
{
   global $timeout_email_sent;
   global $scancel_fail_email_sent;
   global $fail_email_sent_messages;

   if ( $type === 'hang' )
   {
      if ( isset( $timeout_email_sent ) )
         return;

      $timeout_email_sent = true;
   }
   elseif ( $type === 'scancel_fail' )
   {
      if ( isset( $scancel_fail_email_sent ) )
         return;

      $scancel_fail_email_sent = true;
   }
   elseif ( $type === 'fail' )
   {
      ## Keyed on the message, not a single flag: a retryable
      ## connection blip and a later, genuinely different permanent failure
      ## both come through as type 'fail' (job_state_machine.php's one call
      ## site), and a single boolean let the first one's mail silently
      ## swallow the second's -- the mail that actually mattered, since a
      ## permanent failure is the one that ends the monitor. The *same*
      ## message repeating (the real spam case this dedup exists for) is
      ## still deduped.
      if ( ! is_array( $fail_email_sent_messages ) )
      {
         $fail_email_sent_messages = array();
      }
      if ( in_array( $msg, $fail_email_sent_messages, true ) )
         return;

      $fail_email_sent_messages[] = $msg;
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

    ## -2 (CLEANUP_FINALIZING_INTERRUPTED) the worker died mid-span and the
    ## marker survives for --restart, -1 terminal, 0 retry (not yet
    ## finalizable), 1 finalized.
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
