(function () {
	var button = document.getElementById('radius-help-button');
	var panel = document.getElementById('radius-help-panel');
	var close = document.getElementById('radius-help-close');
	if (!button || !panel || !close) {
		return;
	}

	function setOpen(open) {
		button.setAttribute('aria-expanded', open ? 'true' : 'false');
		panel.hidden = !open;
		if (open) {
			close.focus();
		} else {
			button.focus();
		}
	}

	button.addEventListener('click', function () {
		setOpen(panel.hidden);
	});
	close.addEventListener('click', function () {
		setOpen(false);
	});
	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && !panel.hidden) {
			event.preventDefault();
			setOpen(false);
		}
	});
})();
