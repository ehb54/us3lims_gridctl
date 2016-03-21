<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
include "$us3bin/listen-config.php";
include "/srv/www/htdocs/common/class/experiment_status.php";

write_log( "$self: Starting" );

$handle = fopen( $pipe, "r+" );

if ( $handle == NULL ) 
{
  write_log( "$self: Cannot open pipe" );
  exit( -1 );
}

$msg    = "";

// From a pipe, we don't know when the message terminates, so the sender
// added a null to indicate the end of each message
do 
{
   $input = fgetc( $handle );   // Read one character at a time
   $msg  .= $input;

   if ( $input[ 0 ] == chr( 0 ) )
   {
      // Go do some work
      $msg = rtrim( $msg );
      if ( $msg == "Stop listen" ) break;
      process( $msg );
      write_log( "$self: $msg" );
      $msg = "";
   }
} while ( true );

write_log( "$self: Stopping" );
exit();

// The format of the messages would be
// db-requestID: message ( colon-space )
function process( $msg )
{
   global $dbhost;
   global $user;
   global $passwd;
   global $self;

   $list                   = explode( ": ", $msg );
   list( $db, $requestID ) = explode( "-",  array_shift( $list ) );
   $message                = implode( ": ", $list );

   // Convert to integer
   settype( $requestID, 'integer' );

   // We need the gfacID
   $resource = mysql_connect( $dbhost, $user, $passwd );

   if ( ! $resource )
   {
      write_log( "$self process(): Could not connect to MySQL - " . mysql_error() );
      write_log( "$self process(): original msg - $msg" );
      return;
   }

   if ( ! mysql_select_db( $db, $resource ) )
   {
     write_log( "$self: Could not select DB $db" . mysql_error( $resource ) );
     write_log( "$self process(): original msg - $msg" );
     return;
   }

   $query = "SELECT gfacID FROM HPCAnalysisResult " .
            "WHERE HPCAnalysisRequestID=$requestID "             .
            "ORDER BY HPCAnalysisResultID DESC "                 .
            "LIMIT 1";

   $result = mysql_query( $query, $resource );
   
   if ( ! $result )
   {
     write_log( "$self process(): Bad query: $query" );
     write_log( "$self process(): original msg - $msg" );
     return;
   }

   // Set flags for Airavata/Thrift and "Finished..."
   list( $gfacID ) = mysql_fetch_row( $result );
   mysql_close( $resource );

   $is_athrift  = preg_match( "/^US3-A/i", $gfacID );
   $is_finished = preg_match( "/^Finished/i", $message );

   if ( $is_athrift )
   {  // Process submitted thru Airavata/Thrift
      if ( $is_finished )
      {  // Message is "Finished..." : Update message and status
write_log( "$self process(): Thrift + Finished" );
        update_db( $db, $requestID, 'finished', $message );
        update_aira( $gfacID, $message );     // wait for Airvata to deposit data
      }
      else
      {  // Other messages : just update message
//write_log( "$self process(): Thrift, NOT Finished" );
        $updmsg = 'update';
        if ( preg_match( "/^Starting/i", $message ) )
           $updmsg = 'starting';
        if ( preg_match( "/^Abort/i", $message ) )
           $updmsg = 'aborted';

        update_db( $db, $requestID, $updmsg, $message );
        update_gfac( $gfacID, "UPDATING", $message );
      }
   }

   else
   {  // Not Airavata/Thrift
      if ( $is_finished )
      {  // Handle "Finished..." message
         $hex = "[0-9a-fA-F]";

         if ( preg_match( "/^US3-Experiment/i", $gfacID ) ||
              preg_match( "/^US3-$hex{8}-$hex{4}-$hex{4}-$hex{4}-$hex{12}$/", $gfacID ) )
         {
            // Then it's a GFAC job
            update_db( $db, $requestID, 'finished', $message );
            update_gfac( $gfacID, "UPDATING", $message );     // wait for GFAC to deposit data
            notify_gfac_done( $gfacID );                      // notify them to go get it
         }

         else
         {
            // It's a local job
            update_db( $db, $requestID, 'finished', $message );
            update_gfac( $gfacID, "COMPLETE", $message );     // data should be there already
         }
      }

      else if ( preg_match( "/^Starting/i", $message ) )
      {
        update_db( $db, $requestID, 'starting', $message );
        update_gfac( $gfacID, "RUNNING", $message );
      }

      else if ( preg_match( "/^Abort/i", $message ) )
      {
        update_db( $db, $requestID, 'aborted', $message );
        update_gfac( $gfacID, "CANCELED", $message );
      }

      else
      {
        update_db( $db, $requestID, 'update', $message );
        update_gfac( $gfacID, "UPDATING", $message );
      }
   }
}

function update_db( $db, $requestID, $action, $message )
{
   global $dbhost;
   global $user;
   global $passwd;
   global $self;

   $resource = mysql_connect( $dbhost, $user, $passwd );

   if ( ! $resource )
   {
      write_log( "$self: Could not connect to DB" );
      return;
   }

   if ( ! mysql_select_db( $db, $resource ) )
   {
     write_log( "$self: Could not select DB $db" . mysql_error( $resource ) );
     return;
   }

   $query = "SELECT HPCAnalysisResultID FROM HPCAnalysisResult " .
            "WHERE HPCAnalysisRequestID=$requestID "             .
            "ORDER BY HPCAnalysisResultID DESC "                 .
            "LIMIT 1";

   $result = mysql_query( $query, $resource );
   
   if ( ! $result )
   {
     write_log( "$self: Bad query: $query" );
     return;
   }

   list( $resultID ) = mysql_fetch_row( $result );

   $query = "UPDATE HPCAnalysisResult SET ";

   switch ( $action )
   {
      case "starting":
         $query .= "queueStatus='running'," .
                   "startTime=now(), ";
         break;

      case "aborted":
         $query .= "queueStatus='aborted'," .
                   "endTime=now(), ";
         break;

      case "finished":
         $query .= "queueStatus='completed'," .
                   "endTime=now(), ";
//write_log( "$self process(): $requestID : dbupd : Finished" );
         break;

      case "update":
//write_log( "$self process(): $requestID : dbupd : update" );
      default:
         break;
   }

   $query .= "lastMessage='" . mysql_real_escape_string( $message ) . "'" .
             "WHERE HPCAnalysisResultID=$resultID";

   mysql_query( $query, $resource );
   mysql_close( $resource );
}

