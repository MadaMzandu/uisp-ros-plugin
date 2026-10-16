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
        autoTimers() { return [...clock.values()].filter(timer => [5000, 10000, 30000, 60000].includes(timer.delay) && !['pollCrmMonitor', 'pollJobsStatus'].includes(timer.callback.name)); },
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

function recoveryPanelFixture({ bootstrap, details, storage, storageBlocked = false, mobile = false,
    initialReplies = new Map(), url = 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?page=panel', raf = false, exposeRenderers = false,
    navigationType = 'reload', legacyNavigationType, workflows } = {}) {
    const nodes = new Map(Array.from(markup.matchAll(/\bid="([^"]+)"/g), match => [match[1], new Element(match[1])]));
    const closeButtons = Array.from(markup.matchAll(/<button\b[^>]*class="[^"]*\bclose-modal\b[^"]*"/g), () => new Element());
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
    const replies = new Map(initialReplies);
    const events = new Map();
    const documentEvents = new Map();
    const frames = [];
    let onReady, reloads = 0;
    let timerSequence = 0, now = 0;
    const clock = new Map();
    const localDocument = {
        baseURI: document.baseURI, body: new Element(), hidden: false, scrollingElement: new Element(),
        getElementById(id) { return nodes.get(id) || null; },
        addEventListener(type, listener) {
            if (type === 'DOMContentLoaded') onReady = listener;
            else { if (!documentEvents.has(type)) documentEvents.set(type, []); documentEvents.get(type).push(listener); }
        },
        removeEventListener(type, listener) { documentEvents.set(type, (documentEvents.get(type) || []).filter(item => item !== listener)); },
        createElement(tag) {
            const element = new Element(); element.tagName = tag.toUpperCase();
            element.focus = options => { element.focusOptions = options; localDocument.activeElement = element; };
            element.getBoundingClientRect = () => {
                const owners = ['crm-network-conflicts-body', 'crm-network-resolved-body', 'network-conflicts-body'].flatMap(id => nodes.get(id).children
                    .flatMap(row => (row.children[1]?.children || []).filter(item => item.dataset.serviceId)));
                const index = owners.indexOf(element);
                const surface = mobile ? localDocument.scrollingElement : nodes.get('content-area');
                const top = (mobile ? 0 : 100) + 30 + index * 140 - surface.scrollTop;
                return { top, bottom: top + 130, height: 130 };
            };
            return element;
        },
        createElementNS() { return new Element(); },
        querySelector(selector) { assert(localTables.has(selector)); return localTables.get(selector); },
        querySelectorAll(selector) {
            if (selector === '#network-recovery-state') return bootstrap === undefined ? [] : [script];
            if (selector === '.nav-item') return nav;
            if (selector === '.view-section') return views.map(view => nodes.get('view-' + view));
            if (selector === '.close-modal') return closeButtons;
            if (selector === '[data-recovery-write]') return allNodes().filter(node => node.dataset.recoveryWrite === 'true');
            if (selector === '[data-recovery-router-read]') return allNodes().filter(node => node.dataset.recoveryRouterRead === 'true');
            if (selector === '#device-form input, #device-form select, #plan-form input, #plan-form select, #config-form input, #config-form select, .burst-preset-btn')
                return [...deviceControls, ...nodes.get('plan-form').controlsRoot.markupControls, ...formControls(nodes.get('config-fields-container'))];
            return [];
        }
    };
    localDocument.activeElement = localDocument.body;
    for (const surface of [nodes.get('content-area'), localDocument.scrollingElement]) {
        surface.scrollTop = 0; surface.scrollHeight = 10000; surface.clientHeight = 500;
        surface.getBoundingClientRect = () => ({ top: 100, bottom: 600, height: 500 });
    }
    const realReplace = nodes.get('network-conflicts-body').replaceChildren.bind(nodes.get('network-conflicts-body'));
    nodes.get('network-conflicts-body').replaceChildren = (...children) => {
        if (walk(nodes.get('network-conflicts-body')).includes(localDocument.activeElement)) localDocument.activeElement = localDocument.body;
        return realReplace(...children);
    };
    const localContext = vm.createContext({
        URL, FormData: FixtureFormData, document: localDocument,
        ...(workflows ? { PanelWorkflows: workflows } : {}),
        ...(navigationType !== null ? { performance: { getEntriesByType: name => name === 'navigation' ? [{ type: navigationType }] : [] } }
            : legacyNavigationType !== undefined ? { performance: { navigation: { type: legacyNavigationType } } } : {}),
        window: { innerHeight: 500, location: { href: url, reload() { reloads++; } },
            getComputedStyle() { return { overflowY: mobile ? 'visible' : 'auto' }; },
            addEventListener(type, listener) { if (!events.has(type)) events.set(type, []); events.get(type).push(listener); }
        },
        ...(storage !== undefined || storageBlocked ? { sessionStorage: {
            getItem(key) { if (storageBlocked) throw new Error('blocked'); return storage?.get(key) ?? null; },
            setItem(key, value) { if (storageBlocked) throw new Error('blocked'); storage?.set(key, value); }
        } } : {}),
        ...(raf ? { requestAnimationFrame(callback) { frames.push(callback); return frames.length; } } : {}),
        setTimeout(callback, delay) { const id = ++timerSequence; clock.set(id, { callback, delay, due: now + delay }); return id; },
        clearTimeout(id) { clock.delete(id); },
        fetch: async (endpoint, options) => {
            const payload = typeof options.body === 'string' ? JSON.parse(options.body) : { changeType: 'upload' };
            calls.push(payload);
            const key = payload.changeType + '/' + payload.target;
            let response;
            if (replies.has(key)) {
                const reply = replies.get(key);
                response = await (typeof reply === 'function' ? reply(payload) : reply);
            }
            else {
                const data = {
                    devices: [{ id: 1, name: 'Router de prueba', ip: '192.0.2.1', status: null }],
                    plans: [{ id: 1, name: 'Plan de prueba', ratio: 1, uploadSpeed: 5, downloadSpeed: 20 }],
                    config: { disable_contention: false }, jobs: {},
                    job_recovery: { available: true, groups: [], total: 0, has_more: false, checked_at: '2026-10-14T13:00:00Z', reason: null },
                    messages: [{}, { config_disable_contention: { name: 'disable_contention', label: 'Desactivar contención', type: 1 } }],
                    sys: { app_version: '2.4.21', php_version: '8.5' }, backups: [{ name: 'backup-0', date: '2026-10-14' }],
                    network_conflicts: details,
                    crm_network_conflicts: { available: false, duplicate_groups: 0, conflicts: [], checked_at: null, scan_in_progress: false, last_error: null, stale: false, families: [] }
                }[payload.target] ?? [];
                response = { status: 'ok', error: false, data, ...(payload.target === 'network_conflicts' ? { recovery: { readOnly: true } } : {}) };
            }
            return { ok: true, status: 200, json: async () => plain(response) };
        }
    });
    const fixtureApp = exposeRenderers ? appSource.replace('    api.onRecovery?.(applyRecovery);', '    globalThis.__fixtureRefresh = { devices: renderDevices, plans: renderPlans, system: renderSystem };\n    api.onRecovery?.(applyRecovery);') : appSource;
    vm.runInContext(apiSource, localContext); vm.runInContext(fixtureApp, localContext); onReady();
    return { nodes, calls, document: localDocument, tables: localTables, deviceControls, closeButtons, api: vm.runInContext('api', localContext),
        async navigate(view) { await nav[views.indexOf(view)].fire('click'); await flush(); },
        reply(key, response) { replies.set(key, typeof response === 'function' ? response : () => response); },
        reloads() { return reloads; },
        writes() { return calls.filter(call => call.changeType === 'update' || call.changeType === 'upload'); },
        diagnostics() { return calls.filter(call => call.target === 'network_conflicts'); },
        async event(type) { for (const listener of events.get(type) || []) listener(); await flush(); },
        async documentEvent(type, event = {}) { for (const listener of [...(documentEvents.get(type) || [])]) listener(event); await flush(); },
        async hidden(value) { localDocument.hidden = value; for (const listener of documentEvents.get('visibilitychange') || []) listener(); await flush(); },
        async tick(milliseconds) {
            const end = now + milliseconds;
            while (true) {
                const next = [...clock].filter(([, timer]) => timer.due <= end).sort((a, b) => a[1].due - b[1].due)[0];
                if (!next) break;
                now = next[1].due; clock.delete(next[0]); next[1].callback(); await flush();
            }
            now = end;
        },
        monitorCalls() { return calls.filter(call => call.target === 'crm_network_conflicts'); },
        monitorTimers() { return [...clock.values()].filter(timer => timer.callback.name === 'pollCrmMonitor'); },
        async runFrames() { for (const callback of frames.splice(0)) callback(); await flush(); },
        surface() { return mobile ? localDocument.scrollingElement : nodes.get('content-area'); },
        refresh(view) { return localContext.__fixtureRefresh[view](); }
    };
}

async function checkNonblockingDeviceSync() {
    const devices = [{ id: 1, name: 'Router A', ip: '192.0.2.1', status: 1, users: 1 },
        { id: 2, name: 'Router B', ip: '192.0.2.2', status: 1, users: 2 }];
    const reviewed = [];
    const fixture = recoveryPanelFixture({ exposeRenderers: true,
        workflows: { mount() {}, onView() {}, previewSync(device) { reviewed.push(device.id); } },
        initialReplies: new Map([['list/devices', { status: 'ok', error: false, data: devices }]]) });
    await flush();
    const rows = () => fixture.tables.get('#devices-table tbody').children;
    const row = id => rows().find(item => Number(item.dataset.deviceId) === id);
    const buttons = id => row(id).querySelector('.row-actions').children;
    const syncCalls = () => fixture.calls.filter(call => call.target === 'device_sync' && call.action === 'begin');
    const reviewButton = id => buttons(id).find(button => button['aria-label'] === 'Revisar diferencias');
    assert.equal(buttons(1)[2].className, 'btn btn-primary row-action', 'Immediate sync remains the primary router action');
    assert.equal(buttons(1)[2].dataset.recoveryWrite, 'true');
    assert.equal(buttons(1)[2].dataset.recoveryRouterRead, undefined);
    const oldReview = reviewButton(1);
    assert.equal(oldReview.className, 'btn btn-secondary row-action');
    assert.equal(oldReview.dataset.recoveryRouterRead, 'true');
    assert.equal(oldReview.dataset.recoveryWrite, undefined, 'Review must remain a consultation action');
    const beforeReview = fixture.calls.length;
    await oldReview.fire('click');
    assert.deepEqual(reviewed, [1]);
    assert.equal(fixture.calls.length, beforeReview, 'Opening a review cannot submit direct synchronization');
    assert(!fixture.document.body.children.some(node => node.className === 'confirm-overlay'), 'Review does not ask to reconnect sessions');
    const confirm = async accept => {
        const dialog = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
        assert(dialog, 'Synchronization must preserve its reconnection confirmation');
        await dialog.children[0].children[1].children[accept ? 1 : 0].fire('click');
        await flush();
    };
    const pending = new Map([[1, deferred()], [2, deferred()]]);
    const protocol = (id, data = {}) => ({ status: 'ok', error: false, data: { syncToken: String(id).repeat(64),
        phase: 'applying', done: false, processed: 0, total: 2, requested: 0, completed: 0, failed: 0, catalogScanned: 2, ...data } });
    fixture.reply('update/device_sync', payload => payload.action === 'begin'
        ? protocol(payload.data.deviceId) : pending.get(Number(payload.data.syncToken[0])).promise);
    const loaderWrites = fixture.nodes.get('loader').styleWrites.length;
    const oldFirst = buttons(1)[2];
    const first = oldFirst.fire('click');
    await flush();
    assert.equal(syncCalls().length, 0);
    await oldFirst.fire('click');
    await buttons(1)[2].fire('click');
    assert.equal(fixture.document.body.children.filter(node => node.className === 'confirm-overlay').length, 1,
        'A table rebuild must not allow another confirmation for the same device');
    await confirm(true);
    assert.equal(syncCalls().length, 1);
    assert(row(1).innerHTML.includes('device-sync-spinner'));
    assert(row(1).innerHTML.includes('Sincronizando…'));
    assert.equal(row(1)['aria-busy'], 'true');
    assert(buttons(1).every(button => button.disabled), 'Only the active router controls are disabled');
    await oldReview.fire('click'); await reviewButton(1).fire('click');
    assert.deepEqual(reviewed, [1], 'A stale or rebuilt review button cannot inspect a router while its sync is running');
    assert(buttons(2).every(button => !button.disabled), 'Another router must remain available');
    const second = buttons(2)[2].fire('click');
    await flush(); await confirm(true);
    assert.deepEqual(syncCalls().map(call => call.data.deviceId), [1, 2], 'Different router requests may overlap');
    assert(row(2).innerHTML.includes('device-sync-spinner'));
    assert.deepEqual(displayWrites(fixture.nodes.get('loader'), loaderWrites), [], 'Synchronization never opens the global loading overlay');
    fixture.nodes.get('search-devices').value = 'Router A';
    await fixture.nodes.get('search-devices').fire('input');
    assert.equal(rows().length, 1);
    assert(row(1).innerHTML.includes('device-sync-spinner'), 'Filtering must retain active progress');
    fixture.nodes.get('search-devices').value = '';
    await fixture.nodes.get('search-devices').fire('input');
    await buttons(1)[2].fire('click');
    assert.equal(syncCalls().length, 2, 'A replacement button cannot submit the same active router again');
    await fixture.navigate('jobs');
    assert.equal(fixture.nodes.get('view-jobs').style.display, 'block');
    assert.equal(fixture.nodes.get('loader').style.display, 'none', 'Navigation completes while routers remain active');
    await fixture.navigate('devices');
    assert(row(1).innerHTML.includes('device-sync-spinner'));
    assert(row(2).innerHTML.includes('device-sync-spinner'), 'Busy state survives navigation back to Devices');
    pending.get(2).resolve(protocol(2, { phase: 'complete', done: true, processed: 2,
        requested: 2, completed: 1, failed: 1, failures: [{ serviceId: 42 }] }));
    await second; await flush();
    assert(!row(2).innerHTML.includes('device-sync-spinner'));
    assert(buttons(2).every(button => !button.disabled));
    assert(row(1).innerHTML.includes('device-sync-spinner'), 'One completion must retain another active router');
    const partial = fixture.nodes.get('notifications').lastElementChild;
    assert.equal(partial.role, 'alert');
    assert.match(partial.children[0].textContent, /Router B \(#2\).*1 de 2/);
    await fixture.navigate('jobs');
    pending.get(1).resolve(protocol(1, { phase: 'complete', done: true, processed: 2, requested: 1, completed: 1, failed: 0 }));
    await first; await flush();
    assert.equal(fixture.nodes.get('view-jobs').style.display, 'block', 'A background completion cannot force navigation');
    assert.equal(fixture.nodes.get('view-devices').style.display, 'none');
    assert.match(fixture.nodes.get('notifications').lastElementChild.children[0].textContent, /Router A \(#1\).*Sincronización ejecutada.*confirmación en Trabajos/);
    await fixture.navigate('devices');
    assert(rows().every(item => !item.innerHTML.includes('device-sync-spinner')));
    const beforeCancel = syncCalls().length;
    const canceled = buttons(1)[2].fire('click');
    await flush(); await confirm(false); await canceled;
    assert.equal(syncCalls().length, beforeCancel);
    assert(buttons(1).every(button => !button.disabled));
    await reviewButton(1).fire('click');
    assert.deepEqual(reviewed, [1, 1], 'Review becomes available again after synchronization completes');

    // Independent completion refreshes can return out of order. Preserve the
    // latest requested inventory rather than letting a slow earlier read win.
    const slow = deferred(), newer = deferred();
    const lists = [slow, newer];
    fixture.reply('list/devices', () => lists.shift().promise);
    const olderRead = fixture.refresh('devices');
    const newerRead = fixture.refresh('devices');
    newer.resolve({ status: 'ok', error: false, data: [{ ...devices[0], users: 20 }] });
    await newerRead;
    const latestRow = row(1);
    slow.resolve({ status: 'ok', error: false, data: devices });
    await olderRead;
    assert.equal(row(1), latestRow, 'A late inventory response cannot overwrite the newer device refresh');
    assert.equal(rows().length, 1);
}

async function checkDeviceSyncReviewGuards() {
    let reviewed = 0, mounted;
    const workflows = { mount(options) { mounted = options; }, onView() {}, previewSync() { reviewed++; } };
    const details = { duplicate_groups: 1, conflicts: [] };
    const recovery = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details, workflows });
    await flush();
    const actions = fixture => fixture.tables.get('#devices-table tbody').children[0].querySelector('.row-actions').children;
    const button = (fixture, label) => actions(fixture).find(item => item['aria-label'] === label);
    const sync = button(recovery, 'Sincronizar ahora'), review = button(recovery, 'Revisar diferencias');
    assert(sync.disabled && review.disabled, 'Recovery must protect both immediate writes and router review reads');
    await sync.fire('click'); await review.fire('click');
    assert.equal(reviewed, 0); assert.equal(recovery.writes().length, 0);
    assert(!recovery.document.body.children.some(node => node.className === 'confirm-overlay'));

    const restored = recoveryPanelFixture({ workflows }); await flush();
    const staleSync = button(restored, 'Sincronizar ahora'), staleReview = button(restored, 'Revisar diferencias');
    const pendingSync = staleSync.fire('click'); await flush();
    const dialog = restored.document.body.children.find(node => node.className === 'confirm-overlay');
    assert(dialog);
    mounted.onApplied({ requiresReload: true });
    await dialog.children[0].children[1].children[1].fire('click'); await pendingSync;
    assert.equal(restored.writes().length, 0, 'A full restore during confirmation must prevent an immediate sync on stale state');
    await staleReview.fire('click'); await staleSync.fire('click');
    assert.equal(reviewed, 0, 'Restore reload requirements also protect stale preview controls');
    assert(!restored.document.body.children.some(node => node.className === 'confirm-overlay'));
}

async function checkImmediateDeviceSyncProtocol() {
    const syncToken = 'a'.repeat(64);
    const progress = (overrides = {}) => ({ syncToken, phase: 'applying', done: false,
        processed: 0, total: 2, requested: 0, completed: 0, failed: 0, catalogScanned: 2, ...overrides });
    const envelope = data => ({ status: 'ok', error: false, data });
    async function run(responses, initial = progress()) {
        const fixture = recoveryPanelFixture(); await flush();
        let step = 0;
        fixture.reply('update/device_sync', payload => payload.action === 'begin' ? envelope(initial) : responses[step++]);
        const sync = fixture.tables.get('#devices-table tbody').children[0].querySelector('.row-actions').children[2].fire('click');
        await flush();
        const dialog = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
        await dialog.children[0].children[1].children[1].fire('click'); await sync;
        return { fixture, step, toast: fixture.nodes.get('notifications').lastElementChild,
            writes: fixture.calls.filter(call => call.target === 'device_sync') };
    }
    const complete = progress({ phase: 'complete', done: true, processed: 2, requested: 2, completed: 2 });
    const successful = await run([envelope(progress({ processed: 1, requested: 1, completed: 1 })), envelope(complete)]);
    assert.equal(successful.step, 2, 'Immediate synchronization must keep applying until the final response');
    assert.deepEqual(successful.writes.map(call => call.action), ['begin', 'step', 'step']);
    assert(successful.writes.every(call => call.changeType === 'update'), 'A direct sync cannot be sent as a read-only preview');
    assert.equal(successful.toast.role, 'status');
    assert.match(successful.toast.children[0].textContent, /Sincronización ejecutada/);
    const partial = await run([envelope(progress({ processed: 1, requested: 1, failed: 1 })),
        envelope({ ...complete, completed: 1, failed: 1 })]);
    assert.equal(partial.step, 2, 'One service failure must not stop the other services on the same router');
    assert.equal(partial.toast.role, 'alert'); assert.match(partial.toast.children[0].textContent, /1 de 2 servicios completados/);
    for (const malformed of [
        { syncToken: 'b'.repeat(64), processed: 1 },
        { syncToken: attack, processed: 1 },
        { processed: 0 },
        { processed: 1, total: 3 },
        { processed: 1, requested: 3 },
        { phase: 'complete', done: true, processed: 1 },
        { phase: 'complete', done: false, processed: 2 },
        { phase: 'catalog', processed: 0 },
        { processed: 1, catalogScanned: 1 }
    ]) {
        const rejected = await run([envelope(progress(malformed)), envelope(complete)]);
        assert.equal(rejected.step, 1, 'Invalid or stalled progress must stop without replaying its mutation token');
        assert.equal(rejected.toast.role, 'alert');
        assert(!rejected.toast.children[0].textContent.includes('Sincronización ejecutada'));
        assert(!renderedMarkup(rejected.toast).includes('<img'));
    }
    const interrupted = await run([{ status: 'failed', error: true, message: 'fixture transport interrupted' }, envelope(complete)]);
    assert.equal(interrupted.step, 1, 'Transport failure must never automatically retry a step that could have applied');
    assert.match(interrupted.toast.children[0].textContent, /Revisa Trabajos antes de volver a intentarlo/);
    const stalledCatalog = await run([envelope(progress({ phase: 'catalog', processed: 0, total: 0, catalogScanned: 0 }))],
        progress({ phase: 'catalog', total: 0, catalogScanned: 0 }));
    assert.equal(stalledCatalog.step, 1); assert.equal(stalledCatalog.toast.role, 'alert');
}

async function checkPendingJobsIndicator() {
    const envelope = data => ({ status: 'ok', error: false, data });
    const summary = (extra = {}) => ({ available: true, pending: 2, failed: 1, running: 1, recovery: 1,
        total: 4, checked_at: '2026-10-14T13:00:00Z', source: 'sqlite', reason: null, ...extra });
    const fixture = recoveryPanelFixture({ initialReplies: new Map([['list/jobs_status', envelope(summary())]]) }); await flush();
    const calls = () => fixture.calls.filter(call => call.target === 'jobs_status');
    assert.equal(calls().length, 1);
    assert.deepEqual(plain(calls()[0]), { changeType: 'list', target: 'jobs_status', action: 'list', data: {} });
    assert.equal(fixture.nodes.get('jobs-pending-warning').style.display, 'flex');
    assert.equal(fixture.nodes.get('jobs-pending-count').textContent, '4');
    assert.equal(fixture.nodes.get('jobs-pending-title').textContent, 'Trabajos y recuperaciones: 4');
    assert.match(fixture.nodes.get('jobs-pending-note').textContent, /2 pendientes.*1 con error.*1 en ejecución.*1 en recuperación/);
    assert.equal(fixture.calls.filter(call => call.target === 'jobs').length, 0, 'A global queue indicator must use the local status route without initializing the queue list');
    assert.equal(fixture.nodes.get('save-device-btn').disabled, false);
    const notices = fixture.nodes.get('notifications').children.length;
    fixture.reply('list/jobs_status', { status: 'failed', error: true, message: attack }); await fixture.tick(30000);
    assert.equal(fixture.nodes.get('jobs-pending-count').textContent, '4');
    assert.match(fixture.nodes.get('jobs-pending-freshness').textContent, /desactualizado/);
    assert.equal(fixture.nodes.get('notifications').children.length, notices, 'Failed status polls must use inline stale feedback without popup spam');
    for (const invalid of [summary({ total: 0 }), summary({ pending: 0, failed: 1, total: 2 }), summary({ checked_at: attack }),
        { available: false, pending: null, running: null, failed: null, recovery: null, total: null, checked_at: null, source: null, reason: 'unavailable' }]) {
        fixture.reply('list/jobs_status', envelope(invalid)); await fixture.tick(30000);
        assert.equal(fixture.nodes.get('jobs-pending-count').textContent, '4', 'Malformed/unavailable status must retain the last known queue counts');
    }
    fixture.reply('list/jobs_status', envelope(summary({ pending: 0, failed: 0, running: 0, recovery: 0, total: 0, checked_at: '2026-10-14T13:01:00Z' })));
    await fixture.tick(30000);
    assert.equal(fixture.nodes.get('jobs-pending-warning').style.display, 'none'); assert.equal(fixture.nodes.get('jobs-pending-count').style.display, 'none');
    await fixture.hidden(true); const hiddenCalls = calls().length; await fixture.tick(60000); assert.equal(calls().length, hiddenCalls);
    await fixture.hidden(false); assert.equal(calls().length, hiddenCalls + 1);
    const slow = deferred(); fixture.reply('list/jobs_status', () => slow.promise); await fixture.tick(30000);
    const started = calls().length;
    await fixture.hidden(true); await fixture.hidden(false); await fixture.tick(60000);
    assert.equal(calls().length, started, 'Slow status reads and visibility resumes must never overlap requests');
    fixture.reply('list/jobs_status', envelope(summary({ pending: 0, failed: 0, running: 0, recovery: 0, total: 0, checked_at: '2026-10-14T13:02:00Z' })));
    slow.resolve(envelope(summary({ checked_at: '2026-10-14T13:03:00Z' }))); await flush();
    assert.equal(fixture.nodes.get('jobs-pending-warning').style.display, 'none', 'A read from before visibility changed must not resurrect stale pending jobs');
    const read = deferred(); fixture.reply('list/jobs_status', () => read.promise); await fixture.tick(30000);
    const write = deferred(); fixture.reply('update/config', () => write.promise);
    const saving = fixture.api.saveConfig({ fixture: true }); await flush();
    const duringWrite = calls().length;
    read.resolve(envelope(summary({ checked_at: '2026-10-14T13:05:00Z' }))); await flush(); await fixture.tick(60000);
    assert.equal(calls().length, duringWrite, 'Status reads must pause during writes and ignore reads that predate the mutation');
    fixture.reply('list/jobs_status', envelope(summary({ pending: 0, failed: 0, running: 0, recovery: 0, total: 0, checked_at: '2026-10-14T13:04:00Z' })));
    write.resolve(envelope({})); await saving; await flush();
    assert.equal(calls().length, duringWrite + 1); assert.equal(fixture.nodes.get('jobs-pending-warning').style.display, 'none');
    await fixture.event('pagehide'); const stopped = calls().length; await fixture.tick(60000); assert.equal(calls().length, stopped);
    await fixture.event('pageshow'); assert.equal(calls().length, stopped + 1);
    fixture.reply('list/jobs_status', envelope(summary({ checked_at: '2026-10-14T13:06:00Z' }))); await fixture.tick(30000);
    await fixture.nodes.get('btn-view-pending-jobs').fire('click'); await flush();
    assert.equal(fixture.nodes.get('view-jobs').style.display, 'block', 'The global review action must open Jobs');
    const unavailable = recoveryPanelFixture({ bootstrap: { readOnly: true, details: { duplicate_groups: 0, conflicts: [] } }, details: { duplicate_groups: 0, conflicts: [] },
        initialReplies: new Map([['list/jobs_status', envelope({ available: false })]]) }); await flush();
    assert.equal(unavailable.nodes.get('jobs-pending-count').textContent, '?');
    assert.equal(unavailable.nodes.get('jobs-pending-title').textContent, 'No se pudo comprobar el estado de los trabajos.');
    assert.equal(unavailable.calls.filter(call => call.target === 'jobs_status').length, 1, 'Recovery mode must keep the read-only status route available');

    const recoveryOnly = recoveryPanelFixture({ initialReplies: new Map([['list/jobs_status', envelope(summary({ pending: 0, failed: 0, running: 0, recovery: 2, total: 2 }))]]) }); await flush();
    assert.equal(recoveryOnly.nodes.get('jobs-pending-title').textContent, 'Recuperaciones pendientes: 2', 'Stored recoveries must never be labelled executable pending jobs');
    assert.equal(recoveryOnly.calls.filter(call => call.target === 'job_recovery').length, 0, 'Global summary must not initialize recovery inventory');
}

async function checkWarningDismissals() {
    const envelope = data => ({ status: 'ok', error: false, data });
    const summary = (extra = {}) => ({ available: true, pending: 0, failed: 0, running: 0, recovery: 2,
        total: 2, checked_at: '2026-10-14T13:00:00Z', source: 'sqlite', reason: null, ...extra });
    const report = { available: true, duplicate_groups: 1, conflicts_truncated: false, checked_at: '2026-10-14T13:00:00Z',
        scan_started_at: null, scan_in_progress: false, last_error: null, stale: false, services_scanned: 2,
        families: ['IPv4'], unavailable_families: ['IPv6'], coverage_complete: false, source: 'crm_snapshot', warning_only: true,
        conflicts: [{ family: 'IPv4', address: '192.0.2.77', service_ids: [42, 43], service_count: 2, owners: [
            { service_id: 42, client_id: 17, service_status: 'Activo', service_url: '/crm/client/service/42', client_url: '/crm/client/17' },
            { service_id: 43, client_id: 18, service_status: 'Suspendido', service_url: '/crm/client/service/43', client_url: '/crm/client/18' }
        ] }] };
    const replies = () => new Map([['list/jobs_status', envelope(summary())], ['list/crm_network_conflicts', envelope(report)]]);
    const storage = new Map();
    const fixture = recoveryPanelFixture({ storage, initialReplies: replies() }); await flush();
    assert.equal(fixture.nodes.get('jobs-pending-warning').style.display, 'flex');
    const before = fixture.calls.length;
    for (const id of ['jobs-pending-warning', 'crm-network-warning']) {
        await fixture.nodes.get('dismiss-' + id).fire('click');
        assert.equal(fixture.nodes.get(id).style.display, 'none');
    }
    assert.equal(fixture.calls.length, before, 'Closing top warnings must not call any business API');
    assert.equal(fixture.nodes.get('jobs-pending-count').textContent, '2', 'Sidebar counts remain visible after dismissing the large banner');
    assert.equal(fixture.nodes.get('restore-warnings').textContent, 'Mostrar avisos (2)');
    assert.equal(fixture.nodes.get('warning-controls').style.display, 'block');
    const dismissalKey = [...storage.keys()].find(key => key.startsWith('ros-panel-warning-dismissals-v1:'));
    assert(dismissalKey); assert(storage.get(dismissalKey).length < 1024);
    assert(!/192\.0\.2|service_id|client_id|13:00|\/42|\/17/.test(storage.get(dismissalKey)), 'Dismissal preferences must not store customer details, addresses or poll timestamps');
    fixture.reply('list/jobs_status', envelope(summary({ checked_at: '2026-10-14T13:01:00Z' })));
    fixture.reply('list/crm_network_conflicts', envelope({ ...report, checked_at: '2026-10-14T13:01:00Z' }));
    await fixture.tick(30000);
    assert.equal(fixture.nodes.get('jobs-pending-warning').style.display, 'none');
    assert.equal(fixture.nodes.get('crm-network-warning').style.display, 'none', 'Polling timestamps alone must not reopen a dismissed warning');
    const reloaded = recoveryPanelFixture({ storage, initialReplies: replies() }); await flush();
    assert.equal(reloaded.nodes.get('jobs-pending-warning').style.display, 'none');
    assert.equal(reloaded.nodes.get('crm-network-warning').style.display, 'none', 'Dismissal survives reload in the same tab scope');
    await reloaded.nodes.get('restore-warnings').fire('click');
    assert.equal(reloaded.nodes.get('jobs-pending-warning').style.display, 'flex');
    assert.equal(reloaded.nodes.get('crm-network-warning').style.display, 'flex');
    assert.equal(reloaded.nodes.get('warning-controls').style.display, 'none');
    await fixture.nodes.get('dismiss-jobs-pending-warning').fire('click');
    fixture.reply('list/jobs_status', envelope(summary({ pending: 1, failed: 1, total: 3, checked_at: '2026-10-14T13:02:00Z' })));
    await fixture.tick(30000);
    assert.equal(fixture.nodes.get('jobs-pending-warning').style.display, 'flex', 'A new pending/error count must reopen the warning');
    await fixture.nodes.get('dismiss-jobs-pending-warning').fire('click');
    fixture.reply('list/jobs_status', { status: 'failed', error: true, message: attack }); await fixture.tick(30000);
    assert.equal(fixture.nodes.get('jobs-pending-warning').style.display, 'flex', 'A new status-read failure must not remain hidden');
    fixture.reply('list/jobs_status', envelope(summary({ recovery: 0, total: 0, checked_at: '2026-10-14T13:03:00Z' }))); await fixture.tick(30000);
    fixture.reply('list/jobs_status', envelope(summary({ checked_at: '2026-10-14T13:04:00Z' }))); await fixture.tick(30000);
    assert.equal(fixture.nodes.get('jobs-pending-warning').style.display, 'flex', 'An issue that resolved and recurred must reappear');
    const blocked = recoveryPanelFixture({ storageBlocked: true, initialReplies: replies() }); await flush();
    await blocked.nodes.get('dismiss-jobs-pending-warning').fire('click'); await blocked.tick(30000);
    assert.equal(blocked.nodes.get('jobs-pending-warning').style.display, 'none', 'Blocked storage still permits in-memory dismissal');
    const details = { duplicate_groups: 1, conflicts: [] };
    const migration = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details, storage: new Map(), initialReplies: replies() }); await flush();
    await migration.nodes.get('dismiss-network-warning').fire('click');
    assert.equal(migration.nodes.get('network-warning').style.display, 'none');
    assert.equal(migration.nodes.get('btn-run-jobs').disabled, true, 'Closing a migration warning must preserve its existing safety guards');
    await migration.nodes.get('restore-warnings').fire('click');
    assert.equal(migration.nodes.get('network-warning').style.display, 'flex');
    assert(markup.includes('id="dismiss-jobs-pending-warning" aria-label="Cerrar aviso" title="Cerrar aviso"'));
}

async function checkJobRecoveryResolutionPanel() {
    const envelope = data => ({ status: 'ok', error: false, data });
    const row = { source_job_id: 8, job_id: 'Q8', service_id: 42, client_id: 17, client_name: attack,
        device_ids: [2], snapshot_count: 1, reason: 'cancelled_resources', created_at: null };
    const listing = { available: true, groups: [row], total: 1, has_more: false, checked_at: '2026-10-14T13:00:00Z', reason: null };
    const summary = recovery => ({ available: true, pending: 0, failed: 0, running: 0, recovery, total: recovery,
        checked_at: '2026-10-14T13:00:00Z', source: 'sqlite', reason: null });
    const token = 'a'.repeat(64), preview = { previewToken: token, expiresAt: '2099-10-14T13:00:00Z',
        candidate: { ...row, crm_status: 1, client_archived: false, checked_at: '2026-10-14T13:00:00Z', resolution: 'redundant_current_resources' }, blocked: [], checked_at: '2026-10-14T13:00:00Z' };
    const fixture = recoveryPanelFixture({ initialReplies: new Map([['list/jobs_status', envelope(summary(1))], ['list/job_recovery', envelope(listing)], ['recover/job_recovery', envelope(preview)]]) }); await flush();
    assert.equal(fixture.calls.filter(call => call.target === 'job_recovery').length, 0);
    await fixture.nodes.get('btn-view-pending-jobs').fire('click'); await flush();
    assert.equal(fixture.nodes.get('view-jobs').style.display, 'block');
    assert.equal(fixture.nodes.get('job-recovery-list-status').textContent, 'Recuperaciones pendientes: 1');
    assert(fixture.tables.get('#jobs-table tbody').innerHTML.includes('No hay trabajos en cola.'));
    const item = fixture.nodes.get('job-recovery-list').children[0];
    assert.equal(item.children[0].children[0].href, 'https://fixture.invalid/crm/client/service/42');
    assert.equal(item.children[0].children[1].textContent, attack, 'Client names must remain literal text');
    assert(!renderedMarkup(item).includes('<img'));
    assert.equal(item.children[2].textContent, 'Cancelado');
    assert(markup.indexOf('id="jobs-table"') < markup.indexOf('id="job-recovery-section"'), 'The executable queue must precede compact recovery cards');
    await item.children[3].fire('click');
    assert.deepEqual(plain(fixture.calls.filter(call => call.target === 'job_recovery').at(-1)), { changeType: 'recover', target: 'job_recovery', action: 'preview', data: { sourceJobId: 8 } });
    assert.equal(fixture.nodes.get('apply-job-recovery').disabled, false);
    const confirm = async accepted => {
        const overlay = fixture.document.body.children.find(node => node.className === 'confirm-overlay'); assert(overlay);
        await overlay.children[0].children[1].children[accepted ? 1 : 0].fire('click');
    };
    const canceled = fixture.nodes.get('apply-job-recovery').fire('click'); await flush(); await confirm(false); await canceled;
    assert.equal(fixture.calls.filter(call => call.target === 'job_recovery' && call.action === 'apply').length, 0);
    const backup = 'jobs-recovery-20261014T130000Z-' + 'a'.repeat(32) + '.sqlite';
    fixture.reply('recover/job_recovery', payload => payload.action === 'preview' ? envelope(preview) : envelope({ resolved: [{ source_job_id: 8, service_id: 42, snapshot_count: 1 }], backup_name: backup, checked_at: '2026-10-14T13:00:00Z', router_changes: false, crm_changes: false }));
    fixture.reply('list/job_recovery', envelope({ ...listing, groups: [], total: 0 })); fixture.reply('list/jobs_status', envelope(summary(0)));
    const applied = fixture.nodes.get('apply-job-recovery').fire('click'); await flush(); await confirm(true); await applied; await flush();
    const result = fixture.nodes.get('job-recovery-result');
    assert.equal(result.children[0].textContent, 'Recuperación resuelta');
    assert.match(result.children[2].textContent, /IP, las velocidades y los routers se conservaron/);
    assert.equal(result.children[4].href, 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?download=' + backup);
    assert.equal(fixture.nodes.get('job-recovery-list').children.length, 0);
    assert.equal(fixture.nodes.get('job-recovery-list-status').textContent, 'No hay servicios en recuperación.');
    assert.equal(fixture.nodes.get('jobs-pending-warning').style.display, 'none');
    assert.equal(fixture.nodes.get('job-recovery-comparison').children.length, 0);
    assert.equal(fixture.nodes.get('apply-job-recovery').style.display, 'none');
    await fixture.nodes.get('apply-job-recovery').fire('click');
    assert.equal(fixture.calls.filter(call => call.target === 'job_recovery' && call.action === 'apply').length, 1, 'A successful recovery token must not be replayed');

    const blocked = recoveryPanelFixture({ initialReplies: new Map([['list/job_recovery', envelope(listing)], ['recover/job_recovery', envelope({ previewToken: null, expiresAt: null, candidate: null, blocked: [{ source_job_id: 8, service_id: 42, reason: 'different_resources' }], checked_at: '2026-10-14T13:00:00Z' })]]) }); await flush(); await blocked.navigate('jobs');
    await blocked.nodes.get('job-recovery-list').children[0].children[3].fire('click');
    assert.equal(blocked.nodes.get('apply-job-recovery').disabled, true);
    assert.match(blocked.nodes.get('job-recovery-comparison').children[1].textContent, /difieren.*no se retirará ninguno/);
    assert.equal(blocked.nodes.get('btn-run-jobs').disabled, false, 'A blocked recovery must not stop unrelated jobs');
    blocked.reply('recover/job_recovery', envelope(preview)); await blocked.nodes.get('recheck-job-recovery').fire('click');
    blocked.reply('recover/job_recovery', { status: 'failed', error: true, message: 'recovery_verification_failed' });
    const uncertain = blocked.nodes.get('apply-job-recovery').fire('click'); await flush();
    const uncertainOverlay = blocked.document.body.children.find(node => node.className === 'confirm-overlay'); await uncertainOverlay.children[0].children[1].children[1].fire('click'); await uncertain;
    assert.equal(blocked.nodes.get('apply-job-recovery').disabled, true);
    assert.equal(blocked.nodes.get('recheck-job-recovery').disabled, false);
    assert.match(blocked.nodes.get('job-recovery-status').textContent, /No se confirmó.*verifica de nuevo/);
    const writes = blocked.calls.filter(call => call.changeType === 'update' || call.changeType === 'recover' && call.action === 'apply');
    assert.equal(writes.length, 1, 'Review/recheck must not enqueue jobs, rewrite CRM or touch routers');

    const stale = deferred(); blocked.reply('list/job_recovery', () => stale.promise);
    const oldRefresh = blocked.nodes.get('refresh-job-recoveries').fire('click'); await flush();
    await blocked.navigate('devices');
    blocked.reply('list/job_recovery', envelope({ ...listing, groups: [], total: 0 }));
    await blocked.navigate('jobs');
    stale.resolve(envelope(listing)); await oldRefresh; await flush();
    assert.equal(blocked.nodes.get('job-recovery-list').children.length, 0, 'Returning to Jobs must re-read after an old inventory request settles and must not resurrect recovery rows');
    blocked.reply('list/job_recovery', { status: 'failed', error: true, message: attack });
    await blocked.nodes.get('refresh-job-recoveries').fire('click');
    assert.match(blocked.nodes.get('job-recovery-list-status').textContent, /No se pudo refrescar.*última lista/);
    assert(!renderedMarkup(blocked.nodes.get('job-recovery-list')).includes('<img'));
}

async function checkRecoveryObservationPanel() {
    const envelope = data => ({ status: 'ok', error: false, data });
    const row = { source_job_id: 8, job_id: 'Q8', service_id: 42, client_id: 17, client_name: 'Cliente de prueba',
        device_ids: [2], snapshot_count: 1, reason: 'cancelled_resources', created_at: null };
    const listing = { available: true, groups: [row], total: 1, has_more: false, checked_at: '2026-10-14T13:00:00Z', reason: null };
    const checks = [{ device_id: 2, path: '/ppp/secret', state: 'absent', selector_type: 'name', identity_hint: 'sha256:123456abcdef', selector_label: attack }];
    const candidate = { ...row, crm_status: 1, client_archived: false, checked_at: '2026-10-14T13:00:00Z', resolution: 'observed_absent_resources', checks };
    const preview = { previewToken: 'f'.repeat(64), expiresAt: '2099-10-14T13:00:00Z', candidate, blocked: [], checked_at: '2026-10-14T13:00:00Z' };
    const fixture = recoveryPanelFixture({ initialReplies: new Map([['list/job_recovery', envelope(listing)], ['recover/job_recovery', envelope(preview)]]) }); await flush(); await fixture.navigate('jobs');
    assert.equal(fixture.nodes.get('job-recovery-list').children[0].children[3].textContent, 'Comparar con UISP y router');
    await fixture.nodes.get('job-recovery-list').children[0].children[3].fire('click');
    assert.equal(fixture.nodes.get('apply-job-recovery').disabled, false);
    const comparison = fixture.nodes.get('job-recovery-comparison');
    assert.match(comparison.children[3].textContent, /ya no están.*consultará de nuevo/);
    assert.equal(comparison.children[4].children[1].textContent, 'Equipo #2 · /ppp/secret · No encontrado');
    assert.equal(comparison.children[4].children[2].textContent, 'Identificador name: ' + attack);
    assert(!renderedMarkup(comparison).includes('<img'), 'Router selectors must remain plain text');
    assert.equal(fixture.calls.filter(call => ['update', 'repair', 'recover'].includes(call.changeType) && call.action !== 'preview').length, 0, 'Review must not execute or delete router resources');
    const backup = 'jobs-recovery-20261014T130000Z-' + 'f'.repeat(32) + '.sqlite';
    fixture.reply('recover/job_recovery', envelope({ resolved: [{ source_job_id: 8, service_id: 42, snapshot_count: 1, resolution: 'observed_absent_resources' }], backup_name: backup,
        checked_at: '2026-10-14T13:00:00Z', router_changes: false, crm_changes: false }));
    fixture.reply('list/job_recovery', envelope({ ...listing, groups: [], total: 0 }));
    const applying = fixture.nodes.get('apply-job-recovery').fire('click'); await flush();
    const overlay = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
    assert.match(overlay.children[0].children[0].textContent, /comprobará de nuevo.*sólo sus registros locales/);
    await overlay.children[0].children[1].children[1].fire('click'); await applying;
    assert.match(fixture.nodes.get('job-recovery-result').children[2].textContent, /recursos anteriores ya no estaban.*registros locales/);

    const blocked = recoveryPanelFixture({ initialReplies: new Map([['list/job_recovery', envelope(listing)], ['recover/job_recovery', envelope({ ...preview, previewToken: null, expiresAt: null, candidate: null,
        blocked: [{ source_job_id: 8, service_id: 42, reason: 'router_resources_present', checks: [{ ...checks[0], state: 'present' }] }] })]]) }); await flush(); await blocked.navigate('jobs');
    await blocked.nodes.get('job-recovery-list').children[0].children[3].fire('click');
    assert.equal(blocked.nodes.get('apply-job-recovery').disabled, true);
    assert.match(blocked.nodes.get('job-recovery-comparison').children[1].textContent, /aún conserva.*no los elimina/);
    assert.match(blocked.nodes.get('job-recovery-comparison').children[2].children[1].textContent, /Presente en el router/);
    assert.equal(blocked.nodes.get('recheck-job-recovery').disabled, false);
    for (const badChecks of [[{ ...checks[0], path: attack }], [{ ...checks[0], state: 'present' }], [], Array(61).fill(checks[0]), [{ ...checks[0], selector_label: '\u0000' }]]) {
        blocked.reply('recover/job_recovery', envelope({ ...preview, candidate: { ...candidate, checks: badChecks } }));
        await blocked.nodes.get('recheck-job-recovery').fire('click');
        assert.equal(blocked.nodes.get('apply-job-recovery').disabled, true, 'Malformed/inconclusive router evidence must never enable recovery resolution');
        assert.match(blocked.nodes.get('job-recovery-status').textContent, /Respuesta inválida/);
    }
}

async function checkRetainedRecoveryPanel() {
    const envelope = data => ({ status: 'ok', error: false, data });
    const row = { source_job_id: 8, job_id: 'Q8', service_id: 42, client_id: 17, client_name: attack,
        device_ids: [2], snapshot_count: 1, reason: 'cancelled_resources', created_at: null };
    const listing = { available: true, groups: [row], total: 1, has_more: false, checked_at: '2026-10-14T13:00:00Z', reason: null,
        reviewed_groups: [], reviewed_total: 0 };
    const diagnostics = { stage: 'prior_reservations', issues: [{ code: 'uncovered_prior_address', family: 'IPv4', snapshot_slot: 'source' }] };
    const blocked = { previewToken: null, expiresAt: null, candidate: null, blocked: [{ source_job_id: 8, service_id: 42,
        reason: 'router_snapshot_incomplete', checks: [], diagnostics }], checked_at: '2026-10-14T13:00:00Z' };
    const candidate = { ...row, crm_status: 1, client_archived: false, checked_at: '2026-10-14T13:00:00Z', resolution: 'retained_resources', checks: [], diagnostics };
    const preview = { previewToken: 'e'.repeat(64), expiresAt: '2099-10-14T13:00:00Z', candidate, blocked: [], checked_at: '2026-10-14T13:00:00Z' };
    const fixture = recoveryPanelFixture({ initialReplies: new Map([['list/job_recovery', envelope(listing)], ['recover/job_recovery', envelope(blocked)]]) }); await flush(); await fixture.navigate('jobs');
    await fixture.nodes.get('job-recovery-list').children[0].children[3].fire('click');
    assert.equal(fixture.nodes.get('apply-job-recovery').disabled, true);
    assert.equal(fixture.nodes.get('preserve-job-recovery').style.display, 'inline-flex');
    assert.match(fixture.nodes.get('job-recovery-comparison').children[2].children[0].textContent, /reserva anterior.*router.*IPv4/);
    fixture.reply('recover/job_recovery', payload => envelope(payload.data.mode === 'preserve' ? preview : blocked));
    await fixture.nodes.get('preserve-job-recovery').fire('click');
    assert.deepEqual(plain(fixture.calls.filter(call => call.target === 'job_recovery').at(-1)), { changeType: 'recover', target: 'job_recovery', action: 'preview', data: { sourceJobId: 8, mode: 'preserve' } });
    assert.equal(fixture.nodes.get('apply-job-recovery').disabled, false);
    assert.equal(fixture.nodes.get('apply-job-recovery').textContent, 'Conservar recursos y cerrar revisión');
    assert.match(fixture.nodes.get('job-recovery-comparison').children[3].textContent, /archivo protegido.*no confirma.*limpio ni sincronizado/);
    await fixture.nodes.get('recheck-job-recovery').fire('click');
    assert.equal(fixture.calls.filter(call => call.target === 'job_recovery').at(-1).data.mode, 'preserve', 'Read-only retry must retain the selected conservation mode');
    const cancelled = fixture.nodes.get('apply-job-recovery').fire('click'); await flush();
    let overlay = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
    assert.match(overlay.children[0].children[0].textContent, /Conservar.*cerrar.*No se eliminarán/);
    await overlay.children[0].children[1].children[0].fire('click'); await cancelled;
    assert.equal(fixture.calls.filter(call => call.action === 'apply').length, 0);
    const backup = 'jobs-recovery-20261014T130000Z-' + 'e'.repeat(32) + '.sqlite';
    fixture.reply('recover/job_recovery', envelope({ resolution: 'retained_resources', resolved: [], retained: [{ source_job_id: 8, service_id: 42, snapshot_count: 1 }],
        backup_name: backup, checked_at: '2026-10-14T13:00:00Z', router_changes: false, crm_changes: false }));
    fixture.reply('list/job_recovery', envelope({ ...listing, groups: [], total: 0, reviewed_groups: [{ ...row, reason: 'retained_resources', reviewed_at: '2026-10-14T13:00:00Z' }], reviewed_total: 1 }));
    const applied = fixture.nodes.get('apply-job-recovery').fire('click'); await flush();
    overlay = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
    await overlay.children[0].children[1].children[1].fire('click'); await applied; await flush();
    assert.equal(fixture.nodes.get('job-recovery-result').children[0].textContent, 'Revisión cerrada; recursos conservados');
    assert.match(fixture.nodes.get('job-recovery-result').children[2].textContent, /siguen protegidos.*No se cambió UISP ni el router/);
    assert.equal(fixture.nodes.get('job-recovery-list').children.length, 0);
    assert.equal(fixture.nodes.get('job-retained-section').style.display, 'block');
    assert.equal(fixture.nodes.get('job-retained-list').children.length, 1);
    assert(!renderedMarkup(fixture.nodes.get('job-retained-list')).includes('<img'));
    assert.equal(fixture.nodes.get('preserve-job-recovery').style.display, 'none');
    assert.equal(fixture.calls.filter(call => call.action === 'apply').length, 1);
    assert(fixture.calls.every(call => ['list', 'recover'].includes(call.changeType)), 'Closing a review must not call router, CRM or job execution APIs');

    const malformed = recoveryPanelFixture({ initialReplies: new Map([['list/job_recovery', envelope(listing)], ['recover/job_recovery', envelope({ ...blocked,
        blocked: [{ ...blocked.blocked[0], diagnostics: { stage: attack, issues: [] } }] })]]) }); await flush(); await malformed.navigate('jobs');
    await malformed.nodes.get('job-recovery-list').children[0].children[3].fire('click');
    assert.equal(malformed.nodes.get('apply-job-recovery').disabled, true);
    assert.equal(malformed.nodes.get('preserve-job-recovery').style.display, 'none');
    assert.match(malformed.nodes.get('job-recovery-status').textContent, /Respuesta inválida/);
}

async function checkForcedRecoveryDiscardPanel() {
    const envelope = data => ({ status: 'ok', error: false, data });
    const row = { source_job_id: 8, job_id: 'Q8', service_id: 42, client_id: 17, client_name: 'Cliente de prueba',
        device_ids: [2], snapshot_count: 1, reason: 'cancelled_resources', created_at: null };
    const listing = { available: true, groups: [row, { ...row, source_job_id: 9, job_id: 'Q9', service_id: 43 }], total: 2, has_more: false,
        reviewed_groups: [], reviewed_total: 0, checked_at: '2026-10-14T13:00:00Z', reason: null };
    const candidate = { ...row, resolution: 'discarded_resources', checked_at: '2026-10-14T13:00:00Z', crm_status: null, client_archived: null, identity_origin: 'local' };
    const preview = { previewToken: 'b'.repeat(64), expiresAt: '2099-10-14T13:00:00Z', candidate, blocked: [], checked_at: '2026-10-14T13:00:00Z' };
    const blocked = { ...preview, previewToken: null, expiresAt: null, candidate: null, blocked: [{ source_job_id: 8, service_id: 42, reason: 'crm_unavailable' }] };
    const fixture = recoveryPanelFixture({ initialReplies: new Map([['list/job_recovery', envelope(listing)], ['recover/job_recovery', envelope(blocked)]]) }); await flush(); await fixture.navigate('jobs');
    assert.equal(fixture.nodes.get('job-recovery-list').children.length, 2, 'Every pending recovery must validate independently of its list index');
    await fixture.nodes.get('job-recovery-list').children[0].children[3].fire('click');
    assert.equal(fixture.nodes.get('discard-job-recovery').style.display, 'inline-flex', 'Unavailable UISP must not make explicit local discard unreachable');
    fixture.reply('recover/job_recovery', envelope(preview)); await fixture.nodes.get('discard-job-recovery').fire('click');
    assert.equal(fixture.calls.filter(call => call.target === 'job_recovery').at(-1).data.mode, 'discard');
    assert.equal(fixture.nodes.get('apply-job-recovery').textContent, 'Descartar registro local');
    assert.match(fixture.nodes.get('job-recovery-comparison').children[1].textContent, /no se comprobó.*UISP/);
    assert.match(fixture.nodes.get('job-recovery-comparison').children[3].textContent, /IP históricas.*volver a asignarse/);
    const applying = fixture.nodes.get('apply-job-recovery').fire('click'); await flush();
    const overlay = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
    assert.match(overlay.children[0].children[0].textContent, /definitivamente.*Q8.*#42.*podrán reutilizarse/);
    const backup = 'jobs-recovery-20261014T130000Z-' + 'b'.repeat(32) + '.sqlite';
    const outcome = { source_job_id: 8, service_id: 42, snapshot_count: 1 };
    fixture.reply('recover/job_recovery', envelope({ resolution: 'discarded_resources', resolved: [outcome], discarded: [outcome], backup_name: backup,
        checked_at: '2026-10-14T13:00:00Z', router_changes: false, crm_changes: false }));
    fixture.reply('list/job_recovery', envelope({ ...listing, groups: [listing.groups[1]], total: 1 }));
    await overlay.children[0].children[1].children[1].fire('click'); await applying; await flush();
    assert.equal(fixture.nodes.get('job-recovery-result').children[0].textContent, 'Registro local descartado');
    assert.match(fixture.nodes.get('job-recovery-result').children[2].textContent, /no confirma.*retirados del equipo/);
    assert.equal(fixture.nodes.get('job-recovery-list').children.length, 1);
    assert.equal(fixture.calls.filter(call => call.action === 'apply').length, 1);
    assert.equal(fixture.nodes.get('discard-job-recovery').style.display, 'none');
    assert(fixture.calls.every(call => ['list', 'recover'].includes(call.changeType)), 'Forced local discard cannot call router, CRM PATCH or job execution APIs');
}

async function checkInvalidReservationRepairPanel() {
    const envelope = data => ({ status: 'ok', error: false, data });
    const bad = { source: 'network', value: attack };
    const listing = { rows: [{ service_id: 42, client_id: 17, status: 1, fields: [{ field: 'address', family: 'IPv4', sources: [bad] }] }],
        total: 1, truncated: false, checked_at: '2026-10-14T13:00:00Z' };
    const token = 'd'.repeat(64);
    const candidate = { service_id: 42, client_id: 17, status: 1, pending_jobs: 1,
        fields: [{ field: 'address', family: 'IPv4', saved: [bad], current_address: '192.0.2.42', action: 'replace', reason: 'crm_address' }] };
    const preview = { previewToken: token, expiresAt: '2099-10-14T13:00:00Z', candidates: [candidate], blocked: [], api_requests: 4 };
    const backup = 'network-cleanup-20261014T130000Z-' + 'd'.repeat(32) + '.sqlite';
    const fixture = recoveryPanelFixture({ initialReplies: new Map([['list/network_repair', envelope(listing)], ['repair/network_repair', envelope(preview)]]) }); await flush();
    assert.equal(fixture.calls.filter(call => call.target === 'network_repair').length, 0, 'Panel boot must not perform hidden local repair inventory or CRM comparisons');
    await fixture.navigate('network_conflicts');
    const item = fixture.nodes.get('invalid-reservations-list').children[0];
    assert.equal(item.children[0].children[0].href, 'https://fixture.invalid/crm/client/service/42');
    assert.equal(item.children[0].children[1].href, 'https://fixture.invalid/crm/client/17');
    assert(item.children[1].children[0].innerHTML.includes('&lt;img') && !renderedMarkup(item).includes('<img'), 'Malformed reservations must be escaped, never executed');
    assert.equal(fixture.calls.filter(call => call.changeType === 'repair').length, 0);
    await item.children[1].children[1].fire('click');
    assert.deepEqual(plain(fixture.calls.filter(call => call.changeType === 'repair').at(-1)), { changeType: 'repair', target: 'network_repair', action: 'preview', data: { selection: [{ serviceId: 42, fields: ['address'] }] } });
    assert.equal(fixture.nodes.get('network-repair-modal').style.display, 'flex'); assert.equal(fixture.nodes.get('apply-network-repair').disabled, false);
    assert.equal(fixture.nodes.get('network-repair-title').textContent, 'Corregir reserva inválida');
    assert.equal(fixture.nodes.get('network-repair-comparison').style.display, 'grid');
    assert(!renderedMarkup(fixture.nodes.get('network-repair-comparison')).includes('<img'));
    assert.match(fixture.nodes.get('network-repair-comparison').children[0].children[2].textContent, /No se sincronizará el router ni se ejecutarán trabajos/);
    assert.match(fixture.nodes.get('network-repair-comparison').children[0].children[3].textContent, /1 trabajo pendiente\. La corrección no lo ejecutará/);
    const confirm = async yes => {
        const dialog = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
        assert(dialog); await dialog.children[0].children[1].children[yes ? 1 : 0].fire('click');
    };
    const cancel = fixture.nodes.get('apply-network-repair').fire('click'); await flush(); await confirm(false); await cancel;
    assert.equal(fixture.calls.filter(call => call.action === 'apply' && call.target === 'network_repair').length, 0, 'Cancelling final repair confirmation must preserve all data');
    fixture.reply('repair/network_repair', payload => payload.action === 'preview' ? envelope(preview) : envelope({ repaired: [{ service_id: 42,
        fields: [{ field: 'address', family: 'IPv4', action: 'replace', current_address: '192.0.2.42' }] }], backup_name: backup,
        requires_reload: true, checked_at: '2026-10-14T13:01:00Z', jobs_preserved: true, requires_sync: false }));
    fixture.reply('list/network_repair', envelope({ ...listing, rows: [], total: 0 }));
    const apply = fixture.nodes.get('apply-network-repair').fire('click'); await flush(); await confirm(true); await apply;
    const applies = fixture.calls.filter(call => call.action === 'apply' && call.target === 'network_repair');
    assert.deepEqual(plain(applies[0]), { changeType: 'repair', target: 'network_repair', action: 'apply', data: { previewToken: token } });
    assert.equal(fixture.nodes.get('network-repair-result').children[0].textContent, 'Reserva local corregida');
    assert.equal(fixture.nodes.get('network-repair-title').textContent, 'Resultado de la corrección');
    assert.equal(fixture.nodes.get('network-repair-comparison').style.display, 'none');
    assert.equal(fixture.nodes.get('network-repair-comparison').children.length, 0, 'Confirmed repair must discard the old pending comparison and future-tense proposal');
    assert(fixture.nodes.get('network-repair-result').children[2].innerHTML.includes('192.0.2.42'));
    assert(fixture.nodes.get('network-repair-result').children[2].innerHTML.includes('Reserva local antes de la corrección')
        && fixture.nodes.get('network-repair-result').children[2].innerHTML.includes('&lt;img')
        && !fixture.nodes.get('network-repair-result').children[2].innerHTML.includes('<img'), 'Old malformed values belong only in an explicitly before-labelled escaped receipt');
    assert.equal(fixture.nodes.get('network-repair-result').children[3].href, 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?download=' + backup);
    assert.equal(fixture.nodes.get('invalid-reservations-list').children.length, 0, 'Successful repair must refresh the local inventory');
    assert.equal(fixture.nodes.get('apply-network-repair').style.display, 'none');
    await fixture.nodes.get('apply-network-repair').fire('click'); assert.equal(fixture.calls.filter(call => call.action === 'apply' && call.target === 'network_repair').length, 1, 'A completed token must not be replayed');
    assert.equal(fixture.nodes.get('reload-after-repair').style.display, 'inline-flex');
    assert.equal(fixture.calls.filter(call => call.changeType === 'update').length, 0, 'Repair result must never execute jobs, synchronize or change the CRM');
    await fixture.nodes.get('reload-after-repair').fire('click'); assert.equal(fixture.reloads(), 1);
    const jobFixture = recoveryPanelFixture({ initialReplies: new Map([['list/jobs', envelope({ Q1: { serviceId: 43, state: 'pending', error: 'invalid_network_address: requested_service=43 blocking_service=42 source=reserved family=IPv4' } })],
        ['repair/network_repair', envelope({ previewToken: null, expiresAt: '2099-10-14T13:00:00Z', candidates: [], blocked: [{ service_id: 42, reason: 'applied_ownership' }], api_requests: 3 })]]) }); await flush(); await jobFixture.navigate('jobs');
    const job = jobFixture.tables.get('#jobs-table tbody').children[0];
    assert.equal(job.querySelector('.job-description').children[0].textContent, 'Revisar reserva del servicio #42');
    await job.querySelector('.job-description').children[0].fire('click');
    assert.equal(jobFixture.nodes.get('apply-network-repair').disabled, true);
    assert.match(jobFixture.nodes.get('network-repair-comparison').children[0].innerHTML, /aplicados en un router/);
    jobFixture.reply('repair/network_repair', envelope({ ...preview, candidates: [{ ...candidate, status: 2, pending_jobs: 0,
        fields: [{ ...candidate.fields[0], current_address: null, action: 'clear', reason: 'crm_no_address' }] }] }));
    await jobFixture.nodes.get('recheck-network-repair').fire('click');
    assert.equal(jobFixture.nodes.get('apply-network-repair').disabled, false);
    assert.match(jobFixture.nodes.get('network-repair-comparison').children[0].children[2].textContent, /no tiene IP asignada.*Sólo se retirará la reserva local inválida/);
    jobFixture.reply('repair/network_repair', { status: 'failed', error: true, message: 'repair_verification_failed' });
    const uncertain = jobFixture.nodes.get('apply-network-repair').fire('click'); await flush();
    const dialog = jobFixture.document.body.children.find(node => node.className === 'confirm-overlay'); await dialog.children[0].children[1].children[1].fire('click'); await uncertain;
    assert.equal(jobFixture.nodes.get('apply-network-repair').disabled, true, 'A failed/uncertain apply must consume the local token and require a new comparison');
    assert.match(jobFixture.nodes.get('network-repair-status').textContent, /No se confirmó.*Compara nuevamente/);
    jobFixture.reply('repair/network_repair', envelope({ previewToken: null, expiresAt: null, candidates: [], blocked: [{ service_id: 42, reason: 'cached_identity_changed',
        diagnostics: { stage: 'identity', local_service_present: true, local_client_id: null, cached_client_id: 18, crm_client_id: 17, identity_source: 'uisp', checked_at: '2026-10-14T13:00:00Z' } }], api_requests: 2 }));
    await jobFixture.nodes.get('recheck-network-repair').fire('click');
    assert.equal(jobFixture.nodes.get('apply-network-repair').disabled, true);
    const identityBlock = jobFixture.nodes.get('network-repair-comparison').children[0];
    assert.match(identityBlock.innerHTML, /caché no coincide/);
    assert.equal(identityBlock.children[0].children[0].children[1].textContent, 'Sin comprobar');
    assert.equal(identityBlock.children[0].children[1].children[1].href, 'https://fixture.invalid/crm/client/18');
    assert.equal(identityBlock.children[0].children[2].children[1].href, 'https://fixture.invalid/crm/client/17');
    assert.match(identityBlock.children[1].textContent, /no cambia la identidad ni elimina reservas/);
    assert.equal(identityBlock.children[2].href, 'https://fixture.invalid/crm/client/service/42');
    assert.match(identityBlock.children[3].textContent, /Verificado:/);
    jobFixture.reply('repair/network_repair', envelope({ previewToken: null, expiresAt: null, candidates: [], blocked: [{ service_id: 42, reason: 'pending_address_changed',
        diagnostics: { local_client_id: null, cached_client_id: 17, crm_client_id: 17, checked_at: '2026-10-14T13:00:00Z', queue_jobs: [
            { job_id: 'Q1', status: 'pending', reason: 'pending_address_changed' }, { job_id: 'Q2', status: 'cancelled', reason: 'pending_address_changed' },
            { job_id: attack, status: 'pending', reason: attack }
        ] } }], api_requests: 2 }));
    await jobFixture.nodes.get('recheck-network-repair').fire('click');
    const queueBlock = jobFixture.nodes.get('network-repair-comparison').children[0];
    assert.equal(queueBlock.children[2].children[0].textContent, 'Trabajo Q1 · Pendiente');
    assert.equal(queueBlock.children[3].children[0].textContent, 'Trabajo Q2 · Cancelado');
    assert.match(queueBlock.children[3].children[1].textContent, /ya no se ejecuta/);
    assert.equal(queueBlock.children[3].children.length, 2, 'Cancelled jobs must not be offered as executable pending jobs');
    const callsBeforeReview = jobFixture.calls.filter(call => ['update', 'repair', 'recover'].includes(call.changeType)).length;
    await queueBlock.children[2].children[2].fire('click'); await flush();
    assert.equal(jobFixture.nodes.get('view-jobs').style.display, 'block');
    assert.equal(jobFixture.tables.get('#jobs-table tbody').children[0].querySelector('.job-checkbox').checked, true, 'Review job opens Jobs and selects only the requested pending QID');
    assert.equal(jobFixture.calls.filter(call => ['update', 'repair', 'recover'].includes(call.changeType)).length, callsBeforeReview, 'Review job must neither execute, cancel nor modify any job');
    const invalidJobFixture = recoveryPanelFixture({ initialReplies: new Map([['list/jobs', envelope({ Q1: { serviceId: 43, error: 'invalid_network_address: requested_service=99 blocking_service=42 source=reserved family=IPv4' },
        Q2: { serviceId: 43, error: 'invalid_network_address: requested_service=43 blocking_service=42 source=applied family=IPv4' },
        Q3: { serviceId: 43, error: 'invalid_network_address: requested_service=43 blocking_service=42 source=reserved family=IPv4' + attack } })]]) }); await flush(); await invalidJobFixture.navigate('jobs');
    assert(invalidJobFixture.tables.get('#jobs-table tbody').children.every(row => row.querySelector('.job-description').children.length === 0), 'Only a strict reserved diagnostic matching the requested service may offer local repair');
}

async function checkCrmNetworkMonitorPanel() {
    const envelope = data => ({ status: 'ok', error: false, data });
    const report = {
        available: true, duplicate_groups: 1, conflicts_truncated: false, checked_at: '2026-10-14T13:00:00Z',
        scan_started_at: null, scan_in_progress: false, last_error: null, stale: false, services_scanned: 2,
        families: ['IPv4'], unavailable_families: ['IPv6'], coverage_complete: false, source: 'crm_snapshot', warning_only: true,
        conflicts: [{ family: 'IPv4', address: '192.0.2.77', service_ids: [42, 43], service_count: 2, owners: [
            { service_id: 42, client_id: 17, service_status: 'Activo', service_url: '/crm/client/service/42', client_url: '/crm/client/17', source: 'crm_snapshot' },
            { service_id: 43, client_id: 18, service_status: 'Suspendido', service_url: '/crm/client/service/43', client_url: '/crm/client/18', source: 'crm_snapshot' }
        ] }]
    };
    const initialReplies = new Map([['list/crm_network_conflicts', envelope(report)]]);
    const fixture = recoveryPanelFixture({ initialReplies }); await flush();
    assert.equal(fixture.monitorCalls().length, 1, 'Every panel boot reads the local monitor status once');
    assert.deepEqual(plain(fixture.monitorCalls()[0]), { changeType: 'list', target: 'crm_network_conflicts', action: 'list', data: {} });
    assert.equal(fixture.monitorTimers().length, 1); assert.equal(fixture.monitorTimers()[0].delay, 30000);
    assert.equal(fixture.api.recovery, null, 'A CRM warning must never activate migration recovery');
    assert.equal(fixture.nodes.get('crm-network-warning').style.display, 'flex');
    assert.equal(fixture.nodes.get('network-warning').style.display, undefined, 'The stored-reservation warning remains independent');
    assert.equal(fixture.nodes.get('crm-network-warning-title').textContent, 'IP duplicadas en CRM: 1 conflicto');
    assert.match(fixture.nodes.get('crm-network-warning-note').textContent, /suspensiones y reactivaciones automáticas.*continúan/);
    assert.match(fixture.nodes.get('crm-network-warning-freshness').textContent, /Familias revisadas: IPv4/);
    assert.match(fixture.nodes.get('crm-network-warning-freshness').textContent, /Sin revisar.*IPv6/);
    assert.equal(fixture.nodes.get('btn-run-jobs').disabled, false); assert.equal(fixture.nodes.get('save-device-btn').disabled, false);
    const beforeWrites = fixture.writes().length;
    await fixture.api.saveDevice('edit', { id: 1, name: 'Router de ejemplo', ip: '192.0.2.1', user: 'fixture' });
    await fixture.api.send('update', 'jobs', 'run', ['Q1']);
    assert.equal(fixture.writes().length, beforeWrites + 2, 'CRM warnings keep ordinary CRUD and automatic job envelopes available');
    await fixture.nodes.get('btn-view-crm-conflicts').fire('click'); await flush();
    assert.equal(fixture.nodes.get('view-network_conflicts').style.display, 'block');
    assert.equal(fixture.diagnostics().length, 0, 'Healthy CRM monitoring never reads old recovery diagnostics');
    assert.equal(fixture.nodes.get('stored-network-conflicts-pane').style.display, undefined);
    const crmOwners = target => target.nodes.get('crm-network-conflicts-body').children.flatMap(row => row.children[1]?.children || []).filter(owner => owner.dataset.serviceId);
    assert.equal(crmOwners(fixture).length, 2);
    assert(crmOwners(fixture).every(owner => owner.children.slice(1).map(button => button.textContent).join('|') === 'Verificar en UISP|Editar IP en CRM|Revisar limpieza'),
        'CRM conflicts offer explicit verification, reviewed IP edits and protected local cleanup');
    assert.equal(crmOwners(fixture)[0].children[0].children[0].href, 'https://fixture.invalid/crm/client/17');
    assert.equal(crmOwners(fixture)[0].children[0].children[1].children[0].href, 'https://fixture.invalid/crm/client/service/42');
    const notices = fixture.nodes.get('notifications').children.length;
    fixture.reply('list/crm_network_conflicts', { status: 'failed', error: true, message: attack });
    await fixture.tick(30000);
    assert.equal(fixture.nodes.get('notifications').children.length, notices, 'Monitor failures use an inline stale status without repeated toasts');
    assert.equal(crmOwners(fixture).length, 2); assert.equal(fixture.nodes.get('crm-network-warning').style.display, 'flex');
    assert.match(fixture.nodes.get('crm-network-warning-freshness').textContent, /desactualizado/);
    assert.equal(fixture.monitorTimers().length, 1, 'Failures remain retryable');
    const partial = { ...report, available: false, duplicate_groups: 0, conflicts: [], checked_at: null,
        scan_in_progress: true, services_scanned: 50, last_error: 'mapping_changed', families: [] };
    fixture.reply('list/crm_network_conflicts', envelope(partial)); await fixture.tick(30000);
    assert.equal(crmOwners(fixture).length, 2, 'An incomplete/mapping-changed scan cannot falsely clear a previous completed conflict');
    assert.match(fixture.nodes.get('crm-network-conflicts-status').textContent, /en curso: 50/);
    fixture.reply('list/crm_network_conflicts', envelope({ ...report, duplicate_groups: 0 })); await fixture.tick(30000);
    assert.equal(fixture.nodes.get('crm-network-warning').style.display, 'flex', 'Malformed contradictory reports must retain the last known warning');
    const pending = deferred(); fixture.reply('list/crm_network_conflicts', () => pending.promise);
    await fixture.tick(30000); const started = fixture.monitorCalls().length;
    assert.equal(fixture.monitorTimers().length, 0);
    await fixture.hidden(true); await fixture.hidden(false); await fixture.tick(60000);
    assert.equal(fixture.monitorCalls().length, started, 'An unfinished status read must share its promise and prevent overlapping polls');
    pending.resolve(envelope(report)); await flush(); assert.equal(fixture.monitorTimers().length, 1);
    await fixture.hidden(true); const hiddenReads = fixture.monitorCalls().length; await fixture.tick(60000);
    assert.equal(fixture.monitorCalls().length, hiddenReads); assert.equal(fixture.monitorTimers().length, 0);
    await fixture.hidden(false); assert.equal(fixture.monitorCalls().length, hiddenReads + 1, 'Visible resume immediately checks the local snapshot');
    await fixture.event('pagehide'); const stoppedReads = fixture.monitorCalls().length; await fixture.tick(60000);
    assert.equal(fixture.monitorCalls().length, stoppedReads); assert.equal(fixture.monitorTimers().length, 0);
    fixture.reply('list/crm_network_conflicts', envelope(report)); await fixture.event('pageshow');
    assert.equal(fixture.monitorCalls().length, stoppedReads + 1);
    const uncertain = plain(report);
    uncertain.stale = true; uncertain.unknown_address_count = 1; uncertain.address_issues_truncated = false;
    uncertain.address_issues = [{ service_id: 43, client_id: 18, family: 'IPv4', reason: 'invalid_address',
        service_url: '/crm/client/service/43', client_url: '/crm/client/18' }];
    uncertain.checked_at = '2026-10-14T13:04:00Z'; uncertain.conflicts[0].current_confirmed = false;
    uncertain.conflicts[0].owners[1].current_address_known = false; uncertain.conflicts[0].owners[1].current_address = null;
    fixture.reply('list/crm_network_conflicts', envelope(uncertain)); await fixture.tick(30000);
    assert.equal(fixture.nodes.get('crm-network-address-issues').style.display, 'block');
    assert.match(fixture.nodes.get('crm-network-conflicts-status').textContent, /1 dirección.*no se pudo comprobar/);
    assert.equal(fixture.nodes.get('crm-network-address-issues-list').children[0].children[0].children[0].href, 'https://fixture.invalid/crm/client/service/43');
    assert.equal(fixture.nodes.get('crm-network-conflicts-body').children[0].children[0].children[0].textContent, 'Pendiente de comprobar');
    assert.equal(crmOwners(fixture)[1].children[0].children[1].children[2].textContent, 'IP actual sin comprobar');
    const partialEvidence = { ...uncertain, duplicate_groups: 0, conflicts: [], checked_at: '2026-10-14T13:05:00Z' };
    fixture.reply('list/crm_network_conflicts', envelope(partialEvidence)); await fixture.tick(30000);
    assert.equal(crmOwners(fixture).length, 0, 'A newly completed collection may resolve prior known groups even if an unrelated address remains unverified');
    assert.equal(fixture.nodes.get('crm-network-warning').style.display, 'flex');
    assert.match(fixture.nodes.get('crm-network-warning-title').textContent, /IP sin comprobar en CRM: 1/);
    assert(!fixture.nodes.get('crm-network-conflicts-status').textContent.includes('No se encontraron'), 'Unknown addresses must prevent a broad all-clear claim');
    fixture.reply('list/crm_network_conflicts', envelope({ ...report, checked_at: '2026-10-14T13:00:00Z' })); await fixture.tick(30000);
    assert.equal(crmOwners(fixture).length, 0, 'Older snapshots cannot resurrect resolved groups');
    assert.match(fixture.nodes.get('crm-network-warning-title').textContent, /IP sin comprobar en CRM: 1/);
    fixture.reply('list/crm_network_conflicts', { status: 'failed', error: true, message: 'offline' }); await fixture.tick(30000);
    assert.equal(fixture.nodes.get('crm-network-address-issues-list').children.length, 1, 'A failed read retains previous unknown-address warnings and safe links');
    const empty = { ...report, duplicate_groups: 0, conflicts: [], checked_at: '2026-10-14T13:05:00Z' };
    fixture.reply('list/crm_network_conflicts', envelope(empty)); await fixture.tick(30000);
    assert.equal(fixture.nodes.get('crm-network-warning').style.display, 'none', 'Only a completed successful snapshot can clear a resolved warning');
    assert.match(fixture.nodes.get('crm-network-conflicts-status').textContent, /No se encontraron.*familias revisadas/);
    assert.equal(fixture.nodes.get('network-conflicts-count').textContent, '');
    assert.equal(fixture.api.recovery, null);
    assert(fixture.monitorCalls().every(call => call.changeType === 'list' && call.action === 'list' && !Object.keys(call.data).length), 'Polling only reads local status; no CRM scan/provision/cleanup action is sent');
    const old = { duplicate_groups: 1, conflicts: [] };
    const both = recoveryPanelFixture({ bootstrap: { readOnly: true, details: old }, details: old, initialReplies }); await flush();
    assert.equal(both.nodes.get('network-warning').style.display, 'flex'); assert.equal(both.nodes.get('crm-network-warning').style.display, 'flex');
    assert.equal(both.nodes.get('network-warning-title').textContent, 'IP duplicadas: 1 conflicto');
    assert.equal(both.nodes.get('btn-run-jobs').disabled, true, 'A genuine stored-reservation recovery must keep its existing safeguards');
    assert.match(both.nodes.get('crm-network-warning-note').textContent, /pausa por reservas guardadas/);
    assert.equal(both.nodes.get('network-conflicts-count').textContent, '2');
    both.reply('list/crm_network_conflicts', envelope(empty)); await both.tick(30000);
    assert.equal(both.nodes.get('crm-network-warning').style.display, 'none'); assert.equal(both.nodes.get('network-warning').style.display, 'flex');
    assert.equal(both.nodes.get('btn-run-jobs').disabled, true, 'Resolving the CRM warning cannot unlock separate stored-reservation recovery');
    const hostile = plain(report); hostile.conflicts[0].address = attack;
    hostile.conflicts[0].owners[0].service_status = attack;
    hostile.conflicts[0].owners[0].client_url = 'javascript:alert(1)';
    hostile.conflicts[0].owners[0].service_url = 'https://attacker.invalid/crm/client/service/42';
    const malicious = recoveryPanelFixture({ initialReplies: new Map([['list/crm_network_conflicts', envelope(hostile)]]) }); await malicious.navigate('network_conflicts');
    assert(!renderedMarkup(malicious.nodes.get('crm-network-conflicts-body')).includes('<img'));
    assert(!renderedMarkup(malicious.nodes.get('crm-network-conflicts-body')).includes('<script'));
    const identity = crmOwners(malicious)[0].children[0];
    assert.equal(identity.children[0].href, undefined); assert.equal(identity.children[1].children[0].href, undefined);
    assert.equal(identity.children[1].children[1].textContent, 'Servicio: ' + attack, 'External service status remains literal text');
    const storage = new Map();
    const navigation = recoveryPanelFixture({ storage, initialReplies }); await navigation.navigate('network_conflicts');
    navigation.surface().scrollTop = 30; await navigation.event('beforeunload');
    const key = [...storage.keys()][0], serialized = storage.get(key), state = JSON.parse(serialized);
    assert.deepEqual(state.positions.network_conflicts.anchor, { serviceId: 42, offset: 0, occurrence: 0, source: 'crm_snapshot' });
    assert(!serialized.includes('192.0.2.77') && !serialized.includes('checked_at') && !serialized.includes('client_id'), 'CRM snapshot details must never be persisted with navigation');
    const reordered = plain(report); reordered.conflicts[0].owners.reverse();
    const reload = recoveryPanelFixture({ storage, initialReplies: new Map([['list/crm_network_conflicts', envelope(reordered)]]) }); await flush();
    assert.equal(reload.nodes.get('view-network_conflicts').style.display, 'block');
    assert.equal(reload.surface().scrollTop, 170, 'CRM source anchors restore the same service row after a snapshot reorder');
    const sharedDetails = { duplicate_groups: 1, conflicts: [{ family: 'IPv4', address: '192.0.2.77', service_count: 2, service_ids: [42, 44],
        owners: [{ service_id: 42, source: 'database' }, { service_id: 44, source: 'database' }] }] };
    const legacyStorage = new Map([[key, JSON.stringify({ version: 1, scope: 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php',
        view: 'network_conflicts', positions: { network_conflicts: { scrollTop: 30,
            anchor: { serviceId: 42, offset: 0, occurrence: 0 }, focus: { serviceId: 42, action: 'service' } } } })]]);
    const shared = recoveryPanelFixture({ bootstrap: { readOnly: true, details: sharedDetails }, details: sharedDetails, storage: legacyStorage, initialReplies }); await flush();
    assert.equal(shared.surface().scrollTop, 310, 'Pre-monitor navigation anchors stay on saved reservations even when the CRM pane contains the same service');
    assert.equal(shared.document.activeElement, shared.nodes.get('network-conflicts-body').children[0].children[1].children[0].children[1], 'Stored verification focus must never jump to a CRM snapshot link');
    const noSnapshot = recoveryPanelFixture(); await noSnapshot.navigate('network_conflicts');
    assert.match(noSnapshot.nodes.get('crm-network-conflicts-status').textContent, /primera revisión/);
    assert.equal(noSnapshot.nodes.get('crm-network-warning').style.display, 'none');
    for (const source of ['input', 'reserved', 'applied']) {
        const blocking = source === 'input' ? 501 : 509;
        const jobs = recoveryPanelFixture({ initialReplies: new Map([['list/jobs', envelope({ Q1: { serviceId: 501, deviceId: 1, state: 'pending',
            error: `invalid_network_address: requested_service=501 blocking_service=${blocking} source=${source} family=IPv4` } })]]) });
        await jobs.navigate('jobs'); const jobHtml = jobs.tables.get('#jobs-table tbody').children[0].innerHTML;
        assert(!jobHtml.includes('invalid_network_address:'), 'Recognized address diagnostics should explain the owner and family in plain language');
        assert(jobHtml.includes('/crm/client/service/' + blocking));
        assert(jobHtml.includes('noopener noreferrer')); assert(jobHtml.includes('IPv4'));
    }
    for (const error of [
        'invalid_network_address: requested_service=501 blocking_service=509 source=input family=IPv4',
        'invalid_network_address: requested_service=502 blocking_service=509 source=reserved family=IPv4',
        'invalid_network_address: requested_service=501 blocking_service=9007199254740999 source=reserved family=IPv4',
        'invalid_network_address: requested_service=501 blocking_service=509 source=' + attack + ' family=IPv4'
    ]) {
        const jobs = recoveryPanelFixture({ initialReplies: new Map([['list/jobs', envelope({ Q1: { serviceId: 501, state: 'pending', error } })]]) });
        await jobs.navigate('jobs'); const jobHtml = jobs.tables.get('#jobs-table tbody').children[0].innerHTML;
        assert(!jobHtml.includes('href='), 'Invalid or unrelated diagnostic metadata must never create an owner link');
        assert(!jobHtml.includes('<img') && !jobHtml.includes('<script'));
    }
    for (const source of ['reserved', 'cache', 'applied']) {
        const jobs = recoveryPanelFixture({ initialReplies: new Map([['list/jobs', envelope({ Q1: { serviceId: 501, state: 'pending',
            error: `network_address_conflict: requested_service=501 blocking_service=509 source=${source} family=IPv6` } })]]) });
        await jobs.navigate('jobs'); const jobHtml = jobs.tables.get('#jobs-table tbody').children[0].innerHTML;
        assert(!jobHtml.includes('network_address_conflict:') && jobHtml.includes('reservado por el servicio 509') && jobHtml.includes('IPv6'));
        assert(jobHtml.includes('/crm/client/service/509') && jobHtml.includes('noopener noreferrer'));
        assert(jobHtml.includes('Trabajo solicitante: servicio #501.') && jobHtml.includes('/crm/client/service/501') && jobHtml.includes('Servicio con reserva #509'), 'The requesting job and protected reservation must be distinguished explicitly');
        assert.equal(jobs.calls.filter(call => call.changeType !== 'list').length, 0, 'A valid ownership conflict must never trigger an automatic repair or deletion from the panel');
    }
    const relatedJobs = recoveryPanelFixture({ initialReplies: new Map([['list/jobs', envelope({ Q989: { serviceId: 7279, state: 'pending', username: 'customer@48781',
        error: 'network_address_conflict: requested_service=7279 blocking_service=9807 source=reserved family=IPv4', relatedServices: [
            { serviceId: 7279, clientId: 48781 }, { serviceId: 9807, clientId: 29 }, { serviceId: 44, clientId: attack }
        ] } })]]) });
    await relatedJobs.navigate('jobs');
    const relatedDescription = relatedJobs.tables.get('#jobs-table tbody').children[0].querySelector('.job-description');
    const clientLinks = relatedDescription.children.filter(child => child.href);
    assert.deepEqual(clientLinks.map(link => link.href), ['https://fixture.invalid/crm/client/48781', 'https://fixture.invalid/crm/client/29']);
    assert(clientLinks[1].textContent.includes('9807') && clientLinks[1].textContent.includes('29'), 'Blocker client must remain distinct from the requester username');
    assert(clientLinks.every(link => link.rel === 'noopener noreferrer'));
    const reservationButton = relatedDescription.children.find(child => child.textContent === 'Revisar reserva del servicio #9807');
    assert(reservationButton, 'A pending IP blocker needs an explicit reservation review action');
    for (const listener of reservationButton.listeners.get('click') || []) await listener(); await flush();
    assert(relatedJobs.calls.some(call => call.target === 'job_reservation' && call.action === 'preview' && call.data.serviceIds[0] === 9807));
    assert(!relatedJobs.calls.some(call => call.action === 'apply'), 'Opening blocker review cannot release a reservation');
    for (const error of ['network_address_conflict: requested_service=502 blocking_service=509 source=reserved family=IPv4',
        'network_address_conflict: requested_service=501 blocking_service=9007199254740999 source=cache family=IPv4',
        'network_address_conflict: requested_service=501 blocking_service=509 source=input family=IPv4']) {
        const jobs = recoveryPanelFixture({ initialReplies: new Map([['list/jobs', envelope({ Q1: { serviceId: 501, state: 'pending', error } })]]) });
        await jobs.navigate('jobs'); assert(!jobs.tables.get('#jobs-table tbody').children[0].innerHTML.includes('href='));
    }
    const ownershipReasons = {
        identity_unverified: 'Revisa el usuario y su vínculo', router_changed: 'La configuración del router cambió',
        mapping_unverified: 'catálogo de atributos de UISP', client_unverified: 'cliente esté vigente en UISP',
        mapping_site_unverified: 'atributo de Site configurado', mapping_user_unverified: 'atributo de usuario configurado',
        mapping_hotspot_unverified: 'atributo de Hotspot configurado', marker_unverified: 'faltan datos coincidentes de usuario, Site o IP',
        target_unverified: 'comprobar el servicio actual', target_changed: 'Sincronízalo de nuevo',
        previous_unverified: 'comprobar el servicio indicado', previous_active: 'El servicio indicado sigue vigente',
        previous_job_pending: 'El servicio indicado tiene un trabajo pendiente', queue_unverified: 'comprobar los trabajos del servicio indicado',
        previous_changed: 'datos del servicio indicado cambiaron', catalog_incomplete: 'comprobación de los servicios en UISP',
        peer_site_unverified: 'mismo usuario y no se pudo comprobar su Site', account_claimed: 'Otro servicio reclama esta misma cuenta',
        verification_unavailable: 'completar la verificación con UISP', backup_failed: 'guardar el respaldo del comentario de la cuenta',
        record_changed: 'cuenta del router cambió'
    };
    for (const [reason, guidance] of Object.entries(ownershipReasons)) {
        const jobs = recoveryPanelFixture({ initialReplies: new Map([['list/jobs', envelope({ Q1: { serviceId: 501, state: 'pending',
            error: `router_ownership_conflict: current_service=501 previous_service=509 reason=${reason}` } })]]) });
        await jobs.navigate('jobs'); const jobHtml = jobs.tables.get('#jobs-table tbody').children[0].innerHTML;
        assert(!jobHtml.includes('router_ownership_conflict:') && jobHtml.includes(guidance), 'Ownership diagnostics must explain the actual reason: ' + reason);
        assert(jobHtml.includes('/crm/client/service/501') && jobHtml.includes('/crm/client/service/509') && jobHtml.includes('noopener noreferrer'));
        assert(jobHtml.includes('Servicio actual #501') && jobHtml.includes('Servicio indicado #509'));
        assert(jobHtml.includes('El comentario de la cuenta del servicio #501 indica el servicio #509. Ese ID debe comprobarse con UISP.')
            && !jobHtml.includes('servicio anterior') && !jobHtml.includes('sigue vinculada'), 'A router comment does not prove that the referenced ID is a previous owner');
        if (['previous_active', 'account_claimed', 'previous_job_pending', 'marker_unverified'].includes(reason))
            assert(!jobHtml.includes('corregirá el comentario sólo si'), 'Unverified owners require review instead of an automatic repair promise');
        if (reason === 'verification_unavailable') assert(jobHtml.includes('confirma la identidad de la cuenta')
            && jobHtml.includes('terminó, ya no existe o corresponde a otro servicio con datos distintos'));
        assert.equal(jobs.calls.filter(call => call.changeType !== 'list').length, 0, 'Rendering ownership guidance must not change or execute anything');
    }
    for (const error of ['router_ownership_conflict: current_service=502 previous_service=509 reason=previous_active',
        'router_ownership_conflict: current_service=501 previous_service=9007199254740999 reason=previous_active',
        'router_ownership_conflict: current_service=501 previous_service=501 reason=previous_active',
        'router_ownership_conflict: current_service=501 previous_service=0 reason=previous_active',
        'router_ownership_conflict: current_service=501 previous_service=509 reason=constructor',
        'router_ownership_conflict: current_service=501 previous_service=509 reason=' + attack]) {
        const jobs = recoveryPanelFixture({ initialReplies: new Map([['list/jobs', envelope({ Q1: { serviceId: 501, state: 'pending', error } })]]) });
        await jobs.navigate('jobs'); const jobHtml = jobs.tables.get('#jobs-table tbody').children[0].innerHTML;
        assert(!jobHtml.includes('href=') && !jobHtml.includes('<img') && !jobHtml.includes('<script'), 'Invalid ownership metadata must not create links or executable markup');
    }
    for (const error of ['router_ownership_conflict: current_service=501 previous_service=0 reason=identity_unverified',
        'router_ownership_record_changed',
        'The matching RouterOS account or queue belongs to another service or was created manually']) {
        const jobs = recoveryPanelFixture({ initialReplies: new Map([['list/jobs', envelope({ Q1: { serviceId: 501, state: 'pending', error } })]]) });
        await jobs.navigate('jobs'); const jobHtml = jobs.tables.get('#jobs-table tbody').children[0].innerHTML;
        assert(!jobHtml.includes('href=') && !jobHtml.includes(error), 'Unknown ownership identity should explain the issue without inventing a previous service');
    }
}

async function checkCrmNetworkResolutionPanel() {
    const envelope = data => ({ status: 'ok', error: false, data });
    const monitor = { available: true, duplicate_groups: 1, checked_at: '2026-10-14T13:00:00Z', stale: false, families: ['IPv4'], conflicts: [
        { family: 'IPv4', address: '192.0.2.77', service_ids: [42, 43], service_count: 2, owners: [
            { service_id: 42, client_id: 17, service_status_code: 2, client_url: '/crm/client/17', service_url: '/crm/client/service/42', service_status: 'Terminado' },
            { service_id: 43, client_id: 18, service_status_code: 1, client_url: '/crm/client/18', service_url: '/crm/client/service/43', service_status: 'Activo' }
        ] }
    ] };
    const checkedOwner = (id, value) => ({ service_id: id, client_id: id === 42 ? 17 : 18, service_exists: true,
        client_name: id === 42 ? '<img src=x onerror=alert(1)> Cliente de prueba' : 'Cliente actual', client_archived: id === 42,
        service_status_code: id === 42 ? 2 : 1,
        client_url: '/crm/client/' + (id === 42 ? 17 : 18), service_url: '/crm/client/service/' + id, service_status: id === 42 ? 'Terminado' : 'Activo',
        address_evidence: { address: { known: true, value }, address6: { known: true, value: null } } });
    const proof = value => ({ owners: [checkedOwner(42, value), checkedOwner(43, '192.0.2.77')], checked_at: '2026-10-14T13:01:00Z',
        snapshot_checked_at: monitor.checked_at, selection_complete: true, awaiting_full_scan: true,
        groups: [{ family: 'IPv4', address: '192.0.2.77', service_ids: [42, 43], service_count: 2, state: value === '192.0.2.77' ? 'duplicate' : 'resolved' }] });
    const storage = new Map();
    const fixture = recoveryPanelFixture({ storage, initialReplies: new Map([['list/crm_network_conflicts', envelope(monitor)],
        ['list/crm_network_resolution', envelope(proof('192.0.2.77'))]]) }); await fixture.navigate('network_conflicts');
    const row = () => fixture.nodes.get('crm-network-resolved-body').children[0] || fixture.nodes.get('crm-network-conflicts-body').children[0];
    const owner = id => row().children[1].children.find(item => Number(item.dataset.serviceId) === id);
    const action = (id, className) => owner(id).children.find(button => button.className.split(' ').includes(className));
    assert.equal(fixture.calls.filter(call => call.target === 'crm_network_resolution' || call.changeType === 'resolve').length, 0,
        'Loading automatic warnings must not trigger live reads or edits');
    await action(42, 'conflict-verify').fire('click');
    assert.deepEqual(fixture.calls.at(-1), { changeType: 'list', target: 'crm_network_resolution', action: 'verify', data: { serviceIds: [42, 43] } });
    assert.equal(owner(42).children[0].children[0].textContent, '<img src=x onerror=alert(1)> Cliente de prueba');
    assert(owner(42).children[0].children[0].className.includes('client-archived-name'));
    assert(!renderedMarkup(owner(42)).includes('<img'), 'Client identities from live verification remain literal text');
    assert.match(row().children[0].children[0].textContent, /todavía comparten una IP/);
    assert.equal(fixture.nodes.get('save-device-btn').disabled, false);
    await action(42, 'conflict-edit-ip').fire('click');
    assert.equal(fixture.nodes.get('crm-ip-edit-modal').style.display, 'flex');
    assert.equal(fixture.nodes.get('crm-ip-current').textContent, '192.0.2.77');
    assert.equal(fixture.nodes.get('crm-ip-address').value, '192.0.2.77');
    assert.equal(fixture.nodes.get('apply-crm-ip-change').disabled, true);
    const token = 'c'.repeat(64);
    let previewResponse = { previewToken: token, expiresAt: '2099-10-14T13:00:00Z', service_id: 42, client_id: 17, family: 'IPv4', operation: 'remove',
        current_address: '192.0.2.77', current_address_known: true, new_address: null, uniqueness_checked: false };
    fixture.reply('resolve/crm_network_conflicts', payload => envelope(payload.action === 'preview' ? previewResponse : {
        service_id: 42, family: 'IPv4', operation: 'remove', applied: true, uncertain: false, current_address: null,
        current_address_known: true, local_cleanup_required: false, requires_sync: true, awaiting_full_scan: true
    }));
    fixture.nodes.get('crm-ip-operation').value = 'remove'; await fixture.nodes.get('crm-ip-operation').fire('change');
    assert.equal(fixture.nodes.get('crm-ip-address').disabled, true, 'Removal must be an explicit choice, not an empty replace field');
    await fixture.nodes.get('review-crm-ip-change').fire('click');
    assert.deepEqual(fixture.calls.at(-1), { changeType: 'resolve', target: 'crm_network_conflicts', action: 'preview', data: { serviceId: 42, family: 'IPv4', operation: 'remove' } });
    assert.equal(fixture.calls.filter(call => call.changeType === 'resolve' && call.action === 'apply').length, 0, 'Review alone never writes CRM');
    assert.match(fixture.nodes.get('crm-ip-review').innerHTML, /192.0.2.77/);
    assert.match(fixture.nodes.get('crm-ip-review').innerHTML, /Sin IP asignada/);
    const confirmation = () => fixture.document.body.children.find(node => node.className === 'confirm-overlay');
    const cancelled = fixture.nodes.get('apply-crm-ip-change').fire('click'); await flush();
    assert.match(confirmation().children[0].children[0].textContent, /IPv4.*servicio #42.*Sin IP asignada/);
    await confirmation().children[0].children[1].children[0].fire('click'); await cancelled;
    assert.equal(fixture.calls.filter(call => call.changeType === 'resolve' && call.action === 'apply').length, 0);
    fixture.nodes.get('crm-ip-operation').value = 'replace'; await fixture.nodes.get('crm-ip-operation').fire('change');
    assert.equal(fixture.nodes.get('apply-crm-ip-change').disabled, true, 'Changing the draft invalidates the reviewed token');
    fixture.nodes.get('crm-ip-address').value = ''; await fixture.nodes.get('review-crm-ip-change').fire('click');
    assert.match(fixture.nodes.get('crm-ip-edit-status').textContent, /IP válida/);
    fixture.nodes.get('crm-ip-operation').value = 'remove'; await fixture.nodes.get('crm-ip-operation').fire('change');
    await fixture.nodes.get('review-crm-ip-change').fire('click');
    fixture.reply('list/crm_network_resolution', envelope(proof(null)));
    const applying = fixture.nodes.get('apply-crm-ip-change').fire('click'); await flush();
    await confirmation().children[0].children[1].children[1].fire('click'); await applying;
    assert.equal(fixture.calls.filter(call => call.changeType === 'resolve' && call.action === 'apply').length, 1);
    assert.deepEqual(fixture.calls.filter(call => call.changeType === 'resolve' && call.action === 'apply')[0].data, { previewToken: token });
    assert.equal(fixture.nodes.get('crm-ip-current').textContent, 'Sin IP asignada', 'After apply, the modal current IP must reflect the verified new state');
    assert.equal(fixture.nodes.get('crm-ip-address').value, '', 'Removing an IP must not keep the old duplicate as the next replacement draft');
    assert.match(row().children[0].children[0].textContent, /Resolución verificada.*ya no cuenta como pendiente/);
    assert.equal(fixture.nodes.get('crm-network-resolved').style.display, 'block');
    assert.equal(fixture.nodes.get('crm-network-warning').style.display, 'none', 'A complete current group proof removes its pending warning immediately');
    assert.equal(fixture.nodes.get('network-conflicts-count').textContent, '');
    assert.equal(fixture.api.recovery, null); assert.equal(fixture.nodes.get('btn-run-jobs').disabled, false);
    await fixture.event('pagehide');
    assert([...storage.values()].every(value => !value.includes('192.0.2.77') && !value.includes(token) && !value.includes('Cliente de prueba')),
        'IP drafts, tokens and client details must not enter navigation storage');
    fixture.reply('list/crm_network_conflicts', envelope({ ...monitor, checked_at: '2026-10-14T13:02:00Z' }));
    await fixture.event('pageshow');
    assert.equal(row().children[0].children.length, 0, 'A later full snapshot must remove old resolved badges when a duplicate recurs');
    assert.equal(owner(42).children[0].children[0].textContent, 'Cliente #17', 'A later snapshot must not retain previous client/address verification as current');
    fixture.reply('list/crm_network_conflicts', { status: 'failed', error: true, message: 'offline' }); await fixture.tick(30000);
    assert.equal(owner(42).children[0].children[0].textContent, 'Cliente #17');
    assert.match(fixture.nodes.get('crm-network-warning-freshness').textContent, /desactualizado/);
    const cleanupToken = 'd'.repeat(64), backup = 'network-cleanup-20261014T130200Z-' + 'd'.repeat(32) + '.sqlite';
    fixture.reply('cleanup/crm_network_conflicts', payload => envelope(payload.action === 'preview' ? {
        previewToken: cleanupToken, expiresAt: '2099-10-14T13:00:00Z', candidates: [{ service_id: 42, reason: 'stale_address', fields: ['address'],
            evidence: [{ field: 'address', saved_address: '192.0.2.77', current_address: null, state: 'removed' }], witness_service_ids: [43] }], blocked: []
    } : { removed_service_ids: [42], backup_name: backup, duplicate_groups: 0, requires_reload: false }));
    await action(42, 'conflict-cleanup').fire('click');
    assert.equal(fixture.calls.at(-1).target, 'crm_network_conflicts');
    const candidate = fixture.nodes.get('network-cleanup-candidates').children[0].children[0]; candidate.checked = true; await candidate.fire('change');
    const cleaning = fixture.nodes.get('apply-network-cleanup').fire('click'); await flush();
    await confirmation().children[0].children[1].children[1].fire('click'); await cleaning;
    assert.equal(fixture.api.recovery, null, 'Healthy CRM cleanup must never manufacture legacy read-only recovery');
    assert.equal(fixture.nodes.get('save-device-btn').disabled, false); assert.equal(fixture.nodes.get('btn-run-jobs').disabled, false);
    assert.equal(fixture.nodes.get('reload-after-cleanup').style.display, 'none');
    assert.match(fixture.nodes.get('network-cleanup-result').children[0].textContent, /verificación del CRM se muestra por separado/);
    assert.equal(action(42, 'conflict-cleanup').textContent, 'Ya limpiada');
    assert.equal(action(42, 'conflict-cleanup').disabled, true);
    assert.equal(fixture.nodes.get('network-conflicts-count').textContent, '1');
    const mismatch = recoveryPanelFixture({ initialReplies: new Map([['list/crm_network_conflicts', envelope(monitor)],
        ['list/crm_network_resolution', envelope(proof('192.0.2.77'))],
        ['resolve/crm_network_conflicts', envelope({ ...previewResponse, service_id: 99 })]]) }); await mismatch.navigate('network_conflicts');
    await mismatch.nodes.get('crm-network-conflicts-body').children[0].children[1].children[0].children[2].fire('click');
    mismatch.nodes.get('crm-ip-operation').value = 'remove'; await mismatch.nodes.get('crm-ip-operation').fire('change');
    await mismatch.nodes.get('review-crm-ip-change').fire('click');
    assert.equal(mismatch.nodes.get('apply-crm-ip-change').disabled, true, 'A preview for another service cannot authorize an edit');
    assert.match(mismatch.nodes.get('crm-ip-edit-status').textContent, /Respuesta inválida/);
}

async function checkCrmHistoricalResolutionPanel() {
    const envelope = data => ({ status: 'ok', error: false, data });
    const monitor = { available: true, duplicate_groups: 1, checked_at: '2026-10-14T14:00:00Z', stale: false, families: ['IPv4'], conflicts: [
        { family: 'IPv4', address: '192.0.2.80', service_ids: [42, 43], service_count: 2, owners: [
            { service_id: 42, client_id: 17, service_status_code: 2, service_status: 'Terminado', service_url: '/crm/client/service/42', client_url: '/crm/client/17' },
            { service_id: 43, client_id: 18, service_status_code: 1, service_status: 'Activo', service_url: '/crm/client/service/43', client_url: '/crm/client/18', client_archived: true }
        ] }
    ] };
    const current = (historical = false, status = 2) => ({ service_id: 42, client_id: 17, service_exists: true, service_status_code: status,
        service_status: status === 2 ? 'Terminado' : 'Activo', service_url: '/crm/client/service/42', client_url: '/crm/client/17', client_name: 'Cliente histórico',
        historical_families: historical ? ['IPv4'] : [], address_evidence: { address: { known: true, value: '192.0.2.80' }, address6: { known: true, value: null } } });
    const verification = (historical = false, status = 2) => ({ owners: [current(historical, status), { ...monitor.conflicts[0].owners[1],
        service_exists: true, address_evidence: { address: { known: true, value: '192.0.2.80' }, address6: { known: true, value: null } } }],
        groups: [{ family: 'IPv4', address: '192.0.2.80', service_ids: [42, 43], service_count: 2,
        state: historical ? 'resolved' : 'unknown' }], snapshot_checked_at: monitor.checked_at, checked_at: '2026-10-14T14:01:00Z', awaiting_full_scan: true });
    const preview = { service_id: 42, client_id: 17, family: 'IPv4', operation: 'history', historical: true, preserves_crm_history: true,
        requires_sync: false, current_address: '192.0.2.80', current_address_known: true, new_address: '192.0.2.80',
        previewToken: 'e'.repeat(64), expiresAt: '2099-10-14T14:00:00Z' };
    const fixture = recoveryPanelFixture({ initialReplies: new Map([['list/crm_network_conflicts', envelope(monitor)],
        ['list/crm_network_resolution', envelope(verification())], ['resolve/crm_network_conflicts', envelope(preview)]]) });
    await fixture.navigate('network_conflicts');
    const owners = () => (fixture.nodes.get('crm-network-resolved-body').children[0] || fixture.nodes.get('crm-network-conflicts-body').children[0]).children[1].children;
    assert(owners()[0].children.find(button => button.className.includes('conflict-history')));
    assert(!owners()[1].children.find(button => button.className.includes('conflict-history')), 'An archived client with an active service does not authorize historical resolution');
    await owners()[0].children.find(button => button.className.includes('conflict-history')).fire('click');
    assert.equal(fixture.nodes.get('crm-ip-edit-title').textContent, 'Resolver asignación histórica');
    assert.equal(fixture.nodes.get('crm-ip-operation-group').style.display, 'none');
    assert.equal(fixture.nodes.get('crm-ip-address-group').style.display, 'none');
    assert.match(fixture.nodes.get('crm-ip-edit-note').textContent, /historial del CRM.*routers.*reactiva el servicio/);
    await fixture.nodes.get('review-crm-ip-change').fire('click');
    assert.deepEqual(fixture.calls.at(-1), { changeType: 'resolve', target: 'crm_network_conflicts', action: 'preview',
        data: { serviceId: 42, family: 'IPv4', operation: 'history' } });
    assert.match(fixture.nodes.get('crm-ip-review').innerHTML, /IP histórica que se excluirá/);
    assert(!fixture.nodes.get('crm-ip-review').innerHTML.includes('IP propuesta'));
    fixture.reply('list/crm_network_resolution', envelope(verification(true)));
    fixture.reply('resolve/crm_network_conflicts', envelope({ service_id: 42, family: 'IPv4', operation: 'history', historical: true,
        preserves_crm_history: true, requires_sync: false, applied: true, uncertain: false, current_address: '192.0.2.80',
        current_address_known: true, local_cleanup_required: false, awaiting_full_scan: true }));
    const applying = fixture.nodes.get('apply-crm-ip-change').fire('click'); await flush();
    const confirm = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
    assert.match(confirm.children[0].children[0].textContent, /Excluir.*histórica.*192.0.2.80.*IPv4.*terminado #42.*historial del CRM y los routers/);
    await confirm.children[0].children[1].children[1].fire('click'); await applying;
    assert.equal(fixture.nodes.get('crm-ip-result').dataset.state, 'confirmed');
    assert.equal(fixture.nodes.get('crm-ip-result-title').textContent, 'Asignación histórica resuelta');
    assert.match(fixture.nodes.get('crm-ip-result-message').textContent, /CRM conserva la IP y su historial/);
    assert(!fixture.nodes.get('crm-ip-result-message').textContent.includes('sincroniza'), 'History resolution must not suggest a router synchronization');
    assert.equal(fixture.nodes.get('crm-ip-form').style.display, 'none', 'Historical apply ends in a result rather than reopening the reviewed form');
    assert.equal(fixture.nodes.get('crm-ip-current').textContent, '192.0.2.80', 'Exclusion must visibly preserve the CRM history address');
    assert.equal(fixture.nodes.get('crm-network-warning').style.display, 'none', 'A verified complete historical resolution immediately leaves the pending list');
    assert.equal(fixture.nodes.get('crm-network-resolved').style.display, 'block');
    assert(!owners()[0].children.find(button => button.className.includes('conflict-history')),
        'A freshly reviewed family must not offer repeated exclusion while awaiting the full scan');
    const historicalEntry = { service_id: 42, client_id: 17, service_status_code: 2, service_status: 'Terminado', family: 'IPv4', address: '192.0.2.80',
        client_url: '/crm/client/17', service_url: '/crm/client/service/42', historical_reviewed: true };
    fixture.reply('list/crm_network_conflicts', envelope({ ...monitor, checked_at: '2026-10-14T14:02:00Z', duplicate_groups: 0, conflicts: [],
        historical_assignments_count: 1, historical_assignments: [historicalEntry] })); await fixture.tick(30000);
    assert.equal(fixture.nodes.get('crm-network-warning').style.display, 'none');
    assert.equal(fixture.nodes.get('crm-network-history').style.display, 'block');
    const historyOwner = fixture.nodes.get('crm-network-history-list').children[0];
    assert(historyOwner.children.find(button => button.className.includes('conflict-cleanup')), 'Reviewed assignments keep safe local cleanup reachable after the warning disappears');
    assert(historyOwner.children.find(button => button.className.includes('conflict-verify')));
    assert(!historyOwner.children.find(button => button.className.includes('conflict-history')), 'A reviewed historical record must not offer repeated exclusion');
    assert.equal(fixture.api.recovery, null); assert.equal(fixture.nodes.get('save-device-btn').disabled, false);
    fixture.reply('cleanup/crm_network_conflicts', envelope({ previewToken: 'f'.repeat(64), expiresAt: '2099-10-14T14:00:00Z', candidates: [{
        service_id: 42, reason: 'stale_address', historical_reviewed: true, fields: ['address'],
        evidence: [{ field: 'address', saved_address: '192.0.2.80', current_address: '192.0.2.80', state: 'historical' }]
    }], blocked: [] }));
    await historyOwner.children.find(button => button.className.includes('conflict-cleanup')).fire('click');
    const historicCandidate = fixture.nodes.get('network-cleanup-candidates').children[0];
    assert(historicCandidate, 'A proven historical reservation can be reviewed without deleting its CRM IP');
    assert.match(historicCandidate.children[1].children[0].textContent, /histórica revisada.*reserva local.*historial del CRM/);
    assert.equal(fixture.nodes.get('apply-network-cleanup').disabled, true, 'History cleanup still requires explicit checkbox selection');
    const stale = recoveryPanelFixture({ initialReplies: new Map([['list/crm_network_conflicts', envelope(monitor)],
        ['list/crm_network_resolution', envelope(verification(false, 1))]]) }); await stale.navigate('network_conflicts');
    await stale.nodes.get('crm-network-conflicts-body').children[0].children[1].children[0].children.find(button => button.className.includes('conflict-history')).fire('click');
    assert.equal(stale.nodes.get('review-crm-ip-change').disabled, true, 'A service reactivated after the snapshot cannot be reviewed as history');
    assert.match(stale.nodes.get('crm-ip-edit-status').textContent, /sólo.*terminado/);
    assert.equal(stale.calls.filter(call => call.changeType === 'resolve').length, 0);
}

async function checkCrmEditResultFeedback() {
    const envelope = data => ({ status: 'ok', error: false, data });
    const oldAddress = '192.0.2.77', newAddress = '192.0.2.78';
    const snapshot = status => ({ available: true, duplicate_groups: 1, checked_at: '2026-10-14T13:00:00Z',
        completed_scan_started_at: '2026-10-14T12:59:00Z', stale: false, families: ['IPv4'], conflicts: [{ family: 'IPv4', address: oldAddress,
            service_ids: [42, 43], service_count: 2, owners: [42, 43].map(id => ({ service_id: id, client_id: id === 42 ? 17 : 18,
                service_status_code: id === 42 ? status : 1, service_status: id === 42 && status === 2 ? 'Terminado' : 'Activo',
                service_url: '/crm/client/service/' + id, client_url: '/crm/client/' + (id === 42 ? 17 : 18) })) }] });
    const owner = (value, status = 1, extra = {}) => ({ service_id: 42, client_id: 17, service_exists: true, service_status_code: status,
        service_status: status === 2 ? 'Terminado' : 'Activo', client_name: 'Cliente de ejemplo', client_url: '/crm/client/17',
        service_url: '/crm/client/service/42', address_verified_at: '2026-10-14T13:01:00Z', historical_families: [],
        address_evidence: { address: { known: true, value }, address6: { known: true, value: null } }, ...extra });
    const textTree = node => String(node.textContent || '') + node.innerHTML + node.children.map(textTree).join('');
    const fixtureFor = async ({ status = 1, operation = 'remove', apply = {}, fresh = owner(null), localCleanup = false } = {}) => {
        const report = snapshot(status);
        let nextOwner = owner(oldAddress, status);
        const verification = () => ({ owners: [nextOwner, { ...report.conflicts[0].owners[1], service_exists: true,
            address_verified_at: '2026-10-14T13:01:00Z', address_evidence: { address: { known: true, value: oldAddress }, address6: { known: true, value: null } } }],
            checked_at: '2026-10-14T13:01:00Z', snapshot_checked_at: report.checked_at, selection_complete: true, awaiting_full_scan: true,
            groups: [{ ...report.conflicts[0], state: nextOwner.service_exists === true && nextOwner.client_id === 17
                && nextOwner.address_evidence?.address?.known === true
                && (nextOwner.address_evidence.address.value !== oldAddress || (nextOwner.service_status_code === 2 && nextOwner.historical_families?.includes('IPv4')))
                ? 'resolved' : 'duplicate' }] });
        const intended = operation === 'remove' ? null : operation === 'history' ? oldAddress : newAddress;
        const applyResult = { service_id: 42, client_id: 17, family: 'IPv4', operation, applied: true, uncertain: false,
            current_address_known: true, current_address: intended, local_cleanup_required: localCleanup,
            requires_sync: operation !== 'history', awaiting_full_scan: true,
            ...(operation === 'history' ? { historical: true, preserves_crm_history: true } : {}), ...apply };
        const token = 'a'.repeat(64);
        const fixture = recoveryPanelFixture({ initialReplies: new Map([['list/crm_network_conflicts', envelope(report)],
            ['list/crm_network_resolution', () => envelope(verification())], ['resolve/crm_network_conflicts', payload => envelope(payload.action === 'preview'
                ? { previewToken: token, expiresAt: '2099-10-14T13:00:00Z', service_id: 42, client_id: 17, family: 'IPv4',
                    operation: payload.data.operation, current_address: nextOwner.address_evidence.address.value, current_address_known: true,
                    new_address: payload.data.operation === 'remove' ? null : payload.data.operation === 'history' ? nextOwner.address_evidence.address.value : payload.data.address,
                    ...(payload.data.operation === 'history' ? { historical: true, preserves_crm_history: true, requires_sync: false } : {}) }
                : applyResult)]]) });
        await fixture.navigate('network_conflicts');
        const target = fixture.nodes.get('crm-network-conflicts-body').children[0].children[1].children[0];
        await target.children.find(button => button.className.includes(operation === 'history' ? 'conflict-history' : 'conflict-edit-ip')).fire('click');
        if (operation !== 'history') {
            fixture.nodes.get('crm-ip-operation').value = operation; await fixture.nodes.get('crm-ip-operation').fire('change');
            if (operation === 'replace') fixture.nodes.get('crm-ip-address').value = newAddress;
        }
        await fixture.nodes.get('review-crm-ip-change').fire('click');
        assert.equal(fixture.nodes.get('apply-crm-ip-change').disabled, false);
        nextOwner = fresh;
        return { fixture, report, token, setOwner(value) { nextOwner = value; }, verification,
            async apply(accept = true) {
                const applying = fixture.nodes.get('apply-crm-ip-change').fire('click'); await flush();
                const confirmation = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
                assert(confirmation); await confirmation.children[0].children[1].children[accept ? 1 : 0].fire('click');
                await applying; return fixture;
            } };
    };
    const terminal = fixture => {
        assert.equal(fixture.nodes.get('crm-ip-form').style.display, 'none', 'A consumed edit attempt must not return to the editable form');
        assert.equal(fixture.nodes.get('crm-ip-result').style.display, 'block');
        assert.equal(fixture.nodes.get('apply-crm-ip-change').disabled, true, 'A result cannot replay its consumed preview token');
        assert.equal(fixture.nodes.get('review-crm-ip-change').disabled, true);
    };
    const assertReadOnlyRetry = (fixture, before) => {
        const followup = fixture.calls.slice(before);
        assert(followup.length > 0);
        assert(followup.every(call => call.changeType === 'list'), 'Verify again only reads; it cannot preview, apply, clean, or provision');
        const live = followup.filter(call => call.target === 'crm_network_resolution');
        assert.equal(live.length, 1, 'One retry performs one bounded live owner verification');
        assert.deepEqual(live[0].data, { serviceIds: [42, 43] });
    };

    for (const operation of ['remove', 'replace']) {
        const flow = await fixtureFor({ operation, fresh: owner(operation === 'remove' ? null : newAddress) });
        const before = flow.fixture.calls.length;
        await flow.apply(); terminal(flow.fixture);
        assert.equal(flow.fixture.nodes.get('crm-ip-result').dataset.state, 'confirmed');
        assert.match(textTree(flow.fixture.nodes.get('crm-ip-result-title')), operation === 'remove' ? /IP retirada|IP quitada/ : /IP cambiada|IP actualizada|Cambio confirmado/);
        const summary = textTree(flow.fixture.nodes.get('crm-ip-result-summary'));
        assert(summary.includes(oldAddress)); assert(summary.includes(operation === 'remove' ? 'Sin IP asignada' : newAddress));
        assert.equal(flow.fixture.calls.slice(before).filter(call => call.target === 'crm_network_resolution').length, 1);
        assert(flow.fixture.calls.slice(before).some(call => call.changeType === 'list' && call.target === 'crm_network_conflicts'),
            'Completing a write refreshes the local monitor report as well as the selected live owner group');
        assert.equal(flow.fixture.nodes.get('crm-network-warning').style.display, 'none');
        assert.equal(flow.fixture.nodes.get('crm-network-resolved').style.display, 'block');
        assert.equal(flow.fixture.nodes.get('verify-crm-ip-result').disabled, false);
        assert.equal(flow.fixture.nodes.get('cleanup-crm-ip-result').style.display, 'none', 'No obsolete local reservation means no cleanup prompt');
    }

    const uncertain = await fixtureFor({ apply: { applied: false, uncertain: true, current_address: oldAddress }, fresh: owner(oldAddress) });
    await uncertain.apply(); terminal(uncertain.fixture);
    assert.equal(uncertain.fixture.nodes.get('crm-ip-result').dataset.state, 'unchanged');
    assert.match(textTree(uncertain.fixture.nodes.get('crm-ip-result-title')), /IP anterior/);
    assert.equal(uncertain.fixture.nodes.get('resolve-crm-ip-history').style.display, 'none', 'Active services do not receive an ended-service fallback');
    const beforeRetry = uncertain.fixture.calls.length;
    uncertain.setOwner(owner(null));
    await uncertain.fixture.nodes.get('verify-crm-ip-result').fire('click'); terminal(uncertain.fixture);
    assertReadOnlyRetry(uncertain.fixture, beforeRetry);
    assert.equal(uncertain.fixture.nodes.get('crm-ip-result').dataset.state, 'observed');
    assert.match(textTree(uncertain.fixture.nodes.get('crm-ip-result-title')), /muestra el cambio solicitado/);
    assert.equal(uncertain.fixture.calls.filter(call => call.changeType === 'resolve' && call.action === 'apply').length, 1,
        'Observing the requested state never resubmits the original PATCH');

    const rejected = await fixtureFor({ status: 2, apply: { applied: false, uncertain: false, current_address: oldAddress,
        write_outcome: 'rejected', write_http_status: 422, write_reason: 'validation_failed' }, fresh: owner(oldAddress, 2) });
    await rejected.apply(); terminal(rejected.fixture);
    assert.equal(rejected.fixture.nodes.get('crm-ip-result').dataset.state, 'rejected');
    assert.equal(rejected.fixture.nodes.get('crm-ip-result-title').textContent, 'UISP rechazó el cambio');
    assert.match(textTree(rejected.fixture.nodes.get('crm-ip-result-detail')), /rechazó los datos.*HTTP de UISP: 422/);
    assert.equal(rejected.fixture.nodes.get('resolve-crm-ip-history').style.display, 'inline-flex', 'Rejected edits of ended services keep the separately reviewed history option');
    const rejectedRetry = rejected.fixture.calls.length;
    rejected.setOwner(owner(null, 2)); await rejected.fixture.nodes.get('verify-crm-ip-result').fire('click');
    assertReadOnlyRetry(rejected.fixture, rejectedRetry);
    assert.equal(rejected.fixture.nodes.get('crm-ip-result').dataset.state, 'observed', 'Observing desired state after a rejected write is not an acknowledgement of that write');
    assert.match(textTree(rejected.fixture.nodes.get('crm-ip-result-detail')), /rechazó los datos/);
    const rejectedObserved = await fixtureFor({ apply: { applied: true, uncertain: false, current_address: null,
        write_outcome: 'rejected', write_http_status: 409, write_reason: 'conflict' }, fresh: owner(null) });
    await rejectedObserved.apply();
    assert.equal(rejectedObserved.fixture.nodes.get('crm-ip-result').dataset.state, 'observed');
    assert(!textTree(rejectedObserved.fixture.nodes.get('crm-ip-result-message')).includes('UISP confirmó el cambio'));
    const acceptedUnobserved = await fixtureFor({ apply: { applied: false, uncertain: true, current_address: oldAddress,
        write_outcome: 'accepted', write_http_status: 200, write_reason: null }, fresh: owner(oldAddress) });
    await acceptedUnobserved.apply();
    assert.equal(acceptedUnobserved.fixture.nodes.get('crm-ip-result').dataset.state, 'unchanged');
    assert.match(textTree(acceptedUnobserved.fixture.nodes.get('crm-ip-result-detail')), /aceptó la solicitud/);

    const unknownOwner = owner(null, 1, { address_evidence: { address: { known: false, value: null }, address6: { known: true, value: null } } });
    const unknown = await fixtureFor({ apply: { applied: false, uncertain: true, current_address_known: false, current_address: null }, fresh: unknownOwner });
    await unknown.apply(); terminal(unknown.fixture);
    assert.equal(unknown.fixture.nodes.get('crm-ip-result').dataset.state, 'unknown');
    assert.match(textTree(unknown.fixture.nodes.get('crm-ip-result-summary')), /Sin comprobar/);
    assert.equal(unknown.fixture.nodes.get('cleanup-crm-ip-result').style.display, 'none');
    assert.equal(unknown.fixture.nodes.get('resolve-crm-ip-history').style.display, 'none');
    const queryFailure = unknown.fixture.calls.length;
    unknown.fixture.reply('list/crm_network_resolution', { status: 'failed', error: true, message: 'offline' });
    await unknown.fixture.nodes.get('verify-crm-ip-result').fire('click'); terminal(unknown.fixture);
    assert.equal(unknown.fixture.nodes.get('crm-ip-result').dataset.state, 'unknown');
    assert.equal(unknown.fixture.nodes.get('verify-crm-ip-result').disabled, false, 'An unavailable CRM leaves an explicit retry button');
    assertReadOnlyRetry(unknown.fixture, queryFailure);

    const receipt = await fixtureFor();
    receipt.fixture.reply('list/crm_network_resolution', { status: 'failed', error: true, message: 'fixture read unavailable' });
    await receipt.apply(); terminal(receipt.fixture);
    assert.equal(receipt.fixture.nodes.get('crm-ip-result').dataset.state, 'confirmed_pending',
        'A confirmed write receipt remains visible when its later query fails');
    assert.match(textTree(receipt.fixture.nodes.get('crm-ip-result-message')), /confirmó.*guardar.*consulta posterior no pudo/);
    assert.match(textTree(receipt.fixture.nodes.get('crm-ip-result-detail')), /fixture read unavailable/);
    assert.equal(receipt.fixture.nodes.get('cleanup-crm-ip-result').style.display, 'none', 'An unavailable query cannot prove obsolete local ownership');
    receipt.fixture.reply('list/crm_network_resolution', () => envelope(receipt.verification()));
    await receipt.fixture.nodes.get('verify-crm-ip-result').fire('click');
    assert.equal(receipt.fixture.nodes.get('crm-ip-result').dataset.state, 'confirmed');
    assert.equal(receipt.fixture.calls.filter(call => call.changeType === 'resolve' && call.action === 'apply').length, 1);

    for (const applyReply of [
        { status: 'failed', error: true, message: 'fixture PATCH failed' },
        envelope({ service_id: 99, family: 'IPv4', operation: 'remove', applied: true, uncertain: false, current_address_known: true, current_address: null }),
        envelope({ service_id: 42, family: 'IPv4', operation: 'remove', applied: true, uncertain: false, current_address_known: true, current_address: oldAddress })
    ]) {
        const failed = await fixtureFor({ fresh: owner(oldAddress) });
        failed.fixture.reply('resolve/crm_network_conflicts', applyReply);
        const callsBefore = failed.fixture.calls.length;
        await failed.apply(); terminal(failed.fixture);
        assert.equal(failed.fixture.nodes.get('crm-ip-result').dataset.state, 'unchanged', 'An error or incompatible receipt cannot become a successful edit');
        assert.match(textTree(failed.fixture.nodes.get('crm-ip-result-detail')), /Detalle:/);
        assert.equal(failed.fixture.calls.slice(callsBefore).filter(call => call.target === 'crm_network_resolution').length, 1,
            'Ambiguous failed attempts also read back the service without replaying their token');
        await failed.fixture.nodes.get('apply-crm-ip-change').fire('click');
        assert.equal(failed.fixture.calls.filter(call => call.changeType === 'resolve' && call.action === 'apply').length, 1);
    }

    for (const [state, fresh] of [
        ['identity_changed', owner(null, 2, { client_id: 99 })],
        ['absent', owner(null, 2, { service_exists: false })],
        ['absent', owner(null, 2, { service_exists: false, client_id: null })],
        ['unknown', owner(null, 2, { service_exists: null, client_id: null })],
        ['unknown', owner(null, 2, { service_exists: true, client_id: null })],
        ['unknown', owner(null, 2, { address_evidence: { address: { known: false, value: null } } })],
        ['changed', owner('192.0.2.90', 2)]
    ]) {
        const flow = await fixtureFor({ status: 2, apply: { applied: false, uncertain: true, current_address: oldAddress }, fresh });
        await flow.apply(); terminal(flow.fixture);
        assert.equal(flow.fixture.nodes.get('crm-ip-result').dataset.state, state);
        assert.equal(flow.fixture.nodes.get('resolve-crm-ip-history').style.display, state === 'changed' ? 'inline-flex' : 'none',
            'Historical fallback needs a fresh known address of the same existing ended service, independent of the previous edit attempt');
        assert.equal(flow.fixture.nodes.get('cleanup-crm-ip-result').style.display, 'none');
    }

    const unknownIdentityReceipt = await fixtureFor({ fresh: owner(null, 2, { service_exists: null, client_id: null }) });
    await unknownIdentityReceipt.apply();
    assert.equal(unknownIdentityReceipt.fixture.nodes.get('crm-ip-result').dataset.state, 'confirmed_pending',
        'A null client from an unavailable read is uncertainty, not evidence of reassignment');

    const changedHistory = await fixtureFor({ status: 2, apply: { applied: false, uncertain: true, current_address: oldAddress }, fresh: owner('192.0.2.90', 2) });
    await changedHistory.apply();
    await changedHistory.fixture.nodes.get('resolve-crm-ip-history').fire('click'); await flush();
    assert.equal(changedHistory.fixture.nodes.get('crm-ip-current').textContent, '192.0.2.90');
    await changedHistory.fixture.nodes.get('review-crm-ip-change').fire('click');
    assert.match(changedHistory.fixture.nodes.get('crm-ip-review').innerHTML, /192\.0\.2\.90/,
        'A fresh ended-service fallback binds its own review to the current IP, not the prior edit IP');
    assert(!changedHistory.fixture.nodes.get('crm-ip-review').innerHTML.includes(oldAddress));
    assert.equal(changedHistory.fixture.calls.filter(call => call.changeType === 'resolve' && call.action === 'apply').length, 1,
        'Opening the fallback and reviewing it does not repeat the original edit or apply history automatically');

    const ended = await fixtureFor({ status: 2, apply: { applied: false, uncertain: true, current_address: oldAddress }, fresh: owner(oldAddress, 2) });
    await ended.apply(); terminal(ended.fixture);
    assert.equal(ended.fixture.nodes.get('crm-ip-result').dataset.state, 'unchanged');
    assert.equal(ended.fixture.nodes.get('resolve-crm-ip-history').style.display, 'inline-flex');
    await ended.fixture.nodes.get('resolve-crm-ip-history').fire('click'); await flush();
    assert.equal(ended.fixture.nodes.get('crm-ip-form').style.display, 'block');
    assert.equal(ended.fixture.nodes.get('crm-ip-result').style.display, 'none');
    assert.equal(ended.fixture.nodes.get('crm-ip-edit-title').textContent, 'Resolver asignación histórica');
    const priorMutations = ended.fixture.calls.filter(call => call.changeType === 'resolve').length;
    await ended.fixture.nodes.get('review-crm-ip-change').fire('click');
    assert.equal(ended.fixture.calls.filter(call => call.changeType === 'resolve').length, priorMutations + 1, 'Historical fallback still requires a separate reviewed preview');
    assert.equal(ended.fixture.calls.at(-1).data.operation, 'history');
    ended.fixture.reply('resolve/crm_network_conflicts', envelope({ service_id: 42, client_id: 17, family: 'IPv4', operation: 'history',
        historical: true, preserves_crm_history: true, requires_sync: false, applied: true, uncertain: false,
        current_address: oldAddress, current_address_known: true, local_cleanup_required: true, awaiting_full_scan: true }));
    ended.setOwner(owner(oldAddress, 2, { historical_families: ['IPv4'] }));
    await ended.apply(); terminal(ended.fixture);
    assert.equal(ended.fixture.nodes.get('crm-ip-result').dataset.state, 'confirmed');
    assert.match(textTree(ended.fixture.nodes.get('crm-ip-result-message')), /CRM conserva.*historial|historial.*CRM.*conserv/);
    assert.equal(ended.fixture.nodes.get('crm-ip-current').textContent, oldAddress, 'Historical resolution never pretends that UISP IP was removed');
    assert.equal(ended.fixture.nodes.get('resolve-crm-ip-history').style.display, 'none', 'A confirmed historical family cannot be excluded twice');
    assert.equal(ended.fixture.nodes.get('cleanup-crm-ip-result').style.display, 'inline-flex');
    assert.equal(ended.fixture.calls.filter(call => call.changeType === 'cleanup').length, 0, 'Historical confirmation does not silently clear local reservations');
    ended.fixture.reply('cleanup/crm_network_conflicts', envelope({ candidates: [], blocked: [{ service_id: 42, reason: 'ownership_active' }] }));
    await ended.fixture.nodes.get('cleanup-crm-ip-result').fire('click');
    assert.equal(ended.fixture.calls.at(-1).changeType, 'cleanup'); assert.equal(ended.fixture.calls.at(-1).action, 'preview');
    assert.equal(ended.fixture.calls.filter(call => call.changeType === 'cleanup' && call.action === 'apply').length, 0);

    const reactivated = await fixtureFor({ operation: 'history', status: 2, apply: { applied: false, uncertain: true },
        fresh: owner(oldAddress, 1, { historical_families: ['IPv4'] }) });
    await reactivated.apply(); terminal(reactivated.fixture);
    assert.notEqual(reactivated.fixture.nodes.get('crm-ip-result').dataset.state, 'confirmed');
    assert.notEqual(reactivated.fixture.nodes.get('crm-ip-result').dataset.state, 'observed');
    assert.equal(reactivated.fixture.nodes.get('resolve-crm-ip-history').style.display, 'none');
    assert.equal(reactivated.fixture.nodes.get('cleanup-crm-ip-result').style.display, 'none', 'A malformed historical flag on a reactivated service cannot permit cleanup');

    const cancelled = await fixtureFor(); await cancelled.apply(false);
    assert.notEqual(cancelled.fixture.nodes.get('crm-ip-form').style.display, 'none', 'Cancelling confirmation leaves the reviewed form intact');
    assert.equal(cancelled.fixture.nodes.get('crm-ip-result').style.display, 'none');
    assert.equal(cancelled.fixture.nodes.get('apply-crm-ip-change').disabled, false);
    assert.equal(cancelled.fixture.calls.filter(call => call.changeType === 'resolve' && call.action === 'apply').length, 0);

    const navigating = await fixtureFor({ apply: { applied: false, uncertain: true, current_address: oldAddress }, fresh: owner(oldAddress) });
    await navigating.apply();
    const pendingRead = deferred();
    navigating.fixture.reply('list/crm_network_resolution', () => pendingRead.promise);
    const readsBefore = navigating.fixture.calls.length;
    const firstRetry = navigating.fixture.nodes.get('verify-crm-ip-result').fire('click'); await flush();
    assert.equal(navigating.fixture.nodes.get('crm-ip-result').dataset.state, 'checking');
    assert.equal(navigating.fixture.nodes.get('verify-crm-ip-result').disabled, true);
    await navigating.fixture.nodes.get('verify-crm-ip-result').fire('click');
    assert.equal(navigating.fixture.calls.slice(readsBefore).filter(call => call.target === 'crm_network_resolution').length, 1,
        'Double clicking retry cannot overlap bounded owner reads');
    await navigating.fixture.closeButtons[0].fire('click');
    assert.equal(navigating.fixture.nodes.get('crm-ip-edit-modal').style.display, 'flex', 'An in-flight read preserves the current result generation until it settles');
    await navigating.fixture.navigate('plans');
    assert.equal(navigating.fixture.nodes.get('view-plans').style.display, 'block', 'Read-only result verification leaves navigation usable');
    navigating.setOwner(owner(null)); pendingRead.resolve(envelope(navigating.verification())); await firstRetry;
    assert.equal(navigating.fixture.nodes.get('view-plans').style.display, 'block', 'A late verification must not jump back to conflicts');
    assert.equal(navigating.fixture.nodes.get('crm-ip-result').dataset.state, 'observed');
    await navigating.fixture.closeButtons[0].fire('click');
    assert.equal(navigating.fixture.nodes.get('crm-ip-edit-modal').style.display, 'none');
    const afterClose = navigating.fixture.calls.length;
    await navigating.fixture.nodes.get('verify-crm-ip-result').fire('click');
    await navigating.fixture.nodes.get('resolve-crm-ip-history').fire('click');
    assert.equal(navigating.fixture.calls.length, afterClose, 'Detached terminal actions cannot reopen or reverify an already closed generation');
    assert.equal(navigating.fixture.nodes.get('crm-ip-edit-modal').style.display, 'none');

    const staleSnapshot = snapshot(2), reassignedOwner = owner(oldAddress, 2, { client_id: 99 });
    const staleOpen = recoveryPanelFixture({ initialReplies: new Map([['list/crm_network_conflicts', envelope(staleSnapshot)],
        ['list/crm_network_resolution', envelope({ owners: [reassignedOwner], groups: [], checked_at: '2026-10-14T13:01:00Z' })]]) });
    await staleOpen.navigate('network_conflicts');
    await staleOpen.nodes.get('crm-network-conflicts-body').children[0].children[1].children[0].children
        .find(button => button.className.includes('conflict-history')).fire('click');
    assert.equal(staleOpen.nodes.get('review-crm-ip-change').disabled, true, 'An ended snapshot cannot authorize a historical edit for a reassigned client');
    assert.match(staleOpen.nodes.get('crm-ip-edit-status').textContent, /identidad.*cambió/);
    assert.equal(staleOpen.calls.filter(call => call.changeType === 'resolve').length, 0);
}

async function checkResolvedCrmConflictFeedback() {
    const envelope = data => ({ status: 'ok', error: false, data });
    const snapshot = { available: true, duplicate_groups: 1, checked_at: '2026-10-14T13:00:00Z', completed_scan_started_at: '2026-10-14T12:59:00Z',
        stale: false, families: ['IPv4'], conflicts: [{ family: 'IPv4', address: '192.0.2.77', service_ids: [42, 43], service_count: 2,
            services_truncated: false, owners: [42, 43].map(id => ({ service_id: id, client_id: id + 10, service_status_code: 1,
                service_status: 'Activo', service_url: '/crm/client/service/' + id, client_url: '/crm/client/' + (id + 10) })) }] };
    const proof = (extra = {}) => ({ checked_at: '2026-10-14T13:01:00Z', snapshot_checked_at: snapshot.checked_at,
        owners: snapshot.conflicts[0].owners.map(owner => ({ ...owner, service_exists: true,
            address_verified_at: '2026-10-14T13:00:55Z', address_evidence: { address: { known: true, value: owner.service_id === 42 ? null : '192.0.2.77' },
                address6: { known: true, value: null } } })),
        groups: [{ family: 'IPv4', address: '192.0.2.77', service_ids: [42, 43], service_count: 2, state: 'resolved' }], ...extra });
    const fixtureFor = async (report = snapshot, verification = proof()) => {
        const fixture = recoveryPanelFixture({ initialReplies: new Map([['list/crm_network_conflicts', envelope(report)],
            ['list/crm_network_resolution', envelope(verification)]]) });
        await fixture.navigate('network_conflicts'); return fixture;
    };
    const owners = (fixture, resolved = false) => fixture.nodes.get(resolved ? 'crm-network-resolved-body' : 'crm-network-conflicts-body').children
        .flatMap(row => row.children[1]?.children || []).filter(item => item.dataset.serviceId);
    const action = (fixture, id, className, resolved = false) => owners(fixture, resolved).find(owner => Number(owner.dataset.serviceId) === id)
        ?.children.find(button => button.className.split(' ').includes(className));
    const verify = fixture => action(fixture, 42, 'conflict-verify', owners(fixture).length === 0).fire('click');

    const sampled = await fixtureFor({ ...snapshot, duplicate_groups: 7, conflicts_truncated: true });
    await verify(sampled);
    assert.equal(owners(sampled).length, 0, 'The resolved group leaves the pending table immediately');
    assert.equal(owners(sampled, true).length, 2, 'Verified owner details and safe actions remain in the green resolved section');
    assert.match(sampled.nodes.get('crm-network-warning-title').textContent, /6 conflictos/);
    assert.equal(sampled.nodes.get('network-conflicts-count').textContent, '6', 'Subtract only the one completely proven group, preserving six unseen conflicts');
    assert.match(sampled.nodes.get('crm-network-conflicts-limit').textContent, /0 de 6.*fuera de esta muestra/);
    assert.equal(action(sampled, 42, 'conflict-cleanup', true).disabled, false, 'Resolution must not hide a still-needed local cleanup');

    const partial = await fixtureFor(snapshot, proof({ owners: proof().owners.slice(0, 1) })); await verify(partial);
    assert.equal(owners(partial).length, 2, 'A response claiming resolution without all owners cannot dismiss the group');
    assert.equal(partial.nodes.get('network-conflicts-count').textContent, '1');
    const truncated = await fixtureFor({ ...snapshot, conflicts: [{ ...snapshot.conflicts[0], services_truncated: true }] }); await verify(truncated);
    assert.equal(owners(truncated).length, 2, 'Truncated owner groups retain their pending count');
    const wrongIdentity = proof(); wrongIdentity.owners[0].client_id = 99;
    const reassigned = await fixtureFor(snapshot, wrongIdentity); await verify(reassigned);
    assert.equal(owners(reassigned).length, 2, 'A reassigned service cannot reuse a proof for another client');
    const changedScope = await fixtureFor(snapshot, proof({ groups: [{ ...proof().groups[0], service_ids: [42, 42] }] })); await verify(changedScope);
    assert.equal(owners(changedScope).length, 2, 'A duplicate proof ID cannot stand in for the unverified peer');
    const fakeHistory = proof(); fakeHistory.owners[0].historical_families = ['IPv4']; fakeHistory.owners[0].address_evidence.address.value = '192.0.2.77';
    const activeHistory = await fixtureFor(snapshot, fakeHistory); await verify(activeHistory);
    assert.equal(owners(activeHistory).length, 2, 'An active service cannot be dismissed by a malformed historical flag');

    const uncertain = await fixtureFor({ ...snapshot, unknown_address_count: 1, address_issues: [{ service_id: 44, client_id: 54,
        family: 'IPv4', service_url: '/crm/client/service/44', client_url: '/crm/client/54' }] }); await verify(uncertain);
    assert.equal(owners(uncertain).length, 0); assert.equal(owners(uncertain, true).length, 2);
    assert.match(uncertain.nodes.get('crm-network-warning-title').textContent, /IP sin comprobar.*1 dirección/);
    assert.equal(uncertain.nodes.get('network-conflicts-count').textContent, '1', 'Unresolved address evidence stays visible even after all known groups resolve');

    const raced = await fixtureFor(); await verify(raced);
    assert.equal(raced.nodes.get('crm-network-warning').style.display, 'none');
    assert.equal(raced.nodes.get('crm-network-resolved').style.display, 'block');
    assert.match(raced.nodes.get('crm-network-resolved-summary').textContent, /1 conflicto resuelto y verificado/);
    assert.equal(raced.nodes.get('network-conflicts-count').style.display, 'none');
    raced.reply('list/crm_network_conflicts', envelope({ ...snapshot, checked_at: '2026-10-14T13:02:00Z',
        completed_scan_started_at: '2026-10-14T13:00:30Z' })); await raced.tick(30000);
    assert.equal(owners(raced).length, 0); assert.equal(owners(raced, true).length, 2,
        'A scan started before the earliest owner observation cannot undo a later verified resolution when it eventually publishes');
    raced.reply('list/crm_network_conflicts', { status: 'failed', error: true, message: 'offline' }); await raced.tick(30000);
    assert.equal(owners(raced, true).length, 2, 'Polling failures retain the verified resolution without an invented new all-clear');
    raced.reply('list/crm_network_conflicts', envelope({ ...snapshot, available: false, checked_at: null, duplicate_groups: 0, conflicts: [] })); await raced.tick(30000);
    assert.equal(owners(raced, true).length, 2, 'An unavailable report cannot replace the prior snapshot with a false empty snapshot');
    raced.reply('list/crm_network_conflicts', envelope({ ...snapshot, checked_at: '2026-10-14T13:03:00Z', completed_scan_started_at: 'garbage' })); await raced.tick(30000);
    assert.equal(owners(raced, true).length, 2, 'Malformed completed-scan dates are rejected and do not silently retain trusted scope as a new snapshot');
    raced.reply('list/crm_network_conflicts', envelope({ ...snapshot, duplicate_groups: 2, conflicts: [...snapshot.conflicts, ...snapshot.conflicts] })); await raced.tick(30000);
    assert.equal(owners(raced, true).length, 2, 'Repeated snapshot keys cannot subtract the same verified conflict twice');
    raced.reply('list/crm_network_conflicts', envelope({ ...snapshot, checked_at: '2026-10-14T13:03:00Z',
        completed_scan_started_at: '2026-10-14T13:00:57Z' })); await raced.tick(30000);
    assert.equal(owners(raced).length, 2, 'A scan started after the earliest observation expires the proof even before the full verify request finished');
    assert.equal(owners(raced, true).length, 0); assert.equal(raced.nodes.get('crm-network-resolved').style.display, 'none');
    assert.match(raced.nodes.get('crm-network-warning-title').textContent, /1 conflicto/);

    const scope = await fixtureFor(); await verify(scope);
    scope.reply('list/crm_network_conflicts', envelope({ ...snapshot, checked_at: '2026-10-14T13:02:00Z', completed_scan_started_at: '2026-10-14T13:00:30Z',
        conflicts: [{ ...snapshot.conflicts[0], owners: [{ ...snapshot.conflicts[0].owners[0], client_id: 999 }, snapshot.conflicts[0].owners[1]] }] })); await scope.tick(30000);
    assert.equal(owners(scope).length, 2, 'Changed client scope invalidates a proof even if the automatic scan began earlier');

    const endedSnapshot = { ...snapshot, conflicts: [{ ...snapshot.conflicts[0], owners: [{ ...snapshot.conflicts[0].owners[0],
        service_status_code: 2, service_status: 'Terminado' }, snapshot.conflicts[0].owners[1]] }] };
    const endedProof = proof(); endedProof.owners[0].service_status_code = 2; endedProof.owners[0].service_status = 'Terminado';
    endedProof.owners[0].historical_families = ['IPv4']; endedProof.owners[0].address_evidence.address.value = '192.0.2.77';
    const reactivated = await fixtureFor(endedSnapshot, endedProof); await verify(reactivated);
    assert.equal(owners(reactivated, true).length, 2);
    reactivated.reply('list/crm_network_conflicts', envelope({ ...snapshot, checked_at: '2026-10-14T13:02:00Z',
        completed_scan_started_at: '2026-10-14T13:00:30Z' })); await reactivated.tick(30000);
    assert.equal(owners(reactivated).length, 2, 'Reactivation evidence revokes a historical resolution even from an earlier-started scan');
    assert.equal(owners(reactivated, true).length, 0);
    assert.equal(owners(reactivated)[0].children[0].children[1].children[1].textContent, 'Servicio: Activo');

    const dual = { ...snapshot, duplicate_groups: 2, families: ['IPv4', 'IPv6'], conflicts: [...snapshot.conflicts,
        { ...snapshot.conflicts[0], family: 'IPv6', address: '2001:db8:77::/64' }] };
    const absent = await fixtureFor(dual, proof({ groups: [] }));
    absent.reply('cleanup/crm_network_conflicts', envelope({ previewToken: null, expiresAt: null, candidates: [], blocked: [{ service_id: 42,
        reason: 'service_exists', checked_at: '2026-10-14T13:01:00Z', local_assignment_state: {
            address: { known: true, present: false }, address6: { known: true, present: true } } }] }));
    await action(absent, 42, 'conflict-cleanup').fire('click');
    assert.equal(absent.nodes.get('network-cleanup-blocked').children[0].className, 'cleanup-blocked-item is-cleaned');
    assert.match(absent.nodes.get('network-cleanup-blocked').children[0].innerHTML, /Sin reserva local.*No hay reservas locales/);
    assert.equal(absent.nodes.get('network-cleanup-status').textContent, 'No hay reservas locales seleccionadas que limpiar.');
    assert.equal(absent.nodes.get('apply-network-cleanup').disabled, true);
    assert.equal(action(absent, 42, 'conflict-cleanup').textContent, 'Sin reserva local');
    assert.equal(action(absent, 42, 'conflict-cleanup').disabled, true);
    const ipv6Owner = owners(absent).filter(owner => Number(owner.dataset.serviceId) === 42)[1];
    assert.equal(ipv6Owner.children.find(button => button.className.includes('conflict-cleanup')).disabled, false,
        'Absent IPv4 cannot label or disable the retained IPv6 reservation');
    assert.equal(absent.nodes.get('network-conflicts-count').textContent, '2', 'Local absence alone cannot clear a real CRM duplicate');
    const currentReservations = proof({ groups: [] }); currentReservations.owners[0].local_assignment_checked_at = '2026-10-14T13:02:00Z';
    currentReservations.owners[0].local_assignment_state = { address: { known: true, present: true }, address6: { known: true, present: true } };
    absent.reply('list/crm_network_resolution', envelope(currentReservations)); await verify(absent);
    assert.equal(action(absent, 42, 'conflict-cleanup').textContent, 'Revisar limpieza');
    assert.equal(action(absent, 42, 'conflict-cleanup').disabled, false, 'Fresh present-state evidence restores the review action');

    const unknown = await fixtureFor();
    unknown.reply('cleanup/crm_network_conflicts', envelope({ previewToken: null, expiresAt: null, candidates: [], blocked: [{ service_id: 42,
        reason: 'address_unknown', checked_at: '2026-10-14T13:01:00Z', local_assignment_state: {
            address: { known: false, present: null }, address6: { known: false, present: null } } }] }));
    await action(unknown, 42, 'conflict-cleanup').fire('click');
    assert.equal(unknown.nodes.get('network-cleanup-blocked').children[0].className, 'cleanup-blocked-item');
    assert.equal(action(unknown, 42, 'conflict-cleanup').disabled, false, 'Unknown local state must not claim an already-cleaned assignment');

    const applyLocal = async (verification) => {
        const fixture = await fixtureFor(snapshot, verification);
        const token = '9'.repeat(64), backup = 'network-cleanup-20261014T130050Z-' + '9'.repeat(32) + '.sqlite';
        fixture.reply('cleanup/crm_network_conflicts', payload => envelope(payload.action === 'preview' ? { previewToken: token,
            expiresAt: '2099-10-14T13:00:00Z', candidates: [{ service_id: 42, reason: 'stale_address', fields: ['address'],
                evidence: [{ field: 'address', saved_address: '192.0.2.70', current_address: '192.0.2.77', state: 'changed' }] }], blocked: [] }
            : { removed_service_ids: [42], duplicate_groups: 0, requires_reload: false, backup_name: backup, local_assignment_checked_at: '2026-10-14T13:00:50Z' }));
        await action(fixture, 42, 'conflict-cleanup').fire('click');
        const box = fixture.nodes.get('network-cleanup-candidates').children[0].children[0]; box.checked = true; await box.fire('change');
        const applying = fixture.nodes.get('apply-network-cleanup').fire('click'); await flush();
        const confirmation = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
        await confirmation.children[0].children[1].children[1].fire('click'); await applying;
        return fixture;
    };
    const cleanedResolved = await applyLocal(proof());
    assert.equal(owners(cleanedResolved).length, 0); assert.equal(owners(cleanedResolved, true).length, 2);
    assert.equal(cleanedResolved.nodes.get('crm-network-warning').style.display, 'none', 'Cleanup performs a bounded fresh peer verification and updates a genuinely resolved group immediately');
    assert.equal(action(cleanedResolved, 42, 'conflict-cleanup', true).textContent, 'Ya limpiada');
    assert.equal(action(cleanedResolved, 42, 'conflict-cleanup', true).disabled, true);
    assert.equal(cleanedResolved.calls.filter(call => call.target === 'crm_network_resolution').length, 1);
    assert.deepEqual(cleanedResolved.calls.find(call => call.target === 'crm_network_resolution').data, { serviceIds: [42, 43] });
    const stillDuplicate = proof(); stillDuplicate.owners[0].address_evidence.address.value = '192.0.2.77'; stillDuplicate.groups[0].state = 'duplicate';
    const cleanedDuplicate = await applyLocal(stillDuplicate);
    assert.equal(owners(cleanedDuplicate).length, 2); assert.equal(owners(cleanedDuplicate, true).length, 0);
    assert.match(cleanedDuplicate.nodes.get('crm-network-warning-title').textContent, /1 conflicto/,
        'Cleaning an old local reservation cannot hide a CRM duplicate confirmed by the fresh peer check');
    assert.equal(action(cleanedDuplicate, 42, 'conflict-cleanup').textContent, 'Ya limpiada');
    const cleanedOffline = await applyLocal({ status: 'failed', error: true, message: 'offline' });
    assert.equal(owners(cleanedOffline).length, 2);
    assert.match(cleanedOffline.nodes.get('network-cleanup-status').textContent, /La limpieza local terminó.*no se pudo actualizar/);
    assert.equal(action(cleanedOffline, 42, 'conflict-cleanup').textContent, 'Ya limpiada', 'A failed post-cleanup read cannot turn a completed cleanup into an error or lose its receipt');
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
    assert.deepEqual(identity.children[1].children.slice(1).map(node => node.textContent), ['Servicio: Terminado (dato guardado)', 'Cliente archivado (dato guardado)']);
    assert.equal(identity.children[2].textContent, 'Base del plugin', 'Saved service status must identify its source');
    assert.equal(owners[1].children[0].children[0].tagName, 'SPAN', 'Cross-origin client links must never be clickable');
    assert.equal(owners[1].children[0].children[1].children[0].tagName, 'SPAN', 'Executable service URLs must never be clickable');
    assert.equal(owners[1].children[0].children[1].children[1].textContent, 'Servicio: ' + attack + ' (dato guardado)');
    const missing = body.children[1].children[1].children[0].children[0];
    assert.equal(missing.children[0].textContent, 'Cliente sin identificar');
    assert.equal(missing.children[1].children[1].textContent, 'Servicio: Sin comprobar');
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
    assert.equal(body.children[0].children[1].children[0].children[0].children[1].children[1].textContent, 'Servicio: Activo');
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
    assert.equal(ownerItem(fixture, 5347).children[0].children[1].children[1].textContent, 'Servicio: Activo', 'Verifying another owner must preserve the first live service status');
    assert.deepEqual(sourceTexts(ownerItem(fixture, 5347)), ['Servicio verificado en UISP', 'Cliente verificado en UISP']);
    assert.equal(ownerItem(fixture, 11506).children[0].children[1].children[1].textContent, 'Servicio: Suspendido');
    await fixture.navigate('system'); await fixture.navigate('network_conflicts');
    assert.equal(ownerItem(fixture, 5347).children[0].children[1].children[1].textContent, 'Servicio: Activo', 'Normal recovery list responses must preserve earlier CRM verification memory');
    const failedService = plain(details);
    Object.assign(failedService.conflicts[0].owners[0], { refresh_error: 'unavailable', client_source: 'cache' });
    await verifyOwner(fixture, 5347, failedService);
    assert.deepEqual(sourceTexts(ownerItem(fixture, 5347)), ['Base del plugin', 'Cliente verificado en UISP'], 'A failed service check must replace its own result while keeping the independent client verification');
    assert.equal(ownerItem(fixture, 5347).children[0].children[1].children[1].textContent, 'Servicio: Terminado (dato guardado)');
    assert.match(ownerItem(fixture, 5347).children[0].lastElementChild.textContent, /UISP no devolvió este servicio/);
    assert.equal(ownerItem(fixture, 11506).children[0].children[1].children[1].textContent, 'Servicio: Suspendido', 'Failure of a different selected service must preserve unrelated verified facts');
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
    assert.equal((markup.match(/(?:src|href)="(?:js|css)\/[^"?]+\?v=2\.0\.54"/g) || []).length, 7, 'Every panel asset must use the new revision to invalidate UISP browser caches');
    assert(!markup.includes('v=2.0.45'));
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
    assert.equal(owner(42).children[0].children[1].children[1].textContent, 'Servicio: Activo (dato guardado)');
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
    assert(fixture.nodes.get('network-cleanup-blocked').children[0].innerHTML.includes('coincide con la reserva guardada'), 'Current matching IP reservations must remain protected');
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
    const readsBeforeApply = fixture.calls.filter(call => call.changeType === 'list' && call.target !== 'jobs_status').length;
    const applying = fixture.nodes.get('apply-network-cleanup').fire('click'); await flush();
    confirmation = fixture.document.body.children.find(node => node.className === 'confirm-overlay');
    await confirmation.children[0].children[1].children[1].fire('click'); await applying;
    assert.deepEqual(plain(fixture.calls.filter(call => call.target !== 'jobs_status').at(-1)), { changeType: 'cleanup', target: 'network_conflicts', action: 'apply', data: { previewToken: token, serviceIds: [42] } });
    assert.equal(fixture.calls.filter(call => call.changeType === 'list' && call.target !== 'jobs_status').length, readsBeforeApply, 'Cleanup must not automatically issue setup/list/synchronization requests; the read-only queue summary remains independent');
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

async function checkNetworkClientArchivePanel() {
    const cached = (service, client, extra = {}) => ({ service_id: service, client_id: client, client_name: 'Cliente de ejemplo ' + (client ?? '?'), service_status: 'Activo',
        client_archived: false, client_source: 'cache', source: 'cache', refresh_error: null, client_refresh_error: null, client_verified_at: null, ...extra });
    const details = { duplicate_groups: 2, conflicts: [
        { family: 'IPv4', address: '192.0.2.77', service_ids: [42, 43, 44, 45], service_count: 4, owners: [
            cached(42, 17, { refresh_error: 'unavailable' }), cached(43, 17, { source: 'crm', service_status: 'Suspendido' }), cached(44, 18),
            cached(45, null, { source: 'missing', client_source: 'missing', client_archived: null, service_status: 'Sin verificar' })
        ] },
        { family: 'IPv6', address: '2001:db8::/64', service_ids: [52, 53], service_count: 2, owners: [cached(52, 17), cached(53, 19, { client_archived: null })] }
    ] };
    function item(fixture, id) {
        for (const row of fixture.nodes.get('network-conflicts-body').children) {
            const owner = row.children[1].children.find(node => node.children[0]?.children[1]?.children[0]?.textContent === 'Servicio #' + id);
            if (owner) return owner;
        }
        return null;
    }
    const archive = owner => owner.children[0].children[1].children.find(node => node.className.includes('client-lifecycle'));
    const errors = owner => owner.children[0].children.filter(node => node.className.includes('conflict-refresh-error'));
    function reply(fixture, data) { fixture.reply('list/network_conflicts', { status: 'ok', error: false, data, recovery: { readOnly: true } }); }
    async function verifyClient(fixture, id, data) { reply(fixture, data); await item(fixture, id).children[3].fire('click'); await flush(); }
    const fixture = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details }); await fixture.navigate('network_conflicts');
    assert.equal(fixture.diagnostics().length, 0, 'Archive badges must use saved metadata without any automatic CRM query');
    assert.equal(archive(item(fixture, 42)).textContent, 'Cliente no archivado (dato guardado)');
    assert.equal(archive(item(fixture, 45)).textContent, 'Archivo del cliente sin comprobar');
    assert.equal(archive(item(fixture, 53)).textContent, 'Archivo del cliente sin comprobar', 'A known client ID must not imply a known archive flag');
    assert.equal(item(fixture, 45).children.length, 3, 'Unknown client identity must expose no Verify client button');
    assert.equal(item(fixture, 42).children[3].textContent, 'Verificar cliente', 'A deleted service with a known cached client ID must still allow independent client verification');
    const refreshed = plain(details);
    for (const group of refreshed.conflicts) for (const owner of group.owners) if (owner.client_id === 17)
        Object.assign(owner, { client_name: 'Cliente archivado de ejemplo', client_archived: true, client_source: 'crm', client_refresh_error: null, client_verified_at: '2026-10-14T13:00:00Z' });
    const pending = deferred(); fixture.reply('list/network_conflicts', () => pending.promise);
    const verification = item(fixture, 42).children[3].fire('click'); await flush();
    assert.deepEqual(plain(fixture.diagnostics()[0].data), { refreshClients: true, serviceIds: [42] }, 'Verify client must request only the client mode and exactly one selected service ID');
    assert.equal(item(fixture, 42).children[3].textContent, 'Verificando cliente...');
    assert.equal(item(fixture, 43).children[3].textContent, 'Verificando cliente...', 'Shared-client rows must show the same in-flight state');
    assert.equal(item(fixture, 44).children[3].disabled, true);
    assert.equal(fixture.nodes.get('loader').style.display, 'none');
    await item(fixture, 43).children[1].fire('click'); await item(fixture, 44).children[3].fire('click');
    assert.equal(fixture.diagnostics().length, 1, 'Client checks must serialize with service verification and repeated client clicks');
    await fixture.navigate('system'); await fixture.navigate('network_conflicts');
    pending.resolve({ status: 'ok', error: false, data: refreshed, recovery: { readOnly: true } }); await verification;
    for (const id of [42, 43, 52]) {
        assert.equal(archive(item(fixture, id)).textContent, 'Cliente archivado');
        assert(archive(item(fixture, id)).className.includes('badge-warning'), 'A confirmed archived client must be visually amber');
        assert(item(fixture, id).children[0].children[0].className.includes('client-archived-name'), 'Confirmed archive state must visibly strike the client name');
        assert(item(fixture, id).children[0].children.some(node => String(node.textContent).startsWith('Cliente verificado en UISP: ')), 'Successful client verification must show its timestamp');
    }
    assert.equal(item(fixture, 42).children[0].children[1].children[1].textContent, 'Servicio: Activo (dato guardado)', 'Archiving a client must never replace the independently cached active service status');
    assert.match(errors(item(fixture, 42))[0].textContent, /UISP no devolvió este servicio/, 'Client-only success must preserve the existing service lookup failure');
    assert.equal(item(fixture, 43).children[0].children[1].children[1].textContent, 'Servicio: Suspendido');
    assert.equal(archive(item(fixture, 44)).textContent, 'Cliente no archivado (dato guardado)');
    reply(fixture, details); await item(fixture, 42).children[1].fire('click'); await flush();
    assert.equal(archive(item(fixture, 42)).textContent, 'Cliente archivado', 'A missing-service verification must not erase an earlier independent client result');
    assert.equal(archive(item(fixture, 52)).textContent, 'Cliente archivado', 'Client results must remain shared across conflict groups');
    await fixture.navigate('system'); await fixture.navigate('network_conflicts');
    assert.equal(archive(item(fixture, 42)).textContent, 'Cliente archivado');
    const checkedNotArchived = plain(details);
    for (const group of checkedNotArchived.conflicts) for (const owner of group.owners) if (owner.client_id === 19)
        Object.assign(owner, { client_archived: false, client_source: 'crm', client_verified_at: '2026-10-14T13:01:00Z' });
    await verifyClient(fixture, 53, checkedNotArchived);
    assert.equal(archive(item(fixture, 53)).textContent, 'Cliente no archivado');
    assert(!archive(item(fixture, 53)).className.includes('badge-warning'));
    assert(!item(fixture, 53).children[0].children[0].className.includes('client-archived-name'));
    assert.equal(archive(item(fixture, 42)).textContent, 'Cliente archivado', 'Verifying another client must preserve previously checked archive flags');
    const failed = plain(details);
    for (const group of failed.conflicts) for (const owner of group.owners) if (owner.client_id === 17)
        Object.assign(owner, { client_refresh_error: 'unavailable', client_source: 'cache', client_archived: false, client_name: 'Nombre guardado', client_verified_at: null });
    failed.conflicts[0].owners[1].source = 'cache'; failed.conflicts[0].owners[1].service_status = 'Activo';
    await verifyClient(fixture, 42, failed);
    for (const id of [42, 43, 52]) {
        assert.equal(archive(item(fixture, id)).textContent, 'Cliente no archivado (dato guardado)', 'A selected failed client check must replace the prior client result for every shared-client row');
        assert(!item(fixture, id).children[0].children[0].className.includes('client-archived-name'));
        assert(errors(item(fixture, id)).some(node => node.className.includes('conflict-client-error')));
        assert(!item(fixture, id).children[0].children.some(node => String(node.textContent).startsWith('Cliente verificado en UISP: ')), 'A failed client check must not claim a successful verification timestamp');
    }
    assert.equal(item(fixture, 43).children[0].children[1].children[1].textContent, 'Servicio: Suspendido', 'Client-only failure must retain prior live service facts');
    assert.match(errors(item(fixture, 42))[0].textContent, /UISP no devolvió este servicio/);
    const otherService = plain(details);
    Object.assign(otherService.conflicts[0].owners[2], { source: 'crm', client_source: 'crm', client_archived: false, client_verified_at: '2026-10-14T13:02:00Z' });
    reply(fixture, otherService); await item(fixture, 44).children[1].fire('click'); await flush();
    await fixture.navigate('system'); await fixture.navigate('network_conflicts');
    assert(errors(item(fixture, 42)).some(node => node.className.includes('conflict-client-error')), 'Client errors must survive unrelated service checks and navigation');
    assert.equal(fixture.writes().length, 0, 'Archive checks must never trigger writes or alter cleanup eligibility');
    const clientTimeout = plain(details);
    Object.assign(clientTimeout.conflicts[1].owners[1], { client_refresh_error: 'deadline', client_archived: null, client_verified_at: null });
    await verifyClient(fixture, 53, clientTimeout);
    assert.equal(archive(item(fixture, 53)).textContent, 'Archivo del cliente sin comprobar');
    assert.match(errors(item(fixture, 53))[0].textContent, /consulta del cliente.*agotó el tiempo/, 'Client deadlines need their own explanation and must not infer archive state');
    for (const legacy of [false, true]) {
        const baseline = { duplicate_groups: 1, conflicts: [{ family: 'IPv4', address: '192.0.2.90', service_ids: [71, 72], service_count: 2, owners: [cached(71, 27), cached(72, 28)] }] };
        const mixed = recoveryPanelFixture({ bootstrap: { readOnly: true, details: baseline }, details: baseline }); await mixed.navigate('network_conflicts');
        const partial = plain(baseline);
        Object.assign(partial.conflicts[0].owners[0], { source: 'crm', client_source: 'cache', refresh_error: legacy ? 'unavailable' : null, client_refresh_error: legacy ? undefined : 'unavailable' });
        reply(mixed, partial); await item(mixed, 71).children[1].fire('click'); await flush();
        assert.equal(errors(item(mixed, 71)).length, 1, 'Successful service plus failed client must show exactly one client-specific error');
        assert.match(errors(item(mixed, 71))[0].textContent, /No se pudo verificar el cliente/);
        const clientSuccess = plain(baseline);
        Object.assign(clientSuccess.conflicts[0].owners[0], { client_source: 'crm', client_archived: true, client_name: 'Cliente restaurable', client_verified_at: '2026-10-14T13:03:00Z' });
        await verifyClient(mixed, 71, clientSuccess);
        assert.equal(item(mixed, 71).children[0].children[1].children[1].textContent, 'Servicio: Activo', 'Client-only success must keep the earlier successful service verification');
        assert.equal(archive(item(mixed, 71)).textContent, 'Cliente archivado');
        assert.equal(errors(item(mixed, 71)).length, 0, 'Successful client-only verification must clear the client error without falsely claiming that the verified service was unavailable');
    }
}

async function checkNavigationContinuity() {
    const scope = 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php';
    const key = 'ros-panel-navigation-v1:' + scope;
    const views = ['devices', 'plans', 'config', 'jobs', 'system', 'network_conflicts'];
    const details = { duplicate_groups: 1, conflicts: [{ family: 'IPv4', address: '192.0.2.77', service_count: 12,
        service_ids: Array.from({ length: 12 }, (_, index) => 1000 + index), owners: Array.from({ length: 12 }, (_, index) => ({
            service_id: 1000 + index, client_id: 6000 + index, client_name: 'Nombre privado ' + index, service_status: 'Activo', source: 'cache', client_source: 'cache', client_archived: false
        })) }] };
    const response = data => ({ status: 'ok', error: false, data });
    const stored = (view, positions) => JSON.stringify({ version: 1, scope, view, positions });
    const targets = { devices: 'devices', plans: 'plans', config: 'messages', jobs: 'jobs', system: 'sys' };
    const payloads = {
        devices: [{ id: 1, name: 'Router de ejemplo', ip: '192.0.2.1', status: null }], plans: [{ id: 1, name: 'Plan', ratio: 1, uploadSpeed: 10, downloadSpeed: 20 }],
        messages: [[], { config_disable_contention: { name: 'disable_contention', label: 'Desactivar contención', type: 1 } }], jobs: {}, sys: { app_version: '2.4.24' }
    };
    for (const entry of [
        { navigationType: 'navigate' }, { navigationType: 'back_forward' },
        { navigationType: null }, { navigationType: null, legacyNavigationType: 0 }
    ]) {
        const storage = new Map([[key, stored('jobs', { jobs: { scrollTop: 420 }, devices: { scrollTop: 240 } })]]);
        const fresh = recoveryPanelFixture({ storage, ...entry }); await flush();
        assert.equal(fresh.nodes.get('view-devices').style.display, 'block', 'A new visit opens Devices regardless of a previously saved Jobs section');
        assert.equal(fresh.nodes.get('view-jobs').style.display, 'none');
        assert.equal(fresh.surface().scrollTop, 0, 'A fresh visit starts at the top of Devices');
        assert(!fresh.calls.some(call => call.target === 'jobs'), 'Fresh entry must not fetch the remembered queue');
        assert.equal(JSON.parse(storage.get(key)).view, 'devices');
    }
    for (const entry of [{ navigationType: 'reload' }, { navigationType: null, legacyNavigationType: 1 }]) {
        const storage = new Map([[key, stored('jobs', { jobs: { scrollTop: 420 } })]]);
        const reloaded = recoveryPanelFixture({ storage, ...entry }); await flush();
        assert.equal(reloaded.nodes.get('view-jobs').style.display, 'block', 'A real reload resumes the selected section');
        assert.equal(reloaded.surface().scrollTop, 420, 'Reload keeps the selected section position');
        assert.equal(reloaded.writes().length, 0, 'Restoring navigation cannot execute queued work');
    }
    for (const view of views) {
        const storage = new Map();
        const first = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details, storage });
        await first.navigate(view); first.surface().scrollTop = 400; await first.event('pagehide');
        const saved = JSON.parse(storage.get(key));
        assert.equal(saved.view, view); assert.equal(saved.positions[view].scrollTop, 400);
        const pending = deferred();
        const initialReplies = targets[view] ? new Map([['list/' + targets[view], () => pending.promise]]) : new Map();
        const restored = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details, storage, initialReplies });
        assert.equal(restored.surface().scrollTop, 0, 'Position must wait for the view data and layout: ' + view);
        await flush();
        if (targets[view]) {
            assert.equal(restored.surface().scrollTop, 0, 'An initial empty render must not restore too early: ' + view);
            assert.equal(JSON.parse(storage.get(key)).positions[view].scrollTop, 400, 'Initial loading must not overwrite the saved position');
            pending.resolve(response(payloads[targets[view]])); await flush();
        }
        assert.equal(restored.nodes.get('view-' + view).style.display, 'block');
        assert.equal(restored.surface().scrollTop, 400, 'Every allowed section must restore its scroll after rendering: ' + view);
        assert.equal(restored.writes().length, 0);
        assert.equal(restored.diagnostics().filter(call => call.data.refresh || call.data.refreshClients).length, 0, 'Restoring a section must not verify services or clients automatically');
    }
    const perView = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details, storage: new Map() });
    await perView.navigate('plans'); perView.surface().scrollTop = 123;
    await perView.navigate('config'); perView.surface().scrollTop = 234;
    await perView.navigate('plans'); assert.equal(perView.surface().scrollTop, 123);
    await perView.navigate('config'); assert.equal(perView.surface().scrollTop, 234, 'Each section keeps its own position between navigation clicks');
    const storage = new Map();
    const first = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details, storage }); await first.navigate('network_conflicts');
    const owners = fixture => fixture.nodes.get('network-conflicts-body').children[0].children[1].children.filter(node => node.dataset.serviceId);
    const owner = (fixture, id) => owners(fixture).find(node => Number(node.dataset.serviceId) === id);
    first.surface().scrollTop = 450; owner(first, 1003).children[3].focus({ preventScroll: true });
    const verified = plain(details); Object.assign(verified.conflicts[0].owners[3], { client_source: 'crm', client_archived: true, client_verified_at: '2026-10-14T13:00:00Z' });
    first.api.setRecovery({ readOnly: true, details: verified });
    await first.event('beforeunload');
    const serialized = storage.get(key), state = JSON.parse(serialized);
    assert.deepEqual(state.positions.network_conflicts.anchor, { serviceId: 1003, offset: 0, occurrence: 0 });
    assert.deepEqual(state.positions.network_conflicts.focus, { serviceId: 1003, action: 'client' });
    for (const forbidden of ['Nombre privado', '192.0.2.77', 'client_name', 'client_archived', 'client_verified_at', 'previewToken', 'password', 'cookie', '6003'])
        assert(!serialized.includes(forbidden), 'Session navigation must contain no owner/client/proof/business data: ' + forbidden);
    const reordered = plain(details);
    const [moved] = reordered.conflicts[0].owners.splice(3, 1); reordered.conflicts[0].owners.splice(7, 0, moved);
    const restored = recoveryPanelFixture({ bootstrap: { readOnly: true, details: reordered }, details: reordered, storage,
        url: scope + '?page=panel&cache=changed' }); await flush();
    assert.equal(restored.surface().scrollTop, 1010, 'A reordered conflict must restore the same service row and relative offset');
    assert.equal(restored.document.activeElement, owner(restored, 1003).children[3], 'Restore a safe verification focus target without replaying its action');
    assert(!owner(restored, 1003).children[0].children[0].className.includes('client-archived-name'), 'Navigation storage must never restore prior CRM archive proof');
    assert.equal(restored.diagnostics().length, 0);
    const rerendered = plain(reordered);
    const [movedAgain] = rerendered.conflicts[0].owners.splice(7, 1); rerendered.conflicts[0].owners.splice(1, 0, movedAgain);
    restored.reply('list/network_conflicts', { status: 'ok', error: false, data: rerendered, recovery: { readOnly: true } });
    await owner(restored, 1003).children[3].fire('click'); await flush();
    assert.equal(restored.surface().scrollTop, 170, 'Client verification table refreshes must keep the visible service anchor through reordering');
    assert.equal(restored.document.activeElement, owner(restored, 1003).children[3]);
    await restored.nodes.get('btn-recheck-conflicts').fire('click');
    assert.equal(restored.reloads(), 1);
    assert.equal(JSON.parse(storage.get(key)).positions.network_conflicts.scrollTop, 170, 'Recheck must synchronously flush the latest position before reloading');
    restored.surface().scrollTop = 200; await restored.nodes.get('reload-after-cleanup').fire('click');
    assert.equal(JSON.parse(storage.get(key)).positions.network_conflicts.scrollTop, 200, 'Cleanup reload must use the same synchronous continuity flush');
    const removed = plain(rerendered); removed.conflicts[0].owners = removed.conflicts[0].owners.filter(entry => entry.service_id !== 1003);
    const absent = recoveryPanelFixture({ bootstrap: { readOnly: true, details: removed }, details: removed, storage });
    absent.surface().scrollHeight = 300; absent.surface().clientHeight = 200; await flush();
    assert.equal(absent.surface().scrollTop, 100, 'If the service anchor vanished, clamp the saved numeric position to the new content');
    assert.equal(absent.document.activeElement, absent.document.body, 'A removed anchor must never focus a different client');
    const mobileStorage = new Map();
    const mobile = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details, storage: mobileStorage, mobile: true }); await mobile.navigate('network_conflicts');
    mobile.surface().scrollTop = 450; await mobile.event('pagehide');
    const mobileReload = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details, storage: mobileStorage, mobile: true }); await flush();
    assert.equal(mobileReload.document.scrollingElement.scrollTop, 450, 'Responsive layouts must restore the document scroller');
    assert.equal(mobileReload.nodes.get('content-area').scrollTop, 0);
    const healthy = recoveryPanelFixture({ storage, details }); await flush();
    assert.equal(healthy.nodes.get('view-network_conflicts').style.display, 'block', 'Healthy panels must restore the remembered conflicts view for automatic CRM monitoring');
    assert.equal(healthy.diagnostics().length, 0);
    for (const invalid of ['{', 'x'.repeat(4097), stored('unsafe_view', {}), JSON.stringify({ version: 2, scope, view: 'system', positions: {} }),
        JSON.stringify({ version: 1, scope: 'wrong-scope', view: 'system', positions: {} }), stored('system', { system: { scrollTop: -1 } }),
        stored('system', { system: { scrollTop: 1, focus: { serviceId: 42, action: 'execute' } } })]) {
        const invalidStorage = new Map([[key, invalid]]);
        const fixture = recoveryPanelFixture({ storage: invalidStorage, details }); await flush();
        assert.equal(fixture.nodes.get('view-devices').style.display, 'block');
        assert.deepEqual(fixture.calls.map(call => call.target), ['crm_network_conflicts', 'jobs_status', 'devices'], 'Invalid navigation state allows only the local monitor and queue status and default section reads');
    }
    const blocked = recoveryPanelFixture({ storageBlocked: true, details }); await flush(); await blocked.navigate('system');
    assert.equal(blocked.nodes.get('view-system').style.display, 'block');
    const otherScope = recoveryPanelFixture({ storage: new Map([[key, stored('system', { system: { scrollTop: 123 } })]]), details,
        url: 'https://fixture.invalid/crm/_plugins/other-plugin/public.php?page=panel' }); await flush();
    assert.equal(otherScope.nodes.get('view-devices').style.display, 'block', 'Per-plugin session state must not leak into another endpoint');
    const pending = deferred();
    const raceStorage = new Map([[key, stored('plans', { plans: { scrollTop: 280 }, system: { scrollTop: 90 } })]]);
    const race = recoveryPanelFixture({ storage: raceStorage, details, initialReplies: new Map([['list/plans', () => pending.promise]]) }); await flush();
    await race.navigate('system'); assert.equal(race.surface().scrollTop, 90);
    pending.resolve(response(payloads.plans)); await flush();
    assert.equal(race.surface().scrollTop, 90, 'Late results from a previous section must not restore its scroll over the new destination');
    assert.equal(JSON.parse(raceStorage.get(key)).view, 'system');
    const frameRace = recoveryPanelFixture({ storage: new Map([[key, stored('devices', { devices: { scrollTop: 500 }, system: { scrollTop: 80 } })]]), details, raf: true });
    await flush(); await frameRace.navigate('system'); await frameRace.runFrames();
    assert.equal(frameRace.surface().scrollTop, 80, 'The animation-frame restoration must check navigation generation again');
    for (const target of ['config', 'plans', 'sys', 'backups']) {
        const view = ['sys', 'backups'].includes(target) ? 'system' : target;
        const fixture = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details, exposeRenderers: true }); await fixture.navigate(view);
        fixture.surface().scrollTop = 200;
        const waiting = deferred(); fixture.reply('list/' + target, () => waiting.promise);
        const refresh = view === 'config' ? fixture.nodes.get('tab-general').fire('click') : fixture.refresh(view);
        await flush(); fixture.surface().scrollTop = 450;
        waiting.resolve(response(target === 'config' ? { disable_contention: false } : target === 'backups' ? [] : payloads[target]));
        await refresh; await flush();
        assert.equal(fixture.surface().scrollTop, 450, 'A same-section async refresh must respect newer scrolling while ' + target + ' is pending');
        assert.equal(fixture.writes().length, 0);
    }
}

