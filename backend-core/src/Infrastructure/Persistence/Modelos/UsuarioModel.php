<?php

declare(strict_types=1);

namespace Sippt\Infrastructure\Persistence\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $nombre
 * @property string $email
 * @property string $password_hash
 * @property string $rol
 * @property bool $activo
 */
final class UsuarioModel extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'usuario';

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

    protected $fillable = ['nombre', 'email', 'password_hash', 'rol', 'activo'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'creado_en' => 'datetime',
        ];
    }

    /** Laravel espera getAuthPassword(); la columna se llama password_hash. */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /** @return class-string<Model> */
    public static function modelo(): string
    {
        return self::class;
    }
}
