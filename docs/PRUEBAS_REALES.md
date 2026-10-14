# Pruebas reales del candidato 2.4.20

Las pruebas automatizadas usan bases temporales, transportes simulados y sockets locales. Esta lista comprueba la integración **UISP → plugin → API nativa de RouterOS** en una copia de UISP y un CHR/router con clientes de prueba. Registrar versiones de UISP, PHP y RouterOS, así como ID del servicio, plan, trabajo, estado e IP/prefijo en cada caso.

Validación 2.4.20 aprobada: **29 suites aisladas**, sintaxis de **41 archivos PHP propios** y comprobación del instalador de 353 entradas, 120 archivos propios y 13 dependencias. Informe: `dist/verification-2.4.20.json`. SHA-256: `fd74d7d8b81c8937a8553e20305e4bcdbc743cf6b19d7f34401e7956dfda7a01`. Las pruebas usan bases temporales y transportes simulados/locales; queda comprobar el aviso y la resolución de las asignaciones en Familynet. Este candidato añade el aviso de IP duplicadas y la comprobación previa a la migración; la instalación sigue pausada hasta resolver explícitamente las asignaciones. El aviso permite descargar el diagnóstico JSON y volver a comprobar, conservando las IP existentes sin cambios en RouterOS.

La validación automatizada histórica de 2.4.19 pasó las 28 suites, la sintaxis de 40 archivos PHP propios y la comprobación del instalador de 352 entradas, 119 archivos propios y 13 dependencias. Informe: `dist/verification-2.4.19.json`. SHA-256: `9be1f9102bf835f04e9bcc9e7c459bc567555425ce27763fe06341ed02b7118f`. La matriz actual contiene 69 casos de laboratorio; no se declara completada en su totalidad.

**Resultado confirmado el 11 de octubre de 2026:** después de probar 2.4.19, el usuario confirmó que se recuperan correctamente las velocidades de planes archivados. Esta confirmación cubre la recuperación reportada en los casos 62 y 65; no acredita todas sus variantes, los casos de fallo, mediciones de tráfico ni los demás escenarios.

## Preparación

1. Conservar el ZIP anterior y copias privadas de `data.db`, `jobs.db` y configuración. El respaldo descargado desde el panel cubre únicamente `data.db`.
2. Exportar y respaldar el router de laboratorio y registrar sus perfiles, cuentas, colas y direcciones antes del piloto.
3. Pausar el worker y las entregas anteriores mientras se sustituye el paquete; esperar a que terminen las peticiones activas.
4. Instalar 2.4.20 y recargar el panel. Si aparece el aviso de IP duplicadas, conservar los datos actuales, revisar sus servicios y resolver explícitamente las asignaciones antes de volver a comprobar; no continuar el piloto mientras la instalación permanezca pausada. Después verificar versión, configuración, planes y trabajos. Si PHP web conserva código anterior, renovar su OPcache/reiniciar ese servicio.
5. Comprobar el modo de autenticación del webhook; por defecto requiere el mismo secreto en ambos extremos. Verificar permisos administrativos, perfil externo de suspensión y reglas de firewall IPv4/IPv6.
6. Reanudar las entregas/worker y probar primero un servicio. Ampliar después a un router cuando los estados y medidas coincidan.

## Configurar el secreto del webhook

### Por qué se hizo este cambio

Un webhook puede modificar cuentas, suspender o reactivar servicios y retirar recursos administrados en RouterOS. El punto de entrada anterior aceptaba solicitudes por una sesión autenticada o por provenir de una dirección local/privada. Esa dirección no identifica qué aplicación envió la solicitud, especialmente detrás de un proxy o en una red interna de contenedores.

Se separaron los permisos administrativos del receptor de eventos: el panel exige una sesión UISP con permisos, y los webhooks requieren por defecto una clave compartida que confirme que el emisor la conoce. Es una opción de autenticación del plugin, no una exigencia nueva de UISP ni una contraseña de MikroTik. La deduplicación de eventos sigue a cargo de la cola; el secreto no es una firma HMAC.

Para conservar el funcionamiento sin esta comprobación adicional se añadió **Permitir webhooks sin secreto (compatibilidad)**. Activarla mantiene la integración automática y la validación de eventos, pero no autentica al emisor. No concede permisos administrativos ni se activa automáticamente ante una clave incorrecta. Borrar el secreto no equivale a activar esta opción.

La [guía completa en inglés](WEBHOOK_SETUP_EN.md) contiene la explicación, ambos modos, pasos de configuración y diagnóstico; también se incluye dentro del texto del PR.

### Generar y configurar la clave

El secreto es una clave compartida que se genera para esta instalación; no se obtiene de MikroTik ni de la API de UISP. Se puede generar con un gestor de contraseñas o con PowerShell en el equipo del administrador:

