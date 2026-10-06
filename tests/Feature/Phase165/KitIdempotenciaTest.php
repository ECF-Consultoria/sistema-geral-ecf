<?php

namespace Tests\Feature\Phase165;

use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Creative\CreativePermissao;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 165, Plano 04, Task 2 — planejar (referências + kit sob lock,
 * idempotente por (rascunho, grupo), D-15) e gerar (com o relógio por
 * tentativa, achado (b) do 165-01).
 *
 * `services.creative.validacao.ativa` é desligada no `setUp()`: a Fase 162
 * (validador Gemini-juiz) despacha `ValidarCriativoIaJob` no FIM de toda
 * geração via um contrato (`ImageJudgementProvider`) DIFERENTE do dublê que
 * `CenarioCriativoDoPublicador` registra (`ImageGenerationProvider`) — sem
 * desligar aqui, o teste do relógio (que deixa o `GerarCriativoIaJob` rodar
 * de verdade, fila `sync`) tentaria uma chamada HTTP real e quebraria em
 * `Http::preventStrayRequests()`. O plano original (04/10) é anterior à
 * Fase 162; este ajuste é só de infraestrutura de teste, não de produto.
 */
class KitIdempotenciaTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCriativo('mlb_empresa');
        config(['services.creative.validacao.ativa' => false]);
    }

    private function rotaPlanejar(): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.planejar', ['produto' => $this->produto->id]);
    }

    private function rotaGerar(int $kit): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.gerar', ['produto' => $this->produto->id, 'kit' => $kit]);
    }

    /** Um kit `planejado`, com 7 slots `pendente` — planeja de verdade (sem passar pelo teste de `gerar()` isolado). */
    private function kitDePlanejadoPendente(): MlAnuncioCriativoKit
    {
        $foto = $this->fotoComArquivo('GENERAL');
        $resp = $this->actingAs($this->admin())->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$foto->id]]);

        return MlAnuncioCriativoKit::find($resp->json('kit_id'));
    }

    // ═══ PLAN-01/02/03/04 — planejar cria o portador + o kit com 7 slots ═══

    public function test_planejar_devolve_202_e_fica_planejado_com_7_slots(): void
    {
        $foto = $this->fotoComArquivo('GENERAL');
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaPlanejar(), [
            'grupo' => 'GENERAL',
            'imagens' => [$foto->id],
        ]);

        $resp->assertStatus(202);
        $resp->assertJsonStructure(['kit_id', 'status', 'criado']);
        $resp->assertJsonPath('criado', true);

        $kit = MlAnuncioCriativoKit::find($resp->json('kit_id'));
        $this->assertNotNull($kit);
        $this->assertSame(MlAnuncioCriativoKit::STATUS_PLANEJADO, $kit->status);
        $this->assertSame(7, $kit->totalSlots());
        $this->assertSame($this->r->id, $kit->pub_rascunho_id);
        $this->assertSame('GENERAL', $kit->pub_grupo);

        $portador = $kit->criativoReferencia;
        $this->assertSame($this->r->id, $portador->pub_rascunho_id);
        $this->assertSame('GENERAL', $portador->pub_grupo);
        $this->assertCount(1, $portador->referenciasVivas());

        $corpo = $resp->getContent();
        foreach ($this->tokensDoKit($kit) as $token) {
            $this->assertStringNotContainsString((string) $token, $corpo);
        }
        $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z0-9])[A-Za-z0-9]{32}(?![A-Za-z0-9])/', $corpo);
    }

    // ═══ GEN-06 — duplo clique não cria um segundo portador nem referência ═══

    public function test_duplo_clique_devolve_o_mesmo_kit_sem_portador_nem_referencia_novos(): void
    {
        $foto = $this->fotoComArquivo('GENERAL');
        $admin = $this->admin();

        $primeira = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$foto->id]]);
        $totalPortadoresAntes = MlAnuncioCriativo::whereNull('slot_indice')->count();

        $segunda = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$foto->id]]);

        $primeira->assertStatus(202);
        $segunda->assertStatus(202);
        $this->assertSame($primeira->json('kit_id'), $segunda->json('kit_id'));
        $segunda->assertJsonPath('criado', false);
        $this->assertSame($totalPortadoresAntes, MlAnuncioCriativo::whereNull('slot_indice')->count());
    }

    // ═══ Lock ocupado — reaproveita retomável ou devolve 409 ═══

    public function test_lock_ocupado_sem_kit_retomavel_devolve_409(): void
    {
        $foto = $this->fotoComArquivo('GENERAL');
        $admin = $this->admin();

        $lock = Cache::lock('criativo-kit-planejar-pub:' . $this->r->id . ':' . md5('GENERAL'), 5);
        $this->assertTrue($lock->get());

        try {
            $resp = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$foto->id]]);
            $resp->assertStatus(409);
        } finally {
            $lock->release();
        }

        $this->assertSame(0, MlAnuncioCriativoKit::count());
    }

    public function test_lock_ocupado_mas_com_kit_retomavel_devolve_o_existente(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $foto = $this->fotoComArquivo('GENERAL');
        $admin = $this->admin();

        $lock = Cache::lock('criativo-kit-planejar-pub:' . $this->r->id . ':' . md5('GENERAL'), 5);
        $this->assertTrue($lock->get());

        try {
            $resp = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$foto->id]]);
            $resp->assertStatus(202);
            $resp->assertJsonPath('kit_id', $kit->id);
        } finally {
            $lock->release();
        }
    }

    // ═══ D-14/D-15 — grupos diferentes do mesmo rascunho têm kits diferentes ═══

    public function test_grupos_diferentes_do_mesmo_rascunho_geram_kits_diferentes(): void
    {
        $grupoCor = $this->comVariacaoDeCor();
        $fotoGeral = $this->fotoComArquivo('GENERAL');
        $fotoCor = $this->fotoComArquivo($grupoCor);
        $admin = $this->admin();

        $respGeral = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$fotoGeral->id]]);
        $respCor = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => $grupoCor, 'imagens' => [$fotoCor->id]]);

        $respGeral->assertStatus(202);
        $respCor->assertStatus(202);
        $this->assertNotSame($respGeral->json('kit_id'), $respCor->json('kit_id'));
    }

    // ═══ T-165-16 — kit retomável de outro produto nunca é devolvido ═══

    public function test_kit_retomavel_de_outro_produto_nunca_e_devolvido(): void
    {
        $produtoOutro = PubProduto::create(['mlb_empresa_id' => $this->empresa->id, 'sku' => 'OUTRO-02', 'nome' => 'Outro produto', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $rOutro = $this->repo->criar($produtoOutro, [new Alvo('gold_special', 'Outro produto')]);
        $kitOutro = MlAnuncioCriativoKit::create([
            'token' => Str::random(32),
            'status' => MlAnuncioCriativoKit::STATUS_PLANEJADO,
            'pub_rascunho_id' => $rOutro->id,
            'pub_grupo' => 'GENERAL',
            'total_slots' => 7,
        ]);
        $foto = $this->fotoComArquivo('GENERAL');
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$foto->id]]);

        $resp->assertStatus(202);
        $this->assertNotSame($kitOutro->id, $resp->json('kit_id'));
    }

    // ═══ Recusas 422/409 ═══

    public function test_grupo_inexistente_devolve_422(): void
    {
        $foto = $this->fotoComArquivo('GENERAL');
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'COLOR=id:999', 'imagens' => [$foto->id]]);

        $resp->assertStatus(422);
        $resp->assertJsonPath('erros.0.mensagem', 'Este grupo de fotos não existe mais neste anúncio. Recarregue a página.');
    }

    public function test_sem_foto_e_sem_upload_devolve_422(): void
    {
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL']);

        $resp->assertStatus(422);
        $resp->assertJsonPath('erros.0.mensagem', 'Escolha ao menos uma foto do anúncio ou envie uma foto do produto.');
    }

    public function test_mais_de_14_fotos_devolve_422(): void
    {
        $ids = [];
        for ($i = 0; $i < 15; $i++) {
            $ids[] = $this->fotoComArquivo('GENERAL')->id;
        }
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => $ids]);

        $resp->assertStatus(422);
    }

    public function test_foto_de_outro_rascunho_devolve_422(): void
    {
        $produtoOutro = PubProduto::create(['mlb_empresa_id' => $this->empresa->id, 'sku' => 'OUTRO-03', 'nome' => 'Outro produto', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $rOutro = $this->repo->criar($produtoOutro, [new Alvo('gold_special', 'Outro produto')]);
        $fotoOutro = $rOutro->imagens()->create([
            'caminho' => 'publicador/outro.jpg', 'sha256' => str_repeat('b', 64), 'mime' => 'image/jpeg',
            'bytes' => 1000, 'largura' => 800, 'altura' => 800, 'upload_status' => PubImagem::PENDENTE,
        ]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$fotoOutro->id]]);

        $resp->assertStatus(422);
    }

    public function test_upload_nao_imagem_devolve_422(): void
    {
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->post($this->rotaPlanejar(), [
            'grupo' => 'GENERAL',
            'referencias' => [UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json']);

        // Falha na validação do próprio `$request->validate()` — formato padrão
        // de ValidationException do Laravel (message/errors), não o `recusa()`
        // do controller (esse só entra depois que a validação básica passa).
        $resp->assertStatus(422);
        $resp->assertJsonPath('message', 'Envie uma imagem (JPG ou PNG) de até 10 MB.');
    }

    public function test_rascunho_publicado_devolve_422(): void
    {
        $this->r->update(['status' => PubRascunho::PUBLISHED]);
        $foto = $this->fotoComArquivo('GENERAL');
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$foto->id]]);

        $resp->assertStatus(422);
        $resp->assertJsonPath('erros.0.mensagem', 'Este anúncio já está publicado (ou sendo publicado) — as fotos não mudam mais por aqui.');
    }

    // ═══ OPS-04 — permissão explícita, DEPOIS do escopo ═══

    public function test_sem_permissao_devolve_403_depois_do_escopo(): void
    {
        $semPermissao = $this->admin();
        Configuracao::set(CreativePermissao::CHAVE_LISTA, (string) $this->admin()->id);
        $foto = $this->fotoComArquivo('GENERAL');

        $resp = $this->actingAs($semPermissao)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$foto->id]]);

        $resp->assertStatus(403);
        $this->assertSame(0, MlAnuncioCriativoKit::count());
    }

    // ═══ gerar — GEN-01/02/03 ═══

    public function test_gerar_devolve_202_e_enfileirados(): void
    {
        $kit = $this->kitDePlanejadoPendente();
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaGerar($kit->id));

        $resp->assertStatus(202);
        $resp->assertJsonPath('kit_id', $kit->id);
        $this->assertGreaterThan(0, $resp->json('enfileirados'));
    }

    public function test_gerar_com_kit_planejando_devolve_422(): void
    {
        $portador = $this->portadorDoPublicador('GENERAL');
        $kit = MlAnuncioCriativoKit::create([
            'token' => Str::random(32),
            'status' => MlAnuncioCriativoKit::STATUS_PLANEJANDO,
            'pub_rascunho_id' => $this->r->id,
            'pub_grupo' => 'GENERAL',
            'criativo_referencia_id' => $portador->id,
        ]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaGerar($kit->id));

        $resp->assertStatus(422);
        $resp->assertJsonPath('erros.0.mensagem', 'O planejamento deste kit ainda não terminou — aguarde antes de gerar as imagens.');
    }

    public function test_gerar_com_teto_atingido_devolve_422(): void
    {
        $kit = $this->kitDePlanejadoPendente();
        $kit->update(['imagens_geradas' => MlAnuncioCriativoKit::MAX_IMAGENS]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaGerar($kit->id));

        $resp->assertStatus(422);
    }

    public function test_gerar_com_trabalho_em_andamento_devolve_202_enfileirados_zero(): void
    {
        $kit = $this->kitDePlanejadoPendente();
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_GERANDO]);
        $kit->slots()->first()->update(['status' => MlAnuncioCriativo::STATUS_RODANDO]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaGerar($kit->id));

        $resp->assertStatus(202);
        $resp->assertJsonPath('enfileirados', 0);
    }

    public function test_gerar_kit_aprovado_devolve_422(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_APROVADO]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaGerar($kit->id));

        $resp->assertStatus(422);
        $resp->assertJsonPath('erros.0.mensagem', 'Este kit já foi aprovado.');
    }

    // ═══ Relógio por tentativa (achado (b) do 165-01) ═══

    public function test_gerar_muito_depois_do_planejamento_ainda_gera(): void
    {
        $kit = $this->kitDePlanejadoPendente();
        $startedAt = $kit->started_at;

        $this->travel(20)->minutes();

        $admin = $this->admin();
        $resp = $this->actingAs($admin)->postJson($this->rotaGerar($kit->id));
        $resp->assertStatus(202);

        $kit->refresh();
        foreach ($kit->slots as $slot) {
            $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $slot->status, "slot {$slot->slot_indice} não terminou pronto");
        }
        $this->assertEquals($startedAt, $kit->started_at);
        $this->assertTrue($kit->created_at->gt(now()->subMinute()), 'created_at do kit deveria ter sido regravado para o agora do travel');
    }
}
