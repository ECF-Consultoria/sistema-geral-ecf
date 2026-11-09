<?php

namespace Tests\Feature\Phase202;

use App\Models\Company;
use App\Models\ContratoRenovacao;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Services\Hubspot\HubspotErroApi;
use App\Services\Renovacao\RenovacaoDecisao;
use App\Services\Renovacao\RenovacaoDetector;
use App\Services\Renovacao\RenovacaoOrquestrador;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Fase 202 (REN-04/REN-07) — orquestração idempotente, ponta a ponta com Http::fake.
 */
class RenovacaoOrquestradorTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL = 'D-ORIG-1';

    /** @var array<int, array{id: string, properties: array}> estado remoto simulado */
    private array $deals = [];
    /** @var array<int, string> empresas associadas ao deal criado */
    private array $associadas = ['HC1'];
    private string $modoCriar = 'ok'; // ok | timeout | 400 | 429 | 500
    private int $statusSearch = 200;
    /** POSTs de criação tentados (Http::recorded não registra o que estoura por timeout). */
    private int $postsTentados = 0;
    /** 09/11/2026 — vínculo: contatos do negócio original, contatos por e-mail, empresas do contato. */
    private array $contatosDoOriginal = [];
    private array $contatosPorEmail = [];
    private array $empresasDoContato = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-01 03:00:00');
        Sleep::fake();
        config(['services.hubspot.access_token' => 'token-fake', 'services.hubspot.portal_id' => '123']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cenario(array $empresa = []): ContratoRenovacao
    {
        $c = Company::factory()->create(array_merge([
            'active' => true, 'hubspot_company_id' => 'HC1', 'hubspot_contact_id' => 'HK1', 'hubspot_owner_id' => '99',
        ], $empresa));
        $s = Servico::create([
            'nome' => 'Gestao', 'valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo' => true, 'setor' => Servico::SETOR_PERFORMANCE, 'exige_contrato' => true,
        ]);
        ContratoServico::factory()->create([
            'company_id' => $c->id, 'servico_id' => $s->id,
            'data_contratacao' => '2026-01-10', 'data_vencimento' => '2027-01-10', 'hubspot_billing_period' => 'P12M',
        ]);
        $ciclo = app(RenovacaoDetector::class)->garantirCiclo($c, $s->id);
        $ciclo->update(['negocio_original_id' => self::ORIGINAL]);

        return $ciclo->fresh();
    }

    private function fakeHubspot(): void
    {
        Http::fake(function (Request $r) {
            $url = $r->url();
            $m   = $r->method();

            if ($m === 'POST' && str_ends_with($url, '/crm/v3/objects/contacts/search')) {
                return Http::response(['results' => $this->contatosPorEmail]);
            }
            if ($m === 'GET' && preg_match('#/crm/v3/objects/deals/([^/]+)/associations/contacts$#', $url)) {
                return Http::response(['results' => array_map(fn ($id) => ['id' => $id, 'type' => 'deal_to_contact'], $this->contatosDoOriginal)]);
            }
            if ($m === 'GET' && preg_match('#/crm/v3/objects/contacts/([^/]+)/associations/companies$#', $url)) {
                return Http::response(['results' => array_map(fn ($id) => ['id' => $id, 'type' => 'contact_to_company'], $this->empresasDoContato)]);
            }
            if ($m === 'POST' && str_ends_with($url, '/crm/v3/objects/deals/search')) {
                return $this->statusSearch === 200
                    ? Http::response(['results' => $this->deals])
                    : Http::response(['message' => 'erro'], $this->statusSearch);
            }
            if ($m === 'POST' && str_ends_with($url, '/crm/v3/objects/deals')) {
                $this->postsTentados++;

                return match ($this->modoCriar) {
                    'timeout' => throw new ConnectionException('timeout'),
                    '400'     => Http::response(['message' => 'invalid'], 400),
                    '429'     => Http::response([], 429, ['Retry-After' => '30']),
                    '500'     => Http::response([], 500),
                    default   => $this->criarOk($r),
                };
            }
            if ($m === 'GET' && preg_match('#/crm/v3/objects/deals/([^/]+)/associations/companies$#', $url)) {
                return Http::response(['results' => array_map(fn ($id) => ['id' => $id, 'type' => 'deal_to_company'], $this->associadas)]);
            }
            if ($m === 'GET' && preg_match('#/crm/v3/objects/deals/([^/?]+)#', $url, $mm)) {
                foreach ($this->deals as $d) {
                    if ($d['id'] === $mm[1]) {
                        return Http::response($d);
                    }
                }
                return Http::response([], 404);
            }
            if ($m === 'PUT') {
                return Http::response([], 200);
            }

            return Http::response(['message' => 'inesperado: ' . $m . ' ' . $url], 500);
        });
    }

    private function criarOk(Request $r)
    {
        $props = $r['properties'];
        $deal = ['id' => 'D-NEW', 'properties' => [
            'pipeline' => $props['pipeline'], 'dealstage' => $props['dealstage'], 'ecf_renovacao_chave' => $props['ecf_renovacao_chave'],
        ]];
        $this->deals[] = $deal;

        return Http::response($deal, 201);
    }

    private function dealRemoto(string $id, string $etapa = '1480135340', string $pipeline = '958410144', ?string $chave = null): array
    {
        return ['id' => $id, 'properties' => ['pipeline' => $pipeline, 'dealstage' => $etapa, 'ecf_renovacao_chave' => $chave]];
    }

    private function orq(): RenovacaoOrquestrador
    {
        return app(RenovacaoOrquestrador::class);
    }

    private function posts(): int
    {
        return $this->postsTentados;
    }

    private function escritas(): int
    {
        return Http::recorded(fn (Request $r) => in_array($r->method(), ['PUT', 'PATCH', 'DELETE'], true)
            || ($r->method() === 'POST' && ! str_contains($r->url(), '/search')))->count();
    }

    public function test_ganho_gera_um_negocio_com_chave_original_dono_e_associacoes(): void
    {
        $this->fakeHubspot();
        $ciclo = $this->cenario();

        $dec = $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $this->assertSame(RenovacaoDecisao::CRIAR, $dec->acao);
        $this->assertSame(1, $this->posts());

        $req = Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/crm/v3/objects/deals'))->first()[0];
        $p = $req['properties'];
        $this->assertSame('958410144', $p['pipeline']);
        $this->assertSame('1480135340', $p['dealstage']);
        $this->assertSame($ciclo->chave_correlacao, $p['ecf_renovacao_chave']);
        $this->assertSame(self::ORIGINAL, $p['ecf_negocio_original_id']);
        $this->assertSame('99', $p['hubspot_owner_id']);
        $this->assertStringContainsString('Renovação |', $p['dealname']);
        $this->assertArrayNotHasKey('amount', $p);
        $tipos = collect($req['associations'])->pluck('types.0.associationTypeId')->all();
        $this->assertSame([5, 3], $tipos);

        $ciclo->refresh();
        $this->assertSame('D-NEW', $ciclo->negocio_renovacao_id);
        $this->assertSame(ContratoRenovacao::STATUS_INTEGRACAO_SINCRONIZADO, $ciclo->status_integracao);
        $this->assertSame('criar', $ciclo->ultima_decisao);
        $this->assertFalse($ciclo->ultima_decisao_simulada);
        $this->assertTrue($ciclo->eventos()->where('tipo', 'negocio_criado')->exists());
    }

    // ─── 09/11/2026 — empresa sem vínculo no HubSpot não bloqueia mais ───

    private function postCriacao(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/crm/v3/objects/deals'))->first()[0]->data();
    }

    public function test_sem_empresa_no_hubspot_associa_ao_contato_do_negocio_original_e_grava_o_vinculo(): void
    {
        // Forma medida em produção: o negócio original só tem CONTATO.
        $this->associadas         = [];
        $this->contatosDoOriginal = ['K-ORIG'];
        $this->fakeHubspot();
        $ciclo = $this->cenario(['hubspot_company_id' => null, 'hubspot_contact_id' => null, 'hubspot_deal_id' => self::ORIGINAL]);

        $dec = $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $this->assertSame(RenovacaoDecisao::CRIAR, $dec->acao);
        $this->assertSame(1, $this->posts());
        $assoc = $this->postCriacao()['associations'];
        $this->assertCount(1, $assoc);
        $this->assertSame('K-ORIG', $assoc[0]['to']['id']);
        $this->assertSame(3, $assoc[0]['types'][0]['associationTypeId']);

        $empresa = $ciclo->company->fresh();
        $this->assertSame('K-ORIG', $empresa->hubspot_contact_id);
        $this->assertNull($empresa->hubspot_company_id);
        $evento = $ciclo->eventos()->where('tipo', 'negocio_criado')->first();
        $this->assertSame('negocio_original', $evento->dados['fonte_contato'] ?? null);
    }

    public function test_contato_pelo_email_traz_a_empresa_principal_dele(): void
    {
        $this->associadas       = [];
        $this->contatosPorEmail = [['id' => 'K-MAIL', 'properties' => ['email' => 'a@b.com', 'associatedcompanyid' => 'HC-MAIL']]];
        $this->fakeHubspot();
        $ciclo = $this->cenario(['hubspot_company_id' => null, 'hubspot_contact_id' => null, 'hubspot_deal_id' => null, 'email_cliente' => 'a@b.com']);

        $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $tipos = collect($this->postCriacao()['associations'])->pluck('types.0.associationTypeId')->all();
        $this->assertSame([5, 3], $tipos);
        $empresa = $ciclo->company->fresh();
        $this->assertSame('HC-MAIL', $empresa->hubspot_company_id);
        $this->assertSame('K-MAIL', $empresa->hubspot_contact_id);
    }

    public function test_sem_nada_no_hubspot_cria_sem_associacao(): void
    {
        $this->associadas = [];
        $this->fakeHubspot();
        $ciclo = $this->cenario(['hubspot_company_id' => null, 'hubspot_contact_id' => null, 'hubspot_deal_id' => null, 'email_cliente' => 'ninguem@x.com']);

        $dec = $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $this->assertSame(RenovacaoDecisao::CRIAR, $dec->acao);
        $this->assertSame(1, $this->posts());
        $this->assertArrayNotHasKey('associations', $this->postCriacao());
        $this->assertSame('D-NEW', $ciclo->fresh()->negocio_renovacao_id);
    }

    public function test_dois_contatos_com_o_mesmo_email_nao_escolhe_nenhum(): void
    {
        $this->associadas       = [];
        $this->contatosPorEmail = [
            ['id' => 'K1', 'properties' => ['associatedcompanyid' => null]],
            ['id' => 'K2', 'properties' => ['associatedcompanyid' => null]],
        ];
        $this->fakeHubspot();
        $ciclo = $this->cenario(['hubspot_company_id' => null, 'hubspot_contact_id' => null, 'hubspot_deal_id' => null, 'email_cliente' => 'dup@x.com']);

        $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $this->assertArrayNotHasKey('associations', $this->postCriacao());
        $this->assertNull($ciclo->company->fresh()->hubspot_contact_id);
    }

    public function test_vinculo_ja_gravado_nunca_e_trocado(): void
    {
        $this->contatosDoOriginal = ['K-OUTRO'];
        $this->fakeHubspot();
        $ciclo = $this->cenario(['hubspot_deal_id' => self::ORIGINAL]);

        $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $empresa = $ciclo->company->fresh();
        $this->assertSame('HC1', $empresa->hubspot_company_id);
        $this->assertSame('HK1', $empresa->hubspot_contact_id);
    }

    public function test_simulacao_nao_procura_vinculo_nem_grava(): void
    {
        $this->associadas         = [];
        $this->contatosDoOriginal = ['K-ORIG'];
        $this->fakeHubspot();
        $ciclo = $this->cenario(['hubspot_company_id' => null, 'hubspot_contact_id' => null, 'hubspot_deal_id' => self::ORIGINAL]);

        $dec = $this->orq()->sincronizar($ciclo, 'agendador', null, true);

        $this->assertSame(RenovacaoDecisao::CRIAR, $dec->acao);
        $this->assertSame(0, $this->posts());
        $this->assertNull($ciclo->company->fresh()->hubspot_contact_id);
    }

    public function test_comando_vincular_simula_e_aplica(): void
    {
        $this->associadas         = [];
        $this->contatosDoOriginal = ['K-ORIG'];
        $this->fakeHubspot();
        $ciclo = $this->cenario(['hubspot_company_id' => null, 'hubspot_contact_id' => null, 'hubspot_deal_id' => self::ORIGINAL]);

        $this->artisan('renovacao:vincular-hubspot', ['--pausa-ms' => 0])->expectsOutputToContain('SIMULAÇÃO')->assertSuccessful();
        $this->assertNull($ciclo->company->fresh()->hubspot_contact_id);

        $this->artisan('renovacao:vincular-hubspot', ['--aplicar' => true, '--pausa-ms' => 0])->assertSuccessful();
        $this->assertSame('K-ORIG', $ciclo->company->fresh()->hubspot_contact_id);
        $this->assertSame(0, $this->posts());
    }

    public function test_empresa_sem_dono_nao_envia_hubspot_owner_id(): void
    {
        $this->fakeHubspot();
        $ciclo = $this->cenario(['hubspot_owner_id' => null]);

        $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $req = Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/crm/v3/objects/deals'))->first()[0];
        $this->assertArrayNotHasKey('hubspot_owner_id', $req['properties']);
    }

    public function test_reexecucao_nao_cria_segundo_negocio_nem_mexe_na_etapa_nem_no_original(): void
    {
        $this->fakeHubspot();
        $ciclo = $this->cenario();

        $this->orq()->sincronizar($ciclo, 'agendador', null, false);
        $dec = $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $this->assertSame(RenovacaoDecisao::CONCILIAR_ABERTO, $dec->acao);
        $this->assertSame(1, $this->posts());
        $this->assertSame(0, Http::recorded(fn (Request $r) => $r->method() === 'PATCH')->count());
        $this->assertSame(0, Http::recorded(fn (Request $r) => str_contains($r->url(), self::ORIGINAL))->count());
        $this->assertSame(0, Http::recorded(fn (Request $r) => str_contains(json_encode($r->data()), 'PATCH'))->count());
    }

    public function test_simulacao_nao_escreve_nada_no_hubspot(): void
    {
        $this->fakeHubspot();
        $ciclo = $this->cenario();

        $dec = $this->orq()->sincronizar($ciclo, 'agendador', null, true);

        $this->assertSame(RenovacaoDecisao::CRIAR, $dec->acao);
        $this->assertSame(0, $this->escritas());
        $ciclo->refresh();
        $this->assertSame('criar', $ciclo->ultima_decisao);
        $this->assertTrue($ciclo->ultima_decisao_simulada);
        $this->assertNull($ciclo->negocio_renovacao_id);
        $this->assertSame(ContratoRenovacao::STATUS_INTEGRACAO_PENDENTE, $ciclo->status_integracao);
        $this->assertTrue($ciclo->eventos()->where('tipo', 'simulacao')->where('simulacao', true)->exists());
    }

    public function test_timeout_no_post_marca_incerto_e_proxima_execucao_vincula_pela_correlacao(): void
    {
        $this->fakeHubspot();
        $ciclo = $this->cenario();
        $this->modoCriar = 'timeout';

        Log::shouldReceive('channel')->with('ecf-webhooks')->andReturnSelf();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->atLeast()->once()
            ->withArgs(fn ($m) => str_starts_with($m, '[Renovacao]'));

        $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $ciclo->refresh();
        $this->assertSame(ContratoRenovacao::STATUS_INTEGRACAO_RESULTADO_INCERTO, $ciclo->status_integracao);
        $this->assertNotNull($ciclo->criacao_iniciada_em);
        $this->assertSame(1, $this->posts());

        // O HubSpot criou de fato: a correlação o enxerga.
        $this->deals[] = $this->dealRemoto('D-FANTASMA', '1480135340', '958410144', $ciclo->chave_correlacao);
        $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $ciclo->refresh();
        $this->assertSame('D-FANTASMA', $ciclo->negocio_renovacao_id);
        $this->assertSame(ContratoRenovacao::STATUS_INTEGRACAO_SINCRONIZADO, $ciclo->status_integracao);
        $this->assertSame(1, $this->posts());
    }

    public function test_incerto_com_correlacao_vazia_aguarda_depois_revisao_manual_e_so_cria_com_liberacao(): void
    {
        $this->fakeHubspot();
        $ciclo = $this->cenario();
        $this->modoCriar = 'timeout';
        $this->orq()->sincronizar($ciclo, 'agendador', null, false);
        $this->assertSame(1, $this->posts());

        // Dentro da janela: aguarda, sem POST.
        $dec = $this->orq()->sincronizar($ciclo, 'agendador', null, false);
        $this->assertSame(RenovacaoDecisao::AGUARDAR_CONCILIACAO, $dec->acao);
        $this->assertSame(1, $this->posts());

        // Depois da janela: revisão manual, sem POST.
        Carbon::setTestNow('2026-12-01 05:00:00');
        $dec = $this->orq()->sincronizar($ciclo, 'agendador', null, false);
        $this->assertSame(RenovacaoDecisao::REVISAO_MANUAL, $dec->acao);
        $this->assertSame(ContratoRenovacao::STATUS_INTEGRACAO_REVISAO_MANUAL, $ciclo->fresh()->status_integracao);
        $this->assertSame(1, $this->posts());

        // Sem liberação continua em revisão manual.
        $dec = $this->orq()->sincronizar($ciclo, 'manual', null, false);
        $this->assertSame(RenovacaoDecisao::REVISAO_MANUAL, $dec->acao);
        $this->assertSame(1, $this->posts());

        // Liberação manual: exatamente 1 POST, tentativas zeradas antes, evento com o usuário.
        $this->modoCriar = 'ok';
        $user = \App\Models\User::factory()->create();
        $this->orq()->sincronizar($ciclo, 'manual', $user->id, false, true, 'conferi no HubSpot, não existe');
        $this->assertSame(2, $this->posts());
        $this->assertTrue($ciclo->eventos()->where('tipo', 'liberacao_manual')->where('user_id', $user->id)->exists());
        $this->assertSame('D-NEW', $ciclo->fresh()->negocio_renovacao_id);

        // Agora vinculado: reexecutar não cria de novo.
        $this->orq()->sincronizar($ciclo, 'manual', $user->id, false, true);
        $this->assertSame(2, $this->posts());
    }

    public function test_correlacao_duplicada_vai_para_revisao_manual_sem_post(): void
    {
        $this->fakeHubspot();
        $ciclo = $this->cenario();
        $this->deals[] = $this->dealRemoto('D-A');
        $this->deals[] = $this->dealRemoto('D-B');

        $dec = $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $this->assertSame(RenovacaoDecisao::REVISAO_MANUAL, $dec->acao);
        $this->assertSame(0, $this->posts());
        $this->assertSame(ContratoRenovacao::STATUS_INTEGRACAO_REVISAO_MANUAL, $ciclo->fresh()->status_integracao);
    }

    public function test_negocio_vinculado_fechado_estende_o_contrato(): void
    {
        $this->fakeHubspot();
        $ciclo = $this->cenario();
        $ciclo->update(['negocio_renovacao_id' => 'D-REN-1']);
        $this->deals[] = $this->dealRemoto('D-REN-1', '1480135341', '958410144', $ciclo->chave_correlacao);
        $this->associadas = ['HC1'];

        $dec = $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $this->assertSame(RenovacaoDecisao::REGISTRAR_GANHO, $dec->acao);
        $this->assertSame(ContratoRenovacao::STATUS_COMERCIAL_RENOVADO, $ciclo->fresh()->status_comercial);
        $cs = ContratoServico::where('company_id', $ciclo->company_id)->first();
        $this->assertSame('2028-01-10', $cs->data_vencimento->toDateString());
        $this->assertSame(0, $this->posts());
    }

    public function test_negocio_sem_associacao_com_a_empresa_completa_so_a_associacao(): void
    {
        $this->fakeHubspot();
        $ciclo = $this->cenario();
        $ciclo->update(['negocio_renovacao_id' => 'D-REN-1']);
        $this->deals[] = $this->dealRemoto('D-REN-1', '1480135342', '958410144', $ciclo->chave_correlacao);
        $this->associadas = [];

        $dec = $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $this->assertSame(RenovacaoDecisao::CONCILIAR_ABERTO, $dec->acao);
        $this->assertSame(0, $this->posts());
        $puts = Http::recorded(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/crm/v4/objects/deals/D-REN-1/associations/default/companies/HC1'));
        $this->assertSame(1, $puts->count());
        $this->assertSame(ContratoRenovacao::STATUS_COMERCIAL_EM_NEGOCIACAO, $ciclo->fresh()->status_comercial);
    }

    public function test_erro_400_permanente_vira_erro_e_conta_tentativa(): void
    {
        $this->fakeHubspot();
        $ciclo = $this->cenario();
        $this->modoCriar = '400';

        $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $ciclo->refresh();
        $this->assertSame(ContratoRenovacao::STATUS_INTEGRACAO_ERRO, $ciclo->status_integracao);
        $this->assertSame(1, $ciclo->tentativas);
        $this->assertNotEmpty($ciclo->ultimo_erro);
        $this->assertNull($ciclo->criacao_iniciada_em);
    }

    public function test_limite_429_volta_a_pendente_e_a_excecao_sobe_com_retry_after(): void
    {
        $this->fakeHubspot();
        $ciclo = $this->cenario();
        $this->modoCriar = '429';

        try {
            $this->orq()->sincronizar($ciclo, 'agendador', null, false);
            $this->fail('Deveria lançar HubspotErroApi');
        } catch (HubspotErroApi $e) {
            $this->assertTrue($e->ehLimite());
            $this->assertSame(30, $e->retryAfter);
        }

        $ciclo->refresh();
        $this->assertSame(ContratoRenovacao::STATUS_INTEGRACAO_PENDENTE, $ciclo->status_integracao);
        $this->assertSame(0, $ciclo->tentativas);
        $this->assertNull($ciclo->criacao_iniciada_em);
    }

    public function test_falha_de_leitura_nunca_rebaixa_incerto_para_erro_e_nunca_cria(): void
    {
        $this->fakeHubspot();
        $ciclo = $this->cenario();
        $this->modoCriar = 'timeout';
        $this->orq()->sincronizar($ciclo, 'agendador', null, false);
        $this->assertSame(1, $this->posts());

        $this->statusSearch = 500;
        $dec = $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $this->assertSame(RenovacaoDecisao::ERRO_LEITURA, $dec->acao);
        $ciclo->refresh();
        $this->assertSame(ContratoRenovacao::STATUS_INTEGRACAO_RESULTADO_INCERTO, $ciclo->status_integracao);
        $this->assertNotEmpty($ciclo->ultimo_erro);
        $this->assertSame(1, $this->posts());
    }

    public function test_falha_de_leitura_em_ciclo_sem_tentativa_vira_erro_sem_criar(): void
    {
        $this->fakeHubspot();
        $ciclo = $this->cenario();
        $this->statusSearch = 500;

        $this->orq()->sincronizar($ciclo, 'agendador', null, false);

        $this->assertSame(ContratoRenovacao::STATUS_INTEGRACAO_ERRO, $ciclo->fresh()->status_integracao);
        $this->assertSame(0, $this->posts());
    }
}
