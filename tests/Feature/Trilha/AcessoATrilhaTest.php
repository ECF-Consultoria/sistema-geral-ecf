<?php

namespace Tests\Feature\Trilha;

use App\Http\Middleware\RestringeDominioDoPortal;
use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\Module;
use App\Models\User;
use App\Services\Portal\PortalEquipeService;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Modules;
use App\Support\Trilha\TrilhaLigada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\Concerns\EntraNoPortal;
use Tests\TestCase;

/**
 * As duas portas da Trilha de produtos (1ª entrega, 30/10/2026): quem entra, o que cada um recebe e o que NÃO
 * chega à porta do cliente.
 *
 * O serviço é trocado por um dublê que devolve a saída CRUA da seção 3 do contrato (com `pub_produto_id`, MLB,
 * preparo e a lista das sugestões). O que se prova aqui é a PORTA — rota, módulo oculto, chave `trilha.empresas`,
 * domínio do cliente — e a APRESENTAÇÃO: os links e o corte do que é só da equipe. A montagem da trilha tem o
 * teste dela (`TrilhaDaEmpresaTest`).
 */
class AcessoATrilhaTest extends TestCase
{
    use EntraNoPortal;
    use RefreshDatabase;

    private const MLB_CLASSICO = 'MLB3412345678';

    private const MLB_PREMIUM = 'MLB3412345679';

    private const MLB_SEM_PRODUTO = 'MLB3400011223';

    /** As etapas que o dublê devolve: 1 produto na lista, 1 no mapeamento, 1 mapeado; ofertas 1/0/1/2. */
    private const CONTAGEM = ['lista' => 1, 'mapa' => 1, 'plano' => 1, 'robo' => 0, 'revisao' => 1, 'lanc' => 2];

    /** Os campos de cada item na tela — os do contrato (seção 4), nem um a mais. */
    private const CAMPOS_DO_PRODUTO = [
        'id', 'nome', 'codigo', 'skus', 'familia', 'ambientes', 'categoria', 'variacoes', 'custo_min', 'custo_max',
        'medidas', 'embalagem', 'fotos_min', 'tem_descricao', 'ficha', 'faltas', 'etapa', 'sugestoes_pendentes',
        'sugestoes_pendentes_total', 'url_ficha',
    ];

