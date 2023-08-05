<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
include "$us3bin/listen-config.php";
include "$class_dir/experiment_status.php";

# ********* start user defines *************

# the polling interval
$poll_sleep_seconds = 60;

# define admin_email to override listen-config.php's default
$admin_email = "emre.brookes@umt.edu";

# resend email after hours

$resend_hours = 24 * 60;
# $resend_hours = 1 / 120;

# logging_level 
# 0 : minimal messages (expected value for production)
# 1 : add some db messages
# 2 : add idle polling messages
# 3 : debug messages
# 4 : way too many debug messages
$logging_level      = 2;


# ********* end user defines ***************

# ********* start admin defines *************
# these should only be changed by developers
$db                                = "gfac";
$processing_key                    = "submitted";
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
        write_log( $msg );
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

function timestamp( $msg = "" ) {
    return date( "Y-m-d H:i:s " ) . $msg;
}

function dt_duration_minutes ( $datetime_start, $datetime_end ) {
    return ($datetime_end->getTimestamp() - $datetime_start->getTimestamp()) / 60;
}

function dt_now () {
    return new DateTime( "now" );
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

# find databases with autoflowGMPReportEsign

$trylimsdbs = [];
$query  = "SHOW DATABASES";
$result = mysqli_query( $db_handle, $query );
while ( $obj =  mysqli_fetch_object( $result ) ) {
    $this_db = $obj->{ 'Database' };
    # echo "Database: $this_db\n";
    if ( substr( $this_db, 0, 8 ) === "uslims3_" ) {
        $trylimsdbs[] = $this_db;
        # echo "added to limsdbs\n";
    }
}

$limsdbs = [];

foreach ( $trylimsdbs as $v ) {
    write_logls( "checking db $v for autoflowGMPReportEsign tables", 4 );
    $success = true;

    # check db for ${submit_request_table_name}
    if ( $success ) {
        $query  = "select count(*) from ${v}.autoflowGMPReportEsign";
        $result = mysqli_query( $db_handle, $query );
        $error  = '';
        if ( !$result ) {
            $success = false;
            $error  = "table autoflowGMPReportEsign can not be queried";
        }
    }

    if ( $success ) {
        write_logls( "added db $v for eSign email submission control", 1 );
        $limsdbs[] = $v;
    } else {
        write_logls( "ignoring db ${v}. reason: ${error}", 1 );
    }
}
unset( $trylimsdbs );

if ( !count( $limsdbs ) ) {
    error_add( "found no databases with autoflowGMPReportEsign" );
}

exit_if_errors();

# object to store sent times
$sent_times = (object) [];

while( 1 ) {
    write_logls( "checking mysql", 2 );

    $work_done = 0;

    foreach ( $limsdbs as $lims_db ) {
        write_logls( "checking mysql db ${lims_db}", 2 );
        
        # read from mysql - $submit_request_table_name
        $query        = "SELECT ID, autoflowID, autoflowName, eSignStatusJson FROM ${lims_db}.autoflowGMPReportEsign where eSignStatusJson RLIKE '{\"to_sign\":\\\\[\"\\\\d.*'";
        
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
            continue;
        }

        write_logls( "found $outer_result->num_rows in autoflowGMPReportEsign to check on db ${lims_db}", 2 );

        while ( $obj = mysqli_fetch_object( $outer_result ) ) {
            if ( !isset( $obj->ID ) ) {
                write_logls( "critical: no id found in mysql result!" );
                continue;
            }
            $ID                 = $obj->ID;
            $autoflowID         = $obj->autoflowID;
            $autoflowName       = $obj->autoflowName;

            try {
                $eSignStatusJson    = json_decode( $obj->eSignStatusJson );
            } catch ( Exception $e ) {
                write_logls( "JSON decode error db $lims_db autoflowGMPReportEsign.ID $ID", 0 );
                continue;
            }

            # debug_json( "after fetch, decode", $eSignStatusJson );

            if ( !isset( $eSignStatusJson->to_sign ) ) {
                write_logls( "db $lims_db autoflowGMPReportEsign.ID $ID eSignStatusJson->to_sign missing", 0 );
                continue;
            }

            if ( !is_array( $eSignStatusJson->to_sign ) ) {
                write_logls( "db $lims_db autoflowGMPReportEsign.ID $ID eSignStatusJson->to_sign not an array", 0 );
                continue;
            }

            if ( !count( $eSignStatusJson->to_sign ) ) {
                # nothing to do
                continue;
            }

            $personID = explode( ".", $eSignStatusJson->to_sign[0] )[0];

            if ( !strlen( $personID ) ) {
                write_logls( "db $lims_db autoflowGMPReportEsign.ID $ID eSignStatusJson->to_sign personID empty", 3 );
                continue;
            }

            write_logls( "personID is $personID", 3 );

            ## key for checking if message was already sent and how long ago

            $sent_key = "$lims_db:$ID:$personID";

            if ( isset( $sent_times->{ $sent_key } ) ) {
                if ( dt_duration_minutes( $sent_times->{ $sent_key }, dt_now() ) < $resend_hours * 60 ) {
                    ## do no resent
                    write_logls( "sent_key $sent_key, sent too recently, skipped", 3 );
                    continue;
                }
            }

            $query         = "SELECT email, fname, lname FROM ${lims_db}.people where personID = $personID";
            $person_result = mysqli_query( $db_handle, $query );
            if ( !$person_result ) {
                write_logl( "db query failed : $query\ndb query error: " . mysqli_error($db_handle) . "\n" );
                continue;
            }
            $person_obj = mysqli_fetch_object( $person_result );

            # debug_json( "person_result", $person_obj );

            ## build up message

            $subject = "[$lims_db@$host_name] : GMP Report e-signature requested ($autoflowID)";

            $body    =
                "$person_obj->fname $person_obj->lname,\n"
                . "\n"
                . "Your e-signature is requested for -\n"
                . "\n"
                . "Host          : $host_name\n"
                . "Database      : $lims_db\n"
                . "Autoflow ID   : $autoflowID\n"
                . "Autoflow Name : $autoflowName\n"
                . "\n"
                ;

            $mailto = $person_obj->email;
            $mailto = "emre.brookes@umt.edu";

            $headers  = 
                "From: GMP e-signature request $host_name<noreply@$host_name>\n"
                ;

            if ( 1 ) {
                if (
                    !mail(
                         $mailto
                         ,$subject
                         ,$body
                         ,$headers )
                    ) {
                    write_logls( "mail to $mailto failed : subject $subject", 0 );
                    if ( !mail(
                              $admin_email
                              ,"ERROR: ESig Mail failure : $subject"
                              ,"$mailto\n--------mail body follows------\n$body"
                              ,$headers )
                        ) {
                        write_logls( "ERROR admin mail to $admin_mail failed : subject $subject", 0 );
                    }
                }
            } else {
                echo "mailing skipped for testing - $mailto - $subject\n";
            }

            ## set even if mail failed - if mail failed, don't want to keep trying to send
            
            $sent_times->{ $sent_key } = dt_now();
            $work_done = 1;
        }
    }

    # echo "================================================================================\n";
    # echo timestamp(). "\n";
    # echo "================================================================================\n";
    
    if ( !$work_done ) {
        write_logls( "no requests to process sleeping ${poll_sleep_seconds}s", 2 );
        sleep( $poll_sleep_seconds );
    }
}
