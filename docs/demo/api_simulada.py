"""API simulada con las formas de docs/openapi.yaml, solo para capturar el tablero sin el stack.

Uso: python3 docs/demo/api_simulada.py   (escucha en 127.0.0.1:8900)
"""
import json, math
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse, parse_qs

LIMA = timezone(timedelta(hours=-5))
AHORA = datetime.now(LIMA).replace(microsecond=0)
iso = lambda d: d.isoformat()

ETIQ = {"od_mgl": ("Oxígeno disuelto", "mg/L"), "temp_c": ("Temperatura", "°C"), "ph": ("pH", None)}

def p(par, val, mn, mx, seg=60, sev=None, sc=False):
    e, u = ETIQ[par]
    return dict(parametro=par, etiqueta=e, unidad=u, ultimo_valor=val,
                medido_en=iso(AHORA - timedelta(seconds=seg)), antiguedad_segundos=seg,
                sin_comunicacion=sc, min_aceptable=mn, max_aceptable=mx, severidad_alerta=sev)

ESTANQUES = [
    dict(id=1, codigo="EST-01", volumen_m3=80, biomasa_kg=420, etapa="alevino", semaforo="normal", sin_comunicacion=False,
         parametros=[p("od_mgl", 8.42, 6.5, 12), p("temp_c", 12.3, 9, 15), p("ph", 7.41, 6.8, 8.2)]),
    dict(id=2, codigo="EST-02", volumen_m3=120, biomasa_kg=980, etapa="juvenil", semaforo="advertencia", sin_comunicacion=False,
         parametros=[p("od_mgl", 7.10, 6, 12), p("temp_c", 16.6, 9, 16, sev="advertencia"), p("ph", 7.25, 6.5, 8.5)]),
    dict(id=3, codigo="EST-03", volumen_m3=150, biomasa_kg=1650, etapa="engorde", semaforo="critico", sin_comunicacion=False,
         parametros=[p("od_mgl", 4.20, 5.5, 12, seg=28, sev="critica"), p("temp_c", 13.1, 9, 16), p("ph", 7.30, 6.5, 8.5)]),
    dict(id=4, codigo="EST-04", volumen_m3=150, biomasa_kg=1820, etapa="cosecha", semaforo="normal", sin_comunicacion=True,
         parametros=[p("od_mgl", 7.80, 5.5, 12, seg=1380, sc=True), p("temp_c", 12.9, 9, 16, seg=1380, sc=True),
                     p("ph", 7.35, 6.5, 8.5, seg=1380, sc=True)]),
]

ALERTAS = [
    dict(id=41, estanque_id=3, estanque_codigo="EST-03", parametro="od_mgl", etiqueta="Oxígeno disuelto", unidad="mg/L",
         severidad="critica", estado="abierta", valor_detectado=4.20, umbral_violado=5.0, ultimo_valor=4.20,
         generada_en=iso(AHORA - timedelta(seconds=27)), actualizada_en=iso(AHORA - timedelta(seconds=27))),
    dict(id=40, estanque_id=2, estanque_codigo="EST-02", parametro="temp_c", etiqueta="Temperatura", unidad="°C",
         severidad="advertencia", estado="abierta", valor_detectado=16.4, umbral_violado=16.0, ultimo_valor=16.6,
         generada_en=iso(AHORA - timedelta(minutes=34)), actualizada_en=iso(AHORA - timedelta(minutes=4))),
]

def serie(par, n):
    pts = []
    for i in range(n):
        t = AHORA - timedelta(minutes=5 * (n - 1 - i))
        h = t.hour + t.minute / 60
        v = 7.6 + 1.1 * math.sin((h - 9) / 24 * 2 * math.pi)
        k = i - (n - 12)
        if k >= 0:
            v = v + (4.20 - v) * (k / 11)
        pts.append(dict(parametro=par, valor=round(v, 2), medido_en=iso(t)))
    return list(reversed(pts))

USUARIOS = {
    "operador": dict(id=1, nombre="Rosa Quispe", email="operador@sippt.local", rol="operador"),
    "tecnico": dict(id=2, nombre="Julio Mamani", email="tecnico@sippt.local", rol="tecnico"),
    "veterinario": dict(id=3, nombre="Carmen Huamán", email="veterinario@sippt.local", rol="veterinario"),
}

class H(BaseHTTPRequestHandler):
    def _r(self, cuerpo, codigo=200):
        b = json.dumps(cuerpo).encode()
        self.send_response(codigo); self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)
    def do_GET(self):
        u = urlparse(self.path); q = parse_qs(u.query); r = u.path.removeprefix("/api/v1")
        token = self.headers.get("Authorization", "").removeprefix("Bearer ")
        if r == "/auth/yo": return self._r({"datos": USUARIOS.get(token, USUARIOS["operador"])})
        if r == "/estanques": return self._r({"datos": ESTANQUES})
        if r == "/alertas": return self._r({"datos": ALERTAS})
        if r.startswith("/estanques/") and r.endswith("/lecturas"):
            return self._r({"datos": serie(q.get("parametro", ["od_mgl"])[0], int(q.get("por_pagina", ["288"])[0]))})
        self._r({"error": "no_encontrado"}, 404)
    def do_POST(self):
        largo = int(self.headers.get("Content-Length", 0) or 0)
        cuerpo = json.loads(self.rfile.read(largo) or b"{}")
        if self.path.endswith("/auth/login"):
            rol = cuerpo.get("email", "operador@").split("@")[0]
            return self._r({"datos": {"token": rol, "usuario": USUARIOS.get(rol, USUARIOS["operador"])}})
        if self.path.endswith("/estanques"):
            if any(e["codigo"] == cuerpo.get("codigo") for e in ESTANQUES):
                return self._r({"error": "validacion", "detalle": [{"campo": "codigo", "mensaje": "Ya existe un estanque con ese codigo."}]}, 422)
            return self._r({"datos": {"id": 99, **cuerpo}}, 201)
        self._r({"error": "no_encontrado"}, 404)
    def log_message(self, *a): pass

HTTPServer(("127.0.0.1", 8900), H).serve_forever()
