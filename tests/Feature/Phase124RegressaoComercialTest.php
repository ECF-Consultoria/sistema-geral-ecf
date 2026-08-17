<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 124 Plano 01 — Caracterização do caminho Comercial ANTES da extração.
 *
 * A Fase 124 é refatoração pura: `PendenciasComerciaisService` e
 * `EmpresaOperacionalRouter` vão substituir o código inline de
 * `ComercialController::store()` sem mudar nada observável. A única forma de
 * provar isso é ter, ANTES de tocar em produção, testes que capturam o
 * comportamento de HOJE e continuam passando SEM ALTERAÇÃO depois.
 *
 * Se algum teste deste arquivo precisar mudar para voltar a ficar verde, o
 * comportamento mudou — investigar a regressão, não "atualizar o teste".
 */
class Phase124RegressaoComercialTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante os 6 serviços canônicos do catálogo antes de cada teste —
     * mesma convenção de `Phase14ComercialTest::setUp()`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Publicação', 'Polos', 'Assessoria', 'Incubadora', 'Publicidade', 'Gestão'] as $nome) {
            Servico::firstOrCreate(
                ['nome' => $nome],
                ['valor_padrao' => 0, 'tipo_cobranca' => 'mensal', 'ativo' => true],
            );
        }
    }

    private function userAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servico(string $nome): Servico
    {
        return Servico::where('nome', $nome)->firstOrFail();
    }

    // ─── gmail_colaborador na criação (FLUXO-07) ────────────────────────────

    /**
     * ⚠️ TRANSITÓRIO (D8 do REQUIREMENTS-v22 / D-02 do CONTEXT da Fase 124):
     * cadastrar o Gmail do colaborador deixa de ser função do Comercial e
     * passa para o Administrativo na Fase 131 — o campo sai do formulário do
     * Comercial lá. Este teste morre naquela fase de propósito; não é
     * regressão da Fase 124, que só preserva o que existe hoje.
     */
    public function test_comercial_polos_grava_gmail_colaborador_em_links_admin(): void
    {
        $admin = $this->userAdmin();
        $polos = $this->servico('Polos');

        $response = $this->actingAs($admin)->post('/comercial/empresas', [
            'nome'              => 'Empresa Teste Gmail Colaborador',
            'gmail_colaborador' => 'colaborador@empresateste.com',
            'servicos'          => [
                ['servico_id' => $polos->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $company = Company::where('name', 'Empresa Teste Gmail Colaborador')->firstOrFail();
        $mlbEmp  = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'POLO')->first();
        $this->assertNotNull($mlbEmp, 'MlbEmpresa POLO deveria ter sido criada');

        $impl = MlbImplementacao::where('empresa_id', $mlbEmp->id)->first();
        $this->assertNotNull($impl, 'MlbImplementacao deveria ter sido criada para POLO');
        $this->assertSame(
            'colaborador@empresateste.com',
            $impl->dados['links_admin']['gmail_colaborador'] ?? null,
        );
    }

    /**
     * Sem `gmail_colaborador` no POST, o valor persistido é exatamente o
     * default do próprio `MlbImplementacao::dadosPadrao()` — nunca um
     * literal hardcoded neste teste, para não divergir se o default mudar
     * por outro motivo alheio a esta fase.
     */
    public function test_comercial_polos_sem_gmail_colaborador_mantem_o_padrao(): void
    {
        $admin = $this->userAdmin();
        $polos = $this->servico('Polos');

        $response = $this->actingAs($admin)->post('/comercial/empresas', [
            'nome'     => 'Empresa Teste Sem Gmail',
            'servicos' => [
                ['servico_id' => $polos->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $company = Company::where('name', 'Empresa Teste Sem Gmail')->firstOrFail();
        $mlbEmp  = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'POLO')->first();
        $impl    = MlbImplementacao::where('empresa_id', $mlbEmp->id)->first();

        $default = MlbImplementacao::dadosPadrao()['links_admin']['gmail_colaborador'];

        $this->assertSame($default, $impl->dados['links_admin']['gmail_colaborador'] ?? null);
    }

    // ─── Incubadora ponta a ponta ────────────────────────────────────────────

    /**
     * Incubadora cria MlbEmpresa tipo INCUBADORA e NENHUMA MlbImplementacao
     * (só o ramo Polos dispara implementação). `projeto` e `fase` continuam
     * null — só o ramo Polos seta `projeto`, e nenhum ramo seta `fase`.
     * `estagio` mantém o default ATUAL da coluna: a migration original
     * `2026_04_30_000003_create_mlb_empresas_table.php` declarava
     * `default('Não Listado')`, mas `2026_05_08_000001_make_estagio_nullable_mlb_empresas.php`
     * mudou a coluna para `nullable()->default(null)` — o literal vigente é
     * `null`, confirmado lendo as duas migrations, não presumido pela primeira.
     */
    public function test_comercial_incubadora_cria_mlb_empresa_sem_implementacao(): void
    {
        $admin      = $this->userAdmin();
        $incubadora = $this->servico('Incubadora');

        $response = $this->actingAs($admin)->post('/comercial/empresas', [
            'nome'     => 'Empresa Teste Incubadora',
            'servicos' => [
                ['servico_id' => $incubadora->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $company = Company::where('name', 'Empresa Teste Incubadora')->firstOrFail();
        $mlbEmp  = MlbEmpresa::where('company_id', $company->id)->first();

        $this->assertNotNull($mlbEmp, 'MlbEmpresa deveria ter sido criada');
        $this->assertSame('INCUBADORA', $mlbEmp->tipo);
        $this->assertNull($mlbEmp->projeto, 'Só o ramo Polos seta projeto');
        $this->assertNull($mlbEmp->fase, 'Nenhum ramo de roteamento seta fase');
        $this->assertNull(
            $mlbEmp->estagio,
            'Default ATUAL da coluna (migration 2026_05_08_000001 tornou nullable/null)',
        );

        $this->assertSame(0, MlbImplementacao::count(), 'Incubadora não dispara MlbImplementacao');
    }

    // ─── Divergência D-08 (duas fichas na mesma submissão) ──────────────────

    /**
     * Este é o comportamento VIGENTE do caminho Comercial, registrado de
     * propósito (D-08 do CONTEXT da Fase 124 / D10 do REQUIREMENTS-v22).
     *
     * O caminho Comercial não tem guard entre iterações do laço de
     * roteamento — diferente do webhook HubSpot, que tem o guard
     * `MlbEmpresa::where('company_id', ...)->exists()` na linha ~938 de
     * `HubspotWebhookController::rotearImplementacao()`. Por isso, com Polos
     * + Assessoria na MESMA submissão, o Comercial cria DUAS MlbEmpresa; o
     * HubSpot, para o mesmo cenário, criaria UMA.
     *
     * Aplicar aquele guard literalmente ao laço do Comercial faria este
     * teste passar a exigir `count() === 1` — o que É uma mudança de
     * comportamento observável e está PROIBIDO na Fase 124. Se alguém
     * precisar mudar esta asserção, é decisão consciente de outra fase, não
     * conserto de refactor.
     */
    public function test_comercial_dois_tipos_no_mesmo_request_cria_duas_mlb_empresa_hoje(): void
    {
        $admin      = $this->userAdmin();
        $polos      = $this->servico('Polos');
        $assessoria = $this->servico('Assessoria');

        $response = $this->actingAs($admin)->post('/comercial/empresas', [
            'nome'     => 'Empresa Teste Dois Tipos',
            'servicos' => [
                ['servico_id' => $polos->id],
                ['servico_id' => $assessoria->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $company = Company::where('name', 'Empresa Teste Dois Tipos')->firstOrFail();
        $mlbs    = MlbEmpresa::where('company_id', $company->id)->get();

        $this->assertCount(2, $mlbs, 'D-08: hoje o Comercial cria DUAS fichas, sem guard no laço');
        $this->assertEqualsCanonicalizing(['POLO', 'ASSESSORIA'], $mlbs->pluck('tipo')->all());

        $mlbPolo       = $mlbs->firstWhere('tipo', 'POLO');
        $mlbAssessoria = $mlbs->firstWhere('tipo', 'ASSESSORIA');

        $this->assertNotNull(
            MlbImplementacao::where('empresa_id', $mlbPolo->id)->first(),
            'A ficha POLO tem MlbImplementacao associada',
        );
        $this->assertNull(
            MlbImplementacao::where('empresa_id', $mlbAssessoria->id)->first(),
            'A ficha ASSESSORIA não tem MlbImplementacao — só Polos dispara',
        );
    }

    // ─── Interruptor desligado (REDE-01) ─────────────────────────────────────

    /**
     * Prova o Phase Boundary da Fase 124: com o interruptor DESLIGADO — o
     * único estado que existe em produção — o roteamento do Comercial é
     * exatamente o de hoje.
     *
     * Este teste passa ANTES da refatoração (nada lê a chave ainda) e
     * precisa continuar passando DEPOIS (a chave passa a ser lida, mas está
     * desligada). O outro lado do interruptor — chave LIGADA interrompendo o
     * roteamento de verdade — é comportamento NOVO, provado em teste
     * isolado no plano 124-04, e não entra neste arquivo porque este
     * arquivo é o baseline congelado.
     */
    public function test_roteamento_do_comercial_com_interruptor_desligado_permanece_igual(): void
    {
        Configuracao::set('administrativo_bloqueio_ativo', '0');

        $admin = $this->userAdmin();
        $polos = $this->servico('Polos');

        $response = $this->actingAs($admin)->post('/comercial/empresas', [
            'nome'     => 'Empresa Teste Interruptor Desligado',
            'servicos' => [
                ['servico_id' => $polos->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $company = Company::where('name', 'Empresa Teste Interruptor Desligado')->firstOrFail();
        $mlbEmp  = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'POLO')->first();

        $this->assertNotNull($mlbEmp, 'Com o interruptor desligado, a ficha POLO continua sendo criada');
        $this->assertNotNull(
            MlbImplementacao::where('empresa_id', $mlbEmp->id)->first(),
            'Com o interruptor desligado, a MlbImplementacao continua sendo criada',
        );
    }
}
