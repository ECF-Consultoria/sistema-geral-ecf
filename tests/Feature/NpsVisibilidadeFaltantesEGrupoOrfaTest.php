<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\NpsTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\TestCase;

/**
 * Bug reportado em 2026-08-18 por uma estrategista, com dois sintomas que
 * pareciam separados e tinham a mesma origem — a tela decidindo o que MOSTRAR
 * com réguas que existiam para outra coisa:
 *
 *  (1) "não aparece a empresa para gerar o link do grupo, mas para o admin
 *      aparece" — o grupo dela tinha 5 empresas, 4 sob sua responsabilidade e
 *      1 recém-importada sem nenhum responsável. A régua era tudo-ou-nada, e
 *      a órfã derrubava o grupo inteiro: medido em produção, ZERO não-admins
 *      enxergavam aquele grupo;
 *
 *  (2) "tem empresa que não aparece como pendente, nem nada" — em 11/08 os
 *      modelos de Performance foram refeitos sem `envio_automatico_mensal`, e
 *      a aba Faltantes usava essa flag como se fosse "deveria ter NPS". O
 *      setor performance inteiro sumiu da lista de trabalho: 102 empresas,
 *      12 só na carteira de quem reportou.
 *
 * O que estes testes travam, além do fix: que a correção de VISIBILIDADE não
 * arrastou a régua da NOTA 1 junto (`conta_nota_1` continua exigindo modelo
 * com envio automático). Mexer nela reescreve nota de competência fechada, e
 * isso é decisão de negócio — não efeito colateral de um fix de tela.
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 */
class NpsVisibilidadeFaltantesEGrupoOrfaTest extends TestCase
{
    use RefreshDatabase;
    use ContrataServicoNpsCoberto;

