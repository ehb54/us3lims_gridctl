<?php
/*
 * cleanup.php
 *
 * functions relating to copying results and cleaning up the gfac DB
 *  where the job used an Airavata interface.
 *
 */

$email_address   = '';
$queuestatus     = '';
$jobtype         = '';
$db              = '';
$editXMLFilename = '';
$status          = '';

function aira_cleanup( $us3_db, $reqID, $gfac_link )
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
   global $status;
   global $stderr;
   global $stdout;
   global $tarfile;
   global $requestID;
   global $submit_dir;
   $me        = 'cleanup_aira.php';

   $requestID = $reqID;
   $db = $us3_db;
   write_log( "$me: debug db=$db; requestID=$requestID" );

   $us3_link = mysql_connect( $dbhost, $user, $passwd );

   if ( ! $us3_link )
   {
      write_log( "$me: could not connect: $dbhost, $user, $passwd" );
      mail_to_user( "fail", "Internal Error $requestID\nCould not connect to DB" );
      return( -1 );
   }

   $result = mysql_select_db( $db, $us3_link );

   if ( ! $result )
   {
      write_log( "$me: could not select DB $db" );
      mail_to_user( "fail", "Internal Error $requestID\n$could not select DB $db" );
      return( -1 );
   }

   // First get basic info for email messages
   $query  = "SELECT email, investigatorGUID, editXMLFilename FROM HPCAnalysisRequest " .
             "WHERE HPCAnalysisRequestID=$requestID";
   $result = mysql_query( $query, $us3_link );

   if ( ! $result )
   {
      write_log( "$me: Bad query: $query" );
      mail_to_user( "fail", "Internal Error $requestID\n$query\n" . mysql_error( $us3_link ) );
      return( -1 );
   }

   list( $email_address, $investigatorGUID, $editXMLFilename ) =  mysql_fetch_array( $result );

   $query  = "SELECT personID FROM people " .
             "WHERE personGUID='$investigatorGUID'";
   $result = mysql_query( $query, $us3_link );

   list( $personID ) = mysql_fetch_array( $result );

   $query  = "SELECT clusterName, submitTime, queueStatus, method "              .
             "FROM HPCAnalysisRequest h, HPCAnalysisResult r "                   .
             "WHERE h.HPCAnalysisRequestID=$requestID "                          .
             "AND h.HPCAnalysisRequestID=r.HPCAnalysisRequestID";

   $result = mysql_query( $query, $us3_link );

   if ( ! $result )
   {
      write_log( "$me: Bad query:\n$query\n" . mysql_error( $us3_link ) );
      return( -1 );
   }

   if ( mysql_num_rows( $result ) == 0 )
   {
      write_log( "$me: US3 Table error - No records for requestID: $requestID" );
      return( -1 );
   }

   list( $cluster, $submittime, $queuestatus, $jobtype ) = mysql_fetch_array( $result );

   // Get the GFAC ID
   $query = "SELECT HPCAnalysisResultID, gfacID FROM HPCAnalysisResult " .
            "WHERE HPCAnalysisRequestID=$requestID";

   $result = mysql_query( $query, $us3_link );

   if ( ! $result )
   {
      write_log( "$me: Bad query: $query" );
      mail_to_user( "fail", "Internal Error $requestID\n$query\n" . mysql_error( $us3_link ) );
      return( -1 );
   }

   list( $HPCAnalysisResultID, $gfacID ) = mysql_fetch_array( $result ); 

   // Get data from global GFAC DB then insert it into US3 DB

   $result = mysql_select_db( $gDB, $gfac_link );

   if ( ! $result )
   {
      write_log( "$me: Could not connect to DB $gDB" );
      mail_to_user( "fail", "Internal Error $requestID\nCould not connect to DB $gDB" );
      return( -1 );
   }

   $query = "SELECT status, cluster, id FROM analysis " .
            "WHERE gfacID='$gfacID'";

   $result = mysql_query( $query, $gfac_link );
   if ( ! $result )
   {
      write_log( "$me: Could not select GFAC status for $gfacID" );
      mail_to_user( "fail", "Could not select GFAC status for $gfacID" );
      return( -1 );
   }
   
   list( $status, $cluster, $id ) = mysql_fetch_array( $result );

   if ( $cluster == 'bcf-local'  || $cluster == 'alamo-local' )
   {
         $clushost = $cluster;
         $clushost = preg_replace( "/\-local/", "", $clushost );
         get_local_files( $gfac_link, $clushost, $requestID, $id, $gfacID );
   }


   $query = "SELECT id FROM analysis " .
            "WHERE gfacID='$gfacID'";

   $result = mysql_query( $query, $gfac_link );

   if ( ! $result )
   {
      write_log( "$me: Bad query:\n$query\n" . mysql_error( $gfac_link ) );
      mail_to_user( "fail", "Internal error " . mysql_error( $gfac_link ) );
      return( -1 );
   }

   list( $analysisID ) = mysql_fetch_array( $result );

   // Get the request guid (LIMS submit dir name)
   $query  = "SELECT HPCAnalysisRequestGUID FROM HPCAnalysisRequest " .
             "WHERE HPCAnalysisRequestID = $requestID ";
   $result = mysql_query( $query, $us3_link );
   
   if ( ! $result )
   {
      write_log( "$me: Bad query:\n$query\n" . mysql_error( $us3_link ) );
   }

   list( $requestGUID ) = mysql_fetch_array( $result );
   $output_dir = "$submit_dir/$requestGUID";

   // Get stderr,stdout,tarfile from work directory
   if ( ! is_dir( "$output_dir" ) ) mkdir( "$output_dir", 0770 );
   chdir( "$output_dir" );
