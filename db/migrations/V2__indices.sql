-- ════════════════════════════════════════════════════════════════════════════
-- V2__indices.sql — Índices de soporte a las consultas del PMV
--
-- El índice idx_lectura_reciente ya se crea en V1, junto a la hypertable, por
-- ser inseparable de ella. Aquí se añaden los índices que sostienen las
-- consultas concretas del tablero y del panel de alertas.
-- ════════════════════════════════════════════════════════════════════════════

-- GET /api/v1/estanques — el tablero recorre solo los estanques activos
-- (HU-03). Índice parcial: los estanques dados de baja no ocupan el índice.
CREATE INDEX idx_estanque_activo
  ON estanque (id)
  WHERE activo = TRUE;

-- Resolución del estanque por su código, usada por el servicio de ingesta al
-- traducir el tópico piscigranja/{codigo_estanque}/{parametro} (HU-02).
CREATE INDEX idx_estanque_codigo ON estanque (codigo);

-- El validador de calidad busca el sensor por su código de nodo en cada
-- lectura recibida; es la consulta más frecuente del sistema (288 por sensor
-- y día).
CREATE INDEX idx_sensor_codigo_nodo ON sensor (codigo_nodo) WHERE activo = TRUE;

-- Traducción (estanque, parámetro) → sensor, necesaria para evaluar umbrales.
CREATE INDEX idx_sensor_estanque ON sensor (estanque_id, parametro);

-- Carga de umbrales de un estanque al evaluar una lectura (HU-04).
CREATE INDEX idx_umbral_estanque ON umbral_parametro (estanque_id);

-- GET /api/v1/alertas — listado ordenado por fecha de generación descendente,
-- con filtros por estado y severidad (HU-04).
CREATE INDEX idx_alerta_listado
  ON alerta (estado, severidad, generada_en DESC);

-- Panel de alertas abiertas del tablero: es la consulta que se repite en cada
-- refresco y en cada evento WebSocket.
CREATE INDEX idx_alerta_abiertas_recientes
  ON alerta (generada_en DESC)
  WHERE estado = 'abierta';

-- Alertas de un estanque concreto, para el detalle de la tarjeta.
CREATE INDEX idx_alerta_estanque ON alerta (estanque_id, generada_en DESC);

-- Bitácora por usuario, para la trazabilidad de intervenciones (HU-05).
CREATE INDEX idx_atencion_usuario ON atencion_alerta (usuario_id, registrada_en DESC);

-- Solo se autentica a usuarios activos (RF-09).
CREATE INDEX idx_usuario_email_activo ON usuario (email) WHERE activo = TRUE;
