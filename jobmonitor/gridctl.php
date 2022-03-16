<?php

# functions for jobmonitor.php

## Function to determine if this is an airavata/thrift job or not
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

    ## If entry is for Airvata/Thrift, get the true current status

    if ( is_aira_job( $gfacID ) ) {

        $status_in  = $status;
        $status     = aira_status( $gfacID, $status_in );
        if($status != $status_in ) {
            write_logld( "Set to $status from $status_in" );
        }
    } else {
        $status_gw  = $status;
        $status     = get_local_status( $gfacID );
        if ( $status_gw == 'COMPLETE'  ||  $status == 'UNKNOWN' ) {
            $status     = $status_gw;
        }
        write_logld( "Local status=$status status_gw=$status_gw" );
    }

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

        case "DATA":
            case "RESULTS_GEN":
            wait_data( $time );
            break;

        case "DATA_TIMEOUT":
            data_timeout( $time );
            return true;
            break;

        case "COMPLETED":
            case "COMPLETE":
            write_logld( "  COMPLETE gfacID=$gfacID" );
            complete();
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
            if ( ! is_aira_job( $gfacID ) ) {
                complete();
                return true;
            }
            write_logld( "  FINISHED gfacID=$gfacID" );
            break;
        
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

    $now = time();

    if ( $updatetime + 600 > $now ) { ## < 10 minutes ago
        return;
    }

    if ( $updatetime + 86400 > $now ) {
        ## Within the first 24 hours 
        
        if ( ( $job_status = get_gfac_status( $gfacID ) ) === false ) {
            $job_status = get_local_status( $gfacID );
        }
        
        if ( $job_status == 'GFAC_STATUS_UNAVAILABLE' ) {
            return;
        }
        
        if ( ! in_array( $job_status, array( 'SUBMITTED', 'INITIALIZED', 'PENDING' ) ) ) {
            write_logld( "submitted:job_status=$job_status" );
            update_job_status( $job_status, $gfacID );
        }

        return;
    }

    $message = "Job listed submitted longer than 24 hours";
    write_logld( "$message - id: $gfacID" );
    mail_to_admin( "hang", "$message - id: $gfacID" );
    $query = "UPDATE gfac.analysis SET status='SUBMIT_TIMEOUT' WHERE gfacID='$gfacID'";
    $result = mysqli_query( $db_handle, $query );
    
    if ( ! $result ) {
        write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
    }
    
    update_queue_messages( $message );
    update_db( $message );
    update_autoflow_status( 'SUBMIT_TIMEOUT', $message );
}

function submit_timeout( $updatetime ) {

    global $self;
    global $db_handle;
    global $gfacID;
    global $autoflowAnalysisID;

    if ( ( $job_status = get_gfac_status( $gfacID ) ) === false ) {
        $job_status = get_local_status( $gfacID );
    }

    if ( $job_status == 'GFAC_STATUS_UNAVAILABLE' ) {
        return;
    }

    if ( ! in_array( $job_status, array( 'SUBMITTED', 'INITIALIZED', 'PENDING' ) ) ) {
        update_job_status( $job_status, $gfacID );
        return;
    }

    $now = time();

    if ( $updatetime + 86400 > $now ) {
        return; ## < 24 hours ago ( 48 total submitted )
    }

    $message = "Job listed submitted longer than 48 hours";
    write_logld( "$message - id: $gfacID" );
    mail_to_admin( "hang", "$message - id: $gfacID" );
    $query = "UPDATE gfac.analysis SET status='FAILED' WHERE gfacID='$gfacID'";
    $result = mysqli_query( $db_handle, $query );

    if ( ! $result ) {
        write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
    }

    update_queue_messages( $message );
    update_db( $message );
    update_autoflow_status( 'FAILED', $message );
}

