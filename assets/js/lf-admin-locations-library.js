/**
 * Location library: batched empty-library purge + bulk-action guard.
 */
(function () {
	'use strict';

	var cfg = typeof radiusLocationsLibrary === 'object' && radiusLocationsLibrary !== null ? radiusLocationsLibrary : {};
	var ajaxurl = cfg.ajaxurl || '';
	var nonce = cfg.nonce || '';
	var dedupeNonce = cfg.dedupeNonce || '';
	var i18n = cfg.i18n || {};

	function sleep(ms) {
		return new Promise(function (resolve) {
			setTimeout(resolve, ms);
		});
	}

	function bindBulkGuard() {
		var form = document.getElementById('radius-places-bulk-form');
		var submit = document.getElementById('lf-places-bulk-submit');
		var actionSel = document.getElementById('lf_places_bulk_action');
		if (!submit || !form || !actionSel) {
			return;
		}
		submit.addEventListener('click', function (e) {
			if (!actionSel.value) {
				e.preventDefault();
				return;
			}
			if (actionSel.value === 'delete') {
				if (!window.confirm(i18n.confirmDelete || '')) {
					e.preventDefault();
					return;
				}
			}
			var any = false;
			form.querySelectorAll('.lf-place-cb').forEach(function (cb) {
				if (cb.checked) {
					any = true;
				}
			});
			if (!any) {
				e.preventDefault();
				window.alert(i18n.selectOne || '');
			}
		});
	}

	function bindSelectAll() {
		var all = document.getElementById('lf-select-all-places');
		var form = document.getElementById('radius-places-bulk-form');
		if (!all || !form) {
			return;
		}
		all.addEventListener('change', function () {
			form.querySelectorAll('.lf-place-cb').forEach(function (cb) {
				cb.checked = all.checked;
			});
		});
	}

	function bindPurge() {
		var btn = document.getElementById('radius-purge-places-start');
		var status = document.getElementById('radius-purge-places-status');
		if (!btn || !status || !ajaxurl || !nonce) {
			return;
		}
		btn.addEventListener('click', function () {
			if (!window.confirm(i18n.confirmPurge || '')) {
				return;
			}
			btn.disabled = true;
			var totalDeleted = 0;
			var interMs = typeof cfg.interRequestMs === 'number' ? cfg.interRequestMs : 250;

			function fmt(tpl, map) {
				var out = tpl;
				Object.keys(map).forEach(function (k) {
					out = out.split('{' + k + '}').join(String(map[k]));
				});
				return out;
			}

			function tplOr(s, fallback) {
				return s && String(s).length ? String(s) : fallback;
			}

			(function loop() {
				var body = new URLSearchParams();
				body.set('action', 'radius_purge_places_batch');
				body.set('nonce', nonce);

				fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
					},
					body: body.toString(),
				})
					.then(function (r) {
						return r.json();
					})
					.then(function (json) {
						if (!json || !json.success) {
							var msg =
								(json && json.data && json.data.message) ||
								(json && json.data && json.data[0]) ||
								i18n.purgeError ||
								'Error';
							status.textContent = String(msg);
							btn.disabled = false;
							return;
						}
						var d = json.data || {};
						var del = parseInt(d.deleted, 10) || 0;
						var rem = parseInt(d.remaining, 10);
						totalDeleted += del;
						status.textContent = fmt(
							tplOr(i18n.purgeProgressTpl, ''),
							{
								deleted: del,
								total: totalDeleted,
								remaining: rem,
							}
						);
						if (d.done || del === 0) {
							status.textContent = fmt(tplOr(i18n.purgeDoneTpl, ''), { total: totalDeleted });
							btn.disabled = false;
							window.setTimeout(function () {
								window.location.reload();
							}, 800);
							return;
						}
						sleep(interMs).then(loop);
					})
					.catch(function () {
						status.textContent = i18n.purgeNetwork || '';
						btn.disabled = false;
					});
			})();
		});
	}

	function bindDedupe() {
		var btn = document.getElementById('radius-dedupe-places-start');
		var status = document.getElementById('radius-dedupe-places-status');
		if (!btn || !status || !ajaxurl || !dedupeNonce) {
			return;
		}
		if (btn.disabled) {
			return;
		}
		btn.addEventListener('click', function () {
			if (!window.confirm(i18n.confirmDedupe || '')) {
				return;
			}
			btn.disabled = true;
			var totalDeleted = 0;
			var interMs = typeof cfg.interRequestMs === 'number' ? cfg.interRequestMs : 250;

			function fmt(tpl, map) {
				var out = tpl;
				Object.keys(map).forEach(function (k) {
					out = out.split('{' + k + '}').join(String(map[k]));
				});
				return out;
			}

			function tplOr(s, fallback) {
				return s && String(s).length ? String(s) : fallback;
			}

			(function loop() {
				var body = new URLSearchParams();
				body.set('action', 'radius_dedupe_places_batch');
				body.set('nonce', dedupeNonce);

				fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
					},
					body: body.toString(),
				})
					.then(function (r) {
						return r.json();
					})
					.then(function (json) {
						if (!json || !json.success) {
							var msg =
								(json && json.data && json.data.message) ||
								(json && json.data && json.data[0]) ||
								i18n.dedupeError ||
								'Error';
							status.textContent = String(msg);
							btn.disabled = false;
							return;
						}
						var d = json.data || {};
						var del = parseInt(d.deleted, 10) || 0;
						var rem = parseInt(d.remaining, 10);
						totalDeleted += del;
						status.textContent = fmt(
							tplOr(i18n.dedupeProgressTpl, ''),
							{
								deleted: del,
								total: totalDeleted,
								remaining: rem,
							}
						);
						if (d.done || del === 0) {
							status.textContent = fmt(tplOr(i18n.dedupeDoneTpl, ''), { total: totalDeleted });
							window.setTimeout(function () {
								window.location.reload();
							}, 800);
							return;
						}
						sleep(interMs).then(loop);
					})
					.catch(function () {
						status.textContent = i18n.dedupeNetwork || '';
						btn.disabled = false;
					});
			})();
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		bindBulkGuard();
		bindSelectAll();
		bindPurge();
		bindDedupe();
	});
})();
