<?php

# begin to build up data in replacement of queue_setup_1,2,3.php in prep for 2DSA_? submit

$self = __FILE__;
    
$notes = <<<__EOD
usage: $self db ID

1. finds db.AnalysisRequest with a RequestID = ID
2. finds associated db.analysisprofile
3. loads any related tables needed to build up \$_SESSION
4. converts XML -> JSON to setup the job

__EOD;

if ( count( $argv ) != 3 ) {
    echo $notes;
    exit;
}

$us3bin = exec( "ls -d ~us3/lims/bin" );
include "$us3bin/listen-config.php";
include "$class_dir/experiment_status.php";

# ********* start user defines *************

# the polling interval
$poll_sleep_seconds = 30;

# logging_level 
# 0 : minimal messages (expected value for production)
# 1 : add detailed db messages
# 2 : add idle polling messages
$logging_level      = 3;
    
$dumpfilebase = "/home/us3/lims/etc/submit";
if ( !is_dir( $dumpfilebase ) ) {
    mkdir( $dumpfilebase );
}

# www_uslims3 should likely be in listen_config.php
$www_uslims3   = "/srv/www/htdocs/uslims3";
# ********* end user defines ***************

# ********* start admin defines *************
# these should only be changed by developers
$db                                = "gfac";
$submit_request_table_name         = "autoflowAnalysis";
$submit_request_history_table_name = "autoflowAnalysisHistory";
$id_field                          = "requestID";
$processing_key                    = "submitted";
# ********* end admin defines ***************

$cli_errors = [];

function quote_fix( $str ) {
    return str_replace( "'", "*", $str );
}

function check_cli_errors() {
    global $cli_errors;
    if ( count( $cli_errors ) ) {
        echo implode( "\n", $cli_errors ) . "\n";
        fail_job();
        exit(-1);
    }
}

function fail_job() {
    global $lims_db;
    global $cli_errors;
    global $submit_request_table_name;
    global $id_field;
    global $ID;
    global $db_handle;
    global $self;
    
    $use_cli_errors = preg_replace( '/ERROR: \S*\.php /', 'ERROR: ', $cli_errors );
    
    $query  = "UPDATE ${lims_db}.${submit_request_table_name} SET status='FAILED', statusMsg='" . implode( '; ', quote_fix( $use_cli_errors ) ) . "' WHERE ${id_field} = ${ID}";
    $result = mysqli_query( $db_handle, $query );
    
    if ( !$result ) {
        write_logl( "$self: error updating table ${submit_request_table_name} ${id_field} ${ID} status FAILED. query $query", 0 );
    }
}

function write_logl( $msg, $this_level = 0 ) {
    global $logging_level;
    global $self;
    if ( $logging_level >= $this_level ) {
        # echo "${self}: " . $msg . "\n";
        write_log( "${self}: " . $msg );
    }
}

function error( $msg, $fail_job = true ) {
    write_logl( $msg );

    global $lims_db;
    global $submit_request_table_name;
    global $id_field;
    global $ID;
    global $db_handle;
    global $self;
    
    if ( $fail_job ) {
        $qfmsg = quote_fix( $msg );
        $query  = "UPDATE ${lims_db}.${submit_request_table_name} SET status='FAILED', statusMsg='Error submitting job: $qfmsg' WHERE ${id_field} = ${ID}";
        $result = mysqli_query( $db_handle, $query );
    
        if ( !$result ) {
            write_logl( "$self: error updating table ${submit_request_table_name} ${id_field} ${ID} status FAILED. query $query", 0 );
        }
    }
    exit(-1);
}

function debug_json( $msg, $json ) {
    return;
#    echo "$msg\n";
#    echo json_encode( $json, JSON_PRETTY_PRINT );
#    echo "\n";
}

