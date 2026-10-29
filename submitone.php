<?php

# begin to build up data in replacement of queue_setup_1,2,3.php in prep for 2DSA_? submit

$self = __FILE__;

$notes = <<<__EOD
usage: $self db ID

1. finds db.autoflowAnalysis with requestID = ID
2. finds associated db.analysisprofile
3. loads any related tables needed to build up \$_SESSION
4. converts XML -> JSON to setup the job

__EOD;

if ( count( $argv ) != 3 ) {
    echo $notes;
    exit;
}

$us3bin = exec( "ls -d ~us3/lims/bin" );
require_once __DIR__ . '/gridctl_bootstrap.php';
require_once __DIR__ . '/pick_cluster.php';
require_once __DIR__ . '/pick_cluster_reserve.php';
## db_connection_pool.php is required lazily, right before the one place
## below that actually uses it (inside the metascheduler pseudo-cluster
## branch), not here. Round-12 blocker, gridctl#35: common#24/#29 never
## shipped this file at all -- requiring it unconditionally here killed
## every autoflow submission outright (dies before the row can even be
## marked, no statusMsg, no mail), including on a host with the
## metascheduler entirely turned off, where this file is never needed.

## Pulled into its own function, not a bare top-level statement, so a test
## driven through gridctl_extract_functions() (tokenizer-based, pulls only
## function bodies -- see tests/support/gridctl/extract-functions.php) can
## call this directly to simulate "ran before anything else touches
## $admin_email", the same as this script's own top-level call to it does.
##
## $admin_email itself gets overwritten later, once the job's own instance
## config.php loads ($php_base below), with that instance's contact
## address, not the site admin's. mail_submission_fail_to_admin() below
## reads this dedicated copy instead of the raw global, so every failure
## mail goes to the site admin regardless of when in the run it fires, not
## only the ones that happen to run before the instance config loads.
function capture_site_admin_email() {
    global $admin_email, $site_admin_email;
    $site_admin_email = $admin_email ?? null;
}
capture_site_admin_email();

# ********* start user defines *************

$poll_sleep_seconds = 30;

$logging_level = 3;

$dumpfilebase = "/home/us3/lims/etc/submit";
if ( !is_dir( $dumpfilebase ) ) {
    mkdir( $dumpfilebase );
}

# www_uslims3 — use value from listen-config.php if set, otherwise production default
$www_uslims3 = isset( $www_uslims3 ) ? $www_uslims3 : "/srv/www/htdocs/uslims3";

# ********* end user defines ***************

# ********* start admin defines *************
$db                                = "gfac";
$submit_request_table_name         = "autoflowAnalysis";
$submit_request_history_table_name = "autoflowAnalysisHistory";
$id_field                          = "requestID";
$processing_key                    = "submitted";
# ********* end admin defines ***************

$cli_errors = [];

function quote_fix( $str ) {
    return str_replace( "'", "*", $str );
}

function check_cli_errors() {
    global $cli_errors;
    if ( count( $cli_errors ) ) {
        echo implode( "\n", $cli_errors ) . "\n";
        fail_job();
        exit(-1);
    }
}

## The pool members this instance enables in its cluster_config.php: the only ones the driven
## page's own pick (require_cluster.php, through dbinst's instance-filtered cluster list) can choose.
function instance_pool_candidates( $members, $cluster_configuration ) {
    return array_values( array_filter( $members, function ( $member ) use ( $cluster_configuration ) {
        return isset( $cluster_configuration[ $member ] ) && is_array( $cluster_configuration[ $member ] )
            && !empty( $cluster_configuration[ $member ][ 'active' ] );
    } ) );
}

## The included web pages (e.g. 2DSA_2.php) don't push job submission
## failures into $cli_errors themselves - they only echo an ERROR:
## line into the dumped output. Scan the dump for that and fold any
## such lines into $cli_errors so check_cli_errors() catches them too.
function check_cli_errors_in_dump() {
    global $dumpfile;
    global $cli_errors;

    if ( ! file_exists( $dumpfile ) ) {
        return;
    }

    $dump = file_get_contents( $dumpfile );
    if ( preg_match_all( '/^.*ERROR:.*$/m', $dump, $matches ) ) {
        ## common prints pool-definition findings as WARNING:, so only an ERROR: fails the stage
        foreach ( $matches[ 0 ] as $line ) {
            $cli_errors[] = $line;
        }
    }
}

## The Reason field has no guard against
## whatever an included page's $cli_errors/error_get_last() text happens
## to contain -- an included web page's own fatal can leave HTML tags or
## a print_r()/var_export() dump of a response structure in there, which
## then goes out verbatim as a several-hundred-character unreadable blob.
## Pure (no mail/global access) so it is testable on strings alone, same
## pattern as every other decision function in this file.
## Round 15 nit, gridctl#33: three more things an included page's own
## dump can leave in here besides HTML tags -- a print_r()-style array
## index marker ("[0] => ", left behind when something upstream dumped an
## array instead of joining it), the identical error duplicated (a direct
## call's own $reason and the dump-scanned copy of that exact same line,
## folded into one joined string by check_cli_errors_in_dump()), and a
## byte-count truncation that can split a multi-byte UTF-8 character in
## two, leaving invalid UTF-8 in the mail body.
function format_mail_reason( $reason, $max_length = 500 ) {
    $text = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $reason ) ) );
    $text = preg_replace( '/\[\d+\]\s*=>\s*/', '', $text );
    $text = implode( '; ', array_unique( array_filter( array_map( 'trim', explode( ';', $text ) ) ) ) );
    if ( strlen( $text ) > $max_length ) {
        $text = mb_strcut( $text, 0, $max_length, 'UTF-8' ) . '... (truncated)';
    }
    return $text;
}

