#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# demo_pmv.sh — Demostración operativa del PMV en vivo (Diapositiva 5).
#
#   ./scripts/demo_pmv.sh             demo guiada, pausa con Enter entre pasos
#   ./scripts/demo_pmv.sh --sin-pausa recorre los pasos de corrido (ensayo)
#
# Recorre HU-01, HU-02 y HU-03 contra el stack real levantado con
# `docker compose up -d`, y valida en cada paso el criterio de aceptación que
# corresponde. Pensado para dos minutos con el tablero abierto al lado en
# http://localhost:3000 (operador@sippt.local / sippt2026).
#
# Requisito previo: ./scripts/cargar_datos_prueba.sh --escenario
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

API="${API_URL:-http://localhost:8000/api/v1}"
INGESTA="${INGESTA_URL:-http://localhost:8001}"
PAUSA=1
[[ " $* " == *" --sin-pausa "* ]] && PAUSA=0

VERDE=$'\e[32m'; ROJO=$'\e[31m'; NEGRITA=$'\e[1m'; NORMAL=$'\e[0m'
APROBADOS=0; FALLIDOS=0

paso() {
  echo
  echo "${NEGRITA}━━ $1${NORMAL}"
  if [[ $PAUSA -eq 1 ]]; then read -r -p "   (Enter para continuar) " _; fi
}

criterio() {
  if [[ "$2" == "ok" ]]; then
    echo "   ${VERDE}✔ $1${NORMAL}"; APROBADOS=$((APROBADOS + 1))
  else
    echo "   ${ROJO}✘ $1${NORMAL}"; FALLIDOS=$((FALLIDOS + 1))
  fi
}

json() { python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"; }

token_de() {
  curl -sf -X POST "$API/auth/login" -H 'Content-Type: application/json' \
    -d "{\"email\":\"$1\",\"password\":\"sippt2026\"}" | json 'd["datos"]["token"]'
}

TECNICO="$(token_de tecnico@sippt.local)"
OPERADOR="$(token_de operador@sippt.local)"
CODIGO="EST-DEMO-$(date +%H%M%S)"

# ═════════════════════════════════════════════════════════════════════════════
paso "HU-01 · El técnico registra un estanque con sus umbrales"

estado="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/estanques" \
  -H "Authorization: Bearer $TECNICO" -H 'Content-Type: application/json' \
  -d "{\"codigo\":\"$CODIGO\",\"volumen_m3\":120,\"biomasa_kg\":300,\"etapa\":\"engorde\",
       \"umbrales\":[{\"parametro\":\"od_mgl\",\"min_aceptable\":6.0,\"max_aceptable\":12.0,\"severidad_critica\":0.5}]}")"
echo "   POST /estanques $CODIGO → $estado"
[[ "$estado" == "201" ]] && criterio "Alta con umbrales aceptada (201)" ok || criterio "Alta con umbrales (esperado 201)" no

campo="$(curl -s -X POST "$API/estanques" \
  -H "Authorization: Bearer $TECNICO" -H 'Content-Type: application/json' \
  -d '{"codigo":"EST-CP01","volumen_m3":100,"biomasa_kg":50,"etapa":"juvenil",
       "umbrales":[{"parametro":"od_mgl","min_aceptable":9.0,"max_aceptable":2.0,"severidad_critica":0.5}]}' \
  | json 'd["detalle"][0]["campo"]' 2>/dev/null || true)"
[[ "$campo" == "min_aceptable" ]] && criterio "CP-01 · Umbral invertido rechazado señalando el campo" ok \
  || criterio "CP-01 · Umbral invertido (esperado campo min_aceptable)" no

estado="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/estanques" \
  -H "Authorization: Bearer $TECNICO" -H 'Content-Type: application/json' \
  -d '{"codigo":"EST-03","volumen_m3":90,"biomasa_kg":10,"etapa":"juvenil","umbrales":[]}')"
[[ "$estado" == "422" ]] && criterio "CP-02 · Código duplicado rechazado (422)" ok || criterio "CP-02 · Código duplicado (esperado 422)" no

estado="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/estanques" \
  -H "Authorization: Bearer $OPERADOR" -H 'Content-Type: application/json' \
  -d '{"codigo":"EST-ROL","volumen_m3":90,"biomasa_kg":10,"etapa":"juvenil","umbrales":[]}')"
