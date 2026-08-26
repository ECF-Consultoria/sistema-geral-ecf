<?php

// Reconciliação pré-sync: o registro nasce SEM cust_id (sync de Entrantes) e a planilha
// depois traz o mesmo seller COM cust_id. Sem backfill o sync duplicaria.

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliarPolosPlanilhaTest extends TestCase
{
    use RefreshDatabase;

    private function csv(array $header, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'recon_').'.csv';
        $fh = fopen($path, 'w');
        fputcsv($fh, $header);
        foreach ($rows as $r) { fputcsv($fh, $r); }
        fclose($fh);
        return $path;
    }

    private function empresaComFicha(string $nome, string $token, ?string $cust = null, string $gmail = ''): MlbEmpresa
    {
        $e = MlbEmpresa::create(['nome' => $nome, 'cust_id' => $cust, 'projeto' => 'POLOS',
                                 'fase' => 'Aceite no Projeto', 'polo' => 'Arapongas']);
        MlbImplementacao::create(['empresa_id' => $e->id, 'token' => $token, 'gmail_colaborador' => $gmail]);
        return $e;
    }

    public function test_backfill_por_token_preenche_o_cust_no_registro_antigo(): void
    {
        $e = $this->empresaComFicha('Movéis Httl', 'tok'.str_repeat('a', 45));
        $file = $this->csv(['Cust ID', 'Loja', 'Link da Planilha'], [
            ['3615499674', 'Moveis Huttl', 'https://admin.ecfconsultoria.com.br/implementacao/'.$e->implementacao->token],
        ]);

        $this->artisan('polos:reconciliar-planilha', ['--file' => $file, '--apply' => true])->assertExitCode(0);

        $e->refresh();
        $this->assertSame('3615499674', $e->cust_id);
        $this->assertSame('Moveis Huttl', $e->nome, 'O nome deveria alinhar ao da planilha.');
        $this->assertSame(1, MlbEmpresa::count(), 'Nada pode ter sido criado.');
        @unlink($file);
    }

    public function test_token_repetido_em_duas_linhas_e_pulado(): void
    {
        // Caso KL Móveis: duas lojas, dois custs, o MESMO link colado nas duas.
        $e = $this->empresaComFicha('KL Móveis', 'tok'.str_repeat('b', 45));
        $tokUrl = 'https://admin.ecfconsultoria.com.br/implementacao/'.$e->implementacao->token;
        $file = $this->csv(['Cust ID', 'Loja', 'Link da Planilha'], [
            ['3615592884', 'KL Móveis', $tokUrl],
            ['3403152802', 'KL Móveis', $tokUrl],
        ]);

        $this->artisan('polos:reconciliar-planilha', ['--file' => $file, '--apply' => true])->assertExitCode(0);

        $this->assertNull($e->fresh()->cust_id, 'Token ambíguo não pode escolher um cust.');
        @unlink($file);
    }

    public function test_divergencia_de_cust_nao_reponta_sem_a_flag(): void
    {
        $e = $this->empresaComFicha('Amo Eletros', 'tok'.str_repeat('c', 45), '1164686271');
        $file = $this->csv(['Cust ID', 'Loja', 'Link da Planilha'], [
            ['3615090602', 'Amo Eletros', 'https://admin.ecfconsultoria.com.br/implementacao/'.$e->implementacao->token],
        ]);

        $this->artisan('polos:reconciliar-planilha', ['--file' => $file, '--apply' => true])->assertExitCode(0);
        $this->assertSame('1164686271', $e->fresh()->cust_id, 'Sem --trocar-cust nada muda.');

        $this->artisan('polos:reconciliar-planilha', ['--file' => $file, '--apply' => true, '--trocar-cust' => true])->assertExitCode(0);
        $this->assertSame('3615090602', $e->fresh()->cust_id, 'Com --trocar-cust reponta.');
        @unlink($file);
    }

    public function test_dry_run_nao_grava(): void
    {
        $e = $this->empresaComFicha('Loja Dry', 'tok'.str_repeat('d', 45));
        $file = $this->csv(['Cust ID', 'Loja', 'Link da Planilha'], [
            ['3620327877', 'Loja Dry', 'https://admin.ecfconsultoria.com.br/implementacao/'.$e->implementacao->token],
        ]);

        $this->artisan('polos:reconciliar-planilha', ['--file' => $file])->assertExitCode(0);
        $this->assertNull($e->fresh()->cust_id);
        @unlink($file);
    }

    public function test_nao_toca_registro_de_publicador(): void
    {
        $e = MlbEmpresa::create(['nome' => 'Loja Pub', 'projeto' => 'Assessoria', 'fase' => 'ASSESSORIA']);
        MlbImplementacao::create(['empresa_id' => $e->id, 'token' => 'tok'.str_repeat('e', 45)]);
        $file = $this->csv(['Cust ID', 'Loja', 'Link da Planilha'], [
            ['3625911634', 'Loja Pub', 'https://admin.ecfconsultoria.com.br/implementacao/'.$e->implementacao->token],
        ]);

        $this->artisan('polos:reconciliar-planilha', ['--file' => $file, '--apply' => true])->assertExitCode(0);
        $this->assertNull($e->fresh()->cust_id, 'Escopo é rígido: POLOS apenas.');
        @unlink($file);
    }

    public function test_duas_linhas_para_o_mesmo_registro_so_a_primeira_faz_backfill(): void
    {
        // Caso KL Móveis: mesmo nome e mesmo gmail nas duas linhas, custs diferentes.
        // O token é ambíguo, então quem casa é o fallback nome+gmail — e ele casaria as
        // DUAS com o mesmo registro. A primeira linha fica com ele; a segunda tem de virar
        // cadastro novo no sync, e o dry-run precisa dizer isso ANTES do --apply.
        $e = $this->empresaComFicha('KL Móveis', 'tok'.str_repeat('f', 45), null, 'ecf.179@x.com');

        $file = $this->csv(['Cust ID', 'Loja', 'gmail colaborador'], [
            ['3615592884', 'KL Móveis', 'ecf.179@x.com'],
            ['3403152802', 'KL Móveis', 'ecf.179@x.com'],
        ]);

        $this->artisan('polos:reconciliar-planilha', ['--file' => $file, '--apply' => true])
            ->expectsOutputToContain('já reivindicado pela linha')
            ->assertExitCode(0);

        $this->assertSame('3615592884', $e->fresh()->cust_id, 'A primeira linha fica com o registro.');
        $this->assertSame(1, MlbEmpresa::count(), 'A reconciliação não cria — quem cria é o sync.');

        @unlink($file);
    }
}
