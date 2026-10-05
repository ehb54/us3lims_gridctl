#!/usr/bin/php
<?php

define( "SLEEPTIME", 10 );

$us3bin = exec( "ls -d ~us3/lims/bin" );
$us3etc = exec( "ls -d ~us3/lims/etc" );
require_once __DIR__ . '/gridctl_bootstrap.php';

if ( !file_exists( $lock_dir ) ) {
    print "Directory $lock_dir does not exist\n";
    exit;
}

if ( !is_writeable( $lock_dir ) ) {
    print "Directory $lock_dir exists but is not writable\n";
    exit;
}

global $lock;
$lock = array();

$lock[ "listen"    ]  = "$lock_dir/listen.php.lock";
$lock[ "manage"    ]  = "$lock_dir/manage-us3-pipe.php.lock";
$lock[ "submit"    ]  = "$lock_dir/submitctl.php.lock";
$lock[ "esign"     ]  = "$lock_dir/esign.php.lock";

global $cmd;
$cmd = array();

$cmd[ "listen"    ] = "$us3bin/listen.php";
$cmd[ "manage"    ] = "$us3bin/manage-us3-pipe.php";
$cmd[ "submit"    ] = "$us3bin/submitctl.php";
$cmd[ "esign"     ] = "$us3bin/esign.php";

function stop() {
    global $lock;

    echo "stopping services...\n";

    clearstatcache();

    foreach ( $lock as $k => $v ) {
        if ( file_exists( $v ) ) {
            if ( is_link( $v ) ) {
                $link = readlink ( $v );

                $pid = substr( $link, 6 );
            } else {
                $pid = rtrim( file_get_contents( $v ) );
                $link = "/proc/$pid";
            }

            if ( file_exists( $link ) ) {
                posix_kill( $pid, SIGTERM );
            }
        }
    }
    sleep( SLEEPTIME );

    clearstatcache();

    $remaining = 0;
    foreach ( $lock as $k => $v ) {
        if ( file_exists( $v ) ) {
            if ( is_link( $v ) ) {
                $link = readlink ( $v );

                $pid = substr( $link, 6 );
            } else {
                $pid = rtrim( file_get_contents( $v ) );
                $link = "/proc/$pid";
            }

            if ( file_exists( $link ) ) {
                $remaining++;
                posix_kill( $pid, SIGKILL );
            }
        }
    }

    if ( $remaining ) {
        sleep( SLEEPTIME );
    }
}

function start() {
    global $cmd, $us3bin;
    echo "starting services...\n";

    foreach ( $cmd as $k => $v ) {
        if ( $k === "manage" ) {
            // manage is launched by listen
            continue;
        }
        $run = "/usr/bin/php $v";
        # echo __FILE__ . " running exec( 'nohup $run > /dev/null 2>&1&' )\n";
        exec( "nohup $run > /dev/null 2>&1&" );
    }
    sleep( SLEEPTIME );

    ## No monitor restarts on its own: one runs only from submit_slurm at
    ## submission time, or from this call. After a reboot every monitor that
    ## was running died with it, so this is the only thing that picks their
    ## jobs back up; without it a job submitted before the reboot sits
    ## unmonitored until someone notices and runs --restart by hand.
    $restart = "$us3bin/uslims_jobs.php";
    if ( is_file( $restart ) ) {
        exec( "/usr/bin/php $restart --restart > /dev/null 2>&1" );
    }
}

function status( $doprint = true ) {
    global $lock;
    if ( $doprint ) {
        echo "Service   PID    Status\n";
    }

    $running = 0;

    clearstatcache();

    foreach ( $lock as $k => $v ) {
        $status = "not running";
        $pid = "";

        if ( file_exists( $v ) ) {
            if ( is_link( $v ) ) {
                $link = readlink ( $v );

                $pid = substr( $link, 6 );
            } else {
                $pid = rtrim( file_get_contents( $v ) );
                $link = "/proc/$pid";
            }

            if ( file_exists( $link ) ) {
                $status = "running";
                $running++;
            } else {
                $pid = "";
            }
        }

        if ( $doprint ) {
            printf( "%-9s %-6s $status\n", $k, $pid );
        }
    }
    return $running;
}

if ( !isset( $argv[ 1 ] ) ) {
    echo "usage: " . basename( __FILE__ ) . " {status|stop|start|restart}\n";
    exit;
}

switch( $argv[ 1 ] ) {
    case "stop" : {
        if ( status( false ) ) {
            stop();
        } else {
            echo "no services are currently running\n";
        }
        status();
        exit;
    }

    case "restart" : {
        if ( status( false ) ) {
            stop();
        } else {
            echo "services are not currently all running\n";
        }
        start();
        system( "/usr/bin/php " . __FILE__ . " status" );
        exit;
    }

    case "start" : {
        if ( status( false ) == count( $lock ) ) {
            echo "all services are already running\n";
        } else {
            start();
        }
        status();
        exit;
    }

    case "status" :
    default : {
        status();
        exit;
    }
}

