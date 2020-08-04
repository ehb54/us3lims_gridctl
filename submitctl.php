<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
include "$us3bin/listen-config.php";
include "$class_dir/experiment_status.php";

# ********* start user defines *************

# the polling interval
$poll_sleep_seconds = 30;
$logging_level      = 1;

# ********* end user defines ***************


# add locking
if ( isset( $lock_dir ) ) {
    $lock_main_script_name  = __FILE__;
    require "$us3bin/lock.php";
}

function write_logl( $msg, $this_level = 99 ) {
    global $logging_level;
    if ( $logging_level <= $this_level ) {
        write_log( $msg );
    }
}

# Gary: should we have our own log? currently log is "udp.log" 

write_logl( "$self: Starting" );

while( 1 ) {
    write_logl( "$self: checking mysql", 2 );
    # read from mysql - HPCSubmissionRequest
    # if ( mysql returned empty )
    {
        write_logl( "$self: sleeping ${poll_sleep_seconds}s", 2 );
        sleep( $poll_sleep_seconds );
        continue;
    }
    # update status of HPCSubmissionRequest to "submitting" ?
    # call submit_job()
    # move HPCSubmissionRequest to HPCSubmissionRequestHistory ?
    # update any other tables?
}


