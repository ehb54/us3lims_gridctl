#!/usr/bin/php
<?php

{};

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

exec( "squeue -t all 2>&1", $res, $res_code );
if ( $res_code ) {
    echo "{\"error\":\"command failed $res_code\"}";
    exit;
}
if ( !count( $res ) ) {
    echo "{\"error\":\"command failed returned empty result\"}";
    exit;
}

$STDERR = STDERR;

function debug_json( $msg, $json ) {
    global $STDERR;
    fwrite( $STDERR,  "$msg\n" );
    fwrite( $STDERR, json_encode( $json, JSON_PRETTY_PRINT ) );
    fwrite( $STDERR, "\n" );
}

array_shift( $res );

debug_json( "squeue result", $res );

$jinfo = (object)[];
$jcount = count( $res );

foreach ( $res as $v ) {
    $l = preg_split( '/\\s+/', trim( $v ) );
    if ( count( $l ) != 8 ) {
        echo "{\"error\":\"unexpected result line $v\"}";
        exit;
    }
    debug_json( "line", $l );
    $jinfo->{ $l[0] } = (object)[];
    $jinfo->{ $l[0] }->state = $l[4];
    if ( !isset( $jstart ) && $l[4] == "R" ) {
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
