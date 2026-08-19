<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingInvestimento;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingResolverResultado;

/**
 * Resolver do item de checklist "valor de investimento alinhado" (§13.1).
 *
 * Fecha quando o cliente informou o investimento DISPONÍVEL ou o MENSAL
 * PREVISTO — um dos dois basta, porque na prática ele responde ou o caixa que
 * tem hoje, ou o que repõe por mês, raramente os dois. Exigir ambos deixaria
 * o passo aberto para sempre em metade dos casos.
 *
 * ─── Zero fecha o passo ────────────────────────────────────────────────────
 * `0` é uma resposta DADA, não ausência de resposta: quem declara não ter
 * caixa disponível respondeu a pergunta, e o alinhamento aconteceu. Só `null`
 * é "ninguém perguntou ainda". A leitura fica em
 * {@see OnboardingInvestimento::temInvestimentoPrevisto()}, que compara com
 * `null` e nada mais — este é o erro clássico deste passo e é justamente por
 * ele que não se usa `empty()`/`filled()` aqui.
 *
 * ─── Por que `auto_fonte`, e não conclusão manual ──────────────────────────
 * Mesmo motivo do {@see RelatorioInicialResolver}: com conclusão manual o
 * passo ganha um "marcar como feito" e alguém fecha a etapa sem ter perguntado
 * o valor a ninguém. Com `auto_fonte`, o único caminho para fechar é o dado
 * existir (D-19).
 *
 * Nunca devolve `indeterminado`: sem rede envolvida, a linha está no banco ou
 * não está.
 *
 * `informado_em`/`informado_canal` no `valor` são o carimbo do ÚLTIMO registro
 * da linha, não necessariamente o do campo que fechou este passo — os três
 * valores de dinheiro dividem um carimbo só (ver o limite conhecido no
 * docblock da migration). Ler como "quando/por onde esta linha foi mexida pela
 * última vez", nunca como prova de que o número ao lado veio daquele canal.
 */
class InvestimentoResolver implements OnboardingResolver
{
    public function chave(): string
    {
        return OnboardingPasso::AUTO_FONTE_INVESTIMENTO;
    }

    public function label(): string
    {
        return 'Valor de investimento alinhado';
    }

    public function ajuda(): string
    {
        return 'Confere se o cliente informou o investimento disponível ou o investimento mensal previsto (síncrono, sem chamada externa).';
    }

    public function assincrono(): bool
    {
        return false;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        $investimento = OnboardingInvestimento::where('onboarding_id', $onboarding->id)->first();

        if (! $investimento || ! $investimento->temInvestimentoPrevisto()) {
            return OnboardingResolverResultado::naoColetado('Investimento previsto ainda não informado pelo cliente');
        }

        return OnboardingResolverResultado::concluido([
            'investimento_disponivel'      => $investimento->investimento_disponivel,
            'investimento_mensal_previsto' => $investimento->investimento_mensal_previsto,
            'informado_em'                 => $investimento->informado_em?->toISOString(),
            'informado_canal'              => $investimento->informado_canal,
        ]);
    }
}
