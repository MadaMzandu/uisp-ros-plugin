/** Helpers compartidos por el panel y su contrato HTTP. */
const PanelUtils = {
    t(source, params = {}) {
        return globalThis.PanelI18n?.t?.(source, params) ?? source.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match);
    },
    escapeHTML(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[char]));
    },
    number(value, label, { min = 0, max = Infinity, integer = false, nullable = false } = {}) {
        if (value === '' || value === null || value === undefined) {
            if (nullable) return null;
            throw new Error(this.t('{label}: introduce un número.', { label: this.t(label) }));
        }
        const numeric = Number(value);
        if (!Number.isFinite(numeric) || numeric < min || numeric > max || (integer && !Number.isInteger(numeric))) {
            throw new Error(this.t('{label}: valor fuera del rango permitido.', { label: this.t(label) }));
        }
        return numeric;
    },
    flag(value) {
        return value === true || value === 1 || value === '1' || value === 'true' || value === 'on' ? 1 : 0;
    },
    burstPresets() {
        return [
            { id: 'none', label: 'Sin burst', description: 'Mantiene la velocidad normal, sin aceleración.', increment: 0, reduction: 0, window: 0 },
            { id: 'soft', label: 'Suave', description: 'Aumento moderado para transferencias breves.', increment: 25, reduction: 20, window: 8 },
            { id: 'balanced', label: 'Equilibrado', description: 'Más margen de velocidad después de períodos con poco tráfico.', increment: 50, reduction: 25, window: 12 },
            { id: 'fast', label: 'Rápido', description: 'Un pico mayor cuando la red tiene capacidad disponible.', increment: 100, reduction: 30, window: 10 }
        ].map(preset => ({ ...preset, label: this.t(preset.label), description: this.t(preset.description) }));
    },
    burstPresetValues(id) {
        const preset = this.burstPresets().find(item => item.id === id);
        if (!preset) throw new Error(this.t('Plantilla de burst desconocida.'));
        return Object.fromEntries(['Upload', 'Download'].flatMap(direction => [
            ['burst' + direction, preset.increment], ['thresh' + direction, preset.reduction], ['time' + direction, preset.window]
        ]));
    },
    burstPresetMatch(input) {
        if (Number(input.burstUpload ?? 0) === 0 && Number(input.burstDownload ?? 0) === 0) return 'none';
        return this.burstPresets().find(preset => Object.entries(this.burstPresetValues(preset.id))
            .every(([field, value]) => Number(input[field] ?? 0) === value))?.id || null;
    },
    burstPreview({ base, override, increment, reduction, window }) {
        const explicit = String(override ?? '').trim();
        const normal = this.number(explicit === '' ? base ?? 0 : explicit, 'Velocidad normal');
        const extra = this.number(increment === '' || increment == null ? 0 : increment, 'Incremento de burst');
        const reduced = this.number(reduction === '' || reduction == null ? 0 : reduction, 'Reducción del umbral', { max: 100 });
        const seconds = this.number(window === '' || window == null ? 1 : window, 'Ventana de burst');
        const enabled = extra > 0;
        const burst = enabled ? normal * (1 + extra / 100) : 0;
        // En el contrato existente, 0 en el formulario desactiva el umbral.
        const threshold = reduced === 0 ? 0 : normal * (1 - reduced / 100);
        if (!Number.isFinite(burst) || !Number.isFinite(threshold)) throw new Error(this.t('Burst: las velocidades calculadas exceden el rango permitido.'));
        const usable = enabled && normal > 0 && threshold > 0 && seconds > 0;
        return { normal, burst, threshold, window: seconds, enabled, usable,
            initialSeconds: usable ? (threshold / burst) * seconds : null };
    },
    devicePayload(input, action) {
        const data = { ...input, type: 'mikrotik' };
        for (const field of ['name', 'ip', 'user']) {
            data[field] = String(data[field] ?? '').trim();
            if (!data[field]) throw new Error(this.t('Completa nombre, dirección y usuario del dispositivo.'));
        }
        if (action === 'edit') {
            data.id = this.number(data.id, 'ID', { min: 1, integer: true });
            if (!data.password) delete data.password;
        } else {
            delete data.id;
            if (!data.password) throw new Error(this.t('Introduce la contraseña del dispositivo.'));
        }
        const port = String(data.port ?? '').trim();
        if (port) data.port = this.number(port, 'Puerto', { min: 1, max: 65535, integer: true });
        else if (action === 'insert') data.port = this.flag(data.ssl) ? 8729 : 8728;
        else delete data.port;
        const prefix = String(data.pfxLength ?? '').trim().replace(/^\//, '');
        data.pfxLength = this.number(prefix, 'Prefijo IPv6', { max: 128, integer: true, nullable: true });
        for (const field of ['qos', 'ssl', 'allowSelfSigned']) {
            if (Object.prototype.hasOwnProperty.call(data, field)) data[field] = this.flag(data[field]);
        }
        if (data.allowSelfSigned && !data.ssl) throw new Error(this.t('Activa TLS para aceptar un certificado autofirmado.'));
        return data;
    },
    planPayload(input) {
        const data = { ...input };
        data.id = this.number(data.id, 'ID', { min: 1, integer: true });
        // Disabled controls are absent from FormData. Leave a stored ratio
        // untouched when global contention is disabled instead of inventing it.
        if (Object.prototype.hasOwnProperty.call(input, 'ratio')) {
            data.ratio = this.number(data.ratio, 'Contención', { min: 1, integer: true });
        }
        for (const field of ['uploadOverride', 'downloadOverride', 'limitUpload', 'limitDownload',
            'burstUpload', 'burstDownload', 'threshUpload', 'threshDownload', 'timeUpload', 'timeDownload']) {
            const max = ['limitUpload', 'limitDownload', 'threshUpload', 'threshDownload'].includes(field) ? 100 : Infinity;
            data[field] = this.number(data[field], field, { max, nullable: true });
        }
        for (const field of ['priorityUpload', 'priorityDownload']) {
            data[field] = this.number(data[field], field, { min: 1, max: 8, integer: true, nullable: true });
        }
        for (const field of ['limitUpload', 'limitDownload', 'threshUpload', 'threshDownload']) {
            if (data[field] === 0) data[field] = 100;
        }
        for (const field of ['burstUpload', 'burstDownload']) {
            if (data[field] === 0) data[field] = -100;
        }
        return data;
    },
    replaceIcons() {
        // SVG locales: el panel funciona aunque el servidor no tenga acceso a un CDN.
        const paths = {
            server: '<rect x="2" y="3" width="20" height="7" rx="2"/><rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 6h.01M6 17h.01"/>',
            box: '<path d="m12 3 9 5v8l-9 5-9-5V8zM3 8l9 5 9-5M12 13v8"/>',
            settings: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3M5 5l2 2m10 10 2 2M5 19l2-2M17 7l2-2"/>',
            list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
            cpu: '<rect x="5" y="5" width="14" height="14" rx="2"/><path d="M9 1v4m6-4v4M9 19v4m6-4v4M1 9h4m-4 6h4M19 9h4m-4 6h4"/>',
            search: '<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>',
            play: '<path d="m6 3 15 9-15 9z"/>',
            'trash-2': '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/>',
            x: '<path d="m6 6 12 12M6 18 18 6"/>',
            plus: '<path d="M12 5v14M5 12h14"/>',
            users: '<circle cx="9" cy="8" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2M17 4a4 4 0 0 1 0 8M22 21v-2a7 7 0 0 0-3-6"/>',
            'edit-2': '<path d="m16 3 5 5L8 21H3v-5zM14 5l5 5"/>',
            'refresh-cw': '<path d="M21 3v6h-6M3 21v-6h6M20 9a8 8 0 0 0-14-4M4 15a8 8 0 0 0 14 4"/>',
            'x-circle': '<circle cx="12" cy="12" r="10"/><path d="m8 8 8 8m-8 0 8-8"/>',
            download: '<path d="M3 16v5h18v-5M12 3v12m-5-5 5 5 5-5"/>',
            'alert-triangle': '<path d="m12 3 10 18H2zM12 9v5m0 3h.01"/>'
        };
        document.querySelectorAll('i[data-feather]').forEach(icon => {
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            for (const [key, value] of Object.entries({ width: '18', height: '18', viewBox: '0 0 24 24',
                fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round',
                'stroke-linejoin': 'round', 'aria-hidden': 'true' })) svg.setAttribute(key, value);
            svg.innerHTML = paths[icon.dataset.feather] || paths.settings;
            svg.style.cssText = icon.style.cssText;
            icon.replaceWith(svg);
        });
    }
};

