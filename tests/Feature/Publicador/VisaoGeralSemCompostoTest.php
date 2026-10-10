<?php

namespace Tests\Feature\Publicador;

use App\Models\EstruturaOferta;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\TestCase;

/**
 * Planejamento × Fase N, item C na Visão geral (decisões do usuário de 09/10/2026): o composto do
 * Planejamento (produto ligado a Combo/Kit/Combit do Portal) publicado NÃO está "Pronto para a Fase 2",
 * não conta como "Fase 1" no topo ("no ar por fase") e aparece com o rótulo do tipo nas últimas
 * publicações. O base de verdade segue como antes.
 */
class VisaoGeralSemCompostoTest extends TestCase
{
    use CenarioPlanejamentoDaFase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        $this->montarPlanejamento();
    }

    private function publicado(PubProduto $p): void
    {
        $r = PubRascunho::where('produto_id', $p->id)->first()
            ?? PubRascunho::create(['produto_id' => $p->id, 'status' => PubRascunho::PUBLISHED, 'revisao' => 1]);
        $r->update(['status' => PubRascunho::PUBLISHED]);
        $pub = PubPublicacao::create(['rascunho_id' => $r->id, 'revisao' => 1, 'modelo_publicacao' => 'items', 'status' => 'PUBLISHED',
            'chave_idempotencia' => (string) Str::uuid(), 'concluida_em' => now()->subMinutes($p->id)]);
        PubPublicacaoItem::create(['publicacao_id' => $pub->id, 'indice' => 0, 'listing_type_id' => 'gold_special',
            'variante_chave' => ChaveCanonica::UNICA, 'status' => PubPublicacaoItem::CREATED, 'ml_item_id' => 'MLB'.(9000 + $p->id),
            'payload' => ['family_name' => 'Anúncio '.$p->sku]]);
    }

    private function visaoGeral(): array
    {
        return $this->actingAs($this->equipeP)
            ->get('/mlb/anuncios/publicador/empresas/empresa-'.$this->mlbP->id.'/visao-geral')
            ->assertOk()->viewData('page')['props'];
    }

    public function test_composto_publicado_nao_e_pronto_para_a_fase_2_nem_fase_1_no_topo(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->publicado($grupo);
        $combos = $this->aceitarCombos(2, ['Azul']);
        $avulso = PubProduto::create(['oferta_id' => $combos['Azul']->id, 'company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id,
            'sku' => $combos['Azul']->sku, 'nome' => 'Combo 2 Azul', 'origem' => PubProduto::ORIGEM_PORTAL]);
        $this->publicado($avulso);
        $kitPortal = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'sku' => 'KT-1', 'fase' => 'kit', 'nome' => 'Kit']);
        $kitPortal->componentes()->create(['componente_id' => $this->simplesP['Preto']->id, 'quantidade' => 1]);
        $kitPortal->componentes()->create(['componente_id' => $this->simplesP['Branco']->id, 'quantidade' => 1]);
        $kitPub = PubProduto::create(['oferta_id' => $kitPortal->id, 'company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id,
            'sku' => 'KT-1', 'nome' => 'Kit', 'origem' => PubProduto::ORIGEM_PORTAL]);
        $this->publicado($kitPub);

        $props = $this->visaoGeral();
        $linhas = collect($props['oQueFazerAgora'])->keyBy('texto');

        $this->assertSame(1, $linhas['Prontos para a Fase 2']['numero'], 'só o grupo das três cores espera a Fase 2');
        $this->assertSame(['fase1' => 1, 'kits' => 0], $props['indicadores']['no_ar_por_fase']);

        $rotulos = collect($props['ultimasPublicacoes']['itens'])->pluck('rotulo_fase', 'ml_item_id');
        $this->assertSame('1 unidade', $rotulos['MLB'.(9000 + $grupo->id)]);
        $this->assertSame('Combo do Planejamento', $rotulos['MLB'.(9000 + $avulso->id)]);
        $this->assertSame('Kit do Planejamento', $rotulos['MLB'.(9000 + $kitPub->id)]);
    }

    public function test_sem_composto_a_visao_geral_segue_como_antes(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->publicado($grupo);
        $kit = $this->kitAntigo($grupo, 2);
        $this->publicado($kit);

        $props = $this->visaoGeral();

        $this->assertFalse(collect($props['oQueFazerAgora'])->contains('texto', 'Prontos para a Fase 2'), 'o base já tem Kit 2');
        $this->assertSame(['fase1' => 1, 'kits' => 1], $props['indicadores']['no_ar_por_fase']);
        $this->assertSame('Kit 2', collect($props['ultimasPublicacoes']['itens'])->firstWhere('ml_item_id', 'MLB'.(9000 + $kit->id))['rotulo_fase']);
    }
}
