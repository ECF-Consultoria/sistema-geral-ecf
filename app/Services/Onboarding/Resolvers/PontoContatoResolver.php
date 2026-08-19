<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingContato;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingResolverResultado;
use Illuminate\Support\Collection;

/**
 * Resolver do passo "ponto de contato do cliente definido" (§13.2).
 *
 * Fecha quando existe pelo menos um contato de papel `ponto_de_contato` com
 * NOME e E-MAIL preenchidos. Os dois juntos, não um ou outro: o ponto de
 * contato existe para ser acionado, e "Fulano" sem e-mail nem "sem nome" com
 * e-mail cumprem isso.
 *
 * Tem `auto_fonte` — e não um "marcar como feito" — pelo mesmo motivo do
 * relatório inicial: com conclusão manual, alguém fecharia a etapa sem nunca
 * cadastrar ninguém, e a informação que o passo existe para produzir não
 * existiria. Com `auto_fonte`, conclusão manual é recusada (D-19) e o único
 * caminho é cadastrar de fato.
 *
 * Nunca devolve `indeterminado`: lê tabela local, o dado está lá ou não está.
 */
class PontoContatoResolver implements OnboardingResolver
{
    public function chave(): string
    {
        return OnboardingPasso::AUTO_FONTE_PONTO_CONTATO;
    }

    public function label(): string
    {
        return 'Ponto de contato do cliente definido';
    }

    public function ajuda(): string
    {
        return 'Confere se há ao menos um ponto de contato do cliente cadastrado com nome e e-mail (síncrono, sem chamada externa).';
    }

    public function assincrono(): bool
    {
        return false;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        // Ordem por id para que a cobrança nominal saia sempre na mesma
        // sequência — motivo que muda de ordem a cada leitura parece dado novo
        // para quem lê a tela.
        $contatos = OnboardingContato::query()
            ->where('onboarding_id', $onboarding->id)
            ->pontosDeContato()
            ->orderBy('id')
            ->get();

        if ($contatos->isEmpty()) {
            return OnboardingResolverResultado::naoColetado('Ponto de contato do cliente ainda não cadastrado');
        }

        $completos = $contatos->filter(
            fn (OnboardingContato $contato) => filled($contato->nome) && $contato->temComoAcionar()
        );

        if ($completos->isEmpty()) {
            return OnboardingResolverResultado::naoColetado(
                OnboardingContato::motivoDentroDoLimite($this->motivoDoIncompleto($contatos))
            );
        }

        // Só ids e contagem: nome/e-mail continuam morando na linha, com uma
        // fonte só. Copiá-los para `onboarding_passos.valor` criaria uma
        // segunda versão da verdade que envelhece na primeira edição do
        // contato — e a pergunta "qual delas está certa?" meses depois.
        return OnboardingResolverResultado::concluido([
            'total'       => $completos->count(),
            'contato_ids' => $completos->pluck('id')->all(),
        ]);
    }

    /**
     * O que falta, dito por lacuna e não por pessoa.
     *
     * Nome e e-mail faltam de formas diferentes e podem faltar ao mesmo tempo
     * em linhas diferentes: citar só quem está sem e-mail deixaria a linha sem
     * nome invisível, e quem lê a tela cadastraria um e-mail achando que
     * resolveu. As duas lacunas saem juntas quando as duas existem.
     */
    private function motivoDoIncompleto(Collection $contatos): string
    {
        $semEmail = $contatos->reject(fn (OnboardingContato $c) => $c->temComoAcionar());
        $semNome = $contatos->reject(fn (OnboardingContato $c) => filled($c->nome));

        $partes = [];

        if ($semEmail->isNotEmpty()) {
            $partes[] = 'sem e-mail: '.OnboardingContato::cobrancaNominal($semEmail);
        }

        if ($semNome->isNotEmpty()) {
            $partes[] = $semNome->count() === 1
                ? 'sem nome: 1 contato'
                : "sem nome: {$semNome->count()} contatos";
        }

        return 'Ponto de contato incompleto — '.implode('; ', $partes);
    }
}
