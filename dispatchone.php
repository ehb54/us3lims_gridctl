<?php
/*
 * dispatchone.php <db> <dispatchRequestID> <claim generation> <claim owner>
 *
 * One dispatch attempt for a request submitctl.php has claimed. Reads the pool
 * and instance configuration fresh, so a pool's active flag and policy apply
 * without a restart, then selects, stages and submits through
 * dispatch_attempt_run() with the existing submit_slurm code.
 */

$self = __FILE__;

if ( count( $argv ) != 5 ) {
    fwrite( STDERR, "usage: $self db dispatchRequestID generation owner\n" );
    exit( 1 );
}
list( , $lims_db, $dispatch_id, $generation, $owner ) = $argv;
if ( ! preg_match( '/^uslims3_[A-Za-z0-9_]+$/', $lims_db ) ) {
    fwrite( STDERR, "$self: '$lims_db' is not an instance database\n" );
    exit( 1 );
}

$us3bin = exec( "ls -d ~us3/lims/bin" );
require_once __DIR__ . '/gridctl_bootstrap.php';
gridctl_require_not_root( __FILE__, 'run', implode( ' ', array_map( 'escapeshellarg', array_slice( $argv, 1 ) ) ) );

## Named apart from the instance code included below, which opens and closes its own $gfac_link.
$dispatch_gdb  = 'gfac';
$dispatch_link = mysqli_init();
## Reopens the same object, so what already holds it keeps working after a database restart.
$dispatch_connect = function () use ( $dispatch_link, $dbhost, $user, $passwd, $dispatch_gdb ) {
    try {
        return @mysqli_real_connect( $dispatch_link, $dbhost, $user, $passwd, $dispatch_gdb );
    }
    catch ( mysqli_sql_exception $e ) {
        return false;
    }
};
if ( ! $dispatch_connect() ) {
    fwrite( STDERR, "$self: could not connect to $dbhost as $user; the claim expires and is retried\n" );
    exit( 1 );
}

## listen-config.php's host address, before the instance configuration sets its own $admin_email.
$listen_admin_email = isset( $admin_email ) ? $admin_email : '';

## The instance's own configuration, as the pages submitone.php includes see it.
$www_uslims3 = isset( $www_uslims3 ) ? $www_uslims3 : "/srv/www/htdocs/uslims3";
putenv( "LIMS_INSTANCE=$lims_db" );
$_SERVER[ 'LIMS_INSTANCE' ] = $lims_db;
$php_base = "{$www_uslims3}/{$lims_db}";
chdir( $php_base );
set_include_path( get_include_path() . PATH_SEPARATOR . $php_base );
include_once "$php_base/config.php";
include_once "$php_base/lib/utility.php";
include_once "$php_base/lib/motd.php";
mysqli_report( MYSQLI_REPORT_OFF );

require_once $class_dir . 'dispatch_ledger.php';
require_once $class_dir . 'dispatch_alerts.php';
require_once $class_dir . 'dispatch_authorization.php';
require_once $class_dir . 'slurm_assessment.php';
require_once $class_dir . 'remote_exec.php';
require_once $class_dir . 'submit_slurm.php';
require_once __DIR__ . '/dispatch_worker.php';
require_once __DIR__ . '/cluster_probe.php';

$req = dispatch_get( $dispatch_link, $lims_db, $dispatch_id );
if ( ! $req ) {
    exit( 0 );
}

## markAutoflowSubmitFailed() reports against the autoflow stage, if any.
$ID     = (int) $req[ 'autoflowAnalysisID' ];
$is_cli = true;

## A request's submitter, loaded from its received input after checking it is
## the input intake recorded; an error message otherwise.
$open_input = function ( array $row ) {
    $xml = $row[ 'inputRef' ];
    if ( $xml === '' || ! is_readable( $xml ) ) {
        return "input $xml is not readable";
    }
    if ( $row[ 'inputChecksum' ] !== '' && hash_file( 'sha256', $xml ) !== $row[ 'inputChecksum' ] ) {
        return "input $xml no longer matches its recorded checksum";
    }
    ## Constructed in the instance directory, where its configuration lookup is relative.
    $loaded = new submit_slurm();
    $cwd = getcwd();
    chdir( dirname( $xml ) );
    $loaded->clear();
    $loaded->parse_input( basename( $xml ) );
    chdir( $cwd );
    return $loaded;
};

