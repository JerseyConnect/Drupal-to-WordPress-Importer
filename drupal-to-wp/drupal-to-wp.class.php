<?php

function drupal() {
	
	static $drupalLink;
	if( ! $drupalLink )
		$drupalLink = new AutoDB( DR_HOST, DR_DB , DR_USER, DR_PASS );
	return $drupalLink;
	
}

function wordpress() {
	
	static $wpLink;
	if( ! $wpLink )
		$wpLink = new AutoDB( WP_HOST, WP_DB , WP_USER, WP_PASS );
	return $wpLink;
	
}

class Drupal_to_WP {
	
	static $node_to_post_map = array();
	static $term_to_term_map = array();
	static $user_to_user_map = array();
	static $file_to_file_map = array();
	
	/**
	 * Import Drupal nodes into WP posts, mapping types as specified
	 * Optionally tag/categorize imported content acording to user instructions
	 */
	static function importNodes( $type_map, $node_extras ) {
		
		extract( $node_extras );
		
		echo 'Importing posts...' . "<br>\n";
		
		# Build array of types that aren't being skipped
		$import_types = array();
		$import_types =  array_map(
			function( $key, $value ) {
				if( $value != 'skip' ) 
					return $key;
			},
			array_keys( $type_map ),
			$type_map
		);
		$import_types = array_filter( $import_types, 'strlen' );
		
		$nodes = drupal()->node->getRecords(
			array(
				'type' => $import_types
			)
		);
		
		foreach( $nodes as $node ) {
			
			if( apply_filters( 'import_node_skip_node', false, $node ) )
				continue;
			
			# Get content from latest revision
			$node_content = drupal()->node_revisions->getRecord(
				array(
					'nid'  => $node['nid'],
					'DESC' => 'timestamp'
				)
			);

			# Get timestamp from earliest revision and overwrite latest record
			$node_creation = drupal()->node_revisions->getRecord(
				array(
					'nid'  => $node['nid'],
					'ASC' => 'timestamp'
				)
			);
			$node_content['mod_timestamp'] = $node_content['timestamp'];
			$node_content['timestamp'] = $node_creation['timestamp'];
			
			
			# Get slug from url_alias table
			$node_url = drupal()->url_alias->dst->getValue(
				array(
					'src' => 'node/' . $node['nid']
				)
			);
			
			if( false === $node_url || empty( $node_url ) ) {
				echo 'WARNING - No URL available for node: ' . $node['nid'];
				echo ' -- generating a slug from the title' . "<br>\n";
				$node_url = strtolower( str_replace( ' ','-', $node['title'] ) );
			}
			
			$post_author = array_key_exists( $node_content['uid'], self::$user_to_user_map ) ? self::$user_to_user_map[ $node_content['uid'] ] : 0;
			
			// If user requested a new parent page, create or locate it
			if( ! empty( $parents ) && array_key_exists( $node['type'], $parents ) && ! empty( $parents[ $node['type'] ] ) ) {
				
				$parent_page = $parents[ $node['type'] ];
				
				if( is_numeric( $parent_page ) ) {
					
					if( ! get_post( (int)$parent_page ) ) {
						$parent_page = 0;
					}
					
				} else {
					
					$page = get_page_by_path(
						untrailingslashit( strtolower( str_replace( ' ','-', $parent_page ) ) ), 
						OBJECT, 
						$_POST['content_map'][ $node['type'] ]
					);
					
					if( $page ) {
						
						$parent_page = (int)$page->ID;
						
					} else {
						
						$post_data = array(
							'post_type'    => $_POST['content_map'][ $node['type'] ],
							'post_title'   => $parent_page,
							'post_name'    => strtolower( str_replace( ' ','-', $parent_page ) ),
							'post_content' => 'Created by Drupal to WP importer' 
						);
						
						$parent_page = wp_insert_post( $post_data );
						
					}
					
				}
				
			} else {
				$parent_page = 0;
			}
			
			$post_data = array(
				'post_content'   => $node_content['body'],
				'post_date'      => date( 'Y-m-d H:i:s', $node_content['timestamp'] ),
//				'post_date_gmt'  => date( 'Y-m-d H:i:s', $node_content['timestamp'] ),
				'most_modified'  => date( 'Y-m-d H:i:s', $node_content['mod_timestamp'] ),
				'post_excerpt'   => $node_content['teaser'],
				'post_name'      => basename( $node_url ),
				'post_author'    => $post_author,
				'post_parent'    => (int)$parent_page,
				'post_password'  => '',
				'post_status'    => ( $node['status'] == 1 ? 'publish' : 'draft' ),
				'post_title'     => $node['title'],
				'post_type'      => $_POST['content_map'][ $node['type'] ]
			);
			
			$post_data = apply_filters(
				sprintf( 'import_node_pre_%s', $_POST['content_map'][ $node['type'] ] ),
				$post_data,
				$node 
			);
			
			$new_post_id = wp_insert_post(
				$post_data
			);
			update_post_meta( $new_post_id, '_drupal_nid', $node['nid'] );
			update_post_meta( $new_post_id, '_drupal_database', drupal()->dbName );
			self::$node_to_post_map[ $node['nid'] ] = $new_post_id;
			
			// Store all other URL aliases
			$aliases = drupal()->url_alias->dst->getValues(
				array(
					'src' => 'node/' . $node['nid']
				)
			);
			update_post_meta( $new_post_id, '_drupal_aliases', $aliases );
			
			// Add tags / categories if requested
			
			if( array_key_exists( $node['type'], $add_tag_map ) ) {
				wp_set_post_tags(
					$new_post_id,
					$add_tag_map[ $node['type'] ],
					true
				);
			}
			
			if( ! function_exists( 'wp_create_category' ) )
				require WP_PATH . '/wp-admin/includes/taxonomy.php';
			
			if( array_key_exists( $node['type'], $add_cat_map ) ) {
				
				$term = term_exists( $add_cat_map[ $node['type'] ], 'category' );
				if( ! $term ) {
					$term = wp_create_category( $add_cat_map[ $node['type'] ] );
				}
				
				wp_set_post_categories(
					$new_post_id,
					$term
				);
			}
			
			// TODO: Import revisions (?)
			
		}
		
	}
	
