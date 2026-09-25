<?php

declare(strict_types=1);

namespace Sippt\Domain;

use DateTimeImmutable;
use Sippt\Domain\Excepciones\AlertaYaAtendida;

/**
 * Condición de riesgo detectada en un estanque (RF-06).
 *
 * A diferencia del resto del dominio, no es inmutable: una alerta abierta vive
 * mientras la condición persiste, y las lecturas posteriores fuera de rango la
 * actualizan en lugar de crear una nueva (CP-08).
 */
final class Alerta
{
    private function __construct(
        public readonly int $estanqueId,
        public readonly Parametro $parametro,
        private Severidad $severidad,
        private EstadoAlerta $estado,
        public readonly float $valorDetectado,
        public readonly float $umbralViolado,
        private float $ultimoValor,
        public readonly DateTimeImmutable $generadaEn,
        private DateTimeImmutable $actualizadaEn,
        public readonly ?int $id = null,
        private ?int $cerradaPor = null,
    ) {}

    public static function abrir(
        int $estanqueId,
        Parametro $parametro,
        Severidad $severidad,
        float $valorDetectado,
        float $umbralViolado,
        DateTimeImmutable $generadaEn,
        ?int $id = null,
    ): self {
        return new self(
            estanqueId: $estanqueId,
            parametro: $parametro,
            severidad: $severidad,
            estado: EstadoAlerta::ABIERTA,
            valorDetectado: $valorDetectado,
            umbralViolado: $umbralViolado,
            ultimoValor: $valorDetectado,
            generadaEn: $generadaEn,
            actualizadaEn: $generadaEn,
            id: $id,
        );
    }

    /** Reconstrucción desde la persistencia (adaptador secundario). */
    public static function reconstituir(
        int $id,
        int $estanqueId,
        Parametro $parametro,
        Severidad $severidad,
        EstadoAlerta $estado,
        float $valorDetectado,
        float $umbralViolado,
        float $ultimoValor,
        DateTimeImmutable $generadaEn,
        DateTimeImmutable $actualizadaEn,
        ?int $cerradaPor = null,
    ): self {
        return new self(
            estanqueId: $estanqueId,
            parametro: $parametro,
            severidad: $severidad,
            estado: $estado,
            valorDetectado: $valorDetectado,
            umbralViolado: $umbralViolado,
            ultimoValor: $ultimoValor,
            generadaEn: $generadaEn,
            actualizadaEn: $actualizadaEn,
            id: $id,
            cerradaPor: $cerradaPor,
        );
    }

    public function severidad(): Severidad
    {
        return $this->severidad;
    }

    public function estado(): EstadoAlerta
    {
        return $this->estado;
    }

    public function ultimoValor(): float
    {
        return $this->ultimoValor;
    }

    public function actualizadaEn(): DateTimeImmutable
    {
        return $this->actualizadaEn;
    }

    public function cerradaPor(): ?int
    {
        return $this->cerradaPor;
    }

    public function estaAbierta(): bool
    {
        return $this->estado === EstadoAlerta::ABIERTA;
    }

    /**
     * CP-08: una lectura posterior fuera de rango del mismo parámetro y
     * estanque actualiza el último valor en lugar de abrir una segunda alerta.
     *
     * Devuelve true cuando la severidad escaló de advertencia a crítica, porque
     * ese es el único caso en que hay que volver a notificar: el operador ya
     * fue avisado de la advertencia, pero que la condición se agrave es
     * información nueva.
     */
    public function registrarNuevoValor(
        float $valor,
        Severidad $severidad,
        DateTimeImmutable $momento,
    ): bool {
        $this->ultimoValor = $valor;
        $this->actualizadaEn = $momento;

        if ($severidad->esMasGraveQue($this->severidad)) {
            $this->severidad = $severidad;

            return true;
        }

        return false;
    }

    /** HU-05: el registro de la atención cierra la alerta. */
    public function atender(int $usuarioId, DateTimeImmutable $momento): void
    {
        if ($this->estado === EstadoAlerta::ATENDIDA) {
            throw new AlertaYaAtendida($this->id ?? 0);
        }

        $this->estado = EstadoAlerta::ATENDIDA;
        $this->cerradaPor = $usuarioId;
        $this->actualizadaEn = $momento;
    }
}
