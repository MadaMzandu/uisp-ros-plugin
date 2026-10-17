# UISP/UCRM Plugin for RouterOs Services

[Complete English changelog](docs/CHANGELOG_EN.md) · [Texto completo para el PR](docs/PR_DESCRIPTION.md) · [Webhook setup and rationale](docs/WEBHOOK_SETUP_EN.md) · [Mejoras en español](docs/MEJORAS.md)

## Candidato 2.4.43 — conservar la cuenta vinculada al resolver duplicados PPP

Se corrige una regla asimétrica de Resolver: con una cuenta vinculada al servicio actual y otra sin identificador de servicio en su comentario, sólo se permitía conservar la segunda porque la primera sí podía retirarse con propiedad comprobada. Ahora también puede elegirse la cuenta vinculada y retirar el duplicado manual cuando ambas son registros PPP del mismo usuario exacto y la misma IPv4 concreta. La cuenta conservada debe tener el cliente y servicio actuales en su comentario; se comprueban nuevamente el catálogo completo, las reclamaciones locales y las filas del router. Perfiles diferentes no impiden revisar el duplicado. Diferencias contradictorias de IP, Caller ID, tipo de servicio PPP o IPv6, un propietario vigente distinto y los duplicados DHCP o totalmente manuales conservan sus protecciones.

La elección nunca se realiza automáticamente. El panel distingue la cuenta vinculada del registro sin vínculo, muestra los ID concretos que se conservarán y retirarán al cambiar la selección, y explica que el perfil final depende del plan vigente en UISP. Elegir un registro no selecciona su paquete antiguo como plan deseado. Antes de retirar el duplicado se respaldan ambas configuraciones completas y se vuelve a leer la fila exacta; no se elimina si falta su contraseña en el respaldo o cambió la revisión. Después continúa el aprovisionamiento normal y su confirmación real. Interfaz disponible en los seis idiomas del panel y recursos actualizados para evitar conservar el JavaScript anterior.

Validación 2.4.43 aprobada: **68 suites aisladas**, sintaxis de **69 archivos PHP propios**, 384 entradas del instalador, 151 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.43.json`. SHA-256: `d3a4d920e00202099b08936cee956c56d19d985b319a0f45b70e93236e970987`. El informe conserva el fallo inicial de una comprobación que esperaba la revisión anterior de recursos y su revalidación contra el mismo ZIP. Revisión visual sintética en escritorio/móvil, español/inglés y temas claro/oscuro; no se modificaron UISP ni RouterOS de producción.

## Candidato 2.4.42 — resolver cuentas ausentes después de quitar un duplicado

Se corrige una discrepancia entre Diagnóstico y Resolver: RouterOS puede responder `!empty` seguido de `!done` cuando una consulta no encuentra registros. Diagnóstico ya reconocía esa respuesta; Resolver la rechazaba y mostraba un error genérico con el botón bloqueado. Ahora la secuencia completa se interpreta como ausencia comprobada, tanto para la cuenta principal como para su cola auxiliar. Una cuenta ausente puede crearse mediante la sincronización inmediata normal, con confirmación posterior. Las respuestas incompletas, errores y secuencias contradictorias continúan sin autorizar cambios. [Documentación de la API de MikroTik](https://help.mikrotik.com/docs/spaces/ROS/pages/47579160/API).

También se comprueban las asociaciones históricas contra los atributos completos actuales del otro servicio. Si una consulta exacta confirma que se retiró su identificador de conexión, un trabajo, estado aplicado o recuperación antiguos dejan de considerarse su propiedad vigente de esa cuenta. No se exige conservar un Site que también se vació. Un identificador aún coincidente requiere comprobar su router; un propietario vigente, datos incompletos o una identidad no válida siguen impidiendo la apropiación. Esta comprobación no borra trabajos de otros servicios ni libera indiscriminadamente sus IP. Los fallos de lectura del router conservan su motivo específico en Resolver.

Validación 2.4.42 aprobada: **68 suites aisladas**, sintaxis de **69 archivos PHP propios** y 384 entradas verificadas del instalador, con 151 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.42.json`. SHA-256: `b68bad21531310fe071a1581c57716e0ca472f88ef5a2dd0ccd507d0a2aa4e3c`. Incluye protocolo binario de RouterOS en sockets locales y creación/verificación por la cola normal; no se modificaron UISP ni RouterOS de producción.

## Candidato 2.4.41 — guardar dispositivos sin confundir el tipo y el filtro

Se corrige `invalid_service_filter` al guardar el formulario de un router. El panel envía `type: mikrotik` como tecnología del dispositivo; ese valor se estaba interpretando después como alcance de una búsqueda de servicios. El fallo ocurría después de persistir los datos y podía mostrar error aunque el pool ya estuviera guardado. Ahora guardar sin cambios de QoS termina antes de buscar servicios o ejecutar aprovisionamiento. Los cambios reales de QoS usan explícitamente el alcance del dispositivo seleccionado. Los filtros de operaciones de sistema (`device`, `plan`, `service`, `all`) conservan su validación; `mikrotik` no se acepta como filtro de sincronización.

La regresión utiliza la petición completa del formulario, incluido `type`, mediante `ApiRouter` y comprueba la respuesta JSON de éxito, la ausencia de contraseñas y la conservación de caché/cola antes de sincronizar. Continúan las comprobaciones de migración del pool, reintentos, agotamiento y exclusión de escrituras. Se agregan pruebas de los cambios QoS en el router seleccionado y del rechazo de un filtro de sistema inválido sin escrituras.

Validación 2.4.41 aprobada: **67 suites aisladas**, sintaxis de **69 archivos PHP propios** y 384 entradas verificadas del instalador. Informe: `dist/verification-2.4.41.json`. SHA-256: `4d1c0aa46a22609ee5724b3d031cc3bfee84492a86f9ecc224ab5bc98b0501ae`. Las pruebas usan datos sintéticos; no se modificaron UISP ni RouterOS de producción.

## Candidato 2.4.40 — resolver cuentas desde el panel y cambiar pools por Site

