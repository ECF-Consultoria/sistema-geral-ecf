<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OnboardingTemplate — uma VERSÃO de template de onboarding para um serviço.
 *
 * Versionamento imutável por linha nova (D-07/SC-09): publicar uma versão N+1
 * nunca faz UPDATE numa versão já publicada — o Plano 07 insere uma linha nova
 * com `versao = anterior + 1`. Só uma linha `ativo=true` pode existir por
 * `servico_id`, garantido pelo banco via índice único parcial composto
 * (ver database/migrations/2026_08_11_120000_create_onboarding_templates_tables.php).
 *
 * Onboardings vivos ficam presos ao `template_id` em que nasceram (FK
 * restrictOnDelete em `onboardings.template_id`) — a troca de versão ativa
 * nunca afeta quem já está em andamento.
 */
class OnboardingTemplate extends Model
{
    protected $table = 'onboarding_templates';

    protected $fillable = [
        'servico_id',
        'versao',
        'ativo',
        'publicado_em',
        'publicado_por',
    ];

    protected $casts = [
        'ativo'        => 'boolean',
        'publicado_em' => 'datetime',
    ];

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }

    public function publicadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicado_por');
    }

    /** Passos do template, na ordem de exibição. */
    public function passos(): HasMany
    {
        return $this->hasMany(TemplatePasso::class, 'template_id')->orderBy('ordem');
    }

    /** Scope: apenas a versão ativa (vigente) por serviço. */
    public function scopeAtivo($query)
    {
        return $query->where('ativo', true);
    }
}
