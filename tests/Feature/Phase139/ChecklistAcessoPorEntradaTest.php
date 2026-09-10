<?php

namespace Tests\Feature\Phase139;

use App\Models\Company;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Fase 139 Plano 08 (D-17/D-08/D-09, T-139-08-01/T-139-08-02) — a ficha única
 * `admin.contratos.show` abre para as DUAS permissões, e o payload é recortado
 * pela permissão de MÓDULO.
 *
 * Réplica do molde de `tests/Feature/Phase138/ComercEntradaPermissaoRotaTest.php`:
 * asserção por NOME de rota via `Route::getRoutes()->getByName()` +
 * `gatherMiddleware()`, não por texto do arquivo de rotas — pega também o caso
 * de a rota ser envolvida por um grupo externo depois.
 *
 * ⚠️ `permission:a,b` é UMA string de middleware com vírgula, não duas entradas
 * na lista de `gatherMiddleware()`. Comparar com a string inteira.
 */
class ChecklistAcessoPorEntradaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // setUp blindado — mesma disciplina de ContratoAdminDetalheTest:
        // nenhuma chamada real de rede nem job enfileirado de verdade.
        Http::fake();
        Queue::fake();
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    /** User não-admin que pertence a um setor com a permission_key gravada. */
    private function userComPermissaoViaSetor(string $permissionKey): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Checklist Teste',
            'slug'   => 'checklist-teste-'.uniqid(),
            'active' => true,
        ]);
        SetorPermissao::create([
            'setor_id'       => $setor->id,
            'permission_key' => $permissionKey,
        ]);
        $user = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($user->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        return $user;
    }

    private function empresa(array $attrs = []): Company
    {
        return Company::factory()->create(array_merge([
            'active' => true,
            'etapa'  => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
        ], $attrs));
    }

    /** @return array<int, string> */
    private function middlewaresDe(string $nomeRota): array
    {
        $rota = Route::getRoutes()->getByName($nomeRota);

        $this->assertNotNull($rota, "A rota {$nomeRota} precisa existir.");

        return $rota->gatherMiddleware();
    }

    // ─── Caso 1 — a ficha aceita as duas permissões em OR (D-17) ────────────

    public function test_a_rota_da_ficha_aceita_as_duas_permissoes_em_or(): void
    {
        $middlewares = $this->middlewaresDe('admin.contratos.show');

        $esperado = 'permission:'.Permissions::ADMIN_CONTRATOS.','.Permissions::COMERCIAL_ENTRADA;

        $this->assertContains(
            $esperado,
            $middlewares,
            'admin.contratos.show deve usar o OR nativo do EnsurePermission — middlewares: '.implode(', ', $middlewares)
        );

        // E nunca sob role:admin — a D-09 exige chave liberável por setor.
        $this->assertNotContains('role:admin', $middlewares);
    }

    // ─── Caso 2 — as 4 rotas de ação têm o mesmo OR ─────────────────────────

    public function test_as_quatro_rotas_de_acao_tem_a_mesma_permissao_em_or(): void
    {
        $esperado = 'permission:'.Permissions::ADMIN_CONTRATOS.','.Permissions::COMERCIAL_ENTRADA;

        foreach ([
            'admin.contratos.checklist.concluir',
            'admin.contratos.checklist.reabrir',
            'admin.contratos.checklist.conexao-ecf',
            'admin.contratos.finalizar-entrada',
        ] as $nome) {
            $middlewares = $this->middlewaresDe($nome);

            $this->assertContains(
                $esperado,
                $middlewares,
                "{$nome} deve usar o OR das duas permissões — middlewares: ".implode(', ', $middlewares)
            );
        }
    }

    // ─── Caso 3 — só comercial.entrada abre a ficha sem 403 ─────────────────

    public function test_usuario_so_com_comercial_entrada_abre_a_ficha(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::COMERCIAL_ENTRADA);

        $response = $this->actingAs($user)->get(route('admin.contratos.show', $this->empresa()));

        $response->assertOk();
    }

    // ─── Caso 4 — só admin.contratos continua abrindo ───────────────────────

    public function test_usuario_so_com_admin_contratos_abre_a_ficha(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        $response = $this->actingAs($user)->get(route('admin.contratos.show', $this->empresa()));

        $response->assertOk();
    }

    // ─── Caso 5 — sem nenhuma das duas: 403 ─────────────────────────────────

    public function test_usuario_sem_nenhuma_das_duas_permissoes_recebe_403(): void
    {
        $user = User::factory()->create(['role' => 'consultor']);

        $response = $this->actingAs($user)->get(route('admin.contratos.show', $this->empresa()));

        $response->assertStatus(403);
    }

    // ─── Caso 6 — o OR não vazou para o resto do grupo ──────────────────────

    public function test_as_demais_rotas_do_grupo_continuam_so_com_admin_contratos(): void
    {
        $soAdmin = 'permission:'.Permissions::ADMIN_CONTRATOS;
        $comOr   = 'permission:'.Permissions::ADMIN_CONTRATOS.','.Permissions::COMERCIAL_ENTRADA;

        foreach ([
            'admin.contratos.index',
            'admin.contratos.gerar',
            'admin.contratos.liberacao-manual',
        ] as $nome) {
            $middlewares = $this->middlewaresDe($nome);

            $this->assertContains($soAdmin, $middlewares, "{$nome} deve continuar restrita a admin.contratos.");
            $this->assertNotContains($comOr, $middlewares, "O OR da D-17 NÃO pode vazar para {$nome}.");
        }
    }

    // ─── Caso 7 — nenhuma chave de permissão nova (D-09) ────────────────────

    public function test_o_catalogo_de_permissoes_nao_ganhou_chave_nova_de_checklist(): void
    {
        $chaves = collect(Permissions::catalog())->flatten(1)->pluck('key')->all();

        $this->assertContains(Permissions::ADMIN_CONTRATOS, $chaves);
        $this->assertContains(Permissions::COMERCIAL_ENTRADA, $chaves);

        $novas = collect($chaves)->filter(fn (string $k) => str_starts_with($k, 'checklist.'))->all();

        $this->assertSame([], $novas, 'A Fase 139 não cria chave de permissão nova (D-09) — encontradas: '.implode(', ', $novas));
    }
}
