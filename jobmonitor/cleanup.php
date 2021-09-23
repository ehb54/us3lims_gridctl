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

function aira_cleanup( $us3_db, $reqID, $db_handle )
{
   global $org_domain;
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
   global $stderr;
   global $stdout;
   global $tarfile;
   global $requestID;
   global $submit_dir;
   $me        = 'cleanup_aira.php';

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
      return( -1 );
   }

   list( $email_address, $investigatorGUID, $editXMLFilename ) =  mysqli_fetch_array( $result );

   $query  = "SELECT personID FROM ${us3_db}.people " .
             "WHERE personGUID='$investigatorGUID'";
   $result = mysqli_query( $db_handle, $query );

   list( $personID ) = mysqli_fetch_array( $result );

   $query  = "SELECT clusterName, submitTime, queueStatus, analType " .
             "FROM ${us3_db}.HPCAnalysisRequest h, ${us3_db}.HPCAnalysisResult r "        .
             "WHERE h.HPCAnalysisRequestID=$requestID "               .
             "AND h.HPCAnalysisRequestID=r.HPCAnalysisRequestID";

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
      return( -1 );
   }

   if ( mysqli_num_rows( $result ) == 0 )
   {
      write_logld( "$me: US3 Table error - No records for requestID: $requestID" );
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
      return( -1 );
   }

   list( $HPCAnalysisResultID, $gfacID, $endtime ) = mysqli_fetch_array( $result ); 

   ## Get data from global GFAC DB then insert it into US3 DB

/*
   $result = mysqli_select_db( $db_handle, $gDB );

   if ( ! $result )
   {
      write_logld( "$me: Could not connect to DB $gDB" );
      mail_to_user( "fail", "Internal Error $requestID\nCould not connect to DB $gDB" );
      return( -1 );
   }
 */

   $query = "SELECT status, cluster, id FROM gfac.analysis " .
            "WHERE gfacID='$gfacID'";

   $result = mysqli_query( $db_handle, $query );
   if ( ! $result )
   {
      write_logld( "$me: Could not select GFAC status for $gfacID" );
      mail_to_user( "fail", "Could not select GFAC status for $gfacID" );
      return( -1 );
   }
   
   list( $status, $cluster, $id ) = mysqli_fetch_array( $result );

   $is_us3iab  = preg_match( "/us3iab/", $cluster );
   $is_local   = preg_match( "/-local/", $cluster );

   if ( $is_us3iab  ||  $is_local )
   {
         $clushost = $cluster;
         $clushost = preg_replace( "/\-local/", "", $clushost );
         get_local_files( $db_handle, $clushost, $requestID, $id, $gfacID );
   }


   $query = "SELECT id FROM gfac.analysis " .
            "WHERE gfacID='$gfacID'";

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
      mail_to_user( "fail", "Internal error " . mysqli_error( $db_handle ) );
      return( -1 );
   }

   list( $analysisID ) = mysqli_fetch_array( $result );

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

   ## Get stderr,stdout,tarfile from work directory
   if ( ! is_dir( "$output_dir" ) ) mkdir( "$output_dir", 0770 );
   chdir( "$output_dir" );
##write_logld( "$me: gfacID=$gfacID" );
##write_logld( "$me: submit_dir=$submit_dir" );
##write_logld( "$me: requestGUID=$requestGUID" );
write_logld( "$me: output_dir=$output_dir" );

   $stderr     = "";
   $stdout     = "";
   $tarfile    = "";
   $fn_stderr  = "Ultrascan.stderr";
   $fn_stdout  = "Ultrascan.stdout";
   $fn_tarfile = "analysis-results.tar";
   $secwait    = 10;
   $num_try    = 0;
write_logld( "$me: fn_tarfile=$fn_tarfile" );
   while ( ! file_exists( $fn_tarfile )  &&  $num_try < 3 )
   {
      sleep( $secwait );
      $num_try++;
      $secwait   *= 2;
write_logld( "$me:  tar-exists: num_try=$num_try" );
   }

   $ofiles     = scandir( $output_dir );
   foreach ( $ofiles as $ofile )
   {
      if ( preg_match( "/^.*stderr$/", $ofile ) )
         $fn_stderr  = $ofile;
      if ( preg_match( "/^.*stdout$/", $ofile ) )
         $fn_stdout  = $ofile;
##write_logld( "$me:    ofile=$ofile" );
   }
