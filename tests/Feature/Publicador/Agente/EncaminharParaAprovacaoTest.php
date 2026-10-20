<?php

namespace Tests\Feature\Publicador\Agente;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Models\PubAgenteExecucao;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoOferta;
use App\Models\PubRascunho;
use App\Services\Publicador\Agente\EntradaDoPreparo;
use App\Services\Publicador\Agente\PreparadorDaAprovacao;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AG-03 do Agente ECF Publicador (20/10/2026): o produto preparado entra na fila de Aprovação que já existe.
 * Critério de fechamento do plano (D1): produto completo chega à Aprovação com fotos e motivo rastreáveis, sem
 * nenhum POST de publicação; repetir mantém os mesmos IDs; a fila da loja encontra a oferta; nada é aprovado sozinho.
 */
class EncaminharParaAprovacaoTest extends TestCase
{
    use CenarioDoAgente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarAgente();
    }

    /** Pede, roda a cadeia de texto e passa o encaminhador — o caminho do save até a fila. */
    private function prepararEEncaminhar(): array
    {
        $this->ligar();
        $pub = $this->noPublicador();
        $r = app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
        $antesDoTexto = app(PreparadorDaAprovacao::class)->encaminhar($r['execucao']);
        $this->rodar(GerarPreparoIaJob::class);
        $resumo = app(PreparadorDaAprovacao::class)->encaminharProntas();

        return [$pub, $r['execucao']->fresh(), $antesDoTexto, $resumo];
    }

    public function test_d1_produto_completo_chega_a_fila_da_loja_aguardando_a_pessoa(): void
    {
        [$pub, $execucao, $antesDoTexto, $resumo] = $this->prepararEEncaminhar();

        $this->assertSame('aguardando_texto', $antesDoTexto, 'encaminhou com a IA ainda escrevendo');
        $this->assertSame(1, $resumo['encaminhada']);

        $oferta = PubAprovacaoOferta::firstOrFail();
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $oferta->andamento);
        $this->assertSame(PubAprovacaoOferta::ORIGEM_AUTO, $oferta->origem);
        $this->assertSame($pub->id, $oferta->produto_id);
        $this->assertSame('gold_special', $oferta->alvo->listing_type_id);
        $this->assertNotNull($oferta->entrou_na_fila_em);
        $this->assertNull($oferta->aprovado_por);
        $this->assertNull($oferta->aprovado_em);
        $this->assertSame(1, $oferta->versao);

        // A fila da loja encontra (as âncoras são as do resolvedor da própria fila).
        $this->assertSame([$oferta->id], app(AndamentoDaOfertaService::class)->filaDaConta($this->empresa->id, null)->pluck('id')->all());

        // A foto original virou revisão PENDENTE no conjunto geral, como principal.
        $conjunto = PubAprovacaoConjunto::firstOrFail();
        $this->assertSame(ChaveCanonica::hash(PubAprovacaoConjunto::GERAL), $conjunto->grupo_hash);
        $imagem = PubAprovacaoImagem::firstOrFail();
        $this->assertSame(PubAprovacaoImagem::PENDENTE, $imagem->estado);
        $this->assertSame(PubAprovacaoImagem::TIPO_ORIGINAL, $imagem->tipo);
        $this->assertSame(0, $imagem->posicao);
        $this->assertSame($imagem->imagem_id, $conjunto->principal_imagem_id);

        // A execução terminou na revisão e o histórico da oferta registra quem moveu: o sistema.
        $this->assertSame(PubAgenteExecucao::AGUARDANDO_APROVACAO, $execucao->estado);
        $this->assertSame('encaminhada', $execucao->etapa);
        $this->assertNull($execucao->chave_ativa);
        $evento = DB::table('pub_aprovacao_eventos')->where('oferta_id', $oferta->id)->latest('id')->first();
        $this->assertNull($evento->autor_id);
        $this->assertSame(1, $this->booleano($evento->ator_sistema));

        // Nenhum POST ao ML (o `CenarioDoPreparo` barra requisição solta e responde 200 vazio ao resto).
        $this->assertSame(0, collect(\Illuminate\Support\Facades\Http::recorded())->filter(fn ($p) => $p[0]->method() === 'POST' && str_contains($p[0]->url(), 'mercadolibre'))->count());
    }

    public function test_repetir_mantem_os_mesmos_ids_sem_evento_nem_versao_a_mais(): void
    {
        [$pub] = $this->prepararEEncaminhar();
        $oferta = PubAprovacaoOferta::firstOrFail();
        $eventos = DB::table('pub_aprovacao_eventos')->count();

        // Pedir de novo com os mesmos fatos devolve a execução que já terminou na revisão.
        $outra = app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
        $this->assertFalse($outra['nova']);
        app(PreparadorDaAprovacao::class)->encaminharProntas();

        $this->assertSame(1, PubAprovacaoOferta::count());
        $this->assertSame(1, PubAprovacaoConjunto::count());
        $this->assertSame(1, PubAprovacaoImagem::count());
        $this->assertSame($eventos, DB::table('pub_aprovacao_eventos')->count());
        $this->assertSame(1, $oferta->fresh()->versao);
    }

    public function test_parecer_humano_sobre_foto_nao_e_apagado(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        $r = PubRascunho::where('produto_id', $pub->id)->first();
        $alvo = $r->alvos()->where('listing_type_id', 'gold_special')->first();
        // Uma oferta da equipe ainda preparando, com a foto já REPROVADA por alguém.
        PubAprovacaoOferta::create(['alvo_id' => $alvo->id, 'rascunho_id' => $r->id, 'produto_id' => $pub->id,
            'company_id' => $this->empresa->id, 'origem' => PubAprovacaoOferta::ORIGEM_MANUAL]);
        $conjunto = PubAprovacaoConjunto::create(['rascunho_id' => $r->id, 'grupo_chave' => PubAprovacaoConjunto::GERAL,
            'grupo_hash' => ChaveCanonica::hash(PubAprovacaoConjunto::GERAL)]);
        $foto = $r->imagens()->first();
        PubAprovacaoImagem::create(['conjunto_id' => $conjunto->id, 'imagem_id' => $foto->id, 'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
            'estado' => PubAprovacaoImagem::REPROVADA, 'posicao' => 0, 'quem_id' => $this->admin->id, 'motivo' => 'foto escura']);

        $e = app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin)['execucao'];
        $this->rodar(GerarPreparoIaJob::class);
        $this->assertSame('encaminhada', app(PreparadorDaAprovacao::class)->encaminhar($e));

        $linha = PubAprovacaoImagem::firstOrFail();
        $this->assertSame(PubAprovacaoImagem::REPROVADA, $linha->estado);
        $this->assertSame('foto escura', $linha->motivo);
        $this->assertSame(1, PubAprovacaoOferta::count());
        $this->assertSame(PubAprovacaoOferta::ORIGEM_MANUAL, PubAprovacaoOferta::first()->origem);
    }

    public function test_oferta_que_ja_saiu_de_preparando_nao_e_tocada(): void
    {
        [$pub] = $this->prepararEEncaminhar();
        $oferta = PubAprovacaoOferta::firstOrFail();
        app(AndamentoDaOfertaService::class)->mover($oferta, PubAprovacaoOferta::EM_REVISAO_ECF, $this->admin);

        // Fatos novos (a descrição do cliente mudou) abrem outra execução — que encontra a oferta já em revisão.
        $this->produtoDoPortalAtualizado($pub);
        $e = app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
        $this->assertTrue($e['nova']);
        $this->rodar(GerarPreparoIaJob::class);
        app(PreparadorDaAprovacao::class)->encaminharProntas();

        $this->assertSame(PubAprovacaoOferta::EM_REVISAO_ECF, $oferta->fresh()->andamento);
        $this->assertSame(1, PubAprovacaoOferta::count());
    }

    public function test_modalidade_desativada_durante_o_preparo_bloqueia_em_vez_de_encaminhar(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        $e = app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin)['execucao'];
        $this->rodar(GerarPreparoIaJob::class);

        $r = PubRascunho::where('produto_id', $pub->id)->first();
        $repo = app(RascunhoRepository::class);
        $repo->gravarAlvos($r, array_map(fn (Alvo $a) => new Alvo($a->listingTypeId, $a->titulo, true), $repo->snapshot($r)->alvos));

        $this->assertSame('bloqueada', app(PreparadorDaAprovacao::class)->encaminhar($e));
        $this->assertSame(0, PubAprovacaoOferta::count());
        $this->assertContains('alvos_fora_do_piloto', array_column($e->fresh()->bloqueios, 'codigo'));
    }

    public function test_o_comando_agendado_encaminha(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
        $this->rodar(GerarPreparoIaJob::class);

        $this->artisan('publicador:agente-encaminhar')->assertSuccessful();

        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, PubAprovacaoOferta::firstOrFail()->andamento);
    }

    /** Muda um FATO do produto (a descrição do cliente entra no hash): a próxima avaliação tem outra versão. */
    private function produtoDoPortalAtualizado($pub): void
    {
        \App\Models\EstruturaProduto::whereKey($pub->estrutura_produto_id)->update(['descricao' => 'Agora com apoio de braço regulável.']);
    }

    private function booleano(mixed $v): int
    {
        return (int) (bool) $v;
    }
}
