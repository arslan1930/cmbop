import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { createContext, runInContext } from 'node:vm';

function loadCatalogUrl(locationSearch = '') {
    const catalogJs = resolve(dirname(fileURLToPath(import.meta.url)), '../../public/assets/js/catalog.js');
    const src = readFileSync(catalogJs, 'utf8');
    const start = src.indexOf('const CatalogUrl = (function ()');
    const end = src.indexOf('window.CatalogUrl = CatalogUrl;');
    if (start < 0 || end < 0) {
        throw new Error('CatalogUrl IIFE not found in catalog.js');
    }

    const sandbox = {
        window: {
            CatalogConfig: {
                catalogPath: '/advertiser/catalog',
                defaultSort: 'dr_desc',
            },
            location: { search: locationSearch },
            history: { replaceState() {}, pushState() {} },
        },
        document: {
            getElementById: () => null,
            querySelector: () => null,
            querySelectorAll: () => [],
        },
        URLSearchParams,
        FormData,
        syncCatalogFilterFields() {},
    };

    createContext(sandbox);
    runInContext(src.slice(start, end) + 'this.CatalogUrl = CatalogUrl;', sandbox);

    return sandbox.CatalogUrl;
}

function shouldSkipLiveApply(queryKey, lastAppliedQuery, locationKey, force) {
    return !force
        && lastAppliedQuery !== null
        && queryKey === lastAppliedQuery
        && queryKey === locationKey;
}

test('canonicalize drops page=1 and keeps page=2', () => {
    const CatalogUrl = loadCatalogUrl();
    assert.equal(
        CatalogUrl.canonicalize(new URLSearchParams({ page: '1', search: 'alpha' })).toString(),
        'search=alpha'
    );
    assert.equal(
        CatalogUrl.canonicalize(new URLSearchParams({ page: '2' })).toString(),
        'page=2'
    );
});

test('fromForm keepPage restores page from the URL; filter apply drops it', () => {
    const CatalogUrl = loadCatalogUrl('?page=2&per_page=50');
    const kept = CatalogUrl.fromForm({ keepPage: true });
    assert.equal(kept.get('page'), '2');
    assert.equal(kept.get('per_page'), '50');

    const reset = CatalogUrl.fromForm({ keepPage: false });
    assert.equal(reset.get('page'), null);
    assert.equal(reset.get('per_page'), '50');
});

test('page-1 click is not a no-op while the listing URL is page=2', () => {
    const CatalogUrl = loadCatalogUrl('?page=2');
    const lastAppliedQuery = CatalogUrl.fromForm({ keepPage: true }).toString();
    const locationKey = CatalogUrl.fromLocation().toString();
    const pageOne = CatalogUrl.canonicalize(new URLSearchParams({ page: '1' })).toString();

    assert.equal(lastAppliedQuery, 'page=2');
    assert.equal(locationKey, 'page=2');
    assert.equal(pageOne, '');
    assert.equal(shouldSkipLiveApply(pageOne, lastAppliedQuery, locationKey, false), false);
    assert.equal(shouldSkipLiveApply(pageOne, '', locationKey, false), false);
    assert.equal(shouldSkipLiveApply(pageOne, pageOne, pageOne, false), true);
    assert.equal(shouldSkipLiveApply(pageOne, pageOne, pageOne, true), false);
});
