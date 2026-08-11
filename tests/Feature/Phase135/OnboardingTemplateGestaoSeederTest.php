<?php

namespace Tests\Feature\Phase135;

use App\Models\OnboardingTemplate;
use App\Models\Servico;
use App\Models\TemplatePasso;
use Database\Seeders\OnboardingTemplateGestaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 135 Plano 04 — cobre o seeder do template de Gestão v1: as 13 linhas
 * exatas de 135-04-PLAN.md (bloco <interfaces>), os catálogos fechados via
 * constante (nunca string literal) e a idempotência (rodar duas vezes não
 * gera versão 2 nem duplica passo).
 *
 * Importante (não é suposição — confirmado via debug nesta sessão): as
 * migrations de catálogo (2026_05_27_100001_seed_servicos_catalog +
 * 2026_06_18_100002_seed_servicos_setor) já publicam um Servico real
 * "Gestão" com setor=performance/ativo=true em QUALQUER banco migrado do
 * zero — inclusive o SQLite de teste via RefreshDatabase. Os testes daqui
 * reaproveitam essa linha (nunca criam uma segunda "Gestão" duplicada, o
 * que ambiguaria a resolução por nome) e, no caso negativo, desativam a
 * linha existente em vez de assumir uma tabela vazia.
 */
class OnboardingTemplateGestaoSeederTest extends TestCase
{
    use RefreshDatabase;

    /** O Servico "Gestão" real, publicado pelas migrations do catálogo. */
    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    /** @test */
    public function publica_o_template_v1_com_13_passos(): void
    {
        $servico = $this->servicoDeGestao();

        (new OnboardingTemplateGestaoSeeder())->run();

        $template = OnboardingTemplate::where('servico_id', $servico->id)->firstOrFail();

        $this->assertSame(1, $template->versao);
        $this->assertTrue($template->ativo);
        $this->assertSame(13, TemplatePasso::where('template_id', $template->id)->count());
    }

    /** @test */
    public function exatamente_5_passos_tem_auto_fonte_e_o_conjunto_bate_com_o_catalogo_fechado(): void
    {
        (new OnboardingTemplateGestaoSeeder())->run();

        $autoFontes = TemplatePasso::whereNotNull('auto_fonte')
            ->pluck('auto_fonte')
            ->sort()
            ->values()
            ->all();

        $this->assertCount(5, $autoFontes);
        $this->assertSame(
            collect(TemplatePasso::AUTO_FONTES)->sort()->values()->all(),
            $autoFontes
        );
    }

    /** @test */
    public function exatamente_4_passos_tem_dono_sistema_e_o_grant_ecf_e_cliente_com_auto_fonte_d19(): void
    {
        (new OnboardingTemplateGestaoSeeder())->run();

        $this->assertSame(4, TemplatePasso::where('dono', TemplatePasso::DONO_SISTEMA)->count());

        $passoGrantEcf = TemplatePasso::where('chave', 'grant_sistema_ecf')->firstOrFail();
        $this->assertSame(TemplatePasso::DONO_CLIENTE, $passoGrantEcf->dono);
        $this->assertSame(TemplatePasso::AUTO_FONTE_ML_TOKEN, $passoGrantEcf->auto_fonte);
    }

    /** @test */
    public function reuniao_realizada_depende_de_agendar_reuniao_e_confirmacao_pagamento_d15(): void
    {
        (new OnboardingTemplateGestaoSeeder())->run();

        $passo = TemplatePasso::where('chave', 'reuniao_realizada')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['agendar_reuniao_onboarding', 'confirmacao_pagamento'],
            $passo->depende_de
        );
    }

    /** @test */
    public function nenhum_passo_de_mapeamento_depende_de_confirmacao_pagamento_d15(): void
    {
        (new OnboardingTemplateGestaoSeeder())->run();

        foreach (['metricas_da_conta', 'anuncios_ativos_inativos', 'agendar_reuniao_onboarding'] as $chave) {
            $passo = TemplatePasso::where('chave', $chave)->firstOrFail();
            $this->assertNotContains('confirmacao_pagamento', $passo->depende_de ?? []);
        }
    }

    /** @test */
    public function so_excluir_anuncios_inativos_tem_condicao_d12(): void
    {
        (new OnboardingTemplateGestaoSeeder())->run();

        $this->assertSame(1, TemplatePasso::whereNotNull('condicao')->count());

        $passo = TemplatePasso::where('chave', 'excluir_anuncios_inativos')->firstOrFail();
        $this->assertNotNull($passo->condicao);
        $this->assertSame(
            TemplatePasso::CONDICAO_ANUNCIOS_INATIVOS,
            $passo->condicao['tipo'] ?? null
        );
    }

    /** @test */
    public function rodar_o_seeder_duas_vezes_mantem_1_template_e_13_passos(): void
    {
        (new OnboardingTemplateGestaoSeeder())->run();
        (new OnboardingTemplateGestaoSeeder())->run();

        $this->assertSame(1, OnboardingTemplate::count());
        $this->assertSame(13, TemplatePasso::count());
    }

    /** @test */
    public function sem_servico_de_gestao_ativo_o_seeder_nao_cria_nada(): void
    {
        // Desativa a "Gestão" publicada pelas migrations — simula o cenário
        // em que a resolução por consulta não encontra candidato válido.
        Servico::query()->where('nome', 'like', '%Gestão%')->update(['ativo' => false]);

        (new OnboardingTemplateGestaoSeeder())->run();

        $this->assertSame(0, OnboardingTemplate::count());
        $this->assertSame(0, TemplatePasso::count());
    }
}
