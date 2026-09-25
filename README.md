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
| API / Swagger UI | http://localhost:8000 | 8000 |
| Broker MQTT | `mqtt://localhost:1883` | 1883 |
| PostgreSQL | `localhost:5432` | 5432 |

Comprobar el estado de los contenedores:

```bash
docker compose ps
```

---

## 4. Base de datos

🔜 *Fase 1.* Migraciones numeradas `V1__esquema_pmv.sql` … `V4__retencion.sql`
en `db/migrations/`, y datos semilla en `db/seeds/` con 4 estanques, sus
umbrales, sus sensores y 30 días de lecturas simuladas.

---

## 5. Simulación de sensores

🔜 *Fase 6.* `scripts/simular_sensores.py` publicará lecturas en el broker para
provocar escenarios normales, de advertencia, críticos y de corte de enlace sin
necesidad de hardware físico.

---

## 6. Ejecución de pruebas

🔜 *Fases 2 a 6.* Suites por módulo: PHPUnit para el núcleo hexagonal, pytest
para el servicio de ingesta y las pruebas del tablero, más la suite de
integración que recorre la cadena completa y verifica los casos CP-01 a CP-10.

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
