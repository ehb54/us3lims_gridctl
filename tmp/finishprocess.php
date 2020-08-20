<?php

if ( count( $argv ) != 4 ) {
    echo "usage: finishprocess.php db ID status\n";
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

$lims_db = $argv[ 1 ];
$ID      = $argv[ 2 ];
$status  = $argv[ 3 ];

write_logl( "$self: Starting" );

do {
    $db_handle = mysqli_connect( $dbhost, $user, $passwd, $db );
    if ( !$db_handle ) {
        write_logl( "$self: could not connect to mysql: $dbhost, $user, $db. Will retry in ${poll_sleep_seconds}s" );
        sleep( $poll_sleep_seconds );
    }
} while ( !$db_handle );

write_logl( "$self: connected to mysql: $dbhost, $user, $db.", 2 );

$query        = "SELECT statusJson FROM ${lims_db}.${submit_request_table_name} WHERE ${id_field}=$ID";
$outer_result = mysqli_query( $db_handle, $query );

if ( !$outer_result || !$outer_result->num_rows ) {
    if ( $outer_result ) {
        # $outer_result->free_result();
    }
    write_logl( "$self: ${id_field} $ID not found in ${lims_db}.${submit_request_table_name}", 2 );
    exit;
}

$obj =  mysqli_fetch_object( $outer_result );

$statusJson = json_decode( $obj->{"statusJson"} );
debug_json( "after fetch, decode", $statusJson );
        
if ( !isset( $statusJson->{ $processing_key } ) ||
     empty( $statusJson->{ $processing_key } ) ) {
    write_logl( "$self: ${submit_request_table_name} db ${lims_db} ${id_field} $ID is NOT ${processing_key}", 1 );
    exit;
}

$stage = $statusJson->{ $processing_key };
unset( $statusJson->{ $processing_key } );
$statusJson->{ "processed" }[] = $stage;
    
debug_json( "after shift to ${processing_key}", $statusJson );

$query  = "UPDATE ${lims_db}.${submit_request_table_name} SET status='$status', statusJson='" . json_encode( $statusJson ) . "' WHERE ${id_field} = ${ID}";
$result = mysqli_query( $db_handle, $query );
write_logl( "$self: ${submit_request_table_name} db ${lims_db} submitting ${id_field} $ID stage " . json_encode( $stage ), 1 );
if ( !$result ) {
    write_logl( "$self: error updating db ${lims_db} table ${submit_request_table_name} ${id_field} ${ID} statusJson.", 0 );
} else {
    write_logl( "$self: success updating db db ${lims_db} table ${submit_request_table_name} ${id_field} ${ID} statusJson.", 2 );
}
