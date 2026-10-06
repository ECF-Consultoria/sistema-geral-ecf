<?php

namespace App\Services\Fechamento;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * TabelaDeContratoAssinadoService — quick 261006-gf5. Quando um contrato gerado pelo PRÓPRIO
 * sistema é assinado, a tabela progressiva da empresa deixa de ser presunção e passa a ser
 * tabela CONFIRMADA POR CONTRATO (`EmpresaFaixaFaturamento::ORIGEM_CONTRATO`).
 *
 * ### O problema que este serviço fecha (medido em produção em 2026-10-06)
 * A inteligência já existia pela METADE. Desde a Fase 141 / quick 260904-kwz, tabela de origem
 * `servico` conta como confirmada quando a empresa tem `ContratoAssinatura` com
 * `status = 'assinado'` — "é a tabela escrita nesse contrato que legitima a do serviço"
 * (`AdminController::fechamentoCompanyIdsComContratoAssinado()`). O que derrota a regra é a
 * materialização da Fase 141 (`fechamento:materializar-tabelas`, rodada em 2026-09-09 21:02):
 * ela copiou a tabela do serviço para `empresa_faixas_faturamento` com
 * `origem = 'presumida_servico'` — selo que, por decisão da Fase 141 (D-04/D-05), NUNCA pode
 * passar por confirmado. Resultado: empresa com contrato assinado pelo próprio sistema aparecia
 * como **presumida** (caso MADERATTO MÓVEIS #446, 7 faixas presumidas em 2026-09-09, contrato
 * assinado em 2026-08-27).
 *
 * ⚠️ **Isto não muda valor de cobrança nenhum nas empresas já materializadas**: as faixas
 * gravadas são as MESMAS que já estavam presumidas (vêm da mesma `servico_faixas_faturamento`).
 * Muda o SELO — de presunção para confirmada por contrato. Provado em
 * `Quick261006ValorDeCobrancaNaoMudaTest`.
 *
 * ### As faixas vêm do SERVIÇO, não do PDF
 * O documento assinado imprime a tabela do serviço (`servico_faixas_faturamento`) — ver
 * `TabelaEmpresaContratoController::modelosDePartida()`, que usa a mesma fonte como "modelo de
 * partida". Não há parsing de PDF aqui: a leitura do PDF é o caminho da Fase 140
 * (`ContratoTabelaProposta`), que depende de confirmação humana. Este serviço cobre o caso em
 * que o próprio sistema gerou o documento e, portanto, JÁ SABE qual tabela foi impressa.
 *
 * ### Quais serviços o contrato cobre
 * Pós D-06 (Fase 127-01) um `ContratoAssinatura` tem UM `servico_id` — mas desde o quick
 * 260901-gj7 ele pode ser o serviço DONO de um contrato COMBINADO (Mercado Livre + Shopee num
 * documento só), e desde o quick 260824-bte o `servicos_snapshot` tem uma entrada por FASE do
 * pagamento escalonado (duas linhas do MESMO serviço). As duas coisas são resolvidas em
 * `servicoIdsCobertos()`: o dono, mais os serviços combinados com ele cujo nome aparece no
 * snapshot congelado. A dedup é por `servico_id`, então N fases do mesmo serviço gravam UMA vez.
 *
 * ⛔ A gravação é delegada a `GravarTabelaEmpresaService::gravar()` — a porta ÚNICA de escrita em
 * `empresa_faixas_faturamento` (Fase 142 Plano 01). Nada de `create()` solto aqui: transação,
 * trava de precedência e trilha de auditoria moram lá, uma vez só.
 *
 * ⛔ A precedência de `FechamentoFaixaResolver::paraEmpresa()` (grupo vence empresa) NÃO é
 * consultada: empresa de grupo com tabela recebe a tabela própria igual, e o resolver continua
 * decidindo sozinho quem manda na cobrança. Consultar a precedência aqui criaria uma SEGUNDA
 * regra de precedência, divergente da do resolver.
 */
class TabelaDeContratoAssinadoService
{
    /** Gravou a tabela da empresa com `origem = contrato`. */
    public const MOTIVO_GRAVOU = 'gravou';

    /** Gravaria, mas a chamada foi em modo simulação (`--dry-run` do comando retroativo). */
    public const MOTIVO_SIMULADO = 'simulado';

    /** Ato humano vence: a empresa já tem tabela `manual` ou `contrato` — não sobrescreve. */
    public const MOTIVO_JA_CONFIRMADA = 'ja_confirmada';

