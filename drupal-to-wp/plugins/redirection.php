<?php

/**
 * Conversion filter for Redirection
 * Create redirects for all Drupal aliases to the new URL for a post or page
 */

define( 'REDIRECTIONIMPORT_GROUP_NAME', 'Drupal Conversion' );

// Only proceed if Redirection is installed and active
if( function_exists( 'red_get_url' ) ) {
	add_action( 'erase_wp_data', array( 'RedirectionImport', 'clean_alias_redirects' ) );
	add_action( 'import_node_postprocess', array( 'RedirectionImport', 'build_alias_redirects' ), 10, 2 );
}

class RedirectionImport {
	
	private static $redirect_group_id = false;
	
	public static function build_alias_redirects( $post_ID, $node ) {
		
		$group = self::get_import_group();
		
		// If we can't get the group for some reason, just bail
		if( ! $group )
			return;
		
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
					'target'     => get_permalink( $post_ID ),
					'group'      => $group,
					'match'      => 'url',
					'red_action' => 'url'
				)
			);
			
		}
		
	}
	
	public static function get_import_group() {
		
		if( self::$redirect_group_id )
			return self::$redirect_group_id;
		
		global $wpdb;
		
		$group_name = $wpdb->prefix . 'redirection_groups';
		
		$group_ID = wordpress()->$group_name->id->getValue(
			array(
				'name' => REDIRECTIONIMPORT_GROUP_NAME
			)
		);
		
		if( $group_ID ) {
			self::$redirect_group_id = (int)$group_ID;
			return $group_ID;
		}
		
		$group_ID = Red_Group::create(
			array(
				'name'      => REDIRECTIONIMPORT_GROUP_NAME,
				'module_id' => 1
			)
		);

		self::$redirect_group_id = (int)$group_ID;
		return $group_ID;
	}
	
	public static function clean_alias_redirects() {
		
		echo_now( 'Clearing old redirects...' );
		
		global $wpdb;
		
		$group_table_name = $wpdb->prefix . 'redirection_groups';
		
		$group_ID = wordpress()->$group_table_name->id->getValue(
			array(
				'name' => REDIRECTIONIMPORT_GROUP_NAME
			)
		);
		
		if( $group_ID ) {
			
			// Waiting on fix for bug with this function
//			Red_Item::delete_by_group( $group_ID );
			
			$item_table = $wpdb->prefix . 'redirection_items';

			wordpress()->$item_table->delete(
				array(
					'group_id' => $group_ID
				)
			);
			
		}
		
	}
	
}

?>