//write_log( "$me: gfacID=$gfacID" );
//write_log( "$me: submit_dir=$submit_dir" );
//write_log( "$me: requestGUID=$requestGUID" );
write_log( "$me: output_dir=$output_dir" );

   $stderr     = "";
   $stdout     = "";
   $tarfile    = "";
   $fn_stderr  = "Ultrascan.stderr";
   $fn_stdout  = "Ultrascan.stdout";
   $fn_tarfile = "analysis-results.tar";
   $num_try    = 0;
   while ( ! file_exists( $fn_tarfile ) && $num_try < 3 )
   {
      sleep( 10 );
      $num_try++;
   }

   $ofiles     = scandir( $output_dir );
   foreach ( $ofiles as $ofile )
   {
      if ( preg_match( "/.*stderr$/", $ofile ) )
         $fn_stderr  = $ofile;
      if ( preg_match( "/.*stdout$/", $ofile ) )
         $fn_stdout  = $ofile;
//write_log( "$me:    ofile=$ofile" );
   }
write_log( "$me: fn_stderr=$fn_stderr" );
write_log( "$me: fn_stdout=$fn_stdout" );
if (file_exists($fn_tarfile)) write_log( "$me: fn_tarfile=$fn_tarfile" );
else                          write_log( "$me: NOT FOUND: $fn_tarfile" );

   if ( file_exists( $fn_stderr  ) ) $stderr   = file_get_contents( $fn_stderr  );
   if ( file_exists( $fn_stdout  ) ) $stdout   = file_get_contents( $fn_stdout  );
   if ( file_exists( $fn_tarfile ) ) $tarfile  = file_get_contents( $fn_tarfile );

   if ( $cluster == 'alamo'  || $cluster == 'alamo-local' )
   {  // Filter "ipath_userinit" lines out of alamo stdout lines
      $prefln = strlen( $stdout );
      $output = array();
      exec( "grep -v 'ipath_userinit' $fn_stdout 2>&1", $output, $err );
      $stdout = implode( "\n", $output );
      $posfln = strlen( $stdout );
write_log( "$me: fn_stdout : filtered. Length $prefln -> $posfln ." );
   }

   // Save queue messages for post-mortem analysis
   $query = "SELECT message, time FROM queue_messages " .
            "WHERE analysisID = $analysisID " .
            "ORDER BY time ";
   $result = mysql_query( $query, $gfac_link );

   if ( ! $result )
   {
      // Just log it and continue
      write_log( "$me: Bad query:\n$query\n" . mysql_error( $gfac_link ) );
   }

   $now = date( 'Y-m-d H:i:s' );
   $message_log = "US3 DB: $db\n" .
                  "RequestID: $requestID\n" .
                  "GFAC ID: $gfacID\n" .
                  "Processed: $now\n\n" .
                  "Queue Messages\n\n" ;
   if ( mysql_num_rows( $result ) > 0 )
   {
      while ( list( $message, $time ) = mysql_fetch_array( $result ) )
         $message_log .= "$time $message\n";
   }

   $query = "DELETE FROM queue_messages " .
            "WHERE analysisID = $analysisID ";

   $result = mysql_query( $query, $gfac_link );

   if ( ! $result )
   {
      // Just log it and continue
      write_log( "$me: Bad query:\n$query\n" . mysql_error( $gfac_link ) );
   }

   $query = "SELECT queue_msg FROM analysis " .
            "WHERE gfacID='$gfacID' ";

   $result = mysql_query( $query, $gfac_link );
   list( $queue_msg ) = mysql_fetch_array( $result );

   // But let's allow for investigation of other large stdout and/or stderr
   if ( strlen( $stdout ) > 20480000 ||
        strlen( $stderr ) > 20480000 )
      write_log( "$me: stdout + stderr larger than 20M - $gfacID\n" );

   $message_log .= "\n\n\nStdout Contents\n\n" .
                   $stdout .
                   "\n\n\nStderr Contents\n\n" .
                   $stderr .
                   "\n\n\nGFAC Status: $status\n" .
                   "GFAC message field: $queue_msg\n";

   // Delete data from GFAC DB
   $query = "DELETE from analysis WHERE gfacID='$gfacID'";

   $result = mysql_query( $query, $gfac_link );

   if ( ! $result )
   {
      // Just log it and continue
      write_log( "$me: Bad query:\n$query\n" . mysql_error( $gfac_link ) );
   }


   // Try to create it if necessary, and write the file
   // Let's use FILE_APPEND, in case this is the second time around and the 
   //  GFAC job status was INSERTed, rather than UPDATEd
   if ( ! is_dir( $output_dir ) )
      mkdir( $output_dir, 0775, true );
   $message_filename = "$output_dir/$db-$requestID-messages.txt";
   file_put_contents( $message_filename, $message_log, FILE_APPEND );
  // mysql_close( $gfac_link );

   /////////
   // Insert data into HPCAnalysis

   $query = "UPDATE HPCAnalysisResult SET "                              .
            "stderr='" . mysql_real_escape_string( $stderr, $us3_link ) . "', " .
            "stdout='" . mysql_real_escape_string( $stdout, $us3_link ) . "', " .
            "queueStatus='completed' " .
            "WHERE HPCAnalysisResultID=$HPCAnalysisResultID";

   $result = mysql_query( $query, $us3_link );

   if ( ! $result )
   {
      write_log( "$me: Bad query:\n$query\n" . mysql_error( $us3_link ) );
      mail_to_user( "fail", "Bad query:\n$query\n" . mysql_error( $us3_link ) );
      return( -1 );
   }

   // Delete data from GFAC DB
   $query = "DELETE from analysis WHERE gfacID='$gfacID'";

   $result = mysql_query( $query, $gfac_link );

   if ( ! $result )
   {
      // Just log it and continue
      write_log( "$me: Bad query:\n$query\n" . mysql_error( $gfac_link ) );
   }

   // Expand the tar file

   if ( strlen( $tarfile ) == 0 )
   {
      write_log( "$me: No tarfile" );
      mail_to_user( "fail", "No results" );
      return( -1 );
   }

   $tar_out = array();
   exec( "tar -xf analysis-results.tar 2>&1", $tar_out, $err );

   // Insert the model files and noise files
   $files      = file( "analysis_files.txt", FILE_IGNORE_NEW_LINES );
   $noiseIDs   = array();
   $modelGUIDs = array();
   $mrecsIDs   = array();
   $fns_used   = array();

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

      if ( in_array( $fn, $fns_used ) )
         continue;

      $fns_used[] = $fn;

      if ( filesize( $fn ) < 100 )
      {
         write_log( "$me:fn is invalid $fn size filesize($fn)" );
         mail_to_user( "fail", "Internal error\n$fn is invalid" );
         return( -1 );
      }

      if ( preg_match( "/^job_statistics\.xml$/", $fn ) ) // Job statistics file
      {
         $xml         = file_get_contents( $fn );
         $statistics  = parse_xml( $xml, 'statistics' );
//         $ntries      = 0;
//
//         while ( $statistics['cpucount'] < 1  &&  $ntries < 3 )
//         {  // job_statistics file not totally copied, so retry
//            sleep( 10 );
//            $xml         = file_get_contents( $fn );
//            $statistics  = parse_xml( $xml, 'statistics' );
//            $ntries++;
//write_log( "$me:jobstats retry $ntries" );
//         }
//write_log( "$me:cputime=$statistics['cputime']" );

         $otherdata   = parse_xml( $xml, 'id' );

         $query = "UPDATE HPCAnalysisResult SET "   .
                  "wallTime = {$statistics['walltime']}, " .
                  "CPUTime = {$statistics['cputime']}, " .
                  "CPUCount = {$statistics['cpucount']}, " .
                  "max_rss = {$statistics['maxmemory']}, " .
                  "startTime = '{$otherdata['starttime']}', " .
                  "endTime = '{$otherdata['endtime']}', " .
                  "mgroupcount = {$otherdata['groupcount']} " .
                  "WHERE HPCAnalysisResultID=$HPCAnalysisResultID";
         $result = mysql_query( $query, $us3_link );

         if ( ! $result )
         {
            write_log( "$me: Bad query:\n$query\n" . mysql_error( $us3_link ) );
         }

         file_put_contents( "$output_dir/$fn", $xml );    // Copy to submit dir

         $file_type = "jobstats";
      }

      else if ( preg_match( "/\.noise/", $fn ) > 0 ) // It's a noise file
      {
         $xml        = file_get_contents( $fn );
         $noise_data = parse_xml( $xml, "noise" );
         $type       = ( $noise_data[ 'type' ] == "ri" ) ? "ri_noise" : "ti_noise";
         $desc       = $noise_data[ 'description' ];
         $modelGUID  = $noise_data[ 'modelGUID' ];
         $noiseGUID  = $noise_data[ 'noiseGUID' ];

         $query = "INSERT INTO noise SET "  .
                  "noiseGUID='$noiseGUID'," .
                  "modelGUID='$modelGUID'," .
                  "editedDataID=1, "        .
                  "modelID=1, "             .
                  "noiseType='$type',"      .
                  "description='$desc',"    .
                  "xml='" . mysql_real_escape_string( $xml, $us3_link ) . "'";

         // Add later after all files are processed: editDataID, modelID

         $result = mysql_query( $query, $us3_link );

         if ( ! $result )
         {
            write_log( "$me: Bad query:\n$query\n" . mysql_error( $us3_link ) );
            mail_to_user( "fail", "Internal error\n$query\n" . mysql_error( $us3_link ) );
            return( -1 );
         }

         $id        = mysql_insert_id( $us3_link );
         $file_type = "noise";
         $noiseIDs[] = $id;

         // Keep track of modelGUIDs for later, when we replace them
         $modelGUIDs[ $id ] = $modelGUID;
         
      }

      else if ( preg_match( "/\.mrecs/", $fn ) > 0 )  // It's an mrecs file
      {
         $xml         = file_get_contents( $fn );
         $mrecs_data  = parse_xml( $xml, "modelrecords" );
         $desc        = $mrecs_data[ 'description' ];
         $editGUID    = $mrecs_data[ 'editGUID' ];
write_log( "$me:   mrecs file editGUID=$editGUID" );
         if ( strlen( $editGUID ) < 36 )
            $editGUID    = "12345678-0123-5678-0123-567890123456";
         $mrecGUID    = $mrecs_data[ 'mrecGUID' ];
         $modelGUID   = $mrecs_data[ 'modelGUID' ];

         $query = "INSERT INTO pcsa_modelrecs SET "  .
                  "editedDataID="                .
                  "(SELECT editedDataID FROM editedData WHERE editGUID='$editGUID')," .
                  "modelID=0, "             .
                  "mrecsGUID='$mrecGUID'," .
                  "description='$desc',"    .
                  "xml='" . mysql_real_escape_string( $xml, $us3_link ) . "'";

         // Add later after all files are processed: editDataID, modelID

         $result = mysql_query( $query, $us3_link );

         if ( ! $result )
         {
            write_log( "$me: Bad query:\n$query\n" . mysql_error( $us3_link ) );
            mail_to_user( "fail", "Internal error\n$query\n" . mysql_error( $us3_link ) );
            return( -1 );
         }

         $id         = mysql_insert_id( $us3_link );
         $file_type  = "mrecs";
         $mrecsIDs[] = $id;

         // Keep track of modelGUIDs for later, when we replace them
         $rmodlGUIDs[ $id ] = $modelGUID;
//write_log( "$me:   mrecs file inserted into DB : id=$id" );
      }

      else                                           // It's a model file
      {
         $xml         = file_get_contents( $fn );
         $model_data  = parse_xml( $xml, "model" );
         $description = $model_data[ 'description' ];
         $modelGUID   = $model_data[ 'modelGUID' ];
         $editGUID    = $model_data[ 'editGUID' ];

         if ( $mc_iteration > 1 )
         {
            $miter       = sprintf( "_mcN%03d", $mc_iteration );
            $description = preg_replace( "/_mc[0-9]+/", $miter, $description );
write_log( "$me:   MODELUpd: O:description=$description" );
         }

         $query = "INSERT INTO model SET "       .
                  "modelGUID='$modelGUID',"      .
                  "editedDataID="                .
                  "(SELECT editedDataID FROM editedData WHERE editGUID='$editGUID')," .
                  "description='$description',"  .
                  "MCIteration='$mc_iteration'," .
                  "meniscus='$meniscus'," .
                  "variance='$variance'," .
                  "xml='" . mysql_real_escape_string( $xml, $us3_link ) . "'";

         $result = mysql_query( $query, $us3_link );

         if ( ! $result )
         {
            write_log( "$me: Bad query:\n$query " . mysql_error( $us3_link ) );
            mail_to_user( "fail", "Internal error\n$query\n" . mysql_error( $us3_link ) );
            return( -1 );
         }

         $modelID   = mysql_insert_id( $us3_link );
         $id        = $modelID;
         $file_type = "model";

         $query = "INSERT INTO modelPerson SET " .
                  "modelID=$modelID, personID=$personID";
         $result = mysql_query( $query, $us3_link );
      }

      $query = "INSERT INTO HPCAnalysisResultData SET "       .
               "HPCAnalysisResultID='$HPCAnalysisResultID', " .
               "HPCAnalysisResultType='$file_type', "         .
               "resultID=$id";

      $result = mysql_query( $query, $us3_link );

      if ( ! $result )
      {
         write_log( "$me: Bad query:\n$query\n" . mysql_error( $us3_link ) );
         mail_to_user( "fail", "Internal error\n$query\n" . mysql_error( $us3_link ) );
         return( -1 );
      }
   }

   // Now fix up noise entries
   // For noise files, there is, at most two: ti_noise and ri_noise
   // In this case there will only be one modelID

   foreach ( $noiseIDs as $noiseID )
   {
      $modelGUID = $modelGUIDs[ $noiseID ];
      $query = "UPDATE noise SET "                                                 .
               "editedDataID="                                                     .
               "(SELECT editedDataID FROM model WHERE modelGUID='$modelGUID'),"    .
               "modelID="                                                          .
               "(SELECT modelID FROM model WHERE modelGUID='$modelGUID')"          .
               "WHERE noiseID=$noiseID";

      $result = mysql_query( $query, $us3_link );

      if ( ! $result )
      {
         write_log( "$me: Bad query:\n$query\n" . mysql_error( $us3_link ) );
         mail_to_user( "fail", "Bad query\n$query\n" . mysql_error( $us3_link ) );
         return( -1 );
      }
   }

   // Now possibly fix up mrecs entries

   foreach ( $mrecsIDs as $mrecsID )
   {
      $modelGUID = $rmodlGUIDs[ $mrecsID ];
      $query = "UPDATE pcsa_modelrecs SET "                                                 .
               "modelID="                                                          .
               "(SELECT modelID FROM model WHERE modelGUID='$modelGUID')"          .
               "WHERE mrecsID=$mrecsID";

      $result = mysql_query( $query, $us3_link );

      if ( ! $result )
      {
         write_log( "$me: Bad query:\n$query\n" . mysql_error( $us3_link ) );
         mail_to_user( "fail", "Bad query\n$query\n" . mysql_error( $us3_link ) );
         return( -1 );
      }
write_log( "$me:     mrecs entry updated : mrecsID=$mrecsID" );
   }
