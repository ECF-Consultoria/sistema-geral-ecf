<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Servico;
use App\Services\Fechamento\FechamentoFaixaResolver;
use App\Services\Fechamento\FechamentoRegraTabela;
use App\Services\Fechamento\FechamentoRollupService;
use App\Support\CobrancaCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fase 141 (plano 05) — a conferência que precede a virada da chave
 * `FechamentoRegraTabela::CHAVE`: mostra, EMPRESA A EMPRESA e GRUPO A
 * GRUPO, quanto se cobra HOJE (regra antiga) e quanto se cobraria pela
 * regra NOVA (D-01/D-02/D-03 do CONTEXT), sem escrever nada em lugar
 * nenhum.
 *
 * Calcula os dois lados NO MESMO PROCESSO, via
 * `FechamentoRegraTabela::forcar()` — nunca exige dois processos
 * separados nem toca a flag persistida. `forcar(null)` no `finally`
 * devolve o leitor ao estado real, sempre — inclusive se uma exceção
 * estourar no meio do cálculo.
 *
 * O CÁLCULO por linha (empresa e grupo) é ESPELHO deliberado dos Passos 3
 * e 5 de `ConsolidarMesFechamento` — mesma precedência de cobrança
 * (`CobrancaCalculator::novo()` para o lado ANTES, `mensalidade()` para o
 * lado DEPOIS), mesma regra de âncora de grupo. Este é a TERCEIRA cópia da
 * montagem de linha de grupo (comando de consolidação, tela administrativa
 * e agora este relatório) — divergência silenciosa entre cópias só
 * aparece na fatura do cliente, por isso a montagem de grupo vive num
 * método único (`linhaDeGrupo()`) e a Tarefa 2 trava por teste que o lado
 * DEPOIS deste relatório é, campo a campo, o `cobranca_mensal`/
 * `faixa_ordem`/`faturamento_total` que `fechamento:consolidar-mes` de
 * fato grava (e o lado ANTES, o que a mesma consolidação grava com a flag
 * desligada).
 *
 * NÃO faz: gate de cobertura (Passo 6), aviso de mudança de faixa (Passo
 * 8), evolução mês a mês, congelamento. É leitura pura — a conferência
 * OFICIAL pós-consolidação continua sendo a reconsulta direta às tabelas
 * de snapshot (`.planning/learnings/desempenho-bonificacao.md` §4) e
 * `fechamento:verificar-consolidacao`. Este comando responde uma pergunta
 * ANTERIOR a essa: "quanto vai mudar, e para quem, se eu virar a chave
 * agora?"
 *
 * ⚠️ O comando NÃO ESCREVE NADA — nem tabela de faixa, nem snapshot, nem a
 * flag. `forcar()` não persiste (ver docblock de
 * `FechamentoRegraTabela::forcar()`).
 */
class CompararMensalidadeFechamento extends Command
{
    protected $signature = 'fechamento:comparar-mensalidade
        {--mes= : YYYY-MM (default = mês corrente)}
        {--json : saída em JSON, numa linha só, para conferência automatizada}
        {--todas : lista todas as linhas, não só as que mudam}';

    protected $description = 'Compara, empresa a empresa e grupo a grupo, quanto se cobra HOJE e quanto se cobraria pela regra nova — leitura pura, não escreve nada.';

    private const TOLERANCIA = 0.01;

    private FechamentoFaixaResolver $faixaResolver;

    public function __construct(
        private FechamentoRollupService $rollupService,
        private FechamentoRegraTabela $regra,
    ) {
        parent::__construct();

        // ⚠️ NUNCA deixar o container resolver `FechamentoFaixaResolver`
        // sozinho (`app(FechamentoFaixaResolver::class)`) — ele receberia a
        // SUA PRÓPRIA instância de `FechamentoRegraTabela`, diferente desta
        // ($this->regra), e `forcar()` chamado aqui não teria NENHUM efeito
        // sobre `paraEmpresa()`/`paraGrupo()` (cada um leria a flag
        // persistida, sempre desligada — o resolver nunca veria o lado
        // DEPOIS). Construído manualmente com a MESMA instância: é isso
        // que faz `forcar()` valer para os dois cálculos (cobrança E
        // régua) no mesmo processo, que é o propósito único de existir de
        // `forcar()` (ver docblock em `FechamentoRegraTabela`).
        $this->faixaResolver = new FechamentoFaixaResolver($this->regra);
    }

