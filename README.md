# UISP/UCRM Plugin for RouterOs Services

[Complete English changelog](docs/CHANGELOG_EN.md) · [Texto completo para el PR](docs/PR_DESCRIPTION.md) · [Webhook setup and rationale](docs/WEBHOOK_SETUP_EN.md) · [Mejoras en español](docs/MEJORAS.md)

## Candidato 2.4.28 — mostrar el resultado del cambio de IP y volver a verificar

Después de aplicar una corrección de IP o una exclusión histórica revisada, el editor muestra un resultado propio. Conserva la IP anterior, el cambio solicitado y el valor actual comunicado por UISP. Distingue el cambio confirmado, la IP anterior que sigue presente, un valor actual diferente, el servicio inexistente y la evidencia sin comprobar. Un resultado confirmado conserva esa identificación si falla la consulta posterior o si después se observa otro estado.

El panel refresca el conflicto seleccionado y su contador mediante una verificación acotada, y consulta de nuevo el informe local del monitor. No inicia una revisión completa del CRM. **Verificar nuevamente** sólo hace consultas frescas: no repite la escritura ni reutiliza el token consumido. Si una consulta posterior coincide con una solicitud incierta, indica que UISP ahora muestra el estado solicitado, sin afirmar que la escritura original se confirmó. La evidencia desconocida o una identidad de cliente diferente no autorizan un resultado exitoso. Un nuevo cambio exige otra revisión y confirmación.

Un servicio existente cuyo estado terminado se compruebe de nuevo permite pasar explícitamente de un cambio sin confirmar a **Resolver asignación histórica**. Esa alternativa exige su propia revisión y confirmación, y conserva el historial del CRM y los routers. El resultado histórico se contrasta con el mismo cliente, estado terminado, familia, IP exacta y marca revisada vigente. No se ofrece con servicio inexistente, reactivado, reasignado o sin comprobar. La limpieza local sigue siendo una acción revisada independiente, con respaldo y protecciones de propiedad.

Las direcciones de los archivos del panel llevan una revisión nueva para solicitar el JavaScript y los estilos actualizados al cargar el panel nuevo.

Validación 2.4.28 aprobada: **35 suites aisladas**, sintaxis de **48 archivos PHP propios** y verificación del instalador de 360 entradas, 127 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.28.json`. SHA-256: `ab16abdbc2b2e63f5a2c6418d920766e723e8c313aa80a285ec211f4ea0f4956`. Se comprobó con datos ficticios en navegador el resultado explícito, la nueva verificación de sólo lectura y la alternativa histórica revisada para un servicio terminado. No se modificaron CRM ni RouterOS de producción.

## Candidato 2.4.27 — distinguir conflictos resueltos y reservas limpiadas

Un grupo comprobado por completo pasa inmediatamente al apartado verde **Conflictos resueltos** y deja de sumar al aviso y al contador de pendientes. Exige IDs de todos los servicios e identidades coincidentes, evidencia conocida de la familia y ausencia de duplicado operativo. Los grupos sin comprobar, truncados, reasignados o fuera de la muestra siguen pendientes. Al resolver todos los grupos revisados, la lista lo indica y conserva el alcance y la fecha de la revisión automática completa.

El monitor distingue el inicio de la revisión completada de su fecha de publicación. Las páginas de una revisión iniciada antes de una verificación seleccionada más reciente no vuelven a presentar inmediatamente el aviso antiguo. Una revisión posterior, cambio de grupo/identidad, reactivación del propietario histórico o cambio de mapeo revoca la prueba correspondiente. La verificación seleccionada permanece en memoria del panel abierto, separada de la navegación guardada; al recargar se usa el resultado automático y la verificación explícita fresca. No se guardan datos de clientes en el navegador ni se inicia un escaneo completo desde las consultas del panel.

La limpieza local distingue **Ya limpiada** tras una limpieza confirmada de **Sin reserva local** tras comprobar la ausencia por familia. Consulta tanto la fila de red como los campos antiguos del servicio, sin confundir valores como cero con un campo vacío. La estructura desconocida, lectura fallida, asignaciones de la otra familia y propiedad aplicada mantienen sus protecciones. Una revisión CRM sin reservas devuelve estado neutral, sin token ni consultas a UISP. Después de limpiar se verifica una sola selección acotada del grupo original; un duplicado real que continúe en CRM conserva su aviso, y un fallo de esa consulta conserva el resultado de limpieza sin inventar resolución del CRM.

Los metadatos del monitor se actualizan únicamente desde el proceso programado, de sus estructuras exactas conocidas v3/v4 a v5. La lectura del panel no migra ni escribe esas estructuras anteriores. Se conserva el progreso por equipo de las sincronizaciones y las protecciones del servidor.

Validación 2.4.27 aprobada: **35 suites aisladas**, sintaxis de **48 archivos PHP propios** y verificación del instalador de 360 entradas, 127 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.27.json`. SHA-256: `fcabbdb8b67dc77d2cbefa97e339bad4270eabb5a885199f3bc01d3f32aa8b88`. Se comprobó con datos ficticios en navegador el apartado verde de resueltos, el contador pendiente y el indicador de reserva limpiada. No se modificaron CRM ni RouterOS de producción.

