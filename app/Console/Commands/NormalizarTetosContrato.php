<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\EmpresaFaixaFaturamento;
use App\Services\Fechamento\GravarTabelaEmpresaService;
use App\Services\Fechamento\ValidadorTabelaFaixas;
use App\Support\FaixaFaturamento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Quick 260910-l7k — põe na convenção da casa os tetos que já foram gravados fora dela.
 *
 * Medido em produção em 2026-09-10: 75 tetos terminando em ",00", todos de linhas com
 * `origem = 'contrato'` (13 empresas), porque a confirmação da leitura do Clicksign gravava o teto
 * LITERAL do contrato ("Até R$ 500.000,00") sem passar pela borda de conversão. Do lado das outras
 * duas tabelas (`servico_faixas_faturamento` e `grupo_faixas_faturamento`) não havia nenhum fora.
 *
 * A convenção é `teto = valor redondo − 0,01`, porque `FechamentoFaixaResolver::classificar()`
 * classifica com `limite_superior >= faturamento` — ver `App\Support\FaixaFaturamento`.
 *
 * ⚠️ (1) Dry-run é o PADRÃO. Sem `--aplicar` nada é gravado.
 *
 * ⚠️ (2) Reescreve SÓ o `limite_superior`. `ordem`, `valor` e `valor_e_piso` passam intactos, e o
 * `servico_origem_id` das linhas atuais é devolvido igual — a porta única de escrita recebe esse
 * valor por parâmetro, e esquecer dele apagaria o vínculo em silêncio.
 *
 * ⚠️ (3) Grava pela porta única (`GravarTabelaEmpresaService::gravar()`), com
 * `feitoDe = 'normalizacao_teto'` — é o que garante UMA entrada de `activity_log` por empresa, com
 * a tabela inteira antes e depois.
 *
 * ⚠️ (4) Tabela que não passa na validação (`ValidadorTabelaFaixas`) é PULADA e nomeada no
 * relatório — nunca corrigida pela metade. Em 2026-09-10 eram exatamente duas (MAXIGOLD #234 e
 * EZIOFREDIANI #256), as duas com a faixa aberta pelo MENOR preço da tabela; consertar o conteúdo
 * delas depende de ler o contrato real e é trabalho humano, não deste comando.
 *
 * ⚠️ (5) O texto impresso é conveniência operacional — a conferência oficial é a RECONSULTA ao
 * banco (`empresa_faixas_faturamento`), nunca o stdout desta rodada. Disciplina registrada em
 * `.planning/learnings/desempenho-bonificacao.md`.
 *
 * ⚠️ (6) Este comando NUNCA lê nem grava `fechamento_snapshots` — competência já congelada não é
 * reescrita por ele (D-11 da Fase 137).
 */
class NormalizarTetosContrato extends Command
{
    protected $signature = 'fechamento:normalizar-tetos-contrato
        {--aplicar : grava de fato (sem esta opção, só mostra o que faria)}';

    protected $description = 'Põe na convenção da casa (teto terminando em ,99) os tetos de tabelas vindas de contrato que foram gravados com o valor redondo do contrato. Dry-run por padrão.';

    public function __construct(
        private GravarTabelaEmpresaService $gravador,
        private ValidadorTabelaFaixas $validador,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        $companyIds = EmpresaFaixaFaturamento::where('origem', EmpresaFaixaFaturamento::ORIGEM_CONTRATO)
            ->distinct()
            ->pluck('company_id');

        $companies = Company::whereIn('id', $companyIds)->orderBy('name')->get()->keyBy('id');

        $aCorrigir = [];  // empresas cuja tabela muda
        $puladas   = [];  // empresas cuja tabela não passa na validação

        foreach ($companyIds as $companyId) {
            $company = $companies->get($companyId);

            if ($company === null) {
                // Linha órfã (empresa apagada) — não é caso deste comando.
                continue;
            }

            $linhas = EmpresaFaixaFaturamento::where('company_id', $companyId)
                ->ordenadas()
                ->get();

            $faixas    = [];
            $mudancas  = [];

            foreach ($linhas as $linha) {
                $atual = $linha->limite_superior !== null ? (float) $linha->limite_superior : null;
                $novo  = FaixaFaturamento::tetoGravado($atual);

                if ($this->mudou($atual, $novo)) {
                    $mudancas[] = ['ordem' => $linha->ordem, 'antes' => $atual, 'depois' => $novo];
                }

                $faixas[] = [
                    'ordem'           => $linha->ordem,
                    'limite_superior' => $novo,
                    'valor'           => (float) $linha->valor,
                    'valor_e_piso'    => (bool) $linha->valor_e_piso,
                ];
            }

            if ($mudancas === []) {
                continue; // já está na convenção — nada a fazer.
            }

            $erros = $this->validador->erros($faixas);

            if ($erros !== []) {
                $puladas[] = ['company' => $company, 'motivo' => $erros[0]['mensagem']];

                continue;
            }

            $aCorrigir[] = [
                'company'           => $company,
                'faixas'            => $faixas,
                'mudancas'          => $mudancas,
                // Todas as linhas de uma empresa compartilham a mesma procedência (é o que a porta
                // única de escrita grava) — devolver o mesmo valor preserva o vínculo com o serviço.
                'servico_origem_id' => $linhas->pluck('servico_origem_id')->filter()->first(),
            ];
        }

        $gravadas = 0;
        $falhas   = 0;

        if ($aplicar) {
            foreach ($aCorrigir as $item) {
                /** @var Company $company */
                $company = $item['company'];

                try {
                    $this->gravador->gravar(
                        $company,
                        $item['faixas'],
                        EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
                        $item['servico_origem_id'],
                        null,
                        'normalizacao_teto',
                    );
                    $gravadas++;
                } catch (\Throwable $e) {
                    Log::error("[Fechamento] Falha ao normalizar tetos da empresa {$company->id} ({$company->name}): {$e->getMessage()}");
                    $falhas++;
                }
            }
        }

        $this->relatorio($aCorrigir, $puladas, $aplicar, $gravadas, $falhas);

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Comparação de centavos por inteiro — nunca `==` em float (um centavo aqui move empresa de
     * faixa).
     */
    private function mudou(?float $antes, ?float $depois): bool
    {
        if ($antes === null || $depois === null) {
            return $antes !== $depois;
        }

        return (int) round($antes * 100) !== (int) round($depois * 100);
    }

    /**
     * Relatório em pt-BR, sem jargão. Conveniência operacional (comentário (5) da classe) — nunca
     * critério de verificação.
     *
     * @param  array<int, array{company: Company, faixas: array, mudancas: array, servico_origem_id: ?int}>  $aCorrigir
     * @param  array<int, array{company: Company, motivo: string}>  $puladas
     */
    private function relatorio(array $aCorrigir, array $puladas, bool $aplicar, int $gravadas, int $falhas): void
    {
        $totalTetos = array_sum(array_map(fn (array $i) => count($i['mudancas']), $aCorrigir));

        $this->info($aplicar
            ? '[Fechamento] Correção EXECUTADA — tetos de tabelas vindas de contrato postos na convenção da casa.'
            : '[Fechamento] Simulação (dry-run) — nada foi gravado. Rode de novo com --aplicar para gravar.');

        $this->line(sprintf(
            'Empresas a corrigir: %d · tetos a corrigir: %d · empresas puladas (tabela precisa de conserto humano): %d',
            count($aCorrigir),
            $totalTetos,
            count($puladas)
        ));

        if ($aplicar) {
            $this->line("Empresas gravadas nesta rodada: {$gravadas} · falhas: {$falhas}");
        }

        if ($aCorrigir !== []) {
            $this->line('');
            $this->line('Empresa por empresa:');

            foreach ($aCorrigir as $item) {
                /** @var Company $company */
                $company = $item['company'];
                $qtd     = count($item['mudancas']);

                $this->line("  - {$company->name} (empresa {$company->id}) — {$qtd} teto(s):");

                foreach ($item['mudancas'] as $mudanca) {
                    $this->line(sprintf(
                        '      faixa %d: %s  ->  %s',
                        $mudanca['ordem'],
                        $this->fmtBRL($mudanca['antes']),
                        $this->fmtBRL($mudanca['depois'])
                    ));
                }
            }
        }

        if ($puladas !== []) {
            $this->line('');
            $this->line('Puladas — a tabela destas empresas está fora de ordem e precisa ser cadastrada à mão, olhando o contrato:');

            foreach ($puladas as $item) {
                /** @var Company $company */
                $company = $item['company'];
                $this->line("  - {$company->name} (empresa {$company->id}) — {$item['motivo']}");
            }
        }
    }

    private function fmtBRL(?float $valor): string
    {
        return $valor === null ? 'sem teto' : 'R$ '.number_format($valor, 2, ',', '.');
    }
}
