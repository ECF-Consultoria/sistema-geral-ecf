<?php

namespace Tests\Unit\Phase139;

use App\Models\ChecklistAdministrativoItem;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 139 Plano 02 — prova de D-02 (catálogo fechado de status, sem
 * "nao_aplicavel") e de D-11 (autoria que sobrevive ao soft delete do
 * usuário), mais a trava de mass assignment (T-139-02-01).
 */
class ChecklistItemModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_todos_tem_exatamente_dois_estados_sem_nao_aplicavel(): void
    {
        $this->assertCount(2, ChecklistAdministrativoItem::STATUS_TODOS);
        $this->assertContains(ChecklistAdministrativoItem::STATUS_ABERTO, ChecklistAdministrativoItem::STATUS_TODOS);
        $this->assertContains(ChecklistAdministrativoItem::STATUS_CONCLUIDO, ChecklistAdministrativoItem::STATUS_TODOS);
        $this->assertNotContains('nao_aplicavel', ChecklistAdministrativoItem::STATUS_TODOS);
    }

    public function test_autoria_sobrevive_ao_soft_delete_do_usuario(): void
    {
        $empresa = Company::factory()->create();
        $usuario = User::factory()->create();

        $item = ChecklistAdministrativoItem::create([
            'company_id' => $empresa->id,
            'chave'      => 'grupo_whatsapp_criado',
            'status'     => ChecklistAdministrativoItem::STATUS_CONCLUIDO,
            'feito_por'  => $usuario->id,
            'feito_em'   => now(),
        ]);

        $usuario->delete(); // soft delete

        $this->assertTrue($usuario->trashed());

        $itemAtualizado = $item->fresh();

        $this->assertNotNull($itemAtualizado->feitoPor);
        $this->assertSame($usuario->id, $itemAtualizado->feitoPor->id);
        $this->assertTrue($itemAtualizado->feitoPor->trashed());
    }

    public function test_fillable_nao_permite_forcar_o_id(): void
    {
        $empresa = Company::factory()->create();

        $item = ChecklistAdministrativoItem::create([
            'id'         => 999,
            'company_id' => $empresa->id,
            'chave'      => 'email_colaborador_criado',
            'status'     => ChecklistAdministrativoItem::STATUS_ABERTO,
        ]);

        $this->assertNotSame(999, $item->id);
    }
}
