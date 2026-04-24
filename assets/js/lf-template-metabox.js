/**
 * Template screen: Spintax blocks (table + modal).
 */
(function () {
	'use strict';

	var mb = typeof window.lfTemplateMetaboxCfg === 'object' ? window.lfTemplateMetaboxCfg : {};
	var spCfg = mb.spintax || {};

	/* ---------- helpers ---------- */
	function stripTags(html) {
		var d = document.createElement('div');
		d.innerHTML = html;
		return d.textContent || d.innerText || '';
	}

	/* ---------- Spintax ---------- */
	var spState = { blocks: [] };
	var spEditing = -1;

	function spSummary(block) {
		var vars = block.variations && block.variations.length ? block.variations : [''];
		var n = vars.length;
		var label =
			n === 1
				? spCfg.i18n && spCfg.i18n.oneVar
					? spCfg.i18n.oneVar
					: '1 variation'
				: spCfg.i18n && spCfg.i18n.nVars
					? spCfg.i18n.nVars.replace('%d', String(n))
					: n + ' variations';
		var t = stripTags(vars[0] || '')
			.replace(/\s+/g, ' ')
			.trim();
		if (t.length > 72) {
			t = t.slice(0, 72) + '…';
		}
		if (t) {
			return label + ' — ' + t;
		}
		return label;
	}

	function spSyncHidden() {
		var el = document.getElementById('lf_spintax_blocks_json');
		if (el) {
			el.value = JSON.stringify({ blocks: spState.blocks });
		}
	}

	function spRenderRows() {
		var tb = document.querySelector('#lf-blocks tbody');
		if (!tb) {
			return;
		}
		tb.innerHTML = '';
		spState.blocks.forEach(function (block, idx) {
			var tr = document.createElement('tr');
			tr.setAttribute('data-index', String(idx));
			tr.innerHTML =
				'<td class="radius-tpl-col-key"><input type="text" class="regular-text lf-spintax-key" value="" /></td>' +
				'<td class="lf-spintax-summary radius-tpl-col-val"></td>' +
				'<td class="radius-tpl-col-actions"><button type="button" class="button lf-spintax-edit"></button> ' +
				'<button type="button" class="button lf-spintax-remove-block"></button></td>';
			tr.querySelector('.lf-spintax-key').value = block.key || '';
			tr.querySelector('.lf-spintax-summary').textContent = spSummary(block);
			tr.querySelector('.lf-spintax-edit').textContent =
				spCfg.i18n && spCfg.i18n.editVariations ? spCfg.i18n.editVariations : 'Edit variations';
			tr.querySelector('.lf-spintax-remove-block').textContent =
				spCfg.i18n && spCfg.i18n.remove ? spCfg.i18n.remove : 'Remove';
			tb.appendChild(tr);
		});
		spSyncHidden();
	}

	function spReadModal() {
		if (spEditing < 0) {
			return;
		}
		var body = document.getElementById('lf-spintax-modal-body');
		if (!body) {
			return;
		}
		var textareas = body.querySelectorAll('textarea');
		var vars = [];
		textareas.forEach(function (ta) {
			vars.push(ta.value);
		});
		if (vars.length === 0) {
			vars.push('');
		}
		spState.blocks[spEditing].variations = vars;
	}

	function spCloseModal() {
		var modal = document.getElementById('lf-spintax-modal');
		if (modal) {
			modal.style.display = 'none';
		}
		document.body.style.overflow = '';
		spEditing = -1;
	}

	function spSaveModal() {
		spReadModal();
		var tr = document.querySelector('#lf-blocks tbody tr[data-index="' + spEditing + '"]');
		if (tr) {
			var sum = tr.querySelector('.lf-spintax-summary');
			if (sum) {
				sum.textContent = spSummary(spState.blocks[spEditing]);
			}
		}
		spSyncHidden();
		spCloseModal();
	}

	function spOpenModal(idx) {
		spEditing = idx;
		var block = spState.blocks[idx];
		if (!block) {
			return;
		}
		var modal = document.getElementById('lf-spintax-modal');
		var title = document.getElementById('lf-spintax-modal-title');
		var body = document.getElementById('lf-spintax-modal-body');
		if (!modal || !title || !body) {
			return;
		}
		title.textContent =
			(spCfg.i18n && spCfg.i18n.modalTitle ? spCfg.i18n.modalTitle : 'Variations for') +
			' "' +
			(block.key || '') +
			'"';
		body.innerHTML = '';
		var vars = block.variations && block.variations.length ? block.variations.slice() : [''];
		vars.forEach(function (html, vi) {
			var wrap = document.createElement('div');
			wrap.className = 'lf-spintax-modal-var';
			wrap.style.marginBottom = '14px';
			var lab = document.createElement('label');
			lab.style.display = 'block';
			lab.style.marginBottom = '4px';
			lab.style.fontWeight = '600';
			lab.textContent =
				(spCfg.i18n && spCfg.i18n.variation ? spCfg.i18n.variation : 'Variation') + ' ' + (vi + 1);
			var ta = document.createElement('textarea');
			ta.className = 'widefat code';
			ta.rows = 8;
			ta.value = html;
			var rm = document.createElement('p');
			rm.style.marginTop = '6px';
			var rmBtn = document.createElement('button');
			rmBtn.type = 'button';
			rmBtn.className = 'button-link lf-spintax-remove-variation';
			rmBtn.textContent =
				spCfg.i18n && spCfg.i18n.removeVariation ? spCfg.i18n.removeVariation : 'Remove variation';
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
		addBtn.id = 'lf-spintax-add-variation';
		addBtn.textContent = spCfg.i18n && spCfg.i18n.addVariation ? spCfg.i18n.addVariation : 'Add variation';
		addP.appendChild(addBtn);
		body.appendChild(addP);
		modal.style.display = 'flex';
		document.body.style.overflow = 'hidden';
	}

	function spBindModal() {
		var modal = document.getElementById('lf-spintax-modal');
		if (!modal) {
			return;
		}
		var saveBtn = document.getElementById('lf-spintax-modal-save');
		var cancelBtn = document.getElementById('lf-spintax-modal-cancel');
		var dismissBtns = modal.querySelectorAll('.lf-spintax-modal-dismiss');
		if (saveBtn) {
			saveBtn.addEventListener('click', function (e) {
				e.preventDefault();
				spSaveModal();
			});
		}
		if (cancelBtn) {
			cancelBtn.addEventListener('click', function (e) {
				e.preventDefault();
				spCloseModal();
			});
		}
		dismissBtns.forEach(function (b) {
			b.addEventListener('click', function (e) {
				e.preventDefault();
				spCloseModal();
			});
		});
		modal.addEventListener('click', function (e) {
			var t = e.target;
			if (t && t.classList && t.classList.contains('lf-spintax-modal__backdrop')) {
				spCloseModal();
			}
		});
		modal.addEventListener('click', function (e) {
			var t = e.target;
			if (!t) {
				return;
			}
			if (t.id === 'lf-spintax-add-variation') {
				e.preventDefault();
				var body = document.getElementById('lf-spintax-modal-body');
				if (!body) {
					return;
				}
				var addAnchor = document.getElementById('lf-spintax-add-variation');
				var n = body.querySelectorAll('.lf-spintax-modal-var textarea').length;
				var wrap = document.createElement('div');
				wrap.className = 'lf-spintax-modal-var';
				wrap.style.marginBottom = '14px';
				var lab = document.createElement('label');
				lab.style.display = 'block';
				lab.style.marginBottom = '4px';
				lab.style.fontWeight = '600';
				lab.textContent =
					(spCfg.i18n && spCfg.i18n.variation ? spCfg.i18n.variation : 'Variation') + ' ' + (n + 1);
				var ta = document.createElement('textarea');
				ta.className = 'widefat code';
				ta.rows = 8;
				ta.value = '';
				var rm = document.createElement('p');
				rm.style.marginTop = '6px';
				var rmBtn = document.createElement('button');
				rmBtn.type = 'button';
				rmBtn.className = 'button-link lf-spintax-remove-variation';
				rmBtn.textContent =
					spCfg.i18n && spCfg.i18n.removeVariation ? spCfg.i18n.removeVariation : 'Remove variation';
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
			if (t.classList && t.classList.contains('lf-spintax-remove-variation')) {
				e.preventDefault();
				var row = t.closest('.lf-spintax-modal-var');
				var body2 = document.getElementById('lf-spintax-modal-body');
				if (!row || !body2) {
					return;
				}
				var all = body2.querySelectorAll('.lf-spintax-modal-var textarea');
				if (all.length <= 1) {
					return;
				}
				row.remove();
				body2.querySelectorAll('.lf-spintax-modal-var label').forEach(function (labEl, i) {
					labEl.textContent =
						(spCfg.i18n && spCfg.i18n.variation ? spCfg.i18n.variation : 'Variation') + ' ' + (i + 1);
				});
			}
		});
	}

	function spInit() {
		var initial = spCfg.initial;
		if (Array.isArray(initial)) {
			spState.blocks = initial.map(function (b) {
				return {
					key: b.key || '',
					variations:
						Array.isArray(b.variations) && b.variations.length ? b.variations.slice() : [''],
				};
			});
		}
		spRenderRows();
		spBindModal();

		var form = document.querySelector('#post');
		if (form) {
			form.addEventListener('submit', function () {
				document.querySelectorAll('#lf-blocks .lf-spintax-key').forEach(function (inp, i) {
					if (spState.blocks[i]) {
						spState.blocks[i].key = inp.value;
					}
				});
				spSyncHidden();
			});
		}

		var addBtn = document.getElementById('lf-blocks-add');
		if (addBtn) {
			addBtn.addEventListener('click', function () {
				spState.blocks.push({ key: '', variations: [''] });
				spRenderRows();
			});
		}

		var tbl = document.getElementById('lf-blocks');
		if (tbl) {
			tbl.addEventListener('click', function (e) {
				var t = e.target;
				if (!t || !t.classList) {
					return;
				}
				if (t.classList.contains('lf-spintax-edit')) {
					var tr = t.closest('tr');
					var idx = tr ? parseInt(tr.getAttribute('data-index'), 10) : -1;
					if (!isNaN(idx) && idx >= 0) {
						spOpenModal(idx);
					}
				}
				if (t.classList.contains('lf-spintax-remove-block')) {
					var tr2 = t.closest('tr');
					var idx2 = tr2 ? parseInt(tr2.getAttribute('data-index'), 10) : -1;
					if (!isNaN(idx2) && idx2 >= 0) {
						spState.blocks.splice(idx2, 1);
						spRenderRows();
					}
				}
			});
			tbl.addEventListener('input', function (e) {
				if (e.target && e.target.classList.contains('lf-spintax-key')) {
					var tr = e.target.closest('tr');
					var idx = tr ? parseInt(tr.getAttribute('data-index'), 10) : -1;
					if (!isNaN(idx) && spState.blocks[idx]) {
						spState.blocks[idx].key = e.target.value;
						spSyncHidden();
					}
				}
			});
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		spInit();
	});
})();
