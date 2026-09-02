<?php
/*
 * cleanup_job.php
 *
 * Functions for copying results and cleaning up the job tracking DB
 * after a Slurm job completes.
 *
 */

$us3bin = exec( "ls -d ~us3/lims/bin" );
include_once "$us3bin/listen-config.php";

$me              = 'cleanup_job.php';
$email_address   = '';
$queuestatus     = '';
$jobtype         = '';
$db              = '';
$editXMLFilename = '';
$status          = '';

## Guarded fallback in case listen-config.php didn't already define this.
## Must run before job_cleanup() below makes its first call.
if ( ! function_exists( 'write_logld' ) ) {
   function write_logld( $msg, $this_level = 0 ) {
      global $logging_level;
      global $self;
      if ( ! isset( $logging_level ) || $logging_level >= $this_level ) {
         write_log( ( isset( $self ) ? "$self: " : '' ) . $msg );
      }
   }
}

function job_cleanup( $us3_db, $reqID, $db_handle )
{
   global $dbhost;
   global $user;
   global $passwd;
   global $db;
   global $guser;
   global $gpasswd;
   global $gDB;
   global $me;
   global $work;
   global $email_address;
   global $queuestatus;
   global $jobtype;
   global $editXMLFilename;
   global $submittime;
   global $endtime;
   global $status;
   global $stdout;
   global $requestID;

   $requestID = $reqID;
   $db = $us3_db;
   write_logld( "$me: debug db=$db; requestID=$requestID" );

   ## First get basic info for email messages
   $query  = "SELECT email, investigatorGUID, editXMLFilename FROM {$us3_db}.HPCAnalysisRequest " .
             "WHERE HPCAnalysisRequestID=$requestID";
   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      write_logld( "$me: Bad query: $query" );
      mail_to_user( "fail", "Internal Error $requestID\n$query\n" . mysqli_error( $db_handle ) );
      update_autoflow_status( 'FAILED', "Internal error - query failed: $query" . mysqli_error( $db_handle ) );
      return( -1 );
   }

   list( $email_address, $investigatorGUID, $editXMLFilename ) =  mysqli_fetch_array( $result );

   $query  = "SELECT personID FROM {$us3_db}.people " .
             "WHERE personGUID='$investigatorGUID'";
   $result = mysqli_query( $db_handle, $query );

   list( $personID ) = mysqli_fetch_array( $result );

   $query  = "SELECT clusterName, submitTime, queueStatus, analType "            .
             "FROM {$us3_db}.HPCAnalysisRequest h, {$us3_db}.HPCAnalysisResult r "                   .
             "WHERE h.HPCAnalysisRequestID=$requestID "                          .
             "AND h.HPCAnalysisRequestID=r.HPCAnalysisRequestID";

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
      update_autoflow_status( 'FAILED', "Internal error - query failed: $query" . mysqli_error( $db_handle ) );
      return( -1 );
   }

   if ( mysqli_num_rows( $result ) == 0 )
   {
      write_logld( "$me: US3 Table error - No records for requestID: $requestID" );
      update_autoflow_status( 'FAILED', "US3 Table error - No recoreds for requestID: $requestID" );
      return( -1 );
   }

   list( $cluster, $submittime, $queuestatus, $jobtype ) = mysqli_fetch_array( $result );

   ## Get the scheduler job ID recorded for this request.
   $query = "SELECT HPCAnalysisResultID, gfacID, endTime FROM {$us3_db}.HPCAnalysisResult " .
            "WHERE HPCAnalysisRequestID=$requestID";

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      write_logld( "$me: Bad query: $query" );
      mail_to_user( "fail", "Internal Error $requestID\n$query\n" . mysqli_error( $db_handle ) );
      update_autoflow_status( 'FAILED', "Internal error - query failed: $query" . mysqli_error( $db_handle ) );
      return( -1 );
   }

   list( $HPCAnalysisResultID, $gfacID, $endtime ) = mysqli_fetch_array( $result ); 

   ## Reconnect, this time to the central job-tracking database.
   $db_handle = mysqli_connect( $dbhost, $guser, $gpasswd, $gDB );

   if ( ! $db_handle )
   {
      write_logld( "$me: Could not connect to DB $dbhost : $gDB" );
      mail_to_user( "fail", "Internal Error $requestID\nCould not connect to DB $gDB" );
      update_autoflow_status( 'FAILED', "Internal error - Could not connect to DB $gDB" );
      return( -1 );
   }

   $query = "SELECT status, cluster, id FROM gfac.analysis " .
            "WHERE gfacID='$gfacID'";

   $result = mysqli_query( $db_handle, $query );
   if ( ! $result )
   {
      write_logld( "$me: Could not select GFAC status for $gfacID" );
      mail_to_user( "fail", "Could not select GFAC status for $gfacID" );
      update_autoflow_status( 'FAILED', "Could not select GFAC status for $gfacID" );
      return( -1 );
   }

   $num_rows = mysqli_num_rows( $result );
   if ( $num_rows == 0 )
   {
      ## Row vanished: another worker (the per-minute gridctl.php cron sweep
      ## and the per-job jobmonitor.php daemon both race for this job) already
      ## finished the cleanup and deleted it. Follow resolve_and_cleanup_job()'s
      ## contract (1 = finalized / nothing to do) rather than reporting FAILED.
      write_logld( "$me: analysis row for $gfacID already removed by a concurrent cleanup; nothing to do" );
      return( 1 );
   }
