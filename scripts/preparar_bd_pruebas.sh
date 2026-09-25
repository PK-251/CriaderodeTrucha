#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# preparar_bd_pruebas.sh — Crea (o recrea) la base sippt_test y le aplica el
# esquema completo mas los datos maestros.
#
# Las pruebas de feature corren contra una base propia y no contra la de
# desarrollo: un fallo a mitad de suite no puede dejar inservibles los datos
# con los que se esta trabajando.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SERVICIO="${SERVICIO_BD:-postgres-timescale}"
USUARIO="${POSTGRES_USER:-sippt}"
BD_PRUEBAS="${BD_PRUEBAS:-sippt_test}"

psql_en() {
  docker compose exec -T "$SERVICIO" psql -v ON_ERROR_STOP=1 -q -U "$USUARIO" "$@"
}

echo "Recreando ${BD_PRUEBAS}…"
psql_en -d postgres -c "DROP DATABASE IF EXISTS ${BD_PRUEBAS};"
psql_en -d postgres -c "CREATE DATABASE ${BD_PRUEBAS};"

for archivo in db/migrations/V*.sql; do
  echo "  → $(basename "$archivo")"
  psql_en -d "$BD_PRUEBAS" < "$archivo"
done

echo "  → S1__maestras.sql"
psql_en -d "$BD_PRUEBAS" < db/seeds/S1__maestras.sql

echo "Listo."