## Neither fail_job() nor error() ever told anyone a submission failed --
## both only ever wrote statusMsg to the DB (round-13 should fix, dbinst#57:
## "a refused submission ... tells no one"). $org_name/$admin_email/
## $servhost all come from listen-config.php, same as mail_to_admin()
## (jobmonitor/gridctl.php) already reads for a running job's own failure
## mail -- that function isn't reused directly here since its message
## format is written for a job already mid-poll ($updateTime/$status/
## $cluster), not a submission that never got that far.
## Round 15 should-fix, gridctl#33: a breaker trip, an sbatch rejection or
## an "outcome unknown" result sent 2-3 of these for the one failure --
## fail_job()/error() mail explicitly and then either exit() or fall
## through to the script's natural end, both of which still run
## submitone_shutdown_handler() (a registered shutdown function always
## runs), and that handler mails again itself whenever it finds the row's
## status still isn't FAILED/dealt with by the time it re-checks (a status
## UPDATE that raced, failed, or simply hadn't committed yet). Each call
## site reasoned about its own single path, not about every OTHER path
## that can also reach a mail for the exact same request. A per-request
## sent-once guard here, not another status check at each call site, is
## what actually guarantees "exactly one," regardless of which path (or
## combination of paths) gets there.
$submission_fail_mail_sent = [];
function mail_submission_fail_to_admin( $reason ) {
    global $org_name;
    global $site_admin_email;
    global $servhost;
    global $lims_db;
    global $ID;
    global $submission_fail_mail_sent;

    if ( !isset( $site_admin_email ) || $site_admin_email === '' ) {
        return;
    }

    if ( !empty( $submission_fail_mail_sent[ $ID ] ) ) {
        return;
    }
    $submission_fail_mail_sent[ $ID ] = true;

    $headers  = "From: $org_name Admin<$site_admin_email>"  . "\n";
    $headers .= "Reply-To: $org_name<$site_admin_email>"    . "\n";
    $headers .= "X-Mailer: PHP v" . phpversion()        . "\n";
    $headers .= "MIME-Version: 1.0"                     . "\n";
    $headers .= "Content-Transfer-Encoding: 8bit"       . "\n";

    $subject = "US3 Submission Failure Notification";
    $message = "UltraScan job submission failed on $servhost:\n\n"
             . "Database       :  $lims_db\n"
             . "Request ID     :  $ID\n"
             . "Reason         :  " . format_mail_reason( $reason ) . "\n";

    mail( $site_admin_email, $subject, $message, $headers );
}

function fail_job() {
    global $lims_db;
    global $cli_errors;
    global $submit_request_table_name;
    global $id_field;
    global $ID;
    global $db_handle;
    global $self;

    $use_cli_errors = preg_replace( '/ERROR: \S*\.php /', 'ERROR: ', $cli_errors );
    $joined_errors  = implode( '; ', quote_fix( $use_cli_errors ) );

    $query  = "UPDATE {$lims_db}.{$submit_request_table_name} SET status='FAILED', statusMsg='$joined_errors' WHERE {$id_field} = {$ID}";
    $result = mysqli_query( $db_handle, $query );

    if ( !$result ) {
        write_logl( "$self: error updating table {$submit_request_table_name} {$id_field} {$ID} status FAILED. query $query", 0 );
    }

    mail_submission_fail_to_admin( $joined_errors );
}

function write_logl( $msg, $this_level = 0 ) {
    global $logging_level;
    global $self;
    if ( $logging_level >= $this_level ) {
        write_log( "{$self}: " . $msg );
    }
}

function error( $msg, $fail_job = true ) {
    write_logl( $msg );

    global $lims_db;
    global $submit_request_table_name;
    global $id_field;
    global $ID;
    global $db_handle;
    global $self;

    if ( $fail_job ) {
        $qfmsg  = quote_fix( $msg );
        $query  = "UPDATE {$lims_db}.{$submit_request_table_name} SET status='FAILED', statusMsg='Error submitting job: $qfmsg' WHERE {$id_field} = {$ID}";
        $result = mysqli_query( $db_handle, $query );

        if ( !$result ) {
            write_logl( "$self: error updating table {$submit_request_table_name} {$id_field} {$ID} status FAILED. query $query", 0 );
        }

        mail_submission_fail_to_admin( "Error submitting job: $msg" );
    }
    exit(-1);
}

if ( !function_exists( 'debug_json' ) ) {
    function debug_json( $msg, $json ) {
        return;
    }
}

function db_obj_result( $db_handle, $query ) {
    $result = mysqli_query( $db_handle, $query );

    if ( !$result || !$result->num_rows ) {
        write_logl( "db query failed : $query" );
        exit;
    }

    if ( $result->num_rows > 1 ) {
        write_logl( "WARNING: db query returned " . $result->num_rows . " rows : $query" );
    }

    return mysqli_fetch_object( $result );
}

function truestr( $val ) {
    return $val ? "true" : "false";
}

$lims_db = $argv[ 1 ];
$ID      = $argv[ 2 ];

## The included web pages read their instance from LIMS_INSTANCE (via
## uslims3/config.php, which exits without it). submitctl.php serves every
## instance from one process and cannot set it, so publish $lims_db here.
if ( getenv( 'LIMS_INSTANCE' ) !== $lims_db ) {
    putenv( "LIMS_INSTANCE=$lims_db" );
}
$_SERVER[ 'LIMS_INSTANCE' ] = $lims_db;

if ( !is_dir( $dumpfilebase ) ) {
    write_logl( "ERROR $dumpfilebase is not a directory, logs will not be stored!\n" );
}

$dumpfile = "{$dumpfilebase}/$lims_db-$ID.txt";
global $dumpfile;
if ( file_exists( $dumpfile ) ) {
    unlink( $dumpfile );
}

