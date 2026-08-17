<?php

namespace Tests\Feature\Phase128;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\Servico;
use App\Services\Comercial\PendenciasComerciaisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plano 128-02 (D-01): prova que `calcularUniversais()` enxerga as 4
 * pendências universais (`sem_servico`, `sem_valor`, `sem_setor`,
 * `sem_contato`) para QUALQUER empresa — inclusive cadastro manual, sem
 * `HubspotEvento` — sem que a listagem do Comercial (`calcular()`) mude
 * uma linha de comportamento.
 *
 * O caso mais importante é a regressão da D-01 (último teste desta suíte):
 * a MESMA empresa não-HubSpot que `calcularUniversais()` marca como
 * pendente continua devolvendo `calcular() === []` — o gate administrativo
 * enxerga, a listagem do Comercial não.
 */
class PendenciasUniversaisTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PendenciasComerciaisService
    {
        return new PendenciasComerciaisService();
    }

    private function criarServico(string $nome, string $setor = Servico::SETOR_OUTROS): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 100,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => $setor,
        ]);
    }

    /**
     * Empresa de cadastro manual: nenhum HubspotEvento aponta pra ela, logo
     * `is_origem_hubspot` nunca é setado (fica null) e `calcular()` cai no
     * early-return de sempre — exatamente o comportamento de hoje.
     */
    private function criarEmpresaManual(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'         => 'Empresa Manual P128-02 ' . uniqid(),
            'cnpj'         => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'       => true,
            'status'       => 'ativo',
            'nome_contato' => 'Fulano de Tal',
        ], $overrides));
    }

    private function criarContrato(Company $c, Servico $s, array $overrides = []): ContratoServico
    {
        return ContratoServico::create(array_merge([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ], $overrides));
    }

    #[Test]
    public function empresa_manual_sem_contrato_ativo_calcula_sem_servico(): void
    {
        $empresa = $this->criarEmpresaManual();

        $universais = $this->service()->calcularUniversais($empresa);

        $this->assertSame(['sem_servico'], $universais);
    }

    #[Test]
    public function empresa_manual_com_contrato_valor_zero_calcula_sem_valor(): void
    {
        $empresa = $this->criarEmpresaManual();
        $servico = $this->criarServico('Serviço P128-02 A', Servico::SETOR_PERFORMANCE);
        $this->criarContrato($empresa, $servico, ['valor_contratado' => 0]);

        $universais = $this->service()->calcularUniversais($empresa->fresh());

        $this->assertContains('sem_valor', $universais);
    }

    #[Test]
    public function empresa_manual_com_todos_contratos_setor_outros_calcula_sem_setor(): void
    {
        $empresa = $this->criarEmpresaManual();
        $servico = $this->criarServico('Serviço P128-02 B', Servico::SETOR_OUTROS);
        $this->criarContrato($empresa, $servico);

        $universais = $this->service()->calcularUniversais($empresa->fresh());

        $this->assertContains('sem_setor', $universais);
    }

    #[Test]
    public function empresa_manual_sem_nome_contato_calcula_sem_contato(): void
    {
        $empresa = $this->criarEmpresaManual(['nome_contato' => null]);

        $universais = $this->service()->calcularUniversais($empresa);

        $this->assertContains('sem_contato', $universais);
    }

    #[Test]
    public function empresa_manual_tudo_preenchido_nao_gera_pendencia_universal(): void
    {
        $empresa = $this->criarEmpresaManual();
        $servico = $this->criarServico('Serviço P128-02 C', Servico::SETOR_PERFORMANCE);
        $this->criarContrato($empresa, $servico, ['valor_contratado' => 500]);

        $universais = $this->service()->calcularUniversais($empresa->fresh());

        $this->assertSame([], $universais);
    }

    #[Test]
    public function calcular_universais_nunca_retorna_pendencias_hubspot_only(): void
    {
        $empresa = $this->criarEmpresaManual(['nome_contato' => null]);
        $servico = $this->criarServico('Serviço P128-02 D', Servico::SETOR_OUTROS);
        $this->criarContrato($empresa, $servico, [
            'valor_contratado'        => 0,
            'hubspot_valor_confidence' => 'low',
        ]);

        // Injeta os 3 sinais hubspot-only que `calcular()` reconheceria
        // (HubspotEvento com line_items_nao_mapeados + snapshot com
        // possivel_duplicidade). `is_origem_hubspot` é atributo dinâmico
        // setado só pela query do controller (não é coluna) — irrelevante
        // aqui, `calcularUniversais()` nunca o consulta.
        HubspotEvento::create([
            'signature_valid'   => true,
            'portal_id'         => 12345,
            'object_type'       => 'DEAL',
            'object_id'         => random_int(1000, 99999),
            'subscription_type' => 'deal.propertyChange',
            'property_name'     => 'dealstage',
            'property_value'    => 'closedwon',
            'payload'           => ['line_items_nao_mapeados' => [['name' => 'Produto Desconhecido ' . uniqid()]]],
            'status'            => 'processado',
            'company_id_criada' => $empresa->id,
            'processado_em'     => now(),
        ]);
        $empresa->hubspot_snapshot = ['warnings' => [['tipo' => 'possivel_duplicidade']]];
        $empresa->save();

        $universais = $this->service()->calcularUniversais($empresa->fresh());

        $this->assertNotContains('servico_nao_reconhecido', $universais);
        $this->assertNotContains('valor_revisar', $universais);
        $this->assertNotContains('possivel_duplicidade', $universais);
        // As universais continuam presentes — a exclusão é só das hubspot-only.
        $this->assertContains('sem_valor', $universais);
        $this->assertContains('sem_setor', $universais);
        $this->assertContains('sem_contato', $universais);
    }

    #[Test]
    public function regressao_d01_mesma_empresa_pendente_no_gate_continua_invisivel_na_listagem(): void
    {
        // Empresa de cadastro manual (SEM HubspotEvento) com pendências
        // universais reais: sem contrato ativo E sem contato.
        $empresa = $this->criarEmpresaManual(['nome_contato' => null]);

        // O gate administrativo (calcularUniversais) enxerga a pendência.
        $universais = $this->service()->calcularUniversais($empresa->fresh());
        $this->assertNotEmpty($universais, 'esperava calcularUniversais() detectar pendências nesta empresa manual');
        $this->assertContains('sem_servico', $universais);
        $this->assertContains('sem_contato', $universais);

        // A listagem do Comercial (calcular) continua cega — comportamento
        // de hoje preservado byte-a-byte para empresa não-HubSpot.
        $listagem = $this->service()->calcular($empresa->fresh());
        $this->assertSame([], $listagem);
    }
}
