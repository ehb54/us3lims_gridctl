<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
include "$us3bin/listen-config.php";
include "$class_dir/experiment_status.php";

# ********* start user defines *************

# the polling interval
$poll_sleep_seconds = 30;

# logging_level 
# 0 : minimal messages (expected value for production)
# 1 : add some db messages
# 2 : add idle polling messages
$logging_level      = 1;
    
# ********* end user defines ***************

# ********* start admin defines *************
# these should only be changed by developers
$db                                = "gfac";
$submit_request_table_name         = "AutoflowAnalysis";
$submit_request_history_table_name = "AutoflowAnalysisHistory";
$id_field                          = "RequestID";
$processing_key                    = "submitted";
$failed_status                     = [ "failed" => 1, "error" => 1 ];
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

# find databases with autoflow

$trylimsdbs = [];
$query  = "SHOW DATABASES";
$result = mysqli_query( $db_handle, $query );
while ( $obj =  mysqli_fetch_object( $result ) ) {
    $this_db = $obj->{ 'Database' };
    # echo "Database: $this_db\n";
    if ( substr( $this_db, 0, 8 ) === "uslims3_" ) {
        $trylimsdbs[] = $this_db;
        # echo "added to limsdbs\n";
    }
}

$limsdbs = [];

foreach ( $trylimsdbs as $v ) {
    $msg = "checking db $v for autoflow tables";
    echo  "$msg\n";
    $success = false;
    $query  = "select count(*) from ${v}.AutoflowAnalysis";
    $result = mysqli_query( $db_handle, $query );
    if ( $result ) {
        $query  = "select count(*) from ${v}.AutoflowAnalysisHistory";
        $result = mysqli_query( $db_handle, $query );
        if ( $result ) {
            $success = true;
            $limsdbs[] = $v;
        }
    }

    if ( $success ) {
        write_logl( "$self: added db $v for autoflow submission control", 1 );
    } else {
        write_logl( "$self: db $v does not have AutoflowAnalysis tables, ignoring", 1 );
    }
}
unset( $trylimsdbs );

if ( !count( $limsdbs ) ) {
    write_logl( "$self: found no databases with AutoflowAnalysis tables, quitting", 0 );
    exit;
}
    
while( 1 ) {
    write_logl( "$self: checking mysql", 2 );

    $work_done = 0;

    foreach ( $limsdbs as $lims_db ) {
        write_logl( "$self: checking mysql db ${lims_db}", 2 );
        
        # read from mysql - $submit_request_table_name
        $query        = "SELECT ${id_field}, Cluster_default, status, status_json, create_user FROM ${lims_db}.${submit_request_table_name}";
        echo "query is\n$query\n";
        $outer_result = mysqli_query( $db_handle, $query );

        if ( !$outer_result || !$outer_result->num_rows ) {
            if ( $outer_result ) {
                # $outer_result->free_result();
            }
            continue;
        }

        write_logl( "$self: found $outer_result->num_rows in ${submit_request_table_name} to check on db ${lims_db}", 2 );

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
                write_logl( "$self: AutoflowAnalysis ${id_field} $ID is ${processing_key}", 2 );
                continue;
            }

            $failed = array_key_exists( $obj->{ 'status' }, $failed_status );

            if ( !$failed &&
                 isset( $status_json->{ "to_process" } ) &&
                 count( $status_json->{ "to_process" } ) ) {
                $stage = array_shift( $status_json->{ "to_process" } );
                $status_json->{ $processing_key } = $stage;
                
                debug_json( "after shift to ${processing_key}", $status_json );

                $query  = "UPDATE ${lims_db}.${submit_request_table_name} SET status_json='" . json_encode( $status_json ) . "' WHERE ${id_field} = ${ID}";
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
            
            # must be completed or failed
            if ( $failed ) {
                write_logl( "$self: AutoflowAnalysis ${id_field} $ID all processing complete, moving to history", 1 );
            } else {
                write_logl( "$self: AutoflowAnalysis ${id_field} $ID processing FAILED, moving to history", 1 );
            }

            $query  = "INSERT ${lims_db}.${submit_request_history_table_name} SELECT * FROM ${lims_db}.${submit_request_table_name} WHERE ${id_field} = ${ID}";
            $result = mysqli_query( $db_handle, $query );

            if ( !$result ) {
                write_logl( "$self: error copying ${id_field} ${ID} from ${submit_request_table_name} to ${submit_request_history_table_name}.", 0 );
            } else {
                write_logl( "$self: success copying ${id_field} ${ID} from ${submit_request_table_name} to ${submit_request_history_table_name}.", 2 );
                # $result->free_result();
            }        
            
            $query  = "DELETE FROM ${lims_db}.${submit_request_table_name} WHERE ${id_field} = ${ID}";
            $result = mysqli_query( $db_handle, $query );
            
            if ( !$result ) {
                write_logl( "$self: error deleting ${id_field} ${ID} from ${submit_request_table_name}.", 0 );
            } else {
                write_logl( "$self: success deleting ${id_field} ${ID} from ${submit_request_table_name}.", 2 );
                # $result->free_result();
            }
            $work_done = 1;
        }
    }
    if ( !$work_done ) {
        write_logl( "$self: no requests to process sleeping ${poll_sleep_seconds}s", 2 );
        sleep( $poll_sleep_seconds );
    }
}
