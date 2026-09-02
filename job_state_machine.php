<?php
/*
 * job_state_machine.php
 *
 * The one implementation of "what should happen to this job now", shared by
 * gridctl.php (the per-minute cron sweep over gfac.analysis) and
 * jobmonitor/gridctl.php (the function library behind the per-job
 * jobmonitor.php daemon). Both act on the same rows, so whichever fires first
 * decides the outcome. The policy therefore has to be identical, which means
 * it has to live in one place.
 *
 * WHAT STAYS SEPARATE
 *
 * The two entry points are different programs and are meant to be: one is a
 * sweep that exits, the other a daemon that polls. What differs between them
 * is context, not policy. Which mysqli handle, which logger, which database
 * prefix, and whether a process lives long enough for "have I already mailed
 * the admin about this job" to mean anything. All of that is constructor
 * arguments.
 *
 * Both entry files keep the old global function names and signatures, because
 * cleanup.php and cleanup_job.php call update_autoflow_status(),
 * get_us3_data() and mail_to_admin() from shared code that does not know which
 * of the two included it. Those globals are one-line shims onto this class.
 */

## $class_dir comes from listen-config.php. Guarded so a test process that has
## already loaded cluster_probe.php by another route does not reload it.
if ( ! function_exists( 'cluster_probe_job_status' ) )
{
   require_once __DIR__ . '/cluster_probe.php';
}

require_once __DIR__ . '/job_status.php';

class job_state_machine
{
   ## Statuses that mean "still waiting to start", i.e. no news. UNKNOWN is the
   ## cluster saying it has no record; GRIDCTL_UNREACHABLE is us failing to ask.
   ## Neither is grounds for rewriting the stored status here.
   const QUEUED_STATES  = array( 'SUBMITTED', 'INITIALIZED', 'PENDING', 'UNKNOWN', GRIDCTL_UNREACHABLE );
   const RUNNING_STATES = array( 'ACTIVE', 'RUNNING', 'STARTED', 'UNKNOWN', GRIDCTL_UNREACHABLE );

   ## Nothing is judged stalled inside this window. A job whose status was
   ## touched in the last ten minutes is simply in progress.
   const SETTLE_SECONDS = 600;

   private $gfac;         ## mysqli reaching the gfac tables
   private $us3;          ## mysqli or callable returning one, for the us3 tables
   private $gfac_prefix;  ## '' when $gfac is connected to the gfac db, else 'gfac.'
   private $log;          ## callable( string )
   ## callable( $type, $msg ). Whether a second mail about the same job is
   ## suppressed is the entry point's business, not this class's: it depends on
   ## whether the caller is a one-shot sweep or a daemon that lives as long as
   ## the job does, so the deduplicating wrapper is supplied from there.
   private $mailer;

   ## Per job. Reset by for_job() on every row the sweep visits; set once by
   ## the daemon.
   private $gfacID     = '';
   private $cluster    = '';
   private $us3_db     = '';
   private $autoflowID = 0;

   public function __construct( $gfac, $us3, $gfac_prefix, $log, $mailer )
   {
      $this->gfac        = $gfac;
      $this->us3         = $us3;
      $this->gfac_prefix = $gfac_prefix;
      $this->log         = $log;
      $this->mailer      = $mailer;
   }

   public function for_job( $gfacID, $cluster, $us3_db, $autoflowID )
   {
      $this->gfacID     = $gfacID;
      $this->cluster    = $cluster;
      $this->us3_db     = $us3_db;
      $this->autoflowID = (int) $autoflowID;

      return $this;
   }

   public function cluster() { return $this->cluster; }

   ## ----------------------------------------------------------------- ##
   ## Configuration                                                      ##
   ## ----------------------------------------------------------------- ##

