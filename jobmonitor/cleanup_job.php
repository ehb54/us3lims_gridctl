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

## Fallback for when listen-config.php did not define it.
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

   ## LIMS tables over their own connection as $user: the cron sweep passes a
   ## gfac-user handle, which has no rights on them. $db_handle is for gfac only.
   $us3_link = mysqli_connect( $dbhost, $user, $passwd, $us3_db );

   if ( ! $us3_link )
   {
      write_logld( "$me: Could not connect to DB $dbhost : $us3_db" );
      update_autoflow_status( 'FAILED', "Internal error - cleanup could not connect to DB $us3_db" );
      return( -1 );
   }

   ## First get basic info for email messages
   $query  = "SELECT email, investigatorGUID, editXMLFilename FROM {$us3_db}.HPCAnalysisRequest " .
             "WHERE HPCAnalysisRequestID=$requestID";
   $result = mysqli_query( $us3_link, $query );

   if ( ! $result )
   {
      write_logld( "$me: Bad query: $query" );
      mail_to_user( "fail", "Internal Error $requestID\n$query\n" . mysqli_error( $us3_link ) );
      update_autoflow_status( 'FAILED', "Internal error - query failed: $query" . mysqli_error( $us3_link ) );
      return( -1 );
   }

   list( $email_address, $investigatorGUID, $editXMLFilename ) =  mysqli_fetch_array( $result );

   $query  = "SELECT personID FROM {$us3_db}.people " .
             "WHERE personGUID='$investigatorGUID'";
   $result = mysqli_query( $us3_link, $query );

   list( $personID ) = mysqli_fetch_array( $result );

   $query  = "SELECT clusterName, submitTime, queueStatus, analType "            .
             "FROM {$us3_db}.HPCAnalysisRequest h, {$us3_db}.HPCAnalysisResult r "                   .
             "WHERE h.HPCAnalysisRequestID=$requestID "                          .
             "AND h.HPCAnalysisRequestID=r.HPCAnalysisRequestID";

   $result = mysqli_query( $us3_link, $query );

   if ( ! $result )
   {
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $us3_link ) );
      update_autoflow_status( 'FAILED', "Internal error - query failed: $query" . mysqli_error( $us3_link ) );
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

   $result = mysqli_query( $us3_link, $query );

   if ( ! $result )
   {
      write_logld( "$me: Bad query: $query" );
      mail_to_user( "fail", "Internal Error $requestID\n$query\n" . mysqli_error( $us3_link ) );
      update_autoflow_status( 'FAILED', "Internal error - query failed: $query" . mysqli_error( $us3_link ) );
      return( -1 );
   }

   list( $HPCAnalysisResultID, $gfacID, $endtime ) = mysqli_fetch_array( $result ); 

   ## The caller's connection already reaches the central job-tracking database.
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
      ## Another worker already finished the cleanup and deleted the row.
      write_logld( "$me: analysis row for $gfacID already removed by a concurrent cleanup; nothing to do" );
      return( 1 );
   }
