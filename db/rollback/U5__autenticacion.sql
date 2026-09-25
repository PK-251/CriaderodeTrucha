-- Reversión de V5__autenticacion.sql

DROP INDEX IF EXISTS idx_pat_expiracion;
DROP INDEX IF EXISTS idx_pat_tokenable;
DROP TABLE IF EXISTS personal_access_tokens;
