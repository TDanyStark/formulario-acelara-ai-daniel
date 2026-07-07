<?php

/**
 * Repository for the acelera_form_submissions table.
 *
 * @link       https://danielamado.com
 * @since      1.0.0
 *
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Data access layer for form submissions.
 *
 * Resets are soft: rows are flagged with status 'reset' and kept as history.
 * The active submission for a user is always the latest row with
 * status 'completed'.
 *
 * @since      1.0.0
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/includes
 * @author     Daniel Amado <daniel.amadove@gmail.com>
 */
class Acelera_Submissions_Repo {

	/**
	 * Status of an active (current) submission.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const STATUS_COMPLETED = 'completed';

	/**
	 * Status of a soft-reset submission (kept as history).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const STATUS_RESET = 'reset';

	/**
	 * Fully qualified table name.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'acelera_form_submissions';
	}

	/**
	 * Get the active submission for a user.
	 *
	 * The active submission is the most recent row with status 'completed'.
	 *
	 * @since  1.0.0
	 * @param  int $user_id WordPress user ID.
	 * @return object|null Row object or null when the user has no active submission.
	 */
	public function get_active_for_user( $user_id ) {
		global $wpdb;

		$table = self::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND status = %s ORDER BY id DESC LIMIT 1",
				(int) $user_id,
				self::STATUS_COMPLETED
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Insert a new submission for a user.
	 *
	 * Array values for $answers, $scores and $flags are JSON-encoded before
	 * being stored.
	 *
	 * @since  1.0.0
	 * @param  int          $user_id      WordPress user ID.
	 * @param  array|string $answers      Raw answers per question (P0.1, P1.1, ...).
	 * @param  array|string $scores       Optional. Score per route, e.g. {migratoria: 80}.
	 * @param  string       $module_order Optional. Module order, e.g. "m2,m1,m4,m3,m5".
	 * @param  array|string $flags        Optional. Flags such as bloqueador_migratorio.
	 * @param  string       $cv_url       Optional. URL of the uploaded CV.
	 * @return int|false New row ID on success, false on failure.
	 */
	public function insert( $user_id, $answers, $scores = null, $module_order = null, $flags = null, $cv_url = null ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'user_id'      => (int) $user_id,
				'answers'      => $this->maybe_encode( $answers ),
				'scores'       => is_null( $scores ) ? null : $this->maybe_encode( $scores ),
				'module_order' => is_null( $module_order ) ? null : (string) $module_order,
				'flags'        => is_null( $flags ) ? null : $this->maybe_encode( $flags ),
				'cv_url'       => is_null( $cv_url ) ? null : esc_url_raw( $cv_url ),
				'status'       => self::STATUS_COMPLETED,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Soft-reset all active submissions for a user.
	 *
	 * Marks every 'completed' row as 'reset' while keeping the rows as
	 * history.
	 *
	 * @since  1.0.0
	 * @param  int $user_id WordPress user ID.
	 * @return int|false Number of rows updated, or false on failure.
	 */
	public function mark_reset( $user_id ) {
		global $wpdb;

		$table = self::table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s WHERE user_id = %d AND status = %s",
				self::STATUS_RESET,
				current_time( 'mysql' ),
				(int) $user_id,
				self::STATUS_COMPLETED
			)
		);
	}

	/**
	 * Get a submission row by ID.
	 *
	 * Used by the Clientify cron handler (Fase 5).
	 *
	 * @since  1.0.0
	 * @param  int $id Submission row ID.
	 * @return object|null Row object or null when not found.
	 */
	public function get_by_id( $id ) {
		global $wpdb;

		$table = self::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				(int) $id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Get a page of submissions, newest first.
	 *
	 * Backs the "Sumisiones" admin list (Fase 5.3).
	 *
	 * @since  1.0.0
	 * @param  int $per_page Rows per page (1–100).
	 * @param  int $page     1-based page number.
	 * @return object[] Row objects (possibly empty).
	 */
	public function get_page( $per_page = 20, $page = 1 ) {
		global $wpdb;

		$per_page = max( 1, min( 100, (int) $per_page ) );
		$page     = max( 1, (int) $page );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = self::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total number of submission rows (all statuses).
	 *
	 * @since  1.0.0
	 * @return int
	 */
	public function count_all() {
		global $wpdb;

		$table = self::table_name();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Update the Clientify sync data of a submission.
	 *
	 * @since  1.0.0
	 * @param  int      $id         Submission row ID.
	 * @param  int|null $contact_id Clientify contact ID (null if not created).
	 * @param  string   $status     Sync status: pending | sent | error | skipped.
	 * @return int|false Number of rows updated, or false on failure.
	 */
	public function update_clientify( $id, $contact_id, $status ) {
		global $wpdb;

		return $wpdb->update(
			self::table_name(),
			array(
				'clientify_contact_id' => is_null( $contact_id ) ? null : (int) $contact_id,
				'clientify_status'     => (string) $status,
				'updated_at'           => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Update the module order of a submission.
	 *
	 * Used by the "Sumisiones" admin modal drag-and-drop reorder tool. Also
	 * resyncs the student's `acelera_module_order`/`acelera_module_labels`
	 * user_meta via Acelera_Renaming::save_user_order() — that call is the
	 * caller's responsibility, not this method's.
	 *
	 * @since  1.0.0
	 * @param  int    $id           Submission row ID.
	 * @param  string $module_order Module order, e.g. "m2,m1,m4,m3,m5".
	 * @return int|false Number of rows updated, or false on failure.
	 */
	public function update_module_order( $id, $module_order ) {
		global $wpdb;

		return $wpdb->update(
			self::table_name(),
			array(
				'module_order' => (string) $module_order,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get every submission row, newest first.
	 *
	 * Backs the "Exportar todas (JSON)" admin tool. No LIMIT/OFFSET: the
	 * dataset is small (a few hundred rows), so pagination is unnecessary.
	 *
	 * @since  1.0.0
	 * @param  string|null $status Optional. Filter by status (e.g. 'completed').
	 * @return object[] Row objects (possibly empty).
	 */
	public function get_all( $status = null ) {
		global $wpdb;

		$table = self::table_name();

		if ( null !== $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC",
					(string) $status
				)
			);
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * JSON-encode arrays/objects, pass strings through untouched.
	 *
	 * @since  1.0.0
	 * @param  mixed $value Value to normalize for storage.
	 * @return string
	 */
	private function maybe_encode( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}

		return (string) $value;
	}

}
