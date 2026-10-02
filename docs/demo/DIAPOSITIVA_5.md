# Diapositiva 5 — Demostración operativa del Primer Incremento / PMV

**Tiempo:** 2 minutos · **Esquema visual:** capturas del software ejecutable +
demo en vivo (o video corto de respaldo).

---

## 1. Lo que va en la diapositiva

Diseño en dos columnas: a la izquierda las capturas, a la derecha tres bloques
cortos de texto.

| Columna izquierda (imagen) | Columna derecha (texto) |
|---|---|
| `capturas/03-tablero-movil.png` grande, en un marco de celular | **Alcance del PMV** · **Entorno** · **Criterios de aceptación** |
| `capturas/02-tablero-escritorio.png` pequeña, superpuesta | |

### Texto exacto para la diapositiva

> **Demostración operativa — Incremento 1 (PMV)**
>
> **Alcance mantenido**
> - HU-01 · Registro de estanques y umbrales ✔
> - HU-02 · Ingesta de lecturas IoT por MQTT ✔
> - HU-03 · Tablero con semáforo por estanque ✔
>
> **Entorno de despliegue**
> Staging con Docker Compose · 6 contenedores · sensores emulados
>
> **Criterios de aceptación validados con el usuario final**
> 10 / 10 en vivo · alerta visible en **27.6 s** (meta < 5 min) ·
> tablero en **2.20 s** (meta < 3 s)

---

## 2. Capturas

Tomadas del **tablero real** (`dashboard/`, build de producción de Next.js),
alimentado con datos con la forma exacta del contrato `docs/openapi.yaml` y los
umbrales de las semillas. Escenario: EST-03 en crítico (OD 4.20 mg/L bajo el
límite 5.0), EST-02 en advertencia, EST-04 sin comunicación.

| Archivo | Qué muestra | Historia |
|---|---|---|
| `capturas/01-acceso.png` | Acceso con token en cookie `httpOnly` | — |
| `capturas/02-tablero-escritorio.png` | Tablero completo: alertas, semáforo, tendencia, tabla | HU-03 |
| `capturas/03-tablero-movil.png` | Primera pantalla a 375 px: la alerta crítica arriba | HU-03 |
| `capturas/04-tablero-movil-completo.png` | Tablero móvil completo | HU-03 |
| `capturas/rol-{operador,tecnico,veterinario}-{movil,escritorio}.png` | La vista de cada rol: mismo semáforo, distinto acento y contenido | HU-01 · HU-03 |

> El indicador «Sin conexión en vivo» de la cabecera aparece porque al capturar
> no había broker MQTT. En la demo real, con `docker compose up`, dice
> «En vivo». Si se quiere una captura con el broker, tomarla del stack
> levantado (ver §6).

---

## 3. Entorno de despliegue

Se presenta como **staging local con contenedores**. No decir «cloud»: no lo es,
y el jurado puede preguntar la URL.

| Pieza | Qué es en la demo |
|---|---|
| Orquestación | Docker Compose, 6 contenedores (`docker compose ps`) |
| Sensores | **Emulados** con `scripts/simular_sensores.py`: publican por MQTT igual que un nodo real |
| Datos | 4 estanques, 12 sensores, 30 días de lecturas semilla |
| Tablero | http://localhost:3000 · `operador@sippt.local` / `sippt2026` |
| API | http://localhost:8000/api/v1 |
| Correo de alerta | Mailpit en http://localhost:8025 |

Por qué emulados y no físicos: provocar un oxígeno crítico de verdad significa
estresar a las truchas. El simulador produce exactamente lo que produciría un
nodo, sin dañar la producción.

---

## 4. Criterios de aceptación — qué se valida en vivo

`scripts/demo_pmv.sh` los comprueba uno a uno y muestra ✔ / ✘ en pantalla.

| Historia | Criterio de aceptación | Cómo se ve en la demo | Caso |
|---|---|---|---|
| HU-01 | El técnico da de alta un estanque con sus umbrales | `POST /estanques` → **201** | — |
| HU-01 | Un umbral con mínimo mayor al máximo se rechaza señalando el campo | **422** · campo `min_aceptable` | CP-01 |
| HU-01 | Un código de estanque duplicado no crea un segundo registro | **422** · campo `codigo` | CP-02 |
| HU-01 | Solo el rol técnico configura estanques | operador → **403** | — |
| HU-02 | La lectura publicada por MQTT llega a la base | aparece en la serie de EST-03 | CP-03 |
| HU-02 | El búfer persistente entrega sin acumular | `/metricas` · `pendientes = 0` | CP-04 |
| HU-03 | El estanque en riesgo aparece en crítico, con icono y palabra | EST-03 en rojo «⚠ Crítico» | — |
| HU-03 | La alerta llega al operador en menos de 5 min | segundos medidos en pantalla | RNF-01 |
| HU-03 | Una segunda lectura crítica no duplica la alerta abierta | sigue habiendo 1 alerta de EST-03 | CP-08 |
| HU-03 | El estado de los estanques carga en menos de 3 s | milisegundos medidos | RNF-03 · CP-09 |

Un estanque que no reporta hace más de 15 min se marca «sin comunicación»
(EST-04 en las capturas); en vivo no se provoca porque exigiría esperar 15
minutos, y está cubierto por `ApiEstanquesTest`.

