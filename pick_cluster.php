<?php
/*
 * pick_cluster.php
 *
 * Choosing which real cluster a metascheduler-type submission actually runs
 * on. See METASCHEDULER-DESIGN.md for the full design and rationale; this
 * file is deliberately only the pure decision function and its small
 * supporting pieces, with no DB or SSH calls of its own, so every branch is a
 * plain-data unit test (tests/unit/gridctl/PickClusterTest.php).
 */

## Points added to a candidate's score when its probed status is 'warn'
## rather than 'up'. Large enough that a healthy candidate in the same tier
## always wins over a flaky one, small enough that a warn candidate is still
## preferred over falling through to the next tier -- a single bad probe on
## an otherwise-idle local cluster should not bounce a job to a billed
## national one.
const PICK_CLUSTER_WARN_PENALTY = 100000;

/**
 * True when a cluster_details entry is a metascheduler pseudo-entry (has a
 * "clusters" list to pick a real cluster from) rather than a real cluster.
 */
function pick_cluster_is_metascheduler_entry( $entry )
{
   return is_array( $entry )
          && array_key_exists( 'clusters', $entry )
          && is_array( $entry[ 'clusters' ] );
}

/**
 * Approximate the per-group MPI task demand a PCSA or 2DSA/2DSA-CG
 * submission will ask for, from the raw analysis-profile XML already
 * decoded at submitone.php's pick time -- before any real cluster is
 * chosen, so before jobsubmit.php's own tasks_per_group_plan() can run (it
 * reads $this->grid[$cluster]['ppbj'], a per-cluster value that does not
 * exist yet). Mirrors that formula's *inputs*, deliberately without the
 * per-cluster ppbj clamp it also applies: omitting a clamp only ever makes
 * this number larger, which is the safe direction for a pre-filter -- it
 * can rule out a candidate clearly too small, never wrongly accept one
 * that is.
 *
 * $stage values other than 'PCSA' are treated as the 2DSA family (2DSA,
 * 2DSA-CG): submitone.php itself only ever reaches this call for one of
 * those two, never GA or DMGA, whose own demand formulas (e.g. GA's
 * deme-doubling heuristic) are different enough that approximating them
 * here risks a wrong rejection -- strictly worse than this pre-filter
 * simply not applying yet, which is what every other caller already gets
 * by passing no job_requirements at all.
 *
 * @param string $stage     'PCSA', or anything else (the 2DSA family).
 * @param object $xmljson   The analysis profile's XML, already
 *                          json_decode(json_encode(simplexml_load_string(...)))'d
 *                          (submitone.php's own $xmljson).
 * @param string $paramgroup 'p_pcsa' or 'p_2dsa', matching $stage.
 *
 * @return array Suitable as pick_cluster()'s own $job_requirements:
 *               array('maxproc' => int) when a demand could be read,
 *               array() when the XML did not have the shape expected (no
 *               guess -- pick_cluster_meets_requirements() already treats
 *               "not known yet" as "never fails a candidate").
 */
