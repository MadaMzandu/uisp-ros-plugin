/* Offline checks: dictionaries, local preferences, in-place DOM updates and safe parameters. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const panel = path.join(__dirname, '../src/public/panel_v2');
const source = fs.readFileSync(path.join(panel, 'js/i18n.js'), 'utf8');
const app = fs.readFileSync(path.join(panel, 'js/app.js'), 'utf8');
const api = fs.readFileSync(path.join(panel, 'js/api.js'), 'utf8');
const markup = fs.readFileSync(path.join(panel, 'index.php'), 'utf8');
const codes = ['es', 'en', 'pt', 'fr', 'de', 'it'];

function fixture(saved, blocked = false) {
    const writes = [];
    const callbacks = [];
    const selector = { value: '', addEventListener(type, callback) { callbacks.push(callback); } };
    const control = { value: '37.5', checked: true, disabled: false };
    const title = { attributes: { 'data-i18n': 'Planes' }, getAttribute(name) { return this.attributes[name] ?? null; }, setAttribute(name, value) { this.attributes[name] = value; } };
    const count = { ...title, attributes: { 'data-i18n': 'Seleccionados: {count}', 'data-i18n-values': '{"count":3}' } };
    const malicious = '"<img src=x onerror=alert(1)>{label}';
    const message = { ...title, attributes: { 'data-i18n': 'Error: {message}', 'data-i18n-values': JSON.stringify({ message: malicious }) } };
    const search = { ...title, attributes: { 'data-i18n-placeholder': 'Buscar planes...' } };
    let ready;
    const document = {
        documentElement: {},
        addEventListener(type, listener) { ready = listener; },
        getElementById(id) { return id === 'panel-language' ? selector : null; },
        querySelectorAll(query) {
            if (query === '[data-i18n]') return [title, count, message];
            if (query === '[data-i18n-placeholder]') return [search];
            return [];
        }
    };
    const context = vm.createContext({
        document,
        localStorage: {
            getItem() { if (blocked) throw new Error('storage unavailable'); return saved; },
            setItem(key, value) { if (blocked) throw new Error('storage unavailable'); writes.push([key, value]); }
        }
    });
    vm.runInContext(source, context);
    ready();
    return { i18n: context.PanelI18n, title, count, message, malicious, control, search, selector, document, writes, callbacks };
}

const ui = fixture(null);
assert.equal(ui.i18n.getLanguage(), 'es');
assert.equal(ui.selector.value, 'es');
assert.equal(ui.title.textContent, 'Planes');
assert.equal(ui.count.textContent, 'Seleccionados: 3');
assert.equal(ui.message.textContent, 'Error: ' + ui.malicious, 'Translation parameters must remain literal text and must not be recursively interpreted');
const originalControl = { ...ui.control };
for (const code of codes) {
    assert.equal(ui.i18n.setLanguage(code), true);
    assert.equal(ui.document.documentElement.lang, code);
    for (const key of ui.i18n.keys()) {
        assert(ui.i18n.has(key, code), 'Missing translation: ' + code + '/' + key);
        const translated = ui.i18n.t(key);
        assert.equal(typeof translated, 'string');
        assert(translated.length > 0, 'Empty translation: ' + code + '/' + key);
        const originalTokens = [...key.matchAll(/\{([a-zA-Z][a-zA-Z0-9_]*)\}/g)].map(match => match[1]).sort();
        const translatedTokens = [...translated.matchAll(/\{([a-zA-Z][a-zA-Z0-9_]*)\}/g)].map(match => match[1]).sort();
        assert.deepEqual(translatedTokens, originalTokens, 'Translation dropped or invented parameters: ' + code + '/' + key);
    }
    assert.deepEqual(ui.control, originalControl, 'Presentation preferences must not alter form values');
    for (const error of ['jobs_are_pending_or_running', 'jobs_pending_after_failed_attempt']) {
        assert.notEqual(ui.i18n.t(error), error, 'Job failures must explain the queue outcome in ' + code);
        assert(ui.i18n.t(error).length > 40, 'Queue errors must direct users to the row details');
    }
    assert(ui.i18n.t('Intentos: {count}', { count: 12 }).includes('12'));
    for (const key of ['Pendiente', 'En ejecución', 'Estado desconocido', 'Próximo intento', 'Último error'])
        assert(ui.i18n.has(key, code), 'Job detail label is missing in ' + code + ': ' + key);
    for (const key of ['Refrescar', 'Refrescar la lista de trabajos', 'Refresco automático',
        'Intervalo de refresco automático', 'Cada {seconds} s', 'Actualizando...', 'Sin actualizar.',
        'Última actualización: {time}.', 'Falló el refresco. Última actualización: {time}.', 'No se pudo refrescar la lista de trabajos.'])
        assert(ui.i18n.has(key, code), 'Jobs refresh controls/status lack a translation in ' + code + ': ' + key);
    for (const key of ['Bloqueado', 'No se puede eliminar', 'Espera a {job} del mismo servicio.',
        'Puede haber cambios previos en el router. Resuelve el último error y ejecuta el trabajo para reconciliarlos.',
        'Puerto accesible sólo comprueba que el puerto responde. Revisa usuario, contraseña, permisos de API y TLS del dispositivo.',
        'La conexión TCP al puerto API no se estableció. Revisa dirección, puerto y acceso desde el servidor de UISP.',
        'La conexión API SSL no se estableció. Revisa dirección, puerto, opciones TLS y certificados del dispositivo.',
        'RouterOS rechazó el acceso por API. Revisa usuario, contraseña y permisos de API del dispositivo.',
        'RouterOS no confirmó el acceso por API antes del tiempo límite. Revisa disponibilidad del router, puerto y TLS.',
        'La conexión se cerró durante el acceso por API. Revisa el servicio API, puerto y TLS del dispositivo.',
        'La respuesta del acceso por API no se pudo interpretar. Revisa el puerto y las opciones API/TLS del dispositivo.',
        'No se pudo enviar la solicitud de acceso por API. Revisa la conexión y las opciones API/TLS del dispositivo.',
        'El trabajo está en ejecución.', 'Hay un evento del servicio en curso.',
        'El servicio está terminado; espera un nuevo evento de UISP.', 'La base de datos está en mantenimiento.',
        '{attempted} intentados · {succeeded} completados · {pending} pendientes · {failed} con error.',
        '{deleted} eliminados · {refused} conservados.', 'Pendiente; no se intentó.',
        'Ejecutar procesa también los trabajos anteriores del mismo servicio. Eliminar cancela los pendientes sin cambiar el router y conserva información para una futura sincronización. Los trabajos en ejecución deben terminar primero.',
        '¿Eliminar {count} trabajos pendientes? Se detendrán sus reintentos sin cambiar el router. Se conservará información para una futura sincronización. Las retiradas seleccionadas también cancelarán sus trabajos dependientes.'])
        assert(ui.i18n.has(key, code), 'Detailed job operation explanation is missing in ' + code + ': ' + key);
    for (const key of ['Sincronizar y reconectar cuentas',
        '¿Sincronizar las cuentas administradas de este MikroTik y reconectar todas sus sesiones PPP/Hotspot?',
        'Sincronización y reconexión completadas.']) {
        assert(ui.i18n.has(key, code), 'Router reconnection action is missing in ' + code + ': ' + key);
        if (code !== 'es') assert.notEqual(ui.i18n.t(key), key, 'Router reconnection action was not translated in ' + code);
    }
    for (const key of [
        'El comentario de la cuenta del servicio #{current} indica el servicio #{previous}. Ese ID debe comprobarse con UISP.',
        'Servicio indicado #{id}',
        'Otro servicio reclama esta misma cuenta en el router. Revisa los usuarios y Sites en UISP y el estado aplicado antes de reintentar.',
        'No se pudo comprobar el catálogo de atributos de UISP. Verifica que esté disponible antes de reintentar.',
        'No se pudo comprobar el atributo de Site configurado en el plugin. Revisa su clave y que sea un atributo de servicio en UISP.',
        'No se pudo comprobar el atributo de usuario configurado en el plugin. Revisa su clave y que sea un atributo de servicio en UISP.',
        'No se pudo comprobar el atributo de Hotspot configurado en el plugin. Revisa su clave y que sea un atributo de servicio en UISP.',
        'El ID del comentario corresponde a otro cliente, pero faltan datos coincidentes de usuario, Site o IP para corregirlo automáticamente. Revisa la cuenta y ambas fichas.',
        'Al ejecutar de nuevo, el plugin comprobará UISP y corregirá el comentario sólo si confirma la identidad de la cuenta y que el servicio indicado terminó, ya no existe o corresponde a otro servicio con datos distintos.'
    ]) {
        assert(ui.i18n.has(key, code), 'Ownership diagnostic lacks a translation in ' + code + ': ' + key);
        if (code !== 'es') assert.notEqual(ui.i18n.t(key), key, 'Ownership diagnostic was not translated in ' + code);
    }
}
assert.equal(ui.i18n.setLanguage('ar'), false);
assert.equal(ui.i18n.getLanguage(), 'it');
assert.equal(ui.i18n.t('unknown_fixture_message'), 'unknown_fixture_message');
ui.selector.value = 'de';
ui.callbacks[0]();
assert.equal(ui.title.textContent, 'Tarife');
assert.equal(ui.search.attributes.placeholder, 'Tarife suchen...');
assert.deepEqual(ui.writes.at(-1), ['ros-panel-language', 'de']);
assert.equal(fixture('fr').i18n.getLanguage(), 'fr');
assert.equal(fixture('invalid').i18n.getLanguage(), 'es');
const unavailable = fixture(null, true);
assert.doesNotThrow(() => unavailable.i18n.setLanguage('pt'));
assert.equal(unavailable.i18n.getLanguage(), 'pt');

const decode = value => value.replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&#39;/g, "'");
const used = new Set();
for (const match of markup.matchAll(/\bdata-i18n(?:-(?:placeholder|title|aria-label))?="([^"]+)"/g)) used.add(decode(match[1]));
for (const text of [app, api]) for (const match of text.matchAll(/\bt\(\s*'([^'\n]+)'/g)) used.add(match[1]);
for (const key of used) assert(ui.i18n.has(key, 'en'), 'UI source lacks a dictionary entry: ' + key);
const literalNames = new Set(['UISP ROS Plugin Panel', 'ROS Plugin', 'ID', 'MAC', 'Burst', 'Español', 'English', 'Português', 'Français', 'Deutsch', 'Italiano', 'Apariencia', 'Oscuro', 'Claro', 'Total: 0', 'Seleccionados: 0']);
for (const match of markup.matchAll(/<([a-z][a-z0-9-]*)\b([^>]*)>([^<>]+)</gi)) {
    const text = decode(match[3].trim());
    if (!text || literalNames.has(text) || /data-i18n=/.test(match[2])) continue;
    assert.fail('Visible static UI text is not marked for translation: ' + text);
}
// Translation annotations may target text and options, but never a wrapper containing inputs.
assert(!/<(?:form|select)[^>]*\bdata-i18n=/.test(markup), 'Translating a container could replace interactive controls');
assert(!/data-i18n="[^"]+"[^>]*>\s*<input/.test(markup), 'Translating a label wrapper could remove its switch');
assert(markup.indexOf('js/i18n.js') < markup.indexOf('js/api.js'));
for (const code of codes) assert(markup.includes('value="' + code + '"'), 'Language selector misses ' + code);
assert(!/\b(?:fetch|XMLHttpRequest)\b/.test(source), 'Language preferences must be entirely local');
console.log('Language regression checks passed: six complete dictionaries, parameter parity, safe text, default/persisted/blocked preferences, translated attributes, markup coverage and no API writes.');
