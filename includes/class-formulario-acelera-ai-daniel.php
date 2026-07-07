<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://https://danielamado.com
 * @since      1.0.0
 *
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/includes
 * @author     Daniel Amado <daniel.amadove@gmail.com>
 */
class Formulario_Acelera_Ai_Daniel {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Formulario_Acelera_Ai_Daniel_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'FORMULARIO_ACELERA_AI_DANIEL_VERSION' ) ) {
			$this->version = FORMULARIO_ACELERA_AI_DANIEL_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'formulario-acelera-ai-daniel';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Formulario_Acelera_Ai_Daniel_Loader. Orchestrates the hooks of the plugin.
	 * - Formulario_Acelera_Ai_Daniel_i18n. Defines internationalization functionality.
	 * - Formulario_Acelera_Ai_Daniel_Admin. Defines all hooks for the admin area.
	 * - Formulario_Acelera_Ai_Daniel_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-formulario-acelera-ai-daniel-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-formulario-acelera-ai-daniel-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-formulario-acelera-ai-daniel-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-formulario-acelera-ai-daniel-public.php';

		/**
		 * Hardcoded map of the ACELERA course (course/lesson IDs, modules, routes).
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/config/class-acelera-course-map.php';

		/**
		 * Repository for the acelera_form_submissions table.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-acelera-submissions-repo.php';

		/**
		 * Formats a submission row into the JSON schema shared by the
		 * "Sumisiones" admin modal and the single/bulk JSON export tools.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-acelera-submission-formatter.php';

		/**
		 * Global read access to the plugin settings (acelera_settings option).
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-acelera-settings.php';

		/**
		 * Welcome gate state helper (Fase 2): blocks M1–M5 lessons until
		 * the Bienvenida lessons are completed.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/gate/class-acelera-welcome-gate.php';

		/**
		 * Template loader (Fase 3): redirects LD30 navigation widget
		 * templates to plugin overrides inside the ACELERA course and
		 * provides the sidebar accordion grouping helpers.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-acelera-template-loader.php';

		/**
		 * Diagnostic form (Fase 4 — Part A): hardcoded question definitions
		 * with the conditional engine and server-side validation.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/form/class-acelera-questions.php';

		/**
		 * Diagnostic form (Fase 4 — Part A): pure rule-based scoring engine
		 * (route scores 0–100, personalized module order, flags).
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/form/class-acelera-scoring.php';

		/**
		 * Diagnostic form (Fase 4 — Part A): result email (called explicitly
		 * from the /submit handler, no hooks).
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/form/class-acelera-email.php';

		/**
		 * Diagnostic form (Fase 4 — Part B): per-user module renumbering
		 * (user_meta order/labels) + sidebar reordering and title filters.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/form/class-acelera-renaming.php';

		/**
		 * Diagnostic form (Fase 4 — Part B): REST endpoints (acelera/v1 —
		 * submit, upload-cv, reset, result).
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/form/class-acelera-rest.php';

		/**
		 * Diagnostic form (Fase 4 — Part B): [acelera_form] shortcode with
		 * the form / result / logged-out states and asset enqueueing.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/form/class-acelera-form-shortcode.php';

		/**
		 * Clientify CRM (Fase 5): API client (contact + note + note HTML
		 * builder) and the async cron dispatcher with retry/backoff.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/crm/class-acelera-clientify.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/crm/class-acelera-clientify-dispatcher.php';

		/**
		 * LLM feedback (Fase 6): multi-provider client (Anthropic/OpenAI)
		 * and the [acelera_feedback] shortcode + REST endpoint + cache.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/llm/class-acelera-llm-client.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/llm/class-acelera-module-feedback.php';

		$this->loader = new Formulario_Acelera_Ai_Daniel_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Formulario_Acelera_Ai_Daniel_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Formulario_Acelera_Ai_Daniel_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Formulario_Acelera_Ai_Daniel_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

		// Settings page (menu + Settings API registration).
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_settings_menu' );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'register_settings' );

		// Clientify admin AJAX (Fase 5.3): test connection + resend.
		// admin-ajax requests always load admin hooks, so this is the
		// right section for them.
		$this->loader->add_action( 'wp_ajax_acelera_clientify_test', $plugin_admin, 'ajax_clientify_test' );
		$this->loader->add_action( 'wp_ajax_acelera_clientify_resend', $plugin_admin, 'ajax_clientify_resend' );

		// Submissions detail modal + module reorder + JSON exports.
		$this->loader->add_action( 'wp_ajax_acelera_get_submission_detail', $plugin_admin, 'ajax_get_submission_detail' );
		$this->loader->add_action( 'wp_ajax_acelera_save_module_order', $plugin_admin, 'ajax_save_module_order' );
		$this->loader->add_action( 'wp_ajax_acelera_export_submission', $plugin_admin, 'ajax_export_submission' );
		$this->loader->add_action( 'wp_ajax_acelera_export_all_submissions', $plugin_admin, 'ajax_export_all_submissions' );

		// LLM feedback support tool (Fase 6.4): clear a user's cached
		// feedback so it regenerates on their next visit.
		$this->loader->add_action( 'wp_ajax_acelera_llm_regenerate', $plugin_admin, 'ajax_llm_regenerate' );

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		// Guard: without LearnDash there is no course to integrate with.
		// Show an admin notice and skip every public-facing hook.
		if ( ! defined( 'LEARNDASH_VERSION' ) ) {
			$this->loader->add_action( 'admin_notices', $this, 'learndash_missing_notice' );
			return;
		}

		$plugin_public = new Formulario_Acelera_Ai_Daniel_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

		// Welcome gate (Fase 2).
		// Layer A: hard redirect away from locked M1–M5 lessons.
		$this->loader->add_action( 'template_redirect', $plugin_public, 'gate_template_redirect' );

		// Gate notice on the redirect destination (Focus Mode + the_content fallback).
		$this->loader->add_action( 'learndash-focus-content-content-before', $plugin_public, 'gate_render_focus_notice', 10, 2 );
		$this->loader->add_filter( 'the_content', $plugin_public, 'gate_prepend_notice' );

		// Layer B removed: `learndash_can_user_read_step` returning false
		// made LearnDash core unset() the locked M1–M5 steps from the
		// sidebar/Focus Mode arrays (LEARNDASH_COURSE_STEP_READ_CHECK is
		// true in this install), which hid the modules entirely instead of
		// just locking them. Real enforcement is `template_redirect`
		// (gate_template_redirect) and visual locking is
		// `learndash_lesson_row_class` / `learndash-nav-widget-lesson-class`
		// (gate_lesson_row_class) below. gate_can_user_read_step() is kept
		// as a documented no-op in the public class but intentionally no
		// longer hooked here.

		// Sidebar / course listing signaling: add .acelera-locked to rows.
		$this->loader->add_filter( 'learndash_lesson_row_class', $plugin_public, 'gate_lesson_row_class', 10, 2 );
		$this->loader->add_filter( 'learndash-nav-widget-lesson-class', $plugin_public, 'gate_lesson_row_class', 10, 2 );

		// Free-mode compatibility: immediate unlock when a lesson completes.
		$this->loader->add_action( 'learndash_lesson_completed', $plugin_public, 'gate_on_lesson_completed', 10, 1 );

		// Sidebar accordion (Fase 3): LD30 navigation widget template
		// overrides, ACELERA course only. Priority 999 so the plugin wins
		// over theme `learndash/` folder overrides (plan risk note).
		$plugin_template_loader = new Acelera_Template_Loader();

		$this->loader->add_filter( 'learndash_template', $plugin_template_loader, 'filter_template', 999, 5 );

		// Diagnostic form (Fase 4 — Part B). The whole feature set stays
		// inside the LEARNDASH_VERSION guard: the plugin is LD-specific
		// (submit marks the diagnostic form lesson complete via
		// Acelera_Course_Map::form_lesson_id(), links point to LD lessons)
		// and the admin notice above already explains the requirement.

		// [acelera_form] shortcode (diagnostic form lesson content).
		$plugin_shortcode = new Acelera_Form_Shortcode( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'init', $plugin_shortcode, 'register_shortcode' );

		// REST endpoints acelera/v1 (submit / upload-cv / reset / result).
		$plugin_rest = new Acelera_Rest();

		$this->loader->add_action( 'rest_api_init', $plugin_rest, 'register_routes' );

		// Per-user renumbering: sidebar section headings (Fase 3 filter)
		// and defensive "Módulo X" lesson-title renumbering.
		$plugin_renaming = new Acelera_Renaming();

		$this->loader->add_filter( 'acelera_section_title', $plugin_renaming, 'filter_section_title', 10, 2 );
		$this->loader->add_filter( 'the_title', $plugin_renaming, 'filter_the_title', 10, 2 );

		// Clientify async dispatch (Fase 5). Registered here — NOT in
		// define_admin_hooks() — because both hooks must run on non-admin
		// requests: `acelera_form_completed` fires during the REST /submit
		// request and `acelera_send_to_clientify` during WP-Cron requests,
		// and the loader registers these callbacks on EVERY request type
		// anyway (the admin/public split is organizational). Staying inside
		// the LEARNDASH_VERSION guard is safe: the hook only ever fires
		// from the REST endpoint, which is itself guard-registered, and LD
		// is a hard requirement of the plugin. Worst case (LD deactivated
		// with events still queued) pending cron events fire without a
		// callback and are dropped silently.
		$plugin_clientify_dispatcher = new Acelera_Clientify_Dispatcher();

		$this->loader->add_action( 'acelera_form_completed', $plugin_clientify_dispatcher, 'on_form_completed', 10, 4 );
		$this->loader->add_action( Acelera_Clientify_Dispatcher::CRON_HOOK, $plugin_clientify_dispatcher, 'handle_cron', 10, 2 );

		// LLM module feedback (Fase 6): [acelera_feedback module="mX"]
		// shortcode (placed by the admin inside LD lesson content) and its
		// GET acelera/v1/feedback/{module} endpoint. Inside the LD guard
		// like the rest of the public feature set: the shortcode only
		// lives in LearnDash content of course 16242.
		$plugin_feedback = new Acelera_Module_Feedback( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'init', $plugin_feedback, 'register_shortcode' );
		$this->loader->add_action( 'rest_api_init', $plugin_feedback, 'register_routes' );

	}

	/**
	 * Admin notice shown when LearnDash is not active.
	 *
	 * @since    1.0.0
	 */
	public function learndash_missing_notice() {

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Formulario Acelera AI Daniel requiere LearnDash activo. Las funciones públicas del plugin están deshabilitadas hasta que se active LearnDash.', 'formulario-acelera-ai-daniel' )
		);

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Formulario_Acelera_Ai_Daniel_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
