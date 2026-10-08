<?php

namespace Tests\Unit\Publicador;

use App\Models\Company;
use App\Models\CreativeIdentidade;
use App\Models\MlAcervoItem;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
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

    private function criarAcervo(Company $company, string $mlItemId, string $status, int $soldQuantity, array $overrides = []): MlAcervoItem
    {
        return MlAcervoItem::create(array_merge([
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
        ], $overrides));
    }

    // ═══ oQueFazerAgora() ════════════════════════════════════════════════

    /** @test */
    public function test_o_que_fazer_agora_token_expirado_devolve_so_a_linha_de_reconexao(): void
    {
        $alvo = ['mlb_empresa' => null, 'company' => null, 'programa' => 'polos', 'chave' => 'empresa-1'];
        $empresaParaTela = ['token' => 'expirado', 'link_reconexao' => 'https://reconectar', 'company_id' => null, 'portal' => []];
        $chips = ['chips' => [['chave' => MlAcervoItem::MOTIVO_PAUSADO, 'label' => 'Pausado', 'count' => 5, 'cor' => 'red']]];

        $linhas = $this->service->oQueFazerAgora($alvo, $empresaParaTela, ['com_problema' => 5], $chips, [], ['situacao' => 'novas', 'novas' => 10]);

        $this->assertCount(1, $linhas, 'nenhuma outra linha aparece, mesmo com produtos com problema/portal com novas');
        $this->assertSame('Conta precisa de reconexão', $linhas[0]['texto']);
        $this->assertSame('https://reconectar', $linhas[0]['destino']['url']);
    }

    /** @test */
    public function test_o_que_fazer_agora_vazio_quando_nada_pendente(): void
    {
        $alvo = ['mlb_empresa' => null, 'company' => null, 'programa' => 'polos', 'chave' => 'empresa-1'];
        $empresaParaTela = ['token' => 'ativo', 'link_reconexao' => null, 'company_id' => null, 'portal' => []];
        $contagens = ['com_problema' => 0, 'publicados' => 0, 'conferidos' => 0];

        $linhas = $this->service->oQueFazerAgora($alvo, $empresaParaTela, $contagens, ['chips' => []], [], ['situacao' => 'sincronizado', 'novas' => 0]);

        $this->assertSame([], $linhas);
    }

    /** @test */
    public function test_o_que_fazer_agora_ordem_fixa_e_motivos_distintos(): void
    {
        $company = Company::factory()->create();
        $this->criarAcervo($company, 'MLBL1', 'paused', 0, ['motivos' => [MlAcervoItem::MOTIVO_PAUSADO], 'severidade' => MlAcervoItem::SEVERIDADE_CRITICA]);

        $alvo = ['mlb_empresa' => null, 'company' => $company, 'programa' => 'gestao', 'chave' => $company->chaveContaMl()];
        $empresaParaTela = ['token' => 'ativo', 'link_reconexao' => null, 'company_id' => $company->id, 'portal' => []];
        $contagemProdutos = ['com_problema' => 3, 'publicados' => 2, 'conferidos' => 1];
        $chips = ['chips' => [
            ['chave' => MlAcervoItem::MOTIVO_PAUSADO, 'label' => 'Pausado', 'count' => 2, 'cor' => 'red'],
            ['chave' => MlAcervoItem::MOTIVO_SEM_ESTOQUE, 'label' => 'Sem estoque', 'count' => 1, 'cor' => 'red'],
            ['chave' => MlAcervoItem::MOTIVO_FICHA_INCOMPLETA, 'label' => 'Ficha incompleta', 'count' => 2, 'cor' => 'amber'],
            ['chave' => MlAcervoItem::MOTIVO_PERDENDO_CATALOGO, 'label' => 'Perdendo catálogo', 'count' => 0, 'cor' => 'amber'],
            ['chave' => MlAcervoItem::MOTIVO_FOTO_INSUFICIENTE, 'label' => 'Foto insuficiente', 'count' => 1, 'cor' => 'amber'],
        ]];
        $situacaoPortal = ['situacao' => 'novas', 'novas' => 5, 'sincronizado_em' => null];
        // Dois rascunhos 'conferir' com pendência (linha 6) — o de menor 'faltam' tem que ser citado.
        $produtos = [
            ['nome' => 'Mais pendente', 'status' => ['chave' => 'conferir', 'faltam' => 8]],
            ['nome' => 'Quase pronto', 'status' => ['chave' => 'conferir', 'faltam' => 2]],
            ['nome' => 'A preencher (faltam=0, não entra)', 'status' => ['chave' => 'rascunho', 'faltam' => 0]],
        ];

        $linhas = $this->service->oQueFazerAgora($alvo, $empresaParaTela, $contagemProdutos, $chips, [], $situacaoPortal, $produtos);

        $this->assertSame([
            'Anúncios pausados ou sem estoque',
            'Produtos com problema na publicação',
            'Prontos para a Fase 2',
            'Conferidos, prontos para publicar',
            'Rascunhos com pendências',
            'Ofertas novas no Portal',
            'Ficha incompleta',
            'Foto insuficiente',
        ], array_column($linhas, 'texto'), 'ordem fixa da seção 3 — linha 6 entra entre "conferidos" (5) e "Portal" (7); perdendo_catalogo (count=0) não entra');

        $this->assertSame(3, $linhas[0]['numero'], 'pausados(2) + sem_estoque(1)');
        $this->assertSame(1, $linhas[0]['legado'], 'conta quantos dos pausados/sem estoque são legado');
        $porTexto = collect($linhas)->keyBy('texto');
        $this->assertSame(5, $porTexto['Ofertas novas no Portal']['numero']);
        $this->assertSame(['acao' => 'sincronizar'], $porTexto['Ofertas novas no Portal']['destino']);

        $this->assertSame(2, $porTexto['Rascunhos com pendências']['numero'], 'só os status=conferir com faltam>0 contam');
        $this->assertSame('Quase pronto', $porTexto['Rascunhos com pendências']['exemplo']['nome'], 'cita o de MENOR faltam');
        $this->assertSame(2, $porTexto['Rascunhos com pendências']['exemplo']['faltam']);
        $this->assertSame(
            ['rota' => 'mlb.anuncios.publicador.produtos', 'params' => ['conta' => $company->chaveContaMl(), 'filtro' => 'rascunho']],
            $porTexto['Rascunhos com pendências']['destino']
        );
    }

    /** @test */
    public function test_o_que_fazer_agora_linha_6_fica_fora_sem_pendencia_ou_sem_produtos(): void
    {
        $alvo = ['mlb_empresa' => null, 'company' => null, 'programa' => 'polos', 'chave' => 'empresa-1'];
        $empresaParaTela = ['token' => 'ativo', 'link_reconexao' => null, 'company_id' => null, 'portal' => []];
        $contagens = ['com_problema' => 0, 'publicados' => 0, 'conferidos' => 0];

        // Sem $produtos (chamada antiga, parâmetro omitido) — linha 6 não aparece, nada quebra.
        $linhas = $this->service->oQueFazerAgora($alvo, $empresaParaTela, $contagens, ['chips' => []], [], ['situacao' => 'sincronizado', 'novas' => 0]);
        $this->assertSame([], $linhas);

        // Com produtos, mas nenhum 'conferir' com faltam>0 — também não aparece.
        $produtos = [
            ['nome' => 'Pronto', 'status' => ['chave' => 'pronto', 'faltam' => 0]],
            ['nome' => 'Em preenchimento sem pendência', 'status' => ['chave' => 'conferir', 'faltam' => 0]],
        ];
        $linhas = $this->service->oQueFazerAgora($alvo, $empresaParaTela, $contagens, ['chips' => []], [], ['situacao' => 'sincronizado', 'novas' => 0], $produtos);
        $this->assertSame([], $linhas);
    }

    // ═══ situacaoProdutos() ══════════════════════════════════════════════

    /** @test */
    public function test_situacao_produtos(): void
    {
        $r = $this->service->situacaoProdutos(['rascunho' => 2, 'conferidos' => 1, 'publicados' => 3, 'com_problema' => 0]);

        $this->assertSame(2, $r['rascunho']['numero']);
        $this->assertSame(1, $r['conferidos']['numero']);
        $this->assertSame(3, $r['publicados']['numero']);
        $this->assertSame(0, $r['com_problema']['numero']);
    }

    // ═══ integracoes() ═══════════════════════════════════════════════════

    /** @test */
    public function test_integracoes_com_erp_outro(): void
    {
        $empresa = MlbEmpresa::create(['nome' => 'Polo ERP', 'projeto' => 'POLOS'])->fresh();
        MlbImplementacao::create([
            'empresa_id' => $empresa->id,
            'token' => (string) Str::uuid(),
            'dados' => ['itens' => ['erp' => ['valor' => 'Outro', 'outro' => 'ERP Caseiro', 'acesso' => '', 'feito' => true]]],
        ]);
        $alvo = $this->alvoDaEmpresa($empresa);
        $empresaParaTela = ['token' => 'ativo', 'portal' => ['situacao' => 'sem_portal', 'novas' => 0, 'sincronizado_em' => null]];

        $r = $this->service->integracoes($alvo, $empresaParaTela);

        $this->assertSame('ativo', $r['mercado_livre']['token']);
        $this->assertFalse($r['publicacao_liberada']);
        $this->assertFalse($r['alavancas_liberada']);
        $this->assertSame('ERP Caseiro', $r['erp']['rotulo']);
    }

    /** @test */
    public function test_integracoes_sem_erp_informado_mostra_nao_informado(): void
    {
        $empresa = MlbEmpresa::create(['nome' => 'Polo Sem ERP', 'projeto' => 'POLOS'])->fresh();
        $alvo = $this->alvoDaEmpresa($empresa);
        $empresaParaTela = ['token' => 'sem_token', 'portal' => ['situacao' => 'sem_portal', 'novas' => 0, 'sincronizado_em' => null]];

        $r = $this->service->integracoes($alvo, $empresaParaTela);

        $this->assertSame('Não informado', $r['erp']['rotulo']);
        $this->assertNull($r['erp']['valor']);
    }

    // ═══ identidadeResumo() ══════════════════════════════════════════════

    /** @test */
    public function test_identidade_resumo_devolve_so_as_3_primeiras_linhas(): void
    {
        $company = Company::factory()->create();
        CreativeIdentidade::create(['company_id' => $company->id, 'texto' => "Linha 1\nLinha 2\n\nLinha 3\nLinha 4\nLinha 5"]);

        $r = $this->service->identidadeResumo($this->alvoDaCompany($company));

        $this->assertTrue($r['tem_identidade']);
        $this->assertSame(['Linha 1', 'Linha 2', 'Linha 3'], $r['texto_resumo']);
    }

    /** @test */
    public function test_identidade_resumo_sem_registro(): void
    {
        $company = Company::factory()->create();

        $r = $this->service->identidadeResumo($this->alvoDaCompany($company));

        $this->assertFalse($r['tem_identidade']);
        $this->assertNull($r['texto_resumo']);
    }

    // ═══ ultimasPublicacoes() ════════════════════════════════════════════

    /** @test */
    public function test_ultimas_publicacoes_sem_company(): void
    {
        $empresa = MlbEmpresa::create(['nome' => 'Sem Company 2', 'projeto' => 'POLOS'])->fresh();

        $r = $this->service->ultimasPublicacoes($this->alvoDaEmpresa($empresa));

        $this->assertFalse($r['disponivel']);
        $this->assertSame([], $r['itens']);
    }

    /** @test */
    public function test_ultimas_publicacoes_com_company(): void
    {
        $company = Company::factory()->create();
        $dev = User::factory()->create(['name' => 'Dev']);

        $this->publicar(null, $company, ['equipe' => true, 'id' => $dev->id, 'nome' => $dev->name], now()->subDays(10), 'MLB900');
        $this->publicar(null, $company, ['equipe' => false, 'id' => 1, 'nome' => 'Cliente'], now()->subDays(1), 'MLB901');
        $this->criarAcervo($company, 'MLB901', 'active', 7);

        $r = $this->service->ultimasPublicacoes($this->alvoDaCompany($company));

        $this->assertTrue($r['disponivel']);
        $this->assertCount(2, $r['itens']);
        $this->assertSame('MLB901', $r['itens'][0]['ml_item_id'], 'mais recente primeiro, sem filtro de janela');
        $this->assertSame('cliente', $r['itens'][0]['quem']['tipo']);
        $this->assertSame(7, $r['itens'][0]['vendas']);
        $this->assertSame(PubPublicacao::PUBLISHED, $r['itens'][0]['situacao']);
        $this->assertSame('classico', $r['itens'][0]['tipo'], 'gold_special mapeia para o tipo classico da régua');
        $this->assertSame('equipe', $r['itens'][1]['quem']['tipo']);
        $this->assertNull($r['itens'][1]['vendas'], 'sem linha correspondente em MlAcervoItem — null, nunca zero');
    }
}
