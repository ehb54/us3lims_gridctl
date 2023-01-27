<?php

# setup a fake request

$self = __FILE__;
    
$notes = <<<__EOD
usage: $self db investigatorId rawDataID

1. finds db.AnalysisRequest with a RequestID = ID
2. finds associated db.analysisprofile
3. loads any related tables needed to build up \$_SESSION
4. converts XML -> JSON to setup the job

__EOD;

if ( count( $argv ) != 4 ) {
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
        if ( $result ) {
            debug_json( "query result", $result );
        }
        exit;
    }

    if ( $result->num_rows > 1 ) {
        write_logl( "WARNING: db query returned " . $result->num_rows . " rows : $query" );
    }    

    return mysqli_fetch_object( $result );
}

function db_obj_insert( $db_handle, $query ) {
    $result = mysqli_query( $db_handle, $query );

    if ( !$result ) {
        write_logl( "db query failed : $query" );
        exit;
    }
}


# also in /srv/www/html/uslims3/uslims3_et4/lib/utility.php
function uuid() {
   
    // The field names refer to RFC 4122 section 4.1.2

    return sprintf('%04x%04x-%04x-%03x4-%04x-%04x%04x%04x',
        mt_rand(0, 65535), mt_rand(0, 65535), // 32 bits for "time_low"
        mt_rand(0, 65535), // 16 bits for "time_mid"
        mt_rand(0, 4095),  // 12 bits before the 0100 of (version) 4 for "time_hi_and_version"
        bindec(substr_replace(sprintf('%016b', mt_rand(0, 65535)), '01', 6, 2)),
            // 8 bits, the last two of which (positions 6 and 7) are 01, for "clk_seq_hi_res"
            // (hence, the 2nd hex digit after the 3rd hyphen can only be 1, 5, 9 or d)
            // 8 bits for "clk_seq_low"
        mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535) // 48 bits for "node" 
    ); 
}


$lims_db = $argv[ 1 ];
$invID   = $argv[ 2 ];
$rawID   = $argv[ 3 ];

write_logl( "Starting" );

do {
    $db_handle = mysqli_connect( $dbhost, $user, $passwd, $db );
    if ( !$db_handle ) {
        write_logl( "could not connect to mysql: $dbhost, $user, $db. Will retry in ${poll_sleep_seconds}s" );
        sleep( $poll_sleep_seconds );
    }
} while ( !$db_handle );

write_logl( "connected to mysql: $dbhost, $user, $db.", 2 );

# get person

$person = db_obj_result( $db_handle, 
                         "SELECT * FROM ${lims_db}.people WHERE personID='$invID'" );

echo "got person\n";
# get rawDdata

$rawData = db_obj_result( $db_handle, 
                          "SELECT rawDataID, rawDataGUID, label, filename, comment, experimentID, solutionID, channelID FROM ${lims_db}.rawData WHERE rawDataID='$rawID'" );

$filename = $rawData->{ 'filename' };
$protName = $rawData->{ 'label' };

echo "got rawData filename $filename\n";
$pieces = explode( ".", $filename );
$ufilename = $pieces[ 0 ];
$utriple   = "$pieces[2].$pieces[3].$pieces[4]";
$ucell     = $pieces[2];

# echo "ufilename '$ufilename', utriple '$utriple' ucell = '$ucell'\n";

# create autoflow record

$aprofileGUID=uuid();

## add fields

db_obj_insert(
    $db_handle,
    "INSERT ${lims_db}.autoflow (protName,cellChNum,runName,expID,status,invID,corrRadii,expAborted,gmpRun,filename,aprofileGUID) values " .
    "( '$protName', $ucell, '$ufilename', $rawID, 'ANALYSIS', $invID, 'YES', 'NO', 'YES', '$ufilename','$aprofileGUID' )" )
    ;

$lastautoflowid =  db_obj_result( $db_handle, "SELECT LAST_INSERT_ID()" );
$autoflowID = $lastautoflowid->{'LAST_INSERT_ID()'};

echo "inserted autoflowID is $autoflowID\n";


# create analysisprofile record
$xml = <<<__EOD
<?xml version="1.0"?>
<!DOCTYPE US_AnalysisProfile>
<AnalysisProfileData version="1.0">
<analysis_profile name="$protName" guid="$aprofileGUID">
<channel_parms channel="2A" chandesc="2A:UV/vis.:BSA in PBS" load_concen_ratio="1" lcr_tolerance="5" load_volume="460" lv_tolerance="10" data_end="7"/>
<p_2dsa>
<channel_parms channel="2A:UV/vis.:BSA in PBS" s_min="1" s_max="10" s_gridpoints="64" k_min="1" k_max="5" k_gridpoints="64" vary_vbar="0" constant_ff0="0.72" custom_grid_guid=""/>
<job_2dsa run="1" noise="(TI Noise)"/>
<job_2dsa_fm run="1" noise="(TI+RI Noise)" fit_range="0.3" grid_points="64" fit_mb_select="1" meniscus_range="0.01" meniscus_points="11"/>
<job_fitmen run="1" interactive="1"/>
<job_2dsa_it run="1" noise="(TI+RI Noise)" max_iterations="3"/>
<job_2dsa_mc run="1" mc_iterations="5"/>
</p_2dsa>
<p_pcsa job_run="1">
<channel_parms channel="2A:UV/vis.:BSA in PBS" curve_type="All" x_type="s" y_type="f/f0" z_type="vbar" x_min="1" x_max="10" y_min="1" y_max="4" z_value="0.72" variations_count="3" gridfit_iterations="6" curve_reso_points="100" noise="none" regularization="none" reg_alpha="0" mc_iterations="0"/>
</p_pcsa>
</analysis_profile>
</AnalysisProfileData>
__EOD;

    
db_obj_insert(
    $db_handle,
    "INSERT ${lims_db}.analysisprofile (aprofileGUID,name,xml) values " .
    "( '$aprofileGUID', '$protName', '$xml' )" )
    ;

$lastap =  db_obj_result( $db_handle, "SELECT LAST_INSERT_ID()" );
$apID = $lastap->{'LAST_INSERT_ID()'};

echo "inserted analysisprofile aprofileID is $apID\n";


# create autoflowAnalysis record

db_obj_insert(
    $db_handle,
    "INSERT ${lims_db}.autoflowAnalysis (tripleName,filename,aprofileGUID,invID,statusJson,autoflowID) values " .
    "( '$utriple', '$ufilename', '$aprofileGUID', $invID, '{\"to_process\":[\"PCSA\"]}', $autoflowID )" )
    ;

$lastaaid =  db_obj_result( $db_handle, "SELECT LAST_INSERT_ID()" );
$aaID = $lastaaid->{'LAST_INSERT_ID()'};

echo "inserted autoflowAnalysis requestID is $aaID\n";


# Update autoflow table with the (single) autoflowAnalysisID

db_obj_insert(
    $db_handle,
    "UPDATE ${lims_db}.autoflow SET analysisIDs='$aaID' WHERE ID='$autoflowID'" );
