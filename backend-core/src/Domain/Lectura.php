<?php

declare(strict_types=1);

namespace Sippt\Domain;

use DateTimeImmutable;

/**
 * Medición de un sensor en un instante (RF-03).
 *
 * `medidoEn` proviene del nodo, nunca del servidor: cuando el gateway reenvía
 * su búfer tras una caída de enlace, la lectura debe conservar el instante real
 * de la medición (RNF-02). `recibidoEn` registra cuándo la persistió el núcleo;
 * la diferencia entre ambas revela cuánto estuvo retenida.
 */
final readonly class Lectura
{
    public function __construct(
        public int $sensorId,
        public DateTimeImmutable $medidoEn,
        public float $valor,
        public CalidadLectura $calidad,
        public ?DateTimeImmutable $recibidoEn = null,
    ) {}

    /**
     * Identidad natural de la lectura. Coincide con la clave primaria
     * (sensor_id, medido_en) y es lo que permite descartar duplicados durante
     * el reenvío del búfer (CP-04).
     */
    public function clave(): string
    {
        return $this->sensorId.'@'.$this->medidoEn->format(DATE_ATOM);
    }

    public function puedeGenerarAlerta(): bool
    {
        return $this->calidad->puedeGenerarAlerta();
    }

    /** Antigüedad de la medición en segundos respecto a un instante dado. */
    public function antiguedadEnSegundos(DateTimeImmutable $ahora): int
    {
        return $ahora->getTimestamp() - $this->medidoEn->getTimestamp();
    }
}
