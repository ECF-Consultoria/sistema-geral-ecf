<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubValidacao;
use App\Models\User;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 173 Plano 04 (VISG-03..08) — rota `visao-geral`: o JSON completo que
 * a Visão geral (plans 06/07) vai renderizar, ZERO chamada ao Mercado Livre.
 */
class VisaoGeralTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/mlb/anuncios/publicador';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Nenhum teste desta classe deve chamar o Mercado Livre — qualquer tentativa falha o teste.
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function empresa(array $attrs = [], bool $comToken = true): MlbEmpresa
    {
        $e = MlbEmpresa::create($attrs + ['nome' => 'Polo X', 'projeto' => 'POLOS'])->fresh();
        if ($comToken) {
            MlToken::create([
                'mlb_empresa_id' => $e->id, 'ml_user_id' => '123456', 'access_token' => 'APP_USR-x',
                'refresh_token' => 'TG-x', 'expires_at' => now()->addHours(5), 'status' => 'active',
            ]);
        }

        return $e;
    }

    private function pagina(string $url): array
    {
        return $this->actingAs($this->admin())->get($url)->assertOk()->viewData('page');
    }

    public function test_visao_geral_devolve_todas_as_chaves_do_contrato_sem_chamada_ao_ml(): void
    {
        $e = $this->empresa();
        $page = $this->pagina(self::BASE.'/empresas/empresa-'.$e->id.'/visao-geral');

        $this->assertSame('Mlb/Publicador/VisaoGeral', $page['component']);
        $p = $page['props'];

        // Quick 261010-hdr: `atividadeEquipe` entra ADITIVA — o gate de forma
        // é ATUALIZADO para a chave nova, nunca afrouxado.
        foreach (['empresa', 'liberada', 'indicadores', 'oQueFazerAgora', 'situacaoProdutos', 'ultimasPublicacoes', 'atividadeEquipe', 'integracoes', 'identidadeResumo', 'quemPublicou', 'abas'] as $chave) {
            $this->assertArrayHasKey($chave, $p, "chave '$chave' ausente do contrato");
        }

        $this->assertSame('ativo', $p['empresa']['token']);
        $this->assertFalse($p['liberada']);
        $this->assertSame(0, $p['indicadores']['publicados_30d']);
        $this->assertFalse($p['ultimasPublicacoes']['disponivel'], 'empresa sem Company — D23');
        $this->assertNull($p['abas']['company_id']);
    }

    public function test_visao_geral_de_conta_inexistente_da_404(): void
    {
        $this->actingAs($this->admin())->get(self::BASE.'/empresas/empresa-999999/visao-geral')->assertNotFound();
        $this->actingAs($this->admin())->get(self::BASE.'/empresas/company-999999/visao-geral')->assertNotFound();
    }

    public function test_visao_geral_token_expirado_mostra_so_a_linha_de_reconexao(): void
    {
        $e = MlbEmpresa::create(['nome' => 'Expirada', 'projeto' => 'POLOS'])->fresh();
        MlToken::create([
            'mlb_empresa_id' => $e->id, 'ml_user_id' => '1', 'access_token' => 'x', 'refresh_token' => 'y',
            'expires_at' => now()->subHour(), 'status' => 'active',
        ]);
        PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => 'A', 'nome' => 'A', 'origem' => 'publicador']);
        $r = PubRascunho::create(['produto_id' => PubProduto::first()->id, 'status' => PubRascunho::FAILED]);

        $p = $this->pagina(self::BASE.'/empresas/empresa-'.$e->id.'/visao-geral')['props'];

        $this->assertSame('expirado', $p['empresa']['token']);
        $this->assertCount(1, $p['oQueFazerAgora'], 'token expirado — nenhuma outra linha, mesmo com produto com erro');
        $this->assertSame('Conta precisa de reconexão', $p['oQueFazerAgora'][0]['texto']);
    }

    public function test_visao_geral_de_empresa_sem_company_desabilita_ultimas_publicacoes_sem_erro(): void
    {
        $e = $this->empresa();

        $p = $this->pagina(self::BASE.'/empresas/empresa-'.$e->id.'/visao-geral')['props'];

        $this->assertSame(['disponivel' => false, 'itens' => []], $p['ultimasPublicacoes']);
        $this->assertNull($p['indicadores']['no_ar']);
        $this->assertFalse($p['indicadores']['acervo_disponivel']);
    }

    public function test_visao_geral_com_company_mostra_publicacoes_e_quem_publicou(): void
    {
        $c = Company::factory()->create();
        MlToken::create(['company_id' => $c->id, 'ml_user_id' => '9', 'access_token' => 'x', 'refresh_token' => 'y',
            'expires_at' => now()->addHours(5), 'status' => 'active']);
        $dev = User::factory()->create(['name' => 'Dev ECF']);
        $produto = PubProduto::create(['company_id' => $c->id, 'sku' => 'G1', 'nome' => 'Gestão', 'origem' => 'publicador']);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id, 'status' => PubRascunho::PUBLISHED]);
        $publicacao = PubPublicacao::create([
            'rascunho_id' => $rascunho->id, 'revisao' => 1, 'modelo_publicacao' => 'items', 'status' => PubPublicacao::PUBLISHED,
            'chave_idempotencia' => (string) Str::uuid(), 'concluida_em' => now()->subDays(2),
            'ator' => ['equipe' => true, 'id' => $dev->id, 'nome' => $dev->name],
        ]);
        PubPublicacaoItem::create([
            'publicacao_id' => $publicacao->id, 'indice' => 0, 'listing_type_id' => 'gold_special',
            'variante_chave' => ChaveCanonica::UNICA, 'status' => PubPublicacaoItem::CREATED,
            'ml_item_id' => 'MLB999', 'payload' => ['family_name' => 'Produto Gestão'],
        ]);

        $p = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props'];

        $this->assertSame(1, $p['indicadores']['publicados_30d']);
        $this->assertTrue($p['ultimasPublicacoes']['disponivel']);
        $this->assertCount(1, $p['ultimasPublicacoes']['itens']);
        $this->assertSame('MLB999', $p['ultimasPublicacoes']['itens'][0]['ml_item_id']);
        $this->assertCount(1, $p['quemPublicou']['equipe']);
        $this->assertSame('Dev ECF', $p['quemPublicou']['equipe'][0]['nome']);
        $this->assertSame($p['quemPublicou']['equipe'][0]['quantidade'] + $p['quemPublicou']['cliente']['quantidade'] + $p['quemPublicou']['origem_antiga']['quantidade'], $p['indicadores']['publicados_30d'], 'o total do bloco "quem publicou" bate com o indicador de 30 dias');
    }

    /** Fase 173, Plano 04b — lacuna 1: linha 6 ("Rascunhos com pendências") ponta a ponta. */
    public function test_visao_geral_mostra_rascunhos_com_pendencias_citando_o_de_menor_faltam(): void
    {
        $c = Company::factory()->create();
        MlToken::create(['company_id' => $c->id, 'ml_user_id' => '9', 'access_token' => 'x', 'refresh_token' => 'y',
            'expires_at' => now()->addHours(5), 'status' => 'active']);

        $p1 = PubProduto::create(['company_id' => $c->id, 'sku' => 'P1', 'nome' => 'Mais pendente', 'origem' => 'publicador']);
        PubRascunho::create(['produto_id' => $p1->id, 'status' => PubRascunho::DRAFT, 'step_state' => ['resumo' => ['bloqueios' => 6]]]);

        $p2 = PubProduto::create(['company_id' => $c->id, 'sku' => 'P2', 'nome' => 'Quase pronto', 'origem' => 'publicador']);
        PubRascunho::create(['produto_id' => $p2->id, 'status' => PubRascunho::DRAFT, 'step_state' => ['resumo' => ['bloqueios' => 1]]]);

        $p = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props'];

        $porTexto = collect($p['oQueFazerAgora'])->keyBy('texto');
        $this->assertTrue($porTexto->has('Rascunhos com pendências'));
        $this->assertSame(2, $porTexto['Rascunhos com pendências']['numero']);
        $this->assertSame('Quase pronto', $porTexto['Rascunhos com pendências']['exemplo']['nome'], 'cita o de MENOR faltam');
        $this->assertSame(1, $porTexto['Rascunhos com pendências']['exemplo']['faltam']);
    }

    public function test_nao_admin_recebe_403(): void
    {
        $e = $this->empresa();
        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->get(self::BASE.'/empresas/empresa-'.$e->id.'/visao-geral')->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Quick 261009-t02 — redesign da tela 02 (Dashboard do Publicador).
    //
    // Tudo ADITIVO: as chaves antigas de `indicadores` continuam todas lá, com
    // o mesmo nome e o mesmo valor. O que entra é `no_ar_por_fase`,
    // `criativos_packs`, `tracao_pct` e a prop nova `alertas`.
    // ═══════════════════════════════════════════════════════════════════════

    /** Uma Company com token de ML ativo — o alvo `company-{id}` das contas de Gestão. */
    private function companyComToken(): Company
    {
        $c = Company::factory()->create();
        MlToken::create(['company_id' => $c->id, 'ml_user_id' => '9', 'access_token' => 'x', 'refresh_token' => 'y',
            'expires_at' => now()->addHours(5), 'status' => 'active']);

        return $c;
    }

    private function acervo(Company $c, string $mlItemId, string $status, int $vendidos, array $extra = []): MlAcervoItem
    {
        return MlAcervoItem::create(array_merge([
            'company_id' => $c->id,
            'ml_item_id' => $mlItemId,
            'title' => 'Item',
            'status' => $status,
            'available_quantity' => 10,
            'sold_quantity' => $vendidos,
            'nota_ecf' => 60,
            'motivos' => [],
            'severidade' => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'origem' => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em' => now(),
        ], $extra));
    }

    /** Produto com rascunho PUBLISHED — `prontidao()` devolve a chave 'publicado' (= no ar). */
    private function produtoNoAr(Company $c, string $sku, array $extra = []): PubProduto
    {
        $p = PubProduto::create(array_merge([
            'company_id' => $c->id, 'sku' => $sku, 'nome' => $sku, 'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ], $extra));
        PubRascunho::create(['produto_id' => $p->id, 'status' => PubRascunho::PUBLISHED]);

        return $p;
    }

    public function test_indicadores_mantem_todas_as_chaves_antigas_e_ganha_as_novas(): void
    {
        $c = $this->companyComToken();

        $p = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props'];

        // Gate de FORMA: nenhuma chave antiga pode sumir nem mudar de nome.
        foreach (['no_ar', 'com_venda', 'sem_oferta', 'publicados_30d', 'publicados_30d_pessoas', 'acervo_disponivel', 'nunca_coletado'] as $chave) {
            $this->assertArrayHasKey($chave, $p['indicadores'], "chave ANTIGA '$chave' sumiu de indicadores");
        }
        foreach (['no_ar_por_fase', 'criativos_packs', 'tracao_pct'] as $chave) {
            $this->assertArrayHasKey($chave, $p['indicadores'], "chave NOVA '$chave' ausente de indicadores");
        }
        $this->assertArrayHasKey('alertas', $p, 'a prop `alertas` é o bloco de Alertas da coluna lateral');
    }

    public function test_no_ar_por_fase_separa_base_de_kits_e_rotula_kits_nunca_fase_2(): void
    {
        $c = $this->companyComToken();
        $base = $this->produtoNoAr($c, 'BASE-1');
        $this->produtoNoAr($c, 'BASE-2');
        // Kit de 2 e kit de 3 (Fase 3) — os DOIS contam como "kits", nunca como "Fase 2".
        $this->produtoNoAr($c, 'KIT-2', ['produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2]);
        $this->produtoNoAr($c, 'KIT-3', ['produto_base_id' => $base->id, 'quantidade_kit' => 3, 'fase' => 3]);
        // Produto sem rascunho nenhum: não está no ar, não entra em nenhum dos dois.
        PubProduto::create(['company_id' => $c->id, 'sku' => 'PARADO', 'nome' => 'Parado', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        $p = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props'];

        $this->assertSame(2, $p['indicadores']['no_ar_por_fase']['fase1'], 'só os dois bases publicados');
        $this->assertSame(2, $p['indicadores']['no_ar_por_fase']['kits'], 'kit de 2 E kit de 3 — "kits", não "Fase 2"');
    }

    public function test_no_ar_por_fase_de_conta_sem_produto_nenhum_e_zero_de_verdade(): void
    {
        $c = $this->companyComToken();

        $p = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props'];

        $this->assertSame(['fase1' => 0, 'kits' => 0], $p['indicadores']['no_ar_por_fase'], 'nenhum produto = zero medido, não "não sabemos"');
    }

    public function test_criativos_packs_conta_os_kits_de_criativo_da_conta(): void
    {
        $c = $this->companyComToken();

        $semKit = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props'];
        $this->assertSame(0, $semKit['indicadores']['criativos_packs']);

        MlAnuncioCriativoKit::create(['token' => Str::random(32), 'company_id' => $c->id, 'status' => MlAnuncioCriativoKit::STATUS_PRONTO]);
        MlAnuncioCriativoKit::create(['token' => Str::random(32), 'company_id' => $c->id, 'status' => MlAnuncioCriativoKit::STATUS_APROVADO]);
        // Kit de OUTRA conta nunca entra.
        MlAnuncioCriativoKit::create(['token' => Str::random(32), 'company_id' => Company::factory()->create()->id, 'status' => MlAnuncioCriativoKit::STATUS_PRONTO]);

        $comKit = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props'];
        $this->assertSame(2, $comKit['indicadores']['criativos_packs']);
    }

    public function test_tracao_pct_e_a_razao_entre_com_venda_e_no_ar(): void
    {
        $c = $this->companyComToken();
        $this->acervo($c, 'MLBT1', 'active', 5);
        $this->acervo($c, 'MLBT2', 'active', 1);
        $this->acervo($c, 'MLBT3', 'active', 0);
        $this->acervo($c, 'MLBT4', 'paused', 0);

        $p = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props'];

        $this->assertSame(4, $p['indicadores']['no_ar']);
        $this->assertSame(2, $p['indicadores']['com_venda']);
        $this->assertSame(50, $p['indicadores']['tracao_pct']);
    }

    public function test_tracao_pct_e_nulo_e_nunca_zero_quando_nao_ha_o_que_medir(): void
    {
        // (a) acervo coletado, mas nenhum anúncio acionável: no_ar = 0, nada a dividir.
        $c = $this->companyComToken();
        $this->acervo($c, 'MLBF1', 'closed', 9);
        $p = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props'];
        $this->assertSame(0, $p['indicadores']['no_ar']);
        $this->assertNull($p['indicadores']['tracao_pct'], 'no_ar = 0 não vira 0%');

        // (b) acervo NUNCA coletado — "não medimos" nunca pode virar 0%.
        $c2 = $this->companyComToken();
        $p2 = $this->pagina(self::BASE.'/empresas/company-'.$c2->id.'/visao-geral')['props'];
        $this->assertTrue($p2['indicadores']['nunca_coletado']);
        $this->assertNull($p2['indicadores']['tracao_pct']);

        // (c) empresa sem Company: não há acervo nenhum para consultar.
        $e = $this->empresa();
        $p3 = $this->pagina(self::BASE.'/empresas/empresa-'.$e->id.'/visao-geral')['props'];
        $this->assertFalse($p3['indicadores']['acervo_disponivel']);
        $this->assertNull($p3['indicadores']['tracao_pct']);
    }

    public function test_alertas_espelham_a_triagem_do_acervo_sem_reimplementar_motivo(): void
    {
        $c = $this->companyComToken();
        $this->acervo($c, 'MLBA1', 'paused', 0, ['motivos' => [MlAcervoItem::MOTIVO_PAUSADO], 'severidade' => MlAcervoItem::SEVERIDADE_CRITICA]);
        $this->acervo($c, 'MLBA2', 'active', 0, ['motivos' => [MlAcervoItem::MOTIVO_FICHA_INCOMPLETA], 'severidade' => MlAcervoItem::SEVERIDADE_ATENCAO]);
        $this->acervo($c, 'MLBA3', 'active', 0, ['motivos' => [MlAcervoItem::MOTIVO_FICHA_INCOMPLETA, MlAcervoItem::MOTIVO_FOTO_INSUFICIENTE], 'severidade' => MlAcervoItem::SEVERIDADE_ATENCAO]);

        $alertas = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props']['alertas'];

        $this->assertTrue($alertas['disponivel']);
        // A ordem e os rótulos são os de `AcervoTriagemService::motivosDef()` — fonte única.
        $this->assertSame(
            ['pausado', 'sem_estoque', 'ficha_incompleta', 'perdendo_catalogo', 'foto_insuficiente'],
            array_column($alertas['itens'], 'chave')
        );
        $porChave = collect($alertas['itens'])->keyBy('chave');
        $this->assertSame('Pausado', $porChave['pausado']['label']);
        $this->assertSame('red', $porChave['pausado']['cor']);
        $this->assertSame(1, $porChave['pausado']['total']);
        $this->assertSame(2, $porChave['ficha_incompleta']['total']);
        $this->assertSame(1, $porChave['foto_insuficiente']['total']);
        $this->assertSame(0, $porChave['perdendo_catalogo']['total']);
        // O total é o de anúncios DISTINTOS com motivo (3), nunca a soma dos chips (4).
        $this->assertSame(3, $alertas['total']);
    }

    public function test_alertas_de_empresa_sem_company_nao_afirmam_zero(): void
    {
        $e = $this->empresa();

        $alertas = $this->pagina(self::BASE.'/empresas/empresa-'.$e->id.'/visao-geral')['props']['alertas'];

        $this->assertFalse($alertas['disponivel'], 'sem Company não há acervo para triar — não é "zero alertas"');
        $this->assertSame([], $alertas['itens']);
    }

    public function test_as_chaves_novas_nao_criam_n_mais_1_na_visao_geral(): void
    {
        $c = $this->companyComToken();
        $base = $this->produtoNoAr($c, 'N1');
        for ($i = 2; $i <= 12; $i++) {
            $this->produtoNoAr($c, 'N'.$i, ['produto_base_id' => $base->id, 'quantidade_kit' => $i, 'fase' => $i]);
            MlAnuncioCriativoKit::create(['token' => Str::random(32), 'company_id' => $c->id, 'status' => MlAnuncioCriativoKit::STATUS_PRONTO]);
            $this->acervo($c, 'MLBN'.$i, 'active', $i % 2);
        }

        $admin = $this->admin();
        $url = self::BASE.'/empresas/company-'.$c->id.'/visao-geral';
        // Aquece: a primeira requisição carrega schemas/config que não são do laço.
        $this->actingAs($admin)->get($url)->assertOk();

        DB::enableQueryLog();
        $this->actingAs($admin)->get($url)->assertOk();
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Teto generoso de propósito: o que o gate prova é que 11 produtos/11 kits
        // de criativo NÃO viram uma consulta por item (N+1), não o número exato.
        $this->assertLessThan(60, $consultas, "consultas demais na Visão geral ({$consultas}) — cheiro de N+1 nas chaves novas");
    }

    // ═══ Atividade da equipe — dado REAL das 3 fontes (quick 261010-hdr) ════
    //
    // A tela entregou este widget MOCKADO ("Gerou 5 imagens IA", "revisão
    // aprovada") e o usuário apontou em 10/10 que o dado dinâmico já existe.
    // Estes testes são o contrato das três fontes reais.

    /** Produto + rascunho da conta, para pendurar publicação/kit/conferência. */
    private function rascunhoDaConta(Company $c, string $sku, array $extraProduto = []): PubRascunho
    {
        $produto = PubProduto::create(array_merge([
            'company_id' => $c->id, 'sku' => $sku, 'nome' => $sku, 'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ], $extraProduto));

        return PubRascunho::create(['produto_id' => $produto->id, 'status' => PubRascunho::PUBLISHED]);
    }

    /**
     * Kit de criativo com `created_at` CONTROLADO.
     *
     * ⚠️ `created_at` NÃO está no `$fillable` de `MlAnuncioCriativoKit`: passá-lo
     * para `create()` é silenciosamente descartado e o kit nasce com `now()` —
     * foi o que fez a primeira rodada destes testes ordenar errado. O carimbo
     * vai por `forceFill` com `timestamps` desligado.
     */
    private function kitDeCriativo(Company $c, ?int $userId, int $imagens, string $quando, ?PubRascunho $rascunho = null): MlAnuncioCriativoKit
    {
        $kit = MlAnuncioCriativoKit::create([
            'token' => Str::random(32), 'company_id' => $c->id, 'user_id' => $userId,
            'pub_rascunho_id' => $rascunho?->id,
            'status' => $imagens > 0 ? MlAnuncioCriativoKit::STATUS_PRONTO : MlAnuncioCriativoKit::STATUS_PLANEJANDO,
            'imagens_geradas' => $imagens,
        ]);
        $kit->timestamps = false;
        $kit->forceFill(['created_at' => $quando, 'updated_at' => $quando])->save();

        return $kit;
    }

    /** Uma publicação concluída com UM item CREATED (é o que `baseQuery()` enxerga). */
    private function publicacaoConcluida(PubRascunho $rascunho, ?array $ator, string $quando, string $mlItemId = 'MLB1'): PubPublicacao
    {
        $publicacao = PubPublicacao::create([
            'rascunho_id' => $rascunho->id, 'revisao' => 1, 'modelo_publicacao' => 'items',
            'status' => PubPublicacao::PUBLISHED, 'chave_idempotencia' => (string) Str::uuid(),
            'concluida_em' => $quando, 'ator' => $ator,
        ]);
        PubPublicacaoItem::create([
            'publicacao_id' => $publicacao->id, 'indice' => 0, 'listing_type_id' => 'gold_special',
            'variante_chave' => ChaveCanonica::UNICA, 'status' => PubPublicacaoItem::CREATED,
            'ml_item_id' => $mlItemId, 'payload' => ['family_name' => 'Família'],
        ]);

        return $publicacao;
    }

    public function test_atividade_da_equipe_junta_as_tres_fontes_reais_em_ordem_desc(): void
    {
        $c = $this->companyComToken();
        $dev = User::factory()->create(['name' => 'Dev ECF']);

        // (1) conferiu — o mais antigo dos três.
        $rConf = $this->rascunhoDaConta($c, 'CONF');
        PubValidacao::create([
            'rascunho_id' => $rConf->id, 'revisao' => 2, 'camada' => 'L3',
            'resultado' => 'OK', 'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5),
        ]);

        // (2) gerou criativos — o do meio.
        $rKit = $this->rascunhoDaConta($c, 'KITCRI');
        $this->kitDeCriativo($c, $dev->id, 2, now()->subDays(3)->toDateTimeString(), $rKit);

        // (3) publicou — o mais recente.
        $rPub = $this->rascunhoDaConta($c, 'PUBLI');
        $this->publicacaoConcluida($rPub, ['equipe' => true, 'id' => $dev->id, 'nome' => $dev->name], now()->subDay()->toDateTimeString());

        $atividade = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props']['atividadeEquipe'];

        $this->assertTrue($atividade['disponivel']);
        $this->assertCount(3, $atividade['itens']);
        // A ordem é por data desc: publicou (1d) → criativos (3d) → conferiu (5d).
        $this->assertSame(['publicou', 'criativos', 'conferiu'], array_column($atividade['itens'], 'tipo'));

        // Shape ESCALAR em TODO item — nenhum objeto que a tela possa renderizar cru.
        foreach ($atividade['itens'] as $item) {
            $this->assertSame(['tipo', 'quem', 'quando_iso', 'titulo', 'detalhe'], array_keys($item));
            foreach ($item as $chave => $valor) {
                $this->assertTrue($valor === null || is_string($valor), "item['$chave'] não é escalar de texto");
            }
        }

        [$publicou, $criativos, $conferiu] = $atividade['itens'];
        $this->assertSame('Dev ECF', $publicou['quem']);
        $this->assertSame('PUBLI', $publicou['titulo']);
        $this->assertSame('1 unidade', $publicou['detalhe']);

        $this->assertSame('Dev ECF', $criativos['quem']);
        $this->assertSame('KITCRI', $criativos['titulo']);
        $this->assertSame('2 imagens geradas', $criativos['detalhe']);

        $this->assertSame('Conferência automática', $conferiu['quem'], 'pub_validacoes não tem autor — nunca inventar um');
        $this->assertSame('CONF', $conferiu['titulo']);
        $this->assertSame('Revisão 2 passou sem problemas', $conferiu['detalhe']);
    }

    public function test_atividade_da_equipe_sem_company_nao_afirma_zero(): void
    {
        $e = $this->empresa();

        $p = $this->pagina(self::BASE.'/empresas/empresa-'.$e->id.'/visao-geral')['props'];

        $this->assertSame(['disponivel' => false, 'itens' => []], $p['atividadeEquipe'], 'sem Company — D23, "não sabemos" não é "é zero"');
    }

    public function test_atividade_da_equipe_sem_nenhuma_das_tres_fontes_nao_afirma_zero(): void
    {
        $c = $this->companyComToken();

        $p = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props'];

        $this->assertSame(['disponivel' => false, 'itens' => []], $p['atividadeEquipe']);
    }

    public function test_atividade_da_equipe_com_ator_sem_id_e_ator_cliente_nunca_vira_undefined(): void
    {
        $c = $this->companyComToken();

        // Ator do PORTAL (equipe falso): é o cliente — T-173-09, nome/id não saem.
        $this->publicacaoConcluida($this->rascunhoDaConta($c, 'PORTAL'), ['equipe' => false, 'id' => 77, 'nome' => 'João Cliente'], now()->subDay()->toDateTimeString(), 'MLBP1');
        // Ator migrado do assistente ANTIGO: sem `id` nenhum.
        $this->publicacaoConcluida($this->rascunhoDaConta($c, 'ANTIGO'), ['equipe' => true, 'nome' => 'quem sabe'], now()->subDays(2)->toDateTimeString(), 'MLBA1');
        // Ator nulo na coluna — a forma mais crua de "não sabemos".
        $this->publicacaoConcluida($this->rascunhoDaConta($c, 'NULO'), null, now()->subDays(3)->toDateTimeString(), 'MLBN1');
        // Equipe com id que não existe mais em `users` (usuário removido).
        $this->publicacaoConcluida($this->rascunhoDaConta($c, 'FANTASMA'), ['equipe' => true, 'id' => 999999], now()->subDays(4)->toDateTimeString(), 'MLBF1');
        // Kit SEM user_id — gerado por rotina ou linha antiga.
        $this->kitDeCriativo($c, null, 1, now()->subDays(5)->toDateTimeString());

        $itens = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props']['atividadeEquipe']['itens'];

        $this->assertSame(['Cliente', 'Origem antiga', 'Origem antiga', 'Equipe', 'Autor não registrado'], array_column($itens, 'quem'));
        foreach ($itens as $item) {
            $this->assertIsString($item['quem']);
            $this->assertNotSame('', $item['quem']);
            $this->assertStringNotContainsStringIgnoringCase('undefined', $item['quem']);
            // O nome do cliente do Portal NUNCA pode vazar para a tela.
            $this->assertStringNotContainsString('João Cliente', json_encode($item, JSON_UNESCAPED_UNICODE));
        }
        // Kit sem rascunho do Publicador: sem título, nunca "undefined".
        $this->assertNull($itens[4]['titulo']);
        $this->assertSame('1 imagem gerada', $itens[4]['detalhe']);
    }

    public function test_atividade_da_equipe_respeita_o_limite_e_ignora_kit_que_nao_gerou(): void
    {
        $c = $this->companyComToken();
        $dev = User::factory()->create(['name' => 'Dev ECF']);

        for ($i = 1; $i <= 6; $i++) {
            $this->publicacaoConcluida($this->rascunhoDaConta($c, 'P'.$i), ['equipe' => true, 'id' => $dev->id], now()->subMinutes($i)->toDateTimeString(), 'MLBL'.$i);
        }
        for ($i = 1; $i <= 6; $i++) {
            $this->kitDeCriativo($c, $dev->id, 2, now()->subHours($i)->toDateTimeString());
        }
        // Kit que ainda NÃO gerou imagem: "gerou 0 imagens" não é evento.
        $this->kitDeCriativo($c, $dev->id, 0, now()->toDateTimeString());

        $atividade = $this->pagina(self::BASE.'/empresas/company-'.$c->id.'/visao-geral')['props']['atividadeEquipe'];

        $this->assertCount(8, $atividade['itens'], 'o limite padrão é 8');
        // As 6 publicações (minutos) vêm antes dos kits (horas) — ordem desc.
        $this->assertSame(array_merge(array_fill(0, 6, 'publicou'), ['criativos', 'criativos']), array_column($atividade['itens'], 'tipo'));
    }

    public function test_atividade_da_equipe_nao_cria_n_mais_1(): void
    {
        $c = $this->companyComToken();
        $dev = User::factory()->create(['name' => 'Dev ECF']);
        $outro = User::factory()->create(['name' => 'Outro Dev']);

        // 12 de cada fonte, com autores diferentes alternados: se os nomes
        // fossem resolvidos por linha (`User::find`), a contagem explodiria.
        for ($i = 1; $i <= 12; $i++) {
            $r = $this->rascunhoDaConta($c, 'Q'.$i);
            $this->publicacaoConcluida($r, ['equipe' => true, 'id' => $i % 2 === 0 ? $dev->id : $outro->id], now()->subMinutes($i)->toDateTimeString(), 'MLBQ'.$i);
            $this->kitDeCriativo($c, $i % 2 === 0 ? $dev->id : $outro->id, 2, now()->subHours($i)->toDateTimeString(), $r);
            PubValidacao::create([
                'rascunho_id' => $r->id, 'revisao' => 1, 'camada' => 'L3', 'resultado' => 'AVISOS',
                'created_at' => now()->subDays($i), 'updated_at' => now()->subDays($i),
            ]);
        }

        $alvo = app(ProgramasPublicadorService::class)->resolver('company-'.$c->id);
        $painel = app(PainelVisaoGeralService::class);

        // Aquece (schema/config fora do laço) e mede SÓ o método novo.
        $painel->atividadeDaEquipe($alvo);

        DB::enableQueryLog();
        $atividade = $painel->atividadeDaEquipe($alvo);
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(8, $atividade['itens']);
        // Teto duro: 3 fontes + 1 `whereIn` de nomes = 4. Nada por linha.
        $this->assertLessThanOrEqual(4, $consultas, "atividadeDaEquipe fez {$consultas} consultas — é N+1");
    }
}
