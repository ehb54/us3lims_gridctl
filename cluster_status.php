<?php

{};

$us3bin = exec( "ls -d ~us3/lims/bin" );
require "$us3bin/listen-config.php";
require "$us3bin/cluster_config.php";

$debug         = false;
$no_db_updates = false;

## Health probes are short control commands. Keep this policy with the probe
## implementation instead of publishing another deployment timeout knob. A
## function is used because the unit-test loader safely extracts declarations
## from this side-effecting script.
function cluster_status_command_timeout_seconds() {
    return 120;
}

function debug_json( $msg, $json ) {
    global $debug;
    if ( !isset( $debug ) || !$debug ) {
        return;
    }

    fwrite( STDERR,  "$msg\n" );
    fwrite( STDERR, json_encode( $json, JSON_PRETTY_PRINT ) );
    fwrite( STDERR, "\n" );
}

function error_exit( $msg ) {
    global $self;
    fwrite( STDERR, "$self: $msg\nTerminating due to errors.\n" );
    exit(-1);
}

## Locate GNU timeout(1). This is a Linux runtime requirement supplied by the
## coreutils package, not a CMake/build dependency. See
## remote_exec::timeout_bin() for the same resolution.
function status_timeout_bin() {
    static $bin = null;

    if ( $bin !== null ) {
        return $bin;
    }

    $candidates = array( '/usr/bin/timeout', '/bin/timeout' );

    foreach ( $candidates as $c ) {
        if ( is_executable( $c ) ) {
            return $bin = $c;
        }
    }

    fwrite( STDERR, "cluster_status: WARNING timeout(1) not found; probes run unbounded\n" );

    return $bin = '';
}

## Run one probe command under a wall-clock bound.
##
## The per-cluster 'status' entries in cluster_config.php are raw shell strings,
## mostly bare `ssh <host> .../status/slurm <partition>` with no timeout of
## their own. Unbounded, one probe against a wedged login node blocks the whole
## cron pass, so every cluster's row stops being updated and the queue-setup UI
## keeps showing a stale 'up' for a site that is down. Bounding happens here
## because the command itself is opaque: cluster_probe.php cannot be used, as
## it builds its own remote invocation rather than wrapping a given one.
##
## Returns [ 'lines' => array, 'exit' => int, 'timed_out' => bool ].
function run_probe( $cmd, $seconds ) {
    global $debug;

    $bin     = status_timeout_bin();
    $bounded = $bin === '' ? $cmd : "$bin -k 5 " . (int) $seconds . " sh -c " . escapeshellarg( $cmd );

    if ( isset( $debug ) && $debug ) {
        echo "$bounded\n";
    }

    $res      = array();
    $res_code = 0;
    exec( "$bounded 2>/dev/null", $res, $res_code );

    return array(
        'lines'     => $res,
        'exit'      => $res_code,
        'timed_out' => ( $res_code === 124 || $res_code === 137 ),
    );
}

function run_cmd( $cmd, $die_if_exit = true, $array_result = false ) {
    global $debug;
    if ( isset( $debug ) && $debug ) {
        echo "$cmd\n";
    }
    exec( "$cmd 2>&1", $res, $res_code );
    if ( $die_if_exit && $res_code ) {
        error_exit( "shell command '$cmd' returned result:\n" . implode( "\n", $res ) . "\nand with exit status '$res_code'" );
    }
    if ( !$array_result ) {
        return implode( "\n", $res ) . "\n";
    }
    return $res;
}

$data = array();

local_status();

if ( $no_db_updates ) {
    error_exit( "no db updates set, so exiting now" );
}

$gfac_link = mysqli_connect( $dbhost, $guser, $gpasswd, $gDB );

if ( ! $gfac_link ) {
    error_exit( "Could not connect to DB $gDB" );
}

foreach ( $data as $item ) {
    update( $item[ 'cluster' ], $item[ 'queued' ], $item[ 'status' ], $item[ 'running' ] );
}
mysqli_close( $gfac_link );

exit(0);

## Put it in the DB

