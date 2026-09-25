<?php

declare(strict_types=1);

namespace Sippt\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Vigila la regla dura de la arquitectura hexagonal: el núcleo de dominio no
 * depende de ningún framework.
 *
 * Es una prueba sobre el código fuente y no sobre el comportamiento, lo cual es
 * inusual, pero la regla se viola por descuido —un `use` añadido para resolver
 * un problema puntual— y no por decisión. Una revisión humana la deja pasar; un
 * aserto no. Si esta prueba falla, la política ReglaUmbral ha dejado de ser
 * comprobable sin base de datos ni broker, que es justo lo que el diseño busca
 * preservar.
 */
final class PurezaDelNucleoTest extends TestCase
{
    private const PROHIBIDOS = [
        'Illuminate\\',
        'Laravel\\',
        'Eloquent',
        'PDO',
        'PhpMqtt\\',
        'PhpAmqpLib\\',
        'Symfony\\',
        'GuzzleHttp\\',
    ];

    /** @return list<string> */
    private function archivosPhpDe(string $directorio): array
    {
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directorio, \FilesystemIterator::SKIP_DOTS)
        );

        $archivos = [];

        foreach ($iterador as $archivo) {
            if ($archivo instanceof \SplFileInfo && $archivo->getExtension() === 'php') {
                $archivos[] = $archivo->getPathname();
            }
        }

        sort($archivos);

        return $archivos;
    }

    #[Test]
    #[TestDox('src/Domain no importa nada de Laravel, Eloquent, MQTT ni la base de datos')]
    public function el_dominio_no_depende_de_infraestructura(): void
    {
        $archivos = $this->archivosPhpDe(__DIR__.'/../../src/Domain');

        $this->assertNotEmpty($archivos, 'No se encontraron archivos en src/Domain');

        foreach ($archivos as $archivo) {
            $contenido = file_get_contents($archivo);
            $this->assertIsString($contenido);

            preg_match_all('/^use\s+([^;]+);/m', $contenido, $coincidencias);

            foreach ($coincidencias[1] as $importado) {
                foreach (self::PROHIBIDOS as $prohibido) {
                    $this->assertStringNotContainsString(
                        $prohibido,
                        $importado,
                        sprintf(
                            'El nucleo debe permanecer puro: %s importa %s',
                            basename($archivo),
                            trim($importado),
                        ),
                    );
                }
            }
        }
    }

    #[Test]
    #[TestDox('La capa de aplicacion tampoco depende de infraestructura')]
    public function la_aplicacion_no_depende_de_infraestructura(): void
    {
        $archivos = $this->archivosPhpDe(__DIR__.'/../../src/Application');

        $this->assertNotEmpty($archivos);

        foreach ($archivos as $archivo) {
            $contenido = file_get_contents($archivo);
            $this->assertIsString($contenido);

            preg_match_all('/^use\s+([^;]+);/m', $contenido, $coincidencias);

            foreach ($coincidencias[1] as $importado) {
                foreach (self::PROHIBIDOS as $prohibido) {
                    $this->assertStringNotContainsString(
                        $prohibido,
                        $importado,
                        sprintf(
                            'La aplicacion coordina el dominio y los puertos: %s importa %s',
                            basename($archivo),
                            trim($importado),
                        ),
                    );
                }
            }
        }
    }

    #[Test]
    #[TestDox('Los puertos son interfaces, no clases concretas')]
    public function los_puertos_son_interfaces(): void
    {
        $archivos = $this->archivosPhpDe(__DIR__.'/../../src/Application/Puertos');

        $this->assertNotEmpty($archivos);

        foreach ($archivos as $archivo) {
            $contenido = file_get_contents($archivo);
            $this->assertIsString($contenido);

            $this->assertMatchesRegularExpression(
                '/^interface\s+\w+/m',
                $contenido,
                basename($archivo).' debe declarar una interfaz',
            );
        }
    }
}
