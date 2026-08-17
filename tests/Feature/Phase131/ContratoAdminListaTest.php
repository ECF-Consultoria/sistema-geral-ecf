<?php

namespace Tests\Feature\Phase131;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 131 Plano 03 (UI-01/D-04) — ContratoAdminController::index().
 *
 * Nasce na Task 1 (200 + componente, resumo com 7 chaves, sem_contrato_count
 * fora dele — o núcleo que a Task 1 entrega) e é COMPLETADO na Task 3
 * (filtros, busca, ordenação, ausência de dado de signatário), no MESMO
 * arquivo — regra do "teste nasce na mesma task do código que ele prova"
 * (armadilha do `--filter` sem match que sai 0 e varre a suíte).
 *
 * Mesma disciplina do resto da fase: conferência por RECONSULTA às props
 * Inertia + banco, nunca por stdout.
 */
class ContratoAdminListaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (lista admin)'): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    private function empresa(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge(['active' => true], $overrides));
    }

    private function vincularServico(Company $c, Servico $s): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);
    }

    // ─── Task 1: o núcleo — 200 + componente, resumo de 7 chaves ──────────

    public function test_admin_recebe_200_e_o_componente_admin_contratos(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Contratos'));
    }

    public function test_resumo_tem_exatamente_7_chaves_iguais_a_status_todos(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertIsArray($props['resumo']);
        $this->assertCount(7, $props['resumo']);
        $this->assertSame(
            ContratoAssinatura::STATUS_TODOS,
            array_keys($props['resumo']),
            'O resumo deve ter EXATAMENTE as 7 chaves de STATUS_TODOS, na mesma ordem.'
        );
    }

    public function test_sem_contrato_count_existe_e_fica_fora_do_resumo(): void
    {
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Sem Contrato Ainda']);
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertArrayHasKey('sem_contrato_count', $props);
        $this->assertIsInt($props['sem_contrato_count']);
        $this->assertGreaterThanOrEqual(1, $props['sem_contrato_count']);
        $this->assertArrayNotHasKey('aguardando_administrativo', $props['resumo']);
    }

    // ─── Task 3: contagens, filtros, busca, ordenação, ausência de dado ───
    // de signatário — completa este arquivo sem reescrever os casos acima.

    public function test_as_contagens_do_resumo_batem_com_os_contratos_criados(): void
    {
        $servico = $this->servicoComContrato();

        $empresaAguardando = $this->empresa(['name' => 'Empresa Resumo Aguardando']);
        $this->vincularServico($empresaAguardando, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresaAguardando->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDays(2),
        ]);

        $empresaAssinado = $this->empresa(['name' => 'Empresa Resumo Assinado']);
        $this->vincularServico($empresaAssinado, $servico);
        ContratoAssinatura::factory()->assinado()->create([
            'company_id' => $empresaAssinado->id,
            'servico_id' => $servico->id,
        ]);

        $empresaRecusado = $this->empresa(['name' => 'Empresa Resumo Recusado']);
        $this->vincularServico($empresaRecusado, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresaRecusado->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_RECUSADO,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        // Reconsulta ao banco — nunca confia só na resposta HTTP.
        $this->assertSame(
            ContratoAssinatura::where('status', ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS)->count(),
            $props['resumo'][ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS]
        );
        $this->assertSame(
            ContratoAssinatura::where('status', ContratoAssinatura::STATUS_ASSINADO)->count(),
            $props['resumo'][ContratoAssinatura::STATUS_ASSINADO]
        );
        $this->assertSame(
            ContratoAssinatura::where('status', ContratoAssinatura::STATUS_RECUSADO)->count(),
            $props['resumo'][ContratoAssinatura::STATUS_RECUSADO]
        );
    }

    public function test_as_contagens_do_resumo_nao_mudam_quando_o_filtro_de_situacao_e_aplicado(): void
    {
        $servico = $this->servicoComContrato();

        $empresaAguardando = $this->empresa(['name' => 'Empresa Resumo Fixo Aguardando']);
        $this->vincularServico($empresaAguardando, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresaAguardando->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDay(),
        ]);

        $empresaAssinado = $this->empresa(['name' => 'Empresa Resumo Fixo Assinado']);
        $this->vincularServico($empresaAssinado, $servico);
        ContratoAssinatura::factory()->assinado()->create([
            'company_id' => $empresaAssinado->id,
            'servico_id' => $servico->id,
        ]);

        $semFiltro = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $comFiltro = $this->actingAs($this->admin())->get(route('admin.contratos.index', [
            'situacao' => ContratoAssinatura::STATUS_ASSINADO,
        ]));

        $resumoSemFiltro = $semFiltro->viewData('page')['props']['resumo'];
        $resumoComFiltro = $comFiltro->viewData('page')['props']['resumo'];

        $this->assertSame(
            $resumoSemFiltro,
            $resumoComFiltro,
            'O resumo é contagem ABSOLUTA — não pode mudar quando o filtro de situação é aplicado.'
        );
    }

    public function test_filtro_de_situacao_devolve_apenas_as_linhas_daquele_estado(): void
    {
        $servico = $this->servicoComContrato();

        $empresaAguardando = $this->empresa(['name' => 'Empresa Filtro Aguardando']);
        $this->vincularServico($empresaAguardando, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresaAguardando->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDay(),
        ]);

        $empresaAssinado = $this->empresa(['name' => 'Empresa Filtro Assinado']);
        $this->vincularServico($empresaAssinado, $servico);
        ContratoAssinatura::factory()->assinado()->create([
            'company_id' => $empresaAssinado->id,
            'servico_id' => $servico->id,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index', [
            'situacao' => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
        ]));

        $response->assertOk();
        $linhas = $response->viewData('page')['props']['linhas']['data'];

        $this->assertNotEmpty($linhas);
        foreach ($linhas as $linha) {
            $this->assertSame(ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS, $linha['status']);
        }
    }

    public function test_situacao_fora_da_whitelist_e_ignorada_e_devolve_a_lista_completa(): void
    {
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Whitelist']);
        $this->vincularServico($empresa, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
        ]);

        $semFiltro    = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $filtroInvalido = $this->actingAs($this->admin())->get(route('admin.contratos.index', [
            'situacao' => 'valor_invalido_fora_da_lista',
        ]));

        $semFiltro->assertOk();
        $filtroInvalido->assertOk();

        $this->assertSame(
            $semFiltro->viewData('page')['props']['linhas']['total'],
            $filtroInvalido->viewData('page')['props']['linhas']['total'],
            'situacao fora da whitelist deve virar null e devolver a lista completa, igual a nenhum filtro.'
        );
    }

    public function test_busca_por_q_filtra_por_nome_da_empresa(): void
    {
        $servico = $this->servicoComContrato();

        $empresaAlvo = $this->empresa(['name' => 'Empresa Busca Alvo Único']);
        $this->vincularServico($empresaAlvo, $servico);

        $empresaOutra = $this->empresa(['name' => 'Empresa Totalmente Diferente']);
        $this->vincularServico($empresaOutra, $servico);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index', [
            'q' => 'Busca Alvo Único',
        ]));

        $response->assertOk();
        $nomes = collect($response->viewData('page')['props']['linhas']['data'])->pluck('company_nome')->unique()->values()->all();

        $this->assertSame(['Empresa Busca Alvo Único'], $nomes);
    }

    public function test_ordenacao_padrao_traz_primeiro_a_linha_com_maior_dias_parado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00'));

        $servico = $this->servicoComContrato();

        $empresaRecente = $this->empresa(['name' => 'Empresa Parada Recente']);
        $this->vincularServico($empresaRecente, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresaRecente->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDay(),
        ]);

        $empresaAntiga = $this->empresa(['name' => 'Empresa Parada Há Muito Tempo']);
        $this->vincularServico($empresaAntiga, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresaAntiga->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDays(30),
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();
        $linhas = $response->viewData('page')['props']['linhas']['data'];

        $this->assertSame('Empresa Parada Há Muito Tempo', $linhas[0]['company_nome']);
    }

    public function test_nenhuma_linha_carrega_nome_email_ou_cpf_de_signatario(): void
    {
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Sem Dado De Signatário']);
        $this->vincularServico($empresa, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();
        $linhas = $response->viewData('page')['props']['linhas']['data'];

        $this->assertNotEmpty($linhas);
        foreach ($linhas as $linha) {
            $this->assertArrayNotHasKey('nome', $linha);
            $this->assertArrayNotHasKey('email', $linha);
            $this->assertArrayNotHasKey('cpf', $linha);
            $this->assertArrayNotHasKey('signatarios', $linha);
        }
    }
}
