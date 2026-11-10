<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
$home   = dirname( $us3bin );
submitctl_log_errors_to( "$home/etc/submit.log" );
require_once __DIR__ . '/gridctl_bootstrap.php';
## This is a while(1) daemon exactly like listen.php/manage-us3-pipe.php
## below, whose own suggested remedy already backgrounds correctly -- this
## one didn't, so the suggested fix, copy-pasted as printed, ran the daemon
## tied to the operator's own shell instead of surviving it.
gridctl_require_not_root( __FILE__, 'start', null, true );
include_once $class_dir . "../global_config.php";   ## $cluster_details
include_once __DIR__ . "/cluster_probe.php";   ## ask a cluster about a job
require_once $class_dir . "dispatch_ledger.php";
require_once $class_dir . "dispatch_alerts.php";
require_once __DIR__ . "/dispatch_worker.php";

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

# www_uslims3, www_common — use values from listen-config.php if set, otherwise production defaults
$www_uslims3   = isset( $www_uslims3 ) ? $www_uslims3 : "/srv/www/htdocs/uslims3";
$www_common    = isset( $www_common  ) ? $www_common  : "/srv/www/htdocs/common";

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
    write_logls( "ERROR : {$msg}." );
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

if ( !function_exists( 'debug_json' ) ) {
    function debug_json( $msg, $json ) {
        global $logging_level;
        if ( $logging_level < 3 ) {
            return;
        }
        echo "$msg\n";
        echo json_encode( $json, JSON_PRETTY_PRINT );
        echo "\n";
    }
}

## Child processes (submitone.php, dispatchone.php) run concurrently up to
## $max_children; each is tracked until it exits.
function spawn_child( array $args, $log, $kind = 'dispatch' ) {
    global $children, $child_kinds;
    $cmd  = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' >> ' . escapeshellarg( $log ) . ' 2>&1';
    $proc = proc_open( $cmd, array( 0 => array( 'file', '/dev/null', 'r' ) ), $pipes );
    if ( ! is_resource( $proc ) ) {
        write_logls( "could not start $cmd", 0 );
        return false;
    }
    $children[] = $proc;
    end( $children );
    $child_kinds[ key( $children ) ] = $kind;
    write_logls( "running $cmd", 1 );
    return true;
}

## Reaps finished children; returns how many of $kind (dispatch attempts by default) still run.
function reap_children( $kind = 'dispatch' ) {
    global $children, $child_kinds;
    foreach ( $children as $i => $proc ) {
        $status = proc_get_status( $proc );
        if ( ! $status[ 'running' ] ) {
            proc_close( $proc );
            unset( $children[ $i ], $child_kinds[ $i ] );
        }
    }
    return count( array_keys( (array) $child_kinds, $kind, true ) );
}

## Seconds to sleep when a pass found nothing to do: 1 while work is expected any moment,
## until the next pool retry when it is due within the poll, else the full poll. A running
## child does not shorten it: idle_nap() ends when one exits.
function idle_nap_seconds( $poll, $autoflow_waiting, $autoflow_running, $next_due, $after_submission ) {
    if ( $autoflow_waiting || $after_submission ) {
        return 1;
    }
    return $next_due === null ? $poll : max( 1, min( $poll, (int) $next_due ) );
}

## The instances whose autoflow table a pass reads: all of them on the first pass, after autoflow work and once
## a poll has passed since they were last all read, as 4.2.0 reads them; otherwise those with a waiting stage.
function autoflow_read_dbs( array $limsdbs, $full_at, $poll, $now, $after_work, array $waiting ) {
    if ( $after_work || $now - $full_at >= $poll ) {
        return $limsdbs;
    }
    return array_values( array_intersect( $limsdbs, array_keys( $waiting ) ) );
}

## Seconds until every autoflow table is due to be read again.
function autoflow_poll_left( $full_at, $poll, $now ) {
    return max( 1, (int) ceil( $poll - ( $now - $full_at ) ) );
}

## With nothing to watch the nap is one sleep, as 4.2.0's is; otherwise it looks each second.
function idle_nap_step( $seconds, $watch_dirs, callable $tick = null, callable $running = null ) {
    return $tick === null && $running === null && ! array_filter( (array) $watch_dirs, 'strlen' ) ? max( 1, (int) $seconds ) : 1;
}

