<?php

namespace Tests\Feature\Publicador;

use App\Models\MlbEmpresa;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\CapaDoKitService;
use App\Services\Publicador\CriarFaseService;
use App\Services\Publicador\ImagemAssetService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\RegeneradorVariantes;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 175 Plano 175-02 Task 3 (§5 da ETAPA-3) — o clone de um rascunho CHEIO
 * (2 eixos, 2 variantes, 2 alvos, 3 fotos) e, principalmente, as FOTOS.
 *
 * ⚠️ Este arquivo é o guarda-corpo da fase. A §5 ao pé da letra diz "imagens e
 * atribuições (mesmo arquivo, sem reupload)" — e "mesmo arquivo" APAGA A FOTO DO
 * PRODUTO BASE: `ImagemAssetService::remover()` faz `Storage::delete($imagem->caminho)`
 * e só depois `$imagem->delete()`, e o caminho é namespaced por rascunho
 * (`publicador/{rascunho_id}/{sha}.ext`). Com o caminho compartilhado, "tirar uma
 * foto do kit" apagaria o arquivo debaixo do base, que ficaria com linha em
 * `pub_imagens` apontando para arquivo inexistente (T-175-04).
 *
 * `test_remover_foto_do_kit_nao_apaga_o_arquivo_do_base` prova, por execução, que
 * o caminho de remoção do editor do base continua seguro depois do clone. NÃO
 * enfraquecer esse teste.
 *
 * O "sem reupload" que a §5 queria continua valendo por outro meio: `ml_picture_id`,
 * `ml_url` e `upload_status` são PRESERVADOS (é a mesma conta do ML, a foto já está
 * lá), e `Http::assertNothingSent()` prova que o clone não fala com o ML.
 *
 * Pegadinhas do `learnings/publicador-ml.md` §8 honradas aqui: o 2º argumento de
 * `Storage::assertExists()` é o CONTEÚDO esperado, não uma mensagem; e
 * `UploadedFile::fake()->image(...)` precisa ficar numa variável antes de ser lido.
 *
 * @group phase175
 */
class CriarFaseCloneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Nenhuma chamada ao Mercado Livre pode sair do clone (T-175-06): o fake
        // sem handler intercepta tudo, e `assertNothingSent` cobra o contrário.
        Http::fake();
    }

    // ═══ O clone cheio ═══════════════════════════════════════════════════════

    public function test_clone_de_base_com_dois_eixos_duas_variantes_dois_alvos_e_tres_fotos(): void
    {
        [$base, $fotos] = $this->baseCheio();

        $kit = $this->servico()->criar($base, $this->dados(2));

        $rk = $kit->rascunho;
        $this->assertSame(2, $rk->eixos()->count());
        $this->assertSame(3, DB::table('pub_eixo_valores')
            ->whereIn('eixo_id', $rk->eixos()->pluck('id'))->count(), 'Preto, Azul e P');
        $this->assertSame(2, $rk->variantes()->count());
        $this->assertSame(2, $rk->alvos()->count());
        $this->assertSame(3, $rk->imagens()->count());
        $this->assertSame(4, DB::table('pub_variante_eixo_valores')
            ->whereIn('variante_id', $rk->variantes()->pluck('id'))->count(), '2 variantes × 2 eixos');

        // E o base ficou exatamente como estava.
        $rb = $base->rascunho->fresh();
        $this->assertSame(3, $rb->imagens()->count());
        $this->assertSame(count($fotos), $rb->imagens()->count());
        Http::assertNothingSent();
    }

    // ═══ As fotos ════════════════════════════════════════════════════════════

    public function test_foto_do_kit_tem_caminho_proprio_com_os_mesmos_bytes_e_os_ids_do_ml(): void
    {
        [$base, $fotos] = $this->baseCheio();
        $doBase = $base->rascunho->imagens()->orderBy('id')->get();

        $kit = $this->servico()->criar($base, $this->dados(2));
        $doKit = $kit->rascunho->imagens()->orderBy('id')->get()->keyBy('sha256');

        foreach ($doBase as $i => $original) {
            $copia = $doKit[$original->sha256] ?? null;
            $this->assertNotNull($copia, "a foto {$original->sha256} não foi copiada");

            // Caminho PRÓPRIO, namespaced pelo rascunho do kit — e o mesmo sha.
            $this->assertSame("publicador/{$kit->rascunho->id}/{$original->sha256}.jpg", $copia->caminho);
            $this->assertNotSame($original->caminho, $copia->caminho);
            $this->assertSame($original->sha256, $copia->sha256);

            // O arquivo existe e tem os MESMOS bytes (2º argumento = conteúdo esperado).
            Storage::disk('local')->assertExists($copia->caminho, $fotos[$i]);
            $this->assertSame(
                Storage::disk('local')->get($original->caminho),
                Storage::disk('local')->get($copia->caminho),
                'bytes iguais, arquivos diferentes',
            );

            // Mesma conta do ML ⇒ sem reupload: os ids vêm preservados.
            $this->assertSame($original->ml_picture_id, $copia->ml_picture_id);
            $this->assertSame($original->ml_url, $copia->ml_url);
            $this->assertSame($original->upload_status, $copia->upload_status);
            $this->assertSame(PubImagem::ENVIADA, $copia->upload_status);
            $this->assertSame([$original->mime, $original->bytes, $original->largura, $original->altura],
                [$copia->mime, $copia->bytes, $copia->largura, $copia->altura]);
        }

        Http::assertNothingSent();
    }

    /**
     * ⚠️ O GUARDA-CORPO desta fase: a §5 ao pé da letra ("mesmo arquivo") apagaria
     * a foto do produto base quando o operador tirasse a foto do kit.
     */
    public function test_remover_foto_do_kit_nao_apaga_o_arquivo_do_base(): void
    {
        [$base, $fotos] = $this->baseCheio();
        $kit = $this->servico()->criar($base, $this->dados(2));

        $doBase = $base->rascunho->imagens()->orderBy('id')->first();
        $doKit = $kit->rascunho->imagens()->where('sha256', $doBase->sha256)->firstOrFail();

        // O editor do kit tira a foto — exatamente como a tela faz hoje.
        app(ImagemAssetService::class)->remover($doKit);

        Storage::disk('local')->assertMissing($doKit->caminho);
        $this->assertSame(0, PubImagem::whereKey($doKit->id)->count());

        // E o base continua inteiro: linha, arquivo e bytes.
        $this->assertSame(1, PubImagem::whereKey($doBase->id)->count(), 'a linha do base ficou');
        Storage::disk('local')->assertExists($doBase->caminho, $fotos[0]);
        $this->assertSame(3, $base->rascunho->fresh()->imagens()->count());
        $this->assertSame(2, $kit->rascunho->fresh()->imagens()->count());
    }

    public function test_atribuicoes_do_kit_apontam_para_as_imagens_novas_com_o_mesmo_grupo(): void
    {
        [$base] = $this->baseCheio();

        $kit = $this->servico()->criar($base, $this->dados(2));

        $idsDoKit = $kit->rascunho->imagens()->pluck('id')->all();
        $idsDoBase = $base->rascunho->imagens()->pluck('id')->all();
        $doKit = DB::table('pub_imagem_atribuicoes')->whereIn('imagem_id', $idsDoKit)
            ->orderBy('grupo_chave')->orderBy('posicao')->get();
        $doBase = DB::table('pub_imagem_atribuicoes')->whereIn('imagem_id', $idsDoBase)
            ->orderBy('grupo_chave')->orderBy('posicao')->get();

        $this->assertCount($doBase->count(), $doKit);
        $this->assertSame(
            $doBase->map(fn ($a) => [$a->grupo_chave, $a->grupo_hash, $a->posicao])->all(),
            $doKit->map(fn ($a) => [$a->grupo_chave, $a->grupo_hash, $a->posicao])->all(),
            'mesmo grupo, mesmo hash, mesma ordem',
        );
        $this->assertSame([], array_intersect($doKit->pluck('imagem_id')->all(), $idsDoBase),
            'nenhuma atribuição do kit aponta para imagem do BASE',
        );
    }

    /** H-22: foto que veio do Anunciar antigo tem só o id do ML, sem arquivo. */
    public function test_foto_sem_arquivo_e_copiada_so_com_os_ids_do_ml_sem_lancar(): void
    {
        [$base] = $this->baseCheio();
        $rb = $base->rascunho;
        $legada = $rb->imagens()->create([
            'caminho' => null, 'sha256' => null, 'mime' => null, 'bytes' => null,
            'largura' => null, 'altura' => null, 'ml_picture_id' => '999-MLB_ANTIGO',
            'ml_url' => 'https://http2.mlstatic.com/D_999-O.jpg', 'upload_status' => PubImagem::ENVIADA,
        ]);
        $legada->atribuicoes()->create(['grupo_chave' => R::GERAL, 'grupo_hash' => ChaveCanonica::hash(R::GERAL), 'posicao' => 9]);

        $kit = $this->servico()->criar($base, $this->dados(2));

        $copia = $kit->rascunho->imagens()->where('ml_picture_id', '999-MLB_ANTIGO')->firstOrFail();
        $this->assertNull($copia->caminho, 'não há arquivo para copiar');
        $this->assertNull($copia->sha256);
        $this->assertSame('https://http2.mlstatic.com/D_999-O.jpg', $copia->ml_url);
        $this->assertSame(PubImagem::ENVIADA, $copia->upload_status);
        $this->assertSame(9, $copia->atribuicoes()->value('posicao'));
        $this->assertSame(4, $kit->rascunho->imagens()->count());
        Http::assertNothingSent();
    }

    /**
     * T-175-08: o banco volta sozinho num rollback; o disco não. Os arquivos que o
     * clone escreveu são apagados depois da transação que caiu.
     */
    public function test_falha_depois_das_fotos_nao_deixa_arquivo_orfao_no_disco(): void
    {
        [$base] = $this->baseCheio();
        $antes = Storage::disk('local')->allFiles();

        $servico = new class(new RascunhoRepository(), app(CapaDoKitService::class)) extends CriarFaseService
        {
            protected function copiarImagens(PubRascunho $base, PubRascunho $kit, array &$escritos): void
            {
                parent::copiarImagens($base, $kit, $escritos);
                throw new \RuntimeException('falha simulada depois de gravar as fotos');
            }
        };

        try {
            $servico->criar($base, $this->dados(2));
            $this->fail('a falha simulada devia ter subido');
        } catch (\RuntimeException $e) {
            $this->assertSame('falha simulada depois de gravar as fotos', $e->getMessage());
        }

        $this->assertSame($antes, Storage::disk('local')->allFiles(), 'nenhum arquivo do kit sobrou no disco');
        $this->assertSame(1, PubProduto::count(), 'e nada no banco: só o base');
    }

    // ═══ Fixtures ════════════════════════════════════════════════════════════

    private function servico(): CriarFaseService
    {
        // A capa (175-06) vem do container: nenhum teste deste arquivo a pede,
        // e sem `capa` em $dados ela nem é consultada.
        return new CriarFaseService(new RascunhoRepository(), app(CapaDoKitService::class));
    }

    private function dados(int $quantidade): array
    {
        return [
            'quantidade' => $quantidade,
            'sku' => 'CAD-01-KIT'.$quantidade,
            'seller_skus' => [
                'COLOR=id:52049|SIZE=id:P' => 'CAD-01-PRETO-P-KIT'.$quantidade,
                'COLOR=id:52028|SIZE=id:P' => 'CAD-01-AZUL-P-KIT'.$quantidade,
            ],
            'titulo_por_tipo' => ['gold_special' => "Kit {$quantidade} Cadeira Executiva ECF", 'gold_pro' => null],
            'descricao' => "Este kit contém {$quantidade} unidades de Cadeira.",
            'estoque_por_variante' => [
                'COLOR=id:52049|SIZE=id:P' => ['estoque' => 3, 'depositos' => null],
                'COLOR=id:52028|SIZE=id:P' => ['estoque' => 2, 'depositos' => ['SP' => 2]],
            ],
            'ator' => ['equipe' => true, 'id' => 7],
        ];
    }

    /**
     * Um base cheio: 2 eixos (Cor com dois valores, Tamanho com um), 2 variantes,
     * 2 alvos e 3 fotos com bytes de verdade no disco e ids do ML.
     *
     * As fotos são semeadas DIRETO (linha + `put()`), nunca por
     * `ImagemAssetService::receber()`: `receber()` termina chamando `enviarAoMl()`,
     * e aqui a prova é que NENHUMA chamada ao ML sai do clone.
     *
     * @return array{0: PubProduto, 1: list<string>} o produto base e os bytes das 3 fotos
     */
    private function baseCheio(): array
    {
        $empresa = MlbEmpresa::create(['nome' => 'Polo das Fases', 'projeto' => 'POLOS']);
        $base = PubProduto::create(['mlb_empresa_id' => $empresa->id, 'sku' => 'CAD-01', 'nome' => 'Cadeira',
            'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        $repo = new RascunhoRepository();
        $r = $repo->criar($base, [new Alvo('gold_special', 'Cadeira Executiva ECF'), new Alvo('gold_pro', null)]);
        $r->update(['categoria_id' => 'MLB1234', 'dominio_id' => 'MLB-CHAIRS', 'schema_hash' => str_repeat('b', 64),
            'descricao' => 'Cadeira de uma unidade.', 'fotos_por_variante' => true, 'incluir_geral_nas_variantes' => true]);

        $eixos = [
            new Eixo('COLOR', 'Cor', 0, true, [new ValorEixo('52049', 'Preto'), new ValorEixo('52028', 'Azul')]),
            new Eixo('SIZE', 'Tamanho', 1, false, [new ValorEixo('P', 'P')]),
        ];
        $regen = RegeneradorVariantes::regenerar($repo->snapshot($r)->variantes, $eixos);
        $variantes = array_map(fn (Variante $v) => $v->comDados([
            'estoque' => 7,
            'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD-01-'.mb_strtoupper($v->valores['COLOR']->valueName).'-P']],
        ]), $regen->variantes);
        $repo->gravarVariacao($r, $eixos, $variantes);

        // 3 fotos: duas na galeria geral, uma no grupo da cor Preto.
        $grupos = [R::GERAL, R::GERAL, 'COLOR=id:52049'];
        $bytes = [];
        foreach ($grupos as $i => $grupo) {
            // Pegadinha §8: guardar o UploadedFile numa variável antes de ler.
            $arquivo = UploadedFile::fake()->image("foto{$i}.jpg", 1200, 1200 + $i);
            $conteudo = $arquivo->get();
            $bytes[] = $conteudo;

            $sha = hash('sha256', $conteudo);
            Storage::disk('local')->put("publicador/{$r->id}/{$sha}.jpg", $conteudo);
            $imagem = $r->imagens()->create([
                'caminho' => "publicador/{$r->id}/{$sha}.jpg", 'sha256' => $sha, 'mime' => 'image/jpeg',
                'bytes' => strlen($conteudo), 'largura' => 1200, 'altura' => 1200 + $i,
                'ml_picture_id' => "123-MLB{$i}_102026", 'ml_url' => "https://http2.mlstatic.com/D_{$i}-O.jpg",
                'upload_status' => PubImagem::ENVIADA,
            ]);
            $imagem->atribuicoes()->create(['grupo_chave' => $grupo, 'grupo_hash' => ChaveCanonica::hash($grupo), 'posicao' => $i]);
        }

        return [$base->fresh(), $bytes];
    }
}
