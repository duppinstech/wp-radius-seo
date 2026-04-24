/**
 * License tab: validate API key (placeholder always succeeds when key is non-empty).
 */
(function () {
	var btn = document.getElementById('radius-validate-api-key');
	var input = document.getElementById('lf_api_key');
	var statusEl = document.getElementById('radius-api-validate-status');
	if (!btn || !window.radiusLicenseValidate || !statusEl) {
		return;
	}

	function setStatus(mode, text) {
		statusEl.hidden = false;
		statusEl.className = 'radius-api-validate-status';
		if (mode === 'ok') {
			statusEl.classList.add('radius-api-validate-status--ok');
		} else if (mode === 'bad') {
			statusEl.classList.add('radius-api-validate-status--bad');
		} else {
			statusEl.classList.add('radius-api-validate-status--pending');
		}
		statusEl.textContent = text;
	}

	btn.addEventListener('click', function () {
		var fd = new FormData();
		fd.append('action', 'radius_validate_api_key');
		fd.append('nonce', radiusLicenseValidate.nonce);
		var v = input ? String(input.value).trim() : '';
		var mask = input && input.getAttribute('data-mask');
		if (mask && v === mask && radiusLicenseValidate.hasSavedKey) {
			fd.append('lf_api_key', '');
			fd.append('use_saved', '1');
		} else {
			fd.append('lf_api_key', v);
			if (v === '' && radiusLicenseValidate.hasSavedKey) {
				fd.append('use_saved', '1');
			}
		}
		btn.disabled = true;
		setStatus('pending', radiusLicenseValidate.i18n.checking);
		fetch(radiusLicenseValidate.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (data) {
				if (data.success && data.data && data.data.valid) {
					setStatus('ok', data.data.message || radiusLicenseValidate.i18n.ok);
					return;
				}
				var msg =
					data.data && data.data.message
						? data.data.message
						: radiusLicenseValidate.i18n.fail;
				setStatus('bad', msg);
			})
			.catch(function () {
				setStatus('bad', radiusLicenseValidate.i18n.fail);
			})
			.finally(function () {
				btn.disabled = false;
			});
	});
})();
