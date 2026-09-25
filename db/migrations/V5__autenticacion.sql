-- ════════════════════════════════════════════════════════════════════════════
-- V5__autenticacion.sql — Tokens de acceso a la API (RF-09)
--
-- Tabla de infraestructura, no de dominio: el informe declara siete tablas y
-- esas siguen siendo las siete del negocio. Esta sostiene la emisión y
-- revocación de tokens Bearer, y se mantiene aquí —y no en las migraciones de
-- Laravel— para que db/migrations siga siendo la única fuente del esquema.
--
-- Se necesita porque el servicio de ingesta y el tablero se autentican contra
-- la misma API con credenciales distintas, y un token comprometido debe poder
-- revocarse sin cambiar la contraseña del usuario.
-- ════════════════════════════════════════════════════════════════════════════

CREATE TABLE personal_access_tokens (
  id             BIGSERIAL    PRIMARY KEY,
  tokenable_type VARCHAR(255) NOT NULL,
  tokenable_id   BIGINT       NOT NULL,
  name           VARCHAR(255) NOT NULL,
  token          VARCHAR(64)  NOT NULL UNIQUE,
  abilities      TEXT,
  last_used_at   TIMESTAMPTZ,
  expires_at     TIMESTAMPTZ,
  created_at     TIMESTAMPTZ,
  updated_at     TIMESTAMPTZ
);

CREATE INDEX idx_pat_tokenable
  ON personal_access_tokens (tokenable_type, tokenable_id);

CREATE INDEX idx_pat_expiracion
  ON personal_access_tokens (expires_at)
  WHERE expires_at IS NOT NULL;

COMMENT ON COLUMN personal_access_tokens.token IS
  'Hash SHA-256 del token. El valor en claro solo existe en el momento de la emision.';
