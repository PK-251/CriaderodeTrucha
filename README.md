# SIPPT — Sistema Inteligente para la Optimización de la Producción Piscícola de Trucha

**Primer Incremento · Producto Mínimo Viable (PMV) · `v0.1.0-pmv`**

Asignatura Procesos de Software (ASUC01702) — Universidad Continental, Huancayo.

> **Estado actual: Fase 0 — andamiaje.** La estructura del monorepo y el entorno
> de contenedores están en pie. Las secciones marcadas con 🔜 se completan en la
> fase indicada, conforme avanza el plan de construcción.

---

## 1. Descripción del proyecto

El manejo tradicional de una piscigranja de trucha es **reactivo**: las variaciones
de oxígeno disuelto, temperatura y pH solo se detectan cuando ya hay estrés o
mortalidad visible. El PMV sustituye esa detección tardía por un aviso automático
mientras la condición todavía es reversible.

### Alcance del PMV (Incremento 1)

| Historia | Actor | Funcionalidad |
|---|---|---|
| HU-01 | Técnico acuícola | Registrar estanques (volumen, biomasa, etapa) y configurar umbrales aceptables y críticos de OD, temperatura y pH |
| HU-02 | Nodo sensor IoT | Transmitir lecturas cada 5 min vía MQTT, con búfer y reenvío sin duplicados |
| HU-03 | Operador | Tablero con semáforo por estanque, última lectura y marca de «sin comunicación» |
| HU-04 | Sistema | Evaluar cada lectura contra los umbrales y generar alerta clasificada por severidad, sin duplicar alertas abiertas |
| HU-05 | Operador | Registrar la acción tomada frente a una alerta y cerrarla con observación |

### Requisitos no funcionales exigidos al código

- **RNF-01** — aviso al operador en menos de 5 minutos desde la medición
- **RNF-02** — búfer del gateway sin pérdida de lecturas ante caída de enlace
- **RNF-03** — tablero de hasta 20 estanques en menos de 3 segundos
- **RNF-04** — toda escritura auditada con usuario y marca temporal

### Fuera del alcance

Modelo predictivo de aprendizaje automático, cálculo de ración óptima y FCR,
módulo de sanidad veterinaria, reportes gerenciales, aplicación móvil nativa y
el parámetro amonio (NH₄), que se difiere al Incremento 2.

---

## 2. Requisitos previos

| Herramienta | Versión mínima | Obligatorio |
|---|---|---|
| Docker | 24 o superior | Sí |
| Docker Compose | v2 | Sí |
| Git | — | Sí |
| PHP | 8.3 | Opcional — solo para ejecución sin contenedores |
| Node.js | 20 | Opcional — solo para ejecución sin contenedores |
| Python | 3.12 | Opcional — solo para ejecución sin contenedores |
| Composer | 2.x | Opcional — recomendado para PHPStan y Pint en local |

Todo el stack (PHP 8.3, Node 20, Python 3.12, PostgreSQL 16 + TimescaleDB y
Mosquitto) viene fijado dentro de los contenedores; no hace falta instalarlo
en el sistema anfitrión.

---

## 3. Instalación y ejecución

```bash
git clone https://github.com/PK-251/CriaderodeTrucha.git
cd CriaderodeTrucha
cp .env.example .env
docker compose up -d --build
```

| Servicio | URL | Puerto |
|---|---|---|
| Tablero | http://localhost:3000 | 3000 |
| API | http://localhost:8000/api/v1 | 8000 |
| Correo capturado (Mailpit) | http://localhost:8025 | 8025 |
| Broker MQTT (servicios) | `mqtt://localhost:1883` | 1883 |
| Broker MQTT (navegador) | `ws://localhost:9001` | 9001 |
| PostgreSQL | `localhost:5432` | 5432 |

Comprobar el estado de los contenedores:

```bash
docker compose ps
```

---

## 4. Base de datos

PostgreSQL 16 + TimescaleDB. Migraciones numeradas, con su reversión.

```bash
./scripts/migrar.sh --seed      # aplica V1–V4 y carga los datos semilla
./scripts/revertir.sh           # revierte U4–U1 y deja la base vacía
```