```powershell
$webhookBytes = New-Object byte[] 32
$webhookRandom = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$webhookRandom.GetBytes($webhookBytes)
$webhookRandom.Dispose()
[BitConverter]::ToString($webhookBytes).Replace('-', '').ToLowerInvariant()
```

1. Copiar **sólo la clave** de 64 caracteres al campo **Secreto del webhook** en la configuración del plugin dentro del administrador de plugins de UISP y guardar. **No pegar la URL en este campo**: 2.4.12 lo detecta con `webhook_secret_contains_url`. Mantener desactivada **Permitir webhooks sin secreto (compatibilidad)** para utilizar este modo.
2. En **Webhooks → Terminales**, editar el terminal dirigido a este plugin y establecer `https://SU_SERVIDOR/crm/_plugins/ros-plugin/public.php?webhookToken=EL_SECRETO_GENERADO`. Sustituir el marcador por el mismo valor, sin espacios. Si la URL ya contiene otros parámetros, añadir el token con `&`.
3. Comprobar que el terminal recibe los cambios de servicios y clientes necesarios. Si el terminal generado automáticamente no permite editar su URL, crear uno manual equivalente y evitar entregas duplicadas del anterior.
4. Enviar una entrega nueva de un servicio de prueba y revisar la respuesta específica del terminal de este plugin. HTTP 403 indica que la autenticación sigue fallando; el log precisa si falta configurar el secreto, contiene una URL, falta el token o no coincide. Una respuesta 200 también debe revisarse por su campo `data.provisioning` y por el estado final en RouterOS.
5. Probar suspensión y reactivación, comprobando perfil, sesión y tráfico. Si el evento ya fue rechazado, guardar el secreto no reenvía automáticamente ese evento; usar una entrega nueva/reintento disponible en UISP.

No utilizar literalmente `EL_SECRETO_GENERADO` ni la contraseña del router. Guardar la clave para poder mantener ambos extremos coordinados; no incluirla en capturas o registros compartidos.

Si el terminal ya envía una cabecera de autenticación, actualizarla o retirarla al utilizar el token de la URL. El receptor prioriza Bearer, después `X-Webhook-Token` y finalmente `webhookToken`; dos valores distintos pueden provocar un rechazo.

La clave hexadecimal del ejemplo no requiere escape especial. Si se usa una clave con símbolos, codificar sólo su valor para la URL con `[Uri]::EscapeDataString($suSecretoWebhook)` y conservar la clave original sin codificar en el campo del plugin. Caracteres como `+`, `&` y `#` pueden alterar la URL o el valor recibido si se pegan directamente.

## Casos de integración

### Opción de compatibilidad sin secreto

En los ajustes del plugin de UISP, activar **Permitir webhooks sin secreto (compatibilidad)** y guardar. El terminal puede utilizar `https://SU_SERVIDOR/crm/_plugins/ros-plugin/public.php` sin token. No es necesario borrar el secreto guardado; en este modo no se comprueba. Los eventos válidos se aceptan sin autenticar al emisor, por lo que cualquiera que alcance esa URL puede intentar enviarlos. Se conservan POST/JSON, validación de entidad/acción/UUID/ID y permisos de sesión para las acciones administrativas.

La opción está desactivada cuando falta o es falsa; instalar la actualización no la activa. Para volver al modo con secreto, comprobar primero que el terminal envía la clave guardada, desactivar la opción y probar una entrega nueva. Una clave incorrecta no activa automáticamente la compatibilidad.

### Comprobar una suspensión sin confundir recepción con aplicación

Suspender un único servicio de prueba y esperar su resultado antes de reactivarlo. Revisar la entrega `suspend` y su `edit` asociado. Desde 2.4.12, la respuesta y el registro `webhook_result` incluyen `serviceId`, `resolvedDeviceId`, `desiredStatus`, `appliedStatus`, `provisioning`, `jobKeys` y `jobCounts`. `routing` indica la clave de Site configurada y si estaba presente en el evento. Los registros se guardan con depuración desactivada. `webhook_processing_failed` identifica errores que impidieron completar el procesamiento, incluidos `service_site_not_configured` y `service_credentials_missing`.

| `provisioning` | Interpretación |
| --- | --- |
| `applied` | La ejecución confirmó los comandos. Comprobar además perfil, sesión y tráfico en RouterOS. |
| `pending` | Quedan trabajos por completar; consultar su error y reintento en Trabajos. |
| `already_applied` | No se detectó una diferencia que requiriera aplicar el evento. |
| `duplicate_completed` | Esa entrega ya tiene trabajos completados. |
| `cancelled` | Se reconoce una entrega cancelada previamente, sin volver a ejecutar su operación anterior. |
| `ignored_unmanaged` | Servicio sin Site gestionado o sin recursos que retirar. |
| `deferred` | Estado diferido o barrera de baja que este evento no puede revocar. |
| `credentials_requested` | Se solicitó generar credenciales; todavía no confirma aprovisionamiento. |
| `cleared` | Se completó la retirada de los recursos propios. |
| `failed` | El procesamiento no se completó; revisar el error correspondiente. |

