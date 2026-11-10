<?php
/*
 * dispatch_keepalive.php <db> <dispatchRequestID> <generation> <owner> <parent pid> <lane owner> [<lane key>=<generation> ...]
 *
 * Started by dispatchone.php for the length of a staging copy: renews the
 * claim, and the scheduler lanes held with it, until the parent exits or the
 * claim is lost, so a copy longer than the staging lease is not taken over.
 */

$self = __FILE__;

if ( count( $argv ) < 7 ) {
    fwrite( STDERR, "usage: $self db dispatchRequestID generation owner parentPid laneOwner [lane=generation ...]\n" );
    exit( 1 );
}
list( , $lims_db, $dispatch_id, $generation, $owner, $parent, $lane_owner ) = $argv;
if ( ! preg_match( '/^uslims3_[A-Za-z0-9_]+$/', $lims_db ) || ! ctype_digit( $parent ) ) {
    fwrite( STDERR, "$self: invalid arguments\n" );
    exit( 1 );
}
$lanes = array();
foreach ( array_slice( $argv, 7 ) as $arg ) {
    $at = strrpos( $arg, '=' );
    if ( $at !== false ) {
        $lanes[ substr( $arg, 0, $at ) ] = (int) substr( $arg, $at + 1 );
    }
}

$us3bin = exec( "ls -d ~us3/lims/bin" );
require_once __DIR__ . '/gridctl_bootstrap.php';
gridctl_require_not_root( __FILE__, 'run', implode( ' ', array_map( 'escapeshellarg', array_slice( $argv, 1 ) ) ) );
require_once $class_dir . 'dispatch_ledger.php';
require_once __DIR__ . '/dispatch_worker.php';

$link = mysqli_connect( $dbhost, $user, $passwd, 'gfac' );
if ( ! $link ) {
    exit( 1 );
}
mysqli_report( MYSQLI_REPORT_OFF );
dispatch_worker_keepalive_loop( $link, 'gfac', $lims_db, (int) $dispatch_id, (int) $generation, $owner, $lanes, $lane_owner,
                                DISPATCH_WORKER_KEEPALIVE_SECONDS, function () use ( $parent ) {
                                    ## Reparented once dispatchone exits, even while it is an unreaped zombie.
                                    if ( function_exists( 'posix_getppid' ) ) {
                                        return posix_getppid() === (int) $parent;
                                    }
                                    clearstatcache( true, "/proc/$parent" );
                                    return is_dir( "/proc/$parent" );
                                }, function () use ( $dbhost, $user, $passwd ) {
                                    $fresh = @mysqli_connect( $dbhost, $user, $passwd, 'gfac' );
                                    return $fresh ?: null;
                                } );
exit( 0 );
