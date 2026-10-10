<?php

namespace Tests\Feature\Publicador;

use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\PortalParaRascunhoService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\PublicadorSincronizaPortalService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\TestCase;

/**
 * Planejamento × Fase N, item A (decisões do usuário de 09/10/2026): o Sincronizar deixa de criar um
 * `pub_produto` avulso para a oferta Combo de UMA cor de um produto agrupado. Com o Kit N da família, a
 * oferta é a variante daquela cor (o SKU dela entra só no vazio ou onde o rascunho ainda tem o que o
 * Portal escreveu); sem o Kit N, fica aguardando o "Criar Fase N" (log + `combos_aguardando_fase`).
 * O combo avulso que o Sincronizar de antes criou: sem rascunho nem referência, é absorvido; com
 * rascunho, fica (e é composto do Planejamento). Kit e Combit seguem um produto por oferta.
 */
class SincronizarCombosDaFamiliaTest extends TestCase
{
    use CenarioPlanejamentoDaFase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $this->montarPlanejamento();
    }

    private function sinc(?int $soDoProduto = null): array
    {
        return app(PublicadorSincronizaPortalService::class)->sincronizar($this->mlbP, $this->empresaP, $soDoProduto);
    }

    private function preencher(PubProduto $p): array
    {
        return app(PortalParaRascunhoService::class)->preencher($p->fresh());
    }

    /** O `pub_produto` avulso de uma oferta, como o Sincronizar de antes de 09/10 criava. */
    private function avulso(EstruturaOferta $oferta, ?string $status = null): PubProduto
    {
        $p = PubProduto::create(['oferta_id' => $oferta->id, 'company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id,
            'sku' => $oferta->sku, 'nome' => (string) $oferta->nome, 'origem' => PubProduto::ORIGEM_PORTAL]);
        if ($status !== null) {
            $r = PubRascunho::create(['produto_id' => $p->id, 'status' => $status, 'revisao' => 1]);
            $r->variantes()->create(['combinacao_chave' => ChaveCanonica::UNICA, 'combinacao_hash' => ChaveCanonica::hash(ChaveCanonica::UNICA), 'ativa' => true]);
        }

        return $p;
    }

    private function publicar(PubProduto $p): void
    {
        $r = PubRascunho::where('produto_id', $p->id)->firstOrFail();
        $r->update(['status' => PubRascunho::PUBLISHED]);
        $pub = PubPublicacao::create(['rascunho_id' => $r->id, 'revisao' => 1, 'modelo_publicacao' => 'items', 'status' => 'PUBLISHED',
            'chave_idempotencia' => (string) Str::uuid(), 'concluida_em' => now()]);
        PubPublicacaoItem::create(['publicacao_id' => $pub->id, 'indice' => 0, 'listing_type_id' => 'gold_special',
            'variante_chave' => ChaveCanonica::UNICA, 'status' => PubPublicacaoItem::CREATED, 'ml_item_id' => 'MLB'.$p->id]);
    }

    // ═══ Sem o Kit N: aguardando a Fase N ════════════════════════════════════

    public function test_sem_kit_2_os_tres_combos_nao_viram_produto_avulso_e_ficam_aguardando(): void
    {
        $this->aceitarCombos(2);
        Log::spy();

        $r = $this->sinc();

        $this->assertSame(1, $r['criados'], 'só o grupo das três cores');
        $this->assertSame(1, PubProduto::count());
        $this->assertSame(0, PubProduto::whereIn('oferta_id', EstruturaOferta::where('fase', 'combo')->pluck('id'))->count());
        $this->assertSame(3, $r['combos_aguardando_fase']);
        $this->assertSame(0, $r['combos_na_fase']);
        $grupo = PubProduto::where('estrutura_produto_id', $this->produtoP->id)->firstOrFail();
        $this->assertSame([$grupo->id], $r['para_preencher']);
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => $msg === '[Publicador] Sincronizar avisos'
            && collect($ctx['avisos'] ?? [])->contains(fn ($a) => str_contains($a, 'Combo 2') && str_contains($a, 'aguardando')));

        $this->assertSame(0, $this->sinc()['criados'], 'reexecutar não cria nada');
        $this->assertSame(1, PubProduto::count());
    }

    public function test_combos_aguardando_contam_como_cobertos_na_situacao_do_portal(): void
    {
        $this->aceitarCombos(2);
        $this->sinc();

        $situacao = app(ProgramasPublicadorService::class)->situacaoPortal($this->empresaP->fresh());

        $this->assertSame('sincronizado', $situacao['situacao'], 'o Sincronizar não traria nada: não é "ofertas novas"');
        $this->assertSame(0, $situacao['novas']);
    }

    public function test_antes_do_primeiro_sincronizar_as_ofertas_sao_novas(): void
    {
        $this->aceitarCombos(2);

        $situacao = app(ProgramasPublicadorService::class)->situacaoPortal($this->empresaP->fresh());

        $this->assertSame('nunca', $situacao['situacao']);
        $this->assertSame(6, $situacao['novas']);
    }

    // ═══ Com o Kit N: a oferta é a variante da cor ═══════════════════════════

    public function test_com_kit_2_os_combos_vao_para_as_variantes_do_kit_sem_duplicata(): void
    {
        $grupo = $this->grupoComRascunho();
        $kit = $this->kitAntigo($grupo, 2, ['Preto' => null, 'Azul' => null, 'Branco' => null]);
        $this->aceitarCombos(2);

        $r = $this->sinc();

        $this->assertSame(0, $r['criados']);
        $this->assertSame(3, $r['combos_na_fase']);
        $this->assertSame(0, $r['combos_aguardando_fase']);
        $this->assertContains($kit->id, $r['para_preencher']);
        $this->assertSame(2, PubProduto::count(), 'o grupo e o kit: nenhum combo avulso');

        $resumo = $this->preencher($kit);

        $this->assertSame('CAD-PT-CB2', $this->skuDaCor($kit, 'Preto'));
        $this->assertSame('CAD-AZ-CB2', $this->skuDaCor($kit, 'Azul'));
        $this->assertSame('CAD-BR-CB2', $this->skuDaCor($kit, 'Branco'));
        $this->assertSame(3, $resumo['campos_preenchidos']);
        $this->assertSame(3, $resumo['variantes']);
    }

    public function test_kit_com_sku_kit_de_antes_nao_e_sobrescrito(): void
    {
        $grupo = $this->grupoComRascunho();
        $kit = $this->kitAntigo($grupo, 2);
        $this->aceitarCombos(2);
        $this->sinc();

        $resumo = $this->preencher($kit);

        $this->assertSame('CAD-PT-KIT2', $this->skuDaCor($kit, 'Preto'), 'o -KIT2 é da equipe (D-05): fica');
        $this->assertSame(0, $resumo['campos_preenchidos'] + $resumo['campos_atualizados']);
        $this->assertSame(3, $resumo['campos_mantidos']);
    }

    public function test_sku_que_o_portal_escreveu_segue_o_portal_quando_a_oferta_muda(): void
    {
        $grupo = $this->grupoComRascunho();
        $kit = $this->kitAntigo($grupo, 2, ['Preto' => null, 'Azul' => 'CAD-AZ-CB2', 'Branco' => 'MEU-BRANCO']);
        $combos = $this->aceitarCombos(2);
        $this->sinc();
        $this->preencher($kit);
        $this->assertSame('CAD-PT-CB2', $this->skuDaCor($kit, 'Preto'));

        // O cliente renomeou os combos no Portal.
        foreach ($combos as $cor => $o) {
            $o->update(['sku' => $o->sku.'-N']);
        }
        $this->sinc();
        $resumo = $this->preencher($kit);

        $this->assertSame('CAD-PT-CB2-N', $this->skuDaCor($kit, 'Preto'), 'o Portal escreveu: segue o Portal');
        $this->assertSame('CAD-AZ-CB2-N', $this->skuDaCor($kit, 'Azul'), 'igual ao do Portal sem memória = anotado como do Portal');
        $this->assertSame('MEU-BRANCO', $this->skuDaCor($kit, 'Branco'), 'o da equipe fica');
        $this->assertSame(2, $resumo['campos_atualizados']);
    }

    public function test_cor_do_combo_que_nao_esta_no_kit_nao_quebra_e_as_outras_entram(): void
    {
        $grupo = $this->grupoComRascunho();
        $kit = $this->kitAntigo($grupo, 2, ['Preto' => null, 'Azul' => null]);
        $this->aceitarCombos(2);
        $this->sinc();

        $resumo = $this->preencher($kit);

        $this->assertSame('CAD-PT-CB2', $this->skuDaCor($kit, 'Preto'));
        $this->assertSame('CAD-AZ-CB2', $this->skuDaCor($kit, 'Azul'));
        $this->assertNull($this->skuDaCor($kit, 'Branco'));
        $this->assertSame(2, $resumo['campos_preenchidos']);
    }

    public function test_kit_publicado_nao_e_tocado(): void
    {
        $grupo = $this->grupoComRascunho();
        $kit = $this->kitAntigo($grupo, 2, ['Preto' => null, 'Azul' => null, 'Branco' => null]);
        PubRascunho::where('produto_id', $kit->id)->update(['status' => PubRascunho::PUBLISHED]);
        $this->aceitarCombos(2);
        $this->sinc();

        $resumo = $this->preencher($kit);

        $this->assertTrue($resumo['intocavel']);
        $this->assertNull($this->skuDaCor($kit, 'Preto'));
    }

    // ═══ O combo avulso de antes ═════════════════════════════════════════════

    public function test_combo_avulso_antigo_sem_rascunho_e_absorvido(): void
    {
        $combos = $this->aceitarCombos(2);
        $legado = $this->avulso($combos['Azul']);

        $r = $this->sinc();

        $this->assertNull(PubProduto::find($legado->id));
        $this->assertSame(1, $r['combos_absorvidos']);
        $this->assertSame([$legado->id], $r['combos_absorvidos_ids']);
        $this->assertSame(0, $r['absorvidos'], 'o contador das linhas de COR não mistura os combos');
        $this->assertSame(3, $r['combos_aguardando_fase']);
    }

    public function test_combo_avulso_antigo_com_rascunho_fica_e_e_composto(): void
    {
        $combos = $this->aceitarCombos(2);
        $legado = $this->avulso($combos['Azul'], PubRascunho::DRAFT);
        Log::spy();

        $r = $this->sinc();

        $this->assertNotNull(PubProduto::find($legado->id));
        $this->assertSame(0, $r['combos_absorvidos']);
        $this->assertContains($legado->id, $r['para_preencher'], 'o rascunho dele segue sendo preenchido como composto, como antes');
        $linha = collect(app(ProgramasPublicadorService::class)->produtosParaTela($this->mlbP, $this->empresaP))->firstWhere('id', $legado->id);
        $this->assertSame('combo', $linha['composto']);
        $this->assertSame('Combo do Planejamento', $linha['rotulo_fase']);
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => $msg === '[Publicador] Sincronizar avisos'
            && collect($ctx['avisos'] ?? [])->contains(fn ($a) => str_contains($a, "#{$legado->id}")));
    }

    public function test_combo_avulso_vinculado_ou_base_de_kit_nunca_e_absorvido(): void
    {
        $grupo = $this->grupoComRascunho();
        $combos = $this->aceitarCombos(2);
        $vinculado = $this->avulso($combos['Preto']);
        $vinculado->update(['produto_base_id' => $grupo->id, 'quantidade_kit' => 2, 'fase' => 2]);
        $baseDeKit = $this->avulso($combos['Azul']);
        PubProduto::create(['company_id' => $this->empresaP->id, 'sku' => 'X-KIT2', 'nome' => 'Kit do combo', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $baseDeKit->id, 'quantidade_kit' => 2, 'fase' => 2]);

        $r = $this->sinc();

        $this->assertNotNull(PubProduto::find($vinculado->id), 'o combo vinculado é o Kit 2 da família');
        $this->assertNotNull(PubProduto::find($baseDeKit->id), 'apagar o base soltaria o kit dele');
        $this->assertSame(0, $r['combos_absorvidos']);
    }

    public function test_combo_avulso_publicado_fica_e_a_cor_dele_nao_recebe_o_mesmo_sku_no_kit(): void
    {
        $grupo = $this->grupoComRascunho();
        $kit = $this->kitAntigo($grupo, 2, ['Preto' => null, 'Azul' => null, 'Branco' => null]);
        $combos = $this->aceitarCombos(2);
        $publicado = $this->avulso($combos['Branco'], PubRascunho::DRAFT);
        $this->publicar($publicado);
        $this->sinc();

        $resumo = $this->preencher($kit);

        $this->assertNotNull(PubProduto::find($publicado->id));
        $this->assertNull($this->skuDaCor($kit, 'Branco'), 'nunca o mesmo SKU em dois anúncios');
        $this->assertSame('CAD-PT-CB2', $this->skuDaCor($kit, 'Preto'));
        $this->assertTrue(collect($resumo['avisos'])->contains(fn ($a) => str_contains($a, "#{$publicado->id}")));
    }

    // ═══ O que NÃO muda ══════════════════════════════════════════════════════

    public function test_kit_e_combit_do_planejamento_seguem_um_produto_por_oferta(): void
    {
        $kitPortal = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'sku' => 'KT-1', 'fase' => 'kit', 'nome' => 'Kit']);
        $kitPortal->componentes()->create(['componente_id' => $this->simplesP['Preto']->id, 'quantidade' => 1]);
        $kitPortal->componentes()->create(['componente_id' => $this->simplesP['Azul']->id, 'quantidade' => 1]);
        $combit = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'sku' => 'CT4-1', 'fase' => 'combit', 'nome' => 'Combit']);
        $combit->componentes()->create(['componente_id' => $this->simplesP['Preto']->id, 'quantidade' => 1]);
        $combit->componentes()->create(['componente_id' => $this->simplesP['Branco']->id, 'quantidade' => 4]);

        $r = $this->sinc();

        $this->assertSame(3, $r['criados'], 'o grupo, o kit e o combit');
        $this->assertNotNull(PubProduto::where('oferta_id', $kitPortal->id)->first());
        $this->assertNotNull(PubProduto::where('oferta_id', $combit->id)->first());
        $this->assertSame(0, $r['combos_aguardando_fase']);
    }

    public function test_combo_da_variacao_que_vira_produto_separado_segue_avulso(): void
    {
        $repetida = EstruturaProdutoVariacao::create(['produto_id' => $this->produtoP->id, 'company_id' => $this->empresaP->id, 'ordem' => 9,
            'codigo' => 'CAD-PT2', 'eixo' => 'cor', 'valor' => 'Preto']);
        $simples = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'variacao_id' => $repetida->id, 'sku' => 'CAD-PT2', 'fase' => 'simples', 'nome' => 'Repetida']);
        $combo = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'sku' => 'CAD-PT2-CB2', 'fase' => 'combo', 'nome' => 'Combo da repetida']);
        $combo->componentes()->create(['componente_id' => $simples->id, 'quantidade' => 2]);

        $r = $this->sinc();

        $this->assertNotNull(PubProduto::where('oferta_id', $combo->id)->first(), 'a variação fora do grupo não é cor do kit');
        $this->assertSame(0, $r['combos_aguardando_fase']);
    }

    public function test_salvar_no_portal_um_produto_aplica_a_mesma_regra(): void
    {
        $grupo = $this->grupoComRascunho();
        $kit = $this->kitAntigo($grupo, 2, ['Preto' => null, 'Azul' => null, 'Branco' => null]);
        $this->aceitarCombos(2);

        $r = $this->sinc((int) $this->produtoP->id);

        $this->assertSame(0, $r['criados']);
        $this->assertSame(3, $r['combos_na_fase']);
        $this->assertContains($kit->id, $r['para_preencher']);
    }

    public function test_combo_de_outra_empresa_com_componente_desta_nao_entra(): void
    {
        $b = \App\Models\Company::factory()->create();
        $alheio = EstruturaOferta::create(['company_id' => $b->id, 'sku' => 'B-CB2', 'fase' => 'combo', 'nome' => 'Alheio']);
        $alheio->componentes()->create(['componente_id' => $this->simplesP['Preto']->id, 'quantidade' => 2]);

        $r = $this->sinc();

        $this->assertSame(0, PubProduto::where('oferta_id', $alheio->id)->count());
        $this->assertSame(0, PubProduto::where('company_id', $b->id)->count());
        $this->assertSame(0, $r['combos_aguardando_fase']);
    }
}
