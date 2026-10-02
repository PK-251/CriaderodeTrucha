# El PMV en tres bloques

El trabajo está organizado en tres bloques que se pueden abordar por separado
una vez cerrado el contrato. Esta página existe para poder decir «trabaja solo
en Backend» o «avanza Frontend» sin reconstruir el contexto cada vez.

**Estado: los tres bloques están completos** y verificados de extremo a extremo
en la versión `v0.1.0-pmv`.

## El contrato es la frontera

```
                    ┌──────────────────────────┐
                    │   FUENTE DE VERDAD       │
                    │  docs/openapi.yaml       │
                    │  db/migrations/V*.sql    │
                    └──────────┬───────────────┘
                               │
            ┌──────────────────┼──────────────────┐
            ▼                  ▼                  ▼
      ┌───────────┐      ┌───────────┐      ┌───────────┐
      │ BLOQUE 1  │      │ BLOQUE 2  │      │ BLOQUE 3  │
      │ Backend   │ ───▶ │   APIs    │ ◀─── │ Frontend  │
      │ + BD      │      │ + ingesta │      │  tablero  │
      └───────────┘      └───────────┘      └───────────┘
```

Ningún bloque inventa un campo, endpoint o estado que no esté en el contrato.
Si falta algo, se marca como **pendiente de definir en el contrato** antes de
implementarlo en cualquier frente — no se resuelve en un solo lado.

Cambios al contrato ya aplicados, todos acordados antes de implementarse:

| Cambio | Motivo |
|---|---|
| `V5__autenticacion.sql` añade `personal_access_tokens` | El contrato exige token Bearer y alguien tiene que emitirlo. Es tabla de infraestructura: las **siete del dominio** siguen siendo las del informe |
| `severidad_critica` es un **margen**, no un valor absoluto | El pH y la temperatura son peligrosos en ambas direcciones; una sola columna absoluta solo podría expresar una |
| El notificador WebSocket usa el listener del broker | Evita un contenedor extra para transportar un único tipo de mensaje |
| Laravel 12 en vez de 11 | Composer bloquea las 57 versiones de Laravel 11 por avisos de seguridad sin parche |

---

# BLOQUE 1 — Backend

`backend-core/` · Laravel 12 + PHP 8.3, arquitectura hexagonal · PostgreSQL 16 + TimescaleDB

### B1 · Esquema de datos ✅

Siete tablas de dominio más la de tokens. `lectura` es una *hypertable*
particionada por `medido_en`.

```
db/migrations/   V1__esquema_pmv.sql  V2__indices.sql  V3__auditoria.sql
                 V4__retencion.sql    V5__autenticacion.sql
db/rollback/     U1 … U5
db/seeds/        S1__maestras.sql  S2__lecturas.sql  S3__carga_cp09.sql
```

```bash
./scripts/migrar.sh --seed && ./scripts/revertir.sh
```

**DoD** — migraciones aplicadas y revertidas sin error sobre base limpia; 4
estanques, 12 sensores y 103 718 lecturas sembradas.

### B2 · Núcleo de dominio ✅

PHP plano. Sin Laravel, sin Eloquent, sin PDO, sin MQTT.

```
src/Domain/              18 archivos: entidades, enums, excepciones, ReglaUmbral
src/Application/Puertos/ 7 interfaces
src/Application/UseCases/ RegistrarLectura · EvaluarUmbrales · RegistrarAtencion
```

```bash
docker compose exec -T api-core php vendor/bin/phpunit --testsuite=Unit
```

**DoD** — 64 pruebas unitarias; cobertura de `ReglaUmbral` **100 %**; cero
imports de infraestructura en `src/Domain/`.

### B3 · Persistencia ✅

Repositorios Eloquent que implementan los puertos del dominio.

```
src/Infrastructure/Persistence/   5 repositorios + 6 modelos
```

**DoD** — cada puerto tiene exactamente una implementación, atada en
`app/Providers/HexagonalServiceProvider.php`.

### B4 · Notificadores ✅

```
NotificadorWebSocket   publica en piscigranja/alertas; el navegador se suscribe
                       por el listener WebSocket del broker (puerto 9001)
NotificadorCorreo      SMTP, solo severidad crítica
NotificadorCompuesto   reparte entre canales
```

Un fallo al notificar **no propaga la excepción**: la alerta ya está
persistida, y tumbar la ingesta porque el broker no responde convertiría un
problema de aviso en una pérdida de datos.

### B5 · Pruebas unitarias ✅

```bash
docker compose exec -T api-core php vendor/bin/phpunit --testsuite=Unit --coverage-text
```

