<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\EstruturaPrecificacaoParametros;
use App\Models\PubAprovacaoAjuste;
use App\Models\PubAprovacaoConjunto;
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
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 186, plano 08 — OS **10 ESTADOS** DO PROTÓTIPO contra os bloqueios do servidor.
 *
 * ═══ ⚠️⚠️ O QUE ESTE ARQUIVO AFIRMA, E O QUE ELE **NÃO** AFIRMA ════════════════════════════════
 *
 * **"O protótipo ganha" vale para a FÓRMULA, os RÓTULOS e a ORDEM — NUNCA para os NÚMEROS**
 * (correção de 14/10/2026 ao critério 7 do ROADMAP, commit `2bea797e`). As taxas de `fin()` são
 * **ficção declarada pelo próprio protótipo** (`Aprovacao.dc.html:1064` *"taxa de exemplo"*,
 * `:1066` *"exemplo"*, `src: 'est'`).
 *
 * ➡️ Então o que se afirma por estado é **ESTRUTURA e INVARIANTE**:
 *   · **quais** bloqueios nascem e **em que ORDEM** (`info()`, `:1089-:1097`);
 *   · a **EXCLUSIVIDADE** de `frete_ausente` / `resultado_negativo` / `margem_abaixo_do_minimo`;
 *   · os **TEXTOS** e as **AÇÕES DE CORREÇÃO**, literais do protótipo;
 *   · `kinds` e `aprovavel`.
 * Os valores financeiros vêm das **FONTES REAIS**: a tarifa do
 * `/sites/MLB/listing_prices` (fixtures capturadas em 2026-10-01: `percentage_fee` **11** no
 * Clássico e **14** no Premium) e o imposto dos parâmetros da empresa. **Nenhum número de taxa
 * está escrito no código de produção** (critério 6).
 *
 * ⚠️⚠️ **E ISSO INVERTE ESTADOS.** Medido aqui: `o1` sai de **+R$ 70,82 / 14,46%** (com as taxas de
 * ficção) para **+R$ 20,03 / 4,09%** com as fontes reais — e **inverte de aprovável para
 * bloqueado** por `margem_abaixo_do_minimo`. Exigir os números do protótipo obrigaria a hardcodear
 * `0.12`/`0.17`/`0.06`/`6.75`, que o critério 6 **proíbe**: os dois gates seriam mutuamente
 * exclusivos e o conserto barato seria enfraquecer um.
 *
 * ⚠️ A diferença contra os **+R$ 12,03** registrados no plano 186-07 é a **embalagem**: lá ela
 * entrava com o `pack` do protótipo; aqui ela nasce `Pendente` **sem fonte no repositório**
 * (decisão (7) do usuário) e **não** entra na soma. ➡️ O resultado é **otimista por baixo**, e é
 * exatamente por isso que `loss`/`margin` **disparam sobre ele**.
 *
 * ═══ ⚠️⚠️ A SEÇÃO F DO README É **PROSA**, E SÓ `info()` DECIDE BLOQUEIO ════════════════════════
 *
 * **Medido e confirmado neste arquivo:** o estado `o3`, que o README do desenho chama de *"margem
 * baixa"*, dá **PREJUÍZO** pela cadeia exclusiva (`total R$ 853,47` contra preço `R$ 749,90` ⇒
 * resultado **−R$ 103,57**, margem **−13,81%**) — logo o bloqueio é `resultado_negativo`, e
 * `margem_abaixo_do_minimo` **não nasce**, porque o elo anterior da cadeia já consumiu o fato.
 * ➡️ **Os esperados NÃO saem das duas fontes.** Só de `info()`.
 *
 * ═══ ⚠️ DEGRADAÇÃO EXPLÍCITA (GATE-10) ═════════════════════════════════════════════════════════
 *
 * `design_handoff_publicador/` **não é versionada**. Sem o arquivo no disco cada estado degrada
 * para **`skip` EXPLÍCITO com a razão** — nunca para "conferido".
 *
 * ═══ ⚠️⚠️ DE CADA GATE, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR ═════════════════════════════
 *
 *   · **a ordem dos bloqueios** — alguém reordenar `info()`. O rodapé abrevia **o PRIMEIRO**
 *     (`:1238`), e a ação de correção oferecida primeiro é a dele: reordenar muda o que a pessoa
 *     é mandada fazer, **sem nenhum teste de render acusar**.
 *   · **a exclusividade** — `o4` é o caso que decide: frete ausente **com** custo que daria
 *     prejuízo. Com três `if` ele exibiria "Resultado negativo: R$ …" sobre um número **nulo**.
 *   · **um `test()` por estado, não um laço** — laço sobre a tabela dá UMA asserção para dez
 *     casos: o primeiro que falha esconde os outros nove, e o relatório não diz qual estado
 *     quebrou.
 *   · **nenhum estado aprovável** — ⚠️ **os dez estados do protótipo são TODOS não-aprováveis com
 *     as fontes reais**, e um gate em que o resultado é sempre o mesmo não prova nada. Por isso
 *     existe a **ISCA** `test_isca_…`: ela conserta o único bloqueio de `o5` e exige
 *     `aprovavel === true`.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class BloqueiosContraOPrototipoTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const CATEGORIA = 'MLB189007';

    /** ⚠️ NÃO é versionada: ausente, cada estado dá `skip` EXPLÍCITO. */
    private const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

    /** O imposto REAL dos parâmetros da empresa — **não** os 6% do README do desenho. */
    private const IMPOSTO = 19.0;

    /**
     * Os produtos do protótipo (`PROD`, `:916-920`). ⚠️ `cost` é fonte **real** também lá
     * (`src: 'conf'`, "ERP Bling"): a ficção do `fin()` são as TAXAS, não o custo.
     */
    private const PRODUTOS = ['beny' => 280.0, 'tora' => 95.0, 'oslo' => 120.0];

    /**
     * Os conjuntos do protótipo (`SETS`, `:942-948`) e quais fotos nascem **aprovadas** no
     * `seed()` (`:985-986`). `'todas'` = a galeria inteira aprovada.
     */
    private const SETS = [
        'SB1' => ['prod' => 'beny', 'unidades' => 1, 'imgs' => ['b-frente', 'b-lateral', 'b1-sala', 'b1-tecido', 'b-pes', 'b1-medidas'], 'aprovadas' => 'todas'],
        'SB2' => ['prod' => 'beny', 'unidades' => 2, 'imgs' => ['b2-dupla', 'b-frente', 'b2-sala', 'b-lateral', 'b-pes', 'b2-medidas'], 'aprovadas' => ['b-frente', 'b-lateral', 'b-pes']],
        'ST1' => ['prod' => 'tora', 'unidades' => 1, 'imgs' => ['t-frente', 't1-sala', 't-topo', 't-veio', 't1-medidas'], 'aprovadas' => ['t-frente', 't1-sala', 't-topo']],
        'ST2' => ['prod' => 'tora', 'unidades' => 2, 'imgs' => ['t2-dupla', 't-frente', 't-topo', 't-veio'], 'aprovadas' => 'todas'],
        'SO1' => ['prod' => 'oslo', 'unidades' => 1, 'imgs' => ['o-frente', 'o-lateral', 'o1-sala'], 'aprovadas' => 'todas'],
    ];

    /**
     * As fotos com verificação automática **acima de `ok`** (`IMG`, `:921-941`), medidas por
     * script. ⚠️ `ok` **não é alerta**: é a verificação que passou.
     */
    private const COM_ALERTA = ['b2-sala', 'b2-medidas', 't1-medidas'];

    /**
     * As 10 ofertas do protótipo (`OFF`, `:949-960`) — só o que incide nos bloqueios.
     *
     * `ajuste` reproduz os `comments` abertos do `seed()` (`:987-990`): `c1` em `o6` e `c2` em
     * `o4`, os dois com escopo de **oferta**.
     */
    private const OFERTAS = [
        'o1' => ['set' => 'SB1', 'tipo' => self::CLASSICO, 'preco' => 489.9, 'frete' => 42.9, 'andamento' => 'aguardando_aprovacao', 'titulo' => 'Poltrona Beny Decorativa Veludo Cinza Pés Palito Sala'],
        'o2' => ['set' => 'SB1', 'tipo' => self::PREMIUM, 'preco' => 529.9, 'frete' => 42.9, 'andamento' => 'aguardando_aprovacao', 'titulo' => 'Poltrona Beny Decorativa Veludo Cinza Pés Palito Sala'],
        'o3' => ['set' => 'SB2', 'tipo' => self::CLASSICO, 'preco' => 749.9, 'frete' => 68.5, 'andamento' => 'aguardando_aprovacao', 'titulo' => 'Kit 2 Poltronas Beny Veludo Cinza Decorativas Pés Palito', 'preco_alterado' => ['de' => 799.9, 'para' => 749.9]],
        'o4' => ['set' => 'SB2', 'tipo' => self::PREMIUM, 'preco' => 849.9, 'frete' => null, 'andamento' => 'aguardando_aprovacao', 'titulo' => 'Kit 2 Poltronas Beny Veludo Cinza Decorativas Pés Palito', 'ajuste' => 'Preço Premium'],
        'o5' => ['set' => 'ST1', 'tipo' => self::CLASSICO, 'preco' => 259.9, 'frete' => 29.9, 'andamento' => 'em_revisao_ecf', 'titulo' => 'Mesa Lateral Tora Madeira Maciça Natural 50 cm'],
        'o6' => ['set' => 'ST1', 'tipo' => self::PREMIUM, 'preco' => 279.9, 'frete' => 29.9, 'andamento' => 'ajustes_solicitados', 'titulo' => 'Mesa Lateral Tora Madeira Maciça Natural', 'ajuste' => 'Título'],
        'o7' => ['set' => 'ST2', 'tipo' => self::CLASSICO, 'preco' => 489.9, 'frete' => 44.9, 'andamento' => 'falha', 'titulo' => 'Kit 2 Mesas Laterais Tora Madeira Maciça Natural'],
        'o8' => ['set' => 'ST2', 'tipo' => self::PREMIUM, 'preco' => 519.9, 'frete' => 44.9, 'andamento' => 'aprovado', 'titulo' => 'Kit 2 Mesas Laterais Tora Madeira Maciça Natural'],
        'o9' => ['set' => 'SO1', 'tipo' => self::CLASSICO, 'preco' => 329.9, 'frete' => 32.9, 'andamento' => 'publicando', 'titulo' => 'Cadeira Oslo Off-white Pés de Madeira Sala de Jantar'],
        'o10' => ['set' => 'SO1', 'tipo' => self::PREMIUM, 'preco' => 359.9, 'frete' => 32.9, 'andamento' => 'preparando', 'titulo' => 'Cadeira Oslo Off-white Pés de Madeira Sala de Jantar', 'preparo' => 'calculando frete e composição de preço'],
    ];

    private ?DadosEfetivosService $efetivos = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');

        Http::preventStrayRequests();
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        Http::fake(function (Request $req) {
            $caminho = (string) parse_url($req->url(), PHP_URL_PATH);
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $query);

            if ($caminho === '/sites/MLB/listing_prices') {
                $tipo = (string) ($query['listing_type_id'] ?? '');
                $arquivo = base_path("tests/fixtures-ml/sondagem/publico/categorias/".self::CATEGORIA."/listing_prices_{$tipo}.json");

                if (! is_file($arquivo)) {
                    return Http::response(['message' => 'sem fixture para '.$tipo], 404);
                }

                return Http::response(json_decode((string) file_get_contents($arquivo), true)['resposta'], 200);
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
    // O CENÁRIO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /** ⚠️ GATE-10 — sem o protótipo no disco, `skip` EXPLÍCITO com a razão. Nunca "conferido". */
    private function exigirPrototipo(): void
    {
        if (! is_file(base_path(self::PROTOTIPO))) {
            $this->markTestSkipped(
                'SKIP EXPLÍCITO: `'.self::PROTOTIPO.'` não está no disco (o diretório `design_handoff_publicador/` '
                .'NÃO é versionado). A estrutura esperada deste estado foi medida por LEITURA daquele arquivo e não '
                .'pode ser reconferida agora — isto NÃO é "conferido".'
            );
        }
    }

    /**
     * Monta o conjunto de fotos e as DUAS ofertas do estado pedido, com as fontes financeiras
     * injetadas por tipo de anúncio.
     *
     * ⚠️ As fontes são **por `listing_type_id`** de propósito: no protótipo `o3` tem frete
     * `68.5` e `o4` tem frete **nulo** no MESMO conjunto — é a diferença que faz `o4` ser o caso
     * que decide a exclusividade da cadeia.
     */
    private function estado(string $qual): PubAprovacaoOferta
    {
        $spec = self::OFERTAS[$qual];
        $set = self::SETS[$spec['set']];
        $empresa = Company::factory()->create();

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => strtoupper($spec['set']).'-'.$qual,
            'nome' => 'Produto '.$set['prod'],
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => $set['unidades'],
        ]);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id, 'categoria_id' => self::CATEGORIA]);

        // As duas ofertas do conjunto (Clássico e Premium), com os títulos de cada uma.
        $irmas = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $i => $tipo) {
            $titulo = $spec['tipo'] === $tipo
                ? $spec['titulo']
                : (self::OFERTAS[$this->irmaDe($qual)]['titulo'] ?? $spec['titulo']);

            $alvo = PubRascunhoAlvo::create([
                'rascunho_id' => $rascunho->id,
                'listing_type_id' => $tipo,
                'titulo' => $titulo,
                'posicao' => $i,
            ]);

            $irmas[$tipo] = PubAprovacaoOferta::create([
                'alvo_id' => $alvo->id,
                'rascunho_id' => $rascunho->id,
                'produto_id' => $produto->id,
                'company_id' => $empresa->id,
            ]);
        }

        // O conjunto de fotos COMPARTILHADO (o protótipo tem um por `set`; aqui ele é o `GENERAL`
        // do rascunho, porque nenhum dos 10 estados tem eixo que defina foto).
        $conjunto = PubAprovacaoConjunto::create([
            'rascunho_id' => $rascunho->id,
            'grupo_chave' => PubAprovacaoConjunto::GERAL,
            'grupo_hash' => ChaveCanonica::hash(PubAprovacaoConjunto::GERAL),
        ]);

        foreach ($set['imgs'] as $posicao => $nome) {
            $aprovada = $set['aprovadas'] === 'todas' || in_array($nome, $set['aprovadas'], true);
            $crua = PubImagem::create(['rascunho_id' => $rascunho->id]);
            $img = PubAprovacaoImagem::create([
                'conjunto_id' => $conjunto->id,
                'imagem_id' => $crua->id,
                'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
                'estado' => $aprovada ? PubAprovacaoImagem::APROVADA : PubAprovacaoImagem::PENDENTE,
                'posicao' => $posicao,
            ]);

            if (in_array($nome, self::COM_ALERTA, true)) {
                PubAprovacaoImagemAlerta::create([
                    'aprovacao_imagem_id' => $img->id,
                    'severidade' => PubAprovacaoImagemAlerta::ALTO,
                    'texto' => 'Divergência automática em “'.$nome.'”',
                    'x' => 34.0,
                    'y' => 84.0,
                ]);
            }
        }

        // As fontes financeiras, POR TIPO DE ANÚNCIO.
        $custo = self::PRODUTOS[$set['prod']];
        $fretes = [];
        $precos = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $tipo) {
            $daqui = $spec['tipo'] === $tipo ? $spec : self::OFERTAS[$this->irmaDe($qual)];
            $fretes[$tipo] = ['valor' => $daqui['frete'], 'origem' => $daqui['frete'] === null ? null : 'tabela', 'oferta_id' => null];
            $precos[$tipo] = $daqui['preco'];
        }

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
        // ⚠️ O custo do COMBO é multiplicado pelas unidades dentro do serviço
        // (`quantidade_kit`): aqui vai o custo UNITÁRIO, como no `pr.cost` do protótipo.
        $this->efetivos->osCustos = ['custo' => $custo, 'origem' => 'digitado', 'imposto' => self::IMPOSTO, 'por_variante' => []];
        $this->efetivos->osFretes = ['por_tipo' => $fretes, 'por_variante' => []];
        $this->efetivos->osPrecos = ['precos' => $precos];
        $this->app->instance(DadosEfetivosService::class, $this->efetivos);

        $oferta = $irmas[$spec['tipo']];

        if (isset($spec['preparo'])) {
            $oferta->update(['preparo_frase' => $spec['preparo']]);
        }
        if (isset($spec['preco_alterado'])) {
            $oferta->update(['preco_alterado' => $spec['preco_alterado'] + ['quem' => null, 'quando' => '2026-10-15 10:12:00']]);
        }
        if (isset($spec['ajuste'])) {
            PubAprovacaoAjuste::create([
                'escopo' => PubAprovacaoAjuste::ESCOPO_OFERTA,
                'oferta_id' => $oferta->id,
                'item' => $spec['ajuste'],
                'destino' => PubAprovacaoAjuste::destinoLegivel(PubAprovacaoAjuste::ESCOPO_OFERTA, $spec['tipo']),
                'texto' => 'Pedido do protótipo reproduzido na fixture.',
                'autor_id' => User::factory()->create()->id,
                'aberto' => true,
            ]);
        }

        return $this->levarAo($oferta->fresh(), $spec['andamento']);
    }

    /** O par Clássico↔Premium do protótipo: o1↔o2, o3↔o4, o5↔o6, o7↔o8, o9↔o10. */
    private function irmaDe(string $qual): string
    {
        $pares = ['o1' => 'o2', 'o2' => 'o1', 'o3' => 'o4', 'o4' => 'o3', 'o5' => 'o6', 'o6' => 'o5', 'o7' => 'o8', 'o8' => 'o7', 'o9' => 'o10', 'o10' => 'o9'];

        return $pares[$qual];
    }

    /**
     * Leva a oferta ao andamento do estado **pela ÚNICA PORTA** (`AndamentoDaOfertaService`), um
     * passo por vez e sempre por par declarado de `TRANSICOES`.
     *
     * ⚠️ Nenhum teste deste plano escreve `andamento` direto: a porta é a que valida.
     */
    private function levarAo(PubAprovacaoOferta $oferta, string $destino): PubAprovacaoOferta
    {
        $porta = app(AndamentoDaOfertaService::class);

        if ($destino === PubAprovacaoOferta::PREPARANDO) {
            return $oferta;
        }

        $oferta = $porta->mover($oferta, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $caminho = match ($destino) {
            PubAprovacaoOferta::AGUARDANDO_APROVACAO => [],
            PubAprovacaoOferta::EM_REVISAO_ECF => [PubAprovacaoOferta::EM_REVISAO_ECF],
            PubAprovacaoOferta::AJUSTES_SOLICITADOS => [PubAprovacaoOferta::AJUSTES_SOLICITADOS],
            PubAprovacaoOferta::APROVADO => [PubAprovacaoOferta::APROVADO],
            PubAprovacaoOferta::PUBLICANDO => [PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO],
            PubAprovacaoOferta::FALHA => [PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::FALHA],
            default => throw new \LogicException('andamento sem caminho declarado: '.$destino),
        };

        foreach ($caminho as $passo) {
            $motivo = $passo === PubAprovacaoOferta::FALHA
                ? 'O Mercado Livre não respondeu a tempo durante o envio das imagens.'
                : null;
            $oferta = $porta->mover($oferta, $passo, null, $motivo);
        }

        return $oferta;
    }

    private function servico(): BloqueiosDaOfertaService
    {
        return new BloqueiosDaOfertaService(
            new ComposicaoFinanceiraService($this->efetivos ?? app(DadosEfetivosService::class), app(ClienteMlPublicador::class)),
            app(AjustesDaOfertaService::class),
        );
    }

    /**
     * A conferência comum a todos os dez: a ORDEM dos bloqueios, a exclusividade da cadeia,
     * `kinds`, `aprovavel` e o glifo em cada linha.
     *
     * @param  list<string>  $esperados  as chaves, na ORDEM em que `info()` as empilha
     * @param  list<string>  $kindsExtra  `alerta_em_foto_de_ia` / `falha_na_publicacao`
     * @return array<string, mixed>
     */
    private function conferir(string $qual, array $esperados, array $kindsExtra, bool $aoVivo): array
    {
        $this->exigirPrototipo();

        $oferta = $this->estado($qual);
        $info = $this->servico()->para($oferta);

        // (1) ⚠️ A ORDEM, não só o conjunto: `assertSame` em lista ordenada.
        $this->assertSame($esperados, $info['chaves'], "o estado `{$qual}` divergiu nos bloqueios ou na ORDEM deles");

        // (2) ⚠️ A EXCLUSIVIDADE: no máximo UM dos três elos da cadeia, em QUALQUER estado.
        $daCadeia = array_values(array_intersect(VocabularioDoPortao::CADEIA_EXCLUSIVA, $info['chaves']));
        $this->assertLessThanOrEqual(1, count($daCadeia), "o estado `{$qual}` produziu mais de um bloqueio da cadeia exclusiva: ".implode(', ', $daCadeia));

        // (3) `kinds` = bloqueios + os extras, nessa ordem (`:1098`).
        $this->assertSame(array_merge($esperados, $kindsExtra), $info['kinds'], "os `kinds` do estado `{$qual}` divergiram");

        // (4) `aprovavel = live && zero bloqueios` (`:1099`).
        $this->assertSame($aoVivo, $info['ao_vivo'], "o estado `{$qual}` divergiu em `ao_vivo`");
        $this->assertSame($aoVivo && $esperados === [], $info['aprovavel'], "o estado `{$qual}` divergiu em `aprovavel`");

        // (5) ⚠️ VOC-05 — toda linha tem glifo e severidade; cor nunca é o único sinal.
        foreach ($info['bloqueios'] as $b) {
            $this->assertSame('!', $b['glifo'], "o bloqueio `{$b['chave']}` do estado `{$qual}` saiu sem glifo");
            $this->assertContains($b['severidade'], [VocabularioDoPortao::SEVERIDADE_VERMELHA, VocabularioDoPortao::SEVERIDADE_AMBAR]);
            $this->assertNotSame('', $b['texto'], "o bloqueio `{$b['chave']}` do estado `{$qual}` saiu sem texto");
        }

        return $info;
    }

    /** O texto e a correção de um bloqueio pela chave. */
    private function bloqueio(array $info, string $chave): array
    {
        foreach ($info['bloqueios'] as $b) {
            if ($b['chave'] === $chave) {
                return $b;
            }
        }

        $this->fail("o bloqueio `{$chave}` não está na lista");
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // OS 10 ESTADOS — **um `test()` por estado**, nunca um laço
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_estado_o1_do_prototipo_inverte_de_aprovavel_para_margem_abaixo_do_minimo(): void
    {
        // ⚠️⚠️ A INVERSÃO, medida: com as taxas de ficção do `fin()` o `o1` era **aprovável**
        // (+R$ 70,82 / 14,46%); com as fontes reais (comissão 11% da fixture + imposto 19% dos
        // parâmetros) ele dá **+R$ 20,03 / 4,09%** e fica **BLOQUEADO**.
        $info = $this->conferir('o1', [VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO], [], true);

        $this->assertSame(20.03, $info['composicao']->resultado);
        $this->assertSame(4.09, round((float) $info['composicao']->margem, 2));
        $this->assertSame(12.0, $info['composicao']->margemMinima, 'o mínimo caiu na config (fallback deliberado)');
        $this->assertSame('Margem de 4,1% abaixo do mínimo de 12%', $this->bloqueio($info, VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO)['texto']);
        $this->assertSame('Solicitar exceção', $this->bloqueio($info, VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO)['correcao']);

        // ⚠️ E o resultado é **estimado por baixo**: a embalagem não tem fonte no repositório, e é
        // sobre esse valor OTIMISTA que o bloqueio dispara.
        $this->assertTrue($info['composicao']->estimadoPorBaixo);
        $this->assertStringContainsString('sem o custo de embalagem', $info['composicao']->notaDaMargem);

        // A galeria do SB1 nasce inteira aprovada no `seed()` (`:985`): zero pendentes.
        $this->assertSame(6, $info['na_galeria']);
        $this->assertSame(0, $info['pendentes']);
    }

    public function test_estado_o2_do_prototipo_e_o_premium_com_a_tarifa_real_mais_alta(): void
    {
        // ⚠️ O Premium usa a OUTRA fixture real: `percentage_fee` **14** (contra 11 do Clássico).
        // Nenhum dos dois números está escrito no código de produção.
        $info = $this->conferir('o2', [VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO], [], true);

        $this->assertSame(32.13, $info['composicao']->resultado);
        $this->assertSame(6.06, round((float) $info['composicao']->margem, 2));
        $this->assertSame(round(529.9 * 0.14, 2), $info['composicao']->linha('comissao')->valor, 'a comissão do Premium não veio da fixture `gold_pro`');
        $this->assertSame('Margem de 6,1% abaixo do mínimo de 12%', $this->bloqueio($info, VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO)['texto']);
    }

    public function test_estado_o3_do_prototipo_da_prejuizo_e_nao_margem_baixa_como_diz_a_prosa_do_readme(): void
    {
        // ⚠️⚠️ **A SEÇÃO F DO README É PROSA.** Ela chama o `o3` de "margem baixa"; pela cadeia
        // exclusiva, com as fontes reais, ele dá **PREJUÍZO**. Só `info()` decide bloqueio.
        $info = $this->conferir('o3', [
            VocabularioDoPortao::FOTOS_A_CONFERIR,
            VocabularioDoPortao::RESULTADO_NEGATIVO,
            VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA,
        ], [VocabularioDoPortao::ALERTA_EM_FOTO_DE_IA], true);

        $this->assertSame(-103.57, $info['composicao']->resultado);
        $this->assertSame(-13.81, round((float) $info['composicao']->margem, 2));

        // ⚠️ E `margem_abaixo_do_minimo` **NÃO nasce**, apesar de a margem estar abaixo do mínimo:
        // o elo anterior da cadeia já consumiu o fato.
        $this->assertTrue($info['composicao']->margem < $info['composicao']->margemMinima);
        $this->assertNotContains(VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO, $info['chaves']);

        // Os três textos, literais do protótipo.
        $this->assertSame('3 fotos aguardando conferência · 2 com alerta automático', $this->bloqueio($info, VocabularioDoPortao::FOTOS_A_CONFERIR)['texto']);
        $this->assertSame("Resultado negativo: \u{2212} R$ 103,57 por venda", $this->bloqueio($info, VocabularioDoPortao::RESULTADO_NEGATIVO)['texto']);
        $this->assertSame('Preço alterado após a última conferência: R$ 799,90 → R$ 749,90', $this->bloqueio($info, VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA)['texto']);

        // E as correções (`fix()`, `:1171-:1177`).
        $this->assertSame('Conferir fotos', $this->bloqueio($info, VocabularioDoPortao::FOTOS_A_CONFERIR)['correcao']);
        $this->assertSame('Solicitar exceção', $this->bloqueio($info, VocabularioDoPortao::RESULTADO_NEGATIVO)['correcao']);
        $this->assertSame('Conferir alteração', $this->bloqueio($info, VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA)['correcao']);

        // O rodapé abrevia o PRIMEIRO e conta os demais (`:1238`).
        $this->assertSame('Bloqueado: fotos a conferir +2', $info['resumo']);
    }

    public function test_estado_o4_do_prototipo_e_o_caso_que_decide_a_exclusividade_da_cadeia(): void
    {
        // ⚠️⚠️ **O CASO QUE DECIDE (critério 4).** `o4` tem frete **nulo** e um custo que, se o
        // frete existisse, daria **prejuízo** (custo do combo R$ 560 + comissão 14% + imposto 19%
        // já passa do preço). Com três `if`, a tela exibiria "Resultado negativo: R$ …" sobre um
        // número **nulo** — e, como aprovar já publica e não há desfazer, isso vai ao ar.
        $info = $this->conferir('o4', [
            VocabularioDoPortao::FOTOS_A_CONFERIR,
            VocabularioDoPortao::FRETE_AUSENTE,
            VocabularioDoPortao::AJUSTE_EM_ABERTO,
        ], [VocabularioDoPortao::ALERTA_EM_FOTO_DE_IA], true);

        // ⚠️ NEM prejuízo NEM margem: não há resultado para julgar.
        $this->assertNotContains(VocabularioDoPortao::RESULTADO_NEGATIVO, $info['chaves']);
        $this->assertNotContains(VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO, $info['chaves']);
        $this->assertTrue($info['composicao']->incompleto);
        $this->assertNull($info['composicao']->resultado, 'o resultado virou número onde não há dado');
        $this->assertNull($info['composicao']->margem);

        // ⚠️ A PROVA DE QUE HAVIA MATÉRIA PARA O ELO SEGUINTE — sem ela eu estaria afirmando a
        // exclusividade num cenário em que o prejuízo nunca existiu (negativa vazia por
        // construção). A IRMÃ do mesmo rascunho é o `o3`: MESMO conjunto, MESMO custo de combo, e
        // o frete **informado**. Ela dá **prejuízo**.
        $oferta = $this->ofertaDoEstado('o4');
        $irma = PubAprovacaoOferta::query()
            ->where('rascunho_id', $oferta->rascunho_id)
            ->where('id', '!=', $oferta->id)
            ->firstOrFail();

        $daIrma = $this->servico()->para($irma);
        $this->assertFalse($daIrma['composicao']->incompleto, 'a irmã tem frete informado: a composição dela NÃO é incompleta');
        $this->assertSame(-103.57, $daIrma['composicao']->resultado, 'a irmã (`o3`) tinha de dar prejuízo — é ela que prova que o elo seguinte tinha matéria');
        $this->assertContains(VocabularioDoPortao::RESULTADO_NEGATIVO, $daIrma['chaves'], 'ISCA CEGA: nem com o frete informado o prejuízo nasce — o cenário do `o4` não tinha prejuízo nenhum a suprimir.');

        $this->assertSame('Frete do vendedor não confirmado · resultado indisponível', $this->bloqueio($info, VocabularioDoPortao::FRETE_AUSENTE)['texto']);
        $this->assertSame('Informar frete', $this->bloqueio($info, VocabularioDoPortao::FRETE_AUSENTE)['correcao']);
        $this->assertSame('1 ajuste solicitado em aberto', $this->bloqueio($info, VocabularioDoPortao::AJUSTE_EM_ABERTO)['texto']);
        $this->assertSame('Ver ajustes', $this->bloqueio($info, VocabularioDoPortao::AJUSTE_EM_ABERTO)['correcao']);

        // ⚠️ VOC-05 — `frete_ausente` é VERMELHO (`:1295`), e `ajuste_em_aberto` é âmbar.
        $this->assertSame(VocabularioDoPortao::SEVERIDADE_VERMELHA, $this->bloqueio($info, VocabularioDoPortao::FRETE_AUSENTE)['severidade']);
        $this->assertSame(VocabularioDoPortao::SEVERIDADE_AMBAR, $this->bloqueio($info, VocabularioDoPortao::AJUSTE_EM_ABERTO)['severidade']);
    }

    /** A oferta já montada deste estado — para olhar o grafo sem remontá-lo. */
    private function ofertaDoEstado(string $qual): PubAprovacaoOferta
    {
        $spec = self::OFERTAS[$qual];

        return PubAprovacaoOferta::query()
            ->whereHas('alvo', fn ($q) => $q->where('listing_type_id', $spec['tipo']))
            ->orderByDesc('id')
            ->firstOrFail();
    }

    public function test_estado_o5_do_prototipo_esta_em_revisao_ecf_com_duas_fotos_a_conferir(): void
    {
        $info = $this->conferir('o5', [VocabularioDoPortao::FOTOS_A_CONFERIR], [VocabularioDoPortao::ALERTA_EM_FOTO_DE_IA], true);

        $this->assertSame(PubAprovacaoOferta::EM_REVISAO_ECF, $this->ofertaDoEstado('o5')->andamento);
        $this->assertSame(5, $info['na_galeria']);
        $this->assertSame(2, $info['pendentes']);
        $this->assertSame(1, $info['com_alerta'], 't-veio não tem verificação automática; t1-medidas tem uma `med`');
        $this->assertSame('2 fotos aguardando conferência · 1 com alerta automático', $this->bloqueio($info, VocabularioDoPortao::FOTOS_A_CONFERIR)['texto']);

        // ⚠️ `photos` COM alerta é vermelho (`:1295`).
        $this->assertSame(VocabularioDoPortao::SEVERIDADE_VERMELHA, $this->bloqueio($info, VocabularioDoPortao::FOTOS_A_CONFERIR)['severidade']);

        // A conta financeira deste estado não bloqueia nada — e é por isso que `o5` é o estado da
        // ISCA (ver `test_isca_…`).
        $this->assertSame(57.03, $info['composicao']->resultado);
        $this->assertSame(21.94, round((float) $info['composicao']->margem, 2));
        $this->assertSame('Bloqueado: fotos a conferir', $info['resumo'], 'com um bloqueio só, o rodapé não leva `+N`');
    }

    public function test_estado_o6_do_prototipo_tem_ajuste_aberto_e_esta_em_ajustes_solicitados(): void
    {
        $info = $this->conferir('o6', [
            VocabularioDoPortao::FOTOS_A_CONFERIR,
            VocabularioDoPortao::AJUSTE_EM_ABERTO,
        ], [VocabularioDoPortao::ALERTA_EM_FOTO_DE_IA], true);

        $this->assertSame(PubAprovacaoOferta::AJUSTES_SOLICITADOS, $this->ofertaDoEstado('o6')->andamento);
        $this->assertSame(62.63, $info['composicao']->resultado);
        $this->assertSame('1 ajuste solicitado em aberto', $this->bloqueio($info, VocabularioDoPortao::AJUSTE_EM_ABERTO)['texto']);
        $this->assertSame('Bloqueado: fotos a conferir +1', $info['resumo']);
    }

    public function test_estado_o7_do_prototipo_falhou_e_ganha_o_kind_de_falha_sem_ganhar_bloqueio(): void
    {
        // ⚠️ `falha_na_publicacao` é `kind` (`:1098`), **não** bloqueio: ele entra no filtro
        // "Motivo de bloqueio" e **não** impede nada. Unificá-lo com os bloqueios faria o filtro
        // esconder linhas pelo ESTADO da oferta.
        $info = $this->conferir('o7', [], [VocabularioDoPortao::FALHA_NA_PUBLICACAO], false);

        $oferta = $this->ofertaDoEstado('o7');
        $this->assertSame(PubAprovacaoOferta::FALHA, $oferta->andamento);
        $this->assertFalse($info['aprovavel'], '`falha` não é `live`: a oferta não é aprovável nem com zero bloqueios');

        // ⚠️ BLOQ-11 — a revisão é PRESERVADA: `versao_aprovada` continua carimbada.
        $this->assertNotNull($oferta->versao_aprovada);
        $this->assertSame((int) $oferta->versao, (int) $oferta->versao_aprovada);
        $this->assertFalse($oferta->aprovacaoReaberta());

        // PUB-05 — o erro REAL do ML, não "algo deu errado".
        $this->assertStringContainsString('não respondeu a tempo', (string) $oferta->erro_publicacao);
    }

    public function test_estado_o8_do_prototipo_esta_aprovado_e_nao_e_aprovavel_de_novo(): void
    {
        $info = $this->conferir('o8', [], [], false);

        $oferta = $this->ofertaDoEstado('o8');
        $this->assertSame(PubAprovacaoOferta::APROVADO, $oferta->andamento);
        $this->assertFalse($info['ao_vivo']);
        $this->assertFalse($info['aprovavel'], 'oferta já aprovada voltou a ser aprovável — e aprovar JÁ PUBLICA, sem desfazer');
        $this->assertSame([], $info['chaves'], 'o estado `o8` precisa continuar com ZERO bloqueios para o `live` ser a única variável');
        $this->assertNotNull($oferta->aprovado_em);
        $this->assertSame(113.43, $info['composicao']->resultado);
    }

    public function test_estado_o9_do_prototipo_esta_publicando_e_a_edicao_fica_bloqueada(): void
    {
        $info = $this->conferir('o9', [], [], false);

        $oferta = $this->ofertaDoEstado('o9');
        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $oferta->andamento);
        $this->assertFalse($oferta->podeEditar(), 'PUB-04: edição liberada enquanto a oferta sobe ao Mercado Livre');
        $this->assertFalse($info['aprovavel']);
        $this->assertSame(78.03, $info['composicao']->resultado);
    }

    public function test_estado_o10_do_prototipo_esta_preparando_com_a_frase_real_do_que_falta(): void
    {
        $info = $this->conferir('o10', [VocabularioDoPortao::EM_PREPARACAO], [], false);

        $this->assertSame(PubAprovacaoOferta::PREPARANDO, $this->ofertaDoEstado('o10')->andamento);

        // ⚠️ AUTO-01 — a frase REAL (`o.prep` do protótipo), não um rótulo genérico.
        $this->assertSame('Sistema ainda calculando frete e composição de preço', $this->bloqueio($info, VocabularioDoPortao::EM_PREPARACAO)['texto']);
        $this->assertSame('', $this->bloqueio($info, VocabularioDoPortao::EM_PREPARACAO)['correcao'], 'botão em `em_preparacao` é botão que não resolve nada');

        // ⚠️ AND-03 — `preparando` **não abre revisão**.
        $this->assertFalse($this->ofertaDoEstado('o10')->abreRevisao());
        $this->assertFalse($info['aprovavel']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ A ISCA DA TABELA — SEM ELA, "NENHUM ESTADO É APROVÁVEL" SERIA VERDADE POR ACIDENTE
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_isca_da_tabela_nenhum_dos_dez_e_aprovavel_e_consertar_o_o5_torna_ele_aprovavel(): void
    {
        $this->exigirPrototipo();

        // ⚠️ Fato medido e DECLARADO: com as fontes reais, **nenhum** dos dez estados do protótipo
        // é aprovável. Um gate em que o veredicto é sempre o mesmo não prova nada — por isso esta
        // isca conserta o único bloqueio do `o5` e exige que ele **vire aprovável**.
        $oferta = $this->estado('o5');
        $antes = $this->servico()->para($oferta);
        $this->assertSame([VocabularioDoPortao::FOTOS_A_CONFERIR], $antes['chaves']);
        $this->assertFalse($antes['aprovavel']);
        $this->assertTrue($antes['ao_vivo'], 'o `o5` está em `em_revisao_ecf`, que é `live` — é isso que o torna consertável');

        // Aprovar as duas pendentes do conjunto compartilhado.
        PubAprovacaoImagem::query()
            ->where('estado', PubAprovacaoImagem::PENDENTE)
            ->update(['estado' => PubAprovacaoImagem::APROVADA]);

        $depois = $this->servico()->para($oferta->fresh());
        $this->assertSame([], $depois['chaves'], 'ISCA CEGA: o bloqueio de fotos do `o5` não sai nem com tudo aprovado — a tabela mediria um veredicto constante.');
        $this->assertTrue($depois['aprovavel'], 'ISCA CEGA: nenhum estado consegue ficar aprovável — `aprovavel` pode estar cravado em `false`.');
        $this->assertSame(VocabularioDoPortao::TUDO_CONFERIDO, $depois['resumo']);

        // ⚠️ E a oferta IRMÃ (`o6`) **também** fica sem o bloqueio de fotos: o conjunto é
        // compartilhado por estrutura. Ela continua com o ajuste em aberto, que é dela.
        $irma = PubAprovacaoOferta::query()
            ->where('rascunho_id', $oferta->rascunho_id)
            ->where('id', '!=', $oferta->id)
            ->firstOrFail();
        $this->assertNotContains(VocabularioDoPortao::FOTOS_A_CONFERIR, $this->servico()->para($irma)['chaves'], 'aprovar a foto não valeu para a irmã — o compartilhamento Clássico↔Premium é ESTRUTURAL');
    }

    public function test_a_tabela_cobre_os_dez_estados_e_os_oito_andamentos_do_prototipo(): void
    {
        // ⚠️ GATE DE COBERTURA: gate que roda sobre menos casos do que diz não prova o que diz.
        $this->assertCount(10, self::OFERTAS, 'a tabela deixou de cobrir os 10 estados do protótipo');
        $this->assertCount(5, self::SETS);

        // Os 8 andamentos aparecem na tabela — `aguardando_aprovacao` quatro vezes, os outros uma
        // cada, e `publicado` **não** aparece: nenhum dos 10 estados do protótipo está publicado
        // (o `OFF` não tem `status: 'published'`), e inventar um aqui seria medir o que o
        // protótipo não disse.
        $andamentos = array_values(array_unique(array_column(self::OFERTAS, 'andamento')));
        sort($andamentos);
        $this->assertSame([
            'aguardando_aprovacao', 'ajustes_solicitados', 'aprovado', 'em_revisao_ecf',
            'falha', 'preparando', 'publicando',
        ], $andamentos);
        $this->assertNotContains(PubAprovacaoOferta::PUBLICADO, $andamentos, 'o `OFF` do protótipo não tem estado publicado — inventá-lo mediria o que ele não disse');

        // Todos os andamentos da tabela são chaves REAIS do modelo (erro de digitação aqui faria
        // `levarAo()` lançar, mas só no estado afetado).
        foreach ($andamentos as $a) {
            $this->assertArrayHasKey($a, PubAprovacaoOferta::ANDAMENTOS, "`{$a}` não é um andamento do modelo");
        }

        // ⚠️ E os títulos dos dez: **nenhum** passa de 60 caracteres, medido por `mb_strlen`. É
        // por isso que `titulo_longo` não aparece em estado nenhum — e esta linha é o que impede
        // alguém de "descobrir" que o bloqueio de título nunca foi exercitado aqui (ele é
        // exercitado em `BloqueiosDaOfertaServiceTest`, com 60 e com 61).
        foreach (self::OFERTAS as $qual => $spec) {
            $this->assertLessThanOrEqual(60, mb_strlen($spec['titulo']), "o título do estado `{$qual}` passou de 60 — a tabela ganharia um bloqueio que o protótipo não tem");
        }

        // As fotos com alerta são as TRÊS medidas no `IMG` do protótipo.
        $this->assertSame(['b2-sala', 'b2-medidas', 't1-medidas'], self::COM_ALERTA);

        // ⚠️ O imposto da fixture é o REAL dos parâmetros da empresa — **não** os 6% do README do
        // desenho. Esta linha prende os dois juntos: se `PADROES` mudar, a tabela é remedida em
        // vez de continuar afirmando números de outra época.
        $this->assertSame(EstruturaPrecificacaoParametros::PADROES['imposto'], self::IMPOSTO, 'o imposto da fixture divergiu do parâmetro REAL — os resultados da tabela são de outra época');
        $this->assertNotSame(6.0, self::IMPOSTO, 'os 6% do README do desenho não são o imposto real deste projeto');

        // E os produtos: o custo é fonte REAL também no protótipo (`src: conf`, "ERP Bling").
        $this->assertSame(['beny' => 280.0, 'tora' => 95.0, 'oslo' => 120.0], self::PRODUTOS);

        // ⚠️ GATE-10 — ISCA DA DEGRADAÇÃO: o predicado do `skip` **não** é constante. Hoje o
        // protótipo está no disco (logo os dez estados foram de fato CONFERIDOS, e não "skipados
        // em silêncio"); e o mesmo predicado dá `false` para um caminho que não existe. Sem a
        // segunda linha, um `is_file()` quebrado faria os dez estados virarem `skip` e a suíte
        // ficaria verde sem medir nada.
        $this->assertTrue(is_file(base_path(self::PROTOTIPO)), 'o protótipo não está no disco: os dez estados deste arquivo foram SKIPADOS, e isso NÃO é "conferido"');
        $this->assertFalse(is_file(base_path('design_handoff_publicador/Aprovacao-que-nao-existe.dc.html')), 'ISCA CEGA: o predicado do skip devolve `true` para caminho inexistente.');
    }
}
