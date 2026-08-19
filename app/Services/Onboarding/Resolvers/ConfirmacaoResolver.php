<?php

namespace App\Services\Onboarding\Resolvers;

use App\Contracts\OnboardingResolver;
use App\Models\Onboarding;
use App\Models\OnboardingConfirmacao;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingResolverResultado;
use Illuminate\Support\Str;

/**
 * Resolver ÚNICO dos sete itens de confirmação do checklist (§17 publicidade:
 * processo, investimento, operação e responsabilidades; §18 ADMAN: uso,
 * funcionamento e responsabilidades).
 *
 * Um resolver só para sete passos porque a pergunta que ele responde é sempre
 * a mesma — "esta confirmação foi dada?" — e o que muda entre os itens é
 * apenas QUAL confirmação: ele se orienta por `$passo->chave`, que é o espelho
 * de `onboarding_confirmacoes.chave`. Sete classes idênticas variando só uma
 * string seriam sete lugares para o mesmo bug.
 *
 * ─── Só "Sim" fecha ───────────────────────────────────────────────────────
 * "Não" e "Pendente" devolvem `naoColetado`, o que mantém o passo em `aberto`
 * (regra 10 de `OnboardingEngineService::aplicarResultado()`) e portanto
 * segura a conclusão do onboarding. Marcar "Não" NUNCA pode virar
 * `nao_aplicavel`: aquele status conta como resolvido e daria o onboarding
 * por concluído com o item nunca cumprido.
 *
 * Os motivos de "Não" e de "sem resposta" são DIFERENTES de propósito — a
 * tela precisa distinguir "a equipe respondeu que não" de "ninguém abriu esse
 * item ainda". São dois problemas com donos e prazos diferentes.
 *
 * Tem `auto_fonte` pelo mesmo motivo de {@see RelatorioInicialResolver}
 * (D-19): sem isso o passo ganharia um "marcar como feito" e alguém fecharia
 * a etapa sem ter respondido nada.
 *
 * Nunca devolve `indeterminado`: não há rede envolvida, a resposta está na
 * tabela local ou não está.
 */
class ConfirmacaoResolver implements OnboardingResolver
{
    /**
     * `onboarding_passos.ultimo_erro` é varchar(255) e o MariaDB trunca em
     * silêncio (learnings §6) — a observação entra no motivo cortada para que
     * a frase base nunca seja a parte perdida.
     */
    private const LIMITE_OBSERVACAO_NO_MOTIVO = 150;

    public function chave(): string
    {
        return OnboardingPasso::AUTO_FONTE_CONFIRMACAO;
    }

    public function label(): string
    {
        return 'Confirmação respondida com "Sim"';
    }

    public function ajuda(): string
    {
        return 'Confere se este item de confirmação foi respondido com "Sim" pela equipe (síncrono, sem chamada externa). "Não" e "Pendente" mantêm o passo aberto.';
    }

    public function assincrono(): bool
    {
        return false;
    }

    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado
    {
        $confirmacao = OnboardingConfirmacao::where('onboarding_id', $onboarding->id)
            ->where('chave', $passo->chave)
            ->first();

        // Linha ausente e linha `pendente` significam a mesma coisa aqui:
        // ninguém decidiu. A observação de uma pendente, quando existe, entra
        // no motivo — é o recado de quem já olhou o item.
        if ($confirmacao === null || $confirmacao->pendente()) {
            return OnboardingResolverResultado::naoColetado(
                $this->comObservacao('Ainda sem resposta — pendente de confirmação', $confirmacao)
            );
        }

        if ($confirmacao->negada()) {
            return OnboardingResolverResultado::naoColetado(
                $this->comObservacao('Respondido "Não" — item ainda não cumprido', $confirmacao)
            );
        }

        $valor = ['resposta' => OnboardingConfirmacao::RESPOSTA_SIM];

        if ($confirmacao->respondido_em) {
            $valor['respondido_em'] = $confirmacao->respondido_em->toISOString();
        }

        if ($confirmacao->respondido_por) {
            $valor['respondido_por'] = $confirmacao->respondido_por;
        }

        // "se houver": observação vazia não vira chave nula no valor do passo.
        if (filled($confirmacao->observacoes)) {
            $valor['observacoes'] = $confirmacao->observacoes;
        }

        return OnboardingResolverResultado::concluido($valor);
    }

    private function comObservacao(string $motivo, ?OnboardingConfirmacao $confirmacao): string
    {
        if ($confirmacao === null || ! filled($confirmacao->observacoes)) {
            return $motivo;
        }

        return $motivo.': '.Str::limit(
            trim($confirmacao->observacoes),
            self::LIMITE_OBSERVACAO_NO_MOTIVO
        );
    }
}
