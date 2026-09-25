-- ════════════════════════════════════════════════════════════════════════════
-- V3__auditoria.sql — Auditoría de escrituras (RNF-04)
--
-- RNF-04: «Toda operación de escritura debe quedar auditada con usuario y
-- marca temporal.»
--
-- Se implementa con columnas de auditoría sobre las tablas mutables y un
-- disparador que mantiene actualizado_en, en lugar de una tabla de bitácora
-- aparte. La razón es doble: el informe fija el esquema del PMV en siete
-- tablas, y una tabla de auditoría genérica solo aporta valor cuando existe
-- historial de versiones, que es una necesidad de incrementos posteriores.
--
-- lectura queda deliberadamente fuera: no la escribe un usuario sino un nodo
-- sensor, su autoría está en sensor_id y su marca temporal en recibido_en.
-- ════════════════════════════════════════════════════════════════════════════

-- ── Columnas de auditoría ───────────────────────────────────────────────────

ALTER TABLE estanque
  ADD COLUMN creado_por     BIGINT      REFERENCES usuario(id),
  ADD COLUMN actualizado_por BIGINT     REFERENCES usuario(id),
  ADD COLUMN actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now();

ALTER TABLE umbral_parametro
  ADD COLUMN creado_por      BIGINT     REFERENCES usuario(id),
  ADD COLUMN actualizado_por BIGINT     REFERENCES usuario(id),
  ADD COLUMN actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now();

ALTER TABLE sensor
  ADD COLUMN creado_por      BIGINT     REFERENCES usuario(id),
  ADD COLUMN actualizado_por BIGINT     REFERENCES usuario(id),
  ADD COLUMN actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now();

-- alerta ya tiene actualizada_en desde V1; solo le falta la autoría del cierre.
ALTER TABLE alerta
  ADD COLUMN cerrada_por BIGINT REFERENCES usuario(id);

COMMENT ON COLUMN alerta.cerrada_por IS
  'Usuario que registro la atencion y cerro la alerta (HU-05, RNF-04).';

-- atencion_alerta nace auditada: usuario_id y registrada_en son obligatorios
-- desde V1, porque la trazabilidad de la intervencion es su razon de existir.

-- ── Disparador de marca temporal ────────────────────────────────────────────
-- Mantener actualizado_en en la base, y no en el adaptador de persistencia,
-- evita que una escritura que no pase por Eloquent deje el campo obsoleto.

CREATE OR REPLACE FUNCTION fn_tocar_actualizado_en()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
  NEW.actualizado_en := now();
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_estanque_actualizado
  BEFORE UPDATE ON estanque
  FOR EACH ROW EXECUTE FUNCTION fn_tocar_actualizado_en();

CREATE TRIGGER trg_umbral_actualizado
  BEFORE UPDATE ON umbral_parametro
  FOR EACH ROW EXECUTE FUNCTION fn_tocar_actualizado_en();

CREATE TRIGGER trg_sensor_actualizado
  BEFORE UPDATE ON sensor
  FOR EACH ROW EXECUTE FUNCTION fn_tocar_actualizado_en();

-- alerta usa actualizada_en (femenino) por coherencia con V1.
CREATE OR REPLACE FUNCTION fn_tocar_actualizada_en()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
  NEW.actualizada_en := now();
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_alerta_actualizada
  BEFORE UPDATE ON alerta
  FOR EACH ROW EXECUTE FUNCTION fn_tocar_actualizada_en();
