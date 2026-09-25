<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Persistence\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $codigo_nodo
 * @property int $estanque_id
 * @property string $parametro
 * @property string|null $modelo
 * @property float $rango_fisico_min
 * @property float $rango_fisico_max
 * @property Carbon|null $calibrado_en
 * @property bool $activo
 */
final class SensorModel extends Model
{
    protected $table = 'sensor';

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
        'codigo_nodo', 'estanque_id', 'parametro', 'modelo',
        'rango_fisico_min', 'rango_fisico_max', 'calibrado_en', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'rango_fisico_min' => 'float',
            'rango_fisico_max' => 'float',
            'activo' => 'boolean',
            'calibrado_en' => 'datetime',
        ];
    }
}