write_logl( "Starting" );
// Process-start receipt on stderr (submitctl's submit.log), even when logging
// goes to a file, so a child that never started is distinguishable.
fwrite( STDERR, "$self: starting submission for $lims_db request $ID\n" );

do {
    $db_handle = mysqli_connect( $dbhost, $user, $passwd, $db );
    if ( !$db_handle ) {
        write_logl( "could not connect to mysql: $dbhost, $user, $db. Will retry in {$poll_sleep_seconds}s" );
        sleep( $poll_sleep_seconds );
    }
} while ( !$db_handle );

write_logl( "connected to mysql: $dbhost, $user, $db.", 2 );

## The included web pages can fatal or exit() without reaching our error
## handlers, and submitctl.php never times out a READY row. If we shut down
## with the row still READY, the submission did not happen: fail it.
##
## A named function, not an inline closure:
## gridctl_extract_functions() (the test-support tokenizer this file's own
## tests already depend on) deliberately skips an anonymous closure -- it
## has no name to extract a standalone function under -- so the inline
## version could never be driven directly in a test at all.
function submitone_shutdown_handler() {
    global $db_handle, $lims_db, $submit_request_table_name, $id_field, $ID, $self;

    if ( ! $db_handle ) {
        return;
    }

    if ( ! mysqli_select_db( $db_handle, $lims_db ) ) {
        return;
    }

    $request_id = (int) $ID;
    $statement  = mysqli_prepare( $db_handle,
        "SELECT status FROM autoflowAnalysis WHERE requestID = ?" );
    if ( ! $statement ) {
        return;
    }
    mysqli_stmt_bind_param( $statement, 'i', $request_id );
    if ( ! mysqli_stmt_execute( $statement ) ) {
        mysqli_stmt_close( $statement );
        return;
    }

    $result = mysqli_stmt_get_result( $statement );
    if ( ! $result || ! ( $row = mysqli_fetch_object( $result ) ) ) {
        mysqli_stmt_close( $statement );
        return;
    }
    mysqli_stmt_close( $statement );
    ## SUBMIT_TIMEOUT (from markAutoflowSubmitFailed) also has no Slurm job, and
    ## submitctl reads it as still in progress, so it is failed here the same way.
    $status = strtoupper( $row->{'status'} );
    if ( $status !== 'READY' && $status !== 'SUBMIT_TIMEOUT' ) {
        return;
    }

    ## A page this script include()s (e.g.
    ## require_authorized_cluster(), lib/utility.php) can exit() with its
    ## own specific, already-known reason for refusing -- $gridctl_
    ## submission_failure_reason, when set, is that exact reason, checked
    ## before falling back to PHP's own error_get_last(), which only ever
    ## reports an unrelated incidental warning/notice (confirmed: "Undefined
    ## index: authenticatePAM" from links.php on a PAM+GMP site), never the
    ## actual refusal.
    global $gridctl_submission_failure_reason;
    if ( isset( $gridctl_submission_failure_reason ) && $gridctl_submission_failure_reason !== '' ) {
        $msg = "submitone.php aborted: $gridctl_submission_failure_reason";
    } else {
        $last = error_get_last();
        $msg  = $last === null
                ? "submitone.php exited without submitting the job"
                : "submitone.php aborted: " . $last[ 'message' ];
    }

    write_logl( "$self: {$id_field} {$ID} still $status at shutdown, marking FAILED: $msg", 0 );

    $statement = mysqli_prepare( $db_handle,
        "UPDATE autoflowAnalysis SET status = 'FAILED', statusMsg = ? WHERE requestID = ?" );
    if ( ! $statement ) {
        return;
    }
    mysqli_stmt_bind_param( $statement, 'si', $msg, $request_id );
    mysqli_stmt_execute( $statement );
    mysqli_stmt_close( $statement );

    ## A refused cluster, a page fatal,
    ## or any other exit through this shutdown handler used to only ever
    ## write $msg to statusMsg -- nothing here ever mailed anyone, unlike
    ## fail_job()/error() below, which already call mail_submission_fail_
    ## to_admin(). This is the one remaining choke point every such exit
    ## passes through, so it is the one place that has to mail, not one
    ## more path that bypasses it.
    mail_submission_fail_to_admin( $msg );
}

register_shutdown_function( 'submitone_shutdown_handler' );

$autoflowanalysis = db_obj_result( $db_handle,
    "SELECT clusterDefault, tripleName, filename, invID, aprofileGUID, statusJson FROM {$lims_db}.{$submit_request_table_name} WHERE {$id_field}=$ID" );

$cluster    = $autoflowanalysis->{'clusterDefault'};
$statusJson = json_decode( $autoflowanalysis->{"statusJson"} );
debug_json( "after fetch, decode", $statusJson );

if ( !isset( $statusJson->{ $processing_key } ) ||
     empty( $statusJson->{ $processing_key } ) ) {
    write_logl( "AutoflowAnalysis db {$lims_db} {$id_field} $ID is NOT {$processing_key}", 1 );
    exit;
}

$stage  = $statusJson->{ $processing_key };
$triple = str_replace( ".Interference", ".660", $autoflowanalysis->{ 'tripleName' } );
$invID  = $autoflowanalysis->{ 'invID' };

write_logl( "job $ID found. stage to submit " . json_encode( $stage, JSON_PRETTY_PRINT ) );

$aprofileguid = $autoflowanalysis->{ 'aprofileGUID' };

$analysisprofile = db_obj_result( $db_handle,
    "SELECT * FROM {$lims_db}.analysisprofile WHERE aprofileGUID='{$aprofileguid}'" );

write_logl( "aprofileGUID $aprofileguid found", 3 );

$xmljson = json_decode( json_encode( simplexml_load_string( $analysisprofile->{ 'xml' } ) ) );

debug_json( "analysisprofile's xml in json:", $xmljson );

