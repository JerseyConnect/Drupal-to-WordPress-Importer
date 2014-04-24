<?php
/**
 * Command line converter
 */

if( 2 > $argc ) {
	die( 'No profile selected -- please specify a profile from the unattended directory.' );
}

function echo_now( $content ) {
	echo $content . "\n";
}

ini_set( 'max_execution_time', 0 );

require 'autoDB.php';
require 'settings.php';
require  WP_PATH . '/wp-load.php';
require 'drupal-to-wp.class.php';

if( defined( 'WP_USERID' ) )
	wp_set_current_user( WP_USERID );

/**
 * Load plugins
 */

foreach( glob( 'plugins/*.php' ) as $plugin ) {
	include $plugin;
}

$profile = $argv[1];

require dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'unattended' . DIRECTORY_SEPARATOR . $profile . '.php';

foreach( $import as $site_name => $site ) {
	
	if( 'erase' == $site_name ) {
		
		echo 'Clearing current WordPress data...';
		Drupal_to_WP::eraseWPData();
		
	} else {

		echo 'Starting conversion of: ' . $site_name . "\n";
		
		// Re-point drupal DB handler
		drupal( $site_name );
		
		Drupal_to_WP::importUsers( $site['role_map'] );
		Drupal_to_WP::importNodes( $site['content_map'], $site['node_extras'] );
		
		Drupal_to_WP::importMetadata();
		Drupal_to_WP::importTaxonomies( $site['taxonomy_map'] );
		Drupal_to_WP::importComments();
		
		Drupal_to_WP::postProcessNodes( $site['content_map'] );
		Drupal_to_WP::postProcessSite();
		
	}
	
	
}

?>