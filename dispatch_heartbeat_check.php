<?php
/*
 * dispatch_heartbeat_check.php
 *
 * The independent check of the dispatch worker, run from cron: the worker
 * cannot report its own death. A stale or missing heartbeat opens an urgent
 * alert item on the dispatch dashboard. When the database itself cannot be
 * reached, the problem is appended to a durable log and mailed to the site
 * administrator at most once an hour, since alert items cannot be written.
 * A dead worker is mailed to the site administrator once per outage.
 */

$us3bin = exec( "ls -d ~us3/lims/bin" );
require_once __DIR__ . '/gridctl_bootstrap.php';
gridctl_require_not_root( __FILE__, 'run', '' );
include_once $class_dir . "../global_config.php";   ## $global_site_admin_email
require_once $class_dir . 'dispatch_alerts.php';

$site_admin = dispatch_site_admin_address( isset( $global_site_admin_email ) ? $global_site_admin_email : '',
                                           isset( $admin_email ) ? $admin_email : '' );
$send = dispatch_site_admin_sender( $site_admin, isset( $org_name ) ? $org_name : '' );

$log = dirname( $us3bin ) . '/etc/dispatch-health.log';
$note = function ( $msg ) use ( $log ) {
    file_put_contents( $log, gmdate( 'Y-m-d H:i:s' ) . " UTC $msg\n", FILE_APPEND | LOCK_EX );
};

mysqli_report( MYSQLI_REPORT_OFF );
$link = @mysqli_connect( $dbhost, $user, $passwd, 'gfac' );
if ( ! $link ) {
    $note( "the dispatch database could not be reached: " . mysqli_connect_error() );
    $stamp = dirname( $us3bin ) . '/etc/dispatch-health.mailed';
    if ( ! is_file( $stamp ) || filemtime( $stamp ) < time() - 3600 ) {
        $send( 'UltraScan dispatch: database unreachable',
               "The dispatch heartbeat check could not reach the gfac database on " . gethostname()
               . ". Alert items and the dashboard are unavailable until it is back; see $log.\n" );
        touch( $stamp );
    }
    exit( 1 );
}

$status = dispatch_heartbeat_status( $link, 'gfac' );
if ( $status[ 'ok' ] ) {
    exit( 0 );
}
## An unreadable heartbeat table (gfac's dispatch tables missing or unusable) is not "never beat":
## the alert table is unusable too, so mail directly, once an hour.
if ( isset( $status[ 'error' ] ) ) {
    $detail = "the dispatch tables in gfac cannot be read ({$status['error']}); pool submissions cannot be"
            . " dispatched until uslims_upgrade.php --apply creates or repairs them";
    $note( $detail );
    $stamp = dirname( $us3bin ) . '/etc/dispatch-health.mailed';
    if ( ! is_file( $stamp ) || filemtime( $stamp ) < time() - 3600 ) {
        $send( 'UltraScan dispatch: dispatch tables unusable', "On " . gethostname() . ": $detail. See $log.\n" );
        touch( $stamp );
    }
    exit( 2 );
}
$detail = $status[ 'age' ] === null
        ? 'no dispatch worker has ever recorded a heartbeat; check that submitctl.php runs under us3-listen'
        : "the dispatch worker {$status['worker']} last recorded a heartbeat {$status['age']} seconds ago;"
          . " check submitctl.php under us3-listen (services.php) and its log";
dispatch_alert_raise( $link, 'gfac', 'worker_heartbeat', 'worker_heartbeat', 'urgent', null, null, $detail );
$note( $detail );
## The worker cannot mail its own death; once per outage, whatever $global_dispatch_alert_email says.
dispatch_alert_email_pending( $link, 'gfac', true, $send, 'worker_heartbeat' );
exit( 2 );