//write_log( "$me:     mrecs entries updated" );

   // Copy results to LIMS submit directory (files there are deleted after 7 days)
   global $submit_dir; // LIMS submit files dir
   
   // Get the request guid (LIMS submit dir name)
   $query  = "SELECT HPCAnalysisRequestGUID FROM HPCAnalysisRequest " .
             "WHERE HPCAnalysisRequestID = $requestID ";
   $result = mysql_query( $query, $us3_link );
   
   if ( ! $result )
   {
      write_log( "$me: Bad query:\n$query\n" . mysql_error( $us3_link ) );
   }
   
//   list( $requestGUID ) = mysql_fetch_array( $result );
//   
//   chdir( "$submit_dir/$requestGUID" );
//   $f = fopen( "analysis-results.tar", "w" );
//   fwrite( $f, $tarfile );
//   fclose( $f );

   // Clean up
//   chdir ( $work );
   // exec( "rm -rf $gfacID" );

   mysql_close( $us3_link );

   /////////
   // Send email 

   mail_to_user( "success", "" );
}

function mail_to_user( $type, $msg )
{
   // Note to me. Just changed subject line to include a modified $status instead 
   // of the $type variable passed. More informative than just "fail" or "success." 
   // See how it works for awhile and then consider removing $type parameter from 
   // function.
   global $email_address;
   global $submittime;
   global $queuestatus;
   global $status;
   global $cluster;
   global $jobtype;
   global $org_name;
   global $admin_email;
   global $db;
   global $dbhost;
   global $requestID;
   global $gfacID;
   global $editXMLFilename;
   global $stdout;

global $me;
write_log( "$me mail_to_user(): sending email to $email_address for $gfacID" );

   // Get GFAC status and message
   // function get_gfac_message() also sets global $status
   $gfac_message = get_gfac_message( $gfacID );
   if ( $gfac_message === false ) $gfac_message = "Job Finished";
      
   // Create a status to put in the subject line
   switch ( $status )
   {
      case "COMPLETE":
         $subj_status = 'completed';
         break;

      case "CANCELLED":
      case "CANCELED":
         $subj_status = 'canceled';
         break;

      case "FAILED":
         $subj_status = 'failed';
         if ( preg_match( "/^US3-A/i", $gfacID ) )
         {  // For A/Thrift FAIL, get error message
            $gfac_message = getExperimentErrors( $gfacID );
//$gfac_message .= "Test ERROR MESSAGE";
         }
         break;

      case "ERROR":
         $subj_status = 'unknown error';
         break;

      default:
         $subj_status = $status;       // For now
         break;

   }

   $queuestatus = $subj_status;
   $limshost    = $dbhost;
   if ( $limshost == 'localhost' )
      $limshost    = gethostname();

   // Parse the editXMLFilename
   list( $runID, $editID, $dataType, $cell, $channel, $wl, $ext ) =
      explode( ".", $editXMLFilename );

   $headers  = "From: $org_name Admin<$admin_email>"     . "\n";
   $headers .= "Cc: $org_name Admin<$admin_email>"       . "\n";

   // Set the reply address
   $headers .= "Reply-To: $org_name<$admin_email>"      . "\n";
   $headers .= "Return-Path: $org_name<$admin_email>"   . "\n";

   // Try to avoid spam filters
   $now = time();
   $headers .= "Message-ID: <" . $now . "cleanup@$dbhost>\n";
   $headers .= "X-Mailer: PHP v" . phpversion()         . "\n";
   $headers .= "MIME-Version: 1.0"                      . "\n";
   $headers .= "Content-Transfer-Encoding: 8bit"        . "\n";

   $subject       = "UltraScan Job Notification - $subj_status - " . substr( $gfacID, 0, 16 );
   $message       = "
   Your UltraScan job is complete:

   Submission Time : $submittime
   LIMS Host       : $limshost
   Analysis ID     : $gfacID
   Request ID      : $requestID  ( $db )
   RunID           : $runID
   EditID          : $editID
   Data Type       : $dataType
   Cell/Channel/Wl : $cell / $channel / $wl
   Status          : $queuestatus
   Cluster         : $cluster
   Job Type        : $jobtype
   GFAC Status     : $status
   GFAC Message    : $gfac_message
   Stdout          : $stdout
   ";

   if ( $type != "success" ) $message .= "Grid Ctrl Error :  $msg\n";

   // Handle the error case where an error occurs before fetching the
   // user's email address
   if ( $email_address == "" ) $email_address = $admin_email;

   mail( $email_address, $subject, $message, $headers );
}

