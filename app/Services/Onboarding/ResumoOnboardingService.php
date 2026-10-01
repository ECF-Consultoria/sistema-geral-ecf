<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingConfirmacao;
use App\Models\OnboardingContato;
use App\Models\OnboardingInvestimento;
use App\Models\OnboardingPasso;
use App\Models\OnboardingRelatorio;
use App\Models\User;
use App\Support\Permissions;

/**
 * O que o onboarding COLETOU, para consulta depois dele (23/09/2026).
 *
 * ### Por que existe
 * Investimento, anotações da reunião, itens alinhados e contatos eram
 * preenchidos no onboarding e só existiam lá: o portal os tira da tela quando
 * o onboarding conclui (`blocosDeOperacao()` olha só os em andamento) e a
 * ficha da empresa não os mostrava. O que se combinou com o cliente sumia
 * justamente quando a operação começava a precisar dele.
 *
 * O destino escolhido pelo negócio foi a ficha da empresa (`/companies/{id}`):
 * é onde a equipe procura "o que sabemos deste cliente", e ela sobrevive ao
 * fim do onboarding.
 *
 * ### Só leitura, e sem regra nova
 * Nada aqui escreve nem recalcula. Os dados vêm das MESMAS tabelas que a ficha
 * do onboarding edita; editar continua sendo lá, pelo link de cada bloco.
 *
 * Rascunho fica de fora: onboarding que nunca começou não coletou nada.
 */
class ResumoOnboardingService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function daEmpresa(Company $company, User $espectador): array
    {
        // O link para editar só vai para quem abre a ficha do onboarding —
        // botão que leva a 403 é pior que botão nenhum.
        $podeAbrirFicha = $espectador->hasPermission(Permissions::CORE_ONBOARDING);

        $onboardings = Onboarding::where('company_id', $company->id)
            ->where('status', '!=', Onboarding::STATUS_RASCUNHO)
            ->with([
                'servico:id,nome',
                'responsavelAnalista:id,name',
                'responsavelEstrategista:id,name',
            ])
            ->orderByDesc('id')
            ->get();

        if ($onboardings->isEmpty()) {
            return [];
        }

        $ids = $onboardings->pluck('id');

        $investimentos = OnboardingInvestimento::whereIn('onboarding_id', $ids)->with('informadoPor:id,name')->get()->keyBy('onboarding_id');
        $relatorios = OnboardingRelatorio::whereIn('onboarding_id', $ids)->get()->keyBy('onboarding_id');
        $confirmacoes = OnboardingConfirmacao::whereIn('onboarding_id', $ids)->get()->groupBy('onboarding_id');
        $contatos = OnboardingContato::whereIn('onboarding_id', $ids)->orderBy('id')->get()->groupBy('onboarding_id');

        // Os itens conduzidos na reunião, pelo TÍTULO do passo — a resposta
        // mora em `onboarding_confirmacoes`, o nome do item mora no passo.
        $itensDeReuniao = OnboardingPasso::whereIn('onboarding_id', $ids)
            ->where('auto_fonte', OnboardingPasso::AUTO_FONTE_CONFIRMACAO)
            ->orderBy('ordem')
            ->get(['onboarding_id', 'chave', 'titulo'])
            ->groupBy('onboarding_id');

        $realizadas = OnboardingPasso::whereIn('onboarding_id', $ids)
            ->where('chave', 'reuniao_realizada')
            ->where('status', OnboardingPasso::STATUS_CONCLUIDO)
            ->pluck('onboarding_id')
            ->flip();

        return $onboardings->map(function (Onboarding $o) use (
            $investimentos, $relatorios, $confirmacoes, $contatos, $itensDeReuniao, $realizadas, $podeAbrirFicha
        ) {
            $investimento = $investimentos->get($o->id);
            $relatorio = $relatorios->get($o->id);
            $respostas = ($confirmacoes->get($o->id) ?? collect())->keyBy('chave');

            return [
                'id'           => $o->id,
                'servico'      => $o->servico?->nome,
                'status'       => $o->status,
                'iniciado_em'  => $o->iniciado_em?->toDateString(),
                'concluido_em' => $o->concluido_em?->toDateString(),
                'analista'     => $o->responsavelAnalista?->name,
                'estrategista' => $o->responsavelEstrategista?->name,
                'reuniao'      => [
                    'agendada_para' => $o->reuniao_agendada_para?->toIso8601String(),
                    'realizada'     => $realizadas->has($o->id),
                ],
                // `null` quando ninguém perguntou — a tela diz "não registrado"
                // em vez de desenhar três traços que parecem zero.
                'investimento' => $investimento && (
                    $investimento->temInvestimentoPrevisto()
                    || $investimento->temInvestimentoPublicidade()
                    || filled($investimento->observacoes)
                ) ? [
                    'disponivel'   => $investimento->investimento_disponivel,
                    'objetivo'     => $investimento->investimento_mensal_previsto,
                    'ultimos_90'   => $investimento->investimento_publicidade,
                    'observacoes'  => $investimento->observacoes,
                    'informado_em' => $investimento->informado_em?->toDateString(),
                    'informado_por' => $investimento->informadoPor?->name,
                ] : null,
                'anotacoes'    => $relatorio && collect([$relatorio->pontos_atencao, $relatorio->oportunidades, $relatorio->proximos_passos])->filter(fn ($t) => filled($t))->isNotEmpty() ? [
                    'pontos_atencao'  => $relatorio->pontos_atencao,
                    'oportunidades'   => $relatorio->oportunidades,
                    'proximos_passos' => $relatorio->proximos_passos,
                ] : null,
                'alinhados'    => ($itensDeReuniao->get($o->id) ?? collect())->map(fn (OnboardingPasso $p) => [
                    'titulo'      => $p->titulo,
                    'resposta'    => $respostas->get($p->chave)?->resposta,
                    'observacoes' => $respostas->get($p->chave)?->observacoes,
                ])->values()->all(),
                'contatos'     => ($contatos->get($o->id) ?? collect())->map(fn (OnboardingContato $c) => [
                    'nome'     => $c->nome,
                    'papel'    => OnboardingContato::PAPEL_LABELS[$c->papel] ?? $c->papel,
                    'email'    => $c->email,
                    'telefone' => $c->telefone,
                ])->values()->all(),
                'url'          => $podeAbrirFicha ? route('onboarding.painel.show', $o->id) : null,
            ];
        })->values()->all();
    }
}
