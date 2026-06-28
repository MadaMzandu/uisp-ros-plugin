/**
 * Lógica de la aplicación Panel V2
 */

document.addEventListener('DOMContentLoaded', () => {
    // Referencias UI
    const navItems = document.querySelectorAll('.nav-item');
    const viewSections = document.querySelectorAll('.view-section');
    const pageTitle = document.getElementById('page-title');
    const topbarActions = document.getElementById('topbar-actions');
    const loader = document.getElementById('loader');

    // UI Referencias Modal
    const deviceModal = document.getElementById('device-modal');
    const planModal = document.getElementById('plan-modal');
    const closeButtons = document.querySelectorAll('.close-modal');

    // Estado local
    let currentView = 'devices';
    let devicesCache = [];
    let plansCache = [];
    let configCache = {};
    let fieldsCache = null;

    // --- Ruteo y Navegación ---
    function switchView(viewName, title) {
        currentView = viewName;
        pageTitle.textContent = title;
        
        navItems.forEach(nav => {
            if (nav.dataset.view === viewName) {
                nav.classList.add('active');
            } else {
                nav.classList.remove('active');
            }
        });

        viewSections.forEach(section => {
            section.style.display = section.id === `view-${viewName}` ? 'block' : 'none';
        });

        renderTopbarActions(viewName);
        loadData(viewName);
    }

    navItems.forEach(nav => {
        nav.addEventListener('click', (e) => {
            e.preventDefault();
            const viewName = nav.dataset.view;
            const title = nav.querySelector('span').textContent;
            switchView(viewName, title);
        });
    });

    function renderTopbarActions(viewName) {
        topbarActions.innerHTML = '';
        if (viewName === 'devices') {
            const btn = document.createElement('button');
            btn.className = 'btn btn-primary';
            btn.innerHTML = '<i data-feather="plus"></i> Agregar Dispositivo';
            btn.onclick = () => openDeviceModal();
            topbarActions.appendChild(btn);
            feather.replace();
        }
    }

    async function loadData(viewName) {
        showLoader();
        if (viewName === 'devices') {
            await renderDevices();
        } else if (viewName === 'plans') {
            await renderPlans();
        } else if (viewName === 'config') {
            await renderConfig();
        } else if (viewName === 'jobs') {
            await renderJobs();
        } else if (viewName === 'system') {
            await renderSystem();
        }
        hideLoader();
    }

    // --- Dispositivos ---
    async function renderDevices() {
        const response = await api.getDevices();
        if (response.error) return alert('Error: ' + response.message);

        devicesCache = response.data || [];
        const tbody = document.querySelector('#devices-table tbody');
        tbody.innerHTML = '';

        if (devicesCache.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="empty-state">No hay dispositivos</td></tr>`;
            return;
        }

        devicesCache.forEach(dev => {
            const statusBadge = dev.status 
                ? `<span class="badge badge-success">En línea</span>` 
                : `<span class="badge badge-danger">Desconectado</span>`;
            
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${dev.id}</td>
                <td style="font-weight: 500; color: #fff;">${dev.name}</td>
                <td>${dev.ip}</td>
                <td>${dev.users || 0}</td>
                <td>${statusBadge}</td>
                <td>
                    <button class="btn btn-secondary" style="padding:4px 8px; font-size: 0.75rem;" onclick="editDevice(${dev.id})" title="Editar"><i data-feather="edit-2" style="width:14px;height:14px;"></i></button>
                    <button class="btn btn-secondary" style="padding:4px 8px; font-size: 0.75rem;" onclick="syncDevice(${dev.id})" title="Sincronizar cuentas"><i data-feather="refresh-cw" style="width:14px;height:14px;"></i></button>
                    <button class="btn btn-secondary" style="padding:4px 8px; font-size: 0.75rem;" onclick="clearDevice(${dev.id})" title="Vaciar cuentas"><i data-feather="x-circle" style="width:14px;height:14px;"></i></button>
                    <button class="btn btn-danger" style="padding:4px 8px; font-size: 0.75rem;" onclick="deleteDevice(${dev.id})" title="Eliminar"><i data-feather="trash-2" style="width:14px;height:14px;"></i></button>
                </td>
            `;
            tbody.appendChild(tr);
        });
        feather.replace();
    }

    // --- Planes ---
    async function renderPlans() {
        const response = await api.getPlans();
        if (response.error) return alert('Error: ' + response.message);

        plansCache = response.data || [];
        const tbody = document.querySelector('#plans-table tbody');
        tbody.innerHTML = '';

        // Add search input if it doesn't exist
        if (!document.getElementById('plans-search')) {
            const tableContainer = document.querySelector('#view-plans .card');
            if (tableContainer) {
                const searchHtml = `
                    <div style="padding: 1rem; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; gap: 0.5rem;">
                        <i data-feather="search" style="width: 16px; height: 16px; color: var(--text-muted);"></i>
                        <input type="text" id="plans-search" class="form-control" placeholder="Buscar planes..." style="max-width: 300px; background: rgba(0,0,0,0.2); border-color: rgba(255,255,255,0.1);">
                    </div>
                `;
                tableContainer.insertAdjacentHTML('afterbegin', searchHtml);
                document.getElementById('plans-search').addEventListener('input', () => {
                    renderPlansTable();
                });
                feather.replace();
            }
        }

        if (!Array.isArray(plansCache)) {
            plansCache = Object.values(plansCache);
        }
        
        renderPlansTable();
    }

    function renderPlansTable() {
        const tbody = document.querySelector('#plans-table tbody');
        tbody.innerHTML = '';
        
        const searchTerm = document.getElementById('plans-search') ? document.getElementById('plans-search').value.toLowerCase() : '';

        const filteredPlans = plansCache.filter(plan => {
            // Eliminar los planes huérfanos/null que quedaron en la base de datos local
            if (!plan.name || plan.name === 'null') return false;
            
            // Filtro de busqueda
            if (searchTerm) {
                const searchString = `${plan.name} ${plan.id}`.toLowerCase();
                if (!searchString.includes(searchTerm)) return false;
            }
            return true;
        });

        if (filteredPlans.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="empty-state">No se encontraron planes.</td></tr>`;
            return;
        }

        filteredPlans.forEach(plan => {
            const displayBurstUpload = plan.burstUpload == -100 ? 0 : (plan.burstUpload || 0);
            const displayBurstDownload = plan.burstDownload == -100 ? 0 : (plan.burstDownload || 0);

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${plan.id}</td>
                <td style="font-weight: 500; color: #fff;">${plan.name}</td>
                <td>${plan.uploadSpeed || 0}</td>
                <td>${plan.downloadSpeed || 0}</td>
                <td>${displayBurstUpload}</td>
                <td>${displayBurstDownload}</td>
                <td>
                    <button class="btn btn-secondary" style="padding:4px 8px; font-size: 0.75rem;" onclick="editPlan(${plan.id})"><i data-feather="edit-2" style="width:14px;height:14px;"></i></button>
                </td>
            `;
            tbody.appendChild(tr);
        });
        feather.replace();
    }

    // --- Configuración ---
    async function renderConfig() {
        if (!fieldsCache) {
            const fieldsRes = await api.getFields();
            if (!fieldsRes.error && fieldsRes.data && fieldsRes.data[1]) {
                fieldsCache = fieldsRes.data[1]; // fields_es.json object
            }
        }
        
        const configRes = await api.getConfig();
        if (configRes.error) return alert('Error: ' + configRes.message);
        
        configCache = configRes.data || {};
        
        // Determinar qué tab estamos mostrando (General o Atributos)
        const activeTab = document.querySelector('#view-config .active').id;
        const isAttrs = activeTab === 'tab-attrs';
        
        const container = document.getElementById('config-fields-container');
        container.innerHTML = '';
        
        if (fieldsCache) {
            // Filtrar campos a mostrar
            Object.keys(fieldsCache).forEach(key => {
                const fieldDef = fieldsCache[key];
                // Excluir uisp_token y uisp_url porque se manejan internamente y confunden al usuario
                if (key.startsWith('config_') && fieldDef.name !== 'uisp_token' && fieldDef.name !== 'uisp_url' && fieldDef.name !== 'unmsToken' && fieldDef.name !== 'unmsUrl') {
                    // Si es tab de atributos, mostramos los que son isAttribute
                    if ((isAttrs && fieldDef.isAttribute) || (!isAttrs && !fieldDef.isAttribute)) {
                        let val = configCache[fieldDef.name];
                        if (val === null || val === undefined || val === 'null') val = '';
                        
                        // Determinar si es un booleano basado en el tipo o en el nombre de campos conocidos
                        const boolFields = ['auto_ppp_user', 'router_ppp_pool', 'unsuspend_date_fix', 'disable_contention', 'hs_enable', 'auto_hs_user'];
                        const isBooleanField = fieldDef.type === 1 || boolFields.includes(fieldDef.name);

                        let inputType = fieldDef.obscure ? 'password' : 'text';
                        let inputHtml = `<input type="${inputType}" name="${fieldDef.name}" value="${val}" class="form-control">`;
                        
                        if (isBooleanField) {
                            const isTrue = (val === 1 || val === true || val === 'true' || val === '1');
                            inputHtml = `<select name="${fieldDef.name}" class="form-control">
                                <option value="1" ${isTrue ? 'selected' : ''}>Sí (Habilitado)</option>
                                <option value="0" ${!isTrue ? 'selected' : ''}>No (Deshabilitado)</option>
                            </select>`;
                        } else if (fieldDef.name === 'lease_time') {
                            inputHtml = `<input type="number" name="${fieldDef.name}" value="${val}" class="form-control" placeholder="Ej: 60">`;
                        }

                        const div = document.createElement('div');
                        div.className = 'form-group';
                        div.innerHTML = `
                            <label style="color: #fff;">${fieldDef.label}</label>
                            ${inputHtml}
                            <small style="color: var(--text-muted); display: block; margin-top: 4px;">${fieldDef.description}</small>
                        `;
                        container.appendChild(div);
                    }
                }
            });

            // If we are in General tab, manually inject the Language selection field
            if (!isAttrs) {
                const langVal = configCache['plugin_language'] || 'system';
                const langDiv = document.createElement('div');
                langDiv.className = 'form-group';
                langDiv.innerHTML = `
                    <label>Idioma del Plugin (Plugin Language)</label>
                    <select name="plugin_language" class="form-control">
                        <option value="system" ${langVal === 'system' ? 'selected' : ''}>System Default</option>
                        <option value="es" ${langVal === 'es' ? 'selected' : ''}>Español (ES)</option>
                        <option value="en" ${langVal === 'en' ? 'selected' : ''}>English (EN)</option>
                        <option value="pt" ${langVal === 'pt' ? 'selected' : ''}>Português (PT)</option>
                        <option value="fr" ${langVal === 'fr' ? 'selected' : ''}>Français (FR)</option>
                        <option value="de" ${langVal === 'de' ? 'selected' : ''}>Deutsch (DE)</option>
                    </select>
                    <small class="form-text text-muted">Selecciona el idioma del plugin manualmente o déjalo en System Default para autodetectar.</small>
                `;
                container.appendChild(langDiv);
            }
        }
    }
    
    // Config Tabs listener
    document.getElementById('tab-general').addEventListener('click', (e) => {
        document.getElementById('tab-general').classList.add('active');
        document.getElementById('tab-attrs').classList.remove('active');
        renderConfig();
    });
    
    document.getElementById('tab-attrs').addEventListener('click', (e) => {
        document.getElementById('tab-attrs').classList.add('active');
        document.getElementById('tab-general').classList.remove('active');
        renderConfig();
    });

    document.getElementById('save-config-btn').addEventListener('click', async (e) => {
        e.preventDefault();
        const form = document.getElementById('config-form');
        const data = Object.fromEntries(new FormData(form).entries());
        
        showLoader();
        const res = await api.saveConfig(data);
        hideLoader();
        if(!res.error) {
            alert("Configuración guardada correctamente. Se recargará la página para aplicar los cambios.");
            window.location.reload();
        }
        else alert("Error: " + res.message);
    });

    // --- Trabajos ---
    async function renderJobs() {
        const response = await api.getJobs();
        const tbody = document.querySelector('#jobs-table tbody');
        tbody.innerHTML = '';
        
        const jobs = response.data || [];
        const jobsArray = Array.isArray(jobs) ? jobs : Object.keys(jobs).map(k => ({...jobs[k], _key: k}));
        
        document.getElementById('jobs-total').textContent = `Total: ${jobsArray.length}`;
        document.getElementById('jobs-selected').textContent = 'Selected: 0';
        
        if (jobsArray.length === 0) {
            tbody.innerHTML = `<tr><td colspan="2" class="empty-state">No hay trabajos en cola</td></tr>`;
            return;
        }
        
        jobsArray.forEach(job => {
            const tr = document.createElement('tr');
            tr.style.borderBottom = '10px solid transparent'; // para simular el gap
            
            const jobStr = `${job.action || 'update'} =&gt; id: ${job.id || ''}  sid: ${job.sid || job.id || ''}  device: ${job.device || ''}  user/mac: ${job.username || job.mac || ''}`;
            const errorStr = job.error ? `<br><span style="color: var(--danger); font-size: 0.85rem;">error: ${typeof job.error === 'object' ? JSON.stringify(job.error) : job.error}</span>` : '';
            
            tr.innerHTML = `
                <td style="font-family: monospace; font-size: 0.85rem; padding: 15px; background: rgba(0,0,0,0.2); border-radius: 4px;">
                    <div style="color: var(--text-muted);">${jobStr}</div>
                    ${errorStr}
                </td>
                <td style="width: 50px; text-align: center; vertical-align: middle; background: rgba(0,0,0,0.2); border-radius: 4px;">
                    <input type="checkbox" class="job-checkbox" value="${job._key || job.id}" style="width: 16px; height: 16px; cursor: pointer;">
                </td>
            `;
            tbody.appendChild(tr);
        });
        
        const checks = document.querySelectorAll('.job-checkbox');
        checks.forEach(c => c.addEventListener('change', updateJobSelection));
    }
    
    function updateJobSelection() {
        const selected = document.querySelectorAll('.job-checkbox:checked').length;
        document.getElementById('jobs-selected').textContent = `Selected: ${selected}`;
    }
    
    document.getElementById('jobs-select-all')?.addEventListener('change', (e) => {
        const checks = document.querySelectorAll('.job-checkbox');
        checks.forEach(c => c.checked = e.target.checked);
        updateJobSelection();
    });

    document.getElementById('btn-run-jobs')?.addEventListener('click', async () => {
        const selected = Array.from(document.querySelectorAll('.job-checkbox:checked')).map(c => c.value);
        if (selected.length === 0) return alert('Selecciona al menos un trabajo');
        
        showLoader();
        await api.send('update', 'jobs', 'run', selected);
        await renderJobs();
        hideLoader();
    });
    
    document.getElementById('btn-delete-jobs')?.addEventListener('click', async () => {
        const selected = Array.from(document.querySelectorAll('.job-checkbox:checked')).map(c => c.value);
        if (selected.length === 0) return alert('Selecciona al menos un trabajo');
        
        if(confirm('¿Eliminar ' + selected.length + ' trabajos?')) {
            showLoader();
            await api.send('update', 'jobs', 'delete', selected);
            await renderJobs();
            hideLoader();
        }
    });

    // --- Sistema ---
    async function renderSystem() {
        const response = await api.getBackups();
        const grid = document.getElementById('backups-grid');
        grid.innerHTML = '';
        
        const backups = response.data || [];
        if (backups.length === 0) {
            grid.innerHTML = `<div class="empty-state" style="grid-column: 1 / -1;">No hay respaldos</div>`;
        } else {
            backups.forEach(b => {
                const div = document.createElement('div');
                div.style.background = 'rgba(0,0,0,0.2)';
                div.style.padding = '15px';
                div.style.borderRadius = '4px';
                div.style.display = 'flex';
                div.style.justifyContent = 'space-between';
                div.style.alignItems = 'center';
                
                div.innerHTML = `
                    <div style="color: var(--primary); font-size: 0.9rem;">
                        Cita: ${new Date(b.date).toLocaleString()}
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-secondary" style="padding: 6px;" onclick="restoreBackup('${b.name}')" title="Restaurar"><i data-feather="refresh-cw" style="width: 14px; height: 14px;"></i></button>
                        <a href="../data/${b.name}" class="btn btn-secondary" style="padding: 6px;" download title="Descargar"><i data-feather="download" style="width: 14px; height: 14px;"></i></a>
                    </div>
                `;
                grid.appendChild(div);
            });
            feather.replace();
        }
    }
    
    window.restoreBackup = async function(name) {
        if(confirm('¿Restaurar ' + name + '?')) {
            showLoader();
            await fetch('api_update.php', {
                method: 'POST',
                body: JSON.stringify({mode: 'system', action: 'restore', data: {name: name}})
            });
            hideLoader();
            alert('Copia de seguridad restaurada.');
            await renderSystem();
        }
    };

    document.getElementById('btn-backup')?.addEventListener('click', async () => {
        showLoader();
        await api.createBackup();
        await renderSystem();
        hideLoader();
    });
    
    document.getElementById('btn-rebuild')?.addEventListener('click', async () => {
        if(confirm("¿Sincronizar y reconstruir base de datos? Esto puede tomar un tiempo.")) {
            showLoader();
            await api.rebuild();
            hideLoader();
            alert("Sincronización iniciada/completada.");
        }
    });

    document.getElementById('btn-cache')?.addEventListener('click', async () => {
        showLoader();
        await fetch('api_update.php', {
            method: 'POST',
            body: JSON.stringify({mode: 'system', action: 'cache'})
        });
        hideLoader();
        alert('Caché reconstruida.');
    });

    document.getElementById('btn-upload')?.addEventListener('click', () => {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.db,.sqlite,.json';
        input.onchange = async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            if (!confirm('¿Subir y restaurar este archivo?')) return;
            
            showLoader();
            const formData = new FormData();
            formData.append('backup', file);
            
            try {
                const res = await fetch('../public.php', {
                    method: 'POST',
                    body: formData
                });
                alert('Archivo subido y procesado. Por favor, reinicie o recargue.');
                await renderSystem();
            } catch (err) {
                alert('Error al subir: ' + err.message);
            }
            hideLoader();
        };
        input.click();
    });

    document.getElementById('btn-log-clear')?.addEventListener('click', async () => {
        showLoader();
        await api.clearLogs();
        hideLoader();
        alert("Logs limpiados");
    });

    // --- Device Modal & Acciones ---
    function openDeviceModal(device = null) {
        document.getElementById('device-form').reset();
        document.getElementById('dev-id').value = '';
        document.getElementById('modal-title').textContent = 'Agregar Dispositivo';

        if (device) {
            document.getElementById('modal-title').textContent = 'Editar Dispositivo';
            document.getElementById('dev-id').value = device.id;
            document.getElementById('dev-name').value = device.name || '';
            document.getElementById('dev-ip').value = device.ip || '';
            document.getElementById('dev-user').value = device.user || '';
            document.getElementById('dev-port').value = device.port || '';
            document.getElementById('dev-pool').value = device.pool || '';
            document.getElementById('dev-pool6').value = device.pool6 || '';
            document.getElementById('dev-pfxLength').value = device.pfxLength || '';
        }

        deviceModal.style.display = 'flex';
    }

    window.editDevice = function(id) {
        const device = devicesCache.find(d => d.id == id);
        if (device) openDeviceModal(device);
    };

    function customConfirm(message, onConfirm) {
        const overlay = document.createElement('div');
        overlay.style.position = 'fixed';
        overlay.style.top = '0'; overlay.style.left = '0';
        overlay.style.width = '100vw'; overlay.style.height = '100vh';
        overlay.style.backgroundColor = 'rgba(0,0,0,0.6)';
        overlay.style.display = 'flex';
        overlay.style.alignItems = 'center'; overlay.style.justifyContent = 'center';
        overlay.style.zIndex = '99999';

        const box = document.createElement('div');
        box.style.backgroundColor = '#1e293b';
        box.style.padding = '24px';
        box.style.borderRadius = '8px';
        box.style.color = '#fff';
        box.style.maxWidth = '400px';
        box.style.textAlign = 'center';
        box.style.boxShadow = '0 10px 25px rgba(0,0,0,0.5)';

        const text = document.createElement('p');
        text.textContent = message;
        text.style.marginBottom = '20px';
        text.style.fontSize = '16px';

        const btnGroup = document.createElement('div');
        btnGroup.style.display = 'flex';
        btnGroup.style.justifyContent = 'center';
        btnGroup.style.gap = '10px';

        const btnCancel = document.createElement('button');
        btnCancel.className = 'btn btn-secondary';
        btnCancel.textContent = 'Cancelar';
        btnCancel.onclick = () => document.body.removeChild(overlay);

        const btnOk = document.createElement('button');
        btnOk.className = 'btn btn-primary';
        btnOk.textContent = 'Aceptar';
        btnOk.onclick = () => {
            document.body.removeChild(overlay);
            onConfirm();
        };

        btnGroup.appendChild(btnCancel);
        btnGroup.appendChild(btnOk);
        box.appendChild(text);
        box.appendChild(btnGroup);
        overlay.appendChild(box);
        document.body.appendChild(overlay);
    }

    window.syncDevice = async (id) => {
        customConfirm('¿Estás seguro de que deseas sincronizar TODAS las cuentas en este Mikrotik? Esto enviará todos los servicios correspondientes.', async () => {
            showLoader();
            const res = await api.send('update', 'system', 'insert', { type: 'device', id: id });
            hideLoader();
            if (!res.error) {
                alert('Sincronización completada con éxito.');
                await loadData(currentView);
            } else {
                alert('Error al sincronizar: ' + res.message);
            }
        });
    };

    window.clearDevice = async (id) => {
        customConfirm('¿Estás seguro de que deseas VACIAR TODAS las cuentas de este Mikrotik? Esto eliminará todos los servicios del equipo.', async () => {
            showLoader();
            const res = await api.send('update', 'system', 'delete', { type: 'device', id: id });
            hideLoader();
            if (!res.error) {
                alert('Cuentas vaciadas con éxito.');
                await loadData(currentView);
            } else {
                alert('Error al vaciar cuentas: ' + res.message);
            }
        });
    };

    window.deleteDevice = async function(id) {
        customConfirm("¿Eliminar este dispositivo?", async () => {
            showLoader();
            const res = await api.send('update', 'devices', 'delete', { id: id });
            hideLoader();
            if (!res.error) await loadData(currentView);
            else alert("Error: " + res.message);
        });
    }

    document.getElementById('save-device-btn').addEventListener('click', async (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(document.getElementById('device-form')).entries());
        if(!data.name || !data.ip || !data.user) return alert("Campos requeridos faltantes.");

        const action = data.id ? 'edit' : 'insert';
        if (!data.id) delete data.id;
        
        // No enviar password vacía si es una edición
        if (action === 'edit' && !data.password) {
            delete data.password;
        }

        showLoader();
        const res = await api.saveDevice(action, data);
        hideLoader();

        if (!res.error) {
            closeModals();
            await loadData(currentView);
        } else alert('Error: ' + res.message);
    });

    // --- Plan Modal ---
    window.editPlan = function(id) {
        const plan = plansCache.find(p => p.id == id);
        if (plan) {
            document.getElementById('plan-id').value = plan.id;
            document.getElementById('plan-ratio').value = plan.ratio || 1;
            
            document.getElementById('plan-uploadOverride').value = plan.uploadOverride || '';
            document.getElementById('plan-downloadOverride').value = plan.downloadOverride || '';
            
            document.getElementById('plan-uploadOverride').placeholder = `UISP: ${plan.uploadSpeed || 0}`;
            document.getElementById('plan-downloadOverride').placeholder = `UISP: ${plan.downloadSpeed || 0}`;
            
            document.getElementById('plan-limitUpload').value = plan.limitUpload == 100 ? 0 : (plan.limitUpload || 0);
            document.getElementById('plan-limitDownload').value = plan.limitDownload == 100 ? 0 : (plan.limitDownload || 0);
            
            document.getElementById('plan-burstUpload').value = plan.burstUpload == -100 ? 0 : (plan.burstUpload || 0);
            document.getElementById('plan-burstDownload').value = plan.burstDownload == -100 ? 0 : (plan.burstDownload || 0);
            
            document.getElementById('plan-threshUpload').value = plan.threshUpload == 100 ? 0 : (plan.threshUpload || 0);
            document.getElementById('plan-threshDownload').value = plan.threshDownload == 100 ? 0 : (plan.threshDownload || 0);
            
            document.getElementById('plan-timeUpload').value = plan.timeUpload || '';
            document.getElementById('plan-timeDownload').value = plan.timeDownload || '';
            
            document.getElementById('plan-priorityUpload').value = plan.priorityUpload || 8;
            document.getElementById('plan-priorityDownload').value = plan.priorityDownload || 8;
            
            planModal.style.display = 'flex';
        }
    }

    document.getElementById('save-plan-btn').addEventListener('click', async (e) => {
        e.preventDefault();
        
        const data = Object.fromEntries(new FormData(document.getElementById('plan-form')).entries());
        // Parsear a enteros porque el backend espera int. Eliminar si están vacíos.
        for (let key in data) {
            if (data[key] === '') {
                delete data[key];
            } else {
                data[key] = parseInt(data[key], 10);
            }
        }
        
        // Convertir de vuelta los 0 a los porcentajes de desactivación
        if (data.limitUpload === 0 || data.limitUpload === undefined || isNaN(data.limitUpload)) data.limitUpload = 100;
        if (data.limitDownload === 0 || data.limitDownload === undefined || isNaN(data.limitDownload)) data.limitDownload = 100;
        
        if (data.burstUpload === 0 || data.burstUpload === undefined || isNaN(data.burstUpload)) data.burstUpload = -100;
        if (data.burstDownload === 0 || data.burstDownload === undefined || isNaN(data.burstDownload)) data.burstDownload = -100;
        
        if (data.threshUpload === 0 || data.threshUpload === undefined || isNaN(data.threshUpload)) data.threshUpload = 100;
        if (data.threshDownload === 0 || data.threshDownload === undefined || isNaN(data.threshDownload)) data.threshDownload = 100;
        
        showLoader();
        const res = await api.savePlan(data);
        hideLoader();
        
        if (!res.error) {
            closeModals();
            await loadData(currentView);
        } else alert('Error: ' + res.message);
    });

    // --- Helpers ---
    function closeModals() {
        deviceModal.style.display = 'none';
        planModal.style.display = 'none';
    }
    
    closeButtons.forEach(btn => btn.addEventListener('click', closeModals));

    function showLoader() { loader.style.display = 'flex'; }
    function hideLoader() { loader.style.display = 'none'; }

    // --- Inicialización ---
    switchView('devices', 'Dispositivos Administrados');
});
