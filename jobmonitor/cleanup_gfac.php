<?php
/*
 * cleanup_gfac.php
 *
 * functions relating to copying results and cleaning up the gfac DB
 *
 */

$us3bin = exec( "ls -d ~us3/lims/bin" );
include_once "$us3bin/listen-config.php";
$me              = 'cleanup_gfac.php';
$email_address   = '';
$queuestatus     = '';
$jobtype         = '';
$db              = '';
$editXMLFilename = '';
$status          = '';

function gfac_cleanup( $us3_db, $reqID, $db_handle )
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
   $query  = "SELECT email, investigatorGUID, editXMLFilename FROM ${us3_db}.HPCAnalysisRequest " .
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

   $query  = "SELECT personID FROM ${us3_db}.people " .
             "WHERE personGUID='$investigatorGUID'";
   $result = mysqli_query( $db_handle, $query );

   list( $personID ) = mysqli_fetch_array( $result );

   /*
   $query  = "SELECT clusterName, submitTime, queueStatus, method "              .
             "FROM ${us3_db}.HPCAnalysisRequest h LEFT JOIN ${us3_db}.HPCAnalysisResult "            .
             "ON h.HPCAnalysisRequestID=HPCAnalysisResult.HPCAnalysisRequestID " .
             "WHERE h.HPCAnalysisRequestID=$requestID";
   */
   $query  = "SELECT clusterName, submitTime, queueStatus, analType "            .
             "FROM ${us3_db}.HPCAnalysisRequest h, ${us3_db}.HPCAnalysisResult r "                   .
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

   ## Get the GFAC ID
   $query = "SELECT HPCAnalysisResultID, gfacID, endTime FROM ${us3_db}.HPCAnalysisResult " .
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

   ########
   ## Get data from global GFAC DB and insert it into US3 DB
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
      write_logld( "$me: Cleanup analysis query found 0 entries for $gfacID" );
      update_autoflow_status( 'FAILED', "Cleanup analysis query found 0 entries for $gfacID" );
      return( 0 );
   }
##else
##{
##write_logld( "$me:    db=$db; num_rows=$num_rows; queuestatus=$queuestatus" );
##}

   list( $status, $cluster, $id ) = mysqli_fetch_array( $result );
##write_logld( "$me:     db=$db; requestID=$requestID; status=$status; cluster=$cluster" );

##   if ( $cluster == 'bcf-local'  ||  $cluster == 'alamo-local' )
   if ( preg_match( "/\-local/", $cluster )  ||
        preg_match( "/us3iab/",  $cluster ) )
   {
##      $clushost = $cluster;
##      $clushost = preg_replace( "/\-local/", "", $clushost );
      $parts    = explode( "-", $cluster );
      $clushost = $parts[ 0 ];
      get_local_files( $db_handle, $clushost, $requestID, $id, $gfacID );
write_logld( "$me:     clushost=$clushost  reqID=$requestID get_local_files() gfacID=$gfacID" );
   }
