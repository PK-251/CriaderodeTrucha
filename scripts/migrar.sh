#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# migrar.sh — Aplica las migraciones V1..Vn en orden, y opcionalmente los seeds.
#
#   ./scripts/migrar.sh              aplica solo las migraciones
#   ./scripts/migrar.sh --seed       aplica migraciones y datos semilla
#
# Cada archivo se ejecuta con ON_ERROR_STOP, de modo que un fallo aborta la
# migración completa en lugar de dejar el esquema a medias.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SERVICIO="${SERVICIO_BD:-postgres-timescale}"
BD="${POSTGRES_DB:-sippt}"
USUARIO="${POSTGRES_USER:-sippt}"

ejecutar() {
  local archivo="$1"
  echo "  → $(basename "$archivo")"
  docker compose exec -T "$SERVICIO" \
    psql -v ON_ERROR_STOP=1 -q -U "$USUARIO" -d "$BD" < "$archivo"
}

echo "Aplicando migraciones sobre ${BD}…"
for archivo in db/migrations/V*.sql; do
  ejecutar "$archivo"
done

if [[ "${1:-}" == "--seed" ]]; then
  echo "Cargando datos semilla…"
  for archivo in db/seeds/S*.sql; do
    ejecutar "$archivo"
  done
fi

echo "Listo."
