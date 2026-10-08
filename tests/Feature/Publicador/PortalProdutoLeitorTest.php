<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaOfertaComponente;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\EstruturaProdutoVolume;
use App\Models\PubProduto;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PortalProdutoLeitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Fase 172-07 — o leitor do Portal é escopado por empresa; o rascunho nasce sem ler a conta no ML. */
class PortalProdutoLeitorTest extends TestCase
{
    use RefreshDatabase;

    private PortalProdutoLeitor $leitor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->leitor = app(PortalProdutoLeitor::class);
    }

    /** @return array{0: EstruturaProduto, 1: list<EstruturaProdutoVariacao>, 2: list<EstruturaOferta>} */
    private function produto(Company $c, string $codigo, string $nome, int $cores = 2, array $extra = []): array
    {
        $p = EstruturaProduto::create(['company_id' => $c->id, 'codigo' => $codigo, 'nome' => $nome] + $extra);
        $vars = [];
        $ofertas = [];
        for ($i = 1; $i <= $cores; $i++) {
            $v = EstruturaProdutoVariacao::create([
                'produto_id' => $p->id, 'company_id' => $c->id, 'ordem' => $cores - $i, 'codigo' => "{$codigo}-{$i}",
                'eixo' => 'cor', 'valor' => "Cor {$i}", 'custo' => 10 * $i, 'estoque' => 5 * $i,
            ]);
            EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 50, 'largura' => 40, 'altura' => 30, 'peso' => 2.5]);
            $ofertas[] = EstruturaOferta::create(['company_id' => $c->id, 'variacao_id' => $v->id, 'sku' => "{$codigo}-{$i}", 'fase' => 'simples', 'nome' => "{$nome} {$i}"]);
            $vars[] = $v;
        }

        return [$p, $vars, $ofertas];
    }

    private function pub(EstruturaProduto $p, ?int $ofertaId = null): PubProduto
    {
        return PubProduto::create([
            'company_id' => $p->company_id, 'estrutura_produto_id' => $ofertaId === null ? $p->id : null, 'oferta_id' => $ofertaId,
            'sku' => $p->codigo, 'nome' => $p->nome, 'origem' => PubProduto::ORIGEM_PORTAL,
        ]);
    }

    public function test_do_grupo_traz_categoria_ficha_descricao_e_cores_ordenadas(): void
    {
        $c = Company::factory()->create();
        [$p, $vars] = $this->produto($c, 'MESA', 'Mesa', 2, [
            'categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'Mesas', 'descricao' => 'Mesa boa',
        ]);
        EstruturaProdutoAtributo::create(['company_id' => $c->id, 'produto_id' => $p->id, 'atributo_id' => 'BRAND', 'atributo_nome' => 'Marca', 'valor' => 'ECF']);
        EstruturaProdutoVariacaoImagem::create([
            'company_id' => $c->id, 'produto_id' => $p->id, 'variacao_id' => $vars[0]->id,
            'caminho' => "estrutura/{$c->id}/produtos/{$p->id}/variacoes/{$vars[0]->id}/a.jpg", 'nome_original' => 'a.jpg', 'mime' => 'image/jpeg', 'tamanho' => 10, 'largura' => 800, 'altura' => 800, 'ordem' => 0,
        ]);

        $r = $this->leitor->doGrupo($this->pub($p));

        $this->assertSame('MLB1', $r['categoria']['id']);
        $this->assertSame('Mesa boa', $r['descricao']);
        $this->assertSame('BRAND', $r['atributos'][0]['id']);
        $this->assertSame([$vars[1]->id, $vars[0]->id], array_column($r['variacoes'], 'id'), 'ordenadas por ordem');
        $v = $r['variacoes'][1];
        $this->assertSame('Cor 1', $v['valor']);
        $this->assertSame(5, $v['estoque']);
        $this->assertSame(10.0, $v['custo']);
        $this->assertSame([['c' => 50.0, 'l' => 40.0, 'a' => 30.0, 'kg' => 2.5]], $v['volumes']);
        $this->assertSame('a.jpg', $v['imagens'][0]['nome_original']);
        $this->assertNotNull($v['oferta_id']);
    }

    public function test_categoria_so_vale_confirmada_ou_nao_validada(): void
    {
        $c = Company::factory()->create();
        [$p] = $this->produto($c, 'A', 'A', 1, ['categoria_ml_nome' => 'Só o nome']);

        $pub = $this->pub($p);
        $this->assertNull($this->leitor->doGrupo($pub)['categoria']);

        $p->update(['categoria_ml_id' => 'MLB9']);
        $this->assertSame('MLB9', $this->leitor->doGrupo($pub)['categoria']['id']);
    }

    public function test_do_grupo_de_outra_empresa_e_null(): void
    {
        $dona = Company::factory()->create();
        $outra = Company::factory()->create();
        [$p] = $this->produto($dona, 'MESA', 'Mesa');

        $cruzado = PubProduto::create(['company_id' => $outra->id, 'estrutura_produto_id' => $p->id, 'sku' => 'X', 'nome' => 'X', 'origem' => 'portal']);

        $this->assertNull($this->leitor->doGrupo($cruzado));
        $this->assertNull($this->leitor->descricaoDoCliente($cruzado));
    }

    public function test_numero_de_consultas_nao_cresce_com_as_cores(): void
    {
        $c = Company::factory()->create();
        [$p1] = $this->produto($c, 'P2', 'Dois', 2);
        [$p2] = $this->produto($c, 'P6', 'Seis', 6);
        $a = $this->pub($p1);
        $b = $this->pub($p2);

        DB::enableQueryLog();
        $this->leitor->doGrupo($a);
        $poucas = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->leitor->doGrupo($b);
        $muitas = count(DB::getQueryLog());

        $this->assertSame($poucas, $muitas);
        $this->assertLessThanOrEqual(6, $muitas);
    }

    private function composta(Company $c, string $fase = 'kit'): array
    {
        [$mesa, , $om] = $this->produto($c, 'KMESA', 'Mesa', 1, ['descricao' => 'Mesa de madeira']);
        [$cad, , $oc] = $this->produto($c, 'KCAD', 'Cadeira', 1, ['descricao' => 'Cadeira macia']);
        $oferta = EstruturaOferta::create(['company_id' => $c->id, 'sku' => 'KIT-1', 'fase' => $fase, 'nome' => 'Kit']);
        EstruturaOfertaComponente::create(['oferta_id' => $oferta->id, 'componente_id' => $om[0]->id, 'quantidade' => 1]);
        EstruturaOfertaComponente::create(['oferta_id' => $oferta->id, 'componente_id' => $oc[0]->id, 'quantidade' => 4]);

        return [$oferta, $om[0], $oc[0]];
    }

    public function test_da_composta_traz_itens_com_quantidade_tipo_e_so_a_variacao_do_componente(): void
    {
        $c = Company::factory()->create();
        [$oferta] = $this->composta($c);
        $pub = PubProduto::create(['company_id' => $c->id, 'oferta_id' => $oferta->id, 'sku' => 'KIT-1', 'nome' => 'Kit', 'origem' => 'portal']);

        $r = $this->leitor->daComposta($pub);

        $this->assertSame('kit', $r['fase']);
        $this->assertSame('KIT-1', $r['sku']);
        $this->assertCount(2, $r['itens']);
        $this->assertSame(1, $r['itens'][0]['quantidade']);
        $this->assertSame(4, $r['itens'][1]['quantidade']);
        $this->assertSame('mesa', $r['itens'][0]['tipo']);
        $this->assertSame('cadeira', $r['itens'][1]['tipo']);
        $this->assertCount(1, $r['itens'][1]['produto']['variacoes']);
        $this->assertSame([], $r['avisos']);
        $this->assertIsArray($r['pares']);
    }

    public function test_componente_sem_variacao_ou_de_outra_empresa_fica_fora_com_aviso(): void
    {
        $c = Company::factory()->create();
        $outra = Company::factory()->create();
        [$oferta] = $this->composta($c);
        $solta = EstruturaOferta::create(['company_id' => $c->id, 'sku' => 'SOLTA', 'fase' => 'simples']);
        [, , $deOutra] = $this->produto($outra, 'ALHEIO', 'Alheio', 1);
        EstruturaOfertaComponente::create(['oferta_id' => $oferta->id, 'componente_id' => $solta->id, 'quantidade' => 1]);
        EstruturaOfertaComponente::create(['oferta_id' => $oferta->id, 'componente_id' => $deOutra[0]->id, 'quantidade' => 1]);
        $pub = PubProduto::create(['company_id' => $c->id, 'oferta_id' => $oferta->id, 'sku' => 'KIT-1', 'nome' => 'Kit', 'origem' => 'portal']);

        $r = $this->leitor->daComposta($pub);

        $this->assertCount(2, $r['itens']);
        $this->assertCount(2, $r['avisos']);
    }

    public function test_da_composta_de_oferta_simples_e_null(): void
    {
        $c = Company::factory()->create();
        [, , $ofertas] = $this->produto($c, 'S', 'S', 1);
        $pub = PubProduto::create(['company_id' => $c->id, 'oferta_id' => $ofertas[0]->id, 'sku' => 'S-1', 'nome' => 'S', 'origem' => 'portal']);

        $this->assertNull($this->leitor->daComposta($pub));
    }

    public function test_ler_imagem_de_outra_empresa_ou_com_traversal_e_null_sem_tocar_o_disco(): void
    {
        $c = Company::factory()->create();
        $outra = Company::factory()->create();

        // O arquivo da outra empresa existe de verdade: o prefixo é o que impede a leitura.
        Storage::disk('local')->put("estrutura/{$outra->id}/produtos/1/foto.jpg", 'segredo');
        Storage::disk('local')->put("estrutura/{$c->id}/produtos/1/foto.jpg", 'meu');
        Storage::disk('local')->put('fora.txt', 'fora');

        $this->assertNull($this->leitor->lerImagem($c->id, "estrutura/{$outra->id}/produtos/1/foto.jpg"));
        $this->assertNull($this->leitor->lerImagem($c->id, "estrutura/{$c->id}/../fora.txt"));
        $this->assertNull($this->leitor->lerImagem($c->id, 'fora.txt'));
        $this->assertSame('meu', $this->leitor->lerImagem($c->id, "estrutura/{$c->id}/produtos/1/foto.jpg"));
        $this->assertNull($this->leitor->lerImagem($c->id, "estrutura/{$c->id}/produtos/1/sumiu.jpg"));
    }

    public function test_caminho_de_outra_empresa_nao_cria_nem_le_arquivo(): void
    {
        $c = Company::factory()->create();
        $outra = Company::factory()->create();

        $this->assertNull($this->leitor->lerImagem($c->id, "estrutura/{$outra->id}/x.jpg"));
        Storage::disk('local')->assertMissing("estrutura/{$outra->id}/x.jpg");
    }

    public function test_descricao_do_cliente_do_grupo_da_simples_e_da_composta(): void
    {
        $c = Company::factory()->create();
        [$p, , $ofertas] = $this->produto($c, 'MESA', 'Mesa', 2, ['descricao' => 'Texto da mesa']);

        $this->assertSame('Texto da mesa', $this->leitor->descricaoDoCliente($this->pub($p)));

        $simples = PubProduto::create(['company_id' => $c->id, 'oferta_id' => $ofertas[0]->id, 'sku' => 'MESA-1', 'nome' => 'Mesa 1', 'origem' => 'portal']);
        $this->assertSame('Texto da mesa', $this->leitor->descricaoDoCliente($simples));

        [$oferta] = $this->composta($c);
        $kit = PubProduto::create(['company_id' => $c->id, 'oferta_id' => $oferta->id, 'sku' => 'KIT-1', 'nome' => 'Kit', 'origem' => 'portal']);
        $this->assertSame("Mesa: Mesa de madeira\n\nCadeira: Cadeira macia", $this->leitor->descricaoDoCliente($kit));
    }

    public function test_descricao_sem_portal_e_null(): void
    {
        $c = Company::factory()->create();
        $pub = PubProduto::create(['company_id' => $c->id, 'sku' => 'LIVRE', 'nome' => 'Livre', 'origem' => 'publicador']);

        $this->assertNull($this->leitor->descricaoDoCliente($pub));
    }

    // ── EditorRascunhoService::rascunhoDoProduto ─────────────────────────

    public function test_rascunho_do_produto_nasce_sem_chamar_a_conta_e_com_sku(): void
    {
        Http::fake();
        $c = Company::factory()->create();
        $pub = PubProduto::create(['company_id' => $c->id, 'sku' => 'LIVRE-1', 'nome' => 'Livre', 'origem' => 'publicador']);

        $r = app(EditorRascunhoService::class)->rascunhoDoProduto($pub);

        Http::assertNothingSent();
        $v = app(\App\Services\Publicador\RascunhoRepository::class)->snapshot($r)->variantes;
        $this->assertCount(1, $v);
        $this->assertSame('LIVRE-1', $v[0]->dados['atributos']['SELLER_SKU']['value_name']);
    }

    public function test_rascunho_do_produto_sem_sku_e_idempotente(): void
    {
        Http::fake();
        $c = Company::factory()->create();
        $pub = PubProduto::create(['company_id' => $c->id, 'sku' => 'GRP', 'nome' => 'Grupo', 'origem' => 'publicador']);
        $svc = app(EditorRascunhoService::class);

        $r = $svc->rascunhoDoProduto($pub, comSku: false);
        $v = app(\App\Services\Publicador\RascunhoRepository::class)->snapshot($r)->variantes;
        $this->assertArrayNotHasKey('SELLER_SKU', $v[0]->dados['atributos'] ?? []);

        $this->assertSame($r->id, $svc->rascunhoDoProduto($pub)->id, 'rascunho existente é devolvido sem mudança');
        Http::assertNothingSent();
    }
}
