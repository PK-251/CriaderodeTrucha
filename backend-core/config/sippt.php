<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuración del PMV
|--------------------------------------------------------------------------
|
| Las variables de entorno se leen AQUI y no en los proveedores de servicio.
| La razon no es de estilo: cuando la configuracion se cachea para produccion
| (php artisan config:cache), env() devuelve null fuera del directorio config,
| y los notificadores quedarian apuntando a un host vacio sin que nada falle
| de forma visible.
|
*/

return [

    // Broker al que se publica la alerta para que el tablero la reciba en vivo.
    'mqtt' => [
        'host' => env('MQTT_HOST', 'mosquitto'),
        'puerto' => (int) env('MQTT_PORT', 1883),
        'topico_alertas' => env('MQTT_TOPIC_ALERTAS', 'piscigranja/alertas'),
    ],

    // Destinatario de las alertas de severidad critica (HU-04).
    'alertas' => [
        'destino_critica' => env('ALERTA_CRITICA_DESTINO', 'tecnico@sippt.local'),
    ],

    // Minutos sin reportar tras los cuales un estanque se marca como
    // «sin comunicacion» en el tablero (HU-03).
    'sin_comunicacion_min' => (int) env('SIN_COMUNICACION_MIN', 15),
];