   /**
    * Hours a job may sit in one state before the stall clock fires.
    *
    * Zero or negative disables the timeout entirely, which is a supported
    * setting: a site running jobs longer than any sensible ceiling would
    * rather have them sit than be closed out. The sweep used to ignore this
    * and time the job out at a hardcoded 24 hours anyway.
    */
   private function stall_hours( $global_key, $default )
   {
      $hours = isset( $GLOBALS[ $global_key ] ) ? $GLOBALS[ $global_key ] : $default;

      return (int) $hours;
   }

   private function abandon_hours()
   {
      return isset( $GLOBALS[ 'global_cluster_abandon_hours' ] )
             ? (int) $GLOBALS[ 'global_cluster_abandon_hours' ] : 72;
   }

   ## ----------------------------------------------------------------- ##
   ## Plumbing                                                           ##
   ## ----------------------------------------------------------------- ##

   private function logf( $msg )
   {
      call_user_func( $this->log, $msg );
   }

   private function mail_admin( $type, $msg )
   {
      call_user_func( $this->mailer, $type, $msg );
   }

   ## The us3 tables live in a per-experiment database and, in the sweep, are
   ## reached over a different connection than the gfac tables: the sweep's
   ## gfac handle authenticates as the gfac user, which is not the account the
   ## us3 schemas are granted to. Resolved lazily so a pass that never touches
   ## a us3 table never opens the connection.
   private function us3()
   {
      if ( is_callable( $this->us3 ) )
         $this->us3 = call_user_func( $this->us3 );

      return $this->us3;
   }

   private function gfac_table( $name )
   {
      return $this->gfac_prefix . $name;
   }

   private function us3_table( $name )
   {
      return $this->us3_db . '.' . $name;
   }

   ## Run a statement and log rather than throw on failure. Every caller here
   ## is a status update on a best-effort path: losing one must not take the
   ## sweep or the daemon down with it.
   protected function exec( $handle, $query )
   {
      $result = mysqli_query( $handle, $query );

      if ( ! $result )
         $this->logf( "Query failed $query - " . mysqli_error( $handle ) );

      return $result;
   }

   protected function quote( $handle, $value )
   {
      return mysqli_real_escape_string( $handle, $value );
   }

   ## The three mysqli calls that are not statement execution, given their own
   ## names so a test double can stand in for the database without one. Every
   ## database access in this class goes through exec(), quote(), fetch_row()
   ## or num_rows() and nothing else.
   protected function fetch_row( $result )
   {
      return mysqli_fetch_array( $result );
   }

   protected function num_rows( $result )
   {
      return mysqli_num_rows( $result );
   }

   protected function us3_link()
   {
      return $this->us3();
   }

   ## ----------------------------------------------------------------- ##
   ## Asking the cluster                                                 ##
   ## ----------------------------------------------------------------- ##

   /**
    * May return GRIDCTL_UNREACHABLE, which is NOT a job state: it means the
    * cluster could not be asked. Every caller must leave the job alone then.
    */
   public function get_local_status()
   {
      $log    = $this->log;
      $status = cluster_probe_job_status( $this->cluster, $this->gfacID, $log );

      $this->logf( "get_local_status( {$this->gfacID} ) on {$this->cluster} = $status" );

      return $status;
   }

   /**
    * Returns true only when scancel was actually delivered.
    *
    * Both entry points call this now. The sweep never used to, so whether a
    * timed-out job was really stopped depended on which worker reached it
    * first; the loser's jobs kept running and kept burning allocation while
    * the LIMS recorded them as timed out.
    */
   ## Is the cluster answering at all? Its own seam so the stall policy can be
   ## tested against an outage without one.
   protected function reachable()
   {
      return cluster_probe_reachable( $this->cluster, $this->log );
   }

   public function cancel_local_job()
   {
      return cluster_probe_cancel_job( $this->cluster, $this->gfacID, $this->log );
   }