function pick_cluster_estimate_job_requirements( $stage, $xmljson, $paramgroup )
{
   if ( $stage === 'PCSA' )
   {
      ## PCSA carries every attribute (including curve_type and
      ## variations_count) directly on <channel_parms>, never on a separate
      ## job node -- the same first-channel-is-representative assumption
      ## submitone.php's own later $channel_attributes extraction already
      ## makes (see its "is_array($channel_parms)" branch).
      $channel_parms = isset( $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'} )
                      ? $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'} : null;

      $attrs = null;
      if ( is_array( $channel_parms ) && isset( $channel_parms[ 0 ]->{'@attributes'} ) )
      {
         $attrs = $channel_parms[ 0 ]->{'@attributes'};
      }
      elseif ( is_object( $channel_parms ) && isset( $channel_parms->{'@attributes'} ) )
      {
         $attrs = $channel_parms->{'@attributes'};
      }

      if ( $attrs === null )
      {
         return array();   ## not the XML shape expected: no guess, same as "not known yet"
      }

      $vsize      = isset( $attrs->{'variations_count'} ) ? (int) $attrs->{'variations_count'} : 10;
      $curve_type = isset( $attrs->{'curve_type'} ) ? (string) $attrs->{'curve_type'} : null;

      ## A zero or negative variations_count (bad/empty XML) must not produce
      ## a zero or negative maxproc -- pick_cluster_meets_requirements() reads
      ## that as "requirement not resolved", which silently disables the
      ## size filter entirely rather than surfacing the bad input. Same "not
      ## known yet" treatment the missing-attrs branch above already gets.
      if ( $vsize <= 0 )
      {
         error_log( "pick_cluster_estimate_job_requirements: non-positive variations_count "
                  . "(" . var_export( isset( $attrs->{'variations_count'} ) ? $attrs->{'variations_count'} : null, true )
                  . ") -- treating job sizing as not known yet" );
         return array();
      }

      return array( 'maxproc' => ( $curve_type !== 'HL' ) ? $vsize * $vsize : $vsize );
   }

   ## 2DSA / 2DSA-CG: uniform_grid has no XML override anywhere in this
   ## codebase's conversion tables (confirmed against both the shipped
   ## <job_2dsa*> attributes and submitone.php's own $conv_2dsa_keys) --
   ## $defaults_2dsa always uses 6, so this is the one value genuinely known
   ## in advance rather than approximated.
   return array( 'maxproc' => 36 );
}

/**
 * Default thresholds, overridable from global_config.php. Kept as a function
 * (not a constant array) so a deployment can override individual keys via
 * $GLOBALS without having to restate every key.
 */
function pick_cluster_default_config()
{
   return array(
      ## queued+running at/above this on every local candidate means "local
      ## is busy enough to try national instead". 0 disables local overflow
      ## entirely (every job goes national), which is never the intended
      ## default; a deployment that wants that should say so explicitly.
      'saturation_threshold'   => isset( $GLOBALS[ 'global_cluster_saturation_threshold' ] )
                                  ? (int) $GLOBALS[ 'global_cluster_saturation_threshold' ] : 20,

      ## Reuses the same staleness ceiling cluster_status.php's own callers
      ## already apply to a cluster_status row, so one config key governs
      ## both "is this row too old to show in the UI" and "is this row too
      ## old to pick a cluster from" -- a deployment that retunes one case
      ## retunes the other, rather than the two silently drifting apart.
      'max_status_age_seconds' => isset( $GLOBALS[ 'global_cluster_status_max_age_seconds' ] )
                                  ? (int) $GLOBALS[ 'global_cluster_status_max_age_seconds' ] : 900,

      ## How long a pick_cluster_reserve() nudge (see that function's own
      ## comment) stays counted in a snapshot after being written. Long
      ## enough to bridge a burst of decisions moments apart -- the nudge's
      ## only job -- short enough that it never stands in for a real probe
      ## (every 12 minutes by default) for long: a nudge that outlived its
      ## usefulness here used to persist, uncorrected, until the next probe,
      ## which could fabricate "every candidate saturated" for real jobs
      ## submitted minutes after a burst had already finished and drained.
      'nudge_ttl_seconds'      => isset( $GLOBALS[ 'global_cluster_nudge_ttl_seconds' ] )
                                  ? (int) $GLOBALS[ 'global_cluster_nudge_ttl_seconds' ] : 90,
   );
}

/**
 * One candidate's structural fit, mirroring jobsubmit.php's cluster_opt()
 * default-fallback semantics (read a per-cluster tuning key, fall back to a
 * default when the key or the cluster entry is absent) without depending on
 * that class: pick_cluster() is a free function with no object context, and
 * the lookup itself has no real logic to duplicate, just an array default.
 *
 * A requirement key that is null or absent is not checked -- the caller may
 * not have resolved job sizing yet at the point this runs, and "we don't
 * know" must never be treated as "it doesn't fit".
 */
function pick_cluster_meets_requirements( $cluster_entry, $job_requirements )
{
   $opt = function ( $key, $default ) use ( $cluster_entry )
   {
      return is_array( $cluster_entry ) && array_key_exists( $key, $cluster_entry )
             ? $cluster_entry[ $key ] : $default;
   };

   foreach ( array( 'maxproc', 'ppn', 'maxtime' ) as $key )
   {
      if ( ! isset( $job_requirements[ $key ] ) || $job_requirements[ $key ] === null )
      {
         continue;   ## not resolved yet / not asked: cannot fail this check
      }

      $limit = $opt( $key, null );

      if ( $limit === null )
      {
         continue;
      }

      ## A non-numeric limit (a typo'd config value, a sentinel like
      ## 'unlimited' that was never actually wired up) must never silently
      ## become 0 via (int) -- that reads as "this cluster takes zero of
      ## everything", rejecting every sized job with the same "does not meet
      ## job requirements" message a genuinely small cluster gets, with
      ## nothing to tell the two apart. Logged and skipped instead: treated
      ## the same as "not configured", never as "configured to reject
      ## everything".
      if ( ! is_numeric( $limit ) )
      {
         error_log( "pick_cluster_meets_requirements: non-numeric '$key' limit "
                  . "(" . var_export( $limit, true ) . ") in cluster config -- "
                  . "treating as not configured" );
         continue;
      }

      if ( (int) $job_requirements[ $key ] > (int) $limit )
      {
         return false;
      }
   }

   return true;
}

/**
 * Choose one real cluster from $candidates for a metascheduler submission.
 *
 * @param array    $candidates      Real cluster names, in configured order
 *                                  (the tie-break order).
 * @param array    $cluster_details The full $cluster_details array (tier,
 *                                  capability keys, per candidate).
 * @param array    $status_snapshot Rows from gfac.cluster_status, keyed by
 *                                  cluster name: ['status'=>'up'|'warn'|
 *                                  'down'|'unknown', 'queued'=>int,
 *                                  'running'=>int, 'time'=>int (unix ts)].
 *                                  A candidate with no entry here is treated
 *                                  as having no status data.
 * @param array    $job_requirements Optional structural requirements this
 *                                  job needs ('maxproc','ppn','maxtime');
 *                                  any key may be omitted or null.
 * @param array    $config          See pick_cluster_default_config().
 * @param int|null $now             Unix time to score staleness against;
 *                                  null means time(). Threaded through
 *                                  explicitly, the same way
 *                                  cluster_probe_outage_verdict() takes
 *                                  $now, so staleness is a plain unit test
 *                                  rather than something only reproducible
 *                                  by mocking time().
 * @param ?array   $authorized_clusters The submitting person's own
 *                                  cluster-shortname allowlist (the same
 *                                  list require_authorized_cluster() checks
 *                                  for a direct submission), or null to skip
 *                                  this check. Authorization is per real
 *                                  cluster, never inherited from being
 *                                  allowed to pick the metascheduler
 *                                  pseudo-entry itself -- membership in the
 *                                  pool is not membership in every machine
 *                                  behind it. Appended last, after $now, so
 *                                  existing positional callers/tests that
 *                                  predate this parameter are unaffected.
 *
 * @return array{chosen:?string, reason:string, tier:?string, scores:array}
 *         'scores' covers every candidate, eligible or not, each as
 *         ['eligible'=>bool, 'reason'=>?string, 'tier'=>?string,
 *          'status'=>?string, 'load'=>?int], so a decision is auditable from
 *         the log line alone (see pick_cluster_log_line()).
 */
function pick_cluster( array $candidates, array $cluster_details, array $status_snapshot,
                        array $job_requirements = array(), array $config = array(), $now = null,
                        $authorized_clusters = null )
{
   $config = array_merge( pick_cluster_default_config(), $config );
   $now    = $now === null ? time() : (int) $now;

   $scores = array();
   $by_tier = array( 'local' => array(), 'national' => array() );

   foreach ( $candidates as $name )
   {
      $entry = isset( $cluster_details[ $name ] ) ? $cluster_details[ $name ] : null;

      if ( $entry === null )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'not configured',
            'tier' => null, 'status' => null, 'load' => null );
         continue;
      }

      if ( array_key_exists( 'active', $entry ) && ! $entry[ 'active' ] )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'inactive',
            'tier' => isset( $entry[ 'tier' ] ) ? $entry[ 'tier' ] : null, 'status' => null, 'load' => null );
         continue;
      }

      ## Membership in the metascheduler pool is never itself authorization
      ## for a specific real cluster inside it -- each candidate is checked
      ## against the submitting person's own allowlist, same as a direct
      ## submission would be. $authorized_clusters === null means the caller
      ## chose to skip this (e.g. existing tests that predate this check);
      ## every other caller must pass the real list.
      if ( $authorized_clusters !== null && ! in_array( $name, $authorized_clusters, true ) )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'not authorized for this user',
            'tier' => isset( $entry[ 'tier' ] ) ? $entry[ 'tier' ] : null, 'status' => null, 'load' => null );
         continue;
      }

      $tier = isset( $entry[ 'tier' ] ) ? $entry[ 'tier' ] : null;

      ## Explicit metadata only (see METASCHEDULER-DESIGN.md section 1): a
      ## missing tier is a configuration gap to fix, never a guess to make.
      if ( $tier !== 'local' && $tier !== 'national' )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'tier not configured',
            'tier' => null, 'status' => null, 'load' => null );
         continue;
      }

      if ( ! pick_cluster_meets_requirements( $entry, $job_requirements ) )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'does not meet job requirements',
            'tier' => $tier, 'status' => null, 'load' => null );
         continue;
      }

      $row = isset( $status_snapshot[ $name ] ) ? $status_snapshot[ $name ] : null;

      if ( $row === null )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'no status data',
            'tier' => $tier, 'status' => null, 'load' => null );
         continue;
      }

      $status = isset( $row[ 'status' ] ) ? $row[ 'status' ] : 'unknown';

      if ( $status === 'down' )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'down',
            'tier' => $tier, 'status' => $status, 'load' => null );
         continue;
      }

      $age = $now - (int) ( isset( $row[ 'time' ] ) ? $row[ 'time' ] : 0 );

      if ( $age > $config[ 'max_status_age_seconds' ] )
      {
         $scores[ $name ] = array( 'eligible' => false, 'reason' => 'stale status',
            'tier' => $tier, 'status' => $status, 'load' => null );
         continue;
      }

      $load = (int) ( isset( $row[ 'queued' ] ) ? $row[ 'queued' ] : 0 )
            + (int) ( isset( $row[ 'running' ] ) ? $row[ 'running' ] : 0 );

      ## 'warn' stays eligible but is penalized in scoring, not excluded: a
      ## single flaky probe should not take a cluster out of rotation (the
      ## same judgment cluster_status.php's own warn-before-down ladder
      ## already encodes).
      $scored_load = $status === 'warn' ? $load + PICK_CLUSTER_WARN_PENALTY : $load;

      $scores[ $name ] = array( 'eligible' => true, 'reason' => null,
         'tier' => $tier, 'status' => $status, 'load' => $load );

      $by_tier[ $tier ][ $name ] = $scored_load;
   }

   $pool = array();
   $pool_tier = null;

   if ( ! empty( $by_tier[ 'local' ] )
        && min( $by_tier[ 'local' ] ) < $config[ 'saturation_threshold' ] )
   {
      $pool = $by_tier[ 'local' ];
      $pool_tier = 'local';
   }
   elseif ( ! empty( $by_tier[ 'national' ] ) )
   {
      $pool = $by_tier[ 'national' ];
      $pool_tier = 'national';
   }

   if ( empty( $pool ) )
   {
      return array( 'chosen' => null, 'reason' => 'no eligible cluster', 'tier' => null, 'scores' => $scores );
   }

   ## Deterministic tie-break: first-listed-wins. $pool preserves $candidates'
   ## insertion order, so the first minimum found is the first-configured one.
   $winner = null;
   $winner_score = null;

   foreach ( $pool as $name => $scored_load )
   {
      if ( $winner === null || $scored_load < $winner_score )
      {
         $winner = $name;
         $winner_score = $scored_load;
      }
   }

   return array( 'chosen' => $winner, 'reason' => 'lowest-load', 'tier' => $pool_tier, 'scores' => $scores );
}

