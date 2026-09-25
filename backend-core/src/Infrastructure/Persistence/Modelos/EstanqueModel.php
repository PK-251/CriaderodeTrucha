<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Persistence\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $codigo
 * @property float $volumen_m3
 * @property float $biomasa_kg
 * @property string $etapa
 * @property bool $activo
 * @property int|null $creado_por
 * @property int|null $actualizado_por
 * @property Carbon $creado_en
 * @property Carbon $actualizado_en
 */
final class EstanqueModel extends Model
{
    protected $table = 'estanque';

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
        'codigo', 'volumen_m3', 'biomasa_kg', 'etapa', 'activo',
        'creado_por', 'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'volumen_m3' => 'float',
            'biomasa_kg' => 'float',
            'activo' => 'boolean',
            'creado_en' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    /** @return HasMany<UmbralModel, $this> */
    public function umbrales(): HasMany
    {
        return $this->hasMany(UmbralModel::class, 'estanque_id');
    }

    /** @return HasMany<SensorModel, $this> */
    public function sensores(): HasMany
    {
        return $this->hasMany(SensorModel::class, 'estanque_id');
    }
}
