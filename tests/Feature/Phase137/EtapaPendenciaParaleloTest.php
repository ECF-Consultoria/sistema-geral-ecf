<?php

namespace Tests\Feature\Phase137;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Fase 137 (plano 04, ETAPA-04) — prova de que pendência e etapa são eixos
 * PARALELOS e independentes (D-17/D-18/D-19).
 *
 * O exemplo do §10 do PDF v23.0 mostra "Status = Aguardando Administrativo;
 * Pendência = Contrato não assinado" — dois eixos ao mesmo tempo. Marcar ou
 * desmarcar pendência NUNCA pode mover a etapa. Este teste prova essa
 * independência por construção, e fecha com um gate estático que impede a
 * próxima sessão de espalhar leitura direta de `pendencia_aberta` por fora
 * do ponto único que `Company::pendenciaAberta()`/`scopeComPendenciaAberta()`
 * definem — o modo de falha documentado em
 * `.planning/learnings/painel-polos-status-e-meta.md` §1.
 */
class EtapaPendenciaParaleloTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        return User::factory()->create();
    }

    private function empresaNaEtapa(?string $etapa): Company
    {
        return Company::factory()->create(['etapa' => $etapa]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 1. Marcar/desmarcar pendência nunca move a etapa (ETAPA-04, D-17)
    // ═════════════════════════════════════════════════════════════════════

    public function test_declarar_pendencia_nao_move_a_etapa_em_varias_etapas(): void
    {
        $por = $this->usuario();

        $etapasTestadas = [
            Company::ETAPA_AGUARDANDO_ADMINISTRATIVO,
            Company::ETAPA_ONBOARDING_CONCLUIDO,
            null, // empresa legada, sem etapa
        ];

        foreach ($etapasTestadas as $etapa) {
            $empresa = $this->empresaNaEtapa($etapa);

            $empresa->declararPendencia('Contrato não assinado', $por);
            $empresa->refresh();

            $this->assertSame($etapa, $empresa->etapa, "declararPendencia() moveu a etapa a partir de '{$etapa}'");
        }
    }

    public function test_resolver_pendencia_nao_move_a_etapa(): void
    {
        $por     = $this->usuario();
        $empresa = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO);

        $empresa->declararPendencia('Contrato não assinado', $por);
        $empresa->resolverPendencia();
        $empresa->refresh();

        $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $empresa->etapa);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 2. pendenciaAberta() — ciclo completo (D-19)
    // ═════════════════════════════════════════════════════════════════════

    public function test_pendencia_aberta_ciclo_completo(): void
    {
        $por     = $this->usuario();
        $empresa = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO);

        $this->assertFalse($empresa->pendenciaAberta(), 'empresa recém-criada não deveria ter pendência');

        $empresa->declararPendencia('Contrato não assinado', $por);
        $empresa->refresh();
        $this->assertTrue($empresa->pendenciaAberta());

        $empresa->resolverPendencia();
        $empresa->refresh();
        $this->assertFalse($empresa->pendenciaAberta());
    }

    // ═════════════════════════════════════════════════════════════════════
    // 3. declararPendencia() grava motivo/autor/timestamp; segunda chamada
    //    SUBSTITUI, nunca acumula (D-18: uma pendência aberta por vez)
    // ═════════════════════════════════════════════════════════════════════

    public function test_declarar_pendencia_grava_motivo_autor_timestamp_e_substitui(): void
    {
        $autor1  = $this->usuario();
        $autor2  = $this->usuario();
        $empresa = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO);

        $empresa->declararPendencia('Contrato não assinado', $autor1);
        $empresa->refresh();

        $this->assertTrue($empresa->pendencia_aberta);
        $this->assertSame('Contrato não assinado', $empresa->pendencia_motivo);
        $this->assertSame($autor1->id, $empresa->pendencia_por);
        $this->assertNotNull($empresa->pendencia_em);

        // Segunda chamada — substitui os três, não acumula (D-18).
        $empresa->declararPendencia('Aguardando documento societário', $autor2);
        $empresa->refresh();

        $this->assertTrue($empresa->pendencia_aberta);
        $this->assertSame('Aguardando documento societário', $empresa->pendencia_motivo);
        $this->assertSame($autor2->id, $empresa->pendencia_por);

        // Nenhuma tabela própria de histórico de pendência existe (D-18,
        // decisão de planejamento do 137-04-PLAN.md) — não há nada
        // "acumulado" a conferir além das 4 colunas em `companies`.
        $this->assertSame(1, Company::where('id', $empresa->id)->count());
    }

    // ═════════════════════════════════════════════════════════════════════
    // 4. scopeComPendenciaAberta() — único ponto de leitura por QUERY (D-19)
    // ═════════════════════════════════════════════════════════════════════

    public function test_scope_com_pendencia_aberta_traz_so_as_marcadas(): void
    {
        $por = $this->usuario();

        $comPendencia1 = $this->empresaNaEtapa(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO);
        $comPendencia2 = $this->empresaNaEtapa(null);
        $semPendencia  = $this->empresaNaEtapa(Company::ETAPA_EM_OPERACAO);

        $comPendencia1->declararPendencia('Motivo 1', $por);
        $comPendencia2->declararPendencia('Motivo 2', $por);

        $ids = Company::comPendenciaAberta()->pluck('id');

        $this->assertTrue($ids->contains($comPendencia1->id));
        $this->assertTrue($ids->contains($comPendencia2->id));
        $this->assertFalse($ids->contains($semPendencia->id));
        $this->assertCount(2, $ids);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 5. Gate de leitura direta (D-19) — nenhum controller/componente lê
    //    `pendencia_aberta` direto. Cópia literal da disciplina de
    //    `PolosController::desconsideraDaMeta()` — ler o flag direto é
    //    exatamente o modo de falha que
    //    `.planning/learnings/painel-polos-status-e-meta.md` §1 documenta.
    // ═════════════════════════════════════════════════════════════════════

    public function test_gate_nenhum_arquivo_de_app_le_pendencia_aberta_direto(): void
    {
        $arquivoPermitido = realpath(base_path('app/Models/Company.php'));
        $ofensores        = [];

        foreach (File::allFiles(base_path('app')) as $arquivo) {
            $caminho = $arquivo->getPathname();

            if (realpath($caminho) === $arquivoPermitido) {
                continue;
            }

            if (str_ends_with($caminho, '.php') && str_contains(File::get($caminho), 'pendencia_aberta')) {
                $ofensores[] = $caminho;
            }
        }

        $this->assertEmpty(
            $ofensores,
            "D-19 violado: 'pendencia_aberta' lido diretamente fora de app/Models/Company.php em: "
                . implode(', ', $ofensores)
                . ". Use Company::pendenciaAberta() (por instância) ou Company::comPendenciaAberta() (por query) — "
                . "ver .planning/learnings/painel-polos-status-e-meta.md §1."
        );
    }
}
