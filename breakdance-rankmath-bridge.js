(function (wp, window, document) {
	if (!wp || !wp.hooks || !wp.apiFetch || !window.BreakdanceRankMathBridge) {
		return;
	}

	var settings = window.BreakdanceRankMathBridge;
	var cache = {};
	var loading = {};
	var fetchedOnce = {};
	var refreshedOnce = {};
	var loggedOnce = {};
	var storageKeyPrefix = 'bd_rm_content_';

	function getPostId() {
		if (settings.postId) {
			return parseInt(settings.postId, 10);
		}
		var input = document.getElementById('post_ID');
		if (input && input.value) {
			return parseInt(input.value, 10);
		}
		return 0;
	}

	function refreshRankMath() {
		if (window.rankMathEditor && typeof window.rankMathEditor.refresh === 'function') {
			if (settings.debug && !loggedOnce.refresh) {
				console.log('[Breakdance RankMath Bridge] Refreshing Rank Math content analysis');
				loggedOnce.refresh = true;
			}
			window.rankMathEditor.refresh('content');
		}
	}

	function getCachedFromStorage(postId) {
		try {
			return window.sessionStorage ? window.sessionStorage.getItem(storageKeyPrefix + postId) : null;
		} catch (e) {
			return null;
		}
	}

	function setCachedToStorage(postId, content) {
		try {
			if (window.sessionStorage) {
				window.sessionStorage.setItem(storageKeyPrefix + postId, content);
			}
		} catch (e) {
			// no-op
		}
	}

	function fetchRenderedContent(postId) {
		if (!postId || loading[postId] || fetchedOnce[postId]) {
			return;
		}

		loading[postId] = true;
		fetchedOnce[postId] = true;
		if (settings.debug) {
			console.log('[Breakdance RankMath Bridge] Fetching rendered content', { postId: postId, mode: settings.mode });
		}

		wp.apiFetch({
			url: settings.restUrl + '?post_id=' + encodeURIComponent(postId),
			method: 'GET',
			headers: {
				'X-WP-Nonce': settings.nonce
			}
		}).then(function (response) {
			if (response && response.content) {
				cache[postId] = response.content;
				setCachedToStorage(postId, response.content);
				if (settings.debug) {
					console.log('[Breakdance RankMath Bridge] Rendered content received', {
						postId: postId,
						length: response.content.length,
						debug: response.debug || null
					});
				}
			}
		}).catch(function (error) {
			if (settings.debug) {
				console.warn('[Breakdance RankMath Bridge] Rendered content fetch failed', error);
			}
		}).finally(function () {
			loading[postId] = false;
			if (!refreshedOnce[postId]) {
				refreshedOnce[postId] = true;
				refreshRankMath();
			}
		});
	}

	wp.hooks.addFilter(
		'rank_math_content',
		'breakdance-rankmath-bridge',
		function (content) {
			var postId = getPostId();
			if (!postId) {
				return content;
			}
			if (!cache[postId]) {
				var stored = getCachedFromStorage(postId);
				if (stored) {
					cache[postId] = stored;
				}
			}
			if (cache[postId]) {
				if (settings.mode === 'combine') {
					return content ? (content + "\n\n" + cache[postId]) : cache[postId];
				}
				return cache[postId];
			}
			if (settings.debug && !loggedOnce.fetch) {
				console.log('[Breakdance RankMath Bridge] No cached content yet, fetching...');
				loggedOnce.fetch = true;
			}
			fetchRenderedContent(postId);
			return content;
		},
		20
	);

	wp.hooks.addAction(
		'rank_math_loaded',
		'breakdance-rankmath-bridge',
		function () {
			if (settings.debug && !loggedOnce.loaded) {
				console.log('[Breakdance RankMath Bridge] rank_math_loaded');
				loggedOnce.loaded = true;
			}
			var postId = getPostId();
			if (postId && cache[postId]) {
				refreshRankMath();
			} else if (postId && !loading[postId]) {
				fetchRenderedContent(postId);
			}
		}
	);
})(window.wp, window, document);
