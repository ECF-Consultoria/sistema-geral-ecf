<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\PreencherRascunhoDoPortalJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\EstruturaProdutoVolume;
use App\Models\MlbEmpresa;
use App\Models\MlCategoriaSchema;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\PortalParaRascunhoService;
use App\Services\Publicador\ResumoDoSincronizar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * Fase 172-12 — o clique em "Sincronizar do Portal" ponta a ponta: agrupa, despacha um Job por produto,
 * preenche o rascunho, agrega o resumo por pedido e NUNCA escreve no ML nem vaza dado entre empresas.
 */
class SincronizaPortalCompletoTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    private int $semente = 0;

    /** @var list<array{0: string, 1: string}> toda chamada HTTP que o teste viu (método, url) */
    private array $chamadas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Http::preventStrayRequests();
        // Registrado UMA vez (learnings §5); registra o que foi chamado para provar "zero HTTP".
        Http::fake(function ($request) {
            $this->chamadas[] = [$request->method(), $request->url()];

            return Http::response([], 200);
        });

        // Schema já guardado: o Sincronizar não chama o ML para ler categoria.
        $schema = self::schema(self::CADEIRA);
        MlCategoriaSchema::create(['category_id' => self::CADEIRA, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
            'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
            'schema_hash' => $schema->hash(), 'fetched_at' => now()]);
    }

    // ═══ Ajudantes ═══════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function bytes(): string
    {
        $this->semente += 37;
        $im = imagecreatetruecolor(800, 800);
        imagefill($im, 0, 0, imagecolorallocate($im, $this->semente % 256, ($this->semente * 3) % 256, ($this->semente * 7) % 256));
        ob_start();
        imagejpeg($im, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * Um produto do Portal com uma oferta Simples por cor, volume e uma foto por cor.
     *
     * @return array{0: Company, 1: MlbEmpresa, 2: EstruturaProduto}
     */
    private function empresaComPortal(string $codigo = 'MESA', array $cores = ['Azul', 'Preto', 'Verde']): array
    {
        $c = Company::factory()->create();
        $e = MlbEmpresa::create(['nome' => 'Polo '.$codigo, 'projeto' => 'POLOS', 'company_id' => $c->id])->fresh();
        $p = EstruturaProduto::create(['company_id' => $c->id, 'codigo' => $codigo, 'nome' => 'Mesa '.$codigo,
            'categoria_ml_id' => self::CADEIRA, 'categoria_ml_nome' => 'Categoria']);
        foreach ($cores as $i => $cor) {
            $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $c->id, 'ordem' => $i,
                'codigo' => $codigo.'-'.($i + 1), 'eixo' => 'cor', 'valor' => $cor, 'custo' => 10, 'estoque' => 5]);
            EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 50, 'largura' => 40, 'altura' => 30, 'peso' => 2.5]);
            EstruturaOferta::create(['company_id' => $c->id, 'variacao_id' => $v->id, 'sku' => $codigo.'-'.($i + 1), 'fase' => 'simples', 'nome' => 'Mesa '.$cor]);
            $caminho = "estrutura/{$c->id}/produtos/{$p->id}/variacoes/{$v->id}/foto0.jpg";
            Storage::disk('local')->put($caminho, $this->bytes());
            EstruturaProdutoVariacaoImagem::create(['company_id' => $c->id, 'produto_id' => $p->id, 'variacao_id' => $v->id,
                'caminho' => $caminho, 'nome_original' => 'foto0', 'mime' => 'image/jpeg', 'tamanho' => 1000, 'largura' => 800, 'altura' => 800, 'ordem' => 0]);
        }

        return [$c, $e, $p];
    }

    private function sincronizar(MlbEmpresa $e)
    {
        return $this->actingAs($this->admin())->postJson('/mlb/anuncios/publicador/empresas/empresa-'.$e->id.'/sincronizar');
    }

    private function lerResumo(string $conta, string $pedido)
    {
        return $this->actingAs($this->admin())->getJson('/mlb/anuncios/publicador/empresas/'.$conta.'/sincronizar/'.$pedido);
    }

    /** @return array<string, int> contagem das tabelas que o Sincronizar escreve */
    private function contagens(): array
    {
        return [
            'pub_produtos' => PubProduto::count(),
            'pub_rascunhos' => DB::table('pub_rascunhos')->count(),
            'pub_variantes' => DB::table('pub_variantes')->count(),
            'pub_imagens' => PubImagem::count(),
            'pub_rascunho_atributos' => DB::table('pub_rascunho_atributos')->count(),
        ];
    }

    // ═══ Task 1: Job, resumo e endpoint ══════════════════════════════════════

    public function test_clique_preenche_o_rascunho_e_o_resumo_fecha_pronto(): void
    {
        [$c, $e] = $this->empresaComPortal();

        $r = $this->sincronizar($e)->assertOk();
        $this->assertSame(1, $r->json('preenchendo'));
        $this->assertNotEmpty($r->json('pedido'));
        $this->assertStringContainsString('Preenchendo 1 rascunho com o que está no Portal.', $r->json('mensagem'));

        // Fila sync: o rascunho já está preenchido ao responder.
        $pub = PubProduto::where('company_id', $c->id)->firstOrFail();
        $rascunho = PubRascunho::where('produto_id', $pub->id)->firstOrFail();
        $this->assertSame(3, DB::table('pub_variantes')->where('rascunho_id', $rascunho->id)->count());

        $res = $this->lerResumo('empresa-'.$e->id, $r->json('pedido'))->assertOk();
        $this->assertSame('pronto', $res->json('status'));
        $this->assertSame(1, $res->json('total'));
        $this->assertSame(1, $res->json('concluidos'));
        $this->assertSame(1, $res->json('produtos'));
        $this->assertSame(3, $res->json('variantes'));
        $this->assertSame(3, $res->json('fotos_trazidas'));
        $this->assertGreaterThan(0, $res->json('campos_preenchidos'));
    }

    public function test_resumo_de_outra_empresa_ou_inexistente_da_404(): void
    {
        [, $e] = $this->empresaComPortal('A');
        [, $outra] = $this->empresaComPortal('B');

        $pedido = $this->sincronizar($e)->json('pedido');

        // A empresa B não lê o resumo da A (T-172-42).
        $this->lerResumo('empresa-'.$outra->id, $pedido)->assertNotFound();
        $this->lerResumo('empresa-'.$e->id, '00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->lerResumo('empresa-'.$e->id, $pedido)->assertOk();
    }

    public function test_job_que_falha_registra_aviso_e_o_resumo_fecha(): void
    {
        [$c, $e] = $this->empresaComPortal();
        $this->mock(PortalParaRascunhoService::class, fn ($m) => $m->shouldReceive('preencher')->andThrow(new \RuntimeException('quebrou')));

        // Com a fila sync a exceção sobe ao request; o failed() é o que a fila real chama depois.
        $pub = null;
        try {
            $this->withoutExceptionHandling();
            $this->sincronizar($e);
        } catch (\RuntimeException) {
            $pub = PubProduto::where('company_id', $c->id)->firstOrFail();
        }
        $this->assertNotNull($pub);

        $resumo = app(ResumoDoSincronizar::class);
        $pedido = $resumo->abrir($c->id, [$pub->id]);
        (new PreencherRascunhoDoPortalJob($pub->id, $pedido))->failed(new \RuntimeException('quebrou'));

        $res = $resumo->ler($pedido, $c->id);
        $this->assertSame('pronto', $res['status']);
        $this->assertSame($res['total'], $res['concluidos']);
        $this->assertStringContainsString('Não foi possível preencher', $res['avisos'][0]);
    }

    public function test_empresa_sem_nada_a_preencher_nao_abre_pedido(): void
    {
        $c = Company::factory()->create();
        $e = MlbEmpresa::create(['nome' => 'Polo Vazio', 'projeto' => 'POLOS', 'company_id' => $c->id])->fresh();
        EstruturaOferta::create(['company_id' => $c->id, 'sku' => 'A1', 'fase' => 'simples', 'nome' => 'Avulsa']);

        $r = $this->sincronizar($e)->assertOk();
        $this->assertNull($r->json('pedido'));
        $this->assertSame(0, $r->json('preenchendo'));
        $this->assertSame('1 produto novo do Portal.', $r->json('mensagem'));
    }

    public function test_um_job_por_produto_na_fila_high(): void
    {
        [, $e] = $this->empresaComPortal();
        Queue::fake();

        $r = $this->sincronizar($e)->assertOk();

        Queue::assertPushed(PreencherRascunhoDoPortalJob::class, 1);
        Queue::assertPushedOn('high', PreencherRascunhoDoPortalJob::class, fn ($j) => $j->pedido === $r->json('pedido'));
    }

    // ═══ Task 2: garantias ponta a ponta ═════════════════════════════════════

    public function test_isolamento_entre_empresas_nada_de_b_entra_no_rascunho_de_a(): void
    {
        [$a, $ea, $pa] = $this->empresaComPortal('AAA', ['Azul', 'Preto']);
        [$b, $eb, $pb] = $this->empresaComPortal('BBB', ['Rosa', 'Cinza', 'Roxo']);

        // Os bytes das fotos de B, para provar que nenhuma entrou na empresa A.
        $shaDeB = [];
        foreach (EstruturaProdutoVariacaoImagem::where('company_id', $b->id)->get() as $img) {
            $shaDeB[] = hash('sha256', Storage::disk('local')->get($img->caminho));
        }

        // Dado hostil/corrompido: um produto de A apontando para o produto de B. Preencher não pode ler B.
        $intruso = PubProduto::create(['company_id' => $a->id, 'estrutura_produto_id' => $pb->id, 'sku' => 'INT', 'nome' => 'Intruso', 'origem' => PubProduto::ORIGEM_PORTAL]);
        app(PortalParaRascunhoService::class)->preencher($intruso);
        $this->assertSame(0, DB::table('pub_variantes')->join('pub_rascunhos', 'pub_rascunhos.id', '=', 'pub_variantes.rascunho_id')
            ->where('pub_rascunhos.produto_id', $intruso->id)->count(), 'o rascunho de A leu variações de B');
        $this->assertSame(0, PubImagem::whereIn('sha256', $shaDeB)->count(), 'uma foto de B entrou no Publicador de A');
        $intruso->delete();

        // Sincronizar A: só A ganha produtos, rascunhos, variantes e fotos.
        $this->sincronizar($ea)->assertOk();
        $this->assertSame(0, PubProduto::where('company_id', $b->id)->count());
        $this->assertSame(1, PubProduto::where('company_id', $a->id)->count());
        $this->assertSame(2, DB::table('pub_variantes')->count());
        $this->assertSame(0, PubImagem::whereIn('sha256', $shaDeB)->count());

        // E o produto de A nunca cita atributo, SKU ou cor de B.
        $this->assertSame(0, DB::table('pub_variantes')->where('sku', 'like', 'BBB%')->count());
    }

    public function test_empresa_fora_do_piloto_e_preenchida_igual_d10(): void
    {
        [$c, $e] = $this->empresaComPortal();
        // Piloto = outra empresa qualquer; esta NÃO está liberada.
        config(['publicador.contas_liberadas' => ['companies' => [999999], 'mlb_empresas' => []]]);

        $r = $this->sincronizar($e)->assertOk();
        $this->assertSame(1, $r->json('preenchendo'));
        $pub = PubProduto::where('company_id', $c->id)->firstOrFail();
        $this->assertSame(3, DB::table('pub_variantes')
            ->join('pub_rascunhos', 'pub_rascunhos.id', '=', 'pub_variantes.rascunho_id')->where('pub_rascunhos.produto_id', $pub->id)->count());
        $this->assertSame(3, PubImagem::count());
    }

    public function test_nada_no_ml_conta_liberada_com_token_zero_http_e_zero_publicacao(): void
    {
        [$c, $e] = $this->empresaComPortal();
        // Conta DENTRO do piloto e com token: o caso mais perigoso para escrever no ML.
        config(['publicador.contas_liberadas' => ['companies' => [$c->id], 'mlb_empresas' => []]]);
        \App\Models\MlToken::create(['company_id' => $c->id, 'ml_user_id' => '1555596317', 'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token', 'token_type' => 'bearer', 'expires_at' => now()->addHours(5),
            'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);

        $this->sincronizar($e)->assertOk();

        // Nenhuma chamada: nem leitura (/users/me), nem /items, nem /pictures, nem POST/PUT.
        $this->assertSame([], $this->chamadas, 'o Sincronizar falou com a rede: '.json_encode($this->chamadas));
        foreach ($this->chamadas as [$metodo, $url]) {
            $this->assertStringNotContainsString('/items', $url);
            $this->assertStringNotContainsString('/pictures', $url);
            $this->assertStringNotContainsString('/users/me', $url);
            $this->assertNotContains($metodo, ['POST', 'PUT', 'PATCH', 'DELETE']);
        }

        // Nada publicado: nenhuma pub_publicacoes e nenhum rascunho em publishing/published.
        $this->assertSame(0, DB::table('pub_publicacoes')->count());
        $this->assertSame(0, PubRascunho::whereIn('status', ['publishing', 'published'])->count());
        $this->assertGreaterThan(0, PubRascunho::count());
    }

    public function test_sincronizar_duas_vezes_e_idempotente(): void
    {
        [, $e] = $this->empresaComPortal();

        $this->sincronizar($e)->assertOk();
        $antes = $this->contagens();
        $revisoes = PubRascunho::orderBy('id')->pluck('revisao', 'id')->all();
        $this->assertSame(3, $antes['pub_imagens']);

        $r2 = $this->sincronizar($e)->assertOk();

        $this->assertSame($antes, $this->contagens(), 'o 2º clique duplicou linhas');
        $this->assertSame($revisoes, PubRascunho::orderBy('id')->pluck('revisao', 'id')->all(), 'o 2º clique mexeu na revisão do rascunho');
        $this->assertSame(0, $r2->json('criados'));
        $this->assertSame(0, DB::table('pub_publicacoes')->count());
    }

    public function test_job_do_mesmo_produto_que_ja_esta_rodando_nao_preenche_junto_e_fecha_o_proprio_resumo(): void
    {
        [$c, $e] = $this->empresaComPortal();
        Queue::fake();
        $this->sincronizar($e)->assertOk();
        $pub = PubProduto::where('company_id', $c->id)->whereNotNull('estrutura_produto_id')->firstOrFail();
        $this->mock(PortalParaRascunhoService::class, fn ($m) => $m->shouldNotReceive('preencher'));

        // Outro worker está com o produto (a trava é dele).
        $outro = \Illuminate\Support\Facades\Cache::lock(PreencherRascunhoDoPortalJob::chaveDaTrava($pub->id), 60);
        $this->assertTrue($outro->get());
        $resumo = app(ResumoDoSincronizar::class);
        $pedido = $resumo->abrir($c->id, [$pub->id]);
        app()->call([new PreencherRascunhoDoPortalJob($pub->id, $pedido), 'handle']);

        $res = $resumo->ler($pedido, $c->id);
        $this->assertSame('pronto', $res['status'], 'o 2º clique não fica esperando para sempre');
        $this->assertStringContainsString('já estava sendo preenchido', $res['avisos'][0]);
        $outro->release();
    }

    public function test_foto_guardada_por_outro_processo_entre_a_checagem_e_o_create_nao_quebra(): void
    {
        [$c] = $this->empresaComPortal();
        $pub = PubProduto::create(['company_id' => $c->id, 'sku' => 'X', 'nome' => 'X', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $r = app(\App\Services\Publicador\EditorRascunhoService::class)->rascunhoDoProduto($pub);
        $conteudo = $this->bytes();
        // O "outro processo" grava a mesma foto logo antes do create deste.
        PubImagem::creating(function (PubImagem $i) {
            if (DB::table('pub_imagens')->where('sha256', $i->sha256)->doesntExist()) {
                DB::table('pub_imagens')->insert([...$i->getAttributes(), 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $res = app(\App\Services\Publicador\ImagemAssetService::class)->receber($r, $conteudo, 'foto.jpg', enviar: false);

        $this->assertNotNull($res['imagem']);
        $this->assertFalse($res['nova']);
        $this->assertSame(1, DB::table('pub_imagens')->where('sha256', hash('sha256', $conteudo))->count());
    }

    public function test_rascunho_criado_por_outro_processo_e_reaproveitado(): void
    {
        [$c] = $this->empresaComPortal();
        $pub = PubProduto::create(['company_id' => $c->id, 'sku' => 'X', 'nome' => 'X', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        // O "outro processo" cria (e confirma) o rascunho logo antes do create deste.
        $this->app->instance(\App\Services\Publicador\RascunhoRepository::class, new class extends \App\Services\Publicador\RascunhoRepository
        {
            public ?int $outro = null;

            public function criar(PubProduto $produto, array $alvos, ?array $ator = null): PubRascunho
            {
                $this->outro ??= DB::table('pub_rascunhos')->insertGetId(['produto_id' => $produto->id, 'status' => PubRascunho::DRAFT,
                    'revisao' => 1, 'created_at' => now(), 'updated_at' => now()]);

                return parent::criar($produto, $alvos, $ator);
            }
        });
        $repo = app(\App\Services\Publicador\RascunhoRepository::class);

        $r = app(\App\Services\Publicador\EditorRascunhoService::class)->rascunhoDoProduto($pub);

        $this->assertNotNull($repo->outro);
        $this->assertSame($repo->outro, $r->id);
        $this->assertSame(1, PubRascunho::where('produto_id', $pub->id)->count());
    }
}
