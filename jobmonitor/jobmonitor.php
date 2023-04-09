<?php

$us3lims = exec( "ls -d ~us3/lims" );
$us3bin  = "$us3lims/bin";
$us3util = "$us3lims/database/utils";
$us3jm   = "$us3lims/bin/jobmonitor";

include "$us3bin/listen-config.php";
include $class_dir_p . "experiment_status.php";
include $class_dir_p . "experiment_errors.php";
include $class_dir_p . "experiment_cancel.php";
include $class_dir_p . "experiment_resource.php";
include $class_dir_p . "job_details.php";
include $class_dir_p . "../global_config.php";

include "$us3jm/gridctl.php";
include "$us3jm/cleanup.php";
include "$us3jm/cleanup_gfac.php";

include "$us3util/utility.php";

# ********* start user defines *************
## some could be pushed to listen-config.php

# the polling interval
$poll_sleep_seconds = 165 + random_int( 10, 30 );
$poll_sleep_seconds = 30;

# logging_level 
# 0 : minimal messages (expected value for production)
# 1 : add some db messages
# 2 : add idle polling messages
# 3 : debug messages
# 4 : way too many debug messages
$logging_level      = 2;

# directory for logs & locks
$ll_base_dir  = "$us3lims/etc/joblog";

# ********* end user defines ***************

# process arguments or die

$notes = "usage: $self dbname gfacID HPCAnalysisRequestID
";

$u_argv = $argv;
array_shift( $u_argv ); # first element is program name

if ( count( $u_argv ) != 3 ) {
    error_exit( $notes );
}

$us3_db = array_shift( $u_argv );
$gfacID = array_shift( $u_argv );
$hpcrid = array_shift( $u_argv );

if ( !preg_match( '/^uslims3_[A-Za-z0-9_]*$/', $us3_db ) ) {
    $errors .= "dbname has an invalid format\n";
}

if ( !preg_match( '/^[A-Za-z0-9-_]*$/', $gfacID ) ) {
    $errors .= "gfac has an invalid format\n";
}

if ( !filter_var( $hpcrid, FILTER_VALIDATE_INT ) ) {
    $errors .= "HPCAnalysisRequestID has an invalid format\n";
}

flush_errors_exit();

$lock_dir = "$ll_base_dir/$us3_db/$gfacID";

if ( !is_dir( $lock_dir ) ) {
    mkdir( $lock_dir, 0770, true );
}

$lock_main_script_name  = __FILE__;
echo "lock main script $lock_main_script_name\n";

$logfile = "$lock_dir/log.txt";

## set nofork to 1 to test without daemonizing
$nofork = 0;

if ( !$nofork ) {

    # 1st daemonize

    if ($pid = pcntl_fork()) {
        return;     ## Parent
    }

    ## ob_end_clean(); ## Discard the output buffer and close

    fclose(STDIN);  ## Close all of the standard
    fclose(STDOUT); ## file descriptors as we
    fclose(STDERR); ## are running as a daemon.

    ## need to force the forked parent to die
    function shutdown() {
        global $cancel_shutdown_kill;
        if ( !isset( $cancel_shutdown_kill ) ) {
            posix_kill(posix_getpid(), SIGHUP);
            sleep(1);
            posix_kill(posix_getpid(), SIGTERM);
        }
    }

    register_shutdown_function('shutdown');

    if (posix_setsid() < 0) {
        return;
    }

    if ($pid = pcntl_fork()) {
        return;     ## Parent 
    }

    $cancel_shutdown_kill = true;

    $STDOUT = fopen( $logfile, 'ab' );
    $STDERR = fopen( $logfile, 'ab' );
}

require "$us3bin/lock.php";

# signal handler
# for PHP < 7.2
# declare(ticks = 1);
pcntl_async_signals( true );

function sig_handler($sig) {
    switch($sig) {
        case SIGINT:
        write_logld( "Terminated by signal SIGINT" );
        break;
        case SIGHUP:
        write_logld( "Terminated by signal SIGHUP" );
        break;
        case SIGTERM:
        write_logld( "Terminated by signal SIGTERM" );
        break;
      default:
        write_logld( "Terminated by unknown signal" );
    }
    exit(-1);
}

