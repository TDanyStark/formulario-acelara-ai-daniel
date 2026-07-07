<?php

/**
 * [acelera_form] shortcode for the ACELERA diagnostic form (Fase 4.1).
 *
 * @link       https://danielamado.com
 * @since      1.0.0
 *
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/includes/form
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Renders the diagnostic form (or the result screen) inside the welcome
 * lesson dynamically resolved by Acelera_Course_Map::form_lesson_id().
 *
 * States, resolved via Acelera_Submissions_Repo::get_active_for_user():
 * 1. Not logged in → notice (edge case; LD already requires login).
 * 2. No active submission → empty app container + noscript fallback; the
 *    step-by-step UI is built entirely by public/js/acelera-form.js.
 * 3. Active submission → result screen: renumbered module cards linking
 *    to each module's first lesson + "Resetear" button with confirm modal.
 *
 * Assets (acelera-form.js / acelera-form.css) and the localized payload
 * (questions JSON, prefill, REST url + nonce, i18n strings, existing
 * result) are enqueued ONLY when the shortcode actually renders.
 *
 * Registration: `add_shortcode` on `init` through the plugin loader
 * (matches how the rest of the plugin registers hooks via the loader; no
 * earlier shortcode precedent existed).
 *
 * @since      1.0.0
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/includes/form
 * @author     Daniel Amado <daniel.amadove@gmail.com>
 */
class Acelera_Form_Shortcode {

	/**
	 * Shortcode tag.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const TAG = 'acelera_form';

	/**
	 * The ID of this plugin (asset handle prefix).
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name
	 */
	private $plugin_name;

