<?php

namespace Tests\Feature\Phase123;

use App\Services\Desempenho\CompanyScoreSnapshotReader;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fase 123 Plano 01 (Task 1) — suíte do `CompanyScoreSnapshotReader`, a
 * fonte única de leitura de `desempenho_company_score_snapshots`.
 */
class CompanyScoreSnapshotReaderTest extends Phase123TestCase
{
    private CompanyScoreSnapshotReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new CompanyScoreSnapshotReader();
    }

    // ═══ (a) escopo — só o usuário e a competência pedidos ═══════════════

    #[Test]
    public function para_usuario_devolve_so_as_linhas_do_usuario_e_da_competencia_pedidos(): void
    {
        Http::fake();

        $user       = $this->criarUserElegivel('Alvo');
        $outroUser  = $this->criarUserElegivel('Outro');
        $c1         = $this->darCarteira($user);
        $c2         = $this->darCarteira($user);
        $cOutro     = $this->darCarteira($outroUser);

        $this->seedLinha($user->id, $c1, '2026-06-01');
        $this->seedLinha($user->id, $c2, '2026-07-01'); // outro mês, não deve vazar
        $this->seedLinha($outroUser->id, $cOutro, '2026-06-01'); // outro usuário, não deve vazar

        $linhas = $this->reader->paraUsuario($user->id, Carbon::parse('2026-06-01'));

        $this->assertCount(1, $linhas);
        $this->assertSame($c1, $linhas->first()['company_id']);

        Http::assertNothingSent();
    }

    // ═══ (b) shape enxuto — exatamente 17 chaves ══════════════════════════

    #[Test]
    public function shape_tem_exatamente_as_17_chaves_esperadas_sem_campos_internos(): void
    {
        Http::fake();

        $user = $this->criarUserElegivel('Shape');
        $c1   = $this->darCarteira($user);
        $this->seedLinha($user->id, $c1, '2026-06-01');

        $linhas = $this->reader->paraUsuario($user->id, Carbon::parse('2026-06-01'));

        $esperado = [
            'company_id', 'company_name', 'fonte_financeira', 'status', 'nps_pontos',
            'faturamento_atual', 'faturamento_anterior', 'faturamento_var_pct', 'faturamento_pontos',
            'margem_pct_atual', 'margem_pct_anterior', 'margem_var_pp', 'margem_pontos',
            'componentes_presentes', 'nota_empresa', 'nota_empresa_parcial', 'quality',
        ];

        $this->assertSame($esperado, array_keys($linhas->first()));

        foreach (['origem', 'gerado_em', 'created_at', 'updated_at', 'id', 'user_id'] as $chaveProibida) {
            $this->assertArrayNotHasKey($chaveProibida, $linhas->first(), "chave interna '{$chaveProibida}' vazou pro shape");
        }
    }

    // ═══ (c) ordenação canônica — null sempre por último ══════════════════

    #[Test]
    public function ordenacao_canonica_poe_nota_null_sempre_por_ultimo(): void
    {
        Http::fake();

        $user = $this->criarUserElegivel('Ordenacao');
        $cA   = $this->darCarteira($user);
        $cB   = $this->darCarteira($user);
        $cC   = $this->darCarteira($user);

        $this->seedLinha($user->id, $cA, '2026-06-01', ['company_name' => 'Empresa A', 'nota_empresa' => 3.5]);
        $this->seedLinha($user->id, $cB, '2026-06-01', ['company_name' => 'Empresa B', 'nota_empresa' => 4.8]);
        $this->seedLinha($user->id, $cC, '2026-06-01', ['company_name' => 'Empresa C', 'nota_empresa' => null, 'status' => 'partial', 'componentes_presentes' => 1]);

        $linhas = $this->reader->paraUsuario($user->id, Carbon::parse('2026-06-01'))->values();

        $this->assertSame([4.8, 3.5, null], $linhas->pluck('nota_empresa')->all());
        $this->assertNull($linhas->last()['nota_empresa'], 'a linha com nota_empresa null precisa ser a ÚLTIMA do array');
    }

    // ═══ (d) desempate por nome ════════════════════════════════════════════

    #[Test]
    public function desempate_por_nome_quando_duas_empresas_tem_a_mesma_nota(): void
    {
        Http::fake();

        $user = $this->criarUserElegivel('Desempate');
        $cZ   = $this->darCarteira($user);
        $cA   = $this->darCarteira($user);

        $this->seedLinha($user->id, $cZ, '2026-06-01', ['company_name' => 'Zebra Ltda', 'nota_empresa' => 4.0]);
        $this->seedLinha($user->id, $cA, '2026-06-01', ['company_name' => 'Alfa Ltda', 'nota_empresa' => 4.0]);

        $linhas = $this->reader->paraUsuario($user->id, Carbon::parse('2026-06-01'))->values();

        $this->assertSame(['Alfa Ltda', 'Zebra Ltda'], $linhas->pluck('company_name')->all());
    }

    // ═══ (e) resumo() ══════════════════════════════════════════════════════

    #[Test]
    public function resumo_sobre_1_complete_e_2_partial_devolve_entraram_1_e_nao_entraram_2(): void
    {
        Http::fake();

        $user = $this->criarUserElegivel('Resumo');
        $c1   = $this->darCarteira($user);
        $c2   = $this->darCarteira($user);
        $c3   = $this->darCarteira($user);

        $this->seedLinha($user->id, $c1, '2026-06-01', ['status' => 'complete']);
        $this->seedLinha($user->id, $c2, '2026-06-01', ['status' => 'partial', 'nota_empresa' => null, 'componentes_presentes' => 1]);
        $this->seedLinha($user->id, $c3, '2026-06-01', ['status' => 'partial', 'nota_empresa' => null, 'componentes_presentes' => 2]);

        $linhas = $this->reader->paraUsuario($user->id, Carbon::parse('2026-06-01'));
        $resumo = $this->reader->resumo($linhas);

        $this->assertSame(['entraram' => 1, 'nao_entraram' => 2], $resumo);
    }

    // ═══ (f) paraUsuarios() agrupa e ordena por grupo ═════════════════════

    #[Test]
    public function para_usuarios_agrupa_por_user_id_e_cada_grupo_vem_ordenado(): void
    {
        Http::fake();

        $userA = $this->criarUserElegivel('Grupo A');
        $userB = $this->criarUserElegivel('Grupo B');
        $cA1   = $this->darCarteira($userA);
        $cA2   = $this->darCarteira($userA);
        $cB1   = $this->darCarteira($userB);

        $this->seedLinha($userA->id, $cA1, '2026-06-01', ['nota_empresa' => 3.0]);
        $this->seedLinha($userA->id, $cA2, '2026-06-01', ['nota_empresa' => 4.5]);
        $this->seedLinha($userB->id, $cB1, '2026-06-01', ['nota_empresa' => 2.0]);

        $agrupado = $this->reader->paraUsuarios([$userA->id, $userB->id], Carbon::parse('2026-06-01'));

        $this->assertCount(2, $agrupado);
        $this->assertSame([4.5, 3.0], $agrupado->get($userA->id)->pluck('nota_empresa')->all());
        $this->assertSame([2.0], $agrupado->get($userB->id)->pluck('nota_empresa')->all());
    }

    // ═══ (g) competência sem nenhuma linha ═════════════════════════════════

    #[Test]
    public function competencia_sem_nenhuma_linha_devolve_collection_vazia_nunca_null_nunca_excecao(): void
    {
        Http::fake();

        $user = $this->criarUserElegivel('Vazio');

        $linhas = $this->reader->paraUsuario($user->id, Carbon::parse('2026-05-01'));

        $this->assertTrue($linhas->isEmpty());
    }

    // ═══ (h) quality volta como array mesmo quando gravado null ═══════════

    #[Test]
    public function quality_volta_como_array_mesmo_quando_gravado_null_no_banco(): void
    {
        Http::fake();

        $user = $this->criarUserElegivel('Quality Nulo');
        $c1   = $this->darCarteira($user);
        $this->seedLinha($user->id, $c1, '2026-06-01', ['quality' => null]);

        $linhas = $this->reader->paraUsuario($user->id, Carbon::parse('2026-06-01'));

        $quality = $linhas->first()['quality'];
        $this->assertIsArray($quality);
        $this->assertSame([
            'revenue_diff_source' => null,
            'margin_diff_source'  => null,
            'margin_source'       => null,
            'motivos'             => [],
        ], $quality);
    }

    // ═══ (i) leitura do detalhe não dispara nenhuma requisição HTTP ═══════

    #[Test]
    public function leitura_nunca_dispara_chamada_http(): void
    {
        Http::fake();

        $user = $this->criarUserElegivel('Sem Http');
        $c1   = $this->darCarteira($user);
        $c2   = $this->darCarteira($user);
        $this->seedLinha($user->id, $c1, '2026-06-01');
        $this->seedLinha($user->id, $c2, '2026-06-01');

        $linhas = $this->reader->paraUsuario($user->id, Carbon::parse('2026-06-01'));
        $this->reader->resumo($linhas);
        $this->reader->paraUsuarios([$user->id], Carbon::parse('2026-06-01'));

        Http::assertNothingSent();
    }
}
