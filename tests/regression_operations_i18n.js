/* Six-language coverage for operational observations, including dynamic maps. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const panel = path.join(__dirname, '../src/public/panel_v2');
const source = fs.readFileSync(path.join(panel, 'js/i18n.js'), 'utf8');
const operations = fs.readFileSync(path.join(panel, 'js/operations.js'), 'utf8');
const workflows = fs.readFileSync(path.join(panel, 'js/workflows.js'), 'utf8');
const serviceResolution = fs.readFileSync(path.join(panel, 'js/service_resolution.js'), 'utf8');
const markup = fs.readFileSync(path.join(panel, 'index.php'), 'utf8');
const calls = [];
const context = vm.createContext({
    document: { addEventListener() {}, documentElement: {}, querySelectorAll() { return []; } },
    localStorage: { getItem() { return 'es'; }, setItem(key, value) { calls.push([key, value]); } }
});
vm.runInContext(source, context);
const i18n = context.PanelI18n;
const required = [
    'Diferencia detectada',
    'Sincronización ejecutada. Revisa la confirmación en Trabajos.',
    'Historial local', 'Registros de recuperación: {count}', 'Registros informativos conservados: {count}',
    'Estos registros no son trabajos ejecutables.',
    'Comprobación guardada: {date}',
    'No se pudo consultar UISP.', 'No se pudo consultar el router.',
    'La cuenta no está presente en el router consultado.', 'No se pudo identificar una única cuenta en el router.',
    'La configuración del router cambió durante la consulta.', 'Falta una identidad válida para consultar la cuenta.',
    'El Site no identifica un único router configurado.', 'IPv6 no pudo comprobarse; IPv4 conserva su resultado.',
    'La consulta alcanzó su límite; vuelve a comprobar.', 'Falta la configuración necesaria para consultar el servicio.',
    'Previsto por un trabajo pendiente', 'Último estado aplicado', 'Datos de la caché', 'Cliente #{id}',
    'Aplicado y verificado', 'Aplicado sin confirmar', 'Diferencias en el router', 'Sin cambios que comprobar',
    'Sin comprobación registrada', 'Comprobación anterior', 'Sin observaciones', 'Diagnóstico por servicio',
    'No se pudo completar la consulta.', 'Sin comprobaciones registradas todavía.', 'Se muestran los resultados más recientes.',
    'Consultando…', 'No se pudo actualizar. Se conserva la última lectura.', 'Actividad reciente', 'Ejecución observada',
    'Última ejecución fallida', 'Sin actividad reciente', 'Proceso programado', 'Último inicio', 'Última finalización',
    'El horario externo no está confirmado; se muestra la actividad observada.', 'Último webhook aceptado',
    'Aceptado indica recepción autorizada, no un cambio aplicado.', 'Cola de tareas', 'Pendientes', 'Con error',
    'Pendiente más antiguo', 'Próximo reintento previsto', 'Caché y mantenimiento', 'Mantenimiento registrado',
    'Sin mantenimiento registrado', 'Esta consulta no ejecuta trabajos ni actualiza la caché.', 'Consultado: {date}',
    'Sin resultados en los registros locales. Puedes consultar un ID de servicio directamente.',
    'Hay más resultados; precisa la búsqueda.', 'Selecciona un servicio para consultar UISP y el router.',
    'Consultado', 'Previsto por el plugin', 'Campo', 'Actualizar diagnóstico', 'Trabajos del servicio',
    'Sin comprobar significa que falta evidencia; no equivale a que el servicio esté correcto.',
    'No hay trabajos registrados.', 'Consultando UISP y router…', 'Resultados recientes',
    'La revisión contiene datos sin comprobar o diferencias. Revisa las columnas antes de aplicar cambios.',
    'Compara UISP, el estado previsto del plugin y la cuenta del router sin modificar el servicio.',
    'Servicio, cliente, usuario o IP', 'Salud de la automatización',
    'La confirmación consulta el router. Verificar de nuevo no repite los cambios ni las reconexiones.',
    'Actividad observada del proceso automático, webhooks y cola. Una ejecución manual no cuenta como ejecución programada.',
    'Campos comprobados', 'Valor esperado', 'Valor observado', 'Causa',
    'La consulta superó su límite; verifica de nuevo.', 'El servicio cambió desde esta comprobación.',
    'Cuenta', 'Sesión', 'Cola', 'No hay pendientes',
    'El próximo reintento depende del proceso programado y de los bloqueos del servicio.'
];
assert(required.length >= 40, 'Operational feature coverage was reduced');
// User-facing values occur in conditional expressions and maps as well as t('…').
// Their labels begin with an uppercase letter; technical selectors and status codes do not.
const used = new Set(required);
// Direct calls are independent of surrounding quote pairing in nested template expressions.
for (const match of operations.matchAll(/\bt\('([^'\n]+)'/g)) used.add(match[1]);
for (const match of operations.matchAll(/'([^'\n]+)'/g))
    if (/^[A-ZÁÉÍÓÚÑ]/.test(match[1])) used.add(match[1]);
for (const match of workflows.matchAll(/\bt\('([^'\n]+)'/g)) used.add(match[1]);
for (const match of workflows.matchAll(/'([^'\n]+)'/g))
    if (/^[A-ZÁÉÍÓÚÑ]/.test(match[1]) && match[1] !== 'Escape') used.add(match[1]);
for (const match of serviceResolution.matchAll(/'([^'\n]+)'/g))
    if (/^[A-ZÁÉÍÓÚÑ]/.test(match[1]) && match[1] !== 'Escape') used.add(match[1]);
used.add('Recarga el panel antes de hacer cambios sobre el estado restaurado.');
used.add('Revisar sincronización');
used.add('Trabajo solicitante: servicio #{id}.');
used.add('Servicio solicitante #{id}');
used.add('Servicio con reserva #{id}');
const decode = text => text.replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&#39;/g, "'");
for (const match of markup.matchAll(/\bdata-i18n(?:-(?:placeholder|title|aria-label))?="([^"]+)"/g)) used.add(decode(match[1]));
const tokens = text => [...text.matchAll(/\{([a-zA-Z][a-zA-Z0-9_]*)\}/g)].map(match => match[1]).sort();
for (const code of ['es', 'en', 'pt', 'fr', 'de', 'it']) {
    assert(i18n.setLanguage(code));
    for (const key of used) {
        assert(i18n.has(key, code), `Missing operational translation: ${code}/${key}`);
        const translated = i18n.t(key);
        assert.equal(typeof translated, 'string'); assert(translated.trim().length > 0);
        assert.deepEqual(tokens(translated), tokens(key), `Operational placeholders changed: ${code}/${key}`);
        assert(!/<(?:script|iframe|img|svg)\b/i.test(translated), `Operational translation introduced executable markup: ${code}/${key}`);
    }
    const sentinel = '"<img src=x onerror=alert(1)>{date}';
    const result = i18n.t('Consultado: {date}', { date: sentinel });
    assert(result.includes(sentinel), 'Date parameter was evaluated or transformed recursively');
    assert(!result.includes('undefined'), 'A missing presentation value leaked into a translated message');
}
for (const key of ['Aplicado y verificado', 'Aplicado sin confirmar', 'Diagnóstico por servicio', 'Salud de la automatización', 'Diferencia detectada']) {
    i18n.setLanguage('en'); assert.notEqual(i18n.t(key), key, 'English still falls back to Spanish');
}
assert(calls.every(([key]) => key === 'ros-panel-language'), 'Operational translations changed plugin settings');
console.log(`Operations localization passed: ${required.length} feature labels, dynamic status/field maps, six languages, source coverage and safe parameter parity.`);
