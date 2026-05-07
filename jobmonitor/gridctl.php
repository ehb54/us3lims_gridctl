<?php

# functions for jobmonitor.php

## returns true when job processing is done (regardless error or success)

function check_job() {
    write_logld( "check_job()" );
    global $gfacID;
    global $us3_db;
    global $cluster;
    global $status;
    global $queue_msg;
    global $time;
    global $updateTime;
    global $autoflowAnalysisID;
    global $db_handle;

    $gfacLabl  = $gfacID;
    $status_ex = $status;

    // Get local job status
    $status_gw  = $status;
    $status     = get_local_status( $gfacID );
    if ( $status_gw == 'COMPLETE'  ||  $status == 'UNKNOWN' ) {
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
            submitted( $time );
            break;  

        case "SUBMIT_TIMEOUT": 
            submit_timeout( $time );
            return true;
            break;  

        case "RUNNING":
        case "STARTED":
        case "STAGING":
        case "ACTIVE":
            write_logld( "  RUNNING gfacID=$gfacID" );
            running( $time, $queue_msg );
            break;

        case "RUN_TIMEOUT":
            run_timeout($time );
            return true;
            break;

        case "COMPLETED":
            case "COMPLETE":
            write_logld( "  COMPLETE gfacID=$gfacID" );
            complete( $gfacID );
            return true;
            break;

        case "CANCELLED":
            case "CANCELED":
            case "FAILED":
            failed();
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

function submitted( $updatetime ) {
    global $self;
    global $db_handle;
    global $gfacID;
    global $autoflowAnalysisID;
    global $global_max_queue_time_hours;
    global $timeout_email_sent;

    ## set default if not defined in global_config.php
    if ( !isset( $global_max_queue_time_hours ) ) {
        $global_max_queue_time_hours = 24;
    }

    $now = time();

    if ( $updatetime + 600 > $now ) { ## < 10 minutes ago
        return;
    }

    if ( $global_max_queue_time_hours <= 0 ) {
        return;
    }

    if ( $updatetime + ( $global_max_queue_time_hours * 60 * 60 ) > $now ) {
        ## Within the first $global_max_queue_time_hours hours
        $job_status = get_local_status( $gfacID );

        if ( ! in_array( $job_status, array( 'SUBMITTED', 'INITIALIZED', 'PENDING', 'UNKNOWN' ) ) ) {
            write_logld( "submitted:job_status=$job_status" );
            update_job_status( $job_status, $gfacID );
        }

        return;
    }

    $message = "Job listed submitted longer than $global_max_queue_time_hours hours";
    write_logld( "$message - id: $gfacID" );
    if ( !isset( $timeout_email_sent ) ) {
        mail_to_admin( "hang", "$message - id: $gfacID" );
        $timeout_email_sent = true;
    }
    $query = "UPDATE gfac.analysis SET status='SUBMIT_TIMEOUT' WHERE gfacID='$gfacID'";
    $result = mysqli_query( $db_handle, $query );
    
    if ( ! $result ) {
        write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
    }
    
    update_queue_messages( $message );
    update_db( $message );
    update_autoflow_status( 'SUBMIT_TIMEOUT', $message );

    cancel_local_job( $gfacID );
}

function submit_timeout( $updatetime ) {

    global $self;
    global $db_handle;
    global $gfacID;
    global $autoflowAnalysisID;
    global $global_max_queue_time_hours;
    global $timeout_email_sent;

    ## set default if not defined in global_config.php
    if ( !isset( $global_max_queue_time_hours ) ) {
        $global_max_queue_time_hours = 24;
    }

    $job_status = get_local_status( $gfacID );

    if ( ! in_array( $job_status, array( 'SUBMITTED', 'INITIALIZED', 'PENDING', 'UNKNOWN' ) ) ) {
        update_job_status( $job_status, $gfacID );
        return;
    }

    $now = time();

    if ( $global_max_queue_time_hours <= 0 ) {
        return;
    }

    if ( $updatetime + ( $global_max_queue_time_hours * 60 * 60 ) > $now ) {
        return; ## < $global_max_queue_time_hours hours since last status update
    }

    $message = "Job listed submitted longer than $global_max_queue_time_hours hours";
    write_logld( "$message - id: $gfacID" );

    if ( !isset( $timeout_email_sent ) ) {
        mail_to_admin( "hang", "$message - id: $gfacID" );
        $timeout_email_sent = true;
    }
    $query = "UPDATE gfac.analysis SET status='FAILED' WHERE gfacID='$gfacID'";
    $result = mysqli_query( $db_handle, $query );

    if ( ! $result ) {
        write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
    }

    update_queue_messages( $message );
    update_db( $message );
    update_autoflow_status( 'FAILED', $message );

    cancel_local_job( $gfacID );
}

function running( $updatetime, $queue_msg ) {
    global $self;
    global $db_handle;
    global $gfacID;
    global $autoflowAnalysisID;
    global $global_max_run_time_hours;
    global $timeout_email_sent;

    $now = time();

    ## set default if not defined in global_config.php
    if ( !isset( $global_max_run_time_hours ) ) {
        $global_max_run_time_hours = 24;
    }

    get_us3_data();

    update_autoflow_status( 'RUNNING', $queue_msg );

    if ( $updatetime + 600 > $now ) {
        return;   ## message received < 10 minutes ago
    }

    if ( $global_max_run_time_hours <= 0 ) {
        return;
    }

    if ( $updatetime + ( $global_max_run_time_hours * 60 * 60 ) > $now ) {
        ## Within the first $global_max_run_time_hours hours
        $job_status = get_local_status( $gfacID );

        if ( ! in_array( $job_status, array( 'ACTIVE', 'RUNNING', 'STARTED', 'UNKNOWN' ) ) ) {
            update_job_status( $job_status, $gfacID );
        }
        return;
    }

    $message = "Job listed running longer than $global_max_run_time_hours hours";
    write_logld( "$message - id: $gfacID" );
    if ( !isset( $timeout_email_sent ) ) {
        mail_to_admin( "hang", "$message - id: $gfacID" );
        $timeout_email_sent = true;
    }
    $query = "UPDATE gfac.analysis SET status='RUN_TIMEOUT' WHERE gfacID='$gfacID'";
    $result = mysqli_query( $db_handle, $query );

    if ( ! $result ) {
        write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
    }

    update_queue_messages( $message );
    update_db( $message );
    update_autoflow_status( 'RUN_TIMEOUT', $message );

    cancel_local_job( $gfacID );
}

function run_timeout( $updatetime ) {

    global $self;
    global $db_handle;
    global $gfacID;
    global $autoflowAnalysisID;
    global $global_max_run_time_hours;
    global $timeout_email_sent;

    ## set default if not defined in global_config.php
    if ( !isset( $global_max_run_time_hours ) ) {
        $global_max_run_time_hours = 24;
    }

    $job_status = get_local_status( $gfacID );

    if ( ! in_array( $job_status, array( 'ACTIVE', 'RUNNING', 'STARTED', 'UNKNOWN' ) ) ) {
        update_job_status( $job_status, $gfacID );
        return;
    }

    $now = time();

    get_us3_data();

    if ( $global_max_run_time_hours <= 0 ) {
        return;
    }

    if ( $updatetime + ( $global_max_run_time_hours * 60 * 60 ) > $now ) {
        return; ## < last status update < $global_max_run_time_hours
    }

    $message = "Job listed running longer than $global_max_run_time_hours hours";
    write_logld( "$message - id: $gfacID" );
    if ( !isset( $timeout_email_sent ) ) {
        mail_to_admin( "hang", "$message - id: $gfacID" );
        $timeout_email_sent = true;
    }
    $query = "UPDATE gfac.analysis SET status='FAILED' WHERE gfacID='$gfacID'";
    $result = mysqli_query( $db_handle, $query );

    if ( ! $result ) {
        write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
    }

    update_queue_messages( $message );
    update_db( $message );
    update_autoflow_status( 'FAILED', $message );

    cancel_local_job( $gfacID );
}

function complete( $gfacID ) {
    ## Just cleanup
    update_job_status( "COMPLETE", $gfacID );
    cleanup();
}

function failed() {
    ## Just cleanup
    cleanup();
}

function cleanup() {
    write_logld( "cleanup called" );
    global $self;
    global $db_handle;
    global $gfacID;
    global $autoflowAnalysisID;
    global $us3_db;

    ## Double check that the gfacID exists
    $query  = "SELECT count(*) FROM gfac.analysis WHERE gfacID='$gfacID'";
    $result = mysqli_query( $db_handle, $query );
    
    if ( ! $result ) {
        write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
        mail_to_admin( "fail", "Query failed $query\n" .  mysqli_error( $db_handle ) );
        return;
    }

    list( $count ) = mysqli_fetch_array( $result );

    ##if ($count==0)
    ##write_logld( "count = $count  gfacID = $gfacID" );
    if ( $count == 0 ) {
        return;
    }

    ## Now check the us3 instance
    $requestID = get_us3_data();
    ##write_logld( "requestID = $requestID  gfacID = $gfacID" );
    if ( $requestID == 0 ) {
        return;
    }

    write_logld( "calling job_cleanup() reqID=$requestID" );
    job_cleanup( $us3_db, $requestID, $db_handle );
}

## Function to update status of job
function update_job_status( $job_status, $gfacID ) {
    write_logld( "gridctl.php: update_job_status( '$job_status', '$gfacID' )" );

    global $db_handle;
    global $query;
    global $self;
    
    switch ( $job_status ) {
        case 'SUBMITTED'   :
        case 'SUBMITED'    :
        case 'INITIALIZED' :
        case 'UPDATING'    :
        case 'PENDING'     :
            $status  = 'SUBMITTED';
            $query   = "UPDATE gfac.analysis SET status='SUBMITTED' WHERE gfacID='$gfacID'";
            $message = "Job status request reports job is SUBMITTED";
            break;

        case 'STARTED'     :
        case 'RUNNING'     :
        case 'ACTIVE'      :
            $status  = 'RUNNING';
            $query   = "UPDATE gfac.analysis SET status='RUNNING' WHERE gfacID='$gfacID'";
            $message = "Job status request reports job is RUNNING";
            break;

        case 'EXECUTING'      :
            $message = "Job status request reports job is EXECUTING";
            break;

        case 'FINISHED'    :
            $status  = 'FINISHED';
            $query   = "UPDATE gfac.analysis SET status='FINISHED' WHERE gfacID='$gfacID'";
            $message = "NONE";
            break;

        case 'DONE'        :
            $status  = 'DONE';
            $query   = "UPDATE gfac.analysis SET status='DONE' WHERE gfacID='$gfacID'";
            $message = "NONE";
            break;

        case 'COMPLETED'   :
        case 'COMPLETE'   :
            $status  = 'COMPLETE';
            $query   = "UPDATE gfac.analysis SET status='COMPLETE' WHERE gfacID='$gfacID'";
            $message = "Job status request reports job is COMPLETED";
            break;

        case 'DATA'        :
            $status  = 'DATA';
            $query   = "UPDATE gfac.analysis SET status='DATA' WHERE gfacID='$gfacID'";
            $message = "Job status request reports job is COMPLETE, waiting for data";
            break;

        case 'CANCELED'    :
        case 'CANCELLED'   :
            $status  = 'CANCELED';
            $query   = "UPDATE gfac.analysis SET status='CANCELED' WHERE gfacID='$gfacID'";
            $message = "Job status request reports job is CANCELED";
            break;

        case 'FAILED'      :
            $status  = 'FAILED';
            $query   = "UPDATE gfac.analysis SET status='FAILED' WHERE gfacID='$gfacID'";
            $message = "Job status request reports job is FAILED";
            break;

        case 'UNKNOWN'     :
            write_logld( "job_status='UNKNOWN', reset to 'ERROR' " );
            $status  = 'ERROR';
            $query   = "UPDATE gfac.analysis SET status='ERROR' WHERE gfacID='$gfacID'";
            $message = "Job status request reports job is not in the queue";
            break;

        default            :
            ## We shouldn't ever get here
            $status  = 'ERROR';
            $query   = "UPDATE gfac.analysis SET status='ERROR' WHERE gfacID='$gfacID'";
            $message = "Job status was not recognized - $job_status";
            write_logld( "update_job_status: " .
                         "Job status was not recognized - $job_status\n" .
                         "gfacID = $gfacID\n" );
            break;
    }

    $result =  mysqli_query( $db_handle, $query );
    if ( ! $result ) {
        write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
    }

    if ( $message != 'NONE' ) {
        update_queue_messages( $message );
        update_db( $message );
        update_autoflow_status( $status, $message );
    } else {
        update_autoflow_status( $status, $status );
    }
}

function get_us3_data() {
    write_logld( "get_us3_data()" );
    global $self;
    global $gfacID;
    global $autoflowAnalysisID;
    global $dbhost;
    global $user;
    global $passwd;
    global $us3_db;
    global $updateTime;
    global $db_handle;

    $query = "SELECT HPCAnalysisRequestID, UNIX_TIMESTAMP(updateTime) " .
        "FROM {$us3_db}.HPCAnalysisResult WHERE gfacID='$gfacID'";
    $result = mysqli_query( $db_handle, $query );

    if ( ! $result )
    {
        write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
        mail_to_admin( "fail", "Query failed $query\n" .  mysqli_error( $db_handle ) );
        return 0;
    }

    $numrows =  mysqli_num_rows( $result );
    if ( $numrows > 1 )
    {  ## Duplicate gfacIDs:  get last
        $query = "SELECT HPCAnalysisRequestID, UNIX_TIMESTAMP(updateTime) " .
            "FROM {$us3_db}.HPCAnalysisResult WHERE gfacID='$gfacID' " .
            " ORDER BY HPCAnalysisResultID DESC LIMIT 1";
        $result = mysqli_query( $db_handle, $query );
    }

    list( $requestID, $updateTime ) = mysqli_fetch_array( $result );

    return $requestID;
}

## Function to get status from local cluster
function get_local_status( $gfacID )
{
   write_logld( "get_local_status( $gfacID )" );
   global $cluster;
   global $self;
   global $cluster_details;

   $ruser     = "us3"; 

   if ( !array_key_exists( $cluster, $cluster_details ) ) {
       write_logld( "$self cluster $cluster missing from global_config.php \$cluster_details" );
       $status = 'UNKNOWN';      
       write_logld( "get_local_status: status = $status");
       return $status;
   }
       
   if ( !array_key_exists( 'name', $cluster_details[$cluster] ) ) {
       write_logld( "$self 'name' key missing from global_config.php \$cluster_details[$cluster]" );
       $status = 'UNKNOWN';      
       write_logld( "get_local_status: status = $status");
       return $status;
   }

   $login = $cluster_details[$cluster]['name'];

   if ( array_key_exists( 'login', $cluster_details[$cluster] ) ) {
       $login = $cluster_details[$cluster]['login'];
   }

   $cmd_prefix = "ssh -x $login ";

   if ( array_key_exists( 'localhost', $cluster_details[$cluster] ) 
        && $cluster_details[$cluster]['localhost'] ) {
       $cmd_prefix = "";
   }

   $cmd    = "$cmd_prefix squeue -t all -j $gfacID 2>&1|tail -n 1";

   write_log( "$self gfacID $gfacID cluster $cluster" );

   $result = exec( $cmd );
echo "locstat: cmd=$cmd  result=$result\n";
write_logld( "$self  locstat: cmd=$cmd  result=$result" );

   $secwait    = 2;
   $num_try    = 0;
   ## Sleep and retry up to 3 times if ssh has "ssh_exchange_identification" error
   while ( preg_match( "/ssh_exchange_id/", $result )  &&  $num_try < 3 )
   {
      sleep( $secwait );
      $num_try++;
      $secwait   *= 2;
write_logld( "$me:   num_try=$num_try  secwait=$secwait" );
   }

   if ( preg_match( "/^qstat: Unknown/", $result )  ||
        preg_match( "/ssh_exchange_id/", $result ) )
   {
      write_logld( "$self get_local_status: Local job $gfacID unknown result=$result" );
      return 'UNKNOWN';
   }

   $values = preg_split( "/\s+/", $result );
   $jstat   = count( $values ) > 5 ? $values[ 5 ] : "unknown";

write_logld( "get_local_status: job status = /$jstat/");
   switch ( $jstat )
   {
      case "W" :                      ## Waiting for execution time to be reached
      case "E" :                      ## Job is exiting after having run
      case "R" :                      ## Still running
      case "CG" :                     ## Job is completing
        $status = 'ACTIVE';
        break;

      case "C" :                      ## Job has completed
      case "ST" :                     ## Job has disappeared
      case "CD" :                     ## Job has completed
        $status = 'COMPLETED';
        break;

      case "T" :                      ## Job is being moved
      case "H" :                      ## Held
      case "Q" :                      ## Queued
      case "PD" :                     ## Queued
      case "CF" :                     ## Queued
        $status = 'SUBMITTED';
        break;

      case "CA" :                     ## Job has been canceled
        $status = 'CANCELED';
        break;

      case "F"  :                     ## Job has failed
      case "BF" :                     ## Job has failed
      case "NF" :                     ## Job has failed
      case "TO" :                     ## Job has timed out
      case ""   :                     ## Job has disappeared
        $status = 'FAILED';
        break;

      default :
        $status = 'UNKNOWN';          ## This should not occur
        break;
   }
write_logld( "get_local_status: status = $status");
  
   return $status;
}

## Function to cancel a local (non-Airavata) job
function cancel_local_job( $gfacID )
{
   write_logld( "cancel_local_job( $gfacID )" );
   global $cluster;
   global $self;
   global $cluster_details;

   if ( !array_key_exists( $cluster, $cluster_details ) ) {
       write_logld( "$self cluster $cluster missing from global_config.php \$cluster_details" );
       return false;
   }
       
   if ( !array_key_exists( 'name', $cluster_details[$cluster] ) ) {
       write_logld( "$self 'name' key missing from global_config.php \$cluster_details[$cluster]" );
       return false;
   }

   $login = $cluster_details[$cluster]['name'];

   if ( array_key_exists( 'login', $cluster_details[$cluster] ) ) {
       $login = $cluster_details[$cluster]['login'];
   }

   $cmd_prefix = "ssh -x $login ";

   if ( array_key_exists( 'localhost', $cluster_details[$cluster] ) 
        && $cluster_details[$cluster]['localhost'] ) {
       $cmd_prefix = "";
   }

   $cmd    = "$cmd_prefix scancel $gfacID 2>&1";

   write_log( "$self gfacID $gfacID cluster $cluster" );

   $result = exec( $cmd );
echo "locstat: cmd=$cmd  result=$result\n";
write_logld( "$self  locstat: cmd=$cmd  result=$result" );

   $secwait    = 2;
   $num_try    = 0;
   ## Sleep and retry up to 3 times if ssh has "ssh_exchange_identification" error
   while ( preg_match( "/ssh_exchange_id/", $result )  &&  $num_try < 3 )
   {
      sleep( $secwait );
      $num_try++;
      $secwait   *= 2;
write_logld( "$me:   num_try=$num_try  secwait=$secwait" );
   }

   ## should likely verify if canceled, perhaps via a call to get_local_status()
   return true;
}

function update_queue_messages( $message )
{
   write_logld( "update_queue_messages( $message )" );
   global $self;
   global $db_handle;
   global $gfacID;

   ## Get analysis table ID
   $query  = "SELECT id FROM gfac.analysis " .
             "WHERE gfacID = '$gfacID' ";
   $result = mysqli_query( $db_handle, $query );
   if ( ! $result )
   {
      write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
      return;
   }
   list( $analysisID ) = mysqli_fetch_array( $result );

   ## Insert message into queue_message table
   $query  = "INSERT INTO gfac.queue_messages SET " .
             "message = '" . mysqli_real_escape_string( $db_handle, $message ) . "', " .
             "analysisID = '$analysisID' ";
   $result = mysqli_query( $db_handle, $query );
   if ( ! $result )
   {
      write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
      return;
   }
}

function update_db( $message ) {
   write_logld( "update_db( $message )" );
   global $self;
   global $gfacID;
   global $dbhost;
   global $user;
   global $passwd;
   global $us3_db;
   global $db_handle;

   $requestID = get_us3_data();

   $query = "UPDATE {$us3_db}.HPCAnalysisResult SET " .
            "lastMessage='" . mysqli_real_escape_string( $db_handle, $message ) . "'" .
            "WHERE gfacID = '$gfacID' AND HPCAnalysisRequestID = '$requestID' ";

   mysqli_query( $db_handle, $query );
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
#   $headers .= "Cc: $org_name Admin<alexsav.science@gmail.com>"       . "\n";
   $headers .= "Bcc: Gary Gorbet<gegorbet@gmail.com>"    . "\n";     ## make sure

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

function update_autoflow_status( $status, $message ) {
    global $db_handle;
    global $gfacID;
    global $autoflowAnalysisID;
    global $us3_db;
    global $self;

    write_logld( "update_autoflow_status() id $autoflowAnalysisID status $status message $message" );
        
    if ( $autoflowAnalysisID <= 0 ) {
        write_logld( "update_autoflow_status() ignored, no id" );
        return;
    }
    # escape quotes in message
    $sqlmessage = str_replace( "'", "\'", $message );
    $query = "UPDATE {$us3_db}.autoflowAnalysis SET " .
        "status='$status', " . 
        "statusMsg='$sqlmessage' " . 
        "WHERE requestID = '$autoflowAnalysisID' AND currentGfacID = '$gfacID' AND NOT status RLIKE '^(failed|error|canceled)\$'";
    
    $result = mysqli_query( $db_handle, $query );
    if ( ! $result ) {
        ## Just log it and continue
        write_logld( "Bad query:\n$query\n" . mysqli_error( $db_handle ) );
    }
}
