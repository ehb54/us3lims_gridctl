<?php
$args = $_SERVER[ 'argv' ];
array_shift( $args );

$buf = implode( ";", $args );
echo "$buf\n";
$socket = socket_create(  AF_INET,  SOCK_DGRAM,  SOL_UDP );

// socket_sendto( $socket, $buf, strlen( $buf ), 0, 'localhost', 12233 );
socket_sendto( $socket, $buf, strlen( $buf ), 0, '127.0.0.1', 12233 );
socket_close ( $socket );
?>
