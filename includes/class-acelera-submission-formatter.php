<?php

/**
 * Formats a submission DB row into the JSON schema used by the admin
 * "Ver respuestas" modal and by the single/bulk JSON export endpoints.
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
 * Single source of truth for the submission → array shape consumed by
 * `admin/js/formulario-acelera-ai-daniel-admin.js` (modal rendering) and
 * `Formulario_Acelera_Ai_Daniel_Admin::ajax_export_submission()` /
 * `ajax_export_all_submissions()` (JSON downloads).
 *
 * @since      1.0.0
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/includes
 * @author     Daniel Amado <daniel.amadove@gmail.com>
 */
class Acelera_Submission_Formatter {

	/**
	 * Build the full JSON-schema array for a submission row.
	 *
	 * @since  1.0.0
	 * @param  object $row Submission row object, as returned by
	 *                     Acelera_Submissions_Repo (get_by_id/get_page/get_all).
	 * @return array Associative array matching the documented JSON schema.
	 */
	public static function to_array( $row ): array {

		$answers_raw = self::maybe_decode( isset( $row->answers ) ? $row->answers : null );
		$scores      = self::maybe_decode( isset( $row->scores ) ? $row->scores : null );
		$flags       = self::maybe_decode( isset( $row->flags ) ? $row->flags : null );

		$user = get_userdata( (int) $row->user_id );

		return array(
			'id'           => (int) $row->id,
			'status'       => (string) $row->status,
			'created_at'   => (string) $row->created_at,
			'updated_at'   => (string) $row->updated_at,
			'user'         => array(
				'id'    => (int) $row->user_id,
				'name'  => $user ? $user->display_name : '',
				'email' => $user ? $user->user_email : '',
			),
			'answers'      => self::build_answers( $answers_raw ),
			'scores'       => is_array( $scores ) ? $scores : array(),
			'flags'        => is_array( $flags ) ? $flags : array(),
			'module_order' => Acelera_Renaming::sanitize_order( isset( $row->module_order ) ? $row->module_order : '' ),
			'modules'      => Acelera_Renaming::module_items( isset( $row->module_order ) ? $row->module_order : '' ),
			'cv_url'       => isset( $row->cv_url ) ? (string) $row->cv_url : '',
			'clientify'    => array(
				'contact_id' => ! empty( $row->clientify_contact_id ) ? (int) $row->clientify_contact_id : null,
				'status'     => isset( $row->clientify_status ) ? (string) $row->clientify_status : '',
			),
		);
	}

	/**
	 * Build the `answers` array by walking Acelera_Questions::all() in
	 * order and looking up each question's stored value.
	 *
	 * Questions with no stored answer are skipped (unanswered / not shown
	 * to that respondent per the conditional engine).
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  array $answers_raw Decoded answers, keyed by question ID.
	 * @return array List of answer entries (see class docblock schema).
	 */
	private static function build_answers( array $answers_raw ): array {
		$out = array();

		foreach ( Acelera_Questions::all() as $question ) {
			$id = $question['id'];

			if ( ! array_key_exists( $id, $answers_raw ) ) {
				continue;
			}

			$value = $answers_raw[ $id ];

			if ( null === $value || ( is_array( $value ) && array() === $value ) || ( is_string( $value ) && '' === $value ) ) {
				continue;
			}

			$entry = array(
				'id'    => $id,
				'block' => (int) $question['block'],
				'type'  => $question['type'],
				'label' => $question['label'],
			);

			switch ( $question['type'] ) {

				case 'single':
					$entry['value']       = $value;
					$entry['value_label'] = self::resolve_option_label( (array) $question['options'], $value );
					break;

				case 'multi':
					$values = is_array( $value ) ? array_values( $value ) : array( $value );

					$entry['value']       = $values;
					$entry['value_label'] = array_map(
						function ( $single_value ) use ( $question ) {
							return self::resolve_option_label( (array) $question['options'], $single_value );
						},
						$values
					);
					break;

				case 'repeater':
					$entry['value'] = is_array( $value ) ? $value : array();
					break;

				default:
					$entry['value'] = $value;
					break;
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Resolve a stored single/multi option value into its human label.
	 *
	 * Falls back to the raw value itself when it doesn't match any known
	 * option (data drift protection — never fatal).
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  array $options Question 'options' array ([{value,label}, ...]).
	 * @param  mixed $value   Stored option value.
	 * @return string Resolved label, or the raw value cast to string.
	 */
	private static function resolve_option_label( array $options, $value ): string {
		foreach ( $options as $option ) {
			if ( isset( $option['value'] ) && (string) $option['value'] === (string) $value ) {
				return (string) $option['label'];
			}
		}

		return (string) $value;
	}

	/**
	 * Safely decode a DB JSON column that may already be null/array or a
	 * raw JSON string.
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  mixed $value Raw column value.
	 * @return array Decoded array, or array() when null/invalid.
	 */
	private static function maybe_decode( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : array();
	}

}
