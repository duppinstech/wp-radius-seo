/**
 * Plugins screen: ask before deactivating Radius SEO.
 */
( function () {
	'use strict';

	if ( typeof radiusDeactivate === 'undefined' ) {
		return;
	}

	var cfg = radiusDeactivate;
	var i18n = cfg.i18n || {};
	var deactivateUrl = '';
	var dialog = null;
	var lastFocus = null;
	var mode = 'choose';
	var purging = false;
	var rows = [];
	var hasData = false;
	var countsFailed = false;

	function text( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	function pluginMatches( href ) {
		if ( ! href || href.indexOf( 'action=deactivate' ) === -1 ) {
			return false;
		}
		var file = cfg.pluginFile || '';
		if ( ! file ) {
			return false;
		}
		return href.indexOf( file ) !== -1 || href.indexOf( encodeURIComponent( file ) ) !== -1;
	}

	function focusable( root ) {
		return Array.prototype.filter.call(
			root.querySelectorAll( 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled])' ),
			function ( node ) {
				if ( node.getAttribute( 'tabindex' ) === '-1' ) {
					return false;
				}
				return node.getClientRects().length > 0;
			}
		);
	}

	function el( tag, className, content ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( content ) {
			node.textContent = content;
		}
		return node;
	}

	function button( label, className, action, handler ) {
		var node = el( 'button', className, label );
		node.type = 'button';
		node.setAttribute( 'data-radius-deactivate-action', action );
		node.addEventListener( 'click', handler );
		return node;
	}

	function renderList( parent ) {
		if ( countsFailed ) {
			parent.appendChild( el( 'p', 'radius-deactivate__note', text( 'countsFailed', 'The counts could not be loaded. Reload Plugins and open Deactivate again, or choose Keep all data and deactivate. Delete all Radius data stays unavailable until the counts load.' ) ) );
			return;
		}
		if ( ! rows.length ) {
			parent.appendChild( el( 'p', 'radius-deactivate__note', text( 'empty', 'No Radius pages, places, or templates are stored on this site.' ) ) );
			return;
		}
		parent.appendChild( el( 'p', 'radius-deactivate__lead', text( 'wouldRemove', 'Deleting Radius data would remove:' ) ) );
		var list = el( 'ul', 'radius-deactivate__counts' );
		rows.forEach( function ( row ) {
			list.appendChild( el( 'li', '', row.text || '' ) );
		} );
		parent.appendChild( list );
	}

	function render() {
		var panel = dialog.querySelector( '.radius-deactivate__panel' );
		while ( panel.firstChild ) {
			panel.removeChild( panel.firstChild );
		}

		var title = el( 'h2', 'radius-deactivate__title', mode === 'confirm' ? text( 'confirmTitle', 'Delete all Radius data?' ) : text( 'title', 'Deactivate Radius SEO?' ) );
		title.id = 'radius-deactivate-title';
		panel.appendChild( title );

		if ( ! purging ) {
			var close = button( text( 'close', 'Close' ), 'radius-deactivate__close', 'close', dismiss );
			close.setAttribute( 'aria-label', text( 'close', 'Close' ) );
			panel.appendChild( close );
		}

		if ( mode === 'working' ) {
			var status = el( 'p', 'radius-deactivate__status', text( 'working', 'Deleting Radius data. Keep this window open.' ) );
			status.id = 'radius-deactivate-status';
			status.setAttribute( 'role', 'status' );
			status.setAttribute( 'aria-live', 'polite' );
			panel.appendChild( status );
			return;
		}

		if ( mode === 'confirm' ) {
			panel.appendChild( el( 'p', '', text( 'confirmBody', 'This cannot be undone. Radius SEO deactivates after the delete finishes.' ) ) );
			renderList( panel );
			var confirmActions = el( 'div', 'radius-deactivate__actions' );
			confirmActions.appendChild( button( text( 'confirm', 'Confirm delete' ), 'button radius-deactivate__danger', 'confirm', startPurge ) );
			confirmActions.appendChild( button( text( 'back', 'Back' ), 'button', 'back', function () {
				mode = 'choose';
				render();
				focusFirst();
			} ) );
			panel.appendChild( confirmActions );
			return;
		}

		panel.appendChild( el( 'p', '', text( 'intro', 'Closing this dialog leaves the plugin active and keeps every Radius page, place, and setting.' ) ) );
		renderList( panel );
		var actions = el( 'div', 'radius-deactivate__actions' );
		actions.appendChild( button( text( 'keep', 'Keep all data and deactivate' ), 'button button-primary', 'keep', keepAndDeactivate ) );
		if ( cfg.canPurge && hasData && ! countsFailed ) {
			actions.appendChild( button( text( 'delete', 'Delete all Radius data' ), 'button', 'delete', function () {
				mode = 'confirm';
				render();
				focusFirst();
			} ) );
		}
		actions.appendChild( button( text( 'cancel', 'Cancel' ), 'button', 'cancel', dismiss ) );
		panel.appendChild( actions );
	}

	function focusFirst() {
		var primary = dialog.querySelector( '.button-primary' );
		if ( primary ) {
			primary.focus();
			return;
		}
		var panel = dialog.querySelector( '.radius-deactivate__panel' );
		if ( panel ) {
			panel.setAttribute( 'tabindex', '-1' );
			panel.focus();
		}
	}

	function open( url ) {
		deactivateUrl = url;
		lastFocus = document.activeElement;
		mode = 'choose';
		purging = false;
		dialog.removeAttribute( 'hidden' );
		dialog.setAttribute( 'aria-hidden', 'false' );
		render();
		focusFirst();
		loadSummary();
	}

	function dismiss() {
		if ( purging ) {
			return;
		}
		dialog.setAttribute( 'hidden', 'hidden' );
		dialog.setAttribute( 'aria-hidden', 'true' );
		deactivateUrl = '';
		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}
	}

	function keepAndDeactivate() {
		if ( ! deactivateUrl || purging ) {
			return;
		}
		window.location.href = deactivateUrl;
	}

	function setWorking( message ) {
		var status = document.getElementById( 'radius-deactivate-status' );
		if ( status && message ) {
			status.textContent = message;
		}
	}

	function post( action ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce || '' );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				return { ok: response.ok, payload: payload };
			} );
		} );
	}

	function loadSummary() {
		if ( ! cfg.canPurge ) {
			rows = [];
			hasData = false;
			render();
			focusFirst();
			return;
		}
		post( 'radius_deactivation_summary' ).then( function ( result ) {
			if ( ! result.ok || ! result.payload || ! result.payload.success ) {
				countsFailed = true;
				hasData = false;
			} else {
				countsFailed = false;
				rows = result.payload.data.rows || [];
				hasData = !! result.payload.data.hasData;
			}
			if ( ! purging && mode === 'choose' ) {
				renderKeepingFocus();
			}
		} ).catch( function () {
			countsFailed = true;
			hasData = false;
			if ( ! purging && mode === 'choose' ) {
				renderKeepingFocus();
			}
		} );
	}

	function renderKeepingFocus() {
		var action = '';
		if ( document.activeElement && dialog.contains( document.activeElement ) ) {
			action = document.activeElement.getAttribute( 'data-radius-deactivate-action' ) || '';
		}
		render();
		if ( ! action ) {
			return;
		}
		var again = dialog.querySelector( '[data-radius-deactivate-action="' + action + '"]' );
		if ( again ) {
			again.focus();
		}
	}

	function showFailure() {
		purging = false;
		mode = 'choose';
		render();
		var note = el( 'p', 'radius-deactivate__note', text( 'failed', 'The delete stopped. Radius SEO is still active. Choose Delete all Radius data again, or Keep all data and deactivate. Pages and places already removed stay removed.' ) );
		note.setAttribute( 'role', 'alert' );
		dialog.querySelector( '.radius-deactivate__panel' ).appendChild( note );
		focusFirst();
	}

	function startPurge() {
		if ( purging ) {
			return;
		}
		purging = true;
		mode = 'working';
		render();
		runBatch();
	}

	function runBatch() {
		post( 'radius_deactivation_purge' ).then( function ( result ) {
			if ( ! result.ok || ! result.payload || ! result.payload.success ) {
				showFailure();
				return;
			}
			if ( result.payload.data && result.payload.data.done ) {
				setWorking( text( 'deactivating', 'Radius data deleted. Deactivating…' ) );
				window.location.href = deactivateUrl;
				return;
			}
			var left = result.payload.data && typeof result.payload.data.remaining === 'number' ? result.payload.data.remaining : 0;
			var template = text( 'remaining', '%s items left.' );
			setWorking( text( 'working', 'Deleting Radius data. Keep this window open.' ) + ' ' + template.replace( '%s', String( left ) ) );
			runBatch();
		} ).catch( function () {
			showFailure();
		} );
	}

	function buildDialog() {
		dialog = el( 'div', 'radius-deactivate' );
		dialog.id = 'radius-deactivate-dialog';
		dialog.setAttribute( 'hidden', 'hidden' );
		dialog.setAttribute( 'aria-hidden', 'true' );
		var backdrop = el( 'div', 'radius-deactivate__backdrop' );
		backdrop.addEventListener( 'click', dismiss );
		var panel = el( 'div', 'radius-deactivate__panel' );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-modal', 'true' );
		panel.setAttribute( 'aria-labelledby', 'radius-deactivate-title' );
		dialog.appendChild( backdrop );
		dialog.appendChild( panel );
		document.body.appendChild( dialog );
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest ? event.target.closest( 'a' ) : null;
		if ( ! link || ! pluginMatches( link.getAttribute( 'href' ) || '' ) ) {
			return;
		}
		event.preventDefault();
		open( link.href );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( ! dialog || dialog.hasAttribute( 'hidden' ) ) {
			return;
		}
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			dismiss();
			return;
		}
		if ( event.key !== 'Tab' ) {
			return;
		}
		var nodes = focusable( dialog );
		if ( ! nodes.length ) {
			event.preventDefault();
			return;
		}
		var first = nodes[ 0 ];
		var last = nodes[ nodes.length - 1 ];
		var active = document.activeElement;
		if ( ! dialog.contains( active ) ) {
			event.preventDefault();
			( event.shiftKey ? last : first ).focus();
			return;
		}
		if ( event.shiftKey && active === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && active === last ) {
			event.preventDefault();
			first.focus();
		}
	} );

	buildDialog();
}() );
