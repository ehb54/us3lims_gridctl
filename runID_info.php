<?php
/*
 * runID_info.php
 *
 * All the linkages for a particular runID
 *
 */
include_once 'checkinstance.php';

if ( ($_SESSION['userlevel'] != 2) &&   // data analyst can see own runID's
     ($_SESSION['userlevel'] != 3) &&   // admin and super admin can see all
     ($_SESSION['userlevel'] != 4) &&
     ($_SESSION['userlevel'] != 5) )
{
  header('Location: index.php');
  exit();
} 

include 'config.php';
include 'db.php';
include 'lib/utility.php';
include $class_dir . 'experiment_status.php';

// Start displaying page
$page_title = "Info by Run ID";
$css = 'css/admin.css';
include 'header.php';

global $uses_airavata;

?>
<!-- Begin page content -->
<div id='content'>

  <h1 class="title">Info by Run ID</h1>
  <!-- Place page content here -->

<?php
  if ( isset( $_POST['experimentID'] ) )
  {
    $text  = experiment_select( 'experimentID', $_POST['experimentID'] );
    if ( $_POST['experimentID'] != -1 )               // -1 is Please select...
       $text .= runID_info( $_POST['experimentID'] );
  }

  else if ( isset( $_GET['RequestID'] ) )
    $text = HPCDetail( $_GET['RequestID'] );

  else
    $text  = experiment_select( 'experimentID' );

  echo $text;

?>
</div>

<?php
include 'footer.php';
exit();

// Function to create a dropdown for available runIDs
function experiment_select( $select_name, $current_ID = NULL )
{
  $myID = $_SESSION['id'];

  $users_clause = ( $_SESSION['userlevel'] > 2 ) ? "" : "AND people.personID = $myID ";

  $query  = "SELECT experimentID, runID, lname " .
            "FROM experiment, projectPerson, people " .
            "WHERE experiment.projectID = projectPerson.projectID " .
            "AND projectPerson.personID = people.personID " .
            $users_clause .
            "ORDER BY lname, runID ";
  $result = mysql_query( $query )
            or die( "Query failed : $query<br />" . mysql_error() );

  if ( mysql_num_rows( $result ) == 0 ) return "";

  $text = "<form action='{$_SERVER['PHP_SELF']}' method='post'>\n" .
          "  <select name='$select_name' size='1' onchange='form.submit();'>\n" .
          "    <option value=-1>Please select...</option>\n";
  while ( list( $experimentID, $runID, $lname ) = mysql_fetch_array( $result ) )
  {
    $selected = ( $current_ID == $experimentID ) ? " selected='selected'" : "";
    $text .= "    <option value='$experimentID'$selected>$lname: $runID</option>\n";
  }

  $text .= "  </select>\n" .
           "</form>\n";

  return $text;
}

