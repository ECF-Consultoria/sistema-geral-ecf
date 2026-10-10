<?php

namespace Tests\Feature\Publicador;

use App\Models\Configuracao;
use App\Models\PubImagem;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubTarefa;
use App\Models\User;
use App\Notifications\Categoria;
use App\Notifications\TarefaAlavancasNotification;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\PublicacaoService;
use App\Services\Publicador\Tarefas\TarefasPosPublicacao;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\RegeneradorVariantes;
use App\Support\Publicador\Variacao\ValorEixo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\Feature\Publicador\Concerns\CenarioTarefas;
use Tests\TestCase;

/**
 * O gatilho da tarefa pós-publicação (09/10/2026): publicar pelo Publicador abre a tarefa das alavancas
 * para outro colaborador, com os MLBs CRIADOS, prazo D+1 útil e o sino depois do commit. O ML é
 * simulado (mesmo cenário do `PublicacaoTest`); o relógio fica numa sexta-feira, 09/10/2026 15h, para
 * o prazo cair na terça 13/10 (sábado, domingo e Aparecida no meio).
 */
class TarefasPosPublicacaoTest extends TestCase
{
    use CenarioCadeira;
    use CenarioTarefas;
    use RefreshDatabase;

    private int $posts = 0;

    /** @var \Closure(array, int): mixed o `POST /items` agora */
    private \Closure $criar;

    private User $vitoria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-09 15:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        Notification::fake();
        $this->montarCenario();
        $this->vitoria = User::factory()->create(['name' => 'Vitória Publicadora', 'role' => 'admin']);

        $this->criar = fn (array $corpo, int $n) => Http::response(['id' => sprintf('MLB90000000%02d', $n), 'status' => 'active',
            'family_name' => $corpo['family_name'] ?? null, 'listing_type_id' => $corpo['listing_type_id'] ?? null], 201);

