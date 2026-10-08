#!/usr/bin/php
<?php

define( "SLEEPTIME", 10 );

$us3bin  = exec( "ls -d ~us3/lims/bin" );
$us3etc  = exec( "ls -d ~us3/lims/etc" );
## uslims_jobs.php ships with dbutils, not in lims/bin with the rest of this
## file, and is missing on a host that hasn't run dbutils' upgrade yet -- in
## which case monitor restart is simply skipped below (is_file() on an empty
## path). 2>/dev/null: without it, ls's own "cannot access" went straight to
## this process's stderr on every single stop/start/restart/status call on
## such a host, not just once.
$us3util = exec( "ls -d ~us3/lims/database/utils 2>/dev/null" );
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

## A pending start()'s background restart retry (monitor_restart_background())
## is not one of $lock's own services, so without this it keeps retrying
## after a stop and can start monitors for services this call just stopped,
## once MariaDB finally comes back. Called unconditionally by both the
## "stop" and "restart" cases below, not only from inside stop() itself
## (round 8 nit): those cases only call stop() when a $lock daemon is
## actually running, but a pending retry child can exist with every $lock
## daemon down (e.g. a prior start() left one retrying with MariaDB still
## unreachable) -- that child would otherwise never be reached at all.
##
## Matched by command line (round 8 should-fix), not a pidfile: nothing
## ever removed a pidfile when the child exited on its own (a clean retry
## success, or the FINAL give-up), so a later, unrelated process that
## reused the same pid could be the one a pidfile-based kill hit instead --
## observed with the pid reused by another us3 process, and separately by
## another root process after the root-run variant. pkill -f also
## naturally covers two concurrent retries (two start()s racing with
## MariaDB down each spawn one): a single pidfile could only ever name one
## of them, leaving the other to survive a stop.
function kill_pending_restart_retry() {
    exec( 'pkill -f ' . escapeshellarg( __FILE__ . ' _restart-retry-background' ) );
}

