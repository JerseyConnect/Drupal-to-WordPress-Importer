<?php

require 'autoDB.php';
require 'wp/wp-load.php';
require 'settings.php';

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
	
	/**
	 * Import Drupal nodes into WP posts, mapping types as specified
	 */
	static function importNodes( $type_map ) {
		
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
			
			$post_data = array(
				'post_content'   => $node_content['body'],
				'post_date'      => date( 'Y-m-d H:i:s', $node_content['timestamp'] ),
//				'post_date_gmt'  => date( 'Y-m-d H:i:s', $node_content['timestamp'] ),
				'most_modified'  => date( 'Y-m-d H:i:s', $node_content['mod_timestamp'] ),
				'post_excerpt'   => $node_content['teaser'],
				'post_name'      => basename( $node_url ),
				'post_parent'    => 0,
				'post_password'  => '',
				'post_status'    => ( $node['status'] == 1 ? 'publish' : 'draft' ),
				'post_title'     => $node['title'],
				'post_type'      => $_POST['content_map'][ $node['type'] ]
			);
			
			$new_post_id = wp_insert_post(
				$post_data
			);
			update_post_meta( $new_post_id, '_drupal_nid', $node['nid'] );
			self::$node_to_post_map[ $node['nid'] ] = $new_post_id;
			
			// TODO: Import revisions (?)
			
		}
		
	}
	
	/**
	 * Import content field values as postmeta
	 */
	static function importMetadata() {
		
		echo 'Importing metadata...'. "<br>\n";
		
		# Add content field meta values as postmeta
		$meta_fields = drupal()->content_node_field_instance->getRecords();
		
		foreach( $meta_fields as $meta_field ) {
			
			$table_name = 'content_' . $meta_field['field_name'];
			
			if( ! isset( drupal()->$table_name ) )
				continue;
			
			$meta_records = drupal()->$table_name->getRecords();
			
			foreach( $meta_records as $meta_record ) {
				
				$value_column = $meta_field['field_name'] . '_data';
				
				if( ! array_key_exists( $meta_record['nid'], self::$node_to_post_map ) )
					continue;
				
				if( empty( $meta_record[ $value_column ] ) )
					continue;
				
//				echo 'Adding metadata for: ' . self::$node_to_post_map[ $meta_record['nid'] ] . ' - ' . $meta_record[ $value_column ] . "<br>\n";
				
				update_post_meta(
					self::$node_to_post_map[ $meta_record['nid'] ],
					'_drupal_' . $meta_field['field_name'],
					$meta_record[ $value_column ]
				);
				
			}
			
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
					'comment_approved'     => (int)$comment['status']
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
				} else {
					die('Error creating user: ' . print_r( $user_id, true ) );
				}
				
				continue;
			}
			
			add_user_meta( $user_id, '_drupal_metadata', $user['data'] );
			
		}
		
	}
	
}

/**
 * Import page starts here
 */

