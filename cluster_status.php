<?php

{};

$us3bin = exec( "ls -d ~us3/lims/bin" );
require "$us3bin/listen-config.php";
require "$us3bin/cluster_config.php";

$debug         = false;
$no_db_updates = false;

## Health probes are short control commands. A function so the test loader
## can extract it from this script.
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

## Locate GNU timeout(1) (coreutils). Same resolution as remote_exec::timeout_bin().
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

## Run one probe command under a wall-clock bound, so a wedged login node
## cannot stall the whole pass. The configured 'status' commands are opaque
## shell strings, so they are wrapped here rather than via cluster_probe.php.
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
        ## Report and carry on, so one bad write does not skip the other clusters.
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
            ## No usable answer; escalate_probe_failure() decides warn or down.
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

## Decide what a failed probe means: the first failure is 'warn' (one refused
## connection is routine on a shared cluster), a second in a row is 'down'.
## The previous status serves as the failure counter.
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
