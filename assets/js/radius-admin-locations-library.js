/**
 * Location library: batched empty-library purge + bulk-action guard.
 */
(function () {
	'use strict';

	var cfg = typeof radiusLocationsLibrary === 'object' && radiusLocationsLibrary !== null ? radiusLocationsLibrary : {};
	var ajaxurl = cfg.ajaxurl || '';
	var nonce = cfg.nonce || '';
	var dedupeNonce = cfg.dedupeNonce || '';
	var slugBlacklistNonce = cfg.slugBlacklistNonce || '';
	var repairSlugNonce = cfg.repairSlugNonce || '';
	var i18n = cfg.i18n || {};

	function sleep(ms) {
		return new Promise(function (resolve) {
			setTimeout(resolve, ms);
		});
	}

	function bindBulkGuard() {
		var form = document.getElementById('radius-places-bulk-form');
		var submit = document.getElementById('radius-places-bulk-submit');
		var actionSel = document.getElementById('radius_places_bulk_action');
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
			form.querySelectorAll('.radius-place-cb').forEach(function (cb) {
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
		var all = document.getElementById('radius-select-all-places');
		var form = document.getElementById('radius-places-bulk-form');
		if (!all || !form) {
			return;
		}
		all.addEventListener('change', function () {
			form.querySelectorAll('.radius-place-cb').forEach(function (cb) {
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

	function bindSlugBlacklist() {
		var btn = document.getElementById('radius-slug-blacklist-places-start');
		var status = document.getElementById('radius-slug-blacklist-places-status');
		if (!btn || !status || !ajaxurl || !slugBlacklistNonce) {
			return;
		}
		if (btn.disabled) {
			return;
		}
		btn.addEventListener('click', function () {
			if (!window.confirm(i18n.confirmSlugBlacklist || '')) {
				return;
			}
			btn.disabled = true;
			var totalDeleted = 0;
			var totalPagesTrashed = 0;
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
				body.set('action', 'radius_slug_blacklist_places_batch');
				body.set('nonce', slugBlacklistNonce);

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
								i18n.slugBlacklistError ||
								'Error';
							status.textContent = String(msg);
							btn.disabled = false;
							return;
						}
						var d = json.data || {};
						var del = parseInt(d.deleted, 10) || 0;
						var pages = parseInt(d.pages_trashed, 10) || 0;
						var rem = parseInt(d.remaining, 10);
						totalDeleted += del;
						totalPagesTrashed += pages;
						status.textContent = fmt(
							tplOr(i18n.slugBlacklistProgressTpl, ''),
							{
								deleted: del,
								pages: pages,
								total: totalDeleted,
								pagesTotal: totalPagesTrashed,
								remaining: rem,
							}
						);
						if (d.done || del === 0) {
							status.textContent = fmt(tplOr(i18n.slugBlacklistDoneTpl, ''), {
								total: totalDeleted,
								pagesTotal: totalPagesTrashed,
							});
							window.setTimeout(function () {
								window.location.reload();
							}, 800);
							return;
						}
						sleep(interMs).then(loop);
					})
					.catch(function () {
						status.textContent = i18n.slugBlacklistNetwork || '';
						btn.disabled = false;
					});
			})();
		});
	}

	function bindRepairNumberedSlugs() {
		var btn = document.getElementById('radius-repair-numbered-slugs-start');
		var status = document.getElementById('radius-repair-numbered-slugs-status');
		if (!btn || !status || !ajaxurl || !repairSlugNonce) {
			return;
		}
		btn.addEventListener('click', function () {
			if (!window.confirm(i18n.confirmRepairSlugs || '')) {
				return;
			}
			btn.disabled = true;
			var totalRepaired = 0;
			var cursor = 0;
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
				body.set('action', 'radius_repair_numbered_slug_places_batch');
				body.set('nonce', repairSlugNonce);
				body.set('group_offset', String(cursor));
				body.set('cursor_term_id', String(cursor));

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
								i18n.repairSlugsError ||
								'Error';
							status.textContent = String(msg);
							btn.disabled = false;
							return;
						}
						var d = json.data || {};
						var rep = parseInt(d.repaired, 10) || 0;
						var rem = parseInt(d.remaining, 10);
						totalRepaired += rep;
						cursor =
							parseInt(d.group_offset, 10) ||
							parseInt(d.next_cursor_term_id, 10) ||
							0;
						status.textContent = fmt(
							tplOr(i18n.repairSlugsProgressTpl, ''),
							{
								repaired: rep,
								legacyImport: parseInt(d.legacy_imported, 10) || 0,
								renamed: parseInt(d.slug_renamed, 10) || 0,
								skipped: parseInt(d.skipped, 10) || 0,
								total: totalRepaired,
								remaining: rem,
							}
						);
						if (d.done) {
							status.textContent = fmt(tplOr(i18n.repairSlugsDoneTpl, ''), {
								total: totalRepaired,
							});
							window.setTimeout(function () {
								window.location.reload();
							}, 800);
							return;
						}
						sleep(interMs).then(loop);
					})
					.catch(function () {
						status.textContent = i18n.repairSlugsNetwork || '';
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
		bindRepairNumberedSlugs();
		bindSlugBlacklist();
	});
})();
