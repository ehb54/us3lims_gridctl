<?php
/* Dispatch selection: a pure decision over one pool's policy, candidate plans,
 * observations, authorization, reachability and rotation state. It never
 * reads job counts. PHP 7.2 compatible. */

require_once __DIR__ . '/dispatch_policy.php';

## Assessment verdicts, equal to common's dispatch_contract.php ASSESS_* values.
const SELECT_VERDICT_READY    = 'READY';
const SELECT_VERDICT_REJECTED = 'REJECTED';
const SELECT_VERDICT_UNKNOWN  = 'UNKNOWN';

const SELECT_SUBMIT  = 'submit';   ## submit to 'chosen'
const SELECT_WAIT    = 'wait';     ## transient: reassess later
const SELECT_BLOCKED = 'blocked';  ## needs a person: authorization, configuration or request
const SELECT_FAILED  = 'failed';   ## the pool is switched off

/**
 * One smooth weighted round-robin step over $eligible members in group order.
 * Only eligible members gain weight this round; the chosen member gives back
 * the round's total. Returns array( chosen, new state ). Equal weights give
 * plain round robin. Ties keep group order.
 */
function dispatch_rotation_step( array $members, array $weights, array $eligible, array $state )
{
   $current = isset( $state[ 'current' ] ) && is_array( $state[ 'current' ] ) ? $state[ 'current' ] : array();
   $total = 0;
   $chosen = null;
   foreach ( $members as $m ) {
      if ( ! in_array( $m, $eligible, true ) ) {
         continue;
      }
      $w = isset( $weights[ $m ] ) ? (int) $weights[ $m ] : 1;
      $current[ $m ] = ( isset( $current[ $m ] ) ? (int) $current[ $m ] : 0 ) + $w;
      $total += $w;
      if ( $chosen === null || $current[ $m ] > $current[ $chosen ] ) {
         $chosen = $m;
      }
   }
   if ( $chosen !== null ) {
      $current[ $chosen ] -= $total;
   }
   return array( $chosen, array( 'current' => $current ) );
}

/** Pick one member of a group by its strategy: array( chosen, new rotation state or null ). */
function dispatch_pick( array $group, array $eligible, array $state )
{
   if ( ! $eligible ) {
      return array( null, null );
   }
   if ( $group[ 'strategy' ] === 'ordered' ) {
      foreach ( $group[ 'members' ] as $m ) {
         if ( in_array( $m, $eligible, true ) ) {
            return array( $m, null );
         }
      }
      return array( null, null );
   }
   return dispatch_rotation_step( $group[ 'members' ], $group[ 'weights' ], $eligible, $state );
}

/**
 * Select a destination for one dispatch unit (one batch, or one request outside a batch).
 *
 * $in keys:
 *   policy        dispatch_pool_policy() result
 *   plans         member => candidate plan (batch: the preflight result for all children)
 *   observations  member => assessment observation (optional under queue admission)
 *   authorized    member => dispatch_cluster_authorization() result
 *   reachable     member => true | false | null (null: not known, treated as reachable)
 *   rotation      array( 'primary' => state, 'overflow' => state )
 *   pinned        a member a batch is already pinned to, or null
 *
 * Returns: outcome (SELECT_*), chosen, group, reason, candidates (member => reasons),
 * rotation (array( group => tentative state ) to commit only on acceptance), policy_version.
 */
