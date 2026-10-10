<?php

namespace Tests\Feature\Publicador\Lote;

use App\Jobs\Publicador\PublicarRascunhoJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\MlToken;
use App\Models\PubFilaPublicacao;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubRascunho;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\DadosEfetivosService;
use App\Support\Publicador\EditorEmUso;
use App\Support\Publicador\NaFilaDePublicacao;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A fila de publicação em lote (10/10/2026): agenda só os prontos, publica UM produto a cada 10 minutos (nunca
 * dois ao mesmo tempo), pausa por erro de CONTA sem enviar nada, manda para `precisa_revisar` o produto que mudou
 * e segue sem esperar, respeita janela, editor aberto e o teto global, e isola tudo por conta.
 *
 * O relógio é parado numa segunda-feira, 12/10/2026 10:00 (São Paulo), ANTES do cenário; o agendador roda pelo
 * comando de verdade (`artisan('publicador:fila-publicacao')`) a cada minuto simulado. A fila é `Queue::fake()`:
 * o `PublicarRascunhoJob` é rodado à mão (`terminarPublicacao`), com o ML simulado — nada sai de verdade.
 */
class FilaDePublicacaoTest extends TestCase
{
    use CenarioDaFila;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        Notification::fake();
        $this->withoutVite();
        $this->montarFila(3);
    }

    private function minuto(int $n): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'America/Sao_Paulo')->addMinutes($n));
    }

    // ═══ Agendar ═════════════════════════════════════════════════════════════

    public function test_agenda_so_os_prontos_e_diz_por_que_os_outros_ficaram(): void
    {
        [$a, $b, $c] = $this->cadeiras;
        $this->conferirTodas([$a, $c]);
        $this->repo->tocar($this->rascunhoDe($c)); // editado depois da conferência

        $sem = $this->agendar([$a], ['ciente' => false])->assertStatus(422)->json();
        $this->assertStringContainsString('Estou ciente', $sem['recusados'][$a->id], 'avisos do ML pedem o "Estou ciente"');

        $r = $this->agendar()->assertCreated()->json();

        $this->assertSame([$a->id], $r['agendados']);
        $this->assertSame('Confira no Mercado Livre antes de agendar.', $r['recusados'][$b->id]);
        $this->assertSame('O produto mudou depois da conferência: confira de novo.', $r['recusados'][$c->id]);
        $this->assertSame('1 produto entrou na fila de publicação.', $r['mensagem']);

        $item = $this->itemDe($a);
        $v = $this->rascunhoDe($a)->validacoes()->latest('id')->first();
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $item->status);
        $this->assertSame($v->id, (int) $item->validacao_id);
        $this->assertSame($v->plano_hash, $item->plano_hash);
        $this->assertSame($this->rascunhoDe($a)->revisao, $item->revisao);
        $this->assertTrue($item->ciente);
        $this->assertSame($a->id, (int) $item->produto_ativo);
        $this->assertNotEmpty($item->resumo['digital']);
        $this->assertSame(10, $this->fila()->intervalo_minutos);
        $this->assertSame($this->admin->id, (int) $this->fila()->criada_por);
        $this->assertSame(0, $this->publicacoes(), 'agendar não publica nada');
        $this->assertTrue(NaFilaDePublicacao::emUso($a->id));
    }

    public function test_um_produto_a_cada_10_minutos_e_nunca_dois_ao_mesmo_tempo(): void
    {
        [$a, $b, $c] = $this->cadeiras;
        $this->conferirTodas();
        $this->agendar()->assertCreated();

        $this->passada(); // 10:00
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $this->itemDe($a)->status);
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $this->itemDe($b)->status);
        Queue::assertPushedOn('high', PublicarRascunhoJob::class);
        $this->assertSame(PubRascunho::PUBLISHING, $this->rascunhoDe($a)->status);
        $this->assertSame('Vitória Publicadora', PubPublicacao::query()->sole()->ator['nome'], 'quem agendou é quem publica');

        $this->minuto(2);
        $pub = $this->terminarPublicacao($a);
        $this->assertSame(PubPublicacao::PUBLISHED, $pub->status);
        $this->passada(); // 10:02 — fecha o A; o B só às 10:10
        $this->assertSame(PubFilaPublicacaoItem::PUBLICADO, $this->itemDe($a)->status);
        $this->assertNull($this->itemDe($a)->produto_ativo);
        $this->assertSame(['MLB9000000001'], array_column($this->itemDe($a)->resumo['mlbs'], 'ml_item_id'));
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $this->itemDe($b)->status);

        $this->minuto(9);
        $this->passada();
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $this->itemDe($b)->status, 'ainda dentro dos 10 minutos');

        $this->minuto(10);
        $this->passada();
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $this->itemDe($b)->status);
        $this->assertSame('2026-10-12 10:10', $this->itemDe($b)->iniciado_em->format('Y-m-d H:i'));

        // O B ainda publicando às 10:25: o C espera, mesmo com o intervalo vencido.
        $this->minuto(25);
        $this->passada();
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $this->itemDe($c)->status, 'nunca dois ao mesmo tempo');

        $this->minuto(26);
        $this->terminarPublicacao($b);
        $this->passada(); // fecha o B e, com o intervalo vencido, começa o C
        $this->assertSame(PubFilaPublicacaoItem::PUBLICADO, $this->itemDe($b)->status);
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $this->itemDe($c)->status);

        $this->minuto(28);
        $this->terminarPublicacao($c);
        $this->passada();
        $this->assertSame(PubFilaPublicacao::CONCLUIDA, $this->fila()->status);
        $this->assertNull($this->fila()->conta_ativa, 'a conta fica livre para outra fila');
        $this->assertSame(3, $this->publicacoes());
    }

    public function test_intervalo_ajustavel_vale_para_a_fila(): void
    {
        [$a, $b] = $this->cadeiras;
        $this->conferirTodas([$a, $b]);
        $this->agendar([$a, $b], ['ciente' => true, 'intervalo_minutos' => 15])->assertCreated();
        $this->assertSame(15, $this->fila()->intervalo_minutos);
        $this->agendar([$a], ['ciente' => true, 'intervalo_minutos' => 1])->assertUnprocessable()->assertJsonValidationErrors('intervalo_minutos');

        $this->passada();
        $this->minuto(1);
        $this->terminarPublicacao($a);
        $this->minuto(14);
        $this->passada();
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $this->itemDe($b)->status);
        $this->minuto(15);
        $this->passada();
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $this->itemDe($b)->status);
    }

    // ═══ Erro de conta x erro do item ════════════════════════════════════════

    public function test_conta_tirada_da_lista_pausa_a_fila_sem_publicar_e_retomar_continua(): void
    {
        [$a] = $this->cadeiras;
        $this->conferirTodas();
        $this->agendar()->assertCreated();

        config(['publicador.contas_liberadas.companies' => []]);
        $this->passada();

        $fila = $this->fila();
        $this->assertSame(PubFilaPublicacao::PAUSADA, $fila->status);
        $this->assertStringContainsString('não está liberada para esta conta', $fila->motivo_pausa);
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $this->itemDe($a)->status, 'o produto continua na fila');
        $this->assertSame(0, $this->publicacoes());
        $this->assertSame(0, $this->postsDeItem());

        config(['publicador.contas_liberadas.companies' => [$this->empresa->id]]);
        $this->minuto(3);
        $this->actingAs($this->admin)->postJson($this->rotaLote('retomar'))->assertOk()->assertJsonPath('fila.status', 'ativa');
        $this->passada();
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $this->itemDe($a)->status);
    }

    public function test_token_de_outro_vendedor_pausa_a_fila(): void
    {
        $this->conferirTodas();
        $this->agendar()->assertCreated();
        MlToken::query()->update(['ml_user_id' => '999888777']);

        $this->passada();

        $this->assertSame(PubFilaPublicacao::PAUSADA, $this->fila()->status);
        $this->assertStringContainsString('outro vendedor', $this->fila()->motivo_pausa);
        $this->assertSame(0, $this->publicacoes());
    }

    public function test_produto_editado_depois_de_agendado_vira_precisa_revisar_e_a_fila_segue_sem_esperar(): void
    {
        [$a, $b] = $this->cadeiras;
        $this->conferirTodas();
        $this->agendar()->assertCreated();
        $this->repo->tocar($this->rascunhoDe($a)); // alguém editou o A (subiu a revisão)

        $this->passada();

        $item = $this->itemDe($a);
        $this->assertSame(PubFilaPublicacaoItem::PRECISA_REVISAR, $item->status);
        $this->assertStringContainsString('confira de novo', $item->motivo);
        $this->assertNull($item->produto_ativo, 'o produto pode ser conferido e agendado de novo');
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $this->itemDe($b)->status, 'o B começou na mesma passada');
        $this->assertSame(1, $this->publicacoes());
    }

    public function test_preco_do_portal_que_mudou_depois_de_agendado_vira_precisa_revisar(): void
    {
        // O preço do A vem da Precificação do Portal (nada digitado): o serviço de verdade, não o dublê do cenário.
        $this->app->instance(DadosEfetivosService::class, new DadosEfetivosService(app(EstruturaPrecificacaoService::class)));
        [$a, $b] = $this->cadeiras;
        $linha = EstruturaPrecificacao::create(['oferta_id' => $a->oferta_id, 'custo' => 60, 'frete_classico' => 20, 'frete_premium' => 20]);
        $unica = $this->repo->snapshot($this->rascunhoDe($a))->variantes[0];
        $this->repo->gravarVariacao($this->rascunhoDe($a), [], [$unica->comDados([...$unica->dados, 'precos' => []])]);
        $this->conferirTodas([$a, $b]);
        $this->agendar([$a, $b])->assertCreated();

        $linha->update(['custo' => 90]); // o cliente mudou o custo no Portal: o preço anunciado mudou

        $this->passada();

        $this->assertSame(PubFilaPublicacaoItem::PRECISA_REVISAR, $this->itemDe($a)->status);
        $this->assertStringContainsString('vem do Portal mudou', $this->itemDe($a)->motivo);
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $this->itemDe($b)->status);
    }

    public function test_publicacao_que_passa_de_40_minutos_pausa_a_fila(): void
    {
        [$a, $b] = $this->cadeiras;
        $this->conferirTodas([$a, $b]);
        $this->agendar([$a, $b])->assertCreated();
        $this->passada(); // A começa e nunca termina (o worker morreu)

        $this->minuto(39);
        $this->passada();
        $this->assertSame(PubFilaPublicacao::ATIVA, $this->fila()->status);

        $this->minuto(41);
        $this->passada();
        $this->assertSame(PubFilaPublicacao::PAUSADA, $this->fila()->status);
        $this->assertStringContainsString('passou de 40 minutos', $this->fila()->motivo_pausa);
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $this->itemDe($a)->status, 'o item espera a publicação terminar de fato');
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $this->itemDe($b)->status);

        // Quando a publicação enfim termina, o item fecha mesmo com a fila pausada.
        $this->terminarPublicacao($a);
        $this->passada();
        $this->assertSame(PubFilaPublicacaoItem::PUBLICADO, $this->itemDe($a)->status);
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $this->itemDe($b)->status, 'pausada não anda');
    }

    public function test_editor_aberto_espera_e_a_fila_tenta_o_seguinte(): void
    {
        [$a, $b] = $this->cadeiras;
        $this->conferirTodas([$a, $b]);
        $this->agendar([$a, $b])->assertCreated();
        EditorEmUso::marcar($a->id);

        $this->passada();

        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $this->itemDe($a)->status, 'não publica por baixo de quem está editando');
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $this->itemDe($b)->status);
    }

    public function test_janela_de_horario(): void
    {
        [$a] = $this->cadeiras;
        $this->conferirTodas([$a]);
        $this->agendar([$a], ['ciente' => true, 'janela_inicio' => '11:00', 'janela_fim' => '18:00'])->assertCreated();
        $this->assertSame(['inicio' => '11:00', 'fim' => '18:00'], $this->fila()->janela());

        $this->passada(); // 10:00
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $this->itemDe($a)->status);
        $painel = $this->actingAs($this->admin)->getJson($this->rotaLote('dados'))->assertOk()->json('fila');
        $this->assertSame('2026-10-12T11:00:00-03:00', $painel['itens'][0]['previsto_em'], 'a previsão respeita a janela');

        $this->minuto(60);
        $this->passada(); // 11:00
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $this->itemDe($a)->status);
    }

    public function test_teto_global_de_dois_inicios_por_minuto(): void
    {
        $outras = [];
        foreach ([1, 2] as $n) {
            $c = Company::factory()->create();
            MlToken::create(['company_id' => $c->id, 'ml_user_id' => '1555596317', 'access_token' => 'fake-access-token', 'refresh_token' => 'x',
                'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
            $outras[] = $c;
        }
        config(['publicador.contas_liberadas.companies' => [$this->empresa->id, $outras[0]->id, $outras[1]->id]]);
        $this->conferirTodas([$this->cadeiras[0]]);
        $this->agendar([$this->cadeiras[0]])->assertCreated();

        // As outras duas contas, cada uma com um produto pronto: o mesmo cenário, outra âncora.
        $empresaOriginal = $this->empresa;
        foreach ($outras as $i => $c) {
            $this->empresa = $c;
            $p = $this->outraCadeira(10 + $i);
            $this->conferirTodas([$p]);
            $this->agendar([$p])->assertCreated();
        }
        $this->empresa = $empresaOriginal;

        $this->passada();

        $this->assertSame(2, PubFilaPublicacaoItem::query()->where('status', PubFilaPublicacaoItem::PUBLICANDO)->count(), 'só 2 por minuto, somando as filas');
        $this->assertSame(1, PubFilaPublicacaoItem::query()->where('status', PubFilaPublicacaoItem::AGENDADO)->count());

        $this->minuto(1);
        $this->passada();
        $this->assertSame(3, PubFilaPublicacaoItem::query()->where('status', PubFilaPublicacaoItem::PUBLICANDO)->count());
    }

    // ═══ Pausar, retomar, cancelar, remover ══════════════════════════════════

    public function test_remover_pausar_retomar_e_cancelar(): void
    {
        [$a, $b, $c] = $this->cadeiras;
        $this->conferirTodas();
        $this->agendar()->assertCreated();

        $this->actingAs($this->admin)->deleteJson($this->rotaLote('itens.remover', ['item' => $this->itemDe($b)->id]))->assertOk();
        $this->assertSame(PubFilaPublicacaoItem::CANCELADO, $this->itemDe($b)->status);
        $this->assertFalse(NaFilaDePublicacao::emUso($b->id));
        $this->assertStringContainsString('Tirado da fila por Vitória', $this->itemDe($b)->motivo);

        $this->actingAs($this->admin)->postJson($this->rotaLote('pausar'))->assertOk()->assertJsonPath('fila.status', 'pausada');
        $this->passada();
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $this->itemDe($a)->status, 'pausada não anda');
        $this->actingAs($this->admin)->postJson($this->rotaLote('pausar'))->assertUnprocessable();

        $this->actingAs($this->admin)->postJson($this->rotaLote('retomar'))->assertOk()->assertJsonPath('fila.status', 'ativa');
        $this->passada();
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $this->itemDe($a)->status);
        $this->actingAs($this->admin)->deleteJson($this->rotaLote('itens.remover', ['item' => $this->itemDe($a)->id]))
            ->assertUnprocessable()->assertJsonPath('message', 'Este produto já está sendo publicado e não sai mais da fila.');

        $this->actingAs($this->admin)->postJson($this->rotaLote('cancelar'))->assertOk()->assertJsonPath('fila.status', 'cancelada');
        $this->assertSame(PubFilaPublicacaoItem::CANCELADO, $this->itemDe($c)->status);
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $this->itemDe($a)->status, 'o que já começou termina');
        $this->assertNull($this->fila()->conta_ativa);
        $this->actingAs($this->admin)->postJson($this->rotaLote('retomar'))->assertNotFound();

        // Cancelada, a conta aceita outra fila — e o C (cancelado) pode voltar.
        $this->agendar([$c])->assertCreated();
        $this->assertSame(2, PubFilaPublicacao::query()->where('conta_chave', $this->conta())->count());

        // O A termina depois do cancelamento: o item fecha do mesmo jeito.
        $this->minuto(2);
        $this->terminarPublicacao($a);
        $this->passada();
        $this->assertSame(PubFilaPublicacaoItem::PUBLICADO, PubFilaPublicacaoItem::query()->where('produto_id', $a->id)->sole()->status);
    }

    public function test_corrida_entre_a_tela_e_o_agendador_nunca_publica_o_que_saiu_da_fila(): void
    {
        [$a, $b] = $this->cadeiras;
        $this->conferirTodas([$a, $b]);
        $this->agendar([$a, $b])->assertCreated();

        // A tela leu o item AGENDADO; o agendador o começou antes do clique "Tirar da fila" chegar.
        $lido = $this->itemDe($a);
        PubFilaPublicacaoItem::query()->whereKey($lido->id)->update(['status' => PubFilaPublicacaoItem::PUBLICANDO]);
        try {
            app(\App\Services\Publicador\Fila\FilaPublicacaoService::class)->remover($lido, $this->admin);
            $this->fail('tirou da fila um produto que já estava publicando');
        } catch (\App\Support\Publicador\RegraViolada $e) {
            $this->assertSame('Este produto já está sendo publicado e não sai mais da fila.', $e->getMessage());
        }
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $lido->fresh()->status);
        $this->assertSame($a->id, (int) $lido->fresh()->produto_ativo);

        // O agendador checou o B; a fila foi cancelada antes de ele marcar `publicando`: não começa nada.
        $fila = $this->fila();
        $doB = $this->itemDe($b);
        PubFilaPublicacao::query()->whereKey($fila->id)->update(['status' => PubFilaPublicacao::CANCELADA, 'conta_ativa' => null]);
        $agendador = app(\App\Services\Publicador\Fila\AgendadorDaFila::class);
        $iniciar = (new \ReflectionMethod($agendador, 'iniciar'))->getClosure($agendador);
        $conta = ['fechados' => 0, 'iniciados' => 0, 'revisar' => 0, 'pausadas' => 0, 'concluidas' => 0];
        $this->assertFalse($iniciar($fila, $doB, $this->rascunhoDe($b), $this->admin, $conta));
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $doB->fresh()->status);
        $this->assertSame(0, $this->publicacoes());
    }

    public function test_uma_fila_viva_por_conta_e_um_produto_numa_fila_so(): void
    {
        [$a, $b] = $this->cadeiras;
        $this->conferirTodas([$a, $b]);
        $this->agendar([$a])->assertCreated();
        $this->agendar([$b])->assertCreated();

        $this->assertSame(1, PubFilaPublicacao::query()->where('conta_chave', $this->conta())->count(), 'agendar de novo acrescenta na fila viva');
        $this->assertSame([1, 2], [$this->itemDe($a)->posicao, $this->itemDe($b)->posicao]);

        $r = $this->agendar([$a])->assertUnprocessable()->json();
        $this->assertSame('Já está na fila de publicação.', $r['recusados'][$a->id]);
        $this->assertSame(1, PubFilaPublicacaoItem::query()->where('produto_id', $a->id)->count());
    }

    public function test_isolamento_por_conta(): void
    {
        [$a] = $this->cadeiras;
        $this->conferirTodas([$a]);
        $this->agendar([$a])->assertCreated();
        $item = $this->itemDe($a);

        $outra = Company::factory()->create();
        $alheio = PubProduto::create(['company_id' => $outra->id, 'sku' => 'ALHEIO', 'nome' => 'Alheio', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $rotaOutra = fn (string $nome, array $extra = []) => route('mlb.anuncios.publicador.lote.'.$nome, ['conta' => 'company-'.$outra->id, ...$extra]);

        // O item de uma conta não é achado pela outra; a outra não tem fila.
        $this->actingAs($this->admin)->deleteJson($rotaOutra('itens.remover', ['item' => $item->id]))->assertNotFound();
        $this->actingAs($this->admin)->postJson($rotaOutra('pausar'))->assertNotFound();
        // Produto de outra conta nunca entra nesta fila.
        $r = $this->actingAs($this->admin)->postJson($this->rotaLote('agendar'), ['produtos' => [$alheio->id], 'ciente' => true])->assertUnprocessable()->json();
        $this->assertSame('Este produto não é desta conta ou já foi publicado.', $r['recusados'][$alheio->id]);
        // A visão rápida da outra conta não mostra o produto daqui.
        $linhas = $this->actingAs($this->admin)->getJson($rotaOutra('dados'))->assertOk()->json('linhas');
        $this->assertSame([$alheio->id], array_column($linhas, 'produto_id'));

        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $item->fresh()->status);
    }

    // ═══ Ponta a ponta e painel ══════════════════════════════════════════════

    public function test_ponta_a_ponta_o_painel_mostra_mlbs_tarefa_e_previsao(): void
    {
        [$a, $b] = $this->cadeiras;
        $this->conferirTodas([$a, $b]);
        $this->agendar([$a, $b])->assertCreated();

        $painel = $this->actingAs($this->admin)->getJson($this->rotaLote('dados'))->assertOk()->json('fila');
        $this->assertSame('ativa', $painel['status']);
        $this->assertSame(['inicio' => null], ['inicio' => $painel['janela']]);
        $this->assertSame('2026-10-12T10:00:00-03:00', $painel['itens'][0]['previsto_em']);
        $this->assertSame('2026-10-12T10:10:00-03:00', $painel['itens'][1]['previsto_em']);
        $this->assertSame('2026-10-12T10:12:00-03:00', $painel['termina_em'], 'o último começa 10:10 e leva ~2 min');

        $this->passada();
        $this->minuto(1);
        $this->terminarPublicacao($a);
        $this->passada();

        $painel = $this->actingAs($this->admin)->getJson($this->rotaLote('dados'))->assertOk()->json('fila');
        $doA = collect($painel['itens'])->firstWhere('produto_id', $a->id);
        $this->assertSame('publicado', $doA['status']);
        $this->assertSame('MLB9000000001', $doA['mlbs'][0]['ml_item_id']);
        $this->assertSame('https://produto.mercadolivre.com.br/MLB9000000001-cadeira-_JM', $doA['mlbs'][0]['permalink']);
        $this->assertStringContainsString('tarefa=', (string) $doA['tarefa_url'], 'a tarefa das alavancas que nasceu da publicação');
        $this->assertSame(['total' => 2, 'feitos' => 1, 'andados' => 1, 'pct' => 50], $painel['progresso']);
        $this->assertSame('2026-10-12T10:10:00-03:00', $painel['proximo_em']);

        // O publicado sai da visão rápida (lista só o que falta publicar).
        $linhas = $this->actingAs($this->admin)->getJson($this->rotaLote('dados'))->json('linhas');
        $this->assertNotContains($a->id, array_column($linhas, 'produto_id'));
    }

    public function test_quem_agendou_saiu_do_sistema_a_fila_pausa_e_quem_retoma_assume(): void
    {
        [$a] = $this->cadeiras;
        $this->conferirTodas([$a]);
        $this->agendar([$a])->assertCreated();
        $outro = \App\Models\User::factory()->create(['role' => 'admin', 'name' => 'Caio']);
        $this->admin->delete();

        $this->passada();
        $this->assertSame(PubFilaPublicacao::PAUSADA, $this->fila()->status);

        $this->actingAs($outro)->postJson($this->rotaLote('retomar'))->assertOk();
        $this->assertSame($outro->id, (int) $this->fila()->criada_por);
        $this->passada();
        $this->assertSame('Caio', PubPublicacao::query()->sole()->ator['nome']);
    }

    public function test_a_tela_abre_com_a_selecao_vinda_dos_produtos_e_a_lista_de_produtos_avisa_a_fila(): void
    {
        [$a, $b] = $this->cadeiras;

        $this->actingAs($this->admin)->get($this->rotaLote('index', ['produtos' => "{$a->id},{$b->id},999999"]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Mlb/Publicador/PublicacaoEmLote')
                ->where('selecionados', [$a->id, $b->id, 999999])
                ->has('linhas', 3)
                ->where('fila', null)
                ->where('config.intervalo_padrao', 10)
                ->where('config.intervalo_minimo', 2)
                ->where('empresa.chave', $this->conta()));

        $produtos = fn () => $this->actingAs($this->admin)->get(route('mlb.anuncios.publicador.produtos', ['conta' => $this->conta()]))->assertOk();
        $produtos()->assertInertia(fn ($p) => $p->where('fila_publicacao', null));

        $this->conferirTodas([$a]);
        $this->agendar([$a])->assertCreated();
        $produtos()->assertInertia(fn ($p) => $p->where('fila_publicacao.status', 'ativa')
            ->where('fila_publicacao.url', $this->rotaLote('index'))
            ->missing('fila_publicacao.itens'));
    }

    public function test_sem_admin_nao_entra(): void
    {
        $consultor = \App\Models\User::factory()->create(['role' => 'consultor']);
        $this->actingAs($consultor)->getJson($this->rotaLote('dados'))->assertForbidden();
        $this->actingAs($consultor)->postJson($this->rotaLote('agendar'), ['produtos' => [1]])->assertForbidden();
        $this->assertSame(0, PubFilaPublicacao::query()->count());
    }
}
