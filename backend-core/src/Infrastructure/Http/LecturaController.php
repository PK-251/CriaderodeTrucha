<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Http;

use DateTimeImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sippt\Application\UseCases\RegistrarLectura;
use Sippt\Domain\CalidadLectura;
use Sippt\Domain\Excepciones\SensorDesconocido;
use Sippt\Domain\Excepciones\UmbralNoConfigurado;

/**
 * POST /api/v1/lecturas — ingesta por lote (HU-02).
 *
 * Contrato del informe: 201 cuando todo el lote se acepta, 207 si alguna
 * lectura se descartó por calidad, 422 si el sensor no existe.
 */
final class LecturaController
{
    public function __construct(private readonly RegistrarLectura $registrarLectura) {}

    public function store(Request $request): JsonResponse
    {
        $lote = $request->input('lecturas');

        if (! is_array($lote) || $lote === []) {
            return response()->json([
                'error' => 'validacion',
                'detalle' => [['campo' => 'lecturas', 'mensaje' => 'El lote no puede estar vacio.']],
            ], 422);
        }

        $aceptadas = [];
        $descartadas = [];
        $duplicadas = 0;
        $alertas = [];

        foreach ($lote as $indice => $cruda) {
            if (! is_array($cruda)) {
                return $this->error422("lecturas.{$indice}", 'Cada lectura debe ser un objeto.');
            }

            $codigoNodo = (string) ($cruda['codigo_nodo'] ?? '');
            $valor = $cruda['valor'] ?? null;

            if (! is_numeric($valor)) {
                return $this->error422("lecturas.{$indice}.valor", 'El valor debe ser numerico.');
            }

            try {
                $medidoEn = new DateTimeImmutable((string) ($cruda['medido_en'] ?? ''));
            } catch (Exception) {
                return $this->error422(
                    "lecturas.{$indice}.medido_en",
                    'La marca temporal debe ser una fecha ISO 8601 valida.'
                );
            }

            try {
                $resultado = $this->registrarLectura->ejecutar($codigoNodo, $medidoEn, (float) $valor);
            } catch (SensorDesconocido $e) {
                // 422 según el contrato: el emisor debe corregir su carga útil.
                return $this->error422("lecturas.{$indice}.codigo_nodo", $e->getMessage());
            } catch (UmbralNoConfigurado) {
                // La lectura SÍ se almacenó; solo no pudo evaluarse. Rechazar el
                // lote entero por esto perdería datos válidos por un fallo de
                // configuración que el emisor no puede corregir.
                $aceptadas[] = ['codigo_nodo' => $codigoNodo, 'medido_en' => $medidoEn->format('c')];

                continue;
            }

            if (! $resultado->esNueva) {
                // CP-04: reenvío del búfer. No es un error.
                $duplicadas++;

                continue;
            }

            $registro = [
                'codigo_nodo' => $codigoNodo,
                'medido_en' => $medidoEn->format('c'),
                'calidad' => $resultado->lectura->calidad->value,
            ];

            if ($resultado->lectura->calidad === CalidadLectura::VALIDA) {
                $aceptadas[] = $registro;
            } else {
                $descartadas[] = $registro;
            }

            if ($resultado->alerta !== null) {
                $alertas[] = [
                    'id' => $resultado->alerta->id,
                    'estanque_id' => $resultado->alerta->estanqueId,
                    'parametro' => $resultado->alerta->parametro->value,
                    'severidad' => $resultado->alerta->severidad()->value,
                ];
            }
        }

        $cuerpo = [
            'aceptadas' => count($aceptadas),
            'descartadas' => count($descartadas),
            'duplicadas' => $duplicadas,
            'alertas' => $alertas,
            'detalle_descartadas' => $descartadas,
        ];

        // 207 cuando el lote se acepta solo en parte: el servicio de ingesta
        // necesita distinguir «todo bien» de «guardado, pero revisa el sensor».
        return response()->json($cuerpo, $descartadas === [] ? 201 : 207);
    }

    private function error422(string $campo, string $mensaje): JsonResponse
    {
        return response()->json([
            'error' => 'validacion',
            'detalle' => [['campo' => $campo, 'mensaje' => $mensaje]],
        ], 422);
    }
}
