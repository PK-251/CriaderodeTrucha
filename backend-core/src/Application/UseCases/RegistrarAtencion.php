<?php

declare(strict_types=1);

namespace Sippt\Application\UseCases;

use Sippt\Application\Puertos\Reloj;
use Sippt\Application\Puertos\RepositorioAlerta;
use Sippt\Application\Puertos\RepositorioAtencion;
use Sippt\Domain\AtencionAlerta;
use Sippt\Domain\Excepciones\AlertaYaAtendida;
use Sippt\Domain\Excepciones\ErrorDeValidacion;
use Sippt\Domain\Excepciones\RolNoAutorizado;
use Sippt\Domain\Rol;

/**
 * Registra la acción tomada frente a una alerta y la cierra (HU-05 / RF-08).
 *
 * Las tres condiciones de fallo se distinguen deliberadamente, porque el
 * contrato REST les asigna códigos distintos: rol sin permiso → 403, alerta ya
 * atendida → 409, alerta inexistente o acción vacía → 422.
 */
final readonly class RegistrarAtencion
{
    public function __construct(
        private RepositorioAlerta $alertas,
        private RepositorioAtencion $atenciones,
        private Reloj $reloj,
    ) {}

    public function ejecutar(
        int $alertaId,
        int $usuarioId,
        Rol $rol,
        string $accion,
        ?string $observacion = null,
    ): AtencionAlerta {
        if (! $rol->puedeRegistrarAtencion()) {
            throw new RolNoAutorizado($rol, 'registrar la atención de una alerta');
        }

        $alerta = $this->alertas->buscarPorId($alertaId);

        if ($alerta === null) {
            throw new ErrorDeValidacion('alerta_id', "No existe la alerta {$alertaId}.");
        }

        // CP-10: la comprobación se hace contra la atención ya registrada y no
        // solo contra el estado de la alerta, porque es la atención existente
        // lo que el adaptador debe devolver junto al 409.
        if ($this->atenciones->buscarPorAlerta($alertaId) !== null) {
            throw new AlertaYaAtendida($alertaId);
        }

        $momento = $this->reloj->ahora();

        // Cerrar la alerta antes de guardar la atención: si el estado ya era
        // 'atendida' —por ejemplo, tras una escritura concurrente— la propia
        // entidad lanza AlertaYaAtendida y no se crea un registro huérfano.
        $alerta->atender($usuarioId, $momento);

        $atencion = new AtencionAlerta(
            alertaId: $alertaId,
            usuarioId: $usuarioId,
            accion: $accion,
            observacion: $observacion,
            registradaEn: $momento,
        );

        $guardada = $this->atenciones->guardar($atencion);
        $this->alertas->guardar($alerta);

        return $guardada;
    }
}