## Candidato 2.4.26 — resolver conflictos del CRM y sincronizar equipos sin bloquear el panel

La sincronización muestra su progreso en la fila del equipo elegido. El panel sigue disponible y permite iniciar la sincronización de otros equipos. Evita enviar dos veces el mismo equipo mientras su petición está activa, incluso al filtrar o regresar a la sección. Los resultados identifican el equipo y conservan los errores parciales o trabajos pendientes. Se mantienen los bloqueos de servicio y router físico; un destino físico compartido puede dejar trabajo para reintento. La sincronización manual sigue reconectando las sesiones PPP/Hotspot administradas del equipo elegido.

Los propietarios del monitor automático ofrecen verificación explícita, corrección y revisión de limpieza local. La verificación consulta las IP actuales y la identidad/archivo del cliente, con fecha y alcance separados de la última revisión automática completa. Una comprobación seleccionada no confirma que todo el CRM esté libre de conflictos. También se pueden corregir los atributos inválidos señalados por el monitor.

**Editar IP en CRM** permite revisar un cambio o una retirada explícita del atributo IPv4/IPv6 elegido. Aplicar exige permiso administrativo de edición, solicitud del mismo origen, vista previa privada con vencimiento, comprobación fresca del servicio/catálogo y confirmación del servicio, familia y valor previsto. Si cambió la evidencia, exige otra revisión. Sólo se envía el atributo IP configurado y se vuelve a consultar el resultado; una respuesta incierta queda sin confirmar y no se reintenta silenciosamente. Quitar la IP conserva el servicio. Esta acción no aprovisiona directamente routers; los webhooks normales de edición de UISP pueden activar el flujo existente.

Un registro privado de corrección evita que la exportación diaria restaure una reserva anterior sobre el cambio deliberado del CRM. La limpieza local mantiene su revisión y confirmación independientes, con respaldo, evidencia fresca y protecciones de trabajos pendientes, recuperación y propiedad aplicada. Desde el monitor admite reservas locales antiguas sin exigir un duplicado en la base local y conserva las operaciones habilitadas en un panel sano. El cliente archivado o servicio terminado por sí solos no autorizan limpieza.

Para un servicio terminado que UISP no permita editar, **Resolver asignación histórica** exige estado `2` comprobado y una revisión explícita de la dirección elegida. Guarda una decisión privada ligada al servicio, cliente, familia, atributo y valor exactos; no envía PATCH a CRM, reactiva el servicio, borra su historial ni ejecuta comandos en routers. La revisión completa conserva la IP original en un apartado de históricos revisados y separa únicamente esa familia terminada del contador de duplicados operativos. Los demás propietarios actuales siguen vigilados. La excepción deja de aplicarse si la evidencia actual cambia o queda sin comprobar; un servicio activo no la hereda porque su cliente esté archivado. La verificación seleccionada muestra el resultado al terminar, mientras el aviso general espera una revisión completa. Esta clasificación del CRM no confirma que ya se hayan retirado los recursos antiguos de RouterOS.

