<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\GerarDescricaoIaJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\MlbEmpresa;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\DescricaoIaService;
use App\Services\Publicador\PortalProdutoLeitor;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\PublicadorSincronizaPortalService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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

    public function test_legado_com_rascunho_sem_publicacao_e_adotado_e_a_outra_cor_vazia_e_absorvida(): void
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
        $l2->refresh();
        $this->assertSame($p->id, $l2->estrutura_produto_id);
        $this->assertSame($ofertas[1]->id, $l2->oferta_id);
        $this->assertSame($ofertas[1]->sku, $l2->sku);
        $this->assertSame($l2->id, $rasc->fresh()->produto_id, 'o rascunho do adotado fica intacto');
        // A 1ª cor não tinha rascunho: a linha antiga dela sai, a cor já está no grupo.
        $this->assertNull(PubProduto::find($l1->id));
        $this->assertSame(1, $r['absorvidos']);
        $this->assertSame([$l1->id], $r['absorvidos_ids']);
        $this->assertSame([], $r['duplicados']);
        $this->assertSame(1, PubProduto::count());
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
        $this->assertStringContainsString('"Cor 1" (produto #'.$l1->id.')', $r['avisos'][0], 'o aviso diz qual cor e onde ela está');
        $this->assertStringContainsString('não entram no grupo', $r['avisos'][0]);
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

    public function test_legados_sem_rascunho_de_outras_cores_sao_absorvidos(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [$p, $ofertas] = $this->produtoComCores($c);
        $l1 = $this->legado($ofertas[0], $c, $e);
        $l2 = $this->legado($ofertas[1], $c, $e);
        $l3 = $this->legado($ofertas[2], $c, $e);

        $r = $this->sinc($e, $c);

        // Sem rascunho: adota o da oferta âncora (1ª cor); as outras duas linhas antigas saem.
        $this->assertSame([$l1->id], $r['adotados']);
        $this->assertSame(2, $r['absorvidos']);
        $this->assertSame([$l2->id, $l3->id], $r['absorvidos_ids']);
        $this->assertSame([], $r['duplicados']);
        $this->assertSame([$l1->id], PubProduto::pluck('id')->all());
        $this->assertFalse(collect($r['avisos'])->contains(fn ($a) => str_contains($a, 'também existem como produtos avulsos')));
        $this->assertSame([$l1->id], $r['para_preencher']);
    }

    public function test_legado_com_rascunho_de_outra_cor_nao_e_tocado_e_continua_avisado(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [$p, $ofertas] = $this->produtoComCores($c);
        $l1 = $this->legado($ofertas[0], $c, $e);
        $l2 = $this->legado($ofertas[1], $c, $e);
        $l3 = $this->legado($ofertas[2], $c, $e);
        PubRascunho::create(['produto_id' => $l1->id, 'status' => PubRascunho::DRAFT]);
        $rasc2 = PubRascunho::create(['produto_id' => $l2->id, 'status' => PubRascunho::DRAFT, 'descricao' => 'Feito à mão pela equipe']);

        $r = $this->sinc($e, $c);

        $this->assertSame([$l1->id], $r['adotados']);
        // A 2ª cor tem rascunho (trabalho da equipe): fica como está, com o rascunho.
        $this->assertNotNull(PubProduto::find($l2->id));
        $this->assertNull($l2->fresh()->estrutura_produto_id);
        $this->assertSame('Feito à mão pela equipe', $rasc2->fresh()?->descricao);
        // A 3ª não tem nada: sai.
        $this->assertNull(PubProduto::find($l3->id));
        $this->assertSame(1, $r['absorvidos']);
        $this->assertSame([['produto_id' => $p->id, 'pub_produto_ids' => [$l2->id]]], $r['duplicados']);
        $aviso = collect($r['avisos'])->first(fn ($a) => str_contains($a, 'também existem como produtos avulsos'));
        $this->assertNotNull($aviso);
        $this->assertStringContainsString('"Cor 2" (produto #'.$l2->id.')', $aviso);
    }

    public function test_legado_publicado_de_outra_cor_nao_e_tocado(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [, $ofertas] = $this->produtoComCores($c);
        $l1 = $this->legado($ofertas[0], $c, $e);
        $l2 = $this->legado($ofertas[1], $c, $e);
        $rasc2 = PubRascunho::create(['produto_id' => $l2->id, 'status' => PubRascunho::VALIDATED]);
        $this->publicar($rasc2);

        $r = $this->sinc($e, $c);

        $this->assertSame([$l1->id], $r['adotados']);
        $this->assertNotNull(PubProduto::find($l2->id));
        $this->assertSame(1, PubPublicacao::where('rascunho_id', $rasc2->id)->count());
        $this->assertSame(0, $r['absorvidos']);
        $this->assertSame([$l2->id], $r['duplicados'][0]['pub_produto_ids']);
        $this->assertTrue(collect($r['avisos'])->contains(fn ($a) => str_contains($a, 'não entram no grupo')));
    }

    public function test_legado_referenciado_por_outra_tabela_nao_e_tocado(): void
    {
        // A tabela da Fase 169 saiu em 07/10; se voltar (ou outra igual nascer), ela também segura o legado.
        Schema::create('pub_produto_fatos_criativo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pub_produto_id')->nullable()->constrained('pub_produtos')->nullOnDelete();
            $t->string('tipo', 20);
        });
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [, $ofertas] = $this->produtoComCores($c);
        $l1 = $this->legado($ofertas[0], $c, $e);
        $l2 = $this->legado($ofertas[1], $c, $e);
        $l3 = $this->legado($ofertas[2], $c, $e);
        DB::table('pub_produto_fatos_criativo')->insert(['pub_produto_id' => $l2->id, 'tipo' => 'medida']);

        $r = $this->sinc($e, $c);

        $this->assertSame([$l1->id], $r['adotados']);
        $this->assertNotNull(PubProduto::find($l2->id), 'referenciado: fica');
        $this->assertNull(PubProduto::find($l3->id));
        $this->assertSame([$l3->id], $r['absorvidos_ids']);
    }

    public function test_absorcao_nao_toca_outra_empresa(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $ea = $this->empresa($a);
        $eb = $this->empresa($b);
        [, $oa] = $this->produtoComCores($a, 3, 'AAA');
        [, $ob] = $this->produtoComCores($b, 3, 'BBB');
        $la = array_map(fn ($o) => $this->legado($o, $a, $ea), $oa);
        $lb = array_map(fn ($o) => $this->legado($o, $b, $eb), $ob);
        // Vínculo cruzado: um pub_produto da B ligado à oferta da 3ª cor da A (não deveria existir; daqui não se apaga).
        DB::table('pub_produtos')->where('id', $la[2]->id)->delete();
        $cruzado = PubProduto::create(['oferta_id' => $oa[2]->id, 'company_id' => $b->id, 'sku' => 'X', 'nome' => 'X', 'origem' => 'portal']);

        $r = $this->sinc($ea, $a);

        $this->assertSame([$la[1]->id], $r['absorvidos_ids'], 'só o legado da própria empresa');
        $this->assertNotNull(PubProduto::find($cruzado->id), 'o da outra empresa fica, mesmo ligado à oferta desta');
        foreach ($lb as $l) {
            $this->assertNotNull(PubProduto::find($l->id));
            $this->assertNull($l->fresh()->estrutura_produto_id);
        }
    }

    public function test_rodar_duas_vezes_nao_muda_nada_na_segunda(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [, $ofertas] = $this->produtoComCores($c);
        $l1 = $this->legado($ofertas[0], $c, $e);
        $this->legado($ofertas[1], $c, $e);
        $l3 = $this->legado($ofertas[2], $c, $e);
        // A 1ª cor (com rascunho) vira o grupo; a 3ª tem rascunho e fica; só a 2ª sai.
        PubRascunho::create(['produto_id' => $l1->id, 'status' => PubRascunho::DRAFT]);
        PubRascunho::create(['produto_id' => $l3->id, 'status' => PubRascunho::DRAFT]);

        $r1 = $this->sinc($e, $c);
        $colunas = ['id', 'oferta_id', 'estrutura_produto_id', 'sku', 'nome'];
        $antes = PubProduto::orderBy('id')->get($colunas)->toArray();
        $r2 = $this->sinc($e, $c);

        $this->assertSame(1, $r1['absorvidos']);
        $this->assertSame(0, $r2['absorvidos']);
        $this->assertSame([], $r2['absorvidos_ids']);
        $this->assertSame(0, $r2['criados']);
        $this->assertSame([], $r2['adotados']);
        $this->assertSame($antes, PubProduto::orderBy('id')->get($colunas)->toArray());
        $this->assertSame($r1['duplicados'], $r2['duplicados']);
        $this->assertSame([$l3->id], $r2['duplicados'][0]['pub_produto_ids'] ?? null, 'o de rascunho segue listado');
        $this->assertNotNull(PubProduto::find($l1->id));
    }

    /**
     * O caso da #459 (09/10): o Sincronizar ANTIGO (08/10) criou um pub_produto por cor; o das 10:00 agrupou a
     * cor âncora (com rascunho) e deixou os das outras cores vazios na lista. Agora fica uma linha só.
     */
    public function test_caso_da_459_tres_cores_ancora_adotada_e_dois_legados_vazios_viram_uma_linha(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [$p, $ofertas] = $this->produtoComCores($c);
        $ancora = $this->legado($ofertas[0], $c, $e);
        $vazio2 = $this->legado($ofertas[1], $c, $e);
        $vazio3 = $this->legado($ofertas[2], $c, $e);
        $rasc = PubRascunho::create(['produto_id' => $ancora->id, 'status' => PubRascunho::DRAFT]);
        // Estado deixado pelo Sincronizar das 10:00: âncora já agrupada, as outras cores soltas.
        $ancora->update(['estrutura_produto_id' => $p->id]);

        $r = $this->sinc($e, $c);

        $this->assertSame([], $r['adotados']);
        $this->assertSame(0, $r['criados']);
        $this->assertSame(2, $r['absorvidos']);
        $this->assertSame([$vazio2->id, $vazio3->id], $r['absorvidos_ids']);
        $this->assertSame([$ancora->id], PubProduto::where('company_id', $c->id)->pluck('id')->all(), 'uma linha só na lista do Publicador');
        $this->assertSame($rasc->id, PubRascunho::where('produto_id', $ancora->id)->value('id'));
        $this->assertSame([$ancora->id], $r['para_preencher']);
        // As cores absorvidas não voltam como "novas" no Portal.
        $s = app(ProgramasPublicadorService::class)->situacaoPortal($c);
        $this->assertSame('sincronizado', $s['situacao']);
        $this->assertSame(0, $s['novas']);

        // E rodar de novo não recria as linhas das cores.
        $r2 = $this->sinc($e, $c);
        $this->assertSame(0, $r2['criados']);
        $this->assertSame(1, PubProduto::where('company_id', $c->id)->count());
    }

    /**
     * Item 3 do diagnóstico de 09/10 (Puff, rascunho 9): rascunho que já existia antes do Sincronizar, num produto
     * agrupado, acha a descrição do cliente pelo `estrutura_produto_id` e o pedido automático (D-11) sai.
     */
    public function test_rascunho_de_antes_do_agrupamento_acha_a_descricao_do_cliente_e_o_automatico_sai(): void
    {
        Queue::fake();
        Cache::flush();
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [$p, $ofertas] = $this->produtoComCores($c);
        $p->update(['descricao' => 'Puff redondo']);
        $l2 = $this->legado($ofertas[1], $c, $e);
        $rasc = PubRascunho::create(['produto_id' => $l2->id, 'status' => PubRascunho::DRAFT, 'descricao' => null]);

        $this->sinc($e, $c);

        $l2->refresh();
        $this->assertSame($p->id, $l2->estrutura_produto_id, 'o rascunho de antes foi adotado como o grupo');
        $this->assertSame('Puff redondo', app(PortalProdutoLeitor::class)->descricaoDoCliente($l2));
        $this->assertNotNull(app(DescricaoIaService::class)->pedir($rasc->fresh(), true));
        Queue::assertPushedOn('high', GerarDescricaoIaJob::class, fn ($j) => $j->rascunhoId === $rasc->id);
        $this->assertNull(app(DescricaoIaService::class)->pedir($rasc->fresh(), true), 'uma vez por rascunho');
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

    public function test_lista_de_empresas_conta_a_cobertura_do_grupo_igual_a_situacao_da_empresa(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        $this->produtoComCores($c);
        \App\Models\MlToken::create(['mlb_empresa_id' => $e->id, 'ml_user_id' => '777', 'access_token' => 'APP_USR-x', 'refresh_token' => 'TG-x',
            'expires_at' => now()->addHours(5), 'status' => 'active']);
        $this->sinc($e, $c);

        $servico = app(ProgramasPublicadorService::class);
        $linha = $servico->empresas('polos')->firstWhere('id', $e->id);
        $this->assertNotNull($linha);
        $this->assertSame('sincronizado', $linha['portal']['situacao'], 'as 3 cores agrupadas estão cobertas na lista também');
        $this->assertSame(0, $linha['portal']['novas']);
        $this->assertSame($servico->situacaoPortal($c)['situacao'], $linha['portal']['situacao']);
    }

    public function test_variacao_que_nao_pode_ser_cor_vira_produto_separado_e_nao_some_na_cobertura(): void
    {
        $c = Company::factory()->create();
        $e = $this->empresa($c);
        [$p, $ofertas] = $this->produtoComCores($c);
        // A 3ª variação perdeu o valor no Portal: não pode ser cor do grupo.
        EstruturaProdutoVariacao::whereKey($ofertas[2]->variacao_id)->update(['valor' => '']);

        $r = $this->sinc($e, $c);

        $this->assertSame(2, $r['criados'], 'o grupo e o produto separado');
        $this->assertNotNull(PubProduto::where('oferta_id', $ofertas[2]->id)->whereNull('estrutura_produto_id')->first());
        $this->assertTrue(collect($r['avisos'])->contains(fn ($a) => str_contains($a, 'sem valor') && str_contains($a, 'CAD-3')));
        $s = app(ProgramasPublicadorService::class)->situacaoPortal($c);
        $this->assertSame('sincronizado', $s['situacao']);

        $r2 = $this->sinc($e, $c);
        $this->assertSame(0, $r2['criados'], 'reexecutar não duplica');
    }
}
