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
    const cleanupModal = byId('network-cleanup-modal');
    const crmEditModal = byId('crm-ip-edit-modal');
    const repairModal = byId('network-repair-modal');
    const jobRecoveryModal = byId('job-recovery-modal');
    const lifecycleModal = byId('service-lifecycle-modal');
    let notificationRegion = byId('notifications');
    let currentView = 'devices';
    let devicesCache = [];
    const deviceSyncs = new Map();
    let devicesRequestSequence = 0;
    let plansCache = [];
    let jobsCache = {};
    let jobReviewRequested = null;
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
    let jobsStatus = null;
    let jobsStatusFailure = false;
    let jobsStatusInFlight = null;
    let jobsStatusTimer;
    let jobsStatusStopped = false;
    let jobsStatusEpoch = 0;
    let jobsStatusRefreshRequested = false;
    let jobsStatusMutations = 0;
    let jobRecoveryReport = null;
    let jobRecoveryListInFlight = null;
    let jobRecoveryListFailure = false;
    let jobRecoveryListRefreshRequested = false;
    let jobRecoveryTarget = null;
    let jobRecoveryPreview = null;
    let jobRecoveryBusy = false;
    let jobRecoveryMode = 'resolve';
    let jobRecoveryGeneration = 0;
    let jobRecoveryFocusRequested = false;
    let lifecycleTarget = null;
    let lifecyclePreview = null;
    let lifecycleLoading = false;
    let lifecycleApplying = false;
    let lifecycleGeneration = 0;
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
    let recoveryReadOnly = false;
    let restoredRequiresReload = false;
    let recoveryDetails = null;
    let recoveryLoading = false;
    let verifyingService = null;
    let verifyingClient = null;
    let cleanupBusy = false;
    let cleanupPreview = null;
    let cleanupBoxes = [];
    let cleanupRequiresReload = false;
    let cleanupRemainingCount = null;
    let cleanupSource = 'stored';
    let cleanupFamily = null;
    let cleanupGroup = null;
    const crmLocalAssignmentProofs = new Map();
    let crmMonitorReport = null;
    let crmMonitorFailure = false;
    let crmMonitorInFlight = null;
    let crmMonitorTimer;
    let crmMonitorStopped = false;
    const crmVerifiedOwners = new Map();
    const crmVerifiedGroups = new Map();
    let crmVerifyBusy = false;
    let crmEditTarget = null;
    let crmEditPreview = null;
    let crmEditOutcome = null;
    let crmEditBusy = false;
    let crmEditGeneration = 0;
    let repairReport = null;
    let repairListInFlight = null;
    let repairListFailure = false;
    let repairTarget = null;
    let repairPreview = null;
    let repairBusy = false;
    let repairGeneration = 0;
    let repairRequiresReload = false;
    const navigationViews = new Set(['devices', 'plans', 'config', 'jobs', 'system', 'network_conflicts', 'diagnostics']);
    const navigationVersion = 1;
    const navigationScopeUrl = new URL(api.endpoint || window.location.href, window.location.href);
    const navigationScope = navigationScopeUrl.origin + navigationScopeUrl.pathname;
    const navigationStorageKey = 'ros-panel-navigation-v1:' + navigationScope;
    const viewPositions = new Map();
    let initialView = 'devices';
    let navigationReady = false;
    let navigationGeneration = 0;

    function validPosition(position) {
        if (!position || typeof position !== 'object' || Array.isArray(position)
            || !Number.isFinite(position.scrollTop) || position.scrollTop < 0 || position.scrollTop > 10000000) return null;
        const safe = { scrollTop: position.scrollTop };
        if (position.anchor) {
            const anchor = position.anchor;
            if (!Number.isSafeInteger(anchor.serviceId) || anchor.serviceId < 1 || !Number.isFinite(anchor.offset)
                || Math.abs(anchor.offset) > 100000 || !Number.isSafeInteger(anchor.occurrence) || anchor.occurrence < 0 || anchor.occurrence > 1000) return null;
            safe.anchor = { serviceId: anchor.serviceId, offset: anchor.offset, occurrence: anchor.occurrence };
            if (anchor.source !== undefined) {
                if (anchor.source !== 'crm_snapshot') return null;
                safe.anchor.source = anchor.source;
            }
        }
        if (position.focus) {
            const focus = position.focus;
            if (!Number.isSafeInteger(focus.serviceId) || focus.serviceId < 1 || !['service', 'client', 'cleanup', 'edit', 'history'].includes(focus.action)) return null;
            safe.focus = { serviceId: focus.serviceId, action: focus.action };
            if (focus.source !== undefined) {
                if (focus.source !== 'crm_snapshot') return null;
                safe.focus.source = focus.source;
            }
        }
        return safe;
    }

    function navigationStorage() { return globalThis.sessionStorage || globalThis.window?.sessionStorage; }
    function isPanelReload() {
        try {
            const timing = globalThis.performance || globalThis.window?.performance;
            const entry = timing?.getEntriesByType?.('navigation')?.[0];
            if (entry) return entry.type === 'reload';
            return timing?.navigation?.type === 1;
        } catch (_) { return false; }
    }
    // Dismissals are presentation preferences only. Persist coarse counts and
    // flags for this tab, never customer identities, addresses or poll times.
    const warningIds = ['network-warning', 'crm-network-warning', 'jobs-pending-warning'];
    const warningStorageKey = 'ros-panel-warning-dismissals-v1:' + navigationScope;
    const dismissedWarnings = new Map();
    const warningStates = new Map();
    try {
        const saved = navigationStorage()?.getItem(warningStorageKey);
        if (typeof saved === 'string' && saved.length <= 1024) {
            const value = JSON.parse(saved);
            if (value?.version === 1 && value.dismissed && typeof value.dismissed === 'object' && !Array.isArray(value.dismissed)) {
                for (const [id, signature] of Object.entries(value.dismissed))
                    if (warningIds.includes(id) && typeof signature === 'string' && /^[0-9,[\]a-z-]{1,180}$/.test(signature)) dismissedWarnings.set(id, signature);
            }
        }
    } catch (_) { /* Dismissing warnings also works without session storage. */ }
    function saveWarningDismissals() {
        try { navigationStorage()?.setItem(warningStorageKey, JSON.stringify({ version: 1, dismissed: Object.fromEntries(dismissedWarnings) })); }
        catch (_) { /* Keep the current page preference when storage is blocked. */ }
    }
    function renderWarningVisibility() {
        let hidden = 0;
        for (const [id, state] of warningStates) {
            const dismissed = dismissedWarnings.has(id);
            byId(id).style.display = state.active && !dismissed ? 'flex' : 'none';
            if (state.active && dismissed) hidden++;
        }
        if (byId('warning-controls')) byId('warning-controls').style.display = hidden ? 'block' : 'none';
        translatedText(byId('restore-warnings'), 'Mostrar avisos ({count})', { count: hidden });
    }
    function setTopWarning(id, active, values, observed = true) {
        const signature = values.join(',');
        const prior = dismissedWarnings.get(id);
        if (observed && prior !== undefined && (!active || prior !== signature)) {
            dismissedWarnings.delete(id); saveWarningDismissals();
        }
        warningStates.set(id, { active, signature }); renderWarningVisibility();
    }
    for (const id of warningIds) byId('dismiss-' + id)?.addEventListener('click', () => {
        const state = warningStates.get(id);
        if (!state?.active) return;
        dismissedWarnings.set(id, state.signature); saveWarningDismissals(); renderWarningVisibility();
        byId('restore-warnings')?.focus({ preventScroll: true });
    });
    byId('restore-warnings')?.addEventListener('click', () => {
        dismissedWarnings.clear(); saveWarningDismissals(); renderWarningVisibility();
        const visible = warningIds.find(id => warningStates.get(id)?.active);
        if (visible) byId('dismiss-' + visible)?.focus({ preventScroll: true });
    });
    try {
        // A new visit starts on Devices. Only an actual reload resumes the
        // previous section; a saved Jobs view must not become the landing page.
        const serialized = isPanelReload() ? navigationStorage()?.getItem(navigationStorageKey) : null;
        if (typeof serialized === 'string' && serialized.length <= 4096) {
            const saved = JSON.parse(serialized);
            if (saved?.version === navigationVersion && saved.scope === navigationScope && navigationViews.has(saved.view)
                && saved.positions && typeof saved.positions === 'object' && !Array.isArray(saved.positions)) {
                let valid = true;
                for (const [view, position] of Object.entries(saved.positions)) {
                    const safe = validPosition(position);
                    if (!navigationViews.has(view) || !safe) { valid = false; break; }
                    viewPositions.set(view, safe);
                }
                if (valid) initialView = saved.view;
                else viewPositions.clear();
            }
        }
    } catch (_) { /* Session storage is optional; no business state is persisted. */ }

    function scrollSurface() {
        const content = byId('content-area');
        try {
            const styles = globalThis.getComputedStyle?.(content) || globalThis.window?.getComputedStyle?.(content);
            if (styles?.overflowY === 'visible' || styles?.overflowY === 'clip') return document.scrollingElement || document.documentElement || content;
        } catch (_) { /* Cached panel markup can still use the content scroller. */ }
        return content;
    }

    function conflictOwnerNodes() {
        return ['crm-network-conflicts-body', 'crm-network-resolved-body', 'network-conflicts-body'].flatMap(id =>
            Array.from(byId(id)?.children || []).flatMap(row => Array.from(row.children[1]?.children || [])
                .filter(node => Number.isSafeInteger(Number(node.dataset?.serviceId)) && Number(node.dataset.serviceId) > 0)));
    }

    function surfaceBounds(surface) {
        if (surface === document.scrollingElement || surface === document.documentElement)
            return { top: 0, bottom: Number(window.innerHeight) || Number(surface.clientHeight) || 0 };
        return surface.getBoundingClientRect?.() || { top: 0, bottom: Number(surface.clientHeight) || 0 };
    }

    function ownerFocus(nodes) {
        const active = document.activeElement;
        for (const node of nodes) {
            const actions = Array.from(node.children).slice(1);
            const action = actions.find(button => button === active);
            if (!action) continue;
            const kind = action.className.includes('conflict-client-verify') ? 'client' : action.className.includes('conflict-cleanup') ? 'cleanup'
                : action.className.includes('conflict-edit-ip') ? 'edit' : action.className.includes('conflict-history') ? 'history' : 'service';
            return { serviceId: Number(node.dataset.serviceId), action: kind,
                ...(node.dataset.anchorSource === 'crm_snapshot' ? { source: 'crm_snapshot' } : {}) };
        }
        // Removing a focused button during a table refresh moves focus to body.
        // Retain its safe target until the replacement button can receive focus.
        const previous = viewPositions.get(currentView)?.focus;
        return active === document.body && previous && nodes.some(node => Number(node.dataset.serviceId) === previous.serviceId
            && node.dataset.anchorSource === previous.source) ? previous : undefined;
    }

    function capturePosition() {
        const surface = scrollSurface();
        const position = { scrollTop: Math.max(0, Number(surface?.scrollTop) || 0) };
        if (currentView === 'network_conflicts') {
            const nodes = conflictOwnerNodes();
            const bounds = surfaceBounds(surface);
            const visible = nodes.map((node, index) => ({ node, index, rect: node.getBoundingClientRect?.() }))
                .filter(item => item.rect && item.rect.bottom > bounds.top && item.rect.top < bounds.bottom)
                .sort((left, right) => Math.abs(left.rect.top - bounds.top) - Math.abs(right.rect.top - bounds.top));
            if (visible.length) {
                const nearest = visible[0];
                const serviceId = Number(nearest.node.dataset.serviceId);
                position.anchor = { serviceId, offset: nearest.rect.top - bounds.top,
                    occurrence: nodes.slice(0, nearest.index).filter(node => Number(node.dataset.serviceId) === serviceId
                        && node.dataset.anchorSource === nearest.node.dataset.anchorSource).length };
                if (nearest.node.dataset.anchorSource === 'crm_snapshot') position.anchor.source = 'crm_snapshot';
            }
            const focus = ownerFocus(nodes);
            if (focus) position.focus = focus;
        }
        return validPosition(position) || { scrollTop: 0 };
    }

    function restorePosition(position) {
        if (!position) return;
        const surface = scrollSurface();
        if (!surface) return;
        const max = Math.max(0, (Number(surface.scrollHeight) || 0) - (Number(surface.clientHeight) || 0));
        let top = position.scrollTop;
        const nodes = currentView === 'network_conflicts' ? conflictOwnerNodes() : [];
        if (position.anchor) {
            const matching = nodes.filter(node => Number(node.dataset.serviceId) === position.anchor.serviceId
                && node.dataset.anchorSource === position.anchor.source);
            const target = matching[position.anchor.occurrence] || matching[0];
            const rect = target?.getBoundingClientRect?.();
            if (rect) top = (Number(surface.scrollTop) || 0) + rect.top - surfaceBounds(surface).top - position.anchor.offset;
        }
        surface.scrollTop = Math.max(0, Math.min(max, top));
        if (position.focus) {
            const target = nodes.find(node => Number(node.dataset.serviceId) === position.focus.serviceId && node.dataset.anchorSource === position.focus.source);
            const className = { service: 'conflict-verify', client: 'conflict-client-verify', cleanup: 'conflict-cleanup', edit: 'conflict-edit-ip', history: 'conflict-history' }[position.focus.action];
            const button = Array.from(target?.children || []).find(node => node.className?.split(' ').includes(className));
            if (button && !button.disabled) button.focus?.({ preventScroll: true });
        }
    }

    function rememberPosition() {
        if (navigationReady) viewPositions.set(currentView, capturePosition());
    }

    function saveNavigation() {
        rememberPosition();
        const positions = Object.fromEntries([...viewPositions].map(([view, position]) => [view, validPosition(position)]));
        try { navigationStorage()?.setItem(navigationStorageKey, JSON.stringify({ version: navigationVersion, scope: navigationScope, view: currentView, positions })); }
        catch (_) { /* Blocked storage never prevents navigation or reload. */ }
    }

    function beforeTableUpdate(view) {
        return navigationReady && currentView === view ? { generation: navigationGeneration, position: capturePosition() } : null;
    }

    function afterTableUpdate(view, saved) {
        if (!saved || !navigationReady || currentView !== view || navigationGeneration !== saved.generation) return;
        viewPositions.set(view, saved.position);
        restorePosition(saved.position);
    }

    function reloadPanel() { saveNavigation(); window.location.reload(); }
    globalThis.window?.addEventListener?.('pagehide', saveNavigation);
    globalThis.window?.addEventListener?.('beforeunload', saveNavigation);
    const recoveryWriteIds = new Set(['save-config-btn', 'sync-attrs-btn', 'save-device-btn', 'save-plan-btn',
        'btn-run-jobs', 'btn-delete-jobs', 'btn-backup', 'btn-rebuild', 'btn-cache', 'btn-upload', 'btn-log-clear', 'plan-reset-invalid', 'apply-service-lifecycle']);

    function markRecoveryWrite(button) {
        if (!button) return button;
        button.dataset.recoveryWrite = 'true';
        if (recoveryReadOnly || restoredRequiresReload) {
            button.disabled = true;
            button.title = t(restoredRequiresReload ? 'Recarga el panel antes de hacer cambios sobre el estado restaurado.' : 'Los cambios están pausados hasta corregir las IP duplicadas.');
        }
        return button;
    }

    function markRecoveryRouterRead(button) {
        button.dataset.recoveryRouterRead = 'true';
        if (recoveryReadOnly) {
            button.disabled = true;
            button.title = t('Las consultas al router estarán disponibles al completar la actualización.');
        }
        return button;
    }

    function applyRecoveryControls() {
        if (!recoveryReadOnly && !restoredRequiresReload) return;
        for (const id of recoveryWriteIds) markRecoveryWrite(byId(id));
        for (const control of document.querySelectorAll('[data-recovery-write]')) markRecoveryWrite(control);
        for (const control of document.querySelectorAll('[data-recovery-router-read]')) markRecoveryRouterRead(control);
        for (const control of document.querySelectorAll('#device-form input, #device-form select, #plan-form input, #plan-form select, #config-form input, #config-form select, .burst-preset-btn'))
            control.disabled = true;
        updateConfigSaveStatus();
        updateLifecycleControls();
    }

    function applyRecovery(state) {
        if (state?.readOnly !== true) return;
        recoveryReadOnly = true;
        if (state.details && typeof state.details === 'object') recoveryDetails = retainVerifiedOwners(recoveryDetails, state.details, verifyingService, verifyingClient);
        const total = Number(recoveryDetails?.duplicate_groups);
        const count = cleanupRemainingCount ?? (Number.isSafeInteger(total) && total >= 0 ? total : (Array.isArray(recoveryDetails?.conflicts) ? recoveryDetails.conflicts.length : 0));
        setTopWarning('network-warning', true, [count, cleanupRequiresReload ? 1 : 0]);
        updateConflictNavigation();
        byId('stored-network-conflicts-pane').style.display = 'block';
        translatedText(byId('network-warning-title'), count === 1 ? 'IP duplicadas: {count} conflicto' : 'IP duplicadas: {count} conflictos', { count });
        if (cleanupRequiresReload) translatedText(byId('network-warning-note'), 'La limpieza terminó. Recarga el panel para comprobar las asignaciones y completar la actualización.');
        applyRecoveryControls();
        renderCrmMonitor();
        if (currentView === 'network_conflicts') renderNetworkConflictsTable();
        if (!recoveryDetails && !recoveryLoading && typeof api.getNetworkConflicts === 'function') {
            recoveryLoading = true;
            api.getNetworkConflicts().then(response => {
                checked(response);
                if (response.data && typeof response.data === 'object') applyRecovery({ readOnly: true, details: response.data });
            }).catch(error => notify(error.message, 'error')).finally(() => { recoveryLoading = false; });
        }
    }

    function safeCrmUrl(value, kind) {
        if (typeof value !== 'string' || !value) return null;
        try {
            const url = new URL(value, window.location.href);
            const page = new URL(window.location.href);
            const allowed = kind === 'service' ? /^\/crm\/client\/service\/[1-9]\d*$/ : /^\/crm\/client\/[1-9]\d*$/;
            if (url.origin !== page.origin || url.username || url.password || url.search || url.hash || !allowed.test(url.pathname)) return null;
            return url.href;
        } catch (_) { return null; }
    }

    function conflictLink(label, rawUrl, kind) {
        const link = document.createElement(safeCrmUrl(rawUrl, kind) ? 'a' : 'span');
        link.className = 'conflict-link';
        link.textContent = label;
        const url = safeCrmUrl(rawUrl, kind);
        if (url) { link.href = url; link.target = '_blank'; link.rel = 'noopener noreferrer'; }
        return link;
    }

    function updateConflictNavigation() {
        const total = Number(recoveryDetails?.duplicate_groups);
        const stored = recoveryReadOnly ? (cleanupRemainingCount ?? (Number.isSafeInteger(total) && total >= 0 ? total
            : Array.isArray(recoveryDetails?.conflicts) ? recoveryDetails.conflicts.length : 0)) : 0;
        const current = crmMonitorReport?.available ? pendingCrmConflictCount() + monitorIssueCount(crmMonitorReport) : 0;
        const count = (Number.isSafeInteger(stored) && stored > 0 ? stored : 0) + current;
        byId('network-conflicts-nav').style.display = 'flex';
        byId('network-conflicts-count').textContent = count > 0 || recoveryReadOnly ? String(count) : '';
        byId('network-conflicts-count').style.display = count > 0 || recoveryReadOnly ? 'inline-block' : 'none';
    }

    function monitorDate(value) {
        return typeof value === 'string' && value.length <= 40 && /^\d{4}-\d{2}-\d{2}T/.test(value)
            && Number.isFinite(new Date(value).getTime()) ? value : null;
    }

    function monitorIssueCount(report) {
        return Number.isSafeInteger(report?.unknown_address_count) && report.unknown_address_count >= 0 ? report.unknown_address_count : 0;
    }

    function resolvedCrmGroup(group) {
        const proof = crmVerifiedGroups.get(JSON.stringify([group.family, group.address]));
        if (!proof || proof.state !== 'resolved' || group.services_truncated === true || !crmMonitorReport?.available
            || proof.snapshot_checked_at !== crmMonitorReport.checked_at) return null;
        const ids = Array.isArray(group.service_ids) ? group.service_ids : group.owners?.map(owner => owner.service_id);
        if (!Array.isArray(ids) || ids.length < 2 || ids.some(id => !Number.isSafeInteger(id) || id < 1)
            || new Set(ids).size !== ids.length || (group.service_count !== undefined && group.service_count !== ids.length)
            || !Array.isArray(proof.service_ids) || proof.service_ids.length !== ids.length
            || new Set(proof.service_ids).size !== proof.service_ids.length
            || proof.service_ids.some(id => !ids.includes(id)) || !Array.isArray(proof.owners)) return null;
        const addresses = new Set();
        for (const id of ids) {
            const snapshotOwner = group.owners.find(owner => owner.service_id === id);
            const owner = proof.owners.find(owner => owner.service_id === id);
            const evidence = owner?.address_evidence?.[group.family === 'IPv6' ? 'address6' : 'address'];
            if (!snapshotOwner || !owner || snapshotOwner.client_id !== owner.client_id || owner.refresh_error
                || typeof owner.service_exists !== 'boolean' || evidence?.known !== true) return null;
            if (Array.isArray(owner.historical_families) && owner.historical_families.includes(group.family)) {
                if (owner.service_exists !== true || owner.service_status_code !== 2) return null;
                continue;
            }
            if (owner.service_exists === false && evidence.value !== null) return null;
            if (evidence.value !== null) {
                if (addresses.has(evidence.value)) return null;
                addresses.add(evidence.value);
            }
        }
        return proof;
    }

    function resolvedCrmGroups() {
        return crmMonitorReport?.available ? crmMonitorReport.conflicts.filter(group => resolvedCrmGroup(group)) : [];
    }

    function pendingCrmConflictCount() {
        return Math.max(0, (crmMonitorReport?.available ? crmMonitorReport.duplicate_groups : 0) - resolvedCrmGroups().length);
    }

    function recordCrmLocalAssignments(id, state, checkedAt, cleaned = false) {
        if (!Number.isSafeInteger(id) || id < 1 || !monitorDate(checkedAt)) return;
        for (const [field, family] of [['address', 'IPv4'], ['address6', 'IPv6']]) {
            const evidence = state?.[field];
            if (evidence?.known !== true || typeof evidence.present !== 'boolean') continue;
            const key = JSON.stringify([id, family]);
            const prior = crmLocalAssignmentProofs.get(key);
            if (prior && new Date(prior.checked_at).getTime() > new Date(checkedAt).getTime()) continue;
            if (evidence.present) crmLocalAssignmentProofs.delete(key);
            else crmLocalAssignmentProofs.set(key, { checked_at: checkedAt, cleaned: cleaned || prior?.cleaned === true });
        }
    }

    function crmAssignmentCleaned(id, family) {
        return crmLocalAssignmentProofs.get(JSON.stringify([id, family]));
    }

    function normalizeCrmMonitor(report) {
        const completedStart = monitorDate(report?.completed_scan_started_at);
        if (!report || typeof report !== 'object' || Array.isArray(report) || typeof report.available !== 'boolean'
            || !Number.isSafeInteger(report.duplicate_groups) || report.duplicate_groups < 0 || !Array.isArray(report.conflicts)
            || (report.available && (!monitorDate(report.checked_at) || report.conflicts.length > report.duplicate_groups
                || (report.duplicate_groups > 0 && !report.conflicts.length)))
            || (report.completed_scan_started_at != null && (!completedStart || !monitorDate(report.checked_at)
                || new Date(completedStart).getTime() > new Date(report.checked_at).getTime()))
            || report.conflicts.some(group => !group || !['IPv4', 'IPv6'].includes(group.family)
                || typeof group.address !== 'string' || group.address.length > 128 || !Array.isArray(group.owners))
            || new Set(report.conflicts.map(group => JSON.stringify([group.family, group.address]))).size !== report.conflicts.length) throw new Error('invalid_crm_monitor_report');
        // Render a bounded snapshot. Customer details never enter navigation storage.
        return { ...report, checked_at: monitorDate(report.checked_at), scan_started_at: monitorDate(report.scan_started_at), completed_scan_started_at: completedStart,
            conflicts: report.conflicts.slice(0, 100) };
    }

    function renderCrmMonitor() {
        const savedPosition = beforeTableUpdate('network_conflicts');
        const report = crmMonitorReport;
        const available = report?.available === true;
        const count = available ? pendingCrmConflictCount() : 0;
        const resolvedGroups = resolvedCrmGroups();
        const issueCount = available ? monitorIssueCount(report) : 0;
        const stale = crmMonitorFailure || (available && report?.stale === true) || Boolean(report?.last_error);
        const checkedAt = available ? t('Última revisión completa: {time}', { time: formatDate(report.checked_at) }) : '';
        const families = Array.isArray(report?.families) ? [...new Set(report.families.filter(family => ['IPv4', 'IPv6'].includes(family)))].join(', ') : '';
        const uncovered = Array.isArray(report?.unavailable_families) ? [...new Set(report.unavailable_families.filter(family => ['IPv4', 'IPv6'].includes(family)))].join(', ') : '';
        const freshness = [checkedAt, families ? t('Familias revisadas: {families}.', { families }) : '',
            uncovered ? t('Sin revisar por configuración de atributos: {families}.', { families: uncovered }) : '',
            stale ? t('El resultado guardado puede estar desactualizado.') : ''].filter(Boolean).join(' ');
        setTopWarning('crm-network-warning', count > 0 || issueCount > 0,
            [count, issueCount, stale ? 1 : 0, families.replace(/[^46]/g, ''), uncovered.replace(/[^46]/g, '')], available);
        translatedText(byId('crm-network-warning-title'), count > 0 ? (count === 1 ? 'IP duplicadas en CRM: {count} conflicto' : 'IP duplicadas en CRM: {count} conflictos')
            : issueCount === 1 ? 'IP sin comprobar en CRM: {count} dirección' : 'IP sin comprobar en CRM: {count} direcciones', { count: count > 0 ? count : issueCount });
        translatedText(byId('crm-network-warning-note'), recoveryReadOnly
            ? 'Este aviso del CRM no pausa los trabajos. La pausa por reservas guardadas se indica por separado.'
            : 'Las suspensiones y reactivaciones automáticas de los demás clientes continúan.');
        byId('crm-network-warning-freshness').textContent = freshness;
        const status = byId('crm-network-conflicts-status');
        status.classList.toggle('is-stale', stale && (count > 0 || issueCount > 0 || !resolvedGroups.length));
        const messages = [];
        if (stale) messages.push(t('La revisión no está actualizada. Se conserva el último resultado disponible.'));
        if (report?.scan_in_progress === true) messages.push(t('Revisión en curso: {count} servicios consultados.', {
            count: Number.isSafeInteger(report.services_scanned) && report.services_scanned >= 0 ? report.services_scanned : 0
        }));
        else if (available) messages.push(t(count > 0 ? (count === 1 ? '{count} conflicto encontrado en los servicios del CRM.' : '{count} conflictos encontrados en los servicios del CRM.') : issueCount > 0
            ? (issueCount === 1 ? '{count} dirección del CRM no se pudo comprobar.' : '{count} direcciones del CRM no se pudieron comprobar.') : resolvedGroups.length
                ? 'Los conflictos de esta revisión fueron resueltos y verificados en UISP.' : 'No se encontraron IP duplicadas en las familias revisadas.', { count: count > 0 ? count : issueCount }));
        else messages.push(t(crmMonitorInFlight ? 'Cargando el estado del monitoreo...' : 'El monitoreo automático aún no ha completado su primera revisión.'));
        if (issueCount > 0 && (count > 0 || report?.scan_in_progress === true))
            messages.push(t(issueCount === 1 ? '{count} dirección del CRM no se pudo comprobar.' : '{count} direcciones del CRM no se pudieron comprobar.', { count: issueCount }));
        status.textContent = messages.join(' ');
        byId('crm-network-resolved').style.display = resolvedGroups.length ? 'block' : 'none';
        translatedText(byId('crm-network-resolved-summary'), resolvedGroups.length === 1 ? '{count} conflicto resuelto y verificado en UISP.'
            : '{count} conflictos resueltos y verificados en UISP.', { count: resolvedGroups.length });
        byId('crm-network-conflicts-freshness').textContent = freshness;
        const issueList = byId('crm-network-address-issues-list'); issueList.replaceChildren();
        const issues = available && Array.isArray(report.address_issues) ? report.address_issues.slice(0, 20).filter(issue => issue
            && Number.isSafeInteger(issue.service_id) && issue.service_id > 0 && ['IPv4', 'IPv6'].includes(issue.family)) : [];
        byId('crm-network-address-issues').style.display = issueCount > 0 ? 'block' : 'none';
        for (const issue of issues) {
            const item = document.createElement('div'); item.className = 'crm-address-issue';
            const meta = document.createElement('div'); meta.className = 'conflict-owner-meta';
            meta.appendChild(conflictLink(t('Servicio #{id}', { id: issue.service_id }), issue.service_url, 'service'));
            if (Number.isSafeInteger(issue.client_id) && issue.client_id > 0)
                meta.appendChild(conflictLink(t('Cliente #{id}', { id: issue.client_id }), issue.client_url, 'client'));
            const badge = document.createElement('span'); badge.className = 'badge badge-warning'; badge.textContent = issue.family; meta.appendChild(badge);
            const note = document.createElement('p'); translatedText(note, 'Revisa el atributo {family} de este servicio en el CRM.', { family: issue.family });
            item.append(meta, note);
            appendCrmOwnerActions(item, issue, { family: issue.family, address: null, service_ids: [issue.service_id] });
            issueList.appendChild(item);
        }
        const issueLimit = byId('crm-network-address-issues-limit');
        issueLimit.style.display = issueCount > issues.length || report?.address_issues_truncated === true ? 'block' : 'none';
        translatedText(issueLimit, 'Se muestran {shown} de {total} direcciones sin comprobar.', { shown: issues.length, total: issueCount });
        const historyList = byId('crm-network-history-list'); historyList.replaceChildren();
        const historyCount = available && Number.isSafeInteger(report.historical_assignments_count) && report.historical_assignments_count >= 0
            ? report.historical_assignments_count : 0;
        const historical = available && Array.isArray(report.historical_assignments) ? report.historical_assignments.slice(0, 20).filter(entry => entry
            && entry.historical_reviewed === true && Number.isSafeInteger(entry.service_id) && entry.service_id > 0
            && ['IPv4', 'IPv6'].includes(entry.family) && typeof entry.address === 'string' && entry.address.length <= 128) : [];
        byId('crm-network-history').style.display = historyCount > 0 ? 'block' : 'none';
        for (const entry of historical) {
            const verified = crmVerifiedOwners.get(entry.service_id);
            const owner = verified && verified.owner.client_id === entry.client_id ? { ...entry, ...verified.owner } : entry;
            const item = document.createElement('div'); item.className = 'conflict-owner crm-history-owner';
            const identity = document.createElement('div'); identity.className = 'conflict-identity';
            identity.appendChild(conflictLink(owner.client_name || t('Cliente #{id}', { id: owner.client_id }), owner.client_url, 'client'));
            const meta = document.createElement('div'); meta.className = 'conflict-owner-meta';
            meta.appendChild(conflictLink(t('Servicio #{id}', { id: owner.service_id }), owner.service_url, 'service'));
            const address = document.createElement('span'); address.className = 'badge badge-neutral'; address.textContent = entry.family + ' · ' + entry.address;
            const status = document.createElement('span'); status.className = 'badge badge-neutral';
            translatedText(status, 'Servicio: {state}', { state: t(owner.service_status || 'Sin comprobar') });
            meta.append(address, status); identity.appendChild(meta);
            const note = document.createElement('p'); note.className = 'conflict-source';
            translatedText(note, verified ? 'Servicio consultado en UISP: {time}' : 'Asignación histórica revisada (dato guardado)', { time: formatDate(verified?.checked_at) }); identity.appendChild(note);
            item.appendChild(identity); appendCrmOwnerActions(item, { ...owner, historical_reviewed: true }, { family: entry.family, address: entry.address, service_ids: [entry.service_id] });
            historyList.appendChild(item);
        }
        const historyLimit = byId('crm-network-history-limit'); historyLimit.style.display = historyCount > historical.length ? 'block' : 'none';
        translatedText(historyLimit, 'Se muestran {shown} de {total} asignaciones históricas revisadas.', { shown: historical.length, total: historyCount });
        const tbody = byId('crm-network-conflicts-body');
        tbody.replaceChildren();
        const resolvedBody = byId('crm-network-resolved-body'); resolvedBody.replaceChildren();
        const groups = available ? report.conflicts : [];
        const pendingGroups = groups.filter(group => !resolvedCrmGroup(group));
        if (!pendingGroups.length) {
            const empty = available && count === 0 ? issueCount > 0 ? 'No hay conflictos confirmados entre las direcciones comprobadas.' : 'Sin IP duplicadas en las familias revisadas.' : available
                ? 'No hay detalles disponibles de estos conflictos.' : 'Los resultados aparecerán al completar la revisión automática.';
            tbody.innerHTML = `<tr><td colspan="2" class="empty-state">${html(t(resolvedGroups.length && count === 0 ? 'No quedan conflictos pendientes en esta revisión.' : empty))}</td></tr>`;
        }
        for (const group of groups) {
            const row = document.createElement('tr');
            const resolved = resolvedCrmGroup(group);
            if (resolved) row.className = 'crm-resolved-row';
            const address = document.createElement('td'); address.className = 'conflict-address';
            const owners = group.owners.slice(0, 50).filter(owner => owner && Number.isSafeInteger(owner.service_id) && owner.service_id > 0);
            const total = Number.isSafeInteger(group.service_count) && group.service_count >= owners.length ? group.service_count : owners.length;
            address.innerHTML = `<strong>${html(group.address)}</strong><span class="text-muted">${html(group.family)}</span><span class="badge ${resolved ? 'badge-success' : 'badge-warning'}">${html(t(resolved ? 'Conflicto resuelto' : '{count} servicios en CRM', { count: total }))}</span>`;
            if (!resolved && group.current_confirmed === false) {
                const pending = document.createElement('span'); pending.className = 'badge badge-neutral';
                translatedText(pending, 'Pendiente de comprobar'); address.appendChild(pending);
                const prior = document.createElement('p'); prior.className = 'conflict-source';
                translatedText(prior, 'IP observada en una revisión anterior.'); address.appendChild(prior);
            }
            const verification = crmVerifiedGroups.get(JSON.stringify([group.family, group.address]));
            if (verification) {
                const note = document.createElement('p'); note.className = 'crm-selection-status';
                translatedText(note, resolved ? 'Resolución verificada. Este conflicto ya no cuenta como pendiente.'
                    : verification.state === 'duplicate' ? 'Los servicios comprobados todavía comparten una IP.'
                    : 'La verificación seleccionada está incompleta. El aviso general conserva la revisión automática.');
                address.appendChild(note);
                const when = document.createElement('small'); when.className = 'conflict-source';
                translatedText(when, 'Selección verificada en UISP: {time}', { time: formatDate(verification.checked_at) }); address.appendChild(when);
            }
            const ownersCell = document.createElement('td'); ownersCell.className = 'conflict-owners';
            for (const snapshotOwner of owners) {
                const verification = crmVerifiedOwners.get(snapshotOwner.service_id);
                const owner = verification && verification.owner.client_id === snapshotOwner.client_id
                    ? { ...snapshotOwner, ...verification.owner } : snapshotOwner;
                const item = document.createElement('div'); item.className = 'conflict-owner crm-conflict-owner';
                item.dataset.serviceId = String(owner.service_id); item.dataset.anchorSource = 'crm_snapshot';
                const identity = document.createElement('div'); identity.className = 'conflict-identity';
                const clientId = Number.isSafeInteger(owner.client_id) && owner.client_id > 0 ? owner.client_id : null;
                const clientLink = conflictLink(typeof owner.client_name === 'string' && owner.client_name.length <= 500 && owner.client_name
                    ? owner.client_name : clientId ? t('Cliente #{id}', { id: clientId }) : t('Cliente sin identificar'), clientId ? owner.client_url : null, 'client');
                if (owner.client_archived === true) clientLink.className += ' client-archived-name';
                identity.appendChild(clientLink);
                const meta = document.createElement('div'); meta.className = 'conflict-owner-meta';
                meta.appendChild(conflictLink(t('Servicio #{id}', { id: owner.service_id }), owner.service_url, 'service'));
                const badge = document.createElement('span'); badge.className = 'badge badge-neutral';
                const state = typeof owner.service_status === 'string' && owner.service_status.length <= 80 ? t(owner.service_status) : t('Sin comprobar');
                badge.textContent = t('Servicio: {state}', { state }); meta.appendChild(badge); identity.appendChild(meta);
                if (typeof owner.client_archived === 'boolean') {
                    const archive = document.createElement('span'); archive.className = 'badge badge-neutral client-lifecycle';
                    translatedText(archive, owner.client_archived ? 'Cliente archivado' : 'Cliente no archivado'); meta.appendChild(archive);
                }
                if (Array.isArray(owner.historical_families) && owner.historical_families.includes(group.family)) {
                    const history = document.createElement('span'); history.className = 'badge badge-neutral';
                    translatedText(history, 'Asignación histórica excluida'); meta.appendChild(history);
                }
                const evidence = owner.address_evidence?.[group.family === 'IPv6' ? 'address6' : 'address'];
                const known = evidence ? evidence.known === true : owner.current_address_known !== false;
                if (!known) {
                    const unknown = document.createElement('span'); unknown.className = 'badge badge-warning';
                    translatedText(unknown, 'IP actual sin comprobar'); meta.appendChild(unknown);
                }
                const source = document.createElement('p'); source.className = 'conflict-source';
                translatedText(source, verification ? 'Servicio consultado en UISP: {time}' : 'Servicios consultados por el monitoreo del CRM', { time: formatDate(verification?.checked_at) }); identity.appendChild(source);
                if (verification) {
                    const current = evidence?.known === true ? evidence.value : null;
                    const comparison = evidence?.known === true ? current === null ? 'removed' : current === group.address ? 'same' : 'changed' : 'unknown';
                    const detail = document.createElement('div'); detail.className = 'conflict-address-evidence';
                    detail.innerHTML = `<dl class="conflict-address-grid"><div><dt>${html(t('IP observada por el monitor'))}</dt><dd>${html(group.address)}</dd></div><div><dt>${html(t('IP actual en UISP'))}</dt><dd>${html(evidence?.known === true ? current ?? t('Sin IP asignada') : t('Sin comprobar'))}</dd></div></dl>`;
                    const state = document.createElement('span'); state.className = 'badge badge-neutral';
                    translatedText(state, { same: 'Coincide con la IP guardada', removed: 'IP retirada en UISP', changed: 'IP cambiada en UISP', unknown: 'IP actual sin comprobar' }[comparison]); detail.appendChild(state);
                    identity.appendChild(detail);
                    if (owner.refresh_error || owner.client_refresh_error) {
                        const error = document.createElement('p'); error.className = 'conflict-refresh-error';
                        translatedText(error, 'No se pudo completar la verificación en UISP. Se conserva el resultado automático.'); identity.appendChild(error);
                    }
                }
                item.appendChild(identity); appendCrmOwnerActions(item, owner, group); ownersCell.appendChild(item);
            }
            if (group.services_truncated || total > owners.length) {
                const more = document.createElement('p'); more.className = 'form-note';
                translatedText(more, 'Se muestran {shown} de {total} servicios en CRM.', { shown: owners.length, total }); ownersCell.appendChild(more);
            }
            row.append(address, ownersCell); (resolved ? resolvedBody : tbody).appendChild(row);
        }
        const limit = byId('crm-network-conflicts-limit');
        limit.style.display = available && (report.conflicts_truncated === true || count > pendingGroups.length) ? 'block' : 'none';
        translatedText(limit, 'Se muestran {shown} de {total} conflictos pendientes. Los conflictos fuera de esta muestra aún deben comprobarse.', { shown: pendingGroups.length, total: count });
        updateConflictNavigation();
        afterTableUpdate('network_conflicts', savedPosition);
    }

    function crmGroupServiceIds(group, selectedId) {
        return [...new Set([selectedId, ...(Array.isArray(group?.service_ids) ? group.service_ids : []),
            ...(Array.isArray(group?.owners) ? group.owners.map(owner => owner.service_id) : [])])]
            .filter(id => Number.isSafeInteger(id) && id > 0).slice(0, 3);
    }

    async function verifyCrmOwners(group, selectedId) {
        const ids = crmGroupServiceIds(group, selectedId);
        const data = checked(await api.verifyCrmNetworkServices(ids)).data;
        if (!data || !Array.isArray(data.owners) || !Array.isArray(data.groups) || !monitorDate(data.checked_at)
            || data.owners.length > ids.length || new Set(data.owners.map(owner => owner?.service_id)).size !== data.owners.length
            || data.owners.some(owner => !owner || !ids.includes(owner.service_id)
                || (owner.client_id !== null && owner.client_id !== undefined && (!Number.isSafeInteger(owner.client_id) || owner.client_id < 1))
                || Object.values(owner.address_evidence || {}).some(evidence => !evidence || typeof evidence.known !== 'boolean'
                    || (evidence.known && evidence.value !== null && (typeof evidence.value !== 'string' || evidence.value.length > 128)))))
            throw new Error(t('Respuesta inválida del servidor.'));
        for (const owner of data.owners) {
            const prior = crmVerifiedOwners.get(owner.service_id)?.owner;
            for (const [field, family] of [['address', 'IPv4'], ['address6', 'IPv6']]) {
                if (prior && (prior.client_id !== owner.client_id || JSON.stringify(prior.address_evidence?.[field]) !== JSON.stringify(owner.address_evidence?.[field])
                    || JSON.stringify(prior.historical_families) !== JSON.stringify(owner.historical_families))) crmLocalAssignmentProofs.delete(JSON.stringify([owner.service_id, family]));
            }
            crmVerifiedOwners.set(owner.service_id, { owner, checked_at: data.checked_at });
            recordCrmLocalAssignments(owner.service_id, owner.local_assignment_state, owner.local_assignment_checked_at || data.checked_at);
        }
        for (const result of data.groups) if (result && ['IPv4', 'IPv6'].includes(result.family) && typeof result.address === 'string'
            && result.address.length <= 128 && ['resolved', 'duplicate', 'unknown'].includes(result.state))
            crmVerifiedGroups.set(JSON.stringify([result.family, result.address]), { ...result, checked_at: data.checked_at,
                observed_at: data.owners.reduce((earliest, owner) => monitorDate(owner.address_verified_at)
                    && new Date(owner.address_verified_at).getTime() < new Date(earliest).getTime() ? owner.address_verified_at : earliest, data.checked_at),
                snapshot_checked_at: data.snapshot_checked_at, owners: data.owners });
        return data;
    }

    function appendCrmOwnerActions(item, owner, group) {
        const id = owner.service_id;
        const disabled = crmVerifyBusy || crmEditBusy || cleanupBusy;
        const verify = document.createElement('button'); verify.type = 'button'; verify.className = 'btn btn-secondary conflict-verify';
        translatedText(verify, crmVerifyBusy ? 'Verificando...' : 'Verificar en UISP'); verify.disabled = disabled;
        verify.addEventListener('click', async () => {
            if (crmVerifyBusy || crmEditBusy || cleanupBusy) return;
            crmVerifyBusy = true; renderCrmMonitor();
            try { await verifyCrmOwners(group, id); }
            catch (error) { notify(t(error.message), 'error'); }
            finally { crmVerifyBusy = false; renderCrmMonitor(); }
        });
        const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'btn btn-secondary conflict-edit-ip';
        translatedText(edit, 'Editar IP en CRM'); edit.disabled = disabled;
        edit.addEventListener('click', () => openCrmEditor(owner, group));
        item.appendChild(verify);
        if (owner.service_status_code === 2 && owner.historical_reviewed !== true
            && !(Array.isArray(owner.historical_families) && owner.historical_families.includes(group.family))) {
            const history = document.createElement('button'); history.type = 'button'; history.className = 'btn btn-secondary conflict-history';
            translatedText(history, 'Resolver asignación histórica'); history.disabled = disabled;
            history.addEventListener('click', () => openCrmEditor(owner, group, 'history')); item.appendChild(history);
        }
        item.appendChild(edit);
        if (group.address !== null) {
            const cleanup = document.createElement('button'); cleanup.type = 'button'; cleanup.className = 'btn btn-secondary conflict-cleanup';
            const cleaned = crmAssignmentCleaned(id, group.family);
            translatedText(cleanup, cleaned ? cleaned.cleaned ? 'Ya limpiada' : 'Sin reserva local' : 'Revisar limpieza'); cleanup.disabled = Boolean(disabled || cleanupRequiresReload || cleaned);
            cleanup.addEventListener('click', () => previewCleanup([id], 'crm_snapshot', group.family)); item.appendChild(cleanup);
        }
    }

    function resetCrmEditPreview() {
        crmEditPreview = null;
        byId('crm-ip-review').replaceChildren(); byId('crm-ip-review').style.display = 'none';
        byId('apply-crm-ip-change').disabled = true;
    }

    function crmEditorIdentity(owner, family) {
        const target = byId('crm-ip-target'); target.replaceChildren();
        target.appendChild(conflictLink(t('Servicio #{id}', { id: owner.service_id }), owner.service_url, 'service'));
        if (Number.isSafeInteger(owner.client_id) && owner.client_id > 0)
            target.appendChild(conflictLink(typeof owner.client_name === 'string' && owner.client_name.length <= 500 && owner.client_name
                ? owner.client_name : t('Cliente #{id}', { id: owner.client_id }), owner.client_url, 'client'));
        const badge = document.createElement('span'); badge.className = 'badge badge-neutral'; badge.textContent = family; target.appendChild(badge);
    }

    function updateCrmEditorControls() {
        const terminal = crmEditOutcome !== null;
        const history = crmEditTarget?.mode === 'history';
        const remove = byId('crm-ip-operation').value === 'remove' || history;
        byId('crm-ip-form').style.display = terminal ? 'none' : 'block';
        byId('crm-ip-result').style.display = terminal ? 'block' : 'none';
        byId('crm-ip-address').disabled = terminal || crmEditBusy || remove;
        byId('crm-ip-operation').disabled = terminal || crmEditBusy;
        byId('review-crm-ip-change').disabled = terminal || crmEditBusy || !crmEditTarget?.verified;
        byId('apply-crm-ip-change').disabled = terminal || crmEditBusy || !crmEditPreview;
        for (const id of ['review-crm-ip-change', 'apply-crm-ip-change']) byId(id).style.display = terminal ? 'none' : 'inline-flex';
        byId('verify-crm-ip-result').style.display = terminal ? 'inline-flex' : 'none';
        byId('verify-crm-ip-result').disabled = crmEditBusy || !terminal;
        translatedText(byId('verify-crm-ip-result'), crmEditBusy ? 'Verificando...' : 'Verificar nuevamente');
        const fallback = terminal && crmEditOutcome.historyAvailable === true;
        byId('resolve-crm-ip-history').style.display = fallback ? 'inline-flex' : 'none';
        byId('resolve-crm-ip-history').disabled = crmEditBusy || !fallback;
        byId('crm-ip-result-history-note').style.display = fallback ? 'block' : 'none';
        const cleanup = terminal && crmEditOutcome.cleanupAvailable === true;
        byId('cleanup-crm-ip-result').style.display = cleanup ? 'inline-flex' : 'none';
        byId('cleanup-crm-ip-result').disabled = crmEditBusy || cleanupRequiresReload || !cleanup;
    }

    function renderCrmEditResult() {
        const outcome = crmEditOutcome;
        if (!outcome) return;
        const { preview, owner, checkedAt } = outcome;
        const evidence = owner?.address_evidence?.[preview.family === 'IPv6' ? 'address6' : 'address'];
        const sameClient = owner && Number.isSafeInteger(outcome.target.owner.client_id) && outcome.target.owner.client_id > 0
            && owner.client_id === outcome.target.owner.client_id;
        const known = sameClient && owner.service_exists === true && evidence?.known === true;
        const history = preview.operation === 'history';
        const reviewed = known && owner.service_status_code === 2 && Array.isArray(owner.historical_families)
            && owner.historical_families.includes(preview.family) && evidence.value === preview.new_address;
        const desired = known && !history && evidence.value === preview.new_address;
        const receipt = outcome.receiptConfirmed === true;
        let state = 'unknown', title = 'Resultado pendiente de verificación', message = 'No se pudo confirmar el resultado. Verificar nuevamente sólo consulta UISP; no repite el cambio.';
        if (outcome.checking) { state = 'checking'; title = 'Comprobando el resultado'; message = 'El intento terminó. Se está consultando el estado actual del servicio en UISP.'; }
        else if (owner?.service_exists === true && Number.isSafeInteger(owner.client_id) && owner.client_id > 0 && !sameClient) { state = 'identity_changed'; title = 'La identidad del servicio cambió'; message = 'El cliente actual no coincide con el intento. Revisa el servicio en UISP antes de hacer otro cambio.'; }
        else if (owner?.service_exists === false) { state = 'absent'; title = 'El servicio ya no existe en UISP'; message = 'La ausencia del servicio no confirma que este cambio se haya aplicado. Revisa el registro del CRM.'; }
        else if (reviewed) { state = 'confirmed'; title = 'Asignación histórica resuelta'; message = 'La exclusión histórica está verificada. El CRM conserva la IP y su historial; los routers no se modificaron.'; }
        else if (desired) { state = receipt ? 'confirmed' : 'observed'; title = receipt ? preview.operation === 'remove' ? 'IP retirada correctamente' : 'IP cambiada correctamente' : 'UISP muestra el cambio solicitado';
            message = receipt ? 'UISP confirmó el cambio y la verificación actual coincide con lo solicitado.' : 'La consulta actual coincide con lo solicitado. La respuesta del intento original no confirmó si el cambio se aplicó.'; }
        else if (known) { state = history ? 'history_unconfirmed' : evidence.value === preview.current_address ? 'unchanged' : 'changed';
            title = history ? 'La exclusión histórica no está confirmada' : state === 'unchanged' ? 'UISP sigue mostrando la IP anterior' : 'La IP actual no coincide con lo solicitado';
            message = history ? 'UISP no confirmó la exclusión de esta asignación. Puedes volver a verificar su estado.' : 'Este intento no coincide con el estado actual. Verifica nuevamente antes de intentar otro cambio.'; }
        else if (receipt) { state = 'confirmed_pending'; title = history ? 'Asignación histórica guardada' : 'Cambio aplicado en CRM';
            message = history ? 'El plugin confirmó que guardó la exclusión histórica. La consulta posterior no pudo comprobar su estado actual.' : 'UISP confirmó el cambio al guardar. La consulta posterior no pudo comprobar el estado actual.'; }
        if (!outcome.checking && !history && outcome.result?.write_outcome === 'rejected' && !desired && !['identity_changed', 'absent'].includes(state)) {
            state = 'rejected'; title = 'UISP rechazó el cambio'; message = 'La solicitud fue rechazada. La IP no se considera retirada ni cambiada. Puedes verificar de nuevo sin repetir la modificación.';
        }
        const panel = byId('crm-ip-result'); panel.dataset.state = state;
        panel.classList.toggle('is-confirmed', ['confirmed', 'observed', 'confirmed_pending'].includes(state));
        translatedText(byId('crm-ip-edit-title'), history ? 'Resultado de la resolución histórica' : 'Resultado del cambio');
        translatedText(byId('crm-ip-result-title'), title); translatedText(byId('crm-ip-result-message'), message);
        const previous = preview.current_address_known === true ? preview.current_address ?? t('Sin IP asignada') : t('Sin comprobar');
        const current = known ? evidence.value ?? t('Sin IP asignada') : t('Sin comprobar');
        byId('crm-ip-result-summary').innerHTML = `<dl class="conflict-address-grid"><div><dt>${html(t('IP antes del intento'))}</dt><dd>${html(previous)}</dd></div><div><dt>${html(t(history ? 'IP histórica conservada' : 'IP solicitada'))}</dt><dd>${html(preview.new_address ?? t('Sin IP asignada'))}</dd></div><div><dt>${html(t('IP actual en UISP'))}</dt><dd>${html(current)}</dd></div></dl>`;
        byId('crm-ip-current').textContent = current;
        byId('crm-ip-address').value = known && typeof evidence.value === 'string' ? evidence.value : '';
        byId('crm-ip-result-checked').textContent = checkedAt ? t('Última verificación del intento: {time}.', { time: formatDate(checkedAt) }) : t('Aún no se pudo consultar el resultado de este intento.');
        const detail = byId('crm-ip-result-detail'); detail.replaceChildren();
        const writeResult = outcome.result;
        if (!history && writeResult?.write_outcome) {
            const responseNote = document.createElement('span');
            const reasons = {
                permission_denied: 'UISP rechazó el permiso para modificar este servicio.',
                not_found: 'UISP no encontró el recurso al solicitar el cambio.',
                method_not_allowed: 'UISP no admite esa modificación mediante este recurso.',
                conflict: 'UISP rechazó el cambio por un conflicto con el estado del servicio.',
                validation_failed: 'UISP rechazó los datos del cambio. Revisa el servicio y sus atributos en el CRM.',
                rate_limited: 'UISP limitó temporalmente las solicitudes. Espera antes de verificar de nuevo.',
                http_rejected: 'UISP rechazó la solicitud de modificación.',
                server_unconfirmed: 'UISP no confirmó la modificación por un error o una respuesta incompleta del servidor.',
                transport_unconfirmed: 'No se recibió una confirmación de UISP. La siguiente verificación sólo consultará el estado actual.'
            };
            translatedText(responseNote, reasons[writeResult.write_reason] || (writeResult.write_outcome === 'accepted'
                ? 'UISP aceptó la solicitud. El resultado actual se muestra arriba.' : 'La respuesta original no confirmó la modificación. El resultado actual se muestra arriba.'));
            detail.appendChild(responseNote);
            if (Number.isSafeInteger(writeResult.write_http_status)) {
                const code = document.createElement('span'); translatedText(code, 'Respuesta HTTP de UISP: {code}', { code: writeResult.write_http_status }); detail.appendChild(code);
            }
        }
        if (receipt && !['confirmed', 'confirmed_pending'].includes(state)) {
            const note = document.createElement('span'); translatedText(note, history ? 'El intento recibió una confirmación de guardado histórico; el estado actual se muestra arriba.' : 'El intento recibió una confirmación de UISP; el estado actual se muestra arriba.'); detail.appendChild(note);
        }
        if (outcome.error || outcome.verificationError) {
            const error = document.createElement('span'); translatedText(error, 'Detalle: {message}', { message: t(outcome.verificationError || outcome.error) }); detail.appendChild(error);
        }
        if (desired && owner.service_status_code !== 2) {
            const note = document.createElement('span'); translatedText(note, 'El panel se actualizó. Revisa la sincronización del equipo para reconciliar el router.'); detail.appendChild(note);
        }
        outcome.historyAvailable = !history && sameClient && owner.service_exists === true && owner.service_status_code === 2
            && known && typeof evidence.value === 'string' && evidence.value.length > 0
            && !(Array.isArray(owner.historical_families) && owner.historical_families.includes(preview.family));
        const local = owner?.local_assignment_state?.[preview.family === 'IPv6' ? 'address6' : 'address'];
        outcome.cleanupAvailable = (desired || reviewed) && !crmAssignmentCleaned(preview.service_id, preview.family)
            && (local?.known === true ? local.present === true : outcome.result?.local_cleanup_required === true)
            && (history || outcome.result?.local_cleanup_required === true || preview.local_cleanup_required === true);
        updateCrmEditorControls();
    }

    async function verifyCrmEditResult(outcome, generation) {
        outcome.checking = true; outcome.owner = null; outcome.checkedAt = null; outcome.verificationError = null;
        renderCrmEditResult();
        try {
            const data = await verifyCrmOwners(outcome.target.group, outcome.preview.service_id);
            if (generation !== crmEditGeneration || crmEditOutcome !== outcome) return;
            outcome.owner = data.owners.find(owner => owner.service_id === outcome.preview.service_id) || null;
            outcome.checkedAt = data.checked_at;
        } catch (error) {
            if (generation !== crmEditGeneration || crmEditOutcome !== outcome) return;
            outcome.verificationError = error.message;
        } finally {
            if (generation === crmEditGeneration && crmEditOutcome === outcome) {
                outcome.checking = false; renderCrmEditResult(); renderCrmMonitor();
            }
        }
        // Refresh only the saved local report; this does not start a CRM scan.
        if (generation === crmEditGeneration && crmEditOutcome === outcome) await refreshCrmMonitor();
    }

    async function openCrmEditor(owner, group, mode = 'edit') {
        if (crmVerifyBusy || crmEditBusy || cleanupBusy || !crmEditModal) return;
        const generation = ++crmEditGeneration;
        crmEditOutcome = null;
        crmEditTarget = { owner, group, mode, verified: false }; crmEditBusy = true;
        crmEditModal.style.display = 'flex'; crmEditorIdentity(owner, group.family);
        translatedText(byId('crm-ip-edit-title'), mode === 'history' ? 'Resolver asignación histórica' : 'Editar IP en CRM');
        translatedText(byId('crm-ip-edit-note'), mode === 'history'
            ? 'Esta IP de un servicio terminado se excluirá del monitoreo. El historial del CRM, el servicio actual y los routers se conservarán. La exclusión dejará de aplicarse si cambia la IP o se reactiva el servicio.'
            : 'Sólo se modificará este atributo de IP en el CRM. Las demás IP y los demás datos del servicio se conservarán.');
        translatedText(byId('apply-crm-ip-change'), mode === 'history' ? 'Excluir asignación histórica' : 'Aplicar cambio en CRM');
        byId('crm-ip-operation-group').style.display = mode === 'history' ? 'none' : 'block';
        byId('crm-ip-address-group').style.display = mode === 'history' ? 'none' : 'block';
        byId('crm-ip-operation').value = 'replace'; byId('crm-ip-address').value = '';
        byId('crm-ip-current').textContent = t('Sin comprobar');
        resetCrmEditPreview(); updateCrmEditorControls();
        translatedText(byId('crm-ip-edit-status'), 'Consultando la IP actual en UISP...');
        try {
            const data = await verifyCrmOwners(group, owner.service_id);
            if (generation !== crmEditGeneration) return;
            const current = data.owners.find(item => item.service_id === owner.service_id);
            if (!current || current.service_exists !== true) throw new Error(t('UISP no confirmó que este servicio exista. Vuelve a verificarlo.'));
            if (!Number.isSafeInteger(owner.client_id) || owner.client_id < 1 || current.client_id !== owner.client_id)
                throw new Error(t('La identidad del servicio cambió. Vuelve a verificarlo.'));
            if (mode === 'history' && current.service_status_code !== 2) throw new Error(t('La asignación histórica sólo puede resolverse si UISP confirma que el servicio está terminado.'));
            const evidence = current.address_evidence?.[group.family === 'IPv6' ? 'address6' : 'address'];
            crmEditTarget = { owner: current, group, mode, verified: true };
            crmEditorIdentity(current, group.family);
            byId('crm-ip-current').textContent = evidence?.known === true ? evidence.value ?? t('Sin IP asignada') : t('Sin comprobar');
            byId('crm-ip-address').value = evidence?.known === true && typeof evidence.value === 'string' ? evidence.value : '';
            translatedText(byId('crm-ip-edit-status'), mode === 'history' ? 'Revisa la asignación histórica antes de excluirla del monitoreo.'
                : 'Elige cambiar la IP o quitarla de este servicio, y revisa el cambio antes de guardarlo.');
        } catch (error) {
            if (generation === crmEditGeneration) translatedText(byId('crm-ip-edit-status'), 'Error: {message}', { message: t(error.message) });
        } finally {
            if (generation === crmEditGeneration) { crmEditBusy = false; updateCrmEditorControls(); renderCrmMonitor(); }
        }
    }

    for (const [id, event] of [['crm-ip-operation', 'change'], ['crm-ip-address', 'input']]) byId(id)?.addEventListener(event, () => {
        if (crmEditBusy || crmEditOutcome) return;
        resetCrmEditPreview(); updateCrmEditorControls();
    });

    byId('review-crm-ip-change')?.addEventListener('click', async () => {
        if (crmEditBusy || crmEditOutcome || !crmEditTarget?.verified) return;
        const target = crmEditTarget, generation = crmEditGeneration;
        const operation = target.mode === 'history' ? 'history' : byId('crm-ip-operation').value;
        const address = byId('crm-ip-address').value.trim();
        if (!['replace', 'remove', 'history'].includes(operation) || (operation === 'replace' && (!address || address.length > 128))) {
            translatedText(byId('crm-ip-edit-status'), 'Introduce una IP válida o elige quitar la IP.'); return;
        }
        crmEditBusy = true; resetCrmEditPreview(); updateCrmEditorControls();
        translatedText(byId('crm-ip-edit-status'), 'Comprobando el cambio en UISP...');
        try {
            const preview = checked(await api.previewCrmNetworkChange({ serviceId: target.owner.service_id, family: target.group.family,
                operation, ...(operation === 'replace' ? { address } : {}) })).data;
            if (generation !== crmEditGeneration) return;
            if (!preview || preview.service_id !== target.owner.service_id || preview.family !== target.group.family || preview.operation !== operation
                || typeof preview.previewToken !== 'string' || !/^[a-f0-9]{64}$/.test(preview.previewToken)
                || !monitorDate(preview.expiresAt) || (operation === 'remove' ? preview.new_address !== null
                    : typeof preview.new_address !== 'string' || !preview.new_address || preview.new_address.length > 128)) throw new Error(t('Respuesta inválida del servidor.'));
            if (operation === 'history' && (preview.historical !== true || preview.preserves_crm_history !== true || preview.requires_sync !== false
                || preview.current_address_known !== true || preview.new_address !== preview.current_address)) throw new Error(t('Respuesta inválida del servidor.'));
            if (preview.client_id !== undefined && preview.client_id !== target.owner.client_id) throw new Error(t('La identidad del servicio cambió. Vuelve a verificarlo.'));
            crmEditPreview = preview;
            const review = byId('crm-ip-review'); review.style.display = 'block';
            review.innerHTML = `<dl class="conflict-address-grid"><div><dt>${html(t('IP actual en UISP'))}</dt><dd>${html(typeof preview.current_address === 'string' && preview.current_address.length <= 128
                ? preview.current_address : preview.current_address_known === true ? t('Sin IP asignada') : t('Sin comprobar'))}</dd></div><div><dt>${html(t(operation === 'history' ? 'IP histórica que se excluirá' : 'IP propuesta'))}</dt><dd>${html(preview.new_address ?? t('Sin IP asignada'))}</dd></div></dl>`;
            const note = document.createElement('p'); translatedText(note, operation === 'history'
                ? 'Esta IP de un servicio terminado se excluirá del monitoreo. El historial del CRM, el servicio actual y los routers se conservarán. La exclusión dejará de aplicarse si cambia la IP o se reactiva el servicio.'
                : 'Sólo se modificará este atributo de IP en el CRM. Las demás IP y los demás datos del servicio se conservarán.'); review.appendChild(note);
            if (operation === 'replace') {
                const pending = document.createElement('p'); translatedText(pending, 'La nueva IP no ha sido comprobada contra todos los servicios. La revisión automática actualizará los conflictos.'); review.appendChild(pending);
            }
            translatedText(byId('crm-ip-edit-status'), 'Cambio revisado. Vence: {time}.', { time: formatDate(preview.expiresAt) });
        } catch (error) { if (generation === crmEditGeneration) translatedText(byId('crm-ip-edit-status'), 'Error: {message}', { message: t(error.message) }); }
        finally { if (generation === crmEditGeneration) { crmEditBusy = false; updateCrmEditorControls(); } }
    });

    byId('apply-crm-ip-change')?.addEventListener('click', async () => {
        if (crmEditBusy || crmEditOutcome || !crmEditPreview || !crmEditTarget) return;
        const preview = crmEditPreview, target = crmEditTarget, generation = crmEditGeneration;
        crmEditBusy = true; updateCrmEditorControls();
        try {
            if (!await confirmAction(t(preview.operation === 'history'
                ? '¿Excluir del monitoreo la IP histórica {address} ({family}) del servicio terminado #{id}? Se conservarán el historial del CRM y los routers.'
                : '¿Aplicar el cambio de {family} del servicio #{id} en UISP? IP propuesta: {address}.', {
                family: preview.family, id: preview.service_id, address: preview.new_address ?? t('Sin IP asignada') }))) return;
            if (generation !== crmEditGeneration) return;
            // A confirmed attempt leaves the form permanently. Never replay a
            // consumed token, including an ambiguous HTTP or invalid reply.
            resetCrmEditPreview();
            const outcome = crmEditOutcome = { preview: { ...preview, previewToken: undefined }, target, result: null,
                receiptConfirmed: false, owner: null, checkedAt: null, error: null, checking: true };
            renderCrmEditResult();
            translatedText(byId('crm-ip-result-message'), preview.operation === 'history' ? 'Guardando la resolución histórica...' : 'Guardando el cambio en UISP...');
            try {
                const result = checked(await api.applyCrmNetworkChange(preview.previewToken)).data;
                if (generation !== crmEditGeneration || crmEditOutcome !== outcome) return;
                if (!result || result.service_id !== preview.service_id || result.family !== preview.family || result.operation !== preview.operation
                    || typeof result.applied !== 'boolean' || typeof result.uncertain !== 'boolean' || typeof result.current_address_known !== 'boolean'
                    || (result.current_address_known && result.current_address !== null && (typeof result.current_address !== 'string' || result.current_address.length > 128))
                    || (result.applied === true && result.uncertain === false && (!result.current_address_known || result.current_address !== preview.new_address)))
                    throw new Error(t('Respuesta inválida del servidor.'));
                if (preview.operation === 'history' && (result.historical !== true || result.preserves_crm_history !== true || result.requires_sync !== false))
                    throw new Error(t('Respuesta inválida del servidor.'));
                if (result.write_outcome !== undefined && (!['accepted', 'rejected', 'unconfirmed', 'not_requested'].includes(result.write_outcome)
                    || (result.write_http_status !== null && (!Number.isSafeInteger(result.write_http_status) || result.write_http_status < 100 || result.write_http_status > 599))
                    || (result.write_reason !== null && !['permission_denied', 'not_found', 'method_not_allowed', 'conflict', 'validation_failed', 'rate_limited', 'http_rejected', 'server_unconfirmed', 'transport_unconfirmed'].includes(result.write_reason))))
                    throw new Error(t('Respuesta inválida del servidor.'));
                outcome.result = result; outcome.receiptConfirmed = result.applied === true && result.uncertain === false
                    && (preview.operation === 'history' || result.write_outcome === undefined || result.write_outcome === 'accepted');
            } catch (error) {
                if (generation !== crmEditGeneration || crmEditOutcome !== outcome) return;
                outcome.error = error.message;
            }
            if (generation === crmEditGeneration && crmEditOutcome === outcome) await verifyCrmEditResult(outcome, generation);
        } catch (error) {
            if (generation === crmEditGeneration) notify(t('Error: {message}', { message: t(error.message) }), 'error');
        } finally {
            if (generation === crmEditGeneration) {
                crmEditBusy = false; updateCrmEditorControls(); renderCrmMonitor();
                if (crmEditOutcome) { renderCrmEditResult(); byId('crm-ip-result').focus({ preventScroll: true }); }
            }
        }
    });

    byId('verify-crm-ip-result')?.addEventListener('click', async () => {
        if (crmEditBusy || !crmEditOutcome) return;
        const outcome = crmEditOutcome, generation = crmEditGeneration;
        crmEditBusy = true; updateCrmEditorControls();
        try { await verifyCrmEditResult(outcome, generation); }
        finally {
            if (generation === crmEditGeneration && crmEditOutcome === outcome) {
                crmEditBusy = false; renderCrmEditResult(); renderCrmMonitor();
            }
        }
    });

    byId('resolve-crm-ip-history')?.addEventListener('click', () => {
        if (crmEditBusy || !crmEditOutcome?.historyAvailable) return;
        const { owner, target } = crmEditOutcome;
        openCrmEditor(owner, target.group, 'history');
    });

    byId('cleanup-crm-ip-result')?.addEventListener('click', () => {
        if (crmEditBusy || cleanupRequiresReload || !crmEditOutcome?.cleanupAvailable) return;
        const { preview } = crmEditOutcome;
        closeModals(); previewCleanup([preview.service_id], 'crm_snapshot', preview.family);
    });

    function clearCrmMonitorTimer() { clearTimeout(crmMonitorTimer); crmMonitorTimer = undefined; }
    function expireCrmVerifications(incoming) {
        if (!incoming.available || incoming.last_error || !monitorDate(incoming.checked_at)) return;
        const timestamp = new Date(incoming.completed_scan_started_at || incoming.checked_at).getTime();
        const incomingOwners = new Map(incoming.conflicts.flatMap(group => group.owners).map(owner => [owner.service_id, owner]));
        const incomingGroups = new Map(incoming.conflicts.map(group => [JSON.stringify([group.family, group.address]), group]));
        for (const [id, verification] of crmVerifiedOwners) {
            const owner = incomingOwners.get(id);
            if (timestamp > new Date(monitorDate(verification.owner.address_verified_at) || verification.checked_at).getTime()
                || (owner && (owner.client_id !== verification.owner.client_id
                    || (verification.owner.historical_families?.length > 0 && owner.service_status_code !== 2)))) crmVerifiedOwners.delete(id);
        }
        for (const [key, verification] of crmVerifiedGroups) {
            const group = incomingGroups.get(key);
            const ids = Array.isArray(group?.service_ids) ? group.service_ids : group?.owners?.map(owner => owner.service_id);
            const sameSelection = Array.isArray(ids) && Array.isArray(verification.service_ids) && ids.length === verification.service_ids.length
                && ids.every(id => verification.service_ids.includes(id)) && group.services_truncated !== true
                && (group.service_count === undefined || group.service_count === ids.length)
                && ids.every(id => {
                    const owner = group.owners.find(owner => owner.service_id === id);
                    const prior = verification.owners?.find(owner => owner.service_id === id);
                    return owner?.client_id === prior?.client_id && (!prior?.historical_families?.includes(group.family) || owner?.service_status_code === 2);
                });
            if (!group || !sameSelection || timestamp > new Date(verification.observed_at || verification.checked_at).getTime()) crmVerifiedGroups.delete(key);
            else verification.snapshot_checked_at = incoming.checked_at;
        }
        // A new snapshot may change a service's identity or IP. Such a row
        // cannot inherit a local-cleanup confirmation for an old assignment.
        for (const [key, proof] of crmLocalAssignmentProofs) {
            const [id, family] = JSON.parse(key);
            const prior = crmMonitorReport?.conflicts?.filter(group => group.family === family).find(group => group.owners.some(owner => owner.service_id === id));
            const current = incoming.conflicts.filter(group => group.family === family).find(group => group.owners.some(owner => owner.service_id === id));
            if (timestamp > new Date(proof.checked_at).getTime() || (prior && current && (prior.address !== current.address
                || prior.owners.find(owner => owner.service_id === id)?.client_id !== current.owners.find(owner => owner.service_id === id)?.client_id
                || prior.owners.find(owner => owner.service_id === id)?.service_status_code !== current.owners.find(owner => owner.service_id === id)?.service_status_code))) crmLocalAssignmentProofs.delete(key);
        }
    }
    function scheduleCrmMonitor() {
        clearCrmMonitorTimer();
        if (document.hidden === true || crmMonitorStopped || crmMonitorInFlight) return;
        crmMonitorTimer = setTimeout(function pollCrmMonitor() { crmMonitorTimer = undefined; refreshCrmMonitor(); }, 30000);
    }

    function refreshCrmMonitor() {
        if (crmMonitorInFlight) return crmMonitorInFlight;
        if (document.hidden === true || crmMonitorStopped) return Promise.resolve();
        clearCrmMonitorTimer();
        crmMonitorInFlight = (async () => {
            try {
                const incoming = normalizeCrmMonitor(checked(await api.getCrmNetworkConflicts()).data);
                if (incoming.available && crmMonitorReport?.available
                    && new Date(incoming.checked_at).getTime() < new Date(crmMonitorReport.checked_at).getTime()) throw new Error('old_crm_monitor_report');
                if (['mapping_changed', 'mapping_unavailable'].includes(incoming.last_error)) {
                    crmVerifiedOwners.clear(); crmVerifiedGroups.clear(); crmLocalAssignmentProofs.clear();
                }
                // An incomplete scan cannot replace a completed report with a false zero.
                if (crmMonitorReport?.available && (!incoming.available || incoming.last_error)) {
                    crmMonitorReport = { ...crmMonitorReport, ...incoming, available: true, duplicate_groups: crmMonitorReport.duplicate_groups,
                        conflicts: crmMonitorReport.conflicts, conflicts_truncated: crmMonitorReport.conflicts_truncated,
                        checked_at: crmMonitorReport.checked_at, families: crmMonitorReport.families,
                        unavailable_families: crmMonitorReport.unavailable_families, coverage_complete: crmMonitorReport.coverage_complete,
                        unknown_address_count: crmMonitorReport.unknown_address_count, address_issues: crmMonitorReport.address_issues,
                        address_issues_truncated: crmMonitorReport.address_issues_truncated,
                        historical_assignments_count: crmMonitorReport.historical_assignments_count, historical_assignments: crmMonitorReport.historical_assignments, stale: true };
                } else { expireCrmVerifications(incoming); crmMonitorReport = incoming; }
                crmMonitorFailure = false;
            } catch (_) { crmMonitorFailure = true; }
            finally {
                crmMonitorInFlight = null;
                renderCrmMonitor();
                scheduleCrmMonitor();
            }
        })();
        return crmMonitorInFlight;
    }

    document.addEventListener('visibilitychange', () => {
        clearCrmMonitorTimer();
        if (document.hidden !== true) refreshCrmMonitor();
    });
    globalThis.window?.addEventListener?.('pagehide', () => { crmMonitorStopped = true; clearCrmMonitorTimer(); });
    globalThis.window?.addEventListener?.('pageshow', () => { crmMonitorStopped = false; refreshCrmMonitor(); });

    function retainVerifiedOwners(previous, incoming, selectedService, selectedClient = null) {
        if (!incoming || !Array.isArray(incoming.conflicts)) return incoming;
        const groups = new Map((Array.isArray(previous?.conflicts) ? previous.conflicts : []).map(group =>
            [JSON.stringify([group.family, group.address]), group]));
        return { ...incoming, conflicts: incoming.conflicts.map(group => {
            const priorGroup = groups.get(JSON.stringify([group.family, group.address]));
            if (!priorGroup || !Array.isArray(group.owners)) return group;
            const owners = new Map((Array.isArray(priorGroup.owners) ? priorGroup.owners : []).map(owner => [Number(owner.service_id), owner]));
            return { ...group, owners: group.owners.map(owner => {
                const prior = owners.get(Number(owner.service_id));
                // Service and client verifications have independent outcomes.
                // Reuse facts only for the same assignment and client identity.
                if (!prior || owner.client_id !== prior.client_id) return owner;
                const serviceSelected = Number(owner.service_id) === selectedService;
                const clientSelected = owner.client_id != null && owner.client_id === selectedClient;
                const retained = { ...owner };
                if (!serviceSelected && prior.source === 'crm' && owner.source !== 'crm') {
                    for (const field of ['service_status', 'service_status_code', 'source', 'address_evidence', 'saved_address',
                        'current_address', 'current_address_known', 'address_comparison', 'address_refresh_error', 'address_verified_at']) retained[field] = prior[field];
                }
                const freshClient = owner.client_source === 'crm' || owner.client_refresh_error
                    || (serviceSelected && owner.source === 'crm' && owner.refresh_error);
                const priorClientCheck = prior.client_source === 'crm' || prior.client_refresh_error || prior.client_verified_at;
                if (!clientSelected && !freshClient && priorClientCheck) {
                    for (const field of ['client_name', 'client_archived', 'client_deleted', 'client_source', 'client_refresh_error', 'client_verified_at']) retained[field] = prior[field];
                }
                if (!serviceSelected && prior.refresh_error && !owner.refresh_error) retained.refresh_error = prior.refresh_error;
                return retained;
            }) };
        }) };
    }

    function renderNetworkConflictsTable() {
        const savedPosition = beforeTableUpdate('network_conflicts');
        const tbody = byId('network-conflicts-body');
        tbody.replaceChildren();
        const conflicts = Array.isArray(recoveryDetails?.conflicts) ? recoveryDetails.conflicts : [];
        if (!conflicts.length) {
            tbody.innerHTML = `<tr><td colspan="2" class="empty-state">${html(t('No hay asignaciones duplicadas en este diagnóstico.'))}</td></tr>`;
        }
        for (const conflict of conflicts) {
            const row = document.createElement('tr');
            const address = document.createElement('td');
            address.className = 'conflict-address';
            address.innerHTML = `<strong>${html(conflict.address)}</strong><span class="text-muted">${html(conflict.family)}</span><span class="badge badge-warning">${html(t('{count} asignaciones guardadas', { count: Number(conflict.service_count) || (conflict.service_ids || []).length }))}</span>`;
            const ownersCell = document.createElement('td');
            ownersCell.className = 'conflict-owners';
            const known = new Set();
            const owners = Array.isArray(conflict.owners) ? conflict.owners.slice(0, 50) : [];
            for (const owner of owners) known.add(Number(owner?.service_id));
            // Older diagnostics can contain service IDs before client details are available.
            for (const id of Array.isArray(conflict.service_ids) ? conflict.service_ids.slice(0, 50) : [])
                if (!known.has(Number(id))) owners.push({ service_id: id });
            for (const owner of owners.slice(0, 50)) {
                if (!owner || typeof owner !== 'object') continue;
                const item = document.createElement('div');
                item.className = 'conflict-owner';
                item.dataset.serviceId = String(owner.service_id);
                const identity = document.createElement('div');
                identity.className = 'conflict-identity';
                const clientLabel = owner.client_name || (owner.client_id ? t('Cliente #{id}', { id: owner.client_id }) : t('Cliente sin identificar'));
                const clientLink = conflictLink(clientLabel, owner.client_url, 'client');
                const archived = owner.client_archived === true || owner.client_archived === 1;
                const notArchived = owner.client_archived === false || owner.client_archived === 0;
                if (archived && owner.client_source === 'crm') clientLink.className += ' client-archived-name';
                identity.appendChild(clientLink);
                const meta = document.createElement('div');
                meta.className = 'conflict-owner-meta';
                meta.appendChild(conflictLink(t('Servicio #{id}', { id: owner.service_id }), owner.service_url, 'service'));
                const knownState = owner.service_status && owner.service_status !== 'Sin verificar';
                const state = knownState ? t(String(owner.service_status)) : t('Sin comprobar');
                const serviceState = knownState && owner.source !== 'crm' ? t('{state} (dato guardado)', { state }) : state;
                const states = [t('Servicio: {state}', { state: serviceState })];
                if (owner.client_deleted === true || owner.client_deleted === 1) states.push(t('Cliente eliminado'));
                for (const label of states) {
                    const badge = document.createElement('span');
                    badge.className = 'badge badge-neutral';
                    badge.textContent = t(String(label));
                    meta.appendChild(badge);
                }
                const archiveBadge = document.createElement('span');
                archiveBadge.className = `badge badge-${archived ? 'warning' : 'neutral'} client-lifecycle`;
                const clientState = t(archived ? 'Cliente archivado' : notArchived ? 'Cliente no archivado' : 'Archivo del cliente sin comprobar');
                archiveBadge.textContent = (archived || notArchived) && owner.client_source !== 'crm' ? t('{state} (dato guardado)', { state: clientState }) : clientState;
                meta.appendChild(archiveBadge);
                identity.appendChild(meta);
                const source = document.createElement('p');
                source.className = 'conflict-source';
                const sourceLabel = { database: 'Base del plugin', cache: 'Caché del plugin', crm: 'Servicio verificado en UISP', missing: 'Registro de IP sin ficha local de servicio' };
                translatedText(source, sourceLabel[owner.source] || 'Registro de IP sin ficha local de servicio');
                identity.appendChild(source);
                if (owner.client_source === 'crm' || owner.source === 'crm') {
                    const clientSource = document.createElement('p');
                    clientSource.className = 'conflict-source';
                    translatedText(clientSource, owner.client_source === 'crm' ? (owner.client_verified_at ? 'Cliente verificado en UISP: {time}' : 'Cliente verificado en UISP')
                        : owner.client_source === 'cache' ? 'Cliente: datos guardados' : 'Cliente: sin datos guardados', { time: formatDate(owner.client_verified_at) });
                    identity.appendChild(clientSource);
                }
                if (owner.saved_address !== undefined || owner.address_comparison !== undefined) {
                    const evidence = document.createElement('div'); evidence.className = 'conflict-address-evidence';
                    const savedAddress = owner.saved_address ?? conflict.address;
                    const liveAddress = owner.current_address_known === true ? (owner.current_address ?? t('Sin IP asignada')) : t('Sin comprobar');
                    evidence.innerHTML = `<dl class="conflict-address-grid"><div><dt>${html(t('IP guardada'))}</dt><dd>${html(savedAddress)}</dd></div><div><dt>${html(t('IP actual en UISP'))}</dt><dd>${html(liveAddress)}</dd></div></dl>`;
                    const comparison = owner.current_address_known === true && ['same', 'removed', 'changed'].includes(owner.address_comparison) ? owner.address_comparison : 'unknown';
                    const badge = document.createElement('span');
                    badge.className = `badge badge-${comparison === 'same' ? 'success' : comparison === 'unknown' ? 'neutral' : 'warning'}`;
                    translatedText(badge, { same: 'Coincide con la IP guardada', removed: 'IP retirada en UISP', changed: 'IP cambiada en UISP', unknown: 'IP actual sin comprobar' }[comparison]);
                    evidence.appendChild(badge);
                    if (owner.address_verified_at) {
                        const checkedAt = document.createElement('small'); checkedAt.className = 'conflict-source';
                        translatedText(checkedAt, 'Servicio consultado en UISP: {time}', { time: formatDate(owner.address_verified_at) }); evidence.appendChild(checkedAt);
                    }
                    if (comparison === 'unknown') {
                        const note = document.createElement('small');
                        translatedText(note, 'No hay evidencia suficiente para confirmar la IP actual. Una IP sin comprobar no autoriza su limpieza.'); evidence.appendChild(note);
                    }
                    identity.appendChild(evidence);
                }
                if (owner.refresh_error && (owner.source !== 'crm' || (owner.client_source !== 'crm' && !owner.client_refresh_error))) {
                    const error = document.createElement('p');
                    error.className = 'conflict-refresh-error';
                    translatedText(error, owner.refresh_error === 'deadline' ? 'La consulta a UISP agotó el tiempo disponible. Vuelve a verificar este servicio.'
                        : owner.source === 'crm' && owner.client_source !== 'crm' ? 'No se pudo verificar el cliente en UISP. Se conservan los datos guardados.'
                        : 'UISP no devolvió este servicio. Puede estar eliminado o no ser accesible; compruébalo en el CRM.');
                    identity.appendChild(error);
                }
                if (owner.client_refresh_error) {
                    const clientError = document.createElement('p');
                    clientError.className = 'conflict-refresh-error conflict-client-error';
                    translatedText(clientError, owner.client_refresh_error === 'deadline'
                        ? 'La consulta del cliente a UISP agotó el tiempo disponible. Vuelve a verificar el cliente.'
                        : 'No se pudo verificar el cliente en UISP. Se conservan los datos guardados.');
                    identity.appendChild(clientError);
                }
                item.appendChild(identity);
                const id = Number(owner.service_id);
                if (Number.isSafeInteger(id) && id > 0) {
                    const verify = document.createElement('button');
                    verify.type = 'button'; verify.className = 'btn btn-secondary conflict-verify';
                    translatedText(verify, verifyingService === id ? 'Verificando...' : 'Verificar en UISP');
                    verify.disabled = verifyingService !== null || verifyingClient !== null || cleanupBusy || cleanupRequiresReload;
                    verify.addEventListener('click', async () => {
                        if (verifyingService !== null || verifyingClient !== null || cleanupBusy || cleanupRequiresReload) return;
                        const previous = recoveryDetails;
                        verifyingService = id;
                        renderNetworkConflictsTable();
                        try {
                            const response = checked(await api.getNetworkConflicts({ refresh: true, serviceIds: [id] }));
                            recoveryDetails = retainVerifiedOwners(previous, response.data, id);
                            applyRecovery({ readOnly: true, details: recoveryDetails });
                        } catch (error) { notify(error.message, 'error'); }
                        finally {
                            verifyingService = null;
                            if (currentView === 'network_conflicts') renderNetworkConflictsTable();
                        }
                    });
                    // Keep verification first for keyboard users and cached panel layouts.
                    item.appendChild(verify);
                    const cleanup = document.createElement('button');
                    cleanup.type = 'button'; cleanup.className = 'btn btn-secondary conflict-cleanup';
                    translatedText(cleanup, 'Revisar limpieza');
                    cleanup.disabled = verifyingService !== null || verifyingClient !== null || cleanupBusy || cleanupRequiresReload;
                    cleanup.addEventListener('click', () => previewCleanup([id]));
                    item.appendChild(cleanup);
                    const clientId = Number(owner.client_id);
                    if (Number.isSafeInteger(clientId) && clientId > 0) {
                        const verifyClient = document.createElement('button');
                        verifyClient.type = 'button'; verifyClient.className = 'btn btn-secondary conflict-client-verify';
                        translatedText(verifyClient, verifyingClient === clientId ? 'Verificando cliente...' : 'Verificar cliente');
                        verifyClient.disabled = verifyingService !== null || verifyingClient !== null || cleanupBusy || cleanupRequiresReload;
                        verifyClient.addEventListener('click', async () => {
                            if (verifyingService !== null || verifyingClient !== null || cleanupBusy || cleanupRequiresReload) return;
                            const previous = recoveryDetails;
                            verifyingClient = clientId;
                            renderNetworkConflictsTable();
                            try {
                                const response = checked(await api.getNetworkConflicts({ refreshClients: true, serviceIds: [id] }));
                                recoveryDetails = retainVerifiedOwners(previous, response.data, null, clientId);
                                applyRecovery({ readOnly: true, details: recoveryDetails });
                            } catch (error) { notify(error.message, 'error'); }
                            finally {
                                verifyingClient = null;
                                if (currentView === 'network_conflicts') renderNetworkConflictsTable();
                            }
                        });
                        item.appendChild(verifyClient);
                    }
                }
                ownersCell.appendChild(item);
            }
            if (conflict.services_truncated) {
                const more = document.createElement('p');
                more.className = 'form-note';
                translatedText(more, 'Se muestran las primeras {shown} de {total} asignaciones guardadas.', { shown: Math.min(owners.length, 50), total: Number(conflict.service_count) || 0 });
                ownersCell.appendChild(more);
            }
            row.append(address, ownersCell);
            tbody.appendChild(row);
        }
        const limit = byId('network-conflicts-limit');
        limit.style.display = recoveryDetails?.conflicts_truncated ? 'block' : 'none';
        translatedText(limit, 'Se muestran {shown} de {total} conflictos. El diagnóstico conserva el total y una muestra acotada.', { shown: conflicts.length, total: Number(recoveryDetails?.duplicate_groups) || conflicts.length });
        byId('download-network-conflicts').href = api.downloadUrl('network-conflicts');
        byId('download-network-conflicts').download = 'network-conflicts.json';
        afterTableUpdate('network_conflicts', savedPosition);
    }

    function cleanupReason(reason) {
        return t({
            service_exists: 'La IP actual coincide con la reserva guardada. Se conserva la asignación.',
            crm_unavailable: 'No se pudo comprobar el servicio en UISP. Se conserva la asignación.',
            deadline: 'La consulta a UISP agotó el tiempo disponible. Se conserva la asignación.',
            no_live_peer: 'No se confirmó otro servicio existente que comparta esta IP. Se conserva la asignación.',
            not_in_conflict: 'La asignación ya no forma parte de un conflicto.',
            address_unknown: 'No se pudo confirmar la IP actual en UISP. Se conserva la asignación.',
            identity_changed: 'La identidad del servicio cambió. Se conserva la asignación.',
            local_changed: 'Los datos locales cambiaron. Revisa la limpieza nuevamente.',
            queue_busy: 'Hay trabajos pendientes, en ejecución o en recuperación, o recursos guardados como aplicados en routers. Se conserva la asignación; revisa Trabajos y esos recursos antes de retirarla.'
        }[reason] || 'No se pudo confirmar que esta asignación pueda limpiarse.');
    }

    function updateCleanupButton() {
        const selected = cleanupBoxes.filter(box => box.checked).length;
        byId('apply-network-cleanup').disabled = cleanupBusy || verifyingService !== null || verifyingClient !== null || !cleanupPreview || selected === 0 || selected > 3;
    }

    async function previewCleanup(serviceIds, source = 'stored', family = null) {
        if (cleanupBusy || verifyingService !== null || verifyingClient !== null || crmVerifyBusy || crmEditBusy || cleanupRequiresReload || !cleanupModal) return;
        const ids = [...new Set(serviceIds.map(Number))].filter(id => Number.isSafeInteger(id) && id > 0).slice(0, 3);
        if (!ids.length) return;
        cleanupBusy = true; cleanupPreview = null; cleanupBoxes = []; cleanupSource = ['crm_snapshot', 'job_blocker'].includes(source) ? source : 'stored';
        cleanupFamily = ['IPv4', 'IPv6'].includes(family) ? family : null;
        cleanupGroup = cleanupSource === 'crm_snapshot' ? crmMonitorReport?.conflicts.find(group => group.family === cleanupFamily
            && group.owners.some(owner => ids.includes(owner.service_id))) || null : null;
        cleanupModal.style.display = 'flex';
        byId('network-cleanup-candidates').replaceChildren();
        byId('network-cleanup-blocked').replaceChildren();
        byId('network-cleanup-result').replaceChildren();
        byId('network-cleanup-result').style.display = 'none';
        byId('reload-after-cleanup').style.display = 'none';
        byId('apply-network-cleanup').style.display = 'inline-flex';
        translatedText(byId('network-cleanup-status'), 'Comprobando qué asignaciones pueden limpiarse...');
        updateCleanupButton();
        renderNetworkConflictsTable();
        renderCrmMonitor();
        try {
            const data = checked(await api.previewNetworkCleanup(ids, cleanupSource)).data;
            if (!data || !Array.isArray(data.candidates) || !Array.isArray(data.blocked)
                || (data.candidates.length && (typeof data.previewToken !== 'string' || !/^[a-f0-9]{64}$/.test(data.previewToken)))) throw new Error(t('Respuesta inválida del servidor.'));
            cleanupPreview = data;
            for (const candidate of data.candidates.slice(0, 3)) {
                const id = Number(candidate?.service_id);
                const stale = candidate.reason === 'stale_address';
                const fields = [...new Set(Array.isArray(candidate.fields) ? candidate.fields : [])].filter(field => ['address', 'address6'].includes(field));
                const evidence = Array.isArray(candidate.evidence) ? candidate.evidence : [];
                const reviewedEvidence = fields.map(field => evidence.find(entry => entry?.field === field && typeof entry.saved_address === 'string'
                    && ((entry.state === 'removed' && entry.current_address === null)
                        || (entry.state === 'changed' && typeof entry.current_address === 'string' && entry.current_address !== entry.saved_address)
                        || (entry.state === 'historical' && candidate.historical_reviewed === true && entry.current_address === entry.saved_address))));
                if (!ids.includes(id) || (!stale && candidate.reason !== 'crm_not_found') || (stale && (!fields.length || reviewedEvidence.some(entry => !entry)))) continue;
                const row = document.createElement('label'); row.className = 'cleanup-candidate';
                const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.value = String(id);
                checkbox.addEventListener('change', updateCleanupButton); cleanupBoxes.push(checkbox);
                const copy = document.createElement('span');
                const addresses = stale ? reviewedEvidence.map(entry => entry.saved_address) : [candidate.address, candidate.address6].filter(Boolean);
                copy.innerHTML = `<strong>${html(t('Asignaciones del servicio #{id}', { id }))}</strong><span class="cleanup-address">${html(addresses.join(' · '))}</span>`;
                const explanation = document.createElement('small');
                translatedText(explanation, stale ? reviewedEvidence.some(entry => entry.state === 'historical')
                    ? 'La reserva corresponde a una asignación histórica revisada de un servicio terminado. Sólo se retirará la reserva local; el historial del CRM y el servicio actual se conservarán.'
                    : 'UISP confirmó que las IP guardadas indicadas fueron retiradas o cambiadas. Sólo se retirarán esas asignaciones locales; la ficha del servicio y las demás familias se conservarán.'
                    : 'UISP confirmó que el servicio no existe. Otro servicio del conflicto está verificado en UISP y se conservará.');
                copy.appendChild(explanation);
                if (stale) for (const entry of reviewedEvidence) {
                    const comparison = document.createElement('small'); comparison.className = 'cleanup-address-comparison';
                    translatedText(comparison, 'IP actual en UISP ({family}): {address}', { family: entry.field === 'address6' ? 'IPv6' : 'IPv4', address: entry.current_address ?? t('Sin IP asignada') });
                    copy.appendChild(comparison);
                }
                const peers = (Array.isArray(candidate.witness_service_ids) ? candidate.witness_service_ids : []).map(Number).filter(peer => Number.isSafeInteger(peer) && peer > 0);
                if (peers.length) {
                    const witnesses = document.createElement('div'); witnesses.className = 'cleanup-witnesses';
                    for (const peer of peers) witnesses.appendChild(conflictLink(t('Servicio conservado #{id}', { id: peer }), '/crm/client/service/' + peer, 'service'));
                    copy.appendChild(witnesses);
                }
                row.append(checkbox, copy); byId('network-cleanup-candidates').appendChild(row);
            }
            let cleanedCount = 0;
            for (const blocked of data.blocked) {
                if (!blocked || !Number.isSafeInteger(blocked.service_id) || blocked.service_id < 1) continue;
                if (cleanupSource === 'crm_snapshot' && ids.includes(blocked.service_id)) recordCrmLocalAssignments(blocked.service_id, blocked.local_assignment_state, blocked.checked_at);
                const field = cleanupFamily === 'IPv6' ? 'address6' : cleanupFamily === 'IPv4' ? 'address' : null;
                const cleaned = blocked.reason === 'no_local_assignment' || (cleanupSource === 'crm_snapshot' && field
                    && blocked.local_assignment_state?.[field]?.known === true && blocked.local_assignment_state[field].present === false);
                const row = document.createElement('div'); row.className = 'cleanup-blocked-item';
                if (cleaned) { row.className += ' is-cleaned'; if (ids.includes(blocked.service_id)) cleanedCount++; }
                row.innerHTML = `<strong>${html(t('Servicio #{id}', { id: blocked.service_id }))}${cleaned ? ' · ' + html(t(crmAssignmentCleaned(blocked.service_id, cleanupFamily)?.cleaned ? 'Ya limpiada' : 'Sin reserva local')) : ''}</strong><p>${html(cleaned
                    ? t('No hay reservas locales de esta IP que limpiar.') : cleanupReason(blocked.reason))}</p>`;
                byId('network-cleanup-blocked').appendChild(row);
            }
            translatedText(byId('network-cleanup-status'), cleanupBoxes.length ? 'Selecciona las asignaciones que deseas limpiar. La revisión vence: {time}.'
                : cleanedCount === ids.length ? 'No hay reservas locales seleccionadas que limpiar.' : 'No hay asignaciones que puedan limpiarse con seguridad en esta revisión.', { time: formatDate(data.expiresAt) });
        } catch (error) { translatedText(byId('network-cleanup-status'), 'Error: {message}', { message: t(error.message) }); }
        finally { cleanupBusy = false; updateCleanupButton(); if (currentView === 'network_conflicts') { renderNetworkConflictsTable(); renderCrmMonitor(); } }
    }

    byId('apply-network-cleanup')?.addEventListener('click', async () => {
        if (cleanupBusy || verifyingService !== null || verifyingClient !== null || !cleanupPreview) return;
        const ids = cleanupBoxes.filter(box => box.checked).map(box => Number(box.value));
        if (!ids.length || ids.length > 3) return;
        const originGroup = cleanupGroup;
        cleanupBusy = true; updateCleanupButton();
        try {
            if (!await confirmAction(t('¿Limpiar las asignaciones locales de {count} servicios seleccionados? Se creará un respaldo. UISP y los routers no se modificarán.', { count: ids.length }))) return;
            translatedText(byId('network-cleanup-status'), 'Creando respaldo y limpiando asignaciones locales...');
            const result = checked(await api.applyNetworkCleanup(cleanupPreview.previewToken, ids, cleanupSource)).data;
            if (!result || !Array.isArray(result.removed_service_ids) || !Number.isSafeInteger(result.duplicate_groups) || result.duplicate_groups < 0
                || result.removed_service_ids.some(id => !Number.isSafeInteger(id) || !ids.includes(id))
                || typeof result.requires_reload !== 'boolean') throw new Error(t('Respuesta inválida del servidor.'));
            if (cleanupSource === 'crm_snapshot') for (const id of result.removed_service_ids) {
                const candidate = cleanupPreview.candidates.find(entry => entry.service_id === id);
                const fields = candidate?.reason === 'crm_not_found' ? ['address', 'address6'] : candidate?.fields || [];
                for (const field of fields) if (['address', 'address6'].includes(field)) recordCrmLocalAssignments(id,
                    { [field]: { known: true, present: false } }, monitorDate(result.local_assignment_checked_at) || new Date().toISOString(), true);
            }
            const backupUrl = api.downloadUrl(result.backup_name);
            cleanupRequiresReload = result.requires_reload; cleanupPreview = null;
            if (cleanupSource === 'stored' || recoveryReadOnly) cleanupRemainingCount = result.duplicate_groups;
            cleanupBoxes.forEach(box => { box.disabled = true; });
            const summary = document.createElement('p');
            translatedText(summary, cleanupSource !== 'stored' && !recoveryReadOnly
                ? result.removed_service_ids.length === 1 ? 'Se limpiaron las reservas locales de {count} servicio. La verificación del CRM se muestra por separado.'
                    : 'Se limpiaron las reservas locales de {count} servicios. La verificación del CRM se muestra por separado.'
                : 'Se retiraron las asignaciones de {count} servicios. Quedan {remaining} conflictos de IP.', { count: result.removed_service_ids.length, remaining: result.duplicate_groups });
            const backup = document.createElement('a'); backup.className = 'btn btn-secondary'; backup.href = backupUrl; backup.download = result.backup_name;
            translatedText(backup, 'Descargar respaldo de la limpieza');
            byId('network-cleanup-result').append(summary, backup); byId('network-cleanup-result').style.display = 'block';
            translatedText(byId('network-cleanup-status'), result.requires_reload
                ? 'La limpieza terminó. Recarga el panel para comprobar las asignaciones y completar la actualización.'
                : 'La limpieza local terminó. Puedes seguir usando el panel.');
            byId('apply-network-cleanup').style.display = 'none'; byId('reload-after-cleanup').style.display = result.requires_reload ? 'inline-flex' : 'none';
            if (recoveryReadOnly) applyRecovery({ readOnly: true });
            if (cleanupSource === 'crm_snapshot' && result.removed_service_ids.length) {
                try {
                    const group = originGroup && crmMonitorReport?.conflicts.find(group => group.family === originGroup.family && group.address === originGroup.address);
                    if (group) await verifyCrmOwners(group, result.removed_service_ids[0]);
                    else await refreshCrmMonitor();
                } catch (_) {
                    translatedText(byId('network-cleanup-status'), 'La limpieza local terminó, pero no se pudo actualizar la verificación del CRM. Vuelve a verificar el conflicto.');
                }
            }
        } catch (error) { translatedText(byId('network-cleanup-status'), 'Error: {message}', { message: t(error.message) }); }
        finally { cleanupBusy = false; updateCleanupButton(); if (currentView === 'network_conflicts') { renderNetworkConflictsTable(); renderCrmMonitor(); } }
    });
    byId('reload-after-cleanup')?.addEventListener('click', reloadPanel);

    async function renderNetworkConflicts() {
        renderCrmMonitor();
        await refreshInvalidReservations();
        if (!crmMonitorReport) await refreshCrmMonitor();
        if (recoveryReadOnly && !recoveryDetails) {
            const response = checked(await api.getNetworkConflicts());
            recoveryDetails = response.data;
        }
        if (recoveryReadOnly) renderNetworkConflictsTable();
    }

    const repairFields = { address: 'IPv4', address6: 'IPv6' };
    function repairServiceStatus(value) {
        return t(Number.isInteger(value) ? ['Preparado', 'Activo', 'Terminado', 'Suspendido', 'Preparado bloqueado', 'Obsoleto', 'Diferido', 'Cotizado', 'Inactivo'][value] || 'Sin comprobar' : 'Sin comprobar');
    }
    function repairValues(values) {
        return Array.isArray(values) && values.length > 0 && values.length <= 2
            && values.every(entry => entry && ['network', 'legacy'].includes(entry.source) && typeof entry.value === 'string');
    }
    function repairValueMarkup(values) {
        return values.map(entry => `<div><span>${html(t(entry.source === 'network' ? 'Reserva local' : 'Reserva heredada'))}</span><strong>${html(entry.value.slice(0, 256))}${entry.value.length > 256 ? '…' : ''}</strong></div>`).join('');
    }
    function renderInvalidReservations() {
        const list = byId('invalid-reservations-list');
        if (!list) return;
        list.replaceChildren();
        for (const row of repairReport?.rows || []) {
            const item = document.createElement('article'); item.className = 'repair-list-item';
            const identity = document.createElement('div'); identity.className = 'conflict-owner-meta';
            identity.appendChild(conflictLink(t('Servicio #{id}', { id: row.service_id }), '/crm/client/service/' + row.service_id, 'service'));
            if (Number.isSafeInteger(row.client_id) && row.client_id > 0) identity.appendChild(conflictLink(t('Cliente #{id}', { id: row.client_id }), '/crm/client/' + row.client_id, 'client'));
            const state = document.createElement('span'); state.className = 'badge badge-neutral'; translatedText(state, 'Servicio: {state}', { state: repairServiceStatus(row.status) }); identity.appendChild(state);
            item.appendChild(identity);
            for (const field of row.fields) {
                const family = document.createElement('div'); family.className = 'repair-family';
                const copy = document.createElement('div'); copy.className = 'repair-address-values';
                copy.innerHTML = `<strong>${html(field.family)}</strong>${repairValueMarkup(field.sources)}`;
                const review = document.createElement('button'); review.type = 'button'; review.className = 'btn btn-secondary';
                translatedText(review, 'Comparar con UISP'); review.disabled = repairBusy || repairRequiresReload;
                review.addEventListener('click', () => previewNetworkRepair(row.service_id, field.field));
                family.append(copy, review); item.appendChild(family);
            }
            list.appendChild(item);
        }
        const count = repairReport?.total;
        translatedText(byId('invalid-reservations-status'), repairRequiresReload ? 'Recarga el panel para comprobar las reservas y continuar con el estado actualizado.' : repairListInFlight ? 'Buscando reservas locales inválidas...'
            : repairListFailure ? repairReport ? 'No se pudo actualizar la lista. Se conserva la última consulta de reservas inválidas.' : 'No se pudo consultar la lista de reservas inválidas.'
            : !repairReport ? 'Sin actualizar.' : repairReport?.truncated ? 'Se muestran {shown} de {count} servicios con reservas inválidas.'
            : count > 0 ? 'Servicios con reservas inválidas: {count}. La comparación con UISP requiere una revisión explícita.' : 'No se encontraron reservas locales inválidas.',
            { count: count ?? 0, shown: repairReport?.rows.length ?? 0 });
        byId('refresh-invalid-reservations').disabled = repairListInFlight !== null || repairBusy;
        byId('reload-after-reservation-list-repair').style.display = repairRequiresReload ? 'inline-flex' : 'none';
    }
    function refreshInvalidReservations() {
        if (!byId('invalid-reservations-list') || typeof api.getInvalidNetworkReservations !== 'function') return Promise.resolve();
        if (repairListInFlight) return repairListInFlight;
        const work = (async () => {
            try {
                const data = checked(await api.getInvalidNetworkReservations()).data;
                if (!data || !Array.isArray(data.rows) || data.rows.length > 100 || !Number.isSafeInteger(data.total) || data.total < data.rows.length
                    || data.truncated !== (data.total > data.rows.length) || !monitorDate(data.checked_at)
                    || new Set(data.rows.map(row => row?.service_id)).size !== data.rows.length
                    || data.rows.some(row => !row || !Number.isSafeInteger(row.service_id) || row.service_id < 1 || !Array.isArray(row.fields) || !row.fields.length || row.fields.length > 2
                        || new Set(row.fields.map(field => field?.field)).size !== row.fields.length
                        || row.fields.some(field => !field || !Object.prototype.hasOwnProperty.call(repairFields, field.field) || repairFields[field.field] !== field.family || !repairValues(field.sources)))) throw new Error(t('Respuesta inválida del servidor.'));
                repairReport = data; repairListFailure = false;
            } catch (_) { repairListFailure = true; }
            finally { repairListInFlight = null; renderInvalidReservations(); }
        })();
        repairListInFlight = work; renderInvalidReservations(); return work;
    }
    function repairReason(reason) {
        return t({
            queue_busy: 'Hay trabajos en ejecución, recuperación o recursos aplicados que impiden corregir esta reserva con seguridad.',
            applied_ownership: 'Hay recursos guardados como aplicados en un router. Se conserva la reserva hasta comprobar esos recursos.',
            running_job: 'Un trabajo está en ejecución. Espera a que termine y vuelve a comparar.',
            recovery_job: 'Hay un trabajo en recuperación. Revisa Trabajos antes de corregir la reserva.',
            crm_unavailable: 'No se pudo comprobar el servicio en UISP. Se conserva la reserva.',
            address_unknown: 'UISP no confirmó una dirección válida ni una retirada de IP. Se conserva la reserva.',
            identity_changed: 'La identidad del servicio cambió. Se conserva la reserva.',
            local_changed: 'La reserva local cambió. Compara nuevamente antes de corregirla.',
            valid_reservation: 'La reserva ya es válida. No hay una corrección pendiente.',
            no_invalid_reservation: 'No hay una reserva inválida seleccionada que corregir.',
            collision: 'La IP de UISP coincide con una reserva de otro servicio. Se conserva la reserva hasta resolver el conflicto.',
            deadline: 'La consulta a UISP agotó el tiempo disponible. Vuelve a comparar.'
        }[reason] || {
            identity_unknown: 'No se confirmó la identidad del servicio. Se conserva la reserva.',
            local_identity_invalid: 'El registro local tiene una identidad inválida. Se conserva la reserva hasta revisar el servicio y su cliente.',
            cached_identity_changed: 'El cliente guardado en caché no coincide con UISP. Se conserva la reserva para proteger los servicios actuales.',
            crm_address_unknown: 'UISP no confirmó una dirección válida ni una retirada de IP. Se conserva la reserva.',
            crm_ownership_unknown: 'No se pudieron comprobar todas las IP de otros servicios en UISP. Revisa los atributos IP del CRM y vuelve a comparar. Se conserva la reserva.',
            crm_ownership_limit: 'La comprobación de propietarios de UISP superó el límite. Se conserva la reserva.',
            crm_address_changed: 'La IP de UISP cambió durante la revisión. Compara nuevamente.',
            address_in_use: 'La IP de UISP coincide con una reserva de otro servicio. Se conserva la reserva hasta resolver el conflicto.',
            valid_local_reservation: 'Existe una reserva local válida que se conservará. Revisa la diferencia con UISP antes de corregir.',
            local_ownership_unknown: 'No se pudo comprobar la propiedad de las reservas locales. Se conserva la reserva.',
            local_ownership_limit: 'La comprobación local superó el límite. Se conserva la reserva.',
            queue_unknown: 'No se pudo comprobar la cola de trabajos. Se conserva la reserva.',
            queue_recovery: 'Hay un trabajo en recuperación. Revisa Trabajos antes de corregir la reserva.',
            pending_identity_changed: 'Un trabajo pendiente corresponde a otra identidad del servicio. Se conserva la reserva.',
            pending_address_changed: 'Un trabajo pendiente solicita una IP diferente a UISP. Se conserva la reserva.',
            legacy_queue_pending: 'Hay trabajos heredados pendientes que no pueden comprobarse con seguridad. Se conserva la reserva.',
            repair_local_changed: 'La reserva local cambió. Compara nuevamente antes de corregirla.',
            repair_verification_failed: 'La verificación cambió antes de guardar. Compara nuevamente.',
            repair_preview_expired: 'La revisión venció. Compara nuevamente antes de guardar.',
            repair_backup_failed: 'No se pudo crear el respaldo. La corrección no se confirmó.',
            repair_busy: 'Hay otra operación en curso. Espera a que termine y vuelve a comparar.'
        }[reason] || 'No se pudo confirmar que esta reserva pueda corregirse con seguridad.');
    }
    function updateRepairControls() {
        byId('apply-network-repair').disabled = repairBusy || repairRequiresReload || !repairPreview?.previewToken || !repairPreview.candidates?.length;
        byId('recheck-network-repair').disabled = repairBusy || repairRequiresReload || !repairTarget;
        for (const button of document.querySelectorAll('.job-repair-button')) {
            button.disabled = repairBusy || repairRequiresReload;
            if (repairRequiresReload) button.title = t('Recarga el panel para comprobar las reservas y continuar con el estado actualizado.');
        }
        renderInvalidReservations();
    }
    async function previewNetworkRepair(serviceId, field) {
        if (repairBusy || repairRequiresReload || !repairModal || !Number.isSafeInteger(serviceId) || serviceId < 1 || !Object.prototype.hasOwnProperty.call(repairFields, field)) return;
        repairTarget = { serviceId, field }; repairPreview = null; repairBusy = true;
        const generation = ++repairGeneration;
        repairModal.style.display = 'flex';
        translatedText(byId('network-repair-title'), 'Corregir reserva inválida');
        byId('network-repair-result').style.display = 'none'; byId('network-repair-result').replaceChildren();
        byId('network-repair-comparison').replaceChildren(); byId('network-repair-comparison').style.display = 'grid';
        byId('apply-network-repair').style.display = 'inline-flex'; byId('recheck-network-repair').style.display = 'inline-flex'; byId('reload-after-repair').style.display = 'none';
        translatedText(byId('network-repair-status'), 'Comparando la reserva del servicio #{id} con UISP...', { id: serviceId }); updateRepairControls();
        try {
            const data = checked(await api.previewNetworkRepair([{ serviceId, fields: [field] }])).data;
            if (generation !== repairGeneration) return;
            if (!data || !Array.isArray(data.candidates) || !Array.isArray(data.blocked) || data.candidates.length > 1 || data.blocked.length > 1
                || data.candidates.length + data.blocked.length !== 1 || data.blocked.some(row => row?.service_id !== serviceId || typeof row.reason !== 'string')
                || data.candidates.some(candidate => candidate.service_id !== serviceId || !Array.isArray(candidate.fields) || candidate.fields.length !== 1
                    || candidate.fields.some(entry => entry.field !== field || entry.family !== repairFields[field] || !repairValues(entry.saved)
                        || !['replace', 'clear'].includes(entry.action) || (entry.action === 'clear' ? entry.current_address !== null : typeof entry.current_address !== 'string')
                        || !['crm_address', 'crm_no_address', 'crm_not_found'].includes(entry.reason)))
                || data.candidates.length > 0 && (typeof data.previewToken !== 'string' || !/^[a-f0-9]{64}$/.test(data.previewToken) || !monitorDate(data.expiresAt))) throw new Error(t('Respuesta inválida del servidor.'));
            repairPreview = data;
            for (const candidate of data.candidates) {
                const item = document.createElement('article'); item.className = 'repair-list-item';
                const identity = document.createElement('div'); identity.className = 'conflict-owner-meta';
                identity.appendChild(conflictLink(t('Servicio #{id}', { id: serviceId }), '/crm/client/service/' + serviceId, 'service'));
                if (Number.isSafeInteger(candidate.client_id) && candidate.client_id > 0) identity.appendChild(conflictLink(t('Cliente #{id}', { id: candidate.client_id }), '/crm/client/' + candidate.client_id, 'client'));
                const state = document.createElement('span'); state.className = 'badge badge-neutral'; translatedText(state, 'Servicio: {state}', { state: repairServiceStatus(candidate.status) }); identity.appendChild(state); item.appendChild(identity);
                for (const entry of candidate.fields) {
                    const comparison = document.createElement('div'); comparison.className = 'repair-comparison';
                    comparison.innerHTML = `<div class="repair-address-values">${repairValueMarkup(entry.saved)}</div><div class="repair-address-values"><span>${html(t('IP actual en UISP'))} · ${html(entry.family)}</span><strong>${html(entry.current_address ?? t('Sin IP asignada'))}</strong></div>`;
                    item.appendChild(comparison);
                    const note = document.createElement('p'); note.className = 'form-note';
                    translatedText(note, entry.reason === 'crm_not_found' ? 'UISP confirmó que el servicio no existe. Sólo se retirará la reserva local inválida revisada.'
                        : entry.action === 'clear' ? 'UISP confirmó que esta familia no tiene IP asignada. Sólo se retirará la reserva local inválida revisada.'
                        : 'La reserva inválida se sustituirá por la IP válida confirmada en UISP. No se sincronizará el router ni se ejecutarán trabajos.'); item.appendChild(note);
                }
                if (Number.isSafeInteger(candidate.pending_jobs) && candidate.pending_jobs > 0) {
                    const jobs = document.createElement('p'); jobs.className = 'form-note'; translatedText(jobs, candidate.pending_jobs === 1
                        ? 'Este servicio tiene 1 trabajo pendiente. La corrección no lo ejecutará; los reintentos continuarán según su programación.'
                        : 'Este servicio tiene {count} trabajos pendientes. La corrección no los ejecutará; los reintentos continuarán según su programación.', { count: candidate.pending_jobs }); item.appendChild(jobs);
                }
                if (Number.isSafeInteger(candidate.retained_recovery_records) && candidate.retained_recovery_records > 0) {
                    const retained = document.createElement('p'); retained.className = 'form-note';
                    translatedText(retained, 'La corrección conserva {count} registros de recuperación y sus recursos protegidos. No es necesario eliminarlos para reparar este campo local.', { count: candidate.retained_recovery_records });
                    item.appendChild(retained);
                }
                byId('network-repair-comparison').appendChild(item);
            }
            for (const blocked of data.blocked.slice(0, 3)) {
                if (blocked?.service_id !== serviceId) continue;
                const item = document.createElement('div'); item.className = 'cleanup-blocked-item'; item.innerHTML = `<strong>${html(t('Servicio #{id}', { id: serviceId }))}</strong><p>${html(repairReason(blocked.reason))}</p>`;
                const diagnostics = blocked.diagnostics;
                if (diagnostics && typeof diagnostics === 'object' && !Array.isArray(diagnostics)) {
                    const comparison = document.createElement('div'); comparison.className = 'repair-list';
                    for (const [key, label] of [['local_client_id', 'Cliente del registro local'], ['cached_client_id', 'Cliente de la caché'], ['crm_client_id', 'Cliente confirmado en UISP']]) {
                        const client = diagnostics[key];
                        const entry = document.createElement('div'); entry.className = 'conflict-owner-meta';
                        const title = document.createElement('span'); translatedText(title, label); entry.appendChild(title);
                        if (Number.isSafeInteger(client) && client > 0) entry.appendChild(conflictLink(t('Cliente #{id}', { id: client }), '/crm/client/' + client, 'client'));
                        else { const unknown = document.createElement('span'); translatedText(unknown, 'Sin comprobar'); entry.appendChild(unknown); }
                        comparison.appendChild(entry);
                    }
                    item.appendChild(comparison);
                    const queue = Array.isArray(diagnostics.queue_jobs) ? diagnostics.queue_jobs.slice(0, 20).filter(job => job && typeof job.job_id === 'string' && /^Q[1-9]\d{0,14}$/.test(job.job_id)
                        && ['pending', 'cancelled', 'superseded'].includes(job.status) && typeof job.reason === 'string') : [];
                    if (queue.length) {
                        const heading = document.createElement('strong'); translatedText(heading, 'Trabajos relacionados con la reserva'); item.appendChild(heading);
                        for (const job of queue) {
                            const row = document.createElement('div'); row.className = 'repair-queue-job';
                            const title = document.createElement('strong'); translatedText(title, 'Trabajo {job} · {state}', { job: job.job_id,
                                state: t({ pending: 'Pendiente', cancelled: 'Cancelado', superseded: 'Sustituido' }[job.status]) });
                            const note = document.createElement('p'); note.className = 'form-note';
                            if (job.status === 'pending') note.textContent = repairReason(job.reason);
                            else translatedText(note, 'Este trabajo ya no se ejecuta. Sus registros conservados se revisan por separado en Servicios en recuperación.');
                            row.append(title, note);
                            if (job.status === 'pending') {
                                const review = document.createElement('button'); review.type = 'button'; review.className = 'btn btn-secondary'; translatedText(review, 'Revisar trabajo');
                                review.addEventListener('click', () => { jobReviewRequested = job.job_id; closeModals(); switchView('jobs', 'Trabajos'); }); row.appendChild(review);
                            }
                            item.appendChild(row);
                        }
                        if (queue.some(job => job.status === 'pending')) {
                            const help = document.createElement('p'); help.className = 'form-note'; translatedText(help, 'Revisa el trabajo pendiente señalado antes de cambiar la reserva. Abrirlo no lo ejecuta ni lo elimina.'); item.appendChild(help);
                        }
                    } else {
                        const action = document.createElement('p'); action.className = 'form-note'; translatedText(action, 'Abre el servicio y compara sus clientes. Esta revisión no cambia la identidad ni elimina reservas de otros servicios.'); item.appendChild(action);
                    }
                    item.appendChild(conflictLink(t('Servicio #{id}', { id: serviceId }), '/crm/client/service/' + serviceId, 'service'));
                    if (monitorDate(diagnostics.checked_at)) {
                        const checked = document.createElement('p'); checked.className = 'form-note'; translatedText(checked, 'Verificado: {time}', { time: formatDate(diagnostics.checked_at) }); item.appendChild(checked);
                    }
                }
                byId('network-repair-comparison').appendChild(item);
            }
            translatedText(byId('network-repair-status'), data.candidates.length ? 'Corrección revisada. Vence: {time}. Confirma para guardar sólo esta reserva local.' : 'La reserva se conserva. Puedes comparar nuevamente cuando se resuelva el motivo indicado.', { time: formatDate(data.expiresAt) });
        } catch (error) { translatedText(byId('network-repair-status'), 'Error: {message}', { message: t(error.message) }); }
        finally { if (generation === repairGeneration) { repairBusy = false; updateRepairControls(); } }
    }
    byId('refresh-invalid-reservations')?.addEventListener('click', refreshInvalidReservations);
    byId('recheck-network-repair')?.addEventListener('click', () => repairTarget && previewNetworkRepair(repairTarget.serviceId, repairTarget.field));
    byId('reload-after-repair')?.addEventListener('click', reloadPanel);
    byId('reload-after-reservation-list-repair')?.addEventListener('click', reloadPanel);
    byId('apply-network-repair')?.addEventListener('click', async () => {
        if (repairBusy || repairRequiresReload || !repairPreview?.previewToken || !repairTarget) return;
        const target = { ...repairTarget }, token = repairPreview.previewToken;
        const reviewedField = repairPreview.candidates[0]?.fields[0];
        repairBusy = true; updateRepairControls();
        try {
            if (!await confirmAction(t('¿Corregir la reserva local {family} del servicio #{id} con la revisión de UISP? Se creará un respaldo y se conservarán los servicios y routers.', { family: repairFields[target.field], id: target.serviceId }))) return;
            // A token is single use. Do not leave an apply button after an uncertain response.
            repairPreview = null;
            const response = await api.applyNetworkRepair(token);
            const data = checked(response).data;
            if (!data || !Array.isArray(data.repaired) || data.repaired.length !== 1 || data.repaired[0]?.service_id !== target.serviceId
                || !Array.isArray(data.repaired[0].fields) || data.repaired[0].fields.length !== 1 || data.repaired[0].fields[0]?.field !== target.field
                || data.repaired[0].fields[0]?.family !== repairFields[target.field] || !['clear', 'replace'].includes(data.repaired[0].fields[0]?.action)
                || data.repaired[0].fields[0].action !== reviewedField?.action || data.repaired[0].fields[0].current_address !== reviewedField?.current_address
                || data.jobs_preserved !== true || data.requires_sync !== false || typeof data.requires_reload !== 'boolean'
                || !monitorDate(data.checked_at) || typeof data.backup_name !== 'string' || !/^network-cleanup-\d{8}T\d{6}Z-[a-f0-9]{32}\.sqlite$/.test(data.backup_name)) throw new Error(t('Respuesta inválida del servidor.'));
            repairRequiresReload = data.requires_reload === true;
            translatedText(byId('network-repair-title'), 'Resultado de la corrección');
            byId('network-repair-comparison').replaceChildren(); byId('network-repair-comparison').style.display = 'none';
            const result = byId('network-repair-result'); result.className = 'crm-ip-result is-confirmed'; result.replaceChildren();
            const title = document.createElement('h4'); translatedText(title, 'Reserva local corregida');
            const note = document.createElement('p'); translatedText(note, 'La corrección terminó. Los servicios, UISP y los routers se conservaron. Revisa Trabajos para comprobar los siguientes reintentos.');
            const summary = document.createElement('dl'); summary.className = 'conflict-address-grid';
            const previous = reviewedField.saved.map(entry => `${entry.value.slice(0, 256)}${entry.value.length > 256 ? '…' : ''}`).join(' · ');
            summary.innerHTML = `<div><dt>${html(t('Reserva local antes de la corrección'))} · ${html(repairFields[target.field])}</dt><dd>${html(previous)}</dd></div><div><dt>${html(t('Reserva local corregida'))} · ${html(repairFields[target.field])}</dt><dd>${html(reviewedField.current_address ?? t('Sin IP asignada'))}</dd></div><div><dt>${html(t('Verificado'))}</dt><dd>${html(formatDate(data.checked_at))}</dd></div>`;
            const backup = document.createElement('a'); backup.className = 'btn btn-secondary'; backup.href = api.downloadUrl(data.backup_name); backup.download = data.backup_name; translatedText(backup, 'Descargar respaldo');
            result.append(title, note, summary, backup); result.style.display = 'block'; result.focus?.({ preventScroll: true });
            translatedText(byId('network-repair-status'), repairRequiresReload ? 'Recarga el panel para comprobar las reservas y continuar con el estado actualizado.' : 'Corrección confirmada: {time}.', { time: formatDate(data.checked_at) });
            byId('apply-network-repair').style.display = 'none'; byId('recheck-network-repair').style.display = 'none'; byId('reload-after-repair').style.display = repairRequiresReload ? 'inline-flex' : 'none';
            await refreshInvalidReservations(); refreshJobsStatus();
        } catch (error) {
            translatedText(byId('network-repair-status'), 'No se confirmó la corrección. Compara nuevamente antes de intentar otro cambio. {message}', { message: repairReason(error.message) });
            await refreshInvalidReservations();
        } finally { repairBusy = false; updateRepairControls(); }
    });

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
        if (response?.recovery?.readOnly === true) applyRecovery(response.recovery);
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

    function completed(response, message, deviceName) {
        const report = (text, kind) => notify(deviceName
            ? t('Equipo {name}: {message}', { name: deviceName, message: text }) : text, kind);
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
            report(parts.join(' '), 'error');
            return;
        }
        checked(response);
        const accepted = api.isAccepted(response);
        const totals = counts ? t('Servicios completados: {completed} de {requested}.', result) : '';
        report(accepted ? t('Solicitud aceptada. El trabajo seguirá ejecutándose; comprueba su resultado en Trabajos.') : [message, totals].filter(Boolean).join(' '),
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
            if (button) button.disabled = recoveryReadOnly && (button.dataset.recoveryWrite === 'true' || button.dataset.recoveryRouterRead === 'true');
            applyRecoveryControls();
        }
    }

    function bind(id, work) {
        byId(id)?.addEventListener('click', event => {
            event.preventDefault();
            if (recoveryReadOnly && recoveryWriteIds.has(id)) return;
            return withLoader(() => work(event), event.currentTarget);
        });
    }

    function actionButton(label, icon, work, variant = 'secondary', { loading = true, write = false, routerRead = false } = {}) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `btn btn-${variant} row-action`;
        button.title = t(label);
        button.setAttribute('aria-label', t(label));
        button.setAttribute('data-i18n-title', label);
        button.setAttribute('data-i18n-aria-label', label);
        button.innerHTML = `<i data-feather="${html(icon)}"></i>`;
        if (write) markRecoveryWrite(button);
        if (routerRead) markRecoveryRouterRead(button);
        let running = false;
        button.addEventListener('click', async () => {
            if (running || ((write || routerRead) && recoveryReadOnly)) return;
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
        globalThis.PanelWorkflows?.close?.();
        globalThis.PanelServiceResolution?.close?.();
        if (lifecycleModal && !lifecycleApplying) {
            lifecycleModal.style.display = 'none'; lifecycleGeneration++; lifecycleTarget = null; lifecyclePreview = null; lifecycleLoading = false;
            updateLifecycleControls();
        }
        for (const modal of [deviceModal, planModal, orphanModal, cleanupModal]) if (modal) modal.style.display = 'none';
        if (crmEditModal && !crmEditBusy) {
            crmEditModal.style.display = 'none'; crmEditGeneration++; crmEditTarget = null; crmEditOutcome = null; resetCrmEditPreview(); updateCrmEditorControls();
        }
        if (repairModal && !repairBusy) {
            repairModal.style.display = 'none'; repairGeneration++; repairTarget = null; repairPreview = null;
        }
        if (jobRecoveryModal && !jobRecoveryBusy) {
            jobRecoveryModal.style.display = 'none'; jobRecoveryGeneration++; jobRecoveryTarget = null; jobRecoveryPreview = null;
        }
        byId('dev-password').value = '';
    }

    document.querySelectorAll('.close-modal').forEach(button => button.addEventListener('click', closeModals));
    document.addEventListener('keydown', event => { if (event.key === 'Escape') closeModals(); });

    function switchView(view, title) {
        if (!navigationViews.has(view)) return;
        rememberPosition();
        navigationReady = false;
        const generation = ++navigationGeneration;
        if (currentView === 'config') captureConfigDraft();
        if (currentView === 'config' && view !== 'config') configRenderSequence++;
        jobsViewGeneration++;
        clearJobsRefreshTimer();
        currentView = view;
        globalThis.PanelOperations?.onView(view);
        globalThis.PanelWorkflows?.onView(view);
        translatedText(byId('page-title'), { devices: 'Dispositivos administrados', plans: 'Planes', config: 'Configuración', jobs: 'Trabajos', system: 'Sistema', network_conflicts: 'Conflictos de IP', diagnostics: 'Diagnóstico por servicio' }[view] || title);
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
            markRecoveryWrite(button);
            button.addEventListener('click', () => openDeviceModal());
            topbar.appendChild(button);
        }
        let rendered = false;
        withLoader(async () => {
            const render = { devices: renderDevices, plans: renderPlans, config: renderConfig, jobs: renderJobs, system: renderSystem, network_conflicts: renderNetworkConflicts }[view];
            if (render) await render();
            rendered = true;
        }).then(async () => {
            if (!rendered || generation !== navigationGeneration || currentView !== view) return;
            if (typeof globalThis.requestAnimationFrame === 'function') await new Promise(resolve => globalThis.requestAnimationFrame(resolve));
            if (generation !== navigationGeneration || currentView !== view) return;
            restorePosition(viewPositions.get(view) || { scrollTop: 0 });
            navigationReady = true;
            saveNavigation();
        });
    }

    document.querySelectorAll('.nav-item').forEach(nav => nav.addEventListener('click', event => {
        event.preventDefault();
        switchView(nav.dataset.view, nav.querySelector('span').textContent);
    }));
    byId('btn-view-conflicts')?.addEventListener('click', () => switchView('network_conflicts', 'Conflictos de IP'));
    byId('btn-view-crm-conflicts')?.addEventListener('click', () => switchView('network_conflicts', 'Conflictos de IP'));
    byId('btn-view-pending-jobs')?.addEventListener('click', () => {
        jobRecoveryFocusRequested = jobsStatus?.recovery > 0;
        switchView('jobs', 'Trabajos');
    });
    byId('btn-recheck-conflicts')?.addEventListener('click', reloadPanel);
    byId('reload-after-full-restore')?.addEventListener('click', reloadPanel);

    async function renderDevices() {
        const sequence = ++devicesRequestSequence;
        const response = checked(await api.getDevices());
        if (sequence !== devicesRequestSequence) return;
        devicesCache = Array.isArray(response.data) ? response.data : [];
        renderDevicesTable();
    }

    async function syncDevice(device) {
        const id = Number(device.id);
        if (!Number.isSafeInteger(id) || id < 1 || recoveryReadOnly || restoredRequiresReload || deviceSyncs.has(id)) return;
        const name = `${String(device.name || id).slice(0, 200)} (#${id})`;
        deviceSyncs.set(id, 'confirming');
        renderDevicesTable();
        try {
            if (!await confirmAction(t('¿Sincronizar las cuentas administradas de este MikroTik y reconectar todas sus sesiones PPP/Hotspot?'))) return;
            if (recoveryReadOnly || restoredRequiresReload) return;
            deviceSyncs.set(id, { state: 'running', processed: 0, total: null });
            crmLocalAssignmentProofs.clear();
            if (currentView === 'devices') renderDevicesTable();
            let response = checked(await api.beginDeviceSync(id)), syncToken = null, processed = 0, total = null, applying = false, catalogScanned = -1, steps = 0;
            while (true) {
                const progress = response.data;
                if (!progress || !/^[a-f0-9]{64}$/.test(progress.syncToken) || syncToken !== null && progress.syncToken !== syncToken
                    || typeof progress.done !== 'boolean' || !['catalog', 'applying', 'complete'].includes(progress.phase)
                    || progress.done !== (progress.phase === 'complete')
                    || !['processed', 'total', 'requested', 'completed', 'failed', 'catalogScanned'].every(key => Number.isSafeInteger(progress[key]) && progress[key] >= 0)
                    || progress.processed > progress.total || progress.processed < processed
                    || progress.requested > progress.processed || progress.done && progress.processed !== progress.total
                    || progress.completed + progress.failed > progress.requested
                    || applying && progress.phase === 'catalog' || applying && progress.total !== total
                    || applying && !progress.done && progress.processed <= processed
                    || progress.catalogScanned < catalogScanned || progress.phase === 'catalog' && progress.catalogScanned <= catalogScanned)
                    throw new Error(t('Respuesta inválida del servidor.'));
                syncToken = progress.syncToken; processed = progress.processed; total = progress.total;
                catalogScanned = progress.catalogScanned;
                applying = applying || progress.phase !== 'catalog';
                deviceSyncs.set(id, { state: 'running', processed, total });
                if (currentView === 'devices') renderDevicesTable();
                if (progress.done) break;
                if (++steps > 21000 || recoveryReadOnly || restoredRequiresReload) throw new Error(t('La sincronización se interrumpió. Revisa Trabajos antes de volver a intentarlo.'));
                response = checked(await api.stepDeviceSync(syncToken));
            }
            completed(response, t('Sincronización ejecutada. Revisa la confirmación en Trabajos.'), name);
            try { await renderDevices(); }
            catch (_) {
                notify(t('Equipo {name}: {message}', { name, message: t('No se pudo actualizar el estado del equipo.') }), 'error');
            }
        } catch (error) {
            notify(t('Equipo {name}: {message}', { name,
                message: [t('Error: {message}', { message: t(error?.message || 'No se pudo completar la operación.') }),
                    deviceSyncs.get(id)?.state === 'running' ? t('La sincronización se interrumpió. Revisa Trabajos antes de volver a intentarlo.') : ''].filter(Boolean).join(' ') }), 'error');
        } finally {
            deviceSyncs.delete(id);
            if (currentView === 'devices') renderDevicesTable();
        }
    }

    function renderDevicesTable() {
        const savedPosition = beforeTableUpdate('devices');
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
            const id = Number(device.id);
            const busy = deviceSyncs.has(id);
            const progress = deviceSyncs.get(id);
            const syncing = progress?.state === 'running';
            const progressSource = syncing && progress.total > 0 ? 'Sincronizando… {processed} de {total}' : 'Sincronizando…';
            const progressValues = { processed: progress?.processed ?? 0, total: progress?.total ?? 0 };
            row.dataset.deviceId = String(id);
            row.classList.toggle('device-sync-active', syncing);
            row.setAttribute('aria-busy', syncing ? 'true' : 'false');
            const online = PanelUtils.flag(device.status);
            const deviceStatus = device.status == null ? 'Sin comprobar' : online ? 'Puerto accesible' : 'Desconectado';
            row.innerHTML = `<td>${html(device.id)}</td><td>${html(device.name)}</td><td>${html(device.ip)}</td>
                <td>${html(device.users ?? 0)}</td><td><span class="badge badge-${device.status == null ? 'neutral' : online ? 'success' : 'danger'}" data-i18n="${deviceStatus}">${html(t(deviceStatus))}</span>
                ${syncing ? `<span class="device-sync-progress" role="status"><span class="device-sync-spinner" aria-hidden="true"></span><span data-i18n="${html(progressSource)}" data-i18n-values="${html(JSON.stringify(progressValues))}">${html(t(progressSource, progressValues))}</span></span>` : ''}</td><td class="row-actions"></td>`;
            const actions = row.querySelector('.row-actions');
            actions.append(
                actionButton('Ver cuentas huérfanas', 'users', () => { if (!deviceSyncs.has(id)) return viewOrphans(device.id); }, 'secondary', { routerRead: true }),
                actionButton(recoveryReadOnly ? 'Ver dispositivo' : 'Editar dispositivo', 'edit-2', () => { if (!deviceSyncs.has(id)) openDeviceModal(device); }, 'secondary', { loading: false }),
                actionButton('Sincronizar ahora', 'refresh-cw', () => syncDevice(device), 'primary', { loading: false, write: true }),
                ...(typeof globalThis.PanelWorkflows?.previewSync === 'function' ? [
                    actionButton('Revisar diferencias', 'search', () => {
                        if (!deviceSyncs.has(id) && !restoredRequiresReload) return globalThis.PanelWorkflows.previewSync(device);
                    }, 'secondary', { loading: false, routerRead: true })
                ] : []),
                actionButton('Vaciar cuentas administradas', 'x-circle', async () => {
                    if (deviceSyncs.has(id)) return;
                    if (!await confirmAction(t('¿Eliminar todas las cuentas administradas por el plugin de este MikroTik?'))) return;
                    completed(await api.send('update', 'system', 'delete', { type: 'device', id: Number(device.id) }), t('Cuentas eliminadas.'));
                    await renderDevices();
                }, 'secondary', { write: true }),
                actionButton('Eliminar dispositivo', 'trash-2', async () => {
                    if (deviceSyncs.has(id)) return;
                    if (!await confirmAction(t('¿Eliminar este dispositivo del plugin?'))) return;
                    checked(await api.send('update', 'devices', 'delete', { id: Number(device.id) }));
                    await renderDevices();
                }, 'danger', { write: true })
            );
            if (busy) for (const button of actions.children) button.disabled = true;
            tbody.appendChild(row);
        });
        PanelUtils.replaceIcons();
        afterTableUpdate('devices', savedPosition);
    }

    byId('search-devices').addEventListener('input', renderDevicesTable);
    document.querySelectorAll('#devices-table th.sortable').forEach(header => header.addEventListener('click', () => {
        const column = header.dataset.sort;
        sortDirection = sortColumn === column && sortDirection === 'asc' ? 'desc' : 'asc';
        sortColumn = column;
        renderDevicesTable();
    }));

    async function renderPlans() {
        const startedGeneration = navigationGeneration;
        // Sólo la configuración guardada determina qué campos se aplican a los planes.
        planContentionDisabled = null;
        if (!navigationReady) { plansCache = []; renderPlansTable(); }
        const [plansResponse, configResponse] = await Promise.all([api.getPlans(), api.getConfig()]);
        const response = checked(plansResponse);
        const savedConfig = checked(configResponse).data;
        if (!savedConfig || typeof savedConfig !== 'object' || Array.isArray(savedConfig)
            || !Object.prototype.hasOwnProperty.call(savedConfig, 'disable_contention')) {
            throw new Error(t('No se pudo comprobar la configuración de contención. Vuelve a abrir la sección Planes.'));
        }
        const savedPosition = startedGeneration === navigationGeneration ? beforeTableUpdate('plans') : null;
        planContentionDisabled = Boolean(PanelUtils.flag(savedConfig.disable_contention));
        plansCache = Array.isArray(response.data) ? response.data : Object.values(response.data || {});
        renderPlansTable();
        afterTableUpdate('plans', savedPosition);
    }

    function renderPlansTable() {
        const savedPosition = beforeTableUpdate('plans');
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
            row.querySelector('.row-actions').appendChild(actionButton(recoveryReadOnly ? 'Ver plan' : 'Editar plan', 'edit-2', () => openPlanModal(plan), 'secondary', { loading: false }));
            tbody.appendChild(row);
        });
        PanelUtils.replaceIcons();
        afterTableUpdate('plans', savedPosition);
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
        if (status) status.textContent = recoveryReadOnly ? t('Modo consulta: los cambios están pausados.') : changed ? t('Cambios sin guardar.') : t('Guarda para aplicar los cambios.');
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
        const startedGeneration = navigationGeneration;
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
        const savedPosition = startedGeneration === navigationGeneration ? beforeTableUpdate('config') : null;
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
        applyRecoveryControls();
        afterTableUpdate('config', savedPosition);
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

    function validJobRecoveryGroup(row, reviewed = false) {
        return row && Number.isSafeInteger(row.source_job_id) && row.source_job_id > 0 && row.job_id === 'Q' + row.source_job_id
            && Number.isSafeInteger(row.service_id) && row.service_id > 0 && (row.client_id === null || Number.isSafeInteger(row.client_id) && row.client_id > 0)
            && (row.client_name === null || typeof row.client_name === 'string' && row.client_name.length <= 500)
            && Array.isArray(row.device_ids) && row.device_ids.length <= 100 && row.device_ids.every(id => Number.isSafeInteger(id) && id > 0)
            && Number.isSafeInteger(row.snapshot_count) && row.snapshot_count > 0 && row.snapshot_count <= 100
            && row.reason === (reviewed ? 'retained_resources' : 'cancelled_resources')
            && (reviewed ? monitorDate(row.reviewed_at) : row.created_at === null || monitorDate(row.created_at));
    }
    function jobRecoveryIdentity(row) {
        const identity = document.createElement('div'); identity.className = 'conflict-owner-meta';
        identity.appendChild(conflictLink(t('Servicio #{id}', { id: row.service_id }), '/crm/client/service/' + row.service_id, 'service'));
        if (row.client_id !== null) identity.appendChild(conflictLink(row.client_name || t('Cliente #{id}', { id: row.client_id }), '/crm/client/' + row.client_id, 'client'));
        const source = document.createElement('span'); source.className = 'badge badge-neutral'; translatedText(source, 'Trabajo original: {job}', { job: row.job_id }); identity.appendChild(source);
        return identity;
    }
    function jobRecoveryReason(reason) {
        return t({
            queue_busy: 'Hay una operación de este servicio en curso o pendiente. Espera a que termine y verifica de nuevo.',
            current_ownership_unknown: 'No se confirmaron los recursos actuales de este servicio. Se conserva la recuperación para no perder información del router.',
            different_resources: 'Los registros conservados difieren del estado aplicado guardado. Se requiere revisar los recursos anteriores en el router; no se retirará ninguno desde esta opción.',
            crm_unavailable: 'No se pudo comprobar el servicio y su cliente en UISP. Verifica de nuevo cuando UISP responda.',
            crm_identity_changed: 'El cliente de UISP no coincide con el registro conservado. Revisa el servicio antes de resolver la recuperación.',
            crm_status_changed: 'El estado de UISP cambió respecto a los recursos actuales. Se conserva la recuperación hasta reconciliar el servicio.',
            source_state_changed: 'La recuperación cambió o ya fue resuelta. Refresca la lista y verifica de nuevo.',
            legacy_queue_present: 'Hay trabajos heredados sin comprobar. Se conserva la recuperación hasta revisar esa cola.',
            unavailable: 'No se pudo comprobar esta recuperación con seguridad. Se conserva el registro.',
            recovery_preview_expired: 'La revisión venció. Verifica de nuevo antes de resolver la recuperación.',
            recovery_verification_failed: 'La comprobación cambió antes de guardar. Verifica de nuevo.',
            recovery_state_changed: 'La comprobación cambió antes de guardar. Verifica de nuevo.',
            recovery_backup_failed: 'No se pudo crear el respaldo. La recuperación se conserva.',
            recovery_busy: 'Hay otra operación en curso. Espera a que termine y verifica de nuevo.',
            router_resources_present: 'El router aún conserva recursos de esta recuperación. Revisa el equipo indicado; esta opción no los elimina ni modifica servicios actuales.',
            router_observation_unavailable: 'No se pudo consultar el router. Revisa su conexión y permisos de lectura, y verifica de nuevo.',
            router_snapshot_incomplete: 'El registro anterior no permite identificar todos los recursos del router con seguridad. Se conserva la recuperación para revisión.',
            router_authority_changed: 'La identidad o configuración del equipo cambió. Revisa el equipo antes de resolver la recuperación.',
            router_observation_limit: 'La comprobación del router superó el límite de recursos. Se conserva la recuperación para revisión.'
        }[reason] || 'No se pudo comprobar esta recuperación con seguridad. Se conserva el registro.');
    }
    function validRecoveryChecks(checks) {
        const paths = ['/ppp/secret', '/ppp/active', '/ip/hotspot/user', '/ip/hotspot/active', '/ppp/profile', '/ip/hotspot/user/profile', '/queue/simple', '/ipv6/pool', '/ip/dhcp-server/lease', '/ipv6/dhcp-server/binding'];
        return Array.isArray(checks) && checks.length > 0 && checks.length <= 60 && checks.every(check => check
            && Number.isSafeInteger(check.device_id) && check.device_id > 0 && paths.includes(check.path)
            && ['absent', 'present', 'unknown'].includes(check.state) && ['name', 'mac', 'duid', 'owner'].includes(check.selector_type)
            && typeof check.identity_hint === 'string' && /^sha256:[a-f0-9]{12}$/.test(check.identity_hint)
            && (check.selector_label === undefined || typeof check.selector_label === 'string' && check.selector_label.length > 0 && check.selector_label.length <= 160 && !/[\u0000-\u001f\u007f]/.test(check.selector_label)));
    }
    function validRecoveryDiagnostics(value) {
        return value === undefined || value && ['source', 'prior_reservations', 'snapshot', 'router', 'applied'].includes(value.stage)
            && Array.isArray(value.issues) && value.issues.length <= 20 && value.issues.every(issue => issue
                && ['cancelled_delete', 'missing_frozen_commands', 'uncovered_prior_address', 'missing_prior_identity', 'incomplete_source_identity', 'different_applied_resources'].includes(issue.code)
                && (issue.snapshot_slot === undefined || ['source', 'applied', 'commands', 'cleanup'].includes(issue.snapshot_slot))
                && (issue.family === undefined || ['IPv4', 'IPv6'].includes(issue.family)));
    }
    function recoveryDiagnostics(value) {
        const list = document.createElement('div'); list.className = 'repair-list';
        for (const issue of value?.issues || []) {
            const line = document.createElement('p'); line.className = 'form-note';
            translatedText(line, {
                cancelled_delete: 'El trabajo cancelado era una retirada de recursos; cancelarlo no confirma que se retiraran.',
                missing_frozen_commands: 'El registro antiguo no contiene la lista completa de recursos preparados.',
                uncovered_prior_address: 'Hay una reserva anterior sin una comprobación completa de su recurso en el router.',
                missing_prior_identity: 'La reserva anterior no tiene una identidad de recurso comprobable.',
                incomplete_source_identity: 'Faltan datos de identidad en el registro original.',
                different_applied_resources: 'Los recursos anteriores difieren del último estado aplicado guardado.'
            }[issue.code]);
            if (issue.family) line.textContent += ' · ' + issue.family;
            list.appendChild(line);
        }
        return list;
    }
    function recoveryCheckList(checks) {
        const list = document.createElement('div'); list.className = 'recovery-resource-checks';
        const heading = document.createElement('strong'); translatedText(heading, 'Comprobación de recursos en el router'); list.appendChild(heading);
        for (const check of checks) {
            const item = document.createElement('p'); item.className = 'form-note';
            translatedText(item, 'Equipo #{id} · {path} · {state}', { id: check.device_id, path: check.path,
                state: t({ absent: 'No encontrado', present: 'Presente en el router', unknown: 'Sin comprobar' }[check.state]) });
            list.appendChild(item);
            if (check.selector_label) {
                const identity = document.createElement('p'); identity.className = 'form-note';
                translatedText(identity, 'Identificador {type}: {value}', { type: check.selector_type, value: check.selector_label }); list.appendChild(identity);
            }
        }
        return list;
    }
    function renderJobRecoveries() {
        const list = byId('job-recovery-list'); if (!list) return;
        list.replaceChildren();
        for (const row of jobRecoveryReport?.groups || []) {
            const item = document.createElement('article'); item.className = 'repair-list-item'; item.dataset.sourceJobId = row.source_job_id;
            const meta = document.createElement('p'); meta.className = 'job-recovery-meta';
            translatedText(meta, '{count} registros conservados · Equipos: {devices}', { count: row.snapshot_count, devices: row.device_ids.join(', ') || t('Sin comprobar') });
            const note = document.createElement('span'); note.className = 'badge badge-neutral';
            translatedText(note, 'Cancelado');
            const review = document.createElement('button'); review.type = 'button'; review.className = 'btn btn-secondary job-repair-button'; translatedText(review, 'Comparar con UISP y router');
            review.disabled = jobRecoveryBusy; review.addEventListener('click', () => previewJobRecovery(row));
            item.append(jobRecoveryIdentity(row), meta, note, review); list.appendChild(item);
        }
        const retained = byId('job-retained-list');
        retained?.replaceChildren();
        for (const row of jobRecoveryReport?.reviewed_groups || []) {
            const item = document.createElement('article'); item.className = 'repair-list-item';
            const note = document.createElement('p'); note.className = 'form-note';
            translatedText(note, 'Revisión cerrada. Los recursos y sus reservas se conservan protegidos.');
            const discard = document.createElement('button'); discard.type = 'button'; discard.className = 'btn btn-danger';
            translatedText(discard, 'Descartar registro local'); discard.disabled = jobRecoveryBusy;
            discard.addEventListener('click', () => previewJobRecovery(row, 'discard'));
            item.append(jobRecoveryIdentity(row), note, discard); retained?.appendChild(item);
        }
        if (byId('job-retained-section')) byId('job-retained-section').style.display = jobRecoveryReport?.reviewed_total > 0 ? 'block' : 'none';
        translatedText(byId('job-recovery-list-status'), jobRecoveryListInFlight ? 'Consultando recuperaciones...'
            : jobRecoveryListFailure ? jobRecoveryReport ? 'No se pudo refrescar. Se conserva la última lista de recuperaciones.' : 'No se pudo consultar la lista de recuperaciones. Puedes volver a refrescar.'
            : !jobRecoveryReport ? 'Sin actualizar.' : jobRecoveryReport.has_more ? 'Se muestran {shown} de {count} recuperaciones. Resuelve y refresca para ver las siguientes.'
            : jobRecoveryReport.total > 0 ? 'Recuperaciones pendientes: {count}' : 'No hay servicios en recuperación.', { shown: jobRecoveryReport?.groups.length ?? 0, count: jobRecoveryReport?.total ?? 0 });
        byId('refresh-job-recoveries').disabled = jobRecoveryListInFlight !== null || jobRecoveryBusy;
    }
    function refreshJobRecoveries() {
        if (!byId('job-recovery-list') || typeof api.getJobRecoveries !== 'function' || !jobsVisible()) return Promise.resolve();
        if (jobRecoveryListInFlight) { jobRecoveryListRefreshRequested = true; return jobRecoveryListInFlight; }
        jobRecoveryListRefreshRequested = false;
        const generation = jobsViewGeneration, epoch = jobsStatusEpoch;
        const work = (async () => {
            try {
                const data = checked(await api.getJobRecoveries()).data;
                if (!jobsVisible() || generation !== jobsViewGeneration || epoch !== jobsStatusEpoch) return;
                if (!data || data.available !== true || !Array.isArray(data.groups) || data.groups.length > 100 || !data.groups.every(row => validJobRecoveryGroup(row))
                    || new Set(data.groups.map(row => row.source_job_id)).size !== data.groups.length || !Number.isSafeInteger(data.total) || data.total < data.groups.length
                    || data.has_more !== (data.total > data.groups.length) || data.reason !== null || !monitorDate(data.checked_at)) throw new Error('invalid_recoveries');
                if (data.reviewed_groups !== undefined && (!Array.isArray(data.reviewed_groups) || data.reviewed_groups.length > 100
                    || !data.reviewed_groups.every(row => validJobRecoveryGroup(row, true)) || !Number.isSafeInteger(data.reviewed_total)
                    || data.reviewed_total < data.reviewed_groups.length || new Set(data.reviewed_groups.map(row => row.source_job_id)).size !== data.reviewed_groups.length)) throw new Error('invalid_recoveries');
                jobRecoveryReport = data; jobRecoveryListFailure = false;
            } catch (_) { if (generation === jobsViewGeneration && epoch === jobsStatusEpoch) jobRecoveryListFailure = true; }
            finally {
                jobRecoveryListInFlight = null; renderJobRecoveries();
                if (jobsVisible() && generation === jobsViewGeneration && jobRecoveryFocusRequested) {
                    jobRecoveryFocusRequested = false; byId('job-recovery-section').focus?.({ preventScroll: false });
                }
                if (jobRecoveryListRefreshRequested && jobsVisible() && jobsStatusMutations === 0) {
                    jobRecoveryListRefreshRequested = false; refreshJobRecoveries();
                }
            }
        })();
        jobRecoveryListInFlight = work; renderJobRecoveries(); return work;
    }
    function updateJobRecoveryControls() {
        if (!jobRecoveryModal) return;
        byId('apply-job-recovery').disabled = jobRecoveryBusy || !jobRecoveryPreview?.previewToken;
        byId('recheck-job-recovery').disabled = jobRecoveryBusy || !jobRecoveryTarget;
        byId('preserve-job-recovery').disabled = jobRecoveryBusy || !jobRecoveryTarget;
        byId('discard-job-recovery').disabled = jobRecoveryBusy || !jobRecoveryTarget;
    }
    async function previewJobRecovery(row, mode = 'resolve') {
        if (jobRecoveryBusy || !jobRecoveryModal || !validJobRecoveryGroup(row, row?.reason === 'retained_resources')) return;
        const generation = ++jobRecoveryGeneration; jobRecoveryTarget = row; jobRecoveryPreview = null; jobRecoveryBusy = true; jobRecoveryMode = mode;
        jobRecoveryModal.style.display = 'flex'; translatedText(byId('job-recovery-title'), 'Revisar recuperación');
        translatedText(byId('job-recovery-intro'), mode === 'discard' ? 'Revisión local; no se comprobó el estado en UISP.'
            : 'Se comprobarán UISP y los registros actuales. Si hace falta, se consultará el router sin modificarlo. Sólo se retirarán registros locales redundantes o cuyos recursos ya no existan; los servicios actuales se conservarán.');
        byId('job-recovery-result').style.display = 'none'; byId('job-recovery-result').replaceChildren();
        byId('job-recovery-comparison').style.display = 'grid'; byId('job-recovery-comparison').replaceChildren(jobRecoveryIdentity(row));
        byId('apply-job-recovery').style.display = 'inline-flex'; byId('recheck-job-recovery').style.display = 'inline-flex';
        byId('preserve-job-recovery').style.display = 'none';
        byId('discard-job-recovery').style.display = 'none';
        translatedText(byId('apply-job-recovery'), mode === 'discard' ? 'Descartar registro local' : mode === 'preserve' ? 'Conservar recursos y cerrar revisión' : 'Resolver recuperación');
        translatedText(byId('job-recovery-status'), 'Comprobando UISP y los registros guardados; si hace falta, se consultará el router sin modificarlo...'); updateJobRecoveryControls(); renderJobRecoveries();
        try {
            const data = checked(await api.previewJobRecovery(row.source_job_id, mode)).data;
            if (generation !== jobRecoveryGeneration) return;
            if (!data || !Array.isArray(data.blocked) || data.blocked.length > 1 || !monitorDate(data.checked_at)
                || (data.candidate ? !validJobRecoveryGroup(data.candidate, data.candidate.reason === 'retained_resources') || data.candidate.source_job_id !== row.source_job_id || data.candidate.service_id !== row.service_id
                    || !(mode === 'discard' ? ['discarded_resources'] : mode === 'preserve' ? ['retained_resources'] : ['redundant_current_resources', 'observed_absent_resources']).includes(data.candidate.resolution) || !monitorDate(data.candidate.checked_at)
                    || !validRecoveryDiagnostics(data.candidate.diagnostics)
                    || data.candidate.resolution === 'observed_absent_resources' && (!validRecoveryChecks(data.candidate.checks) || data.candidate.checks.some(check => check.state !== 'absent'))
                    || (mode === 'discard' ? data.candidate.crm_status !== null || data.candidate.client_archived !== null || data.candidate.identity_origin !== 'local'
                        : !Number.isInteger(data.candidate.crm_status) || data.candidate.crm_status < 0 || data.candidate.crm_status > 8)
                    || (data.candidate.client_archived !== null && typeof data.candidate.client_archived !== 'boolean')
                    || data.blocked.length !== 0 || typeof data.previewToken !== 'string' || !/^[a-f0-9]{64}$/.test(data.previewToken) || !monitorDate(data.expiresAt)
                    : data.previewToken !== null || data.blocked.length !== 1)
                || data.blocked.some(entry => entry.source_job_id !== row.source_job_id || entry.service_id !== null && entry.service_id !== row.service_id || typeof entry.reason !== 'string'
                    || !validRecoveryDiagnostics(entry.diagnostics)
                    || entry.checks !== undefined && (!Array.isArray(entry.checks) || entry.checks.length > 0 && !validRecoveryChecks(entry.checks)))) throw new Error(t('Respuesta inválida del servidor.'));
            jobRecoveryPreview = data;
            if (data.candidate) {
                jobRecoveryTarget = data.candidate;
                const state = document.createElement('p'); state.className = 'form-note';
                translatedText(state, mode === 'discard' ? 'Revisión local; no se comprobó el estado en UISP.' : 'Servicio: {state}', { state: repairServiceStatus(data.candidate.crm_status) }); byId('job-recovery-comparison').appendChild(state);
                const checked = document.createElement('p'); checked.className = 'form-note';
                translatedText(checked, 'Verificado: {time}', { time: formatDate(data.candidate.checked_at) }); byId('job-recovery-comparison').appendChild(checked);
                const note = document.createElement('p'); note.className = 'form-note'; translatedText(note, mode === 'discard'
                    ? 'Se descartará este historial local con respaldo. Sus recursos pueden seguir en el router y sus IP históricas podrán volver a asignarse. La cuenta y las reservas actuales confirmadas se conservarán.'
                    : data.candidate.resolution === 'retained_resources'
                    ? 'Se conservarán los registros de recursos y sus reservas en un archivo protegido, con respaldo. Se cerrará esta revisión y no se ejecutarán como una limpieza cancelada. Esto no confirma que el router esté limpio ni sincronizado.'
                    : data.candidate.resolution === 'observed_absent_resources'
                    ? 'La identidad se comprobó en UISP y los recursos anteriores ya no están en el router. Sólo se retirarán sus registros locales, con respaldo; el router se consultará de nuevo antes de confirmar.'
                    : 'La identidad y el estado se comprobaron en UISP. Los registros conservados son copias del estado aplicado guardado; sólo se retirarán esas copias, con un respaldo previo.');
                byId('job-recovery-comparison').appendChild(note);
                if (data.candidate.diagnostics?.issues.length) byId('job-recovery-comparison').appendChild(recoveryDiagnostics(data.candidate.diagnostics));
                if (data.candidate.resolution === 'observed_absent_resources') byId('job-recovery-comparison').appendChild(recoveryCheckList(data.candidate.checks));
                translatedText(byId('job-recovery-status'), 'Recuperación revisada. Vence: {time}.', { time: formatDate(data.expiresAt) });
            } else {
                const blocked = document.createElement('div'); blocked.className = 'cleanup-blocked-item';
                blocked.textContent = jobRecoveryReason(data.blocked[0].reason); byId('job-recovery-comparison').appendChild(blocked);
                if (data.blocked[0].checks?.length) byId('job-recovery-comparison').appendChild(recoveryCheckList(data.blocked[0].checks));
                if (data.blocked[0].diagnostics?.issues.length) byId('job-recovery-comparison').appendChild(recoveryDiagnostics(data.blocked[0].diagnostics));
                const review = document.createElement('p'); review.className = 'form-note';
                translatedText(review, 'Puedes comprobar el servicio en UISP y el equipo indicado. No se borrarán recursos del router desde esta revisión.');
                byId('job-recovery-comparison').appendChild(review);
                translatedText(byId('job-recovery-status'), 'La recuperación se conserva. No se cambiaron los servicios ni el router.');
                if (mode !== 'discard' && !['source_state_changed', 'legacy_queue_present'].includes(data.blocked[0].reason)) byId('discard-job-recovery').style.display = 'inline-flex';
                if (mode === 'resolve' && ['current_ownership_unknown', 'different_resources', 'router_resources_present', 'router_observation_unavailable', 'router_snapshot_incomplete', 'router_authority_changed', 'router_observation_limit'].includes(data.blocked[0].reason)) {
                    byId('preserve-job-recovery').style.display = 'inline-flex';
                    const alternative = document.createElement('p'); alternative.className = 'form-note';
                    translatedText(alternative, 'Si deseas mantener los recursos actuales, puedes revisar su conservación y cerrar este registro sin eliminarlos del router.');
                    byId('job-recovery-comparison').appendChild(alternative);
                }
            }
        } catch (error) { translatedText(byId('job-recovery-status'), 'Error: {message}', { message: t(error.message) }); }
        finally { if (generation === jobRecoveryGeneration) { jobRecoveryBusy = false; updateJobRecoveryControls(); renderJobRecoveries(); } }
    }
    byId('refresh-job-recoveries')?.addEventListener('click', async () => { await refreshJobRecoveries(); await refreshJobsStatus(); });
    byId('recheck-job-recovery')?.addEventListener('click', () => jobRecoveryTarget && previewJobRecovery(jobRecoveryTarget, jobRecoveryMode));
    byId('preserve-job-recovery')?.addEventListener('click', () => jobRecoveryTarget && previewJobRecovery(jobRecoveryTarget, 'preserve'));
    byId('discard-job-recovery')?.addEventListener('click', () => jobRecoveryTarget && previewJobRecovery(jobRecoveryTarget, 'discard'));
    byId('apply-job-recovery')?.addEventListener('click', async () => {
        if (jobRecoveryBusy || !jobRecoveryTarget || !jobRecoveryPreview?.previewToken) return;
        const row = jobRecoveryTarget, token = jobRecoveryPreview.previewToken;
        jobRecoveryBusy = true; updateJobRecoveryControls(); renderJobRecoveries();
        try {
            const preserving = row.resolution === 'retained_resources';
            const discarding = row.resolution === 'discarded_resources';
            if (!await confirmAction(t(discarding
                ? '¿Descartar definitivamente el registro local {job} del servicio #{id}? Sus recursos pueden seguir en el router y sus IP históricas podrán reutilizarse. Se creará un respaldo; la cuenta actual se conservará.'
                : preserving
                ? '¿Conservar los recursos de {job} del servicio #{id} y cerrar la revisión? Se guardarán sus registros y reservas protegidos, con respaldo. No se eliminarán recursos del router ni se confirmará una sincronización.'
                : row.resolution === 'observed_absent_resources'
                ? '¿Resolver la recuperación {job} del servicio #{id}? Se comprobará de nuevo que los recursos anteriores no estén en el router y se retirarán sólo sus registros locales con respaldo.'
                : '¿Resolver la recuperación {job} del servicio #{id}? Se respaldarán y retirarán sólo las copias redundantes verificadas; los servicios y routers se conservarán.', { job: row.job_id, id: row.service_id }))) return;
            jobRecoveryPreview = null;
            const data = checked(await api.applyJobRecovery(token)).data;
            const outcomes = preserving ? data?.retained : data?.resolved;
            if (!data || !Array.isArray(outcomes) || outcomes.length !== 1 || outcomes[0].source_job_id !== row.source_job_id || outcomes[0].service_id !== row.service_id
                || outcomes[0].snapshot_count !== row.snapshot_count || preserving && (data.resolution !== 'retained_resources' || !Array.isArray(data.resolved) || data.resolved.length !== 0)
                || discarding && (data.resolution !== 'discarded_resources' || !Array.isArray(data.discarded) || data.discarded.length !== 1 || data.discarded[0].source_job_id !== row.source_job_id)
                || data.router_changes !== false || data.crm_changes !== false || !monitorDate(data.checked_at)
                || typeof data.backup_name !== 'string' || !/^jobs-recovery-\d{8}T\d{6}Z-[a-f0-9]{32}\.sqlite$/.test(data.backup_name)) throw new Error(t('Respuesta inválida del servidor.'));
            translatedText(byId('job-recovery-title'), discarding ? 'Registro local descartado' : preserving ? 'Revisión cerrada; recursos conservados' : 'Recuperación resuelta');
            byId('job-recovery-comparison').replaceChildren(); byId('job-recovery-comparison').style.display = 'none';
            const result = byId('job-recovery-result'); result.className = 'crm-ip-result is-confirmed';
            const title = document.createElement('h4'); translatedText(title, discarding ? 'Registro local descartado' : preserving ? 'Revisión cerrada; recursos conservados' : 'Recuperación resuelta');
            const note = document.createElement('p'); translatedText(note, discarding
                ? 'Se descartó el registro local de {job}, con respaldo. No se modificó UISP ni el router. Este resultado no confirma que los recursos históricos hayan sido retirados del equipo.'
                : preserving
                ? 'Se cerró la revisión de {job}. Sus recursos y reservas siguen protegidos y no se ejecutarán como una limpieza cancelada. No se cambió UISP ni el router.'
                : row.resolution === 'observed_absent_resources'
                ? 'Se confirmó que los recursos anteriores ya no estaban en el router y se retiraron sus registros locales de {job}. Los servicios actuales, las IP, las velocidades y los routers se conservaron.'
                : 'Se retiraron los registros redundantes de {job}. Los servicios actuales, las IP, las velocidades y los routers se conservaron.', { job: row.job_id });
            const when = document.createElement('p'); translatedText(when, 'Verificado: {time}', { time: formatDate(data.checked_at) });
            const backup = document.createElement('a'); backup.className = 'btn btn-secondary'; backup.href = api.downloadUrl(data.backup_name); backup.download = data.backup_name; translatedText(backup, 'Descargar respaldo');
            result.replaceChildren(title, jobRecoveryIdentity(row), note, when, backup); result.style.display = 'block'; result.focus?.({ preventScroll: true });
            byId('apply-job-recovery').style.display = 'none'; byId('recheck-job-recovery').style.display = 'none';
            byId('preserve-job-recovery').style.display = 'none';
            byId('discard-job-recovery').style.display = 'none';
            translatedText(byId('job-recovery-status'), 'La lista y el indicador se están actualizando.');
            await Promise.all([refreshJobRecoveries(), refreshJobsStatus()]);
            translatedText(byId('job-recovery-status'), discarding ? 'Registro local descartado' : preserving ? 'Revisión cerrada; recursos conservados' : 'Resolución confirmada. La lista y el indicador se consultaron de nuevo.');
        } catch (error) {
            translatedText(byId('job-recovery-status'), 'No se confirmó la resolución. Refresca la lista y verifica de nuevo antes de intentar otro cambio. {message}', { message: jobRecoveryReason(error.message) });
            await Promise.all([refreshJobRecoveries(), refreshJobsStatus()]);
        } finally { jobRecoveryBusy = false; updateJobRecoveryControls(); renderJobRecoveries(); }
    });

    function renderJobsStatus() {
        if (!byId('jobs-pending-warning')) return;
        const known = jobsStatus?.available === true;
        const pending = known ? jobsStatus.pending : null;
        const active = known ? jobsStatus.pending + jobsStatus.running : 0;
        const uncertain = jobsStatusFailure || !known;
        const count = byId('jobs-pending-count');
        count.textContent = known && jobsStatus.total > 0 ? String(jobsStatus.total) : uncertain ? '?' : '';
        count.style.display = known && jobsStatus.total > 0 || uncertain ? 'inline-block' : 'none';
        count.title = uncertain ? t('El estado de los trabajos no está actualizado.')
            : t('{pending} pendientes · {running} en ejecución · {recovery} recuperaciones', jobsStatus);
        setTopWarning('jobs-pending-warning', uncertain || known && jobsStatus.total > 0,
            [known ? 1 : 0, jobsStatus?.pending ?? 0, jobsStatus?.failed ?? 0, jobsStatus?.running ?? 0, jobsStatus?.recovery ?? 0, uncertain ? 1 : 0], known || jobsStatusFailure);
        translatedText(byId('jobs-pending-title'), known ? jobsStatus.recovery > 0 ? active > 0 ? 'Trabajos y recuperaciones: {count}' : 'Recuperaciones pendientes: {count}'
            : pending > 0 ? 'Trabajos pendientes: {count}' : 'Trabajos en ejecución: {count}'
            : 'No se pudo comprobar el estado de los trabajos.', { count: jobsStatus?.recovery > 0 ? active > 0 ? jobsStatus.total : jobsStatus.recovery : pending > 0 ? pending : jobsStatus?.running ?? 0 });
        translatedText(byId('jobs-pending-note'), known
            ? '{pending} pendientes · {failed} con error · {running} en ejecución · {recovery} en recuperación. Revisa Trabajos para comprobar el resultado.'
            : 'El aviso no cambia ni ejecuta trabajos. Puedes seguir usando el panel.', jobsStatus || {});
        const time = known ? formatDate(jobsStatus.checked_at) : null;
        translatedText(byId('jobs-pending-freshness'), uncertain ? time
            ? 'El estado puede estar desactualizado. Última consulta: {time}.' : 'Se volverá a comprobar automáticamente cuando el panel esté visible.'
            : 'Última consulta: {time}. Este aviso no detiene las operaciones de otros clientes.', { time });
    }
    function clearJobsStatusTimer() { clearTimeout(jobsStatusTimer); jobsStatusTimer = undefined; }
    function scheduleJobsStatus() {
        clearJobsStatusTimer();
        if (document.hidden === true || jobsStatusStopped || jobsStatusInFlight || jobsStatusMutations > 0) return;
        jobsStatusTimer = setTimeout(function pollJobsStatus() { jobsStatusTimer = undefined; refreshJobsStatus(); }, 30000);
    }
    function refreshJobsStatus() {
        if (jobsStatusInFlight) { jobsStatusRefreshRequested = true; return jobsStatusInFlight; }
        if (document.hidden === true || jobsStatusStopped || jobsStatusMutations > 0 || typeof api.getJobsStatus !== 'function') return Promise.resolve();
        clearJobsStatusTimer();
        jobsStatusRefreshRequested = false;
        const epoch = jobsStatusEpoch;
        jobsStatusInFlight = (async () => {
            try {
                const data = checked(await api.getJobsStatus()).data;
                if (epoch !== jobsStatusEpoch || document.hidden === true || jobsStatusStopped || jobsStatusMutations > 0) return;
                if (!data || data.available !== true || !['pending', 'running', 'failed', 'recovery', 'total'].every(key => Number.isSafeInteger(data[key]) && data[key] >= 0)
                    || data.failed > data.pending || data.total !== data.pending + data.running + data.recovery || !monitorDate(data.checked_at)
                    || !['sqlite', 'legacy', 'empty'].includes(data.source) || data.reason !== null) throw new Error('invalid_jobs_status');
                if (jobsStatus?.available && new Date(data.checked_at).getTime() < new Date(jobsStatus.checked_at).getTime()) throw new Error('old_jobs_status');
                const recoveryChanged = jobsStatus?.recovery !== data.recovery;
                jobsStatus = data; jobsStatusFailure = false;
                if (recoveryChanged && jobsVisible() && !jobRecoveryBusy) refreshJobRecoveries();
            } catch (_) { if (epoch === jobsStatusEpoch) jobsStatusFailure = true; }
            finally {
                jobsStatusInFlight = null;
                renderJobsStatus();
                if (jobsStatusRefreshRequested && document.hidden !== true && !jobsStatusStopped && jobsStatusMutations === 0) {
                    jobsStatusRefreshRequested = false; refreshJobsStatus();
                } else scheduleJobsStatus();
            }
        })();
        return jobsStatusInFlight;
    }
    api.onMutation?.(started => {
        jobsStatusEpoch++; clearJobsStatusTimer();
        jobsStatusMutations = Math.max(0, jobsStatusMutations + (started ? 1 : -1));
        jobsStatusRefreshRequested = true;
        if (!started && jobsStatusMutations === 0) refreshJobsStatus();
    });
    document.addEventListener('visibilitychange', () => {
        clearJobsStatusTimer(); jobsStatusEpoch++;
        if (document.hidden !== true) refreshJobsStatus();
    });
    globalThis.window?.addEventListener?.('pagehide', () => { jobsStatusStopped = true; jobsStatusEpoch++; clearJobsStatusTimer(); });
    globalThis.window?.addEventListener?.('pageshow', () => { jobsStatusStopped = false; jobsStatusEpoch++; refreshJobsStatus(); });

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
        for (const id of ['btn-run-jobs', 'btn-delete-jobs']) if (byId(id)) byId(id).disabled = jobsActionPending || recoveryReadOnly;
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
                globalThis.PanelOperations?.jobsUpdated();
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
            await refreshJobRecoveries();
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
    function jobOwnershipDiagnostic(error, serviceId) {
        const retry = 'Al ejecutar de nuevo, el plugin comprobará UISP y corregirá el comentario sólo si confirma la identidad de la cuenta y que el servicio indicado terminó, ya no existe o corresponde a otro servicio con datos distintos.';
        if (error === 'router_ownership_record_changed') return { parts: [{ source: 'La cuenta del router cambió durante la comprobación. Se requiere verificarla de nuevo.', params: {} },
            { source: retry, params: {} }], links: [] };
        if (error === 'The matching RouterOS account or queue belongs to another service or was created manually') {
            return { parts: [{ source: 'La cuenta o cola del router tiene otro vínculo de servicio o fue creada manualmente. Revisa el vínculo en UISP y ejecuta de nuevo para comprobarlo.', params: {} },
                { source: retry, params: {} }], links: [] };
        }
        if (typeof error !== 'string') return null;
        const match = /^router_ownership_conflict: current_service=([1-9]\d{0,14}) previous_service=(0|[1-9]\d{0,14}) reason=([a-z_]+)$/.exec(error);
        if (!match || !Number.isSafeInteger(Number(match[1])) || !Number.isSafeInteger(Number(match[2]))
            || Number(match[1]) !== Number(serviceId) || match[1] === match[2]) return null;
        const reasons = {
            identity_unverified: 'No se pudo confirmar la identidad de la cuenta del router. Revisa el usuario y su vínculo con el cliente en UISP.',
            router_changed: 'La configuración del router cambió. Sincroniza de nuevo el servicio con los datos actuales de UISP.',
            mapping_unverified: 'No se pudo comprobar el catálogo de atributos de UISP. Verifica que esté disponible antes de reintentar.',
            mapping_site_unverified: 'No se pudo comprobar el atributo de Site configurado en el plugin. Revisa su clave y que sea un atributo de servicio en UISP.',
            mapping_user_unverified: 'No se pudo comprobar el atributo de usuario configurado en el plugin. Revisa su clave y que sea un atributo de servicio en UISP.',
            mapping_hotspot_unverified: 'No se pudo comprobar el atributo de Hotspot configurado en el plugin. Revisa su clave y que sea un atributo de servicio en UISP.',
            marker_unverified: 'El ID del comentario corresponde a otro cliente, pero faltan datos coincidentes de usuario, Site o IP para corregirlo automáticamente. Revisa la cuenta y ambas fichas.',
            client_unverified: 'No se pudo confirmar que el cliente esté vigente en UISP. Revisa su ficha antes de reintentar.',
            target_unverified: 'No se pudo comprobar el servicio actual en UISP. Revisa su ficha antes de reintentar.',
            target_changed: 'El servicio actual cambió en UISP. Sincronízalo de nuevo para usar su estado y sus atributos actuales.',
            previous_unverified: 'No se pudo comprobar el servicio indicado en UISP. El comentario de la cuenta se conserva.',
            previous_active: 'El servicio indicado sigue vigente en UISP. Revisa ambas fichas y corrige el vínculo antes de reintentar.',
            previous_job_pending: 'El servicio indicado tiene un trabajo pendiente sobre esta cuenta. Revisa ese trabajo antes de reintentar.',
            queue_unverified: 'No se pudieron comprobar los trabajos del servicio indicado. El comentario de la cuenta se conserva.',
            previous_changed: 'Los datos del servicio indicado cambiaron durante la comprobación. El comentario de la cuenta se conserva.',
            catalog_incomplete: 'No se pudo completar la comprobación de los servicios en UISP.',
            peer_site_unverified: 'Otro servicio tiene el mismo usuario y no se pudo comprobar su Site. Revisa sus atributos en UISP.',
            account_claimed: 'Otro servicio reclama esta misma cuenta en el router. Revisa los usuarios y Sites en UISP y el estado aplicado antes de reintentar.',
            verification_unavailable: 'No se pudo completar la verificación con UISP. El comentario de la cuenta se conserva.',
            backup_failed: 'No se pudo guardar el respaldo del comentario de la cuenta. Revisa el espacio y los permisos de la carpeta de datos del plugin.',
            record_changed: 'La cuenta del router cambió durante la comprobación. Se requiere verificarla de nuevo.'
        };
        if (!Object.prototype.hasOwnProperty.call(reasons, match[3]) || match[2] === '0' && match[3] !== 'identity_unverified') return null;
        const current = Number(match[1]), previous = Number(match[2]), parts = [], links = [];
        if (previous) {
            parts.push({ source: 'El comentario de la cuenta del servicio #{current} indica el servicio #{previous}. Ese ID debe comprobarse con UISP.', params: { current, previous } });
            links.push({ id: current, label: 'Servicio actual #{id}' }, { id: previous, label: 'Servicio indicado #{id}' });
        }
        parts.push({ source: reasons[match[3]], params: {} });
        if (['previous_unverified', 'previous_changed', 'catalog_incomplete', 'verification_unavailable', 'record_changed', 'queue_unverified'].includes(match[3]))
            parts.push({ source: retry, params: {} });
        return { parts, links };
    }
    function jobOwnershipErrorMarkup(error, serviceId) {
        const detail = jobOwnershipDiagnostic(error, serviceId);
        if (!detail) return null;
        const parts = detail.parts.map(({ source, params }) => `<span data-i18n="${html(source)}" data-i18n-values="${html(JSON.stringify(params))}">${html(t(source, params))}</span>`);
        for (const link of detail.links) {
            const url = safeCrmUrl('/crm/client/service/' + link.id, 'service');
            if (url) parts.push(`<a class="conflict-link" href="${html(url)}" target="_blank" rel="noopener noreferrer" data-i18n="${html(link.label)}" data-i18n-values="${html(JSON.stringify({ id: link.id }))}">${html(t(link.label, { id: link.id }))}</a>`);
        }
        return parts.join(' ');
    }
    function jobLifecycleErrorMarkup(error) {
        const source = {
            lifecycle_unavailable: 'No se pudo comprobar el estado actual en UISP. El trabajo se revisará en el próximo reintento automático.',
            lifecycle_review_pending: 'La revisión del estado quedó pendiente para otra pasada automática.',
            lifecycle_identity_changed: 'La identidad del cliente cambió. Revisa el servicio y su vínculo en UISP antes de retirar recursos.',
            lifecycle_reactivated: 'UISP indica que el servicio volvió a estar vigente. Se conservaron sus recursos y se revisará su estado actual.',
            lifecycle_router_identity_unknown: 'No se pudo identificar el router o la cuenta anterior. Revisa el usuario y el dispositivo del servicio.',
            lifecycle_resources_unconfirmed: 'La baja anterior no se pudo confirmar en el router. Sus reservas se conservan hasta comprobar la limpieza.',
            lifecycle_changed: 'El estado del servicio cambió durante la revisión. Se comprobará de nuevo antes de modificarlo.'
        }[error];
        return typeof source === 'string' ? `<span data-i18n="${html(source)}">${html(t(source))}</span>` : null;
    }
    function jobNetworkErrorMarkup(error, serviceId) {
        const collision = /^network_address_conflict: requested_service=([1-9]\d{0,14}) blocking_service=([1-9]\d{0,14}) source=(reserved|cache|applied) family=(IPv4|IPv6)$/.exec(error);
        if (collision && Number.isSafeInteger(Number(collision[1])) && Number.isSafeInteger(Number(collision[2])) && Number(collision[1]) === Number(serviceId)) {
            const source = 'La IP o prefijo {family} solicitado ya está reservado por el servicio {id}. Revisa ambas asignaciones en UISP.';
            const params = { family: collision[4], id: Number(collision[2]) };
            const url = safeCrmUrl('/crm/client/service/' + collision[2], 'service');
            const requested = { id: Number(collision[1]) }, requestLabel = 'Trabajo solicitante: servicio #{id}.';
            const requesterUrl = safeCrmUrl('/crm/client/service/' + collision[1], 'service');
            return `<span data-i18n="${html(requestLabel)}" data-i18n-values="${html(JSON.stringify(requested))}">${html(t(requestLabel, requested))}</span> <span data-i18n="${html(source)}" data-i18n-values="${html(JSON.stringify(params))}">${html(t(source, params))}</span>`
                + (requesterUrl ? ` <a class="conflict-link" href="${html(requesterUrl)}" target="_blank" rel="noopener noreferrer" data-i18n="Servicio solicitante #{id}" data-i18n-values="${html(JSON.stringify(requested))}">${html(t('Servicio solicitante #{id}', requested))}</a>` : '')
                + (url ? ` <a class="conflict-link" href="${html(url)}" target="_blank" rel="noopener noreferrer" data-i18n="Servicio con reserva #{id}" data-i18n-values="${html(JSON.stringify({ id: params.id }))}">${html(t('Servicio con reserva #{id}', { id: params.id }))}</a>` : '');
        }
        const match = /^invalid_network_address: requested_service=([1-9]\d{0,14}) blocking_service=([1-9]\d{0,14}) source=(input|reserved|applied) family=(IPv4|IPv6)$/.exec(error);
        if (!match || !Number.isSafeInteger(Number(match[1])) || !Number.isSafeInteger(Number(match[2])) || Number(match[1]) !== Number(serviceId)) return null;
        const own = match[3] === 'input';
        if (own && match[1] !== match[2]) return null;
        const source = own ? 'La dirección {family} de este servicio no es válida. Revisa el atributo IP en el CRM.'
            : match[3] === 'reserved' ? 'Una reserva {family} inválida del servicio {id} impide asignar una IP a este trabajo.'
            : 'Un recurso {family} guardado como aplicado para el servicio {id} tiene una dirección inválida.';
        const params = { family: match[4], id: Number(match[2]) };
        const url = safeCrmUrl('/crm/client/service/' + match[2], 'service');
        const label = t('Servicio #{id}', { id: params.id });
        return `<span data-i18n="${html(source)}" data-i18n-values="${html(JSON.stringify(params))}">${html(t(source, params))}</span>`
            + (url ? ` <a class="conflict-link" href="${html(url)}" target="_blank" rel="noopener noreferrer">${html(label)}</a>` : '');
    }
    function jobNetworkRepairTarget(error, serviceId) {
        const match = /^invalid_network_address: requested_service=([1-9]\d{0,14}) blocking_service=([1-9]\d{0,14}) source=reserved family=(IPv4|IPv6)$/.exec(error);
        if (!match || !Number.isSafeInteger(Number(match[1])) || !Number.isSafeInteger(Number(match[2])) || Number(match[1]) !== Number(serviceId)) return null;
        return { serviceId: Number(match[2]), field: match[3] === 'IPv6' ? 'address6' : 'address' };
    }
    function lifecycleReason(reason) {
        return t({
            active_service: 'El servicio sigue vigente en UISP. Se conserva su asignación.',
            service_ended: 'UISP confirmó que el servicio terminó.',
            client_archived: 'UISP confirmó que el cliente está archivado.',
            service_not_found: 'UISP confirmó que el servicio no existe.',
            lifecycle_changed: 'El estado del servicio cambió. Revisa nuevamente antes de retirar sus pendientes.',
            lifecycle_router_cleanup_pending: 'La baja del router quedó programada. La IP se liberará al completar la limpieza.',
            lifecycle_retired: 'Se retiraron los pendientes anteriores del servicio.'
        }[reason] || 'No se pudo confirmar el estado actual en UISP. Vuelve a revisar.');
    }
    function updateLifecycleControls() {
        if (byId('review-service-lifecycle')) byId('review-service-lifecycle').disabled = lifecycleLoading || lifecycleApplying || restoredRequiresReload;
        if (byId('apply-service-lifecycle')) byId('apply-service-lifecycle').disabled = lifecycleLoading || lifecycleApplying || restoredRequiresReload
            || recoveryReadOnly || lifecyclePreview?.eligible !== true;
    }
    function lifecycleIdentity(data) {
        const details = byId('service-lifecycle-details'); details.replaceChildren();
        const identity = document.createElement('p'); identity.className = 'cleanup-witnesses';
        identity.appendChild(conflictLink(t('Servicio #{id}', { id: data.serviceId }), '/crm/client/service/' + data.serviceId, 'service'));
        if (Number.isSafeInteger(data.clientId) && data.clientId > 0)
            identity.appendChild(conflictLink(t('Cliente #{id}', { id: data.clientId }), '/crm/client/' + data.clientId, 'client'));
        details.appendChild(identity);
        if (Number.isInteger(data.status)) {
            const state = document.createElement('p'); translatedText(state, 'Servicio: {state}', { state: repairServiceStatus(data.status) }); details.appendChild(state);
        }
    }
    async function previewServiceLifecycle(serviceId) {
        if (!lifecycleModal || lifecycleLoading || lifecycleApplying || restoredRequiresReload || !Number.isSafeInteger(serviceId) || serviceId < 1) return;
        const generation = ++lifecycleGeneration;
        lifecycleTarget = serviceId; lifecyclePreview = null; lifecycleLoading = true;
        lifecycleModal.style.display = 'flex';
        byId('apply-service-lifecycle').style.display = 'inline-flex';
        byId('service-lifecycle-result').replaceChildren();
        lifecycleIdentity({ serviceId }); translatedText(byId('service-lifecycle-status'), 'Consultando…'); updateLifecycleControls();
        try {
            const data = checked(await api.previewServiceLifecycle(serviceId)).data;
            if (generation !== lifecycleGeneration || lifecycleTarget !== serviceId) return;
            const eligibleReasons = ['service_ended', 'client_archived', 'service_not_found'];
            if (!data || data.serviceId !== serviceId || !['active', 'retired', 'unknown'].includes(data.state)
                || (data.clientId !== null && (!Number.isSafeInteger(data.clientId) || data.clientId < 1))
                || (data.status !== null && (!Number.isInteger(data.status) || data.status < 0 || data.status > 8))
                || typeof data.missing !== 'boolean' || typeof data.eligible !== 'boolean'
                || (data.eligible && (data.state !== 'retired' || !eligibleReasons.includes(data.reason)
                    || typeof data.previewToken !== 'string' || !/^[a-f0-9]{64}$/.test(data.previewToken) || !monitorDate(data.expiresAt))))
                throw new Error('lifecycle_invalid');
            lifecycleIdentity(data); lifecyclePreview = data;
            byId('service-lifecycle-status').textContent = lifecycleReason(data.reason);
            if (data.eligible) {
                const note = document.createElement('p'); note.className = 'form-note';
                translatedText(note, 'Se retirarán los pendientes anteriores de este servicio. Si quedan recursos en el router, se programará su baja antes de liberar la IP.');
                const expiry = document.createElement('p'); expiry.className = 'form-note'; translatedText(expiry, 'Revisión válida hasta: {time}.', { time: formatDate(data.expiresAt) });
                byId('service-lifecycle-details').append(note, expiry);
            }
        } catch (_) {
            if (generation === lifecycleGeneration) {
                lifecyclePreview = null; byId('service-lifecycle-status').textContent = lifecycleReason('lifecycle_unavailable');
            }
        } finally { if (generation === lifecycleGeneration) { lifecycleLoading = false; updateLifecycleControls(); } }
    }
    byId('review-service-lifecycle')?.addEventListener('click', () => { if (lifecycleTarget !== null) previewServiceLifecycle(lifecycleTarget); });
    byId('apply-service-lifecycle')?.addEventListener('click', async () => {
        if (lifecycleLoading || lifecycleApplying || recoveryReadOnly || restoredRequiresReload || lifecyclePreview?.eligible !== true) return;
        const preview = lifecyclePreview, generation = lifecycleGeneration;
        lifecyclePreview = null; lifecycleApplying = true; updateLifecycleControls();
        translatedText(byId('service-lifecycle-status'), 'Consultando…');
        try {
            const data = checked(await api.applyServiceLifecycle(preview.previewToken)).data;
            if (generation !== lifecycleGeneration) return;
            if (!data || data.serviceId !== preview.serviceId || (data.clientId !== null && (!Number.isSafeInteger(data.clientId) || data.clientId < 1))
                || !['active', 'retired', 'unknown'].includes(data.state) || typeof data.retired !== 'boolean' || typeof data.released !== 'boolean'
                || !Number.isSafeInteger(data.queued) || data.queued < 0 || !Array.isArray(data.jobKeys)
                || data.jobKeys.length > 100 || data.jobKeys.some(key => typeof key !== 'string' || !/^Q[1-9]\d*$/.test(key))
                || (data.released && (!data.retired || data.queued > 0))) throw new Error('lifecycle_invalid');
            byId('service-lifecycle-status').textContent = data.retired
                ? t(data.queued > 0 ? 'La baja del router quedó programada. La IP se liberará al completar la limpieza.'
                    : data.released ? 'Se retiraron los pendientes anteriores y se liberaron sus reservas locales.' : 'Se retiraron los pendientes anteriores del servicio.')
                : lifecycleReason(data.reason);
            const result = byId('service-lifecycle-result'); result.replaceChildren();
            for (const key of [...new Set(data.jobKeys)]) {
                const job = document.createElement('button'); job.type = 'button'; job.className = 'btn btn-secondary';
                job.textContent = t('Baja programada') + ' · ' + key;
                job.addEventListener('click', () => { jobReviewRequested = key; closeModals(); switchView('jobs', 'Trabajos'); }); result.appendChild(job);
            }
            byId('apply-service-lifecycle').style.display = 'none';
            try { await refreshJobsStatus(); if (currentView === 'jobs') await renderJobs(); }
            catch (_) { notify(t('No se pudo actualizar. Se conserva la última lectura.'), 'error'); }
        } catch (error) {
            if (generation === lifecycleGeneration) byId('service-lifecycle-status').textContent = error.message === 'lifecycle_changed'
                ? lifecycleReason('lifecycle_changed') : t('No se confirmó el retiro. Revisa nuevamente antes de intentarlo.');
        } finally { lifecycleApplying = false; updateLifecycleControls(); }
    });
    function renderJobsTable(jobs) {
        const savedPosition = beforeTableUpdate('jobs');
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
            const lifecycleError = jobLifecycleErrorMarkup(error);
            const explainedError = lifecycleError ?? jobOwnershipErrorMarkup(error, job.serviceId ?? job.id) ?? jobNetworkErrorMarkup(error, job.serviceId ?? job.id);
            const connectionHelp = jobConnectionHelp(error);
            const stateKey = job.state === 'pending' ? 'Pendiente' : job.state === 'running' ? 'En ejecución' : 'Estado desconocido';
            const attempts = Number.isSafeInteger(job.attempts) && job.attempts >= 0 ? job.attempts : 0;
            const nextRetry = job.nextRetry
                ? ` · <span data-i18n="Próximo intento">${html(t('Próximo intento'))}</span>: <span data-date="${html(job.nextRetry)}">${html(formatDate(job.nextRetry))}</span>` : '';
            row.innerHTML = `<td class="job-description"><span data-i18n="${html(descriptionKey)}" data-i18n-values="${html(JSON.stringify(descriptionValues))}">${html(description)}</span>
                <br><small class="text-muted">${html(job.jobId || key)} · <span data-i18n="${html(stateKey)}">${html(t(stateKey))}</span> · <span data-i18n="Intentos: {count}" data-i18n-values="${html(JSON.stringify({ count: attempts }))}">${html(t('Intentos: {count}', { count: attempts }))}</span>${nextRetry}</small>
                ${error ? `<br><span class="job-error"><span data-i18n="Último error">${html(t('Último error'))}</span>: ${explainedError ?? html(error)}</span>` : ''}
                ${connectionHelp ? `<br><small class="text-muted" data-i18n="${html(connectionHelp)}">${html(t(connectionHelp))}</small>` : ''}
                ${jobNoteMarkup(jobReason(job), 'Bloqueado')}
                ${jobNoteMarkup(job.canDelete === false ? jobReason(job, true) : null, 'No se puede eliminar')}
                </td><td class="job-selection"><input type="checkbox" class="job-checkbox" aria-label="${html(t('Seleccionar trabajo'))}" data-i18n-aria-label="Seleccionar trabajo"></td>`;
            const checkbox = row.querySelector('.job-checkbox');
            checkbox.value = key;
            checkbox.checked = (selected.has(key) || jobReviewRequested === key) && (!job.state || job.state === 'pending');
            if (jobReviewRequested === key) { row.className = 'job-review-target'; checkbox.focus?.({ preventScroll: false }); }
            checkbox.addEventListener('change', updateJobSelection);
            const repair = jobNetworkRepairTarget(error, job.serviceId ?? job.id);
            const diagnosticId = Number(job.serviceId ?? job.id);
            const collision = /^network_address_conflict: requested_service=([1-9]\d*) blocking_service=([1-9]\d*) source=(?:reserved|cache|applied) family=(IPv[46])$/.exec(error);
            const validCollision = collision && Number(collision[1]) === diagnosticId && Number.isSafeInteger(Number(collision[2]));
            if ((validCollision || lifecycleError !== null) && Number.isSafeInteger(diagnosticId) && diagnosticId > 0
                && lifecycleModal && typeof api.previewServiceLifecycle === 'function') {
                const lifecycle = document.createElement('button'); lifecycle.type = 'button'; lifecycle.className = 'btn btn-secondary job-repair-button';
                translatedText(lifecycle, 'Revisar servicio solicitante #{id}', { id: diagnosticId });
                lifecycle.disabled = lifecycleApplying || restoredRequiresReload;
                lifecycle.addEventListener('click', () => previewServiceLifecycle(diagnosticId));
                row.querySelector('.job-description').appendChild(lifecycle);
            }
            if (validCollision) {
                const review = document.createElement('button'); review.type = 'button'; review.className = 'btn btn-secondary job-repair-button';
                translatedText(review, 'Revisar reserva del servicio #{id}', { id: Number(collision[2]) });
                review.disabled = cleanupBusy || cleanupRequiresReload;
                review.addEventListener('click', () => previewCleanup([Number(collision[2])], 'job_blocker', collision[3]));
                row.querySelector('.job-description').appendChild(review);
            }
            for (const related of (Array.isArray(job.relatedServices) ? job.relatedServices : []).slice(0, 3)) {
                const service = Number(related.serviceId), client = Number(related.clientId);
                if (!Number.isSafeInteger(service) || service < 1 || !Number.isSafeInteger(client) || client < 1) continue;
                const url = safeCrmUrl('/crm/client/' + client, 'client'); if (!url) continue;
                const link = document.createElement('a'); link.className = 'conflict-link job-repair-button';
                link.href = url; link.target = '_blank'; link.rel = 'noopener noreferrer';
                link.textContent = t('Servicio #{id}', { id: service }) + ' · ' + t('Cliente #{id}', { id: client });
                row.querySelector('.job-description').appendChild(link);
            }
            if (globalThis.PanelOperations && Number.isSafeInteger(diagnosticId) && diagnosticId > 0) {
                if (typeof globalThis.PanelServiceResolution?.resolveService === 'function') {
                    const resolve = document.createElement('button'); resolve.type = 'button'; resolve.className = 'btn btn-primary job-repair-button';
                    translatedText(resolve, 'Resolver'); markRecoveryWrite(resolve);
                    resolve.disabled = resolve.disabled || job.state === 'running' || jobsActionPending;
                    resolve.addEventListener('click', () => {
                        if (job.state !== 'running' && !jobsActionPending && !recoveryReadOnly && !restoredRequiresReload)
                            return globalThis.PanelServiceResolution.resolveService(diagnosticId);
                    });
                    row.querySelector('.job-description').appendChild(resolve);
                }
                const diagnostic = document.createElement('button'); diagnostic.type = 'button'; diagnostic.className = 'btn btn-secondary job-repair-button';
                translatedText(diagnostic, 'Diagnóstico por servicio');
                diagnostic.addEventListener('click', () => globalThis.PanelOperations.openService(diagnosticId));
                row.querySelector('.job-description').appendChild(diagnostic);
            }
            if (repair && repairModal && typeof api.previewNetworkRepair === 'function') {
                const review = document.createElement('button'); review.type = 'button'; review.className = 'btn btn-secondary job-repair-button';
                translatedText(review, 'Revisar reserva del servicio #{id}', { id: repair.serviceId });
                review.disabled = repairBusy || repairRequiresReload;
                review.addEventListener('click', () => previewNetworkRepair(repair.serviceId, repair.field));
                row.querySelector('.job-description').appendChild(review);
            }
            tbody.appendChild(row);
        });
        jobReviewRequested = null;
        updateJobSelection();
        afterTableUpdate('jobs', savedPosition);
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
                if (job.verification && globalThis.PanelOperations) parts.push(t('{job}: {reason}', { job: key,
                    reason: globalThis.PanelOperations.verificationLabel(job.verification.state) }));
                continue;
            }
            if (action === 'delete' && result === true) continue;
            const note = jobReason(job, action === 'delete');
            const reason = note ? t(note[0], note[1]) : t(action === 'delete' ? 'No se pudo eliminar el trabajo.'
                : result && result.attempted !== false ? 'El intento falló.' : 'Pendiente; no se intentó.');
            const lastError = action === 'run' && typeof result?.error === 'string' && result.error ? result.error : job.error;
            const ownership = jobOwnershipDiagnostic(lastError, job.serviceId ?? job.id);
            const explainedError = ownership ? ownership.parts.map(({ source, params }) => t(source, params)).join(' ') : String(lastError).slice(0, 500);
            const detail = t('{job}: {reason}', { job: key, reason })
                + (lastError ? ' ' + t('Último error: {message}', { message: explainedError }) : '');
            if (details++ < 8) parts.push(detail);
            const help = jobConnectionHelp(lastError);
            if (help && !connectionHelp.has(help)) { connectionHelp.add(help); parts.push(t(help)); }
        }
        if (details > 8) parts.push(t('Otros {count} trabajos requieren atención.', { count: details - 8 }));
        const error = Boolean(response.error || ['failed', 'error'].includes(response.status)
            || summary.failed || summary.pending || summary.refused);
        if (error && !summary.failed && !summary.pending && !summary.refused) parts.push(t('No se pudo confirmar el resultado de la operación.'));
        const unverified = action === 'run' && Object.values(data.jobs).some(job =>
            ['unconfirmed', 'mismatch'].includes(job.verification?.state));
        return { data, message: parts.join(' '), kind: error ? 'error' : unverified ? 'info' : 'success' };
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
            if (feedback) { await globalThis.PanelOperations?.refreshResults(); notify(feedback.message, feedback.kind); return; }
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
        const startedGeneration = navigationGeneration;
        const system = checked(await api.send('list', 'sys', 'get')).data || {};
        const systemPosition = startedGeneration === navigationGeneration ? beforeTableUpdate('system') : null;
        byId('system-info-container').innerHTML = [
            ['Versión del plugin', system.app_version], ['Versión de PHP', system.php_version],
            ['Última caché', system.last_cache, true], ['Próxima caché', system.next_cache, true]
        ].map(([label, value, date]) => `<div class="system-info"><div class="text-muted" data-i18n="${html(label)}">${html(t(label))}</div><div${date ? ` data-date="${html(value || '')}"` : ''}>${html(date ? formatDate(value) : value)}</div></div>`).join('');
        afterTableUpdate('system', systemPosition);
        const backups = checked(await api.getBackups()).data || [];
        const savedPosition = startedGeneration === navigationGeneration ? beforeTableUpdate('system') : null;
        const grid = byId('backups-grid');
        grid.replaceChildren();
        if (!backups.length) grid.innerHTML = `<div class="empty-state" data-i18n="No hay respaldos.">${html(t('No hay respaldos.'))}</div>`;
        backups.forEach(backup => {
            const cleanupBackup = /^network-cleanup-\d{8}T\d{6}Z-[a-f0-9]{32}\.sqlite$/.test(backup.name);
            if (!cleanupBackup && !/^backup-[0-6]$/.test(backup.name)) return;
            const card = document.createElement('div');
            card.className = 'backup-card';
            const date = document.createElement('span');
            date.textContent = formatDate(backup.date); date.dataset.date = backup.date || '';
            const controls = document.createElement('div');
            controls.className = 'row-actions';
            if (!cleanupBackup) controls.appendChild(actionButton('Restaurar respaldo', 'refresh-cw', async () => {
                if (!await confirmAction(t('¿Restaurar {name}?', { name: backup.name }))) return;
                completed(await api.restoreBackup(backup.name), t('Copia de seguridad restaurada.'));
                await renderSystem();
            }, 'secondary', { write: true }));
            const download = document.createElement('a');
            download.className = 'btn btn-secondary row-action';
            download.href = api.downloadUrl(backup.name);
            download.download = cleanupBackup ? backup.name : backup.name + '.db';
            download.title = t('Descargar respaldo');
            download.setAttribute('aria-label', t('Descargar respaldo'));
            download.setAttribute('data-i18n-title', 'Descargar respaldo');
            download.setAttribute('data-i18n-aria-label', 'Descargar respaldo');
            download.innerHTML = '<i data-feather="download"></i>';
            controls.appendChild(download);
            if (cleanupBackup) {
                const copy = document.createElement('div');
                const label = document.createElement('strong'); label.className = 'backup-label';
                translatedText(label, 'Respaldo de limpieza'); copy.append(label, date);
                card.append(copy, controls);
            } else card.append(date, controls);
            grid.appendChild(card);
        });
        PanelUtils.replaceIcons();
        afterTableUpdate('system', savedPosition);
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
        if (recoveryReadOnly) return;
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
        if (recoveryReadOnly && !device) return;
        byId('device-form').reset();
        byId('dev-id').value = device?.id ?? '';
        translatedText(byId('modal-title'), recoveryReadOnly ? 'Ver dispositivo' : device ? 'Editar dispositivo' : 'Agregar dispositivo');
        byId('dev-password').value = '';
        byId('dev-password').required = !device;
        for (const field of ['name', 'ip', 'user', 'port', 'pool', 'pool6', 'pfxLength']) byId('dev-' + field).value = device?.[field] ?? '';
        byId('dev-ssl').checked = device ? Boolean(PanelUtils.flag(device.ssl ?? (Number(device.port) === 8729 ? 1 : 0))) : false;
        byId('dev-allowSelfSigned').checked = Boolean(PanelUtils.flag(device?.allowSelfSigned));
        updateTlsControls();
        applyRecoveryControls();
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
        applyRecoveryControls();
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
        applyRecoveryControls();
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
        if (recoveryReadOnly) applyRecovery({ readOnly: true });
        renderCrmMonitor();
        renderCrmEditResult();
        renderJobsStatus();
        renderJobRecoveries();
        renderInvalidReservations();
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

    globalThis.PanelServiceResolution?.mount({ api, notify, navigate: (view, serviceId) => {
        if (view === 'diagnostics' && Number.isSafeInteger(serviceId) && serviceId > 0) globalThis.PanelOperations?.openService(serviceId);
        else switchView(view, view);
    }, reviewJob: key => {
        if (!/^Q[1-9]\d*$/.test(key)) return;
        jobReviewRequested = key; switchView('jobs', 'Trabajos');
    }, onApplied: result => {
        refreshJobsStatus(); if (currentView === 'jobs') renderJobs();
        globalThis.PanelOperations?.refreshService?.(result.serviceId);
    } });
    globalThis.PanelOperations?.mount({ api, resolveService: typeof globalThis.PanelServiceResolution?.resolveService === 'function'
        ? serviceId => globalThis.PanelServiceResolution.resolveService(serviceId) : undefined,
    navigate: view => switchView(view, view), reviewJob: key => {
        if (!/^Q[1-9]\d*$/.test(key)) return;
        jobReviewRequested = key;
        switchView('jobs', 'Trabajos');
    } });
    globalThis.PanelWorkflows?.mount({ api, notify, navigate: (view, serviceId) => {
        if (view === 'diagnostics' && Number.isSafeInteger(serviceId) && serviceId > 0) globalThis.PanelOperations?.openService(serviceId);
        else switchView(view, view);
    }, reviewJob: key => {
        if (!/^Q[1-9]\d*$/.test(key)) return;
        jobReviewRequested = key; switchView('jobs', 'Trabajos');
    }, onApplied: (result) => {
        if (result?.requiresReload) {
            restoredRequiresReload = true; api.requireReload?.();
            globalThis.PanelServiceResolution?.requireReload?.();
            clearJobsRefreshTimer(); applyRecoveryControls(); return;
        }
        refreshJobsStatus();
        if (currentView === 'jobs') renderJobs();
    } });
    PanelUtils.replaceIcons();
    api.onRecovery?.(applyRecovery);
    if (api.recovery?.readOnly === true) applyRecovery(api.recovery);
    renderCrmMonitor();
    refreshCrmMonitor();
    refreshJobsStatus();
    switchView(initialView, initialView);
});
