<?php
// Shared listener/monitor runtime helpers. Deployment configs own values,
// not alternate implementations of the functions the daemons require.
function write_log( $msg ) {
    global $STDOUT, $logfile;
    $line = date( 'Y-m-d H:i:s' ) . ' ' . $msg . "\n";
    if ( isset( $STDOUT ) && is_resource( $STDOUT ) ) {
        fwrite( $STDOUT, $line );
    } elseif ( isset( $logfile ) && $logfile !== '' ) {
        file_put_contents( $logfile, $line, FILE_APPEND );
    } else {
        echo $line;
    }
}

function write_logld( $msg ) {
    global $logging_level;
    if ( ($logging_level ?? 0) >= 1 ) write_log( $msg );
}

if ( !function_exists( 'error_exit' ) ) {
    function error_exit( $msg ) {
        write_log( "ERROR: $msg" );
        exit( -1 );
    }
}

function flush_errors_exit() {
    global $errors;
    if ( isset( $errors ) && strlen( $errors ) > 0 ) error_exit( $errors );
}

if ( !function_exists( 'debug_json' ) ) {
    function debug_json( $label, $obj ) {
        global $logging_level;
        if ( ($logging_level ?? 0) >= 3 ) {
            write_log( "$label: " . json_encode( $obj, JSON_PRETTY_PRINT ) );
        }
    }
}

function open_db() {
    global $db_handle, $dbhost, $user, $passwd;
    $db_handle = mysqli_connect( $dbhost, $user, $passwd );
    if ( ! $db_handle ) error_exit( "Cannot connect to database at $dbhost" );
}

// Named distinctly from submitone.php's own local db_obj_result() (2 args,
// always exits on missing rows): both get loaded into the same PHP process
// when submitone.php includes this file, and PHP has no per-file function
// scoping, so a shared name here fatals with "Cannot redeclare".
function listen_db_obj_result( $db_handle, $query, $die_on_error = false, $return_obj = false ) {
    $result = mysqli_query( $db_handle, $query );
    if ( $result === false ) {
        if ( $die_on_error ) error_exit( "Query failed: $query\n" . mysqli_error( $db_handle ) );
        return false;
    }
    return $return_obj ? mysqli_fetch_object( $result ) : $result;
}
