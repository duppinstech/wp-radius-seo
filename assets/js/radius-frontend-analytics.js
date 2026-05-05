jQuery(document).ready(function($) {
	var cfg = typeof RadiusAnalyticsFrontend !== 'undefined' ? RadiusAnalyticsFrontend : null;
	if (!cfg || !cfg.post_id) {
		return;
	}

	$.post(cfg.ajax_url, {
		action: 'radius_record_visit',
		nonce: cfg.nonce,
		post_id: cfg.post_id
	});

	$(document.body).on('click', 'a[href^="http"]', function() {
		var $a = $(this);
		var href = $a.attr('href');
		var text = $a.text().trim() || $a.attr('title') || '';
		if (!href) {
			return;
		}
		$.post(cfg.ajax_url, {
			action: 'radius_record_click',
			post_id: cfg.post_id,
			href: href,
			text: text,
			click_nonce: cfg.click_nonce
		});
	});
});
