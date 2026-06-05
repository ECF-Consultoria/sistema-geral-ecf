<?php

// Phase 20 — substitui grants:sync-sftp por consumo HTTP da API ECF Drive.
// SyncGrantsFromSftp permanece intacto como rollback safety por +1 fase.

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyGrant;
use App\Services\EcfDriveService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncGrantsFromEcfDrive extends Command
{
    protected $signature = 'grants:sync-ecf {--dry-run : Exibe o que seria importado sem salvar no banco}';

    protected $description = 'Sincroniza grants do ML via API HTTP do ECF Drive (substitui o pipeline SFTP anterior).';

    public function handle(EcfDriveService $service): int
    {
        ignore_user_abort(true);
        set_time_limit(300);

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('[dry-run] Nenhuma alteração será salva.');
        }

        // writeStatus de início — só fora de dry-run (não travar UI em dry-run manual)
        if (! $dryRun) {
            $this->writeStatus(['status' => 'running', 'started_at' => time()]);
        }

        try {
            $page      = 1;
            $limit     = 100;
            $recebidos = $matched = $created = $updated = $orfaos = 0;

            while (true) {
                $r     = $service->listGrants(['page' => $page, 'limit' => $limit]);
                $data  = $r['data'] ?? [];
                $count = count($data);

                if ($count === 0) {
                    break;
                }

                $recebidos += $count;

                foreach ($data as $grant) {
                    $custId = $grant['custId'] ?? null;

                    if (! $custId) {
                        $orfaos++;
                        continue;
                    }

                    $company = $this->resolveCompany($grant);

                    if (! $company) {
                        $this->logOrfao($grant, 'ÓRFÃO');
                        $orfaos++;
                        continue;
                    }

                    $matched++;
                    $grantData = $this->mapToDb($grant);

                    if ($dryRun) {
                        $this->line("[dry-run] {$company->name} → " . json_encode($grantData, JSON_UNESCAPED_UNICODE));
                        continue;
                    }

                    $row = CompanyGrant::updateOrCreate(
                        ['company_id' => $company->id, 'ml_cust_id' => $custId],
                        $grantData,
                    );

                    $row->wasRecentlyCreated ? $created++ : $updated++;
                }

                // Pitfall CONTEXT.md #4 — para na última página parcial (não esperar data vazio)
                if ($count < $limit) {
                    break;
                }

                $page++;
            }

            $this->table(
                ['Recebidos', 'Matched', 'Criados', 'Atualizados', 'Órfãos'],
                [[$recebidos, $matched, $created, $updated, $orfaos]],
            );

            if (! $dryRun) {
                $this->writeStatus([
                    'status'  => 'done',
                    'success' => true,
                    'message' => "Sincronização concluída via ECF Drive: {$created} criados, {$updated} atualizados, {$orfaos} órfãos.",
                ]);
            }

            $this->info('Sincronização concluída.');
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (! $dryRun) {
                $this->writeStatus(['status' => 'done', 'success' => false, 'error' => $msg]);
            }
            $this->error("Erro durante sync ECF Drive: {$msg}");
            return self::FAILURE;
        }
    }

    /**
     * Resolve a Company correspondente ao grant da API ECF Drive.
     *
     * Estratégia (autoritativa — Plano 02 Phase 20, 2026-06-05):
     *  Match ESTRITO por cust_id ML. A API ECF Drive vem do SFTP do Mercado
     *  Livre e traz grants de TODA conta ML que deu grant (incluindo alunos
     *  de cursos / pessoas físicas) — não filtra por carteira ECF. Logo, o
     *  match precisa ser exato em cust_id; fallback por CNPJ foi descartado
     *  pelo usuário em 2026-06-05 porque introduz risco de associar grants
     *  de não-clientes (mesma pessoa física pode ter CNPJ que casa).
     *
     *  1º Match por companies.adman_account_id == grant.custId
     *  2º Match por companies.ml_store_id == grant.custId
     *     (ambos representam o MESMO cust_id ML em campos diferentes;
     *     2 companies em prod têm valores divergentes, justificando o segundo passo)
     *  3º Nenhum match → órfão (sem fallback CNPJ — risco de cliente errado)
     *
     *  Comparação como string com trim() defensivo — adman_account_id e
     *  ml_store_id são strings no DB; trim cobre zeros à esquerda ou espaços
     *  improváveis.
     */
    private function resolveCompany(array $grant): ?Company
    {
        $custId = trim((string) ($grant['custId'] ?? ''));
        if ($custId === '') {
            return null;
        }

        // 1º match por adman_account_id (campo primário, alimentado pela planilha Adman)
        $c = Company::where('active', true)
            ->where('adman_account_id', $custId)
            ->first();
        if ($c) {
            return $c;
        }

        // 2º match por ml_store_id (alimentado pelo cadastro Comercial; em ~2 companies
        // este valor diverge de adman_account_id — ambos representam cust_id ML).
        $c = Company::where('active', true)
            ->where('ml_store_id', $custId)
            ->first();
        if ($c) {
            return $c;
        }

        return null;
    }

    /**
     * Mapeia os campos da API ECF Drive para o schema de company_grants.
     *
     * Mapping autoritativo definido em PLAN.md (não alterar campos sem atualizar o plan).
     */
    private function mapToDb(array $grant): array
    {
        $expirado    = (bool) ($grant['expirado'] ?? false);
        $grantInicio = ! empty($grant['grantInicio']) ? Carbon::parse($grant['grantInicio']) : null;
        $grantFim    = ! empty($grant['grantFim'])    ? Carbon::parse($grant['grantFim'])    : null;

        // Mapping de status (D-02): expirado → expired; grantInicio futuro → pending; default active
        if ($expirado) {
            $status = 'expired';
        } elseif ($grantInicio && $grantInicio->isFuture()) {
            $status = 'pending';
        } else {
            $status = 'active';
        }

        return [
            'ml_cust_id' => $grant['custId'],
            'ml_email'   => $grant['email']    ?: null,
            'ml_phone'   => $grant['telefone'] ?: null,
            'segmento'   => $grant['segmento'] ?: null,
            'granted_at' => $grantInicio?->toDateString(),
            'expires_at' => $grantFim?->toDateString(),
            'status'     => $status,
        ];
    }

    /**
     * Registra grant não vinculado a nenhuma Company no log dedicado de órfãos.
     *
     * Formato: [YYYY-MM-DD HH:MM:SS] TIPO custId=... cnpj=... razaoSocial=... payload={json}
     * Arquivo: storage/logs/grants-orfaos.log (não acessível via HTTP)
     */
    private function logOrfao(array $grant, string $tipo): void
    {
        $line = sprintf(
            "[%s] %s custId=%s cnpj=%s razaoSocial=%s payload=%s\n",
            now()->format('Y-m-d H:i:s'),
            $tipo,
            $grant['custId']      ?? '-',
            $grant['cnpj']        ?? '-',
            $grant['razaoSocial'] ?? '-',
            json_encode($grant, JSON_UNESCAPED_UNICODE),
        );

        @file_put_contents(storage_path('logs/grants-orfaos.log'), $line, FILE_APPEND);
    }

    /**
     * Escreve status JSON compatível com o polling de Grants/Index.jsx.
     *
     * Formato mantido idêntico ao SyncGrantsFromSftp::writeStatus para que
     * o polling existente funcione sem alteração (D-W1-T3).
     */
    private function writeStatus(array $data): void
    {
        @file_put_contents(storage_path('app/grants_sync_status.json'), json_encode($data));
    }
}
