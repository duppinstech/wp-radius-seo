(function () {
	var cfg = window.RadiusAnalyticsFrontend;
	if (!cfg || !cfg.post_id || !cfg.ajax_url) {
		return;
	}

	function post(fields) {
		var body = new FormData();
		Object.keys(fields).forEach(function (key) {
			body.append(key, fields[key]);
		});
		if (window.fetch) {
			window.fetch(cfg.ajax_url, {
				method: 'POST',
				body: body,
				credentials: 'same-origin',
				keepalive: true
			});
			return;
		}
	}

	post({
		action: 'radius_record_visit',
		nonce: cfg.nonce,
		post_id: String(cfg.post_id)
	});

	document.addEventListener('click', function (event) {
		var target = event.target;
		var link = target && target.closest ? target.closest('a[href^="http"]') : null;
		if (!link) {
			return;
		}
		post({
			action: 'radius_record_click',
			post_id: String(cfg.post_id),
			click_nonce: cfg.click_nonce
		});
	});
})();
