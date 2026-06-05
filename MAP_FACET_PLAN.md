# Map Facet — Implementation Plan

Add a Google Maps–based facet to EtchFacets so a `location` (or any geo-enabled)
CPT can be plotted on a map and filtered live alongside the existing taxonomy /
meta facets. The map participates in the same facet pipeline: selecting terms or
values filters the markers; panning/zooming the map filters the result list.

---

## 1. Goals & Non-Goals

### Goals
- Render a Google Map showing posts of a chosen CPT that have geo coordinates.
- Apply existing facet selections (taxonomies, meta, search…) to the markers.
- Add a new **map viewport facet** that uses the visible map bounds as a filter
  (so panning/zooming narrows the results list).
- Show counts and listings consistently with the rest of the facet system.
- Keep it framework-agnostic in the PHP layer; Google Maps stays in JS.

### Non-Goals (v1)
- Multiple map providers (Mapbox, Leaflet) — pluggable later.
- Server-side clustering / heatmaps (use Google's `MarkerClusterer` on the
  client).
- Geocoding addresses on save (admin UX). v1 expects lat/lng meta to already be
  present; we only document the expected meta keys.
- Routing / directions.

---

## 2. Architecture Overview

```diagram
╭───────────────────────────╮      ╭────────────────────────────╮
│  Etch Builder (frontend)  │─────▶│  data-etchfacet="map"      │
│  drops a Map control      │      │  data-etchfacets-map       │
╰───────────────────────────╯      ╰─────────────┬──────────────╯
                                                 │
                                                 ▼
╭───────────────────────────╮      ╭────────────────────────────╮
│  etchfacets.js            │─────▶│  etchfacets-map.js         │
│  collects selections      │      │  - init Google Map         │
│  + map bounds             │      │  - render markers/clusters │
│  POST → admin-ajax        │      │  - emit bounds change      │
╰─────────────┬─────────────╯      ╰────────────────────────────╯
              │
              ▼
╭───────────────────────────────────────────────────────────────╮
│ class-ajax-handler.php                                        │
│   handle_filter()  ─ existing list + counts                   │
│   handle_map()     ─ NEW: returns marker dataset for the map  │
╰─────────────┬─────────────────────────────────────────────────╯
              │
              ▼
╭───────────────────────────────────────────────────────────────╮
│ class-query-builder.php                                       │
│   adds "geo" source type → posts_clauses filter for bbox      │
╰───────────────────────────────────────────────────────────────╯
```

The map is just **another facet source** (`geo:lat_key,lng_key`) plus a new
output channel (`marker dataset`). The list and the map both update from the
same selection state held in `etchfacets.js`.

---

## 3. Data Model

A faceted CPT (e.g. `location`) needs two numeric meta fields:

| Meta key            | Default        | Type    | Notes                       |
| ------------------- | -------------- | ------- | --------------------------- |
| Latitude            | `_ef_lat`      | float   | Stored as string in WP meta |
| Longitude           | `_ef_lng`      | float   | Stored as string in WP meta |

Both keys are configurable via the data-attribute and via a PHP filter
`etchfacets/map/coord_keys`.

Optional meta consumed by the map UI (all overrideable):

| Purpose            | Default key    |
| ------------------ | -------------- |
| Marker label       | post title     |
| Info-window image  | featured image |
| Info-window body   | excerpt        |

---

## 4. PHP Changes

### 4.1 New source type: `geo`
File: [src/includes/class-query-builder.php](src/includes/class-query-builder.php)

Extend `build_query_args()` with a `case 'geo':` branch that adds a
**bounding-box filter** via `posts_clauses` (we cannot express this cleanly with
`meta_query` without two `BETWEEN` clauses + `JOIN`s, but we can — start with
the simple `meta_query` approach and switch to a custom `posts_clauses` join if
performance demands).

Source format: `geo:_ef_lat,_ef_lng`
Values format: `[swLat, swLng, neLat, neLng]` (4 floats, sent by JS).

```php
case 'geo':
    [$lat_key, $lng_key] = array_pad( explode( ',', $source['value'] ), 2, '' );
    if ( count( $values ) !== 4 ) { break; }
    [$sw_lat, $sw_lng, $ne_lat, $ne_lng] = array_map( 'floatval', $values );

    $meta_query[] = [
        'relation' => 'AND',
        [ 'key' => $lat_key, 'value' => [$sw_lat, $ne_lat], 'type' => 'DECIMAL(10,6)', 'compare' => 'BETWEEN' ],
        [ 'key' => $lng_key, 'value' => [$sw_lng, $ne_lng], 'type' => 'DECIMAL(10,6)', 'compare' => 'BETWEEN' ],
    ];
    // Antimeridian crossing (sw_lng > ne_lng) handled with OR clause.
    break;
```

