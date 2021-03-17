<?php

$us3bin    = exec( "ls -d ~us3/lims/bin" );
include_once "$us3bin/listen-config.php";

$class_dir = $class_dir_d;		// development version
include "$us3bin/cleanup_adev.php";
include "$us3bin/cleanup_gfac.php";

// lock
if ( isset( $lock_dir ) ) {
   $lock_main_script_name  = __FILE__;
   require "$us3bin/lock.php";
} 

include "$us3bin/gridctl.php";

?>