Los estados de servicio `3` y `1` corresponden respectivamente a suspendido y activo. `appliedStatus` puede ser `null` si no existe una instantánea aplicada verificable. Los conteos sólo incluyen trabajos conocidos por ese evento; cero no demuestra ausencia de escrituras previas. La tabla Trabajos muestra pendientes, por lo que un trabajo terminado puede desaparecer de ella. Si hay pendientes, la ejecución programada permite reintentarlos; el primer intento del webhook no depende de esperar al cron.

Durante la prueba real del 11 de octubre de 2026, el usuario confirmó que la ausencia aparente de cambios era un problema de logs. La captura posterior mostró accesos por API y modificaciones de perfil/cuenta PPP. Ante un caso similar, distinguir el registro del plugin del registro de RouterOS y contrastar ambos con el perfil efectivo de la cuenta, sesión y tráfico; la ausencia de una línea visible no demuestra que el comando no se ejecutó. No se necesitó otro parche para ese incidente sobre 2.4.12.

### Matriz de pruebas

Desde 2.4.13, **Sincronizar y reconectar cuentas** en Dispositivos reconecta siempre las sesiones PPP/Hotspot administradas del router elegido. Probarlo primero con un router de laboratorio: repetir el botón vuelve a cerrar esas sesiones. Los eventos explícitos de suspensión/reactivación hacen lo mismo para su servicio; el `edit` asociado de sólo estado ya aplicado devuelve `already_applied` si no hay otro cambio o trabajo pendiente. Se mantienen dos entregas HTTP de UISP, pero ese par no necesita dos aplicaciones al router. DHCP conserva su aprovisionamiento habitual, sin inventar un cierre de sesión PPP.

