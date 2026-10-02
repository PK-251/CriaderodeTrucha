# Guía de exposición del PMV

Qué decir al presentar el Primer Incremento del SIPPT, en el orden en que
conviene decirlo. Cada sección trae la idea central, el detalle que la sostiene
y las preguntas que es razonable esperar.

---

## 1. Empieza por el problema, no por la tecnología

> «El manejo de una piscigranja de trucha es **reactivo**: las variaciones de
> oxígeno disuelto, temperatura y pH solo se detectan cuando ya hay mortalidad
> visible. Recién ahí se reporta, se interviene y se registra. La organización
> actúa **después** del daño.»

Y el cambio que propone el sistema, en una frase:

> «El PMV sustituye esa detección tardía por un aviso automático mientras la
> condición todavía es reversible.»

**Dato que vale más que cualquier explicación técnica:** de 2 mediciones
diarias anotadas a mano por estanque, a **288 lecturas automáticas por sensor
y día**. Y el tiempo hasta el aviso baja de las 6–12 horas que separan dos
rondas manuales a **27.6 segundos medidos**.

> ⚠️ No abras con «usamos Laravel y Next.js». La tecnología es el *cómo*; el
> problema es el *por qué*. Si el jurado no entiende el problema, el resto
> suena a lista de herramientas.

---

## 2. La metodología: por qué esta y no otra

### Qué decir

> «El modelo de proceso no se eligió por ser el más conocido, sino porque las
> características del proyecto exigen determinadas capacidades.»

El modelo es **iterativo-incremental, gestionado con Scrum adaptado, con
prácticas de DevOps y un ciclo MLOps/DataOps previsto para el componente
predictivo**.

### Las tres razones

| Característica del proyecto | Qué exige del proceso |
|---|---|
| El modelo predictivo no puede especificarse por adelantado: no se sabe qué precisión alcanzará hasta tener datos reales | Un ciclo que admita **experimentación**, no uno que exija cerrar requisitos antes de construir |
| Los umbrales y reglas de alimentación cambian con la estación y la etapa de crecimiento | Entregas **cortas** que incorporen lo aprendido, no un plan maestro fijado al inicio |
| El sistema opera 24 h junto a los estanques | Integración, despliegue y monitoreo **automatizados**, no una etapa final |

### Por qué no Cascada

> «En la matriz de comparación, Cascada obtuvo 7 puntos sobre 30 — el último
> lugar. Postergar las pruebas al final eleva el costo de corregir errores de
> diseño, y en un componente de IA es inviable: obligaría a prometer el
> rendimiento de un modelo sin haberlo entrenado.»

### Y la frase que cierra el argumento

> «Elegir el modelo con mayor puntaje sería un error metodológico: los modelos
> comparados operan en niveles distintos y no son excluyentes. MLOps resuelve
> la operación del modelo, pero no cómo organizar el trabajo. Por eso la
> decisión final combina ciclo de vida, marco de gestión y prácticas
> operativas.»

### Si preguntan «¿y dónde se ve eso en el código?»

| Decisión del proceso | Dónde se materializa |
|---|---|
| Iterativo-incremental | 6 incrementos ordenados por dependencia de datos; este es el 1.º |
| Scrum | Ramas `feature/hu{NN}-*`, una por historia, fusionadas a `develop` |
| DevOps | `.github/workflows/ci.yml` — 6 trabajos en cada cambio |
| MLOps/DataOps | **Todavía no se activa.** El PMV construye su insumo: la serie temporal etiquetada con intervenciones |

---

## 3. La estructura: cómo está organizado

### Qué decir

> «Cada carpeta de primer nivel es una pieza técnica, y el contrato es la
> frontera entre ellas.»

```
db/                  esquema y datos          → Base de datos
backend-core/        núcleo + API REST        → Backend
ingesta-service/     suscriptor MQTT          → APIs externas
dashboard/           tablero                  → Frontend
docs/openapi.yaml    EL CONTRATO              → frontera
tests-integracion/   cadena completa          → verificación
```

El trabajo se organizó en **tres bloques** que avanzan por separado una vez
cerrado el contrato: Backend, APIs y Frontend. Detalle en
[`docs/BLOQUES.md`](BLOQUES.md).

### La regla que sostiene todo

> «El contrato —`openapi.yaml` más el esquema de datos— es la fuente de verdad.
> Ningún frente inventa un campo, endpoint o estado que no esté ahí. Si falta
> algo, se marca como pendiente de definir **en el contrato** antes de
> implementarlo en cualquier lado.»

Eso es lo que permitió que los tres frentes avanzaran sin bloquearse.

---

## 4. El backend: la parte que hay que saber explicar bien