| Caso | Escenario | Estado |
|---|---|---|
| CP-01 | Umbral con mínimo mayor al máximo | ✅ `ErrorDeValidacion` con el campo |
| CP-05 | pH 14.8 fuera del rango del electrodo | ✅ descartada, sin alerta |
| CP-06 | OD 5.1 con mínimo 5.5 y crítico 5.0 | ✅ advertencia |
| CP-08 | Tres lecturas consecutivas fuera de rango | ✅ una alerta, un aviso |

**DoD del bloque** — PHPStan nivel 6 `[OK]` · Pint `PASS` en 88 archivos ·
93 pruebas (64 unitarias + 29 de feature) · **100 %** de cobertura del dominio.

```bash
docker compose exec -T api-core php vendor/bin/pint --test
docker compose exec -T api-core php vendor/bin/phpstan analyse --memory-limit=1G
```

---

# BLOQUE 2 — APIs

`docs/openapi.yaml` + `backend-core/src/Infrastructure/Http/` + `ingesta-service/`

### A1 · Contrato OpenAPI ✅

799 líneas. Los 6 endpoints de la Tabla 2 con sus códigos exactos, más la
emisión de tokens que el contrato exige pero la tabla no lista.

```bash
npx @stoplight/spectral-cli lint docs/openapi.yaml --ruleset .spectral.yaml
```

**DoD** — `No results with a severity of 'error' found!`

### A2 · Endpoints REST ✅

| Endpoint | Método | Historia | Códigos |
|---|---|---|---|
| `/api/v1/lecturas` | POST | HU-02 | 201 · 207 · 422 |
| `/api/v1/estanques` | GET | HU-03 | 200 |
| `/api/v1/estanques` | POST | HU-01 | 201 · 422 |
| `/api/v1/estanques/{id}/lecturas` | GET | HU-03 | 200 |
| `/api/v1/alertas` | GET | HU-04 | 200 |
| `/api/v1/alertas/{id}/atencion` | POST | HU-05 | 201 · 409 · 403 |

Autenticación Bearer con control por rol en dos capas: el middleware
`ExigirRol` responde 403 temprano, y el caso de uso vuelve a verificarlo. La
duplicación es deliberada — la regla de quién puede cerrar una alerta pertenece
al negocio y debe seguir vigente si mañana el caso de uso se invoca desde una
cola o desde la consola.

### A3 · Servicio de ingesta MQTT ✅

```
validador.py     interpreta el tópico y la carga; clasifica por rango físico
bufer.py         cola SQLite persistente, dedup por (nodo, instante)
cliente_core.py  envío por lote; traduce 201/207/422/401/5xx a decisiones
subscriber.py    suscripción con sesión persistente
despachador.py   vacía el búfer hacia el núcleo
```

El búfer es un archivo y no una lista en memoria porque un corte de enlace
suele venir con un corte eléctrico: la cola en memoria se habría perdido entera
al reiniciarse el contenedor.

```bash
docker compose exec -T ingesta-service python -m pytest -q
docker compose exec -T ingesta-service python -m ruff check .
```

**DoD** — Ruff `All checks passed` · 70 pruebas · cobertura **98.92 %**

### A4 · Pruebas de contrato e integración ✅

Cadena completa `nodo → MQTT → ingesta → núcleo → BD → alerta → tablero`
contra los servicios reales, sin dobles.

```bash
docker compose exec -T -w /srv/tests-integracion ingesta-service python -m pytest . -v -o addopts=""
```

| Caso | Escenario | Estado |
|---|---|---|
| CP-02 | Código de estanque duplicado | ✅ 422, sin segundo registro |
| CP-03 | Lectura publicada llega a la base | ✅ con su marca y calidad |
| CP-04 | Lote retenido y reenviado | ✅ **60 almacenadas, 0 duplicados** |
| CP-07 | OD 4.2 bajo el crítico | ✅ crítica en **27.6 s** |
| CP-10 | Atención duplicada | ✅ 409 con el registro existente |

**DoD del bloque** — contratos conformes · Ruff aprobado · CP-04 sin pérdidas
ni duplicados · **RNF-01 verificado: 27.6 s frente a los 300 s del presupuesto**

---

# BLOQUE 3 — Frontend

`dashboard/` · Next.js 14 + React 18 + TypeScript

### F1 · Maquetado ✅

Una sola pantalla operativa, **mobile-first a 375 px**: el operador consulta el
tablero en campo, con el celular. Objetivos táctiles de 44 px y campos de 16 px
para que iOS no haga zoom al enfocar.

Orden vertical por urgencia, que en un celular es el orden de lectura: alertas
abiertas → estado de los estanques → tendencia → detalle.

### F2 · Componentes ✅

