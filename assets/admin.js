(function() {
	'use strict';

	// WPMJ is emitted immediately before this file by wp_add_inline_script(),
	// as one JSON literal rather than a set of quoted interpolations.
	// wp_json_encode() escapes forward slashes, so a closing script tag inside
	// any value cannot break out, and unlike wp_localize_script() it preserves
	// types - hasResults stays a boolean and the resume cursors stay integers.

	// Translation helper: looks up the key in WPMJ.i18n and substitutes args
	// for placeholders. Supports both sequential (%s, %d) and positional
	// (%1$s, %2$d) sprintf-style markers - same syntax as PHP's sprintf so
	// translators can rely on identical conventions on both sides.
	const I18N = WPMJ.i18n;
	function tr(key, ...args) {
		const template = I18N[key] !== undefined ? I18N[key] : key;
		let seqIndex = 0;
		return template.replace(/%(?:(\d+)\$)?([ds])/g, (m, posStr) => {
			const idx = posStr ? parseInt(posStr, 10) - 1 : seqIndex++;
			return args[idx] !== undefined ? String(args[idx]) : m;
		});
	}

	// ---- Helpers ----
	function $(sel) { return document.querySelector(sel); }
	function $$(sel) { return document.querySelectorAll(sel); }

	// Retries transient failures before giving up.
	//
	// A scan over a large library is hundreds of sequential requests, and
	// losing the whole run to one blip - a dropped connection, a 502 from a
	// host under load - meant starting from zero. Only genuinely transient
	// conditions are retried: a network error, a 5xx, or a gateway timeout.
	// A 4xx is the server saying no, and repeating it changes nothing.
	async function wpmjAjax(action, params, opts) {
		const attempts = (opts && opts.retries !== undefined) ? opts.retries : 3;
		let lastErr = null;

		for (let attempt = 0; attempt < attempts; attempt++) {
			if (attempt > 0) {
				// 600ms, 1800ms, 5400ms
				await new Promise(r => setTimeout(r, 600 * Math.pow(3, attempt - 1)));
			}

			const body = new FormData();
			body.append('action', 'wpmj_' + action);
			body.append('nonce', WPMJ.nonce);
			if (params) Object.entries(params).forEach(([k, v]) => body.append(k, v));

			let resp;
			try {
				resp = await fetch(WPMJ.ajax, { method: 'POST', body });
			} catch (e) {
				lastErr = new Error(tr('errNetwork'));
				continue;
			}

			if (!resp.ok) {
				const err = new Error(tr('errHttpFmt', resp.status, resp.statusText));
				if (resp.status >= 500 || resp.status === 408 || resp.status === 429) {
					lastErr = err;
					continue;
				}
				// 4xx: the server has decided. Surface the JSON body if there
				// is one, so an expired nonce reads as an expired nonce.
				const t = await resp.text();
				try { return JSON.parse(t); } catch (e) { throw err; }
			}

			const text = await resp.text();
			try {
				return JSON.parse(text);
			} catch (e) {
				console.error('WPMJ: non-JSON response:', text.substring(0, 500));
				lastErr = new Error(tr('errInvalidJson'));
				continue;
			}
		}

		throw lastErr || new Error(tr('errNetwork'));
	}

	function setBar(pct) {
		const v = Math.max(0, Math.min(100, Math.round(pct)));
		$('#wpmj-bar').style.width = pct + '%';
		$('#wpmj-bar-label').textContent = v + '%';
		const wrap = $('#wpmj-bar-wrap');
		if (wrap) wrap.setAttribute('aria-valuenow', v);
	}

	function log(msg, cls) {
		const el = $('#wpmj-log');
		const line = document.createElement('div');
		if (cls) line.className = cls;
		line.textContent = msg;
		el.appendChild(line);
		el.scrollTop = el.scrollHeight;
	}

	// wpmjAjax throws once its retries are exhausted, and every destructive
	// click handler awaits it. Without this wrapper a failed request left the
	// button disabled reading "Moving to trash…" forever, with nothing on
	// screen and only an uncaught promise rejection in the console - so the
	// user could not tell whether their files had been deleted or not.
	function guard(fn) {
		return async function (e) {
			try {
				await fn.call(this, e);
			} catch (err) {
				console.error('WPMJ:', err);
				showNotice(err && err.message ? err.message : String(err), 'error');
				// Put every control back the way it was found.
				$$('#wpmj-app button[disabled]').forEach(b => { b.disabled = false; });
				restoreButtonLabels();
			}
		};
	}

	function restoreButtonLabels() {
		const b1 = $('#wpmj-delete-unused-btn');
		if (b1) b1.textContent = tr('btnTrashSelected');
		const b2 = $('#wpmj-cleanup-all-btn');
		if (b2) b2.textContent = tr('btnTrashAllFmt', STATE.unused.length);
		const b3 = $('#wpmj-resolve-all-btn');
		if (b3) b3.textContent = tr('resolveAll');
	}

	// A resolve that blocked everything is NOT resolved. Report it by the
	// actual reason: the server deliberately keeps the group in the results
	// so it can be retried, and marking the group done threw that away.
	function reportBlocked(blocked) {
		if (!blocked || !blocked.length) return;
		const byReason = { 'not-identical': [], 'file-unavailable': [], other: [] };
		blocked.forEach(b => {
			const r = (b.rows && b.rows[0]) || 'other';
			(byReason[r] || byReason.other).push(b.file);
		});
		if (byReason['not-identical'].length) showNotice(tr('blockedDiffFmt', byReason['not-identical'].join(', ')), 'warning');
		if (byReason['file-unavailable'].length) showNotice(tr('blockedGoneFmt', byReason['file-unavailable'].join(', ')), 'warning');
		if (byReason.other.length) showNotice(tr('blockedFmt', byReason.other.join(', ')), 'warning');
	}

	// ---- Screen-reader status ----
	// Only phase transitions and outcomes go here. The scan log next door
	// emits one line per file; announcing those would queue thousands of
	// utterances and make the page unusable with a screen reader.
	function announce(msg) {
		const el = $('#wpmj-status');
		if (el) el.textContent = msg;
	}

	// ---- Confirmation dialog ----
	// Replaces window.confirm for destructive actions. After the second
	// native confirm in a run, browsers offer "prevent this page from
	// creating more dialogs" - and once suppressed, confirm() silently
	// returns false, so a bulk action stops working with no explanation.
	// <dialog> also gives focus trapping and Escape-to-close for free, and
	// can show the user WHICH files are about to go.
	function wpmjConfirm(opts) {
		const dlg = $('#wpmj-dialog');
		if (!dlg || typeof dlg.showModal !== 'function') {
			return Promise.resolve(window.confirm(opts.message || ''));
		}
		$('#wpmj-dialog-title').textContent = opts.title || '';
		$('#wpmj-dialog-msg').textContent = opts.message || '';

		const list = $('#wpmj-dialog-files');
		if (opts.files && opts.files.length) {
			list.textContent = opts.files.slice(0, 200).join('\n')
				+ (opts.files.length > 200 ? '\n' + tr('whereMore') : '');
			list.hidden = false;
		} else {
			list.textContent = '';
			list.hidden = true;
		}

		const ok = $('#wpmj-dialog-ok');
		const cancel = $('#wpmj-dialog-cancel');
		ok.textContent = opts.okLabel || tr('dialogContinue');
		cancel.textContent = tr('dialogCancel');
		ok.className = 'button ' + (opts.destructive ? 'button-primary wpmj-danger-btn' : 'button-primary');

		return new Promise(resolve => {
			function done(val) {
				ok.removeEventListener('click', onOk);
				cancel.removeEventListener('click', onCancel);
				dlg.removeEventListener('close', onClose);
				if (dlg.open) dlg.close();
				resolve(val);
			}
			function onOk() { done(true); }
			function onCancel() { done(false); }
			function onClose() { done(false); }
			ok.addEventListener('click', onOk);
			cancel.addEventListener('click', onCancel);
			dlg.addEventListener('close', onClose);
			dlg.showModal();
			cancel.focus();
		});
	}

	// ---- Tab switching ----
	const TABS = ['duplicates', 'unused', 'trashed'];

	function selectTab(name, focusPanel) {
		TABS.forEach(t => {
			const tab = $('#wpmj-tab-' + t);
			const panel = $('#wpmj-panel-' + t);
			if (!tab || !panel) return;
			const on = t === name;
			tab.classList.toggle('nav-tab-active', on);
			tab.setAttribute('aria-selected', on ? 'true' : 'false');
			tab.tabIndex = on ? 0 : -1;
			panel.hidden = !on;
		});
		if (focusPanel) {
			const p = $('#wpmj-panel-' + name);
			if (p) p.focus();
		}
		if (name === 'trashed') loadTrash();
	}

	$$('[data-wpmj-tab]').forEach(tab => {
		tab.addEventListener('click', function(e) {
			e.preventDefault();
			selectTab(this.dataset.wpmjTab, true);
		});
		// Arrow-key navigation is what makes a tablist a tablist.
		tab.addEventListener('keydown', function(e) {
			const i = TABS.indexOf(this.dataset.wpmjTab);
			let next = null;
			if (e.key === 'ArrowRight') next = TABS[(i + 1) % TABS.length];
			else if (e.key === 'ArrowLeft') next = TABS[(i - 1 + TABS.length) % TABS.length];
			else if (e.key === 'Home') next = TABS[0];
			else if (e.key === 'End') next = TABS[TABS.length - 1];
			if (!next) return;
			e.preventDefault();
			selectTab(next, true);
			$('#wpmj-tab-' + next).focus();
		});
	});

	// ---- Scan flow ----
	async function runScan(btn, resume) {
		btn.disabled = true;
		const other = $(resume ? '#wpmj-scan-btn' : '#wpmj-resume-btn');
		if (other) other.disabled = true;

		$('#wpmj-progress').style.display = 'block';
		$('#wpmj-progress-title').textContent = tr('progInit');
		$('#wpmj-results').style.display = 'none';
		$('#wpmj-log').innerHTML = '';
		setBar(0);
		announce(tr('progInit'));

		try {
			let total, startPhase = 'hash', startCursor = { hash: 0, references: 0 };

			if (resume && WPMJ.resume) {
				total = WPMJ.resume.total;
				startPhase = WPMJ.resume.phase || 'hash';
				startCursor = WPMJ.resume.cursor || startCursor;
				log(tr('resumeFrom'), 'head');
			} else {
				log(tr('progInit'), 'head');
				const init = await wpmjAjax('scan_init');
				if (!init.success) throw new Error(init.data.message);
				total = init.data.total;
			}
			log(tr('logFoundFmt', total), 'ok');

			if (total === 0) {
				log(tr('logEmpty'), 'warn');
				announce(tr('logEmpty'));
				// An empty library is not a failed scan. Hide the progress
				// card rather than leaving a 0% bar looking like a crash.
				$('#wpmj-progress').style.display = 'none';
				showNotice(tr('logEmpty'));
				btn.disabled = false;
				if (other) other.disabled = false;
				return;
			}

			// Phase 1: Hash
			let offset = startCursor.hash || 0;
			let skipHash = (startPhase === 'references');
			$('#wpmj-progress-title').textContent = tr('progPhase1');
			announce(tr('progPhase1'));
			log(tr('progPhase1Long'), 'head');
			while (!skipHash) {
				const r = await wpmjAjax('scan_chunk', { offset: offset, phase: 'hash' });
				if (!r.success) throw new Error(r.data.message);
				const files = r.data.files || [];
				const baseScanned = r.data.scanned - files.length;
				files.forEach(function(f, i) {
					const n = baseScanned + i + 1;
					setBar((n / total * 100) * 0.6);
					log('[' + n + '/' + total + '] ' + f, f.endsWith('(missing)') ? 'warn' : '');
				});
				offset = r.data.next_offset;
				if (r.data.done) break;
			}
			log(tr('logHashDone'), 'ok');

			// Phase 2: References
			$('#wpmj-progress-title').textContent = tr('progPhase2');
			announce(tr('progPhase2'));
			log(tr('progPhase2Long'), 'head');
			offset = (startPhase === 'references') ? (startCursor.references || 0) : 0;
			while (true) {
				const r = await wpmjAjax('scan_chunk', { offset: offset, phase: 'references' });
				if (!r.success) throw new Error(r.data.message);
				const files = r.data.files || [];
				const baseScanned = r.data.scanned - files.length;
				const unusedSet = r.data.unused_in_batch || [];
				for (let i = 0; i < files.length; i++) {
					const n = baseScanned + i + 1;
					setBar(60 + (n / total * 100) * 0.4);
					const isUnused = unusedSet.includes(files[i]);
					log('[' + n + '/' + total + '] ' + files[i] + (isUnused ? tr('logUnusedSuffix') : tr('logRefSuffix')),
						isUnused ? 'warn' : '');
				}
				offset = r.data.next_offset;
				if (r.data.done) break;
			}
			log(tr('logRefDone'), 'ok');

			$('#wpmj-progress-title').textContent = tr('progComplete');
			log(tr('progLoading'), 'head');
			const results = await wpmjAjax('get_results');
			if (!results.success) throw new Error(results.data.message);

			setBar(100);
			const d = results.data;
			log(tr('logDoneFmt', d.duplicate_groups, d.unused_count), 'ok');
			announce(tr('logDoneFmt', d.duplicate_groups, d.unused_count));

			renderResults(d);
			$('#wpmj-clear-btn').style.display = 'inline-block';
			const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			$('#wpmj-results').scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });

		} catch (err) {
			log(tr('errPrefix') + ' ' + err.message, 'err');
			log(tr('resumeHint'), 'warn');
			announce(tr('errPrefix') + ' ' + err.message);
			showNotice(err.message, 'error');
		}

		btn.disabled = false;
		if (other) other.disabled = false;
	}

	$('#wpmj-scan-btn').addEventListener('click', function() { runScan(this, false); });
	const resumeBtn = $('#wpmj-resume-btn');
	if (resumeBtn) resumeBtn.addEventListener('click', function() { runScan(this, true); });

	// ---- Clear results ----
	$('#wpmj-clear-btn').addEventListener('click', async function() {
		if (!await wpmjConfirm({ title: tr('confirmClear'), message: '' })) return;
		await wpmjAjax('clear_results');
		$('#wpmj-results').style.display = 'none';
		$('#wpmj-savings').innerHTML = '';
		$('#wpmj-meta').textContent = '';
		$('#wpmj-panel-duplicates').innerHTML = '';
		$('#wpmj-panel-unused').innerHTML = '';
		this.style.display = 'none';
		showNotice(tr('noticeCleared'));
	});

	// ---- Render results ----
	let STATE = { unused: [], exportUrl: '', shown: 200, query: '', sort: 'size-desc',
	              libraryTotal: 0, ignoredCount: 0 };

	function renderResults(data) {
		$('#wpmj-results').style.display = 'block';
		STATE.unused = data.unused || [];
		STATE.exportUrl = data.export_url || '';
		STATE.shown = 200;
		STATE.query = '';
		STATE.sort = 'size-desc';

		renderSavings(data);

		// The meta line sits above the panels, so it may only say things that
		// are true on whichever tab is open. The unused-only facts ("6 of 355
		// items in your library", the ignore-list count) used to live here and
		// stayed on screen while the Trash or Duplicates tab was showing,
		// describing a number the user was not looking at. They now render
		// inside the unused panel, next to the list they describe.
		const bits = [];
		if (data.scanned_at) bits.push(tr('lastScannedFmt', data.scanned_at));
		// Attachments whose file is gone from disk are skipped rather than
		// hashed, so say so - otherwise they are silently absent from both
		// the duplicate and unused numbers with no explanation.
		if (data.missing_count) bits.push(tr('missingNoteFmt', data.missing_count));
		$('#wpmj-meta').textContent = bits.join('  ·  ');

		STATE.libraryTotal = data.library_total || 0;
		STATE.ignoredCount = data.ignored_count || 0;

		setBadge('#wpmj-dup-count', data.duplicate_groups);
		setBadge('#wpmj-unused-count', data.unused_count);
		setBadge('#wpmj-trash-count', data.trashed_count || 0, true);

		renderDuplicates(data.duplicates || []);
		renderUnused();
	}

	// Badge colour carries meaning, so it is decided in exactly one place.
	//   green  - nothing to do
	//   grey   - items exist but they are informational, not a problem (trash)
	//   red    - items the user is expected to act on (duplicates, unused)
	// `neutral` used to mean "always green", which painted a trash tab
	// holding 40 files pending permanent deletion as if it were empty.
	function setBadge(sel, n, neutral) {
		const b = $(sel);
		if (!b) return;
		b.textContent = n;
		b.className = 'wpmj-badge' + (n === 0 ? ' clean' : (neutral ? ' info' : ''));
	}

	function renderSavings(data) {
		const el = $('#wpmj-savings');
		const totalItems = (data.duplicate_count || 0) + (data.unused_count || 0);

		// The banner's glyph is the plugin's own icon, not an emoji. Emoji
		// render as a different typeface on every OS, carry no brand, and
		// look like a placeholder next to a drawn mark. WPMJ.iconUrl is an
		// empty string when the file is missing, in which case the banner
		// simply has no glyph rather than a broken image.
		const glyph = WPMJ.iconUrl
			? '<img class="spark" src="' + escHtml(WPMJ.iconUrl) + '" alt="" width="26" height="26"> '
			: '';

		if (totalItems === 0) {
			el.innerHTML =
				'<div class="wpmj-savings clean">' +
					'<div class="total">' + glyph + escHtml(tr('libClean')) + '</div>' +
					'<div class="breakdown">' + escHtml(tr('libCleanSub')) + '</div>' +
				'</div>';
			return;
		}

		const dupGroups = data.duplicate_groups || 0;
		const dupCount  = data.duplicate_count || 0;
		const unused    = data.unused_count || 0;
		const parts = [];
		if (dupCount) {
			parts.push(tr('breakdownDupFmt', dupCount, dupCount === 1 ? tr('duplicate') : tr('duplicates'),
				dupGroups, dupGroups === 1 ? tr('group') : tr('groups')));
		}
		if (unused) parts.push(tr('breakdownUnFmt', unused, unused === 1 ? tr('unusedFile') : tr('unusedFiles')));
		parts.push(tr('breakdownTotalFmt', totalItems));

		el.innerHTML =
			'<div class="wpmj-savings">' +
				'<div class="total">' + glyph + escHtml(tr('reclaimableFmt', data.reclaimable_human || '0 B')) + '</div>' +
				'<div class="breakdown">' + escHtml(parts.join(' · ')) + '</div>' +
			'</div>';
	}

	function showNotice(msg, type) {
		const wrap = document.querySelector('.wrap');
		const notice = document.createElement('div');
		notice.className = 'notice notice-' + (type || 'success') + ' is-dismissible';
		notice.setAttribute('role', 'status');
		notice.style.marginTop = '10px';
		const p = document.createElement('p');
		p.textContent = msg;
		notice.appendChild(p);
		wrap.insertBefore(notice, wrap.firstChild.nextSibling);
		announce(msg);
		// Errors stay put. A message that vanishes after 2.7 seconds is one
		// a keyboard user can never reach and a screen reader may never finish.
		if (type !== 'error') {
			setTimeout(() => {
				notice.style.transition = 'opacity .3s';
				notice.style.opacity = '0';
				setTimeout(() => notice.remove(), 300);
			}, 4000);
		}
	}

	function thumbHtml(item) {
		const editUrl = WPMJ.adminUrl + 'post.php?post=' + item.id + '&action=edit';
		const inner = item.thumb
			? '<img class="thumb" loading="lazy" decoding="async" src="' + escHtml(item.thumb) + '" alt="">'
			: '<div class="no-thumb">' + escHtml(String(item.filename || '').split('.').pop().toUpperCase()) + '</div>';
		return '<a class="thumb-link" href="' + escHtml(editUrl) + '" target="_blank" rel="noopener" title="'
			+ escHtml(tr('thumbTitleFmt', item.id)) + '">' + inner + '</a>';
	}

	function escHtml(str) {
		const d = document.createElement('div');
		d.textContent = (str === undefined || str === null) ? '' : str;
		return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	// Where-is-this-used drill-down. The reference count is the whole basis
	// on which a user picks a keeper, so it has to be inspectable.
	function refsHtml(item) {
		const n = item.ref_count || 0;
		const posts = item.ref_posts || [];
		if (!n) return '<span class="wpmj-count">' + escHtml(tr('whereNone')) + '</span>';
		if (!posts.length) return String(n);

		let h = '<details class="wpmj-refs"><summary>' + escHtml(tr('whereUsedFmt', n)) + '</summary><ul>';
		posts.forEach(function(p) {
			const label = escHtml(p.title) + ' <span class="via">(' + escHtml(p.via) + ')</span>';
			h += '<li>' + (p.edit ? '<a href="' + escHtml(p.edit) + '" target="_blank" rel="noopener">' + label + '</a>' : label) + '</li>';
		});
		if (n > posts.length) h += '<li class="via">' + escHtml(tr('whereMore')) + '</li>';
		h += '</ul></details>';
		return h;
	}

	function renderDuplicates(groups) {
		const panel = $('#wpmj-panel-duplicates');
		if (!groups.length) {
			panel.innerHTML = '<div class="wpmj-empty">' + escHtml(tr('noDups')) + '</div>';
			return;
		}

		let html = '<div class="wpmj-toolbar">';
		html += '<button class="button button-primary" id="wpmj-resolve-all-btn">' + escHtml(tr('resolveAll')) + '</button>';
		html += '<span class="wpmj-count">' + escHtml(tr('resolveAllHint')) + '</span>';
		html += '<span id="wpmj-resolve-all-status"></span>';
		html += '<span class="spacer"></span>';
		html += exportBtnHtml();
		html += '</div>';

		groups.forEach(function(group, gi) {
			html += '<div class="wpmj-group" data-group="' + gi + '">';
			html += '<h3>' + escHtml(tr('dupGroupHeadFmt', gi + 1, group.items[0].filename, group.items.length, group.items[0].size)) + '</h3>';
			html += '<div class="wpmj-tablewrap"><table class="wpmj-table"><thead><tr>'
				+ '<th><span class="screen-reader-text">' + escHtml(tr('colPreview')) + '</span></th>'
				+ '<th>' + escHtml(tr('colKeep')) + '</th>'
				+ '<th>' + escHtml(tr('colFile')) + '</th>'
				+ '<th>' + escHtml(tr('colId')) + '</th>'
				+ '<th class="wpmj-col-optional">' + escHtml(tr('colDimensions')) + '</th>'
				+ '<th>' + escHtml(tr('colSize')) + '</th>'
				+ '<th>' + escHtml(tr('colReferences')) + '</th>'
				+ '<th class="wpmj-col-optional">' + escHtml(tr('colUploaded')) + '</th>'
				+ '</tr></thead><tbody>';
			group.items.forEach(function(item, ii) {
				html += '<tr>';
				html += '<td>' + thumbHtml(item) + '</td>';
				html += '<td><input type="radio" name="wpmj-keep-' + gi + '" value="' + item.id + '"'
					+ (ii === 0 ? ' checked' : '') + ' aria-label="' + escHtml(tr('keepFileFmt', item.filename)) + '"></td>';
				html += '<td><strong>' + escHtml(item.filename) + '</strong></td>';
				html += '<td>#' + item.id + '</td>';
				html += '<td class="wpmj-col-optional">' + escHtml(item.dimensions || '-') + '</td>';
				html += '<td>' + escHtml(item.size) + '</td>';
				html += '<td>' + refsHtml(item) + '</td>';
				html += '<td class="wpmj-col-optional">' + escHtml(item.date) + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table></div>';
			html += '<div class="wpmj-actions"><button class="button button-primary wpmj-resolve-btn">' + escHtml(tr('btnResolve')) + '</button><span class="wpmj-resolve-status"></span></div>';
			html += '</div>';
		});

		panel.innerHTML = html;
	}

	function exportBtnHtml() {
		if (!STATE.exportUrl) return '';
		return '<a class="button" href="' + escHtml(STATE.exportUrl) + '" title="' + escHtml(tr('exportHint')) + '">'
			+ escHtml(tr('btnExportCsv')) + '</a>';
	}

	// ---- Unused panel: search, sort, paginate ----
	function visibleUnused() {
		const q = String(STATE.query || '').trim().toLowerCase();
		const sort = STATE.sort || 'size-desc';
		let items = STATE.unused.slice();
		if (q) items = items.filter(i => String(i.filename).toLowerCase().indexOf(q) !== -1);
		items.sort(function(a, b) {
			switch (sort) {
				case 'size-asc':  return (a.bytes || 0) - (b.bytes || 0);
				case 'date-desc': return String(b.date).localeCompare(String(a.date));
				case 'date-asc':  return String(a.date).localeCompare(String(b.date));
				case 'name':      return String(a.filename).localeCompare(String(b.filename));
				default:          return (b.bytes || 0) - (a.bytes || 0);
			}
		});
		return items;
	}

	function renderUnused() {
		const panel = $('#wpmj-panel-unused');
		const all = STATE.unused;

		if (!all.length) {
			panel.innerHTML = '<div class="wpmj-empty">' + escHtml(tr('noUnused')) + '</div>';
			return;
		}

		const matching = visibleUnused();
		const items = matching.slice(0, STATE.shown);

		let html = '<div class="wpmj-warn">' + escHtml(tr('mediaWarn')) + '</div>';

		// Unused-only context, kept beside the list it describes.
		const unusedBits = [];
		if (STATE.libraryTotal) unusedBits.push(tr('ofLibraryFmt', all.length, STATE.libraryTotal));
		if (STATE.ignoredCount) unusedBits.push(tr('ignoredNoteFmt', STATE.ignoredCount));
		if (unusedBits.length) html += '<div class="wpmj-meta">' + escHtml(unusedBits.join('  ·  ')) + '</div>';

		html += '<div class="wpmj-toolbar">';
		// Demoted from button-primary and relabelled. It ignores the
		// checkboxes entirely, and sitting above "Delete Selected" as the
		// big blue button it was the one a user reached for after carefully
		// unchecking the files they wanted to keep.
		html += '<button class="button" id="wpmj-cleanup-all-btn">' + escHtml(tr('btnTrashAllFmt', all.length)) + '</button>';
		html += '<span id="wpmj-cleanup-all-status"></span>';
		html += '<span class="spacer"></span>';
		html += '<label class="screen-reader-text" for="wpmj-search">' + escHtml(tr('search')) + '</label>';
		html += '<input type="search" id="wpmj-search" value="' + escHtml(STATE.query) + '" placeholder="' + escHtml(tr('search')) + '">';
		html += '<label class="screen-reader-text" for="wpmj-sort">' + escHtml(tr('sortBy')) + '</label>';
		const sortOpts = [
			['size-desc', tr('sortLargest')], ['size-asc', tr('sortSmallest')],
			['date-desc', tr('sortNewest')],  ['date-asc', tr('sortOldest')],
			['name', tr('sortName')],
		];
		html += '<select id="wpmj-sort">'
			+ sortOpts.map(o => '<option value="' + o[0] + '"'
				+ (STATE.sort === o[0] ? ' selected' : '') + '>' + escHtml(o[1]) + '</option>').join('')
			+ '</select>';
		html += exportBtnHtml();
		html += '</div>';

		if (!matching.length) {
			html += '<div class="wpmj-empty">' + escHtml(tr('noMatches')) + '</div>';
			panel.innerHTML = html;
			wireUnusedToolbar();
			return;
		}

		html += '<div style="margin-bottom:10px"><label><input type="checkbox" id="wpmj-select-all"> '
			+ escHtml(tr('selectAllVisible')) + '</label> <span class="wpmj-count">'
			+ escHtml(tr('showingFmt', items.length, matching.length)) + '</span></div>';

		html += '<div class="wpmj-tablewrap"><table id="wpmj-unused-table" class="wpmj-table"><thead><tr>'
			+ '<th><span class="screen-reader-text">' + escHtml(tr('colSelect')) + '</span></th>'
			+ '<th><span class="screen-reader-text">' + escHtml(tr('colPreview')) + '</span></th>'
			+ '<th>' + escHtml(tr('colFile')) + '</th>'
			+ '<th>' + escHtml(tr('colId')) + '</th>'
			+ '<th class="wpmj-col-optional">' + escHtml(tr('colType')) + '</th>'
			+ '<th>' + escHtml(tr('colSize')) + '</th>'
			+ '<th class="wpmj-col-optional">' + escHtml(tr('colUploaded')) + '</th>'
			+ '<th><span class="screen-reader-text">' + escHtml(tr('colActions')) + '</span></th>'
			+ '</tr></thead><tbody>';
		items.forEach(function(item) {
			html += '<tr data-id="' + item.id + '">';
			html += '<td><input type="checkbox" class="wpmj-unused-cb" value="' + item.id + '" aria-label="'
				+ escHtml(tr('selectFileFmt', item.filename)) + '"></td>';
			html += '<td>' + thumbHtml(item) + '</td>';
			html += '<td><strong>' + escHtml(item.filename) + '</strong></td>';
			html += '<td>#' + item.id + '</td>';
			html += '<td class="wpmj-col-optional">' + escHtml(item.mime || '-') + '</td>';
			html += '<td>' + escHtml(item.size) + '</td>';
			html += '<td class="wpmj-col-optional">' + escHtml(item.date) + '</td>';
			html += '<td><button class="button button-small wpmj-ignore-btn" data-id="' + item.id + '" title="'
				+ escHtml(tr('ignoreTitle')) + '">' + escHtml(tr('btnIgnore')) + '</button></td>';
			html += '</tr>';
		});
		html += '</tbody></table></div>';

		if (matching.length > items.length) {
			html += '<div class="wpmj-pager"><button class="button" id="wpmj-more-btn">' + escHtml(tr('btnLoadMore'))
				+ '</button><span class="wpmj-count">' + escHtml(tr('showingFmt', items.length, matching.length)) + '</span></div>';
		}

		html += '<div class="wpmj-actions"><button class="button button-primary" id="wpmj-delete-unused-btn">'
			+ escHtml(tr('btnTrashSelected')) + '</button><span id="wpmj-delete-status"></span></div>';

		panel.innerHTML = html;
		wireUnusedToolbar();
	}

	function wireUnusedToolbar() {
		const search = $('#wpmj-search');
		if (search) {
			let t = null;
			search.addEventListener('input', function() {
				clearTimeout(t);
				t = setTimeout(function() {
					const pos = search.selectionStart;
					STATE.query = search.value;
					STATE.shown = 200;
					renderUnused();
					const s2 = $('#wpmj-search');
					if (s2) { s2.focus(); s2.setSelectionRange(pos, pos); }
				}, 200);
			});
		}
		const sort = $('#wpmj-sort');
		if (sort) sort.addEventListener('change', function() {
			STATE.sort = sort.value;
			STATE.shown = 200;
			renderUnused();
		});

		const more = $('#wpmj-more-btn');
		if (more) more.addEventListener('click', function() { STATE.shown += 200; renderUnused(); });

		// "Select all shown" means exactly that - the rows currently
		// rendered, not every match hidden behind pagination.
		const all = $('#wpmj-select-all');
		if (all) all.addEventListener('change', function() {
			$$('.wpmj-unused-cb').forEach(cb => { cb.checked = all.checked; });
		});
	}

	// ---- Trash panel ----
	async function loadTrash() {
		const panel = $('#wpmj-panel-trashed');
		if (panel.dataset.loading === '1') return;
		panel.dataset.loading = '1';
		if (!panel.innerHTML) panel.innerHTML = '<div class="wpmj-empty">' + escHtml(tr('trashLoading')) + '</div>';

		let r;
		try {
			r = await wpmjAjax('list_trash');
		} catch (err) {
			// The flag MUST come off on the error path too. Leaving it set
			// made every later click on the tab return early in silence.
			panel.dataset.loading = '0';
			panel.innerHTML = '<div class="wpmj-empty">' + escHtml(err.message) + '</div>';
			return;
		}
		panel.dataset.loading = '0';
		if (!r.success) { panel.innerHTML = '<div class="wpmj-empty">' + escHtml(r.data.message || '') + '</div>'; return; }

		const items = r.data.items || [];
		setBadge('#wpmj-trash-count', r.data.count || 0, true);

		if (!items.length) {
			panel.innerHTML = '<div class="wpmj-empty">' + escHtml(tr('trashEmpty')) + '</div>';
			return;
		}

		let html = '<div class="wpmj-trash-note">' + escHtml(tr('trashNote')) + '</div>';
		html += '<div class="wpmj-toolbar">';
		html += '<button class="button" id="wpmj-restore-sel-btn">' + escHtml(tr('btnRestoreSel')) + '</button>';
		html += '<button class="button" id="wpmj-empty-trash-btn">' + escHtml(tr('btnEmptyTrash')) + '</button>';
		html += '<span id="wpmj-trash-status"></span>';
		html += '<span class="spacer"></span>';
		html += '<span class="wpmj-count">' + escHtml(tr('reclaimableFmt', r.data.bytes_human)) + '</span>';
		html += '</div>';
		html += '<div class="wpmj-tablewrap"><table id="wpmj-trash-table" class="wpmj-table"><thead><tr>'
			+ '<th><span class="screen-reader-text">' + escHtml(tr('colSelect')) + '</span></th>'
			+ '<th><span class="screen-reader-text">' + escHtml(tr('colPreview')) + '</span></th>'
			+ '<th>' + escHtml(tr('colFile')) + '</th>'
			+ '<th>' + escHtml(tr('colId')) + '</th>'
			+ '<th>' + escHtml(tr('colSize')) + '</th>'
			+ '<th class="wpmj-col-optional">' + escHtml(tr('colTrashedAt')) + '</th>'
			+ '<th><span class="screen-reader-text">' + escHtml(tr('colActions')) + '</span></th>'
			+ '</tr></thead><tbody>';
		items.forEach(function(item) {
			html += '<tr data-id="' + item.id + '">';
			html += '<td><input type="checkbox" class="wpmj-trash-cb" value="' + item.id + '" aria-label="'
				+ escHtml(tr('selectFileFmt', item.filename)) + '"></td>';
			html += '<td>' + thumbHtml(item) + '</td>';
			html += '<td><strong>' + escHtml(item.filename) + '</strong></td>';
			html += '<td>#' + item.id + '</td>';
			html += '<td>' + escHtml(item.size) + '</td>';
			html += '<td class="wpmj-col-optional">' + escHtml(item.trashed_at || '') + '</td>';
			html += '<td><button class="button button-small wpmj-restore-btn" data-id="' + item.id + '">'
				+ escHtml(tr('btnRestore')) + '</button></td>';
			html += '</tr>';
		});
		html += '</tbody></table></div>';
		panel.innerHTML = html;
	}

	// ---- Resolve one duplicate group ----
	document.addEventListener('click', guard(async function(e) {
		if (!e.target.classList.contains('wpmj-resolve-btn')) return;
		const group = e.target.closest('.wpmj-group');
		const keeper = group.querySelector('input[type=radio]:checked');
		if (!keeper) { showNotice(tr('selectKeeper'), 'error'); return; }

		const keeperId = keeper.value;
		const dupIds = [];
		group.querySelectorAll('input[type=radio]').forEach(function(r) {
			if (r.value !== keeperId) dupIds.push(r.value);
		});
		if (!dupIds.length) return;

		if (!await wpmjConfirm({
			title: tr('btnResolve'),
			message: tr('confirmResolveFmt', keeperId, dupIds.length),
			destructive: true,
			okLabel: tr('dialogDelete')
		})) return;

		const btn = e.target;
		const status = group.querySelector('.wpmj-resolve-status');
		btn.disabled = true;
		btn.textContent = tr('btnResolving');
		status.textContent = '';

		const r = await wpmjAjax('resolve_duplicate', { keeper_id: keeperId, duplicate_ids: dupIds.join(',') });

		if (r.success) {
			const blocked = r.data.blocked || [];
			const done = (r.data.deleted > 0) && !blocked.length;

			status.textContent = tr('resolveStatusFmt', r.data.deleted, r.data.refs_updated);
			status.style.color = done ? '#00a32a' : '#dba617';
			announce(status.textContent);
			reportBlocked(blocked);

			if (done) {
				group.dataset.resolved = 'true';
				btn.textContent = tr('btnResolved');
				btn.disabled = true;
			} else {
				// Leave the group live and retryable. The server keeps it in
				// the results transient precisely so a retry can work once
				// the offending row or file is dealt with.
				btn.disabled = false;
				btn.textContent = tr('btnResolve');
				return;
			}
			// Go through setBadge rather than reimplementing it. The copy
			// that used to live here only ever added the clean class and
			// never removed it, so a badge that reached zero and then went
			// back up stayed green.
			setBadge('#wpmj-dup-count', document.querySelectorAll('.wpmj-group:not([data-resolved="true"])').length);
		} else {
			btn.disabled = false;
			btn.textContent = tr('btnResolve');
			status.textContent = tr('errPrefix') + ' ' + r.data.message;
			status.style.color = '#d63638';
			announce(status.textContent);
		}
	}));

	// ---- Trash / delete selected unused ----
	document.addEventListener('click', guard(async function(e) {
		if (e.target.id !== 'wpmj-delete-unused-btn') return;
		const checked = document.querySelectorAll('.wpmj-unused-cb:checked');
		if (!checked.length) { showNotice(tr('noSelection'), 'error'); return; }

		const ids = [];
		const names = [];
		checked.forEach(function(cb) {
			ids.push(cb.value);
			const row = cb.closest('tr');
			const strong = row ? row.querySelector('strong') : null;
			if (strong) names.push(strong.textContent);
		});

		if (!await wpmjConfirm({
			title: tr('btnTrashSelected'),
			message: tr('confirmTrashFmt', ids.length),
			files: names
		})) return;

		const btn = e.target;
		const status = $('#wpmj-delete-status');
		btn.disabled = true;
		btn.textContent = tr('btnTrashing');
		status.textContent = '';

		const r = await wpmjAjax('delete_unused', { ids: ids.join(','), mode: 'trash' });

		if (r.success) {
			const skipped = r.data.skipped || [];
			const skippedIds = skipped.map(s => String(s.id));
			const gone = ids.filter(id => skippedIds.indexOf(String(id)) === -1);
			STATE.unused = STATE.unused.filter(i => gone.indexOf(String(i.id)) === -1);
			renderUnused();
			setBadge('#wpmj-unused-count', STATE.unused.length);
			loadTrash();

			let msg = tr('filesTrashedFmt', r.data.deleted);
			if (skipped.length) msg += ' ' + tr('skippedFmt', skipped.length);
			showNotice(msg, skipped.length ? 'warning' : 'success');
		} else {
			status.textContent = tr('errPrefix') + ' ' + r.data.message;
			status.style.color = '#d63638';
			showNotice(r.data.message, 'error');
			btn.disabled = false;
			btn.textContent = tr('btnTrashSelected');
		}
	}));

	// ---- Ignore ----
	document.addEventListener('click', guard(async function(e) {
		if (!e.target.classList.contains('wpmj-ignore-btn')) return;
		const id = e.target.dataset.id;
		e.target.disabled = true;
		const r = await wpmjAjax('toggle_ignore', { ids: id, ignore: '1' });
		if (r.success) {
			STATE.unused = STATE.unused.filter(i => String(i.id) !== String(id));
			renderUnused();
			setBadge('#wpmj-unused-count', STATE.unused.length);
			showNotice(tr('ignoredFmt', 1));
		} else {
			e.target.disabled = false;
			showNotice(r.data.message, 'error');
		}
	}));

	// ---- Restore from trash ----
	document.addEventListener('click', guard(async function(e) {
		const single = e.target.classList.contains('wpmj-restore-btn');
		const bulk = e.target.id === 'wpmj-restore-sel-btn';
		if (!single && !bulk) return;

		let ids = [];
		if (single) ids = [e.target.dataset.id];
		else document.querySelectorAll('.wpmj-trash-cb:checked').forEach(cb => ids.push(cb.value));
		if (!ids.length) { showNotice(tr('noSelection'), 'error'); return; }

		e.target.disabled = true;
		const r = await wpmjAjax('restore_trash', { ids: ids.join(',') });
		e.target.disabled = false;
		if (r.success) {
			showNotice(tr('restoredFmt', r.data.restored));
			loadTrash();
		} else {
			showNotice(r.data.message, 'error');
		}
	}));

	// ---- Empty trash ----
	document.addEventListener('click', guard(async function(e) {
		if (e.target.id !== 'wpmj-empty-trash-btn') return;
		const ids = [];
		const names = [];
		document.querySelectorAll('.wpmj-trash-cb:checked').forEach(function(cb) {
			ids.push(cb.value);
			const row = cb.closest('tr');
			const strong = row ? row.querySelector('strong') : null;
			if (strong) names.push(strong.textContent);
		});
		const count = ids.length || document.querySelectorAll('.wpmj-trash-cb').length;
		if (!count) return;

		if (!await wpmjConfirm({
			title: tr('btnEmptyTrash'),
			message: tr('confirmEmptyFmt', count),
			files: names,
			destructive: true,
			okLabel: tr('dialogDelete')
		})) return;

		e.target.disabled = true;
		const r = await wpmjAjax('empty_trash', ids.length ? { ids: ids.join(',') } : {});
		e.target.disabled = false;
		if (r.success) {
			showNotice(tr('emptiedFmt', r.data.deleted));
			loadTrash();
		} else {
			showNotice(r.data.message, 'error');
		}
	}));

	// ---- Resolve All duplicates ----
	document.addEventListener('click', guard(async function(e) {
		if (e.target.id !== 'wpmj-resolve-all-btn') return;

		const groups = document.querySelectorAll('.wpmj-group');
		const active = [...groups].filter(g => g.dataset.resolved !== 'true');
		if (!active.length) { showNotice(tr('noUnresolved'), 'error'); return; }

		if (!await wpmjConfirm({
			title: tr('resolveAll'),
			message: tr('confirmResolveAllFmt', active.length),
			destructive: true,
			okLabel: tr('dialogDelete')
		})) return;

		const btn = e.target;
		const status = $('#wpmj-resolve-all-status');
		btn.disabled = true;
		btn.textContent = tr('btnResolving');
		status.textContent = '';

		let resolved = 0, errors = 0, lastError = '', allBlocked = [];

		for (const group of active) {
			const keeper = group.querySelector('input[type=radio]:checked');
			if (!keeper) continue;
			const keeperId = keeper.value;
			const dupIds = [];
			group.querySelectorAll('input[type=radio]').forEach(function(r) {
				if (r.value !== keeperId) dupIds.push(r.value);
			});
			if (!dupIds.length) continue;

			btn.textContent = tr('btnResolvingFmt', resolved + 1, active.length);
			const r = await wpmjAjax('resolve_duplicate', { keeper_id: keeperId, duplicate_ids: dupIds.join(',') });

			if (r.success) {
				const gBlocked = r.data.blocked || [];
				gBlocked.forEach(b => allBlocked.push(b));
				if (!(r.data.deleted > 0) || gBlocked.length) {
					// Blocked groups stay live and retryable.
					errors++;
					continue;
				}
				group.dataset.resolved = 'true';
				const gb = group.querySelector('.wpmj-resolve-btn');
				const gs = group.querySelector('.wpmj-resolve-status');
				if (gb) { gb.textContent = tr('btnResolved'); gb.disabled = true; }
				if (gs) {
					gs.textContent = tr('resolveStatusFmt', r.data.deleted, r.data.refs_updated);
					gs.style.color = '#00a32a';
				}
				resolved++;
			} else {
				errors++;
				lastError = r.data.message || '';
				// A stale results transient fails every remaining group the
				// same way, so stop rather than firing N more doomed calls.
				if (r.data.code === 'results_expired' || r.data.code === 'bad_nonce') break;
			}
		}

		const remaining = document.querySelectorAll('.wpmj-group:not([data-resolved="true"])').length;
		setBadge('#wpmj-dup-count', remaining);

		if (remaining === 0 && !errors) {
			btn.textContent = tr('btnAllDone');
			btn.disabled = true;
		} else {
			btn.disabled = false;
			btn.textContent = tr('resolveAll');
		}
		status.textContent = errors ? tr('resolveAllSummaryErrorFmt', resolved, errors) : tr('resolveAllSummaryFmt', resolved);
		status.style.color = errors ? '#dba617' : '#00a32a';
		if (errors && lastError) showNotice(lastError, 'error');
		reportBlocked(allBlocked);
		announce(status.textContent);
	}));

	// ---- Trash All unused ----
	document.addEventListener('click', guard(async function(e) {
		if (e.target.id !== 'wpmj-cleanup-all-btn') return;

		const allIds = STATE.unused.map(i => String(i.id));
		if (!allIds.length) { showNotice(tr('noUnusedToCleanup'), 'error'); return; }

		if (!await wpmjConfirm({
			title: tr('btnTrashAllFmt', allIds.length),
			message: tr('confirmTrashFmt', allIds.length),
			files: STATE.unused.map(i => i.filename)
		})) return;

		const btn = e.target;
		const status = $('#wpmj-cleanup-all-status');
		btn.disabled = true;
		status.textContent = '';

		let done = 0, skipped = 0, failed = '';

		for (let i = 0; i < allIds.length; i += 25) {
			const batch = allIds.slice(i, i + 25);
			btn.textContent = tr('btnDeletingFmt', Math.min(i + 25, allIds.length), allIds.length);

			const r = await wpmjAjax('delete_unused', { ids: batch.join(','), mode: 'trash' });
			if (r.success) {
				done += r.data.deleted;
				skipped += (r.data.skipped || []).length;
				const skippedIds = (r.data.skipped || []).map(s => String(s.id));
				const gone = batch.filter(id => skippedIds.indexOf(id) === -1);
				STATE.unused = STATE.unused.filter(it => gone.indexOf(String(it.id)) === -1);
			} else {
				// Previously this had no else branch at all: an expired
				// transient made every batch fail, nothing was removed, and
				// the button simply reset with no message.
				failed = r.data.message || '';
				break;
			}
		}

		renderUnused();
		setBadge('#wpmj-unused-count', STATE.unused.length);
		loadTrash();
		btn.disabled = false;
		btn.textContent = tr('btnTrashAllFmt', STATE.unused.length);

		if (failed) {
			showNotice(failed, 'error');
		} else {
			let msg = tr('filesTrashedFmt', done);
			if (skipped) msg += ' ' + tr('skippedFmt', skipped);
			showNotice(msg, skipped ? 'warning' : 'success');
		}
	}));

	// ---- Auto-load existing results on page load ----
	if (WPMJ.hasResults) {
		$('#wpmj-results').style.display = 'block';
		$('#wpmj-savings').innerHTML =
			'<div class="wpmj-savings" style="background:#f0f0f1;color:#666;box-shadow:none">' +
				'<div class="total" style="font-size:16px;font-weight:400">' + escHtml(tr('loadingPrev')) + '</div>' +
			'</div>';
		(async function() {
			const results = await wpmjAjax('get_results');
			if (results.success) {
				renderResults(results.data);
			} else {
				$('#wpmj-savings').innerHTML = '';
				$('#wpmj-results').style.display = 'none';
			}
		})();
	}

})();
