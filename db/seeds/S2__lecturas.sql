-- ════════════════════════════════════════════════════════════════════════════
-- S2__lecturas.sql — 30 días de lecturas simuladas
--
-- Una lectura cada 5 minutos por sensor: 288 diarias, 8 640 por sensor en el
-- periodo, ~103 700 filas para los 12 sensores. Es el volumen que el informe
-- declara en la Matriz 5.1 («de 2 mediciones diarias anotadas a mano a 288
-- lecturas automáticas por sensor y día»).
--
-- Los valores siguen el ciclo diurno real de un estanque: el oxígeno disuelto
-- cae durante la noche por la respiración y alcanza su máximo a media tarde;
-- la temperatura acompaña al ciclo solar. Esto importa porque un histórico
-- plano no permitiría distinguir después una anomalía de una variación normal.
--
-- Los valores se mantienen DENTRO de los rangos aceptables a propósito: las
-- alertas las genera la política ReglaUmbral del núcleo al evaluar lecturas
-- entrantes, no la carga inicial de datos. Para provocar escenarios de riesgo
-- se usa scripts/simular_sensores.py (Fase 6).
-- ════════════════════════════════════════════════════════════════════════════

-- Semilla fija: dos ejecuciones sobre base limpia producen el mismo conjunto,
-- lo que hace reproducibles las mediciones de rendimiento del tablero (CP-09).
SELECT setseed(0.42);

INSERT INTO lectura (sensor_id, medido_en, valor, calidad)
SELECT
    s.id,
    t.momento,
    GREATEST(
        s.rango_fisico_min,
        LEAST(s.rango_fisico_max, ROUND((c.base + c.amplitud * c.ciclo + c.ruido)::numeric, 3))
    ),
    c.calidad
FROM sensor s
CROSS JOIN LATERAL generate_series(
        date_trunc('hour', now()) - INTERVAL '30 days',
        date_trunc('hour', now()),
        INTERVAL '5 minutes'
     ) AS t(momento)
CROSS JOIN LATERAL (
    SELECT
        -- Ciclo diurno: mínimo a las 03:00, máximo a las 15:00.
        sin(2 * pi() * ((EXTRACT(hour FROM t.momento) + EXTRACT(minute FROM t.momento) / 60.0) - 9) / 24) AS ciclo,
        CASE s.parametro
            WHEN 'od_mgl' THEN 7.40
            WHEN 'temp_c' THEN 12.00
            WHEN 'ph'     THEN 7.40
            ELSE 0.00
        END AS base,
        CASE s.parametro
            WHEN 'od_mgl' THEN 1.10
            WHEN 'temp_c' THEN 2.00
            WHEN 'ph'     THEN 0.25
            ELSE 0.00
        END AS amplitud,
        CASE s.parametro
            WHEN 'od_mgl' THEN (random() - 0.5) * 0.40
            WHEN 'temp_c' THEN (random() - 0.5) * 0.40
            WHEN 'ph'     THEN (random() - 0.5) * 0.10
            ELSE 0.00
        END AS ruido,
        -- Calidad del dato: la inmensa mayoría válida, con una fracción
        -- marcada como dudosa o descartada para que el tablero y las consultas
        -- de entrenamiento tengan que filtrarla desde el primer día.
        CASE
            WHEN random() < 0.004 THEN 'descartada'
            WHEN random() < 0.012 THEN 'dudosa'
            ELSE 'valida'
        END AS calidad
) AS c
WHERE s.activo = TRUE
ON CONFLICT (sensor_id, medido_en) DO NOTHING;

-- Última calibración coherente con el histórico cargado.
UPDATE sensor SET calibrado_en = now() - INTERVAL '30 days' WHERE calibrado_en IS NULL;
