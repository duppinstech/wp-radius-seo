/**
 * License field: Remove → mark for deletion; typing after Remove cancels removal.
 */
(function () {
	var rm = document.getElementById('radius-remove-api-key');
	var hf = document.getElementById('radius_api_key_remove_field');
	var input = document.getElementById('radius_api_key');
	if (!rm || !hf) {
		return;
	}
	if (input) {
		input.addEventListener('input', function () {
			if (hf.value === '1' && input.value.length > 0) {
				hf.value = '';
			}
		});
	}
	rm.addEventListener('click', function () {
		hf.value = '1';
		if (input) {
			input.value = '';
			input.removeAttribute('data-mask');
			input.type = 'password';
			input.setAttribute('autocomplete', 'new-password');
			input.focus();
		}
	});
})();