La limpieza local histórica se revisa por separado, incluso cuando CRM conserva la misma IP: exige que la prueba histórica coincida con una consulta fresca del servicio terminado, y conserva respaldo, exclusión de mantenimiento y protecciones de trabajos y propiedad aplicada. Un trabajo pendiente o cuenta registrada como aplicada impide retirar esa reserva. El mapeo o atributo sin comprobar no autoriza exclusión ni limpieza. El panel lee la estructura anterior v3 del monitor sin escribir; únicamente el proceso programado la actualiza a v4, conservando direcciones y evidencia por familia.

Validación 2.4.26 aprobada: **35 suites aisladas**, sintaxis de **48 archivos PHP propios** y verificación del instalador de 360 entradas, 127 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.26.json`. SHA-256: `b290f01d7ca0f5a2f38ec4cc827096844f49763df92285a8b51d94f27ff90a3b`. La revisión en navegador utilizó datos ficticios para progreso simultáneo, corrección de CRM con revisión/confirmación, resolución histórica de un servicio terminado y limpieza local con el panel disponible. No se modificaron CRM ni RouterOS de producción.

## Candidato 2.4.25 — aviso automático de IP duplicadas en CRM

El proceso programado revisa los servicios de CRM en un monitor independiente, después de procesar los trabajos y fuera de sus bloqueos. Inicia revisiones cada cinco minutos, lee como máximo un catálogo y dos páginas de 500 servicios por ejecución y continúa las colecciones mayores en ejecuciones posteriores. Los cinco minutos indican la frecuencia de inicio; completar un catálogo grande depende de la frecuencia del proceso programado. El presupuesto de 12 segundos impide iniciar lecturas adicionales; una petición iniciada utiliza el límite de 15 segundos del transporte existente.

El panel consulta únicamente ese resultado local cada 30 segundos mientras está visible. Muestra **IP duplicadas en CRM**, fecha de revisión, familias comprobadas y enlaces a los servicios y clientes. Este aviso no activa el modo consulta ni desactiva controles, trabajos, suspensiones o reactivaciones. El aviso de reservas duplicadas que impiden migrar conserva su protección independiente. Conflictos de IP permanece disponible también durante la operación normal y conserva la navegación de 2.4.24.

El monitor publica una colección completa de identidades válidas, con evidencia por familia y direcciones normalizadas. Un atributo inválido o incompleto no impide detectar otros duplicados válidos: se señala como sin comprobar, y sólo se conserva su dirección anterior del mismo servicio como evidencia pendiente. Una IP retirada explícitamente se vacía al completar la revisión. Los errores de transporte o páginas inválidas conservan el último resultado como desactualizado. Se informa de cobertura parcial sin afirmar que todas las IP están libres de conflictos. El monitor guarda únicamente IDs, estado y direcciones necesarias en una base privada; no modifica reservas, caché, cola ni routers.

También se corrige el error repetido «Invalid address in a reserved network»: al volver a aplicar una IP manual exactamente igual a una reserva propia válida, no se adquiere propiedad nueva ni se revisan reservas ajenas. Esto permite suspender o reactivar ese servicio aunque otra fila heredada sea inválida. Crear o cambiar una dirección conserva las comprobaciones de asignación. Las reservas propias inválidas se rechazan antes de usarlas, incluso `"0"` o IPv6 sin longitud explícita. El trabajo y el registro identifican ahora el servicio bloqueante, origen y familia mediante `invalid_network_address`, sin revelar valores arbitrarios ni credenciales.

El error independiente del plan 99 (`invalid_speed` en `uploadSpeed`) sigue indicando un valor de CRM que debe revisarse. El catálogo anterior se conserva; esta corrección no inventa velocidades ni cambia planes para ocultar ese error.

Validación 2.4.25 aprobada: **34 suites aisladas**, sintaxis de **46 archivos PHP propios** y verificación del instalador de 358 entradas, 125 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.25.json`. SHA-256: `e12899924c05f8c739deb30004d5430cd0ee6c4113683579285fd7cd1c9f60e6`. Se comprobó en navegador el aviso independiente, las operaciones habilitadas, los enlaces y las incidencias de dirección con datos sintéticos. Las regresiones verifican la continuidad de servicios ya asignados y la conservación segura de evidencia parcial. La integración de producción mantiene su comprobación específica.

