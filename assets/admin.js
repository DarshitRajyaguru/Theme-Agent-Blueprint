(function () {
	'use strict';
	document.addEventListener('DOMContentLoaded', function () {
		var viewContent = document.getElementById('tab-view-content');
		var viewCache = {};
		var loadingViews = {};
		if (viewContent) viewCache[viewContent.dataset.currentView] = viewContent.innerHTML;

		function createProgress(title, detail) {
			var overlay = document.createElement('div');
			overlay.className = 'tab-progress-overlay';
			overlay.setAttribute('aria-hidden', 'false');
			overlay.innerHTML = '<div class="tab-progress-dialog" role="status" aria-live="polite"><div class="tab-progress-orb"><div class="tab-progress-glow"></div><div class="tab-progress-water"></div><div class="tab-progress-ripple"></div><div class="tab-progress-bubbles" aria-hidden="true"><span></span><span></span><span></span></div><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><strong>0%</strong></div><h2></h2><p></p><div class="tab-progress-track"><span></span></div></div>';
			overlay.querySelector('h2').textContent = title;
			overlay.querySelector('p').textContent = detail;
			document.body.appendChild(overlay);
			requestAnimationFrame(function () { overlay.classList.add('is-visible'); });
			return overlay;
		}

		function setProgress(overlay, percent, detail) {
			percent = Math.max(0, Math.min(100, Math.round(percent)));
			overlay.querySelector('.tab-progress-water').style.height = percent + '%';
			overlay.querySelector('.tab-progress-orb strong').textContent = percent + '%';
			overlay.querySelector('.tab-progress-track span').style.width = percent + '%';
			if (detail) overlay.querySelector('p').textContent = detail;
		}

		function closeProgress(overlay) {
			if (!overlay) return;
			overlay.classList.remove('is-visible');
			overlay.setAttribute('aria-hidden', 'true');
			window.setTimeout(function () { overlay.remove(); }, 180);
		}

		function showPassiveProgress(title, detail) {
			var overlay = createProgress(title, detail);
			var percent = 14;
			setProgress(overlay, percent, detail);
			var timer = window.setInterval(function () {
				percent = Math.min(78, percent + (percent < 52 ? 9 : 4));
				setProgress(overlay, percent, detail);
			}, 420);
			return {
				overlay: overlay,
				stop: function () {
					window.clearInterval(timer);
					setProgress(overlay, 100, 'Done.');
					window.setTimeout(function () { closeProgress(overlay); }, 250);
				}
			};
		}

		function formEndpoint(form) {
			return form.getAttribute('action') || window.location.href;
		}

		function showResponseNotices(html) {
			var parsed = new DOMParser().parseFromString(html, 'text/html');
			var incoming = parsed.querySelectorAll('.tab-wrap > .notice, .tab-wrap > .updated, .tab-wrap > .error');
			var hero = document.querySelector('.tab-wrap > .tab-hero');
			if (!hero || !incoming.length) return null;
			var hasError = false;
			document.querySelectorAll('.tab-wrap > .notice, .tab-wrap > .updated, .tab-wrap > .error').forEach(function (notice) { notice.remove(); });
			incoming.forEach(function (notice) {
				hasError = hasError || notice.classList.contains('notice-error') || notice.classList.contains('error');
				hero.parentNode.insertBefore(document.importNode(notice, true), hero);
			});
			return { hasError: hasError };
		}

		function importTemplate(form) {
			var overlay = createProgress('Importing template', 'Preparing upload...');
			var request = new XMLHttpRequest();
			var simulated = 66;
			var timer;
			request.open('POST', formEndpoint(form), true);
			request.upload.addEventListener('progress', function (event) {
				if (event.lengthComputable) setProgress(overlay, Math.max(4, event.loaded / event.total * 65), 'Uploading template package...');
			});
			request.upload.addEventListener('load', function () {
				setProgress(overlay, simulated, 'Validating package and paths...');
				timer = window.setInterval(function () {
					simulated = Math.min(94, simulated + (simulated < 82 ? 2 : 1));
					var detail = simulated < 76 ? 'Validating package and paths...' : simulated < 86 ? 'Building template and custom fields...' : simulated < 94 ? 'Merging assets and creating restore snapshot...' : 'Finalizing import...';
					setProgress(overlay, simulated, detail);
				}, 450);
			});
			request.addEventListener('load', function () {
				window.clearInterval(timer);
				var result = showResponseNotices(request.responseText);
				if (request.status < 200 || request.status >= 300 || !result) {
					closeProgress(overlay);
					window.location.assign(request.responseURL || formEndpoint(form));
					return;
				}
				setProgress(overlay, 100, result.hasError ? 'Import stopped. Review the error above.' : 'Import complete.');
				if (!result.hasError) {
					delete viewCache.history;
					form.reset();
				}
				window.setTimeout(function () { closeProgress(overlay); }, 420);
			});
			request.addEventListener('error', function () { window.clearInterval(timer); closeProgress(overlay); form.submit(); });
			request.send(new FormData(form));
		}

		function downloadAgent(form) {
			var overlay = createProgress('Generating agent file', 'Reading project profile...');
			var request = new XMLHttpRequest();
			var percent = 18;
			var timer = window.setInterval(function () {
				percent = Math.min(88, percent + 7);
				setProgress(overlay, percent, percent < 55 ? 'Building theme-aware instructions...' : 'Preparing Markdown download...');
			}, 220);
			request.open('POST', formEndpoint(form), true);
			request.responseType = 'blob';
			request.addEventListener('load', function () {
				window.clearInterval(timer);
				if (request.status < 200 || request.status >= 300) { closeProgress(overlay); form.submit(); return; }
				setProgress(overlay, 100, 'Agent file ready.');
				var match = (request.getResponseHeader('Content-Disposition') || '').match(/filename="?([^";]+)"?/i);
				var link = document.createElement('a');
				link.href = URL.createObjectURL(request.response);
				link.download = match ? match[1] : 'theme-agent.md';
				document.body.appendChild(link);
				link.click();
				var objectUrl = link.href;
				link.remove();
				window.setTimeout(function () { URL.revokeObjectURL(objectUrl); closeProgress(overlay); }, 450);
			});
			request.addEventListener('error', function () { window.clearInterval(timer); closeProgress(overlay); form.submit(); });
			request.send(new FormData(form));
		}

		function bindForms(root) {
			root = root || document;
			var form = root.querySelector('.tab-form');
			if (form && !form.dataset.tabBound) {
				form.dataset.tabBound = '1';
				form.addEventListener('submit', function () {
					var button = form.querySelector('button[type="submit"]');
					if (button) { button.disabled = true; button.classList.add('is-busy'); button.innerHTML = '<span class="dashicons dashicons-update" aria-hidden="true"></span><span>Saving profile...</span>'; }
					showPassiveProgress('Saving project profile', 'Updating theme agent settings...');
				});
			}
			var importForm = root.querySelector('.tab-import-form');
			if (importForm && !importForm.dataset.tabBound) {
				importForm.dataset.tabBound = '1';
				importForm.addEventListener('submit', function (event) { if (!window.XMLHttpRequest || !window.FormData) return; event.preventDefault(); importTemplate(importForm); });
			}
			var downloadForm = root.querySelector('.tab-download-form');
			if (downloadForm && !downloadForm.dataset.tabBound) {
				downloadForm.dataset.tabBound = '1';
				downloadForm.addEventListener('submit', function (event) { if (!window.XMLHttpRequest || !window.FormData || !window.URL) return; event.preventDefault(); downloadAgent(downloadForm); });
			}
			root.querySelectorAll('.tab-history-action').forEach(function (historyForm) {
				if (historyForm.dataset.tabBound) return;
				historyForm.dataset.tabBound = '1';
				historyForm.addEventListener('submit', function (event) {
					var button = historyForm.querySelector('button[type="submit"]');
					if (button && button.dataset.confirm && !window.confirm(button.dataset.confirm)) { event.preventDefault(); return; }
					if (button) {
						button.disabled = true;
						button.classList.add('is-busy');
						button.innerHTML = '<span class="dashicons dashicons-update" aria-hidden="true"></span><span>Working...</span>';
					}
					var isRestore = !!(button && button.classList.contains('tab-restore-button'));
					showPassiveProgress(isRestore ? 'Restoring template' : 'Revoking template', isRestore ? 'Re-applying tracked files and shared assets...' : 'Removing tracked files and shared asset blocks...');
				});
			});
		}

		function setActiveView(view) {
			document.querySelectorAll('.tab-nav [data-tab-view]').forEach(function (link) { link.classList.toggle('nav-tab-active', link.dataset.tabView === view); });
			if (viewContent) viewContent.dataset.currentView = view;
		}

		function fetchView(view, href) {
			if (viewCache[view]) return Promise.resolve(viewCache[view]);
			if (loadingViews[view]) return loadingViews[view];
			if (view === 'history' && window.TABAdmin) {
				var data = new URLSearchParams({ action: 'tab_load_history', nonce: window.TABAdmin.nonce });
				loadingViews[view] = window.fetch(window.TABAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: data.toString() })
					.then(function (response) { if (!response.ok) throw new Error('History request failed'); return response.json(); })
					.then(function (result) { if (!result.success) throw new Error('History response failed'); return result.data.html; });
			} else {
				loadingViews[view] = window.fetch(href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
					.then(function (response) { if (!response.ok) throw new Error('View request failed'); return response.text(); })
					.then(function (html) { var parsed = new DOMParser().parseFromString(html, 'text/html'); var incoming = parsed.getElementById('tab-view-content'); if (!incoming) throw new Error('View content was not found'); return incoming.innerHTML; });
			}
			return loadingViews[view].then(function (html) { viewCache[view] = html; delete loadingViews[view]; return html; }, function (error) { delete loadingViews[view]; throw error; });
		}

		function loadView(link, pushState) {
			if (!viewContent) return Promise.resolve();
			var view = link.dataset.tabView;
			if (viewCache[view]) {
				viewContent.innerHTML = viewCache[view]; setActiveView(view); bindForms(viewContent);
				if (pushState) window.history.pushState({ tabView: view }, '', link.href);
				return Promise.resolve();
			}
			viewContent.classList.add('is-loading');
			viewContent.setAttribute('aria-busy', 'true');
			var progress = showPassiveProgress(view === 'history' ? 'Opening template history' : 'Loading project profile', view === 'history' ? 'Retrieving saved templates and restore actions...' : 'Loading current theme settings...');
			return fetchView(view, link.href).then(function (html) {
				viewContent.innerHTML = html; setActiveView(view); bindForms(viewContent);
				if (pushState) window.history.pushState({ tabView: view }, '', link.href);
			}).catch(function () { window.location.assign(link.href); }).finally(function () { progress.stop(); viewContent.classList.remove('is-loading'); viewContent.removeAttribute('aria-busy'); });
		}

		var hero = document.querySelector('.tab-wrap > .tab-hero');
		if (hero) document.querySelectorAll('.tab-wrap .notice, .tab-wrap .updated, .tab-wrap .error').forEach(function (notice) { hero.parentNode.insertBefore(notice, hero); });
		bindForms(document);
		document.querySelectorAll('.tab-nav [data-tab-view]').forEach(function (link) {
			link.addEventListener('click', function (event) { if (!viewContent) return; event.preventDefault(); if (link.dataset.tabView !== viewContent.dataset.currentView) loadView(link, true); });
		});
		window.addEventListener('popstate', function () {
			var view = new URL(window.location.href).searchParams.get('tab_view') === 'history' ? 'history' : 'project';
			var link = document.querySelector('.tab-nav [data-tab-view="' + view + '"]');
			if (link) loadView(link, false);
		});
		var historyLink = document.querySelector('.tab-nav [data-tab-view="history"]');
		if (historyLink && !viewCache.history) (window.requestIdleCallback || function (callback) { window.setTimeout(callback, 250); })(function () { fetchView('history', historyLink.href).catch(function () {}); });
	});
}());