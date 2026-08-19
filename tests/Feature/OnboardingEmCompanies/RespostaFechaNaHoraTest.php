<?php

namespace Tests\Feature\OnboardingEmCompanies;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Responder o checklist tem de fechar o item NA HORA.
 *
 * Este teste existe por causa de um bug real: as ações gravavam a resposta e
 * chamavam `reavaliar()`, que **não executa resolver nenhum** — ele só destrava
 * passo bloqueado cuja dependência resolveu. Quem roda resolver é o comando
 * `onboarding:reavaliar-passos`, agendado de 10 em 10 minutos. O efeito para
 * quem usa: responde "Sim", a página recarrega, o item continua pendente, e a
 * pessoa responde de novo achando que não salvou.
 *
 * Um teste que só verificasse "a linha foi gravada na tabela" passaria verde
 * com o bug inteiro de pé. Por isso todos aqui cobram o STATUS DO PASSO depois
 * da requisição HTTP real.
 */
class RespostaFechaNaHoraTest extends TestCase
{
    use RefreshDatabase;

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    /** Onboarding em andamento, com os passos já destravados. */
    private function onboardingEmAndamento(): Onboarding
    {
        $company = Company::create([
            'name'   => 'Empresa Resposta '.uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
            'status' => 'ativo',
        ]);

        $contrato = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $this->servicoDeGestao()->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        app(OnboardingEngineService::class)
            ->definirResponsaveis($onboarding, null, User::factory()->create());

        return $onboarding->fresh();
    }