	/**
	 * Import content field values as postmeta
	 */
	static function importMetadata() {
		
		echo 'Importing metadata...'. "<br>\n";
		
		$upload_dir = wp_upload_dir();
		$searched_fields = array();
		
		# Add content field meta values as postmeta
		$meta_fields = drupal()->content_node_field_instance->getRecords();
		
		foreach( $meta_fields as $meta_field ) {
			
			// There are two ways of storing metadata -- simply (in the content_type_[node content type] table)
			//  and richly (in the content_[field name] table)
			
			$table_name = 'content_' . $meta_field['field_name'];
			
			if( in_array( $meta_field['field_name'], $searched_fields ) )
				continue;
			
			if( isset( drupal()->$table_name ) ) {
				
				// Found a content_field_name table -- add post meta from its columns
//				echo 'Table - search content table: ' . $table_name . ' for fields' . "<br>\n";
				
				$meta_records = drupal()->$table_name->getRecords();
				
				foreach( $meta_records as $meta_record ) {
					
					// Import all columns beginning with field_name
					
					if( ! array_key_exists( $meta_record['nid'], self::$node_to_post_map ) )
						continue;
					
//					echo 'Adding metadata for: ' . self::$node_to_post_map[ $meta_record['nid'] ] . ' - ' . $meta_record[ $value_column ] . "<br>\n";
					
					foreach( $meta_record as $column => $value ) {
						
						if( empty( $value ) )
							continue;
						
						if( 0 !== strpos( $column, $meta_field['field_name'] ) )
							continue;
						
						if( strpos( $column, '_fid' ) ) {
							
							if( array_key_exists( (int)$value, self::$file_to_file_map ) )
								continue;
							
							// This is a "file ID" column - create attachment entry
							
							$file = drupal()->files->getRecord( (int)$value );
							
							// Long files names will exceed guid length limit
							$guid = $upload_dir['url'] . $file['filepath'];
							if( 255 >= strlen( $guid ) )
								$guid = substr( $guid, 0, 254 ); 
							
							$attachment = array(
								'guid'           => $guid,
								'post_mime_type' => $file['filemime'],
								'post_title'     => preg_replace( '/\.[^.]+$/', '', $file['filename'] ),
								'post_status'    => 'inherit',
								'post_date'      => date('Y-m-d H:i:s', $file['timestamp'] ),
								'post_date_gmt'  => date('Y-m-d H:i:s', $file['timestamp'] )
							);
							
							$result = wp_insert_attachment(
								$attachment,
								$file['filename'],
								self::$node_to_post_map[ $meta_record['nid'] ]
							);
							
//							echo 'Found a file attachment meta value: ' . $value . ' for key:' . $column . ' and node: ' . $meta_record['nid'] . "<br>\n";
							
							self::$file_to_file_map[ (int)$value ] = $result;
							
							add_post_meta(
								$result,
								'_drupal_fid',
								$file['fid']
							);
							
							$value = $result;
						}
						
						add_post_meta(
							self::$node_to_post_map[ $meta_record['nid'] ],
							'_drupal_' . $column,
							$value
						);
						
						$searched_fields[] = $column;
						
					}
					
					
				}
			
			} else {

				// Search the content_type_[content type] table for this value
				$table_name = 'content_type_' . $meta_field['type_name'];
//				echo 'Not a table - search content_type table: ' . $table_name . "<br>\n";
				
				$meta_records = drupal()->$table_name->getRecords();
				
				foreach( $meta_records as $meta_record ) {
					
					// Import all columns beginning with field_name
					
					if( ! array_key_exists( $meta_record['nid'], self::$node_to_post_map ) )
						continue;
					
//					echo 'Adding metadata for: ' . self::$node_to_post_map[ $meta_record['nid'] ] . ' - ' . $meta_record[ $value_column ] . "<br>\n";
					
					foreach( $meta_record as $column => $value ) {
						
						if( empty( $value ) )
							continue;
						
						if( 0 !== strpos( $column, $meta_field['field_name'] ) )
							continue;
						
						if( strpos( $column, '_fid' ) ) {
							
							if( array_key_exists( (int)$value, self::$file_to_file_map ) )
								continue;
							
							// This is a "file ID" column - create attachment entry
							
							$file = drupal()->files->getRecord( (int)$value );
							
							// Long files names will exceed guid length limit
							$guid = $upload_dir['url'] . $file['filepath'];
							if( 255 >= strlen( $guid ) )
								$guid = substr( $guid, 0, 254 ); 
							
							$attachment = array(
								'guid'           => $guid,
								'post_mime_type' => $file['filemime'],
								'post_title'     => preg_replace( '/\.[^.]+$/', '', $file['filename'] ),
								'post_status'    => 'inherit',
								'post_date'      => date('Y-m-d H:i:s', $file['timestamp'] ),
								'post_date_gmt'  => date('Y-m-d H:i:s', $file['timestamp'] )
							);
							
							$result = wp_insert_attachment(
								$attachment,
								$file['filename'],
								self::$node_to_post_map[ $meta_record['nid'] ]
							);
							
//							echo 'Found a file attachment meta value: ' . $value . ' for key:' . $column . ' and node: ' . $meta_record['nid'] . "<br>\n";
							
							self::$file_to_file_map[ (int)$value ] = $result;
							
							add_post_meta(
								$result,
								'_drupal_fid',
								$file['fid']
							);
							
							$value = $result;
						}
						
						update_post_meta(
							self::$node_to_post_map[ $meta_record['nid'] ],
							'_drupal_' . $column,
							$value
						);
					}
				}
			}
			
			$searched_fields[] = $meta_field['field_name'];
				
		}
	}
	
