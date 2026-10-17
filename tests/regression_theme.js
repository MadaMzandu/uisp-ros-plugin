/* Theme behavior is tested without browser storage or a connection to UISP. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../src/public/panel_v2/js/theme.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '../src/public/panel_v2/css/style.css'), 'utf8');

function createFixture({ saved = null, systemLight = false, blockedStorage = false, legacyMedia = false, ready = false, noMedia = false } = {}) {
    const attributes = new Map();
    const events = new Map();
    const documentEvents = new Map();
    const controlEvents = new Map();
    const writes = [];
    let mediaCallback = null;
    const control = { value: '', addEventListener: (name, callback) => controlEvents.set(name, callback) };
    const media = { matches: systemLight };
    media[legacyMedia ? 'addListener' : 'addEventListener'] = legacyMedia
        ? callback => { mediaCallback = callback; }
        : (name, callback) => { assert.equal(name, 'change'); mediaCallback = callback; };
    const window = {
        localStorage: {
            getItem: key => { assert.equal(key, 'ros-panel-theme'); if (blockedStorage) throw new Error('blocked'); return saved; },
            setItem: (key, value) => { if (blockedStorage) throw new Error('blocked'); writes.push([key, value]); }
        },
        matchMedia: query => { assert.equal(query, '(prefers-color-scheme: light)'); if (noMedia) throw new Error('unavailable'); return media; },
        addEventListener: (name, callback) => events.set(name, callback),
        dispatchEvent: event => { if (events.has(event.type)) events.get(event.type)(event); },
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } }
    };
    const document = {
        readyState: ready ? 'complete' : 'loading',
        documentElement: { setAttribute: (name, value) => attributes.set(name, value) },
        getElementById: id => { assert.equal(id, 'panel-theme'); return control; },
        addEventListener: (name, callback) => documentEvents.set(name, callback)
    };
    vm.runInNewContext(source, { window, document });
    return {
        attributes, control, writes, api: window.PanelTheme,
        ready: () => documentEvents.get('DOMContentLoaded')?.(),
        select: value => { control.value = value; controlEvents.get('change')(); },
        system: matches => { media.matches = matches; mediaCallback?.({ matches }); },
        storage: (key, newValue) => events.get('storage')({ key, newValue })
    };
}

const initial = createFixture({ systemLight: true });
assert.equal(initial.attributes.get('data-theme'), 'dark', 'First use keeps the established dark appearance before DOM ready.');
assert.equal(initial.api.getPreference(), 'dark');
initial.ready();
assert.equal(initial.control.value, 'dark');
initial.select('light');
assert.equal(initial.attributes.get('data-theme'), 'light');
assert.equal(initial.writes.at(-1)[1], 'light');
initial.system(false);
assert.equal(initial.api.getTheme(), 'light', 'Explicit light ignores an operating system change.');
initial.select('system');
assert.equal(initial.api.getTheme(), 'dark');
initial.system(true);
assert.equal(initial.api.getTheme(), 'light');
assert.equal(initial.control.value, 'system', 'The selector retains System while the resolved colors change.');
assert.equal(initial.attributes.get('data-theme-preference'), 'system');
initial.select('dark');
initial.system(true);
assert.equal(initial.api.getTheme(), 'dark');

assert.equal(createFixture({ saved: 'light', ready: true }).api.getTheme(), 'light', 'Saved light is applied synchronously.');
assert.equal(createFixture({ saved: 'system', systemLight: true }).api.getTheme(), 'light');
assert.equal(createFixture({ saved: 'not-a-theme', systemLight: true }).api.getTheme(), 'dark');
const blocked = createFixture({ blockedStorage: true });
blocked.ready();
blocked.select('light');
assert.equal(blocked.api.getTheme(), 'light', 'A rejected storage write still applies the preference to this page.');
assert.equal(blocked.writes.length, 0);
const legacy = createFixture({ saved: 'system', legacyMedia: true });
legacy.system(true);
assert.equal(legacy.api.getTheme(), 'light');
assert.equal(createFixture({ saved: 'system', noMedia: true }).api.getTheme(), 'dark');
initial.storage('other-setting', 'light');
assert.equal(initial.api.getTheme(), 'dark');
initial.storage('ros-panel-theme', 'light');
assert.equal(initial.api.getTheme(), 'light');
assert.equal(initial.control.value, 'light');
initial.storage('ros-panel-theme', null);
assert.equal(initial.api.getTheme(), 'dark', 'Removing a preference restores the default.');

// Both palettes must keep text and essential controls readable. Alpha tokens
// are visually checked in the browser; these assertions cover opaque colors.
const darkBlock = css.match(/:root\s*\{([\s\S]*?)\}/)[1];
const lightBlock = css.match(/:root\[data-theme="light"\]\s*\{([\s\S]*?)\}/)[1];
function tokens(block) {
    return Object.fromEntries([...block.matchAll(/(--[\w-]+):\s*(#[\da-f]+)\s*;/gi)].map(match => [match[1], match[2]]));
}
function luminance(hex) {
    const digits = hex.slice(1);
    const rgb = (digits.length === 3 ? [...digits].map(c => c + c).join('') : digits).match(/../g).map(v => parseInt(v, 16) / 255);
    const linear = rgb.map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
    return linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
}
function contrast(a, b) {
    const light = Math.max(luminance(a), luminance(b));
    const dark = Math.min(luminance(a), luminance(b));
    return (light + 0.05) / (dark + 0.05);
}
const darkTokens = tokens(darkBlock);
for (const [name, palette] of [['dark', darkTokens], ['light', { ...darkTokens, ...tokens(lightBlock) }]]) {
    for (const foreground of ['--text-main', '--text-muted', '--danger', '--success', '--link', '--placeholder']) {
        assert.ok(contrast(palette[foreground], palette['--bg-card']) >= 4.5, `${name} ${foreground} needs readable contrast on cards.`);
    }
    assert.ok(contrast(palette['--button-text'], palette['--button-primary']) >= 4.5, `${name} primary button text needs readable contrast.`);
    assert.ok(contrast(palette['--control-border'], palette['--bg-card']) >= 3, `${name} input borders need visible contrast.`);
    assert.ok(contrast(palette['--switch-knob'], palette['--switch-off']) >= 3, `${name} an off switch must remain visible.`);
}
const rulesAfterTokens = css.slice(css.indexOf('* {'));
assert.equal(/#[\da-f]{3,8}\b|rgba?\(/i.test(rulesAfterTokens), false, 'Component colors must use palette tokens.');
console.log('PASS theme: early preference, explicit/system selection, storage failure and sync, legacy media, palette contrast.');
