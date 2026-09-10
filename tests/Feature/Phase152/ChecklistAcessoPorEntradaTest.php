<?php

namespace Tests\Feature\Phase152;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoDefinicao;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Fase 152 Plano 08 (D-17/D-08/D-09, T-152-08-01/T-152-08-02) — a ficha única
 * `admin.contratos.show` abre para as DUAS permissões, e o payload é recortado
 * pela permissão de MÓDULO.
 *
 * Réplica do molde de `tests/Feature/Phase151/ComercEntradaPermissaoRotaTest.php`:
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

        $this->assertSame([], $novas, 'A Fase 152 não cria chave de permissão nova (D-09) — encontradas: '.implode(', ', $novas));
    }

    // ─── Caso 8 — gating do payload: Entrada não vê a seção Contrato ────────

    /**
     * T-152-08-01 — a rota em OR alarga quem alcança esta ficha; o payload é
     * o que impede a escalada virar vazamento. Até a Fase 151, só quem tinha
     * `admin.contratos` chegava aqui, e o payload carrega envelopes e
     * SIGNATÁRIOS.
     */
    public function test_usuario_de_entrada_nao_recebe_a_secao_contrato_no_payload(): void
    {
        $empresa = $this->empresaComContrato();
        $user    = $this->userComPermissaoViaSetor(Permissions::COMERCIAL_ENTRADA);

        $props = $this->propsDaFicha($user, $empresa);

        $this->assertFalse($props['pode_ver_contrato']);
        $this->assertSame([], $props['contratos'], 'Envelopes e signatários NUNCA podem chegar ao perfil de Entrada.');
        $this->assertFalse($props['pode_gerar_contrato']);
        $this->assertNull($props['motivo_bloqueio']);

        $this->assertArrayNotHasKey(
            ChecklistAdministrativoDefinicao::GRUPO_CONTRATO,
            $props['checklist']['grupos'],
            'O grupo Contrato do checklist não pode chegar a quem não tem admin.contratos (D-09).'
        );
        $this->assertArrayHasKey(ChecklistAdministrativoDefinicao::GRUPO_ENTRADA, $props['checklist']['grupos']);

        // O progresso continua sendo o da empresa INTEIRA de propósito — é a
        // régua do FINALIZAR, não uma métrica da seção visível.
        $this->assertSame(9, $props['checklist']['progresso']['total']);

        // As props que os dois perfis compartilham continuam chegando.
        $this->assertSame($empresa->id, $props['company']['id']);
        $this->assertNotEmpty($props['contratos_servico']);
    }

    // ─── Caso 9 — quem tem admin.contratos vê tudo ──────────────────────────

    public function test_usuario_de_contratos_recebe_a_secao_contrato_completa(): void
    {
        $empresa = $this->empresaComContrato();
        $this->envelopeEnviado($empresa);

        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        $props = $this->propsDaFicha($user, $empresa);

        $this->assertTrue($props['pode_ver_contrato']);
        $this->assertNotEmpty($props['contratos'], 'Quem tem admin.contratos continua vendo os envelopes.');
        $this->assertArrayHasKey(ChecklistAdministrativoDefinicao::GRUPO_CONTRATO, $props['checklist']['grupos']);
    }

    // ─── Caso 10 — D-04: o link do Adman vem do servidor ────────────────────

    public function test_o_link_do_adman_chega_do_backend_e_nao_do_jsx(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::COMERCIAL_ENTRADA);

        $props = $this->propsDaFicha($user, $this->empresaComContrato());

        $this->assertSame(config('services.adman.register_url'), $props['adman_register_url']);
        $this->assertNotEmpty($props['adman_register_url']);
    }

    // ─── Caso 11 — a régua do FINALIZAR vem calculada no servidor ───────────

    public function test_pode_finalizar_chega_calculado_no_payload(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        $props = $this->propsDaFicha($user, $this->empresaComContrato());

        $this->assertArrayHasKey('permitido', $props['pode_finalizar']);
        $this->assertArrayHasKey('requisito_faltante', $props['pode_finalizar']);
        $this->assertFalse($props['pode_finalizar']['permitido']);
        $this->assertNotNull($props['pode_finalizar']['requisito_faltante']);
    }

    // ─── Caso 12 — D-15: abrir a ficha observa o evento EXTERNO ─────────────

    /**
     * O degrau 2→3 depende do webhook do Clicksign gravando `enviado_em` —
     * nenhuma ação de checklist acontece nesse caminho. O carregamento da ficha
     * é o único momento em que o sistema observa esse fato. Aqui o envelope é
     * gravado direto no banco, sem passar por endpoint nenhum.
     */
    public function test_abrir_a_ficha_sincroniza_a_etapa_com_o_envelope_enviado_pelo_webhook(): void
    {
        $empresa = $this->empresaComContrato(['etapa' => Company::ETAPA_ADMINISTRATIVO_ANDAMENTO]);
        $this->envelopeEnviado($empresa);

        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        $this->actingAs($user)->get(route('admin.contratos.show', $empresa))->assertOk();

        // Reconsulta ao banco — nunca o objeto em memória.
        $this->assertSame(
            Company::ETAPA_AGUARDANDO_ASSINATURA,
            Company::findOrFail($empresa->id)->etapa,
            'Abrir a ficha precisa observar o envelope enviado e avançar a etapa (D-15).'
        );
    }

    // ─── Helpers dos casos de payload ───────────────────────────────────────

    /** @return array<string, mixed> */
    private function propsDaFicha(User $user, Company $empresa): array
    {
        $response = $this->actingAs($user)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/ContratoDetalhe'));

        return $response->viewData('page')['props'];
    }

    private function empresaComContrato(array $attrs = []): Company
    {
        $empresa = $this->empresa($attrs);

        $servico = Servico::create([
            'nome'           => 'Performance (checklist 152-08)',
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);

        ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'valor_contratado'      => 100,
            'data_contratacao'      => now()->toDateString(),
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento'        => 10,
            'ativo'                 => true,
        ]));

        return $empresa->fresh();
    }

    /** Envelope gravado DIRETO no banco — é o que o webhook do Clicksign faz. */
    private function envelopeEnviado(Company $empresa): ContratoAssinatura
    {
        $servico = $empresa->contratosServico()->first()->servico_id;

        return ContratoAssinatura::create([
            'company_id'  => $empresa->id,
            'servico_id'  => $servico,
            'status'      => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em'  => now(),
            'assinado_em' => null,
        ]);
    }
}
