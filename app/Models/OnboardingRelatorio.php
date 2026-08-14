<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OnboardingRelatorio — o relatório inicial apresentado na reunião de
 * onboarding (PDF §3), um por onboarding.
 *
 * `dados` é o retrato factual congelado no momento da geração; os três campos
 * de texto são a leitura humana. Um relatório sem as três seções escritas está
 * incompleto — é o que {@see \App\Services\Onboarding\RelatorioInicialService}
 * usa para decidir se o passo pode fechar.
 */
class OnboardingRelatorio extends Model
{
    protected $table = 'onboarding_relatorios';

    protected $fillable = [
        'onboarding_id',
        'dados',
        'pontos_atencao',
        'oportunidades',
        'proximos_passos',
        'gerado_em',
        'gerado_por',
        'atualizado_por',
    ];

    protected $casts = [
        'dados'     => 'array',
        'gerado_em' => 'datetime',
    ];

    /**
     * As três seções de julgamento, na ordem do PDF. O relatório só está
     * pronto quando todas têm conteúdo — sem isso ele é um retrato de dados,
     * não um relatório.
     *
     * @var array<int, string>
     */
    public const SECOES_ANALISTA = [
        'pontos_atencao',
        'oportunidades',
        'proximos_passos',
    ];

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }

    public function geradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerado_por');
    }

    public function atualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atualizado_por');
    }

    /** Todas as três seções de julgamento preenchidas. */
    public function completo(): bool
    {
        return collect(self::SECOES_ANALISTA)
            ->every(fn (string $secao) => filled($this->{$secao}));
    }

    /** @return array<int, string> As seções que ainda faltam escrever. */
    public function secoesPendentes(): array
    {
        return collect(self::SECOES_ANALISTA)
            ->reject(fn (string $secao) => filled($this->{$secao}))
            ->values()
            ->all();
    }
}
