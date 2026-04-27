# EtchFacets — Implementation Plan (Prototype-First)

A custom faceted search plugin for WordPress/EtchWP. Prototype-first approach: get filtering working fast, optimize later.

---

## Philosophy

1. **Query-first** — No custom index table for v1. Filter directly via `WP_Query` (`tax_query`, `meta_query`). Add indexing later for performance.
2. **Etch-built UI** — Facet UI components (checkboxes, dropdowns, etc.) are built visually in Etch's editor by the developer. The plugin provides the AJAX engine + JS controller, not the markup.
3. **Progressive enhancement** — Start with shortcodes/data-attributes, migrate to native Etch elements when the Components API ships.

---

## Architecture Overview

```
EtchFacets Plugin (v1 Prototype)
├── AJAX Handler
│   ├── Reads facet selections from request
│   ├── Builds WP_Query (tax_query / meta_query)
│   └── Returns JSON (HTML + counts)
├── Query Builder
│   ├── Translates facet selections → WP_Query args
│   └── Calculates facet counts (per-choice result totals)
└── Frontend JS (etchfacets.js)
    ├── Listens to [data-etchfacet] elements
    ├── Collects selections → AJAX POST
    ├── Replaces .etchfacets-template HTML
    ├── Updates facet choice counts
    └── Manages URL state (pushState)

Etch Theme (built by developer in Etch editor)
├── Loop container with .etchfacets-template class
├── Facet UI components (checkboxes, selects, etc.)
│   └── Use data-etchfacet="facet_name" + data-etchfacet-source="taxonomy:category"
└── Styled however you want — plugin doesn't care about markup
```

---

## Phase 1: Plugin Scaffold + Query Engine (CORE — Build First)

This is the minimum needed for a working prototype.

### 1a. Plugin Scaffold

```
EtchFacets/
├── etchfacets.php                  # Main plugin file, bootstrap
├── includes/
│   ├── class-query-builder.php     # Translates facet selections → WP_Query args
│   ├── class-ajax-handler.php      # AJAX endpoint
│   ├── class-facet-renderer.php    # Shortcode registration + PHP render helpers
│   └── class-count-calculator.php  # Calculates per-choice counts
├── assets/
│   └── js/etchfacets.js            # Frontend AJAX controller
└── readme.txt
```

### 1b. Query Builder (`class-query-builder.php`)

The heart of v1. Translates facet selections into native `WP_Query` arguments — **no custom table needed**.

**Supported source types:**

| Source Type | WP_Query Arg | Example |
|---|---|---|
| `taxonomy:{name}` | `tax_query` | `taxonomy:category`, `taxonomy:product_cat` |
| `meta:{key}` | `meta_query` | `meta:price`, `meta:_color` |
| `meta_range:{key}` | `meta_query` with `BETWEEN` | `meta_range:price` |
| `post_type` | `post_type` | Built-in |
| `search` | `s` | Full-text search |
| `author` | `author__in` | Author filter |
| `date` | `date_query` | Date range filter |

**Logic:**
- Between facets: **AND** (intersect — selecting a category AND a tag narrows results)
- Within a facet: **OR** by default (selecting "Red" OR "Blue" expands within that facet), configurable to AND

**Example of what the query builder produces:**

```php
// User selects: category=design,photography & meta:price=100,500
$args = [
    'post_type'      => 'post',
    'posts_per_page' => 12,
    'tax_query'      => [
        [
            'taxonomy' => 'category',
            'field'    => 'slug',
            'terms'    => ['design', 'photography'],
            'operator' => 'IN',  // OR within facet
        ],
    ],
    'meta_query'     => [
        [
            'key'     => 'price',
            'value'   => [100, 500],
            'type'    => 'NUMERIC',
            'compare' => 'BETWEEN',
        ],
    ],
];
```

### 1c. AJAX Handler (`class-ajax-handler.php`)

Endpoints: `wp_ajax_etchfacets_filter` / `wp_ajax_nopriv_etchfacets_filter`

**Request payload:**
```json
{
    "facets": {
        "category": ["design", "photography"],
        "price": ["100", "500"]
    },
    "page": 1,
    "query_context": {
        "post_type": "post",
        "posts_per_page": 12,
        "template_id": "main"
    }
}
```

**Response payload:**
```json
{
    "html": "<article>...</article><article>...</article>",
    "counts": {
        "category": [
            {"value": "design", "label": "Design", "count": 8},
            {"value": "photography", "label": "Photography", "count": 5},
            {"value": "code", "label": "Code", "count": 0}
        ],
        "price": [
            {"min": 0, "max": 1000}
        ]
    },
    "total": 13,
    "max_pages": 2,
    "pager": {"current": 1, "total": 2}
}
```

**HTML generation:**
- The AJAX handler re-runs the loop using the filtered `WP_Query`
- It needs to know which Etch template/loop to render — passed via `query_context`
- Uses `ob_start()` + the Etch loop rendering to capture the HTML output

### 1d. Count Calculator (`class-count-calculator.php`)

