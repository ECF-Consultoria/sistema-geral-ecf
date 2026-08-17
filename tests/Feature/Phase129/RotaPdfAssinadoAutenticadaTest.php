<?php

namespace Tests\Feature\Phase129;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 129 Plano 06, Task 2 (CLICK-11, D-13, T-129-37/T-129-38) — prova que
 * o PDF assinado só sai do servidor por rota autenticada de admin, e que a
 * rota se defende sozinha contra path traversal mesmo com um valor de banco
 * malicioso.
 */
class RotaPdfAssinadoAutenticadaTest extends TestCase
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

    private function contratoDeTeste(array $overrides = []): ContratoAssinatura
    {
        $company = Company::factory()->create();
        $servico = Servico::create([
            'nome'          => 'Assessoria (rota pdf)',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        return ContratoAssinatura::factory()->comSnapshot()->create(array_merge([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'status'     => ContratoAssinatura::STATUS_ASSINADO,
        ], $overrides));
    }

    #[Test]
    public function visitante_anonimo_e_redirecionado_para_login(): void
    {
        $contrato = $this->contratoDeTeste();

        $response = $this->get(route('contratos.pdf-assinado', $contrato));

        $response->assertRedirect(); // login, nao 200
        $this->assertNotSame(200, $response->getStatusCode());
    }

    #[Test]
    public function usuario_sem_role_admin_recebe_403(): void
    {
        $contrato = $this->contratoDeTeste();

        $response = $this->actingAs($this->naoAdmin())
            ->get(route('contratos.pdf-assinado', $contrato));

        $response->assertForbidden();
    }

    #[Test]
    public function admin_com_arquivo_presente_recebe_download(): void
    {
        Storage::fake('local');

        $relativo = 'contratos/999/assinado.pdf';
        Storage::disk('local')->put($relativo, "%PDF-1.4\nfake");

        $contrato = $this->contratoDeTeste(['pdf_assinado_path' => $relativo]);

        $response = $this->actingAs($this->admin())
            ->get(route('contratos.pdf-assinado', $contrato));

        $response->assertOk();
        $response->assertHeader('Content-Disposition');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function admin_com_pdf_assinado_path_nulo_recebe_404(): void
    {
        $contrato = $this->contratoDeTeste(['pdf_assinado_path' => null]);

        $response = $this->actingAs($this->admin())
            ->get(route('contratos.pdf-assinado', $contrato));

        $response->assertNotFound();
    }

    #[Test]
    public function admin_com_path_traversal_recebe_404(): void
    {
        Storage::fake('local');

        $contrato = $this->contratoDeTeste([
            'pdf_assinado_path' => '../../../../etc/passwd',
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('contratos.pdf-assinado', $contrato));

        $response->assertNotFound();
    }
}