$xmljsonfilename = $xmljson->{ 'analysis_profile' }->{ '@attributes' }->{ 'name' };
$xmljsonguid     = $xmljson->{ 'analysis_profile' }->{ '@attributes' }->{ 'guid' };
$aprofilename    = $analysisprofile->{'name'};
$aaname          = $autoflowanalysis->{'filename'};

if ( $xmljsonfilename != $aprofilename ||
     $xmljsonfilename != $aaname ) {
    write_logl( "table name inconsistencies $xmljsonfilename vs $aprofilename vs $aaname" );
}

write_logl( "analysisprofile filename $aaname triplename $triple" );

$rawdata = db_obj_result( $db_handle,
    "select rawDataID, filename from {$lims_db}.rawData where filename like '{$aaname}.%.{$triple}%'" );

echo json_encode( $rawdata, JSON_PRETTY_PRINT ) . "\n";

$editdata = db_obj_result( $db_handle,
    "select editedDataID, filename from {$lims_db}.editedData where filename like '{$aaname}.%.{$triple}%'" );

echo json_encode( $editdata, JSON_PRETTY_PRINT ) . "\n";

$person = db_obj_result( $db_handle,
    "select personID, personGUID, fname, lname, phone, email, userlevel, advancelevel, clusterAuthorizations " .
    "from {$lims_db}.people where personID='{$invID}'" );

echo json_encode( $person, JSON_PRETTY_PRINT ) . "\n";

$clusterAuth = explode( ":", $person->{'clusterAuthorizations'} );

echo "personid:" . $person->{'personID'} . "\n";

$php_base = "{$www_uslims3}/{$lims_db}";
chdir( $php_base );

set_include_path( get_include_path() . PATH_SEPARATOR . $php_base );

$person->{'userlevel'} = 2;
$autoflow_admin_email        = $admin_email;   ## from listen-config.php
$person->{'email'}           = $autoflow_admin_email;
$person->{'submitter_email'} = $autoflow_admin_email;

# ************* queue_setup_1 ***************

$php_queue_setup_1 = "{$php_base}/queue_setup_1.php";
$php_queue_setup_2 = "{$php_base}/queue_setup_2.php";
$php_queue_setup_3 = "{$php_base}/queue_setup_3.php";
echo "preparing to call $php_queue_setup_1\n";

## Resolve cluster, host_name, and queue before building $_SESSION
## so $_SESSION['gwhostid'] is populated with the actual host name.

$queue = "batch";

$global_config_file = $class_dir . "../global_config.php";
try {
    include_once( $global_config_file );
} catch ( Exception $e ) {
    echo "ERROR: including $global_config_file " . $e->getMessage() . "\n";
}

