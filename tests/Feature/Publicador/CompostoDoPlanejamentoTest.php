<?php

namespace Tests\Feature\Publicador;

use App\Models\EstruturaOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\CriarFaseService;
use App\Services\Publicador\FamiliaDeFasesService;
use App\Services\Publicador\PlanejamentoDaFaseService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioPlanejamentoDaFase;
use Tests\TestCase;

/**
 * Planejamento × Fase N, item C (decisões do usuário de 09/10/2026): o produto do Publicador ligado a
 * uma oferta Combo/Kit/Combit do Portal é um COMPOSTO do Planejamento, não a base de uma Fase 1 —
 * rótulo próprio, fora dos buckets por fase (contado em `compostos`) e "Criar Fase N" recusado
 * (KIT-06), na tela do Produto e no servidor, antes do KIT-05 e do KIT-01.
 */
class CompostoDoPlanejamentoTest extends TestCase
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

    private function programas(): ProgramasPublicadorService
    {
        return app(ProgramasPublicadorService::class);
    }

    /** Um `pub_produto` avulso de uma oferta do tipo pedido (como o Sincronizar de antes criava). */
    private function composto(string $tipo, string $sku, ?string $status = null): PubProduto
    {
        $oferta = EstruturaOferta::create(['company_id' => $this->empresaP->id, 'sku' => $sku, 'fase' => $tipo, 'nome' => $sku]);
        $oferta->componentes()->create(['componente_id' => $this->simplesP['Preto']->id, 'quantidade' => 2]);
        if ($tipo !== 'combo') {
            $oferta->componentes()->create(['componente_id' => $this->simplesP['Azul']->id, 'quantidade' => $tipo === 'kit' ? 1 : 4]);
        }
        $p = PubProduto::create(['company_id' => $this->empresaP->id, 'mlb_empresa_id' => $this->mlbP->id, 'oferta_id' => $oferta->id,
            'sku' => $sku, 'nome' => $sku, 'origem' => PubProduto::ORIGEM_PORTAL]);
        if ($status !== null) {
            $r = PubRascunho::create(['produto_id' => $p->id, 'status' => $status, 'revisao' => 1]);
            $r->variantes()->create(['combinacao_chave' => ChaveCanonica::UNICA, 'combinacao_hash' => ChaveCanonica::hash(ChaveCanonica::UNICA), 'ativa' => true]);
        }

        return $p->fresh();
    }

    /** @return array<int, array> a lista de Produtos indexada pelo id */
    private function lista(): array
    {
        $saida = [];
        foreach ($this->programas()->produtosParaTela($this->mlbP, $this->empresaP, 'empresa-'.$this->mlbP->id) as $linha) {
            $saida[(int) $linha['id']] = $linha;
        }

        return $saida;
    }

    // ═══ O rótulo e a lista ══════════════════════════════════════════════════

    public function test_lista_da_ao_composto_o_rotulo_do_tipo_e_a_chave_composto(): void
    {
        $grupo = $this->grupoComRascunho();
        $kitDaFamilia = $this->kitAntigo($grupo, 2);
        $combo = $this->composto('combo', 'CAD-PT-CB2', PubRascunho::PUBLISHED);
        $kit = $this->composto('kit', 'KT-CAD', PubRascunho::DRAFT);
        $combit = $this->composto('combit', 'CT4-CAD');

        $lista = $this->lista();

        $this->assertSame(['combo', 'Combo do Planejamento', false], [$lista[$combo->id]['composto'], $lista[$combo->id]['rotulo_fase'], $lista[$combo->id]['eh_kit']]);
        $this->assertSame(['kit', 'Kit do Planejamento'], [$lista[$kit->id]['composto'], $lista[$kit->id]['rotulo_fase']]);
        $this->assertSame(['combit', 'Combit do Planejamento'], [$lista[$combit->id]['composto'], $lista[$combit->id]['rotulo_fase']]);
        $this->assertSame([null, '1 unidade'], [$lista[$grupo->id]['composto'], $lista[$grupo->id]['rotulo_fase']], 'o grupo (Simples) segue base de Fase 1');
        $this->assertSame([null, 'Kit 2', true], [$lista[$kitDaFamilia->id]['composto'], $lista[$kitDaFamilia->id]['rotulo_fase'], $lista[$kitDaFamilia->id]['eh_kit']]);
    }

    public function test_combo_vinculado_como_kit_da_familia_e_kit_nao_composto(): void
    {
        $grupo = $this->grupoComRascunho();
        $combo = $this->composto('combo', 'CAD-PT-CB2', PubRascunho::PUBLISHED);
        $combo->update(['produto_base_id' => $grupo->id, 'quantidade_kit' => 2, 'fase' => 2]);

        $linha = $this->lista()[$combo->id];

        $this->assertNull($linha['composto']);
        $this->assertSame('Kit 2', $linha['rotulo_fase']);
        $this->assertNull(PlanejamentoDaFaseService::tipoComposto($combo->fresh()));
    }

    // ═══ A contagem ══════════════════════════════════════════════════════════

    public function test_composto_fica_fora_dos_cinco_buckets_e_a_soma_fecha_com_compostos(): void
    {
        $grupo = $this->grupoComRascunho();
        $this->composto('combo', 'CAD-PT-CB2', PubRascunho::PUBLISHED);
        $this->composto('kit', 'KT-CAD');

        $c = $this->programas()->contagemProdutos(array_values($this->lista()));

        $this->assertSame(2, $c['compostos']);
        $this->assertSame(['sem_oferta' => 0, 'fase1_publicada' => 1, 'fase2_preparacao' => 0, 'fase2_publicada' => 0, 'fase3_mais' => 0], $c['por_fase'],
            'só o grupo é Fase 1 publicada: o combo publicado não conta como Fase 1');
        $this->assertSame($c['todos'], array_sum($c['por_fase']) + $c['compostos']);
        $this->assertSame(2, $c['publicados'], 'a situação continua contando todos os publicados');
        $this->assertNotNull($grupo->id);
    }

    public function test_lista_antiga_sem_a_chave_composto_conta_como_antes(): void
    {
        $c = $this->programas()->contagemProdutos([['status' => ['chave' => 'publicado'], 'oferta_id' => 7]]);

        $this->assertSame(0, $c['compostos']);
        $this->assertSame(1, $c['por_fase']['fase1_publicada']);
    }

    // ═══ KIT-06: "Criar Fase N" recusado ═════════════════════════════════════

    public function test_criar_fase_no_composto_e_recusado_com_kit06(): void
    {
        $combo = $this->composto('combo', 'CAD-PT-CB2', PubRascunho::PUBLISHED);

        try {
            app(CriarFaseService::class)->criar($combo, ['quantidade' => 2, 'sku' => 'X', 'user' => $this->equipeP]);
            $this->fail('devia recusar com KIT-06');
        } catch (RegraViolada $e) {
            $this->assertSame('KIT-06', $e->regra);
            $this->assertStringContainsString('Combo do Planejamento', $e->getMessage());
        }
        $this->assertSame(0, PubProduto::whereNotNull('produto_base_id')->count());
    }

    public function test_endpoint_recusa_kit06_antes_do_kit05_e_do_kit01(): void
    {
        $semRascunho = $this->composto('kit', 'KT-CAD');
        $emRascunho = $this->composto('combit', 'CT4-CAD', PubRascunho::DRAFT);
        $url = fn (PubProduto $p) => "/mlb/anuncios/publicador/empresas/empresa-{$this->mlbP->id}/produtos/{$p->id}/fases";

        foreach ([$semRascunho, $emRascunho] as $p) {
            $this->actingAs($this->equipeP)->postJson($url($p), ['quantidade' => 2, 'sku' => 'X-KIT2'])
                ->assertStatus(422)->assertJsonPath('regra', 'KIT-06');
        }
        $this->assertSame(0, PubProduto::whereNotNull('produto_base_id')->count(), 'nenhum kit nasce de um composto');
    }

    public function test_tela_do_produto_desabilita_criar_fase_no_composto_com_o_motivo(): void
    {
        $combo = $this->composto('combo', 'CAD-PT-CB2', PubRascunho::PUBLISHED);
        $alvo = $this->programas()->resolver('empresa-'.$this->mlbP->id);

        $tela = app(FamiliaDeFasesService::class)->paraTela($combo, $alvo, $this->programas()->empresaParaTela($alvo, $combo));

        $this->assertFalse($tela['proxima_fase']['habilitado']);
        $this->assertSame(PlanejamentoDaFaseService::motivoKit06('combo'), $tela['proxima_fase']['motivo']);
        $this->assertSame('Combo do Planejamento', $tela['fases'][0]['rotulo']);
    }

    public function test_base_simples_publicado_continua_habilitado(): void
    {
        $grupo = $this->grupoComRascunho();
        $alvo = $this->programas()->resolver('empresa-'.$this->mlbP->id);

        $tela = app(FamiliaDeFasesService::class)->paraTela($grupo, $alvo, $this->programas()->empresaParaTela($alvo, $grupo));

        $this->assertTrue($tela['proxima_fase']['habilitado']);
        $this->assertNull($tela['proxima_fase']['motivo']);
        $this->assertSame('1 unidade', $tela['fases'][0]['rotulo']);
    }
}
