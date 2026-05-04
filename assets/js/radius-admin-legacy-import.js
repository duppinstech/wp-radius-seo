/**
 * Chain legacy place import batches via AJAX (one batch per request — avoids timeouts).
 * Handles HTML error pages (Wordfence, timeouts) with retries and clearer messaging.
 * Shows overall + per-batch progress; per-batch % is estimated while the request is in flight.
 */
(function () {
	'use strict';

	var cfg = typeof window.radiusLegacyImport === 'object' ? window.radiusLegacyImport : {};
	var i18n = cfg.i18n || {};

	function el(id) {
		return document.getElementById(id);
	}

	function replaceAll(str, map) {
		var out = str;
		Object.keys(map).forEach(function (k) {
			out = out.split(k).join(map[k]);
		});
		return out;
	}

	function sleep(ms) {
		return new Promise(function (resolve) {
			setTimeout(resolve, ms);
		});
	}

	function looksLikeHtmlResponse(text) {
		var t = (text || '').trim();
		if (!t) {
			return true;
		}
		var c0 = t.charAt(0);
		if (c0 === '<') {
			return true;
		}
		if (c0 === '\ufeff') {
			t = t.slice(1);
			c0 = t.charAt(0);
		}
		return c0 !== '{';
	}

	/**
	 * POST admin-ajax; parse JSON; retry on HTML / network / 5xx.
	 */
	async function postJsonWithRetries(fd) {
		var maxRetries =
			typeof cfg.maxRetries === 'number' && cfg.maxRetries >= 0 ? cfg.maxRetries : 5;
		var attempt = 0;
		var lastErr = '';

		while (attempt <= maxRetries) {
			var res;
			try {
				res = await fetch(cfg.ajaxurl, {
					method: 'POST',
					body: fd,
					credentials: 'same-origin',
					headers: {
						Accept: 'application/json, text/javascript, */*; q=0.01',
					},
				});
			} catch (netErr) {
				lastErr = String(netErr);
				if (attempt >= maxRetries) {
					throw new Error(lastErr);
				}
				await sleep(800 * Math.pow(2, attempt));
				++attempt;
				continue;
			}

			var raw = await res.text();
			if (!res.ok || looksLikeHtmlResponse(raw)) {
				lastErr = !res.ok
					? 'HTTP ' + res.status
					: 'HTML or non-JSON response (likely firewall / timeout)';
				if (attempt >= maxRetries) {
					throw new Error(lastErr);
				}
				await sleep(1000 * Math.pow(2, attempt));
				++attempt;
				continue;
			}

			var j;
			try {
				j = JSON.parse(raw);
			} catch (parseErr) {
				lastErr = String(parseErr);
				if (attempt >= maxRetries) {
					throw parseErr;
				}
				await sleep(1000 * Math.pow(2, attempt));
				++attempt;
				continue;
			}

			return j;
		}

		throw new Error(lastErr || 'Request failed');
	}

	function getBatchSize() {
		var n = typeof cfg.batchSize === 'number' ? cfg.batchSize : 25;
		return Math.max(1, Math.min(100, n));
	}

	/**
	 * Simulated % for the current HTTP request (server does not stream progress).
	 * Rises quickly then levels off ~95% until the response returns.
	 */
	function createBatchProgressTicker(onTick) {
		var start = Date.now();
		var id = setInterval(function () {
			var elapsedSec = (Date.now() - start) / 1000;
			var pct = Math.min(
				95,
				Math.round(100 * (1 - Math.exp(-elapsedSec / 2.2)))
			);
			onTick(pct);
		}, 160);
		return function stop() {
			clearInterval(id);
		};
	}

	function setProgressBar(barEl, value) {
		if (!barEl) {
			return;
		}
		if (typeof value !== 'number' || value < 0) {
			barEl.removeAttribute('value');
			return;
		}
		barEl.value = Math.min(100, Math.round(value));
	}

	async function runChain() {
		var btn = el('radius-legacy-import-start');
		var wrap = el('radius-legacy-import-status');
		var line = el('radius-legacy-import-status-line');
		var log = el('radius-legacy-import-log');
		var overall = el('radius-legacy-import-overall');
		var overallLab = el('radius-legacy-import-overall-label');
		var overallCap = el('radius-legacy-import-overall-caption');
		var batch = el('radius-legacy-import-batch');
		var batchLab = el('radius-legacy-import-batch-label');
		var batchCap = el('radius-legacy-import-batch-caption');
		var batchWrap = el('radius-legacy-import-batch-wrap');

		if (!btn || !cfg.ajaxurl || !cfg.nonce) {
			return;
		}

		var batchSize = getBatchSize();

		btn.disabled = true;
		btn.textContent = i18n.running || 'Importing…';
		if (wrap) {
			wrap.hidden = false;
		}
		if (line) {
			line.textContent = i18n.startingFmt || '';
		}
		if (log) {
			log.textContent = '';
		}
		if (overallLab) {
			overallLab.textContent = i18n.overallLabel || '';
		}
		if (batchLab) {
			batchLab.textContent = i18n.batchLabel || '';
		}
		setProgressBar(overall, 0);
		setProgressBar(batch, 0);
		if (overallCap) {
			overallCap.textContent = i18n.waitingTotalFmt || '';
		}
		if (batchCap) {
			batchCap.textContent = '';
		}
		if (batchWrap) {
			batchWrap.style.opacity = '1';
		}

		var offset = 0;
		var totalLegacy = 0;
		var interBatchMs =
			typeof cfg.interBatchDelayMs === 'number' && cfg.interBatchDelayMs >= 0
				? cfg.interBatchDelayMs
				: 1200;
		var skipCb = el('radius-legacy-import-skip-existing');

		try {
			while (true) {
				var fd = new FormData();
				fd.append('action', 'radius_legacy_places_batch');
				fd.append('nonce', cfg.nonce);
				fd.append('offset', String(offset));
				if (totalLegacy > 0) {
					fd.append('total_legacy', String(totalLegacy));
				}
				fd.append('skip_existing', skipCb && skipCb.checked ? '1' : '0');

				if (batchCap) {
					batchCap.textContent = replaceAll(i18n.batchWorkingFmt || '', {
						'{pct}': '0',
						'{size}': String(batchSize),
					});
				}
				setProgressBar(batch, 0);

				var stopTicker = createBatchProgressTicker(function (pct) {
					setProgressBar(batch, pct);
					if (batchCap) {
						batchCap.textContent = replaceAll(i18n.batchWorkingFmt || '', {
							'{pct}': String(pct),
							'{size}': String(batchSize),
						});
					}
				});

				var j;
				try {
					j = await postJsonWithRetries(fd);
				} catch (e) {
					stopTicker();
					setProgressBar(batch, 0);
					if (log) {
						log.textContent += (i18n.nonJsonFail || String(e)) + '\n';
					}
					if (line) {
						line.textContent = i18n.stopped || 'Import stopped.';
					}
					if (batchCap) {
						batchCap.textContent = '';
					}
					break;
				}
				stopTicker();
				setProgressBar(batch, 100);
				if (batchCap) {
					batchCap.textContent = replaceAll(i18n.batchCompleteFmt || '', {
						'{pct}': '100',
					});
				}

				if (!j.success) {
					var errMsg =
						j.data && j.data.message
							? j.data.message
							: i18n.stopped || 'Import stopped.';
					if (log) {
						log.textContent +=
							(i18n.errorPrefix || 'Error:') + ' ' + errMsg + '\n';
					}
					if (line) {
						line.textContent = errMsg;
					}
					break;
				}

				var d = j.data;
				totalLegacy = d.total_legacy || totalLegacy;

				var batchLine = replaceAll(i18n.batchFmt || '', {
					'{offset}': String(offset),
					'{new}': String(d.imported != null ? d.imported : 0),
					'{updated}': String(d.updated != null ? d.updated : 0),
					'{skipped}': String(d.skipped != null ? d.skipped : 0),
					'{skipped_existing}': String(
						d.skipped_existing != null ? d.skipped_existing : 0
					),
				});

				if (log) {
					log.textContent += batchLine + '\n';
					if (d.errors && d.errors.length) {
						log.textContent +=
							replaceAll(i18n.errorsFmt || '', {
								'{errors}': d.errors.join(' '),
							}) + '\n';
					}
				}

				var done = d.next_offset != null ? d.next_offset : offset;
				var pct =
					totalLegacy > 0
						? Math.min(100, Math.round((done / totalLegacy) * 100))
						: 0;
				setProgressBar(overall, totalLegacy > 0 ? pct : 0);
				if (overallCap && totalLegacy > 0) {
					overallCap.textContent = replaceAll(i18n.progressFmt || '', {
						'{pct}': String(pct),
						'{done}': String(Math.min(done, totalLegacy)),
						'{total}': String(totalLegacy),
					});
				} else if (overallCap) {
					overallCap.textContent = i18n.waitingTotalFmt || '';
				}
				if (line && totalLegacy > 0) {
					line.textContent = replaceAll(i18n.progressFmt || '', {
						'{pct}': String(pct),
						'{done}': String(Math.min(done, totalLegacy)),
						'{total}': String(totalLegacy),
					});
				}

				if (!d.has_more) {
					setProgressBar(overall, 100);
					if (overallCap && totalLegacy > 0) {
						overallCap.textContent = replaceAll(i18n.progressFmt || '', {
							'{pct}': '100',
							'{done}': String(totalLegacy),
							'{total}': String(totalLegacy),
						});
					}
					if (line && totalLegacy > 0) {
						line.textContent = replaceAll(i18n.progressFmt || '', {
							'{pct}': '100',
							'{done}': String(totalLegacy),
							'{total}': String(totalLegacy),
						});
					} else if (line) {
						line.textContent = i18n.done || 'Done.';
					}
					setProgressBar(batch, 100);
					if (batchCap) {
						batchCap.textContent = replaceAll(i18n.batchCompleteFmt || '', {
							'{pct}': '100',
						});
					}
					break;
				}

				var nextOff = d.next_offset != null ? d.next_offset : offset;
				if (nextOff <= offset) {
					if (log) {
						log.textContent += 'Import did not advance offset; stopping.\n';
					}
					break;
				}
				offset = nextOff;

				if (interBatchMs > 0) {
					setProgressBar(batch, 0);
					if (batchCap) {
						batchCap.textContent = replaceAll(i18n.pauseFmt || '', {
							'{ms}': String(interBatchMs),
						});
					}
					await sleep(interBatchMs);
				}
			}
		} catch (e) {
			if (log) {
				log.textContent += (i18n.errorPrefix || 'Error:') + ' ' + String(e) + '\n';
			}
			if (line) {
				line.textContent = i18n.stopped || 'Import stopped.';
			}
		}

		btn.disabled = false;
		btn.textContent = i18n.start || 'Run legacy place import (all batches)';
	}

	window.radiusLegacyImportRunAll = runChain;

	document.addEventListener('DOMContentLoaded', function () {
		var buttons = document.querySelectorAll(
			'#radius-legacy-import-start, .radius-legacy-place-import-start'
		);
		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				runChain();
			});
		});
	});
})();
