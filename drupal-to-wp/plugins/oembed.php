<?php
/**
 * Handle oEmbeds from Drupal 7
 */

add_action( 'metadata_import_unknown_path', 'create_oembed_shortcode', 10, 2 );

function create_oembed_shortcode( $file, $post_ID ) {
	
	$file['uri'] = str_replace('youtube://v/', 'http://www.youtube.com/watch?v=', $file['uri'] );
	
	$post = get_post( $post_ID );
	
	$post_content = $post->post_content . "<br>\n" . '[embed]' . $file['uri'] . '[/embed]';
	
	$update_result = wp_update_post(
		array(
			'ID'           => $post_ID,
			'post_content' => $post_content
		)
	);
	
}

?>