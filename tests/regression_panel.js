/* Regression tests use only in-memory fixtures; they never contact UISP or RouterOS. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const panelRoot = path.join(__dirname, '../src/public/panel_v2');
const apiSource = fs.readFileSync(path.join(panelRoot, 'js/api.js'), 'utf8');
const appSource = fs.readFileSync(path.join(panelRoot, 'js/app.js'), 'utf8');
const i18nSource = fs.readFileSync(path.join(panelRoot, 'js/i18n.js'), 'utf8');
const markup = fs.readFileSync(path.join(panelRoot, 'index.php'), 'utf8');
const attack = '\"><img src=x onerror=alert(1)><script>alert(2)</script>&\'';
const requests = [];
const alerts = [];
const responders = new Map();
const responseStatuses = new Map();
const timers = new Map();
let timerSequence = 0;
const fixtureSetTimeout = (callback, delay) => {
    const id = ++timerSequence;
    timers.set(id, { callback, delay });
    return id;
};
const fixtureClearTimeout = id => timers.delete(id);

const decodeAttribute = value => String(value).replace(/&(quot|apos|amp|lt|gt|#39);/g, (_, entity) => ({
    quot: '"', apos: "'", amp: '&', lt: '<', gt: '>', '#39': "'"
}[entity]));

// Model the successful-control rules used by browser FormData. In particular an
// unchecked checkbox is omitted, while its preceding hidden fallback remains.
function parseControls(markup) {
    const controls = [];
    for (const match of markup.matchAll(/<input\b([^>]*)>|<select\b([^>]*)>([\s\S]*?)<\/select\s*>/gi)) {
        const control = new Element();
        control.tagName = match[1] !== undefined ? 'INPUT' : 'SELECT';
        const attributes = match[1] ?? match[2];
        for (const attribute of attributes.matchAll(/([^\s=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+)))?/g)) {
            control[attribute[1]] = attribute[2] === undefined && attribute[3] === undefined && attribute[4] === undefined
                ? true : decodeAttribute(attribute[2] ?? attribute[3] ?? attribute[4]);
        }
        if (control.type === 'checkbox' && !/\bvalue\s*=/.test(attributes)) control.value = 'on';
        if (control.tagName === 'SELECT') {
            const options = Array.from((match[3] || '').matchAll(/<option\b([^>]*)>([\s\S]*?)<\/option>/gi));
            const selected = options.find(option => /\bselected\b/.test(option[1])) || options[0];
            const value = selected?.[1].match(/\bvalue\s*=\s*"([^"]*)"/);
            control.value = decodeAttribute(value ? value[1] : selected?.[2] || '');
        }
        controls.push(control);
    }
    return controls;
}
function formControls(root) {
    return [...(root.markupControls || []), ...root.children.flatMap(formControls)];
}
function renderedMarkup(root) {
    return root.innerHTML + root.children.map(renderedMarkup).join('');
}
function displayWrites(element, from = 0) {
    return element.styleWrites.slice(from).filter(write => write.property === 'display').map(write => write.value);
}
function deferred() {
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    return { promise, resolve };
}

class Element {
    constructor(id = '') {
        this.id = id;
        this.children = [];
        // Record intermediate visibility changes, rather than testing only the
        // final state: a local edit can flash a loader and end with it hidden.
        this.styleWrites = [];
        this.style = new Proxy({}, {
            set: (target, property, value) => {
                this.styleWrites.push({ property, value });
                target[property] = value;
                return true;
            }
        });
        this.dataset = {};
        this.value = '';
        this.checked = false;
        this.disabled = false;
        this.listeners = new Map();
        this.selectors = new Map();
        this.classes = new Set();
        this.classList = {
            contains: name => this.classes.has(name),
            toggle: (name, active) => active ? this.classes.add(name) : this.classes.delete(name)
        };
    }
    set innerHTML(value) {
        this.markup = value;
        this.markupControls = parseControls(value);
        this.children = [];
        this.selectors.clear();
    }
    get innerHTML() { return this.markup || ''; }
    appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
    append(...children) { children.forEach(child => this.appendChild(child)); }
    replaceChildren(...children) {
        this.children.forEach(child => { child.parentNode = null; });
        this.children = [];
        this.markup = '';
        this.markupControls = [];
        this.selectors.clear();
        children.forEach(child => this.appendChild(child));
    }
    querySelector(selector) {
        if (selector === 'input[type="checkbox"]') {
            const checkbox = formControls(this).find(control => control.tagName === 'INPUT' && control.type === 'checkbox');
            if (checkbox) return checkbox;
        }
        if (!this.selectors.has(selector)) this.selectors.set(selector, new Element());
        return this.selectors.get(selector);
    }
    setAttribute(name, value) { this[name] = value; }
    getAttribute(name) { return this[name] ?? null; }
    addEventListener(type, listener) {
        if (!this.listeners.has(type)) this.listeners.set(type, []);
        this.listeners.get(type).push(listener);
    }
    async fire(type) {
        for (const listener of this.listeners.get(type) || []) {
            await listener({ preventDefault() {}, currentTarget: this, target: this });
        }
    }
    focus(options) { this.focusOptions = options; }
    remove() {
        if (this.parentNode) this.parentNode.children = this.parentNode.children.filter(child => child !== this);
        this.parentNode = null;
    }
    reset() {}
    setCustomValidity(message) { this.validationMessage = String(message); }
    reportValidity() {
        const invalid = this.controlsRoot && formControls(this.controlsRoot).find(control => {
            if (control.disabled) return false;
            if (control.validationMessage) return true;
            const value = String(control.value ?? '').trim();
            if (!value) return Boolean(control.required);
            if (control.type !== 'number') return false;
            const numeric = Number(value);
            return !Number.isFinite(numeric)
                || (control.min !== undefined && numeric < Number(control.min))
                || (control.max !== undefined && numeric > Number(control.max))
                || (control.step === '1' && !Number.isInteger(numeric));
        });
        this.invalidControl = invalid?.id;
        return !invalid;
    }
    get firstElementChild() { return this.children[0]; }
    get lastElementChild() { return this.children.at(-1); }
    get childElementCount() { return this.children.length; }
}
const elements = new Map(Array.from(markup.matchAll(/\bid="([^"]+)"/g), match => [match[1], new Element(match[1])]));
elements.get('tab-general').classes.add('active');
const navigation = ['devices', 'plans', 'config', 'jobs', 'system'].map(view => {
    const nav = new Element();
    nav.dataset.view = view;
    nav.querySelector('span').textContent = view;
    return nav;
});
const sections = ['devices', 'plans', 'config', 'jobs', 'system'].map(view => elements.get('view-' + view));
const headers = ['id', 'name', 'ip', 'users', 'status'].map(sort => {
    const header = new Element(); header.dataset.sort = sort; return header;
});
const tables = new Map(['devices', 'plans', 'jobs', 'orphan'].map(name => ['#' + name + '-table tbody', new Element()]));
let ready;
const document = {
    baseURI: 'https://fixture.invalid/crm/_plugins/ros-plugin/public/panel_v2/',
    body: new Element(),
    getElementById(id) { assert(elements.has(id), 'Missing panel element: ' + id); return elements.get(id); },
    addEventListener(type, listener) { if (type === 'DOMContentLoaded') ready = listener; },
    removeEventListener() {},
    createElement() { return new Element(); },
    createElementNS() { return new Element(); },
    querySelector(selector) { assert(tables.has(selector), 'Unexpected selector: ' + selector); return tables.get(selector); },
    querySelectorAll(selector) {
        if (selector === '.nav-item') return navigation;
        if (selector === '.view-section') return sections;
        if (selector === '#devices-table th.sortable') return headers;
        if (selector === '.job-checkbox:checked' || selector === '.job-checkbox') {
            const checkboxes = tables.get('#jobs-table tbody').children.map(row => row.querySelector('.job-checkbox'));
            return selector.endsWith(':checked') ? checkboxes.filter(box => box.checked) : checkboxes;
        }
        return [];
    }
};
class FixtureFormData {
    constructor(form) {
        this.values = form?.controlsRoot
            ? formControls(form.controlsRoot)
                .filter(control => control.name && !control.disabled && (control.type !== 'checkbox' || control.checked))
                .map(control => [control.name, String(control.value)])
            : Object.entries(form?.formValues || {});
    }
    append(name, value) { this.values.push([name, value]); }
    entries() { return this.values[Symbol.iterator](); }
}
elements.get('config-form').controlsRoot = elements.get('config-fields-container');
const planFields = ['id', 'ratio', 'uploadOverride', 'downloadOverride', 'limitUpload', 'limitDownload', 'burstUpload', 'burstDownload',
    'threshUpload', 'threshDownload', 'timeUpload', 'timeDownload', 'priorityUpload', 'priorityDownload'];
function planFormControls(nodes, htmlSource = markup) {
    const controls = parseControls(htmlSource.match(/<form\b[^>]*id="plan-form"[\s\S]*?<\/form>/)[0]);
    return controls.map(control => {
        const existing = nodes.get(control.id);
        assert(existing, 'The fixture form control must exist in its HTML');
        for (const name of ['tagName', 'type', 'name', 'value', 'min', 'max', 'step', 'required']) {
            if (control[name] !== undefined) existing[name] = control[name];
        }
        return existing;
    });
}
elements.get('plan-form').controlsRoot = {
    children: [], markupControls: planFormControls(elements)
};
const context = vm.createContext({
    URL, FormData: FixtureFormData, document,
    setTimeout: fixtureSetTimeout, clearTimeout: fixtureClearTimeout,
    window: {
        location: { href: 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?page=panel' },
        setTimeout: fixtureSetTimeout, clearTimeout: fixtureClearTimeout
    },
    alert: message => alerts.push(message),
    fetch: async (endpoint, options) => {
        const payload = typeof options.body === 'string' ? JSON.parse(options.body) : null;
        requests.push({ endpoint, options, payload });
        const key = payload ? payload.changeType + '/' + payload.target + '/' + payload.action : 'upload';
        const responder = responders.get(key);
        const result = typeof responder === 'function' ? await responder(payload) : responder || { status: 'ok', error: false, data: [] };
        const status = responseStatuses.get(key) ?? 200;
        return { ok: status >= 200 && status < 300, status, json: async () => JSON.parse(JSON.stringify(result)) };
    }
});
const evaluate = code => vm.runInContext(code, context);
const plain = value => JSON.parse(JSON.stringify(value));
const flush = () => new Promise(resolve => setImmediate(resolve));

function jobsRefreshFixture(storage = new Map(), blockedStorage = false) {
    const nodes = new Map(Array.from(markup.matchAll(/\bid="([^"]+)"/g), match => [match[1], new Element(match[1])]));
    const views = ['devices', 'plans', 'config', 'jobs', 'system'];
    const nav = views.map(view => {
        const node = new Element(); node.dataset.view = view; node.querySelector('span').textContent = view; return node;
    });
    const localTables = new Map(['devices', 'plans', 'jobs', 'orphan'].map(name => ['#' + name + '-table tbody', new Element()]));
    const events = new Map();
    const windowEvents = new Map();
    const clock = new Map();
    const calls = [];
    const jobReplies = [];
    let onReady, now = 0, sequence = 0, updateReply;
    let state = { Q1: { jobId: 'Q1', id: 1, device: 10, state: 'pending', attempts: 0 },
        Q2: { jobId: 'Q2', id: 2, device: 10, state: 'pending', attempts: 0 } };
    const localDocument = {
        baseURI: document.baseURI, body: new Element(), hidden: false,
        getElementById(id) { return nodes.get(id) || null; },
        addEventListener(type, listener) {
            if (type === 'DOMContentLoaded') onReady = listener;
            else { if (!events.has(type)) events.set(type, []); events.get(type).push(listener); }
        },
        removeEventListener(type, listener) { events.set(type, (events.get(type) || []).filter(item => item !== listener)); },
        createElement() { return new Element(); }, createElementNS() { return new Element(); },
        querySelector(selector) { assert(localTables.has(selector)); return localTables.get(selector); },
        querySelectorAll(selector) {
            if (selector === '.nav-item') return nav;
            if (selector === '.view-section') return views.map(view => nodes.get('view-' + view));
            if (selector === '.job-checkbox' || selector === '.job-checkbox:checked') {
                const boxes = localTables.get('#jobs-table tbody').children.map(row => row.querySelector('.job-checkbox'));
                return selector.endsWith(':checked') ? boxes.filter(box => box.checked) : boxes;
            }
            return [];
        }
    };
    const localContext = vm.createContext({
        URL, FormData: FixtureFormData, document: localDocument,
        window: { location: { href: 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?page=panel' },
            addEventListener(type, listener) { if (!windowEvents.has(type)) windowEvents.set(type, []); windowEvents.get(type).push(listener); } },
        localStorage: {
            getItem(key) { if (blockedStorage) throw new Error('blocked'); return storage.get(key) ?? null; },
            setItem(key, value) { if (blockedStorage) throw new Error('blocked'); storage.set(key, value); }
        },
        setTimeout(callback, delay) { const id = ++sequence; clock.set(id, { callback, delay, due: now + delay }); return id; },
        clearTimeout(id) { clock.delete(id); },
        fetch: async (endpoint, options) => {
            const payload = JSON.parse(options.body); calls.push(payload);
            let response = { status: 'ok', error: false, data: [] };
            if (payload.target === 'jobs' && payload.changeType === 'list')
                response = jobReplies.length ? await jobReplies.shift()() : { status: 'ok', error: false, data: state };
            if (payload.target === 'jobs' && payload.changeType === 'update') {
                if (updateReply) await updateReply();
                response = { status: 'ok', error: false, data: {} };
            }
            return { ok: true, status: 200, json: async () => plain(response) };
        }
    });
    vm.runInContext(apiSource, localContext); vm.runInContext(appSource, localContext); onReady();
    return {
        nodes, calls, clock, document: localDocument, state,
        async navigate(view) { await nav[views.indexOf(view)].fire('click'); await flush(); },
        async interval(value) { nodes.get('jobs-refresh-interval').value = value; await nodes.get('jobs-refresh-interval').fire('change'); },
        refresh() { return nodes.get('btn-refresh-jobs').fire('click'); },
        boxes() { return localDocument.querySelectorAll('.job-checkbox'); },
        queueReply(reply) { jobReplies.push(typeof reply === 'function' ? reply : () => reply); },
        setState(value) { state = value; }, setUpdateReply(reply) { updateReply = reply; },
        listCalls() { return calls.filter(call => call.target === 'jobs' && call.changeType === 'list').length; },
        autoTimers() { return [...clock.values()].filter(timer => [5000, 10000, 30000, 60000].includes(timer.delay)); },
        async hidden(value) { localDocument.hidden = value; for (const listener of events.get('visibilitychange') || []) listener(); await flush(); },
        async page(type) { for (const listener of windowEvents.get(type) || []) listener(); await flush(); },
        async tick(milliseconds) {
            const end = now + milliseconds;
            while (true) {
                const next = [...clock].filter(([, timer]) => timer.due <= end).sort((a, b) => a[1].due - b[1].due)[0];
                if (!next) break;
                now = next[1].due; clock.delete(next[0]); next[1].callback(); await flush();
            }
            now = end;
        }
    };
}

async function checkJobsRefresh() {
    const saved = new Map();
    const fixture = jobsRefreshFixture(saved);
    await fixture.navigate('jobs');
    assert.equal(fixture.nodes.get('jobs-refresh-interval').value, '0');
    assert.equal(fixture.autoTimers().length, 0, 'Automatic refresh must be disabled by default');
    assert.equal(fixture.listCalls(), 1);
    fixture.boxes()[0].checked = true;
    fixture.setState({ Q1: { ...fixture.state.Q1, attempts: 3 }, Q3: { ...fixture.state.Q2, jobId: 'Q3' } });
    await fixture.refresh();
    assert.equal(fixture.listCalls(), 2);
    assert.equal(fixture.boxes()[0].checked, true, 'Manual refresh must preserve a selected pending QID');
    assert.equal(fixture.nodes.get('jobs-total').textContent, 'Total: 2');
    assert.equal(fixture.nodes.get('jobs-selected').textContent, 'Seleccionados: 1');
    assert.equal(fixture.nodes.get('jobs-select-all').indeterminate, true);
    assert.match(fixture.nodes.get('jobs-refresh-status').textContent, /Última actualización:/);
    assert(markup.includes('id="jobs-refresh-status" role="status" aria-live="polite"'));
    for (const seconds of ['5', '10', '30', '60']) {
        await fixture.interval(seconds);
        const before = fixture.listCalls();
        assert.equal(fixture.autoTimers().length, 1);
        assert.equal(fixture.autoTimers()[0].delay, Number(seconds) * 1000);
        await fixture.tick(Number(seconds) * 1000 - 1);
        assert.equal(fixture.listCalls(), before);
        await fixture.tick(1);
        assert.equal(fixture.listCalls(), before + 1);
        assert.equal(fixture.autoTimers().length, 1, 'Each completed refresh must schedule exactly one successor');
        assert.equal(fixture.boxes()[0].checked, true);
    }
    assert.equal(saved.get('ros-panel-jobs-refresh-v1'), '60');
    const reloaded = jobsRefreshFixture(saved);
    await reloaded.navigate('jobs');
    assert.equal(reloaded.nodes.get('jobs-refresh-interval').value, '60');
    assert.equal(reloaded.autoTimers()[0].delay, 60000);
    for (const invalid of ['1', '1000', attack, 'false', '{"seconds":5}']) {
        const invalidFixture = jobsRefreshFixture(new Map([['ros-panel-jobs-refresh-v1', invalid]]));
        await invalidFixture.navigate('jobs');
        assert.equal(invalidFixture.nodes.get('jobs-refresh-interval').value, '0');
        assert.equal(invalidFixture.autoTimers().length, 0);
    }
    const blocked = jobsRefreshFixture(new Map(), true);
    await blocked.navigate('jobs'); await blocked.interval('10');
    assert.equal(blocked.autoTimers()[0].delay, 10000, 'Blocked storage must not prevent session-only preferences');
    await fixture.interval('5');
    const inFlight = deferred();
    fixture.queueReply(() => inFlight.promise);
    await fixture.tick(5000);
    const pollingCalls = fixture.listCalls();
    assert.equal(fixture.nodes.get('btn-refresh-jobs').disabled, true);
    assert.equal(fixture.autoTimers().length, 0);
    const overlappingManual = fixture.refresh();
    await fixture.tick(30000);
    assert.equal(fixture.listCalls(), pollingCalls, 'Slow polling/manual overlap must share one GET and never accumulate timers');
    inFlight.resolve({ status: 'ok', error: false, data: { Q3: { ...fixture.state.Q2, jobId: 'Q3' } } });
    await overlappingManual; await flush();
    assert.equal(fixture.nodes.get('jobs-selected').textContent, 'Seleccionados: 0', 'A completed/disappeared QID must leave the selection');
    assert.equal(fixture.nodes.get('jobs-total').textContent, 'Total: 1');
    assert.equal(fixture.autoTimers().length, 1);
    const navigationReply = deferred();
    fixture.queueReply(() => navigationReply.promise);
    const oldRefresh = fixture.refresh(); await flush();
    const rowBeforeNavigation = fixture.boxes()[0].value;
    await fixture.navigate('plans');
    assert.equal(fixture.autoTimers().length, 0);
    const beforeOutside = fixture.listCalls(); await fixture.tick(60000);
    assert.equal(fixture.listCalls(), beforeOutside, 'Leaving Jobs must tear down its timer');
    navigationReply.resolve({ status: 'ok', error: false, data: { Q999: { state: 'pending', attempts: 99 } } });
    await oldRefresh;
    assert.equal(fixture.boxes()[0].value, rowBeforeNavigation, 'An old Jobs response must not replace the table after navigation');
    fixture.setState({ Q8: { ...fixture.state.Q1, jobId: 'Q8' } });
    await fixture.navigate('jobs');
    assert.equal(fixture.boxes()[0].value, 'Q8');
    const returningReply = deferred(); fixture.queueReply(() => returningReply.promise);
    const returningRefresh = fixture.refresh(); await flush();
    await fixture.navigate('plans'); await fixture.navigate('jobs');
    const beforeReturning = fixture.listCalls();
    fixture.setState({ Q9: { ...fixture.state.Q1, jobId: 'Q9' } });
    returningReply.resolve({ status: 'ok', error: false, data: { Q777: { state: 'pending' } } });
    await returningRefresh; await flush();
    assert.equal(fixture.listCalls(), beforeReturning + 1, 'Returning to Jobs during an old GET must start one new request after it settles');
    assert.equal(fixture.boxes()[0].value, 'Q9', 'Re-entering Jobs must reject the previous view generation');
    const hiddenReply = deferred(); fixture.queueReply(() => hiddenReply.promise);
    const hiddenRefresh = fixture.refresh(); await flush();
    await fixture.hidden(true);
    hiddenReply.resolve({ status: 'ok', error: false, data: { Q888: { state: 'pending' } } });
    await hiddenRefresh;
    assert.equal(fixture.boxes()[0].value, 'Q9', 'A GET finishing while the document is hidden must not replace its table');
    fixture.setState({ Q8: { ...fixture.state.Q1, jobId: 'Q8' } });
    await fixture.hidden(false);
    assert.equal(fixture.boxes()[0].value, 'Q8');
    await fixture.hidden(true);
    const beforeHidden = fixture.listCalls(); await fixture.tick(60000);
    assert.equal(fixture.listCalls(), beforeHidden);
    assert.equal(fixture.autoTimers().length, 0);
    await fixture.hidden(false);
    assert.equal(fixture.listCalls(), beforeHidden + 1, 'Returning to a visible Jobs view must refresh before rescheduling');
    assert.equal(fixture.autoTimers().length, 1);
    const oldTime = fixture.nodes.get('jobs-refresh-status').textContent;
    const notificationsBefore = fixture.nodes.get('notifications').children.length;
    fixture.queueReply({ status: 'failed', error: true, message: 'RAM refresh failure' });
    await fixture.tick(5000);
    assert.match(fixture.nodes.get('jobs-refresh-status').textContent, /Falló el refresco/);
    assert(fixture.nodes.get('jobs-refresh-status').textContent.includes(oldTime.replace('Última actualización: ', '').replace(/\.$/, '')));
    assert.equal(fixture.nodes.get('notifications').children.length, notificationsBefore, 'Polling failures must not produce repeated popups');
    assert.equal(fixture.boxes()[0].value, 'Q8', 'A failed refresh must keep the last known table');
    assert.equal(fixture.autoTimers().length, 1, 'A failed poll must remain retryable');
    await fixture.tick(5000);
    assert(!fixture.nodes.get('jobs-refresh-status').textContent.includes('Falló'));
    const mutation = deferred();
    fixture.setUpdateReply(() => mutation.promise);
    fixture.boxes()[0].checked = true;
    const running = fixture.nodes.get('btn-run-jobs').fire('click'); await flush();
    assert.equal(fixture.nodes.get('btn-refresh-jobs').disabled, true);
    assert.equal(fixture.nodes.get('btn-delete-jobs').disabled, true);
    const beforeMutation = fixture.listCalls();
    await fixture.tick(30000); await fixture.refresh();
    assert.equal(fixture.listCalls(), beforeMutation, 'Actions must pause manual and automatic GETs until their result is available');
    mutation.resolve(); await running;
    assert.equal(fixture.listCalls(), beforeMutation + 1, 'The action itself must refresh its updated diagnostics');
    assert.equal(fixture.autoTimers().length, 1);
    fixture.setUpdateReply(null);
    fixture.boxes()[0].checked = true;
    const beforeCancel = fixture.calls.length;
    const cancel = fixture.nodes.get('btn-delete-jobs').fire('click'); await flush();
    assert.equal(fixture.autoTimers().length, 0, 'A deletion confirmation must pause polling');
    const confirmation = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
    assert(confirmation); await confirmation.children[0].children[1].children[0].fire('click'); await cancel;
    assert.equal(fixture.calls.length, beforeCancel, 'Cancelling deletion must not write or refresh');
    assert.equal(fixture.autoTimers().length, 1);
    await fixture.page('pagehide');
    const beforeTeardown = fixture.listCalls(); await fixture.tick(60000);
    assert.equal(fixture.listCalls(), beforeTeardown);
    assert.equal(fixture.autoTimers().length, 0);
    await fixture.page('pageshow');
    assert.equal(fixture.listCalls(), beforeTeardown + 1);
    assert.equal(fixture.autoTimers().length, 1);
    await fixture.interval('0');
    const beforeDisabled = fixture.listCalls(); await fixture.tick(60000);
    assert.equal(fixture.listCalls(), beforeDisabled);
    assert.equal(fixture.autoTimers().length, 0);
    const beforeOffVisibility = fixture.listCalls();
    await fixture.hidden(true); await fixture.hidden(false);
    assert.equal(fixture.listCalls(), beforeOffVisibility, 'Disabled automatic refresh must not fetch on visibility changes without an interrupted request');
    await fixture.interval('1000');
    assert.equal(fixture.nodes.get('jobs-refresh-interval').value, '0');
    assert.equal(fixture.calls.filter(call => call.changeType === 'update').length, 1, 'Refresh controls may only request list/jobs/list');
}

async function checkLegacyPlanEditor({ missingBurstMarkup, missingBurstHelpers }) {
    // Simulate a UISP frame that retains its old HTML while its JavaScript assets update.
    // Missing IDs must return null, as in the browser; never invent the burst nodes.
    const legacyMarkup = (missingBurstMarkup
        ? markup.replace(/<section\b[^>]*class="burst-templates"[\s\S]*?<\/section>/, '')
        : markup).replace(/<div\b[^>]*id="notifications"[^>]*>[\s\S]*?<\/div>/, '')
        .replace(/<p\b[^>]*id="plan-ratio-help"[^>]*>[\s\S]*?<\/p>/, '');
    const legacyElements = new Map(Array.from(legacyMarkup.matchAll(/\bid="([^"]+)"/g), match => [match[1], new Element(match[1])]));
    const legacyBody = new Element();
    const findCreated = (root, id) => root.id === id ? root : root.children.map(child => findCreated(child, id)).find(Boolean);
    const legacyViews = ['devices', 'plans', 'config', 'jobs', 'system'];
    const legacyNav = legacyViews.map(view => {
        const nav = new Element(); nav.dataset.view = view; nav.querySelector('span').textContent = view; return nav;
    });
    const legacyTables = new Map(['devices', 'plans', 'jobs', 'orphan'].map(name => ['#' + name + '-table tbody', new Element()]));
    const legacyHeaders = ['id', 'name', 'ip', 'users', 'status'].map(sort => {
        const header = new Element(); header.dataset.sort = sort; return header;
    });
    // Associate only controls actually present in the HTML with the manual plan form.
    const legacyPlanControls = planFormControls(legacyElements, legacyMarkup);
    legacyElements.get('plan-form').controlsRoot = { children: [], markupControls: legacyPlanControls };
    let legacyReady;
    const legacyDocument = {
        baseURI: document.baseURI, body: legacyBody,
        getElementById(id) { return legacyElements.get(id) || findCreated(legacyBody, id) || null; },
        addEventListener(type, listener) { if (type === 'DOMContentLoaded') legacyReady = listener; },
        removeEventListener() {}, createElement() { return new Element(); }, createElementNS() { return new Element(); },
        querySelector(selector) { assert(legacyTables.has(selector)); return legacyTables.get(selector); },
        querySelectorAll(selector) {
            if (selector === '.nav-item') return legacyNav;
            if (selector === '.view-section') return legacyViews.map(view => legacyElements.get('view-' + view));
            if (selector === '#devices-table th.sortable') return legacyHeaders;
            return [];
        }
    };
    const legacyRequests = [];
    const legacyPlan = { id: 7, name: 'Legacy plan', ratio: 2, uploadSpeed: 2, downloadSpeed: 8,
        uploadOverride: 3.75, downloadOverride: null, burstUpload: 37.5, burstDownload: 80,
        threshUpload: 22.5, threshDownload: 18.5, timeUpload: 9.5, timeDownload: 14.25,
        limitUpload: 100, limitDownload: 100, priorityUpload: 3, priorityDownload: 7 };
    const legacyContext = vm.createContext({
        URL, FormData: FixtureFormData, document: legacyDocument,
        window: { location: { href: 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?page=panel' } },
        setTimeout() { return 1; }, clearTimeout() {}, alert() { throw new Error('Legacy editor must use visible notifications'); },
        fetch: async (endpoint, options) => {
            const payload = JSON.parse(options.body);
            legacyRequests.push({ endpoint, payload });
            let data = [];
            if (payload.changeType === 'list' && payload.target === 'plans') data = [legacyPlan];
            if (payload.changeType === 'list' && payload.target === 'config') data = { disable_contention: false };
            if (payload.changeType === 'update' && payload.target === 'plans') Object.assign(legacyPlan, payload.data);
            return { ok: true, status: 200, json: async () => ({ status: 'ok', error: false, data: plain(data) }) };
        }
    });
    vm.runInContext(apiSource, legacyContext);
    if (missingBurstHelpers) vm.runInContext('delete PanelUtils.burstPresets; delete PanelUtils.burstPresetValues; delete PanelUtils.burstPresetMatch; delete PanelUtils.burstPreview;', legacyContext);
    vm.runInContext(appSource, legacyContext);
    assert.doesNotThrow(() => legacyReady(), 'Missing optional burst nodes/helpers must not stop panel initialization');
    await flush();
    await legacyNav[1].fire('click');
    await flush();
    const editButton = legacyTables.get('#plans-table tbody').children[0].querySelector('.row-actions').children[0];
    const legacyLoaderWrites = legacyElements.get('loader').styleWrites.length;
    const legacyModalWrites = legacyElements.get('plan-modal').styleWrites.length;
    const legacyRequestsBeforeEdit = legacyRequests.length;
    for (let repeat = 0; repeat < 2; repeat++) await editButton.fire('click');
    assert.deepEqual(displayWrites(legacyElements.get('loader'), legacyLoaderWrites), [], 'Opening an old manual editor must not flash a loading overlay');
    assert.deepEqual(displayWrites(legacyElements.get('plan-modal'), legacyModalWrites), ['flex', 'flex'], 'The legacy editor must never hide while opening');
    assert.equal(legacyRequests.length, legacyRequestsBeforeEdit, 'Opening an old manual editor must use its cached plan without network requests');
    assert.equal(legacyElements.get('plan-modal').style.display, 'flex', 'The pencil must open the manual editor with an old HTML/API asset');
    for (const [field, value] of Object.entries({ uploadOverride: 3.75, burstUpload: 37.5, burstDownload: 80,
        threshUpload: 22.5, timeUpload: 9.5, timeDownload: 14.25 })) {
        assert.equal(Number(legacyElements.get('plan-' + field).value), value, 'Opening the legacy editor changed ' + field);
    }
    assert.equal(legacyRequests.filter(request => request.payload.changeType === 'update').length, 0, 'Opening the manual editor must not save or apply a template');
    const region = legacyDocument.getElementById('notifications');
    assert(region && region.parentNode === legacyBody, 'An old frame must receive a visible notification region');
    assert(region.children.some(toast => toast.role === 'status' && /Recarga.*plantillas/i.test(toast.children[0].textContent)),
        'Missing burst support must display the update notice');
    if (missingBurstMarkup) {
        for (const id of ['plan-preset-none', 'plan-preset-balanced', 'plan-burst-preset-status', 'plan-burst-preview-upload']) {
            assert.equal(legacyDocument.getElementById(id), null, 'The fixture must not invent optional burst elements');
        }
    }
    legacyElements.get('plan-uploadOverride').value = '';
    legacyElements.get('plan-downloadOverride').value = '4.25';
    legacyElements.get('plan-burstUpload').value = '45.5';
    legacyElements.get('plan-timeUpload').value = '11.25';
    await legacyElements.get('save-plan-btn').fire('click');
    const saved = legacyRequests.filter(request => request.payload.changeType === 'update' && request.payload.target === 'plans');
    assert.equal(saved.length, 1, 'The manual Save button must remain functional');
    assert.equal(saved[0].payload.action, 'edit');
    assert.equal(saved[0].payload.data.id, 7);
    assert.equal(saved[0].payload.data.uploadOverride, null);
    assert.equal(saved[0].payload.data.downloadOverride, 4.25);
    assert.equal(saved[0].payload.data.burstUpload, 45.5);
    assert.equal(saved[0].payload.data.threshUpload, 22.5);
    assert.equal(saved[0].payload.data.timeUpload, 11.25);
    assert.equal(saved[0].payload.data.burstDownload, 80);
    assert.equal(legacyElements.get('plan-modal').style.display, 'none');
    assert.equal(legacyElements.get('loader').style.display, 'none');
    assert.equal(editButton.disabled, false);
    assert.equal(legacyElements.get('save-plan-btn').disabled, false);
}

function contentionFixture(disableContention, translated = false) {
    const nodes = new Map(Array.from(markup.matchAll(/\bid="([^"]+)"/g), match => [match[1], new Element(match[1])]));
    nodes.get('tab-general').classes.add('active');
    nodes.get('plan-form').controlsRoot = { children: [], markupControls: planFormControls(nodes) };
    nodes.get('config-form').controlsRoot = nodes.get('config-fields-container');
    const views = ['devices', 'plans', 'config', 'jobs', 'system'];
    const nav = views.map(view => {
        const element = new Element(); element.dataset.view = view; element.querySelector('span').textContent = view; return element;
    });
    const localTables = new Map(['devices', 'plans', 'jobs', 'orphan'].map(name => ['#' + name + '-table tbody', new Element()]));
    const closeButtons = Array.from(markup.matchAll(/<button\b[^>]*class="[^"]*\bclose-modal\b[^"]*"/g), () => new Element());
    const config = { disable_contention: disableContention };
    // Real installations can retain zero from older versions. It must survive a
    // burst-only edit when global contention is disabled.
    const storedPlan = { id: 19, name: 'Legacy zero ratio', ratio: 0, uploadSpeed: 5, downloadSpeed: 20,
        burstUpload: -100, burstDownload: -100, threshUpload: 100, threshDownload: 100,
        timeUpload: 0, timeDownload: 0, limitUpload: 100, limitDownload: 100, priorityUpload: 8, priorityDownload: 8 };
    const localRequests = [];
    let configResponse;
    let saveDelay;
    const fieldsDelays = new Map();
    let onReady;
    const localDocument = {
        baseURI: document.baseURI, body: new Element(),
        getElementById(id) { return nodes.get(id) || null; },
        addEventListener(type, listener) { if (type === 'DOMContentLoaded') onReady = listener; },
        removeEventListener() {}, createElement() { return new Element(); }, createElementNS() { return new Element(); },
        querySelector(selector) { assert(localTables.has(selector)); return localTables.get(selector); },
        querySelectorAll(selector) {
            if (selector === '.nav-item') return nav;
            if (selector === '.view-section') return views.map(view => nodes.get('view-' + view));
            if (selector === '.close-modal') return closeButtons;
            if (translated) {
                const walk = node => [node, ...node.children.flatMap(walk)];
                const all = [...nodes.values()].flatMap(walk);
                if (selector === '.config-field[data-field-name]') return all.filter(node => node.dataset.fieldName);
                const attribute = selector.match(/^\[(data-i18n(?:-(?:placeholder|title|aria-label))?)\]$/)?.[1];
                if (attribute) return all.filter(node => node.getAttribute(attribute));
                if (selector === '[data-date]') return all.filter(node => node.dataset.date !== undefined);
            }
            return [];
        }
    };
    const localContext = vm.createContext({
        URL, FormData: FixtureFormData, document: localDocument,
        window: { location: { href: 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?page=panel' } },
        setTimeout() { return 1; }, clearTimeout() {}, alert() { throw new Error('The contention editor must use nonblocking feedback'); },
        fetch: async (endpoint, options) => {
            const payload = JSON.parse(options.body);
            localRequests.push({ endpoint, payload });
            let response = { status: 'ok', error: false, data: [] };
            if (payload.changeType === 'list' && payload.target === 'plans') response.data = [storedPlan];
            if (payload.changeType === 'list' && payload.target === 'config') response = configResponse || { status: 'ok', error: false, data: config };
            if (payload.changeType === 'list' && payload.target === 'messages') {
                const language = payload.data.language;
                if (fieldsDelays.has(language)) await fieldsDelays.get(language);
                response.data = [{}, {
                    config_disable_contention: { name: 'disable_contention', label: language === 'en' ? 'Disable contention' : language === 'de' ? 'Überbuchung deaktivieren' : 'Desactivar contención', description: language === 'en' ? 'Do not apply contention to plans' : 'No aplicar contención a los planes', type: 1 }
                }];
            }
            if (payload.changeType === 'update' && payload.target === 'plans') {
                Object.assign(storedPlan, payload.data);
                if (Array.isArray(storedPlan.numericIssues)) storedPlan.numericIssues = storedPlan.numericIssues.filter(issue => !Object.hasOwn(payload.data, issue.field));
            }
            if (payload.changeType === 'update' && payload.target === 'config') { if (saveDelay) await saveDelay; Object.assign(config, payload.data); }
            return { ok: true, status: 200, json: async () => plain(response) };
        }
    });
    if (translated) {
        localContext.window.document = localDocument;
        localContext.window.localStorage = { getItem() { return null; }, setItem() {} };
        vm.runInContext(i18nSource, localContext);
        localContext.PanelI18n = localContext.window.PanelI18n;
    }
    vm.runInContext(apiSource, localContext);
    vm.runInContext(appSource, localContext);
    onReady();
    return {
        nodes, config, storedPlan, requests: localRequests, tables: localTables,
        async navigate(view) { await nav[views.indexOf(view)].fire('click'); await flush(); },
        editButton() { return localTables.get('#plans-table tbody').children[0]?.querySelector('.row-actions').children[0]; },
        async closeEditor() { await closeButtons[0].fire('click'); },
        setConfigResponse(response) { configResponse = response; },
        i18n: localContext.PanelI18n,
        delayFields(language, promise) { fieldsDelays.set(language, promise); },
        delaySave(promise) { saveDelay = promise; },
        planUpdates() { return localRequests.filter(request => request.payload.changeType === 'update' && request.payload.target === 'plans'); },
        contentionSwitch() {
            const control = formControls(nodes.get('config-fields-container')).find(control => control.name === 'disable_contention' && control.type === 'checkbox');
            assert(control, 'The global contention switch must be rendered');
            return control;
        }
    };
}

function recoveryPanelFixture({ bootstrap, details } = {}) {
    const nodes = new Map(Array.from(markup.matchAll(/\bid="([^"]+)"/g), match => [match[1], new Element(match[1])]));
    const views = ['devices', 'plans', 'config', 'jobs', 'system', 'network_conflicts'];
    const nav = views.map(view => {
        const element = new Element(); element.dataset.view = view; element.querySelector('span').textContent = view; return element;
    });
    const localTables = new Map(['devices', 'plans', 'jobs', 'orphan'].map(name => ['#' + name + '-table tbody', new Element()]));
    const deviceControls = ['name', 'ip', 'user', 'password', 'port', 'pool', 'pool6', 'pfxLength', 'ssl', 'allowSelfSigned'].map(name => nodes.get('dev-' + name));
    nodes.get('plan-form').controlsRoot = { children: [], markupControls: planFormControls(nodes) };
    nodes.get('config-form').controlsRoot = nodes.get('config-fields-container');
    nodes.get('tab-general').classes.add('active');
    const script = new Element(); script.textContent = typeof bootstrap === 'string' ? bootstrap : JSON.stringify(bootstrap);
    const walk = node => [node, ...node.children.flatMap(walk)];
    const allNodes = () => [...nodes.values(), ...localTables.values()].flatMap(walk);
    const calls = [];
    const replies = new Map();
    let onReady, reloads = 0;
    const localDocument = {
        baseURI: document.baseURI, body: new Element(), hidden: false,
        getElementById(id) { return nodes.get(id) || null; },
        addEventListener(type, listener) { if (type === 'DOMContentLoaded') onReady = listener; },
        removeEventListener() {},
        createElement(tag) { const element = new Element(); element.tagName = tag.toUpperCase(); return element; },
        createElementNS() { return new Element(); },
        querySelector(selector) { assert(localTables.has(selector)); return localTables.get(selector); },
        querySelectorAll(selector) {
            if (selector === '#network-recovery-state') return bootstrap === undefined ? [] : [script];
            if (selector === '.nav-item') return nav;
            if (selector === '.view-section') return views.map(view => nodes.get('view-' + view));
            if (selector === '[data-recovery-write]') return allNodes().filter(node => node.dataset.recoveryWrite === 'true');
            if (selector === '[data-recovery-router-read]') return allNodes().filter(node => node.dataset.recoveryRouterRead === 'true');
            if (selector === '#device-form input, #device-form select, #plan-form input, #plan-form select, #config-form input, #config-form select, .burst-preset-btn')
                return [...deviceControls, ...nodes.get('plan-form').controlsRoot.markupControls, ...formControls(nodes.get('config-fields-container'))];
            return [];
        }
    };
    const localContext = vm.createContext({
        URL, FormData: FixtureFormData, document: localDocument,
        window: { location: { href: 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?page=panel', reload() { reloads++; } } },
        setTimeout() { return 1; }, clearTimeout() {},
        fetch: async (endpoint, options) => {
            const payload = typeof options.body === 'string' ? JSON.parse(options.body) : { changeType: 'upload' };
            calls.push(payload);
            const key = payload.changeType + '/' + payload.target;
            let response;
            if (replies.has(key)) response = await replies.get(key)(payload);
            else {
                const data = {
                    devices: [{ id: 1, name: 'Router de prueba', ip: '192.0.2.1', status: null }],
                    plans: [{ id: 1, name: 'Plan de prueba', ratio: 1, uploadSpeed: 5, downloadSpeed: 20 }],
                    config: { disable_contention: false }, jobs: {},
                    messages: [{}, { config_disable_contention: { name: 'disable_contention', label: 'Desactivar contención', type: 1 } }],
                    sys: { app_version: '2.4.21', php_version: '8.5' }, backups: [{ name: 'backup-0', date: '2026-10-14' }],
                    network_conflicts: details
                }[payload.target] ?? [];
                response = { status: 'ok', error: false, data, ...(payload.target === 'network_conflicts' ? { recovery: { readOnly: true } } : {}) };
            }
            return { ok: true, status: 200, json: async () => plain(response) };
        }
    });
    vm.runInContext(apiSource, localContext); vm.runInContext(appSource, localContext); onReady();
    return { nodes, calls, document: localDocument, tables: localTables, deviceControls, api: vm.runInContext('api', localContext),
        async navigate(view) { await nav[views.indexOf(view)].fire('click'); await flush(); },
        reply(key, response) { replies.set(key, typeof response === 'function' ? response : () => response); },
        reloads() { return reloads; },
        writes() { return calls.filter(call => call.changeType === 'update' || call.changeType === 'upload'); },
        diagnostics() { return calls.filter(call => call.target === 'network_conflicts'); }
    };
}

async function checkNetworkRecoveryPanel() {
    const details = { duplicate_groups: 3, conflicts_truncated: true, conflicts: [
        { family: 'IPv4', address: attack, service_ids: [5347, 11506], service_count: 4, services_truncated: true, owners: [
            { service_id: 5347, client_id: 88, client_name: attack, service_status: 'Terminado', client_archived: true,
                source: 'database', service_url: '/crm/client/service/5347', client_url: '/crm/client/88' },
            { service_id: 11506, client_id: 1057, client_name: 'Cliente de prueba', service_status: attack, source: 'cache',
                service_url: 'javascript:alert(1)', client_url: 'https://other.invalid/crm/client/1057' }
        ] },
        { family: 'IPv6', address: '2001:db8::1', service_ids: [9], service_count: 2, owners: [
            { service_id: 9, client_id: null, client_name: null, service_status: 'Sin verificar', source: 'missing', client_source: 'crm', service_url: '/crm/client/service/9' }
        ] }
    ] };
    const fixture = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details });
    await flush();
    assert.equal(fixture.nodes.get('network-warning').style.display, 'flex');
    assert.equal(fixture.nodes.get('network-conflicts-nav').style.display, 'flex');
    assert.equal(fixture.nodes.get('network-conflicts-count').textContent, '3');
    assert.equal(fixture.nodes.get('network-warning-title').textContent, 'IP duplicadas: 3 conflictos');
    assert.equal(fixture.diagnostics().length, 0, 'Bootstrap diagnostics must not trigger UISP reads or diagnostic requests');
    assert(fixture.tables.get('#devices-table tbody').children[0].innerHTML.includes('Sin comprobar'), 'Unprobed routers must not be reported disconnected');
    const deviceActions = fixture.tables.get('#devices-table tbody').children[0].querySelector('.row-actions').children;
    assert.deepEqual(deviceActions.map(button => button.disabled), [true, false, true, true, true], 'Local device inspection remains available while router queries and mutations are disabled');
    assert.equal(deviceActions[0].title, 'Las consultas al router estarán disponibles al completar la actualización.');
    await deviceActions[0].fire('click');
    const blockedRouterQuery = await fixture.api.send('list', 'orphans', 'get', { id: 1 });
    assert.equal(blockedRouterQuery.error, true);
    assert.equal(blockedRouterQuery.message, 'Las consultas al router estarán disponibles al completar la actualización.');
    assert.equal(fixture.calls.filter(call => call.target === 'orphans').length, 0, 'Recovery must block orphan UI/programmatic queries before any HTTP request');
    await deviceActions[1].fire('click');
    assert.equal(fixture.nodes.get('device-modal').style.display, 'flex');
    assert(fixture.deviceControls.every(control => control.disabled));
    for (const view of ['plans', 'config', 'jobs', 'system', 'devices', 'network_conflicts']) {
        await fixture.navigate(view);
        assert.equal(fixture.nodes.get('view-' + view).style.display, 'block', 'Recovery must keep every normal panel section consultable: ' + view);
    }
    assert.equal(fixture.diagnostics().length, 0, 'Opening diagnostics and normal navigation must not automatically verify services');
    for (const id of ['save-config-btn', 'sync-attrs-btn', 'save-device-btn', 'save-plan-btn', 'btn-run-jobs', 'btn-delete-jobs', 'btn-backup', 'btn-rebuild', 'btn-cache', 'btn-upload', 'btn-log-clear']) {
        assert.equal(fixture.nodes.get(id).disabled, true, 'Recovery mutation button enabled: ' + id);
        await fixture.nodes.get(id).fire('click');
    }
    const blockedUpdate = await fixture.api.send('update', 'devices', 'delete', { id: 1 });
    const blockedUpload = await fixture.api.uploadBackup({ name: 'fixture.db' });
    assert.equal(blockedUpdate.error, true); assert.equal(blockedUpload.error, true);
    assert.match(blockedUpdate.message, /Puedes seguir consultando/);
    assert.equal(fixture.writes().length, 0, 'Disabled handlers and direct API calls must never send recovery mutations');
    const body = fixture.nodes.get('network-conflicts-body');
    assert.equal(body.children.length, 2);
    assert(!renderedMarkup(body).includes('<img') && !renderedMarkup(body).includes('<script'), 'IP, owner names and statuses must stay escaped or literal text');
    const owners = body.children[0].children[1].children;
    const identity = owners[0].children[0];
    assert.equal(identity.children[0].textContent, attack);
    assert.equal(identity.children[0].href, 'https://fixture.invalid/crm/client/88');
    assert.equal(identity.children[0].target, '_blank');
    assert.equal(identity.children[0].rel, 'noopener noreferrer');
    assert.equal(identity.children[1].children[0].href, 'https://fixture.invalid/crm/client/service/5347');
    assert.deepEqual(identity.children[1].children.slice(1).map(node => node.textContent), ['Terminado (dato guardado)', 'Cliente archivado (dato guardado)']);
    assert.equal(identity.children[2].textContent, 'Base del plugin', 'Saved service status must identify its source');
    assert.equal(owners[1].children[0].children[0].tagName, 'SPAN', 'Cross-origin client links must never be clickable');
    assert.equal(owners[1].children[0].children[1].children[0].tagName, 'SPAN', 'Executable service URLs must never be clickable');
    assert.equal(owners[1].children[0].children[1].children[1].textContent, attack + ' (dato guardado)');
    const missing = body.children[1].children[1].children[0].children[0];
    assert.equal(missing.children[0].textContent, 'Cliente sin identificar');
    assert.equal(missing.children[1].children[1].textContent, 'Sin comprobar');
    assert.equal(missing.children[2].textContent, 'Registro de IP sin ficha local de servicio');
    assert.equal(missing.children[3].textContent, 'Cliente verificado en UISP', 'Fresh client facts must not imply a fresh service status');
    assert.equal(owners[2].textContent, 'Se muestran las primeras 2 de 4 asignaciones guardadas.');
    assert.match(fixture.nodes.get('network-conflicts-limit').textContent, /2 de 3 conflictos.*muestra acotada/);
    assert.equal(fixture.nodes.get('download-network-conflicts').href, 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?download=network-conflicts');
    const waiting = deferred();
    fixture.reply('list/network_conflicts', () => waiting.promise);
    const verification = owners[0].children[1].fire('click'); await flush();
    assert.equal(fixture.diagnostics().length, 1);
    assert.deepEqual(plain(fixture.diagnostics()[0].data), { refresh: true, serviceIds: [5347] }, 'A verify click must request only that service');
    assert.equal(fixture.nodes.get('loader').style.display, 'none', 'CRM verification must leave the rest of the panel accessible');
    assert.equal(body.children[0].children[1].children[0].children[1].disabled, true);
    assert.equal(body.children[0].children[1].children[0].children[1].textContent, 'Verificando...');
    await body.children[0].children[1].children[1].children[1].fire('click');
    assert.equal(fixture.diagnostics().length, 1, 'Repeated clicks must not stack CRM requests');
    await fixture.navigate('system');
    assert.equal(fixture.nodes.get('view-system').style.display, 'block', 'Navigation must work during explicit CRM verification');
    const refreshed = plain(details);
    Object.assign(refreshed.conflicts[0].owners[0], { service_status: 'Activo', source: 'crm', client_source: 'crm', client_archived: false, client_name: 'Nombre verificado' });
    waiting.resolve({ status: 'ok', error: false, data: refreshed, recovery: { readOnly: true } });
    await verification;
    await fixture.navigate('network_conflicts');
    assert.equal(body.children[0].children[1].children[0].children[0].children[1].children[1].textContent, 'Activo');
    assert.equal(body.children[0].children[1].children[0].children[0].children[2].textContent, 'Servicio verificado en UISP');
    function ownerItem(target, id) {
        for (const group of target.nodes.get('network-conflicts-body').children) {
            for (const item of group.children[1].children) {
                if (item.children[0]?.children[1]?.children[0]?.textContent === 'Servicio #' + id) return item;
            }
        }
        return null;
    }
    const sourceTexts = item => item.children[0].children.filter(node => node.className === 'conflict-source').map(node => node.textContent);
    async function verifyOwner(target, id, incoming) {
        target.reply('list/network_conflicts', { status: 'ok', error: false, data: incoming, recovery: { readOnly: true } });
        await target.navigate('network_conflicts');
        await ownerItem(target, id).children[1].fire('click');
        await flush();
    }
    const verifiedSecond = plain(details);
    Object.assign(verifiedSecond.conflicts[0].owners[1], { service_status: 'Suspendido', source: 'crm', client_source: 'crm', client_name: 'Segundo verificado' });
    await verifyOwner(fixture, 11506, verifiedSecond);
    assert.equal(ownerItem(fixture, 5347).children[0].children[0].textContent, 'Nombre verificado', 'Verifying another owner must preserve the first live client name');
    assert.equal(ownerItem(fixture, 5347).children[0].children[1].children[1].textContent, 'Activo', 'Verifying another owner must preserve the first live service status');
    assert.deepEqual(sourceTexts(ownerItem(fixture, 5347)), ['Servicio verificado en UISP', 'Cliente verificado en UISP']);
    assert.equal(ownerItem(fixture, 11506).children[0].children[1].children[1].textContent, 'Suspendido');
    await fixture.navigate('system'); await fixture.navigate('network_conflicts');
    assert.equal(ownerItem(fixture, 5347).children[0].children[1].children[1].textContent, 'Activo', 'Normal recovery list responses must preserve earlier CRM verification memory');
    const failedService = plain(details);
    Object.assign(failedService.conflicts[0].owners[0], { refresh_error: 'unavailable', client_source: 'cache' });
    await verifyOwner(fixture, 5347, failedService);
    assert.deepEqual(sourceTexts(ownerItem(fixture, 5347)), ['Base del plugin'], 'A selected failed check must not reuse its prior successful verification');
    assert.equal(ownerItem(fixture, 5347).children[0].children[1].children[1].textContent, 'Terminado (dato guardado)');
    assert.match(ownerItem(fixture, 5347).children[0].lastElementChild.textContent, /UISP no devolvió este servicio/);
    assert.equal(ownerItem(fixture, 11506).children[0].children[1].children[1].textContent, 'Suspendido', 'Failure of a different selected service must preserve unrelated verified facts');
    const failedClient = plain(details);
    Object.assign(failedClient.conflicts[0].owners[0], { service_status: 'Activo', source: 'crm', client_source: 'cache', refresh_error: 'unavailable' });
    await verifyOwner(fixture, 5347, failedClient);
    assert.equal(ownerItem(fixture, 5347).children[0].children[0].textContent, attack, 'A client read failure retains its saved name, not an earlier live name');
    assert.deepEqual(sourceTexts(ownerItem(fixture, 5347)), ['Servicio verificado en UISP', 'Cliente: datos guardados']);
    assert.equal(ownerItem(fixture, 5347).children[0].children[1].children[2].textContent, 'Cliente archivado (dato guardado)');
    assert.equal(ownerItem(fixture, 5347).children[0].lastElementChild.textContent, 'No se pudo verificar el cliente en UISP. Se conservan los datos guardados.', 'A client-only failure must never report that a successfully fetched service was unavailable');
    await verifyOwner(fixture, 11506, verifiedSecond);
    await fixture.navigate('system'); await fixture.navigate('network_conflicts');
    assert.equal(ownerItem(fixture, 5347).children[0].lastElementChild.textContent, 'No se pudo verificar el cliente en UISP. Se conservan los datos guardados.', 'An unchanged owner verification failure must survive other checks and navigation');
    const reassigned = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details }); await flush();
    await verifyOwner(reassigned, 5347, refreshed);
    const changedIdentity = plain(verifiedSecond);
    Object.assign(changedIdentity.conflicts[0].owners[0], { client_id: 99, client_name: 'Cliente reasignado', client_archived: false });
    await verifyOwner(reassigned, 11506, changedIdentity);
    assert.equal(ownerItem(reassigned, 5347).children[0].children[0].textContent, 'Cliente reasignado');
    assert.deepEqual(sourceTexts(ownerItem(reassigned, 5347)), ['Base del plugin'], 'Verified facts must not follow a service into a different client identity');
    const verifiedChanged = plain(changedIdentity);
    Object.assign(verifiedChanged.conflicts[0].owners[0], { service_status: 'Activo', source: 'crm', client_source: 'crm', client_name: 'Reasignado verificado' });
    await verifyOwner(reassigned, 5347, verifiedChanged);
    const changedGroup = plain(changedIdentity);
    changedGroup.conflicts[0].address = '192.0.2.199';
    await verifyOwner(reassigned, 11506, changedGroup);
    assert.equal(ownerItem(reassigned, 5347).children[0].children[0].textContent, 'Cliente reasignado');
    assert.deepEqual(sourceTexts(ownerItem(reassigned, 5347)), ['Base del plugin'], 'Verified facts must not be reused across different IP assignments');
    const removedGroup = { duplicate_groups: 1, conflicts_truncated: false, conflicts: [plain(details.conflicts[1])] };
    await verifyOwner(reassigned, 11506, removedGroup);
    assert.equal(reassigned.nodes.get('network-conflicts-body').children.length, 1, 'Server group removals must never be resurrected from verification memory');
    assert.equal(reassigned.nodes.get('network-conflicts-count').textContent, '1', 'Conflict totals must always follow the fresh server response');
    assert.equal(ownerItem(reassigned, 5347), null);
    await fixture.nodes.get('btn-recheck-conflicts').fire('click'); assert.equal(fixture.reloads(), 1);
    const healthy = recoveryPanelFixture({ details }); await flush();
    assert.equal(healthy.diagnostics().length, 0, 'Healthy boots must not call the new diagnostics API');
    assert.equal(healthy.nodes.get('btn-cache').disabled, false);
    healthy.api.setRecovery({ readOnly: true }); await flush();
    assert.equal(healthy.nodes.get('btn-cache').disabled, true, 'A mid-session conflict must immediately pause mutations');
    assert.equal(healthy.diagnostics().length, 1, 'A mid-session conflict without details needs exactly one local diagnostic read');
    assert.deepEqual(plain(healthy.diagnostics()[0].data), {}, 'Reactive recovery reads must not request live CRM verification');
    const malformed = recoveryPanelFixture({ bootstrap: '{', details }); await flush();
    assert.equal(malformed.nodes.get('btn-cache').disabled, false, 'An invalid optional bootstrap must not break normal navigation');
    assert.equal((markup.match(/(?:src|href)="(?:js|css)\/[^"?]+\?v=2\.0\.37"/g) || []).length, 5, 'Every panel asset must use the new revision to invalidate UISP browser caches');
    assert(!markup.includes('v=2.0.36'));
    assert(apiSource.includes("'alert-triangle':"), 'The conflict warning must have its own local warning icon');
}

async function checkNetworkCleanupPanel() {
    const details = { duplicate_groups: 1, conflicts_truncated: false, conflicts: [
        { family: 'IPv4', address: '192.0.2.77', service_ids: [42, 43], service_count: 2, owners: [
            { service_id: 42, client_id: 17, client_name: 'Cliente de ejemplo A', service_status: 'Activo', source: 'cache', client_source: 'cache' },
            { service_id: 43, client_id: 18, client_name: 'Cliente de ejemplo B', service_status: null, source: 'missing', client_source: 'missing' }
        ] }
    ] };
    const fixture = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details });
    await fixture.navigate('network_conflicts');
    const owner = id => fixture.nodes.get('network-conflicts-body').children[0].children[1].children.find(item => item.children[0]?.children[1]?.children[0]?.textContent === 'Servicio #' + id);
    assert.match(fixture.nodes.get('network-conflicts-body').children[0].children[0].innerHTML, /2 asignaciones guardadas/);
    assert.equal(owner(42).children[0].children[1].children[1].textContent, 'Activo (dato guardado)');
    assert.equal(owner(43).children[0].children[2].textContent, 'Registro de IP sin ficha local de servicio');
    const token = 'a'.repeat(64);
    const preview = { previewToken: token, expiresAt: '2099-10-14T13:00:00Z', candidates: [
        { service_id: 42, client_id: 17, address: '192.0.2.77', address6: '2001:db8::42', reason: 'crm_not_found', witness_service_ids: [43] }
    ], blocked: [{ service_id: 43, reason: 'service_exists' }] };
    fixture.reply('cleanup/network_conflicts', previewPayload => ({ status: 'ok', error: false, data: preview, recovery: { readOnly: true } }));
    await owner(42).children[2].fire('click');
    assert.equal(fixture.nodes.get('network-cleanup-modal').style.display, 'flex');
    assert.deepEqual(plain(fixture.calls.at(-1)), { changeType: 'cleanup', target: 'network_conflicts', action: 'preview', data: { serviceIds: [42] } });
    assert.equal(fixture.nodes.get('network-conflicts-count').textContent, '1', 'Preview payloads must not replace conflict diagnostics with token/candidate data');
    const candidate = fixture.nodes.get('network-cleanup-candidates').children[0];
    assert(candidate.children[1].innerHTML.includes('192.0.2.77') && candidate.children[1].innerHTML.includes('2001:db8::42'), 'Cleanup review must show every local IP assignment being removed');
    const peer = candidate.children[1].children[1].children[0];
    assert.equal(peer.textContent, 'Servicio conservado #43');
    assert.equal(peer.href, 'https://fixture.invalid/crm/client/service/43');
    assert.equal(peer.rel, 'noopener noreferrer');
    assert(fixture.nodes.get('network-cleanup-blocked').children[0].innerHTML.includes('cualquiera que sea su estado'), 'Existing UISP services must be protected irrespective of status');
    assert.equal(fixture.nodes.get('apply-network-cleanup').disabled, true, 'Cleanup candidates must require explicit checkbox selection');
    await fixture.nodes.get('apply-network-cleanup').fire('click');
    assert.equal(fixture.calls.filter(call => call.changeType === 'cleanup').length, 1, 'Review and an empty selection must never apply cleanup');
    candidate.children[0].checked = true; await candidate.children[0].fire('change');
    assert.equal(fixture.nodes.get('apply-network-cleanup').disabled, false);
    const cancelled = fixture.nodes.get('apply-network-cleanup').fire('click'); await flush();
    let confirmation = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
    assert(confirmation);
    assert.match(confirmation.children[0].children[0].textContent, /asignaciones locales de 1 servicios.*respaldo.*UISP y los routers no se modificarán/);
    await confirmation.children[0].children[1].children[0].fire('click'); await cancelled;
    assert.equal(fixture.calls.filter(call => call.changeType === 'cleanup').length, 1, 'Cancelling the final confirmation must send no apply request');
    const forbidden = await fixture.api.send('cleanup', 'system', 'apply', { previewToken: token, serviceIds: [42] });
    const unsupported = await fixture.api.send('cleanup', 'network_conflicts', 'delete', { serviceIds: [42] });
    assert.equal(forbidden.error, true); assert.equal(unsupported.error, true);
    assert.equal(fixture.calls.filter(call => call.changeType === 'cleanup').length, 1, 'Recovery bypass must only permit the exact preview/apply cleanup envelopes');
    const backupName = 'network-cleanup-20261014T130000Z-' + 'a'.repeat(32) + '.sqlite';
    fixture.reply('cleanup/network_conflicts', payload => ({ status: 'ok', error: false, data: {
        removed_service_ids: [42], backup_name: backupName, duplicate_groups: 0, requires_reload: true
    }, recovery: { readOnly: true } }));
    const readsBeforeApply = fixture.calls.filter(call => call.changeType === 'list').length;
    const applying = fixture.nodes.get('apply-network-cleanup').fire('click'); await flush();
    confirmation = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
    await confirmation.children[0].children[1].children[1].fire('click'); await applying;
    assert.deepEqual(plain(fixture.calls.at(-1)), { changeType: 'cleanup', target: 'network_conflicts', action: 'apply', data: { previewToken: token, serviceIds: [42] } });
    assert.equal(fixture.calls.filter(call => call.changeType === 'list').length, readsBeforeApply, 'Cleanup must not automatically issue setup/list/synchronization requests');
    assert.equal(fixture.writes().length, 0, 'Cleanup must leave all ordinary update and upload operations paused');
    assert.equal(fixture.nodes.get('network-conflicts-count').textContent, '0', 'Zero remaining conflicts must be shown without falling back to an old table count');
    assert.equal(fixture.nodes.get('network-cleanup-result').children[0].textContent, 'Se retiraron las asignaciones de 1 servicios. Quedan 0 conflictos de IP.');
    assert.equal(fixture.nodes.get('network-cleanup-result').children[1].href, 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?download=' + backupName);
    assert.equal(fixture.nodes.get('reload-after-cleanup').style.display, 'inline-flex');
    assert.equal(fixture.reloads(), 0, 'Completing cleanup must require an explicit reload rather than performing one');
    assert.equal(owner(42).children[1].disabled, true); assert.equal(owner(42).children[2].disabled, true);
    assert.match(fixture.nodes.get('network-warning-note').textContent, /Recarga el panel/);
    fixture.reply('list/backups', { status: 'ok', error: false, data: [{ name: 'backup-0', date: '2026-10-14' }, { name: backupName, date: '2026-10-14T13:00:00Z' }], recovery: { readOnly: true } });
    await fixture.navigate('system');
    const cards = fixture.nodes.get('backups-grid').children;
    assert.equal(cards.length, 2);
    assert.equal(cards[1].children[0].children[0].textContent, 'Respaldo de limpieza');
    assert.equal(cards[1].children[1].children.length, 1, 'Cleanup backups must expose download without an unsupported restore action');
    assert.equal(cards[1].children[1].children[0].href, 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?download=' + backupName);
    assert.equal(cards[1].children[1].children[0].download, backupName, 'Cleanup downloads must preserve their SQLite filename');
    for (const invalid of ['../' + backupName, backupName.replace('.sqlite', '.db'), backupName + '.php', backupName.replace('aaaa', 'gggg')])
        assert.throws(() => fixture.api.downloadUrl(invalid), /Nombre de respaldo inválido/);
    await fixture.nodes.get('reload-after-cleanup').fire('click'); assert.equal(fixture.reloads(), 1);
    const protectedFixture = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details }); await protectedFixture.navigate('network_conflicts');
    protectedFixture.reply('cleanup/network_conflicts', { status: 'ok', error: false, data: { previewToken: null, expiresAt: null, candidates: [], blocked: [{ service_id: 42, reason: 'service_exists' }, { service_id: 43, reason: 'queue_busy' }] }, recovery: { readOnly: true } });
    await protectedFixture.nodes.get('network-conflicts-body').children[0].children[1].children[0].children[2].fire('click');
    assert.equal(protectedFixture.nodes.get('network-cleanup-candidates').children.length, 0);
    assert.equal(protectedFixture.nodes.get('network-cleanup-blocked').children.length, 2, 'A null preview token with no candidates must still render protected reasons');
    assert.equal(protectedFixture.nodes.get('network-cleanup-status').textContent, 'No hay asignaciones que puedan limpiarse con seguridad en esta revisión.');
    assert.equal(protectedFixture.nodes.get('apply-network-cleanup').disabled, true);
}

async function checkNumericPlanEditors() {
    const fixture = contentionFixture(true, true);
    Object.assign(fixture.storedPlan, {
        uploadSpeed: 5, downloadSpeed: 25, burstUpload: null, timeDownload: null,
        burstDownload: 60, threshDownload: 30, timeUpload: 12,
        numericIssues: [
            { field: 'burstUpload', reason: 'non_finite', value: 'Infinity' },
            { field: 'timeDownload', reason: 'non_numeric', value: attack }
        ]
    });
    await fixture.navigate('plans');
    assert.match(fixture.tables.get('#plans-table tbody').children[0].innerHTML, /Inválido/, 'Invalid values must have a visible table marker');
    const requestsBeforeOpen = fixture.requests.length;
    await fixture.editButton().fire('click');
    const burst = fixture.nodes.get('plan-burstUpload');
    const time = fixture.nodes.get('plan-timeDownload');
    const warning = fixture.nodes.get('plan-numeric-warning');
    const diagnostics = fixture.nodes.get('plan-numeric-issues');
    assert.equal(fixture.requests.length, requestsBeforeOpen, 'Opening diagnostics must not write or fetch to repair a plan');
    assert.equal(burst.value, '', 'An invalid burst must not silently become the UI zero sentinel');
    assert.equal(time.value, '', 'An invalid averaging window must remain an unresolved empty draft');
    assert.equal(fixture.storedPlan.burstUpload, null);
    assert.equal(fixture.storedPlan.numericIssues[0].value, 'Infinity', 'Opening an editor mutated its cached diagnostic');
    assert.equal(warning.hidden, false);
    assert.match(diagnostics.children[0].textContent, /burstUpload.*Infinity.*Valor inválido/);
    assert(diagnostics.children[1].textContent.includes(attack), 'Diagnostic values must remain literal text');
    assert(!renderedMarkup(diagnostics).includes('<img') && !renderedMarkup(diagnostics).includes('<script'));
    assert.equal(burst['aria-invalid'], 'true');
    await fixture.nodes.get('save-plan-btn').fire('click');
    assert.equal(fixture.planUpdates().length, 0, 'Opening and saving without explicit correction must not normalize invalid data');
    assert.equal(fixture.nodes.get('plan-form').invalidControl, 'plan-burstUpload');
    fixture.i18n.setLanguage('en');
    await flush();
    assert.match(diagnostics.children[0].textContent, /Invalid value/);
    assert.match(burst.validationMessage, /Correct this value/);
    assert.equal(fixture.nodes.get('plan-reset-invalid').textContent, 'Reset invalid settings');
    assert.equal(burst.value, '', 'A language change must not repair the numeric draft');
    burst.value = '35';
    await burst.fire('input');
    assert.equal(burst.validationMessage, '');
    assert.equal(burst['aria-invalid'], 'false');
    await fixture.nodes.get('save-plan-btn').fire('click');
    assert.equal(fixture.planUpdates().length, 0, 'Editing one invalid field must not clear another field’s diagnosis');
    assert.equal(fixture.nodes.get('plan-form').invalidControl, 'plan-timeDownload');
    const requestsBeforeReset = fixture.requests.length;
    await fixture.nodes.get('plan-reset-invalid').fire('click');
    assert.equal(time.value, '1');
    assert.equal(Number(burst.value), 35, 'Resetting invalid settings must preserve an explicitly corrected field');
    assert.equal(Number(fixture.nodes.get('plan-burstDownload').value), 60, 'Reset must preserve valid burst settings');
    assert.equal(Number(fixture.nodes.get('plan-threshDownload').value), 30);
    assert.equal(Number(fixture.nodes.get('plan-timeUpload').value), 12);
    assert.equal(fixture.requests.length, requestsBeforeReset, 'Reset must change the draft only');
    assert.equal(fixture.nodes.get('plan-reset-invalid').hidden, true);
    await fixture.nodes.get('save-plan-btn').fire('click');
    assert.equal(fixture.planUpdates().length, 1);
    assert.equal(fixture.planUpdates()[0].payload.data.burstUpload, 35);
    assert.equal(fixture.planUpdates()[0].payload.data.timeDownload, 1);
    await fixture.editButton().fire('click');
    assert.equal(warning.hidden, true, 'A corrected plan must not retain stale validation blockers');
    assert.equal(fixture.nodes.get('plan-form').reportValidity(), true);

    const preset = contentionFixture(true);
    Object.assign(preset.storedPlan, { burstUpload: null,
        numericIssues: [{ field: 'burstUpload', reason: 'non_finite', value: '-Infinity' }] });
    await preset.navigate('plans');
    await preset.editButton().fire('click');
    await preset.nodes.get('plan-preset-balanced').fire('click');
    assert.equal(preset.nodes.get('plan-burstUpload').validationMessage, '', 'Explicit burst presets must resolve the fields they replace');
    assert.equal(preset.planUpdates().length, 0);
    await preset.nodes.get('save-plan-btn').fire('click');
    assert.equal(preset.planUpdates()[0].payload.data.burstUpload, 50);

    const originalSpeed = contentionFixture(true, true);
    Object.assign(originalSpeed.storedPlan, { uploadSpeed: null, burstUpload: null,
        numericIssues: [{ field: 'uploadSpeed', reason: 'non_finite', value: 'Infinity' },
            { field: 'burstUpload', reason: 'non_finite', value: 'NaN' }] });
    await originalSpeed.navigate('plans');
    await originalSpeed.editButton().fire('click');
    originalSpeed.i18n.setLanguage('de');
    await flush();
    assert.equal(originalSpeed.nodes.get('plan-numeric-source-hint').hidden, false);
    assert.match(originalSpeed.nodes.get('plan-uploadOverride').placeholder, /ungültiger Wert/);
    await originalSpeed.nodes.get('plan-reset-invalid').fire('click');
    await originalSpeed.nodes.get('save-plan-btn').fire('click');
    assert.equal(originalSpeed.planUpdates().length, 0, 'Resetting plugin settings must not repair or overwrite a UISP base speed');
    assert.match(originalSpeed.nodes.get('notifications').lastElementChild.children[0].textContent, /Korrigieren Sie sie in UISP/);

    const ratio = contentionFixture(true);
    Object.assign(ratio.storedPlan, { ratio: null, numericIssues: [{ field: 'ratio', reason: 'invalid_integer', value: '1.5' }] });
    await ratio.navigate('plans');
    await ratio.editButton().fire('click');
    assert.equal(ratio.nodes.get('plan-ratio').disabled, false, 'An invalid disabled contention value must remain explicitly repairable');
    assert.match(ratio.nodes.get('plan-ratio-help').textContent, /valor inválido debe corregirse/);
    await ratio.nodes.get('plan-reset-invalid').fire('click');
    assert.match(ratio.nodes.get('plan-ratio-help').textContent, /corrección.*guardará con el plan/);
    await ratio.nodes.get('save-plan-btn').fire('click');
    assert.equal(ratio.planUpdates()[0].payload.data.ratio, 1, 'The explicit correction must be submitted even with global contention disabled');
}

async function checkContentionPlanEditors() {
    for (const [flag, disabled] of [[true, true], [1, true], ['1', true], ['true', true], ['on', true],
        [false, false], [0, false], ['0', false], ['false', false]]) {
        const fixture = contentionFixture(flag);
        await fixture.navigate('plans');
        assert(fixture.editButton(), 'Plans must load with an explicit saved contention flag: ' + String(flag));
        await fixture.editButton().fire('click');
        const ratio = fixture.nodes.get('plan-ratio');
        const form = fixture.nodes.get('plan-form');
        assert.equal(ratio.disabled, disabled, 'The ratio must follow saved configuration: ' + String(flag));
        assert.equal(ratio.required, !disabled);
        assert.equal(Number(ratio.value), 0, 'Opening a legacy plan must not normalize its stored zero');
        assert(fixture.nodes.get('plan-ratio-help').textContent.includes(disabled ? 'no se aplica' : 'entero desde 1'));
        await fixture.nodes.get('plan-preset-balanced').fire('click');
        if (disabled) {
            assert.equal(form.reportValidity(), true, 'An irrelevant stored zero must not block a burst edit');
            assert(!new FixtureFormData(form).values.some(([name]) => name === 'ratio'), 'Disabled ratio controls must be absent from browser FormData');
        } else {
            assert.equal(form.reportValidity(), false, 'An active ratio of zero must fail browser validation');
            await fixture.nodes.get('save-plan-btn').fire('click');
            assert.equal(fixture.planUpdates().length, 0, 'An active ratio of zero must not reach the API');
            assert.equal(form.invalidControl, 'plan-ratio');
            ratio.value = '';
            assert.equal(form.reportValidity(), false, 'The active ratio is required');
            ratio.value = '1.5';
            assert.equal(form.reportValidity(), false, 'The active ratio must be an integer');
            ratio.value = '1';
            assert.equal(form.reportValidity(), true);
        }
        await fixture.nodes.get('save-plan-btn').fire('click');
        assert.equal(fixture.planUpdates().length, 1, 'A valid burst edit should issue one plan update');
        const data = fixture.planUpdates()[0].payload.data;
        assert.equal(data.burstUpload, 50);
        assert.equal(data.burstDownload, 50);
        assert.equal(Object.hasOwn(data, 'ratio'), !disabled, 'Burst-only edits must omit inactive contention rather than sending zero or an invented one');
        assert.equal(fixture.storedPlan.ratio, disabled ? 0 : 1);
        await fixture.editButton().fire('click');
        assert.equal(Number(ratio.value), disabled ? 0 : 1, 'Saving and reopening must preserve the appropriate ratio');
        assert.equal(ratio.disabled, disabled);
    }

    // A configuration draft must not silently change what the server applies.
    // Exercise both toggle directions before and after saving the global setting.
    for (const savedDisabled of [false, true]) {
        const fixture = contentionFixture(savedDisabled);
        await fixture.navigate('config');
        fixture.contentionSwitch().checked = !savedDisabled;
        await fixture.nodes.get('config-form').fire('change');
        assert.equal(fixture.config.disable_contention, savedDisabled, 'Editing a switch must remain a draft until Save');
        await fixture.navigate('plans');
        await fixture.editButton().fire('click');
        assert.equal(fixture.nodes.get('plan-ratio').disabled, savedDisabled, 'The plan editor must ignore an unsaved global draft');
        await fixture.closeEditor();
        await fixture.navigate('config');
        assert.equal(fixture.contentionSwitch().checked, !savedDisabled, 'The global draft must survive navigation to plans');
        await fixture.nodes.get('save-config-btn').fire('click');
        assert.equal(fixture.config.disable_contention, !savedDisabled);
        await fixture.navigate('plans');
        await fixture.editButton().fire('click');
        assert.equal(fixture.nodes.get('plan-ratio').disabled, !savedDisabled, 'A later editor must reflect the newly saved global setting');
        assert.equal(fixture.nodes.get('plan-ratio').required, savedDisabled);
        assert.equal(Number(fixture.nodes.get('plan-ratio').value), 0, 'Changing the global setting must not rewrite a plan ratio');
    }

    const failureFixture = contentionFixture(true);
    await failureFixture.navigate('plans');
    const previousPencil = failureFixture.editButton();
    await previousPencil.fire('click');
    await failureFixture.closeEditor();
    for (const response of [
        { status: 'failed', error: true, message: 'contention config fixture failed' },
        { status: 'ok', error: false, data: {} },
        { status: 'ok', error: false, data: [] }
    ]) {
        failureFixture.setConfigResponse(response);
        await failureFixture.navigate('plans');
        assert.equal(failureFixture.editButton(), undefined, 'Failed or missing saved configuration must remove stale plan actions');
        await previousPencil.fire('click');
        assert.equal(failureFixture.nodes.get('plan-modal').style.display, 'none', 'A stale pencil must not assume that contention is enabled or disabled');
        assert.equal(failureFixture.nodes.get('loader').style.display, 'none');
        assert.equal(failureFixture.nodes.get('notifications').lastElementChild.role, 'alert');
        assert.equal(failureFixture.planUpdates().length, 0);
    }
    failureFixture.setConfigResponse(undefined);
    await failureFixture.navigate('plans');
    await failureFixture.editButton().fire('click');
    assert.equal(failureFixture.nodes.get('plan-ratio').disabled, true, 'A successful retry must restore the actual saved setting');
}

async function checkLanguagePresentation() {
    const fixture = contentionFixture(true, true);
    await fixture.navigate('plans');
    await fixture.editButton().fire('click');
    const burst = fixture.nodes.get('plan-burstUpload');
    burst.value = '37.5';
    fixture.nodes.get('plan-timeUpload').value = '9.25';
    fixture.nodes.get('plan-modal').querySelector('.modal-body').scrollTop = 173;
    const requestsBefore = fixture.requests.length;
    fixture.i18n.setLanguage('en');
    await flush();
    assert.equal(burst.value, '37.5', 'Changing UI language must preserve custom unsaved burst values');
    assert.equal(fixture.nodes.get('plan-timeUpload').value, '9.25');
    assert.equal(fixture.nodes.get('plan-modal').style.display, 'flex');
    assert.equal(fixture.nodes.get('plan-modal').querySelector('.modal-body').scrollTop, 173);
    assert.equal(fixture.nodes.get('plan-ratio').disabled, true);
    assert.equal(fixture.requests.length, requestsBefore, 'A plan language switch must use local state without requests');
    assert.match(fixture.nodes.get('plan-burst-preview-upload').textContent, /Upload: normal 5 Mbps/);
    assert.match(fixture.nodes.get('plan-ratio-help').textContent, /Contention is disabled/);
    assert.equal(fixture.nodes.get('page-title').textContent, 'Plans');
    assert.equal(fixture.planUpdates().length, 0);
    fixture.i18n.setLanguage('pt');
    await flush();
    assert.match(fixture.nodes.get('plan-burst-preview-upload').textContent, /Janela 9,25 s/);
    assert.equal(burst.value, '37.5', 'Localized number formatting must not change form numeric values');

    await fixture.closeEditor();
    await fixture.navigate('config');
    const control = fixture.contentionSwitch();
    control.checked = false;
    await fixture.nodes.get('config-form').fire('input');
    const writesBefore = fixture.requests.filter(request => request.payload.changeType === 'update').length;
    const pending = deferred();
    fixture.delayFields('en', pending.promise);
    fixture.i18n.setLanguage('en');
    await flush();
    control.checked = true;
    await fixture.nodes.get('config-form').fire('input');
    fixture.i18n.setLanguage('de');
    await flush();
    pending.resolve();
    await flush();
    await flush();
    assert.equal(fixture.contentionSwitch(), control, 'Language changes must update labels in place, preserving control identity');
    assert.equal(control.checked, true, 'Keyboard and switch edits during label loading must survive');
    assert.equal(fixture.i18n.getLanguage(), 'de');
    const fieldGroup = fixture.nodes.get('config-fields-container').children.flatMap(section => section.children)
        .flatMap(node => node.children).find(node => node.dataset.fieldName === 'disable_contention');
    assert(fieldGroup);
    assert.equal(fieldGroup.querySelector('.field-label-text').textContent, 'Überbuchung deaktivieren', 'A slow earlier language response must never overwrite current labels');
    assert.equal(fixture.requests.filter(request => request.payload.changeType === 'update').length, writesBefore);

    control.checked = false;
    await fixture.nodes.get('config-form').fire('input');
    const save = deferred();
    fixture.delaySave(save.promise);
    const saving = fixture.nodes.get('save-config-btn').fire('click');
    await flush();
    control.checked = true;
    await fixture.nodes.get('config-form').fire('input');
    fixture.i18n.setLanguage('it');
    await flush();
    save.resolve();
    await saving;
    assert.equal(fixture.config.disable_contention, false, 'Only explicitly submitted settings must reach the backend');
    assert.equal(fixture.contentionSwitch().checked, true, 'A draft edited during save and language change must survive the reload');
    assert.equal(fixture.nodes.get('config-save-status').textContent, 'Modifiche non salvate.');
    assert(!Object.hasOwn(fixture.config, 'language'), 'Local UI preferences must never be saved into router/plugin settings');
}

(async () => {
    evaluate(apiSource);
    assert.equal(evaluate('api.endpoint'), '/crm/_plugins/ros-plugin/public.php');
    assert.equal(evaluate('PanelUtils.escapeHTML(' + JSON.stringify(attack) + ')'), '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;&lt;script&gt;alert(2)&lt;/script&gt;&amp;&#39;');
    const create = plain(evaluate('PanelUtils.devicePayload({name:"Router",ip:"192.0.2.1",user:"fixture",password:"00123",port:"",pfxLength:"/64",qos:"1"},"insert")'));
    assert.equal(create.type, 'mikrotik');
    assert.equal(create.port, 8728);
    assert.equal(create.pfxLength, 64);
    assert.equal(create.qos, 1);
    assert.equal(create.password, '00123');
    const edit = plain(evaluate('PanelUtils.devicePayload({id:"1",name:"Router",ip:"192.0.2.1",user:"fixture",password:"",port:"",pfxLength:""},"edit")'));
    assert.equal(edit.id, 1);
    assert(!('port' in edit));
    assert(!('password' in edit));
    assert.equal(evaluate('PanelUtils.devicePayload({name:"Router",ip:"192.0.2.1",user:"fixture",password:"x",port:"",ssl:1},"insert").port'), 8729);
    assert.throws(() => evaluate('PanelUtils.devicePayload({name:"R",ip:"192.0.2.1",user:"u",password:"x",port:"70000"},"insert")'));
    const plan = plain(evaluate('PanelUtils.planPayload({id:"1",ratio:"2",uploadOverride:"",downloadOverride:"3.75",timeUpload:"0.5",priorityUpload:"8",limitUpload:"0",burstUpload:"0"})'));
    assert.equal(plan.uploadOverride, null);
    assert.equal(plan.downloadOverride, 3.75);
    assert.equal(plan.timeUpload, 0.5);
    assert.equal(plan.limitUpload, 100);
    assert.equal(plan.burstUpload, -100);
    assert.throws(() => evaluate('PanelUtils.planPayload({id:"1",ratio:"0"})'));
    assert.throws(() => evaluate('PanelUtils.planPayload({id:"1",ratio:""})'));
    assert.throws(() => evaluate('PanelUtils.planPayload({id:"1",ratio:"1.5"})'));
    assert.throws(() => evaluate('PanelUtils.planPayload({id:"1",ratio:undefined})'));
    const omittedRatio = plain(evaluate('PanelUtils.planPayload({id:"1",burstUpload:"50",threshUpload:"25",timeUpload:"12"})'));
    assert(!Object.hasOwn(omittedRatio, 'ratio'), 'A disabled control must preserve the stored ratio by omitting it');
    assert.equal(omittedRatio.burstUpload, 50);
    assert.throws(() => evaluate('PanelUtils.planPayload({id:"1",ratio:"1",threshUpload:"101"})'));
    assert.throws(() => evaluate('PanelUtils.planPayload({id:"1",ratio:"1",limitDownload:"-1"})'));
    assert.throws(() => evaluate('PanelUtils.planPayload({id:"1",ratio:"1",burstUpload:"Infinity"})'));
    assert.deepEqual(plain(evaluate('PanelUtils.burstPresetValues("balanced")')), {
        burstUpload: 50, threshUpload: 25, timeUpload: 12, burstDownload: 50, threshDownload: 25, timeDownload: 12
    });
    assert.equal(evaluate('PanelUtils.burstPresetMatch(PanelUtils.burstPresetValues("balanced"))'), 'balanced');
    assert.equal(evaluate('PanelUtils.burstPresetMatch({...PanelUtils.burstPresetValues("balanced"),burstDownload:60})'), null);
    assert.equal(evaluate('PanelUtils.burstPresetMatch({burstUpload:0,burstDownload:0,threshUpload:25,threshDownload:35,timeUpload:1,timeDownload:12})'), 'none', 'Disabled burst stays off even if legacy threshold and window values remain');
    assert.throws(() => evaluate('PanelUtils.burstPresetValues("unknown")'));
    const balancedPreview = plain(evaluate('PanelUtils.burstPreview({base:10,override:"",increment:50,reduction:25,window:12})'));
    assert.deepEqual(balancedPreview, { normal: 10, burst: 15, threshold: 7.5, window: 12, enabled: true, usable: true, initialSeconds: 6 });
    const decimalPreview = plain(evaluate('PanelUtils.burstPreview({base:99,override:"3.75",increment:25,reduction:20,window:8})'));
    assert.equal(decimalPreview.normal, 3.75);
    assert.equal(decimalPreview.burst, 4.6875);
    assert(Math.abs(decimalPreview.threshold - 3) < 1e-12);
    assert(Math.abs(decimalPreview.initialSeconds - 5.12) < 1e-12);
    const zeroPreview = plain(evaluate('PanelUtils.burstPreview({base:99,override:"0",increment:100,reduction:30,window:10})'));
    assert.equal(zeroPreview.normal, 0, 'An explicit zero override must not fall back to UISP');
    assert.equal(zeroPreview.initialSeconds, null, 'A missing effective rate must not promise a burst duration');
    const incompletePreview = plain(evaluate('PanelUtils.burstPreview({base:10,override:null,increment:50,reduction:0,window:0})'));
    assert.equal(incompletePreview.threshold, 0, 'UI zero keeps the legacy disabled-threshold meaning');
    assert.equal(incompletePreview.usable, false);
    assert.equal(incompletePreview.initialSeconds, null);
    const defaultWindow = plain(evaluate('PanelUtils.burstPreview({base:10,override:"",increment:50,reduction:25,window:null})'));
    assert.equal(defaultWindow.window, 1, 'An empty legacy burst-time uses the RouterOS job default of one second');
    assert.equal(defaultWindow.initialSeconds, 0.5);
    const incompletePayload = plain(evaluate('PanelUtils.planPayload({id:"1",ratio:"1",burstUpload:50,threshUpload:0,timeUpload:0})'));
    assert.equal(incompletePayload.burstUpload, 50, 'An old incomplete burst must remain editable when changing other plan fields');
    assert.equal(incompletePayload.threshUpload, 100);
    assert.throws(() => evaluate('PanelUtils.burstPreview({base:10,override:"",increment:25,reduction:101,window:8})'));
    assert.throws(() => evaluate('PanelUtils.burstPreview({base:10,override:"bad",increment:25,reduction:20,window:8})'));

    await evaluate('api.restoreBackup("backup-1")');
    assert.deepEqual(requests.at(-1).payload, { changeType: 'update', target: 'system', action: 'restore', data: { name: 'backup-1' } });
    await evaluate('api.rebuild()');
    assert.deepEqual(requests.at(-1).payload, { changeType: 'update', target: 'system', action: 'insert', data: { type: 'all' } });
    await evaluate('api.rebuildCache()');
    assert.equal(requests.at(-1).payload.action, 'cache');
    assert.equal(requests.at(-1).payload.target, 'system');
    await evaluate('api.uploadBackup({name:"fixture.db"})');
    assert.equal(requests.at(-1).endpoint, '/crm/_plugins/ros-plugin/public.php');
    assert.equal(requests.at(-1).options.body.values[0][0], 'backup');
    assert(!requests.at(-1).options.headers);
    assert.equal(evaluate('api.downloadUrl("backup-1")'), 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?download=backup-1');
    assert.throws(() => evaluate('api.downloadUrl("../config.json")'));
    assert.throws(() => evaluate('api.downloadUrl("backup-1evil")'));
    evaluate('window.location.href = "https://fixture.invalid/crm/_plugins/ros-plugin/public/panel_v2/index.php"');
    assert.equal(evaluate('new ApiService().endpoint'), '/crm/_plugins/ros-plugin/public.php');
    assert(evaluate('api.isAccepted({status:"accepted"})'));
    responders.set('update/system/log_clear', { status: 'failed', error: false, message: 'fixture failed' });
    assert.equal((await evaluate('api.clearLogs()')).error, true);
    const fixtureFetch = context.fetch;
    context.fetch = async () => ({ ok: false, status: 403, json: async () => ({ message: 'fixture unauthorized' }) });
    assert.equal((await evaluate('api.getDevices()')).message, 'fixture unauthorized');
    context.fetch = async () => ({ ok: true, status: 200, json: async () => { throw new Error('invalid fixture JSON'); } });
    assert.equal((await evaluate('api.getDevices()')).error, true);
    context.fetch = async () => ({ ok: false, status: 502, json: async () => { throw new Error('invalid fixture JSON'); } });
    assert.match((await evaluate('api.getDevices()')).message, /HTTP 502/);
    for (const malformed of [null, [], 'invalid fixture JSON']) {
        context.fetch = async () => ({ ok: false, status: 502, json: async () => malformed });
        const rejected = await evaluate('api.getDevices()');
        assert.equal(rejected.error, true);
        assert.equal(rejected.message, 'Respuesta inválida del servidor.');
    }
    context.fetch = async () => ({ ok: false, status: 502, json: async () => ({ status: 'ok', error: false, data: { requested: 3, completed: 3, failed: 0 } }) });
    assert.equal((await evaluate('api.getDevices()')).error, true, 'An HTTP failure must never become an application success');
    context.fetch = fixtureFetch;

    responders.set('list/devices/list', { status: 'ok', error: false, data: [{ id: 1, name: attack, ip: attack, user: 'fixture', port: 8729, qos: null, users: 3, status: true }] });
    const planState = { id: 1, name: attack, uploadSpeed: 2, downloadSpeed: 4, uploadOverride: 3.75, effectiveUploadSpeed: 3.75, ratio: 1,
        burstUpload: 37.5, burstDownload: 80, threshUpload: 33.3, threshDownload: 18.5, timeUpload: 9.5, timeDownload: 14.25 };
    responders.set('list/plans/list', () => ({ status: 'ok', error: false, data: [planState] }));
    responders.set('update/plans/edit', payload => {
        Object.assign(planState, payload.data);
        return { status: 'ok', error: false, data: {} };
    });
    const configFields = {
        config_auto_ppp_user: { name: 'auto_ppp_user', label: 'Usuario PPP automático', description: 'Generación automática', type: 1 },
        config_router_ppp_pool: { name: 'router_ppp_pool', label: 'Usar pool del router', description: 'Pool DHCP' },
        config_ppp_pool: { name: 'ppp_pool', label: 'Pool PPP', description: 'Subredes', hint: '10.99.0.0/16' },
        config_disabled_list: { name: 'disabled_list', label: attack, description: attack, type: 0 },
        config_unsuspend_date_fix: { name: 'unsuspend_date_fix', label: 'Ajustar fecha', description: 'Facturación', type: 1 },
        config_lease_time: { name: 'lease_time', label: 'Concesión', description: 'Minutos' },
        config_hs_enable: { name: 'hs_enable', label: 'Hotspot', description: 'Activar Hotspot', isAttribute: true },
        config_password_attr: { name: 'password_attr', label: 'Atributo contraseña', description: attack, isAttribute: true },
        config_ip_addr_attr: { name: 'ip_addr_attr', label: 'Atributo IP', description: 'IPv4 manual', isAttribute: true },
        config_secret: { name: 'secret', label: 'Secreto de prueba', description: 'Texto protegido', obscure: true },
        config_uisp_token: { name: 'uisp_token', label: 'Token oculto', description: 'No es editable aquí' },
        config_bad: { name: 'bad"><img', label: 'Nombre inválido', description: 'Debe omitirse' }
    };
    const configState = {
        disabled_list: attack, auto_ppp_user: 1, router_ppp_pool: 0, unsuspend_date_fix: 1, disable_contention: false,
        ppp_pool: '10.99.0.0/16,fd10:9900::/48', lease_time: '30', hs_enable: 0,
        password_attr: '00123', ip_addr_attr: 'dirección-IPv4', ip_addr6_attr: 'prefijo-IPv6', hs_attr: 'hs-cuenta',
        secret: '000123&"clave', uisp_token: 'private-fixture', plugin_language: 'fr'
    };
    responders.set('list/messages/list', { status: 'ok', error: false, data: [{}, configFields] });
    responders.set('list/config/list', () => ({ status: 'ok', error: false, data: configState }));
    responders.set('update/config/edit', payload => {
        Object.assign(configState, payload.data);
        return { status: 'ok', error: false, data: {} };
    });
    responders.set('list/jobs/list', { status: 'ok', error: false, data: { Q1: { id: 1, username: attack, error: attack } } });
    responders.set('list/sys/get', { status: 'ok', error: false, data: { app_version: attack, php_version: attack } });
    responders.set('list/backups/list', { status: 'ok', error: false, data: [{ name: 'backup-1', date: '2026-01-01' }, { name: '../config.json', date: attack }] });
    responders.set('list/orphans/get', { status: 'ok', error: false, data: [{ name: attack, mac: attack, address: attack, address6: attack }] });
    evaluate(appSource);
    ready();
    await flush();
    async function synchronizeDevice(response, accept, status = 200) {
        responders.set('update/system/insert', response);
        responseStatuses.set('update/system/insert', status);
        const syncButton = tables.get('#devices-table tbody').children[0].querySelector('.row-actions').children[2];
        assert.equal(syncButton.title, 'Sincronizar y reconectar cuentas');
        assert.equal(syncButton['aria-label'], 'Sincronizar y reconectar cuentas');
        const beforeRequests = requests.length;
        const running = syncButton.fire('click');
        await flush();
        const confirmation = document.body.children.find(node => node.className === 'confirm-overlay');
        assert(confirmation, 'Router synchronization must confirm its session reconnections');
        assert.equal(confirmation.children[0].children[0].textContent,
            '¿Sincronizar las cuentas administradas de este MikroTik y reconectar todas sus sesiones PPP/Hotspot?');
        assert.equal(requests.length, beforeRequests, 'No router request may start before confirmation');
        await confirmation.children[0].children[1].children[accept ? 1 : 0].fire('click');
        await running;
        const sent = requests.slice(beforeRequests).filter(request => request.payload?.target === 'system');
        if (accept) {
            assert.equal(sent.length, 1);
            assert.deepEqual(sent[0].payload, { changeType: 'update', target: 'system', action: 'insert', data: { type: 'device', id: 1 } },
                'Router sync must preserve the device route without adding a UI force flag');
        } else assert.equal(sent.length, 0, 'Canceling router reconnection must not issue provisioning requests');
        assert.equal(syncButton.disabled, false);
        assert.equal(elements.get('loader').style.display, 'none');
        responseStatuses.delete('update/system/insert');
        return elements.get('notifications').lastElementChild;
    }
    const canceledNotifications = elements.get('notifications').children.length;
    await synchronizeDevice({ status: 'ok', error: false, data: { requested: 2, completed: 2, failed: 0 } }, false);
    assert.equal(elements.get('notifications').children.length, canceledNotifications, 'Canceling must not claim synchronization or reconnection');
    const syncSuccess = await synchronizeDevice({ status: 'ok', error: false, data: { requested: 2, completed: 2, failed: 0 } }, true);
    assert.equal(syncSuccess.role, 'status');
    assert(syncSuccess.children[0].textContent.includes('Sincronización y reconexión completadas.'));
    const syncPartial = await synchronizeDevice({ status: 'failed', error: true, message: 'provisioning_incomplete',
        data: { requested: 2, completed: 1, failed: 1, failures: [{ serviceId: 1420, planId: 1 }] } }, true, 502);
    assert.equal(syncPartial.role, 'alert');
    assert.match(syncPartial.children[0].textContent, /1 de 2 servicios completados; 1 pendientes o con error/);
    assert(!syncPartial.children[0].textContent.includes('Sincronización y reconexión completadas.'), 'Partial synchronization must not claim that all reconnections completed');
    const syncPending = await synchronizeDevice({ status: 'accepted', error: false, accepted: true, data: {} }, true);
    assert.equal(syncPending.role, 'status');
    assert(syncPending.children[0].textContent.includes('Solicitud aceptada.'));
    assert(!syncPending.children[0].textContent.includes('Sincronización y reconexión completadas.'), 'Pending synchronization must not claim completed reconnections');
    responders.delete('update/system/insert');
    const deviceRow = tables.get('#devices-table tbody').children[0];
    assert(deviceRow.innerHTML.includes('&lt;img'));
    assert(!deviceRow.innerHTML.includes('<img'));
    await deviceRow.querySelector('.row-actions').children[0].fire('click');
    assert(!tables.get('#orphan-table tbody').children[0].innerHTML.includes('<img'));
    const deviceLoaderWrites = elements.get('loader').styleWrites.length;
    const deviceEditRequests = requests.length;
    await deviceRow.querySelector('.row-actions').children[1].fire('click');
    assert.deepEqual(displayWrites(elements.get('loader'), deviceLoaderWrites), [], 'Opening a cached device must not flash the loading overlay');
    assert.equal(requests.length, deviceEditRequests, 'Opening a cached device must not contact the server');
    assert.equal(elements.get('dev-password').value, '');
    assert.equal(elements.get('dev-ssl').checked, true, 'Port 8729 must infer TLS for older records');
    assert(!markup.includes('id="dev-qos"'), 'Device form must not expose an incomplete QoS policy');
    elements.get('device-form').formValues = { id: '1', name: 'Router', ip: '192.0.2.1', user: 'fixture', port: '8729', password: '', qos: '0' };
    await elements.get('save-device-btn').fire('click');
    const savedDevice = requests.findLast(request => request.payload?.target === 'devices' && request.payload?.action === 'edit');
    assert(savedDevice, 'Device edit was not submitted');
    assert(!Object.hasOwn(savedDevice.payload.data, 'qos'), 'Editing a legacy router must not request QoS removal');
    assert(!Object.hasOwn(savedDevice.payload.data, 'password'), 'Empty edit password must preserve router credentials');
    await navigation[1].fire('click');
    await flush();
    assert(!tables.get('#plans-table tbody').children[0].innerHTML.includes('<img'));
    elements.get('plan-modal').querySelector('.modal-body').scrollTop = 350;
    const planEditButton = tables.get('#plans-table tbody').children[0].querySelector('.row-actions').children[0];
    const planLoaderWrites = elements.get('loader').styleWrites.length;
    const planModalWrites = elements.get('plan-modal').styleWrites.length;
    const requestsBeforeOpen = requests.length;
    for (let repeat = 0; repeat < 3; repeat++) await planEditButton.fire('click');
    assert.deepEqual(displayWrites(elements.get('loader'), planLoaderWrites), [], 'The pencil must open the cached editor without activating a loading overlay');
    assert.deepEqual(displayWrites(elements.get('plan-modal'), planModalWrites), ['flex', 'flex', 'flex'], 'Repeated pencil clicks must never hide or toggle the editor');
    assert.equal(requests.length, requestsBeforeOpen, 'Opening the plan must not perform a network request');
    assert.equal(planEditButton.disabled, false, 'A local pencil action must remain available after opening');
    assert.equal(elements.get('plan-modal').querySelector('.modal-body').scrollTop, 0, 'Opening the editor must start at the top');
    assert.deepEqual(plain(elements.get('plan-ratio').focusOptions), { preventScroll: true }, 'Focus must not scroll the editor past its heading or templates');
    assert.equal(elements.get('plan-uploadOverride').value, 3.75);
    assert.equal(elements.get('plan-burst-preset-status').textContent, 'Personalizado');
    assert.equal(elements.get('plan-burstUpload').value, 37.5, 'Opening a custom plan must preserve its burst');
    assert.equal(elements.get('plan-timeDownload').value, 14.25);
    assert(elements.get('plan-burst-preview-upload').textContent.includes('normal 3,75 Mbps'), 'Preview must use the effective override');
    const normalPlanUpdate = responders.get('update/plans/edit');
    const failedSaveGate = deferred();
    responders.set('update/plans/edit', () => failedSaveGate.promise);
    const valuesBeforeFailedSave = planFields.map(field => elements.get('plan-' + field).value);
    const failedSaveModalWrites = elements.get('plan-modal').styleWrites.length;
    const failedSave = elements.get('save-plan-btn').fire('click');
    await flush();
    assert.equal(elements.get('loader').style.display, 'flex', 'A real plan save must show the loading overlay while awaiting the server');
    assert.equal(elements.get('save-plan-btn').disabled, true, 'A pending save must prevent repeated submission');
    assert.equal(elements.get('plan-modal').style.display, 'flex', 'Waiting for a save must retain the open editor');
    failedSaveGate.resolve({ status: 'failed', error: true, message: 'plan save fixture failed' });
    await failedSave;
    assert.deepEqual(displayWrites(elements.get('plan-modal'), failedSaveModalWrites), [], 'A failed save must never hide or reopen the editor');
    assert.deepEqual(planFields.map(field => elements.get('plan-' + field).value), valuesBeforeFailedSave, 'A failed save must preserve every editable draft value');
    assert.equal(elements.get('plan-modal').style.display, 'flex');
    assert.equal(elements.get('loader').style.display, 'none', 'An error must release the loading overlay');
    assert.equal(elements.get('save-plan-btn').disabled, false, 'An error must allow saving again');
    assert(elements.get('notifications').lastElementChild.children[0].textContent.includes('plan save fixture failed'));
    responders.set('update/plans/edit', normalPlanUpdate);
    const beforePresetRequests = requests.length;
    const modalWritesBeforePreset = elements.get('plan-modal').styleWrites.length;
    const loaderWritesBeforePreset = elements.get('loader').styleWrites.length;
    await elements.get('plan-preset-balanced').fire('click');
    assert.equal(requests.length, beforePresetRequests, 'A template must only populate the form, without saving or touching RouterOS');
    assert.deepEqual(displayWrites(elements.get('plan-modal'), modalWritesBeforePreset), [], 'Changing a burst template must not toggle editor visibility');
    assert.deepEqual(displayWrites(elements.get('loader'), loaderWritesBeforePreset), [], 'Changing a local burst template must not activate a loading overlay');
    assert.equal(elements.get('plan-burst-preset-status').textContent, 'Equilibrado');
    assert(elements.get('plan-burst-preset-description').textContent.includes('Más margen de velocidad'));
    assert.equal(elements.get('plan-preset-balanced')['aria-pressed'], 'true');
    assert.equal(elements.get('plan-preset-fast')['aria-pressed'], 'false');
    for (const direction of ['Upload', 'Download']) {
        assert.equal(elements.get('plan-burst' + direction).value, '50');
        assert.equal(elements.get('plan-thresh' + direction).value, '25');
        assert.equal(elements.get('plan-time' + direction).value, '12');
    }
    assert(elements.get('plan-burst-preview-upload').textContent.includes('Pico 5,625 Mbps'));
    assert(elements.get('plan-burst-preview-upload').textContent.includes('Umbral 2,8125 Mbps'));
    assert(elements.get('plan-burst-preview-upload').textContent.includes('6 s de pico, sin garantía'));
    const manualModalWrites = elements.get('plan-modal').styleWrites.length;
    const manualLoaderWrites = elements.get('loader').styleWrites.length;
    elements.get('plan-uploadOverride').value = '0';
    await elements.get('plan-uploadOverride').fire('input');
    assert(elements.get('plan-burst-preview-upload').textContent.includes('sin velocidad base positiva para estimar el burst'));
    assert(!elements.get('plan-burst-preview-upload').textContent.includes('normal 0 Mbps'), 'A zero override must not promise a traffic rate');
    elements.get('plan-uploadOverride').value = '';
    await elements.get('plan-uploadOverride').fire('input');
    assert(elements.get('plan-burst-preview-upload').textContent.includes('normal 2 Mbps'), 'An empty override must use UISP');
    assert(elements.get('plan-burst-preview-upload').textContent.includes('Pico 3 Mbps'));
    elements.get('plan-downloadOverride').value = '3.75';
    elements.get('plan-burstUpload').value = '37.5';
    elements.get('plan-threshUpload').value = '33.3';
    elements.get('plan-timeUpload').value = '0.5';
    await elements.get('plan-burstUpload').fire('input');
    assert.equal(elements.get('plan-burst-preset-status').textContent, 'Personalizado');
    assert(elements.get('plan-burst-preset-description').textContent.includes('ajustados manualmente'));
    assert.deepEqual(displayWrites(elements.get('plan-modal'), manualModalWrites), [], 'Editing burst fields and overrides must keep the editor continuously visible');
    assert.deepEqual(displayWrites(elements.get('loader'), manualLoaderWrites), [], 'Calculating a local preview must not activate a loading overlay');
    const planNotificationsBefore = elements.get('notifications').children.length;
    const successfulSaveGate = deferred();
    const refreshGate = deferred();
    const normalPlanList = responders.get('list/plans/list');
    responders.set('update/plans/edit', async payload => {
        await successfulSaveGate.promise;
        return normalPlanUpdate(payload);
    });
    responders.set('list/plans/list', () => refreshGate.promise);
    const successfulSave = elements.get('save-plan-btn').fire('click');
    await flush();
    assert.equal(elements.get('loader').style.display, 'flex');
    assert.equal(elements.get('save-plan-btn').disabled, true);
    assert.equal(elements.get('plan-modal').style.display, 'flex', 'The editor must remain open until the server confirms a successful save');
    successfulSaveGate.resolve();
    await flush();
    assert.equal(elements.get('plan-modal').style.display, 'none', 'A confirmed successful save should close the editor');
    assert.equal(elements.get('loader').style.display, 'flex', 'Loading must continue until the refreshed plan list arrives');
    assert.equal(elements.get('save-plan-btn').disabled, true);
    refreshGate.resolve(normalPlanList());
    await successfulSave;
    assert.equal(elements.get('loader').style.display, 'none');
    assert.equal(elements.get('save-plan-btn').disabled, false);
    responders.set('update/plans/edit', normalPlanUpdate);
    responders.set('list/plans/list', normalPlanList);
    assert.equal(elements.get('notifications').children.length, planNotificationsBefore + 1, 'A successful plan save must show one notification');
    assert.equal(elements.get('notifications').lastElementChild.role, 'status');
    assert.match(elements.get('notifications').lastElementChild.children[0].textContent, /^Plan guardado\..*Trabajos/);
    const saved = requests.findLast(request => request.payload?.target === 'plans' && request.payload?.action === 'edit');
    assert.equal(saved.payload.data.uploadOverride, null);
    assert.equal(saved.payload.data.downloadOverride, 3.75);
    assert.equal(saved.payload.data.burstUpload, 37.5);
    assert.equal(saved.payload.data.threshUpload, 33.3);
    assert.equal(saved.payload.data.timeUpload, 0.5);
    assert(Object.keys(saved.payload.data).every(field => planFields.includes(field)), 'Preset names and catalog data must not enter the legacy payload');
    elements.get('plan-modal').querySelector('.modal-body').scrollTop = 200;
    await tables.get('#plans-table tbody').children[0].querySelector('.row-actions').children[0].fire('click');
    assert.equal(elements.get('plan-modal').querySelector('.modal-body').scrollTop, 0, 'Reopening a scrolled custom plan must restore the top of the editor');
    assert.equal(elements.get('plan-burst-preset-status').textContent, 'Personalizado');
    assert.equal(elements.get('plan-burstUpload').value, 37.5, 'Saving and reopening must preserve custom values');
    assert.equal(elements.get('plan-threshUpload').value, 33.3);
    assert.equal(elements.get('plan-timeUpload').value, 0.5);
    await elements.get('plan-preset-none').fire('click');
    assert.equal(elements.get('plan-burst-preset-status').textContent, 'Sin burst');
    assert(elements.get('plan-burst-preview-upload').textContent.includes('Burst desactivado'));
    assert.equal(elements.get('plan-threshUpload').value, '0');
    assert.equal(elements.get('plan-timeUpload').value, '0');
    await elements.get('save-plan-btn').fire('click');
    const offPlan = requests.findLast(request => request.payload?.target === 'plans' && request.payload?.action === 'edit').payload.data;
    assert.equal(offPlan.burstUpload, -100);
    assert.equal(offPlan.burstDownload, -100);
    assert.equal(offPlan.threshUpload, 100);
    assert.equal(offPlan.threshDownload, 100);
    assert.equal(offPlan.timeUpload, 0);
    assert.equal(offPlan.timeDownload, 0);
    assert(tables.get('#plans-table tbody').children[0].innerHTML.includes('Sin burst'));
    await tables.get('#plans-table tbody').children[0].querySelector('.row-actions').children[0].fire('click');
    assert.equal(elements.get('plan-burst-preset-status').textContent, 'Sin burst', 'Off sentinels must reopen as the off template');
    Object.assign(planState, { threshUpload: 25, threshDownload: 30, timeUpload: 1, timeDownload: 12 });
    await navigation[1].fire('click');
    await flush();
    await tables.get('#plans-table tbody').children[0].querySelector('.row-actions').children[0].fire('click');
    assert.equal(elements.get('plan-burst-preset-status').textContent, 'Sin burst', 'Legacy off plans with retained window and threshold must be identified as off');
    assert.equal(elements.get('plan-threshUpload').value, 25, 'Opening a legacy off plan must not reset retained fields');
    assert.equal(elements.get('plan-timeDownload').value, 12);
    await elements.get('plan-preset-soft').fire('click');
    assert.equal(elements.get('plan-burstUpload').value, '25');
    assert.equal(elements.get('plan-threshDownload').value, '20');
    assert.equal(elements.get('plan-timeDownload').value, '8');
    await elements.get('plan-preset-fast').fire('click');
    assert.equal(elements.get('plan-burstDownload').value, '100');
    assert.equal(elements.get('plan-threshUpload').value, '30');
    assert.equal(elements.get('plan-timeUpload').value, '10');
    elements.get('plan-timeUpload').value = '';
    await elements.get('plan-timeUpload').fire('input');
    assert(elements.get('plan-burst-preview-upload').textContent.includes('Ventana 1 s'), 'Preview must match the server default for a blank burst-time');
    elements.get('plan-timeUpload').value = '0';
    await elements.get('plan-timeUpload').fire('input');
    assert(elements.get('plan-burst-preview-upload').textContent.includes('no funciona como aceleración'), 'Incomplete existing values should warn rather than promise a duration');
    const normalPlanResponder = responders.get('update/plans/edit');
    responders.set('update/plans/edit', payload => {
        Object.assign(planState, payload.data);
        return { status: 'accepted', error: false, accepted: true };
    });
    const acceptedPlanNotificationsBefore = elements.get('notifications').children.length;
    await elements.get('save-plan-btn').fire('click');
    assert.equal(elements.get('notifications').children.length, acceptedPlanNotificationsBefore + 1, 'An accepted plan save must not duplicate notifications');
    assert(elements.get('notifications').lastElementChild.className.includes('toast-info'));
    assert(elements.get('notifications').lastElementChild.children[0].textContent.includes('Solicitud aceptada.'));
    responders.set('update/plans/edit', normalPlanResponder);
    await navigation[2].fire('click');
    await flush();
    const configRoot = elements.get('config-fields-container');
    assert(!renderedMarkup(configRoot).includes('<img'));
    assert(renderedMarkup(configRoot).includes('&quot;'));
    const control = (name, type) => {
        const found = formControls(configRoot).find(input => input.name === name && (!type || input.type === type));
        assert(found, `Missing rendered configuration control: ${name} (${type || 'any'})`);
        return found;
    };
    assert(!formControls(configRoot).some(input => input.name === 'uisp_token'));
    assert(!formControls(configRoot).some(input => input.name === 'password_attr'), 'Service attributes belong in the Attributes tab');
    assert.equal(control('auto_ppp_user', 'checkbox').checked, true);
    assert.equal(control('router_ppp_pool', 'checkbox').checked, false);
    assert(!formControls(configRoot).some(input => ['hs_enable','unsuspend_date_fix','unsuspend_fix_wait'].includes(input.name)),
        'Settings without an effective backend must not promise editable behavior');
    assert.equal(elements.get('config-save-status').textContent, 'Guarda para aplicar los cambios.');
    control('auto_ppp_user', 'checkbox').checked = false;
    control('router_ppp_pool', 'checkbox').checked = true;
    await control('auto_ppp_user', 'checkbox').fire('change');
    await control('router_ppp_pool', 'checkbox').fire('change');
    control('ppp_pool').value = '10.99.0.0/16,fd10:9900::/48';
    control('lease_time').value = '0.5';
    await elements.get('config-form').fire('input');
    assert.equal(elements.get('config-save-status').textContent, 'Cambios sin guardar.');
    const serialized = new FixtureFormData(elements.get('config-form')).values;
    assert.deepEqual(serialized.filter(([name]) => name === 'auto_ppp_user'), [['auto_ppp_user', '0']], 'Unchecked switch must submit its false fallback');
    assert.deepEqual(serialized.filter(([name]) => name === 'router_ppp_pool'), [['router_ppp_pool', '0'], ['router_ppp_pool', '1']], 'Checked switch must override its fallback in DOM order');
    const editsBefore = requests.filter(request => request.payload?.target === 'config' && request.payload.action === 'edit').length;
    await elements.get('save-config-btn').fire('click');
    const configEdits = requests.filter(request => request.payload?.target === 'config' && request.payload.action === 'edit');
    assert.equal(configEdits.length, editsBefore + 1, 'Save must issue one request from one click');
    const savedConfig = configEdits.at(-1).payload.data;
    const successToast = elements.get('notifications').lastElementChild;
    assert.equal(successToast.role, 'status');
    assert.equal(successToast['aria-atomic'], 'true');
    assert.equal(successToast.children[0].textContent, 'Configuración guardada.');
    assert(Array.from(timers.values()).some(timer => timer.delay === 6000), 'Successful notifications must dismiss automatically');
    assert.equal(savedConfig.auto_ppp_user, false);
    assert.equal(savedConfig.router_ppp_pool, true);
    assert(!Object.hasOwn(savedConfig,'unsuspend_date_fix') && !Object.hasOwn(savedConfig,'hs_enable'),
        'An unrelated save must not overwrite historical inactive settings');
    assert.equal(configState.unsuspend_date_fix, 1);
    assert.equal(configState.hs_enable, 0);
    assert.equal(savedConfig.lease_time, 0.5);
    assert.equal(savedConfig.ppp_pool, '10.99.0.0/16,fd10:9900::/48');
    assert.equal(savedConfig.disabled_list, attack, 'Escaping must not alter the saved text');
    assert.equal(savedConfig.secret, '000123&"clave', 'Text and leading zeros must survive form rendering');
    assert(!Object.hasOwn(savedConfig, 'password_attr'), 'Saving General must preserve unsubmitted service attribute mappings');
    assert.equal(control('auto_ppp_user', 'checkbox').checked, false, 'Saved false must remain false on reload');
    assert.equal(control('router_ppp_pool', 'checkbox').checked, true, 'Saved true must remain true on reload');
    assert.equal(elements.get('config-save-status').textContent, 'Guarda para aplicar los cambios.');
    // The loader does not stop keyboard input: edits after submission must stay as a separate unsaved draft.
    const originalConfigEdit = responders.get('update/config/edit');
    const delayedConfigSave = deferred();
    responders.set('update/config/edit', async payload => {
        await delayedConfigSave.promise;
        return originalConfigEdit(payload);
    });
    control('lease_time').value = '61';
    await elements.get('config-form').fire('input');
    const deferredSave = elements.get('save-config-btn').fire('click');
    await flush();
    control('lease_time').value = '99';
    control('router_ppp_pool', 'checkbox').checked = false;
    await elements.get('config-form').fire('input');
    delayedConfigSave.resolve();
    await deferredSave;
    assert.equal(configState.lease_time, 61, 'Only the version actually submitted may be saved');
    assert.equal(control('lease_time').value, '99', 'Keyboard edits during the save must survive its response');
    assert.equal(control('router_ppp_pool', 'checkbox').checked, false, 'Switch edits during the save must survive its response');
    assert.equal(elements.get('config-save-status').textContent, 'Cambios sin guardar.');
    responders.set('update/config/edit', originalConfigEdit);
    const originalConfigRead = responders.get('list/config/list');
    const delayedConfigRead = deferred();
    responders.set('list/config/list', async () => {
        await delayedConfigRead.promise;
        return originalConfigRead();
    });
    const saveAndReload = elements.get('save-config-btn').fire('click');
    await flush();
    assert.equal(configState.lease_time, 99, 'A second explicit save must apply the retained draft');
    control('lease_time').value = '88';
    await elements.get('config-form').fire('input');
    delayedConfigRead.resolve();
    await saveAndReload;
    assert.equal(control('lease_time').value, '88', 'Edits during the follow-up reload must survive replacement of the controls');
    assert.equal(configState.lease_time, 99, 'A new draft must not be automatically sent');
    assert.equal(elements.get('config-save-status').textContent, 'Cambios sin guardar.');
    responders.set('list/config/list', originalConfigRead);
    control('router_ppp_pool', 'checkbox').checked = false;
    control('lease_time').value = '5.25';
    await elements.get('tab-attrs').fire('click');
    assert.equal(control('password_attr').value, '00123');
    assert.equal(control('ip_addr6_attr').value, 'prefijo-IPv6', 'Existing mapping without translation metadata must remain editable');
    assert.equal(control('hs_attr').value, 'hs-cuenta', 'Existing Hotspot attribute mapping must remain editable');
    assert.equal(formControls(configRoot).filter(input => input.name === 'ip_addr6_attr').length, 1);
    assert(!formControls(configRoot).some(input => input.name === 'hs_enable'), 'Hotspot switch must not be duplicated in Attributes');
    assert(!renderedMarkup(configRoot).includes('<img'));
    control('ip_addr_attr').value = 'IP principal & secundaria';
    await elements.get('tab-general').fire('click');
    assert.equal(control('router_ppp_pool', 'checkbox').checked, false, 'Changing tabs must retain an unchecked draft switch');
    assert.equal(control('lease_time').value, '5.25', 'Changing tabs must retain a numeric draft');
    await elements.get('tab-attrs').fire('click');
    assert.equal(control('ip_addr_attr').value, 'IP principal & secundaria', 'Returning to Attributes must retain its text draft');
    await elements.get('save-config-btn').fire('click');
    const savedAttributes = requests.findLast(request => request.payload?.target === 'config' && request.payload.action === 'edit').payload.data;
    assert.equal(savedAttributes.password_attr, '00123');
    assert.equal(savedAttributes.ip_addr_attr, 'IP principal & secundaria');
    assert.equal(savedAttributes.ip_addr6_attr, 'prefijo-IPv6');
    assert.equal(savedAttributes.hs_attr, 'hs-cuenta');
    assert.equal(savedAttributes.auto_ppp_user, false);
    assert.equal(savedAttributes.router_ppp_pool, false, 'Saving Attributes must include an unchecked draft from General');
    assert.equal(savedAttributes.lease_time, 5.25, 'One save must include drafts from both visited tabs');
    control('ip_addr6_attr').value = 'IPv6 nuevo';
    const attributeSyncBefore = requests.filter(request => request.payload?.action === 'sync_attrs').length;
    const createRequestsBefore = requests.length;
    await elements.get('sync-attrs-btn').fire('click');
    const attributeSyncRequests = requests.filter(request => request.payload?.action === 'sync_attrs');
    assert.equal(attributeSyncRequests.length, attributeSyncBefore + 1, 'Create missing attributes must run from one click');
    assert.deepEqual(attributeSyncRequests.at(-1).payload, { changeType: 'update', target: 'config', action: 'sync_attrs', data: {} });
    const createSequence = requests.slice(createRequestsBefore).filter(request => request.payload?.changeType === 'update');
    assert.equal(createSequence[0].payload.action, 'edit', 'Save and create must persist current mappings first');
    assert.equal(createSequence[0].payload.data.ip_addr6_attr, 'IPv6 nuevo');
    assert.equal(createSequence[1].payload.action, 'sync_attrs');
    const successfulConfigEdit = responders.get('update/config/edit');
    responders.set('update/config/edit', { status: 'failed', error: true, message: 'save fixture failed' });
    control('hs_attr').value = 'hs-borrador';
    const syncBeforeFailedSave = requests.filter(request => request.payload?.action === 'sync_attrs').length;
    await elements.get('sync-attrs-btn').fire('click');
    assert.equal(requests.filter(request => request.payload?.action === 'sync_attrs').length, syncBeforeFailedSave, 'Failed save must stop attribute creation');
    assert.equal(control('hs_attr').value, 'hs-borrador', 'Failed save must keep the editable draft');
    assert.equal(elements.get('sync-attrs-btn').disabled, false);
    assert.equal(elements.get('loader').style.display, 'none');
    responders.set('update/config/edit', successfulConfigEdit);
    await elements.get('tab-general').fire('click');
    const fieldLoadsBefore = requests.filter(request => request.payload?.target === 'messages').length;
    assert(!formControls(configRoot).some(input => input.name === 'plugin_language'), 'The complete UI language selector must replace the conflicting legacy settings control');
    await elements.get('save-config-btn').fire('click');
    assert.equal(configState.plugin_language, 'fr', 'Saving unrelated settings must preserve the legacy global language');
    assert(!Object.hasOwn(requests.findLast(request => request.payload?.target === 'config' && request.payload.action === 'edit').payload.data, 'plugin_language'));
    assert.equal(configState.hs_attr, 'hs-borrador', 'A later save must retain the mapping from the failed save');
    assert.equal(requests.filter(request => request.payload?.target === 'messages').length, fieldLoadsBefore, 'An unrelated save must reuse its existing field metadata');
    // A slower old tab request must not replace the user's newer tab or the draft edited there.
    const tabConfigResponder = responders.get('list/config/list');
    const oldTabResponse = deferred();
    const currentTabResponse = deferred();
    let tabRead = 0;
    responders.set('list/config/list', async () => {
        const snapshot = plain(configState);
        await (++tabRead === 1 ? oldTabResponse.promise : currentTabResponse.promise);
        return { status: 'ok', error: false, data: snapshot };
    });
    control('lease_time').value = '23';
    await elements.get('config-form').fire('input');
    const openingAttributes = elements.get('tab-attrs').fire('click');
    await flush();
    configState.lease_time = '47'; // The newer read observes a newer server value.
    const openingGeneral = elements.get('tab-general').fire('click');
    await flush();
    control('lease_time').value = '47';
    await elements.get('config-form').fire('input');
    currentTabResponse.resolve();
    await openingGeneral;
    assert.equal(elements.get('tab-general').classList.contains('active'), true);
    assert.equal(control('lease_time').value, '47', 'Edits during the latest tab request must survive its response');
    const currentLeaseControl = control('lease_time');
    currentLeaseControl.value = '61';
    await elements.get('config-form').fire('input');
    oldTabResponse.resolve();
    await openingAttributes;
    assert.equal(elements.get('tab-general').classList.contains('active'), true, 'A late obsolete Attributes response must not reverse the latest General choice');
    assert.equal(elements.get('tab-attrs').classList.contains('active'), false);
    assert.equal(control('lease_time'), currentLeaseControl, 'An obsolete response must not rebuild the current form');
    assert.equal(currentLeaseControl.value, '61', 'Edits made after the latest form loaded must remain untouched');
    assert.equal(elements.get('sync-attrs-btn').style.display, 'none');
    assert.equal(elements.get('config-save-status').textContent, 'Cambios sin guardar.');
    currentLeaseControl.value = '47';
    await elements.get('config-form').fire('input');
    assert.equal(elements.get('config-save-status').textContent, 'Guarda para aplicar los cambios.', 'An obsolete response must not replace the newer saved-value cache');
    responders.set('list/config/list', tabConfigResponder);
    control('lease_time').value = '12.5';
    await navigation[1].fire('click');
    await flush();
    await navigation[2].fire('click');
    await flush();
    assert.equal(control('lease_time').value, '12.5', 'Navigating to another view must preserve the configuration draft');
    await navigation[3].fire('click');
    await flush();
    assert(!tables.get('#jobs-table tbody').children[0].innerHTML.includes('<img'));
    assert.equal(tables.get('#jobs-table tbody').children[0].querySelector('.job-checkbox').value, 'Q1');
    // A failed queue action still has new diagnostic state to display immediately.
    const jobsTranslations = vm.createContext({});
    vm.runInContext(i18nSource, jobsTranslations);
    context.PanelI18n = jobsTranslations.PanelI18n;
    let jobsState = { Q1: { id: 1, device: 10, jobId: 'Q1', username: 'fixture-user', action: 'update',
        state: 'pending', attempts: 0, error: null, nextRetry: null } };
    const jobListResponse = () => ({ status: 'ok', error: false, data: jobsState });
    responders.set('list/jobs/list', jobListResponse);
    responders.set('update/jobs/run', () => {
        jobsState.Q1 = { ...jobsState.Q1, attempts: 4, state: 'pending', error: 'Router fixture failed ' + attack,
            nextRetry: '2026-10-11T10:15:00+00:00' };
        return { status: 'failed', error: true, message: 'jobs_pending_after_failed_attempt' };
    });
    tables.get('#jobs-table tbody').children[0].querySelector('.job-checkbox').checked = true;
    const jobRunRequests = requests.length;
    await elements.get('btn-run-jobs').fire('click');
    assert.deepEqual(requests.slice(jobRunRequests).map(request => request.payload.target + '/' + request.payload.action), ['jobs/run', 'jobs/list'],
        'A failed run must fetch the updated queue instead of throwing before its refresh');
    const failedJobMarkup = tables.get('#jobs-table tbody').children[0].innerHTML;
    assert(failedJobMarkup.includes('Router fixture failed &quot;&gt;&lt;img') && !failedJobMarkup.includes('<img'),
        'New job errors must be shown immediately as escaped text');
    assert(failedJobMarkup.includes('Intentos: 4') && failedJobMarkup.includes('Pendiente')
        && failedJobMarkup.includes('Próximo intento') && failedJobMarkup.includes('data-date="2026-10-11T10:15:00+00:00"'),
        'The failed attempt lost its new attempt count, state or scheduled retry');
    assert.equal(elements.get('jobs-selected').textContent, 'Seleccionados: 1', 'Refreshing a failed pending job must preserve its selection');
    assert.equal(elements.get('jobs-select-all').checked, true);
    assert.equal(elements.get('btn-run-jobs').disabled, false);
    assert.equal(elements.get('loader').style.display, 'none');
    const jobFailureToast = elements.get('notifications').lastElementChild;
    assert.equal(jobFailureToast.role, 'alert');
    assert.match(jobFailureToast.children[0].textContent, /El intento falló.*último error/);
    assert(!jobFailureToast.children[0].textContent.includes('jobs_pending_after_failed_attempt'));

    // A refresh error must not replace the original action error or clear the last known rows.
    responders.set('update/jobs/run', { status: 'failed', error: true, message: 'jobs_are_pending_or_running' });
    responders.set('list/jobs/list', { status: 'failed', error: true, message: 'secondary refresh fixture failure' });
    const retainedJobRow = tables.get('#jobs-table tbody').children[0];
    retainedJobRow.querySelector('.job-checkbox').checked = true;
    await elements.get('btn-run-jobs').fire('click');
    assert.equal(tables.get('#jobs-table tbody').children[0], retainedJobRow);
    const combinedJobError = elements.get('notifications').lastElementChild.children[0].textContent;
    assert.match(combinedJobError, /Quedan trabajos pendientes o en ejecución/);
    assert.match(combinedJobError, /No se pudo actualizar la lista de trabajos/);
    assert(!combinedJobError.includes('secondary refresh fixture failure'), 'A secondary fetch error must not replace the original queue outcome');

    // Deletion failures can also change queue state and must refresh it after confirmation.
    responders.set('list/jobs/list', jobListResponse);
    responders.set('update/jobs/delete', () => {
        jobsState.Q1 = { ...jobsState.Q1, attempts: 5, state: 'running', error: 'delete fixture retained job' };
        return { status: 'failed', error: true, message: 'jobs_are_pending_or_running' };
    });
    const deleteJobRequests = requests.length;
    const deletingJob = elements.get('btn-delete-jobs').fire('click');
    await flush();
    const deleteJobConfirmation = document.body.children.find(node => node.className === 'confirm-overlay');
    assert(deleteJobConfirmation);
    await deleteJobConfirmation.children[0].children[1].children[1].fire('click');
    await deletingJob;
    assert.deepEqual(requests.slice(deleteJobRequests).map(request => request.payload.target + '/' + request.payload.action), ['jobs/delete', 'jobs/list']);
    const deleteJobMarkup = tables.get('#jobs-table tbody').children[0].innerHTML;
    assert(deleteJobMarkup.includes('delete fixture retained job') && deleteJobMarkup.includes('Intentos: 5')
        && deleteJobMarkup.includes('En ejecución'), 'A failed deletion left stale queue details visible');
    assert.match(elements.get('notifications').lastElementChild.children[0].textContent, /Quedan trabajos pendientes o en ejecución/);
    assert.equal(elements.get('btn-delete-jobs').disabled, false);

    // New operation envelopes retain actual attempts/blockers/refusals, including HTTP failures.
    const jobMeta = (id, extra = {}) => ({ jobId: id, serviceId: 1420, deviceId: 1, state: 'pending', attempts: 0,
        nextRetry: null, error: null, blockedBy: null, blockedReason: null, canDelete: false,
        deleteBlockedReason: 'prepared', ...extra });
    const jobSummary = (requested, extra = {}) => ({ requested, attempted: 0, succeeded: 0, pending: 0,
        failed: 0, deleted: 0, refused: 0, ...extra });
    async function showJobFixtures(state) {
        jobsState = state;
        responders.set('list/jobs/list', jobListResponse);
        await navigation[3].fire('click'); await flush();
    }
    function selectJobFixtures(ids) {
        for (const row of tables.get('#jobs-table tbody').children) {
            const checkbox = row.querySelector('.job-checkbox');
            checkbox.checked = ids.includes(checkbox.value);
            assert.notEqual(checkbox.disabled, true, 'A prepared or blocked job must remain selectable for execution');
        }
    }
    async function jobEnvelopeAction(action, response, ids, status = 200, refreshFailure = false) {
        const responseData = response.data;
        responders.set('update/jobs/' + action, () => {
            for (const [key, job] of Object.entries(responseData?.jobs || {})) {
                if ((action === 'delete' && responseData.results[key] === true)
                    || ['succeeded', 'cleaned', 'deleted', 'cancelled', 'superseded', 'missing'].includes(job.state)) delete jobsState[key];
                else jobsState[key] = { ...jobsState[key], ...job };
            }
            return response;
        });
        responseStatuses.set('update/jobs/' + action, status);
        if (refreshFailure) responders.set('list/jobs/list', { status: 'failed', error: true, message: 'secondary refresh fixture failure' });
        selectJobFixtures(ids);
        const requestStart = requests.length;
        const running = elements.get(action === 'run' ? 'btn-run-jobs' : 'btn-delete-jobs').fire('click');
        if (action === 'delete') {
            await flush();
            const confirmation = document.body.children.find(node => node.className === 'confirm-overlay');
            assert(confirmation, 'Job deletion must retain its existing confirmation');
            await confirmation.children[0].children[1].children[1].fire('click');
        }
        await running;
        assert.deepEqual(requests.slice(requestStart).map(request => request.payload.target + '/' + request.payload.action),
            ['jobs/' + action, 'jobs/list'], 'Every failed/partial operation must refresh job diagnostics');
        assert.deepEqual(requests[requestStart].payload, { changeType: 'update', target: 'jobs', action, data: ids },
            'UI must send only selected IDs; same-service prerequisite selection belongs to the backend');
        responseStatuses.delete('update/jobs/' + action);
        responders.set('list/jobs/list', jobListResponse);
        assert.equal(elements.get('loader').style.display, 'none');
        assert.equal(elements.get(action === 'run' ? 'btn-run-jobs' : 'btn-delete-jobs').disabled, false);
        return elements.get('notifications').lastElementChild;
    }
    await showJobFixtures({
        Q15: jobMeta('Q15', { serviceId: 577, deviceId: 6, attempts: 7, error: 'device connect failed', canDelete: true, deleteBlockedReason: null }),
        Q16: jobMeta('Q16', { serviceId: 678, deviceId: 6, attempts: 7, error: 'device connect failed', canDelete: true, deleteBlockedReason: null })
    });
    assert(tables.get('#jobs-table tbody').children.every(row => !row.innerHTML.includes('No se puede eliminar')),
        'Previously attempted pending jobs must show the backend cancellation eligibility');
    const cancelledAttempts = await jobEnvelopeAction('delete', { status: 'ok', error: false, data: {
        operation: 'delete', requested: ['Q15', 'Q16'], results: { Q15: true, Q16: true },
        jobs: { Q15: jobMeta('Q15', { state: 'cancelled', attempts: 7, deleteBlockedReason: 'not_pending' }),
            Q16: jobMeta('Q16', { state: 'cancelled', attempts: 7, deleteBlockedReason: 'not_pending' }) },
        summary: jobSummary(2, { deleted: 2 }) } }, ['Q15', 'Q16']);
    assert(cancelledAttempts.children[0].textContent.includes('2 eliminados · 0 conservados.'),
        'Cancelling attempted pending work must report actual removals');
    assert.equal(Object.keys(jobsState).length, 0, 'Cancelled attempted jobs must disappear without generating replacement jobs');
    assert(!tables.get('#jobs-table tbody').children.some(row => row.querySelector('.job-checkbox')),
        'The refreshed table must not retain cancelled rows');
    await showJobFixtures({ Q1: jobMeta('Q1'), Q2: jobMeta('Q2', { blockedBy: 'Q1', blockedReason: 'earlier_job' }) });
    const blockerMarkup = tables.get('#jobs-table tbody').children[1].innerHTML;
    assert(blockerMarkup.includes('Espera a Q1 del mismo servicio.') && blockerMarkup.includes('No se puede eliminar')
        && blockerMarkup.includes('Puede haber cambios previos en el router. Resuelve el último error'), 'Rows must explain execution and delete barriers before a click');
    assert(markup.includes('Ejecutar procesa también los trabajos anteriores del mismo servicio.'), 'Execution help must explain prerequisite work');
    assert(markup.includes('Eliminar cancela los pendientes sin cambiar el router y conserva información para una futura sincronización.')
        && markup.includes('Los trabajos en ejecución deben terminar primero.'),
        'General help must distinguish pending cancellation from running work');
    assert(!markup.includes('Los trabajos preparados se conservan para reconciliar el router.'));
    const connectionDiagnostics = [
        ['device connect failed', 'Puerto accesible sólo comprueba que el puerto responde.'],
        ['router_connection_failed: unknown_fixture', 'Revisa usuario, contraseña, permisos de API y TLS'],
        ['router_tcp_connect_failed', 'Revisa dirección, puerto y acceso desde el servidor de UISP.'],
        ['router_tls_connect_failed', 'Revisa dirección, puerto, opciones TLS y certificados'],
        ['router_login_rejected', 'RouterOS rechazó el acceso por API.'],
        ['router_login_timeout', 'antes del tiempo límite'],
        ['router_login_connection_closed', 'La conexión se cerró durante el acceso por API.'],
        ['router_login_protocol_error', 'La respuesta del acceso por API no se pudo interpretar.'],
        ['router_login_write_failed', 'No se pudo enviar la solicitud de acceso por API.']
    ];
    for (const [code, guidance] of connectionDiagnostics) {
        await showJobFixtures({ Q1: jobMeta('Q1', { error: code }) });
        const row = tables.get('#jobs-table tbody').children[0].innerHTML;
        assert(row.includes(code) && row.includes(guidance), 'Connection errors must retain their raw code with specific diagnostic guidance: ' + code);
    }
    await showJobFixtures({ Q1: jobMeta('Q1', { error: 'router_connection_failed: ' + attack }) });
    const connectionAttack = tables.get('#jobs-table tbody').children[0].innerHTML;
    assert(connectionAttack.includes('Puerto accesible sólo comprueba') && !connectionAttack.includes('<img'));
    await showJobFixtures({ Q1: jobMeta('Q1', { error: 'constructor' }) });
    assert(!tables.get('#jobs-table tbody').children[0].innerHTML.includes('Puerto accesible sólo comprueba'),
        'Unrelated errors must not be classified as connection failures');
    const connectionFailure = await jobEnvelopeAction('run', { status: 'failed', error: true, data: {
        operation: 'run', requested: ['Q1'], results: { Q1: { success: false, error: 'router_login_rejected', attempted: true } },
        jobs: { Q1: jobMeta('Q1', { error: 'router_login_rejected', attempts: 1 }) },
        summary: jobSummary(1, { attempted: 1, failed: 1, pending: 1 }) } }, ['Q1']);
    assert(connectionFailure.children[0].textContent.includes('router_login_rejected')
        && connectionFailure.children[0].textContent.includes('RouterOS rechazó el acceso por API.'));
    await showJobFixtures({ Q1: jobMeta('Q1'), Q2: jobMeta('Q2', { blockedBy: 'Q1', blockedReason: 'earlier_job' }) });
    const attemptError = 'Router actual fixture failed ' + attack;
    const actualFailure = await jobEnvelopeAction('run', { status: 'failed', error: true, message: 'jobs_pending_after_failed_attempt', data: {
        operation: 'run', requested: ['Q1'], results: { Q1: { success: false, error: attemptError } },
        jobs: { Q1: jobMeta('Q1', { attempts: 6, error: attemptError, nextRetry: '2026-10-11T10:30:00+00:00' }) },
        summary: jobSummary(1, { attempted: 1, pending: 1, failed: 1 }) } }, ['Q1'], 502);
    assert.equal(actualFailure.role, 'alert');
    assert(actualFailure.children[0].textContent.includes('1 intentados') && actualFailure.children[0].textContent.includes('Q1: El intento falló.'));
    assert(actualFailure.children[0].textContent.includes(attemptError), 'HTTP 502 must retain the concrete returned job error');
    assert(!renderedMarkup(actualFailure).includes('<img') && !renderedMarkup(actualFailure).includes('<script'));
    assert(tables.get('#jobs-table tbody').children[0].innerHTML.includes('Intentos: 6'));
    const noAttempt = await jobEnvelopeAction('run', { status: 'failed', error: true, message: 'jobs_are_pending_or_running', data: {
        operation: 'run', requested: ['Q2'], results: { Q2: { success: false, error: null, attempted: false } }, jobs: { Q2: jobMeta('Q2', { blockedBy: 'Q1', blockedReason: 'earlier_job' }) },
        summary: jobSummary(1, { pending: 1 }) } }, ['Q2']);
    assert.equal(noAttempt.role, 'alert');
    assert(noAttempt.children[0].textContent.includes('0 intentados') && noAttempt.children[0].textContent.includes('Q2: Espera a Q1 del mismo servicio.'),
        'An unattempted selection must identify its earlier job instead of a generic error');
    await showJobFixtures({ Q1: jobMeta('Q1'), Q3: jobMeta('Q3', { canDelete: true, deleteBlockedReason: null }) });
    const partialDelete = await jobEnvelopeAction('delete', { status: 'failed', error: true, message: 'jobs_are_pending_or_running', data: {
        operation: 'delete', requested: ['Q1', 'Q3'], results: { Q1: false, Q3: true },
        jobs: { Q1: jobMeta('Q1'), Q3: jobMeta('Q3', { state: 'cancelled', canDelete: false, deleteBlockedReason: 'not_pending' }) },
        summary: jobSummary(2, { pending: 1, deleted: 1, refused: 1 }) } }, ['Q1', 'Q3']);
    assert.equal(partialDelete.role, 'alert');
    assert(partialDelete.children[0].textContent.includes('1 eliminados · 1 conservados.')
        && partialDelete.children[0].textContent.includes('Q1: Puede haber cambios previos'), 'Partial deletion must distinguish deleted IDs from protected snapshots');
    assert.equal(tables.get('#jobs-table tbody').children.length, 1);
    assert.equal(tables.get('#jobs-table tbody').children[0].querySelector('.job-checkbox').value, 'Q1');
    const contradictoryDelete = await jobEnvelopeAction('delete', { status: 'ok', error: false, data: {
        operation: 'delete', requested: ['Q1'], results: { Q1: false }, jobs: { Q1: jobMeta('Q1') },
        summary: jobSummary(1, { deleted: 1 }) } }, ['Q1']);
    assert.equal(contradictoryDelete.role, 'alert');
    assert(contradictoryDelete.children[0].textContent.includes('Respuesta inválida del servidor.'), 'Contradictory deletion totals must not claim the guarded job was deleted');
    const failedRefresh = await jobEnvelopeAction('run', { status: 'failed', error: true, message: 'jobs_pending_after_failed_attempt', data: {
        operation: 'run', requested: ['Q1'], results: { Q1: { success: false, error: attemptError } },
        jobs: { Q1: jobMeta('Q1', { attempts: 9, error: attemptError }) },
        summary: jobSummary(1, { attempted: 1, pending: 1, failed: 1 }) } }, ['Q1'], 502, true);
    assert(failedRefresh.children[0].textContent.includes('Q1: El intento falló.')
        && failedRefresh.children[0].textContent.includes(attemptError)
        && failedRefresh.children[0].textContent.includes('No se pudo actualizar la lista de trabajos'), 'A refresh failure must preserve the returned operation outcome');
    assert(!failedRefresh.children[0].textContent.includes('secondary refresh fixture failure'));
    const retainedDiagnostics = tables.get('#jobs-table tbody').children[0].innerHTML;
    assert(retainedDiagnostics.includes('Intentos: 9') && !retainedDiagnostics.includes('<img'), 'Returned diagnostic state must survive a failed list refresh safely');
    const malformedEnvelope = await jobEnvelopeAction('run', { status: 'ok', error: false, data: {
        operation: 'run', requested: ['Q1'], results: { Q1: { success: true, error: null } }, jobs: {},
        summary: jobSummary(1, { attempted: attack }) } }, ['Q1']);
    assert.equal(malformedEnvelope.role, 'alert');
    assert(malformedEnvelope.children[0].textContent.includes('Respuesta inválida del servidor.'), 'Malformed operation totals must not claim success');
    await showJobFixtures({ Q1: jobMeta('Q1', { error: attack, blockedReason: 'earlier_job', blockedBy: attack }) });
    const hostileJobMarkup = tables.get('#jobs-table tbody').children[0].innerHTML;
    assert(hostileJobMarkup.includes('&lt;img') && !hostileJobMarkup.includes('<img') && !hostileJobMarkup.includes('<script'),
        'Blocker identifiers and error text must remain escaped in job rows');
    await showJobFixtures({ Q1: jobMeta('Q1'), Q2: jobMeta('Q2', { blockedBy: 'Q1', blockedReason: 'earlier_job' }) });
    const prerequisitesDone = await jobEnvelopeAction('run', { status: 'ok', error: false, data: {
        operation: 'run', requested: ['Q2'], results: { Q1: { success: true, error: null, attempted: true }, Q2: { success: true, error: null, attempted: true } },
        jobs: { Q1: jobMeta('Q1', { state: 'succeeded' }), Q2: jobMeta('Q2', { state: 'succeeded' }) },
        summary: jobSummary(1, { attempted: 2, succeeded: 2 }) } }, ['Q2']);
    assert.equal(prerequisitesDone.role, 'status');
    assert(prerequisitesDone.children[0].textContent.includes('2 intentados · 2 completados'), 'Actual prerequisite work may exceed the number of selected jobs');
    assert(tables.get('#jobs-table tbody').innerHTML.includes('No hay trabajos en cola.'));
    await showJobFixtures({ Q2: jobMeta('Q2') });
    const completedElsewhere = await jobEnvelopeAction('run', { status: 'ok', error: false, data: {
        operation: 'run', requested: ['Q2'], results: { Q2: { success: true, error: null, attempted: false } },
        jobs: { Q2: jobMeta('Q2', { state: 'succeeded' }) }, summary: jobSummary(1) } }, ['Q2']);
    assert.equal(completedElsewhere.role, 'status');
    assert(completedElsewhere.children[0].textContent.includes('Q2: ya estaba completado.'), 'A concurrent completion must explain why this request performed no attempt');
    await showJobFixtures({ Q3: jobMeta('Q3', { canDelete: true, deleteBlockedReason: null }) });
    const deleteCompleted = await jobEnvelopeAction('delete', { status: 'ok', error: false, data: {
        operation: 'delete', requested: ['Q3'], results: { Q3: true }, jobs: { Q3: jobMeta('Q3', { state: 'cancelled' }) },
        summary: jobSummary(1, { deleted: 1 }) } }, ['Q3']);
    assert.equal(deleteCompleted.role, 'status');
    assert(deleteCompleted.children[0].textContent.includes('1 eliminados · 0 conservados.'));
    const cascadeIds = ['Q10', 'Q11', 'Q12', 'Q13'];
    await showJobFixtures(Object.fromEntries(cascadeIds.map((key, index) => [key,
        jobMeta(key, { canDelete: index === 0, deleteBlockedReason: index === 0 ? null : 'prepared',
            blockedBy: index === 0 ? null : 'Q10', blockedReason: index === 0 ? null : 'earlier_job' })])));
    const cascadeCancelled = await jobEnvelopeAction('delete', { status: 'ok', error: false, data: {
        operation: 'delete', requested: cascadeIds, results: Object.fromEntries(cascadeIds.map(key => [key, true])),
        jobs: Object.fromEntries(cascadeIds.map(key => [key, jobMeta(key, { state: 'cancelled', deleteBlockedReason: 'not_pending' })])),
        summary: jobSummary(4, { deleted: 4 }) } }, cascadeIds);
    assert.equal(cascadeCancelled.role, 'status');
    assert(cascadeCancelled.children[0].textContent.includes('4 eliminados · 0 conservados.'),
        'Selected successors cancelled in cascade must count as deleted instead of protected/refused');
    assert(tables.get('#jobs-table tbody').innerHTML.includes('No hay trabajos en cola.'));
    // Restore a single legacy row to retain compatibility assertions below.
    await showJobFixtures({ Q1: { id: 1, device: 10, jobId: 'Q1', username: 'fixture-user', action: 'update', state: 'pending', attempts: 0, error: null } });

    responders.set('update/jobs/run', () => {
        jobsState = {};
        return { status: 'ok', error: false, data: {} };
    });
    tables.get('#jobs-table tbody').children[0].querySelector('.job-checkbox').checked = true;
    await elements.get('btn-run-jobs').fire('click');
    assert(tables.get('#jobs-table tbody').innerHTML.includes('No hay trabajos en cola.'));
    assert.equal(elements.get('jobs-total').textContent, 'Total: 0');
    assert.equal(elements.get('jobs-selected').textContent, 'Seleccionados: 0');
    assert.equal(elements.get('notifications').lastElementChild.role, 'status');
    assert.equal(elements.get('notifications').lastElementChild.children[0].textContent, 'Trabajos procesados.');
    assert.equal(elements.get('btn-run-jobs').disabled, false);
    assert.equal(elements.get('loader').style.display, 'none');
    delete context.PanelI18n;
    await navigation[4].fire('click');
    await flush();
    assert(!elements.get('system-info-container').innerHTML.includes('<img'));
    assert.equal(elements.get('backups-grid').children.length, 1);
    assert.equal(elements.get('backups-grid').children[0].children[1].children[1].href, 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?download=backup-1');
    async function rebuildResponse(response, status = 200) {
        responders.set('update/system/insert', response);
        responseStatuses.set('update/system/insert', status);
        const running = elements.get('btn-rebuild').fire('click');
        await flush();
        const confirmation = document.body.children.find(node => node.className === 'confirm-overlay');
        assert(confirmation, 'Bulk provisioning must retain its confirmation');
        await confirmation.children[0].children[1].children[1].fire('click');
        await running;
        responseStatuses.delete('update/system/insert');
        assert.equal(elements.get('loader').style.display, 'none');
        assert.equal(elements.get('btn-rebuild').disabled, false);
        return elements.get('notifications').lastElementChild;
    }
    const partialToast = await rebuildResponse({ status: 'failed', error: true, message: 'provisioning_incomplete',
        data: { requested: 3, completed: 2, failed: 1,
            failures: [{ serviceId: 1353, planId: 35, field: 'downloadSpeed', message: attack }] } }, 502);
    assert.equal(partialToast.role, 'alert', 'A partially provisioned batch must show an error, never success');
    assert.match(partialToast.children[0].textContent, /2 de 3 servicios completados; 1 pendientes o con error/);
    assert.match(partialToast.children[0].textContent, /Servicio 1353 · plan 35 · campo downloadSpeed/);
    assert.match(partialToast.children[0].textContent, /Revisa Trabajos y el registro/);
    assert(!partialToast.children[0].textContent.includes('sincronización completadas'), 'A partial batch must not claim all services were synchronized');
    assert(!partialToast.children[0].textContent.includes(attack), 'Raw internal failure messages must not enter the notification');
    const maliciousFieldToast = await rebuildResponse({ status: 'failed', error: true, message: 'provisioning_incomplete',
        data: { requested: 8, completed: 1, failed: 7,
            failures: Array.from({ length: 7 }, (_, index) => ({ serviceId: 1400 + index, planId: 35, field: attack })) } });
    assert.equal(maliciousFieldToast.role, 'alert');
    assert(maliciousFieldToast.children[0].textContent.includes('campo ' + attack), 'Diagnostic fields must remain literal text');
    assert(!renderedMarkup(maliciousFieldToast).includes('<img') && !renderedMarkup(maliciousFieldToast).includes('<script'));
    assert(maliciousFieldToast.children[0].textContent.includes('Servicio 1405'));
    assert(!maliciousFieldToast.children[0].textContent.includes('Servicio 1406'), 'Partial notifications must show at most six service details');
    assert.match(maliciousFieldToast.children[0].textContent, /Otros 1 servicios con error/);
    const completeToast = await rebuildResponse({ status: 'ok', error: false,
        data: { requested: 3, completed: 3, failed: 0, failures: [] } });
    assert.equal(completeToast.role, 'status');
    assert.match(completeToast.children[0].textContent, /Servicios completados: 3 de 3/);
    const malformedCountsToast = await rebuildResponse({ status: 'ok', error: false,
        data: { requested: 3, completed: 2, failed: 0, failures: [] } });
    assert.equal(malformedCountsToast.role, 'alert', 'Inconsistent totals must not produce a false successful completion');
    assert.match(malformedCountsToast.children[0].textContent, /Respuesta inválida del servidor/);
    assert.equal(formControls(elements.get('config-fields-container')).find(field => field.name === 'lease_time').value, '12.5', 'Provisioning feedback must preserve configuration drafts');
    responders.set('update/system/cache', { status: 'accepted', error: false, accepted: true });
    await elements.get('btn-cache').fire('click');
    const pendingToast = elements.get('notifications').lastElementChild;
    assert.equal(pendingToast.role, 'status');
    assert(pendingToast.children[0].textContent.includes('Solicitud aceptada.'));
    responders.set('update/system/cache', { status: 'failed', error: true, message: 'cache fixture failed ' + attack });
    const notificationsBefore = elements.get('notifications').children.length;
    await elements.get('btn-cache').fire('click');
    assert.equal(elements.get('notifications').children.length, notificationsBefore + 1);
    const errorToast = elements.get('notifications').lastElementChild;
    assert.equal(errorToast.role, 'alert');
    assert.equal(errorToast.children[0].textContent, 'Error: cache fixture failed ' + attack, 'Untrusted error text must stay literal text');
    assert(!renderedMarkup(errorToast).includes('<img'));
    assert(!renderedMarkup(errorToast).includes('<script'));
    assert.equal(elements.get('loader').style.display, 'none');
    assert.equal(elements.get('btn-cache').disabled, false);
    responders.set('list/devices/list', () => { throw new Error('offline fixture'); });
    await navigation[0].fire('click');
    await flush();
    assert.equal(elements.get('loader').style.display, 'none');
    assert(elements.get('notifications').lastElementChild.children[0].textContent.includes('offline fixture'));
    for (const [id, timer] of Array.from(timers)) {
        timers.delete(id);
        timer.callback();
    }
    assert(elements.get('notifications').children.every(toast => toast.role === 'alert'), 'Only persistent errors should remain after successful-notification timers');
    assert(elements.get('notifications').children.includes(errorToast), 'Errors must remain visible until dismissed');
    assert(elements.get('notifications').children.includes(partialToast)
        && elements.get('notifications').children.includes(maliciousFieldToast), 'Partial provisioning notices must remain visible until dismissed');
    const errorsBeforeDismiss = elements.get('notifications').children.length;
    await errorToast.children[1].fire('click');
    assert.equal(elements.get('notifications').children.length, errorsBeforeDismiss - 1);
    assert(!elements.get('notifications').children.includes(errorToast));
    assert.equal(alerts.length, 0, 'Routine feedback must not require accepting a browser alert');
    assert(!/\bonclick\s*=/.test(appSource));
    assert(!/fetch\(\s*['"](?:api_update\.php|\.\.\/public\.php)/.test(appSource));
    assert(!markup.includes('unpkg.com'));
    assert(requests.every(request => request.endpoint === '/crm/_plugins/ros-plugin/public.php'));
    await checkLegacyPlanEditor({ missingBurstMarkup: true, missingBurstHelpers: false });
    await checkLegacyPlanEditor({ missingBurstMarkup: false, missingBurstHelpers: true });
    await checkLegacyPlanEditor({ missingBurstMarkup: true, missingBurstHelpers: true });
    await checkContentionPlanEditors();
    await checkLanguagePresentation();
    await checkNumericPlanEditors();
    await checkJobsRefresh();
    await checkNetworkRecoveryPanel();
    await checkNetworkCleanupPanel();
    console.log('Panel regression checks passed: recovery navigation, escaped owner/status diagnostics, same-origin CRM links, explicit single-service verification without blocking navigation, update/upload guards and diagnostic downloads; partial provisioning counts/diagnostics/persistence and full totals, API contracts, old HTML/API manual editors, saved global contention flags and drafts, legacy zero ratios, configuration keyboard drafts during pending saves/reloads, language changes preserving open modal/custom values/scroll and asynchronous settings drafts, stale language response rejection, legacy global language preservation, inactive control omission, configuration failure recovery, stable local editor visibility, deferred plan saves and failure drafts, burst templates and math, custom plans, legacy sentinels, drafts, one-click saves, nonblocking notifications, safe text, numeric types, overrides, TLS, backup routes and failure cleanup.');
})().catch(error => { console.error(error); process.exitCode = 1; });
