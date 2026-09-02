<?php

namespace Tests\Feature\Phase138;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 138 Plano 05 (COMERC-02/03, D-06/D-07/D-11/D-12/D-13/D-14) —
 * `ComercialEntradaController::index()`.
 *
 * Cobre: universo das 4 etapas do fluxo de entrada, exclusão de etapa NULL
 * (legado), os 8 campos mínimos do §2 no payload, as duas pendências em
 * chaves separadas (nunca somadas), e a regra de origem que decide qual
 * conjunto de pendências de cadastro a empresa recebe.
 */
class ComercListagemEntradaTest extends TestCase
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

    // ─── Universo: as 4 etapas do fluxo de entrada ──────────────────────────

    public function test_as_quatro_etapas_do_universo_aparecem_na_listagem(): void
    {
        $etapa1 = $this->empresa(['name' => 'Empresa Etapa 1', 'etapa' => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO]);
        $etapa2 = $this->empresa(['name' => 'Empresa Etapa 2', 'etapa' => Company::ETAPA_ADMINISTRATIVO_ANDAMENTO]);
        $etapa3 = $this->empresa(['name' => 'Empresa Etapa 3', 'etapa' => Company::ETAPA_AGUARDANDO_ASSINATURA]);
        $etapa4 = $this->empresa(['name' => 'Empresa Etapa 4', 'etapa' => Company::ETAPA_ADMINISTRATIVO_CONCLUIDO]);

        $response = $this->actingAs($this->admin())->get(route('comercial.entrada.index'));
        $response->assertOk();

        $nomes = collect($response->viewData('page')['props']['companies']['data'])->pluck('name')->all();

        $this->assertContains('Empresa Etapa 1', $nomes);
        $this->assertContains('Empresa Etapa 2', $nomes);
        $this->assertContains('Empresa Etapa 3', $nomes);
        $this->assertContains('Empresa Etapa 4', $nomes);
    }

    /** D-14 — legado com etapa NULL nunca entra na listagem Entrada. */
    public function test_empresa_com_etapa_null_nao_aparece(): void
    {
        $legado = $this->empresa(['name' => 'Empresa Legado Sem Etapa', 'etapa' => null]);

        $response = $this->actingAs($this->admin())->get(route('comercial.entrada.index'));
        $response->assertOk();

        $nomes = collect($response->viewData('page')['props']['companies']['data'])->pluck('name')->all();

        $this->assertNotContains('Empresa Legado Sem Etapa', $nomes);
    }

    // ─── Os 8 campos do §2 ───────────────────────────────────────────────────

    public function test_payload_contem_os_8_campos_minimos_do_2(): void
    {
        $servico = $this->servico('Gestão de Tráfego (Entrada)');
        $empresa = $this->empresa([
            'name'   => 'Empresa Campos Mínimos',
            'etapa'  => Company::ETAPA_ADMINISTRATIVO_ANDAMENTO,
            'hubspot_owner_nome' => 'Fulano de Tal',
            'data_venda'         => '2026-08-15',
        ]);
        $this->vincularServico($empresa, $servico);
        $this->marcarOrigemHubspot($empresa);

        $response = $this->actingAs($this->admin())->get(route('comercial.entrada.index'));
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['companies']['data'])
            ->firstWhere('id', $empresa->id);

        $this->assertNotNull($linha);
        foreach ([
            'id', 'name', 'cnpj', 'servicos', 'setor_dominante', 'origem',
            'hubspot_owner_nome', 'data_venda', 'contrato_badge',
            'pendencia_fluxo', 'pendencias_cadastro', 'etapa',
        ] as $campo) {
            $this->assertArrayHasKey($campo, $linha, "campo '{$campo}' ausente no payload");
        }

        $this->assertSame('Fulano de Tal', $linha['hubspot_owner_nome']);
        $this->assertSame('2026-08-15', $linha['data_venda']);
        $this->assertSame('hubspot', $linha['origem']);
    }

    /** hubspot_owner_nome null é NORMAL (cadastro manual nunca teve deal) — nunca quebra o payload. */
    public function test_hubspot_owner_nome_nulo_nao_quebra_o_payload(): void
    {
        $servico = $this->servico('Assessoria (Entrada owner nulo)');
        $empresa = $this->empresa([
            'name'  => 'Empresa Sem Owner',
            'etapa' => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
            'hubspot_owner_nome' => null,
            'data_venda'         => null,
        ]);
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($this->admin())->get(route('comercial.entrada.index'));
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['companies']['data'])
            ->firstWhere('id', $empresa->id);

        $this->assertNotNull($linha);
        $this->assertArrayHasKey('hubspot_owner_nome', $linha);
        $this->assertNull($linha['hubspot_owner_nome']);
        $this->assertNull($linha['data_venda']);
    }

    // ─── D-11: duas pendências, nunca somadas ───────────────────────────────

    public function test_pendencia_fluxo_e_pendencias_cadastro_sao_chaves_distintas_sem_chave_agregada(): void
    {
        $servico = $this->servico('Gestão de Tráfego (pendências)');
        $empresa = $this->empresa([
            'name'  => 'Empresa Pendências Separadas',
            'etapa' => Company::ETAPA_ADMINISTRATIVO_ANDAMENTO,
        ]);
        $this->vincularServico($empresa, $servico);
        $this->marcarOrigemHubspot($empresa);
        $empresa->declararPendencia('Falta assinar termo interno', $this->admin());

        $response = $this->actingAs($this->admin())->get(route('comercial.entrada.index'));
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['companies']['data'])
            ->firstWhere('id', $empresa->id);

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
        // Empresa manual: nenhum serviço vinculado -> sem_servico é universal.
        $empresa = $this->empresa([
            'name'  => 'Empresa Cadastro Manual',
            'etapa' => Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
        ]);
        // Nenhum marcarOrigemHubspot() — is_origem_hubspot fica false.

        $response = $this->actingAs($this->admin())->get(route('comercial.entrada.index'));
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['companies']['data'])
            ->firstWhere('id', $empresa->id);

        $this->assertSame('manual', $linha['origem']);
        $this->assertNotEmpty($linha['pendencias_cadastro'], 'cadastro manual sem serviço deve receber ao menos a pendência universal sem_servico');
        $this->assertContains('sem_servico', $linha['pendencias_cadastro']);
    }

    // ─── D-12: setor ECF ─────────────────────────────────────────────────────

    public function test_setor_dominante_traz_o_setor_ecf_do_servico_contratado(): void
    {
        $servico = $this->servico('Publicação (Entrada setor)', Servico::SETOR_PUBLICACAO);
        $empresa = $this->empresa([
            'name'  => 'Empresa Setor Publicação',
            'etapa' => Company::ETAPA_AGUARDANDO_ASSINATURA,
        ]);
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($this->admin())->get(route('comercial.entrada.index'));
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['companies']['data'])
            ->firstWhere('id', $empresa->id);

        $this->assertSame(Servico::SETOR_PUBLICACAO, $linha['setor_dominante']);
    }
}
