<?php

namespace App\Services\Clicksign;

use App\Jobs\GerarContratoAssinaturaJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Services\Contratos\ContratoDadosMinimosService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * ContratoClicksignService — Fase 127 Plano 127-06. O **ponto único** que o
 * ROADMAP pede: `iniciarParaEmpresa()` decide se a empresa está pronta,
 * congela os dados, cria um `ContratoAssinatura` por serviço ativo e
 * despacha um `GerarContratoAssinaturaJob` por contrato — nessa ordem.
 *
 * ⚠️ Fronteiras desta classe, para ninguém "completar" depois:
 *
 * - **Não ativa** o envelope (D-02) e **não grava `enviado_em`** — com a
 *   ativação acontecendo FORA do sistema (é o Comercial quem envia, pela
 *   interface da Clicksign), quem vai saber que foi enviado é o webhook da
 *   **Fase 129**. Um `enviado_em` otimista aqui seria mentira no banco.
 * - **Não expõe rota HTTP** — a tela e a permissão `admin.contratos` são da
 *   **Fase 131**; a autorização de quem pode chamar este service é
 *   responsabilidade de quem chamar, não deste service.
 * - **Não reusa `PendenciasComerciaisService`** — motivo documentado no
 *   docblock de `ContratoDadosMinimosService` (plano 127-03): aquele
 *   service é gated por `is_origem_hubspot` e não checa e-mail nem CNPJ.
 */
class ContratoClicksignService
{
    public function __construct(private ContratoDadosMinimosService $dadosMinimos)
    {
    }

