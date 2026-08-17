<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HubspotEvento;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 124 Plano 02 — Caracterização do caminho webhook HubSpot ANTES da extração.
 *
 * A Fase 124 é refatoração pura: `EmpresaOperacionalRouter` vai substituir o
 * roteamento inline de `HubspotWebhookController::rotearImplementacao()` sem
 * mudar nada observável. A única forma de provar isso é ter, ANTES de tocar
 * em produção, testes que capturam o comportamento de HOJE e continuam
 * passando SEM ALTERAÇÃO depois.
 *
 * Se algum teste deste arquivo precisar mudar para voltar a ficar verde, o
 * comportamento mudou — investigar a regressão, não "atualizar o teste".
 *
 * Helpers de HMAC/webhook copiados de `Phase35HubspotV2Test` (convenção do
 * projeto: cada arquivo de teste de webhook tem sua própria cópia, sem trait
 * compartilhada).
 */
class Phase124RegressaoHubspotTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const URL    = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

        // Forca config previsivel — independente do .env.testing local.
        config([
            'services.hubspot.client_secret'          => self::SECRET,
            'services.hubspot.access_token'           => 'token-fake',
            'services.hubspot.stage_fechado_ganho_id' => '1352209026,closedwon',
            'services.hubspot.props.deal' => [
                'servico'            => 'servico_ecf',
            ],
            'services.hubspot.props.company' => [
                'name'  => 'name',
                'cnpj'  => 'cnpj',
                'email' => 'email',
                'phone' => 'phone',
            ],
            'services.hubspot.props.contact' => [
                'firstname' => 'firstname',
                'lastname'  => 'lastname',
                'email'     => 'email',
                'phone'     => 'phone',
            ],
        ]);
    }

    /**
     * Calcula assinatura v3 do mesmo jeito do controller:
     *   base64(hmac_sha256(secret, METHOD + URI + body + timestamp))
     */
    private function assinatura(string $body, string $ts): string
    {
        $url           = url(self::URL);
        $methodUriBody = 'POST' . $url . $body . $ts;
        return base64_encode(hash_hmac('sha256', $methodUriBody, self::SECRET, true));
    }

    /**
     * Converte headers em formato $_SERVER (HTTP_X_*) — necessario quando
     * usamos $this->call() direto.
     */
    private function servidor(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $key                 = strtoupper(str_replace('-', '_', $name));
            $out['HTTP_' . $key] = $value;
        }
        return $out;
    }

    /**
     * Evento HubSpot padrao (overridable via $overrides).
     */
    private function eventoPadrao(array $overrides = []): array
    {
        return array_merge([
            'portalId'         => 12345,
            'objectType'       => 'DEAL',
            'objectId'         => 9876,
            'subscriptionType' => 'deal.propertyChange',
            'propertyName'     => 'dealstage',
            'propertyValue'    => 'closedwon',
        ], $overrides);
    }

    /**
     * Helper p/ disparar o webhook com payload padrao + signature valida.
     */
    private function disparaWebhook(array $eventos): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($eventos);
        $ts   = (string) (int) (microtime(true) * 1000);

        return $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $this->assinatura($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);
    }

    /**
     * Mock padrao dos GETs HubSpot.
     *
     * @param  array  $dealProps     properties do deal (override de defaults)
     * @param  array  $companyProps  properties do company (override; null = company nao associado)
     * @param  array|false  $contactProps  properties do contato; false = sem contato associado
     */
    private function mockaHubSpot(array $dealProps, ?array $companyProps = [], array|false $contactProps = []): void
    {
        $deal = array_merge([
            'dealname'           => 'Cliente Teste',
            'amount'             => '1500.00',
            'dealstage'          => 'closedwon',
            'servico_ecf'        => 'Mentoria Avançada',
        ], $dealProps);

        $fakes = [];

        // IMPORTANTE: Http::fake matcheia pelo primeiro padrao que bate. As
        // chaves MAIS especificas devem vir primeiro (associations) e o
        // catch-all do deal (deals/9876*) por ultimo. Patterns terminam com
        // * para casar query-string ?properties=... que o client envia.

        // Associations: companies
        if ($companyProps === null) {
            $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/companies'] = Http::response([
                'results' => [],
            ]);
        } else {
            $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/companies'] = Http::response([
                'results' => [['toObjectId' => 55501]],
            ]);
            $fakes['api.hubapi.com/crm/v3/objects/companies/55501*'] = Http::response([
                'id'         => '55501',
                'properties' => array_merge([
                    'name'  => 'Cliente Teste',
                    'cnpj'  => '12345678000199',
                    'email' => 'company@cliente.com',
                    'phone' => '11999998888',
                ], $companyProps),
            ]);
        }

        // Associations: contacts
        if ($contactProps === false) {
            $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts'] = Http::response([
                'results' => [],
            ]);
        } else {
            $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts'] = Http::response([
                'results' => [['toObjectId' => 77701]],
            ]);
            $fakes['api.hubapi.com/crm/v3/objects/contacts/77701*'] = Http::response([
                'id'         => '77701',
                'properties' => array_merge([
                    'firstname' => 'João',
                    'lastname'  => 'Silva',
                    'email'     => 'contato@cliente.com',
                    'phone'     => '11888887777',
                ], $contactProps),
            ]);
        }

        // Deal — catch-all DEPOIS das associacoes (pattern 'deals/9876*'
        // matchearia tambem '/associations/...' se viesse antes).
        $fakes['api.hubapi.com/crm/v3/objects/deals/9876*'] = Http::response([
            'id'         => '9876',
            'properties' => $deal,
        ]);

        Http::fake($fakes);
    }

    // ─── Incubadora ponta a ponta ────────────────────────────────────────────

    /**
     * Incubadora cria MlbEmpresa tipo INCUBADORA e NENHUMA MlbImplementacao —
     * só o ramo Polos dispara implementação. `projeto` e `fase` continuam
     * null (só o ramo Polos seta `projeto`; nenhum ramo seta `fase`).
     * `estagio` mantém o default ATUAL da coluna: a migration original
     * `2026_04_30_000003_create_mlb_empresas_table.php` declarava
     * `default('Não Listado')`, mas
     * `2026_05_08_000001_make_estagio_nullable_mlb_empresas.php` mudou a
     * coluna para `nullable()->default(null)` — o literal vigente é `null`
     * (confirmado por leitura das duas migrations, não presumido pela
     * primeira — mesma conclusão do 124-01 para o caminho Comercial, que
     * usa a mesma tabela).
     */
    public function test_hubspot_incubadora_cria_mlb_empresa_sem_implementacao(): void
    {
        Servico::create([
            'nome'          => 'Incubadora Norte',
            'valor_padrao'  => 900.00,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        $this->mockaHubSpot(['servico_ecf' => 'Incubadora Norte']);

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $this->assertSame(1, Company::count());
        $company = Company::first();

        $mlbEmp = MlbEmpresa::where('company_id', $company->id)->first();
        $this->assertNotNull($mlbEmp, 'MlbEmpresa INCUBADORA deveria ter sido criada');
        $this->assertSame('INCUBADORA', $mlbEmp->tipo);
        $this->assertNull($mlbEmp->projeto, 'Só o ramo Polos seta projeto');
        $this->assertNull($mlbEmp->fase, 'Nenhum ramo de roteamento seta fase');
        $this->assertNull(
            $mlbEmp->estagio,
            'Default ATUAL da coluna (migration 2026_05_08_000001 tornou nullable/null)',
        );

        $this->assertSame(0, MlbImplementacao::count(), 'Incubadora não dispara MlbImplementacao');
    }

    // ─── Assimetria do gmail_colaborador (D-02 do CONTEXT / FLUXO-07) ──────

    /**
     * Documenta a ASSIMETRIA vigente entre os dois caminhos de entrada: o
     * Comercial passa o `$handoff` do wizard (com `gmail_colaborador`) para
     * `MlbImplementacaoFactory::criarParaPolo()`; o webhook HubSpot NÃO passa
     * segundo argumento — cai sempre no default de
     * `MlbImplementacao::dadosPadrao()`.
     *
     * Não é bug; é o que acontece hoje, e a extração desta fase precisa
     * preservar os dois lados (o roteador unificado passa o pacote opcional
     * que cada chamador decide informar — D-02 do CONTEXT).
     */
    public function test_hubspot_polos_nao_preenche_gmail_colaborador(): void
    {
        Servico::create([
            'nome'          => 'Polos SP',
            'valor_padrao'  => 2500.00,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        $this->mockaHubSpot(['servico_ecf' => 'Polos SP']);

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $company = Company::first();
        $mlbEmp  = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'POLO')->first();
        $this->assertNotNull($mlbEmp, 'MlbEmpresa POLO deveria ter sido criada');

        $impl = MlbImplementacao::where('empresa_id', $mlbEmp->id)->first();
        $this->assertNotNull($impl, 'MlbImplementacao deveria ter sido criada pela factory');

        $default = MlbImplementacao::dadosPadrao()['links_admin']['gmail_colaborador'];
        $this->assertSame(
            $default,
            $impl->dados['links_admin']['gmail_colaborador'] ?? null,
            'O caminho webhook nunca preenche gmail_colaborador — permanece no default global',
        );
    }

    // ─── FLUXO-05 — empresa que já tem ficha não ganha uma segunda ─────────

    /**
     * D-06/D-07 do CONTEXT: "empresa que não pode ser afetada" = já ter
     * `MlbEmpresa` (existe `MlbEmpresa::where('company_id', ...)->exists()`).
     * O guard da linha ~938 de `rotearImplementacao()` é reaproveitado como
     * está — é o backstop real desta milestone contra liberação duplicada.
     *
     * Cenário: empresa já existe (match FORTE por cnpj, mesmo padrão de
     * `Phase113HubspotDedupTest`) e já tem uma `MlbEmpresa` ASSESSORIA. Chega
     * um deal novo, para a MESMA empresa, com serviço Polos. O guard deve
     * impedir a criação de uma segunda ficha — a empresa continua com a
     * ficha ASSESSORIA original, sem MlbImplementacao nenhuma.
     */
    public function test_hubspot_empresa_que_ja_tem_ficha_nao_ganha_segunda(): void
    {
        Servico::create([
            'nome'          => 'Polos SP',
            'valor_padrao'  => 2500.00,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        $existente = Company::factory()->create([
            'cnpj' => '77.788.899/0001-11',
        ]);

        $mlbExistente = MlbEmpresa::create([
            'nome'       => $existente->name,
            'tipo'       => 'ASSESSORIA',
            'company_id' => $existente->id,
        ]);

        $this->mockaHubSpot(
            dealProps: ['servico_ecf' => 'Polos SP'],
            companyProps: [
                'name' => $existente->name,
                'cnpj' => '77788899000111', // mesmos digitos do cnpj existente — match FORTE
            ],
        );

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        // NAO duplica company (match forte enriquece a existente).
        $this->assertSame(1, Company::count());

        $fichas = MlbEmpresa::where('company_id', $existente->id)->get();
        $this->assertCount(1, $fichas, 'Guard deve impedir a criação de uma segunda MlbEmpresa');
        $this->assertSame($mlbExistente->id, $fichas->first()->id, 'A única ficha continua sendo a original');
        $this->assertSame('ASSESSORIA', $fichas->first()->tipo, 'Tipo da ficha original não muda');

        $this->assertSame(0, MlbImplementacao::count(), 'Guard também impede a criação de MlbImplementacao');
    }

    // ─── FLUXO-06 — replay contra empresa já operando não duplica nada ─────

    /**
     * `hubspot:reprocess-event` reusa `HubspotWebhookController::
     * reprocessarEvento()`, que chama o MESMO `criarEmpresa()` do webhook
     * original — logo o guard de `rotearImplementacao()` (D-07) também cobre
     * o replay. Reprocessar um evento de uma empresa que já tem ficha não
     * cria nada novo: nem MlbEmpresa, nem MlbImplementacao (token do link
     * público não muda), nem Company.
     */
    public function test_reprocess_event_em_empresa_com_ficha_nao_duplica_nem_recria(): void
    {
        Servico::create([
            'nome'          => 'Polos SP',
            'valor_padrao'  => 2500.00,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        $this->mockaHubSpot(['servico_ecf' => 'Polos SP']);

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $company = Company::first();
        $mlbEmp  = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'POLO')->firstOrFail();
        $impl    = MlbImplementacao::where('empresa_id', $mlbEmp->id)->firstOrFail();

        $mlbEmpId    = $mlbEmp->id;
        $tokenAntes  = $impl->token;

        $evento = HubspotEvento::first();
        $this->assertNotNull($evento, 'Evento precisa ter sido gravado pelo disparo original');

        $this->artisan('hubspot:reprocess-event', ['id' => $evento->id])
            ->assertExitCode(0);

        $this->assertSame(1, Company::count(), 'Replay não pode duplicar Company');
        $this->assertSame(1, MlbEmpresa::count(), 'Replay não pode duplicar MlbEmpresa');
        $this->assertSame($mlbEmpId, MlbEmpresa::first()->id, 'A MlbEmpresa continua sendo a mesma linha');

        $this->assertSame(1, MlbImplementacao::count(), 'Replay não pode duplicar MlbImplementacao');
        $this->assertSame(
            $tokenAntes,
            MlbImplementacao::first()->token,
            'Replay não pode recriar/sobrescrever o token do link público de onboarding',
        );
    }
}
