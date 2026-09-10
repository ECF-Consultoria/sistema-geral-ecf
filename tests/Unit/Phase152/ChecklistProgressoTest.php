<?php

namespace Tests\Unit\Phase152;

use App\Models\ChecklistAdministrativoItem;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\MlToken;
use App\Models\OnboardingLink;
use App\Models\Servico;
use App\Models\User;
use App\Services\ChecklistAdministrativo\ChecklistAdministrativoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Fase 152 Plano 05 (D-10) — ChecklistAdministrativoService::progresso().
 *
 * Prova que o denominador vem do CATÁLOGO em código, nunca da contagem de
 * linhas em `checklist_administrativo_itens`: uma linha com `chave` órfã
 * não entra no denominador nem no numerador, e a ficha ainda fecha 100%.
 */
class ChecklistProgressoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        // Mesma blindagem de ContratoAdminDetalheTest (Fase 131).
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function servicoIsento(): Servico
    {
        return Servico::create([
            'nome'           => 'Polos (progresso 152-05)',
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_POLOS,
            'exige_contrato' => false,
        ]);
    }

    private function servicoComContrato(): Servico
    {
        return Servico::create([
            'nome'           => 'Gestão de Tráfego (progresso 152-05)',
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    private function empresaCompleta(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge([
            'active'        => true,
            'cnpj'          => '11.222.333/0001-81',
            'email_cliente' => 'cliente@example.com',
            'nome_contato'  => 'Contato de Teste',
            'razao_social'  => 'Contato de Teste LTDA',
            'endereco'      => 'Rua de Teste, 123',
            'bairro'        => 'Bairro de Teste',
            'cidade'        => 'Cidade de Teste',
            'estado'        => 'TS',
            'cep'           => '00000-000',
        ], $overrides));
    }

    private function vincularServico(Company $c, Servico $s, array $overrides = []): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create(array_merge([
            'company_id'            => $c->id,
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento'        => 10,
            'servico_id'            => $s->id,
            'valor_contratado'      => 100,
            'data_contratacao'      => now()->toDateString(),
            'ativo'                 => true,
        ], $overrides)));
    }

    private function service(): ChecklistAdministrativoService
    {
        return app(ChecklistAdministrativoService::class);
    }

    /** Fecha os 2 itens automáticos do grupo Entrada (grant OAuth ML + conexão ECF). */
    private function fecharAutomaticosDeEntrada(Company $company): void
    {
        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '111222333',
            'access_token'      => 'fake-token',
            'refresh_token'     => 'fake-refresh',
            'token_type'        => 'bearer',
            'scope'             => 'read offline_access',
            'expires_at'        => now()->addHour(),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        OnboardingLink::create(['company_id' => $company->id, 'token' => 'token-progresso-152-05']);
    }

    /** Fecha os 4 itens manuais do grupo Entrada, com autoria. */
    private function fecharManuaisDeEntrada(Company $company, User $usuario): void
    {
        foreach (['grupo_whatsapp_criado', 'email_colaborador_criado', 'link_adman_entregue', 'boas_vindas_enviada'] as $chave) {
            $this->service()->concluirManualmente($company, $chave, $usuario);
        }
    }

    // ─── Caso 1 — empresa isenta, 0 concluídos ──────────────────────────

    public function test_empresa_isenta_com_zero_concluidos_devolve_total_6_feitos_0_percentual_0(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoIsento());

        $progresso = $this->service()->progresso($empresa->fresh());

        $this->assertSame(6, $progresso['total']);
        $this->assertSame(0, $progresso['feitos']);
        $this->assertSame(0, $progresso['percentual']);
    }

    // ─── Caso 2 — empresa isenta, todos os 6 concluídos ─────────────────

    public function test_empresa_isenta_com_todos_os_6_concluidos_devolve_percentual_100(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoIsento());
        $usuario = User::factory()->create(['role' => 'admin']);

        $this->fecharAutomaticosDeEntrada($empresa);
        $this->fecharManuaisDeEntrada($empresa, $usuario);

        $progresso = $this->service()->progresso($empresa->fresh());

        $this->assertSame(6, $progresso['total']);
        $this->assertSame(6, $progresso['feitos']);
        $this->assertSame(100, $progresso['percentual']);
    }

    // ─── Caso 3 — empresa com contrato, 0 concluídos: total = 9 ─────────

    public function test_empresa_com_contrato_e_zero_concluidos_devolve_total_9(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoComContrato());

        $progresso = $this->service()->progresso($empresa->fresh());

        $this->assertSame(9, $progresso['total']);
        $this->assertSame(0, $progresso['feitos']);
    }

    // ─── Caso 4 — chave órfã não entra no denominador nem no numerador (D-10) ──

    public function test_chave_orfa_nao_entra_no_denominador_nem_no_numerador_e_ficha_ainda_fecha_100(): void
    {
        $empresa = $this->empresaCompleta();
        $this->vincularServico($empresa, $this->servicoIsento());
        $usuario = User::factory()->create(['role' => 'admin']);

        // Linha órfã — chave que não existe (mais) no catálogo.
        ChecklistAdministrativoItem::create([
            'company_id' => $empresa->id,
            'chave'      => 'item_que_saiu_do_catalogo',
            'status'     => ChecklistAdministrativoItem::STATUS_CONCLUIDO,
        ]);

        $progressoAntes = $this->service()->progresso($empresa->fresh());

        $this->assertSame(6, $progressoAntes['total']);
        $this->assertSame(0, $progressoAntes['feitos']);

        $this->fecharAutomaticosDeEntrada($empresa);
        $this->fecharManuaisDeEntrada($empresa, $usuario);

        $progressoDepois = $this->service()->progresso($empresa->fresh());

        $this->assertSame(6, $progressoDepois['total']);
        $this->assertSame(6, $progressoDepois['feitos']);
        $this->assertSame(100, $progressoDepois['percentual']);
    }

    // ─── Caso 5 — total === 0 nunca produz divisão por zero ─────────────

    public function test_total_zero_nunca_produz_divisao_por_zero(): void
    {
        // A montagem real (paraEmpresa()/progresso()) nunca produz total=0
        // — D-07 sempre deixa ao menos os 6 itens do grupo Entrada no
        // catálogo. O que se prova aqui é a guarda da FÓRMULA em si,
        // chamando o método privado que soma o progresso com um catálogo
        // vazio hipotético (reflection — não há como montar essa condição
        // pela API pública, de propósito).
        $service = $this->service();

        $metodo = new ReflectionMethod($service, 'calcularProgresso');
        $metodo->setAccessible(true);

        $resultado = $metodo->invoke($service, [], 0);

        $this->assertSame(['feitos' => 0, 'total' => 0, 'percentual' => 0], $resultado);
    }
}
