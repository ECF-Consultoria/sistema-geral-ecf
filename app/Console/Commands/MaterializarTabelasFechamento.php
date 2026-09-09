<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\EmpresaFaixaFaturamento;
use App\Services\Fechamento\FechamentoFaixaResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fase 141 Plano 03 (D-04/D-05 do CONTEXT) — a PONTE de transição do fechamento por tabela do
 * serviço para o fechamento por tabela da empresa.
 *
 * Medido em produção em 2026-09-09: 127 das 201 empresas são classificadas hoje pela tabela do
 * SERVIÇO (`FechamentoFaixaResolver::paraEmpresa()` devolve `origem = 'servico'`), e NENHUMA tem
 * tabela própria cadastrada. D-04 tira a tabela do serviço de circulação como régua — aplicar isso
 * sem mais nada faz as 127 virarem "A DEFINIR" e a ECF fica sem saber quanto cobrar. Este comando
 * copia, para cada uma dessas empresas, a tabela do serviço que JÁ está sendo aplicada a ela, como
 * tabela DELA, carimbada com `origem = 'presumida_servico'` (D-04/D-05) — os valores são idênticos
 * aos de hoje, então rodar isto NÃO muda nenhuma cobrança; só transforma uma presunção invisível em
 * dado explícito.
 *
 * ⚠️ (1) O texto impresso (`$this->info()`) é conveniência operacional — a conferência oficial é a
 * RECONSULTA ao banco (`empresa_faixas_faturamento` por `origem`), nunca o stdout desta rodada.
 * Disciplina registrada em `.planning/learnings/desempenho-bonificacao.md`.
 *
 * ⚠️ (2) Este comando NUNCA lê nem grava `fechamento_snapshots`/`fechamento_grupo_snapshots` — copiar
 * a tabela da empresa agora não reescreve nenhuma competência já congelada (D-11 da Fase 137).
 *
 * ⚠️ (3) Este comando grava a MESMA régua que já está sendo aplicada à empresa — a mensalidade dela
 * não muda por causa dele. O que muda a mensalidade é a flag que tira a tabela do serviço de
 * circulação (plano 141-04), não esta materialização.
 *
 * Dry-run é o PADRÃO — precisa de `--aplicar` explícito para gravar. Guard de idempotência: uma
 * empresa que já tem QUALQUER linha própria (de qualquer origem — manual, contrato ou já
 * materializada antes) NUNCA é sobrescrita por este comando; sobrescrever uma tabela confirmada por
 * humano com uma presunção seria reintroduzir, em silêncio, exatamente o problema que a Fase 141
 * existe para resolver.
 */
class MaterializarTabelasFechamento extends Command
{
    protected $signature = 'fechamento:materializar-tabelas
        {--aplicar : grava de fato (sem esta opção, só mostra o que faria)}
        {--json : saída em JSON para conferência}';

    protected $description = 'Copia, para cada empresa hoje classificada pela tabela do serviço, essa mesma tabela como tabela própria (origem presumida_servico) — a ponte de transição da Fase 141 (D-04/D-05).';

    public function __construct(private FechamentoFaixaResolver $faixaResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        // Mesmo eager loading do `ConsolidarMesFechamento` — evita N+1 dentro do resolver.
        $companies = Company::where('active', true)
            ->with([
                'contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico'),
                'grupo',
            ])
            ->get();

        $materializar       = []; // company => resultado do resolver (origem 'servico')
        $jaTemPropria        = 0;
        $peloGrupo            = 0;
        $continuaSemTabela   = [];

        foreach ($companies as $company) {
            $resolvido = $this->faixaResolver->paraEmpresa($company);

            if ($resolvido === null) {
                $continuaSemTabela[] = $company;

                continue;
            }

            if ($resolvido['origem'] === 'propria') {
                $jaTemPropria++;

                continue;
            }

            if ($resolvido['origem'] === 'grupo') {
                $peloGrupo++;

                continue;
            }

            // origem === 'servico' — exatamente o balde que esta ponte materializa.
            $materializar[] = ['company' => $company, 'resolvido' => $resolvido];
        }

        $gravadas = 0;
        $falhas   = 0;

