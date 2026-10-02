#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# cargar_datos_prueba.sh — Deja el entorno listo para una demostración.
#
#   ./scripts/cargar_datos_prueba.sh              base limpia + datos semilla
#   ./scripts/cargar_datos_prueba.sh --cp09       añade 20 estanques para medir
#   ./scripts/cargar_datos_prueba.sh --escenario  siembra lecturas recientes
#
# Las lecturas semilla llegan hasta el momento de la carga, de modo que al
# abrir el tablero los estanques aparecen incomunicados hasta que algo publique.
# --escenario resuelve eso publicando una ronda actual por el broker.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SERVICIO="${SERVICIO_BD:-postgres-timescale}"
BD="${POSTGRES_DB:-sippt}"
USUARIO="${POSTGRES_USER:-sippt}"

psql_en() {
  docker compose exec -T "$SERVICIO" psql -v ON_ERROR_STOP=1 -q -U "$USUARIO" -d "$BD" "$@"
}

echo "Reconstruyendo el esquema y los datos semilla…"
bash scripts/revertir.sh >/dev/null
bash scripts/migrar.sh --seed

echo "Fijando la contrasena de los usuarios de prueba…"
HASH="$(docker compose exec -T api-core php -r \
  'echo password_hash("sippt2026", PASSWORD_BCRYPT, ["cost" => 12]);' | tr -d '\r')"
psql_en -c "UPDATE usuario SET password_hash = '${HASH}';"

if [[ " $* " == *" --cp09 "* ]]; then
  echo "Cargando el banco de medicion de CP-09 (20 estanques)…"
  psql_en < db/seeds/S3__carga_cp09.sql
fi

if [[ " $* " == *" --escenario "* ]]; then
  echo "Publicando una ronda de lecturas actuales…"
  docker compose exec -T ingesta-service python /srv/scripts/simular_sensores.py \
    --normal --host mosquitto --silencioso
  echo "Provocando una alerta critica en EST-03…"
  docker compose exec -T ingesta-service python /srv/scripts/simular_sensores.py \
    --critico --estanque EST-03 --host mosquitto --silencioso
fi

echo
psql_en -c "SELECT
  (SELECT count(*) FROM estanque) AS estanques,
  (SELECT count(*) FROM sensor)   AS sensores,
  (SELECT count(*) FROM lectura)  AS lecturas,
  (SELECT count(*) FROM alerta)   AS alertas;"

echo "Listo. Tablero en http://localhost:3000 — operador@sippt.local / sippt2026"