write_logld( "$me: fn_stderr=$fn_stderr" );
write_logld( "$me: fn_stdout=$fn_stdout" );
if (file_exists($fn_tarfile)) write_logld( "$me: fn_tarfile=$fn_tarfile" );
else                          write_logld( "$me: NOT FOUND: $fn_tarfile" );

   if ( file_exists( $fn_stderr ) )
   {  ## Reconstruct stderr if too big
      $lense = filesize( $fn_stderr );
      if ( $lense > 1000000 )
      { ## Replace exceptionally large stderr with smaller version
         exec( "head -n 10000 $fn_stderr >stderr-h", $output, $stat );
         exec( "tail -n 10000 $fn_stderr >stderr-t", $output, $stat );
         exec( "mv $fn_stderr stderr-orig",          $output, $stat );
         exec( "cat stderr-h stderr-t >$fn_stderr",  $output, $stat );
write_logld( "$me:  stderr reduced from $lense original bytes ." );
      }
   }

   $stderr   = '';
   $stdout   = '';
   $tarfile  = '';
   if ( file_exists( $fn_stderr  ) ) $stderr   = file_get_contents( $fn_stderr  );
   if ( file_exists( $fn_stdout  ) ) $stdout   = file_get_contents( $fn_stdout  );
   if ( file_exists( $fn_tarfile ) ) $tarfile  = file_get_contents( $fn_tarfile );
write_logld( "$me(0):  length contents stderr,stdout,tarfile -- "
 . strlen($stderr) . "," . strlen($stdout) . "," . strlen($tarfile) );
   ## If stdout,stderr have no content, retry after delay
   if ( strlen( $stdout ) == 0  ||  strlen( $stderr ) == 0 )
   {
      sleep( 20 );
      if ( file_exists( $fn_stderr  ) )
      {
         $lense = filesize( $fn_stderr );
         if ( $lense > 1000000 )
         { ## Replace exceptionally large stderr with smaller version
            exec( "head -n 10000 $fn_stderr >stderr-h", $output, $stat );
            exec( "tail -n 10000 $fn_stderr >stderr-t", $output, $stat );
            exec( "mv $fn_stderr stderr-orig",          $output, $stat );
            exec( "cat stderr-h stderr-t >$fn_stderr",  $output, $stat );
write_logld( "$me:  stderr reduced from $lense original bytes ." );
         }
         $stderr   = file_get_contents( $fn_stderr  );
      }
      if ( file_exists( $fn_stdout  ) )
         $stdout   = file_get_contents( $fn_stdout  );
   }

