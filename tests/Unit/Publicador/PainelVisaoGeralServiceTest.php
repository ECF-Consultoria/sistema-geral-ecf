<?php

namespace Tests\Unit\Publicador;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlbEmpresa;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\AcervoTriagemService;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 173 Plano 04 — `PainelVisaoGeralService`, fonte ÚNICA `pub_publicacoes`
 * (decisão do usuário que reescreveu este plano: o assistente antigo não
 * entra em nenhum número aqui, nem como fallback).
 *
 * @group phase173
 */
class PainelVisaoGeralServiceTest extends TestCase
{
    use RefreshDatabase;

    private PainelVisaoGeralService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PainelVisaoGeralService(new AcervoTriagemService());
    }

    // ═══ Fixtures ════════════════════════════════════════════════════════

    private function produto(?MlbEmpresa $empresa, ?Company $company): PubProduto
    {
        return PubProduto::create([
            'mlb_empresa_id' => $empresa?->id,
            'company_id' => $company?->id,
            'sku' => 'SKU-'.random_int(1, 999999),
            'nome' => 'Produto',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    /**
     * Uma publicação completa (produto → rascunho → publicação → item CREATED) com o ator
     * dado. Cada chamada nasce de um PRODUTO novo — pub_rascunhos.produto_id é único, então
     * "2 publicações" da mesma conta são 2 produtos distintos, nunca o mesmo rascunho 2x.
     */
    private function publicar(?MlbEmpresa $empresa, ?Company $company, array $ator, ?Carbon $concluidaEm = null, ?string $mlItemId = null): PubPublicacaoItem
    {
        $produto = $this->produto($empresa, $company);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id, 'status' => PubRascunho::PUBLISHED]);
        $publicacao = PubPublicacao::create([
            'rascunho_id' => $rascunho->id,
            'revisao' => 1,
            'modelo_publicacao' => 'items',
            'status' => PubPublicacao::PUBLISHED,
            'chave_idempotencia' => (string) Str::uuid(),
            'concluida_em' => $concluidaEm ?? now(),
            'ator' => $ator,
        ]);

        return PubPublicacaoItem::create([
            'publicacao_id' => $publicacao->id,
            'indice' => 0,
            'listing_type_id' => 'gold_special',
            'variante_chave' => ChaveCanonica::UNICA,
            'status' => PubPublicacaoItem::CREATED,
            'ml_item_id' => $mlItemId ?? 'MLB'.random_int(1000000000, 9999999999),
            'payload' => ['family_name' => 'Produto publicado'],
        ]);
    }

    private function alvoDaEmpresa(MlbEmpresa $empresa): array
    {
        return ['mlb_empresa' => $empresa, 'company' => $empresa->company, 'programa' => 'polos', 'chave' => $empresa->chaveContaMl()];
    }

    private function alvoDaCompany(Company $company): array
    {
        return ['mlb_empresa' => null, 'company' => $company, 'programa' => 'gestao', 'chave' => $company->chaveContaMl()];
    }

    // ═══ publicadosRecentes() ════════════════════════════════════════════

    /** @test */
    public function test_publicados_recentes(): void
    {
        $responsavel = User::factory()->create(['name' => 'Responsável']);
        $colega = User::factory()->create(['name' => 'Colega']);
        $empresa = MlbEmpresa::create(['nome' => 'Polo X', 'projeto' => 'POLOS', 'responsavel_id' => $responsavel->id])->fresh();
        $alvo = $this->alvoDaEmpresa($empresa);

        // 2 itens CREATED com ml_item_id diferentes, dentro dos 30 dias.
        $this->publicar($empresa, null, ['equipe' => true, 'id' => $colega->id, 'nome' => $colega->name], now()->subDays(5), 'MLB111');
        $this->publicar($empresa, null, ['equipe' => true, 'id' => $responsavel->id, 'nome' => $responsavel->name], now()->subDays(2), 'MLB222');
        // Fora da janela de 30 dias — não conta.
        $this->publicar($empresa, null, ['equipe' => true, 'id' => $colega->id, 'nome' => $colega->name], now()->subDays(40), 'MLB333');
        // Cliente do Portal — agrupa numa linha única, nunca por pessoa.
        $this->publicar($empresa, null, ['equipe' => false, 'id' => 55, 'nome' => 'Cliente'], now()->subDays(1), 'MLB444');
        $this->publicar($empresa, null, ['equipe' => false, 'id' => 56, 'nome' => 'Outro Cliente'], now()->subDays(1), 'MLB555');
        // Migração antiga (estrutura_publicacoes), ator sem id.
        $this->publicar($empresa, null, ['origem' => 'migracao_anunciar_antigo'], now()->subDays(3), 'MLB666');

        $r = $this->service->publicadosRecentes($alvo);

        $this->assertSame(5, $r['total'], 'fora da janela de 30 dias não conta; sem dedup, fonte única');
        $this->assertSame(2, $r['cliente']['quantidade'], 'cliente agrupa numa linha só, soma, nunca uma linha por cliente');
        $this->assertSame(1, $r['origem_antiga']['quantidade']);

        $this->assertCount(2, $r['equipe']);
        // O responsável vem sempre primeiro, mesmo com quantidade igual/menor.
        $this->assertSame('Responsável', $r['equipe'][0]['nome']);
        $this->assertTrue($r['equipe'][0]['responsavel']);
        $this->assertSame(1, $r['equipe'][0]['quantidade']);
        $this->assertSame('Colega', $r['equipe'][1]['nome']);
        $this->assertFalse($r['equipe'][1]['responsavel']);
        $this->assertSame(1, $r['equipe'][1]['quantidade']);
    }

    /** @test */
    public function test_publicados_recentes_sem_nenhuma_publicacao_devolve_zerado(): void
    {
        $empresa = MlbEmpresa::create(['nome' => 'Polo Vazio', 'projeto' => 'POLOS'])->fresh();

        $r = $this->service->publicadosRecentes($this->alvoDaEmpresa($empresa));

        $this->assertSame(0, $r['total']);
        $this->assertSame([], $r['equipe']);
        $this->assertSame(0, $r['cliente']['quantidade']);
        $this->assertSame(0, $r['origem_antiga']['quantidade']);
    }

    // ═══ indicadores() ═══════════════════════════════════════════════════

    /** @test */
    public function test_indicadores(): void
    {
        $company = Company::factory()->create();
        $alvo = $this->alvoDaCompany($company);

        $this->criarAcervo($company, 'MLB1', 'active', 3);
        $this->criarAcervo($company, 'MLB2', 'paused', 0);
        $this->criarAcervo($company, 'MLB3', 'closed', 10); // encerrado não entra no escopo (active/paused)

        $r = $this->service->indicadores($alvo, ['sem_oferta' => 4]);

        $this->assertSame(2, $r['no_ar']);
        $this->assertSame(1, $r['com_venda']);
        $this->assertSame(4, $r['sem_oferta']);
        $this->assertSame(0, $r['publicados_30d']);
        $this->assertTrue($r['acervo_disponivel']);
        $this->assertFalse($r['nunca_coletado']);
    }

    /** @test */
    public function test_indicadores_nunca_coletado_nunca_e_zero(): void
    {
        $company = Company::factory()->create();

        $r = $this->service->indicadores($this->alvoDaCompany($company), []);

        $this->assertNull($r['no_ar']);
        $this->assertNull($r['com_venda']);
        $this->assertTrue($r['nunca_coletado']);
        $this->assertTrue($r['acervo_disponivel'], 'a empresa TEM Company — o acervo é que nunca foi coletado');
    }

    /** @test */
    public function test_indicadores_mlb_empresa_sem_company(): void
    {
        $empresa = MlbEmpresa::create(['nome' => 'Sem Company', 'projeto' => 'POLOS'])->fresh();

        $r = $this->service->indicadores($this->alvoDaEmpresa($empresa), ['sem_oferta' => 2]);

        $this->assertNull($r['no_ar']);
        $this->assertNull($r['com_venda']);
        $this->assertFalse($r['acervo_disponivel']);
        $this->assertSame(2, $r['sem_oferta']);
        $this->assertSame(0, $r['publicados_30d'], 'publicados_30d calcula normalmente, não depende de MlAcervoItem');
    }

    private function criarAcervo(Company $company, string $mlItemId, string $status, int $soldQuantity): MlAcervoItem
    {
        return MlAcervoItem::create([
            'company_id' => $company->id,
            'ml_item_id' => $mlItemId,
            'title' => 'Item',
            'status' => $status,
            'available_quantity' => 10,
            'sold_quantity' => $soldQuantity,
            'nota_ecf' => 60,
            'motivos' => [],
            'severidade' => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'origem' => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em' => now(),
        ]);
    }
}
