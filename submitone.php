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
    
$dumpfilebase = "/home/us3/lims/etc/";

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

function write_logl( $msg, $this_level = 0 ) {
    global $logging_level;
    global $self;
    if ( $logging_level >= $this_level ) {
        # echo "${self}: " . $msg . "\n";
        write_log( "${self}: " . $msg );
    }
}

function error( $msg ) {
    write_logl( $msg );
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

$dumpfile = "${dumpfilebase}/submit-debug-dump-$ID.txt";
global $dumpfile;
unlink( $dumpfile );

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
$triple = $autoflowanalysis->{ 'tripleName' };
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
                          "select rawDataID, filename from ${lims_db}.rawData where filename like '${aaname}%${triple}%'" );

echo json_encode( $rawdata, JSON_PRETTY_PRINT ) . "\n";

$editdata = db_obj_result( $db_handle,
                          "select editedDataID, filename from ${lims_db}.editedData where filename like '${aaname}%${triple}%'" );

echo json_encode( $editdata, JSON_PRETTY_PRINT ) . "\n";

$person = db_obj_result( $db_handle,
                          "select * from ${lims_db}.people where personID='${invID}'" );

echo json_encode( $person, JSON_PRETTY_PRINT ) . "\n";

$clusterAuth = explode( ":", $person->{'clusterAuthorizations'} );

echo "personid:" .  $person->{'personID'} . "\n";

$php_base          = "${www_uslims3}/${lims_db}";
set_include_path( get_include_path() . PATH_SEPARATOR . $php_base );

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

echo "queue is prepared\n";

# ************* process xml and determine job type and parameters ***************


$jobkey = "job_" . strtolower( $stage );

if ( !isset( $xmljson->{'analysis_profile'} ) ) {
    error( "analysis profile's xml does not contain an 'analysis_profile' key" );
}

if ( !isset( $xmljson->{'analysis_profile'}->{'p_2dsa'} ) ) {
    error( "analysis profile's xml does not contain an 'analysis_profile'->'p_2dsa' key" );
}

if ( !isset( $xmljson->{'analysis_profile'}->{'p_2dsa'}->{'channel_parms'} ) ||
     !count( $xmljson->{'analysis_profile'}->{'p_2dsa'}->{'channel_parms'} ) ) {
    error( "analysis profile's xml does not contain an 'analysis_profile'->'channel_parms' key with a nonzero size" );
}    

if ( !isset( $xmljson->{'analysis_profile'}->{'p_2dsa'}->{'channel_parms'}[0]->{'@attributes'} ) ) {
    error( "analysis profile's xml's 'analysis_profile'->'p_2dsa'->'channel_parms' first entry does not contain '\@attributes'" );
}    

$channel_attributes = $xmljson->{'analysis_profile'}->{'p_2dsa'}->{'channel_parms'}[0]->{'@attributes'};
debug_json( "channel attributes", $channel_attributes );

if ( !isset( $xmljson->{'analysis_profile'}->{'p_2dsa'}->{$jobkey} ) ) {
    error( "analysis profile's xml's 'analysis_profile'->'p_2dsa'->'$jobkey' is missing" );
}
if ( !isset( $xmljson->{'analysis_profile'}->{'p_2dsa'}->{$jobkey}->{'@attributes'} ) ) {
    error( "analysis profile's xml's 'analysis_profile'->'p_2dsa'->'$jobkey'->'\@attributes' is missing" );
}

$job_attributes = $xmljson->{'analysis_profile'}->{'p_2dsa'}->{$jobkey}->{'@attributes'};
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

if ( $cluster == "localhost" ) {
    $cluster = "us3iab-node1";
}
$queue = "normal";

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