function stop() {
    global $lock;

    echo "stopping services...\n";

    kill_pending_restart_retry();
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

## Text mysqli/MariaDB produce for the connection-class errnos
## db_error_is_connection_class() (job_state_machine.php) retries on --
## uslims_jobs.php runs in a separate process reached only through its exit
## code and stdout/stderr, so there is no errno to read directly here, only
## this text to recognize it by. Anything else is treated as permanent: a
## bad db_config.php, wrong credentials, or a missing file reads the same on
## every attempt, and retrying it for minutes cannot help.
function monitor_restart_is_connection_class_failure( $output ) {
    $text = implode( "\n", $output );
    foreach ( array(
        'Can\'t connect to',
        'Lost connection',
        'Connection refused',
        'Too many connections',
        'has exceeded the',
        'Lock wait timeout',
        'Deadlock found',
        ## mysqlnd's own wording for a missing socket after a clean stop
        ## (as opposed to the stale-socket case above, which still prints
        ## "Connection refused"): a reboot or `systemctl restart mariadb`
        ## leaves no socket file at all for up to ~20s while mariadb starts,
        ## and without this the restart gave up in ~10s even though MariaDB
        ## came up on its own a few seconds later.
        '(HY000/2002)',
        ## 2006: the connection was accepted and then dropped mid-query, as
        ## MariaDB can do while still finishing its own startup.
        'gone away',
        ## 1053 (ER_SERVER_SHUTDOWN): seen here if --restart itself raced a
        ## shutdown, not just the cleanup-query case job_state_machine.php's
        ## db_error_is_connection_class() already retries.
        'Server shutdown',
    ) as $pattern ) {
        if ( stripos( $text, $pattern ) !== false ) {
            return true;
        }
    }
    return false;
}

## The restart command and its log path, computed the same way by both
## start() and the detached retry child (round 8 nit, replacing argv
## passing between them): _restart-retry-background used to take these as
## arguments, so a junk or missing argument (even '' -- isset() alone does
## not catch that) reached exec() with an empty command, crashing 8.2 with
## an uncaught ValueError and sending a false "monitor restart failed" mail
## on 7.2. Computing them here, in the child itself, needs no arguments to
## validate at all.
function restart_command_and_log() {
    global $us3util, $us3etc;

    $restart     = "$us3util/uslims_jobs.php";
    $restart_log = "$us3etc/services-restart.log";
    ## See start()'s own comment on the cd: harmless, not required, kept for
    ## uslims_jobs.php's own relative-path assumptions elsewhere.
    $restart_cmd = "cd " . escapeshellarg( $us3util ) . " && /usr/bin/php "
                 . escapeshellarg( $restart ) . " --restart 2>&1";

    return array( $restart_cmd, $restart_log );
}

## One attempt at `uslims_jobs.php --restart`, logged either way. Returns the
## exit code.
function monitor_restart_attempt( $restart_cmd, $restart_log, $attempt ) {
    $output = array();
    exec( $restart_cmd, $output, $rc );
    file_put_contents( $restart_log,
        "[" . date( 'c' ) . "] uslims_jobs.php --restart attempt $attempt exited $rc\n"
        . implode( "\n", $output ) . "\n", FILE_APPEND );
    return array( $rc, $output );
}

## A thin wrapper around sleep(), so a test can replace it with a no-op
## instead of actually waiting out a real retry budget (up to ~300s for the
## connection-class case).
function monitor_restart_sleep( $seconds ) {
    sleep( $seconds );
}

## Keeps retrying `uslims_jobs.php --restart` well past what start() can wait
## for in the foreground (see start()'s own comment for why this runs as a
## detached child instead). $next_attempt is the attempt number to log next
## (the foreground already logged attempt 1). A fresh attempt here decides
## whether this looks connection-class: that failure gets the full extended
## budget (EL8's mariadb.service alone allows itself up to 300s to recover
## from an unclean shutdown), a permanent one (a missing db_config.php, bad
## credentials) gets only a couple of quick extra tries before giving up --
## waiting minutes for a config error to fix itself on its own helps no
## one. Mails $admin_email and syslogs once, only on final failure.
function monitor_restart_background( $restart_cmd, $restart_log, $next_attempt ) {
    global $admin_email, $org_name;

    list( $rc, $output ) = monitor_restart_attempt( $restart_cmd, $restart_log, $next_attempt );
    if ( $rc === 0 ) {
        return;
    }

    $connection_class = monitor_restart_is_connection_class_failure( $output );
    ## The last attempt number to try, inclusive -- not a count: with
    ## $next_attempt 2, the connection-class budget is attempts 2 through
    ## 30, 29 tries total, each wait up to 60s apart (~ comfortably past
    ## EL8's own 300s mariadb.service allowance).
    $max_tries = $connection_class ? 30 : ( $next_attempt + 2 );
    $wait = SLEEPTIME;
    $last_try = $next_attempt;

    for ( $try = $next_attempt + 1; $try <= $max_tries; $try++ ) {
        monitor_restart_sleep( $wait );
        $last_try = $try;
        list( $rc, $output ) = monitor_restart_attempt( $restart_cmd, $restart_log, $try );
        if ( $rc === 0 ) {
            return;
        }
        ## This attempt's own output, not the first attempt's $connection_class
        ## (round 8 nit): checking only the original classification meant that
        ## once attempt $next_attempt looked connection-class, nothing any
        ## later attempt's output said could ever break the loop early, since
        ## "!$connection_class" was already false for the rest of the run. A
        ## wrong password discovered partway through the extended budget (say,
        ## MariaDB came back but the stored credentials are now bad) used to
        ## burn the full ~26-minute budget with no mail until it finally gave
        ## up. Each attempt's own output decides now, independently.
        if ( !monitor_restart_is_connection_class_failure( $output ) ) {
            break;
        }
        $wait = min( $wait * 2, 60 );
    }

    $message = "uslims_jobs.php --restart did not succeed after $last_try attempt(s); "
             . "some jobmonitors may still be unmonitored after this boot/restart. "
             . "See $restart_log.";
    file_put_contents( $restart_log, "[" . date( 'c' ) . "] FINAL: $message\n", FILE_APPEND );
    if ( function_exists( 'syslog' ) ) {
        openlog( 'us3-services', LOG_PID, LOG_DAEMON );
        syslog( LOG_ERR, $message );
        closelog();
    }
    if ( !empty( $admin_email ) ) {
        @mail( $admin_email, "[$org_name] monitor restart failed", $message );
    }
}

function start() {
    global $cmd, $us3bin, $us3util, $us3etc;
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
    ##
    ## uslims_jobs.php finds db_config.php next to itself now regardless of
    ## cwd, so restart_command_and_log()'s cd is harmless rather than
    ## required; kept so uslims_jobs.php's own relative-path assumptions
    ## elsewhere (e.g. its own help text, other scripts invoked the same
    ## way) still see the directory they expect. This unit (us3-listen.service)
    ## runs from /, not from lims/bin like the rest of this file assumes, and
    ## isn't ordered after mariadb.service, so right after a crash this can
    ## run before MariaDB has finished recovering.
    if ( is_file( "$us3util/uslims_jobs.php" ) ) {
        list( $restart_cmd, $restart_log ) = restart_command_and_log();

        list( $rc, $output ) = monitor_restart_attempt( $restart_cmd, $restart_log, 1 );

        if ( $rc === 0 ) {
            echo "monitor restart: ok\n";
        } else {
            ## The rest of the retry budget runs in a detached
            ## child instead of here, so this call -- and the systemd unit
            ## (and multi-user.target) waiting on it -- does not hold for
            ## however long that takes. The old foreground budget (6 tries,
            ## 10s apart, ~60s) was already shorter than a slow crash
            ## recovery, and a permanent failure (a missing db_config.php,
            ## say) burned the full ~60s on every boot and every manual
            ## start/restart/reload for nothing, holding the unit each time.
            echo "monitor restart: attempt 1 failed (exit $rc); retrying in the"
               . " background, see $restart_log\n";
            ## No pidfile and no arguments (round 8 should-fix/nit): stop()
            ## finds this child by matching its command line with pkill -f
            ## instead (see stop()'s own comment for why a pidfile could not
            ## reliably name it), and the child recomputes the restart
            ## command/log itself via restart_command_and_log() instead of
            ## receiving them as arguments, so there is nothing for a junk
            ## or missing argument to crash on either.
            $bg = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ )
                . ' _restart-retry-background > /dev/null 2>&1 &';
            exec( $bg );
        }
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
    ## Internal: start()'s own detached child, not a documented entry point.
    ## Takes no arguments (round 8 nit): restart_command_and_log() computes
    ## the same restart command/log start() already used for attempt 1, so
    ## there is nothing left for a junk or missing argument to crash on.
    case "_restart-retry-background" : {
        list( $restart_cmd, $restart_log ) = restart_command_and_log();
        monitor_restart_background( $restart_cmd, $restart_log, 2 );
        exit;
    }

    case "stop" : {
        if ( status( false ) ) {
            stop();
        } else {
            echo "no services are currently running\n";
            kill_pending_restart_retry();
        }
        status();
        exit;
    }

    case "restart" : {
        if ( status( false ) ) {
            stop();
        } else {
            echo "services are not currently all running\n";
            kill_pending_restart_retry();
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

