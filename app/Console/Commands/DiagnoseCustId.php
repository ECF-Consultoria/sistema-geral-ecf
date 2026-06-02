<?php

namespace App\Console\Commands;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Services\AdmanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Comando de diagnostico do mapeamento `cust_id` por empresa.
 *
 * Motivacao: a auditoria W3-T2 (ver AUDIT-OUTPUT-30d.txt) revelou 43 empresas
 * com erro Adman concentradas em IDs 258-291 onde `adman_account_id == ml_store_id`
 * em formato Seller ID ML (10 digitos). A Adman recusa essas chamadas porque o
 * cliente foi cadastrado com o Seller ID do ML em vez do ID Adman real, mas a
 * empresa segue funcional na Dashboard via fallback ML (token OAuth ativo).
 *
 * Estrategia (Phase 18 W4-T1 / Estrategia A do CONTEXT):
 *  1. Classifica cada empresa com `adman_account_id` preenchido em uma de 7
 *     categorias: OK, VALIDADO_OK, SUSPEITO_IGUAIS, SUSPEITO_FORMATO,
 *     INVALIDO_CONFIRMADO, VALIDADO_API, ERRO_INDEFINIDO.
 *  2. Empresas com sync recente em adman_metrics (60d, sem error_message) ganham
 *     curto-circuito VALIDADO_OK e nao consomem chamada Adman.
 *  3. Demais SUSPEITAS sao validadas via Adman::fetchPerformance(ontem, ontem)
 *     com throttle 7s (ADMAN_RATE_LIMIT_RPM = 10).
 *  4. Mostra tabela + sumario; --fix limpa SOMENTE `adman_account_id` de
 *     empresas com Status === INVALIDO_CONFIRMADO E mlToken ativo, preservando
 *     `ml_store_id` como cust_id valido via accessor `Company::cust_id`.
 *
 * Verificacao do accessor `Company::cust_id` (commit f9d0547):
 *  `getCustIdAttribute()` retorna `$this->ml_store_id ?: $this->adman_account_id`.
 *  Logo, limpar `adman_account_id` quando `ml_store_id` existe preserva
 *  `cust_id` (cai no fallback). Quando `ml_store_id` NAO existe e
 *  `adman_account_id` e INVALIDO, limpar zeraria a empresa — por isso o filtro
 *  do --fix exige mlToken ativo (que implica `ml_store_id` valido).
 *
 * NOTA: nao existe coluna `ml_oauth_ativo` no schema. O criterio de "ML como
 * fallback ativo" e o accessor `Company::is_ml_driven` (mlToken->status ===
 * 'active'). PLAN.md referencia `ml_oauth_ativo` em descricao informal, mas a
 * implementacao usa `is_ml_driven` (a fonte de verdade real).
 *
 * READ-ONLY por default. UPDATE so acontece dentro do bloco `if --fix`.
 *
 * @see \App\Services\AdmanService::ADMAN_RATE_LIMIT_RPM
 * @see \App\Models\Company::getCustIdAttribute()
 * @see .planning/phases/18-dashboard-precisao-filtros/PLAN.md (W4-T1)
 * @see .planning/phases/18-dashboard-precisao-filtros/AUDIT-OUTPUT-30d.txt
 */
class DiagnoseCustId extends Command
{
    protected $signature = 'dashboard:diagnose-cust-id
                            {--fix : Aplica correcoes automaticas (remove adman_account_id corrompido onde for seguro)}';

    protected $description = 'Diagnostica mapeamento cust_id por empresa (READ-ONLY). Com --fix, limpa adman_account_id de empresas INVALIDO_CONFIRMADO que possuem mlToken ativo (fallback ML preservado).';

    /** Categorias de diagnostico — populadas durante o handle. */
    private const CAT_OK                   = 'OK';
    private const CAT_VALIDADO_OK          = 'VALIDADO_OK';
    private const CAT_SUSPEITO_IGUAIS      = 'SUSPEITO_IGUAIS';
    private const CAT_SUSPEITO_FORMATO     = 'SUSPEITO_FORMATO';
    private const CAT_INVALIDO_CONFIRMADO  = 'INVALIDO_CONFIRMADO';
    private const CAT_VALIDADO_API         = 'VALIDADO_API';
    private const CAT_ERRO_INDEFINIDO      = 'ERRO_INDEFINIDO';

    public function __construct(private AdmanService $adman)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $aplicarFix = (bool) $this->option('fix');

        $this->info('[DiagnoseCustId] Iniciando diagnostico do mapeamento cust_id...');
        if ($aplicarFix) {
            $this->warn('[DiagnoseCustId] Modo --fix ATIVO: empresas com INVALIDO_CONFIRMADO + mlToken ativo serao atualizadas.');
        } else {
            $this->info('[DiagnoseCustId] Modo READ-ONLY: nenhuma alteracao no DB.');
        }
        $this->newLine();