## Cluster errors are raised after the interactive check, since a WAIT stage needs no cluster.
$cluster_error = null;
if ( $cluster == "localhost" ) {
    ## $default_local_cluster in global_config.php names the LIMS host's cluster.
    $cluster = null;
    if ( isset( $default_local_cluster )
         && isset( $cluster_details[ $default_local_cluster ] ) ) {
        $cluster = $default_local_cluster;
    }
    if ( $cluster === null ) {
        $cluster_error = "cluster 'localhost' requires \$default_local_cluster to be set"
               . " to a cluster present in \$cluster_details;"
               . " run php ~us3/lims/database/utils/uslims_upgrade.php";
    }
    if ( isset( $cluster_details[$cluster] ) ) {
        $host_name = $cluster_details[$cluster]['name'];
        $queue     = $cluster_details[$cluster]['queue'] ?? 'batch';
    }
    echo "localhost cluster resolved to $cluster host_name $host_name queue $queue\n";
} elseif ( isset( $cluster_details )
           && is_array( $cluster_details )
           && array_key_exists( $cluster, $cluster_details )
           && pick_cluster_is_metascheduler_entry( $cluster_details[ $cluster ] ) ) {
    ## $cluster names a metascheduler pseudo-entry (a "clusters" list to pick
    ## a real cluster from), not a real cluster. $cluster itself is left
    ## unchanged below -- it still becomes the stored gfac.analysis.cluster
    ## value exactly as it does for a named cluster today (see
    ## METASCHEDULER-DESIGN.md section 3); only host_name/queue are resolved
    ## from the cluster pick_cluster() actually chose.
    $metascheduler_pseudo_cluster = $cluster;

    ## Required here, not at the top of this file: this is the only branch
    ## that actually uses db_connection_pool, so a host with no metascheduler
    ## pseudo-cluster entry configured at all never needs it -- and a common
    ## checkout that predates this file (common#24/#29, before common#33's
    ## consolidation) can still run every other submission normally.
    $db_connection_pool_file = $class_dir . 'db_connection_pool.php';
    $db_connection_pool_available = class_exists( 'db_connection_pool', false );
    if ( ! $db_connection_pool_available && is_file( $db_connection_pool_file ) ) {
        require_once $db_connection_pool_file;
        $db_connection_pool_available = true;
    }

    ## Approximated from the analysis profile's own XML, already decoded
    ## into $xmljson above -- real sizing (jobsubmit.php's own
    ## tasks_per_group_plan()) cannot run yet, since it needs the real
    ## cluster's own ppbj/ppn, not resolved until after this pick. Stashed
    ## into $_SESSION (not $_SESSION['cluster'], which the driven _1.php
    ## page below unconditionally overwrites) so require_cluster.php -- the
    ## one authoritative, lock-protected pick for both this autoflow path
    ## and an interactive submission -- can read it back once it runs
    ## inside the driven _2.php page, in this same PHP process.
    ## The instance's cluster_config.php, found the way common's jobsubmit finds it, so this preview
    ## picks from the same members the driven page will.
    foreach ( array( "$php_base/cluster_config.php", "$www_uslims3/cluster_config.php",
                     "$www_uslims3/uslims3_newlims/cluster_config.php" ) as $instance_cluster_config ) {
        if ( is_file( $instance_cluster_config ) ) {
            include $instance_cluster_config;
            break;
        }
    }
    $pool_candidates = isset( $cluster_configuration ) && is_array( $cluster_configuration )
        ? instance_pool_candidates( $cluster_details[ $metascheduler_pseudo_cluster ][ 'clusters' ], $cluster_configuration )
        : $cluster_details[ $metascheduler_pseudo_cluster ][ 'clusters' ];

    $job_requirements_estimate = pick_cluster_estimate_job_requirements(
        $stage, $xmljson, $stage === 'PCSA' ? 'p_pcsa' : 'p_2dsa' );

    ## Kill switch: flipping the pseudo-entry's own 'active' to false must
    ## stop this resolution outright, not just hide it from the UI's cluster
    ## list (collect_config_info() filtering). Without this check, an
    ## autoflowAnalysis row whose clusterDefault was already set to this
    ## pseudo-name before it was disabled would still reach pick_cluster()
    ## here and keep resolving to a real cluster -- 'active' only stopped new
    ## selections, not jobs already pointed at it.
    if ( array_key_exists( 'active', $cluster_details[ $metascheduler_pseudo_cluster ] )
         && ! $cluster_details[ $metascheduler_pseudo_cluster ][ 'active' ] ) {
        $pick = array( 'chosen' => null,
            'reason' => "metascheduler '$metascheduler_pseudo_cluster' is disabled (active=false)",
            'tier' => null, 'scores' => array() );
    } elseif ( ! $db_connection_pool_available ) {
        write_logl( "submitone.php: metascheduler pseudo-cluster"
                   . " '$metascheduler_pseudo_cluster' posted, but $db_connection_pool_file"
                   . " is missing (common checkout predates it) -- cannot pool this cluster" );
        $pick = array( 'chosen' => null,
            'reason' => 'db_connection_pool.php is not installed on this host',
            'tier' => null, 'scores' => array() );
    } else {
        $gfac_link = db_connection_pool::shared()->checkout( $dbhost, $guser, $gpasswd, $gDB );

        if ( ! $gfac_link ) {
            ## No status data is learnable without this connection: every
            ## candidate reads as ineligible and pick_cluster() reports
            ## "no eligible cluster" rather than guessing -- never treat a
            ## failed connection here as permission to pick blindly.
            $pick = array( 'chosen' => null, 'reason' => 'could not connect to gfac for cluster status',
                'tier' => null, 'scores' => array() );
        } else {
            try {
                ## pick_cluster(), not pick_cluster_reserve(): $cluster itself
                ## stays the pseudo name below (see comment above), so the
                ## simulated $_POST this script builds still names the pseudo
                ## pool, not a real cluster -- the driven _1.php/_2.php pages
                ## re-detect it as unresolved and require_cluster.php makes the
                ## one authoritative, lock-protected, logged reservation when
                ## they run (same as an interactive submission). Resolving here
                ## too, with pick_cluster_reserve(), would reserve and log a
                ## second, discarded decision every autoflow job -- this call is
                ## read-only and only feeds the host_name/queue shown below for
                ## display/record purposes.
                $snapshot = pick_cluster_status_snapshot( $gfac_link, $pool_candidates );
                $pick = pick_cluster(
                    $pool_candidates,
                    $cluster_details,
                    $snapshot,
                    $job_requirements_estimate,
                    array(),
                    null,
                    ## $clusterAuth (resolved above from {$lims_db}.people for this
                    ## job's owning person, $invID) -- being allowed to select the
                    ## metascheduler pseudo-entry is never authorization for every
                    ## real cluster behind it, so every candidate is checked against
                    ## this same per-person allowlist a direct submission would be.
                    $clusterAuth
                );
            } finally {
                db_connection_pool::shared()->release( $gfac_link );
            }
        }
    }

    write_logl( pick_cluster_log_line( $metascheduler_pseudo_cluster, $pick ) );

    if ( $pick[ 'chosen' ] === null ) {
        $cluster_error = "metascheduler '$metascheduler_pseudo_cluster': " . $pick[ 'reason' ];
    } else {
        $real = $pick[ 'chosen' ];
        $host_name = $cluster_details[ $real ][ 'name' ];
        if ( array_key_exists( 'queue', $cluster_details[ $real ] ) ) {
            $queue = $cluster_details[ $real ][ 'queue' ];
        }
        echo "metascheduler $metascheduler_pseudo_cluster resolved to $real"
             . " (reason={$pick['reason']}) host_name=$host_name queue=$queue\n";
    }
} else {
    if ( isset( $cluster_details )
         && is_array( $cluster_details )
         && array_key_exists( $cluster, $cluster_details )
         && array_key_exists( 'name', $cluster_details[ $cluster ] ) ) {
        $host_name = $cluster_details[ $cluster ][ 'name' ];
        if ( array_key_exists( 'queue', $cluster_details[ $cluster ] ) ) {
            $queue = $cluster_details[ $cluster ][ 'queue' ];
        }
        echo "cluster $cluster host_name $host_name queue $queue\n";
    } else {
        $cluster_error = "cluster '$cluster' not found or missing 'name' in cluster_details";
    }
}

$_SESSION = [];

$_SESSION[ 'id' ]              = $person->{'personID'};
$_SESSION[ 'loginID' ]         = $person->{'personID'};
$_SESSION[ 'firstname' ]       = $person->{'fname'};
$_SESSION[ 'lastname' ]        = $person->{'lname'};
$_SESSION[ 'phone' ]           = $person->{'phone'};
$_SESSION[ 'email' ]           = $person->{'email'};
$_SESSION[ 'submitter_email' ] = $person->{'email'};
$_SESSION[ 'userlevel' ]       = $person->{'userlevel'};
$_SESSION[ 'instance' ]        = $lims_db;
$_SESSION[ 'user_id' ]         = $person->{'fname'} . "_" . $person->{'lname'} . "_" . $person->{'personGUID'};
$_SESSION[ 'advancelevel' ]    = $person->{'advancelevel'};
$_SESSION[ 'clusterAuth' ]     = $clusterAuth;
$_SESSION[ 'gwhostid' ]        = $host_name;

