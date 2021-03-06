<?php
/**
 * Allow the importer to map nodes to new URLs by ID with a CSV file.
 *   Also enables merging multiple pages into a single URL
 *   and redirect support if Redirection is available. Cleans
 *   up merged posts.
 * 
 * This plugin will search for a [database name]_map.csv file in the specified folder, but
 *   also supports the use of a single `global-node-map.csv` file for multiple databases.
 * 
 */

define( 'NODEMAP_MAP_PATH', './nodemaps' );
define( 'NODEMAP_READ_MODE', 'memory' ); 
// one of: memory, streaming depending on your needs -- memory is much faster, but streaming scales infinitely

add_action( 'import_node_postprocess', array( 'MapNodeURL', 'process_node' ), 1, 2 );

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
		'final_url'  => 'Final URL',
		'post_title' => 'Page Title'
	);
	
	public static function process_node( $post_ID, $node ) {
		
		if( ! self::has_map() )
			return;
		
		self::load_map();
		
		$key = self::get_key( $post_ID, $node );
		
		if( empty( $key ) || ! array_key_exists( $key, self::$map ) )
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
			case 'yes':
			

				if( $result = get_page_by_path( untrailingslashit( self::$map[ $key ][ self::$map_fieldmap[ 'final_url' ] ] ) ) ) {

					// Make sure the parent page is at its final URL
					$parent_key = self::get_key( $result->ID );
					
					if( $parent_key ) {
					
						if( untrailingslashit( self::$map[ $key ][ self::$map_fieldmap[ 'final_url' ] ] ) != untrailingslashit( self::$map[ $parent_key ][ self::$map_fieldmap[ 'final_url' ] ] )) {
							
							// If the page is supposed to be somewhere else, move it now
							
							self::move_or_merge_page( $post_ID );
							
						}
						
					}
					
				}
				
				self::move_or_merge_page( $post_ID, $node );
				
				if( ! empty( self::$map_fieldmap[ 'post_title' ] ) && get_the_title( $post_ID ) != self::$map[ $key ][ self::$map_fieldmap[ 'post_title' ] ] ) {
					
					wp_update_post(
						array(
							'ID' => $post_ID,
							'post_title' => self::$map[ $key ][ self::$map_fieldmap[ 'post_title' ] ]
						)
					);
					
				}
				
				break;
			case 'nomerge':  // Keep node content at specified URL if possible, letting WP generate a different slug if needed
				
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
				
				if( ! empty( self::$map_fieldmap[ 'post_title' ] ) && get_the_title( $post_ID ) != self::$map[ $key ][ self::$map_fieldmap[ 'post_title' ] ] ) {
					
					wp_update_post(
						array(
							'ID' => $post_ID,
							'post_title' => self::$map[ $key ][ self::$map_fieldmap[ 'post_title' ] ]
						)
					);
					
				}
				
				break;
			default:
				
				// If the action is the name of a different post type, convert the post
				$post_types = get_post_types( '', 'names' );
				
				$post_type = apply_filters(
					'map_nodes_change_post_type',
					strtolower( self::$map[ $key ][ self::$map_fieldmap[ 'action' ] ] ),
					$post_ID,
					$node
				);
				
				if( in_array( $post_type, $post_types ) ) {
					
					$update_result = wp_update_post(
						array(
							'ID'        => $post_ID,
							'post_type' => $post_type
						)
					);
					
					do_action(
						'map_nodes_after_change_post_type',
						$post_type,
						strtolower( self::$map[ $key ][ self::$map_fieldmap[ 'action' ] ] ),
						$post_ID,
						$node
					);
					
					if( ! $update_result )
						echo_now( 'ERROR changing post type for post ID: ' . $post_ID );
					
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
			echo_now( 'No map file found -- skipping mapping phase' );
			self::$has_map = false;
		}
		
		return self::$has_map;
	}
	
	/**
	 * Generate index for final URL array
	 */
	private static function get_key( $post_ID, $node = array() ) {
		
		$drupal_db = get_post_meta(
			$post_ID,
			'_drupal_database',
			true
		);
		
		if( ! $drupal_db )
			return false;
		
		if( ! empty( $node ) ) {
			$drupal_nid = (int)$node['nid'];
		} else {
			$drupal_nid = (int)get_post_meta(
				$post_ID,
				'_drupal_nid',
				true
			);
		}
		
		$key = $drupal_db .'-'.$drupal_nid;
		
		return $key;
		
	}
	
	/**
	 * Load the map file containing URLs to convert
	 */
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
				
				$line = array_map( 'trim', $line );
				
				$key = $line[ self::$map_fieldmap[ 'source_db' ] ] . '-' . $line[ self::$map_fieldmap[ 'source_nid' ] ];
				
				self::$map[ $key ] = $line;
				
			}
			
			fclose( self::$map_file );
			
		} else {
			
			// TODO: Streaming mode -- this will take forever but will scale
			// Probably build an index of the file with database names and node IDs
			// Then load chunks when a matching node is processed.
			// This ended up not being needed.
			
		}
		
	}
	
	/**
	 * Update the page to its specified final URL, merging with other pages if needed
	 */
	private static function move_or_merge_page( $post_ID, $node = array() ) {
		
		$key = self::get_key( $post_ID, $node );
		
		if( $result = get_page_by_path( untrailingslashit( self::$map[ $key ][ self::$map_fieldmap[ 'final_url' ] ] ) ) ) {
			
			// If the page is already in place, we are done
			if( $result->ID == $post_ID )
				return;
			
			// A page already exists at the specified URL - merge the contents
			
			$new_post = get_post( $post_ID );
			
			$content = apply_filters(
				'map_nodes_merge_content',
				$result->post_content . "<br><br>" . $new_post->post_content,
				$new_post,
				$result
			);
			
			$updated_post = wp_update_post(
				array(
					'ID'           => $result->ID,
					'post_content' => $content
				)
			);
			
			if( ! $updated_post )
				echo 'ERROR updating post ' . $result->ID . ' : ' . "<br>\n";
			
			// Copy aliases to the combined post for potential redirection
			$update_result = update_post_meta(
				$result->ID,
				'_drupal_aliases',
				array_filter(
					array_merge(
						(array)maybe_unserialize(
							get_post_meta( $result->ID, '_drupal_aliases', true )
						),
						(array)maybe_unserialize(
							get_post_meta( $post_ID, '_drupal_aliases', true )
						)
					)
				)
			);
			
			if( ! $update_result )
				echo_now( 'ERROR updating aliases for merged page' );
			
			// If the merged page does not have a Drupal NID, call import_node_postprocess on this one
			//   since it will be skipped later
			if( ! get_post_meta( $result->ID, '_drupal_nid', true ) ) {
				
				remove_action( 'import_node_postprocess', array( 'MapNodeURL', 'process_node' ), 1, 2 );
				do_action(
					'import_node_postprocess',
					$result->ID,
					$node
				);
				add_action( 'import_node_postprocess', array( 'MapNodeURL', 'process_node' ), 1, 2 );
				
			}
			
			// Move any child posts to the new parent
			$children = get_children(
				array(
					'post_parent' => $post_ID,
					'post_type'   => 'page'
				)
			);
			
			if( ! empty( $children ) ) {
				foreach( $children as $child_page ) {
					
					$update_result = wp_update_post(
						array(
							'ID' => $child_page->ID,
							'post_parent' => (int)$result->ID
						)
					);
					
					if( ! $update_result )
						echo_now( 'Error moving child page to merged parent' );
					
				}
				
			}
			
			// Delete the merged post
			wp_delete_post(
				$post_ID,
				true
			);
			
		} else {
			
			// No page exists at the specified URL - move this page
			$last_page = self::make_page_tree(  self::$map[ $key ][ self::$map_fieldmap[ 'final_url' ] ] );
			
			$update_result = wp_update_post(
				array(
					'ID'          => $post_ID,
					'post_name'   => basename( self::$map[ $key ][ self::$map_fieldmap[ 'final_url' ] ] ),
					'post_parent' => (int)$last_page
				)
			);
			
			if( ! $update_result )
				echo_now( 'ERRROR - There was a problem moving post: ' . $post_ID );
			
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
			
			if( $page = get_page_by_path( implode( '/', array_slice( $path_parts, 0, $x ) ) ) ) {
				
				$last_page = $page->ID;

				// Make sure the parent page is at its final URL
				$parent_key = self::get_key( $page->ID );
				
				if( $parent_key ) {
				
					if( implode( '/', array_slice( $path_parts, 0, $x ) ) != untrailingslashit( self::$map[ $parent_key ][ self::$map_fieldmap[ 'final_url' ] ] )) {
						
						// If the page is supposed to be somewhere else, move it now
						
						// Move to a temporary location to prevent path paradoxes
						wp_update_post(
							array(
								'ID' => $page->ID,
								'post_name' => $page->post_name . '-temp'
							)
						);
						
						self::move_or_merge_page( $page->ID );
						
					}
					
				}
				
			}
			
			if( $page = get_page_by_path( implode( '/', array_slice( $path_parts, 0, $x ) ) ) ) {
			
				$last_page = $page->ID;
				
				do_action(
					'map_existing_parent',
					$page
				);
				
				
			} else {
				
				$last_page = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_title'   => ucwords( str_replace( '_', ' ', $path_parts[ ( $x - 1 ) ] ) ),
						'post_name'    => strtolower( str_replace( ' ','-', $path_parts[ ( $x - 1 ) ] ) ),
						'post_content' => apply_filters( 'new_parent_content', 'Created by Drupal to WP importer', $path_parts[ ( $x - 1 ) ], array() ),
						'post_status'  => 'publish',
						'post_parent'  => $last_page
					)
				);
				
				if( ! $last_page ) {
					echo 'ERROR creating parent page: ' . $path_parts[ ( $x - 1 ) ] . "<br>\n";
					return;
				}
				
			}
			
		}
		
		return $last_page;
		
	}
	
}

?>