function checkSystemBackupGrouping() {
    const group = markup.match(/<section\b[^>]*class="[^"]*\bsystem-backups\b[^"]*"[\s\S]*?<\/details>\s*<\/section>/)?.[0];
    assert(group, 'System must have one grouped backup section');
    for (const id of ['full-backups-heading', 'refresh-full-backups', 'create-full-backup', 'upload-full-backup', 'full-backup-list',
        'legacy-backups', 'btn-backup', 'btn-upload', 'backups-grid']) {
        assert(group.includes('id="' + id + '"'), 'Existing backup control must remain available in its grouped section: ' + id);
        assert.equal([...markup.matchAll(new RegExp('\\bid="' + id + '"', 'g'))].length, 1, 'Backup controls must not be duplicated: ' + id);
    }
    assert(/<details\b[^>]*id="legacy-backups"[^>]*>\s*<summary/.test(group), 'Previous SQLite copies remain keyboard accessible through a native details control');
    assert(!/<details\b[^>]*id="legacy-backups"[^>]*\bopen(?:\s|=|>)/.test(group), 'Long legacy history starts compact');
    assert(group.includes('Respaldo completo (ZIP)') && group.includes('No incluyen la cola ni las recuperaciones.'), 'ZIP and SQLite scopes must be visible and distinct');
    const utilities = markup.match(/<div class="system-utilities">([\s\S]*?)\n\s*<\/div>\n\s*<\/div>\n\s*<\/section>/)?.[1];
    assert(utilities, 'System utilities remain available');
    assert.equal([...utilities.matchAll(/class="utility-card"/g)].length, 3, 'Utilities contain only rebuild, cache and log tools');
    for (const id of ['btn-rebuild', 'btn-cache', 'btn-log-clear']) assert(utilities.includes('id="' + id + '"'));
    for (const id of ['btn-backup', 'btn-upload', 'backups-grid']) assert(!utilities.includes('id="' + id + '"'), 'Backup actions must not repeat in Utilities');
}

