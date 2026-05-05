/**
 * Settings → Site replacers: global token keys, default values, per–service-area overrides.
 */
(function () {
	'use strict';

	var cfg = typeof window.radiusSiteReplacementsCfg === 'object' ? window.radiusSiteReplacementsCfg : {};
	var i18n = cfg.i18n || {};

	function areaCodesList() {
		return Array.isArray(cfg.serviceAreaCodes) ? cfg.serviceAreaCodes : [];
	}

	var state = { rows: [] };
	var editing = -1;

	function normRow(r) {
		r = r || {};
		var vals = Array.isArray(r.values) && r.values.length ? r.values.slice() : [];
		if (r.value !== undefined && r.value !== null && vals.length === 0) {
			vals = [String(r.value)];
		}
		if (vals.length === 0) {
			vals = [''];
		}
		var aos = [];
		var src = Array.isArray(r.area_overrides)
			? r.area_overrides
			: Array.isArray(r.set_overrides)
				? r.set_overrides
				: [];
		src.forEach(function (o) {
			if (!o) {
				return;
			}
			var ac = (o.area || o.set || '').toString().trim();
			if (ac === '') {
				return;
			}
			aos.push({ area: ac, value: o.value != null ? String(o.value) : '' });
		});
		return { key: r.key || '', values: vals, area_overrides: aos };
	}

	function summary(row) {
		var vals = row.values && row.values.length ? row.values : [''];
		var n = vals.length;
		var label =
			n === 1
				? i18n.oneVal || '1 value'
				: (i18n.nVals || '%d values').replace('%d', String(n));
		var nao = row.area_overrides && row.area_overrides.length ? row.area_overrides.length : 0;
		if (nao > 0) {
			var sf = (i18n.areaOverridesSummary || '%d area overrides').replace('%d', String(nao));
			label += ' · ' + sf;
		}
		var t = (vals[0] || '').replace(/\s+/g, ' ').trim();
		if (t.length > 72) {
			t = t.slice(0, 72) + '…';
		}
		if (t) {
			return label + ' — ' + t;
		}
		return label;
	}

	function syncHidden() {
		var el = document.getElementById('radius_site_replacements_json');
		if (el) {
			el.value = JSON.stringify({ rows: state.rows });
		}
	}

	function renderRows() {
		var tb = document.querySelector('#radius-site-replacements tbody');
		if (!tb) {
			return;
		}
		tb.innerHTML = '';
		state.rows.forEach(function (row, idx) {
			var tr = document.createElement('tr');
			tr.setAttribute('data-index', String(idx));
			tr.innerHTML =
				'<td class="radius-tpl-col-key"><input type="text" class="regular-text radius-site-repl-key" value="" /></td>' +
				'<td class="radius-site-repl-summary radius-tpl-col-val"></td>' +
				'<td class="radius-tpl-col-actions"><button type="button" class="button radius-site-repl-edit"></button> ' +
				'<button type="button" class="button radius-site-repl-remove"></button></td>';
			tr.querySelector('.radius-site-repl-key').value = row.key || '';
			tr.querySelector('.radius-site-repl-summary').textContent = summary(row);
			tr.querySelector('.radius-site-repl-edit').textContent = i18n.editValues || 'Edit values';
			tr.querySelector('.radius-site-repl-remove').textContent = i18n.remove || 'Remove';
			tb.appendChild(tr);
		});
		syncHidden();
	}

	function appendAreaOverrideRow(container, o) {
		o = o || { area: '', value: '' };
		var wrap = document.createElement('div');
		wrap.className = 'radius-site-repl-ao-row';
		wrap.style.marginBottom = '14px';
		var labArea = document.createElement('label');
		labArea.style.display = 'block';
		labArea.style.marginBottom = '4px';
		labArea.style.fontWeight = '600';
		labArea.textContent = i18n.areaColumn || 'Service area code';
		var sel = document.createElement('select');
		sel.className = 'radius-site-repl-ao-area widefat';
		var opt0 = document.createElement('option');
		opt0.value = '';
		opt0.textContent = i18n.areaSelectPlaceholder || '— Choose area code —';
		sel.appendChild(opt0);
		var seen = {};
		areaCodesList().forEach(function (ac) {
			if (!ac || !ac.code) {
				return;
			}
			var c = String(ac.code);
			seen[c] = true;
			var oopt = document.createElement('option');
			oopt.value = c;
			oopt.textContent = ac.label ? c + ' — ' + ac.label : c;
			if ((o.area || o.set || '') === c) {
				oopt.selected = true;
			}
			sel.appendChild(oopt);
		});
		var pick = (o.area || o.set || '').trim();
		if (pick && !seen[pick]) {
			var ox = document.createElement('option');
			ox.value = pick;
			ox.textContent = pick;
			ox.selected = true;
			sel.appendChild(ox);
		}
		var labVal = document.createElement('label');
		labVal.style.display = 'block';
		labVal.style.marginTop = '8px';
		labVal.style.marginBottom = '4px';
		labVal.style.fontWeight = '600';
		labVal.textContent = i18n.customValueColumn || 'Custom value';
		var ta = document.createElement('textarea');
		ta.className = 'widefat code radius-site-repl-ao-value';
		ta.rows = 4;
		ta.value = o.value || '';
		var rm = document.createElement('p');
		rm.style.marginTop = '6px';
		var rmBtn = document.createElement('button');
		rmBtn.type = 'button';
		rmBtn.className = 'button-link radius-site-repl-ao-remove';
		rmBtn.textContent = i18n.removeAreaOverride || 'Remove';
		rm.appendChild(rmBtn);
		wrap.appendChild(labArea);
		wrap.appendChild(sel);
		wrap.appendChild(labVal);
		wrap.appendChild(ta);
		wrap.appendChild(rm);
		container.appendChild(wrap);
	}

	function readModal() {
		if (editing < 0) {
			return;
		}
		var body = document.getElementById('radius-site-repl-modal-body');
		if (!body) {
			return;
		}
		var inputs = body.querySelectorAll('textarea.radius-site-repl-value-input');
		var vals = [];
		inputs.forEach(function (ta) {
			vals.push(ta.value);
		});
		if (vals.length === 0) {
			vals.push('');
		}
		state.rows[editing].values = vals;

		var aow = document.getElementById('radius-site-repl-area-overrides');
		var aos = [];
		if (aow) {
			aow.querySelectorAll('.radius-site-repl-ao-row').forEach(function (rw) {
				var s = rw.querySelector('.radius-site-repl-ao-area');
				var t = rw.querySelector('.radius-site-repl-ao-value');
				if (!s || !t) {
					return;
				}
				var c = (s.value || '').trim();
				if (c === '') {
					return;
				}
				aos.push({ area: c, value: t.value || '' });
			});
		}
		state.rows[editing].area_overrides = aos;
	}

	function closeModal() {
		var modal = document.getElementById('radius-site-repl-modal');
		if (modal) {
			modal.style.display = 'none';
		}
		document.body.style.overflow = '';
		editing = -1;
	}

	function saveModal() {
		readModal();
		var tr = document.querySelector('#radius-site-replacements tbody tr[data-index="' + editing + '"]');
		if (tr) {
			var sum = tr.querySelector('.radius-site-repl-summary');
			if (sum) {
				sum.textContent = summary(state.rows[editing]);
			}
		}
		syncHidden();
		closeModal();
	}

	function openModal(idx) {
		editing = idx;
		var row = state.rows[idx];
		if (!row) {
			return;
		}
		var modal = document.getElementById('radius-site-repl-modal');
		var title = document.getElementById('radius-site-repl-modal-title');
		var body = document.getElementById('radius-site-repl-modal-body');
		if (!modal || !title || !body) {
			return;
		}
		title.textContent = (i18n.modalTitle || 'Values for') + ' "' + (row.key || '') + '"';
		body.innerHTML = '';
		var vals = row.values && row.values.length ? row.values.slice() : [''];
		vals.forEach(function (val, vi) {
			var wrap = document.createElement('div');
			wrap.className = 'radius-site-repl-modal-row';
			wrap.style.marginBottom = '14px';
			var lab = document.createElement('label');
			lab.style.display = 'block';
			lab.style.marginBottom = '4px';
			lab.style.fontWeight = '600';
			lab.textContent = (i18n.valueLabel || 'Value') + ' ' + (vi + 1);
			var ta = document.createElement('textarea');
			ta.className = 'widefat radius-site-repl-value-input';
			ta.rows = 3;
			ta.value = val;
			var rm = document.createElement('p');
			rm.style.marginTop = '6px';
			var rmBtn = document.createElement('button');
			rmBtn.type = 'button';
			rmBtn.className = 'button-link radius-site-repl-remove-value';
			rmBtn.textContent = i18n.removeValue || 'Remove value';
			rm.appendChild(rmBtn);
			wrap.appendChild(lab);
			wrap.appendChild(ta);
			wrap.appendChild(rm);
			body.appendChild(wrap);
		});
		var addP = document.createElement('p');
		var addBtn = document.createElement('button');
		addBtn.type = 'button';
		addBtn.className = 'button';
		addBtn.id = 'radius-site-repl-add-value';
		addBtn.textContent = i18n.addValue || 'Add value';
		addP.appendChild(addBtn);
		body.appendChild(addP);

		var hr = document.createElement('hr');
		hr.style.margin = '20px 0';
		body.appendChild(hr);
		var h4 = document.createElement('h4');
		h4.style.marginTop = '0';
		h4.textContent = i18n.areaOverridesTitle || 'Per–service-area values';
		body.appendChild(h4);
		var help = document.createElement('p');
		help.className = 'description';
		help.textContent = i18n.areaOverridesHelp || '';
		body.appendChild(help);
		if (areaCodesList().length === 0) {
			var emptyAc = document.createElement('p');
			emptyAc.className = 'description';
			emptyAc.textContent = i18n.noServiceAreas || '';
			body.appendChild(emptyAc);
		}
		var aoWrap = document.createElement('div');
		aoWrap.id = 'radius-site-repl-area-overrides';
		body.appendChild(aoWrap);
		var ainit = Array.isArray(row.area_overrides) ? row.area_overrides : [];
		if (ainit.length === 0) {
			appendAreaOverrideRow(aoWrap, { area: '', value: '' });
		} else {
			ainit.forEach(function (o) {
				appendAreaOverrideRow(aoWrap, o);
			});
		}
		var addAoP = document.createElement('p');
		var addAoBtn = document.createElement('button');
		addAoBtn.type = 'button';
		addAoBtn.className = 'button radius-site-repl-add-area-override';
		addAoBtn.textContent = i18n.addAreaOverride || 'Add area override';
		addAoP.appendChild(addAoBtn);
		body.appendChild(addAoP);

		modal.style.display = 'flex';
		document.body.style.overflow = 'hidden';
	}

	function bindModal() {
		var modal = document.getElementById('radius-site-repl-modal');
		if (!modal) {
			return;
		}
		var saveBtn = document.getElementById('radius-site-repl-modal-save');
		var cancelBtn = document.getElementById('radius-site-repl-modal-cancel');
		var dismissBtns = modal.querySelectorAll('.radius-site-repl-modal-dismiss');
		if (saveBtn) {
			saveBtn.addEventListener('click', function (e) {
				e.preventDefault();
				saveModal();
			});
		}
		if (cancelBtn) {
			cancelBtn.addEventListener('click', function (e) {
				e.preventDefault();
				closeModal();
			});
		}
		dismissBtns.forEach(function (b) {
			b.addEventListener('click', function (e) {
				e.preventDefault();
				closeModal();
			});
		});
		modal.addEventListener('click', function (e) {
			var t = e.target;
			if (t && t.classList && t.classList.contains('radius-spintax-modal__backdrop')) {
				closeModal();
			}
		});
		modal.addEventListener('click', function (e) {
			var t = e.target;
			if (!t) {
				return;
			}
			if (t.id === 'radius-site-repl-add-value') {
				e.preventDefault();
				var body = document.getElementById('radius-site-repl-modal-body');
				if (!body) {
					return;
				}
				var addAnchor = document.getElementById('radius-site-repl-add-value');
				var n = body.querySelectorAll('textarea.radius-site-repl-value-input').length;
				var wrap = document.createElement('div');
				wrap.className = 'radius-site-repl-modal-row';
				wrap.style.marginBottom = '14px';
				var lab = document.createElement('label');
				lab.style.display = 'block';
				lab.style.marginBottom = '4px';
				lab.style.fontWeight = '600';
				lab.textContent = (i18n.valueLabel || 'Value') + ' ' + (n + 1);
				var ta = document.createElement('textarea');
				ta.className = 'widefat radius-site-repl-value-input';
				ta.rows = 3;
				ta.value = '';
				var rm = document.createElement('p');
				rm.style.marginTop = '6px';
				var rmBtn = document.createElement('button');
				rmBtn.type = 'button';
				rmBtn.className = 'button-link radius-site-repl-remove-value';
				rmBtn.textContent = i18n.removeValue || 'Remove value';
				rm.appendChild(rmBtn);
				wrap.appendChild(lab);
				wrap.appendChild(ta);
				wrap.appendChild(rm);
				if (addAnchor && addAnchor.parentNode) {
					addAnchor.parentNode.insertBefore(wrap, addAnchor);
				} else {
					body.appendChild(wrap);
				}
			}
			if (t.classList && t.classList.contains('radius-site-repl-remove-value')) {
				e.preventDefault();
				var row = t.closest('.radius-site-repl-modal-row');
				var body2 = document.getElementById('radius-site-repl-modal-body');
				if (!row || !body2) {
					return;
				}
				var all = body2.querySelectorAll('textarea.radius-site-repl-value-input');
				if (all.length <= 1) {
					return;
				}
				row.remove();
				body2.querySelectorAll('.radius-site-repl-modal-row label').forEach(function (labEl, i) {
					labEl.textContent = (i18n.valueLabel || 'Value') + ' ' + (i + 1);
				});
			}
			if (t.classList && t.classList.contains('radius-site-repl-add-area-override')) {
				e.preventDefault();
				var aow = document.getElementById('radius-site-repl-area-overrides');
				if (aow) {
					appendAreaOverrideRow(aow, { area: '', value: '' });
				}
			}
			if (t.classList && t.classList.contains('radius-site-repl-ao-remove')) {
				e.preventDefault();
				var srow = t.closest('.radius-site-repl-ao-row');
				var aow2 = document.getElementById('radius-site-repl-area-overrides');
				if (!srow || !aow2) {
					return;
				}
				if (aow2.querySelectorAll('.radius-site-repl-ao-row').length <= 1) {
					var sel = srow.querySelector('.radius-site-repl-ao-area');
					var ta2 = srow.querySelector('.radius-site-repl-ao-value');
					if (sel) {
						sel.value = '';
					}
					if (ta2) {
						ta2.value = '';
					}
					return;
				}
				srow.remove();
			}
		});
	}

	function init() {
		if (!document.getElementById('radius-site-replacements')) {
			return;
		}
		var rowsIn = cfg.initial && Array.isArray(cfg.initial.rows) ? cfg.initial.rows : [];
		state.rows = rowsIn.map(normRow);
		renderRows();
		bindModal();

		var form = document.querySelector('form.radius-settings-form');
		if (form) {
			form.addEventListener('submit', function () {
				document.querySelectorAll('#radius-site-replacements .radius-site-repl-key').forEach(function (inp, i) {
					if (state.rows[i]) {
						state.rows[i].key = inp.value;
					}
				});
				syncHidden();
			});
		}

		var addBtn = document.getElementById('radius-site-replacements-add');
		if (addBtn) {
			addBtn.addEventListener('click', function () {
				state.rows.push({ key: '', values: [''], area_overrides: [] });
				renderRows();
			});
		}

		var tbl = document.getElementById('radius-site-replacements');
		if (tbl) {
			tbl.addEventListener('click', function (e) {
				var t = e.target;
				if (!t || !t.classList) {
					return;
				}
				if (t.classList.contains('radius-site-repl-edit')) {
					var tr = t.closest('tr');
					var idx = tr ? parseInt(tr.getAttribute('data-index'), 10) : -1;
					if (!isNaN(idx) && idx >= 0) {
						openModal(idx);
					}
				}
				if (t.classList.contains('radius-site-repl-remove')) {
					var tr2 = t.closest('tr');
					var idx2 = tr2 ? parseInt(tr2.getAttribute('data-index'), 10) : -1;
					if (!isNaN(idx2) && idx2 >= 0) {
						state.rows.splice(idx2, 1);
						renderRows();
					}
				}
			});
			tbl.addEventListener('input', function (e) {
				if (e.target && e.target.classList.contains('radius-site-repl-key')) {
					var tr = e.target.closest('tr');
					var idx = tr ? parseInt(tr.getAttribute('data-index'), 10) : -1;
					if (!isNaN(idx) && state.rows[idx]) {
						state.rows[idx].key = e.target.value;
						syncHidden();
					}
				}
			});
		}
	}

	document.addEventListener('DOMContentLoaded', init);
})();
