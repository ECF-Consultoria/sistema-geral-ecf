<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoSnapshot;
use App\Models\ShopeeMetric;
use App\Services\Fechamento\FechamentoConferenciaFaturamentoService;
use App\Services\Fechamento\FechamentoEmpresasDoMes;
use App\Services\Fechamento\FechamentoSnapshotWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fase 137 (Plano 05, Tarefa 3) — conferência READ-ONLY de uma competência
 * do fechamento mensal, por RECONSULTA direta às tabelas de snapshot.
 *
 * POR QUE ESTE COMANDO EXISTE
 * (.planning/learnings/desempenho-bonificacao.md §4 e §10.1): o gate de
 * cobertura de `fechamento:consolidar-mes` recusa gravar amostra degradada
 * e reporta apenas uma CONTAGEM no stdout — os nomes só vão para
 * `Log::error`. A disciplina que este projeto já pagou caro para aprender é
 * que "o comando disse que deu certo" NUNCA é o critério de verificação.
 * NENHUMA linha do texto que este comando mesmo imprime é critério de
 * nada — o contrato real é a saída `--json` e o EXIT CODE. SUCCESS
 * (exit code 0) só acontece com ZERO inconsistências.
 *
 * READ-ONLY: nenhuma escrita, nenhum dispatch de job, e nenhuma chamada HTTP
 * — a não ser com `--com-api`, que é opt-in exatamente para a chamada
 * continuar sendo uma decisão de quem roda (Quick 261001-gi1). Um
 * verificador que corrige o que encontra esconderia a inconsistência em vez
 * de expô-la.
 *
 * ── As 5 classes de inconsistência ─────────────────────────────────────
 *
 *  SEM_SNAPSHOT — empresa ATIVA com integração financeira (`cust_id` ou
 *  pelo menos uma linha em `shopee_metrics`, qualquer data) sem linha em
 *  `fechamento_snapshots` na competência. Ação: re-rodar
 *  `fechamento:consolidar-mes --mes=` (ou investigar o `Log::error` do gate
 *  de cobertura, se o comando recusou o lote inteiro).
 *
 *  LINHAS_ORFAS — existe linha em `fechamento_grupo_snapshots` para um
 *  grupo, mas NENHUMA linha em `fechamento_snapshots` cujo
 *  `company_group_id` aponte para ele na mesma competência. Ação:
 *  reconsolidar — o grupo não tem detalhe por empresa que o sustente.
 *
 *  DIVERGENCIA_SOMA_GRUPO — `faturamento_total` do grupo diverge (tolerância
 *  0,01) da SOMA de `faturamento_total` das linhas de empresa daquele grupo
 *  na competência. D-10 exige que sejam a MESMA fonte — nunca recalculada
 *  em paralelo. Ação: investigar antes de confiar no número — pode ser
 *  reconsolidação parcial ou escrita fora do writer.
 *
 *  DIVERGENCIA_CONTAGEM — `empresas_count` do grupo diverge do número real
 *  de linhas de empresa daquele grupo na competência. Mesma ação acima.
 *
 *  FAIXA_MUDARIA (Quick 261001-gi1) — o faturamento de HOJE não cai mais na
 *  faixa congelada na linha, ou seja: a competência foi fechada com um
 *  número que mudou depois e a MENSALIDADE gravada está errada. Única classe
 *  de divergência de VALOR que derruba o exit code, porque é a única em que
 *  dinheiro muda. Ação: refazer a competência (ato humano, com motivo).
 *
 *  ORIGEM_NAO_CONGELADA — competência FECHADA (mês anterior ao corrente)
 *  com pelo menos uma linha (empresa ou grupo) cuja `origem` não é
 *  `consolidar_mes` — só essa origem representa o fechamento oficial nesta
 *  fase. Ação: reconsolidar a competência.
 *
 * ── Quick 261001-gi1: a conferência de NÚMEROS ─────────────────────────
 *
 * As cinco classes acima conferem ESTRUTURA. Desde o incidente de
 * 2026-10-01 (setembro consolidado às 10:23, sync da Shopee às 10:42
 * reescrevendo o mês de 19 empresas, descoberto à mão pelo usuário) o
 * comando também confere VALOR, via
 * `FechamentoConferenciaFaturamentoService` — o mesmo rollup da
 * consolidação, nunca uma segunda fórmula.
 *
 * O resultado sai em DOIS lugares, e a separação é o ponto:
 *
 * - `inconsistencias[FAIXA_MUDARIA]` → exit code 1. A faixa decide a
 *   cobrança; mudar de faixa é dinheiro errado.
 * - `avisos.faturamento` → NÃO mexe no exit code. Diferença que não muda
 *   faixa é informação: a Adman revisa dias passados todo dia (é por isso
 *   que existe `adman:reler-dias`) e um verificador que falha todo santo
 *   dia por centavos ensina a ignorar o verificador.
 *
 * ⛔ A conferência de valor NÃO conserta nada: nenhuma linha de fechamento é
 * escrita, aqui ou no serviço. Quem refaz a competência é uma pessoa.
 *
 * `--com-api` é a única forma de o comando fazer chamada HTTP, e é opt-in
 * justamente por isso.
 *
 * @see App\Services\Fechamento\FechamentoConferenciaFaturamentoService
 * @see App\Console\Commands\ConsolidarMesFechamento
 * @see App\Services\Fechamento\FechamentoSnapshotWriter
 */