async function checkRequesterLifecycleReview() {
    const token = 'e'.repeat(64), envelope = data => ({ status: 'ok', error: false, data });
    const job = { Q989: { serviceId: 7279, deviceId: 10, state: 'pending', username: 'former@48781',
        error: 'network_address_conflict: requested_service=7279 blocking_service=9807 source=reserved family=IPv4' } };
    const candidate = extra => ({ serviceId: 7279, clientId: 48781, status: 2, missing: false, state: 'retired', reason: 'service_ended',
        eligible: true, previewToken: token, expiresAt: '2026-10-16T23:00:00Z', ...extra });
    const outcome = extra => ({ serviceId: 7279, clientId: 48781, state: 'retired', reason: 'lifecycle_retired', retired: true, queued: 0, jobKeys: [], released: true, ...extra });
    async function open(preview, options = {}) {
        const fixture = recoveryPanelFixture({ ...options, initialReplies: new Map([['list/jobs', envelope(job)], ['list/service_lifecycle', envelope(preview)]]) });
        await fixture.navigate('jobs');
        const button = fixture.tables.get('#jobs-table tbody').children[0].querySelector('.job-description').children
            .find(child => child.textContent === 'Revisar servicio solicitante #7279');
        assert(button, 'The requesting service needs an action separate from protected reservation #9807');
        await button.fire('click'); await flush(); return fixture;
    }
    for (const [code, phrase] of Object.entries({ lifecycle_unavailable: 'próximo reintento automático', lifecycle_review_pending: 'otra pasada automática',
        lifecycle_identity_changed: 'identidad del cliente cambió', lifecycle_reactivated: 'volvió a estar vigente', lifecycle_router_identity_unknown: 'router o la cuenta anterior',
        lifecycle_resources_unconfirmed: 'confirmar en el router', lifecycle_changed: 'cambió durante la revisión' })) {
        const fixture = recoveryPanelFixture({ initialReplies: new Map([['list/jobs', envelope({ Q989: { ...job.Q989, error: code } })],
            ['list/service_lifecycle', envelope(candidate({ state: 'unknown', reason: 'lifecycle_unavailable', eligible: false, previewToken: null, expiresAt: null }))]]) });
        await fixture.navigate('jobs');
        const row = fixture.tables.get('#jobs-table tbody').children[0];
        assert(row.innerHTML.includes(phrase) && !row.innerHTML.includes(code), 'Lifecycle job errors must explain the service-specific action instead of showing raw codes: ' + code);
        const review = row.querySelector('.job-description').children.find(child => child.textContent === 'Revisar servicio solicitante #7279');
        assert(review, 'A lifecycle error needs an explicit requester review action: ' + code);
        await review.fire('click'); await flush();
        assert(fixture.nodes.get('service-lifecycle-status').textContent.includes('No se pudo confirmar el estado actual'));
        assert.equal(fixture.nodes.get('apply-service-lifecycle').disabled, true, 'An unknown status must not enable retirement');
        assert.equal(fixture.writes().length, 0);
    }
    for (const [extra, reason] of [[{}, 'servicio terminó'], [{ reason: 'client_archived', status: 1 }, 'cliente está archivado'],
        [{ reason: 'service_not_found', missing: true, clientId: null, status: null }, 'servicio no existe']]) {
        const fixture = await open(candidate(extra));
        assert(fixture.nodes.get('service-lifecycle-status').textContent.includes(reason));
        assert.equal(fixture.nodes.get('apply-service-lifecycle').disabled, false);
        const identity = fixture.nodes.get('service-lifecycle-details').children[0].children;
        assert.equal(identity[0].href, 'https://fixture.invalid/crm/client/service/7279', 'Review must target the requester, never reservation holder #9807');
        if (!extra.missing) assert.equal(identity[1].href, 'https://fixture.invalid/crm/client/48781');
        assert.equal(fixture.writes().length, 0, 'Review cannot retire work or modify the router');
        fixture.reply('update/service_lifecycle', payload => { assert.deepEqual(plain(payload.data), { previewToken: token }); return envelope(outcome()); });
        fixture.reply('list/jobs', envelope({}));
        await fixture.nodes.get('apply-service-lifecycle').fire('click'); await flush();
        assert(fixture.nodes.get('service-lifecycle-status').textContent.includes('liberaron sus reservas locales'));
        assert.equal(fixture.nodes.get('apply-service-lifecycle').disabled, true);
        assert.equal(fixture.writes().length, 1);
        assert(!fixture.calls.some(call => ['job_reservation', 'network_conflicts'].includes(call.target) && call.action === 'apply'), 'Retiring old work must never clean the active holder');
        assert(!fixture.calls.some(call => call.target === 'jobs' && call.action === 'run'), 'The review must not execute router jobs automatically');
    }
    for (const preview of [candidate({ state: 'active', reason: 'active_service', status: 1, eligible: false, previewToken: null, expiresAt: null }),
        candidate({ state: 'unknown', reason: 'lifecycle_unavailable', eligible: false, previewToken: null, expiresAt: null }),
        candidate({ state: 'active' }), candidate({ serviceId: 9807 }), candidate({ clientId: attack }), candidate({ reason: attack }), candidate({ previewToken: 'invalid' })]) {
        const fixture = await open(preview);
        assert.equal(fixture.nodes.get('apply-service-lifecycle').disabled, true, 'Active, ambiguous or malformed lifecycle proof must never enable retirement');
        await fixture.nodes.get('apply-service-lifecycle').fire('click');
        assert.equal(fixture.writes().length, 0);
        assert(!fixture.nodes.get('service-lifecycle-status').textContent.includes('<img'), 'Unknown reason text cannot enter the panel');
    }
    const queued = await open(candidate());
    queued.reply('update/service_lifecycle', envelope(outcome({ queued: 1, jobKeys: ['Q1001'], released: false, reason: 'lifecycle_router_cleanup_pending' })));
    await queued.nodes.get('apply-service-lifecycle').fire('click'); await flush();
    assert(queued.nodes.get('service-lifecycle-status').textContent.includes('liberará al completar la limpieza'), 'A queued router cleanup cannot claim the IP is already free');
    assert.equal(queued.nodes.get('service-lifecycle-result').children[0].textContent, 'Baja programada · Q1001');
    assert.equal(queued.calls.filter(call => call.target === 'jobs' && call.action === 'run').length, 0);
    const recovery = await open(candidate(), { bootstrap: { readOnly: true, details: { duplicate_groups: 1, conflicts: [] } } });
    assert(recovery.calls.some(call => call.target === 'service_lifecycle' && call.action === 'preview'), 'Fresh lifecycle evidence remains available during recovery');
    assert.equal(recovery.nodes.get('apply-service-lifecycle').disabled, true, 'Migration recovery must retain its compatibility barrier for retirement writes');
    await recovery.nodes.get('apply-service-lifecycle').fire('click');
    assert.equal(recovery.writes().length, 0);
    const refreshFailure = await open(candidate());
    refreshFailure.reply('update/service_lifecycle', envelope(outcome()));
    refreshFailure.reply('list/jobs', { status: 'failed', error: true, message: 'read_failed' });
    await refreshFailure.nodes.get('apply-service-lifecycle').fire('click'); await flush();
    assert(refreshFailure.nodes.get('service-lifecycle-status').textContent.includes('liberaron sus reservas locales'), 'A failed queue refresh must not turn a confirmed retirement into an uncertain result');
    assert.equal(refreshFailure.writes().length, 1);
    const uncertain = await open(candidate()), pending = deferred();
    uncertain.reply('update/service_lifecycle', () => pending.promise);
    const first = uncertain.nodes.get('apply-service-lifecycle').fire('click'); await flush();
    await uncertain.nodes.get('apply-service-lifecycle').fire('click');
    assert.equal(uncertain.writes().length, 1, 'Apply must be single flight');
    pending.resolve({ status: 'failed', error: true, message: 'transport failure' }); await first; await flush();
    assert.equal(uncertain.nodes.get('apply-service-lifecycle').disabled, true);
    await uncertain.nodes.get('apply-service-lifecycle').fire('click');
    assert.equal(uncertain.writes().length, 1, 'An uncertain apply must consume its token instead of replaying it');
    assert(uncertain.nodes.get('service-lifecycle-status').textContent.includes('No se confirmó el retiro'));
    const closed = recoveryPanelFixture({ initialReplies: new Map([['list/jobs', envelope(job)]]) }); await closed.navigate('jobs');
    const waiting = deferred(); closed.reply('list/service_lifecycle', () => waiting.promise);
    const review = closed.tables.get('#jobs-table tbody').children[0].querySelector('.job-description').children.find(child => child.textContent === 'Revisar servicio solicitante #7279');
    const reviewing = review.fire('click'); await flush(); await closed.documentEvent('keydown', { key: 'Escape' });
    waiting.resolve(envelope(candidate())); await reviewing; await flush();
    assert.equal(closed.nodes.get('service-lifecycle-modal').style.display, 'none');
    assert.equal(closed.nodes.get('apply-service-lifecycle').disabled, true, 'A closed preview response must not leave an actionable token');
    assert.equal(closed.writes().length, 0);
}

