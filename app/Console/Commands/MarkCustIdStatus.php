<?php

namespace App\Console\Commands;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Services\AdmanService;
use Illuminate\Console\Command;

/**
 * Comando que persiste `companies.cust_id_status` baseado na mesma logica de
 * classificacao do `dashboard:diagnose-cust-id` (W4-T1).
 *
 * IMPORTANTE — escopo de mutacao:
 *   Este comando APENAS atualiza a coluna `cust_id_status`. NUNCA toca
 *   `cust_id`, `adman_account_id` ou `ml_store_id`. Confirmacao defensiva:
 *   `$company->update(['cust_id_status' => ...])` e o unico UPDATE; a
 *   propriedade `$fillable` em `Company` cobre exatamente o campo certo.
 *
 * Espelha DiagnoseCustId::classificarEmpresa — refator futuro pode extrair
 * a logica de classificacao para um trait/helper (Phase 18.5). Optamos por
 * duplicar agora pra entregar a marcacao em W5 sem mexer em W4-T1 que ja
 * esta em prod.
 *
 * Mapeamento categoria → coluna `cust_id_status`:
 *   - OK / VALIDADO_OK / VALIDADO_API → 'ok'
 *   - INVALIDO_CONFIRMADO             → 'invalido'
 *   - ERRO_INDEFINIDO                 → MANTEM status anterior (transitorio)
 *   - SUSPEITO_IGUAIS / SUSPEITO_FORMATO sem upgrade → MANTEM (transitorio)
 *   - Empresa sem cust_id             → 'nao_aplicavel'
 *
 * Modo `--dry-run`: classifica e imprime o que seria mudado, sem UPDATE
 * nem activity log.
 *
 * Throttle 7s entre chamadas Adman (ADMAN_RATE_LIMIT_RPM = 10). O curto-
 * circuito VALIDADO_OK (sync recente em adman_metrics) economiza chamadas
 * para empresas que ja estao funcionando, deixando o tempo total em ~5min
 * em prod (32 SUSPEITOS x 7s + overhead).
 *
 * Activity log enxuto via Spatie: registra somente mudancas reais (antigo
 * != novo). Logs ficam em `activity_log` para auditoria do operador.
 *
 * Executar manualmente apos cadastros novos ou periodicamente (1x/semana
 * sugerido) via SSH. NAO esta agendado no scheduler (decisao W5 do PLAN).
 *
 * @see \App\Console\Commands\DiagnoseCustId
 * @see \App\Services\AdmanService::ADMAN_RATE_LIMIT_RPM
 * @see .planning/phases/18-dashboard-precisao-filtros/PLAN.md (W5-T2)
 */
class MarkCustIdStatus extends Command
{
    protected $signature = 'dashboard:mark-custid-status
                            {--dry-run : Mostra mudancas sem persistir}';

    protected $description = 'Persiste companies.cust_id_status reusando a classificacao do dashboard:diagnose-cust-id. UPDATE somente da flag (nunca cust_id/adman_account_id/ml_store_id).';

    /** Categorias internas — mesmas chaves do DiagnoseCustId. */
    private const CAT_OK                   = 'OK';
    private const CAT_VALIDADO_OK          = 'VALIDADO_OK';
    private const CAT_SUSPEITO_IGUAIS      = 'SUSPEITO_IGUAIS';
    private const CAT_SUSPEITO_FORMATO     = 'SUSPEITO_FORMATO';
    private const CAT_INVALIDO_CONFIRMADO  = 'INVALIDO_CONFIRMADO';
    private const CAT_VALIDADO_API         = 'VALIDADO_API';
    private const CAT_ERRO_INDEFINIDO      = 'ERRO_INDEFINIDO';

    /** Valores aceitos pela coluna cust_id_status (alinhado com a migration W5-T1). */
    private const STATUS_OK             = 'ok';
    private const STATUS_INVALIDO       = 'invalido';
    private const STATUS_DESCONHECIDO   = 'desconhecido';
    private const STATUS_NAO_APLICAVEL  = 'nao_aplicavel';

    public function __construct(private AdmanService $adman)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $inicio = microtime(true);

        $this->info('[MarkCustIdStatus] Iniciando marcacao do cust_id_status...');
        if ($dryRun) {
            $this->warn('[MarkCustIdStatus] Modo --dry-run ATIVO: nenhum UPDATE sera feito.');
        }
        $this->newLine();

        // Considera TODAS as empresas ativas. Empresas inativas ficam fora pra
        // economizar chamadas Adman; podem ser tratadas em re-execucao manual
        // caso reativadas.
        $empresas = Company::with('mlToken')
            ->where('active', true)
            ->get(['id', 'name', 'adman_account_id', 'ml_store_id', 'cust_id_status']);

        $total = $empresas->count();
        if ($total === 0) {
            $this->info('[MarkCustIdStatus] Nenhuma empresa ativa para classificar.');
            return 0;
        }