class ApiService {
    constructor(endpoint) {
        const current = new URL(window.location.href);
        if (/\/public\/panel_v2\/(?:index\.php)?$/.test(current.pathname)) {
            current.href = new URL('../../public.php', document.baseURI).href;
        }
        this.endpoint = endpoint || current.pathname;
        this.recovery = null;
        this.recoveryListeners = new Set();
        this.mutationListeners = new Set();
        const bootstrap = document.querySelectorAll('#network-recovery-state')[0];
        if (bootstrap) {
            try { this.setRecovery(JSON.parse(bootstrap.textContent)); }
            catch (_) { /* A malformed optional diagnostic must not break panel navigation. */ }
        }
    }
    setRecovery(state) {
        if (state?.readOnly !== true) return;
        this.recovery = { ...this.recovery, ...state };
        for (const listener of this.recoveryListeners) listener(this.recovery);
    }
    onRecovery(listener) {
        this.recoveryListeners.add(listener);
    }
    onMutation(listener) { this.mutationListeners.add(listener); }
    async mutatingRequest(options) {
        for (const listener of this.mutationListeners) listener(true);
        try { return await this.request(options); }
        finally { for (const listener of this.mutationListeners) listener(false); }
    }
    blockedWrite() {
        return Promise.resolve({ status: 'failed', error: true,
            message: PanelUtils.t('Los cambios están pausados hasta corregir las IP duplicadas. Puedes seguir consultando el plugin.'),
            recovery: this.recovery });
    }
    blockedRouterRead() {
        return Promise.resolve({ status: 'failed', error: true,
            message: PanelUtils.t('Las consultas al router estarán disponibles al completar la actualización.'),
            recovery: this.recovery });
    }
    async request(options) {
        try {
            const response = await fetch(this.endpoint, { credentials: 'same-origin', ...options });
            let result;
            try { result = await response.json(); }
            catch (_) { throw new Error(PanelUtils.t('Respuesta inválida del servidor (HTTP {status}).', { status: response.status })); }
            if (!result || typeof result !== 'object' || Array.isArray(result)) throw new Error(PanelUtils.t('Respuesta inválida del servidor.'));
            if (result.recovery?.readOnly === true) this.setRecovery(result.recovery);
            if (!response.ok) {
                // A valid failure envelope may contain the counts and service
                // diagnostics for a partially applied batch, including HTTP 502.
                if (['failed', 'error'].includes(result.status) && result.error === true
                    && typeof result.message === 'string' && result.data !== null && typeof result.data === 'object')
                    return { ...result, error: true };
                throw new Error(PanelUtils.t(result.message || 'Error HTTP {status}.', { status: response.status }));
            }
            if (result.error || ['failed', 'error'].includes(result.status)) return { ...result, error: true };
            if (!['ok', 'accepted'].includes(result.status)) throw new Error(PanelUtils.t('Estado de respuesta desconocido.'));
            return result;
        } catch (error) {
            return { status: 'failed', error: true, message: error.message };
        }
    }
    send(changeType, target, action = 'list', data = {}) {
        const cleanup = changeType === 'cleanup' && target === 'network_conflicts' && ['preview', 'apply'].includes(action);
        const crmRepair = ['cleanup', 'resolve'].includes(changeType) && target === 'crm_network_conflicts' && ['preview', 'apply'].includes(action);
        const localRepair = changeType === 'repair' && target === 'network_repair' && ['preview', 'apply'].includes(action);
        if (changeType !== 'list' && !cleanup && !crmRepair && !localRepair && this.recovery?.readOnly === true) return this.blockedWrite();
        if (target === 'orphans' && this.recovery?.readOnly === true) return this.blockedRouterRead();
        const mutate = changeType === 'update' || action === 'apply' && ['cleanup', 'resolve', 'repair'].includes(changeType);
        return (mutate ? this.mutatingRequest.bind(this) : this.request.bind(this))({ method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ changeType, target, action, data }) }).then(response => {
                if (changeType === 'list' && target === 'network_conflicts' && !response.error && response.recovery?.readOnly === true)
                    this.setRecovery({ ...response.recovery, details: response.data });
                return response;
            });
    }
    uploadBackup(file) {
        if (this.recovery?.readOnly === true) return this.blockedWrite();
        const body = new FormData();
        body.append('backup', file);
        return this.mutatingRequest({ method: 'POST', body });
    }
    downloadUrl(name) {
        if (name !== 'network-conflicts' && !/^backup-[0-6]$/.test(name)
            && !/^network-cleanup-\d{8}T\d{6}Z-[a-f0-9]{32}\.sqlite$/.test(name)) throw new Error(PanelUtils.t('Nombre de respaldo inválido.'));
        const url = new URL(this.endpoint, window.location.href);
        url.search = '';
        url.searchParams.set('download', name);
        return url.href;
    }
    isAccepted(response) { return response.accepted === true || response.status === 'accepted'; }
    getDevices() { return this.send('list', 'devices'); }
    saveDevice(action, data) { return this.send('update', 'devices', action, data); }
    getPlans() { return this.send('list', 'plans'); }
    savePlan(data) { return this.send('update', 'plans', 'edit', data); }
    getConfig() { return this.send('list', 'config'); }
    saveConfig(data) { return this.send('update', 'config', 'edit', data); }
    getFields(language) { return this.send('list', 'messages', 'list', language ? { language } : {}); }
    getJobs() { return this.send('list', 'jobs'); }
    getJobsStatus() { return this.send('list', 'jobs_status'); }
    getInvalidNetworkReservations() { return this.send('list', 'network_repair'); }
    previewNetworkRepair(selection) { return this.send('repair', 'network_repair', 'preview', { selection }); }
    applyNetworkRepair(previewToken) { return this.send('repair', 'network_repair', 'apply', { previewToken }); }
    getNetworkConflicts(data = {}) { return this.send('list', 'network_conflicts', 'list', data); }
    getCrmNetworkConflicts() { return this.send('list', 'crm_network_conflicts', 'list', {}); }
    verifyCrmNetworkServices(serviceIds) { return this.send('list', 'crm_network_resolution', 'verify', { serviceIds }); }
    previewCrmNetworkChange(data) { return this.send('resolve', 'crm_network_conflicts', 'preview', data); }
    applyCrmNetworkChange(previewToken) { return this.send('resolve', 'crm_network_conflicts', 'apply', { previewToken }); }
    previewNetworkCleanup(serviceIds, source = 'stored') { return this.send('cleanup', source === 'crm_snapshot' ? 'crm_network_conflicts' : 'network_conflicts', 'preview', { serviceIds }); }
    applyNetworkCleanup(previewToken, serviceIds, source = 'stored') { return this.send('cleanup', source === 'crm_snapshot' ? 'crm_network_conflicts' : 'network_conflicts', 'apply', { previewToken, serviceIds }); }
    getBackups() { return this.send('list', 'backups'); }
    createBackup() { return this.send('update', 'system', 'backup'); }
    restoreBackup(name) { return this.send('update', 'system', 'restore', { name }); }
    rebuild() { return this.send('update', 'system', 'insert', { type: 'all' }); }
    rebuildCache() { return this.send('update', 'system', 'cache'); }
    clearLogs() { return this.send('update', 'system', 'log_clear'); }
}
const api = new ApiService();
