<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Http;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Sippt\Application\Puertos\Reloj;
use Sippt\Domain\Estanque;
use Sippt\Domain\Excepciones\ErrorDeValidacion;
use Sippt\Domain\Parametro;
use Sippt\Domain\Umbral;
use Sippt\Infrastructure\Persistence\Modelos\EstanqueModel;
use Sippt\Infrastructure\Persistence\Modelos\UmbralModel;
use Throwable;

/**
 * Adaptador primario HTTP para la gestión y consulta de estanques
 * (HU-01 y HU-03).
 */
final class EstanqueController
{
    public function __construct(private readonly Reloj $reloj) {}

    /**
     * GET /api/v1/estanques — estado actual con semáforo y antigüedad (HU-03).
     *
     * Usa el patrón LATERAL … LIMIT 1 documentado en
     * docs/arquitectura/consultas-criticas.md: resuelve el estado en tiempo
     * proporcional al número de sensores y no al tamaño del histórico, que es
     * lo que sostiene RNF-03 cuando la piscigranja crece a 20 estanques.
     */
    public function index(Request $request): JsonResponse
    {
        $minutosSinComunicacion = (int) $request->query('sin_comunicacion_min', '15');

        $filas = DB::select(
            'SELECT e.id, e.codigo, e.volumen_m3, e.biomasa_kg, e.etapa,
                    s.parametro, u.valor, u.medido_en,
                    um.min_aceptable, um.max_aceptable, um.severidad_critica,
                    a.severidad AS severidad_alerta
             FROM estanque e
             LEFT JOIN sensor s ON s.estanque_id = e.id AND s.activo
             LEFT JOIN umbral_parametro um
                    ON um.estanque_id = e.id AND um.parametro = s.parametro
             LEFT JOIN LATERAL (
                 SELECT l.valor, l.medido_en
                 FROM lectura l
                 WHERE l.sensor_id = s.id AND l.calidad = \'valida\'
                 ORDER BY l.medido_en DESC
                 LIMIT 1
             ) u ON TRUE
             LEFT JOIN alerta a
                    ON a.estanque_id = e.id AND a.parametro = s.parametro
                   AND a.estado = \'abierta\'
             WHERE e.activo
             ORDER BY e.codigo, s.parametro'
        );

        $ahora = $this->reloj->ahora();
        $estanques = [];

        foreach ($filas as $fila) {
            $id = (int) $fila->id;

            $estanques[$id] ??= [
                'id' => $id,
                'codigo' => $fila->codigo,
                'volumen_m3' => (float) $fila->volumen_m3,
                'biomasa_kg' => (float) $fila->biomasa_kg,
                'etapa' => $fila->etapa,
                'semaforo' => 'normal',
                'sin_comunicacion' => false,
                'parametros' => [],
            ];

            if ($fila->parametro === null) {
                continue;
            }

            $medidoEn = $fila->medido_en === null
                ? null
                : new DateTimeImmutable($fila->medido_en);

            $antiguedad = $medidoEn?->diff($ahora);
            $segundos = $medidoEn === null
                ? null
                : $ahora->getTimestamp() - $medidoEn->getTimestamp();

            // La antigüedad se mide sobre medido_en, el instante que fijó el
            // nodo. Usar recibido_en ocultaría un corte de enlace: las lecturas
            // retenidas en el búfer llegan juntas y el estanque parecería al
            // día cuando en realidad estuvo incomunicado.
            $incomunicado = $segundos === null || $segundos > $minutosSinComunicacion * 60;

            $estanques[$id]['parametros'][] = [
                'parametro' => $fila->parametro,
                'etiqueta' => Parametro::tryFrom($fila->parametro)?->etiqueta(),
                'unidad' => Parametro::tryFrom($fila->parametro)?->unidad(),
                'ultimo_valor' => $fila->valor === null ? null : (float) $fila->valor,
                'medido_en' => $medidoEn?->format('c'),
                'antiguedad_segundos' => $segundos,
                'sin_comunicacion' => $incomunicado,
                'min_aceptable' => $fila->min_aceptable === null ? null : (float) $fila->min_aceptable,
                'max_aceptable' => $fila->max_aceptable === null ? null : (float) $fila->max_aceptable,
                'severidad_alerta' => $fila->severidad_alerta,
            ];

            if ($incomunicado) {
                $estanques[$id]['sin_comunicacion'] = true;
            }

            $estanques[$id]['semaforo'] = $this->peorSemaforo(
                $estanques[$id]['semaforo'],
                $fila->severidad_alerta,
            );

            unset($antiguedad);
        }

        return response()->json(['datos' => array_values($estanques)]);
    }

