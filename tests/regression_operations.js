/* Real operations/API modules with an in-memory DOM and fetch transport. No network or provisioning. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const panel = path.join(__dirname, '../src/public/panel_v2/js');
const attack = '\"><img src=x onerror=alert(1)><script>alert(2)</script>&\'';
const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const settle = async () => { for (let index = 0; index < 12; index++) await Promise.resolve(); };
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }
const envelope = data => ({ status: 'ok', error: false, data });
class Element {
    constructor(tagName = 'div', id = '') { this.tagName = tagName.toUpperCase(); this.id = id; this.children = []; this.events = new Map(); this.style = {}; this.value = ''; this.disabled = false; this._html = ''; this._text = ''; this.className = ''; }
    set innerHTML(value) { this._html = String(value); this._text = ''; this.children = []; }
    get innerHTML() { return this._html; }
    set textContent(value) { this._text = String(value); this._html = ''; this.children = []; }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    appendChild(child) { this.children.push(child); return child; }
    replaceChildren(...children) { this._html = ''; this._text = ''; this.children = children; }
    addEventListener(name, callback) { if (!this.events.has(name)) this.events.set(name, []); this.events.get(name).push(callback); }
    async emit(name, event = {}) { for (const callback of this.events.get(name) || []) await callback({ preventDefault() {}, ...event }); }
    focus() { this.focused = true; }
    setAttribute(name, value) { this[name] = String(value); }
    querySelectorAll() { return []; }
}
function all(node) { return [node, ...node.children.flatMap(all)]; }
function html(node) { return node.innerHTML + escape(node._text) + node.children.map(html).join(''); }
function button(node, label) { const found = all(node).find(item => item.tagName === 'BUTTON' && item.textContent === label); assert(found, 'Missing button ' + label); return found; }
function assertReadOnly(ui) {
    for (const request of ui.requests) {
        assert.equal(request.body.changeType, 'list', 'Operational observation attempted to provision');
        assert(['automation_health', 'service_diagnostics', 'provisioning_verification'].includes(request.body.target), 'Operational observation reached a mutation target');
        assert(!/update|delete|disconnect|reconnect|apply/.test(request.body.action), 'Operational observation invoked a mutation action');
    }
}
function fixture(reviewEnabled = true) {
    const nodes = new Map(); const events = new Map(); const timers = new Map(); const requests = []; const routes = new Map(); let timerId = 0;
    const document = {
        baseURI: 'https://fixture.invalid/public.php', visibilityState: 'visible', documentElement: {},
        getElementById(id) { if (!nodes.has(id)) nodes.set(id, new Element('div', id)); return nodes.get(id); },
        createElement(name) { return new Element(name); }, querySelectorAll() { return []; },
        addEventListener(name, callback) { if (!events.has(name)) events.set(name, []); events.get(name).push(callback); }
    };
    const context = vm.createContext({ document, URL, console, location: { href: document.baseURI },
        setTimeout(callback, delay) { const id = ++timerId; timers.set(id, { callback, delay }); return id; }, clearTimeout(id) { timers.delete(id); },
        async fetch(url, options) {
            const body = JSON.parse(options.body); requests.push({ url, options, body });
            assert.equal(body.changeType, 'list', 'Operations sent a provisioning/mutation request');
            assert(['automation_health', 'service_diagnostics', 'provisioning_verification'].includes(body.target), 'Operations touched another resource');
            const callback = routes.get(body.target + '/' + body.action);
            assert(callback, 'Unexpected route ' + body.target + '/' + body.action);
            const response = await callback(body.data);
            return { ok: true, status: 200, async json() { return response; } };
        }
    });
    context.window = context;
    vm.runInContext(fs.readFileSync(path.join(panel, 'i18n.js'), 'utf8'), context);
    vm.runInContext(fs.readFileSync(path.join(panel, 'api.js'), 'utf8'), context);
    vm.runInContext(fs.readFileSync(path.join(panel, 'operations.js'), 'utf8'), context);
    const api = vm.runInContext('api', context); const operations = context.PanelOperations;
    const navigations = []; const reviews = [];
    operations.mount({ api, navigate(view) { navigations.push(view); operations.onView(view); },
        ...(reviewEnabled ? { reviewJob(job, service) { reviews.push([job, service]); } } : {}) });
    return { context, document, nodes, timers, requests, routes, api, operations, navigations, reviews,
        node: id => document.getElementById(id),
        async visibility(state) { document.visibilityState = state; for (const listener of events.get('visibilitychange') || []) listener(); await settle(); },
        async mutation(active) { for (const listener of api.mutationListeners) listener(active); await settle(); },
        async runTimers(delay) { const pending = [...timers.entries()].filter(([, timer]) => delay === undefined || timer.delay === delay); for (const [id, timer] of pending) { timers.delete(id); await timer.callback(); } await settle(); }
    };
}
function receipt(service, state = 'verified') {
    return { service_id: service, state, current: true, checked_at: '2026-10-15T16:00:00Z', checks: [{ kind: 'account', state, path: '/ppp/secret', fields: [
        { field: 'name', expected: attack, observed: attack, state: 'verified' },
        { field: 'password', expected: 'PASSWORD-MUST-NOT-RENDER', observed: 'PASSWORD-MUST-NOT-RENDER', state: 'verified' }
    ] }, { kind: 'resource', path: '/queue/simple', state: 'verified', fields: [{ field: 'target', expected: '192.0.2.42/32', observed: '192.0.2.42/32', state: 'verified' }] }] };
}
function diagnostic(service) {
    const values = { username: attack, site: attack, address: attack, address6: attack, profile: attack, status: 1, ownership: attack };
    return { service_id: service, client_id: 17, client_name: attack, checked_at: '2026-10-15T16:00:00Z',
        uisp: { available: true, values, checked_at: '2026-10-15T16:00:00Z' }, expected: { available: true, source: 'queued', values, checked_at: null },
        router: { available: true, values, reason: null, checked_at: '2026-10-15T16:00:00Z' },
        comparisons: Object.entries(values).map(([field, value]) => ({ field, uisp: value, expected: value, router: value, matches_router: field === 'ownership' ? false : null, matches_uisp: true }))
            .concat([{ field: 'password', uisp: 'PRIVATE-COMPARISON', expected: 'PRIVATE-COMPARISON', router: 'PRIVATE-COMPARISON' }]),
        verification: receipt(service), jobs: { available: true, items: [{ job_id: attack, status: 'pending', updated_at: '2026-10-15T16:00:00Z', error_code: 'operation_failed', error: 'RAW-JOB-SECRET' }] },
        history: { available: true, recovery_count: 1, retained_count: 2 }, issues: ['router_unavailable', attack] };
}
async function resultsAndRechecks() {
    const ui = fixture(); let calls = 0;
    ui.routes.set('provisioning_verification/list', () => envelope({ available: true, items: [receipt(42), receipt(43, 'unconfirmed'), receipt(44, 'mismatch')], has_more: false }));
    ui.routes.set('provisioning_verification/recheck', data => { calls++; assert.equal(data.serviceId, 42); return envelope(receipt(42, 'mismatch')); });
    ui.operations.onView('jobs'); await settle();
    const list = ui.node('provisioning-results-list');
    assert.equal(list.children.length, 3);
    assert.match(html(list), /Aplicado y verificado/); assert.match(html(list), /Aplicado sin confirmar/); assert.match(html(list), /Diferencias en el router/);
    assert.match(html(list), /<strong>Cola<\/strong>/, 'Queue resource was labelled as an account');
    assert(!html(list).includes('<img') && !html(list).includes('<script'), 'Receipt fields were rendered as HTML');
    assert(!html(list).includes('PASSWORD-MUST-NOT-RENDER'), 'Unknown receipt field exposed credentials');
    const first = list.children[0]; await button(first, 'Verificar de nuevo').emit('click'); await settle();
    assert.equal(calls, 1); assert.equal(ui.node('provisioning-results-status').textContent, 'Diferencias en el router');
    assert.match(html(ui.node('provisioning-results-list').children[0]), /Diferencias en el router/);
    ui.routes.set('provisioning_verification/recheck', () => { throw new Error('SECRET-TRANSPORT-ERROR'); });
    await button(ui.node('provisioning-results-list').children[0], 'Verificar de nuevo').emit('click'); await settle();
    assert.equal(ui.node('provisioning-results-status').textContent, 'No se pudo completar la consulta.', 'Recheck failure was erased by the final render');
    assert(!html(ui.node('provisioning-results-list')).includes('SECRET-TRANSPORT-ERROR'));
    ui.routes.set('provisioning_verification/recheck', () => envelope(receipt(99)));
    await button(ui.node('provisioning-results-list').children[0], 'Verificar de nuevo').emit('click'); await settle();
    assert.equal(ui.node('provisioning-results-status').textContent, 'No se pudo completar la consulta.', 'A different service receipt was accepted');
    const before = ui.requests.length; await ui.mutation(true);
    await button(ui.node('provisioning-results-list').children[0], 'Verificar de nuevo').emit('click');
    assert.equal(ui.requests.length, before, 'Recheck ran during provisioning');
    await ui.mutation(false); await settle();
    assertReadOnly(ui);
    return ui;
}
async function diagnosticsAndRaces() {
    const ui = fixture(); const searchCalls = []; const inspectCalls = [];
    ui.routes.set('service_diagnostics/search', data => { searchCalls.push(data.query); return envelope({ available: true, items: [{ service_id: 42, client_name: attack, username: attack, address: '192.0.2.42' }] }); });
    ui.routes.set('service_diagnostics/inspect', data => { inspectCalls.push(data.serviceId); return envelope(diagnostic(data.serviceId)); });
    ui.node('service-diagnostic-query').value = 'fixture-user';
    await ui.node('service-diagnostic-search').emit('submit'); await settle();
    assert.deepEqual(searchCalls, ['fixture-user']); assert.equal(inspectCalls.length, 0, 'Text search guessed a service to inspect');
    const search = ui.node('service-diagnostic-results'); assert.equal(search.children.length, 1);
    assert(!html(search).includes('<img'), 'Search results used HTML for remote text');
    await search.children[0].emit('click'); await settle();
    assert.deepEqual(inspectCalls, [42]); assert.equal(ui.navigations.at(-1), 'diagnostics');
    const content = html(ui.node('service-diagnostic-detail'));
    assert(content.includes('Servicio #42') && content.includes('Diferencia detectada'));
    assert(!content.includes('<img') && !content.includes('<script'), 'A diagnostic cell executed remote markup');
    assert(!content.includes('PRIVATE-COMPARISON') && !content.includes('PASSWORD-MUST-NOT-RENDER') && !content.includes('RAW-JOB-SECRET'), 'Diagnostics exposed an unknown or sensitive field');
    assert(content.includes('&lt;img'), 'Remote values were omitted instead of safely displayed');
    ui.routes.set('provisioning_verification/recheck', () => { throw new Error('PRIVATE-RECHECK-ERROR'); });
    await button(ui.node('service-diagnostic-detail'), 'Verificar de nuevo').emit('click'); await settle();
    assert.equal(ui.node('service-diagnostic-status').textContent, 'No se pudo completar la consulta.', 'Diagnostic recheck error was erased by rendering');
    assert(!html(ui.node('service-diagnostic-detail')).includes('PRIVATE-RECHECK-ERROR'));
    ui.node('service-diagnostic-query').value = '77';
    await ui.node('service-diagnostic-search').emit('submit'); await settle();
    assert.equal(inspectCalls.at(-1), 77, 'Numeric search did not inspect the exact requested service');
    const first = deferred(); const second = deferred();
    ui.routes.set('service_diagnostics/inspect', data => data.serviceId === 100 ? first.promise : second.promise);
    const a = ui.operations.openService(100); const b = ui.operations.openService(200);
    second.resolve(envelope(diagnostic(200))); await b; await settle();
    first.resolve(envelope(diagnostic(100))); await a; await settle();
    assert(html(ui.node('service-diagnostic-detail')).includes('Servicio #200') && !html(ui.node('service-diagnostic-detail')).includes('Servicio #100'), 'Late service A replaced selected service B');
    ui.routes.set('service_diagnostics/inspect', () => envelope(diagnostic(301)));
    await ui.operations.openService(300); await settle();
    assert.equal(ui.node('service-diagnostic-detail').style.display, 'none', 'Different returned service was displayed');
    assert.equal(ui.node('service-diagnostic-status').textContent, 'No se pudo completar la consulta.');
    const slow = deferred(); const fast = deferred();
    ui.routes.set('service_diagnostics/search', data => data.query === 'old' ? slow.promise : fast.promise);
    ui.node('service-diagnostic-query').value = 'old'; const old = ui.node('service-diagnostic-search').emit('submit');
    ui.node('service-diagnostic-query').value = 'new'; const next = ui.node('service-diagnostic-search').emit('submit');
    fast.resolve(envelope({ available: true, items: [{ service_id: 500, client_name: 'New result' }] })); await next;
    slow.resolve(envelope({ available: true, items: [{ service_id: 400, client_name: 'Old result' }] })); await old;
    assert(html(ui.node('service-diagnostic-results')).includes('New result') && !html(ui.node('service-diagnostic-results')).includes('Old result'), 'Stale search replaced newer search');
    assertReadOnly(ui);
}
async function automationPolling() {
    const ui = fixture(); let calls = 0;
    const unknown = { checkedAt: '2026-10-15T16:00:00Z', scheduler: { state: 'never_observed' }, webhook: { state: 'unknown' },
        queue: { state: 'unknown', pending: null, running: null, failed: null, timingKnown: false }, cache: { state: 'unknown' }, maintenance: { state: 'unknown' } };
    ui.routes.set('automation_health/list', () => { calls++; return envelope(unknown); });
    assert.equal(ui.requests.length, 0, 'Mount polled inactive views');
    ui.operations.onView('system'); await settle();
    const content = html(ui.node('automation-health-content'));
    assert(content.includes('Sin observaciones') && content.includes('Sin comprobar'));
    assert(!content.includes('<strong>0</strong>'), 'Unknown counters were presented as zero');
    assert(!content.includes('Actividad reciente') && !content.includes('badge-success'), 'Missing heartbeat presented as healthy');
    assert([...ui.timers.values()].some(timer => timer.delay === 30000), 'Health refresh did not schedule a bounded poll');
    const beforeHidden = calls; await ui.visibility('hidden');
    assert.equal(ui.timers.size, 0); await ui.operations.refreshHealth(); await ui.runTimers(); assert.equal(calls, beforeHidden, 'Hidden page polled automation');
    await ui.visibility('visible'); assert.equal(calls, beforeHidden + 1);
    const beforeMutation = calls; await ui.mutation(true); assert.equal(ui.timers.size, 0);
    await ui.operations.refreshHealth(); await ui.runTimers(); assert.equal(calls, beforeMutation, 'Health poll ran while a mutation was active');
    await ui.mutation(false); assert.equal(calls, beforeMutation + 1);
    ui.operations.onView('devices'); assert.equal(ui.timers.size, 0); await ui.operations.refreshHealth(); assert.equal(calls, beforeMutation + 1);
    const pending = deferred(); ui.routes.set('automation_health/list', () => pending.promise);
    ui.operations.onView('system'); await settle(); await ui.mutation(true);
    pending.resolve(envelope({ ...unknown, scheduler: { state: 'healthy' }, queue: { state: 'known', pending: 0, running: 0, failed: 0 } })); await settle();
    assert(!html(ui.node('automation-health-content')).includes('Actividad reciente'), 'In-flight stale health replaced data across a mutation');
    ui.routes.set('automation_health/list', () => envelope(unknown)); await ui.mutation(false); await ui.runTimers(0);
    assert(html(ui.node('automation-health-content')).includes('Sin observaciones'));
    ui.routes.set('automation_health/list', () => { throw new Error('PRIVATE-HEALTH-ERROR'); });
    await ui.operations.refreshHealth(); await settle();
    assert.equal(ui.node('automation-health-status').textContent, 'No se pudo actualizar. Se conserva la última lectura.');
    assert(!html(ui.node('automation-health-content')).includes('PRIVATE-HEALTH-ERROR'));
    assertReadOnly(ui);
}
async function jobReviewAndHistory() {
    const ui = fixture();
    let detail = diagnostic(42);
    detail.jobs = { available: true, has_more: true, items: [
        { job_id: 'Q201', status: 'pending', attempts: 3, next_attempt: '2026-10-15T17:00:00Z', error_code: 'invalid_network_address', error: 'PRIVATE-RAW-JOB-ERROR' },
        { job_id: 'Q202', status: 'running', attempts: 5, next_attempt: '2026-10-15T17:00:00Z' },
        { job_id: 'Q203', status: 'succeeded', attempts: 2 },
        { job_id: 'Q0', status: 'pending', attempts: null },
        { job_id: 'Q204', status: 'pending', attempts: -1, next_attempt: 'invalid', error_code: attack },
        { job_id: attack, status: 'pending', error: 'PRIVATE-RAW-JOB-ERROR' }
    ] };
    ui.routes.set('service_diagnostics/inspect', () => envelope(detail));
    await ui.operations.openService(42); await settle();
    const container = ui.node('service-diagnostic-detail'); const content = html(container);
    assert(content.includes('Intentos: 3') && content.includes('Intentos: 5'), 'Known job attempts omitted');
    assert.equal((content.match(/Próximo intento:/g) || []).length, 1, 'Retry timestamp shown for a running/completed/unknown retry');
    assert(content.includes('Con error') && !content.includes('PRIVATE-RAW-JOB-ERROR'), 'Finite job failure summary exposed raw text');
    assert(content.includes('Registros de recuperación: 1') && content.includes('Registros informativos conservados: 2')
        && content.includes('Estos registros no son trabajos ejecutables.'), 'History presented as executable pending work');
    const controls = all(container).filter(item => item.tagName === 'BUTTON' && item.textContent === 'Revisar trabajo');
    assert.equal(controls.length, 3, 'Review action accepted an invalid Q ID or completed job');
    const requests = ui.requests.length;
    for (const control of controls) await control.emit('click');
    assert.deepEqual(ui.reviews, [['Q201', 42], ['Q202', 42], ['Q204', 42]], 'Review did not identify the exact pending/running job');
    assert.equal(ui.requests.length, requests, 'Review action executed a task instead of opening it');
    detail = { ...detail, history: { available: false, recovery_count: null, retained_count: null } };
    await ui.operations.openService(42); assert(!html(container).includes('Historial local'), 'Unknown history counters shown as zero');
    const without = fixture(false); without.routes.set('service_diagnostics/inspect', () => envelope(detail));
    await without.operations.openService(42);
    assert(!all(without.node('service-diagnostic-detail')).some(item => item.tagName === 'BUTTON' && item.textContent === 'Revisar trabajo'), 'Missing review callback created a dead control');
    assertReadOnly(ui); assertReadOnly(without);
}
(async () => {
    await resultsAndRechecks(); await diagnosticsAndRaces(); await jobReviewAndHistory(); await automationPolling();
    console.log('Operations UI regression passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