| Migración | Contenido |
|---|---|
| `V1__esquema_pmv.sql` | 3 tipos enumerados, 7 tablas, hypertable `lectura`, `idx_lectura_reciente` |
| `V2__indices.sql` | Índices de soporte al tablero y al panel de alertas |
| `V3__auditoria.sql` | Columnas de auditoría y disparador de marca temporal (RNF-04) |
| `V4__retencion.sql` | Fragmentos de 30 días, compresión a los 90 días, retención de 36 meses |

**Datos semilla** — 3 usuarios (uno por rol), 4 estanques en distintas etapas
productivas, 12 umbrales, 12 sensores y **103 692 lecturas** que cubren 30 días
con ciclo diurno realista. `EST-03` reproduce el escenario del informe: umbral
mínimo de oxígeno disuelto 5.5 mg/L con margen crítico 0.5, de modo que el
límite crítico es exactamente 5.0 (casos CP-06 y CP-07).

Diagrama entidad-relación: [`docs/arquitectura/er-pmv.md`](docs/arquitectura/er-pmv.md).
Consultas con presupuesto de tiempo: [`docs/arquitectura/consultas-criticas.md`](docs/arquitectura/consultas-criticas.md).

---

## 5. Simulación de sensores

El servicio de ingesta se suscribe a `piscigranja/{estanque}/{parametro}`. Para
publicar una lectura a mano y ver la cadena completa en acción:

```bash
docker compose exec mosquitto mosquitto_pub -h 127.0.0.1 -t piscigranja/EST-03/od_mgl -m '{"codigo_nodo":"N-07","medido_en":"2026-09-18T17:42:10-05:00","valor":4.2}'
```

Estado del servicio y del búfer:

```bash
curl http://localhost:8001/metricas
```

`pendientes` es el indicador que revela un corte de enlace: si crece de forma
sostenida, las lecturas se están acumulando porque el núcleo no responde. El
búfer es un SQLite en un volumen con nombre, de modo que sobrevive al reinicio
del contenedor (RNF-02).

🔜 *Fase 6.* `scripts/simular_sensores.py` automatizará los escenarios de
advertencia, crítico y corte de enlace sin hardware físico.

---

## 6. Ejecución de pruebas

```bash
./scripts/preparar_bd_pruebas.sh
```

```bash
docker compose exec -T api-core php vendor/bin/phpunit
```

| Suite | Contenido | Requiere base de datos |
|---|---|---|
| `Unit` | Núcleo hexagonal: entidades, `ReglaUmbral` y casos de uso sobre dobles en memoria | No |
| `Feature` | Los seis endpoints contra PostgreSQL real, en la base `sippt_test` | Sí |

Calidad:

```bash
cd backend-core && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G
```

```bash
npx @stoplight/spectral-cli lint docs/openapi.yaml --ruleset .spectral.yaml
```

Servicio de ingesta:

```bash
docker compose exec -T ingesta-service python -m pytest -q
```

```bash
docker compose exec -T ingesta-service python -m ruff check .
```

Tablero:

```bash
docker compose exec -T dashboard npx vitest run
```

```bash
docker compose exec -T dashboard npx tsc --noEmit && docker compose exec -T dashboard npx next lint
```

> La construcción de producción (`npx next build`) sobrescribe el directorio
> `.next` del servidor de desarrollo y lo deja inservible. Ejecútala solo con el
> contenedor detenido, o reinicia el servicio después:
> `docker compose restart dashboard`.

**CP-09 · RNF-03** — el tablero debe resolver 20 estanques en menos de 3 s. Los
datos semilla traen cuatro, así que la medición necesita su propio banco:

```bash
docker compose exec -T postgres-timescale psql -U sippt -d sippt < db/seeds/S3__carga_cp09.sql
```

Se retira con `S3__limpiar_cp09.sql`.

🔜 *Fase 6.* Suite de integración de la cadena completa.

## 6.1 El tablero

Una sola pantalla operativa, diseñada mobile-first y probada a 375 px de ancho.

| Zona | Qué muestra |
|---|---|
| Alertas abiertas | Ordenadas por fecha descendente, con el formulario de atención (HU-05) |
| Estado de los estanques | Tarjeta por estanque con semáforo, últimos valores y antigüedad (HU-03) |
| Tendencia | Gráfico del parámetro en riesgo, con la banda del rango aceptable |
| Últimas lecturas | Tabla con los valores exactos |