### Qué decir, en una frase

> «Está construido con **arquitectura hexagonal**: el núcleo de negocio no sabe
> que existe una base de datos.»

### Cómo explicarlo sin jerga

```
        ┌─────────────────────────────────────┐
        │  Infrastructure  (adaptadores)      │
        │  HTTP · Eloquent · MQTT · correo    │
        │     ┌─────────────────────────┐     │
        │     │  Application (puertos)  │     │
        │     │    ┌───────────────┐    │     │
        │     │    │    Domain     │    │     │
        │     │    │  PHP puro     │    │     │
        │     │    └───────────────┘    │     │
        │     └─────────────────────────┘     │
        └─────────────────────────────────────┘
             la dependencia apunta ADENTRO
```

> «`ReglaUmbral`, que es la clase que decide si una lectura es peligrosa, son
> **14 líneas de PHP** sin base de datos, sin framework y sin broker. Por eso
> se prueba en milisegundos, y por eso cuando llegue el modelo predictivo será
> otro adaptador detrás del mismo puerto, sin reescribir el tablero.»

### El detalle que demuestra que no es un adorno

> «Hay una prueba que **lee el código fuente** y falla si alguien escribe
> `use Illuminate\…` dentro de `src/Domain/`. Y el pipeline lo vuelve a
> comprobar por su cuenta: si alguien desactivara esa prueba, la verificación
> seguiría impidiéndolo.»

### El corazón del PMV, con números

`ReglaUmbral` decide así, para el estanque EST-03 con mínimo 5.5 y margen 0.5:

| Lectura | Resultado | Por qué |
|---|---|---|
| 7.8 mg/L | sin alerta | dentro del rango |
| 5.1 mg/L | **advertencia** | bajo el mínimo, sobre el crítico |
| 4.2 mg/L | **crítica** | bajo el límite crítico de 5.0 → además se notifica por correo |

### Si preguntan por qué el margen y no un valor absoluto

> «Porque el pH y la temperatura son peligrosos en **ambas** direcciones. Una
> sola columna con un valor absoluto solo podría expresar una de las dos. Con
> margen, el límite crítico se calcula a cada lado.»

---

## 5. La base de datos

> «PostgreSQL 16 con **TimescaleDB**. Siete tablas de dominio, y `lectura` es
> una *hypertable* particionada por tiempo.»

### Por qué TimescaleDB y no PostgreSQL a secas

> «288 lecturas por sensor y día. Con 12 sensores son 1.26 millones de filas al
> año, y el tablero tiene que responder en menos de 3 segundos. El particionado
> por tiempo es lo que mantiene esa consulta rápida mientras el histórico crece.»

### El hallazgo que conviene contar

> «La consulta obvia para obtener el último valor de cada sensor tardaba
> **21.7 ms** y recorría las 102 mil lecturas para devolver 12 filas. Cambiándola
> a un patrón `LATERAL … LIMIT 1` pasó a **0.158 ms** — 137 veces más rápido— y,
> lo importante, su coste crece con el número de sensores y no con el tamaño del
> histórico. Con 20 estanques y medio millón de lecturas sigue en 0.7 ms.»

Eso demuestra que se midió, no que se supuso.

### Las restricciones hacen cumplir las reglas

> «No son validaciones defensivas duplicadas: son la última línea que impide que
> un defecto del código corrompa los datos.»

| Restricción | Qué protege |
|---|---|
| `PRIMARY KEY (sensor_id, medido_en)` | El reenvío del búfer no duplica lecturas |
| `UNIQUE … WHERE estado = 'abierta'` | Una sola alerta abierta por estanque y parámetro |
| `UNIQUE (alerta_id)` en atenciones | No se puede atender dos veces la misma alerta |

---

## 6. Las APIs

> «Seis endpoints REST especificados en OpenAPI **antes** de escribir el código,
> y validados automáticamente con Spectral en cada cambio.»

| Endpoint | Historia | Códigos |
|---|---|---|
| `POST /api/v1/lecturas` | HU-02 | 201 · 207 · 422 |
| `GET /api/v1/estanques` | HU-03 | 200 |
| `POST /api/v1/estanques` | HU-01 | 201 · 422 |
| `GET /api/v1/estanques/{id}/lecturas` | HU-03 | 200 |
| `GET /api/v1/alertas` | HU-04 | 200 |
| `POST /api/v1/alertas/{id}/atencion` | HU-05 | 201 · 409 · 403 |

### El servicio de ingesta y la pieza de la que estar orgulloso