function update( $cluster, $queued, $status, $running ) {
    global $gfac_link;

## added time=CURRENT_TIMESTAMP() on updates since mariadb (10.3.28)
##   doesn't seem to honor the gfac.cluster_status.time on update current_timestamp()
    

## if we put a primary key on gfac.cluster_status.cluster we could use this
#    $query =
#        "INSERT INTO cluster_status SET"
#        . " cluster='$cluster'"
#        . " ,queued=$queued"
#        . " ,running=$running"
#        . " ,status='$status'"
#        . " ON DUPLICATE KEY UPDATE"
#        . " queued=$queued"
#        . " ,running=$running"
#        . " ,status='$status'"
#        . " ,time=CURRENT_TIMESTAMP()"
#        ;

## without the primary key on gfac.cluster_status.cluster, we have to do two mysql calls

    $query = "SELECT * FROM cluster_status WHERE cluster='$cluster'";
    $result = mysqli_query( $gfac_link, $query );

    if ( ! $result ) {
        error_exit( "Query failed $query - " .  mysqli_error( $gfac_link ) );
    }

    $rows = mysqli_num_rows( $result );

    if ( $rows == 0 ) { ## INSERT
        $query =
            "INSERT INTO cluster_status SET"
            . " cluster='$cluster'"
            . " ,queued=$queued"
            . " ,running=$running"
            . " ,status='$status'"
            ;
    } else {            ## UPDATE
        $query = 
            "UPDATE cluster_status SET"
            . " queued=$queued"
            . " ,running=$running"
            . " ,status='$status'"
            . " ,time=CURRENT_TIMESTAMP()"
            . " WHERE cluster='$cluster'"
            ;
    }
    
    $result = mysqli_query( $gfac_link, $query );

    if ( ! $result ) {
        ## Do not error_exit() here. This runs in a loop over every cluster,
        ## so aborting on the first bad write leaves every remaining cluster's
        ## row unwritten, and the staleness check then downgrades all of them
        ## to 'down'. Report and carry on.
        fwrite( STDERR, "cluster_status: failed to record status for $cluster: "
                        . mysqli_error( $gfac_link ) . "\n" );
    }
}

## Get local cluster status

function local_status() {
    global $data;
    global $cluster_configuration;
    global $self;

    foreach ( $cluster_configuration as $clname => $v ) {
        if ( !isset( $v["active"] ) || $v["active"] != true ) {
            continue;
        }

        if ( !isset( $v["status"] ) ) {
            error_exit( "cluster $clname does not contain a status command" );
        }

        $probe = run_probe(
            $v["status"], cluster_status_command_timeout_seconds() );

        debug_json( "status for $clname", $probe );

        if ( count( $probe[ 'lines' ] ) != 3
             || !is_numeric( $probe[ 'lines' ][1] )
             || !is_numeric( $probe[ 'lines' ][2] )
           ) {
            ## The probe did not come back with a usable answer. Whether that
            ## means the cluster is down or merely that we could not reach it
            ## is decided by escalate_probe_failure(), because a single failed
            ## probe against a shared HPC site is routine (a login node
            ## refusing one connection under rate limiting) while two in a row
            ## is a real outage.
            $sta = escalate_probe_failure( $clname, $probe );
            $run = 0;
            $que = 0;
        } else {
            $sta = $probe[ 'lines' ][ 0 ];
            $run = $probe[ 'lines' ][ 1 ];
            $que = $probe[ 'lines' ][ 2 ];
        }

        if ( $run != intval( $run ) || $que != intval( $que ) ) {
            $sta = 'down';
            $run = 0;
            $que = 0;
        }
        
        ## Save cluster status values
        $a[ 'cluster' ] = $clname;
        $a[ 'status'  ] = $sta;
        $a[ 'running' ] = $run;
        $a[ 'queued'  ] = $que;

        $data[] = $a;

        echo "$self:  $clname  $que $run $sta\n";
    }
}

## Decide what a failed probe means, using the previously recorded status as a
## one-bit consecutive-failure counter.
##
## Not straight to 'down': against a large shared cluster a single refused
## connection is normal background noise, and flapping to 'down' on one bad
## probe takes the cluster out of the queue-setup UI for everyone. Not 'up'
## either, which would let a dead site keep accepting submissions. 'warn' is
## the intermediate state the schema already has, and a second consecutive
## failure escalates it to 'down'.
##
## The escalation ladder is deliberately stored in the status column rather
## than a new counter column, so this needs no schema migration.
function escalate_probe_failure( $cluster, $probe ) {
    global $gfac_link;

    $why = $probe[ 'timed_out' ]
         ? "probe exceeded its time budget"
         : "probe exited {$probe['exit']} without a parseable answer";

    ## $gfac_link is opened after local_status() runs, so read the previous
    ## status through a short-lived connection of our own.
    $previous = previous_cluster_status( $cluster );

    if ( $previous === 'warn' || $previous === 'down' ) {
        fwrite( STDERR, "cluster_status: $cluster still failing ($why); marking down\n" );
        return 'down';
    }

    fwrite( STDERR, "cluster_status: $cluster $why; marking warn (will escalate if it fails again)\n" );

    return 'warn';
}

## Read a cluster's last recorded status, or '' if it has none.
function previous_cluster_status( $cluster ) {
    global $dbhost, $guser, $gpasswd, $gDB;

    $link = @mysqli_connect( $dbhost, $guser, $gpasswd, $gDB );

    if ( !$link ) {
        return '';
    }

    $query  = "SELECT status FROM cluster_status WHERE cluster='"
              . mysqli_real_escape_string( $link, $cluster ) . "'";
    $result = mysqli_query( $link, $query );
    $status = '';

    if ( $result && mysqli_num_rows( $result ) > 0 ) {
        list( $status ) = mysqli_fetch_array( $result );
    }

    mysqli_close( $link );

    return (string) $status;
}
