<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\ProgramasPublicadorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 175 Plano 175-08 (§6 e §7 da ETAPA-3) — a lista de produtos e a Visão
 * geral passam a conhecer fases.
 *
 * ⚠️ Este arquivo é, antes de tudo, um **gate de regressão**. Os dois serviços
 * mexidos aqui (`ProgramasPublicadorService` e `PainelVisaoGeralService`)
 * alimentam a Etapa 1 e a Etapa 2, que estão EM PRODUÇÃO desde 08/10: uma chave
 * que sai do presenter derruba Produtos, Editor e Visão geral de uma vez. Por
 * isso as chaves de hoje são comparadas contra um ARRAY LITERAL, e não contra o
 * próprio código.
 *
 * ⚠️ E contra a lição de 07/10 ("a tela preta"): um campo do presenter chegou
 * como objeto e foi renderizado como texto React ("Objects are not valid as a
 * React child"), derrubando a página. Toda chave nova tem aqui uma prova de
 * FORMATO (escalar quando é escalar; array com forma conhecida quando não é).
 *
 * @group phase175
 */
class ListaPorFaseTest extends TestCase
{
    use RefreshDatabase;

    /** As chaves que `produtosParaTela()` JÁ devolvia antes desta plan. NENHUMA pode sair. */
    private const CHAVES_DE_HOJE = [
        'id', 'sku', 'nome', 'origem', 'oferta_id', 'rascunho_id', 'status', 'status_rascunho',
        'anuncios', 'parcial', 'atualizado_em', 'conta_nome', 'conta_diferente', 'liberada',
    ];

    /** As chaves novas da §7 que o 175-10 vai consumir. */
    private const CHAVES_NOVAS = [
        'fase', 'quantidade_kit', 'produto_base_id', 'eh_kit', 'rotulo_fase', 'url_produto',
        'sugestao_kit', 'kits', 'base',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // O HandleInertiaRequests busca os sinais do ECF Drive em toda página
        // autenticada: `Http::fake()` sem `assertNothingSent()` estrito.
        Http::fake();
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** @return array{0: MlbEmpresa, 1: Company} */
    private function conta(): array
    {
        $company = Company::factory()->create();
        $empresa = MlbEmpresa::create(['nome' => 'Polo das Fases', 'projeto' => 'POLOS', 'company_id' => $company->id]);
        MlToken::create([
            'company_id' => $company->id, 'ml_user_id' => '1555596317',
            'access_token' => 'fake-access', 'refresh_token' => 'fake-refresh',
            'expires_at' => now()->addHours(5), 'status' => 'active',
        ]);

        return [$empresa->fresh(), $company];
    }

    private function produto(MlbEmpresa $e, Company $c, string $sku, array $extra = []): PubProduto
    {
        return PubProduto::create($extra + [
            'mlb_empresa_id' => $e->id,
            'company_id' => $c->id,
            'sku' => $sku,
            'nome' => $sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    private function rascunho(PubProduto $p, string $status): PubRascunho
    {
        return PubRascunho::create(['produto_id' => $p->id, 'status' => $status, 'revisao' => 1]);
    }

    private function servico(): ProgramasPublicadorService
    {
        return app(ProgramasPublicadorService::class);
    }

    /** @return array<int, array> a lista indexada pelo id do produto */
    private function lista(MlbEmpresa $e, Company $c, ?string $chave = null): array
    {
        $saida = [];
        foreach ($this->servico()->produtosParaTela($e, $c, $chave) as $linha) {
            $saida[(int) $linha['id']] = $linha;
        }

        return $saida;
    }

    // ═══ Task 1 — o contrato da lista ════════════════════════════════════════

    public function test_lista_mantem_todas_as_chaves_de_hoje_e_acrescenta_as_de_fase(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $this->rascunho($base, PubRascunho::PUBLISHED);

        $linha = $this->lista($e, $c)[$base->id];

        foreach (self::CHAVES_DE_HOJE as $chave) {
            $this->assertArrayHasKey($chave, $linha, "a chave '$chave' da Etapa 1/2 NÃO pode sair do contrato");
        }
        foreach (self::CHAVES_NOVAS as $chave) {
            $this->assertArrayHasKey($chave, $linha, "a chave nova '$chave' (§7) faltou");
        }

        // Tipos das chaves de hoje, do jeito que Produtos.jsx e Editor.jsx leem.
        $this->assertIsInt($linha['id']);
        $this->assertIsString($linha['sku']);
        $this->assertIsString($linha['nome']);
        $this->assertSame('publicador', $linha['origem']);
        $this->assertNull($linha['oferta_id']);
        $this->assertIsArray($linha['status']);
        $this->assertSame('publicado', $linha['status']['chave']);
        $this->assertIsArray($linha['anuncios']);
        $this->assertIsBool($linha['liberada']);

        // Lição de 07/10: o que é escalar TEM de chegar escalar.
        $this->assertIsInt($linha['fase']);
        $this->assertIsInt($linha['quantidade_kit']);
        $this->assertNull($linha['produto_base_id']);
        $this->assertIsBool($linha['eh_kit']);
        $this->assertIsString($linha['rotulo_fase']);
        $this->assertNull($linha['url_produto'], 'sem a chave da conta, url_produto é null — nenhum chamador antigo quebra');
        $this->assertNull($linha['sugestao_kit']);
        $this->assertIsArray($linha['kits']);
        $this->assertNull($linha['base']);

        // Produto que já existia continua Fase 1, base, 1 unidade (o default é o backfill).
        $this->assertSame(1, $linha['fase']);
        $this->assertSame(1, $linha['quantidade_kit']);
        $this->assertFalse($linha['eh_kit']);
        $this->assertSame('1 unidade', $linha['rotulo_fase']);
        $this->assertSame([], $linha['kits']);
    }

    public function test_base_com_dois_kits_traz_os_ids_e_cada_kit_traz_o_base(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $kit2 = $this->produto($e, $c, 'CAD-KIT2', ['produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2]);
        $kit3 = $this->produto($e, $c, 'CAD-KIT3', ['produto_base_id' => $base->id, 'quantidade_kit' => 3, 'fase' => 3]);

        $lista = $this->lista($e, $c);

        $this->assertSame([$kit2->id, $kit3->id], $lista[$base->id]['kits'], 'o base lista os kits na ordem das fases');
        $this->assertSame([], $lista[$kit2->id]['kits'], 'kit nunca tem kits (sem cadeia)');

        // O kit traz o base para a lista poder recuá-lo — forma {id, sku, nome}, só escalares.
        $this->assertSame(
            ['id' => $base->id, 'sku' => 'CAD', 'nome' => 'CAD'],
            $lista[$kit2->id]['base'],
        );
        $this->assertTrue($lista[$kit2->id]['eh_kit']);
        $this->assertSame(2, $lista[$kit2->id]['quantidade_kit']);
        $this->assertSame('Kit 2', $lista[$kit2->id]['rotulo_fase']);
        $this->assertSame('Kit 3', $lista[$kit3->id]['rotulo_fase']);
        $this->assertSame($base->id, $lista[$kit2->id]['produto_base_id']);
    }

    public function test_kit_com_base_apagado_aparece_como_linha_de_topo(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $kit = $this->produto($e, $c, 'CAD-KIT2', ['produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2]);

        // SET NULL (`pubprod_base_fk`): apagar o base deixa o kit solto COM o histórico.
        $base->delete();

        $lista = $this->lista($e, $c);

        $this->assertArrayHasKey($kit->id, $lista, 'o kit órfão NUNCA fica invisível');
        $this->assertNull($lista[$kit->id]['produto_base_id']);
        $this->assertNull($lista[$kit->id]['base'], 'sem base, a linha é de topo');
        $this->assertFalse($lista[$kit->id]['eh_kit'], 'sem base não é kit de ninguém');
        $this->assertSame('Kit 2', $lista[$kit->id]['rotulo_fase'], 'o rótulo segue dizendo quantas unidades ele leva');
        $this->assertSame(2, $lista[$kit->id]['fase']);
    }

    public function test_url_produto_aponta_para_a_tela_do_produto_quando_a_conta_e_passada(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');

        $linha = $this->lista($e, $c, 'empresa-'.$e->id)[$base->id];

        $this->assertSame(
            route('mlb.anuncios.publicador.produto', ['conta' => 'empresa-'.$e->id, 'produto' => $base->id]),
            $linha['url_produto'],
        );
        $this->assertIsString($linha['url_produto']);
    }

    public function test_sugestao_de_vinculo_chega_na_lista_e_produto_que_nao_e_kit_sai_sem_sugestao(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $combo = $this->produto($e, $c, 'CAD-CB2');
        // Nome com "+" = kit misto: a §6 proíbe o palpite.
        $misto = $this->produto($e, $c, 'COMBIT', ['nome' => 'Combit 4 Cadeira Escritório + 1 MESA REDONDA']);

        $lista = $this->lista($e, $c);

        $this->assertIsArray($lista[$combo->id]['sugestao_kit']);
        $this->assertSame($base->id, $lista[$combo->id]['sugestao_kit']['base_id']);
        $this->assertSame('CAD', $lista[$combo->id]['sugestao_kit']['base_sku']);
        $this->assertSame('sku', $lista[$combo->id]['sugestao_kit']['origem']);

        $this->assertNull($lista[$misto->id]['sugestao_kit'], 'kit misto nunca recebe sugestão');
        $this->assertNull($lista[$base->id]['sugestao_kit'], 'quem já é base de um candidato não recebe sugestão');
    }

    public function test_sugestao_sai_em_uma_passada_e_o_numero_de_consultas_nao_cresce_com_a_lista(): void
    {
        [$e, $c] = $this->conta();
        for ($i = 1; $i <= 3; $i++) {
            $this->produto($e, $c, 'P3-'.$i);
        }

        $comTres = $this->contarConsultas($e, $c);

        [$e2, $c2] = $this->conta();
        for ($i = 1; $i <= 12; $i++) {
            $this->produto($e2, $c2, 'P12-'.$i);
        }

        $comDoze = $this->contarConsultas($e2, $c2);

        $this->assertSame(
            $comTres,
            $comDoze,
            "T-175-34: o número de consultas tem de ser constante (3 produtos: $comTres; 12 produtos: $comDoze) — sugestão em lote, nunca uma por produto"
        );
        $this->assertLessThan(20, $comDoze, 'teto de sanidade: a lista inteira cabe em poucas consultas agrupadas');
    }

    private function contarConsultas(MlbEmpresa $e, Company $c): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->servico()->produtosParaTela($e, $c, 'empresa-'.$e->id);
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $total;
    }

    // ═══ Task 1 — `contagemProdutos` ganha `por_fase` ════════════════════════

    public function test_contagem_mantem_os_seis_buckets_de_hoje_e_acrescenta_por_fase(): void
    {
        $c = $this->servico()->contagemProdutos([]);

        foreach (['todos', 'rascunho', 'conferidos', 'publicados', 'com_problema', 'sem_oferta'] as $chave) {
            $this->assertArrayHasKey($chave, $c, "o bucket '$chave' da Etapa 1/2 não pode sair");
            $this->assertSame(0, $c[$chave]);
        }

        $this->assertSame(
            ['sem_oferta' => 0, 'fase1_publicada' => 0, 'fase2_preparacao' => 0, 'fase2_publicada' => 0, 'fase3_mais' => 0],
            $c['por_fase'],
            'array vazio devolve os 5 buckets zerados, nunca ausentes'
        );
    }

    public function test_por_fase_classifica_cada_produto_em_exatamente_um_bucket(): void
    {
        $produtos = [
            // Base sem anúncio no ar.
            ['status' => ['chave' => 'rascunho'], 'oferta_id' => null, 'anuncios' => [], 'fase' => 1, 'eh_kit' => false],
            ['status' => ['chave' => 'pronto'], 'oferta_id' => 1, 'anuncios' => [], 'fase' => 1, 'eh_kit' => false],
            ['status' => ['chave' => 'erro'], 'oferta_id' => 2, 'anuncios' => [], 'fase' => 1, 'eh_kit' => false],
            // Base publicado / parcial.
            ['status' => ['chave' => 'publicado'], 'oferta_id' => 3, 'anuncios' => [['ml_item_id' => 'MLB1']], 'fase' => 1, 'eh_kit' => false],
            ['status' => ['chave' => 'parcial'], 'oferta_id' => 4, 'anuncios' => [['ml_item_id' => 'MLB2']], 'fase' => 1, 'eh_kit' => false],
            // Kit de fase 2 em preparação.
            ['status' => ['chave' => 'rascunho'], 'oferta_id' => null, 'anuncios' => [], 'fase' => 2, 'eh_kit' => true],
            ['status' => ['chave' => 'conferir'], 'oferta_id' => null, 'anuncios' => [], 'fase' => 2, 'eh_kit' => true],
            ['status' => ['chave' => 'publicando'], 'oferta_id' => null, 'anuncios' => [], 'fase' => 2, 'eh_kit' => true],
            // Kit de fase 2 publicado.
            ['status' => ['chave' => 'publicado'], 'oferta_id' => null, 'anuncios' => [['ml_item_id' => 'MLB3']], 'fase' => 2, 'eh_kit' => true],
            // Fase 3+, qualquer status.
            ['status' => ['chave' => 'rascunho'], 'oferta_id' => null, 'anuncios' => [], 'fase' => 3, 'eh_kit' => true],
            ['status' => ['chave' => 'publicado'], 'oferta_id' => null, 'anuncios' => [['ml_item_id' => 'MLB4']], 'fase' => 4, 'eh_kit' => true],
        ];

        $c = $this->servico()->contagemProdutos($produtos);

        $this->assertSame(
            ['sem_oferta' => 3, 'fase1_publicada' => 2, 'fase2_preparacao' => 3, 'fase2_publicada' => 1, 'fase3_mais' => 2],
            $c['por_fase'],
        );
        $this->assertSame(
            $c['todos'],
            array_sum($c['por_fase']),
            'por_fase soma exatamente `todos`: nenhum produto em dois buckets, nenhum fora'
        );

        // Os 6 buckets de hoje seguem calculados do mesmo jeito, no mesmo laço.
        $this->assertSame(11, $c['todos']);
        $this->assertSame(5, $c['rascunho'], 'rascunho + conferir + publicando caem no bucket default de hoje');
        $this->assertSame(1, $c['conferidos']);
        $this->assertSame(4, $c['publicados']);
        $this->assertSame(1, $c['com_problema']);
        $this->assertSame(7, $c['sem_oferta'], 'o `sem_oferta` de hoje é oferta_id === null — NÃO é o bucket por_fase de mesmo nome');
    }

    public function test_contagem_aceita_a_lista_antiga_sem_as_chaves_de_fase(): void
    {
        // Chamador que ainda passe o shape de antes: nada quebra e a soma fecha.
        $c = $this->servico()->contagemProdutos([
            ['status' => ['chave' => 'publicado'], 'oferta_id' => 7],
            ['status' => ['chave' => 'rascunho'], 'oferta_id' => null],
        ]);

        $this->assertSame(2, $c['todos']);
        $this->assertSame(['sem_oferta' => 1, 'fase1_publicada' => 1, 'fase2_preparacao' => 0, 'fase2_publicada' => 0, 'fase3_mais' => 0], $c['por_fase']);
        $this->assertSame($c['todos'], array_sum($c['por_fase']));
    }

    public function test_por_fase_bate_com_a_lista_produto_por_produto(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $this->rascunho($base, PubRascunho::PUBLISHED);
        $kit = $this->produto($e, $c, 'CAD-KIT2', ['produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2]);
        $this->rascunho($kit, PubRascunho::DRAFT);
        $solto = $this->produto($e, $c, 'SOLTO');

        $lista = $this->servico()->produtosParaTela($e, $c, 'empresa-'.$e->id);
        $contagem = $this->servico()->contagemProdutos($lista);

        $this->assertSame(
            ['sem_oferta' => 1, 'fase1_publicada' => 1, 'fase2_preparacao' => 1, 'fase2_publicada' => 0, 'fase3_mais' => 0],
            $contagem['por_fase'],
        );
        $this->assertSame(3, $contagem['todos']);
        $this->assertSame($contagem['todos'], array_sum($contagem['por_fase']));
        $this->assertCount(3, $lista);
        $this->assertNotNull($solto->id);
    }

    public function test_a_tela_de_produtos_continua_respondendo_com_o_contrato_de_hoje(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD');
        $this->rascunho($base, PubRascunho::PUBLISHED);

        $props = $this->actingAs($this->admin())
            ->get('/mlb/anuncios/publicador/empresas/empresa-'.$e->id)
            ->assertOk()->viewData('page')['props'];

        $this->assertCount(1, $props['produtos']);
        $this->assertSame(1, $props['contagens']['todos']);
        $this->assertSame(1, $props['contagens']['publicados']);
        $this->assertArrayHasKey('por_fase', $props['contagens']);
        $this->assertSame(1, $props['contagens']['por_fase']['fase1_publicada']);
        // A tela passa a chave da conta: a coluna Fases do 175-10 precisa do link.
        $this->assertSame(
            route('mlb.anuncios.publicador.produto', ['conta' => 'empresa-'.$e->id, 'produto' => $base->id]),
            $props['produtos'][0]['url_produto'],
        );
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'mercadolibre'));
    }
}
