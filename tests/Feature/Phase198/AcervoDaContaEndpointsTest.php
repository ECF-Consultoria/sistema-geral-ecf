<?php

namespace Tests\Feature\Phase198;

use App\Models\AcervoImagem;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\ImagemAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 198 (v28.0) — o acervo da conta por ATIVO: listagem com filtros e páginas, miniatura,
 * original e "usar" sem cópia (ACV-01..06, ACV-08).
 *
 * Cenário mínimo da Fase 171: produto ancorado só em `company_id` (programa 'gestao' resolvido
 * sozinho) e sem `MlToken` — nada chega ao Mercado Livre (`Http::preventStrayRequests()`).
 */
class AcervoDaContaEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('r2');
        config(['filesystems.imagens' => 'r2']);
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function rascunho(Company $company, string $sku, ?string $nome = null): PubRascunho
    {
        $p = PubProduto::create(['company_id' => $company->id, 'sku' => $sku, 'nome' => $nome ?? "Produto {$sku}", 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        return PubRascunho::create(['produto_id' => $p->id]);
    }

    private static function jpeg(int $lado = 800, int $cor = 200): string
    {
        $img = imagecreatetruecolor($lado, $lado);
        imagefill($img, 0, 0, imagecolorallocate($img, $cor, $cor, $cor));
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function foto(PubRascunho $r, int $cor = 200, array $origem = []): PubImagem
    {
        return app(ImagemAssetService::class)->receber($r, self::jpeg(800, $cor), "foto-{$cor}.jpg", origem: $origem)['imagem'];
    }

    private function rota(string $nome, PubRascunho $r, array $extra = []): string
    {
        return route("mlb.anuncios.publicador.acervo.ativos.{$nome}", ['produto' => $r->produto_id, ...$extra]);
    }

    /** ACV-01/06: uploads e IA aprovada da conta, com produto, SKU, origem e uso. */
    public function test_lista_os_ativos_da_conta_com_metadados(): void
    {
        $empresa = Company::factory()->create();
        $a = $this->rascunho($empresa, 'CAD-01', 'Cadeira azul');
        $b = $this->rascunho($empresa, 'MESA-01', 'Mesa');
        $this->foto($a, 10);
        $this->foto($b, 20, ['origem' => AcervoImagem::ORIGEM_IA, 'modelo_ia' => 'gemini']);

        $res = $this->actingAs($this->admin)->getJson($this->rota('listar', $a))->assertOk()->json();

        $this->assertSame(2, $res['total']);
        $porSku = collect($res['itens'])->keyBy('sku');
        $this->assertSame(['upload', 'Cadeira azul', true, 1, true], [$porSku['CAD-01']['origem'], $porSku['CAD-01']['produto_nome'],
            $porSku['CAD-01']['do_produto_atual'], $porSku['CAD-01']['em_uso'], $porSku['CAD-01']['no_rascunho_atual']]);
        $this->assertSame(['ia', false, false], [$porSku['MESA-01']['origem'], $porSku['MESA-01']['do_produto_atual'], $porSku['MESA-01']['no_rascunho_atual']]);
    }

    /** O acervo vale mesmo com o Creative Engine desligado — upload manual também é acervo. */
    public function test_funciona_com_o_creative_engine_desligado(): void
    {
        Configuracao::set('creative_engine_ativo', '0');
        $r = $this->rascunho(Company::factory()->create(), 'CAD-01');
        $this->foto($r);

        $this->actingAs($this->admin)->getJson($this->rota('listar', $r))->assertOk()->assertJsonPath('total', 1);
    }

    /** ACV-02: origem, só este produto, busca por SKU/nome, período e paginação. */
    public function test_filtros_e_paginacao(): void
    {
        $empresa = Company::factory()->create();
        $a = $this->rascunho($empresa, 'CAD-01', 'Cadeira azul');
        $b = $this->rascunho($empresa, 'MESA-01', 'Mesa de canto');
        $this->foto($a, 10);
        $this->foto($b, 20, ['origem' => AcervoImagem::ORIGEM_IA]);
        AcervoImagem::query()->where('sku', 'MESA-01')->update(['created_at' => '2026-09-01 10:00:00']);

        $listar = fn (array $q) => $this->actingAs($this->admin)->getJson($this->rota('listar', $a, $q))->assertOk()->json();

        $this->assertSame(['MESA-01'], array_column($listar(['origem' => 'ia'])['itens'], 'sku'));
        $this->assertSame(['CAD-01'], array_column($listar(['so_este_produto' => 1])['itens'], 'sku'));
        $this->assertSame(['MESA-01'], array_column($listar(['busca' => 'canto'])['itens'], 'sku'));
        $this->assertSame(['CAD-01'], array_column($listar(['busca' => 'CAD'])['itens'], 'sku'));
        $this->assertSame(['MESA-01'], array_column($listar(['de' => '2026-09-01', 'ate' => '2026-09-30'])['itens'], 'sku'));
        $this->assertSame(['MESA-01', 'CAD-01'], array_column($listar(['ordem' => 'antigas'])['itens'], 'sku'));

        for ($cor = 30; $cor < 30 + 25; $cor++) {
            $this->foto($a, $cor);
        }
        $p2 = $listar(['pagina' => 2]);
        $this->assertSame([27, 2, 2, 3], [$p2['total'], $p2['paginas'], $p2['pagina'], count($p2['itens'])]);
    }

    /** Ativo saindo do acervo (Fase 199) não aparece. */
    public function test_so_lista_ativos_disponiveis(): void
    {
        $r = $this->rascunho(Company::factory()->create(), 'CAD-01');
        $this->foto($r);
        AcervoImagem::query()->update(['status' => AcervoImagem::EXCLUINDO]);

        $this->actingAs($this->admin)->getJson($this->rota('listar', $r))->assertOk()->assertJsonPath('total', 0);
    }

    /** ACV-08: conta A não lista, não lê e não usa ativo da conta B — sempre 404. */
    public function test_isolamento_entre_contas(): void
    {
        $daA = $this->rascunho(Company::factory()->create(), 'A-01');
        $daB = $this->rascunho(Company::factory()->create(), 'B-01');
        $this->foto($daB);
        $ativoDeB = AcervoImagem::first();

        $this->actingAs($this->admin)->getJson($this->rota('listar', $daA))->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($this->admin)->get($this->rota('miniatura', $daA, ['ativo' => $ativoDeB->id]))->assertNotFound();
        $this->actingAs($this->admin)->get($this->rota('original', $daA, ['ativo' => $ativoDeB->id]))->assertNotFound();
        $this->actingAs($this->admin)->postJson($this->rota('usar', $daA, ['ativo' => $ativoDeB->id]), ['grupo' => 'GENERAL'])->assertNotFound();
        $this->assertSame(0, $daA->imagens()->count());
    }

    /** ACV-03/04: miniatura WebP gerada uma vez e guardada; o original sai inteiro. */
    public function test_miniatura_webp_e_original(): void
    {
        $r = $this->rascunho(Company::factory()->create(), 'CAD-01');
        $this->foto($r);
        $ativo = AcervoImagem::first();

        $mini = $this->actingAs($this->admin)->get($this->rota('miniatura', $r, ['ativo' => $ativo->id]))->assertOk();
        $ativo->refresh();
        $this->assertNotNull($ativo->miniatura_caminho);
        Storage::disk('r2')->assertExists($ativo->miniatura_caminho);
        $bytes = Storage::disk('r2')->get($ativo->miniatura_caminho);
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        $this->assertLessThanOrEqual(360, getimagesizefromstring($bytes)[0]);
        $this->assertStringContainsString('max-age=86400', (string) $mini->headers->get('Cache-Control'));

        $original = $this->actingAs($this->admin)->get($this->rota('original', $r, ['ativo' => $ativo->id]))->assertOk();
        $this->assertSame(Storage::disk('r2')->get($ativo->caminho), $original->streamedContent());
    }

    /** ACV-05: usar em outro anúncio da conta cria o USO, sem novo arquivo; repetir é idempotente. */
    public function test_usar_vincula_sem_copia_fisica(): void
    {
        $empresa = Company::factory()->create();
        $origem = $this->rascunho($empresa, 'CAD-01');
        $destino = $this->rascunho($empresa, 'CAD-02');
        $this->foto($origem);
        $ativo = AcervoImagem::first();
        $arquivosAntes = Storage::disk('r2')->allFiles();

        $res = $this->actingAs($this->admin)->postJson($this->rota('usar', $destino, ['ativo' => $ativo->id]), ['grupo' => 'GENERAL'])
            ->assertOk()->assertJsonPath('repetida', false)->json();

        $img = PubImagem::find((int) $res['imagem_id']);
        $this->assertSame([$destino->id, $ativo->id, $ativo->caminho], [$img->rascunho_id, $img->acervo_imagem_id, $img->caminho]);
        $this->assertSame(1, $img->atribuicoes()->where('grupo_chave', 'GENERAL')->count());
        $this->assertSame($arquivosAntes, Storage::disk('r2')->allFiles());

        $this->actingAs($this->admin)->postJson($this->rota('usar', $destino, ['ativo' => $ativo->id]), ['grupo' => 'GENERAL'])
            ->assertOk()->assertJsonPath('repetida', true);
        $this->assertSame(1, $destino->imagens()->count());
    }

    public function test_usar_recusa_grupo_inexistente_e_anuncio_publicado(): void
    {
        $empresa = Company::factory()->create();
        $origem = $this->rascunho($empresa, 'CAD-01');
        $destino = $this->rascunho($empresa, 'CAD-02');
        $this->foto($origem);
        $ativo = AcervoImagem::first();

        $this->actingAs($this->admin)->postJson($this->rota('usar', $destino, ['ativo' => $ativo->id]), ['grupo' => 'COLOR=nao-existe'])->assertStatus(422);

        $destino->update(['status' => PubRascunho::PUBLISHED]);
        $this->actingAs($this->admin)->postJson($this->rota('usar', $destino, ['ativo' => $ativo->id]), ['grupo' => 'GENERAL'])->assertStatus(422);
        $this->assertSame(0, $destino->imagens()->count());
    }
}