	/**
	 * Import Drupal vocabularies and terms as taxonomies and terms
	 * Map Drupal taxonomies to WP category/tag types as specified in mapping
	 */
	static function importTaxonomies( $taxonomy_map ) {
		
		echo 'Importing categories and tags...' . "<br>\n";
		
		# Import Drupal vocabularies as WP taxonomies
		
		$vocab_map = array();
		
		$vocabs = drupal()->vocabulary->getRecords();
		
		foreach( $vocabs as $vocab ) {
			
			if( 'skip' == $taxonomy_map[ $vocab['name'] ] )
				continue;
			
			if( 'asis' == $taxonomy_map[ $vocab['name'] ] ) {
				
				echo 'Registering taxonomy for: ' . str_replace(' ','-',strtolower( $vocab['name'] ) ) . "<br>\n";
				
				$vocab_map[ (int)$vocab['vid'] ] = str_replace(' ','-',strtolower( $vocab['name'] ) );
				
				register_taxonomy(
					str_replace(' ','-',strtolower( $vocab['name'] ) ),
					array( 'post', 'page' ),
					array(
						'label'        => $vocab['name'],
						'hierarchical' => (bool)$vocab['hierarchy']
					)
				);
				
			} else {
				
				echo 'Converting taxonomy: ' .  str_replace(' ','-',strtolower( $vocab['name'] ) ) . ' to: ' . $taxonomy_map[ $vocab['name'] ] . "<br>\n";
				$vocab_map[ (int)$vocab['vid'] ] = $taxonomy_map[ $vocab['name'] ];
				
			}
		}
		
		# Import Drupal terms as WP terms
		
		$term_vocab_map = array();
		
		$terms = drupal()->term_data->getRecords();
		
		foreach( $terms as $term ) {
			
			$parent = drupal()->term_hierarchy->parent->getValue(
				array(
					'tid' => $term['tid']
				)
			);
			
			if( ! array_key_exists( (int)$term['vid'], $vocab_map ) )
				continue;

			$term_vocab_map[ $term['tid'] ] = $vocab_map[ (int)$term['vid'] ];
			
//			echo 'Creating term: ' . $term['name'] . ' from: ' . $term['tid'] . "<br>\n";
			
			$term_result = wp_insert_term(
				$term['name'],
				$vocab_map[ (int)$term['vid'] ],
				array(
					'description' => $term['description'],
					'parent' => (int)$parent
				)
			);
			
			if( is_wp_error( $term_result ) ) {
				echo 'WARNING - Got error creating term: ' . $term['name']; 
				
				if( in_array( 'term_exists', $term_result->get_error_codes() ) ) {
					
					echo ' -- term already exists as: ' . $term_result->get_error_data() . "<br>\n";
					$term_id = $term_result->get_error_data();
					
				} else {
					
					echo ' -- error was: ' . print_r( $term_result, true ) . "<br>\n";
					
				}
				continue;
			}
			
			$term_id = $term_result['term_id'];
			
			self::$term_to_term_map[ (int)$term['tid'] ] = $term_id;
			
		}
		
		# Attach terms to posts
		
		$term_assignments = drupal()->term_node->getRecords();
		
		foreach( $term_assignments as $term_assignment ) {
			
			if( ! array_key_exists( $term_assignment['tid'], $term_vocab_map ) )
				continue;

			if( ! array_key_exists( (int)$term_assignment['tid'], self::$term_to_term_map ) )
				continue;
			
			if( ! array_key_exists( $term_assignment['nid'], self::$node_to_post_map ) )
				continue;
			
//			echo 'Adding term: ' . (int)$term_assignment['tid'] . ' from: ' . self::$term_to_term_map[ (int)$term_assignment['tid'] ] . ' to: ' . self::$node_to_post_map[ $term_assignment['nid'] ] . "<br>\n";
			
			$term_result = wp_set_object_terms(
				self::$node_to_post_map[ $term_assignment['nid'] ],
				array( self::$term_to_term_map[ (int)$term_assignment['tid'] ] ),
				$term_vocab_map[ $term_assignment['tid'] ],
				true
			);
			
			if( is_wp_error( $term_result ) )
				die( 'Got error setting object term: ' . print_r( $term_result, true ) );

			if( empty( $term_result ) )
				die('Failed to set object term properly.');

		}
		
	}
	
