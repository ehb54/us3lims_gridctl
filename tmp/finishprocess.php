<?php

if ( count( $argv ) != 5 ) {
    echo "usage: finishprocess.php db ID status statusMsg\n";
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
$db                                = "gfac";
$submit_request_table_name         = "autoflowAnalysis";
$submit_request_history_table_name = "autoflowAnalysisHistory";
$id_field                          = "requestID";
$processing_key                    = "submitted";
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

$lims_db   = $argv[ 1 ];
$ID        = $argv[ 2 ];
$status    = $argv[ 3 ];
$statusMsg = $argv[ 4 ];

write_logl( "$self: Starting" );

do {
    $db_handle = mysqli_connect( $dbhost, $user, $passwd, $db );
    if ( !$db_handle ) {
        write_logl( "$self: could not connect to mysql: $dbhost, $user, $db. Will retry in ${poll_sleep_seconds}s" );
        sleep( $poll_sleep_seconds );
    }
} while ( !$db_handle );

write_logl( "$self: connected to mysql: $dbhost, $user, $db.", 2 );

$query  = "UPDATE ${lims_db}.${submit_request_table_name} SET status='$status', statusMsg='$statusMsg' WHERE ${id_field} = ${ID}";
$result = mysqli_query( $db_handle, $query );
if ( !$result ) {
    write_logl( "$self: error updating db ${lims_db} table ${submit_request_table_name} ${id_field} ${ID} status, statusMsg query:\n$query\n", 0 );
} else {
    write_logl( "$self: success updating db db ${lims_db} table ${submit_request_table_name} ${id_field} ${ID}", 2 );
}