## Candidato 2.4.24 — conservar la navegación y contrastar las IP actuales

Recargar el panel conserva la sección abierta y la posición de la lista en la misma pestaña. En Conflictos de IP se guarda también un ID de servicio como referencia para volver al propietario visible, incluso si cambió la altura de una fila. La restauración funciona con el desplazamiento de escritorio y móvil, espera la carga de datos y evita que respuestas anteriores desplacen una sección distinta. Si Conflictos de IP dejó de estar disponible, vuelve a Dispositivos. Sólo se guarda navegación en la sesión del navegador; verificaciones, nombres, direcciones, credenciales y autorizaciones de limpieza no se conservan al recargar.

**Verificar en UISP** compara ahora la dirección guardada con el atributo actual del servicio para cada familia. Distingue coincidencia, IP retirada, IP cambiada e información insuficiente. Una lista completa de atributos vacía confirma ausencia únicamente cuando el atributo configurado existe de forma inequívoca en el catálogo actual; campos omitidos, valores inválidos o errores de consulta quedan sin comprobar. Las lecturas siguen siendo explícitas: como máximo tres servicios, tres clientes y un catálogo de atributos por solicitud, dentro del presupuesto orientativo de 12 segundos para iniciar consultas. No se escribe caché ni se cambian reservas al verificar.

**Revisar limpieza** también admite una reserva local cuya IP ya se retiró o cambió en un servicio que sigue existiendo. Exige identidad coincidente y otro servicio actual que conserve esa IP, y vuelve a verificar la evidencia, el atributo configurado y la base local al confirmar. Vacía únicamente la familia elegida y su campo heredado; conserva el servicio y la otra familia. Guarda el respaldo privado antes del cambio y mantiene las protecciones de trabajos pendientes, recuperación y propiedad aplicada en RouterOS. Una IP vacía en CRM por sí sola no demuestra que el router la haya liberado. La ruta anterior de HTTP 404 conserva sus comprobaciones.

Durante la operación normal, una nueva IP ya reservada se rechaza al preparar el trabajo afectado; otros servicios pueden continuar sus suspensiones y reactivaciones. El modo consulta global se activa cuando la base principal ya contiene duplicados. El diagnóstico no vigila continuamente los atributos de todo el CRM.

