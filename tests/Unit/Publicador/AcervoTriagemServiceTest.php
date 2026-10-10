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

    // ═══════════════════════════════════════════════════════════════════════
    // Quick 261010-rie — busca por SKU + nome + MLB no MESMO campo, e o
    // filtro próprio "Em revisão".
    //
    // Falas literais do usuário em 10/10/2026: "Quero poder buscar por SKU,
    // Nome do Anuncio ou MLB" e "Em revisão quero poder isolar".
    // ═══════════════════════════════════════════════════════════════════════

    /** @test T18 */
    public function busca_acha_pelo_sku(): void
    {
        $company = Company::factory()->create();

        $this->criarItem($company, ['ml_item_id' => 'MLB-COM-SKU', 'title' => 'Poltrona Verde', 'skus' => ['ABC-1']]);
        $this->criarItem($company, ['ml_item_id' => 'MLB-OUTRO', 'title' => 'Mesa Lateral', 'skus' => ['ZZZ-9']]);

        $achados = $this->service->escopo($company, 'ABC-1', 'todos')->pluck('ml_item_id')->all();

        $this->assertSame(['MLB-COM-SKU'], $achados, 'RIE-01: o SKU é um dos três identificadores que o usuário tem na mão');
    }

    /** @test T19 */
    public function busca_acha_por_pedaco_do_sku(): void
    {
        $company = Company::factory()->create();

        $this->criarItem($company, ['ml_item_id' => 'MLB-COM-SKU', 'skus' => ['ABC-1']]);

        $this->assertSame(
            ['MLB-COM-SKU'],
            $this->service->escopo($company, 'BC-', 'todos')->pluck('ml_item_id')->all(),
            'pedaço do SKU acha, como já valia para o título'
        );
    }

    /** @test T20 */
    public function busca_por_titulo_e_por_mlb_continua_achando(): void
    {
        $company = Company::factory()->create();

        $this->criarItem($company, ['ml_item_id' => 'MLB5366398961', 'title' => 'Poltrona Beny Verde Musgo', 'skus' => ['ABC-1']]);
        $this->criarItem($company, ['ml_item_id' => 'MLB1000000001', 'title' => 'Mesa Lateral Redonda', 'skus' => ['ZZZ-9']]);

        $this->assertSame(
            ['MLB5366398961'],
            $this->service->escopo($company, 'Poltrona Beny', 'todos')->pluck('ml_item_id')->all(),
            'regressão: busca por pedaço do título'
        );
        $this->assertSame(
            ['MLB5366398961'],
            $this->service->escopo($company, '5366398961', 'todos')->pluck('ml_item_id')->all(),
            'regressão: busca pelo código MLB'
        );
    }

    /** @test T21 */
    public function busca_por_sku_nao_fura_o_escopo_por_empresa(): void
    {
        $company = Company::factory()->create();
        $outra   = Company::factory()->create();

        $this->criarItem($company, ['ml_item_id' => 'MLB-MINHA', 'skus' => ['MESMO-SKU']]);
        $this->criarItem($outra, ['ml_item_id' => 'MLB-DA-OUTRA', 'skus' => ['MESMO-SKU']]);

        $achados = $this->service->escopo($company, 'MESMO-SKU', 'todos')->pluck('ml_item_id')->all();

        $this->assertSame(
            ['MLB-MINHA'],
            $achados,
            'T-134-01: o orWhere do SKU tem de ficar DENTRO do where(function...) — solto, ele anula o escopo por empresa'
        );
    }

    /** @test T22 */
    public function busca_por_sku_respeita_o_filtro_de_status(): void
    {
        $company = Company::factory()->create();

        $this->criarItem($company, ['ml_item_id' => 'MLB-ENCERRADO', 'status' => 'closed', 'skus' => ['ABC-1']]);

        $this->assertSame(
            [],
            $this->service->escopo($company, 'ABC-1', 'acionaveis')->pluck('ml_item_id')->all(),
            'a busca mora DENTRO do mesmo builder do whereIn(status) — herda o filtro, como sempre herdou'
        );
        $this->assertSame(['MLB-ENCERRADO'], $this->service->escopo($company, 'ABC-1', 'todos')->pluck('ml_item_id')->all());
        $this->assertSame(['MLB-ENCERRADO'], $this->service->escopo($company, 'ABC-1', 'encerrados')->pluck('ml_item_id')->all());
    }

    /** @test T23 */
    public function linha_sem_sku_coletado_nao_e_achada_e_nao_quebra_a_query(): void
    {
        $company = Company::factory()->create();

        // skus NULL = "ainda não coletado" (D-RIE-02) — é a janela que o RIE-04
        // cobre na tela, com o aviso em vez de um "não achei" mudo.
        $this->criarItem($company, ['ml_item_id' => 'MLB-SEM-COLETA']);

        $this->assertSame([], $this->service->escopo($company, 'ABC-1', 'todos')->pluck('ml_item_id')->all());
    }

    /** @test T24 */
    public function termo_com_pontuacao_de_json_nao_devolve_o_acervo_inteiro(): void
    {
        $company = Company::factory()->create();

        $this->criarItem($company, ['ml_item_id' => 'MLB-1', 'title' => 'Produto Um', 'skus' => ['ABC-1']]);
        $this->criarItem($company, ['ml_item_id' => 'MLB-2', 'title' => 'Produto Dois', 'skus' => ['XYZ-9']]);

        foreach (['"', '[', ']'] as $pontuacao) {
            $this->assertCount(
                0,
                $this->service->escopo($company, $pontuacao, 'todos')->get(),
                "termo {$pontuacao} casaria com a pontuação do próprio JSON e devolveria o acervo inteiro como se fosse resultado de busca"
            );
        }
    }

    /** @test T25 */
    public function em_revisao_isola_so_os_under_review(): void
    {
        $company = Company::factory()->create();

        $this->criarItem($company, ['ml_item_id' => 'MLB-ATIVO', 'status' => 'active']);
        $this->criarItem($company, ['ml_item_id' => 'MLB-PAUSADO', 'status' => 'paused']);
        $this->criarItem($company, ['ml_item_id' => 'MLB-REVISAO', 'status' => 'under_review']);
        $this->criarItem($company, ['ml_item_id' => 'MLB-ENCERRADO', 'status' => 'closed']);

        $this->assertSame(
            ['MLB-REVISAO'],
            $this->service->escopo($company, '', 'em_revisao')->pluck('ml_item_id')->all(),
            'RIE-03: "Em revisão quero poder isolar"'
        );
    }

    /** @test T26 */
    public function em_revisao_acrescenta_sem_mexer_em_nenhum_filtro_existente(): void
    {
        $company = Company::factory()->create();

        $this->criarItem($company, ['ml_item_id' => 'MLB-ATIVO', 'status' => 'active']);
        $this->criarItem($company, ['ml_item_id' => 'MLB-PAUSADO', 'status' => 'paused']);
        $this->criarItem($company, ['ml_item_id' => 'MLB-REVISAO', 'status' => 'under_review']);
        $this->criarItem($company, ['ml_item_id' => 'MLB-ENCERRADO', 'status' => 'closed']);
        $this->criarItem($company, ['ml_item_id' => 'MLB-INATIVO', 'status' => 'inactive']);

        $this->assertEqualsCanonicalizing(
            ['MLB-ATIVO', 'MLB-PAUSADO', 'MLB-REVISAO'],
            $this->service->escopo($company, '', 'acionaveis')->pluck('ml_item_id')->all(),
            'D-RIE-07: a opção nova ACRESCENTA — nada saiu de acionaveis, as emendas de 10/08 e 10/10 ficam intactas'
        );
        $this->assertSame(['MLB-ATIVO'], $this->service->escopo($company, '', 'ativos')->pluck('ml_item_id')->all());
        $this->assertSame(['MLB-PAUSADO'], $this->service->escopo($company, '', 'pausados')->pluck('ml_item_id')->all());
        $this->assertSame(['MLB-ENCERRADO'], $this->service->escopo($company, '', 'encerrados')->pluck('ml_item_id')->all());
        $this->assertCount(5, $this->service->escopo($company, '', 'todos')->get(), "'todos' não filtra status");
    }

    /** @test T27 */
    public function chips_com_em_revisao_dizem_a_verdade_daquele_universo(): void
    {
        $company = Company::factory()->create();

        // Pausado e sem estoque existem na empresa, mas NÃO no universo
        // `under_review`: AnuncioSaudeService::triagem() só carimba
        // MOTIVO_PAUSADO com status 'paused' e MOTIVO_SEM_ESTOQUE com
        // 'active'. Zero aqui é a VERDADE do filtro, não um bug (D-RIE-09).
        $this->criarItem($company, [
            'ml_item_id' => 'MLB-PAUSADO',
            'status'     => 'paused',
            'motivos'    => [MlAcervoItem::MOTIVO_PAUSADO],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);
        $this->criarItem($company, [
            'ml_item_id'         => 'MLB-SEM-ESTOQUE',
            'status'             => 'active',
            'available_quantity' => 0,
            'motivos'            => [MlAcervoItem::MOTIVO_SEM_ESTOQUE],
            'severidade'         => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);
        $this->criarItem($company, [
            'ml_item_id' => 'MLB-REVISAO',
            'status'     => 'under_review',
            'motivos'    => [MlAcervoItem::MOTIVO_FICHA_INCOMPLETA],
            'severidade' => MlAcervoItem::SEVERIDADE_ATENCAO,
        ]);

        $triagem       = $this->service->triagem($company, '', 'em_revisao');
        $chipsPorChave = collect($triagem['chips'])->keyBy('chave');

        $this->assertSame(0, $chipsPorChave[MlAcervoItem::MOTIVO_PAUSADO]['count']);
        $this->assertSame(0, $chipsPorChave[MlAcervoItem::MOTIVO_SEM_ESTOQUE]['count']);
        $this->assertSame(
            1,
            $chipsPorChave[MlAcervoItem::MOTIVO_FICHA_INCOMPLETA]['count'],
            'ficha/foto/catálogo continuam contando no universo em revisão'
        );
        $this->assertSame(1, $triagem['total'], 'total = anúncios DISTINTOS com motivo dentro do filtro');
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
