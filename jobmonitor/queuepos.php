#!/usr/bin/php
<?php

/*
 * queuepos.php <gfacID>
 *
 * Reports a job's queue position and state as JSON. Asks through remote_exec,
 * so a transport failure is reported as such rather than parsed as an answer.
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

## The executing cluster wins over the requested one: a metascheduler
## submission can land somewhere other than where it was aimed.
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

## Short budget and no retry: a human is waiting.
$rx  = cluster_probe_remote( $cluster, function ( $m ) use ( $STDERR ) {
    fwrite( $STDERR, "$m\n" );
} );

$out = $rx->run( "squeue -t all", array(
    'label'   => 'squeue',
    'timeout' => 20,
    'retries' => 0,
) );

## An infrastructure fault is not an answer about the job.
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

## Guarded: listen-config.php declares debug_json() too, and its version wins.
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

## Number of running jobs ahead of the queue.
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