$job = null;
$load_job = function () use ( $req, &$job, $open_input ) {
    if ( $job === null ) {
        $job = $open_input( $req );
    }
    return $job;
};

$io = array(
    'cluster_details' => is_array( $global_cluster_details ) ? $global_cluster_details : array(),
    ## read for each attempt, so putting a cluster back takes effect on the next one
    'operator'        => dispatch_operator_overrides( "$www_uslims3/down_clusters.php" ),
    'configured'      => array_map( function ( $c ) { return $c->short_name; }, is_array( $clusters ) ? $clusters : array() ),
    'plans' => function ( array $members, array $request ) use ( $load_job ) {
        $loaded = $load_job();
        $plans = array();
        foreach ( $members as $m ) {
            $plans[ $m ] = is_string( $loaded )
                ? array( 'ok' => false, 'cluster' => $m, 'error' => $loaded, 'input' => true )
                : $loaded->candidate_plan( $m, array( 'priority_directive' => (string) $request[ 'priorityDirective' ] ) );
        }
        return $plans;
    },
    'assess' => function ( array $plans, $allowance ) use ( $global_cluster_details ) {
        $run = function ( $cluster, $command, array $opts ) use ( $global_cluster_details ) {
            $rx = new remote_exec( $cluster, $global_cluster_details, 'elog2' );
            return $rx->run( $command, $opts );
        };
        return slurm_assess_candidates( $run, $plans, array( 'allowance' => $allowance ) );
    },
    'stage' => function ( array $plan ) use ( $load_job ) {
        return $load_job()->stage_planned( $plan );
    },
    ## Renews the claim from a separate process while the staging copy runs; returns its stop function.
    'keepalive' => function ( array $lanes, $lane_owner ) use ( $lims_db, $dispatch_id, $generation, $owner ) {
        $args = array( 'php', __DIR__ . '/dispatch_keepalive.php', $lims_db, $dispatch_id, $generation, $owner,
                       (string) getmypid(), $lane_owner );
        foreach ( $lanes as $key => $lane_generation ) {
            $args[] = "$key=$lane_generation";
        }
        $proc = proc_open( 'exec ' . implode( ' ', array_map( 'escapeshellarg', $args ) ),
                           array( 0 => array( 'file', '/dev/null', 'r' ) ), $pipes );
        return function () use ( $proc ) {
            if ( is_resource( $proc ) ) {
                proc_terminate( $proc );
                proc_close( $proc );
            }
        };
    },
    'submit' => function ( array $plan, $token ) use ( $load_job ) {
        return $load_job()->submit_staged( $token );
    },
    'track' => function ( array $plan, $job_id ) use ( $load_job ) {
        $loaded = $load_job();
        return ! is_string( $loaded ) && $loaded->track_accepted( $job_id, $plan );
    },
    'advise' => function ( $job_id ) use ( $load_job ) {
        $loaded = $load_job();
        if ( ! is_string( $loaded ) ) {
            $loaded->observe_accepted( $job_id );
        }
    },
    ## Jobs at $cluster carrying the attempt token in their comment. --states=all keeps a job that
    ## already finished (within MinJobAge); an empty answer still never proves nonacceptance.
    'find' => function ( $cluster, $token ) use ( $global_cluster_details ) {
        $rx = new remote_exec( $cluster, $global_cluster_details, 'elog2' );
        $res = $rx->run( "squeue --noheader --all --states=all --user=\"\$(id -un)\" --format='%i|%k'",
                         array( 'retries' => 1, 'label' => 'dispatch reconcile' ) );
        if ( ! $res[ 'ok' ] ) {
            return array( 'ok' => false, 'jobs' => array(), 'reason' => $res[ 'class' ],
                          'gone' => dispatch_worker_member_gone( $cluster, $global_cluster_details ) );
        }
        return array( 'ok' => true, 'reason' => '',
                      'jobs' => dispatch_worker_jobs_with_comment( $res[ 'stdout' ], submit_slurm::dispatch_comment( $token ) ) );
    },
    'cancel' => function ( $cluster, $job_id ) {
        return cluster_probe_cancel_job( $cluster, $job_id, 'elog2', array( 'retries' => 0, 'timeout' => 15 ) );
    },
    'reconnect' => $dispatch_connect,
    'spool' => dispatch_spool_dir(),
    'submission_blocked' => function () { return motd_isblocked(); },
    'batch_preflight' => function ( $member, array $children ) use ( $open_input ) {
        $jobs = array();
        $failed = array();
        foreach ( $children as $child ) {
            $loaded = $open_input( $child );
            if ( is_string( $loaded ) ) {
                $failed[] = "request {$child['dispatchRequestID']}: $loaded";
                continue;
            }
            $jobs[ $child[ 'dispatchRequestID' ] ] = $loaded;
        }
        if ( $failed ) {
            return array( 'ok' => false, 'failed' => $failed );
        }
        $check = jobsubmit::batch_preflight( $jobs, $member );
        foreach ( $check[ 'failed' ] as $id => $error ) {
            $failed[] = "request $id: $error";
        }
        return array( 'ok' => $check[ 'ok' ], 'failed' => $failed );
    },
    ## Blocked work waits for an operator: tell the site admin and the submitter.
    'blocked' => function ( $reason, $code = '' ) use ( $dispatch_link, $lims_db, $dispatch_id, $req, $listen_admin_email ) {
        global $global_site_admin_email, $org_name;
        $send = dispatch_site_admin_sender(
            dispatch_site_admin_address( isset( $global_site_admin_email ) ? $global_site_admin_email : '', $listen_admin_email ),
            isset( $org_name ) ? $org_name : '' );
        $who = dispatch_request_submitters( $dispatch_link, $lims_db, array( $dispatch_id ) );
        dispatch_notify_blocked( $send, $lims_db, $req, $reason, isset( $who[ (int) $dispatch_id ] ) ? $who[ (int) $dispatch_id ][ 0 ] : '', $code );
    },
    'closed' => function ( $reason ) use ( $dispatch_link, $lims_db, $dispatch_id, $req, $listen_admin_email ) {
        global $global_site_admin_email, $org_name;
        $send = dispatch_site_admin_sender(
            dispatch_site_admin_address( isset( $global_site_admin_email ) ? $global_site_admin_email : '', $listen_admin_email ),
            isset( $org_name ) ? $org_name : '' );
        $who = dispatch_request_submitters( $dispatch_link, $lims_db, array( $dispatch_id ) );
        dispatch_notify_closed( $send, $lims_db, $req, $reason, isset( $who[ (int) $dispatch_id ] ) ? $who[ (int) $dispatch_id ][ 0 ] : '' );
    },
    'pool_disabled' => function ( array $ids, $reason ) use ( $dispatch_link, $lims_db, $listen_admin_email ) {
        global $global_site_admin_email, $org_name;
        $send = dispatch_site_admin_sender(
            dispatch_site_admin_address( isset( $global_site_admin_email ) ? $global_site_admin_email : '', $listen_admin_email ),
            isset( $org_name ) ? $org_name : '' );
        ## Housekeeping mirrors only instances with unfinished requests, and these may have been the last.
        dispatch_mirror_autoflow( $dispatch_link, $lims_db );
        dispatch_notify_pool_disabled( $send, $lims_db, $reason, dispatch_request_submitters( $dispatch_link, $lims_db, $ids ) );
    },
    'failed' => function ( $reason ) use ( $load_job ) {
        $loaded = $load_job();
        if ( ! is_string( $loaded ) ) {
            $loaded->report_deferred_failure( $reason );
        }
    },
);

$state = in_array( $req[ 'state' ], array( DISPATCH_SUBMISSION_UNKNOWN, DISPATCH_ACCEPTED, DISPATCH_MONITORED ), true )
       ? dispatch_recover_run( $dispatch_link, $dispatch_gdb, $lims_db, (int) $dispatch_id, (int) $generation, $owner, $io )
       : dispatch_attempt_run( $dispatch_link, $dispatch_gdb, $lims_db, (int) $dispatch_id, (int) $generation, $owner, $io );
elog2( "dispatchone: $lims_db request $dispatch_id generation $generation left in $state" );
exit( 0 );
