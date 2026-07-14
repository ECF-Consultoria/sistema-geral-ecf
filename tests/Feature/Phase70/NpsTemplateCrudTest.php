<?php

namespace Tests\Feature\Phase70;

use App\Models\NpsTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 70 Plan 06 T1 — Suite Feature do CRUD principal de NpsTemplate.
 *
 * Cobre SC #1 do ROADMAP Phase 70 (list/create/edit/toggle-active) mais os 2
 * guards críticos:
 *   - is_default NÃO pode ser desativado (protege fallback do resolveForCompany)
 *   - is_default NÃO pode ser injetado via payload (Model::update filtra)
 *   - non-admin recebe 403 em TODAS as rotas do módulo
 *
 * REQ atendidos: NPS-C-01 (list), NPS-C-02 (create), NPS-C-03 (edit),
 * NPS-C-04 (toggle-active).
 *
 * Setup implícito via RefreshDatabase:
 *   - Migration 100001 cria as 5 tabelas nps_*
 *   - Migration 100004 seeda o "NPS Padrão" (is_default=true) — usado no
 *     ordering test + guard toggle
 *
 * Referências:
 *   - .planning/phases/70-ui-de-configuracao-admin/70-06-PLAN.md (T1)
 *   - app/Http/Controllers/NpsTemplateController.php (endpoints)
 *   - app/Http/Requests/StoreNpsTemplateRequest.php (rules)
 *   - app/Http/Requests/UpdateNpsTemplateRequest.php (is_default fora de rules)
 */
class NpsTemplateCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante FKs SQLite ativas — padrão herdado das Phases 68/69 (constraints
     * dependem do driver estar com foreign_keys=ON).
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Cria user admin — helper de auth para os testes deste arquivo.
     * (UserFactory não tem state ->admin(); usamos ->create([role]) direto.)
     */
    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Cria user consultor — usado nos guards 403.
     */
    private function consultor(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — index ordena por is_default DESC, priority DESC, id ASC
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_index_retorna_inertia_com_templates_ordenados_por_is_default_priority_id(): void
    {
        // Seeds base: "NPS Padrão" (is_default=true) + "NPS Shopee" (Phase 79,
        // is_default=false). Criamos 2 templates com priorities diferentes. O
        // primeiro na listagem deve ser o default (is_default vence priority).
        // Contamos o baseline pra o teste ser robusto a seeds aditivos.
        $baseline = NpsTemplate::count();

        NpsTemplate::factory()->create([
            'nome'     => 'Template Alpha',
            'priority' => 10,
        ]);
        NpsTemplate::factory()->create([
            'nome'     => 'Template Beta',
            'priority' => 5,
        ]);

        $this->actingAs($this->admin());

        $response = $this->get(route('nps.configuracao.templates.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Nps/Configuracao')
            ->has('templates', $baseline + 2) // 2 criados + seeds base
            ->where('templates.0.is_default', true) // seed padrão vem 1º pelo ORDER BY is_default DESC
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — store cria template com active=true e is_default=false forçados
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_store_cria_template_novo_com_active_true_e_is_default_false(): void
    {
        // Payload inclui campos válidos + tentativa maliciosa de is_default=true.
        // Controller força invariantes (is_default=false, active=true) mesmo se
        // o payload tentar burlar via StoreNpsTemplateRequest (que não aceita
        // is_default no rules).
        $this->actingAs($this->admin());

        $response = $this->post(route('nps.configuracao.templates.store'), [
            'nome'                    => 'Template Performance Mensal',
            'descricao'               => 'Template criado no teste automatizado.',
            'priority'                => 5,
            'envio_automatico_mensal' => true,
            'is_default'              => true, // tentativa maliciosa — deve ser ignorada
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('nps_templates', [
            'nome'       => 'Template Performance Mensal',
            'active'     => true,
            'is_default' => false, // invariante do seed preservado
            'priority'   => 5,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — update ignora is_default no payload (rules não aceita)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_update_edita_campos_do_template_sem_permitir_is_default(): void
    {
        // Cenário: template não-default recebe PUT tentando promover a default.
        // UpdateNpsTemplateRequest não tem is_default no rules() → Laravel
        // filtra do validated() → Model::update não recebe. Coluna continua false.
        $template = NpsTemplate::factory()->create([
            'nome'       => 'Template Editável',
            'is_default' => false,
            'priority'   => 3,
        ]);

        $this->actingAs($this->admin());

        $response = $this->put(
            route('nps.configuracao.templates.update', $template),
            [
                'nome'       => 'Template Editado',
                'priority'   => 20,
                'is_default' => true, // tentativa maliciosa — deve ser filtrada
            ]
        );

        $response->assertRedirect();

        $template->refresh();
        $this->assertSame('Template Editado', $template->nome);
        $this->assertSame(20, $template->priority);
        $this->assertFalse(
            $template->is_default,
            'is_default deveria continuar false — UpdateNpsTemplateRequest não aceita a chave.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — toggle-active alterna flag em 2 direções
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_toggle_active_alterna_flag(): void
    {
        // Template não-default ativo. Toggle 1x → inativo. Toggle 2x → ativo.
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template Toggle',
            'active' => true,
        ]);

        $this->actingAs($this->admin());

        $this->patch(route('nps.configuracao.templates.toggle-active', $template))
            ->assertRedirect();

        $template->refresh();
        $this->assertFalse($template->active, 'Toggle 1x deveria desativar');

        $this->patch(route('nps.configuracao.templates.toggle-active', $template))
            ->assertRedirect();

        $template->refresh();
        $this->assertTrue($template->active, 'Toggle 2x deveria reativar');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 5 — guard is_default: template padrão NÃO pode ser desativado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_toggle_active_bloqueia_desativacao_do_is_default(): void
    {
        // Seed "NPS Padrão" (migration 100004) tem is_default=true + active=true.
        // Toggle NUNCA pode desativá-lo — quebraria o fallback do
        // NpsTemplateService::resolveForCompany. Controller aborta 422.
        $padrao = NpsTemplate::where('is_default', true)->firstOrFail();
        $this->assertTrue($padrao->active, 'baseline: seed NPS Padrão ativo');

        $this->actingAs($this->admin());

        $response = $this->patch(
            route('nps.configuracao.templates.toggle-active', $padrao)
        );

        $response->assertStatus(422);
        $response->assertSee('padrão não pode ser desativado');

        $padrao->refresh();
        $this->assertTrue(
            $padrao->active,
            'seed NPS Padrão deveria continuar ativo apesar do toggle tentado'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 6 — non-admin recebe 403 em TODAS as rotas do módulo
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_non_admin_recebe_403_em_todas_as_rotas_de_templates(): void
    {
        // Consultor tenta cada uma das 4 rotas principais → 403 em todas
        // (middleware role:admin + authorize() no FormRequest, camada dupla).
        $template = NpsTemplate::factory()->create();

        $this->actingAs($this->consultor());

        // GET index
        $this->get(route('nps.configuracao.templates.index'))
            ->assertStatus(403);

        // POST store
        $this->post(route('nps.configuracao.templates.store'), [
            'nome' => 'Template Não Admin',
        ])->assertStatus(403);

        // PUT update
        $this->put(route('nps.configuracao.templates.update', $template), [
            'nome' => 'Editado',
        ])->assertStatus(403);

        // PATCH toggle-active
        $this->patch(route('nps.configuracao.templates.toggle-active', $template))
            ->assertStatus(403);
    }
}
