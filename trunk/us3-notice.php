$us3bin = exec( "ls -d ~us3/lims/bin" );
include_once "$us3bin/listen-config.php";
include "$us3bin/cleanup_aira.php";
include "$us3bin/cleanup_gfac.php";
