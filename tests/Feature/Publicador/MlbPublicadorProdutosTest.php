<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Fase 164 / 164-06: tela B (produtos da empresa), cadastro manual e casca do editor. */
class MlbPublicadorProdutosTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/mlb/anuncios/publicador';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function empresa(array $attrs = [], bool $comToken = false): MlbEmpresa
    {
        $e = MlbEmpresa::create($attrs + ['nome' => 'Polo X', 'projeto' => 'POLOS'])->fresh();
        if ($comToken) {
            MlToken::create([
                'mlb_empresa_id' => $e->id, 'ml_user_id' => '123456', 'access_token' => 'APP_USR-segredo',
                'refresh_token' => 'TG-segredo', 'expires_at' => now()->addHours(5), 'status' => 'active',
            ]);
        }

        return $e;
    }

    private function pagina(string $url): array
    {
        $page = $this->actingAs($this->admin())->get($url)->assertOk()->viewData('page');

        return $page;
    }

    public function test_tela_b_de_empresa_sem_company_marca_abas_indisponiveis(): void
    {
        $e = $this->empresa([], true);
        PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => 'A1', 'nome' => 'Produto A', 'origem' => 'publicador']);

        $page = $this->pagina(self::BASE.'/empresas/empresa-'.$e->id);

        $this->assertSame('Mlb/Publicador/Produtos', $page['component']);
        $p = $page['props'];
        $this->assertSame('polos', $p['empresa']['programa']);
        $this->assertSame('Polos', $p['empresa']['programa_rotulo']);
        $this->assertNull($p['abas']['company_id']);
        $this->assertNull($p['empresa']['company_id']);
        $this->assertSame('ativo', $p['empresa']['token']);
        $this->assertSame('123456', $p['empresa']['conta_ml_id']);
        $this->assertSame('Polo X', $p['empresa']['conta_nome']);
        $this->assertFalse($p['liberada']);
        $this->assertCount(1, $p['produtos']);
        $this->assertSame('rascunho', $p['produtos'][0]['status']['chave']);
        $this->assertNull($p['rascunhos_antigos']['url']);
        // Nunca vaza token.
        $this->assertStringNotContainsString('segredo', json_encode($p));
    }

    public function test_tela_b_de_company_de_gestao_traz_abas_e_rascunhos_antigos(): void
    {
        $c = Company::factory()->create();
        MlToken::create(['company_id' => $c->id, 'ml_user_id' => '9', 'access_token' => 'x', 'refresh_token' => 'y',
            'expires_at' => now()->addHours(5), 'status' => 'active']);
        $u = $this->admin();
        MlAnuncioRascunho::create(['user_id' => $u->id, 'company_id' => $c->id, 'status' => MlAnuncioRascunho::STATUS_RASCUNHO]);
        MlAnuncioRascunho::create(['user_id' => $u->id, 'company_id' => $c->id, 'status' => MlAnuncioRascunho::STATUS_PUBLICADO]);

        $p = $this->pagina(self::BASE.'/empresas/company-'.$c->id)['props'];

        $this->assertSame('gestao', $p['empresa']['programa']);
        $this->assertSame($c->id, $p['abas']['company_id']);
        $this->assertSame(1, $p['rascunhos_antigos']['total']);
        $this->assertSame(route('mlb.anuncios.wizard', ['company' => $c->id]), $p['rascunhos_antigos']['url']);
    }

    public function test_company_ligada_a_polos_redireciona_para_a_empresa(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa(['company_id' => $c->id]);

        $this->actingAs($this->admin())->get(self::BASE.'/empresas/company-'.$c->id)
            ->assertRedirect(route('mlb.anuncios.publicador.produtos', ['conta' => 'empresa-'.$e->id]));
    }

    public function test_produto_do_backfill_sem_mlb_empresa_aparece_na_empresa_ligada(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa(['company_id' => $c->id]);
        $o = EstruturaOferta::create(['company_id' => $c->id, 'sku' => 'BF1', 'fase' => 'simples', 'nome' => 'Backfill']);
        PubProduto::daOferta($o);

        $p = $this->pagina(self::BASE.'/empresas/empresa-'.$e->id)['props'];

        $this->assertCount(1, $p['produtos']);
        $this->assertSame('BF1', $p['produtos'][0]['sku']);
        $this->assertSame('portal', $p['produtos'][0]['origem']);
        $this->assertSame($o->id, $p['produtos'][0]['oferta_id']);
    }

    public function test_status_anuncios_parcial_e_contagens(): void
    {
        $e = $this->empresa();
        $mk = fn (string $sku) => PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => $sku, 'nome' => $sku, 'origem' => 'publicador']);

        $semRascunho = $mk('SEM');
        $conferido = $mk('CONF');
        PubRascunho::create(['produto_id' => $conferido->id, 'status' => PubRascunho::VALIDATED]);
        $erro = $mk('ERRO');
        PubRascunho::create(['produto_id' => $erro->id, 'status' => PubRascunho::FAILED]);

        $parcial = $mk('PARC');
        $r = PubRascunho::create(['produto_id' => $parcial->id, 'status' => PubRascunho::PARTIALLY_PUBLISHED]);
        $pub = PubPublicacao::create(['rascunho_id' => $r->id, 'revisao' => 1, 'modelo_publicacao' => 'items', 'status' => 'PARTIALLY_PUBLISHED',
            'chave_idempotencia' => (string) Str::uuid(), 'concluida_em' => now()]);
        PubPublicacaoItem::create(['publicacao_id' => $pub->id, 'indice' => 0, 'listing_type_id' => 'gold_special',
            'variante_chave' => ChaveCanonica::UNICA, 'status' => PubPublicacaoItem::CREATED, 'ml_item_id' => 'MLB777']);
        PubPublicacaoItem::create(['publicacao_id' => $pub->id, 'indice' => 1, 'listing_type_id' => 'gold_pro',
            'variante_chave' => ChaveCanonica::UNICA, 'status' => PubPublicacaoItem::FAILED]);

        $publicado = $mk('PUB');
        PubRascunho::create(['produto_id' => $publicado->id, 'status' => PubRascunho::PUBLISHED]);

        $p = $this->pagina(self::BASE.'/empresas/empresa-'.$e->id)['props'];
        $por = collect($p['produtos'])->keyBy('sku');

        $this->assertSame('rascunho', $por['SEM']['status']['chave']);
        $this->assertSame('a preencher', $por['SEM']['status']['rotulo']);
        $this->assertNull($por['SEM']['rascunho_id']);
        $this->assertSame('pronto', $por['CONF']['status']['chave']);
        $this->assertSame('erro', $por['ERRO']['status']['chave']);
        $this->assertSame('publicado', $por['PUB']['status']['chave']);
        $this->assertSame('parcial', $por['PARC']['status']['chave']);
        $this->assertSame(['publicados' => 1, 'total' => 2], $por['PARC']['parcial']);
        $this->assertSame([['ml_item_id' => 'MLB777', 'listing_type_id' => 'gold_special']], $por['PARC']['anuncios']);
        $this->assertSame(PubRascunho::PARTIALLY_PUBLISHED, $por['PARC']['status_rascunho']);

        $c = $p['contagens'];
        $this->assertSame(5, $c['todos']);
        $this->assertSame(1, $c['rascunho']);
        $this->assertSame(1, $c['conferidos']);
        $this->assertSame(2, $c['publicados']);
        $this->assertSame(1, $c['com_problema']);
        $this->assertSame($c['todos'], $c['rascunho'] + $c['conferidos'] + $c['publicados'] + $c['com_problema']);
    }

    public function test_criar_produto_grava_ancoras_do_servidor_e_devolve_url(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa(['company_id' => $c->id]);

        $r = $this->actingAs($this->admin())->postJson(self::BASE.'/empresas/empresa-'.$e->id.'/produtos', [
            'sku' => '  ABC-1 ', 'nome' => ' Produto Novo ', 'mlb_empresa_id' => 999, 'company_id' => 999, 'origem' => 'portal',
        ])->assertCreated();

        $p = PubProduto::findOrFail($r->json('produto.id'));
        $this->assertSame('publicador', $p->origem);
        $this->assertSame($e->id, $p->mlb_empresa_id);
        $this->assertSame($c->id, $p->company_id);
        $this->assertSame('ABC-1', $p->sku);
        $this->assertSame('Produto Novo', $p->nome);
        $this->assertNull($p->oferta_id);
        $this->assertSame(route('mlb.anuncios.publicador.editor', ['produto' => $p->id]), $r->json('url'));
        $this->assertNull($r->json('aviso'));
    }

    public function test_criar_produto_exige_sku_e_nome_e_sku_repetido_so_avisa(): void
    {
        $e = $this->empresa();
        $this->actingAs($this->admin());

        $this->postJson(self::BASE.'/empresas/empresa-'.$e->id.'/produtos', ['sku' => 'X'])->assertStatus(422)->assertJsonValidationErrors('nome');
        $this->postJson(self::BASE.'/empresas/empresa-'.$e->id.'/produtos', ['nome' => 'X'])->assertStatus(422)->assertJsonValidationErrors('sku');
        $this->postJson(self::BASE.'/empresas/empresa-'.$e->id.'/produtos', ['sku' => '   ', 'nome' => 'X'])->assertStatus(422);
        $this->assertSame(0, PubProduto::count());

        $this->postJson(self::BASE.'/empresas/empresa-'.$e->id.'/produtos', ['sku' => 'Dup', 'nome' => 'Um'])->assertCreated();
        $r = $this->postJson(self::BASE.'/empresas/empresa-'.$e->id.'/produtos', ['sku' => 'DUP', 'nome' => 'Dois'])->assertCreated();

        $this->assertSame('Já existe um produto com este SKU nesta empresa.', $r->json('aviso'));
        $this->assertSame(2, PubProduto::count());
    }

    public function test_criar_produto_em_company_de_gestao_grava_company(): void
    {
        $c = Company::factory()->create();

        $r = $this->actingAs($this->admin())->postJson(self::BASE.'/empresas/company-'.$c->id.'/produtos', ['sku' => 'G1', 'nome' => 'Gestão'])
            ->assertCreated();

        $p = PubProduto::findOrFail($r->json('produto.id'));
        $this->assertNull($p->mlb_empresa_id);
        $this->assertSame($c->id, $p->company_id);
    }

    public function test_editor_entrega_produto_empresa_faixa_e_liberada(): void
    {
        $e = $this->empresa([], true);
        $a = PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => 'A', 'nome' => 'Alfa', 'origem' => 'publicador']);
        PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => 'B', 'nome' => 'Beta', 'origem' => 'publicador']);

        $page = $this->pagina(self::BASE.'/produtos/'.$a->id.'/editor');
        $p = $page['props'];

        $this->assertSame('Mlb/Publicador/Editor', $page['component']);
        $this->assertSame($a->id, $p['produto']['id']);
        $this->assertSame('A', $p['produto']['sku']);
        $this->assertSame('empresa-'.$e->id, $p['empresa']['chave']);
        $this->assertCount(2, $p['produtos']);
        $this->assertEqualsCanonicalizing(['A', 'B'], array_column($p['produtos'], 'sku'));
        $this->assertArrayHasKey('status', $p['produtos'][0]);
        $this->assertFalse($p['liberada']);
        $this->assertStringNotContainsString('segredo', json_encode($p));
    }

    public function test_editor_de_conta_liberada_marca_liberada(): void
    {
        $c = Company::factory()->create();
        MlToken::create(['company_id' => $c->id, 'ml_user_id' => '9', 'access_token' => 'x', 'refresh_token' => 'y',
            'expires_at' => now()->addHours(5), 'status' => 'active']);
        config(['publicador.contas_liberadas' => ['companies' => [$c->id], 'mlb_empresas' => []]]);
        $p = PubProduto::create(['company_id' => $c->id, 'sku' => 'L', 'nome' => 'Liberada', 'origem' => 'publicador']);

        $this->assertTrue($this->pagina(self::BASE.'/produtos/'.$p->id.'/editor')['props']['liberada']);
    }

    /**
     * WR-B01: o produto do backfill (só `company_id`) aparece na tela da MlbEmpresa ligada; se ela
     * ganha token, o cabeçalho da EMPRESA mostra a conta dela, mas o produto confere e publica pela
     * Company. O editor e a linha da tela B mostram a conta do PRODUTO — e concordam com o JSON.
     */
    public function test_wr_b01_editor_e_tela_b_mostram_a_conta_que_publica_o_produto(): void
    {
        $c = Company::factory()->create(['name' => 'Dev 02 Testes API']);
        MlToken::create(['company_id' => $c->id, 'ml_user_id' => '1555596317', 'access_token' => 'x', 'refresh_token' => 'y',
            'expires_at' => now()->addHours(5), 'status' => 'active']);
        $e = $this->empresa(['nome' => 'Dev 02 (Polos)', 'company_id' => $c->id], comToken: true); // conta da EMPRESA: 123456
        config(['publicador.contas_liberadas' => ['companies' => [$c->id], 'mlb_empresas' => []]]);
        $doBackfill = PubProduto::create(['company_id' => $c->id, 'sku' => 'BF1', 'nome' => 'Backfill', 'origem' => 'portal']);
        $daEmpresa = PubProduto::create(['mlb_empresa_id' => $e->id, 'company_id' => $c->id, 'sku' => 'E1', 'nome' => 'Da empresa', 'origem' => 'publicador']);

        $editor = $this->pagina(self::BASE.'/produtos/'.$doBackfill->id.'/editor')['props'];
        $this->assertSame('empresa-'.$e->id, $editor['empresa']['chave'], 'a navegação continua sendo a da empresa');
        $this->assertSame('Dev 02 Testes API', $editor['empresa']['conta_nome'], 'a conta que publica é a Company do produto');
        $this->assertSame('1555596317', $editor['empresa']['conta_ml_id']);
        $this->assertSame('ativo', $editor['empresa']['token']);
        $this->assertTrue($editor['liberada']);
        Http::preventStrayRequests();
        Http::fake(); // a leitura da conta no abrir não sai para o ML de verdade
        $json = $this->actingAs($this->admin())->getJson(route('mlb.anuncios.publicador.abrir', $doBackfill->id))->assertOk()->json();
        $this->assertSame($editor['liberada'], $json['publicacao_liberada'], 'props e JSON concordam');

        $doOutro = $this->pagina(self::BASE.'/produtos/'.$daEmpresa->id.'/editor')['props'];
        $this->assertSame('Dev 02 (Polos)', $doOutro['empresa']['conta_nome']);
        $this->assertSame('123456', $doOutro['empresa']['conta_ml_id']);
        $this->assertFalse($doOutro['liberada'], 'a MlbEmpresa não está liberada');

        $linhas = collect($this->pagina(self::BASE.'/empresas/empresa-'.$e->id)['props']['produtos'])->keyBy('sku');
        $this->assertTrue($linhas['BF1']['conta_diferente']);
        $this->assertSame('Dev 02 Testes API', $linhas['BF1']['conta_nome']);
        $this->assertTrue($linhas['BF1']['liberada']);
        $this->assertFalse($linhas['E1']['conta_diferente']);
        $this->assertSame('Dev 02 (Polos)', $linhas['E1']['conta_nome']);
        $this->assertFalse($linhas['E1']['liberada']);
    }

    /** WR-B05: empresa listada na aba Polos com `projeto` em outra caixa ou com espaços abre a tela B e o editor. */
    public function test_wr_b05_projeto_com_outra_caixa_ou_espacos_aparece_na_tela_a_e_abre_a_tela_b(): void
    {
        $polos = $this->empresa(['nome' => 'Caixa Mista', 'projeto' => 'Polos'], comToken: true);
        $espacos = $this->empresa(['nome' => 'Com Espacos', 'projeto' => ' POLOS '], comToken: true);
        $this->actingAs($this->admin());

        $listadas = collect($this->get('/mlb/anuncios?programa=polos')->assertOk()->viewData('page')['props']['empresas'])->pluck('nome')->all();
        $this->assertEqualsCanonicalizing(['Caixa Mista', 'Com Espacos'], $listadas);

        foreach ([$polos, $espacos] as $e) {
            $this->get(self::BASE.'/empresas/empresa-'.$e->id)->assertOk();
            $p = PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => 'S'.$e->id, 'nome' => 'Produto', 'origem' => 'publicador']);
            $this->get(self::BASE.'/produtos/'.$p->id.'/editor')->assertOk();
        }
    }

    public function test_404_para_arquivada_chave_invalida_e_produto_inexistente(): void
    {
        $e = $this->empresa();
        $p = PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => 'A', 'nome' => 'A', 'origem' => 'publicador']);
        $semDono = PubProduto::create(['sku' => 'Z', 'nome' => 'Órfão', 'origem' => 'publicador']);
        $this->actingAs($this->admin());

        $this->get(self::BASE.'/empresas/empresa-999999')->assertNotFound();
        $this->get(self::BASE.'/empresas/company-999999')->assertNotFound();
        $this->get(self::BASE.'/empresas/qualquer')->assertNotFound();
        $this->get(self::BASE.'/produtos/999999/editor')->assertNotFound();
        $this->get(self::BASE.'/produtos/'.$semDono->id.'/editor')->assertNotFound();
        $this->postJson(self::BASE.'/empresas/empresa-999999/produtos', ['sku' => 'a', 'nome' => 'b'])->assertNotFound();

        $e->forceFill(['arquivado_em' => now()])->save();
        $this->get(self::BASE.'/empresas/empresa-'.$e->id)->assertNotFound();
        $this->get(self::BASE.'/produtos/'.$p->id.'/editor')->assertNotFound();
        $this->postJson(self::BASE.'/empresas/empresa-'.$e->id.'/produtos', ['sku' => 'a', 'nome' => 'b'])->assertNotFound();
    }

    public function test_nao_admin_recebe_403_em_todas_as_rotas(): void
    {
        $e = $this->empresa();
        $p = PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => 'A', 'nome' => 'A', 'origem' => 'publicador']);
        $this->actingAs(User::factory()->create(['role' => 'consultor']));

        $this->get(self::BASE.'/empresas/empresa-'.$e->id)->assertForbidden();
        $this->postJson(self::BASE.'/empresas/empresa-'.$e->id.'/sincronizar')->assertForbidden();
        $this->postJson(self::BASE.'/empresas/empresa-'.$e->id.'/produtos', ['sku' => 'a', 'nome' => 'b'])->assertForbidden();
        $this->get(self::BASE.'/produtos/'.$p->id.'/editor')->assertForbidden();
        $this->assertSame(1, PubProduto::count());
    }
}
