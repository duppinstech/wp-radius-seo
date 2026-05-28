/**
 * Settings → Database: Magic Page wp_options table (select, bulk, per-row).
 */
(function () {
	'use strict';

	var cfg = window.radiusMagicPageOptions;
	if (!cfg || !cfg.ajaxurl) {
		return;
	}

	var table = document.getElementById('radius-magic-page-cleanup');
	if (!table) {
		return;
	}

	var tbody = table.querySelector('tbody');
	var selectAll = document.getElementById('radius-mp-select-all');
	var selectionBar = document.getElementById('radius-mp-selection-bar');
	var selectionCount = document.getElementById('radius-mp-selection-count');
	var lastCheckedIndex = -1;

	function post(action, payload) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce);
		Object.keys(payload).forEach(function (key) {
			body.set(key, payload[key]);
		});
		return fetch(cfg.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		}).then(function (res) {
			return res.json().then(function (json) {
				return { ok: res.ok, json: json };
			});
		});
	}

	function rowChecks() {
		return Array.prototype.slice.call(table.querySelectorAll('.radius-mp-row-check'));
	}

	function selectedNames() {
		return rowChecks()
			.filter(function (cb) {
				return cb.checked;
			})
			.map(function (cb) {
				return cb.value;
			});
	}

	function updateSelectionUi() {
		var names = selectedNames();
		var n = names.length;
		if (selectionBar) {
			selectionBar.hidden = n === 0;
		}
		if (selectionCount) {
			selectionCount.textContent = String(n);
		}
		if (selectAll) {
			var checks = rowChecks();
			selectAll.checked = checks.length > 0 && n === checks.length;
			selectAll.indeterminate = n > 0 && n < checks.length;
		}
	}

	function setBadge(cell, label, tone) {
		var badge = cell.querySelector('.radius-mp-autoload');
		if (!badge) {
			badge = document.createElement('span');
			badge.className = 'radius-mp-autoload';
			cell.appendChild(badge);
		}
		badge.className = 'radius-mp-autoload radius-mp-autoload--' + tone;
		badge.setAttribute('data-autoload-tone', tone);
		badge.textContent = label;
	}

	function clearUnautoloadAction(actionsCell) {
		var link = actionsCell.querySelector('.radius-mp-unautoload');
		if (link) {
			link.remove();
		}
		var sep = actionsCell.querySelector('.radius-mp-action-sep');
		if (sep) {
			sep.remove();
		}
	}

	function applyUnautoloadToRow(row, data) {
		var autoloadCell = row.querySelector('.radius-mp-col-autoload');
		var actionsCell = row.querySelector('.radius-mp-col-actions');
		if (autoloadCell) {
			setBadge(
				autoloadCell,
				(data && data.autoload_label) || cfg.i18n.autoloadNo,
				(data && data.autoload_tone) || 'success'
			);
		}
		if (actionsCell) {
			clearUnautoloadAction(actionsCell);
		}
	}

	function removeRow(row) {
		var cb = row.querySelector('.radius-mp-row-check');
		if (cb) {
			cb.checked = false;
		}
		row.remove();
		updateSelectionUi();
		if (!tbody.querySelector('tr')) {
			table.remove();
			if (selectionBar) {
				selectionBar.hidden = true;
			}
		}
	}

	function runSingle(ajaxAction, optionName, btn) {
		var label = btn.classList.contains('radius-mp-delete') ? cfg.i18n.delete : cfg.i18n.unautoload;
		btn.disabled = true;
		btn.textContent = cfg.i18n.working;

		return post(ajaxAction, { option_name: optionName }).then(function (result) {
			var row = btn.closest('tr.radius-mp-option-row');
			if (!result.ok || !result.json || !result.json.success) {
				var msg =
					result.json && result.json.data && result.json.data.message
						? result.json.data.message
						: cfg.i18n.failed;
				window.alert(msg);
				btn.disabled = false;
				btn.textContent = label;
				return;
			}
			if (ajaxAction === 'radius_magic_page_option_delete') {
				if (row) {
					removeRow(row);
				}
				return;
			}
			if (row) {
				applyUnautoloadToRow(row, result.json.data || {});
			}
			btn.disabled = false;
			btn.textContent = label;
		});
	}

	function runBulk(mode) {
		var names = selectedNames();
		if (!names.length) {
			window.alert(cfg.i18n.selectRows);
			return;
		}
		if (mode === 'delete' && !window.confirm(cfg.i18n.deleteBulkConfirm)) {
			return;
		}

		var barBtn =
			mode === 'delete'
				? selectionBar && selectionBar.querySelector('.radius-mp-bulk-delete')
				: selectionBar && selectionBar.querySelector('.radius-mp-bulk-unautoload');
		if (barBtn) {
			barBtn.disabled = true;
		}

		return post('radius_magic_page_options_bulk', {
			bulk_mode: mode,
			option_names: JSON.stringify(names),
		})
			.then(function (result) {
				if (!result.ok || !result.json || !result.json.success) {
					var msg =
						result.json && result.json.data && result.json.data.message
							? result.json.data.message
							: cfg.i18n.failed;
					window.alert(msg);
					return;
				}
				names.forEach(function (name) {
					var row = tbody.querySelector('tr[data-option-name="' + CSS.escape(name) + '"]');
					if (!row) {
						return;
					}
					if (mode === 'delete') {
						removeRow(row);
					} else {
						applyUnautoloadToRow(row, { autoload_label: cfg.i18n.autoloadNo, autoload_tone: 'success' });
						var cb = row.querySelector('.radius-mp-row-check');
						if (cb) {
							cb.checked = false;
						}
					}
				});
				updateSelectionUi();
			})
			.catch(function () {
				window.alert(cfg.i18n.failed);
			})
			.finally(function () {
				if (barBtn) {
					barBtn.disabled = false;
				}
			});
	}

	if (selectAll) {
		selectAll.addEventListener('change', function () {
			var on = selectAll.checked;
			rowChecks().forEach(function (cb) {
				cb.checked = on;
			});
			updateSelectionUi();
		});
	}

	if (tbody) {
		tbody.addEventListener('click', function (ev) {
			var cb = ev.target.closest('.radius-mp-row-check');
			if (!cb || !tbody.contains(cb)) {
				return;
			}
			var checks = rowChecks();
			var index = checks.indexOf(cb);
			if (index < 0) {
				return;
			}
			if (ev.shiftKey && lastCheckedIndex >= 0 && lastCheckedIndex !== index) {
				var start = Math.min(lastCheckedIndex, index);
				var end = Math.max(lastCheckedIndex, index);
				var state = cb.checked;
				for (var i = start; i <= end; i++) {
					checks[i].checked = state;
				}
			}
			lastCheckedIndex = index;
			updateSelectionUi();
		});
	}

	if (selectionBar) {
		var bulkUn = selectionBar.querySelector('.radius-mp-bulk-unautoload');
		var bulkDel = selectionBar.querySelector('.radius-mp-bulk-delete');
		if (bulkUn) {
			bulkUn.addEventListener('click', function () {
				runBulk('unautoload');
			});
		}
		if (bulkDel) {
			bulkDel.addEventListener('click', function () {
				runBulk('delete');
			});
		}
	}

	table.addEventListener('click', function (ev) {
		var btn = ev.target.closest('.radius-mp-unautoload, .radius-mp-delete');
		if (!btn || btn.disabled) {
			return;
		}
		ev.preventDefault();

		var name = btn.getAttribute('data-option-name') || '';
		if (!name) {
			return;
		}

		if (btn.classList.contains('radius-mp-delete') && !window.confirm(cfg.i18n.deleteConfirm)) {
			return;
		}

		var ajaxAction = btn.classList.contains('radius-mp-delete')
			? 'radius_magic_page_option_delete'
			: 'radius_magic_page_option_unautoload';

		runSingle(ajaxAction, name, btn).catch(function () {
			window.alert(cfg.i18n.failed);
			btn.disabled = false;
			btn.textContent = btn.classList.contains('radius-mp-delete') ? cfg.i18n.delete : cfg.i18n.unautoload;
		});
	});
})();
