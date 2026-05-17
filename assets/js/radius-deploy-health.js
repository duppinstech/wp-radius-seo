/**
 * Deploy → Health check tab: run validation via AJAX and render results.
 */
(function () {
	'use strict';

	var cfg = typeof window.radiusDeployHealth === 'object' ? window.radiusDeployHealth : {};

	function esc(s) {
		if (s == null) {
			return '';
		}
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function statusClass(st) {
		if (st === 'pass') {
			return 'radius-deploy-health-item--pass';
		}
		if (st === 'warn') {
			return 'radius-deploy-health-item--warn';
		}
		if (st === 'fail') {
			return 'radius-deploy-health-item--fail';
		}
		return 'radius-deploy-health-item--skip';
	}

	function statusLabel(st) {
		var i18n = cfg.i18n || {};
		if (st === 'pass') {
			return i18n.pass || 'Pass';
		}
		if (st === 'warn') {
			return i18n.warn || 'Warning';
		}
		if (st === 'fail') {
			return i18n.fail || 'Fail';
		}
		return i18n.skip || 'Skipped';
	}

	function renderReport(data) {
		var summaryEl = document.getElementById('radius-deploy-health-summary');
		var resultsEl = document.getElementById('radius-deploy-health-results');
		if (!summaryEl || !resultsEl) {
			return;
		}
		var sum = data && data.summary ? data.summary : {};
		var st = sum.status || 'pass';
		var i18n = cfg.i18n || {};
		var overall =
			st === 'fail'
				? i18n.overallFail || 'Some checks failed.'
				: st === 'warn'
					? i18n.overallWarn || 'Warnings found.'
					: i18n.overallPass || 'All checks passed.';
		var sumClass =
			st === 'fail'
				? 'radius-deploy-summary--bad'
				: st === 'warn'
					? 'radius-deploy-summary--warn'
					: 'radius-deploy-summary--ok';
		summaryEl.className = 'radius-deploy-health-summary radius-deploy-summary ' + sumClass;
		summaryEl.innerHTML =
			'<p><strong>' +
			esc(overall) +
			'</strong> ' +
			esc(
				(sum.pass || 0) +
					' pass, ' +
					(sum.warn || 0) +
					' warn, ' +
					(sum.fail || 0) +
					' fail'
			);
		if (data && data.scope && typeof data.scope.expected_places === 'number') {
			summaryEl.innerHTML +=
				'<br><span class="description">' +
				esc(
					(i18n.scopeFmt || 'Deploy scope: %d places.').replace(
						'%d',
						String(data.scope.expected_places)
					)
				) +
				'</span>';
		}
		summaryEl.hidden = false;

		var checks = data && Array.isArray(data.checks) ? data.checks : [];
		var html = '<ul class="radius-deploy-health-list">';
		checks.forEach(function (c) {
			var stc = c.status || 'skip';
			html += '<li class="radius-deploy-health-item ' + esc(statusClass(stc)) + '">';
			html +=
				'<div class="radius-deploy-health-item__head"><strong>' +
				esc(c.label || c.id || '') +
				'</strong> <span class="radius-badge">' +
				esc(statusLabel(stc)) +
				'</span></div>';
			html += '<p class="radius-deploy-health-item__summary">' + esc(c.summary || '') + '</p>';
			if (c.detail) {
				html += '<p class="description">' + esc(c.detail) + '</p>';
			}
			if (c.missing_slugs && c.missing_slugs.length) {
				html +=
					'<p class="description"><strong>' +
					esc(i18n.missingSlugs || 'Sample missing place slugs') +
					':</strong> ' +
					esc(c.missing_slugs.join(', ')) +
					(c.missing_count > c.missing_slugs.length
						? ' …'
						: '') +
					'</p>';
			}
			if (c.extra_slugs && c.extra_slugs.length) {
				html +=
					'<p class="description"><strong>' +
					esc(i18n.extraSlugs || 'Sample out-of-scope place slugs') +
					':</strong> ' +
					esc(c.extra_slugs.join(', ')) +
					(c.extra_count > c.extra_slugs.length ? ' …' : '') +
					'</p>';
			}
			if (
				c.remediation &&
				c.remediation.action === 'trash_extra_service_areas' &&
				c.remediation.count > 0
			) {
				html +=
					'<p class="radius-deploy-health-remediate">' +
					'<button type="button" class="button button-secondary radius-deploy-health-trash-extra" ' +
					'data-remediate-action="trash_extra_service_areas" data-check-id="' +
					esc(c.id || 'service_area_coverage') +
					'">' +
					esc(i18n.trashExtraHubs || 'Trash out-of-scope hubs') +
					' (' +
					esc(String(c.remediation.count)) +
					')</button></p>';
			}
			if (c.fix_url) {
				html +=
					'<p><a class="button button-small" href="' +
					esc(c.fix_url) +
					'">' +
					esc(i18n.fix || 'Deploy missing hubs') +
					'</a></p>';
			}
			html += '</li>';
		});
		html += '</ul>';
		resultsEl.innerHTML = html;
	}

	function setRunning(on) {
		var btn = document.getElementById('radius-deploy-health-run');
		var sp = document.getElementById('radius-deploy-health-spinner');
		if (btn) {
			btn.disabled = !!on;
		}
		if (sp) {
			sp.classList.toggle('is-active', !!on);
		}
	}

	function runCheck() {
		if (!cfg.ajaxurl || !cfg.nonce) {
			return;
		}
		setRunning(true);
		var fd = new FormData();
		fd.append('action', 'radius_deploy_health_check');
		fd.append('nonce', cfg.nonce);
		fetch(cfg.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) {
				return r.json();
			})
			.then(function (json) {
				setRunning(false);
				if (!json || !json.success) {
					var msg =
						json && json.data && json.data.message
							? json.data.message
							: cfg.i18n && cfg.i18n.errorPrefix
								? cfg.i18n.errorPrefix + ' Request failed.'
								: 'Request failed.';
					var resultsEl = document.getElementById('radius-deploy-health-results');
					if (resultsEl) {
						resultsEl.innerHTML =
							'<p class="notice notice-error"><strong>' + esc(msg) + '</strong></p>';
					}
					return;
				}
				renderReport(json.data);
			})
			.catch(function (err) {
				setRunning(false);
				var resultsEl = document.getElementById('radius-deploy-health-results');
				if (resultsEl) {
					resultsEl.innerHTML =
						'<p class="notice notice-error"><strong>' + esc(String(err)) + '</strong></p>';
				}
			});
	}

	function runRemediate(action, triggerBtn) {
		if (!cfg.ajaxurl || !cfg.nonce || !action) {
			return;
		}
		var i18n = cfg.i18n || {};
		if (
			action === 'trash_extra_service_areas' &&
			!window.confirm(
				i18n.trashExtraHubsConfirm ||
					'Move out-of-scope service area hub pages to the Trash?'
			)
		) {
			return;
		}
		if (triggerBtn) {
			triggerBtn.disabled = true;
			triggerBtn.textContent = i18n.trashExtraHubsRunning || 'Trashing hubs…';
		}
		var fd = new FormData();
		fd.append('action', 'radius_deploy_health_remediate');
		fd.append('nonce', cfg.nonce);
		fd.append('remediate_action', action);
		fetch(cfg.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) {
				return r.json();
			})
			.then(function (json) {
				if (triggerBtn) {
					triggerBtn.disabled = false;
					triggerBtn.textContent =
						i18n.trashExtraHubs || 'Trash out-of-scope hubs';
				}
				var resultsEl = document.getElementById('radius-deploy-health-results');
				if (!json || !json.success) {
					var msg =
						json && json.data && json.data.message
							? json.data.message
							: i18n.errorPrefix
								? i18n.errorPrefix + ' Remediation failed.'
								: 'Remediation failed.';
					if (resultsEl) {
						resultsEl.insertAdjacentHTML(
							'afterbegin',
							'<p class="notice notice-error"><strong>' +
								esc(msg) +
								'</strong></p>'
						);
					}
					return;
				}
				if (json.data && json.data.report) {
					renderReport(json.data.report);
				}
				if (resultsEl && json.data && json.data.message) {
					resultsEl.insertAdjacentHTML(
						'afterbegin',
						'<p class="notice notice-success"><strong>' +
							esc(json.data.message) +
							'</strong></p>'
					);
				}
			})
			.catch(function (err) {
				if (triggerBtn) {
					triggerBtn.disabled = false;
					triggerBtn.textContent =
						i18n.trashExtraHubs || 'Trash out-of-scope hubs';
				}
				var resultsEl = document.getElementById('radius-deploy-health-results');
				if (resultsEl) {
					resultsEl.insertAdjacentHTML(
						'afterbegin',
						'<p class="notice notice-error"><strong>' +
							esc(String(err)) +
							'</strong></p>'
					);
				}
			});
	}

	function init() {
		var root = document.getElementById('radius-deploy-health');
		if (!root) {
			return;
		}
		var btn = document.getElementById('radius-deploy-health-run');
		if (btn) {
			btn.addEventListener('click', runCheck);
		}
		root.addEventListener('click', function (ev) {
			var t = ev.target;
			if (
				t &&
				t.classList &&
				t.classList.contains('radius-deploy-health-trash-extra')
			) {
				ev.preventDefault();
				runRemediate(
					t.getAttribute('data-remediate-action') ||
						'trash_extra_service_areas',
					t
				);
			}
		});
		if (cfg.autoRun) {
			runCheck();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
