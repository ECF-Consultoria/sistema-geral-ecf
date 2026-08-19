<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OnboardingInvestimento — o quanto o cliente pretende investir (§13.1), um
 * registro por onboarding.
 *
 * Guarda as TRÊS respostas separadas (disponível, mensal previsto e o pedaço
 * destinado a publicidade) porque o checklist cobra DOIS itens diferentes a
 * partir daqui: "valor de investimento alinhado" e "investimento em
 * publicidade alinhado". Ver o docblock da migration para o resto do porquê.
 *
 * ─── `null` ≠ `0` ──────────────────────────────────────────────────────────
 * Este é o erro clássico deste model: `0.00` é uma resposta DADA — cliente
 * que declara não ter verba de publicidade respondeu a pergunta — e só `null`
 * significa "ninguém perguntou ainda". Nunca testar preenchimento com `empty()`
 * ou `filled()` sobre estes campos; usar {@see self::temInvestimentoPrevisto()}
 * e {@see self::temInvestimentoPublicidade()}, que comparam com `null` e nada
 * mais.
 */
class OnboardingInvestimento extends Model
{
    protected $table = 'onboarding_investimentos';

    protected $fillable = [
        'onboarding_id',
        'investimento_disponivel',
        'investimento_mensal_previsto',
        'investimento_publicidade',
        'observacoes',
        'informado_em',
        'informado_por',
        'informado_canal',
    ];

    protected $casts = [
        'investimento_disponivel'      => 'decimal:2',
        'investimento_mensal_previsto' => 'decimal:2',
        'investimento_publicidade'     => 'decimal:2',
        'informado_em'                 => 'datetime',
    ];

    // ─── Catálogo de `informado_canal` ──────────────────────────────────────
    // Referências ao vocabulário de OnboardingMapeamento de propósito: a
    // distinção "o cliente conferiu sozinho" × "a equipe conferiu por ele numa
    // call" é a MESMA pergunta em todo o módulo, e o literal precisa existir
    // num lugar só. Canal novo entra lá e vale aqui automaticamente.
    public const CANAL_CLIENTE_PORTAL = OnboardingMapeamento::CANAL_CLIENTE_PORTAL;
    public const CANAL_INTERNO_CALL = OnboardingMapeamento::CANAL_INTERNO_CALL;

    public const CANAIS = OnboardingMapeamento::CANAIS;
    public const CANAL_LABELS = OnboardingMapeamento::CANAL_LABELS;

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }

    public function informadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'informado_por');
    }

    /**
     * O cliente respondeu "quanto pretende investir?" — basta UM dos dois
     * campos, porque na prática ele informa ou o caixa que tem hoje, ou o que
     * repõe por mês, raramente os dois.
     *
     * Comparação com `null` e nada mais: `0` é resposta válida (ver docblock
     * da classe). O cast `decimal:2` devolve string (`"0.00"`), que é truthy
     * em PHP — mas `empty("0.00")` seria falso hoje e verdadeiro no dia em que
     * alguém trocar o cast para float, então não se depende disso.
     */
    public function temInvestimentoPrevisto(): bool
    {
        return $this->investimento_disponivel !== null
            || $this->investimento_mensal_previsto !== null;
    }

    /** Idem: `0` fecha o item, `null` não. */
    public function temInvestimentoPublicidade(): bool
    {
        return $this->investimento_publicidade !== null;
    }
}
