<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://https://danielamado.com
 * @since      1.0.0
 *
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/public
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The public-facing functionality of the plugin.
 *
 * Enqueues the public assets only inside the ACELERA course (course
 * 16242 or any of its lessons/topics/quizzes).
 *
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/public
 * @author     Daniel Amado <daniel.amadove@gmail.com>
 */
class Formulario_Acelera_Ai_Daniel_Public {

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
	 * Whether the gate notice was already rendered for this request.
	 *
	 * The focus-mode action and the the_content fallback can both fire on
	 * the same page; this flag guarantees the notice shows only once.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      bool    $gate_notice_rendered
	 */
	private $gate_notice_rendered = false;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of the plugin.
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
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * Loaded only inside the ACELERA course.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		if ( ! $this->is_acelera_context() ) {
			return;
		}

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/formulario-acelera-ai-daniel-public.css', array(), $this->asset_version( 'public/css/formulario-acelera-ai-daniel-public.css' ), 'all' );

		// Sidebar accordion styles (Fase 3).
		wp_enqueue_style( $this->plugin_name . '-accordion', plugin_dir_url( __FILE__ ) . 'css/acelera-accordion.css', array( $this->plugin_name ), $this->asset_version( 'public/css/acelera-accordion.css' ), 'all' );

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * Loaded only inside the ACELERA course.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		if ( ! $this->is_acelera_context() ) {
			return;
		}

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/formulario-acelera-ai-daniel-public.js', array( 'jquery' ), $this->asset_version( 'public/js/formulario-acelera-ai-daniel-public.js' ), false );

		// Welcome gate data for the front-end (see gate JS in the same file).
		// Locked lesson IDs are only sent while the gate applies; once the
		// user completes Bienvenida (or has bypass) the array is empty and
		// the JS becomes a no-op.
		$user_id        = get_current_user_id();
		$locked_lessons = array();

		if ( $user_id > 0 && class_exists( 'Acelera_Welcome_Gate' ) && ! Acelera_Welcome_Gate::is_welcome_completed( $user_id ) && ! Acelera_Welcome_Gate::user_can_bypass( $user_id ) ) {
			$locked_lessons = array_map( 'intval', Acelera_Course_Map::all_module_lessons() );
		}

		wp_localize_script(
			$this->plugin_name,
			'aceleraGate',
			array(
				'lockedLessons' => $locked_lessons,
				'tooltip'       => __( 'Completa Bienvenida primero', 'formulario-acelera-ai-daniel' ),
			)
		);

		// Sidebar accordion (Fase 3). Vanilla JS, loaded in the footer.
		wp_enqueue_script( $this->plugin_name . '-accordion', plugin_dir_url( __FILE__ ) . 'js/acelera-accordion.js', array(), $this->asset_version( 'public/js/acelera-accordion.js' ), true );

		$current_section_id = class_exists( 'Acelera_Template_Loader' )
			? Acelera_Template_Loader::current_section_id( Acelera_Course_Map::COURSE_ID )
			: '';

		wp_localize_script(
			$this->plugin_name . '-accordion',
			'aceleraAccordion',
			array(
				'courseId'         => Acelera_Course_Map::COURSE_ID,
				'currentSectionId' => (string) $current_section_id,
			)
		);

