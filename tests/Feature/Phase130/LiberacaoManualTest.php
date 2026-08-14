<?php

namespace Tests\Feature\Phase130;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoLiberacao;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 130 Plano 04 (REDE-03, DADOS-05, D-10/D-11/D-12) —
 * `ContratoLiberacaoManualController` original.
 *
 * ⚠️ Reapontado pelo plano 131-06 (D-10): a ação foi ABSORVIDA por
 * `ContratoAdminController::liberarManual()` e a rota antiga foi removida
 * (ver `LiberacaoManualRotaAntigaRemovidaTest`). O GET que antes ia para a
 * listagem própria da Fase 130 agora vai para o detalhe da empresa
 * (`admin.contratos.show`); o POST vai para `admin.contratos.liberacao-manual`.
 * O comportamento provado aqui — validação, IDOR, idempotência — é o
 * mesmo, só a superfície mudou.
 *
 * Mesma disciplina de asserção do resto da fase: toda gravação é conferida
 * por RECONSULTA ao banco, nunca por leitura de stdout.
 */
class LiberacaoManualTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function naoAdmin(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    private function servico(string $nome = 'Polos (liberacao manual)'): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    /** Contrato "preso" de verdade — aparece em ContratosPresosService::listar(). */
    private function contratoPreso(?Company $company = null, ?Servico $servico = null): ContratoAssinatura
    {
        return ContratoAssinatura::factory()->emAndamento()->create([
            'company_id' => ($company ?? Company::factory()->create())->id,
            'servico_id' => ($servico ?? $this->servico())->id,
            'enviado_em' => now()->subDays(10),
        ]);
    }

    public function test_visitante_anonimo_e_redirecionado_no_get_e_no_post(): void
    {
        $company = Company::factory()->create();

        $getResponse = $this->get(route('admin.contratos.show', $company));
        $getResponse->assertRedirect();
        $this->assertNotSame(200, $getResponse->getStatusCode());

        $postResponse = $this->post(route('admin.contratos.liberacao-manual'), []);
        $postResponse->assertRedirect();
        $this->assertNotSame(200, $postResponse->getStatusCode());
    }

    public function test_usuario_sem_role_admin_recebe_403_no_get_e_no_post(): void
    {
        $usuario = $this->naoAdmin();
        $company = Company::factory()->create();

        $getResponse = $this->actingAs($usuario)->get(route('admin.contratos.show', $company));
        $getResponse->assertForbidden();

        $postResponse = $this->actingAs($usuario)->post(route('admin.contratos.liberacao-manual'), []);
        $postResponse->assertForbidden();
    }

    public function test_admin_no_get_recebe_200_e_a_empresa_presa_na_prop_contratos(): void
    {
        $admin    = $this->admin();
        $company  = Company::factory()->create(['name' => 'Empresa Presa Teste']);
        $contrato = $this->contratoPreso($company);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $company));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/ContratoDetalhe')
            ->where('company.id', $company->id)
            ->has('contratos', 1)
            ->where('contratos.0.id', $contrato->id)
        );
    }

    public function test_post_sem_motivo_detalhe_falha_validacao_e_nao_grava_nada(): void
    {
        $admin    = $this->admin();
        $company  = Company::factory()->create();
        $servico  = $this->servico();

        $response = $this->actingAs($admin)->post(route('admin.contratos.liberacao-manual'), [
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'motivo_slug' => ContratoLiberacao::MOTIVO_OUTRO,
            // motivo_detalhe ausente de propósito
        ]);

        $response->assertSessionHasErrors('motivo_detalhe');
        $this->assertSame(0, ContratoLiberacao::where('company_id', $company->id)->count());
    }

    public function test_post_com_motivo_slug_fora_da_lista_fechada_falha_validacao_e_nao_grava_nada(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $servico = $this->servico();

        $response = $this->actingAs($admin)->post(route('admin.contratos.liberacao-manual'), [
            'company_id'     => $company->id,
            'servico_id'     => $servico->id,
            'motivo_slug'    => 'motivo_inventado_fora_da_lista',
            'motivo_detalhe' => 'Um detalhe qualquer, com mais de cinco caracteres.',
        ]);

        $response->assertSessionHasErrors('motivo_slug');
        $this->assertSame(0, ContratoLiberacao::where('company_id', $company->id)->count());
    }

    public function test_post_valido_grava_via_manual_autor_motivo_e_slug(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $servico = $this->servico();

        $response = $this->actingAs($admin)->post(route('admin.contratos.liberacao-manual'), [
            'company_id'     => $company->id,
            'servico_id'     => $servico->id,
            'motivo_slug'    => ContratoLiberacao::MOTIVO_WEBHOOK_NAO_CHEGOU,
            'motivo_detalhe' => 'O aviso da Clicksign nunca chegou, confirmado no painel deles.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $liberacao = ContratoLiberacao::where('company_id', $company->id)->first();

        $this->assertNotNull($liberacao);
        $this->assertSame(ContratoLiberacao::VIA_MANUAL, $liberacao->via);
        $this->assertSame($admin->id, $liberacao->liberado_por_user_id);
        $this->assertSame('O aviso da Clicksign nunca chegou, confirmado no painel deles.', $liberacao->motivo);
        $this->assertSame(ContratoLiberacao::MOTIVO_WEBHOOK_NAO_CHEGOU, $liberacao->motivo_slug);
    }

    public function test_post_valido_repetido_para_mesma_empresa_e_servico_nao_duplica_liberacao(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $servico = $this->servico();

        $payload = [
            'company_id'     => $company->id,
            'servico_id'     => $servico->id,
            'motivo_slug'    => ContratoLiberacao::MOTIVO_DECISAO_COMERCIAL,
            'motivo_detalhe' => 'Decisão comercial registrada por e-mail com o cliente.',
        ];

        $this->actingAs($admin)->post(route('admin.contratos.liberacao-manual'), $payload)->assertRedirect();
        $this->actingAs($admin)->post(route('admin.contratos.liberacao-manual'), $payload)->assertRedirect();

        $this->assertSame(
            1,
            ContratoLiberacao::where('company_id', $company->id)->where('servico_id', $servico->id)->count(),
            'idempotencia herdada de liberarEmpresa() -- nenhum guard novo deve existir no controller'
        );
    }

    public function test_post_com_contrato_de_outra_empresa_falha_com_422_e_nao_grava_nada(): void
    {
        $admin = $this->admin();

        $companyAlvo  = Company::factory()->create(['name' => 'Empresa Alvo']);
        $servicoAlvo  = $this->servico('Servico Alvo');

        $companyOutra = Company::factory()->create(['name' => 'Empresa Outra']);
        $servicoOutro = $this->servico('Servico Outro');
        $contratoDeOutraEmpresa = ContratoAssinatura::factory()->emAndamento()->create([
            'company_id' => $companyOutra->id,
            'servico_id' => $servicoOutro->id,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.liberacao-manual'), [
            'company_id'             => $companyAlvo->id,
            'servico_id'             => $servicoAlvo->id,
            'contrato_assinatura_id' => $contratoDeOutraEmpresa->id,
            'motivo_slug'            => ContratoLiberacao::MOTIVO_OUTRO,
            'motivo_detalhe'         => 'Tentativa de amarrar contrato de outra empresa.',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ContratoLiberacao::where('company_id', $companyAlvo->id)->count());
    }
}
