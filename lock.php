<?php

# check if already running and register pid
    
echo "php lock.php ... __FILE__ is " . __FILE__ . "\n"; 

if ( !isset( $lock_dir ) ) {
    fwrite( STDERR, "variable \$lock_dir not set\n" );
    exit(-1);
}

if ( !isset( $lock_main_script_name ) ) {
    fwrite( STDERR, "variable \$lock_main_script_name not set\n" );
    exit(-1);
}

if ( !is_dir( $lock_dir ) ) {
    fwrite( STDERR, "variable \$lock_dir set as $lock_dir, but it is not a directory\n" );
    exit(-1);
}

define('LOCK_FILE', "$lock_dir/" . basename( $lock_main_script_name ) . ".lock");
define('EXPECTED_CMDLINE', basename( $lock_main_script_name ) );

echo "LOCK_FILE: " . LOCK_FILE . "\n";
echo "EXPECTED_CMDLINE: " . EXPECTED_CMDLINE . "\n";

function tryLock() {
    # If lock file exists, check if stale.  If exists and is not stale, return TRUE
    # Else, create lock file and return FALSE.

    echo "tryLock() 0\n";

    if (@symlink("/proc/" . getmypid(), LOCK_FILE) !== FALSE) # the @ in front of 'symlink' is to suppress the NOTICE you get if the LOCK_FILE exists
    {
        return true;
    }
    echo "tryLock() 1\n";

    # link already exists
    # check if it's stale
    $isstale = false;

    if ( is_link(LOCK_FILE) ) {
        echo "is_link(" . LOCK_FILE . ") true\n";
        if ( ( $link = readlink( LOCK_FILE ) ) === FALSE ) {
            $isstale = true;
            echo "is stale 1\n";
        }
    } else {
        $isstale = true;
        echo "is stale 2\n";
    }
    echo "tryLock() 2\n";

    if ( !$isstale && is_dir( $link ) ) {
        # make sure the cmdline exists & matches expected
        $cmdline_file = $link . "/cmdline";
        echo "cmdline_file = $cmdline_file\n";
        if ( ($cmdline = file_get_contents( $cmdline_file )) === FALSE ) {
            echo "could not get contents of $cmdline_file\n";
            $isstale = true;
            echo "is stale 3\n";
        } else {
            # remove nulls
            $cmdline = str_replace("\0", "", $cmdline);
            if ( strpos( $cmdline, EXPECTED_CMDLINE ) === false ) {
                echo "unexpected contents of $cmdline_file\n";
                $isstale = true;
                echo "is stale 4 \n";
            }
        }
    }
    echo "tryLock() 3\n";

    if (is_link(LOCK_FILE) && !is_dir(LOCK_FILE)) {
        $isstale = true;
    }

    echo "tryLock() 4\n";
    if ( $isstale ) {
        unlink(LOCK_FILE);
        # try to lock again
        return tryLock();
    }
    echo "tryLock() 5\n";
    return false;
}

if ( !tryLock() ) {
    die( "Already running.\n" );
}

# remove the lock on exit (Control+C doesn't count as 'exit'?)
register_shutdown_function( 'unlink', LOCK_FILE );
