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

/**
 * Fase 164 / 164-03: tela A — empresas com conta do ML (critério 1 do ROADMAP).
 * 26/10/2026: os três programas numa lista só; a busca e o filtro de programa
 * passaram para o navegador (ver `MlbPublicadorEntradaController::index`).
 */
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

    /** Nomes das linhas de UM programa — o corte que antes era a aba. */
    private function nomesDo(string $programa): array
    {
        return collect($this->props()['empresas'])->where('programa', $programa)->pluck('nome')->values()->all();
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

    /**
     * 26/10/2026: uma lista só. Antes cada programa era uma aba e a busca só
     * procurava na aba escolhida — empresa de Polos nunca era achada em Gestão.
     */
    public function test_lista_unica_com_os_tres_programas_e_cada_linha_diz_o_seu(): void
    {
        $this->empresa('Polo A', [], []);
        $this->empresa('Inc A', ['projeto' => 'Incubadora'], []);
        $c = Company::factory()->create(['name' => 'Gestao A']);
        $this->token(['company_id' => $c->id]);

        $linhas = collect($this->props()['empresas'])->keyBy('nome');

        $this->assertSame(['Gestao A', 'Inc A', 'Polo A'], $linhas->keys()->all(), 'ordem alfabética, programas misturados');
        $this->assertSame(['polos', 'Polos'], [$linhas['Polo A']['programa'], $linhas['Polo A']['programa_rotulo']]);
        $this->assertSame(['incubadora', 'Incubadora'], [$linhas['Inc A']['programa'], $linhas['Inc A']['programa_rotulo']]);
        $this->assertSame(['gestao', 'Gestão'], [$linhas['Gestao A']['programa'], $linhas['Gestao A']['programa_rotulo']]);
    }

    /** `?programa=` só escolhe a etiqueta inicial: não corta a lista nem fica na sessão. */
    public function test_programa_da_url_so_escolhe_a_etiqueta_inicial(): void
    {
        $this->empresa('Polo A', [], []);
        $this->empresa('Inc A', ['projeto' => 'Incubadora'], []);

        $this->assertSame('todos', $this->props()['programa']);
        $this->assertSame('todos', $this->props('?programa=xyz')['programa']);

        $comPrograma = $this->props('?programa=incubadora');
        $this->assertSame('incubadora', $comPrograma['programa']);
        $this->assertEqualsCanonicalizing(['Polo A', 'Inc A'], collect($comPrograma['empresas'])->pluck('nome')->all());

        // A sessão não guarda mais o último programa (Fase 172-02 aposentada em 26/10/2026).
        $this->assertSame('todos', $this->props()['programa']);
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

        $this->assertEqualsCanonicalizing(['Token Proprio', 'Token Na Company', 'Token Vencido', 'So Carimbo'], $this->nomesDo('polos'));
        $linhas = collect($this->props()['empresas'])->keyBy('nome');
        $this->assertNotContains('Arquivada', $linhas->keys()->all());
        $this->assertNotContains('Sem Conta', $linhas->keys()->all());
        $this->assertSame('ativo', $linhas['Token Proprio']['token']);
        $this->assertSame('ativo', $linhas['Token Na Company']['token']);
        // 20/10/2026: vencido renova sozinho na 1ª chamada — a conta não aparece desconectada.
        $this->assertSame('ativo', $linhas['Token Vencido']['token']);
        $this->assertFalse($linhas['Token Vencido']['token_expirado']);
        $this->assertNull($linhas['Token Vencido']['link_reconexao']);
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

        $this->assertEqualsCanonicalizing(['Por Projeto', 'Por Fase', 'Por Tipo'], $this->nomesDo('incubadora'));
        $this->assertSame(['De Polos'], $this->nomesDo('polos'));
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

        $this->assertEqualsCanonicalizing(['Cliente Livre', 'Ligada Arquivada'], $this->nomesDo('gestao'));
        $linha = collect($this->props()['empresas'])->firstWhere('nome', 'Cliente Livre');
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
        $this->assertSame(1, $l['publicados_mes']);
        // 26/10/2026: os cartões de número saíram da tela — o servidor não monta mais `indicadores`.
        $this->assertArrayNotHasKey('indicadores', $props);
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

    /**
     * A contagem por programa é a das LINHAS da lista. Antes contava só as contas
     * com token: a aba dizia "Polos 60" e a lista mostrava 96 (as que autorizaram
     * e falta reconectar entram na lista).
     */
    public function test_programas_contam_as_linhas_da_lista(): void
    {
        $this->empresa('P1', [], []);
        $this->empresa('P2', [], []);
        $carimbo = $this->empresa('P3 Falta Reconectar');
        MlbImplementacao::create(['empresa_id' => $carimbo->id, 'token' => 'tok-p3', 'dados' => ['ml_oauth' => ['autorizado_em' => '2026-08-12T14:03:00Z']]]);
        $this->empresa('I1', ['projeto' => 'Incubadora'], []);
        $c = Company::factory()->create();
        $this->token(['company_id' => $c->id]);
        $this->empresa('Sem Conta');

        $props = $this->props();
        $this->assertSame(['polos' => 3, 'incubadora' => 1, 'gestao' => 1], $props['programas']);
        $this->assertCount(array_sum($props['programas']), $props['empresas']);
    }

    /** Sem paginação, e `busca`/`filtro`/`pagina` de links antigos não cortam nada. */
    public function test_lista_inteira_sem_paginacao_e_parametros_antigos_ignorados(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->empresa(sprintf('Loja %02d', $i), [], []);
        }
        $carimbo = $this->empresa('Falta Reconectar');
        MlbImplementacao::create(['empresa_id' => $carimbo->id, 'token' => 'tok-esp', 'dados' => ['ml_oauth' => ['autorizado_em' => '2026-08-12T14:03:00Z']]]);

        $p = $this->props();
        $this->assertCount(56, $p['empresas']);
        $this->assertSame('Falta Reconectar', $p['empresas'][0]['nome']);
        $this->assertSame('Loja 55', $p['empresas'][55]['nome']);
        foreach (['paginacao', 'filtros', 'indicadores'] as $chave) {
            $this->assertArrayNotHasKey($chave, $p, $chave);
        }

        $this->assertCount(56, $this->props('?busca=loja 07&filtro=atencao&pagina=2')['empresas']);
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
