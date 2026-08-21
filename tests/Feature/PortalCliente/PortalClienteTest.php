<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\OnboardingLink;
use App\Models\Ppa;
use App\Models\PpaTask;
use App\Models\User;
use App\Services\Portal\PortalClienteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O Portal do Cliente — a área em `/portal-cliente/{token}` que deixou de ser
 * só o onboarding e virou o ambiente da empresa (21/08/2026).
 *
 * ### O que estes testes protegem
 * 1. **O isolamento por empresa.** O portal é um link sem senha. A única coisa
 *    que separa o cliente A do cliente B é o token, e todo caminho de leitura
 *    e de escrita precisa respeitá-lo. Um vazamento aqui não é um bug de tela:
 *    é o plano de ação de um cliente aparecendo para outro.
 * 2. **Que o PPA é UM só.** Não há cópia para o portal. O que a equipe cria em
 *    `/ppa` é o que o cliente vê, e o que o cliente move é a linha que o
 *    kanban interno mostra.
 * 3. **Que os links já enviados continuam abrindo.** O prefixo antigo
 *    (`/onboarding-cliente/{token}`) está no WhatsApp de clientes reais e não
 *    há como recolhê-lo.
 */
class PortalClienteTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(string $nome = 'Cliente Portal'): Company
    {
        return Company::create([
            'name'             => $nome.' '.uniqid(),
            'cnpj'             => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'           => true,
            'status'           => 'ativo',
            'adman_account_id' => (string) random_int(100000, 999999),
            'empresa_nova'     => false,
        ]);
    }

    private function token(Company $company): string
    {
        return OnboardingLink::firstOrCreate(
            ['company_id' => $company->id],
            ['token' => Str::random(48)]
        )->token;
    }

    private function mentor(): User
    {
        return User::create([
            'name'     => 'Mentor '.uniqid(),
            'email'    => 'mentor.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $tarefas  [titulo, status]
     */
    private function ppa(Company $company, string $status = 'sent', array $tarefas = [], string $titulo = 'Plano'): Ppa
    {
        $ppa = Ppa::create([
            'escopo'     => Ppa::ESCOPO_GERAL,
            'company_id' => $company->id,
            'mentor_id'  => $this->mentor()->id,
            'title'      => $titulo.' '.uniqid(),
            'status'     => $status,
        ]);

        foreach ($tarefas as $i => [$t, $s]) {
            PpaTask::create(['ppa_id' => $ppa->id, 'title' => $t, 'status' => $s, 'order' => $i]);
        }

        return $ppa->fresh();
    }

    // ─── A moldura do portal ────────────────────────────────────────────────

    #[Test]
    public function inicio_responde_e_traz_os_tres_modulos_na_ordem(): void
    {
        $company = $this->empresa();

        $this->get(route('portal.inicio', $this->token($company)))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Inicio')
                ->where('modulo', 'inicio')
                ->where('modulos.0.chave', 'inicio')
                ->where('modulos.1.chave', 'onboarding')
                ->where('modulos.2.chave', 'ppa')
                ->where('modulos.0.ativo', true)
                ->where('modulos.2.ativo', false)
            );
    }

    /**
     * "Minhas pendências" era um item de menu que apontava para uma âncora
     * dentro da própria página de onboarding. Ele saiu quando o portal virou
     * multimódulo: com Onboarding e PPA como destinos de verdade, um item que
     * rolava a tela para baixo passou a ser ruído.
     */
    #[Test]
    public function menu_nao_tem_mais_minhas_pendencias(): void
    {
        $company = $this->empresa();

        $this->get(route('portal.inicio', $this->token($company)))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('modulos', fn ($modulos) => collect($modulos)
                    ->pluck('chave')
                    ->diff(['inicio', 'onboarding', 'ppa'])
                    ->isEmpty()
                )
            );
    }

    #[Test]
    public function o_modulo_de_onboarding_continua_funcionando_e_recebe_o_contexto(): void
    {
        $company = $this->empresa();

        $this->get(route('portal.onboarding', $this->token($company)))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Onboarding/Publico')
                ->where('modulo', 'onboarding')
                ->where('empresa.nome', $company->name)
                // A tela do onboarding continua recebendo o que só ela usa —
                // um spread mal colocado no controller apagaria isto ou a
                // identidade da empresa, e o sintoma seria silencioso.
                ->has('empresa.email_colaborador')
                ->has('empresa.iniciais')
                ->has('passos')
            );
    }

    /**
     * Links já enviados a clientes apontam para o prefixo antigo. Eles não
     * podem morrer — não há como recolher um link que já está no WhatsApp de
     * alguém.
     */
    #[Test]
    public function url_antiga_redireciona_permanentemente_para_o_modulo_de_onboarding(): void
    {
        $company = $this->empresa();
        $token   = $this->token($company);

        $this->get("/onboarding-cliente/{$token}")
            ->assertStatus(301)
            ->assertRedirect(route('portal.onboarding', $token));
    }

    #[Test]
    public function token_inexistente_da_404_em_todos_os_modulos(): void
    {
        foreach (['portal.inicio', 'portal.onboarding', 'portal.ppa'] as $rota) {
            $this->get(route($rota, 'token-que-nao-existe-0000'))
                ->assertNotFound();
        }
    }

    /**
     * O painel interno distingue "não fez" de "nem viu" pelo `ultimo_acesso`.
     * Ele é carimbado no `PortalClienteService::resolver()`, que é a porta de
     * TODO módulo — entrar pelo PPA precisa contar como visita tanto quanto
     * entrar pelo onboarding.
     */
    #[Test]
    public function qualquer_modulo_carimba_o_ultimo_acesso(): void
    {
        foreach (['portal.inicio', 'portal.onboarding', 'portal.ppa'] as $rota) {
            $company = $this->empresa();
            $token   = $this->token($company);

            $this->assertNull(OnboardingLink::where('token', $token)->first()->ultimo_acesso);

            $this->get(route($rota, $token))->assertOk();

            $this->assertNotNull(
                OnboardingLink::where('token', $token)->first()->ultimo_acesso,
                "A rota {$rota} não carimbou ultimo_acesso."
            );
        }
    }

    // ─── A identidade da empresa ────────────────────────────────────────────

    #[Test]
    public function a_logo_da_empresa_chega_ao_portal(): void
    {
        $company = $this->empresa();
        $company->update(['logo_url' => '/storage/logos/9_abc.webp']);

        $this->get(route('portal.inicio', $this->token($company)))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('empresa.logo_url', '/storage/logos/9_abc.webp')
            );
    }

    /**
     * Empresa sem logo cai no monograma. As iniciais vêm prontas do backend
     * para que menu e hub nunca divirjam.
     */
    #[Test]
    public function empresa_sem_logo_recebe_iniciais_para_o_monograma(): void
    {
        $company = Company::create([
            'name'         => 'Casa de Festas Aurora',
            'cnpj'         => '11222333000144',
            'active'       => true,
            'status'       => 'ativo',
            'empresa_nova' => false,
        ]);

        $this->get(route('portal.inicio', $this->token($company)))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('empresa.logo_url', null)
                // "de" não conta: seriam "CD" em vez de "CF".
                ->where('empresa.iniciais', 'CF')
            );
    }

    #[Test]
    public function iniciais_tratam_nome_de_uma_palavra_so(): void
    {
        $this->assertSame('VI', PortalClienteService::iniciais('Vitória'));
        $this->assertSame('CF', PortalClienteService::iniciais('Casa de Festas'));
        $this->assertSame('AB', PortalClienteService::iniciais('  Alfa   Beta Gama '));
        $this->assertSame('?',  PortalClienteService::iniciais(''));
        $this->assertSame('?',  PortalClienteService::iniciais(null));
    }

    // ─── O módulo PPA ───────────────────────────────────────────────────────

    #[Test]
    public function ppa_criado_internamente_aparece_no_portal_da_empresa(): void
    {
        $company = $this->empresa();
        $ppa = $this->ppa($company, 'sent', [['Ajustar preços', 'todo'], ['Enviar custos', 'done']]);

        $this->get(route('portal.ppa', $this->token($company)))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Ppa')
                ->has('ppas', 1)
                ->where('ppas.0.id', $ppa->id)
                ->where('ppas.0.titulo', $ppa->title)
                ->where('ppas.0.total', 2)
                ->where('ppas.0.feitas', 1)
                ->where('ppas.0.pct', 50)
            );
    }

    /**
     * Rascunho é trabalho interno em construção — o cliente veria um plano
     * pela metade e cobraria explicação sobre algo que ainda está sendo
     * montado.
     */
    #[Test]
    public function ppa_em_rascunho_nao_aparece_para_o_cliente(): void
    {
        $company = $this->empresa();
        $this->ppa($company, 'draft', [['Nao deve vazar', 'todo']]);

        $this->get(route('portal.ppa', $this->token($company)))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('ppas', 0));
    }

    /**
     * O teste central de isolamento: o token de A nunca pode trazer o plano de
     * B.
     */
    #[Test]
    public function cliente_nao_ve_ppa_de_outra_empresa(): void
    {
        $minha  = $this->empresa('Minha');
        $outra  = $this->empresa('Outra');

        $meu     = $this->ppa($minha, 'sent', [], 'Meu plano');
        $alheio  = $this->ppa($outra, 'sent', [], 'Plano alheio');

        $this->get(route('portal.ppa', $this->token($minha)))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('ppas', 1)
                ->where('ppas.0.id', $meu->id)
            );

        $this->assertDatabaseHas('ppas', ['id' => $alheio->id, 'company_id' => $outra->id]);
    }

    /**
     * PPA de Polos amarra em `MlbEmpresa`, não em `Company` — o vínculo até a
     * empresa do portal passa por `mlb_empresas.company_id`.
     */
    #[Test]
    public function ppa_do_escopo_polos_chega_pelo_vinculo_da_mlb_empresa(): void
    {
        $company = $this->empresa();

        $mlbEmpresa = MlbEmpresa::create([
            'nome'       => 'Polo '.uniqid(),
            'company_id' => $company->id,
        ]);

        $ppa = Ppa::create([
            'escopo'         => Ppa::ESCOPO_POLOS,
            'mlb_empresa_id' => $mlbEmpresa->id,
            'mentor_id'      => $this->mentor()->id,
            'title'          => 'Plano de Polos',
            'status'         => 'sent',
        ]);

        $this->get(route('portal.ppa', $this->token($company)))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('ppas', 1)
                ->where('ppas.0.id', $ppa->id)
            );
    }

    /**
     * Nada de operação interna viaja no payload do PPA — mesma disciplina do
     * T-135-11-02 aplicada ao módulo novo. `trello_board_url` é o quadro de
     * trabalho da equipe e `workspace_token` é uma credencial.
     */
    #[Test]
    public function payload_do_ppa_nao_carrega_dado_interno(): void
    {
        $company = $this->empresa();
        $ppa = $this->ppa($company);
        $ppa->update([
            'trello_board_url' => 'https://trello.com/b/interno',
            'workspace_token'  => 'token-secreto-do-workspace',
        ]);

        $resposta = $this->get(route('portal.ppa', $this->token($company)));

        $resposta->assertOk();
        $resposta->assertDontSee('trello.com', false);
        $resposta->assertDontSee('token-secreto-do-workspace', false);
        $resposta->assertInertia(fn ($page) => $page
            ->missing('ppas.0.trello_board_url')
            ->missing('ppas.0.workspace_token')
            ->missing('ppas.0.mentor_id')
        );
    }

    // ─── O cliente movendo tarefa ───────────────────────────────────────────

    #[Test]
    public function cliente_move_a_tarefa_e_a_mesma_linha_muda_no_banco(): void
    {
        $company = $this->empresa();
        $ppa = $this->ppa($company, 'sent', [['Ajustar preços', 'todo']]);
        $tarefa = $ppa->tasks()->first();

        $this->patchJson(route('portal.ppa.tarefa', [$this->token($company), $tarefa->id]), [
            'status' => 'doing',
        ])->assertOk()->assertJson(['ok' => true, 'status' => 'doing']);

        // A MESMA linha que o kanban interno lê — não uma cópia do portal.
        $this->assertDatabaseHas('ppa_tasks', ['id' => $tarefa->id, 'status' => 'doing']);
    }

    /**
     * A trava que impede trocar o id na URL. Sem ela, o token de A moveria a
     * tarefa de B.
     */
    #[Test]
    public function cliente_nao_move_tarefa_de_outra_empresa(): void
    {
        $minha = $this->empresa('Minha');
        $outra = $this->empresa('Outra');

        $ppaAlheio = $this->ppa($outra, 'sent', [['Tarefa alheia', 'todo']]);
        $tarefa = $ppaAlheio->tasks()->first();

        $this->patchJson(route('portal.ppa.tarefa', [$this->token($minha), $tarefa->id]), [
            'status' => 'done',
        ])->assertForbidden();

        $this->assertDatabaseHas('ppa_tasks', ['id' => $tarefa->id, 'status' => 'todo']);
    }

    /**
     * Rascunho não aparece na tela — e também não pode ser movido por quem
     * descobrir o id da tarefa.
     */
    #[Test]
    public function cliente_nao_move_tarefa_de_ppa_em_rascunho(): void
    {
        $company = $this->empresa();
        $ppa = $this->ppa($company, 'draft', [['Tarefa de rascunho', 'todo']]);
        $tarefa = $ppa->tasks()->first();

        $this->patchJson(route('portal.ppa.tarefa', [$this->token($company), $tarefa->id]), [
            'status' => 'done',
        ])->assertForbidden();

        $this->assertDatabaseHas('ppa_tasks', ['id' => $tarefa->id, 'status' => 'todo']);
    }

    /**
     * Plano encerrado pela equipe vira leitura. A tela já esconde os botões; a
     * trava do servidor é o que garante o comportamento para quem chamar a
     * rota direto.
     */
    #[Test]
    public function cliente_nao_move_tarefa_de_ppa_ja_concluido(): void
    {
        $company = $this->empresa();
        $ppa = $this->ppa($company, 'completed', [['Tarefa encerrada', 'done']]);
        $tarefa = $ppa->tasks()->first();

        $this->patchJson(route('portal.ppa.tarefa', [$this->token($company), $tarefa->id]), [
            'status' => 'todo',
        ])->assertForbidden();
    }

    #[Test]
    public function status_invalido_e_recusado(): void
    {
        $company = $this->empresa();
        $ppa = $this->ppa($company, 'sent', [['Ajustar preços', 'todo']]);

        $this->patchJson(route('portal.ppa.tarefa', [$this->token($company), $ppa->tasks()->first()->id]), [
            'status' => 'arquivada',
        ])->assertStatus(422);
    }

    // ─── O badge do menu ────────────────────────────────────────────────────

    /**
     * O badge acompanha o cliente por todo o portal: ele precisa ver as
     * tarefas em aberto do PPA enquanto está no Onboarding. Por isso a
     * contagem é montada no contexto compartilhado, e não na página do módulo.
     */
    #[Test]
    public function badge_do_ppa_aparece_mesmo_estando_em_outro_modulo(): void
    {
        $company = $this->empresa();
        $this->ppa($company, 'sent', [
            ['Uma', 'todo'],
            ['Outra', 'doing'],
            ['Feita', 'done'],
        ]);

        $this->get(route('portal.onboarding', $this->token($company)))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('modulos.2.chave', 'ppa')
                ->where('modulos.2.badge', 2)
            );
    }

    /**
     * PPA encerrado não cobra mais nada do cliente, mesmo com tarefa aberta —
     * um número teimando no menu o mandaria perseguir algo que ninguém espera.
     */
    #[Test]
    public function ppa_concluido_nao_gera_badge(): void
    {
        $company = $this->empresa();
        $this->ppa($company, 'completed', [['Sobrou aberta', 'todo']]);

        $this->get(route('portal.inicio', $this->token($company)))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('modulos.2.badge', null));
    }
}
