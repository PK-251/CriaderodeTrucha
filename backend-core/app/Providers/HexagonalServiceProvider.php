<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Sippt\Application\Puertos\Notificador;
use Sippt\Application\Puertos\Reloj;
use Sippt\Application\Puertos\RepositorioAlerta;
use Sippt\Application\Puertos\RepositorioAtencion;
use Sippt\Application\Puertos\RepositorioLectura;
use Sippt\Application\Puertos\RepositorioSensor;
use Sippt\Application\Puertos\RepositorioUmbral;
use Sippt\Infrastructure\Notificacion\NotificadorCompuesto;
use Sippt\Infrastructure\Notificacion\NotificadorCorreo;
use Sippt\Infrastructure\Notificacion\NotificadorWebSocket;
use Sippt\Infrastructure\Persistence\RepositorioAlertaEloquent;
use Sippt\Infrastructure\Persistence\RepositorioAtencionEloquent;
use Sippt\Infrastructure\Persistence\RepositorioLecturaEloquent;
use Sippt\Infrastructure\Persistence\RepositorioSensorEloquent;
use Sippt\Infrastructure\Persistence\RepositorioUmbralEloquent;
use Sippt\Infrastructure\Reloj\RelojDelSistema;

/**
 * Único punto donde los puertos del núcleo se atan a sus adaptadores.
 *
 * Esta clase es la frontera de la arquitectura hexagonal: sustituir Eloquent
 * por otra persistencia, o el broker por un servidor WebSocket propio, se
 * resuelve cambiando una línea aquí, sin tocar el dominio ni los casos de uso.
 */
final class HexagonalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RepositorioSensor::class, RepositorioSensorEloquent::class);
        $this->app->bind(RepositorioUmbral::class, RepositorioUmbralEloquent::class);
        $this->app->bind(RepositorioLectura::class, RepositorioLecturaEloquent::class);
        $this->app->bind(RepositorioAlerta::class, RepositorioAlertaEloquent::class);
        $this->app->bind(RepositorioAtencion::class, RepositorioAtencionEloquent::class);

        $this->app->singleton(Reloj::class, fn (): RelojDelSistema => new RelojDelSistema(
            (string) config('app.timezone', 'America/Lima'),
        ));

        $this->app->singleton(Notificador::class, function (): Notificador {
            /** @var LoggerInterface $registro */
            $registro = $this->app->make(LoggerInterface::class);

            /** @var Mailer $correo */
            $correo = $this->app->make(Mailer::class);

            return new NotificadorCompuesto(
                new NotificadorWebSocket(
                    $registro,
                    (string) config('sippt.mqtt.host'),
                    (int) config('sippt.mqtt.puerto'),
                    (string) config('sippt.mqtt.topico_alertas'),
                ),
                new NotificadorCorreo(
                    $correo,
                    $registro,
                    (string) config('sippt.alertas.destino_critica'),
                ),
            );
        });
    }
}
