# UISP/UCRM Plugin for RouterOs Services

[Complete English changelog](docs/CHANGELOG_EN.md) · [Texto completo para el PR](docs/PR_DESCRIPTION.md) · [Webhook setup and rationale](docs/WEBHOOK_SETUP_EN.md) · [Mejoras en español](docs/MEJORAS.md)

## Candidato 2.4.20 — diagnóstico de IP duplicadas al actualizar

Si una instalación anterior conserva la misma IPv4 o dirección/prefijo IPv6 en varios servicios, la actualización se detiene para mantener la unicidad global de las IP, incluso entre routers distintos. Antes se mostraba `operation_failed_check_log` sin indicar las asignaciones en conflicto. La 2.4.20 muestra al administrador un aviso en español con contador de conflictos y una tabla de familia, dirección e IDs de servicios; permite descargar el diagnóstico JSON y volver a comprobar los datos.

La comprobación inicial es de sólo lectura y precede a la inicialización de la cola y a la rotación de respaldos. Se repite dentro de la transacción de migración e incluye las direcciones que se importarían desde servicios antiguos, para impedir que `INSERT OR IGNORE` omita una asignación en conflicto. Las respuestas POST conservan JSON con el error específico `duplicate_network_addresses_resolve_before_upgrade`. Los detalles acotados se reservan a administradores; los webhooks reciben sólo el código y el total de conflictos.

Instalar 2.4.20, revisar las asignaciones indicadas y corregirlas explícitamente según las IP reales de cada servicio, conservando los respaldos privados. La instalación sigue pausada hasta resolver los conflictos y volver a comprobar. El aviso conserva las IP existentes y no envía cambios al router; no reasigna ni repara direcciones automáticamente.

Validación 2.4.20 aprobada: **29 suites aisladas**, sintaxis de **41 archivos PHP propios** y comprobación del instalador de 353 entradas, 120 archivos propios y 13 dependencias. Informe: `dist/verification-2.4.20.json`. SHA-256: `fd74d7d8b81c8937a8553e20305e4bcdbc743cf6b19d7f34401e7956dfda7a01`. Las pruebas usan bases temporales y transportes simulados/locales; queda comprobar el aviso y la resolución de las asignaciones en Familynet. Los resultados históricos de 2.4.19 y versiones anteriores se conservan a continuación.

## Candidato 2.4.19 — compatibilidad del tipo de plan devuelto por UISP

La validación añadida en 2.4.18 aceptaba sólo `servicePlanType: "internet"`, pero UISP devuelve también `"Internet"`. Ese dato válido se rechazaba al refrescar el catálogo y al recuperar planes archivados por ID. La 2.4.19 acepta ambas formas, conserva el rechazo de tipos ajenos a Internet y mantiene las comprobaciones de nombre, ID y velocidades. También reconoce el campo de respuesta `archived` al mostrar el estado del plan.

Si la recuperación falla, el registro `plan_metadata_failed` indica el ID, campo y causa controlada (`unsupported_type`, `invalid_speed`, `id_mismatch`, `missing_name` o `request_failed`, entre otras). No registra respuestas completas ni credenciales. Los datos locales anteriores se conservan ante un fallo.

Instalar 2.4.19 y volver a sincronizar el router afectado; no hace falta restaurar el plan archivado ni eliminar perfiles. La sincronización manual reconecta sus cuentas administradas. El caso del plan 38 ahora se prueba con `Internet`, tanto en la consulta individual como en el catálogo.

Validación 2.4.19 aprobada: **28 suites aisladas**, sintaxis de **40 archivos PHP propios** y verificación del instalador de 352 entradas, 119 archivos propios y 13 dependencias. Informe: `dist/verification-2.4.19.json`. SHA-256: `9be1f9102bf835f04e9bcc9e7c459bc567555425ce27763fe06341ed02b7118f`. El 11 de octubre de 2026, el usuario confirmó la recuperación correcta de velocidades de planes archivados en su instalación. Las comprobaciones restantes conservan su alcance independiente. Los resultados de versiones anteriores se conservan a continuación.

## Candidato 2.4.18 — nombres y velocidades de planes archivados

Los planes archivados que siguen asignados a servicios se recuperan por su ID cuando faltan el nombre o las velocidades en la base local. La sincronización no depende de abrir primero Planes. El catálogo también consulta por ID los planes referenciados que no aparecen en la colección habitual de UISP.

Se actualizan sólo el nombre y las velocidades base: se conservan los overrides, porcentajes, prioridades y burst del plugin. El perfil administrado se renombra por su identidad existente, conservando las credenciales e IP de cada cuenta. Un fallo o respuesta incompleta de UISP detiene ese aprovisionamiento antes de escribir en el router; no crea un perfil de respaldo con límites inventados. Las retiradas de cuentas no necesitan esta consulta.

Para corregir un perfil como `uisp-plan-38`, instalar 2.4.19 (incluye la corrección del tipo de respuesta) y sincronizar el router correspondiente. Con los datos del plan 38 y sin overrides, el nombre será `Plan Comercial - Ultra [25Mb/5Mb] [UISP#38]` y la tasa comenzará por `5M/25M`. El botón del router también reconecta sus cuentas, según la opción elegida para la sincronización manual.