## services.php discards the daemon's stderr, where error_log() writes by default.
function submitctl_log_errors_to( $file ) {
    ini_set( 'error_log', $file );
}

## Sleep up to $seconds; returns true early when a directory in $watch_dirs changes (a new
## submission's directory), and 'child' when $running() drops (a child exited and freed a slot).
function idle_nap( $seconds, $watch_dirs, callable $tick = null, callable $running = null ) {
    ## mtime has 1 s resolution; a new subdirectory also changes the link count.
    $mark = function () use ( $watch_dirs ) {
        $out = array();
        foreach ( array_filter( (array) $watch_dirs, 'strlen' ) as $dir ) {
            clearstatcache( true, $dir );
            $st = @stat( $dir );
            $out[ $dir ] = $st ? array( $st[ 'mtime' ], $st[ 'nlink' ], $st[ 'size' ] ) : false;
        }
        return $out;
    };
    $seen = $mark();
    $was = $running !== null ? $running() : 0;
    $step = idle_nap_step( $seconds, $watch_dirs, $tick, $running );
    for ( $i = 0; $i < $seconds; $i += $step ) {
        if ( $tick !== null ) {
            $tick();
        }
        sleep( $step );
        if ( $seen && $mark() !== $seen ) {
            return true;
        }
        if ( $running !== null && $running() < $was ) {
            return 'child';
        }
    }
    return false;
}

function quote_fix( $str ) {
    return str_replace( "'", "*", $str );
}

function db_connect() {
    global $dbhost;
    global $user;
    global $passwd;
    global $db;
    global $db_handle;
    global $poll_sleep_seconds;

    do {
        $db_handle = mysqli_connect( $dbhost, $user, $passwd, $db );
        if ( !$db_handle ) {
            write_logls( "could not connect to mysql: $dbhost, $user, $db. Will retry in {$poll_sleep_seconds}s" );
            sleep( $poll_sleep_seconds );
        }
    } while ( !$db_handle );
}

pcntl_async_signals( true );

function sig_handler($sig) {
    switch($sig) {
        case SIGINT:
        write_logls( "Terminated by signal SIGINT" );
        break;
        case SIGHUP:
        write_logls( "Terminated by signal SIGHUP" );
        break;
        case SIGTERM:
        write_logls( "Terminated by signal SIGTERM" );
        break;
      default:
        write_logls( "Terminated by unknown signal" );
    }
    exit(-1);
}

pcntl_signal(SIGINT,  "sig_handler");
pcntl_signal(SIGTERM, "sig_handler");
pcntl_signal(SIGHUP,  "sig_handler");

write_logls( "Starting" );

db_connect();

$cfield = "autoflowAnalysisID";
$query  = "select {$cfield} from gfac.analysis limit 1";
$result = mysqli_query( $db_handle, $query );
if ( !$result ) {
    error_add( "{$cfield} is not a field in gfac.analysis" );
}
unset( $cfield );

$check_file = "{$www_common}/class/submit_slurm.php";
write_logls( "checking www {$check_file} for autoflow code", 4 );
if ( file_exists( $check_file ) ) {
    $qs1 = file_get_contents( $check_file );
    if ( !strpos( $qs1, '$autoflowID' ) ) {
        error_add( "file $check_file does not contain autoflow code" );
    }
    unset( $qs1 );
} else {
    error_add( "file $check_file does not exist" );
}

$trylimsdbs = [];
$query  = "SHOW DATABASES";
$result = mysqli_query( $db_handle, $query );
while ( $obj = mysqli_fetch_object( $result ) ) {
    $this_db = $obj->{ 'Database' };
    if ( substr( $this_db, 0, 8 ) === "uslims3_" ) {
        $trylimsdbs[] = $this_db;
    }
}

$limsdbs = [];

