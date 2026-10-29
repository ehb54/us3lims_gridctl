<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
require_once __DIR__ . '/gridctl_bootstrap.php';
gridctl_require_not_root( __FILE__, 'start', null, true );

# add locking
if ( isset( $lock_dir ) ) {
    $lock_main_script_name  = __FILE__;
    require "$us3bin/lock.php";
}

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

   // Look up the scheduler job ID associated with this request.
   $resource = mysqli_connect( $dbhost, $user, $passwd, $db );

   if ( ! $resource )
   {
      write_log( "$self process(): Could not connect to MySQL - " . mysqli_error($resource) );
      write_log( "$self process(): dbhost,user,db $dbhost, $user, $db" );
      write_log( "$self process(): original msg - $msg" );
      return;
   }

   $query = "SELECT gfacID FROM HPCAnalysisResult " .
            "WHERE HPCAnalysisRequestID=$requestID "             .
            "ORDER BY HPCAnalysisResultID DESC "                 .
            "LIMIT 1";

   $result = mysqli_query( $resource, $query );
   
   if ( ! $result )
   {
     write_log( "$self process(): Bad query: $query" );
     write_log( "$self process(): original msg - $msg" );
     mysqli_close( $resource );
     return;
   }

   // Read the current job-tracking state before processing the message.
   list( $gfacID ) = mysqli_fetch_row( $result );

   $query2 = "SELECT status from gfac.analysis where gfacID='$gfacID'"
           . pipe_job_condition( $resource, $db, $requestID );
   $result2 = mysqli_query( $resource, $query2 );
   
   if ( ! $result2 )
   {
     write_log( "$self process(): Bad query: $query2" );
     mysqli_close( $resource );
     return;
   }
   
   list( $gfacStatus ) = mysqli_fetch_row( $result2 );

   if ( !strncmp( $gfacStatus, "CANCEL", 6 ) ) {
     write_log( "$self process(): state CANCELED, no updates done" );
     return;
   }   

   $is_finished = preg_match( "/^Finished/i", $message );

   // Update the LIMS request and central job-tracking state directly.
   if ( $is_finished )
   {  // Local job finished — data should be there already
      update_db( $db, $requestID, 'finished', $message );
      update_gfac( $gfacID, "COMPLETE", $message, $db, $requestID );
   }

   else if ( preg_match( "/^Starting/i", $message ) )
   {
     update_db( $db, $requestID, 'starting', $message );
     update_gfac( $gfacID, "RUNNING", $message, $db, $requestID );
   }

   else if ( preg_match( "/^Abort/i", $message ) )
   {
     update_db( $db, $requestID, 'aborted', $message );
     update_gfac( $gfacID, "CANCELED", $message, $db, $requestID );
   }

   else
   {
     update_db( $db, $requestID, 'update', $message );
     update_gfac( $gfacID, "UPDATING", $message, $db, $requestID );
   }
}

function update_db( $db, $requestID, $action, $message )
{
   global $dbhost;
   global $user;
   global $passwd;
   global $self;

   $resource = mysqli_connect( $dbhost, $user, $passwd, $db );

   if ( ! $resource )
   {
     write_log( "$self: Could not connect to DB $db " . mysqli_error( $resource ) );
     return;
   }

   $query = "SELECT HPCAnalysisResultID,queueStatus FROM HPCAnalysisResult " .
            "WHERE HPCAnalysisRequestID=$requestID "             .
            "ORDER BY HPCAnalysisResultID DESC "                 .
            "LIMIT 1";

   $result = mysqli_query( $resource, $query );
   
   if ( ! $result )
   {
     write_log( "$self: Bad query: $query" );
     return;
   }

   list( $resultID, $queueStatus ) = mysqli_fetch_row( $result );

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
          if ( $queueStatus == 'queued' ) {
             ## catch missed Starting UDP
             ## start time is not perfect, but is the time of first UDP update received
             $query .=
                 "queueStatus='running'," 
                 . "startTime=now(), "
                 ;
          }
      default:
         break;
   }

   $query .= "lastMessage='" . mysqli_real_escape_string( $resource, $message ) . "'" .
             "WHERE HPCAnalysisResultID=$resultID";

   mysqli_query( $resource, $query );
   mysqli_close( $resource );
}

// Update the central job-tracking status and append its message history.
## This message's gfac.analysis row: the job number alone is not unique once
## two clusters reuse it, so also its instance and, after the 4.3.0 upgrade
## adds the column, its own request.
function pipe_job_condition( $link, $db, $requestID )
{
  static $has_request_column = null;
  if ( $has_request_column === null )
  {
    $gdb = isset( $GLOBALS[ 'gDB' ] ) && $GLOBALS[ 'gDB' ] !== '' ? $GLOBALS[ 'gDB' ] : 'gfac';
    $res = mysqli_query( $link, "SHOW COLUMNS FROM `$gdb`.analysis LIKE 'HPCAnalysisRequestID'" );
    $has_request_column = $res && mysqli_num_rows( $res ) > 0;
  }
  return " AND us3_db='" . mysqli_real_escape_string( $link, $db ) . "'"
       . ( $has_request_column ? " AND (HPCAnalysisRequestID IS NULL OR HPCAnalysisRequestID=" . (int) $requestID . ")" : '' );
}

function update_gfac( $gfacID, $status, $message, $db = '', $requestID = 0 )
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

  // Connect to the central job-tracking database.
  $gLink     = mysqli_connect( $dbhost, $guser, $gpasswd, $gDB );
  if ( ! $gLink )
  {
    write_log( "$self: Could not select DB $gDB " . mysqli_error( $gLink ) );
    return;
  }

  $job_where = "WHERE gfacID='$gfacID'" . ( $db !== '' ? pipe_job_condition( $gLink, $db, $requestID ) : '' );

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
              "SET queue_msg='" . mysqli_real_escape_string( $gLink, $message ) . "' " .
              $job_where;

//write_log( "$self process(): updgf-u : status=$status" );
     mysqli_query( $gLink, $query );
  }

  else
  {
     $query = "UPDATE analysis SET status='$status', " .
              "queue_msg='" . mysqli_real_escape_string( $gLink, $message ) . "' " .
              $job_where;

//write_log( "$self process(): updgf-s : status=$status" );
     mysqli_query( $gLink, $query );
  }

  // Also update the queue_messages table
  $query  = "SELECT id FROM analysis " . $job_where;
  $result = mysqli_query( $gLink, $query );
  if ( ! $result )
  {
    write_log( "$self: bad query: $query " . mysqli_error( $gLink ) );
    return;
  }

  if ( mysqli_num_rows( $result ) == 0 )
  {
    write_log( "$self: can't find $gfacID in GFAC db" );
    return;
  }

  list( $aID ) = mysqli_fetch_array( $result );

  $query  = "INSERT INTO queue_messages " .
            "SET analysisID = $aID, " .
            "message = '" . mysqli_real_escape_string( $gLink, $message ) . "'";
  $result = mysqli_query( $gLink, $query );
  if ( ! $result )
  {
    write_log( "$self: bad query: $query " . mysqli_error( $gLink ) );
    return;
  }

  mysqli_close( $gLink );
}

?>
