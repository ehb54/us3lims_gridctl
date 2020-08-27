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
$logging_level      = 2;

# ********* end user defines ***************

# ********* start admin defines *************
# these should only be changed by developers
$db                                = "gfac";
$submit_request_table_name         = "autoflowAnalysis";
$submit_request_history_table_name = "autoflowAnalysisHistory";
$id_field                          = "requestID";
$processing_key                    = "submitted";
$failed_status                     = [ "failed" => 1, "error" => 1 ];
$completed_status                  = [ "complete" => 1 ];
$wait_status                       = [ "wait" => 1 ];
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
    $query  = "select count(*) from ${v}.${submit_request_table_name}";
    $result = mysqli_query( $db_handle, $query );
    if ( $result ) {
        $query  = "select count(*) from ${v}.${submit_request_history_table_name}";
        $result = mysqli_query( $db_handle, $query );
        if ( $result ) {
            $success = true;
            $limsdbs[] = $v;
        }
    }

    if ( $success ) {
        write_logl( "$self: added db $v for autoflow submission control", 1 );
    } else {
        write_logl( "$self: db $v does not have ${submit_request_history_table_name} tables, ignoring", 1 );
    }
}
unset( $trylimsdbs );

if ( !count( $limsdbs ) ) {
    write_logl( "$self: found no databases with ${submit_request_history_table_name}, quitting", 0 );
    exit;
}
    
while( 1 ) {
    write_logl( "$self: checking mysql", 2 );

    $work_done = 0;

    foreach ( $limsdbs as $lims_db ) {
        write_logl( "$self: checking mysql db ${lims_db}", 2 );
        
        # read from mysql - $submit_request_table_name
        $query        = "SELECT ${id_field}, clusterDefault, status, statusJson, createUser FROM ${lims_db}.${submit_request_table_name}";
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
            $ID         = $obj->{ $id_field };
            $status     = strtolower( $obj->{ 'status' } );
            $statusJson = json_decode( $obj->{"statusJson"} );

            debug_json( "after fetch, decode", $statusJson );
            
            $failed    = array_key_exists( $status, $failed_status );
            $completed = array_key_exists( $status, $completed_status );

            // not used:
            // $wait      = array_key_exists( $status, $wait_status );

            if ( !$failed &&
                 !$completed &&
                 isset( $statusJson->{ $processing_key } ) &&
                 !empty( $statusJson->{ $processing_key } ) ) {
                write_logl( "$self: autoflowAnalysis ${id_field} $ID is ${processing_key} status ${status} ", 2 );
                continue;
            }

            if ( $completed ) {
                # shift to processed
                $stage = $statusJson->{ $processing_key };
                unset( $statusJson->{ $processing_key } );
                $statusJson->{ "processed" }[] = $stage;
            }

            if ( !$failed &&
                 isset( $statusJson->{ "to_process" } ) &&
                 count( $statusJson->{ "to_process" } ) ) {
                $stage = array_shift( $statusJson->{ "to_process" } );
                $statusJson->{ $processing_key } = $stage;
                
                debug_json( "after shift to ${processing_key}", $statusJson );

                $query  = "UPDATE ${lims_db}.${submit_request_table_name} SET status='READY', statusjson='" . json_encode( $statusJson ) . "' WHERE ${id_field} = ${ID}";
                $result = mysqli_query( $db_handle, $query );

                write_logl( "$self: ${submit_request_table_name} submitting ${id_field} $ID stage " . json_encode( $stage ), 1 );
                if ( !$result ) {
                    write_logl( "$self: error updating table ${submit_request_table_name} ${id_field} ${ID} statusJson.", 0 );
                } else {
                    write_logl( "$self: success updating table ${submit_request_table_name} ${id_field} ${ID} statusJson.", 2 );
                }
                # run each submit in a separate shell
                $cmd = "php submitone.php $lims_db $ID >> $home/etc/submit.log 2>&1 &";
                write_logl( "$self: running cmd" );
                shell_exec( $cmd );
                $work_done = 1;
                continue;
            }

            if ( $completed ) {
                # update the db for after the final stage completes (if there were more stages, the prior if() would run instead)
                $query  = "UPDATE ${lims_db}.${submit_request_table_name} SET status='FINISHED', statusjson='" . json_encode( $statusJson ) . "' WHERE ${id_field} = ${ID}";
                $result = mysqli_query( $db_handle, $query );

                write_logl( "$self: ${submit_request_table_name} finishing ${id_field} $ID stage " . json_encode( $stage ), 1 );
                if ( !$result ) {
                    write_logl( "$self: error updating table ${submit_request_table_name} ${id_field} ${ID} statusJson.", 0 );
                } else {
                    write_logl( "$self: success updating table ${submit_request_table_name} ${id_field} ${ID} statusJson.", 2 );
                }
            }                
            
            # must be completed or failed
            if ( $failed ) {
                write_logl( "$self: ${submit_request_table_name} ${id_field} $ID processing FAILED, moving to history", 1 );
            } else {
                write_logl( "$self: ${submit_request_table_name} ${id_field} $ID all processing complete, moving to history", 1 );
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
