<?php

/**
 * "Sumisiones" admin list (Fase 5.3).
 *
 * Minimal escaped table with the Clientify sync status per submission and
 * a "Reenviar" action for failed/skipped rows. Expects $submissions
 * (row objects), $page, $per_page, $total and $total_pages from
 * Formulario_Acelera_Ai_Daniel_Admin::render_submissions_page().
 *
 * @link       https://danielamado.com
 * @since      1.0.0
 *
 * @package    Formulario_Acelera_Ai_Daniel
 * @subpackage Formulario_Acelera_Ai_Daniel/admin/partials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$acelera_status_labels = array(
	'pending' => __( 'Pendiente', 'formulario-acelera-ai-daniel' ),
	'sent'    => __( 'Enviado', 'formulario-acelera-ai-daniel' ),
	'error'   => __( 'Error', 'formulario-acelera-ai-daniel' ),
	'skipped' => __( 'Omitido', 'formulario-acelera-ai-daniel' ),
);
?>

<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<p>
		<?php
		printf(
			/* translators: %d: total number of submissions. */
			esc_html__( '%d sumisiones en total. Estado de sincronización con Clientify por fila.', 'formulario-acelera-ai-daniel' ),
			(int) $total
		);
		?>
	</p>

	<p>
		<a
			class="button"
			href="<?php
			echo esc_url(
				add_query_arg(
					array(
						'action' => 'acelera_export_all_submissions',
						'nonce'  => wp_create_nonce( Formulario_Acelera_Ai_Daniel_Admin::SUBMISSIONS_NONCE_ACTION ),
					),
					admin_url( 'admin-ajax.php' )
				)
			);
			?>"
		>
			<?php esc_html_e( 'Exportar todas (JSON)', 'formulario-acelera-ai-daniel' ); ?>
		</a>
	</p>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'ID', 'formulario-acelera-ai-daniel' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Usuario', 'formulario-acelera-ai-daniel' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Fecha', 'formulario-acelera-ai-daniel' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Estado del formulario', 'formulario-acelera-ai-daniel' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Clientify', 'formulario-acelera-ai-daniel' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Contacto', 'formulario-acelera-ai-daniel' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Último error', 'formulario-acelera-ai-daniel' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Acciones', 'formulario-acelera-ai-daniel' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( array() === $submissions ) : ?>
				<tr>
					<td colspan="8"><?php esc_html_e( 'No hay sumisiones todavía.', 'formulario-acelera-ai-daniel' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $submissions as $submission ) : ?>
					<?php
					$acelera_user       = get_userdata( (int) $submission->user_id );
					$acelera_user_name  = $acelera_user ? $acelera_user->display_name : sprintf( '#%d', (int) $submission->user_id );
					$acelera_cf_status  = (string) $submission->clientify_status;
					$acelera_contact_id = (int) $submission->clientify_contact_id;
					$acelera_last_error = get_transient( Acelera_Clientify_Dispatcher::ERROR_TRANSIENT_PREFIX . (int) $submission->id );
					$acelera_resendable = in_array( $acelera_cf_status, array( 'error', 'skipped' ), true );
					?>
					<tr>
						<td><?php echo (int) $submission->id; ?></td>
						<td><?php echo esc_html( $acelera_user_name ); ?></td>
						<td><?php echo esc_html( (string) $submission->created_at ); ?></td>
						<td><?php echo esc_html( (string) $submission->status ); ?></td>
						<td>
							<?php
							echo esc_html(
								isset( $acelera_status_labels[ $acelera_cf_status ] )
									? $acelera_status_labels[ $acelera_cf_status ]
									: ( '' !== $acelera_cf_status ? $acelera_cf_status : '—' )
							);
							?>
						</td>
						<td>
							<?php if ( $acelera_contact_id > 0 && 'sent' === $acelera_cf_status ) : ?>
								<a href="<?php echo esc_url( 'https://app.clientify.com/contacts/' . $acelera_contact_id ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo (int) $acelera_contact_id; ?>
								</a>
							<?php elseif ( $acelera_contact_id > 0 ) : ?>
								<?php echo (int) $acelera_contact_id; ?>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td><?php echo $acelera_last_error ? esc_html( (string) $acelera_last_error ) : '&mdash;'; ?></td>
						<td>
							<button type="button" class="button button-small acelera-view-submission" data-submission-id="<?php echo (int) $submission->id; ?>">
								<?php esc_html_e( 'Ver respuestas', 'formulario-acelera-ai-daniel' ); ?>
							</button>
							<a
								class="button button-small"
								href="<?php
								echo esc_url(
									add_query_arg(
										array(
											'action'        => 'acelera_export_submission',
											'submission_id' => (int) $submission->id,
											'nonce'         => wp_create_nonce( Formulario_Acelera_Ai_Daniel_Admin::SUBMISSIONS_NONCE_ACTION ),
										),
										admin_url( 'admin-ajax.php' )
									)
								);
								?>"
							>
								<?php esc_html_e( 'Descargar JSON', 'formulario-acelera-ai-daniel' ); ?>
							</a>
							<?php if ( $acelera_resendable ) : ?>
								<button type="button" class="button button-small acelera-clientify-resend" data-submission-id="<?php echo (int) $submission->id; ?>">
									<?php esc_html_e( 'Reenviar', 'formulario-acelera-ai-daniel' ); ?>
								</button>
								<span class="acelera-resend-result" aria-live="polite"></span>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => (int) $page,
							'total'   => (int) $total_pages,
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

	<div id="acelera-submission-modal" class="acelera-modal acelera-modal-hidden">
		<div class="acelera-modal-overlay"></div>
		<div class="acelera-modal-box">
			<button type="button" class="acelera-modal-close" aria-label="<?php esc_attr_e( 'Cerrar', 'formulario-acelera-ai-daniel' ); ?>">&times;</button>
			<div class="acelera-modal-content"></div>
		</div>
	</div>
</div>
