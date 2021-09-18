<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
include "$us3bin/listen-config.php";
include "$class_dir/experiment_status.php";
include "$class_dir/experiment_errors.php";

# ********* start user defines *************

# the polling interval
$poll_sleep_seconds = 4;

# logging_level 
# 0 : minimal messages (expected value for production)
# 1 : add some db messages
# 2 : add idle polling messages
# 3 : debug messages
# 4 : way too many debug messages
$logging_level      = 3;

# ********* end user defines ***************

# ********* start admin defines *************
# ********* end admin defines ***************


# add locking
if ( isset( $lock_dir ) ) {
    $lock_main_script_name  = __FILE__;
    require "$us3bin/lock.php";
}

function error_add( $msg ) {
    global $startup_errors;
    $startup_errors = true;
    write_logls( "ERROR : ${msg}." );
}

function exit_if_errors() {
    global $startup_errors;
    if ( $startup_errors ) {
        write_logls( "ERRORs present, quitting." );
        exit(-1);
    }
}

function write_logls( $msg, $this_level = 0 ) {
    global $self;
    write_logl( "$self: $msg", $this_level );
}

function write_logl( $msg, $this_level = 0 ) {
    global $logging_level;
    if ( $logging_level >= $this_level ) {
        echo "$msg\n";
#        write_log( $msg );
    }
}

function debug_json( $msg, $json ) {
    global $logging_level;
    if ( $logging_level < 3 ) {
        return;
    }
    echo "$msg\n";
    echo json_encode( $json, JSON_PRETTY_PRINT );
    echo "\n";
}

function quote_fix( $str ) {
    return str_replace( "'", "*", $str );
}

function db_connect() {
    global $dbhost;
    global $user;
    global $passwd;
    global $db;
    global $db_handle;
    global $poll_sleep_seconds;
    
    do {
        $db_handle = mysqli_connect( $dbhost, $user, $passwd, $db );
        if ( !$db_handle ) {
            write_logls( "could not connect to mysql: $dbhost, $user, $db. Will retry in ${poll_sleep_seconds}s" );
            sleep( $poll_sleep_seconds );
        }
    } while ( !$db_handle );
}

function echoline( $str = "-", $count = 80, $print = true ) {
    $out = "";
    for ( $i = 0; $i < $count; ++$i ) {
       $out .= $str;
    }
    if ( $print ) {
        echo "$out\n";
    }
    return "$out\n";
}

# signal handler
# for PHP < 7.2
# declare(ticks = 1);
pcntl_async_signals( true );

function sig_handler($sig) {
    switch($sig) {
        case SIGINT:
        write_logls( "Terminated by signal SIGINT" );
        break;
        case SIGHUP:
        write_logls( "Terminated by signal SIGHUP" );
        break;
        case SIGTERM:
        write_logls( "Terminated by signal SIGTERM" );
        break;
      default:
        write_logls( "Terminated by unknown signal" );
    }
    exit(-1);
}

pcntl_signal(SIGINT,  "sig_handler");
pcntl_signal(SIGTERM, "sig_handler");
pcntl_signal(SIGHUP,  "sig_handler");

write_logls( "Starting" );

db_connect();

exit_if_errors();

$work_done = 1;

while( 1 ) {
    if ( !$work_done ) {
        write_logls( "no requests to process sleeping ${poll_sleep_seconds}s", 2 );
        sleep( $poll_sleep_seconds );
    }
    $work_done = 0;
    write_logls( "checking mysql", 2 );

    # get gfac.analysis info

    $query        = "SELECT gfacID, us3_db, cluster, status, queue_msg, UNIX_TIMESTAMP(time), time, autoflowAnalysisID from gfac.analysis";
        
    echoline();

    $outer_result = mysqli_query( $db_handle, $query );
    if ( mysqli_error( $db_handle ) != "" ) {
        write_logls( "read from mysql query='$query'. " . mysqli_error( $db_handle ) );
        if ( false !== strpos( mysqli_error( $db_handle ) , "MySQL server has gone away" ) ) {
            write_logls( "trying to reconnect..." );
            db_connect();
            write_logls( "connected..." );
            continue;
        }
    }

    if ( !$outer_result || !$outer_result->num_rows ) {
        if ( $outer_result ) {
            # $outer_result->free_result();
        }
        # if there is nothing in analysis, no need to check queue messages
        continue;
    }

    write_logls( "found $outer_result->num_rows in gfac.analysis", 2 );

    while ( $obj = mysqli_fetch_object( $outer_result ) ) {
        debug_json( "gfac.analysis", $obj );

        $gfac_id = $obj->{ "gfacID" };
        $us3_db  = $obj->{ "us3_db" };
        
        
        $query        = "SELECT HPCAnalysisResultID, HPCAnalysisRequestID, gfacID from $us3_db.HPCAnalysisResult where gfacID = '$gfac_id'";
        
        $inner_result = mysqli_query( $db_handle, $query );
        while ( $obj = mysqli_fetch_object( $inner_result ) ) {
            debug_json( "$us3_db.HPCAnalysisResult", $obj );
        }
    }

    # get gfac.queue_messages info
    $query        = "SELECT * from gfac.queue_messages";
        
    $outer_result = mysqli_query( $db_handle, $query );
    if ( mysqli_error( $db_handle ) != "" ) {
        write_logls( "read from mysql query='$query'. " . mysqli_error( $db_handle ) );
        if ( false !== strpos( mysqli_error( $db_handle ) , "MySQL server has gone away" ) ) {
            write_logls( "trying to reconnect..." );
            db_connect();
            write_logls( "connected..." );
            continue;
        }
    }

    if ( !$outer_result || !$outer_result->num_rows ) {
        if ( $outer_result ) {
            # $outer_result->free_result();
        }
        # if there is nothing in analysis, no need to check queue messages
        continue;
    }
    
    write_logls( "found $outer_result->num_rows in gfac.queue_messages", 2 );

#    while ( $obj = mysqli_fetch_object( $outer_result ) ) {
#        debug_json( "gfac.queue_messages", $obj );
#    }

    

}
