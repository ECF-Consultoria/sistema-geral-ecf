<?php

namespace Tests\Feature\Phase133;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Servico;
use App\Models\User;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Fase 133 Plano 02 (FLUXO-09) — PRIMEIRO teste que existe para
 * `MlbController::ativarEmpresaPendente()` (o botão "Ativar" da tela
 * `/mlb/empresas`). Antes desta fase, o método tinha zero cobertura.
 *
 * Fecha a "porta dos fundos" do time de Publicação (D-03 do CONTEXT.md):
 * `ativarEmpresaPendente()` cria `MlbEmpresa` + `MlbImplementacao` INLINE,
 * por fora do `EmpresaOperacionalRouter`, e por isso a checagem do
 * interruptor `administrativo_bloqueio_ativo` vive numa SEGUNDA cópia
 * dentro do próprio controller — consequência aceita da D-03, não um erro.
 *
 * A regra vive em DOIS lugares:
 * 1. `EmpresaOperacionalRouter::rotear()` — provado por
 *    `tests/Feature/Phase133/RoteamentoExcecaoServicoTest.php` (plano 133-01).
 * 2. `MlbController::ativarEmpresaPendente()` — provado por ESTE arquivo.
 *
 * Quem mudar um dos dois precisa mudar o outro — não há mecanismo de
 * código que force a sincronia, só a disciplina de teste nos dois lugares.
 *
 * D-07: a decisão usa os serviços REALMENTE contratados pela empresa
 * (`$company->contratosServico`), nunca o `$validated['tipo']` escolhido a
 * mão no formulário — um rótulo de UI não é autorização.
 */
class AtivarEmpresaPendenteBloqueioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Polos', 'Assessoria'] as $nome) {
            Servico::firstOrCreate(
                ['nome' => $nome],
                ['valor_padrao' => 0, 'tipo_cobranca' => 'mensal', 'ativo' => true],
            );
        }

        // O `update()` explícito logo após o `firstOrCreate` é DELIBERADO
        // (mesma disciplina do Phase124KillSwitchTest/RoteamentoExcecaoServicoTest,
        // plano 133-01): `firstOrCreate` ignora o array de atributos quando a
        // linha já existe, e o seed real do catálogo (migrations
        // 2026_05_27_100001 + 2026_08_13_100001) já roda em todo
        // `RefreshDatabase`. Só o `update()` garante o cenário do teste,
        // independente do que o seed real definiu.
        Servico::where('nome', 'Polos')->update(['exige_contrato' => false]);
        Servico::where('nome', 'Assessoria')->update(['exige_contrato' => true]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Cria uma Company com um ContratoServico ATIVO apontando pro Servico de nome dado. */
    private function empresaComServico(string $nomeServico): Company
    {
        $company = Company::create(['name' => "Empresa {$nomeServico} Teste FLUXO-09"]);
        $servico = Servico::where('nome', $nomeServico)->firstOrFail();

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        return $company;
    }

    private function empresaSemServico(): Company
    {
        return Company::create(['name' => 'Empresa Sem Servico Contratado Teste FLUXO-09']);
    }

    private function ativar(Company $c, string $tipo): TestResponse
    {
        return $this->actingAs($this->admin())
            ->post(route('mlb.empresas.pendente.ativar', $c->id), ['tipo' => $tipo]);
    }

    // ─── Sentinela de fixture ──────────────────────────────────────────────

    public function test_fixture_declara_polos_isento_e_assessoria_exigindo_contrato(): void
    {
        $polos      = Servico::where('nome', 'Polos')->firstOrFail();
        $assessoria = Servico::where('nome', 'Assessoria')->firstOrFail();

        $this->assertFalse($polos->exigeContrato(), 'Fixture inválida: Polos deveria ser isento de contrato.');
        $this->assertTrue($assessoria->exigeContrato(), 'Fixture inválida: Assessoria deveria exigir contrato.');
    }

    // ─── Chave LIGADA ──────────────────────────────────────────────────────

    public function test_interruptor_ligado_permite_ativar_polos_manualmente(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->empresaComServico('Polos');

        $response = $this->ativar($company, 'polos');

        $response->assertSessionHas('success');

        $mlbEmp = MlbEmpresa::where('company_id', $company->id)->first();
        $this->assertNotNull($mlbEmp, 'Polos é isento — com o interruptor ligado, a MlbEmpresa deveria nascer normalmente.');
        $this->assertSame('POLO', $mlbEmp->tipo);
        $this->assertSame('POLOS', $mlbEmp->projeto);
        $this->assertSame('M0', $mlbEmp->fase);

        $this->assertNotNull(
            MlbImplementacao::where('empresa_id', $mlbEmp->id)->first(),
            'Polos é isento — a MlbImplementacao vinculada deveria nascer junto.',
        );
    }

    public function test_interruptor_ligado_recusa_ativacao_manual_de_servico_que_exige_contrato(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->empresaComServico('Assessoria');

        $response = $this->ativar($company, 'assessoria');

        $response->assertSessionHas('error');
        $this->assertNotEmpty(session('error'));

        $this->assertNull(
            MlbEmpresa::where('company_id', $company->id)->first(),
            'Assessoria exige contrato — com o interruptor ligado, nenhuma MlbEmpresa deveria nascer.',
        );
    }

    public function test_interruptor_ligado_recusa_quando_o_tipo_do_formulario_diverge_do_servico_contratado(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        // A empresa contratou Assessoria de verdade, mas quem clica no botão
        // marca "polos" no formulário. D-07: a decisão vem do contrato, não
        // do rótulo escolhido a mão.
        $company = $this->empresaComServico('Assessoria');

        $response = $this->ativar($company, 'polos');

        $response->assertSessionHas('error');

        $this->assertNull(
            MlbEmpresa::where('company_id', $company->id)->first(),
            'O rótulo do formulário não pode abrir a porta para um serviço que exige contrato.',
        );
    }

    public function test_interruptor_ligado_recusa_empresa_sem_servico_contratado_ativo(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->empresaSemServico();

        $response = $this->ativar($company, 'polos');

        $response->assertSessionHas('error');

        $this->assertNull(
            MlbEmpresa::where('company_id', $company->id)->first(),
            'Fail-safe: ausência de contrato ativo nunca pode virar passe livre.',
        );
    }

    // ─── Chave DESLIGADA (regressão) ───────────────────────────────────────

    public function test_interruptor_desligado_ativa_assessoria_como_sempre(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0');

        $company = $this->empresaComServico('Assessoria');

        $response = $this->ativar($company, 'assessoria');

        $response->assertSessionHas('success');

        $mlbEmp = MlbEmpresa::where('company_id', $company->id)->first();
        $this->assertNotNull($mlbEmp, 'Com o interruptor desligado, a ativação manual de Assessoria continua funcionando.');
        $this->assertSame('ASSESSORIA', $mlbEmp->tipo);
    }

    public function test_interruptor_desligado_ativa_polos_como_sempre(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0');

        $company = $this->empresaComServico('Polos');

        $response = $this->ativar($company, 'polos');

        $response->assertSessionHas('success');

        $mlbEmp = MlbEmpresa::where('company_id', $company->id)->first();
        $this->assertNotNull($mlbEmp, 'Com o interruptor desligado, a ativação manual de Polos continua funcionando.');
        $this->assertSame('POLO', $mlbEmp->tipo);

        $this->assertNotNull(MlbImplementacao::where('empresa_id', $mlbEmp->id)->first());
    }

    // ─── Sem jargão (UI-06) ────────────────────────────────────────────────

    public function test_mensagem_de_recusa_nao_usa_jargao(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->empresaComServico('Assessoria');

        $this->ativar($company, 'assessoria');

        $mensagem = session('error');
        $this->assertNotEmpty($mensagem, 'Esperava uma mensagem de erro na sessão.');

        foreach (['flag', 'roteamento', 'ficha operacional', 'interruptor'] as $jargao) {
            $this->assertStringNotContainsStringIgnoringCase(
                $jargao,
                $mensagem,
                "A mensagem de recusa não pode conter o jargão '{$jargao}' (UI-06).",
            );
        }
    }
}
