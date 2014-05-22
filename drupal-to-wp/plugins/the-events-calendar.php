<?php

/**
 * Conversion filters for bringing Drupal events into The Events Calendar
 * 
 * This plugin may require customization based on your Drupal event types.
 * 
 * Requires The Events Calendar plugin -- http://wordpress.org/plugins/the-events-calendar/
 */

// Define a default timezone in case the WP value is not set
define( 'TEC_DEFAULT_TZ', 'America/New_York' );

if( function_exists( 'tribe_get_events' ) ) {
	add_action( 'import_post_tribe_events', array( 'TheEventsCalendarImport', 'scrape_event_metadata' ) );
	add_filter( 'map_nodes_change_post_type', array( 'TheEventsCalendarImport', 'use_tribe_events' ) );
	add_filter( 'add_cat_tax_map', array( 'TheEventsCalendarImport', 'use_tribe_categories' ), 10, 2 );
}

class TheEventsCalendarImport {
	
	public static $venues     = false;
	public static $organizers = false;
	public static $timezone   = false;
	
	/**
	 * Fill in event details based on imported Drupal metadata
	 */
	public static function scrape_event_metadata( $post_id ) {
		
		/**
		 *  Set hours for the event - source time is in UTC, but The Events Calendar expects localtime
		 */
		
		if( empty( self::$timezone ) ) {
			$tz = get_option( 'timezone_string' );
			if( empty( $tz ) )
				self::$timezone = TEC_DEFAULT_TZ;
			else
				self::$timezone = $tz;
		}
		
		$tz_src = new DateTimeZone( 'UTC' );
		$tz_dst = new DateTimeZone( self::$timezone ); // TODO: read from WP config
		
		$start_date = false;
		if( $result = get_post_meta( $post_id, '_drupal_field_event_date_value', true ) ) {
			
			$start_date = $result;
			delete_post_meta(
				$post_id,
				'_drupal_field_event_date_value'
			);
			
		} else if( $result = get_post_meta( $post_id, '_drupal_field_event_dates_value', true ) ) {
			
			$start_date = $result;
			delete_post_meta(
				$post_id,
				'_drupal_field_event_dates_value'
			);
			
		}
		
		if( ! empty( $start_date ) ) {
			$st_date = new DateTime( $start_date, $tz_src );
			$st_date->setTimezone( $tz_dst );
			
			update_post_meta(
				$post_id,
				'_EventStartDate',
				$st_date->format( 'Y-m-d H:i:s' )
			);
		}
		
		$end_date = false;
		if( $result = get_post_meta( $post_id, '_drupal_field_event_date_value2', true ) ) {
			
			$end_date = $result;
			delete_post_meta(
				$post_id,
				'_drupal_field_event_date_value2'
			);
			
		} else if( $result = get_post_meta( $post_id, '_drupal_field_event_dates_value2', true ) ) {
			
			$end_date = $result;
			delete_post_meta(
				$post_id,
				'_drupal_field_event_dates_value2'
			);
			
		}

		if( ! empty( $end_date ) ) {
			$ed_date = new DateTime( $end_date, $tz_src );
			$ed_date->setTimezone( $tz_dst );
			
			update_post_meta(
				$post_id,
				'_EventEndDate',
				$ed_date->format( 'Y-m-d H:i:s' )
			);
		}
		
		update_post_meta(
			$post_id,
			'_EventDuration',
			strtotime( $end_date ) - strtotime( $start_date ) // TODO: in rare cases this may be off by an hour
		);
		
		/**
		 * Create event venue if needed
		 */
		
		$location_value = null;
		if( $result = get_post_meta( $post_id, '_drupal_field_event_location_value', true ) ) {
			
			$location_value = $result;
			
			delete_post_meta(
				$post_id,
				'_drupal_field_event_location_value'
			);
			
		}
		$location_value = trim( htmlspecialchars( $location_value ) );
		
		$address = false;
		if( $result = get_post_meta( $post_id, '_drupal_field_event_location_map_value', true ) ) {
			
			$address = array(
				'address' => '185 West State St',
				'city'    => 'Trenton',
				'state'   => 'NJ',
				'zip'     => '08625',
				'country' => 'United States'
			);
			
			delete_post_meta(
				$post_id,
				'_drupal_field_event_location_map_value'
			);
			
		}
		
		
		if( ! self::$venues )
			self::$venues = tribe_get_venues();
		
		$venue_ID = false;
		
		foreach( self::$venues as $venue ) {
			
			if( $venue->post_title == $location_value ) {
				$venue_ID = $venue->ID;
				break;
			}
			
		}
		
		if( ! $venue_ID && ! empty( $location_value ) ) {
			
			$venue_ID = tribe_create_venue(
				array(
					'Venue'    => $location_value, // Name of the venue
					'Country'  => $address['country'],
					'Address'  => $address['address'],
					'City'     => $address['city'],
					'State'    => $address['state'],
					'Province' => '',
					'Zip'      => $address['zip'],
					'Phone'    => ''
				)
			);
			
			self::$venues = tribe_get_venues();
		}
		
		// Set event venue
		update_post_meta(
			$post_id,
			'_EventVenueID',
			$venue_ID
		);
		
		$contact_value = false;
		if( $result = get_post_meta( $post_id, '_drupal_field_event_contact_email_email', true ) ) {
			
			$contact_value = $result;
			
		}
		$contact_value = trim( htmlspecialchars( $contact_value ) );
		
		// Create event organizer if needed
		if( ! self::$organizers )
			self::$organizers = tribe_get_organizers();
		
		$organizer_ID = false;
		
		foreach( self::$organizers as $organizer ) {
			
			if( trim( $organizer->post_title ) == trim( $contact_value ) ) {
				$organizer_ID = $organizer->ID;
				break;
			}
			
		}
		
		if( ! $organizer_ID ) {
			
			$organizer_ID = tribe_create_organizer(
				array(
					'Organizer' => trim( $contact_value ),
					'Email'     => get_post_meta( $post_id, '_drupal_field_event_contact_email_email', true ),
					'Website'   => '',
					'Phone'     => ''
				)
			);
			
			self::$organizers = tribe_get_organizers();
			
		}
		
		delete_post_meta(
			$post_id,
			'_drupal_field_event_contact_email_email'
		);
		
		// Set event organizer
		update_post_meta(
			$post_id,
			'_EventOrganizerID',
			$organizer_ID
		);
		
		/**
		 * Import registration URL details
		 */
		
		$URL_value = false;
		if( $result = get_post_meta( $post_id, '_drupal_field_event_registration_url', true ) ) {
			
			update_post_meta(
				$post_id,
				'_EventURL',
				$result
			);
			
			delete_post_meta(
				$post_id,
				'_drupal_field_event_registration_url'
			);
			
		}
		
		
		/**
		 * Clean up unused event metadata
		 */
		
		delete_post_meta(
			$post_id,
			'_drupal_field_event_registration_attributes'
		);
		
	}
	
	/**
	 * If a node map specifies that a post become an 'event', make it a tribe_events post
	 */
	public static function use_tribe_events( $post_type, $post_ID = 0, $node = array() ) {
		
		if( 'event' == $post_type )
			return 'tribe_events';
		
		return $post_type;
		
	}
	
	/**
	 * If a user adds categories to an event post, make them event categories
	 */
	public static function use_tribe_categories( $taxonomy, $node_type ) {
		
		if( 'tribe_events' == $node_type ) {
			
			return 'tribe_events_cat';
			
		}
		
		return $taxonomy;
		
	}
	
}

?>