| Caso | Prueba | Resultado esperado |
| --- | --- | --- |
| 1 | Abrir/guardar plan y editar un valor mientras espera el guardado. | Modal estable; el segundo valor queda como borrador. |
| 2 | Aplicar velocidad modificada y plantilla de burst. | Perfil `Nombre completo [UISP#ID]` propio; límite normal/burst/umbral/ventana correctos. Medir tras reposo y con tráfico sostenido. |
| 3 | Usar dos planes con el mismo nombre. | Perfiles distintos por ID; editar uno no cambia el otro. |
| 4 | Suspender desde UISP. | Estado suspendido y bloqueo real según firewall; perfil externo intacto y límite individual si se configuró. |
| 5 | Registrar pago/reactivar. | Perfil activo, conectividad restaurada y retirada de la cola adicional de suspensión. |
| 6 | Router fuera de línea: activar → suspender → activar, después reconectar. | Trabajos visibles sin falso éxito; el estado final coincide con la última intención. |
| 7 | Provocar un fallo intermedio en CHR, restaurar permisos y reintentar. | Trabajo recuperable, sin liberar la reserva antes de completar los comandos. |
| 8 | Repetir el mismo UUID y enviar eventos que omitan campos no modificados. | Deduplicación y conservación de plan, credenciales/direcciones; una intención nueva usa UUID nuevo. |
| 9 | Guardar velocidad mientras se suspende el mismo cliente. | El plan se aplica mediante trabajos y el estado final continúa suspendido. |
| 10 | Cambiar sólo nombre/apellido/empresa. | Campos omitidos conservados y una sola cola DHCP; `null` explícito limpia el campo. |
| 11 | Deshabilitar contención y guardar. | Perfiles `parent-queue=none`, colas `parent=none` tras aplicar; ratio antiguo `0` no bloquea el editor. |
| 12 | Retirar router con servicios/trabajos, luego uno vacío. | El primero se rechaza; el segundo puede retirarse. |
| 13 | Usuario/contraseña `0`, contraseña con espacios, IAID `0`. | Valores literales conservados y autenticación/binding correctos. |
| 14 | Un servicio con IP inválida y otro válido. | Falla sólo el inválido sin perder su reserva anterior. |
| 15 | /56, /64, dirección manual sin máscara y exclusión IPv6 amplia. | Tamaño propio de cada router y exclusión sin recorrer cada prefijo. |
| 16 | Huérfanos con router inaccesible; TLS válido/inválido/autofirmado. | Error de lectura visible; certificado inválido rechazado; autofirmado sólo con opción explícita y host compatible. |
| 17 | API UISP lenta o redirigida en laboratorio. | Timeout recuperable y rechazo de redirecciones; las siguientes peticiones siguen funcionando. |
| 18 | Restaurar mientras se intenta editar; reiniciar worker entre intentos. | Exclusión de mantenimiento y recuperación sin duplicar una petición viva. |
| 19 | Claro/oscuro/sistema, seis idiomas y recarga del navegador. | Preferencias persistidas localmente; controles y ayudas legibles, sin guardar configuración al cambiar idioma. |
| 20 | Cambiar idioma con valores pendientes; alternar General/Atributos rápidamente. | Borrador conservado y última elección visible; una respuesta antigua no sustituye el formulario. |
| 21 | Dos servicios intentan utilizar el mismo usuario/MAC/DUID; cuenta manual existente. | Conflicto visible antes de alterar al propietario anterior; no desconectar una sesión ajena. |
| 22 | Colas/perfiles manuales con nombres reservados. | Conflicto y conservación del objeto. Padres nuevos `uisp-plan-ID-parent` propios. Revisar límites/orden de los padres antiguos. |
| 23 | Terminar/eliminar con router fuera de línea, luego guardar plan/configuración y reiniciar worker. | Se completa la retirada al reconectar; no se recrea desde caché vieja. Una activación nueva de UISP sí puede reactivarlo. |
| 24 | Actualizar una copia con terminaciones pendientes o completadas de la cola anterior. | Se recupera su intención terminal. Actualizaciones ambiguas quedan pendientes hasta un evento de servicio nuevo; migraciones delete+update no se confunden con bajas. |
| 25 | A falla después de escribir un perfil compartido; aplicar un plan nuevo a B; reintentar A. | B conserva la velocidad nueva. A conserva su identidad/IP original; padres cuentan una sola vez cada servicio y mantienen direcciones todavía aplicadas. |
| 26 | A DHCPv6 falla preparado; cambiar pool/tamaño del router y aplicar B de otro plan; reintentar A. | Pools propios por revisión; el de B no cambia y los bindings mantienen sus prefijos. El `uisp-pool` histórico queda intacto. |
| 27 | Mover un servicio de prueba a otro cliente, si la instalación admite ese flujo. | Limpieza del propietario anterior antes de aplicar el nuevo, sin duplicar cuentas. |
| 28 | Servicio de otro proveedor sin site con usuario/IP documental, incluyendo CIDR. | Atributos intactos, sin trabajos, generación automática de credenciales, reservas ni `invalid_cached_address`; sincronizan los demás servicios. |
| 29 | Añadir site y después retirarlo mientras existe una migración parcialmente aplicada. | Alta sin router ficticio; retirada de todas las identidades que pudieron crearse. Las reservas no se liberan antes de confirmar cada limpieza. |
| 30 | IP manual con `/30` o `/32`, luego IP sin máscara y atributo vacío. | La máscara se rechaza sin convertirla; el host válido se conserva y el campo vacío usa el pool configurado. |
| 31 | Plan de prueba con datos numéricos corruptos/desbordados y otro servicio válido. | Continúa el válido; aviso parcial con conteos y servicio/plan/campo. Ediciones inválidas revierten la fila y no generan nuevas solicitudes. |
| 32 | Actualizar un perfil técnico propio de 2.4.8 y otro perfil de nombre completo sin propietario. | El propio se renombra al nombre completo con el mismo `.id`; el externo permanece. Verificar referencias PPP/Hotspot y sesiones durante el renombre. |
| 33 | Renombrar un plan mientras queda un trabajo preparado anterior. | El reintento utiliza el nombre vigente sin revertir el perfil ni cambiar la identidad/IP/credenciales individuales. |
| 34 | Plan de laboratorio con 5/25 Mbps válidos y un porcentaje inválido. Abrir, cancelar, corregir y guardar. | Aviso del campo real sin convertirlo en cero; abrir/cancelar no cambia datos. Restablecer sólo cambia el borrador y Guardar valida el plan completo. El log incluye causa y valor seguro. |
| 35 | Plan archivado aún utilizado con campos opcionales vacíos heredados. | Se conserva el plan local; vacíos equivalen a no configurado antes del cálculo, sin generar garantías del 100%. Un override vacío hereda la velocidad de UISP. |
| 36 | Aplicar un servicio PPP/Hotspot y repetir una actualización ordinaria o reconstrucción general sin cambios. | La sesión conserva su uptime; no aparece una nueva desconexión ordenada por el plugin. La acción manual por dispositivo se comprueba por separado en el caso 45. |
| 37 | Cambiar un perfil compartido utilizado por dos servicios; provocar un fallo después de escribirlo y reintentar. | Cada servicio recibe la política nueva y conserva la reconexión pendiente hasta completar su trabajo. Repetir sin cambios ya no desconecta. |
| 38 | Enviar un `edit` de suspensión/reactivación cuando la caché ya tenga el estado nuevo; luego probar un evento sin estado tras una baja. | Se aplica el cambio pendiente del primer caso; el segundo no recrea el servicio terminado. |
| 39 | Con compatibilidad desactivada, entregas con secreto sin configurar, URL pegada como secreto, token ausente, token incorrecto y luego credencial correcta. | Las inválidas devuelven 403 sin trabajos ni escrituras al router y con motivo seguro en el log. La válida entra al flujo; comprobar su resultado y el perfil/sesión del cliente. |
| 40 | Ejecutar un trabajo que falle por router inaccesible y volver a consultarlo. | Tabla actualizada con último error, estado, intentos y próximo reintento. El aviso original persiste si también falla la consulta. |
| 41 | Activar compatibilidad sin secreto, probar evento válido y acción administrativa sin sesión; desactivarla. | Sólo el evento válido pasa sin clave. El panel conserva permisos. Al desactivar vuelve a exigirse el secreto; una actualización no cambia el modo por sí sola. |
| 42 | Cambiar el Site de un servicio aplicado por uno desconocido o ambiguo; comparar con quitarlo explícitamente. | Site desconocido/ambiguo falla sin modificar caché, reservas ni cuenta. Quitar el Site explícitamente conserva la limpieza prevista. |
| 43 | Suspender/reactivar con depuración desactivada y repetir el evento; probar identidad ausente con generación automática desactivada. | Resultados y router resuelto visibles; duplicado identificado; falta de identidad produce error. Sin credenciales ni cuerpo del evento en los registros nuevos. |
| 44 | Renombrar el router y entregar un evento con Site actual válido y nombre anterior obsoleto. Después probar un router anterior retirado. | La instantánea aplicada recupera la identidad anterior; los valores actuales prevalecen. Si falta el router anterior, su limpieza permanece pendiente hasta restaurarlo, sin escribir en otro endpoint. |
| 45 | Pulsar dos veces Sincronizar y reconectar cuentas del mismo router, con sesiones de prueba reconectadas entre ambas; conservar otra cuenta en otro router. | Cada ejecución cierra las sesiones administradas seleccionadas después de actualizar sus cuentas, incluso sin cambios de perfil. El otro router y cuentas ajenas no se modifican. Un fallo previo a la escritura no desconecta. |
| 46 | Suspender y reactivar con sus `edit` asociados; repetir un UUID; después enviar un cambio real adicional y probar con el primer trabajo fallido. | Cada transición explícita fuerza la reconexión; el `edit` posterior de sólo estado ya confirmado no crea otro trabajo ni otra escritura. El mismo UUID no repite el corte. El cambio adicional y el trabajo pendiente siguen aplicándose/reintentándose. |
| 47 | Con depuración desactivada, aplicar una cuenta de prueba con perfil externo válido; después simular lectura fallida/ambigua, incluyendo PPP y Hotspot. Reintentar sólo su trabajo. | El perfil válido pasa. Los errores indican motivo, ruta y nombre solicitado; aparecen en Trabajos y log, con identificador del intento y próximo reintento, sin credenciales. El rechazo no modifica la cuenta ni cierra la sesión. El resumen manual coincide con los contadores y consultar la cola no genera nuevos intentos. |
| 48 | En router de laboratorio sin el perfil de suspensión, configurar perfil y lista `disabled`; suspender y después reactivar una cuenta. Repetir con un trabajo preparado por una versión anterior. | Se crea un único perfil con address-list `disabled`, dirección local PPP y límite configurado; la cuenta suspendida lo utiliza y se reconecta. La IP conectada aparece en la lista y las reglas existentes bloquean el tráfico esperado. Reactivar restaura el perfil del plan. No hay IP ficticia ni modificación de un perfil existente. |
| 49 | Seleccionar un trabajo nuevo bloqueado por otro anterior del mismo servicio; probar fallo y recuperación. Seleccionar para borrar pendientes preparados y sin preparar, junto con uno en ejecución. | Ejecutar intenta primero el anterior, sin otros servicios ni versiones posteriores. El panel indica Q bloqueante y errores reales. Eliminar cancela todos los seleccionados pendientes y conserva el que está en ejecución. Los contadores y el log coinciden; cancelar no envía comandos al router. |
| 50 | Sincronizar perfiles con el comentario antiguo y repetir la sincronización; renombrar un plan desde UISP. | El comentario cambia a `Plan #ID`, sin branding, conservando la misma `.id`. No hay duplicados ni adopción de perfiles manuales; el renombre desde UISP mantiene el identificador del perfil. |
| 51 | Seleccionar trabajos y pulsar Refrescar; activar otro intervalo automático, salir de Trabajos, ocultar la pestaña y volver. Ejecutar/Eliminar durante el auto refresco. | Se conserva la selección de Q existentes y la preferencia de intervalo. Las consultas no crean ni ejecutan trabajos, no se solapan con acciones y se pausan fuera de la vista visible. Una respuesta antigua no reemplaza resultados más recientes. |
| 52 | En laboratorio, preparar una retirada nueva sin ejecutarla, con comandos copiados de una cuenta aplicada y un reemplazo pendiente. Seleccionar ambos y eliminar. Comparar con una retirada ya intentada y con una importada. | Todos los pendientes seleccionados pueden cancelarse, incluidos intentados e importados; los sucesores dependientes también se cancelan y se cuentan correctamente. No hay comandos al router ni nuevos trabajos. Estado aplicado, recursos posiblemente modificados y reservas siguen registrados para una recuperación posterior. |
| 53 | Usar un puerto API accesible que rechace el inicio de sesión; comparar con transporte inaccesible y luego credenciales válidas. Reintentar un único trabajo. | El diagnóstico distingue la etapa fallida con códigos fijos, sin credenciales ni respuesta completa. No hay escrituras de aprovisionamiento antes de autenticar. Tras corregir la conexión, el trabajo puede completarse y sus sucesores mantienen el orden. |
| 54 | Dejar un cambio preparado fallido; recuperar el router y enviar un cambio nuevo del mismo servicio por webhook, sin pulsar Ejecutar. Repetir manteniendo el fallo y comparar con otros servicios. | Se intenta el antecesor y luego el cambio nuevo en orden. Si continúa fallando, el cambio nuevo queda pendiente. No se ejecutan otros servicios ni versiones posteriores a la solicitada, y una entrega completada no se repite. |
| 55 | Provocar una actualización parcialmente aplicada, cancelarla desde Eliminar y reiniciar el worker sin enviar otro cambio. Repetir con un trabajo preparado/importado. | Sale de pendientes y no se reintenta. No aparecen nuevos Q ni escrituras de cancelación. Las identidades y reservas siguen registradas tras reiniciar; el contador de intentos del trabajo cancelado no aumenta. |
| 56 | Dejar recursos del mismo servicio aplicados en A y parcialmente creados en B; cancelar la migración. Enviar un cambio nuevo o sincronización completa para un destino C, con otro servicio ajeno en cada router. | Antes de aplicar C se limpian todas las identidades originales de A/B en sus destinos reales, sin ejecutar los sets cancelados ni tocar cuentas ajenas. Cada reserva se conserva hasta confirmar su retirada; el resultado final contiene sólo la configuración nueva. |
| 57 | Después de cancelar un preparado, provocar un fallo en su limpieza posterior; reiniciar entre intentos y después recuperar el router. | El nuevo trabajo mantiene el fallo y no aplica su configuración antes de completar la limpieza. El registro privado, endpoint original y reservas sobreviven al reinicio. Tras recuperar, retira sólo los recursos exactos anteriores y aplica el estado nuevo sin revivir el Q cancelado. |
| 58 | Mantener un trabajo realmente en ejecución y solicitar Eliminar; comparar con el mismo trabajo cuando termine fallido y vuelva a pendiente. | Durante la ejecución se rechaza su cancelación y se conserva la exclusión de la petición activa. Una vez pendiente puede cancelarse, deteniendo reintentos sin comandos ni liberación insegura de recursos. |
| 59 | Provocar una creación parcial del perfil de suspensión, cancelar su trabajo y enviar una intención nueva. Comparar perfil incompleto, perfil con valores verificados y perfil ausente. | La evidencia congelada se conserva. Un perfil existente que no coincide mantiene el fallo antes de modificar cuentas/sesiones. La limpieza no crea ni sobrescribe perfiles de suspensión; un perfil ausente no se recrea para terminar la operación cancelada. |
| 60 | Reentregar el UUID de un evento cuyos trabajos se cancelaron; luego enviar una intención realmente nueva para el servicio. | El UUID cancelado no revive trabajos ni reproduce comandos. La intención nueva puede iniciar la limpieza de los recursos anteriores y sólo después aplicar su configuración vigente. Las barreras de baja continúan exigiendo una activación válida cuando corresponda. |
| 61 | Tras cancelar un preparado, ejecutar una operación que sólo cambie colas y consultar reservas/recuperación. Después solicitar sincronización completa del servicio. | La operación de colas no consume el registro privado de recursos cancelados ni libera sus reservas. La sincronización completa sí verifica y limpia esos recursos antes del aprovisionamiento nuevo. |
| 62 | Con un servicio que use un plan archivado, dejar en una copia de la base su nombre vacío y sus velocidades de respaldo. Sin abrir Planes, sincronizar el router. | Se consulta el plan por ID, se conserva archivado en UISP y se recuperan nombre/velocidades base. El perfil administrado existente se renombra sin duplicarse. Las cuentas mantienen IP y credenciales. Sin overrides, el plan 38 muestra 5M/25M y su nombre completo. |
| 63 | Repetir la recuperación del plan archivado con overrides, porcentajes y burst locales. Incluir un trabajo preparado anterior. | Los ajustes locales permanecen intactos; el perfil compartido se actualiza con los valores efectivos y las cuentas preparadas conservan sus datos individuales. |
| 64 | Simular fallo de UISP, ID de respuesta distinto o velocidades ausentes durante recuperación; comparar con un plan válido y con retirada de cuenta. | El catálogo anterior no se reemplaza parcialmente. El servicio sin plan verificable falla sin escribir un perfil de respaldo y otros servicios válidos pueden continuar. La retirada no depende de consultar el plan. |
| 65 | Recuperar un plan archivado con `servicePlanType: "Internet"` y repetir con `"internet"`. Comparar con tipo General y con velocidad inválida. | Las dos formas Internet se aceptan y recuperan nombre/velocidades. Los datos inválidos se rechazan con `plan_metadata_failed`, ID, campo y causa, sin exponer el cuerpo ni credenciales. Comprobar especialmente el servicio 814/plan 38 del router 2 después de instalar 2.4.19. |
| 66 | En una copia aislada de una base heredada, conservar dos servicios con la misma IPv4 y otros dos con la misma dirección/prefijo IPv6, incluidos routers distintos. Registrar hashes de bases, respaldos y estado; abrir el panel como administrador al instalar 2.4.20. | Aparece el aviso en español con contador de conflictos y tabla de familia, dirección e IDs. La unicidad sigue siendo global y la instalación se detiene antes de inicializar la cola o rotar respaldos. Las asignaciones, configuración, versión y estado se conservan; no hay cambios en cuentas ni sesiones del router. |
| 67 | Preparar copias antiguas con direcciones en `services.address` y `services.prefix6`, sin tabla `network` o con sus columnas antiguas. Añadir colisiones entre la importación y las reservas existentes; comparar con un ID ya presente en `network`. | La comprobación proyecta sólo las filas que se importarían y detecta los conflictos antes de omitir datos por `INSERT OR IGNORE`. Un ID ya presente en `network` conserva su fila y no se considera una importación nueva. El rechazo no altera el origen ni los respaldos; una copia sin conflictos migra correctamente. |
| 68 | Descargar el diagnóstico y volver a comprobar varias veces. Comparar panel autorizado, usuario sin permisos y POST/webhook válidos; usar muchas colisiones y texto arbitrario en una dirección de la copia. | La descarga entrega JSON acotado con grupos e IDs y señala los datos omitidos por sus límites. El panel escapa texto y mantiene sus permisos; el usuario no autorizado no recibe detalles. POST/webhook conservan JSON con `duplicate_network_addresses_resolve_before_upgrade`. Consultar el aviso no corrige IP ni envía comandos a RouterOS. |
| 69 | En la copia de laboratorio, revisar el diagnóstico contra los servicios y las IP reales, corregir explícitamente las asignaciones en conflicto y volver a comprobar. Repetir dejando un conflicto pendiente y comparar con una restauración de respaldo que contenga duplicados. | La instalación continúa sólo cuando desaparecen los conflictos. Una colisión pendiente o un respaldo con duplicados sigue rechazándose con diagnóstico específico y conserva el estado anterior. La corrección requiere una decisión explícita del operador; el aviso no reasigna ni repara direcciones automáticamente. |