class VerificarConsolidacaoFechamento extends Command
{
    protected $signature = 'fechamento:verificar-consolidacao
        {--mes= : YYYY-MM (default = mês anterior ao hoje)}
        {--json : saída em JSON, parseável, sem nenhum outro texto}
        {--com-api : confere o lado Mercado Livre chamando a Adman ao vivo (mais exato, mais lento — única opção que faz HTTP)}';

    protected $description = 'Confere uma competência do fechamento por RECONSULTA (read-only): estrutura das linhas e faturamento gravado x atual. O exit code é o veredito, nunca o texto impresso.';

    private const TOLERANCIA_SOMA_GRUPO = 0.01;

    public function __construct(
        private FechamentoConferenciaFaturamentoService $conferencia,
        private FechamentoEmpresasDoMes $empresasDoMes,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $mesOption = $this->option('mes');

        if ($mesOption) {
            try {
                // Mesma regra ancorada no dia 1 explícito de
                // ConsolidarMesFechamento — nunca formato sem o dia.
                $mes = Carbon::createFromFormat('Y-m-d', $mesOption.'-01')->startOfMonth();
            } catch (\Throwable $e) {
                $this->error("[VerificarConsolidacao] Formato inválido para --mes: '{$mesOption}' (esperado YYYY-MM).");

                return self::FAILURE;
            }
        } else {
            $mes = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        }

        $mesStr     = $mes->toDateString();
        $mesLabel   = $mes->format('Y-m');
        $mesFechado = $mes->lt(Carbon::now()->startOfMonth());

        $relatorio = $this->montarRelatorio($mesStr, $mesLabel, $mesFechado);

        if ($this->option('json')) {
            $this->line(json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->imprimirRelatorioHumano($relatorio);
        }

        return $relatorio['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Monta o relatório inteiro por RECONSULTA — nenhuma escrita.
     */
    private function montarRelatorio(string $mesStr, string $mesLabel, bool $mesFechado): array
    {
        // Quick 261001-gi1 — a conferência de VALOR, pelo mesmo rollup que a
        // consolidação usa. Read-only; HTTP só com --com-api.
        $faturamento = $this->conferencia->conferir($mesLabel, (bool) $this->option('com-api'));

        // Empresas ativas com integração financeira — mesma definição de
        // "tem_integracao" usada por ConsolidarMesFechamento::handle().
        $companyIdsComShopee = ShopeeMetric::query()->distinct()->pluck('company_id')->flip();

        $ativasComIntegracao = Company::query()
            ->where('active', true)
            ->with(['contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico')])
            ->get()
            ->filter(fn (Company $c) => $c->cust_id !== null || $companyIdsComShopee->has($c->id));

        // Quem é elegível é decidido pelo PONTO ÚNICO de "quem entra no mês"
        // (`FechamentoEmpresasDoMes`), o mesmo que a consolidação usa. Sem isto o
        // comando acusava SEM_SNAPSHOT para quem CORRETAMENTE não tem linha:
        // empresa marcada como "não participa do fechamento" (quick 260916-onn —
        // em 01/10/2026 eram RELOJOARIA WENUS e DSG VARIEDADES pelo grupo, e
        // Rações Soldera pela própria empresa) e empresa cujo contrato começou
        // depois do mês (quick 260915-jpr). Resultado: exit 1 em TODA
        // competência, e alarme que vive aceso ensina a ignorar justamente o mês
        // em que o número está errado.
        $empresasElegiveis = $this->empresasDoMes->filtrar($ativasComIntegracao, $mesLabel);

        $snapshotsEmpresa      = FechamentoSnapshot::query()->whereDate('mes_referencia', $mesStr)->get();
        $snapshotsEmpresaPorId = $snapshotsEmpresa->keyBy('company_id');

        $snapshotsGrupo = FechamentoGrupoSnapshot::query()->whereDate('mes_referencia', $mesStr)->get();

        $achados = [
            'SEM_SNAPSHOT'            => [],
            'LINHAS_ORFAS'            => [],
            'DIVERGENCIA_SOMA_GRUPO'  => [],
            'DIVERGENCIA_CONTAGEM'    => [],
            'FAIXA_MUDARIA'           => [],
            'ORIGEM_NAO_CONGELADA'    => [],
        ];

        // ── FAIXA_MUDARIA (Quick 261001-gi1) ─────────────────────────────
        // Só a mudança de FAIXA entra aqui. A divergência de valor que não
        // muda faixa vai para `avisos` lá embaixo, sem tocar no exit code.
        foreach ($faturamento['divergentes'] as $divergente) {
            if ($divergente['faixa_mudaria'] !== true) {
                continue;
            }

            $achados['FAIXA_MUDARIA'][] = [
                'company_id'    => $divergente['company_id'],
                'company_name'  => $divergente['company_name'],
                'gravado'       => $divergente['gravado'],
                'atual'         => $divergente['atual'],
                'diferenca'     => $divergente['diferenca'],
                'faixa_gravada' => $divergente['faixa_gravada'],
                'causa'         => $divergente['causa'],
            ];
        }

        // ── SEM_SNAPSHOT ──────────────────────────────────────────────
        foreach ($empresasElegiveis as $company) {
            if (! $snapshotsEmpresaPorId->has($company->id)) {
                $achados['SEM_SNAPSHOT'][] = [
                    'company_id'   => $company->id,
                    'company_name' => $company->name,
                ];
            }
        }

        // ── LINHAS_ORFAS / DIVERGENCIA_SOMA_GRUPO / DIVERGENCIA_CONTAGEM ─
        foreach ($snapshotsGrupo as $grupoSnap) {
            $membros = $snapshotsEmpresa->where('company_group_id', $grupoSnap->company_group_id);

            if ($membros->isEmpty()) {
                $achados['LINHAS_ORFAS'][] = [
                    'company_group_id' => $grupoSnap->company_group_id,
                    'grupo_name'       => $grupoSnap->grupo_name,
                ];

                // Sem membro nenhum, soma/contagem não têm o que comparar —
                // a linha órfã já cobre o problema real.
                continue;
            }

            $somaMembros = (float) $membros->sum(fn ($m) => $m->faturamento_total !== null ? (float) $m->faturamento_total : 0.0);
            $totalGrupo  = $grupoSnap->faturamento_total !== null ? (float) $grupoSnap->faturamento_total : 0.0;

            if (abs($totalGrupo - $somaMembros) > self::TOLERANCIA_SOMA_GRUPO) {
                $achados['DIVERGENCIA_SOMA_GRUPO'][] = [
                    'company_group_id'  => $grupoSnap->company_group_id,
                    'grupo_name'        => $grupoSnap->grupo_name,
                    'faturamento_grupo' => $totalGrupo,
                    'soma_membros'      => $somaMembros,
                ];
            }

            if ((int) $grupoSnap->empresas_count !== $membros->count()) {
                $achados['DIVERGENCIA_CONTAGEM'][] = [
                    'company_group_id' => $grupoSnap->company_group_id,
                    'grupo_name'       => $grupoSnap->grupo_name,
                    'empresas_count'   => (int) $grupoSnap->empresas_count,
                    'membros_reais'    => $membros->count(),
                ];
            }
        }

        // ── ORIGEM_NAO_CONGELADA — só se aplica a competência FECHADA ────
        if ($mesFechado) {
            foreach ($snapshotsEmpresa as $snap) {
                if ($snap->origem !== FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES) {
                    $achados['ORIGEM_NAO_CONGELADA'][] = [
                        'tipo'         => 'empresa',
                        'company_id'   => $snap->company_id,
                        'company_name' => $snap->company_name,
                        'origem'       => $snap->origem,
                    ];
                }
            }

            foreach ($snapshotsGrupo as $grupoSnap) {
                if ($grupoSnap->origem !== FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES) {
                    $achados['ORIGEM_NAO_CONGELADA'][] = [
                        'tipo'              => 'grupo',
                        'company_group_id'  => $grupoSnap->company_group_id,
                        'grupo_name'        => $grupoSnap->grupo_name,
                        'origem'            => $grupoSnap->origem,
                    ];
                }
            }
        }

        $inconsistencias = [];
        foreach ($achados as $classe => $entidades) {
            if ($entidades !== []) {
                $inconsistencias[] = [
                    'classe'     => $classe,
                    'quantidade' => count($entidades),
                    'entidades'  => $entidades,
                ];
            }
        }

        return [
            'mes'             => $mesLabel,
            'mes_referencia'  => $mesStr,
            'mes_fechado'     => $mesFechado,
            'gerado_em'       => now()->toIso8601String(),
            'total_empresas'  => $empresasElegiveis->count(),
            'total_grupos'    => $snapshotsGrupo->count(),
            'inconsistencias' => $inconsistencias,
            // Quick 261001-gi1 — chave NOVA, e de propósito fora de
            // `inconsistencias`: `ok`/exit code continua significando
            // exatamente o que significava antes mais a mudança de faixa.
            'avisos'          => [
                'faturamento' => $faturamento,
            ],
            'ok'              => $inconsistencias === [],
        ];
    }

    /**
     * Saída CONVENIÊNCIA HUMANA — nenhum teste desta suíte pode depender
     * dela. O contrato real é `--json` + exit code.
     */
    private function imprimirRelatorioHumano(array $relatorio): void
    {
        $this->info(sprintf(
            '[VerificarConsolidacao] Competência %s (%s) — %d empresa(s) elegível(is), %d grupo(s).',
            $relatorio['mes'],
            $relatorio['mes_fechado'] ? 'fechada' : 'em curso',
            $relatorio['total_empresas'],
            $relatorio['total_grupos']
        ));

        $this->imprimirConferenciaDeFaturamento($relatorio['avisos']['faturamento']);

        if ($relatorio['ok']) {
            $this->info('[VerificarConsolidacao] Nenhuma inconsistência encontrada.');

            return;
        }

        $rows = [];
        foreach ($relatorio['inconsistencias'] as $inc) {
            $rows[] = [$inc['classe'], $inc['quantidade']];
        }

        $this->table(['Classe', 'Quantidade'], $rows);

        $this->warn('[VerificarConsolidacao] AVISO: esta tabela é CONVENIÊNCIA HUMANA. A conferência OFICIAL é o EXIT CODE (0 = sem inconsistências) ou a saída --json — nunca este texto.');
    }

    /**
     * Quick 261001-gi1 — saída enxuta da conferência de valor: uma linha por
     * empresa divergente e um resumo com quantas mudariam de faixa.
     *
     * CONVENIÊNCIA HUMANA, como o resto do texto deste comando: nenhum teste
     * depende destas linhas.
     */
    private function imprimirConferenciaDeFaturamento(array $f): void
    {
        if (! $f['congelado']) {
            $this->line('[VerificarConsolidacao] Competência sem fechamento gravado — nada a conferir no faturamento.');

            return;
        }

        $fechadoEm = $f['fechado_em'] !== null
            ? Carbon::parse($f['fechado_em'])->format('d/m/Y H:i')
            : 'hora não registrada';

        $this->line(sprintf(
            '[VerificarConsolidacao] Fechamento gravado em %s — %d de %d linha(s) conferida(s)%s.',
            $fechadoEm,
            $f['linhas_conferidas'],
            $f['linhas_gravadas'],
            $f['com_api'] ? ' (lado Mercado Livre conferido ao vivo)' : ''
        ));

        if ($f['dado_atualizado_em'] !== null) {
            $this->line(sprintf(
                '[VerificarConsolidacao] Dado mais recente deste mês gravado em %s%s.',
                Carbon::parse($f['dado_atualizado_em'])->format('d/m/Y H:i'),
                $f['dado_mudou_depois'] ? ' — DEPOIS do fechamento' : ''
            ));
        }

        if ($f['divergentes'] === []) {
            $this->info('[VerificarConsolidacao] Faturamento: tudo bate com o que está gravado.');
        } else {
            foreach ($f['divergentes'] as $d) {
                $this->line(sprintf(
                    '  %s #%d (%s): gravado %s | atual %s | diferença %s%s%s',
                    $d['faixa_mudaria'] === true ? 'MUDA DE FAIXA' : 'difere',
                    $d['company_id'],
                    $d['company_name'],
                    number_format((float) ($d['gravado'] ?? 0), 2, ',', '.'),
                    number_format((float) ($d['atual'] ?? 0), 2, ',', '.'),
                    number_format((float) ($d['diferenca'] ?? 0), 2, ',', '.'),
                    ' | origem da diferença: '.$d['causa'],
                    $d['ml_conferido'] ? '' : ' | lado Mercado Livre NÃO conferido'
                ));
            }

            $this->line(sprintf(
                '[VerificarConsolidacao] Faturamento: %d empresa(s) com valor diferente do gravado; %d mudaria(m) de faixa.',
                $f['total_divergentes'],
                $f['total_faixas_mudariam']
            ));
        }

        if ($f['nao_comparaveis'] !== []) {
            $nomes = collect($f['nao_comparaveis'])
                ->map(fn ($n) => $n['company_name'].' ('.$n['motivo'].')')
                ->take(10)
                ->implode(', ');

            $this->line(sprintf(
                '[VerificarConsolidacao] %d linha(s) não deu(ram) para conferir: %s%s. Com --com-api a conferência do lado Mercado Livre fica exata.',
                count($f['nao_comparaveis']),
                $nomes,
                count($f['nao_comparaveis']) > 10 ? ', ...' : ''
            ));
        }
    }
}