function dispatch_select( array $in )
{
   $policy = $in[ 'policy' ];
   $plans = isset( $in[ 'plans' ] ) ? $in[ 'plans' ] : array();
   $obs = isset( $in[ 'observations' ] ) ? $in[ 'observations' ] : array();
   $auth = isset( $in[ 'authorized' ] ) ? $in[ 'authorized' ] : array();
   $reach = isset( $in[ 'reachable' ] ) ? $in[ 'reachable' ] : array();
   $rotation = isset( $in[ 'rotation' ] ) ? $in[ 'rotation' ] : array();
   $pinned = isset( $in[ 'pinned' ] ) ? $in[ 'pinned' ] : null;

   $decision = array( 'outcome' => SELECT_WAIT, 'chosen' => null, 'group' => null, 'reason' => '', 'code' => '',
                      'candidates' => array(), 'rotation' => array(),
                      'policy_version' => isset( $policy[ 'version' ] ) ? $policy[ 'version' ] : '' );

   if ( empty( $policy[ 'active' ] ) ) {
      $disabled = false;
      foreach ( $policy[ 'reasons' ] as $r ) {
         $disabled = $disabled || strpos( $r, 'is disabled (active=false)' ) !== false;
      }
      $decision[ 'outcome' ] = $disabled ? SELECT_FAILED : SELECT_BLOCKED;
      $decision[ 'reason' ] = implode( '; ', $policy[ 'reasons' ] );
      $decision[ 'code' ] = $disabled ? 'pool_disabled' : 'configuration';
      return $decision;
   }

   $groups = array( 'primary' => $policy[ 'primary' ] );
   if ( $policy[ 'overflow' ] && $policy[ 'overflow_enabled' ] ) {
      $groups[ 'overflow' ] = $policy[ 'overflow' ];
   }

   ## Eligibility first: a member that cannot take this request is never ranked.
   $eligible = array();
   $permanent = 0;
   $considered = 0;
   foreach ( $groups as $gname => $group ) {
      $eligible[ $gname ] = array();
      foreach ( $group[ 'members' ] as $m ) {
         $considered++;
         $c = array( 'group' => $gname, 'eligible' => false, 'verdict' => null, 'reason' => '', 'cause' => '' );
         $o = isset( $obs[ $m ] ) ? $obs[ $m ] : null;
         $c[ 'verdict' ] = $o ? $o[ 'verdict' ] : null;
         if ( isset( $policy[ 'ineligible' ][ $m ] ) ) {
            $c[ 'reason' ] = $policy[ 'ineligible' ][ $m ];
            $c[ 'cause' ] = 'configuration';
            $permanent++;
         }
         elseif ( $pinned !== null && $m !== $pinned ) {
            $c[ 'reason' ] = "batch is pinned to $pinned";
            $c[ 'cause' ] = 'pinned';
         }
         elseif ( ! isset( $auth[ $m ] ) || empty( $auth[ $m ][ 'authorized' ] ) ) {
            $c[ 'reason' ] = isset( $auth[ $m ][ 'reason' ] ) ? $auth[ $m ][ 'reason' ] : 'authorization not checked';
            $c[ 'cause' ] = 'authorization';
            $permanent++;
         }
         elseif ( ! isset( $plans[ $m ] ) || empty( $plans[ $m ][ 'ok' ] ) ) {
            $c[ 'reason' ] = isset( $plans[ $m ][ 'error' ] ) ? $plans[ $m ][ 'error' ] : 'no plan for this member';
            $c[ 'cause' ] = 'plan';
            $permanent++;
         }
         elseif ( $o && $o[ 'verdict' ] === SELECT_VERDICT_REJECTED ) {
            $c[ 'reason' ] = 'scheduler rejected the request: ' . $o[ 'reason' ];
            $c[ 'cause' ] = 'rejected';
            $permanent++;
         }
         elseif ( isset( $reach[ $m ] ) && $reach[ $m ] === false ) {
            $c[ 'reason' ] = 'unreachable';
            $c[ 'cause' ] = 'unreachable';
         }
         else {
            $c[ 'eligible' ] = true;
            $c[ 'reason' ] = $o ? $o[ 'reason' ] : 'eligible';
            $eligible[ $gname ][] = $m;
         }
         $decision[ 'candidates' ][ $m ] = $c;
      }
   }

   $choose = function ( $gname, array $members ) use ( &$decision, $groups, $rotation ) {
      $state = isset( $rotation[ $gname ] ) ? $rotation[ $gname ] : array();
      list( $m, $new ) = dispatch_pick( $groups[ $gname ], $members, $state );
      if ( $m === null ) {
         return false;
      }
      $decision[ 'outcome' ] = SELECT_SUBMIT;
      $decision[ 'chosen' ] = $m;
      $decision[ 'group' ] = $gname;
      if ( $new !== null ) {
         $decision[ 'rotation' ][ $gname ] = $new;
      }
      return true;
   };

   $verdict = function ( $m ) use ( $obs ) {
      return isset( $obs[ $m ] ) ? $obs[ $m ][ 'verdict' ] : SELECT_VERDICT_UNKNOWN;
   };

   ## A pinned batch never moves: submit there or wait for it.
   if ( $pinned !== null ) {
      if ( $choose( 'primary', array_intersect( $eligible[ 'primary' ], array( $pinned ) ) )
           || ( isset( $eligible[ 'overflow' ] ) && $choose( 'overflow', array_intersect( $eligible[ 'overflow' ], array( $pinned ) ) ) ) ) {
         $decision[ 'reason' ] = "batch stays at $pinned";
         return $decision;
      }
      $decision[ 'reason' ] = "pinned destination $pinned cannot take work now";
      $decision[ 'code' ] = isset( $decision[ 'candidates' ][ $pinned ] )
                          && $decision[ 'candidates' ][ $pinned ][ 'cause' ] === 'unreachable' ? 'unreachable' : 'occupied';
      return $decision;
   }

   $no_one = ! $eligible[ 'primary' ] && ( ! isset( $eligible[ 'overflow' ] ) || ! $eligible[ 'overflow' ] );
   if ( $no_one ) {
      $decision[ 'outcome' ] = $permanent === $considered ? SELECT_BLOCKED : SELECT_WAIT;
      $decision[ 'reason' ] = $permanent === $considered ? 'no member can ever take this request'
                                                          : 'no member can take this request now';
      $decision[ 'code' ] = dispatch_select_cause_code( $decision[ 'candidates' ], $permanent === $considered );
      return $decision;
   }

   if ( $policy[ 'admission' ] === 'queue' ) {
      if ( $choose( 'primary', $eligible[ 'primary' ] ) ) {
         $decision[ 'reason' ] = 'queue admission';
         return $decision;
      }
      if ( isset( $eligible[ 'overflow' ] ) && $choose( 'overflow', $eligible[ 'overflow' ] ) ) {
         $decision[ 'reason' ] = 'queue admission: no preferred member reachable';
         return $decision;
      }
   }

   ## start_within_allowance
   $ready = array_values( array_filter( $eligible[ 'primary' ], function ( $m ) use ( $verdict ) {
      return $verdict( $m ) === SELECT_VERDICT_READY;
   } ) );
   if ( $choose( 'primary', $ready ) ) {
      $decision[ 'reason' ] = 'preferred member can start within the allowance';
      return $decision;
   }

   $unknown = array_values( array_filter( $eligible[ 'primary' ], function ( $m ) use ( $verdict ) {
      return $verdict( $m ) === SELECT_VERDICT_UNKNOWN;
   } ) );
   $may_overflow = ! $unknown || $policy[ 'overflow_on_assessment_unknown' ];
   if ( $may_overflow && isset( $eligible[ 'overflow' ] ) && $eligible[ 'overflow' ] ) {
      $overflow_ready = array_values( array_filter( $eligible[ 'overflow' ], function ( $m ) use ( $verdict ) {
         return $verdict( $m ) === SELECT_VERDICT_READY;
      } ) );
      if ( $choose( 'overflow', $overflow_ready ? $overflow_ready : $eligible[ 'overflow' ] ) ) {
         $decision[ 'reason' ] = $unknown ? 'overflow permitted although preferred availability is unknown'
                                          : 'no preferred member can start within the allowance';
         return $decision;
      }
   }

   if ( $policy[ 'fallback' ] === 'queue_preferred' && $eligible[ 'primary' ] ) {
      $members = $eligible[ 'primary' ];
      if ( $groups[ 'primary' ][ 'strategy' ] === 'ordered' ) {
         ## Earliest usable estimate, then configured order.
         $order = array_flip( $groups[ 'primary' ][ 'members' ] );
         usort( $members, function ( $a, $b ) use ( $obs, $order ) {
            $sa = isset( $obs[ $a ][ 'start_at' ] ) ? $obs[ $a ][ 'start_at' ] : PHP_INT_MAX;
            $sb = isset( $obs[ $b ][ 'start_at' ] ) ? $obs[ $b ][ 'start_at' ] : PHP_INT_MAX;
            return $sa !== $sb ? ( $sa < $sb ? -1 : 1 ) : $order[ $a ] - $order[ $b ];
         } );
         $members = array( $members[ 0 ] );
      }
      if ( $choose( 'primary', $members ) ) {
         $decision[ 'reason' ] = 'queued at a preferred member';
         return $decision;
      }
   }

   $decision[ 'outcome' ] = SELECT_WAIT;
   $decision[ 'reason' ] = $unknown && ! $policy[ 'overflow_on_assessment_unknown' ]
                         ? 'preferred availability unknown; waiting for a fresh assessment'
                         : 'no member can start within the allowance; waiting';
   $decision[ 'code' ] = $unknown && ! $policy[ 'overflow_on_assessment_unknown' ] ? 'availability_unknown' : 'occupied';
   return $decision;
}

/**
 * The reason code when no member is eligible, from the candidates' causes:
 * permanently no permission, no fitting profile or a configuration problem;
 * otherwise every reachable destination is temporarily out.
 */
function dispatch_select_cause_code( array $candidates, $permanent )
{
   $causes = array();
   foreach ( $candidates as $c ) {
      if ( $c[ 'cause' ] !== '' && $c[ 'cause' ] !== 'pinned' ) {
         $causes[ $c[ 'cause' ] ] = true;
      }
   }
   if ( ! $permanent ) {
      return isset( $causes[ 'unreachable' ] ) ? 'unreachable' : 'occupied';
   }
   if ( isset( $causes[ 'authorization' ] ) && count( $causes ) === 1 ) {
      return 'no_permission';
   }
   return isset( $causes[ 'plan' ] ) || isset( $causes[ 'rejected' ] ) ? 'no_fit' : 'configuration';
}
