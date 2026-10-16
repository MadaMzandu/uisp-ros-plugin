/* Reviewed repairs for one service. An uncertain mutation is never replayed. */
(function (root) {
    'use strict';
    const node = id => root.document.getElementById(id);
    const t = (key, values = {}) => root.PanelI18n?.t(key, values) ?? key.replace(/\{(\w+)\}/g, (all, key) => values[key] ?? all);
    const positive = value => Number.isSafeInteger(value) && value > 0;
    const token = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
    const choice = value => typeof value === 'string' && /^(?:[a-f0-9]{32}|[a-f0-9]{64})$/.test(value);
    const safe = value => typeof value === 'string' ? value.slice(0, 512) : typeof value === 'number' && Number.isFinite(value) ? String(value) : '';
    const fields = { ownership: 'Propiedad', comment: 'Comentario', address: 'IPv4', address6: 'IPv6', profile: 'Perfil', status: 'Estado', username: 'Usuario', site: 'Site', duplicates: 'Cuentas duplicadas', duplicate_queue: 'Colas duplicadas', account: 'Cuenta', 'remote-address': 'IPv4', 'remote-ipv6-prefix': 'IPv6', disabled: 'Estado' };
    const reasons = {
        resolution_unavailable: 'No se pudo revisar la resolución. Vuelve a comprobar el servicio.',
        resolution_changed: 'El servicio o la cuenta cambiaron. Actualiza la revisión antes de resolver.',
        resolution_expired: 'La revisión caducó. Actualízala antes de resolver.',
        resolution_ambiguous: 'Selecciona la cuenta que corresponde a este servicio.',
        resolution_foreign_owner: 'La cuenta pertenece a otro servicio vigente. Revisa ambas asignaciones.',
        resolution_client_unconfirmed: 'No se pudo confirmar el cliente vigente en UISP.',
        resolution_router_unavailable: 'No se pudo consultar el router.',
        resolution_busy: 'Hay otra operación en curso. Espera a que termine y verifica de nuevo.',
        resolution_not_applicable: 'No hay cambios comprobables que resolver.',
        resolution_unconfirmed: 'No se pudo confirmar la resolución. Revisa Trabajos antes de intentarlo de nuevo.',
        resolution_configuration_unavailable: 'Falta la configuración necesaria para consultar el servicio.',
        resolution_configuration_changed: 'El servicio o la cuenta cambiaron. Actualiza la revisión antes de resolver.',
        resolution_mapping_unverified: 'No se pudo comprobar el catálogo de atributos de UISP. Verifica que esté disponible antes de reintentar.',
        resolution_service_unverified: 'No se pudo consultar UISP.',
        resolution_service_inactive: 'El servicio terminado requiere revisión.',
        resolution_client_unverified: 'No se pudo confirmar el cliente vigente en UISP.',
        resolution_client_archived: 'Cliente archivado',
        resolution_site_unverified: 'El Site no identifica un único router configurado.',
        resolution_identity_unverified: 'No se pudo confirmar la identidad de la cuenta.',
        resolution_catalog_incomplete: 'No se pudo consultar UISP.',
        resolution_target_changed: 'El servicio o la cuenta cambiaron. Actualiza la revisión antes de resolver.',
        resolution_owner_active: 'La cuenta pertenece a otro servicio vigente. Revisa ambas asignaciones.',
        resolution_local_claim: 'La cuenta pertenece a otro servicio vigente. Revisa ambas asignaciones.',
        resolution_queue_unverified: 'No se pudo consultar la cola.',
        resolution_router_ambiguous: 'No se pudo identificar una única cuenta en el router.',
        resolution_row_invalid: 'No se pudo confirmar la identidad de la cuenta.',
        resolution_marker_unverified: 'No se pudo confirmar la identidad de la cuenta.',
        resolution_manual_confirmation: 'Selecciona la cuenta que corresponde a este servicio.',
        resolution_select_account: 'Selecciona la cuenta que corresponde a este servicio.',
        resolution_selection_required: 'Selecciona la cuenta que corresponde a este servicio.',
        resolution_invalid_selection: 'El servicio o la cuenta cambiaron. Actualiza la revisión antes de resolver.',
        resolution_duplicates_unreviewed: 'Las cuentas duplicadas requieren revisión antes de resolver.',
        resolution_dhcp_server_ambiguous: 'No se pudo identificar un único servidor DHCP para esta cuenta.',
        resolution_derived_unverified: 'No se pudo comprobar la cola asociada a esta cuenta.',
        resolution_derived_ownership_unverified: 'No se pudo comprobar la cola asociada a esta cuenta.',
        resolution_derived_in_use: 'La cola duplicada está en uso por otra cuenta o perfil. Revisa sus dependencias.',
        resolution_read_limit: 'La consulta alcanzó su límite; vuelve a comprobar.',
        resolution_timeout: 'La consulta alcanzó su límite; vuelve a comprobar.',
        resolution_running: 'Hay otra operación en curso. Espera a que termine y verifica de nuevo.',
        resolution_pending_cleanup: 'La baja anterior no se pudo confirmar en el router. Sus reservas se conservan hasta comprobar la limpieza.',
        resolution_backup_unavailable: 'No se pudo crear el respaldo. No se aplicó la resolución.',
        resolution_queue_changed: 'El servicio o la cuenta cambiaron. Actualiza la revisión antes de resolver.',
        resolution_invalid_token: 'La revisión caducó. Actualízala antes de resolver.',
        resolution_storage_unavailable: 'No se pudo revisar la resolución. Vuelve a comprobar el servicio.',
        resolution_write_unconfirmed: 'No se pudo confirmar la resolución. Revisa Trabajos antes de intentarlo de nuevo.',
        resolution_verification_unconfirmed: 'No se pudo confirmar la resolución. Revisa Trabajos antes de intentarlo de nuevo.'
    };
    let api, notify, navigate, reviewJob, onApplied, mounted = false;
    let opened = false, loading = false, applying = false, requiresReload = false, mutations = 0, epoch = 0, sequence = 0;
    let serviceId = null, preview = null, selected = null, outcome = null, feedback = '', manualOnly = false, ownMutationExpected = false;
    let previousFocus = null;
    const locked = () => requiresReload || api?.reloadRequired === true || api?.recovery?.readOnly === true || mutations > 0;
    const reason = code => t(reasons[code] || reasons.resolution_unavailable);
    const accepted = response => {
        if (!response || response.error || response.status !== 'ok' || !response.data || typeof response.data !== 'object' || Array.isArray(response.data)) throw new Error('resolution_unavailable');
        return response.data;
    };
    function element(tag, text, className) {
        const result = root.document.createElement(tag); if (text !== undefined) result.textContent = text; if (className) result.className = className; return result;
    }
    function button(label, work, primary = false) {
        const result = element('button', t(label), 'btn btn-' + (primary ? 'primary' : 'secondary')); result.type = 'button'; result.addEventListener('click', work); return result;
    }
    function crmLink(label, kind, id) {
        const result = element('a', t(label, { id })); result.href = '/crm/client/' + (kind === 'service' ? 'service/' : '') + id;
        result.target = '_blank'; result.rel = 'noopener noreferrer'; return result;
    }
    function requiresSelection() { return preview?.mode === 'select_account' || preview?.autoApply === false && preview.candidates.some(item => item.selectable); }
    function validPreview(value) {
        if (value.serviceId !== serviceId || !['reconcile', 'select_account', 'blocked'].includes(value.mode) || typeof value.eligible !== 'boolean'
            || typeof value.autoApply !== 'boolean' || !Array.isArray(value.candidates) || value.candidates.length > 20 || !Array.isArray(value.changes) || value.changes.length > 40
            || value.clientId !== null && !positive(value.clientId) || value.deviceId !== null && !positive(value.deviceId)
            || value.eligible && (!token(value.previewToken) || typeof value.expiresAt !== 'string' || !Number.isFinite(Date.parse(value.expiresAt)) || Date.parse(value.expiresAt) <= Date.now())
            || value.mode === 'blocked' && value.eligible) throw new Error('resolution_unavailable');
        const seen = new Set();
        const paths = new Set(['ppp/secret', 'ip/hotspot/user', 'ip/dhcp-server/lease', 'ipv6/dhcp-server/binding', 'queue/simple']);
        for (const item of value.candidates) {
            if (!item || typeof item.selectable !== 'boolean' || !choice(item.candidateId) && (item.selectable || item.candidateId != null)
                || choice(item.candidateId) && seen.has(item.candidateId)
                || typeof item.rowId !== 'string' || !/^\*[a-fA-F0-9]{1,16}$/.test(item.rowId)
                || typeof item.path !== 'string' || !paths.has(item.path.replace(/^\//, ''))
                || item.ownerServiceId !== null && !positive(item.ownerServiceId) || item.ownerClientId !== null && !positive(item.ownerClientId)) throw new Error('resolution_unavailable');
            if (choice(item.candidateId)) seen.add(item.candidateId);
        }
        return value;
    }
    function controls() {
        const needsChoice = requiresSelection();
        if (node('service-resolution-apply')) node('service-resolution-apply').disabled = locked() || loading || applying || !preview?.eligible
            || needsChoice && !preview.candidates.some(item => item.candidateId === selected && item.selectable)
            || preview?.eligible && Date.parse(preview.expiresAt) <= Date.now();
        if (node('service-resolution-refresh')) node('service-resolution-refresh').disabled = locked() || loading || applying;
        for (const radio of node('service-resolution-candidates')?.querySelectorAll('input') || []) radio.disabled = locked() || loading || applying || radio.dataset.selectable !== 'true';
    }
    function render() {
        if (!opened) return;
        node('service-resolution-heading').textContent = t('Resolver servicio #{id}', { id: serviceId });
        node('service-resolution-status').textContent = loading ? t('Revisando UISP y la cuenta del router…') : applying ? t('Corrigiendo vínculo y sincronizando…') : feedback;
        const summary = node('service-resolution-summary'), candidates = node('service-resolution-candidates'), changes = node('service-resolution-changes'), result = node('service-resolution-result');
        summary.replaceChildren(); candidates.replaceChildren(); changes.replaceChildren(); result.replaceChildren();
        if (preview) {
            const links = element('p', undefined, 'resolution-identity'); links.appendChild(crmLink('Servicio #{id}', 'service', serviceId));
            if (positive(preview.clientId)) links.appendChild(crmLink('Cliente #{id}', 'client', preview.clientId));
            summary.appendChild(links);
            const label = [safe(preview.clientName), safe(preview.site), positive(preview.deviceId) ? t('Router #{id}', { id: preview.deviceId }) : ''].filter(Boolean).join(' · ');
            if (label) summary.appendChild(element('p', label, 'form-note'));
            if (preview.reason) summary.appendChild(element('p', reason(preview.reason), 'form-note'));
            if (preview.willReconnect === true) summary.appendChild(element('p', t('Se reconectarán las sesiones de esta cuenta.'), 'form-note'));
            for (const change of preview.changes) {
                if (!change || !Object.hasOwn(fields, change.field)) continue;
                const line = element('p', undefined, 'resolution-change'); line.appendChild(element('strong', t(fields[change.field])));
                const after = change.field === 'account' && change.after === 'create' ? t('Crear cuenta') : safe(change.after);
                line.appendChild(element('span', (safe(change.before) || t('Sin comprobar')) + ' → ' + (after || t('Sin comprobar')))); changes.appendChild(line);
            }
            if (requiresSelection()) {
                candidates.appendChild(element('p', t('Selecciona la cuenta que corresponde a este servicio.'), 'form-note'));
                if (preview.candidates.length > 1) candidates.appendChild(element('p', t('Se conservará la cuenta seleccionada y se retirarán las demás cuentas duplicadas mostradas.'), 'form-note'));
            }
            for (const item of preview.candidates) {
                const card = element('label', undefined, 'resolution-candidate');
                if (requiresSelection()) {
                    const radio = element('input'); radio.type = 'radio'; radio.name = 'service-resolution-account'; radio.value = item.candidateId;
                    radio.dataset.selectable = String(item.selectable); radio.checked = selected === item.candidateId;
                    radio.disabled = locked() || loading || applying || !item.selectable;
                    radio.addEventListener('change', () => { if (preview?.candidates.includes(item) && item.selectable && !locked() && !applying) { selected = item.candidateId; controls(); } }); card.appendChild(radio);
                }
                const body = element('span', undefined, 'resolution-candidate-body');
                body.appendChild(element('strong', [safe(item.username), item.rowId].filter(Boolean).join(' · ')));
                body.appendChild(element('small', [safe(item.path), safe(item.address), safe(item.profile)].filter(Boolean).join(' · ')));
                const details = [safe(item.server) ? t('Servidor') + ': ' + safe(item.server) : '',
                    typeof item.disabled === 'boolean' ? t(item.disabled ? 'Deshabilitado' : 'Habilitado') : '',
                    typeof item.dynamic === 'boolean' ? t(item.dynamic ? 'Dinámico' : 'Estático') : ''].filter(Boolean);
                if (details.length) body.appendChild(element('small', details.join(' · ')));
                if (safe(item.comment)) body.appendChild(element('small', safe(item.comment)));
                if (positive(item.ownerServiceId)) body.appendChild(crmLink('Servicio #{id}', 'service', item.ownerServiceId));
                if (positive(item.ownerClientId)) body.appendChild(crmLink('Cliente #{id}', 'client', item.ownerClientId));
                if (item.reason) body.appendChild(element('small', reason(item.reason)));
                card.appendChild(body); candidates.appendChild(card);
            }
        }
        if (outcome) {
            const text = outcome.state === 'resolved' ? t('Resuelto y verificado') : outcome.state === 'pending' ? t('Pendiente; revisa el trabajo del servicio.')
                : outcome.state === 'blocked' ? reason(outcome.reason) : t('No se pudo confirmar la resolución. Revisa Trabajos antes de intentarlo de nuevo.');
            result.appendChild(element('p', text, 'resolution-outcome'));
            for (const key of outcome.jobKeys) result.appendChild(button('Revisar trabajo', () => { close(); if (typeof reviewJob === 'function') reviewJob(key, serviceId); else navigate?.('jobs'); }));
            result.appendChild(button('Actualizar diagnóstico', () => { const sid = serviceId; close(); navigate?.('diagnostics', sid); }));
            if (typeof outcome.backupName === 'string') {
                try { const link = element('a', t('Descargar respaldo'), 'btn btn-secondary'); link.href = api.downloadUrl(outcome.backupName); link.target = '_blank'; link.rel = 'noopener noreferrer'; result.appendChild(link); } catch (_) { /* Only an existing validated backup route may be used. */ }
            }
        }
        node('service-resolution-apply').style.display = preview ? '' : 'none';
        controls();
    }
    async function read(allowAuto) {
        if (!opened || loading || applying || locked()) return;
        const sid = serviceId, started = ++sequence, observedEpoch = epoch; loading = true; preview = null; selected = null; outcome = null; feedback = ''; render();
        try {
            const value = accepted(await api.previewServiceResolution(sid));
            if (!opened || sequence !== started || serviceId !== sid) return;
            if (epoch !== observedEpoch || locked()) throw new Error('resolution_changed');
            preview = validPreview(value); loading = false; render();
            if (allowAuto && !manualOnly && preview.eligible && preview.autoApply && preview.mode === 'reconcile') await apply();
        } catch (error) { if (opened && sequence === started) { preview = null; feedback = reason(error.message); manualOnly = true; } }
        finally { if (sequence === started) { loading = false; render(); } }
    }
    async function apply() {
        if (!opened || loading || applying || locked() || !preview?.eligible || !token(preview.previewToken)) return;
        if (Date.parse(preview.expiresAt) <= Date.now()) { preview = null; feedback = reason('resolution_expired'); manualOnly = true; render(); return; }
        const selection = requiresSelection() ? [selected] : [];
        if (selection.some(key => !choice(key) || !preview.candidates.some(item => item.candidateId === key && item.selectable))) return;
        const sid = serviceId, started = sequence, observedEpoch = epoch, previewToken = preview.previewToken;
        preview = null; selected = null; applying = true; manualOnly = true; ownMutationExpected = true; feedback = ''; render();
        try {
            const value = accepted(await api.applyServiceResolution(previewToken, selection));
            if (value.serviceId !== sid || !['resolved', 'pending', 'unconfirmed', 'blocked'].includes(value.state)
                || !Array.isArray(value.jobKeys) || value.jobKeys.length > 100 || value.jobKeys.some(key => typeof key !== 'string' || !/^Q[1-9]\d{0,14}$/.test(key))
                || value.state === 'resolved' && value.verificationState !== 'verified') throw new Error('resolution_unconfirmed');
            if (observedEpoch !== epoch) throw new Error('resolution_unconfirmed');
            if (opened && started === sequence && sid === serviceId) outcome = { ...value, jobKeys: [...new Set(value.jobKeys)] };
            try { onApplied?.(value); } catch (_) { /* Refresh failures cannot change a confirmed repair outcome. */ }
            if (value.state === 'resolved') notify?.(t('Resuelto y verificado'), 'success');
        } catch (_) {
            if (opened && started === sequence) { outcome = { state: 'unconfirmed', jobKeys: [] }; feedback = reason('resolution_unconfirmed'); }
        } finally { ownMutationExpected = false; applying = false; render(); }
    }
    function resolveService(sid) {
        if (!mounted || !positive(sid) || applying || locked()) return;
        if (!opened) previousFocus = root.document.activeElement;
        sequence++; loading = false;
        serviceId = sid; opened = true; manualOnly = false; outcome = null; preview = null; feedback = ''; selected = null;
        node('service-resolution-modal').style.display = 'flex';
        node('service-resolution-heading').focus?.({ preventScroll: true }); return read(true);
    }
    function close() {
        const restoreFocus = opened;
        opened = false; sequence++; loading = false; preview = null; selected = null; outcome = null;
        if (node('service-resolution-modal')) node('service-resolution-modal').style.display = 'none';
        if (restoreFocus) previousFocus?.focus?.({ preventScroll: true });
    }
    function mount(options) {
        if (mounted || !options?.api || !node('service-resolution-modal')) return;
        mounted = true; ({ api, notify, navigate, reviewJob, onApplied } = options);
        node('service-resolution-apply').addEventListener('click', apply);
        node('service-resolution-refresh').addEventListener('click', () => { manualOnly = true; return read(false); });
        node('service-resolution-close').addEventListener('click', close);
        root.document.addEventListener('keydown', event => { if (event.key === 'Escape' && opened) close(); });
        api.onMutation?.(active => {
            mutations = Math.max(0, mutations + (active ? 1 : -1));
            if (active && ownMutationExpected) ownMutationExpected = false;
            else if (active) { epoch++; if (preview) { preview = null; selected = null; manualOnly = true; feedback = reason('resolution_changed'); } }
            controls();
        });
        api.onRecovery?.(() => { epoch++; preview = null; selected = null; manualOnly = true; feedback = t('Modo consulta: los cambios están pausados.'); render(); });
        root.PanelI18n?.onChange(render);
    }
    root.PanelServiceResolution = Object.freeze({ mount, resolveService, close, requireReload() { requiresReload = true; epoch++; preview = null; manualOnly = true; feedback = t('Recarga el panel antes de hacer cambios sobre el estado restaurado.'); render(); } });
})(globalThis);