// Function to update the global database status
function update_gfac( $gfacID, $status, $message )
{
  global $dbhost;
  global $guser;
  global $gpasswd;
  global $gDB;
  global $self;

  $allowed_status = array( 'RUNNING',
                           'UPDATING',
                           'CANCELED',
                           'COMPLETE'
                         );

  // Get data from global GFAC DB 
  $gLink = mysql_connect( $dbhost, $guser, $gpasswd );
  if ( ! mysql_select_db( $gDB, $gLink ) )
  {
    write_log( "$self: Could not select DB $gDB" . mysql_error( $gLink ) );
    return;
  }

  $status = strtoupper( $status );
  if ( ! in_array( $status, $allowed_status ) )
  {
    write_log( "$self: update_gfac status $status not allowed" );
    return;
  }

  // if 'UPDATING' then we're only updating the queue_messages table
  if ( $status == 'UPDATING' )
  {
     $query = "UPDATE analysis " .
              "SET queue_msg='" . mysql_real_escape_string( $message ) . "' " .
              "WHERE gfacID='$gfacID'";

//write_log( "$self process(): updgf-u : status=$status" );
     mysql_query( $query, $gLink );
  }

  else
  {
     $query = "UPDATE analysis SET status='$status', " .
              "queue_msg='" . mysql_real_escape_string( $message ) . "' " .
              "WHERE gfacID='$gfacID'";

//write_log( "$self process(): updgf-s : status=$status" );
     mysql_query( $query, $gLink );
  }

  // Also update the queue_messages table
  $query  = "SELECT id FROM analysis " .
            "WHERE gfacID = '$gfacID'";
  $result = mysql_query( $query, $gLink );
  if ( ! $result )
  {
    write_log( "$self: bad query: $query " . mysql_error( $gLink ) );
    return;
  }

  if ( mysql_num_rows( $result ) == 0 )
  {
    write_log( "$self: can't find $gfacID in GFAC db" );
    return;
  }

  list( $aID ) = mysql_fetch_array( $result );

  $query  = "INSERT INTO queue_messages " .
            "SET analysisID = $aID, " .
            "message = '" . mysql_real_escape_string( $message ) . "'";
  $result = mysql_query( $query, $gLink );
  if ( ! $result )
  {
    write_log( "$self: bad query: $query " . mysql_error( $gLink ) );
    return;
  }

  mysql_close( $gLink );
}

// function to notify GFAC that the UDP message "Finished" has arrived
function notify_gfac_done( $gfacID )
{
  global $serviceURL;
  global $self;

  $hex = "[0-9a-fA-F]";
  if ( ! preg_match( "/^US3-Experiment/i", $gfacID ) &&
       ! preg_match( "/^US3-$hex{8}-$hex{4}-$hex{4}-$hex{4}-$hex{12}$/", $gfacID ) )
   {
      // Then it's not a GFAC job
      return false;
   }

   $url = "$serviceURL/setstatus/$gfacID";
   try
   {
      $post = new HttpRequest( $url, HttpRequest::METH_GET );
      $http = $post->send();
      $xml  = $post->getResponseBody();      
   }
   catch ( HttpException $e )
   {
      write_log( "$self: Set status unsuccessful -  $gfacID" );
      return false;
   }

   // Parse the result
   // Not sure we need to know $gfac_status = parse_response( $xml );

   // return $gfac_status;

   return true;
}

// Function to update the global database status (AThrift + Finished)
function update_aira( $gfacID, $message )
{
   global $dbhost;
   global $guser;
   global $gpasswd;
   global $gDB;
   global $self;

   // Get data from global GFAC DB 
   $gLink = mysql_connect( $dbhost, $guser, $gpasswd );
   if ( ! mysql_select_db( $gDB, $gLink ) )
   {
      write_log( "$self: Could not select DB $gDB" . mysql_error( $gLink ) );
      return;
   }

   // Update message and update status to 'FINISHED'
   $query = "UPDATE analysis SET status='FINISHED', " .
            "queue_msg='" . mysql_real_escape_string( $message ) . "' " .
            "WHERE gfacID='$gfacID'";

   mysql_query( $query, $gLink );
   write_log( "$self: Status FINISHED and 'Finished...' message updated" );

   // Also update the queue_messages table
   $query  = "SELECT id FROM analysis " .
             "WHERE gfacID = '$gfacID'";
   $result = mysql_query( $query, $gLink );
   if ( ! $result )
   {
      write_log( "$self: bad query: $query " . mysql_error( $gLink ) );
      return;
   }

   if ( mysql_num_rows( $result ) == 0 )
   {
//      write_log( "$self: can't find $gfacID in GFAC db" );
      return;
   }

   list( $aID ) = mysql_fetch_array( $result );

   $query  = "INSERT INTO queue_messages " .
             "SET analysisID = $aID, " .
             "message = '" . mysql_real_escape_string( $message ) . "'";
   $result = mysql_query( $query, $gLink );
   if ( ! $result )
   {
      write_log( "$self: bad query: $query " . mysql_error( $gLink ) );
      return;
   }

   mysql_close( $gLink );
}
?>
