<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Notifications\EmpresaCadastradaNotification;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Suíte de testes para o módulo Comercial — cadastro centralizado de empresas.
 *
 * Cobre autenticação, validação, guard de duplicata, criação atômica por
 * service_type, visibilidade no financeiro e notificação de líderes.
 *
 * @group phase13
 */
class Phase13ComercialTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Cria um user com permissão `comercial.cadastrar_empresa` via setor — exercita
     * o caminho real de SetorPermissao + effectivePermissions.
     */
    private function userComPermissao(): User
    {
        $setor = Setor::create(['nome' => 'Comercial Teste', 'slug' => 'comercial-teste', 'active' => true]);
        SetorPermissao::create([
            'setor_id'       => $setor->id,
            'permission_key' => Permissions::COMERCIAL_CADASTRAR_EMPRESA,
        ]);

        $user = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($user->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        return $user;
    }

    /**
     * Cria um user admin — tem todas as permissões via short-circuit isAdmin().
     */
    private function userAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Cria um user sem nenhuma permissão especial.
     */
    private function userSemPermissao(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    /**
     * Payload válido padrão para POST /comercial/empresas.
     */
    private function payloadValido(array $overrides = []): array
    {
        return array_merge([
            'nome'         => 'Empresa Teste Comercial',
            'cnpj'         => null,
            'service_type' => 'publicidade',
        ], $overrides);
    }

    // ─── Testes de Autenticação e Permissão ──────────────────────────────────

    /**
     * COM-02: User sem permissão 'comercial.cadastrar_empresa' deve receber 403
     * ao tentar acessar GET /comercial/empresas/novo.
     */
    public function test_sem_permissao_recebe_403(): void
    {
        $user = $this->userSemPermissao();

        $response = $this->actingAs($user)->get('/comercial/empresas/novo');

        $response->assertStatus(403);
    }

    /**
     * COM-02: Admin acessa GET /comercial/empresas/novo via short-circuit isAdmin()
     * sem precisar de permissão explícita no setor.
     */
    public function test_admin_acessa_sem_permissao_explicita(): void
    {
        $user = $this->userAdmin();

        $response = $this->actingAs($user)->get('/comercial/empresas/novo');

        $response->assertStatus(200);
    }

    // ─── Testes de Validação ──────────────────────────────────────────────────

    /**
     * COM-03: POST sem 'nome' ou sem 'service_type' retorna erro de validação.
     */
    public function test_validacao_campos_obrigatorios(): void
    {
        $user = $this->userAdmin();

        // Sem nenhum campo
        $response = $this->actingAs($user)->post('/comercial/empresas', []);
        $response->assertSessionHasErrors(['nome', 'service_type']);

        // Com nome mas sem service_type
        $response = $this->actingAs($user)->post('/comercial/empresas', ['nome' => 'Empresa']);
        $response->assertSessionHasErrors(['service_type']);

        // Com service_type mas sem nome
        $response = $this->actingAs($user)->post('/comercial/empresas', ['service_type' => 'polos']);
        $response->assertSessionHasErrors(['nome']);
    }

    // ─── Testes de Guard de Duplicata ─────────────────────────────────────────

    /**
     * COM-04 / D-21: POST com nome igual a company existente deve retornar
     * erro de validação no campo 'nome' com mensagem de duplicata.
     */
    public function test_guard_duplicata_companies(): void
    {
        $user = $this->userAdmin();

        // Cria uma company existente
        DB::table('companies')->insert([
            'name'       => 'Empresa Duplicada',
            'active'     => true,
            'status'     => 'ativo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->post('/comercial/empresas', $this->payloadValido([
            'nome' => 'Empresa Duplicada',
        ]));

        $response->assertSessionHasErrors(['nome']);
    }

    /**
     * COM-04 / D-21: POST com nome igual a mlb_empresa existente deve retornar
     * erro de validação no campo 'nome' com mensagem de duplicata.
     */
    public function test_guard_duplicata_mlb_empresas(): void
    {
        $user = $this->userAdmin();

        // Cria uma mlb_empresa existente
        DB::table('mlb_empresas')->insert([
            'nome'       => 'Empresa MLB Duplicada',
            'tipo'       => 'POLO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->post('/comercial/empresas', $this->payloadValido([
            'nome' => 'Empresa MLB Duplicada',
        ]));

        $response->assertSessionHasErrors(['nome']);
    }

    // ─── Testes de Criação por service_type ───────────────────────────────────

    /**
     * COM-05: POST com service_type='polos' deve criar atomicamente:
     * 1 company + 1 mlb_empresa (tipo=POLO) + 1 mlb_implementacao.
     */
    public function test_cria_empresa_polos_atomico(): void
    {
        $user = $this->userAdmin();

        $response = $this->actingAs($user)->post('/comercial/empresas', $this->payloadValido([
            'nome'         => 'Empresa Nova POLO',
            'service_type' => 'polos',
        ]));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        // Verifica company criada
        $this->assertDatabaseHas('companies', [
            'name'         => 'Empresa Nova POLO',
            'service_type' => 'polos',
            'status'       => 'pendente',
            'active'       => true,
        ]);

        // Verifica mlb_empresa criada e vinculada
        $company = Company::where('name', 'Empresa Nova POLO')->first();
        $this->assertNotNull($company);

        $this->assertDatabaseHas('mlb_empresas', [
            'nome'       => 'Empresa Nova POLO',
            'tipo'       => 'POLO',
            'company_id' => $company->id,
        ]);

        // Verifica mlb_implementacao criada
        $mlbEmpresa = MlbEmpresa::where('nome', 'Empresa Nova POLO')->first();
        $this->assertNotNull($mlbEmpresa);
        $this->assertDatabaseHas('mlb_implementacoes', [
            'empresa_id' => $mlbEmpresa->id,
        ]);
    }

    /**
     * COM-06: POST com service_type='assessoria' deve criar:
     * 1 company + 1 mlb_empresa (tipo=ASSESSORIA); sem mlb_implementacao.
     */
    public function test_cria_empresa_assessoria(): void
    {
        $user = $this->userAdmin();

        $response = $this->actingAs($user)->post('/comercial/empresas', $this->payloadValido([
            'nome'         => 'Empresa Nova Assessoria',
            'service_type' => 'assessoria',
        ]));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertDatabaseHas('companies', [
            'name'         => 'Empresa Nova Assessoria',
            'service_type' => 'assessoria',
            'status'       => 'pendente',
        ]);

        $company = Company::where('name', 'Empresa Nova Assessoria')->first();
        $this->assertNotNull($company);

        $this->assertDatabaseHas('mlb_empresas', [
            'nome'       => 'Empresa Nova Assessoria',
            'tipo'       => 'ASSESSORIA',
            'company_id' => $company->id,
        ]);

        // Não deve criar mlb_implementacao
        $mlbEmpresa = MlbEmpresa::where('nome', 'Empresa Nova Assessoria')->first();
        $this->assertNotNull($mlbEmpresa);
        $this->assertDatabaseMissing('mlb_implementacoes', [
            'empresa_id' => $mlbEmpresa->id,
        ]);
    }

    /**
     * COM-07: POST com service_type='publicidade' deve criar apenas 1 company;
     * sem mlb_empresa nem mlb_implementacao.
     */
    public function test_cria_empresa_publicidade(): void
    {
        $user = $this->userAdmin();

        $response = $this->actingAs($user)->post('/comercial/empresas', $this->payloadValido([
            'nome'         => 'Empresa Nova Publicidade',
            'service_type' => 'publicidade',
        ]));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertDatabaseHas('companies', [
            'name'         => 'Empresa Nova Publicidade',
            'service_type' => 'publicidade',
            'status'       => 'pendente',
        ]);

        $this->assertDatabaseMissing('mlb_empresas', [
            'nome' => 'Empresa Nova Publicidade',
        ]);
    }

    /**
     * COM-07: POST com service_type='gestao' deve criar apenas 1 company;
     * sem mlb_empresa nem mlb_implementacao.
     */
    public function test_cria_empresa_gestao(): void
    {
        $user = $this->userAdmin();

        $response = $this->actingAs($user)->post('/comercial/empresas', $this->payloadValido([
            'nome'         => 'Empresa Nova Gestao',
            'service_type' => 'gestao',
        ]));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertDatabaseHas('companies', [
            'name'         => 'Empresa Nova Gestao',
            'service_type' => 'gestao',
            'status'       => 'pendente',
        ]);

        $this->assertDatabaseMissing('mlb_empresas', [
            'nome' => 'Empresa Nova Gestao',
        ]);
    }

    // ─── Testes de Visibilidade no Financeiro ─────────────────────────────────

    /**
     * COM-08: Company criada pelo Comercial (active=true) deve aparecer
     * em AdminController::fechamento() props (query por active=true).
     */
    public function test_empresa_visivel_no_financeiro(): void
    {
        $admin = $this->userAdmin();

        // Cria empresa via Comercial
        $this->actingAs($admin)->post('/comercial/empresas', $this->payloadValido([
            'nome'         => 'Empresa Financeiro Teste',
            'service_type' => 'gestao',
        ]));

        // Acessa rota do financeiro
        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertStatus(200);

        // Verifica que a empresa está na query (active=true)
        $this->assertDatabaseHas('companies', [
            'name'   => 'Empresa Financeiro Teste',
            'active' => true,
        ]);
    }

    // ─── Testes de Notificação ─────────────────────────────────────────────────

    /**
     * COM-08: Ao criar empresa, EmpresaCadastradaNotification deve ser enviada
     * para os líderes do setor de destino.
     */
    public function test_notificacao_lideres(): void
    {
        Notification::fake();

        $admin = $this->userAdmin();

        // Cria setor 'publicacao' e vincula um líder
        $setor = Setor::create(['nome' => 'Publicação', 'slug' => 'publicacao', 'active' => true]);
        $lider = User::factory()->create(['role' => 'consultor']);
        $setor->lideres()->attach($lider->id, [
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
        ]);

        // Cria empresa do tipo 'polos' → setor de destino 'publicacao'
        $this->actingAs($admin)->post('/comercial/empresas', $this->payloadValido([
            'nome'         => 'Empresa Notificacao POLO',
            'service_type' => 'polos',
        ]));

        // Verifica que a notificação foi enviada para o líder
        Notification::assertSentTo($lider, EmpresaCadastradaNotification::class);
    }

    /**
     * COM-08: Quando o setor de destino não tem líderes, a criação não deve
     * lançar exceção — deve continuar normalmente.
     */
    public function test_sem_lideres_nao_falha(): void
    {
        Notification::fake();

        $admin = $this->userAdmin();

        // Cria setor 'publicacao' SEM líderes
        Setor::create(['nome' => 'Publicação', 'slug' => 'publicacao', 'active' => true]);

        $response = $this->actingAs($admin)->post('/comercial/empresas', $this->payloadValido([
            'nome'         => 'Empresa Sem Lideres',
            'service_type' => 'polos',
        ]));

        // Deve redirecionar normalmente sem exceção
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        // Empresa deve ter sido criada
        $this->assertDatabaseHas('companies', ['name' => 'Empresa Sem Lideres']);

        Notification::assertNothingSent();
    }
}
