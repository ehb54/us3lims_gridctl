<?php

{};

$us3bin = exec( "ls -d ~us3/lims/bin" );
require "$us3bin/listen-config.php";
require "$us3bin/cluster_config.php";

$debug         = false;
$no_db_updates = false;

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
        error_exit( "Query failed $query - " .  mysqli_error( $gfac_link ) );
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

        $results = run_cmd( $v["status"], false, true );

        debug_json( "status for $clname", $results );

        if ( count( $results ) != 3
             || !is_numeric( $results[1] )
             || !is_numeric( $results[2] )
           ) {
            $sta = "down";
            $run = 0;
            $que = 0;
        } else {
            $sta = $results[ 0 ];
            $run = $results[ 1 ];
            $que = $results[ 2 ];
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
