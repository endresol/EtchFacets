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

Choices are populated automatically via `get_terms()` (only non-empty terms).

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

A **map viewport** facet (Google Maps) using `meta:lat` / `meta:lng` and the
visible map bounds as a live filter is planned — see
[MAP_FACET_PLAN.md](MAP_FACET_PLAN.md). It is not implemented yet.
