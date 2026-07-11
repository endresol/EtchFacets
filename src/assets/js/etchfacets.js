/**
 * EtchFacets — Frontend AJAX Controller
 *
 * Vanilla JS (no dependencies). Auto-discovers facet elements and the listing
 * template on the page, handles user interactions, sends AJAX requests, and
 * updates the DOM.
 *
 * @package EtchFacets
 */
(function () {
	'use strict';

	// -------------------------------------------------------------------------
	// Debounce helper
	// -------------------------------------------------------------------------

	function debounce(fn, delay) {
		let timer;
		return function (...args) {
			clearTimeout(timer);
			timer = setTimeout(() => fn.apply(this, args), delay);
		};
	}

	// -------------------------------------------------------------------------
	// Initialisation — wait for DOM
	// -------------------------------------------------------------------------

	document.addEventListener('DOMContentLoaded', () => {
		// --- Auto-discovery ---------------------------------------------------

		const template = document.querySelector('.etchfacets-template');
		if (!template) return; // No facets on this page — exit silently.

		const facetElements  = document.querySelectorAll('[data-etchfacet]');
		const resetButtons   = document.querySelectorAll('.etchfacets-reset');
		const totalElements  = document.querySelectorAll('[data-etchfacets-total]');

		// --- Configuration ----------------------------------------------------

		const config    = window.etchfacetsConfig || {};
		const baseQuery = parseQueryConfig(template);

		/**
		 * Parse query config from the template element.
		 * Supports two methods:
		 *   1. Single JSON attribute: data-etchfacets-query='{"post_type":"produkter"}'
		 *   2. Individual attributes (Etch-friendly):
		 *      data-etchfacets-post-type="produkter"
		 *      data-etchfacets-posts-per-page="12"
		 *      data-etchfacets-orderby="date"
		 *      data-etchfacets-order="DESC"
		 */
		function parseQueryConfig(el) {
			// Try JSON attribute first.
			const jsonAttr = el.getAttribute('data-etchfacets-query');
			if (jsonAttr) {
				try {
					return JSON.parse(jsonAttr);
				} catch (e) {
					// JSON failed — fall through to individual attributes.
				}
			}

			// Read individual data attributes.
			return {
				post_type:      el.getAttribute('data-etchfacets-post-type') || 'post',
				posts_per_page: parseInt(el.getAttribute('data-etchfacets-posts-per-page') || '12', 10),
				orderby:        el.getAttribute('data-etchfacets-orderby') || 'date',
				order:          el.getAttribute('data-etchfacets-order') || 'DESC',
			};
		}

		// Active AbortController for cancelling in-flight requests.
		let currentController = null;

		// Current page number, used to redraw pagination status after each fetch.
		let currentPage = 1;
		let maxPages    = 1;

		// ---------------------------------------------------------------------
		// Selection collection
		// ---------------------------------------------------------------------

		function collectSelections() {
			const facets  = {};
			const sources = {};
			const logic   = {};

			facetElements.forEach((el) => {
				const name   = el.getAttribute('data-etchfacet');
				const source = el.getAttribute('data-etchfacet-source') || '';
				const facetLogic = el.getAttribute('data-etchfacet-logic') || 'or';

				// Always register sources and logic so count calculator
				// can calculate counts for ALL facets, not just active ones.
				sources[name] = source;
				logic[name]   = facetLogic;

				let values = [];

				// Checkboxes — for hierarchical facets, when a parent is checked
				// also include all its child term values so the query matches children too.
				const checkboxes = el.querySelectorAll('input[type="checkbox"]:checked');
				if (checkboxes.length) {
					const collected = new Set();
					checkboxes.forEach((cb) => {
						collected.add(cb.value);

						// If this checkbox is in a tree item with children, include all child values.
						const treeItem = cb.closest('.etchfacets-tree-item');
						if (treeItem) {
							const childrenContainer = treeItem.querySelector('.etchfacets-tree-children');
							if (childrenContainer) {
								childrenContainer.querySelectorAll('input[type="checkbox"]').forEach((childCb) => {
									collected.add(childCb.value);
								});
							}
						}
					});
					values = Array.from(collected);
				}

				// Radio buttons
				if (!values.length) {
					const radio = el.querySelector('input[type="radio"]:checked');
					if (radio) {
						values = [radio.value];
					}
				}

				// Select / dropdown
				if (!values.length) {
					const select = el.querySelector('select');
					if (select && select.value !== '') {
						values = [select.value];
					}
				}

				// Search input
				if (!values.length) {
					const search = el.querySelector('input[type="search"]') || el.querySelector('input[type="text"]');
					if (search) {
						const trimmed = search.value.trim();
						if (trimmed !== '') {
							values = [trimmed];
						}
					}
				}

				// Range inputs
				if (!values.length) {
					const rangeMin = el.querySelector('.etchfacet-min');
					const rangeMax = el.querySelector('.etchfacet-max');
					if (rangeMin && rangeMax) {
						values = [rangeMin.value, rangeMax.value];
					}
				}

				// Only include active selections in facets.
				if (values.length) {
					facets[name] = values;
				}
			});

			return { facets, sources, logic };
		}

		// ---------------------------------------------------------------------
		// Count updates
		// ---------------------------------------------------------------------

		function updateCounts(counts) {
			if (!counts) return;

			for (const [name, choices] of Object.entries(counts)) {
				const facetEl = document.querySelector(`[data-etchfacet="${name}"]`);
				if (!facetEl) continue;

				// meta_range facets return { min, max } bounds, not a choices array —
				// sync the range/slider inputs' bounds and move on. Leaving this
				// unhandled would call .forEach on a plain object and throw, aborting
				// the rest of updateCounts() (and the total-count update that follows
				// it in the caller) for every facet processed after this one.
				if (!Array.isArray(choices)) {
					if (choices && typeof choices.min !== 'undefined' && typeof choices.max !== 'undefined') {
						[facetEl.querySelector('.etchfacet-min'), facetEl.querySelector('.etchfacet-max')].forEach((input) => {
							if (!input) return;
							input.min = choices.min;
							input.max = choices.max;
						});
					}
					continue;
				}

				choices.forEach((choice) => {
					if (choice.value === undefined) return;

					// --- Checkboxes / Radios ---
					const input = facetEl.querySelector(
						`input[type="checkbox"][value="${CSS.escape(choice.value)}"], input[type="radio"][value="${CSS.escape(choice.value)}"]`
					);
					if (input) {
						const countSpan = input.closest('label')?.querySelector('.etchfacet-count');
						if (countSpan) {
							countSpan.textContent = `(${choice.count})`;
						}

						const label = input.closest('label');
						if (label) {
							if (choice.count === 0 && !input.checked) {
								label.classList.add('etchfacet-ghost');
							} else {
								label.classList.remove('etchfacet-ghost');
							}

							if (choice.count === 0 && !input.checked) {
								label.classList.add('etchfacet-hidden');
							} else {
								label.classList.remove('etchfacet-hidden');
							}
						}
						return;
					}

					// --- Dropdown options ---
					const option = facetEl.querySelector(
						`select option[value="${CSS.escape(choice.value)}"]`
					);
					if (option) {
						// Strip any existing count from the label, then append.
						const baseText = option.textContent.replace(/\s*\(\d+\)$/, '');
						option.textContent = `${baseText} (${choice.count})`;
					}
				});
			}
		}

		// ---------------------------------------------------------------------
		// URL building
		// ---------------------------------------------------------------------

		/**
		 * Build a URL with facet params for fetching the filtered page.
		 * Includes _src_ and _logic_ params so the PHP pre_get_posts hook
		 * knows how to filter the query.
		 */
		function buildFilterUrl(page = 1) {
			const selections = collectSelections();
			const params     = new URLSearchParams();

			// Add facet values + source + logic for each active facet.
			for (const [name, values] of Object.entries(selections.facets)) {
				params.set(`_${name}`, values.join(','));
				params.set(`_src_${name}`, selections.sources[name] || '');
				params.set(`_logic_${name}`, selections.logic[name] || 'or');
			}

			// Tell PHP which post type the listing targets so it only filters
			// that query — not the secondary queries Etch runs to render each
			// card's related/meta data.
			if (baseQuery && baseQuery.post_type) {
				params.set('_pt', baseQuery.post_type);
			}

			if (page > 1) {
				params.set('_page', page);
			}

			const qs = params.toString();
			return window.location.pathname + (qs ? `?${qs}` : '');
		}

		/**
		 * Update the browser URL (without the _src_ and _logic_ params — keep it clean).
		 */
		function updateUrl(page = 1) {
			const selections = collectSelections();
			const params     = new URLSearchParams();

			for (const [name, values] of Object.entries(selections.facets)) {
				params.set(`_${name}`, values.join(','));
			}

			if (page > 1) {
				params.set('_page', page);
			}

			const qs     = params.toString();
			const newUrl = window.location.pathname + (qs ? `?${qs}` : '');
			history.pushState(null, '', newUrl);
		}

		/**
		 * Populate facet inputs from URL params.
		 * Returns true if any facet state was found.
		 */
		function populateFromUrl() {
			const params = new URLSearchParams(window.location.search);
			let hasState = false;

			params.forEach((value, key) => {
				if (!key.startsWith('_') || key.startsWith('_src_') || key.startsWith('_logic_')) return;

				const facetName = key.slice(1);
				const facetEl   = document.querySelector(`[data-etchfacet="${facetName}"]`);
				if (!facetEl) return;

				const values = value.split(',');
				hasState = true;

				values.forEach((v) => {
					const cb = facetEl.querySelector(`input[type="checkbox"][value="${CSS.escape(v)}"]`);
					if (cb) cb.checked = true;

					const radio = facetEl.querySelector(`input[type="radio"][value="${CSS.escape(v)}"]`);
					if (radio) radio.checked = true;
				});

				const select = facetEl.querySelector('select');
				if (select && values.length === 1) {
					select.value = values[0];
				}

				const search = facetEl.querySelector('input[type="search"]') || facetEl.querySelector('input[type="text"]');
				if (search && values.length === 1) {
					search.value = values[0];
				}

				if (values.length === 2) {
					const minInput = facetEl.querySelector('.etchfacet-min');
					const maxInput = facetEl.querySelector('.etchfacet-max');
					if (minInput && maxInput) {
						minInput.value = values[0];
						maxInput.value = values[1];
					}
				}
			});

			return hasState;
		}

		function readUrlState() {
			if (populateFromUrl()) {
				// Check if the URL has _src_ params (full filter URL) or just
				// clean params (shared/bookmarked URL like ?_produkt_type=musserende).
				const params    = new URLSearchParams(window.location.search);
				const hasSrcParams = Array.from(params.keys()).some((k) => k.startsWith('_src_'));

				if (hasSrcParams) {
					// Full URL — server already filtered via pre_get_posts, just get counts.
					fetchCounts();
				} else {
					// Clean URL (shared/bookmarked) — server couldn't filter,
					// so we need to fetch the filtered page using the sources
					// from our data attributes.
					fetchResults(1);
				}
			}
		}

		// Handle back/forward navigation.
		window.addEventListener('popstate', () => {
			clearAllInputs();
			populateFromUrl();
			fetchResults(1);
		});

		// ---------------------------------------------------------------------
		// AJAX request
		// ---------------------------------------------------------------------

		function fetchResults(page = 1) {
			currentPage = page;

			// Cancel any in-flight request.
			if (currentController) {
				currentController.abort();
			}
			currentController = new AbortController();

			// Loading state.
			template.classList.add('etchfacets-loading');
			document.dispatchEvent(new CustomEvent('etchfacets:before-refresh'));

			// Build the filtered page URL (Etch renders it via pre_get_posts).
			const filterUrl = buildFilterUrl(page);

			// Fetch the actual page so Etch renders the listing with its own templates.
			fetch(filterUrl, {
				method: 'GET',
				headers: { 'X-EtchFacets': '1' },
				signal: currentController.signal,
			})
				.then((res) => res.text())
				.then((html) => {
					// Parse the response HTML and extract the .etchfacets-template content.
					const parser  = new DOMParser();
					const doc     = parser.parseFromString(html, 'text/html');
					const newTemplate = doc.querySelector('.etchfacets-template');

					if (newTemplate) {
						template.innerHTML = newTemplate.innerHTML;
					} else {
						template.innerHTML = '<div class="etchfacets-no-results">No results found.</div>';
					}

					// Update the browser URL (clean version without _src_ params).
					updateUrl(page);

					// Remove loading state.
					template.classList.remove('etchfacets-loading');

					// Dispatch loaded event.
					document.dispatchEvent(
						new CustomEvent('etchfacets:loaded', {
							detail: { page },
						})
					);

					// Re-attach pagination listeners (new DOM content).
					attachPaginationListeners();

					// Now fetch counts separately via admin-ajax.
					fetchCounts();
				})
				.catch((err) => {
					if (err.name === 'AbortError') return;
					template.classList.remove('etchfacets-loading');
					document.dispatchEvent(new CustomEvent('etchfacets:error'));
					console.error('EtchFacets: page fetch failed', err);
				});
		}

		/**
		 * Fetch facet counts via admin-ajax (lightweight, separate from HTML).
		 */
		function fetchCounts() {
			const selections = collectSelections();

			const formData = new FormData();
			formData.append('action', 'etchfacets_filter');
			formData.append('nonce', config.nonce || '');
			formData.append('facets', JSON.stringify(selections.facets));
			formData.append('sources', JSON.stringify(selections.sources));
			formData.append('logic', JSON.stringify(selections.logic));
			formData.append('page', '1');
			formData.append('query_context', JSON.stringify(baseQuery));

			fetch(config.ajaxUrl || '', {
				method: 'POST',
				body: formData,
			})
				.then((res) => res.json())
				.then((response) => {
					if (response.success === true) {
						updateCounts(response.data.counts);

						totalElements.forEach((el) => {
							el.textContent = response.data.total;
						});

						maxPages = response.data.max_pages || 1;
						updatePaginationUi();
					}
				})
				.catch(() => {
					// Counts are non-critical — fail silently.
				});
		}

		// ---------------------------------------------------------------------
		// Pagination support
		// ---------------------------------------------------------------------

		function attachPaginationListeners() {
			const paginationLinks = template.querySelectorAll(
				'a.page-numbers, .etchfacets-pagination a'
			);

			paginationLinks.forEach((link) => {
				link.addEventListener('click', (e) => {
					e.preventDefault();

					const href = link.getAttribute('href');
					if (!href) return;

					// Extract page number from href.
					const match = href.match(/[?&]paged=(\d+)/) || href.match(/\/page\/(\d+)/);
					const pageNum = match ? parseInt(match[1], 10) : 1;

					fetchResults(pageNum);

					// Scroll to template top for UX.
					template.scrollIntoView({ behavior: 'smooth', block: 'start' });
				});
			});
		}

		/**
		 * Hand-built Prev/Next pager — an alternative to the numbered
		 * page-numbers links attachPaginationListeners() supports.
		 * Markup: .etchfacets-pagination-prev / -next (buttons) and an
		 * optional .etchfacets-pagination-status (text) sharing a common
		 * ancestor. Multiple pager widgets on one page are all kept in sync.
		 */
		const paginationPrevButtons   = document.querySelectorAll('.etchfacets-pagination-prev');
		const paginationNextButtons   = document.querySelectorAll('.etchfacets-pagination-next');
		const paginationStatusEls     = document.querySelectorAll('.etchfacets-pagination-status');
		const loadMoreButtons         = document.querySelectorAll('.etchfacets-load-more');

		function updatePaginationUi() {
			paginationStatusEls.forEach((el) => {
				el.textContent = `Page ${currentPage} of ${maxPages}`;
			});
			paginationPrevButtons.forEach((btn) => {
				btn.disabled = currentPage <= 1;
			});
			paginationNextButtons.forEach((btn) => {
				btn.disabled = currentPage >= maxPages;
			});
			loadMoreButtons.forEach((btn) => {
				const exhausted = currentPage >= maxPages;
				btn.disabled = exhausted;
				btn.classList.toggle('etchfacets-load-more--exhausted', exhausted);
			});
		}

		/**
		 * Load More — fetches the next page and appends it to the existing
		 * results instead of replacing them (unlike fetchResults(), which
		 * always replaces .etchfacets-template's content). Markup:
		 * .etchfacets-load-more (button). Reuses the same posts-per-page
		 * batch size configured on the template via data-etchfacets-posts-per-page.
		 */
		function loadMore() {
			if (currentPage >= maxPages) return;
			const nextPage = currentPage + 1;

			if (currentController) {
				currentController.abort();
			}
			currentController = new AbortController();

			template.classList.add('etchfacets-loading');
			document.dispatchEvent(new CustomEvent('etchfacets:before-refresh'));

			const filterUrl = buildFilterUrl(nextPage);

			fetch(filterUrl, {
				method: 'GET',
				headers: { 'X-EtchFacets': '1' },
				signal: currentController.signal,
			})
				.then((res) => res.text())
				.then((html) => {
					const parser      = new DOMParser();
					const doc         = parser.parseFromString(html, 'text/html');
					const newTemplate = doc.querySelector('.etchfacets-template');

					if (newTemplate) {
						// Append each fetched node instead of replacing existing content.
						Array.from(newTemplate.childNodes).forEach((node) => {
							template.appendChild(node);
						});
					}

					currentPage = nextPage;
					updateUrl(currentPage);
					template.classList.remove('etchfacets-loading');

					document.dispatchEvent(
						new CustomEvent('etchfacets:loaded', {
							detail: { page: currentPage, appended: true },
						})
					);

					// Counts (and max_pages, via updatePaginationUi()) still
					// reflect the whole filtered set, not just what's loaded.
					fetchCounts();
				})
				.catch((err) => {
					if (err.name === 'AbortError') return;
					template.classList.remove('etchfacets-loading');
					document.dispatchEvent(new CustomEvent('etchfacets:error'));
					console.error('EtchFacets: load more failed', err);
				});
		}

		loadMoreButtons.forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				loadMore();
			});
		});

		paginationPrevButtons.forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				if (currentPage <= 1) return;
				fetchResults(currentPage - 1);
				template.scrollIntoView({ behavior: 'smooth', block: 'start' });
			});
		});

		paginationNextButtons.forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				if (currentPage >= maxPages) return;
				fetchResults(currentPage + 1);
				template.scrollIntoView({ behavior: 'smooth', block: 'start' });
			});
		});

		// ---------------------------------------------------------------------
		// Clear all inputs
		// ---------------------------------------------------------------------

		function clearAllInputs() {
			facetElements.forEach((el) => {
				el.querySelectorAll('input[type="checkbox"], input[type="radio"]').forEach((input) => {
					input.checked = false;
				});

				el.querySelectorAll('select').forEach((select) => {
					select.selectedIndex = 0;
				});

				el.querySelectorAll('input[type="search"], input[type="text"]').forEach((input) => {
					input.value = '';
				});

				// Range/slider/date facet bounds. Matched by class rather than
				// input type — .etchfacet-min/-max can be number, range, or
				// date inputs (see FACETS.md), and a date input left with a
				// stale value would otherwise never get cleared, silently
				// pinning the facet active even after a reset.
				el.querySelectorAll('.etchfacet-min, .etchfacet-max').forEach((input) => {
					if (input.hasAttribute('data-default')) {
						input.value = input.getAttribute('data-default');
					} else if (input.type === 'date') {
						input.value = '';
					} else if (input.classList.contains('etchfacet-min')) {
						input.value = input.min || 0;
					} else {
						input.value = input.max || 0;
					}
				});
			});
		}

		// ---------------------------------------------------------------------
		// Event listeners
		// ---------------------------------------------------------------------

		const debouncedSearch = debounce(() => fetchResults(1), 300);
		const debouncedRange  = debounce(() => fetchResults(1), 500);

		facetElements.forEach((el) => {
			// Checkboxes & radios.
			el.querySelectorAll('input[type="checkbox"], input[type="radio"]').forEach((input) => {
				input.addEventListener('change', () => fetchResults(1));
			});

			// Selects.
			el.querySelectorAll('select').forEach((select) => {
				select.addEventListener('change', () => fetchResults(1));
			});

			// Search inputs (debounced).
			el.querySelectorAll('input[type="search"], input[type="text"]').forEach((input) => {
				input.addEventListener('input', debouncedSearch);
			});

			// Range inputs (debounced).
			el.querySelectorAll('input[type="range"]').forEach((input) => {
				input.addEventListener('input', debouncedRange);
			});

			// Number inputs used as range min/max (debounced).
			el.querySelectorAll('input[type="number"].etchfacet-min, input[type="number"].etchfacet-max').forEach((input) => {
				input.addEventListener('input', debouncedRange);
			});
		});

		// Reset buttons.
		resetButtons.forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				clearAllInputs();
				fetchResults(1);
			});
		});

		// ---------------------------------------------------------------------
		// Hierarchical tree toggles
		// ---------------------------------------------------------------------

		/**
		 * Attach toggle listeners to all .etchfacets-tree-toggle buttons.
		 * Uses event delegation on facet containers so it works after DOM updates.
		 */
		function attachTreeToggleListeners() {
			document.querySelectorAll('.etchfacets-hierarchy').forEach((facetEl) => {
				// Use a single delegated listener per hierarchy facet.
				if (facetEl.dataset.etchfacetsTreeInit) return;
				facetEl.dataset.etchfacetsTreeInit = '1';

				facetEl.addEventListener('click', (e) => {
					const toggleBtn = e.target.closest('.etchfacets-tree-toggle');
					if (!toggleBtn) return;

					e.preventDefault();
					e.stopPropagation();

					const treeItem = toggleBtn.closest('.etchfacets-tree-item');
					if (!treeItem) return;

					const children = treeItem.querySelector('.etchfacets-tree-children');
					if (!children) return;

					const isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';

					if (isExpanded) {
						// Collapse
						children.hidden = true;
						toggleBtn.setAttribute('aria-expanded', 'false');
						toggleBtn.textContent = '+';
					} else {
						// Expand
						children.hidden = false;
						toggleBtn.setAttribute('aria-expanded', 'true');
						toggleBtn.textContent = '−';
					}
				});
			});
		}

		attachTreeToggleListeners();

		// Initial pagination listeners.
		attachPaginationListeners();

		// ---------------------------------------------------------------------
		// Initial load
		// ---------------------------------------------------------------------

		readUrlState();

		// Always fetch counts on page load so they show immediately.
		fetchCounts();

		// ---------------------------------------------------------------------
		// Public API
		// ---------------------------------------------------------------------

		window.EtchFacets = {
			refresh: () => fetchResults(1),
			reset: () => {
				clearAllInputs();
				fetchResults(1);
			},
			getSelections: () => collectSelections(),
			on: (event, callback) => document.addEventListener(`etchfacets:${event}`, callback),
		};
	});
})();
