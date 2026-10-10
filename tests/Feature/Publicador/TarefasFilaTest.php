<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\PubTarefa;
use App\Models\User;
use App\Services\Publicador\Tarefas\TarefasPosPublicacao;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Concerns\CenarioTarefas;
use Tests\TestCase;

/**
 * A fila "Publicados aguardando alavancas" (09/10/2026): acesso pela chave `mlb.alavancas` (admin passa),
 * filtros, pegar, marcar, "não se aplica" com motivo, concluir só com tudo resolvido, observação,
 * responsável padrão só do admin e isolamento por conta. Nada aqui fala com o Mercado Livre.
 */
class TarefasFilaTest extends TestCase
{
    use CenarioTarefas;
    use RefreshDatabase;

    private const BASE = '/mlb/anuncios/publicador/tarefas';

    private User $admin;

    private Company $loja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        // Quinta 15/10/2026: prazos de 13/10 já venceram, 15/10 é hoje, 16/10 é amanhã.
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-15 10:00:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin ECF']);
        $this->loja = Company::factory()->create(['name' => 'Loja do Puff']);
    }

    private function pagina(User $u, string $query = ''): array
    {
        return $this->actingAs($u)->get(self::BASE.$query)->assertOk()->viewData('page');
    }

    // ═══ Acesso ═════════════════════════════════════════════════════════════

    public function test_consultor_sem_a_chave_leva_403_e_quem_tem_mlb_alavancas_ve_e_opera(): void
    {
        $t = $this->tarefaDa($this->loja);
        $consultor = User::factory()->create(['role' => 'consultor']);
        $publicador = $this->comPermissao(Permissions::MLB_ANUNCIAR, 'Só publica');
        $caio = $this->comPermissao();

        $this->actingAs($consultor)->get(self::BASE)->assertForbidden();
        $this->actingAs($consultor)->post(self::BASE."/{$t->id}/pegar")->assertForbidden();
        $this->actingAs($publicador)->get(self::BASE)->assertForbidden(); // mlb.anunciar não dá a fila

        $page = $this->pagina($caio);
        $this->assertSame('Mlb/Publicador/Tarefas', $page['component']);
        $this->assertCount(1, $page['props']['tarefas']);
        $this->assertFalse($page['props']['pode_configurar']);
        $this->assertFalse($page['props']['pode_escrever']);
        $this->assertSame([], $page['props']['candidatos']);
        $this->assertNull($page['props']['tarefas'][0]['itens'][0]['url_alavancas'], 'Alavancas (escrever no ML) é só admin');

        $this->actingAs($caio)->post(self::BASE."/{$t->id}/pegar")->assertRedirect();
        $this->assertSame($caio->id, $t->fresh()->responsavel_id);
        $this->actingAs($caio)->put(self::BASE.'/responsavel-padrao', ['responsavel_id' => $caio->id])->assertForbidden();
    }

    public function test_a_linha_traz_empresa_produto_mlbs_com_link_quem_publicou_prazo_e_checklist(): void
    {
        config(['publicador.alavancas.contas_liberadas' => ['companies' => [$this->loja->id], 'mlb_empresas' => []]]);
        $vitoria = User::factory()->create(['role' => 'admin', 'name' => 'Vitória']);
        $t = $this->tarefaDa($this->loja, ator: $vitoria);

        $linha = $this->pagina($this->admin)['props']['tarefas'][0];

        $this->assertSame($t->id, $linha['id']);
        $this->assertSame('Loja do Puff', $linha['empresa']['nome']);
        $this->assertSame('company-'.$this->loja->id, $linha['empresa']['conta']);
        $this->assertSame('Puff Redondo', $linha['produto']['nome']);
        $this->assertSame(['MLB1001', 'MLB1002'], array_column($linha['itens'], 'ml_item_id'));
        $this->assertSame(['Clássico', 'Premium'], array_column($linha['itens'], 'tipo'));
        $this->assertSame('https://produto.mercadolivre.com.br/MLB1001-teste-_JM', $linha['itens'][0]['permalink']);
        $this->assertStringContainsString('/mlb/anuncios/publicador/empresas/company-'.$this->loja->id.'/alavancas?aba=promocoes&item=MLB1001', $linha['itens'][0]['url_alavancas']);
        $this->assertSame('Vitória', $linha['publicado_por']);
        $this->assertSame('2026-10-16', $linha['prazo'], 'publicada hoje (quinta) → D+1 útil sexta');
        $this->assertSame('programado', $linha['selo']);
        $this->assertNull($linha['responsavel']);
        $this->assertSame(array_keys(PubTarefa::CHECKLIST_ALAVANCAS), array_column($linha['checklist'], 'chave'));
        $this->assertSame('Central de Promoções', $linha['checklist'][0]['rotulo']);
        $this->assertFalse($linha['resolvida']);
        $this->assertTrue($linha['liberada'], 'conta na lista das Alavancas');
    }

    public function test_selo_atrasado_hoje_e_programado_e_contagens_do_cabecalho(): void
    {
        $atrasada = $this->tarefaDa($this->loja, ['MLB1' => 'gold_special']);
        $atrasada->update(['prazo' => '2026-10-13']);
        $hoje = $this->tarefaDa($this->loja, ['MLB2' => 'gold_special']);
        $hoje->update(['prazo' => '2026-10-15', 'responsavel_id' => $this->admin->id]);
        $this->tarefaDa($this->loja, ['MLB3' => 'gold_special']); // prazo 16/10
        $feita = $this->tarefaDa($this->loja, ['MLB4' => 'gold_special']);
        $feita->update(['status' => PubTarefa::FEITA, 'concluida_em' => now()]);

        $p = $this->pagina($this->admin)['props'];

        $this->assertSame(['atrasado', 'hoje', 'programado'], array_column($p['tarefas'], 'selo'), 'abertas, o prazo mais curto em cima; a feita não aparece');
        $this->assertSame(['abertas' => 3, 'minhas' => 1, 'atrasadas' => 1, 'hoje' => 1], $p['contagens']);
    }

    public function test_filtros_minhas_status_e_tarefa_vinda_do_sino(): void
    {
        $minha = $this->tarefaDa($this->loja, ['MLB1' => 'gold_special']);
        $minha->update(['responsavel_id' => $this->admin->id, 'status' => PubTarefa::EM_ANDAMENTO]);
        $deNinguem = $this->tarefaDa($this->loja, ['MLB2' => 'gold_special']);
        $feita = $this->tarefaDa($this->loja, ['MLB3' => 'gold_special']);
        $feita->update(['status' => PubTarefa::FEITA, 'concluida_em' => now()]);

        $ids = fn (string $q) => array_column($this->pagina($this->admin, $q)['props']['tarefas'], 'id');

        $this->assertEqualsCanonicalizing([$minha->id, $deNinguem->id], $ids(''), 'padrão: todas as abertas');
        $this->assertSame([$minha->id], $ids('?escopo=minhas'));
        $this->assertSame([$deNinguem->id], $ids('?status=pendente'));
        $this->assertSame([$minha->id], $ids('?status=em_andamento'));
        $this->assertSame([$feita->id], $ids('?status=feita'));
        $this->assertContains($feita->id, $ids("?tarefa={$feita->id}"), 'vinda do sino, aparece qualquer que seja o status');
        $this->assertSame($feita->id, $this->pagina($this->admin, "?tarefa={$feita->id}")['props']['tarefa_destacada']);
    }

    public function test_isolamento_por_conta_no_filtro(): void
    {
        $outra = Company::factory()->create(['name' => 'Outra Loja']);
        $daLoja = $this->tarefaDa($this->loja, ['MLB1' => 'gold_special']);
        $this->tarefaDa($outra, ['MLB2' => 'gold_special']);
        $incubadora = MlbEmpresa::create(['nome' => 'Loja Incubadora', 'projeto' => 'Incubadora'])->fresh();
        $daIncubadora = $this->tarefaDa($incubadora, ['MLB3' => 'gold_special']);

        $p = $this->pagina($this->admin, '?conta=company-'.$this->loja->id)['props'];
        $this->assertSame([$daLoja->id], array_column($p['tarefas'], 'id'));
        $this->assertSame('company-'.$this->loja->id, $p['filtros']['conta']);
        $this->assertSame('Loja do Puff', $p['filtros']['conta_nome']);
        $this->assertSame(1, $p['contagens']['abertas'], 'as contagens também são da conta');

        $p = $this->pagina($this->admin, '?conta=empresa-'.$incubadora->id)['props'];
        $this->assertSame([$daIncubadora->id], array_column($p['tarefas'], 'id'));
        $this->assertSame('empresa-'.$incubadora->id, $p['tarefas'][0]['empresa']['conta'], 'MlbEmpresa sem Company abre pela chave dela');

        $this->assertCount(3, $this->pagina($this->admin, '?conta=lixo')['props']['tarefas'], 'conta inválida = sem filtro');
    }

    // ═══ Ações ══════════════════════════════════════════════════════════════

    public function test_pegar_vira_responsavel_e_em_andamento(): void
    {
        $t = $this->tarefaDa($this->loja);

        $this->actingAs($this->admin)->post(self::BASE."/{$t->id}/pegar")->assertRedirect()->assertSessionHas('success');

        $t->refresh();
        $this->assertSame($this->admin->id, $t->responsavel_id);
        $this->assertSame(PubTarefa::EM_ANDAMENTO, $t->status);
        $this->assertNotNull($t->iniciada_em);
    }

    public function test_marcar_feito_nao_se_aplica_com_motivo_obrigatorio_e_voltar_a_pendente(): void
    {
        $t = $this->tarefaDa($this->loja);
        $rota = fn (string $chave) => self::BASE."/{$t->id}/itens/{$chave}";

        $this->actingAs($this->admin)->put($rota('central_promocao'), ['estado' => 'feito'])->assertSessionHasNoErrors();
        $item = $t->fresh()->checklist['central_promocao'];
        $this->assertSame('feito', $item['estado']);
        $this->assertSame(['id' => $this->admin->id, 'nome' => 'Admin ECF'], $item['por']);
        $this->assertNull($item['escrita_id']);
        $this->assertSame(PubTarefa::EM_ANDAMENTO, $t->fresh()->status, 'a primeira ação tira de pendente');

        $this->actingAs($this->admin)->put($rota('afiliados'), ['estado' => 'nao_se_aplica'])->assertSessionHasErrors('motivo');
        $this->actingAs($this->admin)->put($rota('afiliados'), ['estado' => 'nao_se_aplica', 'motivo' => '   '])->assertSessionHasErrors('motivo');
        $this->assertSame('pendente', $t->fresh()->checklist['afiliados']['estado']);

        $this->actingAs($this->admin)->put($rota('afiliados'), ['estado' => 'nao_se_aplica', 'motivo' => 'Loja sem programa de afiliados'])->assertSessionHasNoErrors();
        $this->assertSame('Loja sem programa de afiliados', $t->fresh()->checklist['afiliados']['motivo']);

        $this->actingAs($this->admin)->put($rota('central_promocao'), ['estado' => 'pendente'])->assertSessionHasNoErrors();
        $this->assertSame(['estado' => 'pendente', 'motivo' => null, 'por' => null, 'em' => null, 'escrita_id' => null], $t->fresh()->checklist['central_promocao']);

        $this->actingAs($this->admin)->put($rota('inventado'), ['estado' => 'feito'])->assertNotFound();
        $this->actingAs($this->admin)->put($rota('cupom'), ['estado' => 'talvez'])->assertSessionHasErrors('estado');
    }

    public function test_concluir_exige_todos_os_itens_resolvidos(): void
    {
        $t = $this->tarefaDa($this->loja);
        $marcar = fn (string $chave, string $estado, ?string $motivo = null) => $this->actingAs($this->admin)
            ->put(self::BASE."/{$t->id}/itens/{$chave}", array_filter(['estado' => $estado, 'motivo' => $motivo]));

        $marcar('central_promocao', 'feito');
        $this->actingAs($this->admin)->post(self::BASE."/{$t->id}/concluir")->assertSessionHasErrors('tarefa');
        $this->assertSame(PubTarefa::EM_ANDAMENTO, $t->fresh()->status);

        foreach (['ads_lancamento', 'atacado', 'cupom'] as $chave) {
            $marcar($chave, 'feito');
        }
        $marcar('afiliados', 'nao_se_aplica', 'Sem afiliados');
        $marcar('lista_transmissao', 'nao_se_aplica', 'Sem lista');

        $this->actingAs($this->admin)->post(self::BASE."/{$t->id}/concluir")->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame(PubTarefa::FEITA, $t->status);
        $this->assertNotNull($t->concluida_em);
        $this->assertSame($this->admin->id, $t->responsavel_id, 'quem conclui fica registrado');

        // Concluída: não muda mais o checklist nem é pega de novo; a observação continua.
        $marcar('cupom', 'pendente')->assertSessionHasErrors('tarefa');
        $this->actingAs($this->admin)->post(self::BASE."/{$t->id}/pegar")->assertSessionHasErrors('tarefa');
        $this->actingAs($this->admin)->put(self::BASE."/{$t->id}/observacao", ['observacao' => 'Cupom de 10% até o fim do mês'])->assertSessionHasNoErrors();
        $this->assertSame('Cupom de 10% até o fim do mês', $t->fresh()->observacao);
    }

    public function test_observacao_salva_e_limpa(): void
    {
        $t = $this->tarefaDa($this->loja);

        $this->actingAs($this->admin)->put(self::BASE."/{$t->id}/observacao", ['observacao' => '  Ver com o cliente o ADS  '])->assertSessionHasNoErrors();
        $this->assertSame('Ver com o cliente o ADS', $t->fresh()->observacao);

        $this->actingAs($this->admin)->put(self::BASE."/{$t->id}/observacao", ['observacao' => ''])->assertSessionHasNoErrors();
        $this->assertNull($t->fresh()->observacao);

        $this->actingAs($this->admin)->put(self::BASE."/{$t->id}/observacao", ['observacao' => str_repeat('a', 2001)])->assertSessionHasErrors('observacao');
    }

    public function test_tarefa_de_outro_tipo_nao_e_endereçavel_pela_fila(): void
    {
        $t = $this->tarefaDa($this->loja);
        $t->update(['tipo' => 'validacao']);

        $this->actingAs($this->admin)->post(self::BASE."/{$t->id}/pegar")->assertNotFound();
        $this->assertSame([], $this->pagina($this->admin)['props']['tarefas']);
    }

    // ═══ Responsável padrão ════════════════════════════════════════════════

    public function test_responsavel_padrao_so_admin_escolhe_entre_quem_ve_a_fila(): void
    {
        $caio = $this->comPermissao(nome: 'Caio');
        $semChave = User::factory()->create(['role' => 'consultor', 'name' => 'Sem chave']);

        $p = $this->pagina($this->admin)['props'];
        $this->assertTrue($p['pode_configurar']);
        $this->assertContains('Caio', array_column($p['candidatos'], 'nome'));
        $this->assertNotContains('Sem chave', array_column($p['candidatos'], 'nome'));
        $this->assertSame(['id' => null, 'nome' => null, 'invalido' => false], $p['responsavel_padrao']);

        $this->actingAs($this->admin)->put(self::BASE.'/responsavel-padrao', ['responsavel_id' => $semChave->id])->assertSessionHasErrors('responsavel_id');
        $this->assertNull(Configuracao::get(TarefasPosPublicacao::CHAVE_RESPONSAVEL));

        $this->actingAs($this->admin)->put(self::BASE.'/responsavel-padrao', ['responsavel_id' => $caio->id])->assertSessionHasNoErrors();
        $this->assertSame((string) $caio->id, (string) Configuracao::get(TarefasPosPublicacao::CHAVE_RESPONSAVEL));
        $this->assertSame(['id' => $caio->id, 'nome' => 'Caio', 'invalido' => false], $this->pagina($this->admin)['props']['responsavel_padrao']);

        // A próxima tarefa já nasce com ele; a que já existia não muda de dono.
        $this->assertSame($caio->id, $this->tarefaDa($this->loja, ['MLB9' => 'gold_special'])->responsavel_id);

        // Caio sai da empresa: a tela avisa e as próximas caem na fila comum.
        $caio->update(['active' => false]);
        $this->assertTrue($this->pagina($this->admin)['props']['responsavel_padrao']['invalido']);

        $this->actingAs($this->admin)->put(self::BASE.'/responsavel-padrao', ['responsavel_id' => null])->assertSessionHasNoErrors();
        $this->assertNull(app(TarefasPosPublicacao::class)->responsavelPadraoGravado());
    }

    public function test_conta_fora_da_lista_das_alavancas_vem_marcada_para_fazer_no_seller_center(): void
    {
        config(['publicador.alavancas.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);
        $this->tarefaDa($this->loja);

        $this->assertFalse($this->pagina($this->admin)['props']['tarefas'][0]['liberada']);
    }
}
