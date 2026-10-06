<?php

namespace Tests\Feature\Phase165;

use App\Models\MlAnuncioCriativoKit;
use App\Services\Publicador\Criativos\PublicadorCriativoAprovacaoService;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 165, Plano 03, Task 2 (CE165-09) — a retenção da foto de referência
 * efêmera do portador continua valendo para o kit do Publicador: some ao
 * fechar o kit inteiro, nunca na aprovação de um slot isolado, e a varredura
 * diária (`creative:limpar-referencias`) recolhe o que sobrou.
 */
class RetencaoDoPublicadorTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    private function servico(): PublicadorCriativoAprovacaoService
    {
        return app(PublicadorCriativoAprovacaoService::class);
    }

    public function test_fechar_o_kit_apaga_a_referencia_efemera_do_portador(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $kit = $this->kitProntoDoPublicador(R::GERAL, 3);
        $portador = $kit->criativoReferencia;
        $diretorio = "creative-referencias/{$portador->token}";
        Storage::disk('local')->assertExists($diretorio.'/0.jpg');

        $res = $this->servico()->aprovarKit($this->r->fresh(), $kit->fresh(), $this->admin());

        $this->assertTrue($res['kit_aprovado']);
        $this->assertFalse(Storage::disk('local')->exists($diretorio), 'o diretório da referência efêmera some ao fechar o kit');
        $this->assertNotNull($portador->fresh()->referencias_apagadas_em);
    }

    public function test_slot_sem_arquivo_falha_e_o_kit_nao_fecha_a_referencia_continua(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $kit = $this->kitProntoDoPublicador(R::GERAL, 3);
        $portador = $kit->criativoReferencia;
        $diretorio = "creative-referencias/{$portador->token}";
        $slotSemArquivo = $kit->slots()->first();
        Storage::disk('local')->delete($slotSemArquivo->imagem_path);

        $res = $this->servico()->aprovarKit($this->r->fresh(), $kit->fresh(), $this->admin());

        $this->assertFalse($res['kit_aprovado']);
        $this->assertSame([$slotSemArquivo->slot_indice], $res['falharam']);
        $this->assertSame(MlAnuncioCriativoKit::STATUS_PRONTO, $kit->fresh()->status, 'falha parcial nunca muda o status do kit');
        $this->assertTrue(Storage::disk('local')->exists($diretorio), 'a referência continua — o kit não fechou');
        $this->assertNull($portador->fresh()->referencias_apagadas_em);
    }

    public function test_aprovar_um_slot_isolado_nunca_apaga_a_referencia_do_portador(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $kit = $this->kitProntoDoPublicador(R::GERAL, 3);
        $portador = $kit->criativoReferencia;
        $diretorio = "creative-referencias/{$portador->token}";
        $slot = $kit->slots()->first();

        $this->servico()->aprovarSlot($this->r->fresh(), $slot, $kit->pub_grupo, $this->admin());

        $this->assertTrue(Storage::disk('local')->exists($diretorio), 'aprovação de UM slot isolado nunca apaga a referência (ela ainda serve às regenerações dos outros)');
        $this->assertNull($portador->fresh()->referencias_apagadas_em);
    }

    public function test_varredura_diaria_recolhe_a_referencia_do_portador_do_publicador_apos_48h(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $portador = $this->portadorDoPublicador(R::GERAL);
        $this->travel(49)->hours();

        $diretorio = "creative-referencias/{$portador->token}";
        Storage::disk('local')->assertExists($diretorio.'/0.jpg');

        $this->artisan('creative:limpar-referencias')->assertExitCode(0);

        $this->assertFalse(Storage::disk('local')->exists($diretorio));
        $this->assertNotNull($portador->fresh()->referencias_apagadas_em);
    }
}
