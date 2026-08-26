<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\NpsGroupSurvey;
use App\Models\NpsImputedAssignment;
use App\Models\NpsTemplate;
use App\Models\User;
use App\Services\Nps\NpsImputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\TestCase;

/**
 * Bug reportado em 2026-08-26 (grupo Utilar, 5 empresas): "foi gerado NPS do
 * grupo mas nao contabiliza 1 ponto para a carteira do responsavel".
 *
 * A regua "NPS disparado e nao respondido vale nota 1 desde o disparo" (Fase
 * 116, D2) era materializada por `NpsImputationService::materializar()`, que
 * recebe um `NpsSurvey`. So que o link de GRUPO nao produz `nps_surveys`: os
 * espelhos so nascem quando o cliente RESPONDE
 * (`NpsGrupoReplicacaoService::replicar()`). Entre gerar e responder, o link
 * vivia so em `nps_group_surveys` — invisivel para a imputacao e para o cron
 * diario, que varrem `nps_surveys`.
 *
 * Consequencia medida em producao: 4 links de grupo pendentes, 9 empresas sem
 * o piso, 7 linhas de nota 1 faltando para cada uma das duas responsaveis do
 * grupo Utilar. Um link INDIVIDUAL do mesmo modelo materializava normalmente
 * (17 surveys pendentes geraram 49 linhas), o que descartou a flag
 * `envio_automatico_mensal` como causa.
 *
 * O que estes testes travam:
 *  1. gerar o link de grupo cria o piso para TODAS as empresas cobertas;
 *  2. a leitura enxerga uma nota POR EMPRESA — a dedupe nao pode colapsar o
 *     grupo inteiro numa nota so (o `survey_id` e NULL em todas elas);
 *  3. empresa fora da cobertura nao ganha piso;
 *  4. grupo respondido nao deixa piso para tras — senao a empresa contaria a
 *     nota 1 E a nota real na mesma competencia.
 *
 * Comentarios e nomes de teste em pt-BR, conforme convencao do projeto.
 */
class NpsPisoLinkDeGrupoTest extends TestCase
{
    use RefreshDatabase;
    use ContrataServicoNpsCoberto;

    private function templatePadrao(): NpsTemplate
    {
        return NpsTemplate::where('is_default', true)->firstOrFail();
    }

    /**
     * Empresa ativa do grupo, com contrato ativo no servico coberto pelo
     * modelo e a dupla responsavel atribuida NAQUELE servico — o cenario em
     * que `NpsGrupoCoberturaService` inclui a empresa no link.
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

    private function gerarLinkDeGrupo(CompanyGroup $grupo, User $autor): NpsGroupSurvey
    {
        return NpsGroupSurvey::create([
            'token'            => Str::random(40),
            'company_group_id' => $grupo->id,
            'template_id'      => $this->templatePadrao()->id,
            'generated_by'     => $autor->id,
            'month_reference'  => now()->startOfMonth(),
            'status'           => 'pending',
            'expires_at'       => now()->endOfMonth(),
        ]);
    }

    /**
     * @return array{0: CompanyGroup, 1: User, 2: User, 3: array<int, Company>}
     */
    private function cenarioGrupoComTresEmpresas(): array
    {
        $grupo        = CompanyGroup::create(['name' => 'Grupo Piso ' . uniqid()]);
        $estrategista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $analista     = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $empresas = [
            $this->criarEmpresaDoGrupo($grupo, $estrategista, $analista, 'Piso Um'),
            $this->criarEmpresaDoGrupo($grupo, $estrategista, $analista, 'Piso Dois'),
            $this->criarEmpresaDoGrupo($grupo, $estrategista, $analista, 'Piso Tres'),
        ];

        return [$grupo, $estrategista, $analista, $empresas];
    }

    #[Test]
    public function test_link_de_grupo_pendente_materializa_o_piso_para_todas_as_empresas_cobertas(): void
    {
        [$grupo, $estrategista, , $empresas] = $this->cenarioGrupoComTresEmpresas();

        $link = $this->gerarLinkDeGrupo($grupo, $estrategista);

        app(NpsImputationService::class)->materializarGrupo($link);

        foreach ($empresas as $empresa) {
            $this->assertTrue(
                NpsImputedAssignment::where('group_survey_id', $link->id)
                    ->where('company_id', $empresa->id)
                    ->where('status', NpsImputedAssignment::STATUS_PROVISORIO)
                    ->exists(),
                "a empresa {$empresa->name}, coberta pelo link de grupo, precisa constar com o piso de 1."
            );
        }

        // A ancora e o link de grupo, nunca um survey — o espelho so nasce na
        // resposta, e inventar um aqui recriaria o link individual que o
        // usuario recusou.
        $this->assertSame(
            0,
            NpsImputedAssignment::where('group_survey_id', $link->id)->whereNotNull('survey_id')->count(),
            'linha de piso de grupo nao pode estar amarrada a nenhum survey individual.'
        );
    }

