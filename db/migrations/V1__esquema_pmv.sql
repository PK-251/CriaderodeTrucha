-- ════════════════════════════════════════════════════════════════════════════
-- V1__esquema_pmv.sql — Esquema del Primer Incremento (PMV)
-- SIPPT · PostgreSQL 16 + TimescaleDB
--
-- Cubre HU-01 (estanques y umbrales), HU-02 (lecturas), HU-04 (alertas) y
-- HU-05 (atención de alertas).
-- ════════════════════════════════════════════════════════════════════════════

CREATE EXTENSION IF NOT EXISTS timescaledb;

-- ── Tipos enumerados ────────────────────────────────────────────────────────

-- nh4_mgl se declara en el tipo pero NO se usa en el PMV: el amonio pertenece
-- al Incremento 2. Añadir un valor a un ENUM más adelante obliga a un ALTER
-- TYPE que no puede ejecutarse dentro de una transacción, de modo que dejarlo
-- previsto aquí evita una migración incómoda sin habilitar la funcionalidad.
CREATE TYPE parametro_t   AS ENUM ('od_mgl', 'temp_c', 'ph', 'nh4_mgl');
CREATE TYPE severidad_t   AS ENUM ('advertencia', 'critica');
CREATE TYPE estado_alerta AS ENUM ('abierta', 'atendida');

-- ── 1. usuario ──────────────────────────────────────────────────────────────
-- Soporta RF-09 (autenticar y restringir operaciones por rol) y es el sujeto
-- de la auditoría exigida por RNF-04.

CREATE TABLE usuario (
  id            BIGSERIAL    PRIMARY KEY,
  nombre        VARCHAR(120) NOT NULL,
  email         VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  rol           VARCHAR(20)  NOT NULL,
  activo        BOOLEAN      NOT NULL DEFAULT TRUE,
  creado_en     TIMESTAMPTZ  NOT NULL DEFAULT now(),
  CONSTRAINT rol_valido CHECK (rol IN ('operador', 'tecnico', 'veterinario'))
);

COMMENT ON TABLE  usuario IS 'Usuarios del sistema con su rol operativo (RF-09).';
COMMENT ON COLUMN usuario.rol IS 'operador | tecnico | veterinario. El PMV no incluye rol gerente.';

-- ── 2. estanque ─────────────────────────────────────────────────────────────
-- HU-01 / RF-01.

CREATE TABLE estanque (
  id          BIGSERIAL     PRIMARY KEY,
  codigo      VARCHAR(20)   NOT NULL UNIQUE,
  volumen_m3  NUMERIC(8,2)  NOT NULL CHECK (volumen_m3 > 0),
  biomasa_kg  NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (biomasa_kg >= 0),
  etapa       VARCHAR(20)   NOT NULL,
  activo      BOOLEAN       NOT NULL DEFAULT TRUE,
  creado_en   TIMESTAMPTZ   NOT NULL DEFAULT now(),
  CONSTRAINT etapa_valida CHECK (etapa IN ('alevino', 'juvenil', 'engorde', 'cosecha'))
);

COMMENT ON TABLE estanque IS 'Catálogo de estanques de la piscigranja (RF-01).';

-- ── 3. umbral_parametro ─────────────────────────────────────────────────────
-- HU-01 / RF-02. Un umbral por parámetro y por estanque: los rangos varían
-- según la etapa productiva, por eso no son constantes del sistema.

CREATE TABLE umbral_parametro (
  id                BIGSERIAL    PRIMARY KEY,
  estanque_id       BIGINT       NOT NULL REFERENCES estanque(id) ON DELETE CASCADE,
  parametro         parametro_t  NOT NULL,
  min_aceptable     NUMERIC(6,2) NOT NULL,
  max_aceptable     NUMERIC(6,2) NOT NULL,
  severidad_critica NUMERIC(6,2) NOT NULL,
  creado_en         TIMESTAMPTZ  NOT NULL DEFAULT now(),
  CONSTRAINT rango_valido CHECK (min_aceptable < max_aceptable),
  CONSTRAINT umbral_unico UNIQUE (estanque_id, parametro)
);

-- Semántica de severidad_critica: margen absoluto MÁS ALLÁ del rango aceptable
-- a partir del cual la alerta escala de advertencia a crítica (HU-04).
--
--   critica  ⟺  valor <= (min_aceptable - severidad_critica)
--            ∨  valor >= (max_aceptable + severidad_critica)
--
-- Se modela como margen y no como valor absoluto porque el pH y la temperatura
-- son peligrosos en ambas direcciones, y una sola columna absoluta solo podría
-- expresar una de ellas. El caso CP-06 del informe se reproduce exactamente:
-- con min_aceptable 5.5 y severidad_critica 0.5, el límite crítico de oxígeno
-- disuelto es 5.0, de modo que 5.1 es advertencia y 4.2 es crítica (CP-07).
COMMENT ON COLUMN umbral_parametro.severidad_critica IS
  'Margen absoluto mas alla del rango aceptable a partir del cual la alerta es critica (HU-04).';

-- ── 4. sensor ───────────────────────────────────────────────────────────────
-- Un nodo físico mide un parámetro en un estanque. El rango físico sostiene
-- la regla de calidad del dato de CP-05 (pH 14.8 se descarta antes de evaluar
-- umbrales, porque un valor imposible no es una condición de riesgo sino un
-- sensor averiado).

