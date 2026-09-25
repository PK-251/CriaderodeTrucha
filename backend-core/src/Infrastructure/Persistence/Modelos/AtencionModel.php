<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Persistence\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $alerta_id
 * @property int $usuario_id
 * @property string $accion
 * @property string|null $observacion
 * @property Carbon $registrada_en
 */
final class AtencionModel extends Model
{
    protected $table = 'atencion_alerta';

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

    protected $fillable = ['alerta_id', 'usuario_id', 'accion', 'observacion', 'registrada_en'];

    protected function casts(): array
    {
        return ['registrada_en' => 'datetime'];
    }
}
