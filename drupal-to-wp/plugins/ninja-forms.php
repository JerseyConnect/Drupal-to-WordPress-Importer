<?php
/**
 * Convert Drupal webforms to Ninja Forms
 * Create forms for all Drupal webforms, copying over field data and submissions
 * NOTE: unlike other converter plugins, this expects the target post_type to be 'page'
 *   since Ninja Forms are embedded in a page. This converter will embed the
 *   ninja-form shortcode at the end of the page's content
 * 
 * Note: this plugin uses closures, so PHP 5.3+ is required.
 * 
 * Requires the Ninja Forms plugin -- https://wordpress.org/plugins/ninja-forms/
 * 
 */

// Only proceed if Ninja Forms is installed and active
if( function_exists( 'nf_get_settings' ) ) {
	add_action( 'erase_wp_data_after', array( 'NinjaFormsImport', 'clean_forms' ) );
	add_action( 'import_node_postprocess', array( 'NinjaFormsImport', 'build_forms_and_submissions' ), 10, 2 );
}

class NinjaFormsImport {
	
	/**
	 * Map Drupal webform types to Ninja Forms field types
	 */
	static private $fieldtype_map = array(
		'textfield' => '_text',
		'select'    => '_list',
		'email'     => '_text',
		'fieldset'  => '_desc',
		'date'      => '_text',
		'textarea'  => '_textarea'
	);
	
	static public $default = null;
	