Validación 2.4.24 aprobada: **32 suites aisladas**, sintaxis de **45 archivos PHP propios** y verificación del instalador de 357 entradas, 124 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.24.json`. SHA-256: `a9c899ec39a5d395cc322394ad263a587a704b7055bd5350ba342b0a73cd818e`. Se comprobó en navegador la recarga conservando sección y posición en escritorio y móvil, la comparación de IP actual y la revisión de una reserva retirada con datos sintéticos. La limpieza mantiene las protecciones de trabajos y propiedad aplicada; estas pruebas no modifican UISP ni routers de producción.

## Candidato 2.4.23 — identificar el archivo del cliente por separado

La caché anterior conservaba los nombres de clientes pero omitía `isArchived`. El diagnóstico podía mostrar «Activo (dato guardado)» del servicio sin informar que su cliente estaba archivado. Ahora se guarda ese campo con tres resultados: archivado, no archivado o sin comprobar. La actualización añade una columna nullable y conserva las filas existentes; no convierte la ausencia del dato en «no archivado».

En Conflictos de IP, el estado del cliente aparece separado del servicio y los clientes archivados tienen un indicador destacado. **Verificar cliente** consulta directamente su ficha, incluso cuando el servicio anterior ya no existe. La consulta se limita a los clientes asociados a propietarios del diagnóstico, deduplica clientes compartidos y admite como máximo tres clientes por petición, con un presupuesto orientativo de 12 segundos para iniciar lecturas. Se indican origen, fecha de verificación y errores sin alterar servicios, caché, reservas, trabajos ni routers. Abrir el panel sigue usando únicamente los datos guardados.

El nuevo campo también se conserva al refrescar la caché normal y al recibir cambios parciales de clientes. La caché heredada en modo consulta sigue sin modificarse; Verificar cliente permite contrastar su estado actual explícitamente. El archivo por sí solo no convierte una asignación en candidata de limpieza: se mantienen todas las comprobaciones y protecciones de 2.4.22.

Validación 2.4.23 aprobada: **32 suites aisladas**, sintaxis de **44 archivos PHP propios** y verificación del instalador de 356 entradas, 123 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.23.json`. SHA-256: `724ec7240531954968fbe4a516e473013e9b4b184cbb114fe4f6095e508b598f`. Se comprobó también en navegador el indicador de archivo independiente y la verificación directa de clientes con datos sintéticos. La migración y las consultas no alteran servicios ni relajan la protección de sus reservas. La integración en UISP real conserva su comprobación específica.

## Candidato 2.4.22 — limpiar asignaciones antiguas con revisión y respaldo

El indicador cuenta **asignaciones guardadas**, no paquetes actuales del cliente. Un servicio eliminado del CRM puede conservar su reserva en la base del plugin. Los estados locales llevan «dato guardado» y una IP sin ficha local se identifica como un registro de IP; verificar otro propietario conserva el resultado anterior de cada consulta.

**Conflictos de IP → Revisar limpieza** presenta una selección acotada y sus motivos. Sólo propone una reserva cuando UISP devuelve un HTTP 404 explícito para ese servicio y otro propietario del mismo conflicto responde con un servicio válido. Un servicio existente se conserva en cualquier estado, incluido Terminado o un cliente archivado. Fallos de transporte, permisos, respuestas incompletas, falta de un propietario verificable, trabajos pendientes, recuperación pendiente o propiedad aplicada registrada en la cola impiden la limpieza.

Al confirmar, el servidor repite las consultas y comprueba que la asignación local no cambió. Antes de modificarla guarda un respaldo SQLite privado y descargable. La operación retira únicamente las reservas seleccionadas y vacía sus campos heredados de dirección para evitar que la migración las importe de nuevo. Conserva las fichas locales de servicio, los demás propietarios y la configuración; no modifica el CRM, routers, caché ni trabajos. Usa la exclusión de mantenimiento y una transacción, sin inicializar o importar la cola. Si falla una comprobación o el respaldo, no limpia ninguna selección.

Después se muestra el resultado, enlace al respaldo y conflictos restantes. El respaldo permanece descargable desde **Sistema** después de recargar, con permiso administrativo de edición. Recargar el panel permite completar la migración si ya no hay duplicados. Las demás escrituras siguen pausadas mientras persista un conflicto. Un 404 aislado no desencadena cambios: la acción requiere vista previa y confirmación administrativa explícitas.

