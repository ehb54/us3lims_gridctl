#!/usr/bin/php
<?php

/*
 * queuepos.php <gfacID>
 *
 * Reports a job's queue position and state as JSON.
 *
 * An operator runs this when something looks wrong, which means it is most
 * often run while the cluster is misbehaving, so it asks the cluster the job
 * actually ran on through remote_exec: bounded by a timeout, and with
 * transport failure kept separate from a real answer rather than parsed as
 * one. There is no local-versus-remote branch here, because remote_exec
 * decides that from the cluster's own configuration.
 */

$us3bin = exec( "ls -d ~us3/lims/bin" );
include_once "$us3bin/listen-config.php";
include_once $class_dir . "../global_config.php";   ## $cluster_details
include_once __DIR__ . "/../cluster_probe.php";

$self = __FILE__;

$notes = <<<__EOD
usage: $self jobid

reports queue position and status for job


__EOD;

$u_argv = $argv;
## not if executed array_shift( $u_argv ); # first element is program name

$gfacid = array_shift( $u_argv );
if ( count( $u_argv ) ) {
    $gfacid = array_shift( $u_argv );
} else {
    echo $notes;
    exit;
}

if ( count( $u_argv ) ) {
    echo $notes;
    exit;
}

$STDERR = STDERR;

## Which cluster is this job on? The executing cluster wins over the requested
## one: a metascheduler submission can land somewhere other than where it was
## aimed, and asking the wrong cluster reports "job not found" for a job that
## is running perfectly well.
$gLink = mysqli_connect( $dbhost, $guser, $gpasswd, $gDB );

if ( ! $gLink ) {
    echo "{\"error\":\"could not connect to the job database\"}";
    exit;
}

$stmt = mysqli_prepare( $gLink,
    "SELECT cluster, metaschedulerClusterExecuting FROM analysis WHERE gfacID = ?" );
mysqli_stmt_bind_param( $stmt, 's', $gfacid );
mysqli_stmt_execute( $stmt );
$row = mysqli_fetch_assoc( mysqli_stmt_get_result( $stmt ) );
mysqli_stmt_close( $stmt );
mysqli_close( $gLink );

if ( ! $row ) {
    echo "{\"error\":\"job not found in the job database\"}";
    exit;
}

$cluster = empty( $row[ 'metaschedulerClusterExecuting' ] )
         ? $row[ 'cluster' ] : $row[ 'metaschedulerClusterExecuting' ];

## Short budget and no retry: this is a diagnostic with a human waiting, and a
## reachability answer that took a minute to arrive describes history rather
## than the present.
$rx  = cluster_probe_remote( $cluster, function ( $m ) use ( $STDERR ) {
    fwrite( $STDERR, "$m\n" );
} );

$out = $rx->run( "squeue -t all", array(
    'label'   => 'squeue',
    'timeout' => 20,
    'retries' => 0,
) );

## An infrastructure fault is not an answer about the job. Saying so plainly is
## the whole point: "unreachable" and "not queued" look identical to a user and
## mean opposite things.
if ( remote_exec_infra_fault( $out ) ) {
    echo json_encode( [
        "error"   => "cluster $cluster did not respond, so the queue could not be read",
        "class"   => $out[ 'class' ],
        "cluster" => $cluster,
    ] );
    exit;
}

if ( ! $out[ 'ok' ] ) {
    echo json_encode( [
        "error"   => "squeue failed on $cluster",
        "detail"  => trim( $out[ 'stderr' ] ) !== '' ? trim( $out[ 'stderr' ] ) : $out[ 'text' ],
        "cluster" => $cluster,
    ] );
    exit;
}

## stdout only. stderr is kept out of the parser deliberately.
$res = $out[ 'stdout' ];

if ( ! count( $res ) ) {
    echo "{\"error\":\"squeue returned an empty result\"}";
    exit;
}

## Guarded because listen-config.php, included at the top of this file,
## declares a debug_json() of its own. An unguarded top-level declaration is
## hoisted at compile time, so it was already in place before listen-config.php
## ran and every invocation of this script died with "Cannot redeclare
## debug_json()" -- no argument and no configuration avoided it. submitone.php
## and submitctl.php carry the same guard for the same reason.
##
## The consequence is deliberate: with the guard, listen-config.php's version
## wins, so this output is gated behind $logging_level >= 3 and goes through
## write_log() rather than unconditionally to stderr. That matches the sibling
## scripts, and an operator running this for a queue position does not want a
## dump of every squeue line by default.
if ( !function_exists( 'debug_json' ) ) {
    function debug_json( $msg, $json ) {
        global $STDERR;
        fwrite( $STDERR,  "$msg\n" );
        fwrite( $STDERR, json_encode( $json, JSON_PRETTY_PRINT ) );
        fwrite( $STDERR, "\n" );
    }
}

array_shift( $res );

debug_json( "squeue result", $res );

$jinfo = (object)[];
$jcount = count( $res );

## Number of running jobs ahead of the queue. Left unset when nothing is
## running, which made the pending-position arithmetic below subtract an
## undefined value: PHP treats that as 0 with a warning, so every reported
## position was silently wrong by the running-job count on an idle cluster.
$jstart = 0;

foreach ( $res as $v ) {
    $l = preg_split( '/\\s+/', trim( $v ) );
    if ( count( $l ) != 8 ) {
        echo "{\"error\":\"unexpected result line $v\"}";
        exit;
    }
    debug_json( "line", $l );
    $jinfo->{ $l[0] } = (object)[];
    $jinfo->{ $l[0] }->state = $l[4];
    if ( ! $jstart && $l[4] == "R" ) {
        $jstart = $jcount;
    }
    $jinfo->{ $l[0] }->pos   = $jcount--;
}

foreach ( $jinfo as $v ) {
    switch ( $v->state ) {
        case "PD"  : $v->pos -= $jstart; break;
        case "R"   : $v->pos = 0; break;
        default    : $v->pos = -1; break;
    }
}

debug_json( "jinfo", $jinfo );

if ( isset( $jinfo->{ $gfacid } ) ) {
    echo json_encode( $jinfo->{ $gfacid } );
} else {
    echo "{\"error\":\"job not found\"}";
}
