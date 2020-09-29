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
# 3 : debug messages
# 4 : way too many debug messages
$logging_level      = 2;

# www_uslims3, www_common should likely be in listen_config.php
$www_uslims3   = "/srv/www/htdocs/uslims3";
$www_common    = "/srv/www/htdocs/common";

# ********* end user defines ***************

# ********* start admin defines *************
# these should only be changed by developers
$db                                = "gfac";
$submit_request_table_name         = "autoflowAnalysis";
$submit_request_history_table_name = "autoflowAnalysisHistory";
$id_field                          = "requestID";
$processing_key                    = "submitted";
$failed_status                     = [ "failed" => 1, "error" => 1, "canceled" => 1 ];
$completed_status                  = [ "complete" => 1, "done" => 1 ];
$wait_status                       = [ "wait" => 1 ];
# ********* end admin defines ***************


# add locking
if ( isset( $lock_dir ) ) {
    $lock_main_script_name  = __FILE__;
    require "$us3bin/lock.php";
}

function error_add( $msg ) {
    global $startup_errors;
    $startup_errors = true;
    write_logls( "ERROR : ${msg}." );
}

function exit_if_errors() {
    global $startup_errors;
    if ( $startup_errors ) {
        write_logls( "ERRORs present, quitting." );
        exit(-1);
    }
}

function write_logls( $msg, $this_level = 0 ) {
    global $self;
    write_logl( "$self: $msg", $this_level );
}

function write_logl( $msg, $this_level = 0 ) {
    global $logging_level;
    if ( $logging_level >= $this_level ) {
        write_log( $msg );
    }
}

function debug_json( $msg, $json ) {
    global $logging_level;
    if ( $logging_level < 3 ) {
        return;
    }
    echo "$msg\n";
    echo json_encode( $json, JSON_PRETTY_PRINT );
    echo "\n";
}

# Gary: should we have our own log? currently log is "udp.log" 

write_logls( "Starting" );

do {
    $db_handle = mysqli_connect( $dbhost, $user, $passwd, $db );
    if ( !$db_handle ) {
        write_logls( "could not connect to mysql: $dbhost, $user, $db. Will retry in ${poll_sleep_seconds}s" );
        sleep( $poll_sleep_seconds );
    }
} while ( !$db_handle );

# check for gfac.analysis.autoflowAnalysisID

$cfield = "autoflowAnalysisID";
$query  = "select ${cfield} from gfac.analysis limit 1";
$result = mysqli_query( $db_handle, $query );
if ( !$result ) {
    error_add( "${cfield} is not a field in gfac.analysis" );
}
unset( $cfield );

# check www php for autoflow
$check_file = "${www_common}/class/submit_local.php";
write_logls( "checking www ${check_file} for autoflow code", 4 );
if ( file_exists( $check_file ) ) {
    $qs1 = file_get_contents( $check_file );
    if ( !strpos( $qs1, '$autoflowID' ) ) {
        error_add( "file $check_file does not contain autoflow code" );
    }
    unset( $qs1 );
} else {
    error_add( "file $check_file does not exist" );
}
unset( $checkfile );

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
    write_logls( "checking db $v for autoflow tables", 4 );
    $success = true;

    # check db for ${submit_request_table_name}
    if ( $success ) {
        $query  = "select count(*) from ${v}.${submit_request_table_name}";
        $result = mysqli_query( $db_handle, $query );
        $error  = '';
        if ( !$result ) {
            $success = false;
            $error  = "table ${submit_request_table_name} can not be queried";
        }
    }

    # check db for ${submit_request_history_table_name}
    if ( $success ) {
        $query  = "select count(*) from ${v}.${submit_request_history_table_name}";
        $result = mysqli_query( $db_handle, $query );
        $error  = '';
        if ( !$result ) {
            $success = false;
            $error  = "table ${submit_request_history_table_name} can not be queried";
        }
    }

    # check db's www php for cli compatibility
    if ( $success ) {
        $php_base          = "${www_uslims3}/${v}";
        $check_files       = [ "${php_base}/queue_setup_1.php", "${php_base}/config.php" ];
        foreach ( $check_files as $check_file ) {
            write_logls( "checking www ${check_file} for cli submit compatibility", 4 );
            if ( file_exists( $check_file ) ) {
                $qs1 = file_get_contents( $check_file );
                if ( !$qs1 || !strpos( $qs1, '$is_cli' ) ) {
                    $success = false;
                    $error   = "file $check_file is not cli compliant";
                }
                unset( $qs1 );
            } else {
                $success = false;
                $error   = "file $check_file does not exist ";
            }
        }
    }

    if ( $success ) {
        write_logls( "added db $v for autoflow submission control", 1 );
        $limsdbs[] = $v;
    } else {
        write_logls( "ignoring db ${v}. reason: ${error}", 1 );
    }
}
unset( $trylimsdbs );