##else
##{
##write_logld( "$me:    db=$db; num_rows=$num_rows; queuestatus=$queuestatus" );
##}

   list( $status, $cluster, $id ) = mysqli_fetch_array( $result );

   ## Stage the job's stderr/stdout/results tar into its job-tracking row. This is
   ## the only writer of those columns, and the SELECT below fails the job
   ## outright ("Failed data fetch") if tarfile comes back empty, so this must
   ## run for every cluster, not just co-located ones.
   ## -1 means the cluster could not be reached, so we do not yet know whether
   ## the results exist. Return 0 ("not yet finalizable") so resolve_and_cleanup_job()
   ## hands control back to the jobmonitor poll loop and we ask again in 30s,
   ## rather than persisting empty result columns and failing the job. The
   ## existing $global_complete_max_seconds ceiling still force-finalizes a job
   ## that stays unfetchable, so this cannot hold a job open forever.
   if ( get_local_files( $db_handle, $cluster, $requestID, $id, $gfacID ) === -1 )
   {
      write_logld( "$me: results for $gfacID not retrievable right now (cluster unreachable); will retry" );
      return( 0 );
   }

   $query = "SELECT id, stderr, stdout, tarfile FROM gfac.analysis " .
            "WHERE gfacID='$gfacID'";

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
      mail_to_user( "fail", "Internal error " . mysqli_error( $db_handle ) );
      update_autoflow_status( 'FAILED', "Internal error - query failed: $query" . mysqli_error( $db_handle ) );
      return( -1 );
   }

   $num_rows = mysqli_num_rows( $result );
   if ( $num_rows == 0 )
   {
      ## Same concurrent-cleanup race as above -- the competing worker
      ## deleted the row between our two SELECTs. Not a failure.
      write_logld( "$me: analysis row for $gfacID removed by a concurrent cleanup mid-run; nothing to do" );
      return( 1 );
   }

   list( $analysisID, $stderr, $stdout, $tarfile ) = mysqli_fetch_array( $result );

   if ( strlen( $tarfile ) > 0 )
   {  ## Log success at fetch attempt
      write_logld( "$me: Successful data fetch: $requestID $gfacID" );
   }
   else
   {  ## The cluster was reachable and has no results tar for this job, so the
      ## job really did produce nothing. get_local_files() has already returned
      ## 0 above for the unreachable case, so reaching here means this is a
      ## genuine result, not an outage -- which is what makes it safe to tell
      ## the user their job produced no results.
      update_autoflow_status( 'FAILED', "Failed data fetch" );
      write_logld( "$me: Failed data fetch: $requestID $gfacID" );
      mail_to_user( "fail", "No results tarfile" );
      return( -1 );
   }

   ## Save queue messages for post-mortem analysis
   $query = "SELECT message, time FROM gfac.queue_messages " .
            "WHERE analysisID = $analysisID " .
            "ORDER BY time ";
   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      ## Just log it and continue
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
   }

   $now = date( 'Y-m-d H:i:s' );
   $message_log = "US3 DB: $db\n" .
                  "RequestID: $requestID\n" .
                  "GFAC ID: $gfacID\n" .
                  "Processed: $now\n\n" .
                  "Queue Messages\n\n" ;

   global $lock_dir;
   global $ll_base_dir;
   global $global_complete_grace_seconds;
   global $global_complete_max_seconds;

   $seen_dir  = ( isset( $lock_dir ) && $lock_dir != "" ) ? $lock_dir : "$ll_base_dir/$db/$gfacID";
   $seen_file = "$seen_dir/complete_seen";

   $need_finish = ( $status == 'COMPLETE' );

   ## us_mpi_analysis prints "Us_Mpi_Analysis has finished successfully" to
   ## stdout on completion (parallel_masters.cpp, pmasters_compjob.cpp,
   ## us_mpi_analysis.cpp) -- treat it as an immediate finish signal instead of
   ## always falling through to the queue_messages check and grace-period
   ## timeout below.
   if ( $need_finish && preg_match( "/^Us_Mpi_Analysis has finished successfully/m", $stdout ) )
      $need_finish = false;

   if ( mysqli_num_rows( $result ) > 0 )
   {
      while ( list( $message, $time ) = mysqli_fetch_array( $result ) )
      {
##write_logld( "$me: message=$message" );
         $message_log .= "$time $message\n";
         if ( preg_match( "/^Finished/i", $message ) )
            $need_finish = false;
      }

      if ( $need_finish )
      {  ## No 'Finished' message yet -- finalize anyway once enough time has
         ## passed since the job was first seen COMPLETE. Timing uses the
         ## jobmonitor's own clock (epoch seconds) to stay immune to
         ## timezone/clock skew between the LIMS host, cluster, and DB.
         $grace = isset( $global_complete_grace_seconds ) ? (int) $global_complete_grace_seconds : 600;
         $ceil  = isset( $global_complete_max_seconds )   ? (int) $global_complete_max_seconds   : 21600;
         $seen  = is_file( $seen_file ) ? (int) trim( @file_get_contents( $seen_file ) ) : 0;
         if ( $seen <= 0 )
         {
            $seen = time();
            @file_put_contents( $seen_file, $seen );
         }
         $elapsed = time() - $seen;
         write_logld( "$me: complete-since: seen=$seen now=" . time() . " elapsed=$elapsed grace=$grace ceil=$ceil" );
         if ( $elapsed > $grace )
         {
            $need_finish = false;
         }
         if ( $elapsed > $ceil )
         {
            write_logld( "$me: complete ceiling ($ceil s) exceeded for $gfacID - forcing finalize" );
            $need_finish = false;
         }
      }
   }
   else
   {
      write_logld( "$me: No messages for analysisID=$analysisID ." );
      $need_finish = false;
   }

   if ( $need_finish )
   {
      write_logld( "$me: Cleanup has not yet found 'Finished' for $gfacID" );
      return( 0 );
   }

   ## Finalizing this job: drop the complete-seen marker.
   if ( is_file( $seen_file ) )
   {
      @unlink( $seen_file );
   }

   $query = "DELETE FROM gfac.queue_messages " .
            "WHERE analysisID = $analysisID ";

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      ## Just log it and continue
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
   }

   ## Save stdout, stderr, etc. for message log
   $query  = "SELECT stdout, stderr, status, queue_msg, autoflowAnalysisID FROM gfac.analysis " .
             "WHERE gfacID='$gfacID' ";
   $result = mysqli_query( $db_handle, $query );
   try
   {
      ## What if this is too large?
      list( $stdout, $stderr, $status, $queue_msg, $autoflowAnalysisID ) = mysqli_fetch_array( $result );
   }
   catch ( Exception $e )
   {
      write_logld( "$me: stdout + stderr larger than 128M - $gfacID\n" . mysqli_error( $db_handle ) );
      ## Just go ahead and clean up
   }

   ## But let's allow for investigation of other large stdout and/or stderr
   if ( strlen( $stdout ) > 20480000 ||
        strlen( $stderr ) > 20480000 )
      write_logld( "$me: stdout + stderr larger than 20M - $gfacID\n" );

   $message_log .= "\n\n\nStdout Contents\n\n" .
                   $stdout .
                   "\n\n\nStderr Contents\n\n" .
                   $stderr .
                   "\n\n\nGFAC Status: $status\n" .
                   "GFAC message field: $queue_msg\n";

   ## only update status after all model records etc are written
   ## move to end (where we email the user)
   ## update_autoflow_status( $status, $queue_msg );

   ## Delete the completed central job-tracking records.
   $query = "DELETE from gfac.analysis WHERE gfacID='$gfacID'";

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      ## Just log it and continue
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
   }
