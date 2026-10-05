<?php

namespace Tests\Feature\Phase165;

use App\Jobs\GerarCriativoIaJob;
use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubProduto;
use App\Services\Creative\Contracts\ImageGenerationProvider;
use App\Services\Creative\CreativeContextBuilder;
use App\Services\Creative\CreativePermissao;
use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\ProductTruthBuilder;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 165, Plano 05, Task 1 — regenerar UMA imagem do kit e aprovar o kit
 * inteiro pelo Publicador. Molde de `MlbAnuncioController::criativoRegenerar()`
 * (linhas 1770-1849) e `criativoKitAprovar()` (linhas 2184-2262), agora
 * endereçados pelo `id` numérico escopado (D-13).
 *
 * ⚠️ `test_regenerar_passa_regeneracao_e_ajuste_operador_ao_prompt_builder()`
 * é o teste que este plano existe para ter: a correção do Quick 261003-l8o
 * (`CreativePromptBuilder::paraSlot()` com `$regeneracao`/`$ajusteOperador`
 * opcionais no fim) só vale se o caminho do Publicador também os alimentar —
 * sem isso, regenerar devolveria SEMPRE a mesma imagem em produção.
 */
class RegenerarEAprovarKitTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCriativo('mlb_empresa');
    }

    private function rotaRegenerar(int $kit, int $indice): string
    {
        return route('mlb.anuncios.publicador.criativos.slot.regenerar', ['produto' => $this->produto->id, 'kit' => $kit, 'indice' => $indice]);
    }

    private function rotaAprovarKit(int $kit): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.aprovar', ['produto' => $this->produto->id, 'kit' => $kit]);
    }

    private function rotaAprovarSlot(int $kit, int $indice): string
    {
        return route('mlb.anuncios.publicador.criativos.slot.aprovar', ['produto' => $this->produto->id, 'kit' => $kit, 'indice' => $indice]);
    }

    private function rotaStatus(int $kit): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.status', ['produto' => $this->produto->id, 'kit' => $kit]);
    }

    private function rotaAtual(array $query = []): string
    {
        return route('mlb.anuncios.publicador.criativos.atual', ['produto' => $this->produto->id, ...$query]);
    }

    /**
     * Kit `pronto` com 3 slots, o primeiro com `slot_plano` preenchido — só
     * assim `GerarCriativoIaJob` usa `CreativePromptBuilder::paraSlot()` (o
     * ramo que recebe `$regeneracao`/`$ajusteOperador`) em vez de
     * `paraSlotHero()` (que não recebe nenhum dos dois).
     *
     * @return array{0: MlAnuncioCriativoKit, 1: MlAnuncioCriativo}
     */
    private function kitComSlotPlanejado(string $grupo = 'GENERAL'): array
    {
        $kit = $this->kitProntoDoPublicador($grupo, 3);
        $slot = $kit->slots()->where('slot_indice', 1)->first();
        $slot->update([
            'slot_plano' => [
                'indice' => 1,
                'tipo' => 'hero',
                'objetivo' => 'Objetivo do slot hero.',
                'cena' => 'Cena do slot hero.',
                'headline' => null,
                'badges' => [],
                'fatos_usados' => [],
                'proibicoes' => [],
            ],
        ]);

        return [$kit->fresh(), $slot->fresh()];
    }

    private function rodar(MlAnuncioCriativo $criativo): void
    {
        (new GerarCriativoIaJob($criativo->id))->handle(
            app(ImageGenerationProvider::class),
            app(CreativeContextBuilder::class),
            app(ProductTruthBuilder::class),
            app(CreativePromptBuilder::class),
        );
    }

    // ═══ O TESTE QUE O PLANO EXISTE PARA TER ════════════════════════════

    public function test_regenerar_passa_regeneracao_e_ajuste_operador_ao_prompt_builder(): void
    {
        [$kit, $slot] = $this->kitComSlotPlanejado();

        // 1ª geração "de referência" (fora do endpoint) só para comparar o prompt.
        $slot->update(['status' => MlAnuncioCriativo::STATUS_PENDENTE]);
        $this->rodar($slot);
        $slot->refresh();
        $promptPrimeiraGeracao = $slot->prompt;
        $this->assertNotNull($promptPrimeiraGeracao);
        $this->assertStringNotContainsString('VARIAÇÃO OBRIGATÓRIA', $promptPrimeiraGeracao);

        $admin = $this->admin();
        $resp = $this->actingAs($admin)->postJson(
            $this->rotaRegenerar($kit->id, $slot->slot_indice),
            ['motivo' => 'o produto ficou pequeno demais no quadro'],
        );
        $resp->assertStatus(202);
        $resp->assertJsonPath('status', MlAnuncioCriativo::STATUS_PRONTO);

        $slot->refresh();
        $this->assertNotSame($promptPrimeiraGeracao, $slot->prompt);
        $this->assertStringContainsString('VARIAÇÃO OBRIGATÓRIA', $slot->prompt);
        $this->assertStringContainsString('o produto ficou pequeno demais no quadro', $slot->prompt);
        $this->assertStringNotContainsString('o produto ficou pequeno demais no quadro', $promptPrimeiraGeracao);
    }

    // ═══ Regenerar com sucesso — contadores e motivos ═══════════════════

    public function test_regenerar_com_motivo_volta_pronto_com_imagem_nova_e_atualiza_contadores(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        // Índice 2 (não 1): o dublê de `ImageGenerationProvider` começa em
        // `n=0` por teste e devolve `jpeg(1200+n)` — regenerar o slot 1
        // coincidiria com `jpeg(1201)`, o MESMO lado que `kitProntoDoPublicador`
        // já escreveu em disco para o slot 1 (fakes de imagem com o mesmo
        // lado dão bytes idênticos, sem relação com o bug que este teste prova).
        $slot = $kit->slots()->where('slot_indice', 2)->first();
        $caminhoAntigo = $slot->imagem_path;
        $bytesAntigos = Storage::disk('local')->get($caminhoAntigo);
        $slotPlanoAntes = $slot->slot_plano;
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson(
            $this->rotaRegenerar($kit->id, 2),
            ['motivo' => 'fundo mais claro'],
        );

        $resp->assertStatus(202);
        $resp->assertJsonPath('indice', 2);
        $resp->assertJsonPath('status', MlAnuncioCriativo::STATUS_PRONTO);
        $this->assertArrayHasKey('regeneracoes_restantes', $resp->json());

        $slot->refresh();
        $kit->refresh();
        $this->assertSame(1, $slot->regeneracoes);
        $this->assertSame(1, $kit->regeneracoes);
        $this->assertCount(1, $slot->regenerar_motivos);
        $this->assertSame('fundo mais claro', $slot->regenerar_motivos[0]['texto']);
        $this->assertArrayHasKey('user_id', $slot->regenerar_motivos[0]);
        $this->assertArrayHasKey('em', $slot->regenerar_motivos[0]);
        $this->assertSame($slotPlanoAntes, $slot->slot_plano);
        $this->assertNotSame($caminhoAntigo, $slot->imagem_path);
        $this->assertNotSame($bytesAntigos, Storage::disk('local')->get($slot->imagem_path));
    }

    public function test_regenerar_sem_motivo_grava_entrada_com_texto_nulo(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $slot = $kit->slots()->where('slot_indice', 1)->first();
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaRegenerar($kit->id, 1));

        $resp->assertStatus(202);
        $slot->refresh();
        $this->assertCount(1, $slot->regenerar_motivos);
        $this->assertNull($slot->regenerar_motivos[0]['texto']);
    }

    public function test_motivo_acima_de_300_caracteres_devolve_422(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $slot = $kit->slots()->where('slot_indice', 1)->first();
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson(
            $this->rotaRegenerar($kit->id, 1),
            ['motivo' => str_repeat('a', 301)],
        );

        $resp->assertStatus(422);
        $this->assertStringContainsString(
            'no máximo 300 caracteres',
            $resp->json('errors.motivo.0'),
        );
        $this->assertNull($slot->fresh()->regenerar_motivos);
    }

    // ═══ Recusas de estado do slot ═══════════════════════════════════════

    public function test_slot_aprovado_devolve_422(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $slot = $kit->slots()->where('slot_indice', 1)->first();
        $slot->update(['status' => MlAnuncioCriativo::STATUS_APROVADO]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaRegenerar($kit->id, 1));

        $resp->assertStatus(422);
        $resp->assertJsonPath('erros.0.mensagem', 'Esta imagem já está nas fotos do anúncio — não é possível gerar de novo.');
        $this->assertSame(0, $slot->fresh()->regeneracoes);
    }

    public function test_slot_pendente_ou_rodando_devolve_422(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $slot = $kit->slots()->where('slot_indice', 1)->first();

        foreach ([MlAnuncioCriativo::STATUS_PENDENTE, MlAnuncioCriativo::STATUS_RODANDO] as $status) {
            $slot->update(['status' => $status]);

            $resp = $this->actingAs($this->admin())->postJson($this->rotaRegenerar($kit->id, 1));

            $resp->assertStatus(422);
            $resp->assertJsonPath('erros.0.mensagem', 'Esta imagem já está sendo gerada.');
        }
    }

    public function test_teto_de_regeneracoes_por_imagem_devolve_422_com_motivo_do_teto(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $slot = $kit->slots()->where('slot_indice', 1)->first();
        $slot->update(['regeneracoes' => MlAnuncioCriativoKit::MAX_REGENERACOES_ASSET]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaRegenerar($kit->id, 1));

        $resp->assertStatus(422);
        $resp->assertJsonPath(
            'erros.0.mensagem',
            'Este slot já atingiu o limite de ' . MlAnuncioCriativoKit::MAX_REGENERACOES_ASSET . ' regenerações.',
        );
        $this->assertSame((int) MlAnuncioCriativoKit::MAX_REGENERACOES_ASSET, $slot->fresh()->regeneracoes);
    }

    // ═══ Kit fechado ou sem referência viva — nunca sobe contador nem despacha job ═══

    public function test_kit_aprovado_devolve_422_sem_subir_contador_nem_despachar_job(): void
    {
        Queue::fake();
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $slot = $kit->slots()->where('slot_indice', 1)->first();
        $kit->criativoReferencia->update(['referencias_apagadas_em' => now()]);
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_APROVADO]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaRegenerar($kit->id, 1), ['motivo' => 'x']);

        $resp->assertStatus(422);
        $resp->assertJsonPath(
            'erros.0.mensagem',
            'Este kit já foi fechado e as fotos de referência foram apagadas — gere outro kit para tentar de novo.',
        );
        $this->assertSame(0, $slot->fresh()->regeneracoes);
        $this->assertSame(0, $kit->fresh()->regeneracoes);
        Queue::assertNothingPushed();
    }

    public function test_kit_aberto_sem_referencia_viva_devolve_mesma_422(): void
    {
        Queue::fake();
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $slot = $kit->slots()->where('slot_indice', 1)->first();
        // Kit continua `pronto` (aberto) — só a varredura de 48h apagou a referência do portador.
        $kit->criativoReferencia->update(['referencias_apagadas_em' => now()]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaRegenerar($kit->id, 1));

        $resp->assertStatus(422);
        $resp->assertJsonPath(
            'erros.0.mensagem',
            'Este kit já foi fechado e as fotos de referência foram apagadas — gere outro kit para tentar de novo.',
        );
        $this->assertSame(0, $slot->fresh()->regeneracoes);
        Queue::assertNothingPushed();
    }

    // ═══ Relógio por tentativa (achado (b) do 165-01) ═══════════════════

    public function test_regenerar_30_minutos_depois_com_outro_slot_aprovado_termina_pronto(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $slot2 = $kit->slots()->where('slot_indice', 2)->first();
        $admin = $this->admin();

        // Slot 1 aprovado primeiro — status do kit não é recalculado (não pode virar "gerando" por isso).
        $this->actingAs($admin)->postJson($this->rotaAprovarSlot($kit->id, 1))->assertOk();

        $this->travel(30)->minutes();

        $resp = $this->actingAs($admin)->postJson($this->rotaRegenerar($kit->id, 2));
        $resp->assertStatus(202);

        $slot2->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $slot2->status);
        $this->assertNotSame(MlAnuncioCriativoKit::STATUS_ERRO, $kit->fresh()->status);

        $status = $this->actingAs($admin)->getJson($this->rotaStatus($kit->id));
        $status->assertOk();
        $status->assertJsonPath('status', 'pronto');
    }

    // ═══ Escopo e permissão (iguais ao resto do controller) ═════════════

    public function test_regenerar_sem_permissao_devolve_403_depois_do_escopo(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        Configuracao::set(CreativePermissao::CHAVE_LISTA, (string) $this->admin()->id);
        $semPermissao = $this->admin();

        $resp = $this->actingAs($semPermissao)->postJson($this->rotaRegenerar($kit->id, 1));

        $resp->assertStatus(403);
        $this->assertSame(0, $kit->fresh()->regeneracoes);
    }

    public function test_regenerar_kit_de_outro_produto_devolve_404(): void
    {
        $produtoOutro = PubProduto::create(['mlb_empresa_id' => $this->empresa->id, 'sku' => 'OUTRO-05', 'nome' => 'Outro produto', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $rOutro = $this->repo->criar($produtoOutro, [new Alvo('gold_special', 'Outro produto')]);
        $kitOutro = MlAnuncioCriativoKit::create([
            'token' => Str::random(32),
            'status' => MlAnuncioCriativoKit::STATUS_PRONTO,
            'pub_rascunho_id' => $rOutro->id,
            'pub_grupo' => 'GENERAL',
        ]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaRegenerar($kitOutro->id, 1));

        $resp->assertStatus(404);
    }

    public function test_regenerar_com_chave_desligada_devolve_404(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        Configuracao::set('creative_engine_ativo', '0');
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaRegenerar($kit->id, 1));

        $resp->assertStatus(404);
    }

    // ═══ Aprovar o kit inteiro ═══════════════════════════════════════════

    public function test_aprovar_kit_com_minimo_de_prontas_poe_tudo_no_fim_do_grupo_e_fecha_o_kit(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaAprovarKit($kit->id));

        $resp->assertOk();
        $resp->assertJsonPath('ok', true);
        $resp->assertJsonPath('aprovadas', 3);
        $resp->assertJsonPath('falharam', []);
        $resp->assertJsonPath('kit_aprovado', true);
        $this->assertNotNull($resp->json('mensagem'));
        $this->assertNotNull($resp->json('kit'));

        $kit->refresh();
        $this->assertSame(MlAnuncioCriativoKit::STATUS_APROVADO, $kit->status);

        $doGrupo = collect(app(RascunhoRepository::class)->snapshot($this->r->fresh())->imagens)
            ->where('grupo', R::GERAL)->sortBy('posicao')->values();
        $tresUltimas = $doGrupo->slice(-3)->values();

        $slotsOrdenados = $kit->slots()->orderBy('slot_indice')->get();
        foreach ($slotsOrdenados as $i => $slot) {
            $this->assertSame((int) $tresUltimas[$i]['imagem'], (int) $slot->fresh()->pub_imagem_id);
        }

        // Retenção (CE165-09) — a referência efêmera do portador foi apagada ao fechar o kit.
        $this->assertSame([], $kit->criativoReferencia->fresh()->referenciasVivas());

        $atual = $this->actingAs($admin)->getJson($this->rotaAtual(['grupo' => 'GENERAL']));
        $atual->assertOk();
        $atual->assertJsonPath('kit.status', MlAnuncioCriativoKit::STATUS_APROVADO);
    }

    public function test_aprovar_kit_abaixo_do_minimo_devolve_422(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $kit->slots()->where('slot_indice', 3)->first()->update(['status' => MlAnuncioCriativo::STATUS_ERRO]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaAprovarKit($kit->id));

        $resp->assertStatus(422);
        $resp->assertJsonPath('erros.0.mensagem', 'Faltam 1 imagem(ns) pronta(s) para atingir o mínimo de 3 aprovadas.');
    }

    public function test_aprovar_kit_ja_aprovado_devolve_422(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_APROVADO]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaAprovarKit($kit->id));

        $resp->assertStatus(422);
        $resp->assertJsonPath('erros.0.mensagem', 'Este kit já foi aprovado.');
    }

    public function test_aprovar_kit_com_falha_parcial_devolve_200_sem_fechar_kit(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $slotComFalha = $kit->slots()->where('slot_indice', 2)->first();
        Storage::disk('local')->delete($slotComFalha->imagem_path);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaAprovarKit($kit->id));

        // Divergência registrada no SUMMARY: `PublicadorCriativoAprovacaoService::aprovarKit()`
        // (165-03, fora do escopo deste plano) devolve `ok: true` mesmo com
        // falha parcial — `ok` ali significa "a chamada rodou", não "tudo
        // deu certo"; quem decide se o kit fechou é `kit_aprovado`/`falharam`.
        $resp->assertOk();
        $resp->assertJsonPath('ok', true);
        $resp->assertJsonPath('falharam', [2]);
        $resp->assertJsonPath('kit_aprovado', false);
        $this->assertNotSame(MlAnuncioCriativoKit::STATUS_APROVADO, $kit->fresh()->status);
    }

    public function test_aprovar_kit_sem_permissao_devolve_403_depois_do_escopo(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        Configuracao::set(CreativePermissao::CHAVE_LISTA, (string) $this->admin()->id);
        $semPermissao = $this->admin();

        $resp = $this->actingAs($semPermissao)->postJson($this->rotaAprovarKit($kit->id));

        $resp->assertStatus(403);
        $this->assertNotSame(MlAnuncioCriativoKit::STATUS_APROVADO, $kit->fresh()->status);
    }

    public function test_aprovar_kit_de_outro_produto_devolve_404(): void
    {
        $produtoOutro = PubProduto::create(['mlb_empresa_id' => $this->empresa->id, 'sku' => 'OUTRO-06', 'nome' => 'Outro produto', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $rOutro = $this->repo->criar($produtoOutro, [new Alvo('gold_special', 'Outro produto')]);
        $kitOutro = MlAnuncioCriativoKit::create([
            'token' => Str::random(32),
            'status' => MlAnuncioCriativoKit::STATUS_PRONTO,
            'pub_rascunho_id' => $rOutro->id,
            'pub_grupo' => 'GENERAL',
        ]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaAprovarKit($kitOutro->id));

        $resp->assertStatus(404);
    }
}
