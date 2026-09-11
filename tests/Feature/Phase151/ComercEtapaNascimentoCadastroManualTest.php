<?php

namespace Tests\Feature\Phase151;

use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite Feature — Fase 151 Plano 07 (COMERC-01/D-13).
 *
 * Prova que `ComercialController::store()` — a SEGUNDA porta de nascimento —
 * também faz a empresa nascer na etapa `aguardando_administrativo`, pelo
 * MESMO `EtapaTransicaoService`, com o usuário da sessão como ator:
 *
 *  1. `POST /comercial/empresas`, autenticado, cria a empresa com
 *     `etapa = aguardando_administrativo`.
 *  2. A linha em `company_etapa_transicoes` tem `user_id` igual ao do
 *     usuário logado — nunca o da conta de sistema.
 *  3. A empresa criada assim aparece na listagem Entrada
 *     (`GET /comercial/entrada`).
 *  4. A resposta continua sendo o `back()->with('success', ...)` de sempre.
 */
class ComercEtapaNascimentoCadastroManualTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Phase151-07 ' . uniqid(),
            'email'    => 'admin.p138-07.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    private function servico(): Servico
    {
        return Servico::create([
            'nome'          => 'Mentoria Avançada',
            'valor_padrao'  => 999.99,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'nome'     => 'Empresa Cadastro Manual P138-07 ' . uniqid(),
            'servicos' => [
                ['servico_id' => $this->servico()->id],
            ],
        ], $overrides);
    }

    public function test_post_cria_empresa_ja_na_etapa_1(): void
    {
        $this->actingAsAdmin();
        $payload = $this->payload();

        $response = $this->post(route('comercial.empresas.store'), $payload);
        $response->assertSessionHasNoErrors();

        $company = Company::where('name', $payload['nome'])->first();
        $this->assertNotNull($company);
        $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $company->etapa);
    }

    public function test_historico_tem_user_id_do_usuario_logado_nao_da_conta_de_sistema(): void
    {
        $admin  = $this->actingAsAdmin();
        $payload = $this->payload();

        $this->post(route('comercial.empresas.store'), $payload)->assertSessionHasNoErrors();

        $company   = Company::where('name', $payload['nome'])->firstOrFail();
        $transicao = CompanyEtapaTransicao::where('company_id', $company->id)->first();

        $this->assertNotNull($transicao);
        $this->assertSame($admin->id, $transicao->user_id);
        $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $transicao->etapa_nova);
    }

    public function test_empresa_cadastrada_aparece_na_listagem_entrada(): void
    {
        $this->actingAsAdmin();
        $payload = $this->payload();

        $this->post(route('comercial.empresas.store'), $payload)->assertSessionHasNoErrors();

        $company = Company::where('name', $payload['nome'])->firstOrFail();

        $resposta = $this->get(route('comercial.entrada.index'));
        $resposta->assertOk();

        $ids = collect($resposta->viewData('page')['props']['companies']['data'])->pluck('id');
        $this->assertContains($company->id, $ids, 'Empresa cadastrada manualmente deveria aparecer na listagem Entrada');
    }

    public function test_resposta_continua_sendo_back_com_success(): void
    {
        $this->actingAsAdmin();
        $payload = $this->payload();

        $response = $this->post(route('comercial.empresas.store'), $payload);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }
}
