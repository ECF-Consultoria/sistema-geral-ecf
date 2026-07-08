<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Template NPS configurável — Phase 68 v15.0.
 *
 * Cada template define UM conjunto de perguntas/opções que o admin monta no
 * CRUD (Phase 70) e o `NpsTemplateService::resolveForCompany` (Phase 69)
 * escolhe qual template aplica a cada empresa, na seguinte precedência:
 *
 *   1. WHERE active=true AND EXISTS(pivot serviço) ORDER BY priority DESC
 *   2. Fallback: template com `is_default=true` (unique parcial garante 1 só)
 *
 * O seed "NPS Padrão" (Plan 68-03) é o `is_default=true` e cobre empresas sem
 * serviço aplicável — imprescindível para o disparo mensal automatizado
 * (`nps:disparar-mensal`, NPS-B-04) nunca ficar sem template.
 *
 * Referências:
 *  - .planning/research/v15-nps-templates-schema.md §4 (priority + is_default)
 *  - .planning/research/v15-nps-templates-schema.md §1 (snapshot per-row)
 *  - database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php
 *
 * @property string $nome
 * @property string|null $descricao
 * @property bool $active
 * @property bool $is_default
 * @property int $priority
 * @property bool $envio_automatico_mensal
 */
class NpsTemplate extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'nps_templates';

    protected $fillable = [
        'nome',
        'descricao',
        'active',
        'is_default',
        'priority',
        'envio_automatico_mensal',
    ];

    protected $casts = [
        'active'                  => 'bool',
        'is_default'              => 'bool',
        'envio_automatico_mensal' => 'bool',
        'priority'                => 'int',
    ];

    /**
     * Auditoria de mudanças no template — usada pela tela admin (Phase 70).
     * Segue padrão de `NpsSurvey::getActivitylogOptions()`.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nome', 'active', 'is_default', 'priority', 'envio_automatico_mensal'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => 'Template NPS criado',
                'updated' => 'Template NPS atualizado',
                'deleted' => 'Template NPS excluído',
                default   => $eventName,
            });
    }

    /**
     * Perguntas do template ordenadas pela coluna `ordem` (renderização
     * consistente no form público e no preview admin).
     */
    public function questions(): HasMany
    {
        return $this->hasMany(NpsTemplateQuestion::class, 'template_id')
            ->orderBy('ordem')
            ->orderBy('id');
    }

    /**
     * Serviços que este template atende — pivot `nps_template_service_scopes`.
     *
     * Empresa com [Gestão, Mentoria] recebe o template de MAIOR priority entre
     * os que possuem pelo menos 1 serviço em comum (research §4). Se nenhum
     * template bate, cai no `is_default=true`.
     */
    public function servicos(): BelongsToMany
    {
        return $this->belongsToMany(
            Servico::class,
            'nps_template_service_scopes',
            'template_id',
            'servico_id'
        )->withTimestamps();
    }

    /**
     * Alias de `servicos()` — nome usado no research §4 e no
     * `NpsTemplateService::resolveForCompany` (Phase 69). Ambos apontam para
     * o mesmo pivot; expor os dois nomes evita re-refatoração posterior.
     */
    public function serviceScopes(): BelongsToMany
    {
        return $this->servicos();
    }

    /**
     * Surveys emitidos com este template. FK `template_id` é nullOnDelete —
     * apagar o template não corrompe histórico, pois o snapshot per-row em
     * `nps_response_answers` é a fonte de verdade.
     */
    public function surveys(): HasMany
    {
        return $this->hasMany(NpsSurvey::class, 'template_id');
    }

    /**
     * Scope: apenas templates ativos.
     *
     * Uso típico: `NpsTemplate::active()->orderByDesc('priority')->get()`
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope: apenas o template default (deve retornar 1 row — unique parcial
     * em `is_default` garante).
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
