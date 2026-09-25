<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Persistence\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $estanque_id
 * @property string $parametro
 * @property string $severidad
 * @property string $estado
 * @property float $valor_detectado
 * @property float $umbral_violado
 * @property float $ultimo_valor
 * @property Carbon $generada_en
 * @property Carbon $actualizada_en
 * @property int|null $cerrada_por
 */
final class AlertaModel extends Model
{
    protected $table = 'alerta';

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
        'estanque_id', 'parametro', 'severidad', 'estado',
        'valor_detectado', 'umbral_violado', 'ultimo_valor',
        'generada_en', 'actualizada_en', 'cerrada_por',
    ];

    protected function casts(): array
    {
        return [
            'valor_detectado' => 'float',
            'umbral_violado' => 'float',
            'ultimo_valor' => 'float',
            'generada_en' => 'datetime',
            'actualizada_en' => 'datetime',
        ];
    }
}
