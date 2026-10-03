<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Fase 164 / 164-03: tela A — empresas do programa com conta do ML (critério 1 do ROADMAP). */
class MlbPublicadorEntradaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function props(string $query = ''): array
    {
        $r = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/mlb/anuncios'.$query)
            ->assertOk();
        $page = $r->viewData('page');
        $this->assertSame('Mlb/AnunciosEmpresas', $page['component']);

        return $page['props'];
    }

    private function nomes(string $query = ''): array
    {
        return collect($this->props($query)['empresas'])->pluck('nome')->all();
    }

    private function token(array $ancora, array $extra = []): MlToken
    {
        return MlToken::create($ancora + $extra + [
            'ml_user_id' => (string) random_int(1000, 99999999),
            'access_token' => 'APP_USR-x',
            'refresh_token' => 'TG-x',
            'expires_at' => now()->addHours(5),
            'status' => 'active',
        ]);
    }

    private function empresa(string $nome, array $attrs = [], ?array $token = null): MlbEmpresa
    {
        $e = MlbEmpresa::create($attrs + ['nome' => $nome, 'projeto' => 'POLOS'])->fresh();
        if ($token !== null) {
            $this->token(['mlb_empresa_id' => $e->id], $token);
        }

        return $e;
    }

    public function test_nao_admin_recebe_403(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'consultor']))->get('/mlb/anuncios')->assertForbidden();
    }

    public function test_sem_parametro_ou_invalido_e_polos(): void
    {
        $this->empresa('Polo A', [], []);
        $this->empresa('Inc A', ['projeto' => 'Incubadora'], []);

        $this->assertSame('polos', $this->props()['programa']);
        $this->assertSame('polos', $this->props('?programa=xyz')['programa']);
        $this->assertSame(['Polo A'], $this->nomes('?programa=xyz'));
    }

    public function test_polos_lista_formas_de_conta_e_exclui_arquivada_sem_conta_e_incubadora(): void
    {
        $this->empresa('Token Proprio', [], []);

        $company = Company::factory()->create();
        $this->token(['company_id' => $company->id]);
        $this->empresa('Token Na Company', ['company_id' => $company->id]);

        $this->empresa('Token Vencido', [], ['expires_at' => now()->subHour()]);

        $autorizou = $this->empresa('So Carimbo');
        MlbImplementacao::create(['empresa_id' => $autorizou->id, 'token' => 'tok-abc', 'dados' => ['ml_oauth' => ['autorizado_em' => '2026-08-12T14:03:00Z']]]);

        $this->empresa('Arquivada', ['arquivado_em' => now()], []);
        $this->empresa('Sem Conta');
        $this->empresa('Da Incubadora', ['projeto' => 'Incubadora'], []);

        $linhas = collect($this->props('?programa=polos')['empresas'])->keyBy('nome');

        $this->assertEqualsCanonicalizing(['Token Proprio', 'Token Na Company', 'Token Vencido', 'So Carimbo'], $linhas->keys()->all());
        $this->assertSame('ativo', $linhas['Token Proprio']['token']);
        $this->assertSame('ativo', $linhas['Token Na Company']['token']);
        $this->assertSame('expirado', $linhas['Token Vencido']['token']);
        $this->assertTrue($linhas['Token Vencido']['token_expirado']);
        $this->assertSame('sem_token', $linhas['So Carimbo']['token']);
        $this->assertFalse($linhas['So Carimbo']['tem_token']);
        $this->assertStringContainsString('tok-abc', (string) $linhas['So Carimbo']['link_reconexao']);
        $this->assertStringStartsWith('empresa-', $linhas['Token Proprio']['chave']);
    }

    public function test_incubadora_lista_as_tres_formas(): void
    {
        $this->empresa('Por Projeto', ['projeto' => 'Incubadora'], []);
        $this->empresa('Por Fase', ['projeto' => null, 'fase' => 'Incubadora'], []);
        $this->empresa('Por Tipo', ['projeto' => null, 'tipo' => 'INCUBADORA'], []);
        $this->empresa('De Polos', [], []);

        $this->assertEqualsCanonicalizing(['Por Projeto', 'Por Fase', 'Por Tipo'], $this->nomes('?programa=incubadora'));
        $this->assertSame(['De Polos'], $this->nomes('?programa=polos'));
    }

    public function test_gestao_lista_company_com_token_e_exclui_ligada_a_polos_ou_incubadora(): void
    {
        $livre = Company::factory()->create(['name' => 'Cliente Livre']);
        $this->token(['company_id' => $livre->id]);
        $semToken = Company::factory()->create(['name' => 'Sem Token']);
        $ligadaPolos = Company::factory()->create(['name' => 'Ligada Polos']);
        $this->token(['company_id' => $ligadaPolos->id]);
        $this->empresa('Polo', ['company_id' => $ligadaPolos->id]);
        $ligadaInc = Company::factory()->create(['name' => 'Ligada Inc']);
        $this->token(['company_id' => $ligadaInc->id]);
        $this->empresa('Inc', ['projeto' => 'Incubadora', 'company_id' => $ligadaInc->id]);
        $ligadaArquivada = Company::factory()->create(['name' => 'Ligada Arquivada']);
        $this->token(['company_id' => $ligadaArquivada->id]);
        $this->empresa('Arq', ['company_id' => $ligadaArquivada->id, 'arquivado_em' => now()]);

        $this->assertEqualsCanonicalizing(['Cliente Livre', 'Ligada Arquivada'], $this->nomes('?programa=gestao'));
        $linha = collect($this->props('?programa=gestao')['empresas'])->firstWhere('nome', 'Cliente Livre');
        $this->assertSame('company-'.$livre->id, $linha['chave']);
        $this->assertSame('company', $linha['tipo']);
    }

    public function test_situacao_do_portal(): void
    {
        $this->empresa('Sem Company', [], []);

        $vazia = Company::factory()->create();
        $this->empresa('Company Sem Ofertas', ['company_id' => $vazia->id], []);

        $nunca = Company::factory()->create();
        EstruturaOferta::create(['company_id' => $nunca->id, 'sku' => 'N1', 'fase' => 'simples', 'nome' => 'N1']);
        $this->empresa('Nunca', ['company_id' => $nunca->id], []);

        $parcial = Company::factory()->create();
        $o1 = EstruturaOferta::create(['company_id' => $parcial->id, 'sku' => 'P1', 'fase' => 'simples', 'nome' => 'P1']);
        EstruturaOferta::create(['company_id' => $parcial->id, 'sku' => 'P2', 'fase' => 'simples', 'nome' => 'P2']);
        EstruturaOferta::create(['company_id' => $parcial->id, 'sku' => 'P3', 'fase' => 'simples', 'nome' => 'P3']);
        PubProduto::daOferta($o1);
        $this->empresa('Novas', ['company_id' => $parcial->id], []);

        $completa = Company::factory()->create();
        $oc = EstruturaOferta::create(['company_id' => $completa->id, 'sku' => 'C1', 'fase' => 'simples', 'nome' => 'C1']);
        PubProduto::daOferta($oc);
        $this->empresa('Sincronizada', ['company_id' => $completa->id], []);

        $l = collect($this->props()['empresas'])->keyBy('nome');

        $this->assertSame('sem_portal', $l['Sem Company']['portal']['situacao']);
        $this->assertSame('sem_portal', $l['Company Sem Ofertas']['portal']['situacao']);
        $this->assertSame('nunca', $l['Nunca']['portal']['situacao']);
        $this->assertSame('novas', $l['Novas']['portal']['situacao']);
        $this->assertSame(2, $l['Novas']['portal']['novas']);
        $this->assertSame('sincronizado', $l['Sincronizada']['portal']['situacao']);
        $this->assertNotNull($l['Sincronizada']['portal']['sincronizado_em']);
    }

    public function test_produtos_prontos_publicados_pela_regra_ou(): void
    {
        $company = Company::factory()->create();
        $e = $this->empresa('Loja', ['company_id' => $company->id], []);

        $porEmpresa = PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => 'A', 'nome' => 'A']);
        $porCompany = PubProduto::create(['company_id' => $company->id, 'sku' => 'B', 'nome' => 'B']);
        PubProduto::create(['mlb_empresa_id' => $e->id, 'company_id' => $company->id, 'sku' => 'C', 'nome' => 'C']);
        PubProduto::create(['sku' => 'Z', 'nome' => 'De outra']);

        PubRascunho::create(['produto_id' => $porEmpresa->id, 'status' => PubRascunho::VALIDATED]);
        $r2 = PubRascunho::create(['produto_id' => $porCompany->id, 'status' => PubRascunho::PUBLISHED]);
        $pub = PubPublicacao::create(['rascunho_id' => $r2->id, 'revisao' => 1, 'modelo_publicacao' => 'items', 'status' => 'PUBLISHED',
            'chave_idempotencia' => (string) Str::uuid(), 'concluida_em' => now()]);
        PubPublicacaoItem::create(['publicacao_id' => $pub->id, 'indice' => 0, 'listing_type_id' => 'gold_special',
            'variante_chave' => ChaveCanonica::UNICA, 'status' => PubPublicacaoItem::CREATED, 'ml_item_id' => 'MLB1']);
        PubPublicacaoItem::create(['publicacao_id' => $pub->id, 'indice' => 1, 'listing_type_id' => 'gold_pro',
            'variante_chave' => ChaveCanonica::UNICA, 'status' => PubPublicacaoItem::FAILED]);

        $props = $this->props();
        $l = $props['empresas'][0];

        $this->assertSame(3, $l['produtos']);
        $this->assertSame(1, $l['prontos']);
        $this->assertSame(1, $l['publicados']);
        $this->assertSame(1, $props['indicadores']['prontos']);
        $this->assertSame(1, $props['indicadores']['publicados_mes']);
    }

    public function test_liberada_so_para_a_ancora_com_token_na_lista(): void
    {
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);
        $a = $this->empresa('A', [], []);
        $b = $this->empresa('B', [], []);

        $this->assertSame([false, false], collect($this->props()['empresas'])->pluck('liberada')->all());

        config(['publicador.contas_liberadas.mlb_empresas' => [$a->id]]);
        $l = collect($this->props()['empresas'])->keyBy('nome');
        $this->assertTrue($l['A']['liberada']);
        $this->assertFalse($l['B']['liberada']);

        // Company com o mesmo id da MlbEmpresa liberada NÃO libera a empresa.
        config(['publicador.contas_liberadas' => ['companies' => [$b->id], 'mlb_empresas' => []]]);
        $l = collect($this->props()['empresas'])->keyBy('nome');
        $this->assertFalse($l['B']['liberada']);
    }

    public function test_programas_e_indicadores(): void
    {
        $this->empresa('P1', [], []);
        $this->empresa('P2', [], []);
        $this->empresa('I1', ['projeto' => 'Incubadora'], []);
        $c = Company::factory()->create();
        $this->token(['company_id' => $c->id]);
        $this->empresa('Sem Conta');

        $props = $this->props();
        $this->assertSame(['polos' => 2, 'incubadora' => 1, 'gestao' => 1], $props['programas']);
        $this->assertSame(2, $props['indicadores']['empresas']);
        $this->assertSame(0, $props['indicadores']['pct_sincronizado']);
    }

    public function test_busca_filtros_e_paginacao(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->empresa(sprintf('Loja %02d', $i), [], []);
        }
        $this->empresa('Vencida Especial', [], ['expires_at' => now()->subHour()]);

        $p = $this->props();
        $this->assertCount(50, $p['empresas']);
        $this->assertSame(56, $p['paginacao']['total']);
        $this->assertSame([1, 50], [$p['paginacao']['de'], $p['paginacao']['ate']]);
        $this->assertSame(50, $p['paginacao']['por_pagina']);

        $p2 = $this->props('?pagina=2');
        $this->assertCount(6, $p2['empresas']);
        $this->assertSame([51, 56], [$p2['paginacao']['de'], $p2['paginacao']['ate']]);

        $this->assertSame(['Loja 07'], $this->nomes('?busca=loja 07'));
        $this->assertSame(['Vencida Especial'], $this->nomes('?filtro=atencao'));
        $this->assertSame([], $this->nomes('?filtro=prontos'));
        $this->assertSame([], $this->nomes('?filtro=nunca'));
    }

    public function test_numero_de_consultas_nao_cresce_com_o_numero_de_empresas(): void
    {
        $cenario = function (int $n, string $prefixo) {
            for ($i = 0; $i < $n; $i++) {
                $c = Company::factory()->create();
                EstruturaOferta::create(['company_id' => $c->id, 'sku' => 'S'.$i, 'fase' => 'simples', 'nome' => 'S']);
                $e = $this->empresa("$prefixo $i", ['company_id' => $c->id], []);
                PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => 'X', 'nome' => 'X']);
            }
        };
        $consultas = function (): int {
            $this->actingAs(User::factory()->create(['role' => 'admin']));
            $this->get('/mlb/anuncios')->assertOk(); // aquece caches de boot (não conta)
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/mlb/anuncios')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $cenario(3, 'Pequena');
        $com3 = $consultas();
        $cenario(27, 'Grande');
        $com30 = $consultas();

        $this->assertSame($com3, $com30, "3 empresas: $com3 consultas; 30 empresas: $com30");
    }
}
