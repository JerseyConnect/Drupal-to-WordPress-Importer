<?php
/**
 * Allow the importer to map nodes to new URLs by ID with a CSV file.
 *   Also enables merging multiple pages into a single URL
 *   and redirect support if Redirection is available. Cleans
 *   up merged posts.
 * 
 * This plugin will search for a node_map.csv file in the specified folder.
 * 
 */

define( 'NODEMAP_MAP_PATH', './nodemaps' );
define( 'NODEMAP_READ_MODE', 'memory' ); 
// one of: memory, streaming depending on your needs -- memory is much faster, but streaming scales infinitely

add_filter( 'import_node_postprocess', array( 'MapNodeURL', 'process_node' ), 1, 2 );

class MapNodeURL {
	
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
	 * action     = action to take (keep, redirect, delete, nomerge)
	 * final_url  = intended URL for the page
	 */
	private static $map_fieldmap = array(
		'source_db'  => 'Drupal site',
		'source_nid' => 'Drupal ID',
		'action'     => 'Action',
		'final_url'  => 'Final URL'
	);
	
	public static function process_node( $post_ID, $node ) {
		
		if( ! self::has_map() )
			return;
		
		self::load_map();
		
		$drupal_db = get_post_meta(
			$post_ID,
			'_drupal_database',
			true
		);
		$drupal_nid = $node['nid'];
		
		$key = $drupal_db .'-'.$drupal_nid;
		
		if( ! array_key_exists( $key, self::$map ) )
			return;
		
		switch( strtolower( self::$map[ $key ][ self::$map_fieldmap[ 'action' ] ] ) ) {
			
			case 'delete':   // Delete page and don't create redirects -- also cleans up metadata
			
				wp_delete_post(
					$post_ID,
					true
				);
			
				break;
				
			case 'redirect': // Delete page contents but redirect aliases to specified URL
				
				if( ! function_exists( 'red_get_url' ) ) {
					echo_now( 'Redirects require the Redirection WP plugin' );
					return;
				}
				
				$aliases = maybe_unserialize(
					get_post_meta(
						$post_ID,
						'_drupal_aliases',
						true
					)
				);
				
				foreach( $aliases as $alias ) {
					
					Red_Item::create(
						array(
							'source'     => $alias,
							'target'     => self::$map[ $key ][ self::$map_fieldmap[ 'final_url' ] ],
							'group'      => 1,
							'match'      => 'url',
							'red_action' => 'url'
						)
					);
					
				}
				
				wp_delete_post(
					$post_ID,
					true
				);
				
				break;
				
			case 'keep':     // Keep node content in page at specified URL - if a page already exists there, merge contents
				
				if( $result = get_page_by_path( untrailingslashit( self::$map[ $key ][ self::$map_fieldmap[ 'final_url' ] ] ) ) ) {
					
					// A page already exists at the specified URL - merge the contents
					
					$new_post = get_post( $post_ID );
					
					$content = $result->post_content;
					$content .= "<br><br>" . $new_post->post_content;
					
					$updated_post = wp_update_post(
						array(
							'ID'           => $result->ID,
							'post_content' => $content
						)
					);
					
					if( ! $updated_post )
						echo 'ERROR updating post ' . $result->ID . ' : ' . "<br>\n";
					
					// Copy aliases to the combined post for potential redirection
					update_post_meta(
						$result->ID,
						'_drupal_aliases',
						array_merge(
							maybe_unserialize(
								get_post_meta( $result->ID, '_drupal_aliases', true )
							),
							maybe_unserialize(
								get_post_meta( $post_ID, '_drupal_aliases', true )
							)
						)
					);
					
					// Delete the merged post
					wp_delete_post(
						$post_ID,
						true
					);
					
				} else {
					
					// No page exists at the specified URL - move this page
					
					$last_page = self::make_page_tree(  self::$map[ $key ][ self::$map_fieldmap[ 'final_url' ] ] );
					
					wp_update_post(
						array(
							'ID'          => $post_ID,
							'post_name'   => basename( self::$map[ $key ][ self::$map_fieldmap[ 'final_url' ] ] ),
							'post_parent' => $last_page
						)
					);
					
				}
				
				break;
			case 'nomerge':  // Keep node content at specified URL if possible, letting WP generate a different slug if needed
			default:
				
				if( $result = get_page_by_path( untrailingslashit( self::$map[ $key ][ self::$map_fieldmap[ 'final_url' ] ] ) ) ) {
					
					$last_page = self::make_page_tree(  self::$map[ $key ][ self::$map_fieldmap[ 'final_url' ] ] );
					
					$post_slug = wp_unique_post_slug(
						sanitize_title( basename( self::$map[ $key ][ self::$map_fieldmap[ 'final_url' ] ] ) ),
						$post_ID,
						'publish',
						'page',
						$last_page
					);
					
					wp_update_post(
						array(
							'ID'          => $post_ID,
							'post_name'   => $post_slug,
							'post_parent' => $last_page
						)
					);
					
				}
				break;
		}
		
	}
	
