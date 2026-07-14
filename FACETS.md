# EtchFacets — Facet Types & Usage

This page documents every facet type currently implemented in EtchFacets, the
data source it pulls from, the UI variants it supports, and how to render it
using the shortcode, the PHP helper, or hand-built Etch markup.

For the architectural overview (how the AJAX + count pipeline works), see
[PLUGIN_REFERENCE.md](PLUGIN_REFERENCE.md).

---

## Concepts in 30 Seconds

A facet has two halves:

1. A **data source** — what the facet filters by. Format: `type:key`
   (e.g. `taxonomy:category`, `meta:color`). Some sources have no key
   (e.g. `search`, `author`).
2. A **UI type** — how the user interacts with it (checkboxes, dropdown,
   range, …).

Sources are interpreted server-side by `EtchFacets_Query_Builder`; UI types are
interpreted client-side by `etchfacets.js`. Any source can in principle be
paired with any compatible UI type.

Inside one facet, multiple selected values default to **OR** (`taxonomy:category`
with "Red" + "Blue" = posts in Red *or* Blue). Set `logic="and"` to require all
selected values. Between facets the relation is always **AND**.

---

## Quick Reference

| Source              | What it filters             | Default UI    | Notes                                          |
|---------------------|-----------------------------|---------------|------------------------------------------------|
| `taxonomy:{name}`   | Posts in matching terms     | checkboxes    | Supports `or` / `and`, hierarchical trees      |
| `meta:{key}`        | Posts with matching meta    | checkboxes    | Supports `or` / `and`                          |
| `meta_range:{key}`  | Posts whose numeric meta is between min/max | range | Auto-detects min/max from DB |
| `search`            | Free-text WP search (`s`)   | search        | Debounced 300 ms                               |
| `author`            | Posts by author ID(s)       | checkboxes / dropdown | Values are author IDs                  |
| `date`              | Posts within a date range   | range         | Values = `[after, before]`, `YYYY-MM-DD`       |
| `post_type`         | Restrict to post types      | checkboxes / dropdown | Values are post type slugs             |
| `geo:{lat},{lng}`   | Posts inside the map viewport | map         | Google Map; bounds become a live filter        |
| `sort`              | Orders the listing (no filtering) | dropdown | Value is one combined key, e.g. `date_desc`    |

---

## How to Render a Facet

You have three options, in order of convenience:

### 1. Shortcode

```text
[etchfacets_facet
    name="category"
    source="taxonomy:category"
    type="checkboxes"
    logic="or"
    label="Category"
    show_counts="true"]
```

| Attribute     | Default       | Description                                            |
|---------------|---------------|--------------------------------------------------------|
| `name`        | *(required)*  | Unique facet name. Used as the URL key (`_<name>`).    |
| `source`      | *(required)*  | Data source string (see table above).                  |
| `type`        | `checkboxes`  | UI type: `checkboxes`, `radio`, `dropdown`, `search`, `range`. |
| `logic`       | `or`          | `or` or `and` — within this facet.                     |
| `label`       | *(empty)*     | Optional `<h3>` label rendered above choices.          |
| `show_counts` | `true`        | Render `(N)` count badges next to each choice.         |
| `class`       | *(empty)*     | Extra CSS class on the wrapper.                        |
| `post_type`   | *(empty)*     | For `taxonomy:`/`meta:` sources shared by more than one post type, scopes the choice list and counts to this post type. Leave empty only if the taxonomy/meta key belongs to a single post type. |
| `hide_empty`  | `true`        | For `taxonomy:` sources, omit terms with zero matching posts (within `post_type`, if set). Set to `false` to always list every term. |

### 2. PHP helper

```php
etchfacets_display( 'category', 'taxonomy:category', [
    'type'  => 'dropdown',
    'label' => 'Category',
] );
```

Same options as the shortcode.

### 3. Hand-built Etch markup

The plugin only requires the data attributes; the markup itself is yours.

```html
<div data-etchfacet="category"
     data-etchfacet-source="taxonomy:category"
     data-etchfacet-logic="or">
    <label><input type="checkbox" value="design"> Design
        <span class="etchfacet-count"></span></label>
    <label><input type="checkbox" value="photo"> Photography
        <span class="etchfacet-count"></span></label>
</div>
```

