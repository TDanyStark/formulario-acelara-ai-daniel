<?php

/**
 * REST API endpoints for the ACELERA diagnostic form (Fase 4.4).
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
 * Namespace `acelera/v1` — submit, upload-cv, reset and result endpoints.
 *
 * Authentication: WordPress cookie auth. Every route requires a logged-in
 * user ({@see Acelera_Rest::permission_logged_in()}); the X-WP-Nonce
 * header ('wp_rest' nonce, localized by the shortcode) is validated by
 * core's cookie-auth layer BEFORE the permission callback runs — when the
 * nonce is missing/invalid the current user is 0 and the permission
 * check fails with 401/403.
 *
 * /submit flow: validate (Acelera_Questions) → score (Acelera_Scoring) →
 * persist (Acelera_Submissions_Repo) → renumber (Acelera_Renaming) →
 * mark the diagnostic form lesson complete
 * (Acelera_Course_Map::form_lesson_id(), via learndash_process_mark_complete,
 * verified signature at sfwd-lms/includes/course/ld-course-progress.php:715) →
 * invalidate welcome-gate cache → result email (Acelera_Email) → fire
 * `acelera_form_completed` (Fase 5 extension point — Clientify listens
 * there; nothing Clientify/LLM-related lives in this class).
 *
 * @since      1.0.0
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/includes/form
 * @author     Daniel Amado <daniel.amadove@gmail.com>
 */
class Acelera_Rest {

	/**
	 * REST namespace.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const REST_NAMESPACE = 'acelera/v1';

	/**
	 * Allowed CV extensions → MIME types (wp_handle_upload whitelist).
	 *
	 * @since 1.0.0
	 * @var   array<string, string>
	 */
	const CV_MIMES = array(
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	);

	/**
	 * Maximum CV size in bytes (10 MB, mirrors the question definition).
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const CV_MAX_BYTES = 10485760;

	/**
	 * Register every `acelera/v1` route. Hooked to `rest_api_init`.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_routes() {

		register_rest_route(
			self::REST_NAMESPACE,
			'/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_submit' ),
				'permission_callback' => array( $this, 'permission_logged_in' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/upload-cv',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_upload_cv' ),
				'permission_callback' => array( $this, 'permission_logged_in' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_reset' ),
				'permission_callback' => array( $this, 'permission_logged_in' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/result',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_result' ),
				'permission_callback' => array( $this, 'permission_logged_in' ),
			)
		);

	}

	/**
	 * Shared permission callback: a logged-in user is required.
	 *
	 * Cookie-auth nonce (X-WP-Nonce) is verified by core before this runs;
	 * without a valid nonce get_current_user_id() is 0 here.
	 *
	 * @since  1.0.0
	 * @return bool|WP_Error
	 */
	public function permission_logged_in() {

		if ( is_user_logged_in() ) {
			return true;
		}

		return new WP_Error(
			'acelera_not_logged_in',
			__( 'Debes iniciar sesión para usar el formulario de diagnóstico.', 'formulario-acelera-ai-daniel' ),
			array( 'status' => rest_authorization_required_code() )
		);

	}

