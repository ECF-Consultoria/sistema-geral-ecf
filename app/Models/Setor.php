<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Setor organizacional. Substitui a string descritiva users.setor.
 *
 * Cada setor agrupa usuários, define quais telas eles veem (via setor_permissoes)
 * e tem seus próprios cargos e líderes. Setores `is_system=true` (Administração)
 * são protegidos contra delete e perda de permissões essenciais.
 */
class Setor extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $table = 'setores';

    protected $fillable = [
        'nome', 'slug', 'descricao', 'active', 'is_system', 'created_by',
    ];

    protected $casts = [
        'active'    => 'boolean',
        'is_system' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nome', 'slug', 'descricao', 'active', 'is_system'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $event) => match ($event) {
                'created' => 'Setor criado',
                'updated' => 'Setor atualizado',
                'deleted' => 'Setor excluído',
                default   => $event,
            });
    }

    protected static function booted(): void
    {
        // Gera slug a partir do nome se não vier explícito.
        static::saving(function (Setor $setor) {
            if (empty($setor->slug) && !empty($setor->nome)) {
                $setor->slug = Str::slug($setor->nome);
            }
        });
    }

    public function cargos(): HasMany
    {
        return $this->hasMany(Cargo::class)->orderBy('ordem')->orderBy('nome');
    }

    public function permissoes(): HasMany
    {
        return $this->hasMany(SetorPermissao::class);
    }

    public function metas(): HasMany
    {
        return $this->hasMany(SetorGoal::class);
    }

    /** Membros (via pivot user_setores). */
    public function membros(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_setores')
            ->withPivot('cargo_id', 'is_principal', 'assigned_at')
            ->withTimestamps();
    }

    /** Líderes (via pivot setor_lideres). */
    public function lideres(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'setor_lideres')
            ->withPivot('assigned_by', 'assigned_at')
            ->withTimestamps();
    }

    /** Array das permission keys habilitadas neste setor. */
    public function permissionKeys(): array
    {
        return $this->permissoes()->pluck('permission_key')->all();
    }

    public function hasPermission(string $key): bool
    {
        return $this->permissoes()->where('permission_key', $key)->exists();
    }

    /** True se é "Administração" ou similar — não pode ser deletado. */
    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }
}