`etchfacets.js` discovers the facet via `[data-etchfacet]` and reads selections
from whichever inputs it finds (checkbox / radio / select / search / range).

---

## Facet Types in Detail

### Taxonomy — `taxonomy:{name}`

Filters posts by terms of any registered taxonomy. Values are **term slugs**.

```text
[etchfacets_facet name="category" source="taxonomy:category" label="Category"]
[etchfacets_facet name="tag"      source="taxonomy:post_tag" type="dropdown"]
[etchfacets_facet name="brand"    source="taxonomy:product_brand" logic="and"]
```

Choices are populated automatically via `get_terms()` (only non-empty terms
by default).

**Shared taxonomies.** If this taxonomy is registered on more than one post
type (e.g. `category` used by both `post` and a custom `event` CPT), always
set `post_type` to match the sibling `[etchfacets_listing]`'s post type —
otherwise the term list and counts are global across every post type using
the taxonomy, which usually isn't what you want. The same applies to the
`/wp-json/etchfacets/v1/choices` REST endpoint that backs the native Etch
Loop-based facet components (`?source=taxonomy:category&post_type=event`);
pass `hide_empty=false` there to include zero-count terms. This scoping also
applies to `meta:` sources sharing a meta key across post types.

**Hierarchical (tree) variant.** Parent/child taxonomies (e.g. `category`,
`product_cat`) can be rendered as a collapsible tree. Use the `etchfacets-*`
tree classes; the JS handles toggles, and when a parent is selected its
children are automatically included in the query.

```html
<div class="etchfacets-hierarchy"
     data-etchfacet="category"
     data-etchfacet-source="taxonomy:category">
    <div class="etchfacets-tree-item">
        <button class="etchfacets-tree-toggle" type="button">+</button>
        <label><input type="checkbox" value="design"> Design
            <span class="etchfacet-count"></span></label>
        <div class="etchfacets-tree-children" hidden>
            <div class="etchfacets-tree-item">
                <label><input type="checkbox" value="ui"> UI
                    <span class="etchfacet-count"></span></label>
            </div>
            <div class="etchfacets-tree-item">
                <label><input type="checkbox" value="branding"> Branding
                    <span class="etchfacet-count"></span></label>
            </div>
        </div>
    </div>
</div>
```

### Meta — `meta:{key}`

Filters by exact match on a `postmeta` value. Distinct values are auto-listed
from the DB.

```text
[etchfacets_facet name="color" source="meta:color"     label="Colour"]
[etchfacets_facet name="brand" source="meta:_brand"    type="dropdown"]
[etchfacets_facet name="size"  source="meta:size"      logic="and"]
```

With `logic="and"` the query requires the post to have **all** selected values
on that meta key (useful for repeating meta).

### Meta range — `meta_range:{key}`

Numeric range filter on a single meta key. The shortcode pre-computes the
min/max from the database and seeds the inputs' `min`/`max` attributes.

```text
[etchfacets_facet name="price" source="meta_range:price" type="range" label="Price"]
```

Or hand-built — you only need two inputs with the `.etchfacet-min` /
`.etchfacet-max` classes:

```html
<div data-etchfacet="price" data-etchfacet-source="meta_range:price">
    <input type="number" class="etchfacet-min" placeholder="Min">
    <input type="number" class="etchfacet-max" placeholder="Max">
</div>
```

Values are sent as `[min, max]` and translated to a `meta_query` `BETWEEN`
clause with `NUMERIC` casting.

A range/slider facet's own min/max bounds (as returned by the count endpoint,
and used to auto-size the inputs) always span the **entire post type** —
they deliberately ignore every other active facet, so the slider's draggable
extent stays fixed regardless of what else is filtered. Only the *value*
you've chosen within those bounds acts as a filter, and only once you've
actually touched the control (see "Active filters summary" below).