/** One explainable log line for a pick_cluster() result. See section 4 of the design doc. */
function pick_cluster_log_line( $pseudo_cluster, array $result )
{
   $parts = array();

   foreach ( $result[ 'scores' ] as $name => $score )
   {
      $bits = array( 'eligible: ' . ( $score[ 'eligible' ] ? 'true' : 'false' ) );

      if ( $score[ 'eligible' ] )
      {
         $bits[] = 'tier: ' . $score[ 'tier' ];
         $bits[] = 'load: ' . $score[ 'load' ];
         $bits[] = 'status: ' . $score[ 'status' ];
      }
      else
      {
         $bits[] = 'reason: ' . $score[ 'reason' ];
      }

      $parts[] = "$name: {" . implode( ', ', $bits ) . '}';
   }

   $chosen = $result[ 'chosen' ] === null ? 'null' : $result[ 'chosen' ];

   return "pick_cluster($pseudo_cluster): chosen=$chosen reason={$result['reason']} scores={"
          . implode( ', ', $parts ) . '}';
}

/**
 * Read the live gfac.cluster_status rows for $candidates, with any
 * not-yet-expired pick_cluster_reserve() nudges (see
 * pick_cluster_nudge_counts()) folded into 'queued'. The only non-pure piece
 * in this file; kept to one tiny function so pick_cluster() itself never
 * needs a DB handle to be tested.
 *
 * @param int|null $now               Unix time to age nudges against; null
 *                                     means time(). Threaded through
 *                                     explicitly for the same reason
 *                                     pick_cluster() itself takes $now.
 * @param int|null $nudge_ttl_seconds Overrides
 *                                     pick_cluster_default_config()['nudge_ttl_seconds'].
 *
 * @return array keyed by cluster name, in the shape pick_cluster() expects.
 */
