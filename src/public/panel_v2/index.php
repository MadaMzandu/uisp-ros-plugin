<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UISP ROS Plugin Panel</title>
    
    <base href="/crm/_plugins/ros-plugin/public/panel_v2/">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
    
    <!-- Icons (Feather Icons) -->
    <script src="https://unpkg.com/feather-icons"></script>
</head>
<body>

    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>ROS Plugin</h2>
                <p>Real-time Provisioning</p>
            </div>
            <nav class="sidebar-nav">
                <a href="#" class="nav-item active" data-view="devices">
                    <i data-feather="server"></i>
                    <span>Dispositivos</span>
                </a>
                <a href="#" class="nav-item" data-view="plans">
                    <i data-feather="box"></i>
                    <span>Planes</span>
                </a>
                <a href="#" class="nav-item" data-view="config">
                    <i data-feather="settings"></i>
                    <span>Configuración</span>
                </a>
                <a href="#" class="nav-item" data-view="jobs">
                    <i data-feather="list"></i>
                    <span>Trabajos</span>
                </a>
                <a href="#" class="nav-item" data-view="system">
                    <i data-feather="cpu"></i>
                    <span>Sistema</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="topbar">
                <h1 id="page-title">Dispositivos Administrados</h1>
                <div class="topbar-actions" id="topbar-actions">
                    <!-- Dynamic actions like Add Device will go here -->
                </div>
            </header>
            
            <div class="content-area" id="content-area">
                <!-- Loader -->
                <div id="loader" class="loader-container">
                    <div class="spinner"></div>
                </div>
                
                <!-- Dynamic Content Views -->
                <div id="view-devices" class="view-section" style="display: none;">
                    <div class="table-container">
                        <table class="data-table" id="devices-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nombre</th>
                                    <th>IP / Hostname</th>
                                    <th>Usuarios</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Data injected via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- View Plans -->
                <div id="view-plans" class="view-section" style="display: none;">
                    <div class="table-container">
                        <table class="data-table" id="plans-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Plan</th>
                                    <th>Upload Max</th>
                                    <th>Download Max</th>
                                    <th>Upload Burst</th>
                                    <th>Download Burst</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Data injected via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- View Config -->
                <div id="view-config" class="view-section" style="display: none;">
                    <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                        <button class="btn btn-secondary active" id="tab-general">General</button>
                        <button class="btn btn-secondary" id="tab-attrs">Atributos</button>
                    </div>
                    <div class="table-container" style="padding: 20px;">
                        <form id="config-form">
                            <div id="config-fields-container"></div>
                            <div style="margin-top: 20px; text-align: right;">
                                <button class="btn btn-primary" id="save-config-btn">Guardar Configuración</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- View Jobs -->
        <section id="view-jobs" class="view-section" style="display: none;">
            <div class="panel">
                <div class="flex-between" style="margin-bottom: 20px;">
                    <div style="display: flex; gap: 15px; align-items: center;">
                        <h4 style="color: var(--primary); margin: 0;">Trabajos programados</h4>
                        <span class="badge badge-success" id="jobs-total" style="font-size: 0.85rem;">Total: 0</span>
                        <span class="text-muted" id="jobs-selected" style="font-size: 0.85rem;">Selected: 0</span>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button class="btn btn-primary" id="btn-run-jobs" title="Ejecutar seleccionados"><i data-feather="play" style="width: 16px; height: 16px;"></i></button>
                        <button class="btn btn-danger" id="btn-delete-jobs" title="Eliminar seleccionados"><i data-feather="trash-2" style="width: 16px; height: 16px;"></i></button>
                        <input type="checkbox" id="jobs-select-all" style="width: 18px; height: 18px; cursor: pointer;">
                    </div>
                </div>

                <div class="table-container">
                    <table class="table" id="jobs-table" style="width: 100%; border-collapse: collapse;">
                        <tbody>
                            <!-- Data injected via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

                <!-- View System -->
        <section id="view-system" class="view-section" style="display: none;">
            <div class="panel" style="margin-bottom: 20px;">
                <h4 style="color: var(--primary); margin-bottom: 20px;">Utilidades del sistema</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                    <div class="flex-between" style="align-items: flex-start;">
                        <div>
                            <strong style="color: #fff;">Reconstruir datos del complemento</strong>
                            <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 5px;">Esto reconstruirá la caché del complemento y llenará las cuentas que faltan en los enrutadores.</p>
                        </div>
                        <button class="btn btn-primary" id="btn-rebuild">Reconstruir</button>
                    </div>

                    <div class="flex-between" style="align-items: flex-start;">
                        <div>
                            <strong style="color: #fff;">Rebuild Cache</strong>
                            <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 5px;">Cache customer and service information for better performance.</p>
                        </div>
                        <button class="btn btn-primary" id="btn-cache">Cache</button>
                    </div>

                    <div class="flex-between" style="align-items: flex-start;">
                        <div>
                            <strong style="color: #fff;">Restore Backup</strong>
                            <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 5px;">Upload and restore a backfile from this computer.</p>
                        </div>
                        <button class="btn btn-primary" id="btn-upload">Upload</button>
                    </div>

                    <div class="flex-between" style="align-items: flex-start;">
                        <div>
                            <strong style="color: #fff;">Clear Plugin Log</strong>
                            <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 5px;">Clears the log file for the plugin in crm settings.</p>
                        </div>
                        <button class="btn btn-primary" id="btn-log-clear">Clear Log</button>
                    </div>

                    <div class="flex-between" style="grid-column: 1 / -1; border-top: 1px solid var(--border-color); padding-top: 20px; align-items: flex-start;">
                        <div>
                            <strong style="color: #fff;">Descargar o restaurar una copia de seguridad</strong>
                            <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 5px;">Creará una copia de seguridad de la caché del complemento.</p>
                        </div>
                        <button class="btn btn-primary" id="btn-backup">Respaldo</button>
                    </div>
                </div>
            </div>

            <div class="panel">
                <h4 style="color: var(--primary); margin-bottom: 20px;">Restaurar o descargar una copia de seguridad</h4>
                <div id="backups-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                    <!-- Contenido dinámico -->
                </div>
            </div>
        </section>

            </div>
        </main>
    </div>

    <!-- Modals -->
    <div id="device-modal" class="modal-overlay" style="display: none;">
        <div class="modal" style="max-height: 90vh; overflow-y: auto;">
            <div class="modal-header">
                <h3 id="modal-title">Agregar Dispositivo</h3>
                <button class="close-modal"><i data-feather="x"></i></button>
            </div>
            <div class="modal-body">
                <form id="device-form">
                    <input type="hidden" id="dev-id" name="id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Nombre del dispositivo</label>
                            <input type="text" id="dev-name" name="name" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Dirección IP o Hostname</label>
                            <input type="text" id="dev-ip" name="ip" required class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Puerto (API)</label>
                            <input type="number" id="dev-port" name="port" class="form-control" placeholder="Ej: 8728">
                        </div>
                        <div class="form-group">
                            <label>Nombre de usuario</label>
                            <input type="text" id="dev-user" name="user" required class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Contraseña</label>
                            <input type="password" id="dev-password" name="password" class="form-control" placeholder="Dejar en blanco para no cambiar">
                        </div>
                        <div class="form-group">
                            <label>Prefijo IPv6 del cliente</label>
                            <input type="text" id="dev-pfxLength" name="pfxLength" class="form-control" placeholder="Ej: /64">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Pool IPv4 <small style="color: var(--text-muted);">(Separar por comas)</small></label>
                            <input type="text" id="dev-pool" name="pool" class="form-control" placeholder="Ej: 10.88.1.0/24">
                        </div>
                        <div class="form-group">
                            <label>Pool IPv6 <small style="color: var(--text-muted);">(Separar por comas)</small></label>
                            <input type="text" id="dev-pool6" name="pool6" class="form-control" placeholder="Ej: fddc:9057:1bea::/48">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary close-modal">Cancelar</button>
                <button class="btn btn-primary" id="save-device-btn">Guardar</button>
            </div>
        </div>
    </div>

    <!-- Modal Plan Ratio -->
    <div id="plan-modal" class="modal-overlay" style="display: none;">
        <div class="modal" style="max-height: 90vh; overflow-y: auto;">
            <div class="modal-header">
                <h3>Editar Plan</h3>
                <button class="close-modal"><i data-feather="x"></i></button>
            </div>
            <div class="modal-body">
                <form id="plan-form">
                    <input type="hidden" id="plan-id" name="id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Ratio de Contención</label>
                            <input type="number" id="plan-ratio" name="ratio" required class="form-control" min="1">
                        </div>
                    </div>

                    <h4 style="margin-top: 15px; margin-bottom: 10px; color: var(--primary);">Speed Rates</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Max upload rate (Override) <small style="color: var(--text-muted);">Mbps</small></label>
                            <input type="number" id="plan-uploadOverride" name="uploadOverride" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Max download rate (Override) <small style="color: var(--text-muted);">Mbps</small></label>
                            <input type="number" id="plan-downloadOverride" name="downloadOverride" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Limit upload Percent <small style="color: var(--text-muted);">%</small></label>
                            <input type="number" id="plan-limitUpload" name="limitUpload" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Limit download Percent <small style="color: var(--text-muted);">%</small></label>
                            <input type="number" id="plan-limitDownload" name="limitDownload" class="form-control">
                        </div>
                    </div>

                    <h4 style="margin-top: 15px; margin-bottom: 10px; color: var(--primary);">Burst Rates</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Upload burst Percent <small style="color: var(--text-muted);">%</small></label>
                            <input type="number" id="plan-burstUpload" name="burstUpload" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Download burst Percent <small style="color: var(--text-muted);">%</small></label>
                            <input type="number" id="plan-burstDownload" name="burstDownload" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Upload threshold Percent <small style="color: var(--text-muted);">%</small></label>
                            <input type="number" id="plan-threshUpload" name="threshUpload" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Download threshold Percent <small style="color: var(--text-muted);">%</small></label>
                            <input type="number" id="plan-threshDownload" name="threshDownload" class="form-control">
                        </div>
                    </div>

                    <h4 style="margin-top: 15px; margin-bottom: 10px; color: var(--primary);">Burst Timers</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Upload burst time <small style="color: var(--text-muted);">Seconds</small></label>
                            <input type="number" id="plan-timeUpload" name="timeUpload" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Download burst time <small style="color: var(--text-muted);">Seconds</small></label>
                            <input type="number" id="plan-timeDownload" name="timeDownload" class="form-control">
                        </div>
                    </div>

                    <h4 style="margin-top: 15px; margin-bottom: 10px; color: var(--primary);">Queue Priority</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Upload priority</label>
                            <input type="number" id="plan-priorityUpload" name="priorityUpload" class="form-control" min="1" max="8">
                        </div>
                        <div class="form-group">
                            <label>Download priority</label>
                            <input type="number" id="plan-priorityDownload" name="priorityDownload" class="form-control" min="1" max="8">
                        </div>
                    </div>
                    
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary close-modal">Cancelar</button>
                <button class="btn btn-primary" id="save-plan-btn">Guardar</button>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="js/api.js?v=2.0.2"></script>
    <script src="js/app.js?v=2.0.14"></script>
    <script>
        feather.replace();
    </script>
</body>
</html>
