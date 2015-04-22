<?php

include "/export/home/us3/bin/listen-config.php";

$socket = socket_create(  AF_INET,  SOCK_DGRAM,  SOL_UDP );

// Listen on all interfaces
if ( ! socket_bind( $socket, 0, $listen_port ) )
{
  $msg = "listen bind failed: " . socket_strerror( socket_last_error( $socket ) );
  write_log( "$self: $msg" );
  exit();
};

$handle = fopen( $pipe, "r+" );

$php = "/usr/bin/php";

$cmd = "/usr/bin/nohup $php $home/bin/manage-us3-pipe.php >>$home/etc/manage.log 2>&1 </dev/null &";

exec( $cmd );

do
{
  socket_recvfrom( $socket, $buf, 200, 0, $from, $port );
  fwrite( $handle, $buf . chr( 0 ) );

} while ( trim( $buf ) != "Stop listen" );

socket_close( $socket );
?>
