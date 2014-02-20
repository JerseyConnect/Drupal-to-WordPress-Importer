<?php

require 'autoDB.php';
require 'settings.php';
require  WP_PATH . '/wp-load.php';
require 'drupal-to-wp.class.php';

if ( ! defined( 'UPLOADS' ) )
	define( 'UPLOADS', trailingslashit( WP_CONTENT_DIR ) . 'uploads' );

/**
 * Load plugins
 */



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
		<label for="erase">Erase WP content before importing from Drupal?</label>
	</p>
	<p>
		<input type="checkbox" id="copy_uploads" name="copy_uploads" disabled="disabled">
		<label for="copy_uploads">Copy uploaded files into your uploads folder? <code><?= UPLOADS ?></code></label>
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
		<li>
			<span class="original-wide"><?= $type ?></span>
			 => 
			<select name="content_map[<?= $type ?>]"><?= $wp_types ?></select>
			<input type="text" name="add_cat[<?= $type ?>]" placeholder="Create or add this category to all content of this type">
			<input type="text" name="add_tag[<?= $type ?>]" placeholder="Create of add this tag to all content of this type">
			<input type="text" name="parent[<?= $type ?>]"  placeholder="Set this page as the parent of all nodes of this type">
		</li>
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

	$node_extras = array(
		'add_cat_map' => $_POST['add_cat'],
		'add_tag_map' => $_POST['add_tag'],
		'parents'  => $_POST['parent']
	);

	Drupal_to_WP::importUsers( $_POST['role_map'] );
	Drupal_to_WP::importNodes( $_POST['content_map'], $node_extras );

	Drupal_to_WP::importMetadata();
	Drupal_to_WP::importTaxonomies( $_POST['taxonomy_map'] );
	Drupal_to_WP::importComments();
	
	// Uploaded files are imported as WP attachments during metadata import
	//  This dertermines whether they are copied into the WP uploads folder
	if( isset( $_REQUEST['copy_uploads'] ) ) :
		echo 'Copying uploaded files to ' . UPLOADS . '...' . "<br>\n";
		Drupal_to_WP::importUploads();
	endif;
	
	Drupal_to_WP::postProcessNodes( $_POST['content_map'] );

	echo "<br>" . 'All done!' . "<br>\n";

}


?>