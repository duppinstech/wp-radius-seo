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

	function countRemediations(checks) {
		var n = 0;
		if (!Array.isArray(checks)) {
			return 0;
		}
		checks.forEach(function (c) {
			if (c && c.remediation && c.remediation.count > 0 && c.remediation.action) {
				n += 1;
			}
		});
		return n;
	}

	function remediateButtonLabel(action, i18n) {
		if (action === 'trash_extra_service_areas') {
			return i18n.trashExtraHubs || 'Trash out-of-scope hubs';
		}
		if (action === 'trash_extra_landings') {
			return i18n.trashExtraLandings || 'Trash out-of-scope landings';
		}
		if (action === 'remove_redirect_conflicts') {
			return i18n.removeRedirectConflicts || 'Remove conflicting redirects';
		}
		if (action === 'deactivate_magic_page_plugin') {
			return i18n.deactivateMagicPage || 'Deactivate Magic Page plugin';
		}
		return 'Fix';
	}

	var lastReport = null;

	function summarizeChecks(checks) {
		var pass = 0;
		var warn = 0;
		var fail = 0;
		var skip = 0;
		if (!Array.isArray(checks)) {
			return { pass: 0, warn: 0, fail: 0, skip: 0, status: 'pass' };
		}
		checks.forEach(function (c) {
			if (!c || !c.status) {
				skip += 1;
				return;
			}
			if (c.status === 'pass') {
				pass += 1;
			} else if (c.status === 'warn') {
				warn += 1;
			} else if (c.status === 'fail') {
				fail += 1;
			} else {
				skip += 1;
			}
		});
		var status = fail > 0 ? 'fail' : warn > 0 ? 'warn' : 'pass';
		return { pass: pass, warn: warn, fail: fail, skip: skip, status: status };
	}

	function renderRedirectScanAllLink(c, i18n) {
		if (!c || !c.redirect_scan_capped) {
			return '';
		}
		return (
			' <button type="button" class="button-link radius-deploy-health-scan-all-redirects" data-remediate-action="scan_redirect_conflicts_all" data-check-id="' +
			esc(c.id || 'url_redirect_conflicts') +
			'">' +
			esc(i18n.checkAllRedirects || 'Check all now') +
			'</button>'
		);
	}

	function renderCheckItem(c, i18n) {
		var stc = c.status || 'skip';
		var html = '<li class="radius-deploy-health-item ' + esc(statusClass(stc)) + '" data-check-id="' + esc(c.id || '') + '">';
		html +=
			'<div class="radius-deploy-health-item__head"><strong>' +
			esc(c.label || c.id || '') +
			'</strong> <span class="radius-badge">' +
			esc(statusLabel(stc)) +
			'</span></div>';
		html += '<p class="radius-deploy-health-item__summary">' + esc(c.summary || '');
		html += renderRedirectScanAllLink(c, i18n);
		html += '</p>';
		if (c.detail) {
			html += '<p class="description">' + esc(c.detail) + '</p>';
		}
		if (c.missing_slugs && c.missing_slugs.length) {
			html +=
				'<p class="description"><strong>' +
				esc(i18n.missingSlugs || 'Sample missing place slugs') +
				':</strong> ' +
				esc(c.missing_slugs.join(', ')) +
				(c.missing_count > c.missing_slugs.length ? ' …' : '') +
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
		if (c.conflict_paths && c.conflict_paths.length) {
			html +=
				'<p class="description"><strong>' +
				esc(i18n.conflictPaths || 'Sample conflicting URL paths') +
				':</strong> ' +
				esc(c.conflict_paths.join(', ')) +
				(c.conflict_count > c.conflict_paths.length ? ' …' : '') +
				'</p>';
		}
		if (c.sample_option_names && c.sample_option_names.length) {
			html +=
				'<p class="description"><strong>' +
				esc(i18n.sampleOptions || 'Sample autoload=yes options') +
				':</strong> <code>' +
				esc(c.sample_option_names.join('</code>, <code>')) +
				'</code>' +
				(c.autoload_yes > c.sample_option_names.length ? ' …' : '') +
				'</p>';
		}
		html += renderRemediationButton(c, i18n);
		if (c.fix_url) {
			html +=
				'<p><a class="button button-small" href="' +
				esc(c.fix_url) +
				'">' +
				esc(i18n.fix || 'Open fix') +
				'</a></p>';
		}
		html += '</li>';
		return html;
	}

	function mergeCheckIntoReport(check) {
		if (!lastReport || !check || !check.id || !Array.isArray(lastReport.checks)) {
			return;
		}
		lastReport.checks = lastReport.checks.map(function (c) {
			return c && c.id === check.id ? check : c;
		});
		lastReport.summary = summarizeChecks(lastReport.checks);
		renderReport(lastReport);
	}

	function renderRemediationButton(c, i18n) {
		var rem = c.remediation;
		if (!rem || rem.count < 1 || !rem.action) {
			return '';
		}
		var label = remediateButtonLabel(rem.action, i18n);
		var html =
			'<p class="radius-deploy-health-remediate">' +
			'<button type="button" class="button button-secondary radius-deploy-health-trash-extra" ' +
			'data-remediate-action="' +
			esc(rem.action) +
			'"';
		if (rem.template_id) {
			html += ' data-template-id="' + esc(String(rem.template_id)) + '"';
		}
		html +=
			' data-check-id="' +
			esc(c.id || '') +
			'" data-btn-label="' +
			esc(label) +
			'">' +
			esc(label) +
			' (' +
			esc(String(rem.count)) +
			')</button></p>';
		return html;
	}

	function renderReport(data) {
		var summaryEl = document.getElementById('radius-deploy-health-summary');
		var resultsEl = document.getElementById('radius-deploy-health-results');
		var fixAllBtn = document.getElementById('radius-deploy-health-fix-all');
		if (!summaryEl || !resultsEl) {
			return;
		}
		lastReport = data;
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
		if (data && data.stored_only) {
			summaryEl.innerHTML +=
				'<br><span class="description">' +
				esc(
					i18n.storedSnapshotHint ||
						'Showing saved results from the last automatic or manual check. Run again for a full report.'
				) +
				'</span>';
		}
		summaryEl.hidden = false;

		var checks = data && Array.isArray(data.checks) ? data.checks : [];
		var remediateN = countRemediations(checks);
		if (fixAllBtn) {
			fixAllBtn.hidden = remediateN < 1;
			fixAllBtn.textContent = i18n.fixAllIssues || 'Fix all issues';
			if (remediateN > 0) {
				fixAllBtn.textContent += ' (' + remediateN + ')';
			}
		}

		var html = '<ul class="radius-deploy-health-list">';
		checks.forEach(function (c) {
			html += renderCheckItem(c, i18n);
		});
		html += '</ul>';
		resultsEl.innerHTML = html;
	}

	function setRunning(on) {
		var btn = document.getElementById('radius-deploy-health-run');
		var fixAll = document.getElementById('radius-deploy-health-fix-all');
		var sp = document.getElementById('radius-deploy-health-spinner');
		if (btn) {
			btn.disabled = !!on;
		}
		if (fixAll) {
			fixAll.disabled = !!on;
		}
		if (sp) {
			sp.classList.toggle('is-active', !!on);
		}
	}

	function confirmRemediate(action, i18n) {
		if (action === 'fix_all_issues') {
			return window.confirm(
				i18n.fixAllConfirm ||
					'Run all automated fixes? Manual deploy steps are skipped.'
			);
		}
		if (action === 'trash_extra_service_areas') {
			return window.confirm(
				i18n.trashExtraHubsConfirm ||
					'Move out-of-scope service area hub pages to the Trash?'
			);
		}
		if (action === 'trash_extra_landings') {
			return window.confirm(
				i18n.trashExtraLandingsConfirm ||
					'Move out-of-scope landing pages to the Trash?'
			);
		}
		if (action === 'remove_redirect_conflicts') {
			return window.confirm(
				i18n.removeRedirectConflictsConfirm ||
					'Remove conflicting redirect rules?'
			);
		}
		if (action === 'deactivate_magic_page_plugin') {
			return window.confirm(
				i18n.deactivateMagicPageConfirm || 'Deactivate the Magic Page plugin?'
			);
		}
		if (action === 'scan_redirect_conflicts_all') {
			return window.confirm(
				i18n.checkAllRedirectsConfirm ||
					'Scan every published landing and service-area URL for redirect conflicts? This can take a minute on large sites.'
			);
		}
		return true;
	}

	function parseAjaxJson(r) {
		return r.text().then(function (text) {
			var raw = text == null ? '' : String(text);
			if (raw.trim() === '') {
				throw new Error('Empty response (connection reset or server timeout).');
			}
			if (raw.trim().charAt(0) === '<') {
				var i18n = cfg.i18n || {};
				throw new Error(
					i18n.htmlNotJson ||
						'Server returned HTML instead of JSON (timeout, security block, or PHP error).'
				);
			}
			try {
				return JSON.parse(raw);
			} catch (e) {
				throw new Error('Invalid JSON: ' + e.message);
			}
		});
	}

	function runningLabel(action, i18n) {
		if (action === 'fix_all_issues') {
			return i18n.fixAllRunning || 'Fixing issues…';
		}
		if (action === 'trash_extra_landings') {
			return i18n.trashExtraLandingsRunning || 'Trashing landings…';
		}
		if (action === 'remove_redirect_conflicts') {
			return i18n.removeRedirectConflictsRunning || 'Removing redirects…';
		}
		if (action === 'deactivate_magic_page_plugin') {
			return i18n.deactivateMagicPageRunning || 'Deactivating…';
		}
		if (action === 'scan_redirect_conflicts_all') {
			return i18n.checkAllRedirectsRunning || 'Scanning all URLs…';
		}
		return i18n.trashExtraHubsRunning || 'Trashing hubs…';
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
			.then(parseAjaxJson)
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

	function runRemediate(action, triggerBtn, templateId) {
		if (!cfg.ajaxurl || !cfg.nonce || !action) {
			return;
		}
		var i18n = cfg.i18n || {};
		if (!confirmRemediate(action, i18n)) {
			return;
		}
		var defaultLabel =
			triggerBtn && triggerBtn.getAttribute('data-btn-label')
				? triggerBtn.getAttribute('data-btn-label')
				: remediateButtonLabel(action, i18n);
		if (triggerBtn) {
			triggerBtn.disabled = true;
			triggerBtn.textContent = runningLabel(action, i18n);
		}
		var fixAllBtn = document.getElementById('radius-deploy-health-fix-all');
		if (fixAllBtn && action === 'fix_all_issues') {
			fixAllBtn.disabled = true;
			fixAllBtn.textContent = runningLabel(action, i18n);
		}
		var fd = new FormData();
		fd.append('action', 'radius_deploy_health_remediate');
		fd.append('nonce', cfg.nonce);
		fd.append('remediate_action', action);
		if (action === 'trash_extra_landings' && templateId) {
			fd.append('template_id', String(templateId));
		}
		fetch(cfg.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(parseAjaxJson)
			.then(function (json) {
				if (triggerBtn) {
					triggerBtn.disabled = false;
					triggerBtn.textContent = defaultLabel;
				}
				if (fixAllBtn) {
					fixAllBtn.disabled = false;
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
				} else if (json.data && json.data.check) {
					mergeCheckIntoReport(json.data.check);
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
					triggerBtn.textContent = defaultLabel;
				}
				if (fixAllBtn) {
					fixAllBtn.disabled = false;
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
		var fixAllBtn = document.getElementById('radius-deploy-health-fix-all');
		if (fixAllBtn) {
			fixAllBtn.addEventListener('click', function () {
				runRemediate('fix_all_issues', fixAllBtn, '');
			});
		}
		root.addEventListener('click', function (ev) {
			var t = ev.target;
			if (
				t &&
				t.classList &&
				(t.classList.contains('radius-deploy-health-trash-extra') ||
					t.classList.contains('radius-deploy-health-scan-all-redirects'))
			) {
				ev.preventDefault();
				runRemediate(
					t.getAttribute('data-remediate-action') ||
						'trash_extra_service_areas',
					t,
					t.getAttribute('data-template-id') || ''
				);
			}
		});
		if (cfg.autoRun) {
			runCheck();
		} else if (cfg.storedReport && cfg.storedReport.summary) {
			renderReport(cfg.storedReport);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
