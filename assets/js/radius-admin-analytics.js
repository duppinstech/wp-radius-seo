jQuery(document).ready(function($) {
	var config = typeof radiusAnalytics !== 'undefined' ? radiusAnalytics : null;
	if (!config) {
		return;
	}

	var perPage = parseInt(config.per_page, 10) || 25;
	var i18n = config.i18n || {};

	var state = {
		locationsAll: [],
		topPagesAll: [],
		locPage: 1,
		pagesPage: 1
	};

	function esc(s) {
		if (s == null || s === '') return '—';
		var div = document.createElement('div');
		div.textContent = s;
		return div.innerHTML;
	}

	function pageCount(total, size) {
		return Math.max(1, Math.ceil(total / size));
	}

	function sprintfPageOf(cur, tot) {
		var t = i18n.page_of || 'Page %1$s of %2$s';
		return t.replace('%1$s', String(cur)).replace('%2$s', String(tot));
	}

	function bindPager($wrap, page, totalItems, onPage) {
		if (!$wrap.length) return;
		var totalPages = pageCount(totalItems, perPage);
		if (totalItems === 0) {
			$wrap.attr('hidden', true).empty();
			return;
		}
		if (totalPages <= 1) {
			$wrap.attr('hidden', true).empty();
			return;
		}
		$wrap.removeAttr('hidden');
		var prevDis = page <= 1 ? ' disabled' : '';
		var nextDis = page >= totalPages ? ' disabled' : '';
		var html = '<div class="lfa-pager-inner">' +
			'<button type="button" class="button lfa-pager-prev"' + prevDis + '>' + esc(i18n.prev || '« Previous') + '</button>' +
			'<span class="lfa-pager-status">' + esc(sprintfPageOf(page, totalPages)) + '</span>' +
			'<button type="button" class="button lfa-pager-next"' + nextDis + '>' + esc(i18n.next || 'Next »') + '</button>' +
			'</div>';
		$wrap.html(html);
		$wrap.find('.lfa-pager-prev').on('click', function() {
			if (page > 1) onPage(page - 1);
		});
		$wrap.find('.lfa-pager-next').on('click', function() {
			if (page < totalPages) onPage(page + 1);
		});
	}

	function renderLocationsSlice() {
		var tbody = document.getElementById('lfa-locations-tbody');
		var $pager = $('#lfa-locations-pager');
		if (!tbody) return;

		var all = state.locationsAll;
		if (!all.length) {
			tbody.innerHTML = '<tr><td colspan="6">' + esc('No place data yet. Visits on published landings will show here.') + '</td></tr>';
			$pager.attr('hidden', true).empty();
			return;
		}

		var start = (state.locPage - 1) * perPage;
		var slice = all.slice(start, start + perPage);
		tbody.innerHTML = slice.map(function(loc) {
			return '<tr><td>' + esc(loc.label) + '</td><td>' + esc(loc.region) + '</td><td>' + (loc.pages || 0) + '</td><td>' + (loc.visits || 0).toLocaleString() + '</td><td>' + (loc.clicks || 0).toLocaleString() + '</td><td>' + (loc.ctr != null ? loc.ctr + '%' : '—') + '</td></tr>';
		}).join('');

		bindPager($pager, state.locPage, all.length, function(p) {
			state.locPage = p;
			renderLocationsSlice();
		});
	}

	function renderTopPagesSlice() {
		var tbody = document.getElementById('lfa-top-pages-tbody');
		var $pager = $('#lfa-top-pages-pager');
		if (!tbody) return;

		var all = state.topPagesAll;
		if (!all.length) {
			tbody.innerHTML = '<tr><td colspan="4">' + esc('No landing data yet.') + '</td></tr>';
			$pager.attr('hidden', true).empty();
			return;
		}

		var start = (state.pagesPage - 1) * perPage;
		var slice = all.slice(start, start + perPage);
		tbody.innerHTML = slice.map(function(p) {
			return '<tr><td>' + esc(p.title) + '</td><td>' + (p.count || 0).toLocaleString() + '</td><td>' + (p.clicks || 0).toLocaleString() + '</td><td>' + (p.ctr != null ? p.ctr + '%' : '—') + '</td></tr>';
		}).join('');

		bindPager($pager, state.pagesPage, all.length, function(p) {
			state.pagesPage = p;
			renderTopPagesSlice();
		});
	}

	$.post(config.ajax_url, {
		action: 'radius_analytics_data',
		nonce: config.nonce
	}, function(response) {
		if (!response.success) {
			var tp = document.getElementById('lfa-top-pages-tbody');
			if (tp) tp.innerHTML = '<tr><td colspan="4">Failed to load data.</td></tr>';
			var lb = document.getElementById('lfa-locations-tbody');
			if (lb) lb.innerHTML = '<tr><td colspan="6">Failed to load data.</td></tr>';
			return;
		}

		var data = response.data;
		var s = data.summary || {};
		var locEl = document.getElementById('lfa-stat-locations');
		var visEl = document.getElementById('lfa-stat-visits');
		var clkEl = document.getElementById('lfa-stat-clicks');
		var ctrEl = document.getElementById('lfa-stat-ctr');
		if (locEl) locEl.textContent = s.total_locations != null ? s.total_locations : '—';
		if (visEl) visEl.textContent = s.total_visits != null ? s.total_visits.toLocaleString() : '—';
		if (clkEl) clkEl.textContent = s.total_clicks != null ? s.total_clicks.toLocaleString() : '—';
		if (ctrEl) ctrEl.textContent = s.overall_ctr != null ? s.overall_ctr + '%' : '—';

		state.locationsAll = data.locations_all || [];
		state.topPagesAll = data.top_pages_all || [];
		state.locPage = 1;
		state.pagesPage = 1;

		renderLocationsSlice();
		renderTopPagesSlice();

		var visitsOverTime = data.visits_over_time || {};
		var votLabels = Object.keys(visitsOverTime);
		var votData = Object.values(visitsOverTime);
		var votEl = document.getElementById('lfa-chart-visits-over-time');
		if (votEl && votLabels.length) {
			new Chart(votEl, {
				type: 'line',
				data: {
					labels: votLabels,
					datasets: [{
						label: 'Visits',
						data: votData,
						borderColor: 'rgb(59, 130, 246)',
						backgroundColor: 'rgba(59, 130, 246, 0.1)',
						fill: true,
						tension: 0.2
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false } },
					scales: {
						x: { ticks: { maxRotation: 45 } },
						y: { beginAtZero: true }
					}
				}
			});
		} else if (votEl) {
			votEl.parentNode.innerHTML = '<p class="lfa-no-data">No visit data for the last 30 days yet.</p>';
		}

		var locations = state.locationsAll.slice();
		var locVisitsEl = document.getElementById('lfa-chart-locations-visits');
		if (locVisitsEl && locations.length) {
			var locLabels = locations.slice(0, 10).map(function(l) { return l.label; });
			var locVisits = locations.slice(0, 10).map(function(l) { return l.visits; });
			new Chart(locVisitsEl, {
				type: 'bar',
				data: {
					labels: locLabels,
					datasets: [{
						label: 'Visits',
						data: locVisits,
						backgroundColor: 'rgba(34, 197, 94, 0.7)',
						borderColor: 'rgb(34, 197, 94)',
						borderWidth: 1
					}]
				},
				options: {
					indexAxis: 'y',
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false } },
					scales: {
						x: { beginAtZero: true },
						y: { ticks: { maxRotation: 0 } }
					}
				}
			});
		} else if (locVisitsEl) {
			locVisitsEl.parentNode.innerHTML = '<p class="lfa-no-data">No data yet.</p>';
		}

		var locClicksEl = document.getElementById('lfa-chart-locations-clicks');
		if (locClicksEl && locations.length) {
			var locLabels2 = locations.slice(0, 10).map(function(l) { return l.label; });
			var locClicks = locations.slice(0, 10).map(function(l) { return l.clicks; });
			new Chart(locClicksEl, {
				type: 'bar',
				data: {
					labels: locLabels2,
					datasets: [{
						label: 'Clicks',
						data: locClicks,
						backgroundColor: 'rgba(59, 130, 246, 0.7)',
						borderColor: 'rgb(59, 130, 246)',
						borderWidth: 1
					}]
				},
				options: {
					indexAxis: 'y',
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false } },
					scales: {
						x: { beginAtZero: true },
						y: { ticks: { maxRotation: 0 } }
					}
				}
			});
		} else if (locClicksEl) {
			locClicksEl.parentNode.innerHTML = '<p class="lfa-no-data">No data yet.</p>';
		}
	}).fail(function() {
		var t = document.getElementById('lfa-top-pages-tbody');
		if (t) t.innerHTML = '<tr><td colspan="4">Failed to load.</td></tr>';
		var lb = document.getElementById('lfa-locations-tbody');
		if (lb) lb.innerHTML = '<tr><td colspan="6">Failed to load.</td></tr>';
	});

	$('#lfa-clear-unassigned-stats').on('click', function() {
		var msg = (config.i18n && config.i18n.clear_unassigned_confirm)
			? config.i18n.clear_unassigned_confirm
			: 'Clear unassigned stats?';
		if (!window.confirm(msg)) {
			return;
		}
		var $btn = $(this);
		$btn.prop('disabled', true);
		$.post(config.ajax_url, {
			action: 'radius_analytics_clear_unassigned',
			nonce: config.nonce
		}, function(response) {
			if (!response.success) {
				window.alert('Could not clear stats.');
				$btn.prop('disabled', false);
				return;
			}
			var d = response.data || {};
			var done = (config.i18n && config.i18n.clear_unassigned_done)
				? config.i18n.clear_unassigned_done
				: 'Cleared %1$s landing(s).';
			done = done.replace('%1$s', String(d.cleared_posts != null ? d.cleared_posts : 0));
			window.alert(done);
			window.location.reload();
		}).fail(function() {
			window.alert('Request failed.');
			$btn.prop('disabled', false);
		});
	});
});
