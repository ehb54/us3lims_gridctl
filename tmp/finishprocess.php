<?php

if ( count( $argv ) != 2 ) {
    echo "usage: finishprocess.php ID\n";
    exit;
}

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
$db                                = "emre_test";
$submit_request_table_name         = "AutoflowAnalysis";
$submit_request_history_table_name = "AutoflowAnalysisHistory";
# ********* end admin defines ***************

function write_logl( $msg, $this_level = 0 ) {
    global $logging_level;
    if ( $logging_level >= $this_level ) {
        echo $msg . "\n";
    }
}

function debug_json( $msg, $json ) {
    echo "$msg\n";
    echo json_encode( $json, JSON_PRETTY_PRINT );
    echo "\n";
}

# Gary: should we have our own log? currently log is "udp.log" 

$ID = $argv[ 1 ];

write_logl( "$self: Starting" );

do {
    $db_handle = mysqli_connect( $dbhost, $user, $passwd, $db );
    if ( !$db_handle ) {
        write_logl( "$self: could not connect to mysql: $dbhost, $user, $db. Will retry in ${poll_sleep_seconds}s" );
        sleep( $poll_sleep_seconds );
    }
} while ( !$db_handle );

write_logl( "$self: connected to mysql: $dbhost, $user, $db.", 2 );

$query        = "SELECT status_json  FROM ${submit_request_table_name} WHERE ID=$ID";
$outer_result = mysqli_query( $db_handle, $query );

if ( !$outer_result || !$outer_result->num_rows ) {
    if ( $outer_result ) {
        # $outer_result->free_result();
    }
    write_logl( "$self: ID $ID not found in ${submit_request_table_name}", 2 );
    exit;
}

$obj =  mysqli_fetch_object( $outer_result );

$status_json = json_decode( $obj->{"status_json"} );
debug_json( "after fetch, decode", $status_json );
        
if ( !isset( $status_json->{ "processing" } ) ||
     empty( $status_json->{ "processing" } ) ) {
    write_logl( "$self: AutoflowAnalysis ID $ID is NOT processing", 1 );
    exit;
}

$stage = $status_json->{ "processing" };
unset( $status_json->{"processing"} );
$status_json->{ "processed" }[] = $stage;
    
debug_json( "after shift to processing", $status_json );

$query  = "UPDATE ${submit_request_table_name} SET status_json='" . json_encode( $status_json ) . "' WHERE ID = ${ID}";
$result = mysqli_query( $db_handle, $query );
write_logl( "$self: AutoflowAnalysis submitting ID $ID stage " . json_encode( $stage ), 1 );
if ( !$result ) {
    write_logl( "$self: error updating table ${submit_request_table_name} ID ${ID} status_json.", 0 );
} else {
    write_logl( "$self: success updating table ${submit_request_table_name} ID ${ID} status_json.", 2 );
}