**Reset-to-full-range icon.** Drop a `.etchfacets-range-reset` button
anywhere inside the facet container and it'll snap that facet's inputs back
to their full min/max (or clear a date range) and re-fetch — independent of
the page-wide `[etchfacets_reset]` button, which clears everything:

```html
<div data-etchfacet="price" data-etchfacet-source="meta_range:price">
    <input type="number" class="etchfacet-min">
    <input type="number" class="etchfacet-max">
    <button type="button" class="etchfacets-range-reset" aria-label="Reset price range">↺</button>
</div>
```

### Search — `search`

Forwards the input to `WP_Query`'s `s` parameter. Input is debounced 300 ms.

```text
[etchfacets_facet name="q" source="search" type="search" label="Search"]
```

Hand-built:

```html
<div data-etchfacet="q" data-etchfacet-source="search">
    <input type="search" placeholder="Search…">
</div>
```

### Author — `author`

Filters by author IDs (`author__in`). Currently no built-in choice generator;
you must provide the inputs yourself.

```html
<div data-etchfacet="author" data-etchfacet-source="author">
    <label><input type="checkbox" value="1"> Alice
        <span class="etchfacet-count"></span></label>
    <label><input type="checkbox" value="2"> Bob
        <span class="etchfacet-count"></span></label>
</div>
```

### Date — `date`

Maps to `date_query` with optional `after` / `before` bounds. Send values as
`[after, before]` using `YYYY-MM-DD` (or anything `strtotime` understands).
Hand-build with two date inputs sharing the range class convention:

```html
<div data-etchfacet="published" data-etchfacet-source="date">
    <input type="date" class="etchfacet-min">
    <input type="date" class="etchfacet-max">
</div>
```

### Post type — `post_type`

Switches the queried post type(s) at request time. Values are post type slugs.

```html
<div data-etchfacet="kind" data-etchfacet-source="post_type">
    <label><input type="checkbox" value="post"> Posts</label>
    <label><input type="checkbox" value="product"> Products</label>
</div>
```

### Sort — `sort`

Orders the listing. Unlike every other source type, it never narrows
results — it's excluded from count calculation and from the active-filters
summary (see below), since there's nothing to "clear".

The `<select>`'s value is a single combined key mapping to `orderby`/`order`:

| Value         | Meaning                          |
|---------------|-----------------------------------|
| `date_desc`   | Newest first (the typical default) |
| `date_asc`    | Oldest first                      |
| `title_asc`   | A–Z                                |
| `title_desc`  | Z–A                                |
| `relevance`   | Best match — only meaningful alongside an active `search` facet; otherwise WordPress quietly falls back to its default ordering. |

```html
<div data-etchfacet="sort" data-etchfacet-source="sort">
    <select>
        <option value="date_desc">Newest first</option>
        <option value="date_asc">Oldest first</option>
        <option value="title_asc">A–Z</option>
        <option value="title_desc">Z–A</option>
        <option value="relevance">Best match</option>
    </select>
</div>
```

Give the `<select>` a non-empty default value (e.g. pre-select `date_desc`) —
an empty `value=""` is treated the same as "no selection" by the generic
dropdown handling, so nothing would be sent until the user touches it.

### Map — `geo:{lat_key},{lng_key}`

Renders a **Google Map** that plots posts with latitude/longitude meta and uses
the **visible map bounds** as a live filter. Source format is
`geo:LAT_META_KEY,LNG_META_KEY` (defaults `_ef_lat` / `_ef_lng`).

How it behaves alongside other facets:

- Selecting taxonomies / meta / search filters **both** the listing and the
  markers.
- Panning or zooming shows a **"Search this area"** button rather than
  filtering instantly (see below) — clicking it filters the **listing and
  every other facet's counts** to what's currently in view. The markers
  themselves are not re-filtered by the viewport, so you always see every
  marker matching the other facets regardless of where you've panned.
- Reset clears the viewport filter and restores the initial center/zoom.

**Requirements**

1. A Google Maps JavaScript API key — set it under **Settings → EtchFacets**
   (or via the `etchfacets/map/api_key` filter / a `wp-config.php` constant
   surfaced through that filter).