	/**
	 * Delete any existing forms and submissions
	 *   if erase WP data is selected
	 * NOTE: preserve the sample form?
	 */
	static public function clean_forms() {
		
		echo_now( 'Clearing old Ninja Forms...' );
		
		global $wpdb;
		
		$forms = ninja_forms_get_all_forms();
		
		foreach( $forms as $form ) {
			
			ninja_forms_delete_form( $form['id'] );
			
			$wpdb->query($wpdb->prepare("DELETE FROM " . NINJA_FORMS_FIELDS_TABLE_NAME . " WHERE form_id = %d", $form['id'] ), ARRAY_A);
			
			// TODO: delete submissions
			$wpdb->query($wpdb->prepare("DELETE FROM " . NINJA_FORMS_SUBS_TABLE_NAME . " WHERE form_id = %d", $form['id'] ), ARRAY_A);
			
		}

		wordpress()->query( 'TRUNCATE TABLE ' . NINJA_FORMS_TABLE_NAME );
		wordpress()->query( 'TRUNCATE TABLE ' . NINJA_FORMS_SUBS_TABLE_NAME );
		wordpress()->query( 'TRUNCATE TABLE ' . NINJA_FORMS_FIELDS_TABLE_NAME );
		
	}
	
	
	/**
	 * If the post was imported from a webform, create
	 *   fields and submissions
	 */
	static public function build_forms_and_submissions( $post_ID, $node ) {
		
		$field_map     = array(); // Map Drupal cid to Ninja Forms field ID
		$fieldname_map = array(); // Map Drupal form_key to Ninja Forms field ID
		
		if( 'webform' == $node['type'] ) {
			
			echo_now('Found a form -- building a new Ninja Form');
			
			// Get email data
			$form_options = drupal()->webform_emails->getRecords(
				array(
					'nid' => $node['nid']
				)
			);
			
			// Build the form and get form ID
			
			$form_data = array();
			
			$form_data['form_title'] = get_the_title( $post_ID );
			$form_data['success_msg'] = get_post_meta(
				$post_ID,
				'_drupal_confirmation',
				true
			);
			
			foreach( $form_options as $option ) {
				
				if( 'default' == $option['subject'] ) {
					$option['subject'] = unserialize( drupal()->variable->value->getValue( array( 'name' => 'webform_default_subject' ) ) );
				}
				
				if( 'default' == $option['from_name'] ) {
					$option['from_name'] = unserialize( drupal()->variable->value->getValue( array( 'name' => 'webform_default_from_name' ) ) );
				}
				
				if( 'default' == $option['from_address'] ) {
					$option['from_address'] = unserialize( drupal()->variable->value->getValue( array( 'name' => 'webform_default_from_address' ) ) );
				}
				
				if( 'default' == $option['template'] ) {
					$option['template'] = '%title <br> %email_values';
				}
				
				if( ! is_numeric( $option['email'] ) ) {
					// Submission email
					
					$form_data['admin_subject']   = $option['subject'];
					$form_data['admin_email_msg'] = $option['template'];
					
					$form_data['admin_mailto']    = array(
						$option['email']
					);
					
				} else {
					// Confirmation email
					
					$form_data['user_subject']   = $option['subject'];
					$form_data['user_email_msg'] = $option['template'];
					
					$form_data['email_from_name'] = $option['from_name'];
					$form_data['email_from']      = $option['from_address'];
				}
			}
			
			$form_ID = self::create_ninja_form(
				'new',
				$form_data
			);
			
			if( ! $form_ID ) {
				echo_now( 'Error creating new Ninja Form' );
				return;
			}
			
			// Update page with form shortcode
			$post = get_post( $post_ID );
			
			$update_result = wp_update_post(
				array(
					'ID' => $post_ID,
					'post_content' => $post->post_content . sprintf( "<br>\n[ninja_forms_display_form id=%d]", $form_ID )
				)
			);
			
			if( ! $update_result ) {
				echo_now( 'Error updating form page' );
				return;
			}
			
			// Build form fields
			
			$form_fields = drupal()->webform_component->getRecords(
				array(
					'nid'    => $node['nid'],
					'weight' => 'ASC'
				)
			);
			
			foreach( $form_fields as $field ) {
				
				self::$default = null;
				
				$field['extras'] = unserialize( $field['extra'] );
				
				$field_data = array(
					'type'  => self::$fieldtype_map[ $field['type'] ],
					'order' => $field['weight'],
					'data'  => array(
						'label'     => $field['name'],
						'label_pos' => 'left',
						'req'       => ( $field['mandatory'] ? '1' : '0' ), // Is the field required
					),
					'fav_id' => 0,
					'def_id' => 0 // Default value?
				);
				
				// If the webform field is being converted to a description, set the default value
				if( '_desc' == self::$fieldtype_map[ $field['type'] ] ) {
					
					$field_data['data'] = array_merge(
						$field_data['data'],
						array(
							'default_value' => $field['name'],
						)
					);
					
				}
				
				// If the webform field has a description, copy it over
				if( array_key_exists( 'description', $field['extras'] ) ) {
					
					$field_data['data'] = array_merge(
						$field_data['data'],
						array(
							'show_desc' => 1,
							'desc_pos'  => 'after_everything',
							'desc_text' => $field['extras']['description']
						)
					);
					
				}
				
				// If the webform field has items, format them and copy over
				if( array_key_exists( 'items', $field['extras'] ) ) {
					
					self::$default = $field['value'];
					
					$items = array_map(
						function( $val ) {
							
							if( strpos( $val, '|') ) {
								list( $value, $label ) = explode( '|', $val );
							} else {
								list( $value, $label ) = array( $val, $val );
							}
							
							return array(
								'label'    => trim( $label ),
								'value'    => trim( $value ),
								'calc'     => '',
								'selected' => ( trim($value) == NinjaFormsImport::$default ? '1' : '0' )
							);
						},
						explode("\n", $field['extras']['items'])
					);
					
					if( 0 == $field['extras']['aslist'] ) {
						// Radio buttons (maybe checkboxes?)
						if( ! empty( $field['extras']['multiple'] ) )
							$list_type = 'checkbox';
						else
							$list_type = 'radio';
					} else {
						// Select box
						if( ! empty( $field['extras']['multiple'] ) )
							$list_type = 'multi';
						else
							$list_type = 'dropdown';
					}
					
					$field_data['data'] = array_merge(
						$field_data['data'],
						array(
							'list'  => array(
								'options' => $items
							),
							'list_type' => $list_type,
							'multi_size' => '5',
							'list_show_value' => '1'
						)
					);
					
				}
				
				// If the field appears to hold a "State" value, mark as state
				if( false !== stripos( $field['name'], 'State' ) ) {
					
					$field_data['data'] = array_merge(
						$field_data['data'],
						array(
							'user_state' => '1'
						)
					);
					
				}
				
				// If the field appears to hold an email address, mark as email
				if( preg_match( '/e(\-)?mail/i', $field['name'] ) ) {
					
					$field_data['data'] = array_merge(
						$field_data['data'],
						array(
							'email'      => '1',
							'user_email' => '1',
							'send_email' => '1'
						)
					);
					
				}
				
				// If the webform field was a date, set the Datepicker flag
				if( 'date' == $field['type'] ) {

					$field_data['data'] = array_merge(
						$field_data['data'],
						array(
							'datepicker'      => '1',
						)
					);
					
				}
				
				// Before inserting, serialize the 'data' key
				$field_data['data'] = serialize( $field_data['data'] );
				
				$field_ID = ninja_forms_insert_field(
					$form_ID,
					$field_data
				);
				
				$field_map[ $field['nid'] . '-' . $field['cid'] ] = $field_ID;
				$fieldname_map[ $field['form_key'] ] = $field_ID;
				
			}
			
			// Generate a submit button once all fields have been created
			$submit_button_data = array(
				'type'  => '_submit',
				'data'  => array(
					'label'     => get_post_meta( $post_ID, '_drupal_submit_text', true )
				),
				'fav_id' => 0,
				'def_id' => 0 // Default value?
			);
			$submit_button_data['data'] = serialize( $submit_button_data['data'] );
			
			ninja_forms_insert_field(
				$form_ID,
				$submit_button_data
			);
			
			// Build entries / submissions
			$submissions = drupal()->webform_submissions->getRecords(
				array(
					'nid' => $node['nid']
				)
			);
			
			foreach( $submissions as $submission ) {
				
				$sub_fields = array(
					'user_id' => 0,
					'form_id' => $form_ID,
					'status'  => 1,
					'action'  => 'submit',
					'data'    => array(),
					'date_updated' => date( 'Y-m-d H:i:s', $submission['submitted'] )
				);
				
				$submission_data = drupal()->webform_submitted_data->getRecords(
					array(
						'nid' => $node['nid'],
						'sid' => $submission['sid']
					)
				);
				
				foreach( $submission_data as $sub_data ) {
					
					$sub_field = array(
						'field_id'   => $field_map[ $sub_data['nid'] . '-' . $sub_data['cid'] ],
						'user_value' => $sub_data['data']
					);
					
					$sub_fields['data'][] = $sub_field;
					
				}
				
				$sub_fields['data'] = serialize( $sub_fields['data'] );
				
				$sub_ID = ninja_forms_insert_sub(
					$sub_fields
				);
				
				if( ! $sub_ID ) {
					echo_now( 'Error creating submission record' );
					return;
				}
				
			}
			
			// Return to form and update email template with field names
			$form = ninja_forms_get_form_by_id( $form_ID );
			
			$admin_email = $form_data['admin_email_msg'];
			
			if( array_key_exists( 'user_email_msg', $form_data ) ) {
				
				$user_email  = $form_data['user_email_msg'];
				
				if( preg_match_all( '/%(value|email)\[(.*?)\]/', $user_email, $fields, PREG_SET_ORDER ) ) {
					
					foreach( $fields as $field ) {
						$user_email = str_replace( $field[0], sprintf( '[ninja_forms_field id=%d]', $fieldname_map[ $field[2] ] ), $user_email );
					}
					
				}

				$form['data'] = array_merge(
					$form['data'],
					array(
						'user_email_msg'  => $user_email,
					)
				);
					
			}
			
			ninja_forms_update_form(
				array(
					'update_array' => array(
						'data' => serialize( $form['data'] )
					),
					'where'        => array(
						'id' => $form_ID
					)
				)
			);
			
		}
		
	}
	
	/**
	 * A copy of ninja_forms_save_form_settings without the redirect
	 */
	private static function create_ninja_form( $form_id, $data ) {
		
		global $wpdb, $ninja_forms_admin_update_message;
		$form_row = ninja_forms_get_form_by_id( $form_id );
		$form_data = $form_row['data'];
	
		foreach ( $data as $key => $val ){
			$form_data[$key] = $val;
		}
	
		if ( empty( $form_data['admin_mailto'] ) ) {
			$form_data['admin_mailto'] = array( get_option( 'admin_email' ) );
		}
		if ( empty( $form_data['email_from_name'] ) ) {
			$form_data['email_from_name'] = get_option( 'blogname' );
		}
		if ( empty( $form_data['email_from'] ) ) {
			$form_data['email_from'] = get_option( 'admin_email' );
		}
		$data_array = array('data' => serialize( $form_data ) );

		$wpdb->insert( NINJA_FORMS_TABLE_NAME, $data_array );
		do_action( 'ninja_forms_save_new_form_settings', $wpdb->insert_id, $data );
		
		return $wpdb->insert_id;
	}
}

?>