write_logld( "$me:  length contents stderr,stdout,tarfile -- "
 . strlen($stderr) . "," . strlen($stdout) . "," . strlen($tarfile) );

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
   if ( mysqli_num_rows( $result ) > 0 )
   {
      while ( list( $message, $time ) = mysqli_fetch_array( $result ) )
         $message_log .= "$time $message\n";
   }

   $query = "DELETE FROM gfac.queue_messages " .
            "WHERE analysisID = $analysisID ";

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      ## Just log it and continue
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
   }

   $query = "SELECT queue_msg FROM gfac.analysis " .
            "WHERE gfacID='$gfacID' ";

   $result = mysqli_query( $db_handle, $query );
   list( $queue_msg ) = mysqli_fetch_array( $result );

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

   ## Delete data from GFAC DB
   $query = "DELETE from gfac.analysis WHERE gfacID='$gfacID'";

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      ## Just log it and continue
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
   }


   ## Try to create it if necessary, and write the file
   ## Let's use FILE_APPEND, in case this is the second time around and the 
   ##  GFAC job status was INSERTed, rather than UPDATEd
   if ( ! is_dir( $output_dir ) )
      mkdir( $output_dir, 0775, true );
   $message_filename = "$output_dir/$db-$requestID-messages.txt";
   file_put_contents( $message_filename, $message_log, FILE_APPEND );
  ## mysqli_close( $db_handle );

   ########/
   ## Insert data into HPCAnalysis

   $query = "UPDATE ${us3_db}.HPCAnalysisResult SET "                              .
            "stderr='" . mysqli_real_escape_string( $db_handle, $stderr ) . "', " .
            "stdout='" . mysqli_real_escape_string( $db_handle, $stdout ) . "', " .
            "queueStatus='completed' " .
            "WHERE HPCAnalysisResultID=$HPCAnalysisResultID";

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
      mail_to_user( "fail", "Bad query:\n$query\n" . mysqli_error( $db_handle ) );
      return( -1 );
   }

   ## Delete data from GFAC DB
   $query = "DELETE from gfac.analysis WHERE gfacID='$gfacID'";

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      ## Just log it and continue
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
   }

   ## Expand the tar file

   if ( strlen( $tarfile ) == 0 )
   {
      write_logld( "$me: No tarfile" );
      mail_to_user( "fail", "No results" );
      return( -1 );
   }

   $tar_out = array();
   exec( "tar -xf analysis-results.tar 2>&1", $tar_out, $err );

   ## Insert the model files and noise files
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
         write_logld( "$me:fn is invalid $fn size filesize($fn)" );
         mail_to_user( "fail", "Internal error\n$fn is invalid" );
         return( -1 );
      }

      if ( preg_match( "/^job_statistics\.xml$/", $fn ) ) ## Job statistics file
      {
         $xml         = file_get_contents( $fn );
         $statistics  = parse_xml( $xml, 'statistics' );
##         $ntries      = 0;
##
##         while ( $statistics['cpucount'] < 1  &&  $ntries < 3 )
##         {  ## job_statistics file not totally copied, so retry
##            sleep( 10 );
##            $xml         = file_get_contents( $fn );
##            $statistics  = parse_xml( $xml, 'statistics' );
##            $ntries++;
##write_logld( "$me:jobstats retry $ntries" );
##         }
##write_logld( "$me:cputime=$statistics['cputime']" );

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
         $id        = 1;
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
            return( -1 );
         }

         $id        = mysqli_insert_id( $db_handle );
         $file_type = "noise";
         $noiseIDs[] = $id;

         ## Keep track of modelGUIDs for later, when we replace them
         $modelGUIDs[ $id ] = $modelGUID;
         
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
            return( -1 );
         }

         $id         = mysqli_insert_id( $db_handle );
         $file_type  = "mrecs";
         $mrecsIDs[] = $id;

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
            $miter       = sprintf( "_mcN%03d", $mc_iteration );
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
            return( -1 );
         }

         $modelID   = mysqli_insert_id( $db_handle );
         $id        = $modelID;
         $file_type = "model";

         $query = "INSERT INTO ${us3_db}.modelPerson SET " .
                  "modelID=$modelID, personID=$personID";
         $result = mysqli_query( $db_handle, $query );
      }

      else      ## Undetermined type:  skip result data update
         continue;

      $query = "INSERT INTO ${us3_db}.HPCAnalysisResultData SET "       .
               "HPCAnalysisResultID='$HPCAnalysisResultID', " .
               "HPCAnalysisResultType='$file_type', "         .
               "resultID=$id";

      $result = mysqli_query( $db_handle, $query );

      if ( ! $result )
      {
         write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
         mail_to_user( "fail", "Internal error\n$query\n" . mysqli_error( $db_handle ) );
         return( -1 );
      }
   }

   ## Now fix up noise entries
   ## For noise files, there is, at most two: ti_noise and ri_noise
   ## In this case there will only be one modelID

   foreach ( $noiseIDs as $noiseID )
   {
      $modelGUID = $modelGUIDs[ $noiseID ];
      $query = "UPDATE ${us3_db}.noise SET "                                                 .
               "editedDataID="                                                     .
               "(SELECT editedDataID FROM ${us3_db}.model WHERE modelGUID='$modelGUID'),"    .
               "modelID="                                                          .
               "(SELECT modelID FROM ${us3_db}.model WHERE modelGUID='$modelGUID')"          .
               "WHERE noiseID=$noiseID";

      $result = mysqli_query( $db_handle, $query );

      if ( ! $result )
      {
         write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
         mail_to_user( "fail", "Bad query\n$query\n" . mysqli_error( $db_handle ) );
         return( -1 );
      }
   }

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
         return( -1 );
      }
write_logld( "$me:     mrecs entry updated : mrecsID=$mrecsID" );
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
   
##   list( $requestGUID ) = mysqli_fetch_array( $result );
##   
##   chdir( "$submit_dir/$requestGUID" );
##   $f = fopen( "analysis-results.tar", "w" );
##   fwrite( $f, $tarfile );
##   fclose( $f );

   ## Clean up
