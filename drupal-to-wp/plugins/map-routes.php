<?php
/**
 * Allow the importer to map routes from the "menu_router" Drupal table to new URLs with a CSV file.
 * Requires the Redirection plugin -- https://wordpress.org/plugins/redirection/
 * 
 * This plugin will search for a [database name]_routemap.csv file in the specified folder, but can
 *   also use a `global-route-map.csv` file for multiple sites. 
 * 
 */

define( 'ROUTEMAP_MAP_PATH', './routemaps' );
define( 'ROUTEMAP_READ_MODE', 'memory' );

add_filter( 'import_postprocess', array( 'MapRouteURL', 'process_routes' ), 10, 4 );

class MapRouteURL {
	
	private static $map = null;
	private static $has_map = null;
	private static $map_fields = null;
	
	private static $map_path = null;
	private static $map_file = null;
	
	/**
	 * Use this array to determine which fields in the map file provide
	 *   data for this operation.
	 * 
	 * source_db  = name of source database (as stored in _drupal_database)
	 * source_nid = source Drupal node ID (as stored in _drupal_nid)
	 * action     = action to take (keep, redirect, delete, skip)
	 * final_url  = intended URL for the page
	 */
	private static $map_fieldmap = array(
		'source_db'   => 'Drupal site',
		'source_path' => 'Drupal path',
		'action'      => 'Action',
		'final_url'   => 'Final URL',
		'post_title'  => 'Page Title'
	);

	/**
	 * 
	 */
	public static function process_routes( $node_map, $user_map, $term_map, $file_map ) {
		
		echo_now( 'Generating pages from menu_router URL entries...' );
		
		$routes = drupal()->menu_router->getRecords(
			array(
				'page_callback' => 'views_page',
				'AND' => array(
					'path' => array(
						'!admin%',
						'!user%',
						'!%feed'
					)
				)
			)
		);
		
		foreach( $routes as $route ) {
			
			self::process_route( $route );
			
		}
		
	}
	
	/**
	 * Handle the mapping for an individual route entry
	 */
	public static function process_route( $route ) {
		
		if( ! self::has_map() )
			return;
		
		self::load_map();
		
		$key = self::get_key( $route );
		
		if( empty( $key ) || ! array_key_exists( $key, self::$map ) )
			return;
		
		switch( strtolower( self::$map[ $key ][ self::$map_fieldmap[ 'action' ] ] ) ) {
			
			case 'delete':   // Skip this menu route / view
			case 'skip':
			
				break;
			case 'redirect': // Create a blind redirect to the specified URL
			case 'keep':
			case 'yes':
				
				if( ! function_exists( 'red_get_url' ) ) {
					echo_now( 'Redirects require the Redirection WP plugin' );
					return;
				}
				
				Red_Item::create(
					array(
						'source'     => $route['path'],
						'target'     => self::$map[ $key ][ self::$map_fieldmap[ 'final_url' ] ],
						'group'      => RedirectionImport::get_group_id(),
						'match'      => 'url',
						'red_action' => 'url'
					)
				);
				
				break;
			default:
				
				// If the action is the name of a different post type, convert the post
				$post_types = get_post_types( '', 'names' );
				
				$post_type = apply_filters(
					'map_routes_create_post',
					strtolower( self::$map[ $key ][ self::$map_fieldmap[ 'action' ] ] ),
					$route
				);
				
				if( in_array( $post_type, $post_types ) ) {


					$post_data = array(
						'post_type'    => $post_type,
						'post_title'   => $route['title'],
						'post_name'    => basename( $route['path'] ),
						'post_content' => $route['description'],
						'post_excerpt' => $route['description']
					);

					$post_data = apply_filters(
						'import_node_pre',
						$post_data,
						$route
					);
					
					$post_data = apply_filters(
						sprintf( 'import_post_pre_%s', $post_type ),
						$post_data,
						$route
					);
					
					$post_ID = wp_insert_post(
						$post_data
					);
					
					do_action(
						'map_routes_after_create_post',
						$post_type,
						strtolower( self::$map[ $key ][ self::$map_fieldmap[ 'action' ] ] ),
						$post_ID,
						$route
					);
					
					if( ! $post_ID )
						echo_now( 'ERROR creating post for route: ' . $route['path'] );
					
				}
				
				if( ! empty( self::$map_fieldmap[ 'post_title' ] ) && get_the_title( $post_ID ) != self::$map[ $key ][ self::$map_fieldmap[ 'post_title' ] ] ) {
					
					wp_update_post(
						array(
							'ID' => $post_ID,
							'post_title' => self::$map[ $key ][ self::$map_fieldmap[ 'post_title' ] ]
						)
					);
					
				}
				
				// Otherwise, skip it
				return;	
				
				break;
		}
		
	}
	
