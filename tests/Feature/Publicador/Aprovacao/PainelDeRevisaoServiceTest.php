<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\PubAprovacaoAjuste;
use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\Aprovacao\AjustesDaOfertaService;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService;
use App\Services\Publicador\Aprovacao\Fila\GrupoDaFilaService;
use App\Services\Publicador\Aprovacao\Fila\ProximoARevisarService;
use App\Services\Publicador\Aprovacao\Painel\PainelDeRevisaoService;
use App\Services\Publicador\Aprovacao\VocabularioDoPortao;
use App\Services\Publicador\DadosEfetivosService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 188, plano 02 — O **SERVIDOR** DO PAINEL DE REVISÃO SIMPLES (E2).
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"** — gate cuja resposta é "nada" é
 * decorativo e não entra (`feedback_gate_vazio_por_construcao`).
 *
 *   · **A NAVEGAÇÃO vindo como STRING** — foi o defeito da primeira redação deste plano: só a frase
 *     `'3 de 12 na fila'` viajava, e o ↑/↓ da wave 7 **não teria para onde ir**. Sem os ids, a tela
 *     recalcularia a fila no cliente — a segunda verdade que este plano existe para impedir.
 *   · **`preparando` entrando na navegação** — `_nav` o exclui (`:1263`), e abrir uma oferta em
 *     preparo daria `indexOf = -1`, que no protótipo produz **"0 de N na fila"**: a tela afirmando
 *     posição que não existe. ➡️ Aqui é `null`, e há caso.
 *   · **`correcao` sobrevivendo fora do `live`** — `hasFix = !!b.fix && inf.live` (`:1295`). Sem o
 *     `&& live`, oferta **já publicada** ofereceria botão de conserto.
 *   · **Os dois destinos sem rota ganhando botão** — PERGUNTA-188-B: a revisão detalhada é da Fase
 *     190/191 (`routes/mlb_anuncios.php:491`). **Botão sem destino é D4 violado**, e o usuário já
 *     exerceu essa régua QUATRO vezes.
 *   · **O `foco_imagem_id` ignorando o ALERTA** — `inf.alerts[0] || inf.pend[0]` (`:1172`): quem
 *     tem alerta automático é quem precisa de olho humano primeiro. Abrir a primeira pendente
 *     qualquer esconderia o caso perigoso.
 *   · **A cadeia deixando de ser EXCLUSIVA** — faltando dado **não há resultado**, então prejuízo e
 *     margem não existem para ser julgados (`:1092`-`:1094`, um `if` e dois `else if`).
 *   · **A EMBALAGEM anulando** — a decisão 7+10 (14/10/2026) é o oposto: ela nasce `Pendente`, o
 *     resultado sai **estimado por baixo** e prejuízo/margem **DISPARAM** sobre ele. O **FRETE**, no
 *     MESMO cenário, **anula** — é a isca que separa as duas.
 *   · **O rodapé remontando o `join` do protótipo** (`:1297`) em vez de chamar `resumoDoRodape()` —
 *     daria a SEGUNDA VERDADE da mesma frase na mesma tela (fila × painel).
 *   · **O CUSTO** — `BloqueiosDaOfertaService::para()` mais de uma vez por oferta multiplicaria as
 *     requisições ao Mercado Livre. É a classe do incidente de **30/07/2026**, quando requisições
 *     lentas ocuparam os workers do php-fpm e **até o login parou**.
 *   · **Rótulo de status do ML no payload** — AND-02 / REVS-14: o vocabulário é o **interno**, e o
 *     do ML não atravessa a fronteira.
 *
 * ⚠️ **ZERO REDE:** `Http::preventStrayRequests()` + `Http::fake` com gravador. Nenhuma chamada à
 * API real do Mercado Livre sai daqui.
 *
 * ⚠️ **ESTE ARQUIVO NÃO ALTERA NADA DAS FASES 186 E 187.** `BloqueiosDaOfertaService`,
 * `VocabularioDoPortao`, `ProximoARevisarService`, `AndamentoDaOfertaService`, `GrupoDaFilaService`
 * e `PubAprovacaoOferta` são **LIDOS** — e, no caso de custo, **SUBCLASSADOS no teste**, nunca
 * alterados no disco.
 *
 * ⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU ESTA TELA RENDERIZADA.** O banco local não tem empresa com
 * `ml_token` (a rota dá **404** aqui), `pub_aprovacao_ofertas` está **vazia** e a fila nasce vazia
 * até a Fase 194 (decisão **P3** do usuário). A conferência é do usuário, em produção, na conta 459
 * — e a **conferência visual da Fase 187 continua ABERTA**, nada daqui a substitui.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class PainelDeRevisaoServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O commit de PARTIDA desta wave — o HEAD no começo DESTE plano, capturado na primeira ação.
     *
     * ⚠️ **Ele é REFERÊNCIA DE LEITURA, nunca extremo de range confiável:** a 187-11 mediu 111
     * commits no range, **43 da sessão paralela**, e o diff cru acusava **12 dos 60 alvos vetados**.
     * ➡️ O que discrimina território é o **ESCOPO DO ASSUNTO**, abaixo.
     */
    private const PARTIDA = '0a5d4dc1';

    /**
     * O ESCOPO DO ASSUNTO deste plano.
     *
     * ⚠️ **O autor NÃO discrimina nada nesta árvore:** as três máquinas rodam com o MESMO
     * `user.name` (`MB.ECF-100376`), então `%an` é vazio por construção. O discriminador é o escopo
     * — e há **isca nas duas direções**.
     */
    private const ESCOPO = '/\(188-02\)/';

    /**
     * ⚠️ O vocabulário de **status do Mercado Livre**, que **NÃO** pode atravessar para o payload
     * (AND-02 / REVS-14).
     *
     * O nosso vocabulário é o **interno** (`PubAprovacaoOferta::ANDAMENTOS`, pt-BR). Deixar um
     * `active`/`paused` passar faria a tela misturar dois namespaces de estado — e o da fila da ECF
     * é o único que governa a aprovação.
     */
    private const STATUS_DO_ML = ['active', 'paused', 'closed', 'under_review', 'inactive', 'payment_required', 'not_yet_active'];

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    /** A categoria com fixture de tarifa no repositório. */
    private const CATEGORIA = 'MLB189007';

    private int $sequencia = 0;

    /** `"{categoria}|{listing_type}"` → corpo do `/sites/MLB/listing_prices`. */
    private array $tarifas = [];

    /** @var list<array{caminho: string, query: array}> */
    private array $chamadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ `Carbon::setTestNow()` em TODO caso que compara espera: um `subHours(9)` sem o agora
        // PARADO falha entre 00:00 e 09:00 e passa depois — foi exatamente o que deixou duas
        // falhas herdadas nesta suíte (`CockpitDaContaTest`, Fase 179, que usa `now()` 52 vezes).
        Carbon::setTestNow('2026-10-19 09:00:00');

        // ⚠️ O app token do `ClienteMlPublicador` é FINGIDO no cache: sem ele a primeira chamada
        // pública tenta `/oauth/token` e LANÇA. ⚠️ E nunca `Cache::flush()` aqui.
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        Http::preventStrayRequests();
        Http::fake(function ($req) {
            $caminho = (string) parse_url($req->url(), PHP_URL_PATH);
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $query);
            $this->chamadas[] = ['caminho' => $caminho, 'query' => $query];

            if ($caminho === '/sites/MLB/listing_prices') {
                $chave = ($query['category_id'] ?? '').'|'.($query['listing_type_id'] ?? '');
                $corpo = $this->tarifas[$chave] ?? null;

                return $corpo === null
                    ? Http::response(['message' => 'sem stub de tarifa para '.$chave], 404)
                    : Http::response($corpo, 200);
            }

            return Http::response(['message' => 'sem stub no teste'], 404);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{kit?: int, tipo?: string, titulo?: ?string, espera?: ?string, responsavel?: ?User,
     *   aprovador?: ?User, versao?: int, versao_aprovada?: ?int, origem?: string, mlb?: ?string,
     *   preparo?: ?string, categoria?: ?string, sku?: ?string, produto?: string, erro?: ?string,
     *   preco_alterado?: ?array}  $o
     */
    private function oferta(Company $empresa, string $andamento, array $o = []): PubAprovacaoOferta
    {
        $this->sequencia++;

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => array_key_exists('sku', $o) ? $o['sku'] : ('PAI-'.$this->sequencia),
            'nome' => $o['produto'] ?? 'Poltrona Beny',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => $o['kit'] ?? 1,
        ]);

        $rascunho = PubRascunho::create([
            'produto_id' => $produto->id,
            'categoria_id' => array_key_exists('categoria', $o) ? $o['categoria'] : self::CATEGORIA,
        ]);

        $alvo = PubRascunhoAlvo::create([
            'rascunho_id' => $rascunho->id,
            'listing_type_id' => $o['tipo'] ?? self::CLASSICO,
            'titulo' => array_key_exists('titulo', $o) ? $o['titulo'] : 'Poltrona Beny Decorativa Veludo',
            'posicao' => ($o['tipo'] ?? self::CLASSICO) === self::CLASSICO ? 0 : 1,
        ]);

        $oferta = PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $empresa->id,
            'andamento' => $andamento,
            'versao' => $o['versao'] ?? 1,
            'versao_aprovada' => $o['versao_aprovada'] ?? null,
            'origem' => $o['origem'] ?? PubAprovacaoOferta::ORIGEM_AUTO,
            'responsavel_id' => isset($o['responsavel']) ? $o['responsavel']->id : null,
            'aprovado_por' => isset($o['aprovador']) ? $o['aprovador']->id : null,
            'ml_item_id' => $o['mlb'] ?? null,
            'preparo_frase' => $o['preparo'] ?? null,
            'erro_publicacao' => $o['erro'] ?? null,
            'preco_alterado' => $o['preco_alterado'] ?? null,
            'entrou_na_fila_em' => array_key_exists('espera', $o) ? $o['espera'] : '2026-10-19 06:00:00',
        ]);

        return $oferta->fresh(['alvo', 'produto', 'responsavel', 'aprovadoPor']);
    }

    /** @param array{estado?: string, alerta?: ?string} $o */
    private function imagem(PubAprovacaoOferta $oferta, int $posicao, array $o = []): PubAprovacaoImagem
    {
        $conjunto = PubAprovacaoConjunto::query()
            ->where('rascunho_id', $oferta->rascunho_id)
            ->first()
            ?? PubAprovacaoConjunto::create([
                'rascunho_id' => $oferta->rascunho_id,
                'grupo_chave' => PubAprovacaoConjunto::GERAL,
                'grupo_hash' => ChaveCanonica::hash('GERAL-'.$oferta->rascunho_id),
            ]);

        $crua = PubImagem::create(['rascunho_id' => $oferta->rascunho_id]);

        $imagem = PubAprovacaoImagem::create([
            'conjunto_id' => $conjunto->id,
            'imagem_id' => $crua->id,
            'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
            'estado' => $o['estado'] ?? PubAprovacaoImagem::APROVADA,
            'posicao' => $posicao,
        ]);

        if (($o['alerta'] ?? null) !== null) {
            PubAprovacaoImagemAlerta::create([
                'aprovacao_imagem_id' => $imagem->id,
                'severidade' => $o['alerta'],
                'texto' => 'A quantidade de peças na foto não confere com a do kit.',
            ]);
        }

        return $imagem;
    }

    private function servico(): PainelDeRevisaoService
    {
        return app(PainelDeRevisaoService::class);
    }

    /** O recorte da fila da conta — o MESMO que o controller passa (`paraAConta`). */
    private function base(Company $empresa)
    {
        return app(FilaDeAprovacaoService::class)->paraAConta($empresa->id, null);
    }

    /**
     * Instala o dublê de `DadosEfetivosService` para a composição REAL chegar à tarifa do ML.
     *
     * ⚠️ É isto que torna os casos de dinheiro uma MEDIÇÃO e não uma afirmação: o preço, o custo e
     * o frete entram de verdade, e o `ComposicaoFinanceiraService` da Fase 186 faz a conta.
     *
     * @param  array{preco?: float, custo?: float, frete?: ?float, imposto?: float}  $o
     */
    private function comDadosEfetivos(array $o = []): void
    {
        $duble = new class(app(EstruturaPrecificacaoService::class)) extends DadosEfetivosService
        {
            public float $oPreco = 0.0;

            public float $oCusto = 20.0;

            public ?float $oFrete = 10.0;

            public float $oImposto = 10.0;

            public function custosDoProduto(PubProduto $produto): array
            {
                return ['custo' => $this->oCusto, 'origem' => 'digitado', 'imposto' => $this->oImposto, 'por_variante' => []];
            }

            public function fretesDoProduto(PubProduto $produto): array
            {
                // ⚠️ `null` no valor é o caso do FRETE AUSENTE, que ANULA o resultado.
                return ['por_tipo' => [
                    'gold_special' => ['valor' => $this->oFrete, 'origem' => 'tabela', 'oferta_id' => null],
                    'gold_pro' => ['valor' => $this->oFrete, 'origem' => 'tabela', 'oferta_id' => null],
                ], 'por_variante' => []];
            }

            public function daProduto(PubProduto $produto): array
            {
                return ['precos' => ['gold_special' => $this->oPreco, 'gold_pro' => $this->oPreco]];
            }
        };

        // ⚠️⚠️ **OS DEFAULTS SÃO A CALIBRAÇÃO MEDIDA DA 186**, não números escolhidos à toa:
        // `BloqueiosDaOfertaServiceTest::cenario()` usa `preco 100 · imposto 10` e, sobre essa
        // base, mediu os quatro cenários — `custo 20` = tudo em ordem, `custo 65` = margem abaixo
        // do mínimo (12%), `custo 80` = prejuízo, `frete null` = incompleto. ➡️ Reusar a mesma
        // régua é o que faz estes casos medirem o elo QUE EU QUERO: com `preco 1200 · custo 1000`
        // a primeira redação deste arquivo caía em **prejuízo** quando pedia **margem**, e o gate
        // reprovou dizendo exatamente isso.
        $duble->oPreco = $o['preco'] ?? 100.0;
        $duble->oCusto = $o['custo'] ?? 20.0;
        $duble->oFrete = array_key_exists('frete', $o) ? $o['frete'] : 10.0;
        $duble->oImposto = $o['imposto'] ?? 10.0;

        $this->app->instance(DadosEfetivosService::class, $duble);
    }

    private function comTarifa(string $listingType = self::CLASSICO): void
    {
        $arquivo = base_path('tests/fixtures-ml/sondagem/publico/categorias/'.self::CATEGORIA."/listing_prices_{$listingType}.json");
        $this->assertFileExists($arquivo, 'fixture de tarifa ausente — o gate rodaria sobre zero casos');

        $this->tarifas[self::CATEGORIA.'|'.$listingType] = json_decode((string) file_get_contents($arquivo), true)['resposta'];
    }

    private function raiz(): string
    {
        return rtrim(str_replace('\\', '/', base_path()), '/');
    }

    private function git(string $comando): string
    {
        return trim((string) shell_exec('cd '.escapeshellarg($this->raiz()).' && '.$comando.' 2>&1'));
    }

    /** O código PHP **sem comentário** — `token_get_all`, nunca regex. */
    private function semComentarios(string $relativo): string
    {
        $caminho = $this->raiz().'/'.$relativo;
        $this->assertFileExists($caminho, "arquivo inexistente: {$relativo} — o gate mediria NADA");

        $limpo = '';
        foreach (token_get_all((string) file_get_contents($caminho)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $limpo .= is_array($token) ? $token[1] : $token;
        }

        return $limpo;
    }

    /**
     * Achata o payload em `caminho => valor` escalar, **coletando** o que não é escalar.
     *
     * ⚠️ Existe para que a asserção de "nenhuma data sai como objeto" e a de status do ML sejam
     * **UMA** cada, coletando os infratores — nunca uma por chave, que faria a contagem crescer
     * junto com o contrato (learnings §4b.2).
     *
     * @return array{plano: array<string, mixed>, objetos: list<string>}
     */
    private function achatar(mixed $valor, string $prefixo = ''): array
    {
        $plano = [];
        $objetos = [];

        if (is_array($valor)) {
            foreach ($valor as $k => $v) {
                $filho = $this->achatar($v, $prefixo === '' ? (string) $k : $prefixo.'.'.$k);
                $plano += $filho['plano'];
                $objetos = array_merge($objetos, $filho['objetos']);
            }

            return ['plano' => $plano, 'objetos' => $objetos];
        }

        if (is_object($valor)) {
            $objetos[] = $prefixo.' ('.get_class($valor).')';

            return ['plano' => $plano, 'objetos' => $objetos];
        }

        $plano[$prefixo] = $valor;

        return ['plano' => $plano, 'objetos' => $objetos];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) A IDENTIFICAÇÃO — SKU, formato, origem e os CINCO chips
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ Os três literais são `:1301` — **remedido**: a prosa herdada apontava `:1309`, que é o
     * `onApprove`/`onSave`/`onAdjust`/`onRetry`. Deslocamento de 8 linhas, conferido por `sed`.
     */
    public function test_a_identificacao_sai_com_SKU_formato_origem_e_os_cinco_chips_188_02(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();
        $this->comTarifa(self::PREMIUM);
        $this->comDadosEfetivos();
        $quem = User::factory()->create(['name' => 'Rafaela Guedes']);

        // ─── Unidade: `{sku}` sem sufixo, `Unidade · 1 un. por oferta`, "Clássico" ───
        $unidade = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'sku' => 'PLT-900', 'responsavel' => $quem, 'versao' => 3,
        ]);
        $this->imagem($unidade, 0);
        $vmUnidade = $this->servico()->para($unidade, $this->base($empresa));
        $idUnidade = $vmUnidade['identificacao'];

        $this->assertSame('Poltrona Beny', $idUnidade['produto']);
        $this->assertSame('PLT-900', $idUnidade['sku'], 'com 1 unidade o SKU sai SEM `-K`');
        $this->assertSame('Poltrona Beny Decorativa Veludo', $idUnidade['titulo']);
        $this->assertSame('Unidade · 1 un. por oferta', $idUnidade['chips']['formato'], '`:1301` literal');
        $this->assertSame(PainelDeRevisaoService::ORIGEM_AUTOMATICA, $idUnidade['chips']['origem']);
        $this->assertSame('Preparado automaticamente', $idUnidade['chips']['origem'], '`:1301` literal');
        $this->assertSame('Versão 3', $idUnidade['chips']['versao'], '`:1301` literal — no painel é "Versão N", na LINHA é "vN"');
        $this->assertSame('Rafaela Guedes', $idUnidade['responsavel']);
        $this->assertSame('Clássico', $idUnidade['chips']['modalidade']['rotulo'], 'o rótulo é LIDO de EstruturaAnuncio::TIPOS');
        $this->assertFalse($idUnidade['chips']['modalidade']['premium'], 'o losângo é SÓ do Premium');

        // ⚠️ OS CINCO CHIPS, por igualdade de CONJUNTO de chaves — nunca `count()`, que passa com
        // um chip trocado por outro.
        $this->assertSame(
            ['modalidade', 'formato', 'origem', 'versao', 'andamento'],
            array_keys($idUnidade['chips']),
            'os 5 chips de `:568`-`:572`, nesta ordem',
        );

        // ⚠️⚠️ O CHIP DE ANDAMENTO — este arquivo **EXPÕE** o andamento, e o rótulo é **LIDO** do
        // modelo, nunca recopiado. ⚠️ E o selo exige **TEXTO NÃO-VAZIO**: cor sozinha não é sinal.
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $idUnidade['chips']['andamento']['chave']);
        $this->assertSame(
            PubAprovacaoOferta::ANDAMENTOS[PubAprovacaoOferta::AGUARDANDO_APROVACAO],
            $idUnidade['chips']['andamento']['rotulo'],
            'o rótulo do andamento tem de ser LIDO de ANDAMENTOS',
        );
        $this->assertNotSame('', trim((string) $idUnidade['chips']['andamento']['rotulo']), 'VOC-05: andamento sem TEXTO é estado mudo');

        // ─── Combo: `{sku}-K{N}` e `Combo ×N · N un. por oferta`, "Premium" com losângo ───
        $combo = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'sku' => 'PLT-901', 'kit' => 4, 'tipo' => self::PREMIUM,
        ]);
        $this->imagem($combo, 0);
        $idCombo = $this->servico()->para($combo, $this->base($empresa))['identificacao'];

        $this->assertSame('PLT-901-K4', $idCombo['sku'], '`:1301` — o combo ganha `-K{N}`');
        $this->assertSame('Combo ×4 · 4 un. por oferta', $idCombo['chips']['formato'], '`:1301` literal');
        $this->assertSame('Premium', $idCombo['chips']['modalidade']['rotulo']);
        $this->assertTrue($idCombo['chips']['modalidade']['premium'], 'o losângo do Premium sumiu');

        // ⚠️ ISCA: as palavras "Unidade" e "Combo ×" são **LIDAS** de `GrupoDaFilaService`, e a
        // isca prova que a leitura não está cega. Sem isto, as duas poderiam ser literais soltos.
        $this->assertStringStartsWith(GrupoDaFilaService::UNIDADE, $idUnidade['chips']['formato']);
        $this->assertStringStartsWith(GrupoDaFilaService::PREFIXO_COMBO, $idCombo['chips']['formato']);

        // ─── `origem` manual, e sem SKU **nada** (nunca um `-K2` solto) ───
        $manual = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            // ⚠️ `pub_produtos.sku` é **NOT NULL** no schema (medido: 23000 na primeira rodada),
            // então o caso de "sem SKU" é o SKU **em branco** — que é o que `texto()` apara.
            'sku' => '  ', 'kit' => 2, 'origem' => PubAprovacaoOferta::ORIGEM_MANUAL,
        ]);
        $idManual = $this->servico()->para($manual, $this->base($empresa))['identificacao'];

        $this->assertSame('Cadastro manual', $idManual['chips']['origem'], '`:1301` literal');
        $this->assertNull($idManual['sku'], 'sem SKU não se inventa — e nunca um `-K2` solto');
        // ⚠️ ISCA: com SKU em branco e kit 2, o que NÃO pode sair é um `-K2` órfão.
        $this->assertStringNotContainsString('-K2', (string) $idManual['sku'], 'saiu um `-K2` sem SKU');

        // ⚠️ ISCA DA DISCRIMINAÇÃO: unidade e combo deram formatos DIFERENTES. Sem esta linha, as
        // duas pontas poderiam estar medindo o mesmo texto.
        $this->assertNotSame($idUnidade['chips']['formato'], $idCombo['chips']['formato'], 'a fixture não discrimina unidade de combo');
        $this->assertNotSame($idUnidade['chips']['origem'], $idManual['chips']['origem'], 'a fixture não discrimina auto de manual');
    }

    /**
     * A espera e a caixa de falha — **formatadas no SERVIDOR** (COL-06).
     *
     * ⚠️ `Carbon::setTestNow()` está no `setUp()`: sem o agora PARADO, a espera vira dependente da
     * hora do dia (foi o que deixou duas falhas herdadas nesta suíte).
     */
    public function test_a_espera_e_a_falha_vem_do_servidor_formatadas_188_02(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();
        $this->comDadosEfetivos();

        // 3 h antes do agora parado → "3 h", abreviado DE PROPÓSITO.
        $tresHoras = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['espera' => '2026-10-19 06:00:00']);
        $espera = $this->servico()->para($tresHoras, $this->base($empresa))['identificacao']['espera'];

        $this->assertSame('aguardando há 3 h', $espera, '`:1302` — o prefixo é LIDO da 187-05');
        $this->assertStringStartsWith(ProximoARevisarService::PREFIXO_ESPERA, (string) $espera, 'o prefixo foi recopiado em vez de lido');
        $this->assertIsString($espera, 'COL-06: a espera sai STRING pronta, nunca um Carbon');

        // ⚠️⚠️ **DECISÃO DO USUÁRIO (16/10/2026): "dia"/"dias" POR EXTENSO**, contra o `' d'` do
        // protótipo (`:1054`) — a PRIMEIRA derrota dele na milestone, e por conflito entre DUAS
        // regras do projeto. ⚠️ `min` e `h` FICAM abreviados, de propósito.
        $tresDias = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['espera' => '2026-10-16 09:00:00']);
        $esperaDias = $this->servico()->para($tresDias, $this->base($empresa))['identificacao']['espera'];
        $this->assertSame('aguardando há 3 dias', $esperaDias);
        $this->assertStringNotContainsString(' d ', (string) $esperaDias, 'o `d` abreviado voltou — é a decisão do usuário que cai');

        // ⚠️ Espera DESCONHECIDA é `null`, nunca "0 min": zero afirmaria "entrou agora".
        $semCarimbo = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['espera' => null]);
        $this->assertNull($this->servico()->para($semCarimbo, $this->base($empresa))['identificacao']['espera']);

        // ─── A CAIXA DE FALHA: só com andamento de falha, e com o erro REAL (PUB-05) ───
        $falhou = $this->oferta($empresa, PubAprovacaoOferta::FALHA, [
            'erro' => 'item.attributes: BRAND is required', 'versao_aprovada' => 2, 'versao' => 2,
        ]);
        $vmFalha = $this->servico()->para($falhou, $this->base($empresa));

        $this->assertIsArray($vmFalha['falha']);
        $this->assertSame('item.attributes: BRAND is required', $vmFalha['falha']['mensagem'], 'PUB-05: a mensagem é o erro REAL do ML');
        $this->assertSame('Tentar publicar novamente', $vmFalha['falha']['rotulo_acao'], '`:582` literal');
        $this->assertSame(PainelDeRevisaoService::FALHA_ROTULO_ACAO, $vmFalha['falha']['rotulo_acao']);

        // ⚠️ ISCA: fora da falha a caixa é `null` — ela não pode aparecer em oferta sadia.
        $this->assertNull($this->servico()->para($tresHoras, $this->base($empresa))['falha'], 'a caixa de falha apareceu fora da falha');

        // ⚠️ Sem erro gravado a caixa CONTINUA, com `mensagem` null: a falha É o fato, e esconder
        // a caixa esconderia trabalho.
        $semErro = $this->oferta($empresa, PubAprovacaoOferta::FALHA, ['erro' => null]);
        $vmSemErro = $this->servico()->para($semErro, $this->base($empresa));
        $this->assertIsArray($vmSemErro['falha'], 'a caixa de falha sumiu por falta de mensagem');
        $this->assertNull($vmSemErro['falha']['mensagem']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) AS PENDÊNCIAS E OS **NOVE DESTINOS**
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **OS NOVE DESTINOS**, um por chave de `BLOQUEIOS_EM_ORDEM` — incluindo os **dois `null`
     * da PERGUNTA-188-B** e o `null` do `em_preparacao`.
     */
    public function test_os_NOVE_destinos_de_correcao_estao_resolvidos_no_servidor_188_02(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();
        $quem = User::factory()->create();

        // ⚠️ O destino de CADA uma das nove é COLETADO, para a equivalência do fim deste caso.
        $destinos = [];

        $destinoDe = function (PubAprovacaoOferta $oferta, string $chave) use ($empresa, &$destinos): ?array {
            foreach ($this->servico()->para($oferta, $this->base($empresa))['pendencias'] as $p) {
                // ⚠️ Flag DENTRO do laço.
                if ($p['chave'] === $chave) {
                    $destinos[$chave] = $p['destino'];

                    return $p['destino'];
                }
            }

            return ['NAO-ACHOU-A-PENDENCIA' => $chave];
        };

        // ─── (2) `fotos_a_conferir` → a primeira COM ALERTA, não a primeira pendente (`:1172`) ──
        $this->comDadosEfetivos();
        $comFotos = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $primeiraPendente = $this->imagem($comFotos, 0, ['estado' => PubAprovacaoImagem::PENDENTE]);
        $comAlerta = $this->imagem($comFotos, 1, ['estado' => PubAprovacaoImagem::PENDENTE, 'alerta' => PubAprovacaoImagemAlerta::ALTO]);

        $this->assertSame(
            ['tipo' => 'foto', 'imagem_id' => $comAlerta->id],
            $destinoDe($comFotos, VocabularioDoPortao::FOTOS_A_CONFERIR),
            '`:1172` — `inf.alerts[0] || inf.pend[0]`: a COM ALERTA vem primeiro',
        );
        // ⚠️ ISCA: a primeira pendente NÃO é o foco. Sem esta linha, um serviço que ignorasse o
        // alerta passaria — e quem tem alerta automático é quem precisa de olho humano primeiro.
        $this->assertNotSame($primeiraPendente->id, $comAlerta->id, 'a fixture não discrimina pendente de com-alerta');
        $this->assertNotSame(
            ['tipo' => 'foto', 'imagem_id' => $primeiraPendente->id],
            $destinoDe($comFotos, VocabularioDoPortao::FOTOS_A_CONFERIR),
        );

        // ─── (3) `sem_fotos` → a coluna de fotos, com `imagem_id` NULL (não há imagem) ──────────
        $semFotos = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->assertSame(
            ['tipo' => 'foto', 'imagem_id' => null],
            $destinoDe($semFotos, VocabularioDoPortao::SEM_FOTOS),
            'IMG-12: a coluna abre e DIZ que está vazia — nunca painel vazio',
        );

        // ─── (4) `frete_ausente` → o modal de FRETE ───────────────────────────────────────────
        $this->comDadosEfetivos(['frete' => null]);
        $semFrete = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($semFrete, 0);
        $this->assertSame(
            ['tipo' => 'modal', 'modal' => 'frete'],
            $destinoDe($semFrete, VocabularioDoPortao::FRETE_AUSENTE),
        );

        // ─── (5) `resultado_negativo` → o modal de EXCEÇÃO, com a CHAVE ───────────────────────
        $this->comDadosEfetivos(['custo' => 80.0]);
        $prejuizo = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($prejuizo, 0);
        $this->assertSame(
            ['tipo' => 'modal', 'modal' => 'excecao', 'excecao' => VocabularioDoPortao::RESULTADO_NEGATIVO],
            $destinoDe($prejuizo, VocabularioDoPortao::RESULTADO_NEGATIVO),
        );

        // ─── (6) `margem_abaixo_do_minimo` → o mesmo modal, com a OUTRA chave ─────────────────
        $this->comDadosEfetivos(['custo' => 65.0]);
        $margemBaixa = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($margemBaixa, 0);
        $this->assertSame(
            ['tipo' => 'modal', 'modal' => 'excecao', 'excecao' => VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO],
            $destinoDe($margemBaixa, VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO),
        );

        // ─── (7) `alteracao_nao_conferida` → a AÇÃO, que **confere SEM alterar o preço** ───────
        $this->comDadosEfetivos();
        $alterada = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'preco_alterado' => ['de' => 1200.0, 'para' => 1100.0, 'quem' => $quem->id, 'quando' => '2026-10-19 08:00:00'],
        ]);
        $this->imagem($alterada, 0);
        $this->assertSame(
            ['tipo' => 'acao', 'acao' => 'conferir_alteracao'],
            $destinoDe($alterada, VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA),
            '`:1176` — `checkChange(o.id)` confere SEM alterar o preço',
        );

        // ─── (8) `ajuste_em_aberto` → **`null`** (PERGUNTA-188-B) ─────────────────────────────
        $comAjuste = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($comAjuste, 0);
        PubAprovacaoAjuste::create([
            'escopo' => PubAprovacaoAjuste::ESCOPO_OFERTA, 'oferta_id' => $comAjuste->id,
            'item' => 'Título', 'destino' => 'Este anúncio (Clássico)', 'texto' => 'Trocar a cor do fundo', 'autor_id' => $quem->id,
        ]);
        $pendenciasDoAjuste = $this->servico()->para($comAjuste, $this->base($empresa))['pendencias'];
        $doAjuste = array_values(array_filter($pendenciasDoAjuste, static fn (array $p): bool => $p['chave'] === VocabularioDoPortao::AJUSTE_EM_ABERTO));

        $this->assertCount(1, $doAjuste, 'a pendência de ajuste não nasceu — o caso mediria NADA');
        $this->assertNull($doAjuste[0]['destino'], 'PERGUNTA-188-B: sem rota, SEM destino');
        $this->assertNull($doAjuste[0]['correcao'], 'PERGUNTA-188-B: sem destino, SEM botão');
        // ⚠️⚠️ **MAS O TEXTO CONTINUA APARECENDO** — a pessoa precisa saber o que trava a oferta.
        $destinos[VocabularioDoPortao::AJUSTE_EM_ABERTO] = $doAjuste[0]['destino'];
        $this->assertSame('1 ajuste solicitado em aberto', $doAjuste[0]['texto'], 'o TEXTO do bloqueio sumiu junto com o botão');
        $this->assertSame(VocabularioDoPortao::GLIFO, $doAjuste[0]['glifo'], 'VOC-05: ícone + texto, nunca só cor');

        // ─── (9) `titulo_longo` → **`null`** (PERGUNTA-188-B) ─────────────────────────────────
        $tituloLongo = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'titulo' => str_repeat('A', BloqueiosDaOfertaService::TITULO_MAXIMO + 1),
        ]);
        $this->imagem($tituloLongo, 0);
        $doTitulo = array_values(array_filter(
            $this->servico()->para($tituloLongo, $this->base($empresa))['pendencias'],
            static fn (array $p): bool => $p['chave'] === VocabularioDoPortao::TITULO_LONGO,
        ));

        $this->assertCount(1, $doTitulo, 'a pendência de título não nasceu — o caso mediria NADA');
        $this->assertNull($doTitulo[0]['destino']);
        $this->assertNull($doTitulo[0]['correcao']);
        $destinos[VocabularioDoPortao::TITULO_LONGO] = $doTitulo[0]['destino'];
        $this->assertStringContainsString('61 caracteres', $doTitulo[0]['texto'], 'o TEXTO do bloqueio sumiu junto com o botão');

        // ─── (1) `em_preparacao` → **`null`** nos dois campos (`:1089`, `fix: ''`) ────────────
        $preparando = $this->oferta($empresa, PubAprovacaoOferta::PREPARANDO, ['preparo' => 'gerando as fotos']);
        $doPreparo = array_values(array_filter(
            $this->servico()->para($preparando, $this->base($empresa))['pendencias'],
            static fn (array $p): bool => $p['chave'] === VocabularioDoPortao::EM_PREPARACAO,
        ));

        $this->assertCount(1, $doPreparo);
        $this->assertNull($doPreparo[0]['destino'], '`:1089` — não há nada que a pessoa possa fazer');
        $this->assertNull($doPreparo[0]['correcao']);
        $destinos[VocabularioDoPortao::EM_PREPARACAO] = $doPreparo[0]['destino'];
        $this->assertSame('Sistema ainda gerando as fotos', $doPreparo[0]['texto'], 'AUTO-01: a frase REAL do que falta');

        // ⚠️⚠️ **A LISTA CONSTANTE da PERGUNTA-188-B, e ela é quem a Fase 190/191 esvazia.**
        // ⚠️ Lista CONSTANTE, nunca `count()` — piso não prova presença (learnings §4b.2).
        $this->assertSame(
            [VocabularioDoPortao::AJUSTE_EM_ABERTO, VocabularioDoPortao::TITULO_LONGO],
            PainelDeRevisaoService::SEM_ROTA_ATE_A_FASE_190,
            'a lista dos dois sem rota mudou — PERGUNTA-188-B é do usuário/Fase 190-191',
        );

        // ⚠️ E a cobertura das NOVE chaves é conferida por CONJUNTO, nunca por contagem: os nove
        // destinos deste caso cobrem `BLOQUEIOS_EM_ORDEM` inteira.
        $this->assertSame(
            ['em_preparacao', 'fotos_a_conferir', 'sem_fotos', 'frete_ausente', 'resultado_negativo',
                'margem_abaixo_do_minimo', 'alteracao_nao_conferida', 'ajuste_em_aberto', 'titulo_longo'],
            VocabularioDoPortao::BLOQUEIOS_EM_ORDEM,
            'as 9 chaves mudaram — este caso cobre uma por uma e precisa ser revisto',
        );
        // ⚠️ E as NOVE foram de fato MEDIDAS aqui, não quatro delas.
        $this->assertSame([], array_values(array_diff(VocabularioDoPortao::BLOQUEIOS_EM_ORDEM, array_keys($destinos))), 'chave dos 9 sem destino MEDIDO neste caso');

        // ═══ ⚠️⚠️ A EQUIVALÊNCIA QUE TORNA `SEM_ROTA_ATE_A_FASE_190` LOAD-BEARING ════════════
        //
        // ⚠️⚠️ **ESTE BLOCO NASCEU DE UMA SOBREVIVENTE DA MUTAÇÃO, e a investigação achou GAP
        // REAL.** Esvaziar `SEM_ROTA_ATE_A_FASE_190` **não reprovava nada**: sem o `return null`
        // antecipado, `destino()` cai no `match`, que **não tem arm** para `ajuste_em_aberto` nem
        // `titulo_longo` — então o `default => null` devolvia o mesmo `null`. A mutação era
        // **semanticamente equivalente**, e a decisão do usuário na PERGUNTA-188-B estava protegida
        // **só pela ausência acidental de dois arms**.
        //
        // ⚠️ É a régua 6 em estado puro: *"asserção vazia quando escrita vira FALSO POSITIVO
        // depois"*. No dia em que a Fase 190/191 acrescentar o arm da revisão detalhada e esquecer
        // de esvaziar a lista — ou o contrário —, nada acusaria.
        //
        // ➡️ O conserto fecha o laço na OUTRA direção: **ter destino ⟺ NÃO estar na lista dos sem
        // rota e NÃO ser `em_preparacao`**. Com a lista vazia, as duas passam a DEVER ter destino,
        // não têm, e a mutação **MORRE**. ⚠️ UMA asserção coletando os divergentes.
        $semDestinoPorDesenho = array_merge(
            PainelDeRevisaoService::SEM_ROTA_ATE_A_FASE_190,
            // `em_preparacao` tem `fix: ''` (`:1089`): não há nada a fazer, e é por OUTRA razão.
            [VocabularioDoPortao::EM_PREPARACAO],
        );
        $divergentes = [];
        foreach (VocabularioDoPortao::BLOQUEIOS_EM_ORDEM as $chave) {
            // ⚠️ Flag DENTRO do laço.
            $deveTer = ! in_array($chave, $semDestinoPorDesenho, true);
            $tem = ($destinos[$chave] ?? null) !== null;
            if ($deveTer !== $tem) {
                $divergentes[] = $chave.($deveTer ? ' deveria TER destino e não tem' : ' tem destino e NÃO deveria');
            }
        }
        $this->assertSame([], $divergentes, 'a equivalência destino ⟺ fora da lista dos sem-rota quebrou');

        // ⚠️ ISCA DA EQUIVALÊNCIA, nas duas direções — sem ela, "[] divergentes" poderia ser o
        // valor de um laço que nunca compara nada.
        $this->assertCount(3, $semDestinoPorDesenho, 'a lista dos sem-destino mudou de tamanho');
        $this->assertTrue(in_array(VocabularioDoPortao::AJUSTE_EM_ABERTO, $semDestinoPorDesenho, true));
        $this->assertFalse(in_array(VocabularioDoPortao::FRETE_AUSENTE, $semDestinoPorDesenho, true), 'o frete entrou na lista dos sem-destino');
        $this->assertNotNull($destinos[VocabularioDoPortao::FRETE_AUSENTE], 'ISCA: quem está FORA da lista tem de ter destino');
        $this->assertNull($destinos[VocabularioDoPortao::AJUSTE_EM_ABERTO], 'ISCA: quem está DENTRO da lista não pode ter destino');
    }

    /**
     * A `correcao` é `null` em **TODAS** fora do `live`, e a ORDEM é a do vocabulário.
     *
     * ⚠️ `hasFix = !!b.fix && inf.live` (**`:1295`** — a prosa herdada dizia `:1304`, que é o
     * `shared`/`safeCount`; deslocamento de 9 linhas, remedido por `sed`).
     */
    public function test_a_correcao_e_nula_em_TODAS_fora_do_live_e_a_ordem_e_a_do_vocabulario_188_02(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();
        $quem = User::factory()->create();

        // ⚠️ Um cenário com VÁRIOS bloqueios ao mesmo tempo: fotos pendentes **com alerta**,
        // prejuízo, alteração de preço, ajuste aberto e título longo. Com UM bloqueio só a ordem
        // não se distingue de nada — foi a falha de fixture das duas sobreviventes da 187-06.
        $montar = function (string $andamento) use ($empresa, $quem): PubAprovacaoOferta {
            $this->comDadosEfetivos(['custo' => 80.0]);
            $oferta = $this->oferta($empresa, $andamento, [
                'titulo' => str_repeat('B', BloqueiosDaOfertaService::TITULO_MAXIMO + 5),
                'preco_alterado' => ['de' => 120.0, 'para' => 100.0, 'quem' => $quem->id, 'quando' => '2026-10-19 08:00:00'],
                'versao' => 4, 'versao_aprovada' => 4, 'aprovador' => $quem,
            ]);
            $this->imagem($oferta, 0, ['estado' => PubAprovacaoImagem::PENDENTE, 'alerta' => PubAprovacaoImagemAlerta::ALTO]);
            PubAprovacaoAjuste::create([
                'escopo' => PubAprovacaoAjuste::ESCOPO_OFERTA, 'oferta_id' => $oferta->id,
                'item' => 'Custo', 'destino' => 'Este anúncio (Clássico)', 'texto' => 'Conferir o custo', 'autor_id' => $quem->id,
            ]);

            return $oferta;
        };

        // ─── AO VIVO: a ordem é a de `BLOQUEIOS_EM_ORDEM`, e há correção nas que têm rota ──────
        //
        // ⚠️⚠️ **A OFERTA É MONTADA NUMA VARIÁVEL, E SÓ ENTÃO O SERVIÇO NASCE.** Isto NÃO é estilo:
        // **o PHP avalia o RECEPTOR antes do ARGUMENTO**, então
        // `$this->servico()->para($montar(...), …)` construiria o serviço **ANTES** de `$montar()`
        // instalar o dublê — e ele nasceria com o `DadosEfetivosService` **REAL**. ⚠️ É o **falso
        // negativo silencioso medido no plano 186-07**, e a primeira redação deste arquivo caiu
        // nele: o cenário ao vivo vinha com `frete_ausente` e o fora-do-live com
        // `resultado_negativo`, com a fixture pedindo os MESMOS bloqueios nos dois.
        $ofertaAoVivo = $montar(PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $aoVivo = $this->servico()->para($ofertaAoVivo, $this->base($empresa))['pendencias'];
        $chavesAoVivo = array_column($aoVivo, 'chave');

        $this->assertGreaterThanOrEqual(4, count($chavesAoVivo), 'a fixture não produziu bloqueios suficientes para a ORDEM se distinguir');

        // ⚠️⚠️ A ORDEM, não só a presença: o produzido é **SUBSEQUÊNCIA** de `BLOQUEIOS_EM_ORDEM`.
        // `BLOQUEIOS_EM_ORDEM` é a autoridade, e é **o PRIMEIRO** que o rodapé abrevia (BLOQ-12).
        $ordenadoPelaAutoridade = array_values(array_filter(
            VocabularioDoPortao::BLOQUEIOS_EM_ORDEM,
            static fn (string $c): bool => in_array($c, $chavesAoVivo, true),
        ));
        $this->assertSame($ordenadoPelaAutoridade, $chavesAoVivo, 'a ordem das pendências não é a de BLOQUEIOS_EM_ORDEM');

        // ⚠️ ISCA DA SONDA DE ORDEM: ela PEGA uma permutação. Sem esta linha, um `assertSame` sobre
        // uma lista reordenada pelos dois lados passaria sempre.
        $trocado = $chavesAoVivo;
        [$trocado[0], $trocado[1]] = [$trocado[1], $trocado[0]];
        $this->assertNotSame($ordenadoPelaAutoridade, $trocado, 'a sonda de ordem não discrimina permutação');

        // ⚠️ E AO VIVO há correção nas que TÊM rota (`fotos_a_conferir`, `resultado_negativo`,
        // `alteracao_nao_conferida`) — gate de ausência rodado com dado VAZIO é vazio por
        // construção, então a ponta POSITIVA é obrigatória.
        $comCorrecaoAoVivo = array_values(array_filter($aoVivo, static fn (array $p): bool => $p['correcao'] !== null));
        $this->assertNotSame([], $comCorrecaoAoVivo, 'nenhuma correção ao vivo: o gate do `null` seria vazio por construção');
        $this->assertNotSame([], array_values(array_filter($comCorrecaoAoVivo, static fn (array $p): bool => $p['destino'] !== null)));

        // ─── FORA DO LIVE: **TODAS** com `correcao` null e `destino` null ─────────────────────
        $ofertaForaDoLive = $montar(PubAprovacaoOferta::APROVADO);
        $foraDoLive = $this->servico()->para($ofertaForaDoLive, $this->base($empresa))['pendencias'];

        $this->assertNotSame([], $foraDoLive, 'a oferta fora do live não produziu pendência — o gate mediria NADA');
        // ⚠️ UMA asserção coletando os infratores, nunca uma por pendência (a contagem não pode
        // crescer com o número de bloqueios — learnings §4b.2).
        $this->assertSame(
            [],
            array_values(array_map(
                static fn (array $p): string => $p['chave'],
                array_filter($foraDoLive, static fn (array $p): bool => $p['correcao'] !== null || $p['destino'] !== null),
            )),
            '`:1295` — fora do `live` NENHUM botão de correção aparece',
        );

        // ⚠️⚠️ A ISCA QUE SEPARA AS DUAS PONTAS: os dois cenários são IDÊNTICOS menos o andamento,
        // e deram resultados DIFERENTES. Sem esta linha, as duas poderiam medir o mesmo.
        $this->assertSame(array_column($aoVivo, 'chave'), array_column($foraDoLive, 'chave'), 'os dois cenários deveriam ter os MESMOS bloqueios');
        $this->assertNotSame(array_column($aoVivo, 'correcao'), array_column($foraDoLive, 'correcao'), 'a fixture não discrimina live de fora-do-live');

        // ⚠️ E a SEVERIDADE do ramo fácil de omitir: `fotos_a_conferir` é **VERMELHA com alerta**
        // (`:1295`), e a lista fixa dos sempre-vermelhos é LIDA do vocabulário.
        $dasFotos = array_values(array_filter($aoVivo, static fn (array $p): bool => $p['chave'] === VocabularioDoPortao::FOTOS_A_CONFERIR));
        $this->assertCount(1, $dasFotos);
        $this->assertSame(VocabularioDoPortao::SEVERIDADE_VERMELHA, $dasFotos[0]['severidade'], '`fotos_a_conferir` COM alerta é vermelha');
        $this->assertNotContains(VocabularioDoPortao::FOTOS_A_CONFERIR, VocabularioDoPortao::VERMELHOS, 'ela não é vermelha SEMPRE — depende do alerta');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) O RODAPÉ
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ `aprovavel` é **LIDO**, e o motivo **CHAMA** `resumoDoRodape()` — a divergência contra o
     * `join(' · ')` de `:1297` está declarada no docblock do serviço e na SUMMARY.
     */
    public function test_o_rodape_le_o_aprovavel_e_o_resumo_do_rodape_188_02(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();

        // ─── Aprovável: zero bloqueio e `live` → o literal VERDE ──────────────────────────────
        $this->comDadosEfetivos();
        $limpa = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($limpa, 0);
        $vmLimpa = $this->servico()->para($limpa, $this->base($empresa));

        $leitura = app(BloqueiosDaOfertaService::class)->para($limpa);
        $this->assertTrue($leitura['aprovavel'], 'a fixture aprovável não ficou aprovável — o caso mediria NADA');
        // ⚠️ **LIDO, nunca recalculado**: o veredicto do rodapé é o MESMO objeto do serviço da 186.
        $this->assertSame($leitura['aprovavel'], $vmLimpa['rodape']['aprovavel'], 'BLOQ-04: `aprovavel` tem de ser LIDO de BloqueiosDaOfertaService');
        $this->assertSame('Fotos, preço e conteúdo conferidos', $vmLimpa['rodape']['motivo'], '`:1297` literal, com a vírgula de Oxford');
        $this->assertSame(VocabularioDoPortao::TUDO_CONFERIDO, $vmLimpa['rodape']['motivo'], 'o literal tem de ser LIDO do vocabulário');
        $this->assertSame(PainelDeRevisaoService::COR_VERDE, $vmLimpa['rodape']['motivo_cor']);
        $this->assertSame([], $vmLimpa['pendencias'], 'oferta aprovável não tem pendência');

        // ─── Com DOIS bloqueios: o PRIMEIRO abreviado + ' +1' (os DEMAIS, não o total) ─────────
        $this->comDadosEfetivos(['custo' => 80.0]);
        $doisBloqueios = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($doisBloqueios, 0, ['estado' => PubAprovacaoImagem::PENDENTE]);
        $vmDois = $this->servico()->para($doisBloqueios, $this->base($empresa));

        $this->assertCount(2, $vmDois['pendencias'], 'a fixture de DOIS bloqueios não produziu dois');
        $this->assertFalse($vmDois['rodape']['aprovavel']);
        $this->assertSame(PainelDeRevisaoService::COR_VERMELHA, $vmDois['rodape']['motivo_cor']);

        // ⚠️⚠️ **CHAMADO, não remontado** — a frase do painel é IDÊNTICA à que a fila mostra.
        $this->assertSame(
            app(BloqueiosDaOfertaService::class)->resumoDoRodape($vmDois['pendencias']),
            $vmDois['rodape']['motivo'],
            'BLOQ-12: o motivo tem de vir de `resumoDoRodape()`, nunca remontado',
        );
        $this->assertSame('Bloqueado: fotos a conferir +1', $vmDois['rodape']['motivo'], 'o `+N` conta os DEMAIS, não o total');

        // ⚠️⚠️ **A DIVERGÊNCIA DECLARADA CONTRA `:1297`**: o protótipo junta **TODAS** as
        // abreviações por `' · '`; o produto mostra o **PRIMEIRO** + ` +N`. O produto GANHA — é o
        // critério 10 do ROADMAP, e recopiar o `join` daria a SEGUNDA verdade da mesma frase.
        $todasJuntas = VocabularioDoPortao::BLOQUEADO_PREFIXO.implode(' · ', array_map(
            static fn (array $p): string => (string) VocabularioDoPortao::abreviacaoDe($p['chave']),
            $vmDois['pendencias'],
        ));
        $this->assertSame('Bloqueado: fotos a conferir · resultado negativo', $todasJuntas, 'a reconstrução do `join` do protótipo mudou — a divergência precisa ser remedida');
        $this->assertNotSame($todasJuntas, $vmDois['rodape']['motivo'], 'o `join` de `:1297` foi RECOPIADO — é a segunda verdade da mesma frase');

        // ─── Os BOTÕES: `salvar`/`ajustes`/`aprovar` só com `ao_vivo`; `proximo` SEMPRE ────────
        $this->assertSame(
            ['salvar' => true, 'ajustes' => true, 'aprovar' => true, 'proximo' => true],
            $vmDois['rodape']['botoes'],
            '`:713`/`:718` — ao vivo os quatro existem',
        );

        // ⚠️⚠️ `botoes.aprovar` e `aprovavel` são coisas DIFERENTES: "o botão existe" × "ele está
        // habilitado". Fundi-los faria oferta publicada mostrar um botão amarelo.
        $this->assertTrue($vmDois['rodape']['botoes']['aprovar'], 'o botão EXISTE');
        $this->assertFalse($vmDois['rodape']['aprovavel'], 'e está DESABILITADO — são campos distintos');

        // ⚠️ E não existe segundo passo nem desfazer em chave nenhuma do payload.
        $plano = $this->achatar($vmDois)['plano'];
        $texto = mb_strtolower(implode(' ', array_merge(array_keys($plano), array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $plano))));
        foreach (['confirmar publica', 'desfazer', 'cancelar aprova', 'segundo passo'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $texto, 'APROVAR JÁ PUBLICA: nada de segundo passo');
        }
        // ⚠️ ISCA: a sonda PEGA. Sem isto, "não achei" seria indistinguível de sonda cega.
        $this->assertStringContainsString('desfazer', mb_strtolower('Clique em Desfazer'), 'a sonda de segundo passo está cega');
    }

    /** Os **5** textos do motivo fora do `live` (`:1297`), na ordem do ternário, e os botões. */
    public function test_o_motivo_fora_do_live_tem_os_CINCO_textos_e_os_botoes_somem_188_02(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();
        $this->comDadosEfetivos();
        $quem = User::factory()->create(['name' => 'Rafaela Guedes']);

        $motivoDe = function (string $andamento, array $extra = []) use ($empresa): array {
            $oferta = $this->oferta($empresa, $andamento, $extra);
            $this->imagem($oferta, 0);

            return $this->servico()->para($oferta, $this->base($empresa))['rodape'];
        };

        // (1) `aprovado`
        $aprovado = $motivoDe(PubAprovacaoOferta::APROVADO, ['versao' => 5, 'versao_aprovada' => 5, 'aprovador' => $quem]);
        $this->assertSame('Aprovado na versão 5 por Rafaela Guedes · aguardando publicação', $aprovado['motivo']);

        // (2) `publicando`
        $this->assertSame('Publicando no Mercado Livre · edição bloqueada', $motivoDe(PubAprovacaoOferta::PUBLICANDO)['motivo']);

        // (3) `publicado`
        $this->assertSame('Publicado e confirmado · MLB3456789012', $motivoDe(PubAprovacaoOferta::PUBLICADO, ['mlb' => 'MLB3456789012'])['motivo']);

        // (4) `falha`
        $this->assertSame(
            'Falha na publicação · versão aprovada 7 preservada',
            $motivoDe(PubAprovacaoOferta::FALHA, ['versao' => 7, 'versao_aprovada' => 7])['motivo'],
        );

        // (5) o ramo `senão`
        $preparando = $motivoDe(PubAprovacaoOferta::PREPARANDO, ['preparo' => 'montando a ficha']);
        $this->assertSame('Em preparação', $preparando['motivo']);

        // ⚠️ Os CINCO são DIFERENTES entre si: um ternário colapsado daria o mesmo texto em dois
        // ramos, e ninguém veria.
        $cinco = [$aprovado['motivo'], $motivoDe(PubAprovacaoOferta::PUBLICANDO)['motivo'],
            $motivoDe(PubAprovacaoOferta::PUBLICADO, ['mlb' => 'MLB1'])['motivo'],
            $motivoDe(PubAprovacaoOferta::FALHA, ['versao_aprovada' => 1])['motivo'], $preparando['motivo']];
        $this->assertCount(5, array_unique($cinco), 'dois ramos do ternário deram o MESMO texto');

        // ⚠️ A cor fora do `live` é **a do ANDAMENTO** (`:1308`, `STM[o.status][1]`), nunca hex no
        // PHP (TOK-15): o `content` do Tailwind não varre `.php`.
        foreach ($cinco as $i => $_) {
            $this->assertNotSame('', $cinco[$i]);
        }
        $this->assertSame(PainelDeRevisaoService::COR_DO_ANDAMENTO, $aprovado['motivo_cor']);
        $this->assertSame(PainelDeRevisaoService::COR_DO_ANDAMENTO, $preparando['motivo_cor']);

        // ⚠️⚠️ **FORA DO `live` OS TRÊS BOTÕES SOMEM, e "Próximo ↓" FICA** (`:721`, fora dos
        // `sc-if`): a pessoa tem de poder seguir a fila mesmo numa oferta que não dá para mexer.
        foreach ([PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::PUBLICADO,
            PubAprovacaoOferta::FALHA, PubAprovacaoOferta::PREPARANDO] as $andamento) {
            $botoes = $motivoDe($andamento, ['mlb' => 'MLB9', 'versao_aprovada' => 1])['botoes'];
            $this->assertSame(
                ['salvar' => false, 'ajustes' => false, 'aprovar' => false, 'proximo' => true],
                $botoes,
                "fora do live os três somem e `proximo` fica — falhou em {$andamento}",
            );
        }

        // ⚠️ Sem nome de aprovador o segmento ` por {nome}` é OMITIDO — não se inventa responsável.
        $semNome = $motivoDe(PubAprovacaoOferta::APROVADO, ['versao' => 2, 'versao_aprovada' => 2]);
        $this->assertSame('Aprovado na versão 2 · aguardando publicação', $semNome['motivo']);
        $this->assertStringNotContainsString(' por ', $semNome['motivo'], 'inventou responsável onde não havia nome');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (4) A CADEIA EXCLUSIVA E O PAR EMBALAGEM × FRETE
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * A cadeia `frete → prejuízo → margem` é **EXCLUSIVA** (`:1092`-`:1094`), e a **EMBALAGEM** não
     * anula enquanto o **FRETE** anula.
     */
    public function test_a_cadeia_e_EXCLUSIVA_e_a_embalagem_nao_anula_mas_o_frete_anula_188_02(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();

        // ⚠️ A lista é LIDA do modelo, e a prova de que embalagem está FORA dela.
        $this->assertNotContains('embalagem', PubAprovacaoComposicao::ANULAM_O_RESULTADO, 'embalagem entrou na lista que ANULA');
        $this->assertContains('frete', PubAprovacaoComposicao::ANULAM_O_RESULTADO, 'frete saiu da lista que ANULA');
        $this->assertCount(5, PubAprovacaoComposicao::ANULAM_O_RESULTADO, 'são CINCO linhas que anulam');
        $this->assertSame(
            [VocabularioDoPortao::FRETE_AUSENTE, VocabularioDoPortao::RESULTADO_NEGATIVO, VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO],
            VocabularioDoPortao::CADEIA_EXCLUSIVA,
            'a cadeia é LIDA do vocabulário, nesta ordem',
        );

        $chavesCom = function (array $dados) use ($empresa): array {
            $this->comDadosEfetivos($dados);
            $oferta = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
            $this->imagem($oferta, 0);

            return array_column($this->servico()->para($oferta, $this->base($empresa))['pendencias'], 'chave');
        };

        // ─── A ponta A: FRETE ausente → **não existe** prejuízo nem margem ────────────────────
        $semFrete = $chavesCom(['custo' => 80.0, 'frete' => null]);
        $this->assertContains(VocabularioDoPortao::FRETE_AUSENTE, $semFrete);
        $this->assertNotContains(VocabularioDoPortao::RESULTADO_NEGATIVO, $semFrete, 'faltando dado NÃO existe resultado para julgar');
        $this->assertNotContains(VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO, $semFrete);

        // ─── A ponta B (ISCA): frete PRESENTE e resultado < 0 → existe prejuízo e **não** margem ─
        $comPrejuizo = $chavesCom(['custo' => 80.0]);
        $this->assertNotContains(VocabularioDoPortao::FRETE_AUSENTE, $comPrejuizo);
        $this->assertContains(VocabularioDoPortao::RESULTADO_NEGATIVO, $comPrejuizo);
        $this->assertNotContains(VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO, $comPrejuizo, 'resultado negativo é SEMPRE margem abaixo — o `elseif` caiu');

        // ─── A ponta C: margem baixa SEM prejuízo → só margem ─────────────────────────────────
        $soMargem = $chavesCom(['custo' => 65.0]);
        $this->assertNotContains(VocabularioDoPortao::FRETE_AUSENTE, $soMargem);
        $this->assertNotContains(VocabularioDoPortao::RESULTADO_NEGATIVO, $soMargem);
        $this->assertContains(VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO, $soMargem);

        // ⚠️⚠️ **NO MÁXIMO UM dos três por oferta** — a asserção que mata três `if` independentes.
        foreach ([$semFrete, $comPrejuizo, $soMargem] as $i => $chaves) {
            $daCadeia = array_values(array_intersect(VocabularioDoPortao::CADEIA_EXCLUSIVA, $chaves));
            $this->assertCount(1, $daCadeia, "a cadeia deixou de ser EXCLUSIVA no cenário {$i}: ".implode(',', $daCadeia));
        }

        // ⚠️⚠️ A EMBALAGEM: o dublê **não informa embalagem**, e mesmo assim o prejuízo DISPARA —
        // ele dispara sobre o valor **estimado por baixo** (decisão 7+10, 14/10/2026). Sem este
        // par, um serviço que tratasse "qualquer pendência" como incompleto passaria em metade dos
        // casos.
        $this->assertContains(VocabularioDoPortao::RESULTADO_NEGATIVO, $comPrejuizo, 'embalagem pendente NÃO pode impedir o prejuízo de disparar');
        $this->assertNotContains(VocabularioDoPortao::FRETE_AUSENTE, $comPrejuizo, 'embalagem pendente NÃO pode anular o resultado');

        // ⚠️⚠️ A ISCA QUE SEPARA AS DUAS: os cenários A e B são IDÊNTICOS menos o frete, e deram
        // elos DIFERENTES da cadeia.
        $this->assertNotSame($semFrete, $comPrejuizo, 'a fixture não discrimina frete ausente de embalagem pendente');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (5) A NAVEGAÇÃO E O CONTRATO DO PAYLOAD
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ A navegação ↑/↓ **como DADO**: os ids, as pontas, a posição e a frase — com **4 ofertas,
     * UMA em `preparando`**. Com uma oferta só nada disso se distingue (foi a falha de fixture das
     * duas sobreviventes da 187-06).
     */
    public function test_a_navegacao_pula_preparando_e_as_pontas_sao_nulas_188_02(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();
        $this->comDadosEfetivos();

        // ⚠️ `entrou_na_fila_em` crescente dentro do MESMO degrau ⇒ ordem estável e previsível
        // (maior espera primeiro). As três ao vivo + UMA em `preparando`, que vai para o FIM
        // (degrau 6) e **sai da navegação**.
        $a = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['espera' => '2026-10-19 03:00:00']);
        $b = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['espera' => '2026-10-19 05:00:00']);
        $c = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['espera' => '2026-10-19 07:00:00']);
        $preparando = $this->oferta($empresa, PubAprovacaoOferta::PREPARANDO, ['espera' => '2026-10-19 01:00:00', 'preparo' => 'gerando as fotos']);
        foreach ([$a, $b, $c] as $o) {
            $this->imagem($o, 0);
        }

        $navDe = fn (PubAprovacaoOferta $o): array => $this->servico()->para($o, $this->base($empresa))['navegacao'];

        // ⚠️ `ids` tem **3**, não 4: `preparando` está FORA da navegação (`_nav`, `:1263`).
        $nav = $navDe($b);
        $this->assertSame([$a->id, $b->id, $c->id], $nav['ids'], 'a navegação tem de ser a fila ORDENADA sem `preparando`');
        $this->assertSame(3, $nav['total']);
        $this->assertNotContains($preparando->id, $nav['ids'], '`preparando` entrou na navegação');

        // ⚠️ A PRIMEIRA: `anterior_id` é `null`; a ÚLTIMA: `proxima_id` é `null`. Sem isso o ↑/↓
        // daria a volta, e dar a volta faz a pessoa revisar de novo o que já revisou.
        $naPrimeira = $navDe($a);
        $this->assertNull($naPrimeira['anterior_id'], 'na primeira `anterior_id` tem de ser null');
        $this->assertSame($b->id, $naPrimeira['proxima_id']);
        $this->assertSame(1, $naPrimeira['posicao']);
        $this->assertSame('1 de 3 na fila', $naPrimeira['pos'], '`:1318` — a frase, 1-based');

        $naUltima = $navDe($c);
        $this->assertSame($b->id, $naUltima['anterior_id']);
        $this->assertNull($naUltima['proxima_id'], 'na última `proxima_id` tem de ser null');
        $this->assertSame(3, $naUltima['posicao']);
        $this->assertSame('3 de 3 na fila', $naUltima['pos']);

        // ⚠️ No MEIO as duas pontas existem — sem este caso, "null nas pontas" poderia ser "null
        // sempre".
        $this->assertSame($a->id, $nav['anterior_id']);
        $this->assertSame($c->id, $nav['proxima_id']);
        $this->assertSame(2, $nav['posicao']);
        $this->assertSame('2 de 3 na fila', $nav['pos']);

        // ⚠️⚠️ A oferta em `preparando`: `posicao`, `pos` e as duas pontas são `null`. **O
        // PROTÓTIPO PERDE AQUI**: o `indexOf` dele dá `-1` e `:1318` produz "0 de 3 na fila" — a
        // tela afirmando posição que não existe. ➡️ A tela não inventa posição.
        $noPreparando = $navDe($preparando);
        $this->assertNull($noPreparando['posicao'], '`preparando` não tem posição na navegação');
        $this->assertNull($noPreparando['pos'], 'a frase foi montada para quem não está na fila');
        $this->assertNull($noPreparando['anterior_id']);
        $this->assertNull($noPreparando['proxima_id']);
        $this->assertSame([$a->id, $b->id, $c->id], $noPreparando['ids'], '`ids` continua sendo a lista inteira');
        $this->assertStringNotContainsString('0 de', (string) ($noPreparando['pos'] ?? ''), 'a frase "0 de N" do protótipo voltou');

        // ⚠️ O RECORTE: a navegação não pula para FORA da conta. Oferta de OUTRA empresa não entra.
        $outra = Company::factory()->create();
        $alheia = $this->oferta($outra, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['espera' => '2026-10-19 02:00:00']);
        $this->imagem($alheia, 0);
        $this->assertSame([$a->id, $b->id, $c->id], $navDe($b)['ids'], 'a navegação vazou para outra conta');
        $this->assertNotContains($alheia->id, $navDe($b)['ids']);

        // ⚠️⚠️ **`preparando` sai da NAVEGAÇÃO mas CONTINUA nos `ids` do LOTE** — a divergência D9
        // da Fase 187, reproduzida de propósito e **já no ar**. São listas diferentes.
        $this->assertSame([PubAprovacaoOferta::PREPARANDO], PainelDeRevisaoService::FORA_DA_NAVEGACAO, 'a lista do que sai da navegação mudou');
        $this->assertSame(
            4,
            app(FilaDeAprovacaoService::class)->paraAConta($empresa->id, null)->count(),
            'o recorte da conta tem as QUATRO — é a navegação que exclui, não a consulta',
        );
    }

    /** O contrato do payload: **todas** as chaves, e **nenhuma data como objeto** (COL-06). */
    public function test_o_payload_tem_todas_as_chaves_e_nenhuma_data_sai_como_objeto_188_02(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();
        $this->comDadosEfetivos(['custo' => 80.0]);
        $quem = User::factory()->create(['name' => 'Rafaela Guedes']);

        $oferta = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'responsavel' => $quem, 'versao' => 6,
            'preco_alterado' => ['de' => 120.0, 'para' => 100.0, 'quem' => $quem->id, 'quando' => '2026-10-19 08:00:00'],
        ]);
        $this->imagem($oferta, 0, ['estado' => PubAprovacaoImagem::PENDENTE, 'alerta' => PubAprovacaoImagemAlerta::ALTO]);

        $vm = $this->servico()->para($oferta, $this->base($empresa));

        // ⚠️⚠️ **UMA asserção coletando as chaves ausentes**, nunca uma por chave: uma por chave
        // faria a contagem crescer junto com o contrato (learnings §4b.2). ⚠️ E `array_key_exists`,
        // não `isset`: **chave ausente e chave NULA são casos DIFERENTES** no cliente, e foi chave
        // ausente que derrubou a página em 07/10/2026.
        $esperadas = ['oferta_id', 'versao', 'identificacao', 'falha', 'navegacao', 'pendencias', 'rodape', 'fotos', 'preco'];
        $this->assertSame(
            [],
            array_values(array_filter($esperadas, static fn (string $k): bool => ! array_key_exists($k, $vm))),
            'chave do contrato AUSENTE no payload',
        );
        $this->assertSame($esperadas, array_keys($vm), 'o payload ganhou ou perdeu chave — o contrato mudou');

        // ─── Os VALORES, conferidos um por um (um `assertArrayHasKey` passa com tudo vazio) ────
        $this->assertSame($oferta->id, $vm['oferta_id']);
        // ⚠️ COL-04 depende dela: a versão **no instante da leitura**.
        $this->assertSame(6, $vm['versao']);
        $this->assertSame($oferta->versao, $vm['versao'], 'a versão não é a da oferta');
        $this->assertNotSame([], $vm['pendencias'], 'a fixture não produziu pendência — metade do payload mediria NADA');
        $this->assertSame(['produto', 'sku', 'titulo', 'chips', 'responsavel', 'espera'], array_keys($vm['identificacao']));
        $this->assertSame(['ids', 'anterior_id', 'proxima_id', 'posicao', 'total', 'pos'], array_keys($vm['navegacao']));
        $this->assertSame(['aprovavel', 'motivo', 'motivo_cor', 'botoes'], array_keys($vm['rodape']));
        $this->assertSame(['chave', 'texto', 'correcao', 'severidade', 'glifo', 'destino'], array_keys($vm['pendencias'][0]));

        // ⚠️⚠️ `fotos` e `preco` NASCERAM `null`, **declaradas** com o plano dono no docblock —
        // **nunca ausentes**. ⚠️ **`fotos` FOI PREENCHIDA pelo plano 188-03** (`FotosDoPainelService`),
        // e esta linha foi atualizada pela wave 3: a asserção antiga (`assertNull($vm['fotos'])`)
        // **reprovava pelo próprio conserto** — ela media *"o dono ainda não chegou"*, que é uma
        // medição DATADA, não invariante. ⚠️ **A contagem de asserções deste caso não mudou**
        // (uma asserção virou uma), então `contagens-188-02.txt` continua valendo sem retoque.
        // ⚠️ `preco` continua `null`: ela é do plano **188-04**.
        $this->assertIsArray($vm['fotos'], '`fotos` é do plano 188-03 e já está preenchida');
        $this->assertNull($vm['preco'], '`preco` é do plano 188-04 e nasce null, declarada');
        $this->assertTrue(array_key_exists('fotos', $vm) && array_key_exists('preco', $vm), 'chave nula ≠ chave ausente');

        // ⚠️⚠️ **COL-06 — NENHUMA data sai como objeto.** UMA asserção coletando os infratores.
        $achatado = $this->achatar($vm);
        $this->assertSame([], $achatado['objetos'], 'objeto no payload: `Carbon` não atravessa — COL-06');

        // ⚠️ ISCA: o achatador PEGA objeto. Sem isto, "[]" seria indistinguível de sonda cega.
        $comObjeto = $this->achatar(['a' => ['b' => Carbon::now()]]);
        $this->assertSame(['a.b (Carbon\Carbon)'], $comObjeto['objetos'], 'o achatador não acha objeto — a asserção de COL-06 é vazia');

        // ⚠️ E o payload é serializável de ponta a ponta (é o que o Inertia faz com ele).
        $json = json_encode($vm);
        $this->assertIsString($json);
        $this->assertSame($vm, json_decode((string) $json, true), 'o payload não sobrevive ao `json_encode` — algo nele não é dado');

        // ─── ⚠️⚠️ AND-02 / REVS-14 — NENHUM rótulo de status do ML no payload ─────────────────
        //
        // ⚠️ UMA asserção coletando os infratores, e a sonda é por **IGUALDADE/PALAVRA INTEIRA**,
        // nunca `includes`: `includes('active')` casaria `inactive` e `'closed'` casaria `enclosed`
        // — a família que já mordeu CINCO vezes neste projeto.
        $sondaDoMl = '/\b('.implode('|', self::STATUS_DO_ML).')\b/i';
        $infratores = [];
        foreach ($achatado['plano'] as $caminho => $valor) {
            // ⚠️ Flag DENTRO do laço.
            $alvo = $caminho.' '.(is_scalar($valor) ? (string) $valor : '');
            if (preg_match($sondaDoMl, $alvo) === 1) {
                $infratores[] = $caminho.' => '.(is_scalar($valor) ? (string) $valor : gettype($valor));
            }
        }
        $this->assertSame([], $infratores, 'AND-02 / REVS-14: status do Mercado Livre atravessou para o payload');

        // ⚠️ ISCA DA SONDA, nas duas direções — sem elas "0 infratores" não prova nada.
        $this->assertSame(1, preg_match($sondaDoMl, 'andamento => active'), 'a sonda de status do ML está cega');
        $this->assertSame(1, preg_match($sondaDoMl, 'estado => under_review'), 'a sonda não pega status com underscore');
        $this->assertSame(0, preg_match($sondaDoMl, 'andamento => aguardando_aprovacao'), 'a sonda acusa o vocabulário INTERNO — ela está larga');
        $this->assertSame(0, preg_match($sondaDoMl, 'motivo => Publicando no Mercado Livre · edição bloqueada'), 'a sonda acusa a PROSA que nomeia o ML — ela está larga');

        // ⚠️ E o vocabulário que DE FATO viaja é o interno, lido do modelo.
        $this->assertArrayHasKey($vm['identificacao']['chips']['andamento']['chave'], PubAprovacaoOferta::ANDAMENTOS);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (6) O CUSTO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ `BloqueiosDaOfertaService::para()` é chamado **EXATAMENTE UMA** vez por oferta.
     *
     * Ele lê a composição, que consulta `/sites/MLB/listing_prices`. **Duas chamadas dobrariam as
     * requisições ao Mercado Livre** — é a classe do incidente de **30/07/2026**, quando requisições
     * lentas ocuparam os workers do php-fpm e **até o login parou**.
     *
     * ⚠️ O espião é uma **SUBCLASSE no teste**: o serviço da 186 **não é alterado no disco**.
     */
    public function test_CUSTO_para_e_chamado_UMA_vez_por_oferta_no_painel_188_02(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();
        $this->comTarifa(self::PREMIUM);
        $this->comDadosEfetivos();

        $espiao = new class(app(ComposicaoFinanceiraService::class), app(AjustesDaOfertaService::class)) extends BloqueiosDaOfertaService
        {
            public int $chamadas = 0;

            public function para(PubAprovacaoOferta $oferta): array
            {
                $this->chamadas++;

                return parent::para($oferta);
            }
        };
        $controle = new class(app(ComposicaoFinanceiraService::class), app(AjustesDaOfertaService::class)) extends BloqueiosDaOfertaService
        {
            public int $chamadas = 0;

            public function para(PubAprovacaoOferta $oferta): array
            {
                $this->chamadas++;

                return parent::para($oferta);
            }
        };

        $servico = new PainelDeRevisaoService($espiao, app(ProximoARevisarService::class), app(AndamentoDaOfertaService::class));

        $ofertas = [];
        foreach ([self::CLASSICO, self::PREMIUM, self::CLASSICO] as $i => $tipo) {
            $o = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['tipo' => $tipo, 'espera' => '2026-10-19 0'.($i + 2).':00:00']);
            $this->imagem($o, 0);
            $ofertas[] = $o;
        }

        $antesDeTarifa = count(array_filter($this->chamadas, static fn (array $c): bool => $c['caminho'] === '/sites/MLB/listing_prices'));

        foreach ($ofertas as $o) {
            $servico->para($o, $this->base($empresa));
        }

        // ⚠️ **UMA por oferta, nem uma a mais.** Três ofertas ⇒ três chamadas.
        $this->assertSame(3, $espiao->chamadas, '`para()` não foi chamado UMA vez por oferta');

        // ⚠️ ISCA DIREÇÃO 1 — o espião CONTA. Sem isto, "3" poderia ser o valor de um espião cego.
        $espiao->para($ofertas[0]);
        $this->assertSame(4, $espiao->chamadas, 'ISCA: o espião não conta');
        $this->assertSame(0, $controle->chamadas, 'o espião de CONTROLE não deveria ter sido usado');

        // ⚠️ ISCA DIREÇÃO 2 — a requisição ao ML é **DE VERDADE** (trio distinto gera requisição
        // nova): os dois `listing_type_id` produziram trios diferentes. Sem isto, um cache que
        // devolvesse sempre a mesma coisa faria o gate parecer verde sem medir nada.
        $trios = [];
        foreach ($this->chamadas as $c) {
            // ⚠️ Flag DENTRO do laço.
            if ($c['caminho'] === '/sites/MLB/listing_prices') {
                $trios[] = ($c['query']['category_id'] ?? '').'|'.($c['query']['listing_type_id'] ?? '').'|'.($c['query']['price'] ?? '');
            }
        }
        $this->assertGreaterThan($antesDeTarifa, count($trios), 'nenhuma requisição de tarifa saiu — o gate de custo mede NADA');
        $this->assertGreaterThanOrEqual(2, count(array_unique($trios)), 'os dois tipos deveriam ser DOIS trios distintos');

        // ⚠️⚠️ **E NADA VAI AO MERCADO LIVRE ALÉM DA TARIFA** — o painel APROVA, não PUBLICA
        // (critério 18: a escrita no ML é da **Fase 193**). Prova por **AUSÊNCIA de requisição**,
        // não por leitura de código.
        $forasteiras = array_values(array_filter(
            $this->chamadas,
            static fn (array $c): bool => $c['caminho'] !== '/sites/MLB/listing_prices',
        ));
        $this->assertSame([], $forasteiras, 'requisição ao ML fora da tarifa: o painel não publica nada');

        // ⚠️ ISCA DO GRAVADOR, nas duas direções: ele registra qualquer caminho. Sem isto, "[]"
        // seria indistinguível de gravador cego.
        Http::get('https://api.mercadolibre.com/items/MLB1');
        $this->assertNotSame(
            [],
            array_values(array_filter($this->chamadas, static fn (array $c): bool => $c['caminho'] === '/items/MLB1')),
            'ISCA: o gravador não registra requisição fora da tarifa — a prova por ausência é vazia',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // TRANSVERSAIS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Território e AUSÊNCIAS, lidas do código **SEM COMENTÁRIO**.
     *
     * ⚠️ Ler sem comentário não é zelo: a prosa deste projeto cita os próprios identificadores que
     * diz NÃO usar, e um gate cru reprovaria **pelo próprio conserto**. ⚠️ **ESTA PROSA CITA DE
     * PROPÓSITO** `lucro líquido`, `lucro_liquido`, `localStorage`, `#FACC15`, `desfazer`,
     * `confirmar publicação` e `ehKit` — é a **ISCA DO LEITOR**.
     *
     * ⚠️⚠️ **O gate de "lucro líquido" é recortado à lista CONSTANTE dos arquivos deste plano:**
     * `lucro_liquido` é campo **legítimo** do Portal, e varrer o `app/` inteiro reprovaria trabalho
     * de outra sessão — foi a 5ª ocorrência de "gate mais largo que o invariante" na Fase 186, e ela
     * **BARROU UM DEPLOY**.
     */
    public function test_territorio_e_ausencias_do_plano_188_02(): void
    {
        // ⚠️ A lista CONSTANTE dos arquivos DESTE plano — nunca `app/` inteiro.
        $meus = [
            'app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php',
            'app/Http/Controllers/MlbPublicadorAprovacaoController.php',
        ];

        $fonte = '';
        foreach ($meus as $rel) {
            $fonte .= $this->semComentarios($rel);
        }

        // ⚠️ ISCA DO RECORTE: a lista não está vazia e cada entrada EXISTE no disco (o
        // anti-fantasma é o gate perene — são NOVE caminhos fantasmas nesta milestone).
        $this->assertCount(2, $meus);
        $this->assertNotSame('', trim($fonte), 'o leitor devolveu vazio — o gate mediria NADA');

        // ─── VOC-03 e COL-02 ─────────────────────────────────────────────────────────────────
        $this->assertStringNotContainsString('lucro', mb_strtolower($fonte), 'VOC-03: "lucro líquido" é proibido');
        $this->assertStringNotContainsString('localStorage', $fonte, 'COL-02: filtro e oferta aberta vivem na URL');
        // ⚠️ TOK-15 — nenhum HEX sai do PHP: o `content` do Tailwind **NÃO** varre `.php`, então a
        // classe não geraria CSS. A cor é CHAVE SEMÂNTICA.
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}\b/', $fonte, 'hex no PHP: a classe não seria varrida pelo Tailwind');
        $this->assertStringNotContainsString('->ehKit(', $fonte, 'é `quantidade_kit` e só — ver a divergência declarada');

        // ─── APROVAR JÁ PUBLICA: a ausência de segundo passo, na FORMA ───────────────────────
        foreach (['desfazer', 'confirmar publica', 'cancelar aprova', 'segundo passo'] as $proibido) {
            $this->assertStringNotContainsString($proibido, mb_strtolower($fonte), 'decisão do usuário 14/10/2026: aprovar JÁ PUBLICA');
        }

        // ⚠️ ISCA DAS SONDAS, nas duas direções.
        $this->assertStringContainsString('lucro', mb_strtolower('O lucro líquido do mês'), 'a sonda de lucro está cega');
        $this->assertMatchesRegularExpression('/#[0-9A-Fa-f]{6}\b/', 'color: #FACC15;', 'a sonda de hex está cega');
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}\b/', 'const X = 1;');
        $this->assertStringContainsString('desfazer', mb_strtolower('Botão de Desfazer'), 'a sonda de segundo passo está cega');

        // ─── ⚠️ ESTE SERVIÇO NÃO ESCREVE NADA (é a prova da camada B, do lado deste plano) ────
        $doPainel = $this->semComentarios('app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php');
        $sondaDeEscrita = "/->andamento\s*=(?!=)|(?:update|updateOrCreate|create|fill|insert|forceFill|save|delete)\s*\(/";
        $this->assertSame(0, preg_match($sondaDeEscrita, $doPainel), 'o serviço do painel ESCREVE — ele só pode LER');
        // ⚠️ ISCA: a sonda de escrita PEGA, nas duas direções.
        $this->assertSame(1, preg_match($sondaDeEscrita, '$o->update([1]);'), 'a sonda de escrita está cega');
        $this->assertSame(1, preg_match($sondaDeEscrita, "\$o->andamento = 'aprovado';"), 'a sonda não pega a atribuição');
        $this->assertSame(0, preg_match($sondaDeEscrita, "return ['andamento' => (string) \$o->andamento];"), 'a sonda acusa a chave de SAÍDA — não discrimina expor de escrever');

        // ⚠️⚠️ E ele CASA a sonda larga do gate `andamento-um-lugar-so` — senão a entrada dele na
        // lista de EXPOSITORES seria **LINHA MORTA** abrindo um buraco para o futuro.
        $sondaLarga = "/'andamento'\s*=>|->andamento\s*=(?!=)/";
        $this->assertSame(1, preg_match($sondaLarga, $doPainel), 'o expositor não casa a sonda larga: a exceção dele é linha morta');
        $this->assertSame(1, preg_match('/PubAprovacaoOferta|pub_aprovacao_ofertas/', $doPainel), 'o expositor não referencia o portão');

        // ─── E o que o código REALMENTE usa está lá: a 186/187 é CHAMADA, nunca recopiada ────
        $this->assertStringContainsString('resumoDoRodape', $doPainel, 'BLOQ-12: o motivo tem de CHAMAR `resumoDoRodape()`');
        $this->assertStringContainsString('rotuloDaEspera', $doPainel, 'a espera tem de vir da fórmula pública do 187-05');
        $this->assertStringContainsString('PREFIXO_ESPERA', $doPainel, 'o prefixo da espera tem de ser LIDO');
        $this->assertStringContainsString('ordenarFila', $doPainel, 'a ordem tem de vir da ÚNICA PORTA');
        // ⚠️⚠️ **AQUI NÃO SE COBRA `TUDO_CONFERIDO`, E ISSO É MEDIDO, NÃO ESQUECIMENTO.** A
        // primeira redação deste gate exigia o nome da constante no arquivo — e **o desenho CERTO
        // não a menciona**: o literal verde sai de dentro de `resumoDoRodape()`, que é justamente o
        // que este plano DEVE chamar. ➡️ Cobrar a constante empurraria o executor seguinte a
        // **remontar a frase** só para o gate passar, perdendo o único-lugar. É a família
        // `feedback_gate_que_reprova_pelo_proprio_conserto`, e o gate reprovou **o conserto certo**
        // na primeira rodada (*"To contain: TUDO_CONFERIDO"*).
        // ➡️ O invariante de verdade é o PAR que já está acima: `resumoDoRodape` **é chamado** e o
        // literal **não é recopiado**. Os dois juntos só passam com a frase vindo de um lugar só.
        $this->assertStringContainsString("->rotulo()", $doPainel, 'o rótulo do andamento tem de ser LIDO do modelo');
        // ⚠️ E os literais da 186/187 NÃO foram recopiados.
        $this->assertStringNotContainsString("'Fotos, preço e conteúdo conferidos'", $doPainel, 'o literal verde foi RECOPIADO');
        $this->assertStringNotContainsString("'Bloqueado: '", $doPainel, 'o prefixo do bloqueio foi RECOPIADO');
        $this->assertStringNotContainsString("'aguardando há '", $doPainel, 'o prefixo da espera foi RECOPIADO');
        $this->assertStringNotContainsString("'gold_pro'", $doPainel, 'o listing type foi escrito à mão — é a terceira cópia daquele literal');

        // ─── O ESCOPO DO ASSUNTO, com ISCA nas duas direções ────────────────────────────────
        //
        // ⚠️⚠️ **"Prova de território por RANGE" NÃO PASSA nesta árvore:** a 187-11 mediu 111
        // commits no range, **43 da sessão paralela**, e o diff cru acusava **12 dos 60 alvos
        // vetados**. ⚠️ **E NÃO se confere o AUTOR:** as três máquinas usam o MESMO `user.name`.
        $this->assertSame(1, preg_match(self::ESCOPO, 'feat(188-02): x'), 'o escopo não casa o próprio assunto');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(188-01): x'), 'o escopo casa a wave 1 — ele está largo');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(187-02): x'), 'o escopo casa a Fase 187 — ele está largo');
        $this->assertSame(0, preg_match(self::ESCOPO, 'fix(261016-mwe): x'), 'o escopo casa a outra máquina — ele está largo');

        $log = $this->git('git log --format=%h:%s '.self::PARTIDA.'..HEAD');
        $hashes = [];
        foreach (array_filter(explode("\n", $log)) as $linha) {
            // ⚠️ Flag DENTRO do laço.
            $corte = strpos($linha, ':');
            if ($corte !== false && preg_match(self::ESCOPO, substr($linha, $corte + 1)) === 1) {
                $hashes[] = substr($linha, 0, $corte);
            }
        }

        // ⚠️ **SEM `return` antecipado:** a contagem deste caso NÃO pode depender de quantos
        // commits existem (a lição medida no 187-07, onde ela ia de 26 para 70). ⚠️ Medido: a
        // contagem é a MESMA com zero e com N commits no escopo.
        $arquivos = $hashes === []
            ? []
            : array_values(array_filter(array_map(
                'trim',
                explode("\n", $this->git('git show --name-only --format= '.implode(' ', $hashes))),
            )));

        // ⚠️ A lista dos VETADOS — `188-REGUA.md` §9.
        $proibidos = [
            // Os 10 da Fase 186 em `Aprovacao/` (fora de `Fila/` e de `Painel/`).
            'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
            'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
            'app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php',
            'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php',
            'app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/LinhaFinanceira.php',
            // Os 8 de `Fila/` da Fase 187.
            'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
            'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php',
            'app/Services/Publicador/Aprovacao/Fila/FiltrosDaFilaService.php',
            'app/Services/Publicador/Aprovacao/Fila/GrupoDaFilaService.php',
            'app/Services/Publicador/Aprovacao/Fila/GaleriaDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/Fila/LoteDaFilaService.php',
            'app/Services/Publicador/Aprovacao/Fila/AquecimentoDeTarifasService.php',
            // Os 21 `.jsx` de `aprovacao/`, NO AR, e as peças vizinhas.
            'resources/js/Components/Mlb/Publicador/aprovacao',
            'resources/js/Components/Mlb/Publicador/publicacoes',
            'resources/js/Components/Mlb/Publicador/identidade',
            'resources/js/Components/Publicador/Mesa',
            'resources/js/Layouts/AppLayout.jsx',
            'resources/js/Pages/Mlb/Publicador/Editor.jsx',
            // Creative Engine, acervo, MCP, Support, Portal e os outros vetados.
            'app/Services/Creative',
            'app/Services/Publicador/Criativos',
            'app/Services/Publicador/ImagemAssetService.php',
            'app/Support/Publicador',
            'app/Mcp',
            'app/Services/Portal',
            'app/Services/Mlb/Publicacao/MlPublicacaoService.php',
            'app/Services/Publicador/Fila/ResumoRapidoService.php',
            'app/Services/Publicador/EditorRascunhoService.php',
            // Os gates INTOCÁVEIS.
            'tests/js/publicador-header-zindex-ordem.test.js',
            'tests/js/estrutura-meus-anuncios.test.js',
            'tests/js/publicador-fila-tokens-187.test.js',
            'tests/js/publicador-fila-proximo-187.test.js',
            // ⚠️ GATE-07 — nenhum destes pertence a plano nenhum; quem edita é o orquestrador.
            '.planning/ROADMAP.md',
            '.planning/REQUIREMENTS-v27.md',
            '.planning/STATE.md',
        ];

        // ⚠️⚠️ **UMA asserção coletando os infratores**, nunca uma por caminho — a contagem não
        // pode crescer com a lista (learnings §4b.2).
        $tocados = [];
        foreach ($arquivos as $a) {
            // ⚠️ Flag DENTRO do laço.
            foreach ($proibidos as $p) {
                if ($a === $p || str_starts_with($a, rtrim($p, '/').'/')) {
                    $tocados[] = $a;
                }
            }
        }
        $this->assertSame([], array_values(array_unique($tocados)), 'commit do escopo (188-02) tocou alvo VETADO');

        // ⚠️ A prova de que o escopo não está vazio por construção, COLETADA.
        $doPlano = ['Aprovacao/Painel/PainelDeRevisao', 'PainelDeRevisaoServiceTest', 'PainelDaOfertaRotaTest',
            'contagens-188-02', 'MlbPublicadorAprovacaoController', 'FechamentoDaFase186Test', 'contagens-186-09'];
        $reconhece = static fn (string $a): bool => (bool) array_filter($doPlano, static fn (string $p): bool => str_contains($a, $p));

        $semProva = $hashes !== [] && ! array_filter($arquivos, $reconhece)
            ? ['os commits do escopo não tocaram arquivo deste plano: '.json_encode(array_slice($arquivos, 0, 8))]
            : [];
        $this->assertSame([], $semProva, 'o range do escopo está vazio por construção');

        // ⚠️ ISCA DA PROVA, nas duas direções.
        $this->assertTrue($reconhece('app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php'), 'a prova não reconhece o serviço deste plano');
        $this->assertFalse($reconhece('app/Services/Creative/CreativeJuiz.php'), 'a prova reconhece arquivo de OUTRA fase');

        // ⚠️ E o ANTI-FANTASMA, que é o gate PERENE (`test -e` em cada alvo citado).
        $semDisco = array_values(array_filter(
            $proibidos,
            fn (string $p): bool => ! file_exists($this->raiz().'/'.$p) && ! str_contains($p, 'REQUIREMENTS-v27'),
        ));
        $this->assertSame([], $semDisco, 'caminho FANTASMA na lista de vetados — o gate dele mede NADA');

        // ⚠️ **T-188-SC — este plano NÃO instala nada**, provado por FORMA (nunca por `numstat` de
        // range): nenhum manifesto de dependência aparece entre os arquivos do escopo.
        $this->assertSame(
            [],
            array_values(array_filter($arquivos, static fn (string $a): bool => in_array($a, ['composer.json', 'composer.lock', 'package.json', 'package-lock.json'], true))),
            'T-188-SC: este plano não instala nada',
        );
    }

    /**
     * ⚠️ A BIJEÇÃO ids ↔ métodos, nos **DOIS** sentidos, sobre os **DOIS** arquivos deste plano.
     *
     * ⚠️⚠️ **O SUFIXO `_188_02` NÃO É ZELO.** O `--filter` do PHPUnit é regex **NÃO ANCORADO**, e
     * todo plano `_0N` desta fase tem homônimo possível nas Fases 186 **E** 187, as duas **EM
     * PRODUÇÃO**. Medido por script em 19/10/2026: existem
     * `test_territorio_e_ausencias_do_plano_187_06` … `_187_10` — sem o sufixo, o id de uma delas
     * casaria o meu e **SOMARIA** as contagens. ➡️ O conserto de colisão é sufixo no método **NOVO**,
     * **nunca** levantar a contagem do manifesto alheio.
     *
     * ⚠️ **E HÁ UMA TERCEIRA BIJEÇÃO: id ↔ `<verify>`.** Um id pode estar no manifesto, com contagem
     * medida, apontando para métodos que existem — e **não ser rodado por `<verify>` nenhum**. Ele
     * não falha: ele simplesmente **nunca roda**. Quem a prova sobre os treze pares é o plano
     * **188-13**; aqui provo que os NOVE ids deste manifesto existem e casam método.
     */
    public function test_bijecao_do_manifesto_do_painel_188_02(): void
    {
        $manifesto = $this->raiz().'/tests/gates/contagens-188-02.txt';
        $this->assertFileExists($manifesto, 'manifesto ausente — os gates deste plano rodariam sem régua');

        $linhas = array_values(array_filter(
            array_map('trim', explode("\n", (string) file_get_contents($manifesto))),
            static fn (string $l): bool => $l !== '' && ! str_starts_with($l, '#'),
        ));

        $filtrosDosIds = [];
        foreach ($linhas as $linha) {
            // ⚠️ Flag DENTRO do laço.
            $partes = preg_split('/\s+/', $linha);
            if (($partes[1] ?? '') === 'php') {
                $this->assertArrayNotHasKey($partes[0], $filtrosDosIds, 'id REPETIDO no manifesto: o harness pega o PRIMEIRO');
                $filtrosDosIds[$partes[0]] = $partes[2];
            }
        }

        // ⚠️ Lista CONSTANTE dos NOVE, nunca `count()` — **piso não prova PRESENÇA** (a 187-11
        // mediu uma sobrevivente exatamente aí: com 13 itens, perder um deixava 12 e o piso
        // passava). ⚠️ O conserto é lista constante, NUNCA elevar o piso.
        $this->assertSame(
            ['identificacao-e-chips', 'pendencias-e-destinos', 'rodape-e-aprovavel', 'cadeia-e-incompleto',
                'navegacao-e-payload', 'custo-do-painel', 'painel-na-rota', 'territorio-painel', 'bijecao-manifesto-02'],
            array_keys($filtrosDosIds),
            'os NOVE ids deste plano, nesta ordem — id a mais ou a menos muda o que roda',
        );

        // ─── Os métodos dos DOIS arquivos, lidos do DISCO (não só desta classe) ──────────────
        $arquivos = [
            'tests/Feature/Publicador/Aprovacao/PainelDeRevisaoServiceTest.php',
            'tests/Feature/Publicador/Aprovacao/PainelDaOfertaRotaTest.php',
        ];
        $metodos = [];
        foreach ($arquivos as $rel) {
            $caminho = $this->raiz().'/'.$rel;
            $this->assertFileExists($caminho, "arquivo de teste inexistente: {$rel} — a bijeção mediria metade");
            preg_match_all('/function (test_[A-Za-z0-9_]*_188_02)\s*\(/', (string) file_get_contents($caminho), $m);
            $metodos = array_merge($metodos, $m[1]);
        }
        $metodos = array_values(array_unique($metodos));

        $this->assertCount(15, $metodos, 'os métodos `_188_02` deste plano encolheram ou cresceram');

        // ⚠️ SENTIDO 1 — método sem id **NUNCA RODA** em gate nenhum (foi o sobrevivente da
        // mutação do 186-03). UMA asserção coletando.
        $semId = [];
        foreach ($metodos as $metodo) {
            $achou = false;
            foreach ($filtrosDosIds as $filtro) {
                // ⚠️ Flag DENTRO do laço.
                if (preg_match('/^'.$filtro.'$/', $metodo) === 1) {
                    $achou = true;
                    break;
                }
            }
            if (! $achou) {
                $semId[] = $metodo;
            }
        }
        $this->assertSame([], $semId, 'método sem id no manifesto: ele NUNCA RODA em gate nenhum');

        // ⚠️ SENTIDO 2 — id órfão roda sobre **ZERO casos** e é indistinguível de gate verde.
        $orfaos = [];
        foreach ($filtrosDosIds as $id => $filtro) {
            $casou = false;
            foreach ($metodos as $metodo) {
                if (preg_match('/^'.$filtro.'$/', $metodo) === 1) {
                    $casou = true;
                    break;
                }
            }
            if (! $casou) {
                $orfaos[] = $id;
            }
        }
        $this->assertSame([], $orfaos, 'id órfão no manifesto: o gate dele roda sobre ZERO casos');

        // ⚠️ **NENHUM id com mais de TRÊS métodos** (alternância grande equivale a filtro de classe,
        // passa dos 120 s do shell e o helper RECUSA). UMA asserção coletando.
        $gordos = [];
        foreach ($filtrosDosIds as $id => $filtro) {
            $quantos = count(array_filter($metodos, static fn (string $m): bool => preg_match('/^'.$filtro.'$/', $m) === 1));
            if ($quantos > 3) {
                $gordos[] = $id.' ('.$quantos.')';
            }
        }
        $this->assertSame([], $gordos, 'id com mais de TRÊS métodos: o harness RECUSA e o shell estoura');

        // ⚠️ ISCA DO NOME QUE NINGUÉM ESCREVEU, nas duas direções.
        $casouFantasma = array_values(array_filter(
            $filtrosDosIds,
            static fn (string $f): bool => preg_match('/^'.$f.'$/', 'test_metodo_que_ninguem_escreveu_188_02') === 1,
        ));
        $this->assertSame([], $casouFantasma, 'ISCA: um filtro casa método que ninguém escreveu — ele está largo');
        $this->assertSame(1, preg_match('/^'.$filtrosDosIds['territorio-painel'].'$/', 'test_territorio_e_ausencias_do_plano_188_02'), 'ISCA: o filtro não casa o próprio método');

        // ⚠️⚠️ **E O SUFIXO PROTEGE DE VERDADE:** o método homônimo das waves da 187 **existe**, e o
        // meu filtro **NÃO** o casa. Sem esta linha, o sufixo seria zelo não medido.
        $this->assertSame(0, preg_match('/^'.$filtrosDosIds['territorio-painel'].'$/', 'test_territorio_e_ausencias_do_plano_187_08'), 'o filtro casa o homônimo da 187 — as contagens SOMARIAM');
        $this->assertFileExists($this->raiz().'/tests/Unit/Publicador/Aprovacao/LinhaDaOfertaServiceTest.php', 'o homônimo da 187 sumiu — a isca perdeu o sujeito');
        $this->assertStringContainsString(
            'test_territorio_e_ausencias_do_plano_187_08',
            (string) file_get_contents($this->raiz().'/tests/Unit/Publicador/Aprovacao/LinhaDaOfertaServiceTest.php'),
            'o homônimo da 187 mudou de nome — a isca perdeu o sujeito',
        );
    }
}
