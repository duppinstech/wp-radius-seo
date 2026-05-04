/**
 * Magic Page → Radius migration wizard (Import → Magic Page migration tab).
 */
(function () {
	'use strict';

	var cfg =
		typeof window.radiusMigrationWizard === 'object'
			? window.radiusMigrationWizard
			: {};

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

	function logLine(el, msg) {
		if (!el) {
			return;
		}
		el.textContent += msg + '\n';
	}

	async function runImportTemplates(btn, logEl) {
		if (btn) {
			btn.disabled = true;
		}
		try {
			logLine(logEl, cfg.i18n.importingTemplates || 'Importing legacy templates…');
			var j = await postMigration('radius_migration_import_templates');
			if (!j.success) {
				throw new Error(
					(j.data && j.data.message) || cfg.i18n.requestFailed || 'Request failed'
				);
			}
			var d = j.data || {};
			logLine(
				logEl,
				(cfg.i18n.templatesResultFmt || 'Imported: {i}, skipped: {s}').replace(
					'{i}',
					String(d.imported != null ? d.imported : 0)
				).replace('{s}', String(d.skipped != null ? d.skipped : 0))
			);
			if (d.errors && d.errors.length) {
				logLine(logEl, d.errors.join(' '));
			}
		} catch (e) {
			logLine(logEl, (cfg.i18n.errorPrefix || 'Error:') + ' ' + String(e));
		}
		if (btn) {
			btn.disabled = false;
		}
	}

	async function runCloneVariants(btn, logEl) {
		var sel = document.getElementById('radius-migration-base-template');
		var baseId = sel && sel.value ? String(sel.value) : '';
		if (!baseId) {
			logLine(
				logEl,
				cfg.i18n.pickBase || 'Choose the towing blueprint template first.'
			);
			return;
		}
		if (btn) {
			btn.disabled = true;
		}
		try {
			logLine(logEl, cfg.i18n.cloningVariants || 'Creating variant drafts…');
			var j = await postMigration('radius_migration_clone_variants', {
				base_id: baseId,
			});
			if (!j.success) {
				throw new Error(
					(j.data && j.data.message) || cfg.i18n.requestFailed || 'Request failed'
				);
			}
			var d = j.data || {};
			if (d.created) {
				Object.keys(d.created).forEach(function (k) {
					logLine(logEl, k + ': ID ' + d.created[k]);
				});
			}
			if (d.errors && d.errors.length) {
				logLine(logEl, d.errors.join(' '));
			}
			logLine(logEl, cfg.i18n.cloneDone || 'Variant drafts ready.');
		} catch (e) {
			logLine(logEl, (cfg.i18n.errorPrefix || 'Error:') + ' ' + String(e));
		}
		if (btn) {
			btn.disabled = false;
		}
	}

	async function runFullMigration() {
		var logEl = document.getElementById('radius-migration-automation-log');
		var btn = document.getElementById('radius-migration-run-full');
		if (logEl) {
			logEl.textContent = '';
		}
		if (btn) {
			btn.disabled = true;
		}
		try {
			logLine(logEl, cfg.i18n.stepPlaces || 'Step 1 — Legacy locations…');
			if (typeof window.radiusLegacyImportRunAll === 'function') {
				await window.radiusLegacyImportRunAll();
			} else {
				throw new Error(
					cfg.i18n.legacyImportMissing ||
						'Legacy import script not loaded. Reload the page.'
				);
			}

			logLine(logEl, cfg.i18n.stepTemplates || 'Step 2 — Magic Page templates…');
			var j2 = await postMigration('radius_migration_import_templates');
			if (!j2.success) {
				throw new Error(
					(j2.data && j2.data.message) || cfg.i18n.requestFailed || 'Request failed'
				);
			}
			var d2 = j2.data || {};
			logLine(
				logEl,
				(cfg.i18n.templatesResultFmt || 'Imported: {i}, skipped: {s}').replace(
					'{i}',
					String(d2.imported != null ? d2.imported : 0)
				).replace('{s}', String(d2.skipped != null ? d2.skipped : 0))
			);

			var sel = document.getElementById('radius-migration-base-template');
			var baseId = sel && sel.value ? String(sel.value) : '';
			if (!baseId) {
				throw new Error(
					cfg.i18n.pickBase || 'Choose the towing blueprint template before running.'
				);
			}

			logLine(logEl, cfg.i18n.stepVariants || 'Step 3 — Roadside / heavy / equipment drafts…');
			var j3 = await postMigration('radius_migration_clone_variants', {
				base_id: baseId,
			});
			if (!j3.success) {
				throw new Error(
					(j3.data && j3.data.message) || cfg.i18n.requestFailed || 'Request failed'
				);
			}
			var d3 = j3.data || {};
			if (d3.created) {
				Object.keys(d3.created).forEach(function (k) {
					logLine(logEl, k + ': ID ' + d3.created[k]);
				});
			}
			if (d3.errors && d3.errors.length) {
				logLine(logEl, d3.errors.join(' '));
			}

			logLine(
				logEl,
				cfg.i18n.stepNext ||
					'Next: Spintax tab — import global spintax per template with prefix filters; then Deploy.'
			);
			logLine(logEl, cfg.i18n.done || 'Automated steps finished.');
		} catch (e) {
			logLine(logEl, (cfg.i18n.errorPrefix || 'Error:') + ' ' + String(e));
		}
		if (btn) {
			btn.disabled = false;
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var full = document.getElementById('radius-migration-run-full');
		if (full) {
			full.addEventListener('click', runFullMigration);
		}
		var tplBtn = document.getElementById('radius-migration-import-templates-only');
		if (tplBtn) {
			tplBtn.addEventListener('click', function () {
				runImportTemplates(
					tplBtn,
					document.getElementById('radius-migration-automation-log')
				);
			});
		}
		var cloneBtn = document.getElementById('radius-migration-clone-only');
		if (cloneBtn) {
			cloneBtn.addEventListener('click', function () {
				runCloneVariants(
					cloneBtn,
					document.getElementById('radius-migration-automation-log')
				);
			});
		}
	});
})();
