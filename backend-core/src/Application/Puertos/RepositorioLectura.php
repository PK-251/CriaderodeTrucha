<?php

declare(strict_types=1);

namespace Sippt\Application\Puertos;

use Sippt\Domain\Lectura;

interface RepositorioLectura
{
    /**
     * Persiste la lectura y devuelve true si era nueva, false si ya existía.
     *
     * La distinción es lo que hace posible CP-04: cuando el gateway reenvía su
     * búfer tras una caída de enlace, las lecturas repetidas deben absorberse
     * en silencio —no son un error— pero el servicio de ingesta necesita saber
     * cuántas se insertaron realmente para informar sin pérdidas ni duplicados.
     */
    public function guardarSiNoExiste(Lectura $lectura): bool;

    public function ultimaValidaDeSensor(int $sensorId): ?Lectura;
}