2. The faceted posts must have two numeric meta values: latitude and longitude
   (defaults `_ef_lat` and `_ef_lng`).
3. A `[etchfacets_listing]` (or equivalent `.etchfacets-template`) must be on the
   page — the map drives that listing.

**Shortcode**

```text
[etchfacets_map
    name="map"
    lat_key="_ef_lat"
    lng_key="_ef_lng"
    center="59.913,10.739"
    zoom="11"
    height="480"]
```

| Attribute | Default    | Description                              |
|-----------|------------|-------------------------------------------|
| `name`    | `map`      | Facet name (URL key `_map`).             |
| `lat_key` | `_ef_lat`  | Latitude meta key.                       |
| `lng_key` | `_ef_lng`  | Longitude meta key.                      |
| `center`  | `0,0`      | Initial center `lat,lng`.                |
| `zoom`    | `11`       | Initial zoom level.                      |
| `height`  | `480`      | Map height in pixels.                    |

**Hand-built Etch markup**

```html
<div class="etchfacets-map"
     data-etchfacet="map"
     data-etchfacet-source="geo:_ef_lat,_ef_lng"
     data-etchfacet-center="59.913,10.739"
     data-etchfacet-zoom="11"
     data-etchfacet-min-height="480"
     data-etchfacet-color-taxonomy="location-type"
     style="width:100%;height:480px"></div>
```

`data-etchfacet-color-taxonomy` (optional) — color-codes markers and the info
window's category badge by a taxonomy's terms. The color per term slug is a
deterministic hash into a fixed palette, so it's stable across page loads
without any server-side color config. Omit it for single-color markers.

**Built in, no extra markup needed**

- **Clustering** — nearby markers group into a numbered cluster bubble at low
  zoom and split apart as you zoom in (via `@googlemaps/markerclusterer`,
  loaded lazily from a CDN alongside the Maps API).
- **"Search this area"** — panning/zooming shows a floating button instead of
  refiltering immediately; the listing only updates once you click it. Keeps
  exploration (panning around, zooming) from firing a filter request on every
  micro-movement.