For each facet, calculates how many results each choice would return. This is what makes facets "aware" of each other.

**Algorithm (no index table — queries directly):**

For each facet `F`:
1. Build a `WP_Query` using all *other* active facets' selections (exclude `F`)
2. Get the matching post IDs
3. For taxonomy sources: use `wp_get_object_terms()` or a direct `$wpdb` query on `term_relationships` to count terms among those posts
4. For meta sources: query `postmeta` table grouped by `meta_value` among those posts

This is the expensive part. For v1 prototype it's acceptable. The index table (Phase 4) will replace this.

### 1e. Frontend JS Controller (`etchfacets.js`)

Vanilla JS (no jQuery dependency). Handles:

1. **Element discovery** — Finds all `[data-etchfacet]` elements on the page
2. **Event listening** — `change` events on checkboxes/selects/radios, `input` with debounce on search fields
3. **Selection collection** — Reads values from all facet elements
4. **AJAX request** — POST to `admin-ajax.php` with `action=etchfacets_filter`
5. **DOM update** — Replaces `.etchfacets-template` innerHTML, updates facet counts
6. **URL state** — `history.pushState` with `_facet=value` params, `popstate` listener for back/forward
7. **Loading state** — Adds/removes `.etchfacets-loading` class during requests
8. **Events** — Dispatches `etchfacets:loaded` CustomEvent for external hooks

**Data attribute convention for Etch-built facets:**

```html
<!-- Taxonomy facet (checkboxes built in Etch) -->
<div data-etchfacet="category" data-etchfacet-source="taxonomy:category" data-etchfacet-logic="or">
    <label>
        <input type="checkbox" value="design"> Design
        <span class="etchfacet-count">8</span>
    </label>
    <label>
        <input type="checkbox" value="photography"> Photography
        <span class="etchfacet-count">5</span>
    </label>
</div>

<!-- Meta range facet (e.g., price slider) -->
<div data-etchfacet="price" data-etchfacet-source="meta_range:price">
    <input type="range" class="etchfacet-min" min="0" max="1000" value="0">
    <input type="range" class="etchfacet-max" min="0" max="1000" value="1000">
</div>

<!-- Search facet -->
<div data-etchfacet="search" data-etchfacet-source="search">
    <input type="search" placeholder="Search...">
</div>

<!-- Dropdown facet -->
<div data-etchfacet="color" data-etchfacet-source="taxonomy:color">
    <select>
        <option value="">All Colors</option>
        <option value="red">Red (3)</option>
        <option value="blue">Blue (7)</option>
    </select>
</div>

<!-- The listing container -->
<div class="etchfacets-template" data-etchfacets-query='{"post_type":"post","posts_per_page":12}'>
    <!-- Etch loop output goes here -->
</div>
```

---

## Phase 2: Etch Integration Hooks

Wire the plugin into Etch's existing extension points.

### 2a. Canvas asset loading

```php
add_action('etch/canvas/enqueue_assets', function () {
    wp_enqueue_script('etchfacets', plugin_dir_url(__FILE__) . 'assets/js/etchfacets.js', [], '1.0', true);
    wp_localize_script('etchfacets', 'etchfacetsConfig', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('etchfacets_nonce'),
    ]);
});
```

### 2b. CSS class autocompletion

```php
add_filter('etch_autocompletion_classes', function (array $classes) {
    return array_merge($classes, [
        'etchfacets-template',
        'etchfacets-loading',
        'etchfacet-count',
        'etchfacet-ghost',
        'etchfacet-hidden',
    ]);
});
```

### 2c. Shortcode support (optional — data attributes are primary)

```php
// [etchfacets_listing post_type="post" posts_per_page="12"]
// Wraps content in .etchfacets-template with the query config
add_shortcode('etchfacets_listing', function ($atts, $content) { ... });
```

---

## Phase 3: Developer Workflow (How to Use v1)

Since facet UI is built manually in Etch, here's the developer workflow:

### Step 1: Build your loop in Etch
- Create your post loop (cards, grid, etc.) as normal in Etch
- Add the class `etchfacets-template` to the loop's wrapper element
- Add a `data-etchfacets-query` attribute with the base query JSON

### Step 2: Build your facet UI in Etch
- Build checkboxes, dropdowns, search inputs, etc. as normal HTML elements in Etch
- Add `data-etchfacet="facet_name"` to each facet's container
- Add `data-etchfacet-source="taxonomy:category"` (or `meta:price`, etc.)
- Add `value` attributes to each checkbox/option matching the term slug or meta value
- Optionally add `<span class="etchfacet-count"></span>` next to labels for live counts

### Step 3: Activate the plugin
- The JS auto-discovers all `[data-etchfacet]` elements and `.etchfacets-template`
- Filtering just works

### Step 4: Style everything in Etch
- The plugin adds `.etchfacets-loading` during AJAX — style it however you want
- Ghost choices get `.etchfacet-ghost` — dim them with CSS
- Hidden choices get `.etchfacet-hidden`

---

