<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\AvaliarCriativosAutomaticosJob;
use App\Jobs\Publicador\CriarCombosDoPlanejamentoJob;
use App\Models\Configuracao;
use App\Models\EstruturaOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Publicador\CombosDoPlanejamentoService;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use App\Services\Publicador\FamiliaDeFasesService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\PublicadorSincronizaPortalService;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\TestCase;

/**
 * Combo, Kit e Combit nascem só no Planejamento do Portal, e o anúncio do combo nasce SOZINHO quando a Fase 1
 * é publicada (decisões do usuário de 20/10/2026). O Kit N sai com as cores que têm o Combo N no Portal — a
 * que já está no ar como avulso fica de fora —, como rascunho, sem nenhuma oferta nova no Portal. O "Criar
 * Fase N" à mão recusa o produto do Portal (KIT-08); o produto sem Portal segue com ele.
 */
class CombosDoPlanejamentoTest extends TestCase
{
    use CenarioPlanejamentoDaFase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        Queue::fake();
        $this->montarPlanejamento();
    }

    private function servico(): CombosDoPlanejamentoService
    {
        return app(CombosDoPlanejamentoService::class);
    }

    private function kit(PubProduto $grupo, int $n): ?PubProduto
    {
        return PubProduto::where('produto_base_id', $grupo->id)->where('quantidade_kit', $n)->first();
    }

    /** @return list<string> as cores ATIVAS do rascunho do produto */
    private function coresAtivas(PubProduto $p): array
    {
        $s = $this->repoP()->snapshot($p->fresh()->rascunho);

        return array_values(array_map(fn (Variante $v) => (string) ($v->valores['COLOR']->valueName ?? ''), $s->variantesAtivas()));
    }

    private function url(PubProduto $p, string $resto): string
    {
        return "/mlb/anuncios/publicador/empresas/empresa-{$this->mlbP->id}/produtos/{$p->id}/fases{$resto}";
    }

    // ═══ A criação automática ════════════════════════════════════════════════

    public function test_fase_1_no_ar_cria_o_kit_de_cada_combo_planejado_sem_tocar_no_portal(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(2);
        $this->aceitarCombos(3);
        $ofertasAntes = EstruturaOferta::count();

        $r = $this->servico()->criarOsQueFaltam($grupo->fresh());

        $this->assertSame([2, 3], array_column($r['criados'], 'quantidade'));
        $this->assertSame([], $r['pulados']);
        $this->assertSame($ofertasAntes, EstruturaOferta::count(), 'nada nasce no Portal');
        $kit2 = $this->kit($grupo, 2);
        $this->assertSame('CAD-CB2', $kit2->sku);
        $this->assertSame(PubRascunho::DRAFT, $kit2->rascunho->status, 'nasce rascunho: a equipe revisa e publica');
        $this->assertSame(CombosDoPlanejamentoService::ATOR, $kit2->rascunho->ator);
        $this->assertSame(['Preto', 'Azul', 'Branco'], $this->coresAtivas($kit2));
        foreach (['Preto' => 'CAD-PT-CB2', 'Azul' => 'CAD-AZ-CB2', 'Branco' => 'CAD-BR-CB2'] as $cor => $sku) {
            $this->assertSame($sku, $this->skuDaCor($kit2, $cor));
        }
        $this->assertSame(5, $kit2->rascunho->variantes()->first()->estoque, 'estoque do base (10) ÷ 2');
        $this->assertArrayHasKey('resumo', (array) $kit2->rascunho->fresh()->step_state, 'o que falta já está calculado para a lista');
    }

    /**
     * 21/10/2026 — a capa do conjunto nasce com a fase: com as imagens automáticas ligadas para a empresa, o kit que
     * nasce já tem o pedido da capa na fila `creative`; desligadas, nada (a capa segue no botão do editor do kit).
     */
    public function test_a_fase_que_nasce_pede_a_capa_do_conjunto_so_com_as_imagens_automaticas_ligadas(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(2);
        $this->servico()->criarOsQueFaltam($grupo->fresh());
        $this->assertNotNull($this->kit($grupo, 2));
        Queue::assertNotPushed(AvaliarCriativosAutomaticosJob::class);

        config(['publicador.criativos_auto.ativo' => true]);
        Configuracao::set(CreativeEngineAtivo::CHAVE, '1');
        Configuracao::set(CriativosAutomaticosService::CHAVE_EMPRESAS, (string) $grupo->company_id);
        $this->aceitarCombos(3);
        $this->servico()->criarOsQueFaltam($grupo->fresh());

        $kit3 = $this->kit($grupo, 3);
        Queue::assertPushedOn('creative', AvaliarCriativosAutomaticosJob::class, fn (AvaliarCriativosAutomaticosJob $j) => $j->rascunhoId === (int) $kit3->rascunho->id);
        Queue::assertPushed(AvaliarCriativosAutomaticosJob::class, 1);
    }

    public function test_so_as_cores_com_o_combo_no_portal_ficam_ativas(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(3, ['Preto', 'Branco']);

        $r = $this->servico()->criarOsQueFaltam($grupo->fresh());

        $this->assertSame(['Preto', 'Branco'], $r['criados'][0]['cores']);
        $this->assertSame(['Preto', 'Branco'], $this->coresAtivas($this->kit($grupo, 3)), 'o Azul não tem Combo 3: fica inativo no kit');
    }

    public function test_cor_com_o_combo_ja_no_ar_como_avulso_fica_de_fora(): void
    {
        $grupo = $this->grupoComRascunho();
        $combos = $this->aceitarCombos(2);
        // O Sincronizar de antes criava um produto por oferta: o Combo 2 Preto já foi publicado assim.
        $avulso = PubProduto::create(['company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id, 'oferta_id' => $combos['Preto']->id,
            'sku' => 'CAD-PT-CB2', 'nome' => 'Combo 2 Preto', 'origem' => PubProduto::ORIGEM_PORTAL]);
        PubRascunho::create(['produto_id' => $avulso->id, 'status' => PubRascunho::PUBLISHED, 'revisao' => 1]);

        $this->servico()->criarOsQueFaltam($grupo->fresh());

        $this->assertSame(['Azul', 'Branco'], $this->coresAtivas($this->kit($grupo, 2)), 'o Preto já está no ar: publicar de novo duplicaria');
    }

    public function test_sem_cor_que_possa_entrar_o_combo_e_pulado_com_o_motivo(): void
    {
        $grupo = $this->grupoComRascunho();
        $combos = $this->aceitarCombos(2, ['Preto']);
        $avulso = PubProduto::create(['company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id, 'oferta_id' => $combos['Preto']->id,
            'sku' => 'CAD-PT-CB2', 'nome' => 'Combo 2 Preto', 'origem' => PubProduto::ORIGEM_PORTAL]);
        PubRascunho::create(['produto_id' => $avulso->id, 'status' => PubRascunho::PUBLISHED, 'revisao' => 1]);

        $r = $this->servico()->criarOsQueFaltam($grupo->fresh());

        $this->assertSame([], $r['criados']);
        $this->assertSame(2, $r['pulados'][0]['quantidade']);
        $this->assertStringContainsString('já está no ar como produto avulso', $r['pulados'][0]['motivo']);
        $this->assertNull($this->kit($grupo, 2));
    }

    public function test_sem_a_fase_1_no_ar_nada_nasce_nem_e_agendado(): void
    {
        $grupo = $this->grupoComRascunho(PubRascunho::DRAFT);
        $this->aceitarCombos(2);

        $this->assertSame(['criados' => [], 'pulados' => []], $this->servico()->criarOsQueFaltam($grupo->fresh()));
        $this->assertFalse($this->servico()->agendar($grupo->id));
        Queue::assertNotPushed(CriarCombosDoPlanejamentoJob::class);
    }

    public function test_quantidade_que_a_familia_ja_tem_nao_repete_e_rodar_de_novo_nao_duplica(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->kitAntigo($grupo, 2);
        $this->aceitarCombos(2);
        $this->aceitarCombos(4);

        $primeira = $this->servico()->criarOsQueFaltam($grupo->fresh());
        $segunda = $this->servico()->criarOsQueFaltam($grupo->fresh());

        $this->assertSame([4], array_column($primeira['criados'], 'quantidade'));
        $this->assertSame(['criados' => [], 'pulados' => []], $segunda);
        $this->assertSame(2, PubProduto::where('produto_base_id', $grupo->id)->count());
    }

    public function test_produto_que_nao_e_do_portal_nao_e_tocado(): void
    {
        $avulso = PubProduto::create(['company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id, 'sku' => 'MANUAL',
            'nome' => 'Cadeira manual', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $this->rascunhoDeCores($avulso, ['Preto' => 'MANUAL-PT'], 10);
        PubRascunho::where('produto_id', $avulso->id)->update(['status' => PubRascunho::PUBLISHED]);
        $this->aceitarCombos(2);

        $this->assertFalse(CombosDoPlanejamentoService::doPortal($avulso->fresh()));
        $this->assertFalse($this->servico()->agendar($avulso->id));
        $this->assertSame([], $this->servico()->criarOsQueFaltam($avulso->fresh())['criados']);
    }

    // ═══ Os gatilhos ═════════════════════════════════════════════════════════

    public function test_agendar_poe_o_job_na_fila_high_e_o_job_cria(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(2);

        $this->assertTrue($this->servico()->agendar($grupo->id));
        Queue::assertPushedOn('high', CriarCombosDoPlanejamentoJob::class, fn (CriarCombosDoPlanejamentoJob $j) => $j->produtoId === $grupo->id);

        (new CriarCombosDoPlanejamentoJob($grupo->id))->handle($this->servico());
        $this->assertNotNull($this->kit($grupo, 2));
        (new CriarCombosDoPlanejamentoJob(999999))->handle($this->servico());
    }

    public function test_sincronizar_agenda_o_combo_planejado_com_a_fase_1_ja_no_ar(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(2);

        $r = app(PublicadorSincronizaPortalService::class)->sincronizar($this->mlbP, $this->empresaP);

        $this->assertSame(3, $r['combos_aguardando_fase']);
        $this->assertSame(1, $r['combos_agendados']);
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, fn (CriarCombosDoPlanejamentoJob $j) => $j->produtoId === $grupo->id);
    }

    public function test_sincronizar_nao_agenda_sem_a_fase_1_no_ar(): void
    {
        $this->grupoComRascunho(PubRascunho::DRAFT);
        $this->aceitarCombos(2);

        $r = app(PublicadorSincronizaPortalService::class)->sincronizar($this->mlbP, $this->empresaP);

        $this->assertSame(0, $r['combos_agendados']);
        Queue::assertNotPushed(CriarCombosDoPlanejamentoJob::class);
    }

    // ═══ O "Criar Fase N" à mão ═════════════════════════════════════════════

    public function test_o_criar_fase_por_http_recusa_o_produto_do_portal_com_kit08(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->aceitarCombos(2);
        $equipe = $this->actingAs($this->equipeP);

        $equipe->getJson($this->url($grupo, '/previa?quantidade=2'))->assertStatus(422)->assertJsonPath('regra', 'KIT-08');
        $equipe->postJson($this->url($grupo, ''), ['quantidade' => 2, 'sku' => 'CAD-CB2'])->assertStatus(422)->assertJsonPath('regra', 'KIT-08')
            ->assertJsonPath('message', CombosDoPlanejamentoService::MOTIVO_PORTAL);
        $equipe->postJson($this->url($grupo, '/ia'), ['alvo' => 'titulo', 'quantidade' => 2])->assertStatus(422)->assertJsonPath('regra', 'KIT-08');

        $this->assertNull($this->kit($grupo, 2));
    }

    public function test_o_produto_sem_portal_segue_com_o_criar_fase(): void
    {
        $avulso = PubProduto::create(['company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id, 'sku' => 'MANUAL',
            'nome' => 'Cadeira manual', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $this->rascunhoDeCores($avulso, ['Preto' => 'MANUAL-PT'], 10);
        PubRascunho::where('produto_id', $avulso->id)->update(['status' => PubRascunho::PUBLISHED, 'categoria_id' => 'MLB193945']);

        $this->actingAs($this->equipeP)->getJson($this->url($avulso, '/previa?quantidade=2'))->assertOk()->assertJsonPath('sku', 'MANUAL-KIT2');
    }

    // ═══ O que as telas recebem ══════════════════════════════════════════════

    public function test_tela_do_produto_do_portal_mostra_os_combos_que_esperam_no_lugar_do_botao(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->kitAntigo($grupo, 2);
        $this->aceitarCombos(2);
        $this->aceitarCombos(3);
        $alvo = app(ProgramasPublicadorService::class)->resolver('empresa-'.$this->mlbP->id);

        $proxima = app(FamiliaDeFasesService::class)->paraTela($grupo->fresh(), $alvo, app(ProgramasPublicadorService::class)->empresaParaTela($alvo, $grupo))['proxima_fase'];

        $this->assertFalse($proxima['habilitado']);
        $this->assertSame(CombosDoPlanejamentoService::MOTIVO_PORTAL, $proxima['motivo']);
        $this->assertTrue($proxima['combos_no_portal']);
        $this->assertSame([3], $proxima['combos_esperando'], 'o Combo 2 já tem o kit');
        $this->assertTrue($proxima['fase_1_no_ar']);
    }

    public function test_a_lista_marca_o_produto_do_portal_para_o_menu_esconder_o_criar_fase(): void
    {
        $grupo = $this->grupoComRascunho();
        $kit = $this->kitAntigo($grupo, 2);

        $lista = collect(app(ProgramasPublicadorService::class)->produtosParaTela($this->mlbP, $this->empresaP, 'empresa-'.$this->mlbP->id))->keyBy('id');

        $this->assertTrue($lista[$grupo->id]['combos_no_portal']);
        $this->assertFalse($lista[$kit->id]['combos_no_portal'], 'o kit não é o produto de 1 unidade');
    }
}