function db_obj_result( $db_handle, $query ) {
    $result = mysqli_query( $db_handle, $query );

    if ( !$result || !$result->num_rows ) {
        if ( $result ) {
            # $result->free_result();
        }
        write_logl( "db query failed : $query" );
        exit;
    }

    if ( $result->num_rows > 1 ) {
        write_logl( "WARNING: db query returned " . $result->num_rows . " rows : $query" );
    }    

    return mysqli_fetch_object( $result );
}

function truestr( $val ) {
    return $val ? "true" : "false";
}

$lims_db = $argv[ 1 ];
$ID      = $argv[ 2 ];

if ( !is_dir( $dumpfilebase ) ) {
    write_logl( "ERROR $dumpfilebase is not a directory, logs will not be stored!\n" );
}

$dumpfile = "${dumpfilebase}/$lims_db-$ID.txt";
global $dumpfile;
if ( file_exists( $dumpfile ) ) {
    unlink( $dumpfile );
}

write_logl( "Starting" );

do {
    $db_handle = mysqli_connect( $dbhost, $user, $passwd, $db );
    if ( !$db_handle ) {
        write_logl( "could not connect to mysql: $dbhost, $user, $db. Will retry in ${poll_sleep_seconds}s" );
        sleep( $poll_sleep_seconds );
    }
} while ( !$db_handle );

write_logl( "connected to mysql: $dbhost, $user, $db.", 2 );

# get AutoflowAnalysis record

$autoflowanalysis = db_obj_result( $db_handle, 
                                   "SELECT clusterDefault, tripleName, filename, invID, aprofileGUID, statusJson FROM ${lims_db}.${submit_request_table_name} WHERE ${id_field}=$ID" );

$cluster    = $autoflowanalysis->{'clusterDefault'};
$statusJson = json_decode( $autoflowanalysis->{"statusJson"} );
debug_json( "after fetch, decode", $statusJson );
        
if ( !isset( $statusJson->{ $processing_key } ) ||
     empty( $statusJson->{ $processing_key } ) ) {
    write_logl( "AutoflowAnalysis db ${lims_db} ${id_field} $ID is NOT ${processing_key}", 1 );
    exit;
}

$stage  = $statusJson->{ $processing_key };
$triple = str_replace( ".Interference", ".660", $autoflowanalysis->{ 'tripleName' } );
$invID  = $autoflowanalysis->{ 'invID' };

write_logl( "job $ID found. stage to submit " .  json_encode( $stage, JSON_PRETTY_PRINT ) );

# get analysisprofile record

$aprofileguid = $autoflowanalysis->{ 'aprofileGUID' };

$analysisprofile = db_obj_result( $db_handle, 
                                  "SELECT * FROM ${lims_db}.analysisprofile WHERE aprofileGUID='${aprofileguid}'" );

write_logl( "aprofileGUID $aprofileguid found", 3 );

$xmljson = json_decode( json_encode( simplexml_load_string( $analysisprofile->{ 'xml' } ) ) );

debug_json( "analysisprofile's xml in json:", $xmljson );

# sanity checks

$xmljsonfilename = $xmljson->{ 'analysis_profile' }->{ '@attributes' }->{ 'name' };
$xmljsonguid     = $xmljson->{ 'analysis_profile' }->{ '@attributes' }->{ 'guid' };
$aprofilename    = $analysisprofile->{'name'};
$aaname          = $autoflowanalysis->{'filename'};

if ( $xmljsonfilename != $aprofilename ||
     $xmljsonfilename != $aaname ) {
    write_logl( "table name inconsistencies $xmljsonfilename vs $aprofilename vs $aaname" );
}

write_logl( "analysisprofile filename $aaname triplename $triple" );

# $autoflow = db_obj_result( $db_handle, 
#                           "SELECT * FROM ${lims_db}.autoflow WHERE aprofileGUID='${aprofileguid}'" );

$rawdata = db_obj_result( $db_handle,
                          "select rawDataID, filename from ${lims_db}.rawData where filename like '${aaname}.%.${triple}%'" );