write_logld( "$me: GFAC DB entry deleted" );

   ## Write the accumulated message log to the LIMS submit directory (files
   ## there are deleted after 7 days).
   global $submit_dir;
   
   ## Get the request guid (LIMS submit dir name)
   $query  = "SELECT HPCAnalysisRequestGUID FROM {$us3_db}.HPCAnalysisRequest " .
             "WHERE HPCAnalysisRequestID = $requestID ";
   $result = mysqli_query( $db_handle, $query );
   
   if ( ! $result )
   {
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
   }
   
   list( $requestGUID ) = mysqli_fetch_array( $result );
   $output_dir = "$submit_dir/$requestGUID";
write_logld( "$me: Output dir determined: $output_dir" );

   ## Try to create it if necessary, and write the file
   ## Let's use FILE_APPEND, in case this is the second time around and the 
   ##  job status was INSERTed, rather than UPDATEd
   if ( ! is_dir( $output_dir ) )
      mkdir( $output_dir, 0775, true );
   $message_filename = "$output_dir/$db-$requestID-messages.txt";
   file_put_contents( $message_filename, $message_log, FILE_APPEND );
  ## mysqli_close( $db_handle );
write_logld( "$me: *messages.txt written" );

   ## Update HPCAnalysisResult with the job's stdout/stderr.
   $query = "UPDATE {$us3_db}.HPCAnalysisResult SET "                              .
            "stderr='" . mysqli_real_escape_string( $db_handle, $stderr ) . "', " .
            "stdout='" . mysqli_real_escape_string( $db_handle, $stdout ) . "' "  .
            "WHERE HPCAnalysisResultID=$HPCAnalysisResultID";

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      update_autoflow_status( 'FAILED', "Could not insert data into HPCAnalysis" );
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
      mail_to_user( "fail", "Bad query:\n$query\n" . mysqli_error( $db_handle ) );
      return( -1 );
   }

   ## Save the tarfile and expand it

   ## Shouldn't happen
   if ( ! is_dir( "$work" ) )
   {
      update_autoflow_status( 'FAILED', "$work directory does not exist" );
      write_logld( "$me: $work directory does not exist" );
      mail_to_user( "fail", "$work directory does not exist" );
      return( -1 );
   }

   if ( ! is_dir( "$work/$gfacID" ) ) mkdir( "$work/$gfacID", 0770 );
   chdir( "$work/$gfacID" );

   $f = fopen( "analysis-results.tar", "w" );
   fwrite( $f, $tarfile );
   fclose( $f );