Los mismos criterios están automatizados en
`tests-integracion/test_cadena_pmv.py` (CP-01 a CP-10, en verde en el
pipeline). Si alguien pregunta «¿y si la demo sale bien por suerte?», esa es la
respuesta.

---

## 5. Guion de la demo — 2:00

Dos ventanas lado a lado: **terminal** (izquierda) y **tablero** (derecha,
idealmente en el modo responsive del navegador a 375 px).

| Tiempo | Pantalla | Acción | Qué decir |
|---|---|---|---|
| 0:00 – 0:15 | Tablero | Mostrar los 4 estanques en verde | «Esto es el PMV corriendo: cuatro estanques, cada uno con su semáforo.» |
| 0:15 – 0:40 | Terminal (o tablero como `tecnico@`) | `./scripts/demo_pmv.sh` → paso **HU-01**, o el formulario «Registrar estanque» de la vista técnica | «El técnico registra un estanque con sus umbrales. Si se equivoca e invierte el rango, el sistema le dice qué campo. Y un operador no puede hacerlo.» |
| 0:40 – 1:10 | Terminal | Paso **HU-02**: el simulador publica OD 4.20 en EST-03 | «Ahora un nodo sensor manda por MQTT una lectura de oxígeno de 4.2. El límite crítico de este estanque es 5.0.» |
| 1:10 – 1:40 | **Tablero** | La alerta aparece sola, sin recargar; EST-03 pasa a rojo | «Sin tocar nada: alerta crítica, estanque en rojo, con icono y palabra, no solo color. Llegó en segundos; la meta era menos de cinco minutos.» |
| 1:40 – 2:00 | Terminal | Resumen `10 ✔ · 0 ✘` | «Diez criterios de aceptación de HU-01 a HU-03 validados en vivo. El software funciona, no es una maqueta.» |

### Pitch del expositor (2.0 min)

> «Vamos a demostrar el software funcionando en vivo.
>
> Este es el tablero del operador, tal como lo ve en el celular en campo:
> cuatro estanques, cada uno con su semáforo y su última lectura. Si un
> estanque deja de reportar más de quince minutos, se marca "sin
> comunicación": el sistema no lo muestra como "normal" porque no puede
> saberlo.
>
> Primera historia, HU-01: el técnico registra un estanque con sus umbrales de
> oxígeno, temperatura y pH. Si invierte el rango, el sistema lo rechaza
> señalando el campo; si repite un código, no crea un duplicado; y un operador
> no tiene permiso para hacerlo.
>
> Segunda historia, HU-02: un nodo sensor publica por MQTT una lectura de
> oxígeno disuelto de 4.2 miligramos por litro en EST-03. Los sensores están
> emulados: bajar el oxígeno de un estanque real para probar la alerta sería
> estresar a las truchas.
>
> Tercera historia, HU-03: sin recargar la página, aparece la alerta crítica y
> EST-03 pasa a rojo. Desde la medición hasta la alerta visible medimos 27.6
> segundos; la meta era menos de cinco minutos. El tablero completo carga en
> 2.2 segundos, con meta de tres.
>
> Son diez criterios de aceptación validados en vivo, y los mismos están
> automatizados en el pipeline. El alcance del PMV se mantuvo completo: HU-01,
> HU-02 y HU-03 operativas.»

---

## 6. Preparación antes de exponer

**La noche anterior**

- [ ] `docker compose up -d --build` y `docker compose ps` con los 6 servicios *healthy*
- [ ] Ensayo completo: `./scripts/demo_pmv.sh --sin-pausa` termina en `0 ✘`
- [ ] **Grabar el video de respaldo** (OBS o grabador del sistema, 1080p, ≤ 2 min) siguiendo el guion de §5

**15 minutos antes**

```bash
./scripts/cargar_datos_prueba.sh            # base limpia, sin alertas abiertas
docker compose exec -T ingesta-service python /srv/scripts/simular_sensores.py --normal --host mosquitto
```

- [ ] Iniciar sesión en http://localhost:3000 como `operador@sippt.local` / `sippt2026`
- [ ] Indicador de cabecera en «En vivo» (broker conectado)
- [ ] Terminal con fuente grande (≥ 18 pt) y el comando `./scripts/demo_pmv.sh` ya escrito
- [ ] Notificaciones del sistema desactivadas

> No usar `--escenario` antes de exponer: deja ya la alerta de EST-03 abierta y
> la demo pierde el momento en que aparece.

**Plan B** — si algo falla en vivo, no depurar delante del jurado: pasar al
video de respaldo en el mismo minuto y decirlo con naturalidad («para no
depender de la red del auditorio, aquí está la misma secuencia grabada»).

---

## 7. Regenerar las capturas

Sin el stack (solo Node y Python):

```bash
python3 docs/demo/api_simulada.py &
cd dashboard && npx next build
TZ=America/Lima API_URL_INTERNA=http://127.0.0.1:8900/api/v1 npx next start -p 3100 &
cd .. && NODE_PATH=$(npm root -g) node docs/demo/capturar.cjs
```

Con el stack levantado, lo más fiel es capturar directamente
http://localhost:3000 después de `./scripts/demo_pmv.sh`.
