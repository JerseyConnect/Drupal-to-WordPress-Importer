<?php
/**
 * Allow the importer to skip nodes by ID using a simple file format
 * 
 * This plugin will search for a [Drupal database name]_skip.txt file in the plugins folder.
 * If found, any node IDs listed in the file will be skipped.
 * 
 */

add_filter( 'import_node_skip_node', array( 'SkipNodeImport', 'is_node_skipped' ), 10, 2 );

class SkipNodeImport {
	
	public static $has_skip_file = 'unknown';
	private static $skips = array();
	
	public static function is_node_skipped( $result, $node ) {
		
		if( ! self::has_skip_file() )
			return false;
		
		self::load_skips();
		
		return in_array( (int)$node['nid'], self::$skips );
		
	}
	
	public static function has_skip_file() {
		
		if( false === self::$has_skip_file )
			return false;
		if( true === self::$has_skip_file )
			return true;
		
		$file_name = drupal()->dbName . '_skip.txt';
		
		if( file_exists( dirname( __FILE__ ) . DIRECTORY_SEPARATOR . $file_name ) ) {
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
		
		$file_name = drupal()->dbName . '_skip.txt';
		self::$skips = file( dirname( __FILE__ ) . DIRECTORY_SEPARATOR . $file_name );
		
	}
	
}

?>