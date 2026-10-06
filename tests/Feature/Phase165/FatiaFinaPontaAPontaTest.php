<?php

namespace Tests\Feature\Phase165;

use App\Models\Configuracao;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Creative\CreativePermissao;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 165, Plano 04, Task 3 — aprovar UMA imagem pelo HTTP e a fatia fina
 * ponta a ponta: planejar → atual → gerar → status → imagem → aprovar, pela
 * galeria geral e por uma variação, numa conta NÃO liberada (sem HTTP ao
 * Mercado Livre), e o gate de validação da Fase 162 também no caminho do
 * Publicador (não é regra do plano original — ver docblock do controller).
 *
 * `services.creative.validacao.ativa` desligada no `setUp()` por este mesmo
 * motivo de infraestrutura explicado em `KitIdempotenciaTest` — os testes
 * dedicados ao gate (`test_slot_com_validacao_*`) ligam/simulam o estado de
 * validação diretamente no slot, sem depender do juiz de verdade.
 */
class FatiaFinaPontaAPontaTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCriativo('mlb_empresa');
        config(['services.creative.validacao.ativa' => false]);
    }

    private function rotaAtual(array $query = []): string
    {
        return route('mlb.anuncios.publicador.criativos.atual', ['produto' => $this->produto->id, ...$query]);
    }

    private function rotaPlanejar(): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.planejar', ['produto' => $this->produto->id]);
    }

    private function rotaGerar(int $kit): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.gerar', ['produto' => $this->produto->id, 'kit' => $kit]);
    }

    private function rotaStatus(int $kit): string
    {
        return route('mlb.anuncios.publicador.criativos.kit.status', ['produto' => $this->produto->id, 'kit' => $kit]);
    }

    private function rotaImagem(int $kit, int $indice): string
    {
        return route('mlb.anuncios.publicador.criativos.slot.imagem', ['produto' => $this->produto->id, 'kit' => $kit, 'indice' => $indice]);
    }

    private function rotaAprovar(int $kit, int $indice): string
    {
        return route('mlb.anuncios.publicador.criativos.slot.aprovar', ['produto' => $this->produto->id, 'kit' => $kit, 'indice' => $indice]);
    }

    // ═══ Ponta a ponta — galeria geral ═══

    public function test_ponta_a_ponta_galeria_geral_planejar_gerar_status_imagem_aprovar(): void
    {
        $foto = $this->fotoComArquivo('GENERAL');
        $admin = $this->admin();

        $planejar = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => 'GENERAL', 'imagens' => [$foto->id]]);
        $planejar->assertStatus(202);
        $kitId = (int) $planejar->json('kit_id');

        $atual = $this->actingAs($admin)->getJson($this->rotaAtual(['grupo' => 'GENERAL']));
        $atual->assertOk();
        $atual->assertJsonPath('kit.kit_id', $kitId);

        $gerar = $this->actingAs($admin)->postJson($this->rotaGerar($kitId));
        $gerar->assertStatus(202);

        $status = $this->actingAs($admin)->getJson($this->rotaStatus($kitId));
        $status->assertOk();
        $slots = $status->json('slots');
        $this->assertCount(7, $slots);
        foreach ($slots as $slot) {
            $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $slot['status']);
            $this->assertNotNull($slot['imagem_url']);
        }

        $imagem = $this->actingAs($admin)->get($this->rotaImagem($kitId, 1));
        $imagem->assertOk();

        $aprovar = $this->actingAs($admin)->postJson($this->rotaAprovar($kitId, 1));
        $aprovar->assertOk();
        $aprovar->assertJsonPath('ok', true);
        $aprovar->assertJsonPath('repetida', false);
        $imagemId = $aprovar->json('imagem_id');
        $this->assertNotNull($imagemId);

        $pubImagem = PubImagem::find($imagemId);
        $this->assertNotNull($pubImagem);
        $this->assertSame(PubImagem::PENDENTE, $pubImagem->upload_status);

        // A foto entra no FIM da galeria geral.
        $doGrupo = collect(app(RascunhoRepository::class)->snapshot($this->r->fresh())->imagens)
            ->where('grupo', R::GERAL)->sortBy('posicao')->values();
        $this->assertSame((int) $imagemId, (int) $doGrupo->last()['imagem']);

        $aprovar->assertJsonPath('kit.slots.0.no_anuncio', true);
        $aprovar->assertJsonPath('kit.status', 'pronto');

        // Conta não liberada (D26) — nenhuma chamada ao Mercado Livre em todo o fluxo.
        Http::assertNothingSent();

        $kit = MlAnuncioCriativoKit::find($kitId);
        // $imagem->getContent() é o BINÁRIO da foto (bytes de imagem, não
        // JSON) — fica de fora desta checagem: bytes de imagem casam com a
        // regex de 32 caracteres por acaso, sem relação com token nenhum.
        $corpoCompleto = $planejar->getContent().$atual->getContent().$gerar->getContent().$status->getContent().$aprovar->getContent();
        foreach ($this->tokensDoKit($kit) as $token) {
            $this->assertStringNotContainsString((string) $token, $corpoCompleto);
        }
        $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z0-9])[A-Za-z0-9]{32}(?![A-Za-z0-9])/', $corpoCompleto);
    }

    // ═══ Ponta a ponta — variação de cor (D-14) ═══

    public function test_ponta_a_ponta_variacao_cor_entra_no_grupo_e_contexto_tem_atributo(): void
    {
        $grupoCor = $this->comVariacaoDeCor();
        $foto = $this->fotoComArquivo($grupoCor);
        $admin = $this->admin();

        $planejar = $this->actingAs($admin)->postJson($this->rotaPlanejar(), ['grupo' => $grupoCor, 'imagens' => [$foto->id]]);
        $planejar->assertStatus(202);
        $kitId = (int) $planejar->json('kit_id');

        $this->actingAs($admin)->postJson($this->rotaGerar($kitId))->assertStatus(202);

        $aprovar = $this->actingAs($admin)->postJson($this->rotaAprovar($kitId, 1));
        $aprovar->assertOk();
        $imagemId = (int) $aprovar->json('imagem_id');

        $doGrupo = collect(app(RascunhoRepository::class)->snapshot($this->r->fresh())->imagens)
            ->where('grupo', $grupoCor)->values();
        $this->assertTrue($doGrupo->contains(fn ($a) => (int) $a['imagem'] === $imagemId));

        $slot = MlAnuncioCriativoKit::find($kitId)->slots()->where('slot_indice', 1)->first();
        $this->assertSame('Azul', $slot->contexto['atributos']['COLOR'] ?? null);
    }

    // ═══ D-12 — aprovar de novo o mesmo índice não paga de novo ═══

    public function test_aprovar_de_novo_o_mesmo_indice_devolve_repetida_true_sem_pub_imagem_nova(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $admin = $this->admin();

        $primeira = $this->actingAs($admin)->postJson($this->rotaAprovar($kit->id, 1));
        $primeira->assertOk();
        $totalAntes = PubImagem::count();

        $segunda = $this->actingAs($admin)->postJson($this->rotaAprovar($kit->id, 1));
        $segunda->assertOk();
        $segunda->assertJsonPath('repetida', true);
        $this->assertSame($totalAntes, PubImagem::count());
    }

    // ═══ Recusas ═══

    public function test_slot_pendente_devolve_422(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $kit->slots()->first()->update(['status' => MlAnuncioCriativo::STATUS_PENDENTE]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaAprovar($kit->id, 1));

        $resp->assertStatus(422);
        $resp->assertJsonPath('erros.0.mensagem', 'Esta imagem ainda não está pronta para ser usada.');
    }

    public function test_rascunho_publishing_devolve_422(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $this->r->update(['status' => PubRascunho::PUBLISHING]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaAprovar($kit->id, 1));

        $resp->assertStatus(422);
        $resp->assertJsonPath('erros.0.mensagem', 'Este anúncio já está publicado (ou sendo publicado) — as fotos não mudam mais por aqui.');
    }

    public function test_sem_permissao_devolve_403_depois_do_escopo(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $totalAntes = PubImagem::count();
        $semPermissao = $this->admin();
        Configuracao::set(CreativePermissao::CHAVE_LISTA, (string) $this->admin()->id);

        $resp = $this->actingAs($semPermissao)->postJson($this->rotaAprovar($kit->id, 1));

        $resp->assertStatus(403);
        $this->assertSame($totalAntes, PubImagem::count());
    }

    public function test_kit_de_outro_produto_devolve_404(): void
    {
        $produtoOutro = PubProduto::create(['mlb_empresa_id' => $this->empresa->id, 'sku' => 'OUTRO-04', 'nome' => 'Outro produto', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $rOutro = $this->repo->criar($produtoOutro, [new Alvo('gold_special', 'Outro produto')]);
        $kitOutro = MlAnuncioCriativoKit::create([
            'token' => Str::random(32),
            'status' => MlAnuncioCriativoKit::STATUS_PRONTO,
            'pub_rascunho_id' => $rOutro->id,
            'pub_grupo' => 'GENERAL',
        ]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaAprovar($kitOutro->id, 1));

        $resp->assertStatus(404);
    }

    // ═══ Fase 162 — o gate de validação também vale no caminho do Publicador ═══
    // (código manda sobre o plano, escrito antes da Fase 162 mergear — ver
    // docblock de `MlbPublicadorCriativoController::aprovar()`.)

    public function test_slot_com_validacao_reprovada_exige_confirmar_risco(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $totalAntes = PubImagem::count();
        $slot = $kit->slots()->first();
        $slot->update([
            'validacao_status' => MlAnuncioCriativo::VALIDACAO_REPROVADA,
            'validacao' => ['mensagem' => 'Risco: o produto mudou de cor na imagem gerada.'],
        ]);
        $admin = $this->admin();

        $semConfirmar = $this->actingAs($admin)->postJson($this->rotaAprovar($kit->id, $slot->slot_indice));
        $semConfirmar->assertStatus(422);
        $semConfirmar->assertJsonPath('erros.0.mensagem', 'Risco: o produto mudou de cor na imagem gerada.');
        $this->assertSame($totalAntes, PubImagem::count());

        $comConfirmar = $this->actingAs($admin)->postJson($this->rotaAprovar($kit->id, $slot->slot_indice), ['confirmar_risco' => true]);
        $comConfirmar->assertOk();
        $this->assertSame($totalAntes + 1, PubImagem::count());
        $this->assertNotNull($slot->fresh()->validacao['override']['user_id'] ?? null);
    }

    public function test_slot_com_validacao_pendente_devolve_422(): void
    {
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $totalAntes = PubImagem::count();
        $slot = $kit->slots()->first();
        $slot->update(['validacao_status' => MlAnuncioCriativo::VALIDACAO_PENDENTE, 'validacao_pedida_em' => now()]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaAprovar($kit->id, $slot->slot_indice));

        $resp->assertStatus(422);
        $resp->assertJsonPath('erros.0.mensagem', 'A validação automática desta imagem ainda está em andamento. Aguarde alguns segundos e tente de novo.');
        $this->assertSame($totalAntes, PubImagem::count());
    }

    public function test_slot_aprovado_sendo_readicionado_nao_passa_de_novo_pela_validacao(): void
    {
        // D-12: um slot já aprovado cuja foto saiu do grupo pode ser readicionado
        // sem passar de novo pela validação (ela já rodou/foi confirmada na
        // primeira aprovação) — mesmo com validacao_status=reprovada gravado.
        $kit = $this->kitProntoDoPublicador('GENERAL', 3);
        $slot = $kit->slots()->first();
        $slot->update([
            'status' => MlAnuncioCriativo::STATUS_APROVADO,
            'validacao_status' => MlAnuncioCriativo::VALIDACAO_REPROVADA,
            'pub_imagem_id' => null,
        ]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin)->postJson($this->rotaAprovar($kit->id, $slot->slot_indice));

        $resp->assertOk();
        $resp->assertJsonPath('repetida', false);
    }
}
