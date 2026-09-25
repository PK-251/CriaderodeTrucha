-- ════════════════════════════════════════════════════════════════════════════
-- V4__retencion.sql — Políticas de compresión y retención de la serie temporal
--
-- Volumen esperado: 288 lecturas diarias por sensor (una cada 5 minutos).
-- Con 12 sensores son ~3 456 filas al día, ~1.26 millones al año.
--
-- RNF-06 exige conservar el histórico al menos 24 meses para entrenar el
-- modelo predictivo de incrementos posteriores. Ese requisito está asignado al
-- Incremento 2, pero la política se declara aquí porque debe existir ANTES de
-- que se acumulen los datos: aplicarla después obligaría a comprimir de golpe
-- un histórico entero.
-- ════════════════════════════════════════════════════════════════════════════

-- ── Tamaño del fragmento ────────────────────────────────────────────────────
-- Por defecto TimescaleDB crea fragmentos de 7 días. Con este volumen, uno de
-- 30 días mantiene el número de fragmentos bajo sin perjudicar las consultas
-- del tablero, que siempre miran la ventana reciente.
SELECT set_chunk_time_interval('lectura', INTERVAL '30 days');

-- ── Compresión ──────────────────────────────────────────────────────────────
-- Se segmenta por sensor porque toda consulta del PMV filtra por sensor_id, y
-- se ordena por medido_en descendente porque la lectura más reciente es la que
-- pide el tablero en cada refresco (HU-03).
ALTER TABLE lectura SET (
  timescaledb.compress,
  timescaledb.compress_segmentby = 'sensor_id',
  timescaledb.compress_orderby   = 'medido_en DESC'
);

-- Los datos de más de 90 días ya no se consultan en la operación diaria: solo
-- sirven para entrenamiento y análisis histórico.
SELECT add_compression_policy('lectura', INTERVAL '90 days');

-- ── Retención ───────────────────────────────────────────────────────────────
-- 24 meses es el mínimo de RNF-06. Se fija en 36 para cubrir dos ciclos
-- productivos completos más un margen, de modo que un reentrenamiento pueda
-- comparar el mismo mes de tres años distintos.
SELECT add_retention_policy('lectura', INTERVAL '36 months');