function pick_cluster_status_snapshot( $gfac_link, array $candidates, $now = null, $nudge_ttl_seconds = null )
{
   $snapshot = array();

   if ( empty( $candidates ) )
   {
      return $snapshot;
   }

   $now = $now === null ? time() : (int) $now;

   ## try/catch, not just the $result === false check below: this file's
   ## entire contract is "a failed query leaves data out, never fails the
   ## caller", which mysqli only honors under MYSQLI_REPORT_OFF. Autoflow sets
   ## that globally in gridctl_bootstrap.php, but an interactive submission
   ## reaches this same function through require_cluster.php, which never
   ## loads that bootstrap -- so under PHP 8.1+'s real default
   ## (MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT) the identical failure
   ## throws there instead of returning false, uncaught, turning "no status
   ## data, treat every candidate as ineligible" into a fatal 500. Catching
   ## here makes the function behave the same regardless of which caller
   ## loaded it first or what mysqli_report() is set to process-wide.
   try
   {
      $quoted = array_map(
         function ( $name ) use ( $gfac_link ) { return "'" . mysqli_real_escape_string( $gfac_link, $name ) . "'"; },
         $candidates
      );

      $result = mysqli_query( $gfac_link,
         "SELECT cluster, status, queued, running, UNIX_TIMESTAMP(time) AS epoch"
         . " FROM gfac.cluster_status WHERE cluster IN (" . implode( ',', $quoted ) . ")" );

      if ( $result )
      {
         while ( $row = mysqli_fetch_assoc( $result ) )
         {
            $snapshot[ $row[ 'cluster' ] ] = array(
               'status'  => $row[ 'status' ],
               'queued'  => (int) $row[ 'queued' ],
               'running' => (int) $row[ 'running' ],
               'time'    => (int) $row[ 'epoch' ],
            );
         }
      }
      ## A failed query ($result === false, or caught below) leaves $snapshot
      ## empty: every candidate still reads as "no status data" below, same
      ## as before.
   }
   catch ( \Throwable $e )
   {
      error_log( 'pick_cluster_status_snapshot: ' . $e->getMessage() );
   }

   $nudge_ttl_seconds = $nudge_ttl_seconds === null
                      ? pick_cluster_default_config()[ 'nudge_ttl_seconds' ] : (int) $nudge_ttl_seconds;

   ## A nudge only ever adds load to a candidate this snapshot already has a
   ## real probe row for -- it cannot manufacture eligibility for a candidate
   ## with no status data, which stays ineligible exactly as it always has.
   foreach ( pick_cluster_nudge_counts( $gfac_link, $candidates, $now, $nudge_ttl_seconds ) as $name => $count )
   {
      if ( $count > 0 && isset( $snapshot[ $name ] ) )
      {
         $snapshot[ $name ][ 'queued' ] += $count;
      }
   }

   return $snapshot;
}

