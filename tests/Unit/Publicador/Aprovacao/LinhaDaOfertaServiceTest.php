<?php

namespace Tests\Unit\Publicador\Aprovacao;

use App\Models\Company;
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
use App\Services\Publicador\Aprovacao\AjustesDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\Fila\LinhaDaOfertaService;
use App\Services\Publicador\Aprovacao\Fila\ProximoARevisarService;
use App\Services\Publicador\Aprovacao\VocabularioDoPortao;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\DadosEfetivosService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 187, plano 08 — AS 11 CÉLULAS DA LINHA DA OFERTA, resolvidas no servidor.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"** — gate cuja resposta é "nada" é
 * decorativo e não entra (`feedback_gate_vazio_por_construcao`).
 *
 *   · **`preparando` no anel** — alguém pôr `preparando` em `parcial`. O protótipo tem
 *     `&& st!=='preparing'` (`:1229`) e é CONTRAINTUITIVO: uma oferta que o sistema ainda está
 *     montando **não** tem "revisão parcial salva", porque ninguém a tocou. Há isca.
 *   · **A EMBALAGEM** — alguém pôr "embalagem" em `ANULAM_O_RESULTADO` e o resultado virar
 *     `Incompleto`. A decisão 7+10 (14/10/2026) é o oposto: ela nasce `Pendente` VISÍVEL, o
 *     resultado sai **estimado por baixo** e **`prejuízo`/`margem` DISPARAM**. Há caso com as duas
 *     pontas no MESMO cenário (embalagem pendente × frete ausente) — é a isca que as separa.
 *   · **A fronteira de 1440 min** — alguém escrever `>=` no lugar de `>`. 1440 **NÃO** é âmbar
 *     (`:1247`), e sem `Carbon::setTestNow()` o caso viraria dependente da hora.
 *   · **O `+N` do bloqueio** — alguém contar o TOTAL em vez dos DEMAIS. Com 2 bloqueios a linha é
 *     "… +1" (`resumoDoRodape`, Fase 186, **CHAMADO** daqui).
 *   · **`Revisar` amarelo em todas** — apagaria o sinal de "comece por esta" (T-187-85).
 *   · **O CUSTO** — `para()` mais de uma vez por oferta multiplicaria as requisições ao Mercado
 *     Livre. É a classe do incidente de 30/07/2026, quando requisições lentas ocuparam os workers
 *     do php-fpm e até o login parou.
 *   · **O pior caso** — virar frase VISÍVEL (a coluna tem 92px e UMA linha, `:156`) ou aparecer com
 *     um preço só (aí `variante` é `null` e não há nota).
 *   · **O clamp da barra** — margem de 95% estourando a trilha, ou negativa desenhando para a
 *     esquerda.
 *
 * ⚠️ **ZERO REDE:** `Http::preventStrayRequests()` + `Http::fake` com gravador. Nenhuma chamada à
 * API real do Mercado Livre sai daqui.
 *
 * ⚠️ **ESTE ARQUIVO NÃO ALTERA NADA DAS FASES 186 E 187-02/04/05/06/07.**
 * `BloqueiosDaOfertaService`, `ComposicaoFinanceiraService`, `VocabularioDoPortao`,
 * `ProximoARevisarService`, `GaleriaDaOfertaService` e `PubAprovacaoOferta` são **LIDOS** — e, nos
 * casos de custo, SUBCLASSADOS no teste, nunca alterados no disco.
 *
 * ⚠️ **Nenhum executor desta fase viu esta tela renderizada:** o banco local não tem empresa com
 * `ml_token` (a rota dá 404 aqui). A conferência é do usuário, em produção, na conta 459.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class LinhaDaOfertaServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O commit de PARTIDA desta wave.
     *
     * ⚠️⚠️ **REMEDIDO de `07d87a56` para `44d0a364` em 16/10/2026.** `07d87a56` era o merge da
     * outra máquina, ANTES dos quatro commits do plano 187-07 (`9aca3eba`, `bd137b64`, `963009d6`
     * e a SUMMARY `44d0a364`). O ESCOPO `(187-08)` já descartava aqueles quatro, então o número de
     * asserções não muda (**28 com as duas partidas**, medido); mas um range que ABRAÇA o trabalho
     * de outra wave é uma afirmação falsa sobre o que este plano tocou, e um dia alguém o lê sem o
     * escopo ao lado. ➡️ A partida é o HEAD do começo DESTE plano.
     */
    private const PARTIDA = '44d0a364';

    /**
     * O ESCOPO DO ASSUNTO deste plano.
     *
     * ⚠️ **O autor NÃO discrimina nada nesta árvore:** as três sessões rodam com o MESMO
     * `user.name`. O discriminador é o escopo — e há isca nas duas direções.
     */
    private const ESCOPO = '/\(187-08\)/';

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

        // ⚠️ `Carbon::setTestNow()` em TODO caso de espera: a fronteira de 1440 min é exata, e sem
        // o agora PARADO o caso vira dependente da hora do dia.
        Carbon::setTestNow('2026-10-16 09:00:00');

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
     * @param  array{kit?: int, tipo?: string, titulo?: ?string, tocado?: bool, espera?: ?string,
     *   responsavel?: ?User, aprovador?: ?User, versao?: int, origem?: string, mlb?: ?string,
     *   preparo?: ?string, categoria?: ?string, sku?: string}  $o
     */
    private function oferta(Company $empresa, string $andamento, array $o = []): PubAprovacaoOferta
    {
        $this->sequencia++;

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => $o['sku'] ?? ('LIN-'.$this->sequencia),
            'nome' => 'Poltrona Beny',
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
            'tocado' => $o['tocado'] ?? false,
            'versao' => $o['versao'] ?? 1,
            'origem' => $o['origem'] ?? PubAprovacaoOferta::ORIGEM_AUTO,
            'responsavel_id' => isset($o['responsavel']) ? $o['responsavel']->id : null,
            'aprovado_por' => isset($o['aprovador']) ? $o['aprovador']->id : null,
            'ml_item_id' => $o['mlb'] ?? null,
            'preparo_frase' => $o['preparo'] ?? null,
            'entrou_na_fila_em' => array_key_exists('espera', $o) ? $o['espera'] : '2026-10-16 07:00:00',
        ]);

        return $oferta->fresh(['alvo', 'produto', 'responsavel', 'aprovadoPor']);
    }

    /** @param array{estado?: string, alerta?: ?string, tipo?: string} $o */
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
            'tipo' => $o['tipo'] ?? PubAprovacaoImagem::TIPO_ORIGINAL,
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

    private function servico(): LinhaDaOfertaService
    {
        return app(LinhaDaOfertaService::class);
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

            public float $oCusto = 200.0;

            public ?float $oFrete = 42.9;

            public float $oImposto = 19.0;

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

        $duble->oPreco = $o['preco'] ?? 1200.0;
        $duble->oCusto = $o['custo'] ?? 200.0;
        $duble->oFrete = array_key_exists('frete', $o) ? $o['frete'] : 42.9;
        $duble->oImposto = $o['imposto'] ?? 19.0;

        $this->app->instance(DadosEfetivosService::class, $duble);
    }

    private function comTarifa(string $listingType = self::CLASSICO): void
    {
        $arquivo = base_path("tests/fixtures-ml/sondagem/publico/categorias/".self::CATEGORIA."/listing_prices_{$listingType}.json");
        $this->assertFileExists($arquivo, 'fixture de tarifa ausente — o gate rodaria sobre zero casos');

        $this->tarifas[self::CATEGORIA.'|'.$listingType] = json_decode((string) file_get_contents($arquivo), true)['resposta'];
    }

    /** Quantas requisições a `/sites/MLB/listing_prices` saíram até agora. */
    private function requisicoesDeTarifa(): int
    {
        return count(array_filter(
            $this->chamadas,
            static fn (array $c): bool => $c['caminho'] === '/sites/MLB/listing_prices',
        ));
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

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 1. O ANEL, O TÍTULO E A META
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Os 4 estados do anel (`:1229`) — e ⚠️ **`preparando` cai em `nao_analisada`, NÃO em
     * `parcial`**, com isca.
     *
     * Mais o peso 600 do título **só** no `nao_analisada` (`:1242`).
     */
    public function test_o_anel_tem_QUATRO_estados_e_preparando_nao_e_parcial_187_08(): void
    {
        $empresa = Company::factory()->create();
        $servico = $this->servico();

        $casos = [
            // [andamento, tocado, progresso esperado]
            [PubAprovacaoOferta::APROVADO, false, LinhaDaOfertaService::PROGRESSO_APROVADA],
            [PubAprovacaoOferta::PUBLICANDO, false, LinhaDaOfertaService::PROGRESSO_APROVADA],
            [PubAprovacaoOferta::PUBLICADO, false, LinhaDaOfertaService::PROGRESSO_APROVADA],
            [PubAprovacaoOferta::FALHA, false, LinhaDaOfertaService::PROGRESSO_FALHA],
            [PubAprovacaoOferta::EM_REVISAO_ECF, false, LinhaDaOfertaService::PROGRESSO_PARCIAL],
            [PubAprovacaoOferta::AJUSTES_SOLICITADOS, false, LinhaDaOfertaService::PROGRESSO_PARCIAL],
            // `aguardando` NÃO tocada é a única `nao_analisada` dos estados ao vivo…
            [PubAprovacaoOferta::AGUARDANDO_APROVACAO, false, LinhaDaOfertaService::PROGRESSO_NAO_ANALISADA],
            // …e TOCADA ela vira `parcial`.
            [PubAprovacaoOferta::AGUARDANDO_APROVACAO, true, LinhaDaOfertaService::PROGRESSO_PARCIAL],
            // ⚠️⚠️ A ISCA: `preparando` é `nao_analisada`, mesmo TOCADA.
            [PubAprovacaoOferta::PREPARANDO, false, LinhaDaOfertaService::PROGRESSO_NAO_ANALISADA],
            [PubAprovacaoOferta::PREPARANDO, true, LinhaDaOfertaService::PROGRESSO_NAO_ANALISADA],
        ];

        $errados = [];
        foreach ($casos as [$andamento, $tocado, $esperado]) {
            // Flag DENTRO do laço.
            $oferta = $this->oferta($empresa, $andamento, ['tocado' => $tocado]);
            $obtido = $servico->progresso($oferta);

            if ($obtido !== $esperado) {
                $errados[] = "{$andamento}/tocado=".($tocado ? '1' : '0').": {$obtido}, esperava {$esperado}";
            }
        }

        $this->assertSame([], $errados, 'o estado do anel divergiu de `:1229`');

        // ⚠️ ISCA DO PRÓPRIO CASO: os 4 estados foram todos exercitados. Sem isto, uma tabela com
        // um estado só passaria.
        $vistos = array_unique(array_map(static fn (array $c): string => $c[2], $casos));
        sort($vistos);
        $this->assertSame(
            [LinhaDaOfertaService::PROGRESSO_APROVADA, LinhaDaOfertaService::PROGRESSO_FALHA,
                LinhaDaOfertaService::PROGRESSO_NAO_ANALISADA, LinhaDaOfertaService::PROGRESSO_PARCIAL],
            $vistos,
            'a tabela de casos não cobre os 4 estados do anel',
        );

        // ⚠️ `:1242` — o TÍTULO é peso 600 **só** quando `nao_analisada`.
        $this->comDadosEfetivos();
        $this->comTarifa();

        $naoAnalisada = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $parcial = $this->oferta($empresa, PubAprovacaoOferta::EM_REVISAO_ECF);

        $this->assertTrue($servico->para($naoAnalisada)['titulo_forte'], 'o título não analisado deveria ser 600');
        $this->assertFalse($servico->para($parcial)['titulo_forte'], 'o título analisado deveria ser 500');
    }

    /**
     * A META na ordem literal (`:1242`): **SKU · origem · vN · EXCEÇÃO**.
     *
     * O SKU do combo ganha `-K{N}`; a `EXCEÇÃO` só com exceção **vigente**.
     */
    public function test_a_meta_sai_na_ordem_SKU_origem_versao_excecao_187_08(): void
    {
        $empresa = Company::factory()->create();
        $quem = User::factory()->create();
        $this->comDadosEfetivos();
        $this->comTarifa();

        // Unidade, origem AUTO, v1, sem exceção.
        $unidade = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['sku' => 'POL-BENY']);
        $meta = $this->servico()->para($unidade)['meta'];

        $this->assertSame('POL-BENY', $meta['sku'], 'o SKU da unidade não ganha sufixo');
        $this->assertSame('Automática', $meta['origem']['rotulo']);
        $this->assertSame('⚡', $meta['origem']['glifo'], 'o glifo da origem automática é U+26A1');
        $this->assertSame(LinhaDaOfertaService::COR_DESTAQUE, $meta['origem']['cor']);
        $this->assertSame('v1', $meta['versao']);
        $this->assertFalse($meta['excecao'], 'sem exceção vigente o selo não aparece');

        // Combo de 3, origem MANUAL, v4.
        $combo = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'sku' => 'POL-BENY', 'kit' => 3, 'versao' => 4, 'origem' => PubAprovacaoOferta::ORIGEM_MANUAL,
        ]);
        $metaDoCombo = $this->servico()->para($combo)['meta'];

        $this->assertSame('POL-BENY-K3', $metaDoCombo['sku'], '`:1242` — o SKU do combo ganha `-K{N}`');
        $this->assertSame('Manual', $metaDoCombo['origem']['rotulo']);
        $this->assertSame('✎', $metaDoCombo['origem']['glifo']);
        $this->assertSame(LinhaDaOfertaService::COR_SUAVE, $metaDoCombo['origem']['cor']);
        $this->assertSame('v4', $metaDoCombo['versao']);

        // ⚠️ A EXCEÇÃO só com `ativa = true` (`vigentesDa`: `ativa` é o ÚNICO filtro de vigência).
        PubAprovacaoExcecao::create([
            'oferta_id' => $unidade->id,
            'tipo' => PubAprovacaoExcecao::RESULTADO_NEGATIVO,
            'justificativa' => 'Campanha de liquidação aprovada pela diretoria.',
            'liberada_por' => $quem->id,
            'liberada_em' => now(),
            'versao' => 1,
            'ativa' => true,
        ]);
        $this->assertTrue(
            $this->servico()->para($unidade->fresh(['alvo', 'produto']))['meta']['excecao'],
            'a exceção VIGENTE não acendeu o selo',
        );

        // ⚠️ ISCA: a exceção INATIVA **não** acende o selo — senão `vigentesDa` seria decorativa.
        $outra = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        PubAprovacaoExcecao::create([
            'oferta_id' => $outra->id,
            'tipo' => PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO,
            'justificativa' => 'Exceção revogada depois da renegociação com o fornecedor.',
            'liberada_por' => $quem->id,
            'liberada_em' => now(),
            'versao' => 1,
            'ativa' => false,
        ]);
        $this->assertFalse(
            $this->servico()->para($outra)['meta']['excecao'],
            'ISCA: exceção INATIVA acendeu o selo — `vigentesDa` virou decorativa',
        );

        // E o literal, em caixa alta (`:1242`).
        $this->assertSame('EXCEÇÃO', LinhaDaOfertaService::EXCECAO);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 2. O DINHEIRO — e a EXCEÇÃO DELIBERADA DA EMBALAGEM
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * O resultado nos TRÊS casos (`:1245`): `Incompleto` em âmbar · negativo em vermelho com
     * "prejuízo" · positivo em texto com "estimado".
     *
     * ⚠️ `Incompleto` é LIDO de `VocabularioDoPortao::INCOMPLETO` — *"custo ou frete ausente não
     * vira zero"*, porque zero afirmaria que é de graça.
     */
    public function test_o_resultado_tem_TRES_casos_com_as_tres_cores_187_08(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();

        // (a) POSITIVO: preço alto, custo baixo.
        $this->comDadosEfetivos(['preco' => 1200.0, 'custo' => 200.0]);
        $positivo = $this->servico()->para($this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO))['resultado'];
        $this->assertStringStartsWith('R$ ', $positivo['valor'], 'o resultado positivo sai em R$');
        $this->assertSame(LinhaDaOfertaService::COR_TEXTO, $positivo['cor']);
        $this->assertSame(LinhaDaOfertaService::RESULTADO_SUB_ESTIMADO, $positivo['sub']);
        $this->assertSame('estimado', $positivo['sub']);
        $this->assertSame(LinhaDaOfertaService::COR_NEUTRA, $positivo['sub_cor']);

        // (b) NEGATIVO: custo maior que o preço.
        $this->comDadosEfetivos(['preco' => 100.0, 'custo' => 900.0]);
        $negativo = $this->servico()->para($this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO))['resultado'];
        $this->assertStringContainsString("\u{2212}", $negativo['valor'], 'o negativo usa U+2212, não o hífen-menos');
        $this->assertSame(LinhaDaOfertaService::COR_VERMELHA, $negativo['cor']);
        $this->assertSame('prejuízo', $negativo['sub']);
        $this->assertSame(LinhaDaOfertaService::COR_VERMELHA, $negativo['sub_cor']);

        // (c) INCOMPLETO: frete AUSENTE anula o resultado.
        $this->comDadosEfetivos(['preco' => 1200.0, 'frete' => null]);
        $incompleto = $this->servico()->para($this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO))['resultado'];
        $this->assertSame(VocabularioDoPortao::INCOMPLETO, $incompleto['valor'], 'o literal é LIDO do vocabulário');
        $this->assertSame('Incompleto', $incompleto['valor']);
        $this->assertSame(LinhaDaOfertaService::COR_AMBAR, $incompleto['cor']);
        $this->assertSame('frete pendente', $incompleto['sub']);
        $this->assertSame(LinhaDaOfertaService::COR_AMBAR, $incompleto['sub_cor']);

        // ⚠️ As TRÊS cores são DIFERENTES — se colapsassem, as asserções acima passariam.
        $this->assertSame(3, count(array_unique([$positivo['cor'], $negativo['cor'], $incompleto['cor']])));

        // ⚠️ VOC-03/VOC-04 — o rótulo longo é LIDO do modelo, nunca "lucro líquido".
        $this->assertSame(PubAprovacaoComposicao::ROTULO_RESULTADO, $positivo['rotulo_longo']);
        $this->assertSame('Resultado estimado por venda', $positivo['rotulo_longo']);
    }

    /**
     * ⚠️⚠️ **A EMBALAGEM É A EXCEÇÃO DELIBERADA E ÚNICA** (decisões 7 e 10, 14/10/2026), e este
     * caso tem as DUAS pontas no MESMO cenário — é a isca que as separa.
     *
     * `PubAprovacaoComposicao::ANULAM_O_RESULTADO` são CINCO, e "embalagem" **não** está na lista:
     * ela nasce `Pendente` VISÍVEL, o resultado sai **estimado por baixo** e **`prejuízo`/`margem`
     * DISPARAM** sobre ele. O **FRETE** ausente, no mesmo cenário, **ANULA**.
     */
    public function test_a_EMBALAGEM_nao_anula_mas_o_FRETE_anula_187_08(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();

        // ⚠️ A lista é LIDA do modelo, e a prova de que embalagem está FORA dela.
        $this->assertNotContains('embalagem', PubAprovacaoComposicao::ANULAM_O_RESULTADO, 'embalagem entrou na lista que ANULA');
        $this->assertContains('frete', PubAprovacaoComposicao::ANULAM_O_RESULTADO, 'frete saiu da lista que ANULA');
        $this->assertCount(5, PubAprovacaoComposicao::ANULAM_O_RESULTADO, 'são CINCO linhas que anulam');

        // ─── A ponta A: embalagem PENDENTE (o dublê não informa embalagem) → NÃO é Incompleto ───
        //
        // ⚠️ E com preço baixo, o `prejuízo` DISPARA sobre o estimado por baixo.
        $this->comDadosEfetivos(['preco' => 100.0, 'custo' => 900.0]);
        $comEmbalagemPendente = $this->servico()->para($this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO));

        $this->assertNotSame(
            VocabularioDoPortao::INCOMPLETO,
            $comEmbalagemPendente['resultado']['valor'],
            'embalagem pendente NÃO pode tornar o resultado Incompleto (decisão 7+10)',
        );
        $this->assertSame(
            LinhaDaOfertaService::RESULTADO_SUB_PREJUIZO,
            $comEmbalagemPendente['resultado']['sub'],
            '`prejuízo` tem de DISPARAR sobre o estimado por baixo',
        );
        $this->assertNotSame(
            LinhaDaOfertaService::MARGEM_SEM_NUMERO,
            $comEmbalagemPendente['margem']['valor'],
            'a margem tem de EXISTIR sobre o estimado por baixo',
        );

        // ─── A ponta B: FRETE ausente, no MESMO cenário → É Incompleto ───
        $this->comDadosEfetivos(['preco' => 100.0, 'custo' => 900.0, 'frete' => null]);
        $semFrete = $this->servico()->para($this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO));

        $this->assertSame(
            VocabularioDoPortao::INCOMPLETO,
            $semFrete['resultado']['valor'],
            'frete ausente TEM de anular o resultado',
        );
        // ⚠️ E aí a margem é `—`, nunca `0%`: zero afirmaria margem zero.
        $this->assertSame(LinhaDaOfertaService::MARGEM_SEM_NUMERO, $semFrete['margem']['valor']);
        $this->assertSame('—', $semFrete['margem']['valor']);
        $this->assertSame('0%', $semFrete['margem']['largura'], 'sem número a barra tem largura ZERO');
        $this->assertSame(LinhaDaOfertaService::COR_TRILHA, $semFrete['margem']['barra'], 'sem número a barra é neutra');

        // ⚠️⚠️ A ISCA QUE SEPARA AS DUAS: os dois cenários são IDÊNTICOS menos o frete, e deram
        // resultados DIFERENTES. Sem esta linha, as duas pontas poderiam estar medindo o mesmo.
        $this->assertNotSame(
            $comEmbalagemPendente['resultado']['valor'],
            $semFrete['resultado']['valor'],
            'ISCA: os dois cenários deram o MESMO resultado — a separação não está medindo nada',
        );
    }

    /**
     * A margem (`:1246`) nos quatro casos, a barra CLAMPADA e ⚠️ **a divergência D10: o traço do
     * mínimo é a FÓRMULA `minimo/40`**, que dá **30% com 12** e continua certa com outro mínimo.
     */
    public function test_a_margem_tem_barra_clampada_e_o_traco_em_minimo_sobre_40_187_08(): void
    {
        // ⚠️ A FÓRMULA, provada nas duas leituras que o plano pede.
        $this->assertSame('30%', LinhaDaOfertaService::percentualDaBarra(12.0), 'D10: 12/40 = 30%');
        $this->assertSame('40%', LinhaDaOfertaService::percentualDaBarra(16.0), 'a fórmula continua certa com outro mínimo');
        $this->assertSame(40.0, LinhaDaOfertaService::TETO_DA_BARRA);

        // ⚠️ O CLAMP nas duas pontas.
        $this->assertSame('0%', LinhaDaOfertaService::percentualDaBarra(-15.0), 'margem negativa não desenha para a esquerda');
        $this->assertSame('100%', LinhaDaOfertaService::percentualDaBarra(95.0), '95% não estoura a trilha');
        $this->assertSame('100%', LinhaDaOfertaService::percentualDaBarra(40.0), 'o teto é exatamente 100%');
        $this->assertSame('0%', LinhaDaOfertaService::percentualDaBarra(0.0));
        $this->assertSame('0%', LinhaDaOfertaService::percentualDaBarra(null), 'nulo é largura ZERO, nunca `NaN%`');
        $this->assertSame('0%', LinhaDaOfertaService::percentualDaBarra(NAN), '`NAN` não produz `NaN%`');
        $this->assertSame('0%', LinhaDaOfertaService::percentualDaBarra(INF), '`INF` não produz `Inf%`');

        // ⚠️ ISCA DA FÓRMULA: ela NÃO é a identidade. Sem isto, um `percentualDaBarra` que
        // devolvesse o próprio número passaria nos dois primeiros casos por acidente.
        $this->assertNotSame('12%', LinhaDaOfertaService::percentualDaBarra(12.0));

        $empresa = Company::factory()->create();
        $this->comTarifa();

        // (a) margem ALTA (>= mínimo) → cor de texto, barra suave.
        $this->comDadosEfetivos(['preco' => 2000.0, 'custo' => 200.0]);
        $alta = $this->servico()->para($this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO))['margem'];
        $this->assertSame(LinhaDaOfertaService::COR_TEXTO, $alta['cor']);
        $this->assertSame(LinhaDaOfertaService::COR_SUAVE, $alta['barra']);
        $this->assertStringEndsWith('%', $alta['valor']);

        // (b) margem NEGATIVA → vermelha, barra `barra_baixa`, largura ZERO (clamp).
        $this->comDadosEfetivos(['preco' => 100.0, 'custo' => 900.0]);
        $negativa = $this->servico()->para($this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO))['margem'];
        $this->assertSame(LinhaDaOfertaService::COR_VERMELHA, $negativa['cor']);
        $this->assertSame(LinhaDaOfertaService::COR_BARRA_BAIXA, $negativa['barra']);
        $this->assertSame('0%', $negativa['largura'], 'margem negativa é barra de largura ZERO');

        // ⚠️ As duas cores de BARRA são DIFERENTES das duas de NÚMERO — o protótipo usa hex
        // distintos para cada eixo (`:1246`), e colapsá-los apagaria a distinção.
        $this->assertNotSame($alta['cor'], $alta['barra']);
        $this->assertNotSame($negativa['cor'], $negativa['barra']);

        // ⚠️ D4 / decisão 9 — a célula diz DE ONDE o número vem.
        $this->assertSame('com a tarifa real do Mercado Livre', $alta['fonte']);
        $this->assertSame(PubAprovacaoComposicao::ROTULO_MARGEM, $alta['rotulo_longo']);
        $this->assertSame('Margem de contribuição estimada', $alta['rotulo_longo']);

        // ⚠️ E o traço do mínimo acompanha o MÍNIMO da composição, não um literal.
        $this->assertSame(
            LinhaDaOfertaService::percentualDaBarra($alta['minimo_valor']),
            $alta['minimo'],
            'o traço do mínimo não é a fórmula do próprio mínimo',
        );
        $this->assertGreaterThan(0.0, $alta['minimo_valor'], 'o mínimo vem da config/parâmetro (FIN-09), nunca zero');
    }

    /**
     * O PREÇO (`:156`/`:1245`): **UMA** linha, o **PIOR CASO** e o balão só com mais de uma
     * variação.
     *
     * ⚠️ Decisão do usuário de 15/10/2026: o "pior caso entre N variações" é **BALÃO** (`title`), e
     * a frase visível é da **Fase 188**, onde há espaço. **Não é pendência.**
     */
    public function test_o_preco_e_UMA_linha_e_o_pior_caso_vai_no_BALAO_187_08(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();
        $this->comDadosEfetivos(['preco' => 1200.0]);

        // Com UM preço só: `variante` é `null` e NÃO há nota nenhuma.
        $umSo = $this->servico()->para($this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO))['preco'];
        $this->assertSame('R$ 1.200,00', $umSo['valor']);
        $this->assertNull($umSo['titulo'], 'com um preço só NÃO há nota (decisão 11)');
        $this->assertSame(1, $umSo['ativos']);

        // ⚠️ A célula NÃO tem sub-linha: só `valor`, `titulo` e `ativos`.
        $this->assertSame(['valor', 'titulo', 'ativos'], array_keys($umSo), 'a célula de Preço ganhou campo de sub-linha');

        // ⚠️ E o literal do balão, para o 187-08 e o 188 falarem a mesma língua.
        $this->assertSame('pior caso entre ', LinhaDaOfertaService::PIOR_CASO_PREFIXO);
        $this->assertSame(' variações · ', LinhaDaOfertaService::PIOR_CASO_SUFIXO);

        // ⚠️ ISCA DA MONTAGEM: o balão montado com 3 variações e a chave da variante.
        $this->assertSame(
            'pior caso entre 3 variações · COR:Azul',
            LinhaDaOfertaService::PIOR_CASO_PREFIXO.'3'.LinhaDaOfertaService::PIOR_CASO_SUFIXO.'COR:Azul',
        );

        // ⚠️⚠️ **ZERO É AUSENTE, NÃO É PREÇO — e este caso ACHOU UM BUG REAL no meu serviço.**
        //
        // `precoDoAlvo()` devolve **`0.0`** (não `null`) quando a Precificação traz zero, e a
        // primeira redação do serviço testava só `=== null`: a célula exibia **`R$ 0,00`**, que
        // afirma que o produto é **de graça**. É exatamente o que o README §D proíbe (*"custo ou
        // frete ausente não vira zero"*).
        // ⚠️ A régua é a MESMA que a Fase 186 já usa: `ComposicaoFinanceiraService:228` trata
        // `$preco === null || $preco <= 0` como "sem preço não há composição nenhuma". Copiar só
        // metade da condição faria a célula de Preço discordar da composição ao lado.
        $this->comDadosEfetivos(['preco' => 0.0]);
        $semPreco = $this->servico()->para($this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO))['preco'];
        $this->assertNotSame('R$ 0,00', $semPreco['valor'], 'preço ZERO não pode virar R$ 0,00 — zero afirmaria que é de graça');
        $this->assertSame(LinhaDaOfertaService::MARGEM_SEM_NUMERO, $semPreco['valor'], 'preço ausente é travessão');
        $this->assertSame('—', $semPreco['valor']);

        // ⚠️ ISCA: a régua deste caso é a MESMA da composição — e ela é LIDA, não afirmada.
        $semComposicao = $this->servico()->para($this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO));
        $this->assertSame(
            VocabularioDoPortao::INCOMPLETO,
            $semComposicao['resultado']['valor'],
            'com preço zero a composição também tem de dizer Incompleto — as duas réguas são a mesma',
        );

        // ⚠️ E o negativo, que o schema não proíbe: também é ausente, nunca `− R$ 5,00`.
        $this->comDadosEfetivos(['preco' => -5.0]);
        $negativo = $this->servico()->para($this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO))['preco'];
        $this->assertSame(LinhaDaOfertaService::MARGEM_SEM_NUMERO, $negativo['valor'], 'preço negativo é ausente');
    }

    /**
     * `brl()`/`pct()` reproduzem o protótipo (`:1044`/`:1045`) — inclusive o **U+2212** no
     * negativo, que **não** é o hífen-menos.
     *
     * ⚠️ Esta é a QUARTA cópia privada da forma neste módulo, e é deliberada: extrair uma única
     * exigiria alterar os TRÊS arquivos da Fase 186, que são território INTOCÁVEL. ➡️ Este caso é
     * o que torna a cópia auditável.
     */
    public function test_o_dinheiro_sai_no_formato_do_prototipo_nas_QUATRO_formas_187_08(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();

        $formas = [];
        foreach ([[1200.0, 200.0], [100.0, 900.0]] as [$preco, $custo]) {
            // Flag DENTRO do laço.
            $this->comDadosEfetivos(['preco' => $preco, 'custo' => $custo]);
            $linha = $this->servico()->para($this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO));
            $formas[] = $linha['resultado']['valor'];
            $formas[] = $linha['preco']['valor'];
        }

        // Milhar com PONTO, decimal com VÍRGULA, e o sinal U+2212 só no negativo.
        foreach ($formas as $forma) {
            $this->assertMatchesRegularExpression(
                '/^(\x{2212} )?R\$ \d{1,3}(\.\d{3})*,\d{2}$/u',
                $forma,
                "o formato de dinheiro divergiu do protótipo: {$forma}",
            );
        }

        // ⚠️ ISCA DA REGEX, nas duas direções: ela SABE aceitar a forma certa e recusar as erradas.
        $this->assertMatchesRegularExpression('/^(\x{2212} )?R\$ \d{1,3}(\.\d{3})*,\d{2}$/u', 'R$ 1.200,00');
        $this->assertMatchesRegularExpression('/^(\x{2212} )?R\$ \d{1,3}(\.\d{3})*,\d{2}$/u', "\u{2212} R$ 800,00");
        $this->assertDoesNotMatchRegularExpression('/^(\x{2212} )?R\$ \d{1,3}(\.\d{3})*,\d{2}$/u', '- R$ 800,00');
        $this->assertDoesNotMatchRegularExpression('/^(\x{2212} )?R\$ \d{1,3}(\.\d{3})*,\d{2}$/u', 'R$ 1200.00');

        // E pelo menos um NEGATIVO entrou na varredura — senão o U+2212 não foi medido.
        $this->assertTrue(
            (bool) array_filter($formas, static fn (string $f): bool => str_contains($f, "\u{2212}")),
            'ISCA CEGA: nenhuma forma negativa na varredura — o U+2212 não foi medido',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 3. FOTOS, RESPONSÁVEL, ANDAMENTO E AÇÃO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /** As fotos (`:1244`): `N fotos · M IA` + o resumo colorido nos TRÊS casos. */
    public function test_as_fotos_sao_N_fotos_M_IA_com_o_resumo_nos_TRES_casos_187_08(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();
        $this->comDadosEfetivos();

        // (a) Todas aprovadas → verde.
        $todas = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($todas, 0);
        $this->imagem($todas, 1, ['tipo' => PubAprovacaoImagem::TIPO_IA]);
        $daA = $this->servico()->para($todas)['fotos'];
        $this->assertSame('2 fotos · 1 IA', $daA['resumo'], 'o literal de `:1244`');
        $this->assertSame(LinhaDaOfertaService::FOTOS_TODAS_APROVADAS, $daA['sub']);
        $this->assertSame('Todas aprovadas', $daA['sub']);
        $this->assertSame(LinhaDaOfertaService::COR_VERDE, $daA['sub_cor']);
        $this->assertCount(2, $daA['quadrados'], 'os quadrados são a galeria final');

        // (b) Pendente SEM alerta → âmbar, "N a conferir".
        $pend = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($pend, 0);
        $this->imagem($pend, 1, ['estado' => PubAprovacaoImagem::PENDENTE]);
        $this->imagem($pend, 2, ['estado' => PubAprovacaoImagem::PENDENTE]);
        $daB = $this->servico()->para($pend)['fotos'];
        $this->assertSame('2 a conferir', $daB['sub']);
        $this->assertSame(LinhaDaOfertaService::COR_AMBAR, $daB['sub_cor']);

        // (c) COM alerta → vermelho, "N com alerta" (e ele GANHA do "a conferir").
        $alerta = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($alerta, 0);
        $this->imagem($alerta, 1, ['estado' => PubAprovacaoImagem::PENDENTE, 'alerta' => PubAprovacaoImagemAlerta::ALTO]);
        $this->imagem($alerta, 2, ['estado' => PubAprovacaoImagem::PENDENTE]);
        $daC = $this->servico()->para($alerta)['fotos'];
        $this->assertSame('1 com alerta', $daC['sub'], 'o alerta GANHA do "a conferir"');
        $this->assertSame(LinhaDaOfertaService::COR_VERMELHA, $daC['sub_cor']);

        // ⚠️ As três cores são DIFERENTES.
        $this->assertSame(3, count(array_unique([$daA['sub_cor'], $daB['sub_cor'], $daC['sub_cor']])));
    }

    /**
     * O responsável (`:1247`): o nome, a espera FORMATADA pela fórmula pública do 187-05, e ⚠️ **a
     * fronteira EXATA de 1440 min — `== 1440` NÃO é âmbar**.
     */
    public function test_o_responsavel_fica_ambar_ACIMA_de_1440_min_e_nao_EM_1440_187_08(): void
    {
        $empresa = Company::factory()->create();
        $pessoa = User::factory()->create(['name' => 'Ana Ribeiro']);
        $this->comTarifa();
        $this->comDadosEfetivos();

        // `now()` está parado em 2026-10-16 09:00:00.
        $casos = [
            // [entrou_na_fila_em, minutos esperados, âmbar?]
            ['2026-10-16 08:30:00', 30, false],
            ['2026-10-16 05:00:00', 240, false],
            // ⚠️ EXATAMENTE 1440 min (24 h) — NÃO é âmbar.
            ['2026-10-15 09:00:00', 1440, false],
            // ⚠️ 1441 min — É âmbar. A fronteira é ESTRITAMENTE maior.
            ['2026-10-15 08:59:00', 1441, true],
            ['2026-10-13 09:00:00', 4320, true],
        ];

        $errados = [];
        foreach ($casos as [$quando, $minutos, $ambar]) {
            // Flag DENTRO do laço.
            $oferta = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
                'espera' => $quando, 'responsavel' => $pessoa,
            ]);
            $celula = $this->servico()->para($oferta)['responsavel'];
            $esperada = $ambar ? LinhaDaOfertaService::COR_AMBAR : LinhaDaOfertaService::COR_NEUTRA;

            if ($celula['espera_minutos'] !== $minutos) {
                $errados[] = "{$quando}: {$celula['espera_minutos']} min, esperava {$minutos}";
            }
            if ($celula['espera_cor'] !== $esperada) {
                $errados[] = "{$quando}: cor {$celula['espera_cor']}, esperava {$esperada}";
            }
        }

        $this->assertSame([], $errados, 'a fronteira de 1440 min divergiu de `:1247`');
        $this->assertSame(1440, LinhaDaOfertaService::ESPERA_AMBAR_ACIMA_DE);

        // ⚠️ A espera chega FORMATADA, pela fórmula PÚBLICA do 187-05 — copiá-la aqui criaria duas
        // verdades para o mesmo número na mesma tela.
        $oferta = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'espera' => '2026-10-13 09:00:00', 'responsavel' => $pessoa,
        ]);
        $celula = $this->servico()->para($oferta)['responsavel'];
        $this->assertSame('Ana Ribeiro', $celula['nome']);
        $this->assertSame(
            'há '.app(ProximoARevisarService::class)->rotuloDaEspera(4320),
            $celula['espera'],
            'a espera não vem da fórmula pública do 187-05',
        );
        // ⚠️ "3 dias" por EXTENSO (decisão do usuário de 16/10): `min`/`h` ficam abreviados.
        $this->assertSame('há 3 dias', $celula['espera']);

        // ⚠️ Sem responsável e sem carimbo, NADA é inventado.
        $sem = $this->servico()->para($this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['espera' => null]))['responsavel'];
        $this->assertNull($sem['nome'], 'sem responsável não se inventa nome');
        $this->assertNull($sem['espera'], 'sem carimbo não se inventa espera');
        $this->assertNull($sem['espera_minutos']);
        $this->assertSame(LinhaDaOfertaService::COR_NEUTRA, $sem['espera_cor'], 'espera desconhecida não é âmbar');
    }

    /**
     * A sub-linha do andamento — a cadeia de SETE casos (`:1232`-`:1239`) e as QUATRO cores
     * (`:1238`).
     *
     * ⚠️ O `+N` conta os **DEMAIS**, e quem monta é `resumoDoRodape()` da Fase 186, **CHAMADO**.
     */
    public function test_a_sublinha_do_andamento_tem_SETE_casos_e_QUATRO_cores_187_08(): void
    {
        $empresa = Company::factory()->create();
        $aprovador = User::factory()->create(['name' => 'Bruno Lima']);
        $this->comTarifa();
        $this->comDadosEfetivos();
        $servico = $this->servico();

        // (1) falha → literal + vermelho.
        $falha = $servico->para($this->oferta($empresa, PubAprovacaoOferta::FALHA))['andamento'];
        $this->assertSame('Revisão preservada · pode tentar de novo', $falha['sub']);
        $this->assertSame(LinhaDaOfertaService::COR_VERMELHA, $falha['sub_cor']);

        // (2) aprovado → "aguardando publicação · <quem>".
        $aprovado = $servico->para($this->oferta($empresa, PubAprovacaoOferta::APROVADO, ['aprovador' => $aprovador]))['andamento'];
        $this->assertSame('aguardando publicação · Bruno Lima', $aprovado['sub']);
        $this->assertSame(LinhaDaOfertaService::COR_APAGADA, $aprovado['sub_cor']);

        // ⚠️ Sem nome, nada é inventado: o prefixo sai APARADO, nunca "· " solto.
        $semNome = $servico->para($this->oferta($empresa, PubAprovacaoOferta::APROVADO))['andamento'];
        $this->assertSame('aguardando publicação', $semNome['sub']);
        $this->assertStringEndsNotWith('·', $semNome['sub']);
        $this->assertStringEndsNotWith(' ', $semNome['sub']);

        // (3) publicando.
        $publicando = $servico->para($this->oferta($empresa, PubAprovacaoOferta::PUBLICANDO))['andamento'];
        $this->assertSame('enviando ao Mercado Livre', $publicando['sub']);

        // (4) publicado → o MLB, ou "confirmado"; verde nos dois.
        $comMlb = $servico->para($this->oferta($empresa, PubAprovacaoOferta::PUBLICADO, ['mlb' => 'MLB1234567890']))['andamento'];
        $this->assertSame('MLB1234567890', $comMlb['sub']);
        $this->assertSame(LinhaDaOfertaService::COR_VERDE, $comMlb['sub_cor']);
        $semMlb = $servico->para($this->oferta($empresa, PubAprovacaoOferta::PUBLICADO))['andamento'];
        $this->assertSame('confirmado', $semMlb['sub']);

        // (5) preparando → a frase REAL do que falta (AUTO-01); sem ela, NADA.
        $preparando = $servico->para($this->oferta($empresa, PubAprovacaoOferta::PREPARANDO, ['preparo' => 'montando a ficha técnica']))['andamento'];
        $this->assertSame('montando a ficha técnica', $preparando['sub']);
        $this->assertNull($servico->para($this->oferta($empresa, PubAprovacaoOferta::PREPARANDO))['sub'] ?? null);

        // (7) nada bloqueia → "Pronto para aprovar" em VERDE.
        $pronta = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($pronta, 0);
        $daPronta = $servico->para($pronta)['andamento'];
        $this->assertSame('Pronto para aprovar', $daPronta['sub']);
        $this->assertSame(LinhaDaOfertaService::COR_VERDE, $daPronta['sub_cor']);

        // (6) COM bloqueio → `resumoDoRodape()` da Fase 186, com o `+N` dos DEMAIS.
        //
        // ⚠️ A oferta abaixo tem DOIS bloqueios: `fotos_a_conferir` (uma foto pendente) e
        // `sem_fotos` NÃO (há foto)… então o segundo vem do frete ausente.
        $this->comDadosEfetivos(['frete' => null]);
        $bloqueada = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->imagem($bloqueada, 0, ['estado' => PubAprovacaoImagem::PENDENTE]);
        $daBloqueada = $this->servico()->para($bloqueada)['andamento'];

        $this->assertStringStartsWith(VocabularioDoPortao::BLOQUEADO_PREFIXO, $daBloqueada['sub']);
        $this->assertStringStartsWith('Bloqueado: ', $daBloqueada['sub']);
        // ⚠️ O `+N` conta os DEMAIS: com 2 bloqueios a linha é "… +1".
        $this->assertStringEndsWith(' +1', $daBloqueada['sub'], 'o `+N` deveria contar os DEMAIS, não o total');
        $this->assertSame(LinhaDaOfertaService::COR_AMBAR, $daBloqueada['sub_cor']);

        // ⚠️ ISCA: a frase é EXATAMENTE a que a Fase 186 monta — chamada, não recopiada.
        $leitura = (new BloqueiosDaOfertaService(app(ComposicaoFinanceiraService::class), app(AjustesDaOfertaService::class)))
            ->para($bloqueada);
        $this->assertSame($leitura['resumo'], $daBloqueada['sub'], 'a sub-linha divergiu de `resumoDoRodape()`');
        $this->assertCount(2, $leitura['bloqueios'], 'a fixture não discrimina: precisa de DOIS bloqueios para o `+1`');
    }

    /**
     * A ação (`:1240`): o rótulo vem do mapa `CTA` do **MODELO** e ⚠️ **`Revisar` é AMARELO só na
     * oferta PRÓXIMA** (T-187-85).
     */
    public function test_a_acao_le_o_CTA_do_modelo_e_Revisar_e_amarelo_SO_na_proxima_187_08(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();
        $this->comDadosEfetivos();
        $servico = $this->servico();

        $paletas = [
            PubAprovacaoOferta::FALHA => LinhaDaOfertaService::PALETA_DESTAQUE,
            PubAprovacaoOferta::APROVADO => LinhaDaOfertaService::PALETA_VER,
            PubAprovacaoOferta::PUBLICANDO => LinhaDaOfertaService::PALETA_VER,
            PubAprovacaoOferta::PUBLICADO => LinhaDaOfertaService::PALETA_VER,
            PubAprovacaoOferta::PREPARANDO => LinhaDaOfertaService::PALETA_AGUARDE,
            PubAprovacaoOferta::AGUARDANDO_APROVACAO => LinhaDaOfertaService::PALETA_NEUTRA,
            PubAprovacaoOferta::EM_REVISAO_ECF => LinhaDaOfertaService::PALETA_NEUTRA,
            PubAprovacaoOferta::AJUSTES_SOLICITADOS => LinhaDaOfertaService::PALETA_NEUTRA,
        ];
        $this->assertCount(8, $paletas, 'os 8 andamentos mudaram de número');

        $errados = [];
        foreach ($paletas as $andamento => $paleta) {
            // Flag DENTRO do laço.
            $oferta = $this->oferta($empresa, $andamento);
            $acao = $servico->acao($oferta, false);

            // ⚠️ O rótulo é LIDO do mapa do MODELO, nunca recopiado.
            if ($acao['rotulo'] !== PubAprovacaoOferta::CTA[$andamento]) {
                $errados[] = "{$andamento}: rótulo {$acao['rotulo']}, esperava ".PubAprovacaoOferta::CTA[$andamento];
            }
            if ($acao['paleta'] !== $paleta) {
                $errados[] = "{$andamento}: paleta {$acao['paleta']}, esperava {$paleta}";
            }
        }

        $this->assertSame([], $errados, 'a ação divergiu de `:1240` ou do mapa `CTA`');

        // ⚠️⚠️ `Revisar` AMARELO **só** na próxima.
        $aguardando = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $naoProxima = $servico->acao($aguardando, false);
        $proxima = $servico->acao($aguardando, true);

        $this->assertSame('Revisar', $naoProxima['rotulo']);
        $this->assertSame('Revisar', $proxima['rotulo'], 'o RÓTULO é o mesmo; o que muda é a paleta');
        $this->assertSame(LinhaDaOfertaService::PALETA_NEUTRA, $naoProxima['paleta']);
        $this->assertSame(LinhaDaOfertaService::PALETA_DESTAQUE, $proxima['paleta']);
        $this->assertNotSame($naoProxima['paleta'], $proxima['paleta'], 'as duas paletas colapsaram');

        // ⚠️ ISCA: `ehProxima` NÃO muda a paleta de quem já tem paleta própria. Sem isto, um
        // `ehProxima` que sobrescrevesse tudo pintaria "Ver" e "Aguarde" de amarelo.
        $this->assertSame(
            LinhaDaOfertaService::PALETA_VER,
            $servico->acao($this->oferta($empresa, PubAprovacaoOferta::PUBLICADO), true)['paleta'],
            '`ehProxima` sobrescreveu a paleta de `Ver`',
        );
        $this->assertSame(
            LinhaDaOfertaService::PALETA_AGUARDE,
            $servico->acao($this->oferta($empresa, PubAprovacaoOferta::PREPARANDO), true)['paleta'],
            '`ehProxima` sobrescreveu a paleta de `Aguarde`',
        );
    }

    /**
     * ⚠️⚠️ **DIVERGÊNCIA D9, reproduzida e DECLARADA:** `preparando` **não abre** a linha, mas
     * **entra** no que o checkbox do cabeçalho marca.
     *
     * Medido: `ids.push(o.id)` é `:1226` (sem filtro) e `this._nav = ids.filter(… !== 'preparing')`
     * é `:1263`. ➡️ "Consertar" isto mudaria o comportamento do lote sem ninguém pedir.
     *
     * Mais os 5 fundos (`:1250`) na PRECEDÊNCIA do ternário.
     */
    public function test_preparando_NAO_abre_mas_ENTRA_no_lote_e_os_fundos_tem_precedencia_187_08(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa();
        $this->comDadosEfetivos();
        $servico = $this->servico();

        // ⚠️ `abre` é FALSE só em `preparando`; `publicado` e `falha` abrem em LEITURA.
        $errados = [];
        foreach (array_keys(PubAprovacaoOferta::ANDAMENTOS) as $andamento) {
            $oferta = $this->oferta($empresa, $andamento);
            $esperado = $andamento !== PubAprovacaoOferta::PREPARANDO;

            if ($servico->abre($oferta) !== $esperado) {
                $errados[] = $andamento.': abre='.($servico->abre($oferta) ? '1' : '0');
            }
        }
        $this->assertSame([], $errados, '`abre` divergiu de `:1252`');

        // ⚠️⚠️ **E `abre` NÃO é `abreRevisao()`** — a diferença é medida: `abreRevisao()` é
        // `ehLive()` (os TRÊS ao vivo), e `abre` é "tudo menos preparando" (OITO menos um).
        $publicada = $this->oferta($empresa, PubAprovacaoOferta::PUBLICADO);
        $this->assertTrue($servico->abre($publicada), 'publicado abre em LEITURA');
        $this->assertFalse($publicada->abreRevisao(), 'publicado NÃO é editável — as duas perguntas são diferentes');

        // Os 5 fundos, na PRECEDÊNCIA: aberta > foco > marcada > padrão.
        $oferta = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->assertSame(LinhaDaOfertaService::FUNDO_PADRAO, $servico->para($oferta)['fundo']);
        $this->assertSame(LinhaDaOfertaService::FUNDO_MARCADA, $servico->para($oferta, false, ['marcada' => true])['fundo']);
        $this->assertSame(LinhaDaOfertaService::FUNDO_FOCO, $servico->para($oferta, false, ['foco' => true])['fundo']);
        $this->assertSame(LinhaDaOfertaService::FUNDO_ABERTA, $servico->para($oferta, false, ['aberta' => true])['fundo']);

        // ⚠️ A PRECEDÊNCIA, provada com DOIS estados ativos ao mesmo tempo.
        $this->assertSame(
            LinhaDaOfertaService::FUNDO_ABERTA,
            $servico->para($oferta, false, ['aberta' => true, 'foco' => true, 'marcada' => true])['fundo'],
            'aberta deveria ganhar de foco e de marcada',
        );
        $this->assertSame(
            LinhaDaOfertaService::FUNDO_FOCO,
            $servico->para($oferta, false, ['foco' => true, 'marcada' => true])['fundo'],
            'foco deveria ganhar de marcada',
        );

        // ⚠️ Truthiness não vale: `'sim'`/`1`/`{}` não acendem fundo nenhum.
        foreach (['sim', 1, [], 0.0] as $valor) {
            $this->assertSame(
                LinhaDaOfertaService::FUNDO_PADRAO,
                $servico->para($oferta, false, ['aberta' => $valor])['fundo'],
                'o fundo acendeu por truthiness',
            );
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 4. ⚠️⚠️ O CUSTO — o gate mais importante deste plano
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * `para()` é chamado **exatamente UMA vez por oferta** (espião), e no máximo **UMA requisição
     * a `/sites/MLB/listing_prices` por trio `(categoria, tipo, preço)` distinto** — com **isca
     * nas duas direções**.
     *
     * ⚠️ O pré-aquecimento do plano **187-10** muda **QUANDO** a requisição acontece, **não
     * QUANTAS**: este caso continua valendo depois dele, sem afrouxamento.
     */
    public function test_CUSTO_para_e_uma_chamada_por_oferta_e_uma_requisicao_por_trio_187_08(): void
    {
        $empresa = Company::factory()->create();
        $this->comTarifa(self::CLASSICO);
        $this->comTarifa(self::PREMIUM);
        $this->comDadosEfetivos(['preco' => 1200.0]);

        // ─── O espião: SUBCLASSE, não mock de string ───
        //
        // ⚠️ Ele herda a assinatura real, então renomear `para()` no serviço da Fase 186 quebra
        // este teste em vez de deixá-lo passar calado.
        $espiao = new class(app(ComposicaoFinanceiraService::class), app(AjustesDaOfertaService::class)) extends BloqueiosDaOfertaService
        {
            public int $chamadas = 0;

            /** @var list<int> */
            public array $ofertasVistas = [];
        };

        $real = new class(app(ComposicaoFinanceiraService::class), app(AjustesDaOfertaService::class)) extends BloqueiosDaOfertaService
        {
            public int $chamadas = 0;

            public array $ofertasVistas = [];

            public function para(PubAprovacaoOferta $oferta): array
            {
                $this->chamadas++;
                $this->ofertasVistas[] = (int) $oferta->id;

                return parent::para($oferta);
            }
        };
        $this->app->instance(BloqueiosDaOfertaService::class, $real);

        // Seis ofertas: TRÊS do mesmo trio (mesma categoria+tipo+preço) e TRÊS do outro tipo.
        $ofertas = [];
        foreach ([self::CLASSICO, self::CLASSICO, self::CLASSICO, self::PREMIUM, self::PREMIUM, self::PREMIUM] as $tipo) {
            $ofertas[] = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['tipo' => $tipo]);
        }

        $servico = $this->servico();
        foreach ($ofertas as $oferta) {
            $servico->para($oferta);
        }

        // ⚠️ UMA chamada por oferta — e a lista de ids provada SEM repetição.
        $this->assertSame(6, $real->chamadas, '`para()` deveria ser chamado UMA vez por oferta');
        $this->assertSame(
            count($real->ofertasVistas),
            count(array_unique($real->ofertasVistas)),
            '`para()` foi chamado DUAS vezes para a mesma oferta',
        );

        // ⚠️⚠️ No máximo UMA requisição por TRIO distinto. São DOIS trios (dois tipos).
        $trios = [];
        foreach ($this->chamadas as $chamada) {
            if ($chamada['caminho'] !== '/sites/MLB/listing_prices') {
                continue;
            }
            $trios[] = ($chamada['query']['category_id'] ?? '').'|'
                .($chamada['query']['listing_type_id'] ?? '').'|'
                .($chamada['query']['price'] ?? '');
        }

        $this->assertSame(
            count(array_unique($trios)),
            count($trios),
            'saiu mais de UMA requisição para o MESMO trio — o cache por (categoria, tipo, preço) furou',
        );

        // ⚠️ ISCA DIREÇÃO 1 — trio IGUAL dedupa: 6 ofertas, 2 trios, no máximo 2 requisições.
        $this->assertLessThanOrEqual(2, $this->requisicoesDeTarifa(), 'o trio igual não dedupou');

        // ⚠️ ISCA DIREÇÃO 2 — trio DIFERENTE **não** dedupa: os dois tipos geraram requisições
        // distintas. Sem esta linha, um cache que devolvesse sempre a mesma coisa passaria.
        $this->assertSame(2, count(array_unique($trios)), 'os dois tipos deveriam ser DOIS trios distintos');

        // ⚠️ ISCA DO ESPIÃO: ele CONTA. Sem isto, "6" poderia ser o valor de um espião cego.
        $antes = $real->chamadas;
        $real->para($ofertas[0]);
        $this->assertSame($antes + 1, $real->chamadas, 'ISCA: o espião não conta');
        $this->assertSame(0, $espiao->chamadas, 'o espião de controle não deveria ter sido usado');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // TRANSVERSAIS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Território e AUSÊNCIAS, lidas do código **SEM COMENTÁRIO**.
     *
     * ⚠️ Ler sem comentário não é zelo: a prosa deste projeto cita os próprios identificadores que
     * diz NÃO usar, e um gate cru reprovaria pelo próprio conserto. ⚠️ **ESTA PROSA CITA DE
     * PROPÓSITO** `lucro líquido`, `lucro_liquido`, `localStorage`, `#FACC15` e `ehKit` — é a ISCA
     * DO LEITOR.
     *
     * ⚠️⚠️ **O gate de "lucro líquido" é recortado à lista CONSTANTE dos arquivos deste plano:**
     * `lucro_liquido` é campo **legítimo** do Portal, e varrer o `app/` inteiro reprovaria trabalho
     * de outra sessão — foi a 5ª ocorrência de "gate mais largo que o invariante" na Fase 186, e
     * ela **barrou um deploy**.
     */
    public function test_territorio_e_ausencias_do_plano_187_08(): void
    {
        $meus = [
            'app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php',
        ];

        $fonte = '';
        foreach ($meus as $rel) {
            $fonte .= $this->semComentarios($rel);
        }

        // ⚠️ ISCA DO LEITOR: o removedor de comentário FUNCIONA. A prosa acima cita os cinco.
        $this->assertStringNotContainsString('lucro', mb_strtolower($fonte), 'VOC-03: "lucro líquido" é proibido');
        $this->assertStringNotContainsString('localStorage', $fonte);
        // ⚠️ TOK-15 — nenhum HEX sai do PHP: a cor é CHAVE SEMÂNTICA, e o nome do token mora no
        // `.jsx` (o `content` do Tailwind NÃO varre `.php`).
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}\b/', $fonte, 'hex no PHP: a classe não seria varrida pelo Tailwind');
        $this->assertStringNotContainsString('->ehKit(', $fonte, 'é `quantidade_kit` e só — ver a divergência declarada');

        // ⚠️ ISCA DAS SONDAS, nas duas direções.
        $this->assertStringContainsString('lucro', mb_strtolower('O lucro líquido do mês'), 'a sonda de lucro está cega');
        $this->assertMatchesRegularExpression('/#[0-9A-Fa-f]{6}\b/', 'color: #FACC15;', 'a sonda de hex está cega');
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}\b/', 'const X = 1;');

        // ⚠️ E o que o código REALMENTE usa está lá: os literais da 186 são CHAMADOS.
        $this->assertStringContainsString('resumoDoRodape', $fonte, 'o resumo do bloqueio tem de ser CHAMADO');
        $this->assertStringContainsString('INCOMPLETO', $fonte, '`Incompleto` tem de ser LIDO do vocabulário');
        $this->assertStringContainsString('ROTULO_RESULTADO', $fonte, 'o rótulo longo tem de ser LIDO do modelo');
        $this->assertStringContainsString('ROTULO_MARGEM', $fonte);
        $this->assertStringContainsString('rotuloDaEspera', $fonte, 'a espera tem de vir da fórmula pública do 187-05');
        $this->assertStringContainsString('cta()', $fonte, 'o rótulo da ação tem de vir do mapa do MODELO');
        $this->assertStringNotContainsString("'Tentar de novo'", $fonte, 'o rótulo da ação foi RECOPIADO em vez de lido');
        $this->assertStringNotContainsString("'Aguarde'", $fonte, 'o rótulo da ação foi RECOPIADO em vez de lido');

        // ─── O ESCOPO DO ASSUNTO ───
        $this->assertSame(1, preg_match(self::ESCOPO, 'feat(187-08): x'), 'o escopo não casa o próprio assunto');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(187-07): x'), 'o escopo casa a wave 7 — ele está largo');
        $this->assertSame(0, preg_match(self::ESCOPO, 'fix(261016-mwe): x'), 'o escopo casa a outra máquina — ele está largo');

        $log = $this->git('git log --format=%h:%s '.self::PARTIDA.'..HEAD');
        $hashes = [];
        foreach (array_filter(explode("\n", $log)) as $linha) {
            $corte = strpos($linha, ':');
            if ($corte !== false && preg_match(self::ESCOPO, substr($linha, $corte + 1)) === 1) {
                $hashes[] = substr($linha, 0, $corte);
            }
        }

        // ⚠️ SEM `return` antecipado: a contagem deste caso NÃO pode depender de quantos commits
        // existem (a lição medida no plano 187-07, onde ela ia de 26 para 70).
        $arquivos = $hashes === []
            ? []
            : array_values(array_filter(array_map(
                'trim',
                explode("\n", $this->git('git show --name-only --format= '.implode(' ', $hashes))),
            )));

        $proibidos = [
            // Os 10 da Fase 186 em `Aprovacao/` (fora de `Fila/`).
            'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
            'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
            'app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php',
            'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php',
            'app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php',
            // Os de `Fila/` das waves 2/5/6/7 — ⚠️ inclusive o `GrupoDaFilaService`, que o 187-08
            // **não pode editar** (é por isso que o slot `linhaDaOferta` nasceu no plano 07).
            'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
            'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php',
            'app/Services/Publicador/Aprovacao/Fila/FiltrosDaFilaService.php',
            'app/Services/Publicador/Aprovacao/Fila/GrupoDaFilaService.php',
            'app/Services/Publicador/Aprovacao/Fila/GaleriaDaOfertaService.php',
            // Os componentes já NO AR.
            'resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/SeloDeAndamentoInterno.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/CabecalhoDaFila.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/FaixaDeAndamento.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/ProximoARevisar.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/FiltrosDaFila.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/PopoverDeFiltro.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/LinhaDeProdutoDaFila.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/SubLinhaDeConjunto.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/QuadradosDeFoto.jsx',
            'resources/js/Components/Mlb/Publicador/publicacoes',
            'resources/js/Components/Mlb/Publicador/identidade',
            'resources/js/Components/Publicador/Mesa',
            'resources/js/Layouts/AppLayout.jsx',
            'resources/js/Pages/Mlb/Publicador/Editor.jsx',
            // Creative Engine, acervo, MCP, Support e os de outras fases.
            'app/Services/Creative',
            'app/Services/Publicador/Criativos',
            'app/Services/Publicador/ImagemAssetService.php',
            'app/Support/Publicador',
            'app/Mcp',
            'app/Services/Portal',
            // Os gates INTOCÁVEIS e os de outras waves.
            'tests/js/publicador-header-zindex-ordem.test.js',
            'tests/js/estrutura-meus-anuncios.test.js',
            'tests/js/publicador-fila-filtros-187.test.js',
            'tests/js/publicador-fila-proximo-187.test.js',
            'tests/js/publicador-fila-tokens-187.test.js',
            'tests/js/publicador-fila-zindex-187.test.js',
            'tests/js/publicador-fila-cabecalho-187.test.js',
            // ⚠️⚠️ `tests/js/publicador-fila-tabela-187.test.js` **SAIU desta lista**, e é
            // DESVIO DECLARADO desta sessão — não descuido. Aquele gate da wave 7 tinha, em
            // `:1009`, `assert.doesNotMatch(FONTE_PAGINA, /linhaDaOferta=/, '`linhaDaOferta` é do
            // plano 187-08')`: uma asserção de AUSÊNCIA cuja PRÓPRIA MENSAGEM nomeava a wave que
            // a tornaria falsa. É o item 6 da régua (*"asserção vazia quando escrita que vira
            // FALSO POSITIVO depois"*), e o slot nasceu NOMEADO E VAZIO no 187-07 justamente para
            // esta wave o preencher.
            // ➡️ Ela foi **INVERTIDA para uma POSITIVA** (`assert.match(…, /linhaDaOferta=\{/)`),
            // que é permanente e mais forte. A CONTAGEM daquele id não muda (uma asserção por
            // uma), então `contagens-187-07.txt` segue INTOCADO e continua na lista abaixo.
            // ⚠️ Nenhuma outra régua da wave 7 reprova o trabalho certo desta: os casos `:747` e
            // `:765` renderizam o COMPONENTE (e continuam valendo), e o gate de território dela é
            // filtrado por `(187-07)`, então os commits desta wave são invisíveis para ele.
            'tests/Unit/Publicador/Aprovacao/GrupoDaFilaServiceTest.php',
            'tests/gates/contagens-187-07.txt',
            // Dependências (T-187-SC), migrations e a camada de tokens.
            'package.json', 'package-lock.json', 'composer.json', 'composer.lock',
            'database/migrations', 'tailwind.config.js',
            '.planning/ROADMAP.md', '.planning/STATE.md',
        ];

        // ⚠️ UMA asserção coletando fantasmas (nunca uma por caminho — isso fazia a contagem
        // crescer junto com a lista, a lição do 187-07).
        $fantasmas = array_values(array_filter(
            $proibidos,
            fn (string $caminho): bool => ! file_exists($this->raiz().'/'.$caminho),
        ));
        $this->assertSame([], $fantasmas, 'caminho inexistente na lista de proibidos — o gate mediria NADA');
        $this->assertFalse(file_exists($this->raiz().'/app/Services/NaoExiste9z7'), 'a isca do fantasma virou caminho de verdade');
        $this->assertTrue(file_exists($this->raiz().'/'.$proibidos[0]), 'o CONTROLE da varredura não existe mais');

        $tocados = array_values(array_filter($arquivos, static function (string $a) use ($proibidos): bool {
            foreach ($proibidos as $p) {
                if ($a === $p || str_starts_with($a, $p.'/')) {
                    return true;
                }
            }

            return false;
        }));
        $this->assertSame([], $tocados, 'este plano saiu do território declarado');

        // ⚠️ ISCA DO PREFIXO: casa o segmento, não o irmão.
        $this->assertTrue(str_starts_with('app/Mcp/X.php', 'app/Mcp/'));
        $this->assertFalse(str_starts_with('app/McpOutraCoisa.php', 'app/Mcp/'));

        // ⚠️ A prova de que o range não está vazio por construção, COLETADA.
        $doPlano = ['Fila/LinhaDaOferta', 'aprovacao/LinhaDaOferta', 'aprovacao/AnelDeProgresso',
            'aprovacao/BarraDeMargem', 'LinhaDaOfertaServiceTest', 'contagens-187-08',
            'publicador-fila-linha-187'];
        $reconhece = static fn (string $a): bool => (bool) array_filter($doPlano, static fn (string $p): bool => str_contains($a, $p));

        $semProva = $hashes !== [] && ! array_filter($arquivos, $reconhece)
            ? ['os commits do escopo não tocaram arquivo deste plano: '.json_encode(array_slice($arquivos, 0, 8))]
            : [];
        $this->assertSame([], $semProva, 'o range do escopo está vazio por construção');

        $this->assertTrue($reconhece('app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php'), 'a prova não reconhece o serviço');
        $this->assertFalse($reconhece('app/Services/Creative/CreativeJuiz.php'), 'a prova reconhece arquivo de OUTRA fase');
    }

    /**
     * ⚠️ A BIJEÇÃO ids ↔ métodos, nos DOIS sentidos.
     *
     * ⚠️⚠️ **O NOME DESTE MÉTODO TEM SUFIXO PRÓPRIO DE PROPÓSITO.** No plano 187-07 um gate ALHEIO
     * (`FechamentoDaFase186Test::os_nove_manifestos_nao_tem_homonimo_que_some_dois_arquivos`) achou
     * que `test_todo_metodo_do_plano_07_…_187_07` era **SUPERSTRING** do homônimo da **Fase 186,
     * plano 07** — e o `--filter` do PHPUnit é regex **NÃO ANCORADO**, então rodar o id da 186
     * casaria os DOIS e **SOMARIA** as contagens. ⚠️ A colisão existe porque as duas fases numeram
     * planos de 01 a 09+: **todo `_0N` desta fase tem um homônimo possível na 186.**
     */
    public function test_bijecao_do_manifesto_da_linha_187_08(): void
    {
        $manifesto = $this->raiz().'/tests/gates/contagens-187-08.txt';
        $this->assertFileExists($manifesto, 'manifesto ausente — os gates deste plano rodariam sem régua');

        $linhas = array_values(array_filter(
            array_map('trim', explode("\n", (string) file_get_contents($manifesto))),
            static fn (string $l): bool => $l !== '' && ! str_starts_with($l, '#'),
        ));
        $this->assertGreaterThanOrEqual(4, count($linhas), 'o manifesto encolheu — algum id desapareceu');

        $filtrosDosIds = [];
        foreach ($linhas as $linha) {
            $partes = preg_split('/\s+/', $linha);
            if (($partes[1] ?? '') === 'php') {
                $filtrosDosIds[$partes[0]] = $partes[2];
            }
        }
        $this->assertNotSame([], $filtrosDosIds, 'nenhum id `php` no manifesto — a bijeção mediria NADA');

        $metodos = array_values(array_filter(
            get_class_methods($this),
            static fn (string $m): bool => str_starts_with($m, 'test_') && str_ends_with($m, '_187_08'),
        ));
        $this->assertGreaterThanOrEqual(8, count($metodos), 'os métodos deste arquivo encolheram');

        $semId = [];
        foreach ($metodos as $metodo) {
            $achou = false;
            foreach ($filtrosDosIds as $filtro) {
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

        $this->assertSame(0, preg_match('/^'.reset($filtrosDosIds).'$/', 'test_metodo_que_ninguem_escreveu_187_08'));
    }
}
