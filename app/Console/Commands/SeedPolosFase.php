<?php

namespace App\Console\Commands;

use App\Models\MlbEmpresa;
use App\Support\CustId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * SeedPolosFase — bootstrap único da Fase POLOS.
 *
 * Lê a aba 'Junho' do EvoluçãoSemanalV3.xlsx UMA vez (D-02 do CONTEXT.md)
 * e faz upsert por cust_id em MlbEmpresa:
 *   - Cria empresas faltantes com projeto=POLOS (D-04)
 *   - Atualiza fase/problema de quem já existe, preservando o nome (D-05)
 *   - Loga e pula linhas sem cust_id ou com fase inválida como órfãs (D-06)
 *   - Suporta --dry-run: imprime o que faria sem salvar (POL-14)
 *
 * Após este seed, a equipe mantém fase/problema pelo Kanban Mlb/Projetos (D-03).
 * NÃO usar este comando para re-importar a planilha em runtime.
 */
class SeedPolosFase extends Command
{
    protected $signature = 'mlb:seed-polos-fase
        {--dry-run : Exibe o que seria alterado sem salvar no banco}
        {--file= : Caminho absoluto do XLSX (padrão: storage/app/EvoluçãoSemanalV3.xlsx)}';

    protected $description = 'Bootstrap único da Fase POLOS a partir da aba Junho do EvoluçãoSemanalV3.xlsx';

    // Fases válidas do projeto POLOS (M1 incluído para seed; M1 é excluído só da página /polos)
    private const FASES_VALIDAS = ['M1', 'M2', 'M3', 'M4'];

    // Índices 0-based das colunas na aba Junho (confirmados pelo orquestrador)
    private const COL_NOME     = 2;   // C = Nome da empresa
    private const COL_CUST     = 3;   // D = Cust ID
    private const COL_FASE     = 17;  // R = Fase (M1..M4)
    private const COL_PROBLEMA = 19;  // T = Problema (sim/não)

    // Valores que representam "sim" para o campo problema
    private const PROBLEMA_SIM = ['sim', 's', '1', 'true', 'x'];

