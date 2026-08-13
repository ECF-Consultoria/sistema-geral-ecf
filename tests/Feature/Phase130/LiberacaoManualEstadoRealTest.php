<?php

namespace Tests\Feature\Phase130;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoLiberacao;
use App\Models\Servico;
use App\Models\User;
use App\Services\Contratos\ContratosPresosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 130 Plano 04 (D-11) — prova que a tela recebe o dado necessário para
 * mostrar o estado real do contrato (recusado/erro/etc.) em destaque ANTES
 * de confirmar, e que a liberação manual sucede mesmo nesses estados —
 * exatamente o cenário para o qual ela foi criada.
 */
class LiberacaoManualEstadoRealTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servico(string $nome = 'Assessoria (estado real)'): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    public function test_get_com_contrato_recusado_traz_status_e_causa_certos(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['name' => 'Empresa Recusada']);
        $servico = $this->servico();

        $contrato = ContratoAssinatura::factory()->create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_RECUSADO,
        ]);
        // dataBase() de um estado "default" (recusado/expirado/cancelado/erro)
        // é `updated_at` — envelhece via forceFill()+save() para que o
        // contrato passe no gatilho de limiar e apareça em listar(). Não é
        // fillable de propósito (T-125-01); forceFill() bypassa isso.
        $contrato->forceFill(['updated_at' => now()->subDays(10)])->save();

        $response = $this->actingAs($admin)->get(route('contratos.liberacao-manual.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/ContratosLiberacaoManual')
            ->where('contratos.0.id', $contrato->id)
            ->where('contratos.0.status', ContratoAssinatura::STATUS_RECUSADO)
            ->where('contratos.0.causa', ContratosPresosService::CAUSA_RECUSADO)
        );
    }

    public function test_get_com_contrato_em_erro_distingue_de_recusado(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['name' => 'Empresa Erro Tecnico']);
        $servico = $this->servico();

        $contrato = ContratoAssinatura::factory()->create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_ERRO,
        ]);
        $contrato->forceFill(['updated_at' => now()->subDays(10)])->save();

        $response = $this->actingAs($admin)->get(route('contratos.liberacao-manual.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/ContratosLiberacaoManual')
            ->where('contratos.0.id', $contrato->id)
            ->where('contratos.0.status', ContratoAssinatura::STATUS_ERRO)
            ->where('contratos.0.causa', ContratosPresosService::CAUSA_ERRO)
        );
    }

    public function test_prop_motivos_traz_as_quatro_chaves_com_rotulos_nao_vazios(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('contratos.liberacao-manual.index'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $page->component('Admin/ContratosLiberacaoManual');

            $motivos = $page->toArray()['props']['motivos'];

            foreach (ContratoLiberacao::MOTIVOS_MANUAIS as $slug) {
                $this->assertArrayHasKey($slug, $motivos);
                $this->assertNotEmpty($motivos[$slug]);
            }
        });
    }

    public function test_liberacao_manual_de_contrato_recusado_sucede_ignorando_o_gate(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create(['name' => 'Empresa Recusada Libera']);
        $servico = $this->servico();

        $contrato = ContratoAssinatura::factory()->create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_RECUSADO,
        ]);

        $response = $this->actingAs($admin)->post(route('contratos.liberacao-manual.store'), [
            'company_id'             => $company->id,
            'servico_id'             => $servico->id,
            'contrato_assinatura_id' => $contrato->id,
            'motivo_slug'            => ContratoLiberacao::MOTIVO_DECISAO_COMERCIAL,
            'motivo_detalhe'         => 'Cliente recusou na Clicksign mas fechamos por fora, decisão comercial.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $company->id)->where('via', ContratoLiberacao::VIA_MANUAL)->count(),
            'D-11: liberacao manual tem que suceder mesmo com o contrato recusado, ignorando o gate automatico'
        );
    }

    public function test_nenhum_prop_de_contrato_expoe_dado_de_signatario(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $servico = $this->servico();

        ContratoAssinatura::factory()->emAndamento()->create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'enviado_em' => now()->subDays(10),
        ]);

        $response = $this->actingAs($admin)->get(route('contratos.liberacao-manual.index'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $primeiroContrato = $page->toArray()['props']['contratos'][0];

            $chavesEsperadas = [
                'id', 'company_id', 'company_nome', 'servico_id', 'servico_nome',
                'status', 'causa', 'dias_parado', 'enviado_em', 'assinado_em',
            ];

            $this->assertEqualsCanonicalizing($chavesEsperadas, array_keys($primeiroContrato));
        });
    }
}
