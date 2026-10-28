<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\EstruturaProdutoVolume;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PortalParaRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * Cor E tamanho (28/10/2026, o usuário: "deveria ter mais de um eixo, assim como é no mercado livre"):
 * o produto do Portal com dois eixos chega ao rascunho com DOIS eixos — não "Outro" com os valores juntos.
 * Só as combinações que o Portal tem ficam ativas; as fotos são da cor. Os schemas vêm das respostas reais
 * guardadas (zero HTTP): a furadeira tem Cor e Voltagem como variação, a camiseta Cor e Tamanho, a pastilha nenhum.
 */
class PortalDoisEixosNoRascunhoTest extends TestCase
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

        foreach ([self::FURADEIRA, self::CAMISETA, self::PASTILHA] as $categoria) {
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

    /**
     * @param  list<array{0: string, 1: string, 2: ?int}>  $combinacoes  [valor 1, valor 2, estoque]
     * @return array{0: EstruturaProduto, 1: PubProduto, 2: list<EstruturaProdutoVariacao>}
     */
    private function produto(array $combinacoes, string $categoria = self::FURADEIRA, string $eixo2 = 'voltagem'): array
    {
        $p = EstruturaProduto::create(['company_id' => $this->empresa->id, 'codigo' => 'FUR', 'nome' => 'Furadeira',
            'categoria_ml_id' => $categoria, 'categoria_ml_nome' => 'Categoria']);
        $vars = [];
        foreach ($combinacoes as $i => [$cor, $valor2, $estoque]) {
            $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresa->id, 'ordem' => $i,
                'codigo' => 'FUR-'.($i + 1), 'eixo' => 'cor', 'valor' => $cor, 'eixo_2' => $eixo2, 'valor_2' => $valor2,
                'custo' => 10, 'estoque' => $estoque]);
            EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 30, 'largura' => 20, 'altura' => 10, 'peso' => 1.5]);
            EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $v->id, 'sku' => 'FUR-'.($i + 1), 'fase' => 'simples',
                'nome' => "Furadeira — {$cor} · {$valor2}"]);
            $vars[] = $v;
        }
        $pub = PubProduto::create(['company_id' => $this->empresa->id, 'estrutura_produto_id' => $p->id, 'sku' => 'FUR', 'nome' => 'Furadeira', 'origem' => PubProduto::ORIGEM_PORTAL]);

        return [$p, $pub, $vars];
    }

    private function foto(EstruturaProdutoVariacao $v, int $ordem): string
    {
        $this->semente += 37;
        $im = imagecreatetruecolor(800, 800);
        imagefill($im, 0, 0, imagecolorallocate($im, $this->semente % 256, ($this->semente * 3) % 256, ($this->semente * 7) % 256));
        ob_start();
        imagejpeg($im, null, 90);
        $bytes = (string) ob_get_clean();
        $caminho = "estrutura/{$this->empresa->id}/produtos/{$v->produto_id}/variacoes/{$v->id}/foto{$ordem}.jpg";
        Storage::disk('local')->put($caminho, $bytes);
        EstruturaProdutoVariacaoImagem::create(['company_id' => $this->empresa->id, 'produto_id' => $v->produto_id, 'variacao_id' => $v->id,
            'caminho' => $caminho, 'nome_original' => "foto{$ordem}", 'mime' => 'image/jpeg', 'tamanho' => 1000, 'largura' => 800, 'altura' => 800, 'ordem' => $ordem]);

        return hash('sha256', $bytes);
    }

    private function rascunho(PubProduto $pub): PubRascunho
    {
        return PubRascunho::where('produto_id', $pub->id)->firstOrFail();
    }

    private function snap(PubProduto $pub): RascunhoSnapshot
    {
        return $this->repo->snapshot($this->rascunho($pub));
    }

    /** @return array<string, array{ativa: bool, sku: ?string, estoque: mixed}> "Cor|Valor 2" → o que a variante tem */
    private function variantes(PubProduto $pub): array
    {
        $s = $this->snap($pub);
        $eixos = Eixo::ordenar($s->eixos);
        $saida = [];
        foreach ($s->variantes as $v) {
            if ($v->orfa) {
                continue;
            }
            $nome = implode('|', array_map(fn (Eixo $e) => $v->valores[$e->chave]->valueName ?? '?', $eixos));
            $saida[$nome] = ['ativa' => $v->ativa, 'sku' => $v->dados['atributos']['SELLER_SKU']['value_name'] ?? null, 'estoque' => $v->dados['estoque'] ?? null];
        }
        ksort($saida);

        return $saida;
    }

    // ═══ Testes ══════════════════════════════════════════════════════════════

    public function test_cor_e_voltagem_viram_dois_eixos_e_so_as_combinacoes_do_portal_ficam_ativas(): void
    {
        // Amarelo só existe em 127V: a combinação Amarelo 220V é gerada pelo Publicador e fica desativada.
        [, $pub] = $this->produto([['Preto', '127V', 4], ['Preto', '220V', 2], ['Amarelo', '127V', 7]]);

        $resumo = $this->servico->preencher($pub);

        $s = $this->snap($pub);
        $this->assertSame(['COLOR', 'VOLTAGE'], array_map(fn (Eixo $e) => $e->chave, Eixo::ordenar($s->eixos)));
        $this->assertSame([
            'Amarelo|127V' => ['ativa' => true, 'sku' => 'FUR-3', 'estoque' => 7],
            'Amarelo|220V' => ['ativa' => false, 'sku' => null, 'estoque' => null],
            'Preto|127V' => ['ativa' => true, 'sku' => 'FUR-1', 'estoque' => 4],
            'Preto|220V' => ['ativa' => true, 'sku' => 'FUR-2', 'estoque' => 2],
        ], $this->variantes($pub));
        $this->assertSame([], array_values(array_filter($resumo['avisos'], fn ($a) => str_contains($a, 'outro tipo') || str_contains($a, 'repetida'))));
    }

    public function test_sincronizar_de_novo_nao_muda_nada(): void
    {
        [, $pub] = $this->produto([['Preto', '127V', 4], ['Preto', '220V', 2], ['Amarelo', '127V', 7]]);
        $this->servico->preencher($pub);
        $revisao = $this->rascunho($pub)->revisao;
        $antes = [DB::table('pub_variantes')->count(), DB::table('pub_eixo_valores')->count(), $this->variantes($pub)];

        $this->servico->preencher($pub);

        $this->assertSame($antes, [DB::table('pub_variantes')->count(), DB::table('pub_eixo_valores')->count(), $this->variantes($pub)]);
        $this->assertSame($revisao, $this->rascunho($pub)->revisao, 'nada mudou: a revisão não sobe');
    }

    public function test_combinacao_que_o_portal_desativou_volta_quando_o_cliente_a_cria(): void
    {
        [$p, $pub] = $this->produto([['Preto', '127V', 4], ['Preto', '220V', 2], ['Amarelo', '127V', 7]]);
        $this->servico->preencher($pub);
        $this->assertFalse($this->variantes($pub)['Amarelo|220V']['ativa']);

        $nova = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresa->id, 'ordem' => 9,
            'codigo' => 'FUR-9', 'eixo' => 'cor', 'valor' => 'Amarelo', 'eixo_2' => 'voltagem', 'valor_2' => '220V', 'estoque' => 3]);
        EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $nova->id, 'sku' => 'FUR-9', 'fase' => 'simples', 'nome' => 'Furadeira — Amarelo · 220V']);
        $this->servico->preencher($pub);

        $this->assertSame(['ativa' => true, 'sku' => 'FUR-9', 'estoque' => 3], $this->variantes($pub)['Amarelo|220V']);
    }

    public function test_combinacao_desativada_pela_equipe_nao_e_reativada(): void
    {
        [, $pub] = $this->produto([['Preto', '127V', 4], ['Preto', '220V', 2], ['Amarelo', '127V', 7]]);
        $this->servico->preencher($pub);
        $r = $this->rascunho($pub);
        $chave = collect($this->repo->snapshot($r)->variantes)->first(fn ($v) => collect($v->valores)->contains(fn ($x) => $x->valueName === '220V')
            && collect($v->valores)->contains(fn ($x) => $x->valueName === 'Preto'))->chave;
        app(EditorRascunhoService::class)->salvarVariantes($r, [$chave => ['ativa' => false]]);

        $this->servico->preencher($pub);

        $this->assertFalse($this->variantes($pub)['Preto|220V']['ativa']);
    }

    public function test_as_fotos_sao_da_cor_e_o_segundo_tamanho_usa_as_do_primeiro(): void
    {
        // COLOR define a foto na furadeira: um grupo por cor. A 2ª voltagem do Preto não tem foto no Portal.
        [, $pub, [$preto127, , $amarelo]] = $this->produto([['Preto', '127V', 4], ['Preto', '220V', 2], ['Amarelo', '127V', 7]]);
        $fotoPreto = $this->foto($preto127, 0);
        $fotoAmarelo = $this->foto($amarelo, 0);

        $resumo = $this->servico->preencher($pub);

        $r = $this->rascunho($pub);
        $s = $this->repo->snapshot($r);
        $eixos = Eixo::ordenar($s->eixos);
        $sha = $r->imagens()->pluck('sha256', 'id')->all();
        $porVariante = [];
        foreach ($s->variantes as $v) {
            $grupo = ResolvedorGruposImagem::chaveDoGrupo($v, $eixos, $s->fotosPorVariante);
            $porVariante[implode('|', array_map(fn (Eixo $e) => $v->valores[$e->chave]->valueName, $eixos))] =
                array_values(array_map(fn ($a) => $sha[(int) $a['imagem']], array_filter($s->imagens, fn ($a) => $a['grupo'] === $grupo)));
        }
        $this->assertSame([$fotoPreto], $porVariante['Preto|127V']);
        $this->assertSame([$fotoPreto], $porVariante['Preto|220V'], 'mesma cor, mesmas fotos');
        $this->assertSame([$fotoAmarelo], $porVariante['Amarelo|127V']);
        $this->assertSame(2, $resumo['fotos_trazidas'], 'uma vez por cor');
    }

    public function test_cor_e_tamanho_na_camiseta(): void
    {
        [, $pub] = $this->produto([['Branco', 'P', 1], ['Branco', 'M', 2], ['Azul', 'M', 3]], self::CAMISETA, 'tamanho');

        $this->servico->preencher($pub);

        $this->assertSame(['COLOR', 'SIZE'], array_map(fn (Eixo $e) => $e->chave, Eixo::ordenar($this->snap($pub)->eixos)));
        $ativas = array_keys(array_filter($this->variantes($pub), fn ($v) => $v['ativa']));
        $this->assertSame(['Azul|M', 'Branco|M', 'Branco|P'], $ativas);
    }

    public function test_categoria_sem_nenhum_dos_dois_eixos_junta_os_valores_numa_variacao_so(): void
    {
        // O anúncio aceita uma variação própria só: sem Cor nem Tamanho na categoria, "Preto · P" num eixo só.
        [, $pub] = $this->produto([['Preto', 'P', 1], ['Preto', 'G', 2]], self::PASTILHA, 'tamanho');

        $resumo = $this->servico->preencher($pub);

        $s = $this->snap($pub);
        $this->assertCount(1, $s->eixos);
        $this->assertSame(ChaveCanonica::EIXO_CUSTOM, $s->eixos[0]->chave);
        $this->assertSame(['Preto · G', 'Preto · P'], collect($s->eixos[0]->valores)->map(fn ($x) => $x->valueName)->sort()->values()->all());
        $this->assertNotEmpty(array_filter($resumo['avisos'], fn ($a) => str_contains($a, 'entraram juntos')));
    }
}