    private const CAMPOS_DA_OFERTA = [
        'id', 'tipo', 'nome', 'sku', 'skus', 'componentes', 'etapa', 'status', 'alerta', 'anuncios', 'preparo',
        'onda_id', 'sem_produto',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    // ─── Porta da EQUIPE (`/trilha`) ─────────────────────────────────────────

    public function test_visitante_vai_para_o_login_nas_duas_portas(): void
    {
        $empresa = $this->empresaLigada();
        $this->mock(TrilhaDaEmpresa::class)->shouldNotReceive('montar');

        $this->get('/trilha')->assertRedirect(route('login'));
        $this->get('/trilha/'.$empresa->id)->assertRedirect(route('login'));
        // A do cliente leva ao login DO PORTAL, não ao do sistema interno.
        $this->get('/portal/'.$empresa->id.'/trilha')->assertRedirect(route('portal.entrada'));
    }

    /** Módulo oculto: quem não é Dev recebe 404 — inclusive o admin de negócio —, como se a rota não existisse. */
    public function test_quem_nao_e_dev_recebe_404_enquanto_o_modulo_esta_oculto(): void
    {
        $empresa = $this->empresaLigada();
        $this->mock(TrilhaDaEmpresa::class)->shouldNotReceive('montar');

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $consultor = User::factory()->create(['role' => 'consultor', 'active' => true]);

        foreach ([$admin, $consultor] as $usuario) {
            $this->actingAs($usuario)->get('/trilha')->assertNotFound();
            $this->actingAs($usuario)->get('/trilha/'.$empresa->id)->assertNotFound();
        }
    }

    public function test_o_dev_abre_a_trilha_da_empresa_ligada_com_os_links_da_equipe(): void
    {
        $empresa = $this->empresaLigada(['name' => 'Casa Jatobá']);
        $this->trilhaDuble($empresa);

        $resposta = $this->actingAs($this->dev())->get('/trilha/'.$empresa->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Trilha/Empresa')
                ->where('visao', 'equipe')
                ->where('sessao_equipe', false)
                ->where('empresa', ['id' => $empresa->id, 'nome' => 'Casa Jatobá', 'logo_url' => null])
                ->where('voltar', ['url' => route('trilha.index'), 'rotulo' => 'Empresas'])
                ->where('links', [
                    'portal_abrir' => route('companies.portal.abrir', ['company' => $empresa->id]),
                    'planejamento' => null,
                    'produtos'     => null,
                    // Empresa sem loja de programa: a conta do Publicador é a da própria Company.
                    'publicador'   => route('mlb.anuncios.publicador.produtos', ['conta' => 'company-'.$empresa->id]),
                ])
                // Produto: o editor do pub_produto do grupo; sem pub_produto, sem link. Ficha do Portal só na porta do cliente.
                ->where('produtos.2.url_publicador', route('mlb.anuncios.publicador.editor', ['produto' => 71]))
                ->where('produtos.1.url_publicador', route('mlb.anuncios.publicador.editor', ['produto' => 74]))
                ->where('produtos.0.url_publicador', null)
                ->where('produtos.2.url_ficha', null)
                // A equipe vê as sugestões esperando decisão.
                ->where('produtos.1.sugestoes_pendentes.0', ['fase' => 'combo', 'nome' => 'Combo 2 Racks para TV 1,80 m', 'sku' => 'CJ-213-CB2'])
                ->where('produtos.1.sugestoes_pendentes_total', 2)
                // Oferta: na revisão o link abre a Prévia; no ar, o editor; sem pub_produto, nada.
                ->where('ofertas.0.url_publicador', null)
                ->where('ofertas.1.url_publicador', route('mlb.anuncios.publicador.editor', ['produto' => 72]).'?etapa=previa')
                ->where('ofertas.2.url_publicador', route('mlb.anuncios.publicador.editor', ['produto' => 71]))
                ->where('ofertas.2.anuncios', [
                    ['tipo' => 'Clássico', 'mlb' => self::MLB_CLASSICO, 'situacao' => null],
                    ['tipo' => 'Premium', 'mlb' => self::MLB_PREMIUM, 'situacao' => null],
                ])
                ->where('ofertas.2.preparo', ['titulo' => 'pronto', 'modelo' => 'pronto', 'descricao' => 'pronto', 'imagens' => 'pronto'])
                ->where('ondas', [])
                ->where('gerado_em', '2026-10-30T18:00:00-03:00')
            );

        $props = $this->props($resposta);
        // O id do Publicador vira link e não sai cru; campo que o serviço inventar não chega sem decisão.
        foreach ($props['produtos'] as $produto) {
            $this->assertEqualsCanonicalizing([...self::CAMPOS_DO_PRODUTO, 'url_publicador'], array_keys($produto));
        }
        foreach ($props['ofertas'] as $oferta) {
            $this->assertEqualsCanonicalizing([...self::CAMPOS_DA_OFERTA, 'url_publicador'], array_keys($oferta));
        }
        $this->assertNotContains('campo_novo_do_servico', self::chaves($props));
    }

    /** Empresa que é loja de um programa (Polos): o link vai direto à chave canônica `empresa-N`, sem redirecionar. */
    public function test_o_link_do_publicador_usa_a_chave_canonica_da_loja_de_programa(): void
    {
        $empresa = $this->empresaLigada();
        $loja = MlbEmpresa::create(['nome' => 'Loja Polos', 'projeto' => 'POLOS', 'company_id' => $empresa->id]);
        $this->trilhaDuble($empresa);

        $this->actingAs($this->dev())->get('/trilha/'.$empresa->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('links.publicador', route('mlb.anuncios.publicador.produtos', ['conta' => 'empresa-'.$loja->id]))
            );
    }

    public function test_empresa_fora_da_chave_responde_404_na_porta_da_equipe(): void
    {
        $ligada = $this->empresaLigada();
        $fora = Company::factory()->create();
        $this->mock(TrilhaDaEmpresa::class)->shouldNotReceive('montar');
        $dev = $this->dev();

        $this->actingAs($dev)->get('/trilha/'.$fora->id)->assertNotFound();

        // Id ligado que não é empresa cadastrada: o mesmo 404 de empresa que não existe.
        config(['trilha.empresas' => [$ligada->id, 999999]]);
        $this->actingAs($dev)->get('/trilha/999999')->assertNotFound();

        // Tirar a empresa da chave vale na requisição seguinte (a lista é relida a cada pergunta).
        config(['trilha.empresas' => []]);
        $this->actingAs($dev)->get('/trilha/'.$ligada->id)->assertNotFound();

        // E o número no caminho é só número.
        config(['trilha.empresas' => [$ligada->id]]);
        $this->actingAs($dev)->get('/trilha/'.$ligada->id.'abc')->assertNotFound();
    }

    public function test_o_indice_lista_so_as_empresas_ligadas_com_a_contagem_por_etapa(): void
    {
        $zeta = Company::factory()->create(['name' => 'Zeta Móveis']);
        $alfa = Company::factory()->create(['name' => 'Alfa Decor', 'logo_url' => 'https://exemplo.test/alfa.png']);
        Company::factory()->create(['name' => 'Beta Fora da Chave']);
        config(['trilha.empresas' => [$zeta->id, $alfa->id, 999999]]);

        // Uma trilha por empresa ligada e cadastrada — a de fora da chave nunca é montada.
        $this->mock(TrilhaDaEmpresa::class, fn (MockInterface $m) => $m
            ->shouldReceive('montar')->twice()
            ->withArgs(fn (Company $e) => in_array($e->id, [$zeta->id, $alfa->id], true))
            ->andReturn($this->trilhaCrua()));

        $this->actingAs($this->dev())->get('/trilha')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Trilha/Index')
                ->where('empresas', [
                    [
                        'id' => $alfa->id, 'nome' => 'Alfa Decor', 'logo_url' => 'https://exemplo.test/alfa.png',
                        'url' => route('trilha.empresa', ['company' => $alfa->id]), 'contagem' => self::CONTAGEM,
                    ],
                    [
                        'id' => $zeta->id, 'nome' => 'Zeta Móveis', 'logo_url' => null,
                        'url' => route('trilha.empresa', ['company' => $zeta->id]), 'contagem' => self::CONTAGEM,
                    ],
                ])
                // A chave inteira vai junto (o id 999999 não é empresa): é ela que a tela mostra no aviso.
                ->where('ligadas', [$zeta->id, $alfa->id, 999999])
                ->where('voltar', ['url' => route('dashboard'), 'rotulo' => 'Voltar ao sistema'])
            );
    }

