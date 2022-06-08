<?php

$us3lims = exec( "ls -d ~us3/lims" );
$us3bin  = "$us3lims/bin";
$us3util = "$us3lims/database/utils";
$us3jm   = "$us3lims/bin/jobmonitor";

include "$us3bin/listen-config.php";
include $class_dir_p . "experiment_status.php";
include $class_dir_p . "experiment_errors.php";
include $class_dir_p . "job_details.php";

include "$us3jm/gridctl.php";
include "$us3jm/cleanup.php";
include "$us3jm/cleanup_gfac.php";

include "$us3util/utility.php";

# ********* start user defines *************

# process arguments or die

$notes = "usage: $self dbname autoflowAnalysisID
";

$u_argv = $argv;
array_shift( $u_argv ); # first element is program name

if ( count( $u_argv ) != 2 ) {
    error_exit( $notes );
}

$us3_db             = array_shift( $u_argv );
$autoflowAnalysisID = array_shift( $u_argv );

if ( !preg_match( '/^uslims3_[A-Za-z0-9_]*$/', $us3_db ) ) {
    $errors .= "dbname has an invalid format\n";
}

flush_errors_exit();

# open db
open_db();


$query = "SELECT modelsDesc from ${us3_db}.autoflowModelsLink where autoflowAnalysisID = $autoflowAnalysisID";
echo "query : $query\n";

$result = mysqli_query( $db_handle, $query );

if ( ! $result ) {
    write_logld( "Bad query:\n$query\n" . mysqli_error( $db_handle ) );
    return;
}

# debug_json( "result", $result );

$descJson = (object)[];

if ( $result->num_rows ) {
    $obj = mysqli_fetch_object( $result );
    $descJson = json_decode( $obj->modelsDesc );
}

debug_json( "modelsDesc", $descJson );