		// BuddyBoss sidebar adapter data (the theme replaces the LD30
		// focus sidebar with its own template, so the Fase 3 template
		// overrides never render there; acelera-accordion.js rebuilds
		// the accordion client-side from this object).
		wp_localize_script(
			$this->plugin_name . '-accordion',
			'aceleraSidebar',
			$this->build_sidebar_localization()
		);

	}

	/**
	 * Build the `aceleraSidebar` localization for the BuddyBoss adapter.
	 *
	 * Lessons are referenced by permalink PATHNAME (host-agnostic,
	 * trailing-slash normalized) because the BuddyBoss sidebar markup
	 * carries no lesson IDs — only permalinks.
	 *
	 * Shape:
	 * - modules:       ordered per the current user's visual order (their
	 *                  `acelera_module_order` when a submission is active,
	 *                  natural m1..m5 otherwise). Each item: {key, label,
	 *                  collapsible} where label is the DISPLAY label
	 *                  (renumbered per user, or the natural "Módulo N. {tema}").
	 *                  Only 'welcome' is never collapsible; m1..m5 collapse.
	 * - lessonModules: permalink path → module key ('welcome' or 'm1'..'m5')
	 *                  for every welcome + module lesson.
	 * - lockedPaths:   permalink paths of the lessons currently locked by
	 *                  the welcome gate (empty once the gate is passed).
	 * - currentPath:   permalink path of the lesson governing the current
	 *                  singular request ('' outside lesson/topic/quiz).
	 * - strings:       UI strings (lock tooltip, toggle hint).
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   array
	 */
	private function build_sidebar_localization() {

		$user_id     = get_current_user_id();
		$modules_map = Acelera_Course_Map::modules();
		$natural     = array_keys( $modules_map );

		$order  = $natural;
		$labels = array();

		if ( $user_id > 0 && class_exists( 'Acelera_Renaming' ) ) {

			$user_order = Acelera_Renaming::get_user_order( $user_id );

			if ( array() !== $user_order ) {
				$order  = $user_order;
				$labels = Acelera_Renaming::get_user_labels( $user_id );
			}
		}

		$modules = array();

		foreach ( $order as $key ) {

			if ( ! isset( $modules_map[ $key ] ) ) {
				continue;
			}

			$natural_number = (int) array_search( $key, $natural, true ) + 1;

			$modules[] = array(
				'key'         => $key,
				'label'       => isset( $labels[ $key ] )
					? $labels[ $key ]
					: sprintf( 'Módulo %d. %s', $natural_number, $modules_map[ $key ]['label'] ),
				'collapsible' => ( 'welcome' !== $key ),
			);
		}

		$lesson_modules = array();

		foreach ( Acelera_Course_Map::welcome_lessons() as $lesson_id ) {

			$path = $this->lesson_path( $lesson_id );

			if ( '' !== $path ) {
				$lesson_modules[ $path ] = 'welcome';
			}
		}

		foreach ( $modules_map as $key => $module ) {
			foreach ( $module['lessons'] as $lesson_id ) {

				$path = $this->lesson_path( $lesson_id );

				if ( '' !== $path ) {
					$lesson_modules[ $path ] = $key;
				}
			}
		}

		// Locked paths: skip the whole computation once the gate is passed
		// (same condition as aceleraGate.lockedLessons above).
		$locked_paths = array();

		if (
			$user_id > 0
			&& class_exists( 'Acelera_Welcome_Gate' )
			&& ! Acelera_Welcome_Gate::is_welcome_completed( $user_id )
			&& ! Acelera_Welcome_Gate::user_can_bypass( $user_id )
		) {
			foreach ( Acelera_Course_Map::all_module_lessons() as $lesson_id ) {

				if ( ! Acelera_Welcome_Gate::is_lesson_locked( $lesson_id, $user_id ) ) {
					continue;
				}

				$path = $this->lesson_path( $lesson_id );

				if ( '' !== $path ) {
					$locked_paths[] = $path;
				}
			}
		}

		$current_path      = '';
		$current_lesson_id = class_exists( 'Acelera_Template_Loader' )
			? Acelera_Template_Loader::current_lesson_id( Acelera_Course_Map::COURSE_ID )
			: 0;

		if ( $current_lesson_id > 0 ) {
			$current_path = $this->lesson_path( $current_lesson_id );
		}

		return array(
			'modules'       => $modules,
			'lessonModules' => $lesson_modules,
			'lockedPaths'   => array_values( $locked_paths ),
			'currentPath'   => $current_path,
			'strings'       => array(
				'tooltip'       => __( 'Completa Bienvenida primero', 'formulario-acelera-ai-daniel' ),
				'toggleSection' => __( 'Mostrar u ocultar las lecciones de la sección', 'formulario-acelera-ai-daniel' ),
			),
		);

	}

	/**
	 * Permalink pathname of a post, trailing-slash normalized.
	 *
	 * Host-agnostic on purpose: the BuddyBoss adapter matches sidebar
	 * anchors by pathname so staging/production hosts both work.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @param    int $post_id Post ID.
	 * @return   string Pathname (e.g. '/lessons/foo/') or '' when unresolvable.
	 */
	private function lesson_path( $post_id ) {

		$permalink = get_permalink( (int) $post_id );

		if ( ! $permalink ) {
			return '';
		}

		$path = wp_parse_url( $permalink, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		return trailingslashit( $path );

	}

	/* ---------------------------------------------------------------------
	 * Welcome gate (Fase 2) — hooks registered through the loader in
	 * Formulario_Acelera_Ai_Daniel::define_public_hooks().
	 * ------------------------------------------------------------------- */

	/**
	 * Layer A — hard redirect away from locked module lessons.
	 *
	 * Runs on `template_redirect`. Covers lesson singulars directly and,
	 * defensively, topic/quiz singulars by resolving their parent lesson
	 * with learndash_get_lesson_id(). Logged-out visitors are left to
	 * LearnDash's own enrollment/login handling.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function gate_template_redirect() {

		if ( ! is_user_logged_in() || ! class_exists( 'Acelera_Welcome_Gate' ) ) {
			return;
		}

		$lesson_id = $this->gate_resolve_request_lesson_id();

		if ( 0 === $lesson_id ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! Acelera_Welcome_Gate::is_lesson_locked( $lesson_id, $user_id ) ) {
			return;
		}

		$target = Acelera_Welcome_Gate::first_incomplete_welcome_lesson( $user_id );

		wp_safe_redirect( add_query_arg( 'acelera_gate', '1', get_permalink( $target ) ) );
		exit;

	}

	/**
	 * Resolve the lesson ID governing the current singular request.
	 *
	 * - sfwd-lessons: the post itself.
	 * - sfwd-topic / sfwd-quiz: the parent lesson via learndash_get_lesson_id().
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   int Lesson post ID, or 0 when the request is not gated content.
	 */
	private function gate_resolve_request_lesson_id() {

		if ( is_singular( 'sfwd-lessons' ) ) {
			return (int) get_the_ID();
		}

		if ( ( is_singular( 'sfwd-topic' ) || is_singular( 'sfwd-quiz' ) ) && function_exists( 'learndash_get_lesson_id' ) ) {
			return (int) learndash_get_lesson_id( get_the_ID(), Acelera_Course_Map::COURSE_ID );
		}

		return 0;

	}

	/**
	 * Gate notice inside LearnDash Focus Mode.
	 *
	 * Hooked to `learndash-focus-content-content-before` (fires in
	 * sfwd-lms/themes/ld30/templates/focus/index.php right before the step
	 * content). Note: the hook named in the plan, "learndash-focus-content-before",
	 * does not exist in LD 5.1.4; this is the real one.
	 *
	 * @since    1.0.0
	 * @param    int $course_id Course ID (passed by the template).
	 * @param    int $user_id   User ID (passed by the template).
	 * @return   void
	 */
	public function gate_render_focus_notice( $course_id = 0, $user_id = 0 ) {

		if ( (int) $course_id !== Acelera_Course_Map::COURSE_ID ) {
			return;
		}

		echo $this->gate_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built and escaped in gate_notice_html().

	}

	/**
	 * Gate notice fallback for non Focus Mode rendering.
	 *
	 * Prepends the notice to the_content on welcome-lesson singulars when
	 * the redirect query arg is present. Skipped if the focus-mode action
	 * already rendered it (shared $gate_notice_rendered flag).
	 *
	 * @since    1.0.0
	 * @param    string $content Post content.
	 * @return   string
	 */
	public function gate_prepend_notice( $content ) {

		if ( ! is_singular( 'sfwd-lessons' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		return $this->gate_notice_html() . $content;

	}

	/**
	 * Build the gate notice markup (at most once per request).
	 *
	 * Empty string unless ?acelera_gate=1 is present AND the current
	 * singular is one of the Welcome lessons. Lists the specific pending
	 * Welcome lessons (Acelera_Welcome_Gate::incomplete_welcome_lessons())
	 * as direct links so the user knows exactly what's left, falling back
	 * to the generic message if that list is unexpectedly empty.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   string Escaped HTML or empty string.
	 */
	private function gate_notice_html() {

		if ( $this->gate_notice_rendered ) {
			return '';
		}

		$gate_flag = isset( $_GET['acelera_gate'] ) ? sanitize_text_field( wp_unslash( $_GET['acelera_gate'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.

		if ( '1' !== $gate_flag ) {
			return '';
		}

		if ( ! in_array( (int) get_the_ID(), Acelera_Course_Map::welcome_lessons(), true ) ) {
			return '';
		}

		$this->gate_notice_rendered = true;

		$generic_message = esc_html__( 'Debes completar el módulo de Bienvenida antes de acceder al resto del curso.', 'formulario-acelera-ai-daniel' );

		if ( ! class_exists( 'Acelera_Welcome_Gate' ) ) {
			return sprintf( '<div class="acelera-gate-notice" role="alert">%s</div>', $generic_message );
		}

		$pending_lessons = Acelera_Welcome_Gate::incomplete_welcome_lessons( get_current_user_id() );

		if ( array() === $pending_lessons ) {
			return sprintf( '<div class="acelera-gate-notice" role="alert">%s</div>', $generic_message );
		}

		$items = '';

		foreach ( $pending_lessons as $lesson_id ) {

			$permalink = get_permalink( $lesson_id );

			if ( ! $permalink ) {
				continue;
			}

			$items .= sprintf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( $permalink ),
				esc_html( get_the_title( $lesson_id ) )
			);
		}

		if ( '' === $items ) {
			return sprintf( '<div class="acelera-gate-notice" role="alert">%s</div>', $generic_message );
		}

		return sprintf(
			'<div class="acelera-gate-notice" role="alert"><p>%1$s</p><ul class="acelera-gate-notice-list">%2$s</ul></div>',
			esc_html__( 'Debes completar el módulo de Bienvenida antes de acceder al resto del curso. Te falta completar:', 'formulario-acelera-ai-daniel' ),
			$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_url()/esc_html() above.
		);

	}

	/**
	 * Layer B (DEPRECATED — now a no-op) — former LearnDash read-step filter.
	 *
	 * Hooked to `learndash_can_user_read_step` (apply_filters in
	 * sfwd-lms/includes/classes/class-ldlms-model-course.php:264). This
	 * method used to return `false` for locked M1–M5 steps for "visual/
	 * logical consistency", but that filter is NOT cosmetic: LearnDash
	 * core (class-ldlms-model-course.php get_lessons()/get_sections())
	 * uses its result to unset() the step from the array that feeds the
	 * course sidebar and Focus Mode navigation whenever
	 * LEARNDASH_COURSE_STEP_READ_CHECK is true (the default, and the
	 * value in this install — see learndash-scalar-constants.php).
	 * Returning false here was therefore REMOVING locked lessons from the
	 * sidebar entirely instead of just marking them locked, which is why
	 * M1–M5 disappeared for users who hadn't finished Bienvenida.
	 *
	 * Real access control does not depend on this filter:
	 * - Hard enforcement: gate_template_redirect() (hooked to
	 *   `template_redirect`) redirects away from any locked lesson/topic/
	 *   quiz singular before it ever renders.
	 * - Visual signaling: gate_lesson_row_class() adds `.acelera-locked`
	 *   to the row (padlock + opacity via CSS) and the public JS
	 *   neutralizes clicks on those rows.
	 *
	 * The method is kept (rather than deleted) as a no-op passthrough for
	 * backward compatibility and to document why it must never again
	 * return false — see includes/class-formulario-acelera-ai-daniel.php
	 * define_public_hooks() for the (now removed) hook registration.
	 *
	 * @since    1.0.0
	 * @param    bool $user_can_read True if the user can read the step.
	 * @param    int  $step_post_id  Step post ID.
	 * @param    int  $course_id     Course post ID.
	 * @return   bool Always $user_can_read, unmodified.
	 */
	public function gate_can_user_read_step( $user_can_read, $step_post_id, $course_id ) {

		return $user_can_read;

	}

	/**
	 * Add the `acelera-locked` class to locked lesson rows.
	 *
	 * Approach chosen for 2.3: LD 5.1.4 ships two real filters that cover
	 * both listings without template overrides, so PHP filters (not JS DOM
	 * scanning) are the primary mechanism:
	 * - `learndash_lesson_row_class` — course page listing rows
	 *   (sfwd-lms/themes/ld30/includes/helpers.php:458).
	 * - `learndash-nav-widget-lesson-class` — focus sidebar / nav widget rows
	 *   (sfwd-lms/themes/ld30/templates/widgets/navigation/lesson-row.php:71).
	 * Both pass ($classes, $lesson) where $lesson is the LD lesson array
	 * with $lesson['post']. The anchor href cannot be filtered there, so a
	 * small JS layer neutralizes clicks on `.acelera-locked` rows and adds
	 * the tooltip (see public JS).
	 *
	 * @since    1.0.0
	 * @param    string $classes Space-separated row class names.
	 * @param    mixed  $lesson  LD lesson array ({post: WP_Post, ...}), WP_Post or ID.
	 * @return   string
	 */
	public function gate_lesson_row_class( $classes, $lesson = null ) {

		if ( ! class_exists( 'Acelera_Welcome_Gate' ) ) {
			return $classes;
		}

		$lesson_id = 0;

		if ( is_array( $lesson ) && isset( $lesson['post'] ) && $lesson['post'] instanceof WP_Post ) {
			$lesson_id = (int) $lesson['post']->ID;
		} elseif ( $lesson instanceof WP_Post ) {
			$lesson_id = (int) $lesson->ID;
		} elseif ( is_numeric( $lesson ) ) {
			$lesson_id = (int) $lesson;
		}

		if ( $lesson_id && Acelera_Welcome_Gate::is_lesson_locked( $lesson_id, get_current_user_id() ) ) {
			$classes .= ' acelera-locked';
		}

		return $classes;

	}

	/**
	 * Invalidate the gate cache when a lesson is completed.
	 *
	 * Hooked to `learndash_lesson_completed` (do_action in
	 * sfwd-lms/includes/course/ld-course-progress.php:979), which receives
	 * a single array: {user: WP_User, course: WP_Post, lesson: WP_Post,
	 * progress: array}. Clearing the cache makes the unlock immediate when
	 * the 4th Welcome lesson is completed within the same request.
	 *
	 * @since    1.0.0
	 * @param    array $lesson_data Lesson completion data from LearnDash.
	 * @return   void
	 */
	public function gate_on_lesson_completed( $lesson_data ) {

		if ( ! class_exists( 'Acelera_Welcome_Gate' ) ) {
			return;
		}

		$user_id = 0;

		if ( is_array( $lesson_data ) && isset( $lesson_data['user']->ID ) ) {
			$user_id = (int) $lesson_data['user']->ID;
		}

		Acelera_Welcome_Gate::invalidate_cache( $user_id );

	}

	/**
	 * Whether the current request is inside the ACELERA course.
	 *
	 * True on the course page itself or on any of its lessons, topics or
	 * quizzes, resolved through learndash_get_course_id().
	 *
	 * @since    1.0.0
	 * @access   private
	 * @return   bool
	 */
	private function is_acelera_context() {

		if ( ! function_exists( 'learndash_get_course_id' ) || ! class_exists( 'Acelera_Course_Map' ) ) {
			return false;
		}

		$course_id = learndash_get_course_id();

		return (int) $course_id === Acelera_Course_Map::COURSE_ID;

	}

}