> «El servicio recibe las lecturas por MQTT y las guarda en un **búfer
> persistente** antes de entregarlas. Es un archivo SQLite y no una lista en
> memoria, y la razón es concreta: un corte de enlace en una piscigranja rural
> suele venir acompañado de un corte eléctrico. Con la cola en memoria, al
> reiniciarse el servicio se habrían perdido todas las lecturas del corte.»

> «Probado con 20 minutos de enlace caído: **60 lecturas retenidas, 60
> almacenadas, 0 duplicados** incluso reenviando el lote dos veces.»

### Si preguntan por el 207

> «201 significa que el lote entró completo; **207** que alguna lectura se
> almacenó marcada como descartada por calidad. Es un resultado, no un fallo:
> si se tratara como error, el servicio la reintentaría en bucle para siempre.»

---

## 7. El frontend

> «Una sola pantalla operativa, diseñada **mobile-first** y probada a 375 px.»

### La justificación, que no es estética

> «El operador de estanques no está en una oficina: consulta el tablero en
> campo, con el celular, muchas veces con las manos mojadas. Por eso los
> objetivos táctiles son de 44 px y los campos de texto de 16 px — por debajo de
> eso, iOS hace zoom al enfocar y descoloca la pantalla.»

### Las dos decisiones de accesibilidad que conviene mencionar

> «El semáforo **nunca comunica solo con color**: cada estado lleva icono y
> palabra. El operador puede ser daltónico, y la pantalla se mira a pleno sol.»

> «Un estanque que no reporta hace más de 15 minutos se marca como *sin
> comunicación*, y eso **desplaza al semáforo**. Mostrarlo como "normal" sería
> afirmar algo que el sistema no puede saber.»

### El tiempo real

> «Cuando se genera una alerta, aparece en el tablero **sin recargar la página**.
> El navegador se suscribe al mismo broker MQTT por WebSocket. Y al recibirla,
> el tablero se refresca **desde el servidor** en lugar de insertar la alerta en
> el cliente, para que la pantalla muestre lo que la base confirma y no una
> versión optimista.»

### Seguridad

> «El token de sesión vive en una cookie `httpOnly` y nunca llega a JavaScript.
> En un sistema donde el token permite registrar lecturas y cerrar alertas,
> guardarlo en `localStorage` lo dejaría al alcance de cualquier script
> inyectado en la página.»

---

## 8. Las pruebas: la sección que distingue un trabajo serio

### Qué decir primero

> «Las pruebas no se dejaron para el final. El *Definition of Done* del
> incremento exige que estén en verde **antes** de declararlo terminado.»

### Los cuatro niveles

| Nivel | Cuántas | Qué verifica | Sin qué corre |
|---|---|---|---|
| **Unitarias** (núcleo) | 64 | Entidades y `ReglaUmbral` | BD, broker, framework |
| **De feature** (API) | 29 | Los 6 endpoints contra PostgreSQL real | — |
| **De servicio** (ingesta) | 76 | Validador, búfer, cliente | Broker real |
| **De interfaz** (tablero) | 20 | Componentes y el camino del 409 | Navegador real |
| **De integración** | 13 | La cadena completa, sin dobles | — |

**Total: 202 pruebas.**

### La cobertura que importa

> «**100 % del núcleo de dominio** — las 21 clases, 197 líneas. Y 98.92 % del
> servicio de ingesta. No es cobertura por cobertura: `ReglaUmbral` es la clase
> de la que depende que una trucha reciba ayuda a tiempo.»

### Los diez casos de la Matriz 4.1

Todos verificados, varios en más de un nivel:

| Caso | Escenario | Resultado |
|---|---|---|
| CP-01 | Umbral con mínimo mayor al máximo | 422 señalando el campo |
| CP-02 | Código de estanque duplicado | 422, sin segundo registro |
| CP-03 | Lectura publicada en MQTT | Persiste con marca y calidad |
| CP-04 | 20 min de enlace caído | 60/60, cero duplicados |
| CP-05 | pH 14.8 (imposible) | Descartada, sin alerta |
| CP-06 | OD 5.1 mg/L | Advertencia |
| CP-07 | OD 4.2 mg/L | Crítica en **27.6 s** |
| CP-08 | Tres lecturas consecutivas | Una sola alerta |
| CP-09 | 20 estanques, 518 484 lecturas | **2.20 s** |
| CP-10 | Atención duplicada | 409 con el registro existente |

### Si preguntan «¿y cómo sé que no están inventadas?»

> «La suite de integración no usa dobles: habla con el broker, el núcleo, la
> base y el tablero reales, y al terminar **imprime los tiempos medidos**. Se
> ejecuta con un comando y tarda cinco minutos porque espera de verdad a que la
> ingesta despache el búfer.»