    public function handle(): int
    {
        $mesOption = $this->option('mes');

        if ($mesOption) {
            try {
                // Mesma âncora explícita no dia 1 de ConsolidarMesFechamento
                // — nunca 'Y-m' sem o dia (estoura pro mês seguinte quando o
                // mês alvo tem menos dias que hoje).
                $mes = Carbon::createFromFormat('Y-m-d', $mesOption.'-01')->startOfMonth();
            } catch (\Throwable $e) {
                $this->error("[CompararMensalidade] Formato inválido para --mes: '{$mesOption}' (esperado YYYY-MM).");

                return self::FAILURE;
            }
        } else {
            $mes = Carbon::now()->startOfMonth();
        }

        $mesLabel = $mes->format('Y-m');

        // Mesmo eager loading de ConsolidarMesFechamento — nunca N+1 no
        // laço de ~201 empresas em produção.
        $companies = Company::where('active', true)
            ->with([
                'contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico'),
                'grupo',
            ])
            ->get();

        // Os dois lados no MESMO processo, sempre devolvendo o leitor ao
        // estado real no final — inclusive em caso de exceção.
        try {
            $ladoAntes  = $this->calcularLado(false, $companies, $mesLabel);
            $ladoDepois = $this->calcularLado(true, $companies, $mesLabel);
        } finally {
            $this->regra->forcar(null);
        }

        $relatorio = $this->montarComparativo($mesLabel, $ladoAntes, $ladoDepois);

        if ($this->option('json')) {
            $this->line(json_encode($relatorio, JSON_UNESCAPED_UNICODE));
        } else {
            $this->imprimirRelatorioHumano($relatorio);
        }

