<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use App\Support\Publicador\AlavancasLiberadas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-10 (D-01/D-06): acesso só admin, nas duas âncoras, sem chamar o ML ao abrir a página. */
class AcessoAlavancasTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function url(string $conta, string $sufixo = ''): string
    {
        return "/mlb/anuncios/publicador/empresas/{$conta}/alavancas".($sufixo === '' ? '' : "/{$sufixo}");
    }

    /** O layout consulta o ECF Drive (sinais); só conta o que vai ao Mercado Livre. */
    private function chamadasAoMl(): array
    {
        return array_values(array_filter($this->chamadas, fn (array $c) => str_contains($c['url'], 'mercadolibre')));
    }

    public function test_consultor_leva_403_em_todas_as_familias_de_rota(): void
    {
        $this->montarAlavancas();
        $consultor = User::factory()->create(['role' => 'consultor']);
        $chave = $this->ancora->chaveContaMl();

        foreach (['', 'panorama', 'promocoes', 'produtos', 'cupons', 'exclusao', 'publicidade', 'atacado', 'historico', 'historico/1'] as $sufixo) {
            $this->actingAs($consultor)->get($this->url($chave, $sufixo))->assertForbidden();
        }
        foreach (['analise', 'atacado/MLB1/recomendacoes'] as $sufixo) {
            $this->actingAs($consultor)->postJson($this->url($chave, $sufixo), [])->assertForbidden();
        }
        $this->assertSame([], $this->chamadas);
    }

    public function test_chave_inexistente_arquivada_ou_sem_programa_da_404(): void
    {
        $this->montarAlavancas();
        $arquivada = MlbEmpresa::create(['nome' => 'Arquivada', 'projeto' => 'POLOS', 'arquivado_em' => now()]);
        $semPrograma = MlbEmpresa::create(['nome' => 'Sem programa', 'projeto' => '']);

        foreach (['empresa-999999', 'company-999999', "empresa-{$arquivada->id}", "empresa-{$semPrograma->id}"] as $chave) {
            $this->actingAs($this->admin)->get($this->url($chave))->assertNotFound();
            $this->actingAs($this->admin)->getJson($this->url($chave, 'panorama'))->assertNotFound();
        }
    }

    public function test_company_ligada_a_mlb_empresa_redireciona_para_a_chave_canonica(): void
    {
        $this->montarAlavancas();
        $company = Company::factory()->create();
        $empresa = MlbEmpresa::create(['nome' => 'Polo', 'projeto' => 'POLOS', 'company_id' => $company->id]);

        $this->actingAs($this->admin)->get($this->url("company-{$company->id}"))
            ->assertRedirect(route('mlb.anuncios.publicador.alavancas.index', ['conta' => "empresa-{$empresa->id}"]));
    }

    public function test_abre_para_company_e_para_mlb_empresa_sem_company_sem_chamar_o_ml(): void
    {
        foreach (['company', 'mlb_empresa'] as $ancora) {
            $this->chamadas = [];
            $this->montarAlavancas($ancora);

            $r = $this->actingAs($this->admin)->get($this->url($this->ancora->chaveContaMl()))->assertOk();
            $page = $r->viewData('page');

            $this->assertSame('Mlb/Publicador/Alavancas', $page['component']);
            $this->assertSame($this->ancora->chaveContaMl(), $page['props']['empresa']['chave']);
            $this->assertSame('ativo', $page['props']['empresa']['token']);
            $this->assertTrue($page['props']['alavancas']['liberada']);
            $this->assertTrue($page['props']['alavancas']['tem_conta']);
            $this->assertSame(['itens_por_lote' => 50, 'itens_por_analise' => 10], $page['props']['alavancas']['limites']);
            $this->assertIsArray($page['props']['alavancas']['alertas']);
            $this->assertSame([], $this->chamadasAoMl(), 'renderizar a página não chama o ML');
        }
    }

    public function test_liberada_so_na_publicacao_nao_libera_as_alavancas(): void
    {
        $this->montarAlavancas('company', false);
        config(['publicador.contas_liberadas' => ['companies' => [$this->ancora->id], 'mlb_empresas' => []]]);

        $props = $this->actingAs($this->admin)->get($this->url($this->ancora->chaveContaMl()))->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['alavancas']['liberada']);
        $this->assertSame(AlavancasLiberadas::MOTIVO, $props['alavancas']['motivo']);
    }

    public function test_conta_sem_token_abre_em_reconectar_e_o_json_da_409(): void
    {
        $this->montarAlavancas();
        $sem = Company::factory()->create();

        $props = $this->actingAs($this->admin)->get($this->url("company-{$sem->id}"))->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['alavancas']['tem_conta']);
        $this->assertSame('sem_token', $props['empresa']['token']);

        $this->actingAs($this->admin)->getJson($this->url("company-{$sem->id}", 'panorama'))
            ->assertStatus(409)->assertJson(['regra' => 'V-ACC-01']);
        $this->assertSame([], $this->chamadasAoMl());
    }

    public function test_token_revogado_tambem_vira_reconectar(): void
    {
        $this->montarAlavancas();
        $c = Company::factory()->create();
        MlToken::create(['company_id' => $c->id, 'ml_user_id' => '1', 'access_token' => 'x', 'refresh_token' => 'y', 'expires_at' => now()->addHour(), 'status' => 'revoked']);

        $this->actingAs($this->admin)->getJson($this->url("company-{$c->id}", 'cupons'))->assertStatus(409);
        $this->assertSame([], $this->chamadas);
    }
}
