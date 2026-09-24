<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Http\Middleware\RestringeDominioDoPortal;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Quem vê o quê. A empresa vem SEMPRE da sessão; um id de outra empresa
 * responde 404, igual a um id que não existe.
 */
class AcessoAoModuloEstruturaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    public function test_o_modulo_aparece_no_menu_de_toda_empresa_e_a_pagina_abre_vazia(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Estrutura')
                ->where('estrutura.painel.ofertas', 0)
                ->where('estrutura.blocos', [])
                ->where('modulos', fn ($modulos) => collect($modulos)->contains(fn ($m) => $m['chave'] === 'estrutura' && $m['ativo']))
            );
    }

    public function test_a_agenda_abre_com_as_quatro_secoes(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.agenda'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaAgenda')
                ->has('agenda.secoes.atrasadas')
                ->has('agenda.secoes.hoje')
                ->has('agenda.secoes.proximas')
                ->has('agenda.secoes.concluidas')
                ->where('vocabulario.dias_ate_jardinagem', 7)
            );
    }

    public function test_sem_sessao_vai_para_a_entrada(): void
    {
        $this->get(route('portal.auth.estrutura'))->assertRedirect();
    }

    public function test_a_pagina_entrega_o_gabarito_em_blocos(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->anunciosDoGabarito($this->listaDoGabarito($empresa, $ator), $ator);

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura'))
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Estrutura')
                ->where('estrutura.painel.publicados', 4)
                ->where('estrutura.contadores', ['todas' => 9, 'publicar' => 6, 'falta' => 2, 'completas' => 1])
                // CAD-01 (+5 combos), MSA-MR, kit, combit.
                ->count('estrutura.blocos', 4)
                ->count('estrutura.blocos.0.ofertas', 6)
                ->where('estrutura.blocos.0.tambem_em.0.sku', 'MSA-MR+CAD-01-KIT')
            );
    }

    /** Filtro e busca no SERVIDOR; o painel continua sendo do conjunto inteiro. */
    public function test_filtro_e_busca_recortam_os_blocos_mas_nao_o_painel(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->anunciosDoGabarito($this->listaDoGabarito($empresa, $ator), $ator);

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura', ['situacao' => 'falta']))
            ->assertInertia(fn ($page) => $page
                ->where('estrutura.painel.ofertas', 9)
                ->count('estrutura.blocos', 2) // CB2 (bloco CAD-01) e MSA-MR
            );

        // A busca acha pelo MLB — "onde está o MLB tal?" leva à oferta.
        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura', ['q' => 'mlb0000000004']))
            ->assertInertia(fn ($page) => $page
                ->count('estrutura.blocos', 1)
                ->where('estrutura.blocos.0.ofertas.0.sku', 'MSA-MR')
            );
    }

    /**
     * O bloco nasce recolhido; o cabeçalho vive do resumo. O resumo é do bloco
     * INTEIRO — filtrar "Falta um lado" esconde CB3..CB6, mas o cabeçalho
     * continua dizendo "4 a publicar".
     */
    public function test_resumo_do_bloco_e_do_conjunto_inteiro_mesmo_filtrado(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        app(\App\Services\Portal\Estrutura\EstruturaAgendaService::class)
            ->agendar($ofertas['CAD-01-CB3'], '2026-10-01', 'publicacao', $ator);

        $esperado = ['total' => 5, 'ok' => 0, 'falta' => 1, 'publicar' => 4, 'sem_agenda' => 4];

        foreach (['todas', 'falta'] as $filtro) {
            $this->withoutVite()->entrarNoPortal($empresa)
                ->get(route('portal.auth.estrutura', ['situacao' => $filtro]))
                ->assertInertia(fn ($page) => $page
                    ->where('estrutura.blocos.0.principal.sku', 'CAD-01')
                    ->where('estrutura.blocos.0.principal.situacao', 'ok')
                    ->where('estrutura.blocos.0.resumo_combos', $esperado)
                    ->where('estrutura.blocos.0.uso', ['kits' => 1, 'combits' => 1])
                    ->where('estrutura.blocos.0.quantidades_combo', [2, 3, 4, 5, 6])
                    ->where('estrutura.resumo_kits', ['total' => 2, 'ok' => 0, 'falta' => 0, 'publicar' => 2, 'sem_agenda' => 2])
                );
        }
    }

    /**
     * A faixa "próximo passo": UMA coisa a fazer, em ordem de urgência, sobre o
     * conjunto inteiro. Percorre os estados na ordem em que um cliente passa
     * por eles.
     */
    public function test_proximo_passo_segue_a_ordem_de_urgencia(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $agenda = app(\App\Services\Portal\Estrutura\EstruturaAgendaService::class);
        $passo = fn () => $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura'))
            ->viewData('page')['props']['estrutura']['proximo_passo'];

        // Nada cadastrado.
        $this->assertSame('cadastrar', $passo()['tipo']);

        // Gabarito sem agenda: 8 ofertas com buraco e nenhuma data.
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        $this->assertSame(['tipo' => 'agendar', 'quantidade' => 8], $passo());

        // Algo para hoje passa na frente de tudo.
        $agenda->agendar($ofertas['CAD-01-CB3'], today()->format('Y-m-d'), 'publicacao', $ator);
        $p = $passo();
        $this->assertSame('hoje', $p['tipo']);
        $this->assertSame('CAD-01-CB3', $p['primeira']['sku']);

        // Tudo com data e nada para hoje: sobram os produtos sem variação?
        // No gabarito, os dois simples entram em kit — então está em dia.
        \App\Models\EstruturaAgendaItem::query()->delete();
        $agenda->agendarProposta($empresa, array_map(fn ($o) => $o->id, array_values($ofertas)), $ator);
        \App\Models\EstruturaAgendaItem::query()->update(['data' => today()->addDays(3)->format('Y-m-d')]);
        $this->assertSame('em_dia', $passo()['tipo']);

        // Um produto novo, sem combo nem kit, e já com data: vira "variacoes".
        [$novo] = app(\App\Services\Portal\Estrutura\EstruturaOfertaService::class)
            ->criar($empresa, ['sku' => 'NOVO-1', 'fase' => 'simples'], $ator);
        $agenda->agendar($novo, today()->addDays(9)->format('Y-m-d'), 'publicacao', $ator);
        $this->assertSame(['tipo' => 'variacoes', 'quantidade' => 1], $passo());
    }

    public function test_pagina_com_mais_de_25_blocos(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $svc = app(\App\Services\Portal\Estrutura\EstruturaOfertaService::class);
        foreach (range(1, 30) as $i) {
            $svc->criar($empresa, ['sku' => "P{$i}", 'fase' => 'simples'], $ator);
        }

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura', ['pagina' => 2]))
            ->assertInertia(fn ($page) => $page
                ->where('estrutura.paginacao', ['pagina' => 2, 'paginas' => 2, 'blocos' => 30, 'por_pagina' => 25])
                ->count('estrutura.blocos', 5)
                ->where('estrutura.painel.ofertas', 30)
            );
    }

    public function test_id_de_outra_empresa_responde_404_em_toda_escrita(): void
    {
        $minha = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $ator = $this->atorCliente($outra);
        $alheias = $this->listaDoGabarito($outra, $ator);
        $this->anunciosDoGabarito($alheias, $ator);
        $anuncio = $alheias['CAD-01']->anuncios()->first();
        $espera = EstruturaAnuncioEspera::create(['company_id' => $outra->id, 'sku_colado' => 'Z', 'motivo' => 'sem_oferta', 'tipo' => 'classico']);
        $agenda = $alheias['CAD-01']->agenda()->create(['data' => '2026-09-30', 'acao' => 'jardinagem']);

        $sessao = $this->entrarNoPortal($minha);

        $sessao->put(route('portal.auth.estrutura.ofertas.atualizar', $alheias['CAD-01']->id), ['sku' => 'X', 'fase' => 'simples'])->assertNotFound();
        $sessao->delete(route('portal.auth.estrutura.ofertas.excluir', $alheias['CAD-01-CB6']->id))->assertNotFound();
        $sessao->post(route('portal.auth.estrutura.anuncios.criar', $alheias['CAD-01']->id), ['tipo' => 'classico'])->assertNotFound();
        $sessao->delete(route('portal.auth.estrutura.anuncios.excluir', $anuncio->id))->assertNotFound();
        $sessao->delete(route('portal.auth.estrutura.espera.descartar', $espera->id))->assertNotFound();
        $sessao->post(route('portal.auth.estrutura.agenda.criar'), ['oferta_id' => $alheias['CAD-01']->id, 'data' => '2026-10-01', 'acao' => 'publicacao'])->assertNotFound();
        $sessao->patch(route('portal.auth.estrutura.agenda.jardinagem', $agenda->id), ['feita' => true])->assertNotFound();

        // Vincular linha MINHA a oferta ALHEIA também não.
        $minhaLinha = EstruturaAnuncioEspera::create(['company_id' => $minha->id, 'sku_colado' => 'Z', 'motivo' => 'sem_oferta', 'tipo' => 'classico']);
        $sessao->post(route('portal.auth.estrutura.espera.vincular', $minhaLinha->id), ['oferta_id' => $alheias['CAD-01']->id])->assertNotFound();

        $this->assertSame(9, EstruturaOferta::where('company_id', $outra->id)->count());
        $this->assertSame('CAD-01', $alheias['CAD-01']->fresh()->sku);
        $this->assertNull($agenda->fresh()->concluida_em);
    }

    /**
     * A allowlist tem de cobrir cada rota do módulo — `DominioLiberaTodoModuloTest`
     * já varre todas as `portal.auth.*`; este afirma que as do módulo EXISTEM
     * para aquela varredura (sem isto, um prefixo trocado a deixaria vazia e
     * verde, `portal-do-cliente.md` §3).
     */
    public function test_as_rotas_do_modulo_existem_e_estao_na_allowlist(): void
    {
        $permitido = (new \ReflectionClass(RestringeDominioDoPortal::class))->getConstant('PERMITIDO');

        $rotas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => Str::startsWith((string) $r->getName(), 'portal.auth.estrutura'));

        $this->assertGreaterThanOrEqual(18, $rotas->count());
        $this->assertNotContains('portal/*', $permitido);

        foreach ($rotas as $rota) {
            $this->assertTrue(
                collect($permitido)->contains(fn ($p) => Str::is($p, $rota->uri())),
                "{$rota->uri()} fora da allowlist"
            );
        }
    }

    /** Toda escrita registra de que lado veio — o causer não distingue. */
    public function test_escrita_registra_a_origem(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->entrarNoPortal($empresa)
            ->post(route('portal.auth.estrutura.ofertas.criar'), ['sku' => 'CAD-01', 'fase' => 'simples'])
            ->assertSessionHasNoErrors();

        $log = \Spatie\Activitylog\Models\Activity::where('log_name', 'portal')->latest('id')->first();
        $this->assertSame('cliente', $log->properties['origem']);
        $this->assertSame('oferta_criada', $log->properties['evento']);
    }
}
