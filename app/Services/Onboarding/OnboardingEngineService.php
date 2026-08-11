<?php

namespace App\Services\Onboarding;

use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\OnboardingTemplate;
use App\Models\TemplatePasso;
use Illuminate\Support\Facades\Log;

/**
 * OnboardingEngineService — motor do onboarding geral por serviço (Fase 135).
 *
 * Monta um onboarding a partir da versão ATIVA e CONGELADA do template do
 * serviço no momento da criação (D-07/SC-09) e sabe destravar, carimbar e
 * pular passo sozinho — os métodos de avaliação (`reavaliar()`,
 * `aplicarResultado()`, `avaliarCondicao()`, `concluirManualmente()`) são
 * acrescentados na Task 3 deste mesmo plano.
 *
 * Molde de estilo: AdmanService (métodos por responsabilidade, docblock
 * descrevendo o shape de retorno) e DiagnoseCustId (classificação por
 * resultado, sem booleano solto).
 */
class OnboardingEngineService
{
    /**
     * Cria o onboarding em rascunho para um contrato de serviço, montado da
     * versão ativa do template — ou devolve `null` quando o serviço não tem
     * template publicado (D-08: só Gestão nesta v1; os outros serviços do
     * catálogo ficam inertes até ganharem template próprio).
     *
     * Guard de duplicidade em DUAS camadas, sem lançar exceção — este
     * método roda dentro do Observer (Plano 05), por sua vez dentro do
     * loop SEM transação de `CompanyGroupController::atribuirServico()`; uma
     * exceção aqui derrubaria a request inteira:
     *  - já existe onboarding para este `contrato_servico_id` (também é
     *    constraint de banco, ver Plano 02) → devolve o existente;
     *  - já existe onboarding NÃO concluído para o par `company_id` ×
     *    `servico_id` (D-01: um por empresa × serviço) → devolve o
     *    existente, mesmo que o `contrato_servico_id` seja diferente.
     *
     * Todo o trabalho é banco local — nenhuma chamada de rede, nenhum
     * client HTTP, nenhum comando Artisan disparado a partir daqui.
     */
    public function criarParaContrato(ContratoServico $contrato): ?Onboarding
    {
        $existentePorContrato = Onboarding::where('contrato_servico_id', $contrato->id)->first();
        if ($existentePorContrato) {
            return $existentePorContrato;
        }

        $existentePorParEmpresaServico = Onboarding::where('company_id', $contrato->company_id)
            ->where('servico_id', $contrato->servico_id)
            ->naoConcluido()
            ->first();
        if ($existentePorParEmpresaServico) {
            return $existentePorParEmpresaServico;
        }

        $template = OnboardingTemplate::ativo()
            ->where('servico_id', $contrato->servico_id)
            ->first();

        if (! $template) {
            Log::info(
                "[Onboarding] serviço sem template publicado — contrato {$contrato->id} "
                . "(servico_id {$contrato->servico_id}) não gera onboarding nesta v1 (D-08)."
            );

            return null;
        }

        $onboarding = Onboarding::create([
            'company_id'          => $contrato->company_id,
            'servico_id'          => $contrato->servico_id,
            'contrato_servico_id' => $contrato->id,
            'template_id'         => $template->id,
            'status'              => Onboarding::STATUS_RASCUNHO,
        ]);

        $this->montarPassos($onboarding);

        return $onboarding;
    }

    /**
     * Cria 1 OnboardingPasso por TemplatePasso da versão congelada do
     * onboarding (`onboarding->template_id`), ordenado por `ordem`,
     * copiando `chave` (denormalizada, D-10) e `template_passo_id`.
     *
     * Todos nascem `status = bloqueado` e `disponivel_em = null` —
     * inclusive os passos sem `depende_de` — porque o onboarding nasce em
     * `rascunho` e rascunho não corre SLA (D-05/SC-04); só `reavaliar()`
     * (Task 3) destrava, e só quando o onboarding estiver em `andamento`.
     *
     * Inserção em lote (`insert()` com timestamps explícitos) para manter o
     * custo baixo no cenário do `CompanyGroupController` (loop sem
     * transação, pode rodar N vezes numa única request).
     */
    public function montarPassos(Onboarding $onboarding): void
    {
        $templatePassos = TemplatePasso::where('template_id', $onboarding->template_id)
            ->orderBy('ordem')
            ->get();

        if ($templatePassos->isEmpty()) {
            return;
        }

        $agora = now();

        $linhas = $templatePassos->map(fn (TemplatePasso $templatePasso) => [
            'onboarding_id'     => $onboarding->id,
            'template_passo_id' => $templatePasso->id,
            'chave'             => $templatePasso->chave,
            'status'            => OnboardingPasso::STATUS_BLOQUEADO,
            'disponivel_em'     => null,
            'created_at'        => $agora,
            'updated_at'        => $agora,
        ])->all();

        OnboardingPasso::insert($linhas);
    }
}
