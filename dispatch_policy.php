<?php
/* Dispatch policy for metascheduler pools: settings, upgrade defaults and validation. Pure except
   dispatch_operator_overrides(), which reads the operator's file; PHP 7.2 compatible. */

const DISPATCH_STRATEGIES = array( 'ordered', 'round_robin', 'weighted_round_robin' );
const DISPATCH_ADMISSIONS = array( 'queue', 'start_within_allowance' );
const DISPATCH_FALLBACKS  = array( 'queue_preferred', 'wait_for_dispatch' );

## Global tunables of the job-count selector that the dispatcher no longer reads.
const DISPATCH_RETIRED_GLOBALS = array( 'global_cluster_saturation_threshold', 'global_cluster_nudge_ttl_seconds' );

/** True for a pool pseudo-entry (has a "clusters" member list). */
function dispatch_is_pool_entry( $entry )
{
   return is_array( $entry ) && isset( $entry[ 'clusters' ] ) && is_array( $entry[ 'clusters' ] );
}

/**
 * The clusters the operator took out of service in down_clusters.php ($down_clusters,
 * $draining_clusters), the file dbinst's cluster list reads: cluster => 'down' | 'draining',
 * down winning when a cluster is in both. Empty when the file is absent.
 */
function dispatch_operator_overrides( $path )
{
   $down_clusters = array();
   $draining_clusters = array();
   if ( is_file( $path ) )
      include $path;
   $out = array();
   foreach ( array( 'draining' => $draining_clusters, 'down' => $down_clusters ) as $state => $names ) {
      foreach ( is_array( $names ) ? $names : array() as $name )
         $out[ (string) $name ] = $state;
   }
   return $out;
}

/** Physical scheduler identity: an explicit 'controller', else the login host 'name'. */
function dispatch_member_controller( array $entry )
{
   if ( isset( $entry[ 'controller' ] ) && (string) $entry[ 'controller' ] !== '' )
      return (string) $entry[ 'controller' ];
   return isset( $entry[ 'name' ] ) ? (string) $entry[ 'name' ] : '';
}

/** Profile identity on its controller: two members with the same one are aliases of one destination. */
function dispatch_member_profile_key( array $entry )
{
   return dispatch_member_controller( $entry ) . '|' . ( isset( $entry[ 'queue' ] ) ? (string) $entry[ 'queue' ] : '' );
}

/** Why a configured member cannot take work, or null when it can. */
function dispatch_member_ineligibility( $name, array $cluster_details )
{
   if ( ! isset( $cluster_details[ $name ] ) || ! is_array( $cluster_details[ $name ] ) )
      return 'not configured in cluster_details';
   $entry = $cluster_details[ $name ];
   if ( dispatch_is_pool_entry( $entry ) )
      return 'is itself a pool';
   $tier = isset( $entry[ 'tier' ] ) ? $entry[ 'tier' ] : null;
   if ( $tier !== 'local' && $tier !== 'national' )
      return 'tier not configured';
   if ( array_key_exists( 'active', $entry ) && ! $entry[ 'active' ] )
      return 'inactive';
   return null;
}

/** Sizing settings that contradict each other; reported, never rewritten. */
function dispatch_member_sizing_problems( $name, array $entry )
{
   $problems = array();
   foreach ( array( 'ppn', 'ppbj', 'maxproc' ) as $key ) {
      if ( isset( $entry[ $key ] ) && ( ! is_numeric( $entry[ $key ] ) || (int) $entry[ $key ] < 1 ) )
         $problems[] = "$name.$key must be a positive integer";
   }
   if ( isset( $entry[ 'ppbj' ], $entry[ 'maxproc' ] ) && (int) $entry[ 'ppbj' ] > (int) $entry[ 'maxproc' ] )
      $problems[] = "$name.ppbj exceeds maxproc";
   if ( ! empty( $entry[ 'single_node' ] ) && isset( $entry[ 'maxproc' ], $entry[ 'ppn' ] )
        && (int) $entry[ 'maxproc' ] > (int) $entry[ 'ppn' ] )
      $problems[] = "$name is single_node but maxproc exceeds ppn";
   return $problems;
}