if ( !count( $limsdbs ) ) {
    error_add( "found no databases with ${submit_request_table_name} and cli complient www php" );
}

exit_if_errors();

while( 1 ) {
    write_logls( "checking mysql", 2 );

    $work_done = 0;

    foreach ( $limsdbs as $lims_db ) {
        write_logls( "checking mysql db ${lims_db}", 2 );
        
        # read from mysql - $submit_request_table_name
        $query        = "SELECT ${id_field}, clusterDefault, status, currentGfacID, currentHPCARID, statusMsg, nextWaitStatus, nextWaitStatusMsg, statusJson, createUser, updateTime, stageSubmitTime FROM ${lims_db}.${submit_request_table_name}";
        $outer_result = mysqli_query( $db_handle, $query );

        if ( !$outer_result || !$outer_result->num_rows ) {
            if ( $outer_result ) {
                # $outer_result->free_result();
            }
            continue;
        }

        write_logls( "found $outer_result->num_rows in ${submit_request_table_name} to check on db ${lims_db}", 2 );

        while ( $obj = mysqli_fetch_object( $outer_result ) ) {
            if ( !isset( $obj->{ $id_field } ) ) {
                write_logls( "critical: no id found in mysql result!" );
                continue;
            }
            $ID                 = $obj->{ $id_field };
            $status             = strtolower( $obj->{ 'status' } );
            $statusJson         = json_decode( $obj->{"statusJson"} );
            $nextWaitStatus     = strtolower( $obj->{ 'nextWaitStatus' } );
            $next_wait_clear    = strlen( $nextWaitStatus ) > 0;

            debug_json( "after fetch, decode", $statusJson );
            
            $failed    = array_key_exists( $status, $failed_status );
            $completed = array_key_exists( $status, $completed_status );
            $wait      = array_key_exists( $status, $wait_status );

            if ( $wait && $next_wait_clear ) {
                $nextWaitStatusMsg     = $obj->{ 'nextWaitStatusMsg' };
                $query  = "UPDATE ${lims_db}.${submit_request_table_name} SET status='${nextWaitStatus}', statusMsg='${nextWaitStatusMsg}', nextWaitStatus=NULL, nextWaitStatusMsg=NULL WHERE ${id_field} = ${ID}";
                $result = mysqli_query( $db_handle, $query );

                write_logls( "${submit_request_table_name} clearing WAIT ${id_field} $ID stage", 1 );
                if ( !$result ) {
                    write_logls( "error updating table ${submit_request_table_name} ${id_field} ${ID} query='$query'.", 0 );
                } else {
                    write_logls( "updating table ${submit_request_table_name} ${id_field} ${ID} nextWaitStatus", 2 );
                }
                $work_done = 1;
                continue;
            }

            if ( !$failed &&
                 !$completed &&
                 isset( $statusJson->{ $processing_key } ) &&
                 !empty( $statusJson->{ $processing_key } ) ) {
                write_logls( "autoflowAnalysis ${id_field} $ID is ${processing_key} status ${status} ", 2 );
                continue;
            }

            if ( $completed ) {
                # shift to processed
                $stage = $statusJson->{ $processing_key };
                unset( $statusJson->{ $processing_key } );
                $statusJson->{ "processed" }[] =
                    (object) [
                        $stage => [
                            "gfacID"                => $obj->{ 'currentGfacID'   },
                            "HPCAnalysisRequestID"  => $obj->{ 'currentHPCARID'  },
                            "status"                => $obj->{ 'status'          },
                            "statusMsg"             => $obj->{ 'statusMsg'       },
                            "updateTime"            => $obj->{ 'updateTime'      },
                            "createTime"            => $obj->{ 'stageSubmitTime' }
                        ]
                    ];
            }

            if ( !$failed &&
                 isset( $statusJson->{ "to_process" } ) &&
                 count( $statusJson->{ "to_process" } ) ) {
                $stage = array_shift( $statusJson->{ "to_process" } );
                $statusJson->{ $processing_key } = $stage;
                
                debug_json( "after shift to ${processing_key}", $statusJson );

                $query  = "UPDATE ${lims_db}.${submit_request_table_name} SET currentGfacID=NULL, currentHPCARID=NULL, stageSubmitTime=current_time(), status='READY', statusMsg='Job ready to submit', statusjson='" . json_encode( $statusJson ) . "' WHERE ${id_field} = ${ID}";
                $result = mysqli_query( $db_handle, $query );

                write_logls( "${submit_request_table_name} submitting ${id_field} $ID stage " . json_encode( $stage ), 1 );
                if ( !$result ) {
                    write_logls( "error updating table ${submit_request_table_name} ${id_field} ${ID} statusJson.", 0 );
                } else {
                    write_logls( "success updating table ${submit_request_table_name} ${id_field} ${ID} statusJson.", 2 );
                }
                # run each submit in a separate shell
                $cmd = "php submitone.php $lims_db $ID >> $home/etc/submit.log 2>&1 &";
                write_logls( "running cmd" );
                shell_exec( $cmd );
                $work_done = 1;
                continue;
            }

            if ( $completed ) {
                # update the db for after the final stage completes (if there were more stages, the prior if() would run instead)
                $query  = "UPDATE ${lims_db}.${submit_request_table_name} SET currentGfacID=NULL, currentHPCARID=NULL, status='FINISHED', statusMsg='Final stage completed', statusjson='" . json_encode( $statusJson ) . "' WHERE ${id_field} = ${ID}";
                $result = mysqli_query( $db_handle, $query );

                write_logls( "${submit_request_table_name} finishing ${id_field} $ID stage " . json_encode( $stage ), 1 );
                if ( !$result ) {
                    write_logls( "error updating table ${submit_request_table_name} ${id_field} ${ID} statusJson.", 0 );
                } else {
                    write_logls( "success updating table ${submit_request_table_name} ${id_field} ${ID} statusJson.", 2 );
                }
            }                
            
            # must be completed or failed
            if ( $failed ) {
                if ( $currentGfacID = $obj->{ 'currentGfacID' } ) {
                    # only works for slurm
                    $scancel = "scancel $currentGfacID >> $home/etc/submit.log 2>&1 &";
                    write_logls( "canceling gfac job with '$scancel'", 1 );
                    shell_exec( "scancel $currentGfacID >> $home/etc/submit.log 2>&1 &" );
                }
                write_logls( "${submit_request_table_name} ${id_field} $ID processing FAILED, moving to history", 1 );
            } else {
                write_logls( "${submit_request_table_name} ${id_field} $ID all processing complete, moving to history", 1 );
            }

            # delete previous version if exists (shouldn't happen, but might upon manual intervention such as recreating autoflowAnalysis without recreating autoflowAnalysisHistory)
            # possibly could be replaced with a REPLACE or ON DUPLICATE KEY UPDATE, haven't tested this
            $query  = "DELETE FROM ${lims_db}.${submit_request_history_table_name} WHERE ${id_field} = ${ID}";
            $result = mysqli_query( $db_handle, $query );
            # ignore result

            $query  = "INSERT ${lims_db}.${submit_request_history_table_name} SELECT * FROM ${lims_db}.${submit_request_table_name} WHERE ${id_field} = ${ID}";
            $result = mysqli_query( $db_handle, $query );

            if ( !$result ) {
                write_logls( "error copying ${id_field} ${ID} from ${submit_request_table_name} to ${submit_request_history_table_name}.", 0 );
            } else {
                write_logls( "success copying ${id_field} ${ID} from ${submit_request_table_name} to ${submit_request_history_table_name}.", 2 );
                # $result->free_result();
            }        
            
            $query  = "DELETE FROM ${lims_db}.${submit_request_table_name} WHERE ${id_field} = ${ID}";
            $result = mysqli_query( $db_handle, $query );
            
            if ( !$result ) {
                write_logls( "error deleting ${id_field} ${ID} from ${submit_request_table_name}.", 0 );
            } else {
                write_logls( "success deleting ${id_field} ${ID} from ${submit_request_table_name}.", 2 );
                # $result->free_result();
            }
            $work_done = 1;
        }
    }
    if ( !$work_done ) {
        write_logls( "no requests to process sleeping ${poll_sleep_seconds}s", 2 );
        sleep( $poll_sleep_seconds );
    }
}