Validación 2.4.18 aprobada: **28 suites aisladas**, sintaxis de **40 archivos PHP propios** y comprobación del instalador de 352 entradas, 119 archivos propios y 13 dependencias. Informe: `dist/verification-2.4.18.json`. SHA-256: `2a0c4da46b426e7fabf38f2ec642d9a5de3be927fb063c4a9a20b0e6bc260c21`. Las pruebas usan bases temporales y transportes simulados/locales; queda comprobar la instalación en UISP/RouterOS real.

## Candidato 2.4.17 — cancelar pendientes y conservar una recuperación segura

**Trabajos → Eliminar** permite cancelar cualquier trabajo pendiente: nuevo, preparado, intentado o importado. Los trabajos en ejecución siguen protegidos. Cancelar detiene sus reintentos y lo retira de la lista; no envía comandos a RouterOS, no crea trabajos ni deshace cambios que ya pudieron llegar al router. Una entrega repetida del UUID cancelado no revive la operación.

Si el trabajo pudo modificar recursos, se conserva un registro privado con las identidades, comandos, direcciones y destinos originales necesarios para retirarlos, junto con sus reservas. Ante un cambio nuevo del servicio o una sincronización completa posterior, se limpian esos recursos antes de aplicar la configuración nueva. No se reproducen los comandos de actualización cancelados. Si la limpieza falla, permanece recuperable después de reiniciar; las tareas que sólo ajustan colas no consumen ese registro. Cancelar una retirada también cancela sus reemplazos pendientes, para impedir que adelanten la limpieza cancelada.

La evidencia de una creación parcial del perfil de suspensión también se conserva. La recuperación posterior comprueba ese perfil antes de retirar otros recursos: no acepta uno incompleto por el hecho de existir, ni crea o sobrescribe el perfil al limpiar una operación cancelada. Los perfiles externos y las comprobaciones de propiedad siguen protegidos.

El plugin abre y cierra una conexión de la API nativa por lote de un servicio. Las consultas y la limpieza de identidades anteriores pueden abrir conexiones adicionales. Los mensajes de login/logout de la API reflejan ese ciclo; por sí solos no indican una desconexión de la sesión PPP del cliente. La 2.4.17 no introduce una conexión compartida entre lotes.

El candidato 2.4.17 pasó **27 suites aisladas** y la comprobación de sintaxis de **39 archivos PHP propios**. El instalador verifica 351 entradas, 118 archivos propios, 13 dependencias fijadas, CRC y correspondencia con el código probado. Informe: `dist/verification-2.4.17.json`. SHA-256: `af81d06f968012d7928014c4f1dc3d03f3f6ba9af7b382d1a04ff10be883fcc3`. Las pruebas usan bases temporales y transportes simulados/locales; queda validar la instalación en UISP/RouterOS real.

### Recuperación y diagnóstico incorporados en 2.4.16

Los cambios recibidos por webhook y las aplicaciones inmediatas intentan primero los trabajos pendientes anteriores del mismo servicio y después el cambio solicitado. Antes, el webhook intentaba sólo los trabajos de su propia entrega: un antecesor fallido podía dejar el cambio bloqueado hasta el worker programado o una ejecución manual. La recuperación mantiene el orden y el alcance del servicio; si el antecesor vuelve a fallar, el cambio permanece pendiente. El worker programado conserva sus intervalos de reintento.

La 2.4.16 permitía cancelar sólo ciertas retiradas nuevas sin intentos y protegía las preparadas/intentadas/importadas. **Esa restricción se sustituye en 2.4.17** por la cancelación local de todos los pendientes descrita arriba; se conserva la protección de los trabajos en ejecución.

**Puerto accesible** sólo comprueba acceso al puerto. Un trabajo puede fallar después al negociar TLS o iniciar sesión en la API. Los nuevos intentos conservan un diagnóstico de conexión con códigos fijos, sin credenciales ni respuestas completas del router. Para los errores anteriores, reintentar un único trabajo desde **Trabajos → Ejecutar**; no hace falta otra sincronización completa. Borrar un trabajo no corrige el acceso al dispositivo.

### Recuperación de suspensión y trabajos pendientes en 2.4.15

Si falta el perfil de suspensión configurado (por ejemplo, `disabled`), se crea antes de asignarlo a la cuenta PPP/Hotspot, con su lista de suspendidos (`disabled` en la configuración habitual) y el límite configurado. PPP obtiene también la dirección local del router. El perfil se verifica tras crearlo y se conserva en las siguientes ejecuciones. Un perfil existente mantiene su configuración. La IP aparece dinámicamente en la lista al conectar; el bloqueo de tráfico depende de las reglas del firewall que utilizan esa lista. No se añaden direcciones ficticias ni reglas de firewall genéricas.

**Ejecutar** procesa primero los trabajos anteriores pendientes del mismo servicio y después la selección, respetando su orden. La tabla identifica el trabajo que bloquea a otro. **Eliminar** informa qué pendientes se cancelaron y qué trabajos en ejecución se conservaron; la recuperación de posibles cambios anteriores sigue la política de 2.4.17. Los comentarios visibles de perfiles de planes pasan de identificadores internos a `Plan #94`, sin referencias al plugin; se reconocen ambos formatos para actualizar los perfiles existentes sin duplicarlos.

Trabajos incorpora **Refrescar** y **Refresco automático** cada 5, 10, 30 o 60 segundos, desactivado por defecto. El intervalo se guarda en el navegador, se conserva la selección de trabajos presentes y las consultas se pausan al ocultar la vista o realizar acciones. Consultar la lista no ejecuta trabajos.

### Diagnóstico incorporado en 2.4.14

