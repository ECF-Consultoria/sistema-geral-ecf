<?php

namespace Tests\Feature\Phase165;

use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\PubProduto;
use App\Models\User;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 165, Plano 04, Task 1 — leituras do Creative Engine no Publicador
 * (`MlbPublicadorCriativoController::atual/status/referencia/imagem`):
 * escopo por produto, kit pelo id numérico (D-13, nenhum token de 32
 * caracteres chega ao navegador), status de tela calculado pelos slots
 * (decisão 3 do `165-04-PLAN.md`).
 */
class CriativosEndpointsTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCriativo('mlb_empresa');
    }

    private function rotaAtual(array $query = []): string
    {
        return route('mlb.anuncios.publicador.criativos.atual', ['produto' => $this->produto->id, ...$query]);
    }

    private function rotaStatus(int $kit): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.status', ['produto' => $this->produto->id, 'kit' => $kit]);
    }

    private function rotaReferencia(int $kit, int $indice): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.referencia', ['produto' => $this->produto->id, 'kit' => $kit, 'indice' => $indice]);
    }

    private function rotaImagem(int $kit, int $indice): string
    {
        return route('mlb.anuncios.publicador.criativos.slot.imagem', ['produto' => $this->produto->id, 'kit' => $kit, 'indice' => $indice]);
    }

    // ═══ Rotas registradas (nomes + whereNumber + limitadores, T-165-20) ═══

    public function test_rotas_novas_estao_registradas_com_os_nomes_certos(): void
    {
        foreach (['atual', 'kit.planejar', 'kit.status', 'kit.gerar', 'kit.referencia', 'slot.imagem', 'slot.aprovar'] as $nome) {
            $this->assertTrue(Route::has("mlb.anuncios.publicador.criativos.{$nome}"), "rota {$nome} não registrada");
        }
    }

    // ═══ OPS-03 — chave desligada, 404 puro em toda rota nova ═══

    public function test_chave_desligada_devolve_404_nas_rotas_de_leitura(): void
    {
        Configuracao::set('creative_engine_ativo', '0');
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson($this->rotaAtual(['grupo' => 'GENERAL']))->assertStatus(404);
        $this->actingAs($admin)->getJson($this->rotaStatus($kit->id))->assertStatus(404);
        $this->actingAs($admin)->get($this->rotaReferencia($kit->id, 1))->assertStatus(404);
        $this->actingAs($admin)->get($this->rotaImagem($kit->id, 1))->assertStatus(404);
    }

    // ═══ Produto fora do escopo ═══

    public function test_produto_inexistente_devolve_404(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson(route('mlb.anuncios.publicador.criativos.atual', ['produto' => 999999, 'grupo' => 'GENERAL']))
            ->assertStatus(404);
    }

    public function test_empresa_arquivada_devolve_404(): void
    {
        $this->empresa->update(['arquivado_em' => now()]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson($this->rotaAtual(['grupo' => 'GENERAL']))->assertStatus(404);
    }

    // ═══ T-165-16 — kit de outro produto / inexistente / do assistente antigo ═══

    public function test_kit_de_outro_produto_devolve_404_em_status_referencia_e_imagem(): void
    {
        $produtoOutro = PubProduto::create(['mlb_empresa_id' => $this->empresa->id, 'sku' => 'OUTRO-01', 'nome' => 'Outro produto', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $rOutro = $this->repo->criar($produtoOutro, [new Alvo('gold_special', 'Outro produto')]);
        $kitOutro = MlAnuncioCriativoKit::create([
            'token' => Str::random(32),
            'status' => MlAnuncioCriativoKit::STATUS_PRONTO,
            'pub_rascunho_id' => $rOutro->id,
            'pub_grupo' => 'GENERAL',
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson($this->rotaStatus($kitOutro->id))->assertStatus(404);
        $this->actingAs($admin)->get($this->rotaReferencia($kitOutro->id, 1))->assertStatus(404);
        $this->actingAs($admin)->get($this->rotaImagem($kitOutro->id, 1))->assertStatus(404);
    }

    public function test_kit_id_inexistente_devolve_404(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson($this->rotaStatus(999999))->assertStatus(404);
    }

    public function test_kit_do_assistente_antigo_devolve_404_pelo_id(): void
    {
        $rascunhoAntigo = MlAnuncioRascunho::create([
            'company_id' => null,
            'category_id' => 'MLB1574',
            'payload' => ['title' => 'Antigo'],
            'status' => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id' => User::factory()->create()->id,
        ]);
        $kitAntigo = MlAnuncioCriativoKit::create([
            'token' => Str::random(32),
            'status' => MlAnuncioCriativoKit::STATUS_PRONTO,
            'rascunho_id' => $rascunhoAntigo->id,
            'pub_rascunho_id' => null,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson($this->rotaStatus($kitAntigo->id))->assertStatus(404);
    }

    // ═══ Slot inexistente ═══

    public function test_slot_indice_inexistente_devolve_404_na_imagem(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->rotaImagem($kit->id, 99))->assertStatus(404);
    }

    // ═══ GET atual — D-15 (retomar) / D-12 (último aprovado) ═══

    public function test_atual_sem_kit_devolve_kit_null(): void
    {
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->getJson($this->rotaAtual(['grupo' => 'GENERAL']));

        $resp->assertOk();
        $resp->assertJson(['kit' => null]);
    }

    public function test_atual_com_kit_retomavel_devolve_o_kit_id(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->getJson($this->rotaAtual(['grupo' => 'GENERAL']));

        $resp->assertOk();
        $resp->assertJsonPath('kit.kit_id', $kit->id);
    }

    public function test_atual_so_com_kit_aprovado_devolve_o_ultimo_aprovado(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_APROVADO]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->getJson($this->rotaAtual(['grupo' => 'GENERAL']));

        $resp->assertOk();
        $resp->assertJsonPath('kit.kit_id', $kit->id);
    }

    public function test_atual_com_kit_em_erro_devolve_kit_null(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_ERRO]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->getJson($this->rotaAtual(['grupo' => 'GENERAL']));

        $resp->assertOk();
        $resp->assertJson(['kit' => null]);
    }

    // ═══ GET kit.status — contrato whitelist (T-161-05/T-162-18), sem nenhum token (D-13) ═══

    public function test_status_devolve_as_chaves_esperadas_sem_nenhum_token(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->getJson($this->rotaStatus($kit->id));

        $resp->assertOk();
        $resp->assertJsonStructure([
            'kit_id', 'grupo', 'status', 'etapa', 'em_andamento', 'erro', 'estrategia', 'minimo_aprovadas',
            'prontas', 'aprovadas', 'referencias',
            'slots' => [['indice', 'tipo', 'rotulo', 'objetivo', 'status', 'imagem_url']],
        ]);

        $corpo = $resp->getContent();
        $this->assertStringNotContainsString('"token"', $corpo);
        $this->assertStringNotContainsString('kit_token', $corpo);
        foreach ($this->tokensDoKit($kit) as $token) {
            $this->assertStringNotContainsString((string) $token, $corpo);
        }
        $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z0-9])[A-Za-z0-9]{32}(?![A-Za-z0-9])/', $corpo);
    }

    public function test_status_urls_usam_o_id_numerico_do_kit_e_o_indice(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->getJson($this->rotaStatus($kit->id));

        $url = $resp->json('slots.0.imagem_url');
        $this->assertStringContainsString("/kit/{$kit->id}/", $url);
        $this->assertStringContainsString('/slots/1/imagem', $url);

        $refUrl = $resp->json('referencias.0.url');
        $this->assertStringContainsString("/kit/{$kit->id}/", $refUrl);
    }

    // ═══ Status efetivo pelos slots (decisão 3) — achado (a) do 165-01 ═══

    public function test_status_efetivo_pronto_com_slot_aprovado_misturado(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_GERANDO]);
        $kit->slots()->first()->update(['status' => MlAnuncioCriativo::STATUS_APROVADO]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->getJson($this->rotaStatus($kit->id));

        $resp->assertJsonPath('status', 'pronto');
        $resp->assertJsonPath('em_andamento', false);
    }

    public function test_status_efetivo_gerando_com_um_slot_rodando(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_GERANDO]);
        $kit->slots()->first()->update(['status' => MlAnuncioCriativo::STATUS_APROVADO]);
        $kit->slots()->skip(1)->first()->update(['status' => MlAnuncioCriativo::STATUS_RODANDO]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->getJson($this->rotaStatus($kit->id));

        $resp->assertJsonPath('status', 'gerando');
        $resp->assertJsonPath('em_andamento', true);
    }

    public function test_status_efetivo_planejado_com_todos_os_slots_pendente(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_PLANEJADO]);
        $kit->slots()->update(['status' => MlAnuncioCriativo::STATUS_PENDENTE]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->getJson($this->rotaStatus($kit->id));

        $resp->assertJsonPath('status', 'planejado');
    }

    // ═══ Polling com slot aprovado não recalcula nem encerra por tempo (decisão 3) ═══

    public function test_polling_com_slot_aprovado_nao_chama_recalcular_nem_vira_erro_mesmo_antigo(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_GERANDO]);
        $kit->slots()->first()->update(['status' => MlAnuncioCriativo::STATUS_APROVADO]);
        $kit->forceFill(['created_at' => now()->subMinutes(30)])->save();
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->getJson($this->rotaStatus($kit->id));

        $resp->assertJsonPath('status', 'pronto');
        $this->assertSame(MlAnuncioCriativoKit::STATUS_GERANDO, $kit->fresh()->status);
    }

    // ═══ Binários — referência e imagem do slot ═══

    public function test_referencia_devolve_bytes_com_no_store(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $admin = $this->admin();

        // ReferenciaEfemeraService::guardar() indexa as referências a partir de 0.
        $resp = $this->actingAs($admin)->get($this->rotaReferencia($kit->id, 0));

        $resp->assertOk();
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));
    }

    public function test_referencia_apagada_devolve_404(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $kit->criativoReferencia->update(['referencias_apagadas_em' => now()]);
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->rotaReferencia($kit->id, 1))->assertStatus(404);
    }

    public function test_imagem_do_slot_devolve_bytes_e_content_type(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->get($this->rotaImagem($kit->id, 1));

        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));
    }

    public function test_slot_sem_imagem_devolve_404(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $kit->slots()->first()->update(['imagem_path' => null]);
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->rotaImagem($kit->id, 1))->assertStatus(404);
    }
}