##write_logld( "$me: analysis-results.tar file written to work dir" );

   $tar_out = array();
   exec( "tar -xf analysis-results.tar 2>&1", $tar_out, $err );

   if ( $err != 0 )
   {
      chdir( $work );
      exec( "rm -r $gfacID" );
      $output = implode( "\n", $tar_out );

      update_autoflow_status( 'FAILED', "Bad output tarfile: $output" );
      write_logld( "$me: Bad output tarfile: $output" );
      mail_to_user( "fail", "Bad output file" );
      return( -1 );
   }
##write_logld( "$me: tar files extracted" );

   ## Insert the model files and noise files
   $files      = file( "analysis_files.txt", FILE_IGNORE_NEW_LINES );
   $noiseIDs   = array();
   $modelGUIDs = array();
   $mrecsIDs   = array();
   $rmodlGUIDs = array();

   foreach ( $files as $file )
   {
      $split = explode( ";", $file );

      if ( count( $split ) > 1 )
      {
         list( $fn, $meniscus, $mc_iteration, $variance ) = explode( ";", $file );
      
         list( $other, $mc_iteration ) = explode( "=", $mc_iteration );
         list( $other, $variance     ) = explode( "=", $variance );
         list( $other, $meniscus     ) = explode( "=", $meniscus );
      }
      else
         $fn = $file;

      if ( preg_match( "/mdl.tmp$/", $fn ) )
         continue;

      if ( filesize( $fn ) < 100 )
      {
         update_autoflow_status( 'FAILED', "Internal error - $fn is invalid" );
         write_logld( "$me:fn is invalid $fn" );
         mail_to_user( "fail", "Internal error\n$fn is invalid" );
         return( -1 );
      }
##write_logld( "$me:  handling file: $fn" );

      if ( preg_match( "/^job_statistics\.xml$/", $fn ) ) ## Job statistics file
      {
         $xml         = file_get_contents( $fn );
         $statistics  = parse_xml( $xml, 'statistics' );
         $otherdata   = parse_xml( $xml, 'id' );

         $query = "UPDATE {$us3_db}.HPCAnalysisResult SET "   .
                  "wallTime = {$statistics['walltime']}, " .
                  "CPUTime = {$statistics['cputime']}, " .
                  "CPUCount = {$statistics['cpucount']}, " .
                  "max_rss = {$statistics['maxmemory']}, " .
                  "startTime = '{$otherdata['starttime']}', " .
                  "endTime = '{$otherdata['endtime']}', " .
                  "mgroupcount = {$otherdata['groupcount']} " .
                  "WHERE HPCAnalysisResultID=$HPCAnalysisResultID";
         $result = mysqli_query( $db_handle, $query );

         if ( ! $result )
         {
            write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
         }

         file_put_contents( "$output_dir/$fn", $xml );    ## Copy to submit dir
         $file_type = "job_stats";
##write_logld( "$me:   job_statistics file updated in Result and written" );

      }

      else if ( preg_match( "/\.noise/", $fn ) > 0 ) ## It's a noise file
      {
         $xml        = file_get_contents( $fn );
         $noise_data = parse_xml( $xml, "noise" );
         $type       = ( $noise_data[ 'type' ] == "ri" ) ? "ri_noise" : "ti_noise";
         $desc       = $noise_data[ 'description' ];
         $modelGUID  = $noise_data[ 'modelGUID' ];
         $noiseGUID  = $noise_data[ 'noiseGUID' ];
         $editGUID   = '00000000-0000-0000-0000-000000000000';
         if ( isset( $model_data[ 'editGUID' ] ) )
            $editGUID   = $model_data[ 'editGUID' ];

         $query = "INSERT INTO {$us3_db}.noise SET "  .
                  "noiseGUID='$noiseGUID'," .
                  "modelGUID='$modelGUID'," .
                  "editedDataID="                .
                  "(SELECT editedDataID FROM {$us3_db}.editedData WHERE editGUID='$editGUID')," .
                  "modelID=1, "             .
                  "noiseType='$type',"      .
                  "description='$desc',"    .
                  "xml='" . mysqli_real_escape_string( $db_handle, $xml ) . "'";

         ## Add later after all files are processed: editDataID, modelID

         $result = mysqli_query( $db_handle, $query );

         if ( ! $result )
         {
            write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
            mail_to_user( "fail", "Internal error\n$query\n" . mysqli_error( $db_handle ) );
            update_autoflow_status( 'FAILED', "Internal error - bad query $query " . mysqli_error( $db_handle ) );
            return( -1 );
         }

         $id        = mysqli_insert_id( $db_handle );
         $file_type = "noise";
         $noiseIDs[] = $id;

         ## Keep track of modelGUIDs for later, when we replace them
         $modelGUIDs[ $id ] = $modelGUID;
##write_logld( "$me:   noise file inserted into DB : id=$id  modelGUID=$modelGUID" );
         
      }

      else if ( preg_match( "/\.mrecs/", $fn ) > 0 )  ## It's an mrecs file
      {
         $xml         = file_get_contents( $fn );
         $mrecs_data  = parse_xml( $xml, "modelrecords" );
         $desc        = $mrecs_data[ 'description' ];
         $editGUID    = $mrecs_data[ 'editGUID' ];
write_logld( "$me:   mrecs file editGUID=$editGUID" );
         if ( strlen( $editGUID ) < 36 )
            $editGUID    = "12345678-0123-5678-0123-567890123456";
         $mrecGUID    = $mrecs_data[ 'mrecGUID' ];
         $modelGUID   = $mrecs_data[ 'modelGUID' ];

         $query = "INSERT INTO {$us3_db}.pcsa_modelrecs SET "  .
                  "editedDataID="                .
                  "(SELECT editedDataID FROM {$us3_db}.editedData WHERE editGUID='$editGUID')," .
                  "modelID=0, "             .
                  "mrecsGUID='$mrecGUID'," .
                  "description='$desc',"    .
                  "xml='" . mysqli_real_escape_string( $db_handle, $xml ) . "'";

         ## Add later after all files are processed: editDataID, modelID

         $result = mysqli_query( $db_handle, $query );

         if ( ! $result )
         {
            write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
            mail_to_user( "fail", "Internal error\n$query\n" . mysqli_error( $db_handle ) );
            update_autoflow_status( 'FAILED', "Internal error - bad query $query " . mysqli_error( $db_handle ) );
            return( -1 );
         }

         $id         = mysqli_insert_id( $db_handle );
         $file_type  = "mrecs";
         $mrecsIDs[] = $id;

         ## update_autoflow_models( $id, $modelGUID, $editGUID );

         ## Keep track of modelGUIDs for later, when we replace them
         $rmodlGUIDs[ $id ] = $modelGUID;
##write_logld( "$me:   mrecs file inserted into DB : id=$id" );
      }

      else if ( preg_match( "/\.model/", $fn ) > 0 ) ## It's a model file
      {
         $xml         = file_get_contents( $fn );
         $model_data  = parse_xml( $xml, "model" );
         $description = $model_data[ 'description' ];
         $modelGUID   = $model_data[ 'modelGUID' ];
         $editGUID    = $model_data[ 'editGUID' ];

         ## A superglobal result describes the request as a whole, so
         ## us_mpi_analysis intentionally writes the nil editGUID.  The model
         ## table still requires one editedDataID for ownership/linkage.  Bind
         ## that request-level record to the request's primary edited file;
         ## ordinary per-dataset models must continue to resolve their exact
         ## editGUID.  This keeps the fallback request-scoped and avoids a
         ## corpus-specific ID or filename.
         if ( $editGUID == '00000000-0000-0000-0000-000000000000' )
         {
            $request_edit_filename = mysqli_real_escape_string( $db_handle, $editXMLFilename );
            $edited_data_selector  =
               "(SELECT editedDataID FROM {$us3_db}.editedData " .
               "WHERE filename='$request_edit_filename' ORDER BY editedDataID LIMIT 1)";
         }
         else
         {
            $escaped_edit_guid    = mysqli_real_escape_string( $db_handle, $editGUID );
            $edited_data_selector =
               "(SELECT editedDataID FROM {$us3_db}.editedData " .
               "WHERE editGUID='$escaped_edit_guid')";
         }

         if ( $mc_iteration > 1 )
         {
##write_logld( "$me:   MODELUpd: mc_iteration=$mc_iteration" );
            $miter       = sprintf( "_mcN%03d", $mc_iteration );
##write_logld( "$me:   MODELUpd: miter=$miter" );
##write_logld( "$me:   MODELUpd: I:description=$description" );
            $description = preg_replace( "/_mc[0-9]+/", $miter, $description );
write_logld( "$me:   MODELUpd: O:description=$description" );
         }

         $query = "INSERT INTO {$us3_db}.model SET "       .
                  "modelGUID='$modelGUID',"      .
                  "editedDataID=$edited_data_selector," .
                  "description='$description',"  .
                  "MCIteration='$mc_iteration'," .
                  "meniscus='$meniscus'," .
                  "variance='$variance'," .
                  "xml='" . mysqli_real_escape_string( $db_handle, $xml ) . "'";

         $result = mysqli_query( $db_handle, $query );

         if ( ! $result )
         {
            write_logld( "$me: Bad query:\n$query " . mysqli_error( $db_handle ) );
            mail_to_user( "fail", "Internal error\n$query\n" . mysqli_error( $db_handle ) );
            update_autoflow_status( 'FAILED', "Internal error - bad query $query " . mysqli_error( $db_handle ) );
            return( -1 );
         }

         $modelID   = mysqli_insert_id( $db_handle );
         $id        = $modelID;
         $file_type = "model";

         update_autoflow_models( $modelID, $modelGUID, $editGUID );

         $query = "INSERT INTO {$us3_db}.modelPerson SET " .
                  "modelID=$modelID, personID=$personID";
         $result = mysqli_query( $db_handle, $query );
##write_logld( "$me:   model file inserted into DB : id=$id" );
      }

      $query = "INSERT INTO {$us3_db}.HPCAnalysisResultData SET "       .
               "HPCAnalysisResultID='$HPCAnalysisResultID', " .
               "HPCAnalysisResultType='$file_type', "         .
               "resultID=$id";

      $result = mysqli_query( $db_handle, $query );

      if ( ! $result )
      {
         write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
         mail_to_user( "fail", "Internal error\n$query\n" . mysqli_error( $db_handle ) );
         update_autoflow_status( 'FAILED', "Internal error - bad query $query " . mysqli_error( $db_handle ) );
         return( -1 );
      }
##write_logld( "$me:    ResultData updated : file_type=$file_type" );
   }

   ## Now fix up noise entries
   ## For noise files, there is, at most two: ti_noise and ri_noise
   ## In this case there will only be one modelID

   foreach ( $noiseIDs as $noiseID )
   {
      $modelGUID = $modelGUIDs[ $noiseID ];
      $query = "UPDATE {$us3_db}.noise SET "                                                 .
               "editedDataID="                                                     .
               "(SELECT editedDataID FROM {$us3_db}.model WHERE modelGUID='$modelGUID')," .
               "modelID="                                                          .
               "(SELECT modelID FROM {$us3_db}.model WHERE modelGUID='$modelGUID')"          .
               "WHERE noiseID=$noiseID";

      $result = mysqli_query( $db_handle, $query );

      if ( ! $result )
      {
         write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
         mail_to_user( "fail", "Bad query\n$query\n" . mysqli_error( $db_handle ) );
         update_autoflow_status( 'FAILED', "Internal error - bad query $query " . mysqli_error( $db_handle ) );
         return( -1 );
      }
##write_logld( "$me:     noise entry updated : noiseID=$noiseID" );
   }