    private function vincular(Company $company, User $user, string $role, ?int $servicoId = null): void
    {
        DB::table('company_users')->insert([
            'company_id' => $company->id,
            'user_id'    => $user->id,
            'role'       => $role,
            'servico_id' => $servicoId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Reproduz o estado de produção pós-11/08: modelo ativo, sem envio automático. */
    private function desligarEnvioAutomatico(): void
    {
        NpsTemplate::query()->update(['envio_automatico_mensal' => false]);
    }

    private function props(User $user, array $query = []): array
    {
        $props = null;

        $this->actingAs($user)
            ->get(route('nps.index', $query + ['template_id' => '__todos__']))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Nps/Index');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — Faltantes deixou de depender de `envio_automatico_mensal`
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_aparece_em_faltantes_mesmo_com_modelo_sem_envio_automatico(): void
    {
        $admin   = User::factory()->create(['role' => 'admin', 'active' => true]);
        $empresa = Company::factory()->create(['active' => true, 'name' => 'Loja Sem Link']);
        $servico = $this->contratarServicoNpsCoberto($empresa);
        $this->vincular($empresa, User::factory()->create(), 'estrategista', $servico);

        $this->desligarEnvioAutomatico();

        $props = $this->props($admin);

        $this->assertSame(1, $props['contadores']['faltantes'],
            'a lista de trabalho não pode depender da flag de DISPARO — foi assim que 102 empresas sumiram da tela.');
        $this->assertSame($empresa->id, $props['faltantes'][0]['company_id']);
    }

    #[Test]
    public function test_empresa_sem_estrategista_continua_fora_de_faltantes(): void
    {
        $admin   = User::factory()->create(['role' => 'admin', 'active' => true]);
        $empresa = Company::factory()->create(['active' => true]);
        $this->contratarServicoNpsCoberto($empresa); // sem nenhum responsável

        $this->desligarEnvioAutomatico();

        $props = $this->props($admin);

        $this->assertSame(0, $props['contadores']['faltantes'],
            'o guard D-07 (empresa sem estrategista fica fora) não podia cair junto com a flag.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — a régua da NOTA 1 ficou onde estava (gate do bônus)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_faltante_de_modelo_sem_envio_automatico_nao_pesa_nota_1(): void
    {
        $admin   = User::factory()->create(['role' => 'admin', 'active' => true]);
        $empresa = Company::factory()->create(['active' => true]);
        $servico = $this->contratarServicoNpsCoberto($empresa);
        $this->vincular($empresa, User::factory()->create(), 'estrategista', $servico);

        $this->desligarEnvioAutomatico();

        // Ciclo fechado é a condição para a nota 1 valer — sem isto o teste
        // passaria por motivo errado (janela aberta nunca penaliza ninguém).
        $this->actingAs($admin)->post(route('nps.ciclo.fechar'), ['mes' => now()->format('Y-m')]);

        $props = $this->props($admin);

        $this->assertSame(1, $props['contadores']['faltantes'], 'a empresa aparece na lista de trabalho...');
        $this->assertFalse($props['faltantes'][0]['conta_nota_1'], '...mas NÃO passa a pesar nota 1 por causa deste fix.');
        $this->assertSame(0, $props['contadores']['contam_nota_1']);
    }

    #[Test]
    public function test_faltante_de_modelo_com_envio_automatico_continua_pesando_nota_1(): void
    {
        $admin   = User::factory()->create(['role' => 'admin', 'active' => true]);
        $empresa = Company::factory()->create(['active' => true]);
        $servico = $this->contratarServicoNpsCoberto($empresa);
        $this->vincular($empresa, User::factory()->create(), 'estrategista', $servico);

        // Aqui a flag fica LIGADA (estado do seed) — controle do teste acima.
        $this->actingAs($admin)->post(route('nps.ciclo.fechar'), ['mes' => now()->format('Y-m')]);

        $props = $this->props($admin);

        $this->assertTrue($props['faltantes'][0]['conta_nota_1'],
            'a régua antiga da nota 1 tem que continuar valendo intacta.');
        $this->assertSame(1, $props['contadores']['contam_nota_1']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — grupo com empresa órfã volta a aparecer para quem cuida das demais
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_grupo_com_empresa_sem_responsavel_aparece_para_quem_cuida_das_demais(): void
    {
        $grupo = CompanyGroup::create(['name' => 'MaxiGold']);
        $eu    = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $minha = Company::factory()->create(['active' => true, 'company_group_id' => $grupo->id]);
        Company::factory()->create(['active' => true, 'company_group_id' => $grupo->id]); // órfã

        $this->vincular($minha, $eu, 'estrategista');

        $props = $this->props($eu);

        $this->assertSame([$grupo->id], collect($props['grupos'])->pluck('id')->all(),
            'empresa sem responsável é cadastro não distribuído, não carteira de outra pessoa.');
    }

    #[Test]
    public function test_grupo_com_empresa_de_outra_pessoa_continua_oculto(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo Misto']);
        $eu    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $outro = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $minha  = Company::factory()->create(['active' => true, 'company_group_id' => $grupo->id]);
        $alheia = Company::factory()->create(['active' => true, 'company_group_id' => $grupo->id]);

        $this->vincular($minha, $eu, 'estrategista');
        $this->vincular($alheia, $outro, 'estrategista');

        $props = $this->props($eu);

        $this->assertSame([], collect($props['grupos'])->pluck('id')->all(),
            'o tudo-ou-nada continua valendo para empresa que É de outra pessoa.');
    }

    #[Test]
    public function test_grupo_sem_nenhuma_empresa_do_usuario_nao_aparece(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Só Órfãs']);
        $eu    = User::factory()->create(['role' => 'consultor', 'active' => true]);

        Company::factory()->create(['active' => true, 'company_group_id' => $grupo->id]);
        Company::factory()->create(['active' => true, 'company_group_id' => $grupo->id]);

        // A pessoa precisa ter carteira para a tela abrir com sentido, mas
        // nenhuma empresa DESTE grupo é dela.
        $this->vincular(Company::factory()->create(['active' => true]), $eu, 'estrategista');

        $props = $this->props($eu);

        $this->assertSame([], collect($props['grupos'])->pluck('id')->all(),
            'grupo 100% órfão não pode aparecer para todo mundo.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — a órfã fica de fora do LINK, com motivo próprio na prévia
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_previa_de_cobertura_exclui_a_empresa_sem_responsavel_com_motivo_proprio(): void
    {
        $grupo = CompanyGroup::create(['name' => 'MaxiGold']);
        $eu    = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $minha = Company::factory()->create(['active' => true, 'company_group_id' => $grupo->id, 'name' => 'Maxigold Suplementos']);
        $orfa  = Company::factory()->create(['active' => true, 'company_group_id' => $grupo->id, 'name' => 'Maxi Gold Suplementos']);

        $template = (int) NpsTemplate::where('is_default', true)->value('id');
        $servico  = $this->contratarServicoNpsCoberto($minha, $template);
        $this->contratarServicoNpsCoberto($orfa, $template);
        $this->vincular($minha, $eu, 'estrategista', $servico);

        $resposta = $this->actingAs($eu)
            ->getJson(route('nps.grupo.cobertura', ['grupo' => $grupo->id, 'template' => $template]))
            ->assertOk()
            ->json();

        $this->assertSame([$minha->id], collect($resposta['incluidas'])->pluck('company_id')->all(),
            'a nota do grupo só pode ir para empresa que tem a quem ser atribuída.');

        $excluida = collect($resposta['excluidas'])->firstWhere('company_id', $orfa->id);
        $this->assertNotNull($excluida, 'a órfã tem que aparecer explicada na prévia, não sumir calada.');
        $this->assertSame('sem_responsavel', $excluida['motivo'],
            'antes caía em responsavel_diferente e a tela escrevia "é cuidado por outra pessoa ()".');
    }
}
