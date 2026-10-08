<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\MlbEmpresa;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\PublicadorSincronizaPortalService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Fase 172-03 (D-06): as cores de um produto do Portal viram UM produto no Publicador. */
class SincronizaPortalAgrupamentoTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(Company $c): MlbEmpresa
    {
        return MlbEmpresa::create(['nome' => 'Polo X', 'projeto' => 'POLOS', 'company_id' => $c->id])->fresh();
    }

    /** @return array{0: EstruturaProduto, 1: list<EstruturaOferta>} */
    private function produtoComCores(Company $c, int $n = 3, string $codigo = 'CAD'): array
    {
        $p = EstruturaProduto::create(['company_id' => $c->id, 'codigo' => $codigo, 'nome' => 'Cadeira '.$codigo]);
        $ofertas = [];
        for ($i = 1; $i <= $n; $i++) {
            $v = EstruturaProdutoVariacao::create([
                'produto_id' => $p->id, 'company_id' => $c->id, 'ordem' => $i, 'codigo' => $codigo.'-'.$i, 'eixo' => 'cor', 'valor' => 'Cor '.$i,
            ]);
            $ofertas[] = EstruturaOferta::create([
                'company_id' => $c->id, 'variacao_id' => $v->id, 'sku' => $codigo.'-'.$i, 'fase' => 'simples', 'nome' => 'Cadeira Cor '.$i,
            ]);
        }

        return [$p, $ofertas];
    }

    private function legado(EstruturaOferta $o, Company $c, ?MlbEmpresa $e = null): PubProduto
    {
        return PubProduto::create([
            'oferta_id' => $o->id, 'company_id' => $c->id, 'mlb_empresa_id' => $e?->id,
            'sku' => $o->sku, 'nome' => $o->nome, 'origem' => 'portal',
        ]);
    }

    private function publicar(PubRascunho $r): void
    {
        $pub = PubPublicacao::create(['rascunho_id' => $r->id, 'revisao' => 1, 'modelo_publicacao' => 'items', 'status' => 'PUBLISHED',
            'chave_idempotencia' => (string) Str::uuid(), 'concluida_em' => now()]);
        PubPublicacaoItem::create(['publicacao_id' => $pub->id, 'indice' => 0, 'listing_type_id' => 'gold_special',
            'variante_chave' => ChaveCanonica::UNICA, 'status' => PubPublicacaoItem::CREATED, 'ml_item_id' => 'MLB1']);
    }

    private function sinc(MlbEmpresa $e, Company $c): array
    {
        return app(PublicadorSincronizaPortalService::class)->sincronizar($e, $c);
    }

    public function test_tres_cores_viram_um_produto_ancorado_na_primeira_variacao_e_reexecutar_nao_cria(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [$p, $ofertas] = $this->produtoComCores($c);

        $r = $this->sinc($e, $c);

        $this->assertSame(1, $r['criados']);
        $this->assertSame(1, PubProduto::count());
        $g = PubProduto::first();
        $this->assertSame($p->id, $g->estrutura_produto_id);
        $this->assertSame($ofertas[0]->id, $g->oferta_id);
        $this->assertSame([$g->id], $r['para_preencher']);

        $r2 = $this->sinc($e, $c);
        $this->assertSame(0, $r2['criados']);
        $this->assertSame(1, PubProduto::count());
        $this->assertSame([$g->id], $r2['para_preencher']);
    }

    public function test_legado_com_rascunho_sem_publicacao_e_adotado_sem_apagar_nada(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [$p, $ofertas] = $this->produtoComCores($c);
        $l1 = $this->legado($ofertas[0], $c, $e);
        $l2 = $this->legado($ofertas[1], $c, $e);
        $rasc = PubRascunho::create(['produto_id' => $l2->id, 'status' => PubRascunho::VALIDATED]);

        $r = $this->sinc($e, $c);

        $this->assertSame(0, $r['criados']);
        $this->assertSame([$l2->id], $r['adotados']);
        $this->assertSame(2, PubProduto::count());
        $l2->refresh();
        $this->assertSame($p->id, $l2->estrutura_produto_id);
        $this->assertSame($ofertas[1]->id, $l2->oferta_id);
        $this->assertSame($ofertas[1]->sku, $l2->sku);
        $this->assertSame($l2->id, $rasc->fresh()->produto_id);
        $this->assertNull($l1->fresh()->estrutura_produto_id);
        $this->assertSame([['produto_id' => $p->id, 'pub_produto_ids' => [$l1->id]]], $r['duplicados']);
    }

    public function test_legado_publicado_nao_e_adotado_e_o_grupo_nasce_separado_com_aviso(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [$p, $ofertas] = $this->produtoComCores($c);
        $l1 = $this->legado($ofertas[0], $c, $e);
        $rasc = PubRascunho::create(['produto_id' => $l1->id, 'status' => PubRascunho::VALIDATED]);
        $this->publicar($rasc);

        $r = $this->sinc($e, $c);

        $this->assertSame([], $r['adotados']);
        $this->assertSame(1, $r['criados']);
        $this->assertNull($l1->fresh()->estrutura_produto_id);
        $g = PubProduto::where('estrutura_produto_id', $p->id)->firstOrFail();
        $this->assertSame($ofertas[1]->id, $g->oferta_id);
        $this->assertNotSame($l1->id, $g->id);
        $this->assertNotEmpty($r['avisos']);
        $this->assertStringContainsString($p->nome, $r['avisos'][0]);
    }

    public function test_todas_as_ofertas_com_legado_publicado_nao_criam_grupo(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [$p, $ofertas] = $this->produtoComCores($c, 2);
        foreach ($ofertas as $o) {
            $l = $this->legado($o, $c, $e);
            $this->publicar(PubRascunho::create(['produto_id' => $l->id, 'status' => PubRascunho::VALIDATED]));
        }

        $r = $this->sinc($e, $c);

        $this->assertSame(0, $r['criados']);
        $this->assertSame(2, PubProduto::count());
        $this->assertSame(0, PubProduto::whereNotNull('estrutura_produto_id')->count());
        $this->assertStringContainsString('já foram publicadas como anúncios avulsos', $r['avisos'][0]);
    }

    public function test_legados_sem_rascunho_de_outras_cores_aparecem_em_duplicados(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [$p, $ofertas] = $this->produtoComCores($c);
        $l1 = $this->legado($ofertas[0], $c, $e);
        $l2 = $this->legado($ofertas[1], $c, $e);
        $l3 = $this->legado($ofertas[2], $c, $e);

        $r = $this->sinc($e, $c);

        // Sem rascunho: adota o da oferta âncora (1ª cor).
        $this->assertSame([$l1->id], $r['adotados']);
        $this->assertSame([$l2->id, $l3->id], $r['duplicados'][0]['pub_produto_ids']);
        $this->assertSame(3, PubProduto::count());
    }

    public function test_combo_kit_combit_e_simples_sem_variacao_seguem_um_produto_por_oferta(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        foreach (['combo', 'kit', 'combit'] as $fase) {
            EstruturaOferta::create(['company_id' => $c->id, 'sku' => strtoupper($fase), 'fase' => $fase, 'nome' => $fase]);
        }
        EstruturaOferta::create(['company_id' => $c->id, 'sku' => 'ANTIGA', 'fase' => 'simples', 'nome' => 'Antiga']);

        $r = $this->sinc($e, $c);

        $this->assertSame(4, $r['criados']);
        $this->assertSame(0, PubProduto::whereNotNull('estrutura_produto_id')->count());
        // Só as compostas entram em para_preencher.
        $this->assertCount(3, $r['para_preencher']);
    }

    public function test_nao_le_produto_nem_oferta_de_outra_empresa(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $e = $this->empresa($a);
        [$pa] = $this->produtoComCores($a, 2, 'AAA');
        [$pb] = $this->produtoComCores($b, 2, 'BBB');
        EstruturaOferta::create(['company_id' => $b->id, 'sku' => 'KB', 'fase' => 'kit', 'nome' => 'Kit B']);

        $r = $this->sinc($e, $a);

        $this->assertSame(1, $r['criados']);
        $this->assertSame(0, PubProduto::where('company_id', $b->id)->count());
        $this->assertNull(PubProduto::where('estrutura_produto_id', $pb->id)->first());
        $this->assertCount(1, $r['para_preencher']);
    }

    public function test_corrida_no_unique_do_produto_nao_lanca_e_relê_o_grupo(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [$p, $ofertas] = $this->produtoComCores($c);

        $disparou = false;
        PubProduto::creating(function (PubProduto $x) use (&$disparou, $p, $c, $ofertas) {
            if (! $disparou && (int) $x->estrutura_produto_id === $p->id) {
                $disparou = true;
                \DB::table('pub_produtos')->insert([
                    'oferta_id' => $ofertas[2]->id, 'estrutura_produto_id' => $p->id, 'company_id' => $c->id,
                    'sku' => 'X', 'nome' => 'Corrida', 'origem' => 'portal', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $r = $this->sinc($e, $c);

        $this->assertTrue($disparou);
        $this->assertSame(0, $r['criados']);
        $this->assertSame(1, PubProduto::count());
        $this->assertCount(1, $r['para_preencher']);
    }

    public function test_pub_produto_agrupado_exibe_o_produto_do_portal(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [$p, $ofertas] = $this->produtoComCores($c);
        $this->sinc($e, $c);

        $g = PubProduto::first();
        $this->assertSame('CAD', $g->skuExibido());
        $this->assertSame('Cadeira CAD', $g->nomeExibido());

        // Vínculo com produto de outra empresa não vaza: cai no comportamento atual.
        $outro = Company::factory()->create();
        $g->update(['company_id' => $outro->id]);
        $this->assertSame('CAD-1', $g->fresh()->skuExibido());
    }

    public function test_situacao_portal_nao_mostra_novas_para_cores_do_grupo(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [$p] = $this->produtoComCores($c);
        $this->sinc($e, $c);

        $s = app(ProgramasPublicadorService::class)->situacaoPortal($c);
        $this->assertSame('sincronizado', $s['situacao']);
        $this->assertSame(0, $s['novas']);
    }
}
