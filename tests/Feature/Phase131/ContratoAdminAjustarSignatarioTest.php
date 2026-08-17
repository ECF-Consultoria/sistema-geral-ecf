<?php

namespace Tests\Feature\Phase131;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Fase 131 Plano 05 (CLICK-09/UI-04, RAMO B/D-14) — a trava que impede
 * alguém reintroduzir a promessa de "corrigir e-mail de quem assina".
 *
 * Medido em 2026-08-14 (`CLICKSIGN-SANDBOX-EMPIRICO.md` §15.1): `PATCH` e
 * `PUT` em `/envelopes/{id}/signers/{signerId}` devolvem **404** (HTML
 * genérico de rota inexistente, não o 404 JSON:API). Não existe endpoint de
 * correção de e-mail na v3 — a D-14 colapsou "corrigir e-mail" e "trocar
 * quem assina" no MESMO caminho (cancelar e reemitir).
 */
class ContratoAdminAjustarSignatarioTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Nenhuma rota nomeada `admin.contratos.*` contém `email`/`signatario.update`
     * — a superfície de "corrigir e-mail" simplesmente não existe.
     */
    public function test_nenhuma_rota_admin_contratos_oferece_corrigir_email_ou_atualizar_signatario(): void
    {
        $rotasAdminContratos = collect(Route::getRoutes())
            ->map(fn ($rota) => $rota->getName())
            ->filter(fn ($nome) => $nome !== null && str_starts_with($nome, 'admin.contratos.'));

        $this->assertTrue($rotasAdminContratos->isNotEmpty(), 'esperava rotas admin.contratos.* registradas.');

        $rotaDeCorrigirEmail = $rotasAdminContratos->first(
            fn ($nome) => str_contains($nome, 'email') || str_contains($nome, 'signatario.update'),
        );

        $this->assertNull(
            $rotaDeCorrigirEmail,
            'não deveria existir rota de correção de e-mail/atualização de signatário — a API v3 não permite (404 medido).',
        );
    }

    /**
     * A prop de signatários que a tela de detalhe recebe nunca traz `email`
     * — não há como o client montar um formulário de correção nem por
     * engano (a mesma trava do T-131-04-04, reafirmada aqui pelo motivo
     * específico da CLICK-09).
     */
    public function test_prop_de_signatarios_na_tela_de_detalhe_nunca_traz_email(): void
    {
        $admin    = $this->admin();
        $empresa  = Company::factory()->create(['active' => true]);
        $contrato = ContratoAssinatura::factory()->emAndamento()->create(['company_id' => $empresa->id]);

        ContratoAssinaturaSignatario::factory()->create([
            'contrato_assinatura_id' => $contrato->id,
            'situacao'               => ContratoAssinaturaSignatario::SITUACAO_PENDENTE,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/ContratoDetalhe'));

        $props = $response->viewData('page')['props'];
        $contratoProp = collect($props['contratos'])->firstWhere('id', $contrato->id);

        $this->assertNotNull($contratoProp);
        foreach ($contratoProp['signatarios'] as $signatarioProp) {
            $this->assertArrayNotHasKey('email', $signatarioProp);
        }
    }

    /**
     * Reforço por convenção de nome — nenhuma rota com o nome que uma
     * bifurcação de "corrigir e-mail" teria naturalmente ganho existe.
     */
    public function test_rotas_com_nome_convencional_de_corrigir_email_nao_existem(): void
    {
        $this->assertFalse(Route::has('admin.contratos.signatario.update'));
        $this->assertFalse(Route::has('admin.contratos.email.update'));
        $this->assertFalse(Route::has('admin.contratos.signatario.email'));
    }
}
