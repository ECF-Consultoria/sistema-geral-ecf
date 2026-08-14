<?php

namespace App\Services\Onboarding;

use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\OnboardingRelatorio;
use App\Models\User;

/**
 * RelatorioInicialService — monta o retrato FACTUAL do relatório inicial
 * (PDF §3) a partir do que o sistema já sabe, sem tocar em rede.
 *
 * Todo dado aqui já foi coletado pelos passos automáticos: `metricas_da_conta`,
 * `anuncios_ativos_inativos` e os grants. O serviço só reúne e rotula — não
 * busca nada.
 *
 * O cliente NÃO declara nada: as informações da conta são puxadas depois que
 * ele autoriza o grant com o Sistema ECF. O que a API não devolver aparece em
 * `nao_obtidos`, visível no relatório — campo em branco nunca vira zero.
 *
 * As três seções de julgamento (pontos de atenção, oportunidades, próximos
 * passos) NÃO são geradas: elas ficam em branco esperando o analista. Gerar
 * texto para elas produziria recheio genérico com cara de análise, que é pior
 * que campo vazio.
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
        $metricas = $this->valorDoPasso($onboarding, 'metricas_da_conta');
        $acervo = $this->valorDoPasso($onboarding, 'anuncios_ativos_inativos');

        return [
            // ─── Cenário atual da conta ──────────────────────────────────────
            'cenario' => [
                'empresa'     => $company->name,
                'servico'     => $onboarding->servico?->nome,
                'marketplace' => $company->marketplace,
                'nickname_ml' => $metricas['nickname'] ?? null,
            ],

            // ─── Métricas apuradas pelo grant ────────────────────────────────
            // Tudo aqui vem do `metricas_da_conta`, que só resolve DEPOIS que o
            // cliente autoriza o Sistema ECF. Antes do grant não há o que
            // buscar, e o relatório sai com estes campos em branco.
            'metricas' => [
                'faturamento_3_meses' => $metricas['faturamento_3_meses'] ?? null,
                'full_ativo'          => $metricas['full'] ?? null,
                'reputacao_level'     => $metricas['reputacao']['level_id'] ?? null,
                'reputacao_status'    => $metricas['reputacao']['power_seller_status'] ?? null,
                'programa_parceiro'   => $metricas['programa'] ?? null,
                'iniciativa'          => $metricas['iniciativa'] ?? null,
                // O que a API não devolveu vem marcado, nunca como zero. Hoje
                // caem aqui a pontuação do Full e os objetivos da próxima
                // medalha — nenhuma chamada atual os expõe.
                'nao_obtidos' => $metricas['nao_obtidos'] ?? [],
            ],

            // ─── Estrutura encontrada ────────────────────────────────────────
            'estrutura' => [
                'anuncios_ativos'   => $acervo['ativos'] ?? null,
                'anuncios_inativos' => $acervo['inativos'] ?? null,
                'acessos'           => $this->situacaoDosAcessos($onboarding),
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
