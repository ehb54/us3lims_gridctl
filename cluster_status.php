<?php

$us3bin = exec( "ls -d ~us3/lims/bin" );
include "$us3bin/listen-config.php";

if ( ! preg_match( "/_local/", $class_dir ) )
{
   $xml  = get_data();

   if ( $xml != "" )
      parse( $xml );
}

$data = array();

local_status();

foreach ( $data as $item )
{
   if ( ! preg_match( "/error/", $item[ 'running' ] ) )
      update( $item[ 'cluster' ], $item[ 'queued' ], $item[ 'status' ], $item[ 'running' ] );
}

//exit();
exit(0);

// Get the cluster status

function get_data()
{
   global $self;
   $url = "http://community.ucs.indiana.edu:19444/orps-service/XML/gateway/ultrascan";

   try
   {
      $post = new HttpRequest( $url, HttpRequest::METH_GET );
      $http = $post->send();
      $xml  = $post->getResponseBody();      
   }
   catch ( HttpException $e )
   {
//      write_log( "$self: Cluster Status not available" );
      return "";
   }

   return $xml;
}

// Parse the xml

function parse( $xml )
{
   global $data;
   
   $data = array();

   $x = new XML_Array( $xml );
   $d = $x->ReturnArray();

   if ( ! isset( $d[ 'summaries' ] ) ) exit();  // Bad input

   foreach ( $d[ 'summaries' ] as $item )
   {
      $a = Array();

      
      $a[ 'queued'  ] = $item[ 'waitingJobs' ];
      $a[ 'running' ] = $item[ 'runningJobs' ];

      if (  $a[ 'queued'  ] == ""  ||  $a[ 'queued'  ] < 0 ) $a[ 'queued'  ] = 0;
      if (  $a[ 'running' ] == ""  ||  $a[ 'running' ] < 0 ) $a[ 'running' ] = 0;

      $clusterParts  = explode( ".", $item[ 'resourceId' ] );
      $cluster       = preg_replace( '/\d+$/', "", $clusterParts[ 0 ] );

      if ( $cluster == 'uthscsa-bcf' )   $cluster = 'bcf';
      if ( $cluster == 'uthscsa-alamo' ) $cluster = 'alamo';

      $a[ 'cluster' ] = $cluster;
      
      switch ( $item[ 'resourceStatus' ] )
      {
         case 'UP'     :
            $status = "up";
            break;

         case 'DOWN'   :
            $status = "down";
            break;

         case 'WARN'   :
            $status = "warn";
            break;

         case 'FAILED' :
         default       :
            $status = "unknown";
            break;
      }
      
      $a[ 'status' ]  = $status;

      $data[] = $a;
   }
}

// Put it in the DB

function update( $cluster, $queued, $status, $running )
{
   global $dbhost;
   global $guser;
   global $gpasswd;
   global $gDB;
   global $self;
//echo  " $cluster $queued, $status, $running\n";

   $gfac_link = mysqli_connect( $dbhost, $guser, $gpasswd, $gDB );

   if ( ! $gfac_link )
   {
      write_log( "$self: Could not connect to DB $gDB" );
      echo "Could not connect to DB $gDB.\n";
      exit();
   }
      
   $query = "SELECT * FROM cluster_status WHERE cluster='$cluster'";
   $result = mysqli_query( $gfac_link, $query );

   if ( ! $result )
   {
      write_log( "$self: Query failed $query - " .  mysqli_error( $gfac_link ) );
      echo "$self: Query failed $query - " .  mysqli_error( $gfac_link ) . "\n";
      exit();
   }

   $rows = mysqli_num_rows( $result );

   if ( $rows == 0 )  // INSERT
   {
      $query = "INSERT INTO cluster_status SET " .
               "cluster='$cluster', " .
               "queued=$queued, "     .
               "running=$running, "   .
               "status='$status'";
   }
   else               // UPDATE
   {
      $query = "UPDATE cluster_status SET " .
               "queued=$queued, "     .
               "running=$running, "   .
               "status='$status' "    .
               "WHERE cluster='$cluster'";
   }

   $result = mysqli_query( $gfac_link, $query );

   if ( ! $result )
   {
      write_log( "$self: Query failed $query - " .  mysqli_error( $gfac_link ) );
      echo "$self: Query failed $query - " .  mysqli_error( $gfac_link ) . "\n";
   }
}

