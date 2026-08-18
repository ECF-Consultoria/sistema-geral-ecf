<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\OnboardingRelatorio;
use App\Services\Onboarding\OnboardingResolverResultado;

/**
 * Resolver do passo "Relatório inicial" (PDF §3).
 *
 * Fecha quando o relatório existe E as três seções de julgamento estão
 * escritas. O retrato factual sozinho não basta: relatório sem pontos de
 * atenção, oportunidades e próximos passos é um dump de dados, não o
 * documento que a reunião pede.
 *
 * Tem `auto_fonte` pelo mesmo motivo da ficha: sem isso, o passo teria um
 * "marcar como feito" e alguém fecharia a etapa sem relatório nenhum. Com
 * `auto_fonte`, conclusão manual é recusada (D-19) e o único caminho é
 * escrever de fato.
 *
 * Nunca devolve `indeterminado`: não há rede envolvida.
 */
class RelatorioInicialResolver implements OnboardingResolver
{
    public function chave(): string
    {
        return OnboardingPasso::AUTO_FONTE_RELATORIO_INICIAL;
    }

    public function label(): string
    {
        return 'Relatório inicial escrito';
    }

    public function ajuda(): string
    {
        return 'Confere se o relatório inicial foi gerado e teve as três seções de análise escritas (síncrono, sem chamada externa).';
    }

    public function assincrono(): bool
    {
        return false;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        $relatorio = OnboardingRelatorio::where('onboarding_id', $onboarding->id)->first();

        if (! $relatorio) {
            return OnboardingResolverResultado::naoColetado('Relatório inicial ainda não gerado');
        }

        if (! $relatorio->completo()) {
            $faltam = implode(', ', $relatorio->secoesPendentes());

            return OnboardingResolverResultado::naoColetado("Relatório gerado, faltam as seções: {$faltam}");
        }

        return OnboardingResolverResultado::concluido([
            'gerado_em'      => $relatorio->gerado_em?->toISOString(),
            'secoes_escritas' => count(OnboardingRelatorio::SECOES_ANALISTA),
        ]);
    }
}