## Diagnosticar una conexión pendiente

En Dispositivos, **Puerto accesible** indica que respondió la comprobación del puerto; no valida usuario, contraseña, permisos API ni TLS. El mensaje anterior `device connect failed` no permite elegir entre esas causas. Desde 2.4.16, reintentar un trabajo muestra un motivo más concreto. Corregir la conexión del dispositivo y reintentar la actualización pendiente; no generar otra sincronización para investigar el mismo error. Las tareas posteriores del servicio seguirán esperando hasta completar o cancelar sus antecesores de forma segura.

| Código | Interpretación |
| --- | --- |
| `router_tcp_connect_failed` | No se pudo establecer la conexión TCP al destino del trabajo. |
| `router_tls_connect_failed` | No se pudo establecer la conexión TLS; puede ser transporte o negociación, sin atribuir automáticamente el fallo al certificado. |
| `router_login_rejected` | La API respondió con un rechazo explícito de acceso. Revisar usuario, contraseña y permisos de API. |
| `router_login_timeout` | Se agotó el tiempo esperando la respuesta de acceso. |
| `router_login_connection_closed` | La conexión se cerró durante el acceso. |
| `router_login_protocol_error` | La respuesta de acceso no tiene el formato esperado. |
| `router_login_write_failed` | No se pudo enviar la solicitud de acceso. |

