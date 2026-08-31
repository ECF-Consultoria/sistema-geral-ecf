<?php

// Trava de faturamento no --arquivar-ausentes do polos:sync-planilha.
//
// Incidente que originou o teste (2026-07-18): a planilha de Polos é editada à mão e a
// linha da Spinella Decor sumiu de uma versão. O --arquivar-ausentes arquivou a empresa,
// que sumiu do /polos faturando ~R$ 900 mil/mês. Só foi notada 6 semanas depois, quando o
// painel passou a divergir da planilha em R$ 445 mil.
//
// Regra: ausência da planilha NÃO basta para arquivar. Quem tem faturamento recente em
// polos_faturamento_snapshots é pulada e reportada para revisão humana.

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\PoloFaturamentoSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** @group polos */
class ArquivarAusentesTravaFaturamentoTest extends TestCase
{
    use RefreshDatabase;

    /** CSV temporário no formato que o comando lê. */
    private function csv(array $header, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'polos_').'.csv';
        $fh   = fopen($path, 'w');
        fputcsv($fh, $header);
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);

        return $path;
    }

    private function empresaPolos(string $cust, string $nome, string $fase = 'M2'): MlbEmpresa
    {
        return MlbEmpresa::create([
            'nome' => $nome, 'cust_id' => $cust, 'fase' => $fase,
            'projeto' => 'POLOS', 'polo' => 'Arapongas', 'tipo' => 'POLO',
        ]);
    }

    private function snapshot(string $cust, string $mes, float $gross): void
    {
        PoloFaturamentoSnapshot::create([
            'mes' => $mes, 'cust_id' => $cust, 'faturamento' => $gross,
            'faturamento_moveis' => $gross, 'ads' => 0, 'synced_at' => now(),
        ]);
    }

    /** Só uma linha qualquer, para o comando rodar sem mexer nas empresas sob teste. */
    private function planilhaComOutraEmpresa(): string
    {
        return $this->csv(
            ['Cust ID', 'Loja', 'Fase', 'Polo'],
            [['9999999999', 'Empresa Que Está Na Planilha', 'M2', 'Arapongas']],
        );
    }

    public function test_empresa_ausente_que_faturou_recentemente_nao_e_arquivada(): void
    {
        // O caso Spinella: fatura alto, mas a linha sumiu da planilha.
        $e = $this->empresaPolos('3223656591', 'Spinella Decor', 'M4');
        $this->snapshot('3223656591', now()->format('Ym'), 892093.07);

        $file = $this->planilhaComOutraEmpresa();

        $this->artisan('polos:sync-planilha', [
            '--file' => $file, '--apply' => true, '--arquivar-ausentes' => true,
        ])->assertExitCode(0);

        $this->assertNull(
            $e->fresh()->arquivado_em,
            'Empresa com faturamento recente NÃO pode ser arquivada por ausência na planilha.'
        );

        @unlink($file);
    }

    public function test_empresa_ausente_sem_faturamento_continua_sendo_arquivada(): void
    {
        // A trava não pode desligar o arquivamento legítimo (cliente que de fato saiu).
        $e = $this->empresaPolos('1111111111', 'Saiu Do Programa');

        $file = $this->planilhaComOutraEmpresa();

        $this->artisan('polos:sync-planilha', [
            '--file' => $file, '--apply' => true, '--arquivar-ausentes' => true,
        ])->assertExitCode(0);

        $this->assertNotNull(
            $e->fresh()->arquivado_em,
            'Sem sinal de faturamento, a ausência na planilha deve arquivar normalmente.'
        );

        @unlink($file);
    }

    public function test_faturamento_antigo_nao_protege(): void
    {
        // Faturou há mais de 3 meses e parou: é churn de verdade, pode arquivar.
        $e = $this->empresaPolos('2222222222', 'Parou De Faturar');
        $this->snapshot('2222222222', now()->subMonthsNoOverflow(7)->format('Ym'), 500000.0);

        $file = $this->planilhaComOutraEmpresa();

        $this->artisan('polos:sync-planilha', [
            '--file' => $file, '--apply' => true, '--arquivar-ausentes' => true,
        ])->assertExitCode(0);

        $this->assertNotNull(
            $e->fresh()->arquivado_em,
            'Faturamento fora da janela de 3 meses não deve proteger do arquivamento.'
        );

        @unlink($file);
    }

    public function test_snapshot_zerado_nao_protege(): void
    {
        // Snapshot existe mas com faturamento 0 — ausência de atividade, não atividade.
        $e = $this->empresaPolos('3333333333', 'Snapshot Zerado');
        $this->snapshot('3333333333', now()->format('Ym'), 0.0);

        $file = $this->planilhaComOutraEmpresa();

        $this->artisan('polos:sync-planilha', [
            '--file' => $file, '--apply' => true, '--arquivar-ausentes' => true,
        ])->assertExitCode(0);

        $this->assertNotNull(
            $e->fresh()->arquivado_em,
            'faturamento = 0 não é sinal de vida — deve arquivar.'
        );

        @unlink($file);
    }

    public function test_protegida_aparece_no_relatorio(): void
    {
        // A empresa pulada tem de ser VISÍVEL — arquivar calado foi o que causou o incidente,
        // e proteger calado esconderia uma linha apagada por engano na planilha.
        $this->empresaPolos('3223656591', 'Spinella Decor', 'M4');
        $this->snapshot('3223656591', now()->format('Ym'), 892093.07);

        $file = $this->planilhaComOutraEmpresa();

        $this->artisan('polos:sync-planilha', [
            '--file' => $file, '--apply' => true, '--arquivar-ausentes' => true,
        ])
            ->expectsOutputToContain('Spinella Decor')
            ->assertExitCode(0);

        @unlink($file);
    }
}