CREATE TABLE sensor (
  id                BIGSERIAL    PRIMARY KEY,
  codigo_nodo       VARCHAR(20)  NOT NULL UNIQUE,
  estanque_id       BIGINT       NOT NULL REFERENCES estanque(id) ON DELETE CASCADE,
  parametro         parametro_t  NOT NULL,
  modelo            VARCHAR(60),
  rango_fisico_min  NUMERIC(8,3) NOT NULL,
  rango_fisico_max  NUMERIC(8,3) NOT NULL,
  calibrado_en      TIMESTAMPTZ,
  activo            BOOLEAN      NOT NULL DEFAULT TRUE,
  creado_en         TIMESTAMPTZ  NOT NULL DEFAULT now(),
  CONSTRAINT rango_fisico_valido CHECK (rango_fisico_min < rango_fisico_max),
  CONSTRAINT sensor_unico_por_parametro UNIQUE (estanque_id, parametro)
);

COMMENT ON COLUMN sensor.calibrado_en IS
  'Ultima calibracion de la sonda. Mitigacion del riesgo de deriva (informe 8.1).';

-- ── 5. lectura ──────────────────────────────────────────────────────────────
-- HU-02 / RF-03, RF-04. Serie temporal: 288 lecturas diarias por sensor.
--
-- La clave primaria (sensor_id, medido_en) es lo que garantiza la ausencia de
-- duplicados cuando el gateway reenvía su búfer tras una caída de enlace
-- (RNF-02, caso CP-04). La marca temporal la pone el nodo, no el servidor.

CREATE TABLE lectura (
  sensor_id   BIGINT       NOT NULL REFERENCES sensor(id) ON DELETE CASCADE,
  medido_en   TIMESTAMPTZ  NOT NULL,
  valor       NUMERIC(8,3) NOT NULL,
  calidad     VARCHAR(12)  NOT NULL DEFAULT 'valida',
  recibido_en TIMESTAMPTZ  NOT NULL DEFAULT now(),
  PRIMARY KEY (sensor_id, medido_en),
  CONSTRAINT calidad_valida CHECK (calidad IN ('valida', 'dudosa', 'descartada'))
);

SELECT create_hypertable('lectura', 'medido_en');

CREATE INDEX idx_lectura_reciente ON lectura (sensor_id, medido_en DESC);

COMMENT ON COLUMN lectura.medido_en IS
  'Instante de la medicion segun el nodo. Nunca lo asigna el servidor (RNF-02).';
COMMENT ON COLUMN lectura.recibido_en IS
  'Instante en que el nucleo persistio la lectura. La diferencia con medido_en revela el tiempo que estuvo retenida en el bufer del gateway.';

-- ── 6. alerta ───────────────────────────────────────────────────────────────
-- HU-04 / RF-06, RF-07.

CREATE TABLE alerta (
  id              BIGSERIAL     PRIMARY KEY,
  estanque_id     BIGINT        NOT NULL REFERENCES estanque(id) ON DELETE CASCADE,
  parametro       parametro_t   NOT NULL,
  severidad       severidad_t   NOT NULL,
  estado          estado_alerta NOT NULL DEFAULT 'abierta',
  valor_detectado NUMERIC(8,3)  NOT NULL,
  umbral_violado  NUMERIC(6,2)  NOT NULL,
  ultimo_valor    NUMERIC(8,3)  NOT NULL,
  generada_en     TIMESTAMPTZ   NOT NULL DEFAULT now(),
  actualizada_en  TIMESTAMPTZ   NOT NULL DEFAULT now()
);

-- CP-08: mientras una alerta siga abierta para el mismo estanque y parámetro,
-- las lecturas posteriores fuera de rango actualizan ultimo_valor en lugar de
-- crear una segunda alerta. El índice parcial hace que la base de datos —y no
-- solo el código— impida el duplicado.
CREATE UNIQUE INDEX idx_alerta_abierta_unica
  ON alerta (estanque_id, parametro)
  WHERE estado = 'abierta';

COMMENT ON COLUMN alerta.valor_detectado IS 'Valor que origino la alerta.';
COMMENT ON COLUMN alerta.ultimo_valor    IS 'Valor mas reciente fuera de rango (CP-08).';

-- ── 7. atencion_alerta ──────────────────────────────────────────────────────
-- HU-05 / RF-08. La unicidad de alerta_id es lo que produce el 409 de CP-10
-- cuando se intenta registrar una atención sobre una alerta ya atendida.

CREATE TABLE atencion_alerta (
  id            BIGSERIAL    PRIMARY KEY,
  alerta_id     BIGINT       NOT NULL UNIQUE REFERENCES alerta(id) ON DELETE CASCADE,
  usuario_id    BIGINT       NOT NULL REFERENCES usuario(id),
  accion        VARCHAR(80)  NOT NULL,
  observacion   TEXT,
  registrada_en TIMESTAMPTZ  NOT NULL DEFAULT now(),
  CONSTRAINT accion_no_vacia CHECK (length(trim(accion)) > 0)
);

COMMENT ON TABLE atencion_alerta IS
  'Bitacora de intervencion (HU-05). Es la fuente de etiquetado del dataset que alimentara el modelo predictivo en incrementos posteriores.';