        if ($aplicar) {
            foreach ($materializar as $item) {
                /** @var Company $company */
                $company   = $item['company'];
                $resolvido = $item['resolvido'];

                try {
                    DB::transaction(function () use ($company, $resolvido, &$jaTemPropria, &$gravadas) {
                        // Guard de idempotência ANTES de criar: se já existe qualquer linha própria
                        // (gravada entre o cálculo acima e agora, ou por outra rodada concorrente),
                        // pula — nunca sobrescreve.
                        $jaExiste = EmpresaFaixaFaturamento::where('company_id', $company->id)->exists();

                        if ($jaExiste) {
                            $jaTemPropria++;

                            return;
                        }

                        foreach ($resolvido['faixas'] as $faixaServico) {
                            EmpresaFaixaFaturamento::create([
                                'company_id'        => $company->id,
                                'ordem'             => $faixaServico->ordem,
                                'limite_superior'   => $faixaServico->limite_superior,
                                'valor'             => $faixaServico->valor,
                                'valor_e_piso'      => $faixaServico->valor_e_piso,
                                'origem'            => EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO,
                                'servico_origem_id' => $resolvido['servico_id'],
                            ]);
                        }

                        $gravadas++;
                    });
                } catch (\Throwable $e) {
                    Log::error("[Fechamento] Falha ao materializar tabela da empresa {$company->id} ({$company->name}): {$e->getMessage()}");
                    $falhas++;
                }
            }
        }

        $this->relatorio($materializar, $continuaSemTabela, $jaTemPropria, $peloGrupo, $aplicar, $gravadas, $falhas);

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Imprime o relatório (pt-BR, sem jargão) — texto normal ou `--json` numa linha só. Conveniência
     * operacional (comentário (1) da classe) — nunca critério de verificação.
     */
    private function relatorio(
        array $materializar,
        array $continuaSemTabela,
        int $jaTemPropria,
        int $peloGrupo,
        bool $aplicar,
        int $gravadas,
        int $falhas,
    ): void {
        $listaMaterializar = array_map(fn (array $item) => [
            'company_id'   => $item['company']->id,
            'company_name' => $item['company']->name,
            'servico_id'   => $item['resolvido']['servico_id'],
            'servico_nome' => $item['resolvido']['servico_nome'],
            'faixas'       => $item['resolvido']['faixas']->count(),
        ], $materializar);

        $listaSemTabela = array_map(fn (Company $c) => [
            'company_id'   => $c->id,
            'company_name' => $c->name,
        ], $continuaSemTabela);

        if ($this->option('json')) {
            $this->line(json_encode([
                'aplicou'    => $aplicar,
                'contagens'  => [
                    'materializar'       => count($materializar),
                    'ja_tem_propria'     => $jaTemPropria,
                    'pelo_grupo'         => $peloGrupo,
                    'continua_sem_tabela' => count($continuaSemTabela),
                    'gravadas'           => $gravadas,
                    'falhas'             => $falhas,
                ],
                'materializar'        => $listaMaterializar,
                'continua_sem_tabela' => $listaSemTabela,
            ], JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->info($aplicar
            ? '[Fechamento] Materialização EXECUTADA — tabelas gravadas como tabela própria da empresa (origem presumida_servico).'
            : '[Fechamento] Simulação (dry-run) — nada foi gravado. Rode de novo com --aplicar para gravar.');

        $this->line(sprintf(
            'Ganhariam tabela própria: %d · já têm tabela própria: %d · classificadas pelo grupo: %d · continuam sem tabela: %d',
            count($materializar),
            $jaTemPropria,
            $peloGrupo,
            count($continuaSemTabela)
        ));

        if ($aplicar) {
            $this->line("Gravadas nesta rodada: {$gravadas} · falhas: {$falhas}");
        }

        if ($listaMaterializar !== []) {
            $this->line('');
            $this->line('Empresas que ganhariam (ou ganharam) tabela própria:');
            foreach ($listaMaterializar as $linha) {
                $this->line("  - {$linha['company_name']} (empresa {$linha['company_id']}) — tabela do serviço \"{$linha['servico_nome']}\", {$linha['faixas']} faixa(s)");
            }
        }

        if ($listaSemTabela !== []) {
            $this->line('');
            $this->line('Empresas que continuam sem tabela nenhuma (olhar no gate humano do plano 141-07):');
            foreach ($listaSemTabela as $linha) {
                $this->line("  - {$linha['company_name']} (empresa {$linha['company_id']})");
            }
        }
    }
}
