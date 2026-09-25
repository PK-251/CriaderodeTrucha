-- Reversión de V1__esquema_pmv.sql
-- Orden inverso al de creación, respetando las dependencias de clave foránea.
-- La extensión timescaledb no se elimina: puede estar en uso por otras bases
-- de la misma instancia.

DROP TABLE IF EXISTS atencion_alerta;
DROP TABLE IF EXISTS alerta;
DROP TABLE IF EXISTS lectura;          -- hypertable: DROP TABLE la retira completa
DROP TABLE IF EXISTS sensor;
DROP TABLE IF EXISTS umbral_parametro;
DROP TABLE IF EXISTS estanque;
DROP TABLE IF EXISTS usuario;

DROP TYPE IF EXISTS estado_alerta;
DROP TYPE IF EXISTS severidad_t;
DROP TYPE IF EXISTS parametro_t;
