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

	document.addEventListener( 'DOMContentLoaded', function () {
		initDeployHelpModal();
		initChainedDeploy();

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
