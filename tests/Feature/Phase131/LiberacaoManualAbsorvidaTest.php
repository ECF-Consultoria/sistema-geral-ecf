<?php

namespace Tests\Feature\Phase131;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoLiberacao;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 131 Plano 06 (D-10) — prova que a absorção de
 * `ContratoLiberacaoManualController::store()` em
 * `ContratoAdminController::liberarManual()` preservou TODAS as mitigações
 * fechadas pelo `130-SECURITY.md` (41/41 ameaças): lista fechada de
 * `motivo_slug`, `motivo_detalhe` obrigatório mesmo com o slug preenchido,
 * `exists:` nos ids, o guard de IDOR (T-130-04-03) e a permissão dedicada
 * `admin.contratos` (T-131-06-04) na rota nova.
 *
 * Mesma disciplina de asserção do resto da fase: toda gravação é conferida
 * por RECONSULTA ao banco, nunca por leitura de stdout.
 */
class LiberacaoManualAbsorvidaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function usuarioSemPermissao(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    private function servico(string $nome = 'Servico Liberacao Absorvida'): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    public function test_motivo_slug_fora_da_lista_fechada_falha_validacao_e_nao_grava_nada(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $servico = $this->servico();

        $response = $this->actingAs($admin)->post(route('admin.contratos.liberacao-manual'), [
            'company_id'     => $company->id,
            'servico_id'     => $servico->id,
            'motivo_slug'    => 'motivo_que_nao_existe',
            'motivo_detalhe' => 'Detalhe qualquer, com mais de cinco caracteres.',
        ]);

        $response->assertSessionHasErrors('motivo_slug');
        $this->assertSame(0, ContratoLiberacao::where('company_id', $company->id)->count());
    }

    public function test_motivo_detalhe_ausente_ou_curto_falha_validacao_mesmo_com_slug_valido(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $servico = $this->servico();

        $semDetalhe = $this->actingAs($admin)->post(route('admin.contratos.liberacao-manual'), [
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'motivo_slug' => ContratoLiberacao::MOTIVO_OUTRO,
        ]);
        $semDetalhe->assertSessionHasErrors('motivo_detalhe');

        $detalheCurto = $this->actingAs($admin)->post(route('admin.contratos.liberacao-manual'), [
            'company_id'     => $company->id,
            'servico_id'     => $servico->id,
            'motivo_slug'    => ContratoLiberacao::MOTIVO_OUTRO,
            'motivo_detalhe' => 'abcd', // 4 caracteres, abaixo do min:5
        ]);
        $detalheCurto->assertSessionHasErrors('motivo_detalhe');

        $this->assertSame(0, ContratoLiberacao::where('company_id', $company->id)->count());
    }

    public function test_company_servico_ou_contrato_inexistentes_falham_validacao(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('admin.contratos.liberacao-manual'), [
            'company_id'             => 999999,
            'servico_id'             => 999999,
            'contrato_assinatura_id' => 999999,
            'motivo_slug'            => ContratoLiberacao::MOTIVO_OUTRO,
            'motivo_detalhe'         => 'Ids inventados que não existem no banco.',
        ]);

        $response->assertSessionHasErrors(['company_id', 'servico_id', 'contrato_assinatura_id']);
        $this->assertSame(0, ContratoLiberacao::count());
    }

    public function test_contrato_de_outra_empresa_ou_servico_falha_com_422_idor(): void
    {
        $admin = $this->admin();

        $companyAlvo = Company::factory()->create(['name' => 'Empresa Alvo Absorvida']);
        $servicoAlvo = $this->servico('Servico Alvo Absorvido');

        $companyOutra = Company::factory()->create(['name' => 'Empresa Outra Absorvida']);
        $servicoOutro = $this->servico('Servico Outro Absorvido');
        $contratoDeOutraEmpresa = ContratoAssinatura::factory()->emAndamento()->create([
            'company_id' => $companyOutra->id,
            'servico_id' => $servicoOutro->id,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.contratos.liberacao-manual'), [
            'company_id'             => $companyAlvo->id,
            'servico_id'             => $servicoAlvo->id,
            'contrato_assinatura_id' => $contratoDeOutraEmpresa->id,
            'motivo_slug'            => ContratoLiberacao::MOTIVO_OUTRO,
            'motivo_detalhe'         => 'Tentativa de amarrar contrato de outra empresa (T-130-04-03).',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ContratoLiberacao::where('company_id', $companyAlvo->id)->count());
    }

    public function test_caminho_feliz_grava_contratoliberacao_com_via_manual_autor_e_motivo_por_reconsulta_ao_banco(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();
        $servico = $this->servico();

        $response = $this->actingAs($admin)->post(route('admin.contratos.liberacao-manual'), [
            'company_id'     => $company->id,
            'servico_id'     => $servico->id,
            'motivo_slug'    => ContratoLiberacao::MOTIVO_DECISAO_COMERCIAL,
            'motivo_detalhe' => 'Decisão comercial registrada por e-mail com o cliente (rota absorvida).',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // RECONSULTA AO BANCO — nunca acreditar no toast.
        $liberacao = ContratoLiberacao::where('company_id', $company->id)->first();

        $this->assertNotNull($liberacao);
        $this->assertSame(ContratoLiberacao::VIA_MANUAL, $liberacao->via);
        $this->assertSame($admin->id, $liberacao->liberado_por_user_id);
        $this->assertSame(ContratoLiberacao::MOTIVO_DECISAO_COMERCIAL, $liberacao->motivo_slug);
        $this->assertSame('Decisão comercial registrada por e-mail com o cliente (rota absorvida).', $liberacao->motivo);
    }

    public function test_usuario_sem_permissao_admin_contratos_recebe_403(): void
    {
        $usuario = $this->usuarioSemPermissao();
        $company = Company::factory()->create();
        $servico = $this->servico();

        $response = $this->actingAs($usuario)->post(route('admin.contratos.liberacao-manual'), [
            'company_id'     => $company->id,
            'servico_id'     => $servico->id,
            'motivo_slug'    => ContratoLiberacao::MOTIVO_OUTRO,
            'motivo_detalhe' => 'Usuário sem a permissão dedicada admin.contratos.',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, ContratoLiberacao::where('company_id', $company->id)->count());
    }
}
