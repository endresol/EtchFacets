=== EtchFacets ===
Contributors: endresol
Tags: facets, faceted-search, etch, etchwp, search
Requires at least: 5.9
Tested up to: 6.9.4
Requires PHP: 8.1
Stable tag: 0.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Faceted search engine for EtchWP.

== Description ==

EtchFacets adds a faceted search engine to EtchWP, allowing visitors to filter
content by taxonomies, custom fields, authors and more — with live counts and
URL-driven state.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin, or extract into
   `wp-content/plugins/etchfacets/`.
2. Activate it from the Plugins screen.

== Changelog ==

= 0.1.0 =
* Initial release.

= 0.1.1 =
* Testing updates.

= 0.1.2 =
* Test build via local symlink.

= 0.1.3 =
* Fix: meta facets no longer trigger a 404 on singular pages — the main query
  for a page/post is left untouched so only the listing loop is filtered.

= 0.1.4 =
* Fix: facet filters are now scoped to the listing's post type, so meta filters
  no longer leak into the secondary queries Etch runs to render each card —
  cards keep their meta data (country, region, etc.) when meta-filtering.

= 0.1.5 =
* Fix: the release zip no longer strips the bundled Parsedown library, which
  caused a fatal "Class Parsedown not found" during update checks. The release
  workflow no longer excludes the plugin-update-checker vendor directory.

= 0.3.4 =
* Fix: a taxonomy or meta key with any uppercase letter in its registered
  name could show an empty choice list (shortcode, PHP helper, and the
  `/wp-json/etchfacets/v1/choices` REST endpoint), even though live
  filtering and counts worked fine for the same facet. `get_choices()` was
  force-lowercasing the source name via `sanitize_key()` before checking
  `taxonomy_exists()` — a case-sensitive lookup — while the query-builder
  path used for filtering/counts preserved case. Both now preserve case via
  `sanitize_text_field()`, matching each other.

= 0.3.3 =
* Fix: checkbox/radio facet counts on a shared taxonomy (a taxonomy used by
  more than one post type) could show a `(N)` count on choices used by the
  current post type but a bare number on choices only used by another post
  type. The live count refresh's `updateCounts()` was hardcoding a "(N)"
  format onto any `.etchfacet-count` element it touched, clashing with hand-
  authored Etch templates that render counts without parentheses. It now
  only swaps the digits, leaving whatever formatting the template already
  has around them.
* Fix: `EtchFacets_Count_Calculator::calculate_taxonomy_counts()` used an
  INNER JOIN, so a term with zero matching posts under the current post type
  (e.g. one only used by another post type sharing the taxonomy) was omitted
  from the count response entirely rather than reported as 0 — meaning its
  displayed count could go stale indefinitely, and the `etchfacet-ghost` /
  `etchfacet-hidden` zero-count styling could never engage for it. Switched
  to a LEFT JOIN so every term in the taxonomy is represented, with 0 where
  there's no match.

= 0.3.2 =
* Fix: dropdown facet choices could show an inconsistent "(N)" count suffix —
  present on some options but not others — after the live count refresh ran,
  regardless of the facet's `show_counts` setting. The `<select>` now carries
  a `data-etchfacets-show-counts` flag so the frontend JS only appends counts
  to dropdown options when the facet was actually rendered with counts on,
  matching the behavior checkbox/radio facets already had.

= 0.3.1 =
* Fix: taxonomy/meta facet choice lists (shortcode, PHP helper, and the
  `/wp-json/etchfacets/v1/choices` REST endpoint used by native Etch Loop
  facet components) can now be scoped to a specific post type via a new
  `post_type` attribute/param, so term/value lists and counts no longer leak
  across post types that share a taxonomy or meta key.
* Add: `hide_empty` attribute/param controls whether zero-count taxonomy
  terms are included in a facet's choice list (default `true`, unchanged).
* Fix: the `pre_get_posts` facet filter no longer applies filters to a query
  when the originating listing's post type can't be determined from the URL
  — previously it fell back to a loose "does this taxonomy apply to this
  post type" guess for tax_query, and applied meta_query/search/author/sort
  with no guard at all, which could leak one listing's facet filters into an
  unrelated query for a different post type on the same page. A new
  `etchfacets/filter_query/apply_untargeted` filter restores the old
  best-effort behavior for sites that were relying on it.
