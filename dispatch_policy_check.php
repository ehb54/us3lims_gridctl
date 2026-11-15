<?php
/* Dry-run report of every metascheduler pool's effective dispatch policy.
 * Usage: php dispatch_policy_check.php [--global-config=PATH]
 * Exits 1 when a pool configured active cannot dispatch, so upgrades and checks can stop on it. */

require_once __DIR__ . '/dispatch_policy.php';

$options = getopt( '', array( 'global-config:' ) );
if ( isset( $options[ 'global-config' ] ) ) {
   $global_config = $options[ 'global-config' ];
}
else {
   require_once __DIR__ . '/gridctl_bootstrap.php';
   $global_config = $class_dir . '../global_config.php';
}

if ( ! is_file( $global_config ) ) {
   fwrite( STDERR, "dispatch_policy_check: $global_config not found\n" );
   exit( 2 );
}
include_once $global_config;

if ( ! isset( $cluster_details ) || ! is_array( $cluster_details ) ) {
   fwrite( STDERR, "dispatch_policy_check: $global_config defines no \$cluster_details\n" );
   exit( 2 );
}

echo implode( "\n", dispatch_policy_report( $cluster_details, get_defined_vars() ) ), "\n";

$status = 0;
foreach ( $cluster_details as $name => $entry ) {
   if ( dispatch_is_pool_entry( $entry ) && ( ! array_key_exists( 'active', $entry ) || $entry[ 'active' ] )
        && ! dispatch_pool_policy( $name, $cluster_details )[ 'active' ] )
      $status = 1;
}
exit( $status );
