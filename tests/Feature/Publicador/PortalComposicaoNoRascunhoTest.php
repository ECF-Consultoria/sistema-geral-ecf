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
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PortalParaRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * Fase 172-10 — Combo, Kit e Combit chegam ao rascunho: categoria e ficha do PRINCIPAL, pacote empilhado,
 * estoque = menor piso(estoque ÷ quantidade), fotos de todos os componentes. Só o vazio; nunca escreve no ML.
 */
class PortalComposicaoNoRascunhoTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    private Company $empresa;

    private RascunhoRepository $repo;

    private PortalParaRascunhoService $servico;

    private int $semente = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake();

        foreach ([self::CADEIRA, self::PASTILHA] as $categoria) {
            $schema = self::schema($categoria);
            MlCategoriaSchema::create(['category_id' => $categoria, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
                'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
                'schema_hash' => $schema->hash(), 'fetched_at' => now()]);
        }

        $this->empresa = Company::factory()->create();
        $this->repo = new RascunhoRepository();
        $this->servico = app(PortalParaRascunhoService::class);
    }

    // ═══ Ajudantes ═══════════════════════════════════════════════════════════

    private function bytes(): string
    {
        $this->semente += 41;
        $im = imagecreatetruecolor(800, 800);
        imagefill($im, 0, 0, imagecolorallocate($im, $this->semente % 256, ($this->semente * 5) % 256, ($this->semente * 11) % 256));
        ob_start();
        imagejpeg($im, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * Um produto de UMA variação com oferta simples, volume, ficha e fotos.
     *
     * @return array{0: EstruturaOferta, 1: list<string>} a oferta simples e os sha256 das fotos na ordem
     */
    private function componente(string $nome, string $categoria, ?int $estoque, float $custo, int $fotos = 1, ?Company $empresa = null, string $marca = 'Marca'): array
    {
        $empresa ??= $this->empresa;
        $p = EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => strtoupper($nome), 'nome' => $nome,
            'categoria_ml_id' => $categoria, 'categoria_ml_nome' => 'Categoria']);
        EstruturaProdutoAtributo::create(['company_id' => $empresa->id, 'produto_id' => $p->id, 'atributo_id' => 'BRAND',
            'atributo_nome' => 'Marca', 'valor' => $marca]);
        $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $empresa->id, 'ordem' => 0,
            'codigo' => strtoupper($nome).'-1', 'eixo' => 'cor', 'valor' => 'Unica', 'custo' => $custo, 'estoque' => $estoque]);
        EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 50, 'largura' => 40, 'altura' => 30, 'peso' => 2.5]);
        $oferta = EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => $v->id, 'sku' => strtoupper($nome).'-1', 'fase' => 'simples', 'nome' => $nome]);

        $shas = [];
        for ($i = 0; $i < $fotos; $i++) {
            $conteudo = $this->bytes();
            $caminho = "estrutura/{$empresa->id}/produtos/{$p->id}/variacoes/{$v->id}/foto{$i}.jpg";
            Storage::disk('local')->put($caminho, $conteudo);
            EstruturaProdutoVariacaoImagem::create(['company_id' => $empresa->id, 'produto_id' => $p->id, 'variacao_id' => $v->id,
                'caminho' => $caminho, 'nome_original' => "foto{$i}", 'mime' => 'image/jpeg', 'tamanho' => 1000, 'largura' => 800, 'altura' => 800, 'ordem' => $i]);
            $shas[] = hash('sha256', $conteudo);
        }

        return [$oferta, $shas];
    }

    /** @param list<array{0: EstruturaOferta, 1: int}> $itens [oferta do componente, quantidade] */
    private function composta(string $fase, array $itens, string $sku = 'KIT-1'): PubProduto
    {
        $oferta = EstruturaOferta::create(['company_id' => $this->empresa->id, 'sku' => $sku, 'fase' => $fase, 'nome' => $sku]);
        foreach ($itens as [$comp, $quantidade]) {
            EstruturaOfertaComponente::create(['oferta_id' => $oferta->id, 'componente_id' => $comp->id, 'quantidade' => $quantidade]);
        }

        return PubProduto::create(['company_id' => $this->empresa->id, 'oferta_id' => $oferta->id, 'sku' => $sku, 'nome' => $sku, 'origem' => PubProduto::ORIGEM_PORTAL]);
    }

    private function rascunho(PubProduto $pub): PubRascunho
    {
        return PubRascunho::where('produto_id', $pub->id)->firstOrFail();
    }

    private function snap(PubProduto $pub): RascunhoSnapshot
    {
        return $this->repo->snapshot($this->rascunho($pub));
    }

    /** @return list<string> sha256 das fotos do grupo geral, pela posição */
    private function fotosGerais(PubProduto $pub): array
    {
        $r = $this->rascunho($pub);
        $sha = $r->imagens()->pluck('sha256', 'id')->all();
        $lista = array_filter($this->repo->snapshot($r)->imagens, fn ($a) => $a['grupo'] === 'GENERAL');
        usort($lista, fn ($a, $b) => $a['posicao'] <=> $b['posicao']);

        return array_values(array_map(fn ($a) => $sha[(int) $a['imagem']], $lista));
    }

    private function unica(PubProduto $pub): array
    {
        $v = collect($this->snap($pub)->variantes)->first(fn ($v) => $v->chave === ChaveCanonica::UNICA);
        $this->assertNotNull($v);

        return $v->dados;
    }

    // ═══ Testes ══════════════════════════════════════════════════════════════

    public function test_combo_herda_categoria_ficha_pacote_fotos_e_divide_o_estoque(): void
    {
        [$cadeira, $fotos] = $this->componente('Cadeira', self::CADEIRA, 9, 150, 2, null, 'ECF');
        $pub = $this->composta('combo', [[$cadeira, 4]], 'COMBO-4');

        $resumo = $this->servico->preencher($pub);

        $r = $this->rascunho($pub);
        $s = $this->snap($pub);
        $this->assertSame(self::CADEIRA, $r->categoria_id);
        $this->assertSame('ECF', $s->atributos['BRAND']['value_name']);
        $this->assertSame('50 cm', $s->atributos['SELLER_PACKAGE_LENGTH']['value_name']);
        $this->assertSame('120 cm', $s->atributos['SELLER_PACKAGE_HEIGHT']['value_name'], '4 volumes empilhados');
        $this->assertSame('10000 g', $s->atributos['SELLER_PACKAGE_WEIGHT']['value_name']);
        $this->assertCount(1, $s->variantes);
        $dados = $this->unica($pub);
        $this->assertSame('COMBO-4', $dados['atributos']['SELLER_SKU']['value_name']);
        $this->assertSame(2, $dados['estoque'], 'piso(9 / 4)');
        $this->assertSame($fotos, $this->fotosGerais($pub));
        $this->assertSame(2, $resumo['fotos_trazidas']);
        $this->assertFalse($resumo['intocavel']);
        Http::assertNothingSent();
    }

    public function test_kit_de_mesa_com_4_cadeiras_usa_categoria_e_ficha_da_mesa_e_fotos_dos_dois(): void
    {
        [$cadeira, $fotosCadeira] = $this->componente('Cadeira', self::CADEIRA, 9, 150, 1, null, 'Marca da Cadeira');
        [$mesa, $fotosMesa] = $this->componente('Mesa', self::PASTILHA, 3, 800, 1, null, 'Marca da Mesa');
        // A cadeira vem primeiro na composição: o principal é a mesa por regra, não por posição.
        $pub = $this->composta('kit', [[$cadeira, 4], [$mesa, 1]]);

        $this->servico->preencher($pub);

        $s = $this->snap($pub);
        $this->assertSame(self::PASTILHA, $this->rascunho($pub)->categoria_id, 'categoria da MESA');
        if (isset($s->atributos['BRAND'])) {
            $this->assertSame('Marca da Mesa', $s->atributos['BRAND']['value_name']);
        }
        $this->assertSame([...$fotosMesa, ...$fotosCadeira], $this->fotosGerais($pub), 'principal primeiro, depois os outros');
        $this->assertSame(2, $this->unica($pub)['estoque'], 'min(piso(3/1), piso(9/4))');
        $this->assertSame('150 cm', $s->atributos['SELLER_PACKAGE_HEIGHT']['value_name'], '1 mesa + 4 cadeiras = 5 volumes de 30 cm');
    }

    public function test_kit_de_tres_sem_par_usa_o_de_maior_custo_vezes_quantidade(): void
    {
        [$a] = $this->componente('Alfa Zeta', self::PASTILHA, 10, 100);
        [$b] = $this->componente('Beta Zeta', self::CADEIRA, 10, 30);
        [$c] = $this->componente('Gama Zeta', self::PASTILHA, 10, 20);
        $pub = $this->composta('kit', [[$a, 1], [$b, 5], [$c, 1]]);

        $this->servico->preencher($pub);

        $this->assertSame(self::CADEIRA, $this->rascunho($pub)->categoria_id, '30 x 5 = 150 vence 100');
    }

    public function test_componente_com_estoque_desconhecido_deixa_o_estoque_do_kit_nulo(): void
    {
        [$a] = $this->componente('Alfa Zeta', self::CADEIRA, 10, 100);
        [$b] = $this->componente('Beta Zeta', self::CADEIRA, null, 30);
        $pub = $this->composta('kit', [[$a, 1], [$b, 1]]);

        $this->servico->preencher($pub);

        $this->assertArrayNotHasKey('estoque', $this->unica($pub), 'estoque não é inventado');
    }

    public function test_componente_sem_variacao_fica_fora_com_aviso_e_os_demais_preenchem(): void
    {
        [$a, $fotos] = $this->componente('Alfa Zeta', self::CADEIRA, 8, 100);
        $solta = EstruturaOferta::create(['company_id' => $this->empresa->id, 'sku' => 'SOLTA', 'fase' => 'simples']);
        $pub = $this->composta('kit', [[$a, 2], [$solta, 1]]);

        $resumo = $this->servico->preencher($pub);

        $this->assertSame(self::CADEIRA, $this->rascunho($pub)->categoria_id);
        $this->assertSame(4, $this->unica($pub)['estoque']);
        $this->assertSame($fotos, $this->fotosGerais($pub));
        $this->assertNotEmpty(array_filter($resumo['avisos'], fn ($x) => str_contains($x, 'componente')));
    }

    public function test_nao_sobrescreve_o_que_a_equipe_fez_e_rodar_de_novo_nao_muda_nada(): void
    {
        [$a, $fotos] = $this->componente('Alfa Zeta', self::CADEIRA, 8, 100, 2, null, 'Marca do Portal');
        $pub = $this->composta('combo', [[$a, 2]]);

        // A equipe já pôs a categoria, uma marca, o estoque e uma foto.
        $editor = app(EditorRascunhoService::class);
        $r = $editor->rascunhoDoProduto($pub, true);
        $editor->trocarCategoria($r, self::CADEIRA);
        $this->repo->mesclarAtributos($r, ['BRAND' => ['value_name' => 'Marca da equipe', 'origem' => 'user']]);
        $editor->salvarVariantes($r, [ChaveCanonica::UNICA => ['estoque' => 99]]);
        $da = app(\App\Services\Publicador\ImagemAssetService::class)->receber($r, $this->bytes(), 'equipe.jpg', enviar: false);
        $editor->colocarFotoNoGrupo($r, $da['imagem'], 'GENERAL');

        $this->servico->preencher($pub);

        $s = $this->snap($pub);
        $this->assertSame('Marca da equipe', $s->atributos['BRAND']['value_name']);
        $this->assertSame(99, $this->unica($pub)['estoque']);
        $this->assertSame([$da['imagem']->sha256], $this->fotosGerais($pub), 'grupo com foto da equipe não recebe');

        $antes = [DB::table('pub_imagens')->count(), DB::table('pub_imagem_atribuicoes')->count(), DB::table('pub_rascunho_atributos')->count()];
        $revisao = $this->rascunho($pub)->revisao;
        $this->servico->preencher($pub);
        $this->assertSame($antes, [DB::table('pub_imagens')->count(), DB::table('pub_imagem_atribuicoes')->count(), DB::table('pub_rascunho_atributos')->count()]);
        $this->assertSame($revisao, $this->rascunho($pub)->revisao, 'a revisão só sobe quando algo foi gravado');
        $this->assertNotContains($fotos[0], $this->fotosGerais($pub));
    }

    public function test_componente_de_outra_empresa_nunca_e_lido(): void
    {
        $outra = Company::factory()->create();
        [$meu, $fotosMeu] = $this->componente('Alfa Zeta', self::CADEIRA, 8, 100);
        [$alheio, $fotosAlheio] = $this->componente('Beta Zeta', self::PASTILHA, 50, 900, 1, $outra, 'Marca Alheia');
        $pub = $this->composta('kit', [[$meu, 1], [$alheio, 1]]);

        $resumo = $this->servico->preencher($pub);

        $this->assertSame(self::CADEIRA, $this->rascunho($pub)->categoria_id, 'o principal não é o de maior custo da outra empresa');
        $this->assertSame($fotosMeu, $this->fotosGerais($pub));
        $this->assertSame([], array_intersect($fotosAlheio, $this->fotosGerais($pub)));
        $this->assertSame(8, $this->unica($pub)['estoque'], 'o estoque do alheio não entra na conta');
        $this->assertNotEmpty($resumo['avisos']);
    }
}