    /** Contrato cobre serviços DIFERENTES com tabelas DIFERENTES — adivinhar é pior que presumir. */
    public const MOTIVO_TABELAS_DIVERGENTES = 'tabelas_divergentes';

    /** Nenhum serviço coberto tem faixa cadastrada (cobrança fixa) — não há tabela a gravar. */
    public const MOTIVO_SERVICO_SEM_FAIXAS = 'servico_sem_faixas';

    /** Contrato órfão de empresa (dado inconsistente) — nada a fazer. */
    public const MOTIVO_SEM_EMPRESA = 'sem_empresa';

    /** Contrato que não está `assinado` — só assinatura legitima a tabela. */
    public const MOTIVO_NAO_ASSINADO = 'nao_assinado';

    /** A gravação lançou — registrado em `Log::error` e devolvido como motivo, nunca propagado. */
    public const MOTIVO_FALHA = 'falha';

    /** `feito_de` da trilha de auditoria quando a assinatura do contrato é que gravou. */
    public const FEITO_DE = 'contrato_assinado';

    public function __construct(private GravarTabelaEmpresaService $gravador)
    {
    }

    /**
     * Aplica a regra para UM contrato assinado. Shape de retorno com as chaves SEMPRE presentes
     * (`null` quando não se aplica), para o chamador nunca precisar de coalesce.
     *
     * @param  bool  $aplicar  `false` = simulação: decide tudo igual e NÃO grava (é assim que o
     *                         `--dry-run` do comando retroativo reusa esta mesma regra, em vez de
     *                         ter uma segunda cópia dela).
     * @return array{motivo: string, gravou: bool, company_id: int|null, company_name: string|null, origem_anterior: string|null, faixas_antes: int, faixas_depois: int, servico_origem_id: int|null, servico_nome: string|null, servico_ids: array<int, int>, faixas: array<int, array{ordem:int, limite_superior:float|null, valor:float, valor_e_piso:bool}>}
     */
    public function aplicar(ContratoAssinatura $contrato, bool $aplicar = true): array
    {
        $company = $contrato->company;

        if ($contrato->status !== ContratoAssinatura::STATUS_ASSINADO) {
            return $this->resultado(self::MOTIVO_NAO_ASSINADO, $company);
        }

        if ($company === null) {
            Log::warning('[Fechamento] Contrato assinado sem empresa — tabela não gravada', [
                'contrato_assinatura_id' => $contrato->id,
            ]);

            return $this->resultado(self::MOTIVO_SEM_EMPRESA, null);
        }

        $linhasAtuais   = EmpresaFaixaFaturamento::where('company_id', $company->id)->ordenadas()->get();
        $origemAnterior = $linhasAtuais->first()->origem ?? null;

        // Ato humano vence sempre. `manual` é cadastro humano; `contrato` é confirmação humana
        // da leitura do documento (Fase 140) — ou uma gravação anterior DESTE serviço, caso em
        // que não há nada a refazer. Sobrescrever qualquer uma das duas reabriria em silêncio o
        // problema que a trava de precedência da Fase 141 (D-05) existe para fechar.
        if (in_array($origemAnterior, [
            EmpresaFaixaFaturamento::ORIGEM_MANUAL,
            EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
        ], true)) {
            Log::info('[Fechamento] Empresa já tem tabela confirmada — assinatura do contrato não sobrescreve', [
                'company_id'             => $company->id,
                'contrato_assinatura_id' => $contrato->id,
                'origem_atual'           => $origemAnterior,
            ]);

            return $this->resultado(self::MOTIVO_JA_CONFIRMADA, $company, $linhasAtuais, $origemAnterior);
        }

        $servicoIds = $this->servicoIdsCobertos($contrato);

        // Faixas por serviço coberto, já descartando quem não tem tabela nenhuma (cobrança
        // fixa) — o mesmo critério de "quem entra no catálogo" de
        // `TabelaEmpresaContratoController::modelosDePartida()`: "modelo de partida" pressupõe
        // ter algo para copiar.
        $faixasPorServico = (new Collection($servicoIds))
            ->mapWithKeys(fn (int $servicoId) => [
                $servicoId => ServicoFaixaFaturamento::where('servico_id', $servicoId)->ordenadas()->get(),
            ])
            ->filter(fn (Collection $faixas) => $faixas->isNotEmpty());

        if ($faixasPorServico->isEmpty()) {
            Log::info('[Fechamento] Contrato assinado de serviço sem tabela progressiva — nada a gravar', [
                'company_id'             => $company->id,
                'contrato_assinatura_id' => $contrato->id,
                'servico_ids'            => $servicoIds,
            ]);

            return $this->resultado(self::MOTIVO_SERVICO_SEM_FAIXAS, $company, $linhasAtuais, $origemAnterior, $servicoIds);
        }

        // Serviços DIFERENTES com tabelas DIFERENTES: não há resposta óbvia para "qual vale", e
        // adivinhar é pior que deixar a tabela como presumida — a tela mostra a presunção e o
        // humano decide. Tabelas IDÊNTICAS entre serviços combinados não são divergência: o
        // documento imprimiu a mesma régua duas vezes.
        $assinaturas = $faixasPorServico->map(fn (Collection $faixas) => $this->assinaturaDaTabela($faixas))->unique();

        if ($assinaturas->count() > 1) {
            Log::warning('[Fechamento] Contrato assinado cobre serviços com tabelas progressivas diferentes — tabela da empresa NÃO gravada', [
                'company_id'             => $company->id,
                'contrato_assinatura_id' => $contrato->id,
                'servico_ids'            => $faixasPorServico->keys()->map(fn ($id) => (int) $id)->all(),
            ]);

            return $this->resultado(self::MOTIVO_TABELAS_DIVERGENTES, $company, $linhasAtuais, $origemAnterior, $servicoIds);
        }

        // Serviço de origem: o DONO do contrato quando ele próprio tem tabela; senão o menor id
        // entre os cobertos com tabela — determinístico, nunca dependente da ordem de leitura do
        // banco. As tabelas são idênticas neste ponto, então a escolha só carimba a procedência.
        $servicoOrigemId = $faixasPorServico->has((int) $contrato->servico_id)
            ? (int) $contrato->servico_id
            : (int) $faixasPorServico->keys()->map(fn ($id) => (int) $id)->sort()->first();

        $faixas      = $this->achatarFaixas($faixasPorServico->get($servicoOrigemId));
        $servicoNome = Servico::query()->whereKey($servicoOrigemId)->value('nome');

        if (! $aplicar) {
            return $this->resultado(
                self::MOTIVO_SIMULADO,
                $company,
                $linhasAtuais,
                $origemAnterior,
                $servicoIds,
                $servicoOrigemId,
                $servicoNome,
                $faixas,
            );
        }

        $this->gravador->gravar(
            $company,
            $faixas,
            EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
            $servicoOrigemId,
            null,
            self::FEITO_DE,
        );

        Log::info('[Fechamento] Tabela da empresa confirmada pela assinatura do contrato', [
            'company_id'             => $company->id,
            'contrato_assinatura_id' => $contrato->id,
            'servico_origem_id'      => $servicoOrigemId,
            'origem_anterior'        => $origemAnterior,
            'faixas'                 => count($faixas),
        ]);

        return $this->resultado(
            self::MOTIVO_GRAVOU,
            $company,
            $linhasAtuais,
            $origemAnterior,
            $servicoIds,
            $servicoOrigemId,
            $servicoNome,
            $faixas,
            gravou: true,
        );
    }