if(! isset( $_POST['content_map'] ) ) {
	
	?>
	<style type="text/css">
		ul {
			list-style-type: none;
		}
			li:nth-child(even) {
				background-color: #ddd;
			}
			ul>li {
				padding: 2px 0;
			}
		.original, .original-wide {
			display: inline-block;
			text-align: right;
		}
			.original {
				width: 7em;
			}
			.original-wide {
				width: 10em;
			}
	</style>
	
	<h1>STEP ONE</h1>
	
	<form method="get">
	
		<p>
			<label for="drupal_db">Select a database to import:</label>
			<input type="text" name="drupal_db" id="drupal_db" value="<?= DR_DB ?>">
		</p>
		<button type="submit">Scan Database</button>
	
	</form>
	
	<p>
		When you have selected the database containing the Drupal site you want to import, see the form below:
	</p>
	
	<hr>
	
	<h1>STEP TWO</h1>
	
	<form method="post">
	
	<p>
		<input type="checkbox" id="erase" name="erase" checked="true">
		<label for="erase">Erase WP content before importing from Drupal 6?</label>
	</p>
	
	<?php
	
	# First get all Drupal content types in use:
	$content_types = drupal()->node->type->getUniqueValues();
	
	# Allow importer to map Drupal types to WP types
	$wp_types = array_map(
		function( $value ) { 
			return '<option>' . $value . '</option>'; 
		},
		get_post_types() 
	);
	array_unshift( $wp_types, '<option value="skip">Don\'t Import</option>' );
	$wp_types = implode("\n", $wp_types);
	
	?>
	
	<h2>Content type mapping:</h2>
	<ul>
		<?php foreach( $content_types as $type ) : ?>
		<li><span class="original-wide"><?= $type ?></span> => <select name="content_map[<?= $type ?>]"><?= $wp_types ?></select></li>
		<?php endforeach; ?>
	</ul>
	
	<?php
	
	# First get all Drupal vocabularies
	$dr_vocabs = drupal()->vocabulary->name->getUniqueValues();
	
	# Allow importer to map Druapl taxonomies to WP taxonomies
	$wp_taxes = array_map(
		function( $value ) { 
			return '<option>' . $value . '</option>'; 
		},
		get_taxonomies()
	);
	array_unshift( $wp_taxes, '<option value="asis">Create New Taxonomy</option>' );
	array_unshift( $wp_taxes, '<option value="skip">Don\'t Import</option>' );
	$wp_taxes = implode("\n", $wp_taxes);
	
	?>
	
	<h2>Taxonomy mapping:</h2>
	<ul>
		<?php foreach( $dr_vocabs as $tax ) : ?>
		<li><span class="original-wide"><?= $tax ?></span> => <select name="taxonomy_map[<?= $tax ?>]"><?= $wp_taxes ?></select></li>
		<?php endforeach; ?>
	</ul>
	
	<?php

	# First get all Drupal roles
	$dr_roles = drupal()->role->name->getUniqueValues();

	# Allow importer to map Drupal roles to WP roles
	$wp_role_obj = new WP_Roles;
	$wp_roles = array_map(
		function( $value ) { 
			return '<option>' . $value . '</option>'; 
		},
		$wp_role_obj->get_names()
	);
	array_unshift( $wp_roles, '<option value="asis">Create New Role</option>' );
	array_unshift( $wp_roles, '<option value="skip">Don\'t Import</option>' );
	$wp_roles = implode("\n", $wp_roles);
	
	?>
	<h2>User role mapping:</h2>
	<ul>
		<?php foreach( $dr_roles as $role ) : ?>
		<li><span class="original-wide"><?= $role ?></span> => <select name="role_map[<?= $role ?>]"><?= $wp_roles ?></select></li>
		<?php endforeach; ?>
	</ul>

	<button type="submit">IMPORT</button>
	<?php
	
} else {
	
	echo 'Starting import...' . "<br>\n";
	
	# Clear out WordPress DB if indicated
	
	if( isset( $_POST['erase'] ) ) {
	
		wordpress()->query( 'TRUNCATE TABLE wp_comments' );
		wordpress()->query( 'TRUNCATE TABLE wp_links' );
		wordpress()->query( 'TRUNCATE TABLE wp_postmeta' );
		wordpress()->query( 'TRUNCATE TABLE wp_posts' );
		wordpress()->query( 'TRUNCATE TABLE wp_term_relationships' );
		wordpress()->query( 'TRUNCATE TABLE wp_term_taxonomy' );
		wordpress()->query( 'TRUNCATE TABLE wp_terms' );
		
		wordpress()->query( 'DELETE FROM wp_users WHERE ID > 1' );
		wordpress()->query( 'DELETE FROM wp_usermeta WHERE user_id > 1' );

	}	

	Drupal_to_WP::importNodes( $_POST['content_map'] );
	Drupal_to_WP::importMetadata();
	Drupal_to_WP::importTaxonomies( $_POST['taxonomy_map'] );
	Drupal_to_WP::importComments();
	Drupal_to_WP::importUsers( $_POST['role_map'] );

	echo "<br>" . 'All done!' . "<br>\n";

}


?>