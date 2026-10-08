<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\EstruturaProdutoVariacao;
use App\Models\MlToken;
use App\Services\Portal\Estrutura\Geracao\ListaDeSugestoes;
use App\Services\Portal\Estrutura\Produtos\FreteMe2Service;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 168-10: logística e frete do CONJUNTO, só da página (D-08, D-18).
 *
 * Modos de falha que estes testes impedem: chamar o ML ao listar, gravar frete como
 * preço, cotar fora da página e a cotação escrever na conta do cliente.
 */
class LogisticaDoConjuntoTest extends TestCase
{
    use CatalogoSinteticoDeSugestoes;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private Company $empresa;

    /** @var array<string,int> */
    private array $v;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->empresa = $this->empresaDoGabarito();
        $this->catalogoSintetico($this->empresa, $this->atorCliente($this->empresa));
        $this->v = EstruturaProdutoVariacao::where('company_id', $this->empresa->id)->pluck('id', 'codigo')->all();
    }

    private function lista(): ListaDeSugestoes
    {
        return app(ListaDeSugestoes::class);
    }

    /** Todas as sugestões vigentes (todas as páginas, Combos expandidos), por chave. */
    private function todas(): array
    {
        $filtros = ['combos' => $this->combosDeTodas($this->empresa)];
        $itens = [];
        $paginas = $this->lista()->listar($this->empresa, $filtros, 1)['paginacao']['paginas'];
        for ($p = 1; $p <= $paginas; $p++) {
            foreach ($this->lista()->listar($this->empresa, $filtros, $p)['itens'] as $i) {
                $itens[$i['chave']] = $i;
            }
        }

        return $itens;
    }

    /** @param array<int,int> $quantidades variação => quantidade */
    private function achar(string $fase, array $quantidades): array
    {
        foreach ($this->todas() as $item) {
            $q = [];
            foreach ($item['itens'] as $i) {
                $q[$i['variacao_id']] = $i['quantidade'];
            }
            ksort($q);
            ksort($quantidades);
            if ($item['fase'] === $fase && $q === $quantidades) {
                return $item;
            }
        }
        $this->fail('Sugestão não encontrada: '.json_encode($quantidades));
    }

    private function conectar(): void
    {
        MlToken::create([
            'company_id' => $this->empresa->id, 'ml_user_id' => '436501796',
            'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer', 'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6), 'last_refreshed_at' => now(),
            'status' => 'active', 'connected_at' => now(),
        ]);
        $this->empresa->unsetRelation('mlToken');
    }

    public function test_combit_mesa_com_4_cadeiras_empilha_volumes_e_soma_custo(): void
    {
        Http::fake();
        $item = $this->achar('combit', [$this->v['V101'] => 1, $this->v['V201'] => 4]);

        $mesa    = ['c' => 160.0, 'l' => 90.0, 'a' => 15.0, 'kg' => 40.0];
        $cadeira = ['c' => 50.0, 'l' => 50.0, 'a' => 20.0, 'kg' => 6.0];
        $esperado = LogisticaProduto::daVolumes([$mesa, $cadeira, $cadeira, $cadeira, $cadeira]);

        $this->assertSame($esperado['logistica'], $item['logistica']['chave']);
        $this->assertEquals($esperado['peso_faturado'], $item['logistica']['peso_faturado']);
        $this->assertSame([], $item['logistica']['sem_medida']);
        $this->assertSame(620.0, $item['custo']);
    }

    public function test_conjunto_com_componente_sem_volumes_fica_pendente_e_diz_qual(): void
    {
        Http::fake();
        $itens = array_filter($this->todas(), fn ($i) => $i['fase'] === 'kit'
            && in_array($this->v['V301'], array_column($i['itens'], 'variacao_id'), true));
        $this->assertNotEmpty($itens);

        $kit = array_values($itens)[0];

        $this->assertSame('pendente', $kit['logistica']['chave']);
        $produtoId = collect($kit['itens'])->firstWhere('variacao_id', $this->v['V301'])['produto_id'];
        $this->assertSame([['id' => $produtoId, 'nome' => 'Banco Polo']], $kit['logistica']['sem_medida']);
        $this->assertNull($kit['frete']['valor']);
    }

    public function test_combo_de_2_cadeiras_e_me2_com_frete_da_tabela_ecf(): void
    {
        Http::fake();
        $combo = $this->achar('combo', [$this->v['V201'] => 2]);

        $this->assertContains($combo['logistica']['chave'], [LogisticaProduto::ME2, LogisticaProduto::ME2_FULL]);
        $this->assertEquals(12.0, $combo['logistica']['pacote']['peso_real']);
        $this->assertSame('tabela_ecf', $combo['frete']['origem']);
        $this->assertNotNull($combo['frete']['valor']);
    }

    public function test_listar_nao_faz_requisicao_nem_grava_nada(): void
    {
        Http::fake();
        $ofertas = EstruturaOferta::count();
        $precificacoes = EstruturaPrecificacao::count();

        $this->lista()->listar($this->empresa, [], 1);
        $this->conectar();
        $this->lista()->listar($this->empresa, [], 2);

        Http::assertNothingSent();
        $this->assertSame($ofertas, EstruturaOferta::count());
        $this->assertSame($precificacoes, EstruturaPrecificacao::count());
    }

    public function test_frete_estimado_numa_unica_chamada_por_listar(): void
    {
        $real = app(FreteMe2Service::class);
        $espiao = \Mockery::mock(FreteMe2Service::class)->makePartial();
        $espiao->shouldReceive('estimar')->once()->andReturnUsing(fn (...$a) => $real->estimar(...$a));
        $this->app->instance(FreteMe2Service::class, $espiao);

        // Uma chamada mesmo com os Combos de todas as famílias expandidos na página (08/10).
        $r = app(ListaDeSugestoes::class)->listar($this->empresa, ['combos' => $this->combosDeTodas($this->empresa)], 1);

        $this->assertCount(29, $r['itens']);
    }

    public function test_cotar_pagina_sem_conta_ml_nao_faz_requisicao(): void
    {
        Http::fake();
        $chaves = array_slice(array_keys($this->todas()), 0, 3);

        $r = $this->lista()->cotarPagina($this->empresa, $chaves);

        $this->assertFalse($r['conectado']);
        $this->assertCount(3, $r['fretes']);
        Http::assertNothingSent();
    }

    public function test_cotar_pagina_com_conta_usa_a_api_so_por_get(): void
    {
        Http::fake(fn (Request $r) => Http::response(['coverage' => ['all_country' => ['list_cost' => 31.9]]]));
        $this->conectar();
        $combo = $this->achar('combo', [$this->v['V201'] => 2]);

        $r = $this->lista()->cotarPagina($this->empresa, [$combo['chave']]);

        $this->assertTrue($r['conectado']);
        $this->assertSame('api', $r['fretes'][$combo['chave']]['origem']);
        $this->assertEquals(31.9, $r['fretes'][$combo['chave']]['valor']);
        Http::assertSent(fn (Request $q) => $q->method() === 'GET' && str_contains($q->url(), '/users/436501796/shipping_options/free'));
        Http::assertNotSent(fn (Request $q) => $q->method() !== 'GET');
        $this->assertSame(0, EstruturaPrecificacao::count());
    }

    public function test_cotar_pagina_corta_na_pagina_e_ignora_chave_invalida_ou_descartada(): void
    {
        Http::fake();
        $chaves = array_keys($this->todas());
        $this->assertCount(29, $chaves);

        $descartada = $chaves[0];
        \App\Models\EstruturaSugestaoDescartada::create(['company_id' => $this->empresa->id, 'chave' => $descartada, 'fase' => 'combo']);

        $pedidas = array_merge($chaves, ['chave-que-nao-existe']);
        $r = $this->lista()->cotarPagina($this->empresa, $pedidas);

        $this->assertCount(20, $r['fretes']);
        $this->assertArrayNotHasKey($descartada, $r['fretes']);
        $this->assertArrayNotHasKey('chave-que-nao-existe', $r['fretes']);
    }
}