    /**
     * Mesma regra de `aplicar()`, blindada: QUALQUER falha vira `Log::error` + motivo `falha`,
     * nunca uma exceção propagada.
     *
     * É esta a porta usada pelos dois jobs do Clicksign (`ProcessarEventoClicksignJob` e
     * `ReconciliarContratoClicksignJob`). Mesmo espírito do `try/catch` que protege o download
     * do PDF assinado (D-14 da Fase 129) e do `AdmanService::syncAll()`: a assinatura e a
     * liberação da empresa JÁ aconteceram e não podem ser desfeitas porque uma gravação de
     * tabela de cobrança falhou. Carimbar o selo é consequência, não pré-requisito.
     *
     * @return array<string, mixed>  mesmo shape de `aplicar()`
     */
    public function aplicarComSeguranca(ContratoAssinatura $contrato, bool $aplicar = true): array
    {
        try {
            return $this->aplicar($contrato, $aplicar);
        } catch (\Throwable $e) {
            Log::error('[Fechamento] Falha ao gravar a tabela da empresa a partir do contrato assinado (assinatura/liberação não afetadas)', [
                'contrato_assinatura_id' => $contrato->id,
                'company_id'             => $contrato->company_id,
                'erro'                   => $e->getMessage(),
            ]);

            return $this->resultado(self::MOTIVO_FALHA, $contrato->company);
        }
    }

