<?php
// Loads listen-config.php (values only), refuses a pre-upgrade config, then loads the shared helpers.
if ( !isset( $us3bin ) ) {
    $us3entry = function_exists( 'posix_getpwnam' ) ? posix_getpwnam( 'us3' ) : false;
    $us3bin   = ( $us3entry ? $us3entry[ 'dir' ] : '/home/us3' ) . '/lims/bin';
}

include_once "$us3bin/listen-config.php";

if ( ( $listen_config_version ?? 0 ) < 2 ) {
    $msg = "gridctl: $us3bin/listen-config.php is the pre-upgrade format; "
         . "run php ~us3/lims/database/utils/uslims_upgrade.php";
    if ( function_exists( 'syslog' ) ) {
        syslog( LOG_ERR, $msg );
    }
    fwrite( STDERR, "$msg\n" );
    exit( 1 );
}

// One form for every caller: $class_dir always ends in a slash
$class_dir = rtrim( $class_dir, '/' ) . '/';

// The "log and continue" paths expect failed queries to return false (PHP 8.1+ throws by default)
mysqli_report( MYSQLI_REPORT_OFF );

require_once __DIR__ . '/listen_functions.php';

if ( ! function_exists( 'gridctl_require_not_root' ) ) {
    ## Shared by every daemon entry point (services.php, listen.php,
    ## jobmonitor.php, manage-us3-pipe.php) -- round-12 should-fix, gridctl#33:
    ## the old SysV us3-listen script still runs "php ~us3/lims/bin/listen.php &"
    ## as root, and none of the daemons refused that themselves. These
    ## processes submit and manage cluster jobs as the us3 account (mkdir,
    ## scp, sbatch, scancel all run as us3 -- see us3lims_common's
    ## remote_exec::runsAsLoginUser()); root has no ssh key or known_hosts
    ## entry for any cluster us3 does, and no account name appears anywhere
    ## in the failure -- every submission just fails at staging with "No
    ## ECDSA host key is known" or similar, and nothing mails or logs why.
    ##
    ## $file is the caller's own __FILE__, not this one's: a magic constant
    ## evaluates where it's written, so a copy living here would always
    ## print gridctl_bootstrap.php's own path in the remedy instead of the
    ## script that actually needs restarting as us3.
    ##
    ## $replay_args, when given, replaces $action in the printed command
    ## (round-13 nit): jobmonitor.php's own real invocation is "dbname
    ## gfacID HPCAnalysisRequestID", not the literal word "start" used for
    ## the "... refused" message, and printing "start" there left the
    ## suggested command one argument short, exiting 255. $background,
    ## when true, backgrounds the suggested command with the same
    ## nohup/redirect/& pattern listen.php itself already uses to launch
    ## manage-us3-pipe.php (round-13 nit): listen.php and
    ## manage-us3-pipe.php are daemons meant to keep running after the
    ## shell that started them exits, and the plain foreground example
    ## left an admin's terminal blocked on it instead.
    function gridctl_require_not_root( $file, $action, $replay_args = null, $background = false ) {
        if ( ! function_exists( 'posix_geteuid' ) || posix_geteuid() !== 0 ) {
            return;
        }

        $cmd = 'php ' . escapeshellarg( $file ) . ' ' . ( $replay_args !== null ? $replay_args : $action );
        if ( $background ) {
            $cmd = "nohup $cmd >/dev/null 2>&1 &";
        }

        fwrite( STDERR, basename( $file ) . " $action refused: these services submit and"
            . " manage cluster jobs as the us3 account, and must run as us3, not root --"
            . " started as root, every submission fails at staging because root has no ssh"
            . " key or known_hosts entry for the cluster(s) us3 does.\n"
            . "Run this as us3 instead, for example:\n"
            . "    runuser -l us3 -c " . escapeshellarg( $cmd ) . "\n"
            . "or let us3-listen.service start it, which already runs as us3.\n" );
        exit( 1 );
    }
}