- **Rich info windows** — marker click shows a styled popup with photo (falls
  back to a `_ef_photo` meta URL when there's no featured image), category
  badge, and a link to the post.

**Extensibility filters (PHP)**

| Hook                         | Purpose                              |
|-------------------------------|--------------------------------------|
| `etchfacets/map/api_key`     | Override the API key resolution.     |
| `etchfacets/map/coord_keys`  | Override `[lat_key, lng_key]`.       |
| `etchfacets/map/max_markers` | Cap markers per request (def. 500).  |
| `etchfacets/map/marker`      | Mutate a single marker payload.      |
| `etchfacets/map/markers`     | Mutate the full marker array.        |

---

## UI Types in Detail

The same source can usually be rendered with any of these. The JS auto-detects
which inputs are present inside `[data-etchfacet]` and uses them.

| UI Type     | Markup                                                 | Trigger             |
|-------------|--------------------------------------------------------|---------------------|
| Checkboxes  | `input[type="checkbox"]`                               | `change` (immediate) |
| Radio       | `input[type="radio"]` (one value per facet)            | `change` (immediate) |
| Dropdown    | `<select>` with empty `value=""` as "All"              | `change` (immediate) |
| Search      | `input[type="search"]` or `input[type="text"]`         | `input` (300 ms debounce) |
| Range       | `.etchfacet-min` + `.etchfacet-max` (number / date / range) | `input` (500 ms debounce) |
| Hierarchical | Checkboxes inside `.etchfacets-tree-item` / `.etchfacets-tree-children` | `change` + tree toggle clicks |

### Counts and ghost / hidden states

Add `<span class="etchfacet-count"></span>` inside each `<label>` and the JS
will keep it in sync. When a choice would yield 0 results and is not selected,
JS applies `.etchfacet-ghost` (dimmed, non-interactive) and `.etchfacet-hidden`
(hidden) so you can style empties however you like.

---

## Companion Pieces

Things you'll usually drop on the page along with facets:

```text
[etchfacets_listing post_type="product" posts_per_page="12"]
[etchfacets_reset text="Clear filters"]
[etchfacets_results_count]
```

| Shortcode                  | Output                                                                       |
|----------------------------|------------------------------------------------------------------------------|
| `[etchfacets_listing]`     | The `.etchfacets-template` container (initial server-rendered loop inside).  |
| `[etchfacets_reset]`       | `<button class="etchfacets-reset">` — clears all inputs and refetches.       |
| `[etchfacets_results_count]` | `<span data-etchfacets-total>` — live total post count.                    |

`[etchfacets_listing]` accepts `post_type`, `posts_per_page`, `orderby`,
`order`, `id`, `class`. Or build your own container with the equivalent
`data-etchfacets-*` attributes — see [PLUGIN_REFERENCE.md](PLUGIN_REFERENCE.md#listing-container).

### Active filters summary

Drop a `[data-etchfacets-active-filters]` container anywhere on the page and
the JS keeps it populated with a removable "chip" for every currently active
facet value — labels are read straight from the facet UI already on the page
(a checkbox's `<label>` text, a `<select>`'s selected `<option>`, etc.), no
extra config needed:

```html
<div data-etchfacets-active-filters></div>
```

Each chip's `×` clears just that one value and re-fetches. Entirely optional
— nothing renders here unless the container exists on the page. A `sort`
facet (see above) never appears here, since it doesn't filter anything.

---

## Pagination

Two hand-built markup patterns, both driven by the same page state
(`posts_per_page` from the listing container, `paged` on the query). Pick
one per page — combining both against the same listing works but produces
confusing UX (Prev after a Load More click drops the appended posts, since
Prev/Next replace the listing rather than append to it).

### Prev / Next pager

```html
<div class="etchfacets-pagination">
    <button class="etchfacets-pagination-prev" type="button">Previous</button>
    <span class="etchfacets-pagination-status"></span>
    <button class="etchfacets-pagination-next" type="button">Next</button>
</div>
```

- `.etchfacets-pagination-prev` / `-next` — buttons; auto-disabled at the
  first/last page.
- `.etchfacets-pagination-status` — auto-populated with `Page X of Y`.
- Replaces the listing's contents with the requested page (same behavior
  as a facet change).

Numbered links are also supported without any of the above markup — any
`<a class="page-numbers">` (e.g. from `the_posts_pagination()`) or
`.etchfacets-pagination a` inside the listing container gets its clicks
intercepted automatically; the page number is parsed from `?paged=N` or
`/page/N/` in the link's `href`.

### Load More

```html
<button class="etchfacets-load-more" type="button">Load more</button>
```

Fetches the next page and **appends** it to the existing listing instead
of replacing it. Batch size is the listing's `posts_per_page`. Auto-disables
(and gets the `etchfacets-load-more--exhausted` class) once every post has
been loaded.

### Shared behavior

- Any facet change, or `[etchfacets_reset]`, resets back to page 1 and
  clears anything a Load More click had appended.
- The clean bookmarkable URL includes `_page=N` once past page 1
  (e.g. `?_category=design&_page=2`).

---

## URL State

Active facets are reflected in the URL so selections are bookmarkable and
back/forward navigation works:

```
/shop/?_category=design,photography&_price=100,500
```

The JS rehydrates inputs from the URL on page load and writes back via
`history.pushState` after each filter.

---

## JS API

```js
window.EtchFacets.refresh()        // Re-fetch with current selections
window.EtchFacets.reset()          // Clear all inputs and re-fetch
window.EtchFacets.getSelections()  // { facets, sources, logic }
window.EtchFacets.on('loaded', fn) // Listen to etchfacets:loaded
window.EtchFacets.on('error',  fn) // Listen to etchfacets:error
```

---

## Roadmap

The **map viewport** facet (Google Maps) is implemented, including marker
clustering, category-colored markers, and a "Search this area" pan/zoom
pattern — see the [Map](#map--geolat_keylng_key) section above and the
original design notes in [MAP_FACET_PLAN.md](MAP_FACET_PLAN.md). Future map
work: a "zoom in for more" hint when results are truncated, and an admin
metabox for entering coordinates.
