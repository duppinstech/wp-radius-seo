/**
 * Magic Page → Radius migration: modal tour (all Radius admin screens when eligible).
 */
(function () {
	'use strict';

	var cfg =
		typeof window.radiusMigrationWizard === 'object'
			? window.radiusMigrationWizard
			: {};
	var i18n = cfg.i18n || {};

	var rootEl = null;
	var overlayEl = null;
	var runStep = 0;
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
			return res.json();
		});
	}

	function applyPrefilledSteps(steps) {
		if (!steps) {
			return;
		}
		['places', 'templates', 'replacers', 'anchors'].forEach(function (key) {
			var s = steps[key];
			if (s && s.done) {
				setStepState('mw-step-' + key, 'done');
				if (key === 'places') {
					setPlacesProgress(100);
				}
			}
		});
	}

	function ensureModal(payload) {
		if (rootEl) {
			if (payload && payload.steps) {
				applyPrefilledSteps(payload.steps);
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
		var steps = el('ol', 'radius-mw-steps', '');
		steps.appendChild(
			createStepRow('mw-step-places', i18n.stepPlaces, 'bar')
		);
		steps.appendChild(
			createStepRow('mw-step-templates', i18n.stepTemplates, 'spin')
		);
		steps.appendChild(
			createStepRow('mw-step-replacers', i18n.stepReplacers, 'spin')
		);
		steps.appendChild(
			createStepRow('mw-step-anchors', i18n.stepAnchors, 'spin')
		);

		var run = el('div', 'radius-mw-run', '');
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
		panel.appendChild(steps);
		panel.appendChild(run);
		panel.appendChild(summary);
		panel.appendChild(foot);
		overlayEl.appendChild(panel);
		document.body.appendChild(overlayEl);

		start.addEventListener('click', onStart);
		dismiss.addEventListener('click', onDismiss);
		rootEl = panel;
		if (payload && payload.steps) {
			applyPrefilledSteps(payload.steps);
		}
	}

	function createStepRow(id, label, mode) {
		var li = el('li', 'radius-mw-step', '');
		li.id = id;
		var lab = el('span', 'radius-mw-step-label', label);
		li.appendChild(lab);
		if (mode === 'bar') {
			var bar = el('progress', 'radius-mw-step-progress', '');
			bar.max = 100;
			bar.value = 0;
			li.appendChild(bar);
		}
		var st = el('span', 'radius-mw-step-state', '');
		st.setAttribute('data-state', mode === 'bar' ? '' : 'idle');
		li.appendChild(st);
		return li;
	}

	function setStepState(id, state) {
		var row = document.getElementById(id);
		if (!row) {
			return;
		}
		var st = row.querySelector('.radius-mw-step-state');
		if (st) {
			st.setAttribute('data-state', state);
		}
		if (state === 'done') {
			row.setAttribute('data-done', '1');
		}
	}

	function setPlacesProgress(pct) {
		var row = document.getElementById('mw-step-places');
		if (!row) {
			return;
		}
		var bar = row.querySelector('progress');
		if (bar && typeof pct === 'number') {
			bar.value = Math.min(100, Math.max(0, Math.round(pct)));
		}
	}

	function showSummary(data) {
		var sum = document.getElementById('radius-mw-summary');
		if (!sum) {
			return;
		}
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
										? ' (' + placeStats.totalLegacy + ' legacy terms)'
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
				parts.push(
					'<li>' + esc(r.label || '') + '</li>'
				);
			});
			parts.push('</ul>');
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
		if (tpl && tpl.errors && tpl.errors.length) {
			parts.push(
				'<p class="radius-mw-warn">' +
					esc(tpl.errors.join(' ')) +
					'</p>'
			);
		}
		}
		sum.innerHTML =
			'<h2 class="radius-mw-summary-title">' +
			esc(i18n.deployCta || 'Ready') +
			'</h2>' +
			parts.join('') +
			'<p class="radius-mw-deploy-wrap"><a class="button button-primary button-hero" id="radius-mw-godeploy" href="#">' +
			esc(i18n.goDeploy || 'Open Deploy') +
			'</a></p>';
		sum.hidden = false;
		var go = document.getElementById('radius-mw-godeploy');
		if (go) {
			go.addEventListener('click', function (e) {
				e.preventDefault();
				postWizard('complete').finally(function () {
					window.location.href =
						cfg.deployPageUrl ||
						'admin.php?page=radius-deploy';
				});
			});
		}
		var intro = document.querySelector('.radius-mw-intro');
		if (intro) {
			intro.hidden = true;
		}
		var steps = document.querySelector('.radius-mw-steps');
		if (steps) {
			steps.hidden = true;
		}
		var run = document.querySelector('.radius-mw-run');
		if (run) {
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

	async function onStart() {
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
		}

		var stFresh = await postWizard('status');
		var steps =
			stFresh.success && stFresh.data && stFresh.data.steps
				? stFresh.data.steps
				: {};
		applyPrefilledSteps(steps);

		var allDone =
			steps.places &&
			steps.places.done &&
			steps.templates &&
			steps.templates.done &&
			steps.replacers &&
			steps.replacers.done &&
			steps.anchors &&
			steps.anchors.done;

		if (allDone) {
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
		window.radiusLegacyImportOnOverall = function (o) {
			if (o && typeof o.pct === 'number') {
				setPlacesProgress(o.pct);
			}
		};

		placeStats = null;
		var tpl = {};
		var jRepData = {};
		var jAncData = {};
		var skips = {
			places: false,
			templates: false,
			replacers: false,
			anchors: false,
		};

		try {
			if (!(steps.places && steps.places.done)) {
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
				setPlacesProgress(100);
				setStepState('mw-step-places', 'done');
				await postWizard('step_complete', { step: 'places' });
			} else {
				setPlacesProgress(100);
				setStepState('mw-step-places', 'done');
				placeStats = { success: true, skipped: true };
				skips.places = true;
			}

			stFresh = await postWizard('status');
			if (stFresh.success && stFresh.data && stFresh.data.steps) {
				steps = stFresh.data.steps;
			}

			if (!(steps.templates && steps.templates.done)) {
				setStepState('mw-step-templates', 'wait');
				var jTpl = await postWizard('templates_pipeline');
				if (!jTpl.success) {
					throw new Error(
						(jTpl.data && jTpl.data.message) ||
							i18n.requestFailed ||
							'Request failed'
					);
				}
				tpl = jTpl.data || {};
				setStepState('mw-step-templates', 'done');
			} else {
				setStepState('mw-step-templates', 'done');
				skips.templates = true;
			}

			stFresh = await postWizard('status');
			if (stFresh.success && stFresh.data && stFresh.data.steps) {
				steps = stFresh.data.steps;
			}

			if (!(steps.replacers && steps.replacers.done)) {
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
			} else {
				setStepState('mw-step-replacers', 'done');
				skips.replacers = true;
			}

			stFresh = await postWizard('status');
			if (stFresh.success && stFresh.data && stFresh.data.steps) {
				steps = stFresh.data.steps;
			}

			if (!(steps.anchors && steps.anchors.done)) {
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
			} else {
				setStepState('mw-step-anchors', 'done');
				skips.anchors = true;
			}

			showSummary({
				templates: tpl,
				replacers: jRepData,
				anchors: jAncData,
				skips: skips,
			});
		} catch (err) {
			if (run) {
				run.innerHTML =
					'<p class="radius-mw-error">' +
					esc((i18n.errorPrefix || 'Error') + ': ' + String(err)) +
					'</p>';
			}
			if (start) {
				start.disabled = false;
			}
		} finally {
			window.radiusLegacyImportOnOverall = null;
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
		if (!p.offer) {
			return;
		}
		if (p.show_modal || cfg.openOnLoad) {
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
			postWizard('step_complete', { step: 'places' });
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
				postMigration('radius_migration_import_templates')
					.finally(function () {
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
})();

