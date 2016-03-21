<?php

$us3bin    = exec( "ls -d ~us3/lims/bin" );
include_once "$us3bin/listen-config.php";

$class_dir = $class_dir_d;		// development version
include "$us3bin/cleanup_adev.php";
include "$us3bin/cleanup_gfac.php";
include "$us3bin/gridctl.php";

?>

