<?php

namespace Tests\Feature\Phase171;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 171, Plano 01, Task 2 — `MlbPublicadorAcervoController::listar()/imagem()/usar()`.
 *
 * Cenário mínimo (sem `CenarioCadeira`, molde da Fase 170): `PubProduto`
 * ancorado só em `company_id` — `ProgramasPublicadorService::empresaDoProduto()`
 * resolve sozinho como programa 'gestao'. Nenhum `MlToken` é criado para as
 * contas de teste, então `ImagemAssetService::receber()->enviarAoMl()` NUNCA
 * chama o Mercado Livre de verdade: `PubProduto::contaOuNula()` devolve
 * `null` (sem token) e o método retorna antes de qualquer HTTP.
 * `Http::preventStrayRequests()` garante isso por teste, não por leitura de
 * código.
 *
 * Imagens de teste são JPEGs REAIS (`imagecreatetruecolor()`/`imagejpeg()`
 * em buffer) — `ValidadorImagem::problemas()` lê a dimensão de verdade via
 * `getimagesizefromstring()`; uma string literal fake daria falso-positivo
 * ou falso-negativo na revalidação de tamanho mínimo (caso 7).
 */
class MlbPublicadorAcervoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        Configuracao::set('creative_engine_ativo', '1');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function produto(Company $company, string $sku): PubProduto
    {
        return PubProduto::create([
            'company_id' => $company->id,
            'sku' => $sku,
            'nome' => 'Produto '.$sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    private function rascunhoDoProduto(PubProduto $produto): PubRascunho
    {
        return PubRascunho::create(['produto_id' => $produto->id]);
    }

    private static function jpegBytes(int $lado): string
    {
        $img = imagecreatetruecolor($lado, $lado);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 200, 200));
        ob_start();
        imagejpeg($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /** Um criativo GERADO (imagem real em disco fake) — a unidade mínima que o acervo lista/serve/reaproveita. */
    private function criativoGerado(
        Company $company,
        ?int $pubRascunhoId,
        int $lado = 600,
        string $status = MlAnuncioCriativo::STATUS_APROVADO,
        int $slotIndice = 1,
        ?int $kitId = null,
    ): MlAnuncioCriativo {
        $bytes = self::jpegBytes($lado);
        $caminho = 'creative-geradas/'.Str::random(16)."/{$slotIndice}.jpg";
        Storage::disk('local')->put($caminho, $bytes);

        return MlAnuncioCriativo::create([
            'token' => Str::random(32),
            'company_id' => $company->id,
            'mlb_empresa_id' => null,
            'rascunho_id' => null,
            'pub_rascunho_id' => $pubRascunhoId,
            'pub_grupo' => 'GENERAL',
            'kit_id' => $kitId,
            'slot_indice' => $slotIndice,
            'slot' => 'hero',
            'status' => $status,
            'imagem_path' => $caminho,
            'imagem_mime' => 'image/jpeg',
        ]);
    }

    private function rotaListar(PubProduto $produto, array $query = []): string
    {
        return route('mlb.anuncios.publicador.acervo.listar', ['produto' => $produto->id, ...$query]);
    }

    private function rotaImagem(PubProduto $produto, int $criativo): string
    {
        return route('mlb.anuncios.publicador.acervo.imagem', ['produto' => $produto->id, 'criativo' => $criativo]);
    }

    private function rotaUsar(PubProduto $produto, int $criativo): string
    {
        return route('mlb.anuncios.publicador.acervo.usar', ['produto' => $produto->id, 'criativo' => $criativo]);
    }

    // ═══ Caso 1 — listar sem toda_conta: só o item do PRÓPRIO rascunho ═══

    public function test_listar_sem_toda_conta_mostra_so_o_item_do_proprio_rascunho(): void
    {
        $companyA = Company::factory()->create();
        $produtoA1 = $this->produto($companyA, 'A-01');
        $rascunhoA1 = $this->rascunhoDoProduto($produtoA1);
        $produtoA2 = $this->produto($companyA, 'A-02');
        $rascunhoA2 = $this->rascunhoDoProduto($produtoA2);

        $itemProprio = $this->criativoGerado($companyA, $rascunhoA1->id);
        $this->criativoGerado($companyA, $rascunhoA2->id); // outro produto da MESMA conta

        $resp = $this->actingAs($this->admin())->getJson($this->rotaListar($produtoA1));

        $resp->assertOk();
        $resp->assertJsonPath('toda_conta', false);
        $ids = collect($resp->json('itens'))->pluck('id')->all();
        $this->assertSame([$itemProprio->id], $ids);
    }

    // ═══ Caso 2 — toda_conta=1: outro produto da MESMA conta aparece; conta B nunca ═══

    public function test_listar_com_toda_conta_mostra_outro_produto_da_mesma_conta_mas_nunca_de_outra_conta(): void
    {
        $companyA = Company::factory()->create();
        $produtoA1 = $this->produto($companyA, 'A-01');
        $rascunhoA1 = $this->rascunhoDoProduto($produtoA1);
        $produtoA2 = $this->produto($companyA, 'A-02');
        $rascunhoA2 = $this->rascunhoDoProduto($produtoA2);

        $companyB = Company::factory()->create();
        $produtoB1 = $this->produto($companyB, 'B-01');
        $rascunhoB1 = $this->rascunhoDoProduto($produtoB1);

        $itemA1 = $this->criativoGerado($companyA, $rascunhoA1->id);
        $itemA2 = $this->criativoGerado($companyA, $rascunhoA2->id);
        $itemB1 = $this->criativoGerado($companyB, $rascunhoB1->id);

        $resp = $this->actingAs($this->admin())->getJson($this->rotaListar($produtoA1, ['toda_conta' => 1]));

        $resp->assertOk();
        $resp->assertJsonPath('toda_conta', true);
        $ids = collect($resp->json('itens'))->pluck('id')->all();
        $this->assertContains($itemA1->id, $ids);
        $this->assertContains($itemA2->id, $ids);
        $this->assertNotContains($itemB1->id, $ids);
    }

    // ═══ Caso 3 — criativo órfão ═══

    public function test_criativo_orfao_so_aparece_com_toda_conta_e_sem_nome_de_produto(): void
    {
        $companyA = Company::factory()->create();
        $produtoA1 = $this->produto($companyA, 'A-01');
        $rascunhoA1 = $this->rascunhoDoProduto($produtoA1);

        $orfao = $this->criativoGerado($companyA, $rascunhoA1->id);
        // Simula o rascunho de origem já apagado — sem precisar de nenhum endpoint de exclusão
        // (não existe hoje no Publicador novo).
        $orfao->update(['pub_rascunho_id' => null]);

        $semTodaConta = $this->actingAs($this->admin())->getJson($this->rotaListar($produtoA1));
        $semTodaConta->assertOk();
        $this->assertSame([], collect($semTodaConta->json('itens'))->pluck('id')->all());

        $comTodaConta = $this->actingAs($this->admin())->getJson($this->rotaListar($produtoA1, ['toda_conta' => 1]));
        $comTodaConta->assertOk();
        $item = collect($comTodaConta->json('itens'))->firstWhere('id', $orfao->id);
        $this->assertNotNull($item);
        $this->assertNull($item['produto_nome']);
    }

    // ═══ Caso 4 — imagem de conta B, autenticado em produto da conta A => 404 ═══

    public function test_imagem_de_criativo_de_outra_conta_devolve_404(): void
    {
        $companyA = Company::factory()->create();
        $produtoA1 = $this->produto($companyA, 'A-01');
        $this->rascunhoDoProduto($produtoA1);

        $companyB = Company::factory()->create();
        $produtoB1 = $this->produto($companyB, 'B-01');
        $rascunhoB1 = $this->rascunhoDoProduto($produtoB1);
        $itemB1 = $this->criativoGerado($companyB, $rascunhoB1->id);

        $resp = $this->actingAs($this->admin())->getJson($this->rotaImagem($produtoA1, $itemB1->id));

        $resp->assertStatus(404);
    }

    // ═══ Caso 5 — imagem da MESMA conta, de OUTRO produto => 200 com o binário certo ═══

    public function test_imagem_da_mesma_conta_mas_de_outro_produto_devolve_o_binario_certo(): void
    {
        $companyA = Company::factory()->create();
        $produtoA1 = $this->produto($companyA, 'A-01');
        $this->rascunhoDoProduto($produtoA1);
        $produtoA2 = $this->produto($companyA, 'A-02');
        $rascunhoA2 = $this->rascunhoDoProduto($produtoA2);

        $itemA2 = $this->criativoGerado($companyA, $rascunhoA2->id, 700);
        $bytesEsperados = Storage::disk('local')->get($itemA2->imagem_path);

        $resp = $this->actingAs($this->admin())->get($this->rotaImagem($produtoA1, $itemA2->id));

        $resp->assertOk();
        $this->assertSame($bytesEsperados, $resp->getContent());
    }

    // ═══ Caso 6 — usar: cópia, nunca referência; origem intocada ═══

    public function test_usar_copia_a_imagem_para_o_rascunho_atual_sem_alterar_a_origem(): void
    {
        $companyA = Company::factory()->create();
        $produtoA1 = $this->produto($companyA, 'A-01');
        $rascunhoA1 = $this->rascunhoDoProduto($produtoA1);
        $produtoA2 = $this->produto($companyA, 'A-02');
        $rascunhoA2 = $this->rascunhoDoProduto($produtoA2);

        $origem = $this->criativoGerado($companyA, $rascunhoA2->id);
        $statusAntes = $origem->status;
        $pubImagemIdAntes = $origem->pub_imagem_id;
        $aprovadoEmAntes = $origem->aprovado_em;
        $totalImagensAntes = PubImagem::count();

        $resp = $this->actingAs($this->admin())->postJson($this->rotaUsar($produtoA1, $origem->id), ['grupo' => 'GENERAL']);

        $resp->assertOk();
        $resp->assertJsonPath('ok', true);
        $this->assertIsString($resp->json('imagem_id'));

        $this->assertSame($totalImagensAntes + 1, PubImagem::count());
        $novaImagem = PubImagem::find((int) $resp->json('imagem_id'));
        $this->assertNotNull($novaImagem);
        $this->assertSame($rascunhoA1->id, $novaImagem->rascunho_id);

        $origem->refresh();
        $this->assertSame($statusAntes, $origem->status);
        $this->assertSame($pubImagemIdAntes, $origem->pub_imagem_id);
        $this->assertEquals($aprovadoEmAntes, $origem->aprovado_em);
    }

    // ═══ Caso 7 — revalidação: imagem pequena demais ═══

    public function test_usar_com_imagem_pequena_demais_recusa_e_nao_cria_pub_imagem(): void
    {
        $companyA = Company::factory()->create();
        $produtoA1 = $this->produto($companyA, 'A-01');
        $this->rascunhoDoProduto($produtoA1);
        $produtoA2 = $this->produto($companyA, 'A-02');
        $rascunhoA2 = $this->rascunhoDoProduto($produtoA2);

        $pequena = $this->criativoGerado($companyA, $rascunhoA2->id, 10);
        $totalAntes = PubImagem::count();

        $resp = $this->actingAs($this->admin())->postJson($this->rotaUsar($produtoA1, $pequena->id), ['grupo' => 'GENERAL']);

        $resp->assertStatus(422);
        $resp->assertJsonPath('ok', false);
        $this->assertStringContainsString('mínimo', $resp->json('erros.0.mensagem'));
        $this->assertSame($totalAntes, PubImagem::count());
    }

    // ═══ Caso 8 — grupo forjado ═══

    public function test_usar_com_grupo_forjado_recusa(): void
    {
        $companyA = Company::factory()->create();
        $produtoA1 = $this->produto($companyA, 'A-01');
        $this->rascunhoDoProduto($produtoA1);
        $produtoA2 = $this->produto($companyA, 'A-02');
        $rascunhoA2 = $this->rascunhoDoProduto($produtoA2);

        $origem = $this->criativoGerado($companyA, $rascunhoA2->id);

        $resp = $this->actingAs($this->admin())->postJson(
            $this->rotaUsar($produtoA1, $origem->id),
            ['grupo' => 'GRUPO-QUE-NAO-EXISTE'],
        );

        $resp->assertStatus(422);
        $resp->assertJsonPath('erros.0.mensagem', 'Este grupo de fotos não existe mais neste anúncio. Recarregue a página.');
    }

    // ═══ Caso 9 — rascunho atual intocável (PUBLISHED) ═══

    public function test_usar_com_rascunho_atual_publicado_recusa(): void
    {
        $companyA = Company::factory()->create();
        $produtoA1 = $this->produto($companyA, 'A-01');
        $rascunhoA1 = $this->rascunhoDoProduto($produtoA1);
        $rascunhoA1->update(['status' => PubRascunho::PUBLISHED]);
        $produtoA2 = $this->produto($companyA, 'A-02');
        $rascunhoA2 = $this->rascunhoDoProduto($produtoA2);

        $origem = $this->criativoGerado($companyA, $rascunhoA2->id);

        $resp = $this->actingAs($this->admin())->postJson($this->rotaUsar($produtoA1, $origem->id), ['grupo' => 'GENERAL']);

        $resp->assertStatus(422);
        $resp->assertJsonPath(
            'erros.0.mensagem',
            'Este anúncio já está publicado (ou sendo publicado) — as fotos não mudam mais por aqui.',
        );
    }

    // ═══ Caso 10 — chave desligada ═══

    public function test_chave_desligada_devolve_404_nos_3_endpoints(): void
    {
        $companyA = Company::factory()->create();
        $produtoA1 = $this->produto($companyA, 'A-01');
        $rascunhoA1 = $this->rascunhoDoProduto($produtoA1);
        $origem = $this->criativoGerado($companyA, $rascunhoA1->id);

        Configuracao::set('creative_engine_ativo', '0');
        $admin = $this->admin();

        $this->actingAs($admin)->getJson($this->rotaListar($produtoA1))->assertStatus(404);
        $this->actingAs($admin)->getJson($this->rotaImagem($produtoA1, $origem->id))->assertStatus(404);
        $this->actingAs($admin)->postJson($this->rotaUsar($produtoA1, $origem->id), ['grupo' => 'GENERAL'])->assertStatus(404);
    }

    // ═══ Caso 11 — idempotência: reaproveitar a mesma imagem 2x no mesmo grupo/destino ═══

    public function test_usar_duas_vezes_a_mesma_imagem_no_mesmo_grupo_e_destino_e_idempotente(): void
    {
        $companyA = Company::factory()->create();
        $produtoA1 = $this->produto($companyA, 'A-01');
        $this->rascunhoDoProduto($produtoA1);
        $produtoA2 = $this->produto($companyA, 'A-02');
        $rascunhoA2 = $this->rascunhoDoProduto($produtoA2);

        $origem = $this->criativoGerado($companyA, $rascunhoA2->id);
        $admin = $this->admin();

        $primeira = $this->actingAs($admin)->postJson($this->rotaUsar($produtoA1, $origem->id), ['grupo' => 'GENERAL']);
        $primeira->assertOk();
        $primeira->assertJsonPath('ok', true);

        $totalDepoisDaPrimeira = PubImagem::count();

        $segunda = $this->actingAs($admin)->postJson($this->rotaUsar($produtoA1, $origem->id), ['grupo' => 'GENERAL']);
        $segunda->assertOk();
        $segunda->assertJsonPath('ok', true);

        $this->assertSame($totalDepoisDaPrimeira, PubImagem::count());
    }

    // ═══ Caso 12 — kit legado de 7 slots ═══

    public function test_kit_legado_de_7_slots_aparece_na_listagem_sem_erro(): void
    {
        $companyA = Company::factory()->create();
        $produtoA1 = $this->produto($companyA, 'A-01');
        $rascunhoA1 = $this->rascunhoDoProduto($produtoA1);

        $kit = MlAnuncioCriativoKit::create([
            'token' => Str::random(32),
            'company_id' => $companyA->id,
            'pub_rascunho_id' => $rascunhoA1->id,
            'pub_grupo' => 'GENERAL',
            'status' => MlAnuncioCriativoKit::STATUS_PRONTO,
            'total_slots' => 7,
            'minimo_aprovadas' => 3,
        ]);

        $ids = [];
        for ($i = 1; $i <= 7; $i++) {
            $ids[] = $this->criativoGerado($companyA, $rascunhoA1->id, 600, MlAnuncioCriativo::STATUS_PRONTO, $i, $kit->id)->id;
        }

        $resp = $this->actingAs($this->admin())->getJson($this->rotaListar($produtoA1));

        $resp->assertOk();
        $idsDevolvidos = collect($resp->json('itens'))->pluck('id')->all();
        foreach ($ids as $id) {
            $this->assertContains($id, $idsDevolvidos);
        }
    }
}