**Resolver** aparece junto a cada trabajo con error y en el diagnóstico del servicio. Consulta nuevamente UISP, su catálogo completo y las cuentas del router. Cuando una única cuenta puede asociarse al servicio actual con evidencia vigente, corrige el comentario y sincroniza inmediatamente con un clic. La diferencia de IP no impide corregir un comentario antiguo si la identidad y la exclusividad actuales están comprobadas. Una cuenta manual requiere selección explícita; ante duplicados se elige cuál conservar, sin selección predeterminada. Sólo se retiran los demás registros cuya propiedad se pudo comprobar. Los propietarios vigentes reales y los datos desconocidos conservan su protección y explican la causa concreta.

Los cambios de otros clientes sin relación no invalidan la revisión; un nuevo propietario competidor se detecta al consultar otra vez el catálogo completo. La resolución vuelve a comprobar la evidencia bajo los bloqueos del servicio y del router, guarda un respaldo privado antes de modificar cuentas y consume un permiso de un uso. Tramita el estado actual mediante la cola y el aprovisionamiento normales, conserva las deudas de limpieza y distingue **Resuelto y verificado**, pendiente y resultado sin confirmar. Una respuesta incierta exige una nueva revisión y no repite automáticamente cambios. Las colas DHCP duplicadas inequívocamente propias pueden consolidarse si no tienen dependencias; las colas compartidas, ajenas o sin evidencia suficiente no se eliminan. Los respaldos de resolución tienen descarga administrativa y quedan incluidos en el respaldo completo del plugin; restaurar el plugin no revierte cambios físicos del router.

Guardar un dispositivo ya no fuerza una descarga de toda la caché de UISP. Un servicio de otro Site mal configurado deja de causar `service_site_not_configured` al guardar un nuevo rango. Los pools se validan antes de guardar y se conserva su rango anterior, vinculado al dispositivo y a su endpoint. Al sincronizar se reconoce una IP del pool anterior que ya no pertenece al nuevo, se confirma el servicio vigente y la disponibilidad del reemplazo, se vacía el atributo antiguo y se asigna una IP del pool actual. Esto también corrige trabajos preparados con el rango anterior, sin restaurar su IP obsoleta. Repetir la sincronización conserva el reemplazo. Un pool agotado, una API no confirmada o una reserva de un propietario vigente siguen siendo incidencias concretas; no se sobrescriben asignaciones de otros clientes.

Validación 2.4.40 aprobada: **67 suites aisladas**, sintaxis de **69 archivos PHP propios**, 384 entradas del instalador, 151 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.40.json`. SHA-256: `4b5ea85b8993872423b0566850526d025b5e966b031e58debe18f463598f44f4`. La revisión visual sintética comprobó el botón Resolver, selección de duplicados, resolución de un clic, respaldo y resultado en escritorio/móvil, español/inglés. No se modificaron UISP ni RouterOS de producción.

## Candidato 2.4.39 — sincronización inmediata y revisión opcional

**Sincronizar ahora** vuelve a ser la acción principal por router. Consulta el catálogo actual de UISP, descubre servicios nuevos del Site y aplica uno por uno en ese momento, con progreso visible; no obliga a pasar por una comparación previa ni espera la próxima ejecución programada. Reaplica las cuentas administradas y fuerza sus reconexiones PPP/Hotspot, incluso sin diferencias. **Revisar diferencias** es una acción secundaria independiente: compara sin escribir y permite programar después las diferencias comprobadas o la reconexión revisada. Esas acciones de la vista previa mantienen su ejecución posterior mediante la cola.

La ejecución directa confirma otra vez el servicio, su Site y el cliente actual antes de aplicar; conserva la identidad anterior para tramitar un traslado mediante la migración normal. Cada petición procesa una página de catálogo o un servicio, con resultados individuales y protección de mantenimiento/restauración. Un fallo de un cliente no impide intentar los siguientes. Los trabajos que fallen al aplicar conservan sus reintentos normales; una consulta sin evidencia no autoriza escribir. Una respuesta incierta no se repite automáticamente, evitando reconexiones duplicadas. Cerrar o recargar la página detiene los siguientes pasos del recorrido; los trabajos ya enviados conservan su estado.

Se corrige la revisión que rechazaba clientes por `isArchived = NULL` en una caché actualizada desde versiones anteriores. La autoridad es el cliente exacto consultado en UISP, con archivo explícitamente descartado; la caché desconocida, antigua o ausente no veta esa consulta. Un cliente archivado actualmente, una identidad incorrecta o una API fallida mantienen la protección. La aplicación de la vista previa vuelve a verificar ese estado.

Al mover un servicio, una IP fuera del pool destino que pertenece a otro Site configurado se vacía mediante la política existente y se solicita otra del pool destino. Una IP válida en destino puede conservarse; una IP manual fuera de todos los pools no se descarta por esa regla. Antes de retirar la cuenta anterior se valida el reemplazo; el traslado mantiene el orden de baja del origen y alta del destino. Si la aplicación al destino falla después de retirar el origen, queda pendiente de reintento: no se promete un traslado sin interrupción. Las cuentas manuales o de otros propietarios y las reservas vigentes continúan protegidas.

El icono de **Configuración** utiliza un engranaje con dientes y centro circular, también con los SVG locales del panel.

Validación 2.4.39 aprobada: **64 suites aisladas**, sintaxis de **67 archivos PHP propios**, 381 entradas del instalador, 148 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.39.json`. SHA-256: `dc7bcf7cb12ff783ab114c331522069968917ad20bbf6b4295a3ec8030d0dc36`. La comprobación visual sintética incluye acciones separadas, progreso, modal móvil, español/inglés, temas claro/oscuro y el nuevo engranaje. No se ejecutaron cambios en UISP ni RouterOS de producción.

## Candidato 2.4.38 — retirar intenciones de servicios dados de baja y ordenar Sistema

La cola consulta el estado actual en UISP antes de volver a aplicar intenciones antiguas. Un servicio terminado, obsoleto o inactivo (`2`, `5`, `8`), un cliente cuyo archivo está confirmado o un servicio ausente mediante HTTP 404 comprobado permiten retirar sus pendientes anteriores. **Suspendido (`3`) conserva su cuenta y asignación**: no se trata como una baja. Una ficha que no abre, una caché antigua o el sufijo de un usuario PPPoE no prueban por sí solos ninguna de esas situaciones.