    /**
     * Serviços que ESTE contrato cobre, deduplicados por `servico_id`.
     *
     * Parte sempre do `servico_id` do contrato (o DONO, pós quick 260901-gj7) e acrescenta os
     * serviços COMBINADOS com ele — aqueles com `contrato_junto_com_servico_id` apontando para o
     * dono — cujo nome aparece no `servicos_snapshot` CONGELADO. A conferência pelo nome do
     * snapshot é o que impede incluir um serviço combinado que não estava neste documento (o
     * agrupamento só acontece quando o dono TAMBÉM está ativo na empresa, condição avaliada na
     * CRIAÇÃO do contrato — reavaliá-la hoje leria o catálogo de agora, não o de então).
     *
     * A busca pelo nome é restrita ao conjunto pequeno de candidatos (dono + combinados dele),
     * nunca à tabela `servicos` inteira: nome de serviço não tem unicidade garantida no schema.
     *
     * @return array<int, int>
     */
    private function servicoIdsCobertos(ContratoAssinatura $contrato): array
    {
        $donoId = (int) $contrato->servico_id;

        $nomesDoSnapshot = (new Collection($contrato->servicos_snapshot ?? []))
            ->map(fn ($item) => is_array($item) ? ($item['servico'] ?? null) : null)
            ->filter()
            ->unique()
            ->all();

        $combinados = $nomesDoSnapshot === []
            ? []
            : Servico::query()
                ->where('contrato_junto_com_servico_id', $donoId)
                ->whereIn('nome', $nomesDoSnapshot)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        return array_values(array_unique(array_merge([$donoId], $combinados)));
    }

    /**
     * Assinatura comparável de uma tabela de faixas — é por ela que "duas tabelas diferentes" é
     * decidido. Valores formatados com 2 casas fixas de propósito: `decimal:2` devolve string em
     * alguns drivers e float em outros, e comparar os dois crus daria divergência falsa.
     */
    private function assinaturaDaTabela(Collection $faixas): string
    {
        return (string) json_encode(
            (new Collection($this->achatarFaixas($faixas)))
                ->map(fn (array $f) => [
                    $f['ordem'],
                    $f['limite_superior'] === null ? null : number_format($f['limite_superior'], 2, '.', ''),
                    number_format($f['valor'], 2, '.', ''),
                    $f['valor_e_piso'],
                ])
                ->all()
        );
    }

    /**
     * Achata as faixas do serviço no formato que `GravarTabelaEmpresaService::gravar()` espera —
     * mesmo shape de `TabelaEmpresaContratoController::achatarFaixas()`.
     *
     * @return array<int, array{ordem:int, limite_superior:float|null, valor:float, valor_e_piso:bool}>
     */
    private function achatarFaixas(Collection $faixas): array
    {
        return $faixas
            ->map(fn (ServicoFaixaFaturamento $f) => [
                'ordem'           => (int) $f->ordem,
                'limite_superior' => $f->limite_superior !== null ? (float) $f->limite_superior : null,
                'valor'           => (float) $f->valor,
                'valor_e_piso'    => (bool) $f->valor_e_piso,
            ])
            ->values()
            ->all();
    }

    /**
     * Monta o shape único de retorno, com as chaves sempre presentes.
     *
     * @param  array<int, int>  $servicoIds
     * @param  array<int, array{ordem:int, limite_superior:float|null, valor:float, valor_e_piso:bool}>  $faixas
     * @return array<string, mixed>
     */
    private function resultado(
        string $motivo,
        ?Company $company,
        ?Collection $linhasAtuais = null,
        ?string $origemAnterior = null,
        array $servicoIds = [],
        ?int $servicoOrigemId = null,
        ?string $servicoNome = null,
        array $faixas = [],
        bool $gravou = false,
    ): array {
        return [
            'motivo'            => $motivo,
            'gravou'            => $gravou,
            'company_id'        => $company?->id,
            'company_name'      => $company?->name,
            'origem_anterior'   => $origemAnterior,
            'faixas_antes'      => $linhasAtuais?->count() ?? 0,
            // "O que passa a ter": a tabela nova quando há uma decidida (gravada OU simulada no
            // dry-run), senão a contagem atual — é o que o relatório do comando retroativo
            // compara com `faixas_antes`.
            'faixas_depois'     => $faixas !== [] ? count($faixas) : ($linhasAtuais?->count() ?? 0),
            'servico_origem_id' => $servicoOrigemId,
            'servico_nome'      => $servicoNome,
            'servico_ids'       => $servicoIds,
            'faixas'            => $faixas,
        ];
    }
}
