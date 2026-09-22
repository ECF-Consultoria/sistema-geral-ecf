<?php

namespace Tests\Feature\DemandasDev;

use App\Models\DevDemanda;
use App\Models\DevDemandaAtualizacao;
use App\Models\DevReuniao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Importação da planilha de gestão do time dev — com as armadilhas da planilha
 * real: ID repetido (vários "DEV-17"), prazo em texto ("sem prazo definido"),
 * nome que não é usuário ("Administrativo") e a linha de teste.
 */
class ImportarDemandasDevPlanilhaTest extends TestCase
{
    use RefreshDatabase;

    private string $arquivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arquivo = tempnam(sys_get_temp_dir(), 'demandas') . '.xlsx';
        $this->montarPlanilha();
    }

    protected function tearDown(): void
    {
        @unlink($this->arquivo);
        parent::tearDown();
    }

    private function montarPlanilha(): void
    {
        $x = new Spreadsheet();
        $data = fn (string $d) => ExcelDate::PHPToExcel(new \DateTime($d));

        $dem = $x->getActiveSheet()->setTitle('Demandas');
        $dem->fromArray(['ID', 'Demanda', 'Área', 'Escopo', 'Responsável', 'Prioridade', 'Entrada', 'Prazo'], null, 'A2');
        $dem->fromArray(['DEV-01', 'Layout da Entrada', 'Entrada', 'Compactar', 'Maycon', 'P1 - Alta', $data('2026-09-19'), $data('2026-09-25')], null, 'A3');
        $dem->fromArray(['DEV-17', 'Mapear ofertas', 'Mapeamento', null, 'Maycon', 'P3 - Backlog', $data('2026-09-19'), $data('2026-10-07')], null, 'A4');
        $dem->fromArray(['DEV-17', 'Levantar metodologia', 'Metodologia', null, 'Maycon', 'P2 - Normal', $data('2026-09-19'), $data('2026-10-07')], null, 'A5');
        $dem->fromArray(['ADM-02', 'Auditar a base', 'Fechamento', null, 'Administrativo', 'P0 - Crítica', $data('2026-09-19'), 'sem prazo definido'], null, 'A6');
        $dem->fromArray(['TESTE-01', 'TESTAR PLANILHA', 'Gestão Dev', null, 'Maycon', 'P0 - Crítica', $data('2026-09-19'), null], null, 'A7');
        $dem->setCellValue('O3', 'Onda 1');

        $atu = $x->createSheet()->setTitle('Atualizações');
        $atu->fromArray(['Data', 'Dev', 'ID', 'Status', 'Feito', 'Próxima', 'Bloqueado?', 'Motivo', 'Previsão'], null, 'A2');
        $atu->fromArray([$data('2026-09-19'), 'Erlon', 'DEV-01', 'A fazer', 'Definida', 'Levantar blocos', 'Não'], null, 'A3');
        $atu->fromArray([$data('2026-09-22'), 'Maycon', 'DEV-01', 'Em validação', 'Fiz o layout', 'aguardar Erlon', 'Sim', 'revisão do Erlon'], null, 'A4');
        $atu->fromArray([$data('2026-09-22'), 'Maycon', 'DEV-17', 'Em desenvolvimento'], null, 'A5');
        $atu->fromArray([$data('2026-09-22'), 'Maycon', 'TESTE-01', 'Concluído'], null, 'A6');

        $reu = $x->createSheet()->setTitle('Reuniões');
        $reu->fromArray(['Data', 'Pauta', 'Participantes', 'Gravação', 'Transcrição', 'IDs', 'Decisões', 'Duração'], null, 'A2');
        $reu->fromArray([$data('2026-09-19'), 'Alinhamento', 'Erlon, Maycon', 'https://drive.google.com/g', 'https://docs.google.com/t',
            'DEV-01 a DEV-24, DEV-15.1 a 15.3, ADM-01 a ADM-03', 'Entrada primeiro'], null, 'A3');

        (new Xlsx($x))->save($this->arquivo);
    }

    private function maycon(): User
    {
        return User::factory()->create(['name' => 'Maycon Gomes', 'role' => 'admin', 'active' => true]);
    }

    public function test_dry_run_nao_grava_nada(): void
    {
        $this->maycon();

        $this->artisan('demandas-dev:importar-planilha', [
            'arquivo' => $this->arquivo, '--mapa' => ['Administrativo=', 'Erlon='], '--ignorar' => ['TESTE-01'],
        ])->assertSuccessful();

        $this->assertSame(0, DevDemanda::count());
    }

    public function test_nome_sem_usuario_unico_aborta_sem_mapa(): void
    {
        $this->maycon();
        User::factory()->create(['name' => 'Maycon Silva', 'active' => true]);

        $this->artisan('demandas-dev:importar-planilha', ['arquivo' => $this->arquivo, '--apply' => true])
            ->expectsOutputToContain('Maycon: ambíguo')
            ->assertFailed();

        $this->assertSame(0, DevDemanda::count());
    }

    public function test_apply_importa_demandas_atualizacoes_e_reunioes(): void
    {
        $maycon = $this->maycon();

        $this->artisan('demandas-dev:importar-planilha', [
            'arquivo' => $this->arquivo, '--mapa' => ['Administrativo=', 'Erlon='], '--ignorar' => ['TESTE-01'], '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(['ADM-02', 'DEV-01', 'DEV-17', 'DEV-18'], DevDemanda::orderBy('codigo')->pluck('codigo')->all());

        // ID repetido: a 2ª linha "DEV-17" ganhou o número seguinte ao maior do prefixo e guarda a origem.
        $repetida = DevDemanda::where('titulo', 'Levantar metodologia')->sole();
        $this->assertSame('DEV-18', $repetida->codigo);
        $this->assertStringContainsString('ID na planilha: DEV-17 (repetido)', $repetida->observacoes);

        // Nome sem usuário: responsável nulo, nome guardado; prazo em texto vira nulo.
        $adm = DevDemanda::where('codigo', 'ADM-02')->sole();
        $this->assertNull($adm->responsavel_id);
        $this->assertNull($adm->prazo);
        $this->assertStringContainsString('Responsável na planilha: Administrativo', $adm->observacoes);
        $this->assertSame(0, $adm->prioridade);

        // Status derivado: última linha de DEV-01 (Maycon, em validação, bloqueada).
        $dev01 = DevDemanda::with('ultimaAtualizacao')->where('codigo', 'DEV-01')->sole();
        $this->assertSame($maycon->id, $dev01->responsavel_id);
        $this->assertSame('em_validacao', $dev01->statusAtual());
        $this->assertTrue($dev01->estaBloqueada());
        $this->assertSame('Onda 1', $dev01->observacoes);

        // Autor sem usuário fica com o nome.
        $primeira = DevDemandaAtualizacao::orderBy('id')->first();
        $this->assertNull($primeira->user_id);
        $this->assertSame('Erlon', $primeira->autor_nome);

        // Atualização de ID repetido vai para a 1ª linha; a da linha ignorada é descartada.
        $this->assertSame(1, DevDemanda::where('codigo', 'DEV-17')->sole()->atualizacoes()->count());
        $this->assertSame(0, $repetida->atualizacoes()->count());
        $this->assertSame(3, DevDemandaAtualizacao::count());

        // Reunião: intervalos expandidos (inclui a DEV-17 repetida); sub-IDs ignorados.
        $reuniao = DevReuniao::sole();
        $this->assertEqualsCanonicalizing(
            ['ADM-02', 'DEV-01', 'DEV-17', 'DEV-18'],
            $reuniao->demandas()->pluck('codigo')->all(),
        );
    }

    public function test_nao_importa_por_cima_de_demandas_existentes(): void
    {
        $this->maycon();
        DevDemanda::create(['codigo' => 'DEV-01', 'titulo' => 'já existe', 'prioridade' => 2, 'data_entrada' => '2026-09-01']);

        $this->artisan('demandas-dev:importar-planilha', [
            'arquivo' => $this->arquivo, '--mapa' => ['Administrativo=', 'Erlon='], '--apply' => true,
        ])->assertFailed();

        $this->assertSame(1, DevDemanda::count());
    }
}
