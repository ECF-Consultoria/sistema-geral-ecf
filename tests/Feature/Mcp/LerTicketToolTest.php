<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Acoes\CatalogoDeAcoes;
use App\Mcp\Telas\CatalogoDeTelas;
use App\Models\Chamado;
use App\Models\User;
use App\Services\DevDemandas\ChamadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\DemandasDev\LiberaModulosDev;
use Tests\TestCase;

/**
 * `ler_ticket` (06/10/2026): a lista de tickets e o detalhe com mensagens e
 * prints, pelo MCP de verdade (`/mcp` com token do Passport), com as mesmas
 * regras das telas — a equipe dev vê a caixa da equipe e as notas internas;
 * quem só abriu vê os próprios tickets, sem nota interna.
 */
class LerTicketToolTest extends TestCase
{
    use ChamaMcp, LiberaModulosDev, RefreshDatabase;

    private User $maycon;

    private User $barreto;

    private User $debora;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        Storage::fake('local');
        $this->withoutVite();
        $this->liberarModulosDev();
        Carbon::setTestNow('2026-10-06 10:00:00');

        $this->maycon  = $this->dev('Maycon Gomes');
        $this->barreto = $this->dev('Matheus Barreto');
        $this->debora  = $this->colaborador('Débora Lima');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dev(string $nome): User
    {
        $u = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);
        $u->forceFill(['is_dev' => true])->save();

