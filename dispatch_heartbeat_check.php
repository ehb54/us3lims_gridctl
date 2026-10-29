<?php
/*
 * dispatch_heartbeat_check.php
 *
 * The independent check of the dispatch worker, run from cron: the worker
 * cannot report its own death. A stale or missing heartbeat opens an urgent
 * alert item on the dispatch dashboard. When the database itself cannot be
 * reached, the problem is appended to a durable log and mailed to the site
 * administrator at most once an hour, since alert items cannot be written.
 */

$us3bin = exec( "ls -d ~us3/lims/bin" );
require_once __DIR__ . '/gridctl_bootstrap.php';
gridctl_require_not_root( __FILE__, 'run', '' );
require_once $class_dir . 'dispatch_alerts.php';

$log = dirname( $us3bin ) . '/etc/dispatch-health.log';
$note = function ( $msg ) use ( $log ) {
    file_put_contents( $log, gmdate( 'Y-m-d H:i:s' ) . " UTC $msg\n", FILE_APPEND | LOCK_EX );
};

mysqli_report( MYSQLI_REPORT_OFF );
$link = @mysqli_connect( $dbhost, $user, $passwd, 'gfac' );
if ( ! $link ) {
    $note( "the dispatch database could not be reached: " . mysqli_connect_error() );
    $stamp = dirname( $us3bin ) . '/etc/dispatch-health.mailed';
    if ( ! empty( $admin_email ) && ( ! is_file( $stamp ) || filemtime( $stamp ) < time() - 3600 ) ) {
        mail( $admin_email, 'UltraScan dispatch: database unreachable',
              "The dispatch heartbeat check could not reach the gfac database on " . gethostname()
              . ". Alert items and the dashboard are unavailable until it is back; see $log.\n",
              "From: " . ( isset( $org_name ) ? $org_name : 'UltraScan' ) . " Admin<$admin_email>\n" );
        touch( $stamp );
    }
    exit( 1 );
}

$status = dispatch_heartbeat_status( $link, 'gfac' );
if ( $status[ 'ok' ] ) {
    exit( 0 );
}
$detail = $status[ 'age' ] === null
        ? 'no dispatch worker has ever recorded a heartbeat; check that submitctl.php runs under us3-listen'
        : "the dispatch worker {$status['worker']} last recorded a heartbeat {$status['age']} seconds ago;"
          . " check submitctl.php under us3-listen (services.php) and its log";
dispatch_alert_raise( $link, 'gfac', 'worker_heartbeat', 'worker_heartbeat', 'urgent', null, null, $detail );
$note( $detail );
exit( 2 );