function parse_xml( $xml, $type )
{
   $parser = new XMLReader();
   $parser->xml( $xml );

   $results = array();

   while ( $parser->read() )
   {
      if ( $parser->name == $type )
      {
         while ( $parser->moveToNextAttribute() ) 
         {
            $results[ $parser->name ] = $parser->value;
         }

         break;
      }
   }

   $parser->close();
   return $results;
}

// Function to get information about the current job GFAC
function get_gfac_message( $gfacID )
{
  global $serviceURL;
  global $me;

  $hex = "[0-9a-fA-F]";
  if ( ! preg_match( "/^US3-Experiment/i", $gfacID ) &&
       ! preg_match( "/^US3-$hex{8}-$hex{4}-$hex{4}-$hex{4}-$hex{12}$/", $gfacID ) )
   {
      // Then it's not a GFAC job
      return false;
   }

   $url = "$serviceURL/jobstatus/$gfacID";
   try
   {
      $post = new HttpRequest( $url, HttpRequest::METH_GET );
      $http = $post->send();
      $xml  = $post->getResponseBody();      
   }
   catch ( HttpException $e )
   {
      write_log( "$me: Job status not available - $gfacID" );
      return false;
   }

   // Parse the result
   $gfac_message = parse_message( $xml );

   return $gfac_message;
}

