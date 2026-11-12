#!/usr/bin/env node
/**
 * Export the EtchFacets Etch components from a live builder tab into
 * src/components/<slug>.json, unstyled and site-neutral, so they can be
 * versioned in git (and later installed by the plugin).
 *
 * Usage:
 *   npm run export-components                # tab "EtchFacetsTest"
 *   npm run export-components -- --tab NAME  # another connected tab
 *   npm run export-components -- --check     # exit 1 if the files would change
 *
 * Needs the Etch Connector running (`npx @digital-gravy/etch-connector serve`)
 * and the builder tab connected (AI button in Etch's left sidebar).
 *
 * What "unstyled and site-neutral" means:
 *   - block ids, parentIds and `styles` (site-specific style record ids) are
 *     dropped; class names in `attributes.class` stay;
 *   - the plugin's URL on the source site becomes {{etchfacets_url}} and the
 *     site's own origin becomes {{site_url}};
 *   - keys are sorted, so a diff only shows real changes.
 * See src/components/README.md for the file format.
 */

import { spawnSync } from 'node:child_process';
import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'src', 'components');

const args = process.argv.slice(2);
const tab = args.includes('--tab') ? args[args.indexOf('--tab') + 1] : 'EtchFacetsTest';
const check = args.includes('--check');

// Which components belong to the plugin. Anything else on the test site
// (map experiments, hero demos) stays out of the repo.
const INCLUDE = /^(Facet - |Facets )|^(Listing|Active Filters|Clear Filters)$/;

const inTab = `
const include = ${INCLUDE};
return {
  components: etch.components.list()
    .filter(c => include.test(c.name))
    .map(c => etch.components.getJson(c.id)),
};
`;

const res = spawnSync('npx', ['--yes', '@digital-gravy/etch-connector', 'eval', '-t', tab, inTab], {
	encoding: 'utf8',
	maxBuffer: 64 * 1024 * 1024,
});
if (res.status !== 0) {
	process.stderr.write(res.stderr || res.stdout || 'etch-connector failed\n');
	process.stderr.write(`\nIs the connector running and the "${tab}" tab connected?\n`);
	process.exit(res.status || 1);
}
const data = JSON.parse(res.stdout);
if (!data.components.length) {
	console.error('No matching components found in the tab.');
	process.exit(1);
}

// Learn the source site's origin from absolute URLs in the blocks.
const raw = JSON.stringify(data.components);
const pluginUrlMatch = raw.match(/https?:\/\/[^"\\/]+\/wp-content\/plugins\/etchfacets\//);
const origin = pluginUrlMatch ? new URL(pluginUrlMatch[0]).origin : null;

const neutral = (value) => {
	if (typeof value !== 'string') return value;
	let v = value;
	if (pluginUrlMatch) v = v.split(pluginUrlMatch[0]).join('{{etchfacets_url}}');
	if (origin) v = v.split(origin).join('{{site_url}}');
	return v;
};

const cleanBlock = (block) => {
	const out = {};
	for (const [k, v] of Object.entries(block)) {
		if (k === 'id' || k === 'parentId' || k === 'styles') continue;
		if (k === 'children') out.children = v.map(cleanBlock);
		else if (k === 'attributes') out.attributes = Object.fromEntries(Object.entries(v).map(([ak, av]) => [ak, neutral(av)]));
		else out[k] = typeof v === 'string' ? neutral(v) : v;
	}
	return out;
};

// Stable, readable output: known keys in a fixed order (component fields
// first; for blocks: what it is, then its settings, then its children),
// anything else alphabetically after them. Arrays keep their order.
const ORDER = [
	'name', 'key', 'description', 'properties', 'blocks',
	'type', 'version', 'tag', 'context', 'attributes', 'conditionString',
	'loopId', 'itemId', 'loopParams', 'target', 'componentId', 'text', 'options',
	'primitive', 'specialized', 'default', 'selectOptionsString',
	'children',
];
const rank = (k) => (ORDER.includes(k) ? ORDER.indexOf(k) : ORDER.length);
const sortKeys = (v, inAttributes = false) => {
	if (Array.isArray(v)) return v.map((x) => sortKeys(x));
	if (v && typeof v === 'object') {
		const keys = Object.keys(v).sort((a, b) => (inAttributes ? 0 : rank(a) - rank(b)) || a.localeCompare(b));
		return Object.fromEntries(keys.map((k) => [k, sortKeys(v[k], k === 'attributes')]));
	}
	return v;
};

const slug = (name) => name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

const files = new Map();
for (const c of data.components) {
	const doc = sortKeys({
		name: c.name,
		key: c.key,
		description: c.description ?? '',
		properties: (c.properties || []).map((p) => {
			const { keyTouched, ...rest } = p; // builder UI state, not part of the definition
			return rest;
		}),
		blocks: c.blocks.map(cleanBlock),
	});
	files.set(`${slug(c.name)}.json`, JSON.stringify(doc, null, '\t') + '\n');
}

const existing = (() => {
	try {
		return readdirSync(OUT).filter((f) => f.endsWith('.json'));
	} catch {
		return [];
	}
})();

const changed = [];
for (const [file, text] of files) {
	let before = null;
	try {
		before = readFileSync(join(OUT, file), 'utf8');
	} catch {}
	if (before !== text) changed.push((before === null ? 'added   ' : 'changed ') + file);
}
const removed = existing.filter((f) => !files.has(f));
removed.forEach((f) => changed.push('removed ' + f));

if (check) {
	if (changed.length) {
		console.log(changed.join('\n'));
		process.exit(1);
	}
	console.log(`Up to date (${files.size} components).`);
	process.exit(0);
}

mkdirSync(OUT, { recursive: true });
for (const [file, text] of files) writeFileSync(join(OUT, file), text);
for (const f of removed) rmSync(join(OUT, f));

console.log(`Exported ${files.size} components from tab "${tab}"${origin ? ` (${origin})` : ''} to src/components/`);
console.log(changed.length ? changed.join('\n') : 'No changes.');
