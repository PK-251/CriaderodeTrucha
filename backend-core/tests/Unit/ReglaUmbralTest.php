<?php

declare(strict_types=1);

namespace Sippt\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Sippt\Domain\CalidadLectura;
use Sippt\Domain\Lectura;
use Sippt\Domain\Parametro;
use Sippt\Domain\ReglaUmbral;
use Sippt\Domain\Severidad;
use Sippt\Domain\Umbral;

/**
 * Pruebas de la política de evaluación de riesgo.
 *
 * Se ejecutan sin base de datos, sin broker y sin framework: ReglaUmbral es
 * PHP plano y esa es exactamente la propiedad que estas pruebas explotan.
 */
final class ReglaUmbralTest extends TestCase
{
    private ReglaUmbral $regla;

    protected function setUp(): void
    {
        $this->regla = new ReglaUmbral;
    }

    /**
     * Umbral del estanque EST-03 en el informe: oxígeno disuelto con mínimo
     * aceptable 5.5 mg/L y margen crítico 0.5, de modo que el límite crítico
     * es exactamente 5.0.
     */
    private function umbralOxigeno(): Umbral
    {
        return new Umbral(
            estanqueId: 3,
            parametro: Parametro::OD_MGL,
            minAceptable: 5.5,
            maxAceptable: 12.0,
            severidadCritica: 0.5,
        );
    }

    private function lectura(float $valor, CalidadLectura $calidad = CalidadLectura::VALIDA): Lectura
    {
        return new Lectura(
            sensorId: 7,
            medidoEn: new DateTimeImmutable('2026-09-18T17:42:10-05:00'),
            valor: $valor,
            calidad: $calidad,
        );
    }

    #[Test]
    #[TestDox('CP-06: OD 5.1 mg/L con minimo 5.5 y critico 5.0 genera advertencia')]
    public function cp06_oxigeno_bajo_el_minimo_pero_sobre_el_critico_es_advertencia(): void
    {
        $resultado = $this->regla->evaluar($this->lectura(5.1), $this->umbralOxigeno());

        $this->assertTrue($resultado->hayRiesgo);
        $this->assertSame(Severidad::ADVERTENCIA, $resultado->severidad);
        $this->assertSame(5.5, $resultado->umbralViolado);
        $this->assertFalse($resultado->esCritica());
    }

    #[Test]
    #[TestDox('CP-07: OD 4.2 mg/L por debajo del critico genera alerta critica')]
    public function cp07_oxigeno_bajo_el_critico_es_critica(): void
    {
        $resultado = $this->regla->evaluar($this->lectura(4.2), $this->umbralOxigeno());

        $this->assertTrue($resultado->hayRiesgo);
        $this->assertSame(Severidad::CRITICA, $resultado->severidad);
        $this->assertTrue($resultado->esCritica());
        $this->assertTrue($resultado->severidad->exigeNotificacionExterna());
    }

    #[Test]
    #[TestDox('El valor exacto del limite critico ya es critico, no advertencia')]
    public function el_limite_critico_exacto_es_critico(): void
    {
        // 5.5 − 0.5 no produce exactamente 5.0 en coma flotante binaria. Sin la
        // tolerancia de ReglaUmbral, esta lectura se clasificaría como
        // advertencia por un error de representación de 10⁻¹⁶, y el operador
        // no recibiría el correo que la severidad crítica exige.
        $resultado = $this->regla->evaluar($this->lectura(5.0), $this->umbralOxigeno());

        $this->assertSame(Severidad::CRITICA, $resultado->severidad);
    }

    #[Test]
    #[TestDox('El valor exacto del minimo aceptable no genera alerta')]
    public function el_minimo_aceptable_exacto_no_es_riesgo(): void
    {
        $resultado = $this->regla->evaluar($this->lectura(5.5), $this->umbralOxigeno());

        $this->assertFalse($resultado->hayRiesgo);
        $this->assertNull($resultado->severidad);
        $this->assertNull($resultado->umbralViolado);
    }