##write_logld( "$me:     noise entries updated" );

   ## Now possibly fix up mrecs entries

   foreach ( $mrecsIDs as $mrecsID )
   {
      $modelGUID = $rmodlGUIDs[ $mrecsID ];
      $query = "UPDATE {$us3_db}.pcsa_modelrecs SET "                                                 .
               "modelID="                                                          .
               "(SELECT modelID FROM {$us3_db}.model WHERE modelGUID='$modelGUID')"          .
               "WHERE mrecsID=$mrecsID";

      $result = mysqli_query( $db_handle, $query );

      if ( ! $result )
      {
         write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
         mail_to_user( "fail", "Bad query\n$query\n" . mysqli_error( $db_handle ) );
         update_autoflow_status( 'FAILED', "Internal error - bad query $query " . mysqli_error( $db_handle ) );
         return( -1 );
      }
##write_logld( "$me:     mrecs entry updated : mrecsID=$mrecsID" );
   }
##write_logld( "$me:     mrecs entries updated" );

   ## Copy results to LIMS submit directory (files there are deleted after 7 days)
   global $submit_dir; ## LIMS submit files dir

   ## $requestGUID was already fetched above; reuse it here.
   chdir( "$submit_dir/$requestGUID" );
   $f = fopen( "analysis.tar", "w" );
   fwrite( $f, $tarfile );
   fclose( $f );

   ## Clean up
   chdir ( $work );
   ## exec( "rm -rf $gfacID" );