	/**
	 * The version of this plugin (asset cache busting).
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param    string $plugin_name The name of the plugin.
	 * @param    string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;

	}

	/**
	 * Register the shortcode. Hooked to `init` through the loader.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_shortcode() {

		add_shortcode( self::TAG, array( $this, 'render' ) );

	}

	/**
	 * Shortcode callback.
	 *
	 * @since  1.0.0
	 * @param  array|string $atts Shortcode attributes (unused).
	 * @return string Markup.
	 */
	public function render( $atts = array() ) {

		// State 1 — not logged in (edge case, LD requires login here).
		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<div class="acelera-form acelera-form--notice"><p>%s</p></div>',
				esc_html__( 'Debes iniciar sesión para completar el formulario de diagnóstico.', 'formulario-acelera-ai-daniel' )
			);
		}

		$user_id = get_current_user_id();
		$repo    = new Acelera_Submissions_Repo();
		$active  = $repo->get_active_for_user( $user_id );

		$this->enqueue_assets( $active );

		// State 3 — active submission: result screen.
		if ( $active ) {
			return $this->render_result_screen( $active );
		}

		// State 2 — no active submission: JS-driven step form.
		return $this->render_form_container();

	}

	/**
	 * Cache-busting version for a plugin asset, based on its mtime.
	 *
	 * Production sits behind a CDN/browser caches; using filemtime makes
	 * the ?ver= change on every deploy so clients never run stale assets.
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  string $relative_path Asset path relative to the plugin root.
	 * @return string Version string for wp_enqueue_*().
	 */
	private function asset_version( $relative_path ) {

		$file  = plugin_dir_path( dirname( __FILE__, 2 ) ) . $relative_path;
		$mtime = file_exists( $file ) ? filemtime( $file ) : false;

		return $mtime ? $this->version . '.' . $mtime : $this->version;

	}

	/**
	 * Enqueue the form assets + localized payload (render time only).
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  object|null $active Active submission row or null.
	 * @return void
	 */
	private function enqueue_assets( $active ) {

		$handle = $this->plugin_name . '-form';
		$base   = plugin_dir_url( dirname( __FILE__, 2 ) ) . 'public/';

		// intl-tel-input (local vendor copy, v18) — enqueued before the form
		// script so the form can depend on window.intlTelInput being ready.
		$iti_handle = $this->plugin_name . '-intl-tel-input';
		$iti_base   = $base . 'vendor/intl-tel-input/';

		wp_enqueue_style( $iti_handle, $iti_base . 'css/intlTelInput.min.css', array(), $this->asset_version( 'public/vendor/intl-tel-input/css/intlTelInput.min.css' ), 'all' );
		wp_enqueue_script( $iti_handle, $iti_base . 'js/intlTelInput.min.js', array(), $this->asset_version( 'public/vendor/intl-tel-input/js/intlTelInput.min.js' ), true );

		wp_enqueue_style( $handle, $base . 'css/acelera-form.css', array( $iti_handle ), $this->asset_version( 'public/css/acelera-form.css' ), 'all' );
		wp_enqueue_script( $handle, $base . 'js/acelera-form.js', array( $iti_handle ), $this->asset_version( 'public/js/acelera-form.js' ), true );

		$user = wp_get_current_user();

		// Prefill the WhatsApp number from the user's most recent WooCommerce
		// billing phone (e.g. "+573042465482"), used by intl-tel-input to
		// auto-detect the country flag and national number.
		$woo_phone = $this->get_user_billing_phone( (int) $user->ID );

		$result = null;

		if ( $active ) {
			$scores = json_decode( (string) $active->scores, true );
			$flags  = json_decode( (string) $active->flags, true );

			$result = array(
				'module_order' => Acelera_Renaming::sanitize_order( (string) $active->module_order ),
				'modules'      => Acelera_Renaming::module_items( (string) $active->module_order ),
				'scores'       => is_array( $scores ) ? $scores : array(),
				'flags'        => is_array( $flags ) ? $flags : array(),
			);
		}

		wp_localize_script(
			$handle,
			'aceleraForm',
			array(
				'restUrl' => esc_url_raw( rest_url( 'acelera/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'userId'  => (int) $user->ID,
				'state'   => $active ? 'result' : 'form',
				'result'  => $result,
				'questions' => Acelera_Questions::all(),
				'prefill'   => array(
					'p0_1a' => (string) $user->first_name,
					'p0_1b' => (string) $user->last_name,
					'p0_2'  => (string) $user->user_email,
					'p0_3'  => (string) $woo_phone,
				),
				'intlUtilsUrl' => $iti_base . 'js/utils.js',
				'strings'   => array(
					'back'           => __( 'Atrás', 'formulario-acelera-ai-daniel' ),
					'skip'           => __( 'Omitir', 'formulario-acelera-ai-daniel' ),
					'next'           => __( 'Siguiente', 'formulario-acelera-ai-daniel' ),
					'send'           => __( 'Enviar diagnóstico', 'formulario-acelera-ai-daniel' ),
					'sending'        => __( 'Enviando…', 'formulario-acelera-ai-daniel' ),
					'required'       => __( 'Esta pregunta es obligatoria.', 'formulario-acelera-ai-daniel' ),
					'invalidEmail'   => __( 'El correo electrónico no es válido.', 'formulario-acelera-ai-daniel' ),
					'invalidTel'     => __( 'El teléfono no es válido (mínimo 7 dígitos).', 'formulario-acelera-ai-daniel' ),
					'invalidDate'    => __( 'La fecha no es válida.', 'formulario-acelera-ai-daniel' ),
					/* translators: %d: maximum number of selectable options. */
					'maxOptions'     => __( 'Puedes seleccionar máximo %d opciones.', 'formulario-acelera-ai-daniel' ),
					'invalidRows'    => __( 'Completa todos los datos de cada hijo (nombre, edad 0–99 y si estudia).', 'formulario-acelera-ai-daniel' ),
					'addChild'       => __( '+ Agregar hijo', 'formulario-acelera-ai-daniel' ),
					'removeChild'    => __( 'Quitar', 'formulario-acelera-ai-daniel' ),
					'childLabel'     => __( 'Hijo/a', 'formulario-acelera-ai-daniel' ),
					'uploading'      => __( 'Subiendo archivo…', 'formulario-acelera-ai-daniel' ),
					'uploaded'       => __( 'Archivo subido:', 'formulario-acelera-ai-daniel' ),
					'uploadError'    => __( 'No se pudo subir el archivo.', 'formulario-acelera-ai-daniel' ),
					'fileTooBig'     => __( 'El archivo supera el tamaño máximo de 10 MB.', 'formulario-acelera-ai-daniel' ),
					'fileBadType'    => __( 'Solo se permiten archivos PDF o Word (.pdf, .doc, .docx).', 'formulario-acelera-ai-daniel' ),
					'removeFile'     => __( 'Quitar archivo', 'formulario-acelera-ai-daniel' ),
					/* translators: 1: current question number, 2: total visible questions. */
					'progressOf'     => __( 'Pregunta %1$d de %2$d', 'formulario-acelera-ai-daniel' ),
					'errorGeneric'   => __( 'Ocurrió un error. Intenta de nuevo.', 'formulario-acelera-ai-daniel' ),
					'successTitle'   => __( '¡Diagnóstico enviado! Cargando tu resultado…', 'formulario-acelera-ai-daniel' ),
					'resetting'      => __( 'Reseteando…', 'formulario-acelera-ai-daniel' ),
					'scaleLow'       => __( 'Nada listo/a', 'formulario-acelera-ai-daniel' ),
					'scaleHigh'      => __( 'Totalmente listo/a', 'formulario-acelera-ai-daniel' ),
				),
			)
		);

	}

	/**
	 * Resolve the user's billing phone for the WhatsApp prefill.
	 *
	 * Prefers the billing phone of the most recent WooCommerce order, then
	 * falls back to the `billing_phone` user meta. Returns '' when nothing
	 * is available or WooCommerce is not active.
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  int $user_id WordPress user ID.
	 * @return string Phone number (raw, may be empty).
	 */
	private function get_user_billing_phone( $user_id ) {

		if ( $user_id <= 0 ) {
			return '';
		}

		// Most recent WooCommerce order's billing phone.
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'limit'       => 1,
					'orderby'     => 'date',
					'order'       => 'DESC',
				)
			);

			if ( is_array( $orders ) && ! empty( $orders ) ) {
				$order = $orders[0];

				if ( is_object( $order ) && method_exists( $order, 'get_billing_phone' ) ) {
					$phone = trim( (string) $order->get_billing_phone() );

					if ( '' !== $phone ) {
						return $phone;
					}
				}
			}
		}

		// Fallback: billing_phone user meta.
		$meta_phone = trim( (string) get_user_meta( $user_id, 'billing_phone', true ) );

		return '' !== $meta_phone ? $meta_phone : '';

	}

	/**
	 * State 2 markup — container for the JS step form.
	 *
	 * @since  1.0.0
	 * @access private
	 * @return string
	 */
	private function render_form_container() {

		return sprintf(
			'<div id="acelera-form" class="acelera-form" data-state="form">' .
				'<noscript><p class="acelera-form-noscript">%s</p></noscript>' .
				'<div class="acelera-form-app" aria-live="polite"></div>' .
			'</div>',
			esc_html__( 'El formulario de diagnóstico requiere JavaScript. Activa JavaScript en tu navegador para continuar.', 'formulario-acelera-ai-daniel' )
		);

	}

	/**
	 * State 3 markup — result screen with renumbered module cards + reset.
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  object $active Active submission row.
	 * @return string
	 */
	private function render_result_screen( $active ) {

		$items = Acelera_Renaming::module_items( (string) $active->module_order );

		$cards = '';

		foreach ( $items as $item ) {
			$cards .= sprintf(
				'<li class="acelera-form-module-card">' .
					'<a class="acelera-form-module-link" href="%1$s">' .
						'<span class="acelera-form-module-number" aria-hidden="true">%2$d</span>' .
						'<span class="acelera-form-module-label">%3$s</span>' .
						'<span class="acelera-form-module-arrow" aria-hidden="true">&rarr;</span>' .
					'</a>' .
				'</li>',
				esc_url( $item['url'] ),
				(int) $item['number'],
				esc_html( $item['label'] )
			);
		}

		return sprintf(
			'<div id="acelera-form" class="acelera-form acelera-form--result" data-state="result">' .
				'<div class="acelera-form-result">' .
					'<h2 class="acelera-form-result-title">%1$s</h2>' .
					'<p class="acelera-form-result-intro">%2$s</p>' .
					'<ol class="acelera-form-modules">%3$s</ol>' .
					'<div class="acelera-form-result-actions">' .
						'<button type="button" class="acelera-form-btn acelera-form-btn--ghost acelera-form-reset-open">%4$s</button>' .
					'</div>' .
				'</div>' .
				'<div class="acelera-form-modal" role="dialog" aria-modal="true" aria-labelledby="acelera-form-modal-title" hidden>' .
					'<div class="acelera-form-modal-backdrop" data-acelera-modal-close></div>' .
					'<div class="acelera-form-modal-box">' .
						'<h3 id="acelera-form-modal-title">%5$s</h3>' .
						'<p>%6$s</p>' .
						'<div class="acelera-form-modal-actions">' .
							'<button type="button" class="acelera-form-btn acelera-form-btn--ghost acelera-form-reset-cancel" data-acelera-modal-close>%7$s</button>' .
							'<button type="button" class="acelera-form-btn acelera-form-btn--danger acelera-form-reset-confirm">%8$s</button>' .
						'</div>' .
					'</div>' .
				'</div>' .
			'</div>',
			esc_html__( 'Tu orden de ejecución del curso', 'formulario-acelera-ai-daniel' ),
			esc_html__( 'Según tu diagnóstico, este es el orden recomendado para avanzar por los módulos:', 'formulario-acelera-ai-daniel' ),
			$cards,
			esc_html__( 'Resetear diagnóstico', 'formulario-acelera-ai-daniel' ),
			esc_html__( '¿Resetear tu diagnóstico?', 'formulario-acelera-ai-daniel' ),
			esc_html__( 'Tu resultado actual se archivará y volverás a responder el formulario desde cero. El orden personalizado de los módulos se restablecerá.', 'formulario-acelera-ai-daniel' ),
			esc_html__( 'Cancelar', 'formulario-acelera-ai-daniel' ),
			esc_html__( 'Sí, resetear', 'formulario-acelera-ai-daniel' )
		);

	}

}