##else
##{
##write_logld( "$me:    db=$db; num_rows=$num_rows; queuestatus=$queuestatus" );
##}

   list( $status, $cluster, $id ) = mysqli_fetch_array( $result );

   ## Stage the results into the job-tracking row. An unreachable cluster (-1)
   ## is retried until the ceiling, then the job is failed; -2 fails it now.
   $seen_file     = cleanup_job_dir( $db, $gfacID ) . "/complete_seen";
   $fetch_failure = '';

   $fetched = get_local_files( $db_handle, $cluster, $requestID, $id, $gfacID );

   if ( $fetched === -1 )
   {
      if ( cleanup_retry_unreachable( $seen_file ) )
      {
         write_logld( "$me: results for $gfacID not retrievable right now (cluster unreachable); will retry" );
         return( 0 );
      }

      $ceil          = cleanup_complete_ceiling();
      $fetch_failure = "Results could not be retrieved from $cluster";
      write_logld( "$me: results for $gfacID still not retrievable after $ceil s; failing the job" );
   }
   else if ( $fetched === -2 )
   {
      $fetch_failure = "Results could not be staged from $cluster";
      write_logld( "$me: results for $gfacID cannot be staged; failing the job" );
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
      ## Another worker deleted the row between our two SELECTs.
      write_logld( "$me: analysis row for $gfacID removed by a concurrent cleanup mid-run; nothing to do" );
      return( 1 );
   }

   list( $analysisID, $stderr, $stdout, $tarfile ) = mysqli_fetch_array( $result );

   ## A job with no results is still finalized (stdout/stderr saved, tracking
   ## row deleted) and then failed at the tar step, so no worker retries it.
   $has_results = ( $fetch_failure == '' && strlen( $tarfile ) > 0 );

   if ( $has_results )
   {  ## Log success at fetch attempt
      write_logld( "$me: Successful data fetch: $requestID $gfacID" );
   }
   else
   {  ## The fetch failed for good, or the cluster has no results tar.
      if ( $fetch_failure == '' )
         $fetch_failure = "No results tarfile";

      update_autoflow_status( 'FAILED', "Failed data fetch" );
      write_logld( "$me: Failed data fetch: $requestID $gfacID" );
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

   global $global_complete_grace_seconds;

   ## No results means no 'Finished' message worth waiting for.
   $need_finish = ( $status == 'COMPLETE' && $has_results );

   ## us_mpi_analysis's own success line counts as the finish signal.
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
         ## passed since the job was first seen COMPLETE (local clock, so no
         ## skew between hosts).
         $grace   = isset( $global_complete_grace_seconds ) ? (int) $global_complete_grace_seconds : 600;
         $ceil    = cleanup_complete_ceiling();
         $elapsed = cleanup_pending_seconds( $seen_file );
         write_logld( "$me: complete-since: elapsed=$elapsed grace=$grace ceil=$ceil" );
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
   $autoflowAnalysisID = 0;
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
   $result = mysqli_query( $us3_link, $query );
   
   if ( ! $result )
   {
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $us3_link ) );
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
            "stderr='" . mysqli_real_escape_string( $us3_link, $stderr ) . "', " .
            "stdout='" . mysqli_real_escape_string( $us3_link, $stdout ) . "' "  .
            "WHERE HPCAnalysisResultID=$HPCAnalysisResultID";

   $result = mysqli_query( $us3_link, $query );

   if ( ! $result )
   {
      update_autoflow_status( 'FAILED', "Could not insert data into HPCAnalysis" );
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $us3_link ) );
      mail_to_user( "fail", "Bad query:\n$query\n" . mysqli_error( $us3_link ) );
      return( -1 );
   }

   if ( ! $has_results )
   {
      mail_to_user( "fail", $fetch_failure );
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

   ## Explicit rather than jobmonitor.php's globals, which the cron sweep lacks.
   $autoflow = get_autoflow_type_id( $us3_link, $us3_db, $autoflowAnalysisID );

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
         $result = mysqli_query( $us3_link, $query );

         if ( ! $result )
         {
            write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $us3_link ) );
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
                  "xml='" . mysqli_real_escape_string( $us3_link, $xml ) . "'";

         ## Add later after all files are processed: editDataID, modelID

         $result = mysqli_query( $us3_link, $query );

         if ( ! $result )
         {
            write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $us3_link ) );
            mail_to_user( "fail", "Internal error\n$query\n" . mysqli_error( $us3_link ) );
            update_autoflow_status( 'FAILED', "Internal error - bad query $query " . mysqli_error( $us3_link ) );
            return( -1 );
         }

         $id        = mysqli_insert_id( $us3_link );
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
                  "xml='" . mysqli_real_escape_string( $us3_link, $xml ) . "'";

         ## Add later after all files are processed: editDataID, modelID

         $result = mysqli_query( $us3_link, $query );

         if ( ! $result )
         {
            write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $us3_link ) );
            mail_to_user( "fail", "Internal error\n$query\n" . mysqli_error( $us3_link ) );
            update_autoflow_status( 'FAILED', "Internal error - bad query $query " . mysqli_error( $us3_link ) );
            return( -1 );
         }

         $id         = mysqli_insert_id( $us3_link );
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

         ## A superglobal model has the nil editGUID; link it to the request's
         ## primary edited file, since model needs an editedDataID.
         if ( $editGUID == '00000000-0000-0000-0000-000000000000' )
         {
            $request_edit_filename = mysqli_real_escape_string( $us3_link, $editXMLFilename );
            $edited_data_selector  =
               "(SELECT editedDataID FROM {$us3_db}.editedData " .
               "WHERE filename='$request_edit_filename' ORDER BY editedDataID LIMIT 1)";
         }
         else
         {
            $escaped_edit_guid    = mysqli_real_escape_string( $us3_link, $editGUID );
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
                  "xml='" . mysqli_real_escape_string( $us3_link, $xml ) . "'";

         $result = mysqli_query( $us3_link, $query );

         if ( ! $result )
         {
            write_logld( "$me: Bad query:\n$query " . mysqli_error( $us3_link ) );
            mail_to_user( "fail", "Internal error\n$query\n" . mysqli_error( $us3_link ) );
            update_autoflow_status( 'FAILED', "Internal error - bad query $query " . mysqli_error( $us3_link ) );
            return( -1 );
         }

         $modelID   = mysqli_insert_id( $us3_link );
         $id        = $modelID;
         $file_type = "model";

         update_autoflow_models( $us3_link, $us3_db, $autoflowAnalysisID, $autoflow, $modelID, $modelGUID, $editGUID );

         $query = "INSERT INTO {$us3_db}.modelPerson SET " .
                  "modelID=$modelID, personID=$personID";
         $result = mysqli_query( $us3_link, $query );
##write_logld( "$me:   model file inserted into DB : id=$id" );
      }

      $query = "INSERT INTO {$us3_db}.HPCAnalysisResultData SET "       .
               "HPCAnalysisResultID='$HPCAnalysisResultID', " .
               "HPCAnalysisResultType='$file_type', "         .
               "resultID=$id";

      $result = mysqli_query( $us3_link, $query );

      if ( ! $result )
      {
         write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $us3_link ) );
         mail_to_user( "fail", "Internal error\n$query\n" . mysqli_error( $us3_link ) );
         update_autoflow_status( 'FAILED', "Internal error - bad query $query " . mysqli_error( $us3_link ) );
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

      $result = mysqli_query( $us3_link, $query );

      if ( ! $result )
      {
         write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $us3_link ) );
         mail_to_user( "fail", "Bad query\n$query\n" . mysqli_error( $us3_link ) );
         update_autoflow_status( 'FAILED', "Internal error - bad query $query " . mysqli_error( $us3_link ) );
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

      $result = mysqli_query( $us3_link, $query );

      if ( ! $result )
      {
         write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $us3_link ) );
         mail_to_user( "fail", "Bad query\n$query\n" . mysqli_error( $us3_link ) );
         update_autoflow_status( 'FAILED', "Internal error - bad query $query " . mysqli_error( $us3_link ) );
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