/** Validate one configured group; returns array( normalized group, errors ). */
function dispatch_normalize_group( $label, $group, array $pool_members )
{
   $errors = array();
   if ( ! is_array( $group ) || ! isset( $group[ 'members' ] ) || ! is_array( $group[ 'members' ] )
        || count( $group[ 'members' ] ) === 0 )
      return array( null, array( "$label.members must be a non-empty list" ) );

   $members = array_values( $group[ 'members' ] );
   if ( count( array_unique( $members ) ) !== count( $members ) )
      $errors[] = "$label.members lists a member twice";
   foreach ( $members as $m ) {
      if ( ! in_array( $m, $pool_members, true ) )
         $errors[] = "$label member '$m' is not in the pool's clusters list";
   }

   $strategy = isset( $group[ 'strategy' ] ) ? $group[ 'strategy' ] : 'ordered';
   if ( ! in_array( $strategy, DISPATCH_STRATEGIES, true ) )
      $errors[] = "$label.strategy '$strategy' is not one of " . implode( ', ', DISPATCH_STRATEGIES );

   $weights = array();
   foreach ( $members as $m )
      $weights[ $m ] = 1;
   if ( $strategy === 'weighted_round_robin' ) {
      $given = isset( $group[ 'weights' ] ) && is_array( $group[ 'weights' ] ) ? $group[ 'weights' ] : array();
      foreach ( $members as $m ) {
         $w = isset( $given[ $m ] ) ? $given[ $m ] : null;
         if ( ! is_int( $w ) || $w < 1 )
            $errors[] = "$label weight for '$m' must be a positive integer";
         else
            $weights[ $m ] = $w;
      }
      foreach ( array_keys( $given ) as $m ) {
         if ( ! in_array( $m, $members, true ) )
            $errors[] = "$label has a weight for '$m', which is not a member";
      }
   }

   return array( array( 'members' => $members, 'strategy' => $strategy, 'weights' => $weights ), $errors );
}

/**
 * The effective dispatch policy of one pool. A pool without a 'dispatch'
 * setting gets the upgrade default: local members rotate in list order,
 * national overflow is off, and a national-only pool stays inactive until a
 * site confirms its primary group. Errors make the pool inactive; the
 * reasons say why.
 */
