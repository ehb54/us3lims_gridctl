<?php

# begin to build up data in replacement of queue_setup_1,2,3.php in prep for 2DSA_? submit
    
$notes = <<<__EOD
usage: __FILE__ db ID

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
        echo "${self}: " . $msg . "\n";
        # write_log( "${self}: " . $msg );
    }
}

function debug_json( $msg, $json ) {
    echo "$msg\n";
    echo json_encode( $json, JSON_PRETTY_PRINT );
    echo "\n";
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

    return mysqli_fetch_object( $result );
}

$lims_db = $argv[ 1 ];
$ID      = $argv[ 2 ];

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
                                   "SELECT clusterDefault, tripleName, filename, aprofileGUID, statusJson FROM ${lims_db}.${submit_request_table_name} WHERE ${id_field}=$ID" );

$statusJson = json_decode( $autoflowanalysis->{"statusJson"} );
debug_json( "after fetch, decode", $statusJson );
        
if ( !isset( $statusJson->{ $processing_key } ) ||
     empty( $statusJson->{ $processing_key } ) ) {
    write_logl( "AutoflowAnalysis db ${lims_db} ${id_field} $ID is NOT ${processing_key}", 1 );
    exit;
}

$stage = $statusJson->{ $processing_key };
$triple = $autoflowanalysis->{ 'tripleName' };

write_logl( "job $ID found. stage to submit " .  json_encode( $stage, JSON_PRETTY_PRINT ) );

# get analysisprofile record

$aprofileguid = $autoflowanalysis->{ 'AprofileGUID' };

$analysisprofile = db_obj_result( $db_handle, 
                                  "SELECT * FROM ${lims_db}.analysisprofile WHERE aprofileGUID='${aprofileguid}'" );

write_logl( "aprofileGUID $aprofileguid found", 3 );

$xmljson = json_decode( json_encode( simplexml_load_string( $analysisprofile->{ 'xml' } ) ) );

write_logl( "analysisprofile's xml in json:\n" . json_encode( $xmljson, JSON_PRETTY_PRINT ) );

# sanity checks

$xmljsonfilename = $xmljson->{ 'analysis_profile' }->{ '@attributes' }->{ 'name' };
$xmljsonguid     = $xmljson->{ 'analysis_profile' }->{ '@attributes' }->{ 'guid' };
$aprofilename    = $analysisprofile->{'name'};
$aaname          = $autoflowanalysis->{'Filename'};

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

