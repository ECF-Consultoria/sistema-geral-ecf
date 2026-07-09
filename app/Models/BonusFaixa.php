<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Faixa de bônus do módulo Desempenho — Phase 74 (D-15, D-16).
 *
 * Cada row representa 1 faixa da régua de bonificação da equipe Performance,
 * com limites inclusivos `nota_min`/`nota_max` em [0.00, 5.00]. As 4 faixas
 * canônicas iniciais (sem_bonus, básico, intermediário, máximo) são semeadas
 * pela migration `2026_07_09_140003_seed_bonus_faixas_iniciais.php` (D-16).
 *
 * O admin edita valores/nomes/descricao via UI de configuração
 * (`Desempenho/Configuracao.jsx`, Plans 74-05/74-07). A trait `LogsActivity`
 * registra qualquer alteração para auditoria (log name `bonus_faixa`).
 *
 * Método estático `classificar(float $nota): ?self` faz o lookup direto pela
 * régua ativa e é reutilizado pelo `DesempenhoScoreService::classificarFaixa`
 * (Plan 74-03). NOTA IMPORTANTE: `classificar()` NÃO aplica a regra DESEMP-08
 * ("2 meses consecutivos intermediário → máximo"). Essa regra fica no Service
 * para manter o Model puro (apenas semântica de lookup, sem estado histórico).
 *
 * Referências:
 *  - `.planning/phases/74-.../74-CONTEXT.md` §D-14, D-15, D-16
 *  - `.planning/phases/74-.../74-SPEC.md` DESEMP-07 (config editável), DESEMP-08 (promoção)
 *
 * @property int    $id
 * @property string $slug
 * @property string $nome
 * @property string|null $descricao
 * @property string $nota_min       (decimal:2 — Laravel casts para string)
 * @property string $nota_max       (decimal:2)
 * @property int    $ordem
 * @property bool   $ativo
 */
class BonusFaixa extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'bonus_faixas';

    protected $fillable = [
        'slug',
        'nome',
        'descricao',
        'nota_min',
        'nota_max',
        'ordem',
        'ativo',
    ];

    protected $casts = [
        'nota_min' => 'decimal:2',
        'nota_max' => 'decimal:2',
        'ordem'    => 'int',
        'ativo'    => 'bool',
    ];

    /**
     * Auditoria de mudanças na régua de bônus.
     * Padrão seguindo `NpsTemplate::getActivitylogOptions()` (mesma convenção pt-BR).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('bonus_faixa')
            ->setDescriptionForEvent(
                fn (string $event) => "Faixa de bônus {$this->slug} foi {$event}"
            );
    }

    /**
     * Scope: apenas faixas ativas.
     * Uso: `BonusFaixa::ativas()->ordenadas()->get()`.
     */
    public function scopeAtivas($query)
    {
        return $query->where('ativo', true);
    }

    /**
     * Scope: ordena pela coluna `ordem` ascendente (padrão UI + Manual).
     */
    public function scopeOrdenadas($query)
    {
        return $query->orderBy('ordem');
    }

    /**
     * Classifica uma nota na régua ATIVA — retorna a primeira faixa cujo
     * intervalo `[nota_min, nota_max]` contém a nota (inclusivo em ambos os
     * lados). Ordenação por `ordem` ASC garante que faixas com sobreposição
     * (caso o admin as introduza acidentalmente) retornem a de menor ordem.
     *
     * IMPORTANTE: não aplica a regra DESEMP-08 (promoção por 2 meses
     * consecutivos). Essa regra depende de histórico do snapshot mensal e é
     * responsabilidade do `DesempenhoScoreService::classificarFaixa` no
     * Plan 74-03.
     *
     * @param  float  $nota  nota final calculada (esperado em [0.00, 5.00])
     * @return self|null     faixa correspondente, ou null se nenhuma cobrir
     */
    public static function classificar(float $nota): ?self
    {
        return self::query()
            ->ativas()
            ->ordenadas()
            ->where('nota_min', '<=', $nota)
            ->where('nota_max', '>=', $nota)
            ->first();
    }
}
