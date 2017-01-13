<?php
/*
 * cleanup_aira.php
 *
 * functions relating to copying results and cleaning up the gfac DB
 *  where the job used an Airavata interface (production version).
 *
 */

$us3bin = exec( "ls -d ~us3/lims/bin" );
include_once "$us3bin/listen-config.php";
include $class_dir_p . "experiment_status.php";
include $class_dir_p . "experiment_errors.php";
$me              = 'cleanup_aira.php';
$class_dir       = $class_dir_p;
include_once "$us3bin/cleanup.php";

?>