	/**
	 * Build the full path to the specified route map directory
	 */
	public static function get_map_path() {
		
		if( ! empty( self::$map_path ) )
			return self::$map_path;
		
		$dir = realpath( dirname( __FILE__ ) . DIRECTORY_SEPARATOR . ROUTEMAP_MAP_PATH );
		if( $dir ) {
			self::$map_path = $dir;
			return $dir;
		}
		
		return '.';
		
	}
	
	/**
	 * Determine whether either a local or global map file exists
	 */
	public static function has_map() {
		
		if( false === self::$has_map )
			return false;
		if( true === self::$has_map )
			return true;
		
		$file_name = drupal()->dbName . '_routemap.csv';
		$global_file_name = 'global-route-map.csv';
		
//		echo_now('Looking for: ' .  self::get_map_path() . DIRECTORY_SEPARATOR . $file_name );
		
		if( file_exists( self::get_map_path() . DIRECTORY_SEPARATOR . $file_name ) ) {
			self::$has_map = true;
		} else if( file_exists( self::get_map_path() . DIRECTORY_SEPARATOR . $global_file_name ) ) {
			self::$has_map = true;
		} else {
			echo_now( 'No route map file found -- skipping mapping phase' );
			self::$has_map = false;
		}
		
		return self::$has_map;
	}
	
	/**
	 * Generate index for final URL array
	 */
	private static function get_key( $route ) {
		
		$drupal_db = drupal()->dbName;
		
		if( ! $drupal_db )
			return false;
		
		$drupal_nid = $route['path'];
		
		$key = $drupal_db .'-'.$drupal_nid;
		
		return $key;
		
	}
	
	public static function load_map() {
		
		if( ! empty( self::$map ) && 'memory' == ROUTEMAP_READ_MODE )
			return;
		
		echo_now( 'Loading route URL map for: ' . drupal()->dbName );
		
		$file_name = drupal()->dbName . '_routemap.csv';
		$global_file_name = 'global-route-map.csv';
		
		if( ! file_exists( self::get_map_path() . DIRECTORY_SEPARATOR . $file_name ) ) {
			
			if(  file_exists( self::get_map_path() . DIRECTORY_SEPARATOR . $global_file_name ) ) {
				$file_name = $global_file_name;
			} else {
				return false;
			}
			
		}
		
		if( 'memory' == ROUTEMAP_READ_MODE ) {
			
			self::$map_file = fopen( self::get_map_path() . DIRECTORY_SEPARATOR . $file_name , 'r' );
			
			self::$map_fields = fgetcsv( self::$map_file );
			
			self::$map = array();
			while( ( $line = fgetcsv( self::$map_file ) ) !== false ) {
				
				$line = array_combine(
					self::$map_fields,
					$line
				);
				
				$line = array_map( 'trim', $line );
				
				$key = $line[ self::$map_fieldmap[ 'source_db' ] ] . '-' . $line[ self::$map_fieldmap[ 'source_path' ] ];
				
				self::$map[ $key ] = $line;
				
			}
			
			fclose( self::$map_file );
			
		} else {
			
			// TODO: Streaming mode -- this will take forever but will scale
			// Probably build an index of the file with database names and node IDs
			// Then load chunks when a matching node is processed.
			// There ended up being no need for this.
			
		}
		
	}
	
}

?>