        $this->info("[MarkCustIdStatus] Total empresas a classificar: {$total}");
        $this->newLine();

        $ontem = now()->subDay()->toDateString();

        // Contadores por categoria final (apos curto-circuito + validacao Adman).
        $contadores = [
            self::STATUS_OK            => 0,
            self::STATUS_INVALIDO      => 0,
            self::STATUS_NAO_APLICAVEL => 0,
            'mantido'                  => 0, // ERRO_INDEFINIDO ou SUSPEITO sem upgrade
        ];

        $callsAdman   = 0;
        $aplicados    = 0;
        $puladosSemMudanca = 0;
        $linhasMudanca = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($empresas as $company) {
            $admanAccountId = (string) ($company->adman_account_id ?? '');
            $mlStoreId      = (string) ($company->ml_store_id ?? '');
            $statusAnterior = (string) ($company->cust_id_status ?? self::STATUS_DESCONHECIDO);

            // Empresa sem cust_id (nem adman_account_id nem ml_store_id) ja e
            // classificada como nao_aplicavel sem precisar chamar a Adman.
            if ($admanAccountId === '' && $mlStoreId === '') {
                $this->aplicarMudanca(
                    $company,
                    $statusAnterior,
                    self::STATUS_NAO_APLICAVEL,
                    $contadores,
                    $aplicados,
                    $puladosSemMudanca,
                    $linhasMudanca,
                    $dryRun
                );
                $bar->advance();
                continue;
            }

            // Curto-circuito VALIDADO_OK: sync recente em adman_metrics (60d) prova
            // que o cust_id ainda funciona — evita chamada Adman para empresas
            // estaveis. Reaproveita logica do DiagnoseCustId.
            if ($this->teveSyncRecenteOk($company->id)) {
                $this->aplicarMudanca(
                    $company,
                    $statusAnterior,
                    self::STATUS_OK,
                    $contadores,
                    $aplicados,
                    $puladosSemMudanca,
                    $linhasMudanca,
                    $dryRun
                );
                $bar->advance();
                continue;
            }

            // Categoria inicial pelo shape.
            $categoria = $this->categoriaInicial($admanAccountId, $mlStoreId);

            // SUSPEITOS sem upgrade → valida via Adman.
            if (in_array($categoria, [self::CAT_SUSPEITO_IGUAIS, self::CAT_SUSPEITO_FORMATO], true)) {
                $categoria = $this->validarViaAdman($company->cust_id, $ontem);
                $callsAdman++;
            }

            // Resolve categoria → coluna.
            $novoStatus = $this->categoriaParaStatus($categoria);

            // ERRO_INDEFINIDO / SUSPEITO transitorio → mantem status anterior.
            if ($novoStatus === null) {
                $contadores['mantido']++;
                $bar->advance();
                continue;
            }

            $this->aplicarMudanca(
                $company,
                $statusAnterior,
                $novoStatus,
                $contadores,
                $aplicados,
                $puladosSemMudanca,
                $linhasMudanca,
                $dryRun
            );

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Sumario final.
        $duracao = round(microtime(true) - $inicio, 1);

        $this->info('Sumario:');
        $this->line("- Empresas analisadas:               {$total}");
        $this->line("- Chamadas Adman feitas (validacao): {$callsAdman}");
        $this->line('- Categorizacao final (apos classificacao):');
        $this->line(sprintf('    - %-14s %d', 'ok:',            $contadores[self::STATUS_OK]));
        $this->line(sprintf('    - %-14s %d', 'invalido:',      $contadores[self::STATUS_INVALIDO]));
        $this->line(sprintf('    - %-14s %d', 'nao_aplicavel:', $contadores[self::STATUS_NAO_APLICAVEL]));
        $this->line(sprintf('    - %-14s %d', 'mantido:',       $contadores['mantido']));
        $this->newLine();
        $this->line('- UPDATE aplicados:        ' . $aplicados);
        $this->line('- UPDATE pulados (sem mudanca): ' . $puladosSemMudanca);
        $this->line('- Tempo decorrido: ' . $duracao . 's');

        // No --dry-run, mostra tabela do que SERIA mudado.
        if ($dryRun && !empty($linhasMudanca)) {
            $this->newLine();
            $this->info('[MarkCustIdStatus] --dry-run — mudancas que SERIAM aplicadas:');
            $this->table(['ID', 'Empresa', 'Antigo', 'Novo'], $linhasMudanca);
        }

        return 0;
    }

