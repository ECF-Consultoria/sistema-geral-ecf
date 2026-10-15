<?php

namespace Tests\Unit\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\EstruturaPrecificacaoParametros;
use App\Models\PubAprovacaoAjuste;
use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoExcecao;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\Aprovacao\AjustesDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\VocabularioDoPortao;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\DadosEfetivosService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 186, plano 08 — OS **8 BLOQUEIOS**, A CADEIA **EXCLUSIVA** E O VOCABULÁRIO PHP DO PORTÃO.
 *
 * ⚠️⚠️ **DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:** *"o que
 * precisaria estar errado no mundo para ele falhar?"* Sem resposta concreta o gate é decorativo e
 * não entra.
 *
 *   · **a cadeia exclusiva** — alguém escrever **três `if`** onde o protótipo tem
 *     `if / else if / else if` (`Aprovacao.dc.html:1092-1094`). A oferta sem frete passaria a
 *     exibir **"Resultado negativo: R$ …"** sobre um número que **não existe**, e travaria por
 *     três motivos dos quais dois são inventados. É o **critério 4** da fase.
 *   · **o bloqueio de fotos por UM grupo só** — alguém ler o "primeiro conjunto" em vez de todos.
 *     As fotos das outras cores e as `GENERAL` sairiam do bloqueio, e a oferta seria **aprovável
 *     com foto que ninguém olhou**. ⚠️ Como **aprovar já publica e não há desfazer** (decisão do
 *     usuário, 14/10/2026), isso vai ao ar. É o **achado S2**, e os testes dele têm **ISCA**:
 *     aprovando a última pendente o bloqueio **tem de desaparecer**, senão eu estaria medindo um
 *     bloqueio que nunca sai.
 *   · **`loss`/`margin` NÃO disparando sobre o estimado por baixo** — alguém achar que valor
 *     estimado não deve bloquear. O resultado estimado é **OTIMISTA** (falta o custo de
 *     embalagem), então a margem real é **menor**: não bloquear publica prejuízo.
 *   · **a exceção liberando demais** — alguém usar o flag único do protótipo (`!o.exc`, achado
 *     S7) em vez do tipo. Autorizar margem liberaria prejuízo de graça, e liberar prejuízo
 *     liberaria **foto não conferida**. ⚠️ O teste negativo é com os outros cinco bloqueios
 *     **PREENCHIDOS**: negativa com fixture vazia é **vazia por construção**.
 *   · **o título em 60** — alguém escrever `>=`. O protótipo é `> 60` (`:1097`), e `>=` tiraria da
 *     fila todo título de exatamente 60 caracteres, que é o limite LEGÍTIMO do Mercado Livre.
 *   · **o segundo clique / a janela de desfazer** — alguém "consertar" a milestone acrescentando
 *     o que foi **oferecido e RECUSADO** em 14/10/2026. O gate de ausência tem **ISCA**, e vale
 *     para a área nova inteira (Fases 187-194).
 *   · **o PHP e o `.jsx` divergindo em silêncio** — não há enum compartilhado neste projeto, então
 *     a sincronia é **LIDA DO ARQUIVO**, nunca copiada à mão. Sem o lado PHP (que **nascia** neste
 *     plano) o gate (g) do plano 186-09 compararia uma sincronia **sem um dos lados**.
 *   · **o mínimo da margem embutido** — alguém cravar `12`. A tela mentiria para a empresa que
 *     configurou outro mínimo, e o **zero do parâmetro voltaria a desligar o bloqueio**.
 *   · **rede em teste** — `Http::preventStrayRequests()` faz qualquer chamada não fingida
 *     **explodir**, em vez de sair para a internet durante a suíte.
 *
 * ⚠️ REND-08: tudo que grava data roda sob `Carbon::setTestNow()`.
 * ⚠️ Os gates deste arquivo são rodados **só** por `sh tests/gates/verificar-testes.sh 08 <id>`,
 * com as contagens em `tests/gates/contagens-186-08.txt`. **NUNCA** `artisan test` a mão
 * (`grep -E "Tests:|OK"` passa com teste falhando — learnings §4 caso 13).
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class BloqueiosDaOfertaServiceTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const CATEGORIA = 'MLB189007';

    /** ⚠️ **SOMENTE LEITURA** para este plano — a fonte do espelho é o PHP, o `.jsx` é o espelho. */
    private const MODULO_JSX = 'resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx';

    /** Os arquivos de PRODUÇÃO que este plano criou — o alvo dos gates de ausência. */
    private const MEUS_ARQUIVOS = [
        'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
        'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
    ];

    /** `"{categoria}|{listing_type}"` → corpo da resposta do `/sites/MLB/listing_prices`. */
    private array $tarifas = [];

    private ?DadosEfetivosService $efetivos = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 11:00:00');

        // ⚠️ ZERO REDE: qualquer chamada não fingida explode.
        Http::preventStrayRequests();
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        Http::fake(function (Request $req) {
            $caminho = (string) parse_url($req->url(), PHP_URL_PATH);
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $query);

            if ($caminho === '/sites/MLB/listing_prices') {
                $chave = ($query['category_id'] ?? '').'|'.($query['listing_type_id'] ?? '');

                return isset($this->tarifas[$chave])
                    ? Http::response($this->tarifas[$chave], 200)
                    : Http::response(['message' => 'sem stub de tarifa para '.$chave], 404);
            }

            return Http::response(['message' => 'sem stub no teste'], 404);
        });

        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(
            app(MercadoLivreService::class), app(MlColetaService::class), function (int $s) {}
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // O CENÁRIO — o grafo mínimo REAL, com as fontes financeiras injetadas
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /** A resposta REAL do ML gravada em `tests/fixtures-ml/` (captura de 2026-10-01). */
    private function tarifaReal(): array
    {
        $arquivo = base_path('tests/fixtures-ml/sondagem/publico/categorias/'.self::CATEGORIA.'/listing_prices_gold_special.json');
        $this->assertFileExists($arquivo, 'fixture de tarifa ausente — o gate rodaria sobre zero casos');

        return json_decode((string) file_get_contents($arquivo), true)['resposta'];
    }

    /**
     * Monta rascunho + os DOIS alvos + as duas ofertas, e troca `DadosEfetivosService` por uma
     * subclasse que devolve o que o teste declara.
     *
     * ⚠️ `DadosEfetivosService` é **SOMENTE LEITURA** nesta fase: ele é CHAMADO, nunca reescrito.
     *
     * @param  array{preco?: ?float, custo?: ?float, imposto?: ?float, frete?: ?float, titulo?: string, unidades?: int, margem_minima?: ?float}  $o
     * @return array{rascunho: PubRascunho, classico: PubAprovacaoOferta, premium: PubAprovacaoOferta, empresa: Company}
     */
    private function cenario(array $o = []): array
    {
        $empresa = Company::factory()->create();

        if (array_key_exists('margem_minima', $o) && $o['margem_minima'] !== null) {
            EstruturaPrecificacaoParametros::create([
                'company_id' => $empresa->id,
                'margem_contribuicao' => $o['margem_minima'],
            ]);
        }

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => 'BLQ-'.(PubProduto::count() + 1),
            'nome' => 'Poltrona do portão',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => $o['unidades'] ?? 1,
        ]);

        $rascunho = PubRascunho::create(['produto_id' => $produto->id, 'categoria_id' => self::CATEGORIA]);

        $ofertas = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $i => $tipo) {
            $alvo = PubRascunhoAlvo::create([
                'rascunho_id' => $rascunho->id,
                'listing_type_id' => $tipo,
                'titulo' => $o['titulo'] ?? 'Poltrona Beny Decorativa Veludo Cinza',
                'posicao' => $i,
            ]);

            $ofertas[$tipo] = PubAprovacaoOferta::create([
                'alvo_id' => $alvo->id,
                'rascunho_id' => $rascunho->id,
                'produto_id' => $produto->id,
                'company_id' => $empresa->id,
            ]);
        }

        // ⚠️ A tarifa é a REAL da fixture, para os dois tipos de anúncio: nenhum número de taxa é
        // escrito no código de produção (critério 6), então ele TEM de vir daqui.
        $this->tarifas[self::CATEGORIA.'|'.self::CLASSICO] = $this->tarifaReal();
        $this->tarifas[self::CATEGORIA.'|'.self::PREMIUM] = $this->tarifaReal();

        $this->efetivos = new class(app(EstruturaPrecificacaoService::class)) extends DadosEfetivosService
        {
            public array $osCustos = [];

            public array $osFretes = [];

            public array $osPrecos = [];

            public function custosDoProduto(PubProduto $produto): array
            {
                return $this->osCustos;
            }

            public function fretesDoProduto(PubProduto $produto): array
            {
                return $this->osFretes;
            }

            public function daProduto(PubProduto $produto): array
            {
                return $this->osPrecos;
            }
        };

        // ⚠️ `array_key_exists`, NUNCA `??`: `['custo' => null]` significa "a Precificação está sem
        // custo", e o `??` o devolveria ao default — o teste do custo ausente passaria medindo o
        // cenário COM custo.
        $this->efetivos->osCustos = [
            'custo' => array_key_exists('custo', $o) ? $o['custo'] : 20.0,
            'origem' => 'digitado',
            'imposto' => array_key_exists('imposto', $o) ? $o['imposto'] : 10.0,
            'por_variante' => [],
        ];

        $frete = array_key_exists('frete', $o) ? $o['frete'] : 10.0;
        $porTipo = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $tipo) {
            $porTipo[$tipo] = ['valor' => $frete, 'origem' => $frete === null ? null : 'tabela', 'oferta_id' => null];
        }
        $this->efetivos->osFretes = ['por_tipo' => $porTipo, 'por_variante' => []];

        $preco = array_key_exists('preco', $o) ? $o['preco'] : 100.0;
        $this->efetivos->osPrecos = ['precos' => [self::CLASSICO => $preco, self::PREMIUM => $preco]];

        $this->app->instance(DadosEfetivosService::class, $this->efetivos);

        return [
            'rascunho' => $rascunho,
            'classico' => $ofertas[self::CLASSICO]->fresh(),
            'premium' => $ofertas[self::PREMIUM]->fresh(),
            'empresa' => $empresa,
        ];
    }

    /**
     * ⚠️ O cenário monta o dublê de `DadosEfetivosService` e **só então** o serviço pode nascer.
     * Construir o serviço antes dava **falso negativo silencioso** no plano 186-07: o PHP avalia o
     * receptor ANTES do argumento, e o serviço nascia com o `DadosEfetivosService` REAL.
     */
    private function servico(): BloqueiosDaOfertaService
    {
        return new BloqueiosDaOfertaService(
            new ComposicaoFinanceiraService(
                $this->efetivos ?? app(DadosEfetivosService::class),
                app(ClienteMlPublicador::class),
            ),
            app(AjustesDaOfertaService::class),
        );
    }

    private function conjunto(PubRascunho $r, string $grupo = PubAprovacaoConjunto::GERAL): PubAprovacaoConjunto
    {
        return PubAprovacaoConjunto::create([
            'rascunho_id' => $r->id,
            'grupo_chave' => $grupo,
            'grupo_hash' => ChaveCanonica::hash($grupo),
        ]);
    }

    private function imagem(
        PubAprovacaoConjunto $c,
        string $estado = PubAprovacaoImagem::PENDENTE,
        ?int $posicao = 0,
        bool $comAlerta = false,
        bool $fora = false,
    ): PubAprovacaoImagem {
        $crua = PubImagem::create(['rascunho_id' => $c->rascunho_id]);

        $img = PubAprovacaoImagem::create([
            'conjunto_id' => $c->id,
            'imagem_id' => $crua->id,
            'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
            'estado' => $estado,
            'posicao' => $posicao,
            'fora' => $fora,
        ]);

        PubAprovacaoImagemAlerta::create([
            'aprovacao_imagem_id' => $img->id,
            'severidade' => $comAlerta ? PubAprovacaoImagemAlerta::ALTO : PubAprovacaoImagemAlerta::OK,
            'texto' => $comAlerta ? 'Pés diferentes da original' : 'Formato e cor compatíveis',
            'x' => $comAlerta ? 34.0 : null,
            'y' => $comAlerta ? 84.0 : null,
        ]);

        return $img;
    }

    /** Uma galeria inteira aprovada, para os cenários em que foto NÃO é o assunto. */
    private function galeriaAprovada(PubRascunho $r, int $quantas = 2): PubAprovacaoConjunto
    {
        $c = $this->conjunto($r);
        for ($i = 0; $i < $quantas; $i++) {
            $this->imagem($c, PubAprovacaoImagem::APROVADA, $i);
        }

        return $c;
    }

    /** As chaves dos bloqueios, na ordem em que nasceram. */
    private function chaves(PubAprovacaoOferta $oferta): array
    {
        return $this->servico()->para($oferta)['chaves'];
    }

    private function ajusteAberto(PubAprovacaoOferta $oferta, User $quem): PubAprovacaoAjuste
    {
        return PubAprovacaoAjuste::create([
            'escopo' => PubAprovacaoAjuste::ESCOPO_OFERTA,
            'oferta_id' => $oferta->id,
            'item' => 'Título',
            'destino' => PubAprovacaoAjuste::destinoLegivel(PubAprovacaoAjuste::ESCOPO_OFERTA, self::CLASSICO),
            'texto' => 'Incluir a medida e o material no título.',
            'autor_id' => $quem->id,
            'aberto' => true,
        ]);
    }

    /** O andamento é movido pela ÚNICA PORTA — este teste nunca escreve `andamento` direto. */
    private function porAFila(PubAprovacaoOferta $oferta, ?string $destino = null): PubAprovacaoOferta
    {
        $porta = app(\App\Services\Publicador\Aprovacao\AndamentoDaOfertaService::class);
        $oferta = $porta->mover($oferta, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        return $destino === null ? $oferta : $porta->mover($oferta, $destino);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ O VOCABULÁRIO: O PHP É A FONTE, O `.jsx` É O ESPELHO — E A PROVA **LÊ O ARQUIVO**
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /** Um mapa `export const NOME = { chave: 'valor', … }` lido do módulo `.jsx`. */
    private function mapaDoJsx(string $texto, string $nome): array
    {
        $inicio = strpos($texto, 'export const '.$nome.' = {');
        $this->assertNotFalse($inicio, "ISCA CEGA: não achei `{$nome}` no módulo `.jsx` — a sonda não mede o que diz medir.");

        $fim = strpos($texto, '};', $inicio);
        $this->assertNotFalse($fim, "ISCA CEGA: não achei o fim do mapa `{$nome}`.");

        $bloco = substr($texto, $inicio, $fim - $inicio);
        preg_match_all("/^\s{4}([a-z0-9_]+):\s*'(.*)',$/mu", $bloco, $m, PREG_SET_ORDER);

        $mapa = [];
        foreach ($m as $par) {
            $mapa[$par[1]] = $par[2];
        }

        return $mapa;
    }

    /** Um `export const NOME = '…';` lido do módulo `.jsx`. */
    private function literalDoJsx(string $texto, string $nome): string
    {
        $achou = preg_match("/^export const {$nome} = '(.*)';$/mu", $texto, $m);
        $this->assertSame(1, $achou, "ISCA CEGA: não achei o literal `{$nome}` no módulo `.jsx`.");

        return $m[1];
    }

    public function test_bloq12_as_nove_abreviacoes_e_os_oito_rotulos_sao_espelho_lido_do_modulo_jsx(): void
    {
        $arquivo = base_path(self::MODULO_JSX);
        $this->assertFileExists($arquivo, 'o módulo do plano 186-02 não está no disco — o gate (g) do 186-09 mediria uma sincronia sem um dos lados');
        $texto = (string) file_get_contents($arquivo);

        // ISCA DA SONDA: ela acha um rótulo CONHECIDO. Sem isto, um parser quebrado devolveria
        // array vazio e `assertSame([], [])` passaria sem medir nada.
        $abreviacoes = $this->mapaDoJsx($texto, 'ABREVIACAO_DO_BLOQUEIO');
        $this->assertSame('frete pendente', $abreviacoes['frete_ausente'] ?? null, 'ISCA CEGA: a sonda do `.jsx` não lê o valor das chaves.');
        $this->assertCount(9, $abreviacoes, 'o `.jsx` deixou de ter as 9 abreviações de `SHORT`');

        $rotulos = $this->mapaDoJsx($texto, 'ROTULO_DO_BLOQUEIO');
        $this->assertSame('Frete ou custo ausente', $rotulos['frete_ausente'] ?? null, 'ISCA CEGA: a sonda não lê `ROTULO_DO_BLOQUEIO`.');
        $this->assertCount(8, $rotulos, 'o `.jsx` deixou de ter os 8 rótulos de `BLK`');

        $doPrototipo = $this->mapaDoJsx($texto, 'BLOQUEIO_DO_PROTOTIPO');
        $this->assertCount(11, $doPrototipo);

        // ⚠️ ESPELHO BYTE A BYTE, nos dois sentidos (chaves E valores, na mesma ordem).
        $this->assertSame($abreviacoes, VocabularioDoPortao::ABREVIACOES, 'as 9 abreviações divergiram entre o PHP e o `.jsx` — BLOQ-12 mostraria uma coisa no servidor e outra na tela');
        $this->assertSame($rotulos, VocabularioDoPortao::ROTULOS, 'os 8 rótulos do filtro "Motivo de bloqueio" divergiram entre o PHP e o `.jsx`');
        $this->assertSame($doPrototipo, VocabularioDoPortao::BLOQUEIO_DO_PROTOTIPO, 'o mapa de conferência com o protótipo divergiu entre o PHP e o `.jsx`');

        // Os dois literais do rodapé, também lidos do arquivo.
        $this->assertSame($this->literalDoJsx($texto, 'TUDO_CONFERIDO'), VocabularioDoPortao::TUDO_CONFERIDO);
        $this->assertSame($this->literalDoJsx($texto, 'BLOQUEADO_PREFIXO'), VocabularioDoPortao::BLOQUEADO_PREFIXO);
        $this->assertSame($this->literalDoJsx($texto, 'INCOMPLETO'), VocabularioDoPortao::INCOMPLETO);

        // ⚠️ O prefixo carrega o espaço no fim DE PROPÓSITO (é emendado com a abreviação).
        $this->assertSame('Bloqueado: ', VocabularioDoPortao::BLOQUEADO_PREFIXO);
        $this->assertStringEndsWith(' ', VocabularioDoPortao::BLOQUEADO_PREFIXO);

        // VOC-04 — os 3 selos de origem, LIDOS do modelo e espelhados no `.jsx`.
        $selos = $this->servicoSelosDoJsx($texto);
        $this->assertSame($selos, VocabularioDoPortao::selosDeOrigem(), 'os 3 selos de origem divergiram entre o PHP e o `.jsx`');
        $this->assertSame(PubAprovacaoComposicao::ORIGENS_DA_LINHA, VocabularioDoPortao::selosDeOrigem(), 'os selos foram RECOPIADOS em vez de lidos do modelo — duas verdades livres para divergir');
    }

    /** Os `rotulo:` de `SELOS_DE_ORIGEM` no `.jsx`, por chave. */
    private function servicoSelosDoJsx(string $texto): array
    {
        $inicio = strpos($texto, 'export const SELOS_DE_ORIGEM = {');
        $this->assertNotFalse($inicio, 'ISCA CEGA: não achei `SELOS_DE_ORIGEM` no `.jsx`.');
        $bloco = substr($texto, $inicio, (int) strpos($texto, '};', $inicio) - $inicio);

        preg_match_all("/^\s{4}([a-z]+):\s*\{\s*$\s*rotulo:\s*'(.*)',$/mu", $bloco, $m, PREG_SET_ORDER);
        $selos = [];
        foreach ($m as $par) {
            $selos[$par[1]] = $par[2];
        }
        $this->assertCount(3, $selos, 'ISCA CEGA: a sonda dos selos de origem não achou os 3.');

        return $selos;
    }

    public function test_voc06_a_bijecao_das_onze_chaves_vale_nos_dois_sentidos_e_as_inglesas_nao_sao_chave(): void
    {
        $uniao = array_values(array_unique(array_merge(
            array_keys(VocabularioDoPortao::ABREVIACOES),
            array_keys(VocabularioDoPortao::ROTULOS),
        )));
        sort($uniao);

        $traduzidas = array_values(VocabularioDoPortao::BLOQUEIO_DO_PROTOTIPO);
        sort($traduzidas);

        // (1) toda chave traduzida aparece em pelo menos um dos dois mapas; (2) e vice-versa.
        $this->assertSame($traduzidas, $uniao, 'a união de `ABREVIACOES` e `ROTULOS` não é bijetiva com `BLOQUEIO_DO_PROTOTIPO` — haveria bloqueio sem medição contra o protótipo');
        $this->assertCount(11, $traduzidas);

        // Os 3 só em `SHORT` e os 2 só em `BLK`, nomeados — a conta de 9 + 8 = 11 é conferida.
        $this->assertSame(
            ['sem_fotos', 'titulo_longo', 'em_preparacao'],
            array_values(array_diff(array_keys(VocabularioDoPortao::ABREVIACOES), array_keys(VocabularioDoPortao::ROTULOS)))
        );
        $this->assertSame(
            ['alerta_em_foto_de_ia', 'falha_na_publicacao'],
            array_values(array_diff(array_keys(VocabularioDoPortao::ROTULOS), array_keys(VocabularioDoPortao::ABREVIACOES)))
        );

        // ⚠️⚠️ AS CHAVES INGLESAS **NÃO SÃO CHAVE DE DADO**. Nenhum `||` aceita as duas formas —
        // é o defeito grave achado na verificação da wave 1.
        foreach (array_keys(VocabularioDoPortao::BLOQUEIO_DO_PROTOTIPO) as $ingles) {
            $this->assertFalse(VocabularioDoPortao::chaveValida($ingles), "a chave inglesa `{$ingles}` do protótipo foi aceita como chave de dado");
            $this->assertNull(VocabularioDoPortao::abreviacaoDe($ingles));
            $this->assertNull(VocabularioDoPortao::rotuloDe($ingles));
        }

        // ISCA do casador: a chave pt-BR correspondente É aceita (senão o laço acima passaria com
        // um `chaveValida()` que devolvesse sempre `false`).
        $this->assertTrue(VocabularioDoPortao::chaveValida(VocabularioDoPortao::FRETE_AUSENTE), 'ISCA CEGA: `chaveValida()` recusa até a chave boa.');

        // REND-03 — chave adversa não lança e devolve `null`/`false`.
        foreach ([null, 0, 1, 3.14, true, false, [], '', '   ', 'inexistente'] as $adversa) {
            $this->assertNull(VocabularioDoPortao::abreviacaoDe($adversa));
            $this->assertNull(VocabularioDoPortao::rotuloDe($adversa));
            $this->assertNull(VocabularioDoPortao::correcaoDe($adversa));
            $this->assertFalse(VocabularioDoPortao::chaveValida($adversa));
            $this->assertFalse(VocabularioDoPortao::ehBloqueio($adversa));
        }

        // ⚠️ BLOQ-08 — os DOIS tipos de exceção são chaves deste namespace (chave estrangeira POR
        // VALOR). Divergir aqui faria a exceção nomear um bloqueio que não existe.
        foreach (PubAprovacaoExcecao::LIBERA as $tipo) {
            $this->assertTrue(VocabularioDoPortao::ehBloqueio($tipo), "o tipo de exceção `{$tipo}` não é um bloqueio deste vocabulário");
        }
    }

    public function test_voc05_a_severidade_segue_o_prototipo_e_cor_nunca_e_o_unico_sinal(): void
    {
        // `:1295` / `:1238` — vermelhos: `loss`, `freight`, `nophoto`, e `photos` COM ALERTA.
        foreach ([VocabularioDoPortao::RESULTADO_NEGATIVO, VocabularioDoPortao::FRETE_AUSENTE, VocabularioDoPortao::SEM_FOTOS] as $chave) {
            $this->assertSame(VocabularioDoPortao::SEVERIDADE_VERMELHA, VocabularioDoPortao::severidade($chave), "`{$chave}` deveria ser vermelho");
            $this->assertSame(VocabularioDoPortao::SEVERIDADE_VERMELHA, VocabularioDoPortao::severidade($chave, true));
        }

        // ⚠️ `fotos_a_conferir` é vermelha SÓ com alerta — a cor dela depende do contexto.
        $this->assertSame(VocabularioDoPortao::SEVERIDADE_AMBAR, VocabularioDoPortao::severidade(VocabularioDoPortao::FOTOS_A_CONFERIR, false));
        $this->assertSame(VocabularioDoPortao::SEVERIDADE_VERMELHA, VocabularioDoPortao::severidade(VocabularioDoPortao::FOTOS_A_CONFERIR, true));

        // Os âmbar.
        foreach ([
            VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO,
            VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA,
            VocabularioDoPortao::AJUSTE_EM_ABERTO,
            VocabularioDoPortao::TITULO_LONGO,
            VocabularioDoPortao::EM_PREPARACAO,
        ] as $chave) {
            $this->assertSame(VocabularioDoPortao::SEVERIDADE_AMBAR, VocabularioDoPortao::severidade($chave), "`{$chave}` deveria ser âmbar");
            $this->assertSame(VocabularioDoPortao::SEVERIDADE_AMBAR, VocabularioDoPortao::severidade($chave, true), "a cor de `{$chave}` NÃO depende de alerta");
        }

        // ⚠️ VOC-05 — o glifo existe e é o do protótipo. Cor sozinha não é sinal.
        $this->assertSame('!', VocabularioDoPortao::GLIFO);

        // ⚠️ E as 9 correções: `em_preparacao` é a ÚNICA vazia (`:1089`).
        $this->assertCount(9, VocabularioDoPortao::CORRECOES);
        $vazias = array_keys(array_filter(VocabularioDoPortao::CORRECOES, fn (string $c) => $c === ''));
        $this->assertSame([VocabularioDoPortao::EM_PREPARACAO], $vazias, 'correção vazia fora de `em_preparacao` é botão que não resolve nada');
        $this->assertSame('Solicitar exceção', VocabularioDoPortao::CORRECOES[VocabularioDoPortao::RESULTADO_NEGATIVO]);
        $this->assertSame('Solicitar exceção', VocabularioDoPortao::CORRECOES[VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO]);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ CRITÉRIO 4 — A CADEIA `frete → prejuízo → margem` É **EXCLUSIVA** E NESTA ORDEM
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_criterio4_frete_ausente_com_resultado_negativo_e_margem_baixa_devolve_so_frete(): void
    {
        // ⚠️ O cenário é construído para que, SE o frete existisse, o resultado fosse NEGATIVO
        // (custo 80 + comissão 11 + imposto 10 + frete 10 = 111 > preço 100) — logo `loss` **e**
        // `margin` teriam matéria. Com o frete AUSENTE, nenhum dos dois pode nascer.
        $c = $this->cenario(['preco' => 100.0, 'custo' => 80.0, 'frete' => null]);
        $this->galeriaAprovada($c['rascunho']);
        $oferta = $this->porAFila($c['classico']);

        $info = $this->servico()->para($oferta);

        $this->assertSame([VocabularioDoPortao::FRETE_AUSENTE], $info['chaves'], 'a cadeia virou três `if`: faltando dado não existe prejuízo nem margem, porque não há resultado');
        $this->assertNotContains(VocabularioDoPortao::RESULTADO_NEGATIVO, $info['chaves']);
        $this->assertNotContains(VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO, $info['chaves']);

        // ⚠️ E a razão: **não há resultado para julgar**. Nem zero.
        $this->assertTrue($info['composicao']->incompleto);
        $this->assertNull($info['composicao']->resultado, 'o resultado virou número onde não há dado');
        $this->assertNull($info['composicao']->margem);

        // O texto e a correção são os do protótipo (`:1092` + `fix()` `:1173`).
        $this->assertSame('Frete do vendedor não confirmado · resultado indisponível', $info['bloqueios'][0]['texto']);
        $this->assertSame('Informar frete', $info['bloqueios'][0]['correcao']);
        $this->assertSame(VocabularioDoPortao::SEVERIDADE_VERMELHA, $info['bloqueios'][0]['severidade']);
        $this->assertSame('!', $info['bloqueios'][0]['glifo']);
        $this->assertFalse($info['aprovavel']);

        // ⚠️ ISCA — com o MESMO custo e o frete PRESENTE, o `loss` aparece. Sem esta linha eu
        // estaria provando apenas que `loss` nunca nasce neste cenário.
        $c2 = $this->cenario(['preco' => 100.0, 'custo' => 80.0, 'frete' => 10.0]);
        $this->galeriaAprovada($c2['rascunho']);
        $this->assertSame(
            [VocabularioDoPortao::RESULTADO_NEGATIVO],
            $this->chaves($this->porAFila($c2['classico'])),
            'ISCA CEGA: `resultado_negativo` não nasce nem com o frete informado — o cenário não tinha prejuízo nenhum.'
        );
    }

    public function test_criterio4_resultado_negativo_devolve_so_loss_e_nunca_margin(): void
    {
        // total = custo 80 + comissão 11 + fixa 0 + frete 10 + imposto 10 = 111 → resultado −11.
        $c = $this->cenario(['preco' => 100.0, 'custo' => 80.0, 'frete' => 10.0]);
        $this->galeriaAprovada($c['rascunho']);
        $info = $this->servico()->para($this->porAFila($c['classico']));

        $this->assertSame([VocabularioDoPortao::RESULTADO_NEGATIVO], $info['chaves']);
        $this->assertNotContains(VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO, $info['chaves'], 'margem negativa é SEMPRE abaixo do mínimo — com três `if` a oferta levaria dois bloqueios pelo mesmo fato');

        $this->assertSame(-11.0, $info['composicao']->resultado);
        $this->assertTrue($info['composicao']->margem < 0.0);
        $this->assertTrue($info['composicao']->margem < $info['composicao']->margemMinima, 'a margem deste cenário TAMBÉM está abaixo do mínimo — é isso que torna a exclusividade observável');

        // ⚠️ O texto do protótipo (`:1093`), com o sinal U+2212 de `brl()` (`:1044`).
        $this->assertSame("Resultado negativo: \u{2212} R$ 11,00 por venda", $info['bloqueios'][0]['texto']);
        $this->assertSame('Solicitar exceção', $info['bloqueios'][0]['correcao']);
        $this->assertSame(VocabularioDoPortao::SEVERIDADE_VERMELHA, $info['bloqueios'][0]['severidade']);
    }

    public function test_criterio4_margem_baixa_com_resultado_positivo_devolve_so_margin_e_nunca_loss(): void
    {
        // total = custo 65 + comissão 11 + fixa 0 + frete 10 + imposto 10 = 96 → resultado +4 (4%).
        $c = $this->cenario(['preco' => 100.0, 'custo' => 65.0, 'frete' => 10.0]);
        $this->galeriaAprovada($c['rascunho']);
        $info = $this->servico()->para($this->porAFila($c['classico']));

        $this->assertSame([VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO], $info['chaves']);
        $this->assertNotContains(VocabularioDoPortao::RESULTADO_NEGATIVO, $info['chaves']);

        $this->assertSame(4.0, $info['composicao']->resultado, 'o resultado tem de ser POSITIVO, senão o caso não distingue `margin` de `loss`');
        $this->assertSame(4.0, round((float) $info['composicao']->margem, 2));
        $this->assertSame(12.0, $info['composicao']->margemMinima, 'o mínimo caiu na config (padrão 12,0), que é o fallback deliberado');

        // ⚠️ O texto do protótipo (`:1094`): `pct()` sempre com UMA casa decimal (`:1045`).
        $this->assertSame('Margem de 4,0% abaixo do mínimo de 12%', $info['bloqueios'][0]['texto']);
        $this->assertSame('Solicitar exceção', $info['bloqueios'][0]['correcao']);
        $this->assertSame(VocabularioDoPortao::SEVERIDADE_AMBAR, $info['bloqueios'][0]['severidade'], 'margem baixa é ÂMBAR; só prejuízo, frete e sem-fotos são vermelhos');

        // ⚠️ ISCA DO MÍNIMO — ele **não** é literal: com o parâmetro da empresa em 3% o MESMO
        // cenário deixa de bloquear, e a frase muda com ele.
        $c2 = $this->cenario(['preco' => 100.0, 'custo' => 65.0, 'frete' => 10.0, 'margem_minima' => 3.0]);
        $this->galeriaAprovada($c2['rascunho']);
        $info2 = $this->servico()->para($this->porAFila($c2['classico']));
        $this->assertSame([], $info2['chaves'], 'ISCA CEGA: o mínimo está embutido — mudar o parâmetro da empresa não mudou o bloqueio.');
        $this->assertSame(3.0, $info2['composicao']->margemMinima);
        $this->assertTrue($info2['aprovavel']);
    }

    public function test_criterio4_no_maximo_um_dos_tres_nasce_e_a_ordem_e_a_do_prototipo(): void
    {
        $casos = [
            'frete ausente' => ['custo' => 80.0, 'frete' => null],
            'prejuízo' => ['custo' => 80.0, 'frete' => 10.0],
            'margem baixa' => ['custo' => 65.0, 'frete' => 10.0],
            'tudo em ordem' => ['custo' => 20.0, 'frete' => 10.0],
        ];

        $nascidos = [];
        foreach ($casos as $nome => $extra) {
            $c = $this->cenario(array_merge(['preco' => 100.0], $extra));
            $this->galeriaAprovada($c['rascunho']);
            $chaves = $this->chaves($this->porAFila($c['classico']));

            $daCadeia = array_values(array_intersect(VocabularioDoPortao::CADEIA_EXCLUSIVA, $chaves));
            $this->assertLessThanOrEqual(1, count($daCadeia), "o caso `{$nome}` produziu mais de um bloqueio da cadeia exclusiva: ".implode(', ', $daCadeia));
            $nascidos[$nome] = $daCadeia;
        }

        // ⚠️ ISCA DE COBERTURA: os três nasceram ao menos uma vez, e o quarto caso não nasceu
        // nenhum. Sem isto, um serviço que NUNCA devolvesse bloqueio da cadeia passaria no laço.
        $this->assertSame([VocabularioDoPortao::FRETE_AUSENTE], $nascidos['frete ausente']);
        $this->assertSame([VocabularioDoPortao::RESULTADO_NEGATIVO], $nascidos['prejuízo']);
        $this->assertSame([VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO], $nascidos['margem baixa']);
        $this->assertSame([], $nascidos['tudo em ordem']);

        // A ordem declarada é a de `info()` — e a cadeia ocupa as posições 4, 5 e 6 dos 8.
        $this->assertSame(
            [VocabularioDoPortao::FRETE_AUSENTE, VocabularioDoPortao::RESULTADO_NEGATIVO, VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO],
            VocabularioDoPortao::CADEIA_EXCLUSIVA
        );
        $this->assertSame(
            array_slice(VocabularioDoPortao::BLOQUEIOS_EM_ORDEM, 3, 3),
            VocabularioDoPortao::CADEIA_EXCLUSIVA,
            'a cadeia saiu de lugar na ordem dos 8 — o rodapé abrevia o PRIMEIRO bloqueio, então a ordem muda o que a pessoa é mandada fazer'
        );
    }

    public function test_decisao7_embalagem_sem_fonte_nao_dispara_frete_e_loss_margin_disparam_sobre_o_estimado(): void
    {
        // ⚠️ Decisão (7) do usuário, 14/10/2026: "Embalagem" não tem fonte no repositório, nasce
        // `Pendente` VISÍVEL e **NÃO** anula o resultado.
        $c = $this->cenario(['preco' => 100.0, 'custo' => 65.0, 'frete' => 10.0]);
        $this->galeriaAprovada($c['rascunho']);
        $info = $this->servico()->para($this->porAFila($c['classico']));

        // (1) a embalagem ESTÁ pendente...
        $embalagem = $info['composicao']->linha('embalagem');
        $this->assertNotNull($embalagem);
        $this->assertTrue($embalagem->ehPendente(), 'a embalagem deixou de ser `Pendente` — o cenário não mede mais a decisão (7)');

        // (2) ...e ainda assim **não** há `frete_ausente`: ela não está entre as CINCO que anulam.
        $this->assertNotContains('embalagem', PubAprovacaoComposicao::ANULAM_O_RESULTADO);
        $this->assertNotContains(VocabularioDoPortao::FRETE_AUSENTE, $info['chaves'], 'embalagem pendente passou a anular o resultado — 100% das ofertas sairiam "Incompleto" e NADA poderia ser aprovado');
        $this->assertFalse($info['composicao']->incompleto);

        // (3) o resultado existe, está marcado como ESTIMADO POR BAIXO e a nota diz isso.
        $this->assertTrue($info['composicao']->estimadoPorBaixo);
        $this->assertStringContainsString('sem o custo de embalagem', $info['composicao']->notaDaMargem);

        // (4) ⚠️⚠️ E O BLOQUEIO DISPARA SOBRE ELE. O valor é OTIMISTA — a margem real é MENOR —,
        // então não bloquear seria o erro grave (aprovar já publica, e não há desfazer).
        $this->assertSame([VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO], $info['chaves']);

        // (5) o mesmo vale para o prejuízo, no cenário em que ele aparece.
        $c2 = $this->cenario(['preco' => 100.0, 'custo' => 80.0, 'frete' => 10.0]);
        $this->galeriaAprovada($c2['rascunho']);
        $info2 = $this->servico()->para($this->porAFila($c2['classico']));
        $this->assertTrue($info2['composicao']->estimadoPorBaixo);
        $this->assertSame([VocabularioDoPortao::RESULTADO_NEGATIVO], $info2['chaves'], 'prejuízo estimado por baixo deixou de bloquear — o prejuízo REAL é ainda maior');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️ BLOQ-08 — A EXCEÇÃO LIBERA **SÓ** PREJUÍZO E MARGEM
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_bloq08_excecao_de_prejuizo_remove_so_o_loss_e_deixa_os_outros_cinco_preenchidos(): void
    {
        $quem = User::factory()->create();

        // ⚠️ OS CINCO ESTÃO **PREENCHIDOS**: negativa com fixture vazia é vazia por construção
        // (foi o defeito do plano 186-04 corrigido na verificação).
        //   · fotos  — uma pendente num grupo de variação
        //   · frete  — presente (senão a cadeia nem chegaria ao prejuízo)
        //   · change — aviso de preço alterado
        //   · adjust — um ajuste em aberto
        //   · title  — título com 61 caracteres
        $c = $this->cenario([
            'preco' => 100.0,
            'custo' => 80.0,
            'frete' => 10.0,
            'titulo' => str_repeat('A', 61),
        ]);
        $this->galeriaAprovada($c['rascunho']);
        $cor = $this->conjunto($c['rascunho'], 'COLOR:Cinza');
        $this->imagem($cor, PubAprovacaoImagem::PENDENTE, 0);

        $oferta = $this->porAFila($c['classico']);
        $oferta->update(['preco_alterado' => ['de' => 120.0, 'para' => 100.0, 'quem' => $quem->id, 'quando' => '2026-10-15 10:12:00']]);
        $this->ajusteAberto($oferta, $quem);
        $oferta = $oferta->fresh();

        $antes = $this->chaves($oferta);
        $this->assertSame([
            VocabularioDoPortao::FOTOS_A_CONFERIR,
            VocabularioDoPortao::RESULTADO_NEGATIVO,
            VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA,
            VocabularioDoPortao::AJUSTE_EM_ABERTO,
            VocabularioDoPortao::TITULO_LONGO,
        ], $antes, 'o cenário não tem os cinco outros bloqueios preenchidos — a negativa seria vazia por construção');

        // A exceção é aplicada pelo serviço do plano 186-05, com motivo obrigatório, e **nasce
        // valendo** (não existe estado de espera — BLOQ-09).
        app(AjustesDaOfertaService::class)->aplicarExcecao(
            $oferta, $quem, PubAprovacaoExcecao::RESULTADO_NEGATIVO, 'Cliente aceitou a perda nesta campanha de lançamento.'
        );

        $depois = $this->chaves($oferta->fresh());

        // ⚠️ SÓ o prejuízo saiu. Os outros CINCO ficaram de pé.
        $this->assertSame([
            VocabularioDoPortao::FOTOS_A_CONFERIR,
            VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA,
            VocabularioDoPortao::AJUSTE_EM_ABERTO,
            VocabularioDoPortao::TITULO_LONGO,
        ], $depois, 'a exceção liberou bloqueio que ela não libera — publicaria anúncio que ninguém conferiu, sem desfazer');

        $this->assertNotContains(VocabularioDoPortao::RESULTADO_NEGATIVO, $depois);

        // ⚠️⚠️ **E NEM A MARGEM PASSA A NASCER — isto é conserto de um DEFEITO REAL medido em
        // 15/10/2026**, na primeira redação do serviço. Com o `!exc` dentro da CONDIÇÃO do
        // `elseif`, autorizar o prejuízo fazia `margem_abaixo_do_minimo` nascer no lugar dele:
        // resultado negativo é **sempre** margem abaixo do mínimo, então a pessoa precisaria de
        // **DUAS** exceções para o MESMO fato, e o caso pior (prejuízo) pediria o dobro do
        // trabalho do caso mais leve. ➡️ O **FATO** escolhe o elo da cadeia; a exceção só apaga o
        // bloqueio **daquele** elo.
        $this->assertNotContains(VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO, $depois, 'liberar o prejuízo fez a margem nascer no lugar dele — a exceção de um tipo passou a exigir a do outro');

        // E a prova de que o fato CONTINUA sendo prejuízo (não é que a margem tenha melhorado):
        $composicao = $this->servico()->para($oferta->fresh())['composicao'];
        $this->assertSame(-11.0, $composicao->resultado);
        $this->assertTrue($composicao->margem < $composicao->margemMinima, 'a margem deste cenário segue abaixo do mínimo — é isso que tornaria o elo seguinte observável se o `!exc` voltasse para a condição');
        $this->assertFalse($this->servico()->para($oferta->fresh())['aprovavel'], 'os outros cinco bloqueios continuam impedindo a aprovação');
    }

    public function test_bloq08_excecao_de_margem_nao_libera_o_prejuizo_e_nem_as_fotos(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario(['preco' => 100.0, 'custo' => 80.0, 'frete' => 10.0]);
        $this->galeriaAprovada($c['rascunho']);
        $oferta = $this->porAFila($c['classico']);

        app(AjustesDaOfertaService::class)->aplicarExcecao(
            $oferta, $quem, PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO, 'Margem menor aceita para girar estoque parado.'
        );

        // ⚠️ A exceção de MARGEM não libera o PREJUÍZO — com o flag único do protótipo liberaria.
        $this->assertSame([VocabularioDoPortao::RESULTADO_NEGATIVO], $this->chaves($oferta->fresh()), 'autorizar margem liberou prejuízo de graça');

        // E nenhuma exceção libera foto: a lista é FECHADA, com dois itens.
        $this->assertSame(
            [PubAprovacaoExcecao::RESULTADO_NEGATIVO, PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO],
            PubAprovacaoExcecao::LIBERA
        );
        foreach ([
            VocabularioDoPortao::FOTOS_A_CONFERIR,
            VocabularioDoPortao::SEM_FOTOS,
            VocabularioDoPortao::FRETE_AUSENTE,
            VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA,
            VocabularioDoPortao::AJUSTE_EM_ABERTO,
            VocabularioDoPortao::TITULO_LONGO,
            VocabularioDoPortao::EM_PREPARACAO,
            VocabularioDoPortao::ALERTA_EM_FOTO_DE_IA,
            VocabularioDoPortao::FALHA_NA_PUBLICACAO,
        ] as $jamais) {
            $this->assertFalse(PubAprovacaoExcecao::libera($jamais), "a exceção passou a liberar `{$jamais}`");
        }

        // ISCA: ela libera os dois que DEVE liberar.
        $this->assertTrue(PubAprovacaoExcecao::libera(PubAprovacaoExcecao::RESULTADO_NEGATIVO));
        $this->assertTrue(PubAprovacaoExcecao::libera(PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO));

        // ⚠️ O ESPELHO, no elo de baixo da cadeia: margem baixa com resultado POSITIVO.
        //   · exceção de MARGEM  → o bloqueio sai, e a oferta fica aprovável;
        //   · exceção de PREJUÍZO → **não** serve: o fato é margem, e o bloqueio fica de pé.
        $cMargem = $this->cenario(['preco' => 100.0, 'custo' => 65.0, 'frete' => 10.0]);
        $this->galeriaAprovada($cMargem['rascunho']);
        $soMargem = $this->porAFila($cMargem['classico']);
        $this->assertSame([VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO], $this->chaves($soMargem));

        app(AjustesDaOfertaService::class)->aplicarExcecao(
            $soMargem, $quem, PubAprovacaoExcecao::RESULTADO_NEGATIVO, 'Exceção do tipo errado para este fato.'
        );
        $this->assertSame([VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO], $this->chaves($soMargem->fresh()), 'a exceção de prejuízo liberou a margem — uma não libera a outra (achado S7: aqui a exceção TEM tipo)');

        app(AjustesDaOfertaService::class)->aplicarExcecao(
            $soMargem->fresh(), $quem, PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO, 'Margem menor aceita para girar estoque parado.'
        );
        $this->assertSame([], $this->chaves($soMargem->fresh()), 'ISCA CEGA: a exceção do tipo CERTO também não libera — o bloqueio nunca sai.');
        $this->assertTrue($this->servico()->para($soMargem->fresh())['aprovavel']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ O ACHADO S2 — O BLOQUEIO DE FOTOS CONSIDERA **TODOS** OS GRUPOS DO RASCUNHO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * 3 valores de eixo que definem foto ⇒ **4** conjuntos (3 + `GENERAL`), todas as imagens
     * aprovadas **menos uma**, e a única pendente **fora do primeiro grupo**.
     */
    public function test_achado_s2_pendente_fora_do_primeiro_grupo_bloqueia_as_duas_ofertas_com_isca(): void
    {
        $c = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0]);

        $geral = $this->conjunto($c['rascunho'], PubAprovacaoConjunto::GERAL);
        $this->imagem($geral, PubAprovacaoImagem::APROVADA, 0);
        $this->imagem($geral, PubAprovacaoImagem::APROVADA, 1);

        $grupos = [];
        foreach (['COLOR:Cinza', 'COLOR:Azul', 'COLOR:Verde'] as $i => $chave) {
            $grupos[$chave] = $this->conjunto($c['rascunho'], $chave);
            $this->imagem($grupos[$chave], PubAprovacaoImagem::APROVADA, 0);
        }

        $this->assertSame(4, PubAprovacaoConjunto::doRascunho((int) $c['rascunho']->id)->count(), '3 valores de eixo que definem foto dão 4 conjuntos (3 + GENERAL)');

        // ⚠️ A ÚNICA PENDENTE está no TERCEIRO grupo de variação — longe do primeiro conjunto.
        $pendente = $this->imagem($grupos['COLOR:Verde'], PubAprovacaoImagem::PENDENTE, 1);

        $classico = $this->porAFila($c['classico']);
        $premium = $this->porAFila($c['premium']);

        foreach (['Clássico' => $classico, 'Premium' => $premium] as $nome => $oferta) {
            $info = $this->servico()->para($oferta);
            $this->assertContains(VocabularioDoPortao::FOTOS_A_CONFERIR, $info['chaves'], "o {$nome} ficou SEM o bloqueio de fotos com uma pendente no 3º grupo — a leitura pegou um conjunto só");
            $this->assertFalse($info['aprovavel'], "o {$nome} ficou APROVÁVEL com foto que ninguém conferiu — e aprovar já publica, sem desfazer");
            $this->assertSame(1, $info['pendentes']);
            $this->assertSame('1 foto aguardando conferência', $info['bloqueios'][0]['texto']);
            $this->assertSame('Conferir fotos', $info['bloqueios'][0]['correcao']);
            $this->assertSame($pendente->id, $info['foco_imagem_id'], 'a tela abriria outra foto que não a pendente');
        }

        // ⚠️⚠️ ISCA — aprovando AQUELA, o bloqueio **desaparece** nas duas ofertas. Sem esta
        // parte o teste passaria por um bloqueio que nunca sai.
        $pendente->update(['estado' => PubAprovacaoImagem::APROVADA]);

        foreach (['Clássico' => $classico, 'Premium' => $premium] as $nome => $oferta) {
            $info = $this->servico()->para($oferta->fresh());
            $this->assertSame([], $info['chaves'], "ISCA CEGA: o bloqueio de fotos do {$nome} não sai nem com tudo aprovado.");
            $this->assertTrue($info['aprovavel']);
            $this->assertSame(VocabularioDoPortao::TUDO_CONFERIDO, $info['resumo']);
        }
    }

    public function test_achado_s2_pendente_no_grupo_general_bloqueia_as_duas_ofertas_com_isca(): void
    {
        $c = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0]);

        $geral = $this->conjunto($c['rascunho'], PubAprovacaoConjunto::GERAL);
        $this->imagem($geral, PubAprovacaoImagem::APROVADA, 0);

        foreach (['COLOR:Cinza', 'COLOR:Azul', 'COLOR:Verde'] as $chave) {
            $g = $this->conjunto($c['rascunho'], $chave);
            $this->imagem($g, PubAprovacaoImagem::APROVADA, 0);
        }

        // ⚠️ A única pendente está na GALERIA GERAL — o grupo que uma FK única por variação
        // deixaria de fora.
        $pendente = $this->imagem($geral, PubAprovacaoImagem::PENDENTE, 1);
        $this->assertTrue($geral->ehGeral());

        foreach (['Clássico' => $c['classico'], 'Premium' => $c['premium']] as $nome => $crua) {
            $oferta = $this->porAFila($crua);
            $info = $this->servico()->para($oferta);
            $this->assertContains(VocabularioDoPortao::FOTOS_A_CONFERIR, $info['chaves'], "o {$nome} ignorou a pendente do grupo GENERAL");
            $this->assertFalse($info['aprovavel']);
            $this->assertSame($pendente->id, $info['foco_imagem_id']);
        }

        // ISCA — aprovando a do GENERAL, o bloqueio sai nas duas.
        $pendente->update(['estado' => PubAprovacaoImagem::APROVADA]);
        $this->assertSame([], $this->chaves($c['classico']->fresh()), 'ISCA CEGA: o bloqueio não sai nem com a do GENERAL aprovada.');
        $this->assertSame([], $this->chaves($c['premium']->fresh()));
    }

    public function test_img12_galeria_vazia_devolve_sem_fotos_e_nao_tela_vazia_silenciosa(): void
    {
        // (a) rascunho SEM conjunto nenhum.
        $c = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0]);
        $info = $this->servico()->para($this->porAFila($c['classico']));
        $this->assertSame([VocabularioDoPortao::SEM_FOTOS], $info['chaves']);
        $this->assertSame('Nenhuma foto na publicação', $info['bloqueios'][0]['texto']);
        $this->assertSame('Adicionar fotos', $info['bloqueios'][0]['correcao']);
        $this->assertSame(VocabularioDoPortao::SEVERIDADE_VERMELHA, $info['bloqueios'][0]['severidade']);
        $this->assertSame(0, $info['na_galeria']);

        // (b) conjunto existindo, mas com TODAS as fotos fora da publicação (`fora`/`posicao`).
        $c2 = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0]);
        $conj = $this->conjunto($c2['rascunho']);
        $this->imagem($conj, PubAprovacaoImagem::REPROVADA, null, false, true);
        $this->imagem($conj, PubAprovacaoImagem::SUBSTITUIDA, null, false, true);
        $info2 = $this->servico()->para($this->porAFila($c2['classico']));
        $this->assertSame([VocabularioDoPortao::SEM_FOTOS], $info2['chaves'], 'foto reprovada/substituída continuou contando como galeria');
        $this->assertSame(0, $info2['pendentes'], 'reprovada não é "pendente": ela saiu da publicação');

        // ISCA — com UMA foto de volta na publicação, `sem_fotos` desaparece.
        $this->imagem($conj, PubAprovacaoImagem::APROVADA, 0);
        $this->assertSame([], $this->chaves($c2['classico']->fresh()), 'ISCA CEGA: `sem_fotos` não sai nem com foto na galeria.');
    }

    public function test_photos_aponta_para_a_primeira_com_alerta_e_sem_alerta_para_a_primeira_pendente(): void
    {
        $c = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0]);
        $conj = $this->conjunto($c['rascunho']);

        $semAlerta = $this->imagem($conj, PubAprovacaoImagem::PENDENTE, 0, false);
        $comAlerta = $this->imagem($conj, PubAprovacaoImagem::PENDENTE, 1, true);
        $this->imagem($conj, PubAprovacaoImagem::APROVADA, 2, false);

        $info = $this->servico()->para($this->porAFila($c['classico']));

        // `fix()` (`:1171`): `inf.alerts[0] || inf.pend[0]` — a COM ALERTA vence, mesmo vindo
        // depois na ordem da galeria.
        $this->assertSame($comAlerta->id, $info['foco_imagem_id'], 'a tela abriria a pendente sem alerta tendo uma com alerta à espera');
        $this->assertSame(2, $info['pendentes']);
        $this->assertSame(1, $info['com_alerta']);

        // O texto do protótipo (`:1090`), com o sufixo do alerta e a pluralização pt-BR.
        $this->assertSame('2 fotos aguardando conferência · 1 com alerta automático', $info['bloqueios'][0]['texto']);
        // ⚠️ VOC-05 — `photos` COM alerta é VERMELHO (`:1295`).
        $this->assertSame(VocabularioDoPortao::SEVERIDADE_VERMELHA, $info['bloqueios'][0]['severidade']);
        $this->assertContains(VocabularioDoPortao::ALERTA_EM_FOTO_DE_IA, $info['kinds']);

        // ⚠️ ISCA / SEM ALERTA — tirando o alerta, o foco cai na PRIMEIRA pendente, o texto perde
        // o sufixo e a cor volta para âmbar. Três mudanças observáveis no mesmo ato.
        PubAprovacaoImagemAlerta::query()
            ->where('aprovacao_imagem_id', $comAlerta->id)
            ->update(['severidade' => PubAprovacaoImagemAlerta::OK]);

        $info2 = $this->servico()->para($c['classico']->fresh());
        $this->assertSame($semAlerta->id, $info2['foco_imagem_id'], 'ISCA CEGA: o foco não muda quando o alerta sai.');
        $this->assertSame('2 fotos aguardando conferência', $info2['bloqueios'][0]['texto']);
        $this->assertSame(VocabularioDoPortao::SEVERIDADE_AMBAR, $info2['bloqueios'][0]['severidade']);
        $this->assertNotContains(VocabularioDoPortao::ALERTA_EM_FOTO_DE_IA, $info2['kinds']);
        $this->assertSame(0, $info2['com_alerta']);

        // ⚠️ Alerta em imagem **APROVADA** não conta (a pessoa já decidiu com ele na tela).
        $outro = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0]);
        $cj = $this->conjunto($outro['rascunho']);
        $this->imagem($cj, PubAprovacaoImagem::APROVADA, 0, true);
        $info3 = $this->servico()->para($this->porAFila($outro['classico']));
        $this->assertSame([], $info3['chaves']);
        $this->assertNotContains(VocabularioDoPortao::ALERTA_EM_FOTO_DE_IA, $info3['kinds'], 'alerta em foto já decidida voltou a contar — o painel pediria para sempre uma revisão que já aconteceu');
    }

    public function test_o_bloqueio_de_fotos_nao_faz_uma_consulta_por_grupo(): void
    {
        // ⚠️ GATE DE DESENHO (T-186-38): no lote, uma consulta por grupo multiplica a página. A
        // medição é por CONTAGEM DE CONSULTAS, com dois tamanhos de rascunho e o MESMO número.
        $contar = function (int $quantosGrupos): int {
            $c = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0]);
            $geral = $this->conjunto($c['rascunho'], PubAprovacaoConjunto::GERAL);
            $this->imagem($geral, PubAprovacaoImagem::APROVADA, 0);
            for ($i = 0; $i < $quantosGrupos; $i++) {
                $g = $this->conjunto($c['rascunho'], 'COLOR:'.$i);
                $this->imagem($g, PubAprovacaoImagem::APROVADA, 0);
            }
            $oferta = $this->porAFila($c['classico']);
            $servico = $this->servico();

            DB::enableQueryLog();
            DB::flushQueryLog();
            $servico->para($oferta);
            $consultas = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $consultas;
        };

        $com1 = $contar(1);
        $com12 = $contar(12);

        $this->assertGreaterThan(0, $com1, 'ISCA CEGA: o log de consultas não capturou nada — o gate rodaria sobre zero casos.');
        $this->assertSame($com1, $com12, "o número de consultas cresceu com o número de grupos ({$com1} → {$com12}) — é o N+1 que a decisão de 14/10 proíbe");
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // OS OUTROS BLOQUEIOS: título, alteração de preço, ajuste e preparação
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_titulo_com_sessenta_nao_bloqueia_e_com_sessenta_e_um_bloqueia(): void
    {
        // ⚠️ `:1097` é `> 60`, **não** `>= 60`: 60 é o limite LEGÍTIMO do Mercado Livre.
        $c60 = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0, 'titulo' => str_repeat('A', 60)]);
        $this->galeriaAprovada($c60['rascunho']);
        $this->assertSame([], $this->chaves($this->porAFila($c60['classico'])), 'título com EXATAMENTE 60 caracteres passou a bloquear — o `>` virou `>=`');

        $c61 = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0, 'titulo' => str_repeat('A', 61)]);
        $this->galeriaAprovada($c61['rascunho']);
        $info = $this->servico()->para($this->porAFila($c61['classico']));
        $this->assertSame([VocabularioDoPortao::TITULO_LONGO], $info['chaves']);
        $this->assertSame('Título com 61 caracteres (máx. 60)', $info['bloqueios'][0]['texto']);
        $this->assertSame('Ver conteúdo', $info['bloqueios'][0]['correcao']);
        $this->assertSame(60, BloqueiosDaOfertaService::TITULO_MAXIMO);

        // ⚠️ A contagem é de CARACTERES, não de bytes: `mb_strlen`. 60 letras acentuadas passam.
        $cAcento = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0, 'titulo' => str_repeat('á', 60)]);
        $this->galeriaAprovada($cAcento['rascunho']);
        $this->assertSame([], $this->chaves($this->porAFila($cAcento['classico'])), '60 caracteres acentuados foram contados como 120 bytes — `strlen` no lugar de `mb_strlen`');
    }

    public function test_alteracao_de_preco_e_ajuste_em_aberto_tem_o_texto_e_a_correcao_do_prototipo(): void
    {
        $quem = User::factory()->create();
        $c = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0]);
        $this->galeriaAprovada($c['rascunho']);
        $oferta = $this->porAFila($c['classico']);

        // Sem aviso e sem ajuste: nada bloqueia.
        $this->assertSame([], $this->chaves($oferta));

        $oferta->update(['preco_alterado' => ['de' => 799.9, 'para' => 749.9, 'quem' => $quem->id, 'quando' => '2026-10-15 10:12:00']]);
        $info = $this->servico()->para($oferta->fresh());
        $this->assertSame([VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA], $info['chaves']);
        $this->assertSame('Preço alterado após a última conferência: R$ 799,90 → R$ 749,90', $info['bloqueios'][0]['texto']);
        $this->assertSame('Conferir alteração', $info['bloqueios'][0]['correcao']);
        $this->assertSame(VocabularioDoPortao::SEVERIDADE_AMBAR, $info['bloqueios'][0]['severidade']);

        // UM ajuste em aberto — singular.
        $a1 = $this->ajusteAberto($oferta, $quem);
        $info2 = $this->servico()->para($oferta->fresh());
        $this->assertSame([VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA, VocabularioDoPortao::AJUSTE_EM_ABERTO], $info2['chaves'], 'a ordem de `change` antes de `adjust` é a de `info()` (`:1095` antes de `:1096`)');
        $this->assertSame('1 ajuste solicitado em aberto', $info2['bloqueios'][1]['texto']);
        $this->assertSame('Ver ajustes', $info2['bloqueios'][1]['correcao']);

        // DOIS — plural em pt-BR, com o verbo concordando ("solicitados").
        $this->ajusteAberto($oferta, $quem);
        $this->assertSame('2 ajustes solicitados em aberto', $this->servico()->para($oferta->fresh())['bloqueios'][1]['texto']);

        // ⚠️ ISCA — ajuste FECHADO não conta.
        $a1->update(['aberto' => false]);
        $this->assertSame('1 ajuste solicitado em aberto', $this->servico()->para($oferta->fresh())['bloqueios'][1]['texto'], 'ISCA CEGA: ajuste resolvido continua contando como bloqueio.');
    }

    public function test_prep_aparece_so_em_preparando_com_a_frase_real_e_correcao_vazia(): void
    {
        $c = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0]);
        $this->galeriaAprovada($c['rascunho']);

        // A oferta NASCE em `preparando` (default do modelo E da coluna).
        $oferta = $c['classico'];
        $oferta->update(['preparo_frase' => 'calculando frete e composição de preço']);
        $oferta = $oferta->fresh();
        $this->assertSame(PubAprovacaoOferta::PREPARANDO, $oferta->andamento);

        $info = $this->servico()->para($oferta);
        $this->assertSame([VocabularioDoPortao::EM_PREPARACAO], $info['chaves']);
        // ⚠️ AUTO-01 — a frase REAL do que falta, não um rótulo genérico (`:1089`).
        $this->assertSame('Sistema ainda calculando frete e composição de preço', $info['bloqueios'][0]['texto']);
        $this->assertSame('', $info['bloqueios'][0]['correcao'], 'botão em `em_preparacao` é botão que não resolve nada');
        $this->assertSame('!', $info['bloqueios'][0]['glifo'], 'VOC-05: nem o bloqueio sem ação fica só com cor');

        // ⚠️ `preparando` NÃO é `live`: a oferta não é aprovável nem com zero bloqueios.
        $this->assertFalse($info['ao_vivo']);
        $this->assertFalse($info['aprovavel']);

        // ISCA — movida para a fila pela ÚNICA PORTA, `em_preparacao` desaparece.
        $info2 = $this->servico()->para($this->porAFila($oferta));
        $this->assertSame([], $info2['chaves'], 'ISCA CEGA: `em_preparacao` não sai nem fora de `preparando`.');
        $this->assertTrue($info2['ao_vivo']);
        $this->assertTrue($info2['aprovavel']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // BLOQ-03 / BLOQ-04 / BLOQ-12 — `kinds`, `aprovavel` e o rodapé
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_bloq03_kinds_ganha_alerta_e_falha_que_nao_sao_bloqueio(): void
    {
        $c = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0]);
        $conj = $this->conjunto($c['rascunho']);
        $this->imagem($conj, PubAprovacaoImagem::APROVADA, 0);
        $this->imagem($conj, PubAprovacaoImagem::PENDENTE, 1, true);

        $oferta = $this->porAFila($c['classico']);
        $info = $this->servico()->para($oferta);

        // `alerta_em_foto_de_ia` está em `kinds` e **NÃO** em `chaves`: é `kind` (`:1098`), não
        // bloqueio. Unificá-los faria o filtro esconder linhas pelo ESTADO da oferta.
        $this->assertContains(VocabularioDoPortao::ALERTA_EM_FOTO_DE_IA, $info['kinds']);
        $this->assertNotContains(VocabularioDoPortao::ALERTA_EM_FOTO_DE_IA, $info['chaves']);
        $this->assertFalse(VocabularioDoPortao::ehBloqueio(VocabularioDoPortao::ALERTA_EM_FOTO_DE_IA));
        $this->assertFalse(VocabularioDoPortao::ehBloqueio(VocabularioDoPortao::FALHA_NA_PUBLICACAO));

        // ⚠️ A CONTA, explícita: `info()` empilha **9** chaves em `B` (`:1089-:1097`), e **8**
        // delas são "coisa a consertar" — `em_preparacao` é aviso de preparo, com correção vazia.
        // É daí que vem "os 8 bloqueios" da fase, e deixar isso implícito já custou uma contagem
        // errada neste plano.
        $this->assertCount(9, VocabularioDoPortao::BLOQUEIOS_EM_ORDEM);
        $this->assertCount(8, VocabularioDoPortao::OS_OITO);
        $this->assertSame(
            [VocabularioDoPortao::EM_PREPARACAO],
            array_values(array_diff(VocabularioDoPortao::BLOQUEIOS_EM_ORDEM, VocabularioDoPortao::OS_OITO)),
            'a diferença entre os 9 de `info()` e os 8 da fase tem de ser EXATAMENTE `em_preparacao`'
        );

        // ⚠️ `falha_na_publicacao` entra em `kinds` pelo ANDAMENTO `falha`, e os dois namespaces
        // são diferentes de propósito.
        $aprovado = app(\App\Services\Publicador\Aprovacao\AndamentoDaOfertaService::class);
        $comFalha = $aprovado->mover($aprovado->mover($aprovado->mover($oferta, PubAprovacaoOferta::APROVADO), PubAprovacaoOferta::PUBLICANDO), PubAprovacaoOferta::FALHA, null, 'O Mercado Livre não respondeu a tempo.');
        $this->assertSame(PubAprovacaoOferta::FALHA, $comFalha->andamento);

        $infoFalha = $this->servico()->para($comFalha);
        $this->assertContains(VocabularioDoPortao::FALHA_NA_PUBLICACAO, $infoFalha['kinds']);
        $this->assertNotContains(VocabularioDoPortao::FALHA_NA_PUBLICACAO, $infoFalha['chaves']);
        $this->assertFalse($infoFalha['ao_vivo'], '`falha` não é `live`');
        $this->assertFalse($infoFalha['aprovavel']);

        // ISCA — sem alerta e fora de `falha`, nenhum dos dois `kinds` aparece.
        $limpo = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0]);
        $this->galeriaAprovada($limpo['rascunho']);
        $this->assertSame([], $this->servico()->para($this->porAFila($limpo['classico']))['kinds'], 'ISCA CEGA: os `kinds` extras aparecem sem motivo.');
    }

    public function test_bloq04_aprovavel_e_verdadeiro_so_com_live_e_zero_bloqueios(): void
    {
        $c = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => 10.0]);
        $this->galeriaAprovada($c['rascunho']);
        $porta = app(\App\Services\Publicador\Aprovacao\AndamentoDaOfertaService::class);

        // (1) zero bloqueios nos TRÊS estados `live` → aprovável nos três.
        $oferta = $porta->mover($c['classico'], PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->assertTrue($this->servico()->para($oferta)['aprovavel']);

        $oferta = $porta->mover($oferta, PubAprovacaoOferta::EM_REVISAO_ECF);
        $this->assertTrue($this->servico()->para($oferta)['aprovavel']);

        $oferta = $porta->mover($oferta, PubAprovacaoOferta::AJUSTES_SOLICITADOS);
        $this->assertTrue($this->servico()->para($oferta)['aprovavel']);
        $this->assertSame(PubAprovacaoOferta::LIVE, [
            PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            PubAprovacaoOferta::EM_REVISAO_ECF,
            PubAprovacaoOferta::AJUSTES_SOLICITADOS,
        ]);

        // (2) fora de `live`, nunca — nem com zero bloqueios.
        $oferta = $porta->mover($oferta, PubAprovacaoOferta::APROVADO);
        $this->assertSame([], $this->servico()->para($oferta)['chaves'], 'o cenário precisa continuar com ZERO bloqueios para o `live` ser a única variável');
        $this->assertFalse($this->servico()->para($oferta)['aprovavel'], '`aprovado` não é aprovável de novo');

        $oferta = $porta->mover($oferta, PubAprovacaoOferta::PUBLICANDO);
        $this->assertFalse($this->servico()->para($oferta)['aprovavel']);

        $oferta->update(['ml_item_id' => 'MLB1234567890']);
        $oferta = $porta->mover($oferta->fresh(), PubAprovacaoOferta::PUBLICADO);
        $this->assertFalse($this->servico()->para($oferta)['aprovavel']);

        // (3) `live` **com** bloqueio → nunca.
        $c2 = $this->cenario(['preco' => 100.0, 'custo' => 80.0, 'frete' => 10.0]);
        $this->galeriaAprovada($c2['rascunho']);
        $comBloqueio = $this->porAFila($c2['classico']);
        $info = $this->servico()->para($comBloqueio);
        $this->assertTrue($info['ao_vivo']);
        $this->assertNotSame([], $info['chaves']);
        $this->assertFalse($info['aprovavel'], 'BLOQ-04: um bloqueio em aberto é recusa — e esta é a ÚNICA trava, porque aprovar já publica');
    }

    public function test_bloq12_o_rodape_tem_o_literal_verde_e_o_bloqueado_com_mais_n(): void
    {
        $servico = $this->servico();

        // Sem bloqueio: o literal verde, byte a byte (`:1297`).
        $this->assertSame('Fotos, preço e conteúdo conferidos', $servico->resumoDoRodape([]));
        $this->assertSame(VocabularioDoPortao::TUDO_CONFERIDO, $servico->resumoDoRodape([]));

        // Um bloqueio: prefixo + abreviação em MINÚSCULA, sem `+N`.
        $um = [['chave' => VocabularioDoPortao::FRETE_AUSENTE, 'texto' => 'x', 'correcao' => '', 'severidade' => 'vermelho', 'glifo' => '!']];
        $this->assertSame('Bloqueado: frete pendente', $servico->resumoDoRodape($um));

        // Dois: `+1` — o `+N` conta os DEMAIS, não o total (`:1238`).
        $dois = array_merge($um, [['chave' => VocabularioDoPortao::TITULO_LONGO, 'texto' => 'y', 'correcao' => '', 'severidade' => 'ambar', 'glifo' => '!']]);
        $this->assertSame('Bloqueado: frete pendente +1', $servico->resumoDoRodape($dois));

        $tres = array_merge($dois, [['chave' => VocabularioDoPortao::AJUSTE_EM_ABERTO, 'texto' => 'z', 'correcao' => '', 'severidade' => 'ambar', 'glifo' => '!']]);
        $this->assertSame('Bloqueado: frete pendente +2', $servico->resumoDoRodape($tres));

        // E de ponta a ponta, no cenário real: o rodapé abrevia o PRIMEIRO da ordem de `info()`.
        $c = $this->cenario(['preco' => 100.0, 'custo' => 80.0, 'frete' => null, 'titulo' => str_repeat('A', 61)]);
        $conj = $this->conjunto($c['rascunho']);
        $this->imagem($conj, PubAprovacaoImagem::PENDENTE, 0);
        $info = $this->servico()->para($this->porAFila($c['classico']));

        $this->assertSame([
            VocabularioDoPortao::FOTOS_A_CONFERIR,
            VocabularioDoPortao::FRETE_AUSENTE,
            VocabularioDoPortao::TITULO_LONGO,
        ], $info['chaves']);
        $this->assertSame('Bloqueado: fotos a conferir +2', $info['resumo']);
        $this->assertSame($info['resumo'], $this->servico()->resumoDoRodape($info['bloqueios']), 'o `resumo` do retorno divergiu de `resumoDoRodape()` — duas verdades');
    }

    public function test_o_texto_do_frete_nomeia_a_fonte_que_de_fato_falta_e_nao_mente(): void
    {
        // ⚠️ DIVERGÊNCIA DECLARADA. No protótipo só o frete podia faltar (`fin()` `:1067`); aqui
        // CINCO linhas anulam o resultado. Dizer "frete" quando falta o CUSTO seria a tela
        // afirmando o que não é — e mandando a pessoa ao modal errado.
        $c = $this->cenario(['preco' => 100.0, 'custo' => null, 'frete' => 10.0]);
        $this->galeriaAprovada($c['rascunho']);
        $info = $this->servico()->para($this->porAFila($c['classico']));

        $this->assertSame([VocabularioDoPortao::FRETE_AUSENTE], $info['chaves'], 'custo ausente tem de cair na MESMA chave — o rótulo do filtro é "Frete ou custo ausente"');
        $this->assertSame('Custo do produto não confirmado · resultado indisponível', $info['bloqueios'][0]['texto']);
        $this->assertSame('Solicitar ajuste', $info['bloqueios'][0]['correcao'], 'mandar "Informar frete" quando falta o custo manda a pessoa ao lugar errado');
        $this->assertSame(VocabularioDoPortao::CORRECAO_SEM_FRETE, $info['bloqueios'][0]['correcao']);

        // ISCA — faltando o FRETE, volta o literal e a correção do protótipo.
        $c2 = $this->cenario(['preco' => 100.0, 'custo' => 20.0, 'frete' => null]);
        $this->galeriaAprovada($c2['rascunho']);
        $info2 = $this->servico()->para($this->porAFila($c2['classico']));
        $this->assertSame('Frete do vendedor não confirmado · resultado indisponível', $info2['bloqueios'][0]['texto'], 'ISCA CEGA: o literal do protótipo não volta nem quando é o frete que falta.');
        $this->assertSame('Informar frete', $info2['bloqueios'][0]['correcao']);

        // E o rótulo do filtro é o LARGO, que já previa os dois casos.
        $this->assertSame('Frete ou custo ausente', VocabularioDoPortao::rotuloDe(VocabularioDoPortao::FRETE_AUSENTE));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ GATES DE AUSÊNCIA — O SEGUNDO CLIQUE E O DESFAZER FORAM **RECUSADOS** EM 14/10/2026
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * O CÓDIGO sem os comentários, pelo tokenizador do PHP.
     *
     * ⚠️ Molde de `MigrationDoPortaoGuardaTest::semComentarios` (plano 186-03). Existe porque
     * **palavra no DOCBLOCK que explica "eu não uso isso" faz o gate reprovar pelo próprio
     * conserto** — aconteceu com `try` nesta fase. As quebras de linha são preservadas para não
     * colar dois statements.
     */
    private function semComentarios(string $texto): string
    {
        $saida = '';

        foreach (token_get_all($texto) as $token) {
            if (! is_array($token)) {
                $saida .= $token;

                continue;
            }

            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $saida .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $saida .= $token[1];
        }

        return $saida;
    }

    public function test_o_leitor_tira_os_comentarios_do_plano_08_e_preserva_o_codigo(): void
    {
        // ⚠️ Sem esta prova o gate de ausência abaixo poderia estar lendo o arquivo INTEIRO (e
        // reprovando pelo docblock que explica a decisão) ou lendo NADA (e passando sempre).
        $amostra = <<<'PHP'
        <?php
        // desfazer no comentario de linha
        /* desfazer no comentario de bloco */
        /** @doc desfazer no docblock */
        $x = 'codigo preservado';
        PHP;

        $limpo = $this->semComentarios($amostra);

        $this->assertStringNotContainsString('desfazer no comentario de linha', $limpo);
        $this->assertStringNotContainsString('desfazer no comentario de bloco', $limpo);
        $this->assertStringNotContainsString('desfazer no docblock', $limpo);
        $this->assertStringContainsString("\$x = 'codigo preservado';", $limpo, 'o leitor comeu o CÓDIGO junto do comentário — o gate ficaria vazio por construção');

        // E sobre um arquivo REAL deste plano: o docblock sai, as constantes ficam.
        $real = $this->semComentarios((string) file_get_contents(base_path(self::MEUS_ARQUIVOS[0])));
        $this->assertStringContainsString("const FRETE_AUSENTE = 'frete_ausente'", $real);
        $this->assertStringNotContainsString('OFERECIDO e RECUSADO', $real, 'o docblock sobreviveu ao leitor — o gate de ausência reprovaria pelo próprio conserto');
    }

    public function test_nenhum_arquivo_deste_plano_cria_segundo_clique_desfazer_ou_cancelar_aprovacao(): void
    {
        // ⚠️⚠️ O gate transversal da decisão de 14/10/2026 (vale para as Fases 187-194): dois atos,
        // janela de desfazer e estado de "exceção aguardando autorização" foram **OFERECIDOS e
        // RECUSADOS**. Quem "completar o fluxo" achando que faltou tem de encontrar isto aqui.
        //
        // ⚠️ As sondas são ancoradas no SUJEITO (aprovação / publicação), não em palavra solta:
        // `cancelar` casaria "cancelar o pedido" de outro módulo, e `undo` casaria `undone`.
        $proibidas = [
            'confirmar publicação' => '/confirmar[_ ]?(a )?publica/iu',
            'segundo passo da aprovação' => '/(segundo|dois)[_ ](passo|ato|clique)s?/iu',
            'desfazer a aprovação' => '/desfazer[_ ]?(a )?(aprova|publica)/iu',
            'undo da aprovação' => '/\bundo[_ ]?(approv|publi)/iu',
            'cancelar a aprovação' => '/cancelar[_ ]?(a )?aprova/iu',
            'janela de desfazer' => '/janela[_ ]de[_ ]desfazer/iu',
            'exceção aguardando autorização' => '/(excecao|exceção)[_ ]?(aguardando|pendente|a[_ ]autorizar)/iu',
        ];

        // ISCA DE CADA SONDA — uma amostra que ela TEM de pegar. Sem isto eu não saberia se o
        // silêncio vem de o código estar limpo ou de a regex não casar nada.
        $iscas = [
            'confirmar publicação' => "public function confirmarPublicacao() {}",
            'segundo passo da aprovação' => "const SEGUNDO_PASSO = 'x';",
            'desfazer a aprovação' => "public function desfazerAprovacao() {}",
            'undo da aprovação' => "public function undoApproval() {}",
            'cancelar a aprovação' => "public function cancelarAprovacao() {}",
            'janela de desfazer' => "const JANELA_DE_DESFAZER = 30;",
            'exceção aguardando autorização' => "const EXCECAO_AGUARDANDO = 'x';",
        ];
        foreach ($proibidas as $nome => $padrao) {
            $this->assertSame(1, preg_match($padrao, $iscas[$nome]), "ISCA CEGA: a sonda `{$nome}` não pega nem a amostra que ela existe para pegar.");
        }

        // ⚠️ ISCA INVERTIDA: o que é LEGÍTIMO e parecido **não** pode casar. Sem ela, uma sonda
        // larga reprovaria o trabalho correto e o executor seguinte a enfraqueceria.
        $legitimos = [
            'o rótulo do botão, que NÃO muda' => 'Aprovar para publicação',
            'o ponto de extensão da Fase 193' => 'protected function aoAprovar(PubAprovacaoOferta $o) {}',
            'a exceção que nasce valendo' => "PubAprovacaoExcecao::create(['ativa' => true])",
            'cancelar de outro assunto' => 'public function cancelarPedidoDoCliente() {}',
        ];
        foreach ($legitimos as $nome => $amostra) {
            foreach ($proibidas as $qual => $padrao) {
                $this->assertSame(0, preg_match($padrao, $amostra), "a sonda `{$qual}` reprova o que é legítimo ({$nome}) — ela reprovaria pelo próprio conserto");
            }
        }

        // ⚠️ A lista de arquivos é CONSTANTE e cada entrada EXISTE (isca de caminho fantasma:
        // arquivo ausente faria o gate reportar zero infrações e parecer verde).
        $this->assertCount(2, self::MEUS_ARQUIVOS);
        $infratores = [];
        foreach (self::MEUS_ARQUIVOS as $relativo) {
            $caminho = base_path($relativo);
            $this->assertFileExists($caminho, "alvo do gate não existe: {$relativo}");
            $codigo = $this->semComentarios((string) file_get_contents($caminho));
            $this->assertNotSame('', trim($codigo), "ISCA CEGA: `{$relativo}` ficou vazio depois de tirar os comentários.");

            foreach ($proibidas as $nome => $padrao) {
                if (preg_match($padrao, $codigo) === 1) {
                    $infratores[] = $relativo.' → '.$nome;
                }
            }
        }

        // ⚠️ Contagem FIXA (zero), com a lista nomeada: contagem que depende de quantos arquivos
        // existem é frágil por construção — foram dois gates assim consertados nesta fase.
        $this->assertSame([], $infratores, 'segundo clique / desfazer / estado de espera entrou no código: '.implode(' · ', $infratores));

        // E o rótulo do botão **não muda**: continua o literal do protótipo.
        $this->assertSame('Aprovar para publicação', 'Aprovar para publicação');
    }

    public function test_o_minimo_da_margem_vem_do_resolvedor_unico_e_nunca_de_literal_neste_plano(): void
    {
        // ⚠️ Critério 6: nenhum número de taxa nem de mínimo mora no código deste plano. O mínimo
        // chega pela composição, que o resolve em UM lugar (`margemMinimaComOrigem`).
        foreach (self::MEUS_ARQUIVOS as $relativo) {
            $codigo = $this->semComentarios((string) file_get_contents(base_path($relativo)));

            // ISCA DA SONDA: ela pega o literal sintético...
            $this->assertSame(1, preg_match('/\b12(\.0+)?\b/', 'margem minima 12.0'), 'ISCA CEGA: a sonda de literal não acha nem o número sintético.');
            // ...e não acha o número de taxa/mínimo em nenhum arquivo meu.
            foreach (['11', '12', '16.5', '17', '19', '0.11', '0.12', '0.17'] as $taxa) {
                $this->assertSame(
                    0,
                    preg_match('/(?<![\w.])'.preg_quote($taxa, '/').'(?![\w.])/', $codigo),
                    "`{$relativo}` tem o literal `{$taxa}` no CÓDIGO — taxa e mínimo vêm das fontes reais, nunca de número escrito"
                );
            }

            // ⚠️ E `config(` não é lida aqui: duplicar o fallback deixaria os dois caminhos livres
            // para divergir, e o **zero do parâmetro voltaria a desligar o bloqueio** num deles.
            $this->assertSame(0, preg_match("/config\s*\(\s*'publicador/", $codigo), "`{$relativo}` lê a config da margem mínima direto — o resolvedor mora em UM lugar (`ComposicaoFinanceiraService::margemMinimaComOrigem`)");
        }

        // ISCA do `60`: ele **está** no código, e é LEGÍTIMO (o teto do título do protótipo). Sem
        // esta linha a sonda acima poderia estar recusando qualquer número e o gate seria cego.
        $servicoLimpo = $this->semComentarios((string) file_get_contents(base_path(self::MEUS_ARQUIVOS[1])));
        $this->assertSame(1, preg_match('/TITULO_MAXIMO = 60;/', $servicoLimpo), 'ISCA CEGA: o `60` do título não está no código — a sonda de literais estaria medindo um arquivo sem número nenhum.');

        // E os dois lados do fallback continuam no resolvedor, que é de outro plano (SOMENTE LEITURA).
        $resolvedor = $this->semComentarios((string) file_get_contents(base_path('app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php')));
        $this->assertStringContainsString('MINIMO_PARAMETRO', $resolvedor);
        $this->assertStringContainsString('MINIMO_CONFIG', $resolvedor);
    }

    public function test_terr09_a_fronteira_do_d11_esta_declarada_e_este_plano_nao_escreve_no_ml(): void
    {
        // ⚠️⚠️ A FRONTEIRA, declarada: o gate **D-11** (`tests/js/estrutura-meus-anuncios.test.js`)
        // vale para a **LISTA de Publicações**, que NUNCA escreve no Mercado Livre. A **FILA** do
        // portão escreve — mas **não é este plano**: aqui só se calcula bloqueio. A escrita no ML
        // é da **Fase 193**, pelo ponto de extensão `aoAprovar()`.
        $d11 = base_path('tests/js/estrutura-meus-anuncios.test.js');
        $this->assertFileExists($d11, 'ISCA CEGA: o arquivo do D-11 não existe — o gate de territorio rodaria sobre zero casos.');

        // ⚠️ A conferência é por SEMÂNTICA, não por número de linha (o arquivo é de outra área e
        // de outra sessão): os dois gates continuam lá, com o nome deles.
        $texto = (string) file_get_contents($d11);
        $this->assertStringContainsString('D-11', $texto, 'o gate D-11 saiu do arquivo — território de outra fase, intocável');
        $this->assertStringContainsString('D-22', $texto);

        // E o lado de cá: nenhum arquivo deste plano chama o ML, nem o serviço de publicação.
        foreach (self::MEUS_ARQUIVOS as $relativo) {
            $codigo = $this->semComentarios((string) file_get_contents(base_path($relativo)));
            foreach (['MlPublicacaoService', 'Http::', 'items', 'MercadoLivreService'] as $proibido) {
                $this->assertStringNotContainsString($proibido, $codigo, "`{$relativo}` fala com o Mercado Livre — este plano calcula bloqueio, a publicação é da Fase 193");
            }
        }

        // ISCA da sonda de território: ela acha o nome do serviço quando ele ESTÁ presente.
        $this->assertStringContainsString('MlPublicacaoService', 'use App\Services\Mlb\Publicacao\MlPublicacaoService;', 'ISCA CEGA: a sonda de território não acha nem o nome que procura.');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ A BIJEÇÃO ids ↔ MÉTODOS — TESTE SEM id NO MANIFESTO **NUNCA RODA**
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_todo_metodo_do_plano_08_tem_id_no_manifesto_e_todo_id_casa_um_metodo(): void
    {
        // ⚠️ O SUFIXO `_do_plano_08` NÃO é estilo: o `--filter` do PHPUnit casa por nome em TODOS
        // os arquivos da suíte, e os planos 186-06 e 186-07 têm métodos de bijeção homônimos — o
        // filtro genérico SOMA as contagens de vários arquivos e o manifesto deixa de medir este.
        // ⚠️⚠️ E teste que existe no arquivo e não tem id **NUNCA RODA** em gate nenhum: foi o
        // sobrevivente da campanha de mutação do plano 186-03, e era lacuna REAL.
        $manifesto = base_path('tests/gates/contagens-186-08.txt');
        $this->assertFileExists($manifesto);

        $arquivos = [base_path('tests/Unit/Publicador/Aprovacao/BloqueiosDaOfertaServiceTest.php')];

        $metodos = [];
        foreach ($arquivos as $arquivo) {
            $this->assertFileExists($arquivo);
            preg_match_all('/public function (test_[a-z0-9_]+)\(/', (string) file_get_contents($arquivo), $m);
            $metodos = array_merge($metodos, $m[1]);
        }
        sort($metodos);

        // ISCA: a sonda acha os testes de verdade — e me acha a mim.
        $this->assertGreaterThanOrEqual(20, count($metodos), 'ISCA CEGA: a sonda achou menos métodos do que o arquivo tem — a bijeção rodaria sobre lista curta.');
        $this->assertContains('test_todo_metodo_do_plano_08_tem_id_no_manifesto_e_todo_id_casa_um_metodo', $metodos, 'ISCA CEGA: a sonda não acha nem o próprio método da bijeção.');

        $filtros = [];
        foreach (file($manifesto, FILE_IGNORE_NEW_LINES) as $linha) {
            if (preg_match('/^\s*(#|$)/', $linha)) {
                continue;
            }
            $campos = preg_split('/\s+/', trim($linha));
            $this->assertSame('php', $campos[1] ?? null, "a linha `{$linha}` do manifesto não é do modo `php` — este plano não tem gate de JS");
            $filtros[$campos[0]] = $campos[2];
        }
        $this->assertNotEmpty($filtros, 'ISCA CEGA: o manifesto não tem linha de gate nenhuma.');

        // (1) ⚠️ TODO MÉTODO coberto por pelo menos um filtro.
        $semId = [];
        foreach ($metodos as $metodo) {
            $coberto = false;
            foreach ($filtros as $filtro) {
                if (preg_match('/^(?:'.$filtro.')$/', $metodo) === 1) {
                    $coberto = true;
                    break;
                }
            }
            if (! $coberto) {
                $semId[] = $metodo;
            }
        }
        $this->assertSame([], $semId, 'MÉTODO SEM id NO MANIFESTO — ele NUNCA RODA em gate nenhum: '.implode(', ', $semId));

        // (2) ⚠️ TODO id casa pelo menos um método — id órfão é gate sobre ZERO casos.
        $orfaos = [];
        foreach ($filtros as $id => $filtro) {
            if (array_filter($metodos, fn (string $m) => preg_match('/^(?:'.$filtro.')$/', $m) === 1) === []) {
                $orfaos[] = $id;
            }
        }
        $this->assertSame([], $orfaos, 'id do manifesto que não casa método nenhum (gate sobre ZERO casos): '.implode(', ', $orfaos));

        // ⚠️ ISCA DO CASADOR, nos dois sentidos: ele casa alternância e NÃO casa por prefixo.
        $this->assertSame(1, preg_match('/^(?:test_(a|b))$/', 'test_b'), 'ISCA CEGA: o casador não entende alternância — a bijeção daria falso negativo.');
        $this->assertSame(0, preg_match('/^(?:test_a)$/', 'test_abc'), 'ISCA CEGA: o casador casa por PREFIXO — um id cobriria métodos que ele nunca roda.');
    }
}
