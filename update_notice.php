<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
$us3etc = exec( "ls -d ~us3/lims/etc" );
include_once "$us3bin/listen-config.php";

// Get the US3 system release string
$s_url1 = "//bcf2.uthscsa.edu/ultrascan3/trunk/utils";
$s_url2 = "//bcf2.uthscsa.edu/ultrascan3/trunk/programs/us";

$s_cmd1 = "/usr/bin/svn info svn:$s_url1|grep Revision|cut -d ' ' -f2";
$s_cmd2 = "/usr/bin/svn info svn:$s_url2|grep Revision|cut -d ' ' -f2";

$s_rev1 = exec( $s_cmd1 );
$s_rev2 = exec( $s_cmd2 );

$sysrev = $s_rev2;
if ( $s_rev1 > $s_rev2 )
  $sysrev = $s_rev1;
$sysrev = "3.3." . $sysrev;

// Global variables
$notice_db  = "us3_notice";
$dbhost     = "localhost";
$dbuser     = "root";
$dbpassw    = exec( "cat ~/.sec/.pwsq" );

// Produce some output temporarily, so cron will send me message
$now = time();
echo "Time started: " . date( 'Y-m-d H:i:s', $now ) . "\n";

// Read and parse the local notice file

$n_fname  = "$us3etc/us3-notice.xml";
$fh       = fopen( $n_fname, "r" );
$xml      = fread( $fh, filesize( $n_fname ) );

$parser = new XMLReader();
$parser->xml( $xml );

$notices  = array();
$keys     = array();

echo "=====START of PARSE LOOP===== \n";
while( $parser->read() )
{
   $n_type = $parser->nodeType;
//echo "n_type=$n_type \n";
      
   if ( $n_type == XMLReader::ELEMENT )
   {
      $name = $parser->name;
//echo "name=$name \n";

      if ( $name == "notice" )
      {
         $type   = $parser->getAttribute( "type" );
//echo " type=$type \n";
         $parser->moveToAttribute( "revision" );
         $rev    = $parser->value;
//echo " rev=$rev \n";
         if ( $rev == "latest" )
            $rev    = $sysrev;
//echo " rev=$rev \n";

         $key    = $type . $rev;
         $notices[ $key ] = array();
//echo "  key=$key \n";
      }
   }
      
   else if ( $n_type == XMLReader::TEXT )
   {
      $msg      = $parser->readString();
//echo " msg=+++$msg+++ \n";
      $len      = strlen( $msg );

      if ( $len > 0 )
      {  // Only add if not an empty message
         $msg      = preg_replace( "/\@revision/", $rev, $msg );
         $msg      = preg_replace( "/'/", "\\'", $msg );
         $notices[ $key ][ 'type' ] = $type;
         $notices[ $key ][ 'rev'  ] = $rev;
         $notices[ $key ][ 'msg'  ] = $msg;
         $notices[ $key ][ 'act'  ] = 'add';
         $notices[ $key ][ 'id'   ] = '0';

         $keys[]  = $key;
      }
   }
}

$parser->close();
echo "=====END of PARSE LOOP===== \n";

// Get data from notice DB. Update the action field to
//  reflect which action is required on each entry:
//   "add"  - is only present in file (add to DB);
//   "del"  - is only present in DB (delete from DB);
//   "upd"  - present in both, but messages differ (update in DB);
//   "none" - present in both and messages identical (no DB update).

$noteLink = mysql_connect( $dbhost, $dbuser, $dbpassw );

if ( ! mysql_select_db( $notice_db, $noteLink ) )
{
   echo "Could not select DB $notice_db - " . mysql_error() . "\n";
   exit();
}
   
$query = "SELECT id, type, revision, message FROM notice";

$result = mysql_query( $query, $noteLink )
   or die( "Query failed : $query<br />" . mysql_error() );

echo "=====START of DB QUERY LOOP===== \n";
$num_rows = mysql_num_rows( $result );

echo "   numrows = $num_rows \n";
while ( list( $id, $type, $rev, $msg ) = mysql_fetch_array( $result ) )
{
   $key    = $type . $rev;

   if ( in_array( $key, $keys ) )
   {  // Entry is in both file and DB
      $msgf   = $notices[ $key ][ 'msg'  ];
      $notices[ $key ][ 'id'   ] = $id;
      $notices[ $key ][ 'type' ] = $type;
      $notices[ $key ][ 'rev'  ] = $rev;

      if ( strcmp( $msg, $msgf ) == 0 )
      {  // Messages match, so no update is needed
         $notices[ $key ][ 'act'  ] = 'none';
      }
      else
      {  // Messages differ, so DB entry must be updated
         $notices[ $key ][ 'act'  ] = 'upd';
         $notices[ $key ][ 'msg'  ] = $msgf;
echo "   msg  = ++$msg++\n";
echo "   msgf = ++$msgf++\n";
      }
   }

   else
   {  // Entry is only in DB, so a delete is needed
      $notices[ $key ] = array();
      $notices[ $key ][ 'id'   ] = $id;
      $notices[ $key ][ 'type' ] = $type;
      $notices[ $key ][ 'rev'  ] = $rev;
      $notices[ $key ][ 'act'  ] = 'del';
      $notices[ $key ][ 'msg'  ] = $msg;
      $keys[]  = $key;
   }
}
echo "=====END of DB QUERY LOOP===== \n";

echo "=====START of DB Update LOOP===== \n";
foreach ( $keys as $key )
{
   $type  = $notices[ $key ][ 'type' ];
   $rev   = $notices[ $key ][ 'rev'  ];
   $act   = $notices[ $key ][ 'act'  ];
   $id    = $notices[ $key ][ 'id'   ];
   $msg   = $notices[ $key ][ 'msg'  ];
   $len   = strlen( $msg );
   echo "-- key=$key --\n";
   echo "    type:  $type \n";
   echo "    rev:   $rev  \n";
   echo "    act:   $act  \n";
   echo "    id:    $id   \n";
//   echo "    msg:   $msg  \n";
   echo "    msg:   ( $len characters ) \n";

   if ( $act == "add" )
   {  // Must add the entry to the database
      $query = "INSERT INTO notice " .
               "SET type='$type', revision='$rev', message='$msg'";
   }
   else if ( $act == "del" )
   {  // Must delete the entry from the database
      $query = "DELETE FROM notice WHERE id='$id'";
   }
   else if ( $act == "upd" )
   {  // Must update an existing entry in the database
      $query = "UPDATE notice " .
               "SET message='$msg' " .
               "WHERE id='$id'";
   }
   echo "      query: [ $query ]  \n";

   $result = mysql_query( $query, $noteLink )
      or die( "Query failed : $query<br />" . mysql_error() );

}
echo "=====END of DB Update LOOP===== \n";

?>
