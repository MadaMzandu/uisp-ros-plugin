/* Operational observations. These controls never provision or reconnect a service. */
(function (root) {
    'use strict';
    const byId = id => root.document.getElementById(id);
    const t = (key, values = {}) => root.PanelI18n?.t(key, values) ?? key.replace(/\{(\w+)\}/g, (all, key) => values[key] ?? all);
    const h = value => PanelUtils.escapeHTML(value);
    const fields = { username: 'Usuario', site: 'Site', address: 'IPv4', address6: 'IPv6', profile: 'Perfil', status: 'Estado', ownership: 'Propiedad', plan_id: 'Plan' };
    const sourceReasons = {
        uisp_unavailable: 'No se pudo consultar UISP.', uisp_client_unavailable: 'No se pudo consultar UISP.',
        router_unavailable: 'No se pudo consultar el router.', router_not_inspected: 'No se pudo consultar el router.',
        account_absent: 'La cuenta no está presente en el router consultado.', router_ambiguous: 'No se pudo identificar una única cuenta en el router.',
        router_authority_changed: 'La configuración del router cambió durante la consulta.', identity_unknown: 'Falta una identidad válida para consultar la cuenta.',
        site_unverified: 'El Site no identifica un único router configurado.', ipv6_unavailable: 'IPv6 no pudo comprobarse; IPv4 conserva su resultado.',
        router_observation_limit: 'La consulta alcanzó su límite; vuelve a comprobar.', configuration_unavailable: 'Falta la configuración necesaria para consultar el servicio.'
    };
    let api, navigate, reviewJob, mounted = false, view = 'devices', mutations = 0;
    let health = null, results = null, detail = null, search = null, selected = null;
    let healthBusy = false, resultsBusy = false, searchSequence = 0, detailSequence = 0, timer, epoch = 0, lastResultsAt = 0;
    let resultsFeedback = '';
    const rechecking = new Set();
    const id = value => Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : null;
    const recordId = record => id(record?.serviceId ?? record?.service_id);
    const text = value => typeof value === 'string' ? value.slice(0, 512) : typeof value === 'number' && Number.isFinite(value) ? String(value) : '';
    const date = value => {
        if (typeof value !== 'string' || !value) return t('Sin observaciones');
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? t('Sin comprobar') : parsed.toLocaleString(root.PanelI18n?.getLanguage());
    };
    const badge = (label, tone = 'neutral') => `<span class="badge badge-${tone}">${h(t(label))}</span>`;
    function verificationLabel(state) {
        return t({ verified: 'Aplicado y verificado', unconfirmed: 'Aplicado sin confirmar', mismatch: 'Diferencias en el router',
            not_applicable: 'Sin cambios que comprobar', missing: 'Sin comprobación registrada', superseded: 'Comprobación anterior' }[state] || 'Sin comprobar');
    }
    function verificationBadge(record) {
        const state = record?.state;
        const label = record?.reason === 'no_verification' ? t('Sin comprobación registrada') : record?.current === false ? t('Comprobación anterior') : verificationLabel(state);
        return `<span class="badge badge-${record?.current !== false && state === 'verified' ? 'success' : ['unconfirmed', 'mismatch'].includes(state) ? 'warning' : 'neutral'}">${h(label)}</span>`;
    }
    function verificationChecks(record) {
        const allowed = new Set(['name', 'comment', 'profile', 'remote-address', 'remote-ipv6-prefix', 'address', 'mac-address', 'duid', 'iaid', 'disabled', 'caller-id', 'rate-limit', 'address-list', 'address-pool', 'local-address', 'parent-queue', 'target', 'max-limit', 'limit-at', 'parent', 'burst-limit', 'burst-threshold', 'burst-time', 'priority']);
        const checks = Array.isArray(record?.checks) ? record.checks.slice(0, 20) : [];
        if (!checks.length) return '';
        return `<details class="operations-checks"><summary>${h(t('Campos comprobados'))}</summary>${checks.map(check => {
            const label = check.kind === 'resource' && /^\/?queue\//.test(check.path || '') ? 'Cola'
                : { account: 'Cuenta', profile: 'Perfil', queue: 'Cola', session: 'Sesión' }[check.kind] || 'Cuenta';
            const rows = (Array.isArray(check.fields) ? check.fields : []).filter(field => allowed.has(field.field)).slice(0, 32);
            return `<div><strong>${h(t(label))}</strong> · ${h(verificationLabel(check.state))}${rows.length ? `<dl>${rows.map(field => `<div class="${field.state === 'mismatch' ? 'operations-difference' : ''}"><dt>${h(field.field)}</dt><dd>${h(t('Valor esperado'))}: ${h(text(field.expected) || t('Sin comprobar'))}<br>${h(t('Valor observado'))}: ${h(text(field.observed) || t('Sin comprobar'))}</dd></div>`).join('')}</dl>` : ''}</div>`;
        }).join('')}</details>`;
    }
    function value(value, field) {
        if (value === null || value === undefined || value === '') return t('Sin comprobar');
        if (field === 'status') return t({ 0: 'Preparación', 1: 'Activo', 2: 'Terminado', 3: 'Suspendido', 4: 'Suspendido', 5: 'Terminado', 7: 'Activo', 8: 'Terminado', enabled: 'Habilitado', disabled: 'Deshabilitado' }[String(value)] || 'Estado desconocido');
        if (field === 'ownership' && typeof value === 'string' && /^[1-9]\d{0,14}:[1-9]\d{0,14}$/.test(value)) {
            const [client, service] = value.split(':'); return t('Cliente #{id}', { id: client }) + ' · ' + t('Servicio #{id}', { id: service });
        }
        return text(value) || t('Sin comprobar');
    }
    function status(elementId, message) { const node = byId(elementId); if (node) node.textContent = message; }
    function accepted(response) {
        if (!response || response.error || response.status !== 'ok' || !response.data || typeof response.data !== 'object') throw new Error('unavailable');
        return response.data;
    }
    function button(label, handler) {
        const node = root.document.createElement('button'); node.type = 'button'; node.className = 'btn btn-secondary'; node.textContent = t(label);
        node.addEventListener('click', handler); return node;
    }
    function actions(node, service, receipt) {
        const controls = root.document.createElement('div'); controls.className = 'operations-actions';
        controls.appendChild(button('Diagnóstico por servicio', () => openService(service)));
        if (receipt && !['missing', 'superseded', 'not_applicable'].includes(receipt.state)) {
            const recheckButton = button('Verificar de nuevo', () => recheck(service));
            recheckButton.disabled = rechecking.has(service) || mutations > 0; controls.appendChild(recheckButton);
        }
        node.appendChild(controls);
    }
    function renderResults() {
        const list = byId('provisioning-results-list'); if (!list) return;
        list.replaceChildren();
        const rows = Array.isArray(results?.items) ? results.items.slice(0, 20) : [];
        if (!rows.length) { status('provisioning-results-status', t(results?.available === false ? 'No se pudo completar la consulta.' : 'Sin comprobaciones registradas todavía.')); return; }
        status('provisioning-results-status', resultsFeedback || (results?.has_more ? t('Se muestran los resultados más recientes.') : ''));
        for (const receipt of rows) {
            const service = recordId(receipt); if (!service) continue;
            const card = root.document.createElement('article'); card.className = 'operations-result-card';
            card.innerHTML = `<strong>${h(t('Servicio #{id}', { id: service }))}</strong>${verificationBadge(receipt)}<small class="text-muted">${h(date(receipt.checkedAt ?? receipt.checked_at))}</small>`;
            card.innerHTML += verificationChecks(receipt);
            actions(card, service, receipt); list.appendChild(card);
        }
    }
    async function refreshResults() {
        if (!mounted || view !== 'jobs' || resultsBusy || mutations) return;
        resultsBusy = true; resultsFeedback = ''; const startedEpoch = epoch; status('provisioning-results-status', t('Consultando…'));
        try { const response = accepted(await api.getProvisioningVerifications()); if (epoch === startedEpoch) { results = response; lastResultsAt = Date.now(); renderResults(); } }
        catch (_) { status('provisioning-results-status', t('No se pudo actualizar. Se conserva la última lectura.')); }
        finally { resultsBusy = false; if (epoch !== startedEpoch && !mutations) root.setTimeout(refreshResults, 0); }
    }
    function metric(label, content) { return `<div class="operations-metric"><span>${h(t(label))}</span><strong>${h(content)}</strong></div>`; }
    function renderHealth() {
        const container = byId('automation-health-content'); if (!container || !health) return;
        const scheduler = health.scheduler || {}, queue = health.queue || {}, cache = health.cache || {}, webhook = health.webhook || {};
        const state = { healthy: 'Actividad reciente', observed: 'Ejecución observada', never_observed: 'Sin observaciones', running: 'En ejecución', failed: 'Última ejecución fallida', delayed: 'Sin actividad reciente', unknown: 'Sin comprobar' }[scheduler.state] || 'Sin comprobar';
        const tone = scheduler.state === 'healthy' ? 'success' : ['failed', 'delayed'].includes(scheduler.state) ? 'warning' : 'neutral';
        const count = number => Number.isSafeInteger(number) && number >= 0 ? String(number) : t('Sin comprobar');
        const observedDate = (source, value) => source?.state === 'unknown' ? t('Sin comprobar') : date(value);
        const queueDate = value => queue.state === 'unknown' || queue.timingKnown !== true ? t('Sin comprobar') : queue.pending === 0 ? t('No hay pendientes') : date(value);
        container.innerHTML = `<article class="operations-health-card"><h3>${h(t('Proceso programado'))}</h3>${badge(state, tone)}
            ${metric('Último inicio', observedDate(scheduler, scheduler.lastStartedAt))}${metric('Última finalización', observedDate(scheduler, scheduler.lastFinishedAt))}
            <p class="form-note">${h(t('El horario externo no está confirmado; se muestra la actividad observada.'))}</p></article>
            <article class="operations-health-card"><h3>${h(t('Webhooks'))}</h3>${metric('Último webhook aceptado', observedDate(webhook, webhook.lastAcceptedAt))}
            <p class="form-note">${h(t('Aceptado indica recepción autorizada, no un cambio aplicado.'))}</p></article>
            <article class="operations-health-card"><h3>${h(t('Cola de tareas'))}</h3><div class="operations-counts">${metric('Pendientes', count(queue.pending))}${metric('En ejecución', count(queue.running))}${metric('Con error', count(queue.failed))}</div>
            ${metric('Pendiente más antiguo', queueDate(queue.oldestPendingAt))}${metric('Próximo reintento previsto', queueDate(queue.nextDueAt))}<p class="form-note">${h(t('El próximo reintento depende del proceso programado y de los bloqueos del servicio.'))}</p></article>
            <article class="operations-health-card"><h3>${h(t('Caché y mantenimiento'))}</h3>${metric('Última caché', observedDate(cache, cache.lastFullRefreshAt))}
            ${badge(health.maintenance?.state === 'recorded' ? 'Mantenimiento registrado' : health.maintenance?.active === false ? 'Sin mantenimiento registrado' : 'Sin comprobar')}
            <p class="form-note">${h(t('Esta consulta no ejecuta trabajos ni actualiza la caché.'))}</p></article>`;
    }
    function scheduleHealth() {
        root.clearTimeout(timer);
        if (view === 'system' && root.document.visibilityState !== 'hidden' && !mutations) timer = root.setTimeout(refreshHealth, 30000);
    }
    async function refreshHealth() {
        if (!mounted || view !== 'system' || healthBusy || mutations || root.document.visibilityState === 'hidden') return;
        healthBusy = true; const startedEpoch = epoch; status('automation-health-status', t('Consultando…'));
        try { const response = accepted(await api.getAutomationHealth()); if (epoch === startedEpoch) { health = response; renderHealth(); status('automation-health-status', t('Consultado: {date}', { date: date(health.checkedAt) })); } }
        catch (_) { status('automation-health-status', t('No se pudo actualizar. Se conserva la última lectura.')); }
        finally { healthBusy = false; if (epoch !== startedEpoch && !mutations) root.setTimeout(refreshHealth, 0); else scheduleHealth(); }
    }
    function renderSearch() {
        const container = byId('service-diagnostic-results'); if (!container || !search) return;
        container.replaceChildren();
        for (const item of (Array.isArray(search.items) ? search.items : []).slice(0, 30)) {
            const service = recordId(item); if (!service) continue;
            const entry = button('Servicio #{id}', () => openService(service));
            entry.textContent = [t('Servicio #{id}', { id: service }), text(item.client_name), text(item.username), text(item.address)].filter(Boolean).join(' · ');
            container.appendChild(entry);
        }
    }
    async function findServices(event) {
        event?.preventDefault();
        const query = byId('service-diagnostic-query').value.trim(); if (!query || query.length > 160) return;
        const sequence = ++searchSequence; status('service-diagnostic-status', t('Consultando…'));
        try {
            const response = accepted(await api.searchServiceDiagnostics(query)); if (sequence !== searchSequence) return;
            search = response; renderSearch();
            status('service-diagnostic-status', t(response.available === false ? 'No se pudo completar la consulta.' : !response.items?.length ? 'Sin resultados en los registros locales. Puedes consultar un ID de servicio directamente.' : response.has_more ? 'Hay más resultados; precisa la búsqueda.' : 'Selecciona un servicio para consultar UISP y el router.'));
            if (/^[1-9]\d*$/.test(query) && id(query)) await inspect(Number(query));
        } catch (_) { if (sequence === searchSequence) status('service-diagnostic-status', t('No se pudo completar la consulta.')); }
    }
    function sourceCaption(source) {
        const available = source?.available === true;
        const origin = { queued: 'Previsto por un trabajo pendiente', applied: 'Último estado aplicado', cache: 'Datos de la caché' }[source?.source];
        return badge(available ? 'Consultado' : 'Sin comprobar', available ? 'success' : 'neutral') + `<small>${h(date(source?.checked_at))}</small>`
            + (origin ? `<small>${h(t(origin))}</small>` : '') + (sourceReasons[source?.reason] ? `<small>${h(t(sourceReasons[source.reason]))}</small>` : '');
    }
    function renderDetail() {
        const container = byId('service-diagnostic-detail'); if (!container || !detail) return;
        const service = recordId(detail); if (!service) return;
        container.style.display = 'block';
        const rows = (Array.isArray(detail.comparisons) ? detail.comparisons : []).filter(row => Object.hasOwn(fields, row.field)).slice(0, 12);
        container.innerHTML = `<div class="conflicts-heading"><div><h2>${h(t('Servicio #{id}', { id: service }))} · ${h(text(detail.client_name))}</h2><p class="form-note">${h(t('Consultado: {date}', { date: date(detail.checked_at) }))}</p></div><div class="operations-stored-verification"><small>${h(t('Comprobación guardada: {date}', { date: date(detail.verification?.checked_at) }))}</small>${verificationBadge(detail.verification)}</div></div>
            <div class="operations-source-status"><div><strong>UISP</strong>${sourceCaption(detail.uisp)}</div><div><strong>${h(t('Previsto por el plugin'))}</strong>${sourceCaption(detail.expected)}</div><div><strong>RouterOS</strong>${sourceCaption(detail.router)}</div></div>
            <div class="operations-comparison-scroll"><table class="operations-comparison"><thead><tr><th>${h(t('Campo'))}</th><th>UISP</th><th>${h(t('Previsto por el plugin'))}</th><th>RouterOS</th></tr></thead><tbody>${rows.map(row => `<tr><th>${h(t(fields[row.field]))}</th><td>${h(value(row.uisp, row.field))}</td><td class="${row.matches_uisp === false ? 'operations-difference' : ''}">${h(value(row.expected, row.field))}${row.matches_uisp === false ? `<small>${h(t('Diferencia detectada'))}</small>` : ''}</td><td class="${row.matches_router === false ? 'operations-difference' : ''}">${h(value(row.router, row.field))}${row.matches_router === false ? `<small>${h(t('Diferencia detectada'))}</small>` : ''}</td></tr>`).join('')}</tbody></table></div>
            <p class="form-note">${h(t('Sin comprobar significa que falta evidencia; no equivale a que el servicio esté correcto.'))}</p>${verificationChecks(detail.verification)}`;
        const controls = root.document.createElement('div'); controls.className = 'operations-actions';
        controls.appendChild(button('Actualizar diagnóstico', () => inspect(service)));
        if (detail.verification && !['missing', 'not_applicable', 'superseded'].includes(detail.verification.state)) {
            const check = button('Verificar de nuevo', () => recheck(service)); check.disabled = rechecking.has(service) || mutations > 0; controls.appendChild(check);
        }
        container.appendChild(controls);
        const jobs = root.document.createElement('section'); jobs.className = 'operations-history';
        jobs.innerHTML = `<h3>${h(t('Trabajos del servicio'))}</h3>`;
        const entries = Array.isArray(detail.jobs?.items) ? detail.jobs.items.slice(0, 30) : [];
        const states = { pending: 'Pendiente', running: 'En ejecución', succeeded: 'Completado', cancelled: 'Cancelado', superseded: 'Sustituido', cleaned: 'Limpiado' };
        if (!entries.length) jobs.innerHTML += `<p class="form-note">${h(t(detail.jobs?.available === false ? 'No se pudo completar la consulta.' : 'No hay trabajos registrados.'))}</p>`;
        for (const job of entries) {
            const jobKey = text(job.job_id ?? job.jobId);
            const jobState = job.state ?? job.status;
            const row = root.document.createElement('p'); row.className = 'operations-history-row';
            const parts = [jobKey, t(states[jobState] || 'Estado desconocido'), date(job.updated_at ?? job.updated ?? job.created_at ?? job.created)];
            if (Number.isSafeInteger(job.attempts) && job.attempts >= 0) parts.push(t('Intentos: {count}', { count: job.attempts }));
            if (jobState === 'pending' && typeof job.next_attempt === 'string' && !Number.isNaN(new Date(job.next_attempt).getTime()))
                parts.push(t('Próximo intento') + ': ' + date(job.next_attempt));
            const errors = new Set(['invalid_network_address', 'router_ownership_conflict', 'router_ownership_record_changed',
                'site_address_identity_changed', 'site_address_device_changed', 'site_address_resources_changed',
                'site_address_prepared_guard_changed', 'queue_busy', 'device_busy', 'provisioning_cancelled', 'operation_failed']);
            if (errors.has(job.error_code)) parts.push(t('Con error') + ' · ' + t('Revisa Trabajos y el registro del plugin.'));
            row.textContent = parts.filter(Boolean).join(' · ');
            if (typeof reviewJob === 'function' && ['pending', 'running'].includes(jobState) && /^Q[1-9]\d{0,14}$/.test(jobKey))
                row.appendChild(button('Revisar trabajo', () => reviewJob(jobKey, service)));
            jobs.appendChild(row);
        }
        if (detail.jobs?.has_more) {
            const more = root.document.createElement('p'); more.className = 'form-note'; more.textContent = t('Se muestran los resultados más recientes.'); jobs.appendChild(more);
        }
        container.appendChild(jobs);
        const history = detail.history || {};
        const knownCount = count => Number.isSafeInteger(count) && count >= 0;
        if (knownCount(history.recovery_count) || knownCount(history.retained_count)) {
            const retained = root.document.createElement('section'); retained.className = 'operations-history';
            retained.innerHTML = `<h3>${h(t('Historial local'))}</h3>`;
            const counts = [];
            if (knownCount(history.recovery_count)) counts.push(t('Registros de recuperación: {count}', { count: history.recovery_count }));
            if (knownCount(history.retained_count)) counts.push(t('Registros informativos conservados: {count}', { count: history.retained_count }));
            const note = root.document.createElement('p'); note.className = 'form-note';
            note.textContent = counts.join(' · ') + '. ' + t('Estos registros no son trabajos ejecutables.');
            retained.appendChild(note); container.appendChild(retained);
        }
        if (Array.isArray(detail.issues) && detail.issues.length) {
            const note = root.document.createElement('p'); note.className = 'operations-issues'; note.textContent = t('La revisión contiene datos sin comprobar o diferencias. Revisa las columnas antes de aplicar cambios.'); container.appendChild(note);
        }
    }
    async function inspect(service) {
        if (!id(service)) return; selected = Number(service); const sequence = ++detailSequence;
        detail = null; byId('service-diagnostic-detail').style.display = 'none'; status('service-diagnostic-status', t('Consultando UISP y router…'));
        try {
            const response = accepted(await api.inspectService(selected)); if (sequence !== detailSequence) return;
            if (recordId(response) !== selected) throw new Error('wrong_service');
            detail = response; renderDetail(); status('service-diagnostic-status', '');
        } catch (_) { if (sequence === detailSequence) status('service-diagnostic-status', t('No se pudo completar la consulta.')); }
    }
    function openService(service) {
        if (!mounted || !id(service)) return;
        navigate('diagnostics'); byId('service-diagnostic-query').value = String(service); return inspect(Number(service));
    }
    async function recheck(service) {
        if (!id(service) || rechecking.has(service) || mutations) return;
        rechecking.add(service); resultsFeedback = t('Consultando…'); renderResults(); renderDetail();
        try {
            const response = accepted(await api.recheckProvisioning(service));
            if (recordId(response) !== service) throw new Error('wrong_service');
            if (Array.isArray(results?.items)) results.items = results.items.map(item => recordId(item) === service ? response : item);
            if (recordId(detail) === service) detail.verification = response;
            resultsFeedback = verificationLabel(response.state); status('provisioning-results-status', resultsFeedback);
            if (selected === service) status('service-diagnostic-status', verificationLabel(response.state));
        } catch (_) {
            resultsFeedback = t('No se pudo completar la consulta.');
            status(view === 'diagnostics' ? 'service-diagnostic-status' : 'provisioning-results-status', t('No se pudo completar la consulta.'));
        } finally { rechecking.delete(service); renderResults(); renderDetail(); }
    }
    function onView(next) {
        view = next; root.clearTimeout(timer);
        if (view === 'system') refreshHealth();
        if (view === 'jobs') refreshResults();
    }
    function mount(options) {
        if (mounted) return; api = options.api; navigate = options.navigate; reviewJob = options.reviewJob; mounted = true;
        byId('service-diagnostic-search')?.addEventListener('submit', findServices);
        byId('refresh-automation-health')?.addEventListener('click', refreshHealth);
        byId('refresh-provisioning-results')?.addEventListener('click', refreshResults);
        api.onMutation?.(active => { epoch++; mutations = Math.max(0, mutations + (active ? 1 : -1)); root.clearTimeout(timer);
            if (!mutations) { if (view === 'system') refreshHealth(); if (view === 'jobs') refreshResults(); }
        });
        root.document.addEventListener('visibilitychange', () => {
            root.clearTimeout(timer); if (root.document.visibilityState !== 'hidden') { if (view === 'system') refreshHealth(); if (view === 'jobs') refreshResults(); }
        });
        root.PanelI18n?.onChange(() => { renderHealth(); renderResults(); renderSearch(); renderDetail(); });
    }
    root.PanelOperations = { mount, onView, openService, refreshResults, refreshHealth, verificationLabel,
        jobsUpdated() { if (Date.now() - lastResultsAt > 10000) refreshResults(); } };
})(globalThis);
