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
## %P (partition) is needed so a job's queue position only counts PD jobs
## actually ahead of it in ITS OWN partition -- a cluster with more than
## one queue listed this job behind every PD job in every partition
## combined, reporting a higher position than the partition it will
## actually start from ever has.
$out = $rx->run( "squeue --noheader --array --states=all --format='%i|%t|%P'", array(
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
            if ( count( $fields ) !== 3 || trim( $fields[0] ) === '' ) {
                debug_json( "queuepos: skipping malformed row", $v );
                $malformed++;
                continue;
            }
            $rows[] = array( trim( $fields[0] ), trim( $fields[1] ), trim( $fields[2] ) );
        }

        debug_json( "squeue rows", $rows );

        if ( ! count( $rows ) ) {
            return array( "error" => "squeue returned no usable rows" );
        }

        $jinfo = (object)[];

        ## Rank among PD jobs only, in listing order, PER PARTITION: the
        ## first pending job squeue lists in a given partition gets 1 (next
        ## up in THAT partition), the second gets 2, and so on. An earlier
        ## version ranked across every row regardless of partition --
        ## confirmed on a real cluster with more than one queue: a job
        ## listed behind only 3 other PD jobs in its own partition, but
        ## behind a 3-job partition listed first in squeue's output overall,
        ## reported position 4 instead of 1.
        ##
        ## Also previously counted every row DOWN from the total count
        ## instead, then subtracted where the first "R" row appeared --
        ## which numbered the *first*-listed PD job highest and the
        ## *last*-listed one 1, backwards from what "position in queue"
        ## means, confirmed on a real cluster: the next job to start
        ## reported position 10500 (of 10500), the last reported 1.
        $pdRankByPartition = array();

        foreach ( $rows as $r ) {
            list( $jobid, $state, $partition ) = $r;
            $jinfo->{ $jobid } = (object)[];
            $jinfo->{ $jobid }->state = $state;
            $jinfo->{ $jobid }->pos = 0;   ## Overwritten below for PD; harmless meanwhile.

            if ( $state !== "PD" ) {
                continue;
            }

            ## %P lists every partition a job was submitted to, comma-joined,
            ## until it is actually assigned one ("batch,fast") -- not just
            ## the one it ends up running in. That job is never LIMS's own:
            ## LIMS always resolves to one specific queue per cluster_details
            ## entry (a center with more than one real partition, e.g.
            ## Bridges2's shared/full-node split, gets two separate cluster
            ## entries, never one job submitted to both). A multi-partition
            ## row belongs to some other user sharing the same national HPC
            ## center, and this squeue snapshot cannot say which of its
            ## listed partitions Slurm's own scheduler will actually place it
            ## in -- that choice hasn't been made yet, so there is no ground
            ## truth to rank against, only a choice of which way to be
            ## wrong. Counting it into every partition it listed (the
            ## conservative choice: never understates how many jobs could
            ## still take a slot in this partition before the one being
            ## asked about) is what is done here; counting it into only its
            ## best-ranked partition is what an earlier version did, and
            ## both are estimates, not counts, regardless of which is
            ## picked. $cluster isn't something LIMS schedules -- it only
            ## decides which cluster to send a job to and polls the result,
            ## the same as this whole function is read-only against
            ## whatever Slurm itself decides. Nothing else in gridctl reads
            ## $jinfo->pos: it reaches a human through the GUI and nothing
            ## here retries or branches on its value.
            $bestRank = null;
            foreach ( explode( ',', $partition ) as $p ) {
                $pdRankByPartition[ $p ] = ( $pdRankByPartition[ $p ] ?? 0 ) + 1;
                $rank = $pdRankByPartition[ $p ];
                if ( $bestRank === null || $rank < $bestRank ) {
                    $bestRank = $rank;
                }
            }
            $jinfo->{ $jobid }->pos = $bestRank;
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
                case "PD"  : $v->pos = $malformed > 0 ? -1 : $v->pos; break;
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