Si no quedan recursos físicos posibles, se retiran las reservas locales correspondientes. Si hay estado aplicado, comandos preparados, intentos antiguos o recursos conservados, se programa su baja mediante la cola normal y se mantiene la IP protegida hasta confirmar la limpieza. Antes de escribir se comprueban otra vez el estado y la propiedad del recurso; una cuenta que ahora pertenece a otro servicio se conserva. La reactivación, una identidad contradictoria o una consulta fallida dejan una incidencia del servicio afectado y no autorizan borrar ni sobrescribir al propietario actual. La revisión usa turnos y presupuestos acotados; no promete limpiar cientos de servicios en una sola pasada. Quitar el Site o el identificador de cuenta se comprueba como una retirada de configuración independiente; no se confunde con una baja de facturación reactivada ni reproduce una actualización obsoleta antes de limpiar.

La revisión programada incluye propietarios históricos, y un conflicto real puede revisar al propietario concreto registrado antes de reintentar la asignación. Así se evita perpetuar una reserva por el único hecho de conservar una intención cancelada. En el caso solicitante `7279` / reserva `9807`, si UISP confirma que `7279` está dado de baja o su cliente archivado, se retira esa intención antigua; la asignación vigente de `9807` se conserva.

**Revisar servicio solicitante** abre una comprobación explícita con enlaces al servicio y al cliente conocidos. **Retirar pendientes del servicio** sólo se habilita con evidencia fresca y un token de un uso; vuelve a validar antes de cambiar la cola. El resultado distingue las reservas ya liberadas de **Baja programada**, con acceso a sus trabajos. Una respuesta incierta consume el permiso y exige revisar de nuevo. Los errores explican si falta consultar UISP, confirmar identidad o limpiar el router; una comprobación sin evidencia no habilita la retirada. La compatibilidad de una migración pausada mantiene su protección independiente.

Una entrada nueva al panel abre **Dispositivos**. Una recarga real conserva la sección y su posición; recordar una visita anterior a Trabajos ya no cambia la portada. **Sistema → Respaldos del plugin** reúne el ZIP completo y las copias SQLite anteriores o de limpieza, con sus alcances explícitos. El ZIP incluye cola y estado operativo; SQLite mantiene el alcance de la base principal y las copias de limpieza permiten descarga. Sus controles dejan de repetirse en Utilidades. Se compactan las secciones nuevas de Sistema; el rediseño general y el centro de notificaciones siguen pendientes.

Validación automatizada e instalador: **63 suites aisladas aprobadas**, sintaxis de **66 archivos PHP propios** y verificación de 380 entradas, 147 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.38.json`. SHA-256: `ebbb4de8aa1d0e6a14e0b8f89981639cbfab3e15956397c1edb72e6f90bcda73`. La revisión de navegador usó datos sintéticos en escritorio/móvil, español/inglés y temas claro/oscuro, incluido el modal a 390 × 844. No se modificaron UISP ni routers de producción; la baja y reutilización de IP requieren comprobarse en la instalación afectada.

## Candidato 2.4.37 — sincronización revisada, estado fresco, reconciliación y respaldo completo

**Revisar sincronización** prepara una vista previa por router con servicios sin cambios, diferencias y casos que requieren revisión. Consulta UISP y RouterOS con límites; no actualiza la caché, reserva IP, modifica CRM/router ni ejecuta trabajos. Muestra IPv4/IPv6, campos comprobados, posibles reconexiones y, cuando corresponda, router anterior y destino del traslado. **Sólo revisar** cierra sin aplicar. **Aplicar diferencias** programa únicamente cambios comprobados; **Forzar reconexión** incluye las cuentas verificadas sin diferencias. La confirmación vuelve a comprobar la identidad y el estado actual y usa la cola normal, servicio por servicio, para la siguiente pasada del worker. Cancelar detiene las solicitudes siguientes; una solicitud ya enviada conserva su resultado. Un dato incierto, una cuenta manual o ajena y un cambio concurrente no se convierten en permiso para sobrescribir.

La **revisión automática de diferencias** se ejecuta después de los trabajos: como máximo un servicio por minuto, con turnos persistentes y presupuesto de ocho segundos. Compara UISP fresco, estado aplicado y cuenta observada. Sólo programa correcciones de una cuenta propia identificada en el mismo router cuando la evidencia está completa, no hay operaciones activas y la configuración/versiones siguen coincidiendo. Los casos ambiguos quedan como incidencias por servicio sin detener los demás. En Sistema se puede desactivar la revisión o su aplicación automática. No adopta cuentas manuales, migra arbitrariamente recursos ni libera reservas por archivo del cliente o ausencia de una ficha.

Los webhooks nuevos obtienen la representación actual del servicio en UISP bajo el bloqueo del servicio antes de actualizar datos o programar trabajo. Un evento antiguo no restaura sus atributos ni su estado congelados. Si la acción explícita contradice el estado consultado o falta evidencia, conserva una revisión pendiente identificada sólo por servicio. El proceso programado vuelve a consultar el estado actual con límites; no reproduce la acción ni el cuerpo anterior. La deduplicación de UUID continúa. Esta comprobación evita aplicar datos viejos, pero no inventa una revisión cronológica que UISP no proporcione ni asegura consistencia inmediata de su API.

**Sistema → Respaldos completos** crea, descarga o carga un ZIP privado con las bases de datos, cola, caché y estado operativo admitido, incluidas credenciales. El respaldo SQLite anterior de `data.db` se conserva como opción independiente. Restaurar un ZIP completo requiere revisar cantidades, confirmar expresamente y presentar un token ligado al estado actual. Se consume antes del cambio, guarda otro respaldo completo previo y usa un diario de recuperación que revierte una sustitución interrumpida al iniciar. La integración SDK del host actual se conserva; no se importan credenciales de otro host desde el paquete. La restauración no revierte CRM ni RouterOS: los trabajos restaurados podrán continuar en la siguiente ejecución programada. Un resultado incierto exige recargar y revisar de nuevo; no permite repetir el token ni seguir escribiendo desde formularios anteriores. El resultado ofrece el respaldo previo para descargarlo.

**Dispositivos → Capacidad de pools** consulta bajo demanda IPv4 e IPv6 con cantidades exactas expresadas como cadenas decimales: total utilizable, asignadas, retenidas, excluidas y libres. Los intervalos solapados se unen sin contar dos veces; las reservas históricas siguen ocupadas. Indica pools compartidos, poca capacidad y agotamiento; una fuente ausente o incompleta aparece sin comprobar. Los totales IPv6 corresponden al prefijo de cliente mostrado. La consulta no asigna ni reserva direcciones y no garantiza capacidad hasta la preparación normal del trabajo.

Los errores de trabajo enlazan tanto el servicio solicitante como el bloqueante y sus clientes guardados cuando existe una asociación única. No deducen el cliente por el sufijo de un usuario PPPoE. Si un servicio bloqueante devuelve HTTP 404, sigue disponible la revisión explícita de limpieza con sus comprobaciones, exclusión, respaldo y confirmación; archivar un cliente o borrar un trabajo no libera por sí solo las reservas válidas, el estado aplicado ni los recursos protegidos.

El error de IP distingue el trabajo solicitante del servicio que conserva la reserva, con acceso a ambas fichas. Que el solicitante sea antiguo no autoriza retirar la asignación de un servicio vigente.

El rediseño compacto general y el centro de notificaciones siguen aplazados para la etapa final. Validación 2.4.37 aprobada: **62 suites aisladas**, sintaxis de **65 archivos PHP propios** y verificación de 379 entradas, 146 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.37.json`. SHA-256: `1284c74f580d8e792479694ebb0df97f1f055b033577c72999e62e7ba386adc7`. Revisión de navegador en escritorio/móvil, español/inglés y temas claro/oscuro con datos sintéticos; sin cambios en UISP ni routers de producción.