    private function admin(): User
    {
        $admin = User::create([
            'name'     => 'Admin '.uniqid(),
            'email'    => 'admin.resp.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    private function passo(Onboarding $onboarding, string $chave): OnboardingPasso
    {
        return OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', $chave)
            ->firstOrFail();
    }

    /**
     * `confirmar_dados_cadastrais` é o item de confirmação que NÃO depende da
     * reunião — por isso serve para provar o fechamento imediato.
     *
     * @test
     */
    public function responder_sim_fecha_o_item_na_mesma_requisicao(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        $this->assertSame(
            OnboardingPasso::STATUS_ABERTO,
            $this->passo($onboarding, 'confirmar_dados_cadastrais')->status
        );

        $this->post(route('onboarding.confirmacao.responder', $onboarding), [
            'chave'    => 'confirmar_dados_cadastrais',
            'resposta' => 'sim',
        ])->assertRedirect();

        $this->assertSame(
            OnboardingPasso::STATUS_CONCLUIDO,
            $this->passo($onboarding, 'confirmar_dados_cadastrais')->status,
            'O item não fechou na hora — a resposta gravou mas o resolver não rodou.'
        );
    }

    /** "Não" grava e o item continua aberto — é resposta, não silêncio. */
    /** @test */
    public function responder_nao_grava_e_mantem_o_item_aberto(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        $this->post(route('onboarding.confirmacao.responder', $onboarding), [
            'chave'    => 'confirmar_dados_cadastrais',
            'resposta' => 'nao',
        ])->assertRedirect();

        $this->assertSame(
            OnboardingPasso::STATUS_ABERTO,
            $this->passo($onboarding, 'confirmar_dados_cadastrais')->status
        );

        $this->assertDatabaseHas('onboarding_confirmacoes', [
            'onboarding_id' => $onboarding->id,
            'chave'         => 'confirmar_dados_cadastrais',
            'resposta'      => 'nao',
        ]);
    }

    /** @test */
    public function salvar_investimento_fecha_os_dois_itens_na_hora(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        $this->put(route('onboarding.investimento.salvar', $onboarding), [
            'investimento_disponivel'  => 5000,
            'investimento_publicidade' => 1500,
        ])->assertRedirect();

        $this->assertSame(
            OnboardingPasso::STATUS_CONCLUIDO,
            $this->passo($onboarding, 'investimento_alinhado')->status
        );
        $this->assertSame(
            OnboardingPasso::STATUS_CONCLUIDO,
            $this->passo($onboarding, 'investimento_publicidade_alinhado')->status
        );
    }

    /**
     * Zero é resposta informada, não ausência — e tem de fechar o item igual.
     *
     * @test
     */
    public function investimento_zero_fecha_o_item(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        $this->put(route('onboarding.investimento.salvar', $onboarding), [
            'investimento_publicidade' => 0,
        ])->assertRedirect();

        $this->assertSame(
            OnboardingPasso::STATUS_CONCLUIDO,
            $this->passo($onboarding, 'investimento_publicidade_alinhado')->status,
            'Zero foi tratado como "sem resposta".'
        );
    }

    /** @test */
    public function salvar_agenda_fecha_o_item_na_hora(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        $this->put(route('onboarding.agenda.salvar', $onboarding), [
            'dia_semana'    => 3,
            'horario'       => '14:00',
            'periodicidade' => 'quinzenal',
        ])->assertRedirect();

        $this->assertSame(
            OnboardingPasso::STATUS_CONCLUIDO,
            $this->passo($onboarding, 'agenda_quinzenal_definida')->status
        );
    }

    /** @test */
    public function adicionar_contato_fecha_o_item_na_hora(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        $this->post(route('onboarding.contatos.salvar', $onboarding), [
            'papel' => 'ponto_de_contato',
            'nome'  => 'Fulano da Silva',
            'email' => 'fulano@cliente.test',
        ])->assertRedirect();

        $this->assertSame(
            OnboardingPasso::STATUS_CONCLUIDO,
            $this->passo($onboarding, 'ponto_contato_definido')->status
        );
    }

    /**
     * Participante sem e-mail não fecha o item — o objetivo do §16 é mandar
     * convite, e sem e-mail não há convite.
     *
     * @test
     */
    public function participante_sem_email_mantem_o_item_aberto(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        $this->post(route('onboarding.contatos.salvar', $onboarding), [
            'papel' => 'participante_reuniao',
            'nome'  => 'Sem Email',
        ])->assertRedirect();

        $this->assertSame(
            OnboardingPasso::STATUS_ABERTO,
            $this->passo($onboarding, 'participantes_reuniao_cadastrados')->status
        );

        // Com e-mail, fecha.
        $this->post(route('onboarding.contatos.salvar', $onboarding), [
            'papel' => 'participante_reuniao',
            'nome'  => 'Com Email',
            'email' => 'com@cliente.test',
        ])->assertRedirect();

        $this->assertSame(
            OnboardingPasso::STATUS_ABERTO,
            $this->passo($onboarding, 'participantes_reuniao_cadastrados')->status,
            'Um participante sem e-mail ainda impede o fechamento.'
        );
    }

    /**
     * Editar um contato não pode afetar os outros — a lista é escrita por
     * LINHA. Noutro módulo deste sistema, salvar a lista inteira colapsou N
     * produtos num só e apagou o custo do cliente sem volta.
     *
     * @test
     */
    public function editar_um_contato_nao_toca_os_outros(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        foreach (['Ana', 'Bruno', 'Carla'] as $nome) {
            $this->post(route('onboarding.contatos.salvar', $onboarding), [
                'papel' => 'participante_reuniao',
                'nome'  => $nome,
                'email' => strtolower($nome).'@cliente.test',
            ])->assertRedirect();
        }

        $bruno = \App\Models\OnboardingContato::where('onboarding_id', $onboarding->id)
            ->where('nome', 'Bruno')
            ->firstOrFail();

        $this->put(route('onboarding.contatos.atualizar', $bruno), [
            'nome'  => 'Bruno Editado',
            'email' => 'bruno.novo@cliente.test',
        ])->assertRedirect();

        $nomes = \App\Models\OnboardingContato::where('onboarding_id', $onboarding->id)
            ->orderBy('id')
            ->pluck('nome')
            ->all();

        $this->assertSame(['Ana', 'Bruno Editado', 'Carla'], $nomes);
    }

    /** @test */
    public function nao_da_para_responder_chave_que_nao_e_deste_onboarding(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        $this->post(route('onboarding.confirmacao.responder', $onboarding), [
            'chave'    => 'chave_inventada',
            'resposta' => 'sim',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('onboarding_confirmacoes', [
            'onboarding_id' => $onboarding->id,
            'chave'         => 'chave_inventada',
        ]);
    }
}
