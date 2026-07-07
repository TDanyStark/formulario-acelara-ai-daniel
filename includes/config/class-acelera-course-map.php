<?php

/**
 * Course map for the ACELERA course.
 *
 * Single source of truth for every course/lesson ID used by the plugin.
 * No other file may hardcode LearnDash IDs.
 *
 * @link       https://danielamado.com
 * @since      1.0.0
 *
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/includes/config
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Dynamic map of the ACELERA course structure, derived at runtime from
 * LearnDash's native Course Builder "section headings" instead of a
 * hardcoded lesson-ID table.
 *
 * Course Builder convention this class DEPENDS on (client-confirmed):
 * 1. The course must have a section heading whose title contains
 *    "Bienvenida" (case/accent-insensitive). Its lessons are the onboarding
 *    gate ({@see self::welcome_lessons()}).
 * 2. Every section AFTER "Bienvenida", in Course Builder order, is a
 *    module: the first one is 'm1', the second 'm2', and so on — there is
 *    no fixed count. Section titles do NOT need a "Módulo N." prefix; the
 *    label shown to users is built elsewhere (see
 *    Formulario_Acelera_Ai_Daniel_Public::build_sidebar_localization()).
 * 3. One of the Bienvenida lessons must have "Formulario" or "Diagnostico"
 *    in its title so {@see self::form_lesson_id()} can find it.
 *
 * Why dynamic instead of hardcoded IDs: LearnDash's own
 * `learndash_can_user_read_step` permission filter is unreliable for this
 * course (see Formulario_Acelera_Ai_Daniel_Public::gate_can_user_read_step()
 * for the incident writeup) — it used to remove locked steps from the
 * sidebar entirely. That confirmed that ANY LearnDash API which applies
 * per-user permission filtering (like
 * `learndash_course_get_sections()` / `LDLMS_Model_Course::get_sections()`)
 * cannot be trusted to return the FULL structural list of lessons for a
 * module. This class therefore reads the raw, unfiltered section/lesson
 * structure via `learndash_course_get_steps_by_type( $course_id, 'sections' )`
 * (sfwd-lms/includes/course/ld-course-steps-functions.php:130), which
 * resolves to `LDLMS_Course_Steps::get_steps( 'sections' )`
 * (sfwd-lms/includes/classes/class-ldlms-model-course-steps.php:1118) —
 * built by `steps_grouped_sections()` (same file, line 616) directly from
 * the `course_sections` postmeta + the raw `sfwd-lessons` step keys, with
 * NO `can_user_read_step` filtering applied. That filtering only happens
 * afterwards, inside `LDLMS_Model_Course::get_sections()`
 * (class-ldlms-model-course.php:274-313), which this class deliberately
 * never calls.
 *
 * Fail-open by design: if the native section data is missing/unusable
 * (Course Builder misconfigured, LearnDash inactive, etc.) every method
 * here returns an empty result instead of guessing. Empty data means the
 * welcome gate and module detection simply do not match any lesson, so
 * nothing gets incorrectly BLOCKED — see individual method docblocks.
 *
 * The ONLY reason to touch this file again should be adding a ROUTE_MAP
 * entry for a newly added 6th+ module section — not for adding, removing
 * or reordering individual lessons, which is now fully automatic.
 *
 * @since      1.0.0
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/includes/config
 * @author     Daniel Amado <daniel.amadove@gmail.com>
 */
class Acelera_Course_Map {

	/**
	 * LearnDash course ID for "1. PROGRAMA ACELERA".
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const COURSE_ID = 16242;

	/**
	 * Fixed mini-config: module key → diagnostic-form "life area" route
	 * slug consumed by Acelera_Scoring::ROUTE_TO_MODULE.
	 *
	 * This mapping does NOT change when lessons are added/removed/
	 * reordered inside a module — only when a whole new module SECTION is
	 * added to the Course Builder (m6, m7, ...), in which case a new entry
	 * must be added here manually. See self::modules() for the fail-open
	 * behaviour when a key has no entry.
	 *
	 * @since 1.0.0
	 * @var   array<string, string>
	 */
	private const ROUTE_MAP = array(
		'm1' => 'migratoria',
		'm2' => 'empresa',
		'm3' => 'profesional',
		'm4' => 'softlanding',
		'm5' => 'inversion',
	);

	/**
	 * Per-request cache of the raw LearnDash section list.
	 *
	 * `learndash_course_get_steps_by_type()` is cheap (it reads from the
	 * course's own steps meta cache), but self::modules() and
	 * self::welcome_lessons() are called together up to ~18 times per
	 * request (sidebar rendering, gate checks, etc.), so this still saves
	 * repeated array rebuilding via steps_grouped_sections().
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    array|null Null until first computed; array() means "no
	 *                    usable sections found" (fail-open, see
	 *                    self::get_course_sections()).
	 */
	private static $sections_cache = null;