## Candidato 2.4.36 — confirmación en RouterOS, diagnóstico por servicio y salud automática

Cada trabajo nuevo guarda una comprobación de los campos efectivos de cuenta, perfil, cola y desconexión después de ejecutar sus comandos. Trabajos distingue **Aplicado y verificado**, **Aplicado sin confirmar** y **Diferencias en el router**, con fecha y valores comprobados. Una lectura fallida no vuelve a ejecutar el cambio ni deja el trabajo pendiente. **Verificar de nuevo** consulta el router sin repetir escrituras o reconexiones. Los traslados conservan también la evidencia del router anterior entre reintentos. Las comprobaciones antiguas se identifican cuando existe una versión nueva del servicio; los trabajos anteriores a esta versión no reciben una confirmación inventada.

**Diagnóstico por servicio** busca por servicio, cliente, usuario o IP en los registros locales. Al elegir un servicio, consulta UISP y RouterOS y los compara con el estado previsto por el plugin: usuario, Site, IPv4/IPv6, perfil, plan, estado y propiedad. Muestra fecha y origen de cada lectura, diferencias, trabajos, intentos, próximo reintento e historial local. Un dato desconocido aparece como sin comprobar. El diagnóstico y la nueva verificación no actualizan caché ni modifican servicios.

En **Sistema → Salud de la automatización** aparecen las ejecuciones observadas del proceso programado, el último webhook autorizado, la cola, el pendiente más antiguo, el próximo reintento guardado, la última caché y el mantenimiento registrado. Una ejecución manual no cuenta como actividad programada. La recepción de un webhook no equivale a aprovisionamiento y el panel no inventa el horario del cron externo. El sondeo es local y se pausa al ocultar la pestaña o ejecutar cambios. El registro de actividad tiene espera limitada: una contención de archivos puede omitir una observación sin detener el aprovisionamiento.

La confirmación acredita configuración observada en RouterOS y retirada de las sesiones anteriores identificadas, no conectividad de Internet del abonado. Las consultas tienen límites y excluyen contraseñas. La cola sigue primero y las recuperaciones compactas permanecen debajo. Interfaz disponible en los seis idiomas del panel. Después de instalar, recargar el panel; la salud empezará a registrar actividad desde las siguientes ejecuciones y los nuevos trabajos generarán sus comprobaciones.

Validación 2.4.36 aprobada: **54 suites aisladas**, sintaxis de **58 archivos PHP propios** y verificación de 371 entradas, 138 archivos propios y 13 dependencias fijadas. Informe actual: `dist/verification-2.4.36.json`. SHA-256: `e56d016e13804415acfca3400588c729fa41dc895332f105e47998f25fe47508`. Pruebas de navegador en escritorio/móvil, español/inglés y temas claro/oscuro con datos sintéticos; no se modificaron UISP ni routers de producción. El informe conserva el primer fallo de contención y su comprobación posterior contra el mismo ZIP.

## Candidato 2.4.35 — corregir un ID de servicio contradictorio en la cuenta del router

La comprobación de propiedad de una cuenta PPPoE acepta que el atributo opcional de Hotspot no exista en un catálogo válido de UISP. Site y usuario siguen siendo obligatorios. Los errores distinguen qué configuración falta y presentan el ID del comentario como un dato por comprobar, sin atribuirle automáticamente la propiedad de la cuenta.

Cuando el comentario contiene el cliente correcto pero el ID de servicio de otra persona, la corrección exige coincidencia exacta entre el usuario actual del router, del trabajo y de UISP, el Site del destino y la IPv4 actual. El otro servicio debe tener cliente, usuario, router e IP diferentes y confirmados. Se revisan reclamaciones aplicadas y trabajos ejecutables, se releen ambas fichas y se guarda un respaldo privado antes de actualizar el mismo registro RouterOS. Una evidencia incompleta conserva la cuenta y muestra el motivo.

Los reintentos preparados también normalizan los identificadores del comentario de la cuenta desde su propio servicio, conservando usuario, IP y credenciales, para no volver a publicar un marcador antiguo incoherente.

Después de instalar, recargar el panel y ejecutar el pendiente afectado. No hace falta vaciar la cola ni repetir la sincronización completa. La comprobación utiliza lecturas de UISP; la corrección actualiza únicamente la cuenta identificada del router, sin editar la ficha del ID incorrecto. Las pruebas usan datos sintéticos; el resultado real se confirma después del reintento.

Validación 2.4.35 aprobada: **47 suites aisladas**, sintaxis de **55 archivos PHP propios** y verificación de 367 entradas, 134 archivos propios y 13 dependencias fijadas. Informe actual: `dist/verification-2.4.35.json`. SHA-256: `92a773f1021233b4407d5a42466d8ee799322cad3db35dd7b703e208581389e1`. Las pruebas usan datos sintéticos y transportes simulados/locales; el reintento de producción queda por confirmar.