Para retirar Q15 y Q16 de la cola, instalar 2.4.17, recargar el panel, seleccionarlos y pulsar **Eliminar**. No hace falta ejecutarlos ni sincronizar para cancelarlos. Corregir la conexión del router antes de aplicar cambios nuevos. Si se quiere completar una actualización pendiente en vez de cancelarla, seleccionarla y pulsar **Ejecutar**; no ejecutar retiradas de cuentas que se pretendía cancelar.

Desde 2.4.17, **Eliminar cancela cualquier pendiente**, aunque tenga intentos, preparación, limpieza intermedia o proceda de una importación. Sólo los trabajos en ejecución siguen protegidos. Cancelar detiene reintentos, no corrige la conexión ni revierte RouterOS, y no crea trabajos. Si pudo haber cambios previos, se conservan de forma privada sus identidades, comandos y destinos exactos, junto con las reservas. Una intención nueva o sincronización completa posterior limpia esos recursos antes de aplicar la configuración nueva; no reproduce los sets cancelados. Si falla, sigue siendo recuperable tras reiniciar. Las operaciones que sólo ajustan colas no consumen este registro y una reentrega del UUID cancelado no revive la operación. La restricción de cancelación de preparados/intentados/importados de 2.4.16 ya no rige.

### Interpretar login/logout de la API

