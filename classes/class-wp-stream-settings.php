<?php
/**
 * Settings class
 *
 * @author X-Team <x-team.com>
 * @author Shady Sharaf <shady@x-team.com>
 */
class WP_Stream_Settings {

	/**
	 * Settings key/identifier
	 */
	const KEY = 'wp_stream';

	/**
	 * Settings key/identifier
	 */
	const NETWORK_KEY = 'wp_stream_network';

	/**
	 * Default Settings key/identifier
	 */
	const DEFAULTS_KEY = 'wp_stream_defaults';

	/**
	 * Plugin settings
	 *
	 * @var array
	 */
	public static $options = array();

	/**
	 * Option key for current screen
	 *
	 * @var array
	 */
	public static $option_key = '';

	/**
	 * Settings fields
	 *
	 * @var array
	 */
	public static $fields = array();

	/**
	 * Public constructor
	 *
	 * @return \WP_Stream_Settings
	 */
	public static function load() {
		self::$option_key = self::get_option_key();
		self::$options    = self::get_options();

		// Register settings, and fields
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

		// Check if we need to flush rewrites rules
		add_action( 'update_option_' . self::KEY, array( __CLASS__, 'updated_option_trigger_flush_rules' ), 10, 2 );

		// Remove records when records TTL is shortened
		add_action( 'update_option_' . self::KEY, array( __CLASS__, 'updated_option_ttl_remove_records' ), 10, 2 );

		add_filter( 'wp_stream_serialized_labels', array( __CLASS__, 'get_settings_translations' ) );

		// Ajax callback function to search users
		add_action( 'wp_ajax_stream_get_users', array( __CLASS__, 'get_users' ) );

		// Ajax callback function to search IPs
		add_action( 'wp_ajax_stream_get_ips', array( __CLASS__, 'get_ips' ) );
	}

	/**
	 * Ajax callback function to search users that is used on exclude setting page
	 *
	 * @uses WP_User_Query WordPress User Query class.
	 * @return void
	 */
	public static function get_users(){
		if ( ! defined( 'DOING_AJAX' ) || ! current_user_can( WP_Stream_Admin::SETTINGS_CAP ) ) {
			return;
		}

		check_ajax_referer( 'stream_get_users', 'nonce' );

		$response = (object) array(
			'status'  => false,
			'message' => esc_html__( 'There was an error in the request', 'stream' ),
		);

		$search  = ( isset( $_POST['find'] )? wp_unslash( trim( $_POST['find'] ) ) : '' );
		$request = (object) array(
			'find' => $search,
		);

		add_filter( 'user_search_columns', array( __CLASS__, 'add_display_name_search_columns' ), 10, 3 );

		$users = new WP_User_Query(
			array(
				'search' => "*{$request->find}*",
				'search_columns' => array(
					'user_login',
					'user_nicename',
					'user_email',
					'user_url',
				),
				'orderby' => 'display_name',
				'number'  => WP_Stream_Admin::PRELOAD_AUTHORS_MAX,
			)
		);

		remove_filter( 'user_search_columns', array( __CLASS__, 'add_display_name_search_columns' ), 10 );

		if ( 0 === $users->get_total() ) {
			wp_send_json_error( $response );
		}

		$response->status  = true;
		$response->message = '';
		$response->users   = array();

		foreach ( $users->results as $key => $user ) {
			$author = new WP_Stream_Author( $user->ID );

			$args = array(
				'id'   => $author->ID,
				'text' => $author->display_name,
			);

			$args['tooltip'] = esc_attr(
				sprintf(
					__( "ID: %d\nUser: %s\nEmail: %s\nRole: %s", 'stream' ),
					$author->id,
					$author->user_login,
					$author->user_email,
					ucwords( $author->get_role() )
				)
			);

			$args['icon'] = $author->get_avatar_src( 32 );

			$response->users[] = $args;
		}

		if ( empty( $search ) || preg_match( '/wp|cli|system|unknown/i', $search ) ) {
			$author = new WP_Stream_Author( 0 );
			$response->users[] = array(
				'id'      => $author->id,
				'text'    => $author->get_display_name(),
				'icon'    => $author->get_avatar_src( 32 ),
				'tooltip' => esc_html__( 'Actions performed by the system when a user is not logged in (e.g. auto site upgrader, or invoking WP-CLI without --user)', 'stream' ),
			);
		}

		wp_send_json_success( $response );
	}

