<?php

namespace App\Services\Onboarding;

use App\Models\Onboarding;
use App\Models\OnboardingFicha;
use App\Models\OnboardingPasso;
use App\Models\OnboardingRelatorio;
use App\Models\User;

/**
 * RelatorioInicialService — monta o retrato FACTUAL do relatório inicial
 * (PDF §3) a partir do que o sistema já sabe, sem tocar em rede.
 *
 * Todo dado aqui já foi coletado pelos passos automáticos ou declarado pelo
 * cliente na ficha: `metricas_da_conta`, `anuncios_ativos_inativos`, os grants
 * e a `OnboardingFicha`. O serviço só reúne e rotula — não busca nada.
 *
 * As três seções de julgamento (pontos de atenção, oportunidades, próximos
 * passos) NÃO são geradas: elas ficam em branco esperando o analista. Gerar
 * texto para elas produziria recheio genérico com cara de análise, que é pior
 * que campo vazio.
 *
 * O DECLARADO E O APURADO ANDAM LADO A LADO no relatório, nunca fundidos. É a
 * divergência entre os dois que interessa na reunião: cliente que declarou
 * reputação verde e cuja conta não tem, faturamento estimado muito acima do
 * apurado. Fundir os dois campos apagaria justamente a informação.
 */
class RelatorioInicialService
{
    /**
     * Gera (ou regera) o relatório do onboarding. Regerar SUBSTITUI o retrato
     * factual e PRESERVA o texto do analista — o que ele escreveu não se perde
     * porque o acervo foi recontado.
     */
    public function gerar(Onboarding $onboarding, ?User $usuario = null): OnboardingRelatorio
    {
        $relatorio = OnboardingRelatorio::firstOrNew(['onboarding_id' => $onboarding->id]);

        $relatorio->dados = $this->montarDados($onboarding);
        $relatorio->gerado_em = now();
        $relatorio->gerado_por = $usuario?->id ?? $relatorio->gerado_por;
        $relatorio->save();

        return $relatorio->fresh();
    }

    /**
     * O retrato factual, nas três seções mecânicas do PDF.
     *
     * @return array<string, mixed>
     */
    private function montarDados(Onboarding $onboarding): array
    {
        $company = $onboarding->company;
        $ficha = OnboardingFicha::where('company_id', $company->id)->first();

        $metricas = $this->valorDoPasso($onboarding, 'metricas_da_conta');
        $acervo = $this->valorDoPasso($onboarding, 'anuncios_ativos_inativos');

        return [
            // ─── Cenário atual da conta ──────────────────────────────────────
            'cenario' => [
                'empresa'     => $company->name,
                'servico'     => $onboarding->servico?->nome,
                'marketplace' => $ficha?->marketplace ?? $company->marketplace,
                'nickname_ml' => $metricas['nickname'] ?? null,
            ],

            // ─── Métricas: declarado × apurado, lado a lado ──────────────────
            'metricas' => [
                'faturamento_3_meses' => [
                    'declarado' => $ficha?->faturamento_3_meses !== null ? (float) $ficha->faturamento_3_meses : null,
                    'apurado'   => $metricas['faturamento_3_meses'] ?? null,
                ],
                'full_ativo' => [
                    'declarado' => $ficha?->full_ativo,
                    'apurado'   => $metricas['full'] ?? null,
                ],
                'reputacao' => [
                    'declarado_verde' => $ficha?->reputacao_verde,
                    'apurado_level'   => $metricas['reputacao']['level_id'] ?? null,
                    'apurado_status'  => $metricas['reputacao']['power_seller_status'] ?? null,
                ],
                'medalha' => [
                    'declarada' => $ficha?->medalha_atual,
                ],
                'full_pontuacao_declarada' => $ficha?->full_pontuacao,
                // O que a API não devolveu vem marcado, nunca como zero.
                'nao_obtidos' => $metricas['nao_obtidos'] ?? [],
            ],

            // ─── Estrutura encontrada ────────────────────────────────────────
            'estrutura' => [
                'anuncios_ativos'   => $acervo['ativos'] ?? null,
                'anuncios_inativos' => $acervo['inativos'] ?? null,
                'acessos'           => $this->situacaoDosAcessos($onboarding),
            ],

            'ficha' => [
                'origem'        => $ficha?->origem,
                'respondidas'   => $ficha?->respondidas() ?? 0,
                'preenchida_em' => $ficha?->preenchida_em?->toISOString(),
            ],
        ];
    }

    /**
     * Situação dos passos de acesso, pela CHAVE — o relatório precisa dizer o
     * que estava no lugar no dia da reunião.
     *
     * @return array<string, string>
     */
    private function situacaoDosAcessos(Onboarding $onboarding): array
    {
        $chaves = [
            'planilha_custos_adman',
            'grant_consultoria_adman',
            'grant_sistema_ecf',
            'grant_de_ads',
            'acesso_colaborador_ml',
        ];

        return OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->whereIn('chave', $chaves)
            ->get()
            ->mapWithKeys(fn (OnboardingPasso $p) => [$p->chave => $p->status])
            ->all();
    }

    /**
     * @return array<string, mixed> `valor` do passo, ou vazio se o passo não
     *   existe ou não foi resolvido — nunca lança, o relatório precisa sair
     *   mesmo com mapeamento incompleto.
     */
    private function valorDoPasso(Onboarding $onboarding, string $chave): array
    {
        $passo = OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', $chave)
            ->first();

        return is_array($passo?->valor) ? $passo->valor : [];
    }
}