## Candidato 2.4.34 — corregir reintentos con IP de otro Site y vínculos de cuentas anteriores

Los trabajos ya preparados comprueban de nuevo en UISP una IP perteneciente a otro Site antes de ejecutar sus comandos. Si sigue siendo ajena al destino, vacían únicamente ese atributo y preparan una dirección del pool correspondiente. Si UISP ya tiene una IP manual nueva, la respetan. Se conserva la elección entre reintentos y la reserva anterior hasta confirmar el cambio completo. La corrección mantiene la identidad de los recursos existentes, el plan y las credenciales del servicio.

Una cuenta PPP/Hotspot cuyo comentario conserva otro servicio del mismo cliente puede actualizarse en el mismo registro cuando UISP confirma el servicio actual, su usuario y Site, y que el servicio anterior terminó o ya no existe mediante HTTP 404 comprobado. Se comprueban otros propietarios y trabajos ejecutables, se guarda un respaldo privado sin contraseñas y se relee el registro exacto antes de escribir. Un servicio anterior vigente, una cuenta manual o de otro cliente y una consulta fallida mantienen la protección. Los errores muestran el motivo y enlaces al servicio actual y anterior.

Después de instalar esta versión, los pendientes pueden corregirse en su siguiente reintento programado o con **Ejecutar** en Trabajos. Los cambios normales de estado y las velocidades configuradas conservan su comportamiento. La validación usa datos sintéticos; el resultado de cada cuenta real debe comprobarse en UISP y RouterOS.

Validación 2.4.34 aprobada: **47 suites aisladas**, sintaxis de **55 archivos PHP propios** y verificación de 367 entradas, 134 archivos propios y 13 dependencias fijadas. Informe actual: `dist/verification-2.4.34.json`. SHA-256: `851d250ef1c9124e4bc86564f72fe5099dada37ec16fc682f1536a8eb2141be1`.

## Candidato 2.4.33 — IP nueva al vaciar el atributo y al cambiar de Site

Un atributo IP vaciado en UISP sustituye el valor anterior de la caché. El plugin solicita otra dirección del pool efectivo del Site; conserva esa elección en reintentos y eventos duplicados. Las familias IPv4/IPv6 se resuelven juntas antes de preparar cambios del router. Una sincronización posterior también detecta el vaciado aunque no haya llegado el webhook, usando el último valor fijo confirmado.

Si una IP explícita está fuera del pool de destino y pertenece al pool de otro Site configurado, el plugin comprueba el servicio, cliente, Site, atributo e IP actuales en UISP. Antes de vaciar sólo ese atributo, valida que pueda preparar el reemplazo del pool de destino; confirma el vaciado mediante una nueva lectura. Una IP manual fuera de todos los pools no se cambia por esta regla. No se eliminan reservas de otros servicios. Si falta capacidad o la evidencia cambió, conserva la cuenta anterior y muestra el fallo para ese servicio.

La exportación programada de IP ya no sobrescribe un valor distinto encontrado en UISP. Sólo publica en un campo vacío una asignación nueva del pool con prueba aplicada y vigente, una vez por generación; una respuesta incierta no permite repetir ciegamente la escritura. Las correcciones de CRM pendientes de confirmación conservan su protección. Un conflicto válido muestra el servicio que reserva la dirección y su origen, sin confundirlo con una reserva malformada.

Una sincronización nueva sustituye una intención pendiente obsoleta si todavía no preparó comandos nuevos y su limpieza anterior ya está confirmada; no repite esa limpieza. Las limpiezas incompletas y los comandos preparados conservan su orden. Después de actualizar, volver a sincronizar el servicio o router crea la intención actual; reintentar un trabajo antiguo conserva su instantánea anterior.

Validación 2.4.33 aprobada: **45 suites aisladas**, sintaxis de **54 archivos PHP propios** y verificación de 366 entradas, 133 archivos propios y 13 dependencias fijadas. Informe actual: `dist/verification-2.4.33.json`. SHA-256: `6aa920f75a8b03745711e9d2f07d294f03bbd4ff218fe022d4c6f177e4163384`. Las pruebas usan datos sintéticos, bases temporales y transportes simulados/locales; no se modificaron UISP ni routers de producción.

## Candidato 2.4.32 — revisión automática y salida explícita de recuperaciones

El proceso programado comprueba reservas malformadas y recuperaciones antes de ejecutar la cola, fuera de sus bloqueos de trabajo. Revisa como máximo un servicio/familia y una recuperación por pasada, con presupuesto de 12 segundos y turnos persistentes para no atascarse en el primer caso. Sólo aplica correcciones locales demostrables contra UISP y consolida copias redundantes o recursos observados ausentes, con respaldo y revalidación. No fuerza descartes, no envía PATCH a CRM ni desconecta cuentas desde esa revisión. Los casos sin evidencia suficiente se conservan y los fallos de consulta no abortan la ejecución de otros trabajos.

Una reserva malformada de otro servicio queda como diagnóstico informativo y no detiene la asignación del cliente actual. La fila sigue visible para corrección; las IP válidas de caché, recursos aplicados y registros históricos permanecen exclusivas. Una IP propia inválida falla sólo para ese servicio.

La reparación de un campo local malformado ya no exige eliminar una recuperación compatible. Conserva sus registros y direcciones válidas byte por byte; una dirección válida distinta, una identidad contradictoria o un trabajo ejecutable incompatible siguen protegidos. El flujo muestra cuántos registros conserva. Las reservas se validan antes de las limpiezas de una actualización que necesite preparar su reemplazo, para evitar retirar una cuenta y fallar después por la IP inválida de su reemplazo.

**Conservar recursos y cerrar revisión** ofrece una salida al historial antiguo incompleto. Comprueba de nuevo la identidad del servicio y cliente en UISP, respalda la cola y mueve sus registros exactos a un almacén protegido. Sus reservas válidas, referencias de equipos y recursos compartidos siguen protegidos, incluso después de reiniciar o compactar el trabajo original; no se ejecutan como limpiezas canceladas en una actualización posterior. El apartado de revisiones cerradas permanece visible. Conservar no confirma que el router esté limpio ni sincronizado, y no libera las IP históricas.

