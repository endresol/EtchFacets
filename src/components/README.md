# EtchFacets components

One JSON file per Etch component, exported from the test site
(https://etchfacetstestsite.local) by `npm run export-components`. **Don't edit these by hand:**
change the component in Etch on the test site, then re-export.

## Format

```json
{
	"name": "Facet - Checkboxes",
	"key": "FacetCheckboxes",
	"description": "…",
	"properties": [ { "name": "Label", "key": "label", "type": { "primitive": "string" }, "default": "" } ],
	"blocks": [ { "type": "etch/element", "version": 1, "tag": "div", "attributes": { "class": "etchfacets-facet" }, "children": [] } ]
}
```

- `properties` and `blocks` use the shapes from Etch's `etch.components.getJson()`. They can go
  straight back into `etch.components.updateAsync(id, { properties, blocks })`.
- **Unstyled:**
  - Block `id`, `parentId` and `styles` (site-specific Etch style record ids) are removed.
  - Class names stay in `attributes.class`.
  - On Etch builds that require `styles` on every element block (seen on the test site), the
    importer has to add it, with the matching style ids from `etch.styles.list()` or `[]`.
- **Site-neutral:** absolute URLs are replaced with placeholders, which an importer must fill in:
  - `{{etchfacets_url}}`: the plugin's own URL, e.g. `https://example.com/wp-content/plugins/etchfacets/`
    (in PHP, the `ETCHFACETS_PLUGIN_URL` constant). Used for the toggle chevron
    `assets/icons/circle-chevron-down-stroke-rounded.svg`.
  - `{{site_url}}`: the site's origin, for any other absolute URL.
- **Stable key order:** a re-export with no real change gives no diff.

## Which components

Every component on the test site named `Facet - …` or `Facets …`, plus `Listing`,
`Active Filters` and `Clear Filters`. The filter is `INCLUDE` in `scripts/export-components.mjs`.