[[ "$estado" == "403" ]] && criterio "Solo el rol técnico configura estanques (operador → 403)" ok \
  || criterio "Control de rol (esperado 403)" no

# ═════════════════════════════════════════════════════════════════════════════
paso "HU-02 · Un nodo IoT publica por MQTT una lectura crítica de OD en EST-03"

inicio=$(date +%s)
docker compose exec -T ingesta-service python /srv/scripts/simular_sensores.py \
  --critico --estanque EST-03 --host mosquitto --silencioso
echo "   Publicado: piscigranja/EST-03/od_mgl  valor 4.20 mg/L (crítico < 5.0)"

ultimo=""
for _ in $(seq 1 90); do
  ultimo="$(curl -sf -H "Authorization: Bearer $OPERADOR" \
    "$API/estanques/3/lecturas?parametro=od_mgl&por_pagina=1" | json 'd["datos"][0]["valor"]' 2>/dev/null || true)"
  [[ "$ultimo" == "4.2" || "$ultimo" == "4.20" ]] && break
  sleep 1
done
[[ "$ultimo" == "4.2" || "$ultimo" == "4.20" ]] \
  && criterio "CP-03 · La lectura MQTT llegó a la base ($(( $(date +%s) - inicio )) s)" ok \
  || criterio "CP-03 · La lectura no llegó a la base en 90 s" no

pendientes="$(curl -sf "$INGESTA/metricas" | json 'd["entrega"]["pendientes"]' 2>/dev/null || echo '?')"
echo "   Ingesta /metricas → búfer persistente con $pendientes pendientes"
[[ "$pendientes" == "0" ]] && criterio "CP-04 · Búfer vaciado sin acumular lecturas" ok \
  || criterio "CP-04 · Búfer con $pendientes pendientes" no

# ═════════════════════════════════════════════════════════════════════════════
paso "HU-03 · El operador ve el semáforo y la alerta en el tablero, sin recargar"

estanques="$(curl -sf -H "Authorization: Bearer $OPERADOR" "$API/estanques")"
echo "$estanques" | python3 -c '
import json, sys
for e in json.load(sys.stdin)["datos"]:
    estado = "sin comunicación" if e.get("sin_comunicacion") else e.get("semaforo")
    print(f"   {e[\"codigo\"]:<18} {estado}")'

semaforo="$(echo "$estanques" | json 'next(e["semaforo"] for e in d["datos"] if e["codigo"]=="EST-03")')"
[[ "$semaforo" == "critico" ]] && criterio "EST-03 aparece en rojo · crítico (icono + palabra)" ok \
  || criterio "EST-03 en crítico (obtenido: $semaforo)" no

alertas_od3() {
  curl -sf -H "Authorization: Bearer $OPERADOR" "$API/alertas?estado=abierta" \
    | json 'sum(1 for a in d["datos"] if a["estanque_codigo"]=="EST-03" and a["parametro"]=="od_mgl")'
}
abiertas="$(alertas_od3)"
espera=$(( $(date +%s) - inicio ))
(( abiertas >= 1 && espera < 300 )) && criterio "Alerta crítica visible ${espera} s después de la medición (RNF-01 < 300 s)" ok \
  || criterio "Alerta crítica de EST-03 (abiertas: $abiertas, ${espera} s)" no

docker compose exec -T ingesta-service python /srv/scripts/simular_sensores.py \
  --critico --estanque EST-03 --host mosquitto --silencioso
sleep 20
[[ "$(alertas_od3)" == "1" ]] && criterio "CP-08 · Una segunda lectura crítica no duplica la alerta abierta" ok \
  || criterio "CP-08 · Alerta duplicada (abiertas: $(alertas_od3))" no

t0=$(date +%s%N)
curl -sf -o /dev/null -H "Authorization: Bearer $OPERADOR" "$API/estanques"
ms=$(( ($(date +%s%N) - t0) / 1000000 ))
(( ms < 3000 )) && criterio "Estado de los estanques en ${ms} ms (RNF-03 < 3 s)" ok \
  || criterio "Estado de los estanques en ${ms} ms (RNF-03 < 3 s)" no

# ═════════════════════════════════════════════════════════════════════════════
echo
echo "${NEGRITA}Criterios de aceptación validados en vivo: ${VERDE}${APROBADOS} ✔${NORMAL}${NEGRITA} · ${ROJO}${FALLIDOS} ✘${NORMAL}"
[[ $FALLIDOS -eq 0 ]]