El código abre y cierra la conexión API por lote de un servicio. Una sincronización con varios servicios puede mostrar varios pares de login/logout; las consultas y limpiezas de recursos anteriores pueden abrir conexiones adicionales. Esos mensajes reflejan la sesión API del plugin y no prueban por sí solos un corte PPP. Para confirmar un corte del cliente, revisar su sesión PPP/Hotspot, uptime y eventos correspondientes. La 2.4.17 no introduce una conexión compartida entre lotes.

## Diagnosticar los perfiles pendientes

Desde 2.4.14, un rechazo de perfil externo genera `router_session_profile_error` con servicio, router, protocolo, ruta, nombre solicitado y motivo. `missing` significa que la consulta devolvió cero coincidencias; `lookup_failed` indica que la lectura falló; `ambiguous` significa varias coincidencias, e `invalid_identity` una fila sin identidad válida. El mensaje guardado en Trabajos contiene también la ruta y el nombre solicitado, con códigos `router_session_profile_*`.

PPP busca en `/ppp/profile`; Hotspot en `/ip/hotspot/user/profile`. Desde 2.4.15, si falta el perfil configurado para suspensión, se crea con su lista/límite configurados y se verifica antes de usarlo. Una lectura fallida o ambigua sigue rechazándose. Un perfil existente conserva sus parámetros: revisar allí la address-list y las reglas del firewall. La lista dinámica se poblará al conectarse una cuenta; no se genera una entrada ficticia para mostrarla vacía.

