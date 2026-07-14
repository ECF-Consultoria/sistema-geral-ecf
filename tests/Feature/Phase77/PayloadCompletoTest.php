<?php

namespace Tests\Feature\Phase77;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressão do gotcha Laravel: validar a chave aninhada `payload.title` fazia
 * $request->validate() devolver apenas { title } em $dados['payload'], e o
 * atualizarRascunho gravava um payload SEM category_id/price/available_quantity.
 * O ItemBuilder então descartava as chaves null e o ML devolvia body.required_fields.
 *
 * Este teste garante que o PUT preserva o payload COMPLETO.
 *
 * @group phase77
 */
class PayloadCompletoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function companyComToken(): Company
    {
        $company = Company::factory()->create();
        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => 'U' . uniqid(),
            'access_token'  => 'x',
            'refresh_token' => 'y',
            'status'        => 'active',
            'expires_at'    => now()->addDays(30),
            'connected_at'  => now(),
        ]);
        return $company;
    }

    public function test_atualizar_rascunho_preserva_payload_completo(): void
    {
        $admin   = $this->admin();
        $company = $this->companyComToken();

        $rascunho = MlAnuncioRascunho::create([
            'company_id' => $company->id,
            'user_id'    => $admin->id,
            'payload'    => ['title' => 'inicial'],
            'status'     => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        $payloadCompleto = [
            'title'              => 'Poltrona Reclinável Suede Cinza',
            'category_id'        => 'MLB458191',
            'price'              => 776.98,
            'currency_id'        => 'BRL',
            'available_quantity' => 3,
            'condition'          => 'new',
            'listing_type_id'    => 'gold_special',
            'attributes'         => [['id' => 'BRAND', 'value_name' => 'Genérica']],
        ];

        $this->actingAs($admin)
            ->putJson(route('mlb.anuncios.rascunho.update', ['rascunho' => $rascunho->id]), [
                'category_id' => 'MLB458191',
                'payload'     => $payloadCompleto,
            ])
            ->assertOk();

        $fresh = $rascunho->fresh();

        // O payload gravado deve conter TODAS as chaves — não só title
        $this->assertSame('MLB458191', $fresh->payload['category_id'] ?? null);
        $this->assertEquals(776.98, $fresh->payload['price'] ?? null);
        $this->assertSame(3, $fresh->payload['available_quantity'] ?? null);
        $this->assertSame('Poltrona Reclinável Suede Cinza', $fresh->payload['title'] ?? null);
        $this->assertNotEmpty($fresh->payload['attributes'] ?? []);
    }
}
