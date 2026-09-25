-- Reversión de V2__indices.sql
-- idx_lectura_reciente NO se elimina aquí: pertenece a V1, junto a la hypertable.

DROP INDEX IF EXISTS idx_usuario_email_activo;
DROP INDEX IF EXISTS idx_atencion_usuario;
DROP INDEX IF EXISTS idx_alerta_estanque;
DROP INDEX IF EXISTS idx_alerta_abiertas_recientes;
DROP INDEX IF EXISTS idx_alerta_listado;
DROP INDEX IF EXISTS idx_umbral_estanque;
DROP INDEX IF EXISTS idx_sensor_estanque;
DROP INDEX IF EXISTS idx_sensor_codigo_nodo;
DROP INDEX IF EXISTS idx_estanque_codigo;
DROP INDEX IF EXISTS idx_estanque_activo;