else
write_logld( "$me:     NO get_local_files()" );

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
      write_logld( "$me: Cleanup analysis query found 0 entries for $gfacID" );
      update_autoflow_status( 'FAILED', "Cleanup analysis query found 0 entries for $gfacID" );
      return( 0 );
   }

   list( $analysisID, $stderr, $stdout, $tarfile ) = mysqli_fetch_array( $result );

   if ( strlen( $tarfile ) > 0 )
   {  ## Log success at fetch attempt
      write_logld( "$me: Successful data fetch: $requestID $gfacID" );
   }
   else
   {  ## Log failure at fetch attempt
      update_autoflow_status( 'FAILED', "Failed data fetch" );
      write_logld( "$me: Failed data fetch: $requestID $gfacID" );
      if ( $analysisID == '' )
         $analysisID = '0';
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

   $need_finish = ( $status == 'COMPLETE' );

   if ( mysqli_num_rows( $result ) > 0 )
   {
      $time_msg = time();
      while ( list( $message, $time ) = mysqli_fetch_array( $result ) )
      {
##write_logld( "$me: message=$message" );
         $message_log .= "$time $message\n";
         if ( preg_match( "/^Finished/i", $message ) )
            $need_finish = false;
         $time_msg = strtotime( $time );
      }

      if ( $need_finish )
      {  ## No 'Finished' yet:  forget if too much time has passed
         $time_now = time();
         $tdelta   = $time_now - $time_msg;
write_logld( "$me: no-Finish time: tnow=$time_now, tmsg=$time_msg, tdelt=$tdelta" );
         if ( $tdelta > 600 )
            $need_finish = false;
      }
##write_logld( "$me: no-Finish time: tnow=$time_now, tmsg=$time_msg, tdelt=$tdelta" );
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

   update_autoflow_status( $status, $queue_msg );
   ## Delete data from GFAC DB
   $query = "DELETE from gfac.analysis WHERE gfacID='$gfacID'";

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      ## Just log it and continue
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
   }
write_logld( "$me: GFAC DB entry deleted" );

   ## Copy queue messages to LIMS submit directory (files there are deleted after 7 days)
   global $submit_dir;
   
   ## Get the request guid (LIMS submit dir name)
   $query  = "SELECT HPCAnalysisRequestGUID FROM ${us3_db}.HPCAnalysisRequest " .
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
   ##  GFAC job status was INSERTed, rather than UPDATEd
   if ( ! is_dir( $output_dir ) )
      mkdir( $output_dir, 0775, true );
   $message_filename = "$output_dir/$db-$requestID-messages.txt";
   file_put_contents( $message_filename, $message_log, FILE_APPEND );
  ## mysqli_close( $db_handle );
write_logld( "$me: *messages.txt written" );

   ########/
   ## Insert data into HPCAnalysis

   $query = "UPDATE ${us3_db}.HPCAnalysisResult SET "                              .
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

   if ( strlen( $tarfile ) == 0 )
   {
      write_logld( "$me: No tarfile" );
      update_autoflow_status( 'FAILED', "Empty results tarfile" );
      mail_to_user( "fail", "No results" );
      return( -1 );
   }

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

         $query = "UPDATE ${us3_db}.HPCAnalysisResult SET "   .
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

         $query = "INSERT INTO ${us3_db}.noise SET "  .
                  "noiseGUID='$noiseGUID'," .
                  "modelGUID='$modelGUID'," .
                  "editedDataID="                .
                  "(SELECT editedDataID FROM ${us3_db}.editedData WHERE editGUID='$editGUID')," .
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

         $query = "INSERT INTO ${us3_db}.pcsa_modelrecs SET "  .
                  "editedDataID="                .
                  "(SELECT editedDataID FROM ${us3_db}.editedData WHERE editGUID='$editGUID')," .
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

         update_autoflow_models( $id, $modelGUID, $editGUID );

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

         if ( $mc_iteration > 1 )
         {
##write_logld( "$me:   MODELUpd: mc_iteration=$mc_iteration" );
            $miter       = sprintf( "_mcN%03d", $mc_iteration );
##write_logld( "$me:   MODELUpd: miter=$miter" );
##write_logld( "$me:   MODELUpd: I:description=$description" );
            $description = preg_replace( "/_mc[0-9]+/", $miter, $description );
write_logld( "$me:   MODELUpd: O:description=$description" );
         }

         $query = "INSERT INTO ${us3_db}.model SET "       .
                  "modelGUID='$modelGUID',"      .
                  "editedDataID="                .
                  "(SELECT editedDataID FROM ${us3_db}.editedData WHERE editGUID='$editGUID')," .
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

         $query = "INSERT INTO ${us3_db}.modelPerson SET " .
                  "modelID=$modelID, personID=$personID";
         $result = mysqli_query( $db_handle, $query );
##write_logld( "$me:   model file inserted into DB : id=$id" );
      }

      $query = "INSERT INTO ${us3_db}.HPCAnalysisResultData SET "       .
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
      $query = "UPDATE ${us3_db}.noise SET "                                                 .
               "editedDataID="                                                     .
               "(SELECT editedDataID FROM ${us3_db}.model WHERE modelGUID='$modelGUID')," .
               "modelID="                                                          .
               "(SELECT modelID FROM ${us3_db}.model WHERE modelGUID='$modelGUID')"          .
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
      $query = "UPDATE ${us3_db}.pcsa_modelrecs SET "                                                 .
               "modelID="                                                          .
               "(SELECT modelID FROM ${us3_db}.model WHERE modelGUID='$modelGUID')"          .
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

  ## Get the request guid (LIMS submit dir name)
   $query  = "SELECT HPCAnalysisRequestGUID FROM ${us3_db}.HPCAnalysisRequest " .
             "WHERE HPCAnalysisRequestID = $requestID ";
   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
   }

   list( $requestGUID ) = mysqli_fetch_array( $result );

   chdir( "$submit_dir/$requestGUID" );
   $f = fopen( "analysis.tar", "w" );
   fwrite( $f, $tarfile );
   fclose( $f );

   ## Clean up
   chdir ( $work );
   ## exec( "rm -rf $gfacID" );

##   mysqli_close( $db_handle );

   ########/
   ## Send email 

   mail_to_user( "success", "" );
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

    $query = "SELECT statusJson,autoflowID from ${us3_db}.autoflowAnalysis where requestID=$autoflowAnalysisID";
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

    $query = "SELECT editedDataID FROM ${us3_db}.editedData WHERE editGUID='$editGUID'";

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

    $query = "SELECT modelsDesc from ${us3_db}.autoflowModelsLink where autoflowAnalysisID = $autoflowAnalysisID";
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
        $query = "UPDATE ${us3_db}.autoflowModelsLink set modelsDesc='$descenc' where autoflowAnalysisID = $autoflowAnalysisID";
    } else {
        ## insert
        $query = "INSERT INTO ${us3_db}.autoflowModelsLink"
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
