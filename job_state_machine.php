<?php
/*
 * job_state_machine.php
 *
 * Job-state policy shared by gridctl.php (the per-minute cron sweep) and
 * jobmonitor/gridctl.php (the per-job daemon). Both act on the same rows, so
 * the policy lives here; connections, logger and mailer are constructor
 * arguments. Each entry file exposes the methods as global one-line shims for
 * the shared cleanup code.
 */

## Guarded so a test process that already loaded cluster_probe.php does not reload it.
if ( ! function_exists( 'cluster_probe_job_status' ) )
{
   require_once __DIR__ . '/cluster_probe.php';
}

require_once __DIR__ . '/job_status.php';

class job_state_machine
{
   ## Probe answers that carry no news. UNKNOWN: the cluster has no record.
   ## GRIDCTL_UNREACHABLE: we could not ask.
   const QUEUED_STATES  = array( 'SUBMITTED', 'INITIALIZED', 'PENDING', 'UNKNOWN', GRIDCTL_UNREACHABLE );
   const RUNNING_STATES = array( 'ACTIVE', 'RUNNING', 'STARTED', 'UNKNOWN', GRIDCTL_UNREACHABLE );

   ## A job touched within this window is never judged stalled.
   const SETTLE_SECONDS = 600;

   private $gfac;         ## mysqli reaching the gfac tables
   private $us3;          ## mysqli or callable returning one, for the us3 tables
   private $gfac_prefix;  ## '' when $gfac is connected to the gfac db, else 'gfac.'
   private $log;          ## callable( string )
   private $mailer;       ## callable( $type, $msg ); the entry point handles dedup

   ## Per job, set by for_job().
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

   ## Hours a job may sit in one state before the stall clock fires.
   ## Zero or negative disables the timeout.
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

   ## In the sweep the us3 schemas need their own connection (the gfac user has
   ## no rights on them). Opened lazily, on first use.
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

   ## Best-effort: log a failed statement rather than abort the sweep or daemon.
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

   ## All database access goes through exec(), quote(), fetch_row() and
   ## num_rows(), so a test double can replace the database.
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

   ## May return GRIDCTL_UNREACHABLE, which is not a job state: leave the job alone.
   public function get_local_status()
   {
      $log    = $this->log;
      $status = cluster_probe_job_status( $this->cluster, $this->gfacID, $log );

      $this->logf( "get_local_status( {$this->gfacID} ) on {$this->cluster} = $status" );

      return $status;
   }

   ## Is the cluster answering at all? Overridable for tests.
   protected function reachable()
   {
      return cluster_probe_reachable( $this->cluster, $this->log );
   }

   ## True only when scancel was actually delivered.
   public function cancel_local_job()
   {
      return cluster_probe_cancel_job( $this->cluster, $this->gfacID, $this->log );
   }

   ## May a stall clock fire? 'proceed', 'defer' or 'abandon'; see
   ## cluster_probe_outage_verdict().
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

   ## Close out a job whose cluster has been unreachable past the ceiling.
   ## $enum_status is SUBMIT_TIMEOUT or RUN_TIMEOUT (the ENUM has no
   ## UNREACHABLE); the message says what really happened. No cancel: there is
   ## no cluster to send it to.
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

   ## Shared by the four stall paths: close the job out once its window has
   ## passed, unless the window is disabled or the outage verdict defers.
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

      ## 'proceed' means the cluster just answered, so the cancel can land.
      $this->cancel_local_job();
   }

   ## Record the cluster's answer unless it is one of $live_states ("no news").
   ## Returns true when the job has moved on and the caller should stop.
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

      ## Inside the window: just check whether the job has moved on.
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

      ## Moved on: the first timeout was premature.
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

   ## Record a status the cluster reported, in gfac.analysis and on the LIMS
   ## side. Each column gets its own vocabulary via job_status.php.
   public function update_job_status( $job_status )
   {
      $this->logf( "update_job_status( '$job_status', '{$this->gfacID}' )" );

      $log    = $this->log;
      ## An unparseable status is recorded as ERROR rather than ignored.
      $status = job_status_normalise( $job_status, $log, 'ERROR' );

      if ( $status === null )
      {
         ## Nothing learned (e.g. cluster unreachable): keep the previous status.
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

   ## Record a status in gfac.analysis without touching the stage or queue
   ## status, which submitctl.php acts on. Used by complete(): cleanup sets
   ## those after importing the results. Messages are written only on a change,
   ## since complete() repeats every poll while cleanup waits.
   public function record_job_status( $job_status )
   {
      $this->logf( "record_job_status( '$job_status', '{$this->gfacID}' )" );

      $status = job_status_normalise( $job_status, $this->log, 'ERROR' );

      if ( $status === null )
         return null;

      $analysis = $this->gfac_table( 'analysis' );
      $result   = $this->exec( $this->gfac,
         "SELECT status FROM $analysis WHERE gfacID='{$this->gfacID}'" );
      $row      = $result ? $this->fetch_row( $result ) : null;

      if ( $row && $row[ 0 ] === $status )
         return $status;

      $this->exec( $this->gfac,
         "UPDATE $analysis SET status='$status' WHERE gfacID='{$this->gfacID}'" );

      $message = $this->status_message( $status );

      if ( $message !== null )
      {
         $this->update_queue_messages( $message );
         $this->update_db( $message );
      }

      return $status;
   }

   ## The sentence a user sees for a status, or null for bookkeeping ones.
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

   ## This job's HPCAnalysisRequestID, or 0 when it cannot be found.
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

      list( $requestID ) = $row;

      return $requestID;
   }

   public function update_autoflow_status( $status, $message )
   {
      $this->logf( "update_autoflow_status() id {$this->autoflowID} status $status message $message" );

      ## Callers pass scheduler spellings too (COMPLETED); the maps want job words.
      $status = job_status_normalise( $status, $this->log );

      if ( $status === null )
         return;

      ## Non-autoflow submissions (DMGA/GA) get their status only through this.
      $this->update_hpc_analysis_result_status( $status );

      if ( $this->autoflowID <= 0 )
      {
         $this->logf( "update_autoflow_status() ignored, no id" );
         return;
      }

      $us3 = $this->us3_link();

      if ( ! $us3 )
         return;

      ## submitctl.php matches this column against fixed stage words.
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

   ## The status the scientist sees; left alone when there is no mapping.
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