        $this->fakeMl([
            '*/oauth/token' => fn () => Http::response(['access_token' => 'novo-access', 'refresh_token' => 'novo-refresh', 'expires_in' => 21600, 'user_id' => 1555596317]),
            '*/items/*/description' => fn () => Http::response(['plain_text' => 'ok'], 200),
            '*/items/MLB*' => fn (Request $q) => Http::response(['id' => basename(parse_url($q->url(), PHP_URL_PATH)), 'status' => 'active', 'sub_status' => [],
                'tags' => [], 'permalink' => 'https://produto.mercadolivre.com.br/'.basename(parse_url($q->url(), PHP_URL_PATH)).'-cadeira-_JM']),
            '*/items' => fn (Request $q) => ($this->criar)($q->data(), ++$this->posts),
        ]);
    }

    private function publicar(): PubPublicacao
    {
        $v = app(ConferenciaService::class)->conferir($this->r->fresh());
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        $p = app(PublicacaoService::class)->iniciar($this->r->fresh(), AtorDoPortal::daEquipe($this->vitoria), cienteDosAvisos: true);
        $this->assertTrue(app(PublicacaoService::class)->executarFatia($p->fresh(), 45));

        return $p->fresh();
    }

    private function tresCores(): void
    {
        $nomes = ['52049' => 'Preto', '52028' => 'Azul', '52055' => 'Branco'];
        $cor = new Eixo('COLOR', 'Cor', 0, true, array_map(fn ($id, $n) => new ValorEixo((string) $id, $n), array_keys($nomes), $nomes));
        $variantes = array_map(fn ($v) => $v->comDados(['estoque' => 2, 'precos' => ['gold_special' => 150.0],
            'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD-'.$v->valores['COLOR']->valueName], 'GTIN' => ['value_name' => '7896553367645']]]),
            RegeneradorVariantes::regenerar($this->repo->snapshot($this->r->fresh())->variantes, [$cor])->variantes);
        $this->repo->gravarVariacao($this->r->fresh(), [$cor], $variantes);

        $atribuicoes = [['imagem' => $this->r->imagens()->value('id'), 'grupo' => R::GERAL, 'posicao' => 0]];
        foreach (array_keys($nomes) as $i => $id) {
            $foto = $this->r->imagens()->create(['caminho' => "publicador/{$id}.jpg", 'sha256' => str_repeat((string) $i, 64), 'mime' => 'image/jpeg', 'bytes' => 800_000,
                'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => "PIC-{$id}"]);
            $atribuicoes[] = ['imagem' => $foto->id, 'grupo' => "COLOR=id:{$id}", 'posicao' => 0];
        }
        $this->repo->gravarAtribuicoes($this->r, $atribuicoes);
    }

    private function recusarONumero(int $qual): void
    {
        $criar = $this->criar;
        $this->criar = fn (array $corpo, int $n) => $n === $qual
            ? Http::response(['message' => 'Validation error', 'error' => 'validation_error', 'status' => 400, 'cause' => [['cause_id' => 3701, 'type' => 'error', 'code' => 'item.attribute.invalid', 'message' => 'GTIN', 'references' => ['item.attributes']]]], 400)
            : $criar($corpo, $n);
    }

    // ═══ O gatilho ══════════════════════════════════════════════════════════

    public function test_publicado_abre_a_tarefa_com_o_mlb_criado_quem_publicou_prazo_d1_util_e_checklist_pendente(): void
    {
        $p = $this->publicar();
        $this->assertSame(PubPublicacao::PUBLISHED, $p->status);

        $t = PubTarefa::query()->sole();
        $this->assertSame(PubTarefa::TIPO_ALAVANCAS, $t->tipo);
        $this->assertSame(PubTarefa::PENDENTE, $t->status);
        $this->assertSame($p->id, $t->publicacao_id);
        $this->assertSame($this->produto->id, $t->produto_id);
        $this->assertSame($this->r->id, $t->rascunho_id);
        $this->assertSame($this->empresa->id, $t->company_id);
        $this->assertNull($t->mlb_empresa_id);
        $this->assertSame('company-'.$this->empresa->id, $t->conta_chave, 'a conta fixada no clique em Publicar');
        $this->assertSame([[
            'ml_item_id' => 'MLB9000000001', 'listing_type' => 'gold_special',
            'permalink' => 'https://produto.mercadolivre.com.br/MLB9000000001-cadeira-_JM',
            'titulo' => 'Cadeira Escritório Executiva ECF Giratória', 'publicacao_id' => $p->id,
        ]], $t->itens);
        $this->assertSame($this->vitoria->id, $t->publicado_por);
        $this->assertSame('2026-10-13', $t->prazo, 'sexta 09/10 → terça 13/10: sábado, domingo e Aparecida (12/10) no meio');
        $this->assertNull($t->responsavel_id, 'sem responsável padrão: fila comum');
        $this->assertSame(array_keys(PubTarefa::CHECKLIST_ALAVANCAS), array_keys($t->checklist));
        foreach ($t->checklist as $item) {
            $this->assertSame(PubTarefa::ITEM_PENDENTE, $item['estado']);
        }
    }

    public function test_parcial_abre_so_com_os_criados_e_a_retentativa_junta_o_novo_mlb_na_mesma_tarefa(): void
    {
        $this->tresCores();
        $this->recusarONumero(2);

        $p = $this->publicar();
        $this->assertSame(PubPublicacao::PARTIALLY_PUBLISHED, $p->status);

        $t = PubTarefa::query()->sole();
        $this->assertSame(['MLB9000000001', 'MLB9000000003'], $t->idsDosItens(), 'o item recusado não entra');

        // Retentar: o ML aceita o que faltou. Uma tarefa por produto — o MLB novo entra na que está aberta.
        $this->criar = fn (array $corpo, int $n) => Http::response(['id' => sprintf('MLB90000000%02d', $n), 'status' => 'active',
            'family_name' => $corpo['family_name'] ?? null], 201);
        $nova = app(PublicacaoService::class)->iniciar($this->r->fresh(), AtorDoPortal::daEquipe($this->vitoria), cienteDosAvisos: true);
        $this->assertTrue(app(PublicacaoService::class)->executarFatia($nova->fresh(), 45));
        $this->assertSame(PubPublicacao::PUBLISHED, $nova->fresh()->status);

        $this->assertSame(1, PubTarefa::query()->count(), 'não nasce uma segunda tarefa para o mesmo produto');
        $this->assertSame(['MLB9000000001', 'MLB9000000003', 'MLB9000000004'], $t->fresh()->idsDosItens());
        Notification::assertSentToTimes($this->vitoria, TarefaAlavancasNotification::class, 1);
    }

    public function test_retentativa_depois_da_tarefa_concluida_abre_outra_so_com_o_mlb_novo(): void
    {
        $this->tresCores();
        $this->recusarONumero(2);
        $this->publicar();
        $primeira = PubTarefa::query()->sole();
        $primeira->update(['status' => PubTarefa::FEITA, 'concluida_em' => now()]);

        $this->criar = fn (array $corpo, int $n) => Http::response(['id' => sprintf('MLB90000000%02d', $n), 'status' => 'active'], 201);
        $nova = app(PublicacaoService::class)->iniciar($this->r->fresh(), AtorDoPortal::daEquipe($this->vitoria), cienteDosAvisos: true);
        $this->assertTrue(app(PublicacaoService::class)->executarFatia($nova->fresh(), 45));

        $segunda = PubTarefa::query()->where('id', '!=', $primeira->id)->sole();
        $this->assertSame($nova->id, $segunda->publicacao_id);
        $this->assertSame(['MLB9000000004'], $segunda->idsDosItens(), 'o MLB novo precisa das alavancas; os da concluída não voltam');
    }

    public function test_publicacao_que_falhou_inteira_nao_abre_tarefa(): void
    {
        $this->recusarONumero(1);

        $p = $this->publicar();

        $this->assertSame(PubPublicacao::FAILED, $p->status);
        $this->assertSame(0, PubTarefa::query()->count());
        Notification::assertNothingSent();
    }

    public function test_interrompida_depois_de_criar_um_item_abre_a_tarefa_com_o_que_esta_no_ar(): void
    {
        $this->tresCores();
        $criar = $this->criar;
        // O 1º item é criado; logo depois a conta sai da lista de publicação: o resto não vai (CR-B01).
        $this->criar = function (array $corpo, int $n) use ($criar) {
            config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);

            return $criar($corpo, $n);
        };

        $p = $this->publicar();

        $this->assertSame(PubPublicacao::FAILED, $p->status);
        $this->assertSame(PubRascunho::PARTIALLY_PUBLISHED, $this->r->fresh()->status);
        $this->assertSame(['MLB9000000001'], PubTarefa::query()->sole()->idsDosItens(), 'o que foi criado está no ar e precisa das alavancas');
    }

    public function test_abrir_de_novo_a_mesma_publicacao_nao_duplica(): void
    {
        $p = $this->publicar();
        $primeira = PubTarefa::query()->sole();

        $de_novo = app(TarefasPosPublicacao::class)->abrir($p->fresh());
        $outra_vez = app(TarefasPosPublicacao::class)->abrir($p->fresh());

        $this->assertSame($primeira->id, $de_novo->id);
        $this->assertSame($primeira->id, $outra_vez->id);
        $this->assertSame(1, PubTarefa::query()->count());
    }

    public function test_publicacao_ainda_rodando_nao_abre_nada(): void
    {
        $p = $this->publicacaoConcluida($this->empresa, ['MLB777' => 'gold_special'], PubPublicacao::RUNNING);

        $this->assertNull(app(TarefasPosPublicacao::class)->abrir($p));
        $this->assertSame(0, PubTarefa::query()->count());
    }

    public function test_falha_no_gatilho_nao_afeta_a_publicacao(): void
    {
        $this->mock(TarefasPosPublicacao::class, fn ($m) => $m->shouldReceive('abrir')->andThrow(new \RuntimeException('banco fora do ar')));
        Log::spy();

        $p = $this->publicar();

        $this->assertSame(PubPublicacao::PUBLISHED, $p->status, 'o anúncio está no ML: a publicação segue concluída');
        $this->assertSame(PubRascunho::PUBLISHED, $this->r->fresh()->status);
        $this->assertSame('MLB9000000001', $p->itens()->sole()->ml_item_id);
        $this->assertSame(1, $this->posts, 'nada é repetido');
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains((string) $m, 'tarefa pós-publicação não abriu') && str_contains((string) $m, 'banco fora do ar'));
    }

    // ═══ Responsável e sino ═════════════════════════════════════════════════

    public function test_responsavel_configurado_recebe_a_tarefa_e_so_ele_e_avisado(): void
    {
        $caio = $this->comPermissao(nome: 'Caio');
        $outroAdmin = User::factory()->create(['role' => 'admin']);
        Configuracao::set(TarefasPosPublicacao::CHAVE_RESPONSAVEL, $caio->id);

        $this->publicar();

        $t = PubTarefa::query()->sole();
        $this->assertSame($caio->id, $t->responsavel_id);
        Notification::assertSentTo($caio, TarefaAlavancasNotification::class, function (TarefaAlavancasNotification $n) use ($t, $caio) {
            $dados = $n->toArray($caio);

            return $dados['categoria'] === Categoria::TAREFA_ALAVANCAS->value
                && $dados['url'] === "/mlb/anuncios/publicador/tarefas?tarefa={$t->id}"
                && str_contains($dados['mensagem'], 'Publicado por Vitória Publicadora')
                && str_contains($dados['mensagem'], 'prazo 13/10')
                && $dados['meta'] === ['tarefa_id' => $t->id];
        });
        Notification::assertNotSentTo($outroAdmin, TarefaAlavancasNotification::class);
        Notification::assertNotSentTo($this->vitoria, TarefaAlavancasNotification::class);
    }

    public function test_responsavel_inativo_cai_na_fila_comum_e_avisa_quem_ve_a_fila(): void
    {
        $inativo = $this->comPermissao(nome: 'Saiu da empresa');
        $inativo->update(['active' => false]);
        Configuracao::set(TarefasPosPublicacao::CHAVE_RESPONSAVEL, $inativo->id);
        $caio = $this->comPermissao(nome: 'Caio');
        $semAcesso = User::factory()->create(['role' => 'consultor']);

        $this->publicar();

        $this->assertNull(PubTarefa::query()->sole()->responsavel_id);
        Notification::assertSentTo($caio, TarefaAlavancasNotification::class);
        // Admin vê a fila (passa por qualquer chave); consultor sem `mlb.alavancas`, não.
        Notification::assertSentTo($this->vitoria, TarefaAlavancasNotification::class);
        Notification::assertNotSentTo($inativo, TarefaAlavancasNotification::class);
        Notification::assertNotSentTo($semAcesso, TarefaAlavancasNotification::class);
    }

    public function test_responsavel_que_perdeu_a_chave_da_fila_tambem_cai_na_fila_comum(): void
    {
        $semChave = User::factory()->create(['role' => 'consultor']);
        Configuracao::set(TarefasPosPublicacao::CHAVE_RESPONSAVEL, $semChave->id);

        $this->publicar();

        $this->assertNull(PubTarefa::query()->sole()->responsavel_id);
        Notification::assertNotSentTo($semChave, TarefaAlavancasNotification::class);
    }

    public function test_o_aviso_so_sai_depois_do_commit(): void
    {
        $p = $this->publicacaoConcluida($this->empresa, ['MLB555' => 'gold_special']);
        Notification::fake();

        try {
            DB::transaction(function () use ($p) {
                app(TarefasPosPublicacao::class)->abrir($p);
                throw new \RuntimeException('a transação de fora desfez tudo');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, PubTarefa::query()->count());
        Notification::assertNothingSent();

        app(TarefasPosPublicacao::class)->abrir($p);
        Notification::assertSentTo($this->vitoria, TarefaAlavancasNotification::class);
    }

    public function test_conta_nao_liberada_para_as_alavancas_tambem_ganha_tarefa(): void
    {
        config(['publicador.alavancas.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);

        $this->publicar();

        $this->assertSame(1, PubTarefa::query()->count(), 'a equipe faz no Seller Center e marca na fila');
    }

    public function test_apagar_a_publicacao_mantem_a_tarefa(): void
    {
        $p = $this->publicar();
        $t = PubTarefa::query()->sole();

        PubPublicacaoItem::query()->where('publicacao_id', $p->id)->delete();
        $p->delete();

        $this->assertNull($t->fresh()->publicacao_id, 'SET NULL: a tarefa e os MLBs que ela registrou ficam');
        $this->assertSame(['MLB9000000001'], $t->fresh()->idsDosItens());
    }
}