   /**
    * Should a stall clock be allowed to fire? Returns 'proceed', 'defer' or
    * 'abandon'.
    *
    * The stall timers measure wall time since the last status update, so an
    * outage that stops status updates makes every waiting job look hung, which
    * is exactly backwards: during an outage the jobs are usually fine and the
    * LIMS is blind. Deferring fixes that. Deferring without a ceiling trades
    * one bug for another, since a cluster that is decommissioned or never
    * coming back would hold its jobs in 'submitted' forever with nobody ever
    * seeing them. The ceiling is where "we cannot tell" becomes an answer.
    *
    * It is measured from the same $updatetime the stall timers use, so it is
    * always the longer of the two clocks: a job reaches its stall timeout
    * first and only then starts accumulating deferrals against the ceiling.
    */
   public function outage_timeout_verdict( $what, $updatetime )
   {
      $verdict = cluster_probe_outage_verdict(
         $this->reachable(), $updatetime, $this->abandon_hours(), time()
      );

      if ( $verdict !== 'defer' )
         return $verdict;

      $message = "$what deferred: cluster {$this->cluster} is not reachable,"
                 . " so elapsed time does not indicate a hung job";

      $this->logf( "$message - id: {$this->gfacID}" );
      $this->update_queue_messages( $message );

      return 'defer';
   }

   /**
    * Close a job out because its cluster has been unreachable past the ceiling.
    *
    * $enum_status must be a value gfac.analysis.status actually accepts,
    * SUBMIT_TIMEOUT or RUN_TIMEOUT. There is deliberately no UNREACHABLE
    * member: adding one needs a schema migration, and a timeout describes what
    * happened well enough. The distinction that matters to a human lives in
    * the message and in the autoflow status, which is free text and so can
    * carry CLUSTER_UNAVAILABLE, the thing that tells an operator to look at
    * the site rather than at the job.
    *
    * No cancel here, unlike the ordinary timeout paths: the cluster is
    * unreachable by definition, so there is nothing to send scancel to.
    */
   public function abandon_for_outage( $enum_status, $what )
   {
      $hours   = $this->abandon_hours();
      $message = "$what abandoned: cluster {$this->cluster} has not answered for over $hours hours."
                 . " The job's true state is unknown, it may have completed, failed, or still be queued."
                 . " Resubmit once {$this->cluster} is available again.";

      $this->logf( "$message - id: {$this->gfacID}" );
      $this->mail_admin( "hang", "$message - id: {$this->gfacID}" );

      $this->exec( $this->gfac,
         "UPDATE " . $this->gfac_table( 'analysis' ) . " SET status='$enum_status'"
         . " WHERE gfacID='{$this->gfacID}'" );

      $this->update_queue_messages( $message );
      $this->update_db( $message );
      $this->update_autoflow_status( $enum_status, $message );
   }

   ## ----------------------------------------------------------------- ##
   ## The stall clocks                                                   ##
   ## ----------------------------------------------------------------- ##

   /**
    * The tail shared by all four stall paths: decide whether the clock may
    * fire, and if so close the job out.
    *
    * Returns without acting while the job is still inside its window, or when
    * the window is disabled, or when the cluster is unreachable and the
    * abandonment ceiling has not been passed.
    */
   private function fire_stall( $updatetime, $window_seconds, $enum_status, $what, $message )
   {
      if ( $window_seconds <= 0 )
         return;

      if ( $updatetime + $window_seconds > time() )
         return;

      switch ( $this->outage_timeout_verdict( $what, $updatetime ) )
      {
         case 'defer':
            return;

         case 'abandon':
            $this->abandon_for_outage( $enum_status, $what );
            return;
      }

      $this->logf( "$message - id: {$this->gfacID}" );
      $this->mail_admin( "hang", "$message - id: {$this->gfacID}" );

      $this->exec( $this->gfac,
         "UPDATE " . $this->gfac_table( 'analysis' ) . " SET status='$enum_status'"
         . " WHERE gfacID='{$this->gfacID}'" );

      $this->update_queue_messages( $message );
      $this->update_db( $message );
      $this->update_autoflow_status( $enum_status, $message );

      ## The verdict above came back 'proceed', which means the cluster
      ## answered a moment ago, so there is something there to cancel against.
      ## The sweep never used to do this and the daemon always did, so whether
      ## a timed-out job was really stopped came down to which won the race.
      $this->cancel_local_job();
   }