        // Seleciona empresas ativas com adman_account_id preenchido.
        // Eager-load mlToken evita N+1 no acessor is_ml_driven dentro do loop.
        $empresas = Company::with('mlToken')
            ->where('active', true)
            ->whereNotNull('adman_account_id')
            ->where('adman_account_id', '!=', '')
            ->get(['id', 'name', 'adman_account_id', 'ml_store_id']);

        $totalEmpresas = $empresas->count();

        if ($totalEmpresas === 0) {
            $this->info('[DiagnoseCustId] Nenhuma empresa ativa com adman_account_id preenchido.');
            return 0;
        }

        $this->info("[DiagnoseCustId] Total empresas a analisar: {$totalEmpresas}");
        $this->newLine();

        // Range usado para validacao API: somente ontem (range minimo). Reduz
        // payload Adman + minimiza tempo total (1 dia em vez de 30).
        $ontem = now()->subDay()->toDateString();

        // Estrutura: cada item carrega empresa + categoria + acao sugerida.
        $linhas       = [];
        $contagens    = [
            self::CAT_OK                  => 0,
            self::CAT_VALIDADO_OK         => 0,
            self::CAT_SUSPEITO_IGUAIS     => 0,
            self::CAT_SUSPEITO_FORMATO    => 0,
            self::CAT_INVALIDO_CONFIRMADO => 0,
            self::CAT_VALIDADO_API        => 0,
            self::CAT_ERRO_INDEFINIDO     => 0,
        ];
        $candidatasFix = []; // empresas elegiveis para --fix

        $callsAdmanFeitas = 0;

        $bar = $this->output->createProgressBar($totalEmpresas);
        $bar->start();

