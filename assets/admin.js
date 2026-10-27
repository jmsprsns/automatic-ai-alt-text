/**
 * Content Powered > Automatic AI Alt-Text page behaviour. Only loaded on that page.
 *
 * The provider switch, the connection test, the Bulk Generate runner (test
 * run, unlock, batches) and the History tab's Edit and "Write again".
 * automatic_ai_alt_text_admin.ajaxUrl, .nonce, .i18n and the rest are passed
 * in from PHP (see Automatic_AI_Alt_Text_Admin::enqueue_assets).
 *
 * Everything the AI writes is put on the page with textContent, never as HTML.
 */

/* global automatic_ai_alt_text_admin */
( function () {
	'use strict';

	var data = window.automatic_ai_alt_text_admin;
	var i18n = data ? data.i18n : {};

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		initProvider();
		initDepends();
		initPrompt();
		initTest();
		initBulk();
		initHistory();
	} );

	// Helpers ------------------------------------------------------------------

	function post( action, fields ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( 'nonce', fields && fields.nonce ? fields.nonce : data.nonce );

		Object.keys( fields || {} ).forEach( function ( key ) {
			if ( 'nonce' !== key ) {
				body.append( key, fields[ key ] );
			}
		} );

		return fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json().catch( function () {
				return { success: false, data: { message: i18n.failed } };
			} );
		} );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( undefined !== text && null !== text ) {
			node.textContent = String( text );
		}

		return node;
	}

	function delay( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	function format( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var index = 0;

		return String( text ).replace( /%(\d\$)?[sd]/g, function ( match, position ) {
			var value = position ? args[ parseInt( position, 10 ) - 1 ] : args[ index++ ];

			return undefined === value ? '' : String( value );
		} );
	}

	function money( micros ) {
		var dollars = ( micros || 0 ) / 1000000;

		if ( dollars <= 0 ) {
			return '$0.00';
		}

		if ( dollars < 0.01 ) {
			return '$' + dollars.toFixed( 5 ).replace( /0+$/, '' ).replace( /\.$/, '' );
		}

		return '$' + dollars.toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	function spinner() {
		var icon = el( 'span', 'dashicons dashicons-update aaat-spin' );

		icon.setAttribute( 'aria-hidden', 'true' );

		return icon;
	}

	// Settings -------------------------------------------------------------------

	function initProvider() {
		var radios = document.querySelectorAll( 'input[name="automatic_ai_alt_text_settings[provider]"]' );

		function show() {
			var chosen = document.querySelector( 'input[name="automatic_ai_alt_text_settings[provider]"]:checked' );
			var value = chosen ? chosen.value : 'openai';

			document.querySelectorAll( '[data-aaat-provider]' ).forEach( function ( row ) {
				row.hidden = row.getAttribute( 'data-aaat-provider' ) !== value;
			} );
		}

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', show );
		} );

		document.querySelectorAll( '.aaat-model-select' ).forEach( function ( select ) {
			var custom = select.parentNode.querySelector( '.aaat-custom-model' );

			select.addEventListener( 'change', function () {
				if ( custom ) {
					custom.hidden = 'custom' !== select.value;

					if ( ! custom.hidden ) {
						custom.querySelector( 'input' ).focus();
					}
				}
			} );
		} );

		if ( radios.length ) {
			show();
		}
	}

	function initDepends() {
		document.querySelectorAll( '[data-aaat-depends]' ).forEach( function ( target ) {
			var key = target.getAttribute( 'data-aaat-depends' );
			var toggle = document.querySelector( '[data-aaat-toggle="' + key + '"]' );

			if ( ! toggle ) {
				return;
			}

			function sync() {
				target.classList.toggle( 'is-disabled', ! toggle.checked );
			}

			toggle.addEventListener( 'change', sync );
			sync();
		} );
	}

	function initPrompt() {
		var reset = document.getElementById( 'aaat-reset-prompt' );
		var field = document.getElementById( 'aaat-instructions' );

		if ( ! reset || ! field ) {
			return;
		}

		reset.addEventListener( 'click', function () {
			if ( field.value.trim() === data.instructions.trim() || window.confirm( i18n.resetConfirm ) ) {
				field.value = data.instructions;
				field.focus();
			}
		} );
	}

	function initTest() {
		var form = document.querySelector( '.aaat-form' );
		var box = document.querySelector( '.aaat-test-result' );

		if ( ! form || ! box || '1' !== form.getAttribute( 'data-aaat-autotest' ) ) {
			return;
		}

		box.className = 'aaat-test-result';
		box.textContent = '';

		var loading = el( 'p', 'aaat-loading' );

		loading.appendChild( spinner() );
		loading.appendChild( el( 'span', '', i18n.testing ) );
		box.appendChild( loading );
		box.scrollIntoView( { block: 'center' } );

		post( 'automatic_ai_alt_text_test', {} ).then( function ( res ) {
			box.textContent = '';

			if ( ! res || ! res.success ) {
				box.className = 'aaat-test-result is-error';
				box.appendChild( el( 'p', 'aaat-test-head', i18n.testFailed ) );
				box.appendChild( el( 'p', '', res && res.data && res.data.message ? res.data.message : i18n.failed ) );
				refreshConnection( false );
				return;
			}

			var result = res.data;
			var body = el( 'div', 'aaat-test-body' );
			var img = document.createElement( 'img' );
			var text = el( 'div' );

			box.className = 'aaat-test-result is-ok';
			box.appendChild( el( 'p', 'aaat-test-head', i18n.testOk ) );

			img.src = result.image;
			img.alt = '';
			body.appendChild( img );

			text.appendChild( el( 'p', 'aaat-muted', result.name ) );

			if ( result.alt ) {
				text.appendChild( el( 'p', 'aaat-quote', '“' + result.alt + '”' ) );
			}

			if ( result.title ) {
				text.appendChild( el( 'p', '', i18n.title + ' ' + result.title ) );
			}

			text.appendChild( el( 'p', 'aaat-muted', i18n.cost + ' ' + result.costLabel ) );
			body.appendChild( text );
			box.appendChild( body );
			refreshConnection( true );
		}, function () {
			box.className = 'aaat-test-result is-error';
			box.textContent = i18n.failed;
		} );
	}

	// Show the new connection state without a reload.
	function refreshConnection( ok ) {
		var line = document.querySelector( '.aaat-connection' );
		var side = document.querySelector( '[data-aaat-status="connection"]' );

		if ( line ) {
			line.classList.remove( 'is-ok', 'is-warn', 'is-bad', 'is-none' );
			line.classList.add( ok ? 'is-ok' : 'is-bad' );
			line.querySelector( 'span' ).textContent = ok ? i18n.connected : i18n.notConnected;

			if ( ok ) {
				line.querySelector( 'svg' ).outerHTML = data.okIcon;
			}
		}

		if ( ok ) {
			document.querySelectorAll( '[data-aaat-status="connection"], [data-aaat-status="uploads"][data-aaat-on="1"]' ).forEach( function ( item ) {
				item.className = 'is-ok';
				item.querySelector( 'svg' ).outerHTML = data.okIconSmall;
			} );

			if ( side ) {
				side.querySelector( 'span' ).textContent = data.connectedLabel;
			}
		}
	}

	// Bulk Generate ----------------------------------------------------------------

	function initBulk() {
		var root = document.querySelector( '.aaat-bulk' );

		if ( ! root ) {
			return;
		}

		var running = null;
		var testButton = document.getElementById( 'aaat-run-test' );
		var approve = document.getElementById( 'aaat-approve' );
		var approveCheck = document.getElementById( 'aaat-approve-check' );
		var unlock = document.getElementById( 'aaat-unlock' );
		var start = document.getElementById( 'aaat-start' );
		var stop = document.getElementById( 'aaat-stop' );
		var costCheck = document.getElementById( 'aaat-cost-check' );
		var allCheck = document.getElementById( 'aaat-all-check' );
		var allConfirm = root.querySelector( '.aaat-all-confirm' );
		var projection = root.querySelector( '.aaat-projection' );
		var unlocked = '1' === root.getAttribute( 'data-unlocked' );

		function scope() {
			var chosen = root.querySelector( 'input[name="aaat_scope"]:checked' );

			return chosen ? chosen.value : 'missing';
		}

		function remaining() {
			return parseInt( root.getAttribute( 'all' === scope() ? 'data-total' : 'data-missing' ), 10 ) || 0;
		}

		function batch() {
			var chosen = root.querySelector( 'input[name="aaat_batch"]:checked' );

			if ( ! chosen || 'all' === chosen.value ) {
				return remaining();
			}

			return Math.min( parseInt( chosen.value, 10 ), remaining() );
		}

		function isAll() {
			var chosen = root.querySelector( 'input[name="aaat_batch"]:checked' );

			return chosen && 'all' === chosen.value;
		}

		function updateCosts() {
			var avg = data.avgCost;

			root.querySelectorAll( '.aaat-batch-cost' ).forEach( function ( label ) {
				var count = 'all' === label.getAttribute( 'data-count' ) ? remaining() : parseInt( label.getAttribute( 'data-count' ), 10 );

				label.textContent = 'all' === label.getAttribute( 'data-count' )
					? count.toLocaleString() + ( null !== avg && undefined !== avg ? ' · ≈' + money( avg * count ) : '' )
					: ( null !== avg && undefined !== avg ? '≈' + money( avg * count ) : '' );
			} );

			if ( projection ) {
				if ( null !== avg && undefined !== avg && batch() > 0 ) {
					projection.hidden = false;
					projection.textContent = format(
						i18n.projection,
						batch().toLocaleString(),
						money( avg ),
						money( avg * batch() )
					);
				} else {
					projection.hidden = true;
				}
			}

			if ( allConfirm ) {
				allConfirm.hidden = ! isAll();
			}

			syncStart();
		}

		function syncStart() {
			if ( ! start ) {
				return;
			}

			start.disabled = ! unlocked || null !== running || ! costCheck.checked || ( isAll() && ! allCheck.checked ) || batch() < 1;
		}

		root.querySelectorAll( 'input[name="aaat_scope"], input[name="aaat_batch"]' ).forEach( function ( input ) {
			input.addEventListener( 'change', updateCosts );
		} );

		[ costCheck, allCheck ].forEach( function ( box ) {
			if ( box ) {
				box.addEventListener( 'change', syncStart );
			}
		} );

		updateCosts();

		if ( testButton ) {
			testButton.addEventListener( 'click', function () {
				if ( running ) {
					return;
				}

				var panel = document.getElementById( 'aaat-test-run' );

				approve.hidden = true;
				approveCheck.checked = false;
				unlock.disabled = true;
				testButton.disabled = true;

				running = new Runner( {
					mode: 'test',
					scope: scope(),
					target: data.testSize,
					panel: panel,
					onDone: function ( summary ) {
						running = null;
						testButton.disabled = false;
						syncStart();
						refreshStats();

						if ( summary.ok > 0 ) {
							approve.hidden = false;
							summary.status( i18n.testDone, 'is-ok' );
						} else if ( ! summary.stoppedWith ) {
							summary.status( 0 === summary.processed ? i18n.noImages : i18n.testNone, 'is-error' );
						}
					},
				} );

				running.start();
			} );
		}

		if ( approveCheck ) {
			approveCheck.addEventListener( 'change', function () {
				unlock.disabled = ! approveCheck.checked;
			} );

			unlock.addEventListener( 'click', function () {
				unlock.disabled = true;

				post( 'automatic_ai_alt_text_bulk_unlock', {} ).then( function ( res ) {
					if ( res && res.success ) {
						window.location.reload();
						return;
					}

					unlock.disabled = false;
					window.alert( res && res.data && res.data.message ? res.data.message : i18n.failed );
				} );
			} );
		}

		if ( start ) {
			start.addEventListener( 'click', function () {
				if ( running || start.disabled ) {
					return;
				}

				if ( isAll() && ! window.confirm( i18n.confirmAll ) ) {
					return;
				}

				start.disabled = true;
				stop.hidden = false;
				testButton.disabled = true;
				costCheck.checked = false;

				running = new Runner( {
					mode: 'bulk',
					scope: scope(),
					target: batch(),
					panel: document.getElementById( 'aaat-batch-run' ),
					progress: true,
					onDone: function ( summary ) {
						running = null;
						stop.hidden = true;
						testButton.disabled = false;
						syncStart();
						refreshStats();

						if ( ! summary.stoppedWith ) {
							summary.status( 0 === summary.processed ? i18n.noImages : i18n.finished + ' ' + summary.line(), 0 === summary.processed ? 'is-error' : 'is-ok' );
						}
					},
				} );

				running.start();
			} );

			stop.addEventListener( 'click', function () {
				if ( running ) {
					running.stop( i18n.stopped, false );
				}
			} );
		}

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( running ) {
				event.preventDefault();
				event.returnValue = i18n.leave;
				return i18n.leave;
			}
		} );

		function refreshStats() {
			post( 'automatic_ai_alt_text_bulk_stats', {} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					return;
				}

				root.setAttribute( 'data-missing', res.data.missing );
				root.setAttribute( 'data-total', res.data.total );

				Object.keys( res.data ).forEach( function ( key ) {
					var node = root.querySelector( '[data-aaat-stat="' + key + '"]' );

					if ( node ) {
						node.textContent = Number( res.data[ key ] ).toLocaleString();
					}
				} );

				updateCosts();
			} );
		}
	}

	/**
	 * Works through a test or a batch: fetches image IDs page by page (newest
	 * first, below a cursor so nothing is done twice) and sends them to the
	 * server one per request, a few at a time.
	 *
	 * @param {Object} options mode, scope, target, panel, progress, onDone.
	 */
	function Runner( options ) {
		var queue = [];
		var cursor = 0;
		var exhausted = false;
		var fetching = null;
		var active = 0;
		var processed = 0;
		var ok = 0;
		var failed = 0;
		var spent = 0;
		var consecutive = 0;
		var stopped = false;
		var stoppedWith = '';
		var panel = options.panel;
		var list = panel.querySelector( '.aaat-results' );
		var statusLine = panel.querySelector( '.aaat-run-status' );
		var bar = panel.querySelector( '.aaat-progress' );
		var workers = 'test' === options.mode ? 2 : 3;

		panel.hidden = false;
		list.textContent = '';

		function status( text, tone, busy ) {
			statusLine.className = 'aaat-run-status' + ( tone ? ' ' + tone : '' );
			statusLine.textContent = '';

			if ( busy ) {
				statusLine.appendChild( spinner() );
			}

			statusLine.appendChild( el( 'span', '', text ) );
		}

		function line() {
			var text = format( i18n.progress, processed, options.target );

			if ( spent > 0 ) {
				text += ' · ' + format( i18n.spent, money( spent ) );
			}

			return text;
		}

		function progress() {
			if ( bar ) {
				var percent = options.target ? Math.round( processed / options.target * 100 ) : 0;

				bar.querySelector( 'span' ).style.width = percent + '%';
				bar.setAttribute( 'aria-valuenow', String( percent ) );
			}

			if ( ! stopped ) {
				status( line(), '', true );
			}
		}

		function fetchMore() {
			if ( fetching ) {
				return fetching;
			}

			var want = Math.min( 50, options.target - processed - active - queue.length );

			if ( want <= 0 || exhausted || stopped ) {
				return Promise.resolve();
			}

			fetching = post( 'automatic_ai_alt_text_bulk_next', { scope: options.scope, before: cursor, limit: want } ).then( function ( res ) {
				var ids = res && res.success && res.data && res.data.ids ? res.data.ids : [];

				fetching = null;

				if ( ! res || ! res.success ) {
					stop( res && res.data && res.data.message ? res.data.message : i18n.failed, true );
					return;
				}

				if ( ! ids.length ) {
					exhausted = true;
				}

				ids.forEach( function ( id ) {
					queue.push( id );
					cursor = 0 === cursor ? id : Math.min( cursor, id );
				} );
			}, function () {
				fetching = null;
				stop( i18n.failed, true );
			} );

			return fetching;
		}

		function worker() {
			if ( stopped || processed + active >= options.target ) {
				return Promise.resolve();
			}

			return ( queue.length ? Promise.resolve() : fetchMore() ).then( function () {
				if ( stopped || ! queue.length || processed + active >= options.target ) {
					return;
				}

				var id = queue.shift();

				active++;

				return processOne( id, 1 ).then( function () {
					active--;
					return worker();
				} );
			} );
		}

		function processOne( id, attempt ) {
			return post( 'automatic_ai_alt_text_bulk_process', { id: id, mode: options.mode, scope: options.scope, attempt: attempt } ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					stop( res && res.data && res.data.message ? res.data.message : i18n.failed, true );
					return;
				}

				var result = res.data;

				if ( 'error' === result.status && result.retry && attempt < 3 && ! stopped ) {
					var wait = Math.min( 60, result.retryAfter || 10 );

					status( format( i18n.waiting, wait ), '', true );

					return delay( wait * 1000 ).then( function () {
						return stopped ? null : processOne( id, attempt + 1 );
					} );
				}

				processed++;
				render( result );

				if ( 'error' === result.status ) {
					failed++;
					consecutive++;

					if ( result.fatal ) {
						stop( result.message, true );
					} else if ( consecutive >= 5 ) {
						stop( i18n.tooMany, true );
					}
				} else {
					consecutive = 0;

					if ( 'success' === result.status ) {
						ok++;
						spent += result.cost || 0;
					}
				}

				progress();
			}, function () {
				if ( attempt < 3 && ! stopped ) {
					return delay( 5000 ).then( function () {
						return processOne( id, attempt + 1 );
					} );
				}

				processed++;
				failed++;
				consecutive++;
				render( { id: id, status: 'error', message: i18n.failed } );

				if ( consecutive >= 5 ) {
					stop( i18n.tooMany, true );
				}
			} );
		}

		function render( result ) {
			var item = el( 'li', 'aaat-result is-' + result.status );
			var body = el( 'div', 'aaat-result-body' );
			var name = el( 'div', 'aaat-result-name' );

			if ( result.thumb ) {
				var img = document.createElement( 'img' );

				img.src = result.thumb;
				img.alt = '';
				img.loading = 'lazy';
				item.appendChild( img );
			} else {
				item.appendChild( el( 'span', 'aaat-result-ph' ) );
			}

			if ( result.edit ) {
				var link = el( 'a', '', result.name || '#' + result.id );

				link.href = result.edit;
				link.target = '_blank';
				link.rel = 'noopener';
				name.appendChild( link );
			} else {
				name.appendChild( el( 'span', '', result.name || '#' + result.id ) );
			}

			if ( 'success' === result.status && result.costLabel ) {
				name.appendChild( el( 'span', '', result.costLabel ) );
			}

			if ( result.undo ) {
				var undo = el( 'a', '', i18n.undo );

				undo.href = result.undo;
				name.appendChild( undo );
			}

			body.appendChild( name );

			if ( 'error' === result.status ) {
				body.appendChild( el( 'div', 'aaat-result-alt', result.message ) );
			} else if ( 'skipped' === result.status ) {
				body.appendChild( el( 'div', 'aaat-result-alt', i18n.skipped ) );
			} else {
				body.appendChild( el( 'div', 'aaat-result-alt', result.alt ) );

				if ( result.title && result.title !== result.old_title ) {
					body.appendChild( el( 'div', 'aaat-result-meta', i18n.title + ' ' + result.title ) );
				}

				if ( result.old_alt && result.old_alt !== result.alt ) {
					body.appendChild( el( 'div', 'aaat-result-meta', i18n.before + ' ' + result.old_alt ) );
				}
			}

			item.appendChild( body );
			list.insertBefore( item, list.firstChild );

			// Keep the page light on long batches.
			while ( list.children.length > 200 ) {
				list.removeChild( list.lastChild );
			}
		}

		function stop( message, isError ) {
			if ( stopped ) {
				return;
			}

			stopped = true;
			stoppedWith = message;
			queue = [];
			status( ( isError ? i18n.stoppedError + ' ' : '' ) + message + ( processed ? ' ' + line() : '' ), isError ? 'is-error' : '' );
		}

		this.stop = stop;

		this.start = function () {
			var all = [];

			status( line(), '', true );

			for ( var n = 0; n < workers; n++ ) {
				all.push( worker() );
			}

			Promise.all( all ).then( finish, finish );
		};

		function finish() {
			// Ran out of images before the batch size: that's complete too.
			if ( bar && ! stopped ) {
				bar.querySelector( 'span' ).style.width = '100%';
				bar.setAttribute( 'aria-valuenow', '100' );
			}

			options.onDone( {
				ok: ok,
				failed: failed,
				processed: processed,
				spent: spent,
				stoppedWith: stoppedWith,
				line: line,
				status: status,
			} );
		}
	}

	// History ---------------------------------------------------------------------

	function initHistory() {
		var root = document.querySelector( '.aaat-history' );

		if ( ! root ) {
			return;
		}

		root.addEventListener( 'click', function ( event ) {
			var open = event.target.closest( '.aaat-edit-open' );
			var cancel = event.target.closest( '.aaat-edit-cancel' );
			var save = event.target.closest( '.aaat-edit-save' );
			var again = event.target.closest( '.aaat-again' );

			if ( open ) {
				var form = root.querySelector( '.aaat-edit[data-log="' + open.getAttribute( 'data-log' ) + '"]' );

				if ( form ) {
					form.hidden = ! form.hidden;

					if ( ! form.hidden ) {
						form.querySelector( 'textarea' ).focus();
					}
				}
			}

			if ( cancel ) {
				cancel.closest( '.aaat-edit' ).hidden = true;
			}

			if ( save ) {
				var edit = save.closest( '.aaat-edit' );
				var note = edit.querySelector( '.aaat-edit-status' );

				save.disabled = true;
				note.textContent = i18n.saving;

				post( 'automatic_ai_alt_text_history_save', {
					log: edit.getAttribute( 'data-log' ),
					alt: edit.querySelector( 'textarea' ).value,
					title: edit.querySelector( 'input' ).value,
				} ).then( function ( res ) {
					if ( res && res.success ) {
						window.location.reload();
						return;
					}

					save.disabled = false;
					note.textContent = res && res.data && res.data.message ? res.data.message : i18n.failed;
				} );
			}

			if ( again ) {
				again.disabled = true;
				again.textContent = i18n.writing;

				post( 'automatic_ai_alt_text_generate', { id: again.getAttribute( 'data-id' ), nonce: data.mediaNonce } ).then( function ( res ) {
					if ( res && res.success ) {
						window.location.reload();
						return;
					}

					again.disabled = false;
					window.alert( res && res.data && res.data.message ? res.data.message : i18n.failed );
				} );
			}
		} );
	}
} )();
