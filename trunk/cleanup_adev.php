<?php
/*
 * cleanup_adev.php
 *
 * functions relating to copying results and cleaning up the gfac DB
 *  where the job used an Airavata interface (development version).
 *
 */

$us3bin = exec( "ls -d ~us3/lims/bin" );
include_once "$us3bin/listen-config.php";
include $class_dir_d . "experiment_status.php";
include $class_dir_d . "experiment_errors.php";
$me              = 'cleanup_adev.php';
$class_dir       = $class_dir_d;
include_once "$us3bin/cleanup.php";

?>