        foreach ($empresas as $company) {
            $admanAccountId = (string) $company->adman_account_id;
            $mlStoreId      = (string) ($company->ml_store_id ?? '');
            $mlAtivo        = (bool) $company->is_ml_driven;

            // 1) Categoria inicial pelo formato/igualdade.
            $categoria = $this->categoriaInicial($admanAccountId, $mlStoreId);

            // 2) Curto-circuito VALIDADO_OK: sync recente sem erro nos ultimos 60d
            //    indica que o adman_account_id ainda funciona — independente do
            //    formato suspeito. Nao consome chamada Adman.
            if ($this->teveSyncRecenteOk($company->id)) {
                $categoria = self::CAT_VALIDADO_OK;
            }

            // 3) SUSPEITOS sem upgrade → validacao via Adman.
            if (in_array($categoria, [self::CAT_SUSPEITO_IGUAIS, self::CAT_SUSPEITO_FORMATO], true)) {
                $categoria = $this->validarViaAdman($company->cust_id, $ontem);
                $callsAdmanFeitas++;
            }

            $contagens[$categoria]++;

            $acaoSugerida = $this->acaoSugerida($categoria, $mlAtivo);

            $linhas[] = [
                'id'                => $company->id,
                'name'              => $this->truncarNome($company->name),
                'adman_account_id'  => $admanAccountId,
                'ml_store_id'       => $mlStoreId !== '' ? $mlStoreId : '—',
                'ml_ativo'          => $mlAtivo ? 'sim' : 'nao',
                'status'            => $categoria,
                'acao'              => $acaoSugerida,
            ];

            if ($categoria === self::CAT_INVALIDO_CONFIRMADO && $mlAtivo) {
                $candidatasFix[] = $company;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Renderiza tabela.
        $this->table(
            ['ID', 'Empresa', 'adman_account_id', 'ml_store_id', 'ml_ativo', 'Status', 'Acao Sugerida'],
            $linhas
        );

        // Sumario.
        $this->newLine();
        $this->info('Sumario:');
        $this->line("- Total empresas analisadas: {$totalEmpresas}");
        $this->line('- Chamadas Adman feitas (validacao): ' . $callsAdmanFeitas);
        foreach ($contagens as $cat => $count) {
            $this->line(sprintf('- %-22s %d', $cat . ':', $count));
        }

        $candidatasCount = count($candidatasFix);
        $this->newLine();
        $this->line("- Candidatas seguras p/ --fix (INVALIDO_CONFIRMADO + ml ativo): {$candidatasCount}");

        // 4) Modo --fix: aplica UPDATE somente em INVALIDO_CONFIRMADO + ml ativo.
        if ($aplicarFix && $candidatasCount > 0) {
            $this->newLine();
            $this->warn("[DiagnoseCustId] Aplicando --fix em {$candidatasCount} empresa(s)...");

            $atualizadas = 0;
            foreach ($candidatasFix as $company) {
                $admanAntigo = (string) $company->adman_account_id;
                $mlStoreId   = (string) ($company->ml_store_id ?? '');

                // UPDATE direto (sem touch em updated_at do model carregado para
                // nao reabrir o observer de activity log na mesma transacao —
                // gravamos a activity manualmente abaixo com a mensagem certa).
                DB::table('companies')
                    ->where('id', $company->id)
                    ->update(['adman_account_id' => null]);

                Log::info(sprintf(
                    "[DiagnoseCustId] Limpou adman_account_id corrompido da empresa %d (%s); adman_account_id era '%s', preservou ml_store_id='%s'",
                    $company->id,
                    $company->name,
                    $admanAntigo,
                    $mlStoreId
                ));

                activity()
                    ->performedOn($company->fresh())
                    ->withProperties([
                        'adman_account_id_antigo' => $admanAntigo,
                        'ml_store_id_preservado'  => $mlStoreId,
                        'origem'                  => 'dashboard:diagnose-cust-id --fix',
                        'phase'                   => '18-W4-T1',
                    ])
                    ->log('adman_account_id corrompido removido via dashboard:diagnose-cust-id --fix (Phase 18 W4-T1)');

                $atualizadas++;
            }

            $this->info("[DiagnoseCustId] --fix concluido: {$atualizadas} empresa(s) atualizada(s).");
        } elseif ($aplicarFix) {
            $this->info('[DiagnoseCustId] --fix solicitado mas nenhuma empresa candidata segura.');
        }

        return 0;
    }

    /**
     * Categoria inicial baseada apenas no shape do `adman_account_id`.
     *
     * - SUSPEITO_IGUAIS: adman_account_id == ml_store_id (provavel cadastro errado pos-Phase 13)
     * - SUSPEITO_FORMATO: adman_account_id parece um Seller ID ML (10 digitos)
     * - OK: nenhum dos suspeitos → mapeamento parece distinto e consistente
     */
    private function categoriaInicial(string $admanAccountId, string $mlStoreId): string
    {
        if ($mlStoreId !== '' && $admanAccountId === $mlStoreId) {
            return self::CAT_SUSPEITO_IGUAIS;
        }

        if (strlen($admanAccountId) === 10 && ctype_digit($admanAccountId)) {
            return self::CAT_SUSPEITO_FORMATO;
        }

        return self::CAT_OK;
    }

    /**
     * True se houver pelo menos 1 metric em adman_metrics nos ultimos 60d
     * COM `error_message IS NULL` (sync funcionou recentemente).
     *
     * Curto-circuito que evita chamar a Adman para empresas com mapeamento
     * inicialmente "suspeito" mas que estao funcionando na pratica.
     */
    private function teveSyncRecenteOk(int $companyId): bool
    {
        return AdmanMetric::query()
            ->where('company_id', $companyId)
            ->where('reference_date', '>=', now()->subDays(60)->toDateString())
            ->whereNull('error_message')
            ->exists();
    }

    /**
     * Valida o cust_id contra a Adman (range minimo = ontem, 1 dia).
     *
     * Mapeamento de retorno:
     *  - Sucesso (200)                 → VALIDADO_API (falso positivo no suspeito)
     *  - Throwable com 500/400/404     → INVALIDO_CONFIRMADO (Adman nao reconhece)
     *  - Throwable com 429 ou outros   → ERRO_INDEFINIDO (transitorio)
     *
     * Throttle 7s aplicado SEMPRE (mesmo em erro) — protege ADMAN_RATE_LIMIT_RPM = 10.
     */
    private function validarViaAdman(?string $custId, string $data): string
    {
        if (!$custId) {
            // Nao deveria acontecer (filtramos por adman_account_id !== ''),
            // mas defesa em profundidade — categoria inicial ja seria OK.
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
                // 429, timeout, conexao, etc.
                $categoria = self::CAT_ERRO_INDEFINIDO;
            }
        }

        // Throttle ADMAN_RATE_LIMIT_RPM = 10 (60s/10 = 6s teorico, 7s com folga).
        usleep(7_000_000);

        return $categoria;
    }

    /** Sugere acao apresentavel para o operador. */
    private function acaoSugerida(string $categoria, bool $mlAtivo): string
    {
        return match (true) {
            $categoria === self::CAT_INVALIDO_CONFIRMADO && $mlAtivo  => 'Limpar adman_account_id (--fix faz isso)',
            $categoria === self::CAT_INVALIDO_CONFIRMADO && !$mlAtivo => 'Revisar manualmente (sem ML como fallback)',
            $categoria === self::CAT_VALIDADO_API                     => 'Nenhuma (falso positivo do shape)',
            $categoria === self::CAT_OK                               => '—',
            $categoria === self::CAT_VALIDADO_OK                      => '—',
            $categoria === self::CAT_SUSPEITO_IGUAIS                  => 'Re-rodar diagnostico (sem validacao API)',
            $categoria === self::CAT_SUSPEITO_FORMATO                 => 'Re-rodar diagnostico (sem validacao API)',
            $categoria === self::CAT_ERRO_INDEFINIDO                  => 'Re-rodar diagnostico (Adman transitorio)',
            default                                                    => '—',
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
