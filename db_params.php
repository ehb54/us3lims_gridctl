<?php
## mysqli_query() with bound values: a result set for a statement that returns rows, true for any
## other, false on failure, with mysqli_errno()/mysqli_error() on $link describing the failure.
## An int argument binds as an integer, a float as a double, anything else as a string. The statement
## stays open until the next call, since closing it clears the link's error and affected-row count.
if ( ! function_exists( 'db_params_query' ) )
{
   function db_params_query( $link, $sql, array $args = array() )
   {
      static $last = null;
      $last = null;
      try
      {
         $stmt = $last = mysqli_prepare( $link, $sql );
         if ( ! $stmt )
         {
            return false;
         }
         if ( $args )
         {
            $types = '';
            $refs  = array();
            foreach ( array_values( $args ) as $k => $v )
            {
               $types   .= is_int( $v ) ? 'i' : ( is_float( $v ) ? 'd' : 's' );
               $refs[ $k ] = $v;
            }
            $bind = array( $stmt, $types );
            foreach ( $refs as $k => $v )
            {
               $bind[] = &$refs[ $k ];
            }
            call_user_func_array( 'mysqli_stmt_bind_param', $bind );
         }
         if ( ! mysqli_stmt_execute( $stmt ) )
         {
            return false;
         }
         return mysqli_stmt_result_metadata( $stmt ) ? mysqli_stmt_get_result( $stmt ) : true;
      }
      catch ( mysqli_sql_exception $e )
      {
         return false;
      }
   }
}
