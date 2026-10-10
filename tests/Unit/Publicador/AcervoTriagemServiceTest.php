<?php

namespace Tests\Unit\Publicador;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Services\Publicador\AcervoTriagemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 173 Plano 02 — `AcervoTriagemService` extraído de
 * `MlbAnuncioController::meus()`. Cobre os mesmos números que a tela
 * `MeusAnuncios.jsx` já mostra hoje (triagem/defasagem), isolados do HTTP,
 * e os dois métodos novos (`comMotivos()`/`legadoEntre()`) que a Visão geral
 * (Plan 05) vai consumir.
 *
 * @group phase173
 */
class AcervoTriagemServiceTest extends TestCase
{
    use RefreshDatabase;

    private AcervoTriagemService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AcervoTriagemService();
    }

    /** @test */
    public function motivos_def_devolve_os_5_motivos_na_ordem_de_gravidade(): void
    {
        $motivos = $this->service->motivosDef();

        $this->assertCount(5, $motivos);
        $this->assertSame(
            [
                MlAcervoItem::MOTIVO_PAUSADO,
                MlAcervoItem::MOTIVO_SEM_ESTOQUE,
                MlAcervoItem::MOTIVO_FICHA_INCOMPLETA,
                MlAcervoItem::MOTIVO_PERDENDO_CATALOGO,
                MlAcervoItem::MOTIVO_FOTO_INSUFICIENTE,
            ],
            array_column($motivos, 'chave')
        );
    }

    /**
     * @test
     *
     * Quick 261010-nke — `acionaveis` (o default da tela) cobre TRÊS status.
     * `under_review` entrou em 10/10/2026: é o status em que o anúncio
     * recém-publicado nasce nessa conta (§21 dos learnings do Publicador) e o
     * mais acionável de todos — é exatamente o que pede alguém corrigir
     * título/foto e repatchar. Os filtros estreitos e `todos` não mudaram.
     */
    public function acionaveis_cobre_ativo_pausado_e_under_review(): void
    {
        $company = Company::factory()->create();

        $this->criarItem($company, ['ml_item_id' => 'MLB-ATIVO', 'status' => 'active']);
        $this->criarItem($company, ['ml_item_id' => 'MLB-PAUSADO', 'status' => 'paused']);
        $this->criarItem($company, ['ml_item_id' => 'MLB-REVISAO', 'status' => 'under_review']);
        $this->criarItem($company, ['ml_item_id' => 'MLB-ENCERRADO', 'status' => 'closed']);
        $this->criarItem($company, ['ml_item_id' => 'MLB-INATIVO', 'status' => 'inactive']);

        $acionaveis = $this->service->escopo($company, '', 'acionaveis')->pluck('ml_item_id')->all();

        $this->assertEqualsCanonicalizing(['MLB-ATIVO', 'MLB-PAUSADO', 'MLB-REVISAO'], $acionaveis);
        $this->assertContains('MLB-REVISAO', $acionaveis, 'sem under_review no default, o anúncio recém-publicado fica invisível');

        // Os braços estreitos recortam UM status só — intactos.
        $this->assertSame(['MLB-ATIVO'], $this->service->escopo($company, '', 'ativos')->pluck('ml_item_id')->all());
        $this->assertSame(['MLB-PAUSADO'], $this->service->escopo($company, '', 'pausados')->pluck('ml_item_id')->all());
        $this->assertSame(['MLB-ENCERRADO'], $this->service->escopo($company, '', 'encerrados')->pluck('ml_item_id')->all());
        $this->assertCount(5, $this->service->escopo($company, '', 'todos')->get(), "'todos' não filtra status");
    }

    /** @test */
    public function triagem_conta_total_distinto_e_cada_chip_bate_com_query_manual(): void
    {
        $company = Company::factory()->create();
        $outra   = Company::factory()->create();

        // 2 pausados (1 também sem estoque — não pode contar dobrado no total)
        $this->criarItem($company, [
            'status'     => 'paused',
            'motivos'    => [MlAcervoItem::MOTIVO_PAUSADO],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);
        $this->criarItem($company, [
            'status'     => 'paused',
            'motivos'    => [MlAcervoItem::MOTIVO_PAUSADO, MlAcervoItem::MOTIVO_SEM_ESTOQUE],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);
        // 1 ficha incompleta
        $this->criarItem($company, [
            'status'     => 'active',
            'motivos'    => [MlAcervoItem::MOTIVO_FICHA_INCOMPLETA],
            'severidade' => MlAcervoItem::SEVERIDADE_ATENCAO,
        ]);
        // 1 saudável (sem motivo)
        $this->criarItem($company, [
            'status'     => 'active',
            'motivos'    => [],
            'severidade' => MlAcervoItem::SEVERIDADE_SAUDAVEL,
        ]);
        // Item de outra empresa não pode vazar na contagem
        $this->criarItem($outra, [
            'status'     => 'paused',
            'motivos'    => [MlAcervoItem::MOTIVO_PAUSADO],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);

        $triagem = $this->service->triagem($company, '', 'acionaveis');

        $this->assertSame(3, $triagem['total'], 'total = anúncios distintos com >=1 motivo, nunca soma dos chips');

        $chipsPorChave = collect($triagem['chips'])->keyBy('chave');
        $this->assertSame(2, $chipsPorChave[MlAcervoItem::MOTIVO_PAUSADO]['count']);
        $this->assertSame(1, $chipsPorChave[MlAcervoItem::MOTIVO_SEM_ESTOQUE]['count']);
        $this->assertSame(1, $chipsPorChave[MlAcervoItem::MOTIVO_FICHA_INCOMPLETA]['count']);
        $this->assertSame(0, $chipsPorChave[MlAcervoItem::MOTIVO_PERDENDO_CATALOGO]['count']);
        $this->assertSame(0, $chipsPorChave[MlAcervoItem::MOTIVO_FOTO_INSUFICIENTE]['count']);
    }

    /** @test */
    public function defasagem_devolve_nunca_coletado_true_quando_empresa_nao_tem_nenhuma_linha(): void
    {
        $company = Company::factory()->create();

        $defasagem = $this->service->defasagem($company);

        $this->assertTrue($defasagem['nunca_coletado']);
        $this->assertNull($defasagem['coletado_em']);
        $this->assertNull($defasagem['horas']);
        $this->assertFalse($defasagem['defasado'], 'sem coleta nao ha "defasado" -- isso e "nunca coletado", nunca zero/false disfarcado de medicao');
    }

    /** @test */
    public function defasagem_calcula_horas_e_defasado_quando_ha_coleta(): void
    {
        $company = Company::factory()->create();

        $this->criarItem($company, ['coletado_em' => now()->subHours(50)]);

        $limiteHoras = (int) config('mlb_acervo.defasagem_horas');
        $defasagem   = $this->service->defasagem($company);

        $this->assertFalse($defasagem['nunca_coletado']);
        $this->assertNotNull($defasagem['coletado_em']);
        // diffInHours() devolve float com frações de segundo (comportamento
        // original do Carbon preservado — não é esta extração que define isso).
        $this->assertEqualsWithDelta(50, $defasagem['horas'], 0.1);
        $this->assertSame($defasagem['horas'] > $limiteHoras, $defasagem['defasado']);
    }

    /** @test */
    public function com_motivos_conta_itens_acionaveis_com_qualquer_um_dos_motivos_passados(): void
    {
        $company = Company::factory()->create();

        $this->criarItem($company, ['status' => 'paused', 'motivos' => [MlAcervoItem::MOTIVO_PAUSADO]]);
        $this->criarItem($company, ['status' => 'active', 'motivos' => [MlAcervoItem::MOTIVO_SEM_ESTOQUE]]);
        $this->criarItem($company, ['status' => 'active', 'motivos' => [MlAcervoItem::MOTIVO_FICHA_INCOMPLETA]]);
        // encerrado não entra no escopo 'acionaveis'
        $this->criarItem($company, ['status' => 'closed', 'motivos' => [MlAcervoItem::MOTIVO_PAUSADO]]);

        $total = $this->service->comMotivos($company, [
            MlAcervoItem::MOTIVO_PAUSADO,
            MlAcervoItem::MOTIVO_SEM_ESTOQUE,
        ]);

        $this->assertSame(2, $total);
    }

    /** @test */
    public function legado_entre_restringe_a_origem_legado(): void
    {
        $company = Company::factory()->create();

        $this->criarItem($company, [
            'status'  => 'paused',
            'motivos' => [MlAcervoItem::MOTIVO_PAUSADO],
            'origem'  => MlAcervoItem::ORIGEM_LEGADO,
        ]);
        $this->criarItem($company, [
            'status'  => 'paused',
            'motivos' => [MlAcervoItem::MOTIVO_PAUSADO],
            'origem'  => MlAcervoItem::ORIGEM_ECF,
        ]);
        $this->criarItem($company, [
            'status'  => 'active',
            'motivos' => [MlAcervoItem::MOTIVO_SEM_ESTOQUE],
            'origem'  => MlAcervoItem::ORIGEM_LEGADO,
        ]);

        $total = $this->service->legadoEntre($company, [
            MlAcervoItem::MOTIVO_PAUSADO,
            MlAcervoItem::MOTIVO_SEM_ESTOQUE,
        ]);

        $this->assertSame(2, $total, 'só os legado entram, mesmo com o mesmo motivo do item ecf');
    }

    private function criarItem(Company $company, array $overrides = []): MlAcervoItem
    {
        return MlAcervoItem::create(array_merge([
            'company_id'          => $company->id,
            'ml_item_id'          => 'MLB' . random_int(1000000000, 9999999999),
            'title'               => 'Produto de Teste',
            'status'              => 'active',
            'available_quantity'  => 10,
            'sold_quantity'       => 0,
            'nota_ecf'            => 60,
            'motivos'             => [],
            'severidade'          => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'origem'              => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em'         => now(),
        ], $overrides));
    }
}
