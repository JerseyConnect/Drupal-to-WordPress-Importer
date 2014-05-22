<?php
/**
 * Override generated parent page content, making new parent pages into listing pages.
 * Requires the List Pages Shortcode plugin -- https://wordpress.org/plugins/list-pages-shortcode/
 */

if( class_exists( 'List_Pages_Shortcode' ) ) {
	add_filter( 'new_parent_content', array( 'ParentListPage', 'generate_list' ), 1, 3 );
	add_action( 'map_existing_parent', array( 'ParentListPage', 'maybe_add_list_shortcode' ) );
}

class ParentListPage {
	
	public static function generate_list( $page_content, $parent_page_name, $node ) {
		
		return "\n" . '[child-pages depth="1"]';
		
	}
	
	public static function maybe_add_list_shortcode( $post ) {
		
		if( false === stripos( $post->post_content, '[child-pages' ) ) {
			
			$post_content = '[child-pages depth="1"]' . "\n" . $post->post_content;
			
			$update_result = wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => $post_content
				)
			);
			
			if( ! $update_result )
				echo_now( 'ERROR adding page listing to parent page' );
			
		}
		
	}
	
}

?>