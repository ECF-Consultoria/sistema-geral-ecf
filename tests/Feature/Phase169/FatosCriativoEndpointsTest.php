<?php

namespace Tests\Feature\Phase169;

use App\Models\Configuracao;
use App\Models\PubProduto;
use App\Models\PubProdutoFatoCriativo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 169, Plano 02, Task 3 (TXT-01/TXT-04) — os três endpoints
 * `mlb.anuncios.publicador.criativos.fatos*`: GET lista + diz o que falta,
 * POST confirma, DELETE remove. Escopados por produto (T-169-05), sem token
 * de 32 caracteres na resposta (D-13/T-169-08), permissão exigida nas
 * escritas (T-169-06).
 */
class FatosCriativoEndpointsTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCriativo('mlb_empresa');

        // O cenário padrão (CenarioCadeira) já cadastra atributos de medida
        // (BACKREST_HEIGHT/SEAT_DEPTH/OFFICE_CHAIR_WIDTH/MAX_CHAIR_HEIGHT) —
        // suficientes para habilitar texto por CADASTRO sozinho. Zerando os
        // atributos aqui isola o caminho humano (TXT-03/04), que é o que
        // este plano testa.
        $this->repo->gravarAtributos($this->r->fresh(), []);
        $this->r = $this->r->fresh();

        // Mesmo ajuste do NenhumTokenNoNavegadorTest (165-05): com
        // APP_DEBUG=true (.env local) o handler de exceção acrescenta
        // `trace`/`file` em QUALQUER abort, e caminhos de arquivo batem na
        // regex de 32 caracteres por acidente, sem relação com token.
        config(['app.debug' => false]);
    }

    private function rotaFatos(): string
    {
        return route('mlb.anuncios.publicador.criativos.fatos', ['produto' => $this->produto->id]);
    }

    private function rotaFatosSalvar(): string
    {
        return route('mlb.anuncios.publicador.criativos.fatos.salvar', ['produto' => $this->produto->id]);
    }

    private function rotaFatosRemover(int $fato): string
    {
        return route('mlb.anuncios.publicador.criativos.fatos.remover', ['produto' => $this->produto->id, 'fato' => $fato]);
    }

    // ═══ GET sem nenhum fato ════════════════════════════════════════════

    public function test_get_sem_nenhum_fato_diz_pode_ter_texto_false_e_o_que_falta(): void
    {
        $resp = $this->actingAs($this->admin())->getJson($this->rotaFatos());

        $resp->assertOk();
        $resp->assertJsonPath('confirmados', []);
        $resp->assertJsonPath('pode_ter_texto', false);
        $this->assertNotEmpty($resp->json('faltam'));
    }

    // ═══ POST grava e aparece em confirmados ═══════════════════════════

    public function test_post_grava_fato_e_aparece_em_confirmados(): void
    {
        $resp = $this->actingAs($this->admin())->postJson($this->rotaFatosSalvar(), [
            'tipo' => 'beneficio',
            'texto' => 'Resistente à água',
        ]);

        $resp->assertOk();

        $confirmados = $resp->json('confirmados');
        $this->assertCount(1, $confirmados);
        $this->assertSame('beneficio', $confirmados[0]['tipo']);
        $this->assertSame('Resistente à água', $confirmados[0]['texto']);
        $this->assertDatabaseHas('pub_produto_fatos_criativo', [
            'pub_produto_id' => $this->produto->id,
            'tipo' => 'beneficio',
            'texto' => 'Resistente à água',
        ]);
    }

    public function test_post_com_3_beneficios_habilita_pode_ter_texto(): void
    {
        $admin = $this->admin();

        foreach (['Resistente à água', 'Fácil de montar', 'Garantia de 1 ano'] as $texto) {
            $this->actingAs($admin)->postJson($this->rotaFatosSalvar(), ['tipo' => 'beneficio', 'texto' => $texto]);
        }

        $resp = $this->actingAs($admin)->getJson($this->rotaFatos());

        $resp->assertOk();
        $resp->assertJsonPath('pode_ter_texto', true);
        $resp->assertJsonPath('faltam', []);
        $this->assertCount(3, $resp->json('confirmados'));
    }

    // ═══ POST com tipo fora de beneficio|medida dá 422 ═════════════════

    public function test_post_com_tipo_invalido_da_422(): void
    {
        $resp = $this->actingAs($this->admin())->postJson($this->rotaFatosSalvar(), [
            'tipo' => 'especificacao',
            'texto' => 'Qualquer coisa',
        ]);

        $resp->assertStatus(422);
        $this->assertDatabaseCount('pub_produto_fatos_criativo', 0);
    }

    public function test_post_sem_texto_da_422(): void
    {
        $resp = $this->actingAs($this->admin())->postJson($this->rotaFatosSalvar(), [
            'tipo' => 'beneficio',
        ]);

        $resp->assertStatus(422);
    }

    // ═══ POST sem permissão dá 403 ══════════════════════════════════════

    public function test_post_sem_permissao_da_403(): void
    {
        $admin = $this->admin();
        $outroAdmin = $this->admin();

        // Camada (b) da CreativePermissao: lista preenchida SEM o usuário
        // que está fazendo a chamada — mesmo sendo admin, fica de fora.
        Configuracao::set('creative_engine_usuarios', (string) $outroAdmin->id);

        $resp = $this->actingAs($admin)->postJson($this->rotaFatosSalvar(), [
            'tipo' => 'beneficio',
            'texto' => 'Resistente à água',
        ]);

        $resp->assertStatus(403);
        $this->assertDatabaseCount('pub_produto_fatos_criativo', 0);
    }

    // ═══ DELETE de fato de OUTRO produto dá 404, nunca 403 ═════════════

    public function test_delete_de_fato_de_outro_produto_da_404_nunca_403(): void
    {
        $admin = $this->admin();

        $outroProduto = PubProduto::create([
            'mlb_empresa_id' => $this->empresa->id,
            'sku' => 'OUTRO-01',
            'nome' => 'Outro produto',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
        $fatoDeOutro = PubProdutoFatoCriativo::create([
            'pub_produto_id' => $outroProduto->id,
            'tipo' => PubProdutoFatoCriativo::TIPO_BENEFICIO,
            'texto' => 'Fato de outro produto',
            'confirmado_por_id' => $admin->id,
        ]);

        $resp = $this->actingAs($admin)->deleteJson($this->rotaFatosRemover($fatoDeOutro->id));

        $resp->assertStatus(404);
        $this->assertDatabaseHas('pub_produto_fatos_criativo', ['id' => $fatoDeOutro->id]);
    }

    // ═══ DELETE remove de fato ══════════════════════════════════════════

    public function test_delete_remove_o_fato(): void
    {
        $admin = $this->admin();

        $criado = $this->actingAs($admin)->postJson($this->rotaFatosSalvar(), [
            'tipo' => 'medida',
            'texto' => 'Largura: 45 cm',
        ]);
        $fatoId = (int) $criado->json('confirmados.0.id');

        $resp = $this->actingAs($admin)->deleteJson($this->rotaFatosRemover($fatoId));

        $resp->assertOk();
        $resp->assertJsonPath('confirmados', []);
        $this->assertDatabaseMissing('pub_produto_fatos_criativo', ['id' => $fatoId]);
    }

    public function test_delete_de_fato_inexistente_da_404(): void
    {
        $resp = $this->actingAs($this->admin())->deleteJson($this->rotaFatosRemover(999999));

        $resp->assertStatus(404);
    }

    // ═══ D-13/T-169-08 — nenhum token de 32 caracteres sai por aqui ════

    public function test_nenhuma_resposta_dos_tres_endpoints_contem_token_de_32_caracteres(): void
    {
        $admin = $this->admin();
        $corpos = [];

        $corpos[] = $this->actingAs($admin)->getJson($this->rotaFatos())->getContent();

        $post = $this->actingAs($admin)->postJson($this->rotaFatosSalvar(), [
            'tipo' => 'beneficio',
            'texto' => 'Resistente à água',
        ]);
        $corpos[] = $post->getContent();
        $fatoId = (int) $post->json('confirmados.0.id');

        $corpos[] = $this->actingAs($admin)->deleteJson($this->rotaFatosRemover($fatoId))->getContent();

        // Recusas também entram na varredura.
        $corpos[] = $this->actingAs($admin)->postJson($this->rotaFatosSalvar(), ['tipo' => 'invalido', 'texto' => 'x'])->getContent();
        $corpos[] = $this->actingAs($admin)->deleteJson($this->rotaFatosRemover(999999))->getContent();

        $corpoCompleto = implode('', $corpos);

        $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z0-9])[A-Za-z0-9]{32}(?![A-Za-z0-9])/', $corpoCompleto);
    }
}