echo json_encode( $rawdata, JSON_PRETTY_PRINT ) . "\n";

$editdata = db_obj_result( $db_handle,
                          "select editedDataID, filename from ${lims_db}.editedData where filename like '${aaname}.%.${triple}%'" );

echo json_encode( $editdata, JSON_PRETTY_PRINT ) . "\n";

$person = db_obj_result( $db_handle,
                          "select * from ${lims_db}.people where personID='${invID}'" );

echo json_encode( $person, JSON_PRETTY_PRINT ) . "\n";

$clusterAuth = explode( ":", $person->{'clusterAuthorizations'} );

echo "personid:" .  $person->{'personID'} . "\n";

$php_base          = "${www_uslims3}/${lims_db}";
chdir( $php_base );

set_include_path( get_include_path() . PATH_SEPARATOR . $php_base );

# person overrides
$person->{'userlevel'} = 2;
$person->{'email'}     = "us3-admin@biophysics.uleth.ca";

# ************* queue_setup_1 ***************

$php_queue_setup_1 = "${php_base}/queue_setup_1.php";
$php_queue_setup_2 = "${php_base}/queue_setup_2.php";
$php_queue_setup_3 = "${php_base}/queue_setup_3.php";
echo "preparing to call $php_queue_setup_1\n";

$_SESSION = [];

$_SESSION[ 'id' ]               = $person->{'personID'};
$_SESSION[ 'loginID' ]          = $person->{'personID'};
$_SESSION[ 'firstname' ]        = $person->{'fname'};
$_SESSION[ 'lastname' ]         = $person->{'lname'};
$_SESSION[ 'phone' ]            = $person->{'phone'};
$_SESSION[ 'email' ]            = $person->{'email'};
$_SESSION[ 'submitter_email' ]  = $person->{'email'};
$_SESSION[ 'userlevel' ]        = $person->{'userlevel'};
$_SESSION[ 'instance' ]         = $lims_db;
$_SESSION[ 'user_id' ]          = $person->{'fname'} . "_" . $person->{'lname'} . "_" . $person->{'personGUID'} ;
$_SESSION[ 'advancelevel' ]     = $person->{'advancelevel'};
$_SESSION[ 'clusterAuth' ]      = $clusterAuth;
$_SESSION[ 'gwhostid' ]         = $host_name;

echo "session now is:\n" . json_encode( $_SESSION, JSON_PRETTY_PRINT ) . "\n";

$_REQUEST = [];
$_REQUEST[ 'submitter_email' ]  = $person->{'email'};
$_REQUEST[ 'expIDs' ]           = [ $rawdata->{'rawDataID' } ];
$_REQUEST[ 'cells' ]            = [ $rawdata->{'rawDataID' } . ":" . $rawdata->{ 'filename' } ]; 
$_REQUEST[ 'next' ]             = "Add to Queue";

$_POST = $_REQUEST;

echo "request/post now is:\n" . json_encode( $_REQUEST, JSON_PRETTY_PRINT ) . "\n";

function dump_it( $str ) {
   global $dumpfile;
   file_put_contents( $dumpfile, $str, FILE_APPEND );
   chmod( $dumpfile, 0666 );
   return true;
}

ob_start( "dump_it" );
include( $php_queue_setup_1 );
while (ob_get_level()) ob_end_flush();
check_cli_errors();

# ************* queue_setup_2 ***************
# queue_setup_3 is chained by queue_setup_2

$_REQUEST[ 'save' ]             = 'Save Queue Information';

$_POST = $_REQUEST;

echo "preparing to call $php_queue_setup_2\n";
echo "session now is:\n" . json_encode( $_SESSION, JSON_PRETTY_PRINT ) . "\n";
echo "request/post now is:\n" . json_encode( $_REQUEST, JSON_PRETTY_PRINT ) . "\n";
ob_start( "dump_it" );
include( $php_queue_setup_2 );
while (ob_get_level()) ob_end_flush();
check_cli_errors();

