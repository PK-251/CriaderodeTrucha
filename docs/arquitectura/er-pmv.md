# Figura 4 — Diagrama entidad-relación del esquema del PMV

PostgreSQL 16 + TimescaleDB · migraciones `V1`–`V4`.

Siete tablas. `lectura` es una *hypertable* particionada por `medido_en`; el
resto son tablas relacionales ordinarias. La separación entre la serie temporal
y las entidades maestras es deliberada: sostiene las 288 lecturas diarias por
sensor sin degradar las consultas del tablero.

```mermaid
erDiagram
    usuario ||--o{ atencion_alerta : "registra"
    usuario ||--o{ estanque        : "audita"
    estanque ||--o{ umbral_parametro : "define"
    estanque ||--o{ sensor           : "aloja"
    estanque ||--o{ alerta           : "origina"
    sensor   ||--o{ lectura          : "emite"
    alerta   ||--|| atencion_alerta  : "se atiende con"

    usuario {
        bigserial id PK
        varchar   nombre
        varchar   email UK
        varchar   password_hash
        varchar   rol "operador|tecnico|veterinario"
        boolean   activo
        timestamptz creado_en
    }

    estanque {
        bigserial id PK
        varchar   codigo UK
        numeric   volumen_m3 "CHECK > 0"
        numeric   biomasa_kg
        varchar   etapa "alevino|juvenil|engorde|cosecha"
        boolean   activo
        bigint    creado_por FK
        bigint    actualizado_por FK
        timestamptz actualizado_en
    }

    umbral_parametro {
        bigserial id PK
        bigint    estanque_id FK
        parametro_t parametro
        numeric   min_aceptable
        numeric   max_aceptable
        numeric   severidad_critica "margen critico"
        bigint    creado_por FK
    }

    sensor {
        bigserial id PK
        varchar   codigo_nodo UK
        bigint    estanque_id FK
        parametro_t parametro
        varchar   modelo
        numeric   rango_fisico_min
        numeric   rango_fisico_max
        timestamptz calibrado_en
        boolean   activo
    }

    lectura {
        bigint    sensor_id PK_FK
        timestamptz medido_en PK "particion"
        numeric   valor
        varchar   calidad "valida|dudosa|descartada"
        timestamptz recibido_en
    }

    alerta {
        bigserial id PK
        bigint    estanque_id FK
        parametro_t parametro
        severidad_t severidad "advertencia|critica"
        estado_alerta estado "abierta|atendida"
        numeric   valor_detectado
        numeric   umbral_violado
        numeric   ultimo_valor
        timestamptz generada_en
        bigint    cerrada_por FK
    }

    atencion_alerta {
        bigserial id PK
        bigint    alerta_id FK_UK
        bigint    usuario_id FK
        varchar   accion
        text      observacion
        timestamptz registrada_en
    }
```

## Restricciones que hacen cumplir las reglas de negocio

Estas no son validaciones defensivas duplicadas: son la última línea que impide
que un defecto del código corrompa los datos.

| Restricción | Tabla | Regla que protege |
|---|---|---|
| `CHECK (volumen_m3 > 0)` | `estanque` | Un estanque sin volumen no es medible |
| `UNIQUE (codigo)` | `estanque` | **CP-02** — código duplicado → 422 |
| `CHECK (min_aceptable < max_aceptable)` | `umbral_parametro` | **CP-01** — rango invertido → 422 |
| `UNIQUE (estanque_id, parametro)` | `umbral_parametro` | Un solo umbral vigente por parámetro |
| `PRIMARY KEY (sensor_id, medido_en)` | `lectura` | **CP-04 / RNF-02** — el reenvío del búfer no duplica |
| `CHECK (calidad IN …)` | `lectura` | **CP-05** — la calidad es un estado cerrado |
| `UNIQUE … WHERE estado = 'abierta'` | `alerta` | **CP-08** — una sola alerta abierta por estanque y parámetro |
| `UNIQUE (alerta_id)` | `atencion_alerta` | **CP-10** — atención duplicada → 409 |
| Disparador `actualizado_en` | 4 tablas | **RNF-04** — marca temporal en toda escritura |

## Índice parcial de alerta abierta

```sql
CREATE UNIQUE INDEX idx_alerta_abierta_unica
  ON alerta (estanque_id, parametro)
  WHERE estado = 'abierta';
```

Es la pieza que resuelve CP-08 en la base y no solo en el código. Mientras una
alerta siga abierta, una segunda lectura fuera de rango del mismo parámetro no
puede insertar una alerta nueva: el caso de uso `EvaluarUmbrales` actualiza
`ultimo_valor`. Al cerrarse la alerta (`estado = 'atendida'`), la fila sale del
índice parcial y una condición de riesgo posterior vuelve a poder abrir una.

## Volumen y particionado

| Concepto | Valor |
|---|---|
| Intervalo de muestreo | 5 minutos |
| Lecturas por sensor y día | 288 |
| Sensores en la base semilla | 12 (3 parámetros × 4 estanques) |
| Filas en 30 días | 103 692 |
| Tamaño del fragmento | 30 días |
| Compresión | a partir de 90 días, segmentada por `sensor_id` |
| Retención | 36 meses (RNF-06 exige ≥ 24) |
