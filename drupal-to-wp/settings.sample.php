<?php
/**
 * Constants for accessing the Drupal database
 */
define( 'DR_HOST', 'localhost' );

if( isset( $_REQUEST['drupal_db'] ) ) {
	define( 'DR_DB', $_REQUEST['drupal_db'] );
} else {
	define( 'DR_DB', 'drupal' );
}
define( 'DR_USER', 'username' );
define( 'DR_PASS', 'password' );

/**
 * Path to your WordPress install
 */
define( 'WP_PATH', 'wp' );

/**
 * User to attribute all conversion operations
 */
define( 'WP_USERID', 1 );

/**
 * Constants for accessing the WordPress database -- only needed if erasing a WP site
 */
define( 'WP_HOST', 'localhost' );
define( 'WP_DB',   'wordpress' );
define( 'WP_USER', 'username' );
define( 'WP_PASS', 'password' );

?>