## Phase 4: Indexer Engine (Performance Optimization — Build Later)

Only needed when direct `WP_Query` becomes too slow (hundreds of posts + many facets).

### Custom index table (`{prefix}_etchfacets_index`)

| Column | Type | Purpose |
|---|---|---|
| `id` | BIGINT AUTO_INCREMENT | PK |
| `facet_name` | VARCHAR(50) | Which facet this row belongs to |
| `facet_source` | VARCHAR(255) | Data source key |
| `post_id` | BIGINT | The post this value belongs to |
| `facet_value` | VARCHAR(255) | Raw/slug value |
| `facet_display_value` | VARCHAR(255) | Human-readable label |
| `depth` | INT | Hierarchy depth (0 for flat) |
| `parent_id` | BIGINT | Parent term ID for hierarchical |

### Indexer behavior
- **Full re-index** via admin button (atomic temp table swap)
- **Auto-index** on `save_post` / `edited_term`
- **Hooks**: `etchfacets_index_row`, `etchfacets_indexer_query_args`

### Query builder swap
- When the index table exists, `class-query-builder.php` switches from `tax_query`/`meta_query` to querying the index table for post IDs, then uses `post__in`
- The AJAX response format stays identical — frontend doesn't change at all

---

## Phase 5: Admin UI (Quality of Life — Build Later)

A settings page for managing facet configurations without code:

- Facet CRUD (name, type, data source, logic)
- Re-index button + progress
- Index stats
- Facet config stored in `wp_options` (`etchfacets_settings`)

For v1, facet configuration lives entirely in the HTML data attributes — no admin UI needed.

---

## Phase 6: Native Etch Integration (When Components API Ships)

When Etch ships their Components API:

- Register facet types as native Etch elements with props
- Facets become drag-and-drop elements in the Etch editor
- Props: facet name, data source, logic, show counts, show ghosts, etc.
- Replaces the manual data-attribute approach
- Existing data-attribute sites continue to work (backward compatible)

---

## Build Order (Prototype-First)

| Step | What | Est. Effort | Milestone |
|---|---|---|---|
| **1** | Plugin scaffold + query builder | 1 day | — |
| **2** | AJAX handler + count calculator | 1-2 days | — |
| **3** | `etchfacets.js` (AJAX controller + URL state) | 1-2 days | — |
| **4** | Etch hooks (canvas assets, class autocompletion) | 0.5 day | **🎉 Working prototype** |
| **5** | Build test facets in Etch + test/debug | 1 day | **🎉 Demo-ready** |
| — | *— Everything below is post-prototype —* | | |
| 6 | Ghost choices + loading states | 1 day | |
| 7 | Pagination facet (load more / pager) | 1 day | |
| 8 | Sort facet | 0.5 day | |
| 9 | Index table + indexer engine | 2-3 days | |
| 10 | Admin UI | 2-3 days | |
| 11 | Native Etch components (when API available) | 2-3 days | |

**Time to working prototype: ~4-5 days**

---

## Key Design Decisions

### Why query-first (no index table for v1)?
Direct `WP_Query` with `tax_query`/`meta_query` works out of the box, requires no database migrations, and is fast enough for sites with <1000 posts and a few facets. The index table is a performance optimization, not a prerequisite.

### Why build facet UI in Etch manually?
- Etch's Components API doesn't exist yet
- Developers get full control over markup and styling (Etch's philosophy)
- Data attributes are the thinnest possible integration layer
- The plugin stays focused: it's a filtering engine, not a UI framework
- When the Components API ships, we add native elements without breaking existing sites

### Why data attributes instead of shortcodes?
- Shortcodes are opaque in Etch's visual editor
- Data attributes are visible, inspectable, and editable in Etch's HTML panel
- They align with Etch's "Total Transparency" principle
- Shortcodes are supported as a fallback but aren't the primary API

### Why vanilla JS (no jQuery)?
- Etch doesn't depend on jQuery
- Smaller footprint
- Modern browser APIs (fetch, CustomEvent, pushState) are sufficient

---

## Etch Extension Points Used

From the codebase analysis (`EXTENSION-POINTS.md`):

| Hook/API | How We Use It |
|---|---|
| `etch/canvas/enqueue_assets` | Load `etchfacets.js` + config in the Etch canvas |
| `etch_autocompletion_classes` | Register `.etchfacets-*` classes for editor autocompletion |
| `etch_process_shortcodes` | Process `[etchfacets_listing]` shortcodes in Etch output |
| `etch/dynamic_data/post` | (Future) Inject facet-related dynamic data |
| REST API `/queries/wp-query` | (Future) Integrate with Etch's query system |

---

## References

- **FacetWP**: https://facetwp.com/help-center/
- **WP Grid Builder**: https://docs.wpgridbuilder.com/
- **EtchWP Docs**: https://docs.etchwp.com/
- **Etch Extension Points**: `../CCTest/EXTENSION-POINTS.md`
- **Etch Current Priorities**: https://docs.etchwp.com/top-priorities
