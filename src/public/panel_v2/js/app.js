/** Panel administrativo: los datos externos se insertan como texto o HTML escapado. */
document.addEventListener('DOMContentLoaded', () => {
    const html = value => PanelUtils.escapeHTML(value);
    const i18n = globalThis.PanelI18n;
    const t = (source, params = {}) => PanelUtils.t?.(source, params)
        ?? source.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match);
    const byId = id => document.getElementById(id);
    const loader = byId('loader');
    const deviceModal = byId('device-modal');
    const planModal = byId('plan-modal');
    const orphanModal = byId('orphan-modal');
    let notificationRegion = byId('notifications');
    let currentView = 'devices';
    let devicesCache = [];
    let plansCache = [];
    let jobsCache = {};
    const jobsRefreshPreference = 'ros-panel-jobs-refresh-v1';
    const jobsRefreshIntervals = new Set(['0', '5', '10', '30', '60']);
    let jobsRefreshSeconds = '0';
    try {
        const saved = globalThis.localStorage?.getItem(jobsRefreshPreference);
        if (jobsRefreshIntervals.has(saved)) jobsRefreshSeconds = saved;
    } catch (ignored) { /* The panel also works when browser storage is unavailable. */ }
    let jobsRefreshTimer;
    let jobsRefreshInFlight = null;
    let jobsRequestSequence = 0;
    let jobsViewGeneration = 0;
    let jobsActionPending = false;
    let jobsRefreshStopped = false;
    let jobsRefreshOnResume = false;
    let jobsLastUpdated = null;
    let jobsRefreshFailed = false;
    let planContentionDisabled = null;
    let configCache = {};
    let configDraft = {};
    let configRendered = false;
    let configRenderSequence = 0;
    let configSavePending = false;
    let currentPlan = null;
    let planNumericDraft = new Map();
    let fieldsCache;
    let fieldsLanguage;
    const pendingFields = new Map();
    let sortColumn = 'id';
    let sortDirection = 'asc';
    let loadingCount = 0;
    let languageLoad = 0;

    function translatedText(node, source, params = {}) {
        node.textContent = t(source, params);
        node.setAttribute('data-i18n', source);
        if (Object.keys(params).length) node.setAttribute('data-i18n-values', JSON.stringify(params));
    }

    async function loadConfigFields() {
        const language = i18n?.getLanguage();
        if (fieldsCache && fieldsLanguage === language) return fieldsCache;
        if (!pendingFields.has(language)) pendingFields.set(language, (async () => {
            const fields = checked(await api.getFields(language)).data;
            if (!fields?.[1]) throw new Error(t('No se pudieron cargar los campos de configuración.'));
            return fields[1];
        })());
        let fields;
        try { fields = await pendingFields.get(language); }
        finally { pendingFields.delete(language); }
        if (language !== i18n?.getLanguage()) return loadConfigFields();
        fieldsCache = fields;
        fieldsLanguage = language;
        return fields;
    }

    function checked(response) {
        if (!response || response.error || ['failed', 'error'].includes(response.status)) {
            throw new Error(response?.message || t('No se pudo completar la operación.'));
        }
        return response;
    }

    function notify(message, kind = 'success') {
        if (!notificationRegion) {
            notificationRegion = document.createElement('div');
            notificationRegion.id = 'notifications';
            notificationRegion.className = 'toast-region';
            notificationRegion.setAttribute('aria-live', 'polite');
            notificationRegion.setAttribute('aria-relevant', 'additions');
            notificationRegion.dataset.fallback = 'true';
            // El HTML y CSS anteriores no incluyen la región de avisos.
            notificationRegion.style.cssText = 'position:fixed;top:16px;right:16px;z-index:1100;max-width:calc(100vw - 24px);width:400px;pointer-events:none;';
            document.body.appendChild(notificationRegion);
        }
        const region = notificationRegion;
        const toast = document.createElement('div');
        toast.className = `toast toast-${kind}`;
        toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        toast.setAttribute('aria-atomic', 'true');
        const text = document.createElement('p');
        text.className = 'toast-message';
        text.textContent = t(String(message ?? ''));
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.textContent = '×';
        close.setAttribute('aria-label', t('Cerrar notificación'));
        close.setAttribute('data-i18n-aria-label', 'Cerrar notificación');
        if (region.dataset.fallback === 'true') {
            const border = { error: 'var(--danger)', info: 'var(--primary)', success: 'var(--success)' }[kind];
            toast.style.cssText = `display:flex;align-items:flex-start;gap:12px;padding:12px 14px;margin-bottom:8px;background:var(--toast-bg);color:var(--text-main);border:1px solid ${border};border-radius:8px;box-shadow:var(--toast-shadow);pointer-events:auto;`;
            text.style.cssText = 'flex:1;margin:0;white-space:normal;overflow-wrap:anywhere;';
            close.style.cssText = 'border:0;background:transparent;color:inherit;font-size:20px;cursor:pointer;padding:0 3px;';
        }
        let timer;
        const dismiss = () => {
            if (timer !== undefined && typeof clearTimeout === 'function') clearTimeout(timer);
            toast.remove();
        };
        close.addEventListener('click', dismiss);
        toast.append(text, close);
        region.appendChild(toast);
        if (kind !== 'error' && typeof setTimeout === 'function') {
            timer = setTimeout(dismiss, 6000);
            timer?.unref?.();
        }
    }

    function completed(response, message) {
        const result = response?.data;
        const counts = result && ['requested', 'completed', 'failed'].every(key => Number.isSafeInteger(result[key]) && result[key] >= 0)
            && result.completed <= result.requested && result.failed <= result.requested
            && result.completed + result.failed === result.requested;
        if (result && ['requested', 'completed', 'failed'].some(key => Object.prototype.hasOwnProperty.call(result, key)) && !counts)
            throw new Error(t('Respuesta inválida del servidor.'));
        if (response?.message === 'provisioning_incomplete' || (counts && result.failed > 0)) {
            const parts = [counts
                ? t('Aprovisionamiento incompleto: {completed} de {requested} servicios completados; {failed} pendientes o con error.', result)
                : t('El aprovisionamiento no se completó.')];
            const failures = Array.isArray(result?.failures) ? result.failures : [];
            for (const failure of failures.slice(0, 6)) {
                if (!failure || !Number.isSafeInteger(failure.serviceId) || failure.serviceId < 1) continue;
                const detail = [t('Servicio {serviceId}', failure)];
                if (Number.isSafeInteger(failure.planId) && failure.planId > 0) detail.push(t('plan {planId}', failure));
                if (typeof failure.field === 'string' && failure.field) detail.push(t('campo {field}', { field: failure.field.slice(0, 100) }));
                parts.push(detail.join(' · '));
            }
            if (failures.length > 6) parts.push(t('Otros {count} servicios con error.', { count: failures.length - 6 }));
            parts.push(t('Revisa Trabajos y el registro del plugin.'));
            notify(parts.join(' '), 'error');
            return;
        }
        checked(response);
        const accepted = api.isAccepted(response);
        const totals = counts ? t('Servicios completados: {completed} de {requested}.', result) : '';
        notify(accepted ? t('Solicitud aceptada. El trabajo seguirá ejecutándose; comprueba su resultado en Trabajos.') : [message, totals].filter(Boolean).join(' '),
            accepted ? 'info' : 'success');
    }

    async function withLoader(work, button) {
        loadingCount++;
        loader.style.display = 'flex';
        if (button) button.disabled = true;
        try { return await work(); }
        catch (error) { notify(t('Error: {message}', { message: t(error?.message || 'No se pudo completar la operación.') }), 'error'); }
        finally {
            loadingCount--;
            loader.style.display = loadingCount > 0 ? 'flex' : 'none';
            if (button) button.disabled = false;
        }
    }

    function bind(id, work) {
        byId(id)?.addEventListener('click', event => {
            event.preventDefault();
            return withLoader(() => work(event), event.currentTarget);
        });
    }

    function actionButton(label, icon, work, variant = 'secondary', { loading = true } = {}) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `btn btn-${variant} row-action`;
        button.title = t(label);
        button.setAttribute('aria-label', t(label));
        button.setAttribute('data-i18n-title', label);
        button.setAttribute('data-i18n-aria-label', label);
        button.innerHTML = `<i data-feather="${html(icon)}"></i>`;
        let running = false;
        button.addEventListener('click', async () => {
            if (running) return;
            if (loading) return withLoader(work, button);
            // Abrir un editor local no necesita superponer la pantalla de carga.
            running = true;
            try { return await work(); }
            catch (error) { notify(t('Error: {message}', { message: t(error?.message || 'No se pudo completar la operación.') }), 'error'); }
            finally { running = false; }
        });
        return button;
    }

    function confirmAction(message) {
        return new Promise(resolve => {
            const previousFocus = document.activeElement;
            const overlay = document.createElement('div');
            overlay.className = 'confirm-overlay';
            const box = document.createElement('div');
            box.className = 'confirm-dialog';
            box.setAttribute('role', 'dialog');
            box.setAttribute('aria-modal', 'true');
            box.setAttribute('aria-label', message);
            const text = document.createElement('p');
            text.textContent = message;
            const controls = document.createElement('div');
            controls.className = 'confirm-controls';
            const finish = result => {
                overlay.remove();
                document.removeEventListener('keydown', escape);
                previousFocus?.focus();
                resolve(result);
            };
            const escape = event => { if (event.key === 'Escape') finish(false); };
            for (const [label, result, variant] of [['Cancelar', false, 'secondary'], ['Aceptar', true, 'primary']]) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = `btn btn-${variant}`;
                translatedText(button, label);
                button.addEventListener('click', () => finish(result));
                controls.appendChild(button);
            }
            box.append(text, controls);
            overlay.appendChild(box);
            document.body.appendChild(overlay);
            document.addEventListener('keydown', escape);
            controls.firstElementChild.focus();
        });
    }

    function closeModals() {
        for (const modal of [deviceModal, planModal, orphanModal]) modal.style.display = 'none';
        byId('dev-password').value = '';
    }

    document.querySelectorAll('.close-modal').forEach(button => button.addEventListener('click', closeModals));
    document.addEventListener('keydown', event => { if (event.key === 'Escape') closeModals(); });

    function switchView(view, title) {
        if (currentView === 'config') captureConfigDraft();
        if (currentView === 'config' && view !== 'config') configRenderSequence++;
        jobsViewGeneration++;
        clearJobsRefreshTimer();
        currentView = view;
        translatedText(byId('page-title'), { devices: 'Dispositivos administrados', plans: 'Planes', config: 'Configuración', jobs: 'Trabajos', system: 'Sistema' }[view] || title);
        document.querySelectorAll('.nav-item').forEach(nav => nav.classList.toggle('active', nav.dataset.view === view));
        document.querySelectorAll('.view-section').forEach(section => {
            section.style.display = section.id === `view-${view}` ? 'block' : 'none';
        });
        const topbar = byId('topbar-actions');
        topbar.replaceChildren();
        if (view === 'devices') {
            const button = document.createElement('button');
            button.className = 'btn btn-primary';
            translatedText(button, 'Agregar dispositivo');
            button.addEventListener('click', () => openDeviceModal());
            topbar.appendChild(button);
        }
        withLoader(async () => {
            const render = { devices: renderDevices, plans: renderPlans, config: renderConfig, jobs: renderJobs, system: renderSystem }[view];
            if (render) await render();
        });
    }

    document.querySelectorAll('.nav-item').forEach(nav => nav.addEventListener('click', event => {
        event.preventDefault();
        switchView(nav.dataset.view, nav.querySelector('span').textContent);
    }));

    async function renderDevices() {
        const response = checked(await api.getDevices());
        devicesCache = Array.isArray(response.data) ? response.data : [];
        renderDevicesTable();
    }

    function renderDevicesTable() {
        const tbody = document.querySelector('#devices-table tbody');
        tbody.replaceChildren();
        const search = byId('search-devices').value.toLocaleLowerCase();
        const devices = [...devicesCache].filter(device => `${device.id} ${device.name} ${device.ip}`.toLocaleLowerCase().includes(search));
        devices.sort((left, right) => {
            const value = item => ['id', 'users'].includes(sortColumn)
                ? Number(item[sortColumn]) || 0 : String(item[sortColumn] ?? '').toLocaleLowerCase();
            const a = value(left), b = value(right);
            return (a < b ? -1 : a > b ? 1 : 0) * (sortDirection === 'asc' ? 1 : -1);
        });
        document.querySelectorAll('#devices-table th.sortable').forEach(header => {
            header.classList.toggle('asc', header.dataset.sort === sortColumn && sortDirection === 'asc');
            header.classList.toggle('desc', header.dataset.sort === sortColumn && sortDirection === 'desc');
        });
        if (!devices.length) tbody.innerHTML = `<tr><td colspan="6" class="empty-state" data-i18n="No se encontraron dispositivos.">${html(t('No se encontraron dispositivos.'))}</td></tr>`;
        devices.forEach(device => {
            const row = document.createElement('tr');
            const online = PanelUtils.flag(device.status);
            row.innerHTML = `<td>${html(device.id)}</td><td>${html(device.name)}</td><td>${html(device.ip)}</td>
                <td>${html(device.users ?? 0)}</td><td><span class="badge badge-${online ? 'success' : 'danger'}" data-i18n="${online ? 'Puerto accesible' : 'Desconectado'}">${html(t(online ? 'Puerto accesible' : 'Desconectado'))}</span></td><td class="row-actions"></td>`;
            const actions = row.querySelector('.row-actions');
            actions.append(
                actionButton('Ver cuentas huérfanas', 'users', () => viewOrphans(device.id)),
                actionButton('Editar dispositivo', 'edit-2', () => openDeviceModal(device), 'secondary', { loading: false }),
                actionButton('Sincronizar y reconectar cuentas', 'refresh-cw', async () => {
                    if (!await confirmAction(t('¿Sincronizar las cuentas administradas de este MikroTik y reconectar todas sus sesiones PPP/Hotspot?'))) return;
                    completed(await api.send('update', 'system', 'insert', { type: 'device', id: Number(device.id) }), t('Sincronización y reconexión completadas.'));
                    await renderDevices();
                }),
                actionButton('Vaciar cuentas administradas', 'x-circle', async () => {
                    if (!await confirmAction(t('¿Eliminar todas las cuentas administradas por el plugin de este MikroTik?'))) return;
                    completed(await api.send('update', 'system', 'delete', { type: 'device', id: Number(device.id) }), t('Cuentas eliminadas.'));
                    await renderDevices();
                }),
                actionButton('Eliminar dispositivo', 'trash-2', async () => {
                    if (!await confirmAction(t('¿Eliminar este dispositivo del plugin?'))) return;
                    checked(await api.send('update', 'devices', 'delete', { id: Number(device.id) }));
                    await renderDevices();
                }, 'danger')
            );
            tbody.appendChild(row);
        });
        PanelUtils.replaceIcons();
    }

    byId('search-devices').addEventListener('input', renderDevicesTable);
    document.querySelectorAll('#devices-table th.sortable').forEach(header => header.addEventListener('click', () => {
        const column = header.dataset.sort;
        sortDirection = sortColumn === column && sortDirection === 'asc' ? 'desc' : 'asc';
        sortColumn = column;
        renderDevicesTable();
    }));

    async function renderPlans() {
        // Sólo la configuración guardada determina qué campos se aplican a los planes.
        planContentionDisabled = null;
        plansCache = [];
        renderPlansTable();
        const [plansResponse, configResponse] = await Promise.all([api.getPlans(), api.getConfig()]);
        const response = checked(plansResponse);
        const savedConfig = checked(configResponse).data;
        if (!savedConfig || typeof savedConfig !== 'object' || Array.isArray(savedConfig)
            || !Object.prototype.hasOwnProperty.call(savedConfig, 'disable_contention')) {
            throw new Error(t('No se pudo comprobar la configuración de contención. Vuelve a abrir la sección Planes.'));
        }
        planContentionDisabled = Boolean(PanelUtils.flag(savedConfig.disable_contention));
        plansCache = Array.isArray(response.data) ? response.data : Object.values(response.data || {});
        renderPlansTable();
    }

    function renderPlansTable() {
        const tbody = document.querySelector('#plans-table tbody');
        tbody.replaceChildren();
        const search = byId('search-plans').value.toLocaleLowerCase();
        const plans = plansCache.filter(plan => plan.name && plan.name !== 'null'
            && `${plan.id} ${plan.name}`.toLocaleLowerCase().includes(search));
        if (!plans.length) tbody.innerHTML = `<tr><td colspan="7" class="empty-state" data-i18n="No se encontraron planes.">${html(t('No se encontraron planes.'))}</td></tr>`;
        plans.forEach(plan => {
            const row = document.createElement('tr');
            const invalidFields = new Set((Array.isArray(plan.numericIssues) ? plan.numericIssues : []).map(issue => issue?.field));
            const invalidLabel = `<span class="badge badge-danger" data-i18n="Inválido">${html(t('Inválido'))}</span>`;
            const speedLabel = direction => ['upload', 'download'].includes(direction)
                && [direction + 'Speed', direction + 'Override', 'effective' + direction[0].toUpperCase() + direction.slice(1) + 'Speed'].some(field => invalidFields.has(field))
                ? invalidLabel : html(plan['effective' + direction[0].toUpperCase() + direction.slice(1) + 'Speed'] ?? plan[direction + 'Override'] ?? plan[direction + 'Speed'] ?? 0);
            const burstLabel = (value, field) => invalidFields.has(field) ? invalidLabel : value == null || Number(value) === -100 || Number(value) === 0
                ? `<span data-i18n="Sin burst">${html(t('Sin burst'))}</span>`
                : html(Number(value) > 0 ? '+' + value : String(value));
            row.innerHTML = `<td>${html(plan.id)}</td><td>${html(plan.name)}${invalidFields.size ? ' ' + invalidLabel : ''}</td>
                <td>${speedLabel('upload')}</td>
                <td>${speedLabel('download')}</td>
                <td>${burstLabel(plan.burstUpload, 'burstUpload')}</td>
                <td>${burstLabel(plan.burstDownload, 'burstDownload')}</td><td class="row-actions"></td>`;
            row.querySelector('.row-actions').appendChild(actionButton('Editar plan', 'edit-2', () => openPlanModal(plan), 'secondary', { loading: false }));
            tbody.appendChild(row);
        });
        PanelUtils.replaceIcons();
    }
    byId('search-plans').addEventListener('input', renderPlansTable);

    const boolFields = ['auto_ppp_user', 'router_ppp_pool', 'unsuspend_date_fix', 'disable_contention', 'hs_enable', 'auto_hs_user'];
    const hotspotOptions = ['hs_enable', 'auto_hs_user'];
    const inactiveConfigFields = ['hs_enable', 'unsuspend_date_fix', 'unsuspend_fix_wait', 'plugin_language'];
    const numericConfigFields = ['lease_time', 'disabled_rate', 'unsuspend_fix_wait'];
    const localAttributeFields = [
        { name: 'ip_addr6_attr', label: 'Atributo de dirección IPv6', description: 'Nombre del atributo de servicio en UISP que contiene la dirección o el prefijo IPv6 del cliente.' },
        { name: 'hs_attr', label: 'Atributo de cuenta Hotspot', description: 'Nombre del atributo de servicio en UISP que identifica una cuenta de Hotspot.' }
    ];

    function isBooleanConfig(name) {
        return boolFields.includes(name) || Object.values(fieldsCache || {}).some(field => field.name === name && field.type === 1);
    }

    function configComparableValue(name, value) {
        if (isBooleanConfig(name)) return PanelUtils.flag(value) ? '1' : '0';
        return value === 'null' || value == null ? '' : String(value);
    }

    function updateConfigSaveStatus() {
        const status = byId('config-save-status');
        const changed = Object.entries(configDraft).some(([name, value]) => configComparableValue(name, value) !== configComparableValue(name, configCache[name]));
        if (status) status.textContent = changed ? t('Cambios sin guardar.') : t('Guarda para aplicar los cambios.');
    }

    function captureConfigDraft() {
        if (!configRendered) return;
        const visible = Object.fromEntries(new FormData(byId('config-form')).entries());
        Object.assign(configDraft, visible);
        updateConfigSaveStatus();
    }

    function configPayload() {
        captureConfigDraft();
        const data = { ...configDraft, ...Object.fromEntries(new FormData(byId('config-form')).entries()) };
        for (const name of inactiveConfigFields) delete data[name];
        for (const name of Object.keys(data)) {
            if (isBooleanConfig(name)) data[name] = Boolean(PanelUtils.flag(data[name]));
        }
        for (const name of numericConfigFields) {
            if (name in data && data[name] !== '') data[name] = PanelUtils.number(data[name], name);
        }
        return data;
    }

    async function saveConfigDraft() {
        if (configSavePending) throw new Error(t('Espera a que termine el guardado de configuración.'));
        configSavePending = true;
        try {
            const data = configPayload();
            const response = checked(await api.saveConfig(data));
            // Keyboard edits and edits in another tab may arrive while the request is in flight.
            captureConfigDraft();
            configCache = { ...configCache, ...data };
            for (const [name, savedValue] of Object.entries(data)) {
                if (configComparableValue(name, configDraft[name]) === configComparableValue(name, savedValue)) delete configDraft[name];
            }
            updateConfigSaveStatus();
            return response;
        } finally { configSavePending = false; }
    }

    byId('config-form').addEventListener('input', captureConfigDraft);
    byId('config-form').addEventListener('change', captureConfigDraft);

    const configSections = {
        general: [
            ['access', 'Usuarios PPPoE y Hotspot', 'Opciones de creación de cuentas y generación de usuarios.'],
            ['addressing', 'Direccionamiento', 'Pools, exclusiones y concesiones de direcciones.'],
            ['suspension', 'Suspensión y reactivación', 'Listas, perfiles y ajustes al reanudar el servicio.'],
            ['advanced', 'Avanzado', 'Contención de ancho de banda.']
        ],
        attributes: [
            ['network', 'Router y direcciones', 'Atributos de UISP que identifican el router y las direcciones del servicio.'],
            ['ppp', 'PPPoE', 'Usuario, contraseña e identificador de la conexión PPP.'],
            ['dhcp', 'DHCP', 'Identificadores del cliente para DHCPv4 y DHCPv6.'],
            ['hotspot', 'Hotspot', 'Atributos para las cuentas de Hotspot.']
        ]
    };

    function configSectionFor(name, isAttrs) {
        if (isAttrs) {
            if (name.startsWith('pppoe_')) return 'ppp';
            if (name === 'mac_addr_attr' || name.startsWith('dhcp')) return 'dhcp';
            return name.startsWith('hs_') ? 'hotspot' : 'network';
        }
        if (hotspotOptions.includes(name) || name === 'auto_ppp_user') return 'access';
        if (['ppp_pool', 'router_ppp_pool', 'excl_addr', 'lease_time'].includes(name)) return 'addressing';
        if (['active_list', 'disabled_list', 'disabled_profile', 'disabled_rate', 'unsuspend_date_fix', 'unsuspend_fix_wait'].includes(name)) return 'suspension';
        return 'advanced';
    }

    function configField(field, value) {
        const id = 'config-' + field.name;
        const boolean = field.type === 1 || boolFields.includes(field.name);
        const description = field.description ? String(field.description) : '';
        const describedBy = description ? ` aria-describedby="${html(id)}-help"` : '';
        const enabled = Boolean(PanelUtils.flag(value));
        const inputType = field.obscure ? 'password' : numericConfigFields.includes(field.name) ? 'number' : 'text';
        const input = boolean
            ? `<input type="hidden" name="${html(field.name)}" value="0">
                <label class="switch-control" for="${html(id)}">
                    <input type="checkbox" role="switch" id="${html(id)}" name="${html(field.name)}" value="1" ${enabled ? 'checked' : ''}${describedBy}>
                    <span class="switch-track" aria-hidden="true"></span>
                    <span class="switch-copy"><span class="field-label-text">${html(field.label)}</span><small class="switch-status" aria-hidden="true">${enabled ? t('Activado') : t('Desactivado')}</small></span>
                </label>`
            : `<label for="${html(id)}" class="field-label-text">${html(field.label)}</label>
                <input id="${html(id)}" type="${inputType}"${inputType === 'number' ? ' step="any"' : ''} name="${html(field.name)}" value="${html(value)}" placeholder="${html(field.hint || '')}" class="form-control"${describedBy}>`;
        const group = document.createElement('div');
        group.className = 'config-field' + (boolean ? ' config-field-toggle' : '')
            + (field.obscure ? ' config-field-wide' : '');
        group.dataset.fieldName = field.name;
        group.innerHTML = input + (description
            ? `<details class="field-help"><summary aria-label="${html(t('Ayuda sobre {label}', { label: field.label }))}" data-i18n="Ayuda">${html(t('Ayuda'))}</summary><p id="${html(id)}-help" class="field-help-copy">${html(description)}</p></details>` : '');
        if (boolean) group.querySelector('input[type="checkbox"]').addEventListener('change', event => {
            group.querySelector('.switch-status').textContent = event.target.checked ? t('Activado') : t('Desactivado');
        });
        return group;
    }

    async function renderConfig(isAttrs = byId('tab-attrs').classList.contains('active')) {
        const sequence = ++configRenderSequence;
        captureConfigDraft();
        // The selected tab follows the newest user intent, not response completion order.
        byId('tab-general').classList.toggle('active', !isAttrs);
        byId('tab-attrs').classList.toggle('active', isAttrs);
        byId('sync-attrs-btn').style.display = isAttrs ? 'inline-block' : 'none';
        try { await loadConfigFields(); }
        catch (error) { if (sequence !== configRenderSequence) return; throw error; }
        if (sequence !== configRenderSequence) return;
        const response = await api.getConfig();
        if (sequence !== configRenderSequence) return;
        configCache = checked(response).data || {};
        // Preserve edits made while reloading labels or saved values before replacing the controls.
        captureConfigDraft();
        const values = { ...configCache, ...configDraft };
        const container = byId('config-fields-container');
        container.replaceChildren();
        const sections = new Map();
        const appendField = (section, field) => {
            if (!sections.has(section)) sections.set(section, []);
            sections.get(section).push(field);
        };
        Object.entries(fieldsCache).forEach(([key, field]) => {
            const attribute = Boolean(field.isAttribute) && !hotspotOptions.includes(field.name);
            if (!key.startsWith('config_') || ['uisp_token', 'uisp_url', 'unmsToken', 'unmsUrl', ...inactiveConfigFields].includes(field.name)
                || attribute !== isAttrs || !/^[a-zA-Z0-9_]+$/.test(field.name)) return;
            const value = values[field.name] === 'null' ? '' : values[field.name] ?? '';
            appendField(configSectionFor(field.name, isAttrs), configField(field, value));
        });
        if (isAttrs) {
            const knownFields = new Set(Object.values(fieldsCache).map(field => field.name));
            for (const original of localAttributeFields) {
                const field = { ...original, label: t(original.label), description: t(original.description) };
                if (knownFields.has(field.name) || !Object.prototype.hasOwnProperty.call(configCache, field.name)) continue;
                const value = values[field.name] === 'null' ? '' : values[field.name] ?? '';
                appendField(configSectionFor(field.name, true), configField(field, value));
            }
        }
        for (const [key, title, description] of configSections[isAttrs ? 'attributes' : 'general']) {
            if (!sections.has(key)) continue;
            const section = document.createElement(key === 'advanced' ? 'details' : 'section');
            section.className = 'config-section';
            const header = document.createElement(key === 'advanced' ? 'summary' : 'header');
            header.className = 'config-section-header';
            header.innerHTML = `<h3 data-i18n="${html(title)}">${html(t(title))}</h3><p data-i18n="${html(description)}">${html(t(description))}</p>`;
            const grid = document.createElement('div');
            grid.className = 'config-grid';
            for (const field of sections.get(key)) grid.appendChild(field);
            section.append(header, grid);
            container.appendChild(section);
        }
        configRendered = true;
        updateConfigSaveStatus();
    }
    for (const tab of ['tab-general', 'tab-attrs']) bind(tab, async () => {
        await renderConfig(tab === 'tab-attrs');
    });
    bind('save-config-btn', async () => {
        if (!byId('config-form').reportValidity()) return;
        const response = await saveConfigDraft();
        completed(response, response.data?.message || t('Configuración guardada.'));
        await renderConfig();
    });
    bind('sync-attrs-btn', async () => {
        if (!byId('config-form').reportValidity()) return;
        await saveConfigDraft();
        const response = checked(await api.send('update', 'config', 'sync_attrs'));
        completed(response, response.data?.message || t('Configuración guardada y atributos verificados.'));
        await renderConfig();
    });

    function jobsVisible() {
        return currentView === 'jobs' && document.hidden !== true && !jobsRefreshStopped;
    }
    function clearJobsRefreshTimer() {
        if (jobsRefreshTimer !== undefined) clearTimeout(jobsRefreshTimer);
        jobsRefreshTimer = undefined;
    }
    function updateJobsRefreshStatus() {
        const busy = jobsActionPending || jobsRefreshInFlight !== null;
        if (byId('btn-refresh-jobs')) byId('btn-refresh-jobs').disabled = busy;
        for (const id of ['btn-run-jobs', 'btn-delete-jobs']) if (byId(id)) byId(id).disabled = jobsActionPending;
        const status = byId('jobs-refresh-status');
        if (!status) return;
        if (jobsRefreshInFlight) translatedText(status, 'Actualizando...');
        else {
            const time = jobsLastUpdated?.toLocaleTimeString(i18n?.getLanguage());
            if (jobsRefreshFailed) translatedText(status, time ? 'Falló el refresco. Última actualización: {time}.' : 'No se pudo refrescar la lista de trabajos.', { time });
            else translatedText(status, time ? 'Última actualización: {time}.' : 'Sin actualizar.', { time });
        }
    }
    function scheduleJobsRefresh() {
        clearJobsRefreshTimer();
        if (!jobsVisible() || jobsActionPending || jobsRefreshInFlight || jobsRefreshSeconds === '0') return;
        jobsRefreshTimer = setTimeout(() => {
            jobsRefreshTimer = undefined;
            refreshJobsQuietly();
        }, Number(jobsRefreshSeconds) * 1000);
    }
    async function refreshJobsQuietly() {
        try { await renderJobs(); }
        catch (ignored) { /* The inline status reports failures without repeated popups. */ }
    }
    async function renderJobs({ duringAction = false } = {}) {
        if (!jobsVisible() || (jobsActionPending && !duringAction)) return false;
        if (jobsRefreshInFlight) {
            const applied = await jobsRefreshInFlight;
            return applied ? true : renderJobs({ duringAction });
        }
        clearJobsRefreshTimer();
        const generation = jobsViewGeneration;
        const sequence = ++jobsRequestSequence;
        const current = () => jobsVisible() && generation === jobsViewGeneration && sequence === jobsRequestSequence
            && (!jobsActionPending || duringAction);
        const work = (async () => {
            try {
                const response = await api.getJobs();
                if (!current()) return false;
                const jobs = checked(response).data || {};
                if (typeof jobs !== 'object' || (Array.isArray(jobs) && jobs.length)
                    || !Object.values(jobs).every(job => job !== null && typeof job === 'object' && !Array.isArray(job)))
                    throw new Error(t('Respuesta inválida del servidor.'));
                jobsCache = jobs;
                renderJobsTable(jobs);
                jobsLastUpdated = new Date();
                jobsRefreshFailed = false;
                return true;
            } catch (error) {
                if (!current()) return false;
                jobsRefreshFailed = true;
                throw error;
            }
        })();
        jobsRefreshInFlight = work;
        updateJobsRefreshStatus();
        try { return await work; }
        finally {
            jobsRefreshInFlight = null;
            updateJobsRefreshStatus();
            scheduleJobsRefresh();
        }
    }
    const jobsIntervalControl = byId('jobs-refresh-interval');
    if (jobsIntervalControl) {
        jobsIntervalControl.value = jobsRefreshSeconds;
        jobsIntervalControl.addEventListener('change', () => {
            jobsRefreshSeconds = jobsRefreshIntervals.has(jobsIntervalControl.value) ? jobsIntervalControl.value : '0';
            jobsIntervalControl.value = jobsRefreshSeconds;
            try { globalThis.localStorage?.setItem(jobsRefreshPreference, jobsRefreshSeconds); } catch (ignored) {}
            scheduleJobsRefresh();
        });
    }
    byId('btn-refresh-jobs')?.addEventListener('click', async () => {
        if (!jobsVisible() || jobsActionPending) return;
        try { await renderJobs(); }
        catch (error) { notify(t('Error: {message}', { message: t(error?.message || 'No se pudo refrescar la lista de trabajos.') }), 'error'); }
    });
    document.addEventListener('visibilitychange', () => {
        clearJobsRefreshTimer();
        if (document.hidden === true) {
            jobsRefreshOnResume = jobsRefreshOnResume || jobsRefreshInFlight !== null;
            jobsViewGeneration++; jobsRequestSequence++;
        } else if (jobsVisible() && !jobsActionPending && (jobsRefreshSeconds !== '0' || jobsRefreshOnResume)) {
            jobsRefreshOnResume = false;
            refreshJobsQuietly();
        }
    });
    globalThis.window?.addEventListener?.('pagehide', () => {
        jobsRefreshStopped = true;
        jobsRefreshOnResume = jobsRefreshOnResume || jobsRefreshInFlight !== null;
        jobsViewGeneration++; jobsRequestSequence++;
        clearJobsRefreshTimer();
    });
    globalThis.window?.addEventListener?.('pageshow', () => {
        jobsRefreshStopped = false;
        if (jobsVisible() && !jobsActionPending && (jobsRefreshSeconds !== '0' || jobsRefreshOnResume)) {
            jobsRefreshOnResume = false;
            refreshJobsQuietly();
        }
    });
    function jobReason(job, deleting = false) {
        const reason = deleting ? job.deleteBlockedReason : job.blockedReason;
        if (!deleting && job.state === 'missing') return ['El trabajo ya no existe en la cola.', {}];
        if (reason === 'earlier_job') return job.blockedBy
            ? ['Espera a {job} del mismo servicio.', { job: String(job.blockedBy) }]
            : ['Espera a un trabajo anterior del mismo servicio.', {}];
        if (reason === 'running') return ['El trabajo está en ejecución.', {}];
        if (reason === 'service_event') return ['Hay un evento del servicio en curso.', {}];
        if (reason === 'terminal') return ['El servicio está terminado; espera un nuevo evento de UISP.', {}];
        if (reason === 'maintenance') return ['La base de datos está en mantenimiento.', {}];
        if (reason === 'prepared') return ['Puede haber cambios previos en el router. Resuelve el último error y ejecuta el trabajo para reconciliarlos.', {}];
        if (reason === 'not_pending') return ['El trabajo ya no está pendiente.', {}];
        if (reason === 'missing') return ['El trabajo ya no existe en la cola.', {}];
        if (deleting && job.canDelete === false) return ['No se pudo eliminar el trabajo.', {}];
        if (!deleting && job.blockedReason) return ['El trabajo está bloqueado.', {}];
        return null;
    }
    function jobNoteMarkup(note, label) {
        if (!note) return '';
        const [source, params] = note;
        return `<br><small class="job-error"><span data-i18n="${html(label)}">${html(t(label))}</span>: <span data-i18n="${html(source)}" data-i18n-values="${html(JSON.stringify(params))}">${html(t(source, params))}</span></small>`;
    }
    function jobConnectionHelp(error) {
        if (typeof error !== 'string') return null;
        const message = error.trim();
        const diagnostics = {
            router_tcp_connect_failed: 'La conexión TCP al puerto API no se estableció. Revisa dirección, puerto y acceso desde el servidor de UISP.',
            router_tls_connect_failed: 'La conexión API SSL no se estableció. Revisa dirección, puerto, opciones TLS y certificados del dispositivo.',
            router_login_rejected: 'RouterOS rechazó el acceso por API. Revisa usuario, contraseña y permisos de API del dispositivo.',
            router_login_timeout: 'RouterOS no confirmó el acceso por API antes del tiempo límite. Revisa disponibilidad del router, puerto y TLS.',
            router_login_connection_closed: 'La conexión se cerró durante el acceso por API. Revisa el servicio API, puerto y TLS del dispositivo.',
            router_login_protocol_error: 'La respuesta del acceso por API no se pudo interpretar. Revisa el puerto y las opciones API/TLS del dispositivo.',
            router_login_write_failed: 'No se pudo enviar la solicitud de acceso por API. Revisa la conexión y las opciones API/TLS del dispositivo.'
        };
        if (Object.prototype.hasOwnProperty.call(diagnostics, message)) return diagnostics[message];
        if (message === 'device connect failed' || /^router_connection_failed(?:[:_]|$)/.test(message))
            return 'Puerto accesible sólo comprueba que el puerto responde. Revisa usuario, contraseña, permisos de API y TLS del dispositivo.';
        return null;
    }
    function renderJobsTable(jobs) {
        const selected = new Set(Array.from(document.querySelectorAll('.job-checkbox:checked'), checkbox => checkbox.value));
        const entries = Object.entries(jobs);
        const tbody = document.querySelector('#jobs-table tbody');
        tbody.replaceChildren();
        translatedText(byId('jobs-total'), 'Total: {count}', { count: entries.length });
        translatedText(byId('jobs-selected'), 'Seleccionados: {count}', { count: 0 });
        byId('jobs-select-all').checked = false;
        if (!entries.length) tbody.innerHTML = `<tr><td colspan="2" class="empty-state" data-i18n="No hay trabajos en cola.">${html(t('No hay trabajos en cola.'))}</td></tr>`;
        entries.forEach(([key, job]) => {
            const row = document.createElement('tr');
            const descriptionKey = '{action} => id: {id} dispositivo: {device} usuario/MAC: {user}';
            const descriptionValues = { action: t(job.action || 'update'), id: job.serviceId ?? job.id ?? '', device: job.deviceId ?? job.device ?? '', user: job.username || job.mac || '' };
            const description = t(descriptionKey, descriptionValues);
            const error = job.error ? typeof job.error === 'object' ? JSON.stringify(job.error) : String(job.error) : '';
            const connectionHelp = jobConnectionHelp(error);
            const stateKey = job.state === 'pending' ? 'Pendiente' : job.state === 'running' ? 'En ejecución' : 'Estado desconocido';
            const attempts = Number.isSafeInteger(job.attempts) && job.attempts >= 0 ? job.attempts : 0;
            const nextRetry = job.nextRetry
                ? ` · <span data-i18n="Próximo intento">${html(t('Próximo intento'))}</span>: <span data-date="${html(job.nextRetry)}">${html(formatDate(job.nextRetry))}</span>` : '';
            row.innerHTML = `<td class="job-description"><span data-i18n="${html(descriptionKey)}" data-i18n-values="${html(JSON.stringify(descriptionValues))}">${html(description)}</span>
                <br><small class="text-muted">${html(job.jobId || key)} · <span data-i18n="${html(stateKey)}">${html(t(stateKey))}</span> · <span data-i18n="Intentos: {count}" data-i18n-values="${html(JSON.stringify({ count: attempts }))}">${html(t('Intentos: {count}', { count: attempts }))}</span>${nextRetry}</small>
                ${error ? `<br><span class="job-error"><span data-i18n="Último error">${html(t('Último error'))}</span>: ${html(error)}</span>` : ''}
                ${connectionHelp ? `<br><small class="text-muted" data-i18n="${html(connectionHelp)}">${html(t(connectionHelp))}</small>` : ''}
                ${jobNoteMarkup(jobReason(job), 'Bloqueado')}
                ${jobNoteMarkup(job.canDelete === false ? jobReason(job, true) : null, 'No se puede eliminar')}
                </td><td class="job-selection"><input type="checkbox" class="job-checkbox" aria-label="${html(t('Seleccionar trabajo'))}" data-i18n-aria-label="Seleccionar trabajo"></td>`;
            const checkbox = row.querySelector('.job-checkbox');
            checkbox.value = key;
            checkbox.checked = selected.has(key) && (!job.state || job.state === 'pending');
            checkbox.addEventListener('change', updateJobSelection);
            tbody.appendChild(row);
        });
        updateJobSelection();
    }
    function updateJobSelection() {
        const boxes = Array.from(document.querySelectorAll('.job-checkbox'));
        const count = boxes.filter(checkbox => checkbox.checked).length;
        translatedText(byId('jobs-selected'), 'Seleccionados: {count}', { count });
        byId('jobs-select-all').checked = boxes.length > 0 && count === boxes.length;
        byId('jobs-select-all').indeterminate = count > 0 && count < boxes.length;
    }
    byId('jobs-select-all').addEventListener('change', event => {
        document.querySelectorAll('.job-checkbox').forEach(checkbox => { checkbox.checked = event.target.checked; });
        updateJobSelection();
    });
    function jobOperationFeedback(response, action) {
        const data = response?.data;
        const record = value => value !== null && typeof value === 'object' && !Array.isArray(value);
        if (!record(data) || !['operation', 'summary', 'results', 'jobs'].some(key => Object.prototype.hasOwnProperty.call(data, key))) return null;
        const validId = value => typeof value === 'string' && /^Q[1-9]\d*$/.test(value);
        const fields = ['requested', 'attempted', 'succeeded', 'pending', 'failed', 'deleted', 'refused'];
        if (data.operation !== action || !Array.isArray(data.requested) || !data.requested.every(validId)
            || new Set(data.requested).size !== data.requested.length
            || !record(data.results) || !record(data.jobs) || !record(data.summary)
            || !fields.every(key => Number.isSafeInteger(data.summary[key]) && data.summary[key] >= 0)
            || data.summary.requested !== data.requested.length
            || !Object.keys(data.results).every(validId) || !Object.keys(data.jobs).every(validId)) throw new Error(t('Respuesta inválida del servidor.'));
        for (const result of Object.values(data.results)) {
            if (action === 'delete' ? typeof result !== 'boolean'
                : !record(result) || typeof result.success !== 'boolean' || (result.error !== null && typeof result.error !== 'string')
                    || (Object.prototype.hasOwnProperty.call(result, 'attempted') && typeof result.attempted !== 'boolean'))
                throw new Error(t('Respuesta inválida del servidor.'));
        }
        for (const job of Object.values(data.jobs)) {
            if (!record(job) || (job.error != null && typeof job.error !== 'string')
                || (job.canDelete !== undefined && typeof job.canDelete !== 'boolean')
                || (job.blockedBy != null && typeof job.blockedBy !== 'string')) throw new Error(t('Respuesta inválida del servidor.'));
        }
        const summary = data.summary;
        if (action === 'delete') {
            if (!data.requested.every(key => Object.prototype.hasOwnProperty.call(data.results, key))) throw new Error(t('Respuesta inválida del servidor.'));
            const deleted = data.requested.filter(key => data.results[key] === true).length;
            if (summary.deleted !== deleted || summary.refused !== data.requested.length - deleted) throw new Error(t('Respuesta inválida del servidor.'));
        } else if (Object.values(data.results).every(result => Object.prototype.hasOwnProperty.call(result, 'attempted'))) {
            const attempts = Object.values(data.results).filter(result => result.attempted);
            const succeeded = attempts.filter(result => result.success).length;
            if (summary.attempted !== attempts.length || summary.succeeded !== succeeded || summary.failed !== attempts.length - succeeded)
                throw new Error(t('Respuesta inválida del servidor.'));
        }
        const parts = [action === 'run'
            ? t('{attempted} intentados · {succeeded} completados · {pending} pendientes · {failed} con error.', summary)
            : t('{deleted} eliminados · {refused} conservados.', summary)];
        const keys = [...new Set([...data.requested, ...Object.keys(data.results)])];
        const connectionHelp = new Set();
        let details = 0;
        for (const key of keys) {
            const result = data.results[key];
            const job = data.jobs[key] || {};
            if (action === 'run' && result?.success === true) {
                if (result.attempted === false && details++ < 8) parts.push(t('{job}: ya estaba completado.', { job: key }));
                continue;
            }
            if (action === 'delete' && result === true) continue;
            const note = jobReason(job, action === 'delete');
            const reason = note ? t(note[0], note[1]) : t(action === 'delete' ? 'No se pudo eliminar el trabajo.'
                : result && result.attempted !== false ? 'El intento falló.' : 'Pendiente; no se intentó.');
            const lastError = action === 'run' && typeof result?.error === 'string' && result.error ? result.error : job.error;
            const detail = t('{job}: {reason}', { job: key, reason })
                + (lastError ? ' ' + t('Último error: {message}', { message: String(lastError).slice(0, 500) }) : '');
            if (details++ < 8) parts.push(detail);
            const help = jobConnectionHelp(lastError);
            if (help && !connectionHelp.has(help)) { connectionHelp.add(help); parts.push(t(help)); }
        }
        if (details > 8) parts.push(t('Otros {count} trabajos requieren atención.', { count: details - 8 }));
        const error = Boolean(response.error || ['failed', 'error'].includes(response.status)
            || summary.failed || summary.pending || summary.refused);
        if (error && !summary.failed && !summary.pending && !summary.refused) parts.push(t('No se pudo confirmar el resultado de la operación.'));
        return { data, message: parts.join(' '), kind: error ? 'error' : 'success' };
    }
    function retainJobDiagnostics(feedback) {
        for (const [key, job] of Object.entries(feedback.data.jobs)) {
            if ((feedback.data.operation === 'delete' && feedback.data.results[key] === true)
                || ['succeeded', 'cleaned', 'deleted', 'cancelled', 'superseded', 'missing'].includes(job.state)) delete jobsCache[key];
            else jobsCache[key] = { ...jobsCache[key], ...job };
        }
        renderJobsTable(jobsCache);
    }
    for (const [id, action] of [['btn-run-jobs', 'run'], ['btn-delete-jobs', 'delete']]) bind(id, async () => {
        if (jobsActionPending) return;
        const selected = Array.from(document.querySelectorAll('.job-checkbox:checked'), checkbox => checkbox.value);
        if (!selected.length) throw new Error(t('Selecciona al menos un trabajo.'));
        jobsActionPending = true;
        jobsRequestSequence++;
        clearJobsRefreshTimer();
        updateJobsRefreshStatus();
        const generation = jobsViewGeneration;
        try {
            if (action === 'delete' && !await confirmAction(t('¿Eliminar {count} trabajos pendientes? Se detendrán sus reintentos sin cambiar el router. Se conservará información para una futura sincronización. Las retiradas seleccionadas también cancelarán sus trabajos dependientes.', { count: selected.length }))) return;
            if (jobsRefreshInFlight) { try { await jobsRefreshInFlight; } catch (ignored) {} }
            let result;
            let feedback;
            let operationError;
            try {
                const response = await api.send('update', 'jobs', action, selected);
                feedback = jobOperationFeedback(response, action);
                if (!feedback) result = checked(response);
            }
            catch (error) { operationError = error; }
            // A failed attempt can change job.error, attempts and retry state. Read them
            // before reporting the operation outcome so the table explains what happened.
            try { await renderJobs({ duringAction: true }); }
            catch (refreshError) {
                if (feedback) {
                    if (jobsVisible() && generation === jobsViewGeneration) retainJobDiagnostics(feedback);
                    feedback.message += ' ' + t('No se pudo actualizar la lista de trabajos. Recarga la vista para ver el último estado.');
                    feedback.kind = 'error';
                } else {
                    if (!operationError) throw refreshError;
                    throw new Error(t(operationError.message || 'No se pudo completar la operación.') + ' '
                        + t('No se pudo actualizar la lista de trabajos. Recarga la vista para ver el último estado.'));
                }
            }
            if (feedback) { notify(feedback.message, feedback.kind); return; }
            if (operationError) throw operationError;
            if (action === 'run') completed(result, t('Trabajos procesados.'));
        } finally {
            jobsActionPending = false;
            updateJobsRefreshStatus();
            if (jobsVisible() && generation !== jobsViewGeneration) refreshJobsQuietly();
            else scheduleJobsRefresh();
        }
    });

    function formatDate(value) {
        if (!value || value === 'never') return t('Nunca');
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? t('Fecha desconocida') : date.toLocaleString(i18n?.getLanguage());
    }
    async function renderSystem() {
        const system = checked(await api.send('list', 'sys', 'get')).data || {};
        byId('system-info-container').innerHTML = [
            ['Versión del plugin', system.app_version], ['Versión de PHP', system.php_version],
            ['Última caché', system.last_cache, true], ['Próxima caché', system.next_cache, true]
        ].map(([label, value, date]) => `<div class="system-info"><div class="text-muted" data-i18n="${html(label)}">${html(t(label))}</div><div${date ? ` data-date="${html(value || '')}"` : ''}>${html(date ? formatDate(value) : value)}</div></div>`).join('');
        const backups = checked(await api.getBackups()).data || [];
        const grid = byId('backups-grid');
        grid.replaceChildren();
        if (!backups.length) grid.innerHTML = `<div class="empty-state" data-i18n="No hay respaldos.">${html(t('No hay respaldos.'))}</div>`;
        backups.forEach(backup => {
            if (!/^backup-[0-6]$/.test(backup.name)) return;
            const card = document.createElement('div');
            card.className = 'backup-card';
            const date = document.createElement('span');
            date.textContent = formatDate(backup.date); date.dataset.date = backup.date || '';
            const controls = document.createElement('div');
            controls.className = 'row-actions';
            controls.appendChild(actionButton('Restaurar respaldo', 'refresh-cw', async () => {
                if (!await confirmAction(t('¿Restaurar {name}?', { name: backup.name }))) return;
                completed(await api.restoreBackup(backup.name), t('Copia de seguridad restaurada.'));
                await renderSystem();
            }));
            const download = document.createElement('a');
            download.className = 'btn btn-secondary row-action';
            download.href = api.downloadUrl(backup.name);
            download.download = backup.name + '.db';
            download.title = t('Descargar respaldo');
            download.setAttribute('aria-label', t('Descargar respaldo'));
            download.setAttribute('data-i18n-title', 'Descargar respaldo');
            download.setAttribute('data-i18n-aria-label', 'Descargar respaldo');
            download.innerHTML = '<i data-feather="download"></i>';
            controls.appendChild(download);
            card.append(date, controls);
            grid.appendChild(card);
        });
        PanelUtils.replaceIcons();
    }
    bind('btn-backup', async () => { completed(await api.createBackup(), t('Respaldo creado.')); await renderSystem(); });
    bind('btn-rebuild', async () => {
        if (!await confirmAction(t('¿Reconstruir los datos del plugin y sincronizar todos los servicios administrados?'))) return;
        completed(await api.rebuild(), t('Reconstrucción y sincronización completadas.'));
        await renderSystem();
    });
    bind('btn-cache', async () => { completed(await api.rebuildCache(), t('Caché reconstruida.')); await renderSystem(); });
    bind('btn-log-clear', async () => { completed(await api.clearLogs(), t('Registro limpiado.')); });
    byId('btn-upload').addEventListener('click', () => {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.db,.sqlite';
        input.addEventListener('change', () => withLoader(async () => {
            const file = input.files[0];
            if (!file || !await confirmAction(t('¿Subir y restaurar esta base de datos del plugin?'))) return;
            completed(await api.uploadBackup(file), t('Archivo validado y restaurado.'));
            await renderSystem();
        }, byId('btn-upload')));
        input.click();
    });

    function updateTlsControls() {
        const ssl = byId('dev-ssl').checked;
        byId('dev-port').placeholder = t('Predeterminado: {port}', { port: ssl ? 8729 : 8728 });
        byId('dev-allowSelfSigned').disabled = !ssl;
        if (!ssl) byId('dev-allowSelfSigned').checked = false;
    }
    byId('dev-ssl').addEventListener('change', updateTlsControls);
    function openDeviceModal(device) {
        byId('device-form').reset();
        byId('dev-id').value = device?.id ?? '';
        translatedText(byId('modal-title'), device ? 'Editar dispositivo' : 'Agregar dispositivo');
        byId('dev-password').value = '';
        byId('dev-password').required = !device;
        for (const field of ['name', 'ip', 'user', 'port', 'pool', 'pool6', 'pfxLength']) byId('dev-' + field).value = device?.[field] ?? '';
        byId('dev-ssl').checked = device ? Boolean(PanelUtils.flag(device.ssl ?? (Number(device.port) === 8729 ? 1 : 0))) : false;
        byId('dev-allowSelfSigned').checked = Boolean(PanelUtils.flag(device?.allowSelfSigned));
        updateTlsControls();
        deviceModal.style.display = 'flex';
        byId('dev-name').focus();
    }
    bind('save-device-btn', async () => {
        const form = byId('device-form');
        if (!form.reportValidity()) return;
        const input = Object.fromEntries(new FormData(form).entries());
        delete input.qos;
        for (const field of ['ssl', 'allowSelfSigned']) input[field] = byId('dev-' + field).checked ? 1 : 0;
        const action = input.id ? 'edit' : 'insert';
        const data = PanelUtils.devicePayload(input, action);
        const response = checked(await api.saveDevice(action, data));
        if (api.isAccepted(response)) completed(response, t('Dispositivo guardado.'));
        closeModals();
        await renderDevices();
    });

    async function viewOrphans(id) {
        const orphans = checked(await api.send('list', 'orphans', 'get', { id: Number(id) })).data || [];
        const tbody = document.querySelector('#orphan-table tbody');
        tbody.replaceChildren();
        if (!orphans.length) tbody.innerHTML = `<tr><td colspan="3" class="empty-state" data-i18n="No hay cuentas huérfanas.">${html(t('No hay cuentas huérfanas.'))}</td></tr>`;
        orphans.forEach(orphan => {
            const row = document.createElement('tr');
            row.innerHTML = `<td>${html(orphan.name || '-')}</td><td>${html(orphan.mac || '-')}</td><td>${html(orphan.address || '-')}${orphan.address6 ? `<br>${html(orphan.address6)}` : ''}</td>`;
            tbody.appendChild(row);
        });
        orphanModal.style.display = 'flex';
    }

    const editablePlanFields = ['ratio', 'uploadOverride', 'downloadOverride', 'limitUpload', 'limitDownload',
        'burstUpload', 'burstDownload', 'threshUpload', 'threshDownload', 'timeUpload', 'timeDownload', 'priorityUpload', 'priorityDownload'];
    const numericResetValues = { ratio: '1', uploadOverride: '', downloadOverride: '', limitUpload: '0', limitDownload: '0',
        burstUpload: '0', burstDownload: '0', threshUpload: '0', threshDownload: '0', timeUpload: '1', timeDownload: '1', priorityUpload: '8', priorityDownload: '8' };

    function numericFieldLabel(field) {
        const labels = { ratio: 'Ratio de contención', uploadOverride: 'Subida (Mbps)', downloadOverride: 'Bajada (Mbps)',
            uploadSpeed: 'Velocidad de UISP (subida)', downloadSpeed: 'Velocidad de UISP (bajada)',
            limitUpload: 'Límite subida (%)', limitDownload: 'Límite bajada (%)',
            priorityUpload: 'Subida (1–8)', priorityDownload: 'Bajada (1–8)' };
        if (labels[field]) return t(labels[field]);
        const direction = field.endsWith('Upload') ? t('Subida') : t('Bajada');
        if (field.startsWith('burst')) return direction + ' · ' + t('Incremento de burst (%)');
        if (field.startsWith('thresh')) return direction + ' · ' + t('Reducción del umbral (%)');
        if (field.startsWith('time')) return direction + ' · ' + t('Ventana de promedio (s)');
        return field;
    }

    function updatePlanNumericWarning() {
        const ratioHelp = byId('plan-ratio-help');
        if (ratioHelp) translatedText(ratioHelp, planContentionDisabled
            ? planNumericDraft.has('ratio')
                ? planNumericDraft.get('ratio').resolved
                    ? 'La contención está desactivada. La corrección de este valor se guardará con el plan.'
                    : 'La contención está desactivada, pero este valor inválido debe corregirse para poder guardar el plan.'
                : 'La contención está desactivada en Configuración. Este valor no se aplica y se conservará al guardar.'
            : 'Introduce un entero desde 1. Se usa para calcular el límite compartido de este plan en cada router; 1 no reduce ese límite.');
        const warning = byId('plan-numeric-warning');
        const list = byId('plan-numeric-issues');
        if (warning) warning.hidden = planNumericDraft.size === 0;
        list?.replaceChildren();
        let editable = false;
        let sourceInvalid = false;
        for (const [field, entry] of planNumericDraft) {
            const control = editablePlanFields.includes(field) ? byId('plan-' + field) : null;
            const invalid = !entry.resolved;
            if (control) {
                control.setCustomValidity?.(invalid ? t('Corrige este valor o restablece el ajuste antes de guardar.') : '');
                control.setAttribute('aria-invalid', String(invalid));
                editable = editable || invalid;
            } else sourceInvalid = sourceInvalid || invalid;
            if (list) {
                const row = document.createElement('li');
                const reasons = { non_finite: 'Número no finito', non_numeric: 'No es un número', empty: 'Valor vacío',
                    invalid_integer: 'Se requiere un entero', out_of_range: 'Fuera del rango permitido', calculation_non_finite: 'El cálculo excede el rango permitido' };
                const reason = t(reasons[entry.issue.reason] ?? String(entry.issue.reason ?? 'Valor inválido').slice(0, 80));
                translatedText(row, '{field} ({key}): {value} · {status}.', {
                    field: numericFieldLabel(field), key: field,
                    value: entry.issue.value === null || entry.issue.value === undefined ? '—' : String(entry.issue.value).slice(0, 100),
                    status: entry.resolved ? t('Corregido en el borrador') : t('Valor inválido') + ' · ' + reason
                });
                list.appendChild(row);
            }
        }
        const sourceHint = byId('plan-numeric-source-hint');
        if (sourceHint) sourceHint.hidden = !sourceInvalid;
        const reset = byId('plan-reset-invalid');
        if (reset) {
            reset.hidden = !editable;
            translatedText(reset, 'Restablecer ajustes inválidos');
        }
    }

    function resolvePlanNumericField(field) {
        const entry = planNumericDraft.get(field);
        if (!entry || entry.resolved) return;
        entry.resolved = true;
        updatePlanNumericWarning();
    }

    for (const field of editablePlanFields) {
        for (const event of ['input', 'change']) byId('plan-' + field)?.addEventListener(event, () => {
            resolvePlanNumericField(field);
            updateBurstPreview();
        });
    }
    byId('plan-reset-invalid')?.addEventListener('click', event => {
        event.preventDefault();
        for (const [field, entry] of planNumericDraft) {
            const control = editablePlanFields.includes(field) ? byId('plan-' + field) : null;
            if (!entry.resolved && control) {
                control.value = numericResetValues[field];
                entry.resolved = true;
            }
        }
        updatePlanNumericWarning();
        updateBurstPreview();
    });

    function planBurstInput() {
        return Object.fromEntries(['Upload', 'Download'].flatMap(direction =>
            ['burst', 'thresh', 'time'].map(field => [field + direction, byId('plan-' + field + direction)?.value ?? ''])));
    }

    function burstHelpersAvailable() {
        return ['burstPresets', 'burstPresetValues', 'burstPresetMatch', 'burstPreview']
            .every(name => typeof PanelUtils[name] === 'function');
    }

    function burstEditorAvailable() {
        return burstHelpersAvailable() && ['none', 'soft', 'balanced', 'fast'].every(id => byId('plan-preset-' + id))
            && ['preset-status', 'preset-description', 'preview-upload', 'preview-download'].every(id => byId('plan-burst-' + id));
    }

    function updateBurstPreview() {
        if (!currentPlan || !burstHelpersAvailable()) return;
        const input = planBurstInput();
        const unresolved = [...planNumericDraft].filter(([, entry]) => !entry.resolved);
        const selected = unresolved.length ? null : PanelUtils.burstPresetMatch(input);
        const preset = PanelUtils.burstPresets().find(item => item.id === selected);
        const status = byId('plan-burst-preset-status');
        const description = byId('plan-burst-preset-description');
        if (status) status.textContent = unresolved.length ? t('Valores inválidos') : preset ? preset.label : t('Personalizado');
        if (description) description.textContent = unresolved.length ? t('Corrige los valores señalados antes de guardar.') : preset ? preset.description : t('Valores ajustados manualmente; guarda para aplicarlos.');
        for (const item of PanelUtils.burstPresets()) {
            const button = byId('plan-preset-' + item.id);
            button?.setAttribute('aria-pressed', String(item.id === selected));
            button?.classList.toggle('active', item.id === selected);
        }
        const format = value => value.toLocaleString(i18n?.getLanguage() || 'es', { maximumSignificantDigits: 6 });
        for (const direction of ['Upload', 'Download']) {
            const label = direction === 'Upload' ? t('Subida') : t('Bajada');
            const output = byId('plan-burst-preview-' + direction.toLowerCase());
            if (!output) continue;
            if (unresolved.some(([field]) => field.endsWith(direction) || field === direction.toLowerCase() + 'Speed' || field === direction.toLowerCase() + 'Override')) {
                output.textContent = t('{direction}: corrige los valores inválidos para ver el resultado.', { direction: label });
                continue;
            }
            try {
                const preview = PanelUtils.burstPreview({
                    base: currentPlan[direction.toLowerCase() + 'Speed'] ?? 0,
                    override: byId('plan-' + direction.toLowerCase() + 'Override').value,
                    increment: input['burst' + direction], reduction: input['thresh' + direction], window: input['time' + direction]
                });
                const normal = t('{direction}: normal {normal} Mbps.', { direction: label, normal: format(preview.normal) });
                if (preview.normal <= 0) output.textContent = t('{direction}: sin velocidad base positiva para estimar el burst.', { direction: label });
                else if (!preview.enabled) output.textContent = normal + t(' Burst desactivado.');
                else {
                    output.textContent = normal + t(' Pico {burst} Mbps · Umbral {threshold} Mbps · Ventana {window} s.', { burst: format(preview.burst), threshold: format(preview.threshold), window: format(preview.window) })
                        + (preview.usable ? t(' Inicio teórico tras reposo: {seconds} s de pico, sin garantía.', { seconds: format(preview.initialSeconds) })
                            : t(' Sin velocidad, umbral o ventana válidos no funciona como aceleración.'));
                }
            } catch (error) { output.textContent = t('{direction}: {message}', { direction: label, message: error.message }); }
        }
    }

    for (const preset of typeof PanelUtils.burstPresets === 'function' ? PanelUtils.burstPresets() : []) byId('plan-preset-' + preset.id)?.addEventListener('click', event => {
        event.preventDefault();
        if (!burstHelpersAvailable()) return;
        for (const [field, value] of Object.entries(PanelUtils.burstPresetValues(preset.id))) {
            const control = byId('plan-' + field);
            if (control) {
                control.value = String(value);
                resolvePlanNumericField(field);
            }
        }
        updateBurstPreview();
    });
    function openPlanModal(plan) {
        if (planContentionDisabled === null) throw new Error(t('No se pudo comprobar la configuración de contención. Vuelve a abrir la sección Planes.'));
        currentPlan = { ...plan };
        planNumericDraft = new Map();
        for (const issue of Array.isArray(plan.numericIssues) ? plan.numericIssues : []) {
            if (issue && typeof issue.field === 'string' && !planNumericDraft.has(issue.field))
                planNumericDraft.set(issue.field, { issue, resolved: false });
        }
        byId('plan-form').reset();
        for (const field of editablePlanFields) {
            byId('plan-' + field)?.setCustomValidity?.('');
            byId('plan-' + field)?.setAttribute('aria-invalid', 'false');
        }
        for (const field of ['id', 'ratio', 'uploadOverride', 'downloadOverride', 'timeUpload', 'timeDownload', 'priorityUpload', 'priorityDownload']) {
            byId('plan-' + field).value = plan[field] ?? (field === 'ratio' ? 1 : '');
        }
        for (const field of ['limitUpload', 'limitDownload', 'threshUpload', 'threshDownload']) byId('plan-' + field).value = plan[field] == 100 ? 0 : plan[field] ?? 0;
        for (const field of ['burstUpload', 'burstDownload']) byId('plan-' + field).value = plan[field] == -100 ? 0 : plan[field] ?? 0;
        for (const field of planNumericDraft.keys()) if (editablePlanFields.includes(field)) byId('plan-' + field).value = '';
        byId('plan-uploadOverride').placeholder = planNumericDraft.has('uploadSpeed') ? t('UISP: valor inválido') : `UISP: ${plan.uploadSpeed ?? 0} Mbps`;
        byId('plan-downloadOverride').placeholder = planNumericDraft.has('downloadSpeed') ? t('UISP: valor inválido') : `UISP: ${plan.downloadSpeed ?? 0} Mbps`;
        const ratio = byId('plan-ratio');
        ratio.disabled = planContentionDisabled && !planNumericDraft.has('ratio');
        ratio.required = !planContentionDisabled || planNumericDraft.has('ratio');
        ratio.step = '1';
        planModal.style.display = 'flex';
        const body = planModal.querySelector('.modal-body');
        if (body) body.scrollTop = 0;
        (planContentionDisabled ? byId('plan-uploadOverride') : ratio).focus({ preventScroll: true });
        if (!burstEditorAvailable()) notify(t('Recarga el panel para ver las plantillas de burst.'), 'info');
        updatePlanNumericWarning();
        updateBurstPreview();
    }
    bind('save-plan-btn', async () => {
        const form = byId('plan-form');
        if ([...planNumericDraft].some(([field, entry]) => !entry.resolved && !editablePlanFields.includes(field))) {
            notify(t('La velocidad original no es válida. Corrígela en UISP y vuelve a cargar los planes.'), 'error');
            return;
        }
        if (!form.reportValidity()) return;
        const data = PanelUtils.planPayload(Object.fromEntries(new FormData(form).entries()));
        const response = checked(await api.savePlan(data));
        completed(response, t('Plan guardado. Los cambios se aplicarán automáticamente; revisa el avance en Trabajos.'));
        closeModals();
        await renderPlans();
    });

    i18n?.onChange(async () => {
        const sequence = ++languageLoad;
        // Update text in place. Inputs, focus, scroll, selected jobs and drafts survive language changes.
        updateConfigSaveStatus();
        updatePlanNumericWarning();
        updateBurstPreview();
        updateJobsRefreshStatus();
        for (const direction of ['upload', 'download']) if (planNumericDraft.has(direction + 'Speed'))
            byId('plan-' + direction + 'Override').placeholder = t('UISP: valor inválido');
        byId('dev-port').placeholder = t('Predeterminado: {port}', { port: byId('dev-ssl').checked ? 8729 : 8728 });
        for (const node of document.querySelectorAll('[data-date]')) node.textContent = formatDate(node.dataset.date);
        if (!configRendered) return;
        try {
            const fields = await loadConfigFields();
            if (sequence !== languageLoad) return;
            const byName = new Map(Object.values(fields).map(field => [field.name, field]));
            for (const group of document.querySelectorAll('.config-field[data-field-name]')) {
                const name = group.dataset.fieldName;
                const local = localAttributeFields.find(field => field.name === name);
                const field = byName.get(name) || (local && { ...local, label: t(local.label), description: t(local.description) });
                if (!field) continue;
                const label = group.querySelector('.field-label-text');
                if (label) label.textContent = field.label;
                const description = group.querySelector('.field-help-copy');
                if (description) description.textContent = field.description || '';
                const help = group.querySelector('.field-help summary');
                if (help) help.setAttribute('aria-label', t('Ayuda sobre {label}', { label: field.label }));
                const input = group.querySelector('input.form-control');
                if (input) input.placeholder = field.hint || '';
                const checkbox = group.querySelector('input[type="checkbox"]');
                const status = group.querySelector('.switch-status');
                if (status && checkbox) status.textContent = t(checkbox.checked ? 'Activado' : 'Desactivado');
            }
        } catch (error) { if (sequence === languageLoad) notify(t('Error: {message}', { message: error.message }), 'error'); }
    });

    PanelUtils.replaceIcons();
    switchView('devices', 'Dispositivos administrados');
});