## Read back by require_cluster.php, if set above -- a top-level key, not
## $_SESSION['cluster'], which the driven _1.php page below (2DSA_1.php,
## PCSA_1.php, ...) unconditionally reinitializes to array() before setting
## its own 'name'/'shortname'/'queue' keys, which would wipe this if it were
## nested there instead. Unset for a direct (non-metascheduler) submission,
## in which case require_cluster.php never looks for it at all.
if ( isset( $job_requirements_estimate ) ) {
    $_SESSION[ 'metascheduler_job_requirements_estimate' ] = $job_requirements_estimate;
}

echo "session now is:\n" . json_encode( $_SESSION, JSON_PRETTY_PRINT ) . "\n";

$_REQUEST = [];
$_REQUEST[ 'submitter_email' ] = $person->{'email'};
$_REQUEST[ 'expIDs' ]          = [ $rawdata->{'rawDataID'} ];
$_REQUEST[ 'cells' ]           = [ $rawdata->{'rawDataID'} . ":" . $rawdata->{ 'filename' } ];
$_REQUEST[ 'next' ]            = "Add to Queue";

$_POST = $_REQUEST;

echo "request/post now is:\n" . json_encode( $_REQUEST, JSON_PRETTY_PRINT ) . "\n";

function dump_it( $str ) {
   global $dumpfile;
   file_put_contents( $dumpfile, $str, FILE_APPEND );
   chmod( $dumpfile, 0640 );
   return true;
}

ob_start( "dump_it" );
include( $php_queue_setup_1 );
while (ob_get_level()) ob_end_flush();
check_cli_errors();

# ************* queue_setup_2 ***************

$_REQUEST[ 'save' ] = 'Save Queue Information';
$_POST = $_REQUEST;

echo "preparing to call $php_queue_setup_2\n";
echo "session now is:\n" . json_encode( $_SESSION, JSON_PRETTY_PRINT ) . "\n";
echo "request/post now is:\n" . json_encode( $_REQUEST, JSON_PRETTY_PRINT ) . "\n";
ob_start( "dump_it" );
include( $php_queue_setup_2 );
while (ob_get_level()) ob_end_flush();
check_cli_errors();

echo "queue is prepared\n";

# ************* process xml and determine job type and parameters ***************

if ( $stage == "PCSA" ) {
    $paramgroup = "p_pcsa";
} else {
    $paramgroup = "p_2dsa";
}

$jobkey = "job_" . strtolower( $stage );
$job_attributes = (object) [];

if ( !isset( $xmljson->{'analysis_profile'} ) ) {
    error( "analysis profile's xml does not contain an 'analysis_profile' key" );
}

if ( !isset( $xmljson->{'analysis_profile'}->{$paramgroup} ) ) {
    error( "analysis profile's xml does not contain an 'analysis_profile'->'$paramgroup' key" );
}

