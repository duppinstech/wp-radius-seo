/**
 * Service-area settings: search places and pick anchor + radius.
 */
(function () {
	var cfg = window.radiusPlaceSearch || {};
	var ajaxUrl = cfg.ajaxurl || '';
	var nonce = cfg.nonce || '';

	function debounce(fn, ms) {
		var t;
		return function () {
			var ctx = this;
			var args = arguments;
			clearTimeout(t);
			t = setTimeout(function () {
				fn.apply(ctx, args);
			}, ms);
		};
	}

	function closeAllSuggest() {
		document.querySelectorAll('.radius-anchor-suggest').forEach(function (el) {
			el.style.display = 'none';
			el.innerHTML = '';
			var search = el.parentNode ? el.parentNode.querySelector('.radius-anchor-search') : null;
			if (search) {
				search.setAttribute('aria-expanded', 'false');
			}
		});
	}

	function nameAnchorRow(row, search, box) {
		if (!box.id) {
			box.id = 'radius-anchor-suggest-' + Math.random().toString(36).slice(2, 8);
		}
		if (!search.id) {
			search.id = 'radius-anchor-search-' + box.id.replace('radius-anchor-suggest-', '');
		}
		search.setAttribute('aria-controls', box.id);
		if (!search.getAttribute('aria-expanded')) {
			search.setAttribute('aria-expanded', 'false');
		}
		if (!search.getAttribute('aria-label') && !document.querySelector('label[for="' + search.id + '"]')) {
			search.setAttribute('aria-label', (cfg.i18n && cfg.i18n.searchLabel) || 'Anchor place');
		}
		if (!box.getAttribute('aria-label')) {
			box.setAttribute('aria-label', (cfg.i18n && cfg.i18n.suggestionsLabel) || 'Place suggestions');
		}
		if (!box.getAttribute('aria-live')) {
			box.setAttribute('aria-live', 'polite');
		}
		var radius = row.querySelector('input[name="radius_anchor_radius[]"]');
		if (radius && !radius.getAttribute('aria-label') && !radius.id) {
			radius.setAttribute('aria-label', (cfg.i18n && cfg.i18n.radiusLabel) || 'Radius (miles)');
		}
	}

	function setSuggestOpen(search, box, open) {
		box.style.display = open ? 'block' : 'none';
		search.setAttribute('aria-expanded', open ? 'true' : 'false');
		if (!open) {
			box.innerHTML = '';
		}
	}

	function bindRow(row) {
		var search = row.querySelector('.radius-anchor-search');
		var hidden = row.querySelector('.radius-pick-place-id') || row.querySelector('.radius-anchor-place-id');
		var box = row.querySelector('.radius-anchor-suggest');
		if (!search || !hidden || !box) {
			return;
		}
		nameAnchorRow(row, search, box);

		var runSearch = debounce(function () {
			var q = (search.value || '').trim();
			if (q.length < 2) {
				setSuggestOpen(search, box, false);
				return;
			}
			var url = ajaxUrl + '?action=radius_search_places&nonce=' + encodeURIComponent(nonce) + '&q=' + encodeURIComponent(q);
			fetch(url, { credentials: 'same-origin' })
				.then(function (r) {
					return r.json();
				})
				.then(function (data) {
					if (!data || !data.success || !data.data || !data.data.places) {
						box.innerHTML = '<div class="lf-suggest-empty">' + (cfg.i18n && cfg.i18n.searchFailed ? cfg.i18n.searchFailed : 'Search failed.') + '</div>';
						setSuggestOpen(search, box, true);
						return;
					}
					var places = data.data.places;
					if (!places.length) {
						box.innerHTML = '<div class="lf-suggest-empty">' + (cfg.i18n && cfg.i18n.noResults ? cfg.i18n.noResults : 'No matches.') + '</div>';
						setSuggestOpen(search, box, true);
						return;
					}
					box.innerHTML = places
						.map(function (p) {
							var warn =
								!p.lat || !p.lng
									? ' <span class="lf-no-coords">(' + (cfg.i18n && cfg.i18n.noCoords ? cfg.i18n.noCoords : 'no lat/lng') + ')</span>'
									: '';
							return (
								'<button type="button" role="option" class="lf-suggest-item radius-suggest-item" data-id="' +
								p.id +
								'" data-label="' +
								escapeAttr(p.name + (p.slug ? ' — ' + p.slug : '')) +
								'">' +
								escapeHtml(p.name) +
								warn +
								'</button>'
							);
						})
						.join('');
					setSuggestOpen(search, box, true);
				})
				.catch(function () {
					box.innerHTML = '<div class="lf-suggest-empty">' + (cfg.i18n && cfg.i18n.searchFailed ? cfg.i18n.searchFailed : 'Search failed.') + '</div>';
					setSuggestOpen(search, box, true);
				});
		}, 250);

		search.addEventListener('input', function () {
			hidden.value = '';
			var legLat = row.querySelector('.radius-anchor-legacy-lat');
			var legLng = row.querySelector('.radius-anchor-legacy-lng');
			if (legLat) {
				legLat.value = '';
			}
			if (legLng) {
				legLng.value = '';
			}
			runSearch();
		});

		search.addEventListener('focus', function () {
			if ((search.value || '').trim().length >= 2 && !hidden.value) {
				runSearch();
			}
		});

		box.addEventListener('click', function (e) {
			var btn = e.target.closest('.lf-suggest-item, .radius-suggest-item');
			if (!btn) {
				return;
			}
			var id = btn.getAttribute('data-id');
			var label = btn.getAttribute('data-label') || '';
			hidden.value = id;
			search.value = label;
			var legLat = row.querySelector('.radius-anchor-legacy-lat');
			var legLng = row.querySelector('.radius-anchor-legacy-lng');
			if (legLat) {
				legLat.value = '';
			}
			if (legLng) {
				legLng.value = '';
			}
			var note = row.querySelector('.radius-anchor-legacy-note');
			if (note) {
				note.remove();
			}
			box.style.display = 'none';
			box.innerHTML = '';
			search.setAttribute('aria-expanded', 'false');
		});
	}

	function escapeHtml(s) {
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	function escapeAttr(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/</g, '&lt;');
	}

	document.addEventListener('click', function (e) {
		if (!e.target.closest('.radius-anchor-pick')) {
			closeAllSuggest();
		}
	});

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.radius-anchor-row').forEach(bindRow);
		var addBtn = document.getElementById('radius-anchor-add');
		if (addBtn) {
			addBtn.addEventListener('click', function () {
				var tbody = document.getElementById('radius-anchor-tbody');
				if (!tbody) {
					tbody = addBtn.closest('td').querySelector('tbody');
				}
				if (!tbody) {
					return;
				}
				var tr = document.createElement('tr');
				tr.className = 'radius-anchor-row';
				var ph = cfg.i18n && cfg.i18n.placeholder ? escapeAttr(cfg.i18n.placeholder) : '';
				var pending =
					cfg.i18n && cfg.i18n.areaCodePending
						? escapeHtml(cfg.i18n.areaCodePending)
						: '— Save settings to assign —';
				tr.innerHTML =
					'<td>' +
					'<input type="hidden" name="radius_anchor_place_id[]" value="" class="radius-anchor-place-id lf-pick-place-id" />' +
					'<input type="hidden" name="radius_anchor_legacy_lat[]" value="" class="radius-anchor-legacy-lat" />' +
					'<input type="hidden" name="radius_anchor_legacy_lng[]" value="" class="radius-anchor-legacy-lng" />' +
					'<div class="radius-anchor-pick">' +
					'<input type="search" class="regular-text radius-anchor-search" value="" placeholder="' +
					ph +
					'" autocomplete="off" aria-label="' +
					escapeAttr((cfg.i18n && cfg.i18n.searchLabel) || 'Anchor place') +
					'" />' +
					'<div class="radius-anchor-suggest" style="display:none;" role="listbox" aria-live="polite" aria-label="' +
					escapeAttr((cfg.i18n && cfg.i18n.suggestionsLabel) || 'Place suggestions') +
					'"></div>' +
					'</div>' +
					'</td>' +
					'<td><input type="text" name="radius_anchor_radius[]" value="" class="small-text" aria-label="' +
					escapeAttr((cfg.i18n && cfg.i18n.radiusLabel) || 'Radius (miles)') +
					'" /></td>' +
					'<td><span class="description">' +
					pending +
					'</span></td>' +
					'<td><button type="button" class="button radius-anchor-remove-row">' +
					(cfg.i18n && cfg.i18n.remove ? escapeHtml(cfg.i18n.remove) : 'Remove') +
					'</button></td>';
				tbody.appendChild(tr);
				bindRow(tr);
			});
		}
		document.addEventListener('click', function (e) {
			if (e.target.classList.contains('radius-anchor-remove-row')) {
				var r = e.target.closest('tr');
				if (r && r.parentNode.querySelectorAll('.radius-anchor-row').length > 1) {
					r.remove();
				}
			}
		});
	});
})();