// A function to retrieve information about that runID
function runID_info( $experimentID )
{
  $query  = "SELECT people.personID, personGUID, lname, fname, email " .
            "FROM experiment, projectPerson, people " .
            "WHERE experiment.experimentID = $experimentID " .
            "AND experiment.projectID = projectPerson.projectID " .
            "AND projectPerson.personID = people.personID ";
  $result = mysql_query( $query )
            or die( "Query failed : $query<br />\n" . mysql_error() );
  list( $ID, $GUID, $lname, $fname, $email ) = mysql_fetch_array( $result );

  $text = <<<HTML
  <table cellspacing='0' cellpadding='0' class='admin'>
  <caption>Investigator Information</caption>
  <tr><th>ID:</th>
      <td>$ID</td></tr>

  <tr><th>GUID:</th>
      <td>$GUID</td></tr>

  <tr><th>Name:</th>
      <td>$fname $lname</td></tr>

  <tr><th>Email:</th>
      <td>$email</td></tr>

  </table>
HTML;

  $query  = "SELECT experimentGUID, coeff1, coeff2, type, runType " .
            "FROM experiment, rotorCalibration " .
            "WHERE experimentID = $experimentID " .
            "AND experiment.rotorCalibrationID = rotorCalibration.rotorCalibrationID ";
  $result = mysql_query( $query )
            or die( "Query failed : $query<br />\n" . mysql_error() );
  list( $GUID, $coeff1, $coeff2, $type, $runType ) = mysql_fetch_array( $result );
  $text .= <<<HTML
  <table cellspacing='0' cellpadding='0' class='admin'>
  <caption>Run Information</caption>
  <tr><th>GUID:</th>
      <td>$GUID</td></tr>

  <tr><th>Rotor stretch coeff 1:</th>
      <td>$coeff1</td></tr>

  <tr><th>Rotor stretch coeff 2:</th>
      <td>$coeff2</td></tr>

  <tr><th>Experiment type:</th>
      <td>$type</td></tr>

  <tr><th>Run Type:</th>
      <td>$runType</td></tr>

  </table>
HTML;

  $query  = "SELECT rawDataID, rawDataGUID, filename, solutionID " .
            "FROM rawData " .
            "WHERE experimentID = $experimentID " .
            "ORDER BY filename ";
  $result = mysql_query( $query )
            or die( "Query failed : $query<br />\n" . mysql_error() );

  if ( mysql_num_rows( $result ) == 0 )
    return $text;

  $rawIDs      = array();
  $solutionIDs = array();
  $text .= <<<HTML
  <table cellspacing='0' cellpadding='0' class='admin'>
  <caption>Raw Data</caption>
  <thead>
    <tr><th>ID</th>
        <th>GUID</th>
        <th>Filename</th>
        <th>Solution</th>
    </tr>
  </thead>

  <tbody>
HTML;

  while ( list( $ID, $GUID, $filename, $solutionID ) = mysql_fetch_array( $result ) )
  {
    $rawIDs[]      = $ID;
    $solutionIDs[] = $solutionID;

    $text .= <<<HTML
    <tr><td>$ID</td>
        <td>$GUID</td>
        <td>$filename</td>
        <td>$solutionID</td>
    </tr>

HTML;

  }
  
  $text .= "</tbody>\n\n" .
           "</table>\n";

  $rawIDs_csv = implode( ", ", $rawIDs );
  $query  = "SELECT editedDataID, rawDataID, editGUID, filename " .
            "FROM editedData " .
            "WHERE rawDataID IN ( $rawIDs_csv ) " .
            "ORDER BY editedDataID, filename ";
  $result = mysql_query( $query )
            or die( "Query failed : $query<br />\n" . mysql_error() );

  if ( mysql_num_rows( $result ) == 0 )
    return $text;

  $text .= <<<HTML
  <table cellspacing='0' cellpadding='0' class='admin'>
  <caption>Edit Profiles</caption>
  <thead>
    <tr><th>ID</th>
        <th>GUID</th>
        <th>Filename</th>
        <th>Raw ID</th>
    </tr>
  </thead>

  <tbody>

HTML;

  $editIDs = array();
  while ( list ( $editID, $rawID, $GUID, $filename ) = mysql_fetch_array( $result ) )
  {
    $editIDs[] = $editID;

    $text .= <<<HTML
    <tr><td>$editID</td>
        <td>$GUID</td>
        <td>$filename</td>
        <td>$rawID</td>
    </tr>

HTML;
  }

  $text .= "</tbody>\n\n" .
           "</table>\n";

  $editIDs_csv = implode( ", ", $editIDs );
  $query  = "SELECT model.modelID, editedDataID, modelGUID, variance, meniscus, personID " .
            "FROM model LEFT JOIN modelPerson " .
            "ON ( model.modelID = modelPerson.modelID ) " .
            "WHERE editedDataID IN ( $editIDs_csv ) " .
            "ORDER BY modelID ";
  $result = mysql_query( $query )
            or die( "Query failed : $query<br />\n" . mysql_error() );

  if ( mysql_num_rows( $result ) != 0 )
  {
    $text .= <<<HTML
    <table cellspacing='0' cellpadding='0' class='admin'>
    <caption>Models</caption>
    <thead>
      <tr><th>ID</th>
          <th>GUID</th>
          <th>Edit ID</th>
          <th>Variance</th>
          <th>Meniscus</th>
          <th>Owner ID</th>
      </tr>
    </thead>

    <tbody>

HTML;

    $modelIDs = array();
    while ( list ( $modelID, $editID, $GUID, $variance, $meniscus, $personID ) = mysql_fetch_array( $result ) )
    {
      $modelIDs[] = $modelID;

      $text .= <<<HTML
      <tr><td>$modelID</td>
          <td>$GUID</td>
          <td>$editID</td>
          <td>$variance</td>
          <td>$meniscus</td>
          <td>$personID</td>
      </tr>

HTML;
    }

    $text .= "</tbody>\n\n" .
             "</table>\n";
  }

  if ( count( $modelIDs ) > 0 )
  {
    $modelIDs_csv = implode( ", ", $modelIDs );
    $query  = "SELECT noiseID, noiseGUID, editedDataID, modelID, modelGUID, noiseType " .
              "FROM noise " .
              "WHERE modelID IN ( $modelIDs_csv ) " .
              "ORDER BY noiseID ";
    $result = mysql_query( $query )
              or die( "Query failed : $query<br />\n" . mysql_error() );

    if ( mysql_num_rows( $result ) != 0 )
    {
      $text .= <<<HTML
      <table cellspacing='0' cellpadding='0' class='admin'>
      <caption>Noise Linked to Models</caption>
      <thead>
        <tr><th>ID</th>
            <th>GUID</th>
            <th>Edit ID</th>
            <th>Model ID</th>
            <th>Model GUID</th>
            <th>Type</th>
        </tr>
      </thead>
    
      <tbody>

HTML;

      while ( list ( $noiseID, $GUID, $editID, $modelID, $modelGUID, $type ) = mysql_fetch_array( $result ) )
      {
        $text .= <<<HTML
        <tr><td>$noiseID</td>
            <td>$GUID</td>
            <td>$editID</td>
            <td>$modelID</td>
            <td>$modelGUID</td>
            <td>$type</td>
        </tr>

HTML;
      }

      $text .= "</tbody>\n\n" .
               "</table>\n";
    }
  }

  $query  = "SELECT noiseID, noiseGUID, editedDataID, modelID, modelGUID, noiseType " .
            "FROM noise " .
            "WHERE editedDataID IN ( $editIDs_csv ) " .
            "ORDER BY noiseID ";
  $result = mysql_query( $query )
            or die( "Query failed : $query<br />\n" . mysql_error() );

  if ( mysql_num_rows( $result ) != 0 )
  {
    $text .= <<<HTML
    <table cellspacing='0' cellpadding='0' class='admin'>
    <caption>Noise Linked to Edit Profiles</caption>
    <thead>
      <tr><th>ID</th>
          <th>GUID</th>
          <th>Edit ID</th>
          <th>Model ID</th>
          <th>Model GUID</th>
          <th>Type</th>
      </tr>
    </thead>
  
    <tbody>

HTML;

    while ( list ( $noiseID, $GUID, $editID, $modelID, $modelGUID, $type ) = mysql_fetch_array( $result ) )
    {
      $text .= <<<HTML
      <tr><td>$noiseID</td>
          <td>$GUID</td>
          <td>$editID</td>
          <td>$modelID</td>
          <td>$modelGUID</td>
          <td>$type</td>
      </tr>

HTML;
    }

    $text .= "</tbody>\n\n" .
             "</table>\n";
  }

  $reportIDs = array();
  $query  = "SELECT reportID, reportGUID, title " .
            "FROM report " .
            "WHERE experimentID = $experimentID " .
            "ORDER BY reportID ";

  $result = mysql_query( $query )
            or die( "Query failed : $query<br />\n" . mysql_error() );

  if ( mysql_num_rows( $result ) != 0 )
  {
    $text .= <<<HTML
    <table cellspacing='0' cellpadding='0' class='admin'>
    <caption>Reports Related to This Experiment</caption>
    <thead>
      <tr><th>ID</th>
          <th>GUID</th>
          <th>Title</th>
      </tr>
    </thead>
  
    <tbody>

HTML;

    while ( list ( $reportID, $GUID, $title ) = mysql_fetch_array( $result ) )
    {
      $reportIDs[] = $reportID;
      $text .= <<<HTML
      <tr><td>$reportID</td>
          <td>$GUID</td>
          <td>$title</td>
      </tr>

HTML;
    }

    $text .= "</tbody>\n\n" .
             "</table>\n";
  }

  $reportTripleIDs = array();
  if ( ! empty( $reportIDs ) )
  {
    $reportIDs_csv = implode( ",", $reportIDs );
    $query  = "SELECT reportTripleID, reportTripleGUID, resultID, triple, dataDescription, reportID " .
              "FROM reportTriple " .
              "WHERE reportID IN ( $reportIDs_csv ) " .
              "ORDER BY reportID, reportTripleID ";
  
    $result = mysql_query( $query )
              or die( "Query failed : $query<br />\n" . mysql_error() );

    if ( mysql_num_rows( $result ) != 0 )
    {
      $text .= <<<HTML
      <table cellspacing='0' cellpadding='0' class='admin'>
      <caption>Report Triples Related to Reports</caption>
      <thead>
        <tr><th>ID</th>
            <th>GUID</th>
            <th>Result ID</th>
            <th>Triple</th>
            <th>Description</th>
            <th>Report ID</th>
        </tr>
      </thead>
    
      <tbody>
  
HTML;
  
      while ( list ( $reportTripleID, $GUID, $resultID, $triple, $dataDesc, $rptID ) 
                   = mysql_fetch_array( $result ) )
      {
        $reportTripleIDs[] = $reportTripleID;
        $text .= <<<HTML
        <tr><td>$reportTripleID</td>
            <td>$GUID</td>
            <td>$resultID</td>
            <td>$triple</td>
            <td>$dataDesc</td>
            <td>$rptID</td>
        </tr>
  
HTML;
      }
  
      $text .= "</tbody>\n\n" .
               "</table>\n";
    }
  }

  if ( ! empty( $reportTripleIDs ) )
  {
    $reportTripleIDs_csv = implode( ",", $reportTripleIDs );
    $query  = "SELECT d.reportDocumentID, reportDocumentGUID, editedDataID, label, " .
              "filename, analysis, subAnalysis, documentType, l.reportTripleID " .
              "FROM documentLink l, reportDocument d " .
              "WHERE reportTripleID IN ( $reportTripleIDs_csv ) " .
              "AND l.reportDocumentID = d.reportDocumentID " .
              "ORDER BY reportTripleID, reportDocumentID ";
  
    $result = mysql_query( $query )
              or die( "Query failed : $query<br />\n" . mysql_error() );

    if ( mysql_num_rows( $result ) != 0 )
    {
      $text .= <<<HTML
      <table cellspacing='0' cellpadding='0' class='admin'>
      <caption>Report Documents Related to Triples</caption>
      <thead>
        <tr><th>ID</th>
            <th>GUID</th>
            <th>Edit ID</th>
            <th>Label/Filename</th>
            <th>Anal/Sub/DocType</th>
            <th>Trip ID</th>
        </tr>
      </thead>
    
      <tbody>
  
HTML;
  
      while ( list ( $reportDocumentID, $GUID, $editID, $label, $filename, 
                     $analysis, $subAnal, $docType, $tripID ) 
                   = mysql_fetch_array( $result ) )
      {
        $text .= <<<HTML
        <tr><td>$reportDocumentID</td>
            <td>$GUID</td>
            <td>$editID</td>
            <td>$label/$filename</td>
            <td>$analysis/$subAnal/$docType</td>
            <td>$tripID</td>
        </tr>
  
HTML;
      }
  
      $text .= "</tbody>\n\n" .
               "</table>\n";
    }
  }

  $query  = "SELECT HPCAnalysisRequestID, HPCAnalysisRequestGUID, editXMLFilename, " .
            "submitTime, clusterName, method " .
            "FROM HPCAnalysisRequest " .
            "WHERE experimentID = $experimentID " .
            "ORDER BY HPCAnalysisRequestID ";
  $result = mysql_query( $query )
            or die( "Query failed : $query<br />\n" . mysql_error() );

  if ( mysql_num_rows( $result ) == 0 )
    return $text;

  $requestIDs = array();
  $text .= <<<HTML
  <table cellspacing='0' cellpadding='0' class='admin'>
  <caption>HPC Requests</caption>
  <thead>
    <tr><th>ID</th>
        <th>GUID</th>
        <th>XML Filename</th>
        <th>Submit</th>
        <th>Cluster</th>
        <th>Method</th>
    </tr>
  </thead>

  <tbody>
HTML;

  while ( list( $ID, $GUID, $filename, $submit, $cluster, $method ) = mysql_fetch_array( $result ) )
  {
    $requestIDs[]  = $ID;

    $text .= <<<HTML
    <tr><td><a href='{$_SERVER['PHP_SELF']}?RequestID=$ID'>$ID</a></td>
        <td>$GUID</td>
        <td>$filename</td>
        <td>$submit</td>
        <td>$cluster</td>
        <td>$method</td>
    </tr>

HTML;

  }
  
  $text .= "</tbody>\n\n" .
           "</table>\n";

  $requestIDs_csv = implode( ", ", $requestIDs );
  $query  = "SELECT HPCAnalysisResultID, HPCAnalysisRequestID, gfacID, queueStatus, updateTime " .
            "FROM HPCAnalysisResult " .
            "WHERE HPCAnalysisRequestID IN ( $requestIDs_csv ) " .
            "ORDER BY HPCAnalysisResultID ";
  $result = mysql_query( $query )
            or die( "Query failed : $query<br />\n" . mysql_error() );

  if ( mysql_num_rows( $result ) != 0 )
  {
    $text .= <<<HTML
    <table cellspacing='0' cellpadding='0' class='admin'>
    <caption>HPC Results</caption>
    <thead>
      <tr><th>ID</th>
          <th>Request ID</th>
          <th>gfac ID</th>
          <th>Status</th>
          <th>Updated</th>
      </tr>
    </thead>

    <tbody>
HTML;

    $incomplete = array();
    while ( list( $ID, $requestID, $gfacID, $status, $updated ) = mysql_fetch_array( $result ) )
    {
      if ( $status != 'completed' )
        $incomplete[] = $gfacID;

      $text .= <<<HTML
      <tr><td>$ID</td>
          <td>$requestID</td>
          <td>$gfacID</td>
          <td>$status</td>
          <td>$updated</td>
      </tr>

HTML;

    }
  
  $text .= "</tbody>\n\n" .
           "</table>\n";

  }

  if ( empty( $incomplete ) )
    return $text;

  // Now switch over to the global db
  global $globaldbhost, $globaldbuser, $globaldbpasswd, $globaldbname;

  $globaldb = mysql_connect( $globaldbhost, $globaldbuser, $globaldbpasswd );

  if ( ! $globaldb )
  {
    $text .= "<p>Cannot open global database on $globaldbhost</p>\n";
    return $text;
  }

  if ( ! mysql_select_db( $globaldbname, $globaldb ) ) 
  {
    $text .= "<p>Cannot change to global database $globaldbname</p>\n";
    return $text;
  }

  $text .= <<<HTML
  <table cellspacing='0' cellpadding='0' class='admin'>
  <caption>GFAC Status</caption>
  <thead>
    <tr><th>gfacID</th>
        <th>Cluster</th>
        <th>DB</th>
        <th>Status</th>
        <th>Message</th>
        <th>Updated</th>
    </tr>
  </thead>

  <tbody>
HTML;

  $in_queue = 0;
  foreach ( $incomplete as $gfacID )
  {
    
    $query  = "SELECT cluster, us3_db, status, queue_msg, time " .
              "FROM analysis " .
              "WHERE gfacID = '$gfacID' ";
    $result = mysql_query( $query )
              or die( "Query failed : $query<br />\n" . mysql_error() );
  
    if ( mysql_num_rows( $result ) == 1 )
    {
      $in_queue++;
      list( $cluster, $db, $status, $msg, $time ) = mysql_fetch_array( $result );
      $text .= <<<HTML
      <tr><td>$gfacID</td>
          <td>$cluster</td>
          <td>$db</td>
          <td>$status</td>
          <td>$msg</td>
          <td>$time</td>
      </tr>

HTML;
    }
  }
  
  if ( $in_queue == 0 )
     $text .= "<tr><td colspan='6'>No local jobs currently in the queue</td></tr>\n";

  $text .= "</tbody>\n\n" .
           "</table>\n";

  mysql_close( $globaldb );

  return $text;
}

