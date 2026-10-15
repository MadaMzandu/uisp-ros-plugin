/* Real workflow/API modules with an in-memory DOM and transport. No external writes. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const panel = path.join(__dirname, '../src/public/panel_v2/js');
const attack = '\"><img src=x onerror=alert(1)><script>alert(2)</script>&\'';
const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const envelope = data => ({ status: 'ok', error: false, data });
const settle = async () => { for (let index = 0; index < 40; index++) await Promise.resolve(); };
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const previewToken = 'a'.repeat(64), otherToken = 'b'.repeat(64);
const filename = 'full-backup-20261015T190000Z-' + 'c'.repeat(32) + '.zip';
const beforeFile = 'full-backup-20261015T190001Z-' + 'd'.repeat(32) + '.zip';
class Element {
    constructor(name = 'div', id = '') { this.tagName = name.toUpperCase(); this.id = id; this.children = []; this.events = new Map(); this.style = {}; this.value = ''; this.disabled = false; this.checked = false; this.files = []; this._html = ''; this._text = ''; }
    set innerHTML(value) { this._html = String(value); this._text = ''; this.children = []; }
    get innerHTML() { return this._html; }
    set textContent(value) { this._text = String(value); this._html = ''; this.children = []; }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    appendChild(child) { this.children.push(child); return child; }
    replaceChildren(...children) { this._html = ''; this._text = ''; this.children = children; }
    addEventListener(name, callback) { if (!this.events.has(name)) this.events.set(name, []); this.events.get(name).push(callback); }
    async emit(name, event = {}) { for (const callback of this.events.get(name) || []) await callback({ preventDefault() {}, ...event }); }
    querySelectorAll(selector) { return selector === '.close-modal' ? this.children.filter(child => child.className === 'close-modal') : []; }
    click() { this.clicked = true; }
}
const all = node => [node, ...node.children.flatMap(all)];
const html = node => node.innerHTML + escape(node._text) + node.children.map(html).join('');
function button(node, label) { const result = all(node).find(item => item.tagName === 'BUTTON' && item.textContent === label); assert(result, 'Missing button ' + label); return result; }
function fixture() {
    const nodes = new Map(), listeners = new Map(), routes = new Map(), requests = [], notifications = [], applied = [], navigation = [], reviews = [];
    const node = id => { if (!nodes.has(id)) nodes.set(id, new Element('div', id)); return nodes.get(id); };
    for (const id of ['sync-preview-modal', 'full-restore-modal']) { const close = new Element('button'); close.className = 'close-modal'; node(id).appendChild(close); }
    const document = { baseURI: 'https://fixture.invalid/plugin/public.php', visibilityState: 'visible', documentElement: {}, getElementById: node,
        createElement: name => new Element(name), querySelectorAll: () => [], addEventListener(name, callback) { if (!listeners.has(name)) listeners.set(name, []); listeners.get(name).push(callback); } };
    class FormData { constructor() { this.fields = new Map(); } append(key, value) { this.fields.set(key, value); } }
    const context = vm.createContext({ document, URL, console, location: { href: document.baseURI }, FormData,
        async fetch(url, options) {
            const body = options.body instanceof FormData ? { target: 'full_backup', action: 'upload', data: options.body.fields } : JSON.parse(options.body);
            requests.push({ url, options, body }); const route = routes.get(body.target + '/' + body.action);
            assert(route, 'Unexpected route ' + body.target + '/' + body.action);
            const result = await route(body.data); return { ok: true, status: 200, async json() { return result; } };
        }
    });
    context.window = context;
    for (const file of ['i18n.js', 'api.js', 'workflows.js']) vm.runInContext(fs.readFileSync(path.join(panel, file), 'utf8'), context);
    const api = vm.runInContext('api', context), workflows = context.PanelWorkflows;
    workflows.mount({ api, notify(message, kind) { notifications.push({ message, kind }); }, navigate(...args) { navigation.push(args); }, reviewJob(...args) { reviews.push(args); }, onApplied(value) { applied.push(value); } });
    return { context, node, api, workflows, routes, requests, notifications, applied, navigation, reviews,
        async language(lang) { context.PanelI18n.setLanguage(lang); await settle(); },
        async escape() { for (const handler of listeners.get('keydown') || []) handler({ key: 'Escape' }); await settle(); },
        async mutation(active) { for (const listener of api.mutationListeners) listener(active); await settle(); }
    };
}
function reconcile(extra = {}) { return { available: true, enabled: true, auto_apply: true, state: 'observed', last_checked_at: '2026-10-15T19:00:00Z', items: [], ...extra }; }
function setupSystem(ui) {
    ui.routes.set('service_reconciliation/list', () => envelope(reconcile()));
    ui.routes.set('full_backup/list', () => envelope({ available: true, items: [{ name: filename, bytes: 1024, createdAt: '2026-10-15T19:00:00Z' }], hasMore: false }));
    ui.workflows.onView('system'); return settle();
}
function restorePreview() {
    const summary = { devices: 3, services: 12, pendingJobs: 4, appliedServices: 8, recoveries: 0, retainedRecords: 2, password: 'PRIVATE-SUMMARY' };
    return { previewToken, candidate: { ...summary, createdAt: '2026-10-15T19:00:00Z', name: filename }, current: { ...summary, devices: null }, password: 'PRIVATE-TOP-LEVEL' };
}
function item(serviceId, state = 'changes') {
    return { serviceId, clientId: 7, clientName: attack, state, reason: attack, oldAddress: attack, newAddress: '192.0.2.5', oldAddress6: '', newAddress6: '', reconnect: true, migration: serviceId === 42, sourceDeviceId: 3,
        password: 'PRIVATE-ITEM', fields: [{ field: 'profile', expected: attack, observed: attack, state: 'mismatch' }, { field: 'password', expected: 'PRIVATE-FIELD', observed: 'PRIVATE-FIELD' }] };
}
function syncResult(extra = {}) { return { previewToken, total: 2, processed: 2, done: true, catalogComplete: true, catalogScanned: 2, items: [item(42), item(43, 'unchanged')], ...extra }; }
async function capacityAndDrafts() {
    const ui = fixture(); assert.equal(ui.requests.length, 0); ui.workflows.onView('devices'); assert.equal(ui.requests.length, 0, 'Heavy capacity query started without user input');
    ui.routes.set('pool_capacity/list', () => envelope({ available: true, checked_at: '2026-10-15T19:00:00Z', invalid_records: 1, items: [
        { site: attack, family: 'IPv6', pool: attack, available: true, total: '18446744073709551616', assigned: '5', retained: '2', excluded: '1', free: '18446744073709551608', unit_prefix: 64, shared_pool: true, warning: 'low', password: 'PRIVATE-CAPACITY' },
        { site: 'Unknown', family: 'IPv4', available: false, free: '0' }
    ] }));
    await ui.node('refresh-pool-capacity').emit('click'); await settle();
    assert.equal(ui.requests.length, 1); assert.match(html(ui.node('pool-capacity-list')), /18446744073709551616/);
    assert(!html(ui.node('pool-capacity-list')).includes('<img')); assert(!html(ui.node('pool-capacity-list')).includes('PRIVATE-CAPACITY'));
    assert.match(html(ui.node('pool-capacity-list').children[1]), /Sin comprobar/); assert(!html(ui.node('pool-capacity-list').children[1]).includes('>0<'), 'Unknown capacity became zero');
    assert.match(ui.node('pool-capacity-status').textContent, /inválidos/);
    await setupSystem(ui); assert.equal(ui.node('pool-capacity-section').style.display, 'none');
    ui.node('service-reconciliation-enabled').checked = false; await ui.node('service-reconciliation-enabled').emit('change');
    ui.routes.set('service_reconciliation/list', () => envelope(reconcile({ items: [{ service_id: 42, reason: attack, fields: ['profile', 'password'], password: 'PRIVATE-INCIDENT' }] })));
    await ui.node('refresh-service-reconciliation').emit('click'); await settle();
    const calls = ui.requests.length; await ui.language('en');
    assert.equal(ui.requests.length, calls, 'Language render issued a network request'); assert.equal(ui.node('service-reconciliation-enabled').checked, false, 'Refresh/language discarded draft');
    assert(!html(ui.node('service-reconciliation-list')).includes('<img')); assert(!html(ui.node('service-reconciliation-list')).includes('PRIVATE-INCIDENT'));
    await ui.language('es'); await button(ui.node('service-reconciliation-list'), 'Diagnóstico por servicio').emit('click'); assert.deepEqual(ui.navigation[0], ['diagnostics', 42]);
    ui.routes.set('config/edit', data => { assert.deepEqual(JSON.parse(JSON.stringify(data)), { service_reconciliation_enabled: false, service_reconciliation_auto_apply: true }); return envelope({}); });
    await ui.node('save-service-reconciliation').emit('click'); assert.equal(ui.notifications.at(-1).kind, 'success');
    ui.workflows.onView('devices'); assert.equal(ui.node('pool-capacity-section').style.display, '');
    for (const request of ui.requests) assert(!['jobs', 'system'].includes(request.body.target), 'Read/settings invoked provisioning');
}
async function restoreSafety() {
    const ui = fixture(); await setupSystem(ui); let previews = 0, applies = 0;
    ui.routes.set('full_backup/preview', data => { assert.equal(data.name, filename); previews++; return envelope(restorePreview()); });
    ui.routes.set('full_backup/apply', data => { assert.equal(data.previewToken, previewToken); applies++; throw new Error('PRIVATE-TRANSPORT'); });
    await button(ui.node('full-backup-list'), 'Revisar restauración completa').emit('click'); await settle();
    assert.equal(ui.node('apply-full-restore').disabled, true); await ui.node('apply-full-restore').emit('click'); assert.equal(applies, 0, 'Restore omitted explicit confirmation');
    assert(!html(ui.node('full-restore-summary')).includes('PRIVATE')); assert.match(html(ui.node('full-restore-summary')), /Sin comprobar/);
    ui.node('full-restore-confirm').checked = true; await ui.node('full-restore-confirm').emit('change'); assert.equal(ui.node('apply-full-restore').disabled, false);
    await ui.node('apply-full-restore').emit('click'); await settle(); assert.equal(applies, 1);
    ui.node('full-restore-confirm').checked = true; await ui.node('apply-full-restore').emit('click'); assert.equal(applies, 1, 'Ambiguous restore replayed consumed token');
    assert.match(ui.node('full-restore-status').textContent, /No se pudo confirmar/); assert(!html(ui.node('full-restore-modal')).includes('PRIVATE-TRANSPORT'));
    assert.equal(ui.applied[0].requiresReload, true); assert.equal(ui.applied[0].unconfirmed, true); assert.equal(ui.node('reload-after-full-restore').style.display, '');
    const success = fixture(); await setupSystem(success);
    success.routes.set('full_backup/preview', () => envelope(restorePreview()));
    success.routes.set('full_backup/apply', () => { applies++; return envelope({ restored: true, requiresReload: true, backupName: beforeFile, password: 'PRIVATE-RESTORED' }); });
    await button(success.node('full-backup-list'), 'Revisar restauración completa').emit('click'); await settle();
    success.node('full-restore-confirm').checked = true; await success.node('full-restore-confirm').emit('change'); await success.node('apply-full-restore').emit('click');
    assert.equal(applies, 2); assert.equal(success.applied[0].requiresReload, true); assert.equal(success.node('create-full-backup').disabled, true);
    const download = all(success.node('full-restore-summary')).find(item => item.tagName === 'A'); assert(download); assert.match(download.href, /download=full-backup-/); assert.equal(download.download, beforeFile);
    await success.language('en'); assert(all(success.node('full-restore-summary')).some(item => item.download === beforeFile), 'Language change lost the before-restore backup download');
    const calls = success.requests.length; await success.node('create-full-backup').emit('click'); await success.workflows.previewSync(1); assert.equal(success.requests.length, calls, 'Write allowed after required reload');
}
async function backupCreationUploadAndStalePreview() {
    const ui = fixture(); await setupSystem(ui);
    ui.routes.set('full_backup/create', () => envelope({ name: filename, createdAt: '2026-10-15T19:00:00Z', bytes: 200, summary: { password: 'PRIVATE-CREATE' } }));
    await ui.node('create-full-backup').emit('click'); assert.match(ui.node('full-backup-status').textContent, /creado/); assert(!html(ui.node('full-backup-list')).includes('PRIVATE-CREATE'));
    const pending = deferred(); ui.routes.set('full_backup/upload', data => { assert.equal(data.get('workflow'), 'full_backup'); assert.equal(data.get('fullBackup').name, 'fixture.zip'); return pending.promise; });
    ui.node('full-backup-file').files = [{ name: 'fixture.zip' }]; await ui.node('full-backup-file').emit('change'); await settle();
    ui.workflows.close(); pending.resolve(envelope(restorePreview())); await settle(); assert.equal(ui.node('full-restore-modal').style.display, 'none'); assert.equal(ui.node('apply-full-restore').disabled, true, 'Closed upload preview restored a stale token');
}
async function syncPreviewAndApply() {
    const ui = fixture(); let step = 0;
    ui.routes.set('sync_preview/begin', data => { assert.equal(data.deviceId, 4); return envelope(syncResult({ total: 0, processed: 0, done: false, catalogScanned: 0, catalogComplete: false, items: [] })); });
    ui.routes.set('sync_preview/step', data => { assert.equal(data.previewToken, previewToken); step++; return envelope(step === 1 ? syncResult({ total: 2, processed: 0, done: false, catalogScanned: 2, catalogComplete: false, items: [] }) : step === 2 ? syncResult({ processed: 1, done: false, items: [item(42)] }) : syncResult({ items: [item(43, 'unchanged')] })); });
    await ui.workflows.previewSync({ id: 4 }); assert.equal(step, 3); assert.match(ui.node('sync-preview-status').textContent, /2 de 2/); assert.equal(ui.node('sync-preview-list').children.length, 2);
    assert.equal(ui.node('sync-preview-apply').disabled, false); assert.equal(ui.node('sync-preview-reconnect').disabled, false);
    const rendered = html(ui.node('sync-preview-list')); assert(!rendered.includes('<img') && !rendered.includes('<script')); assert(!rendered.includes('PRIVATE-ITEM') && !rendered.includes('PRIVATE-FIELD'));
    assert.match(rendered, /Router anterior: #3/); assert.match(rendered, /Router de destino: #4/); assert.match(rendered, /Se retirará la cuenta/);
    assert(rendered.includes('&lt;img'), 'Expected/observed fields were not escaped');
    assert(ui.requests.every(request => request.body.changeType === 'list'), 'Review modified provisioning');
    let apply = 0; ui.routes.set('sync_preview/apply', data => { assert.equal(data.mode, 'differences'); assert.equal(data.previewToken, previewToken); apply++; return envelope({ serviceId: 41 + apply, processed: apply, total: 2, done: apply === 2, state: apply === 1 ? 'queued' : 'skipped', jobKeys: apply === 1 ? ['Q42'] : [] }); });
    await ui.node('sync-preview-apply').emit('click'); assert.equal(apply, 2); assert.match(ui.node('sync-preview-status').textContent, /programados: 1/); assert.equal(ui.applied[0].queued, 1);
    assert.match(html(ui.node('sync-preview-list')), /Omitidos: 1/); await button(ui.node('sync-preview-list'), 'Revisar trabajo').emit('click'); assert.deepEqual(ui.reviews[0], ['Q42', 42]);
    await ui.node('sync-preview-apply').emit('click'); assert.equal(apply, 2, 'Applied preview token was replayed');
    assert(!ui.requests.some(request => request.body.target === 'jobs' || request.body.target === 'system'), 'Preview ran jobs or old sync route');
    ui.workflows.close(); ui.routes.set('sync_preview/begin', () => envelope(syncResult({ previewToken: otherToken, total: 1, processed: 1, items: [item(43, 'unchanged')] })));
    await ui.workflows.previewSync(4); assert.equal(ui.node('sync-preview-apply').disabled, true); assert.equal(ui.node('sync-preview-reconnect').disabled, false);
    ui.routes.set('sync_preview/apply', data => { assert.equal(data.mode, 'force_reconnect'); return envelope({ serviceId: 43, processed: 1, total: 1, done: true, state: 'queued', jobKeys: ['Q43'] }); });
    await ui.node('sync-preview-reconnect').emit('click'); assert.equal(ui.applied.at(-1).queued, 1);
}
async function cancelRacesAndFailures() {
    const ui = fixture(); const pending = deferred(); let steps = 0;
    ui.routes.set('sync_preview/begin', () => pending.promise); ui.routes.set('sync_preview/step', () => { steps++; return envelope(syncResult()); });
    const reading = ui.workflows.previewSync(4); ui.workflows.close(); pending.resolve(envelope(syncResult({ processed: 0, done: false, items: [] }))); await reading;
    assert.equal(steps, 0, 'Cancelled review continued reading'); assert.equal(ui.node('sync-preview-modal').style.display, 'none'); assert.equal(ui.node('sync-preview-apply').disabled, true);
    ui.routes.set('sync_preview/begin', () => envelope(syncResult())); await ui.workflows.previewSync(4);
    const applying = deferred(); let applies = 0;
    ui.routes.set('sync_preview/apply', () => { applies++; return applying.promise; });
    const action = ui.node('sync-preview-apply').emit('click'); await settle(); ui.workflows.close();
    assert.equal(ui.node('sync-preview-modal').style.display, 'flex', 'In-flight mutation outcome was hidden');
    applying.resolve(envelope({ serviceId: 42, processed: 1, total: 2, done: false, state: 'queued', jobKeys: ['Q42'] })); await action; assert.equal(applies, 1, 'Cancel sent another mutation'); assert.equal(ui.applied[0].queued, 1);
    ui.workflows.close(); await ui.workflows.previewSync(4); ui.routes.set('sync_preview/apply', () => { applies++; throw new Error('PRIVATE-APPLY'); });
    await ui.node('sync-preview-apply').emit('click'); assert.match(ui.node('sync-preview-status').textContent, /No se pudo confirmar/); assert(!html(ui.node('sync-preview-list')).includes('PRIVATE-APPLY'));
    await ui.node('sync-preview-apply').emit('click'); assert.equal(applies, 2, 'Ambiguous sync apply replayed token');
    const calls = ui.requests.length; await ui.mutation(true); await ui.workflows.previewSync(4); assert.equal(ui.requests.length, calls, 'Preview began during another write'); await ui.mutation(false);
    const stale = deferred(); ui.routes.set('service_reconciliation/list', () => stale.promise); ui.routes.set('full_backup/list', () => envelope({ available: true, items: [] })); ui.workflows.onView('system'); ui.workflows.onView('devices');
    stale.resolve(envelope(reconcile({ items: [{ service_id: 999, reason: 'correction_queued' }] }))); await settle(); assert(!html(ui.node('service-reconciliation-list')).includes('999'), 'Stale view response rendered');
}
async function fullPreviewPaginationAndGuardedCounts() {
    const ui = fixture(); const items = Array.from({ length: 201 }, (_, offset) => item(1000 + offset));
    ui.routes.set('sync_preview/begin', () => envelope(syncResult({ total: 201, processed: 201, catalogScanned: 201, items })));
    await ui.workflows.previewSync(4);
    assert.equal(all(ui.node('sync-preview-list')).filter(node => node.tagName === 'ARTICLE').length, 100);
    assert.match(html(ui.node('sync-preview-list')), /100 de 201/);
    await button(ui.node('sync-preview-list'), 'Mostrar más servicios').emit('click');
    assert.equal(all(ui.node('sync-preview-list')).filter(node => node.tagName === 'ARTICLE').length, 200);
    await button(ui.node('sync-preview-list'), 'Mostrar más servicios').emit('click');
    assert.equal(all(ui.node('sync-preview-list')).filter(node => node.tagName === 'ARTICLE').length, 201);
    assert(!all(ui.node('sync-preview-list')).some(node => node.tagName === 'BUTTON' && node.textContent === 'Mostrar más servicios'));
    const calls = ui.requests.length; await ui.language('en'); await ui.language('es'); assert.equal(ui.requests.length, calls, 'Showing more/language rerender repeated observation');
    ui.workflows.close(); ui.routes.set('sync_preview/begin', () => envelope(syncResult())); await ui.workflows.previewSync(4);
    let apply = 0; ui.routes.set('sync_preview/apply', () => { apply++; return envelope({ serviceId: 41 + apply, processed: apply, total: 2, done: apply === 2,
        state: apply === 1 ? 'blocked' : 'unconfirmed', reason: apply === 1 ? 'sync_preview_state_changed' : attack, jobKeys: [attack] }); });
    await ui.node('sync-preview-apply').emit('click');
    assert.match(html(ui.node('sync-preview-list')), /Programados: 0 · Omitidos: 0 · Bloqueados: 1 · Sin confirmar: 1/);
    assert.equal(ui.applied[0].queued, 0, 'Blocked/unconfirmed work counted as queued');
    assert(!all(ui.node('sync-preview-list')).some(node => node.tagName === 'BUTTON' && node.textContent === 'Revisar trabajo'), 'Untrusted Q key created a job action');
    assert(!html(ui.node('sync-preview-list')).includes('<img'));
    ui.workflows.close(); await ui.workflows.previewSync(4);
    ui.routes.set('sync_preview/apply', () => envelope({ serviceId: 999, processed: 1, total: 2, done: false, state: 'queued', jobKeys: ['Q999'] }));
    await ui.node('sync-preview-apply').emit('click'); assert.match(ui.node('sync-preview-status').textContent, /No se pudo confirmar/);
    assert(!all(ui.node('sync-preview-list')).some(node => node.tagName === 'BUTTON' && node.textContent === 'Revisar trabajo'), 'Wrong service response attached a foreign job');
}
async function apiReloadGuard() {
    const ui = fixture(); ui.api.requireReload(); const start = ui.requests.length;
    const writes = [() => ui.api.saveConfig({}), () => ui.api.saveDevice('edit', { id: 1 }), () => ui.api.savePlan({ id: 1 }), () => ui.api.rebuild(),
        () => ui.api.applySyncPreview(previewToken, 'differences'), () => ui.api.restoreFullBackup(previewToken), () => ui.api.createFullBackup(),
        () => ui.api.applyNetworkRepair(previewToken), () => ui.api.applyJobRecovery(previewToken), () => ui.api.uploadBackup({}), () => ui.api.uploadFullBackup({})];
    for (const write of writes) { const result = await write(); assert.equal(result.error, true, 'API accepted a stale-page write after restore'); }
    assert.equal(ui.requests.length, start, 'Stale write reached transport after requireReload');
    ui.routes.set('pool_capacity/list', () => envelope({ available: false, items: [] })); await ui.api.getPoolCapacity();
    assert.equal(ui.requests.length, start + 1, 'Reload guard blocked a read-only diagnostic');
    assert.match(ui.api.downloadUrl(beforeFile), /download=/, 'Reload guard prevented before-restore backup download');
}
(async () => {
    await capacityAndDrafts(); await restoreSafety(); await backupCreationUploadAndStalePreview(); await syncPreviewAndApply(); await cancelRacesAndFailures();
    await fullPreviewPaginationAndGuardedCounts(); await apiReloadGuard();
    console.log('Workflow UI regression checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
