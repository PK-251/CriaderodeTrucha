-- ════════════════════════════════════════════════════════════════════════════
-- S3__carga_cp09.sql — Banco de pruebas para CP-09 y RNF-03
--
-- El informe exige que el tablero cargue el estado de hasta 20 estanques en
-- menos de 3 segundos. Los datos semilla traen cuatro, de modo que medir sobre
-- ellos no diría nada: el tiempo se resuelve en milisegundos y el
-- incumplimiento aparecería recién en producción.
--
-- Este archivo completa la piscigranja hasta 20 estanques (EST-05 a EST-20),
-- con sus tres sensores cada uno y 30 días de lecturas: ~518 000 filas en
-- total sumadas a las de S2.
--
-- NO se carga con ./scripts/migrar.sh --seed. Es un banco de medición que se
-- aplica a propósito:
--     docker compose exec -T postgres-timescale psql -U sippt -d sippt < db/seeds/S3__carga_cp09.sql
-- y se retira con db/seeds/S3__limpiar_cp09.sql
-- ════════════════════════════════════════════════════════════════════════════

SELECT setseed(0.17);

-- ── Estanques EST-05 a EST-20 ───────────────────────────────────────────────

INSERT INTO estanque (codigo, volumen_m3, biomasa_kg, etapa)
SELECT
    'EST-' || lpad(n::text, 2, '0'),
    100 + (n % 4) * 25,
    500 + (n % 6) * 220,
    (ARRAY['alevino', 'juvenil', 'engorde', 'cosecha'])[1 + (n % 4)]
FROM generate_series(5, 20) AS n
ON CONFLICT (codigo) DO NOTHING;

-- ── Umbrales ────────────────────────────────────────────────────────────────

INSERT INTO umbral_parametro (estanque_id, parametro, min_aceptable, max_aceptable, severidad_critica)
SELECT e.id, v.parametro, v.min_ace, v.max_ace, v.margen
FROM estanque e
CROSS JOIN (VALUES
    ('od_mgl'::parametro_t, 5.50, 12.00, 0.50),
    ('temp_c'::parametro_t, 9.00, 16.00, 2.00),
    ('ph'::parametro_t,     6.50,  8.50, 0.70)
) AS v(parametro, min_ace, max_ace, margen)
WHERE e.codigo ~ '^EST-(0[5-9]|1[0-9]|20)$'
ON CONFLICT (estanque_id, parametro) DO NOTHING;

-- ── Sensores ────────────────────────────────────────────────────────────────
-- Códigos P-xxx para distinguirlos de los nodos reales N-xx de S1.

INSERT INTO sensor (codigo_nodo, estanque_id, parametro, modelo, rango_fisico_min, rango_fisico_max, calibrado_en)
SELECT
    'P-' || lpad(e.id::text, 2, '0') || '-' || left(v.parametro::text, 2),
    e.id,
    v.parametro,
    v.modelo,
    v.fis_min,
    v.fis_max,
    now() - INTERVAL '10 days'
FROM estanque e
CROSS JOIN (VALUES
    ('od_mgl'::parametro_t, 'Sonda optica OD',  0.000, 20.000),
    ('temp_c'::parametro_t, 'Termistor PT100', -5.000, 45.000),
    ('ph'::parametro_t,     'Electrodo pH',     0.000, 14.000)
) AS v(parametro, modelo, fis_min, fis_max)
WHERE e.codigo ~ '^EST-(0[5-9]|1[0-9]|20)$'
ON CONFLICT (estanque_id, parametro) DO NOTHING;

-- ── 30 días de lecturas ─────────────────────────────────────────────────────

INSERT INTO lectura (sensor_id, medido_en, valor, calidad)
SELECT
    s.id,
    t.momento,
    GREATEST(
        s.rango_fisico_min,
        LEAST(s.rango_fisico_max, ROUND((c.base + c.amplitud * c.ciclo + c.ruido)::numeric, 3))
    ),
    'valida'
FROM sensor s
CROSS JOIN LATERAL generate_series(
        date_trunc('hour', now()) - INTERVAL '30 days',
        date_trunc('hour', now()),
        INTERVAL '5 minutes'
     ) AS t(momento)
CROSS JOIN LATERAL (
    SELECT
        sin(2 * pi() * ((EXTRACT(hour FROM t.momento) + EXTRACT(minute FROM t.momento) / 60.0) - 9) / 24) AS ciclo,
        CASE s.parametro WHEN 'od_mgl' THEN 7.40 WHEN 'temp_c' THEN 12.00 ELSE 7.40 END AS base,
        CASE s.parametro WHEN 'od_mgl' THEN 1.10 WHEN 'temp_c' THEN 2.00 ELSE 0.25 END AS amplitud,
        CASE s.parametro WHEN 'ph' THEN (random() - 0.5) * 0.10 ELSE (random() - 0.5) * 0.40 END AS ruido
) AS c
WHERE s.codigo_nodo LIKE 'P-%'
ON CONFLICT (sensor_id, medido_en) DO NOTHING;
