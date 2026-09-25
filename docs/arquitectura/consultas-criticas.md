# Consultas críticas del PMV

Medidas sobre la base semilla: 12 sensores, 103 692 lecturas, 30 días.

## Estado actual de los estanques (HU-03 · RNF-03)

Es la consulta que ejecuta `GET /api/v1/estanques` en cada carga del tablero y
en cada refresco. RNF-03 exige resolver hasta 20 estanques en menos de 3
segundos, así que es la única consulta del PMV con un presupuesto de tiempo
explícito.

### Patrón correcto — `LATERAL … LIMIT 1`

```sql
SELECT s.id, s.estanque_id, s.parametro, u.valor, u.medido_en
FROM sensor s
LEFT JOIN LATERAL (
  SELECT l.valor, l.medido_en
  FROM lectura l
  WHERE l.sensor_id = s.id AND l.calidad = 'valida'
  ORDER BY l.medido_en DESC
  LIMIT 1
) u ON TRUE
WHERE s.activo;
```

**0.158 ms.** El planificador resuelve cada sensor con una búsqueda por
`idx_lectura_reciente` que se detiene en la primera fila. El coste crece con el
número de sensores, no con el tamaño del histórico.

### Patrón a evitar — `DISTINCT ON`

```sql
SELECT DISTINCT ON (s.id) s.id, l.valor, l.medido_en
FROM sensor s
JOIN lectura l ON l.sensor_id = s.id AND l.calidad = 'valida'
WHERE s.activo
ORDER BY s.id, l.medido_en DESC;
```

**21.7 ms**, 137 veces más lento. Recorre los 102 043 registros válidos de todos
los fragmentos para quedarse con 12 filas. Produce el resultado correcto, pero
se degrada linealmente: con 20 estanques (60 sensores) y un año de histórico
serían ~25 millones de filas recorridas en cada refresco del tablero.

La diferencia no se nota con la base semilla —ambas consultas responden rápido—
y por eso conviene dejarla escrita: es el tipo de decisión que pasa inadvertida
en desarrollo y aparece como incumplimiento de RNF-03 en producción.

## Marca de «sin comunicación» (HU-03)

Un estanque cuyo sensor no reporta hace más de `SIN_COMUNICACION_MIN` minutos
(15 por defecto) se muestra como sin comunicación. Se deriva de `medido_en` de
la consulta anterior, sin necesidad de una segunda consulta:

```sql
now() - u.medido_en > (INTERVAL '1 minute' * :minutos)
```

Importa que la comparación use `medido_en` (instante de la medición según el
nodo) y no `recibido_en`: si el gateway reenvía su búfer tras una caída, las
lecturas llegan con retraso pero su marca temporal es la real, y el estanque
debe seguir apareciendo como incomunicado durante el corte.

## Filtro de calidad

Toda consulta que alimente el tablero o el entrenamiento debe filtrar
`calidad = 'valida'`. En la base semilla, el 1.59 % de las lecturas está marcado
como `dudosa` o `descartada`; omitir el filtro las mezclaría con las buenas y
contaminaría tanto el semáforo como el futuro dataset.