function dispatch_pool_policy( $pool, array $cluster_details )
{
   $policy = array(
      'pool' => $pool, 'active' => false, 'reasons' => array(), 'warnings' => array(),
      'converted' => false, 'primary' => null, 'overflow' => null, 'overflow_enabled' => false,
      'admission' => 'queue', 'wait_allowance_seconds' => 0, 'fallback' => 'queue_preferred',
      'overflow_on_assessment_unknown' => false, 'ineligible' => array(), 'version' => '',
   );

   $entry = isset( $cluster_details[ $pool ] ) ? $cluster_details[ $pool ] : null;
   if ( ! dispatch_is_pool_entry( $entry ) ) {
      $policy[ 'reasons' ][] = "'$pool' is not a metascheduler pool";
      return $policy;
   }
   $configured_active = ! array_key_exists( 'active', $entry ) || (bool) $entry[ 'active' ];
   $members = array_values( $entry[ 'clusters' ] );

   $by_tier = array( 'local' => array(), 'national' => array() );
   foreach ( $members as $m ) {
      $why = dispatch_member_ineligibility( $m, $cluster_details );
      if ( $why !== null ) {
         $policy[ 'ineligible' ][ $m ] = $why;
         continue;
      }
      $by_tier[ $cluster_details[ $m ][ 'tier' ] ][] = $m;
      foreach ( dispatch_member_sizing_problems( $m, $cluster_details[ $m ] ) as $p )
         $policy[ 'warnings' ][] = $p;
      if ( empty( $cluster_details[ $m ][ 'runtime_version' ] ) )
         $policy[ 'warnings' ][] = "$m has no pinned runtime_version";
   }

   $errors = array();
   $seen = array();
   foreach ( $members as $m ) {
      if ( ! isset( $cluster_details[ $m ] ) || ! is_array( $cluster_details[ $m ] )
           || dispatch_is_pool_entry( $cluster_details[ $m ] ) )
         continue;
      $key = dispatch_member_profile_key( $cluster_details[ $m ] );
      if ( isset( $seen[ $key ] ) )
         $errors[] = "'$m' and '{$seen[ $key ]}' are aliases of one destination ($key)";
      else
         $seen[ $key ] = $m;
   }

   if ( ! isset( $entry[ 'dispatch' ] ) ) {
      $policy[ 'converted' ] = true;
      if ( $by_tier[ 'local' ] ) {
         $policy[ 'primary' ] = array( 'members' => $by_tier[ 'local' ], 'strategy' => 'round_robin',
                                       'weights' => array_fill_keys( $by_tier[ 'local' ], 1 ) );
         if ( $by_tier[ 'national' ] )
            $policy[ 'overflow' ] = array( 'members' => $by_tier[ 'national' ], 'strategy' => 'ordered',
                                           'weights' => array_fill_keys( $by_tier[ 'national' ], 1 ) );
      }
      elseif ( $by_tier[ 'national' ] )
         $errors[] = 'national-only pool: confirm a national primary group in its dispatch setting before activation';
      else
         $errors[] = 'no eligible members';
   }
   else {
      $d = $entry[ 'dispatch' ];
      if ( ! is_array( $d ) )
         $d = array();

      list( $primary, $e ) = dispatch_normalize_group( 'primary', isset( $d[ 'primary' ] ) ? $d[ 'primary' ] : null, $members );
      $errors = array_merge( $errors, $e );
      $policy[ 'primary' ] = $primary;

      if ( isset( $d[ 'overflow' ] ) ) {
         list( $overflow, $e ) = dispatch_normalize_group( 'overflow', $d[ 'overflow' ], $members );
         $errors = array_merge( $errors, $e );
         $policy[ 'overflow' ] = $overflow;
         $policy[ 'overflow_enabled' ] = ! empty( $d[ 'overflow' ][ 'enabled' ] );
         if ( $primary && $overflow && array_intersect( $primary[ 'members' ], $overflow[ 'members' ] ) )
            $errors[] = 'a member cannot be in both the primary and overflow groups';
      }

      $admission = isset( $d[ 'admission' ] ) ? $d[ 'admission' ] : 'queue';
      if ( ! in_array( $admission, DISPATCH_ADMISSIONS, true ) )
         $errors[] = "admission '$admission' is not one of " . implode( ', ', DISPATCH_ADMISSIONS );
      $policy[ 'admission' ] = $admission;

      $allowance = isset( $d[ 'wait_allowance_seconds' ] ) ? $d[ 'wait_allowance_seconds' ] : 0;
      if ( ! is_int( $allowance ) || $allowance < 0 )
         $errors[] = 'wait_allowance_seconds must be a non-negative integer';
      $policy[ 'wait_allowance_seconds' ] = $allowance;

      ## Gated rotation waits by default; ordered keeps the institutional-first queue fallback.
      $rotating = $primary && $primary[ 'strategy' ] !== 'ordered';
      $default_fallback = ( $admission === 'start_within_allowance' && $rotating && ! $policy[ 'overflow_enabled' ] )
                        ? 'wait_for_dispatch' : 'queue_preferred';
      $fallback = isset( $d[ 'fallback' ] ) ? $d[ 'fallback' ] : $default_fallback;
      if ( ! in_array( $fallback, DISPATCH_FALLBACKS, true ) )
         $errors[] = "fallback '$fallback' is not one of " . implode( ', ', DISPATCH_FALLBACKS );
      $policy[ 'fallback' ] = $fallback;

      $unknown = isset( $d[ 'overflow_on_assessment_unknown' ] ) ? $d[ 'overflow_on_assessment_unknown' ] : false;
      if ( ! is_bool( $unknown ) )
         $errors[] = 'overflow_on_assessment_unknown must be true or false';
      $policy[ 'overflow_on_assessment_unknown' ] = $unknown === true;

      if ( $primary ) {
         $usable = array_diff( $primary[ 'members' ], array_keys( $policy[ 'ineligible' ] ) );
         if ( ! $usable )
            $errors[] = 'no eligible member in the primary group';
      }

      foreach ( array_keys( $d ) as $k ) {
         if ( ! in_array( $k, array( 'primary', 'overflow', 'admission', 'wait_allowance_seconds', 'fallback',
                                     'overflow_on_assessment_unknown' ), true ) )
            $errors[] = "unknown dispatch setting '$k'";
      }
   }

   $policy[ 'reasons' ] = $errors;
   if ( ! $configured_active )
      $policy[ 'reasons' ][] = "metascheduler '$pool' is disabled (active=false)";
   $policy[ 'active' ] = $configured_active && ! $errors;

   $versioned = $policy;
   unset( $versioned[ 'active' ], $versioned[ 'reasons' ], $versioned[ 'warnings' ], $versioned[ 'version' ] );
   $policy[ 'version' ] = substr( sha1( json_encode( $versioned ) ), 0, 12 );
   return $policy;
}

