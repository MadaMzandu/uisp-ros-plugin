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
    <script src="js/theme.js?v=2.0.36"></script>
    <link rel="stylesheet" href="css/style.css?v=2.0.36">
</head>
<body>

    <div id="notifications" class="toast-region" aria-live="polite" aria-relevant="additions"></div>

    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>ROS Plugin</h2>
                <p data-i18n="Real-time Provisioning">Real-time Provisioning</p>
            </div>
            <nav class="sidebar-nav">
                <a href="#" class="nav-item active" data-view="devices">
                    <i data-feather="server"></i>
                    <span data-i18n="Dispositivos">Dispositivos</span>
                </a>
                <a href="#" class="nav-item" data-view="plans">
                    <i data-feather="box"></i>
                    <span data-i18n="Planes">Planes</span>
                </a>
                <a href="#" class="nav-item" data-view="config">
                    <i data-feather="settings"></i>
                    <span data-i18n="Configuración">Configuración</span>
                </a>
                <a href="#" class="nav-item" data-view="jobs">
                    <i data-feather="list"></i>
                    <span data-i18n="Trabajos">Trabajos</span>
                </a>
                <a href="#" class="nav-item" data-view="system">
                    <i data-feather="cpu"></i>
                    <span data-i18n="Sistema">Sistema</span>
                </a>
                <a href="#" class="nav-item conflict-nav" data-view="network_conflicts" id="network-conflicts-nav" style="display: none;">
                    <i data-feather="alert-triangle"></i>
                    <span data-i18n="Conflictos de IP">Conflictos de IP</span>
                    <span class="badge badge-warning conflict-nav-count" id="network-conflicts-count"></span>
                </a>
            </nav>
            <div class="preference-controls">
                <label class="preference-control" for="panel-theme">
                    <span data-i18n="appearance.label">Apariencia</span>
                    <select id="panel-theme" class="form-control" aria-label="Apariencia" data-i18n-aria-label="appearance.label">
                        <option value="dark" data-i18n="theme.dark">Oscuro</option>
                        <option value="light" data-i18n="theme.light">Claro</option>
                        <option value="system" data-i18n="theme.system">Sistema</option>
                    </select>
                </label>
                <label class="preference-control" for="panel-language">
                    <span data-i18n="Idioma">Idioma</span>
                    <select id="panel-language" class="form-control" aria-label="Idioma" data-i18n-aria-label="Idioma">
                        <option value="es">Español</option><option value="en">English</option>
                        <option value="pt">Português</option><option value="fr">Français</option>
                        <option value="de">Deutsch</option><option value="it">Italiano</option>
                    </select>
                </label>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="topbar">
                <h1 id="page-title" data-i18n="Dispositivos administrados">Dispositivos administrados</h1>
                <div class="topbar-actions" id="topbar-actions">
                    <!-- Dynamic actions like Add Device will go here -->
                </div>
            </header>

            <aside class="network-warning" id="network-warning" role="status" style="display: none;">
                <i data-feather="alert-triangle"></i>
                <div class="network-warning-copy">
                    <strong id="network-warning-title"></strong>
                    <p data-i18n="Puedes consultar el plugin. Los cambios y la sincronización están pausados hasta corregir las IP duplicadas.">Puedes consultar el plugin. Los cambios y la sincronización están pausados hasta corregir las IP duplicadas.</p>
                </div>
                <button type="button" class="btn btn-secondary" id="btn-view-conflicts" data-i18n="Revisar">Revisar</button>
            </aside>
            
            <div class="content-area" id="content-area">
                <!-- Loader -->
                <div id="loader" class="loader-container">
                    <div class="spinner"></div>
                </div>
                
                <!-- Dynamic Content Views -->
                <section id="view-network_conflicts" class="view-section" style="display: none;">
                    <div class="conflicts-heading">
                        <div>
                            <h2 data-i18n="Asignaciones duplicadas">Asignaciones duplicadas</h2>
                            <p data-i18n="Comprueba cada servicio en UISP. Un cliente archivado o un servicio terminado puede conservar una IP en la caché del plugin.">Comprueba cada servicio en UISP. Un cliente archivado o un servicio terminado puede conservar una IP en la caché del plugin.</p>
                        </div>
                        <div class="conflicts-toolbar">
                            <a class="btn btn-secondary" id="download-network-conflicts" data-i18n="Descargar diagnóstico">Descargar diagnóstico</a>
                            <button type="button" class="btn btn-primary" id="btn-recheck-conflicts" data-i18n="Volver a comprobar">Volver a comprobar</button>
                        </div>
                    </div>
                    <p class="form-note" data-i18n="Los datos guardados pueden estar desactualizados. Verificar en UISP consulta el estado actual; no modifica clientes, servicios ni routers.">Los datos guardados pueden estar desactualizados. Verificar en UISP consulta el estado actual; no modifica clientes, servicios ni routers.</p>
                    <div class="table-container conflicts-table-container">
                        <table class="data-table conflicts-table" id="network-conflicts-table">
                            <thead><tr>
                                <th data-i18n="IP / familia">IP / familia</th>
                                <th data-i18n="Cliente y servicio">Cliente y servicio</th>
                            </tr></thead>
                            <tbody id="network-conflicts-body"></tbody>
                        </table>
                    </div>
                    <p class="form-note conflicts-limit" id="network-conflicts-limit" style="display: none;"></p>
                </section>
                <div id="view-devices" class="view-section" style="display: none;">
                    <div class="list-toolbar">
                        <div class="search-field">
                            <i data-feather="search"></i>
                            <input type="search" id="search-devices" class="form-control" placeholder="Buscar dispositivos..." data-i18n-placeholder="Buscar dispositivos..." aria-label="Buscar dispositivos" data-i18n-aria-label="Buscar dispositivos">
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="devices-table">
                            <thead>
                                <tr>
                                    <th data-sort="id" class="sortable">ID</th>
                                    <th data-sort="name" class="sortable" data-i18n="Nombre">Nombre</th>
                                    <th data-sort="ip" class="sortable" data-i18n="IP / Hostname">IP / Hostname</th>
                                    <th data-sort="users" class="sortable" data-i18n="Usuarios">Usuarios</th>
                                    <th data-sort="status" class="sortable" data-i18n="Estado">Estado</th>
                                    <th data-i18n="Acciones">Acciones</th>
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
                    <div class="list-toolbar">
                        <div class="search-field">
                            <i data-feather="search"></i>
                            <input type="search" id="search-plans" class="form-control" placeholder="Buscar planes..." data-i18n-placeholder="Buscar planes..." aria-label="Buscar planes" data-i18n-aria-label="Buscar planes">
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table" id="plans-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th data-i18n="Plan">Plan</th>
                                    <th data-i18n="Subida (Mbps)">Subida (Mbps)</th>
                                    <th data-i18n="Bajada (Mbps)">Bajada (Mbps)</th>
                                    <th data-i18n="Burst subida (+%)">Burst subida (+%)</th>
                                    <th data-i18n="Burst bajada (+%)">Burst bajada (+%)</th>
                                    <th data-i18n="Acciones">Acciones</th>
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
                    <div class="config-tabs" aria-label="Secciones de configuración" data-i18n-aria-label="Secciones de configuración">
                        <button type="button" class="btn btn-secondary active" id="tab-general" data-i18n="General">General</button>
                        <button type="button" class="btn btn-secondary" id="tab-attrs" data-i18n="Atributos">Atributos</button>
                    </div>
                    <form id="config-form">
                        <div class="config-actions">
                            <span class="config-actions-note" id="config-save-status" aria-live="polite" data-i18n="Guarda para aplicar los cambios.">Guarda para aplicar los cambios.</span>
                            <div class="config-action-buttons">
                                <button type="button" class="btn btn-secondary" id="sync-attrs-btn" style="display: none;" data-i18n="Guardar y crear faltantes">Guardar y crear faltantes</button>
                                <button type="button" class="btn btn-primary" id="save-config-btn"><i data-feather="save"></i><span data-i18n="Guardar cambios">Guardar cambios</span></button>
                            </div>
                        </div>
                        <div id="config-fields-container"></div>
                    </form>
                </div>

                <!-- View Jobs -->
        <section id="view-jobs" class="view-section" style="display: none;">
            <div class="panel">
                <div class="jobs-toolbar">
                    <div class="toolbar-summary">
                        <h4 data-i18n="Trabajos programados">Trabajos programados</h4>
                        <span class="badge badge-success" id="jobs-total" data-i18n="Total: {count}" data-i18n-values='{"count":0}'>Total: 0</span>
                        <span class="text-muted" id="jobs-selected" data-i18n="Seleccionados: {count}" data-i18n-values='{"count":0}'>Seleccionados: 0</span>
                    </div>
                    <div class="toolbar-actions">
                        <button type="button" class="btn btn-secondary" id="btn-refresh-jobs" title="Refrescar la lista de trabajos" data-i18n-title="Refrescar la lista de trabajos"><i data-feather="refresh-cw"></i><span data-i18n="Refrescar">Refrescar</span></button>
                        <label class="select-all-label" for="jobs-refresh-interval"><span data-i18n="Refresco automático">Refresco automático</span></label>
                        <select id="jobs-refresh-interval" class="form-control" style="width: auto;" aria-label="Intervalo de refresco automático" data-i18n-aria-label="Intervalo de refresco automático">
                            <option value="0" data-i18n="Desactivado">Desactivado</option>
                            <option value="5" data-i18n="Cada {seconds} s" data-i18n-values='{"seconds":5}'>Cada 5 s</option>
                            <option value="10" data-i18n="Cada {seconds} s" data-i18n-values='{"seconds":10}'>Cada 10 s</option>
                            <option value="30" data-i18n="Cada {seconds} s" data-i18n-values='{"seconds":30}'>Cada 30 s</option>
                            <option value="60" data-i18n="Cada {seconds} s" data-i18n-values='{"seconds":60}'>Cada 60 s</option>
                        </select>
                        <button type="button" class="btn btn-primary" id="btn-run-jobs" title="Ejecutar seleccionados y sus trabajos anteriores" data-i18n-title="Ejecutar seleccionados y sus trabajos anteriores"><i data-feather="play"></i><span data-i18n="Ejecutar">Ejecutar</span></button>
                        <button type="button" class="btn btn-danger" id="btn-delete-jobs" title="Eliminar seleccionados" data-i18n-title="Eliminar seleccionados"><i data-feather="trash-2"></i><span data-i18n="Eliminar">Eliminar</span></button>
                        <label class="select-all-label"><input type="checkbox" id="jobs-select-all"><span data-i18n="Todos">Todos</span></label>
                    </div>
                </div>
                <p class="text-muted" id="jobs-refresh-status" role="status" aria-live="polite" data-i18n="Sin actualizar.">Sin actualizar.</p>
                <p class="text-muted" data-i18n="Ejecutar procesa también los trabajos anteriores del mismo servicio. Eliminar cancela los pendientes sin cambiar el router y conserva información para una futura sincronización. Los trabajos en ejecución deben terminar primero.">Ejecutar procesa también los trabajos anteriores del mismo servicio. Eliminar cancela los pendientes sin cambiar el router y conserva información para una futura sincronización. Los trabajos en ejecución deben terminar primero.</p>
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
            <div class="system-panel">
                <h4 class="section-title" data-i18n="Información del sistema">Información del sistema</h4>
                <div id="system-info-container" class="system-grid">
                    <!-- Injected via JS -->
                </div>
            </div>

            <div class="system-panel">
                <h4 class="section-title" data-i18n="Utilidades del sistema">Utilidades del sistema</h4>
                <div class="system-utilities">
                    <div class="utility-card">
                        <div>
                            <strong data-i18n="Reconstruir datos">Reconstruir datos</strong>
                            <p data-i18n="Actualiza la caché y vuelve a aplicar las cuentas de servicio en los routers.">Actualiza la caché y vuelve a aplicar las cuentas de servicio en los routers.</p>
                        </div>
                        <button class="btn btn-primary" id="btn-rebuild" data-i18n="Reconstruir">Reconstruir</button>
                    </div>

                    <div class="utility-card">
                        <div>
                            <strong data-i18n="Actualizar caché">Actualizar caché</strong>
                            <p data-i18n="Descarga la información de clientes y servicios desde UISP.">Descarga la información de clientes y servicios desde UISP.</p>
                        </div>
                        <button class="btn btn-secondary" id="btn-cache" data-i18n="Actualizar">Actualizar</button>
                    </div>

                    <div class="utility-card">
                        <div>
                            <strong data-i18n="Restaurar desde archivo">Restaurar desde archivo</strong>
                            <p data-i18n="Selecciona un respaldo del plugin guardado en este equipo.">Selecciona un respaldo del plugin guardado en este equipo.</p>
                        </div>
                        <button class="btn btn-secondary" id="btn-upload" data-i18n="Elegir archivo">Elegir archivo</button>
                    </div>

                    <div class="utility-card">
                        <div>
                            <strong data-i18n="Limpiar registro">Limpiar registro</strong>
                            <p data-i18n="Vacía el registro de eventos del plugin.">Vacía el registro de eventos del plugin.</p>
                        </div>
                        <button class="btn btn-secondary" id="btn-log-clear" data-i18n="Limpiar">Limpiar</button>
                    </div>

                    <div class="utility-card">
                        <div>
                            <strong data-i18n="Crear respaldo">Crear respaldo</strong>
                            <p data-i18n="Guarda una copia de la base de datos del plugin.">Guarda una copia de la base de datos del plugin.</p>
                        </div>
                        <button class="btn btn-primary" id="btn-backup" data-i18n="Crear respaldo">Crear respaldo</button>
                    </div>
                </div>
            </div>

            <div class="system-panel">
                <h4 class="section-title" data-i18n="Respaldos disponibles">Respaldos disponibles</h4>
                <div id="backups-grid" class="backups-grid">
                    <!-- Contenido dinámico -->
                </div>
            </div>
        </section>

            </div>
        </main>
    </div>

    <!-- Modals -->
    <div id="device-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title" style="display: none;">
        <div class="modal">
            <div class="modal-header">
                <h3 id="modal-title" data-i18n="Agregar Dispositivo">Agregar Dispositivo</h3>
                <button type="button" class="close-modal" aria-label="Cerrar" data-i18n-aria-label="Cerrar"><i data-feather="x"></i></button>
            </div>
            <div class="modal-body">
                <form id="device-form">
                    <input type="hidden" id="dev-id" name="id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="dev-name" data-i18n="Nombre del dispositivo">Nombre del dispositivo</label>
                            <input type="text" id="dev-name" name="name" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="dev-ip" data-i18n="Dirección IP o Hostname">Dirección IP o Hostname</label>
                            <input type="text" id="dev-ip" name="ip" required class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="dev-port" data-i18n="Puerto (API)">Puerto (API)</label>
                            <input type="number" id="dev-port" name="port" class="form-control" min="1" max="65535" step="1" placeholder="Predeterminado: 8728">
                        </div>
                        <div class="form-group">
                            <label for="dev-user" data-i18n="Nombre de usuario">Nombre de usuario</label>
                            <input type="text" id="dev-user" name="user" required class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="dev-password" data-i18n="Contraseña">Contraseña</label>
                            <input type="password" id="dev-password" name="password" class="form-control" autocomplete="new-password" placeholder="Dejar en blanco para no cambiar" data-i18n-placeholder="Dejar en blanco para no cambiar">
                        </div>
                        <div class="form-group">
                            <label for="dev-pfxLength" data-i18n="Prefijo IPv6 del cliente">Prefijo IPv6 del cliente</label>
                            <input type="text" id="dev-pfxLength" name="pfxLength" class="form-control" placeholder="Ej: /64" data-i18n-placeholder="Ej: /64">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="dev-pool"><span data-i18n="Pool IPv4">Pool IPv4</span> <small style="color: var(--text-muted);" data-i18n="(Separar por comas)">(Separar por comas)</small></label>
                            <input type="text" id="dev-pool" name="pool" class="form-control" placeholder="Ej: 10.88.1.0/24" data-i18n-placeholder="Ej: 10.88.1.0/24">
                        </div>
                        <div class="form-group">
                            <label for="dev-pool6"><span data-i18n="Pool IPv6">Pool IPv6</span> <small style="color: var(--text-muted);" data-i18n="(Separar por comas)">(Separar por comas)</small></label>
                            <input type="text" id="dev-pool6" name="pool6" class="form-control" placeholder="Ej: fddc:9057:1bea::/48" data-i18n-placeholder="Ej: fddc:9057:1bea::/48">
                        </div>
                    </div>
                    <div class="connection-options">
                        <label class="switch-control"><input type="checkbox" role="switch" id="dev-ssl" name="ssl" value="1"><span class="switch-track" aria-hidden="true"></span><span class="switch-copy" data-i18n="Usar TLS (API SSL)">Usar TLS (API SSL)</span></label>
                        <label class="switch-control"><input type="checkbox" role="switch" id="dev-allowSelfSigned" name="allowSelfSigned" value="1" disabled><span class="switch-track" aria-hidden="true"></span><span class="switch-copy" data-i18n="Aceptar certificado autofirmado">Aceptar certificado autofirmado</span></label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary close-modal" data-i18n="Cancelar">Cancelar</button>
                <button class="btn btn-primary" id="save-device-btn" data-i18n="Guardar">Guardar</button>
            </div>
        </div>
    </div>

    <!-- Orphan Modal -->
    <div id="orphan-modal" class="modal-overlay" style="display: none;">
        <div class="modal">
            <div class="modal-header">
                <h3 data-i18n="Cuentas Huérfanas">Cuentas Huérfanas</h3>
                <button type="button" class="close-modal" aria-label="Cerrar" data-i18n-aria-label="Cerrar"><i data-feather="x"></i></button>
            </div>
            <div class="modal-body">
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 15px;" data-i18n="Estas son las cuentas registradas en el dispositivo que no están asociadas a ningún servicio en UCRM.">Estas son las cuentas registradas en el dispositivo que no están asociadas a ningún servicio en UCRM.</p>
                <div class="table-container">
                    <table class="data-table" id="orphan-table">
                        <thead>
                            <tr>
                                <th data-i18n="Nombre">Nombre</th>
                                <th>MAC</th>
                                <th data-i18n="Dirección (v4/v6)">Dirección (v4/v6)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Injected via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Plan Ratio -->
    <div id="plan-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-label="Editar plan" data-i18n-aria-label="Editar plan" style="display: none;">
        <div class="modal">
            <div class="modal-header">
                <h3 data-i18n="Editar Plan">Editar Plan</h3>
                <button type="button" class="close-modal" aria-label="Cerrar" data-i18n-aria-label="Cerrar"><i data-feather="x"></i></button>
            </div>
            <div class="modal-body">
                <form id="plan-form">
                    <input type="hidden" id="plan-id" name="id">
                    <section id="plan-numeric-warning" class="plan-numeric-warning" role="alert" hidden>
                        <strong data-i18n="Este plan contiene valores inválidos.">Este plan contiene valores inválidos.</strong>
                        <p data-i18n="Revisa los campos indicados. Abrir este editor no modifica los valores guardados.">Revisa los campos indicados. Abrir este editor no modifica los valores guardados.</p>
                        <ul id="plan-numeric-issues"></ul>
                        <p id="plan-numeric-source-hint" data-i18n="La velocidad original no es válida. Corrígela en UISP y vuelve a cargar los planes." hidden>La velocidad original no es válida. Corrígela en UISP y vuelve a cargar los planes.</p>
                        <button type="button" class="btn btn-secondary" id="plan-reset-invalid" data-i18n="Restablecer ajustes inválidos">Restablecer ajustes inválidos</button>
                        <p class="form-note" data-i18n="El restablecimiento sólo cambia este borrador. Pulsa Guardar para aplicarlo.">El restablecimiento sólo cambia este borrador. Pulsa Guardar para aplicarlo.</p>
                    </section>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="plan-ratio" data-i18n="Ratio de contención">Ratio de contención</label>
                            <input type="number" id="plan-ratio" name="ratio" required class="form-control" min="1" step="1" aria-describedby="plan-ratio-help">
                            <p id="plan-ratio-help" class="form-note" data-i18n="Introduce un entero desde 1 para calcular la capacidad compartida de este plan.">Introduce un entero desde 1 para calcular la capacidad compartida de este plan.</p>
                        </div>
                    </div>
                    <section class="burst-templates" aria-labelledby="plan-burst-templates-title">
                        <div class="burst-template-header">
                            <h4 id="plan-burst-templates-title" data-i18n="Plantillas de burst del plugin">Plantillas de burst del plugin</h4>
                            <span id="plan-burst-preset-status" class="burst-preset-status" role="status" data-i18n="Personalizado">Personalizado</span>
                        </div>
                        <p id="plan-burst-preset-description" class="form-note" data-i18n="Ajusta los campos manualmente o elige una plantilla del plugin.">Ajusta los campos manualmente o elige una plantilla del plugin.</p>
                        <p class="form-note" data-i18n="Elige una plantilla para rellenar los campos. Pulsa Guardar para aplicarla.">Elige una plantilla para rellenar los campos. Pulsa Guardar para aplicarla.</p>
                        <div class="burst-template-options">
                            <button type="button" id="plan-preset-none" class="burst-preset-btn" data-burst-preset="none" aria-pressed="false"><strong data-i18n="Sin burst">Sin burst</strong><small data-i18n="Velocidad normal">Velocidad normal</small></button>
                            <button type="button" id="plan-preset-soft" class="burst-preset-btn" data-burst-preset="soft" aria-pressed="false"><strong data-i18n="Suave">Suave</strong><small data-i18n="+25% · umbral 80% · 8 s">+25% · umbral 80% · 8 s</small></button>
                            <button type="button" id="plan-preset-balanced" class="burst-preset-btn" data-burst-preset="balanced" aria-pressed="false"><strong data-i18n="Equilibrado">Equilibrado</strong><small data-i18n="+50% · umbral 75% · 12 s">+50% · umbral 75% · 12 s</small></button>
                            <button type="button" id="plan-preset-fast" class="burst-preset-btn" data-burst-preset="fast" aria-pressed="false"><strong data-i18n="Rápido">Rápido</strong><small data-i18n="+100% · umbral 70% · 10 s">+100% · umbral 70% · 10 s</small></button>
                        </div>
                        <div id="plan-burst-preview" class="burst-preview" aria-live="polite">
                            <div><h5 data-i18n="Subida">Subida</h5><p id="plan-burst-preview-upload" data-i18n="Introduce la velocidad para ver el resultado.">Introduce la velocidad para ver el resultado.</p></div>
                            <div><h5 data-i18n="Bajada">Bajada</h5><p id="plan-burst-preview-download" data-i18n="Introduce la velocidad para ver el resultado.">Introduce la velocidad para ver el resultado.</p></div>
                        </div>
                        <details class="field-help burst-help">
                            <summary data-i18n="Cómo funciona el burst">Cómo funciona el burst</summary>
                            <p data-i18n="Burst es el pico de velocidad; el umbral controla cuándo puede activarse. La ventana es el promedio de tráfico (burst-time), no la duración garantizada del pico. El burst puede activarse cuando ese promedio está por debajo del umbral, si la cola principal y el enlace tienen capacidad disponible. La estimación inicial supone que la conexión venía en reposo.">Burst es el pico de velocidad; el umbral controla cuándo puede activarse. La ventana es el promedio de tráfico (burst-time), no la duración garantizada del pico. El burst puede activarse cuando ese promedio está por debajo del umbral, si la cola principal y el enlace tienen capacidad disponible. La estimación inicial supone que la conexión venía en reposo.</p>
                        </details>
                    </section>
                    <div class="plan-settings-grid">
                        <section class="plan-setting-card">
                            <h4 data-i18n="Velocidad">Velocidad</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="plan-uploadOverride" data-i18n="Subida (Mbps)">Subida (Mbps)</label>
                                    <input type="number" id="plan-uploadOverride" name="uploadOverride" class="form-control" min="0" step="any">
                                </div>
                                <div class="form-group">
                                    <label for="plan-downloadOverride" data-i18n="Bajada (Mbps)">Bajada (Mbps)</label>
                                    <input type="number" id="plan-downloadOverride" name="downloadOverride" class="form-control" min="0" step="any">
                                </div>
                            </div>
                            <p class="form-note" data-i18n="Vacío: usar la velocidad de UISP.">Vacío: usar la velocidad de UISP.</p>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="plan-limitUpload" data-i18n="Límite subida (%)">Límite subida (%)</label>
                                    <input type="number" id="plan-limitUpload" name="limitUpload" class="form-control" min="0" max="100" step="any">
                                </div>
                                <div class="form-group">
                                    <label for="plan-limitDownload" data-i18n="Límite bajada (%)">Límite bajada (%)</label>
                                    <input type="number" id="plan-limitDownload" name="limitDownload" class="form-control" min="0" max="100" step="any">
                                </div>
                            </div>
                        </section>
                        <section class="plan-setting-card">
                            <h4>Burst</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="plan-burstUpload"><span data-i18n="Subida">Subida</span><small class="field-label-detail" data-i18n="Incremento de burst (%)">Incremento de burst (%)</small></label>
                                    <input type="number" id="plan-burstUpload" name="burstUpload" class="form-control" min="0" step="any">
                                </div>
                                <div class="form-group">
                                    <label for="plan-burstDownload"><span data-i18n="Bajada">Bajada</span><small class="field-label-detail" data-i18n="Incremento de burst (%)">Incremento de burst (%)</small></label>
                                    <input type="number" id="plan-burstDownload" name="burstDownload" class="form-control" min="0" step="any">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="plan-threshUpload"><span data-i18n="Subida">Subida</span><small class="field-label-detail" data-i18n="Reducción del umbral (%)">Reducción del umbral (%)</small></label>
                                    <input type="number" id="plan-threshUpload" name="threshUpload" class="form-control" min="0" max="100" step="any">
                                </div>
                                <div class="form-group">
                                    <label for="plan-threshDownload"><span data-i18n="Bajada">Bajada</span><small class="field-label-detail" data-i18n="Reducción del umbral (%)">Reducción del umbral (%)</small></label>
                                    <input type="number" id="plan-threshDownload" name="threshDownload" class="form-control" min="0" max="100" step="any">
                                </div>
                            </div>
                        </section>
                        <section class="plan-setting-card">
                            <h4 data-i18n="Promedio de tráfico">Promedio de tráfico</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="plan-timeUpload"><span data-i18n="Subida">Subida</span><small class="field-label-detail" data-i18n="Ventana de promedio (s)">Ventana de promedio (s)</small></label>
                                    <input type="number" id="plan-timeUpload" name="timeUpload" class="form-control" min="0" step="any">
                                </div>
                                <div class="form-group">
                                    <label for="plan-timeDownload"><span data-i18n="Bajada">Bajada</span><small class="field-label-detail" data-i18n="Ventana de promedio (s)">Ventana de promedio (s)</small></label>
                                    <input type="number" id="plan-timeDownload" name="timeDownload" class="form-control" min="0" step="any">
                                </div>
                            </div>
                        </section>
                        <section class="plan-setting-card">
                            <h4 data-i18n="Prioridad de cola">Prioridad de cola</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="plan-priorityUpload" data-i18n="Subida (1–8)">Subida (1–8)</label>
                                    <input type="number" id="plan-priorityUpload" name="priorityUpload" class="form-control" min="1" max="8">
                                </div>
                                <div class="form-group">
                                    <label for="plan-priorityDownload" data-i18n="Bajada (1–8)">Bajada (1–8)</label>
                                    <input type="number" id="plan-priorityDownload" name="priorityDownload" class="form-control" min="1" max="8">
                                </div>
                            </div>
                        </section>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary close-modal" data-i18n="Cancelar">Cancelar</button>
                <button class="btn btn-primary" id="save-plan-btn" data-i18n="Guardar">Guardar</button>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="js/i18n.js?v=2.0.36"></script>
    <script src="js/api.js?v=2.0.36"></script>
    <script src="js/app.js?v=2.0.36"></script>
</body>
</html>
