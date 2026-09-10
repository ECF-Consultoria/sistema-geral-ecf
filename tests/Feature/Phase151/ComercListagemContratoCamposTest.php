<?php

namespace Tests\Feature\Phase151;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 151 Plano 06 (COMERC-02, D-06/D-11/D-12) —
 * `ContratoAdminController::index()` ganha os 8 campos mínimos do §2 + etapa.
 *
 * A listagem Contrato NÃO ganha corte por etapa (D-07 vale só para a
 * listagem Entrada) — este arquivo nunca testa ausência de linha por etapa,
 * só o SHAPE do payload dos dois ramos (com contrato e SEM_CONTRATO).
 *
 * Mesma disciplina do resto da fase: conferência pelas props Inertia
 * devolvidas na resposta, nunca por stdout.
 */
class ComercListagemContratoCamposTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servico(string $nome, string $setor = Servico::SETOR_PERFORMANCE): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => $setor,
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

    /** Marca a empresa como origem HubSpot criando o HubspotEvento que aponta pra ela. */
    private function marcarOrigemHubspot(Company $c): HubspotEvento
    {
        return HubspotEvento::create([
            'signature_valid'   => true,
            'portal_id'         => 12345,
            'object_type'       => 'DEAL',
            'object_id'         => random_int(1000, 99999),
            'subscription_type' => 'deal.propertyChange',
            'property_name'     => 'dealstage',
            'property_value'    => 'closedwon',
            'payload'           => [],
            'status'            => 'processado',
            'company_id_criada' => $c->id,
            'processado_em'     => now(),
        ]);
    }

    private const CAMPOS_NOVOS = [
        'company_cnpj', 'setor_dominante', 'origem', 'hubspot_owner_nome',
        'data_venda', 'email_cliente', 'telefone', 'nome_contato',
        'pendencia_fluxo', 'pendencias_cadastro', 'etapa',
    ];

    // ─── Os 8 campos do §2 (ramo COM contrato) ──────────────────────────────

    public function test_ramo_com_contrato_traz_os_campos_novos_do_2(): void
    {
        $servico = $this->servico('Gestão de Tráfego (Contrato campos)');
        $empresa = $this->empresa([
            'name'               => 'Empresa Contrato Campos Mínimos',
            'etapa'              => Company::ETAPA_ADMINISTRATIVO_ANDAMENTO,
            'hubspot_owner_nome' => 'Fulano de Tal',
            'data_venda'         => '2026-08-15',
            'email_cliente'      => 'cliente@example.com',
            'telefone'           => '11999998888',
            'nome_contato'       => 'Ciclano da Silva',
        ]);
        $this->vincularServico($empresa, $servico);
        $this->marcarOrigemHubspot($empresa);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
            'enviado_em' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['linhas']['data'])
            ->firstWhere('company_id', $empresa->id);

        $this->assertNotNull($linha);
        foreach (self::CAMPOS_NOVOS as $campo) {
            $this->assertArrayHasKey($campo, $linha, "campo '{$campo}' ausente no ramo COM contrato");
        }

        $this->assertSame($empresa->cnpj, $linha['company_cnpj']);
        $this->assertSame('Fulano de Tal', $linha['hubspot_owner_nome']);
        $this->assertSame('2026-08-15', $linha['data_venda']);
        $this->assertSame('hubspot', $linha['origem']);
        $this->assertSame('cliente@example.com', $linha['email_cliente']);
        $this->assertSame('11999998888', $linha['telefone']);
        $this->assertSame('Ciclano da Silva', $linha['nome_contato']);
        $this->assertSame(Company::ETAPA_ADMINISTRATIVO_ANDAMENTO, $linha['etapa']);
        $this->assertSame(Servico::SETOR_PERFORMANCE, $linha['setor_dominante']);
    }

    // ─── O ramo SEM_CONTRATO carrega EXATAMENTE as mesmas chaves ────────────

    public function test_ramo_sem_contrato_traz_as_mesmas_chaves_novas_do_ramo_com_contrato(): void
    {
        $servico = $this->servico('Gestão de Tráfego (Contrato sem contrato ainda)');
        $empresa = $this->empresa([
            'name'  => 'Empresa Sem Contrato Ainda Campos',
            'etapa' => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
        ]);
        $this->vincularServico($empresa, $servico);
        // Nenhum ContratoAssinatura criado — força o ramo SEM_CONTRATO.

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['linhas']['data'])
            ->firstWhere('company_id', $empresa->id);

        $this->assertNotNull($linha);
        $this->assertNull($linha['contrato_id'], 'fixture inválida: precisa cair no ramo SEM_CONTRATO');
        foreach (self::CAMPOS_NOVOS as $campo) {
            $this->assertArrayHasKey($campo, $linha, "campo '{$campo}' ausente no ramo SEM_CONTRATO — undefined no front");
        }
    }

    // ─── hubspot_owner_nome nulo é NORMAL, nunca quebra a resposta ──────────

    public function test_hubspot_owner_nome_nulo_nao_quebra_o_payload(): void
    {
        $servico = $this->servico('Assessoria (Contrato owner nulo)');
        $empresa = $this->empresa([
            'name'               => 'Empresa Contrato Sem Owner',
            'hubspot_owner_nome' => null,
            'data_venda'         => null,
        ]);
        $this->vincularServico($empresa, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['linhas']['data'])
            ->firstWhere('company_id', $empresa->id);

        $this->assertNotNull($linha);
        $this->assertArrayHasKey('hubspot_owner_nome', $linha);
        $this->assertNull($linha['hubspot_owner_nome']);
        $this->assertNull($linha['data_venda']);
    }

    // ─── D-11: duas pendências, chaves separadas, nunca somadas ─────────────

    public function test_pendencia_fluxo_e_pendencias_cadastro_sao_chaves_distintas_sem_chave_agregada(): void
    {
        $servico = $this->servico('Gestão de Tráfego (Contrato pendências)');
        $empresa = $this->empresa(['name' => 'Empresa Contrato Pendências Separadas']);
        $this->vincularServico($empresa, $servico);
        $this->marcarOrigemHubspot($empresa);
        $empresa->declararPendencia('Falta assinar termo interno', $this->admin());
        ContratoAssinatura::factory()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['linhas']['data'])
            ->firstWhere('company_id', $empresa->id);

        $this->assertArrayHasKey('pendencia_fluxo', $linha);
        $this->assertArrayHasKey('pendencias_cadastro', $linha);
        $this->assertArrayNotHasKey('pendencias', $linha, 'não pode existir chave agregada "pendencias" no singular');

        $this->assertTrue($linha['pendencia_fluxo']['aberta']);
        $this->assertSame('Falta assinar termo interno', $linha['pendencia_fluxo']['motivo']);
        $this->assertIsArray($linha['pendencias_cadastro']);
    }

    /**
     * Empresa de cadastro manual (sem HubspotEvento apontando pra ela) recebe
     * as pendências UNIVERSAIS de PendenciasComerciaisService::calcularUniversais(),
     * nunca um array vazio por desenho do calcular() hubspot-only (REQ-37-10).
     */
    public function test_empresa_cadastro_manual_recebe_pendencias_universais_nao_array_vazio(): void
    {
        $servico = $this->servico('Gestão de Tráfego (Contrato cadastro manual)');
        $empresa = $this->empresa(['name' => 'Empresa Contrato Cadastro Manual', 'nome_contato' => null]);
        $this->vincularServico($empresa, $servico);
        // Nenhum marcarOrigemHubspot() — is_origem_hubspot fica false.

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['linhas']['data'])
            ->firstWhere('company_id', $empresa->id);

        $this->assertSame('manual', $linha['origem']);
        $this->assertNotEmpty(
            $linha['pendencias_cadastro'],
            'cadastro manual sem contato deve receber ao menos a pendência universal sem_contato'
        );
        $this->assertContains('sem_contato', $linha['pendencias_cadastro']);
    }

    // ─── O resumo continua com exatamente 7 chaves ──────────────────────────

    public function test_resumo_continua_com_exatamente_7_chaves(): void
    {
        $servico = $this->servico('Gestão de Tráfego (Contrato resumo)');
        $empresa = $this->empresa(['name' => 'Empresa Contrato Resumo']);
        $this->vincularServico($empresa, $servico);
        ContratoAssinatura::factory()->create([
            'company_id' => $empresa->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(7, $props['resumo']);
        $this->assertSame(ContratoAssinatura::STATUS_TODOS, array_keys($props['resumo']));
    }

    // ─── O universo da listagem não muda (mesma contagem de linhas) ─────────

    public function test_universo_da_listagem_permanece_o_mesmo_de_antes_desta_task(): void
    {
        $servicoUm   = $this->servico('Gestão de Tráfego (Contrato universo)');
        $servicoDois = $this->servico('Assessoria (Contrato universo)');
        $polos       = Servico::create([
            'nome'           => 'Polos (Contrato universo, isento)',
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_OUTROS,
            'exige_contrato' => false,
        ]);

        $comDoisServicos = $this->empresa(['name' => 'Empresa Universo Dois Serviços']);
        $this->vincularServico($comDoisServicos, $servicoUm);
        $this->vincularServico($comDoisServicos, $servicoDois);

        $soIsento = $this->empresa(['name' => 'Empresa Universo Só Isento']);
        $this->vincularServico($soIsento, $polos);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        // Empresa com dois serviços que exigem contrato -> 2 linhas;
        // empresa só com serviço isento -> 0 linhas (D9). Total = 2.
        $this->assertSame(2, $props['linhas']['total'], 'a query do universo não pode mudar neste plano');
    }

    // ─── Nenhum dado de signatário atravessa para o browser ─────────────────

    public function test_nenhuma_linha_carrega_dado_de_signatario_apos_os_campos_novos(): void
    {
        $servico = $this->servico('Gestão de Tráfego (Contrato sem signatário)');
        $empresa = $this->empresa(['name' => 'Empresa Contrato Sem Signatário']);
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
            $this->assertArrayNotHasKey('signatarios', $linha);
            $this->assertArrayNotHasKey('cpf', $linha);
        }
    }
}