```
TarjetaEstanque     semáforo, últimos valores, antigüedad, «sin comunicación»
PanelAlertas        alertas abiertas con control por rol
FormularioAtencion  registro de la intervención, con el camino del 409
GraficoTendencia    SVG con banda del rango aceptable y cruz de hover
TablaLecturas       vista de tabla del gráfico
EscuchaAlertas      suscripción MQTT-WebSocket
```

El semáforo **nunca comunica solo con color**: cada estado lleva icono y
palabra. El operador puede ser daltónico y la pantalla se mira a pleno sol.

«Sin comunicación» desplaza al semáforo: un estanque que no reporta podría
estar en riesgo sin que el sistema lo sepa, y mostrarlo como «normal» sería
afirmar algo que no podemos saber.

### F3 · Conexión a la API ✅

Renderizado en servidor. El token vive en una cookie `httpOnly` y nunca llega a
JavaScript del navegador: en un sistema donde el token permite registrar
lecturas y cerrar alertas, `localStorage` lo dejaría al alcance de cualquier
script inyectado.

Los códigos del contrato se tratan como resultados con nombre, no como fallos:
el 409 trae el registro existente y la interfaz lo muestra.

### F4 · Tiempo real ✅

El navegador se suscribe a `piscigranja/alertas` por el listener WebSocket del
broker. Al recibir un aviso **refresca desde el servidor** en lugar de insertar
la alerta en el cliente, para que la pantalla muestre lo que la base confirma.

### F5 · Pruebas de UI ✅

```bash
docker compose exec -T dashboard npx vitest run
```

| Caso | Escenario | Estado |
|---|---|---|
| CP-09 | 20 estanques, 518 484 lecturas | ✅ **2.20 s** frente a los 3 s del presupuesto |
| CP-10 | Atención duplicada | ✅ muestra el registro existente, no un error genérico |

**DoD del bloque** — build de producción sin advertencias · ESLint y TypeScript
estricto sin errores · 20 pruebas · sin scroll horizontal a 375 px

```bash
docker compose exec -T dashboard npx next lint
docker compose exec -T dashboard npx tsc --noEmit
docker compose exec -T dashboard npx next build
```

---

# Verificación de los tres bloques

Todo de una vez, en el orden en que falla más barato:

```bash
./scripts/preparar_bd_pruebas.sh
```

```bash
docker compose exec -T api-core php vendor/bin/pint --test && docker compose exec -T api-core php vendor/bin/phpstan analyse --memory-limit=1G && docker compose exec -T api-core php vendor/bin/phpunit
```

```bash
docker compose exec -T ingesta-service python -m ruff check . && docker compose exec -T ingesta-service python -m pytest -q
```

```bash
docker compose exec -T dashboard npx next lint && docker compose exec -T dashboard npx tsc --noEmit && docker compose exec -T dashboard npx vitest run
```

```bash
docker compose exec -T -w /srv/tests-integracion ingesta-service python -m pytest . -v -o addopts=""
```

## Resumen de la verificación

| Bloque | Pruebas | Calidad | Cobertura |
|---|---|---|---|
| 1 · Backend | 93 | PHPStan 6 `[OK]` · Pint `PASS` | 100 % del dominio |
| 2 · APIs | 70 + 13 integración | Ruff `passed` · Spectral sin errores | 98.92 % ingesta |
| 3 · Frontend | 20 | ESLint · TS estricto | — |

## Requisitos no funcionales, medidos

| | Medido | Presupuesto |
|---|---|---|
| **RNF-01** · medición → alerta visible | **27.6 s** | < 300 s |
| **RNF-02** · búfer sin pérdida | **60/60, 0 duplicados** | sin pérdidas |
| **RNF-03** · tablero con 20 estanques | **2.20 s** | < 3 s |
| **RNF-04** · auditoría de escrituras | usuario y marca temporal en toda escritura | — |

## Los 10 casos de la Matriz 4.1

| Caso | Dónde se verifica | Bloque |
|---|---|---|
| CP-01 | `tests/Unit/ValidacionDeEntidadesTest` + integración | 1 · 2 |
| CP-02 | `tests/Feature/ApiEstanquesTest` + integración | 2 |
| CP-03 | `tests/test_cadena_ingesta.py` + integración | 2 |
| CP-04 | `tests/test_bufer.py` + integración | 2 |
| CP-05 | `tests/Unit/ReglaUmbralTest` + `test_validador.py` + integración | 1 · 2 |
| CP-06 | `tests/Unit/ReglaUmbralTest` + integración | 1 · 2 |
| CP-07 | `tests/Unit/ReglaUmbralTest` + integración (con tiempo) | 1 · 2 |
| CP-08 | `tests/Unit/EvaluarUmbralesTest` + integración | 1 · 2 |
| CP-09 | `tests-integracion/test_cadena_pmv.py` | 3 |
| CP-10 | `tests/PanelAlertas.test.tsx` + integración | 2 · 3 |
