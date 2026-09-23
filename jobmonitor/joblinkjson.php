<?php

$us3lims = exec( "ls -d ~us3/lims" );
$us3bin  = "$us3lims/bin";
$self    = __FILE__;
$errors  = '';

## Needs only configuration, logging and the database helper from listen-config.php.
require_once "$us3bin/listen-config.php";

# ********* start user defines *************

# process arguments or die

$notes = "usage: $self dbname autoflowAnalysisID
";

$u_argv = $argv;
array_shift( $u_argv ); # first element is program name

if ( count( $u_argv ) != 2 ) {
    // Usage is a CLI response, even when daemon logging goes to a file.
    fwrite( STDERR, $notes );
    exit( 1 );
}

$us3_db             = array_shift( $u_argv );
$autoflowAnalysisID = array_shift( $u_argv );

if ( !preg_match( '/^uslims3_[A-Za-z0-9_]*$/', $us3_db ) ) {
    $errors .= "dbname has an invalid format\n";
}

if ( !preg_match( '/^[0-9]+$/', $autoflowAnalysisID ) ) {
    $errors .= "autoflowAnalysisID must be numeric\n";
}

flush_errors_exit();

# open db
open_db();


$query = "SELECT modelsDesc FROM {$us3_db}.autoflowModelsLink WHERE autoflowAnalysisID = ?";
echo "query : $query\n";

$stmt = mysqli_prepare( $db_handle, $query );
if ( ! $stmt ) {
    write_logld( "Bad query:\n$query\n" . mysqli_error( $db_handle ) );
    return;
}

$autoflowAnalysisID = (int)$autoflowAnalysisID;
mysqli_stmt_bind_param( $stmt, 'i', $autoflowAnalysisID );
mysqli_stmt_execute( $stmt );
$result = mysqli_stmt_get_result( $stmt );

if ( ! $result ) {
    write_logld( "Bad query:\n$query\n" . mysqli_error( $db_handle ) );
    mysqli_stmt_close( $stmt );
    return;
}

# debug_json( "result", $result );

$descJson = (object)[];

if ( $result->num_rows ) {
    $obj = mysqli_fetch_object( $result );
    $descJson = json_decode( $obj->modelsDesc );
}

mysqli_stmt_close( $stmt );

debug_json( "modelsDesc", $descJson );