    /**
     * POST /api/v1/estanques — alta con sus umbrales (HU-01).
     *
     * 201 con el recurso creado; 422 con detalle por campo si el rango es
     * inválido (CP-01) o el código está repetido (CP-02).
     */
    public function store(Request $request): JsonResponse
    {
        $usuarioId = $request->user()?->getAuthIdentifier();

        try {
            $estanque = new Estanque(
                codigo: (string) $request->input('codigo', ''),
                volumenM3: (float) $request->input('volumen_m3', 0),
                biomasaKg: (float) $request->input('biomasa_kg', 0),
                etapa: (string) $request->input('etapa', ''),
            );

            $umbrales = $this->umbralesDesde($request);
        } catch (ErrorDeValidacion $e) {
            return $this->error422($e);
        }

        // CP-02: la unicidad del código la garantiza la restricción UNIQUE de
        // la base. Comprobarla antes con un SELECT dejaría una ventana de
        // carrera entre la comprobación y la inserción.
        try {
            return DB::transaction(function () use ($estanque, $umbrales, $usuarioId): JsonResponse {
                $fila = EstanqueModel::query()->create([
                    'codigo' => $estanque->codigo,
                    'volumen_m3' => $estanque->volumenM3,
                    'biomasa_kg' => $estanque->biomasaKg,
                    'etapa' => $estanque->etapa,
                    'activo' => true,
                    'creado_por' => $usuarioId,     // RNF-04
                    'actualizado_por' => $usuarioId,
                ]);

                foreach ($umbrales as $umbral) {
                    UmbralModel::query()->create([
                        'estanque_id' => $fila->id,
                        'parametro' => $umbral->parametro->value,
                        'min_aceptable' => $umbral->minAceptable,
                        'max_aceptable' => $umbral->maxAceptable,
                        'severidad_critica' => $umbral->severidadCritica,
                        'creado_por' => $usuarioId,
                        'actualizado_por' => $usuarioId,
                    ]);
                }

                return response()->json([
                    'datos' => [
                        'id' => $fila->id,
                        'codigo' => $fila->codigo,
                        'volumen_m3' => (float) $fila->volumen_m3,
                        'biomasa_kg' => (float) $fila->biomasa_kg,
                        'etapa' => $fila->etapa,
                        'umbrales' => array_map(static fn (Umbral $u): array => [
                            'parametro' => $u->parametro->value,
                            'min_aceptable' => $u->minAceptable,
                            'max_aceptable' => $u->maxAceptable,
                            'severidad_critica' => $u->severidadCritica,
                        ], $umbrales),
                    ],
                ], 201);
            });
        } catch (Throwable $e) {
            if ($this->esViolacionDeUnicidad($e)) {
                return response()->json([
                    'error' => 'validacion',
                    'detalle' => [[
                        'campo' => 'codigo',
                        'mensaje' => 'Ya existe un estanque con ese codigo.',
                    ]],
                ], 422);
            }

            throw $e;
        }
    }