        return $u->refresh();
    }

    private function colaborador(string $nome): User
    {
        return User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);
    }

    /** Abre pelo mesmo serviço da tela /tickets. */
    private function abrir(User $quem, ?User $para, string $titulo, array $arquivos = []): Chamado
    {
        return app(ChamadoService::class)->abrir($quem, [
            'tipo' => 'problema', 'area' => 'Entrada', 'responsavel_id' => $para?->id,
            'titulo' => $titulo, 'descricao' => "Descrição de {$titulo}", 'impacto' => 'normal',
        ], $arquivos);
    }

    private function responder(Chamado $c, User $autor, string $texto, bool $interna = false, array $arquivos = []): void
    {
        app(ChamadoService::class)->responder($c, $autor, $texto, $interna, $arquivos);
    }

    /**
     * `ler_ticket` responde texto (JSON) + imagens.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function lerTicket(User $u, array $args = []): array
    {
        $r = $this->rpc($u, 'tools/call', ['name' => 'ler_ticket', 'arguments' => (object) $args])->assertOk()->json('result');
        $this->assertFalse($r['isError'] ?? false, 'ler_ticket deu erro: '.($r['content'][0]['text'] ?? ''));

        $imagens = array_values(array_filter($r['content'], fn ($c) => $c['type'] === 'image'));

        return [json_decode($r['content'][0]['text'], true), $imagens];
    }

    private function codigos(array $lista): array
    {
        return collect($lista['itens'])->pluck('codigo')->sort()->values()->all();
    }

    // ═══ Lista ═══

    public function test_dev_ve_na_lista_os_tickets_que_outros_abriram_para_ele_e_a_fila(): void
    {
        $paraMaycon  = $this->abrir($this->debora, $this->maycon, 'Para o Maycon');
        $fila        = $this->abrir($this->debora, null, 'Sem responsável');
        $paraBarreto = $this->abrir($this->debora, $this->barreto, 'Para o Barreto');
        $doMaycon    = $this->abrir($this->maycon, $this->barreto, 'Maycon pediu ao Barreto');

        [$lista] = $this->lerTicket($this->maycon);

        $this->assertSame(
            collect([$paraMaycon, $fila, $doMaycon])->pluck('codigo')->sort()->values()->all(),
            $this->codigos($lista),
            'caixa da equipe (dele + fila) e o que ele abriu; o do outro dev não'
        );
        $this->assertNotContains($paraBarreto->codigo, $this->codigos($lista));
        $this->assertStringContainsString('equipe', $lista['visao']);

        // A mesma caixa da tela /dev/demandas, que é onde o navegador a mostra.
        $caixa = collect($this->actingAs($this->maycon)->get('/dev/demandas')->viewData('page')['props']['chamados'])->pluck('codigo');
        foreach ($caixa as $codigo) {
            $this->assertContains($codigo, $this->codigos($lista));
        }
    }

    public function test_admin_dev_ve_todos_os_tickets_inclusive_os_de_outro_dev(): void
    {
        // Em produção o Maycon é admin E dev. Para admin o daEquipe() não tem
        // filtro — e era aí que a lista caía para "só os que ele abriu".
        $this->maycon->forceFill(['role' => 'admin'])->save();
        $paraMaycon  = $this->abrir($this->debora, $this->maycon, 'Para o Maycon');
        $paraBarreto = $this->abrir($this->debora, $this->barreto, 'Para o Barreto');
        $fila        = $this->abrir($this->debora, null, 'Sem responsável');
        $doMaycon    = $this->abrir($this->maycon, $this->maycon, 'Teste do MCP');

        $todos = collect([$paraMaycon, $paraBarreto, $fila, $doMaycon])->pluck('codigo')->sort()->values()->all();
        $this->assertSame($todos, $this->codigos($this->lerTicket($this->maycon)[0]));
        $this->assertSame($todos, $this->codigos($this->lerTicket($this->maycon, ['status' => 'abertos'])[0]));

        // Admin que não é dev também: é a caixa inteira, como em /dev/demandas.
        $this->assertSame($todos, $this->codigos($this->lerTicket($this->admin())[0]));
    }

    public function test_nao_dev_ve_so_os_proprios_tickets(): void
    {
        $daDebora = $this->abrir($this->debora, $this->maycon, 'Da Débora');
        $karen    = $this->colaborador('Karen Souza');
        $daKaren  = $this->abrir($karen, $this->maycon, 'Da Karen');

        [$lista] = $this->lerTicket($this->debora);

        $this->assertSame([$daDebora->codigo], $this->codigos($lista));
        $this->assertArrayNotHasKey('precisa_atencao', $lista['itens'][0], 'campo de equipe não vai para quem só pediu');
        $this->assertNotContains($daKaren->codigo, $this->codigos($lista));
    }

    public function test_filtro_de_abertos_e_busca(): void
    {
        $aberto    = $this->abrir($this->debora, $this->maycon, 'Relatório de NPS');
        $resolvido = $this->abrir($this->debora, $this->maycon, 'Coluna de bônus');
        app(ChamadoService::class)->resolver($resolvido, $this->maycon, 'Feito.');

        $this->assertSame([$aberto->codigo], $this->codigos($this->lerTicket($this->maycon, ['status' => 'abertos'])[0]));
        $this->assertSame([$resolvido->codigo], $this->codigos($this->lerTicket($this->maycon, ['busca' => 'bônus'])[0]));
    }

    // ═══ Detalhe ═══

    public function test_dev_abre_ticket_de_outro_solicitante_com_descricao_e_mensagens_inclusive_internas(): void
    {
        $c = $this->abrir($this->debora, $this->maycon, 'Comentário Faturamento Polos');
        $this->responder($c, $this->maycon, 'Olhando o banco.', interna: true);
        $this->responder($c, $this->maycon, 'Consegue mandar um print?');
        $this->responder($c, $this->debora, 'Mando já.');

        [$t] = $this->lerTicket($this->maycon, ['ticket' => $c->codigo]);

        $this->assertSame('Descrição de Comentário Faturamento Polos', $t['descricao']);
        $this->assertSame('Débora Lima', $t['solicitante']);
        $mensagens = collect($t['linha_do_tempo'])->where('item', 'mensagem');
        $this->assertSame(['Olhando o banco.', 'Consegue mandar um print?', 'Mando já.'], $mensagens->pluck('texto')->values()->all());
        $this->assertTrue($mensagens->firstWhere('texto', 'Olhando o banco.')['interna']);
        $this->assertTrue($t['pode']['atuar']);
    }

    public function test_quem_abriu_nao_ve_nota_interna_e_nao_abre_ticket_alheio(): void
    {
        $c = $this->abrir($this->debora, $this->maycon, 'Da Débora');
        $this->responder($c, $this->maycon, 'SEGREDO da equipe', interna: true);
        $this->responder($c, $this->maycon, 'Resposta pública');

        [$t] = $this->lerTicket($this->debora, ['ticket' => (string) $c->id]);

        $textos = collect($t['linha_do_tempo'])->where('item', 'mensagem')->pluck('texto')->all();
        $this->assertSame(['Resposta pública'], $textos);
        $this->assertStringNotContainsString('SEGREDO', json_encode($t, JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('pode', $t);

        // Ticket de outra pessoa: igual ao que não existe.
        $karen = $this->colaborador('Karen Souza');
        $this->assertStringContainsString('não encontrado', $this->erroDaFerramenta($karen, 'ler_ticket', ['ticket' => $c->codigo]));
        $this->assertStringContainsString('não encontrado', $this->erroDaFerramenta($karen, 'ler_ticket', ['ticket' => 'TKT-9999']));

        // E o dev que não atende o ticket (é do Maycon) também não abre.
        $this->assertStringContainsString('não encontrado', $this->erroDaFerramenta($this->barreto, 'ler_ticket', ['ticket' => $c->codigo]));
    }

    public function test_o_print_vem_como_imagem_e_o_de_nota_interna_so_para_a_equipe(): void
    {
        $print = UploadedFile::fake()->image('print-da-tela.png', 2400, 1200);
        $c     = $this->abrir($this->debora, $this->maycon, 'Erro na tela', [$print]);
        $this->responder($c, $this->maycon, 'Print do log', interna: true, arquivos: [UploadedFile::fake()->image('log.png', 300, 200)]);
        $this->responder($c, $this->debora, 'Outro print', arquivos: [UploadedFile::fake()->image('outro.jpg', 800, 600)]);

        [$t, $imagens] = $this->lerTicket($this->maycon, ['ticket' => $c->codigo]);

        $this->assertCount(3, $imagens);
        $this->assertSame(['print-da-tela.png', 'log.png', 'outro.jpg'], collect($t['imagens_no_retorno'])->pluck('nome')->all());
        // Print grande vai reduzido (lado maior até 1568) em JPEG.
        $this->assertSame('image/jpeg', $imagens[0]['mimeType']);
        [$largura, $altura] = getimagesizefromstring(base64_decode($imagens[0]['data']));
        $this->assertSame([1568, 784], [$largura, $altura]);
        // Pequeno vai como está.
        $this->assertSame('image/png', $imagens[1]['mimeType']);

        // Quem abriu: o print dela e o da resposta pública; o da nota interna não.
        [$t, $imagens] = $this->lerTicket($this->debora, ['ticket' => $c->codigo]);
        $this->assertCount(2, $imagens);
        $this->assertSame(['print-da-tela.png', 'outro.jpg'], collect($t['imagens_no_retorno'])->pluck('nome')->all());

        // Sem imagens quando pedido.
        [, $imagens] = $this->lerTicket($this->maycon, ['ticket' => $c->codigo, 'imagens' => false]);
        $this->assertSame([], $imagens);
    }

    public function test_ler_pelo_mcp_nao_marca_o_aviso_como_lido(): void
    {
        $c = $this->abrir($this->debora, $this->maycon, 'Da Débora');
        $this->responder($c, $this->maycon, 'Resposta pública');
        $antes = $this->debora->unreadNotifications()->count();
        $this->assertGreaterThan(0, $antes);

        $this->lerTicket($this->debora, ['ticket' => $c->codigo]);

        // Abrir /tickets/{id} no navegador marca; ler pelo MCP não.
        $this->assertSame($antes, $this->debora->unreadNotifications()->count());
    }

    // ═══ Cliente com a lista de ferramentas antiga (cache do claude.ai) ═══

    public function test_ler_tela_de_chamados_index_avisa_onde_esta_a_caixa_da_equipe(): void
    {
        $this->abrir($this->debora, $this->maycon, 'Para o Maycon');
        $lerTela = fn (User $u) => json_decode($this->rpc($u, 'tools/call', ['name' => 'ler_tela', 'arguments' => (object) [
            'tela' => 'chamados.index',
        ]])->assertOk()->json('result.content.0.text'), true);

        // Dev: a tela continua igual (só os que ele abriu), mas a resposta
        // aponta a caixa da equipe — que funciona até sem o ler_ticket.
        $tela = $lerTela($this->maycon);
        $this->assertStringContainsString('SÓ os tickets que você ABRIU', $tela['aviso']);
        $this->assertStringContainsString('dev.demandas.index', $tela['aviso']);

        $caixa = json_decode($this->rpc($this->maycon, 'tools/call', ['name' => 'ler_tela', 'arguments' => (object) [
            'tela' => 'dev.demandas.index', 'campo' => 'chamados',
        ]])->assertOk()->json('result.content.0.text'), true);
        $this->assertSame(['Para o Maycon'], collect($caixa['itens'])->pluck('titulo')->all());

        // Quem não é da equipe não recebe caminho para a caixa (que não abre para ele).
        $tela = $lerTela($this->debora);
        $this->assertStringNotContainsString('dev.demandas.index', $tela['aviso']);
        $this->assertStringContainsString('ler_ticket', $tela['aviso']);

        // A busca por ticket no listar_telas leva a mesma dica.
        $this->assertStringContainsString('dev.demandas.index', $this->ferramenta($this->maycon, 'listar_telas', ['busca' => 'ticket'])['dica']);
        $this->assertArrayNotHasKey('dica', $this->ferramenta($this->maycon, 'listar_telas', ['busca' => 'nps']));
    }

    // ═══ Catálogo ═══

    public function test_rota_sem_nome_com_nome_gerado_pelo_cache_fica_fora_das_telas_e_acoes(): void
    {
        // Em produção (`route:cache`) rota sem nome vira `generated::<aleatório>`
        // — foi assim que /chamados/{chamado} apareceu no listar_telas.
        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('/mcp-teste-legado/{chamado}', fn () => redirect('/tickets'))->name('generated::TesteLegado01');
            Route::post('/mcp-teste-legado', fn () => back())->name('generated::TesteLegado02');
        });
        Route::getRoutes()->refreshNameLookups();

        $this->assertNotContains('generated::TesteLegado01', app(CatalogoDeTelas::class)->todas()->pluck('tela'));
        $this->assertNotContains('generated::TesteLegado02', app(CatalogoDeAcoes::class)->todas()->pluck('acao'));
        foreach (app(CatalogoDeTelas::class)->todas() as $tela) {
            $this->assertStringStartsNotWith('generated::', $tela['tela']);
        }
    }
}
