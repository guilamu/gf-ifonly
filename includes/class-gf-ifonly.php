<?php
/**
 * Main GFAddOn class for Gravity Forms IfOnly.
 *
 * @package GF_IfOnly
 * @license AGPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

GFForms::include_addon_framework();

class GF_IfOnly extends GFAddOn {

	protected $_version                  = GF_IFONLY_VERSION;
	protected $_min_gravityforms_version = '2.8';
	protected $_slug                     = 'gf-ifonly';
	protected $_path                     = 'gf-ifonly/gf-ifonly.php';
	protected $_full_path                = GF_IFONLY_FILE;
	protected $_title                    = 'Gravity Forms IfOnly';
	protected $_short_title              = 'IfOnly';

	private static ?GF_IfOnly $_instance = null;

	public static function get_instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	// ------------------------------------------------------------------
	// Initialization
	// ------------------------------------------------------------------

	public function init(): void {
		parent::init();

		// Server-side logic evaluation.
		add_filter( 'gform_pre_validation', array( $this, 'apply_server_logic' ) );
		add_filter( 'gform_pre_submission_filter', array( $this, 'apply_server_logic' ) );
		add_filter( 'gform_notification', array( $this, 'maybe_suppress_notification' ), 10, 3 );
		add_filter( 'gform_pre_send_email', array( $this, 'maybe_abort_suppressed_email' ), 10, 4 );
		add_filter( 'gform_confirmation', array( $this, 'maybe_override_confirmation' ), 10, 4 );

		// "does_not_contain" operator support — whitelist and server-side evaluation.
		add_filter( 'gform_is_valid_conditional_logic_operator', array( $this, 'whitelist_does_not_contain' ), 10, 2 );
		add_filter( 'gform_is_value_match', array( $this, 'evaluate_does_not_contain' ), 10, 6 );
	}

	public function init_admin(): void {
		parent::init_admin();

		// Form editor.
		add_action( 'gform_field_advanced_settings', array( $this, 'render_field_setting' ), 10, 2 );
		add_action( 'gform_editor_js', array( $this, 'editor_js_init' ) );

		// Notification & Confirmation settings.
		add_filter( 'gform_notification_settings_fields', array( $this, 'add_notification_settings_field' ), 10, 3 );
		add_filter( 'gform_confirmation_settings_fields', array( $this, 'add_confirmation_settings_field' ), 10, 3 );
		add_filter( 'gform_pre_notification_save', array( $this, 'save_notification_ifonly' ), 10, 2 );
		add_filter( 'gform_pre_confirmation_save', array( $this, 'save_confirmation_ifonly' ), 10, 2 );
	}

	public function init_frontend(): void {
		parent::init_frontend();

		// Register frontend script so it can be enqueued per-form.
		wp_register_script(
			'gf_ifonly_frontend',
			GF_IFONLY_URL . 'assets/js/gf-ifonly-frontend.js',
			array( 'jquery', 'gform_gravityforms', 'gform_conditional_logic' ),
			GF_IFONLY_VERSION,
			true
		);

		add_filter( 'gform_pre_render', array( $this, 'prepare_form_for_frontend' ), 10, 1 );
		add_filter( 'gform_register_init_scripts', array( $this, 'register_init_script' ), 10, 1 );
	}

	// ------------------------------------------------------------------
	// Scripts & Styles
	// ------------------------------------------------------------------

	public function scripts(): array {
		$scripts = array(
			// Admin — form editor.
			array(
				'handle'  => 'gf_ifonly_admin',
				'src'     => $this->get_base_url() . '/assets/js/gf-ifonly-admin.js',
				'version' => $this->_version,
				'deps'    => array( 'jquery', 'gform_gravityforms' ),
				'enqueue' => array(
					array( 'admin_page' => array( 'form_editor' ) ),
				),
			),
			// Admin — notification & confirmation settings pages.
			// MUST load in footer so the inline <script> defining gfIfOnlySettingsConfig
			// (rendered in the page body by the Settings API) runs first.
			array(
				'handle'    => 'gf_ifonly_settings',
				'src'       => $this->get_base_url() . '/assets/js/gf-ifonly-settings.js',
				'version'   => $this->_version,
				'deps'      => array( 'jquery', 'gform_gravityforms', 'gform_form_admin' ),
				'in_footer' => true,
				'enqueue'   => array(
					array( 'admin_page' => array( 'form_settings' ) ),
				),
			),
			// Frontend — logic processor (registered only; enqueued dynamically by prepare_form_for_frontend).
			array(
				'handle'    => 'gf_ifonly_frontend',
				'src'       => $this->get_base_url() . '/assets/js/gf-ifonly-frontend.js',
				'version'   => $this->_version,
				'deps'      => array( 'jquery', 'gform_gravityforms', 'gform_conditional_logic' ),
				'in_footer' => true,
				'enqueue'   => array(
					array( 'admin_page' => array( '__never__' ) ), // Never auto-enqueue; we do it manually.
				),
			),
		);

		return array_merge( parent::scripts(), $scripts );
	}

	public function styles(): array {
		$styles = array(
			array(
				'handle'  => 'gf_ifonly_admin',
				'src'     => $this->get_base_url() . '/assets/css/gf-ifonly-admin.css',
				'version' => $this->_version,
				'enqueue' => array(
					array( 'admin_page' => array( 'form_editor', 'form_settings' ) ),
				),
			),
		);

		return array_merge( parent::styles(), $styles );
	}

	// ------------------------------------------------------------------
	// Admin: Setting row + flyout container
	// ------------------------------------------------------------------

	/**
	 * Render the IfOnly accordion row inside the conditional_logic_wrapper area,
	 * plus a dedicated flyout container — matching GF's native pattern.
	 */
	public function render_field_setting( int $position, int $form_id ): void {
		// Position 550 = last position inside conditional_logic_wrapper (after submit/page CL).
		if ( 550 !== $position ) {
			return;
		}
		?>
		<div class="ifonly_advanced_setting field_setting" id="ifonly_field_setting">
			<div class="conditional_logic_wrapper" id="ifonly_cl_wrapper">
				<div id="ifonly-sidebar-field">
					<!-- Accordion rendered by JS -->
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Output the flyout container + localized strings and view templates for JS.
	 */
	public function editor_js_init(): void {
		// Read view templates.
		$views_path = GF_IFONLY_PATH . 'assets/views/';
		$views      = array(
			'accordion' => file_exists( $views_path . 'accordion.html' ) ? file_get_contents( $views_path . 'accordion.html' ) : '',
			'flyout'    => file_exists( $views_path . 'flyout.html' ) ? file_get_contents( $views_path . 'flyout.html' ) : '',
			'main'      => file_exists( $views_path . 'main.html' ) ? file_get_contents( $views_path . 'main.html' ) : '',
			'group'     => file_exists( $views_path . 'group.html' ) ? file_get_contents( $views_path . 'group.html' ) : '',
			'rule'      => file_exists( $views_path . 'rule.html' ) ? file_get_contents( $views_path . 'rule.html' ) : '',
			'option'    => file_exists( $views_path . 'option.html' ) ? file_get_contents( $views_path . 'option.html' ) : '',
			'input'     => file_exists( $views_path . 'input.html' ) ? file_get_contents( $views_path . 'input.html' ) : '',
			'select'    => file_exists( $views_path . 'select.html' ) ? file_get_contents( $views_path . 'select.html' ) : '',
		);

		$config = array(
			'views'   => $views,
			'strings' => array(
				'configure'         => esc_html__( 'Configure', 'gf-ifonly' ),
				'advancedLogic'     => esc_html__( 'Advanced Logic (IfOnly)', 'gf-ifonly' ),
				'enable'            => esc_html__( 'Enable', 'gf-ifonly' ),
				'enabled'           => __( 'Enabled', 'gf-ifonly' ),
				'disabled'          => __( 'Disabled', 'gf-ifonly' ),
				'active'            => __( 'Active', 'gf-ifonly' ),
				'inactive'          => __( 'Inactive', 'gf-ifonly' ),
				'show'              => __( 'Show', 'gf-ifonly' ),
				'hide'              => __( 'Hide', 'gf-ifonly' ),
				'thisFieldIf'       => __( 'this field if', 'gf-ifonly' ),
				'allMatch'          => __( 'all of the following match', 'gf-ifonly' ),
				'addRule'           => __( 'add another rule', 'gf-ifonly' ),
				'removeRule'        => __( 'remove this rule', 'gf-ifonly' ),
				'addGroup'          => __( 'Add rule group (OR)', 'gf-ifonly' ),
				'or'                => __( 'OR', 'gf-ifonly' ),
				'and'               => __( 'AND', 'gf-ifonly' ),
				'flyoutTitle'       => __( 'Configure Advanced Logic (IfOnly)', 'gf-ifonly' ),
				'flyoutDesc'        => __( 'Group conditions with AND within each group, OR between groups.', 'gf-ifonly' ),
				'enterValue'        => __( 'Enter a value', 'gf-ifonly' ),
				'helperText'        => __( 'To use advanced logic, first create fields that support conditional logic.', 'gf-ifonly' ),
				// Extra operators.
				'is'                => __( 'is', 'gf-ifonly' ),
				'isNot'             => __( 'is not', 'gf-ifonly' ),
				'greaterThan'       => __( 'greater than', 'gf-ifonly' ),
				'lessThan'          => __( 'less than', 'gf-ifonly' ),
				'contains'          => __( 'contains', 'gf-ifonly' ),
				'doesNotContain'    => __( 'does NOT contain', 'gf-ifonly' ),
				'startsWith'        => __( 'starts with', 'gf-ifonly' ),
				'endsWith'          => __( 'ends with', 'gf-ifonly' ),
			),
		);
		?>
		<div class="conditional_logic_flyout_container" id="ifonly_flyout_container">
			<!-- IfOnly flyout rendered by JS -->
		</div>
		<script type="text/javascript">
			var gfIfOnlyConfig = <?php echo wp_json_encode( $config ); ?>;

			// Boot the external JS now that gfIfOnlyConfig is defined.
			if ( typeof window.gfIfOnlyBoot === 'function' ) {
				window.gfIfOnlyBoot();
			}

			// Register ifonly_advanced_setting in fieldSettings for every field type
			// that already supports conditional_logic_field_setting.
			jQuery( document ).ready( function() {
				if ( typeof fieldSettings !== 'undefined' ) {
					for ( var type in fieldSettings ) {
						if ( fieldSettings[ type ].indexOf( '.conditional_logic_field_setting' ) !== -1 ) {
							fieldSettings[ type ] += ', .ifonly_advanced_setting';
						}
					}
				}

				// If external JS loaded after config but before DOM ready, boot it now.
				if ( typeof window.gfIfOnlyFlyout === 'undefined' && typeof window.gfIfOnlyBoot === 'function' ) {
					window.gfIfOnlyBoot();
				}
			});

			// Load IfOnly state when a field is selected.
			jQuery( document ).on( 'gform_load_field_settings', function( event, field, form ) {
				if ( typeof window.gfIfOnlyFlyout !== 'undefined' ) {
					window.gfIfOnlyFlyout.loadField( field, form );
				} else {
					window._gfIfOnlyPending = { field: field, form: form };
				}
			});
		</script>
		<?php
	}

	// ------------------------------------------------------------------
	// Frontend: Prepare form & init script
	// ------------------------------------------------------------------

	/**
	 * Inject placeholder conditional logic on fields that have IfOnly logic,
	 * so GF's frontend JS triggers evaluation through our filter.
	 */
	public function prepare_form_for_frontend( array $form ): array {
		$logic_data = $this->get_all_ifonly_logic( $form );

		if ( empty( $logic_data ) ) {
			return $form;
		}

		// Enqueue frontend script when form has IfOnly logic.
		wp_enqueue_script( 'gf_ifonly_frontend' );

		foreach ( $form['fields'] as &$field ) {
			$field_id = (string) $field->id;

			if ( ! isset( $logic_data[ $field_id ] ) ) {
				continue;
			}

			$ifonly = $logic_data[ $field_id ];

			// Build a fake conditional logic entry that will trigger our JS interceptor.
			$fake_rules = array(
				array(
					'fieldId'  => $field_id,
					'operator' => 'is',
					'value'    => '__ifonly_trigger',
				),
			);

			// Also add real field references so GF binds change listeners.
			foreach ( $ifonly['groups'] as $group ) {
				foreach ( $group['rules'] as $rule ) {
					$fake_rules[] = array(
						'fieldId'  => (string) $rule['fieldId'],
						'operator' => 'is',
						'value'    => '__ifonly_passthrough',
					);
				}
			}

			$field->conditionalLogic = array(
				'actionType' => $ifonly['actionType'] ?? 'show',
				'logicType'  => 'all',
				'rules'      => $fake_rules,
			);
		}

		return $form;
	}

	/**
	 * Register the GF init script that bootstraps IfOnly logic on the frontend.
	 */
	public function register_init_script( array $form ): void {
		$logic_data = $this->get_all_ifonly_logic( $form );

		if ( empty( $logic_data ) ) {
			return;
		}

		$args = array(
			'formId' => $form['id'],
			'logic'  => $logic_data,
		);

		$script = 'new GFIfOnlyFrontend( ' . wp_json_encode( $args ) . ' );';
		$slug   = 'gf_ifonly_' . $form['id'];

		GFFormDisplay::add_init_script( $form['id'], $slug, GFFormDisplay::ON_PAGE_RENDER, $script );
	}

	// ------------------------------------------------------------------
	// Server-side logic evaluation
	// ------------------------------------------------------------------

	/**
	 * Apply IfOnly logic server-side to hide fields that should be hidden,
	 * so their values are not validated/submitted.
	 */
	public function apply_server_logic( array $form ): array {
		$logic_data = $this->get_all_ifonly_logic( $form );

		if ( empty( $logic_data ) ) {
			return $form;
		}

		$entry_values = GFFormsModel::get_current_lead();

		foreach ( $form['fields'] as &$field ) {
			$field_id = (string) $field->id;

			if ( ! isset( $logic_data[ $field_id ] ) ) {
				continue;
			}

			$ifonly     = $logic_data[ $field_id ];
			$is_match   = GF_IfOnly_Logic::evaluate( $ifonly, $entry_values, $form );
			$action     = $ifonly['actionType'] ?? 'show';
			$should_show = ( 'show' === $action ) ? $is_match : ! $is_match;

			if ( ! $should_show ) {
				// Mark field as hidden — GF will skip validation for hidden fields.
				$field->conditionalLogic = array(
					'actionType' => 'show',
					'logicType'  => 'all',
					'rules'      => array(
						array(
							'fieldId'  => '0',
							'operator' => 'is',
							'value'    => '__ifonly_always_false',
						),
					),
				);
			} else {
				// Remove conditional logic so field is visible.
				$field->conditionalLogic = null;
			}
		}

		return $form;
	}

	/**
	 * Flag a notification for suppression if IfOnly logic says don't send.
	 *
	 * Setting isActive = false here has no effect because GF checks isActive
	 * before the gform_notification filter fires. Instead we set a custom flag
	 * that is picked up by maybe_abort_suppressed_email() on gform_pre_send_email.
	 */
	public function maybe_suppress_notification( array $notification, array $form, array $entry ): array {
		$ifonly = $notification['ifonlyLogic'] ?? null;

		if ( empty( $ifonly ) || empty( $ifonly['enabled'] ) ) {
			return $notification;
		}

		$is_match   = GF_IfOnly_Logic::evaluate( $ifonly, $entry, $form );
		$action     = $ifonly['actionType'] ?? 'show';
		$should_send = ( 'show' === $action ) ? $is_match : ! $is_match;

		if ( ! $should_send ) {
			$notification['ifonly_suppress'] = true;
		}

		return $notification;
	}

	/**
	 * Abort email delivery for notifications flagged by maybe_suppress_notification().
	 *
	 * The gform_pre_send_email filter is the only reliable way to prevent
	 * GF from actually sending the email, via the abort_email flag.
	 */
	public function maybe_abort_suppressed_email( array $email, string $message_format, array $notification, $entry ): array {
		if ( ! empty( $notification['ifonly_suppress'] ) ) {
			$email['abort_email'] = true;
		}

		return $email;
	}

	/**
	 * Override confirmation based on IfOnly logic.
	 *
	 * Two responsibilities:
	 *  1. If an IfOnly-enabled confirmation matches → use it (override GF's pick).
	 *  2. If GF selected an IfOnly-enabled confirmation whose conditions are NOT
	 *     met → fall back to the default confirmation.
	 *
	 * Case 2 happens because clearing native conditionalLogic (v0.9.6) causes
	 * GF to treat the confirmation as "always eligible," selecting it before
	 * the gform_confirmation filter fires.
	 */
	public function maybe_override_confirmation( $confirmation, array $form, array $entry, bool $ajax ) {
		if ( empty( $form['confirmations'] ) || ! is_array( $form['confirmations'] ) ) {
			return $confirmation;
		}

		$selected_id        = rgar( $form['confirmation'] ?? array(), 'id' );
		$selected_has_ifonly = false;
		$ifonly_winner       = null;

		foreach ( $form['confirmations'] as $conf ) {
			if ( ! empty( $conf['isDefault'] ) ) {
				continue;
			}

			$ifonly = $conf['ifonlyLogic'] ?? null;

			if ( empty( $ifonly ) || empty( $ifonly['enabled'] ) ) {
				continue;
			}

			$is_match   = GF_IfOnly_Logic::evaluate( $ifonly, $entry, $form );
			$action     = $ifonly['actionType'] ?? 'show';
			$should_use = ( 'show' === $action ) ? $is_match : ! $is_match;

			if ( rgar( $conf, 'id' ) === $selected_id ) {
				$selected_has_ifonly = true;
			}

			if ( $should_use && null === $ifonly_winner ) {
				$ifonly_winner = $conf;
			}
		}

		// An IfOnly confirmation matched — use it.
		if ( $ifonly_winner ) {
			return $this->format_confirmation( $ifonly_winner, $form, $entry );
		}

		// GF selected a confirmation with IfOnly, but conditions are not met
		// — fall back to the default confirmation.
		if ( $selected_has_ifonly ) {
			$defaults     = wp_filter_object_list( $form['confirmations'], array( 'isDefault' => true ) );
			$default_conf = reset( $defaults );
			if ( $default_conf ) {
				return $this->format_confirmation( $default_conf, $form, $entry );
			}
		}

		return $confirmation;
	}

	/**
	 * Format a confirmation array into the value expected by the gform_confirmation filter.
	 */
	private function format_confirmation( array $conf, array $form, array $entry ) {
		if ( rgar( $conf, 'type' ) === 'message' || empty( $conf['type'] ) ) {
			return GFFormDisplay::get_confirmation_message( $conf, $form, $entry );
		}

		return array( 'redirect' => GFFormDisplay::get_confirmation_url( $conf, $form, $entry ) );
	}

	// ------------------------------------------------------------------
	// Notification & Confirmation: Settings fields
	// ------------------------------------------------------------------

	/**
	 * Build the shared IfOnly config array (views + strings) for settings pages.
	 *
	 * @param string $object_type 'notification' or 'confirmation'.
	 */
	private function get_settings_config( string $object_type ): array {
		$views_path = GF_IFONLY_PATH . 'assets/views/';
		$views      = array();
		foreach ( array( 'main', 'group', 'rule', 'option', 'input', 'select' ) as $tpl ) {
			$file = $views_path . $tpl . '.html';
			$views[ $tpl ] = file_exists( $file ) ? file_get_contents( $file ) : '';
		}

		// Context-aware action labels (matching GF native CL wording).
		if ( 'notification' === $object_type ) {
			$show_label     = __( 'Send', 'gf-ifonly' );
			$hide_label     = __( 'Do not send', 'gf-ifonly' );
			$this_object_if = __( 'this notification if', 'gf-ifonly' );
		} else {
			$show_label     = __( 'Use', 'gf-ifonly' );
			$hide_label     = __( 'Do not use', 'gf-ifonly' );
			$this_object_if = __( 'this confirmation if', 'gf-ifonly' );
		}

		return array(
			'views'   => $views,
			'strings' => array(
				'show'           => $show_label,
				'hide'           => $hide_label,
				'thisFieldIf'    => $this_object_if,
				'allMatch'       => __( 'all of the following match', 'gf-ifonly' ),
				'addRule'        => __( 'add another rule', 'gf-ifonly' ),
				'removeRule'     => __( 'remove this rule', 'gf-ifonly' ),
				'addGroup'       => __( 'Add rule group (OR)', 'gf-ifonly' ),
				'or'             => __( 'OR', 'gf-ifonly' ),
				'and'            => __( 'AND', 'gf-ifonly' ),
				'enterValue'     => __( 'Enter a value', 'gf-ifonly' ),
				'is'             => __( 'is', 'gf-ifonly' ),
				'isNot'          => __( 'is not', 'gf-ifonly' ),
				'greaterThan'    => __( 'greater than', 'gf-ifonly' ),
				'lessThan'       => __( 'less than', 'gf-ifonly' ),
				'contains'       => __( 'contains', 'gf-ifonly' ),
				'doesNotContain' => __( 'does NOT contain', 'gf-ifonly' ),
				'startsWith'     => __( 'starts with', 'gf-ifonly' ),
				'endsWith'       => __( 'ends with', 'gf-ifonly' ),
			),
		);
	}

	/**
	 * Render the IfOnly HTML block for notification / confirmation settings pages.
	 */
	private function render_settings_ifonly_html( string $object_type, $object ): string {
		$ifonly = is_array( $object ) ? ( $object['ifonlyLogic'] ?? null ) : null;

		// On a save postback the object passed to
		// gform_notification/confirmation_settings_fields was built BEFORE
		// process_postback() ran, so it contains STALE data.  Always read
		// from POST when this is a save request so the UI reflects what the
		// user just submitted.
		if ( ! empty( rgpost( 'gform-settings-save' ) ) ) {
			if ( ! empty( rgpost( 'ifonly_logic_enabled' ) ) ) {
				$json = rgpost( 'ifonly_logic_object' );
				$data = is_string( $json ) ? json_decode( stripslashes( $json ), true ) : null;
				if ( is_array( $data ) && ! empty( $data['groups'] ) ) {
					$data['enabled'] = true;
					$ifonly = $this->sanitize_ifonly_logic( $data );
				}
			} else {
				$ifonly = null;
			}
		}

		$config = $this->get_settings_config( $object_type );
		$config['objectType']  = $object_type;
		$config['ifonlyLogic'] = $ifonly;

		ob_start();
		?>
		<div class="gform-settings-field__header">
			<label class="gform-settings-label">
				<?php esc_html_e( 'Advanced Logic (IfOnly)', 'gf-ifonly' ); ?>
			</label>
		</div>
		<span class="gform-settings-input__container">
			<input type="hidden" name="ifonly_logic_object" id="ifonly_logic_object" value="<?php echo esc_attr( wp_json_encode( $ifonly ) ); ?>" />
			<input type="checkbox"
				name="ifonly_logic_enabled"
				id="ifonly_logic_enabled"
				value="1"
				<?php checked( ! empty( $ifonly['enabled'] ) ); ?>
			/>
			<label for="ifonly_logic_enabled" class="inline">
				<?php esc_html_e( 'Enable Advanced Logic (IfOnly)', 'gf-ifonly' ); ?>
			</label>
		</span>
		<div id="ifonly_settings_container" class="gform-settings-field__conditional-logic" style="<?php echo empty( $ifonly['enabled'] ) ? 'display:none;' : ''; ?>">
			<!-- Rendered by JS -->
		</div>
		<script type="text/javascript">
			var gfIfOnlySettingsConfig = <?php echo wp_json_encode( $config ); ?>;
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Add IfOnly field to notification settings.
	 */
	public function add_notification_settings_field( array $fields, array $notification, array $form ): array {
		$ifonly_field = array(
			'name' => 'ifonlyLogic',
			'type' => 'html',
			'html' => $this->render_settings_ifonly_html( 'notification', $notification ),
		);

		// Insert after conditionalLogic field.
		foreach ( $fields as $section_key => &$section ) {
			if ( empty( $section['fields'] ) ) {
				continue;
			}
			foreach ( $section['fields'] as $idx => $field ) {
				if ( ( $field['name'] ?? '' ) === 'conditionalLogic' ) {
					array_splice( $section['fields'], $idx + 1, 0, array( $ifonly_field ) );
					return $fields;
				}
			}
		}

		return $fields;
	}

	/**
	 * Add IfOnly field to confirmation settings.
	 */
	public function add_confirmation_settings_field( array $fields, array $confirmation, array $form ): array {
		if ( ! empty( $confirmation['isDefault'] ) ) {
			return $fields;
		}

		$ifonly_field = array(
			'name' => 'ifonlyLogic',
			'type' => 'html',
			'html' => $this->render_settings_ifonly_html( 'confirmation', $confirmation ),
		);

		foreach ( $fields as $section_key => &$section ) {
			if ( empty( $section['fields'] ) ) {
				continue;
			}
			foreach ( $section['fields'] as $idx => $field ) {
				if ( ( $field['name'] ?? '' ) === 'conditionalLogic' ) {
					array_splice( $section['fields'], $idx + 1, 0, array( $ifonly_field ) );
					return $fields;
				}
			}
		}

		return $fields;
	}

	/**
	 * Save IfOnly logic when a notification is saved.
	 */
	public function save_notification_ifonly( array $notification, array $form ): array {
		return $this->save_ifonly_from_post( $notification );
	}

	/**
	 * Save IfOnly logic when a confirmation is saved.
	 */
	public function save_confirmation_ifonly( array $confirmation, array $form ): array {
		return $this->save_ifonly_from_post( $confirmation );
	}

	/**
	 * Read IfOnly data from $_POST and attach to the object.
	 *
	 * When IfOnly is enabled, native conditional logic is cleared to prevent
	 * conflicts — both systems cannot be active on the same object.
	 */
	private function save_ifonly_from_post( array $object ): array {
		$enabled = ! empty( rgpost( 'ifonly_logic_enabled' ) );
		$json    = rgpost( 'ifonly_logic_object' );
		$data    = is_string( $json ) ? json_decode( stripslashes( $json ), true ) : null;

		if ( $enabled && is_array( $data ) && ! empty( $data['groups'] ) ) {
			$data['enabled'] = true;
			$object['ifonlyLogic']      = $this->sanitize_ifonly_logic( $data );
			$object['conditionalLogic'] = null;
		} else {
			$object['ifonlyLogic'] = null;
		}

		return $object;
	}

	/**
	 * Sanitize IfOnly logic data.
	 */
	private function sanitize_ifonly_logic( array $data ): array {
		$clean = array(
			'enabled'    => ! empty( $data['enabled'] ),
			'actionType' => in_array( $data['actionType'] ?? 'show', array( 'show', 'hide' ), true ) ? $data['actionType'] : 'show',
			'groups'     => array(),
		);

		if ( empty( $data['groups'] ) || ! is_array( $data['groups'] ) ) {
			return $clean;
		}

		foreach ( $data['groups'] as $group ) {
			if ( empty( $group['rules'] ) || ! is_array( $group['rules'] ) ) {
				continue;
			}
			$clean_rules = array();
			foreach ( $group['rules'] as $rule ) {
				$clean_rules[] = array(
					'fieldId'  => sanitize_text_field( $rule['fieldId'] ?? '' ),
					'operator' => sanitize_text_field( $rule['operator'] ?? 'is' ),
					'value'    => sanitize_text_field( $rule['value'] ?? '' ),
				);
			}
			if ( ! empty( $clean_rules ) ) {
				$clean['groups'][] = array( 'rules' => $clean_rules );
			}
		}

		return $clean;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Whitelist the "does_not_contain" operator for GF's internal validation.
	 */
	public function whitelist_does_not_contain( bool $is_valid, string $operator ): bool {
		if ( 'does_not_contain' === $operator ) {
			return true;
		}
		return $is_valid;
	}

	/**
	 * Evaluate the "does_not_contain" operator on the server side.
	 *
	 * Mirrors the Gravity Wiz reference implementation.
	 */
	public function evaluate_does_not_contain( bool $is_match, string $field_value, string $target_value, string $operation, $source_field, array $rule ): bool {
		if ( 'does_not_contain' !== ( $rule['operator'] ?? '' ) || ! empty( $rule['_ifonly_evaluating_dnc'] ) ) {
			return $is_match;
		}

		return strpos( $field_value, $target_value ) === false;
	}

	/**
	 * Collect all IfOnly logic data from a form's fields.
	 *
	 * @return array<string, array> Keyed by field ID.
	 */
	private function get_all_ifonly_logic( array $form ): array {
		$all = array();

		if ( empty( $form['fields'] ) ) {
			return $all;
		}

		foreach ( $form['fields'] as $field ) {
			$ifonly = $field->ifonlyLogic ?? null;

			if ( ! empty( $ifonly ) && ! empty( $ifonly['enabled'] ) && ! empty( $ifonly['groups'] ) ) {
				$all[ (string) $field->id ] = $ifonly;
			}
		}

		return $all;
	}

	/**
	 * Return the base URL for plugin assets.
	 */
	public function get_base_url( $full_path = '' ) {
		return untrailingslashit( GF_IFONLY_URL );
	}

	/**
	 * Plugin assets base path override for GFAddOn.
	 */
	public function get_base_path( $full_path = '' ) {
		return untrailingslashit( GF_IFONLY_PATH );
	}
}
