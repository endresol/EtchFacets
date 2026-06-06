=== EtchFacets ===
Contributors: endresol
Tags: facets, faceted-search, etch, etchwp, search
Requires at least: 5.9
Tested up to: 6.9.4
Requires PHP: 8.1
Stable tag: 0.1.5
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
