<?php

namespace Tests\Feature\Publicador;

use App\Models\EstruturaAnuncio;
use App\Services\Publicador\EditorRascunhoService;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * 21/10/2026 — o Sofá Chesterfield da #459: o produto foi excluído e recriado, e o Portal religou à oferta nova os dois
 * anúncios antigos do SKU (a espera, como na planilha), guardados como "ativo". No Mercado Livre um estava pausado e o
 * outro em revisão. O editor travava os dois tipos ("No ar") e a publicação parava em "Não há o que publicar: escolha
 * Clássico, Premium ou os dois".
 *
 * Decisão do usuário: "liberar e publicar novo". Só o anúncio ATIVO no ML trava o tipo; o parado fica como aviso, e
 * o rascunho sem nenhum tipo ligado liga, ao abrir, o tipo cujo anúncio não está no ar. Sem resposta do ML, trava como
 * antes.
 */
class ReguaForaDoArTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    /** MLB → status no ML; null = o ML responde 500. */
    private ?array $noMl = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->fakeMl(['*/items?*' => fn (Request $q) => $this->noMl === null
            ? Http::response(['message' => 'erro'], 500)
            : Http::response(array_map(fn ($id) => isset($this->noMl[$id])
                ? ['code' => 200, 'body' => ['id' => $id, 'status' => $this->noMl[$id]]]
                : ['code' => 404, 'body' => ['error' => 'not_found']], explode(',', (string) $q->data()['ids'])))]);

        foreach (['classico' => 'MLB7859832002', 'premium' => 'MLB5438072035'] as $tipo => $mlb) {
            EstruturaAnuncio::create(['oferta_id' => $this->produto->oferta_id, 'tipo' => $tipo, 'status' => 'ativo', 'codigo_mlb' => $mlb, 'titulo' => 'Sofá']);
        }
        // Como nasceu o rascunho do Sofá: os dois tipos desligados pela régua.
        $this->repo->gravarAlvos($this->r, [new Alvo('gold_special', null, false), new Alvo('gold_pro', null, false)]);
    }

    private function editor(): EditorRascunhoService
    {
        return app(EditorRascunhoService::class);
    }

    private function ativos(): array
    {
        return array_column(array_map(fn (Alvo $a) => ['lt' => $a->listingTypeId, 'ativo' => $a->ativo], $this->repo->snapshot($this->r->fresh())->alvos), 'ativo', 'lt');
    }

    public function test_os_dois_parados_no_ml_ligam_ao_abrir_e_viram_aviso(): void
    {
        $this->noMl = ['MLB7859832002' => 'under_review', 'MLB5438072035' => 'paused'];

        $this->editor()->abrir($this->produto);

        $this->assertSame(['gold_special' => true, 'gold_pro' => true], $this->ativos());
        $alvos = collect($this->editor()->estado($this->r->fresh())['alvos'])->keyBy('listing_type_id');
        $this->assertNull($alvos['gold_special']['mlb_na_regua'], 'não está no ar: não trava');
        $this->assertSame(['mlb' => 'MLB7859832002', 'status' => 'under_review'], $alvos['gold_special']['mlb_existente']);
        $this->assertSame(['mlb' => 'MLB5438072035', 'status' => 'paused'], $alvos['gold_pro']['mlb_existente']);
    }

    public function test_o_ativo_no_ml_continua_travado_e_so_o_parado_liga(): void
    {
        $this->noMl = ['MLB7859832002' => 'active', 'MLB5438072035' => 'closed'];

        $this->editor()->abrir($this->produto);

        $this->assertSame(['gold_special' => false, 'gold_pro' => true], $this->ativos());
        $alvos = collect($this->editor()->estado($this->r->fresh())['alvos'])->keyBy('listing_type_id');
        $this->assertSame('MLB7859832002', $alvos['gold_special']['mlb_na_regua'], 'no ar de verdade: trava, como antes');
        $this->assertNull($alvos['gold_special']['mlb_existente']);
        $this->assertSame('closed', $alvos['gold_pro']['mlb_existente']['status']);
    }

    public function test_anuncio_que_o_ml_nao_acha_tambem_libera(): void
    {
        $this->noMl = ['MLB7859832002' => 'paused'];

        $this->editor()->abrir($this->produto);

        $this->assertSame(['gold_special' => true, 'gold_pro' => true], $this->ativos());
        $this->assertSame('nao_encontrado', collect($this->editor()->estado($this->r->fresh())['alvos'])->keyBy('listing_type_id')['gold_pro']['mlb_existente']['status']);
    }

    public function test_sem_resposta_do_ml_trava_como_antes_e_nao_liga_nada(): void
    {
        $this->noMl = null;

        $this->editor()->abrir($this->produto);

        $this->assertSame(['gold_special' => false, 'gold_pro' => false], $this->ativos());
        $alvos = collect($this->editor()->estado($this->r->fresh())['alvos'])->keyBy('listing_type_id');
        $this->assertSame(['MLB7859832002', 'MLB5438072035'], [$alvos['gold_special']['mlb_na_regua'], $alvos['gold_pro']['mlb_na_regua']]);
    }

    public function test_com_um_tipo_ligado_pela_pessoa_nada_muda_ao_abrir(): void
    {
        $this->noMl = ['MLB7859832002' => 'paused', 'MLB5438072035' => 'paused'];
        $this->repo->gravarAlvos($this->r, [new Alvo('gold_special', null, true), new Alvo('gold_pro', null, false)]);

        $this->editor()->abrir($this->produto);

        $this->assertSame(['gold_special' => true, 'gold_pro' => false], $this->ativos(), 'a escolha da pessoa fica');
    }

    public function test_o_estado_do_ml_fica_guardado_e_nao_e_relido_a_cada_leitura(): void
    {
        $this->noMl = ['MLB7859832002' => 'paused', 'MLB5438072035' => 'paused'];

        $this->editor()->estado($this->r->fresh());
        $this->editor()->estado($this->r->fresh());

        $this->assertSame(1, count(Http::recorded(fn (Request $q) => str_contains($q->url(), '/items?'))));
    }
}