// Get local cluster status

function local_status()
{
   global $self;
   global $data;
   global $dbhost;
   global $org_domain;
   global $class_dir;

   if ( preg_match( "/_local/", $class_dir ) )
   {
      if ( preg_match( "/attlocal/", $org_domain ) )
         $clusters = array( "us3iab-devel" );
      else
//         $clusters = array( "us3iab-node0",  "demeler3-local", "puhti-local" );
         $clusters = array( "us3iab-node0" );
   }
   else
   {
      $clusters = array( "lonestar5", "comet",
                         "stampede2", "jetstream" );
   }

   foreach ( $clusters as $clname )
   {
      $a      = Array();
//echo "$self:   clname=$clname\n";

      switch( $clname )
      {
         case 'us3iab-node0':
         {  // USiaB local cluster using slurm
//            $host   = "us3@js-169-137.jetstream-cloud.org";
//            $qstat  = `ssh $host '/home/us3/bin/clusstat |tail -n 1'`;
  	         $qstat  = `/usr/bin/sinfo -s -p batch -o "%a %F" |tail -1`;
echo "qstat=$qstat";
            $sparts = preg_split( '/\s+/', $qstat );
            $sta    = $sparts[ 0 ];
            $knts   = $sparts[ 1 ];
            $sparts = preg_split( '/\//', $knts );
            $run    = $sparts[ 0 ];
            $que    = $sparts[ 2 ];
            $tot    = $sparts[ 3 ];
            if ( $sta == "" )
               $sta    = "down";
            break;
         }
         case 'us3iab-node1':
         case 'us3iab-devel':
         {  // USiaB local cluster using pbs (torque)
            $qstat  = `/usr/bin/qstat -B 2>&1|tail -1`;

            $sparts = preg_split( '/\s+/', $qstat );
            $que    = $sparts[ 3 ];
            $run    = $sparts[ 4 ];
            $sta    = $sparts[ 10 ];
            if ( $sta == "Active" )
               $sta    = "up";
            else
               $sta    = "down";
            break;
         }
         case 'demeler3-local':
         {
            $host   = "us3@demeler3.uleth.ca";
            $qstat  = `ssh $host '/usr/bin/qstat -B 2>&1|tail -1'`;
            $sparts = preg_split( '/\s+/', $qstat );
            $que    = $sparts[ 3 ];
            $run    = $sparts[ 4 ];
            $sta    = $sparts[ 10 ];
            if ( $sta == "Active" )
               $sta    = "up";
            else
               $sta    = "down";
            break;
         }
         case 'stampede2':
         {
            $host   = "us3@stampede2.tacc.utexas.edu";
            $qstat  = `ssh $host '~us3/scripts/clusstat skx-normal 2>/dev/null'`;
            $sparts = preg_split( '/\s+/', $qstat );
            $tot    = $sparts[ 2 ];
            $run    = $sparts[ 5 ];
            $que    = $sparts[ 8 ];
            $sta    = "up";
            if ( $tot == ''  ||  $tot == '0' )
               $sta    = "down";
            break;
         }
         case 'lonestar5':
         {
            $host   = "us3@ls5.tacc.utexas.edu";
            $qstat  = `ssh $host '/opt/apps/tacc/bin/showq 2>/dev/null|grep "Total Jobs"'`;
            $sparts = preg_split( '/\s+/', $qstat );
            $tot    = $sparts[ 2 ];
            $run    = '0';
            $que    = '0';
            $sta    = "up";
            if ( $tot == ''  ||  $tot == '0' )
            {
               $sta    = "down";
            }
            else
            {
               $run    = $sparts[ 5 ];
               $que    = $sparts[ 8 ];
//               $que    = $sparts[ 11 ];
            }
            break;
         }
         case 'comet':
         {
            $host   = "us3@comet.sdsc.edu";
            //$qstat  = `ssh $host '/usr/bin/sinfo -s -p compute -o "%a %F" |tail -1'`;
            $qstat  = `ssh $host '/home/us3/scripts/cstat 2>&1'`;
            $sparts = preg_split( '/\s+/', $qstat );
            $tot    = $sparts[ 1 ];
            $run    = '0';
            $que    = '0';
            $sta    = "up";
            if ( $tot == ''  ||  $tot == '0' )
            {
               $sta    = "down";
            }
            else
            {
               $run    = $sparts[ 3 ];
               $que    = $sparts[ 5 ];
               if ( $run == '0'  &&  $que == '0' )
               {
                  $sta    = "down";
               }
            }
            break;
         }
         case 'jureca':
         {
            $host   = "swus1@jureca.fz-juelich.de";
            $qstat  = `ssh $host '~swus1/scripts/qstat-jureca 2>&1'`;
            $sparts = preg_split( '/\s+/', $qstat );
            $sta    = $sparts[ 0 ];
            $run    = $sparts[ 1 ];
            $que    = $sparts[ 2 ];
            break;
         }
         case 'juwels':
         {
            $host   = "gorbet1@juwels.fz-juelich.de";
            $qstat  = `ssh $host '~gorbet1/scripts/qstat-juwels 2>null'`;
            $sparts = preg_split( '/\s+/', $qstat );
            $sta    = $sparts[ 0 ];
            $run    = $sparts[ 1 ];
            $que    = $sparts[ 2 ];
             break;
         }
         case 'jetstream-local':
         case 'jetstream':
         {
            $host   = "us3@js-169-137.jetstream-cloud.org";
//            $qstat  = `ssh $host '/usr/bin/sinfo -s -p batch -o "%a %F" |tail -1'`;
            $qstat  = `ssh $host '/home/us3/bin/clusstat |tail -n 1'`;
            $sparts = preg_split( '/\s+/', $qstat );
            $sta    = $sparts[ 0 ];
            $knts   = $sparts[ 1 ];
            $sparts = preg_split( '/\//', $knts );
            $run    = $sparts[ 0 ];
            $que    = $sparts[ 2 ];
            $tot    = $sparts[ 3 ];
            if ( $sta == "" )
               $sta    = "down";
            break;
         }
         case 'chinook-local':
         case 'chinook':
         {
            $host   = "us3@chinook.hs.umt.edu";
            $qstat  = `ssh $host '/home/us3/scripts/cstat'`;
            if ( $qstat == "" )
            {
               $sta    = "down";
            }
            else
            {
               $sta    = "up";
               $sparts = preg_split( '/\s+/', $qstat );
               $tot    = $sparts[ 1 ];
               $que    = $sparts[ 3 ];
               $run    = $sparts[ 5 ];
            }
            break;
         }
         case 'umontana-local':
         {
            $host   = "bd142854e@login.gscc.umt.edu";
            $qstat  = `ssh $host '/home/bd142854e/bin/cstat 2>&1'`;
            if ( $qstat == "" )
            {
               $sta    = "down";
            }
            else
            {
               $sta    = "up";
               $sparts = preg_split( '/\s+/', $qstat );
               $tot    = $sparts[ 1 ];
               $run    = $sparts[ 3 ];
               $que    = $sparts[ 5 ];
            }
            break;
         }
         case 'taito-local':
         {
            $host   = "rb_2001068_taito01@taito.csc.fi";
            $qstat  = `ssh -i /home/us3/.ssh/id_rsa_taito_robot $host '/homeappl/home/rb_2001068_taito01/scripts/cstat 2>&1'`;
            $sparts = preg_split( '/\s+/', $qstat );
            $tot    = $sparts[ 1 ];
            $run    = '0';
            $que    = '0';
            $sta    = "up";
            if ( $tot == ''  ||  $tot == '0' )
            {
               $sta    = "down";
            }
            else
            {
               $run    = $sparts[ 3 ];
               $que    = $sparts[ 5 ];
            }
            break;
         }
         case 'puhti-local':
         {
            $host   = "rb_2001068_puhti01@puhti.csc.fi";
            $qstat  = `ssh -i /home/us3/.ssh/id_rsa_puhti_robot $host '/homeappl/home/rb_2001068_puhti01/scripts/cstat 2>&1'`;
            $sparts = preg_split( '/\s+/', $qstat );
            $tot    = $sparts[ 1 ];
            $run    = '0';
            $que    = '0';
            $sta    = "up";
            if ( $tot == ''  ||  $tot == '0' )
            {
               $sta    = "down";
            }
            else
            {
               $run    = $sparts[ 3 ];
               $que    = $sparts[ 5 ];
            }
            break;
         }

      }

      if ( $sta == "" )
         $sta    = "down";

      if ( $sta == "down" )
      {
         $que    = "0";
         $run    = "0";
      }

      // Insure queued,running counts are numeric
      $que_s          = $que;
      $run_s          = $run;
      $que_e          = preg_replace( '/=/', "", $que_s );
      $run_e          = preg_replace( '/=/', "", $run_s );
      $que            = intval( $que_e );
      $run            = intval( $run_e );
if($que!=$que_s ||  $run!=$run_s)
 echo "$self:   *** que s,e,i $que_s $que_e $que  run s,e,i $run_s $run_e $run\n";

      // Save cluster status values
      $a[ 'cluster' ] = $clname;
      $a[ 'queued'  ] = $que;
      $a[ 'running' ] = $run;
      $a[ 'status'  ] = $sta;
echo "$self:  $clname  $que $run $sta\n";

      $data[] = $a;

      if ( $clname == 'alamo'  ||
           $clname == 'jacinto'  ||
           $clname == 'jetstream' )
      {
         $a[ 'cluster' ] = $clname . "-local";
         $data[] = $a;
      }
   }
}

