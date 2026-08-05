<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HubspotEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite Feature — Debug hubspot-handoff-sem-contatos (2026-07-27).
 *
 * Cobre o comando `php artisan hubspot:reenriquecer-handoff` (classe
 * `HubspotReenriquecerHandoff`), fix sistêmico do race condition / eventual
 * consistency do HubSpot: o webhook processa o deal fechado quase
 * instantaneamente, mas as associações deal→company/contacts só ficam
 * consultáveis via API segundos depois — a Company nasce com
 * hubspot_company_id/hubspot_contact_id (e email/telefone) vazios.
 *
 * IMPORTANTE: Http::preventStrayRequests() em TODOS os cenários — nenhuma
 * chamada real ao HubSpot (mesma invariante da Fase 115).
 *
 * NOTA DE MOCK (aprendizado do debug): `Http::fake()` chamado 2x ACUMULA os
 * stubs e usa FIRST-MATCH — um segundo fake NÃO substitui o primeiro. Por isso
 * a race condition (associação vazia no webhook) e a recuperação (associação
 * populada na varredura) são modeladas com `Http::sequence()` na MESMA URL: a
 * 1ª chamada (webhook) devolve vazio, a 2ª (varredura) devolve populado. Um
 * único Http::fake evita o shadowing.
 *
 * Cenários:
 *  (i)   varredura re-enriquece company nascida sem associações, SEM duplicar
 *  (ii)  contato preenchido manualmente pelo Comercial NÃO é sobrescrito
 *  (iii) company criada há menos de 2min NÃO é tocada (janela mínima)
 *  (iv)  --dry-run apenas lista, não reprocessa
 */
class Phase116HubspotReenriquecerHandoffTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const URL    = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hubspot.client_secret'          => self::SECRET,
            'services.hubspot.access_token'           => 'token-fake',
            'services.hubspot.stage_fechado_ganho_id' => 'closedwon',
            'services.hubspot.props.deal' => [
                'servico'            => 'servico_ecf',
                'observacao'         => 'observacao',
            ],
            'services.hubspot.props.company' => [
                'name'   => 'name',
                'cnpj'   => 'cnpj',
                'email'  => 'email',
                'phone'  => 'phone',
                'domain' => 'domain',
            ],
            'services.hubspot.props.contact' => [
                'firstname'   => 'firstname',
                'lastname'    => 'lastname',
                'email'       => 'email',
                'phone'       => 'phone',
                'mobilephone' => 'mobilephone',
                'jobtitle'    => 'jobtitle',
            ],
        ]);

        Http::preventStrayRequests();
    }

    private function assinatura(string $body, string $ts): string
    {
        $url           = url(self::URL);
        $methodUriBody = 'POST' . $url . $body . $ts;
        return base64_encode(hash_hmac('sha256', $methodUriBody, self::SECRET, true));
    }

    private function servidor(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $key                 = strtoupper(str_replace('-', '_', $name));
            $out['HTTP_' . $key] = $value;
        }
        return $out;
    }

    private function eventoPadrao(array $overrides = []): array
    {
        return array_merge([
            'portalId'         => 12345,
            'objectType'       => 'DEAL',
            'objectId'         => 63087274361,
            'subscriptionType' => 'deal.propertyChange',
            'propertyName'     => 'dealstage',
            'propertyValue'    => 'closedwon',
        ], $overrides);
    }

    private function dispararWebhook(array $eventos): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($eventos);
        $ts   = (string) (int) (microtime(true) * 1000);

        return $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $this->assinatura($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);
    }

    /**
     * Mock ÚNICO do ciclo race→pronto, batendo com o caso real Hollyfield.
     *
     * As associações deal→company e deal→contacts usam Http::sequence():
     *  - 1ª chamada (momento do WEBHOOK): resultados VAZIOS (associações ainda
     *    não commitadas/indexadas no HubSpot — reproduz a race condition);
     *  - 2ª chamada (momento da VARREDURA, minutos depois): já POPULADAS.
     *
     * Um único Http::fake (em vez de dois) evita o shadowing de stubs
     * (Http::fake acumula + first-match). URLs cujos retornos não mudam entre
     * as fases (deal, line_items) usam Http::response reutilizável.
     */
    private function mockHubspotRaceEntaoPronto(): void
    {
        Http::fake([
            // Shape REAL da API v3 /associations: {id, type} (NÃO toObjectId).
            'api.hubapi.com/crm/v3/objects/deals/63087274361/associations/companies' => Http::sequence()
                ->push(['results' => []])
                ->push(['results' => [['id' => '56986195877', 'type' => 'deal_to_company']]]),
            'api.hubapi.com/crm/v3/objects/deals/63087274361/associations/contacts' => Http::sequence()
                ->push(['results' => []])
                ->push(['results' => [['id' => '237977565608', 'type' => 'deal_to_contact']]]),
            'api.hubapi.com/crm/v3/objects/deals/63087274361/associations/line_items' => Http::response([
                'results' => [],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/56986195877*' => Http::response([
                'id'         => '56986195877',
                'properties' => [
                    'name'   => 'Hollyfield LTDA',
                    'domain' => 'hollyteste.com.br',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/contacts/237977565608*' => Http::response([
                'id'         => '237977565608',
                'properties' => [
                    'firstname' => 'Holly',
                    'lastname'  => 'Field',
                    'email'     => 'hollyteste@gmail.com',
                    'phone'     => '+5511960821967',
                ],
            ]),
            // Precisa vir DEPOIS das URLs de associação (first-match): o wildcard
            // do deal cobre só o GET do próprio deal, não os sub-paths /associations.
            'api.hubapi.com/crm/v3/objects/deals/63087274361*' => Http::response([
                'id'         => '63087274361',
                'properties' => [
                    'dealname'  => 'Hollyfield LTDA - Novo(a) Deal',
                    'dealstage' => 'closedwon',
                ],
            ]),
        ]);
    }

    /**
     * Cria a company via webhook DENTRO da race condition (associações vazias na
     * 1ª chamada, via sequence) e recua created_at pra fora da janela mínima.
     */
    private function criarCompanyViaWebhookComRaceCondition(int $minutosAtras = 5): Company
    {
        $this->mockHubspotRaceEntaoPronto();

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status);

        $company = Company::find($evento->company_id_criada);
        $this->assertNull($company->hubspot_company_id, 'Baseline: hubspot_company_id deve nascer vazio (race condition)');
        $this->assertNull($company->hubspot_contact_id, 'Baseline: hubspot_contact_id deve nascer vazio (race condition)');

        Company::where('id', $company->id)->update([
            'created_at' => now()->subMinutes($minutosAtras),
        ]);

        return $company->refresh();
    }

    // ══════════════════════════════════════════════════════════════════
    //  Cenário (i): varredura re-enriquece sem duplicar
    // ══════════════════════════════════════════════════════════════════

    public function test_varredura_reenriquece_company_criada_sem_associacoes_prontas(): void
    {
        $company = $this->criarCompanyViaWebhookComRaceCondition();

        $this->artisan('hubspot:reenriquecer-handoff')->assertExitCode(0);

        $this->assertSame(1, Company::count(), 'A varredura NAO pode criar company duplicada');

        $company->refresh();
        $this->assertSame('56986195877', $company->hubspot_company_id);
        $this->assertSame('237977565608', $company->hubspot_contact_id);
        $this->assertSame('hollyteste@gmail.com', $company->email_cliente);
        $this->assertSame('+5511960821967', $company->telefone);
        $this->assertSame('Holly Field', $company->nome_contato);
        $this->assertSame('hollyteste.com.br', $company->hubspot_domain);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Cenário (ii): dado preenchido manualmente pelo Comercial NAO é
    //  sobrescrito (T-113-03-02 continua valendo através da varredura)
    // ══════════════════════════════════════════════════════════════════

    public function test_varredura_nao_sobrescreve_contato_preenchido_manualmente(): void
    {
        $company = $this->criarCompanyViaWebhookComRaceCondition();

        // Comercial preenche email/telefone MANUALMENTE antes da varredura rodar
        // (hubspot_company_id/contact_id continuam vazios — segue elegível).
        $company->update([
            'email_cliente' => 'contato-manual@hollyfield.com.br',
            'telefone'      => '+5511900000000',
        ]);

        $this->artisan('hubspot:reenriquecer-handoff')->assertExitCode(0);

        $this->assertSame(1, Company::count(), 'A varredura NAO pode criar company duplicada');

        $company->refresh();
        // Dado manual do Comercial é SOBERANO — nunca sobrescrito.
        $this->assertSame('contato-manual@hollyfield.com.br', $company->email_cliente);
        $this->assertSame('+5511900000000', $company->telefone);
        // Mas os campos que estavam vazios SÃO preenchidos normalmente.
        $this->assertSame('56986195877', $company->hubspot_company_id);
        $this->assertSame('237977565608', $company->hubspot_contact_id);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Cenário (iii): guarda de janela mínima (2min) — nao reprocessa
    //  company recem-criada (HubSpot ainda pode nao ter assentado)
    // ══════════════════════════════════════════════════════════════════

    public function test_varredura_ignora_company_criada_ha_menos_de_2_minutos(): void
    {
        // created_at recuado só 30s — dentro da janela mínima de 2min.
        $company = $this->criarCompanyViaWebhookComRaceCondition(minutosAtras: 0);
        Company::where('id', $company->id)->update(['created_at' => now()->subSeconds(30)]);

        // A company é jovem demais → a varredura NÃO deve tocá-la. Se tocasse,
        // as associações (já disponíveis no sequence) enriqueceriam a company e
        // as asserções de null abaixo quebrariam.
        $this->artisan('hubspot:reenriquecer-handoff')->assertExitCode(0);

        $company->refresh();
        $this->assertNull($company->hubspot_company_id, 'Company recente demais NAO deve ser tocada pela varredura');
        $this->assertNull($company->hubspot_contact_id);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Cenário (iv): --dry-run apenas lista, nunca reprocessa
    // ══════════════════════════════════════════════════════════════════

    public function test_dry_run_apenas_lista_nao_reprocessa(): void
    {
        $company = $this->criarCompanyViaWebhookComRaceCondition();

        // --dry-run só lista as candidatas: não chama reprocessarEvento, então
        // nada é enriquecido (as asserções de null provam isso).
        $this->artisan('hubspot:reenriquecer-handoff', ['--dry-run' => true])->assertExitCode(0);

        $company->refresh();
        $this->assertNull($company->hubspot_company_id, '--dry-run nao pode reprocessar nada');
        $this->assertNull($company->hubspot_contact_id);
    }
}
