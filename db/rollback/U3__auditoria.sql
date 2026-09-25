-- Reversión de V3__auditoria.sql

DROP TRIGGER IF EXISTS trg_alerta_actualizada ON alerta;
DROP TRIGGER IF EXISTS trg_sensor_actualizado ON sensor;
DROP TRIGGER IF EXISTS trg_umbral_actualizado ON umbral_parametro;
DROP TRIGGER IF EXISTS trg_estanque_actualizado ON estanque;

DROP FUNCTION IF EXISTS fn_tocar_actualizada_en();
DROP FUNCTION IF EXISTS fn_tocar_actualizado_en();

ALTER TABLE alerta
  DROP COLUMN IF EXISTS cerrada_por;

ALTER TABLE sensor
  DROP COLUMN IF EXISTS actualizado_en,
  DROP COLUMN IF EXISTS actualizado_por,
  DROP COLUMN IF EXISTS creado_por;

ALTER TABLE umbral_parametro
  DROP COLUMN IF EXISTS actualizado_en,
  DROP COLUMN IF EXISTS actualizado_por,
  DROP COLUMN IF EXISTS creado_por;

ALTER TABLE estanque
  DROP COLUMN IF EXISTS actualizado_en,
  DROP COLUMN IF EXISTS actualizado_por,
  DROP COLUMN IF EXISTS creado_por;