   /**
    * Ask the cluster where the job actually is, and record any answer that
    * contradicts the status we are holding. $live_states are the answers that
    * mean "no news", including the two that are not job states at all:
    * UNKNOWN (the cluster has no record) and GRIDCTL_UNREACHABLE (we never got
    * to ask). Neither is grounds for rewriting anything.
    *
    * Returns true when the job has moved on and the caller should stop.
    */
   private function reconcile( $live_states, $what )
   {
      $job_status = $this->get_local_status();

      if ( in_array( $job_status, $live_states ) )
         return false;

      $this->logf( "$what: job_status=$job_status" );
      $this->update_job_status( $job_status );

      return true;
   }

   /** Job has been sitting in SUBMITTED. First stall window. */
   public function submitted( $updatetime )
   {
      $hours  = $this->stall_hours( 'global_max_queue_time_hours', 24 );
      $window = $hours * 3600;

      if ( $updatetime + self::SETTLE_SECONDS > time() )
         return;

      ## Inside the window there is nothing to decide yet, but it is still
      ## worth asking whether the job has moved on.
      if ( $window > 0 && $updatetime + $window > time() )
      {
         $this->reconcile( self::QUEUED_STATES, 'submitted' );
         return;
      }

      $this->fire_stall( $updatetime, $window, 'SUBMIT_TIMEOUT', 'submit timeout',
         "Job listed submitted longer than $hours hours" );
   }

   /** Job has been sitting in SUBMIT_TIMEOUT. Second window, then give up. */
   public function submit_timeout( $updatetime )
   {
      $hours = $this->stall_hours( 'global_max_queue_time_hours', 24 );

      ## Already moved on: the first timeout was premature and the job is fine.
      if ( $this->reconcile( self::QUEUED_STATES, 'submit timeout' ) )
         return;

      $this->fire_stall( $updatetime, $hours * 3600, 'FAILED', 'submit timeout (final)',
         "Job listed submitted longer than " . ( 2 * $hours ) . " hours" );
   }

   /** Job is RUNNING. First stall window. */
   public function running( $updatetime, $queue_msg )
   {
      $hours  = $this->stall_hours( 'global_max_run_time_hours', 24 );
      $window = $hours * 3600;

      $this->get_us3_data();
      $this->update_autoflow_status( 'RUNNING', $queue_msg );

      if ( $updatetime + self::SETTLE_SECONDS > time() )
         return;

      if ( $window > 0 && $updatetime + $window > time() )
      {
         $this->reconcile( self::RUNNING_STATES, 'running' );
         return;
      }

      $this->fire_stall( $updatetime, $window, 'RUN_TIMEOUT', 'run timeout',
         "Job listed running longer than $hours hours" );
   }

   /** Job is in RUN_TIMEOUT. Second window, then give up. */
   public function run_timeout( $updatetime )
   {
      $hours = $this->stall_hours( 'global_max_run_time_hours', 24 );

      if ( $this->reconcile( self::RUNNING_STATES, 'run timeout' ) )
         return;

      $this->get_us3_data();

      $this->fire_stall( $updatetime, $hours * 3600, 'FAILED', 'run timeout (final)',
         "Job listed running longer than " . ( 2 * $hours ) . " hours" );
   }

   ## ----------------------------------------------------------------- ##
   ## Writing status                                                     ##
   ## ----------------------------------------------------------------- ##

