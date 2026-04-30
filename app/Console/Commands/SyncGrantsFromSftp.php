<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyGrant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SyncGrantsFromSftp extends Command
{
    protected $signature = 'grants:sync-sftp
        {--dry-run : Exibe o que seria importado sem salvar no banco}
        {--file= : Caminho local do arquivo XLSX (ignora SFTP)}';

    protected $description = 'Sincroniza a lista de grants do Mercado Livre via SFTP (arquivo XLSX)';

    public function handle(): int
    {
        ignore_user_abort(true);
        set_time_limit(300);

        // Previne execução dupla quando exec() e curl disparam ao mesmo tempo
        $statusFile = storage_path('app/grants_sync_status.json');
        if (file_exists($statusFile)) {
            $s = json_decode(file_get_contents($statusFile), true) ?? [];
            if (($s['status'] ?? '') === 'running' && (time() - ($s['started_at'] ?? 0)) < 30) {
                $this->warn('Outra instância iniciada recentemente, abortando duplicata.');
                return self::SUCCESS;
            }
        }

        // Captura crashes de PHP (fatal error, killed, etc.) e escreve status de erro
        register_shutdown_function(function () use ($statusFile) {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                @file_put_contents($statusFile, json_encode([
                    'status'  => 'done',
                    'success' => false,
                    'error'   => 'PHP fatal error: ' . $err['message'],
                ]));
            }
        });

        $dryRun    = $this->option('dry-run');
        $localFile = $this->option('file');

        if ($dryRun) {
            $this->warn('[dry-run] Nenhuma alteração será salva.');
        }

        // ── Baixa o arquivo ──────────────────────────────────────────────────
        $tmpPath = null;
        try {
            if ($localFile) {
                $tmpPath = $localFile;
            } else {
                $remotePath = config('services.ml_sftp.grants_file');
                $this->info("Baixando: {$remotePath}");
                $content = Storage::disk('ml_sftp')->get($remotePath);

                if (empty($content)) {
                    $this->writeStatus(['status' => 'done', 'success' => false, 'error' => 'Arquivo vazio ou não encontrado no SFTP.']);
                    $this->error('Arquivo vazio ou não encontrado no SFTP.');
                    return self::FAILURE;
                }

                $tmpPath = sys_get_temp_dir() . '/ml_grants_' . uniqid() . '.xlsx';
                file_put_contents($tmpPath, $content);
                $this->info('Arquivo baixado (' . round(strlen($content) / 1024, 1) . ' KB).');
            }
        } catch (\Throwable $e) {
            $msg    = $e->getMessage();
            $isIp   = (bool) preg_match('/connection refused|timed out|unable to connect|network unreachable|no route to host/i', $msg);
            $this->writeStatus(['status' => 'done', 'success' => false, 'error' => $msg, 'error_ip' => $isIp]);
            $this->error("Erro ao baixar arquivo do SFTP: {$msg}");
            return self::FAILURE;
        }

        // ── Lê o XLSX ────────────────────────────────────────────────────────
        try {
            $rows = $this->parseXlsx($tmpPath);
        } catch (\Throwable $e) {
            $this->writeStatus(['status' => 'done', 'success' => false, 'error' => "Erro ao ler o arquivo XLSX: {$e->getMessage()}"]);
            $this->error("Erro ao ler o arquivo XLSX: {$e->getMessage()}");
            return self::FAILURE;
        } finally {
            // Remove arquivo temporário criado pelo SFTP download
            if (!$this->option('file') && $tmpPath && file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }

        if (empty($rows)) {
            $this->writeStatus(['status' => 'done', 'success' => false, 'error' => 'Nenhuma linha de dados encontrada no arquivo.']);
            $this->error('Nenhuma linha de dados encontrada no arquivo.');
            return self::FAILURE;
        }

        $this->info("Total de registros: " . count($rows));

        // ── Processa cada linha ───────────────────────────────────────────────
        $created = $updated = $skipped = 0;

        foreach ($rows as $row) {
            // Tenta variações de nome das colunas
            $custId = $this->col($row, ['cust_id', 'custid', 'cust id', 'customer_id', 'seller_id', 'id']);
            $inicio = $this->col($row, ['inicio', 'start', 'start_date', 'data_inicio', 'grant_start']);
            $fim    = $this->col($row, ['fim', 'end', 'end_date', 'data_fim', 'data_termino', 'grant_end']);
            $email  = $this->col($row, ['email', 'e-mail', 'email_contato']);
            $phone  = $this->col($row, ['telefone', 'phone', 'tel', 'celular', 'fone']);

            if (!$custId) {
                $this->warn('Linha sem cust_id, ignorando: ' . implode(', ', array_slice($row, 0, 3)));
                $skipped++;
                continue;
            }

            // Encontra a empresa pelo ml_store_id
            $company = Company::where('ml_store_id', $custId)->where('active', true)->first();

            if (!$company) {
                $this->warn("Empresa não encontrada para cust_id={$custId}");
                $skipped++;
                continue;
            }

            $grantData = [
                'ml_cust_id' => $custId,
                'ml_email'   => $email ?: null,
                'ml_phone'   => $phone ?: null,
                'status'     => 'active',
                'granted_at' => $inicio ? $this->parseDate($inicio) : null,
                'expires_at' => $fim    ? $this->parseDate($fim)    : null,
            ];

            if ($dryRun) {
                $this->line("[dry-run] {$company->name} → " . json_encode($grantData, JSON_UNESCAPED_UNICODE));
                continue;
            }

            $existing = CompanyGrant::where('company_id', $company->id)
                ->where('ml_cust_id', $custId)
                ->first();

            if ($existing) {
                $existing->update($grantData);
                $updated++;
            } else {
                CompanyGrant::create(array_merge($grantData, ['company_id' => $company->id]));
                $created++;
            }
        }

        $this->table(
            ['Criados', 'Atualizados', 'Ignorados'],
            [[$created, $updated, $skipped]]
        );

        $this->writeStatus([
            'status'  => 'done',
            'success' => true,
            'message' => "Sincronização concluída: {$created} importados, {$updated} atualizados, {$skipped} ignorados.",
        ]);
        $this->info('Sincronização concluída.');
        return self::SUCCESS;
    }

    private function writeStatus(array $data): void
    {
        @file_put_contents(storage_path('app/grants_sync_status.json'), json_encode($data));
    }

    private function parseXlsx(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $data        = $sheet->toArray(null, true, true, false);

        if (count($data) < 2) {
            return [];
        }

        // Primeira linha = cabeçalhos
        $headers = array_map(fn($h) => strtolower(trim((string) $h)), $data[0]);

        $rows = [];
        foreach (array_slice($data, 1) as $rawRow) {
            // Ignora linhas completamente vazias
            $values = array_map(fn($v) => trim((string) $v), $rawRow);
            if (implode('', $values) === '') {
                continue;
            }
            $rows[] = array_combine($headers, $values);
        }

        return $rows;
    }

    private function col(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                return $row[$key];
            }
        }
        return null;
    }

    private function parseDate(string $value): ?string
    {
        try {
            // Excel armazena datas como número serial às vezes
            if (is_numeric($value)) {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)
                    ->format('Y-m-d');
            }
            return \Carbon\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
