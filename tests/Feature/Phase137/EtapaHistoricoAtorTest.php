<?php

namespace Tests\Feature\Phase137;

use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\User;
use App\Services\FluxoEntrada\EtapaTransicaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 137 (plano 09, gap closure G2 / CR-02 do `137-REVIEW.md`) — prova
 * comportamental de que remover PERMANENTEMENTE um usuário
 * (`UserController::forceDestroy()` → `$user->forceDelete()`, hard delete
 * real, não soft, apesar de `User` usar `SoftDeletes`) preserva o histórico
 * de transição de etapa de TODAS as empresas que esse usuário movimentou.
 *
 * A FK original (`..._120000_create_company_etapa_transicoes_table.php`)
 * gravava `user_id` com `cascadeOnDelete()`: excluir de vez um único
 * colaborador apagava em cascata o histórico de dezenas de empresas que ele
 * nem tinha relação de posse — não estavam sendo excluídas — sem aviso, log
 * ou confirmação. Isso contradiz o próprio racional documentado no topo da
 * migration: a tabela existe em vez de `spatie/laravel-activitylog`
 * justamente porque a retenção de 365 dias do pacote é risco desalinhado com
 * o propósito do dado, que a Fase 143 consome para medir SLA. O `CASCADE`
 * reintroduzia a mesma perda por outra porta — e de forma silenciosa, só
 * descoberta quando o dado já não existe mais.
 *
 * A FK de `user_id` é a ÚNICA razão pela qual a linha de histórico poderia
 * sumir: `company_id` continua `cascadeOnDelete()` (correto — a empresa
 * deixando de existir leva o histórico dela junto), e nada além da FK apaga
 * linha de `company_etapa_transicoes` (é append-only, sem rota de DELETE na
 * aplicação).
 *
 * ⚠️ Esta asserção só tem valor porque as FKs estão LIGADAS no SQLite dos
 * testes: `config/database.php` usa
 * `'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true)`, e
 * `phpunit.xml` não sobrescreve `DB_FOREIGN_KEYS` — se alguém desligar isso
 * um dia, este teste vira verde vazio (o `ON DELETE` do MariaDB nunca seria
 * exercitado pelo SQLite, e o `forceDelete()` "funcionaria" mesmo que a FK
 * real do MariaDB estivesse de volta a `cascadeOnDelete()`).
 *
 * O histórico nasce pelo caminho REAL —
 * `EtapaTransicaoService::transicionar()` — nunca por
 * `CompanyEtapaTransicao::create()` avulso, para que o teste também prove
 * que o serviço segue funcionando com a coluna `user_id` agora `nullable()`.
 */
class EtapaHistoricoAtorTest extends TestCase
{
    use RefreshDatabase;

    private function transicionarViaServico(Company $company, string $etapaDestino, User $por, ?string $motivo = null): void
    {
        $resultado = app(EtapaTransicaoService::class)->transicionar($company, $etapaDestino, $por, $motivo);

        $this->assertSame('transicionado', $resultado['status'], "transição de teste falhou: {$resultado['requisito_faltante']} {$resultado['erro']}");
    }

    // ═════════════════════════════════════════════════════════════════════
    // 1. forceDelete() do ator preserva a linha de histórico da empresa que
    //    ele movimentou — só a referência ao ator vira NULL (T-137-26)
    // ═════════════════════════════════════════════════════════════════════