Para recuperar los pendientes anteriores, instalar la versión nueva y ejecutar inicialmente un trabajo del servicio afectado; se procesarán sus antecesores necesarios. No es necesario borrar la cola ni generar otra sincronización completa. Después del piloto, ejecutar los pendientes restantes. El botón del router sigue forzando reconexiones de sus cuentas administradas, por elección del operador.

Un intento fallido que quedó persistido genera además `job_attempt_failed`, con identificadores, código seguro, intentos y próximo reintento. La sincronización manual produce `provisioning_result`, con su alcance y cantidades. Los tres registros funcionan sin depuración. Después de instalar la actualización, reintentar un único trabajo pendiente; los mensajes históricos no se reescriben. La sincronización completa fuerza nuevamente la reconexión de todas las cuentas administradas seleccionadas.

## Tráfico, objetos anteriores y recuperación

Después de suspender y reconectar, comprobar posición **y contadores** de la cola individual. El perfil PPP externo puede insertar su cola dinámica por delante; revisar su configuración. Comprobar FastTrack, colas superiores, IPv4/IPv6 y tráfico real: ver una cola no demuestra que el tráfico la atraviese.

Los perfiles/padres antiguos y el pool `uisp-pool` no se borran automáticamente. Revisar manualmente sus dependencias, targets, límites y posición después de migrar los servicios; un objeto anterior puede seguir limitando tráfico aunque el nuevo sea correcto.

Si el piloto falla, pausar worker/webhook y conservar datos/errores sin credenciales. Volver requiere el paquete y respaldos compatibles, junto con una copia coherente de `jobs.db` y el router. `jobs.db` conserva también los registros privados de recursos de trabajos cancelados; no borrarla para vaciar la tabla. Restaurar SQLite no deshace comandos ya ejecutados en RouterOS. Una base 2.4.17 será rechazada por código anterior; no desactivar esa comprobación para forzar el regreso.