Los fallos de verificación de perfiles externos se guardaban en Trabajos sin aparecer en el registro del plugin. Ahora el diagnóstico distingue perfil ausente, fallo de lectura, coincidencias ambiguas e identidad inválida, indicando la ruta PPP/Hotspot y el nombre solicitado. Los intentos fallidos de la cola y el resumen de la sincronización manual se registran también con depuración desactivada, sin credenciales ni respuestas completas del router. Un fallo del registro no cambia el resultado del trabajo.

El usuario confirmó después que había eliminado todos los perfiles del MikroTik: el rechazo `router_session_profile_missing` correspondía a `disabled` ausente. La recuperación automática se añade en 2.4.15. Un fallo de lectura o una identidad ambigua sigue deteniendo la operación; no se interpreta como ausencia. Para reintentar pendientes existentes, usar Trabajos sin crear otra sincronización completa.

### Reconexión manual y eventos de facturación en 2.4.13

El botón **Sincronizar y reconectar cuentas** de cada dispositivo aplica sus cuentas administradas y fuerza la reconexión de sus sesiones PPP/Hotspot, aunque la configuración coincida. Los eventos explícitos de suspensión y reactivación también solicitan cerrar la sesión después de actualizar la cuenta. La intención se guarda antes de escribir al router y se conserva en reintentos; se retira al completar el trabajo. Las cuentas ajenas al plugin y los demás routers quedan fuera de esa sincronización.

Las actualizaciones ordinarias y la reconstrucción general conservan la comparación de cuenta/perfil contra el estado aplicado y contra RouterOS: los cambios efectivos reconectan; una operación sin cambios conserva la sesión. Renombrar el mismo perfil propio no requiere por sí solo reconectar. Las bajas siguen desconectando sus sesiones propias.

UISP puede enviar `suspend` y después `edit`, o `unsuspend` y después `edit`, con UUID distintos. Si ese `edit` sólo repite el cambio de estado ya aplicado, hay una referencia completa que sigue vigente y no quedan trabajos pendientes para el servicio, devuelve `already_applied` sin otra escritura ni otro trabajo. Un cambio adicional o un fallo pendiente sigue procesándose. La repetición del mismo UUID conserva su deduplicación habitual.

Los eventos `edit` reconcilian diferencias con el estado aplicado aunque una actualización de caché ya haya incorporado el nuevo estado de UISP. Un evento parcial sin estado explícito no puede anular una baja pendiente usando una caché anterior. Trabajos muestra estado, intentos, próximo reintento y último error; actualiza la tabla incluso cuando la ejecución falla.

Las respuestas de webhook incluyen el resultado del aprovisionamiento, servicio, router resuelto, estado solicitado/aplicado y trabajos conocidos. Los registros `webhook_result` y `webhook_processing_failed` se conservan incluso con depuración desactivada, sin credenciales ni cuerpo del evento. Trabajos muestra los pendientes; una tabla vacía o una respuesta HTTP 200 por sí solas no confirman el cambio de facturación en RouterOS.

Un Site seleccionado que no corresponde a un router, o cuyo nombre coincide con varios, ahora produce un error antes de alterar la caché o retirar la cuenta anterior. La ausencia de identidad con generación automática desactivada también devuelve un error explícito. Un Site vacío mantiene el funcionamiento documental. Si el Site histórico cambió de nombre, una instantánea aplicada verificable permite recuperar la identidad anterior para su limpieza.

El secreto sigue siendo obligatorio por defecto. La nueva opción **Permitir webhooks sin secreto (compatibilidad)** permite desactivar explícitamente esa comprobación para los eventos válidos de clientes/servicios; el panel conserva sus permisos. En modo con secreto, el campo del plugin contiene **sólo la clave**, y el terminal de UISP lleva la URL con `?webhookToken=LA_MISMA_CLAVE`. Una entrega real pasó de 403 a 200 tras corregir estos valores. En la prueba posterior del 11 de octubre de 2026, el usuario confirmó que los cambios sí ocurrían: la aparente falta de aplicación se debía a la visibilidad de los logs. La captura de RouterOS muestra acceso por API y modificaciones de perfil y cuenta PPP. Este incidente no requiere otro cambio de aprovisionamiento sobre 2.4.12; la comprobación de tráfico y del resto de escenarios de laboratorio continúa siendo independiente.

### Diagnóstico de planes incorporado en 2.4.10

Los valores opcionales vacíos heredados se tratan como no configurados antes de calcular las velocidades. El editor identifica ajustes numéricos corruptos, en vez de mostrarlos como `0`, y permite corregirlos explícitamente. Abrir el plan no modifica su configuración. Los logs distinguen el valor original inválido del desbordamiento durante el cálculo, con campo, causa y valor seguro. Un plan archivado puede seguir atendiendo clientes; sus velocidades de UISP y los porcentajes propios del plugin son datos diferentes.

Los servicios sin router/site se conservan como documentación y no generan trabajos ni reservan IP. Una IPv4 manual debe contener únicamente la IP del cliente, sin máscara; si está vacía, se asigna desde el pool según la configuración. Los errores numéricos de un plan identifican servicio, plan y campo, sin detener los demás servicios. La sincronización informa cuántos se completaron y cuáles necesitan revisión.

Los perfiles propios muestran `Nombre completo del plan [UISP#ID]`, manteniendo su marca de propiedad por ID. Los perfiles técnicos propios de 2.4.8 se renombran al aplicar los servicios; los perfiles históricos ajenos se conservan. Quitar el site o dar de baja limpia también identidades de operaciones parcialmente aplicadas, conservando las reservas hasta confirmar su retirada.

