<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        foreach (['empresa', 'liberada', 'indicadores', 'oQueFazerAgora', 'situacaoProdutos', 'ultimasPublicacoes', 'integracoes', 'identidadeResumo', 'quemPublicou', 'abas'] as $chave) {
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
}
