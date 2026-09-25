-- ════════════════════════════════════════════════════════════════════════════
-- S1__maestras.sql — Datos semilla: usuarios, estanques, umbrales y sensores
--
-- Cuatro estanques en distintas etapas productivas, cada uno con sus tres
-- parámetros del PMV (oxígeno disuelto, temperatura y pH) y su nodo sensor.
-- Los rangos aceptables provienen de los requerimientos de la trucha arcoíris
-- y varían según la etapa: un alevino tolera menos que un ejemplar de engorde.
-- ════════════════════════════════════════════════════════════════════════════

-- ── Usuarios ────────────────────────────────────────────────────────────────
-- Contraseña de todos: "sippt2026" (hash bcrypt, solo para el entorno local).

INSERT INTO usuario (nombre, email, password_hash, rol) VALUES
  ('Operador de estanques', 'operador@sippt.local',   '$2y$12$e0NRzR2Zq8kGx3vJ1qXQ5u5x0m2K9pQ7sYwT4hJ6nLbVc8dFaGiOe', 'operador'),
  ('Tecnico acuicola',      'tecnico@sippt.local',    '$2y$12$e0NRzR2Zq8kGx3vJ1qXQ5u5x0m2K9pQ7sYwT4hJ6nLbVc8dFaGiOe', 'tecnico'),
  ('Veterinario acuicola',  'veterinario@sippt.local','$2y$12$e0NRzR2Zq8kGx3vJ1qXQ5u5x0m2K9pQ7sYwT4hJ6nLbVc8dFaGiOe', 'veterinario');

-- ── Estanques ───────────────────────────────────────────────────────────────

INSERT INTO estanque (codigo, volumen_m3, biomasa_kg, etapa) VALUES
  ('EST-01',  80.00,  420.00, 'alevino'),
  ('EST-02', 120.00,  980.00, 'juvenil'),
  ('EST-03', 150.00, 1650.00, 'engorde'),
  ('EST-04', 150.00, 1820.00, 'cosecha');

-- ── Umbrales por estanque y parámetro ───────────────────────────────────────
-- severidad_critica es el margen más allá del rango aceptable (ver V1).
--
-- EST-03 reproduce el escenario del informe: min_aceptable 5.5 y margen 0.5
-- para el oxígeno disuelto, de modo que el límite crítico es exactamente 5.0
-- (casos CP-06 y CP-07).

INSERT INTO umbral_parametro (estanque_id, parametro, min_aceptable, max_aceptable, severidad_critica)
SELECT e.id, v.parametro, v.min_ace, v.max_ace, v.margen
FROM estanque e
JOIN (VALUES
  -- estanque, parámetro,          min,   max,   margen
  ('EST-01', 'od_mgl'::parametro_t, 6.50, 12.00, 0.50),
  ('EST-01', 'temp_c'::parametro_t, 9.00, 15.00, 2.00),
  ('EST-01', 'ph'::parametro_t,     6.80,  8.20, 0.60),

  ('EST-02', 'od_mgl'::parametro_t, 6.00, 12.00, 0.50),
  ('EST-02', 'temp_c'::parametro_t, 9.00, 16.00, 2.00),
  ('EST-02', 'ph'::parametro_t,     6.50,  8.50, 0.70),

  ('EST-03', 'od_mgl'::parametro_t, 5.50, 12.00, 0.50),
  ('EST-03', 'temp_c'::parametro_t, 9.00, 16.00, 2.00),
  ('EST-03', 'ph'::parametro_t,     6.50,  8.50, 0.70),

  ('EST-04', 'od_mgl'::parametro_t, 5.50, 12.00, 0.50),
  ('EST-04', 'temp_c'::parametro_t, 9.00, 16.00, 2.00),
  ('EST-04', 'ph'::parametro_t,     6.50,  8.50, 0.70)
) AS v(codigo, parametro, min_ace, max_ace, margen)
  ON v.codigo = e.codigo;

-- ── Sensores ────────────────────────────────────────────────────────────────
-- El rango físico es el del instrumento, no el biológico: sirve para descartar
-- lecturas imposibles antes de evaluar umbrales (CP-05). Una sonda de pH mide
-- de 0 a 14, así que un 14.8 es un fallo del sensor, no una condición del agua.

INSERT INTO sensor (codigo_nodo, estanque_id, parametro, modelo, rango_fisico_min, rango_fisico_max, calibrado_en)
SELECT v.nodo, e.id, v.parametro, v.modelo, v.fis_min, v.fis_max, now() - INTERVAL '10 days'
FROM estanque e
JOIN (VALUES
  ('N-01', 'EST-01', 'od_mgl'::parametro_t, 'Sonda optica OD',  0.000, 20.000),
  ('N-02', 'EST-01', 'temp_c'::parametro_t, 'Termistor PT100', -5.000, 45.000),
  ('N-03', 'EST-01', 'ph'::parametro_t,     'Electrodo pH',     0.000, 14.000),

  ('N-04', 'EST-02', 'od_mgl'::parametro_t, 'Sonda optica OD',  0.000, 20.000),
  ('N-05', 'EST-02', 'temp_c'::parametro_t, 'Termistor PT100', -5.000, 45.000),
  ('N-06', 'EST-02', 'ph'::parametro_t,     'Electrodo pH',     0.000, 14.000),

  ('N-07', 'EST-03', 'od_mgl'::parametro_t, 'Sonda optica OD',  0.000, 20.000),
  ('N-08', 'EST-03', 'temp_c'::parametro_t, 'Termistor PT100', -5.000, 45.000),
  ('N-09', 'EST-03', 'ph'::parametro_t,     'Electrodo pH',     0.000, 14.000),

  ('N-10', 'EST-04', 'od_mgl'::parametro_t, 'Sonda optica OD',  0.000, 20.000),
  ('N-11', 'EST-04', 'temp_c'::parametro_t, 'Termistor PT100', -5.000, 45.000),
  ('N-12', 'EST-04', 'ph'::parametro_t,     'Electrodo pH',     0.000, 14.000)
) AS v(nodo, codigo, parametro, modelo, fis_min, fis_max)
  ON v.codigo = e.codigo;