	/**
	 * POST /submit — validate, score, persist and finish the flow.
	 *
	 * Body: { "answers": { "p0_1": "...", ... } }.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_submit( WP_REST_Request $request ) {

		$user_id = get_current_user_id();
		$repo    = new Acelera_Submissions_Repo();

		// Double-submit guard (Fase 7 audit): an active submission already
		// exists → 409 with the existing result instead of a second row.
		// The user must /reset first to submit again.
		$existing = $repo->get_active_for_user( $user_id );

		if ( $existing ) {
			$existing_scores = json_decode( (string) $existing->scores, true );
			$existing_flags  = json_decode( (string) $existing->flags, true );

			return new WP_REST_Response(
				array(
					'code'    => 'acelera_already_submitted',
					'message' => __( 'Ya tienes un diagnóstico activo. Resetéalo si quieres responder de nuevo.', 'formulario-acelera-ai-daniel' ),
					'result'  => $this->build_result_payload(
						(string) $existing->module_order,
						is_array( $existing_scores ) ? $existing_scores : array(),
						is_array( $existing_flags ) ? $existing_flags : array()
					),
				),
				409
			);
		}

		$params  = $request->get_json_params();
		$answers = ( is_array( $params ) && isset( $params['answers'] ) && is_array( $params['answers'] ) )
			? $params['answers']
			: array();

		if ( array() === $answers ) {
			return new WP_REST_Response(
				array(
					'code'    => 'acelera_empty_submission',
					'message' => __( 'No se recibieron respuestas.', 'formulario-acelera-ai-daniel' ),
				),
				400
			);
		}

		// 1. Server-side validation (mirror of the JS conditional engine).
		$clean = Acelera_Questions::validate_answers( $answers );

		if ( is_wp_error( $clean ) ) {
			$field_errors = array();

			foreach ( $clean->get_error_codes() as $question_id ) {
				$field_errors[ $question_id ] = $clean->get_error_message( $question_id );
			}

			return new WP_REST_Response(
				array(
					'code'    => 'acelera_validation_failed',
					'message' => __( 'Hay respuestas inválidas o faltantes.', 'formulario-acelera-ai-daniel' ),
					'errors'  => $field_errors,
				),
				400
			);
		}

		// 2. Rule-based scoring.
		$scoring = Acelera_Scoring::score( $clean );

		// 3. Persist the submission.
		$cv_url = ( isset( $clean['cv_upload'] ) && is_string( $clean['cv_upload'] ) ) ? $clean['cv_upload'] : null;

		// Defense in depth (Fase 7 audit): the CV answer is a URL the
		// client controls — only accept files hosted in THIS user's own
		// acelera-cv folder; anything else is dropped silently.
		if ( null !== $cv_url ) {
			$uploads     = wp_get_upload_dir();
			$expected_cv = trailingslashit( $uploads['baseurl'] ) . 'acelera-cv/' . $user_id . '/';

			if ( 0 !== strpos( $cv_url, $expected_cv ) ) {
				$cv_url = null;
				unset( $clean['cv_upload'] );
			}
		}

		$submission_id = $repo->insert(
			$user_id,
			$clean,
			$scoring['scores'],
			$scoring['module_order'],
			$scoring['flags'],
			$cv_url
		);

		if ( false === $submission_id ) {
			return new WP_REST_Response(
				array(
					'code'    => 'acelera_db_error',
					'message' => __( 'No se pudo guardar el diagnóstico. Intenta de nuevo.', 'formulario-acelera-ai-daniel' ),
				),
				500
			);
		}

		// 4. Per-user renumbering meta (order + labels). Direct call by
		// design — see Acelera_Renaming class docblock.
		Acelera_Renaming::save_user_order( $user_id, $scoring['module_order'] );

		// 5. Mark the diagnostic form lesson complete. Signature verified
		// at sfwd-lms/includes/course/ld-course-progress.php:715:
		// learndash_process_mark_complete( $user_id, $postid, $onlycalculate, $course_id, $force ).
		if ( function_exists( 'learndash_process_mark_complete' ) ) {
			$form_lesson_id = Acelera_Course_Map::form_lesson_id();

			$marked = learndash_process_mark_complete(
				$user_id,
				$form_lesson_id,
				false,
				Acelera_Course_Map::COURSE_ID
			);

			// Submitting the form IS the completion criterion: if LD's
			// progression rules rejected the normal call, force it.
			if ( ! $marked ) {
				learndash_process_mark_complete(
					$user_id,
					$form_lesson_id,
					false,
					Acelera_Course_Map::COURSE_ID,
					true
				);
			}
		}

		// 6. The gate caches welcome completion per request — refresh it so
		// the response/sidebar reflect the unlock immediately.
		if ( class_exists( 'Acelera_Welcome_Gate' ) ) {
			Acelera_Welcome_Gate::invalidate_cache( $user_id );
		}

		// 7. Result email (failures are logged inside, never fatal).
		$email_sent = Acelera_Email::send_result( $user_id, $clean, $scoring );

		/**
		 * Fires after an ACELERA diagnostic submission completes.
		 *
		 * Fase 5 (Clientify) listens here. This is the ONLY integration
		 * point exposed by the form flow.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $user_id       Submitting user ID.
		 * @param int   $submission_id New acelera_form_submissions row ID.
		 * @param array $answers       Sanitized answers keyed by question ID.
		 * @param array $scoring       Scoring result {scores, module_order, flags}.
		 */
		do_action( 'acelera_form_completed', $user_id, $submission_id, $clean, $scoring );

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'result' => $this->build_result_payload( $scoring['module_order'], $scoring['scores'], $scoring['flags'] ) + array(
					'submission_id' => (int) $submission_id,
					'email_sent'    => (bool) $email_sent,
				),
			),
			200
		);

	}

	/**
	 * POST /upload-cv — store the CV under uploads/acelera-cv/{user_id}/.
	 *
	 * Multipart field name: `file`. Validates extension, real MIME (via
	 * wp_check_filetype_and_ext / finfo) and size ≤ 10 MB. Returns { url }.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_upload_cv( WP_REST_Request $request ) {

		$files = $request->get_file_params();

		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'acelera_cv_missing',
					'message' => __( 'No se recibió ningún archivo.', 'formulario-acelera-ai-daniel' ),
				),
				400
			);
		}

		$file = $files['file'];

		// Size: hard cap at 10 MB (mirrors the question definition).
		if ( ! isset( $file['size'] ) || (int) $file['size'] <= 0 || (int) $file['size'] > self::CV_MAX_BYTES ) {
			return new WP_REST_Response(
				array(
					'code'    => 'acelera_cv_too_big',
					'message' => __( 'El archivo supera el tamaño máximo de 10 MB.', 'formulario-acelera-ai-daniel' ),
				),
				400
			);
		}

		// Extension + real content MIME must agree with the whitelist.
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::CV_MIMES );

		if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! isset( self::CV_MIMES[ $check['ext'] ] ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'acelera_cv_bad_type',
					'message' => __( 'Solo se permiten archivos PDF o Word (.pdf, .doc, .docx).', 'formulario-acelera-ai-daniel' ),
				),
				400
			);
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		add_filter( 'upload_dir', array( $this, 'filter_cv_upload_dir' ) );

		$result = wp_handle_upload(
			$file,
			array(
				'test_form'                => false,
				'mimes'                    => self::CV_MIMES,
				'unique_filename_callback' => null, // wp_unique_filename handles collisions.
			)
		);

		remove_filter( 'upload_dir', array( $this, 'filter_cv_upload_dir' ) );

		// Directory-listing hardening: drop an empty index.php in the base
		// acelera-cv dir and in the per-user dir (created by the upload).
		$this->protect_cv_upload_dirs();

		if ( ! is_array( $result ) || isset( $result['error'] ) || empty( $result['url'] ) ) {
			$message = ( is_array( $result ) && isset( $result['error'] ) )
				? (string) $result['error']
				: __( 'No se pudo subir el archivo. Intenta de nuevo.', 'formulario-acelera-ai-daniel' );

			return new WP_REST_Response(
				array(
					'code'    => 'acelera_cv_upload_failed',
					'message' => $message,
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'ok'  => true,
				'url' => esc_url_raw( $result['url'] ),
			),
			200
		);

	}

	/**
	 * Ensure index.php silence files exist in the CV upload directories.
	 *
	 * Covers uploads/acelera-cv/ and uploads/acelera-cv/{user_id}/ so a
	 * server with directory listing enabled never exposes uploaded CVs.
	 *
	 * @since  1.0.0
	 * @access private
	 * @return void
	 */
	private function protect_cv_upload_dirs() {

		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] ) . 'acelera-cv';
		$dirs    = array( $base, $base . '/' . get_current_user_id() );

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$index = $dir . '/index.php';

			if ( ! file_exists( $index ) ) {
				@file_put_contents( $index, "<?php // Silence is golden\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}

	}

	/**
	 * `upload_dir` filter callback: uploads/acelera-cv/{user_id}/.
	 *
	 * Public because it must be removable with remove_filter().
	 *
	 * @since  1.0.0
	 * @param  array $dirs Upload directory data.
	 * @return array
	 */
	public function filter_cv_upload_dir( $dirs ) {

		$subdir = '/acelera-cv/' . get_current_user_id();

		$dirs['subdir'] = $subdir;
		$dirs['path']   = $dirs['basedir'] . $subdir;
		$dirs['url']    = $dirs['baseurl'] . $subdir;

		return $dirs;

	}

	/**
	 * POST /reset — soft reset of the active submission.
	 *
	 * Body: { "confirm": true }. Marks rows as 'reset' and deletes the
	 * renumbering user_meta. The LLM feedback cache
	 * (`acelera_llm_feedback_*`, Fase 6) is intentionally untouched.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_reset( WP_REST_Request $request ) {

		$confirm = $request->get_param( 'confirm' );

		if ( true !== $confirm && 'true' !== $confirm && 1 !== (int) $confirm ) {
			return new WP_REST_Response(
				array(
					'code'    => 'acelera_reset_not_confirmed',
					'message' => __( 'Debes confirmar el reseteo del diagnóstico.', 'formulario-acelera-ai-daniel' ),
				),
				400
			);
		}

		$user_id = get_current_user_id();

		$repo = new Acelera_Submissions_Repo();
		$repo->mark_reset( $user_id );

		Acelera_Renaming::clear_user( $user_id );

		return new WP_REST_Response( array( 'ok' => true ), 200 );

	}

	/**
	 * GET /result — module order, renumbered labels, scores and links of
	 * the active submission.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_result( WP_REST_Request $request ) {

		$repo   = new Acelera_Submissions_Repo();
		$active = $repo->get_active_for_user( get_current_user_id() );

		if ( ! $active ) {
			return new WP_REST_Response(
				array(
					'code'    => 'acelera_no_submission',
					'message' => __( 'No tienes un diagnóstico activo.', 'formulario-acelera-ai-daniel' ),
				),
				404
			);
		}

		$scores = json_decode( (string) $active->scores, true );
		$flags  = json_decode( (string) $active->flags, true );

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'result' => $this->build_result_payload(
					(string) $active->module_order,
					is_array( $scores ) ? $scores : array(),
					is_array( $flags ) ? $flags : array()
				),
			),
			200
		);

	}

	/**
	 * Build the shared result payload (modules, order, scores, flags).
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  string|array $module_order CSV or array of module keys.
	 * @param  array        $scores       Scores per route.
	 * @param  array        $flags        Special-rule flags.
	 * @return array
	 */
	private function build_result_payload( $module_order, array $scores, array $flags ) {

		return array(
			'module_order' => Acelera_Renaming::sanitize_order( $module_order ),
			'modules'      => Acelera_Renaming::module_items( $module_order ),
			'scores'       => $scores,
			'flags'        => $flags,
		);

	}

}
