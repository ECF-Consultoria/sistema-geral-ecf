<?php

namespace Tests\Feature\PlanejadorFull;

use App\Http\Middleware\RestringeDominioDoPortal;
use App\Jobs\ColetarPlanejadorFullJob;
use App\Models\Company;
use App\Models\FullColeta;
use App\Models\FullCompartilhamento;
use App\Models\FullConfiguracao;
use App\Models\FullEnvio;
use App\Models\FullItem;
use App\Models\FullProduto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Link aberto do Planejador FULL para o cliente (decisão do usuário em 15/10/2026): sem
 * login, só a empresa do link, operando tudo. Quem tem o link altera dados — então o que
 * importa aqui é que ele (1) só alcança a empresa dele e (2) morre quando trocado ou desligado.
 */
class PlanejadorFullLinkClienteTest extends TestCase
{
    use RefreshDatabase;

    private User $equipe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->equipe = User::factory()->create(['role' => 'consultor']);
    }

    private function empresa(string $nome, string $cod): Company
    {
        $c = Company::factory()->create(['name' => $nome, 'active' => true]);
        FullColeta::create(['company_id' => $c->id, 'status' => 'ok', 'origem' => 'agendada', 'iniciado_em' => now(), 'concluido_em' => now(), 'produtos' => 1, 'pedidos' => 1]);
        FullProduto::create(['company_id' => $c->id, 'inventory_id' => $cod, 'sku' => "SKU-{$cod}", 'titulo' => "Produto {$cod}", 'aptas' => 10]);

        return $c;
    }

    /** Gera o link pela tela interna e devolve o token (o fim da URL). */
    private function gerarLink(Company $c): string
    {
        $url = $this->actingAs($this->equipe)->postJson(route('planejador-full.compartilhar', $c))
            ->assertOk()->json('compartilhamento.url');

        auth()->logout();

        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_equipe_gera_link_e_o_token_nao_fica_em_texto_no_banco(): void
    {
        $c = $this->empresa('WENUS', 'OKFW19104');

        $resp = $this->actingAs($this->equipe)->postJson(route('planejador-full.compartilhar', $c))->assertOk();
        $url = $resp->json('compartilhamento.url');
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->assertMatchesRegularExpression('#/planejador/[A-Za-z0-9]{48}$#', $url);
        $this->assertTrue($resp->json('compartilhamento.ativo'));

        $linha = FullCompartilhamento::first();
        $this->assertSame(hash('sha256', $token), $linha->token_hash);
        $this->assertStringNotContainsString($token, (string) $linha->getRawOriginal('token_cifrado'));
        $this->assertSame($this->equipe->id, $linha->criado_por);
    }

    public function test_cliente_abre_o_link_sem_login_e_ve_so_a_empresa_dele(): void
    {
        $a = $this->empresa('WENUS', 'OKFW19104');
        $this->empresa('OUTRA LOJA', 'ZZZZ00001');
        $token = $this->gerarLink($a);

        $this->assertGuest();
        $resp = $this->get(route('planejador-publico.show', $token));

        $resp->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page
                ->component('PlanejadorFull/Publico')
                ->where('empresa.id', $a->id)
                ->has('produtos', 1)
                ->where('produtos.0.cod', 'OKFW19104')
                ->missing('outrasEmpresas')
                ->missing('compartilhamento')
                ->where('rotas.config', "/planejador/{$token}/config"));

        $this->assertSame(1, FullCompartilhamento::first()->acessos);
        $this->assertNotNull(FullCompartilhamento::first()->ultimo_acesso_em);
    }

    public function test_token_errado_trocado_ou_desligado_da_404(): void
    {
        $c = $this->empresa('WENUS', 'OKFW19104');
        $antigo = $this->gerarLink($c);

        $this->get(route('planejador-publico.show', str_repeat('a', 48)))->assertNotFound();

        $novo = $this->gerarLink($c);
        $this->get(route('planejador-publico.show', $antigo))->assertNotFound();
        $this->get(route('planejador-publico.show', $novo))->assertOk();

        $this->actingAs($this->equipe)->deleteJson(route('planejador-full.compartilhar.revogar', $c))
            ->assertOk()->assertJsonPath('compartilhamento.ativo', false);
        auth()->logout();

        $this->get(route('planejador-publico.show', $novo))->assertNotFound();
        // Revogar corta também a ação de uma aba que já estava aberta.
        $this->putJson(route('planejador-publico.itens', $novo), ['itens' => ['OKFW19104' => ['fora' => true]]])->assertNotFound();
    }

    public function test_cliente_opera_tudo_pelo_link_e_so_na_empresa_dele(): void
    {
        $a = $this->empresa('WENUS', 'OKFW19104');
        $b = $this->empresa('OUTRA LOJA', 'ZZZZ00001');
        $token = $this->gerarLink($a);

        $this->putJson(route('planejador-publico.itens', $token), ['itens' => ['OKFW19104' => ['qtdManual' => 40]]])->assertOk();
        $this->putJson(route('planejador-publico.skus', $token), ['skus' => ['SKU-OKFW19104' => ['galpao' => 300]]])->assertOk();

        $config = ['preparo' => 1, 'agenda' => 1, 'transito' => 1, 'recebimento' => 1, 'proximoDespacho' => '', 'intervaloEnvios' => 7,
            'segA' => 5, 'segB' => 4, 'segC' => 3, 'fatorSazonal' => 1, 'limiteExcesso' => 60, 'leadTimeFornecedor' => 10, 'intervaloCompras' => 20,
            'abcA' => 0.8, 'abcB' => 0.95];
        $this->putJson(route('planejador-publico.config', $token), ['config' => $config, 'fornecedores' => []])->assertOk();

        $this->assertSame(40, FullItem::where('company_id', $a->id)->first()->qtd_manual);
        $this->assertSame(1, FullConfiguracao::where('company_id', $a->id)->first()->config['preparo']);
        $this->assertNull(FullConfiguracao::where('company_id', $a->id)->first()->updated_by);
        $this->assertSame(0, FullItem::where('company_id', $b->id)->count());

        // Código ML de outra empresa: recusado.
        $this->postJson(route('planejador-publico.envios.store', $token), ['dataDespacho' => '2026-10-20', 'itens' => [['cod' => 'ZZZZ00001', 'quantidade' => 5]]])
            ->assertStatus(422);

        $this->postJson(route('planejador-publico.envios.store', $token), ['dataDespacho' => '2026-10-20', 'itens' => [['cod' => 'OKFW19104', 'quantidade' => 50]]])
            ->assertOk()->assertJsonPath('pendentes.OKFW19104', 50);

        $envio = FullEnvio::where('company_id', $a->id)->first();
        $this->assertSame(FullEnvio::ORIGEM_CLIENTE, $envio->origem);
        $this->assertNull($envio->criado_por);

        // Envio de OUTRA empresa pelo link da primeira: 404, e continua lá.
        $alheio = FullEnvio::create(['company_id' => $b->id, 'data_despacho' => '2026-10-20']);
        $this->patchJson(route('planejador-publico.envios.update', [$token, $alheio]), ['status' => 'cancelado'])->assertNotFound();
        $this->deleteJson(route('planejador-publico.envios.destroy', [$token, $alheio]))->assertNotFound();
        $this->assertSame('a_caminho', $alheio->fresh()->status);

        $this->post(route('planejador-publico.exportar', $token), ['tipo' => 'envio', 'colunas' => ['Código ML'], 'linhas' => [['OKFW19104']]])->assertOk();
    }

    public function test_atualizar_pelo_link_enfileira_a_coleta_so_com_conexao_ativa(): void
    {
        Queue::fake();
        $c = $this->empresa('WENUS', 'OKFW19104');
        $token = $this->gerarLink($c);

        // Sem token do ML: explica e não enfileira.
        $this->postJson(route('planejador-publico.atualizar', $token))->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_link_existe_no_dominio_do_cliente_e_a_tela_interna_nao(): void
    {
        config(['portal.dominio_cliente' => 'cliente.ecf.test']);
        $c = $this->empresa('WENUS', 'OKFW19104');
        $token = $this->gerarLink($c);

        $this->assertTrue(RestringeDominioDoPortal::liberado("planejador/{$token}"));
        $this->assertTrue(RestringeDominioDoPortal::liberado("planejador/{$token}/config"));
        $this->assertFalse(RestringeDominioDoPortal::liberado('planejador-full'));
        $this->assertFalse(RestringeDominioDoPortal::liberado("planejador-full/{$c->id}"));

        $this->get("http://cliente.ecf.test/planejador/{$token}")->assertOk();
        $this->get('http://cliente.ecf.test/planejador-full')->assertNotFound();
    }

    public function test_prefixo_do_link_so_tem_rota_publica(): void
    {
        // O curinga `planejador/*` na allowlist do domínio do cliente só é seguro enquanto
        // nenhuma rota interna morar neste prefixo.
        $noPrefixo = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'planejador/'));

        $this->assertNotEmpty($noPrefixo);
        foreach ($noPrefixo as $rota) {
            $this->assertStringStartsWith('planejador-publico.', (string) $rota->getName(), "Rota {$rota->uri()} no prefixo do link do cliente");
            $this->assertNotContains('auth', $rota->gatherMiddleware());
        }
    }

    public function test_lista_interna_mostra_quem_tem_link_ativo(): void
    {
        $c = $this->empresa('WENUS', 'OKFW19104');
        $this->gerarLink($c);

        $this->actingAs($this->equipe)->get(route('planejador-full.index'))
            ->assertInertia(fn (Assert $page) => $page->where('empresas.0.linkCliente', true));
    }
}
