<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sippt\Application\Puertos\RepositorioAtencion;
use Sippt\Application\UseCases\RegistrarAtencion;
use Sippt\Domain\EstadoAlerta;
use Sippt\Domain\Excepciones\AlertaYaAtendida;
use Sippt\Domain\Excepciones\ErrorDeValidacion;
use Sippt\Domain\Excepciones\RolNoAutorizado;
use Sippt\Domain\Parametro;
use Sippt\Domain\Rol;
use Sippt\Domain\Severidad;
use Sippt\Infrastructure\Persistence\Modelos\AlertaModel;

/**
 * Adaptador primario HTTP para las alertas (HU-04) y su atención (HU-05).
 */
final class AlertaController
{
    public function __construct(
        private readonly RegistrarAtencion $registrarAtencion,
        private readonly RepositorioAtencion $atenciones,
    ) {}

    /** GET /api/v1/alertas — listado por fecha descendente, con filtros. */
    public function index(Request $request): JsonResponse
    {
        $consulta = AlertaModel::query()
            ->join('estanque', 'estanque.id', '=', 'alerta.estanque_id')
            ->select([
                'alerta.*',
                'estanque.codigo as estanque_codigo',
            ]);

        if (($estado = $request->query('estado')) !== null) {
            if (EstadoAlerta::tryFrom((string) $estado) === null) {
                return $this->error422('estado', 'El estado debe ser abierta o atendida.');
            }
            $consulta->where('alerta.estado', $estado);
        }

        if (($severidad = $request->query('severidad')) !== null) {
            if (Severidad::tryFrom((string) $severidad) === null) {
                return $this->error422('severidad', 'La severidad debe ser advertencia o critica.');
            }
            $consulta->where('alerta.severidad', $severidad);
        }

        $filas = $consulta->orderByDesc('alerta.generada_en')->limit(500)->get();

        $datos = $filas->map(function (AlertaModel $fila): array {
            $parametro = Parametro::tryFrom($fila->parametro);

            return [
                'id' => $fila->id,
                'estanque_id' => $fila->estanque_id,
                'estanque_codigo' => $fila->getAttribute('estanque_codigo'),
                'parametro' => $fila->parametro,
                'etiqueta' => $parametro?->etiqueta(),
                'unidad' => $parametro?->unidad(),
                'severidad' => $fila->severidad,
                'estado' => $fila->estado,
                'valor_detectado' => $fila->valor_detectado,
                'umbral_violado' => $fila->umbral_violado,
                'ultimo_valor' => $fila->ultimo_valor,
                'generada_en' => $fila->generada_en->format('c'),
                'actualizada_en' => $fila->actualizada_en->format('c'),
            ];
        })->all();

        return response()->json(['datos' => $datos]);
    }

    /**
     * POST /api/v1/alertas/{id}/atencion — registro de la intervención (HU-05).
     *
     * 201 con la atención registrada; 409 si la alerta ya fue atendida,
     * devolviendo el registro existente; 403 si el rol no está autorizado.
     */
    public function atender(Request $request, int $id): JsonResponse
    {
        $usuario = $request->user();

        if ($usuario === null) {
            return response()->json(['error' => 'no_autenticado'], 401);
        }

        $rol = Rol::tryFrom((string) $usuario->getAttribute('rol'));

        if ($rol === null) {
            return response()->json(['error' => 'rol_desconocido'], 403);
        }

        try {
            $atencion = $this->registrarAtencion->ejecutar(
                alertaId: $id,
                usuarioId: (int) $usuario->getAuthIdentifier(),
                rol: $rol,
                accion: (string) $request->input('accion', ''),
                observacion: $request->input('observacion') === null
                    ? null
                    : (string) $request->input('observacion'),
            );
        } catch (RolNoAutorizado $e) {
            return response()->json([
                'error' => 'rol_no_autorizado',
                'mensaje' => $e->getMessage(),
            ], 403);
        } catch (AlertaYaAtendida $e) {
            // CP-10: el contrato exige devolver el registro existente junto al
            // 409, para que el tablero lo muestre en lugar de duplicarlo.
            $existente = $this->atenciones->buscarPorAlerta($id);

            return response()->json([
                'error' => 'alerta_ya_atendida',
                'mensaje' => $e->getMessage(),
                'datos' => $existente === null ? null : [
                    'id' => $existente->id,
                    'alerta_id' => $existente->alertaId,
                    'usuario_id' => $existente->usuarioId,
                    'accion' => $existente->accion,
                    'observacion' => $existente->observacion,
                    'registrada_en' => $existente->registradaEn->format('c'),
                ],
            ], 409);
        } catch (ErrorDeValidacion $e) {
            return $this->error422($e->campo, $e->getMessage());
        }

        return response()->json([
            'datos' => [
                'id' => $atencion->id,
                'alerta_id' => $atencion->alertaId,
                'usuario_id' => $atencion->usuarioId,
                'accion' => $atencion->accion,
                'observacion' => $atencion->observacion,
                'registrada_en' => $atencion->registradaEn->format('c'),
            ],
        ], 201);
    }

    private function error422(string $campo, string $mensaje): JsonResponse
    {
        return response()->json([
            'error' => 'validacion',
            'detalle' => [['campo' => $campo, 'mensaje' => $mensaje]],
        ], 422);
    }
}
