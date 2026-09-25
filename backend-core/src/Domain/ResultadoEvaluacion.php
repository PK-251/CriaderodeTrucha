<?php

declare(strict_types=1);

namespace Sippt\Domain;

/**
 * Veredicto de ReglaUmbral sobre una lectura.
 *
 * Se modela como objeto y no como un simple `?Severidad` porque el caso de uso
 * necesita además saber QUÉ límite se violó: ese valor se guarda en la alerta
 * y es lo que permite al operador entender por qué se le avisó.
 */
final readonly class ResultadoEvaluacion
{
    private function __construct(
        public bool $hayRiesgo,
        public ?Severidad $severidad,
        public ?float $umbralViolado,
    ) {}

    public static function sinRiesgo(): self
    {
        return new self(false, null, null);
    }

    public static function conRiesgo(Severidad $severidad, float $umbralViolado): self
    {
        return new self(true, $severidad, $umbralViolado);
    }

    public function esCritica(): bool
    {
        return $this->severidad === Severidad::CRITICA;
    }
}