if ( !isset( $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'} ) ) {
    error( "analysis profile's xml does not contain 'analysis_profile'->'channel_parms'" );
}

if ( is_array( $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'} ) &&
     !count( $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'} ) ) {
    error( "analysis profile's xml does not contain an 'analysis_profile'->'channel_parms' key with a nonzero size" );
}

if ( is_array( $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'} ) ) {
    if ( !isset( $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'}[0]->{'@attributes'} ) ) {
        error( "analysis profile's xml's 'analysis_profile'->'$paramgroup'->'channel_parms' first entry does not contain '\@attributes'" );
    }
    $channel_attributes = $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'}[0]->{'@attributes'};
    debug_json( "channel attributes", $channel_attributes );
} else {
    if ( !isset( $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'}->{'@attributes'} ) ) {
        error( "analysis profile's xml's 'analysis_profile'->'$paramgroup'->'channel_parms'  does not contain '\@attributes'" );
    }
    $channel_attributes = $xmljson->{'analysis_profile'}->{$paramgroup}->{'channel_parms'}->{'@attributes'};
    debug_json( "channel attributes", $channel_attributes );
}

if ( $stage != "PCSA" ) {
    if ( !isset( $xmljson->{'analysis_profile'}->{$paramgroup}->{$jobkey} ) ) {
        error( "analysis profile's xml's 'analysis_profile'->'$paramgroup'->'$jobkey' is missing" );
    }
    if ( !isset( $xmljson->{'analysis_profile'}->{$paramgroup}->{$jobkey}->{'@attributes'} ) ) {
        error( "analysis profile's xml's 'analysis_profile'->'$paramgroup'->'$jobkey'->'\@attributes' is missing" );
    }
    $job_attributes = $xmljson->{'analysis_profile'}->{$paramgroup}->{$jobkey}->{'@attributes'};
    debug_json( "job attributes", $job_attributes );

    if ( isset( $job_attributes->{'interactive'} ) ) {
        $query  = "UPDATE {$lims_db}.{$submit_request_table_name} SET status='WAIT', statusMsg='Waiting for manual stage $stage to complete.' WHERE {$id_field} = {$ID}";
        $result = mysqli_query( $db_handle, $query );

        if ( !$result ) {
            write_logl( "$self: error updating table {$submit_request_table_name} {$id_field} {$ID} statusJson. query $query", 0 );
        } else {
            write_logl( "$self: {$submit_request_table_name} now interactive {$id_field} $ID stage " . json_encode( $stage ), 1 );
        }
        exit();
    }
}

if ( $cluster_error !== null ) {
    error( $cluster_error );
}

if ( $stage == "PCSA" ) {
    $conv_pcsa_keys = [
        "channel"              => "_ignore_"
        ,"curve_type"          => "_special_handling_"
        ,"x_type"              => "_special_handling_"
        ,"y_type"              => "_special_handling_"
        ,"z_type"              => "_special_handling_"
        ,"x_min"               => "x_min"
        ,"x_max"               => "x_max"
        ,"y_min"               => "y_min"
        ,"y_max"               => "y_max"
        ,"z_value"             => "z_value"
        ,"variations_count"    => "vars_count"
        ,"gridfit_iterations"  => "gfit_iterations"
        ,"curve_reso_points"   => "curves_points"
        ,"noise"               => "_ignore_"
        ,"regularization"      => "_special_handling_"
        ,"reg_alpha"           => "tikreg_alpha"
        ,"mc_iterations"       => "mc_iterations"
        ];

    $defaults_pcsa = [
        "curve_type"           => "IS"
        ,"solute_type"         => "013.skv"
        ,"x_min"               => "1"
        ,"x_max"               => "10"
        ,"y_min"               => "1"
        ,"y_max"               => "4"
        ,"z_value"             => "0"
        ,"vars_count"          => "10"
        ,"hl_vars_count"       => "100"
        ,"gfit_iterations"     => "3"
        ,"thr_deltr_ratio"     => "0.0001"
        ,"curves_points"       => "200"
        ,"tikreg_option"       => "0"
        ,"tikreg_alpha"        => "0.275"
        ,"mc_iterations"       => "1"
        ,"tinoise_option"      => "0"
        ,"rinoise_option"      => "0"
        ,"debug_level-value"   => "0"
        ,"debug_text-value"    => ""
        ,"simpoints-value"     => "200"
        ,"band_volume-value"   => "0.015"
        ,"radial_grid"         => "0"
        ,"time_grid"           => "1"
        ,"cluster"             => "{$host_name}:{$cluster}:{$queue}"
        ,"TIGRE"               => "Submit"
        ];

    $pcsa_curve_types  = [ "SL", "IS", "DS", "All", "HL", "2O" ];
    $pcsa_solute_types = [
        "s - f/f0 - vbar"  => "013.skv"
        ,"s - mw - vbar"   => "023.swv"
        ,"s - vbar - f/f0" => "031.svk"
        ,"s - vbar - mw"   => "032.svw"
        ,"s - D - vbar"    => "043.sdv"
        ,"f/f0 - s - vbar" => "103.ksv"
        ,"f/f0 - mw - vbar"=> "123.kwv"
        ,"f/f0 - vbar - mw"=> "132.kvw"
        ,"f/f0 - D - vbar" => "143.kdv"
        ,"mw - s - vbar"   => "203.wsv"
        ,"mw - f/f0 - vbar"=> "213.wkv"
        ,"mw - vbar - f/f0"=> "231.wvk"
        ,"mw - D - vbar"   => "243.wdv"
        ,"vbar - s - f/f0" => "301.vsk"
        ,"vbar - s - mw"   => "302.vsw"
        ,"vbar - f/f0 - mw"=> "312.vkv"
        ,"vbar - mw - f/f0"=> "321.vwk"
        ,"vbar - D - f/f0" => "341.vdv"
        ,"vbar - D - mw"   => "342.vdv"
        ,"D - s - vbar"    => "403.dsv"
        ,"D - k - vbar"    => "413.dkv"
        ,"D - mw - vbar"   => "423.dkv"
        ,"D - vbar - f/f0" => "431.dvk"
        ,"D - vbar - mw"   => "432.dvw"
        ];

    $_REQUEST = $defaults_pcsa;

    $all_attributes = (object) array_merge( (array) $channel_attributes, (array) $job_attributes );
    debug_json( "pcsa: all attributes", $all_attributes );

    $solute_types = [];

    foreach ( $all_attributes as $k => $v ) {
        if ( !isset( $conv_pcsa_keys[ $k ] ) ) {
            write_logl( "job attribute '$k' missing in conversion table, ignored" );
            continue;
        }
        $ku = $conv_pcsa_keys[ $k ];
        if ( $ku == '_ignore_' ) {
            continue;
        }
        if ( $ku != '_special_handling_' ) {
            $_REQUEST[ $ku ] = $v;
            continue;
        }
        if ( $k == 'curve_type' ) {
            if ( !in_array( $v, $pcsa_curve_types ) ) {
                error( "pcsa curve_type $v is not supported" );
            }
            $_REQUEST[ $k ] = $v;
            continue;
        }
        if ( $k == 'x_type' ||
             $k == 'y_type' ||
             $k == 'z_type' ) {
            $solute_types[ $k ] = $v;
            continue;
        }
        if ( $k == 'regularization' ) {
            if ( $v == "none" ) {
                $_REQUEST[ 'tikreg_option' ] = 0;
                continue;
            }
            if ( $v == "specified_alpha" ) {
                $_REQUEST[ 'tikreg_option' ] = 1;
                continue;
            }
            if ( $v == "auto_computed_alpha" ) {
                $_REQUEST[ 'tikreg_option' ] = 2;
                continue;
            }
            error( "pcsa: unknown/unsupported regularization option $v" );
        }
        error( "internal error: no special handling code for attribute '$k'" );
    }

    if ( !array_key_exists( "x_type", $solute_types ) ||
         !array_key_exists( "y_type", $solute_types ) ||
         !array_key_exists( "z_type", $solute_types ) ) {
        error( "pcsa: x_type, y_type & z_type are not all defined in the xml" );
    }

    $solute_type =
        $solute_types["x_type"] . " - " .
        $solute_types["y_type"] . " - " .
        $solute_types["z_type"];

    if ( !array_key_exists( $solute_type, $pcsa_solute_types ) ) {
        error( "pcsa: unknown solute_type '$solute_type'" );
    }

    $_REQUEST[ "solute_type" ] = $pcsa_solute_types[ $solute_type ];

    debug_json( "pcsa: \$_REQUEST", $_REQUEST );

    $_POST = $_REQUEST;

    $php_pcsa_1 = "{$php_base}/PCSA_1.php";
    $php_pcsa_2 = "{$php_base}/PCSA_2.php";

    echo "preparing to call $php_pcsa_1\n";
    echo "session now is:\n" . json_encode( $_SESSION, JSON_PRETTY_PRINT ) . "\n";
    echo "request/post now is:\n" . json_encode( $_REQUEST, JSON_PRETTY_PRINT ) . "\n";
    ob_start( "dump_it" );
    include( $php_pcsa_1 );
    while (ob_get_level()) ob_end_flush();
    check_cli_errors_in_dump();
    check_cli_errors();

} else {
    $use_custom_grid = false;

    $conv_2dsa_keys = [
        "s_min"              => "s_value_min"
        ,"s_max"             => "s_value_max"
        ,"s_gridpoints"      => "s_grid_points"
        ,"k_min"             => "ff0_min"
        ,"k_max"             => "ff0_max"
        ,"k_gridpoints"      => "ff0_grid_points"
        ,"max_iterations"    => "_special_handling_"
        ,"mc_iterations"     => "mc_iterations"
        ,"noise"             => "_special_handling_"
        ,"channel"           => "_ignore_"
        ,"run"               => "_ignore_"
        ,"fit_mb_select"     => "fit_mb_select"
        ,"meniscus_range"    => "meniscus_range"
        ,"meniscus_points"   => "meniscus_points"
        ,"custom_grid_guid"  => "_special_handling_"
        ,"vary_vbar"         => "_ignore_"
        ,"constant_ff0"      => "_ignore_"
        ,"fit_range"         => "_ignore_"
        ,"grid_points"       => "_ignore_"
        ];

    $defaults_2dsa = [
        "s_value_min"        => "1",
        "s_value_max"        => "10",
        "s_grid_points"      => "64",
        "ff0_min"            => "1",
        "ff0_max"            => "4",
        "ff0_grid_points"    => "64",
        "mc_iterations"      => "1",
        "tinoise_option"     => "0",
        "rinoise_option"     => "0",
        "fit_mb_select"      => "0",
        "meniscus_range"     => "0.03",
        "meniscus_points"    => "11",
        "iterations_option"  => "0",
        "max_iterations"     => "10",
        "debug_level-value"  => "0",
        "debug_text-value"   => "",
        "simpoints-value"    => "200",
        "band_volume-value"  => "0.015",
        "radial_grid"        => "0",
        "time_grid"          => "1",
        "cluster"            => "{$host_name}:{$cluster}:{$queue}",
        "TIGRE"              => "Submit",
        "uniform_grid"       => "6"
        ];

    $_REQUEST = $defaults_2dsa;

    $all_attributes = (object) array_merge( (array) $channel_attributes, (array) $job_attributes );
    debug_json( "all attributes", $all_attributes );

    foreach ( $all_attributes as $k => $v ) {
        if ( !isset( $conv_2dsa_keys[ $k ] ) ) {
            write_logl( "job attribute '$k' missing in conversion table, ignored" );
            continue;
        }
        $ku = $conv_2dsa_keys[ $k ];
        if ( $ku == '_ignore_' ) {
            continue;
        }
        if ( $ku != '_special_handling_' ) {
            $_REQUEST[ $ku ] = $v;
            continue;
        }
        if ( $k == 'noise' ) {
            if ( $v == "(TI Noise)" ) {
                $_REQUEST[ 'tinoise_option' ] = "1";
                continue;
            }
            if ( $v == "(RI Noise)" ) {
                $_REQUEST[ 'rinoise_option' ] = "1";
                continue;
            }
            if ( $v == "(TI+RI Noise)" ) {
                $_REQUEST[ 'tinoise_option' ] = "1";
                $_REQUEST[ 'rinoise_option' ] = "1";
                continue;
            }
            error( "unknown '$k' value '$v'" );
        }
        if ( $k == 'max_iterations' ) {
            $_REQUEST[ $k ]                  = $v;
            $_REQUEST[ 'iterations_option' ] = "1";
            continue;
        }
        if ( $k == 'custom_grid_guid' ) {
            if ( empty( $v ) ) {
                continue;
            }
            if ( isset( $all_attributes->CG_modelID )
                 && !empty( $all_attributes->CG_modelID ) ) {
                $_REQUEST[ 'CG_modelID' ] = $all_attributes->CG_modelID;
                $use_custom_grid = true;
                continue;
            }
            error( "non empty '$k' is defined, but CG_modelID is not defined or is empty" );
        }
        error( "internal error: no special handling code for attribute '$k'" );
    }

    $_POST = $_REQUEST;

    if ( $use_custom_grid ) {
        $php_2dsa_1 = "{$php_base}/2DSA-CG_1.php";
        $php_2dsa_2 = "{$php_base}/2DSA-CG_2.php";
    } else {
        $php_2dsa_1 = "{$php_base}/2DSA_1.php";
        $php_2dsa_2 = "{$php_base}/2DSA_2.php";
    }

    echo "preparing to call $php_2dsa_1\n";
    echo "session now is:\n" . json_encode( $_SESSION, JSON_PRETTY_PRINT ) . "\n";
    echo "request/post now is:\n" . json_encode( $_REQUEST, JSON_PRETTY_PRINT ) . "\n";
    ob_start( "dump_it" );
    include( $php_2dsa_1 );
    while (ob_get_level()) ob_end_flush();
    check_cli_errors_in_dump();
    check_cli_errors();
}
