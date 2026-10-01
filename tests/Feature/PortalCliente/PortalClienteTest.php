<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\Ppa;
use App\Models\PpaTask;
use App\Models\User;
use App\Services\Portal\PortalClienteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\EntraNoPortal;
use Tests\TestCase;

/**
 * O Portal do Cliente — o ambiente da empresa (21/08/2026), acessado por LOGIN.
 *
 * ### O que estes testes protegem
 * 1. **O isolamento por empresa.** Desde 15/09/2026 o que separa o cliente A do
 *    cliente B é o vínculo em `portal_usuario_empresa`, conferido a cada request
 *    por `EnsurePortalAutenticado` — não mais a posse de um token. Todo caminho
 *    de leitura e de escrita precisa respeitá-lo. Um vazamento aqui não é bug de
 *    tela: é o plano de ação de um cliente aparecendo para outro.
 * 2. **Que o PPA é UM só.** Não há cópia para o portal. O que a equipe cria em
 *    `/ppa` é o que o cliente vê, e o que o cliente move é a linha que o kanban
 *    interno mostra.
 *
 * ### O que saiu daqui
 * Os casos da porta por token — 404 para token inexistente, 301 do prefixo
 * antigo e o carimbo de `ultimo_acesso` no link — descreviam uma porta que
 * deixou de abrir. O que vale agora está em `PortalSemTokenTest`: qualquer
 * token, real ou inventado, em qualquer host, leva ao login sem carimbar nada.
 */
class PortalClienteTest extends TestCase
{
    use EntraNoPortal;
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
    public function inicio_responde_e_traz_os_modulos_na_ordem(): void
    {
        $company = $this->empresa();

        $this->entrarNoPortal($company)->get(route('portal.auth.inicio'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Inicio')
                ->where('modulo', 'inicio')
                // A Calculadora de Custo entrou em 14/09, entre Onboarding e
                // PPA — a ordem é a de DEFINICOES em `ModulosPortal`.
                ->where('modulos.0.chave', 'inicio')
                ->where('modulos.1.chave', 'onboarding')
                ->where('modulos.2.chave', 'calculadora')
                ->where('modulos.3.chave', 'ppa')
                ->where('modulos.0.ativo', true)
                ->where('modulos.3.ativo', false)
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

        $this->entrarNoPortal($company)->get(route('portal.auth.inicio'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('modulos', fn ($modulos) => collect($modulos)
                    ->pluck('chave')
                    // `estrutura` (Mapeamento Estrutural) entrou em 23/09.
                    ->diff(['inicio', 'onboarding', 'calculadora', 'ppa', 'estrutura'])
                    ->isEmpty()
                )
            );
    }

    #[Test]
    public function o_modulo_de_onboarding_continua_funcionando_e_recebe_o_contexto(): void
    {
        $company = $this->empresa();

        $this->entrarNoPortal($company)->get(route('portal.auth.onboarding'))
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

    // ─── A identidade da empresa ────────────────────────────────────────────

    #[Test]
    public function a_logo_da_empresa_chega_ao_portal(): void
    {
        $company = $this->empresa();
        $company->update(['logo_url' => '/storage/logos/9_abc.webp']);

        $this->entrarNoPortal($company)->get(route('portal.auth.inicio'))
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

        $this->entrarNoPortal($company)->get(route('portal.auth.inicio'))
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

        $this->entrarNoPortal($company)->get(route('portal.auth.ppa'))
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

        $this->entrarNoPortal($company)->get(route('portal.auth.ppa'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('ppas', 0));
    }

    /**
     * O teste central de isolamento: a sessão de A nunca pode trazer o plano
     * de B.
     */
    #[Test]
    public function cliente_nao_ve_ppa_de_outra_empresa(): void
    {
        $minha  = $this->empresa('Minha');
        $outra  = $this->empresa('Outra');

        $meu     = $this->ppa($minha, 'sent', [], 'Meu plano');
        $alheio  = $this->ppa($outra, 'sent', [], 'Plano alheio');

        $this->entrarNoPortal($minha)->get(route('portal.auth.ppa'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('ppas', 1)
                ->where('ppas.0.id', $meu->id)
            );

        $this->assertDatabaseHas('ppas', ['id' => $alheio->id, 'company_id' => $outra->id]);
    }

    /**
     * A sessão aponta para uma empresa sem vínculo com a pessoa logada. O que
     * decide é o vínculo no banco — trocar `portal_empresa_id` na sessão não
     * pode abrir o plano de outra empresa.
     */
    #[Test]
    public function sessao_apontando_para_empresa_sem_vinculo_nao_mostra_o_plano_dela(): void
    {
        $minha = $this->empresa('Minha');
        $outra = $this->empresa('Outra');
        $this->ppa($outra, 'sent', [], 'Plano alheio');

        $cliente = $this->clienteDoPortal($minha);

        // Pessoa da empresa "Minha", sessão apontando para "Outra".
        $resposta = $this->entrarNoPortal($outra, $cliente)->get(route('portal.auth.ppa'));

        $resposta->assertOk();
        $resposta->assertDontSee('Plano alheio', false);
        // O middleware devolve a pessoa à empresa dela — não à que a sessão pedia.
        $resposta->assertSessionHas('portal_empresa_id', $minha->id);
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

        $this->entrarNoPortal($company)->get(route('portal.auth.ppa'))
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

        $resposta = $this->entrarNoPortal($company)->get(route('portal.auth.ppa'));

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

        $this->entrarNoPortal($company)->patchJson(route('portal.auth.ppa.tarefa', $tarefa->id), [
            'status' => 'doing',
        ])->assertOk()->assertJson(['ok' => true, 'status' => 'doing']);

        // A MESMA linha que o kanban interno lê — não uma cópia do portal.
        $this->assertDatabaseHas('ppa_tasks', ['id' => $tarefa->id, 'status' => 'doing']);
    }

    /**
     * A trava que impede trocar o id na URL. Sem ela, a sessão de A moveria a
     * tarefa de B.
     */
    #[Test]
    public function cliente_nao_move_tarefa_de_outra_empresa(): void
    {
        $minha = $this->empresa('Minha');
        $outra = $this->empresa('Outra');

        $ppaAlheio = $this->ppa($outra, 'sent', [['Tarefa alheia', 'todo']]);
        $tarefa = $ppaAlheio->tasks()->first();

        $this->entrarNoPortal($minha)->patchJson(route('portal.auth.ppa.tarefa', $tarefa->id), [
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

        $this->entrarNoPortal($company)->patchJson(route('portal.auth.ppa.tarefa', $tarefa->id), [
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

        $this->entrarNoPortal($company)->patchJson(route('portal.auth.ppa.tarefa', $tarefa->id), [
            'status' => 'todo',
        ])->assertForbidden();
    }

    #[Test]
    public function status_invalido_e_recusado(): void
    {
        $company = $this->empresa();
        $ppa = $this->ppa($company, 'sent', [['Ajustar preços', 'todo']]);

        $this->entrarNoPortal($company)->patchJson(route('portal.auth.ppa.tarefa', $ppa->tasks()->first()->id), [
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

        $this->entrarNoPortal($company)->get(route('portal.auth.onboarding'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('modulos.3.chave', 'ppa')
                ->where('modulos.3.badge', 2)
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

        $this->entrarNoPortal($company)->get(route('portal.auth.inicio'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('modulos.3.badge', null));
    }
}
