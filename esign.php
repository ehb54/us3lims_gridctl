<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
include "$us3bin/listen-config.php";
include "$class_dir/experiment_status.php";

# ********* start user defines *************

# the polling interval
$poll_sleep_seconds = 120;

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
$do_send_emails                    = true;
# $do_send_emails                    = false;
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
        $query  = "select count(*) from ${v}.autoflowGMPReport";
        $result = mysqli_query( $db_handle, $query );
        $error  = '';
        if ( !$result ) {
            $success = false;
            $error  = "table autoflowGMPReport can not be queried";
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
        # 
        # can't do the following two this since SME now also needs checking
        # $query        = "SELECT ID, autoflowID, autoflowName, eSignStatusJson FROM ${lims_db}.autoflowGMPReportEsign where eSignStatusJson RLIKE '{\"to_sign\":\\\\[\"\\\\d.*'";
        # $query        = "SELECT ID, autoflowID, autoflowName, eSignStatusJson, smeListJson FROM ${lims_db}.autoflowGMPReportEsign where eSignStatusAll != 'YES'";
        $query        = "SELECT ID, autoflowID, autoflowName, eSignStatusJson, smeListJson FROM ${lims_db}.autoflowGMPReportEsign";
        
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
            write_logls( "found record to process : ID $ID autoflowName $autoflowName", 3 );

            $GMPReportID        = NULL;
            
            try {
                $smeListJson        = json_decode( $obj->smeListJson );
            } catch ( Exception $e ) {
                $smeListJson        = NULL;
            }

            if ( !is_null( $smeListJson ) && !is_array( $smeListJson ) ) {
                $smeListJson        = NULL;
                write_logls( "Warning: db $lims_db autoflowGMPReportEsign.ID $ID smeListJson is not NULL and not an array", 0 );
            }

            if ( !is_null( $smeListJson ) && count( $smeListJson ) ) {
                $smeListJson_cleaned  = preg_grep ('/^\s*\d+\s*\./', $smeListJson);
                if ( count( $smeListJson_cleaned ) != count( $smeListJson ) ) {
                    write_logls( "Warning: db $lims_db autoflowGMPReportEsign.ID $ID smeListJson stripped of non-conformant elements ", 0 );
                    $smeListJson = $smeListJson_cleaned;
                }
            }

            try {
                $eSignStatusJson    = json_decode( $obj->eSignStatusJson );
            } catch ( Exception $e ) {
                write_logls( "JSON decode error db $lims_db autoflowGMPReportEsign.ID $ID", 0 );
                continue;
            }

            ## process all smeEmails if needed

            if ( !is_null( $smeListJson ) && count( $smeListJson ) ) {
                ## anything already signed without an sme_notified_datetime
                if ( isset( $eSignStatusJson->signed ) && count( $eSignStatusJson->signed ) ) {
                    if ( $GMPReportID === NULL ) {
                        $query = "select ID from ${lims_db}.autoflowGMPReport where autoflowHistoryID=$autoflowID";
                        write_logls( "query: '$query'", 4 );
                        $id_result = mysqli_query( $db_handle, $query );
                        if ( !$id_result ) {
                            write_logls( "read from mysql query='$query'. " . mysqli_error( $db_handle ) );
                            $GMPReportID = "autoflowID $autoflowID";
                        } else {
                            $id_obj =  mysqli_fetch_object( $id_result );
                            if ( !$id_obj ) {
                                $GMPReportID = "autoflowID $autoflowID";
                                write_logls( "ID $ID no GMPReportID found, using $GMPReportID", 3 );
                            } else {
                                $GMPReportID = $id_obj->ID;
                                write_logls( "ID $ID got GMPReportID $GMPReportID", 3 );
                            }
                        }
                    }
                                         
                    write_logls( "ID $ID sme processing needs checking", 3 );

                    ## do any signed not have the smeNotifiedDateTime

                    $smebody = 
                        "New e-signature(s) registered for -\n"
                        . "\n"
                        . "Host          : $host_name\n"
                        . "Database      : $lims_db\n"
                        . "ID            : $GMPReportID\n"
                        . "Run Name      : $autoflowName\n"
                        . "\n"
                        ;

                    $any_sme_todo         = false;
                    $esigner_now_notified = [];
                    foreach ( $eSignStatusJson->signed as $k => $v ) {
                        foreach ( $v as $k2 => $v2 ) {
                            $esigner_now_notified[ $k2 ] = true;
                            if ( !isset( $v2->smeNotifiedDateTime ) ) {
                                $smebody .=
                                    "----------------------------------------\n"
                                    . "e-Signer      : $k2\n"
                                    . "Comment       : $v2->Comment\n"
                                    . "Date & Time   : $v2->timeDate\n"
                                    ;
                                $any_sme_todo = true;
                                $v2->smeNotifiedDateTime = "test";
                            }
                        }
                    }
                    $smebody .= "----------------------------------------\n";
                    
                    write_logls( "sme body:\n$smebody", 3 );

                    if ( $any_sme_todo ) {
                        $smeEmails = [];
                        foreach ( $smeListJson as $k => $v ) {
                            $smeID       = explode( ".", $v )[0];
                            $query       = "SELECT email FROM ${lims_db}.people where personID = $smeID";
                            $sme_result  = mysqli_query( $db_handle, $query );
                            if ( !$sme_result ) {
                                write_logl( "db query failed : $query\ndb query error: " . mysqli_error($db_handle) . "\n" );
                                continue;
                            }
                            $sme_obj     = mysqli_fetch_object( $sme_result );
                            $smeEmails[] = $sme_obj->email;
                        }
                        write_logls( "smeEmails : " . implode( " , ", $smeEmails ), 3 );

                        ## send SME email

                        $mailto = implode( ",", $smeEmails );
                        # $mailto = "emre.brookes@umt.edu";

                        $headers  = 
                            "From: GMP e-signature signed $host_name<noreply@$host_name>\n"
                            ;

                        $subject = "[$lims_db@$host_name] : GMP Report e-signature signed ($GMPReportID)";

                        $now = date("m-d-Y H:i:m");

                        if ( $do_send_emails ) {
                            if (
                                !mail(
                                     $mailto
                                     ,$subject
                                     ,$smebody
                                     ,$headers )
                                ) {
                                write_logls( "mail to $mailto failed : subject $subject", 0 );
                                if ( !mail(
                                          $admin_email
                                          ,"ERROR: ESig SME Mail failure : $subject"
                                          ,"SME mail failure\nMail To: '$mailto'\n--------mail body follows------\n$smebody"
                                          ,$headers )
                                    ) {
                                    write_logls( "ERROR admin mail to $admin_mail failed : subject $subject", 0 );
                                }
                            }
                        } else {
                            echo "mailing skipped for testing - $mailto - $subject\n";
                        }
                        
                        ## now update the db with lock

                        if ( mysqli_begin_transaction( $db_handle ) ) {
                            $query = "SELECT eSignStatusJson FROM ${lims_db}.autoflowGMPReportEsign where ID = $ID for UPDATE";
                            $sme_update_result = mysqli_query( $db_handle, $query );
                            if ( !$sme_update_result ) {
                                write_logl( "db query failed : $query\ndb query error: " . mysqli_error($db_handle) . "\n" );
                            } else {
                                $sme_update_obj = mysqli_fetch_object( $sme_update_result );

                                $failed = false;
                                try {
                                    $update_eSignStatusJson    = json_decode( $sme_update_obj->eSignStatusJson );
                                } catch ( Exception $e ) {
                                    write_logls( "JSON decode error db $lims_db autoflowGMPReportEsign.ID $ID", 0 );
                                    $failed = true;
                                }

                                if ( !$failed ) {
                                    foreach ( $update_eSignStatusJson->signed as $k => $v ) {
                                        foreach ( $v as $k2 => $v2 ) {
                                            if ( array_key_exists( $k2, $esigner_now_notified ) ) {
                                                $v2->smeNotifiedDateTime = $now;
                                            }
                                        }
                                    }

                                    $query =
                                        "UPDATE ${lims_db}.autoflowGMPReportEsign set eSignStatusJson='"
                                        . json_encode( $update_eSignStatusJson )
                                        . "' where ID = $ID"
                                        ;
                                    
                                    $sme_commit_result = mysqli_query( $db_handle, $query );
                                    
                                    if ( !$sme_commit_result ) {
                                        write_logl( "db query failed : $query\ndb query error: " . mysqli_error($db_handle) . "\n" );
                                    }
                                }
                            }
                            mysqli_commit( $db_handle );
                        }
                        
                    } ## $any_sme_todo
                }
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

            write_logls( "ID is $ID personID is $personID", 3 );

            ## key for checking if message was already sent and how long ago

            $sent_key = "$lims_db:$ID:$personID";

            if ( isset( $sent_times->{ $sent_key } ) ) {
                if ( dt_duration_minutes( $sent_times->{ $sent_key }, dt_now() ) < $resend_hours * 60 ) {
                    ## do no resent
                    write_logls( "sent_key $sent_key, sent too recently, skipped", 3 );
                    continue;
                }
            }

            ## ok, we are going to send a notification email
            $query         = "SELECT email, fname, lname FROM ${lims_db}.people where personID = $personID";
            $person_result = mysqli_query( $db_handle, $query );
            if ( !$person_result ) {
                write_logl( "db query failed : $query\ndb query error: " . mysqli_error($db_handle) . "\n" );
                continue;
            }
            $person_obj = mysqli_fetch_object( $person_result );

            # debug_json( "person_result", $person_obj );

            ## build up message

            if ( $GMPReportID === NULL ) {
                $query = "select ID from ${lims_db}.autoflowGMPReport where autoflowHistoryID=$autoflowID";
                $id_result = mysqli_query( $db_handle, $query );
                if ( !$id_result ) {
                    write_logls( "read from mysql query='$query'. " . mysqli_error( $db_handle ) );
                    $GMPReportID = "autoflowID $autoflowID";
                } else {
                    $id_obj =  mysqli_fetch_object( $id_result );
                    if ( !$id_obj ) {
                        $GMPReportID = "autoflowID $autoflowID";
                        write_logls( "ID $ID no GMPReportID found, using $GMPReportID", 3 );
                    } else {
                        $GMPReportID = $id_obj->ID;
                        write_logls( "ID $ID got GMPReportID $GMPReportID", 3 );
                    }
                }
            }

            $subject = "[$lims_db@$host_name] : GMP Report e-signature requested ($GMPReportID)";

            $body    =
                "$person_obj->fname $person_obj->lname,\n"
                . "\n"
                . "Your e-signature is requested for -\n"
                . "\n"
                . "Host          : $host_name\n"
                . "Database      : $lims_db\n"
                . "ID            : $GMPReportID\n"
                . "Run Name      : $autoflowName\n"
                . "\n"
                . "To sign - Use the 'us_esigner_gmp' program via the Terminal or the Icon (if available)"
                . "\n"
                ;

            $mailto = $person_obj->email;
            # $mailto = "emre.brookes@umt.edu";

            $headers  = 
                "From: GMP e-signature request $host_name<noreply@$host_name>\n"
                ;

            if ( $do_send_emails ) {
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
                              ,"Esig reminder mail failure\nMail To: '$mailto'\n--------mail body follows------\n$body"
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
