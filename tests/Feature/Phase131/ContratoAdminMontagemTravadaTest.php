<?php

namespace Tests\Feature\Phase131;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Quick 260820-my3 (Tarefa 2) — prova o sub-estado irmão de `preparando`:
 * `ContratoAssinatura::estaMontagemTravada()` (rascunho + envelope vazio +
 * FORA da janela de `JANELA_PREPARANDO_MINUTOS`).
 *
 * Incidente que originou (2026-08-20, produção): contrato da Maderatto
 * (id=6) ficou >1h em `rascunho` sem `clicksign_envelope_id`, preso na fila
 * `default` atrás de dezenas de `SyncMlAcervoCompanyJob`. Passada a janela
 * de "preparando" (5min), a tela caía de volta no rótulo de `rascunho`
 * ("Falta enviar") — a mesma leitura ERRADA que mandou o usuário procurar
 * na Clicksign, onde não havia nada.
 *
 * Mesma disciplina de `ContratoAdminPreparandoTest`: conferência por
 * reconsulta às props Inertia, nunca por stdout.
 */
class ContratoAdminMontagemTravadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        // Mesma blindagem do resto da Fase 131 — ver ContratoAdminPreparandoTest.
        config(['services.clicksign.signatarios_ecf' => []]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (montagem travada)'): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    private function empresa(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge(['active' => true], $overrides));
    }

    /**
     * `withoutEvents`: sem isto o `ContratoServicoGatilhoObserver` (Fase
     * 128) dispara como efeito colateral do setup — mesmo cuidado de
     * `ContratoAdminPreparandoTest`.
     */
    private function vincularServico(Company $c, Servico $s): ContratoServico
    {
        return ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]));
    }

    /**
     * Recua `created_at` por QUERY BUILDER, nunca por `save()` — mesmo
     * atalho e mesma ressalva de `ContratoAdminPreparandoTest::recuarCriacao()`.
     */
    private function recuarCriacao(ContratoAssinatura $contrato, int $minutos): ContratoAssinatura
    {
        ContratoAssinatura::where('id', $contrato->id)->update([
            'created_at' => now()->subMinutes($minutos),
        ]);

        return $contrato->refresh();
    }

    // ─── Cenário — rascunho + envelope NULL + FORA da janela → travado, e NUNCA "falta enviar" ───

    public function test_show_contrato_rascunho_sem_envelope_fora_da_janela_esta_travado(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresa(['name' => 'Empresa Montagem Travada']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $contrato = ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => null,
        ]);
        $this->recuarCriacao($contrato, 30);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['contratos'][0]['montagem_travada']);
        // Mutuamente exclusivo: nunca os dois `true` ao mesmo tempo.
        $this->assertFalse($props['contratos'][0]['preparando']);
    }

    // ─── Cenário — rascunho + envelope NULL + DENTRO da janela → nunca travado (regressão de estaPreparando) ───

    public function test_show_contrato_rascunho_sem_envelope_dentro_da_janela_nao_esta_travado(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresa(['name' => 'Empresa Preparando Nao Travada']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => null,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['contratos'][0]['preparando']);
        $this->assertFalse($props['contratos'][0]['montagem_travada']);
    }

    // ─── Cenário — rascunho + envelope PREENCHIDO → nunca travado, independente da idade ───

    public function test_show_contrato_rascunho_com_envelope_preenchido_nunca_esta_travado(): void
    {
        $admin   = $this->admin();
        $empresa = $this->empresa(['name' => 'Empresa Rascunho Com Envelope Antigo']);
        $servico = $this->servicoComContrato();
        $this->vincularServico($empresa, $servico);

        $contrato = ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => 'env-de-teste-xyz789',
        ]);
        // Idade não importa quando o envelope existe — recua bem além da janela.
        $this->recuarCriacao($contrato, 60 * 24);

        $response = $this->actingAs($admin)->get(route('admin.contratos.show', $empresa));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertFalse($props['contratos'][0]['montagem_travada']);
        $this->assertFalse($props['contratos'][0]['preparando']);
    }

    // ─── Cenário — index(): par sem contrato traz montagem_travada false explícito ───

    public function test_index_par_sem_contrato_traz_montagem_travada_false(): void
    {
        $admin   = $this->admin();
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Sem Contrato Travada']);
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($admin)->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $linhas = collect($props['linhas']['data']);

        $linha = $linhas->firstWhere('company_id', $empresa->id);

        $this->assertNotNull($linha);
        $this->assertArrayHasKey('montagem_travada', $linha);
        $this->assertFalse($linha['montagem_travada']);
    }

    // ─── Cenário — index(): resumo continua com 7 chaves (D-04), "travado" conta dentro de rascunho ───

    public function test_index_resumo_continua_com_7_chaves_e_travado_conta_em_rascunho(): void
    {
        $admin   = $this->admin();
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Resumo Com Travado']);
        $this->vincularServico($empresa, $servico);

        $contrato = ContratoAssinatura::factory()->create([
            'company_id'            => $empresa->id,
            'servico_id'            => $servico->id,
            'status'                => ContratoAssinatura::STATUS_RASCUNHO,
            'clicksign_envelope_id' => null,
        ]);
        $this->recuarCriacao($contrato, 30);

        $response = $this->actingAs($admin)->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(7, $props['resumo']);
        $this->assertSame(ContratoAssinatura::STATUS_TODOS, array_keys($props['resumo']));
        $this->assertSame(
            ContratoAssinatura::where('status', ContratoAssinatura::STATUS_RASCUNHO)->count(),
            $props['resumo'][ContratoAssinatura::STATUS_RASCUNHO],
            '"montagem_travada" é sub-estado de rascunho — continua contando dentro dele, nunca em chave própria.'
        );
    }

    // ─── Cenário — contratoStatus.js: CONTRATO_STATUS_LABELS continua com exatamente 7 chaves ───

    /**
     * Sem suíte de teste JS no projeto (nenhum runner configurado em
     * package.json) — mesma disciplina do teste 11 de
     * `IdempotenciaContratoTest` (asserção por leitura de arquivo fonte).
     * Garante que a Tarefa 2 não acrescentou `montagem_travada` ao mapa que
     * alimenta o resumo de 7 contagens (D-04) — o sub-estado tem rótulo e
     * classe PRÓPRIOS (`MONTAGEM_TRAVADA_*`), fora deste mapa.
     */
    public function test_contrato_status_labels_continua_com_exatamente_sete_chaves(): void
    {
        $caminho = resource_path('js/lib/contratoStatus.js');
        $this->assertFileExists($caminho);

        $conteudo = file_get_contents($caminho);

        $achou = preg_match('/export const CONTRATO_STATUS_LABELS = \{(.*?)\n\};/s', $conteudo, $matches);
        $this->assertSame(1, $achou, 'Bloco CONTRATO_STATUS_LABELS não encontrado em contratoStatus.js');

        preg_match_all('/^\s*[a-z_]+:\s*\'/m', $matches[1], $chaves);

        $this->assertCount(7, $chaves[0]);
    }
}
