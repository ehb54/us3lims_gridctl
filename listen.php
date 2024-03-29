<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
$us3etc = exec( "ls -d ~us3/lims/etc" );
include "$us3bin/listen-config.php";

# add locking
if ( isset( $lock_dir ) ) {
    $lock_main_script_name  = __FILE__;
    require "$us3bin/lock.php";
}

write_log( "$self: startup" );

$socket = socket_create(  AF_INET,  SOCK_DGRAM,  SOL_UDP );

// Listen on all interfaces
if ( ! socket_bind( $socket, 0, $listen_port ) )
{
  $msg = "listen bind failed: " . socket_strerror( socket_last_error( $socket ) );
  write_log( "$self: $msg" );
  exit();
};
write_log( "$self: socket bound" );

$handle = fopen( $pipe, "r+" );

$php = "/usr/bin/php";

$cmd = "/usr/bin/nohup $php $us3bin/manage-us3-pipe.php >>$us3etc/manage.log 2>&1 </dev/null &";

exec( $cmd );

do
{
  socket_recvfrom( $socket, $buf, 200, 0, $from, $port );
  write_log( "$self: udp receipt: $from $port $buf\n" );
  fwrite( $handle, $buf . chr( 0 ) );

} while ( trim( $buf ) != "Stop listen" );

socket_close( $socket );
?>