	public static function get_map_path() {
		
		if( ! empty( self::$map_path ) )
			return self::$map_path;
		
		$dir = realpath( dirname( __FILE__ ) . DIRECTORY_SEPARATOR . NODEMAP_MAP_PATH );
		if( $dir ) {
			self::$map_path = $dir;
			return $dir;
		}
		
		return '.';
		
	}
	
	public static function has_map() {
		
		if( false === self::$has_map )
			return false;
		if( true === self::$has_map )
			return true;
		
		$file_name = drupal()->dbName . '_map.csv';
		$global_file_name = 'global-node-map.csv';
		
		if( file_exists( self::get_map_path() . DIRECTORY_SEPARATOR . $file_name ) ) {
			self::$has_map = true;
		} else if( file_exists( self::get_map_path() . DIRECTORY_SEPARATOR . $global_file_name ) ) {
			self::$has_map = true;
		} else {
			self::$has_map = false;
		}
		
		return self::$has_map;
	}
	
	public static function load_map() {
		
		if( ! empty( self::$map ) && 'memory' == NODEMAP_READ_MODE )
			return;
		
		echo_now( 'Loading node URL map for: ' . drupal()->dbName );
		
		$file_name = drupal()->dbName . '_map.csv';
		$global_file_name = 'global-node-map.csv';
		
		if( ! file_exists( self::get_map_path() . DIRECTORY_SEPARATOR . $file_name ) ) {
			
			if(  file_exists( self::get_map_path() . DIRECTORY_SEPARATOR . $global_file_name ) ) {
				$file_name = $global_file_name;
			} else {
				return false;
			}
			
		}
		
		if( 'memory' == NODEMAP_READ_MODE ) {
			
			self::$map_file = fopen( self::get_map_path() . DIRECTORY_SEPARATOR . $file_name , 'r' );
			
			self::$map_fields = fgetcsv( self::$map_file );
			
			self::$map = array();
			while( ( $line = fgetcsv( self::$map_file ) ) !== false ) {
				
				$line = array_combine(
					self::$map_fields,
					$line
				);
				
				$key = $line[ self::$map_fieldmap[ 'source_db' ] ] . '-' . $line[ self::$map_fieldmap[ 'source_nid' ] ];
				
				self::$map[ $key ] = $line;
				
			}
			
			fclose( self::$map_file );
			
		} else {
			
			// TODO: Streaming mode -- this will take forever but will scale
			// Probably build an index of the file with database names and node IDs
			// Then load chunks when a matching node is processed.
			
		}
		
	}
	
	/**
	 * Identify or generate parent pages, returning the ID of the last descendant
	 */
	private static function make_page_tree( $path ) {
		
		$path_parts = explode(
			'/', 
			untrailingslashit( $path ) 
		);
		
		$last_page = 0;
		
		for( $x = 1; $x < count( $path_parts ); $x++ ) {

//			echo_now( 'Checking: ' . implode( '/', array_slice( $path_parts, 0, $x ) ) );

			if( ! $last_page = get_page_by_path( implode( '/', array_slice( $path_parts, 0, $x ) ) ) ) {
				
//				echo_now( 'Creating page: ' . $path_parts[ ( $x - 1 ) ] . ' at ' . implode( '/', array_slice( $path_parts, 0, $x ) ) . ' with parent: ' . $last_page );
				
				$last_page = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_title'   => $path_parts[ ( $x - 1 ) ],
						'post_name'    => strtolower( str_replace( ' ','-', $path_parts[ ( $x - 1 ) ] ) ),
						'post_content' => 'Created by Drupal to WP importer',
						'post_status'  => 'publish',
						'post_parent'  => $last_page
					)
				);
				
//				echo_now( 'Created page with result: ' . $last_page );
				
				if( ! $last_page ) {
					echo 'ERROR creating parent page: ' . $path_parts[ ( $x - 1 ) ] . "<br>\n";
					return;
				}
				
			}
			
		}
		
		if( is_object( $last_page ) )
			$last_page = $last_page->ID;
		
		return $last_page;
		
	}
	
}

?>