async function checkCurrentAddressEvidence() {
    const base = (id, state, current, known = true) => ({ service_id: id, client_id: 200 + id, client_name: 'Cliente de ejemplo', service_status: 'Activo', source: 'crm',
        client_source: 'cache', client_archived: false, saved_address: '192.0.2.77', current_address: current, current_address_known: known, address_comparison: state,
        address_verified_at: '2026-10-14T13:00:00Z', address_evidence: { address: { known, value: current, reason: known ? null : 'attributes_unknown' } } });
    const details = { duplicate_groups: 1, conflicts: [{ family: 'IPv4', address: '192.0.2.77', service_ids: [42, 43, 44, 45], service_count: 4,
        owners: [base(42, 'removed', null), base(43, 'changed', '192.0.2.88'), base(44, 'same', '192.0.2.77'), base(45, 'changed', attack, false)] }] };
    const fixture = recoveryPanelFixture({ bootstrap: { readOnly: true, details }, details }); await fixture.navigate('network_conflicts');
    const owner = id => fixture.nodes.get('network-conflicts-body').children[0].children[1].children.find(node => Number(node.dataset.serviceId) === id);
    const evidence = id => owner(id).children[0].children.find(node => node.className === 'conflict-address-evidence');
    assert(evidence(42).innerHTML.includes('192.0.2.77') && evidence(42).innerHTML.includes('Sin IP asignada'));
    assert.equal(evidence(42).children[0].textContent, 'IP retirada en UISP');
    assert(evidence(43).innerHTML.includes('192.0.2.88')); assert.equal(evidence(43).children[0].textContent, 'IP cambiada en UISP');
    assert.equal(evidence(44).children[0].textContent, 'Coincide con la IP guardada');
    assert.equal(evidence(45).children[0].textContent, 'IP actual sin comprobar', 'Unknown evidence must never be presented as removal or change');
    assert(!evidence(45).innerHTML.includes('<img') && !evidence(45).innerHTML.includes(attack));
    assert.match(evidence(45).lastElementChild.textContent, /sin comprobar no autoriza su limpieza/);
    assert.equal(fixture.diagnostics().length, 0, 'Displaying current-address metadata must not initiate hidden CRM reads');
    const clientOnly = plain(details);
    Object.assign(clientOnly.conflicts[0].owners[0], { source: 'cache', saved_address: '192.0.2.77', current_address: null, current_address_known: false, address_comparison: 'unknown',
        address_verified_at: null, address_evidence: { address: { known: false, value: null, reason: 'mapping_unknown' } }, client_source: 'crm', client_archived: true });
    fixture.reply('list/network_conflicts', { status: 'ok', error: false, data: clientOnly, recovery: { readOnly: true } });
    await owner(42).children[3].fire('click'); await flush();
    assert.equal(evidence(42).children[0].textContent, 'IP retirada en UISP', 'Client-only verification must retain previously verified address evidence');
    await fixture.navigate('system'); await fixture.navigate('network_conflicts');
    assert.equal(evidence(42).children[0].textContent, 'IP retirada en UISP');
    const token = 'b'.repeat(64);
    fixture.reply('cleanup/network_conflicts', { status: 'ok', error: false, data: { previewToken: token, expiresAt: '2099-10-14T13:00:00Z', candidates: [{ service_id: 42, client_id: 242,
        address: '192.0.2.77', address6: '2001:db8::42', reason: 'stale_address', fields: ['address'],
        evidence: [{ field: 'address', saved_address: '192.0.2.77', current_address: null, state: 'removed' }], witness_service_ids: [43] }], blocked: [{ service_id: 45, reason: 'address_unknown' }] }, recovery: { readOnly: true } });
    await owner(42).children[2].fire('click');
    const candidate = fixture.nodes.get('network-cleanup-candidates').children[0];
    assert(candidate, 'A verified stale_address candidate must be reviewable');
    assert(candidate.children[1].innerHTML.includes('192.0.2.77'));
    assert(!candidate.children[1].innerHTML.includes('2001:db8::42'), 'An unaffected IPv6 assignment must not be presented as selected for removal');
    assert.match(candidate.children[1].children[0].textContent, /la ficha del servicio y las demás familias se conservarán/);
    assert.equal(candidate.children[1].children[1].textContent, 'IP actual en UISP (IPv4): Sin IP asignada');
    assert.equal(fixture.nodes.get('apply-network-cleanup').disabled, true); assert.equal(candidate.children[0].checked, false);
    assert.match(fixture.nodes.get('network-cleanup-blocked').children[0].innerHTML, /No se pudo confirmar la IP actual/);
    assert.equal(fixture.calls.filter(call => call.changeType === 'cleanup' && call.action === 'apply').length, 0, 'Current IP mismatch markers and review must never apply cleanup automatically');
    const unknownService = plain(clientOnly); unknownService.conflicts[0].owners[0].source = 'crm';
    fixture.reply('list/network_conflicts', { status: 'ok', error: false, data: unknownService, recovery: { readOnly: true } });
    await owner(42).children[1].fire('click'); await flush();
    assert.equal(evidence(42).children[0].textContent, 'IP actual sin comprobar', 'An explicit failed/unknown address comparison must replace an older mismatch proof');
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
    await evaluate('api.uploadFullBackup({name:"fixture.zip"})');
    assert.deepEqual(requests.at(-1).options.body.values.map(item => item[0]), ['workflow', 'fullBackup']);
    const fullName = 'full-backup-20261015T120000Z-' + 'a'.repeat(32) + '.zip';
    assert.equal(evaluate(`api.downloadUrl(${JSON.stringify(fullName)})`), 'https://fixture.invalid/crm/_plugins/ros-plugin/public.php?download=' + fullName);
    evaluate('globalThis.restoredApi = new ApiService(); restoredApi.requireReload()');
    const beforeBlocked = requests.length;
    for (const action of ['restoredApi.saveConfig({site:"changed"})', 'restoredApi.restoreFullBackup("a".repeat(64))',
        'restoredApi.uploadBackup({name:"fixture.db"})', 'restoredApi.uploadFullBackup({name:"fixture.zip"})'])
        assert.equal((await evaluate(action)).error, true, 'Stale controls remained writable after a full restore');
    assert.equal(requests.length, beforeBlocked, 'Restored state must require reload before any further write');
    await evaluate('restoredApi.getJobs()');
    assert.equal(requests.length, beforeBlocked + 1, 'Restore lock must preserve consultation');
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
        const syncToken = 'a'.repeat(64);
        responders.set('update/device_sync/begin', { status: 'ok', error: false, data: {
            syncToken, phase: 'catalog', done: false, processed: 0, total: 0, requested: 0, completed: 0, failed: 0, catalogScanned: 0 } });
        responders.set('update/device_sync/step', { ...response, data: { syncToken, phase: 'complete', done: true,
            processed: 2, total: 2, catalogScanned: 2, ...response.data } });
        responseStatuses.set('update/device_sync/step', status);
        const syncButton = tables.get('#devices-table tbody').children[0].querySelector('.row-actions').children[2];
        assert.equal(syncButton.title, 'Sincronizar ahora');
        assert.equal(syncButton['aria-label'], 'Sincronizar ahora');
        assert.equal(syncButton.className, 'btn btn-primary row-action');
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
        const sent = requests.slice(beforeRequests).filter(request => request.payload?.target === 'device_sync');
        if (accept) {
            assert.equal(sent.length, 2);
            assert.deepEqual(sent[0].payload, { changeType: 'update', target: 'device_sync', action: 'begin', data: { deviceId: 1 } });
            assert.deepEqual(sent[1].payload, { changeType: 'update', target: 'device_sync', action: 'step', data: { syncToken } },
                'Immediate synchronization must continue its authenticated token without a preview or a UI force flag');
        } else assert.equal(sent.length, 0, 'Canceling router reconnection must not issue provisioning requests');
        assert.equal(syncButton.disabled, false);
        assert.equal(elements.get('loader').style.display, 'none');
        responseStatuses.delete('update/device_sync/step');
        return elements.get('notifications').lastElementChild;
    }
    const canceledNotifications = elements.get('notifications').children.length;
    await synchronizeDevice({ status: 'ok', error: false, data: { requested: 2, completed: 2, failed: 0 } }, false);
    assert.equal(elements.get('notifications').children.length, canceledNotifications, 'Canceling must not claim synchronization or reconnection');
    const syncSuccess = await synchronizeDevice({ status: 'ok', error: false, data: { requested: 2, completed: 2, failed: 0 } }, true);
    assert.equal(syncSuccess.role, 'status');
    assert(syncSuccess.children[0].textContent.includes('Sincronización ejecutada. Revisa la confirmación en Trabajos.'));
    const syncPartial = await synchronizeDevice({ status: 'ok', error: false,
        data: { requested: 2, completed: 1, failed: 1, failures: [{ serviceId: 1420, planId: 1 }] } }, true);
    assert.equal(syncPartial.role, 'alert');
    assert.match(syncPartial.children[0].textContent, /1 de 2 servicios completados; 1 pendientes o con error/);
    assert(!syncPartial.children[0].textContent.includes('Sincronización ejecutada. Revisa la confirmación en Trabajos.'), 'Partial synchronization must not claim that all jobs completed');
    const syncInvalid = await synchronizeDevice({ status: 'accepted', error: false, accepted: true, data: {} }, true);
    assert.equal(syncInvalid.role, 'alert');
    assert(!syncInvalid.children[0].textContent.includes('Sincronización ejecutada.'), 'An incomplete immediate-sync response cannot claim completed reconnections');
    const syncFailed = await synchronizeDevice({ status: 'failed', error: true, message: 'fixture interrupted' }, true, 502);
    assert.equal(syncFailed.role, 'alert'); assert.match(syncFailed.children[0].textContent, /fixture interrupted/);
    responders.delete('update/device_sync/begin'); responders.delete('update/device_sync/step');
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
    assert.deepEqual(requests.slice(jobRunRequests).filter(request => !['jobs_status', 'job_recovery'].includes(request.payload.target)).map(request => request.payload.target + '/' + request.payload.action), ['jobs/run', 'jobs/list'],
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
    assert.deepEqual(requests.slice(deleteJobRequests).filter(request => !['jobs_status', 'job_recovery'].includes(request.payload.target)).map(request => request.payload.target + '/' + request.payload.action), ['jobs/delete', 'jobs/list']);
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
        assert.deepEqual(requests.slice(requestStart).filter(request => !['jobs_status', 'job_recovery'].includes(request.payload.target)).map(request => request.payload.target + '/' + request.payload.action),
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
    const ownershipError = 'router_ownership_conflict: current_service=1420 previous_service=1450 reason=previous_active';
    const ownershipFailure = await jobEnvelopeAction('run', { status: 'failed', error: true, data: {
        operation: 'run', requested: ['Q1'], results: { Q1: { success: false, error: ownershipError, attempted: true } },
        jobs: { Q1: jobMeta('Q1', { error: ownershipError, attempts: 1 }) },
        summary: jobSummary(1, { attempted: 1, failed: 1, pending: 1 }) } }, ['Q1']);
    assert(ownershipFailure.children[0].textContent.includes('El comentario de la cuenta del servicio #1420 indica el servicio #1450.')
        && ownershipFailure.children[0].textContent.includes('El servicio indicado sigue vigente en UISP.')
        && !ownershipFailure.children[0].textContent.includes('router_ownership_conflict:'), 'Run feedback must explain the actual ownership reason too');
    for (const [language, reason, prefix, detail] of [
        ['es', 'marker_unverified', 'El comentario de la cuenta del servicio #1420 indica el servicio #1450.', 'faltan datos coincidentes de usuario, Site o IP'],
        ['en', 'marker_unverified', 'The account comment for service #1420 names service #1450.', 'matching username, Site or IP details are missing'],
        ['es', 'mapping_hotspot_unverified', 'El comentario de la cuenta del servicio #1420 indica el servicio #1450.', 'atributo de Hotspot configurado'],
        ['en', 'mapping_hotspot_unverified', 'The account comment for service #1420 names service #1450.', 'Hotspot attribute configured in the plugin']
    ]) {
        jobsTranslations.PanelI18n.setLanguage(language);
        const error = 'router_ownership_conflict: current_service=1420 previous_service=1450 reason=' + reason;
        const feedback = await jobEnvelopeAction('run', { status: 'failed', error: true, data: {
            operation: 'run', requested: ['Q1'], results: { Q1: { success: false, error, attempted: true } },
            jobs: { Q1: jobMeta('Q1', { error, attempts: 1 }) },
            summary: jobSummary(1, { attempted: 1, failed: 1, pending: 1 }) } }, ['Q1']);
        const message = feedback.children[0].textContent;
        assert(message.includes(prefix) && message.includes(detail) && !message.includes('router_ownership_conflict:'),
            'The failed action toast must translate the exact ownership blocker in ' + language);
        assert(!message.includes('previous service') && !message.includes('servicio anterior'), 'Toast must not confirm an unverified previous owner');
    }
    jobsTranslations.PanelI18n.setLanguage('es');
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
    const awaitingConfirmation = await jobEnvelopeAction('run', { status: 'ok', error: false, data: {
        operation: 'run', requested: ['Q2'], results: { Q2: { success: true, error: null, attempted: true } },
        jobs: { Q2: jobMeta('Q2', { state: 'succeeded', verification: { state: 'unconfirmed' } }) },
        summary: jobSummary(1, { attempted: 1, succeeded: 1 }) } }, ['Q2']);
    assert.equal(awaitingConfirmation.className, 'toast toast-info', 'Unconfirmed observation must not show a green verified success or turn a completed write into an error');
    assert(tables.get('#jobs-table tbody').innerHTML.includes('No hay trabajos en cola.'), 'An unconfirmed receipt must not requeue completed execution');
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
    await checkNonblockingDeviceSync();
    await checkDeviceSyncReviewGuards();
    await checkImmediateDeviceSyncProtocol();
    await checkPendingJobsIndicator();
    await checkWarningDismissals();
    await checkJobRecoveryResolutionPanel();
    await checkRecoveryObservationPanel();
    await checkRetainedRecoveryPanel();
    await checkForcedRecoveryDiscardPanel();
    await checkInvalidReservationRepairPanel();
    await checkCrmNetworkMonitorPanel();
    await checkCrmNetworkResolutionPanel();
    await checkCrmHistoricalResolutionPanel();
    await checkCrmEditResultFeedback();
    await checkResolvedCrmConflictFeedback();
    await checkNetworkRecoveryPanel();
    await checkNetworkCleanupPanel();
    await checkNetworkClientArchivePanel();
    await checkNavigationContinuity();
    checkSystemBackupGrouping();
    await checkRequesterLifecycleReview();
    await checkCurrentAddressEvidence();
    console.log('Panel regression checks passed: terminal CRM edit outcomes, confirmed receipts and uncertain readback, bounded read-only retries without token replay, ended-service historical fallback and separate cleanup review, identity/absence/reactivation safeguards and cancellation; recovery navigation, escaped owner/status diagnostics, same-origin CRM links, explicit single-service verification without blocking navigation, update/upload guards and diagnostic downloads; partial provisioning counts/diagnostics/persistence and full totals, API contracts, old HTML/API manual editors, saved global contention flags and drafts, legacy zero ratios, configuration keyboard drafts during pending saves/reloads, language changes preserving open modal/custom values/scroll and asynchronous settings drafts, stale language response rejection, legacy global language preservation, inactive control omission, configuration failure recovery, stable local editor visibility, deferred plan saves and failure drafts, burst templates and math, custom plans, legacy sentinels, drafts, one-click saves, nonblocking notifications, safe text, numeric types, overrides, TLS, backup routes and failure cleanup.');
})().catch(error => { console.error(error); process.exitCode = 1; });
