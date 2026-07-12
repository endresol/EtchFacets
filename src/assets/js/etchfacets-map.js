/**
 * EtchFacets — Google Maps viewport facet
 *
 * Vanilla JS (no build step). For each `.etchfacets-map` element on the page it
 * boots a Google Map, clusters markers for the current facet selections, and
 * turns the visible map bounds into a live "geo" facet value so searching the
 * current viewport filters the listing.
 *
 * It is fully decoupled from etchfacets.js: it listens to the
 * `etchfacets:selectionChanged` / `etchfacets:cleared` events and drives the
 * listing back through `window.EtchFacets.refresh()`.
 *
 * @package EtchFacets
 */
(function () {
	'use strict';

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function debounce(fn, delay) {
		let timer;
		return function (...args) {
			clearTimeout(timer);
			timer = setTimeout(() => fn.apply(this, args), delay);
		};
	}

	// A small, visually-distinct palette. Category color is a deterministic
	// hash of the term slug into this list, so the same term always gets the
	// same color across a session without any server-side color mapping.
	const COLOR_PALETTE = [
		'#4f46e5', '#059669', '#dc2626', '#d97706',
		'#0891b2', '#7c3aed', '#db2777', '#65a30d',
	];
	const DEFAULT_COLOR = '#4f46e5';

	function colorForTerm(slug) {
		if (!slug) return DEFAULT_COLOR;
		let hash = 0;
		for (let i = 0; i < slug.length; i++) {
			hash = (hash * 31 + slug.charCodeAt(i)) >>> 0;
		}
		return COLOR_PALETTE[hash % COLOR_PALETTE.length];
	}

	/**
	 * Lazily inject a script exactly once and resolve when it's loaded.
	 *
	 * @param {string} src
	 * @param {() => boolean} isReady Returns true once the script is already loaded.
	 * @returns {Promise<void>}
	 */
	const scriptPromises = new Map();
	function loadScript(src, isReady) {
		if (isReady()) return Promise.resolve();
		if (scriptPromises.has(src)) return scriptPromises.get(src);

		const promise = new Promise((resolve, reject) => {
			const script = document.createElement('script');
			script.src = src;
			script.async = true;
			script.onload = () => resolve();
			script.onerror = () => reject(new Error('EtchFacets: failed to load ' + src));
			document.head.appendChild(script);
		});
		scriptPromises.set(src, promise);
		return promise;
	}

	/**
	 * Lazily load the Google Maps JS API exactly once and resolve when ready.
	 *
	 * @param {string} apiKey Google Maps JavaScript API key.
	 * @returns {Promise<object>} Resolves with the `google.maps` namespace.
	 */
	let mapsPromise = null;
	function loadGoogleMaps(apiKey) {
		if (window.google && window.google.maps) {
			return Promise.resolve(window.google.maps);
		}
		if (mapsPromise) {
			return mapsPromise;
		}

		mapsPromise = new Promise((resolve, reject) => {
			if (!apiKey) {
				reject(new Error('EtchFacets: missing Google Maps API key.'));
				return;
			}

			const callbackName = '__etchfacetsGmapsReady';
			window[callbackName] = () => {
				resolve(window.google.maps);
				delete window[callbackName];
			};

			const script = document.createElement('script');
			script.src =
				'https://maps.googleapis.com/maps/api/js?key=' +
				encodeURIComponent(apiKey) +
				'&loading=async&callback=' +
				callbackName;
			script.async = true;
			script.defer = true;
			script.onerror = () => reject(new Error('EtchFacets: failed to load Google Maps.'));
			document.head.appendChild(script);
		});

		return mapsPromise;
	}

	/**
	 * Lazily load the MarkerClusterer library (UMD build) exactly once.
	 *
	 * @returns {Promise<object>} Resolves with the `markerClusterer` namespace.
	 */
	function loadMarkerClusterer() {
		return loadScript(
			'https://unpkg.com/@googlemaps/markerclusterer@2.5.3/dist/index.min.js',
			() => !!window.markerClusterer
		).then(() => window.markerClusterer);
	}

	/**
	 * Build a stable signature of the current selections, ignoring the map's
	 * own viewport facet. Used to avoid refetching markers on pure pan/zoom.
	 *
	 * @param {object} selections {facets, sources, logic}
	 * @param {string} mapFacetName The map facet's name.
	 * @returns {string}
	 */
	function selectionSignature(selections, mapFacetName) {
		const facets = Object.assign({}, selections.facets);
		delete facets[mapFacetName];
		const keys = Object.keys(facets).sort();
		return keys.map((k) => k + '=' + [].concat(facets[k]).join(',')).join('&');
	}

	// -------------------------------------------------------------------------
	// Per-map controller
	// -------------------------------------------------------------------------

	function initMap(el, gmaps, clustererLib, config) {
		const facetName     = el.getAttribute('data-etchfacet') || 'map';
		const colorTaxonomy = el.getAttribute('data-etchfacet-color-taxonomy') || '';

		// Parse data attributes.
		const centerAttr = (el.getAttribute('data-etchfacet-center') || '0,0').split(',');
		const center = {
			lat: parseFloat(centerAttr[0]) || 0,
			lng: parseFloat(centerAttr[1]) || 0,
		};
		const zoom = parseInt(el.getAttribute('data-etchfacet-zoom') || '11', 10);
		const minHeight = el.getAttribute('data-etchfacet-min-height');
		if (minHeight) {
			el.style.minHeight = parseInt(minHeight, 10) + 'px';
		}

		// The map element itself becomes the positioning context for the
		// floating "Search this area" button.
		if (getComputedStyle(el).position === 'static') {
			el.style.position = 'relative';
		}

		const map = new gmaps.Map(el, {
			center,
			zoom,
			mapTypeControl: false,
			streetViewControl: false,
			fullscreenControl: false,
			clickableIcons: false,
		});

		const infoWindow = new gmaps.InfoWindow();
		let clusterer = null;
		let markerObjs = [];
		const markersById = new Map(); // postId -> { marker, data }
		let lastSignature = null;
		let userMoved = false;
		let markerController = null;
		let markerRequestId = 0;
		let lastSearchedBounds = null; // JSON string of the bounds last applied to the listing.

		// --- "Search this area" button ------------------------------------------

		const searchAreaBtn = document.createElement('button');
		searchAreaBtn.type = 'button';
		searchAreaBtn.className = 'etchfacets-map-search-area';
		searchAreaBtn.textContent = 'Search this area';
		el.appendChild(searchAreaBtn);

		function currentBoundsValue() {
			const bounds = map.getBounds();
			if (!bounds) return null;
			const sw = bounds.getSouthWest();
			const ne = bounds.getNorthEast();
			return [sw.lat(), sw.lng(), ne.lat(), ne.lng()];
		}

		function showSearchAreaButton() {
			const value = currentBoundsValue();
			if (!value) return;
			if (lastSearchedBounds === JSON.stringify(value)) return;
			searchAreaBtn.classList.add('etchfacets-map-search-area--visible');
		}

		function hideSearchAreaButton() {
			searchAreaBtn.classList.remove('etchfacets-map-search-area--visible');
		}

		searchAreaBtn.addEventListener('click', () => {
			const value = currentBoundsValue();
			if (!value) return;
			el.setAttribute('data-etchfacet-value', JSON.stringify(value));
			lastSearchedBounds = JSON.stringify(value);
			hideSearchAreaButton();
			if (window.EtchFacets) {
				window.EtchFacets.refresh();
			}
		});

		// Restore a deep-linked viewport (set by etchfacets.js from the URL).
		// fitBounds will fire `idle`, but userMoved is still false so it won't
		// show the search-area button — the server already filtered.
		const initialValue = el.getAttribute('data-etchfacet-value');
		if (initialValue) {
			try {
				const b = JSON.parse(initialValue);
				if (Array.isArray(b) && b.length === 4) {
					map.fitBounds(new gmaps.LatLngBounds(
						{ lat: b[0], lng: b[1] },
						{ lat: b[2], lng: b[3] }
					));
					lastSearchedBounds = JSON.stringify(b);
				}
			} catch (e) {
				// Ignore malformed bounds.
			}
		}

		// --- Markers --------------------------------------------------------------

		function clearMarkers() {
			if (clusterer) {
				clusterer.clearMarkers();
			}
			markerObjs.forEach((m) => m.setMap(null));
			markerObjs = [];
			markersById.clear();
		}

		function markerIcon(marker) {
			let color = DEFAULT_COLOR;
			if (colorTaxonomy && marker.terms && marker.terms[colorTaxonomy] && marker.terms[colorTaxonomy][0]) {
				color = colorForTerm(marker.terms[colorTaxonomy][0].slug);
			}
			return {
				path: gmaps.SymbolPath.CIRCLE,
				fillColor: color,
				fillOpacity: 1,
				strokeColor: '#ffffff',
				strokeWeight: 2,
				scale: 9,
			};
		}

		// Build the info-window content as DOM nodes (never via innerHTML) so
		// marker titles/URLs can never inject markup.
		function infoContent(marker) {
			const wrap = document.createElement('div');
			wrap.className = 'etchfacets-map-info';

			if (marker.thumb) {
				const img = document.createElement('img');
				img.className = 'etchfacets-map-info-img';
				img.src = marker.thumb;
				img.alt = '';
				wrap.appendChild(img);
			}

			if (colorTaxonomy && marker.terms && marker.terms[colorTaxonomy] && marker.terms[colorTaxonomy][0]) {
				const term = marker.terms[colorTaxonomy][0];
				const badge = document.createElement('span');
				badge.className = 'etchfacets-map-info-badge';
				badge.style.setProperty('--etchfacets-map-badge-color', colorForTerm(term.slug));
				badge.textContent = term.name;
				wrap.appendChild(badge);
			}

			const link = document.createElement('a');
			link.className = 'etchfacets-map-info-title';
			link.href = marker.url || '#';
			link.textContent = marker.title || '';
			wrap.appendChild(link);

			return wrap;
		}

		function renderMarkers(data) {
			clearMarkers();

			markerObjs = (data.markers || []).map((m) => {
				const marker = new gmaps.Marker({
					position: { lat: m.lat, lng: m.lng },
					title: m.title || '',
					icon: markerIcon(m),
				});
				marker.addListener('click', () => {
					infoWindow.setContent(infoContent(m));
					infoWindow.open({ anchor: marker, map });
				});
				if (m.id) {
					markersById.set(m.id, { marker, data: m });
				}
				return marker;
			});

			if (clustererLib && markerObjs.length) {
				clusterer = new clustererLib.MarkerClusterer({
					map,
					markers: markerObjs,
					renderer: {
						render: ({ count, position }) => new gmaps.Marker({
							position,
							icon: {
								path: gmaps.SymbolPath.CIRCLE,
								fillColor: DEFAULT_COLOR,
								fillOpacity: 0.9,
								strokeColor: '#ffffff',
								strokeWeight: 2,
								scale: Math.min(24, 12 + Math.log2(count) * 3),
							},
							label: {
								text: String(count),
								color: '#ffffff',
								fontSize: '12px',
								fontWeight: '600',
							},
							zIndex: 1000 + count,
						}),
					},
				});
			} else {
				markerObjs.forEach((m) => m.setMap(map));
			}

			el.classList.toggle('etchfacets-map--truncated', !!data.truncated);
		}

		function fetchMarkers() {
			const api = window.EtchFacets;
			if (!api) return;

			const selections = api.getSelections();
			const signature = selectionSignature(selections, facetName);

			// Skip refetching when only the viewport changed (markers are
			// independent of the map bounds — the server ignores the geo facet).
			// Compared against the last *successfully applied* signature so a
			// legitimately empty result set doesn't refetch on every pan.
			if (signature === lastSignature) {
				return;
			}

			const queryContext = api.getQueryContext ? api.getQueryContext() : {};

			const formData = new FormData();
			formData.append('action', 'etchfacets_map');
			formData.append('nonce', config.nonce || '');
			formData.append('facets', JSON.stringify(selections.facets));
			formData.append('sources', JSON.stringify(selections.sources));
			formData.append('logic', JSON.stringify(selections.logic));
			formData.append('query_context', JSON.stringify(queryContext || {}));

			// Cancel any in-flight marker request and guard against out-of-order
			// responses (a slower earlier request must not overwrite a newer one).
			if (markerController) {
				markerController.abort();
			}
			markerController = new AbortController();
			const requestId = ++markerRequestId;

			el.classList.add('etchfacets-map--loading');

			fetch(config.ajaxUrl || '', { method: 'POST', body: formData, signal: markerController.signal })
				.then((res) => res.json())
				.then((response) => {
					if (requestId !== markerRequestId) return;
					el.classList.remove('etchfacets-map--loading');
					if (response && response.success === true) {
						lastSignature = signature;
						renderMarkers(response.data);
					}
				})
				.catch((err) => {
					if (err.name === 'AbortError') return;
					el.classList.remove('etchfacets-map--loading');
				});
		}

		// --- Viewport → "Search this area" -----------------------------------------

		const debouncedIdle = debounce(() => {
			if (userMoved) {
				showSearchAreaButton();
			}
		}, 300);

		map.addListener('dragstart', () => {
			userMoved = true;
		});
		map.addListener('zoom_changed', () => {
			userMoved = true;
		});
		map.addListener('idle', debouncedIdle);

		// --- Listing → map: select a marker when its post is clicked --------------

		/**
		 * Pan to, briefly bounce, and open the info window for a marker by
		 * post ID. Only affects markers currently loaded on THIS map — a post
		 * outside the active filters/viewport-independent marker set (e.g. one
		 * excluded by a taxonomy facet) has no corresponding marker to select.
		 *
		 * @param {number} postId
		 * @returns {boolean} Whether a matching marker was found and selected.
		 */
		function selectMarker(postId) {
			const entry = markersById.get(postId);
			if (!entry) return false;

			const { marker, data } = entry;
			map.panTo(marker.getPosition());
			if (map.getZoom() < 12) {
				map.setZoom(12);
			}

			infoWindow.setContent(infoContent(data));
			infoWindow.open({ anchor: marker, map });

			marker.setAnimation(gmaps.Animation.BOUNCE);
			setTimeout(() => marker.setAnimation(null), 700);
			return true;
		}

		// Any element with data-etchfacets-post-id (e.g. a listing card) selects
		// that post's marker on click — except clicks on a real link inside it
		// (like a "View" link), which should navigate normally instead.
		document.addEventListener('click', (e) => {
			if (e.target.closest('a')) return;

			const trigger = e.target.closest('[data-etchfacets-post-id]');
			if (!trigger) return;

			const postId = parseInt(trigger.getAttribute('data-etchfacets-post-id'), 10);
			if (!postId) return;

			if (selectMarker(postId)) {
				el.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
		});

		// --- Wiring to the facet pipeline -------------------------------------------

		// Refresh markers whenever non-viewport selections change.
		document.addEventListener('etchfacets:selectionChanged', fetchMarkers);

		// Reset: drop the viewport facet and restore the initial view.
		document.addEventListener('etchfacets:cleared', () => {
			el.removeAttribute('data-etchfacet-value');
			lastSearchedBounds = null;
			userMoved = false;
			hideSearchAreaButton();
			map.setCenter(center);
			map.setZoom(zoom);
		});

		// Initial marker load.
		fetchMarkers();
	}

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	document.addEventListener('DOMContentLoaded', () => {
		const mapEls = document.querySelectorAll('.etchfacets-map');
		if (!mapEls.length) return; // No maps on this page — exit silently.

		const config = window.etchfacetsMapConfig || {};

		Promise.all([loadGoogleMaps(config.apiKey), loadMarkerClusterer().catch(() => null)])
			.then(([gmaps, clustererLib]) => {
				mapEls.forEach((el) => initMap(el, gmaps, clustererLib, config));
			})
			.catch((err) => {
				mapEls.forEach((el) => {
					el.classList.add('etchfacets-map--error');
				});
				console.error(err);
			});
	});
})();
