/**
 * EtchFacets — Frontend AJAX Controller
 *
 * Vanilla JS (no dependencies). Auto-discovers one or more independent
 * facet/listing instances on the page and drives each one separately.
 *
 * An "instance" is either:
 *   - a `.etchfacets-instance` wrapper element (new — everything inside its
 *     Slot is scoped to it by plain DOM containment, no matching id needed), or
 *   - if no wrapper exists anywhere on the page, the whole `document` is
 *     treated as a single implicit "main" instance — this is the legacy,
 *     pre-wrapper behavior, preserved unchanged for sites still using bare
 *     shortcodes.
 *
 * A component that can't live inside its wrapper's DOM subtree for layout
 * reasons (e.g. a Map broken out of a grid) can opt back in to a specific
 * instance via an explicit `data-etchfacets-group` attribute matching that
 * instance's group id — see scopedQueryAll() below.
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

	/**
	 * Wire up the optional collapse/expand toggle on facets with
	 * `data-etchfacet-collapsible="true"` (the "Facet - Checkboxes" component's
	 * Collapsible/Start Open props). Pure local UI state, unrelated to any
	 * instance/group — the facet's own DOM never gets replaced by AJAX, so this
	 * only needs to run once at page load.
	 */
	function initCollapsibleFacets() {
		document.querySelectorAll('[data-etchfacet-collapsible="true"]').forEach((facetEl) => {
			const toggle = facetEl.querySelector('.etchfacets-facet-toggle');
			const choices = facetEl.querySelector('.etchfacets-facet-choices');
			if (!toggle || !choices) return;

			// Animate max-height to the choice list's actual measured height
			// (not a large fixed cap) so the roll-up/down transition tracks the
			// real content size instead of "snapping" open almost instantly —
			// scrollHeight is measurable even while the element is visually
			// collapsed via CSS (overflow: hidden doesn't affect scrollHeight).
			const setOpen = (open) => {
				facetEl.setAttribute('data-etchfacet-open', String(open));
				toggle.classList.toggle('is-open', open);
				toggle.setAttribute('aria-expanded', String(open));
				choices.style.maxHeight = open ? `${choices.scrollHeight}px` : '0px';
				choices.style.opacity = open ? '1' : '0';
			};

			setOpen(facetEl.getAttribute('data-etchfacet-open') !== 'false');

			toggle.addEventListener('click', () => setOpen(!toggle.classList.contains('is-open')));
		});
	}

	/**
	 * Parse a URL param key into { group, base }.
	 * The "main" instance's params are unprefixed (`_foo`) for backward
	 * compatibility with existing bookmarked/shared URLs. Any other instance's
	 * params are prefixed with its group id (`_{group}__foo`).
	 */
	function parseParamKey(key) {
		if (!key.startsWith('_')) return null;
		// Group class includes "_" (a sanitize_key'd override value can contain
		// one) and matches greedily so the LAST "__" in the key is treated as
		// the group/base separator.
		const match = key.match(/^_([a-zA-Z0-9_-]+)__(.+)$/);
		if (match) return { group: match[1], base: match[2] };
		return { group: 'main', base: key.slice(1) };
	}

	// Registry of initialized instances, keyed by group id — backs the
	// instance-aware window.EtchFacets dispatcher built after DOMContentLoaded.
	const instances = new Map();

	// -------------------------------------------------------------------------
	// Initialisation — wait for DOM
	// -------------------------------------------------------------------------

	document.addEventListener('DOMContentLoaded', () => {
		const wrappers = document.querySelectorAll('.etchfacets-instance');
		wrappers.forEach((el, i) => {
			const group = el.getAttribute('data-etchfacets-group') || `ef-${i}`;
			initInstance(el, group);
		});

		// Legacy support: any .etchfacets-template that ISN'T inside a wrapper
		// is treated as the implicit "main" instance, exactly like before
		// wrappers existed — a page can have both a new wrapper-based instance
		// AND older bare-shortcode markup at the same time; adding one must
		// never stop the other from initializing.
		const hasUnwrappedTemplate = Array.from(document.querySelectorAll('.etchfacets-template')).some(
			(el) => !el.closest('.etchfacets-instance')
		);
		if (hasUnwrappedTemplate) {
			initInstance(document, 'main');
		}

		initCollapsibleFacets();

		// ---------------------------------------------------------------------
		// Public API — instance-aware, defaults to 'main' so existing
		// single-instance callers (window.EtchFacets.refresh(), etc.) keep
		// working unchanged.
		// ---------------------------------------------------------------------

		window.EtchFacets = {
			refresh: (group = 'main') => instances.get(group)?.refresh(),
			reset: (group = 'main') => instances.get(group)?.reset(),
			getSelections: (group = 'main') => instances.get(group)?.getSelections(),
			getQueryContext: (group = 'main') => instances.get(group)?.getQueryContext(),
			on: (event, callback, group = 'main') => {
				document.addEventListener(`etchfacets:${event}`, (e) => {
					const eventGroup = (e.detail && e.detail.group) || 'main';
					if (eventGroup === group) callback(e);
				});
			},
		};
	});

	/**
	 * Initialize one facet/listing instance, scoped to `scopeEl`.
	 *
	 * @param {Document|Element} scopeEl The instance's containment scope —
	 *                                   either `document` (legacy) or a
	 *                                   `.etchfacets-instance` wrapper.
	 * @param {string} group             This instance's id (used for URL/AJAX
	 *                                   param namespacing and event filtering).
	 */
	function initInstance(scopeEl, group) {
		// --- Scoped discovery helpers ------------------------------------------

		/**
		 * Elements matching `selector` inside this instance's own DOM subtree,
		 * plus any page-wide element that explicitly opts into this instance via
		 * a matching `data-etchfacets-group` attribute (the escape hatch for
		 * components that can't be nested inside the wrapper for layout reasons).
		 * When `scopeEl` is `document` (the legacy/"main" instance) this is every
		 * matching element on the page that ISN'T already claimed by some other
		 * `.etchfacets-instance` wrapper — never every match unconditionally,
		 * otherwise a page mixing a wrapper with older bare markup would have
		 * the wrapper's own elements double-bound (once by their wrapper's
		 * instance, once by this whole-document fallback).
		 */
		function scopedQueryAll(selector) {
			const contained =
				scopeEl === document
					? Array.from(document.querySelectorAll(selector)).filter((el) => !el.closest('.etchfacets-instance'))
					: Array.from(scopeEl.querySelectorAll(selector));

			if (scopeEl === document) return contained;

			const detached = Array.from(
				document.querySelectorAll(`${selector}[data-etchfacets-group="${group}"]`)
			).filter((el) => !contained.includes(el));

			return contained.concat(detached);
		}

		function scopedQueryOne(selector) {
			return scopedQueryAll(selector)[0] || null;
		}

		/** Find a facet's container element by name, scoped to this instance. */
		function findFacetEl(name) {
			if (scopeEl === document) {
				const matches = document.querySelectorAll(`[data-etchfacet="${name}"]`);
				for (const el of matches) {
					if (!el.closest('.etchfacets-instance')) return el;
				}
			} else {
				const el = scopeEl.querySelector(`[data-etchfacet="${name}"]`);
				if (el) return el;
			}
			return document.querySelector(`[data-etchfacet="${name}"][data-etchfacets-group="${group}"]`);
		}

		/** Prefix a URL param base name per this instance's group. */
		function gp(base) {
			return group === 'main' ? `_${base}` : `_${group}__${base}`;
		}

		// --- Auto-discovery ---------------------------------------------------

		// For the legacy/"main" instance, the template must be one NOT already
		// claimed by some other .etchfacets-instance wrapper elsewhere on the
		// page (see scopedQueryAll() above for why).
		const template =
			scopeEl === document
				? Array.from(document.querySelectorAll('.etchfacets-template')).find((el) => !el.closest('.etchfacets-instance'))
				: scopeEl.querySelector('.etchfacets-template');
		if (!template) return; // No listing in this instance — nothing to drive.

		const facetElements = scopedQueryAll('[data-etchfacet]');
		const resetButtons = scopedQueryAll('.etchfacets-reset');
		const totalElements = scopedQueryAll('[data-etchfacets-total]');
		const activeFiltersContainer = scopedQueryOne('[data-etchfacets-active-filters]');

		// Facets (keyed by their [data-etchfacet] container) whose range/slider/
		// date inputs the user has genuinely dragged/typed into. This is
		// intentionally NOT inferred from comparing .value against some
		// "default" — updateCounts() below re-narrows a range facet's min/max
		// attributes to match what's actually available under the other active
		// facets, and a <input type="range">/"number"/"date"> silently clamps
		// its own .value whenever that happens. Without an explicit flag, that
		// browser-driven clamp is indistinguishable from the user moving the
		// slider themselves.
		const touchedRangeFacets = new WeakSet();

		// --- Configuration ----------------------------------------------------

		const config = window.etchfacetsConfig || {};
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
				post_type: el.getAttribute('data-etchfacets-post-type') || 'post',
				posts_per_page: parseInt(el.getAttribute('data-etchfacets-posts-per-page') || '12', 10),
				orderby: el.getAttribute('data-etchfacets-orderby') || 'date',
				order: el.getAttribute('data-etchfacets-order') || 'DESC',
			};
		}

		// Active AbortController for cancelling in-flight requests.
		let currentController = null;

		// Current page number, used to redraw pagination status after each fetch.
		let currentPage = 1;
		let maxPages = 1;

		// ---------------------------------------------------------------------
		// Selection collection
		// ---------------------------------------------------------------------

		function collectSelections() {
			const facets = {};
			const sources = {};
			const logic = {};

			facetElements.forEach((el) => {
				const name = el.getAttribute('data-etchfacet');
				const source = el.getAttribute('data-etchfacet-source') || '';
				const facetLogic = el.getAttribute('data-etchfacet-logic') || 'or';

				// Always register sources and logic so count calculator
				// can calculate counts for ALL facets, not just active ones.
				sources[name] = source;
				logic[name] = facetLogic;

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

				// Range inputs — only once the user has actually touched this
				// facet's slider/inputs (see touchedRangeFacets above). An
				// untouched range must never be sent as a filter: its bounds
				// can silently narrow via updateCounts() below to match other
				// active facets, and sending that narrowed range back as a
				// filter would double-apply a constraint the user never chose.
				if (!values.length && touchedRangeFacets.has(el)) {
					const rangeMin = el.querySelector('.etchfacet-min');
					const rangeMax = el.querySelector('.etchfacet-max');
					if (rangeMin && rangeMax) {
						values = [rangeMin.value, rangeMax.value];
					}
				}

				// Generic value attribute — used by JS-driven facets that have no
				// form inputs (e.g. the map viewport, whose value is its bounds).
				if (!values.length) {
					const rawValue = el.getAttribute('data-etchfacet-value');
					if (rawValue) {
						try {
							const parsed = JSON.parse(rawValue);
							if (Array.isArray(parsed) && parsed.length) {
								values = parsed.map(String);
							}
						} catch (e) {
							// Ignore malformed values.
						}
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
				const facetEl = findFacetEl(name);
				if (!facetEl) continue;

				// meta_range facets return { min, max } bounds, not a choices array —
				// sync the range/slider inputs' bounds and move on. Leaving this
				// unhandled would call .forEach on a plain object and throw, aborting
				// the rest of updateCounts() (and the total-count update that follows
				// it in the caller) for every facet processed after this one.
				if (!Array.isArray(choices)) {
					if (choices && typeof choices.min !== 'undefined' && typeof choices.max !== 'undefined') {
						const rangeMin = facetEl.querySelector('.etchfacet-min');
						const rangeMax = facetEl.querySelector('.etchfacet-max');

						if (rangeMin) rangeMin.min = choices.min;
						if (rangeMax) rangeMax.max = choices.max;

						// An untouched facet has no user-chosen value to protect —
						// keep it pinned to the full range currently available
						// under whichever other facets are active, in both
						// directions (a plain attribute update only auto-clamps
						// a value that's now too high/low; it won't widen a value
						// back out when the bounds relax again).
						if (rangeMin && rangeMax && !touchedRangeFacets.has(facetEl)) {
							rangeMin.value = choices.min;
							rangeMax.value = choices.max;
						}

						// Refresh a custom slider's track fill / value labels,
						// which only the inputs' own inline oninput handler
						// knows how to redraw — a bare attribute/value change
						// doesn't trigger it.
						if (rangeMin && typeof rangeMin.oninput === 'function') rangeMin.oninput();
						if (rangeMax && typeof rangeMax.oninput === 'function') rangeMax.oninput();
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
					const select = facetEl.querySelector('select');
					const option = select?.querySelector(
						`option[value="${CSS.escape(choice.value)}"]`
					);
					// Only append "(N)" if the select was rendered with show_counts
					// true — otherwise every choice with a nonzero count under the
					// current filters would gain a suffix that choices sitting at
					// zero (and therefore absent from this response) never get,
					// making counts appear on some options but not others.
					if (option && select?.dataset.etchfacetsShowCounts === '1') {
						// Strip any existing count from the label, then append.
						const baseText = option.textContent.replace(/\s*\(\d+\)$/, '');
						option.textContent = `${baseText} (${choice.count})`;
					}
				});
			}
		}

		// ---------------------------------------------------------------------
		// Active filters summary (optional — only runs if this instance has a
		// [data-etchfacets-active-filters] container)
		// ---------------------------------------------------------------------

		/**
		 * Text of a checkbox/radio <label>, minus the input itself and any
		 * .etchfacet-count span — leaves just the human-readable choice text
		 * (e.g. "Hotel" rather than "Hotel (3)").
		 */
		function choiceLabelText(labelEl, fallback) {
			if (!labelEl) return fallback;
			const clone = labelEl.cloneNode(true);
			clone.querySelectorAll('input, .etchfacet-count').forEach((n) => n.remove());
			const text = clone.textContent.trim();
			return text || fallback;
		}

		/** Text of a <select> <option>, minus any trailing " (N)" count suffix. */
		function optionLabelText(optionEl, fallback) {
			if (!optionEl) return fallback;
			const text = optionEl.textContent.replace(/\s*\(\d+\)$/, '').trim();
			return text || fallback;
		}

		/**
		 * A facet's own heading (e.g. "Topic"), used to prefix its value chips
		 * so "Guides" reads as "Topic: Guides". Falls back to the facet name
		 * when the facet has no heading element of its own.
		 */
		function facetGroupLabel(facetEl, facetName) {
			const heading = facetEl.querySelector('h2, h3, h4, h5, h6');
			const text = heading ? heading.textContent.trim() : '';
			return text || facetName;
		}

		/**
		 * Snap a facet's range/slider/date inputs back to "no filter applied"
		 * and clear its touched flag. Values go to the inputs' CURRENT min/max
		 * attributes (not some remembered original) — those attributes already
		 * reflect what's available under whichever other facets are still
		 * active, via updateCounts() below, so this is "show everything that's
		 * still reachable", not "restore page-load state".
		 */
		function resetRangeFacet(facetEl, rangeMin, rangeMax) {
			touchedRangeFacets.delete(facetEl);

			if (rangeMin.type === 'date') {
				rangeMin.value = '';
				rangeMax.value = '';
			} else {
				rangeMin.value = rangeMin.min || 0;
				rangeMax.value = rangeMax.max || 0;
			}

			// Refresh a custom slider's track fill / value labels, which are
			// driven by the inputs' own inline oninput handler — a bare .value
			// assignment doesn't trigger it. Called directly rather than
			// dispatchEvent() so it doesn't also re-trigger the debounced
			// input listener (and mark the facet touched again).
			if (typeof rangeMin.oninput === 'function') rangeMin.oninput();
			if (typeof rangeMax.oninput === 'function') rangeMax.oninput();
		}

		/**
		 * Render the current active facet selections as removable chips into
		 * [data-etchfacets-active-filters]. Reads labels straight from the
		 * facet UI already on the page — no server round-trip needed.
		 */
		function renderActiveFilters() {
			if (!activeFiltersContainer) return;

			const chips = [];

			facetElements.forEach((facetEl) => {
				// Sorting isn't a filter — it never narrows results, so it
				// doesn't belong in a list of things the user can "remove".
				if (facetEl.getAttribute('data-etchfacet-source') === 'sort') return;

				const facetName = facetEl.getAttribute('data-etchfacet');
				const groupLabel = facetGroupLabel(facetEl, facetName);

				// Checkboxes
				facetEl.querySelectorAll('input[type="checkbox"]:checked').forEach((cb) => {
					const text = choiceLabelText(cb.closest('label'), cb.value);
					chips.push({
						text: `${groupLabel}: ${text}`,
						remove: () => { cb.checked = false; fetchResults(1); },
					});
				});

				// Radios
				const radio = facetEl.querySelector('input[type="radio"]:checked');
				if (radio) {
					const text = choiceLabelText(radio.closest('label'), radio.value);
					chips.push({
						text: `${groupLabel}: ${text}`,
						remove: () => { radio.checked = false; fetchResults(1); },
					});
				}

				// Select / dropdown
				const select = facetEl.querySelector('select');
				if (select && select.value !== '') {
					const text = optionLabelText(select.options[select.selectedIndex], select.value);
					chips.push({
						text: `${groupLabel}: ${text}`,
						remove: () => { select.selectedIndex = 0; fetchResults(1); },
					});
				}

				// Search input
				const search = facetEl.querySelector('input[type="search"]') || facetEl.querySelector('input[type="text"]');
				if (search && search.value.trim() !== '') {
					const value = search.value.trim();
					chips.push({
						text: `“${value}”`,
						remove: () => { search.value = ''; fetchResults(1); },
					});
				}

				// Range / slider / date inputs
				const rangeMin = facetEl.querySelector('.etchfacet-min');
				const rangeMax = facetEl.querySelector('.etchfacet-max');
				if (rangeMin && rangeMax && touchedRangeFacets.has(facetEl)) {
					const text = rangeMin.value && rangeMax.value
						? `${rangeMin.value} – ${rangeMax.value}`
						: (rangeMin.value ? `After ${rangeMin.value}` : `Before ${rangeMax.value}`);
					chips.push({
						text: `${groupLabel}: ${text}`,
						remove: () => { resetRangeFacet(facetEl, rangeMin, rangeMax); fetchResults(1); },
					});
				}

				// Generic JS-driven facet value (e.g. the map viewport) — no form
				// inputs to read a label from, so fall back to the group label alone.
				if (!facetEl.querySelector('input, select') && facetEl.hasAttribute('data-etchfacet-value')) {
					chips.push({
						text: groupLabel,
						remove: () => { facetEl.removeAttribute('data-etchfacet-value'); fetchResults(1); },
					});
				}
			});

			activeFiltersContainer.innerHTML = '';
			chips.forEach((chip) => {
				const chipEl = document.createElement('span');
				chipEl.className = 'etchfacets-active-filter';

				const textEl = document.createElement('span');
				textEl.className = 'etchfacets-active-filter-text';
				textEl.textContent = chip.text;

				const removeBtn = document.createElement('button');
				removeBtn.type = 'button';
				removeBtn.className = 'etchfacets-active-filter-remove';
				removeBtn.setAttribute('aria-label', `Remove ${chip.text}`);
				removeBtn.textContent = '×';
				removeBtn.addEventListener('click', chip.remove);

				chipEl.appendChild(textEl);
				chipEl.appendChild(removeBtn);
				activeFiltersContainer.appendChild(chipEl);
			});

			activeFiltersContainer.classList.toggle('etchfacets-active-filters--empty', chips.length === 0);
		}

		// ---------------------------------------------------------------------
		// URL building
		// ---------------------------------------------------------------------

		/**
		 * Remove this instance's own params from a URLSearchParams in place —
		 * used before rebuilding them, so other instances' params on the same
		 * page are left untouched.
		 */
		function clearOwnParams(params) {
			Array.from(params.keys()).forEach((key) => {
				const parsed = parseParamKey(key);
				if (parsed && parsed.group === group) params.delete(key);
			});
		}

		/**
		 * Build a URL with facet params for fetching the filtered page.
		 * Includes _src_ and _logic_ params so the PHP pre_get_posts hook
		 * knows how to filter the query. Other instances' params (if any) are
		 * preserved untouched.
		 */
		function buildFilterUrl(page = 1) {
			const selections = collectSelections();
			const params = new URLSearchParams(window.location.search);
			clearOwnParams(params);

			// Add facet values + source + logic for each active facet.
			for (const [name, values] of Object.entries(selections.facets)) {
				params.set(gp(name), values.join(','));
				params.set(gp(`src_${name}`), selections.sources[name] || '');
				params.set(gp(`logic_${name}`), selections.logic[name] || 'or');
			}

			// Tell PHP which post type the listing targets so it only filters
			// that query — not the secondary queries Etch runs to render each
			// card's related/meta data.
			if (baseQuery && baseQuery.post_type) {
				params.set(gp('pt'), baseQuery.post_type);
			}

			if (page > 1) {
				params.set(gp('page'), page);
			}

			const qs = params.toString();
			return window.location.pathname + (qs ? `?${qs}` : '');
		}

		/**
		 * Update the browser URL (without the _src_ and _logic_ params — keep it
		 * clean). Other instances' params (if any) are preserved untouched.
		 */
		function updateUrl(page = 1) {
			const selections = collectSelections();
			const params = new URLSearchParams(window.location.search);
			clearOwnParams(params);

			for (const [name, values] of Object.entries(selections.facets)) {
				params.set(gp(name), values.join(','));
			}

			if (page > 1) {
				params.set(gp('page'), page);
			}

			const qs = params.toString();
			const newUrl = window.location.pathname + (qs ? `?${qs}` : '');
			history.pushState(null, '', newUrl);
		}

		/**
		 * Populate facet inputs from URL params belonging to this instance.
		 * Returns true if any facet state was found.
		 */
		function populateFromUrl() {
			const params = new URLSearchParams(window.location.search);
			let hasState = false;

			params.forEach((value, key) => {
				const parsed = parseParamKey(key);
				if (!parsed || parsed.group !== group) return;
				if (parsed.base.startsWith('src_') || parsed.base.startsWith('logic_')) return;

				const facetName = parsed.base;
				const facetEl = findFacetEl(facetName);
				if (!facetEl) return;

				const values = value.split(',');
				hasState = true;

				// Map viewport facet — restore bounds as a generic value, but
				// only when all four parts are finite numbers.
				if (facetEl.classList.contains('etchfacets-map') && values.length === 4) {
					const nums = values.map(Number);
					if (nums.every((n) => Number.isFinite(n))) {
						facetEl.setAttribute('data-etchfacet-value', JSON.stringify(nums));
					}
					return;
				}

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
				// Check if the URL has this instance's _src_ params (full filter
				// URL) or just clean params (shared/bookmarked URL like
				// ?_produkt_type=musserende).
				const params = new URLSearchParams(window.location.search);
				const hasSrcParams = Array.from(params.keys()).some((k) => {
					const parsed = parseParamKey(k);
					return parsed && parsed.group === group && parsed.base.startsWith('src_');
				});

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

			// Reflect the current facet UI state immediately — no need to wait
			// for the request to resolve, since chips are read straight from
			// the inputs that just changed.
			renderActiveFilters();

			// Cancel any in-flight request.
			if (currentController) {
				currentController.abort();
			}
			currentController = new AbortController();

			// Loading state.
			template.classList.add('etchfacets-loading');
			document.dispatchEvent(new CustomEvent('etchfacets:before-refresh', { detail: { group } }));

			// Notify decoupled modules (e.g. the map) that selections changed so
			// they can refresh their own data alongside the listing.
			document.dispatchEvent(
				new CustomEvent('etchfacets:selectionChanged', {
					detail: { group, selections: collectSelections() },
				})
			);

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
					const parser = new DOMParser();
					const doc = parser.parseFromString(html, 'text/html');
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
							detail: { group, page },
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
					document.dispatchEvent(new CustomEvent('etchfacets:error', { detail: { group } }));
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
			formData.append('group', group);

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
		 * ancestor. Multiple pager widgets on one instance are all kept in sync.
		 */
		const paginationPrevButtons = scopedQueryAll('.etchfacets-pagination-prev');
		const paginationNextButtons = scopedQueryAll('.etchfacets-pagination-next');
		const paginationStatusEls = scopedQueryAll('.etchfacets-pagination-status');
		const loadMoreButtons = scopedQueryAll('.etchfacets-load-more');

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
			document.dispatchEvent(new CustomEvent('etchfacets:before-refresh', { detail: { group } }));

			const filterUrl = buildFilterUrl(nextPage);

			fetch(filterUrl, {
				method: 'GET',
				headers: { 'X-EtchFacets': '1' },
				signal: currentController.signal,
			})
				.then((res) => res.text())
				.then((html) => {
					const parser = new DOMParser();
					const doc = parser.parseFromString(html, 'text/html');
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
							detail: { group, page: currentPage, appended: true },
						})
					);

					// Counts (and max_pages, via updatePaginationUi()) still
					// reflect the whole filtered set, not just what's loaded.
					fetchCounts();
				})
				.catch((err) => {
					if (err.name === 'AbortError') return;
					template.classList.remove('etchfacets-loading');
					document.dispatchEvent(new CustomEvent('etchfacets:error', { detail: { group } }));
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
				// Clear JS-driven facet values (e.g. the map viewport bounds).
				if (el.hasAttribute('data-etchfacet-value')) {
					el.removeAttribute('data-etchfacet-value');
				}

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

			// Let decoupled modules (e.g. the map) reset their own state too.
			document.dispatchEvent(new CustomEvent('etchfacets:cleared', { detail: { group } }));
		}

		// ---------------------------------------------------------------------
		// Event listeners
		// ---------------------------------------------------------------------

		const debouncedSearch = debounce(() => fetchResults(1), 300);
		const debouncedRange = debounce(() => fetchResults(1), 500);

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

			// Range/slider/number/date min-max inputs (debounced). Marks the
			// facet touched so it's treated as a genuine user selection — see
			// touchedRangeFacets above for why that can't be inferred from the
			// value alone.
			el.querySelectorAll('.etchfacet-min, .etchfacet-max').forEach((input) => {
				input.addEventListener('input', () => {
					touchedRangeFacets.add(el);
					debouncedRange();
				});
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

		// Per-facet range reset (e.g. a small icon next to a price/age/reading-time
		// slider that snaps just that one facet back to its full min–max span,
		// independent of the "Clear filters" button which resets everything).
		scopedQueryAll('.etchfacets-range-reset').forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();

				const facetEl = btn.closest('[data-etchfacet]');
				if (!facetEl) return;

				const rangeMin = facetEl.querySelector('.etchfacet-min');
				const rangeMax = facetEl.querySelector('.etchfacet-max');
				if (!rangeMin || !rangeMax) return;

				resetRangeFacet(facetEl, rangeMin, rangeMax);
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
			scopedQueryAll('.etchfacets-hierarchy').forEach((facetEl) => {
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

		// Reflect any URL-restored selections in the active-filters summary.
		renderActiveFilters();

		// ---------------------------------------------------------------------
		// Register this instance for the shared window.EtchFacets dispatcher
		// ---------------------------------------------------------------------

		instances.set(group, {
			refresh: () => fetchResults(1),
			reset: () => {
				clearAllInputs();
				fetchResults(1);
			},
			getSelections: () => collectSelections(),
			getQueryContext: () => baseQuery,
		});
	}
})();
