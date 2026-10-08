<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\EstruturaProdutoVolume;
use App\Models\MlCategoriaSchema;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PortalParaRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Variacao\Eixo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * Fase 172-10 — as fotos de cada cor do Portal chegam ao grupo de fotos da variação do rascunho:
 * só em grupo vazio, WebP vira JPG, o que não entra é contado com o motivo e NADA sobe ao ML.
 */
class PortalFotosTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    private Company $empresa;

    private RascunhoRepository $repo;

    private EditorRascunhoService $editor;

    private PortalParaRascunhoService $servico;

    private int $semente = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake();

        $schema = self::schema(self::CADEIRA);
        MlCategoriaSchema::create(['category_id' => self::CADEIRA, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
            'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
            'schema_hash' => $schema->hash(), 'fetched_at' => now()]);

        $this->empresa = Company::factory()->create();
        // Conta liberada: mesmo assim o Sincronizar não pode subir foto (D-10).
        config(['publicador.contas_liberadas' => ['companies' => [$this->empresa->id], 'mlb_empresas' => []]]);
        $this->repo = new RascunhoRepository();
        $this->editor = app(EditorRascunhoService::class);
        $this->servico = app(PortalParaRascunhoService::class);
    }

    // ═══ Ajudantes ═══════════════════════════════════════════════════════════

    /** Imagem distinta a cada chamada (cores diferentes = sha diferente). */
    private function bytes(string $formato = 'jpg', int $lado = 800): string
    {
        $this->semente += 37;
        $im = imagecreatetruecolor($lado, $lado);
        imagefill($im, 0, 0, imagecolorallocate($im, $this->semente % 256, ($this->semente * 3) % 256, ($this->semente * 7) % 256));
        ob_start();
        match ($formato) {
            'webp' => imagewebp($im, null, 80),
            'png' => imagepng($im),
            default => imagejpeg($im, null, 90),
        };

        return (string) ob_get_clean();
    }

    /** Grava a foto no disco do Portal e a linha da variação. */
    private function foto(EstruturaProdutoVariacao $v, int $ordem, ?string $conteudo = null, string $mime = 'image/jpeg', bool $semArquivo = false): void
    {
        $caminho = "estrutura/{$this->empresa->id}/produtos/{$v->produto_id}/variacoes/{$v->id}/foto{$ordem}.".($mime === 'image/webp' ? 'webp' : 'jpg');
        if (! $semArquivo) {
            Storage::disk('local')->put($caminho, $conteudo ?? $this->bytes());
        }
        EstruturaProdutoVariacaoImagem::create(['company_id' => $this->empresa->id, 'produto_id' => $v->produto_id, 'variacao_id' => $v->id,
            'caminho' => $caminho, 'nome_original' => "foto{$ordem}", 'mime' => $mime, 'tamanho' => 1000, 'largura' => 800, 'altura' => 800, 'ordem' => $ordem]);
    }

    /** @return array{0: EstruturaProduto, 1: PubProduto, 2: list<EstruturaProdutoVariacao>} */
    private function produto(array $cores = ['Azul', 'Preto']): array
    {
        $p = EstruturaProduto::create(['company_id' => $this->empresa->id, 'codigo' => 'MESA', 'nome' => 'Mesa',
            'categoria_ml_id' => self::CADEIRA, 'categoria_ml_nome' => 'Categoria']);
        $vars = [];
        foreach ($cores as $i => $nome) {
            $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresa->id, 'ordem' => $i,
                'codigo' => 'MESA-'.($i + 1), 'eixo' => 'cor', 'valor' => $nome, 'custo' => 10, 'estoque' => 5]);
            EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 50, 'largura' => 40, 'altura' => 30, 'peso' => 2.5]);
            EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $v->id, 'sku' => 'MESA-'.($i + 1), 'fase' => 'simples', 'nome' => "Mesa {$nome}"]);
            $vars[] = $v;
        }
        $pub = PubProduto::create(['company_id' => $this->empresa->id, 'estrutura_produto_id' => $p->id, 'sku' => 'MESA', 'nome' => 'Mesa', 'origem' => PubProduto::ORIGEM_PORTAL]);

        return [$p, $pub, $vars];
    }

    private function rascunho(PubProduto $pub): PubRascunho
    {
        return PubRascunho::where('produto_id', $pub->id)->firstOrFail();
    }

    private function snap(PubProduto $pub): RascunhoSnapshot
    {
        return $this->repo->snapshot($this->rascunho($pub));
    }

    /**
     * Cor → sha256 das fotos do grupo dela, na ordem da posição.
     *
     * @return array<string, list<string>>
     */
    private function fotosPorCor(PubProduto $pub): array
    {
        $r = $this->rascunho($pub);
        $s = $this->repo->snapshot($r);
        $eixos = Eixo::ordenar($s->eixos);
        $shaPorId = $r->imagens()->pluck('sha256', 'id')->all();
        $saida = [];
        foreach ($s->variantes as $v) {
            $cor = collect($v->valores)->first()?->valueName ?? 'unica';
            $grupo = ResolvedorGruposImagem::chaveDoGrupo($v, $eixos, $s->fotosPorVariante);
            $lista = array_filter($s->imagens, fn ($a) => $a['grupo'] === $grupo);
            usort($lista, fn ($a, $b) => $a['posicao'] <=> $b['posicao']);
            $saida[$cor] = array_map(fn ($a) => $shaPorId[(int) $a['imagem']], $lista);
        }

        return $saida;
    }

    private function contagens(): array
    {
        return ['imagens' => DB::table('pub_imagens')->count(), 'atribuicoes' => DB::table('pub_imagem_atribuicoes')->count()];
    }

    // ═══ Testes ══════════════════════════════════════════════════════════════

    public function test_cada_cor_leva_as_fotos_dela_na_ordem_do_portal(): void
    {
        [, $pub, [$azul, $preto]] = $this->produto();
        $a1 = $this->bytes();
        $a2 = $this->bytes();
        $p1 = $this->bytes();
        $p2 = $this->bytes();
        // Gravadas fora de ordem: a coluna `ordem` é quem manda.
        $this->foto($azul, 1, $a2);
        $this->foto($azul, 0, $a1);
        $this->foto($preto, 0, $p1);
        $this->foto($preto, 1, $p2);

        $resumo = $this->servico->preencher($pub);

        $porCor = $this->fotosPorCor($pub);
        $this->assertSame([hash('sha256', $a1), hash('sha256', $a2)], $porCor['Azul']);
        $this->assertSame([hash('sha256', $p1), hash('sha256', $p2)], $porCor['Preto']);
        $this->assertSame(4, $resumo['fotos_trazidas']);
        $this->assertSame([], $resumo['fotos_nao_trazidas']);
        $this->assertSame(4, PubImagem::where('rascunho_id', $this->rascunho($pub)->id)->where('upload_status', PubImagem::PENDENTE)->count());
    }

    public function test_sem_eixo_que_define_foto_liga_fotos_por_variante_antes_de_atribuir(): void
    {
        [, $pub, [$azul, $preto]] = $this->produto();
        $this->foto($azul, 0);
        $this->foto($preto, 0);

        $this->servico->preencher($pub);

        $s = $this->snap($pub);
        $definemFoto = array_filter($s->eixos, fn (Eixo $e) => $e->definesPicture);
        // Se a categoria não define foto por cor, a tela liga "fotos por variante"; as duas cores ficam com grupos distintos.
        $this->assertTrue($definemFoto !== [] || $s->fotosPorVariante);
        $porCor = $this->fotosPorCor($pub);
        $this->assertCount(1, $porCor['Azul']);
        $this->assertCount(1, $porCor['Preto']);
        $this->assertNotSame($porCor['Azul'], $porCor['Preto']);
    }

    public function test_grupo_que_ja_tem_foto_da_equipe_nao_recebe_do_portal(): void
    {
        [, $pub, [$azul, $preto]] = $this->produto();
        $this->servico->preencher($pub); // cria o rascunho e as variantes, sem fotos no Portal ainda
        $this->foto($azul, 0);
        $this->foto($preto, 0);

        // A equipe põe uma foto na cor Azul.
        $r = $this->rascunho($pub);
        $this->editor->salvar($r, ['fotos_por_variante' => true]);
        $s = $this->repo->snapshot($r);
        $varAzul = collect($s->variantes)->first(fn ($v) => (collect($v->valores)->first()?->valueName) === 'Azul');
        $grupoAzul = ResolvedorGruposImagem::chaveDoGrupo($varAzul, Eixo::ordenar($s->eixos), $s->fotosPorVariante);
        $da = app(\App\Services\Publicador\ImagemAssetService::class)->receber($r, $this->bytes(), 'equipe.jpg', enviar: false);
        $this->editor->colocarFotoNoGrupo($r, $da['imagem'], $grupoAzul);

        $resumo = $this->servico->preencher($pub);

        $porCor = $this->fotosPorCor($pub);
        $this->assertSame([$da['imagem']->sha256], $porCor['Azul'], 'a foto da equipe fica sozinha');
        $this->assertCount(1, $porCor['Preto'], 'o grupo vazio recebe');
        $this->assertSame(1, $resumo['fotos_trazidas']);
    }

    public function test_rodar_de_novo_nao_duplica_foto(): void
    {
        [, $pub, [$azul, $preto]] = $this->produto();
        $this->foto($azul, 0);
        $this->foto($azul, 1);
        $this->foto($preto, 0);

        $this->servico->preencher($pub);
        $antes = $this->contagens();
        $revisao = $this->rascunho($pub)->revisao ?? null;

        $resumo = $this->servico->preencher($pub);

        $this->assertSame($antes, $this->contagens());
        $this->assertSame(0, $resumo['fotos_trazidas']);
        $this->assertSame($revisao, $this->rascunho($pub)->revisao ?? null);
    }

    public function test_webp_chega_convertida_em_jpg(): void
    {
        [, $pub, [$azul]] = $this->produto(['Azul']);
        $this->foto($azul, 0, $this->bytes('webp'), 'image/webp');

        $resumo = $this->servico->preencher($pub);

        $this->assertSame(1, $resumo['fotos_trazidas']);
        $imagem = PubImagem::where('rascunho_id', $this->rascunho($pub)->id)->firstOrFail();
        $this->assertSame('image/jpeg', $imagem->mime);
        Storage::disk('local')->assertExists($imagem->caminho);
    }

    public function test_foto_pequena_demais_e_contada_com_o_motivo(): void
    {
        [, $pub, [$azul]] = $this->produto(['Azul']);
        $this->foto($azul, 0, $this->bytes('jpg', 300));
        $this->foto($azul, 1);

        $resumo = $this->servico->preencher($pub);

        $this->assertSame(1, $resumo['fotos_trazidas']);
        $this->assertSame(['pequena' => 1], $resumo['fotos_nao_trazidas']);
    }

    public function test_arquivo_sumido_e_contado_com_o_motivo(): void
    {
        [, $pub, [$azul]] = $this->produto(['Azul']);
        $this->foto($azul, 0, null, 'image/jpeg', true);
        $this->foto($azul, 1);

        $resumo = $this->servico->preencher($pub);

        $this->assertSame(1, $resumo['fotos_trazidas']);
        $this->assertSame(['arquivo_sumido' => 1], $resumo['fotos_nao_trazidas']);
    }

    public function test_arquivo_que_nao_e_imagem_e_contado_como_formato(): void
    {
        [, $pub, [$azul]] = $this->produto(['Azul']);
        $this->foto($azul, 0, 'isto nao e uma imagem');

        $resumo = $this->servico->preencher($pub);

        $this->assertSame(0, $resumo['fotos_trazidas']);
        $this->assertSame(['formato' => 1], $resumo['fotos_nao_trazidas']);
    }

    public function test_excedente_do_limite_do_schema_e_contado_nunca_cortado_em_silencio(): void
    {
        [, $pub, [$azul]] = $this->produto(['Azul']);
        for ($i = 0; $i < 3; $i++) {
            $this->foto($azul, $i);
        }
        $schema = MlCategoriaSchema::where('category_id', self::CADEIRA)->firstOrFail();
        // O limite sai do schema guardado: força 2 fotos por anúncio.
        $this->limitarFotosEm($schema, 2);

        $resumo = $this->servico->preencher($pub);

        $this->assertSame(2, $resumo['fotos_trazidas']);
        $this->assertSame(['acima_do_limite' => 1], $resumo['fotos_nao_trazidas']);
    }

    public function test_nada_sobe_ao_ml_nem_com_a_conta_liberada(): void
    {
        [, $pub, [$azul, $preto]] = $this->produto();
        $this->foto($azul, 0);
        $this->foto($preto, 0);

        $this->servico->preencher($pub);

        Http::assertNothingSent();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/pictures'));
        $this->assertSame(0, PubImagem::where('upload_status', PubImagem::ENVIADA)->count());
    }

    public function test_foto_de_outra_empresa_nunca_e_lida(): void
    {
        [, $pub, [$azul]] = $this->produto(['Azul']);
        $outra = Company::factory()->create();
        $alheio = "estrutura/{$outra->id}/produtos/1/variacoes/1/segredo.jpg";
        Storage::disk('local')->put($alheio, $this->bytes());
        EstruturaProdutoVariacaoImagem::create(['company_id' => $this->empresa->id, 'produto_id' => $azul->produto_id, 'variacao_id' => $azul->id,
            'caminho' => $alheio, 'nome_original' => 'segredo', 'mime' => 'image/jpeg', 'tamanho' => 1000, 'largura' => 800, 'altura' => 800, 'ordem' => 0]);

        $resumo = $this->servico->preencher($pub);

        $this->assertSame(0, $resumo['fotos_trazidas']);
        $this->assertSame(['arquivo_sumido' => 1], $resumo['fotos_nao_trazidas']);
    }

    /** Ajusta `max_pictures_per_item` no schema guardado (o resto fica igual). */
    private function limitarFotosEm(MlCategoriaSchema $schema, int $max): void
    {
        $categoria = $schema->categoria;
        $categoria['settings']['max_pictures_per_item'] = $max;
        $categoria['settings']['max_pictures_per_item_var'] = $max;
        $schema->update(['categoria' => $categoria]);
    }

    public function test_publicacao_que_comeca_durante_a_copia_impede_a_atribuicao_da_foto(): void
    {
        [, $pub, [$azul, $preto]] = $this->produto();
        $this->foto($azul, 0);
        $this->foto($preto, 0);
        // A publicação começa entre a checagem do "intocável" e a escrita (a foto já foi guardada).
        PubImagem::created(fn (PubImagem $i) => PubRascunho::whereKey($i->rascunho_id)->update(['status' => PubRascunho::PUBLISHING]));

        $resumo = $this->servico->preencher($pub);

        $this->assertTrue($resumo['intocavel']);
        $this->assertSame(0, DB::table('pub_imagem_atribuicoes')->count(), 'nada é atribuído a um rascunho em publicação');
        $this->assertSame(0, $resumo['fotos_trazidas']);
    }

    public function test_foto_com_resolucao_gigante_nao_e_decodificada_e_entra_no_resumo(): void
    {
        [, $pub, [$azul]] = $this->produto(['Azul']);
        $tres = fn (int $n) => substr(pack('V', $n), 0, 3);
        $chunk = 'VP8X'.pack('V', 10).pack('V', 0).$tres(16382).$tres(16382);
        $this->foto($azul, 0, 'RIFF'.pack('V', 4 + strlen($chunk)).'WEBP'.$chunk, 'image/webp');

        $resumo = $this->servico->preencher($pub);

        $this->assertSame(['dimensao_grande' => 1], $resumo['fotos_nao_trazidas']);
        $this->assertSame(0, DB::table('pub_imagens')->count());
    }
}
