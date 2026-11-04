<?php
/* Prints 1 when the given global_config.php has an active metascheduler pool, else 0. Run in its
 * own process so the long-running daemon can read an edited file without including it twice.
 * Usage: php dispatch_active_pools.php <global_config.php> */

require_once __DIR__ . '/dispatch_policy.php';

if ( $argc !== 2 || ! is_file( $argv[ 1 ] ) ) {
   fwrite( STDERR, "dispatch_active_pools: no such global_config.php\n" );
   exit( 2 );
}
include_once $argv[ 1 ];
foreach ( isset( $cluster_details ) ? (array) $cluster_details : array() as $entry ) {
   if ( dispatch_is_pool_entry( $entry ) && ( ! array_key_exists( 'active', $entry ) || $entry[ 'active' ] ) ) {
      echo "1\n";
      exit( 0 );
   }
}
echo "0\n";