    #[Test]
    #[TestDox('CP-05: una lectura descartada nunca genera alerta')]
    public function cp05_lectura_descartada_no_genera_alerta(): void
    {
        // Un pH de 14.8 está fuera del rango del electrodo: delata un sensor
        // averiado, no una condición del agua.
        $umbralPh = new Umbral(
            estanqueId: 3,
            parametro: Parametro::PH,
            minAceptable: 6.5,
            maxAceptable: 8.5,
            severidadCritica: 0.7,
        );

        $resultado = $this->regla->evaluar(
            $this->lectura(14.8, CalidadLectura::DESCARTADA),
            $umbralPh,
        );

        $this->assertFalse($resultado->hayRiesgo);
    }

    #[Test]
    #[TestDox('Una lectura dudosa tampoco genera alerta')]
    public function lectura_dudosa_no_genera_alerta(): void
    {
        $resultado = $this->regla->evaluar(
            $this->lectura(2.0, CalidadLectura::DUDOSA),
            $this->umbralOxigeno(),
        );

        $this->assertFalse($resultado->hayRiesgo);
    }

    #[Test]
    #[TestDox('Riesgo por exceso: el pH alto se evalua contra el maximo aceptable')]
    public function riesgo_por_exceso_usa_el_maximo_como_umbral_violado(): void
    {
        $umbralPh = new Umbral(
            estanqueId: 3,
            parametro: Parametro::PH,
            minAceptable: 6.5,
            maxAceptable: 8.5,
            severidadCritica: 0.7,
        );

        $advertencia = $this->regla->evaluar($this->lectura(8.9), $umbralPh);
        $this->assertSame(Severidad::ADVERTENCIA, $advertencia->severidad);
        $this->assertSame(8.5, $advertencia->umbralViolado);

        // 8.5 + 0.7 = 9.2 es el límite crítico superior.
        $critica = $this->regla->evaluar($this->lectura(9.3), $umbralPh);
        $this->assertSame(Severidad::CRITICA, $critica->severidad);
    }

    /**
     * @return array<string, array{float, bool, Severidad|null}>
     */
    public static function valoresDeOxigeno(): array
    {
        return [
            'muy por debajo del critico' => [3.0, true, Severidad::CRITICA],
            'justo bajo el critico' => [4.99, true, Severidad::CRITICA],
            'en el limite critico' => [5.0, true, Severidad::CRITICA],
            'entre critico y minimo' => [5.2, true, Severidad::ADVERTENCIA],
            'justo bajo el minimo' => [5.49, true, Severidad::ADVERTENCIA],
            'en el minimo aceptable' => [5.5, false, null],
            'dentro del rango' => [8.0, false, null],
            'en el maximo aceptable' => [12.0, false, null],
            'sobre el maximo' => [12.3, true, Severidad::ADVERTENCIA],
            'en el critico superior' => [12.5, true, Severidad::CRITICA],
            'muy sobre el critico' => [15.0, true, Severidad::CRITICA],
        ];
    }

    #[Test]
    #[DataProvider('valoresDeOxigeno')]
    #[TestDox('Barrido del rango de oxigeno disuelto')]
    public function barrido_del_rango(float $valor, bool $esperaRiesgo, ?Severidad $esperada): void
    {
        $resultado = $this->regla->evaluar($this->lectura($valor), $this->umbralOxigeno());

        $this->assertSame($esperaRiesgo, $resultado->hayRiesgo, "Valor {$valor}");
        $this->assertSame($esperada, $resultado->severidad, "Valor {$valor}");
    }

    #[Test]
    #[TestDox('Un margen critico de cero hace que toda violacion sea critica')]
    public function margen_cero_convierte_toda_violacion_en_critica(): void
    {
        $umbral = new Umbral(
            estanqueId: 1,
            parametro: Parametro::TEMP_C,
            minAceptable: 9.0,
            maxAceptable: 16.0,
            severidadCritica: 0.0,
        );

        $this->assertSame(
            Severidad::CRITICA,
            $this->regla->evaluar($this->lectura(8.9), $umbral)->severidad,
        );
        $this->assertSame(
            Severidad::CRITICA,
            $this->regla->evaluar($this->lectura(16.1), $umbral)->severidad,
        );
    }
}
