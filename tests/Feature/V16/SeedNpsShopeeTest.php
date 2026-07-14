<?php

namespace Tests\Feature\V16;

use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prova do seed idempotente do modelo "NPS Shopee" (DEC-79-B) + link dos
 * serviços de setor performance ao "NPS Padrão" (DEC-79-A).
 *
 * RefreshDatabase roda todas as migrations em SQLite (:memory:) uma vez no
 * setUp — incluindo o seed do NPS Shopee. Como a base de teste nasce sem
 * serviços, no setUp o template + perguntas + opções são criados, porém os
 * `nps_template_service_scopes` são pulados (sem serviço shopee/performance).
 *
 * Cada teste cria os serviços de fixture via o trait `CriaCenarioResponsaveis`
 * e re-invoca `up()` (idempotente) para exercitar o wiring de scopes sem
 * depender do seed da Phase 75 estar na base de teste.
 */
class SeedNpsShopeeTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /** Invoca up() do seed manualmente (idempotente — seguro re-rodar). */
    private function rodarSeed(): void
    {
        $m = require database_path('migrations/2026_07_14_200002_seed_nps_shopee_and_link_performance_scopes.php');
        $m->up();
    }

    /** Id do template "NPS Shopee". */
    private function npsShopeeId(): ?int
    {
        $id = DB::table('nps_templates')->where('nome', 'NPS Shopee')->value('id');
        return $id === null ? null : (int) $id;
    }

    /** Id do template padrão (is_default=true). */
    private function npsPadraoId(): ?int
    {
        $id = DB::table('nps_templates')->where('is_default', true)->value('id');
        return $id === null ? null : (int) $id;
    }

    // ─── (a) template NPS Shopee com flags corretas ─────────────────────────

    public function test_template_nps_shopee_criado_com_flags(): void
    {
        $templates = DB::table('nps_templates')->where('nome', 'NPS Shopee')->get();

        $this->assertCount(1, $templates, 'Deve existir exatamente 1 template "NPS Shopee".');

        $tpl = $templates->first();
        $this->assertSame(0, (int) $tpl->is_default, 'NPS Shopee NÃO é o modelo principal (bônus continua no Padrão — DEC-79-E).');
        $this->assertSame(1, (int) $tpl->active, 'NPS Shopee deve estar ativo.');
        $this->assertSame(1, (int) $tpl->envio_automatico_mensal, 'NPS Shopee deve ter envio automático mensal.');
        $this->assertGreaterThan(0, (int) $tpl->priority, 'priority deve ser > 0 (DEC-79-B) para preceder o fallback.');
    }

    // ─── (b) 3 perguntas + 15 opções espelhando o NPS Padrão ────────────────

    public function test_perguntas_e_opcoes_espelham_padrao(): void
    {
        $shopeeId = $this->npsShopeeId();
        $this->assertNotNull($shopeeId);

        $perguntas = DB::table('nps_template_questions')
            ->where('template_id', $shopeeId)
            ->orderBy('ordem')
            ->get();

        $this->assertCount(3, $perguntas, 'NPS Shopee deve ter exatamente 3 perguntas.');

        // Dimensões e obrigatoriedade espelhando o molde 100004.
        $this->assertSame('estrategista', $perguntas[0]->dimensao);
        $this->assertSame(1, (int) $perguntas[0]->obrigatoria, 'Estrategista é obrigatória.');
        $this->assertSame('analista', $perguntas[1]->dimensao);
        $this->assertSame(0, (int) $perguntas[1]->obrigatoria, 'Analista é opcional.');
        $this->assertSame('empresa', $perguntas[2]->dimensao);
        $this->assertSame(1, (int) $perguntas[2]->obrigatoria, 'Empresa é obrigatória.');

        foreach ($perguntas as $p) {
            $this->assertSame('escala', $p->tipo, 'Todas as perguntas são do tipo escala.');

            $opcoes = DB::table('nps_template_options')
                ->where('question_id', $p->id)
                ->orderBy('peso')
                ->get();

            $this->assertCount(5, $opcoes, 'Cada pergunta deve ter 5 opções (escala 1..5).');
            $this->assertSame([1, 2, 3, 4, 5], $opcoes->pluck('peso')->map(fn ($v) => (int) $v)->all());
            $this->assertSame(['1', '2', '3', '4', '5'], $opcoes->pluck('label')->all());
        }

        // Total de 15 opções (5 × 3 perguntas).
        $totalOpcoes = DB::table('nps_template_options')
            ->whereIn('question_id', $perguntas->pluck('id'))
            ->count();
        $this->assertSame(15, $totalOpcoes, 'NPS Shopee deve ter 15 opções no total.');
    }

    // ─── (c) scope NPS Shopee → serviço shopee ──────────────────────────────

    public function test_scope_liga_nps_shopee_ao_servico_shopee(): void
    {
        // O seed da Phase 75 (2026_07_14_100002) já pode ter semeado o serviço
        // shopee durante o RefreshDatabase. Resolvemos o serviço shopee ativo do
        // MESMO jeito que o seed resolve; só criamos manualmente se ausente
        // (base de teste sem o seed da Phase 75).
        $servicoShopeeId = DB::table('servicos')
            ->where('setor', Servico::SETOR_SHOPEE)
            ->where('ativo', true)
            ->value('id');

        if ($servicoShopeeId === null) {
            $servicoShopeeId = $this->criarServico(Servico::SETOR_SHOPEE, true);
        }

        $this->rodarSeed();

        $this->assertTrue(
            DB::table('nps_template_service_scopes')
                ->where('template_id', $this->npsShopeeId())
                ->where('servico_id', $servicoShopeeId)
                ->exists(),
            'Deve existir scope ligando NPS Shopee ao serviço setor=shopee.'
        );
    }

    // ─── (d) scope NPS Padrão → todos os serviços performance ativos ────────

    public function test_scope_liga_nps_padrao_aos_servicos_performance(): void
    {
        $servicoPerfA = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $servicoPerfB = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        // Serviço performance INATIVO não deve ser linkado.
        $servicoPerfInativo = $this->criarServico(Servico::SETOR_PERFORMANCE, false);

        $this->rodarSeed();

        $padraoId = $this->npsPadraoId();
        $this->assertNotNull($padraoId);

        $this->assertTrue(
            DB::table('nps_template_service_scopes')->where('template_id', $padraoId)->where('servico_id', $servicoPerfA)->exists(),
            'NPS Padrão deve estar linkado ao serviço performance A.'
        );
        $this->assertTrue(
            DB::table('nps_template_service_scopes')->where('template_id', $padraoId)->where('servico_id', $servicoPerfB)->exists(),
            'NPS Padrão deve estar linkado ao serviço performance B.'
        );
        $this->assertFalse(
            DB::table('nps_template_service_scopes')->where('template_id', $padraoId)->where('servico_id', $servicoPerfInativo)->exists(),
            'Serviço performance INATIVO não deve ser linkado ao NPS Padrão.'
        );
    }

    // ─── (e) idempotência: 2ª execução afeta 0 rows ─────────────────────────

    public function test_idempotencia_segunda_execucao_afeta_zero_rows(): void
    {
        // Cenário completo: serviço shopee + serviços performance ativos.
        $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        // 1ª execução com os serviços presentes — cria os scopes.
        $this->rodarSeed();

        $antes = [
            'templates'  => DB::table('nps_templates')->count(),
            'perguntas'  => DB::table('nps_template_questions')->count(),
            'opcoes'     => DB::table('nps_template_options')->count(),
            'scopes'     => DB::table('nps_template_service_scopes')->count(),
        ];

        // 2ª execução — deve ser no-op total.
        $this->rodarSeed();

        $depois = [
            'templates'  => DB::table('nps_templates')->count(),
            'perguntas'  => DB::table('nps_template_questions')->count(),
            'opcoes'     => DB::table('nps_template_options')->count(),
            'scopes'     => DB::table('nps_template_service_scopes')->count(),
        ];

        $this->assertSame($antes, $depois, 'A 2ª execução do seed não pode criar/duplicar nenhuma linha.');

        // Reforço: exatamente 1 template NPS Shopee mesmo após re-run.
        $this->assertSame(1, DB::table('nps_templates')->where('nome', 'NPS Shopee')->count());
    }
}