Validación 2.4.22 aprobada: **32 suites aisladas**, sintaxis de **44 archivos PHP propios** y verificación del instalador de 356 entradas, 123 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.22.json`. SHA-256: `0c92a93035807b0c8306971820353ac7d1f1631600f6713fb621d52a04480c90`. Se comprobó también en navegador la revisión, la protección del servicio actual y la confirmación con datos sintéticos. La instalación y limpieza en UISP real quedan a cargo del operador; estas pruebas no ejecutan limpiezas en producción.

## Candidato 2.4.21 — consultar el panel durante una migración pendiente

Cuando las IP duplicadas impiden migrar una base anterior, el panel habitual permanece disponible en **modo consulta**. Sus seis vistas —Dispositivos, Planes, Configuración, Trabajos, Sistema y Conflictos de IP— conservan la navegación, con un aviso ámbar y contador persistentes. La vista de conflictos permite descargar el diagnóstico, abrir los clientes y servicios afectados en el CRM y volver a comprobar las asignaciones.

El diagnóstico muestra nombres y estados guardados, indicando su origen. **Verificar en UISP** consulta explícitamente el servicio y su cliente para contrastar el estado actual; abrir el panel no inicia esas consultas. Un cliente archivado se identifica por `isArchived`; un servicio terminado (`2`) o inactivo (`8`) tiene un estado distinto. Una fila ausente en caché o una respuesta 404 no confirman eliminación ni archivo. Una reserva anterior puede explicar una colisión y necesita revisión; no se libera automáticamente.

Mientras persistan los conflictos, se pueden consultar los datos guardados, pero no editar ajustes, ejecutar o eliminar trabajos, crear/restaurar respaldos ni aprovisionar cuentas. La descarga de respaldos existentes conserva el permiso administrativo de edición. Estas consultas abren las bases en modo de sólo lectura, sin importar la cola, refrescar la caché ni consultar routers. Las IP existentes se conservan hasta una resolución explícita que permita completar la migración.

Validación 2.4.21 aprobada: **31 suites aisladas**, sintaxis de **43 archivos PHP propios** y comprobación del instalador de 355 entradas, 122 archivos propios y 13 dependencias. Informe: `dist/verification-2.4.21.json`. SHA-256: `ecc819f6f6d247ae28b1b181292a75fe836e79c06596cc09c0727068cde8786a`. Se verificó también la navegación y el aviso con datos ficticios en navegador. La integración de esta versión en UISP y la corrección explícita de las reservas requieren comprobación en la instalación afectada. Los informes de 2.4.20 y 2.4.19 se conservan como evidencia histórica.

## Cambios incorporados en 2.4.20 — diagnóstico de IP duplicadas al actualizar

La pantalla independiente de aviso de esta revisión se integra en el panel habitual desde 2.4.21, manteniendo la pausa de las escrituras descrita arriba.

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

La segunda revisión corrige además propiedad de cuentas/colas padre, hidratación de clientes fuera de caché, bajas con router desconectado y migración de trabajos terminales anteriores, reintentos de perfiles/padres compartidos, pools IPv6 por revisión, idiomas regionales y respuestas tardías al cambiar pestañas. El detalle de cada fallo y su resultado está en el inventario. La [guía de pruebas reales](docs/PRUEBAS_REALES.md) contiene 101 casos para comprobar en laboratorio.

Para verificar el candidato después de instalar dependencias y construir el ZIP:

```text
python tools/verify_plugin.py --php RUTA_PHP --node RUTA_NODE --archive dist/ros-plugin-2.4.28-candidate.zip --output dist/verification-2.4.28.json
```

En Windows sin configuración PHP, añadir `--extension-dir RUTA_EXT`. El verificador ejecuta las suites y comprueba sintaxis PHP, correspondencia del código/dependencias y exclusión de datos/fixtures del instalador. El estado de validación de 2.4.28 se indica al principio. Para construirlo: `python tools/build_plugin.py --vendor-dir src/vendor --output dist/ros-plugin-2.4.28-candidate.zip`.

Si una migración queda pendiente por IP duplicadas, usar **Conflictos de IP → Revisar limpieza** para comprobar las reservas obsoletas elegibles, revisar los motivos y confirmar únicamente la selección aprobada. Los trabajos pendientes, la recuperación pendiente y el estado aplicado de una cuenta protegen sus reservas; limpiar IP no retira recursos del router. Conservar el respaldo privado generado, que permanece descargable desde **Sistema** después de recargar. Tras resolver el último conflicto, recargar el panel para completar la migración antes de reanudar las demás escrituras.

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
