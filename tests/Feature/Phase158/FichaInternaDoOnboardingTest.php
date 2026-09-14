<?php

namespace Tests\Feature\Phase158;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Support\Onboarding\DefinicaoOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A ficha interna do onboarding depois da virada de 14/09 — quando o trabalho
 * passou a ser conduzido no Portal do Cliente e esta tela virou leitura, com
 * as ações que só existem aqui.
 *
 * O que se prova:
 *
 *  1. **a marca `no_portal`** chega em cada passo e diz a verdade. Sem ela, os
 *     oito itens conduzidos no portal mudariam de estado sem nada na ficha
 *     explicar por quê;
 *  2. **nenhum passo some do checklist** — o mesmo espelho manual PHP↔JS que
 *     já cobrou caro no portal ("0/10 itens" com quatro cards na tela) existe
 *     aqui em `ChecklistPorEtapa.jsx`;
 *  3. **a fotografia** aparece na ficha e tem rota própria de coleta, para a
 *     tela não mandar ninguém ao portal só para apertar "Atualizar".
 */
class FichaInternaDoOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    private function admin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Ficha '.uniqid(),
            'email'    => 'admin.ficha.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    private function onboardingEmAndamento(): Onboarding
    {
        $servico = Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();

        $company = Company::create([
            'name'         => 'Empresa Ficha '.uniqid(),
            'cnpj'         => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'       => true,
            'status'       => 'ativo',
            'empresa_nova' => false,
        ]);

        $contrato = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        // Sai do rascunho pelo ENGINE — trocar a coluna na mão deixaria todo
        // passo em `bloqueado`, e nenhum item apareceria aberto na ficha.
        app(OnboardingEngineService::class)->confirmarResponsavel(
            $onboarding,
            User::create([
                'name'     => 'Responsavel Ficha '.uniqid(),
                'email'    => 'resp.ficha.'.uniqid().'@ecf.test',
                'password' => bcrypt('senha'),
                'role'     => 'consultor',
                'active'   => true,
            ]),
        );

        return $onboarding->fresh();
    }

    // ─── A marca de quem é operado no portal ────────────────────────────────

    public function test_cada_passo_diz_se_e_operado_no_portal(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        $props = $this->get(route('onboarding.painel.show', $onboarding->id))
            ->assertOk()
            ->viewData('page')['props'];

        foreach ($props['passos'] as $passo) {
            $this->assertArrayHasKey('no_portal', $passo, "passo {$passo['chave']} sem a marca");
            $this->assertSame(
                DefinicaoOnboarding::apareceNoPortal($passo['chave']),
                $passo['no_portal'],
                "a marca de \"{$passo['chave']}\" diverge de DefinicaoOnboarding::apareceNoPortal()"
            );
        }
    }

    /**
     * A guarda contra o teste acima passar à toa: se um dia TODOS os passos
     * forem do portal (ou nenhum), a comparação seria verdadeira comparando
     * dois valores constantes, e a ficha perderia a distinção sem ninguém
     * notar.
     */
    public function test_a_regua_tem_os_dois_lados(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        $passos = $this->get(route('onboarding.painel.show', $onboarding->id))
            ->viewData('page')['props']['passos'];

        $noPortal = collect($passos)->where('no_portal', true);
        $internos = collect($passos)->where('no_portal', false);

        $this->assertGreaterThan(0, $noPortal->count(), 'nenhum passo no portal — a marca perdeu o sentido');
        $this->assertGreaterThan(
            0,
            $internos->count(),
            'todo passo virou do portal — então a ficha interna não teria mais razão de existir'
        );
    }

    // ─── O espelho manual do checklist ──────────────────────────────────────

    /** @return array<int, string> */
    private function etapasDoChecklistJsx(): array
    {
        $jsx = file_get_contents(resource_path('js/Components/Onboarding/Painel/ChecklistPorEtapa.jsx'));

        $ok = preg_match('/export const ETAPAS_FLUXO = \[(.*?)\];/s', $jsx, $m);
        $this->assertSame(1, $ok, 'ETAPAS_FLUXO sumiu ou mudou de forma em ChecklistPorEtapa.jsx');

        preg_match_all("/etapa: '([a-z_]+)'/", $m[1], $chaves);

        return $chaves[1];
    }

    /**
     * Etapa que falta no JS não dá erro: o passo simplesmente não é desenhado.
     * Foi assim que cinco itens sumiram do portal em 14/09 enquanto o contador
     * continuava contando os dez.
     */
    public function test_toda_etapa_da_regua_existe_no_checklist(): void
    {
        $servico = Servico::make(['nome' => 'Gestão', 'setor' => Servico::SETOR_PERFORMANCE]);

        $etapas = collect(DefinicaoOnboarding::paraServico($servico) ?? [])
            ->pluck('etapa')
            ->unique()
            ->values();

        $this->assertNotEmpty($etapas, 'régua vazia — o teste ficaria sem o que verificar');

        $noJsx = $this->etapasDoChecklistJsx();

        foreach ($etapas as $etapa) {
            $this->assertContains(
                $etapa,
                $noJsx,
                "A etapa \"{$etapa}\" existe na régua mas falta em ETAPAS_FLUXO "
                . '(ChecklistPorEtapa.jsx) — os passos dela SOMEM da ficha, sem erro nenhum.'
            );
        }
    }

    public function test_o_bloco_de_sobra_continua_existindo(): void
    {
        $this->assertContains(
            'outros',
            $this->etapasDoChecklistJsx(),
            'sem "outros" um passo com etapa desconhecida some da ficha em vez de cair num bloco.'
        );
    }

    public function test_o_checklist_nao_inventa_etapa_que_o_backend_desconhece(): void
    {
        $doBackend = [...OnboardingPasso::ETAPAS, 'outros'];

        foreach ($this->etapasDoChecklistJsx() as $etapa) {
            $this->assertContains(
                $etapa,
                $doBackend,
                "ETAPAS_FLUXO tem \"{$etapa}\", que não existe em OnboardingPasso::ETAPAS."
            );
        }
    }

    /**
     * A contagem do cabeçalho vem de `passos.length`, e cada cartão só desenha
     * os itens da própria etapa. Todo passo precisa cair em alguma etapa
     * conhecida, senão as duas contas divergem na tela.
     */
    public function test_nenhum_passo_fica_fora_de_uma_etapa_desenhada(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        $passos = $this->get(route('onboarding.painel.show', $onboarding->id))
            ->viewData('page')['props']['passos'];

        $desenhaveis = $this->etapasDoChecklistJsx();

        foreach ($passos as $passo) {
            $this->assertContains(
                $passo['etapa'] ?? 'outros',
                $desenhaveis,
                "o passo \"{$passo['chave']}\" tem etapa \"{$passo['etapa']}\", que nenhum cartão desenha"
            );
        }
    }

    // ─── A fotografia pelo lado de dentro ───────────────────────────────────

    public function test_a_ficha_recebe_a_fotografia_e_a_rota_de_coleta_existe(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        $props = $this->get(route('onboarding.painel.show', $onboarding->id))
            ->assertOk()
            ->viewData('page')['props'];

        // Sem coleta ainda: a chave existe e vem nula — a tela distingue "não
        // tirada" de "chave ausente".
        $this->assertArrayHasKey('fotografia', $props);
        $this->assertNull($props['fotografia']);

        $this->assertNotNull(
            Route::getRoutes()->getByName('onboarding.fotografia.coletar'),
            'sem rota própria, a ficha mostraria um retrato velho sem como atualizá-lo'
        );
    }

    public function test_a_coleta_interna_exige_login(): void
    {
        $this->admin();
        $onboarding = $this->onboardingEmAndamento();

        auth()->logout();

        $this->post(route('onboarding.fotografia.coletar', $onboarding->id))
            ->assertRedirect(route('login'));
    }
}