##   mysqli_close( $db_handle );

   ## Set the final status now that all model records are written, then notify the user.
   update_autoflow_status( $status, $queue_msg );
   mail_to_user( "success", "" );

   return 1;
}

function get_autoflow_type_id() {
    global $db_handle;
    global $gfacID;
    global $autoflowAnalysisID;
    global $us3_db;
    global $self;

    write_logld( "get_autoflow_type() id $autoflowAnalysisID" );
        
    if ( $autoflowAnalysisID <= 0 ) {
        write_logld( "update_autoflow_links() ignored, no id" );
        return;
    }

    ## get autoflow running submission type

    $query = "SELECT statusJson,autoflowID from {$us3_db}.autoflowAnalysis where requestID=$autoflowAnalysisID";
    echo "query : $query\n";

    $result = mysqli_query( $db_handle, $query );

    if ( ! $result ) {
        ## Just log it and continue
        write_logld( "Bad query:\n$query\n" . mysqli_error( $db_handle ) );
        return;
    }

    $obj = mysqli_fetch_object( $result );
    $statusJson = json_decode( $obj->statusJson );
    debug_json( "statusJson", $statusJson );
    $tag = $statusJson->submitted;
    debug_json( "tag", $tag );
    return
        (object) [
         "type" => $tag
         ,"autoflowID" => $obj->autoflowID
        ];
}

