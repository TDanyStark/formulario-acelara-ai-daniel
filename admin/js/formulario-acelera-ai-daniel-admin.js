(function( $ ) {
	'use strict';

	/**
	 * Admin behavior for the Curso Acelera pages (Fase 5.3 / 6.4).
	 *
	 * - "Probar conexión" button on the Clientify settings tab.
	 * - "Reenviar" buttons on the Sumisiones list.
	 * - "Regenerar feedback" support tool on the LLM settings tab.
	 *
	 * Depends on the `aceleraAdmin` object localized by
	 * Formulario_Acelera_Ai_Daniel_Admin::enqueue_scripts()
	 * ({ ajaxUrl, nonce, i18n }).
	 */

	$( function() {

		if ( 'undefined' === typeof window.aceleraAdmin ) {
			return;
		}

		var settings = window.aceleraAdmin;

		function renderResult( $target, ok, message ) {
			$target
				.text( message )
				.css( 'color', ok ? '#008a20' : '#d63638' );
		}

		// --- Clientify: test connection -----------------------------------.
		$( document ).on( 'click', '#acelera-clientify-test', function() {
			var $button = $( this );
			var $result = $( '#acelera-clientify-test-result' );

			$button.prop( 'disabled', true );
			renderResult( $result, true, settings.i18n.testing );

			$.post( settings.ajaxUrl, {
				action: 'acelera_clientify_test',
				nonce: settings.nonce
			} )
				.done( function( response ) {
					var ok = !! ( response && response.success );
					var message = ( response && response.data && response.data.message )
						? response.data.message
						: settings.i18n.genericKo;

					renderResult( $result, ok, message );
				} )
				.fail( function() {
					renderResult( $result, false, settings.i18n.genericKo );
				} )
				.always( function() {
					$button.prop( 'disabled', false );
				} );
		} );

		// --- Clientify: resend a failed/skipped submission ------------------.
		$( document ).on( 'click', '.acelera-clientify-resend', function() {
			var $button = $( this );
			var $result = $button.siblings( '.acelera-resend-result' );
			var submissionId = $button.data( 'submission-id' );

			$button.prop( 'disabled', true );
			renderResult( $result, true, settings.i18n.resending );

			$.post( settings.ajaxUrl, {
				action: 'acelera_clientify_resend',
				nonce: settings.nonce,
				submission_id: submissionId
			} )
				.done( function( response ) {
					var ok = !! ( response && response.success );
					var message = ( response && response.data && response.data.message )
						? response.data.message
						: settings.i18n.genericKo;

					renderResult( $result, ok, message );

					if ( ! ok ) {
						$button.prop( 'disabled', false );
					}
				} )
				.fail( function() {
					renderResult( $result, false, settings.i18n.genericKo );
					$button.prop( 'disabled', false );
				} );
		} );

		// --- LLM: filter the model <select> by the selected provider -------.
		function filterLlmModels() {
			var provider = $( '#llm_provider' ).val();
			var $model = $( '.acelera-llm-model' );

			if ( ! provider || ! $model.length ) {
				return;
			}

			$model.find( 'optgroup' ).each( function() {
				var $group = $( this );
				var match = $group.data( 'provider' ) === provider;
				$group.prop( 'disabled', ! match ).toggle( match );
			} );

			$model.find( 'option[data-provider]' ).each( function() {
				var $option = $( this );
				var match = $option.data( 'provider' ) === provider;
				$option.prop( 'disabled', ! match ).toggle( match );
			} );

			// If the current selection belongs to another provider, fall back
			// to the empty "provider default" option.
			var $selected = $model.find( 'option:selected' );
			if ( $selected.length && $selected.data( 'provider' ) && $selected.data( 'provider' ) !== provider ) {
				$model.val( '' );
			}
		}

		$( document ).on( 'change', '#llm_provider', filterLlmModels );
		filterLlmModels();

		// --- Submissions: "Ver respuestas" modal + module reorder ----------.
		var $submissionModal = $( '#acelera-submission-modal' );
		var $submissionModalContent = $submissionModal.find( '.acelera-modal-content' );

		function closeSubmissionModal() {
			$submissionModal.addClass( 'acelera-modal-hidden' );
			$submissionModalContent.empty();
		}

		function openSubmissionModal() {
			$submissionModal.removeClass( 'acelera-modal-hidden' );
		}

		function renderAnswerValue( answer ) {
			if ( 'multi' === answer.type ) {
				return ( answer.value_label || [] ).join( ', ' );
			}

			if ( 'repeater' === answer.type ) {
				var items = answer.value || [];
				var lines = [];

				$.each( items, function( index, item ) {
					var parts = [];

					$.each( item, function( key, val ) {
						parts.push( key + ': ' + val );
					} );

					lines.push( parts.join( ', ' ) );
				} );

				return lines.join( ' | ' );
			}

			if ( 'single' === answer.type ) {
				return answer.value_label || answer.value;
			}

			if ( $.isArray( answer.value ) ) {
				return answer.value.join( ', ' );
			}

			return answer.value;
		}

		function buildAnswersTable( answers ) {
			var $table = $( '<table class="widefat striped acelera-answers-table"></table>' );
			var $tbody = $( '<tbody></tbody>' );

			$.each( answers, function( index, answer ) {
				var $row = $( '<tr></tr>' );

				$row.append( $( '<td></td>' ).text( answer.label ) );
				$row.append( $( '<td></td>' ).text( renderAnswerValue( answer ) ) );

				$tbody.append( $row );
			} );

			$table.append( $tbody );

			return $table;
		}

		function buildModuleList( modules, editable ) {
			var $list = $( '<ul id="acelera-module-order-list"></ul>' );

			$.each( modules, function( index, module ) {
				var $item = $( '<li class="acelera-module-item"></li>' )
					.attr( 'data-key', module.key )
					.text( module.label );

				$list.append( $item );
			} );

			if ( editable ) {
				$list.sortable( { axis: 'y', update: function() {} } );
			} else {
				$list.addClass( 'acelera-module-list-readonly' );
			}

			return $list;
		}

		$( document ).on( 'click', '.acelera-view-submission', function() {
			var submissionId = $( this ).data( 'submission-id' );

			$submissionModalContent.empty().append( $( '<p></p>' ).text( settings.i18n.loading ) );
			openSubmissionModal();

			$.post( settings.ajaxUrl, {
				action: 'acelera_get_submission_detail',
				nonce: settings.submissionsNonce,
				submission_id: submissionId
			} )
				.done( function( response ) {
					if ( ! response || ! response.success ) {
						var errorMessage = ( response && response.data && response.data.message )
							? response.data.message
							: settings.i18n.genericKo;

						$submissionModalContent.empty().append(
							$( '<p></p>' ).css( 'color', '#d63638' ).text( errorMessage )
						);

						return;
					}

					var submission = response.data.submission;
					var isActive = !! response.data.is_active;

					$submissionModalContent.empty();

					$submissionModalContent.append(
						$( '<h2></h2>' ).text( '#' + submission.id + ' — ' + submission.user.name )
					);
					$submissionModalContent.append(
						$( '<p></p>' ).text( submission.user.email + ' · ' + submission.created_at )
					);

					$submissionModalContent.append( $( '<h3></h3>' ).text( 'Respuestas' ) );
					$submissionModalContent.append( buildAnswersTable( submission.answers ) );

					$submissionModalContent.append( $( '<h3></h3>' ).text( 'Orden de módulos' ) );

					if ( ! isActive ) {
						$submissionModalContent.append(
							$( '<p class="description"></p>' ).text( settings.i18n.notActive )
						);
					}

					var $moduleList = buildModuleList( submission.modules, isActive );
					$moduleList.data( 'submission-id', submission.id );
					$submissionModalContent.append( $moduleList );

					if ( isActive ) {
						var $saveButton = $( '<button type="button" class="button button-primary acelera-save-order"></button>' )
							.text( 'Guardar orden' );
						var $saveResult = $( '<span class="acelera-save-order-result" aria-live="polite"></span>' );

						$submissionModalContent.append(
							$( '<p></p>' ).append( $saveButton ).append( ' ' ).append( $saveResult )
						);
					}
				} )
				.fail( function() {
					$submissionModalContent.empty().append(
						$( '<p></p>' ).css( 'color', '#d63638' ).text( settings.i18n.genericKo )
					);
				} );
		} );

		$( document ).on( 'click', '.acelera-modal-close, .acelera-modal-overlay', function() {
			closeSubmissionModal();
		} );

		$( document ).on( 'keydown', function( event ) {
			if ( 'Escape' === event.key && ! $submissionModal.hasClass( 'acelera-modal-hidden' ) ) {
				closeSubmissionModal();
			}
		} );

		$( document ).on( 'click', '#acelera-submission-modal .acelera-save-order', function() {
			var $button = $( this );
			var $result = $button.siblings( '.acelera-save-order-result' );
			var $list = $( '#acelera-module-order-list' );
			var submissionId = $list.data( 'submission-id' );
			var order = $list.find( 'li' ).map( function() {
				return $( this ).data( 'key' );
			} ).get();

			$button.prop( 'disabled', true );
			renderResult( $result, true, settings.i18n.saving );

			$.post( settings.ajaxUrl, {
				action: 'acelera_save_module_order',
				nonce: settings.submissionsNonce,
				submission_id: submissionId,
				order: order
			} )
				.done( function( response ) {
					var ok = !! ( response && response.success );
					var message = ( response && response.data && response.data.message )
						? response.data.message
						: settings.i18n.genericKo;

					renderResult( $result, ok, message );

					if ( ok && response.data && response.data.modules ) {
						var $newList = buildModuleList( response.data.modules, true );
						$newList.data( 'submission-id', submissionId );
						$list.replaceWith( $newList );
					}
				} )
				.fail( function() {
					renderResult( $result, false, settings.i18n.genericKo );
				} )
				.always( function() {
					$button.prop( 'disabled', false );
				} );
		} );

		// --- LLM: clear a user's cached module feedback (Fase 6.4) ---------.
		$( document ).on( 'click', '#acelera-llm-regenerate', function() {
			var $button = $( this );
			var $result = $( '#acelera-llm-regenerate-result' );
			var user = $.trim( $( '#acelera-llm-user' ).val() || '' );
			var module = $( '#acelera-llm-module' ).val() || 'todos';

			$button.prop( 'disabled', true );
			renderResult( $result, true, settings.i18n.regenerating );

			$.post( settings.ajaxUrl, {
				action: 'acelera_llm_regenerate',
				nonce: settings.llmNonce,
				user: user,
				module: module
			} )
				.done( function( response ) {
					var ok = !! ( response && response.success );
					var message = ( response && response.data && response.data.message )
						? response.data.message
						: settings.i18n.genericKo;

					renderResult( $result, ok, message );
				} )
				.fail( function() {
					renderResult( $result, false, settings.i18n.genericKo );
				} )
				.always( function() {
					$button.prop( 'disabled', false );
				} );
		} );

	} );

})( jQuery );