    public function test_force_delete_do_ator_preserva_historico_da_empresa_e_zera_so_o_autor(): void
    {
        $ator     = User::factory()->create();
        $empresaX = Company::factory()->create(['etapa' => null]);

        $this->transicionarViaServico($empresaX, Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $ator);

        $linha = CompanyEtapaTransicao::where('company_id', $empresaX->id)->sole();

        $ator->forceDelete();

        $linha->refresh();

        $this->assertNotNull(
            CompanyEtapaTransicao::find($linha->id),
            'o histórico de transição da empresa X foi apagado ao remover permanentemente o usuário ator — CR-02 / Fase 143 perde o insumo de SLA'
        );
        $this->assertNull(
            $linha->user_id,
            'user_id deveria virar NULL (nullOnDelete), não preservar um id de usuário que não existe mais'
        );
        $this->assertNull($linha->etapa_anterior);
        $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $linha->etapa_nova);
        $this->assertNotNull($linha->created_at, 'created_at não deveria ser afetado pela remoção do ator');
        $this->assertFalse((bool) $linha->retrocesso);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 2. forceDelete() do ator preserva o histórico de TODAS as empresas que
    //    ele movimentou, não só uma — prova de que o dano do CASCADE
    //    atingia empresas sem qualquer relação de posse com o usuário
    //    excluído
    // ═════════════════════════════════════════════════════════════════════

    public function test_force_delete_do_ator_preserva_historico_de_varias_empresas_nao_relacionadas(): void
    {
        $ator     = User::factory()->create();
        $empresaX = Company::factory()->create(['etapa' => null]);
        $empresaY = Company::factory()->create(['etapa' => null]);

        $this->transicionarViaServico($empresaX, Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $ator);
        $this->transicionarViaServico($empresaY, Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $ator);

        $linhaX = CompanyEtapaTransicao::where('company_id', $empresaX->id)->sole();
        $linhaY = CompanyEtapaTransicao::where('company_id', $empresaY->id)->sole();

        $ator->forceDelete();

        $this->assertNotNull(
            CompanyEtapaTransicao::find($linhaX->id),
            'o histórico de transição da empresa X foi apagado ao remover permanentemente o usuário ator, mesmo empresa X não sendo excluída — CR-02'
        );
        $this->assertNotNull(
            CompanyEtapaTransicao::find($linhaY->id),
            'o histórico de transição da empresa Y foi apagado ao remover permanentemente o usuário ator, mesmo empresa Y não sendo excluída — CR-02'
        );

        $this->assertNull($linhaX->refresh()->user_id);
        $this->assertNull($linhaY->refresh()->user_id);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 3. delete() (soft) do ator não altera nada — user_id continua
    //    apontando para o ator (caminho comum, `UserController::destroy()`)
    // ═════════════════════════════════════════════════════════════════════

    public function test_soft_delete_do_ator_nao_altera_user_id_do_historico(): void
    {
        $ator    = User::factory()->create();
        $empresa = Company::factory()->create(['etapa' => null]);

        $this->transicionarViaServico($empresa, Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $ator);

        $linha = CompanyEtapaTransicao::where('company_id', $empresa->id)->sole();

        $ator->delete(); // soft delete — UserController::destroy(), não forceDestroy()

        $linha->refresh();

        $this->assertSame(
            $ator->id,
            $linha->user_id,
            'soft delete do ator não deveria alterar user_id do histórico — só forceDelete() aciona a FK'
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // 4. A linha de histórico de um ator DIFERENTE não é tocada quando outro
    //    ator é removido permanentemente
    // ═════════════════════════════════════════════════════════════════════

    public function test_force_delete_de_um_ator_nao_toca_historico_de_outro_ator(): void
    {
        $atorA = User::factory()->create();
        $atorB = User::factory()->create();

        $empresaDeA = Company::factory()->create(['etapa' => null]);
        $empresaDeB = Company::factory()->create(['etapa' => null]);

        $this->transicionarViaServico($empresaDeA, Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $atorA);
        $this->transicionarViaServico($empresaDeB, Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $atorB);

        $linhaDeB = CompanyEtapaTransicao::where('company_id', $empresaDeB->id)->sole();

        $atorA->forceDelete();

        $this->assertSame(
            $atorB->id,
            $linhaDeB->refresh()->user_id,
            'remover permanentemente o ator A não deveria alterar user_id da linha de histórico do ator B'
        );
    }
}
