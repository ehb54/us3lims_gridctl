<?php
/* Prints 1 when the given global_config.php has an active metascheduler pool, else 0. Run in its
 * own process so the long-running daemon can read an edited file without including it twice.
 * Usage: php dispatch_active_pools.php <global_config.php> */

require_once __DIR__ . '/dispatch_policy.php';

if ( $argc !== 2 || ! is_file( $argv[ 1 ] ) || ! is_readable( $argv[ 1 ] ) ) {
   fwrite( STDERR, "dispatch_active_pools: no readable global_config.php\n" );
   exit( 2 );
}
## Anything the file prints (a byte-order mark, a blank line after a closing tag) is not the answer.
ob_start();
include_once $argv[ 1 ];
ob_end_clean();
## A file with no cluster list, such as one caught mid-rewrite, says nothing about its pools.
if ( ! isset( $cluster_details ) || ! is_array( $cluster_details ) ) {
   fwrite( STDERR, "dispatch_active_pools: global_config.php sets no cluster_details\n" );
   exit( 3 );
}
foreach ( $cluster_details as $entry ) {
   if ( dispatch_is_pool_entry( $entry ) && ( ! array_key_exists( 'active', $entry ) || $entry[ 'active' ] ) ) {
      echo "1\n";
      exit( 0 );
   }
}
echo "0\n";