class XML_Array 
{
    var $_data   = Array();
    var $_name   = Array();
    var $_rep    = Array();
    var $_parser = 0;
    var $_level  = 0;
    var $_index  = 0;

    function XML_Array( &$data )
    {
        $this->_parser = xml_parser_create();

        xml_set_object                ( $this->_parser, $this );
        xml_parser_set_option         ( $this->_parser, XML_OPTION_CASE_FOLDING, false );
        xml_set_element_handler       ( $this->_parser, "_startElement", "_endElement" );
        xml_set_character_data_handler( $this->_parser, "_cdata" );

        $this->_data  = array();
        $this->_level = 0;

        if ( ! xml_parse( $this->_parser, $data, true ) )
           return false;

        xml_parser_free( $this->_parser );
    }

    function & ReturnArray() 
    {
        return $this->_data[ 0 ];
    }

    function _startElement( $parser, $name, $attrs )
    {
        if ( $name == "resourceHealth" ) 
        {
##           $name .= $this->_index;
           $this->_index++;
        }

        if ( ! isset( $this->_rep[ $name ] ) ) $this->_rep[ $name ] = 0;
        
        $this->_addElement( $name, $this->_data[ $this->_level ], $attrs );
        $this->_name[ $this->_level ] = $name;
        $this->_level++;
    }
    