##   chdir ( $work );
   ## exec( "rm -rf $gfacID" );

##   mysqli_close( $db_handle );

   ########/
   ## Send email 

   mail_to_user( "success", "" );
}

function mail_to_user( $type, $msg )
{
   ## Note to me. Just changed subject line to include a modified $status instead 
   ## of the $type variable passed. More informative than just "fail" or "success." 
   ## See how it works for awhile and then consider removing $type parameter from 
   ## function.
   global $email_address;
   global $submittime;
   global $endtime;
   global $queuestatus;
   global $status;
   global $cluster;
   global $jobtype;
   global $org_name;
   global $org_domain;
   global $servhost;
   global $admin_email;
   global $db;
   global $dbhost;
   global $requestID;
   global $gfacID;
   global $editXMLFilename;
   global $stdout;

global $me;
write_logld( "$me mail_to_user(): sending email to $email_address for $gfacID" );

   ## Get GFAC status and message
   ## function get_gfac_message() also sets global $status
   $gfac_message = get_gfac_message( $gfacID );
   if ( $gfac_message === false ) $gfac_message = "Job Finished";
      
   ## Create a status to put in the subject line
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
         {  ## For A/Thrift FAIL, get error message
            $gfac_message = getExperimentErrors( $gfacID );
##$gfac_message .= "Test ERROR MESSAGE";
         }
         break;

      case "ERROR":
         $subj_status = 'unknown error';
         break;

      default:
         $subj_status = $status;       ## For now
         break;

   }

   $queuestatus = $subj_status;
   $limshost    = $dbhost;
   if ( $limshost == 'localhost' )
   {
      $limshost    = gethostname();
      if ( ! preg_match( "/\./", $limshost ) )
      {  ## no domain in hostname
         if ( isset( $org_domain ) )
            $limshost    = $limshost . "." . $org_domain;
         else if ( isset( $servhost ) )
            $limshost    = $servhost;
      }
      if ( preg_match( "/lims4.noval/", $limshost ) )
      {  # simplify name of vm on jetstream
         $limshost    = "uslims4.aucsolutions.com";
      }
   }

   $aria_details = "";
   if ( is_aira_job( $gfacID ) ) {
       $jobDetails = getJobDetails( $gfacID );
       if ( $jobDetails ) {
           if ( $jobDetails === ' No Job Details ' ) {
               $jdstdout = $jobDetails;
               $jdstderr = $jobDetails;
               
           } else {
               $jdstdout = isset( $jobDetails->stdOut ) ? trim( $jobDetails->stdOut ) : "n/a";
               $jdstderr = isset( $jobDetails->stdErr ) ? trim( $jobDetails->stdErr ) : "n/a";
           }
       } else {
           $jdstdout = "failed to get job details";
           $jdstderr = "failed to get job details";
       }
       $aira_details =
           sprintf(   "   Airavata stdout : %s\n", $jdstdout )
           . sprintf( "   Airavata stderr : %s\n", $jdstderr )
           ;
   }

   ## Parse the editXMLFilename
   list( $runID, $editID, $dataType, $cell, $channel, $wl, $ext ) =
      explode( ".", $editXMLFilename );

   $headers  = "From: $org_name Admin<$admin_email>"     . "\n";
   $headers .= "Cc: $org_name Admin<$admin_email>"       . "\n";
#   $headers .= "CC: $org_name Admin<alexsav.science@gmail.com>"       . "\n";
   $headers .= "CC: $org_name Admin<gegorbet@gmail.com>"       . "\n";

   ## Set the reply address
   $headers .= "Reply-To: $org_name<$admin_email>"      . "\n";
   $headers .= "Return-Path: $org_name<$admin_email>"   . "\n";

   ## Try to avoid spam filters
   $now      = time();
   $tnow     = date( 'Y-m-d H:i:s' );
   $headers .= "Message-ID: <" . $now . "cleanup@$dbhost>\n";
   $headers .= "X-Mailer: PHP v" . phpversion()         . "\n";
   $headers .= "MIME-Version: 1.0"                      . "\n";
   $headers .= "Content-Transfer-Encoding: 8bit"        . "\n";

   $subject       = "UltraScan Job Notification - $subj_status - " . substr( $gfacID, 0, 16 );
   $message       = "
   Your UltraScan job is complete:

   Submission Time : $submittime
   Job End Time    : $endtime
   Mail Time       : $tnow
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
$aira_details   Stdout          : $stdout
   ";

   if ( $type != "success" ) $message .= "Grid Ctrl Error :  $msg\n";

   ## Handle the error case where an error occurs before fetching the
   ## user's email address
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

