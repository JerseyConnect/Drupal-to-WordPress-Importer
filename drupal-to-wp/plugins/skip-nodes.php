<?php
/**
 * Allow the importer to skip nodes by ID using a simple file format
 * 
 * This plugin will search for a [Drupal database name]_skip.txt file in the plugins folder (or another specified folder).
 * If found, any node IDs listed in the file will be skipped.
 * 
 */

define( 'NODESKIP_SKIPLIST_PATH', './skiplists' );

add_filter( 'import_node_skip_node', array( 'SkipNodeImport', 'is_node_skipped' ), 10, 2 );

class SkipNodeImport {
	
	public static $has_skip_file = 'unknown';
	private static $skiplist_path = false;
	private static $skips = array();
	
	public static function is_node_skipped( $result, $node ) {
		
		if( $result )
			return $result;
		
		if( ! self::has_skip_file() )
			return $result;
		
		self::load_skips();
		
		if( in_array(  (int)$node['nid'], self::$skips  ) )
			return true;
		
		return $result;
		
	}
	
	public static function get_skiplist_path() {
		
		if( ! empty( self::$skiplist_path ) )
			return self::$skiplist_path;
		
		$dir = realpath( dirname( __FILE__ ) . DIRECTORY_SEPARATOR . NODESKIP_SKIPLIST_PATH );
		if( $dir ) {
			self::$skiplist_path = $dir;
			return $dir;
		}
		
		return '.';
		
	}
	
	public static function has_skip_file() {
		
		if( false === self::$has_skip_file )
			return false;
		if( true === self::$has_skip_file )
			return true;
		
		$file_name = drupal()->dbName . '_skip.txt';
		
		if( file_exists( self::get_skiplist_path() . DIRECTORY_SEPARATOR . $file_name ) ) {
			self::$has_skip_file = true;
			return true;
		} else {
			self::$has_skip_file = false;
			return false;
		}
		
	}
	
	public static function load_skips() {
		
		if( ! empty( self::$skips ) )
			return;
		
		echo 'Loading node skip list for: ' . drupal()->dbName . "<br>\n";
		
		$file_name = drupal()->dbName . '_skip.txt';
		self::$skips = file( self::get_skiplist_path() . DIRECTORY_SEPARATOR . $file_name );
		
	}
	
}

?>