Add a static helper `is_geo_source( $source )` so the count calculator can skip
the geo facet (counts shouldn't be affected by viewport).

### 4.2 New AJAX action: `etchfacets_map_markers`
File: [src/includes/class-ajax-handler.php](src/includes/class-ajax-handler.php)

- Same input shape as `handle_filter` (facets, sources, logic, query_context).
- Builds the same `WP_Query` args **but**:
  - Forces `posts_per_page = -1` (capped at a configurable max, default 500).
  - Limits SELECT to `ID` and required meta via `fields => 'ids'` + a single
    `get_post_meta` round-trip (or `update_meta_cache`).
- Returns a JSON marker array:

```json
{
  "markers": [
    { "id": 12, "lat": 59.91, "lng": 10.74, "title": "Foo", "url": "https://…", "thumb": "…" }
  ],
  "total": 42,
  "truncated": false
}
```

Hook it under `wp_ajax_etchfacets_map` / `wp_ajax_nopriv_etchfacets_map`.

### 4.3 Count calculator
File: [src/includes/class-count-calculator.php](src/includes/class-count-calculator.php)

- Skip the geo viewport facet when computing counts: panning the map should not
  change taxonomy counts. (Add a `should_count_facet()` early-return.)

### 4.4 Renderer / asset enqueue
File: [src/etchfacets.php](src/etchfacets.php)

- New script `assets/js/etchfacets-map.js`.
- Conditional Google Maps loader (only enqueue when a `.etchfacets-map` element
  exists on the page — use `wp_enqueue_scripts` + late `wp_footer` check, or
  enqueue inline via the script itself).
- Inject API key + map options:

```php
wp_localize_script( 'etchfacets-map', 'etchfacetsMapConfig', [
    'apiKey'     => apply_filters( 'etchfacets/map/api_key', get_option( 'etchfacets_gmaps_key', '' ) ),
    'libraries'  => [ 'marker' ],
    'cluster'    => true,
    'maxMarkers' => 500,
] );
```

- Settings page (small): one field for the Google Maps API key under
  `Settings → EtchFacets`. (If we already have a settings UI, reuse it; otherwise
  add a tiny `class-settings.php`.)

### 4.5 Filter hooks (extensibility)

| Hook                                  | Purpose                                |
| ------------------------------------- | -------------------------------------- |
| `etchfacets/map/coord_keys`           | Override `[lat_key, lng_key]`.         |
| `etchfacets/map/marker`               | Mutate a single marker payload.        |
| `etchfacets/map/markers`              | Mutate the full marker array.          |
| `etchfacets/map/max_markers`          | Cap markers per request.               |
| `etchfacets/map/api_key`              | Override the API key resolution.       |

---

## 5. Frontend (JS) Changes

### 5.1 New file: `src/assets/js/etchfacets-map.js`

Vanilla JS (matches the project style — no deps). Responsibilities:

1. Lazy-load the Google Maps JS API once, using the key from
   `etchfacetsMapConfig`. Use the recommended bootstrap loader (`importLibrary`).
2. For each `.etchfacets-map` element on the page:
   - Init a `google.maps.Map` with the configured center/zoom (data-attrs).
   - Subscribe to `etchfacets:selectionChanged` (custom event from
     [etchfacets.js](src/assets/js/etchfacets.js)) to re-fetch markers.
   - Subscribe to its own `idle` event (debounced ~300 ms) to:
     - Read map bounds → push them as the value of the `map` facet on the
       shared selection store.
     - Trigger the same selection-changed flow → list re-renders + markers
       refresh.
3. Maintain a `MarkerClusterer` (loaded from CDN or bundled later).
4. Render info-window content from the marker payload.

### 5.2 Changes to `src/assets/js/etchfacets.js`

- Emit a `etchfacets:selectionChanged` `CustomEvent` whenever selections
  change (so the map module can listen without tight coupling).
- Extend the selection collector to recognise `data-etchfacet="map"` elements
  whose value is a `[swLat, swLng, neLat, neLng]` JSON array.
- When the response comes back, dispatch `etchfacets:resultsUpdated` with the
  same payload — map can highlight markers matching the visible page, etc.

### 5.3 Builder control
File: [src/assets/js/etchfacets-builder.js](src/assets/js/etchfacets-builder.js)

Register a new control type **"Map"** in the Etch Settings Bar:

- Coordinate meta keys (lat / lng) — text inputs.
- Initial center (lat,lng).
- Initial zoom.
- Cluster on/off.
- Aspect ratio / min-height.
- Optional info-window template ref.

Output a `<div class="etchfacets-map" data-etchfacet="map" data-etchfacets-map ...>`
with the configuration baked into data attributes.

---

## 6. Markup Contract

```html
<div
  class="etchfacets-map"
  data-etchfacet="map"
  data-etchfacets-source="geo:_ef_lat,_ef_lng"
  data-etchfacets-center="59.913,10.739"
  data-etchfacets-zoom="11"
  data-etchfacets-cluster="true"
  data-etchfacets-min-height="480"
  style="width:100%;height:480px"
></div>
```

The map participates in the same `_facetname` URL-param scheme:
`?_map=59.85,10.6,59.97,10.9&_src_map=geo:_ef_lat,_ef_lng&_logic_map=or`

---

## 7. CSS Additions

File: [src/assets/css/etchfacets.css](src/assets/css/etchfacets.css)

- `.etchfacets-map { position: relative; min-height: 320px; }`
- `.etchfacets-map.is-loading::after { /* spinner overlay */ }`
- `.etchfacets-map-info { /* info-window typography */ }`

---

## 8. Performance Considerations

- Cap markers per request (`max_markers`, default 500) and surface a
  `truncated: true` flag → frontend shows a "Zoom in for more" hint.
- Use `fields => 'ids'` + `update_meta_cache` to avoid hydrating full posts.
- Debounce map `idle` events to ~300 ms.
- Cancel in-flight map AJAX when a new bounds change happens (reuse the
  `AbortController` pattern already in `etchfacets.js`).
- Optional: server-side viewport indexing using a custom table later (out of
  scope for v1, but the `posts_clauses` filter approach makes a future swap
  invisible to callers).

---

## 9. Settings & Configuration

New Settings page under **Settings → EtchFacets**:

| Setting               | Storage                       |
| --------------------- | ----------------------------- |
| Google Maps API key   | option `etchfacets_gmaps_key` |
| Default lat meta key  | option (default `_ef_lat`)    |
| Default lng meta key  | option (default `_ef_lng`)    |
| Default map style ID  | option (optional)             |

(If a settings page already exists in another plugin section, add to it rather
than create a new one.)

---

## 10. Testing Checklist

Manual:
- [ ] Map renders on a page that contains both list + map + facets.
- [ ] Selecting a taxonomy term filters BOTH list and map.
- [ ] Panning the map filters the list.
- [ ] Zooming in narrows the result count; zooming out widens it.
- [ ] Counts on other facets are NOT affected by panning the map.
- [ ] Reset button clears the map viewport facet too.
- [ ] URL contains the map bounds and is sharable (deep-link restores state).
- [ ] No JS errors when no map is on the page (script self-exits).
- [ ] No PHP fatals when `_ef_lat`/`_ef_lng` are missing on some posts.

Automated (later, low priority):
- PHPUnit: `EtchFacets_Query_Builder::build_query_args()` with `geo:` source.
- PHPUnit: count calculator skips geo facet.

---

## 11. Rollout Phases

1. **Phase 1 — Server foundation**
   - Add `geo` source to query builder.
   - Add `etchfacets_map` AJAX endpoint returning marker payload.
   - Skip geo in count calculator.
   - Add settings field for API key.

2. **Phase 2 — Frontend**
   - `etchfacets-map.js` with Google Maps loader, markers, info-windows.
   - Wire `etchfacets:selectionChanged` events into existing `etchfacets.js`.
   - CSS polish + loading state.

3. **Phase 3 — Etch Builder integration**
   - New "Map" control in `etchfacets-builder.js`.
   - Document data-attribute contract in `PLUGIN_REFERENCE.md`.

4. **Phase 4 — Polish**
   - MarkerClusterer.
   - "Zoom in for more" UX when truncated.
   - Optional: simple admin metabox for entering lat/lng on the CPT (or rely on
     ACF / Meta Box already used in the project).

---

## 12. Open Questions

- Which CPT will be the primary target? (`location`, or several?)
- Are coordinates already stored, and under which meta keys?
- Do we need clustering on day one, or is a flat marker layer enough?
- Should the map be filterable independently (its own scope), or always be
  joined to the same list as the other facets on the page?
- API key management: per-site option, or constant in `wp-config.php`?