**Descartar registro local** permite forzar la retirada definitiva del historial cancelado o conservado seleccionado, incluso sin conexión a UISP. Tiene revisión local, confirmación del trabajo/servicio, token de un solo uso, comprobación de concurrencia y respaldo privado previo. Mantiene los trabajos actuales, el estado aplicado, las reservas actuales, CRM y router. Advierte que los recursos históricos pueden seguir presentes y que sus IP podrán reutilizarse al perder esa protección; no es una limpieza física ni elimina conflictos actuales de propiedad. Nunca se ejecuta automáticamente.

La cola de trabajos queda primero. Las recuperaciones aparecen justo debajo en tarjetas compactas de una, dos o tres columnas según el espacio; los textos repetidos se reducen y los controles siguen accesibles. Un resultado confirmado refresca la lista y el indicador. Los diagnósticos identifican las reservas anteriores sin cobertura, comandos antiguos ausentes y retiradas canceladas, sin publicar credenciales.

Validación 2.4.32 aprobada: **42 suites aisladas**, sintaxis de **53 archivos PHP propios** y verificación de 365 entradas, 132 archivos propios y 13 dependencias fijadas. Informe actual: `dist/verification-2.4.32.json`. SHA-256: `2d679a68b6d77f3f11ca3d9006ebaa056f102f2b31c1e408b9733875e37fee0c`. Las pruebas de navegador usan datos ficticios; no se modificaron UISP ni routers de producción.

## Candidato 2.4.31 — cerrar avisos y corregir reservas retenidas por trabajos cancelados

Los tres avisos superiores tienen **Cerrar aviso** y se pueden recuperar con **Mostrar avisos**. El cierre se conserva durante la pestaña mediante cantidades y estados generales; no guarda IP, clientes ni credenciales. No reaparece en cada sondeo ni al navegar. Cambios relevantes de cantidad/error o la desaparición y reaparición del problema vuelven a mostrarlo. Los contadores laterales, la consulta de detalles y las protecciones de una migración pausada siguen vigentes.

La corrección local distingue un trabajo pendiente de una intención cancelada o sustituida. Una actualización cancelada sin comandos preparados ni deuda no bloquea sólo porque su IP solicitada sea antigua o malformada. Conserva íntegros el trabajo y sus reservas válidas; continúa comprobando cliente, identidad anterior, propiedad aplicada y la familia seleccionada. Una intención pendiente diferente sigue protegida y muestra su ID y estado, con acceso para revisarla. El flujo no revive, reescribe ni ejecuta trabajos cancelados. La regresión con la cola real comprueba que, tras corregir las fuentes inválidas, otro servicio vuelve a ejecutarse y los cancelados permanecen sin intentar.

**Comparar con UISP y router** conserva la resolución anterior de copias redundantes y añade observación de los recursos retenidos cuando no basta el estado aplicado guardado. Las consultas RouterOS son sólo `print`, con filtros exactos y propiedades limitadas: no provisionan, adoptan, eliminan ni desconectan cuentas. Comprueba el mismo destino configurado y usa sus credenciales actuales. Muestra equipo, ruta, selector y resultado presente/ausente/sin comprobar. Sólo ofrece resolver por ausencia cuando todos los recursos y reservas anteriores estén representados y se hayan observado ausentes; vuelve a consultar bajo los bloqueos antes de confirmar, respalda `jobs.db` y marca únicamente ese trabajo cancelado como limpiado. El estado aplicado, CRM y routers permanecen intactos. Objetos presentes, perfiles compartidos, destino cambiado, historial incompleto y fallos de lectura siguen protegidos; una comprobación parcial no prueba ausencia. No hay una cola oculta que vuelva a ejecutar las operaciones eliminadas.

Los generadores, vistas previas, capturas y reportes antiguos se retiran del directorio de trabajo. El instalador histórico del repositorio se sustituye por el actual; las herramientas reproducibles, pruebas, código, documentación y dependencias de ejecución se conservan. En `dist` quedan los archivos de entrega y comprobación actuales, con sus capturas en una carpeta propia.

Validación 2.4.31 aprobada: **40 suites aisladas**, sintaxis de **52 archivos PHP propios** y verificación del instalador de 364 entradas, 131 archivos propios y 13 dependencias fijadas. Informe actual: `dist/verification-2.4.31.json`. SHA-256: `48da9d4bd968068f1e85323ae63c39409f98e85ce1b7bad78fefb34306824cab`. Las pruebas de navegador usan datos ficticios; no se realizaron cambios en CRM ni RouterOS de producción.

## Candidato 2.4.30 — revisar recuperaciones y aclarar los cambios rechazados por UISP

El indicador distingue trabajos ejecutables de recuperaciones conservadas: una cola vacía con registros cancelados muestra **Recuperaciones pendientes**, y un estado mixto muestra **Trabajos y recuperaciones**. **Revisar** abre la sección **Servicios en recuperación** dentro de Trabajos, con servicio, cliente conocido, trabajo original y equipos. La lista se consulta localmente sin inicializar la cola ni contactar CRM/router; una lectura fallida no se presenta como lista vacía comprobada.

**Revisar recuperación** consulta la identidad y el estado en UISP y compara los registros conservados con el estado aplicado guardado. Sólo ofrece resolver copias redundantes exactas, con revisión privada de diez minutos, confirmación, comprobación fresca, exclusión de operaciones concurrentes y respaldo coherente de `jobs.db`. No emite comandos al router ni cambia servicios, IP, planes, caché, propiedad aplicada o trabajos. Un resultado confirmado refresca la lista y el contador. Las configuraciones diferentes, identidades desconocidas, reservas anteriores distintas, trabajo activo o evidencia incompleta se conservan con un motivo; esta opción no sustituye la reconciliación física de recursos antiguos. No se debe sincronizar masivamente sólo para ocultar un aviso. El respaldo de recuperación se descarga desde el resultado o Sistema; no es un respaldo restaurable de `data.db`.

