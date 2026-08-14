<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OnboardingFicha — as 7 informações da conta declaradas pelo cliente, uma por
 * empresa.
 *
 * É o retrato PRÉ-GRANT: acontece antes da configuração de acessos, quando o
 * sistema ainda não consegue consultar Adman nem Mercado Livre. Os resolvers
 * automáticos apuram os mesmos dados DEPOIS, e a comparação entre os dois lados
 * é informação de negócio.
 *
 * `null` em campo de resposta significa "não respondido" — nunca "não". Um
 * `full_ativo = null` não é `false`: é uma pergunta que ficou em branco.
 */
class OnboardingFicha extends Model
{
    protected $table = 'onboarding_fichas';

    protected $fillable = [
        'company_id',
        'faturamento_3_meses',
        'marketplace',
        'full_ativo',
        'full_pontuacao',
        'reputacao_verde',
        'medalha_atual',
        'objetivos_proxima_medalha',
        'origem',
        'preenchida_por',
        'preenchida_em',
        'ip',
    ];

    protected $casts = [
        'faturamento_3_meses' => 'decimal:2',
        'full_ativo'          => 'boolean',
        'reputacao_verde'     => 'boolean',
        'full_pontuacao'      => 'integer',
        'preenchida_em'       => 'datetime',
    ];

    // ─── Catálogo fechado de `origem` ────────────────────────────────────────
    /** O próprio cliente preencheu, pelo link público. */
    public const ORIGEM_CLIENTE = 'cliente';
    /** Alguém da equipe preencheu pelo painel, tipicamente em call com o cliente. */
    public const ORIGEM_EQUIPE = 'equipe';

    public const ORIGENS = [
        self::ORIGEM_CLIENTE,
        self::ORIGEM_EQUIPE,
    ];

    /**
     * As 7 chaves de resposta, na ordem do PDF. Serve tanto para validação
     * quanto para a tela — manter a lista num lugar só evita que formulário
     * público e formulário interno divirjam.
     *
     * @var array<int, string>
     */
    public const CAMPOS_RESPOSTA = [
        'faturamento_3_meses',
        'marketplace',
        'full_ativo',
        'full_pontuacao',
        'reputacao_verde',
        'medalha_atual',
        'objetivos_proxima_medalha',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function preenchidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preenchida_por');
    }

    /**
     * Quantas das 7 perguntas foram de fato respondidas. Usado pelo painel para
     * mostrar "5 de 7" sem transformar isso numa barra de progresso (SC-11).
     */
    public function respondidas(): int
    {
        return collect(self::CAMPOS_RESPOSTA)
            ->filter(fn (string $campo) => $this->{$campo} !== null && $this->{$campo} !== '')
            ->count();
    }
}
