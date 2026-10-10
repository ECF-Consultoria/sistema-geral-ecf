<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\PubPublicacao;
use App\Models\PubTarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Publicador\Concerns\CenarioTarefas;
use Tests\TestCase;

/** `publicador:tarefas-retroativas`: o passado só para quem quiser, sem sino, idempotente e com simulação. */
class TarefasRetroativasCommandTest extends TestCase
{
    use CenarioTarefas;
    use RefreshDatabase;

    private Company $loja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-15 10:00:00', 'America/Sao_Paulo'));
        Notification::fake();
        User::factory()->create(['role' => 'admin']);
        $this->loja = Company::factory()->create();
    }

    public function test_dry_run_so_lista_e_o_real_abre_sem_sino_e_nao_duplica(): void
    {
        $antiga = $this->publicacaoConcluida($this->loja, ['MLB1' => 'gold_special'], concluidaEm: Carbon::parse('2026-09-20 10:00'));
        $dentro = $this->publicacaoConcluida($this->loja, ['MLB2' => 'gold_special', 'MLB3' => 'gold_pro'], concluidaEm: Carbon::parse('2026-10-08 16:00'));
        $parcial = $this->publicacaoConcluida($this->loja, ['MLB4' => 'gold_special'], PubPublicacao::PARTIALLY_PUBLISHED, concluidaEm: Carbon::parse('2026-10-09 09:00'));
        $this->publicacaoConcluida($this->loja, ['MLB5' => 'gold_special'], PubPublicacao::RUNNING, concluidaEm: Carbon::parse('2026-10-09 09:00'));

        $this->artisan('publicador:tarefas-retroativas', ['--desde' => '2026-10-01', '--dry-run' => true])
            ->expectsOutputToContain('SIMULAÇÃO')
            ->assertSuccessful();
        $this->assertSame(0, PubTarefa::query()->count());

        $this->artisan('publicador:tarefas-retroativas', ['--desde' => '2026-10-01'])
            ->expectsOutputToContain('2 tarefa(s) aberta(s)')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing([$dentro->id, $parcial->id], PubTarefa::query()->pluck('publicacao_id')->all(), 'antes da data e rodando ficam de fora');
        $this->assertNull(PubTarefa::query()->where('publicacao_id', $antiga->id)->first());
        $this->assertSame('2026-10-09', PubTarefa::query()->where('publicacao_id', $dentro->id)->value('prazo'), 'D+1 útil da publicação, não de hoje');
        Notification::assertNothingSent();

        $this->artisan('publicador:tarefas-retroativas', ['--desde' => '2026-10-01'])
            ->expectsOutputToContain('0 tarefa(s) aberta(s)')
            ->assertSuccessful();
        $this->assertSame(2, PubTarefa::query()->count(), 'rodar de novo não duplica');
    }

    public function test_sem_data_ou_com_data_invalida_nao_grava(): void
    {
        $this->publicacaoConcluida($this->loja);

        $this->artisan('publicador:tarefas-retroativas')->assertFailed();
        $this->artisan('publicador:tarefas-retroativas', ['--desde' => '01/10/2026'])->assertFailed();
        $this->assertSame(0, PubTarefa::query()->count());
    }
}