   /**
    * Record a status the cluster reported.
    *
    * The switch this replaced did three jobs at once: it normalised aliases,
    * it chose a user-facing message, and it handed the same string to three
    * columns that speak three different vocabularies. That last part is what
    * put FINISHED and DONE into an ENUM that has no such members, and what
    * left every timed-out autoflow pipeline hanging. Normalisation now happens
    * once, in job_status.php, and each column is written in its own words.
    */
   public function update_job_status( $job_status )
   {
      $this->logf( "update_job_status( '$job_status', '{$this->gfacID}' )" );

      $log    = $this->log;
      ## 'ERROR' rather than the default: a status we cannot parse is a real
      ## condition an operator needs recorded, not one to pass over quietly.
      $status = job_status_normalise( $job_status, $log, 'ERROR' );

      if ( $status === null )
      {
         ## Nothing was learned about the job. GRIDCTL_UNREACHABLE is the
         ## important case: the previous status is the best information we
         ## have, and the next pass will ask again. Collapsing this into
         ## UNKNOWN is what errored out thousands of healthy jobs during the
         ## outage this work started from.
         $this->logf( "status '$job_status' says nothing about the job, leaving it untouched" );
         $this->update_queue_messages( "Cluster unreachable; job status could not be checked" );
         return;
      }

      $this->exec( $this->gfac,
         "UPDATE " . $this->gfac_table( 'analysis' ) . " SET status='$status'"
         . " WHERE gfacID='{$this->gfacID}'" );

      $message = $this->status_message( $status );

      if ( $message !== null )
      {
         $this->update_queue_messages( $message );
         $this->update_db( $message );
      }

      $this->update_autoflow_status( $status, $message !== null ? $message : $status );
   }

   /**
    * The sentence a user sees for a job status, or null when the transition is
    * bookkeeping they should not be notified about.
    */
   private function status_message( $status )
   {
      switch ( $status )
      {
         case 'SUBMITTED': return "Job status request reports job is SUBMITTED";
         case 'RUNNING':   return "Job status request reports job is RUNNING";
         case 'COMPLETE':  return "Job status request reports job is COMPLETED";
         case 'DATA':      return "Job status request reports job is COMPLETE, waiting for data";
         case 'CANCELED':  return "Job status request reports job is CANCELED";
         case 'FAILED':    return "Job status request reports job is FAILED";
         case 'ERROR':     return "Job status request reports job is not in the queue";
      }

      return null;
   }

   public function update_queue_messages( $message )
   {
      $analysis = $this->gfac_table( 'analysis' );
      $result   = $this->exec( $this->gfac,
         "SELECT id FROM $analysis WHERE gfacID = '{$this->gfacID}'" );

      if ( ! $result )
         return;

      $row = $this->fetch_row( $result );

      if ( ! $row )
         return;

      list( $analysisID ) = $row;

      $this->exec( $this->gfac,
         "INSERT INTO " . $this->gfac_table( 'queue_messages' ) . " SET "
         . "message = '" . $this->quote( $this->gfac, $message ) . "', "
         . "analysisID = '$analysisID'" );
   }

   /** Record the user-visible message against the job's HPCAnalysisResult row. */
   public function update_db( $message )
   {
      $us3 = $this->us3_link();

      if ( ! $us3 )
         return;

      $requestID = $this->get_us3_data();

      $this->exec( $us3,
         "UPDATE " . $this->us3_table( 'HPCAnalysisResult' ) . " SET "
         . "lastMessage='" . $this->quote( $us3, $message ) . "' "
         . "WHERE gfacID = '{$this->gfacID}' AND HPCAnalysisRequestID = '$requestID'" );
   }