	/**
	 * Import Drupal comments as WP comments
	 */
	static function importComments() {
		
		echo 'Importing comments...' . "<br>\n";
		
		$comments = drupal()->comments->getRecords();
		
		foreach( $comments as $comment ) {
			
			if( ! array_key_exists( $comment['nid'], self::$node_to_post_map ) )
				continue;
			
			$comment_author = array_key_exists( $comment['uid'], self::$user_to_user_map ) ? self::$user_to_user_map[ $comment['uid'] ] : 0;
			
			wp_insert_comment(
				array(
					'comment_post_ID'      => self::$node_to_post_map[ $comment['nid'] ],
					'comment_author'       => $comment['name'],
					'comment_author_email' => $comment['mail'],
					'comment_author_url'   => $comment['homepage'],
					'comment_content'      => $comment['comment'],
					'comment_parent'       => intval( $comment['thread'] ),
					'comment_author_IP'    => $comment['hostname'],
					'comment_date'         => date( 'Y-m-d H:i:s', $comment['timestamp'] ),
					'comment_approved'     => (int)$comment['status'],
					'user_id'              => $comment_author
				)
			);
			
		}
		
	}
	
	/**
	 * Import Drupal users as WP users
	 */
	static function importUsers( $role_map ) {

		echo 'Importing users...' . "<br>\n";

		# Generate a pseudo-random password - CHANGE YOUR PASSWORDS AFTER IMPORT
		$password = 'password';
		for( $x = 0; $x < strlen( $password ); $x++ ) {
			$password{$x} = chr( ord( $password{$x} ) + rand(-25,5) );
		}
		
		echo '<strong>IMPORTANT</strong> - Importing users with password: <code>' . $password . '</code> - <b>WRITE THIS DOWN</b>.' . "<br>\n";

		# Get all roles and index by rid
		$roles = drupal()->role->getRecords();

		$drupal_roles = array();
		foreach( $roles as $role ):
			$drupal_roles[ (int)$role['rid'] ] = $role['name'];
		endforeach;
				
		$users = drupal()->users->getRecords();
		
		foreach( $users as $user ) {
			
			$user_role = drupal()->users_roles->rid->getValue(array(
				'uid' => $user['uid']
			));
			
			# Skip users who don't have a role
			if( false === $user_role )
				continue;
			
			if( 'skip' == $role_map[ $drupal_roles[ $user_role ] ] )
				continue;
			
			if( 'asis' == $role_map[ $drupal_roles[ $user_role ] ] ) {
				
				# TODO: Create the Drupal role first
				
			}
			
//			echo 'Creating user: ' . $user['name'] . ' with role: ' . $role_map[ $drupal_roles[ $user_role ] ] . "<br>\n";
			
			$user_id = wp_insert_user(
				array(
					'user_login'      => $user['name'],
					'user_pass'       => $password,
					'user_email'      => $user['mail'],
					'user_registered' => date( 'Y-m-d H:i:s', $user['created'] ),
					'role'            => strtolower( $role_map[ $drupal_roles[ $user_role ] ] )
				)
			);
			
			if( is_wp_error( $user_id ) ) {
				
				echo 'WARNING - Got error creating user: ' . $user['name'];
				
				if( in_array( 'existing_user_login', $user_id->get_error_codes() ) ) {
					echo ' -- user already exists' . "<br>\n";
					
					$wpuser = get_user_by( 'login', $user['name'] );
					self::$user_to_user_map[ $user['uid'] ] = $wpuser->ID;
					
				} else {
					die('Error creating user: ' . print_r( $user_id, true ) );
				}
				
				continue;
			}
			
			self::$user_to_user_map[ $user['uid'] ] = $user_id;
			
			add_user_meta( $user_id, '_drupal_metadata', $user['data'] );
			
		}
		
	}
	