        return self::SUCCESS;
    }

    /**
     * Calcula um lado inteiro (empresas + grupos) sob o regime `$regraNova`
     * — espelho dos Passos 3 e 5 de `ConsolidarMesFechamento`, sem estado
     * (`ESTADO_*`), evolução nem gate: este relatório não congela nada, só
     * precisa dos três campos que a consolidação grava
     * (`faturamento_total`, `faixa_ordem`/`faixa_label`, `cobranca_mensal`)
     * mais a origem da régua para exibição.
     *
     * @return array{empresas: array<int, array>, grupos: array<int, array>}
     */
    private function calcularLado(bool $regraNova, Collection $companies, string $mesLabel): array
    {
        $this->regra->forcar($regraNova);

        $rollup = $this->rollupService->porEmpresa($mesLabel, $companies, somenteContratadas: $regraNova);

        $linhasEmpresa   = [];
        $faixaPorEmpresa = [];

        foreach ($companies as $company) {
            $fat = $rollup[$company->id] ?? ['faturamento_ml' => null, 'faturamento_shopee' => null, 'faturamento_total' => null];

            $faixaData = $this->faixaResolver->paraEmpresa($company);
            $faixaPorEmpresa[$company->id] = $faixaData;

            $classificacao = ($faixaData !== null && $fat['faturamento_total'] !== null)
                ? $this->faixaResolver->classificar((float) $fat['faturamento_total'], $faixaData['faixas'])
                : null;

            $temContratoMensal = $company->contratosServico->contains(
                fn ($c) => $c->ativo === true && $c->servico !== null && $c->servico->tipo_cobranca === Servico::TIPO_MENSAL
            );

            // Mesma precedência de cobrança de ConsolidarMesFechamento
            // (Passo 3) — a única forma do lado DEPOIS deste relatório
            // bater com o que a consolidação de fato grava.
            $cobranca = $regraNova
                ? CobrancaCalculator::mensalidade($classificacao, $company->contratosServico)
                : (($classificacao !== null || $temContratoMensal)
                    ? (CobrancaCalculator::novo($classificacao, $company->contratosServico) ?: null)
                    : null);

            $linhasEmpresa[$company->id] = [
                'company_id'         => $company->id,
                'company_name'       => $company->name,
                'faturamento_total'  => $fat['faturamento_total'],
                'regua'              => $this->reguaLabel($faixaData),
                'faixa_ordem'        => $classificacao['ordem'] ?? null,
                'faixa_label'        => $classificacao['label'] ?? null,
                'cobranca_mensal'    => $cobranca,
            ];
        }

        $linhasPorEmpresaId = collect($linhasEmpresa);

        $gruposMembros = $companies
            ->filter(fn (Company $c) => $c->company_group_id !== null && $linhasPorEmpresaId->has($c->id))
            ->groupBy('company_group_id');

        $linhasGrupo = [];

        foreach ($gruposMembros as $groupId => $membros) {
            $linhasGrupo[(int) $groupId] = $this->linhaDeGrupo($regraNova, (int) $groupId, $membros, $linhasPorEmpresaId, $faixaPorEmpresa);
        }

        return ['empresas' => $linhasEmpresa, 'grupos' => $linhasGrupo];
    }

    /**
     * Monta a linha de comparação de um GRUPO — espelho deliberado do
     * Passo 5 de `ConsolidarMesFechamento` (âncora = empresa-membro de
     * maior `faturamento_total`, empate pelo menor `id`; a tabela do grupo
     * classifica a SOMA dos membros). Método único para as DUAS chamadas
     * de `calcularLado()` (ANTES e DEPOIS) nunca divergirem entre si por
     * acidente — e para não abrir uma QUARTA cópia desta montagem (já são
     * três: consolidação, tela administrativa, e este relatório).
     *
     * @param  Collection<int, Company>  $membros
     * @param  Collection<int, array>  $linhasPorEmpresaId
     * @param  array<int, array|null>  $faixaPorEmpresa
     */
    private function linhaDeGrupo(bool $regraNova, int $groupId, Collection $membros, Collection $linhasPorEmpresaId, array $faixaPorEmpresa): array
    {
        $faturamentoTotal = null;

        foreach ($membros as $membro) {
            $linhaMembro = $linhasPorEmpresaId->get($membro->id);

            if ($linhaMembro !== null && $linhaMembro['faturamento_total'] !== null) {
                $faturamentoTotal = ($faturamentoTotal ?? 0.0) + (float) $linhaMembro['faturamento_total'];
            }
        }

        // Âncora: empresa-membro de maior faturamento_total (empate pelo
        // menor id) — é a tabela DELA que classifica a soma, quando o
        // grupo não tem tabela própria.
        $ancora = $membros->sort(function (Company $a, Company $b) use ($linhasPorEmpresaId) {
            $fatA = (float) ($linhasPorEmpresaId->get($a->id)['faturamento_total'] ?? -INF);
            $fatB = (float) ($linhasPorEmpresaId->get($b->id)['faturamento_total'] ?? -INF);

            if ($fatA === $fatB) {
                return $a->id <=> $b->id;
            }

            return $fatB <=> $fatA;
        })->first();

        $faixaGrupo = $ancora->grupo !== null
            ? $this->faixaResolver->paraGrupo($ancora->grupo, $ancora)
            : ($faixaPorEmpresa[$ancora->id] ?? null); // defensivo: nunca deixar de montar a linha

        $classificacaoGrupo = ($faixaGrupo !== null && $faturamentoTotal !== null)
            ? $this->faixaResolver->classificar($faturamentoTotal, $faixaGrupo['faixas'])
            : null;

        $todosContratosDoGrupo = $membros->flatMap(fn (Company $c) => $c->contratosServico);
        $temContratoMensalGrupo = $todosContratosDoGrupo->contains(
            fn ($c) => $c->ativo === true && $c->servico !== null && $c->servico->tipo_cobranca === Servico::TIPO_MENSAL
        );

        $cobrancaGrupo = $regraNova
            ? CobrancaCalculator::mensalidade($classificacaoGrupo, $todosContratosDoGrupo)
            : (($classificacaoGrupo !== null || $temContratoMensalGrupo)
                ? (CobrancaCalculator::novo($classificacaoGrupo, $todosContratosDoGrupo) ?: null)
                : null);

        return [
            'company_group_id'   => $groupId,
            'grupo_name'         => $ancora->grupo?->name,
            'faturamento_total'  => $faturamentoTotal,
            'regua'              => $this->reguaLabel($faixaGrupo),
            'faixa_ordem'        => $classificacaoGrupo['ordem'] ?? null,
            'faixa_label'        => $classificacaoGrupo['label'] ?? null,
            'cobranca_mensal'    => $cobrancaGrupo,
            'empresas_count'     => $membros->count(),
        ];
    }

    /**
     * Rótulo de exibição da régua a partir do shape de
     * `FechamentoFaixaResolver` — 'sem_tabela' quando `null`, que é
     * EXATAMENTE o critério "ficaria sem régua" do rodapé (D-04 do
     * CONTEXT: 127 das 201 empresas medidas em produção, até a
     * materialização do plano 141-03 rodar).
     */
    private function reguaLabel(?array $faixaData): string
    {
        if ($faixaData === null) {
            return 'sem_tabela';
        }

        return match ($faixaData['origem']) {
            'grupo'   => 'tabela_grupo',
            'propria' => 'tabela_propria',
            'servico' => 'tabela_servico',
            default   => $faixaData['origem'],
        };
    }

    /**
     * Combina os dois lados em linhas comparadas (empresa e grupo) mais o
     * resumo de risco do rodapé.
     */
    private function montarComparativo(string $mesLabel, array $ladoAntes, array $ladoDepois): array
    {
        $empresas = [];
        foreach ($ladoAntes['empresas'] as $companyId => $antes) {
            $depois = $ladoDepois['empresas'][$companyId] ?? null;

            if ($depois === null) {
                continue; // defensivo — os dois lados calculam sobre a MESMA coleção de empresas
            }

            $empresas[] = $this->montarLinhaComparada('empresa', $antes, $depois);
        }

        $grupos = [];
        foreach ($ladoAntes['grupos'] as $groupId => $antes) {
            $depois = $ladoDepois['grupos'][$groupId] ?? null;

            if ($depois === null) {
                continue; // defensivo — mesma razão acima
            }

            $grupos[] = $this->montarLinhaComparada('grupo', $antes, $depois);
        }

        return [
            'mes'       => $mesLabel,
            'gerado_em' => now()->toIso8601String(),
            'empresas'  => $empresas,
            'grupos'    => $grupos,
            'resumo'    => $this->montarResumo($empresas, $grupos),
        ];
    }

    private function montarLinhaComparada(string $tipo, array $antes, array $depois): array
    {
        $mudouFaturamento = $this->diferem($antes['faturamento_total'], $depois['faturamento_total']);
        $mudouRegua       = $antes['regua'] !== $depois['regua'];
        $mudouFaixa       = $antes['faixa_ordem'] !== $depois['faixa_ordem'];
        $mudouValor       = $this->diferem($antes['cobranca_mensal'], $depois['cobranca_mensal']);

        $mudou = $mudouFaturamento || $mudouRegua || $mudouFaixa || $mudouValor;

        $diferenca = ($antes['cobranca_mensal'] !== null && $depois['cobranca_mensal'] !== null)
            ? round($depois['cobranca_mensal'] - $antes['cobranca_mensal'], 2)
            : null;

        return [
            'tipo'      => $tipo,
            'id'        => $tipo === 'empresa' ? $antes['company_id'] : $antes['company_group_id'],
            'nome'      => $tipo === 'empresa' ? $antes['company_name'] : $antes['grupo_name'],
            'antes'     => [
                'faturamento_total' => $antes['faturamento_total'],
                'regua'             => $antes['regua'],
                'faixa_ordem'       => $antes['faixa_ordem'],
                'faixa_label'       => $antes['faixa_label'],
                'cobranca_mensal'   => $antes['cobranca_mensal'],
            ],
            'depois'    => [
                'faturamento_total' => $depois['faturamento_total'],
                'regua'             => $depois['regua'],
                'faixa_ordem'       => $depois['faixa_ordem'],
                'faixa_label'       => $depois['faixa_label'],
                'cobranca_mensal'   => $depois['cobranca_mensal'],
            ],
            'diferenca' => $diferenca,
            'mudou'     => $mudou,
            'motivo'    => $mudou ? $this->motivoMudanca($antes, $depois, $mudouFaturamento, $mudouRegua, $mudouFaixa, $mudouValor) : null,
        ];
    }

    private function diferem(?float $a, ?float $b): bool
    {
        if ($a === null || $b === null) {
            return $a !== $b;
        }

        return abs($a - $b) > self::TOLERANCIA;
    }

    /**
     * Texto pt-BR, sem jargão, do motivo da mudança — o que o CONTEXT pede
     * para não deixar a pessoa adivinhar: "faixa diferente por causa da
     * soma das plataformas? deixou de somar contrato? virou valor fixo?
     * ficou sem tabela?".
     */
    private function motivoMudanca(array $antes, array $depois, bool $mudouFaturamento, bool $mudouRegua, bool $mudouFaixa, bool $mudouValor): string
    {
        $motivos = [];

        if ($antes['regua'] !== 'sem_tabela' && $depois['regua'] === 'sem_tabela') {
            $motivos[] = 'ficou sem tabela (deixou de herdar a do serviço)';
        } elseif ($antes['regua'] === 'sem_tabela' && $depois['regua'] !== 'sem_tabela') {
            $motivos[] = 'passou a ter tabela';
        } elseif ($mudouRegua) {
            $motivos[] = "a régua aplicada mudou ({$antes['regua']} → {$depois['regua']})";
        }

        if ($mudouFaturamento) {
            $motivos[] = 'o faturamento somado das plataformas contratadas mudou';
        }

        if ($mudouFaixa && ! $mudouRegua) {
            $motivos[] = 'a faixa mudou por causa do faturamento somado';
        }

        if ($mudouValor && ! $mudouFaixa && ! $mudouRegua && ! $mudouFaturamento) {
            $motivos[] = 'deixou de somar (ou passou a somar) o valor de contrato por cima da faixa';
        }

        if ($motivos === []) {
            $motivos[] = 'o valor cobrado mudou';
        }

        return implode('; ', $motivos);
    }

    /**
     * Resumo de risco do rodapé — total a receber, contagens de
     * subida/queda/sem-tabela, e as 10 maiores quedas/altas em R$.
     */
    private function montarResumo(array $empresas, array $grupos): array
    {
        $todas = array_merge($empresas, $grupos);

        $totalAntes  = 0.0;
        $totalDepois = 0.0;
        $sobem       = 0;
        $descem      = 0;
        $ficamIguais = 0;
        $semRegua    = [];
        $mudamFaixa  = 0;
        $mudamValor  = 0;
        $deltas      = [];

        foreach ($todas as $linha) {
            if ($linha['antes']['cobranca_mensal'] !== null) {
                $totalAntes += $linha['antes']['cobranca_mensal'];
            }

            if ($linha['depois']['cobranca_mensal'] !== null) {
                $totalDepois += $linha['depois']['cobranca_mensal'];
            }

            if ($linha['depois']['regua'] === 'sem_tabela') {
                $semRegua[] = ['tipo' => $linha['tipo'], 'nome' => $linha['nome']];
            }

            if ($linha['antes']['faixa_ordem'] !== $linha['depois']['faixa_ordem']) {
                $mudamFaixa++;
            }

            if ($linha['diferenca'] === null) {
                continue;
            }

            if (abs($linha['diferenca']) <= self::TOLERANCIA) {
                $ficamIguais++;

                continue;
            }

            $mudamValor++;
            $deltas[] = ['tipo' => $linha['tipo'], 'nome' => $linha['nome'], 'diferenca' => $linha['diferenca']];

            if ($linha['diferenca'] > 0) {
                $sobem++;
            } else {
                $descem++;
            }
        }

        // ⚠️ "Maiores altas"/"maiores quedas" SÓ podem conter diferenças do
        // sinal correspondente — subida é `diferenca > 0`, queda é
        // `diferenca < 0`, nunca "a menos negativa de todas" nem "a menos
        // positiva de todas". Sem este filtro, um cenário em que TODA
        // empresa cai (real: rodada de produção de 2026-09-09) faz
        // `array_reverse()` devolver as quedas MENOS severas sob o rótulo
        // "Maiores altas" — um instrumento de decisão sobre cobrança de
        // ~200 clientes não pode inverter o sinal do número. Quando um dos
        // lados não tem nenhuma linha do sinal certo, a lista fica vazia
        // de propósito — `imprimirRelatorioHumano()` diz isso em palavras
        // (nunca preenche com o sinal errado).
        $quedas = array_values(array_filter($deltas, fn ($d) => $d['diferenca'] < 0));
        $altas  = array_values(array_filter($deltas, fn ($d) => $d['diferenca'] > 0));

        usort($quedas, fn ($a, $b) => $a['diferenca'] <=> $b['diferenca']); // mais negativa primeiro
        usort($altas, fn ($a, $b) => $b['diferenca'] <=> $a['diferenca']);  // mais positiva primeiro

        $maioresQuedas = array_slice($quedas, 0, 10);
        $maioresAltas  = array_slice($altas, 0, 10);

        return [
            'total_receber_antes'  => round($totalAntes, 2),
            'total_receber_depois' => round($totalDepois, 2),
            'diferenca_total'      => round($totalDepois - $totalAntes, 2),
            'sobem'                => $sobem,
            'descem'               => $descem,
            'ficam_iguais'         => $ficamIguais,
            'sem_regua_depois'     => [
                'quantidade' => count($semRegua),
                'nomes'      => $semRegua,
            ],
            'mudam_faixa'          => $mudamFaixa,
            'mudam_valor'          => $mudamValor,
            'maiores_quedas'       => array_values($maioresQuedas),
            'maiores_altas'        => array_values($maioresAltas),
        ];
    }

    /**
     * Saída CONVENIÊNCIA HUMANA — nenhum teste desta suíte pode depender
     * dela. O contrato real é `--json`.
     */
    private function imprimirRelatorioHumano(array $relatorio): void
    {
        $this->info(sprintf(
            '[CompararMensalidade] Competência %s — %d empresa(s), %d grupo(s) calculados.',
            $relatorio['mes'],
            count($relatorio['empresas']),
            count($relatorio['grupos'])
        ));

        $linhas = array_merge($relatorio['empresas'], $relatorio['grupos']);

        if (! $this->option('todas')) {
            $linhas = array_values(array_filter($linhas, fn ($l) => $l['mudou']));
        }

        if ($linhas === []) {
            $this->info('[CompararMensalidade] Nenhuma mudança entre as empresas/grupos calculados (use --todas para listar tudo).');
        } else {
            $rows = [];
            foreach ($linhas as $l) {
                $rows[] = [
                    $l['tipo'],
                    $l['nome'],
                    $this->fmtMoeda($l['antes']['faturamento_total']),
                    $this->fmtMoeda($l['depois']['faturamento_total']),
                    $l['antes']['regua'].' → '.$l['depois']['regua'],
                    ($l['antes']['faixa_ordem'] ?? '-').' → '.($l['depois']['faixa_ordem'] ?? '-'),
                    $this->fmtMoeda($l['antes']['cobranca_mensal']),
                    $this->fmtMoeda($l['depois']['cobranca_mensal']),
                    $l['diferenca'] !== null ? $this->fmtMoeda($l['diferenca']) : '-',
                ];
            }

            $this->table(
                ['Tipo', 'Nome', 'Faturamento ANTES', 'Faturamento DEPOIS', 'Régua ANTES → DEPOIS', 'Faixa ANTES → DEPOIS', 'Mensalidade ANTES', 'Mensalidade DEPOIS', 'Diferença'],
                $rows
            );
        }

        $resumo = $relatorio['resumo'];

        $this->line('');
        $this->info(sprintf(
            '[CompararMensalidade] Total a receber — ANTES %s · DEPOIS %s · diferença %s',
            $this->fmtMoeda($resumo['total_receber_antes']),
            $this->fmtMoeda($resumo['total_receber_depois']),
            $this->fmtMoeda($resumo['diferenca_total'])
        ));

        $this->info(sprintf(
            '[CompararMensalidade] Sobem: %d · Descem: %d · Ficam iguais: %d · Mudam de faixa: %d · Ficariam SEM TABELA: %d',
            $resumo['sobem'],
            $resumo['descem'],
            $resumo['ficam_iguais'],
            $resumo['mudam_faixa'],
            $resumo['sem_regua_depois']['quantidade']
        ));

        if ($resumo['sem_regua_depois']['quantidade'] > 0) {
            $this->warn('[CompararMensalidade] Ficariam SEM TABELA depois da virada: '.
                collect($resumo['sem_regua_depois']['nomes'])->pluck('nome')->implode(', '));
        }

        // ⚠️ Cada seção só lista o sinal correspondente (garantido em
        // `montarResumo()`) — quando não há nenhuma linha daquele sinal, o
        // texto PRECISA dizer isso em palavras. Nunca omitir a seção em
        // silêncio nem preencher com o sinal errado: quem lê rápido não
        // pode concluir "tem gente subindo" quando não tem ninguém.
        $this->line('');
        $this->info('[CompararMensalidade] Maiores quedas:');
        if ($resumo['maiores_quedas'] === []) {
            $this->line('  nenhuma empresa desce nesta comparação.');
        } else {
            foreach ($resumo['maiores_quedas'] as $q) {
                $this->line(sprintf('  %s — %s', $q['nome'], $this->fmtMoeda($q['diferenca'])));
            }
        }

        $this->line('');
        $this->info('[CompararMensalidade] Maiores altas:');
        if ($resumo['maiores_altas'] === []) {
            $this->line('  nenhuma empresa sobe nesta comparação.');
        } else {
            foreach ($resumo['maiores_altas'] as $a) {
                $this->line(sprintf('  %s — %s', $a['nome'], $this->fmtMoeda($a['diferenca'])));
            }
        }

        $this->warn('[CompararMensalidade] AVISO: este texto é conveniência operacional e o comando NÃO escreve nada — a conferência oficial, depois de consolidar de verdade, continua sendo a reconsulta ao banco.');
    }

    private function fmtMoeda(?float $valor): string
    {
        return $valor === null ? '-' : 'R$ '.number_format($valor, 2, ',', '.');
    }
}
