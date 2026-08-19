<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OnboardingConfirmacao — a resposta Sim / Não / Pendente de um dos sete itens
 * de confirmação do checklist (§17 publicidade, §18 ADMAN), com espaço para
 * observação.
 *
 * Existe porque `onboarding_passos.status` não tem como dizer "Não": o único
 * status candidato, `nao_aplicavel`, conta como RESOLVIDO na conclusão do
 * onboarding — marcar "Não" por ele daria o onboarding por concluído com a
 * publicidade nunca explicada. Aqui a resposta negativa é registrada com nome
 * e observação, e o passo continua `aberto` até virar "Sim". Ver o docblock da
 * migration.
 *
 * A linha é casada com o passo por `chave` (espelho de
 * `onboarding_passos.chave`), e é isso que permite um resolver só
 * ({@see \App\Services\Onboarding\Resolvers\ConfirmacaoResolver}) atender os
 * sete itens.
 */
class OnboardingConfirmacao extends Model
{
    protected $table = 'onboarding_confirmacoes';

    protected $fillable = [
        'onboarding_id',
        'chave',
        'resposta',
        'observacoes',
        'respondido_em',
        'respondido_por',
    ];

    protected $casts = [
        'respondido_em' => 'datetime',
    ];

    // ─── Catálogo fechado de `resposta` ─────────────────────────────────────
    /** Confirmado — a ÚNICA resposta que fecha o passo. */
    public const RESPOSTA_SIM = 'sim';
    /** Respondido explicitamente que não aconteceu — o passo segue aberto. */
    public const RESPOSTA_NAO = 'nao';
    /**
     * Terceira opção do formulário do documento. Equivale à ausência de linha
     * para efeito de resolução; existe como valor gravável para que a
     * observação de quem ainda vai confirmar tenha onde morar sem forçar um
     * Sim ou um Não prematuro.
     */
    public const RESPOSTA_PENDENTE = 'pendente';

    /** @var array<int, string> */
    public const RESPOSTAS = [
        self::RESPOSTA_SIM,
        self::RESPOSTA_NAO,
        self::RESPOSTA_PENDENTE,
    ];

    /** @var array<string, string> */
    public const RESPOSTA_LABELS = [
        self::RESPOSTA_SIM      => 'Sim',
        self::RESPOSTA_NAO      => 'Não',
        self::RESPOSTA_PENDENTE => 'Pendente',
    ];

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }

    public function respondidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondido_por');
    }

    /** Confirmado — o único estado que fecha o passo. */
    public function confirmada(): bool
    {
        return $this->resposta === self::RESPOSTA_SIM;
    }

    /** Respondido "Não" — decisão tomada, e ela não fecha o passo. */
    public function negada(): bool
    {
        return $this->resposta === self::RESPOSTA_NAO;
    }

    /**
     * Ninguém decidiu ainda. Cobre `pendente` e também qualquer valor fora do
     * catálogo que chegue por linha antiga — o resolver nunca deve fechar um
     * passo por resposta que não reconhece.
     */
    public function pendente(): bool
    {
        return ! $this->confirmada() && ! $this->negada();
    }
}
