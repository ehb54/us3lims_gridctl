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

echo "got rawData filename $filename\n";


# create autoflow record
## add fields

db_obj_insert(
    $db_handle,
    "INSERT ${lims_db}.autoflow (expID,status,invID,corrRadii,expAborted,gmpRun,filename) values " .
    "( $rawID, 'ANALYSIS', $invID, 'YES', 'NO', 'YES', '$filename' )" )
    ;

$lastautoflowid =  db_obj_result( $db_handle, "SELECT LAST_INSERT_ID()" );
$autoflowID = $lastautoflowid->{'LAST_INSERT_ID()'};

echo "inserted autoflowID is $autoflowID\n";


# create autoflowAnalysis record