    public function handle(): int
    {
        // ── 1. Resolver o caminho do arquivo ──────────────────────────────────
        $path = $this->option('file') ?: storage_path('app/EvoluçãoSemanalV3.xlsx');

        if (!file_exists($path)) {
            $this->error("Arquivo não encontrado: {$path}");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[dry-run] Nenhuma alteração será salva no banco.');
        }

        // ── 2. Ler o XLSX via PhpSpreadsheet ──────────────────────────────────
        $this->info("Lendo arquivo: {$path}");

        try {
            $reader = IOFactory::createReaderForFile($path);
            // setReadDataOnly(false) é necessário para fórmulas em R/T (Pitfall 4 RESEARCH)
            $reader->setReadDataOnly(false);
            $reader->setLoadSheetsOnly(['Junho']);

            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            $this->error("Erro ao abrir o arquivo XLSX: {$e->getMessage()}");
            return self::FAILURE;
        }

        $sheet = $spreadsheet->getSheetByName('Junho');

        if ($sheet === null) {
            $this->error("Aba 'Junho' não encontrada no arquivo.");
            return self::FAILURE;
        }

        // toArray(null, true, true, false): values formatados, fórmulas calculadas, índice 0-based
        $data = $sheet->toArray(null, true, true, false);

        if (empty($data)) {
            $this->error("A aba 'Junho' está vazia.");
            return self::FAILURE;
        }

        // ── 3. Verificação defensiva do cabeçalho (A1/A2 RESEARCH) ───────────
        $cabecalho = $data[0] ?? [];
        $nomeDetectado     = trim((string) ($cabecalho[self::COL_NOME]     ?? ''));
        $custDetectado     = trim((string) ($cabecalho[self::COL_CUST]     ?? ''));
        $faseDetectada     = trim((string) ($cabecalho[self::COL_FASE]     ?? ''));
        $problemaDetectado = trim((string) ($cabecalho[self::COL_PROBLEMA] ?? ''));

        // Verifica se os cabeçalhos conferem com o esperado; emite warn mas continua
        $cabecalhosEsperados = [
            self::COL_NOME     => 'nome',
            self::COL_CUST     => 'cust',
            self::COL_FASE     => 'fase',
            self::COL_PROBLEMA => 'problema',
        ];

        $cabecalhosDetectados = [
            self::COL_NOME     => strtolower($nomeDetectado),
            self::COL_CUST     => strtolower($custDetectado),
            self::COL_FASE     => strtolower($faseDetectada),
            self::COL_PROBLEMA => strtolower($problemaDetectado),
        ];

        foreach ($cabecalhosEsperados as $idx => $keyword) {
            $detectado = $cabecalhosDetectados[$idx] ?? '';
            if (!str_contains($detectado, $keyword)) {
                $this->warn(
                    "Cabeçalho inesperado no índice {$idx}: esperava '{$keyword}', detectou '{$detectado}'. " .
                    "Seguindo com índices fixos confirmados pelo orquestrador."
                );
            }
        }

        // ── 4. Processar as linhas de dados (pula cabeçalho) ─────────────────
        $criados    = 0;
        $atualizados = 0;
        $orfas      = 0;

        foreach (array_slice($data, 1) as $linha) {
            $custIdRaw = trim((string) ($linha[self::COL_CUST]     ?? ''));
            $nome      = trim((string) ($linha[self::COL_NOME]     ?? ''));
            $fase      = trim((string) ($linha[self::COL_FASE]     ?? ''));
            $probRaw   = trim((string) ($linha[self::COL_PROBLEMA] ?? ''));

            // Linhas totalmente vazias → pular silenciosamente
            if ($custIdRaw === '' && $nome === '' && $fase === '') {
                continue;
            }

            // Linha sem cust_id → órfã (D-06)
            if ($custIdRaw === '') {
                $msg = "[POLOS seed] Linha órfã (sem cust_id): nome='{$nome}' fase='{$fase}'";
                Log::warning($msg);
                $this->warn("Órfã ignorada: nome='{$nome}' fase='{$fase}' (sem cust_id)");
                $orfas++;
                continue;
            }

            // Fase inválida → órfã
            if (!in_array($fase, self::FASES_VALIDAS, true)) {
                $msg = "[POLOS seed] Linha órfã (fase inválida '{$fase}'): cust_id='{$custIdRaw}' nome='{$nome}'";
                Log::warning($msg);
                $this->warn("Órfã ignorada: cust_id='{$custIdRaw}' fase='{$fase}' (fase inválida)");
                $orfas++;
                continue;
            }

            // Normaliza cust_id via CustId::normaliza (resolve Pitfall 1 RESEARCH — vírgula decimal)
            $custId  = CustId::normaliza($custIdRaw);
            $problema = in_array(strtolower($probRaw), self::PROBLEMA_SIM, true);

            // Modo dry-run: apenas exibe, não grava
            if ($dryRun) {
                $probLabel = $problema ? 'sim' : 'não';
                $this->line("[dry-run] cust_id={$custId} fase={$fase} problema={$probLabel} nome='{$nome}'");
                continue;
            }

            // Upsert por cust_id (D-05)
            $existe = MlbEmpresa::where('cust_id', $custId)->first();

            if ($existe) {
                // Atualiza fase e problema; NÃO sobrescreve o nome (Pergunta Aberta 2 RESEARCH)
                $existe->update([
                    'fase'     => $fase,
                    'problema' => $problema,
                ]);
                $atualizados++;
            } else {
                // Cria empresa faltante com projeto=POLOS (D-04)
                // criado_por fica null: FK nullable, sem user autenticado na CLI
                MlbEmpresa::create([
                    'cust_id'  => $custId,
                    'nome'     => $nome ?: "Empresa {$custId}",
                    'fase'     => $fase,
                    'problema' => $problema,
                    'projeto'  => 'POLOS',
                    'tipo'     => 'POLO',
                    'estagio'  => 'Não Listado',
                ]);
                $criados++;
            }
        }

        // ── 5. Exibir resumo ──────────────────────────────────────────────────
        $this->table(
            ['Métrica', 'Total'],
            [
                ['Criados',     $criados],
                ['Atualizados', $atualizados],
                ['Órfãs',       $orfas],
            ]
        );

        if ($dryRun) {
            $this->warn('[dry-run] Nenhuma linha foi salva.');
        } else {
            $this->info("Seed concluído: {$criados} criados, {$atualizados} atualizados, {$orfas} órfãs.");
        }

        return self::SUCCESS;
    }
}