    #[Test]
    public function test_a_leitura_devolve_uma_nota_por_empresa_e_nao_colapsa_o_grupo(): void
    {
        [$grupo, $estrategista, , $empresas] = $this->cenarioGrupoComTresEmpresas();

        $link = $this->gerarLinkDeGrupo($grupo, $estrategista);
        app(NpsImputationService::class)->materializarGrupo($link);

        $notas = app(NpsImputationService::class)->notasDoUsuario(
            $estrategista,
            now()->startOfMonth(),
            now()->endOfMonth(),
        );

        // Regua de dedupe antiga era `survey_id|role`; como TODA linha de
        // grupo tem `survey_id` NULL, ela reduziria as 3 empresas a 1 nota.
        $this->assertCount(
            3,
            $notas->pluck('company_id')->unique(),
            'cada empresa coberta pesa a propria nota 1 — o link de grupo nao e uma nota so.'
        );

        foreach ($empresas as $empresa) {
            $this->assertTrue(
                $notas->contains(fn ($n) => $n->company_id === $empresa->id && $n->nota === 1.0),
                "a empresa {$empresa->name} precisa aparecer na leitura com nota 1."
            );
        }
    }

    #[Test]
    public function test_empresa_fora_da_cobertura_nao_ganha_piso(): void
    {
        [$grupo, $estrategista] = $this->cenarioGrupoComTresEmpresas();

        // Do grupo, ativa, mas sem contrato em servico coberto e sem
        // responsavel — sai da cobertura por `sem_servico_contratado`.
        $forasteira = Company::factory()->create([
            'active'           => true,
            'company_group_id' => $grupo->id,
            'name'             => 'Fora da Cobertura',
        ]);

        $link = $this->gerarLinkDeGrupo($grupo, $estrategista);
        app(NpsImputationService::class)->materializarGrupo($link);

        $this->assertFalse(
            NpsImputedAssignment::where('company_id', $forasteira->id)->exists(),
            'empresa que o link de grupo nao cobre nao pode pesar nota 1 por causa dele.'
        );
    }

    #[Test]
    public function test_grupo_respondido_nao_deixa_piso_para_tras(): void
    {
        [$grupo, $estrategista] = $this->cenarioGrupoComTresEmpresas();

        $link      = $this->gerarLinkDeGrupo($grupo, $estrategista);
        $imputacao = app(NpsImputationService::class);

        $imputacao->materializarGrupo($link);
        $this->assertGreaterThan(0, NpsImputedAssignment::where('group_survey_id', $link->id)->count());

        // Resposta chegou: os espelhos reais assumem. Manter o piso faria a
        // empresa contar a nota 1 E a nota real na mesma competencia.
        $link->update(['status' => 'completed', 'completed_at' => now()]);
        $imputacao->materializarGrupo($link);

        $this->assertSame(
            0,
            NpsImputedAssignment::where('group_survey_id', $link->id)->count(),
            'link de grupo respondido nao pode deixar nenhuma linha de piso viva.'
        );
    }

    #[Test]
    public function test_leitura_ja_ignora_o_piso_de_um_grupo_respondido_antes_da_limpeza(): void
    {
        [$grupo, $estrategista] = $this->cenarioGrupoComTresEmpresas();

        $link = $this->gerarLinkDeGrupo($grupo, $estrategista);
        app(NpsImputationService::class)->materializarGrupo($link);

        // Janela entre a resposta chegar e a limpeza rodar: o scope `vigentes`
        // precisa proteger a leitura sozinho, como ja fazia para o individual.
        $link->update(['status' => 'completed', 'completed_at' => now()]);

        $notas = app(NpsImputationService::class)->notasDoUsuario(
            $estrategista,
            now()->startOfMonth(),
            now()->endOfMonth(),
        );

        $this->assertCount(0, $notas, 'piso de grupo respondido nao pode ser lido, mesmo antes da limpeza.');
    }
}
