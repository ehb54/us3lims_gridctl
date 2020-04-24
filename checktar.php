<?php

include "listen-config.php";

$gpasswd  = password_field( $gpasswd, "PW" );
$gfac_link = mysqli_connect( $dbhost, $guser, $gpasswd, $gDB );

$query = "SELECT id, time, cluster, tarfile FROM analysis ";
$result = mysqli_query( $gfac_link, $query );

while ( $row = mysqli_fetch_assoc( $result ) )
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

