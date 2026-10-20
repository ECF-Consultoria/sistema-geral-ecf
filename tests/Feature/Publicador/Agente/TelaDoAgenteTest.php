<?php

namespace Tests\Feature\Publicador\Agente;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Models\Company;
use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Models\PubProduto;
use App\Models\User;
use App\Services\Publicador\Agente\PreparadorDaAprovacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * AG-07 do Agente ECF Publicador (20/10/2026): a tela da loja pelas rotas reais. Critério de fechamento do plano:
 * links abrem o destino real; troca de loja não mistura dados; sem abas fictícias. (O pill do header fica para
 * depois de integrar o `origin/main`.)
 */
class TelaDoAgenteTest extends TestCase
{
    use CenarioDoAgente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarAgente();
    }

    private function rota(string $nome = '', ?string $conta = null): string
    {
        return route('mlb.anuncios.publicador.agente'.$nome, ['conta' => $conta ?? "company-{$this->empresa->id}"]);
    }

    private function salvar(array $dados = [], int $versao = 0)
    {
        return $this->actingAs($this->admin)->from($this->rota())->put($this->rota('.configuracao'), [
            'versao_esperada' => $versao, 'habilitado' => true, 'modalidade' => 'gold_special',
            'estoque_minimo' => 1, 'limite_diario' => 5, 'teto_fila' => 10, ...$dados,
        ]);
    }

    public function test_so_admin_entra(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);
        $this->actingAs($consultor)->get($this->rota())->assertForbidden();
        $this->actingAs($this->admin)->get($this->rota())->assertOk();
    }

    public function test_a_tela_mostra_a_loja_desligada_sem_dado_inventado(): void
    {
        $this->actingAs($this->admin)->get($this->rota())->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Mlb/Publicador/Agente')
            ->where('agente.configuracao.existe', false)
            ->where('agente.configuracao.habilitado', false)
            ->where('agente.configuracao.versao', 0)
            ->where('agente.contadores.preparando', 0)
            ->where('agente.contadores.aguardando_aprovacao', 0)
            ->where('agente.fila', [])
            ->where('cabecalho.aba', 'configuracoes'));
    }

    public function test_salvar_exige_a_versao_vista_e_recusa_a_obsoleta(): void
    {
        $this->salvar()->assertRedirect($this->rota())->assertSessionHas('success');
        $this->assertSame(1, PubAgenteConfiguracao::firstOrFail()->versao);

        $this->salvar(['limite_diario' => 99], 0)->assertSessionHasErrors('agente');
        $this->assertSame(5, PubAgenteConfiguracao::firstOrFail()->limite_diario);

        $this->actingAs($this->admin)->put($this->rota('.configuracao'), ['habilitado' => true, 'estoque_minimo' => 1, 'limite_diario' => 1, 'teto_fila' => 1])
            ->assertSessionHasErrors('versao_esperada');
    }

    public function test_preparar_agora_pausar_e_retomar_pela_tela_e_a_fila_aponta_os_destinos_reais(): void
    {
        $this->salvar();
        $pub = $this->noPublicador();

        $this->actingAs($this->admin)->from($this->rota())->post($this->rota('.executar'), ['produto_id' => $pub->id])
            ->assertSessionHas('success');
        $this->assertSame(1, PubAgenteExecucao::count());
        $this->rodar(GerarPreparoIaJob::class);
        app(PreparadorDaAprovacao::class)->encaminharProntas();

        $this->actingAs($this->admin)->from($this->rota())->post($this->rota('.pausar'))->assertSessionHas('success');
        $this->assertSame(PubAgenteConfiguracao::PAUSADO, PubAgenteConfiguracao::firstOrFail()->controle);
        $this->actingAs($this->admin)->from($this->rota())->post($this->rota('.retomar'))->assertSessionHas('success');

        $this->actingAs($this->admin)->get($this->rota())->assertInertia(fn (Assert $p) => $p
            ->where('agente.configuracao.controle', 'ativo')
            ->where('agente.contadores.aguardando_aprovacao', 1)
            ->has('agente.fila', 1)
            ->where('agente.fila.0.estado', 'aguardando_aprovacao')
            ->where('agente.fila.0.produto.id', $pub->id)
            ->where('agente.fila.0.solicitada_por', $this->admin->name)
            ->where('agente.fila.0.oferta.url', fn ($url) => str_contains($url, "/meus/{$this->empresa->id}/aprovacao") && str_contains($url, 'oferta='))
            ->where('agente.fila.0.url_editor', route('mlb.anuncios.publicador.editor', ['produto' => $pub->id])));
    }

    public function test_produto_de_outra_loja_e_recusado_e_cada_loja_ve_so_o_seu(): void
    {
        $this->salvar();
        $outra = Company::factory()->create();
        $deOutra = PubProduto::create(['company_id' => $outra->id, 'sku' => 'X-1', 'nome' => 'De outra loja', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        $this->actingAs($this->admin)->from($this->rota())->post($this->rota('.executar'), ['produto_id' => $deOutra->id])
            ->assertSessionHasErrors('agente');
        $this->assertSame(0, PubAgenteExecucao::count());

        $this->actingAs($this->admin)->get($this->rota('', "company-{$outra->id}"))->assertInertia(fn (Assert $p) => $p
            ->where('agente.configuracao.existe', false)
            ->where('produtos', fn ($lista) => collect($lista)->pluck('id')->all() === [$deOutra->id]));
    }

    public function test_conta_inexistente_da_404_e_formato_errado_nem_casa_a_rota(): void
    {
        $this->actingAs($this->admin)->get($this->rota('', 'company-999999'))->assertNotFound();
        $this->actingAs($this->admin)->get('/mlb/anuncios/publicador/empresas/qualquer/agente')->assertNotFound();
    }
}
