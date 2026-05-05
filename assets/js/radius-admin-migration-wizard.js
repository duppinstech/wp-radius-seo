/**
 * Magic Page → Radius migration: modal tour (Radius admin when Magic Page is active).
 */
(function () {
	'use strict';

	var cfg =
		typeof window.radiusMigrationWizard === 'object'
			? window.radiusMigrationWizard
			: {};
	var i18n = cfg.i18n || {};

	var STEP_KEYS = [
		'places',
		'templates',
		'anchors',
		'replacers',
		'magic_pages',
		'magic_page_plugin',
		'deploy_areas',
		'deploy_landings',
	];
	var STEP_IDS = {
		places: 'mw-step-places',
		templates: 'mw-step-templates',
		anchors: 'mw-step-anchors',
		replacers: 'mw-step-replacers',
		magic_pages: 'mw-step-magic-pages',
		magic_page_plugin: 'mw-step-magic-page-plugin',
		deploy_areas: 'mw-step-deploy-areas',
		deploy_landings: 'mw-step-deploy-landings',
	};

	var rootEl = null;
	var overlayEl = null;
	var placeStats = null;

	function el(tag, cls, html) {
		var n = document.createElement(tag);
		if (cls) {
			n.className = cls;
		}
		if (html != null) {
			n.innerHTML = html;
		}
		return n;
	}

	function mergeCfgFromPayload(payload) {
		if (!payload || typeof payload !== 'object') {
			return;
		}
		if (payload.service_areas_url) {
			cfg.serviceAreasUrl = payload.service_areas_url;
		}
		if (payload.locations_url) {
			cfg.locationsLibraryUrl = payload.locations_url;
		}
		if (payload.deploy_url) {
			cfg.deployPageUrl = payload.deploy_url;
		}
		if (typeof payload.service_area_template_id === 'number') {
			cfg.serviceAreaTemplateId = payload.service_area_template_id;
		} else if (typeof payload.service_area_template_id === 'string') {
			cfg.serviceAreaTemplateId = parseInt(payload.service_area_template_id, 10) || 0;
		}
		if (Array.isArray(payload.deploy_landing_template_ids)) {
			cfg.deployLandingTemplateIds = payload.deploy_landing_template_ids.map(function (id) {
				return parseInt(id, 10) || 0;
			}).filter(Boolean);
		}
		if (typeof payload.deploy_batch_nonce === 'string') {
			cfg.deployBatchNonce = payload.deploy_batch_nonce;
		}
	}

	function postMigration(action, extra) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', cfg.nonce || '');
		if (extra && typeof extra === 'object') {
			Object.keys(extra).forEach(function (k) {
				fd.append(k, String(extra[k]));
			});
		}
		return fetch(cfg.ajaxurl, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json, text/javascript, */*; q=0.01',
			},
		}).then(function (res) {
			return res.json();
		});
	}

	function postWizard(action, extra) {
		var fd = new FormData();
		fd.append('action', cfg.wizardAction || 'radius_migration_wizard');
		fd.append('nonce', cfg.wizardNonce || '');
		fd.append('wizard_action', action);
		if (extra && typeof extra === 'object') {
			Object.keys(extra).forEach(function (k) {
				fd.append(k, String(extra[k]));
			});
		}
		return fetch(cfg.ajaxurl, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json, text/javascript, */*; q=0.01',
			},
		}).then(function (res) {
			return res.text().then(function (raw) {
				var bad =
					i18n.requestFailed ||
					'Request failed.';
				if (!res.ok) {
					var httpHint =
						res.status === 403
							? ' — your host/WAF may be blocking admin-ajax POSTs.'
							: res.status === 524 || res.status === 504
								? ' — often a Cloudflare/proxy timeout (origin took too long). Retry, or pause Cloudflare for wp-admin / raise origin timeouts.'
								: '';
					return {
						success: false,
						data: {
							message:
								bad + ' (HTTP ' + res.status + httpHint + ')',
						},
					};
				}
				var t = (raw || '').trim();
				if (!t || (t.charAt(0) !== '{' && t.charAt(0) !== '[')) {
					return {
						success: false,
						data: {
							message:
								bad +
								' Non-JSON response (often an HTML error page from a firewall).',
						},
					};
				}
				try {
					return JSON.parse(raw);
				} catch (parseErr) {
					return {
						success: false,
						data: {
							message: bad,
						},
					};
				}
			});
		});
	}

	function rowIdForStep(key) {
		return STEP_IDS[key] || '';
	}

	function countCompletedSteps(steps) {
		if (!steps) {
			return 0;
		}
		var n = 0;
		STEP_KEYS.forEach(function (key) {
			if (steps[key] && steps[key].done) {
				n += 1;
			}
		});
		return n;
	}

	function updateOverallProgress(steps) {
		var wrap = document.getElementById('radius-mw-overall-progress');
		if (!wrap) {
			return;
		}
		var total = STEP_KEYS.length;
		var done = countCompletedSteps(steps);
		var pct = total > 0 ? Math.round((done / total) * 100) : 0;
		var bar = document.getElementById('radius-mw-overall-bar');
		if (bar) {
			bar.value = pct;
			bar.setAttribute('aria-valuenow', String(pct));
		}
		var lab = document.getElementById('radius-mw-overall-progress-label');
		if (lab) {
			var fmt =
				i18n.overallProgressFmt ||
				'%1$d / %2$d steps complete (%3$d%%).';
			lab.textContent = fmt
				.replace('%1$d', String(done))
				.replace('%2$d', String(total))
				.replace('%3$d', String(pct));
		}
	}

	function validatePriorStepsForRun(userWants, steps) {
		var maxIdx = -1;
		var i;
		for (i = 0; i < STEP_KEYS.length; i++) {
			if (userWants[STEP_KEYS[i]]) {
				maxIdx = i;
			}
		}
		if (maxIdx <= 0) {
			return;
		}
		for (i = 0; i < maxIdx; i++) {
			var pk = STEP_KEYS[i];
			if (steps[pk] && steps[pk].done) {
				continue;
			}
			if (userWants[pk]) {
				continue;
			}
			throw new Error(
				i18n.priorStepsIncomplete ||
					'Every earlier step must already be complete or selected to run before the last step you checked.'
			);
		}
	}

	function ensurePriorStepsComplete(steps, upToExclusive) {
		var i;
		for (i = 0; i < upToExclusive; i++) {
			var pk = STEP_KEYS[i];
			if (!steps[pk] || !steps[pk].done) {
				var b1 = i18n.priorStepBlocked || 'Complete step “%s” before continuing.';
				throw new Error(b1.replace('%s', pk));
			}
		}
	}

	function throwIfStepNotDone(stepKey, steps) {
		if (!steps[stepKey] || !steps[stepKey].done) {
			var b2 =
				i18n.stepNotCompleteFailure ||
				'Migration stopped: step did not reach completed status (%s).';
			throw new Error(b2.replace('%s', stepKey));
		}
	}

	/**
	 * @param {Record<string,{done?:boolean}>|null|undefined} steps
	 * @param {Record<string,boolean>|null|undefined} preserveRunFor If set, keeps run checkbox checked for pending re-runs.
	 */
	function refreshStepRows(steps, preserveRunFor) {
		if (!steps) {
			return;
		}
		STEP_KEYS.forEach(function (key) {
			var id = rowIdForStep(key);
			var row = document.getElementById(id);
			if (!row) {
				return;
			}
			var s = steps[key];
			var done = s && s.done;
			var badge = row.querySelector('.radius-mw-step-badge');
			var cb = row.querySelector('.radius-mw-step-run');
			var waitEl = row.querySelector('.radius-mw-step-wait');
			if (waitEl) {
				waitEl.hidden = true;
			}
			if (badge) {
				badge.hidden = false;
				badge.textContent = done
					? i18n.completed || 'Completed'
					: i18n.incomplete || 'Incomplete';
				badge.className =
					'radius-mw-step-badge ' +
					(done
						? 'radius-mw-step-badge--complete'
						: 'radius-mw-step-badge--incomplete');
			}
			if (cb) {
				cb.checked = !done;
				if (preserveRunFor && preserveRunFor[key]) {
					cb.checked = true;
				}
			}
			if (done) {
				row.setAttribute('data-done', '1');
			} else {
				row.removeAttribute('data-done');
			}
		});
		updateOverallProgress(steps);
	}

	function applyPrefilledSteps(steps) {
		refreshStepRows(steps);
	}

	function ensureModal(payload) {
		mergeCfgFromPayload(payload);
		if (rootEl) {
			if (payload && payload.steps) {
				applyPrefilledSteps(payload.steps);
			} else {
				postWizard('status').then(function (st) {
					if (st.success && st.data && st.data.steps) {
						mergeCfgFromPayload(st.data);
						applyPrefilledSteps(st.data.steps);
					}
				});
			}
			return;
		}
		overlayEl = el('div', 'radius-mw-overlay', '');
		overlayEl.setAttribute('role', 'dialog');
		overlayEl.setAttribute('aria-modal', 'true');
		overlayEl.setAttribute('aria-labelledby', 'radius-mw-title');

		var panel = el('div', 'radius-mw-panel', '');
		var head = el('div', 'radius-mw-head', '');
		var hTitle = el(
			'h1',
			'radius-mw-title',
			(i18n.title || 'Migration') + ''
		);
		hTitle.id = 'radius-mw-title';
		head.appendChild(hTitle);
		var intro = el('p', 'radius-mw-intro', i18n.intro || '');
		var overallWrap = el('div', 'radius-mw-overall-progress', '');
		overallWrap.id = 'radius-mw-overall-progress';
		var overallLab = el(
			'label',
			'radius-mw-overall-progress-heading',
			i18n.overallProgressHeading || 'Overall progress'
		);
		overallLab.setAttribute('for', 'radius-mw-overall-bar');
		var overallBar = el('progress', 'radius-mw-overall-bar', '');
		overallBar.id = 'radius-mw-overall-bar';
		overallBar.max = 100;
		overallBar.value = 0;
		overallBar.setAttribute('aria-valuemin', '0');
		overallBar.setAttribute('aria-valuemax', '100');
		overallBar.setAttribute('aria-valuenow', '0');
		var overallPct = el(
			'p',
			'radius-mw-overall-progress-label',
			''
		);
		overallPct.id = 'radius-mw-overall-progress-label';
		overallWrap.appendChild(overallLab);
		overallWrap.appendChild(overallBar);
		overallWrap.appendChild(overallPct);

		var stepsOl = el('ol', 'radius-mw-steps radius-mw-steps--checklist', '');
		stepsOl.appendChild(
			createStepRow('mw-step-places', i18n.stepPlaces, 'spin', 'places')
		);
		stepsOl.appendChild(
			createStepRow(
				'mw-step-templates',
				i18n.stepTemplates,
				'spin',
				'templates'
			)
		);
		stepsOl.appendChild(
			createStepRow('mw-step-anchors', i18n.stepAnchors, 'spin', 'anchors')
		);
		stepsOl.appendChild(
			createStepRow(
				'mw-step-replacers',
				i18n.stepReplacers,
				'spin',
				'replacers'
			)
		);
		stepsOl.appendChild(
			createStepRow(
				'mw-step-magic-pages',
				i18n.stepMagicPages,
				'spin',
				'magic_pages'
			)
		);
		stepsOl.appendChild(
			createStepRow(
				'mw-step-magic-page-plugin',
				i18n.stepMagicPagePlugin || '',
				'spin',
				'magic_page_plugin',
				true
			)
		);
		stepsOl.appendChild(
			createStepRow(
				'mw-step-deploy-areas',
				i18n.stepDeployAreas || '',
				'spin',
				'deploy_areas'
			)
		);
		stepsOl.appendChild(
			createStepRow(
				'mw-step-deploy-landings',
				i18n.stepDeployLandings || '',
				'spin',
				'deploy_landings'
			)
		);

		var run = el('div', 'radius-mw-run', '');
		var completion = el('div', 'radius-mw-completion', '');
		completion.id = 'radius-mw-completion';
		completion.setAttribute('role', 'status');
		completion.hidden = true;

		var foot = el('div', 'radius-mw-foot', '');

		var start = el('button', 'button button-primary', i18n.start || 'Start');
		start.type = 'button';
		start.id = 'radius-mw-start';
		var dismiss = el('button', 'button', i18n.dismiss || 'Not now');
		dismiss.type = 'button';
		dismiss.id = 'radius-mw-dismiss';
		foot.appendChild(dismiss);
		foot.appendChild(start);

		var summary = el('div', 'radius-mw-summary', '');
		summary.hidden = true;
		summary.id = 'radius-mw-summary';

		panel.appendChild(head);
		panel.appendChild(intro);
		panel.appendChild(overallWrap);
		panel.appendChild(stepsOl);
		panel.appendChild(run);
		panel.appendChild(summary);
		panel.appendChild(completion);
		panel.appendChild(foot);
		overlayEl.appendChild(panel);
		document.body.appendChild(overlayEl);

		start.addEventListener('click', onStart);
		dismiss.addEventListener('click', onDismiss);
		bindMagicPagePluginButtons();
		rootEl = panel;
		if (payload && payload.steps) {
			applyPrefilledSteps(payload.steps);
		} else if (payload) {
			postWizard('status').then(function (st) {
				if (st.success && st.data && st.data.steps) {
					mergeCfgFromPayload(st.data);
					applyPrefilledSteps(st.data.steps);
				}
			});
		}
	}

	function bindMagicPagePluginButtons() {
		var row = document.getElementById('mw-step-magic-page-plugin');
		if (!row) {
			return;
		}
		var deact = row.querySelector('[data-radius-mw-mp-deactivate]');
		var del = row.querySelector('[data-radius-mw-mp-delete]');
		function refreshFromServer() {
			postWizard('status').then(function (st) {
				if (st.success && st.data && st.data.steps) {
					mergeCfgFromPayload(st.data);
					refreshStepRows(st.data.steps);
				}
			});
		}
		if (deact) {
			deact.addEventListener('click', function (e) {
				e.preventDefault();
				deact.disabled = true;
				postWizard('magic_page_plugin_deactivate')
					.then(function (r) {
						if (!r.success) {
							window.alert(
								(r.data && r.data.message) ||
									i18n.requestFailed ||
									'Request failed'
							);
							return;
						}
						refreshFromServer();
					})
					.finally(function () {
						deact.disabled = false;
					});
			});
		}
		if (del) {
			del.addEventListener('click', function (e) {
				e.preventDefault();
				if (
					!window.confirm(
						i18n.confirmDeleteMagicPage ||
							'Delete the Magic Page plugin files from this site?'
					)
				) {
					return;
				}
				del.disabled = true;
				postWizard('magic_page_plugin_delete')
					.then(function (r) {
						if (!r.success) {
							window.alert(
								(r.data && r.data.message) ||
									i18n.requestFailed ||
									'Request failed'
							);
							return;
						}
						refreshFromServer();
					})
					.finally(function () {
						del.disabled = false;
					});
			});
		}
	}

	function createStepRow(id, label, mode, stepKey, pluginRow) {
		var li = el('li', 'radius-mw-step', '');
		li.id = id;
		li.setAttribute('data-step-key', stepKey);

		var runWrap = el('label', 'radius-mw-step-run-wrap', '');
		var sr = el('span', 'screen-reader-text', i18n.runThisStep || '');
		var cb = document.createElement('input');
		cb.type = 'checkbox';
		cb.className = 'radius-mw-step-run';
		cb.setAttribute('data-step', stepKey);
		cb.title = i18n.runThisStep || '';
		runWrap.appendChild(sr);
		runWrap.appendChild(cb);

		var main = el('div', 'radius-mw-step-main', '');
		var lab = el('span', 'radius-mw-step-label', label);
		main.appendChild(lab);
		if (pluginRow) {
			var pa = el('div', 'radius-mw-step-plugin-actions');
			var bDeact = el(
				'button',
				'button button-small',
				i18n.deactivateMagicPage || 'Deactivate'
			);
			bDeact.type = 'button';
			bDeact.setAttribute('data-radius-mw-mp-deactivate', '1');
			var bDel = el(
				'button',
				'button button-small',
				i18n.deleteMagicPagePlugin || 'Delete plugin'
			);
			bDel.type = 'button';
			bDel.setAttribute('data-radius-mw-mp-delete', '1');
			pa.appendChild(bDeact);
			pa.appendChild(bDel);
			main.appendChild(pa);
		}

		var meta = el('div', 'radius-mw-step-meta', '');
		var wait = el('span', 'radius-mw-step-wait', '');
		wait.hidden = true;
		wait.setAttribute('aria-hidden', 'true');
		var badge = el(
			'span',
			'radius-mw-step-badge radius-mw-step-badge--incomplete',
			i18n.incomplete || 'Incomplete'
		);
		meta.appendChild(wait);
		meta.appendChild(badge);

		li.appendChild(runWrap);
		li.appendChild(main);
		li.appendChild(meta);
		return li;
	}

	function setStepState(id, state) {
		var row = document.getElementById(id);
		if (!row) {
			return;
		}
		var badge = row.querySelector('.radius-mw-step-badge');
		var waitEl = row.querySelector('.radius-mw-step-wait');
		if (state === 'wait') {
			if (badge) {
				badge.hidden = true;
			}
			if (waitEl) {
				waitEl.hidden = false;
				waitEl.setAttribute('data-state', 'wait');
			}
			return;
		}
		if (waitEl) {
			waitEl.hidden = true;
			waitEl.removeAttribute('data-state');
		}
		if (state === 'done') {
			if (badge) {
				badge.hidden = false;
				badge.textContent = i18n.completed || 'Completed';
				badge.className =
					'radius-mw-step-badge radius-mw-step-badge--complete';
			}
			var cb = row.querySelector('.radius-mw-step-run');
			if (cb) {
				cb.checked = false;
			}
			row.setAttribute('data-done', '1');
			return;
		}
		if (badge) {
			badge.hidden = false;
			badge.textContent = i18n.incomplete || 'Incomplete';
			badge.className =
				'radius-mw-step-badge radius-mw-step-badge--incomplete';
		}
		row.removeAttribute('data-done');
	}

	function showMigrationCompleteBanner() {
		var el = document.getElementById('radius-mw-completion');
		if (!el) {
			return;
		}
		el.innerHTML =
			'<p class="radius-mw-completion-msg"><strong>' +
			esc(i18n.migrationCompletedTitle || 'Migration complete') +
			'</strong> ' +
			esc(i18n.migrationCompletedBody || '') +
			'</p>';
		el.hidden = false;
	}

	function showSummary(data) {
		var sum = document.getElementById('radius-mw-summary');
		if (!sum) {
			return;
		}
		var depUrl = cfg.deployPageUrl || 'admin.php?page=radius-deploy';
		var areasUrl = cfg.serviceAreasUrl || '#';
		var locUrl = cfg.locationsLibraryUrl || '#';

		var parts = [];
		if (data && data.allDone) {
			parts.push(
				'<p class="radius-mw-all-done">' +
					esc(i18n.allStepsDone || '') +
					'</p>'
			);
		}
		if (!(data && data.allDone)) {
			if (placeStats && placeStats.success) {
				if (placeStats.skipped) {
					parts.push(
						'<p><strong>' +
							esc(i18n.summaryLocations) +
							':</strong> ' +
							esc(i18n.summarySkippedPlaces || '') +
							'</p>'
					);
				} else {
					parts.push(
						'<p><strong>' +
							esc(i18n.summaryLocations) +
							':</strong> ' +
							esc(
								String(
									placeStats.sumImported +
										' new, ' +
										placeStats.sumUpdated +
										' updated' +
										(placeStats.totalLegacy
											? ' (' +
											  placeStats.totalLegacy +
											  ' legacy terms)'
											: '')
								)
							) +
							'</p>'
					);
				}
			} else if (placeStats && !placeStats.success) {
				parts.push(
					'<p class="radius-mw-warn"><strong>' +
						esc(i18n.summaryLocations) +
						':</strong> ' +
						esc(placeStats.error || 'incomplete') +
						'</p>'
				);
			}
			var tpl = data && data.templates;
			var skips = (data && data.skips) || {};
			if (skips.templates) {
				parts.push(
					'<p><strong>' +
						esc(i18n.summaryTemplates) +
						':</strong> ' +
						esc(i18n.summarySkippedTemplates || '') +
						'</p>'
				);
			} else if (tpl && tpl.service_template_labels) {
				parts.push(
					'<p><strong>' +
						esc(i18n.summaryTemplates) +
						':</strong> ' +
						esc(String(tpl.service_template_labels.length)) +
						'</p><ul class="radius-mw-list">'
				);
				tpl.service_template_labels.forEach(function (r) {
					parts.push('<li>' + esc(r.label || '') + '</li>');
				});
				parts.push('</ul>');
			}
			if (skips.anchors) {
				parts.push(
					'<p><strong>' +
						esc(i18n.summaryAnchors) +
						':</strong> ' +
						esc(i18n.summarySkippedAnchors || '') +
						'</p>'
				);
			} else if (data && data.anchors) {
				parts.push(
					'<p><strong>' +
						esc(i18n.summaryAnchors) +
						':</strong> ' +
						esc(String(data.anchors.anchors_count || 0)) +
						'</p>'
				);
				if (data.anchors.anchor_labels && data.anchors.anchor_labels.length) {
					parts.push('<ul class="radius-mw-list">');
					data.anchors.anchor_labels.forEach(function (n) {
						if (n) {
							parts.push('<li>' + esc(n) + '</li>');
						}
					});
					parts.push('</ul>');
				}
			}
			if (skips.replacers) {
				parts.push(
					'<p><strong>' +
						esc(i18n.summaryReplacers) +
						':</strong> ' +
						esc(i18n.summarySkippedReplacers || '') +
						'</p>'
				);
			} else if (data && data.replacers && Object.keys(data.replacers).length) {
				parts.push(
					'<p><strong>' +
						esc(i18n.summaryReplacers) +
						':</strong> ' +
						esc(String(data.replacers.updated || 0)) +
						' ' +
						esc(
							data.replacers.keys && data.replacers.keys.length
								? '(' + data.replacers.keys.join(', ') + ')'
								: ''
						) +
						'</p>'
				);
			}
			if (skips.magic_pages) {
				parts.push(
					'<p><strong>' +
						esc(i18n.summaryMagicPages || '') +
						':</strong> ' +
						esc(i18n.summarySkippedMagicPages || '') +
						'</p>'
				);
			} else if (
				data &&
				data.magic_pages &&
				(typeof data.magic_pages.candidate_count === 'number' ||
					typeof data.magic_pages.deleted_count === 'number')
			) {
				var mp = data.magic_pages;
				var mpLine =
					(typeof mp.deleted_count === 'number'
						? mp.deleted_count
						: mp.candidate_count || 0) +
					' ' +
					(i18n.summaryMagicPagesRemoved || 'removed');
				if (mp.blocked_message) {
					mpLine = esc(String(mp.blocked_message));
				}
				parts.push(
					'<p><strong>' +
						esc(i18n.summaryMagicPages || '') +
						':</strong> ' +
						esc(mpLine) +
						'</p>'
				);
			}
			if (tpl && tpl.errors && tpl.errors.length) {
				parts.push(
					'<p class="radius-mw-warn">' +
						esc(tpl.errors.join(' ')) +
						'</p>'
				);
			}
		}
		var summaryTitle =
			data && data.migrationFullyCompleted
				? i18n.summaryAfterDeploy || i18n.deployCta || 'Ready'
				: i18n.deployCta || 'Ready';
		sum.innerHTML =
			'<h2 class="radius-mw-summary-title">' +
			esc(summaryTitle) +
			'</h2>' +
			parts.join('') +
			'<p class="radius-mw-deploy-actions radius-mw-deploy-actions--summary">' +
			'<a class="button" href="' +
			escAttr(locUrl) +
			'">' +
			esc(i18n.locationLibrary || 'Location library') +
			'</a> ' +
			'<a class="button" href="' +
			escAttr(areasUrl) +
			'">' +
			esc(i18n.serviceAreasBtn || 'Service areas') +
			'</a> ' +
			'<a class="button" href="' +
			escAttr(depUrl) +
			'">' +
			esc(i18n.goDeploy || 'Open Deploy') +
			'</a>' +
			'</p>';
		sum.hidden = false;
		var intro = document.querySelector('.radius-mw-intro');
		if (intro) {
			intro.hidden = true;
		}
		var overall = document.getElementById('radius-mw-overall-progress');
		if (overall) {
			overall.hidden = true;
		}
		var steps = document.querySelector('.radius-mw-steps');
		if (steps) {
			steps.hidden = true;
		}
		var run = document.querySelector('.radius-mw-run');
		if (run) {
			run.innerHTML = '';
			run.hidden = true;
		}
		var foot = document.querySelector('.radius-mw-foot');
		if (foot) {
			foot.innerHTML = '';
		}
	}

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	function escAttr(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/</g, '&lt;');
	}

	function onDismiss() {
		postWizard('dismiss');
		closeModal();
	}

	function closeModal() {
		if (overlayEl) {
			overlayEl.remove();
			overlayEl = null;
			rootEl = null;
		}
	}

	function wantsRun(stepKey) {
		var row = document.getElementById(rowIdForStep(stepKey));
		if (!row) {
			return true;
		}
		var cb = row.querySelector('.radius-mw-step-run');
		return cb ? cb.checked : true;
	}

	function preserveAfterRan(userWants, ran) {
		var m = {};
		STEP_KEYS.forEach(function (k) {
			if (userWants[k] && !ran[k]) {
				m[k] = true;
			}
		});
		return m;
	}

	async function runDeployBatchRequest(templateId, target, continuing) {
		var fd = new FormData();
		fd.append('action', 'radius_deploy_batch');
		fd.append('nonce', cfg.deployBatchNonce || '');
		fd.append('radius_template_id', String(templateId));
		fd.append('radius_deploy_target', target);
		if (continuing) {
			fd.append('radius_deploy_continue', '1');
		}
		var res = await fetch(cfg.ajaxurl, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json, text/javascript, */*; q=0.01',
			},
		});
		return res.json();
	}

	async function runDeployChain(templateId, target) {
		var cont = false;
		for (;;) {
			var json = await runDeployBatchRequest(templateId, target, cont);
			if (!json || typeof json.success !== 'boolean') {
				throw new Error(i18n.deployBadResponse || 'Unexpected deploy response.');
			}
			if (!json.success) {
				var msg =
					json.data &&
					typeof json.data.message === 'string' &&
					json.data.message !== ''
						? json.data.message
						: i18n.deployFailed || 'Deploy failed.';
				throw new Error(msg);
			}
			var d = json.data || {};
			if (d.done) {
				return d;
			}
			cont = true;
		}
	}

	async function onStart() {
		var userWants = {};
		STEP_KEYS.forEach(function (k) {
			userWants[k] = wantsRun(k);
		});
		var ran = {};
		STEP_KEYS.forEach(function (k) {
			ran[k] = false;
		});

		var start = document.getElementById('radius-mw-start');
		if (start) {
			start.disabled = true;
		}
		var run = document.querySelector('.radius-mw-run');
		if (run) {
			run.innerHTML =
				'<p class="radius-mw-running"><span class="radius-mw-spinner" aria-hidden="true"></span> ' +
				esc(i18n.running || 'Working…') +
				'</p>';
			run.hidden = false;
		}
		var completionEl = document.getElementById('radius-mw-completion');
		if (completionEl) {
			completionEl.hidden = true;
			completionEl.innerHTML = '';
		}

		var stFresh = await postWizard('status');
		if (!stFresh.success || !stFresh.data) {
			if (run) {
				run.innerHTML =
					'<p class="radius-mw-error">' +
					esc(i18n.requestFailed || 'Request failed') +
					'</p>';
			}
			if (start) {
				start.disabled = false;
			}
			return;
		}
		mergeCfgFromPayload(stFresh.data);
		var steps = stFresh.data.steps || {};

		// Single steps_reset replaces N× step_reset — clears persisted “recorded” flags only (needed so deploy-only steps can re-run and the log stays honest).
		var stepsToReset = [];
		STEP_KEYS.forEach(function (k) {
			if (userWants[k] && steps[k] && steps[k].recorded) {
				stepsToReset.push(k);
			}
		});
		if (stepsToReset.length) {
			await postWizard('steps_reset', { steps: stepsToReset.join(',') });
			stFresh = await postWizard('status');
			if (stFresh.success && stFresh.data) {
				mergeCfgFromPayload(stFresh.data);
				steps = stFresh.data.steps || {};
			}
		}

		refreshStepRows(steps, preserveAfterRan(userWants, ran));

		var allDone = STEP_KEYS.every(function (k) {
			return steps[k] && steps[k].done;
		});
		var anyRunDesired = STEP_KEYS.some(function (k) {
			return userWants[k];
		});

		if (anyRunDesired) {
			try {
				validatePriorStepsForRun(userWants, steps);
			} catch (ve) {
				if (run) {
					run.innerHTML =
						'<p class="radius-mw-error">' +
						esc(String(ve && ve.message ? ve.message : ve)) +
						'</p>';
				}
				if (start) {
					start.disabled = false;
				}
				return;
			}
		}

		if (allDone && !anyRunDesired) {
			placeStats = { success: true, skipped: true };
			showSummary({ allDone: true });
			if (run) {
				run.innerHTML = '';
				run.hidden = true;
			}
			if (start) {
				start.disabled = false;
			}
			return;
		}

		window.radiusLegacyImportSkipExisting = '0';

		placeStats = null;
		var tpl = {};
		var jRepData = {};
		var jAncData = {};
		var jMpData = {};
		var skips = {};
		STEP_KEYS.forEach(function (k) {
			skips[k] = false;
		});
		var migrationFullyCompleted = false;

		try {
			ensurePriorStepsComplete(steps, 0);
			if (userWants.places) {
				setStepState('mw-step-places', 'wait');
				if (typeof window.radiusLegacyImportRunAll !== 'function') {
					throw new Error(
						i18n.legacyImportMissing ||
							'Legacy place import script not loaded.'
					);
				}
				placeStats = await window.radiusLegacyImportRunAll();
				if (!placeStats || placeStats.success === false) {
					throw new Error(
						(placeStats && placeStats.error) ||
							'Legacy location import did not complete.'
					);
				}
				stFresh = await postWizard('status');
				if (stFresh.success && stFresh.data) {
					mergeCfgFromPayload(stFresh.data);
					steps = stFresh.data.steps || {};
				}
				if (!steps.places || !steps.places.done) {
					throw new Error(
						i18n.placesCountMismatch ||
							'The place library does not match the legacy location count. Import all legacy locations on the Locations screen, then try again.'
					);
				}
				setStepState('mw-step-places', 'done');
				await postWizard('step_complete', { step: 'places' });
				stFresh = await postWizard('status');
				if (stFresh.success && stFresh.data && stFresh.data.steps) {
					steps = stFresh.data.steps;
					refreshStepRows(steps, preserveAfterRan(userWants, ran));
				}
				throwIfStepNotDone('places', steps);
			} else {
				if (steps.places && steps.places.done) {
					setStepState('mw-step-places', 'done');
				} else {
					setStepState('mw-step-places', 'idle');
				}
				skips.places = true;
			}
			ran.places = true;

			stFresh = await postWizard('status');
			if (stFresh.success && stFresh.data && stFresh.data.steps) {
				steps = stFresh.data.steps;
				refreshStepRows(steps, preserveAfterRan(userWants, ran));
			}

			ensurePriorStepsComplete(steps, 1);
			if (userWants.templates) {
				setStepState('mw-step-templates', 'wait');
				var jTpl = await postWizard('templates_pipeline');
				if (!jTpl.success) {
					throw new Error(
						(jTpl.data && jTpl.data.message) ||
							i18n.requestFailed ||
							'Request failed'
					);
				}
				var tplAcc = jTpl.data || {};
				while (tplAcc.pipeline_continue_required) {
					await new Promise(function (r) {
						setTimeout(r, 400);
					});
					var contOk = false;
					var ctry = 0;
					while (ctry < 3 && !contOk) {
						jTpl = await postWizard('templates_pipeline_continue');
						if (jTpl.success) {
							contOk = true;
						} else {
							ctry += 1;
							if (ctry < 3) {
								await new Promise(function (r) {
									setTimeout(r, 2000 * ctry);
								});
							}
						}
					}
					if (!contOk) {
						throw new Error(
							(jTpl.data && jTpl.data.message) ||
								i18n.requestFailed ||
								'Request failed'
						);
					}
					tplAcc = jTpl.data || {};
				}
				tpl = tplAcc;
				setStepState('mw-step-templates', 'done');
				stFresh = await postWizard('status');
				if (stFresh.success && stFresh.data && stFresh.data.steps) {
					steps = stFresh.data.steps;
					refreshStepRows(steps, preserveAfterRan(userWants, ran));
				}
				throwIfStepNotDone('templates', steps);
			} else {
				if (steps.templates && steps.templates.done) {
					setStepState('mw-step-templates', 'done');
				} else {
					setStepState('mw-step-templates', 'idle');
				}
				skips.templates = true;
			}
			ran.templates = true;

			stFresh = await postWizard('status');
			if (stFresh.success && stFresh.data && stFresh.data.steps) {
				steps = stFresh.data.steps;
				refreshStepRows(steps, preserveAfterRan(userWants, ran));
			}

			ensurePriorStepsComplete(steps, 2);
			if (userWants.anchors) {
				setStepState('mw-step-anchors', 'wait');
				var jAnc = await postWizard('service_anchors');
				if (!jAnc.success) {
					throw new Error(
						(jAnc.data && jAnc.data.message) ||
							i18n.requestFailed ||
							'Request failed'
					);
				}
				jAncData = jAnc.data || {};
				setStepState('mw-step-anchors', 'done');
				stFresh = await postWizard('status');
				if (stFresh.success && stFresh.data && stFresh.data.steps) {
					steps = stFresh.data.steps;
					refreshStepRows(steps, preserveAfterRan(userWants, ran));
				}
				throwIfStepNotDone('anchors', steps);
			} else {
				if (steps.anchors && steps.anchors.done) {
					setStepState('mw-step-anchors', 'done');
				} else {
					setStepState('mw-step-anchors', 'idle');
				}
				skips.anchors = true;
			}
			ran.anchors = true;

			stFresh = await postWizard('status');
			if (stFresh.success && stFresh.data && stFresh.data.steps) {
				steps = stFresh.data.steps;
				refreshStepRows(steps, preserveAfterRan(userWants, ran));
			}

			ensurePriorStepsComplete(steps, 3);
			if (userWants.replacers) {
				setStepState('mw-step-replacers', 'wait');
				var jRep = await postWizard('site_replacers');
				if (!jRep.success) {
					throw new Error(
						(jRep.data && jRep.data.message) ||
							i18n.requestFailed ||
							'Request failed'
					);
				}
				jRepData = jRep.data || {};
				setStepState('mw-step-replacers', 'done');
				stFresh = await postWizard('status');
				if (stFresh.success && stFresh.data && stFresh.data.steps) {
					steps = stFresh.data.steps;
					refreshStepRows(steps, preserveAfterRan(userWants, ran));
				}
				throwIfStepNotDone('replacers', steps);
			} else {
				if (steps.replacers && steps.replacers.done) {
					setStepState('mw-step-replacers', 'done');
				} else {
					setStepState('mw-step-replacers', 'idle');
				}
				skips.replacers = true;
			}
			ran.replacers = true;

			stFresh = await postWizard('status');
			if (stFresh.success && stFresh.data && stFresh.data.steps) {
				steps = stFresh.data.steps;
				refreshStepRows(steps, preserveAfterRan(userWants, ran));
			}

			ensurePriorStepsComplete(steps, 4);
			if (userWants.magic_pages) {
				setStepState('mw-step-magic-pages', 'wait');
				var mpAfter = 0;
				var mpCand = null;
				var lastMp = {};
				while (true) {
					var jMp = await postWizard('magic_pages_cleanup', {
						after_post_id: String(mpAfter),
					});
					if (!jMp.success) {
						throw new Error(
							(jMp.data && jMp.data.message) ||
								i18n.requestFailed ||
								'Request failed'
						);
					}
					lastMp = jMp.data || {};
					if (typeof lastMp.candidate_count === 'number') {
						mpCand = lastMp.candidate_count;
					}
					if (!lastMp.has_more) {
						break;
					}
					mpAfter =
						lastMp.next_after_post_id != null
							? parseInt(lastMp.next_after_post_id, 10) || 0
							: 0;
					await new Promise(function (r) {
						setTimeout(r, 250);
					});
				}
				jMpData = Object.assign({}, lastMp, {
					deleted_count:
						lastMp.deleted_running_total != null
							? lastMp.deleted_running_total
							: lastMp.deleted_this_batch || 0,
					candidate_count:
						mpCand != null ? mpCand : lastMp.candidate_count,
				});
				setStepState('mw-step-magic-pages', 'done');
				stFresh = await postWizard('status');
				if (stFresh.success && stFresh.data && stFresh.data.steps) {
					steps = stFresh.data.steps;
					refreshStepRows(steps, preserveAfterRan(userWants, ran));
				}
				throwIfStepNotDone('magic_pages', steps);
			} else {
				if (steps.magic_pages && steps.magic_pages.done) {
					setStepState('mw-step-magic-pages', 'done');
				} else {
					setStepState('mw-step-magic-pages', 'idle');
				}
				skips.magic_pages = true;
			}
			ran.magic_pages = true;

			stFresh = await postWizard('status');
			if (stFresh.success && stFresh.data && stFresh.data.steps) {
				steps = stFresh.data.steps;
				refreshStepRows(steps, preserveAfterRan(userWants, ran));
			}

			ensurePriorStepsComplete(steps, 5);
			var runServiceAreas =
				userWants.deploy_areas || userWants.deploy_landings;
			if (userWants.magic_page_plugin) {
				setStepState('mw-step-magic-page-plugin', 'wait');
				var jRm = await postWizard('magic_page_plugin_remove');
				if (!jRm.success) {
					throw new Error(
						(jRm.data && jRm.data.message) ||
							i18n.requestFailed ||
							'Request failed'
					);
				}
				setStepState('mw-step-magic-page-plugin', 'done');
				stFresh = await postWizard('status');
				if (stFresh.success && stFresh.data && stFresh.data.steps) {
					steps = stFresh.data.steps;
					refreshStepRows(steps, preserveAfterRan(userWants, ran));
				}
				throwIfStepNotDone('magic_page_plugin', steps);
			} else {
				if (steps.magic_page_plugin && steps.magic_page_plugin.done) {
					setStepState('mw-step-magic-page-plugin', 'done');
				} else {
					setStepState('mw-step-magic-page-plugin', 'idle');
				}
				skips.magic_page_plugin = true;
			}
			ran.magic_page_plugin = true;

			stFresh = await postWizard('status');
			if (stFresh.success && stFresh.data) {
				mergeCfgFromPayload(stFresh.data);
				steps = stFresh.data.steps || {};
				refreshStepRows(steps, preserveAfterRan(userWants, ran));
			}

			ensurePriorStepsComplete(steps, 6);
			if (!cfg.deployBatchNonce) {
				stFresh = await postWizard('status');
				if (stFresh.success && stFresh.data) {
					mergeCfgFromPayload(stFresh.data);
				}
			}

			if (runServiceAreas) {
				setStepState('mw-step-deploy-areas', 'wait');
				var statusPayload =
					stFresh.success && stFresh.data ? stFresh.data : {};
				var saId =
					cfg.serviceAreaTemplateId ||
					statusPayload.service_area_template_id ||
					0;
				saId = parseInt(saId, 10) || 0;
				if (saId <= 0) {
					throw new Error(
						i18n.deployMissingServiceAreaTemplate ||
							'Set the service area template under Radius → Settings → General, save, then run deployment again.'
					);
				}
				await runDeployChain(saId, 'radius_service_area');
				await postWizard('step_complete', { step: 'deploy_areas' });
				setStepState('mw-step-deploy-areas', 'done');
				stFresh = await postWizard('status');
				if (stFresh.success && stFresh.data && stFresh.data.steps) {
					steps = stFresh.data.steps;
					refreshStepRows(steps, preserveAfterRan(userWants, ran));
				}
				throwIfStepNotDone('deploy_areas', steps);
			} else {
				if (steps.deploy_areas && steps.deploy_areas.done) {
					setStepState('mw-step-deploy-areas', 'done');
				} else {
					setStepState('mw-step-deploy-areas', 'idle');
				}
				skips.deploy_areas = true;
			}
			ran.deploy_areas = true;

			stFresh = await postWizard('status');
			if (stFresh.success && stFresh.data && stFresh.data.steps) {
				steps = stFresh.data.steps;
				refreshStepRows(steps, preserveAfterRan(userWants, ran));
			}

			ensurePriorStepsComplete(steps, 7);
			if (userWants.deploy_landings) {
				setStepState('mw-step-deploy-landings', 'wait');
				var landingIds = cfg.deployLandingTemplateIds || [];
				var lastPayload =
					stFresh.success && stFresh.data ? stFresh.data : {};
				if (
					(!landingIds || !landingIds.length) &&
					Array.isArray(lastPayload.deploy_landing_template_ids)
				) {
					landingIds = lastPayload.deploy_landing_template_ids;
				}
				if (!landingIds || !landingIds.length) {
					throw new Error(
						i18n.deployMissingLandingTemplates ||
							'Could not find published service templates (towing, roadside, heavy, equipment). Run the templates step first.'
					);
				}
				for (var ti = 0; ti < landingIds.length; ti++) {
					await runDeployChain(landingIds[ti], 'radius_landing');
				}
				await postWizard('step_complete', { step: 'deploy_landings' });
				setStepState('mw-step-deploy-landings', 'done');
				stFresh = await postWizard('status');
				if (stFresh.success && stFresh.data && stFresh.data.steps) {
					steps = stFresh.data.steps;
					refreshStepRows(steps, preserveAfterRan(userWants, ran));
				}
				throwIfStepNotDone('deploy_landings', steps);
				migrationFullyCompleted = true;
				await postWizard('complete');
				showMigrationCompleteBanner();
			} else {
				if (steps.deploy_landings && steps.deploy_landings.done) {
					setStepState('mw-step-deploy-landings', 'done');
				} else {
					setStepState('mw-step-deploy-landings', 'idle');
				}
				skips.deploy_landings = true;
			}
			ran.deploy_landings = true;

			stFresh = await postWizard('status');
			if (stFresh.success && stFresh.data && stFresh.data.steps) {
				steps = stFresh.data.steps;
				refreshStepRows(steps, preserveAfterRan(userWants, ran));
			}

			showSummary({
				templates: tpl,
				replacers: jRepData,
				anchors: jAncData,
				magic_pages: jMpData,
				skips: skips,
				migrationFullyCompleted: migrationFullyCompleted,
			});
			if (run) {
				run.innerHTML = '';
				run.hidden = true;
			}
		} catch (err) {
			postWizard('status').then(function (st) {
				if (st.success && st.data && st.data.steps) {
					mergeCfgFromPayload(st.data);
					refreshStepRows(st.data.steps);
				}
			});
			if (run) {
				run.innerHTML =
					'<p class="radius-mw-error">' +
					esc((i18n.errorPrefix || 'Error') + ': ' + String(err)) +
					'</p>';
			}
			if (start) {
				start.disabled = false;
			}
		}
	}

	async function maybeOpen() {
		if (!cfg.wizardNonce || !cfg.ajaxurl) {
			return;
		}
		var st = await postWizard('status');
		if (!st.success || !st.data) {
			return;
		}
		var p = st.data;
		if (!p.wizard_available && !p.offer) {
			return;
		}
		if (p.show_auto_modal || p.show_modal || cfg.openOnLoad) {
			ensureModal(p);
			if (cfg.openOnLoad) {
				try {
					window.history.replaceState(
						{},
						'',
						removeQueryParam(window.location.href, 'radius_open_migration')
					);
				} catch (e) {
					/* ignore */
				}
			}
		}
	}

	function removeQueryParam(url, key) {
		try {
			var u = new URL(url, window.location.origin);
			u.searchParams.delete(key);
			return u.pathname + u.search + u.hash;
		} catch (e) {
			return url;
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		maybeOpen().then(function () {
			if (overlayEl || !cfg.wizardNonce || !cfg.openOnLoad) {
				return;
			}
			return postWizard('status').then(function (st) {
				if (overlayEl) {
					return;
				}
				var p = st.success && st.data ? st.data : {};
				if (!p.wizard_available && !p.offer) {
					return;
				}
				ensureModal(p);
				try {
					window.history.replaceState(
						{},
						'',
						removeQueryParam(window.location.href, 'radius_open_migration')
					);
				} catch (e) {
					/* ignore */
				}
			});
		});

		window.addEventListener('radiusLegacyImportComplete', function (ev) {
			var d = ev.detail;
			if (!d || !d.success || !cfg.wizardNonce) {
				return;
			}
			postWizard('status').then(function (st) {
				if (
					st.success &&
					st.data &&
					st.data.steps &&
					st.data.steps.places &&
					st.data.steps.places.done
				) {
					postWizard('step_complete', { step: 'places' });
				}
			});
		});

		var full = document.getElementById('radius-migration-run-full');
		if (full) {
			full.addEventListener('click', function () {
				if (typeof window.radiusLegacyImportRunAll !== 'function') {
					return;
				}
				window.radiusLegacyImportRunAll();
			});
		}
		var tplBtn = document.getElementById('radius-migration-import-templates-only');
		if (tplBtn) {
			tplBtn.addEventListener('click', function () {
				tplBtn.disabled = true;
				postMigration('radius_migration_import_templates').finally(function () {
					tplBtn.disabled = false;
				});
			});
		}
		var cloneBtn = document.getElementById('radius-migration-clone-only');
		if (cloneBtn) {
			cloneBtn.addEventListener('click', function () {
				var sel = document.getElementById('radius-migration-base-template');
				var baseId = sel && sel.value ? String(sel.value) : '';
				if (!baseId) {
					return;
				}
				cloneBtn.disabled = true;
				postMigration('radius_migration_clone_variants', {
					base_id: baseId,
				}).finally(function () {
					cloneBtn.disabled = false;
				});
			});
		}
	});

	// Allow external code (e.g. the "Rerun Migration" button on the deploy page) to
	// open the wizard after a state reset without a full page reload.
	window.addEventListener('radiusOpenMigrationWizard', function () {
		if (!cfg.wizardNonce || !cfg.ajaxurl) {
			return;
		}
		postWizard('status').then(function (st) {
			if (st.success && st.data) {
				ensureModal(st.data);
			}
		});
	});
})();