function parse_message( $xml )
{
   global $status;
   $status       = "";
   $gfac_message = "";

   $parser = new XMLReader();
   $parser->xml( $xml );

   $results = array();

   while( $parser->read() )
   {
      $type = $parser->nodeType;

      if ( $type == XMLReader::ELEMENT )
         $name = $parser->name;

      else if ( $type == XMLReader::TEXT )
      {
         if ( $name == "status" ) 
            $status       = $parser->value;
         else 
            $gfac_message = $parser->value; 
      }
   }
      
   $parser->close();
   return $gfac_message;
}

function get_local_files( $gfac_link, $cluster, $requestID, $id, $gfacID )
{
   global $work;
   global $work_remote;
   global $me;
   global $db;
   global $status;

   // Figure out local working directory
   if ( ! is_dir( "$work/$gfacID" ) ) mkdir( "$work/$gfacID", 0770 );
   $pwd = chdir( "$work/$gfacID" );

   // Figure out remote directory
   $remoteDir = sprintf( "$work_remote/$db-%06d", $requestID );

   // Get stdout, stderr, output/analysis-results.tar
   $output = array();
//   $cmd = "scp us3@$cluster.uthscsa.edu:$remoteDir/stdout . 2>&1";
//
//   exec( $cmd, $output, $stat );
//   if ( $stat != 0 ) 
//      write_log( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );
//     
//   $cmd = "scp us3@$cluster.uthscsa.edu:$remoteDir/stderr . 2>&1";
//   exec( $cmd, $output, $stat );
//   if ( $stat != 0 ) 
//      write_log( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );

   $cmd = "scp us3@$cluster.uthscsa.edu:$remoteDir/output/analysis-results.tar . 2>&1";
   exec( $cmd, $output, $stat );
   if ( $stat != 0 ) 
      write_log( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );

   $cmd = "scp us3@$cluster.uthscsa.edu:$remoteDir/stdout . 2>&1";
   exec( $cmd, $output, $stat );
   if ( $stat != 0 ) 
   {
      write_log( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );
      sleep( 10 );
      write_log( "$me: RETRY" );
      exec( $cmd, $output, $stat );
      if ( $stat != 0 ) 
         write_log( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );
   }
     
   $cmd = "scp us3@$cluster.uthscsa.edu:$remoteDir/stderr . 2>&1";
   exec( $cmd, $output, $stat );
   if ( $stat != 0 ) 
   {
      write_log( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );
      sleep( 10 );
      write_log( "$me: RETRY" );
      exec( $cmd, $output, $stat );
      if ( $stat != 0 ) 
         write_log( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );
   }

   // Write the files to gfacDB

   if ( file_exists( "stderr" ) ) $stderr  = file_get_contents( "stderr" );
   if ( file_exists( "stdout" ) ) $stdout  = file_get_contents( "stdout" );
   if ( file_exists( "analysis-results.tar" ) ) 
      $tarfile = file_get_contents( "analysis-results.tar" );

   $query = "UPDATE analysis SET " .
            "stderr='"  . mysql_real_escape_string( $stderr,  $gfac_link ) . "'," .
            "stdout='"  . mysql_real_escape_string( $stdout,  $gfac_link ) . "'," .
            "tarfile='" . mysql_real_escape_string( $tarfile, $gfac_link ) . "'";

   $result = mysql_query( $query, $gfac_link );

   if ( ! $result )
   {
      write_log( "$me: Bad query:\n$query\n" . mysql_error( $gfac_link ) );
      echo "Bad query\n";
      return( -1 );
   }
}
?>
