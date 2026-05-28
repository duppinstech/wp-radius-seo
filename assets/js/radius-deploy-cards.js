/**
 * Deploy screen: help modal, template card submit state, chained AJAX deploy batches.
 */
( function () {
	'use strict';

	function initDeployHelpModal() {
		var overlay = document.getElementById( 'radius-deploy-help-dialog' );
		var openBtn = document.getElementById( 'radius-deploy-help-trigger' );
		if ( ! overlay || ! openBtn ) {
			return;
		}

		function isOpen() {
			return ! overlay.hasAttribute( 'hidden' );
		}

		function openModal() {
			overlay.removeAttribute( 'hidden' );
			overlay.setAttribute( 'aria-hidden', 'false' );
			openBtn.setAttribute( 'aria-expanded', 'true' );
			var ok = overlay.querySelector( '.radius-deploy-help-modal__ok' );
			if ( ok ) {
				ok.focus();
			}
		}

		function closeModal() {
			overlay.setAttribute( 'hidden', 'hidden' );
			overlay.setAttribute( 'aria-hidden', 'true' );
			openBtn.setAttribute( 'aria-expanded', 'false' );
			openBtn.focus();
		}

		openBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			openModal();
		} );

		overlay.querySelectorAll( '[data-radius-deploy-close]' ).forEach( function ( el ) {
			el.addEventListener( 'click', function () {
				closeModal();
			} );
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Escape' || ! isOpen() ) {
				return;
			}
			e.preventDefault();
			closeModal();
		} );
	}

	function initChainedDeploy() {
		if ( typeof radiusDeployBatch === 'undefined' ) {
			return;
		}
		var cfg = radiusDeployBatch;

		function setRunning( card, running ) {
			card.classList.toggle( 'radius-deploy-card--ajax-running', running );
			card.querySelectorAll( 'input[type="submit"], button[type="submit"]' ).forEach( function ( el ) {
				var f = el.form;
				if ( f && f.classList.contains( 'radius-deploy-card__form--cancel' ) ) {
					return;
				}
				el.disabled = running;
			} );
		}

		/**
		 * Drive progress bar + fraction from place-queue progress (matches status line: done of total places).
		 *
		 * @param {HTMLElement} card
		 * @param {number} doneCount Places processed so far in this run
		 * @param {number} total     Total places in this deploy run
		 */
		function updateDeployProgressFromPlaces( card, doneCount, total ) {
			var fill = card.querySelector( '.radius-deploy-progress__fill' );
			var bar = card.querySelector( '.radius-deploy-progress[role="progressbar"]' );
			var numStrong = card.querySelector( '.radius-deploy-card__row-deployed .radius-deploy-card__deploy-num strong' );
			var td = fill && fill.closest( 'td' );
			var pct = 0;
			if ( total > 0 ) {
				pct = Math.min( 100, Math.round( ( doneCount / total ) * 100 ) );
			}
			if ( fill ) {
				fill.style.width = String( pct ) + '%';
			}
			if ( bar ) {
				bar.removeAttribute( 'aria-hidden' );
				if ( total > 0 ) {
					bar.setAttribute( 'aria-valuemin', '0' );
					bar.setAttribute( 'aria-valuemax', String( total ) );
					bar.setAttribute( 'aria-valuenow', String( doneCount ) );
				} else {
					bar.setAttribute( 'aria-valuemin', '0' );
					bar.setAttribute( 'aria-valuemax', '100' );
					bar.setAttribute( 'aria-valuenow', String( pct ) );
				}
			}
			if ( numStrong && total > 0 ) {
				numStrong.textContent = String( doneCount ) + ' / ' + String( total );
			}
		}

		function restoreDeployProgressSnapshot( card ) {
			var snap = card.getAttribute( 'data-rd-progress-frac-snapshot' );
			if ( snap !== null && snap !== '' ) {
				var numStrong = card.querySelector( '.radius-deploy-card__row-deployed .radius-deploy-card__deploy-num strong' );
				if ( numStrong ) {
					numStrong.textContent = snap;
				}
			}
			var fill = card.querySelector( '.radius-deploy-progress__fill' );
			var w0 = card.getAttribute( 'data-rd-progress-fill-width' );
			if ( fill && w0 !== null ) {
				fill.style.width = w0;
			}
			var bar = card.querySelector( '.radius-deploy-progress[role="progressbar"]' );
			if ( bar ) {
				var hid = card.getAttribute( 'data-rd-bar-aria-hidden' );
				if ( hid === '1' ) {
					bar.setAttribute( 'aria-hidden', 'true' );
					bar.removeAttribute( 'aria-valuemin' );
					bar.removeAttribute( 'aria-valuemax' );
					bar.removeAttribute( 'aria-valuenow' );
				} else {
					bar.removeAttribute( 'aria-hidden' );
					var max0 = card.getAttribute( 'data-rd-progress-aria-max' );
					var now0 = card.getAttribute( 'data-rd-progress-aria-now' );
					var min0 = card.getAttribute( 'data-rd-progress-aria-min' );
					if ( min0 !== null && min0 !== '' ) {
						bar.setAttribute( 'aria-valuemin', min0 );
					} else {
						bar.removeAttribute( 'aria-valuemin' );
					}
					if ( max0 !== null && max0 !== '' ) {
						bar.setAttribute( 'aria-valuemax', max0 );
					} else {
						bar.removeAttribute( 'aria-valuemax' );
					}
					if ( now0 !== null && now0 !== '' ) {
						bar.setAttribute( 'aria-valuenow', now0 );
					} else {
						bar.removeAttribute( 'aria-valuenow' );
					}
				}
			}
		}

		function snapshotDeployProgress( card ) {
			var numStrong = card.querySelector( '.radius-deploy-card__row-deployed .radius-deploy-card__deploy-num strong' );
			if ( numStrong ) {
				card.setAttribute( 'data-rd-progress-frac-snapshot', numStrong.textContent );
			}
			var fill = card.querySelector( '.radius-deploy-progress__fill' );
			if ( fill ) {
				card.setAttribute( 'data-rd-progress-fill-width', fill.style.width || '' );
			}
			var bar = card.querySelector( '.radius-deploy-progress[role="progressbar"]' );
			if ( bar ) {
				card.setAttribute( 'data-rd-bar-aria-hidden', bar.hasAttribute( 'aria-hidden' ) ? '1' : '0' );
				card.setAttribute( 'data-rd-progress-aria-min', bar.getAttribute( 'aria-valuemin' ) || '' );
				card.setAttribute( 'data-rd-progress-aria-max', bar.getAttribute( 'aria-valuemax' ) || '' );
				card.setAttribute( 'data-rd-progress-aria-now', bar.getAttribute( 'aria-valuenow' ) || '' );
			}
		}

		function deployExtractErrorMessage( json ) {
			if ( ! json || typeof json !== 'object' || ! ( 'success' in json ) ) {
				return '';
			}
			if ( json.success ) {
				return '';
			}
			var d = json.data;
			if ( typeof d === 'string' && d !== '' ) {
				return d;
			}
			if ( d && typeof d === 'object' && typeof d.message === 'string' ) {
				return d.message;
			}
			return '';
		}

		function deployDescribeNonJson( bundle ) {
			var st = bundle.status;
			var tx = bundle.text || '';
			if ( ! tx ) {
				return (
					cfg.i18n.emptyResponse ||
					cfg.i18n.badResponse
				) + ' (HTTP ' + st + ')';
			}
			if ( st === 502 || st === 503 || st === 504 ) {
				return (
					cfg.i18n.gatewayTimeout ||
					cfg.i18n.badResponse
				) +
					' (HTTP ' +
					st +
					')';
			}
			if ( st >= 500 ) {
				return (
					cfg.i18n.serverError ||
					cfg.i18n.badResponse
				) +
					' (HTTP ' +
					st +
					')';
			}
			if ( tx.charAt( 0 ) === '<' ) {
				return cfg.i18n.htmlInsteadOfJson || cfg.i18n.badResponse;
			}
			return ( cfg.i18n.responseNotJson || cfg.i18n.badResponse ) + ' (HTTP ' + st + ')';
		}

		function runBatch( templateId, continuing, card, statusEl, formEl ) {
			var fd = new FormData();
			fd.append( 'action', 'radius_deploy_batch' );
			fd.append( 'nonce', cfg.nonce );
			fd.append( 'radius_template_id', String( templateId ) );
			var targetInput = formEl.querySelector( 'input[name="radius_deploy_target"]' );
			fd.append( 'radius_deploy_target', targetInput && targetInput.value ? targetInput.value : 'radius_landing' );
			if ( continuing ) {
				fd.append( 'radius_deploy_continue', '1' );
			}

			function failUi() {
				card.classList.remove( 'is-submitting' );
				setRunning( card, false );
				restoreDeployProgressSnapshot( card );
				if ( statusEl ) {
					statusEl.setAttribute( 'hidden', 'hidden' );
					statusEl.textContent = '';
				}
			}

			fetch( cfg.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.text().then( function ( text ) {
						return { status: r.status, text: text };
					} );
				} )
				.then( function ( bundle ) {
					var text = bundle.text;
					var json = null;
					if ( text ) {
						try {
							json = JSON.parse( text );
						} catch ( e ) {
							json = null;
						}
					}
					if (
						! json ||
						typeof json !== 'object' ||
						! ( 'success' in json )
					) {
						window.alert(
							cfg.i18n.errorPrefix +
								' ' +
								deployDescribeNonJson( bundle )
						);
						failUi();
						return;
					}
					if ( ! json.success ) {
						var msg =
							deployExtractErrorMessage( json ) || cfg.i18n.badResponse;
						window.alert( cfg.i18n.errorPrefix + ' ' + msg );
						failUi();
						return;
					}
					var p = json.data;
					var total = parseInt( p.initial_total, 10 ) || 0;
					var rem = parseInt( p.remaining, 10 ) || 0;
					var doneCount = total > 0 ? total - rem : 0;
					var batch = p.stats_batch || {};
					var c = batch.created != null ? batch.created : 0;
					var u = batch.updated != null ? batch.updated : 0;
					var s = batch.skipped != null ? batch.skipped : 0;
					updateDeployProgressFromPlaces( card, doneCount, total );
					if ( statusEl ) {
						statusEl.removeAttribute( 'hidden' );
						var tpl = cfg.i18n.progressTpl || '';
						statusEl.textContent = tpl
							.replace( /\{done\}/g, String( doneCount ) )
							.replace( /\{total\}/g, String( total ) )
							.replace( /\{c\}/g, String( c ) )
							.replace( /\{u\}/g, String( u ) )
							.replace( /\{s\}/g, String( s ) );
					}
					if ( p.done ) {
						var base = cfg.deployPageUrl;
						var join = base.indexOf( '?' ) >= 0 ? '&' : '?';
						window.location.href = base + join + 'radius_notice=' + encodeURIComponent( p.done_message || '' );
						return;
					}
					var delay = parseInt( cfg.interBatchDelayMs, 10 ) || 0;
					window.setTimeout( function () {
						runBatch( templateId, true, card, statusEl, formEl );
					}, delay );
				} )
				.catch( function () {
					window.alert(
						cfg.i18n.errorPrefix +
							' ' +
							( cfg.i18n.networkError || cfg.i18n.badResponse )
					);
					failUi();
				} );
		}

		document.querySelectorAll( 'form[data-radius-chained-deploy="1"]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				var trigger = form.querySelector( 'input[type="submit"], button[type="submit"]' );
				if ( trigger && trigger.disabled ) {
					return;
				}
				e.preventDefault();

				var tidInput = form.querySelector( 'input[name="radius_template_id"], select[name="radius_template_id"]' );
				var templateId = tidInput ? tidInput.value : '';
				if ( ! templateId ) {
					return;
				}

				var card = form.closest( '.radius-deploy-card' );
				if ( ! card ) {
					return;
				}

				var statusEl = card.querySelector( '.radius-deploy-card__ajax-progress' );
				if ( statusEl ) {
					statusEl.removeAttribute( 'hidden' );
					statusEl.textContent = cfg.i18n.deploying;
				}

				var continuing = !! form.querySelector( 'input[name="radius_deploy_continue"]' );
				snapshotDeployProgress( card );
				card.classList.add( 'is-submitting' );
				setRunning( card, true );
				if ( ! continuing ) {
					updateDeployProgressFromPlaces( card, 0, 0 );
				}
				runBatch( templateId, continuing, card, statusEl, form );
			} );
		} );
	}

	function initMigrationRerunButton() {
		var cfg    = typeof window.radiusDeployMigration !== 'undefined' ? window.radiusDeployMigration : null;
		var openBtn = document.getElementById( 'radius-migration-rerun-trigger' );
		if ( ! cfg || ! openBtn ) {
			return;
		}

		openBtn.addEventListener( 'click', function () {
			var originalHTML = openBtn.innerHTML;
			openBtn.disabled = true;
			openBtn.textContent = cfg.i18n.running || 'Opening wizard\u2026';

			var fd = new FormData();
			fd.append( 'action', cfg.wizardAction || 'radius_migration_wizard' );
			fd.append( 'nonce', cfg.nonce || '' );
			fd.append( 'wizard_action', 'rerun' );

			fetch( cfg.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( json ) {
					openBtn.disabled = false;
					openBtn.innerHTML = originalHTML;
					if ( ! json || ! json.success ) {
						var msg =
							json && json.data && json.data.message
								? json.data.message
								: ( cfg.i18n.errorPrefix || 'Error:' ) + ' Could not reset migration state.';
						// eslint-disable-next-line no-alert
						window.alert( msg );
						return;
					}
					// Use the global exposed by radius-admin-migration-wizard.js when it is
					// loaded on this page; fall back to a redirect when it is not.
					var fallback = ( json.data && json.data.redirect ) ? json.data.redirect : null;
					if ( typeof window.radiusMigrationWizardOpen === 'function' && window.radiusMigrationWizardOpen( fallback ) !== false ) {
						return;
					}
					if ( fallback ) {
						window.location.href = fallback;
					}
				} )
				.catch( function () {
					openBtn.disabled = false;
					openBtn.innerHTML = originalHTML;
					// eslint-disable-next-line no-alert
					window.alert( ( cfg.i18n.errorPrefix || 'Error:' ) + ' Network error.' );
				} );
		} );
	}

	function initDedupeLandingsButton() {
		var btn = document.getElementById( 'radius-dedupe-landings-start' );
		if ( ! btn ) {
			return;
		}
		var statusEl = document.getElementById( 'radius-dedupe-landings-status' );
		var ajaxurl  = ( window.radiusDeployMigration && window.radiusDeployMigration.ajaxurl )
			? window.radiusDeployMigration.ajaxurl
			: ( window.ajaxurl || '/wp-admin/admin-ajax.php' );

		btn.addEventListener( 'click', function () {
			var nonce = btn.getAttribute( 'data-nonce' ) || '';
			if ( ! nonce ) {
				return;
			}
			btn.disabled = true;
			if ( statusEl ) {
				statusEl.textContent = 'Scanning for duplicates…';
			}
			var body = new URLSearchParams();
			body.set( 'action', 'radius_dedupe_landings' );
			body.set( 'nonce', nonce );

			fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					btn.disabled = false;
					if ( ! res.success ) {
						if ( statusEl ) {
							statusEl.textContent = 'Error: ' + ( ( res.data && res.data.message ) ? res.data.message : 'Unknown error.' );
						}
						return;
					}
					var d = res.data;
					if ( statusEl ) {
						if ( d.trashed > 0 ) {
							statusEl.textContent = 'Done. Trashed ' + d.trashed + ' duplicate page(s) out of ' + d.scanned + ' scanned. Reload to refresh counts.';
						} else {
							statusEl.textContent = 'No duplicates found (' + d.scanned + ' page(s) scanned).';
						}
					}
				} )
				.catch( function () {
					btn.disabled = false;
					if ( statusEl ) {
						statusEl.textContent = 'Network error. Please try again.';
					}
				} );
		} );
	}

	function initReconnectForms() {
		var cfg = typeof window.radiusDeployReconnect !== 'undefined' ? window.radiusDeployReconnect : null;
		if ( ! cfg || ! cfg.ajaxurl ) {
			return;
		}

		var interDelay = parseInt( cfg.interBatchDelayMs, 10 ) || 0;

		function statusElFor( postType ) {
			return document.getElementById( 'radius-reconnect-status-' + postType );
		}

		function setStatus( postType, text, show ) {
			var el = statusElFor( postType );
			if ( ! el ) {
				return;
			}
			if ( show ) {
				el.removeAttribute( 'hidden' );
				el.textContent = text;
			} else {
				el.setAttribute( 'hidden', 'hidden' );
				el.textContent = '';
			}
		}

		function progressText( done, total ) {
			var tpl = cfg.i18n.progressTpl || 'Processed {done} of {total}…';
			return tpl.replace( '{done}', String( done ) ).replace( '{total}', String( total ) );
		}

		function setPanelBusy( panel, busy ) {
			if ( ! panel ) {
				return;
			}
			panel.querySelectorAll( 'input, select, button' ).forEach( function ( node ) {
				node.disabled = !! busy;
			} );
		}

		function delayPromise( ms ) {
			return new Promise( function ( resolve ) {
				window.setTimeout( resolve, ms );
			} );
		}

		function postReconnect( params ) {
			var body = new URLSearchParams();
			body.set( 'action', 'radius_deploy_reconnect' );
			body.set( 'nonce', cfg.nonce || '' );
			body.set( 'post_type', params.postType );
			body.set( 'page', String( params.page ) );
			body.set( 'from', String( params.from ) );
			if ( params.discard ) {
				body.set( 'discard', '1' );
			} else {
				body.set( 'to', String( params.to ) );
			}
			return fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
				return r.json();
			} );
		}

		function runBatchedOp( params ) {
			var totals = params.totals;
			var page = params.page || 1;

			return postReconnect( params )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						var msg =
							json && json.data && json.data.message
								? json.data.message
								: cfg.i18n.errorPrefix + ' ' + ( cfg.i18n.networkError || 'Request failed.' );
						throw new Error( msg );
					}
					var d = json.data || {};
					if ( ! totals.total && d.total ) {
						totals.total = d.total;
					}
					if ( params.discard ) {
						totals.done += d.trashed || 0;
						totals.skipped += d.skipped || 0;
					} else {
						totals.done += ( d.relinked || 0 ) + ( d.skipped || 0 );
						totals.relinked += d.relinked || 0;
						totals.duplicates_trashed += d.duplicates_trashed || 0;
						totals.skipped += d.skipped || 0;
					}
					var total = totals.total || totals.done;
					setStatus( params.postType, progressText( totals.done, total ), true );
					if ( d.done ) {
						return totals;
					}
					var next = Object.assign( {}, params, { page: page + 1, totals: totals } );
					var chain = Promise.resolve( totals );
					if ( interDelay > 0 ) {
						chain = delayPromise( interDelay );
					}
					return chain.then( function () {
						return runBatchedOp( next );
					} );
				} );
		}

		function finishRedirect( postType, message ) {
			var tab = postType === 'radius_service_area' ? 'service-areas' : 'landings';
			var base = cfg.deployPageUrl || window.location.pathname + window.location.search;
			var join = base.indexOf( '?' ) >= 0 ? '&' : '?';
			window.location.href =
				base + join + 'tab=' + encodeURIComponent( tab ) + '&radius_notice=' + encodeURIComponent( message );
		}

		function runReconnect( from, to, postType ) {
			setStatus( postType, cfg.i18n.reconnecting || cfg.i18n.working || 'Working…', true );
			return runBatchedOp( {
				from: from,
				to: to,
				postType: postType,
				page: 1,
				discard: false,
				totals: { done: 0, total: 0, relinked: 0, duplicates_trashed: 0, skipped: 0 },
			} );
		}

		function runDiscard( from, postType ) {
			setStatus( postType, cfg.i18n.deleting || cfg.i18n.working || 'Working…', true );
			return runBatchedOp( {
				from: from,
				to: 0,
				postType: postType,
				page: 1,
				discard: true,
				totals: { done: 0, total: 0, trashed: 0, skipped: 0 },
			} );
		}

		document.querySelectorAll( 'form[data-radius-reconnect-form="1"]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var panel = form.closest( '[data-radius-reconnect-post-type]' );
				var postType = panel ? panel.getAttribute( 'data-radius-reconnect-post-type' ) : 'radius_landing';
				var fromInput = form.querySelector( 'input[name="radius_reconnect_from"]' );
				var toSelect = form.querySelector( 'select[name="radius_reconnect_to"]' );
				var from = fromInput ? parseInt( fromInput.value, 10 ) : 0;
				var to = toSelect ? parseInt( toSelect.value, 10 ) : 0;
				if ( ! to ) {
					window.alert( cfg.i18n.pickTemplate || 'Choose a target template.' );
					return;
				}
				setPanelBusy( panel, true );
				runReconnect( from, to, postType )
					.then( function ( totals ) {
						var msg = ( cfg.i18n.doneTpl || 'Done.' )
							.replace( '{n}', String( totals.relinked ) )
							.replace( '{t}', String( totals.duplicates_trashed ) );
						finishRedirect( postType, msg );
					} )
					.catch( function ( err ) {
						setPanelBusy( panel, false );
						setStatus( postType, '', false );
						window.alert( err && err.message ? err.message : cfg.i18n.networkError );
					} );
			} );
		} );

		document.querySelectorAll( '.radius-deploy-reconnect__discard' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var panel = btn.closest( '[data-radius-reconnect-post-type]' );
				var postType = panel ? panel.getAttribute( 'data-radius-reconnect-post-type' ) : 'radius_landing';
				var from = parseInt( btn.getAttribute( 'data-from' ) || '0', 10 );
				var count = parseInt( btn.getAttribute( 'data-count' ) || '0', 10 );
				var label = btn.getAttribute( 'data-label' ) || '';
				var confirmTpl = cfg.i18n.discardConfirm || 'Delete {n} pages?';
				if ( ! window.confirm( confirmTpl.replace( '{n}', String( count ) ).replace( '{label}', label ) ) ) {
					return;
				}
				setPanelBusy( panel, true );
				runDiscard( from, postType )
					.then( function ( totals ) {
						var n = totals.done || totals.trashed || 0;
						var msg = ( cfg.i18n.discardDoneTpl || 'Done.' ).replace( '{n}', String( n ) );
						finishRedirect( postType, msg );
					} )
					.catch( function ( err ) {
						setPanelBusy( panel, false );
						setStatus( postType, '', false );
						window.alert( err && err.message ? err.message : cfg.i18n.networkError );
					} );
			} );
		} );

		document.querySelectorAll( 'form[data-radius-reconnect-bulk="1"]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var panel = form.closest( '[data-radius-reconnect-post-type]' );
				var postType = panel ? panel.getAttribute( 'data-radius-reconnect-post-type' ) : 'radius_landing';
				setPanelBusy( panel, true );
				setStatus( postType, cfg.i18n.reconnecting || cfg.i18n.working || 'Working…', true );

				var body = new URLSearchParams();
				body.set( 'action', 'radius_deploy_reconnect' );
				body.set( 'nonce', cfg.nonce || '' );
				body.set( 'post_type', postType );
				body.set( 'plan_suggested', '1' );

				fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( json ) {
						if ( ! json || ! json.success ) {
							throw new Error(
								json && json.data && json.data.message
									? json.data.message
									: cfg.i18n.errorPrefix + ' ' + ( cfg.i18n.networkError || '' )
							);
						}
						var pairs = ( json.data && json.data.pairs ) || [];
						if ( ! pairs.length ) {
							throw new Error( cfg.i18n.noSuggested || 'No suggested matches.' );
						}
						var chain = Promise.resolve( {
							relinked: 0,
							duplicates_trashed: 0,
							skipped: 0,
							groups: 0,
						} );
						pairs.forEach( function ( pair ) {
							chain = chain.then( function ( acc ) {
								return runReconnect( pair.from, pair.to, postType ).then( function ( totals ) {
									acc.relinked += totals.relinked;
									acc.duplicates_trashed += totals.duplicates_trashed;
									acc.skipped += totals.skipped;
									acc.groups += 1;
									return acc;
								} );
							} );
						} );
						return chain;
					} )
					.then( function ( acc ) {
						var msg = ( cfg.i18n.bulkDoneTpl || 'Done.' )
							.replace( '{n}', String( acc.relinked ) )
							.replace( '{g}', String( acc.groups ) );
						finishRedirect( postType, msg );
					} )
					.catch( function ( err ) {
						setPanelBusy( panel, false );
						setStatus( postType, '', false );
						window.alert( err && err.message ? err.message : cfg.i18n.networkError );
					} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initDeployHelpModal();
		initMigrationRerunButton();
		initDedupeLandingsButton();
		initChainedDeploy();
		initReconnectForms();

		document.querySelectorAll( '.radius-deploy-card .radius-deploy-card__form' ).forEach( function ( form ) {
			if ( form.getAttribute( 'data-radius-chained-deploy' ) === '1' ) {
				return;
			}
			if ( form.classList.contains( 'radius-deploy-card__form--cancel' ) ) {
				return;
			}
			form.addEventListener( 'submit', function () {
				var card = form.closest( '.radius-deploy-card' );
				if ( card ) {
					card.classList.add( 'is-submitting' );
				}
			} );
		} );
	} );
} )();
