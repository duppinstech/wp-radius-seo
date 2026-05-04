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

	var STEP_KEYS = ['places', 'templates', 'replacers', 'anchors'];
	var STEP_IDS = {
		places: 'mw-step-places',
		templates: 'mw-step-templates',
		replacers: 'mw-step-replacers',
		anchors: 'mw-step-anchors',
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

	function rowIdForStep(key) {
		return STEP_IDS[key] || '';
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
				if (key === 'places') {
					setPlacesProgress(100);
				}
			} else {
				row.removeAttribute('data-done');
				if (key === 'places') {
					setPlacesProgress(0);
				}
			}
		});
		updateDeployBlock(steps);
	}

	function updateDeployBlock(steps) {
		var block = document.getElementById('radius-mw-deploy-block');
		if (!block) {
			return;
		}
		var all =
			steps &&
			STEP_KEYS.every(function (k) {
				return steps[k] && steps[k].done;
			});
		block.classList.toggle('radius-mw-deploy-block--locked', !all);
	}

	function applyPrefilledSteps(steps) {
		refreshStepRows(steps);
	}

	function createDeploySection(payload) {
		mergeCfgFromPayload(payload);
		var depUrl = cfg.deployPageUrl || 'admin.php?page=radius-deploy';
		var areasUrl = cfg.serviceAreasUrl || '#';
		var locUrl = cfg.locationsLibraryUrl || '#';

		var wrap = el('div', 'radius-mw-deploy-block radius-mw-deploy-block--locked');
		wrap.id = 'radius-mw-deploy-block';
		wrap.setAttribute('aria-disabled', 'true');

		var title = el(
			'p',
			'radius-mw-deploy-block-title',
			i18n.stepDeployTitle || ''
		);
		var help = el(
			'p',
			'description radius-mw-deploy-block-help',
			i18n.stepDeployHelp || ''
		);
		var actions = el('div', 'radius-mw-deploy-actions', '');

		var bDeploy = el(
			'button',
			'button button-primary radius-mw-deploy-primary',
			i18n.goDeploy || 'Open Deploy'
		);
		bDeploy.type = 'button';
		bDeploy.addEventListener('click', function (e) {
			e.preventDefault();
			window.location.href = depUrl;
		});

		var aLoc = el('a', 'button', i18n.locationLibrary || 'Location library');
		aLoc.href = locUrl;

		var aAreas = el('a', 'button', i18n.serviceAreasBtn || 'Service areas');
		aAreas.href = areasUrl;

		actions.appendChild(bDeploy);
		actions.appendChild(aLoc);
		actions.appendChild(aAreas);

		wrap.appendChild(title);
		wrap.appendChild(help);
		wrap.appendChild(actions);
		return wrap;
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
		var stepsOl = el('ol', 'radius-mw-steps radius-mw-steps--checklist', '');
		stepsOl.appendChild(
			createStepRow('mw-step-places', i18n.stepPlaces, 'bar', 'places')
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
			createStepRow(
				'mw-step-replacers',
				i18n.stepReplacers,
				'spin',
				'replacers'
			)
		);
		stepsOl.appendChild(
			createStepRow('mw-step-anchors', i18n.stepAnchors, 'spin', 'anchors')
		);

		var deploySec = createDeploySection(payload);

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
		panel.appendChild(stepsOl);
		panel.appendChild(deploySec);
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
		} else if (payload) {
			postWizard('status').then(function (st) {
				if (st.success && st.data && st.data.steps) {
					mergeCfgFromPayload(st.data);
					applyPrefilledSteps(st.data.steps);
				}
			});
		}
	}

	function createStepRow(id, label, mode, stepKey) {
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
		if (mode === 'bar') {
			var bar = el('progress', 'radius-mw-step-progress', '');
			bar.max = 100;
			bar.value = 0;
			main.appendChild(bar);
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
		var depUrl = cfg.deployPageUrl || 'admin.php?page=radius-deploy';
		var areasUrl = cfg.serviceAreasUrl || '#';
		var locUrl = cfg.locationsLibraryUrl || '#';

		var deployBlock = document.getElementById('radius-mw-deploy-block');
		if (deployBlock) {
			deployBlock.hidden = true;
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
			'<p class="radius-mw-deploy-actions radius-mw-deploy-actions--summary">' +
			'<a class="button button-primary" href="' +
			escAttr(depUrl) +
			'">' +
			esc(i18n.goDeploy || 'Open Deploy') +
			'</a> ' +
			'<a class="button" href="' +
			escAttr(locUrl) +
			'">' +
			esc(i18n.locationLibrary || 'Location library') +
			'</a> ' +
			'<a class="button" href="' +
			escAttr(areasUrl) +
			'">' +
			esc(i18n.serviceAreasBtn || 'Service areas') +
			'</a>' +
			'</p>';
		sum.hidden = false;
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

	async function onStart() {
		var userWants = {};
		STEP_KEYS.forEach(function (k) {
			userWants[k] = wantsRun(k);
		});
		var ran = {
			places: false,
			templates: false,
			replacers: false,
			anchors: false,
		};

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

		var resetPromises = [];
		STEP_KEYS.forEach(function (k) {
			if (userWants[k] && steps[k] && steps[k].recorded) {
				resetPromises.push(postWizard('step_reset', { step: k }));
			}
		});
		if (resetPromises.length) {
			await Promise.all(resetPromises);
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
				setPlacesProgress(100);
				setStepState('mw-step-places', 'done');
				await postWizard('step_complete', { step: 'places' });
			} else {
				if (steps.places && steps.places.done) {
					setPlacesProgress(100);
					setStepState('mw-step-places', 'done');
				} else {
					setPlacesProgress(0);
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
				tpl = jTpl.data || {};
				setStepState('mw-step-templates', 'done');
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
})();
