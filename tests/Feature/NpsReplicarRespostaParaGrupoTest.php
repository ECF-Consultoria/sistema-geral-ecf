<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\NpsGroupSurvey;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Trava o comando `nps:replicar-resposta-para-grupo` — 2026-08-21.
 *
 * Cenário de origem (grupo Camillo Parts / modelo Shopee): uma empresa do
 * grupo respondeu por link INDIVIDUAL e as demais ficaram sem nota, porque a
 * replicação automática só existe no caminho do link de GRUPO. O comando
 * repara isso depois do fato, produzindo o MESMO resultado que o link de
 * grupo teria produzido.
 *
 * O que estes testes travam:
 *  1. a nota replicada é IDÊNTICA à original e vai para os responsáveis de
 *     CADA empresa (não os da empresa que respondeu);
 *  2. link pendente do mesmo modelo na competência é REAPROVEITADO, não
 *     duplicado — o motivo pelo qual regerar o link de grupo não resolveria;
 *  3. empresa que já respondeu é PULADA (nunca sobrescreve nota existente);
 *  4. `--dry-run` não grava nada;
 *  5. serviço sem responsável grava a nota da empresa mas NÃO inventa
 *     atribuição.
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 */
class NpsReplicarRespostaParaGrupoTest extends TestCase
{
    use RefreshDatabase;

    private NpsTemplate $template;
    private int $servicoId;
    private CompanyGroup $grupo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grupo = CompanyGroup::create(['name' => 'Grupo Teste Replicação']);