    /**
     * Aplica (ou simula) a mudanca de cust_id_status com guards.
     *
     * - Compara antigo vs novo: pula sem activity log quando iguais
     * - Modo --dry-run: nao chama update() nem activity log; coleta linha pra tabela
     * - Modo normal: update() + activity log via Spatie
     *
     * Observacao: `$contadores` e `$aplicados` / `$puladosSemMudanca` /
     * `$linhasMudanca` recebem &reference para mutar em-place (assinatura
     * defensiva — Laravel command e single-thread).
     *
     * @param array<string, int> $contadores
     * @param array<int, array{0:int, 1:string, 2:string, 3:string}> $linhasMudanca
     */
    private function aplicarMudanca(
        Company $company,
        string $statusAnterior,
        string $novoStatus,
        array &$contadores,
        int &$aplicados,
        int &$puladosSemMudanca,
        array &$linhasMudanca,
        bool $dryRun
    ): void {
        $contadores[$novoStatus] = ($contadores[$novoStatus] ?? 0) + 1;

        if ($statusAnterior === $novoStatus) {
            $puladosSemMudanca++;
            return;
        }

        if ($dryRun) {
            $linhasMudanca[] = [
                $company->id,
                $this->truncarNome($company->name),
                $statusAnterior,
                $novoStatus,
            ];
            return;
        }

        // UPDATE apenas da coluna cust_id_status — proxy seguro via Eloquent.
        // O array tem UMA chave, garantia tripla (logica + fillable + assert).
        $company->update(['cust_id_status' => $novoStatus]);

        // Activity log: somente mudancas reais. Permite auditoria operacional
        // do que o comando alterou em cada execucao.
        activity()
            ->performedOn($company->fresh())
            ->withProperties([
                'cust_id_status_antigo' => $statusAnterior,
                'cust_id_status_novo'   => $novoStatus,
                'origem'                => 'dashboard:mark-custid-status',
                'phase'                 => '18-W5-T2',
            ])
            ->log("cust_id_status atualizado de '{$statusAnterior}' para '{$novoStatus}' via dashboard:mark-custid-status (Phase 18 W5)");

        $aplicados++;
    }

    /**
     * Categoria inicial pelo shape do adman_account_id (sem chamar Adman).
     * Espelha DiagnoseCustId::categoriaInicial.
     */
    private function categoriaInicial(string $admanAccountId, string $mlStoreId): string
    {
        if ($admanAccountId === '') {
            // Apenas ml_store_id presente → trata como OK (cust_id valido via fallback)
            return self::CAT_OK;
        }

        if ($mlStoreId !== '' && $admanAccountId === $mlStoreId) {
            return self::CAT_SUSPEITO_IGUAIS;
        }

        if (strlen($admanAccountId) === 10 && ctype_digit($admanAccountId)) {
            return self::CAT_SUSPEITO_FORMATO;
        }

        return self::CAT_OK;
    }

    /**
     * Sync recente OK em adman_metrics (60d) — espelha DiagnoseCustId.
     * Curto-circuito: empresa funcionando nao precisa de validacao Adman.
     */
    private function teveSyncRecenteOk(int $companyId): bool
    {
        return AdmanMetric::query()
            ->where('company_id', $companyId)
            ->where('reference_date', '>=', now()->subDays(60)->toDateString())
            ->exists();
    }

    /**
     * Validacao via Adman (range minimo: ontem). Espelha DiagnoseCustId.
     *
     * Throttle 7s aplicado SEMPRE (mesmo em erro) — protege ADMAN_RATE_LIMIT_RPM=10.
     */
    private function validarViaAdman(?string $custId, string $data): string
    {
        if (!$custId) {
            return self::CAT_OK;
        }

        try {
            $this->adman->fetchPerformance($custId, $data, $data);
            $categoria = self::CAT_VALIDADO_API;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, '500') || str_contains($msg, '400') || str_contains($msg, '404')) {
                $categoria = self::CAT_INVALIDO_CONFIRMADO;
            } else {
                // 429, timeout, conexao, etc → transitorio
                $categoria = self::CAT_ERRO_INDEFINIDO;
            }
        }

        // Throttle 7s pos-chamada (consistente com DiagnoseCustId).
        // Em ambiente de teste, USE_FAKE_THROTTLE pode evitar o usleep real.
        if (!app()->environment('testing')) {
            usleep(7_000_000);
        }

        return $categoria;
    }

    /**
     * Converte categoria interna para valor da coluna `cust_id_status`.
     * Retorna null quando o status NAO deve ser persistido (transitorio).
     */
    private function categoriaParaStatus(string $categoria): ?string
    {
        return match ($categoria) {
            self::CAT_OK,
            self::CAT_VALIDADO_OK,
            self::CAT_VALIDADO_API        => self::STATUS_OK,
            self::CAT_INVALIDO_CONFIRMADO => self::STATUS_INVALIDO,
            // ERRO_INDEFINIDO / SUSPEITO sem upgrade → null = mantem status anterior
            default                        => null,
        };
    }

    /** Trunca nome longo para nao quebrar a tabela do console. */
    private function truncarNome(?string $nome): string
    {
        $nome = (string) ($nome ?? '');
        if (mb_strlen($nome) <= 28) {
            return $nome;
        }
        return mb_substr($nome, 0, 25) . '...';
    }
}
