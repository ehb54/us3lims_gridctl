#!/usr/bin/php
<?php

/*
 * queuepos.php <gfacID>
 *
 * Reports a job's queue position and state as JSON. Asks through remote_exec,
 * so a transport failure is reported as such rather than parsed as an answer.
 */

$us3bin = exec( "ls -d ~us3/lims/bin" );
require_once dirname( __DIR__ ) . '/gridctl_bootstrap.php';
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
if ( ! $stmt ) {
    fwrite( $STDERR, "query failed: " . mysqli_error( $gLink ) . "\n" );
    exit( 1 );
}
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

## Explicit, delimited fields instead of squeue's default table: that table's
## column count varies by Slurm version (seen directly: 8 on some, 9 on
## Anvil 26.05.4) and by row (extra whitespace-separated fields on some
## Expanse 23.02.7 rows), so a fixed-column-count parse of it is not
## portable. --array expands an array job's pending tasks into one row per
## task (rather than a collapsed range) so each task's own ID and state are
## visible; %i still yields a heterogeneous job component as "<id>+<n>".
$out = $rx->run( "squeue --noheader --array --states=all --format='%i|%t'", array(
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

if ( !function_exists( 'queuepos_report' ) ) {
    /**
     * Parse squeue's "<jobid>|<state>" lines (from --noheader --format='%i|%t')
     * and report $gfacid's queue position and state. Pure: no I/O, so this is
     * the part covered directly by unit tests.
     *
     * @param string[] $lines Raw squeue stdout lines, one per row.
     * @param string   $gfacid The job id being asked about.
     * @return array JSON-ready: ['state' => ..., 'pos' => ...] (+'incomplete'
     *               => true when a malformed row was skipped), or ['error' => ...].
     */
    function queuepos_report( array $lines, $gfacid ) {
        ## No header to discard: --noheader. Each row is "<jobid>|<state>"; an
        ## unrelated row from this same squeue that doesn't split into exactly
        ## those two fields (truncated output, an unexpected scheduler message
        ## mixed into stdout, etc.) is skipped rather than aborting the whole
        ## request -- one bad row must not hide every other job's state,
        ## including the target's.
        $rows = array();
        $malformed = 0;

        foreach ( $lines as $v ) {
            $v = trim( $v );
            if ( $v === '' ) {
                continue;
            }
            $fields = explode( '|', $v );
            if ( count( $fields ) !== 2 || trim( $fields[0] ) === '' ) {
                debug_json( "queuepos: skipping malformed row", $v );
                $malformed++;
                continue;
            }
            $rows[] = array( trim( $fields[0] ), trim( $fields[1] ) );
        }

        debug_json( "squeue rows", $rows );

        if ( ! count( $rows ) ) {
            return array( "error" => "squeue returned no usable rows" );
        }

        $jinfo = (object)[];

        ## Number of entries actually parsed, not the raw line count: a
        ## malformed row contributes nothing to the position count either.
        $jcount = count( $rows );

        ## Number of running jobs ahead of the queue.
        $jstart = 0;

        foreach ( $rows as $r ) {
            list( $jobid, $state ) = $r;
            $jinfo->{ $jobid } = (object)[];
            $jinfo->{ $jobid }->state = $state;
            if ( ! $jstart && $state === "R" ) {
                $jstart = $jcount;
            }
            $jinfo->{ $jobid }->pos   = $jcount--;
        }

        ## Listing order is not a guaranteed start order, so "pos" has always
        ## been an estimate for PD jobs (pre-existing behavior, unchanged
        ## here). A malformed row dropped above makes that estimate
        ## additionally rest on an incomplete snapshot of the queue, so PD
        ## positions are reported as unknown (-1, the same sentinel already
        ## used for states this calculation does not otherwise handle) rather
        ## than a count that is now confidently wrong.
        foreach ( $jinfo as $v ) {
            switch ( $v->state ) {
                case "PD"  : $v->pos = $malformed > 0 ? -1 : $v->pos - $jstart; break;
                case "R"   : $v->pos = 0; break;
                default    : $v->pos = -1; break;
            }
        }

        debug_json( "jinfo", $jinfo );

        if ( ! isset( $jinfo->{ $gfacid } ) ) {
            return array( "error" => "job not found" );
        }

        $result = (array) $jinfo->{ $gfacid };
        if ( $malformed > 0 ) {
            $result[ 'incomplete' ] = true;
        }
        return $result;
    }
}

echo json_encode( queuepos_report( $res, $gfacid ) );
