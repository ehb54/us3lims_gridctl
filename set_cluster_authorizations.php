<?php

$self = __FILE__;

$notes = <<<__EOD

usage: $self db {copyglobalconfig} {updatepeople}

reads cluster_config.php and sets uslims3_DB.people.clusterAuthorizations defaults

options:
copyglobalconfig   : if specified and a dbinst specific config does not exist, make a dbinst copy from uslims3_newlims
updatepeople       : if specified, all uslims3_DB.people will get their clusters also set to the new defaults

__EOD;

$u_argv = $argv;
array_shift( $u_argv ); # first element is program name

if ( !count( $u_argv ) ) {
    echo $notes;
    exit;
}

$lims_db            = array_shift( $u_argv );

$update_people      = false;
$copy_global_config = false;

while( count( $u_argv ) ) {
    switch( $u_argv[ 0 ] ) {
        case "updatepeople": {
            array_shift( $u_argv );
            $update_people = true;
            break;
        }
        case "copyglobalconfig": {
            array_shift( $u_argv );
            $copy_global_config = true;
            break;
        }
      default: {
          echo "\nUnknown option '$u_argv[0]'\n\n$notes";
          exit;
        }
    }
}

$us3bin = exec( "ls -d ~us3/lims/bin" );
include "$us3bin/listen-config.php";

# ********* start user defines *************

# logging_level 
# 0 : minimal messages (expected value for production)
# 1 : add detailed db messages
# 2 : add idle polling messages
$logging_level      = 3;

# www_uslims3 should likely be in listen_config.php
$www_uslims3         = "/srv/www/htdocs/uslims3";
$www_uslims3_newlims = "$www_uslims3/uslims3_newlims";
$cluster_config_name = "cluster_config.php";

# ********* end user defines ***************

# ********* start admin defines *************
# these should only be changed by developers
$db                                = "gfac";
# ********* end admin defines ***************

function write_logl( $msg, $this_level = 0 ) {
    global $logging_level;
    global $self;
    if ( $logging_level >= $this_level ) {
        echo "${self}: " . $msg . "\n";
        # write_log( "${self}: " . $msg );
    }
}

function error( $msg ) {
    write_logl( $msg );
    exit(-1);
}

function truestr( $val ) {
    return $val ? "true" : "false";
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

function db_result( $db_handle, $query ) {
    $result = mysqli_query( $db_handle, $query );
    if ( !$result ) {
        write_logl( "db query failed : $query" );
        exit;
    }
    return $result;
}

do {
    $db_handle = mysqli_connect( $dbhost, $user, $passwd, $db );
    if ( !$db_handle ) {
        write_logl( "could not connect to mysql: $dbhost, $user, $db. Will retry in ${poll_sleep_seconds}s" );
        sleep( $poll_sleep_seconds );
    }
} while ( !$db_handle );

# does database exist

$db_exists = db_obj_result( $db_handle, 
                            "SELECT schema_name from information_schema.schemata WHERE schema_name='$lims_db'" );
# write_logl( "Notice: database $lims_db exists" );

$cluster_config = "$www_uslims3/$lims_db/$cluster_config_name";

if ( !file_exists( $cluster_config ) ) {
    # write_logl( "Notice: $cluster_config does not exist" );
    $default_cluster_config = "$www_uslims3_newlims/$cluster_config_name";
    if ( !file_exists( $default_cluster_config ) ) {
        error( "Error: $default_cluster_config does not exist" );
    } else {
        if ( $copy_global_config ) {
            write_logl( "Notice: copying $default_cluster_config to $cluster_config" );
            copy( $default_cluster_config, $cluster_config );
        } else {
            write_logl( "Notice: using global config $default_cluster_config" );
            $cluster_config = $default_cluster_config;
        }
    }
}
    
write_logl( "Notice: reading $cluster_config" );

include "$cluster_config";

if ( !isset( $cluster_configuration ) ) {
    error( "Error: $cluster_config did not define \$cluster_configuration" );
}

# build up option string

$authclusts = [];

foreach ( $cluster_configuration as $k => $v ) {
    if ( isset( $v["active"] ) && $v["active"] ) {
        $authclusts[] = $k;
    }
}

$defaultclustauth = implode( ":", $authclusts );

write_logl( "default clusterAuthorizations: '$defaultclustauth'" );

# alter table
$result = db_result( $db_handle,
                     "alter table ${lims_db}.people alter clusterAuthorizations set default '$defaultclustauth'" );
write_logl( "${lims_db}.people default clusterAuthorizations updated" );
if ( $update_people ) {
    $result = db_result( $db_handle,
                         "update ${lims_db}.people set clusterAuthorizations='$defaultclustauth'" );
    write_logl( "${lims_db}.people all people clusterAuthorizations updated" );
}
