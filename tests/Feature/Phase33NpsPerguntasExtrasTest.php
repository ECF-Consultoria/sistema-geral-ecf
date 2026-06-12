<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NpsPerguntaCustomizada;
use App\Models\NpsRespostaCustomizada;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Suite Feature — Phase 33 Plan 01.
 *
 * Cobre o backend de perguntas customizadas NPS:
 *  T1. submitResponse() rejeita 422 quando pergunta obrigatoria nao vem
 *  T2. submitResponse() grava NpsRespostaCustomizada com snapshot quando responde
 *  T3. submitResponse() valida tipo multipla contra opcoes (rejeita opcao invalida)
 *  T4. submitResponse() valida tipo sim_nao (rejeita "talvez")
 *  T5. respond() carrega payload com perguntas_extras ativas ordenadas
 *  T6. CRUD criarPerguntaExtra grava com ordem=max+1
 *  T7. CRUD excluirPerguntaExtra com respostas vai pra soft delete (ativa=false)
 *  T8. CRUD moverPerguntaExtra=up troca ordem com vizinha
 */
class Phase33NpsPerguntasExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function criarEmpresa(): Company
    {
        return Company::create([
            'name'   => 'Empresa Teste ' . uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
        ]);
    }

    private function criarSurvey(Company $empresa): NpsSurvey
    {
        return NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
        ]);
    }

    public function test_submit_rejeita_quando_pergunta_obrigatoria_omitida(): void
    {
        $survey = $this->criarSurvey($this->criarEmpresa());

        $p = NpsPerguntaCustomizada::create([
            'texto' => 'Indicaria a ECF?',
            'tipo' => 'sim_nao',
            'obrigatorio' => true,
            'ativa' => true,
            'ordem' => 1,
        ]);

        $response = $this->post("/nps/{$survey->token}", [
            'score_estrategista' => 5,
            'score_empresa' => 4,
            // respostas_extras nao enviado
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors("respostas_extras.{$p->id}");
        $this->assertSame(0, NpsResponse::count());
    }

    public function test_submit_grava_resposta_extra_com_snapshot(): void
    {
        $survey = $this->criarSurvey($this->criarEmpresa());

        $p = NpsPerguntaCustomizada::create([
            'texto' => 'Indicaria a ECF?',
            'tipo' => 'sim_nao',
            'obrigatorio' => true,
            'ativa' => true,
            'ordem' => 1,
        ]);

        $response = $this->post("/nps/{$survey->token}", [
            'score_estrategista' => 5,
            'score_empresa' => 4,
            'respostas_extras' => [
                $p->id => 'sim',
            ],
        ]);

        $response->assertOk();

        $r = NpsRespostaCustomizada::first();
        $this->assertNotNull($r);
        $this->assertSame($p->id, $r->pergunta_id);
        $this->assertSame('Indicaria a ECF?', $r->pergunta_texto_snapshot);
        $this->assertSame('sim_nao', $r->tipo_snapshot);
        $this->assertSame('sim', $r->valor);
    }

    public function test_submit_rejeita_opcao_invalida_em_multipla(): void
    {
        $survey = $this->criarSurvey($this->criarEmpresa());

        $p = NpsPerguntaCustomizada::create([
            'texto' => 'Canal?',
            'tipo' => 'multipla',
            'opcoes' => ['WhatsApp', 'Email'],
            'obrigatorio' => true,
            'ativa' => true,
            'ordem' => 1,
        ]);

        $response = $this->post("/nps/{$survey->token}", [
            'score_estrategista' => 5,
            'score_empresa' => 4,
            'respostas_extras' => [
                $p->id => 'Telefone', // nao esta em opcoes
            ],
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors("respostas_extras.{$p->id}");
    }

    public function test_submit_rejeita_valor_invalido_em_sim_nao(): void
    {
        $survey = $this->criarSurvey($this->criarEmpresa());

        $p = NpsPerguntaCustomizada::create([
            'texto' => 'X?',
            'tipo' => 'sim_nao',
            'obrigatorio' => true,
            'ativa' => true,
            'ordem' => 1,
        ]);

        $response = $this->post("/nps/{$survey->token}", [
            'score_estrategista' => 5,
            'score_empresa' => 4,
            'respostas_extras' => [
                $p->id => 'talvez',
            ],
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors("respostas_extras.{$p->id}");
    }

    public function test_respond_carrega_perguntas_extras_ativas_ordenadas(): void
    {
        $survey = $this->criarSurvey($this->criarEmpresa());

        NpsPerguntaCustomizada::create(['texto' => 'P1', 'tipo' => 'texto', 'obrigatorio' => false, 'ativa' => true, 'ordem' => 2]);
        NpsPerguntaCustomizada::create(['texto' => 'P2', 'tipo' => 'sim_nao', 'obrigatorio' => false, 'ativa' => true, 'ordem' => 1]);
        NpsPerguntaCustomizada::create(['texto' => 'P3-inativa', 'tipo' => 'texto', 'obrigatorio' => false, 'ativa' => false, 'ordem' => 0]);

        $response = $this->get("/nps/{$survey->token}");
        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Nps/Respond')
            ->has('perguntas_extras', 2)
            ->where('perguntas_extras.0.texto', 'P2')
            ->where('perguntas_extras.1.texto', 'P1')
        );
    }

    public function test_criar_pergunta_extra_gera_ordem_incremental(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        NpsPerguntaCustomizada::create(['texto' => 'X', 'tipo' => 'texto', 'ordem' => 7, 'ativa' => true]);

        $response = $this->actingAs($admin)->post('/nps/configuracao/perguntas', [
            'texto' => 'Nova',
            'tipo' => 'texto',
            'obrigatorio' => false,
            'ativa' => true,
        ]);

        $response->assertStatus(302);
        $nova = NpsPerguntaCustomizada::where('texto', 'Nova')->first();
        $this->assertNotNull($nova);
        $this->assertSame(8, $nova->ordem);
    }

    /**
     * Bugfix 260612 — frontend manda `opcoes: []` como default do useForm mesmo
     * quando tipo!=multipla; sem o merge defensivo, `min:2` em `opcoes` falhava
     * a validacao silenciosamente e o POST voltava como redirect com errors
     * que a UI nao mostrava.
     */
    public function test_criar_pergunta_sim_nao_aceita_opcoes_array_vazio_do_frontend(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post('/nps/configuracao/perguntas', [
            'texto' => 'Fez grant?',
            'tipo' => 'sim_nao',
            'opcoes' => [],  // payload exato que o useForm envia em tipos nao-multipla
            'obrigatorio' => false,
            'ativa' => true,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('nps_perguntas_customizadas', [
            'texto' => 'Fez grant?',
            'tipo' => 'sim_nao',
            'opcoes' => null,
        ]);
    }

    public function test_excluir_pergunta_com_respostas_vira_soft_delete(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $survey = $this->criarSurvey($this->criarEmpresa());
        $resp = NpsResponse::create([
            'survey_id' => $survey->id,
            'score_estrategista' => 5,
            'score_empresa' => 4,
        ]);

        $p = NpsPerguntaCustomizada::create(['texto' => 'X', 'tipo' => 'sim_nao', 'ativa' => true, 'ordem' => 1]);
        NpsRespostaCustomizada::create([
            'response_id' => $resp->id,
            'pergunta_id' => $p->id,
            'pergunta_texto_snapshot' => 'X',
            'tipo_snapshot' => 'sim_nao',
            'valor' => 'sim',
        ]);

        $response = $this->actingAs($admin)->delete("/nps/configuracao/perguntas/{$p->id}");
        $response->assertStatus(302);

        $p->refresh();
        $this->assertFalse($p->ativa);
        $this->assertNotNull(NpsPerguntaCustomizada::find($p->id)); // ainda existe (soft)
    }

    public function test_mover_pergunta_extra_up_troca_ordem_com_vizinha(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $p1 = NpsPerguntaCustomizada::create(['texto' => 'A', 'tipo' => 'texto', 'ativa' => true, 'ordem' => 1]);
        $p2 = NpsPerguntaCustomizada::create(['texto' => 'B', 'tipo' => 'texto', 'ativa' => true, 'ordem' => 2]);

        $response = $this->actingAs($admin)->post("/nps/configuracao/perguntas/{$p2->id}/mover", [
            'direcao' => 'up',
        ]);
        $response->assertStatus(302);

        $p1->refresh();
        $p2->refresh();
        $this->assertSame(2, $p1->ordem);
        $this->assertSame(1, $p2->ordem);
    }
}