/**
 * Count each candidate's not-yet-expired nudges from gfac.cluster_status_nudge
 * (written by pick_cluster_record_nudge()). Separate from cluster_status
 * itself -- which only a real probe ever writes -- so a nudge ages out on
 * its own after $ttl_seconds instead of persisting, uncorrected, until the
 * next probe (every 12 minutes by default): a burst of decisions large
 * enough to nudge every candidate past saturation_threshold used to read as
 * "every candidate saturated" for the full gap until the next probe, even
 * once every one of those jobs had already finished.
 *
 * @return array keyed by every entry in $candidates (0 when absent), so a
 *         caller can always add this directly onto a snapshot's 'queued'.
 */
function pick_cluster_nudge_counts( $gfac_link, array $candidates, $now, $ttl_seconds )
{
   $counts = array_fill_keys( $candidates, 0 );

   if ( empty( $candidates ) )
   {
      return $counts;
   }

   ## See pick_cluster_status_snapshot()'s own comment on why this is caught,
   ## not just checked for a false return: the interactive path reaches this
   ## function without autoflow's MYSQLI_REPORT_OFF, so a query failure here
   ## throws there instead.
   try
   {
      $quoted = array_map(
         function ( $name ) use ( $gfac_link ) { return "'" . mysqli_real_escape_string( $gfac_link, $name ) . "'"; },
         $candidates
      );

      $cutoff = gmdate( 'Y-m-d H:i:s', $now - max( 0, (int) $ttl_seconds ) );

      $result = mysqli_query( $gfac_link,
         "SELECT cluster, COUNT(*) AS n FROM gfac.cluster_status_nudge"
         . " WHERE cluster IN (" . implode( ',', $quoted ) . ")"
         . " AND time >= '" . mysqli_real_escape_string( $gfac_link, $cutoff ) . "'"
         . " GROUP BY cluster" );

      if ( $result )
      {
         while ( $row = mysqli_fetch_assoc( $result ) )
         {
            $counts[ $row[ 'cluster' ] ] = (int) $row[ 'n' ];
         }
      }
   }
   catch ( \Throwable $e )
   {
      error_log( 'pick_cluster_nudge_counts: ' . $e->getMessage() );
   }

   return $counts;
}

