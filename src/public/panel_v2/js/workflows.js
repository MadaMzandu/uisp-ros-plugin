/* Explicit administrative workflows. Observations never run provisioning jobs. */
(function (root) {
    'use strict';
    const node = id => root.document.getElementById(id);
    const t = (key, values = {}) => root.PanelI18n?.t(key, values) ?? key.replace(/\{(\w+)\}/g, (all, key) => values[key] ?? all);
    const h = value => PanelUtils.escapeHTML(value);
    const number = value => Number.isSafeInteger(value) && value >= 0 ? value : null;
    const positive = value => (typeof value === 'number' || typeof value === 'string' && /^[1-9]\d{0,15}$/.test(value)) && Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : null;
    const text = value => typeof value === 'string' ? value.slice(0, 512) : typeof value === 'number' && Number.isFinite(value) ? String(value) : '';
    const count = value => number(value) === null ? t('Sin comprobar') : String(value);
    const decimal = value => typeof value === 'string' && /^\d{1,48}$/.test(value) ? value : t('Sin comprobar');
    const token = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value) ? value : null;
    const backupName = value => typeof value === 'string' && /^full-backup-\d{8}T\d{6}Z-[a-f0-9]{32}\.zip$/.test(value) ? value : null;
    const fields = { username: 'Usuario', site: 'Site', address: 'IPv4', address6: 'IPv6', profile: 'Perfil', status: 'Estado', ownership: 'Propiedad', plan_id: 'Plan' };
    const checkFields = new Set(['name', 'comment', 'profile', 'remote-address', 'remote-ipv6-prefix', 'address', 'mac-address', 'duid', 'iaid', 'disabled', 'caller-id', 'rate-limit', 'address-list', 'address-pool', 'local-address', 'parent-queue', 'target', 'max-limit', 'limit-at', 'parent', 'burst-limit', 'burst-threshold', 'burst-time', 'priority']);
    const reasons = {
        differences_observed: 'Diferencias detectadas', correction_queued: 'Corrección programada', inspection_unavailable: 'No se pudo completar la consulta.',
        identity_unverified: 'No se pudo confirmar la identidad de la cuenta.', account_absent: 'La cuenta no está presente en el router consultado.',
        identity_changed: 'La identidad cambió durante la consulta.', terminal_requires_review: 'El servicio terminado requiere revisión.',
        service_busy: 'El servicio tiene una operación pendiente.', state_changed: 'El estado cambió; vuelve a comprobar.',
        queue_unavailable: 'No se pudo consultar la cola.', configuration_unavailable: 'Falta la configuración necesaria para consultar el servicio.',
        automatic_correction_disabled: 'La corrección automática está desactivada.', correction_unavailable: 'No se pudo programar la corrección.',
        observation_limit: 'La consulta alcanzó su límite; vuelve a comprobar.', plan_metadata_unavailable: 'No se pudo comprobar el plan.',
        foreign_site_address: 'La IP pertenece a otro Site.', unchanged: 'Sin cambios', blocked: 'Requiere revisión',
        service_unavailable: 'No se pudo consultar UISP.', router_unavailable: 'No se pudo consultar el router.',
        sync_preview_address_conflict: 'La IP pertenece a otro servicio. Revisa su reserva desde Trabajos.',
        sync_preview_busy: 'El servicio tiene una operación pendiente.',
        sync_preview_catalog_unavailable: 'No se pudo consultar UISP.',
        sync_preview_cleanup_unconfirmed: 'No se pudieron comprobar los recursos del router anterior.',
        sync_preview_client_unconfirmed: 'No se pudo confirmar el cliente vigente en UISP.',
        sync_preview_configuration_missing: 'Falta la configuración necesaria para consultar el servicio.',
        sync_preview_enqueue_unconfirmed: 'No se pudo confirmar toda la programación. Revisa la cola antes de volver a aplicar.',
        sync_preview_expired: 'La vista previa caducó. Vuelve a revisar el equipo.',
        sync_preview_foreign_site_address: 'La IP pertenece a otro Site.',
        sync_preview_identity_ambiguous: 'No se pudo identificar una única cuenta en el router.',
        sync_preview_identity_unconfirmed: 'No se pudo confirmar la identidad de la cuenta.',
        sync_preview_incomplete: 'No se pudo completar la vista previa. Vuelve a revisar el equipo.',
        sync_preview_limit: 'La consulta alcanzó su límite; vuelve a comprobar.',
        sync_preview_local_address_unavailable: 'No se pudo comprobar una IP disponible para el servicio.',
        sync_preview_observation_unavailable: 'No se pudo completar la consulta.',
        sync_preview_ownership_unconfirmed: 'No se pudo confirmar la identidad de la cuenta.',
        sync_preview_pending_work: 'El servicio tiene una operación pendiente.',
        sync_preview_plan_unavailable: 'No se pudo comprobar el plan.',
        sync_preview_queue_unavailable: 'No se pudo consultar la cola.',
        sync_preview_router_ambiguous: 'El Site no identifica un único router configurado.',
        sync_preview_router_unavailable: 'No se pudo consultar el router.',
        sync_preview_service_inactive: 'El servicio terminado requiere revisión.',
        sync_preview_session_limit: 'La consulta alcanzó su límite; vuelve a comprobar.',
        sync_preview_site_changed: 'El estado cambió; vuelve a comprobar.',
        sync_preview_source_router_changed: 'La configuración del router cambió durante la consulta.',
        sync_preview_state_changed: 'El estado cambió; vuelve a comprobar.',
        sync_preview_storage_unavailable: 'No se pudo completar la consulta.',
        sync_preview_timeout: 'La consulta alcanzó su límite; vuelve a comprobar.'
    };
    let api, notify, navigate, reviewJob, onApplied, mounted = false, view = 'devices', epoch = 0, mutations = 0, writeBusy = false, requiresReload = false;
    let capacity = null, reconciliation = null, backups = null, capacityBusy = false, reconciliationBusy = false, backupsBusy = false;
    let reconciliationDraft = null, reconciliationDirty = false;
    let syncSequence = 0, syncOpen = false, syncReading = false, syncApplying = false, syncCancelled = false, syncData = null, syncItems = new Map(), syncFeedback = null;
    let syncOutcomes = null, syncDeviceId = null, syncVisibleLimit = 100;
    let restoreSequence = 0, restoreOpen = false, restoreLoading = false, restoreApplying = false, restoreData = null, restoreFeedback = null, restoreBeforeName = null;
    let backupFeedback = null;
    const locked = () => requiresReload || writeBusy || mutations > 0;
    const accepted = response => {
        if (!response || response.error || response.status !== 'ok' || !response.data || typeof response.data !== 'object' || Array.isArray(response.data)) throw new Error('unavailable');
        return response.data;
    };
    const status = (id, key, values = {}) => { if (node(id)) node(id).textContent = key ? t(key, values) : ''; };
    const date = value => {
        if (typeof value !== 'string' || !value) return t('Sin observaciones');
        const parsed = new Date(value); return Number.isNaN(parsed.getTime()) ? t('Sin comprobar') : parsed.toLocaleString(root.PanelI18n?.getLanguage());
    };
    function button(key, handler, style = 'secondary') {
        const result = root.document.createElement('button'); result.type = 'button'; result.className = 'btn btn-' + style; result.textContent = t(key);
        result.addEventListener('click', handler); return result;
    }
    function link(label, href) { const result = root.document.createElement('a'); result.textContent = label; result.href = href; result.target = '_blank'; result.rel = 'noopener noreferrer'; return result; }
    function serviceLink(service) { return link(t('Servicio #{id}', { id: service }), '/crm/client/service/' + service); }
    function controlState() {
        for (const id of ['save-service-reconciliation', 'create-full-backup', 'upload-full-backup']) if (node(id)) node(id).disabled = locked();
        for (const id of ['service-reconciliation-enabled', 'service-reconciliation-auto']) if (node(id)) node(id).disabled = locked();
        const reviewed = syncOpen && !syncReading && !syncApplying && token(syncData?.previewToken) && syncData?.done === true;
        if (node('sync-preview-apply')) node('sync-preview-apply').disabled = locked() || !reviewed || ![...syncItems.values()].some(item => item.state === 'changes');
        if (node('sync-preview-reconnect')) node('sync-preview-reconnect').disabled = locked() || !reviewed || ![...syncItems.values()].some(item => item.state === 'changes' || item.state === 'unchanged');
        if (node('apply-full-restore')) node('apply-full-restore').disabled = locked() || !restoreOpen || restoreLoading || restoreApplying || !token(restoreData?.previewToken) || node('full-restore-confirm')?.checked !== true;
        if (node('full-restore-confirm')) node('full-restore-confirm').disabled = locked() || restoreLoading || !token(restoreData?.previewToken);
        if (node('reload-after-full-restore')) node('reload-after-full-restore').style.display = requiresReload ? '' : 'none';
    }
    function download(container, name, key = 'Descargar') {
        if (!backupName(name)) return;
        try { const result = link(t(key), api.downloadUrl(name)); result.className = 'btn btn-secondary'; result.download = name; container.appendChild(result); } catch (_) { /* Reject an invalid local endpoint. */ }
    }
    function renderCapacity() {
        const list = node('pool-capacity-list'); if (!list) return; list.replaceChildren();
        if (!capacity) { status('pool-capacity-status', 'Consulta la capacidad con Refrescar.'); return; }
        if (capacity.available !== true) { status('pool-capacity-status', 'No se pudo completar la consulta.'); return; }
        status('pool-capacity-status', number(capacity.invalid_records) > 0 ? 'Hay registros inválidos sin contabilizar; revisa el diagnóstico antes de asignar.' : 'Última consulta: {date}', { date: date(capacity.checked_at) });
        for (const item of (Array.isArray(capacity.items) ? capacity.items.slice(0, 256) : [])) {
            const card = root.document.createElement('article'); card.className = 'operations-result-card';
            const family = ['IPv4', 'IPv6'].includes(item.family) ? item.family : t('Sin comprobar');
            card.innerHTML = `<strong>${h(text(item.site) || t(item.scope === 'global_ppp' ? 'Pool PPP global' : 'Sin comprobar'))} · ${h(family)}</strong><p>${h(text(item.pool))}</p>`;
            if (item.available === true) {
                card.innerHTML += `<dl>${[['Total', 'total'], ['Asignadas', 'assigned'], ['Retenidas', 'retained'], ['Excluidas', 'excluded'], ['Libres', 'free']].map(([label, key]) => `<div><dt>${h(t(label))}</dt><dd>${h(decimal(item[key]))}</dd></div>`).join('')}</dl>`;
                if (family === 'IPv6' && number(item.unit_prefix) !== null) card.innerHTML += `<small>${h(t('Unidad: /{prefix}', { prefix: item.unit_prefix }))}</small>`;
                if (item.warning === 'low' || item.warning === 'exhausted') card.innerHTML += `<p class="form-note">${h(t(item.warning === 'exhausted' ? 'Pool agotado' : 'Quedan pocas direcciones libres'))}</p>`;
            } else card.innerHTML += `<p>${h(t('Sin comprobar'))}</p>`;
            if (item.shared_pool === true) card.innerHTML += `<p class="form-note">${h(t('Pool compartido: los valores se solapan con otro router.'))}</p>`;
            list.appendChild(card);
        }
        if (!list.children.length) status('pool-capacity-status', 'No hay pools configurados.');
    }
    async function refreshCapacity() {
        if (!mounted || view !== 'devices' || capacityBusy || mutations || writeBusy) return;
        capacityBusy = true; const generation = epoch; status('pool-capacity-status', 'Consultando…');
        try { const result = accepted(await api.getPoolCapacity()); if (view === 'devices' && epoch === generation) { capacity = result; renderCapacity(); } }
        catch (_) { if (epoch === generation) status('pool-capacity-status', 'No se pudo actualizar. Se conserva la última lectura.'); }
        finally { capacityBusy = false; }
    }
    function renderReconciliation() {
        const list = node('service-reconciliation-list'); if (!list) return; list.replaceChildren();
        if (reconciliationDraft) { node('service-reconciliation-enabled').checked = reconciliationDraft.enabled; node('service-reconciliation-auto').checked = reconciliationDraft.auto; }
        if (!reconciliation) return;
        if (reconciliation.available !== true) { status('service-reconciliation-status', 'No se pudo completar la consulta.'); return; }
        status('service-reconciliation-status', reconciliation.state === 'never_observed' ? 'Sin observaciones' : 'Última consulta: {date}', { date: date(reconciliation.last_checked_at) });
        for (const item of (Array.isArray(reconciliation.items) ? reconciliation.items.slice(0, 50) : [])) {
            const service = positive(item.service_id); if (!service) continue;
            const card = root.document.createElement('article'); card.className = 'operations-result-card'; card.appendChild(serviceLink(service));
            const description = root.document.createElement('p'); description.textContent = t(reasons[item.reason] || 'Requiere revisión'); card.appendChild(description);
            const safeFields = (Array.isArray(item.fields) ? item.fields : []).filter(field => Object.hasOwn(fields, field));
            if (safeFields.length) { const detail = root.document.createElement('small'); detail.textContent = safeFields.map(field => t(fields[field])).join(' · '); card.appendChild(detail); }
            if (navigate) card.appendChild(button('Diagnóstico por servicio', () => { navigate('diagnostics', service); }));
            list.appendChild(card);
        }
        if (!list.children.length && reconciliation.state !== 'never_observed') status('service-reconciliation-status', 'Sin incidencias registradas.');
        controlState();
    }
    async function refreshReconciliation() {
        if (!mounted || view !== 'system' || reconciliationBusy || mutations || writeBusy) return;
        reconciliationBusy = true; const generation = epoch; status('service-reconciliation-status', 'Consultando…');
        try {
            const result = accepted(await api.getServiceReconciliation());
            if (view === 'system' && epoch === generation) {
                reconciliation = result;
                if (!reconciliationDirty && typeof result.enabled === 'boolean' && typeof result.auto_apply === 'boolean') reconciliationDraft = { enabled: result.enabled, auto: result.auto_apply };
                renderReconciliation();
            }
        } catch (_) { if (epoch === generation) status('service-reconciliation-status', 'No se pudo actualizar. Se conserva la última lectura.'); }
        finally { reconciliationBusy = false; }
    }
    async function saveReconciliation() {
        if (locked() || !reconciliationDraft) return;
        const draft = { ...reconciliationDraft }; writeBusy = true; controlState(); status('service-reconciliation-status', 'Guardando…');
        try {
            accepted(await api.saveConfig({ service_reconciliation_enabled: draft.enabled, service_reconciliation_auto_apply: draft.auto }));
            reconciliationDirty = false; status('service-reconciliation-status', 'Cambios guardados'); notify(t('Cambios guardados'), 'success');
        } catch (_) { status('service-reconciliation-status', 'No se pudo guardar. Se conservan los cambios sin guardar.'); }
        finally { writeBusy = false; controlState(); }
    }
    function renderBackups() {
        const list = node('full-backup-list'); if (!list) return; list.replaceChildren();
        if (backupFeedback) status('full-backup-status', backupFeedback);
        else if (backups?.available === false) status('full-backup-status', 'No se pudo completar la consulta.');
        else status('full-backup-status', backups ? 'Los respaldos completos contienen credenciales. Guárdalos en un lugar seguro.' : '');
        for (const item of (Array.isArray(backups?.items) ? backups.items.slice(0, 100) : [])) {
            const name = backupName(item.name); if (!name) continue;
            const card = root.document.createElement('article'); card.className = 'operations-result-card';
            card.innerHTML = `<strong>${h(date(item.createdAt))}</strong><small>${h(name)}</small><p>${h(t('Tamaño: {bytes} bytes', { bytes: count(item.bytes) }))}</p>`;
            download(card, name); const review = button('Revisar restauración completa', () => previewRestore(name)); review.disabled = locked(); card.appendChild(review); list.appendChild(card);
        }
        if (backups?.available === true && !list.children.length && !backupFeedback) status('full-backup-status', 'No hay respaldos completos.');
        if (backups?.hasMore === true) { const note = root.document.createElement('p'); note.textContent = t('Se muestran los resultados más recientes.'); list.appendChild(note); }
    }
    async function refreshBackups() {
        if (!mounted || view !== 'system' || backupsBusy || mutations || writeBusy) return;
        backupsBusy = true; const generation = epoch; status('full-backup-status', 'Consultando…');
        try { const result = accepted(await api.getFullBackups()); if (view === 'system' && epoch === generation) { backups = result; backupFeedback = null; renderBackups(); } }
        catch (_) { if (epoch === generation) status('full-backup-status', 'No se pudo actualizar. Se conserva la última lectura.'); }
        finally { backupsBusy = false; }
    }
    async function createBackup() {
        if (locked()) return; writeBusy = true; controlState(); status('full-backup-status', 'Creando respaldo…');
        try {
            const result = accepted(await api.createFullBackup()); if (!backupName(result.name)) throw new Error('invalid');
            backups = { available: true, items: [{ name: result.name, bytes: result.bytes, createdAt: result.createdAt }, ...(Array.isArray(backups?.items) ? backups.items.filter(item => item.name !== result.name) : [])] };
            backupFeedback = 'Respaldo completo creado.'; renderBackups();
        } catch (_) { backupFeedback = 'No se pudo crear el respaldo.'; status('full-backup-status', backupFeedback); }
        finally { writeBusy = false; controlState(); renderBackups(); }
    }
    function closeRestore() {
        if (restoreApplying) return;
        restoreOpen = false; restoreSequence++; restoreData = null; restoreFeedback = null;
        if (node('full-restore-modal')) node('full-restore-modal').style.display = 'none';
        if (node('full-restore-confirm')) node('full-restore-confirm').checked = false; controlState();
    }
    function summaryHtml(summary) {
        const labels = { devices: 'Equipos', services: 'Servicios', pendingJobs: 'Trabajos pendientes', appliedServices: 'Servicios aplicados', recoveries: 'Recuperaciones', retainedRecords: 'Registros conservados' };
        return `<dl>${Object.entries(labels).map(([key, label]) => `<div><dt>${h(t(label))}</dt><dd>${h(count(summary?.[key]))}</dd></div>`).join('')}</dl>`;
    }
    function renderRestore() {
        const summary = node('full-restore-summary'); if (!summary) return; summary.replaceChildren();
        if (restoreData) summary.innerHTML = `<article><h4>${h(t('Respaldo seleccionado'))}</h4><p>${h(date(restoreData.candidate?.createdAt))}</p>${summaryHtml(restoreData.candidate)}</article><article><h4>${h(t('Datos actuales'))}</h4>${summaryHtml(restoreData.current)}</article>`;
        if (restoreBeforeName) download(summary, restoreBeforeName, 'Descargar respaldo anterior a la restauración');
        status('full-restore-status', restoreFeedback || (restoreLoading ? 'Consultando…' : 'Revisa los datos antes de restaurar.')); controlState();
    }
    async function previewRestore(name, file) {
        if (locked() || restoreLoading || restoreApplying || (!file && !backupName(name))) return;
        const sequence = ++restoreSequence; restoreOpen = true; restoreLoading = true; restoreData = null; restoreFeedback = null; restoreBeforeName = null;
        node('full-restore-modal').style.display = 'flex'; node('full-restore-confirm').checked = false; renderRestore();
        try {
            const result = accepted(await (file ? api.uploadFullBackup(file) : api.previewFullBackup(name)));
            if (!token(result.previewToken) || !result.candidate || !result.current) throw new Error('invalid');
            if (restoreOpen && sequence === restoreSequence) { restoreData = result; restoreLoading = false; renderRestore(); }
        } catch (_) { if (restoreOpen && sequence === restoreSequence) { restoreFeedback = 'No se pudo revisar el respaldo. Vuelve a seleccionarlo.'; restoreLoading = false; renderRestore(); } }
        finally { if (sequence === restoreSequence) { restoreLoading = false; controlState(); } else restoreLoading = false; }
    }
    async function restoreBackup() {
        if (locked() || restoreLoading || restoreApplying || !restoreOpen || node('full-restore-confirm')?.checked !== true) return;
        const previewToken = token(restoreData?.previewToken); if (!previewToken) return;
        // A failed or disconnected response can follow a completed restore. Never replay this authorization.
        restoreData = null; restoreApplying = true; writeBusy = true; node('full-restore-confirm').checked = false;
        restoreFeedback = 'Restaurando…'; renderRestore();
        try {
            const result = accepted(await api.restoreFullBackup(previewToken));
            if (result.restored !== true || result.requiresReload !== true) throw new Error('invalid');
            requiresReload = true; restoreBeforeName = backupName(result.backupName); restoreFeedback = 'Restauración completada. Recarga el panel antes de hacer cambios.'; renderRestore();
            notify(t(restoreFeedback), 'success'); if (typeof onApplied === 'function') onApplied({ requiresReload: true });
        } catch (_) {
            requiresReload = true; restoreFeedback = 'No se pudo confirmar la restauración. Recarga el panel y revisa de nuevo antes de intentarlo.'; renderRestore();
            if (typeof onApplied === 'function') onApplied({ requiresReload: true, unconfirmed: true });
        }
        finally { restoreApplying = false; writeBusy = false; controlState(); }
    }
    function closeSync() {
        if (syncApplying) { syncCancelled = true; syncFeedback = { key: 'Se detendrá después de confirmar la solicitud en curso.' }; renderSync(); return; }
        syncOpen = false; syncSequence++; syncData = null; syncItems = new Map(); syncOutcomes = null; if (node('sync-preview-modal')) node('sync-preview-modal').style.display = 'none'; controlState();
    }
    function readSync(result) {
        if (!token(result.previewToken) || number(result.total) === null || number(result.processed) === null || result.processed > result.total || typeof result.done !== 'boolean') throw new Error('invalid');
        for (const item of (Array.isArray(result.items) ? result.items.slice(0, 20000) : [])) {
            const service = positive(item.serviceId); if (!service || !['changes', 'unchanged', 'blocked'].includes(item.state)) continue;
            // Copy only display fields. Arbitrary API properties and credentials never enter the UI model.
            syncItems.set(service, { serviceId: service, clientId: positive(item.clientId), clientName: text(item.clientName), state: item.state,
                reason: Object.hasOwn(reasons, item.reason) ? item.reason : null, oldAddress: text(item.oldAddress), newAddress: text(item.newAddress), oldAddress6: text(item.oldAddress6), newAddress6: text(item.newAddress6),
                sourceDeviceId: positive(item.sourceDeviceId), migration: item.migration === true,
                reconnect: item.reconnect === true, fields: (Array.isArray(item.fields) ? item.fields : []).filter(field => field && typeof field === 'object' && checkFields.has(field.field)).slice(0, 32).map(field => ({ field: field.field, expected: text(field.expected), observed: text(field.observed), state: ['verified', 'mismatch'].includes(field.state) ? field.state : 'unknown' })) });
        }
        syncData = { previewToken: result.previewToken, total: result.total, processed: result.processed, done: result.done, catalogScanned: number(result.catalogScanned), catalogComplete: result.catalogComplete === true };
    }
    function renderSync() {
        const list = node('sync-preview-list'); if (!list) return; list.replaceChildren();
        if (syncFeedback) status('sync-preview-status', syncFeedback.key, syncFeedback.values);
        else status('sync-preview-status', syncData ? 'Revisados: {processed} de {total}' : 'Consultando…', { processed: count(syncData?.processed), total: count(syncData?.total) });
        if (syncOutcomes) { const totals = root.document.createElement('p'); totals.textContent = t('Programados: {queued} · Omitidos: {skipped} · Bloqueados: {blocked} · Sin confirmar: {unconfirmed}', syncOutcomes); list.appendChild(totals); }
        let shown = 0;
        for (const item of syncItems.values()) {
            if (++shown > syncVisibleLimit) break;
            const card = root.document.createElement('article'); card.className = 'operations-result-card'; card.appendChild(serviceLink(item.serviceId));
            if (item.clientId) card.appendChild(link(item.clientName || t('Cliente #{id}', { id: item.clientId }), '/crm/client/' + item.clientId));
            else if (item.clientName) { const name = root.document.createElement('strong'); name.textContent = item.clientName; card.appendChild(name); }
            const label = { changes: 'Diferencias detectadas', unchanged: 'Sin cambios', blocked: 'Requiere revisión' }[item.state];
            const description = root.document.createElement('p'); description.textContent = t(label); card.appendChild(description);
            if (item.reason) { const cause = root.document.createElement('small'); cause.textContent = t(reasons[item.reason]); card.appendChild(cause); }
            for (const [label, oldValue, newValue] of [['IPv4', item.oldAddress, item.newAddress], ['IPv6', item.oldAddress6, item.newAddress6]]) {
                if (!oldValue && !newValue) continue;
                const values = root.document.createElement('p'); values.textContent = t(label) + ': ' + (oldValue || t('Sin comprobar')) + ' → ' + (newValue || t('Sin comprobar')); card.appendChild(values);
            }
            if (item.fields.length) card.innerHTML += `<details><summary>${h(t('Campos comprobados'))}</summary><dl>${item.fields.map(field => `<div><dt>${h(field.field)}</dt><dd>${h(t('Valor esperado'))}: ${h(field.expected || t('Sin comprobar'))}<br>${h(t('Valor observado'))}: ${h(field.observed || t('Sin comprobar'))}</dd></div>`).join('')}</dl></details>`;
            if (item.reconnect) { const note = root.document.createElement('p'); note.textContent = t('La aplicación requiere reconexión.'); card.appendChild(note); }
            if (item.migration) {
                const migration = root.document.createElement('p'); migration.textContent = [t('Traslado entre routers'),
                    item.sourceDeviceId ? t('Router anterior: #{id}', { id: item.sourceDeviceId }) : t('Sin comprobar'),
                    t('Router de destino: #{id}', { id: syncDeviceId }), t('Se retirará la cuenta del router anterior.')].join(' · '); card.appendChild(migration);
            }
            if (item.application) {
                const outcome = root.document.createElement('p'); outcome.textContent = t({ queued: 'Corrección programada', skipped: 'Sin cambios', blocked: 'Requiere revisión', unconfirmed: 'Sin comprobar' }[item.application.state]); card.appendChild(outcome);
                if (item.application.reason) { const cause = root.document.createElement('p'); cause.textContent = t(reasons[item.application.reason]); card.appendChild(cause); }
                for (const job of item.application.jobs) card.appendChild(button('Revisar trabajo', () => { if (typeof reviewJob === 'function') reviewJob(job, item.serviceId); else if (typeof navigate === 'function') navigate('jobs'); }));
            }
            list.appendChild(card);
        }
        if (syncItems.size > syncVisibleLimit) {
            const more = root.document.createElement('p'); more.textContent = t('Se muestran {count} de {total} servicios revisados.', { count: syncVisibleLimit, total: syncItems.size }); list.appendChild(more);
            list.appendChild(button('Mostrar más servicios', () => { syncVisibleLimit += 100; renderSync(); }));
        }
        controlState();
    }
    async function previewSync(device) {
        const deviceId = positive(typeof device === 'object' && device ? device.id : device);
        if (!deviceId || locked() || syncReading || syncApplying || restoreApplying) return;
        const sequence = ++syncSequence; syncOpen = true; syncReading = true; syncCancelled = false; syncData = null; syncItems = new Map(); syncVisibleLimit = 100; syncFeedback = null; syncOutcomes = null; syncDeviceId = deviceId;
        node('sync-preview-modal').style.display = 'flex'; renderSync();
        try {
            let result = accepted(await api.beginSyncPreview(deviceId)); if (!syncOpen || sequence !== syncSequence) return;
            readSync(result); renderSync(); let steps = 0;
            while (!syncData.done && syncOpen && sequence === syncSequence) {
                if (++steps > 21000 || mutations > 0 || requiresReload) throw new Error('limit');
                const previous = syncData.processed, previousCatalog = syncData.catalogScanned, catalogComplete = syncData.catalogComplete, previewToken = syncData.previewToken;
                result = accepted(await api.stepSyncPreview(previewToken)); if (!syncOpen || sequence !== syncSequence) return;
                if (result.previewToken !== previewToken || (!result.done && result.processed <= previous && !(number(result.catalogScanned) !== null && result.catalogScanned > (previousCatalog ?? -1)) && !(result.catalogComplete === true && !catalogComplete))) throw new Error('stalled');
                readSync(result); renderSync();
            }
        } catch (_) { if (syncOpen && sequence === syncSequence) { syncData = null; syncFeedback = { key: 'No se pudo completar la vista previa. Vuelve a revisar el equipo.' }; renderSync(); } }
        finally { syncReading = false; controlState(); }
    }
    async function applySync(mode) {
        if (!['differences', 'force_reconnect'].includes(mode) || locked() || syncReading || syncApplying || !syncOpen || syncData?.done !== true || !token(syncData.previewToken)) return;
        if (![...syncItems.values()].some(item => item.state === 'changes' || mode === 'force_reconnect' && item.state === 'unchanged')) return;
        const sequence = syncSequence, previewToken = syncData.previewToken, total = syncData.total; syncData = null; syncApplying = true; syncCancelled = false; syncOutcomes = { queued: 0, skipped: 0, blocked: 0, unconfirmed: 0 }; writeBusy = true; controlState();
        syncFeedback = { key: 'Programando cambios…' }; renderSync();
        let queued = 0, processed = 0, steps = 0;
        try {
            let done = false;
            while (!done && !syncCancelled && syncOpen && sequence === syncSequence) {
                if (++steps > 21000) throw new Error('limit');
                const result = accepted(await api.applySyncPreview(previewToken, mode));
                if (typeof result.done !== 'boolean' || number(result.processed) === null || result.total !== total || result.processed > total || result.processed < processed || (!result.done && result.processed <= processed)) throw new Error('invalid');
                const service = positive(result.serviceId), jobs = (Array.isArray(result.jobKeys) ? result.jobKeys : []).filter(job => typeof job === 'string' && /^Q[1-9]\d{0,14}$/.test(job)).slice(0, 2);
                if (result.state !== 'complete') {
                    if (!service || !syncItems.has(service) || !Object.hasOwn(syncOutcomes, result.state) || result.state === 'queued' && !jobs.length) throw new Error('invalid');
                    syncOutcomes[result.state]++; syncItems.get(service).application = { state: result.state, jobs, reason: Object.hasOwn(reasons, result.reason) ? result.reason : null };
                } else if (result.done !== true) throw new Error('invalid');
                processed = result.processed; queued = syncOutcomes.queued; done = result.done;
                if (syncOpen && sequence === syncSequence) { syncFeedback = { key: 'Cambios programados: {count}. Se ejecutarán en la siguiente pasada automática.', values: { count: queued } }; renderSync(); }
            }
            if (done || syncCancelled) { notify(t('Cambios programados: {count}. Se ejecutarán en la siguiente pasada automática.', { count: queued }), 'success'); if (typeof onApplied === 'function') onApplied({ queued }); }
        } catch (_) {
            const key = 'No se pudo confirmar toda la programación. Revisa la cola antes de volver a aplicar.';
            if (syncOpen && sequence === syncSequence) { syncFeedback = { key }; renderSync(); } notify(t(key), 'error');
        } finally { syncApplying = false; writeBusy = false; controlState(); }
    }
    function onView(next) {
        if (!mounted) return; if (view !== next) epoch++; view = next;
        if (node('pool-capacity-section')) node('pool-capacity-section').style.display = next === 'devices' ? '' : 'none';
        if (next === 'system') { refreshReconciliation(); refreshBackups(); }
    }
    function mount(options) {
        if (mounted || !options?.api) return; api = options.api; notify = typeof options.notify === 'function' ? options.notify : () => {};
        navigate = options.navigate; reviewJob = options.reviewJob; onApplied = options.onApplied; mounted = true;
        for (const [id, callback] of [['refresh-pool-capacity', refreshCapacity], ['refresh-service-reconciliation', refreshReconciliation], ['save-service-reconciliation', saveReconciliation],
            ['refresh-full-backups', refreshBackups], ['create-full-backup', createBackup], ['apply-full-restore', restoreBackup], ['sync-review-only', closeSync],
            ['sync-preview-apply', () => applySync('differences')], ['sync-preview-reconnect', () => applySync('force_reconnect')]]) node(id)?.addEventListener('click', callback);
        for (const id of ['service-reconciliation-enabled', 'service-reconciliation-auto']) node(id)?.addEventListener('change', () => {
            reconciliationDirty = true; reconciliationDraft = { enabled: node('service-reconciliation-enabled').checked === true, auto: node('service-reconciliation-auto').checked === true };
        });
        node('full-restore-confirm')?.addEventListener('change', controlState);
        node('upload-full-backup')?.addEventListener('click', () => { if (!locked()) node('full-backup-file')?.click(); });
        node('full-backup-file')?.addEventListener('change', () => {
            const file = node('full-backup-file').files?.[0]; node('full-backup-file').value = '';
            if (file && !locked()) previewRestore(null, file);
        });
        for (const [id, close] of [['sync-preview-modal', closeSync], ['full-restore-modal', closeRestore]]) {
            const modal = node(id); for (const control of modal?.querySelectorAll('.close-modal') || []) control.addEventListener('click', close);
            modal?.addEventListener('click', event => { if (event.target === modal) close(); });
        }
        root.document.addEventListener('keydown', event => { if (event.key === 'Escape') { closeSync(); closeRestore(); } });
        api.onMutation?.(active => { mutations = Math.max(0, mutations + (active ? 1 : -1)); controlState(); });
        root.PanelI18n?.onChange(() => { renderCapacity(); renderReconciliation(); renderBackups(); if (syncOpen) renderSync(); if (restoreOpen) renderRestore(); });
        renderCapacity(); controlState();
    }
    function close() { closeSync(); closeRestore(); }
    root.PanelWorkflows = Object.freeze({ mount, onView, previewSync, close });
})(globalThis);
