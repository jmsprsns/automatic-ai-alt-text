/**
 * "Write alt text with AI" in the media popup and on the Edit Media screen.
 *
 * The server saves the new text; this only puts it in the visible fields
 * (and the media popup's cached copy of the image) so it shows straight
 * away. automatic_ai_alt_text_media is passed in from PHP (see
 * Automatic_AI_Alt_Text_Media::enqueue).
 */

/* global automatic_ai_alt_text_media, wp */
( function () {
	'use strict';

	var data = window.automatic_ai_alt_text_media;

	if ( ! data ) {
		return;
	}

	var ALT = '#attachment-details-two-column-alt-text, #attachment-details-alt-text, #attachment_alt, [data-setting="alt"] textarea, [data-setting="alt"] input';
	var TITLE = '#attachment-details-two-column-title, #attachment-details-title, [data-setting="title"] input, body.post-type-attachment #title';

	// The nearest part of the page that holds this image's fields.
	function fieldsFor( button ) {
		var node = button.parentNode;

		while ( node && node !== document.body ) {
			if ( node.querySelector && node.querySelector( ALT ) ) {
				return node;
			}

			node = node.parentNode;
		}

		return document;
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.aaat-generate' ) : null;

		if ( ! button || button.disabled ) {
			return;
		}

		event.preventDefault();

		var id = button.getAttribute( 'data-id' );
		var wrap = button.closest( '.aaat-field' );
		var status = wrap ? wrap.querySelector( '.aaat-field-status' ) : null;
		var scope = fieldsFor( button );
		var alt = scope.querySelector( ALT );
		var title = scope.querySelector( TITLE );

		if ( alt && alt.value.trim() && ! window.confirm( data.i18n.replace ) ) {
			return;
		}

		var body = new FormData();

		body.append( 'action', 'automatic_ai_alt_text_generate' );
		body.append( 'nonce', data.nonce );
		body.append( 'id', id );

		button.disabled = true;

		if ( status ) {
			status.classList.remove( 'is-error' );
			status.textContent = data.i18n.working;
		}

		fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( res ) {
				button.disabled = false;

				if ( ! res || ! res.success ) {
					if ( status ) {
						status.classList.add( 'is-error' );
						status.textContent = res && res.data && res.data.message ? res.data.message : data.i18n.failed;
					}
					return;
				}

				if ( alt ) {
					alt.value = res.data.alt;
				}

				if ( title && res.data.title ) {
					title.value = res.data.title;
				}

				// Keep the popup's copy in step, so reopening the image or
				// inserting it uses the new text.
				if ( window.wp && wp.media && wp.media.model && wp.media.model.Attachment ) {
					var model = wp.media.model.Attachment.get( id );

					if ( model ) {
						model.set( { alt: res.data.alt, title: res.data.title || model.get( 'title' ) } );
					}
				}

				if ( status ) {
					status.textContent = data.i18n.done;
				}
			} )
			.catch( function () {
				button.disabled = false;

				if ( status ) {
					status.classList.add( 'is-error' );
					status.textContent = data.i18n.failed;
				}
			} );
	} );
} )();
