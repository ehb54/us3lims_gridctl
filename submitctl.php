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
$submit_request_table_name         = "AutoflowAnalysis";
$submit_request_history_table_name = "AutoflowAnalysisHistory";
$id_field                          = "RequestID";
$processing_key                    = "submitted";
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

function debug_json( $msg, $json ) {
    echo "$msg\n";
    echo json_encode( $json, JSON_PRETTY_PRINT );
    echo "\n";
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
    $query        = "SELECT ${id_field}, us3_db_name, Cluster_default, status, status_json, create_user FROM ${submit_request_table_name}";
    $outer_result = mysqli_query( $db_handle, $query );

    if ( !$outer_result || !$outer_result->num_rows ) {
        if ( $outer_result ) {
            # $outer_result->free_result();
        }
        write_logl( "$self: no requests to process sleeping ${poll_sleep_seconds}s", 2 );
        sleep( $poll_sleep_seconds );
        continue;
    }

    write_logl( "$self: found $outer_result->num_rows in ${submit_request_table_name} to check on db ${db}", 1 );

    $work_done = 0;

    while ( $obj =  mysqli_fetch_object( $outer_result ) ) {
        if ( !isset( $obj->{ $id_field } ) ) {
            write_logl( "$self: critical: no id found in mysql result!" );
            continue;
        }
        $ID = $obj->{ $id_field };

        $status_json = json_decode( $obj->{"status_json"} );
        debug_json( "after fetch, decode", $status_json );
        
        if ( isset( $status_json->{ $processing_key } ) &&
             !empty( $status_json->{ $processing_key } ) ) {
            write_logl( "$self: AutoflowAnalysis ${id_field} $ID is ${processing_key}", 1 );
            continue;
        }

        if ( isset( $status_json->{ "to_process" } ) &&
             count( $status_json->{ "to_process" } ) ) {
            $stage = array_shift( $status_json->{ "to_process" } );
            $status_json->{ $processing_key } = $stage;
            
            debug_json( "after shift to ${processing_key}", $status_json );

            $query  = "UPDATE ${submit_request_table_name} SET status_json='" . json_encode( $status_json ) . "' WHERE ${id_field} = ${ID}";
            $result = mysqli_query( $db_handle, $query );

            

            write_logl( "$self: AutoflowAnalysis submitting ${id_field} $ID stage " . json_encode( $stage ), 1 );
            if ( !$result ) {
                write_logl( "$self: error updating table ${submit_request_table_name} ${id_field} ${ID} status_json.", 0 );
            } else {
                write_logl( "$self: success updating table ${submit_request_table_name} ${id_field} ${ID} status_json.", 2 );
            }
            # ADD SUBMIT CALL
            $work_done = 1;
            continue;
        } 
        
        # must be completed
        write_logl( "$self: AutoflowAnalysis ${id_field} $ID all processing complete, moving to history", 1 );

        $query  = "INSERT ${submit_request_history_table_name} SELECT * FROM ${submit_request_table_name} WHERE ${id_field} = ${ID}";
        $result = mysqli_query( $db_handle, $query );

        if ( !$result ) {
            write_logl( "$self: error copying ${id_field} ${ID} from ${submit_request_table_name} to ${submit_request_history_table_name}.", 0 );
        } else {
            write_logl( "$self: success copying ${id_field} ${ID} from ${submit_request_table_name} to ${submit_request_history_table_name}.", 2 );
            # $result->free_result();
        }        
        
        $query  = "DELETE FROM ${submit_request_table_name} WHERE ${id_field} = ${ID}";
        $result = mysqli_query( $db_handle, $query );
        
        if ( !$result ) {
            write_logl( "$self: error deleting ${id_field} ${ID} from ${submit_request_table_name}.", 0 );
        } else {
            write_logl( "$self: success deleting ${id_field} ${ID} from ${submit_request_table_name}.", 2 );
            # $result->free_result();
        }
        $work_done = 1;
    }
    if ( !$work_done ) {
        write_logl( "$self: no requests to process sleeping ${poll_sleep_seconds}s", 2 );
        sleep( $poll_sleep_seconds );
    }
}
