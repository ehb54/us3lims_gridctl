<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
include "$us3bin/listen-config.php";
include "$class_dir/experiment_status.php";

# ********* start user defines *************

# the polling interval
$poll_sleep_seconds = 30;

# logging_level 
# 0 : minimal messages (expected value for production)
# 1 : add detailed db messages
# 2 : add idle polling messages
$logging_level      = 2;
    
# ********* end user defines ***************

# ********* start admin defines *************
# these should only be changed by developers
$db                                = "gfac";
$submit_request_table_name         = "submit_request";
$submit_request_history_table_name = "submit_request_history";
# ********* end admin defines ***************


# add locking
if ( isset( $lock_dir ) ) {
    $lock_main_script_name  = __FILE__;
    require "$us3bin/lock.php";
}

function write_logl( $msg, $this_level = 0 ) {
    global $logging_level;
    if ( $logging_level >= $this_level ) {
        write_log( $msg );
    }
}

# Gary: should we have our own log? currently log is "udp.log" 

write_logl( "$self: Starting" );

do {
    $db_handle = mysqli_connect( $dbhost, $user, $passwd, $db );
    if ( !$db_handle ) {
        write_logl( "$self: could not connect to mysql: $dbhost, $user, $db. Will retry in ${poll_sleep_seconds}s" );
        sleep( $poll_sleep_seconds );
    }
} while ( !$db_handle );

write_logl( "$self: connected to mysql: $dbhost, $user, $db.", 2 );

while( 1 ) {
    write_logl( "$self: checking mysql", 2 );

    # read from mysql - $submit_request_table_name
    $query  = "SELECT requestID, lims_db, create_user, requestXMLFile FROM ${submit_request_table_name} LIMIT 1";
    $result = mysqli_query( $db_handle, $query );
    if ( !$result || !$result->num_rows ) {
        if ( $result ) {
            # $result->free_result();
        }
        write_logl( "$self: no requests to process sleeping ${poll_sleep_seconds}s", 2 );
        sleep( $poll_sleep_seconds );
        continue;
    }
    list( $requestID, $lims_db, $create_user, $requestXMLFile ) =  mysqli_fetch_array( $result );

    write_logl( "$self: found 1 ${submit_request_table_name} row id ${requestID} from user ${create_user} to process on db ${lims_db}", 1 );

    # update status of $submit_request_table_name to "submitting" ?

    $query  = "UPDATE ${submit_request_table_name} SET status='submitting' WHERE requestID = ${requestID}";
    $result = mysqli_query( $db_handle, $query );

    if ( !$result ) {
        write_logl( "$self: error updating table ${submit_request_table_name} requestID ${requestID} status to submitted.", 0 );
    } else {
        write_logl( "$self: success updating table ${submit_request_table_name} requestID ${requestID} status to submitted.", 2 );
        # $result->free_result();
    }

    # call submit_job()
    write_logl( "$self: (pretending for now) submitting requestID ${requestID}", 2 );
    
    # move $submit_request_table_name to $submit_request_table_name

    $query  = "INSERT ${submit_request_history_table_name} SELECT * FROM ${submit_request_table_name} WHERE requestID = ${requestID}";
    $result = mysqli_query( $db_handle, $query );

    if ( !$result ) {
        write_logl( "$self: error copying requestID ${requestID} from ${submit_request_table_name} to ${submit_request_history_table_name}.", 0 );
    } else {
        write_logl( "$self: success copying requestID ${requestID} from ${submit_request_table_name} to ${submit_request_history_table_name}.", 2 );
        # $result->free_result();
    }        
    
    $query  = "DELETE FROM ${submit_request_table_name} WHERE requestID = ${requestID}";
    $result = mysqli_query( $db_handle, $query );
    
    if ( !$result ) {
        write_logl( "$self: error deleting requestID ${requestID} from ${submit_request_table_name}.", 0 );
    } else {
        write_logl( "$self: success deleting requestID ${requestID} from ${submit_request_table_name}.", 2 );
        # $result->free_result();
    }
    
    # update any other tables?
}
