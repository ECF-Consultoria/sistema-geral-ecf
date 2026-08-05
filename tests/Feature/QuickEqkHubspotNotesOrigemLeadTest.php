<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HubspotEvento;
use App\Services\HubspotApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Suite Feature — Quick task 260805-eqk (handoff HubSpot: Notes + Origem do lead).
 *
 * Contexto validado contra a API real da ECF (NÃO reinvestigar):
 *  - a property `observacao` do deal NÃO existe; as observações são Notes
 *    (engagements) associadas ao DEAL;
 *  - `origem_do_lead` vive no CONTATO (Metalform: contato 235433492313 →
 *    "Parceiro de Polos");
 *  - caso âncora: deal 62661178491 com as notes 113069990193 (16/07) e
 *    114141013579 (03/08) — a segunda nasceu DEPOIS de a empresa já existir.
 *
 * Cobre 3 grupos:
 *  A. `HubspotApiClient::fetchNotes` — ordenação, resiliência e sanitização
 *  B. Webhook — grava hubspot_notas / hubspot_observacao / origem_lead / snapshot.notes
 *  C. Reenriquecimento — nota NOVA em empresa existente ATUALIZA o espelho,
 *     enquanto `origem_lead` preenchido à mão NÃO é sobrescrito
 *
 * Padrão de setUp/HMAC/fakes replicado de Phase113HubspotEnrichmentTest /
 * Phase114HubspotReplayTest — não reinventa o disparo do webhook.
 */
