<?php

namespace Tests\Feature\Polos;

use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * "Baixar planilha" do Painel Polos — .xlsx com uma coluna por campo, cabeçalho
 * congelado e AutoFiltro já ligado.
 *
 * O que os testes protegem (e não é óbvio no código):
 *   - `cust_id` tem de sair como TEXTO: numérico, o Excel mostraria 2,42505E+09.
 *   - Datas saem como número de série do Excel; como texto o filtro de data e a
 *     ordenação da planilha param de funcionar.
 *   - Colunas financeiras são admin-only — o painel também só as mostra pra admin.
 *   - `ids` recorta a exportação ao que está na tela (funis + busca já aplicados).
 *
 * @group polos
 */
class ExportarPainelPlanilhaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Planilhas abertas no teste, liberadas no tearDown.
     *
     * PhpSpreadsheet guarda a planilha inteira em memória e as worksheets seguram uma
     * referência ao parent — sem `disconnectWorksheets()` o arquivo de teste sozinho
     * estourava os 512 MB do processo ao rodar a suíte de Polos inteira.
     *
     * @var array<int,\PhpOffice\PhpSpreadsheet\Spreadsheet>
     */
    private array $abertas = [];

    protected function setUp(): void
    {
        parent::setUp();

        // O bloco financeiro (admin) passa por montarCockpit() → Adman/ECF Drive. Sem o
        // fake, cada teste espera o timeout da rede de verdade (20-30s por teste) e as
        // respostas acumuladas estouravam os 512 MB do processo.
        Http::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->abertas as $planilha) {
            $planilha->disconnectWorksheets();
        }
        $this->abertas = [];

        parent::tearDown();
    }

    private function empresaPolos(array $opts = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome'    => 'Polo ' . Str::random(4),
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
        ], $opts));
    }

    private function userComPermissao(string $permission): User
    {
        $slug = 'setor-' . Str::random(6);

        $setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Setor ' . $slug,
            'slug'       => $slug,
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('setor_permissoes')->insert([
            'setor_id'       => $setorId,
            'permission_key' => $permission,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $user = User::factory()->create(['role' => 'consultor']);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $setorId,
            'cargo_id'     => null,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user->fresh();
    }

    /** Baixa a planilha e devolve a aba já lida pelo PhpSpreadsheet. */
    private function baixarEAbrir(User $user, array $payload = []): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $resposta = $this->actingAs($user)
            ->post(route('mlb.polos-painel.exportar'), $payload);

        $resposta->assertOk();
        $resposta->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $tmp = tempnam(sys_get_temp_dir(), 'polos') . '.xlsx';
        file_put_contents($tmp, $resposta->streamedContent());

        $planilha = IOFactory::load($tmp);
        @unlink($tmp);

        $this->abertas[] = $planilha;

        return $planilha->getActiveSheet();
    }

    /** Cabeçalhos da linha 1, na ordem. */
    private function cabecalhos(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $aba): array
    {
        $out = [];
        foreach ($aba->getRowIterator(1, 1) as $linha) {
            foreach ($linha->getCellIterator() as $celula) {
                $out[] = (string) $celula->getValue();
            }
        }

        return $out;
    }

    // ─── Estrutura da planilha ───────────────────────────────────────────────

    public function test_planilha_sai_com_uma_coluna_por_campo(): void
    {
        $this->empresaPolos(['nome' => 'Loja A']);

        $aba    = $this->baixarEAbrir(User::factory()->create(['role' => 'admin']));
        $header = $this->cabecalhos($aba);

        // Identidade primeiro, depois a ordem da lente "Geral" do painel.
        $this->assertSame('Empresa', $header[0]);
        $this->assertSame('Cust ID', $header[1]);
        $this->assertSame('Situação', $header[2]);

        foreach (['Fase', 'Polo', 'Responsável', 'Onboarding', 'Envio', 'ME1', 'ERP', 'Link do Whats'] as $col) {
            $this->assertContains($col, $header, "coluna '{$col}' sumiu da planilha");
        }

        // Sem colunas repetidas — cabeçalho duplicado quebra o filtro do Sheets.
        $this->assertSame(count($header), count(array_unique($header)));
    }

    public function test_cabecalho_congelado_e_autofiltro_ligado(): void
    {
        $this->empresaPolos();
        $aba = $this->baixarEAbrir(User::factory()->create(['role' => 'admin']));

        // Congela a linha 1 e a coluna A (Empresa continua visível ao rolar pra direita).
        $this->assertSame('B2', $aba->getFreezePane());

        // AutoFiltro cobrindo cabeçalho + corpo: é o "já vindo podendo filtrar".
        $faixa = $aba->getAutoFilter()->getRange();
        $this->assertNotEmpty($faixa, 'planilha saiu sem AutoFiltro');
        $this->assertStringStartsWith('A1:', $faixa);
    }

    /** Numérico, o Excel mostraria 2,42505E+09 e o cust_id ficaria ilegível. */
    public function test_cust_id_sai_como_texto(): void
    {
        $this->empresaPolos(['nome' => 'Loja A', 'cust_id' => '2425054445']);

        $aba = $this->baixarEAbrir(User::factory()->create(['role' => 'admin']));

        $this->assertSame('Loja A', $aba->getCell('A2')->getValue());
        $this->assertSame('2425054445', $aba->getCell('B2')->getValue());
        $this->assertIsString($aba->getCell('B2')->getValue());
    }

    /** Como texto, "10/01" ordenaria antes de "09/12" e o filtro de data não existiria. */
    public function test_data_sai_como_data_de_verdade(): void
    {
        $this->empresaPolos(['nome' => 'Loja A']);

        $aba    = $this->baixarEAbrir(User::factory()->create(['role' => 'admin']));
        $header = $this->cabecalhos($aba);
        $letra  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            array_search('Cadastro', $header, true) + 1
        );

        $valor = $aba->getCell($letra . '2')->getValue();
        $this->assertIsNumeric($valor, 'Cadastro saiu como texto — quebra filtro e ordenação de data');
        $this->assertSame(
            now()->format('d/m/Y'),
            \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($valor)->format('d/m/Y')
        );
    }

    // ─── Recorte de linhas ───────────────────────────────────────────────────

    public function test_ids_recortam_a_exportacao_ao_que_esta_na_tela(): void
    {
        $a = $this->empresaPolos(['nome' => 'AAA Visível']);
        $this->empresaPolos(['nome' => 'ZZZ Filtrada fora']);

        $aba = $this->baixarEAbrir(User::factory()->create(['role' => 'admin']), ['ids' => [$a->id]]);

        $this->assertSame(2, $aba->getHighestDataRow(), 'deveria sair só o cabeçalho + 1 linha');
        $this->assertSame('AAA Visível', $aba->getCell('A2')->getValue());
    }

    public function test_sem_ids_exporta_o_painel_inteiro(): void
    {
        $this->empresaPolos(['nome' => 'AAA']);
        $this->empresaPolos(['nome' => 'BBB']);

        $aba = $this->baixarEAbrir(User::factory()->create(['role' => 'admin']));

        $this->assertSame(3, $aba->getHighestDataRow());
    }

    /** Arquivada saiu do projeto: não conta em nada, nem na planilha. */
    public function test_empresa_arquivada_nao_entra(): void
    {
        $this->empresaPolos(['nome' => 'Ativa']);
        $this->empresaPolos(['nome' => 'Arquivada', 'arquivado_em' => now()]);

        $aba = $this->baixarEAbrir(User::factory()->create(['role' => 'admin']));

        $this->assertSame(2, $aba->getHighestDataRow());
        $this->assertSame('Ativa', $aba->getCell('A2')->getValue());
    }

    public function test_empresa_fora_do_projeto_polos_nao_entra(): void
    {
        $this->empresaPolos(['nome' => 'Do Polos']);
        MlbEmpresa::create([
            'nome' => 'De Assessoria', 'tipo' => 'ASSESSORIA', 'projeto' => 'ASSESSORIA',
            'fase' => null, 'estagio' => 'Não Listado',
        ]);

        $aba = $this->baixarEAbrir(User::factory()->create(['role' => 'admin']));

        $this->assertSame(2, $aba->getHighestDataRow());
        $this->assertSame('Do Polos', $aba->getCell('A2')->getValue());
    }

    // ─── Conteúdo das células ────────────────────────────────────────────────

    public function test_situacao_acumula_os_flags_da_empresa(): void
    {
        $this->empresaPolos(['nome' => 'Loja A', 'problema' => true, 'problema_desconsidera_meta' => true]);

        $aba = $this->baixarEAbrir(User::factory()->create(['role' => 'admin']));

        $situacao = (string) $aba->getCell('C2')->getValue();
        $this->assertStringContainsString('Com problema', $situacao);
        $this->assertStringContainsString('Desconsiderada da meta', $situacao);
        // Sem ficha de onboarding também é uma pendência que a planilha precisa mostrar.
        $this->assertStringContainsString('Sem ficha', $situacao);
    }

    public function test_empresa_sem_pendencia_sai_como_sem_pendencias(): void
    {
        $empresa = $this->empresaPolos(['nome' => 'Loja A']);
        MlbImplementacao::create([
            'empresa_id'      => $empresa->id,
            'token'           => Str::random(32),
            'link_enviado_em' => now(),
        ]);

        $aba = $this->baixarEAbrir(User::factory()->create(['role' => 'admin']));

        $this->assertSame('Sem pendências', $aba->getCell('C2')->getValue());
    }

    // ─── Permissões ──────────────────────────────────────────────────────────

    /** As fin_* são admin-only na tela; a planilha segue a mesma régua. */
    public function test_colunas_financeiras_sao_admin_only(): void
    {
        $this->empresaPolos();

        $header = $this->cabecalhos($this->baixarEAbrir(User::factory()->create(['role' => 'admin'])));
        $this->assertContains('Faturamento', $header);
        $this->assertContains('% da meta', $header);

        $header = $this->cabecalhos($this->baixarEAbrir($this->userComPermissao('mlb.projetos')));
        $this->assertNotContains('Faturamento', $header);
        $this->assertNotContains('Meta', $header);
        $this->assertNotContains('ADS', $header);
        // O operacional continua inteiro pra quem não é admin.
        $this->assertContains('Empresa', $header);
        $this->assertContains('Fase', $header);
    }

    public function test_operacional_do_polos_pode_baixar(): void
    {
        $this->empresaPolos(['nome' => 'Loja A']);

        $aba = $this->baixarEAbrir($this->userComPermissao('mlb.projetos'));

        $this->assertSame('Loja A', $aba->getCell('A2')->getValue());
    }

    public function test_sem_permissao_recebe_403(): void
    {
        $this->empresaPolos();

        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->post(route('mlb.polos-painel.exportar'))
            ->assertForbidden();
    }
}