function update_autoflow_models( $modelID, $modelGUID, $editGUID ) {
    global $db_handle;
    global $gfacID;
    global $autoflowAnalysisID;
    global $us3_db;
    global $self;
    global $autoflowType;
    global $autoflowID;

    write_logld( "update_autoflow_models() id $autoflowAnalysisID model $modelID modelGUID $modelGUID editGUID $editGUID" );
        
    if ( $autoflowAnalysisID <= 0 ) {
        write_logld( "update_autoflow_models() ignored, no id" );
        return;
    }

    ## get editeddataID for editGUID

    $query = "SELECT editedDataID FROM {$us3_db}.editedData WHERE editGUID='$editGUID'";

    $result = mysqli_query( $db_handle, $query );

    if ( ! $result ) {
        write_logld( "Bad query:\n$query\n" . mysqli_error( $db_handle ) );
        return;
    }

    if ( $result->num_rows != 1 ) {
        write_logld( "unexpected $result->num_rows results returned for $query\n" );
        return;
    }
        
    $obj = mysqli_fetch_object( $result );
    debug_json( "edited data", $obj );
    $editedDataID = $obj->editedDataID;

    ## get current autoflowModelsLink

    $query = "SELECT modelsDesc from {$us3_db}.autoflowModelsLink where autoflowAnalysisID = $autoflowAnalysisID";
    echo "query : $query\n";

    $result = mysqli_query( $db_handle, $query );

    if ( ! $result ) {
        write_logld( "Bad query:\n$query\n" . mysqli_error( $db_handle ) );
        return;
    }

    # debug_json( "result", $result );

    $descJson = (object)[];

    if ( $result->num_rows ) {
        $obj = mysqli_fetch_object( $result );
        $descJson = json_decode( $obj->modelsDesc );
    }

    debug_json( "starting descJson", $descJson );
    if ( !isset( $descJson->{$autoflowType} ) ) {
        $descJson->{$autoflowType} = [];
    }
    $descJson->{$autoflowType}[] =
        [
         "modelID"       => "$modelID"
         ,"modelGUID"    => "$modelGUID"
         ,"editeddataID" => "$editedDataID"
        ];
         
    debug_json( "ending descJson", $descJson );
    $descenc = json_encode( $descJson );

    if ( $result->num_rows ) {
        ## update
        $query = "UPDATE {$us3_db}.autoflowModelsLink set modelsDesc='$descenc' where autoflowAnalysisID = $autoflowAnalysisID";
    } else {
        ## insert
        $query = "INSERT INTO {$us3_db}.autoflowModelsLink"
            . " set autoflowAnalysisID=$autoflowAnalysisID"
            . " ,modelsDesc='$descenc'"
            . " ,autoflowID=$autoflowID"
            ;
    }

    $result = mysqli_query( $db_handle, $query );

    if ( ! $result ) {
        write_logld( "Bad query:\n$query\n" . mysqli_error( $db_handle ) );
        return;
    }

}
