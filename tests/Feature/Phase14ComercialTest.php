<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\User;
use App\Notifications\EmpresaCadastradaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Suíte feature do refator Comercial — Phase 14 Plan 14-04.
 *
 * Cobre:
 *  - Roteamento por NOME do catálogo (não mais slug enum) — Polos/Assessoria/
 *    Publicidade/Gestão preservando COM-04/05/06 do Phase 13.
 *  - Criação atômica de Company + N ContratoServico via DB::transaction.
 *  - Validação de servicos[] (exists em servicos + ativo=true).
 *  - Notification enviada para líderes do setor com array (não string).
 *  - Update enxuto (apenas name/cnpj/notes) — campos legacy ignorados.
 *
 * Per Plan 14-04 + CONTEXT.md D-05/D-07/D-09.
 */
class Phase14ComercialTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante que os 6 servicos canônicos do catálogo existem antes de cada teste.
     * RefreshDatabase + migrate já rodam a Migration 1 (Plan 14-02) que faz o seed,
     * mas blindamos contra qualquer reset com firstOrCreate idempotente.
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

    // ─── Roteamento COM-04 / COM-05 / COM-06 ────────────────────────────────

    /**
     * COM-04 preservado: cadastrar com Polos cria Company + MlbEmpresa (POLO)
     * + MlbImplementacao (token + dados padrão).
     */
    public function test_cadastra_empresa_polos_cria_mlb_implementacao(): void
    {
        $admin = $this->userAdmin();
        $polos = $this->servico('Polos');

        $response = $this->actingAs($admin)->post('/comercial/empresas', [
            'nome'     => 'Empresa Teste Polos',
            'servicos' => [
                ['servico_id' => $polos->id, 'valor_contratado' => 0],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $company = Company::where('name', 'Empresa Teste Polos')->first();
        $this->assertNotNull($company, 'Company deveria ter sido criada');
        $this->assertEquals('pendente', $company->status);
        $this->assertEquals(1, $company->contratosServico()->count(), '1 contrato (Polos) esperado');

        $mlbEmp = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'POLO')->first();
        $this->assertNotNull($mlbEmp, 'MlbEmpresa POLO deveria ter sido criada (COM-04)');

        $impl = MlbImplementacao::where('empresa_id', $mlbEmp->id)->first();
        $this->assertNotNull($impl, 'MlbImplementacao deveria ter sido criada para POLO (COM-04)');
    }

    /**
     * COM-05 preservado: cadastrar com Assessoria cria Company + MlbEmpresa
     * (ASSESSORIA) — SEM mlb_implementacao (apenas Polos dispara implementação).
     */
    public function test_cadastra_empresa_assessoria_cria_mlb_sem_implementacao(): void
    {
        $admin = $this->userAdmin();
        $ass   = $this->servico('Assessoria');

        $response = $this->actingAs($admin)->post('/comercial/empresas', [
            'nome'     => 'Empresa Teste Assessoria',
            'servicos' => [
                ['servico_id' => $ass->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $company = Company::where('name', 'Empresa Teste Assessoria')->first();
        $this->assertNotNull($company);

        $mlbEmp = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'ASSESSORIA')->first();
        $this->assertNotNull($mlbEmp, 'MlbEmpresa ASSESSORIA deveria ter sido criada (COM-05)');

        $this->assertDatabaseMissing('mlb_implementacoes', [
            'empresa_id' => $mlbEmp->id,
        ]);
    }

    /**
     * COM-06 preservado: cadastrar com Publicidade cria APENAS Company.
     * Sem MlbEmpresa, sem MlbImplementacao.
     */
    public function test_cadastra_empresa_publicidade_nao_cria_mlb(): void
    {
        $admin = $this->userAdmin();
        $pub   = $this->servico('Publicidade');

        $response = $this->actingAs($admin)->post('/comercial/empresas', [
            'nome'     => 'Empresa Teste Publicidade',
            'servicos' => [
                ['servico_id' => $pub->id, 'valor_contratado' => 500],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $company = Company::where('name', 'Empresa Teste Publicidade')->first();
        $this->assertNotNull($company);

        $this->assertNull(
            MlbEmpresa::where('company_id', $company->id)->first(),
            'Publicidade não deve criar mlb_empresas (COM-06)',
        );
    }

    /**
     * Múltiplos serviços: cria 2 contratos + apenas 1 MlbEmpresa quando há
     * 1 serviço disparador (Polos) + 1 não-disparador (Gestão).
     */
    public function test_cadastra_empresa_com_multiplos_servicos_cria_contratos_e_mlb_apropriado(): void
    {
        $admin  = $this->userAdmin();
        $polos  = $this->servico('Polos');
        $gestao = $this->servico('Gestão');

        $response = $this->actingAs($admin)->post('/comercial/empresas', [
            'nome'     => 'Empresa Multi',
            'servicos' => [
                ['servico_id' => $polos->id],
                ['servico_id' => $gestao->id, 'valor_contratado' => 300],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $company = Company::where('name', 'Empresa Multi')->first();
        $this->assertNotNull($company);
        $this->assertEquals(2, $company->contratosServico()->count(), '2 contratos esperados (Polos + Gestão)');

        $mlbs = MlbEmpresa::where('company_id', $company->id)->get();
        $this->assertCount(1, $mlbs, 'Apenas 1 MlbEmpresa esperada (Polos dispara, Gestão não)');
        $this->assertEquals('POLO', $mlbs->first()->tipo);
    }

    // ─── Validação ──────────────────────────────────────────────────────────

    /**
     * Validação: servicos[] vazio deve falhar.
     */
    public function test_cadastro_sem_servicos_falha_validation(): void
    {
        $admin = $this->userAdmin();

        $response = $this->actingAs($admin)->post('/comercial/empresas', [
            'nome'     => 'Empresa Vazia',
            'servicos' => [],
        ]);

        $response->assertSessionHasErrors(['servicos']);
        $this->assertNull(Company::where('name', 'Empresa Vazia')->first());
    }

    /**
     * Validação: servico_id apontando para servico INATIVO deve falhar
     * (Rule::exists com where ativo=true).
     */
    public function test_cadastro_com_servico_inativo_falha_validation(): void
    {
        $admin = $this->userAdmin();

        $inativo = Servico::create([
            'nome'          => 'Treinamento Especial',
            'valor_padrao'  => 100,
            'tipo_cobranca' => 'unica',
            'ativo'         => false,
        ]);

        $response = $this->actingAs($admin)->post('/comercial/empresas', [
            'nome'     => 'Empresa Inativa',
            'servicos' => [
                ['servico_id' => $inativo->id],
            ],
        ]);

        $response->assertSessionHasErrors(['servicos.0.servico_id']);
        $this->assertNull(Company::where('name', 'Empresa Inativa')->first());
    }

    // ─── Notification (COM-08 preservado) ───────────────────────────────────

    /**
     * COM-08 preservado: ao cadastrar Polos, líderes do setor 'publicacao'
     * recebem EmpresaCadastradaNotification — chamada com ARRAY de nomes
     * (não mais string com implode '+').
     */
    public function test_cadastro_aciona_notificacao_para_lideres_do_setor(): void
    {
        Notification::fake();

        $admin = $this->userAdmin();

        // Setup setor + líder
        $setor = Setor::create([
            'nome'   => 'Publicação',
            'slug'   => 'publicacao',
            'active' => true,
        ]);
        $lider = User::factory()->create(['role' => 'consultor']);
        $setor->lideres()->attach($lider->id, [
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
        ]);

        $polos = $this->servico('Polos');

        $response = $this->actingAs($admin)->post('/comercial/empresas', [
            'nome'     => 'Empresa Notif Polos',
            'servicos' => [
                ['servico_id' => $polos->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        // Verifica que a notificação foi enviada para o líder
        Notification::assertSentTo(
            $lider,
            EmpresaCadastradaNotification::class,
            function ($notification) {
                // Garante que a notification foi construída com array de nomes
                // (e não string legacy). Acessamos a propriedade `meta` do
                // BaseNotification para inspecionar.
                $meta = $notification->meta ?? [];
                return is_array($meta['servicos'] ?? null)
                    && in_array('Polos', $meta['servicos'], true);
            },
        );
    }

    // ─── Update enxuto ──────────────────────────────────────────────────────

    /**
     * Validação enxuta no update(): campos legacy enviados pelo cliente
     * (service_type, additional_service_price) são silenciosamente IGNORADOS.
     * Apenas name/cnpj/notes são persistidos.
     */
    public function test_update_ignora_campos_legacy(): void
    {
        $admin = $this->userAdmin();

        // Cria empresa diretamente (sem passar pelo store, para isolar update)
        $company = Company::create([
            'name'                     => 'Empresa Original',
            'cnpj'                     => null,
            'notes'                    => 'Notas originais',
            'service_type'             => ['publicidade'], // valor legacy populado
            'additional_service_price' => 200.00,
            'status'                   => 'pendente',
            'active'                   => true,
        ]);

        $valorLegacyAnterior = (float) $company->additional_service_price;

        $response = $this->actingAs($admin)->put("/comercial/empresas/{$company->id}", [
            'name'                     => 'Nome Novo',
            'cnpj'                     => '12345678000100',
            'notes'                    => 'Notas novas',
            // Campos legacy enviados — devem ser ignorados pela validação enxuta
            'service_type'             => ['polos'],
            'additional_service_price' => 999.99,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $company->refresh();

        // Campos persistidos
        $this->assertEquals('Nome Novo', $company->name);
        $this->assertEquals('12345678000100', $company->cnpj);
        $this->assertEquals('Notas novas', $company->notes);

        // Campos legacy NÃO devem ter sido sobrescritos
        $this->assertNotEquals(['polos'], $company->service_type, 'service_type legacy não deve ser persistido');
        $this->assertEquals(['publicidade'], $company->service_type, 'service_type legacy deve manter o valor anterior');
        $this->assertEquals(
            $valorLegacyAnterior,
            (float) $company->additional_service_price,
            'additional_service_price legacy não deve ser sobrescrito',
        );
    }
}