function running( $updatetime, $queue_msg ) {
    global $self;
    global $db_handle;
    global $gfacID;
    global $autoflowAnalysisID;

    $now = time();

    get_us3_data();

    update_autoflow_status( 'RUNNING', $queue_msg );

    if ( $updatetime + 600 > $now ) {
        return;   ## message received < 10 minutes ago
    }

    if ( $updatetime + 86400 > $now ) {
        ## Within the first 24 hours

        if ( ( $job_status = get_gfac_status( $gfacID ) ) === false ) {
            $job_status = get_local_status( $gfacID );
        }

        if ( $job_status == 'GFAC_STATUS_UNAVAILABLE' ) {
            return;
        }

        if ( ! in_array( $job_status, array( 'ACTIVE', 'RUNNING', 'STARTED' ) ) ) {
            update_job_status( $job_status, $gfacID );
        }
        return;
    }

    $message = "Job listed running longer than 24 hours";
    write_logld( "$message - id: $gfacID" );
    mail_to_admin( "hang", "$message - id: $gfacID" );
    $query = "UPDATE gfac.analysis SET status='RUN_TIMEOUT' WHERE gfacID='$gfacID'";
    $result = mysqli_query( $db_handle, $query );

    if ( ! $result ) {
        write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
    }

    update_queue_messages( $message );
    update_db( $message );
    update_autoflow_status( 'RUN_TIMEOUT', $message );
}

function run_timeout( $updatetime ) {

    global $self;
    global $db_handle;
    global $gfacID;
    global $autoflowAnalysisID;

    if ( ( $job_status = get_gfac_status( $gfacID ) ) === false ) {
        $job_status = get_local_status( $gfacID );
    }

    if ( $job_status == 'GFAC_STATUS_UNAVAILABLE' )  {
        return;
    }
    
    if ( ! in_array( $job_status, array( 'ACTIVE', 'RUNNING', 'STARTED' ) ) ) {
        update_job_status( $job_status, $gfacID );
        return;
    }

    $now = time();

    get_us3_data();

    if ( $updatetime + 172800 > $now ) {
        return; ## < 48 hours ago
    }

    $message = "Job listed running longer than 48 hours";
    write_logld( "$message - id: $gfacID" );
    mail_to_admin( "hang", "$message - id: $gfacID" );
    $query = "UPDATE gfac.analysis SET status='FAILED' WHERE gfacID='$gfacID'";
    $result = mysqli_query( $db_handle, $query );

    if ( ! $result ) {
        write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
    }

    update_queue_messages( $message );
    update_db( $message );
    update_autoflow_status( 'FAILED', $message );
}

function wait_data( $updatetime ) {

    global $self;
    global $db_handle;
    global $gfacID;
    global $autoflowAnalysisID;

    $now = time();

    if ( $updatetime + 3600 > $now ) {
        ## < Within the first hour
        if ( ( $job_status = get_gfac_status( $gfacID ) ) === false ) {
            $job_status = get_local_status( $gfacID );
        }
        
        if ( $job_status == 'GFAC_STATUS_UNAVAILABLE' )  {
            return;
        }

        if ( $job_status != 'DATA' ) {
            update_job_status( $job_status, $gfacID );
            return;
        }

        ## Request to resend data, but only request every 5 minutes
        $minute = date( 'i' ) * 1; ## Makes it an int
        if ( $minute % 5 ) {
            return;
        }
        
        $output_status = get_gfac_outputs( $gfacID );

        if ( $output_status !== false ) {
            mail_to_admin( "debug", "wait_data/$gfacID/$output_status" );
        }

        return;
    }

    $message = "Waiting for data longer than 1 hour";
    write_logld( "$message - id: $gfacID" );
    mail_to_admin( "hang", "$message - id: $gfacID" );
    $query = "UPDATE gfac.analysis SET status='DATA_TIMEOUT' WHERE gfacID='$gfacID'";
    $result = mysqli_query( $db_handle, $query );

    if ( ! $result ) {
        write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
    }

    update_queue_messages( $message );
    update_db( $message );
    update_autoflow_status( 'DATA_TIMEOUT', $message );
}

