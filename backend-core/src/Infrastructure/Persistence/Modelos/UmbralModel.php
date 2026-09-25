<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Persistence\Modelos;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $estanque_id
 * @property string $parametro
 * @property float $min_aceptable
 * @property float $max_aceptable
 * @property float $severidad_critica
 */
final class UmbralModel extends Model
{
    protected $table = 'umbral_parametro';

    public $timestamps = false;

    /**
     * Incluye el desplazamiento horario al escribir.
     *
     * El formato por defecto de Eloquent (Y-m-d H:i:s) lo descarta, y entonces
     * PostgreSQL reinterpreta la hora en el huso de su sesion: un instante
     * grabado en UTC se almacenaba cinco horas desplazado. Con columnas
     * TIMESTAMPTZ el desplazamiento no es decorativo, es parte del dato.
     */
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = [
        'estanque_id', 'parametro', 'min_aceptable', 'max_aceptable',
        'severidad_critica', 'creado_por', 'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'min_aceptable' => 'float',
            'max_aceptable' => 'float',
            'severidad_critica' => 'float',
        ];
    }
}