La reparación de una reserva inválida ya no queda atascada sólo porque una sincronización inicial fallida no guardó el servicio o dejó su cliente local vacío. Exige lecturas frescas del ID exacto y su cliente en UISP, rechaza identidades locales o de caché contradictorias y conserva las protecciones de propiedad aplicada, trabajos y recuperaciones. No crea ni completa el registro local del servicio: modifica sólo los campos malformados confirmados. Un trabajo cancelado o sustituido sin preparación ni deuda puede conservarse sin bloquear la corrección si su identidad, IP prevista y reservas anteriores satisfacen las mismas comprobaciones; su fila no se modifica ni se cuenta como pendiente. El diagnóstico muestra la etapa y los identificadores conocidos para distinguir ausencia local, lectura fallida y propietario diferente. Una identidad inválida o un HTTP 404 sin propietario local comprobable sigue bloqueado.

La edición de IP distingue **aceptado**, **rechazado por UISP** y **sin confirmar**. Muestra el código HTTP y una causa segura cuando están disponibles, sin publicar el cuerpo privado del error. **Verificar nuevamente** sigue siendo de sólo lectura y no repite el PATCH. Un estado observado coincidente no convierte un rechazo previo en una escritura confirmada. Los servicios terminados nunca se reactivan para forzar la edición. **Asignación histórica revisada** conserva su IP en CRM y excluye sólo esa asignación exacta de los conflictos mientras siga terminado el mismo servicio; cambios de cliente, IP, atributo o estado revocan la excepción. No prueba por sí sola que se retiraron recursos en RouterOS.

Validación 2.4.30 aprobada: **39 suites aisladas**, sintaxis de **51 archivos PHP propios** y verificación del instalador de 363 entradas, 130 archivos propios y 13 dependencias fijadas. Informe actual: `dist/verification-2.4.30.json`. SHA-256: `a285440ef37b0abbc2d45ef5f5648cedee0859e6bb238ab29fced06f9c0abf96`. Se comprobó con datos ficticios en navegador la lista con cola vacía, resolución confirmada, contador actualizado, conservación de recursos distintos y diagnóstico de clientes contradictorios en español/inglés y temas oscuro/claro. No se modificaron CRM ni RouterOS de producción.

## Candidato 2.4.29 — corregir reservas inválidas e indicar trabajos pendientes

**Conflictos de IP → Reservas inválidas** muestra los campos malformados de red y del servicio antiguo sin consultar CRM ni inicializar la cola. El error del trabajo también ofrece **Revisar reserva** para un ID bloqueante validado. **Comparar con UISP** comprueba la identidad actual del servicio/cliente, el atributo IP configurado y la familia elegida antes de proponer una corrección local. Sólo cambia los campos inválidos; conserva las reservas válidas, otras familias, CRM, estado del servicio, caché, trabajos y routers. Una IP explícitamente ausente o un HTTP 404 permite retirar únicamente los campos inválidos revisados si la propiedad lo permite. El cliente archivado o servicio terminado por sí solos no autorizan retirar una dirección aplicada.

Sustituir un valor inválido exige una colección CRM fresca, completa y acotada. Compara direcciones/rangos canónicos con CRM, reservas locales y de caché, y propiedad aplicada o pendiente de recuperación. Admite hasta tres servicios seleccionados, un presupuesto de 12 segundos para iniciar lecturas, 28 peticiones y 20 páginas de 500 servicios; una petición iniciada usa el límite del transporte existente. La evidencia desconocida, otras IP malformadas en CRM, páginas repetidas/incompletas o límites superados conservan la reserva. Una IP aplicada válida sólo puede restaurarse para el mismo servicio/cliente y la dirección exacta confirmada; la propiedad aplicada diferente, inválida o sin comprobar conserva su protección. Otro propietario terminado del CRM sólo se excluye mediante su revisión histórica exacta comprobada de nuevo; reactivación o cambios de cliente, IP o atributo revocan esa excepción. La propiedad local, de caché y de la cola mantienen sus protecciones independientes.

Aplicar exige permiso administrativo de edición, mismo origen, revisión privada de diez minutos, comprobación fresca de identidad/configuración/estado local/propiedad y exclusión de mantenimiento, setup, respaldo y exportación de atributos. Guarda un respaldo SQLite privado coherente y cambia atómicamente sólo las fuentes inválidas revisadas. El respaldo se descarga desde el resultado o Sistema. Los trabajos pendientes sin comandos preparados cuya identidad e IP prevista coincidan con UISP pueden conservarse; su reserva anterior exactamente inválida no se confunde con propiedad confirmada del router. Los comandos preparados, trabajos activos, limpiezas pendientes y propiedad ambigua bloquean la corrección. La operación no ejecuta trabajos: los reintentos normales mantienen su programación y orden. Recargar el panel después de una corrección confirmada.

El panel muestra un aviso persistente de **Trabajos pendientes** y un contador lateral, con pendientes/errores, ejecución y recuperación registrada, y acceso directo a Trabajos. Un endpoint local de sólo lectura consulta cada 30 segundos mientras el panel está visible, pausa durante las escrituras administrativas y refresca cuando terminan. No inicializa, importa ni recupera la cola, consulta CRM ni bloquea otros clientes. Los errores conservan el contador anterior como desactualizado; el estado desconocido no se presenta como cero. Evita contar dos veces los trabajos heredados importados y las entradas de recuperación.

Se conservan las velocidades configuradas del plan, incluso cuando son superiores al nombre anunciado. Corregir una reserva no reescribe el plan/estado de los trabajos guardados ni corrige metadatos de planes inválidos; los siguientes reintentos y una sincronización fresca conservan su validación propia.