pcntl_signal(SIGINT,  "sig_handler");
pcntl_signal(SIGTERM, "sig_handler");
pcntl_signal(SIGHUP,  "sig_handler");

write_logld( "monitoring db $us3_db gfac $gfacID HPCReqID $hpcrid" );

# open db
open_db();

write_logld( "db opened" );

# gfac?

$work_done = false;
$max_loop  = 0; ## set to non zero for testing
$loop      = 0;

if (
    false ===
    ( $res_analysis =
      db_obj_result( $db_handle
                     ,"select"
                     . " cluster"
                     . " ,status"
                     . " ,queue_msg"
                     . " ,UNIX_TIMESTAMP(time)"
                     . " ,time"
                     . " ,autoflowAnalysisID"
                     . " from gfac.analysis"
                     . " where gfacID = \"$gfacID\""
                     ,false
                     ,true
      ) ) ) {
    mysqli_close( $db_handle );
    error_exit( timestamp( "gfacID $gfacID not found in gfac.analysis" ) );
}

$cluster            = $res_analysis->{"cluster"};
$status             = $res_analysis->{"status"};
$queue_msg          = $res_analysis->{"queue_msg"};
$time               = $res_analysis->{"UNIX_TIMESTAMP(time)"};
$updateTime         = $res_analysis->{"time"};
$autoflowAnalysisID = $res_analysis->{"autoflowAnalysisID"};

$type_id_obj        = get_autoflow_type_id();
$autoflowType       = is_object( $type_id_obj ) && isset( $type_id_obj->type ) ? $type_id_obj->type : "unknown";
$autoflowID         = is_object( $type_id_obj ) && isset( $type_id_obj->autoflowID ) ? $type_id_obj->autoflowID : 0;

write_logld( "autoflowType $autoflowType autoflowID $autoflowID" );

## debugging
## 
## debug_json( timestamp("analysis"), $res_analysis );
## update_autoflow_models(1,2,"00000000-0000-0000-0000-000000000000");
## update_autoflow_models(3,4,"a71cda5a-a8cb-41a5-9858-10c9296a3e6e");
## exit(-1);

while( 1 ) {
    write_logld( "jobmonitor.php: main loop" );
    open_db();
    while ( !mysqli_ping( $db_handle ) ) {
        write_logld( "mysql server has gone away" );
        sleep( $poll_sleep_seconds / 2 );
        write_logld( "attempting to reconnect" );
        open_db();
        if ( mysqli_ping( $db_handle ) ) {
            write_logld( "reconnected - success" );
        }            
    }
    
    if (
        false ===
        ( $res_analysis =
          db_obj_result( $db_handle
                         ,"select"
                         . " status"
                         . " ,queue_msg"
                         . " ,metaschedulerClusterExecuting"
                         . " ,UNIX_TIMESTAMP(time)"
                         . " ,time"
                         . " from gfac.analysis"
                         . " where gfacID = \"$gfacID\""
                         ,false
                         ,true
          ) ) ) {
        mysqli_close( $db_handle );
        error_exit( timestamp( "gfacID $gfacID not found in gfac.analysis" ) );
    }

    $status                         = $res_analysis->{"status"};
    $queue_msg                      = $res_analysis->{"queue_msg"};
    $time                           = $res_analysis->{"UNIX_TIMESTAMP(time)"};
    $updateTime                     = $res_analysis->{"time"};
    $metascheduler_cluser_executing = $res_analysis->{"metaschedulerClusterExecuting"};

    if ( check_job() ) {
        write_logld( "jobmonitor.php exiting" );
        mysqli_close( $db_handle );
        exit(0);
    }

    mysqli_close( $db_handle );
    sleep( $poll_sleep_seconds );
}

mysqli_close( $db_handle );
error_exit( timestamp( "dropped out of main loop, this should not happen" ) );
