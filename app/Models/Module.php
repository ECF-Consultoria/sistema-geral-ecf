<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Registro explícito de um módulo do sistema (Module Registry — Fase 97).
 *
 * Representa o estado MUTÁVEL em runtime de um módulo cuja ESTRUTURA (nome,
 * grupo, rota, permission_key, estágio default) é definida no catálogo
 * estático `App\Support\Modules` (fonte de verdade versionada). O comando
 * `modules:sync` (Plano 04) hidrata esta tabela a partir do catálogo via
 * `updateOrCreate` por `key` — o fluxo é sempre catálogo (código) → tabela
 * (runtime), nunca o contrário.
 *
 * `stage` é o eixo ORTOGONAL ao RBAC existente: "está maduro o suficiente
 * pra aparecer?" em cima de "pode acessar?" (permission/role). O service
 * `App\Services\ModuleRegistry` lê esta tabela para resolver `key => stage`
 * de forma cacheada; qualquer `saved`/`deleted`/`restored` aqui invalida
 * aquele cache (ver `booted()` abaixo) para o próximo request pegar o
 * estágio novo.
 *
 * `LogsActivity` audita mudanças de ciclo de vida (`stage`, `prioridade`,
 * `progresso`, `bloqueado`, `ordem`, `promovido_prod_em`) em `activity_log`
 * (log_name `module`) — é essa timeline que o board da Fase 100 vai ler;
 * não existe (nem deve existir) tabela dedicada de transições.
 *
 * Nesta fase (97) o model é backend invisível: nenhuma tela/rota/menu o
 * consome ainda.
 */
class Module extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $table = 'modules';

    protected $fillable = [
        'key',
        'name',
        'grupo',
        'stage',
        'ordem',
        'prioridade',
        'progresso',
        'bloqueado',
        'bloqueio_motivo',
        'owner_id',
        'route_prefix',
        'permission_key',
        'notas',
        'metadata',
        'promovido_prod_em',
    ];

    protected $casts = [
        'bloqueado'         => 'boolean',
        'metadata'          => 'array',
        'ordem'             => 'int',
        'progresso'         => 'int',
        'promovido_prod_em' => 'datetime',
    ];

    /**
     * Auditoria da timeline de ciclo de vida do módulo (log_name `module`).
     * Loga só as colunas relevantes de transição — é o que o board da
     * Fase 100 vai ler de `activity_log` (não existe tabela de transições
     * dedicada, ver CONTEXT.md §"Modelo de dados (locked)").
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['stage', 'prioridade', 'progresso', 'bloqueado', 'ordem', 'promovido_prod_em'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('module')
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Módulo criado',
                'updated' => 'Módulo atualizado',
                'deleted' => 'Módulo excluído',
                default   => $event,
            });
    }

    protected static function booted(): void
    {
        // Mutação em runtime (criar/editar/excluir/restaurar) invalida o mapa
        // key=>stage cacheado do ModuleRegistry — o próximo request pega o
        // estágio novo em vez de servir o valor stale do cache.
        static::saved(function (Module $module) {
            app(\App\Services\ModuleRegistry::class)->flush();
        });

        static::deleted(function (Module $module) {
            app(\App\Services\ModuleRegistry::class)->flush();
        });

        static::restored(function (Module $module) {
            app(\App\Services\ModuleRegistry::class)->flush();
        });
    }

    /** Dono do módulo (nullable — FK owner_id com nullOnDelete). */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** Módulos dos quais este módulo depende. */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            Module::class,
            'module_dependencies',
            'module_id',
            'depends_on_module_id'
        );
    }

    /** Módulos que dependem deste módulo (inverso de `dependencies()`). */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            Module::class,
            'module_dependencies',
            'depends_on_module_id',
            'module_id'
        );
    }

    /** Usuários (devs) que favoritaram/pinaram/optaram por beta deste módulo. */
    public function favoritadoPor(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'module_user')
            ->withPivot('favorito', 'pin_ordem', 'beta_optin')
            ->withTimestamps();
    }
}
