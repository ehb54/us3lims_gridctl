<?php

$us3lims = exec( "ls -d ~us3/lims" );
$us3bin  = "$us3lims/bin";
$us3util = "$us3lims/database/utils";

include "$us3bin/listen-config.php";
include "$us3util/utility.php";

# ********* start user defines *************
## some could be pushed to listen-config.php

# the polling interval
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

$dbname = array_shift( $u_argv );
$gfacid = array_shift( $u_argv );
$hpcrid = array_shift( $u_argv );

if ( !preg_match( '/^uslims3_[A-Za-z0-9_]*$/', $dbname ) ) {
    $errors .= "dbname has an invalid format\n";
}

if ( !preg_match( '/^[A-Za-z0-9-]*$/', $gfacid ) ) {
    $errors .= "gfac has an invalid format\n";
}

if ( !filter_var( $hpcrid, FILTER_VALIDATE_INT ) ) {
    $errors .= "HPCAnalysisRequestID has an invalid format\n";
}

flush_errors_exit();

$lock_dir = "$ll_base_dir/$dbname/$gfacid";

if ( !is_dir( $lock_dir ) ) {
    mkdir( $lock_dir, 0770, true );
}

$lock_main_script_name  = __FILE__;
echo "lock main script $lock_main_script_name\n";

$logfile = "$lock_dir/log.txt";

# 1st daemonize

if ($pid = pcntl_fork()) {
    return;     // Parent
}

// ob_end_clean(); // Discard the output buffer and close

fclose(STDIN);  // Close all of the standard
fclose(STDOUT); // file descriptors as we
fclose(STDERR); // are running as a daemon.

## need to force the forket parent to die
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
    return;     // Parent 
}

$cancel_shutdown_kill = true;

$STDOUT = fopen( $logfile, 'ab' );
$STDERR = fopen( $logfile, 'ab' );

require "$us3bin/lock.php";

write_logl( "monitoring db $dbname gfac $gfacid HPCReqID $hpcrid" );

sleep( $poll_sleep_seconds );

error_exit( "exiting/test" );