    /**
     * Ponto único de entrada. Sequência **nesta ordem** — a ordem é o
     * requisito, não um detalhe:
     *
     * 1. Bloqueio ANTES de qualquer I/O (Success Criteria 1, REDE-05).
     * 2. Lê os serviços ativos da empresa (a checagem do passo 1 já garante
     *    que a lista não está vazia).
     * 3. Um `ContratoAssinatura` por `ContratoServico`, com `servicos_snapshot`
     *    congelado (D-06/D-10) e despacho do job de montagem do envelope
     *    (CLICK-02), escalonado por delay para não bater na corrida do
     *    rate limit (D-01).
     *
     * @return array{ok: bool, faltando: array, criados: array<int, int>, pulados: array<int, array{servico_id: int, motivo: string}>}
     */
    public function iniciarParaEmpresa(Company $company, ?int $prazoDias = null, ?int $lembreteDias = null): array
    {
        // 1. Bloqueio (Success Criteria 1, REDE-05) — este `return` precoce
        // protege o orçamento de 15 chamadas por contrato contra a janela
        // MEDIDA de 20/min da Clicksign: nenhuma chamada HTTP, nenhum PDF,
        // nenhum registro criado quando falta dado.
        $faltando = $this->dadosMinimos->faltantes($company);

        if ($faltando !== []) {
            return ['ok' => false, 'faltando' => $faltando, 'criados' => [], 'pulados' => []];
        }

        // 1b. Configuração da PRÓPRIA ECF (D-08) — achado no gate do plano
        // 127-07 contra o sandbox real. Sem esta guarda o fluxo criava o
        // envelope e o documento e só quebrava no 1º signatário
        // (`email - não pode ficar em branco`): 3 chamadas queimadas da janela
        // de 20/min mais o rollback, por um dado sabível SEM nenhuma
        // requisição. É o que o Goal da fase proíbe literalmente.
        //
        // Devolvido em chave PRÓPRIA (`configuracao`), não em `faltando`:
        // pendência de empresa é do Comercial resolver na tela da Fase 131;
        // isto é `.env`, que só um admin resolve. Misturar mandaria o
        // Comercial caçar um campo que não é dele.
        $problemasDeConfiguracao = $this->dadosMinimos->faltantesDaConfiguracaoEcf();

        if ($problemasDeConfiguracao !== []) {
            return [
                'ok'          => false,
                'faltando'    => [],
                'configuracao' => $problemasDeConfiguracao,
                'criados'     => [],
                'pulados'     => [],
            ];
        }

        // 2. Serviços ativos — a checagem acima já garante que não está vazia.
        $contratosServico = $company->contratosServico()->where('ativo', true)->with('servico')->get();

        // 2b. Incidente Mons Bike (quick 260821-l8n, deal HubSpot 63836845208):
        // dois itens de linha do MESMO serviço (pagamento escalonado) geram
        // dois `ContratoServico` do mesmo `servico_id`. Sem esta guarda, o
        // Observer disparava no primeiro, congelava só aquele valor no
        // snapshot e o segundo caía silenciosamente em `ja_em_andamento` —
        // `ok: true`, dado perdido, ninguém avisado. Consolidar as duas
        // linhas (somar valores / pagamento escalonado) é decisão do
        // jurídico e está FORA de escopo aqui — o objetivo é só parar de
        // perder dado em silêncio.
        //
        // Pré-computado ANTES do laço (não dentro dele) para que o PRIMEIRO
        // item de um par duplicado também seja barrado — se a contagem fosse
        // feita item a item durante o laço, o primeiro passaria batido antes
        // de o segundo aparecer e reproduziria exatamente o bug original.
        $servicosDuplicados = $contratosServico->countBy('servico_id')->filter(fn (int $qtd) => $qtd > 1);

        foreach ($servicosDuplicados as $servicoIdDuplicado => $quantidade) {
            $itensDuplicados = $contratosServico->where('servico_id', $servicoIdDuplicado);

            Log::warning('[Clicksign] serviço duplicado (mais de um ContratoServico ativo) — contrato não gerado para não perder dado', [
                'company_id'           => $company->id,
                'servico_id'           => $servicoIdDuplicado,
                'quantidade'           => $quantidade,
                'hubspot_line_item_id' => $itensDuplicados->pluck('hubspot_line_item_id')->filter()->values()->all(),
            ]);
        }

        $criados = [];
        $pulados = [];

        // 3. Um contrato por serviço.
        foreach ($contratosServico as $i => $contratoServico) {
            // Guard de UX (D-05): só mensagem amigável — a garantia REAL é a
            // constraint composta (empresa+serviço) capturada abaixo pelo
            // catch, porque uma checagem de código corre risco de corrida
            // entre dois workers concorrentes.
            // Isenção por serviço (D-03/SC0, Fase 128-01) — checada AQUI, não só
            // no orquestrador (`GatilhoContratoAdministrativoService`, plano
            // 128-03), porque a Fase 131 vai expor um botão que chama este
            // método direto, sem passar pelo orquestrador. Sem esta guarda,
            // uma empresa com Gestão + Polos geraria um `ContratoAssinatura`
            // também para Polos — violação direta do SC0.
            //
            // `servico === null` (dado órfão) NÃO pula — default seguro do
            // plano 128-01 vale aqui também: nunca isentar por ausência de dado.
            if (optional($contratoServico->servico)?->exigeContrato() === false) {
                $pulados[] = ['servico_id' => $contratoServico->servico_id, 'motivo' => 'servico_isento'];

                continue;
            }

            // Guarda de duplicidade (quick 260821-l8n) — checada AQUI, antes
            // do guard de reentrância abaixo, de propósito: o guard de
            // `ja_em_andamento` só enxerga contrato que JÁ existe, então o
            // primeiro item de um par duplicado passaria por ele sem barreira
            // nenhuma. `$servicosDuplicados` foi computado sobre a coleção
            // inteira antes do laço, então os DOIS itens do par caem aqui.
            if ($servicosDuplicados->has($contratoServico->servico_id)) {
                $pulados[] = ['servico_id' => $contratoServico->servico_id, 'motivo' => 'servicos_duplicados'];

                continue;
            }

            if (ContratoAssinatura::emAndamentoDoServico($company->id, $contratoServico->servico_id)) {
                $pulados[] = ['servico_id' => $contratoServico->servico_id, 'motivo' => 'ja_em_andamento'];

                continue;
            }

            try {
                $contrato = ContratoAssinatura::create([
                    'company_id'     => $company->id,
                    'servico_id'     => $contratoServico->servico_id,
                    'status'         => ContratoAssinatura::STATUS_RASCUNHO,
                    'prazo_dias'     => $prazoDias,
                    'lembrete_dias'  => $lembreteDias,
                    // D-06 + D-10: array de UM item — a forma que
                    // `ContratoPdfService::montarDados()` já espera.
                    // Congelamento deliberado: valor lido ao vivo já zerou
                    // contrato neste projeto (`hs_mrr = 0` do HubSpot).
                    //
                    // Quick 260819-guy (2026-08-19) — dia_vencimento e
                    // data_primeira_parcela ENTRAM no snapshot congelado
                    // aqui, na hora de CRIAR (não são lidos ao vivo depois,
                    // em GerarContratoAssinaturaJob): são dado de SERVIÇO
                    // (mesma disciplina de valor_contratado/data_contratacao/
                    // data_vencimento acima, D-04), diferente de `endereco`
                    // (dado de EMPRESA, lido ao vivo em
                    // GerarContratoAssinaturaJob — não pertence a este
                    // snapshot).
                    'servicos_snapshot' => [[
                        'servico'          => $contratoServico->servico->nome,
                        'valor_contratado' => (float) $contratoServico->valor_contratado,
                        'data_contratacao' => optional($contratoServico->data_contratacao)->format('Y-m-d'),
                        'data_vencimento'  => optional($contratoServico->data_vencimento)->format('Y-m-d'),
                        'dia_vencimento'        => $contratoServico->dia_vencimento,
                        'data_primeira_parcela' => optional($contratoServico->data_primeira_parcela)->format('Y-m-d'),
                    ]],
                ]);
            } catch (QueryException $e) {
                // Precedente de NpsController.php:1835 / NpsGrupoController.php:301,
                // copiado literalmente. ⚠️ `getCode()` é o SQLSTATE, igual em
                // MariaDB e SQLite — `errorInfo[1] === 1062` é específico do
                // MySQL/MariaDB e se comportaria diferente nos testes.
                if ((string) $e->getCode() === '23000') {
                    $pulados[] = ['servico_id' => $contratoServico->servico_id, 'motivo' => 'ja_em_andamento'];

                    continue;
                }

                throw $e;
            }

            $criados[] = $contrato->id;

            // O `catch` acima envolve o INSERT, não o dispatch — a violação
            // acontece no INSERT, antes de qualquer job existir, o que
            // reforça o Success Criteria 1. O delay escalonado é CAMADA
            // ADICIONAL para não lançar N jobs no mesmo segundo; não
            // substitui o bucket global 'clicksign-envelope' (a conta
            // inteira compartilha o mesmo rate limit — duas empresas
            // diferentes disparando ao mesmo tempo estouram igual).
            //
            // Quick 260820-my3 — fila `high`. Incidente em produção
            // (2026-08-20): o contrato da Maderatto ficou mais de uma hora
            // em `rascunho` sem nada criado porque o job foi para a fila
            // `default`, atrás de dezenas de `SyncMlAcervoCompanyJob`
            // (sync de acervo, pesado e sem urgência humana) que estavam
            // em deadlock e retentando. Gerar contrato é ação HUMANA e
            // sensível a tempo — não pode competir com sync em lote pela
            // mesma fila. `->onQueue('high')` não tem relação com o
            // `->delay($i * 5)` abaixo: o delay é o espaçamento por
            // serviço por causa do bucket de 1 envelope/min da Clicksign
            // (comentário acima), a fila é só POR ONDE o job entra.
            GerarContratoAssinaturaJob::dispatch($contrato)
                ->onQueue('high')
                ->delay(now()->addSeconds($i * 5));
        }

        // `ok` deixa de ser `true` quando a duplicidade impediu QUALQUER
        // criação — um "sucesso" que não criou nada é exatamente o silêncio
        // que causou o incidente da Mons Bike (quick 260821-l8n). Se pelo
        // menos um outro serviço da mesma empresa gerou contrato normalmente,
        // `ok` continua `true` — a duplicidade não afetou o resultado geral.
        $duplicidadeImpediuTudo = $criados === [] && collect($pulados)->contains('motivo', 'servicos_duplicados');

        return ['ok' => ! $duplicidadeImpediuTudo, 'faltando' => [], 'criados' => $criados, 'pulados' => $pulados];
    }

    /**
     * Leitura pura (sem efeito colateral, nunca cria nada) dos `servico_id`
     * com mais de um `ContratoServico` ATIVO — mesma regra da guarda de
     * `iniciarParaEmpresa()` (quick 260821-l8n), exposta para
     * `ContratoAdminController::show()` explicar o bloqueio ANTES do
     * clique, sem precisar chamar `iniciarParaEmpresa()` (que teria efeito
     * colateral) só para descobrir isso.
     *
     * @return array<int, int> servico_id => quantidade de ContratoServico ativos
     */
    public function servicosDuplicados(Company $company): array
    {
        return $company->contratosServico()->where('ativo', true)->get()
            ->countBy('servico_id')
            ->filter(fn (int $qtd) => $qtd > 1)
            ->all();
    }
}