function data_timeout( $updatetime ) {

    global $self;
    global $db_handle;
    global $gfacID;
    global $autoflowAnalysisID;

    if ( ( $job_status = get_gfac_status( $gfacID ) ) === false ) {
        $job_status = get_local_status( $gfacID );
    }

    if ( $job_status == 'GFAC_STATUS_UNAVAILABLE' ) {
        return;
    }

    if ( $job_status != 'DATA' ) {
        update_job_status( $job_status, $gfacID );
        return;
    }

    $now = time();

    if ( $updatetime + 86400 > $now ) {
        ## < 24 hours ago
        ## Request to resend data, but only request every 15 minutes
        $minute = date( 'i' ) * 1; ## Makes it an int
        if ( $minute % 15 ) {
            return;
        }
        
        $output_status = get_gfac_outputs( $gfacID );

        if ( $output_status !== false ) {
            mail_to_admin( "debug", "data_timeout/$gfacID/$output_status" );
        }

        return;
    }

    $message = "Waiting for data longer than 24 hours";
    write_logld( "$message - id: $gfacID" );
    mail_to_admin( "hang", "$message - id: $gfacID" );
    $query = "UPDATE gfac.analysis SET status='FAILED' WHERE gfacID='$gfacID'";
    $result = mysqli_query( $db_handle, $query );

    if ( ! $result ) {
        write_logld( "Query failed $query - " .  mysqli_error( $db_handle ) );
    }

    update_queue_messages( $message );
    update_db( $message );
    update_autoflow_status( 'FAILED', $message );
}

function complete() {
    ## Just cleanup
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
    global $class_dir;

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

    if ( preg_match( "/US3-A/i", $gfacID ) ) {
        write_logld( "calling aria_cleanup() reqID=$requestID" );
        aira_cleanup( $us3_db, $requestID, $db_handle );
    } else {
        ## Non-airavata job:  clean up in a non-aira way
        write_logld( "calling gfac_cleanup() reqID=$requestID" );
        gfac_cleanup( $us3_db, $requestID, $db_handle );
    }
}

## Function to update status of job
function update_job_status( $job_status, $gfacID ) {
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
        "FROM ${us3_db}.HPCAnalysisResult WHERE gfacID='$gfacID'";
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
            "FROM ${us3_db}.HPCAnalysisResult WHERE gfacID='$gfacID' " .
            " ORDER BY HPCAnalysisResultID DESC LIMIT 1";
        $result = mysqli_query( $db_handle, $query );
    }


    list( $requestID, $updateTime ) = mysqli_fetch_array( $result );

    return $requestID;
}

## Function to determine if this is an airavata/thrift job or not

function is_aira_job( $gfacID ) {
    return preg_match( "/US3-A/i", $gfacID ) ? true : false;
}

## Function to get the current job status from GFAC
function get_gfac_status( $gfacID )
{
    global $serviceURL;
    global $self;
    global $cluster;
    global $status_ex, $status_gw;

    if ( is_aira_job( $gfacID ) )
    {
        $status_ex    = getExperimentStatus( $gfacID );

        if ( $status_ex == 'EXECUTING' )
        {
            if ( $status_gw == 'RUNNING' )
                $status_ex    = 'ACTIVE';
            else
                $status_ex    = 'QUEUED';
        }

        $gfac_status  = standard_status( $status_ex );
    }

    else
    {
        return false;
    }

    return $gfac_status;
}

