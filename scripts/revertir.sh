#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# revertir.sh — Revierte las migraciones aplicando U*.sql en orden inverso.
#
#   ./scripts/revertir.sh
#
# Deja la base vacía. Es la mitad que verifica el criterio de terminación del
# informe: «migraciones aplicadas y revertidas sin error sobre base limpia».
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SERVICIO="${SERVICIO_BD:-postgres-timescale}"
BD="${POSTGRES_DB:-sippt}"
USUARIO="${POSTGRES_USER:-sippt}"

echo "Revirtiendo migraciones sobre ${BD}…"
for archivo in $(ls -1 db/rollback/U*.sql | sort -r); do
  echo "  → $(basename "$archivo")"
  docker compose exec -T "$SERVICIO" \
    psql -v ON_ERROR_STOP=1 -q -U "$USUARIO" -d "$BD" < "$archivo"
done

echo "Listo."