	/**
	 * Ajax callback function to search IP addresses that is used on exclude setting page
	 *
	 * @uses WP_User_Query WordPress User Query class.
	 * @return void
	 */
	public static function get_ips(){
		if ( ! defined( 'DOING_AJAX' ) || ! current_user_can( WP_Stream_Admin::SETTINGS_CAP ) ) {
			return;
		}

		check_ajax_referer( 'stream_get_ips', 'nonce' );

		global $wpdb;

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"
					SELECT distinct(`ip`)
					FROM `{$wpdb->stream}`
					WHERE `ip` LIKE %s
					ORDER BY inet_aton(`ip`) ASC
					LIMIT %d;
				",
				like_escape( $_POST['find'] ) . '%',
				$_POST['limit']
			)
		);

		wp_send_json_success( $results );
	}

	/**
	 * Filter the columns to search in a WP_User_Query search.
	 *
	 * @param array  $search_columns Array of column names to be searched.
	 * @param string $search         Text being searched.
	 * @param WP_User_Query $query	 current WP_User_Query instance.
	 *
	 * @return array
	 */
	public static function add_display_name_search_columns( $search_columns, $search, $query ){
		$search_columns[] = 'display_name';

		return $search_columns;
	}

	/**
	 * Returns the option key depending on which settings page is being viewed
	 *
	 * @return string Option key for this page
	 */
	public static function get_option_key() {
		$option_key = self::KEY;

		$current_page = wp_stream_filter_input( INPUT_GET, 'page' );

		if ( ! $current_page ) {
			$current_page = wp_stream_filter_input( INPUT_GET, 'action' );
		}

		if ( 'wp_stream_default_settings' === $current_page ) {
			$option_key = self::DEFAULTS_KEY;
		}

		if ( 'wp_stream_network_settings' === $current_page ) {
			$option_key = self::NETWORK_KEY;
		}

		return apply_filters( 'wp_stream_settings_option_key', $option_key );
	}

	/**
	 * Return settings fields
	 *
	 * @return array Multidimensional array of fields
	 */
	public static function get_fields() {
		if ( empty( self::$fields ) ) {
			$fields = array(
				'general' => array(
					'title'  => esc_html__( 'General', 'default' ),
					'fields' => array(
						array(
							'name'        => 'role_access',
							'title'       => esc_html__( 'Role Access', 'stream' ),
							'type'        => 'multi_checkbox',
							'desc'        => esc_html__( 'Users from the selected roles above will have permission to view Stream Records. However, only site Administrators can access Stream Settings.', 'stream' ),
							'choices'     => self::get_roles(),
							'default'     => array( 'administrator' ),
						),
						array(
							'name'        => 'private_feeds',
							'title'       => esc_html__( 'Private Feeds', 'stream' ),
							'type'        => 'checkbox',
							'desc'        => sprintf(
								__( 'Users from the selected roles above will be given a private key found in their %suser profile%s to access feeds of Stream Records securely. Please %sflush rewrite rules%s on your site after changing this setting.', 'stream' ),
								sprintf(
									'<a href="%s" title="%s">',
									admin_url( sprintf( 'profile.php#wp-stream-highlight:%s', WP_Stream_Feeds::USER_FEED_KEY ) ),
									esc_attr__( 'View Profile', 'stream' )
								),
								'</a>',
								sprintf(
									'<a href="%s" title="%s" target="_blank">',
									esc_url( 'http://codex.wordpress.org/Rewrite_API/flush_rules#What_it_does' ),
									esc_attr__( 'View Codex', 'stream' )
								),
								'</a>'
							),
							'after_field' => esc_html__( 'Enabled', 'stream' ),
							'default'     => 0,
						),
						array(
							'name'        => 'records_ttl',
							'title'       => esc_html__( 'Keep Records for', 'stream' ),
							'type'        => 'number',
							'class'       => 'small-text',
							'desc'        => esc_html__( 'Maximum number of days to keep activity records. Leave blank to keep records forever.', 'stream' ),
							'default'     => 90,
							'after_field' => esc_html__( 'days', 'stream' ),
						),
						array(
							'name'        => 'delete_all_records',
							'title'       => esc_html__( 'Reset Stream Database', 'stream' ),
							'type'        => 'link',
							'href'        => add_query_arg(
								array(
									'action'          => 'wp_stream_reset',
									'wp_stream_nonce' => wp_create_nonce( 'stream_nonce' ),
								),
								admin_url( 'admin-ajax.php' )
							),
							'desc'        => esc_html__( 'Warning: Clicking this will delete all activity records from the database.', 'stream' ),
							'default'     => 0,
						),
					),
				),
				'exclude' => array(
					'title' => esc_html__( 'Exclude', 'stream' ),
					'fields' => array(
						array(
							'name'        => 'exclude_rule_list',
							'title'       => esc_html__( 'Exclude Rules', 'stream' ),
							'type'        => 'exclude_rule_list',
							'desc'        => esc_html__( 'Create rules for excluding certain scenarios from ever being logged by Stream.', 'stream' ),
							'default'     => array(),
						),
						array(
							'name'        => 'hide_previous_records',
							'title'       => esc_html__( 'Visibility', 'stream' ),
							'type'        => 'checkbox',
							'desc'        => sprintf(
								esc_html__( 'When checked, all past records that match the excluded rules above will be hidden from view.', 'stream' )
							),
							'after_field' => esc_html__( 'Hide Previous Records', 'stream' ),
							'default'     => 0,
						),
					),
				),
			);
		}

		/**
		 * Filter allows for modification of options fields
		 *
		 * @param  array  array of fields
		 * @return array  updated array of fields
		 */
		return apply_filters( 'wp_stream_options_fields', $fields );
	}

	/**
	 * Returns a list of options based on the current screen.
	 *
	 * @return array Options
	 */
	public static function get_options() {
		$option_key = self::$option_key;

		$defaults = self::get_defaults( $option_key );

		if ( self::DEFAULTS_KEY === $option_key ) {
			return $defaults;
		}

		/**
		 * Filter allows for modification of options
		 *
		 * @param  array  array of options
		 * @return array  updated array of options
		 */
		return apply_filters(
			'wp_stream_options',
			wp_parse_args(
				(array) get_option( $option_key, array() ),
				$defaults
			),
			$option_key
		);
	}

	/**
	 * Iterate through registered fields and extract default values
	 *
	 * @return array Default option values
	 */
	public static function get_defaults() {
		$fields   = self::get_fields();
		$defaults = array();

		foreach ( $fields as $section_name => $section ) {
			foreach ( $section['fields'] as $field ) {
				$defaults[ $section_name.'_'.$field['name'] ] = isset( $field['default'] )
					? $field['default']
					: null;
			}
		}

		/**
		 * Filter allows for modification of options defaults
		 *
		 * @param  array  array of options
		 * @return array  updated array of option defaults
		*/
		return apply_filters(
			'wp_stream_option_defaults',
			wp_parse_args(
				(array) get_site_option( self::DEFAULTS_KEY, array() ),
				$defaults
			)
		);
	}

	/**
	 * Registers settings fields and sections
	 *
	 * @return void
	 */
	public static function register_settings() {
		$sections = self::get_fields();

		register_setting( self::$option_key, self::$option_key );

		foreach ( $sections as $section_name => $section ) {
			add_settings_section(
				$section_name,
				null,
				'__return_false',
				self::$option_key
			);

			foreach ( $section['fields'] as $field_idx => $field ) {
				if ( ! isset( $field['type'] ) ) { // No field type associated, skip, no GUI
					continue;
				}
				add_settings_field(
					$field['name'],
					$field['title'],
					( isset( $field['callback'] ) ? $field['callback'] : array( __CLASS__, 'output_field' ) ),
					self::$option_key,
					$section_name,
					$field + array(
						'section'   => $section_name,
						'label_for' => sprintf( '%s_%s_%s', self::$option_key, $section_name, $field['name'] ), // xss ok
					)
				);
			}
		}
	}

	/**
	 * Check if we have updated a settings that requires rewrite rules to be flushed
	 *
	 * @param array $old_value
	 * @param array $new_value
	 *
	 * @internal param $option
	 * @internal param string $option
	 * @action   updated_option
	 * @return void
	 */
	public static function updated_option_trigger_flush_rules( $old_value, $new_value ) {
		if ( is_array( $new_value ) && is_array( $old_value ) ) {
			$new_value = ( array_key_exists( 'general_private_feeds', $new_value ) ) ? $new_value['general_private_feeds'] : 0;
			$old_value = ( array_key_exists( 'general_private_feeds', $old_value ) ) ? $old_value['general_private_feeds'] : 0;

			if ( $new_value !== $old_value ) {
				delete_option( 'rewrite_rules' );
			}
		}
	}

	/**
	 * Compile HTML needed for displaying the field
	 *
	 * @param  array  $field  Field settings
	 * @return string         HTML to be displayed
	 */
	public static function render_field( $field ) {
		$output        = null;
		$type          = isset( $field['type'] ) ? $field['type'] : null;
		$section       = isset( $field['section'] ) ? $field['section'] : null;
		$name          = isset( $field['name'] ) ? $field['name'] : null;
		$class         = isset( $field['class'] ) ? $field['class'] : null;
		$placeholder   = isset( $field['placeholder'] ) ? $field['placeholder'] : null;
		$description   = isset( $field['desc'] ) ? $field['desc'] : null;
		$href          = isset( $field['href'] ) ? $field['href'] : null;
		$after_field   = isset( $field['after_field'] ) ? $field['after_field'] : null;
		$default       = isset( $field['default'] ) ? $field['default'] : null;
		$title         = isset( $field['title'] ) ? $field['title'] : null;
		$nonce         = isset( $field['nonce'] ) ? $field['nonce'] : null;

		if ( isset( $field['value'] ) ) {
			$current_value = array( $field['value'] );
		} else {
			$current_value = self::$options[ $section . '_' . $name ];
		}

		$option_key = self::$option_key;

		if ( is_callable( $current_value ) ) {
			$current_value = call_user_func( $current_value );
		}

		if ( ! $type || ! $section || ! $name ) {
			return;
		}

		if ( 'multi_checkbox' === $type
			&& ( empty( $field['choices'] ) || ! is_array( $field['choices'] ) )
		) {
			return;
		}

		switch ( $type ) {
			case 'text':
			case 'number':
				$output = sprintf(
					'<input type="%1$s" name="%2$s[%3$s_%4$s]" id="%2$s_%3$s_%4$s" class="%5$s" placeholder="%6$s" value="%7$s" /> %8$s',
					esc_attr( $type ),
					esc_attr( $option_key ),
					esc_attr( $section ),
					esc_attr( $name ),
					esc_attr( $class ),
					esc_attr( $placeholder ),
					esc_attr( $current_value ),
					$after_field // xss ok
				);
				break;
			case 'checkbox':
				$output = sprintf(
					'<label><input type="checkbox" name="%1$s[%2$s_%3$s]" id="%1$s[%2$s_%3$s]" value="1" %4$s /> %5$s</label>',
					esc_attr( $option_key ),
					esc_attr( $section ),
					esc_attr( $name ),
					checked( $current_value, 1, false ),
					$after_field // xss ok
				);
				break;
			case 'multi_checkbox':
				$output = sprintf(
					'<div id="%1$s[%2$s_%3$s]"><fieldset>',
					esc_attr( $option_key ),
					esc_attr( $section ),
					esc_attr( $name )
				);
				// Fallback if nothing is selected
				$output .= sprintf(
					'<input type="hidden" name="%1$s[%2$s_%3$s][]" value="__placeholder__" />',
					esc_attr( $option_key ),
					esc_attr( $section ),
					esc_attr( $name )
				);
				$current_value = (array) $current_value;
				$choices = $field['choices'];
				if ( is_callable( $choices ) ) {
					$choices = call_user_func( $choices );
				}
				foreach ( $choices as $value => $label ) {
					$output .= sprintf(
						'<label>%1$s <span>%2$s</span></label><br />',
						sprintf(
							'<input type="checkbox" name="%1$s[%2$s_%3$s][]" value="%4$s" %5$s />',
							esc_attr( $option_key ),
							esc_attr( $section ),
							esc_attr( $name ),
							esc_attr( $value ),
							checked( in_array( $value, $current_value ), true, false )
						),
						esc_html( $label )
					);
				}
				$output .= '</fieldset></div>';
				break;
			case 'select':
				$current_value = (array) self::$options[ $section . '_' . $name ];
				$default_value = isset( $default['value'] ) ? $default['value'] : '-1';
				$default_name  = isset( $default['name'] ) ? $default['name'] : 'Choose Setting';

				$output  = sprintf(
					'<select name="%1$s[%2$s_%3$s]" id="%1$s_%2$s_%3$s">',
					esc_attr( $option_key ),
					esc_attr( $section ),
					esc_attr( $name )
				);
				$output .= sprintf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $default_value ),
					checked( in_array( $default_value, $current_value ), true, false ),
					esc_html( $default_name )
				);
				foreach ( $field['choices'] as $value => $label ) {
					$output .= sprintf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $value ),
						checked( in_array( $value, $current_value ), true, false ),
						esc_html( $label )
					);
				}
				$output .= '</select>';
				break;
			case 'file':
				$output = sprintf(
					'<input type="file" name="%1$s[%2$s_%3$s]" id="%1$s_%2$s_%3$s" class="%4$s">',
					esc_attr( $option_key ),
					esc_attr( $section ),
					esc_attr( $name ),
					esc_attr( $class )
				);
				break;
			case 'link':
				$output = sprintf(
					'<a id="%1$s_%2$s_%3$s" class="%4$s" href="%5$s">%6$s</a>',
					esc_attr( $option_key ),
					esc_attr( $section ),
					esc_attr( $name ),
					esc_attr( $class ),
					esc_attr( $href ),
					esc_attr( $title )
				);
				break;
			case 'placeholder':
				$output = sprintf(
					'<input type="hidden" name="%1$s[%2$s_%3$s][]" id="%1$s_%2$s_%3$s" class="%4$s-select-placeholder" value="__placeholder__">',
					esc_attr( $option_key ),
					esc_attr( $section ),
					esc_attr( $name ),
					esc_attr( $class )
				);
				foreach ( $current_value as $value ) {
					if ( ! empty( $value ) ) {
						$output .= sprintf(
							'<input type="hidden" name="%1$s[%2$s_%3$s][]" id="%1$s_%2$s_%3$s" class="%4$s-select-placeholder" value="%5$s">',
							esc_attr( $option_key ),
							esc_attr( $section ),
							esc_attr( $name ),
							esc_attr( $class ),
							esc_attr( $value )
						);
					}
				}
				break;
			case 'exclude_rule_list' :
				$output  = '<p class="description">' . esc_html( $description ) . '</p>';
				$output .= '<table class="wp-list-table widefat fixed stream-exclude-list">';

				unset( $description );

				$heading_row = sprintf(
					'<tr>
						<th scrope="col" class="check-column manage-column">%1$s</td>
						<th scope="col" class="manage-column">%2$s</th>
						<th scope="col" class="manage-column">%3$s</th>
						<th scope="col" class="manage-column">%4$s</th>
						<th scope="col" class="manage-column">%5$s</th>
					</tr>',
					'<input class="cb-select" type="checkbox" />',
					esc_html__( 'Authors & Roles', 'stream' ),
					esc_html__( 'Contexts', 'stream' ),
					esc_html__( 'Actions', 'stream' ),
					esc_html__( 'IP Addresses', 'stream' )
				);

				$exclude_rows = array();

				if ( empty( $current_value ) || ! is_array( $current_value ) ) {
					// Create an empty row if there is nothing saved
					$current_value = array(
						array(
							'authors_and_roles' => '',
							'contexts'          => '',
							'actions'           => '',
							'ip_addresses'      => '',
						),
					);
				}

				// Reset keys, just in case
				$current_value = array_values( $current_value );

				foreach ( $current_value as $key => $exclude_row ) {
					$user_role_select = self::render_field(
						array(
							'name'    => $name . '_authors_and_roles',
							'title'   => esc_html__( 'Authors &amp; Roles', 'stream' ),
							'type'    => 'select2_user_role',
							'section' => $section,
							'class'   => 'authors_and_roles',
							'choices' => self::get_roles(),
							'default' => array(),
							'value'   => ( isset( $exclude_row['authors_and_roles'] ) ? $exclude_row['authors_and_roles'] : '' ),
						)
					);
					$connector_select = self::render_field(
						array(
							'name'    => $name . '_connectors',
							'title'   => esc_html__( 'Connectors', 'stream' ),
							'type'    => 'placeholder',
							'section' => $section,
							'class'   => 'connectors',
							'value'   => ( isset( $exclude_row['connectors'] ) ? $exclude_row['connectors'] : '' ),
						)
					);
					$context_select = self::render_field(
						array(
							'name'    => $name . '_contexts',
							'title'   => esc_html__( 'Contexts', 'stream' ),
							'type'    => 'select2',
							'section' => $section,
							'class'   => 'contexts',
							'choices' => array( __CLASS__, 'get_terms_labels' ),
							'param'   => 'context',
							'default' => array(),
							'group'   => 'connectors',
							'value'   => ( isset( $exclude_row['contexts'] ) ? $exclude_row['contexts'] : '' ),
						)
					);
					$action_select = self::render_field(
						array(
							'name'    => $name . '_actions',
							'title'   => esc_html__( 'Actions', 'stream' ),
							'type'    => 'select2',
							'section' => $section,
							'class'   => 'actions',
							'choices' => array( __CLASS__, 'get_terms_labels' ),
							'param'   => 'action',
							'default' => array(),
							'value'   => ( isset( $exclude_row['actions'] ) ? $exclude_row['actions'] : '' ),
						)
					);
					$ip_address_select = self::render_field(
						array(
							'name'    => $name . '_ip_addresses',
							'title'   => esc_html__( 'IP Addresses', 'stream' ),
							'type'    => 'select2',
							'section' => $section,
							'class'   => 'ip_addresses',
							'default' => array(),
							'nonce'   => 'stream_get_ips',
							'value'   => ( isset( $exclude_row['ip_addresses'] ) ? $exclude_row['ip_addresses'] : '' ),
						)
					);

					$exclude_rows[] = sprintf(
						'<tr class="%1$s">
							<th scrope="row" class="check-column">%2$s</td>
							<td>%3$s</td>
							<td>%4$s%5$s</td>
							<td>%6$s</td>
							<td>%7$s</td>
						</tr>',
						( 0 === $key % 2 ) ? 'alternate' : '',
						'<input class="cb-select" type="checkbox" />',
						$user_role_select,
						$connector_select,
						$context_select,
						$action_select,
						$ip_address_select
					);
				}

				$output .= '<thead>' . $heading_row . '</thead>';
				$output .= '<tfoot>' . $heading_row . '</tfoot>';
				$output .= '<tbody>' . implode( '', $exclude_rows ) . '</tbody>';

				$output .= '</table>';
				break;
			case 'select2' :
				if ( ! isset ( $current_value ) ) {
					$current_value = array();
				}

				if ( false !== ( $key = array_search( '__placeholder__', $current_value ) ) ) {
					unset( $current_value[ $key ] );
				}

				$data_values     = array();
				$selected_values = array();
				if ( isset( $field['choices'] ) ) {
					$choices = $field['choices'];
					if ( is_callable( $choices ) ) {
						$param   = ( isset( $field['param'] ) ) ? $field['param'] : null;
						$choices = call_user_func( $choices, $param );
					}
					foreach ( $choices as $key => $value ) {
						if ( is_array( $value ) ) {
							$child_values = array();
							if ( isset( $value['children'] ) ) {
								$child_values = array();
								foreach ( $value['children'] as $child_key => $child_value ) {
									$child_values[] = array( 'id' => $child_key, 'text' => $child_value );
									if ( in_array( $child_key, $current_value ) ) {
										$selected_values[] = array( 'id' => $child_key, 'text' => $child_value );
									}
								}
							}
							if ( isset( $value['label'] ) ) {
								$data_values[] = array( 'id' => $key, 'text' => $value['label'], 'children' => $child_values );
							}
						} else {
							$data_values[] = array( 'id' => $key, 'text' => $value );
							if ( in_array( $key, $current_value ) ) {
								$selected_values[] = array( 'id' => $key, 'text' => $value );
							}
						}
					}
					$class .= ' with-source';
				} else {
					foreach ( $current_value as $value ) {
						if ( '__placeholder__' === $value || '' === $value ) {
							continue;
						}
						$selected_values[] = array( 'id' => $value, 'text' => $value );
					}
				}

				$output  = sprintf(
					'<div id="%1$s[%2$s_%3$s]">',
					esc_attr( $option_key ),
					esc_attr( $section ),
					esc_attr( $name )
				);
				$output .= sprintf(
					'<input type="hidden" data-values=\'%1$s\' data-selected=\'%2$s\' value="%3$s" class="select2-select %4$s" data-placeholder="%5$s" data-select-placeholder="%6$s-%7$s-select-placeholder" %8$s %9$s />',
					esc_attr( json_encode( $data_values ) ),
					esc_attr( json_encode( $selected_values ) ),
					esc_attr( implode( ',', $current_value ) ),
					$class,
					esc_html__( 'Any', 'stream' ),
					esc_attr( $section ),
					esc_attr( $name ),
					isset( $field['group'] ) ? ' data-group-placeholder="' . $field['group'] . '-select-placeholder"' : '',
					isset( $nonce ) ? sprintf( ' data-nonce="%s"', esc_attr( wp_create_nonce( $nonce ) ) ) : ''
				);
				// to store data with default value if nothing is selected
				$output .= sprintf(
					'<input type="hidden" name="%1$s[%2$s_%3$s][]" class="%2$s-%3$s-select-placeholder" value="__placeholder__" />',
					esc_attr( $option_key ),
					esc_attr( $section ),
					esc_attr( $name )
				);
				$output .= '</div>';
				break;
			case 'select2_user_role':
				$current_value = (array)$current_value;
				$data_values   = array();

				if ( isset( $field['choices'] ) ) {
					$choices = $field['choices'];
					if ( is_callable( $choices ) ) {
						$param   = ( isset( $field['param'] ) ) ? $field['param'] : null;
						$choices = call_user_func( $choices, $param );
					}
				} else {
					$choices = array();
				}

				foreach ( $choices as $key => $role ) {
					$args  = array( 'id' => $key, 'text' => $role );
					$users = get_users( array( 'role' => $key ) );
					if ( count( $users ) ) {
						$args['user_count'] = sprintf( _n( '1 user', '%s users', count( $users ), 'stream' ), count( $users ) );
					}
					$data_values[] = $args;
				}

				$selected_values = array();
				foreach ( $current_value as $value ) {
					if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
						continue;
					}

					if ( '__placeholder__' === $value || '' === $value ) {
						continue;
					}

					if ( is_numeric( $value ) ) {
						$user              = new WP_User( $value );
						$selected_values[] = array( 'id' => $user->ID, 'text' => $user->display_name );
					} else {
						foreach ( $data_values as $role ) {
							if ( $role['id'] !== $value ) {
								continue;
							}
							$selected_values[] = $role;
						}
					}
				}

				$output  = sprintf(
					'<div id="%1$s[%2$s_%3$s]">',
					esc_attr( $option_key ),
					esc_attr( $section ),
					esc_attr( $name )
				);
				$output .= sprintf(
					'<input type="hidden" data-values=\'%1$s\' data-selected=\'%2$s\' value="%3$s" class="select2-select %5$s %6$s" data-placeholder="%7$s" data-select-placeholder="%4$s-%5$s-select-placeholder" data-nonce="%8$s" />',
					json_encode( $data_values ),
					json_encode( $selected_values ),
					esc_attr( implode( ',', $current_value ) ),
					esc_attr( $section ),
					esc_attr( $name ),
					$class,
					esc_html__( 'Any', 'stream' ),
					esc_attr( wp_create_nonce( 'stream_get_users' ) )
				);
				// to store data with default value if nothing is selected
				$output .= sprintf(
					'<input type="hidden" name="%1$s[%2$s_%3$s][]" class="%2$s-%3$s-select-placeholder" value="__placeholder__" />',
					esc_attr( $option_key ),
					esc_attr( $section ),
					esc_attr( $name )
				);
				$output .= '</div>';
				break;
		}
		$output .= ! empty( $description ) ? sprintf( '<p class="description">%s</p>', $description /* xss ok */ ) : null;

		return $output;
	}

	/**
	 * Render Callback for post_types field
	 *
	 * @param array $field
	 *
	 * @internal param $args
	 * @return void
	 */
	public static function output_field( $field ) {
		$method = 'output_' . $field['name'];

		if ( method_exists( __CLASS__, $method ) ) {
			return call_user_func( array( __CLASS__, $method ), $field );
		}

		$output = self::render_field( $field );

		echo $output; // xss okay
	}

	/**
	 * Get an array of user roles
	 *
	 * @return array
	 */
	public static function get_roles() {
		$wp_roles = new WP_Roles();
		$roles    = array();

		foreach ( $wp_roles->get_names() as $role => $label ) {
			$roles[ $role ] = translate_user_role( $label );
		}

		return $roles;
	}

	/**
	 * Get an array of registered Connectors
	 *
	 * @return array
	 */
	public static function get_connectors() {
		return WP_Stream_Connectors::$term_labels['stream_connector'];
	}

	/**
	 * Get an array of registered Connectors
	 *
	 * @return array
	 */
	public static function get_default_connectors() {
		return array_keys( WP_Stream_Connectors::$term_labels['stream_connector'] );
	}

	/**
	 * Function will return all terms labels of given column
	 *
	 * @param $column string  Name of the column
	 * @return array
	 */
	public static function get_terms_labels( $column ) {
		$return_labels = array();

		if ( isset ( WP_Stream_Connectors::$term_labels[ 'stream_' . $column ] ) ) {
			if ( 'context' === $column && isset( WP_Stream_Connectors::$term_labels[ 'stream_connector' ] ) ) {
				$connectors = WP_Stream_Connectors::$term_labels['stream_connector'];
				$contexts   = WP_Stream_Connectors::$term_labels['stream_context'];

				foreach ( $connectors as $connector => $connector_label ) {
					$return_labels[ $connector ]['label'] = $connector_label;
					foreach ( $contexts as $context => $context_label ) {
						if ( isset( WP_Stream_Connectors::$contexts[ $connector ] ) && array_key_exists( $context, WP_Stream_Connectors::$contexts[ $connector ] ) ) {
							$return_labels[ $connector ]['children'][ $context ] = $context_label;
						}
					}
				}
			} else {
				$return_labels = WP_Stream_Connectors::$term_labels[ 'stream_' . $column ];
			}

			ksort( $return_labels );
		}

		return $return_labels;
	}

	/**
	 * @param $column string name of the setting key (authors|roles|actions|ip_addresses|contexts|connectors)
	 *
	 * @return array
	 */
	public static function get_excluded_by_key( $column ) {
		$option_name = ( 'authors' === $column || 'roles' === $column ) ? 'exclude_authors_and_roles' : 'exclude_' . $column;

		$excluded_values = ( isset( self::$options[ $option_name ] ) ) ? self::$options[ $option_name ] : array();

		if ( is_callable( $excluded_values ) ) {
			$excluded_values = call_user_func( $excluded_values );
		}

		$excluded_values = wp_list_filter( $excluded_values, array( '__placeholder__' ), 'NOT' );

		if ( 'exclude_authors_and_roles' === $option_name ) {
			// Convert numeric strings to integers
			array_walk( $excluded_values,
				function ( &$value ) {
					if ( is_numeric( $value ) ) {
						$value = absint( $value );
					}
				}
			);

			$filter = ( 'roles' === $column ) ? 'is_string' : 'is_int'; // Author roles are always strings and author ID's are always integers

			$excluded_values = array_values( array_filter( $excluded_values, $filter ) ); // Reset the array keys
		}

		return $excluded_values;
	}

	/**
	 * Get translations of serialized Stream settings
	 *
	 * @filter wp_stream_serialized_labels
	 * @return array Multidimensional array of fields
	 */
	public static function get_settings_translations( $labels ) {
		if ( ! isset( $labels[ self::KEY ] ) ) {
			$labels[ self::KEY ] = array();
		}

		foreach ( self::get_fields() as $section_slug => $section ) {
			foreach ( $section['fields'] as $field ) {
				$labels[ self::KEY ][ sprintf( '%s_%s', $section_slug, $field['name'] ) ] = $field['title'];
			}
		}

		return $labels;
	}

	/**
	 * Remove records when records TTL is shortened
	 *
	 * @param array $old_value
	 * @param array $new_value
	 *
	 * @action update_option_wp_stream
	 * @return void
	 */
	public static function updated_option_ttl_remove_records( $old_value, $new_value ) {
		$ttl_before = isset( $old_value['general_records_ttl'] ) ? (int) $old_value['general_records_ttl'] : -1;
		$ttl_after  = isset( $new_value['general_records_ttl'] ) ? (int) $new_value['general_records_ttl'] : -1;

		if ( $ttl_after < $ttl_before ) {
			/**
			 * Action assists in purging when TTL is shortened
			 */
			do_action( 'wp_stream_auto_purge' );
		}
	}
}