## Function to request data outputs from GFAC
function get_gfac_outputs( $gfacID )
{
    global $serviceURL;
    global $self;

    ## Make sure it's a GFAC job and status is appropriate for this call
    if ( ( $job_status = get_gfac_status( $gfacID ) ) === false )
    {
        ## Then it's not a GFAC job
        $job_status = get_local_status( $gfacID );
        return $job_status;
    }

    if ( ! in_array( $job_status, array( 'DONE', 'FAILED', 'COMPLETE', 'FINISHED' ) ) )
    {
        ## Then it's not appropriate to request data
        return false;
    }

    /*
        $url = "$serviceURL/registeroutput/$gfacID";
    try
    {
        $post = new HttpRequest( $url, HttpRequest::METH_GET );
        $http = $post->send();
        $xml  = $post->getResponseBody();      
    }
    catch ( HttpException $e )
    {
        write_logld( "Data not available - request failed -  $gfacID" );
        return false;
    }

    mail_to_admin( "debug", "get_gfac_outputs/\n$xml/" );

    $gfac_status = parse_response( $xml );

    */
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

## Function to get status from local cluster
function get_local_status( $gfacID )
{
   write_logld( "get_local_status( $gfacID )" );
   global $cluster;
   global $self;

   $is_demel3 = preg_match( "/demeler3/",   $cluster );
   $is_demel1 = preg_match( "/demeler1/",   $cluster );
   $is_jetstr = preg_match( "/jetstream/",  $cluster );
   $is_chino  = preg_match( "/chinook/",    $cluster );
   $is_umont  = preg_match( "/umontana/",   $cluster );
   $is_us3iab = preg_match( "/us3iab/",     $cluster );
   $is_slurm  = ( $is_jetstr  ||  $is_us3iab );
   $is_squeu  = ( $is_jetstr  ||  $is_chino  ||  $is_umont  ||  $is_us3iab || $is_demel1 );
   $ruser     = "us3";

   if ( $is_squeu )
      $cmd    = "squeue -t all -j $gfacID 2>&1|tail -n 1";
   else
      $cmd    = "/usr/bin/qstat -a $gfacID 2>&1|tail -n 1";
##write_logld( "$self cmd: $cmd" );
##write_logld( "$self cluster: $cluster" );
##write_logld( "$self gfacID: $gfacID" );

   write_log( "$self gfacID $gfacID cluster $cluster" );
   if ( ! $is_us3iab )
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

      write_log( "$self !is_usiab system $system" );
      $cmd    = "/usr/bin/ssh -x $ruser@$system " . $cmd;
write_logld( "$self  cmd: $cmd" );
   }

   $result = exec( $cmd );
   ##write_logld( "$self  result: $result" );
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
   $jstat   = ( $is_squeu == 0 ) ? $values[ 9 ] : $values[ 5 ];
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

   $query = "UPDATE ${us3_db}.HPCAnalysisResult SET " .
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

## Convert a status string to one of the standard DB status strings
function standard_status( $status_in )
{
   switch ( $status_in )
   {  ## Map variations to standard gateway status values
      case 'QUEUED' :
      case 'LAUNCHED' :
      case 'CREATED' :
      case 'VALIDATED' :
      case 'SCHEDULED' :
      case 'submitted' :
      case 'SUBMITTED' :
      case '' :
         $status      = 'SUBMITTED';
         break;

      case 'EXECUTING' :
      case 'ACTIVE' :
      case 'running' :
      case 'executing' :
         $status      = 'RUNNING';
         break;

      case 'PENDING' :
      case 'CANCELING' :
         $status      = 'UPDATING';
         break;

      case 'CANCELLED' :
      case 'canceled' :
         $status      = 'CANCELED';
         break;

##         $status      = 'DATA';
##         break;

      case 'COMPLETED' :
      case 'completed' :
         $status      = 'COMPLETE';
         break;

      case 'FAILED_DATA' :
      case 'SUBMIT_TIMEOUT' :
      case 'RUN_TIMEOUT' :
      case 'DATA_TIMEOUT' :
         $status      = 'FAILED';
         break;

      case 'COMPLETE' :
         $status      = 'DONE';
         break;

      case 'UNKNOWN' :
         $status      = 'ERROR';
         break;

      ## Where already standard value, retain value
      case 'ERROR' :
      case 'RUNNING' :
      case 'SUBMITTED' :
      case 'UPDATING' :
      case 'CANCELED' :
      case 'DATA' :
      case 'FAILED' :
      case 'DONE' :
      case 'FINISHED' :
      default :
         $status   = $status_in;
         break;
   }

   return $status;
}

function aira_status( $gfacID, $status_in )
{
   write_logld( "aira_status( $gfacID )" );
   global $self;
   global $class_dir;
    ##echo "a_st: st_in$status_in : $gfacID\n";
   ##$status_gw = standard_status( $status_in );
   $status_gw = $status_in;
##echo "a_st:  st_db=$status_gw\n";
   $status    = $status_gw;
   $me_devel  = preg_match( "/class_devel/", $class_dir );
   $job_devel = preg_match( "/US3-ADEV/i", $gfacID );
   $devmatch  = ( ( !$me_devel  &&  !$job_devel )  ||
                  (  $me_devel  &&   $job_devel ) );

   if ( preg_match( "/US3-A/i", $gfacID )  &&  $devmatch )
   {
      write_logld( "status_in=$status_in status=$status gfacID=$gfacID" );
      $status_ex = getExperimentStatus( $gfacID );
      write_logld( "  status_ex=$status_ex" );

      if ( $status_ex == "CANCELED" ) {
          return $status_ex;
      }

      if ( $status_ex == 'COMPLETED' )
      {  ## Experiment is COMPLETED: check for 'FINISHED' or 'DONE'
         if ( $status_gw == 'FINISHED'  ||  $status_gw == 'DONE' )
         {  ## COMPLETED + FINISHED/DONE : gateway status is now COMPLETE
            $status    = 'COMPLETE';
         }

         else
         {  ## COMPLETED + NOT-FINISHED/DONE:  gw status now DONE
            $status    = 'DONE';
         }
      }

      else if ( $status_gw == 'FINISHED'  ||  $status_gw == 'DONE' )
      {  ## Gfac status == FINISHED/DONE:  leave as is (unless FAILED)
         $status    = $status_gw;
         if ( $status_ex == 'FAILED' )
         {
            sleep( 10 );
            $status_ex = getExperimentStatus( $gfacID );
            if ( $status_ex == 'FAILED' )
            {
               write_logld( "status still 'FAILED' after 10-second delay" );
               sleep( 10 );
               $status_ex = getExperimentStatus( $gfacID );
               if ( $status_ex == 'FAILED' )
                  write_logld( "status still 'FAILED' after 20-second delay" );
               else
                  write_logld( "status is $status_ex after 20-second delayed retry" );
            }
            write_logld( "status reset to 'COMPLETE'" );
            $status    = 'COMPLETE';
         }
      }

      else if ( $status_ex == 'EXECUTING' )
      {
         $status    = standard_status( $status_gw );
write_logld( "status/_in/_gw/_ex=$status/$status_in/$status_gw/$status_ex" );
      }

      else
      {  ## Experiment not COMPLETED/FINISHED/DONE: use experiment status
         $status    = standard_status( $status_ex );
      }

##if ( $status != 'SUBMITTED' )
##write_logld( "status/_in/_gw/_ex=$status/$status_in/$status_gw/$status_ex" );
      if ( $status != $status_gw )
      {
         update_job_status( $status, $gfacID );
      }
   }

   return $status;
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
    $query = "UPDATE ${us3_db}.autoflowAnalysis SET " .
        "status='$status', " . 
        "statusMsg='$sqlmessage' " . 
        "WHERE requestID = '$autoflowAnalysisID' AND currentGfacID = '$gfacID' AND NOT status RLIKE '^(failed|error|canceled)\$'";
    
    $result = mysqli_query( $db_handle, $query );
    if ( ! $result ) {
        ## Just log it and continue
        write_logld( "Bad query:\n$query\n" . mysqli_error( $db_handle ) );
    }
}