Validación 2.4.29 aprobada: **38 suites aisladas**, sintaxis de **50 archivos PHP propios** y verificación del instalador de 362 entradas, 129 archivos propios y 13 dependencias fijadas. Informe: `dist/verification-2.4.29.json`. SHA-256: `f3e8c432d11d106e0ff15cbe4a68516526d6b786ad2266511b40c42c3be74710`. Se comprobó con datos ficticios en navegador el aviso persistente de pendientes, la comparación de reservas inválidas, la confirmación explícita, el acceso al respaldo y el inventario actualizado. No se modificaron CRM ni RouterOS de producción.

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
python tools/verify_plugin.py --php RUTA_PHP --node RUTA_NODE --archive dist/ros-plugin-2.4.31-candidate.zip --output dist/verification-2.4.31.json
```

En Windows sin configuración PHP, añadir `--extension-dir RUTA_EXT`. El verificador ejecuta las suites y comprueba sintaxis PHP, correspondencia del código/dependencias y exclusión de datos/fixtures del instalador. El estado de validación de 2.4.33 se indica al principio. Para construirlo: `python tools/build_plugin.py --vendor-dir src/vendor --output dist/ros-plugin-2.4.33-candidate.zip`.

Si una migración queda pendiente por IP duplicadas, usar **Conflictos de IP → Revisar limpieza** para comprobar las reservas obsoletas elegibles, revisar los motivos y confirmar únicamente la selección aprobada. Los trabajos pendientes, la recuperación pendiente y el estado aplicado de una cuenta protegen sus reservas; limpiar IP no retira recursos del router. Conservar el respaldo privado generado, que permanece descargable desde **Sistema** después de recargar. Tras resolver el último conflicto, recargar el panel para completar la migración antes de reanudar las demás escrituras.

## Implementación de estabilización 2.4.7 — candidato para laboratorio

La revisión 2.4.7 corrige los hallazgos de la auditoría profunda: orden A→B→A de operaciones, eventos parciales, credenciales literales, tipos de configuración, colisiones de perfiles, retirada de routers, datos IP inválidos, tamaños y exclusiones IPv6, errores de lectura, pérdida de borradores y recuperación de estado auxiliar. Los guardados de planes y configuración programan trabajos durables, conservando el estado de facturación al preparar cada servicio. El worker continúa solicitudes diferidas cuando un evento está ocupando su servicio.

En 2.4.7 los perfiles activos utilizaban `uisp-plan-ID`; desde 2.4.9 muestran el nombre completo más `[UISP#ID]`. Los servicios sin plan real usan `uisp-service-ID-profile`. La actualización migra cada cuenta cuando se aplica su trabajo: no reconstruye todos los routers al instalar y conserva perfiles anteriores hasta que ya no estén utilizados. El perfil PPP/Hotspot de suspensión configurado es externo y no se sobrescribe. El límite adicional de suspensión utiliza `uisp-service-ID-suspension`, con propiedad comprobada y posición inicial en las colas simples; al reactivar se elimina. Verificar el perfil externo, sus listas de firewall, la posición de las colas dinámicas tras reconectar y FastTrack en laboratorio.

CRM, NMS y autenticación utilizan límites de 15 segundos por petición y 5 por conexión, y rechazan redirecciones. Se mantienen las opciones TLS del SDK. Se actualizan Guzzle a 7.15.5 y PSR-7 a 2.13.1, junto a sus dependencias compatibles; Composer no encontró avisos de seguridad aplicables en la consulta de esta revisión. La compatibilidad declarada continúa en PHP 7.4; las regresiones se ejecutan con PHP 8.5.11 y deben contrastarse con el runtime de UISP.

Los trabajos completados conservan sólo un resumen sin credenciales; las filas históricas se purgan a los 30 días y los recibos mínimos mantienen la deduplicación de eventos. Las instantáneas pendientes y del estado realmente aplicado conservan los datos necesarios para recuperar operaciones. Los bloqueos de petición se comparten entre cron y FPM y protegen también la restauración frente a escrituras administrativas. Detener workers y entregas de la versión anterior al actualizar; sus filas `running` sin bloqueo nuevo mantienen recuperación conservadora por PID.

La guía de pruebas y recuperación se entrega junto al instalador en `docs/PRUEBAS_REALES.md`. Los controles antiguos sin consumidor efectivo (`hs_enable`, `unsuspend_date_fix`, `unsuspend_fix_wait`) se ocultan en el panel; sus valores históricos se conservan.

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
5. **Siguiente etapa:** ampliar la revisión periódica fuera de cuentas propias comprobadas y obtener una revisión fiable del origen para ordenar cronológicamente eventos distintos; topología NMS con asociación persistente `serviceId → deviceId`, actualización de enlaces y adopción controlada de nodos antiguos; auditoría/actualización de dependencias y pruebas en versiones reales de UISP/RouterOS.

Las escrituras automáticas de topología NMS quedan suspendidas en este candidato: el código anterior podía borrar nodos de otros servicios del mismo sitio. Se conserva la lectura NMS. No se deben reactivar las escrituras hasta implementar y verificar la propiedad de cada nodo.

### Migración y configuración

- Conservar el paquete anterior y descargar un respaldo de la versión actualmente instalada. Los respaldos contienen credenciales y deben guardarse en un lugar privado.
- Durante el reemplazo del paquete, pausar el worker y las entregas del webhook anterior para evitar dos versiones modificando los mismos servicios. Reanudarlos después de verificar la migración y la autenticación en laboratorio.
- Por defecto, configurar `webhookSecret` con **sólo una clave aleatoria** de al menos 32 caracteres. En el terminal de UISP, añadir `?webhookToken=EL_MISMO_SECRETO` a la URL pública, o enviar `X-Webhook-Token`. **Guardar el secreto en el plugin no actualiza las peticiones de UISP.** Si el terminal automático no es editable, crear uno manual compatible y evitar entregas duplicadas. Una URL pegada en el campo de clave se rechaza con el diagnóstico `webhook_secret_contains_url`.
- Para funcionar sin clave, activar expresamente **Permitir webhooks sin secreto (compatibilidad)** en los ajustes del plugin y guardar. El terminal puede usar la URL sin token. Este modo admite eventos válidos sin autenticar su emisor; conserva la validación de formato y los permisos del panel. Una actualización no lo activa automáticamente. Véase la [guía de configuración y comprobación](docs/PRUEBAS_REALES.md#configurar-el-secreto-del-webhook).
- Las IP privadas y los usuarios de la zona de clientes ya no autorizan operaciones del plugin. El panel requiere permiso `system/plugins`; los cambios y descargas de respaldos requieren nivel `edit`.
- Los webhooks no pueden utilizar su secreto para llamar operaciones administrativas. Las peticiones administrativas POST de sesión requieren un origen compatible con `ucrmPublicUrl`.
- La primera ejecución importa los trabajos JSON en `data/jobs.db` sin vaciarlos. La limpieza de registros no elimina trabajos pendientes. Los respaldos SQLite antiguos del panel cubren `data.db`; desde 2.4.37, usar el respaldo ZIP completo para conservar también la cola y el estado operativo admitido. Restaurar una BD no revierte los cambios ya aplicados a los routers.
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
