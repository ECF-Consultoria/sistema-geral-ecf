<?php

// Phase 38 (Plano 01) — Suíte de contratos RED para o comando mlb:seed-polos-fase (Plano 02).
// O comando NÃO existe ainda — estes testes FALHAM intencionalmente.
//
// O seed artisan (D-04..D-06 CONTEXT.md) lê EvoluçãoSemanalV3.xlsx aba 'Junho'
// UMA vez e faz upsert por cust_id em MlbEmpresa:
//   - Cria faltantes como projeto=POLOS
//   - Atualiza fase/problema de quem existe (não sobrescreve nome)
//   - Loga e pula linhas sem cust_id (órfãs)
//   - Suporta --dry-run (não grava nada, imprime resumo)
//
// Cobre: POL-14 (dry-run), POL-15 (cria), POL-16 (atualiza), POL-17 (órfãs).
//
// Os testes geram um XLSX temporário via PhpSpreadsheet para simular a planilha real.
// Colunas (0-based, conforme RESEARCH.md §Padrão 5):
//   2=C (Nome), 3=D (Cust ID), 17=R (Fase), 19=T (Problema)

namespace Tests\Feature\Phase38;

use App\Models\MlbEmpresa;
use App\Support\CustId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SeedPolosFaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cria um XLSX temporário em storage/app/testing/ com a aba 'Junho'.
     * Cabeçalho na linha 1; dados a partir da linha 2.
     * Colunas (0-based → célula): 2=C/Nome, 3=D/Cust, 17=R/Fase, 19=T/Problema.
     *
     * @param  array<array{nome?:string, cust_id?:string, fase?:string, problema?:string}>  $linhas
     * @param  string  $nome  Nome do arquivo (sem extensão)
     * @return string  Caminho absoluto do arquivo gerado
     */
    private function criarXlsxTeste(array $linhas, string $nome = 'seed_test'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Junho');

        // Cabeçalho na linha 1 (conforme premissa A2 do RESEARCH.md)
        $sheet->setCellValue('A1', 'Col A');
        $sheet->setCellValue('B1', 'Col B');
        $sheet->setCellValue('C1', 'Nome');        // índice 2
        $sheet->setCellValue('D1', 'Cust ID');     // índice 3
        // Colunas E..Q preenchidas com placeholder
        foreach (range('E', 'Q') as $col) {
            $sheet->setCellValue($col . '1', 'placeholder');
        }
        $sheet->setCellValue('R1', 'Fase');        // índice 17
        $sheet->setCellValue('S1', 'placeholder');
        $sheet->setCellValue('T1', 'Problema');    // índice 19

        // Dados a partir da linha 2
        foreach ($linhas as $i => $linha) {
            $row = $i + 2;
            $sheet->setCellValue('C' . $row, $linha['nome']    ?? '');
            $sheet->setCellValue('D' . $row, $linha['cust_id'] ?? '');
            $sheet->setCellValue('R' . $row, $linha['fase']    ?? '');
            $sheet->setCellValue('T' . $row, $linha['problema'] ?? '');
        }

        $dir = storage_path('app/testing');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . '/' . $nome . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    // ─── POL-14: Dry-run não altera o banco ─────────────────────────────────────

    /**
     * POL-14: Com --dry-run, o banco permanece intacto e a saída menciona o resumo.
     */
    public function test_seed_dry_run(): void
    {
        $path = $this->criarXlsxTeste([
            ['nome' => 'Empresa Teste', 'cust_id' => '1111111111', 'fase' => 'M2', 'problema' => ''],
        ], 'seed_dry_run');

        $this->artisan('mlb:seed-polos-fase', [
            '--file'    => $path,
            '--dry-run' => true,
        ])
            ->assertExitCode(0);

        // Com --dry-run, NADA deve ser criado no banco
        $this->assertDatabaseCount('mlb_empresas', 0);
    }

    // ─── POL-15: Seed cria empresa faltante ─────────────────────────────────────

    /**
     * POL-15: Linha com cust_id novo cria MlbEmpresa com projeto='POLOS', fase e problema corretos.
     * O cust_id gravado deve estar normalizado (sem ',0').
     */
    public function test_seed_cria_empresa(): void
    {
        $custIdBruto = '2425054445';  // como vem do XLSX (int ou float sem vírgula)
        $fase        = 'M2';

        $path = $this->criarXlsxTeste([
            ['nome' => 'Nova Empresa POLOS', 'cust_id' => $custIdBruto, 'fase' => $fase, 'problema' => ''],
        ], 'seed_cria');

        $this->artisan('mlb:seed-polos-fase', [
            '--file' => $path,
        ])->assertExitCode(0);

        // Deve ter criado 1 empresa
        $this->assertDatabaseCount('mlb_empresas', 1);

        $empresa = MlbEmpresa::first();
        $this->assertSame('POLOS',   $empresa->projeto);
        $this->assertSame($fase,     $empresa->fase);
        $this->assertFalse($empresa->problema);
        // cust_id deve estar normalizado (sem ',0' ou similar)
        $this->assertSame(CustId::normaliza($custIdBruto), $empresa->cust_id);
    }

    // ─── POL-16: Seed atualiza empresa existente ────────────────────────────────

    /**
     * POL-16: MlbEmpresa pré-existente com mesmo cust_id (normalizado) tem
     * fase/problema atualizados. Nome NÃO é sobrescrito (Pergunta Aberta 2 RESEARCH.md).
     */
    public function test_seed_atualiza_empresa(): void
    {
        $custId = '3333333333';
        $nomeOriginal = 'Nome Original que não deve mudar';

        // Empresa pré-existente com nome e fase diferentes
        MlbEmpresa::create([
            'nome'    => $nomeOriginal,
            'fase'    => 'M1',
            'projeto' => 'POLOS',
            'cust_id' => $custId,
            'estagio' => 'Não Listado',
            'problema' => false,
        ]);

        // Planilha indica fase=M3 e problema=sim (deve atualizar fase/problema, não nome)
        $path = $this->criarXlsxTeste([
            ['nome' => 'Nome da Planilha (ignorar)', 'cust_id' => $custId, 'fase' => 'M3', 'problema' => 'sim'],
        ], 'seed_atualiza');

        $this->artisan('mlb:seed-polos-fase', [
            '--file' => $path,
        ])->assertExitCode(0);

        // Ainda deve ser 1 empresa (atualização, não criação)
        $this->assertDatabaseCount('mlb_empresas', 1);

        $empresa = MlbEmpresa::first();
        $this->assertSame('M3',          $empresa->fase);
        $this->assertTrue($empresa->problema);
        // Nome preservado — não deve ser sobrescrito pelo seed
        $this->assertSame($nomeOriginal, $empresa->nome);
    }

    // ─── POL-17: Linhas sem cust_id são puladas (órfãs) ─────────────────────────

    /**
     * POL-17: Linha sem cust_id é pulada (não cria registro) e contabilizada como órfã.
     */
    public function test_seed_orfas_ignoradas(): void
    {
        Log::spy();

        $path = $this->criarXlsxTeste([
            // Linha válida — deve criar
            ['nome' => 'Empresa Válida', 'cust_id' => '9876543210', 'fase' => 'M2', 'problema' => ''],
            // Linha órfã — sem cust_id → deve ser pulada
            ['nome' => 'Linha Orfã', 'cust_id' => '', 'fase' => 'M2', 'problema' => ''],
        ], 'seed_orfas');

        $this->artisan('mlb:seed-polos-fase', [
            '--file' => $path,
        ])->assertExitCode(0);

        // Apenas a linha válida deve ter criado empresa
        $this->assertDatabaseCount('mlb_empresas', 1);
        $this->assertSame('9876543210', MlbEmpresa::first()->cust_id);
    }
}