/** Retired global tunables still set in the loaded configuration. */
function dispatch_retired_globals_set( array $globals )
{
   $set = array();
   foreach ( DISPATCH_RETIRED_GLOBALS as $name ) {
      if ( array_key_exists( $name, $globals ) )
         $set[] = $name;
   }
   return $set;
}

/** Human-readable dry-run lines for every pool in cluster_details. */
function dispatch_policy_report( array $cluster_details, array $globals = array() )
{
   $lines = array();
   foreach ( $cluster_details as $name => $entry ) {
      if ( ! dispatch_is_pool_entry( $entry ) )
         continue;
      $p = dispatch_pool_policy( $name, $cluster_details );
      $state = $p[ 'active' ] ? 'ACTIVE' : 'INACTIVE';
      $lines[] = "$name: $state" . ( $p[ 'converted' ] ? ' (upgrade default)' : '' ) . " version {$p['version']}";
      if ( $p[ 'primary' ] )
         $lines[] = "  primary: {$p['primary']['strategy']} " . implode( ', ', $p[ 'primary' ][ 'members' ] );
      if ( $p[ 'overflow' ] )
         $lines[] = '  overflow (' . ( $p[ 'overflow_enabled' ] ? 'enabled' : 'disabled' ) . "): "
                  . "{$p['overflow']['strategy']} " . implode( ', ', $p[ 'overflow' ][ 'members' ] );
      $lines[] = "  admission: {$p['admission']}, allowance {$p['wait_allowance_seconds']}s, fallback {$p['fallback']}";
      if ( $p[ 'converted' ] && $p[ 'primary' ] )
         $lines[] = '  note: round robin replaces the job-count lowest-load choice; it is not the same algorithm';
      foreach ( $p[ 'ineligible' ] as $m => $why )
         $lines[] = "  member $m ineligible: $why";
      foreach ( $p[ 'reasons' ] as $r )
         $lines[] = "  inactive because: $r";
      foreach ( $p[ 'warnings' ] as $w )
         $lines[] = "  warning: $w";
   }
   foreach ( dispatch_retired_globals_set( $globals ) as $g )
      $lines[] = "retired setting \$$g is still set; the dispatcher ignores it";
   return $lines;
}
