/* Real resolution UI and API transport, entirely synthetic and offline. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const panel = path.join(__dirname, '../src/public/panel_v2/js');
const attack = '\"><img src=x onerror=alert(1)><script>alert(2)</script>&\'';
const settle = async () => { for (let i = 0; i < 30; i++) await Promise.resolve(); };
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };
const all = node => [node, ...node.children.flatMap(all)];
class Element {
    constructor(tag = 'div') { this.tagName = tag.toUpperCase(); this.children = []; this.events = new Map(); this.style = {}; this.dataset = {}; this.disabled = false; this.checked = false; this._text = ''; }
    set textContent(value) { this._text = String(value); this.children = []; }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    appendChild(child) { this.children.push(child); return child; }
    replaceChildren(...children) { this._text = ''; this.children = children; }
    addEventListener(name, work) { if (!this.events.has(name)) this.events.set(name, []); this.events.get(name).push(work); }
    querySelectorAll(selector) { return selector === 'input' ? all(this).filter(item => item.tagName === 'INPUT') : []; }
    async emit(name, event = {}) { for (const work of this.events.get(name) || []) await work(event); }
}
const envelope = data => ({ status: 'ok', error: false, data });
const syncToken = 'a'.repeat(64), candidateId = 'b'.repeat(32), otherId = 'c'.repeat(32);
function candidate(overrides = {}) { return { candidateId, rowId: '*1', path: 'ppp/secret', username: attack, comment: attack,
    ownerServiceId: 42, ownerClientId: 17, address: '192.0.2.42', profile: 'Fixture', disabled: false, dynamic: false, selectable: true, reason: null, ...overrides }; }
function preview(overrides = {}) { return { serviceId: 42, clientId: 17, deviceId: 1, clientName: attack, site: 'Fixture',
    mode: 'reconcile', eligible: true, autoApply: true, previewToken: syncToken, expiresAt: new Date(Date.now() + 600000).toISOString(),
    reason: null, candidates: [candidate()], changes: [{ field: 'ownership', before: '17:99', after: '17:42' }], willReconnect: true, ...overrides }; }
function outcome(overrides = {}) { return { serviceId: 42, state: 'resolved', reason: null, jobKeys: ['Q7'], verificationState: 'verified', ...overrides }; }
function fixture() {
    const nodes = new Map(), events = new Map(), requests = [], routes = new Map(), notifications = [], applied = [], navigated = [], reviewed = [];
    const document = { documentElement: {}, getElementById(id) { if (!nodes.has(id)) nodes.set(id, new Element()); return nodes.get(id); },
        querySelectorAll() { return []; }, createElement(tag) { return new Element(tag); },
        addEventListener(name, work) { if (!events.has(name)) events.set(name, []); events.get(name).push(work); } };
    const context = vm.createContext({ document, URL, Date, window: { location: { href: 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?page=panel' } },
        localStorage: { getItem() { return null; } },
        async fetch(endpoint, options) {
            const payload = JSON.parse(options.body); requests.push(payload);
            const work = routes.get(payload.target + '/' + payload.action); assert(work, 'Unexpected route: ' + payload.target + '/' + payload.action);
            const response = await work(payload.data, payload);
            return { ok: true, status: 200, json: async () => response };
        } });
    for (const file of ['i18n.js', 'api.js', 'service_resolution.js']) vm.runInContext(fs.readFileSync(path.join(panel, file), 'utf8'), context);
    const api = vm.runInContext('api', context), ui = context.PanelServiceResolution;
    ui.mount({ api, notify(...args) { notifications.push(args); }, navigate(...args) { navigated.push(args); }, reviewJob(...args) { reviewed.push(args); }, onApplied(value) { applied.push(value); } });
    routes.set('service_resolution/preview', () => envelope(preview())); routes.set('service_resolution/apply', () => envelope(outcome()));
    return { api, ui, nodes, requests, routes, notifications, applied, navigated, reviewed,
        node: id => document.getElementById(id),
        async mutation(active) { for (const callback of api.mutationListeners) callback(active); await settle(); },
        async escape() { for (const callback of events.get('keydown') || []) callback({ key: 'Escape' }); await settle(); } };
}
async function automaticAndManualResolution() {
    const direct = fixture(), backupName = 'router-resolution-20261016T010000Z-' + 'd'.repeat(16) + '.json';
    direct.routes.set('service_resolution/apply', () => envelope(outcome({ backupName })));
    await direct.ui.resolveService(42);
    assert.deepEqual(direct.requests.map(item => [item.changeType, item.target, item.action]), [
        ['list', 'service_resolution', 'preview'], ['update', 'service_resolution', 'apply']]);
    assert.deepEqual(JSON.parse(JSON.stringify(direct.requests[1].data)), { previewToken: syncToken, selection: [] });
    assert.equal(direct.applied.length, 1); assert.equal(direct.notifications[0][0], 'Resuelto y verificado');
    assert(direct.node('service-resolution-result').textContent.includes('Resuelto y verificado'));
    const backup = all(direct.node('service-resolution-result')).find(item => item.tagName === 'A' && item.textContent === 'Descargar respaldo');
    assert(backup, 'A successful resolution did not offer its saved backup');
    assert.equal(new URL(backup.href).origin, 'https://fixture.invalid');
    assert.equal(new URL(backup.href).searchParams.get('download'), backupName);
    assert.throws(() => direct.api.downloadUrl('../' + backupName));
    assert.throws(() => direct.api.downloadUrl('javascript:alert(1)'));
    await all(direct.node('service-resolution-result')).find(item => item.textContent === 'Revisar trabajo').emit('click');
    assert.deepEqual(direct.reviewed, [['Q7', 42]]);

    const manual = fixture(), pending = deferred();
    manual.routes.set('service_resolution/preview', () => envelope(preview({ mode: 'select_account', autoApply: false,
        candidates: [candidate(), candidate({ candidateId: otherId, rowId: '*2', ownerServiceId: 99, selectable: false, reason: 'resolution_foreign_owner' })],
        changes: [{ field: 'password', before: 'PRIVATE-PASSWORD', after: 'PRIVATE-PASSWORD' }, { field: 'comment', before: attack, after: attack }] })));
    await manual.ui.resolveService(42);
    assert.equal(manual.requests.length, 1, 'An ambiguous account was adopted without explicit selection');
    assert.equal(manual.node('service-resolution-apply').disabled, true);
    const radios = all(manual.node('service-resolution-candidates')).filter(item => item.tagName === 'INPUT');
    assert(radios.every(item => !item.checked), 'An ambiguous account was preselected'); assert.equal(radios[1].disabled, true);
    await radios[1].emit('change'); assert.equal(manual.node('service-resolution-apply').disabled, true);
    await radios[0].emit('change'); assert.equal(manual.node('service-resolution-apply').disabled, false);
    const content = manual.node('service-resolution-summary').textContent + manual.node('service-resolution-candidates').textContent + manual.node('service-resolution-changes').textContent;
    assert(content.includes(attack), 'Remote fields were dropped instead of safely rendered as text'); assert(!content.includes('PRIVATE-PASSWORD'));
    assert(![...manual.nodes.values()].flatMap(all).some(item => item.tagName === 'IMG' || item.tagName === 'SCRIPT'), 'Remote text created executable elements');
    manual.routes.set('service_resolution/apply', data => { assert.deepEqual(JSON.parse(JSON.stringify(data.selection)), [candidateId]); return pending.promise; });
    const applying = manual.node('service-resolution-apply').emit('click'); await settle();
    await manual.node('service-resolution-apply').emit('click');
    assert.equal(manual.requests.filter(item => item.action === 'apply').length, 1, 'Double click replayed a consumed resolution token');
    pending.resolve(envelope(outcome({ state: 'pending', verificationState: null }))); await applying;
    assert(manual.node('service-resolution-result').textContent.includes('Pendiente'));
    assert.equal(manual.notifications.length, 0, 'Pending work was reported as resolved');
}
async function staleReadsAndUncertainWrites() {
    const canceled = fixture(), waiting = deferred(); canceled.routes.set('service_resolution/preview', () => waiting.promise);
    const reading = canceled.ui.resolveService(42); await settle(); canceled.ui.close(); waiting.resolve(envelope(preview())); await reading;
    assert.equal(canceled.requests.length, 1, 'Closing a pending preview still applied its result');
    const switched = fixture(), first = deferred(), second = deferred();
    switched.routes.set('service_resolution/preview', data => data.serviceId === 42 ? first.promise : second.promise);
    const a = switched.ui.resolveService(42), b = switched.ui.resolveService(43);
    second.resolve(envelope(preview({ serviceId: 43, autoApply: false, candidates: [] }))); await b;
    first.resolve(envelope(preview())); await a;
    assert.equal(switched.requests.filter(item => item.action === 'apply').length, 0);
    assert.equal(switched.node('service-resolution-heading').textContent, 'Resolver servicio #43');

    const uncertain = fixture(); let writes = 0;
    uncertain.routes.set('service_resolution/apply', () => { writes++; throw new Error('PRIVATE-TRANSPORT-ERROR'); });
    await uncertain.ui.resolveService(42);
    assert.equal(writes, 1); assert.equal(uncertain.applied.length, 0);
    assert(uncertain.node('service-resolution-result').textContent.includes('No se pudo confirmar'));
    assert(!uncertain.node('service-resolution-result').textContent.includes('PRIVATE-TRANSPORT-ERROR'));
    await uncertain.node('service-resolution-apply').emit('click'); assert.equal(writes, 1, 'Uncertain write was replayed from a stale button');
    await uncertain.node('service-resolution-refresh').emit('click'); assert.equal(writes, 1, 'Refreshing an uncertain resolution automatically applied again');
    uncertain.routes.set('service_resolution/apply', () => { writes++; return envelope(outcome()); });
    await uncertain.node('service-resolution-apply').emit('click'); assert.equal(writes, 2, 'A freshly reviewed resolution could not be explicitly retried');

    const changed = fixture(), fresh = deferred(); changed.routes.set('service_resolution/preview', () => fresh.promise);
    const started = changed.ui.resolveService(42); await changed.mutation(true); await changed.mutation(false);
    fresh.resolve(envelope(preview())); await started;
    assert.equal(changed.requests.filter(item => item.action === 'apply').length, 0, 'A preview predating another mutation was applied');
}
async function validationAndGuards() {
    for (const overrides of [{ serviceId: 99 }, { previewToken: attack }, { expiresAt: 'invalid' }, { expiresAt: '2020-01-01T00:00:00Z' },
        { candidates: [candidate({ candidateId: attack })] }, { candidates: [candidate({ rowId: 'all' })] }, { candidates: [candidate({ path: '/system/reset-configuration' })] },
        { candidates: [candidate(), candidate()] }, { mode: 'blocked', eligible: true }]) {
        const ui = fixture(); ui.routes.set('service_resolution/preview', () => envelope(preview(overrides))); await ui.ui.resolveService(42);
        assert.equal(ui.requests.filter(item => item.action === 'apply').length, 0, 'A malformed preview could apply');
    }
    const unverified = fixture(); unverified.routes.set('service_resolution/apply', () => envelope(outcome({ verificationState: 'mismatch' })));
    await unverified.ui.resolveService(42); assert.equal(unverified.notifications.length, 0); assert.equal(unverified.applied.length, 0);
    assert(unverified.node('service-resolution-result').textContent.includes('No se pudo confirmar'));
    const wrong = fixture(); wrong.routes.set('service_resolution/apply', () => envelope(outcome({ serviceId: 99 })));
    await wrong.ui.resolveService(42); assert.equal(wrong.applied.length, 0);
    for (const lock of ['recovery', 'reload']) {
        const ui = fixture(); if (lock === 'recovery') ui.api.setRecovery({ readOnly: true }); else ui.ui.requireReload();
        await ui.ui.resolveService(42); assert.equal(ui.requests.length, 0, 'Resolution bypassed ' + lock + ' protection');
    }
    const stale = fixture(); stale.routes.set('service_resolution/preview', () => envelope(preview({ autoApply: false })));
    await stale.ui.resolveService(42); stale.api.requireReload(); await stale.node('service-resolution-apply').emit('click');
    assert.equal(stale.requests.length, 1, 'A stale restore page applied a review');
    const noChoice = fixture(); noChoice.routes.set('service_resolution/preview', () => envelope(preview({ mode: 'blocked', eligible: false, autoApply: false, previewToken: null, reason: 'resolution_foreign_owner' })));
    await noChoice.ui.resolveService(42); assert.equal(noChoice.node('service-resolution-apply').disabled, true);
    assert(noChoice.node('service-resolution-summary').textContent.includes('otro servicio vigente'));
    const blocked = fixture(); blocked.routes.set('service_resolution/preview', () => envelope(preview({ mode: 'blocked', eligible: false, autoApply: false,
        previewToken: null, reason: 'resolution_derived_unverified', candidates: [candidate({ candidateId: null, selectable: false })] })));
    await blocked.ui.resolveService(42);
    assert(blocked.node('service-resolution-summary').textContent.includes('cola asociada'), 'Blocked observation lost its useful reason because it had no selectable token');
    assert.equal(blocked.node('service-resolution-apply').disabled, true);
}
(async () => {
    await automaticAndManualResolution(); await staleReadsAndUncertainWrites(); await validationAndGuards();
    console.log('Service resolution UI passed: one-click proven repair, explicit ambiguous selection, protected owners, safe text, no credential display, consumed uncertain writes, fresh manual retries, races and recovery/restore guards.');
})().catch(error => { console.error(error); process.exitCode = 1; });
