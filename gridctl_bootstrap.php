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