    /** GET /api/v1/estanques/{id}/lecturas — serie temporal paginada (HU-03). */
    public function lecturas(Request $request, int $id): JsonResponse
    {
        if (EstanqueModel::query()->find($id) === null) {
            return response()->json([
                'error' => 'validacion',
                'detalle' => [['campo' => 'id', 'mensaje' => "No existe el estanque {$id}."]],
            ], 422);
        }

        $parametro = $request->query('parametro');

        if ($parametro !== null && Parametro::tryFrom((string) $parametro) === null) {
            return response()->json([
                'error' => 'validacion',
                'detalle' => [['campo' => 'parametro', 'mensaje' => 'Parametro no reconocido en el PMV.']],
            ], 422);
        }

        $porPagina = min(max((int) $request->query('por_pagina', '500'), 1), 5000);
        $pagina = max((int) $request->query('pagina', '1'), 1);

        $condiciones = ['s.estanque_id = ?', "l.calidad = 'valida'"];
        $parametros = [$id];

        if ($parametro !== null) {
            $condiciones[] = 's.parametro = ?';
            $parametros[] = $parametro;
        }

        if (($desde = $request->query('desde')) !== null) {
            $condiciones[] = 'l.medido_en >= ?';
            $parametros[] = $desde;
        }

        if (($hasta = $request->query('hasta')) !== null) {
            $condiciones[] = 'l.medido_en <= ?';
            $parametros[] = $hasta;
        }

        $where = implode(' AND ', $condiciones);

        // count(*) siempre devuelve exactamente una fila, incluso cuando el
        // filtro no selecciona nada: no hay caso nulo que contemplar.
        $conteo = DB::selectOne(
            "SELECT count(*) AS n FROM lectura l JOIN sensor s ON s.id = l.sensor_id WHERE {$where}",
            $parametros,
        );
        $total = $conteo === null ? 0 : (int) $conteo->n;

        $filas = DB::select(
            "SELECT s.parametro, l.valor, l.medido_en
             FROM lectura l
             JOIN sensor s ON s.id = l.sensor_id
             WHERE {$where}
             ORDER BY l.medido_en DESC
             LIMIT ? OFFSET ?",
            [...$parametros, $porPagina, ($pagina - 1) * $porPagina],
        );

        return response()->json([
            'datos' => array_map(static fn (object $f): array => [
                'parametro' => $f->parametro,
                'valor' => (float) $f->valor,
                'medido_en' => (new DateTimeImmutable($f->medido_en))->format('c'),
            ], $filas),
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total' => $total,
            ],
        ]);
    }

    /**
     * @return list<Umbral>
     *
     * @throws ErrorDeValidacion
     */
    private function umbralesDesde(Request $request): array
    {
        $entrada = $request->input('umbrales', []);

        if (! is_array($entrada)) {
            throw new ErrorDeValidacion('umbrales', 'Los umbrales deben enviarse como lista.');
        }

        $umbrales = [];

        foreach ($entrada as $fila) {
            if (! is_array($fila)) {
                throw new ErrorDeValidacion('umbrales', 'Cada umbral debe ser un objeto.');
            }

            $parametro = Parametro::tryFrom((string) ($fila['parametro'] ?? ''));

            if ($parametro === null) {
                throw new ErrorDeValidacion(
                    'umbrales.parametro',
                    'El parametro debe ser od_mgl, temp_c o ph.'
                );
            }

            $umbrales[] = new Umbral(
                estanqueId: 0,
                parametro: $parametro,
                minAceptable: (float) ($fila['min_aceptable'] ?? 0),
                maxAceptable: (float) ($fila['max_aceptable'] ?? 0),
                severidadCritica: (float) ($fila['severidad_critica'] ?? 0),
            );
        }

        return $umbrales;
    }

    private function error422(ErrorDeValidacion $e): JsonResponse
    {
        return response()->json([
            'error' => 'validacion',
            'detalle' => [['campo' => $e->campo, 'mensaje' => $e->getMessage()]],
        ], 422);
    }

    private function esViolacionDeUnicidad(Throwable $e): bool
    {
        // 23505 es unique_violation en PostgreSQL.
        return str_contains($e->getMessage(), '23505');
    }

    private function peorSemaforo(string $actual, ?string $severidad): string
    {
        $orden = ['normal' => 0, 'advertencia' => 1, 'critico' => 2];
        $candidato = match ($severidad) {
            'critica' => 'critico',
            'advertencia' => 'advertencia',
            default => 'normal',
        };

        return $orden[$candidato] > $orden[$actual] ? $candidato : $actual;
    }
}
