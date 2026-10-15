<?php

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\MlbEmpresa;
use App\Models\PoloAdsStatus;
use App\Models\PoloFaturamentoSnapshot;
use App\Models\PolosComentario;
use App\Models\PolosReuniaoMes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TKT-0018 — "Planilha" do Faturamento Polos (/polos/empresas): a lista da tela em .xlsx,
 * com Cust ID, os números do mês, a reunião do mês e os comentários da empresa.
 *
 * O que os testes protegem (e não é óbvio no código):
 *   - A planilha sai do MESMO montarPolos() da tela — o front só manda quais linhas.
 *   - `custs` recorta E ordena como a tabela (filtros, busca e ordenação já aplicados).
 *   - O mês selecionado vale para os números e para a reunião; a coluna "Campanhas" só
 *     existe no mês corrente, como o filtro da tela.
 *   - Comentários de todos os meses na mesma célula, o mais recente primeiro.
 *
 * @group polos
 */
class ExportarFaturamentoEmpresasTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,\PhpOffice\PhpSpreadsheet\Spreadsheet> liberadas no tearDown (memória) */
    private array $abertas = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'  => 'test-key',
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->abertas as $planilha) {
            $planilha->disconnectWorksheets();
        }
        $this->abertas = [];

        parent::tearDown();
    }

    /** ECF Drive com o arquivo POLOS MENSAL e as linhas informadas (o mês corrente entra sempre). */
    private function ecfDrive(array $linhas = [], bool $comArquivo = true): void
    {
        Http::fake([
            '*/files/*/json*' => Http::response([
                'filename' => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                'returned' => count($linhas),
                'limited'  => false,
                'rows'     => $linhas,
            ], 200),
            '*/files*' => Http::response([
                'data'  => $comArquivo ? [[
                    'id'           => 'arquivo-polos-01',
                    'filename'     => 'SFTP_ECF_COMERCIO_POLOS_MENSAL.csv',
                    'etlStatus'    => 'done',
                    'downloadedAt' => '2026-10-01T15:00:10Z',
                ]] : [],
                'total' => $comArquivo ? 1 : 0,
            ], 200),
        ]);
    }

    private function empresa(string $nome, string $cust, array $opts = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome'    => $nome,
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
            'cust_id' => $cust,
        ], $opts));
    }

    private function faturou(string $cust, float $valor, float $ads = 0): void
    {
        PoloFaturamentoSnapshot::create([
            'mes'                => now()->format('Ym'),
            'cust_id'            => $cust,
            'faturamento'        => $valor,
            'faturamento_moveis' => $valor,
            'ads'                => $ads,
            'synced_at'          => now(),
        ]);
    }

    private function comentario(string $cust, string $mes, string $texto, User $autor, string $em): void
    {
        $c = PolosComentario::create([
            'cust_id' => $cust, 'mes' => $mes, 'user_id' => $autor->id, 'autor_nome' => $autor->name, 'texto' => $texto,
        ]);
        $c->forceFill(['created_at' => Carbon::parse($em)])->save();
    }

    private function userComPermissao(string $permission): User
    {
        $slug    = 'setor-' . Str::random(6);
        $setorId = DB::table('setores')->insertGetId([
            'nome' => 'Setor ' . $slug, 'slug' => $slug, 'active' => true, 'is_system' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('setor_permissoes')->insert([
            'setor_id' => $setorId, 'permission_key' => $permission, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create(['role' => 'consultor']);
        DB::table('user_setores')->insert([
            'user_id' => $user->id, 'setor_id' => $setorId, 'cargo_id' => null, 'is_principal' => true,
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    private function baixar(User $user, array $payload = []): Worksheet
    {
        $resposta = $this->actingAs($user)->post(route('polos.empresas.exportar'), $payload);

        $resposta->assertOk();
        $resposta->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmp = tempnam(sys_get_temp_dir(), 'polosfat') . '.xlsx';
        file_put_contents($tmp, $resposta->streamedContent());
        $planilha = IOFactory::load($tmp);
        @unlink($tmp);
        $this->abertas[] = $planilha;

        return $planilha->getActiveSheet();
    }

    /** Linha N da aba como [cabeçalho => valor]. */
    private function linha(Worksheet $aba, int $n): array
    {
        $cab = $aba->rangeToArray('A1:' . $aba->getHighestDataColumn() . '1')[0];
        $val = $aba->rangeToArray('A' . $n . ':' . $aba->getHighestDataColumn() . $n, null, false, false)[0];

        return array_combine($cab, $val);
    }

    // ─── Conteúdo ────────────────────────────────────────────────────────────

    public function test_planilha_traz_cust_numeros_reuniao_e_comentarios(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Débora Lima']);
        $this->ecfDrive();

        $this->empresa('Loja Alfa', '2425054445', ['problema' => true, 'problema_nota' => 'Sem estoque']);
        $this->empresa('Loja Beta', '3308946595', ['fase' => 'M3']);
        $this->faturou('2425054445', 1500, 320);
        $this->faturou('3308946595', 900);

        PolosReuniaoMes::create([
            'cust_id' => '2425054445', 'mes' => now()->format('Ym'), 'feita' => true,
            'user_id' => $admin->id, 'marcado_por_nome' => $admin->name, 'marcado_em' => Carbon::parse('2026-10-09 14:00'),
        ]);
        $this->comentario('2425054445', '202609', 'Foi pra consultoria!', $admin, '2026-09-17 11:55');
        $this->comentario('2425054445', '202610', 'Voltou a vender.', $admin, '2026-10-05 09:27');

        $aba = $this->baixar($admin);

        $cabecalho = array_keys($this->linha($aba, 1));
        foreach (['Empresa', 'Cust ID', 'Polo', 'Fase', 'Status', 'Faturamento (móveis)', 'Meta', '% da meta', 'ADS',
                  'ADS ligado/desligado', 'Campanhas', 'Problema', 'Nota do problema', 'Reunião do mês',
                  'Reunião marcada por', 'Comentários'] as $col) {
            $this->assertContains($col, $cabecalho, "coluna '{$col}' sumiu da planilha");
        }
        $this->assertSame('B2', $aba->getFreezePane());
        $this->assertStringStartsWith('A1:', (string) $aba->getAutoFilter()->getRange());

        // Mesma ordem da tela sem filtro: faturamento desc.
        $this->assertSame(3, $aba->getHighestDataRow());
        $alfa = $this->linha($aba, 2);
        $beta = $this->linha($aba, 3);

        $this->assertSame('Loja Alfa', $alfa['Empresa']);
        $this->assertSame('2425054445', $alfa['Cust ID'], 'Cust ID tem de sair como texto, não 2,42505E+09');
        $this->assertSame('Arapongas', $alfa['Polo']);
        $this->assertSame('M2', $alfa['Fase']);
        $this->assertSame('No alvo', $alfa['Status']);
        $this->assertEqualsWithDelta(1500, $alfa['Faturamento (móveis)'], 0.001);
        $this->assertEqualsWithDelta(1000, $alfa['Meta'], 0.001);
        // Valor real (150%), não a barra travada em 100 da tela.
        $this->assertEqualsWithDelta(150, $alfa['% da meta'], 0.001);
        $this->assertEqualsWithDelta(320, $alfa['ADS'], 0.001);
        $this->assertSame('Sim', $alfa['Problema']);
        $this->assertSame('Sem estoque', $alfa['Nota do problema']);
        $this->assertSame('Feita', $alfa['Reunião do mês']);
        $this->assertSame('Débora Lima em 09/10/2026 14:00', $alfa['Reunião marcada por']);

        // Os dois comentários, o mais recente primeiro, cada um com mês, autor e data.
        $coms = (string) $alfa['Comentários'];
        $this->assertStringContainsString("Outubro/2026 · Débora Lima · 05/10/2026 09:27\nVoltou a vender.", $coms);
        $this->assertStringContainsString("Setembro/2026 · Débora Lima · 17/09/2026 11:55\nFoi pra consultoria!", $coms);
        $this->assertLessThan(strpos($coms, 'Foi pra consultoria!'), strpos($coms, 'Voltou a vender.'));
        // Quebra de linha ligada na coluna, senão o Excel mostra só a primeira linha.
        $letraComs = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search('Comentários', $cabecalho, true) + 1);
        $this->assertTrue($aba->getStyle($letraComs . '2')->getAlignment()->getWrapText());

        // Sem marcação = "Não feita", como a tela; sem comentário = célula vazia.
        $this->assertSame('Loja Beta', $beta['Empresa']);
        $this->assertSame('Em progresso', $beta['Status'], 'M3 com R$ 900 sobre limiar de R$ 4.000');
        $this->assertSame('Não feita', $beta['Reunião do mês']);
        $this->assertNull($beta['Reunião marcada por']);
        $this->assertNull($beta['Comentários']);
        $this->assertNull($beta['Problema']);
    }

    public function test_campanhas_e_ads_ligado_seguem_a_leitura_da_adman(): void
    {
        $this->ecfDrive();
        $this->empresa('Ativa', '111');
        $this->empresa('Sem campanha', '222');
        $this->empresa('Ninguém viu', '333');
        PoloAdsStatus::create(['cust_id' => '111', 'campanhas_ativas' => 2, 'campanhas_total' => 3, 'verificado_em' => now()->subHour()]);
        PoloAdsStatus::create(['cust_id' => '222', 'campanhas_ativas' => 0, 'campanhas_total' => 0, 'verificado_em' => now()->subHour()]);

        $aba    = $this->baixar(User::factory()->create(['role' => 'admin']), ['custs' => ['111', '222', '333']]);
        $linhas = [$this->linha($aba, 2), $this->linha($aba, 3), $this->linha($aba, 4)];

        $this->assertSame(['Ligado', 'Desligado', null], array_column($linhas, 'ADS ligado/desligado'));
        $this->assertSame(['Ativa', 'Sem campanha', 'Não verificado'], array_column($linhas, 'Campanhas'));
    }

    // ─── Recorte: o que está na tela ─────────────────────────────────────────

    public function test_custs_recortam_e_ordenam_como_a_tabela(): void
    {
        $this->ecfDrive();
        $this->empresa('Loja A', '111');
        $this->empresa('Loja B', '222');
        $this->empresa('Loja C', '333');

        // A tela filtrou a B fora e ordenou C antes de A.
        $aba = $this->baixar(User::factory()->create(['role' => 'admin']), ['custs' => ['333', '111']]);

        $this->assertSame(3, $aba->getHighestDataRow(), 'deveria sair só o cabeçalho + 2 linhas');
        $this->assertSame('Loja C', $aba->getCell('A2')->getValue());
        $this->assertSame('Loja A', $aba->getCell('A3')->getValue());
    }

    /** Mês fechado: números e reunião daquele mês; sem a coluna Campanhas (estado de agora). */
    public function test_mes_selecionado_vale_para_a_reuniao_e_esconde_campanhas_no_mes_fechado(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->ecfDrive([[
            'TIM_MONTH_ID' => '202609', 'COMPARATIVO' => 'FECHADO', 'CUS_CUST_ID_SEL' => '444,0',
            'MESES_NO_PROGRAMA' => '1', 'CUS_NICKNAME' => 'LOJA SETEMBRO', 'LOCALIDADE' => 'Bento',
        ]]);
        $this->empresa('Loja Setembro', '444', ['fase' => 'M3']);
        PolosReuniaoMes::create([
            'cust_id' => '444', 'mes' => '202609', 'feita' => true,
            'user_id' => $admin->id, 'marcado_por_nome' => $admin->name, 'marcado_em' => now(),
        ]);

        $setembro = $this->linha($this->baixar($admin, ['mes' => '202609']), 2);
        $this->assertNotContains('Campanhas', array_keys($setembro));
        $this->assertSame('444', $setembro['Cust ID']);
        $this->assertSame('M2', $setembro['Fase'], 'mês fechado usa a fase daquele mês, não a de hoje');
        $this->assertSame('Feita', $setembro['Reunião do mês']);

        $agora = $this->linha($this->baixar($admin), 2);
        $this->assertContains('Campanhas', array_keys($agora));
        $this->assertSame('Não feita', $agora['Reunião do mês'], 'a reunião de setembro não vale para o mês corrente');
    }

    // ─── Acesso e falhas ─────────────────────────────────────────────────────

    public function test_setor_polos_baixa_e_quem_nao_tem_acesso_recebe_403(): void
    {
        $this->ecfDrive();
        $this->empresa('Loja A', '111');

        $aba = $this->baixar($this->userComPermissao('mlb.faturamento_polos'));
        $this->assertSame('Loja A', $aba->getCell('A2')->getValue());

        // mlb.projetos (Painel) não abre o financeiro de /polos/empresas — nem a planilha dele.
        $this->actingAs($this->userComPermissao('mlb.projetos'))
            ->post(route('polos.empresas.exportar'))
            ->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->post(route('polos.empresas.exportar'))
            ->assertForbidden();
    }

    public function test_sem_arquivo_no_ecf_drive_volta_para_a_tela_com_aviso(): void
    {
        $this->ecfDrive([], comArquivo: false);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->from(route('polos.empresas'))
            ->post(route('polos.empresas.exportar'))
            ->assertRedirect(route('polos.empresas'))
            ->assertSessionHas('error');
    }

    /** Uma célula do Excel aceita até 32.767 caracteres; acima disso o arquivo abre com erro. */
    public function test_comentarios_longos_respeitam_o_limite_da_celula(): void
    {
        $m = new ReflectionMethod(PolosController::class, 'comentariosParaCelula');
        $m->setAccessible(true);

        $lista = array_fill(0, 8, [
            'texto' => str_repeat('x', 5000), 'autor' => 'Fulano', 'mes_label' => 'Outubro/2026',
            'criado_em' => '01/10/2026 10:00', 'editado_em' => null,
        ]);

        $celula = $m->invoke(app(PolosController::class), $lista);
        $this->assertLessThanOrEqual(32767, mb_strlen($celula));
        $this->assertNull($m->invoke(app(PolosController::class), []));
    }
}