El semáforo **nunca comunica solo con color**: cada estado lleva icono y
palabra. Un estanque que no reporta desde hace más de 15 minutos se marca como
*sin comunicación*, y eso desplaza al semáforo — mostrarlo como «normal» sería
afirmar algo que el sistema no puede saber.

Las alertas aparecen **sin recargar**: el navegador se suscribe a
`piscigranja/alertas` por el listener WebSocket del broker, y al recibir un
aviso refresca el tablero desde el servidor.

El token vive en una cookie `httpOnly` y nunca llega a JavaScript del
navegador: el renderizado ocurre en el servidor y es él quien llama a la API.

## 6.2 Contrato de la API

Los seis endpoints de la Tabla 2 del informe están especificados en
[`docs/openapi.yaml`](docs/openapi.yaml) (OpenAPI 3.0, validado con Spectral).

| Endpoint | Método | Historia | Códigos |
|---|---|---|---|
| `/api/v1/lecturas` | POST | HU-02 | 201 · 207 · 422 |
| `/api/v1/estanques` | GET | HU-03 | 200 |
| `/api/v1/estanques` | POST | HU-01 | 201 · 422 |
| `/api/v1/estanques/{id}/lecturas` | GET | HU-03 | 200 |
| `/api/v1/alertas` | GET | HU-04 | 200 |
| `/api/v1/alertas/{id}/atencion` | POST | HU-05 | 201 · 409 · 403 |

Autenticación por token Bearer (`POST /api/v1/auth/login`). Usuarios semilla:
`operador@`, `tecnico@` y `veterinario@sippt.local`, contraseña `sippt2026`.

---

## 7. Estructura del proyecto

```
.
├── docker-compose.yml          # 5 servicios: BD, broker, núcleo, ingesta, tablero
├── .env.example                # todas las variables de entorno
├── .github/workflows/          # integración continua (Fase 7)
├── backend-core/               # Laravel 11 · PHP 8.3 — núcleo hexagonal
│   ├── src/Domain/             #   entidades y política ReglaUmbral (sin framework)
│   ├── src/Application/        #   casos de uso y puertos
│   ├── src/Infrastructure/     #   adaptadores: HTTP y persistencia
│   └── tests/{Unit,Feature}/
├── ingesta-service/            # Python 3.12 · FastAPI · paho-mqtt
│   ├── app/                    #   suscriptor, validador de calidad, cliente del núcleo
│   └── tests/
├── dashboard/                  # Next.js 14 · React 18 · TypeScript
│   ├── app/
│   ├── components/
│   └── tests/
├── db/
│   ├── migrations/             # V1–V4
│   └── seeds/                  # 4 estanques + 30 días de lecturas
├── docs/
│   ├── arquitectura/           # diagramas de paquetes, despliegue y ER
│   ├── wireframes/             # tablero del PMV
│   └── openapi.yaml            # contratos REST (Fase 3)
├── infra/mosquitto/            # configuración del broker
└── scripts/                    # simulador de sensores y carga de datos
```

**Regla dura de arquitectura:** `backend-core/src/Domain/` no importa nada de
Laravel, Eloquent, el broker MQTT ni la base de datos. La política `ReglaUmbral`
debe ser testeable con PHPUnit puro.

---

## 8. Contribución

**Ramas** — `main` (protegida) ← `develop` ← `feature/*`

```
feature/hu{NN}-{descripcion-corta}     vida máxima: 3 días
fix/{issue}-{descripcion}
```

**Commits** — Conventional Commits con el identificador de la historia:

```
feat(hu04): evaluar umbral de oxígeno disuelto y generar alerta
```

Todo pull request debe enlazar la historia, superar el pipeline (linters y
pruebas) y contar con la aprobación de un integrante distinto del autor.

**Calidad exigida** — PHPStan nivel 6 y Laravel Pint en el backend, Ruff en la
ingesta, ESLint y TypeScript estricto en el tablero. Cobertura de la lógica
principal superior al 80 %.

---

## 9. Equipo y licencia

| Integrante | Responsabilidad en el incremento |
|---|---|
| Castillón Salazar, Ronald Roy | Requisitos, análisis y evaluación del incremento |
| Pacheco Gaspar, Jean Brandon | Diseño, implementación, integración y despliegue |
| Lindo Torres, Raúl Jesús | Pruebas, calidad del dato y monitoreo |

Docente: Guevara Jiménez, Jorge Alfredo.

Licencia de uso académico. Universidad Continental — 2026.
