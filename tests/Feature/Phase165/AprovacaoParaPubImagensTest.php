<?php

namespace Tests\Feature\Phase165;

use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\PubImagem;
use App\Models\PubImagemAtribuicao;
use App\Models\PubRascunho;
use App\Services\Creative\CreativeKitPublicacao;
use App\Services\Mlb\Publicacao\MlImagemService;
use App\Services\Publicador\Criativos\PublicadorCriativoAprovacaoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 165, Plano 03, Task 2 — `PublicadorCriativoAprovacaoService`: a
 * imagem aprovada vira foto do rascunho do Publicador (D-04/D-11/D-12), e
 * nunca o caminho do assistente antigo (T-165-11/T-165-12).
 */
class AprovacaoParaPubImagensTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    /** Contador para o lado da imagem fake — garante bytes (e sha256) únicos entre chamadas. */
    private int $fotoSeq = 0;

    private function servico(): PublicadorCriativoAprovacaoService
    {
        return app(PublicadorCriativoAprovacaoService::class);
    }

    /**
     * `montarCenarioCriativo()` já cria 1 foto na galeria geral (a cadeira do
     * cenário, `CenarioCadeira::montarCenario()`) — ela não interessa aos
     * testes desta classe, que contam atribuições do ZERO.
     */
    private function cenario(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        PubImagemAtribuicao::query()->delete();
        PubImagem::query()->delete();
    }

    /** Uma foto com arquivo em disco já atribuída no FIM do grupo pedido, com conteúdo único. */
    private function fotoExistenteNoGrupo(string $grupo): PubImagem
    {
        $bytes = self::jpeg(900 + (++$this->fotoSeq));
        $sha = hash('sha256', $bytes);
        $caminho = "publicador/{$this->r->id}/{$sha}.jpg";
        Storage::disk('local')->put($caminho, $bytes);
        $foto = $this->r->imagens()->create([
            'caminho' => $caminho, 'sha256' => $sha, 'mime' => 'image/jpeg', 'bytes' => strlen($bytes),
            'largura' => 900, 'altura' => 900, 'upload_status' => PubImagem::PENDENTE,
        ]);

        $atuais = app(RascunhoRepository::class)->snapshot($this->r->fresh())->imagens;
        $doGrupo = array_values(array_filter($atuais, fn ($a) => $a['grupo'] === $grupo));
        app(RascunhoRepository::class)->gravarAtribuicoes($this->r->fresh(), [
            ...$atuais, ['imagem' => $foto->id, 'grupo' => $grupo, 'posicao' => count($doGrupo)],
        ]);

        return $foto->fresh();
    }

    private function doGrupo(string $grupo): array
    {
        return collect(app(RascunhoRepository::class)->snapshot($this->r->fresh())->imagens)
            ->where('grupo', $grupo)->sortBy('posicao')->values()->all();
    }

    public function test_aprovar_slot_pronto_cria_pub_imagem_pending_e_poe_no_fim_do_geral(): void
    {
        $this->cenario();
        $a = $this->fotoExistenteNoGrupo(R::GERAL);
        $b = $this->fotoExistenteNoGrupo(R::GERAL);
        $kit = $this->kitProntoDoPublicador(R::GERAL, 1);
        $slot = $kit->slots()->first();
        $admin = $this->admin();

        $res = $this->servico()->aprovarSlot($this->r->fresh(), $slot, $kit->pub_grupo, $admin);

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['repetida']);
        $this->assertNull($res['mensagem']);
        $this->assertNotNull($res['imagem_id']);

        $img = PubImagem::find($res['imagem_id']);
        $this->assertSame(PubImagem::PENDENTE, $img->upload_status, 'D26: conta não liberada não recebe foto');
        $this->assertStringStartsWith("publicador/{$this->r->id}/", $img->caminho);
        Http::assertNothingSent();

        $doGeral = $this->doGrupo(R::GERAL);
        $this->assertSame([(string) $a->id, (string) $b->id, (string) $img->id], array_column($doGeral, 'imagem'));
        $this->assertSame([0, 1, 2], array_column($doGeral, 'posicao'));

        $slot->refresh();
        $this->assertSame(MlAnuncioCriativo::STATUS_APROVADO, $slot->status);
        $this->assertSame($admin->id, $slot->aprovado_por);
        $this->assertNotNull($slot->aprovado_em);
        $this->assertSame($img->id, $slot->pub_imagem_id);
        $this->assertNull($slot->ml_picture_id);
        $this->assertNull($slot->ml_picture_url);
    }

    public function test_kit_do_grupo_da_variacao_poe_a_foto_no_grupo_da_cor_nao_no_geral(): void
    {
        $this->cenario();
        $grupoCor = $this->comVariacaoDeCor();
        $kit = $this->kitProntoDoPublicador($grupoCor, 1);
        $slot = $kit->slots()->first();

        $res = $this->servico()->aprovarSlot($this->r->fresh(), $slot, $kit->pub_grupo, $this->admin());

        $this->assertTrue($res['ok']);
        $this->assertSame([(string) $res['imagem_id']], array_column($this->doGrupo($grupoCor), 'imagem'));
        $this->assertSame([], $this->doGrupo(R::GERAL), 'a galeria geral não recebeu nada');
    }

    public function test_conta_liberada_a_pub_imagem_sobe_pelo_caminho_de_sempre(): void
    {
        $this->cenario();
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => [$this->empresa->id]]]);
        $kit = $this->kitProntoDoPublicador(R::GERAL, 1);
        $slot = $kit->slots()->first();

        Http::fake(['*/pictures/items/upload' => Http::response(['id' => 'PIC-1', 'variations' => [['secure_url' => 'https://http2.mlstatic.com/D_1-O.jpg']]])]);

        $res = $this->servico()->aprovarSlot($this->r->fresh(), $slot, $kit->pub_grupo, $this->admin());

        $img = PubImagem::find($res['imagem_id']);
        $this->assertSame(PubImagem::ENVIADA, $img->upload_status);
        $this->assertSame('PIC-1', $img->ml_picture_id);
    }

    public function test_nunca_chama_o_caminho_do_assistente_antigo(): void
    {
        $this->cenario();
        $kit = $this->kitProntoDoPublicador(R::GERAL, 1);
        $slot = $kit->slots()->first();

        $this->mock(MlImagemService::class)->shouldNotReceive('enviar');
        $this->mock(CreativeKitPublicacao::class)->shouldNotReceive('aplicarPictures');

        $res = $this->servico()->aprovarSlot($this->r->fresh(), $slot, $kit->pub_grupo, $this->admin());

        $this->assertTrue($res['ok']);
        $this->assertSame(0, MlAnuncioRascunho::count());
    }

    public function test_rascunho_publishing_recusa_a_aprovacao(): void
    {
        $this->cenario();
        $kit = $this->kitProntoDoPublicador(R::GERAL, 1);
        $slot = $kit->slots()->first();
        $this->r->update(['status' => PubRascunho::PUBLISHING]);

        $res = $this->servico()->aprovarSlot($this->r->fresh(), $slot, $kit->pub_grupo, $this->admin());

        $this->assertFalse($res['ok']);
        $this->assertSame('Este anúncio já está publicado (ou sendo publicado) — as fotos não mudam mais por aqui.', $res['mensagem']);
        $this->assertSame(0, PubImagem::count());
    }

    public function test_rascunho_published_recusa_a_aprovacao(): void
    {
        $this->cenario();
        $kit = $this->kitProntoDoPublicador(R::GERAL, 1);
        $slot = $kit->slots()->first();
        $this->r->update(['status' => PubRascunho::PUBLISHED]);

        $res = $this->servico()->aprovarSlot($this->r->fresh(), $slot, $kit->pub_grupo, $this->admin());

        $this->assertFalse($res['ok']);
        $this->assertSame(0, PubImagem::count());
    }

    public function test_slot_nao_pronto_recusa(): void
    {
        $this->cenario();
        $kit = $this->kitProntoDoPublicador(R::GERAL, 1);
        $slot = $kit->slots()->first();

        foreach ([MlAnuncioCriativo::STATUS_PENDENTE, MlAnuncioCriativo::STATUS_RODANDO, MlAnuncioCriativo::STATUS_ERRO] as $status) {
            $slot->update(['status' => $status]);
            $res = $this->servico()->aprovarSlot($this->r->fresh(), $slot->fresh(), $kit->pub_grupo, $this->admin());
            $this->assertFalse($res['ok'], "status {$status} deveria recusar");
            $this->assertSame('Esta imagem ainda não está pronta para ser usada.', $res['mensagem']);
        }
    }

    public function test_arquivo_sumido_do_disco_recusa_e_slot_continua_pronto(): void
    {
        $this->cenario();
        $kit = $this->kitProntoDoPublicador(R::GERAL, 1);
        $slot = $kit->slots()->first();
        Storage::disk('local')->delete($slot->imagem_path);

        $res = $this->servico()->aprovarSlot($this->r->fresh(), $slot, $kit->pub_grupo, $this->admin());

        $this->assertFalse($res['ok']);
        $this->assertSame('A imagem gerada deste criativo não foi encontrada.', $res['mensagem']);
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $slot->fresh()->status);
    }

    public function test_imagem_pequena_recusa_com_a_mensagem_da_l1_e_slot_continua_pronto(): void
    {
        $this->cenario();
        $kit = $this->kitProntoDoPublicador(R::GERAL, 1);
        $slot = $kit->slots()->first();
        Storage::disk('local')->put($slot->imagem_path, self::jpeg(300));

        $res = $this->servico()->aprovarSlot($this->r->fresh(), $slot, $kit->pub_grupo, $this->admin());

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('300×300', $res['mensagem']);
        $this->assertSame(MlAnuncioCriativo::STATUS_PRONTO, $slot->fresh()->status);
        $this->assertSame(0, PubImagem::count());
    }

    public function test_aprovar_a_mesma_imagem_duas_vezes_e_repetida(): void
    {
        $this->cenario();
        $kit = $this->kitProntoDoPublicador(R::GERAL, 1);
        $slot = $kit->slots()->first();

        $primeiro = $this->servico()->aprovarSlot($this->r->fresh(), $slot->fresh(), $kit->pub_grupo, $this->admin());
        $this->assertFalse($primeiro['repetida']);

        $segundo = $this->servico()->aprovarSlot($this->r->fresh(), $slot->fresh(), $kit->pub_grupo, $this->admin());

        $this->assertTrue($segundo['ok']);
        $this->assertTrue($segundo['repetida']);
        $this->assertSame(1, PubImagem::count());
        $this->assertSame(1, PubImagemAtribuicao::count());
    }

    public function test_d12_slot_aprovado_sem_a_foto_no_rascunho_pode_ser_posto_de_novo_sem_pagar(): void
    {
        $this->cenario();
        $kit = $this->kitProntoDoPublicador(R::GERAL, 1);
        $slot = $kit->slots()->first();
        $primeiro = $this->servico()->aprovarSlot($this->r->fresh(), $slot->fresh(), $kit->pub_grupo, $this->admin());
        $imagemOriginal = PubImagem::find($primeiro['imagem_id']);

        // A foto some do rascunho (removida), mas o arquivo gerado continua em disco.
        $imagemOriginal->delete();
        $this->assertTrue(Storage::disk('local')->exists($slot->fresh()->imagem_path), 'o arquivo gerado nunca é apagado pela aprovação');

        $res = $this->servico()->aprovarSlot($this->r->fresh(), $slot->fresh(), $kit->pub_grupo, $this->admin());

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['repetida']);
        $this->assertNotSame($primeiro['imagem_id'], $res['imagem_id'], 'a PubImagem antiga foi removida; uma nova foi criada no lugar');
        $this->assertSame($res['imagem_id'], $slot->fresh()->pub_imagem_id);
        $this->assertSame(1, PubImagem::count());
    }

    public function test_d12_slot_aprovado_cuja_atribuicao_saiu_do_grupo_pode_ser_posto_de_novo(): void
    {
        $this->cenario();
        $kit = $this->kitProntoDoPublicador(R::GERAL, 1);
        $slot = $kit->slots()->first();
        $primeiro = $this->servico()->aprovarSlot($this->r->fresh(), $slot->fresh(), $kit->pub_grupo, $this->admin());

        // A foto some do GRUPO (ex.: o operador tirou dali no editor) mas a PubImagem continua existindo.
        PubImagemAtribuicao::where('imagem_id', $primeiro['imagem_id'])->delete();
        $this->assertFalse($this->servico()->noAnuncio($slot->fresh(), $kit->pub_grupo));

        $res = $this->servico()->aprovarSlot($this->r->fresh(), $slot->fresh(), $kit->pub_grupo, $this->admin());

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['repetida']);
        $this->assertSame($primeiro['imagem_id'], $res['imagem_id'], 'dedupe por sha256: a MESMA PubImagem volta, sem pagar de novo');
        $this->assertSame(1, PubImagem::count());
        $this->assertSame([(string) $primeiro['imagem_id']], array_column($this->doGrupo($kit->pub_grupo), 'imagem'));
    }

    public function test_nao_trunca_general_com_nove_fotos_aprovar_tres_slots(): void
    {
        $this->cenario();
        for ($i = 0; $i < 9; $i++) {
            $this->fotoExistenteNoGrupo(R::GERAL);
        }
        $kit = $this->kitProntoDoPublicador(R::GERAL, 3);

        foreach ($kit->slots()->get() as $slot) {
            $res = $this->servico()->aprovarSlot($this->r->fresh(), $slot, $kit->pub_grupo, $this->admin());
            $this->assertTrue($res['ok']);
        }

        $doGeral = $this->doGrupo(R::GERAL);
        $this->assertCount(12, $doGeral);
        $this->assertSame(range(0, 11), array_column($doGeral, 'posicao'));
    }

    public function test_no_anuncio_so_e_verdade_com_pub_imagem_id_e_atribuicao_no_grupo(): void
    {
        $this->cenario();
        $kit = $this->kitProntoDoPublicador(R::GERAL, 1);
        $slot = $kit->slots()->first();

        $this->assertFalse($this->servico()->noAnuncio($slot->fresh(), $kit->pub_grupo), 'ainda não aprovado');

        $this->servico()->aprovarSlot($this->r->fresh(), $slot->fresh(), $kit->pub_grupo, $this->admin());

        $this->assertTrue($this->servico()->noAnuncio($slot->fresh(), $kit->pub_grupo));
        $this->assertFalse($this->servico()->noAnuncio($slot->fresh(), 'OUTRO-GRUPO'), 'outro grupo não conta');
    }

    public function test_aprovar_kit_com_tres_prontos_e_minimo_tres_fecha_o_kit(): void
    {
        $this->cenario();
        $kit = $this->kitProntoDoPublicador(R::GERAL, 3);
        $admin = $this->admin();

        $res = $this->servico()->aprovarKit($this->r->fresh(), $kit->fresh(), $admin);

        $this->assertTrue($res['ok']);
        $this->assertSame(3, $res['aprovadas']);
        $this->assertSame([], $res['falharam']);
        $this->assertTrue($res['kit_aprovado']);

        $kit->refresh();
        $this->assertSame(MlAnuncioCriativoKit::STATUS_APROVADO, $kit->status);
        $this->assertSame($admin->id, $kit->aprovado_por);
        $this->assertNotNull($kit->aprovado_em);

        $doGrupo = $this->doGrupo($kit->pub_grupo);
        $slotsPorIndice = $kit->slots()->orderBy('slot_indice')->pluck('pub_imagem_id')->map(fn ($id) => (string) $id)->all();
        $this->assertSame($slotsPorIndice, array_column($doGrupo, 'imagem'));
    }

    // Quick 261007-kit2 (2026-10-07): minimo_aprovadas DEIXOU DE BLOQUEAR —
    // mesmo com o valor congelado em 3 (como um kit antigo, da época do kit
    // de 7), 2 prontas bastam para aprovar o kit inteiro.
    public function test_aprovar_kit_com_dois_prontos_e_minimo_tres_congelado_nao_bloqueia_mais(): void
    {
        $this->cenario();
        $kit = $this->kitProntoDoPublicador(R::GERAL, 2);
        $kit->update(['minimo_aprovadas' => 3]);

        $res = $this->servico()->aprovarKit($this->r->fresh(), $kit->fresh(), $this->admin());

        $this->assertTrue($res['ok']);
        $this->assertSame(2, $res['aprovadas']);
        $this->assertSame([], $res['falharam']);
        $this->assertTrue($res['kit_aprovado']);
        $this->assertSame(2, PubImagem::count());

        $kit->refresh();
        $this->assertSame(MlAnuncioCriativoKit::STATUS_APROVADO, $kit->status);
    }

    public function test_aprovar_kit_ja_aprovado_recusa(): void
    {
        $this->cenario();
        $kit = $this->kitProntoDoPublicador(R::GERAL, 3);
        $kit->update(['status' => MlAnuncioCriativoKit::STATUS_APROVADO]);

        $res = $this->servico()->aprovarKit($this->r->fresh(), $kit->fresh(), $this->admin());

        $this->assertFalse($res['ok']);
        $this->assertSame('Este kit já foi aprovado.', $res['mensagem']);
    }
}