/**
 * Record one pick_cluster_reserve() decision's nudge, and opportunistically
 * purge nudges past $ttl_seconds -- piggybacked here, rather than run as a
 * separate cron, since every nudge write is already a round trip to this
 * table; a nudge just past the cutoff was already excluded by
 * pick_cluster_nudge_counts()'s own WHERE clause before this delete ever
 * runs, so the purge is cleanup, not a correctness requirement.
 */
function pick_cluster_record_nudge( $gfac_link, $cluster, $now, $ttl_seconds )
{
   ## Best-effort, same as the rest of this file -- caught so a thrown
   ## failure (interactive path, no MYSQLI_REPORT_OFF) is as harmless to the
   ## caller as a prepare() that merely returned false already was.
   try
   {
      $stmt = mysqli_prepare( $gfac_link,
         'INSERT INTO gfac.cluster_status_nudge (cluster, time) VALUES (?, FROM_UNIXTIME(?))' );

      if ( $stmt )
      {
         mysqli_stmt_bind_param( $stmt, 'si', $cluster, $now );
         mysqli_stmt_execute( $stmt );
         mysqli_stmt_close( $stmt );
      }

      $cutoff_stmt = mysqli_prepare( $gfac_link,
         'DELETE FROM gfac.cluster_status_nudge WHERE time < FROM_UNIXTIME(?)' );

      if ( $cutoff_stmt )
      {
         $cutoff = $now - max( 0, (int) $ttl_seconds );
         mysqli_stmt_bind_param( $cutoff_stmt, 'i', $cutoff );
         mysqli_stmt_execute( $cutoff_stmt );
         mysqli_stmt_close( $cutoff_stmt );
      }
   }
   catch ( \Throwable $e )
   {
      error_log( 'pick_cluster_record_nudge: ' . $e->getMessage() );
   }
}