   /**
    * Look up this job's HPCAnalysisRequestID. Returns it, or 0 when it cannot
    * be found.
    */
   public function get_us3_data()
   {
      $us3 = $this->us3_link();

      if ( ! $us3 )
         return 0;

      $table  = $this->us3_table( 'HPCAnalysisResult' );
      $result = $this->exec( $us3,
         "SELECT HPCAnalysisRequestID FROM $table"
         . " WHERE gfacID='{$this->gfacID}'" );

      if ( ! $result )
      {
         $this->mail_admin( "fail", "Query failed against $table for {$this->gfacID}" );
         return 0;
      }

      ## Duplicate gfacIDs happen; the most recent row is the live one.
      if ( $this->num_rows( $result ) > 1 )
         $result = $this->exec( $us3,
            "SELECT HPCAnalysisRequestID FROM $table"
            . " WHERE gfacID='{$this->gfacID}' ORDER BY HPCAnalysisResultID DESC LIMIT 1" );

      if ( ! $result )
         return 0;

      $row = $this->fetch_row( $result );

      if ( ! $row )
         return 0;

      ## Deliberately does NOT touch $GLOBALS['updateTime'].
      ##
      ## The old code assigned HPCAnalysisResult's UNIX_TIMESTAMP(updateTime)
      ## to that global, which both entry points' mail_to_admin() prints as
      ## "Update Time". Two things were wrong with it. The global otherwise
      ## holds gfac.analysis.time as a datetime string, so after this ran the
      ## admin mail printed a bare epoch instead. And it is a different column
      ## of a different table, so the mail silently changed which event it was
      ## reporting depending on whether this function had happened to run yet.
      list( $requestID ) = $row;

      return $requestID;
   }

   public function update_autoflow_status( $status, $message )
   {
      $this->logf( "update_autoflow_status() id {$this->autoflowID} status $status message $message" );

      ## Normalise at the boundary. cleanup_job.php and the other shared
      ## callers pass whatever word is to hand, including scheduler spellings
      ## like COMPLETED, and the maps below are defined over the JOB
      ## vocabulary only.
      $status = job_status_normalise( $status, $this->log );

      if ( $status === null )
         return;

      ## Independent of the autoflow linkage below. This is the only status
      ## update a non-autoflow (HPCAnalysisRequest-only, e.g. DMGA/GA)
      ## submission ever gets when a job fails before it can self-report via
      ## manage-us3-pipe.php's UDP listener. Without it, queueStatus stays
      ## 'queued' forever on failure for those submissions.
      $this->update_hpc_analysis_result_status( $status );

      if ( $this->autoflowID <= 0 )
      {
         $this->logf( "update_autoflow_status() ignored, no id" );
         return;
      }

      $us3 = $this->us3_link();

      if ( ! $us3 )
         return;

      ## The stage column is submitctl.php's, not ours. It gets the stage word
      ## for this job status, never the job word: submitctl lowercases what it
      ## finds and matches fixed lists, so a JOB value like SUBMIT_TIMEOUT
      ## matched nothing and left the request in "processing" forever.
      $stage = stage_status_from_job( $status, $this->log );

      if ( $stage === null )
         return;

      $this->exec( $us3,
         "UPDATE " . $this->us3_table( 'autoflowAnalysis' ) . " SET "
         . "status='" . $this->quote( $us3, $stage ) . "', "
         . "statusMsg='" . $this->quote( $us3, $message ) . "' "
         . "WHERE requestID = '{$this->autoflowID}' AND currentGfacID = '{$this->gfacID}'"
         . " AND NOT status RLIKE '^(failed|error|canceled)$'" );
   }

   /**
    * Record what the scientist sees. queue_status_from_job() owns the mapping;
    * a status with no user-facing equivalent leaves the column alone rather
    * than guessing.
    */
   public function update_hpc_analysis_result_status( $status )
   {
      $status = job_status_normalise( $status, $this->log );

      if ( $status === null )
         return;

      $queue_status = queue_status_from_job( $status, $this->log );

      if ( $queue_status === null )
         return;

      $us3 = $this->us3_link();

      if ( ! $us3 )
         return;

      $this->exec( $us3,
         "UPDATE " . $this->us3_table( 'HPCAnalysisResult' ) . " SET "
         . "queueStatus='$queue_status' WHERE gfacID = '{$this->gfacID}'" );
   }
}
