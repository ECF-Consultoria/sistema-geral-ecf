<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\NpsGroupSurvey;
use App\Models\NpsTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\TestCase;

/**
 * Bug reportado em 2026-08-20 (grupo MaxiGold, 5 empresas): "quando vou gerar
 * o link fala que já tem um link, porém a empresa está em Faltantes, não em
 * Pendentes".
 *
 * As duas telas estavam certas isoladamente, e é isso que torna o caso
 * traiçoeiro: os surveys-espelho (`nps_surveys`) só nascem quando o cliente
 * RESPONDE o link de grupo (`NpsGrupoReplicacaoService`). Até lá o link vive
 * só em `nps_group_surveys` — e as duas réguas da tela de NPS liam apenas
 * `nps_surveys`. Resultado: as empresas cobertas ficavam em Faltantes, nada
 * aparecia em Pendentes, e o guard de duplicidade de
 * `NpsGrupoController::generate()` recusava gerar de novo, devolvendo o link
 * existente numa chave de flash que `HandleInertiaRequests` nunca
 * compartilhou. O link certo não existia em lugar nenhum da tela.
 *
 * O que estes testes travam:
 *  1. empresa coberta por link de grupo VIVO sai de Faltantes;
 *  2. o link vira UMA linha em Pendentes, com endereço, cobertura e contagem
 *     de empresas (chip soma EMPRESAS, não linhas — DQ-03);
 *  3. empresa do grupo que ficou FORA da cobertura continua faltante;
 *  4. `flash.nps_link_existente` chega ao front (senão o aviso de duplicidade
 *     volta a ser um beco sem saída).
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 */
class NpsLinkDeGrupoNaTelaTest extends TestCase
{
    use RefreshDatabase;
    use ContrataServicoNpsCoberto;

    private function templatePadrao(): NpsTemplate
    {
        return NpsTemplate::where('is_default', true)->firstOrFail();
    }