```bash
docker compose exec -T -w /srv/tests-integracion ingesta-service python -m pytest . -v -o addopts=""
```

---

## 9. La integración continua

> «Cada cambio dispara seis trabajos en paralelo. Si cualquiera falla, no se
> puede fusionar.»

| Trabajo | Qué comprueba |
|---|---|
| migraciones | Aplica, revierte y vuelve a aplicar sobre base limpia |
| backend | Formato, análisis estático nivel 6, pruebas, pureza del núcleo |
| ingesta | Linter y pruebas con umbral de cobertura |
| dashboard | Linter, tipos, pruebas y construcción de producción |
| contrato | Que el OpenAPI siga siendo válido |
| verificación | Puerta final |

### El detalle que vale la pena contar

> «Las migraciones se prueban **en los dos sentidos**. Una migración que solo se
> verifica hacia adelante deja al equipo sin salida el día que haya que dar
> marcha atrás en producción.»

### Y una anécdota honesta que suma, no resta

> «El pipeline falló las tres primeras veces, y los tres fallos eran reales:
> una ruta absoluta que solo existía en nuestro contenedor, un archivo de
> bloqueo generado con una biblioteca de C distinta a la del servidor, y una
> suite que dependía de cómo se invocara pytest. Ninguno se veía ejecutando las
> pruebas en nuestras máquinas. Es exactamente para lo que sirve.»

Contar esto demuestra que el pipeline **hace algo**, en lugar de ser un adorno
que siempre estuvo verde.

---

## 10. Los resultados, al cerrar

| Requisito | Presupuesto | **Medido** |
|---|---|---|
| RNF-01 · medición → alerta | < 5 min | **27.6 s** |
| RNF-02 · búfer sin pérdida | sin pérdidas | **60/60, 0 duplicados** |
| RNF-03 · tablero, 20 estanques | < 3 s | **2.20 s** |
| RNF-04 · auditoría | toda escritura | usuario y marca temporal |

### El cierre

> «El operador deja de depender de la ronda manual para descubrir una condición
> de riesgo: recibe el aviso mientras la condición aún es reversible, y registra
> la acción tomada en el mismo lugar. Esa bitácora es, además, el etiquetado que
> el modelo predictivo necesitará en el incremento 4 — el PMV construye
> deliberadamente su propio insumo.»

---

## Preguntas difíciles y cómo responderlas

**«¿Dónde está la inteligencia artificial? El título dice *Sistema Inteligente*.»**

> «Fuera de este incremento, y a propósito. El modelo depende de un histórico
> que la piscigranja no tiene al iniciar. Si los primeros incrementos se
> planificaran alrededor de la IA, no habría nada que entregar durante meses. El
> orden de los seis incrementos es deliberado: monitoreo, alertas por umbral y
> registro digital entregan valor **mientras se acumula el histórico**; el
> modelo llega en el incremento 4, cuando ya hay con qué entrenarlo.»

**«¿Por qué tantas carpetas y capas para algo que podría ser un CRUD?»**

> «Porque el componente que llega después no es un CRUD. Cuando el modelo
> predictivo reemplace o complemente la regla determinística, será un adaptador
> más detrás del mismo puerto. Si la lógica estuviera mezclada con el acceso a
> datos, habría que reescribir la ingesta y el tablero.»

**«¿Esto funciona de verdad o es una maqueta?»**

> «Se levanta con un comando y se puede provocar cada escenario sin hardware:
> `simular_sensores.py --critico` baja el oxígeno de un estanque y en segundos
> aparece la alerta en el tablero y el correo en la bandeja. Todo lo que se
> reporta está medido, no estimado.»

**«¿Qué harían distinto?»**

> «Tres cosas. Fijar el umbral de cobertura desde el primer día en vez de a
> mitad. Verificar el pipeline reproduciendo el entorno del servidor y no el
> nuestro — nos costó tres intentos. Y declarar antes la semántica del margen
> crítico, que fue la única decisión de diseño que tuvimos que explicar dos
> veces.»

---

## Chuleta de números

| | |
|---|---|
| Historias del PMV | 5 (HU-01 a HU-05) |
| Casos de prueba | 10 (CP-01 a CP-10), todos en verde |
| Pruebas totales | 202 |
| Cobertura del dominio | 100 % |
| Endpoints REST | 6 + autenticación |
| Tablas | 7 de dominio + 1 de infraestructura |
| Lecturas sembradas | 103 718 (30 días) |
| Banco de medición | 518 484 lecturas, 20 estanques |
| Servicios en ejecución | 6 contenedores |
| Tiempo hasta la alerta | 27.6 s |
| Carga del tablero | 2.20 s |