echo "queue is prepared\n";

# ************* process xml and determine job type and parameters ***************

if ( $stage == "PCSA" ) {
    $paramgroup = "p_pcsa";
} else {
    $paramgroup = "p_2dsa";
}

$jobkey = "job_" . strtolower( $stage );

if ( !isset( $xmljson->{'analysis_profile'} ) ) {
    error( "analysis profile's xml does not contain an 'analysis_profile' key" );
}

if ( !isset( $xmljson->{'analysis_profile'}->{$paramgroup} ) ) {
    error( "analysis profile's xml does not contain an 'analysis_profile'->'$paramgroup' key" );
}

if ( !isset( $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'} ) ) {
    error( "analysis profile's xml does not contain 'analysis_profile'->'channel_parms'" );
}    

if ( is_array( $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'} ) &&
     !count( $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'} ) ) {
    error( "analysis profile's xml does not contain an 'analysis_profile'->'channel_parms' key with a nonzero size" );
}    

if ( is_array( $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'} ) ) {
    if ( !isset( $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'}[0]->{'@attributes'} ) ) {
        error( "analysis profile's xml's 'analysis_profile'->'$paramgroup'->'channel_parms' first entry does not contain '\@attributes'" );
    }
    $channel_attributes = $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'}[0]->{'@attributes'};
    debug_json( "channel attributes", $channel_attributes );
} else {
    if ( !isset( $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'}->{'@attributes'} ) ) {
        error( "analysis profile's xml's 'analysis_profile'->'$paramgroup'->'channel_parms'  does not contain '\@attributes'" );
    }
    $channel_attributes = $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'}->{'@attributes'};
    debug_json( "channel attributes", $channel_attributes );
}

if ( $stage != "PCSA" ) {
    if ( !isset( $xmljson->{'analysis_profile'}->{$paramgroup}->{$jobkey} ) ) {
        error( "analysis profile's xml's 'analysis_profile'->'$paramgroup'->'$jobkey' is missing" );
    }
    if ( !isset( $xmljson->{'analysis_profile'}->{$paramgroup}->{$jobkey}->{'@attributes'} ) ) {
        error( "analysis profile's xml's 'analysis_profile'->'$paramgroup'->'$jobkey'->'\@attributes' is missing" );
    }
    $job_attributes = $xmljson->{'analysis_profile'}->{$paramgroup}->{$jobkey}->{'@attributes'};
    debug_json( "job attributes", $job_attributes );

    if ( isset( $job_attributes->{'interactive'} ) ) {
        $query  = "UPDATE ${lims_db}.${submit_request_table_name} SET status='WAIT', statusMsg='Waiting for manual stage $stage to complete.' WHERE ${id_field} = ${ID}";
        $result = mysqli_query( $db_handle, $query );
        
        if ( !$result ) {
            write_logl( "$self: error updating table ${submit_request_table_name} ${id_field} ${ID} statusJson. query $query", 0 );
        } else {
            write_logl( "$self: ${submit_request_table_name} now interactive ${id_field} $ID stage " . json_encode( $stage ), 1 );
        }
        exit();
    }
}

if ( $cluster == "localhost" ) {
    $cluster = "us3iab-node0";
}
$queue = "batch";

if ( $stage == "PCSA" ) {
    $conv_pcsa_keys = [
        "channel"              => "_ignore_"
        ,"curve_type"          => "_special_handling_"
        ,"x_type"              => "_special_handling_"
        ,"y_type"              => "_special_handling_"
        ,"z_type"              => "_special_handling_"
        ,"x_min"               => "x_min"
        ,"x_max"               => "x_max"
        ,"y_min"               => "y_min"
        ,"y_max"               => "y_max"
        ,"z_value"             => "z_value"
        ,"variations_count"    => "vars_count"
        ,"gridfit_iterations"  => "gfit_iterations"
        ,"curve_reso_points"   => "curves_points"
        ,"noise"               => "_ignore_"
        ,"regularization"      => "_special_handling_"
        ,"reg_alpha"           => "tikreg_alpha"
        ,"mc_iterations"       => "mc_iterations"
        ];


    $defaults_pcsa = [
        "curve_type"           => "IS"
        ,"solute_type"         => "013.skv"
        ,"x_min"               => "1"
        ,"x_max"               => "10"
        ,"y_min"               => "1"
        ,"y_max"               => "4"
        ,"z_value"             => "0"
        ,"vars_count"          => "10"
        ,"hl_vars_count"       => "100"
        ,"gfit_iterations"     => "3"
        ,"thr_deltr_ratio"     => "0.0001"
        ,"curves_points"       => "200"
        ,"tikreg_option"       => "0"
        ,"tikreg_alpha"        => "0.275"
        ,"mc_iterations"       => "1"
        ,"tinoise_option"      => "0"
        ,"rinoise_option"      => "0"
        ,"debug_level-value"   => "0"
        ,"debug_text-value"    => ""
        ,"simpoints-value"     => "200"
        ,"band_volume-value"   => "0.015"
        ,"radial_grid"         => "0"
        ,"time_grid"           => "1"
        ,"cluster"             =>  "${host_name}:${cluster}:${queue}"
        ,"TIGRE"               => "Submit"
        ];

    $pcsa_curve_types = [ "SL", "IS", "DS", "All", "HL", "2O" ];
    $pcsa_solute_types = [
        "s - f/f0 - vbar"     => "013.skv"
        ,"s - mw - vbar"      => "023.swv"
        ,"s - vbar - f/f0"    => "031.svk"
        ,"s - vbar - mw"      => "032.svw"
        ,"s - D - vbar"       => "043.sdv"
        ,"f/f0 - s - vbar"    => "103.ksv"
        ,"f/f0 - mw - vbar"   => "123.kwv"
        ,"f/f0 - vbar - mw"   => "132.kvw"
        ,"f/f0 - D - vbar"    => "143.kdv"
        ,"mw - s - vbar"      => "203.wsv"
        ,"mw - f/f0 - vbar"   => "213.wkv"
        ,"mw - vbar - f/f0"   => "231.wvk"
        ,"mw - D - vbar"      => "243.wdv"
        ,"vbar - s - f/f0"    => "301.vsk"
        ,"vbar - s - mw"      => "302.vsw"
        ,"vbar - f/f0 - mw"   => "312.vkv"
        ,"vbar - mw - f/f0"   => "321.vwk"
        ,"vbar - D - f/f0"    => "341.vdv"
        ,"vbar - D - mw"      => "342.vdv"
        ,"D - s - vbar"       => "403.dsv"
        ,"D - k - vbar"       => "413.dkv"
        ,"D - mw - vbar"      => "423.dkv"
        ,"D - vbar - f/f0"    => "431.dvk"
        ,"D - vbar - mw"      => "432.dvw"
        ];        

    $_REQUEST = $defaults_pcsa;

    $all_attributes = (object) array_merge( (array) $channel_attributes, (array) $job_attributes );
    debug_json( "pcsa: all attributes", $all_attributes );

    $solute_types = [];

    foreach ( $all_attributes as $k => $v ) {
        if ( !isset( $conv_pcsa_keys[ $k ] ) ) {
            write_logl( "job attribute '$k' missing in conversion table, ignored" );
            continue;
        }
        $ku = $conv_pcsa_keys[ $k ];
        if ( $ku == '_ignore_' ) {
            continue;
        }
        if ( $ku != '_special_handling_' ) {
            $_REQUEST[ $ku ] = $v;
            continue;
        }
        if ( $k == 'curve_type' ) {
            if ( !in_array( $v, $pcsa_curve_types ) ) {
                error( "pcsa curve_type $v is not supported" );
            }
            $_REQUEST[ $k ] = $v;
            continue;
        }
        if ( $k == 'x_type' ||
             $k == 'y_type' ||
             $k == 'z_type' ) {
            $solute_types[ $k ] = $v;
            continue;
        }
        if ( $k = 'regularization' ) {
            if ( $v == "none" ) {
                $_REQUEST[ 'tikreg_option' ] = 0;
                continue;
            }
            error( "pcsa: unknown/unsupported regularization option $v" );
        }
        error( "internal error: no special handling code for attribute '$k'" );
    }

    if ( !array_key_exists( "x_type", $solute_types ) ||
         !array_key_exists( "y_type", $solute_types ) ||
         !array_key_exists( "z_type", $solute_types ) ) {
        error( "pcsa: x_type, y_type & z_type are not all defined in the xml" );
    }

    $solute_type =
        $solute_types["x_type"] . " - " .
        $solute_types["y_type"] . " - " .
        $solute_types["z_type"]
        ;

    if ( !array_key_exists( $solute_type, $pcsa_solute_types ) ) {
        error( "pcsa: unknown solute_type '$solute_type'" );
    }

    $_REQUEST[ "solute_type" ] = $pcsa_solute_types[ $solute_type ];

    debug_json( "pcsa: \$_REQUEST", $_REQUEST );

    $_POST = $_REQUEST;

    $php_pcsa_1 = "${php_base}/PCSA_1.php";
    $php_pcsa_2 = "${php_base}/PCSA_2.php";

    echo "preparing to call $php_pcsa_1\n";
    echo "session now is:\n" . json_encode( $_SESSION, JSON_PRETTY_PRINT ) . "\n";
    echo "request/post now is:\n" . json_encode( $_REQUEST, JSON_PRETTY_PRINT ) . "\n";
    ob_start( "dump_it" );
    include( $php_pcsa_1 );
    while (ob_get_level()) ob_end_flush();
    check_cli_errors();

} else {
    # all 2dsa methods
    
    $conv_2dsa_keys = [
        "s_min"              => "s_value_min"
        ,"s_max"             => "s_value_max"
        ,"s_gridpoints"      => "s_grid_points"
        ,"k_min"             => "ff0_min"
        ,"k_max"             => "ff0_max"
        ,"k_gridpoints"      => "ff0_grid_points"
        ,"max_iterations"    => "_special_handling_"
        ,"mc_iterations"     => "mc_iterations"
        ,"noise"             => "_special_handling_"
        ,"channel"           => "_ignore_"
        ,"run"               => "_ignore_"
        ,"fit_mb_select"     => "fit_mb_select"
        ,"meniscus_range"    => "meniscus_range"
        ,"meniscus_points"   => "meniscus_points"
        ];

    $defaults_2dsa = [
        "s_value_min"        =>  "1",
        "s_value_max"        =>  "10",
        "s_grid_points"      =>  "64",
        "ff0_min"            =>  "1",
        "ff0_max"            =>  "4",
        "ff0_grid_points"    =>  "64",
        "mc_iterations"      =>  "1",
        "tinoise_option"     =>  "0",
        "rinoise_option"     =>  "0",
        "fit_mb_select"      =>  "0",
        "meniscus_range"     =>  "0.03",
        "meniscus_points"    =>  "11",
        "iterations_option"  =>  "0",
        "max_iterations"     =>  "10",
        "debug_level-value"  =>  "0",
        "debug_text-value"   =>  "",
        "simpoints-value"    =>  "200",
        "band_volume-value"  =>  "0.015",
        "radial_grid"        =>  "0",
        "time_grid"          =>  "1",
        "cluster"            =>  "${host_name}:${cluster}:${queue}",
        "TIGRE"              =>  "Submit"
        ];    


    $_REQUEST = $defaults_2dsa;

    $all_attributes = (object) array_merge( (array) $channel_attributes, (array) $job_attributes );
    debug_json( "all attributes", $all_attributes );

    foreach ( $all_attributes as $k => $v ) {
        if ( !isset( $conv_2dsa_keys[ $k ] ) ) {
            write_logl( "job attribute '$k' missing in conversion table, ignored" );
            continue;
        }
        $ku = $conv_2dsa_keys[ $k ];
        if ( $ku == '_ignore_' ) {
            continue;
        }
        if ( $ku != '_special_handling_' ) {
            $_REQUEST[ $ku ] = $v;
            continue;
        }
        if ( $k == 'noise' ) {
            if ( $v == "(TI Noise)" ) {
                $_REQUEST[ 'tinoise_option' ] = "1";
                continue;
            }
            if ( $v == "(RI Noise)" ) {
                $_REQUEST[ 'rinoise_option' ] = "1";
                continue;
            }
            if ( $v == "(TI+RI Noise)" ) {
                $_REQUEST[ 'tinoise_option' ] = "1";
                $_REQUEST[ 'rinoise_option' ] = "1";
                continue;
            }
            error( "unknown '$k' value '$v'" );
        }
        if ( $k == 'max_iterations' ) {
            $_REQUEST[ $k ]                  = $v;
            $_REQUEST[ 'iterations_option' ] = "1";
            continue;
        }
        error( "internal error: no special handling code for attribute '$k'" );
    }

    $_POST = $_REQUEST;

    $php_2dsa_1 = "${php_base}/2DSA_1.php";
    $php_2dsa_2 = "${php_base}/2DSA_2.php";


    echo "preparing to call $php_2dsa_1\n";
    echo "session now is:\n" . json_encode( $_SESSION, JSON_PRETTY_PRINT ) . "\n";
    echo "request/post now is:\n" . json_encode( $_REQUEST, JSON_PRETTY_PRINT ) . "\n";
    ob_start( "dump_it" );
    include( $php_2dsa_1 );
    while (ob_get_level()) ob_end_flush();
    check_cli_errors();
}

# what we need to add for 2DSA
/*
Post:
{
    "s_value_min": "1",
    "s_value_max": "10",
    "s_grid_points": "64",
    "ff0_min": "1",
    "ff0_max": "4",
    "ff0_grid_points": "64",
    "mc_iterations": "1",
    "tinoise_option": "0",
    "rinoise_option": "0",
    "fit_mb_select": "0",
    "meniscus_range": "0.03",
    "meniscus_points": "11",
    "iterations_option": "0",
    "max_iterations": "10",
    "debug_level-value": "0",
    "debug_text-value": "",
    "simpoints-value": "200",
    "band_volume-value": "0.015",
    "radial_grid": "0",
    "time_grid": "1",
    "cluster": "129.114.17.229:us3iab-node1:normal",
    "TIGRE": "Submit"
}
*/;

# what we need to add for PCSA
/*
Post:
{
    "curve_type": "IS",
    "solute_type": "013.skv",
    "x_min": "1",
    "x_max": "10",
    "y_min": "1",
    "y_max": "4",
    "z_value": "0",
    "vars_count": "10",
    "hl_vars_count": "100",
    "gfit_iterations": "3",
    "thr_deltr_ratio": "0.0001",
    "curves_points": "200",
    "tikreg_option": "0",
    "tikreg_alpha": "0.275",
    "mc_iterations": "1",
    "tinoise_option": "0",
    "rinoise_option": "0",
    "debug_level-value": "0",
    "debug_text-value": "",
    "simpoints-value": "200",
    "band_volume-value": "0.015",
    "radial_grid": "0",
    "time_grid": "1",
    "cluster": "js237a.genapp.rocks:us3iab-node0:batch",
    "TIGRE": "Submit"
}
*/