class QuickEqkHubspotNotesOrigemLeadTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET  = 'segredo-hubspot-testes-32-chars-zzz';
    private const URL     = '/api/webhooks/hubspot';
    private const DEAL_ID = '62661178491';
    private const NOTE_1  = '113069990193';
    private const NOTE_2  = '114141013579';
    private const TS_1    = '2026-07-16T12:33:06.512Z';
    private const TS_2    = '2026-08-03T16:50:39.488Z';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hubspot.client_secret'          => self::SECRET,
            'services.hubspot.access_token'           => 'token-fake-eqk',
            'services.hubspot.stage_fechado_ganho_id' => 'closedwon',
            'services.hubspot.props.deal' => [
                'servico' => 'servico_ecf',
            ],
            'services.hubspot.props.company' => [
                'name'   => 'name',
                'cnpj'   => 'cnpj',
                'email'  => 'email',
                'phone'  => 'phone',
                'domain' => 'domain',
            ],
            'services.hubspot.props.contact' => [
                'firstname'      => 'firstname',
                'lastname'       => 'lastname',
                'email'          => 'email',
                'phone'          => 'phone',
                'mobilephone'    => 'mobilephone',
                'jobtitle'       => 'jobtitle',
                'origem_do_lead' => 'origem_do_lead',
            ],
            'services.hubspot.props.note' => [
                'body'      => 'hs_note_body',
                'timestamp' => 'hs_timestamp',
            ],
        ]);

        // RefreshDatabase pode aplicar seeds — isola os cenários de contrato.
        DB::table('hubspot_line_item_mapping')->delete();
        DB::table('contratos_servico')->delete();
        DB::table('servicos')->delete();
    }

    private function client(): HubspotApiClient
    {
        return new HubspotApiClient('token-fake-eqk');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Grupo A — HubspotApiClient::fetchNotes
    // ─────────────────────────────────────────────────────────────────────────

    public function test_fetch_notes_retorna_duas_notas_ordenadas_por_timestamp_ascendente(): void
    {
        // Associations devolve a MAIS RECENTE primeiro de propósito — o método
        // tem que reordenar para cronológica (mais antiga primeiro).
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/notes' => Http::response([
                'results' => [
                    ['id' => self::NOTE_2, 'type' => 'deal_to_note'],
                    ['id' => self::NOTE_1, 'type' => 'deal_to_note'],
                ],
            ]),
            'https://api.hubapi.com/crm/v3/objects/notes/' . self::NOTE_2 . '*' => Http::response([
                'id'         => self::NOTE_2,
                'properties' => [
                    'hs_note_body'  => '<p>Nota mais recente</p>',
                    'hs_timestamp'  => self::TS_2,
                ],
            ]),
            'https://api.hubapi.com/crm/v3/objects/notes/' . self::NOTE_1 . '*' => Http::response([
                'id'         => self::NOTE_1,
                'properties' => [
                    'hs_note_body'  => '<p>Nota mais antiga</p>',
                    'hs_timestamp'  => self::TS_1,
                ],
            ]),
        ]);

        $notes = $this->client()->fetchNotes('deals', self::DEAL_ID);

        $this->assertCount(2, $notes);
        $this->assertSame(self::NOTE_1, $notes[0]['id']);
        $this->assertSame('Nota mais antiga', $notes[0]['body']);
        $this->assertSame(self::TS_1, $notes[0]['timestamp']);
        $this->assertSame(self::NOTE_2, $notes[1]['id']);
        $this->assertSame('Nota mais recente', $notes[1]['body']);
    }

    public function test_fetch_notes_associations_com_erro_retorna_array_vazio(): void
    {
        foreach ([404, 500] as $status) {
            Http::fake([
                'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/notes' => Http::response([], $status),
            ]);

            $this->assertSame([], $this->client()->fetchNotes('deals', self::DEAL_ID), "status {$status} deveria devolver []");
        }
    }

    public function test_fetch_notes_pula_apenas_a_nota_com_falha_individual(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/notes' => Http::response([
                'results' => [
                    ['id' => self::NOTE_1],
                    ['id' => self::NOTE_2],
                ],
            ]),
            'https://api.hubapi.com/crm/v3/objects/notes/' . self::NOTE_1 . '*' => Http::response([], 404),
            'https://api.hubapi.com/crm/v3/objects/notes/' . self::NOTE_2 . '*' => Http::response([
                'id'         => self::NOTE_2,
                'properties' => [
                    'hs_note_body' => 'Sobrevivente',
                    'hs_timestamp' => self::TS_2,
                ],
            ]),
        ]);

        $notes = $this->client()->fetchNotes('deals', self::DEAL_ID);

        $this->assertCount(1, $notes);
        $this->assertSame(self::NOTE_2, $notes[0]['id']);
        $this->assertSame('Sobrevivente', $notes[0]['body']);
    }

    public function test_fetch_notes_sanitiza_html_sem_colar_paragrafos(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/notes' => Http::response([
                'results' => [['id' => self::NOTE_1]],
            ]),
            'https://api.hubapi.com/crm/v3/objects/notes/' . self::NOTE_1 . '*' => Http::response([
                'id'         => self::NOTE_1,
                'properties' => [
                    'hs_note_body' => '<p>primeira</p><p>segunda</p>',
                    'hs_timestamp' => self::TS_1,
                ],
            ]),
        ]);

        $notes = $this->client()->fetchNotes('deals', self::DEAL_ID);

        $this->assertCount(1, $notes);
        $this->assertSame("primeira\nsegunda", $notes[0]['body']);
        $this->assertStringNotContainsString('primeirasegunda', $notes[0]['body']);
    }

    public function test_fetch_notes_descarta_nota_sem_texto_util(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/notes' => Http::response([
                'results' => [
                    ['id' => self::NOTE_1],
                    ['id' => self::NOTE_2],
                ],
            ]),
            // Body vazio.
            'https://api.hubapi.com/crm/v3/objects/notes/' . self::NOTE_1 . '*' => Http::response([
                'id'         => self::NOTE_1,
                'properties' => ['hs_note_body' => '', 'hs_timestamp' => self::TS_1],
            ]),
            // Só markup, sem texto.
            'https://api.hubapi.com/crm/v3/objects/notes/' . self::NOTE_2 . '*' => Http::response([
                'id'         => self::NOTE_2,
                'properties' => ['hs_note_body' => '<p></p><div></div>', 'hs_timestamp' => self::TS_2],
            ]),
        ]);

        $this->assertSame([], $this->client()->fetchNotes('deals', self::DEAL_ID));
    }

    public function test_fetch_notes_usa_hs_createdate_quando_nao_ha_hs_timestamp(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/notes' => Http::response([
                'results' => [['id' => self::NOTE_1]],
            ]),
            'https://api.hubapi.com/crm/v3/objects/notes/' . self::NOTE_1 . '*' => Http::response([
                'id'         => self::NOTE_1,
                'properties' => [
                    'hs_note_body'  => 'Sem hs_timestamp',
                    'hs_createdate' => self::TS_1,
                ],
            ]),
        ]);

        $notes = $this->client()->fetchNotes('deals', self::DEAL_ID);

        $this->assertCount(1, $notes);
        $this->assertSame(self::TS_1, $notes[0]['timestamp']);
    }

    /**
     * T-EQK-01 — os warnings do canal `ecf-webhooks` carregam apenas IDs +
     * status HTTP; nunca o token nem a string "Bearer".
     */
    public function test_fetch_notes_nunca_vaza_token_no_log(): void
    {
        $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->withArgs(function ($message, $context = []) {
                $ctxJson = json_encode($context);
                return !str_contains((string) $message, 'token-fake-eqk')
                    && !str_contains((string) $message, 'Bearer')
                    && !str_contains((string) $ctxJson, 'token-fake-eqk')
                    && !str_contains((string) $ctxJson, 'Bearer');
            })
            ->zeroOrMoreTimes();
        $logger->shouldReceive('info')->andReturnNull();

        Log::shouldReceive('channel')->with('ecf-webhooks')->andReturn($logger);

        // Cenário 1: associations falha.
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/notes' => Http::response([], 500),
        ]);
        $this->assertSame([], $this->client()->fetchNotes('deals', self::DEAL_ID));

        // Cenário 2: detalhe individual falha.
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals/' . self::DEAL_ID . '/associations/notes' => Http::response([
                'results' => [['id' => self::NOTE_1]],
            ]),
            'https://api.hubapi.com/crm/v3/objects/notes/' . self::NOTE_1 . '*' => Http::response([], 404),
        ]);
        $this->assertSame([], $this->client()->fetchNotes('deals', self::DEAL_ID));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Infra do webhook
    // ─────────────────────────────────────────────────────────────────────────

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
            'objectId'         => self::DEAL_ID,
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
     * Mocka o deal 62661178491 completo: company + contato com origem do lead +
     * sem line items + N notes. `$noteIds` controla quais notas o deal tem —
     * usado para simular a nota que nasce DEPOIS da empresa existir.
     *
     * @param  array<int, string>  $noteIds
     */
    private function mockHubspot(array $noteIds = [self::NOTE_1, self::NOTE_2], ?string $origemLead = 'Parceiro de Polos'): void
    {
        $base = 'https://api.hubapi.com/crm/v3/objects/';

        $fakes = [
            // Chaves MAIS específicas primeiro — Http::fake usa o primeiro match.
            $base . 'deals/' . self::DEAL_ID . '/associations/companies' => Http::response([
                'results' => [['id' => '406001']],
            ]),
            $base . 'companies/406001*' => Http::response([
                'id'         => '406001',
                'properties' => [
                    'name'   => 'Metalform LTDA',
                    'cnpj'   => '55566677000188',
                    'domain' => 'metalform.com.br',
                ],
            ]),
            $base . 'deals/' . self::DEAL_ID . '/associations/contacts' => Http::response([
                'results' => [['id' => '235433492313']],
            ]),
            $base . 'contacts/235433492313*' => Http::response([
                'id'         => '235433492313',
                'properties' => [
                    'firstname'      => 'Carlos',
                    'lastname'       => 'Metalform',
                    'email'          => 'carlos@metalform.com.br',
                    'phone'          => '11955554444',
                    'jobtitle'       => 'Diretor',
                    'origem_do_lead' => $origemLead,
                ],
            ]),
            $base . 'deals/' . self::DEAL_ID . '/associations/line_items' => Http::response([
                'results' => [],
            ]),
            $base . 'deals/' . self::DEAL_ID . '/associations/notes' => Http::response([
                'results' => array_map(static fn ($id) => ['id' => $id, 'type' => 'deal_to_note'], $noteIds),
            ]),
            $base . 'notes/' . self::NOTE_1 . '*' => Http::response([
                'id'         => self::NOTE_1,
                'properties' => [
                    'hs_note_body' => '<p>Primeira conversa com o cliente.</p>',
                    'hs_timestamp' => self::TS_1,
                ],
            ]),
            $base . 'notes/' . self::NOTE_2 . '*' => Http::response([
                'id'         => self::NOTE_2,
                'properties' => [
                    'hs_note_body' => '<p>Nota criada depois do cadastro.</p>',
                    'hs_timestamp' => self::TS_2,
                ],
            ]),
            // Curinga do deal por último.
            $base . 'deals/' . self::DEAL_ID . '*' => Http::response([
                'id'         => self::DEAL_ID,
                'properties' => [
                    'dealname'  => 'Metalform',
                    'amount'    => '2000.00',
                    'dealstage' => 'closedwon',
                ],
            ]),
        ];

        Http::fake($fakes);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Grupo B — Webhook grava notes + origem do lead
    // ─────────────────────────────────────────────────────────────────────────

    public function test_webhook_grava_notas_observacao_consolidada_e_origem_do_lead(): void
    {
        $this->mockHubspot();

        $this->dispararWebhook([$this->eventoPadrao()])->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status);

        $company = Company::find($evento->company_id_criada);
        $this->assertNotNull($company);

        // 2 notas, em ordem cronológica.
        $this->assertCount(2, $company->hubspot_notas);
        $this->assertSame(self::NOTE_1, $company->hubspot_notas[0]['id']);
        $this->assertSame(self::NOTE_2, $company->hubspot_notas[1]['id']);

        // Texto consolidado = bodies unidos por linha em branco.
        $this->assertSame(
            "Primeira conversa com o cliente.\n\nNota criada depois do cadastro.",
            $company->hubspot_observacao,
        );

        // Origem do lead vinda do CONTATO principal.
        $this->assertSame('Parceiro de Polos', $company->origem_lead);

        // Snapshot carrega as notas para auditoria.
        $this->assertArrayHasKey('notes', $company->hubspot_snapshot);
        $this->assertCount(2, $company->hubspot_snapshot['notes']);
    }

    public function test_webhook_sem_notas_deixa_observacao_nula(): void
    {
        $this->mockHubspot(noteIds: []);

        $this->dispararWebhook([$this->eventoPadrao()])->assertStatus(200);

        $company = Company::find(HubspotEvento::first()->company_id_criada);

        $this->assertSame([], $company->hubspot_notas);
        $this->assertNull($company->hubspot_observacao);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Grupo C — Reenriquecimento (a regra crítica desta task)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Caso real que motivou a mudança: a Metalform ganhou uma nota em 03/08
     * DEPOIS de a empresa já existir. `hubspot_notas`/`hubspot_observacao` são
     * ESPELHO do HubSpot e ficam FORA da regra "só preenche se estiver vazio" —
     * sob a regra antiga essa nota nunca apareceria no ECF.
     */
    public function test_nota_nova_em_empresa_existente_atualiza_o_espelho(): void
    {
        // Empresa já existe com match FORTE por hubspot_company_id e com UMA
        // nota (o retrato do primeiro webhook, de 16/07).
        $existente = Company::factory()->create([
            'hubspot_company_id' => '406001',
            'hubspot_notas'      => [[
                'id'        => self::NOTE_1,
                'body'      => 'Primeira conversa com o cliente.',
                'timestamp' => self::TS_1,
            ]],
            'hubspot_observacao' => 'Primeira conversa com o cliente.',
        ]);

        // Agora o deal tem 2 notas no HubSpot.
        $this->mockHubspot();

        $this->dispararWebhook([$this->eventoPadrao()])->assertStatus(200);

        // Não duplicou a empresa (match forte).
        $this->assertSame(1, Company::count());

        $existente->refresh();

        $this->assertCount(2, $existente->hubspot_notas, 'A nota nova precisa aparecer no espelho');
        $this->assertSame(self::NOTE_2, $existente->hubspot_notas[1]['id']);
        $this->assertSame(
            "Primeira conversa com o cliente.\n\nNota criada depois do cadastro.",
            $existente->hubspot_observacao,
        );
    }

    /**
     * Ao contrário das notas, `origem_lead` segue a regra normal de
     * enriquecimento: o Comercial pode corrigir à mão e dado manual é soberano.
     */
    public function test_origem_do_lead_preenchida_a_mao_nao_e_sobrescrita(): void
    {
        $existente = Company::factory()->create([
            'hubspot_company_id' => '406001',
            'origem_lead'        => 'Indicação (corrigido à mão)',
        ]);

        $this->mockHubspot();

        $this->dispararWebhook([$this->eventoPadrao()])->assertStatus(200);

        $existente->refresh();

        $this->assertSame('Indicação (corrigido à mão)', $existente->origem_lead);
    }

    public function test_origem_do_lead_vazia_e_enriquecida_no_match_forte(): void
    {
        $existente = Company::factory()->create([
            'hubspot_company_id' => '406001',
            'origem_lead'        => null,
        ]);

        $this->mockHubspot();

        $this->dispararWebhook([$this->eventoPadrao()])->assertStatus(200);

        $existente->refresh();

        $this->assertSame('Parceiro de Polos', $existente->origem_lead);
    }

    /**
     * Falha na busca das notes não pode derrubar o webhook — a empresa ainda
     * tem que ser criada (mesmo padrão resiliente do bloco de contatos).
     */
    public function test_falha_ao_buscar_notes_nao_quebra_o_webhook(): void
    {
        $this->mockHubspotComNotesQuebradas();

        $this->dispararWebhook([$this->eventoPadrao()])->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status);

        $company = Company::find($evento->company_id_criada);
        $this->assertNotNull($company);
        $this->assertSame([], $company->hubspot_notas);
        $this->assertNull($company->hubspot_observacao);
        // O resto do handoff continua chegando normalmente.
        $this->assertSame('Parceiro de Polos', $company->origem_lead);
    }

    /**
     * Mesmo conjunto de fakes do mockHubspot(), mas com o endpoint de
     * associations de notes devolvendo 500.
     */
    private function mockHubspotComNotesQuebradas(): void
    {
        $base = 'https://api.hubapi.com/crm/v3/objects/';

        Http::fake([
            $base . 'deals/' . self::DEAL_ID . '/associations/notes' => Http::response([], 500),
            $base . 'deals/' . self::DEAL_ID . '/associations/companies' => Http::response([
                'results' => [['id' => '406001']],
            ]),
            $base . 'companies/406001*' => Http::response([
                'id'         => '406001',
                'properties' => [
                    'name'   => 'Metalform LTDA',
                    'cnpj'   => '55566677000188',
                    'domain' => 'metalform.com.br',
                ],
            ]),
            $base . 'deals/' . self::DEAL_ID . '/associations/contacts' => Http::response([
                'results' => [['id' => '235433492313']],
            ]),
            $base . 'contacts/235433492313*' => Http::response([
                'id'         => '235433492313',
                'properties' => [
                    'firstname'      => 'Carlos',
                    'lastname'       => 'Metalform',
                    'email'          => 'carlos@metalform.com.br',
                    'phone'          => '11955554444',
                    'jobtitle'       => 'Diretor',
                    'origem_do_lead' => 'Parceiro de Polos',
                ],
            ]),
            $base . 'deals/' . self::DEAL_ID . '/associations/line_items' => Http::response([
                'results' => [],
            ]),
            $base . 'deals/' . self::DEAL_ID . '*' => Http::response([
                'id'         => self::DEAL_ID,
                'properties' => [
                    'dealname'  => 'Metalform',
                    'amount'    => '2000.00',
                    'dealstage' => 'closedwon',
                ],
            ]),
        ]);
    }
}
