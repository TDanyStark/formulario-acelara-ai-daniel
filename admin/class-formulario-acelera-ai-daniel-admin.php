<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://https://danielamado.com
 * @since      1.0.0
 *
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/admin
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The admin-specific functionality of the plugin.
 *
 * Registers the "Curso Acelera" settings page (Settings API, single
 * serialized option `acelera_settings` with tabs) and enqueues admin
 * assets only on that page.
 *
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/admin
 * @author     Daniel Amado <daniel.amadove@gmail.com>
 */
class Formulario_Acelera_Ai_Daniel_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Hook suffix of the plugin settings page, set by add_settings_menu().
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $settings_page_hook    Hook suffix returned by add_menu_page().
	 */
	private $settings_page_hook = '';

	/**
	 * Hook suffix of the submissions list page, set by add_settings_menu().
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $submissions_page_hook    Hook suffix returned by add_submenu_page().
	 */
	private $submissions_page_hook = '';

	/**
	 * Nonce action shared by the Clientify admin AJAX endpoints.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const CLIENTIFY_NONCE_ACTION = 'acelera_clientify_admin';

	/**
	 * Nonce action for the LLM feedback admin AJAX endpoint (Fase 6.4).
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const LLM_NONCE_ACTION = 'acelera_llm_admin';

	/**
	 * Nonce action for the "Sumisiones" detail/reorder/export AJAX endpoints.
	 *
	 * @since    1.0.0
	 * @var      string
	 */
	const SUBMISSIONS_NONCE_ACTION = 'acelera_submissions_nonce';

	/**
	 * Setting keys that hold API secrets and must never be printed in full.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string[]
	 */
	private $api_key_fields = array( 'clientify_api_key', 'anthropic_api_key', 'openai_api_key' );

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Cache-busting version for a plugin asset, based on its mtime.
	 *
	 * Production sits behind a CDN/browser caches; using filemtime makes
	 * the ?ver= change on every deploy so clients never run stale assets.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    string $relative_path Asset path relative to the plugin root.
	 * @return   string Version string for wp_enqueue_*().
	 */
	private function asset_version( $relative_path ) {

		$file  = plugin_dir_path( dirname( __FILE__ ) ) . $relative_path;
		$mtime = file_exists( $file ) ? filemtime( $file ) : false;

		return $mtime ? $this->version . '.' . $mtime : $this->version;

	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * Only enqueued on the plugin settings page.
	 *
	 * @since    1.0.0
	 * @param    string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_styles( $hook_suffix ) {

		if ( ! in_array( $hook_suffix, array( $this->settings_page_hook, $this->submissions_page_hook ), true ) ) {
			return;
		}

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/formulario-acelera-ai-daniel-admin.css', array(), $this->asset_version( 'admin/css/formulario-acelera-ai-daniel-admin.css' ), 'all' );

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * Only enqueued on the plugin settings page.
	 *
	 * @since    1.0.0
	 * @param    string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_scripts( $hook_suffix ) {

		if ( ! in_array( $hook_suffix, array( $this->settings_page_hook, $this->submissions_page_hook ), true ) ) {
			return;
		}

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/formulario-acelera-ai-daniel-admin.js', array( 'jquery', 'jquery-ui-sortable' ), $this->asset_version( 'admin/js/formulario-acelera-ai-daniel-admin.js' ), false );

		wp_localize_script(
			$this->plugin_name,
			'aceleraAdmin',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( self::CLIENTIFY_NONCE_ACTION ),
				'llmNonce'         => wp_create_nonce( self::LLM_NONCE_ACTION ),
				'submissionsNonce' => wp_create_nonce( self::SUBMISSIONS_NONCE_ACTION ),
				'exportAllUrl'     => add_query_arg(
					array(
						'action' => 'acelera_export_all_submissions',
						'nonce'  => wp_create_nonce( self::SUBMISSIONS_NONCE_ACTION ),
					),
					admin_url( 'admin-ajax.php' )
				),
				'i18n'             => array(
					'testing'      => __( 'Probando conexión…', 'formulario-acelera-ai-daniel' ),
					'resending'    => __( 'Reprogramando…', 'formulario-acelera-ai-daniel' ),
					'regenerating' => __( 'Eliminando feedback cacheado…', 'formulario-acelera-ai-daniel' ),
					'genericKo'    => __( 'Error inesperado. Revisa la consola del navegador.', 'formulario-acelera-ai-daniel' ),
					'loading'      => __( 'Cargando…', 'formulario-acelera-ai-daniel' ),
					'saving'       => __( 'Guardando orden…', 'formulario-acelera-ai-daniel' ),
					'saved'        => __( 'Orden guardado.', 'formulario-acelera-ai-daniel' ),
					'notActive'    => __( 'Esta sumisión no es la activa del alumno; el orden no se puede editar.', 'formulario-acelera-ai-daniel' ),
				),
			)
		);

	}

	/**
	 * Register the "Curso Acelera" top-level menu page.
	 *
	 * @since    1.0.0
	 */
	public function add_settings_menu() {

		$this->settings_page_hook = add_menu_page(
			__( 'Acelera', 'formulario-acelera-ai-daniel' ),
			__( 'Curso Acelera', 'formulario-acelera-ai-daniel' ),
			'manage_options',
			'acelera-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-welcome-learn-more',
			58
		);

		// Mirror the default WP behavior: a submenu entry for the parent page.
		add_submenu_page(
			'acelera-settings',
			__( 'Ajustes', 'formulario-acelera-ai-daniel' ),
			__( 'Ajustes', 'formulario-acelera-ai-daniel' ),
			'manage_options',
			'acelera-settings'
		);

		// Submissions status list (Fase 5.3).
		$this->submissions_page_hook = add_submenu_page(
			'acelera-settings',
			__( 'Sumisiones', 'formulario-acelera-ai-daniel' ),
			__( 'Sumisiones', 'formulario-acelera-ai-daniel' ),
			'manage_options',
			'acelera-submissions',
			array( $this, 'render_submissions_page' )
		);

	}

	/**
	 * Register the `acelera_settings` option, sections and fields.
	 *
	 * One section per tab; each tab uses its own Settings API "page" slug
	 * (acelera-settings-{tab}) so only the active tab is rendered.
	 *
	 * @since    1.0.0
	 */
	public function register_settings() {

		register_setting(
			'acelera_settings',
			Acelera_Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		// --- Tab: Clientify ---------------------------------------------.
		add_settings_section(
			'acelera_section_clientify',
			__( 'Integración con Clientify', 'formulario-acelera-ai-daniel' ),
			'__return_false',
			'acelera-settings-clientify'
		);

		$this->add_field( 'clientify_api_key', __( 'API Key de Clientify', 'formulario-acelera-ai-daniel' ), 'clientify', 'api_key' );
		$this->add_field( 'clientify_owner', __( 'Owner (email)', 'formulario-acelera-ai-daniel' ), 'clientify', 'text', array(
			'description' => __( 'Email del propietario asignado a los contactos creados.', 'formulario-acelera-ai-daniel' ),
		) );
		$this->add_field( 'clientify_tags', __( 'Tags', 'formulario-acelera-ai-daniel' ), 'clientify', 'text', array(
			'description' => __( 'Tags separados por comas que se aplican a los contactos.', 'formulario-acelera-ai-daniel' ),
		) );

		// --- Tab: LLM -----------------------------------------------------.
		add_settings_section(
			'acelera_section_llm',
			__( 'Proveedor LLM (feedback por módulo)', 'formulario-acelera-ai-daniel' ),
			'__return_false',
			'acelera-settings-llm'
		);

		$this->add_field( 'llm_provider', __( 'Proveedor', 'formulario-acelera-ai-daniel' ), 'llm', 'select', array(
			'options' => array(
				'claude'  => 'Claude (Anthropic)',
				'chatgpt' => 'ChatGPT (OpenAI)',
			),
		) );
		$this->add_field( 'anthropic_api_key', __( 'API Key de Anthropic', 'formulario-acelera-ai-daniel' ), 'llm', 'api_key' );
		$this->add_field( 'openai_api_key', __( 'API Key de OpenAI', 'formulario-acelera-ai-daniel' ), 'llm', 'api_key' );
		$this->add_field( 'llm_model', __( 'Modelo', 'formulario-acelera-ai-daniel' ), 'llm', 'model_select', array(
			'description' => __( 'La lista se consulta en vivo a la API del proveedor (con la API key configurada) y se cachea 12 h. Si no hay key o la API falla, se usa una lista de respaldo. La opción "Default del proveedor" usa claude-sonnet-4-6 (Claude) o gpt-5 (OpenAI).', 'formulario-acelera-ai-daniel' ),
		) );

		// --- Tab: Prompts ---------------------------------------------------.
		add_settings_section(
			'acelera_section_prompts',
			__( 'Prompts por módulo', 'formulario-acelera-ai-daniel' ),
			array( $this, 'render_prompts_section_intro' ),
			'acelera-settings-prompts'
		);

		foreach ( Acelera_Course_Map::modules() as $key => $module ) {
			$this->add_field(
				'prompt_' . $key,
				sprintf( '%s — %s', strtoupper( $key ), $module['label'] ),
				'prompts',
				'textarea'
			);
		}

		// --- Tab: Email -----------------------------------------------------.
		add_settings_section(
			'acelera_section_email',
			__( 'Email de resultado', 'formulario-acelera-ai-daniel' ),
			'__return_false',
			'acelera-settings-email'
		);

		$this->add_field( 'email_subject', __( 'Asunto', 'formulario-acelera-ai-daniel' ), 'email', 'text' );
		$this->add_field( 'email_from_name', __( 'Nombre del remitente', 'formulario-acelera-ai-daniel' ), 'email', 'text' );

	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * Each tab submits only its own fields, so the result is merged over
	 * the previously stored option. API key fields keep their stored value
	 * when the submitted value is the masked placeholder.
	 *
	 * @since    1.0.0
	 * @param    array $input Raw submitted values.
	 * @return   array Sanitized full settings array.
	 */
	public function sanitize_settings( $input ) {

		$existing = get_option( Acelera_Settings::OPTION_NAME, array() );

		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		if ( ! is_array( $input ) ) {
			return $existing;
		}

		$output = $existing;

		// Track whether an LLM API key actually changes so we can drop the
		// cached model lists (a new/different key may expose other models).
		$llm_key_changed = false;

		// API keys: keep the stored value when the masked placeholder comes back.
		foreach ( $this->api_key_fields as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}

			$value = trim( (string) $input[ $key ] );

			if ( false !== strpos( $value, "\u{2022}" ) ) {
				continue; // Masked placeholder submitted: keep stored value.
			}

			$value = sanitize_text_field( $value );

			if ( in_array( $key, array( 'anthropic_api_key', 'openai_api_key' ), true )
				&& ( ! isset( $existing[ $key ] ) || $existing[ $key ] !== $value ) ) {
				$llm_key_changed = true;
			}

			$output[ $key ] = $value;
		}

		if ( $llm_key_changed && class_exists( 'Acelera_LLM_Client' ) ) {
			Acelera_LLM_Client::flush_models_cache();
		}

		if ( isset( $input['clientify_owner'] ) ) {
			$output['clientify_owner'] = sanitize_email( $input['clientify_owner'] );
		}

		if ( isset( $input['clientify_tags'] ) ) {
			$tags = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', (string) $input['clientify_tags'] ) ) ) );

			$output['clientify_tags'] = implode( ', ', $tags );
		}

		if ( isset( $input['llm_provider'] ) ) {
			$output['llm_provider'] = in_array( $input['llm_provider'], array( 'claude', 'chatgpt' ), true ) ? $input['llm_provider'] : 'claude';
		}

		if ( isset( $input['llm_model'] ) ) {
			$model = sanitize_text_field( $input['llm_model'] );

			$valid_models = array();
			foreach ( self::llm_models() as $provider_models ) {
				$valid_models = array_merge( $valid_models, array_keys( $provider_models ) );
			}

			// Allow empty (provider default), a known model id from the
			// (dynamic or static) list, or a plausibly-shaped id the live
			// API may know about even when the cache is empty (e.g. a key
			// pasted in this same submit). Otherwise keep the stored value.
			if ( '' === $model || in_array( $model, $valid_models, true ) || self::looks_like_model_id( $model ) ) {
				$output['llm_model'] = $model;
			}
		}

		foreach ( array_keys( Acelera_Course_Map::modules() ) as $module_key ) {
			$prompt_key = 'prompt_' . $module_key;

			if ( isset( $input[ $prompt_key ] ) ) {
				$output[ $prompt_key ] = sanitize_textarea_field( $input[ $prompt_key ] );
			}
		}

		if ( isset( $input['email_subject'] ) ) {
			$output['email_subject'] = sanitize_text_field( $input['email_subject'] );
		}

		if ( isset( $input['email_from_name'] ) ) {
			$output['email_from_name'] = sanitize_text_field( $input['email_from_name'] );
		}

		return $output;

	}

	/**
	 * Render the settings page (delegates to the admin partial).
	 *
	 * @since    1.0.0
	 */
	public function render_settings_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = array(
			'clientify' => __( 'Clientify', 'formulario-acelera-ai-daniel' ),
			'llm'       => __( 'LLM', 'formulario-acelera-ai-daniel' ),
			'prompts'   => __( 'Prompts', 'formulario-acelera-ai-daniel' ),
			'email'     => __( 'Email', 'formulario-acelera-ai-daniel' ),
			'ayuda'     => __( 'Ayuda / Uso', 'formulario-acelera-ai-daniel' ),
		);

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'clientify'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'clientify';
		}

		include plugin_dir_path( __FILE__ ) . 'partials/formulario-acelera-ai-daniel-admin-display.php';

	}

	/**
	 * Render a settings field.
	 *
	 * Generic callback for every field registered via add_field().
	 *
	 * @since    1.0.0
	 * @param    array $args Field arguments (key, type, options, description).
	 */
	public function render_field( $args ) {

		$key   = $args['key'];
		$type  = $args['type'];
		$value = Acelera_Settings::get( $key );
		$name  = Acelera_Settings::OPTION_NAME . '[' . $key . ']';

		switch ( $type ) {

			case 'api_key':
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" autocomplete="off" />',
					esc_attr( $key ),
					esc_attr( $name ),
					esc_attr( $this->mask_api_key( $value ) )
				);
				echo '<p class="description">' . esc_html__( 'Se muestran solo los últimos 4 caracteres. Pegá una clave nueva para reemplazarla.', 'formulario-acelera-ai-daniel' ) . '</p>';
				break;

			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $key ), esc_attr( $name ) );
				foreach ( $args['options'] as $option_value => $option_label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $option_value ),
						selected( $value, $option_value, false ),
						esc_html( $option_label )
					);
				}
				echo '</select>';
				break;

			case 'model_select':
				$provider = Acelera_Settings::get( 'llm_provider' );
				$provider = in_array( $provider, array( 'claude', 'chatgpt' ), true ) ? $provider : 'claude';
				$models   = self::llm_models();

				printf( '<select id="%1$s" name="%2$s" class="acelera-llm-model">', esc_attr( $key ), esc_attr( $name ) );

				// Default-of-provider option (empty stored value).
				printf(
					'<option value="" %1$s>%2$s</option>',
					selected( $value, '', false ),
					esc_html__( 'Default del proveedor', 'formulario-acelera-ai-daniel' )
				);

				foreach ( $models as $provider_key => $provider_models ) {
					$labels = array(
						'claude'  => __( 'Claude (Anthropic)', 'formulario-acelera-ai-daniel' ),
						'chatgpt' => __( 'ChatGPT (OpenAI)', 'formulario-acelera-ai-daniel' ),
					);

					printf(
						'<optgroup label="%1$s" data-provider="%2$s">',
						esc_attr( isset( $labels[ $provider_key ] ) ? $labels[ $provider_key ] : $provider_key ),
						esc_attr( $provider_key )
					);

					foreach ( $provider_models as $model_id => $model_label ) {
						printf(
							'<option value="%1$s" data-provider="%2$s" %3$s>%4$s</option>',
							esc_attr( $model_id ),
							esc_attr( $provider_key ),
							selected( $value, $model_id, false ),
							esc_html( $model_label )
						);
					}

					echo '</optgroup>';
				}

				echo '</select>';
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="6" class="large-text code">%3$s</textarea>',
					esc_attr( $key ),
					esc_attr( $name ),
					esc_textarea( $value )
				);
				break;

			case 'text':
			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $key ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;
		}

		if ( ! empty( $args['description'] ) && 'api_key' !== $type ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}

	}

	/**
	 * Mask an API key, leaving only the last 4 characters visible.
	 *
	 * @since    1.0.0
	 * @param    string $value Stored API key.
	 * @return   string Masked representation, e.g. "••••1234". Empty when no key stored.
	 */
	private function mask_api_key( $value ) {

		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		$visible = strlen( $value ) > 4 ? substr( $value, -4 ) : '';

		return str_repeat( "\u{2022}", 4 ) . $visible;

	}

	/**
	 * Render the "Sumisiones" list page (delegates to the admin partial).
	 *
	 * @since    1.0.0
	 */
	public function render_submissions_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$per_page = 20;
		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$repo        = new Acelera_Submissions_Repo();
		$submissions = $repo->get_page( $per_page, $page );
		$total       = $repo->count_all();
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );

		include plugin_dir_path( __FILE__ ) . 'partials/formulario-acelera-ai-daniel-admin-submissions.php';

	}

	/**
	 * AJAX `acelera_clientify_test` — validate the stored API key.
	 *
	 * @since    1.0.0
	 */
	public function ajax_clientify_test() {

		check_ajax_referer( self::CLIENTIFY_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes.', 'formulario-acelera-ai-daniel' ) ),
				403
			);
		}

		$client = new Acelera_Clientify();
		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array( 'message' => __( 'Conexión correcta con Clientify.', 'formulario-acelera-ai-daniel' ) )
		);

	}

	/**
	 * AJAX `acelera_clientify_resend` — reschedule a failed/skipped send.
	 *
	 * Resets the row to 'pending' (attempt 1) and clears the stored error.
	 *
	 * @since    1.0.0
	 */
	public function ajax_clientify_resend() {

		check_ajax_referer( self::CLIENTIFY_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes.', 'formulario-acelera-ai-daniel' ) ),
				403
			);
		}

		$submission_id = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;

		$repo = new Acelera_Submissions_Repo();
		$row  = $submission_id > 0 ? $repo->get_by_id( $submission_id ) : null;

		if ( ! $row ) {
			wp_send_json_error(
				array( 'message' => __( 'La sumisión no existe.', 'formulario-acelera-ai-daniel' ) )
			);
		}

		if ( 'sent' === (string) $row->clientify_status ) {
			wp_send_json_error(
				array( 'message' => __( 'Esta sumisión ya fue enviada a Clientify.', 'formulario-acelera-ai-daniel' ) )
			);
		}

		delete_transient( Acelera_Clientify_Dispatcher::ERROR_TRANSIENT_PREFIX . $submission_id );

		$repo->update_clientify( $submission_id, (int) $row->clientify_contact_id ? (int) $row->clientify_contact_id : null, 'pending' );

		Acelera_Clientify_Dispatcher::schedule( $submission_id, 1, 0 );

		wp_send_json_success(
			array( 'message' => __( 'Reenvío programado.', 'formulario-acelera-ai-daniel' ) )
		);

	}

	/**
	 * AJAX `acelera_get_submission_detail` — full detail for the "Ver
	 * respuestas" modal (answers + module order).
	 *
	 * @since    1.0.0
	 */
	public function ajax_get_submission_detail() {

		check_ajax_referer( self::SUBMISSIONS_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes.', 'formulario-acelera-ai-daniel' ) ),
				403
			);
		}

		$submission_id = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;

		$repo = new Acelera_Submissions_Repo();
		$row  = $submission_id > 0 ? $repo->get_by_id( $submission_id ) : null;

		if ( ! $row ) {
			wp_send_json_error(
				array( 'message' => __( 'La sumisión no existe.', 'formulario-acelera-ai-daniel' ) ),
				404
			);
		}

		$active    = $repo->get_active_for_user( (int) $row->user_id );
		$is_active = $active && (int) $active->id === (int) $row->id;

		wp_send_json_success(
			array(
				'submission' => Acelera_Submission_Formatter::to_array( $row ),
				'is_active'  => $is_active,
			)
		);

	}

	/**
	 * AJAX `acelera_save_module_order` — persist a new module order from
	 * the "Sumisiones" modal drag-and-drop tool.
	 *
	 * Only allowed on the user's currently active submission: reordering a
	 * historical row would desync the DB row from the LMS sidebar, which
	 * always follows the active submission's order.
	 *
	 * @since    1.0.0
	 */
	public function ajax_save_module_order() {

		check_ajax_referer( self::SUBMISSIONS_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes.', 'formulario-acelera-ai-daniel' ) ),
				403
			);
		}

		$submission_id = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
		$order         = isset( $_POST['order'] ) && is_array( $_POST['order'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['order'] ) )
			: array();

		$repo = new Acelera_Submissions_Repo();
		$row  = $submission_id > 0 ? $repo->get_by_id( $submission_id ) : null;

		if ( ! $row ) {
			wp_send_json_error(
				array( 'message' => __( 'La sumisión no existe.', 'formulario-acelera-ai-daniel' ) ),
				404
			);
		}

		$active = $repo->get_active_for_user( (int) $row->user_id );

		if ( ! $active || (int) $active->id !== (int) $row->id ) {
			wp_send_json_error(
				array( 'message' => __( 'Solo se puede reordenar la sumisión activa del alumno.', 'formulario-acelera-ai-daniel' ) )
			);
		}

		$sanitized_order = Acelera_Renaming::sanitize_order( $order );

		$repo->update_module_order( $submission_id, implode( ',', $sanitized_order ) );

		Acelera_Renaming::save_user_order( (int) $row->user_id, $sanitized_order );

		wp_send_json_success(
			array(
				'message' => __( 'Orden guardado correctamente.', 'formulario-acelera-ai-daniel' ),
				'modules' => Acelera_Renaming::module_items( $sanitized_order ),
			)
		);

	}

	/**
	 * AJAX `acelera_export_submission` — download a single submission as
	 * JSON.
	 *
	 * @since    1.0.0
	 */
	public function ajax_export_submission() {

		check_ajax_referer( self::SUBMISSIONS_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes.', 'formulario-acelera-ai-daniel' ), 403 );
		}

		$submission_id = isset( $_GET['submission_id'] ) ? (int) $_GET['submission_id'] : 0;

		$repo = new Acelera_Submissions_Repo();
		$row  = $submission_id > 0 ? $repo->get_by_id( $submission_id ) : null;

		if ( ! $row ) {
			wp_die( esc_html__( 'La sumisión no existe.', 'formulario-acelera-ai-daniel' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="acelera-sumision-' . (int) $submission_id . '.json"' );

		echo wp_json_encode( Acelera_Submission_Formatter::to_array( $row ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		exit;

	}

	/**
	 * AJAX `acelera_export_all_submissions` — download every submission as
	 * a single JSON array.
	 *
	 * No pagination: the dataset is small (a few hundred rows).
	 *
	 * @since    1.0.0
	 */
	public function ajax_export_all_submissions() {

		check_ajax_referer( self::SUBMISSIONS_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes.', 'formulario-acelera-ai-daniel' ), 403 );
		}

		$repo = new Acelera_Submissions_Repo();
		$rows = $repo->get_all();

		$data = array_map( array( 'Acelera_Submission_Formatter', 'to_array' ), $rows );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="acelera-sumisiones-' . gmdate( 'Y-m-d' ) . '.json"' );

		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		exit;

	}

	/**
	 * Intro text of the Prompts tab: placeholder documentation (Fase 6.3).
	 *
	 * @since    1.0.0
	 */
	public function render_prompts_section_intro() {

		echo '<p class="description">' . esc_html__( 'Cada textarea es el prompt de sistema usado para generar el feedback del módulo. Si un prompt queda vacío, NO se genera feedback para ese módulo (el shortcode se oculta).', 'formulario-acelera-ai-daniel' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Placeholders disponibles (reemplazo simple): {nombre} = nombre del alumno; {modulo} = nombre temático del módulo del shortcode; {ruta_principal} = primer módulo del orden personalizado del alumno; {respuestas_resumen} = resumen compacto del diagnóstico (objetivo, situación, orden con puntajes). Los dos últimos quedan vacíos si el alumno aún no completó el formulario.', 'formulario-acelera-ai-daniel' ) . '</p>';

	}

	/**
	 * AJAX `acelera_llm_regenerate` — delete a user's cached feedback
	 * (Fase 6.4 support tool).
	 *
	 * POST: user (ID or email), module ('m1'..'m5' | 'todos'). The cache
	 * regenerates on the user's next visit to a page with the shortcode.
	 *
	 * @since    1.0.0
	 */
	public function ajax_llm_regenerate() {

		check_ajax_referer( self::LLM_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No tienes permisos suficientes.', 'formulario-acelera-ai-daniel' ) ),
				403
			);
		}

		$user_ref = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
		$module   = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : 'todos';

		if ( '' === $user_ref ) {
			wp_send_json_error(
				array( 'message' => __( 'Indica el ID o email del usuario.', 'formulario-acelera-ai-daniel' ) )
			);
		}

		$user = is_numeric( $user_ref )
			? get_user_by( 'id', (int) $user_ref )
			: get_user_by( 'email', $user_ref );

		if ( ! $user ) {
			wp_send_json_error(
				array( 'message' => __( 'No se encontró ningún usuario con ese ID o email.', 'formulario-acelera-ai-daniel' ) )
			);
		}

		$valid = array_keys( Acelera_Course_Map::modules() );

		if ( 'todos' === $module ) {
			$modules = $valid;
		} elseif ( in_array( $module, $valid, true ) ) {
			$modules = array( $module );
		} else {
			wp_send_json_error(
				array( 'message' => __( 'Módulo inválido.', 'formulario-acelera-ai-daniel' ) )
			);

			return;
		}

		$deleted = Acelera_Module_Feedback::delete_cache( $user->ID, $modules );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: number of cache entries deleted, 2: user login. */
					__( 'Feedback eliminado: %1$d entrada(s) de caché de %2$s. Se regenerará en su próxima visita.', 'formulario-acelera-ai-daniel' ),
					(int) $deleted,
					$user->user_login
				),
			)
		);

	}

	/**
	 * Helper to register a settings field on a tab.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    string $key   Setting key.
	 * @param    string $label Field label.
	 * @param    string $tab   Tab slug (clientify | llm | prompts | email).
	 * @param    string $type  Field type (text | api_key | select | model_select | textarea).
	 * @param    array  $extra Optional. Extra args (options, description).
	 */
	private function add_field( $key, $label, $tab, $type, $extra = array() ) {

		add_settings_field(
			'acelera_field_' . $key,
			$label,
			array( $this, 'render_field' ),
			'acelera-settings-' . $tab,
			'acelera_section_' . $tab,
			array_merge(
				array(
					'key'       => $key,
					'type'      => $type,
					'label_for' => $key,
				),
				$extra
			)
		);

	}

	/**
	 * Available LLM models grouped by provider.
	 *
	 * Used both to render the model <select> and to validate the
	 * submitted model on save. Each provider's list is fetched live from
	 * its API (cached 12h by Acelera_LLM_Client); when the API is
	 * unavailable (no key, network error) it falls back to the static
	 * list in llm_models_static(). The empty value ('') means "use the
	 * provider default".
	 *
	 * @since    1.0.0
	 * @return   array<string,array<string,string>> Provider key => (model id => label).
	 */
	public static function llm_models() {

		$static = self::llm_models_static();
		$models = array();

		foreach ( array( 'claude', 'chatgpt' ) as $provider ) {
			$dynamic = class_exists( 'Acelera_LLM_Client' )
				? Acelera_LLM_Client::fetch_models( $provider )
				: array();

			$models[ $provider ] = ! empty( $dynamic ) ? $dynamic : $static[ $provider ];
		}

		return $models;
	}

	/**
	 * Static fallback list of LLM models grouped by provider.
	 *
	 * Used when the provider API cannot be queried (no API key yet, or a
	 * transient network failure). Model names verified against the
	 * official docs, June 2026.
	 *
	 * @since    1.0.0
	 * @return   array<string,array<string,string>> Provider key => (model id => label).
	 */
	public static function llm_models_static() {

		return array(
			'claude'  => array(
				'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (default)',
				'claude-opus-4-6'   => 'Claude Opus 4.6',
				'claude-haiku-4-5'  => 'Claude Haiku 4.5',
			),
			'chatgpt' => array(
				'gpt-5'        => 'GPT-5 (default)',
				'gpt-4.1-mini' => 'GPT-4.1 mini',
				'gpt-4o-mini'  => 'GPT-4o mini',
			),
		);
	}

	/**
	 * Whether a string is a plausibly-shaped Claude/OpenAI model id.
	 *
	 * Used as a permissive on-save guard so a model selected from the
	 * live dropdown is accepted even when the validation cache is empty
	 * (e.g. an API key pasted in the same request). It only checks the
	 * shape (provider prefix + safe charset), never the API.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    string $model Submitted model id.
	 * @return   bool
	 */
	private static function looks_like_model_id( $model ) {

		if ( ! preg_match( '/^[a-z0-9._-]{2,80}$/i', $model ) ) {
			return false;
		}

		return ( 0 === strpos( $model, 'claude-' ) )
			|| ( 0 === strpos( $model, 'gpt-' ) )
			|| (bool) preg_match( '/^o\d/', $model );
	}

}