## Function to get information about the current job GFAC
function get_gfac_message( $gfacID )
{
  global $serviceURL;
  global $me;

  $hex = "[0-9a-fA-F]";
  if ( ! preg_match( "/^US3-Experiment/i", $gfacID ) &&
       ! preg_match( "/^US3-$hex{8}-$hex{4}-$hex{4}-$hex{4}-$hex{12}$/", $gfacID ) )
   {
      ## Then it's not a GFAC job
      return false;
   }

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

function get_local_files( $db_handle, $cluster, $requestID, $id, $gfacID )
{
   global $work;
   global $work_remote;
   global $me;
   global $db;
   global $dbhost;
   global $servhost;
   global $org_domain;
   global $status;
   
   $is_us3iab  = preg_match( "/us3iab/", $cluster );
   $is_jetstr  = preg_match( "/jetstream/", $cluster );

   $limshost   = $dbhost;
   $stderr     = '';
   $stdout     = '';
   $tarfile    = '';

   if ( $limshost == 'localhost' )
   {
      $limshost    = gethostname();
      if ( ! preg_match( "/\./", $limshost ) )
      {  ## no domain in hostname
         if ( isset( $org_domain ) )
            $limshost    = $limshost . "." . $org_domain;
         else if ( isset( $servhost ) )
            $limshost    = $servhost;
      }
   }

   if ( preg_match( "/alamo/", $limshost )  &&
        preg_match( "/alamo/", $cluster  ) )
   {  ## If both LIMS and cluster are alamo, set up local transfers
      $is_us3iab   = 1;
      if ( ! preg_match( "/\/local/", $work_remote ) )
         $work_remote = $work_remote . "/local";
   }

   ## Figure out job's remote (or local) work directory
   $remoteDir = sprintf( "$work_remote/$db-%06d", $requestID );
   $ruser     = "us3";
##write_logld( "$me: is_us3iab=$is_us3iab  remoteDir=$remoteDir" );

   ## Get stdout, stderr, output/analysis-results.tar
   $output = array();

   if ( $is_us3iab == 0 )
   {
      ## For "-local", recompute remote work directory
      $clushost = "$cluster.hs.umt.edu";
      $lworkdir = "~us3/lims/work/local";
      if ( preg_match( "/jetstream/", $cluster ) )
      {
         $clushost = "js-169-137.jetstream-cloud.org";
         $lworkdir = "/N/us3_cluster/work/local";
      }
      
      if ( preg_match( "/chinook/", $cluster ) )
      {
         $clushost = "chinook.hs.umt.edu";
         $lworkdir = "/home/us3/lims/work"; 
      }
      if ( preg_match( "/umontana/", $cluster ) )
      {
         $clushost = "login.gscc.umt.edu";
         $ruser    = "bd142854e";
         $lworkdir = "/home/bd142854e/cluster/work";
      }
      if ( preg_match( "/demeler9/", $cluster ) )
      {
         $clushost = "demeler9.uleth.ca";
         $lworkdir = "/home/us3/lims/work"; 
      }
      if ( preg_match( "/demeler1/", $cluster ) )
      {
         $clushost = "demeler1.uleth.ca";
         $lworkdir = "/home/us3/lims/work";
      }

      $cmd         = "ssh $ruser@$clushost 'ls -d $lworkdir' 2>/dev/null";
      exec( $cmd, $output, $stat );
      $work_remote = $output[ 0 ];
      $remoteDir   = sprintf( "$work_remote/$db-%06d", $requestID );
write_logld( "$me:  -LOCAL: remoteDir=$remoteDir" );

      ## Figure out local working directory
      if ( ! is_dir( "$work/$gfacID" ) ) mkdir( "$work/$gfacID", 0770 );
      $pwd = chdir( "$work/$gfacID" );

      $cmd = "scp $ruser@$clushost:$remoteDir/output/analysis-results.tar . 2>&1";

      exec( $cmd, $output, $stat );
      if ( $stat != 0 )
         write_logld( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );

      $cmd = "scp $ruser@$clushost:$remoteDir/stdout . 2>&1";

      exec( $cmd, $output, $stat );
      if ( $stat != 0 )
      {
         write_logld( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );
         sleep( 10 );
         write_logld( "$me: RETRY" );
         exec( $cmd, $output, $stat );
         if ( $stat != 0 )
            write_logld( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );
      }

      $cmd = "scp $ruser@$clushost:$remoteDir/stderr . 2>&1";

      exec( $cmd, $output, $stat );
      if ( $stat != 0 )
      {
         write_logld( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );
         sleep( 10 );
         write_logld( "$me: RETRY" );
         exec( $cmd, $output, $stat );
         if ( $stat != 0 )
            write_logld( "$me: Bad exec:\n$cmd\n" . implode( "\n", $output ) );
      }
   }
   else
   { ## Is US3IAB or alamo-to-alamo, so just change to local work directory
      $pwd = chdir( "$remoteDir" );
write_logld( "$me: IS US3IAB: pwd=$pwd $remoteDir");
   }


   ## Write the files to gfacDB

   $secwait    = 10;
   $num_try    = 0;
   while ( ! file_exists( "stderr" )  &&  $num_try < 3 )
   {  ## Do waits and retries to let stderr appear
      sleep( $secwait );
      $num_try++;
      $secwait   *= 2;
write_logld( "$me:  not-exist-stderr: num_try=$num_try" );
   }

   $lense = 0;
   if ( file_exists( "stderr"  ) )
   {
      $lense = filesize( "stderr" );
      if ( $lense > 1000000 )
      { ## Replace exceptionally large stderr with smaller version
         exec( "mv stderr stderr-orig", $output, $stat );
         exec( "head -n 5000 stderr-orig >stderr-h", $output, $stat );
         exec( "tail -n 5000 stderr-orig >stderr-t", $output, $stat );
         exec( "cat stderr-h stderr-t >stderr", $output, $stat );
      }
      $stderr  = file_get_contents( "stderr" );
   }
   else
   {
      $stderr  = "";
   }

   if ( file_exists( "stdout" ) ) $stdout  = file_get_contents( "stdout" );

   $fn1_tarfile = "analysis-results.tar";
   $fn2_tarfile = "output/" . $fn1_tarfile;
   if ( file_exists( $fn1_tarfile ) )
      $tarfile = file_get_contents( $fn1_tarfile );
   else if ( file_exists( $fn2_tarfile ) )
      $tarfile = file_get_contents( $fn2_tarfile );

##   $lense = strlen( $stderr );
##   if ( $lense > 1000000 )
##   { ## Replace exceptionally large stderr with smaller version
##      exec( "mv stderr stderr-orig", $output, $stat );
##      exec( "head -n 5000 stderr-orig >stderr-h", $output, $stat );
##      exec( "tail -n 5000 stderr-orig >stderr-t", $output, $stat );
##      exec( "cat stderr-h stderr-t >stderr", $output, $stat );
##      $stderr  = file_get_contents( "stderr" );
##   }
$lene = strlen( $stderr );
write_logld( "$me: stderr size: $lene  (was $lense)");
$leno = strlen( $stdout );
write_logld( "$me: stdout size: $leno");
$lent = strlen( $tarfile );
write_logld( "$me: tarfile size: $lent");
   $esstde = mysqli_real_escape_string( $db_handle, $stderr );
   $esstdo = mysqli_real_escape_string( $db_handle, $stdout );
   $estarf = mysqli_real_escape_string( $db_handle, $tarfile );
$lene = strlen($esstde);
write_logld( "$me:  es-stderr size: $lene");
$leno = strlen($esstdo);
write_logld( "$me:  es-stdout size: $leno");
$lenf = strlen($estarf);
write_logld( "$me:  es-tarfile size: $lenf");
   $query = "UPDATE gfac.analysis SET " .
            "stderr='"  . $esstde . "'," .
            "stdout='"  . $esstdo . "'," .
            "tarfile='" . $estarf . "'" .
            "WHERE gfacID='$gfacID'";
            ;

   $result = mysqli_query( $db_handle, $query );

   if ( ! $result )
   {
      write_logld( "$me: Bad query:\n$query\n" . mysqli_error( $db_handle ) );
      echo "Bad query\n";
      return( -1 );
   }
}
?>