## The autoflow stage type and autoflowID for an autoflowAnalysis request, or
## null. With no arguments it uses jobmonitor.php's globals.
function get_autoflow_type_id( $link = null, $us3_db = null, $autoflowAnalysisID = null ) {
    if ( $link === null ) {
        $link               = $GLOBALS[ 'db_handle' ];
        $us3_db             = $GLOBALS[ 'us3_db' ];
        $autoflowAnalysisID = $GLOBALS[ 'autoflowAnalysisID' ];
    }

    write_logld( "get_autoflow_type() id $autoflowAnalysisID" );

    if ( $autoflowAnalysisID <= 0 ) {
        write_logld( "get_autoflow_type() ignored, no id" );
        return null;
    }

    $query = "SELECT statusJson,autoflowID from {$us3_db}.autoflowAnalysis where requestID=$autoflowAnalysisID";

    $result = mysqli_query( $link, $query );

    if ( ! $result ) {
        write_logld( "Bad query:\n$query\n" . mysqli_error( $link ) );
        return null;
    }

    $obj = mysqli_fetch_object( $result );

    if ( ! $obj ) {
        write_logld( "get_autoflow_type() no autoflowAnalysis row $autoflowAnalysisID" );
        return null;
    }

    $statusJson = json_decode( $obj->statusJson );
    debug_json( "statusJson", $statusJson );
    $tag = is_object( $statusJson ) && isset( $statusJson->submitted ) ? $statusJson->submitted : "unknown";
    debug_json( "tag", $tag );
    return
        (object) [
         "type" => $tag
         ,"autoflowID" => $obj->autoflowID
        ];
}

## Record a stage's model in autoflowModelsLink. $autoflow is what
## get_autoflow_type_id() returned for $autoflowAnalysisID.
function update_autoflow_models( $link, $us3_db, $autoflowAnalysisID, $autoflow, $modelID, $modelGUID, $editGUID ) {
    write_logld( "update_autoflow_models() id $autoflowAnalysisID model $modelID modelGUID $modelGUID editGUID $editGUID" );

    if ( $autoflowAnalysisID <= 0 || ! $autoflow ) {
        write_logld( "update_autoflow_models() ignored, no autoflow request" );
        return;
    }

    $autoflowType = $autoflow->type;
    $autoflowID   = $autoflow->autoflowID;

    ## get editeddataID for editGUID

    $query = "SELECT editedDataID FROM {$us3_db}.editedData WHERE editGUID='$editGUID'";

    $result = mysqli_query( $link, $query );

    if ( ! $result ) {
        write_logld( "Bad query:\n$query\n" . mysqli_error( $link ) );
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

    $result = mysqli_query( $link, $query );

    if ( ! $result ) {
        write_logld( "Bad query:\n$query\n" . mysqli_error( $link ) );
        return;
    }

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
    $descenc = mysqli_real_escape_string( $link, json_encode( $descJson ) );

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

    $result = mysqli_query( $link, $query );

    if ( ! $result ) {
        write_logld( "Bad query:\n$query\n" . mysqli_error( $link ) );
        return;
    }

}