    public function test_sem_empresa_ligada_o_indice_vem_vazio(): void
    {
        Company::factory()->create();
        config(['trilha.empresas' => []]);
        $this->mock(TrilhaDaEmpresa::class)->shouldNotReceive('montar');

        $this->actingAs($this->dev())->get('/trilha')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Trilha/Index')
                ->where('empresas', [])
                ->where('ligadas', [])
            );
    }

    /**
     * O módulo nasce oculto como a Incubadora, e liberar no Controle Dev (a flag, sem deploy) abre a porta da
     * equipe. Quem não é admin entra, mas sem link para o Publicador (rotas `role:admin`) e sem "Abrir o Portal"
     * de empresa fora da carteira — link que daria 403 não sai.
     */
    public function test_o_modulo_nasce_oculto_e_liberar_no_controle_dev_abre_a_porta_da_equipe(): void
    {
        $this->assertSame([
            'key' => 'trilha.produtos', 'name' => 'Trilha de produtos', 'grupo' => 'Trilha', 'route_prefix' => 'trilha.',
            'permission_key' => null, 'stage' => 'em_desenvolvimento', 'nasce_oculto' => true,
        ], collect(Modules::catalog()['Trilha'] ?? [])->firstWhere('key', Modules::TRILHA_PRODUTOS));

        $this->artisan('modules:sync')->assertSuccessful();
        $this->assertFalse((bool) Module::where('key', Modules::TRILHA_PRODUTOS)->value('visivel_para_todos'));

        $empresa = $this->empresaLigada();
        $this->trilhaDuble($empresa);
        $consultor = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->actingAs($consultor)->get('/trilha/'.$empresa->id)->assertNotFound();

        Module::where('key', Modules::TRILHA_PRODUTOS)->first()->update(['visivel_para_todos' => true]);

        $this->actingAs($consultor)->get('/trilha/'.$empresa->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Trilha/Empresa')
                ->where('visao', 'equipe')
                ->where('links.publicador', null)
                ->where('links.portal_abrir', null)
                ->where('produtos.2.url_publicador', null)
                ->where('ofertas.1.url_publicador', null)
                ->where('ofertas.2.url_publicador', null)
            );
    }

    // ─── Porta do CLIENTE (`/portal/{empresa}/trilha`) ───────────────────────

    public function test_o_cliente_da_empresa_ligada_ve_a_trilha_sem_o_que_e_so_da_equipe(): void
    {
        $empresa = $this->empresaLigada(['name' => 'Casa Jatobá', 'logo_url' => 'https://exemplo.test/jatoba.png']);
        $this->trilhaDuble($empresa);

        $resposta = $this->entrarNoPortal($empresa)->get(route('portal.auth.trilha'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Trilha/Empresa')
                ->where('visao', 'cliente')
                ->where('sessao_equipe', false)
                ->where('empresa', ['id' => $empresa->id, 'nome' => 'Casa Jatobá', 'logo_url' => 'https://exemplo.test/jatoba.png'])
                ->where('voltar', ['url' => route('portal.auth.inicio'), 'rotulo' => 'Voltar ao portal'])
                ->where('links', [
                    'portal_abrir' => null,
                    'planejamento' => route('portal.auth.estrutura.sugestoes'),
                    'produtos'     => route('portal.auth.estrutura.produtos'),
                    'publicador'   => null,
                ])
                // Cada produto leva à ficha dele no cadastro do Portal.
                ->where('produtos.0.url_ficha', route('portal.auth.estrutura.produtos.ficha', ['produto' => 14]))
                ->where('produtos.2.url_ficha', route('portal.auth.estrutura.produtos.ficha', ['produto' => 12]))
                // A lista das sugestões fica na equipe; o total chega, para o "N esperando decisão".
                ->where('produtos.1.sugestoes_pendentes', [])
                ->where('produtos.1.sugestoes_pendentes_total', 2)
                // Sem preparo do agente e sem MLB; o tipo e a situação dos anúncios ficam.
                ->where('ofertas.1.preparo', null)
                ->where('ofertas.2.preparo', null)
                ->where('ofertas.2.anuncios', [
                    ['tipo' => 'Clássico', 'mlb' => null, 'situacao' => null],
                    ['tipo' => 'Premium', 'mlb' => null, 'situacao' => null],
                ])
                ->where('ofertas.3.anuncios', [['tipo' => 'Clássico', 'mlb' => null, 'situacao' => 'Pausado']])
                ->where('ofertas.2.status', 'No ar · Clássico e Premium')
                ->where('ondas', [])
                ->where('gerado_em', '2026-10-30T18:00:00-03:00')
            );

        $props = $this->props($resposta);
        foreach ($props['produtos'] as $produto) {
            $this->assertEqualsCanonicalizing(self::CAMPOS_DO_PRODUTO, array_keys($produto));
        }
        foreach ($props['ofertas'] as $oferta) {
            $this->assertEqualsCanonicalizing(self::CAMPOS_DA_OFERTA, array_keys($oferta));
        }
        $this->assertNaoChegaNadaDaEquipe($resposta);
    }

    /** Alguém da ECF entrando pelo modo equipe do Portal vê exatamente o que o cliente vê, com a faixa de aviso. */
    public function test_a_equipe_no_portal_ve_o_mesmo_que_o_cliente_com_o_aviso(): void
    {
        $empresa = $this->empresaLigada();
        $this->trilhaDuble($empresa);

        $resposta = $this->entrarComoEquipe($empresa)->get(route('portal.auth.trilha'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Trilha/Empresa')
                ->where('visao', 'cliente')
                ->where('sessao_equipe', true)
                ->where('links.portal_abrir', null)
                ->where('links.publicador', null)
                ->where('produtos.1.sugestoes_pendentes', [])
                ->where('ofertas.2.preparo', null)
            );

        $this->assertNaoChegaNadaDaEquipe($resposta);
    }

    public function test_empresa_fora_da_chave_responde_404_na_porta_do_cliente(): void
    {
        $empresa = Company::factory()->create();
        config(['trilha.empresas' => [$empresa->id + 1000]]);
        $this->mock(TrilhaDaEmpresa::class)->shouldNotReceive('montar');

        $this->entrarNoPortal($empresa)->get(route('portal.auth.trilha'))->assertNotFound();

        // Nem pelo modo equipe do Portal: no Portal a chave é a única trava além do login.
        $this->entrarComoEquipe($empresa)->get(route('portal.auth.trilha'))->assertNotFound();
    }

    // ─── Domínio do cliente (`RestringeDominioDoPortal`) ─────────────────────

    public function test_a_allowlist_libera_so_a_porta_do_cliente(): void
    {
        // A lista é escrita sem o número da empresa: `liberado()` o tira logo depois de `portal/`.
        $this->assertTrue(RestringeDominioDoPortal::liberado('portal/459/trilha'));
        // O endereço antigo, sem a empresa, existe lá para redirecionar à empresa da sessão.
        $this->assertTrue(RestringeDominioDoPortal::liberado('portal/trilha'));

        $this->assertFalse(RestringeDominioDoPortal::liberado('trilha'));
        $this->assertFalse(RestringeDominioDoPortal::liberado('trilha/459'));
        $this->assertFalse(RestringeDominioDoPortal::liberado('portal/459/trilha/1'));

        $permitido = (new \ReflectionClass(RestringeDominioDoPortal::class))->getConstant('PERMITIDO');
        $this->assertSame(['portal/trilha'], array_values(array_filter($permitido, fn ($p) => str_contains($p, 'trilha'))));
    }

    /** No domínio do cliente a porta da equipe não existe — nem para o Dev logado. No do admin, abre. */
    public function test_no_dominio_do_cliente_a_porta_da_equipe_responde_404(): void
    {
        $empresa = $this->empresaLigada();
        $this->trilhaDuble($empresa);
        config(['portal.dominio_cliente' => 'cliente.teste']);
        $dev = $this->dev();

        $this->actingAs($dev)->get('http://cliente.teste/trilha')->assertNotFound();
        $this->actingAs($dev)->get('http://cliente.teste/trilha/'.$empresa->id)->assertNotFound();

        $this->actingAs($dev)->get('http://localhost/trilha/'.$empresa->id)->assertOk();
    }

    /** E a porta do cliente abre no domínio dele, com os links também lá (o `route()` usa o host da requisição). */
    public function test_no_dominio_do_cliente_a_porta_do_cliente_abre_com_os_links_dele(): void
    {
        $empresa = $this->empresaLigada();
        $this->trilhaDuble($empresa);
        config(['portal.dominio_cliente' => 'cliente.teste']);
        $base = 'http://cliente.teste/portal/'.$empresa->id;

        $this->entrarNoPortal($empresa)->get($base.'/trilha')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Trilha/Empresa')
                ->where('visao', 'cliente')
                ->where('voltar.url', $base.'/inicio')
                ->where('links.planejamento', $base.'/estrutura/sugestoes')
                ->where('links.produtos', $base.'/estrutura/produtos')
                ->where('produtos.2.url_ficha', $base.'/estrutura/produtos/12')
            );
    }

    // ─── A chave `trilha.empresas` ───────────────────────────────────────────

    public function test_a_chave_aceita_so_ids_inteiros_e_o_padrao_e_a_loja_de_teste(): void
    {
        // O que vem do .env: lista separada por vírgula, só inteiros positivos; sem a variável, só a #459.
        $this->assertSame([459], $this->chaveDoEnv(null));
        $this->assertSame([459, 12], $this->chaveDoEnv(' 459, 12,abc,12abc,0,-3,,12 '));
        $this->assertSame([], $this->chaveDoEnv(''));

        // O que chega por outro caminho (config() de teste, config:cache antigo) é limpo de novo.
        config(['trilha.empresas' => ['459', ' 12 ', 'abc', 0, -3, null, 7.5, 459]]);
        $this->assertSame([459, 12], TrilhaLigada::empresas());
        $this->assertTrue(TrilhaLigada::para(459));
        $this->assertTrue(TrilhaLigada::para(12));
        $this->assertFalse(TrilhaLigada::para(0));
        $this->assertFalse(TrilhaLigada::para(13));

        $empresa = Company::factory()->create();
        $this->assertFalse(TrilhaLigada::para($empresa));
        config(['trilha.empresas' => [$empresa->id]]);
        $this->assertTrue(TrilhaLigada::para($empresa));
    }

    // ─── Ajudantes ───────────────────────────────────────────────────────────

    /** Uma empresa e a chave ligando SÓ ela. */
    private function empresaLigada(array $atributos = []): Company
    {
        $empresa = Company::factory()->create($atributos);
        config(['trilha.empresas' => [$empresa->id]]);

        return $empresa;
    }

    /** O Dev: o espelho `is_dev` do cargo Dev é o que passa pelo módulo oculto (não o `role`). Admin, para os links. */
    private function dev(): User
    {
        $usuario = User::factory()->create(['role' => 'admin', 'active' => true]);
        $usuario->forceFill(['is_dev' => true])->save();

        return $usuario->refresh();
    }

    /** A sessão de equipe no Portal: a passagem de 60 s emitida pelo admin, consumida na porta da equipe. */
    private function entrarComoEquipe(Company $empresa): static
    {
        $membro = User::factory()->create(['role' => 'admin', 'active' => true]);
        $ticket = app(PortalEquipeService::class)->emitir($membro, $empresa, '127.0.0.1');
        $this->get(route('portal.equipe.entrar', ['t' => $ticket]))->assertRedirect();

        return $this;
    }

    /** Troca o serviço por um dublê que devolve a trilha crua, uma vez, e só para a empresa certa. */
    private function trilhaDuble(Company $empresa): void
    {
        $this->mock(TrilhaDaEmpresa::class, fn (MockInterface $m) => $m
            ->shouldReceive('montar')->once()
            ->withArgs(fn (Company $e) => $e->is($empresa))
            ->andReturn($this->trilhaCrua()));
    }

    /**
     * A saída CRUA do serviço (seção 3 do contrato), na ordem em que ele a devolve: produtos lista → mapa →
     * mapeado; ofertas plano → revisão → no ar. Traz tudo o que é só da equipe, e um campo que ninguém decidiu
     * mostrar (`campo_novo_do_servico`), para provar que a apresentação só deixa sair o que está na lista dela.
     */
    private function trilhaCrua(): array
    {
        return [
            'produtos' => [
                [
                    'id' => 14, 'nome' => 'Banqueta alta jatobá 75 cm', 'codigo' => null, 'skus' => ['CJ-215'], 'familia' => 'Cadeiras',
                    'ambientes' => ['Cozinha'], 'categoria' => 'Banquetas', 'variacoes' => 1, 'custo_min' => 210.0, 'custo_max' => 210.0,
                    'medidas' => '38 × 38 × 75 cm', 'embalagem' => '42 × 42 × 40 cm · 5 kg', 'fotos_min' => 1, 'tem_descricao' => false,
                    'ficha' => 'completa', 'faltas' => ['descrição', 'fotos (1 de 3)'], 'etapa' => 'lista',
                    'sugestoes_pendentes' => [], 'sugestoes_pendentes_total' => 0, 'pub_produto_id' => null,
                ],
                [
                    'id' => 13, 'nome' => 'Rack para TV 1,80 m madeira maciça', 'codigo' => 'CJ-213', 'skus' => ['CJ-213'], 'familia' => 'Racks',
                    'ambientes' => ['Sala de estar'], 'categoria' => 'Racks', 'variacoes' => 1, 'custo_min' => 890.0, 'custo_max' => 890.0,
                    'medidas' => '180 × 40 × 55 cm', 'embalagem' => '186 × 46 × 22 cm · 31 kg', 'fotos_min' => 6, 'tem_descricao' => true,
                    'ficha' => 'completa', 'faltas' => [], 'etapa' => 'mapa',
                    'sugestoes_pendentes' => [
                        ['fase' => 'combo', 'nome' => 'Combo 2 Racks para TV 1,80 m', 'sku' => 'CJ-213-CB2'],
                        ['fase' => 'kit', 'nome' => 'Rack para TV 1,80 m + Painel ripado 2,20 m', 'sku' => 'KT-CJ-213-CJ-214'],
                    ],
                    'sugestoes_pendentes_total' => 2, 'pub_produto_id' => 74,
                ],
                [
                    'id' => 12, 'nome' => 'Mesa de jantar jatobá 6 lugares', 'codigo' => 'CJ-210', 'skus' => ['CJ-210'], 'familia' => 'Mesas',
                    'ambientes' => ['Sala de jantar'], 'categoria' => 'Mesas de Jantar', 'variacoes' => 1, 'custo_min' => 1480.0, 'custo_max' => 1480.0,
                    'medidas' => '180 × 90 × 76 cm', 'embalagem' => '186 × 96 × 18 cm · 42 kg', 'fotos_min' => 5, 'tem_descricao' => true,
                    'ficha' => 'completa', 'faltas' => [], 'etapa' => null,
                    'sugestoes_pendentes' => [], 'sugestoes_pendentes_total' => 0, 'pub_produto_id' => 71,
                    'campo_novo_do_servico' => 'não deveria sair',
                ],
            ],
            'ofertas' => [
                [
                    'id' => 'o-31', 'tipo' => 'Kit', 'nome' => 'Kit rack 1,80 m + painel ripado 2,20 m', 'sku' => 'KT-CJ-213-CJ-214',
                    'skus' => ['KT-CJ-213-CJ-214'],
                    'componentes' => [
                        ['produto_id' => 13, 'quantidade' => 1, 'sku' => 'CJ-213', 'nome' => 'Rack para TV 1,80 m madeira maciça'],
                    ],
                    'etapa' => 'plano', 'status' => 'Sem onda', 'alerta' => false, 'anuncios' => [], 'preparo' => null,
                    'onda_id' => null, 'sem_produto' => false, 'pub_produto_id' => null,
                ],
                [
                    'id' => 'c-12-2', 'tipo' => 'Combo', 'nome' => 'Combo 2 · Mesa de jantar jatobá 6 lugares', 'sku' => 'CJ-210-CB2',
                    'skus' => ['CJ-210-CB2'],
                    'componentes' => [
                        ['produto_id' => 12, 'quantidade' => 2, 'sku' => 'CJ-210', 'nome' => 'Mesa de jantar jatobá 6 lugares'],
                    ],
                    'etapa' => 'revisao', 'status' => 'Pré-pronto · esperando a revisão', 'alerta' => false, 'anuncios' => [],
                    'preparo' => ['titulo' => 'pronto', 'modelo' => 'pronto', 'descricao' => 'pronto', 'imagens' => 'fazendo'],
                    'onda_id' => null, 'sem_produto' => false, 'pub_produto_id' => 72,
                ],
                [
                    'id' => 's-12', 'tipo' => 'Simples', 'nome' => 'Mesa de jantar jatobá 6 lugares', 'sku' => 'CJ-210', 'skus' => ['CJ-210'],
                    'componentes' => [
                        ['produto_id' => 12, 'quantidade' => 1, 'sku' => 'CJ-210', 'nome' => 'Mesa de jantar jatobá 6 lugares'],
                    ],
                    'etapa' => 'lanc', 'status' => 'No ar · Clássico e Premium', 'alerta' => false,
                    'anuncios' => [
                        ['tipo' => 'Clássico', 'mlb' => self::MLB_CLASSICO, 'situacao' => null],
                        ['tipo' => 'Premium', 'mlb' => self::MLB_PREMIUM, 'situacao' => null],
                    ],
                    'preparo' => ['titulo' => 'pronto', 'modelo' => 'pronto', 'descricao' => 'pronto', 'imagens' => 'pronto'],
                    'onda_id' => null, 'sem_produto' => false, 'pub_produto_id' => 71,
                    'campo_novo_do_servico' => 'não deveria sair',
                ],
                [
                    'id' => 'o-40', 'tipo' => 'Simples', 'nome' => 'Nicho decorativo hexagonal', 'sku' => 'CJ-303', 'skus' => ['CJ-303'],
                    'componentes' => [
                        ['produto_id' => null, 'quantidade' => 1, 'sku' => 'CJ-303', 'nome' => 'Nicho decorativo hexagonal'],
                    ],
                    'etapa' => 'lanc', 'status' => 'No ar · falta o Premium', 'alerta' => false,
                    'anuncios' => [['tipo' => 'Clássico', 'mlb' => self::MLB_SEM_PRODUTO, 'situacao' => 'Pausado']],
                    'preparo' => null, 'onda_id' => null, 'sem_produto' => true, 'pub_produto_id' => null,
                ],
            ],
            'ondas'     => [],
            'gerado_em' => '2026-10-30T18:00:00-03:00',
        ];
    }

    /**
     * Varre a página inteira da porta do cliente: nenhuma chave de Publicador em nível nenhum, e nem o MLB nem o
     * caminho do editor em lugar nenhum — nem nas props, nem no HTML que vai ao navegador.
     */
    private function assertNaoChegaNadaDaEquipe(TestResponse $resposta): void
    {
        $props = $this->props($resposta);
        $chaves = self::chaves(['produtos' => $props['produtos'], 'ofertas' => $props['ofertas']]);

        foreach (['pub_produto_id', 'url_publicador', 'campo_novo_do_servico'] as $proibida) {
            $this->assertNotContains($proibida, $chaves, "a porta do cliente recebeu `{$proibida}`");
        }

        // `CJ-213-CB2` só existe na lista das sugestões (é a sugestão de Combo ainda não aceita).
        $json = json_encode($props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ([self::MLB_CLASSICO, self::MLB_PREMIUM, self::MLB_SEM_PRODUTO, 'publicador/produtos/71', 'publicador/produtos/72', 'publicador/produtos/74', 'CJ-213-CB2'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $json);
        }
        foreach ([self::MLB_CLASSICO, self::MLB_PREMIUM, self::MLB_SEM_PRODUTO] as $mlb) {
            $resposta->assertDontSee($mlb, false);
        }
        foreach ($props['produtos'] as $produto) {
            $this->assertSame([], $produto['sugestoes_pendentes']);
        }
        foreach ($props['ofertas'] as $oferta) {
            $this->assertNull($oferta['preparo']);
            foreach ($oferta['anuncios'] as $anuncio) {
                $this->assertNull($anuncio['mlb']);
            }
        }
    }

    /** As props da página Inertia como o navegador as recebe. */
    private function props(TestResponse $resposta): array
    {
        return json_decode(json_encode($resposta->viewData('page')), true)['props'];
    }

    /** Todas as chaves, em qualquer nível. */
    private static function chaves(array $dados): array
    {
        $todas = [];
        foreach ($dados as $chave => $valor) {
            $todas[] = (string) $chave;
            if (is_array($valor)) {
                array_push($todas, ...self::chaves($valor));
            }
        }

        return $todas;
    }

    /**
     * A chave como o `config/trilha.php` a monta a partir da variável de ambiente (lida ao vivo pelo `env()`), com
     * a variável devolvida ao estado de antes no fim.
     *
     * @return list<int>
     */
    private function chaveDoEnv(?string $valor): array
    {
        $nome = 'TRILHA_EMPRESAS';
        $antes = [$_SERVER[$nome] ?? null, $_ENV[$nome] ?? null, getenv($nome)];

        try {
            unset($_SERVER[$nome], $_ENV[$nome]);
            putenv($nome);
            if ($valor !== null) {
                $_SERVER[$nome] = $_ENV[$nome] = $valor;
                putenv($nome.'='.$valor);
            }

            return (require base_path('config/trilha.php'))['empresas'];
        } finally {
            unset($_SERVER[$nome], $_ENV[$nome]);
            putenv($nome);
            if ($antes[0] !== null) {
                $_SERVER[$nome] = $antes[0];
            }
            if ($antes[1] !== null) {
                $_ENV[$nome] = $antes[1];
            }
            if ($antes[2] !== false) {
                putenv($nome.'='.$antes[2]);
            }
        }
    }
}
