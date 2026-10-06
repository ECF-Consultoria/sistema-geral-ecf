<?php

namespace Tests\Feature\Quick261005;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\MlToken;
use App\Models\Servico;
use App\Models\User;
use App\Services\Fechamento\FechamentoFonteFaturamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 261005-sm1 (T2) — o AVISO que substituiu a trava `ids-iguais`.
 *
 * Até 2026-10-05 a empresa com `adman_account_id` diferente de `ml_store_id`
 * era recusada pela API da Adman e ficava na soma diária. A trava nasceu da
 * LAURA LAR, cujo defeito era o TOKEN do Mercado Livre apontando para a conta
 * da GRAN BELO (descoberto em 2026-09-15) — não a API. O recorte protegia de
 * um defeito de CADASTRO e custava o número certo de 17 empresas.
 *
 * Decisão do usuário: usar o número da Adman para toda empresa que tenha
 * conta lá, e tratar divergência de contas como AVISO VISÍVEL, nunca como
 * troca silenciosa por um número pior.
 *
 * ⚠️ O aviso NÃO muda número nenhum e NÃO muda o exit code do comando. Ele
 * existe para alguém conferir o cadastro — uma das duas contas provavelmente
 * está errada.
 */
class AvisoContasDivergentesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->fakeApi(119_411.57);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function hojeNoDiaDaDecisao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    }

    private function fakeApi(float $grossBilling): void
    {
        Http::fake([
            '*/performance/*' => Http::response([
                'summarizedData' => ['grossBilling' => ['value' => $grossBilling]],
                'items'          => [],
            ], 200),
        ]);
    }

    private function ligarChave(): void
    {
        Configuracao::set(FechamentoFonteFaturamento::CHAVE, '1');
        // O leitor memoiza por instância e o container é singleton dentro do
        // teste — sem isto, a tela leria o valor anterior.
        app(FechamentoFonteFaturamento::class)->esquecer();
    }

    /**
     * Contrato ATIVO de Mercado Livre cobrado por tabela progressiva — sem
     * ele a empresa nem aparece nas linhas da tela
     * (`fechamentoRemoverForaDeEscopo`), e o teste de "nunca chave nova nas
     * linhas" passaria por array vazio.
     */
    private function comContratoDeMercadoLivre(Company $company): Company
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestao'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update([
            'plataforma'             => 'Mercado Livre',
            'setor'                  => Servico::SETOR_PERFORMANCE,
            'usa_tabela_progressiva' => true,
        ]);

        ContratoServico::factory()->paraServico($servico)->create([
            'company_id'       => $company->id,
            'ativo'            => true,
            'data_contratacao' => '2026-01-10',
            'valor_contratado' => 0,
        ]);

        return $company;
    }

    /** O caso MAXIGOLD SUPLEMENTOS: as duas contas cadastradas, e diferentes. */
    private function empresaComDuasContasDiferentes(string $nome = 'MAXIGOLD SUPLEMENTOS'): Company
    {
        $company = $this->comContratoDeMercadoLivre(Company::factory()->create([
            'name'             => $nome,
            'adman_account_id' => '273196837',
            'ml_store_id'      => '433720509',
        ]));

        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => '999'.$company->id,
            'access_token'  => 'token-fake',
            'refresh_token' => 'refresh-fake',
            'status'        => 'active',
            'expires_at'    => Carbon::now()->addDay(),
        ]);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 3_324.98]);

        return $company->refresh();
    }

    private function propsDoFechamento(string $mes): array
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $this->actingAs($admin)
            ->get('/administrativo/financeiro?mes='.$mes)
            ->assertOk()
            ->viewData('page')['props'];
    }

    // ─── No resumo do comando ─────────────────────────────────────────────

    /**
     * O resumo precisa sair com OS DOIS ids: o aviso serve para alguém abrir
     * o cadastro e decidir qual das duas contas está errada, e sem os ids a
     * pessoa teria de ir procurar.
     */
    #[Test]
    public function o_resumo_do_comando_lista_as_empresas_com_os_dois_ids(): void
    {
        $this->hojeNoDiaDaDecisao();

        $company = $this->empresaComDuasContasDiferentes();
        $this->ligarChave();

        // `Artisan::call()` (e não `$this->artisan()`) porque aqui o objeto do
        // teste é o TEXTO do resumo, e só esta forma devolve a saída crua.
        $exitCode = Artisan::call('fechamento:consolidar-mes', ['--mes' => '2026-09']);

        // O aviso não mexe no exit code (mesma disciplina do aviso de faixa).
        $this->assertSame(0, $exitCode);

        $saida = Artisan::output();

        $this->assertStringContainsString('A conta da Adman e a conta do Mercado Livre são diferentes', $saida);
        $this->assertStringContainsString('MAXIGOLD SUPLEMENTOS', $saida);
        $this->assertStringContainsString('Adman 273196837', $saida);
        $this->assertStringContainsString('Mercado Livre 433720509', $saida);
    }

    /**
     * Chave desligada: o faturamento não veio da Adman, então dizer "o
     * faturamento está vindo da Adman" seria mentira. Nenhuma linha de aviso.
     */
    #[Test]
    public function com_a_chave_desligada_o_comando_nao_fala_de_contas_divergentes(): void
    {
        $this->hojeNoDiaDaDecisao();

        $this->empresaComDuasContasDiferentes();

        $exitCode = Artisan::call('fechamento:consolidar-mes', ['--mes' => '2026-09']);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('A conta da Adman e a conta do Mercado Livre', Artisan::output());
    }

    /** Cadastro limpo = nenhuma linha de aviso. Aviso que aparece sempre deixa de ser aviso. */
    #[Test]
    public function sem_divergencia_o_comando_nao_imprime_nada_a_respeito(): void
    {
        $this->hojeNoDiaDaDecisao();

        $company = $this->comContratoDeMercadoLivre(Company::factory()->create([
            'name'             => 'DESK DESIGN',
            'adman_account_id' => '51493328',
            'ml_store_id'      => '51493328',
        ]));
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 167_537.54]);

        $this->ligarChave();

        $exitCode = Artisan::call('fechamento:consolidar-mes', ['--mes' => '2026-09']);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('A conta da Adman e a conta do Mercado Livre', Artisan::output());
    }

    // ─── Na tela ──────────────────────────────────────────────────────────

    /**
     * Prop da PÁGINA, com os dois ids e o link para a ficha da empresa — onde
     * as duas contas se corrigem.
     */
    #[Test]
    public function a_tela_recebe_a_lista_com_os_dois_ids_e_o_link_da_ficha(): void
    {
        $this->hojeNoDiaDaDecisao();

        $company = $this->empresaComDuasContasDiferentes();
        $this->ligarChave();

        $props = $this->propsDoFechamento('2026-10');

        $this->assertSame([
            [
                'id'                  => $company->id,
                'name'                => 'MAXIGOLD SUPLEMENTOS',
                'conta_adman'         => '273196837',
                'conta_mercado_livre' => '433720509',
                'url'                 => '/companies/'.$company->id,
            ],
        ], $props['contas_divergentes']);
    }

    /** ⛔ Nunca chave nova nos cinco literais de linha — é lista da página. */
    #[Test]
    public function o_aviso_nao_vira_chave_nova_nas_linhas(): void
    {
        $this->hojeNoDiaDaDecisao();

        $this->empresaComDuasContasDiferentes();
        $this->ligarChave();

        $props = $this->propsDoFechamento('2026-10');

        $this->assertNotEmpty($props['companies']);

        foreach ($props['companies'] as $linha) {
            $this->assertArrayNotHasKey('contas_divergentes', $linha);
            $this->assertArrayNotHasKey('conta_adman', $linha);
            $this->assertArrayNotHasKey('conta_mercado_livre', $linha);
        }
    }

    /** Chave desligada: lista vazia, nenhum aviso na tela. */
    #[Test]
    public function com_a_chave_desligada_a_tela_nao_mostra_aviso(): void
    {
        $this->hojeNoDiaDaDecisao();

        $this->empresaComDuasContasDiferentes();

        $props = $this->propsDoFechamento('2026-10');

        $this->assertSame([], $props['contas_divergentes']);
    }

    /** Uma conta só cadastrada não é divergência — não há com o que comparar. */
    #[Test]
    public function empresa_com_uma_conta_so_nao_entra_na_lista_da_tela(): void
    {
        $this->hojeNoDiaDaDecisao();

        $soMl = $this->comContratoDeMercadoLivre(Company::factory()->create([
            'name'             => 'OUZOR TIME',
            'adman_account_id' => null,
            'ml_store_id'      => '654533',
        ]));
        AdmanMetric::create(['company_id' => $soMl->id, 'reference_date' => '2026-09-10', 'revenue' => 583_611.24]);

        $this->ligarChave();

        $props = $this->propsDoFechamento('2026-10');

        $this->assertSame([], $props['contas_divergentes']);
    }

    /**
     * ⛔ A tela nunca espera a Adman: foi assim que o `cache:clear` de
     * 2026-07-30 derrubou a produção. O aviso é de CADASTRO — colunas da
     * própria empresa — e não pergunta nada à API.
     */
    #[Test]
    public function montar_o_aviso_nao_faz_nenhuma_chamada_http(): void
    {
        $this->hojeNoDiaDaDecisao();

        $this->empresaComDuasContasDiferentes();
        $this->ligarChave();

        $this->propsDoFechamento('2026-10');

        Http::assertNothingSent();
    }

    // ─── A copy ───────────────────────────────────────────────────────────

    /**
     * Copy sem jargão (regra sistêmica do projeto): a tela fala "conta da
     * Adman" e "conta do Mercado Livre", nunca `adman_account_id`,
     * `ml_store_id` ou `cust_id`. E diz o que fazer: conferir o cadastro.
     */
    #[Test]
    public function a_copy_da_tela_e_sem_jargao_e_diz_o_que_conferir(): void
    {
        $jsx = file_get_contents(resource_path('js/Pages/Admin/Financeiro.jsx'));

        $this->assertStringContainsString('ContasDivergentesAviso', $jsx);
        $this->assertStringContainsString('contas_divergentes', $jsx);
        $this->assertStringContainsString('A conta da Adman e a conta do Mercado Livre não são a mesma', $jsx);
        $this->assertStringContainsString('confira se as duas contas estão certas', $jsx);

        // O trecho do componente não pode falar o nome das colunas.
        $componente = substr(
            $jsx,
            (int) strpos($jsx, 'function ContasDivergentesAviso'),
            2_000
        );

        foreach (['adman_account_id', 'ml_store_id', 'cust_id'] as $jargao) {
            $this->assertStringNotContainsString($jargao, $componente);
        }
    }
}