foreach ( $trylimsdbs as $v ) {
    write_logls( "checking db $v for autoflow tables", 4 );
    $success = true;

    if ( $success ) {
        $query  = "select count(*) from {$v}.{$submit_request_table_name}";
        $result = mysqli_query( $db_handle, $query );
        $error  = '';
        if ( !$result ) {
            $success = false;
            $error  = "table {$submit_request_table_name} can not be queried";
        }
    }

    if ( $success ) {
        $query  = "select count(*) from {$v}.{$submit_request_history_table_name}";
        $result = mysqli_query( $db_handle, $query );
        $error  = '';
        if ( !$result ) {
            $success = false;
            $error  = "table {$submit_request_history_table_name} can not be queried";
        }
    }

    if ( $success ) {
        $php_base    = "{$www_uslims3}/{$v}";
        $check_files = [ "{$php_base}/queue_setup_1.php", "{$php_base}/config.php" ];
        foreach ( $check_files as $check_file ) {
            write_logls( "checking www {$check_file} for cli submit compatibility", 4 );
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
        write_logls( "ignoring db {$v}. reason: {$error}", 1 );
    }
}
unset( $trylimsdbs );

if ( !count( $limsdbs ) ) {
    error_add( "found no databases with {$submit_request_table_name} and cli complient www php" );
}

exit_if_errors();

$children     = array();
$child_kinds  = array();   ## child index => dispatch | autoflow
$max_children = isset( $global_dispatch_worker_children ) ? max( 1, (int) $global_dispatch_worker_children ) : 4;
$max_autoflow_children = isset( $global_autoflow_submit_children ) ? max( 1, (int) $global_autoflow_submit_children ) : 32;
## No active pool and no unfinished request: no dispatch work at all. Editing the pool list needs no restart.
$global_config_file = $class_dir . '../global_config.php';
$dispatch_on_marker = dispatch_worker_on_marker( $home );
$pool_state = dispatch_worker_pool_state( $db_handle, $global_config_file );
dispatch_worker_pool_report( $db_handle, 'gfac', $global_config_file, null, $pool_state );
$dispatch_dbs = $pool_state[ 'dbs' ];
$dispatch_dbs_read = time();
dispatch_worker_on_marker_set( $dispatch_on_marker, $pool_state[ 'on' ] );
## Each submission creates a directory here; a change ends the idle sleep.
$submit_watch_dirs = function ( array $dbs ) use ( &$db_handle ) {
    global $submit_dir;
    return array_values( array_unique( array_merge( isset( $submit_dir ) ? array( rtrim( (string) $submit_dir, '/' ) ) : array(),
                                                    dispatch_worker_submit_dirs( $db_handle, $dbs ) ) ) );
};
$watch_dirs = $submit_watch_dirs( $dispatch_dbs );
$dispatch_owner = gethostname() . ':' . getmypid();
$org_name     = isset( $org_name ) ? $org_name : 'UltraScan';
$site_admin_send = dispatch_site_admin_sender(
    dispatch_site_admin_address( isset( $global_site_admin_email ) ? $global_site_admin_email : '',
                                 isset( $admin_email ) ? $admin_email : '' ), $org_name );
## Expired requests: one notice to the site admin per pass, and one to each submitter.
$dispatch_expired = function ( $db, array $ids ) use ( &$db_handle, $site_admin_send ) {
    ## The stage shows the expiry now: housekeeping mirrors only instances with unfinished requests.
    dispatch_mirror_autoflow( $db_handle, $db );
    $submitters = dispatch_request_submitters( $db_handle, $db, $ids );
    $admin_told = $site_admin_send( "UltraScan dispatch: " . count( $ids ) . " request(s) expired in $db",
        "These dispatch requests in $db were not submitted within the maximum wait and have expired:\n\n"
        . implode( "\n", array_map( function ( $id ) use ( $submitters ) {
              return "request $id" . ( isset( $submitters[ $id ] ) ? " (HPC request {$submitters[$id][1]}, {$submitters[$id][0]})" : '' );
          }, $ids ) ) . "\n\nThe dispatch dashboard lists the reasons they waited.\n" );
    foreach ( $submitters as $id => $who ) {
        if ( $who[ 0 ] !== '' ) {
            $site_admin_send( "US3 job submission expired",
                "Your analysis (HPC request {$who[1]}) in $db could not be submitted within the maximum wait"
                . " and was not run." . ( $admin_told ? " The system administrator has been notified." : '' ) . "\n", $who[ 0 ] );
        }
    }
};
$last_purge   = 0;
$uploads_kept = array();
$last_housekeeping = 0;
$submission_grace_until = 0;
## What each busy instance needs this pass, and the signatures its last mirror and alert scan saw.
$agenda = array();
$mirrored = array();
$scanned = array();
## When the autoflow tables were last all read, and what the next pass reads between polls.
$autoflow_full_at = 0.0;
$autoflow_after_work = false;
$autoflow_waiting_dbs = array();
write_logls( ( $pool_state[ 'on' ] ? '' : 'no active metascheduler pool or unfinished request; ' )
             . "dispatch instances: " . ( $dispatch_dbs ? implode( ', ', $dispatch_dbs ) : 'none' )
             . "; at most $max_children submissions at once", 1 );

while( 1 ) {
    write_logls( "checking mysql", 2 );

    $work_done = 0;
    $autoflow_waiting = false;

    ## An edited pool list takes effect here. An instance created after start, once it has the
    ## dispatch tables, is served from the next minute.
    $was_state = $pool_state;
    $pool_state = dispatch_worker_pool_state( $db_handle, $global_config_file, $pool_state );
    dispatch_worker_pool_report( $db_handle, 'gfac', $global_config_file, $was_state, $pool_state );
    if ( $pool_state !== $was_state ) {
        $dispatch_dbs = $pool_state[ 'dbs' ];
        $watch_dirs = $submit_watch_dirs( $dispatch_dbs );
        $dispatch_dbs_read = time();
        dispatch_worker_on_marker_set( $dispatch_on_marker, $pool_state[ 'on' ] );
        if ( $pool_state[ 'on' ] !== $was_state[ 'on' ] ) {
            write_logls( "dispatch worker " . ( $pool_state[ 'on' ] ? 'started' : 'stopped' ), 1 );
        }
    } elseif ( $pool_state[ 'on' ] && time() - $dispatch_dbs_read >= 60 ) {
        ## A failed list keeps the instances it has.
        $listed = dispatch_worker_instances( $db_handle );
        if ( $listed !== null ) {
            $dispatch_dbs = $listed;
            $watch_dirs = $submit_watch_dirs( $dispatch_dbs );
        }
        $dispatch_dbs_read = time();
    }

    ## Only instances with unfinished requests have dispatch work, and only what is due there: one statement for all.
    $agenda = $dispatch_dbs ? dispatch_worker_agenda( $db_handle, $dispatch_dbs, $limsdbs ) : array();
    $busy_dbs = array_keys( $agenda );
    $settled = dispatch_worker_pool_state_settled( $pool_state, $busy_dbs );
    if ( $settled[ 'on' ] !== $pool_state[ 'on' ] ) {
        write_logls( "dispatch worker stopped: no active pool and no unfinished request", 1 );
        ## Its last chance to close items no request is held for any more.
        dispatch_alert_scan( $db_handle, 'gfac', $dispatch_dbs, $busy_dbs );
        dispatch_worker_on_marker_set( $dispatch_on_marker, false );
        $dispatch_dbs = $settled[ 'dbs' ];
        $watch_dirs = $submit_watch_dirs( $dispatch_dbs );
    }
    $pool_state = $settled;

    ## Once a minute: liveness for the independent heartbeat check (stale after 300 s), then the
    ## ledger's housekeeping: finished jobs close their requests, autoflow stages show their
    ## dispatch state, long waits are flagged and operator alert items are kept current.
    if ( $pool_state[ 'on' ] && time() - $last_housekeeping >= 60 ) {
        dispatch_heartbeat_beat( $db_handle, 'gfac', $dispatch_owner );
        dispatch_alert_resolve( $db_handle, 'gfac', 'worker_heartbeat', 'the dispatch worker is running' );
        ## A request that has not changed since the last minute, such as one held for an operator, costs nothing here.
        $mirrored = array_intersect_key( $mirrored, $agenda );
        $sigs = array();
        foreach ( $agenda as $dispatch_db => $row ) {
            $need = dispatch_worker_housekeeping( $row, isset( $mirrored[ $dispatch_db ] ) ? $mirrored[ $dispatch_db ] : null );
            if ( $need[ 'close' ] ) {
                dispatch_close_finished( $db_handle, $dispatch_db );
            }
            $marked = $need[ 'notify' ] ? dispatch_mark_waiting_notified( $db_handle, $dispatch_db ) : 0;
            ## Taken as done only when it worked: a failed mirror is tried again next minute.
            $ok = $need[ 'mirror' ] || $marked ? dispatch_mirror_autoflow( $db_handle, $dispatch_db ) !== null : true;
            $mirrored[ $dispatch_db ] = $row !== null && ! $marked && $ok ? $row[ 'sig' ] : null;
            if ( $row !== null ) {
                $sigs[ $dispatch_db ] = $row[ 'sig' ];
            }
        }
        ## Mail first, so an item raised since the last scan is told before the scan can close it.
        dispatch_alert_email_pending( $db_handle, 'gfac', ! empty( $global_dispatch_alert_email ), $site_admin_send );
        $scanned = array_intersect_key( $scanned, $agenda );
        ## Requests the database fix released are due now; this pass looks at everything in their instances.
        foreach ( dispatch_alert_scan( $db_handle, 'gfac', $dispatch_dbs, $busy_dbs, $sigs, $scanned ) as $released_db ) {
            $agenda[ $released_db ] = null;
        }
        $last_housekeeping = time();
    }

    $claimed = dispatch_worker_pass( $db_handle, $busy_dbs,
        array( 'capacity' => $max_children - reap_children(), 'owner' => $dispatch_owner,
               'spool' => dispatch_spool_dir(), 'expired' => $dispatch_expired, 'agenda' => $agenda ),
        function ( $lims_db, $id, $generation ) use ( $home, $dispatch_owner ) {
            spawn_child( array( 'php', "{$home}/bin/dispatchone.php", $lims_db, $id, $generation, $dispatch_owner ),
                         "{$home}/etc/submit.log" );
        } );
    if ( $claimed ) {
        write_logls( "dispatch claimed " . implode( ', ', $claimed ), 1 );
        $work_done = 1;
    }

    ## Hourly: terminal dispatch history ages out on the configured schedule,
    ## interrupted intakes are failed and their unrecorded uploads removed.
    if ( $pool_state[ 'on' ] && time() - $last_purge >= 3600 ) {
        $retention = dispatch_retention_settings();
        foreach ( $dispatch_dbs as $dispatch_db ) {
            dispatch_purge_terminal( $db_handle, $dispatch_db, $retention[ 'requests' ] );
        }
        dispatch_purge_decisions( $db_handle, 'gfac', $retention[ 'decisions' ] );
        dispatch_heartbeat_purge( $db_handle, 'gfac' );
        dispatch_purge_legacy_decisions( $db_handle, 'gfac', $retention[ 'legacy_decisions' ] );
        foreach ( $dispatch_dbs as $dispatch_db ) {
            dispatch_abandon_unsealed( $db_handle, $dispatch_db, 3600 );
        }
        foreach ( dispatch_orphan_upload_dirs( $db_handle, $dispatch_dbs, 86400 ) as $orphan ) {
            dispatch_worker_remove_orphan( $orphan, function ( $line ) { write_logls( $line, 1 ); }, $uploads_kept );
        }
        $last_purge = time();
    }

    ## Every autoflow table each poll and after autoflow work, as 4.2.0 reads them; a pass started by
    ## dispatch work reads only the instances with a stage waiting for a submission slot.
    $autoflow_dbs = autoflow_read_dbs( $limsdbs, $autoflow_full_at, $poll_sleep_seconds, microtime( true ),
                                       $autoflow_after_work, $autoflow_waiting_dbs );
    $autoflow_full = $autoflow_dbs === $limsdbs;
    $autoflow_after_work = false;
    $autoflow_waiting_dbs = array();
    foreach ( $autoflow_dbs as $lims_db ) {
        write_logls( "checking mysql db {$lims_db}", 2 );

        $query        = "SELECT {$id_field}, clusterDefault, status, currentGfacID, currentHPCARID, statusMsg, nextWaitStatus, nextWaitStatusMsg, statusJson, createUser, updateTime, stageSubmitTime FROM {$lims_db}.{$submit_request_table_name}";

        $outer_result = mysqli_query( $db_handle, $query );
        if ( mysqli_error( $db_handle ) != "" ) {
            write_logls( "read from mysql query='$query'. " . mysqli_error( $db_handle ) );
            if ( false !== strpos( mysqli_error( $db_handle ) , "MySQL server has gone away" ) ) {
                write_logls( "trying to reconnect..." );
                db_connect();
                write_logls( "connected..." );
                continue;
            }
        }

        if ( !$outer_result || !$outer_result->num_rows ) {
            continue;
        }

        write_logls( "found {$outer_result->num_rows} in {$submit_request_table_name} to check on db {$lims_db}", 2 );

        while ( $obj = mysqli_fetch_object( $outer_result ) ) {
            if ( !isset( $obj->{ $id_field } ) ) {
                write_logls( "critical: no id found in mysql result!" );
                continue;
            }
            $ID              = $obj->{ $id_field };
            $status          = strtolower( (string)( $obj->{ 'status' } ?? '' ) );
            $statusJson      = json_decode( $obj->{"statusJson"} );
            $nextWaitStatus  = strtolower( (string)( $obj->{ 'nextWaitStatus' } ?? '' ) );
            $next_wait_clear = strlen( $nextWaitStatus ) > 0;

            debug_json( "after fetch, decode", $statusJson );

            $failed    = array_key_exists( $status, $failed_status );
            $completed = array_key_exists( $status, $completed_status );
            $wait      = array_key_exists( $status, $wait_status );

            if ( $wait && $next_wait_clear ) {
                $nextWaitStatusMsg = quote_fix( $obj->{ 'nextWaitStatusMsg' } );

                $query  = "UPDATE {$lims_db}.{$submit_request_table_name} SET status='{$nextWaitStatus}', statusMsg='{$nextWaitStatusMsg}', nextWaitStatus=NULL, nextWaitStatusMsg=NULL WHERE {$id_field} = {$ID}";
                $result = mysqli_query( $db_handle, $query );

                write_logls( "{$submit_request_table_name} clearing WAIT {$id_field} $ID stage", 1 );
                if ( !$result ) {
                    write_logls( "error updating table {$submit_request_table_name} {$id_field} {$ID} query='$query'. " . mysqli_error( $db_handle ), 0 );
                } else {
                    write_logls( "updating table {$submit_request_table_name} {$id_field} {$ID} nextWaitStatus", 2 );
                }
                $work_done = 1;
                $autoflow_after_work = true;
                continue;
            }

            if ( !$failed &&
                 !$completed &&
                 isset( $statusJson->{ $processing_key } ) &&
                 !empty( $statusJson->{ $processing_key } ) ) {
                write_logls( "autoflowAnalysis {$id_field} $ID is {$processing_key} status {$status} ", 2 );
                continue;
            }

            if ( $completed || $failed ) {
                $stage = $statusJson->{ $processing_key };
                unset( $statusJson->{ $processing_key } );
                $statusJson->{ "processed" }[] =
                    (object) [
                        $stage => [
                            "gfacID"               => $obj->{ 'currentGfacID'   },
                            "HPCAnalysisRequestID" => $obj->{ 'currentHPCARID'  },
                            "status"               => $obj->{ 'status'          },
                            "statusMsg"            => quote_fix( $obj->{ 'statusMsg' } ),
                            "updateTime"           => $obj->{ 'updateTime'      },
                            "createTime"           => $obj->{ 'stageSubmitTime' }
                        ]
                    ];
            }

            if ( !$failed &&
                 isset( $statusJson->{ "to_process" } ) &&
                 count( $statusJson->{ "to_process" } ) ) {
                if ( reap_children( 'autoflow' ) >= $max_autoflow_children ) {
                    write_logls( "{$submit_request_table_name} {$id_field} $ID waits: $max_autoflow_children autoflow submissions running", 2 );
                    $autoflow_waiting = true;
                    $autoflow_waiting_dbs[ $lims_db ] = true;
                    continue;
                }
                $stage = array_shift( $statusJson->{ "to_process" } );
                $statusJson->{ $processing_key } = $stage;

                debug_json( "after shift to {$processing_key}", $statusJson );

                $query  = "UPDATE {$lims_db}.{$submit_request_table_name} SET currentGfacID=NULL, currentHPCARID=NULL, stageSubmitTime=current_time(), status='READY', statusMsg='Job ready to submit', statusjson='" . json_encode( $statusJson ) . "' WHERE {$id_field} = {$ID}";
                $result = mysqli_query( $db_handle, $query );

                write_logls( "{$submit_request_table_name} submitting {$id_field} $ID stage " . json_encode( $stage ), 1 );
                if ( !$result ) {
                    write_logls( "error updating table {$submit_request_table_name} {$id_field} {$ID} statusJson. $query='{$query}' " . mysqli_error( $db_handle ), 0 );
                } else {
                    write_logls( "success updating table {$submit_request_table_name} {$id_field} {$ID} statusJson.", 2 );
                }
                ## Quoted: $lims_db comes from the instance list and $ID from a
                ## table row, so neither is typed by a user here, but both reach a
                ## shell and a database name is operator-supplied upstream.
                ## Bounded apart from dispatch attempts, and high enough that ready stages start at once as in 4.2.0.
                spawn_child( array( 'php', "{$home}/bin/submitone.php", $lims_db, $ID ), "{$home}/etc/submit.log", 'autoflow' );
                $work_done = 1;
                $autoflow_after_work = true;
                continue;
            }

            if ( $completed ) {
                $query  = "UPDATE {$lims_db}.{$submit_request_table_name} SET currentGfacID=NULL, currentHPCARID=NULL, status='FINISHED', statusMsg='Final stage completed', statusjson='" . str_replace( "'", "\'", json_encode( $statusJson ) ) . "' WHERE {$id_field} = {$ID}";
                $result = mysqli_query( $db_handle, $query );

                write_logls( "{$submit_request_table_name} finishing {$id_field} $ID stage " . json_encode( $stage ), 1 );
                if ( !$result ) {
                    write_logls( "error updating table {$submit_request_table_name} {$id_field} {$ID} statusJson query='{$query}'. " . mysqli_error( $db_handle ), 0 );
                } else {
                    write_logls( "success updating table {$submit_request_table_name} {$id_field} {$ID} statusJson.", 2 );
                }
            }

            if ( $failed ) {
                if ( $currentGfacID = $obj->{ 'currentGfacID' } ) {
                    cancel_stage_job( $currentGfacID, $obj->{ 'clusterDefault' } ?? '', $lims_db,
                                      (int) ( $obj->{ 'currentHPCARID' } ?? 0 ) );
                }
                $query  = "UPDATE {$lims_db}.{$submit_request_table_name} SET currentGfacID=NULL, currentHPCARID=NULL, statusjson='" . json_encode( $statusJson ) . "' WHERE {$id_field} = {$ID}";
                $result = mysqli_query( $db_handle, $query );

                write_logls( "{$submit_request_table_name} failing {$id_field} $ID stage " . json_encode( $stage ), 1 );
                if ( !$result ) {
                    write_logls( "error updating table {$submit_request_table_name} {$id_field} {$ID} statusJson query='{$query}'. " . mysqli_error( $db_handle ), 0 );
                } else {
                    write_logls( "success updating table {$submit_request_table_name} {$id_field} {$ID} statusJson.", 2 );
                }
                write_logls( "{$submit_request_table_name} {$id_field} $ID processing FAILED, moving to history", 1 );
            } else {
                write_logls( "{$submit_request_table_name} {$id_field} $ID all processing complete, moving to history", 1 );
            }

            $query  = "DELETE FROM {$lims_db}.{$submit_request_history_table_name} WHERE {$id_field} = {$ID}";
            $result = mysqli_query( $db_handle, $query );

            $query  = "INSERT {$lims_db}.{$submit_request_history_table_name} SELECT * FROM {$lims_db}.{$submit_request_table_name} WHERE {$id_field} = {$ID}";
            $result = mysqli_query( $db_handle, $query );

            if ( !$result ) {
                write_logls( "error copying {$id_field} {$ID} from {$submit_request_table_name} to {$submit_request_history_table_name}. $query='{$query}'. " . mysqli_error( $db_handle ), 0 );
            } else {
                write_logls( "success copying {$id_field} {$ID} from {$submit_request_table_name} to {$submit_request_history_table_name}.", 2 );
            }

            $query  = "DELETE FROM {$lims_db}.{$submit_request_table_name} WHERE {$id_field} = {$ID}";
            $result = mysqli_query( $db_handle, $query );

            if ( !$result ) {
                write_logls( "error deleting {$id_field} {$ID} from {$submit_request_table_name}. $query='{$query}'. " . mysqli_error( $db_handle ), 0 );
            } else {
                write_logls( "success deleting {$id_field} {$ID} from {$submit_request_table_name}.", 2 );
            }
            $work_done = 1;
            $autoflow_after_work = true;
        }
    }
    if ( $autoflow_full ) {
        $autoflow_full_at = microtime( true );
    }
    if ( !$work_done ) {
        ## A pool stage is taken about as fast as a direct one: the nap ends when a request is due,
        ## and a new submission directory starts a short run of 1 s passes for its intake.
        ## Only on a host with dispatch instances, and a due request only counts while a child can take it.
        $pools = (bool) $dispatch_dbs;
        $nap = min( autoflow_poll_left( $autoflow_full_at, $poll_sleep_seconds, microtime( true ) ),
                    idle_nap_seconds( $poll_sleep_seconds, $autoflow_waiting, 0,
                                      $busy_dbs && reap_children() < $max_children ? dispatch_worker_agenda_due( $db_handle, $agenda ) : null,
                                      $pools && time() < $submission_grace_until ) );
        write_logls( "no requests to process sleeping {$nap}s", 2 );
        ## A new submission directory: a few 1 s passes until its intake commits.
        if ( idle_nap( $nap, $pools ? $watch_dirs : array(), null,
                       $pools ? function () { return reap_children(); } : null ) === true ) {
            $submission_grace_until = time() + 3;
        }
    }
}


/**
 * Cancel the job a failed stage left behind, and report whether it landed.
 * Short and unretried: this runs inside the loop over every pending request.
 */
function cancel_stage_job( $gfacID, $cluster_default, $lims_db, $hpcarid ) {
    global $db_handle;

    ## The executing cluster wins over the requested one: a metascheduler
    ## submission can land somewhere other than where it was aimed.
    $cluster = $cluster_default;

    ## Two clusters can reuse a job number: also match this stage's instance
    ## and, once the 4.3.0 upgrade has added the column, its request.
    try {
        $stmt = mysqli_prepare( $db_handle,
            "SELECT cluster, metaschedulerClusterExecuting FROM gfac.analysis WHERE gfacID = ? AND us3_db = ?"
            . " AND HPCAnalysisRequestID = ?" );
    } catch ( mysqli_sql_exception $e ) {
        $stmt = false;
    }
    if ( ! $stmt ) {
        $stmt = mysqli_prepare( $db_handle,
            "SELECT cluster, metaschedulerClusterExecuting FROM gfac.analysis WHERE gfacID = ? AND us3_db = ?" );
        $hpcarid = null;
    }

    if ( $stmt ) {
        if ( $hpcarid === null ) {
            mysqli_stmt_bind_param( $stmt, 'ss', $gfacID, $lims_db );
        } else {
            mysqli_stmt_bind_param( $stmt, 'ssi', $gfacID, $lims_db, $hpcarid );
        }
        mysqli_stmt_execute( $stmt );
        $row = mysqli_fetch_assoc( mysqli_stmt_get_result( $stmt ) );
        mysqli_stmt_close( $stmt );

        if ( $row ) {
            $cluster = empty( $row[ 'metaschedulerClusterExecuting' ] )
                     ? $row[ 'cluster' ] : $row[ 'metaschedulerClusterExecuting' ];
        } elseif ( $hpcarid !== null ) {
            ## No row carries this request: the number may belong to another job now.
            write_logls( "cannot cancel {$gfacID}: no gfac.analysis row carries request {$hpcarid}", 0 );
            return;
        }
    }

    ## GUI requests name the LIMS host's own cluster 'localhost'.
    if ( $cluster === 'localhost' ) {
        global $default_local_cluster;
        $cluster = $default_local_cluster ?? '';
    }

    global $cluster_details;
    ## Two different problems: nothing was recorded for the job, or what was
    ## recorded is not a cluster this host knows. "cluster ''" reads like a bug.
    if ( $cluster === '' ) {
        write_logls( "cannot cancel {$gfacID}: no cluster recorded for it", 0 );
        return;
    }
    if ( ! isset( $cluster_details[ $cluster ] ) ) {
        write_logls( "cannot cancel {$gfacID}: cluster '{$cluster}' is not configured", 0 );
        return;
    }

    write_logls( "canceling gfac job {$gfacID} on {$cluster}", 1 );

    $ok = cluster_probe_cancel_job( $cluster, $gfacID,
        function ( $m ) { write_logls( $m, 1 ); },
        array( 'retries' => 0, 'timeout' => 15 ) );

    if ( ! $ok ) {
        ## The only trace that a job may still be running on the cluster.
        write_logls( "cancel of {$gfacID} on {$cluster} was not confirmed;"
                     . " the job may still be running", 0 );
    }
}