	/**
	 * Per-request cache of self::modules().
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    array<string, array{label: string, route: string, first_lesson: int, last_lesson: int, lessons: int[]}>|null
	 */
	private static $modules_cache = null;

	/**
	 * Per-request cache of self::welcome_lessons().
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    int[]|null
	 */
	private static $welcome_cache = null;

	/**
	 * Raw, UNFILTERED LearnDash course sections, in Course Builder order.
	 *
	 * Deliberately bypasses `LDLMS_Model_Course::get_sections()` /
	 * `learndash_course_get_sections()` (both apply the per-current-user
	 * `learndash_can_user_read_step` filter) in favour of the lower-level
	 * `learndash_course_get_steps_by_type( $course_id, 'sections' )`, which
	 * returns the full structural list regardless of the viewing user's
	 * progress/permissions. See the class docblock for the full
	 * investigation notes.
	 *
	 * Fail-open: any missing prerequisite (function not loaded, no
	 * sections configured in Course Builder, etc.) logs a descriptive
	 * error and returns an empty array rather than guessing.
	 *
	 * @since  1.0.0
	 * @access private
	 * @return object{order: int, ID: int, post_title: string, type: string, steps: int[]}[]
	 */
	private static function get_course_sections() {

		if ( null !== self::$sections_cache ) {
			return self::$sections_cache;
		}

		self::$sections_cache = array();

		if ( ! function_exists( 'learndash_course_get_steps_by_type' ) ) {
			error_log( 'Acelera_Course_Map: learndash_course_get_steps_by_type() no existe — LearnDash no está activo o cargado.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return self::$sections_cache;
		}

		$sections = learndash_course_get_steps_by_type( self::COURSE_ID, 'sections' );

		if ( ! is_array( $sections ) || array() === $sections ) {
			error_log( 'Acelera_Course_Map: no se encontraron secciones nativas de LearnDash para el curso 16242 — verificar Course Builder.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return self::$sections_cache;
		}

		self::$sections_cache = array_values( $sections );

		return self::$sections_cache;

	}

	/**
	 * Index (within $sections) of the "Bienvenida" section heading.
	 *
	 * Matches case/accent-insensitively via mb_stripos() against the
	 * literal "bienvenida" substring, per the Course Builder convention
	 * documented on the class. Falls back to the first section (index 0)
	 * with a warning when no title matches, so the rest of the class keeps
	 * working (degraded but not broken) even if the section gets renamed.
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  object{order: int, ID: int, post_title: string, type: string, steps: int[]}[] $sections Non-empty section list, see self::get_course_sections().
	 * @return int
	 */
	private static function find_welcome_section_index( array $sections ) {

		foreach ( $sections as $index => $section ) {

			if ( ! is_object( $section ) || ! isset( $section->post_title ) ) {
				continue;
			}

			if ( false !== mb_stripos( (string) $section->post_title, 'bienvenida' ) ) {
				return (int) $index;
			}
		}

		error_log( 'Acelera_Course_Map: no se encontró una sección con "Bienvenida" en el título — usando la primera sección como fallback.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return 0;

	}

	/**
	 * Welcome section lessons (gate — never reordered by the user).
	 *
	 * Resolves the section whose title contains "Bienvenida" (see
	 * self::find_welcome_section_index()) and returns its lesson IDs, in
	 * Course Builder order, unfiltered by the current user's progress.
	 *
	 * @since  1.0.0
	 * @return int[] Empty array when no sections could be resolved at all
	 *               (fail-open — see self::get_course_sections()).
	 */
	public static function welcome_lessons() {

		if ( null !== self::$welcome_cache ) {
			return self::$welcome_cache;
		}

		$sections = self::get_course_sections();

		if ( array() === $sections ) {
			self::$welcome_cache = array();
			return self::$welcome_cache;
		}

		$section = $sections[ self::find_welcome_section_index( $sections ) ];

		$steps = ( is_object( $section ) && isset( $section->steps ) && is_array( $section->steps ) )
			? $section->steps
			: array();

		self::$welcome_cache = array_values( array_map( 'intval', $steps ) );

		return self::$welcome_cache;

	}

	/**
	 * Lesson that hosts the diagnostic form shortcode.
	 *
	 * Iterates self::welcome_lessons() and returns the first lesson ID
	 * whose title matches "diagnostico" or "formulario"
	 * (case/accent-insensitive, via remove_accents() + stripos() — the
	 * literals are already accent-free so this correctly matches
	 * "Diagnóstico"). Falls back to the LAST welcome lesson when no title
	 * matches, per the Course Builder convention documented on the class
	 * (Bienvenida's last lesson is expected to be the form).
	 *
	 * @since  1.0.0
	 * @return int Lesson post ID, or 0 when there are no welcome lessons
	 *             at all (fail-open).
	 */
	public static function form_lesson_id() {

		$welcome_lessons = self::welcome_lessons();

		if ( array() === $welcome_lessons ) {
			error_log( 'Acelera_Course_Map: no hay lecciones de Bienvenida — no se puede determinar la lección del formulario de diagnóstico.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return 0;
		}

		foreach ( $welcome_lessons as $lesson_id ) {

			$title = function_exists( 'get_the_title' ) ? (string) get_the_title( $lesson_id ) : '';
			$title = function_exists( 'remove_accents' ) ? remove_accents( $title ) : $title;

			if ( false !== stripos( $title, 'diagnostico' ) || false !== stripos( $title, 'formulario' ) ) {
				return (int) $lesson_id;
			}
		}

		return (int) end( $welcome_lessons );

	}

	/**
	 * Module definitions keyed 'm1'..'mN'.
	 *
	 * Built from every section AFTER "Bienvenida", in Course Builder
	 * order: the first is 'm1', the second 'm2', and so on — the count is
	 * NOT assumed to be 5, so a 6th+ module section is picked up
	 * automatically (only its ROUTE_MAP entry needs to be added manually,
	 * see self::ROUTE_MAP).
	 *
	 * Each module contains:
	 * - label        (string) Section post_title, trimmed verbatim (no
	 *                "Módulo N." stripping needed — section titles never
	 *                carry that prefix).
	 * - route        (string) Form route slug from self::ROUTE_MAP, or ''
	 *                when the module key has no mapping (logged).
	 * - first_lesson (int)    First lesson ID of the module, 0 if empty.
	 * - last_lesson  (int)    Last lesson ID of the module, 0 if empty.
	 * - lessons      (int[])  Every lesson ID in the module, in order.
	 *
	 * @since  1.0.0
	 * @return array<string, array{label: string, route: string, first_lesson: int, last_lesson: int, lessons: int[]}> Empty array when no sections could be resolved at all (fail-open).
	 */
	public static function modules() {

		if ( null !== self::$modules_cache ) {
			return self::$modules_cache;
		}

		self::$modules_cache = array();

		$sections = self::get_course_sections();

		if ( array() === $sections ) {
			return self::$modules_cache;
		}

		$welcome_index   = self::find_welcome_section_index( $sections );
		$module_sections = array_slice( $sections, $welcome_index + 1 );

		$modules        = array();
		$module_number  = 1;

		foreach ( $module_sections as $section ) {

			if ( ! is_object( $section ) ) {
				continue;
			}

			$key = 'm' . $module_number;

			$steps = ( isset( $section->steps ) && is_array( $section->steps ) )
				? array_values( array_map( 'intval', $section->steps ) )
				: array();

			$route = isset( self::ROUTE_MAP[ $key ] ) ? self::ROUTE_MAP[ $key ] : '';

			if ( '' === $route ) {
				error_log( sprintf( 'Acelera_Course_Map: falta mapear route para %s en ROUTE_MAP.', $key ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			$modules[ $key ] = array(
				'label'        => trim( (string) ( $section->post_title ?? '' ) ),
				'route'        => $route,
				'first_lesson' => array() !== $steps ? reset( $steps ) : 0,
				'last_lesson'  => array() !== $steps ? end( $steps ) : 0,
				'lessons'      => $steps,
			);

			++$module_number;
		}

		self::$modules_cache = $modules;

		return self::$modules_cache;

	}

	/**
	 * Resolve the module key a given lesson belongs to.
	 *
	 * @since  1.0.0
	 * @param  int $lesson_id Lesson post ID.
	 * @return string|null 'm1'..'mN' for module lessons, 'welcome' for the
	 *                     welcome section, or null when the lesson does not
	 *                     belong to the ACELERA course map.
	 */
	public static function module_for_lesson( $lesson_id ) {
		$lesson_id = (int) $lesson_id;

		if ( in_array( $lesson_id, self::welcome_lessons(), true ) ) {
			return 'welcome';
		}

		foreach ( self::modules() as $key => $module ) {
			if ( in_array( $lesson_id, $module['lessons'], true ) ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Flat list of every lesson ID across all module sections (m1..mN).
	 *
	 * Welcome lessons are NOT included.
	 *
	 * @since  1.0.0
	 * @return int[]
	 */
	public static function all_module_lessons() {
		$lessons = array();

		foreach ( self::modules() as $module ) {
			$lessons = array_merge( $lessons, $module['lessons'] );
		}

		return $lessons;
	}

}