function HPCDetail( $requestID )
{
  $query = "SELECT * FROM HPCAnalysisRequest WHERE HPCAnalysisRequestID=$requestID";
  $result = mysql_query( $query )
           or die( "Query failed : $query<br />\n" . mysql_error());
  $row = mysql_fetch_assoc( $result );
  $row['requestXMLFile'] = '<pre>' . htmlentities( $row['requestXMLFile'] ) . '</pre>';

  // Save for later
  $requestGUID  = $row['HPCAnalysisRequestGUID'];
  $cluster = $row['clusterName'];
  #var_dump($cluster); 
 $text = <<<HTML
  <table cellspacing='0' cellpadding='0' class='admin'>
  <caption>HPC Request Detail</caption>

HTML;

  foreach ($row as $key => $value)
  {
    $text .= "  <tr><th>$key</th><td>$value</td></tr>\n";
  }

  $text .= "</table>\n";
  
  $query = "SELECT * FROM HPCAnalysisResult WHERE HPCAnalysisRequestID=$requestID";
  $result = mysql_query( $query )
           or die( "Query failed : $query<br />\n" . mysql_error());
  $row = mysql_fetch_assoc( $result );
  $row['jobfile'] = '<pre>' . htmlentities( $row['jobfile'] ) . '</pre>';

  // Get GFAC job status
  global $uses_airavata;

  if ( $uses_airavata === true && $cluster != 'juropa.fz-juelich.de' )
  {
    $row['gfacStatus'] = nl2br( getExperimentStatus( $row['gfacID'] ) );
  }
  else
  {
    $row['gfacStatus'] = nl2br( getJobstatus( $row['gfacID'] ) );
  }

  // Get queue messages from disk directory, if it still exists
  global $submit_dir;
  global $dbname;

  $msg_filename = "$submit_dir$requestGUID/$dbname-$requestID-messages.txt";
  $queue_msgs = false;
  if ( file_exists( $msg_filename ) )
  {
    $queue_msgs   = file_get_contents( $msg_filename );
    $len_msgs     = strlen( $queue_msgs );
    $queue_msgs   = '<pre>' . $queue_msgs . '</pre>';
  }

  // Get resulting model and noise information
  if ( ! empty( $resultID ) )
  {
    $resultID = $row['HPCAnalysisResultID'];
    $models   = array();
    $noise    = array();
    $query  = "SELECT resultID FROM HPCAnalysisResultData " .
              "WHERE HPCAnalysisResultID = $resultID " .
              "AND HPCAnalysisResultType = 'model' ";
    $result = mysql_query( $query )
             or die( "Query failed : $query<br />\n" . mysql_error());
    $models = mysql_fetch_row( $result );         // An array with all of them
    if ( $models !== false )
      $row['modelIDs'] = implode( ", ", $models );

    $query  = "SELECT resultID FROM HPCAnalysisResultData " .
              "WHERE HPCAnalysisResultID = $resultID " .
              "AND HPCAnalysisResultType = 'noise' ";
    $result = mysql_query( $query )
             or die( "Query failed : $query<br />\n" . mysql_error());
    $noise  = mysql_fetch_row( $result );         // An array with all of them
    if ( $noise !== false )
      $row['noiseIDs'] = implode( ", ", $noise );
  }

  $text .= <<<HTML
  <a name='runDetail'></a>
  <table cellspacing='0' cellpadding='0' class='admin'>
  <caption>HPC Result Detail</caption>

HTML;

  foreach ($row as $key => $value)
  {
    $text .= "  <tr><th>$key</th><td>$value</td></tr>\n";
  }

  if ( $queue_msgs !== false )
  {
    $linkmsg = "<a href='{$_SERVER[ 'PHP_SELF' ]}?RequestID=$requestID&msgs=t#runDetail'>Length Messages</a>";

    $text .= "  <tr><th>$linkmsg</th><td>$len_msgs</td></tr>\n";
    if ( isset( $_GET[ 'msgs' ] ) ) 
      $text .= "  <tr><th>Queue Messages</th><td>$queue_msgs</td></tr>\n";
  }

  $text .= "</table>\n";

  return $text;
}

?>