	/**
	 * Import uploads from sites/*? folders into UPLOADS
	 */
	static function importUploads() {
		
		$wp_upload_dir = wp_upload_dir();
		$wp_upload_dir = $wp_upload_dir['path'];
		
		$dr_upload_dirs = drupal()->variable->value->getValues(
			array(
				'name' => 'file_%'
			)
		);

		// Locate files based on upload vars		

		$files = drupal()->files->getAllRecords();
		foreach( $files as $file ) {
			
			// Only transfer files referenced by a node
			
			
		}
		
		
		
	}
	
	/**
	 * Allow plugins to perform processing on imported nodes AFTER metadata, comments, etc. have been poulated
	 */
	static function postProcessNodes( $type_map ) {
		
		# Build array of types that aren't being skipped
		$import_types = array();
		$import_types =  array_map(
			function( $key, $value ) {
				if( $value != 'skip' ) 
					return $key;
			},
			array_keys( $type_map ),
			$type_map
		);
		$import_types = array_filter( $import_types, 'strlen' );
		
		$nodes = drupal()->node->getRecords(
			array(
				'type' => $import_types
			)
		);
		
		foreach( $nodes as $node ) {
			
			if( apply_filters( 'import_node_skip_node', false, $node ) )
				continue;
			
			do_action(
				sprintf( 'import_post_%s', $_POST['content_map'][ $node['type'] ] ),
				self::$node_to_post_map[ $node['nid'] ],
				$node 
			);
			
		}
		
	}
	
}

?>