    function _endElement( $parser, $name ) 
    {
       if ( isset( $this->_data[ $this->_level ] ) )
       {
          $this->_addElement( $this->_name[ $this->_level - 1 ],
                              $this->_data[ $this->_level - 1 ],
                              $this->_data[ $this->_level ]
                            );
       }

       unset( $this->_data[ $this->_level ] );
       $this->_level--; 
       $this->_rep[ $name ]++; 
    }

    function _cdata( $parser, $data ) 
    {
        if ( $this->_name[ $this->_level - 1 ] ) 
        {
           $this->_addElement( $this->_name[ $this->_level - 1 ],
                               $this->_data[ $this->_level - 1 ],
                               str_replace( array( "&gt;", "&lt;","&quot;", "&amp;" ), 
                                            array( ">"   , "<"   , '"'    , "&" ), 
                                            $data 
                                          ) 
                             );
        }
    }

    function _addElement( &$name, &$start, $add = array() ) 
    {
        if ( ( sizeof( $add ) == 0 && is_array( $add ) ) || ! $add ) 
        {
           if ( ! isset( $start[ $name ] ) ) $start[ $name ] = '';
           $add = '';
        }

        $update = &$start[ $name ];

        if     ( is_array( $add) && 
                 is_array( $update ) ) $update += $add;
        elseif ( is_array( $update ) ) return;
        elseif ( is_array( $add    ) ) $update  = $add;
        elseif ( $add              )   $update .= $add;
    }
}
?>
