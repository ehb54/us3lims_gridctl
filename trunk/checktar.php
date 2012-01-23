<?php

include "listen-config.php";

$gfac_link = mysql_connect( $dbhost, $guser, $gpasswd );

$result = mysql_select_db( $gDB, $gfac_link );

$query = "SELECT id, time, cluster, tarfile FROM analysis ";
$result = mysql_query( $query, $gfac_link );

while ( $row = mysql_fetch_assoc( $result ) )
{
   $tarfile = $row[ 'tarfile' ];
   $id      = $row[ 'id' ];
   $time    = $row[ 'time' ];

   $cluster = $row[ 'cluster' ];

   $i = 0;

   if ( strlen( $tarfile ) == 0 ) echo "id $id is null\n";

   else
   {
      $fn = "test" . $i++ . ".tar";
      file_put_contents( $fn, $tarfile );
      $i++;
      echo "cluster $cluster; time $time; $id:\n";
      passthru( "tar -xvf $fn" );
      echo "\n";


   }

}

