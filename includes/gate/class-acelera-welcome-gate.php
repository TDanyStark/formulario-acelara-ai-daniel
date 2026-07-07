<?php

/**
 * Welcome gate state helper for the ACELERA course.
 *
 * Determines whether a user has completed the Welcome section
 * (lessons 16243–16246 via Acelera_Course_Map::WELCOME_LESSONS) and
 * whether a given module lesson (M1–M5) must be locked for them.
 *
 * @link       https://danielamado.com
 * @since      1.0.0
 *
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/includes/gate
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Welcome gate state helper.
 *
 * Pure state logic: no hooks are registered here. The public class wires
 * this helper into template_redirect / LearnDash filters through the
 * plugin loader. Phase 3 templates may also call these static methods
 * directly (e.g. lesson-row overrides).
 *
 * @since      1.0.0
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/includes/gate
 * @author     Daniel Amado <daniel.amadove@gmail.com>
 */
class Acelera_Welcome_Gate {

	/**
	 * Per-request cache of welcome completion, keyed by user ID.
	 *
	 * The sidebar queries the gate once per lesson row, so without this
	 * cache every page view would repeat the same LearnDash progress
	 * lookups dozens of times.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      array<int, bool>    $completed_cache    user_id => welcome completed.
	 */
	private static $completed_cache = array();

	/**
	 * Whether the user has completed all 4 Welcome lessons.
	 *
	 * Iterates Acelera_Course_Map::WELCOME_LESSONS and checks each one
	 * with learndash_is_lesson_complete(). The result is cached per
	 * request (see self::$completed_cache).
	 *
	 * Note on lesson 16246 (Formulario de Diagnóstico,
	 * Acelera_Course_Map::FORM_LESSON_ID): Phase 4 auto-marks it complete
	 * by calling learndash_process_mark_complete() when the user submits
	 * the ACELERA diagnostic form, keeping the gate and the form in sync.
	 * The user can also mark it complete with the standard LearnDash
	 * "Mark Complete" button; both paths satisfy this check.
	 *
	 * @since    1.0.0
	 * @param    int $user_id User ID to check.
	 * @return   bool True when every welcome lesson is complete.
	 */
	public static function is_welcome_completed( $user_id ) {

		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( isset( self::$completed_cache[ $user_id ] ) ) {
			return self::$completed_cache[ $user_id ];
		}

		if ( ! function_exists( 'learndash_is_lesson_complete' ) ) {
			return false;
		}

		$completed = true;

		foreach ( Acelera_Course_Map::WELCOME_LESSONS as $lesson_id ) {
			if ( ! learndash_is_lesson_complete( $user_id, $lesson_id, Acelera_Course_Map::COURSE_ID ) ) {
				$completed = false;
				break;
			}
		}

		self::$completed_cache[ $user_id ] = $completed;

		return $completed;

	}

	/**
	 * Whether a lesson must be locked for the user.
	 *
	 * True only when ALL of the following hold:
	 * - The lesson belongs to a module M1–M5 (Acelera_Course_Map). Welcome
	 *   lessons and lessons outside the ACELERA map are never locked.
	 * - The user has not completed the Welcome section.
	 * - The user has no bypass (see self::user_can_bypass()).
	 *
	 * @since    1.0.0
	 * @param    int $lesson_id Lesson post ID.
	 * @param    int $user_id   User ID.
	 * @return   bool True when access must be blocked.
	 */
	public static function is_lesson_locked( $lesson_id, $user_id ) {

		$module = Acelera_Course_Map::module_for_lesson( $lesson_id );

		// Only module lessons (m1..m5) are gated. 'welcome' and null pass.
		if ( null === $module || 'welcome' === $module ) {
			return false;
		}

		if ( self::user_can_bypass( $user_id ) ) {
			return false;
		}

		return ! self::is_welcome_completed( $user_id );

	}

	/**
	 * First incomplete Welcome lesson for the user.
	 *
	 * Used as the redirect target of the gate. Falls back to the first
	 * Welcome lesson when everything is complete (or LearnDash is absent).
	 *
	 * @since    1.0.0
	 * @param    int $user_id User ID.
	 * @return   int Lesson post ID.
	 */
	public static function first_incomplete_welcome_lesson( $user_id ) {

		$incomplete = self::incomplete_welcome_lessons( $user_id );

		return array() !== $incomplete ? $incomplete[0] : Acelera_Course_Map::WELCOME_LESSONS[0];

	}

	/**
	 * All incomplete Welcome lessons for the user, in map order.
	 *
	 * Used by the gate notice to list every pending Welcome lesson (with
	 * a direct link) instead of just the first one. Shares the same
	 * per-lesson check as first_incomplete_welcome_lesson(), which now
	 * delegates here to avoid duplicating the loop.
	 *
	 * @since    1.0.0
	 * @param    int $user_id User ID.
	 * @return   int[] Lesson post IDs still pending, same order as
	 *                 Acelera_Course_Map::WELCOME_LESSONS. Empty array
	 *                 when everything is complete or LearnDash/user are
	 *                 unavailable to check against.
	 */
	public static function incomplete_welcome_lessons( $user_id ) {

		$welcome_lessons = Acelera_Course_Map::WELCOME_LESSONS;

		if ( ! function_exists( 'learndash_is_lesson_complete' ) || (int) $user_id <= 0 ) {
			return array();
		}

		$incomplete = array();

		foreach ( $welcome_lessons as $lesson_id ) {
			if ( ! learndash_is_lesson_complete( (int) $user_id, $lesson_id, Acelera_Course_Map::COURSE_ID ) ) {
				$incomplete[] = $lesson_id;
			}
		}

		return $incomplete;

	}

	/**
	 * Whether the user bypasses the gate entirely.
	 *
	 * Admins (manage_options) are never blocked. The
	 * `acelera_welcome_gate_bypass` filter allows future exceptions
	 * (e.g. support staff, testers) without touching this class.
	 *
	 * @since    1.0.0
	 * @param    int $user_id User ID.
	 * @return   bool True when the gate must not apply.
	 */
	public static function user_can_bypass( $user_id ) {

		$user_id = (int) $user_id;

		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		/**
		 * Filters whether a user bypasses the ACELERA welcome gate.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $bypass  Default false.
		 * @param int  $user_id User being evaluated.
		 */
		return (bool) apply_filters( 'acelera_welcome_gate_bypass', false, $user_id );

	}

	/**
	 * Invalidate the per-request completion cache.
	 *
	 * Called on `learndash_lesson_completed` so that completing the 4th
	 * Welcome lesson unlocks the modules within the same request (no
	 * stale cache).
	 *
	 * @since    1.0.0
	 * @param    int $user_id Optional. User to invalidate. 0 clears all.
	 * @return   void
	 */
	public static function invalidate_cache( $user_id = 0 ) {

		$user_id = (int) $user_id;

		if ( $user_id > 0 ) {
			unset( self::$completed_cache[ $user_id ] );
		} else {
			self::$completed_cache = array();
		}

	}

}
