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
    <script src="js/theme.js?v=2.0.52"></script>
    <link rel="stylesheet" href="css/style.css?v=2.0.52">
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
                    <span class="badge badge-warning conflict-nav-count" id="jobs-pending-count" style="display: none;"></span>
                </a>
                <a href="#" class="nav-item" data-view="system">
                    <i data-feather="cpu"></i>
                    <span data-i18n="Sistema">Sistema</span>
                </a>
                <a href="#" class="nav-item" data-view="diagnostics">
                    <i data-feather="search"></i>
                    <span data-i18n="Diagnóstico por servicio">Diagnóstico por servicio</span>
                </a>
                <a href="#" class="nav-item conflict-nav" data-view="network_conflicts" id="network-conflicts-nav">
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

            <div class="warning-controls" id="warning-controls" style="display: none;">
                <button type="button" class="btn btn-secondary" id="restore-warnings" data-i18n="Mostrar avisos">Mostrar avisos</button>
            </div>

            <aside class="network-warning" id="network-warning" role="status" style="display: none;">
                <i data-feather="alert-triangle"></i>
                <div class="network-warning-copy">
                    <strong id="network-warning-title"></strong>
                    <p id="network-warning-note" data-i18n="Puedes consultar el plugin. Los cambios y la sincronización están pausados hasta corregir las IP duplicadas.">Puedes consultar el plugin. Los cambios y la sincronización están pausados hasta corregir las IP duplicadas.</p>
                </div>
                <button type="button" class="btn btn-secondary" id="btn-view-conflicts" data-i18n="Revisar">Revisar</button>
                <button type="button" class="warning-dismiss" id="dismiss-network-warning" aria-label="Cerrar aviso" title="Cerrar aviso" data-i18n-aria-label="Cerrar aviso" data-i18n-title="Cerrar aviso"><i data-feather="x"></i></button>
            </aside>

            <aside class="network-warning crm-network-warning" id="crm-network-warning" role="status" style="display: none;">
                <i data-feather="alert-triangle"></i>
                <div class="network-warning-copy">
                    <strong id="crm-network-warning-title"></strong>
                    <p id="crm-network-warning-note"></p>
                    <small id="crm-network-warning-freshness"></small>
                </div>
                <button type="button" class="btn btn-secondary" id="btn-view-crm-conflicts" data-i18n="Revisar">Revisar</button>
                <button type="button" class="warning-dismiss" id="dismiss-crm-network-warning" aria-label="Cerrar aviso" title="Cerrar aviso" data-i18n-aria-label="Cerrar aviso" data-i18n-title="Cerrar aviso"><i data-feather="x"></i></button>
            </aside>
            <aside class="network-warning jobs-warning" id="jobs-pending-warning" role="status" style="display: none;">
                <i data-feather="alert-triangle"></i>
                <div class="network-warning-copy">
                    <strong id="jobs-pending-title"></strong>
                    <p id="jobs-pending-note"></p>
                    <small id="jobs-pending-freshness"></small>
                </div>
                <button type="button" class="btn btn-secondary" id="btn-view-pending-jobs" data-i18n="Revisar">Revisar</button>
                <button type="button" class="warning-dismiss" id="dismiss-jobs-pending-warning" aria-label="Cerrar aviso" title="Cerrar aviso" data-i18n-aria-label="Cerrar aviso" data-i18n-title="Cerrar aviso"><i data-feather="x"></i></button>
            </aside>
            
            <div class="content-area" id="content-area">
                <!-- Loader -->
                <div id="loader" class="loader-container">
                    <div class="spinner"></div>
                </div>
                
                <!-- Dynamic Content Views -->
                <section id="view-network_conflicts" class="view-section" style="display: none;">
                    <section class="invalid-reservations-pane" id="invalid-reservations-pane">
                        <div class="conflicts-heading">
                            <div><h2 data-i18n="Reservas inválidas">Reservas inválidas</h2><p data-i18n="Compara las reservas locales inválidas con UISP y revisa una corrección con respaldo. Los servicios actuales y los routers se conservan.">Compara las reservas locales inválidas con UISP y revisa una corrección con respaldo. Los servicios actuales y los routers se conservan.</p></div>
                            <div class="conflicts-toolbar"><button type="button" class="btn btn-secondary" id="refresh-invalid-reservations" data-i18n="Refrescar">Refrescar</button><button type="button" class="btn btn-primary" id="reload-after-reservation-list-repair" style="display: none;" data-i18n="Recargar panel">Recargar panel</button></div>
                        </div>
                        <p id="invalid-reservations-status" class="form-note" role="status" aria-live="polite"></p>
                        <div id="invalid-reservations-list" class="repair-list"></div>
                    </section>
                    <div class="crm-conflicts-pane" id="crm-network-conflicts-pane">
                        <div class="conflicts-heading">
                            <div>
                                <h2 data-i18n="IP duplicadas en CRM">IP duplicadas en CRM</h2>
                                <p data-i18n="Revisión automática de las IP de los servicios en CRM. Este aviso no pausa los trabajos de otros clientes.">Revisión automática de las IP de los servicios en CRM. Este aviso no pausa los trabajos de otros clientes.</p>
                            </div>
                            <span class="badge badge-neutral" data-i18n="Monitoreo automático">Monitoreo automático</span>
                        </div>
                        <p class="crm-monitor-status" id="crm-network-conflicts-status" role="status" aria-live="polite"></p>
                        <p class="form-note crm-monitor-freshness" id="crm-network-conflicts-freshness"></p>
                        <div class="crm-address-issues" id="crm-network-address-issues" style="display: none;">
                            <h3 data-i18n="Direcciones del CRM sin comprobar">Direcciones del CRM sin comprobar</h3>
                            <p class="form-note" data-i18n="La revisión incluye direcciones sin comprobar; pueden existir otros conflictos.">La revisión incluye direcciones sin comprobar; pueden existir otros conflictos.</p>
                            <div id="crm-network-address-issues-list"></div>
                            <p class="form-note conflicts-limit" id="crm-network-address-issues-limit" style="display: none;"></p>
                        </div>
                        <div id="crm-network-history" class="crm-network-history" style="display: none;">
                            <h3 data-i18n="Asignaciones históricas revisadas">Asignaciones históricas revisadas</h3>
                            <p class="form-note" data-i18n="Estas asignaciones pertenecen a servicios terminados y conservan su historial en el CRM. La resolución se revisa automáticamente si cambia la IP o se reactiva el servicio.">Estas asignaciones pertenecen a servicios terminados y conservan su historial en el CRM. La resolución se revisa automáticamente si cambia la IP o se reactiva el servicio.</p>
                            <p class="form-note" data-i18n="Revisada significa excluida de los conflictos actuales mientras coincida la comprobación; la IP permanece en UISP. Esta resolución no limpia recursos del router ni recuperaciones de trabajos.">Revisada significa excluida de los conflictos actuales mientras coincida la comprobación; la IP permanece en UISP. Esta resolución no limpia recursos del router ni recuperaciones de trabajos.</p>
                            <div id="crm-network-history-list"></div>
                            <p id="crm-network-history-limit" class="form-note" style="display: none;"></p>
                        </div>
                        <div class="table-container conflicts-table-container">
                            <table class="data-table conflicts-table" id="crm-network-conflicts-table">
                                <thead><tr>
                                    <th data-i18n="IP / familia">IP / familia</th>
                                    <th data-i18n="Cliente y servicio">Cliente y servicio</th>
                                </tr></thead>
                                <tbody id="crm-network-conflicts-body"></tbody>
                            </table>
                        </div>
                        <p class="form-note conflicts-limit" id="crm-network-conflicts-limit" style="display: none;"></p>
                        <div id="crm-network-resolved" class="crm-network-resolved" style="display: none;">
                            <h3 data-i18n="Conflictos resueltos">Conflictos resueltos</h3>
                            <p id="crm-network-resolved-summary" role="status" aria-live="polite"></p>
                            <p class="form-note" data-i18n="Estos conflictos ya no cuentan como pendientes. La próxima revisión automática comprobará si la situación cambió.">Estos conflictos ya no cuentan como pendientes. La próxima revisión automática comprobará si la situación cambió.</p>
                            <div class="table-container conflicts-table-container">
                                <table class="data-table conflicts-table">
                                    <thead><tr><th data-i18n="IP / familia">IP / familia</th><th data-i18n="Cliente y servicio">Cliente y servicio</th></tr></thead>
                                    <tbody id="crm-network-resolved-body"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div id="stored-network-conflicts-pane" class="stored-conflicts-pane" style="display: none;">
                    <div class="conflicts-heading">
                        <div>
                            <h2 data-i18n="Asignaciones duplicadas">Asignaciones duplicadas</h2>
                            <p data-i18n="Las asignaciones guardadas pueden incluir servicios antiguos que ya no aparecen en UISP.">Las asignaciones guardadas pueden incluir servicios antiguos que ya no aparecen en UISP.</p>
                        </div>
                        <div class="conflicts-toolbar">
                            <a class="btn btn-secondary" id="download-network-conflicts" data-i18n="Descargar diagnóstico">Descargar diagnóstico</a>
                            <button type="button" class="btn btn-primary" id="btn-recheck-conflicts" data-i18n="Volver a comprobar">Volver a comprobar</button>
                        </div>
                    </div>
                    <p class="form-note" data-i18n="Los datos guardados pueden estar desactualizados. Verificar en UISP consulta el estado actual; no modifica clientes, servicios ni routers.">Los datos guardados pueden estar desactualizados. Verificar en UISP consulta el estado actual; no modifica clientes, servicios ni routers.</p>
                    <p class="form-note" data-i18n="El archivo del cliente y el estado del servicio son independientes. Verificar cliente consulta sólo el cliente en UISP, incluso si su servicio ya no existe.">El archivo del cliente y el estado del servicio son independientes. Verificar cliente consulta sólo el cliente en UISP, incluso si su servicio ya no existe.</p>
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
                    </div>
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
                
                <section class="system-panel" id="pool-capacity-section" data-parent-view="devices" style="display: none;">
                    <div class="conflicts-heading"><div><h3 data-i18n="Capacidad de IP por Site">Capacidad de IP por Site</h3><p class="form-note" data-i18n="Direcciones y prefijos disponibles según las reservas del plugin. Los pools compartidos no se suman entre Sites.">Direcciones y prefijos disponibles según las reservas del plugin. Los pools compartidos no se suman entre Sites.</p></div><button type="button" class="btn btn-secondary" id="refresh-pool-capacity" data-i18n="Refrescar">Refrescar</button></div>
                    <p class="form-note" id="pool-capacity-status" role="status" aria-live="polite"></p><div class="operations-result-grid" id="pool-capacity-list"></div>
                </section>
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

                <section id="view-diagnostics" class="view-section" style="display: none;">
                    <section class="system-panel">
                        <h2 data-i18n="Diagnóstico por servicio">Diagnóstico por servicio</h2>
                        <p class="form-note" data-i18n="Compara UISP, el estado previsto del plugin y la cuenta del router sin modificar el servicio.">Compara UISP, el estado previsto del plugin y la cuenta del router sin modificar el servicio.</p>
                        <form id="service-diagnostic-search" class="operations-search">
                            <label for="service-diagnostic-query" data-i18n="Servicio, cliente, usuario o IP">Servicio, cliente, usuario o IP</label>
                            <div class="operations-search-controls"><input id="service-diagnostic-query" type="search" class="form-control" maxlength="160" autocomplete="off" required><button type="submit" class="btn btn-primary" data-i18n="Buscar">Buscar</button></div>
                        </form>
                        <p id="service-diagnostic-status" class="form-note" role="status" aria-live="polite"></p>
                        <div id="service-diagnostic-results" class="operations-search-results"></div>
                    </section>
                    <section id="service-diagnostic-detail" class="system-panel" style="display: none;" aria-live="polite" tabindex="-1"></section>
                </section>

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
            <section class="job-recovery-section" id="job-recovery-section" aria-labelledby="job-recovery-heading" tabindex="-1">
                <div class="conflicts-heading">
                    <div><h3 id="job-recovery-heading" data-i18n="Servicios en recuperación">Servicios en recuperación</h3><p data-i18n="Son registros conservados de trabajos cancelados. No se ejecutan automáticamente. Revisa cada servicio antes de resolver su recuperación.">Son registros conservados de trabajos cancelados. No se ejecutan automáticamente. Revisa cada servicio antes de resolver su recuperación.</p></div>
                    <button type="button" class="btn btn-secondary" id="refresh-job-recoveries" data-i18n="Refrescar recuperaciones">Refrescar recuperaciones</button>
                </div>
                <p class="form-note" id="job-recovery-list-status" role="status" aria-live="polite" data-i18n="Sin actualizar.">Sin actualizar.</p>
                <div class="repair-list" id="job-recovery-list"></div>
                <div id="job-retained-section" style="display: none;">
                    <h4 data-i18n="Revisiones cerradas con recursos conservados">Revisiones cerradas con recursos conservados</h4>
                    <p class="form-note" data-i18n="Estos registros siguen protegiendo sus recursos e IP. No son trabajos pendientes ni se ejecutarán como limpiezas canceladas.">Estos registros siguen protegiendo sus recursos e IP. No son trabajos pendientes ni se ejecutarán como limpiezas canceladas.</p>
                    <div class="repair-list" id="job-retained-list"></div>
                </div>
            </section>
            <section class="system-panel operations-results" aria-labelledby="provisioning-results-title">
                <div class="conflicts-heading"><div><h3 id="provisioning-results-title" data-i18n="Resultados recientes">Resultados recientes</h3><p class="form-note" data-i18n="La confirmación consulta el router. Verificar de nuevo no repite los cambios ni las reconexiones.">La confirmación consulta el router. Verificar de nuevo no repite los cambios ni las reconexiones.</p></div><button type="button" id="refresh-provisioning-results" class="btn btn-secondary" data-i18n="Refrescar">Refrescar</button></div>
                <p id="provisioning-results-status" class="form-note" role="status" aria-live="polite"></p>
                <div id="provisioning-results-list" class="operations-result-grid"></div>
            </section>
        </section>

        <!-- View System -->
        <section id="view-system" class="view-section" style="display: none;">
            <section class="system-panel" aria-labelledby="service-reconciliation-heading">
                <div class="conflicts-heading"><div><h3 id="service-reconciliation-heading" data-i18n="Revisión automática de diferencias">Revisión automática de diferencias</h3><p class="form-note" data-i18n="Revisa servicios gradualmente. Las correcciones comprobadas pasan a la cola; los casos ambiguos quedan para revisión individual.">Revisa servicios gradualmente. Las correcciones comprobadas pasan a la cola; los casos ambiguos quedan para revisión individual.</p></div><button type="button" class="btn btn-secondary" id="refresh-service-reconciliation" data-i18n="Refrescar">Refrescar</button></div>
                <div class="operations-actions"><label><input type="checkbox" id="service-reconciliation-enabled"><span data-i18n="Revisión automática">Revisión automática</span></label><label><input type="checkbox" id="service-reconciliation-auto"><span data-i18n="Corregir casos comprobados">Corregir casos comprobados</span></label><button type="button" class="btn btn-secondary" id="save-service-reconciliation" data-i18n="Guardar cambios">Guardar cambios</button></div>
                <p class="form-note" id="service-reconciliation-status" role="status" aria-live="polite"></p><div id="service-reconciliation-list" class="operations-result-grid"></div>
            </section>
            <section class="system-panel" aria-labelledby="full-backups-heading">
                <div class="conflicts-heading"><div><h3 id="full-backups-heading" data-i18n="Respaldos completos">Respaldos completos</h3><p class="form-note" data-i18n="Incluyen configuración, credenciales del plugin, cola, estado aplicado y recuperaciones. La integración con este servidor UISP se conserva al restaurar.">Incluyen configuración, credenciales del plugin, cola, estado aplicado y recuperaciones. La integración con este servidor UISP se conserva al restaurar.</p></div><div class="operations-actions"><button type="button" class="btn btn-secondary" id="refresh-full-backups" data-i18n="Refrescar">Refrescar</button><button type="button" class="btn btn-primary" id="create-full-backup" data-i18n="Crear respaldo completo">Crear respaldo completo</button><button type="button" class="btn btn-secondary" id="upload-full-backup" data-i18n="Revisar archivo ZIP">Revisar archivo ZIP</button><input type="file" id="full-backup-file" accept=".zip,application/zip" hidden></div></div>
                <p class="form-note" id="full-backup-status" role="status" aria-live="polite"></p><div class="operations-result-grid" id="full-backup-list"></div>
            </section>
            <section class="system-panel" aria-labelledby="automation-health-title">
                <div class="conflicts-heading"><div><h2 id="automation-health-title" data-i18n="Salud de la automatización">Salud de la automatización</h2><p class="form-note" data-i18n="Actividad observada del proceso automático, webhooks y cola. Una ejecución manual no cuenta como ejecución programada.">Actividad observada del proceso automático, webhooks y cola. Una ejecución manual no cuenta como ejecución programada.</p></div><button type="button" id="refresh-automation-health" class="btn btn-secondary" data-i18n="Refrescar">Refrescar</button></div>
                <p id="automation-health-status" class="form-note" role="status" aria-live="polite"></p>
                <div id="automation-health-content" class="automation-health-grid"></div>
            </section>
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
    <div class="modal-overlay" id="sync-preview-modal" role="dialog" aria-modal="true" aria-labelledby="sync-preview-heading" style="display: none;">
        <div class="modal workflow-dialog"><div class="modal-header"><h3 id="sync-preview-heading" data-i18n="Vista previa de sincronización">Vista previa de sincronización</h3><button type="button" class="close-modal" aria-label="Cerrar" data-i18n-aria-label="Cerrar"><i data-feather="x"></i></button></div><div class="modal-body">
            <p class="form-note" data-i18n="Sólo revisar consulta UISP y RouterOS. Aplicar diferencias programa cambios comprobados. Forzar reconexión incluye las cuentas verificadas aunque no tengan diferencias.">Sólo revisar consulta UISP y RouterOS. Aplicar diferencias programa cambios comprobados. Forzar reconexión incluye las cuentas verificadas aunque no tengan diferencias.</p>
            <p class="form-note" data-i18n="La comparación incluye los campos observables. La contraseña de RouterOS queda sin comprobar.">La comparación incluye los campos observables. La contraseña de RouterOS queda sin comprobar.</p>
            <p id="sync-preview-status" class="form-note" role="status" aria-live="polite"></p><div class="operations-result-grid" id="sync-preview-list"></div>
        </div><div class="modal-footer"><button type="button" class="btn btn-secondary" id="sync-review-only" data-i18n="Sólo revisar">Sólo revisar</button><button type="button" class="btn btn-secondary" id="sync-preview-reconnect" disabled data-i18n="Forzar reconexión">Forzar reconexión</button><button type="button" class="btn btn-primary" id="sync-preview-apply" disabled data-i18n="Aplicar diferencias">Aplicar diferencias</button></div></div>
    </div>
    <div class="modal-overlay" id="full-restore-modal" role="dialog" aria-modal="true" aria-labelledby="full-restore-heading" style="display: none;">
        <div class="modal workflow-dialog"><div class="modal-header"><h3 id="full-restore-heading" data-i18n="Revisar restauración completa">Revisar restauración completa</h3><button type="button" class="close-modal" aria-label="Cerrar" data-i18n-aria-label="Cerrar"><i data-feather="x"></i></button></div><div class="modal-body">
            <p class="form-note" data-i18n="La restauración sustituye los datos locales y guarda antes un respaldo. Los trabajos restaurados podrán ejecutarse en la siguiente pasada automática.">La restauración sustituye los datos locales y guarda antes un respaldo. Los trabajos restaurados podrán ejecutarse en la siguiente pasada automática.</p><p id="full-restore-status" class="form-note" role="status" aria-live="polite"></p><div id="full-restore-summary" class="workflow-grid"></div>
            <label class="workflow-confirm"><input type="checkbox" id="full-restore-confirm"><span data-i18n="Confirmo restaurar también la cola y el historial de este respaldo.">Confirmo restaurar también la cola y el historial de este respaldo.</span></label>
        </div><div class="modal-footer"><button type="button" class="btn btn-secondary close-modal" data-i18n="Cerrar">Cerrar</button><button type="button" class="btn btn-secondary" id="reload-after-full-restore" style="display: none;" data-i18n="Recargar panel">Recargar panel</button><button type="button" class="btn btn-primary" id="apply-full-restore" disabled data-i18n="Restaurar respaldo completo">Restaurar respaldo completo</button></div></div>
    </div>
    <div id="job-recovery-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="job-recovery-title" style="display: none;">
        <div class="modal crm-ip-edit-dialog">
            <div class="modal-header"><h3 id="job-recovery-title" data-i18n="Revisar recuperación">Revisar recuperación</h3><button type="button" class="close-modal" aria-label="Cerrar" data-i18n-aria-label="Cerrar"><i data-feather="x"></i></button></div>
            <div class="modal-body">
                <p id="job-recovery-intro" class="form-note" data-i18n="Se comprobarán UISP y los registros actuales. Si hace falta, se consultará el router sin modificarlo. Sólo se retirarán registros locales redundantes o cuyos recursos ya no existan; los servicios actuales se conservarán.">Se comprobarán UISP y los registros actuales. Si hace falta, se consultará el router sin modificarlo. Sólo se retirarán registros locales redundantes o cuyos recursos ya no existan; los servicios actuales se conservarán.</p>
                <p id="job-recovery-status" class="form-note" role="status" aria-live="polite"></p>
                <div id="job-recovery-comparison" class="repair-list"></div>
                <section id="job-recovery-result" class="crm-ip-result" role="status" aria-live="polite" tabindex="-1" style="display: none;"></section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary close-modal" data-i18n="Cerrar">Cerrar</button>
                <button type="button" class="btn btn-secondary" id="recheck-job-recovery" data-i18n="Verificar de nuevo">Verificar de nuevo</button>
                <button type="button" class="btn btn-secondary" id="preserve-job-recovery" style="display: none;" data-i18n="Conservar recursos y cerrar revisión">Conservar recursos y cerrar revisión</button>
                <button type="button" class="btn btn-danger" id="discard-job-recovery" style="display: none;" data-i18n="Descartar registro local">Descartar registro local</button>
                <button type="button" class="btn btn-primary" id="apply-job-recovery" disabled data-i18n="Resolver recuperación">Resolver recuperación</button>
            </div>
        </div>
    </div>

    <div id="network-repair-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="network-repair-title" style="display: none;">
        <div class="modal network-cleanup-dialog">
            <div class="modal-header"><h3 id="network-repair-title" data-i18n="Corregir reserva inválida">Corregir reserva inválida</h3><button type="button" class="close-modal" aria-label="Cerrar" data-i18n-aria-label="Cerrar"><i data-feather="x"></i></button></div>
            <div class="modal-body">
                <p class="form-note" data-i18n="Sólo se corregirán las reservas locales inválidas revisadas. Se creará un respaldo; UISP, los routers y las demás asignaciones no se modificarán.">Sólo se corregirán las reservas locales inválidas revisadas. Se creará un respaldo; UISP, los routers y las demás asignaciones no se modificarán.</p>
                <p id="network-repair-status" class="form-note" role="status" aria-live="polite"></p>
                <div id="network-repair-comparison" class="repair-list"></div>
                <section id="network-repair-result" class="crm-ip-result" role="status" aria-live="polite" tabindex="-1" style="display: none;"></section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary close-modal" data-i18n="Cerrar">Cerrar</button>
                <button type="button" class="btn btn-secondary" id="recheck-network-repair" data-i18n="Comparar con UISP">Comparar con UISP</button>
                <button type="button" class="btn btn-primary" id="apply-network-repair" disabled data-i18n="Corregir reserva local">Corregir reserva local</button>
                <button type="button" class="btn btn-primary" id="reload-after-repair" style="display: none;" data-i18n="Recargar panel">Recargar panel</button>
            </div>
        </div>
    </div>
    <div id="crm-ip-edit-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="crm-ip-edit-title" style="display: none;">
        <div class="modal crm-ip-edit-dialog">
            <div class="modal-header">
                <h3 id="crm-ip-edit-title" data-i18n="Editar IP en CRM">Editar IP en CRM</h3>
                <button type="button" class="close-modal" aria-label="Cerrar" data-i18n-aria-label="Cerrar"><i data-feather="x"></i></button>
            </div>
            <div class="modal-body">
                <div id="crm-ip-target" class="conflict-owner-meta crm-ip-target"></div>
                <div id="crm-ip-form">
                <p id="crm-ip-edit-note" class="form-note" data-i18n="Sólo se modificará este atributo de IP en el CRM. Las demás IP y los demás datos del servicio se conservarán.">Sólo se modificará este atributo de IP en el CRM. Las demás IP y los demás datos del servicio se conservarán.</p>
                <div class="crm-ip-current"><span data-i18n="IP actual en UISP">IP actual en UISP</span><strong id="crm-ip-current"></strong></div>
                <div id="crm-ip-operation-group" class="form-group">
                    <label for="crm-ip-operation" data-i18n="Acción">Acción</label>
                    <select id="crm-ip-operation" class="form-control">
                        <option value="replace" data-i18n="Cambiar IP">Cambiar IP</option>
                        <option value="remove" data-i18n="Quitar IP de este servicio">Quitar IP de este servicio</option>
                    </select>
                </div>
                <div id="crm-ip-address-group" class="form-group">
                    <label for="crm-ip-address" data-i18n="Nueva IP">Nueva IP</label>
                    <input type="text" id="crm-ip-address" maxlength="128" class="form-control" autocomplete="off" spellcheck="false">
                </div>
                <p id="crm-ip-edit-status" class="form-note" role="status" aria-live="polite"></p>
                <div id="crm-ip-review" class="crm-ip-review" style="display: none;"></div>
                </div>
                <section id="crm-ip-result" class="crm-ip-result" role="status" aria-live="polite" tabindex="-1" style="display: none;">
                    <h4 id="crm-ip-result-title"></h4>
                    <p id="crm-ip-result-message"></p>
                    <div id="crm-ip-result-summary"></div>
                    <p id="crm-ip-result-checked" class="form-note"></p>
                    <p id="crm-ip-result-detail" class="form-note"></p>
                    <p id="crm-ip-result-history-note" class="form-note" style="display: none;" data-i18n="UISP confirma que el servicio está terminado. Puedes revisar su asignación histórica sin cambiar el historial del CRM ni los routers.">UISP confirma que el servicio está terminado. Puedes revisar su asignación histórica sin cambiar el historial del CRM ni los routers.</p>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary close-modal" data-i18n="Cerrar">Cerrar</button>
                <button type="button" class="btn btn-secondary" id="review-crm-ip-change" disabled data-i18n="Revisar cambio">Revisar cambio</button>
                <button type="button" class="btn btn-primary" id="apply-crm-ip-change" disabled data-i18n="Aplicar cambio en CRM">Aplicar cambio en CRM</button>
                <button type="button" class="btn btn-secondary" id="verify-crm-ip-result" style="display: none;" data-i18n="Verificar nuevamente">Verificar nuevamente</button>
                <button type="button" class="btn btn-secondary" id="resolve-crm-ip-history" style="display: none;" data-i18n="Resolver asignación histórica">Resolver asignación histórica</button>
                <button type="button" class="btn btn-secondary" id="cleanup-crm-ip-result" style="display: none;" data-i18n="Revisar limpieza">Revisar limpieza</button>
            </div>
        </div>
    </div>
    <div id="network-cleanup-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="network-cleanup-title" style="display: none;">
        <div class="modal network-cleanup-dialog">
            <div class="modal-header">
                <h3 id="network-cleanup-title" data-i18n="Revisar limpieza">Revisar limpieza</h3>
                <button type="button" class="close-modal" aria-label="Cerrar" data-i18n-aria-label="Cerrar"><i data-feather="x"></i></button>
            </div>
            <div class="modal-body">
                <p class="form-note" data-i18n="Se creará un respaldo antes de eliminar sólo las asignaciones locales seleccionadas. UISP y los routers no se modificarán.">Se creará un respaldo antes de eliminar sólo las asignaciones locales seleccionadas. UISP y los routers no se modificarán.</p>
                <p id="network-cleanup-status" class="form-note" role="status" aria-live="polite"></p>
                <div id="network-cleanup-candidates" class="cleanup-assignments"></div>
                <div id="network-cleanup-blocked" class="cleanup-protected"></div>
                <div id="network-cleanup-result" class="cleanup-result" style="display: none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary close-modal" data-i18n="Cerrar">Cerrar</button>
                <button type="button" class="btn btn-primary" id="apply-network-cleanup" disabled data-i18n="Limpiar asignaciones seleccionadas">Limpiar asignaciones seleccionadas</button>
                <button type="button" class="btn btn-primary" id="reload-after-cleanup" style="display: none;" data-i18n="Recargar panel">Recargar panel</button>
            </div>
        </div>
    </div>
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
    <script src="js/i18n.js?v=2.0.52"></script>
    <script src="js/api.js?v=2.0.52"></script>
    <script src="js/operations.js?v=2.0.52"></script>
    <script src="js/workflows.js?v=2.0.52"></script>
    <script src="js/app.js?v=2.0.52"></script>
</body>
</html>