    /**
     * Empresa ativa do grupo, com contrato ativo no serviço coberto pelo
     * modelo padrão e a dupla responsável atribuída NAQUELE serviço — é o
     * cenário em que `NpsGrupoCoberturaService` inclui a empresa no link.
     */
    private function criarEmpresaDoGrupo(
        CompanyGroup $grupo,
        User $estrategista,
        ?User $analista,
        string $nome,
    ): Company {
        $empresa = Company::factory()->create([
            'active'           => true,
            'company_group_id' => $grupo->id,
            'name'             => $nome,
        ]);

        $servico = $this->contratarServicoNpsCoberto($empresa);

        DB::table('company_users')->insert([
            'company_id' => $empresa->id, 'user_id' => $estrategista->id, 'role' => 'estrategista',
            'servico_id' => $servico, 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($analista) {
            DB::table('company_users')->insert([
                'company_id' => $empresa->id, 'user_id' => $analista->id, 'role' => 'consultor',
                'servico_id' => $servico, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $empresa;
    }

    private function gerarLinkDeGrupo(CompanyGroup $grupo, User $autor, ?NpsTemplate $template = null): NpsGroupSurvey
    {
        return NpsGroupSurvey::create([
            'token'            => Str::random(40),
            'company_group_id' => $grupo->id,
            'template_id'      => ($template ?? $this->templatePadrao())->id,
            'generated_by'     => $autor->id,
            'month_reference'  => now()->startOfMonth(),
            'status'           => 'pending',
            'expires_at'       => now()->endOfMonth(),
        ]);
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

    /** Todas as empresas citadas na aba Faltantes, inclusive as colapsadas em linha de grupo. */
    private function empresasEmFaltantes(array $props): array
    {
        return collect($props['faltantes'])
            ->flatMap(fn ($f) => ($f['tipo'] ?? 'empresa') === 'grupo'
                ? ($f['empresas_nomes'] ?? [])
                : [$f['name']])
            ->all();
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — o link de grupo vivo tira as empresas de Faltantes
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_sem_link_de_grupo_as_empresas_aparecem_em_faltantes(): void
    {
        // Controle do teste seguinte: sem o link, o estado antigo se mantém —
        // é o que prova que a mudança de coluna veio do link, e não de algum
        // outro filtro do caminho.
        $admin        = User::factory()->create(['role' => 'admin', 'active' => true]);
        $estrategista = User::factory()->create(['active' => true]);
        $analista     = User::factory()->create(['active' => true]);

        $grupo = CompanyGroup::create(['name' => 'MaxiGold']);
        $this->criarEmpresaDoGrupo($grupo, $estrategista, $analista, 'MAXIGOLD SUPLEMENTOS');
        $this->criarEmpresaDoGrupo($grupo, $estrategista, $analista, 'Chikweb');

        $props = $this->props($admin);

        $this->assertEqualsCanonicalizing(
            ['MAXIGOLD SUPLEMENTOS', 'Chikweb'],
            $this->empresasEmFaltantes($props),
        );
        $this->assertSame(2, $props['contadores']['faltantes']);
        $this->assertSame(0, $props['contadores']['pendentes']);
    }

    #[Test]
    public function test_empresa_coberta_por_link_de_grupo_sai_de_faltantes_e_vira_linha_pendente(): void
    {
        $admin        = User::factory()->create(['role' => 'admin', 'active' => true]);
        $estrategista = User::factory()->create(['active' => true]);
        $analista     = User::factory()->create(['active' => true]);

        $grupo = CompanyGroup::create(['name' => 'MaxiGold']);
        $this->criarEmpresaDoGrupo($grupo, $estrategista, $analista, 'MAXIGOLD SUPLEMENTOS');
        $this->criarEmpresaDoGrupo($grupo, $estrategista, $analista, 'Chikweb');

        $link = $this->gerarLinkDeGrupo($grupo, $admin);

        $props = $this->props($admin);

        // Nenhuma das duas continua sendo cobrada como "sem link".
        $this->assertSame([], $this->empresasEmFaltantes($props));
        $this->assertSame(0, $props['contadores']['faltantes']);

        // O link virou UMA linha da listagem, com a cobertura explícita.
        $linhas = collect($props['surveys']['data'])->where('tipo', 'grupo')->values();
        $this->assertCount(1, $linhas);

        $linha = $linhas->first();
        $this->assertSame('MaxiGold', $linha['company_name']);
        $this->assertSame('pending', $linha['status']);
        $this->assertSame(2, $linha['empresas_count']);
        $this->assertTrue($linha['de_grupo']);
        $this->assertEqualsCanonicalizing(
            ['MAXIGOLD SUPLEMENTOS', 'Chikweb'],
            $linha['empresas_nomes'],
        );
        $this->assertStringContainsString($link->token, $linha['link']);
        $this->assertSame($admin->name, $linha['generated_by']);

        // DQ-03 — os chips somam EMPRESAS, nunca linhas: as 2 que saíram de
        // Faltantes entram em Pendentes, e "Todos" não muda de tamanho.
        $this->assertSame(2, $props['contadores']['pendentes']);
        $this->assertSame(2, $props['contadores']['todos']);
    }

    #[Test]
    public function test_empresa_do_grupo_fora_da_cobertura_continua_faltante(): void
    {
        // A régua de quem entra no link é a do envio (NpsGrupoCoberturaService):
        // responsável diferente fica de fora do link e, portanto, continua
        // precisando de link próprio. Sair de Faltantes só vale para quem o
        // link realmente cobre.
        $admin        = User::factory()->create(['role' => 'admin', 'active' => true]);
        $estrategista = User::factory()->create(['active' => true]);
        $analista     = User::factory()->create(['active' => true]);
        $outroAnalista = User::factory()->create(['active' => true]);

        $grupo = CompanyGroup::create(['name' => 'MaxiGold']);
        $this->criarEmpresaDoGrupo($grupo, $estrategista, $analista, 'MAXIGOLD SUPLEMENTOS');
        $this->criarEmpresaDoGrupo($grupo, $estrategista, $analista, 'Chikweb');
        $this->criarEmpresaDoGrupo($grupo, $estrategista, $outroAnalista, 'Nutrifour');

        $this->gerarLinkDeGrupo($grupo, $admin);

        $props = $this->props($admin);

        $this->assertSame(['Nutrifour'], $this->empresasEmFaltantes($props));
        $this->assertSame(1, $props['contadores']['faltantes']);
        $this->assertSame(2, $props['contadores']['pendentes']);

        $linha = collect($props['surveys']['data'])->firstWhere('tipo', 'grupo');
        $this->assertSame(2, $linha['empresas_count']);
        $this->assertNotContains('Nutrifour', $linha['empresas_nomes']);
    }

    #[Test]
    public function test_link_de_grupo_ja_respondido_nao_vira_linha_propria(): void
    {
        // Respondido = os espelhos existem, e cada empresa já aparece sozinha
        // na listagem. Uma linha de grupo aqui seria contagem em dobro.
        $admin        = User::factory()->create(['role' => 'admin', 'active' => true]);
        $estrategista = User::factory()->create(['active' => true]);
        $analista     = User::factory()->create(['active' => true]);

        $grupo = CompanyGroup::create(['name' => 'MaxiGold']);
        $this->criarEmpresaDoGrupo($grupo, $estrategista, $analista, 'MAXIGOLD SUPLEMENTOS');
        $this->criarEmpresaDoGrupo($grupo, $estrategista, $analista, 'Chikweb');

        $link = $this->gerarLinkDeGrupo($grupo, $admin);
        $link->update(['status' => 'completed', 'completed_at' => now()]);

        $props = $this->props($admin);

        $this->assertSame([], collect($props['surveys']['data'])->where('tipo', 'grupo')->all());
        $this->assertSame(0, $props['contadores']['pendentes']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — o aviso de duplicidade devolve o link que já existe
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_guard_de_duplicidade_entrega_o_link_existente_ao_front(): void
    {
        // Sem esta chave compartilhada, o operador via "este grupo já tem um
        // link deste modelo neste mês" e mais nada: não conseguia copiar o
        // link existente nem gerar outro (o guard barra) — o beco sem saída
        // relatado no grupo MaxiGold.
        $admin        = User::factory()->create(['role' => 'admin', 'active' => true]);
        $estrategista = User::factory()->create(['active' => true]);
        $analista     = User::factory()->create(['active' => true]);

        $grupo = CompanyGroup::create(['name' => 'MaxiGold']);
        $this->criarEmpresaDoGrupo($grupo, $estrategista, $analista, 'MAXIGOLD SUPLEMENTOS');
        $this->criarEmpresaDoGrupo($grupo, $estrategista, $analista, 'Chikweb');

        $link = $this->gerarLinkDeGrupo($grupo, $admin);

        $this->actingAs($admin)
            ->from(route('nps.index'))
            ->post(route('nps.grupo.generate'), [
                'company_group_id' => $grupo->id,
                'template_id'      => $this->templatePadrao()->id,
            ])
            ->assertRedirect(route('nps.index'))
            ->assertSessionHas('nps_link_existente');

        $this->actingAs($admin)
            ->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(function (Assert $page) use ($link) {
                $page->where(
                    'flash.nps_link_existente',
                    fn ($url) => is_string($url) && str_contains($url, $link->token),
                );
            });
    }
}