        $this->servicoId = DB::table('servicos')->insertGetId([
            'nome'          => 'Serviço Coberto ' . uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => 'mensal',
            'ativo'         => true,
            'setor'         => 'shopee',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->template = NpsTemplate::create([
            'nome'                    => 'Modelo Teste Replicação',
            'active'                  => true,
            'is_default'              => false,
            'envio_automatico_mensal' => false,
        ]);

        DB::table('nps_template_service_scopes')->insert([
            'template_id' => $this->template->id,
            'servico_id'  => $this->servicoId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Uma pergunta por dimensão de pessoa — o divisor do calculator vem do
        // TEMPLATE, então sem pergunta não há nota nem atribuição.
        foreach ([NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA, NpsTemplateQuestion::DIMENSAO_ANALISTA] as $i => $dimensao) {
            $pergunta = NpsTemplateQuestion::create([
                'template_id' => $this->template->id,
                'texto'       => 'Pergunta ' . $dimensao,
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $dimensao,
                'obrigatoria' => true,
                'ordem'       => $i + 1,
            ]);

            NpsTemplateOption::create([
                'question_id' => $pergunta->id,
                'label'       => 'Concordo totalmente',
                'peso'        => 5,
                'ordem'       => 1,
            ]);
        }
    }

    /**
     * Empresa do grupo com contrato ATIVO no serviço coberto e a dupla
     * responsável atribuída NAQUELE serviço — as duas condições que
     * `NpsSnapshotService` exige para gerar atribuição.
     */
    private function criarEmpresa(string $nome, ?User $estrategista, ?User $analista, bool $contratoAtivo = true): Company
    {
        $empresa = Company::factory()->create([
            'active'           => true,
            'company_group_id' => $this->grupo->id,
            'name'             => $nome,
        ]);

        DB::table('contratos_servico')->insert([
            'company_id'       => $empresa->id,
            'servico_id'       => $this->servicoId,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => $contratoAtivo,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        foreach (['estrategista' => $estrategista, 'consultor' => $analista] as $role => $user) {
            if (! $user) {
                continue;
            }

            DB::table('company_users')->insert([
                'company_id' => $empresa->id,
                'user_id'    => $user->id,
                'role'       => $role,
                'servico_id' => $this->servicoId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $empresa;
    }

    /** Survey já RESPONDIDO com nota 5 nas duas dimensões — a origem da replicação. */
    private function criarOrigemRespondida(Company $empresa, User $autor): NpsSurvey
    {
        $survey = NpsSurvey::create([
            'token'          => Str::uuid()->toString(),
            'company_id'     => $empresa->id,
            'generated_by'   => $autor->id,
            'expires_at'     => now()->endOfMonth(),
            'status'         => 'completed',
            'completed_at'   => now(),
            'auto_generated' => false,
            'template_id'    => $this->template->id,
        ]);

        $response = NpsResponse::create([
            'survey_id'       => $survey->id,
            'respondent_name' => 'Cliente do Grupo',
            'comment'         => 'Atendimento excelente',
        ]);

        foreach ($this->template->questions as $pergunta) {
            $opcao = $pergunta->options->first();

            NpsResponseAnswer::create([
                'response_id'                => $response->id,
                'template_question_id'       => $pergunta->id,
                'template_option_id'         => $opcao->id,
                'question_texto_snapshot'    => $pergunta->texto,
                'question_dimensao_snapshot' => $pergunta->dimensao,
                'option_label_snapshot'      => $opcao->label,
                'option_peso_snapshot'       => $opcao->peso,
                'comentario'                 => null,
            ]);
        }

        return $survey;
    }

    private function criarLinkPendente(Company $empresa, User $autor): NpsSurvey
    {
        return NpsSurvey::create([
            'token'          => Str::uuid()->toString(),
            'company_id'     => $empresa->id,
            'generated_by'   => $autor->id,
            'expires_at'     => now()->endOfMonth(),
            'status'         => 'pending',
            'auto_generated' => false,
            'template_id'    => $this->template->id,
        ]);
    }

    #[Test]
    public function replica_a_nota_para_os_responsaveis_de_cada_empresa(): void
    {
        $estrategista = User::factory()->create();
        $analistaA    = User::factory()->create();
        $analistaB    = User::factory()->create();

        // A empresa que respondeu tem um analista; a outra tem OUTRO — a nota
        // replicada precisa ir para o analista de CADA empresa.
        $origemEmpresa = $this->criarEmpresa('Respondeu', $estrategista, $analistaA);
        $alvo          = $this->criarEmpresa('Nao respondeu', $estrategista, $analistaB);

        $origem = $this->criarOrigemRespondida($origemEmpresa, $estrategista);

        $this->artisan('nps:replicar-resposta-para-grupo', [
            '--survey'   => $origem->id,
            '--empresas' => (string) $alvo->id,
            '--force'    => true,
        ])->assertSuccessful();

        $espelho = NpsSurvey::where('company_id', $alvo->id)->firstOrFail();
        $this->assertSame('completed', $espelho->status);

        $responseEspelho = NpsResponse::where('survey_id', $espelho->id)->firstOrFail();

        // Nota idêntica à original, nas duas dimensões.
        $this->assertEqualsWithDelta(
            5.0,
            (float) DB::table('nps_response_scores')
                ->where('nps_response_id', $responseEspelho->id)
                ->where('dimensao', NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA)
                ->value('average_score'),
            0.01,
        );

        // A atribuição do analista é a da empresa ALVO, não a da origem.
        $this->assertTrue(
            NpsScoreAssignment::where('nps_response_id', $responseEspelho->id)
                ->where('role', 'consultor')
                ->where('user_id', $analistaB->id)
                ->exists(),
            'a nota do analista deve ir para o responsável da empresa replicada',
        );

        $this->assertFalse(
            NpsScoreAssignment::where('nps_response_id', $responseEspelho->id)
                ->where('user_id', $analistaA->id)
                ->exists(),
            'o analista da empresa de origem não pode receber a nota da empresa alvo',
        );

        // Vínculo de auditoria: espelho e origem apontam para o mesmo link de grupo.
        $grupoRetroativo = NpsGroupSurvey::where('company_group_id', $this->grupo->id)->firstOrFail();
        $this->assertSame('completed', $grupoRetroativo->status);
        $this->assertSame($grupoRetroativo->id, $espelho->fresh()->group_survey_id);
        $this->assertSame($grupoRetroativo->id, $origem->fresh()->group_survey_id);
    }

    #[Test]
    public function reaproveita_o_link_pendente_em_vez_de_criar_outro(): void
    {
        $estrategista = User::factory()->create();
        $analista     = User::factory()->create();

        $origemEmpresa = $this->criarEmpresa('Respondeu', $estrategista, $analista);
        $alvo          = $this->criarEmpresa('Tem link pendente', $estrategista, $analista);

        $origem    = $this->criarOrigemRespondida($origemEmpresa, $estrategista);
        $pendente  = $this->criarLinkPendente($alvo, $estrategista);

        $this->artisan('nps:replicar-resposta-para-grupo', [
            '--survey'   => $origem->id,
            '--empresas' => (string) $alvo->id,
            '--force'    => true,
        ])->assertSuccessful();

        $this->assertSame(
            1,
            NpsSurvey::where('company_id', $alvo->id)->count(),
            'não pode nascer um segundo link para a empresa que já tinha um pendente',
        );

        $this->assertSame('completed', $pendente->fresh()->status);
    }

    #[Test]
    public function pula_empresa_que_ja_respondeu_o_modelo_na_competencia(): void
    {
        $estrategista = User::factory()->create();
        $analista     = User::factory()->create();

        $origemEmpresa = $this->criarEmpresa('Respondeu', $estrategista, $analista);
        $alvo          = $this->criarEmpresa('Tambem respondeu', $estrategista, $analista);

        $origem       = $this->criarOrigemRespondida($origemEmpresa, $estrategista);
        $jaRespondido = $this->criarOrigemRespondida($alvo, $estrategista);

        $this->artisan('nps:replicar-resposta-para-grupo', [
            '--survey'   => $origem->id,
            '--empresas' => (string) $alvo->id,
            '--force'    => true,
        ])->assertSuccessful();

        $this->assertSame(
            1,
            NpsResponse::whereIn('survey_id', NpsSurvey::where('company_id', $alvo->id)->pluck('id'))->count(),
            'nunca sobrescrever nem duplicar nota de quem já respondeu',
        );

        $this->assertSame(1, NpsSurvey::where('company_id', $alvo->id)->count());
        $this->assertNotNull($jaRespondido->fresh());
    }

    #[Test]
    public function dry_run_nao_grava_nada(): void
    {
        $estrategista = User::factory()->create();
        $analista     = User::factory()->create();

        $origemEmpresa = $this->criarEmpresa('Respondeu', $estrategista, $analista);
        $alvo          = $this->criarEmpresa('Nao respondeu', $estrategista, $analista);

        $origem = $this->criarOrigemRespondida($origemEmpresa, $estrategista);

        $this->artisan('nps:replicar-resposta-para-grupo', [
            '--survey'   => $origem->id,
            '--empresas' => (string) $alvo->id,
            '--dry-run'  => true,
        ])->assertSuccessful();

        $this->assertSame(0, NpsSurvey::where('company_id', $alvo->id)->count());
        $this->assertSame(0, NpsGroupSurvey::where('company_group_id', $this->grupo->id)->count());
        $this->assertNull($origem->fresh()->group_survey_id);
    }

    #[Test]
    public function empresa_sem_responsavel_recebe_a_nota_mas_nao_gera_atribuicao(): void
    {
        $estrategista = User::factory()->create();
        $analista     = User::factory()->create();

        $origemEmpresa = $this->criarEmpresa('Respondeu', $estrategista, $analista);
        $orfa          = $this->criarEmpresa('Sem responsavel', null, null);

        $origem = $this->criarOrigemRespondida($origemEmpresa, $estrategista);

        $this->artisan('nps:replicar-resposta-para-grupo', [
            '--survey'   => $origem->id,
            '--empresas' => (string) $orfa->id,
            '--force'    => true,
        ])->assertSuccessful();

        $espelho  = NpsSurvey::where('company_id', $orfa->id)->firstOrFail();
        $response = NpsResponse::where('survey_id', $espelho->id)->firstOrFail();

        $this->assertTrue(
            DB::table('nps_response_scores')->where('nps_response_id', $response->id)->exists(),
            'a nota da empresa é congelada mesmo sem responsável',
        );

        $this->assertSame(
            0,
            NpsScoreAssignment::where('nps_response_id', $response->id)->count(),
            'sem responsável no serviço, nenhuma atribuição pode ser inventada',
        );
    }

    #[Test]
    public function pula_empresa_sem_contrato_ativo_no_servico_coberto(): void
    {
        $estrategista = User::factory()->create();
        $analista     = User::factory()->create();

        $origemEmpresa = $this->criarEmpresa('Respondeu', $estrategista, $analista);
        $semContrato   = $this->criarEmpresa('Contrato inativo', $estrategista, $analista, contratoAtivo: false);

        $origem = $this->criarOrigemRespondida($origemEmpresa, $estrategista);

        $this->artisan('nps:replicar-resposta-para-grupo', [
            '--survey'   => $origem->id,
            '--empresas' => (string) $semContrato->id,
            '--force'    => true,
        ])->assertSuccessful();

        $this->assertSame(0, NpsSurvey::where('company_id', $semContrato->id)->count());
    }
}