Panel compacto con **modo claro, oscuro y sistema** y selector de **español, inglés, portugués, francés, alemán e italiano**. Apariencia e idioma se guardan en cada navegador, sin cambiar la configuración compartida; el cambio de idioma conserva los formularios abiertos y sus valores sin guardar.

El [inventario completo de mejoras](docs/MEJORAS.md) reúne tus cambios del [PR #89](https://github.com/MadaMzandu/uisp-ros-plugin/pull/89), las correcciones posteriores, las plantillas de burst, la cola persistente, seguridad, respaldos, migración y límites conocidos. Incluye instrucciones para preparar las pruebas reales. Hay también un [borrador en inglés para el nuevo PR](docs/PR_DESCRIPTION.md).

La comunicación con MikroTik utiliza la **API nativa de RouterOS**, con TLS opcional; los cambios de interfaz no alteran ese mecanismo. Los apartados siguientes conservan el detalle de las revisiones anteriores y la documentación original.

La segunda revisión corrige además propiedad de cuentas/colas padre, hidratación de clientes fuera de caché, bajas con router desconectado y migración de trabajos terminales anteriores, reintentos de perfiles/padres compartidos, pools IPv6 por revisión, idiomas regionales y respuestas tardías al cambiar pestañas. El detalle de cada fallo y su resultado está en el inventario. La [guía de pruebas reales](docs/PRUEBAS_REALES.md) contiene 69 casos para comprobar en laboratorio.

Para verificar el candidato después de instalar dependencias y construir el ZIP:

```text
python tools/verify_plugin.py --php RUTA_PHP --node RUTA_NODE --archive dist/ros-plugin-2.4.20-candidate.zip --output dist/verification-2.4.20.json
```

En Windows sin configuración PHP, añadir `--extension-dir RUTA_EXT`. El verificador ejecuta las suites y comprueba sintaxis PHP, correspondencia del código/dependencias y exclusión de datos/fixtures del instalador. Validación 2.4.20 aprobada: **29 suites aisladas**, sintaxis de **41 archivos PHP propios** y comprobación del instalador de 353 entradas, 120 archivos propios y 13 dependencias. Informe: `dist/verification-2.4.20.json`. SHA-256: `fd74d7d8b81c8937a8553e20305e4bcdbc743cf6b19d7f34401e7956dfda7a01`. Las pruebas usan bases temporales y transportes simulados/locales; queda comprobar el aviso y la resolución de las asignaciones en Familynet. Para construirlo: `python tools/build_plugin.py --vendor-dir src/vendor --output dist/ros-plugin-2.4.20-candidate.zip`.

## Implementación de estabilización 2.4.7 — candidato para laboratorio

La revisión 2.4.7 corrige los hallazgos de la auditoría profunda: orden A→B→A de operaciones, eventos parciales, credenciales literales, tipos de configuración, colisiones de perfiles, retirada de routers, datos IP inválidos, tamaños y exclusiones IPv6, errores de lectura, pérdida de borradores y recuperación de estado auxiliar. Los guardados de planes y configuración programan trabajos durables, conservando el estado de facturación al preparar cada servicio. El worker continúa solicitudes diferidas cuando un evento está ocupando su servicio.

En 2.4.7 los perfiles activos utilizaban `uisp-plan-ID`; desde 2.4.9 muestran el nombre completo más `[UISP#ID]`. Los servicios sin plan real usan `uisp-service-ID-profile`. La actualización migra cada cuenta cuando se aplica su trabajo: no reconstruye todos los routers al instalar y conserva perfiles anteriores hasta que ya no estén utilizados. El perfil PPP/Hotspot de suspensión configurado es externo y no se sobrescribe. El límite adicional de suspensión utiliza `uisp-service-ID-suspension`, con propiedad comprobada y posición inicial en las colas simples; al reactivar se elimina. Verificar el perfil externo, sus listas de firewall, la posición de las colas dinámicas tras reconectar y FastTrack en laboratorio.

CRM, NMS y autenticación utilizan límites de 15 segundos por petición y 5 por conexión, y rechazan redirecciones. Se mantienen las opciones TLS del SDK. Se actualizan Guzzle a 7.15.5 y PSR-7 a 2.13.1, junto a sus dependencias compatibles; Composer no encontró avisos de seguridad aplicables en la consulta de esta revisión. La compatibilidad declarada continúa en PHP 7.4; las regresiones se ejecutan con PHP 8.5.11 y deben contrastarse con el runtime de UISP.

Los trabajos completados conservan sólo un resumen sin credenciales; las filas históricas se purgan a los 30 días y los recibos mínimos mantienen la deduplicación de eventos. Las instantáneas pendientes y del estado realmente aplicado conservan los datos necesarios para recuperar operaciones. Los bloqueos de petición se comparten entre cron y FPM y protegen también la restauración frente a escrituras administrativas. Detener workers y entregas de la versión anterior al actualizar; sus filas `running` sin bloqueo nuevo mantienen recuperación conservadora por PID.

La guía de pruebas y recuperación se entrega junto al instalador en `dist/pruebas-reales-2.4.7.html`. Los controles antiguos sin consumidor efectivo (`hs_enable`, `unsuspend_date_fix`, `unsuspend_fix_wait`) se ocultan en el panel; sus valores históricos se conservan.

Este paquete requiere validar la integración en una copia de UISP y routers CHR antes de instalarlo en producción. Las regresiones locales usan bases temporales y transportes simulados. No sustituyen la prueba de los webhooks, certificados y reglas de firewall de una instalación real.

La revisión 2.4.1 retira la nueva casilla de QoS del formulario de dispositivos: no representaba una política persistente y podía solicitar eliminar colas al guardar un dispositivo antiguo con `qos=NULL`. El formulario conserva el comportamiento anterior y no modifica ese campo. Las opciones TLS y certificado autofirmado siguen siendo opcionales para la API de RouterOS.

La revisión 2.4.3 corrige la apertura del editor de planes cuando la página conserva un formulario anterior y carga JavaScript actualizado. Los controles de plantillas son opcionales para abrir el editor manual, y los errores mantienen un aviso visible aunque el HTML antiguo no contenga la región de notificaciones. El punto de entrada envía cabeceras que impiden almacenar el panel y las respuestas autenticadas en caché. Después de actualizar desde una página que ya estaba abierta, recargarla con `Ctrl+F5` para obtener también el formulario nuevo y sus plantillas.

La revisión 2.4.4 corrige las fechas del instalador: las anteriores eran iguales para todos los paquetes, lo que podía conservar PHP antiguo en OPcache después de extraer una actualización. Cada versión utiliza una fecha distinta y reproducible. Antes de cargar las clases, el plugin invalida únicamente sus scripts PHP almacenados cuya fecha difiere del archivo instalado. El HTML del panel se lee directamente desde el disco.

La compatibilidad de la base se comprueba antes de rotar un respaldo, conservando la versión como texto. Al abrir una base incompatible, el error `database_version_is_newer_than_plugin` incluye `database_version`, `runtime_version` y `package_version`. La respuesta pública también indica `runtime_cache_needs_restart` cuando la caché no pudo comprobarse o renovarse. La restauración mantiene `backup_version_is_newer_than_plugin` con los mismos detalles. La protección frente a versiones superiores continúa activa; un rechazo no cambia el cursor ni reemplaza los respaldos existentes.

Si el servidor tiene `opcache.validate_timestamps=0` y conserva el punto de entrada anterior, debe renovar la caché del **PHP web** o reiniciar ese servicio una vez tras instalar la actualización. Una recarga del navegador o del PHP de consola no renueva esa caché. Esta condición depende de la configuración del servidor; el ZIP y el bootstrap no pueden ejecutar código que PHP todavía no ha vuelto a cargar. [Configuración oficial de OPcache](https://www.php.net/manual/en/opcache.configuration.php).

La revisión 2.4.5 abre los editores de planes y dispositivos directamente, sin activar el cargador de solicitudes. Conserva el cargador al guardar y en las operaciones de red, con liberación ante errores. También elimina los filtros de desenfoque detrás del cargador y de los modales para reducir la composición gráfica dentro del iframe. En la instalación observada no se detectaron cierres/reaperturas del modal, errores de JavaScript ni cambios de tamaño durante el problema comunicado; el origen gráfico del parpadeo es una hipótesis y requiere confirmar el resultado en el navegador afectado.

La revisión 2.4.6 consulta la configuración guardada al listar los planes. Si la contención está deshabilitada, el editor muestra el ratio desactivado, explica que no se aplica y omite ese campo al guardar: los valores antiguos como `0` se conservan y no bloquean cambiar el burst o la velocidad. Con contención habilitada, exige un entero desde `1`. Los borradores de Configuración no cambian ese comportamiento hasta guardarlos; un fallo de lectura impide editar con un estado desconocido. La edición parcial del servidor conserva los campos omitidos, sin rebajar la validación de ratios enviados.

El panel se compacta con campos en dos o tres columnas en escritorio y una en móvil, secciones por función, interruptores de sí/no, ayudas plegables y una barra de guardado visible. Conserva borradores entre pestañas y vistas; «Guardar y crear faltantes» persiste los nombres antes de crear los atributos en UISP. Los resultados rutinarios se muestran en avisos sin bloquear la pantalla y las acciones destructivas conservan su confirmación. Los formularios de dispositivos/planes y las utilidades de Sistema también se reorganizaron.

### Plantillas de burst en planes

En **Planes → Editar** se puede rellenar el burst de subida y bajada con un clic. Las plantillas son propuestas del plugin y se guardan mediante los campos existentes; se pueden ajustar por dirección. Seleccionar una plantilla sólo modifica el formulario: **Guardar** inicia la aplicación habitual del plan a sus servicios.

| Plantilla | Velocidad de burst | Umbral sobre velocidad normal | Ventana de promedio | Uso previsto |
| --- | --- | --- | --- | --- |
| Sin burst | Desactivado | Desactivado | 0 s | Mantener el límite normal. |
| Suave | 125% | 80% | 8 s | Un aumento moderado para transferencias breves. |
| Equilibrado | 150% | 75% | 12 s | Más margen para transferencias breves después de poco tráfico. |
| Rápido | 200% | 70% | 10 s | Un pico mayor cuando la red dispone de capacidad. |

La vista previa calcula los Mbps usando la velocidad modificada del plugin, si existe, o la del plan UISP. La revisión 2.4.2 corrige el límite normal que antes ignoraba esa modificación mientras el burst sí la utilizaba. Los valores manuales conservan el contrato anterior: incremento de burst de 50 significa +50%; reducción del umbral de 25 significa un umbral del 75%.

RouterOS permite burst mientras el tráfico medio de la ventana está por debajo del umbral. **La ventana no es la duración del burst.** La estimación inicial tras reposo se calcula como `umbral × ventana / velocidad de burst`; el tráfico reciente y la capacidad disponible afectan el resultado. Por ejemplo, Equilibrado en bajada de 50 Mbps propone 75 Mbps, umbral de 37,5 Mbps y ventana de 12 s, con unos 6 s iniciales teóricos a plena velocidad. Las colas superiores también pueden limitarlo. [Funcionamiento oficial de burst en RouterOS](https://manual.mikrotik.com/docs/firewall-and-quality-of-service/queues/queue-burst/).

### Plan y avance

1. **Accesos y datos:** SQL preparado y tipos preservados; permisos administrativos; secreto para webhooks; respuestas de error; respaldo validado; migración transaccional por columnas; caché publicada sólo después de una descarga completa y sin cambios concurrentes.
2. **Automatización:** cola SQLite persistente, importación de `queue.json`, snapshots originales, versiones por servicio, deduplicación de eventos de servicios por UUID, reintentos con espera creciente, bloqueo por servicio y confirmación integral de RouterOS. Las reservas incluyen identidades aplicadas y operaciones pendientes.
3. **Panel y red:** rutas y formularios reparados; valores HTML escapados; tipos de dispositivos y planes; TLS configurable; lectura con timeout/EOF; sesiones Hotspot; DUID/IAID; IPv6 y asignación atómica.
4. **Validación antes de producción:** probar suspensión/pago con router desconectado, cambios de router y dirección, eventos repetidos, fallos parciales IPv4/IPv6, certificados, restauración y recuperación después de reiniciar el worker. Verificar las reglas de suspensión IPv4 e IPv6 existentes.
5. **Siguiente etapa:** reconciliación periódica entre UISP y el estado observado en RouterOS; identificar eventos diferentes recibidos fuera de orden mediante una revisión fiable de origen; topología NMS con asociación persistente `serviceId → deviceId`, actualización de enlaces y adopción controlada de nodos antiguos; auditoría/actualización de dependencias y pruebas en versiones reales de UISP/RouterOS.

Las escrituras automáticas de topología NMS quedan suspendidas en este candidato: el código anterior podía borrar nodos de otros servicios del mismo sitio. Se conserva la lectura NMS. No se deben reactivar las escrituras hasta implementar y verificar la propiedad de cada nodo.

### Migración y configuración

- Conservar el paquete anterior y descargar un respaldo de la versión actualmente instalada. Los respaldos contienen credenciales y deben guardarse en un lugar privado.
- Durante el reemplazo del paquete, pausar el worker y las entregas del webhook anterior para evitar dos versiones modificando los mismos servicios. Reanudarlos después de verificar la migración y la autenticación en laboratorio.
- Por defecto, configurar `webhookSecret` con **sólo una clave aleatoria** de al menos 32 caracteres. En el terminal de UISP, añadir `?webhookToken=EL_MISMO_SECRETO` a la URL pública, o enviar `X-Webhook-Token`. **Guardar el secreto en el plugin no actualiza las peticiones de UISP.** Si el terminal automático no es editable, crear uno manual compatible y evitar entregas duplicadas. Una URL pegada en el campo de clave se rechaza con el diagnóstico `webhook_secret_contains_url`.
- Para funcionar sin clave, activar expresamente **Permitir webhooks sin secreto (compatibilidad)** en los ajustes del plugin y guardar. El terminal puede usar la URL sin token. Este modo admite eventos válidos sin autenticar su emisor; conserva la validación de formato y los permisos del panel. Una actualización no lo activa automáticamente. Véase la [guía de configuración y comprobación](docs/PRUEBAS_REALES.md#configurar-el-secreto-del-webhook).
- Las IP privadas y los usuarios de la zona de clientes ya no autorizan operaciones del plugin. El panel requiere permiso `system/plugins`; los cambios y descargas de respaldos requieren nivel `edit`.
- Los webhooks no pueden utilizar su secreto para llamar operaciones administrativas. Las peticiones administrativas POST de sesión requieren un origen compatible con `ucrmPublicUrl`.
- La primera ejecución importa los trabajos JSON en `data/jobs.db` sin vaciarlos. La limpieza de registros no elimina trabajos pendientes. Los respaldos SQLite del panel cubren `data.db`; conservar también `data/jobs.db` para una recuperación completa. Restaurar una BD no revierte los cambios ya aplicados a los routers.
- Las migraciones rechazan direcciones duplicadas en la BD antes de crear los índices únicos. Resolver esos conflictos en laboratorio; no eliminar reservas automáticamente.
- Requisitos: PHP compatible con el SDK, extensiones declaradas en `composer.json` y SQLite 3.24 o superior. Las pruebas de esta implementación se ejecutaron con PHP 8.5.11; queda validar el runtime incluido en la instalación de UISP.
- Puerto `8729` infiere TLS para dispositivos existentes sin opción explícita. En el panel se puede elegir TLS. Los certificados se verifican; aceptar un certificado autofirmado es una opción explícita y el nombre del host continúa verificándose.
- La sincronización de atributos IP acepta horas `0–23`; vacío la desactiva. `syncTimezone` admite valores como `America/Caracas`; vacío utiliza la zona del servidor.

### Verificación y empaquetado

Ejecutar las regresiones aisladas desde la raíz del repositorio:

```text
php tests/regression_backend.php
php tests/regression_jobs.php
php tests/regression_job_actions.php
php tests/regression_cancelled_recovery.php
php tests/regression_router.php
php tests/regression_burst.php
php tests/regression_runtime.php
node tests/regression_panel.js
php tests/regression_stability.php
php tests/regression_client_queue.php
python tests/regression_http.py --php RUTA_AL_PHP_CON_CURL
```

Las pruebas RouterOS sólo utilizan sockets locales; necesitan permiso para `127.0.0.1`. En el sandbox de Windows, las pruebas de respaldos pueden requerir ejecutarse fuera de su restricción de renombrado. Las antiguas pruebas PHPUnit no son la suite de este candidato; se retiró la prueba EdgeRouter que contenía credenciales y un destino externo.

La regresión de runtime requiere la extensión ZIP, OPcache y Python para construir instaladores temporales. Activa OPcache sólo en sus procesos aislados y comprueba la extracción de fechas, la coherencia de versiones y la renovación selectiva, sin modificar la instalación real.

Para inspeccionar la interfaz con datos ficticios y sin conectar a UISP o routers: `python tools/preview_panel.py`. La vista escucha sólo en `127.0.0.1:8765` y mantiene los cambios en memoria; no es el servidor del plugin instalado.

`composer.lock` fija las dependencias actualizadas. Después de `composer install --no-dev`, se puede construir el instalador reproducible desde `src/vendor`:

```text
python tools/build_plugin.py --vendor-dir src/vendor --output dist/ros-plugin-2.4.7-candidate.zip
```

El empaquetador incluye el código actual y las dependencias del archivo indicado; excluye datos de producción, pruebas y archivos de configuración generados por UISP.

## Documentación histórica del proyecto original

El contenido siguiente se conserva como referencia del proyecto original. Para el candidato actual, utilizar el inventario y las instrucciones de migración anteriores; algunas opciones y procedimientos históricos han cambiado.

So it finally came to be, a simple to install plugin replacement for the previous api based version.

# New Features

1. All new graphical interface for adding devices and managing settings
2. One click installation via uisp plugin interface
3. Contention ratios - the plugin will now create and manage parent queues to apply contention per router device.
4. Automatic ppp profile creation - ppp profiles for service plans are now created/removed automatically
5. Automatic ppp username and password generation
6. Rebuild utility - the plugin can now re-sync itself and router devices against uisp. A new router can now be repopulated with accounts in minutes.
7. Automatic backup - although the plugin can resync itself with uisp and managed routers, there is a now backup feature for added redundancy.

## Other features
1. Real time provisioning, editing, suspending, unsuspending of accounts
2. Real time migration of accounts between devices
3. Supports both dhcp and pppoe and other ppp variants

# Kofi

If this work adds value to your project then take the time and send the programmer some Kofi. 
https://ko-fi.com/madamzandu

# Installation

1. Download the binary zip file "ros-plugin.zip" and upload into your Uisp > Settings > Plugins.

2. Enable the plugin and create the webhook.

# Configuration

1. After enabling the plugin a menu icon will be installed for the plugin. Click on the icon and go to panel.
2. In the devices tab add your mikrotik devices
3. In the plans tab set the contention ratios for your plans or leave as 1:1 if not selling contention. If no plans are defined yet, define your plans in Uisp > Settings > Service Plans & Products
4. In the settings tab go to the attributes tab and define the attributes that you want to use. You will need device name and mac address for dhcp, pppoe username,password and device name for pppoe. You can also set an ip address attribute if you wish to manually assign ip addresses for some accounts. You can also enable all the attributes if you are using both pppoe and dhcp.
5. If using pppoe, in the Settings > General tab select if you want to use one pool for all your routers and specify the pool, or you want to use per router pool specified in the device.
6. Thats it your are done.

# Upgrading
If you have been using the previous api based version:

1. After installation and configuration above disable the webhook for the previous api based version.
2. Go to the settings tab > system and click rebuild to populate with accounts from previous api version.

Please ensure that your mikrotik devices are added to the plugin and are online. Rebuild writes account and queue configuration to the devices; validate it in a lab and schedule a maintenance window before using it in production. Check pending jobs and device accounts to confirm completion.

# Using

The custom attributes in Configution (4) should be listed in the form when creating or editing a service in Uisp.
1. Fill the device name to specify the router for the clients account (see making a device dropdown list below)
2. PPPoE username and Password to provision PPPoE.
3. Mac address to provision DHCP instead.
4. IP address to bypass the pool

## IPv6 Over PPP

Version 1.8.5 introduces support for ipv6 gateway devices and ipv6 over ppp (without PD). Ipv6 for dhcp is
not implemented in this version.

Ipv6 over ppp routes an ipv6 prefix to a ppp client's device which can then be used for dhcp or other 
services on the client's lan.

In the device settings provide an ipv6 prefix of reasonable size e.g. a /48. This prefix can be reused
on more than one router and the plugin will manage and ensure that client allocations have no conflicts.
After specifying the prefix you can specify the size or prefix length to assign clients in the prefix 
length field. If the prefix length field is empty the plugin will default to assigning /64's to clients.

When a pppoe client is added the plugin will allocate an ipv6 /64 along with the normal ipv4 framed address.
The ipv6 assignments can be viewed in the panel > devices > click on one of the devices.


## Hotspot Account Support

Starting version 1.8.5 the plugin now also supports mikrotik hotspot accounts so that ucrm can also be used to bill 
and provision hotspot clients.

To enable hotspot services go to panel > settings > general and check "enable hotspot accounts". This will add a hotspot
attribute to the crm client service form. This also makes available an option to auto generate hotspot user/password.
However please note that enabling automatic hotspot usernames will disable automatic pppoe usernames.

Create a user normally and check the hotspot attribute to provision to hotspot instead of ppp. Thats it.

## Localized Languages

Version 1.8.5 adds support for Swahili and Afrikaans. Please let me have feedback if there are problems.

In version 1.8.4 we have added and are testing support for languages. Currently the languages supported are en,es,fr,pt and de.

Please open an issue if you think there is a problem with the translations. For those that can spare the time and would like to contribute by translating to your preferred language the instructions will soon be posted in https://github.com/MadaMzandu/uisp-ros-plugin/tree/main/src/includes/l10n.

Once again thank you all for your invaluable feedback.

## Job queue - new feature

Version 1.8.3 now has a job queue to handle webhook requests that arrive when the target device is unreachable. 

When the plugin receives a webhook for a mikrotik device that is offline the request is automatically queued for later execution. The job queue can be executed automatically by enabling the plugin's scheduled execution or manually from Panel > Settings > Jobs then clicking on the "run queue" button.

## Contention ratios and volume
The maximum queue size that is acceptable on a Mikrotik is 4.294Gbps or 4294Mbps. If the total rate of the parent queue exceeds this limit the plugin will fail to add new customers to the plan until the contention ratio is adjusted. 

If contention is enabled in the plugin then admin must ensure that plan limit x number of plan customers x contention ratio does not exceed 4.294Gb. 

For example a plan for 50Mbps targeting 200 customers with a contention ratio of 1:1 (1/1) will not work because 50 x 200 x 1/1 = 10000Mbps or 10Gbs which exceeds the Mikrotik limit. The correct contention ratio for this plan must start at 1:3 (1/3) or above which is 50 x 200 x 1/3 = 3.3Gbps. This will work on Mikrotik.

If contention is not required then disable contention in Panel > Settings > General. Warning!! Disabling contention requires all customers to disconnect to apply changes therefore schedule maintanance especially if pppoe clients are on ubiquiti devices. PPPoE clients on mikrotik devices will reconnect in less than a second so this is not a problem for Mikrotik pppoe clients.

## Enabling automatic username and password generation

1. You need to go to panel > settings > general and enable the checkbox for this feature.
2. When adding a service for a client leave the username and password blank to let plugin generate the field automatically, thats it.

Also note the following;

1. A manually typed in username or password will override automatic generation for the field.
2. Deleting the existing username or password will cause the plugin to generate a new value for the field
3. The username is either client login, client lastname or client company name with service number appended.

## Making a dropdown device list

1. Go to crm settings > other > custom attributes.
2. Create a new custom attribute with the following parameters - type: choice, Attribute type: service,Client Visible:no.
3. Add the device names of your managed mikrotiks as values. You have to manually update this list of values when you add a new device.
4. Next go to plugin panel > settings > attributes and in the device name field type in the name of the attribute created in step 2. 
5. Click the save button when the panel finds the attribute.

There are two reasons why this can only be done manually if you are interested to know: 

1. Plugins can query crm but crm cannot query plugins. And since custom attrbutes are part of crm it means they cannot pull values such as device names from the plugin.
2. Why not push the list of devices to crm then? True this would be semi static fallback solution but crm API has not provided a call that can push values to an enumerated custom attribute so for now this will have to do.

## Handling account suspension and unsuspension

The plugin provides four mikrotik parameters that allow customizing how disabled accounts are handled. These are:
1. required : disabled profile - the name of a ppp profile to assign suspended accounts. Created automatically if missing.
2. required : disabled address list - the name of firewall address list to assign suspended accounts. Firewall rules for the the list must be manually configured by admin. The rules can be nat rules to redirect and filter rules to drop.
3. required : disabled rate - the rate limit to apply to disabled accounts. Automatically applied to ppp profile or dhcp queue. 
4. optional : active address list - extra address list to apply to active accounts. Firewall rules must be manually configured by admin.

## Recovering from a failed rebuild

Should the rebuild utility fail to complete, simply restore the latest backup that is listed and the plugin should be restored to its previous state. Note that the rebuild utility makes a backup each time it is executed and that the plugin rotates every 7th backup. Therefore it is advisable to download at least one backup when things get out of hand.

## Actions that interrupt customer service.

The following actions require an active pppoe to disconnect to apply changes. This is normal behaviour. For mikrotik pppoe clients this takes less than a second however ubiquiti pppoe clients seem to take longer to reconnect after an administrative disconnect so it is advisable to use certain features with caution.

1. Any edit of customer's username,password,ip address or callerid causes the active pppoe for the customer to be disconnected to apply changes.
2. Changing the customer's plan, suspending the customer or moving the customer to a different device will also disconnect the active account to apply changes.
3. Enabling or Disabling parent queues using "Panel > Settings > Disable Contention" requires ALL pppoe services to disconnect to apply changes.

# Credits

This software uses or depends on the following software by these developers with
the greatest gratitude.

Ben Menking – RouterOS API

<https://github.com/BenMenking/routeros-api>

Ubiquiti - UISP/UCRM/UNMS

<https://ubnt.com>

Mikrotik - RouterOS

<https://mikrotik.com>




