<?php

namespace Tests\Unit\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaPublicacao;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Publicador\Aprovacao\AjustesDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService;
use App\Services\Publicador\Aprovacao\Fila\FiltrosDaFilaService;
use App\Services\Publicador\Aprovacao\VocabularioDoPortao;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 * Fase 187, plano 06 — OS FILTROS DA FILA: a busca sem acento **no servidor**, os quatro filtros
 * como listas FECHADAS, os **TRÊS** recortes de contagem e o **CUSTO** em chamadas ao ML.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"** — gate cuja resposta é "nada" é
 * decorativo e não entra (`feedback_gate_vazio_por_construcao`).
 *
 *   · **a busca** — alguém delegar o acento ao COLLATION, como o precedente do módulo
 *     (`PublicacoesDaListaService`) decidiu e DECLAROU. Naquele caminho a busca
 *     acento-insensível **não é provável em SQLite**, e os testes passariam enquanto a produção
 *     (MySQL 8.0.46) e o local (MariaDB 10.4.32) se comportam de **três** formas. Os quatro
 *     sentidos de acento/caixa estão aqui, e eles rodam no **SQLite**.
 *   · **o mapa de acentos** — alguém normalizar com `Str::ascii()` de um lado e a cadeia de
 *     `REPLACE` do outro: um termo com `'ñ'` viraria `'n'` num lado e ficaria `'ñ'` no outro, e a
 *     busca **não acharia nada, sem erro nenhum**. Há caso provando que os dois lados leem o
 *     MESMO mapa, para TODAS as suas entradas.
 *   · **o rebaixamento de caixa** — alguém pôr `mb_strtolower` em PHP contra `LOWER()` em SQL. O
 *     `LOWER()` do MySQL rebaixa multibyte e o do SQLite **só** rebaixa ASCII: o par divergiria
 *     entre os bancos, e a busca funcionaria local e falharia em produção.
 *   · **`?busca[]=x`** — ARRAY na querystring. `(string) []` emite `Array to string conversion`,
 *     que o `HandleExceptions` transforma em **500** — e isso já foi 500 nesta tela
 *     (T-180-04-T). Caso próprio para os **seis** parâmetros.
 *   · **as listas fechadas** — valor forjado chegando a SQL, ou pior: zerando a lista em vez de
 *     cair no default. Os dois sentidos, com **valor LEGÍTIMO passando** ao lado (sem isso, um
 *     saneador que recusasse tudo passaria).
 *   · **a interseção** — dois filtros ativos virarem **OU**. A fila mostraria mais do que a
 *     pessoa pediu, e aprovar JÁ PUBLICA.
 *   · **os TRÊS recortes** — colapsar dois deles. É o bug que faz o card ativo mostrar o próprio
 *     total e os outros oito zerarem, e o popover deixar de dizer quanto EXISTE. Os três estão
 *     provados **lado a lado, no mesmo caso**, porque é a confusão que o desenho convida.
 *   · **o "N de M"** — alguém "consertar" o **M** para o total da fila ao vivo. A divergência D8
 *     é do protótipo (`:1276`) e é reproduzida: o M inclui `preparando` e `publicado`.
 *   · **o CUSTO** — `para()` por oferta **dentro** do laço das 8 contagens multiplicaria as
 *     requisições ao ML por oito. O gate conta requisições a `/sites/MLB/listing_prices` com
 *     **isca nas duas direções**: trio igual dedupa, trio diferente não.
 *
 * ⚠️ **ZERO REDE:** `Http::preventStrayRequests()` + `Http::fake` com gravador. Nenhuma chamada à
 * API real do Mercado Livre sai daqui.
 *
 * ⚠️ `Carbon::setTestNow()` em todo caso que dependa de data: a etapa `publicado` recorta
 * "**publicado HOJE**" (`whereDate('updated_at', hoje)`), e teste dependente de `now()` reprova
 * por horário — medido neste projeto.
 *
 * ⚠️ **ESTE ARQUIVO NÃO ALTERA NADA DAS FASES 186 E 187-02/04/05.**
 * `BloqueiosDaOfertaService`, `ComposicaoFinanceiraService`, `DadosEfetivosService`,
 * `AndamentoDaOfertaService`, `FilaDeAprovacaoService` e `PubAprovacaoOferta` são **LIDOS** — e,
 * nos casos de custo, SUBCLASSADOS no teste, nunca alterados no disco.
 *
 * ⚠️ A fila **nasce vazia** e isso é honesto: nada cria `PubAprovacaoOferta` em produção hoje —
 * quem passa a criar é a **Fase 194**. Estes casos montam o grafo real (produto → rascunho →
 * alvo → oferta) à mão, porque é a única forma de medir — e **fixture não é fonte de tela**.
 *
 * ⚠️ **Nenhum executor desta fase viu esta tela renderizada:** o banco local não tem empresa com
 * `ml_token` (a rota dá 404 aqui). A conferência é do usuário, em produção, na conta 459.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 */
class FiltrosDaFilaServiceTest extends TestCase
{
    use RefreshDatabase;

    /** O commit de PARTIDA deste plano, capturado na PRIMEIRA ação do executor. */
    private const PARTIDA = '39eedc49';

    /**
     * O ESCOPO DO ASSUNTO deste plano.
     *
     * ⚠️ **O autor NÃO discrimina nada nesta árvore:** as sessões rodam com o MESMO `user.name`
     * (`MB.ECF-100376`), então `git log --format=%an` devolve um valor só para tudo. O
     * discriminador real é o escopo do assunto — e há isca nas duas direções.
     */
    private const ESCOPO = '/\(187-06\)/';

    /** Clássico e Premium — `EstruturaPublicacao::LISTING_TYPES`, LIDOS, nunca à mão. */
    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    /** Contador de SKU: `alvo_id` é UNIQUE, então cada oferta precisa do seu alvo. */
    private int $sequencia = 0;

    /** `"{categoria}|{listing_type}"` → corpo do `/sites/MLB/listing_prices`. */
    private array $tarifas = [];

    /** @var list<array{caminho: string, query: array}> */
    private array $chamadas = [];

    private ?DadosEfetivosService $efetivos = null;

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ A etapa `publicado` recorta "publicado HOJE": sem o agora PARADO, o caso vira
        // dependente da hora do dia.
        Carbon::setTestNow('2026-10-16 09:00:00');

        // ⚠️ O app token do `ClienteMlPublicador` é FINGIDO no cache: sem ele a primeira chamada
        // pública tenta `/oauth/token` e LANÇA `[MLB Coleta] Falha ao obter app token: HTTP 404`.
        // É o mesmo preparo de `ComposicaoContraOPrototipoTest:123`.
        // ⚠️ E nunca `Cache::flush()` neste arquivo: ele apagaria este token.
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        // ⚠️ ZERO REDE: qualquer chamada não fingida EXPLODE, em vez de sair para a internet.
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
    // Fixture: o grafo REAL — produto -> rascunho -> alvo -> oferta do portão.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{produto?: string, sku?: string, titulo?: ?string, kit?: int, tipo?: string, responsavel?: ?User, categoria?: ?string, atualizado?: ?string}  $o
     */
    private function oferta(Company $empresa, string $andamento, array $o = []): PubAprovacaoOferta
    {
        $this->sequencia++;

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => $o['sku'] ?? ('FILT-'.$this->sequencia),
            'nome' => $o['produto'] ?? 'Poltrona Beny',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => $o['kit'] ?? 1,
        ]);

        $rascunho = PubRascunho::create(array_filter([
            'produto_id' => $produto->id,
            'categoria_id' => $o['categoria'] ?? null,
        ], static fn ($v) => $v !== null));

        $alvo = PubRascunhoAlvo::create([
            'rascunho_id' => $rascunho->id,
            'listing_type_id' => $o['tipo'] ?? self::CLASSICO,
            // ⚠️ `array_key_exists` e NÃO `??`: `['titulo' => null]` é o caso do título AUSENTE, e
            // o `??` o devolveria ao default — o caso passaria medindo outra coisa.
            //
            // ⚠️⚠️ O default é DERIVADO do nome do produto, e isso é correção MEDIDA em
            // 16/10/2026: um literal fixo com a palavra "Poltrona" fazia **toda** oferta casar a
            // busca por `poltrona` pelo TÍTULO, e dois casos reprovavam o código CERTO (a faixa
            // "não recortava" porque o recorte era a conta inteira). Fixture que compartilha
            // palavra com o termo discriminante é gate cego por construção.
            'titulo' => array_key_exists('titulo', $o)
                ? $o['titulo']
                : (($o['produto'] ?? 'Poltrona Beny').' Decorativa Veludo'),
            'posicao' => 0,
        ]);

        $oferta = PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $empresa->id,
            'andamento' => $andamento,
            'responsavel_id' => isset($o['responsavel']) ? $o['responsavel']->id : null,
            'entrou_na_fila_em' => '2026-10-16 07:00:00',
        ]);

        if (array_key_exists('atualizado', $o) && $o['atualizado'] !== null) {
            // ⚠️ `update` direto na tabela: o Eloquent sobrescreveria `updated_at` com o `now()`.
            PubAprovacaoOferta::withoutTimestamps(
                fn () => $oferta->forceFill(['updated_at' => $o['atualizado']])->save()
            );
        }

        return $oferta->fresh();
    }

    private function servico(): FiltrosDaFilaService
    {
        return app(FiltrosDaFilaService::class);
    }

    private function fila(): FilaDeAprovacaoService
    {
        return app(FilaDeAprovacaoService::class);
    }

    private function daConta(Company $empresa)
    {
        return $this->fila()->paraAConta($empresa->id);
    }

    /**
     * O PANORAMA da conta, com os `kinds` que o caso declara.
     *
     * ⚠️ O espião é instalado ANTES: `panoramaDaConta()` chama `para()` de verdade, e o espião
     * devolve os `kinds` do caso — o `texto` da busca continua vindo do BANCO, que é o que estes
     * casos medem.
     */
    private function panorama(Company $empresa, array $kindsPorId = []): array
    {
        $this->espiaoDeBloqueios($kindsPorId);

        return $this->servico()->panoramaDaConta($this->daConta($empresa));
    }

    /** Os ids que sobram depois de `aplicar()`, em ordem. */
    private function idsFiltrados(Company $empresa, array $query, array $kindsPorId = []): array
    {
        $panorama = $this->panorama($empresa, $kindsPorId);
        $servico = $this->servico();
        $responsaveis = array_map(
            static fn (array $r): int => $r['id'],
            $servico->responsaveisDaConta($this->daConta($empresa)),
        );

        $filtros = $servico->saneados($this->pedido($query), $responsaveis);

        $ids = $servico->aplicar($this->daConta($empresa), $filtros, $panorama)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return array_map('intval', $ids);
    }

    /** Uma `Request` com a querystring do caso — o mesmo caminho que o controller usa. */
    private function pedido(array $query): Request
    {
        return Request::create('/mlb/anuncios/meus/1/aprovacao', 'GET', $query);
    }

    /**
     * Troca `BloqueiosDaOfertaService` por um ESPIÃO que CONTA chamadas e devolve os `kinds` que o
     * teste declara por oferta.
     *
     * ⚠️ É SUBCLASSE, não mock de string: o espião herda a assinatura real, então renomear
     * `para()` no serviço da Fase 186 quebra este teste em vez de deixá-lo passar calado.
     */
    private function espiaoDeBloqueios(array $kindsPorId = []): object
    {
        $espiao = new class(app(ComposicaoFinanceiraService::class), app(AjustesDaOfertaService::class)) extends BloqueiosDaOfertaService
        {
            public int $chamadas = 0;

            /** @var list<int> */
            public array $ofertasVistas = [];

            public array $osKinds = [];

            public function para(PubAprovacaoOferta $oferta): array
            {
                $this->chamadas++;
                $this->ofertasVistas[] = (int) $oferta->id;

                return [
                    'bloqueios' => [],
                    'aprovavel' => true,
                    'ao_vivo' => true,
                    'kinds' => $this->osKinds[(int) $oferta->id] ?? [],
                ];
            }
        };

        $espiao->osKinds = $kindsPorId;
        $this->app->instance(BloqueiosDaOfertaService::class, $espiao);

        return $espiao;
    }

    /**
     * Instala o dublê de `DadosEfetivosService` com preço/custo/frete, para que a composição REAL
     * chegue à tarifa do ML — é isso que torna o gate de custo uma medição e não uma afirmação.
     */
    private function comDadosEfetivos(float $preco): void
    {
        $this->efetivos = new class(app(EstruturaPrecificacaoService::class)) extends DadosEfetivosService
        {
            public float $oPreco = 0.0;

            public function custosDoProduto(PubProduto $produto): array
            {
                return ['custo' => 200.0, 'origem' => 'digitado', 'imposto' => 19.0, 'por_variante' => []];
            }

            public function fretesDoProduto(PubProduto $produto): array
            {
                return ['por_tipo' => [
                    'gold_special' => ['valor' => 42.9, 'origem' => 'tabela', 'oferta_id' => null],
                    'gold_pro' => ['valor' => 42.9, 'origem' => 'tabela', 'oferta_id' => null],
                ], 'por_variante' => []];
            }

            public function daProduto(PubProduto $produto): array
            {
                return ['precos' => ['gold_special' => $this->oPreco, 'gold_pro' => $this->oPreco]];
            }
        };

        $this->efetivos->oPreco = $preco;
        $this->app->instance(DadosEfetivosService::class, $this->efetivos);
    }

    private function comTarifa(string $categoria, string $listingType): void
    {
        $arquivo = base_path("tests/fixtures-ml/sondagem/publico/categorias/{$categoria}/listing_prices_{$listingType}.json");
        $this->assertFileExists($arquivo, 'fixture de tarifa ausente — o gate rodaria sobre zero casos');

        $this->tarifas[$categoria.'|'.$listingType] = json_decode(file_get_contents($arquivo), true)['resposta'];
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
        $saida = shell_exec('cd '.escapeshellarg($this->raiz()).' && '.$comando.' 2>&1');

        return trim((string) $saida);
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
    // 1. A BUSCA SEM ACENTO, NO SERVIDOR (FILA-05)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_a_busca_acha_nas_quatro_direcoes_de_acento_e_caixa_187_06(): void
    {
        $empresa = Company::factory()->create();

        // Uma GRAVADA COM acento e uma GRAVADA SEM — é o par que prova as quatro direções.
        $comAcento = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'produto' => 'Poltrona Bény', 'titulo' => 'Poltrona Bény Veludo',
        ]);
        $semAcento = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'produto' => 'Poltrona Beny', 'titulo' => 'Poltrona Beny Veludo',
        ]);

        $ambas = [(int) $comAcento->id, (int) $semAcento->id];

        // 1) termo SEM acento acha a gravada COM acento;
        // 2) termo COM acento acha a gravada SEM acento;
        // 3) caixa ALTA acha minúscula;
        // 4) caixa BAIXA acha maiúscula.
        foreach (['beny', 'bény', 'BENY', 'BÉNY', 'BéNy', 'poltrona', 'POLTRONA'] as $termo) {
            $this->assertSame(
                $ambas,
                $this->idsFiltrados($empresa, ['busca' => $termo]),
                "a busca por \"{$termo}\" não achou as DUAS — ela está presa a acento ou a caixa. "
                .'⚠️ Se isto reprovar só no SQLite, alguém delegou o acento ao COLLATION.',
            );
        }

        // ⚠️ ISCA: a busca SABE recusar. Sem ela, um `aplicar()` que ignorasse o termo passaria
        // em todos os sete casos acima.
        $this->assertSame([], $this->idsFiltrados($empresa, ['busca' => 'cadeira-que-nao-existe']));
    }

    public function test_a_busca_cobre_os_tres_campos_com_so_o_sku_e_so_o_titulo_187_06(): void
    {
        $empresa = Company::factory()->create();

        $porNome = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'produto' => 'Mesa Lateral Nogueira', 'sku' => 'AAA-1', 'titulo' => 'Zzz um',
        ]);
        $porSku = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'produto' => 'Zzz dois', 'sku' => 'SKÚ-ESPECIAL-9', 'titulo' => 'Zzz tres',
        ]);
        $porTitulo = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'produto' => 'Zzz quatro', 'sku' => 'BBB-2', 'titulo' => 'Rack Suspenso Avelã 180cm',
        ]);

        // Um caso em que SÓ o nome casa.
        $this->assertSame([(int) $porNome->id], $this->idsFiltrados($empresa, ['busca' => 'nogueira']));

        // ⚠️ Um caso em que SÓ o SKU casa — e com ACENTO no SKU, para provar que a normalização
        // vale nos três campos e não só no nome.
        $this->assertSame([(int) $porSku->id], $this->idsFiltrados($empresa, ['busca' => 'sku-especial']));
        $this->assertSame([(int) $porSku->id], $this->idsFiltrados($empresa, ['busca' => 'SKÚ-ESPECIAL']));

        // ⚠️ Um caso em que SÓ o título casa.
        $this->assertSame([(int) $porTitulo->id], $this->idsFiltrados($empresa, ['busca' => 'avela']));
        $this->assertSame([(int) $porTitulo->id], $this->idsFiltrados($empresa, ['busca' => 'AVELÃ']));

        // ⚠️ Título NULO não derruba a busca nem casa com tudo: `REPLACE(NULL,…)` é NULL e
        // `NULL LIKE …` é falso. Sem este caso, um `COALESCE` esquecido faria a oferta sem título
        // casar QUALQUER termo (ou a consulta inteira explodir).
        $semTitulo = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'produto' => 'Banqueta Alta', 'sku' => 'CCC-3', 'titulo' => null,
        ]);
        $this->assertSame([(int) $semTitulo->id], $this->idsFiltrados($empresa, ['busca' => 'banqueta']));
        $this->assertSame([], $this->idsFiltrados($empresa, ['busca' => 'termo-impossivel-xyz']));
    }

    public function test_os_dois_lados_da_busca_leem_o_MESMO_mapa_de_acentos_187_06(): void
    {
        $empresa = Company::factory()->create();
        $servico = $this->servico();

        // ⚠️⚠️ **A prova de que não há DOIS mapas.** Para CADA entrada do mapa, uma oferta gravada
        // com a letra acentuada tem de ser achada pela base ASCII e vice-versa. Se o PHP usasse
        // `Str::ascii()` (≈1000 caracteres) e o SQL uma cadeia menor, as entradas de fora cairiam
        // aqui — e a busca não acharia nada, sem erro nenhum.
        $this->assertGreaterThanOrEqual(
            50,
            count(FiltrosDaFilaService::SEM_ACENTO),
            'o mapa encolheu: letra fora dele deixa de ser normalizada e a busca passa a depender do banco',
        );

        // ⚠️ Uma oferta por entrada do mapa, com a letra acentuada no NOME.
        //
        // ⚠️⚠️ O prefixo NUMERADO (`Zz<i>…Zz`) não é enfeite, e o primeiro desenho deste caso
        // estava ERRADO sem ele: **seis** entradas do mapa (`á à â ã ä å`) compartilham a base
        // `a`, então um sinal `Qq<letra>ww` faria o termo `Qqaww` casar as SEIS linhas — e a
        // asserção `=== [$id]` reprovaria o código CERTO. O índice torna cada sinal único e
        // mantém a asserção forte (UMA linha, e é aquela).
        $esperados = [];

        foreach (FiltrosDaFilaService::SEM_ACENTO as $acentuada => $base) {
            $i = count($esperados);
            $oferta = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
                'produto' => 'Zz'.$i.$acentuada.'Zz', 'titulo' => null, 'sku' => 'MAPA-'.$i,
            ]);
            $esperados[$acentuada] = [(int) $oferta->id, 'Zz'.$i.$base.'Zz', 'Zz'.$i.$acentuada.'Zz'];
        }

        // ⚠️ UM panorama só para as entradas do mapa: `panoramaDaConta()` chama `para()` por
        // oferta, e montá-lo dentro do laço custaria n² chamadas sem medir nada a mais.
        $panorama = $this->panorama($empresa);
        $this->assertCount(count(FiltrosDaFilaService::SEM_ACENTO), $panorama, 'ISCA CEGA: o panorama nao cobriu o mapa');

        $falhas = [];

        foreach ($esperados as $acentuada => [$id, $sinalBase, $sinalAcentuado]) {
            // Termo com a base ASCII acha a linha gravada com a acentuada.
            if ($servico->idsComBusca($panorama, $sinalBase) !== [$id]) {
                $falhas[] = "base de '{$acentuada}' nao acha a linha dela ({$sinalBase})";
            }

            // E o termo com a acentuada acha a MESMA linha (idempotencia da normalizacao).
            if ($servico->idsComBusca($panorama, $sinalAcentuado) !== [$id]) {
                $falhas[] = "acentuada '{$acentuada}' nao acha a propria linha";
            }
        }

        // ⚠️ ISCA DO SINAL: ele SABE recusar. Sem isto, um `idsComBusca` que devolvesse a conta
        // inteira passaria nas duas direções acima para o caso de UMA oferta só.
        $this->assertSame([], $servico->idsComBusca($panorama, 'Zz999naoexisteZz'));

        $this->assertSame([], $falhas, 'entradas do mapa que NAO valem nos dois lados: '.implode(' . ', $falhas));

        // ⚠️ `semAcento()` tem de devolver a base para CADA chave. Sem isto, trocar o
        // `str_replace` por `Str::ascii()` (que translitera ~1000 caracteres) passaria — e aí
        // haveria DUAS normalizações possíveis para a mesma pergunta.
        foreach (FiltrosDaFilaService::SEM_ACENTO as $acentuada => $base) {
            $this->assertSame($base, $servico->semAcento($acentuada), "semAcento('{$acentuada}')");
        }

        // ⚠️ `semAcento()` NÃO rebaixa caixa — quem rebaixa é `normalizarParaBusca()`, DEPOIS. As
        // duas responsabilidades ficam separadas de propósito.
        $this->assertSame('BENY', $servico->semAcento('BÉNY'), 'semAcento() passou a rebaixar caixa');
        $this->assertSame('beny', $servico->normalizarParaBusca('BÉNY'));
        $this->assertSame('beny', $servico->normalizarParaBusca('  Bény  '), 'espaco de borda mudou o resultado');
        $this->assertSame('', $servico->normalizarParaBusca('   '));

        // ⚠️⚠️ A prova de que a normalização é a MESMA nas duas pontas: para qualquer texto, o
        // termo normalizado tem de estar DENTRO do conteúdo normalizado. É a identidade que a
        // estratégia (b) não conseguia garantir, porque lá o conteúdo era normalizado pelo BANCO.
        $this->assertStringContainsString(
            $servico->normalizarParaBusca('Bény'),
            $servico->normalizarParaBusca('Poltrona BÉNY Decorativa'),
        );
    }

    public function test_busca_em_ARRAY_nao_vira_500_e_busca_vazia_nao_filtra_nada_187_06(): void
    {
        $empresa = Company::factory()->create();
        $uma = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $outra = $this->oferta($empresa, PubAprovacaoOferta::APROVADO);
        $todas = [(int) $uma->id, (int) $outra->id];

        // ⚠️⚠️ `?busca[]=x` entrega ARRAY, e `(string) []` emite `Array to string conversion`,
        // que o `HandleExceptions` transforma em 500 — foi 500 nesta tela antes (T-180-04-T).
        $this->assertSame($todas, $this->idsFiltrados($empresa, ['busca' => ['x']]));

        // Os SEIS parâmetros, cada um em forma de ARRAY, no mesmo pedido.
        $this->assertSame($todas, $this->idsFiltrados($empresa, [
            'busca' => ['x'], 'modalidade' => ['premium'], 'formato' => ['combo'],
            'responsavel' => ['3'], 'motivo' => ['frete_ausente'], 'etapa' => ['aprovado'],
        ]));

        // Vazia, só espaço, e ausente: nenhuma delas filtra.
        foreach ([['busca' => ''], ['busca' => '   '], []] as $query) {
            $this->assertSame($todas, $this->idsFiltrados($empresa, $query));
        }

        // ⚠️ ISCA: as sete chamadas acima não passaram por um `aplicar()` cego — com termo de
        // verdade ele RECORTA.
        $this->assertSame([], $this->idsFiltrados($empresa, ['busca' => 'nada-casa-aqui']));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 2. OS QUATRO FILTROS — listas FECHADAS que compõem por INTERSEÇÃO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_modalidade_e_formato_sao_listas_FECHADAS_com_default_187_06(): void
    {
        $empresa = Company::factory()->create();
        $servico = $this->servico();

        // ⚠️ A lista de modalidade é LIDA de `EstruturaAnuncio::TIPOS`, nunca escrita à mão.
        $this->assertSame(
            array_merge(['todas'], array_keys(EstruturaAnuncio::TIPOS)),
            $servico->modalidades(),
            'a lista de modalidade deixou de ser lida de `EstruturaAnuncio::TIPOS`',
        );
        $this->assertSame(['todos', 'unidade', 'combo'], FiltrosDaFilaService::FORMATOS);

        $classico = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['tipo' => self::CLASSICO]);
        $premium = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['tipo' => self::PREMIUM]);

        // ⚠️ VALOR LEGÍTIMO PASSANDO — sem este par, um saneador que recusasse TUDO passaria no
        // caso do valor forjado logo abaixo.
        $this->assertSame([(int) $classico->id], $this->idsFiltrados($empresa, ['modalidade' => 'classico']));
        $this->assertSame([(int) $premium->id], $this->idsFiltrados($empresa, ['modalidade' => 'premium']));

        $ambas = [(int) $classico->id, (int) $premium->id];

        // ⚠️ ESPAÇO DE BORDA é TOLERADO, e isso é decisão: `trim` ANTES da lista fechada. Link
        // colado com um espaço no fim não pode mudar o recorte que a pessoa compartilhou. (Medido
        // em 16/10/2026: o primeiro desenho deste caso punha `'premium '` entre os FORJADOS e
        // reprovava o comportamento CERTO.)
        $this->assertSame([(int) $premium->id], $this->idsFiltrados($empresa, ['modalidade' => 'premium ']));
        $this->assertSame([(int) $premium->id], $this->idsFiltrados($empresa, ['modalidade' => ' premium']));

        // Valor FORJADO cai no default e NÃO zera a lista — e nenhum deles chega a SQL.
        foreach (['gold_special', 'Premium', 'prem ium', 'classic', "premium'--", '1', 'todas'] as $forjado) {
            $this->assertSame(
                $ambas,
                $this->idsFiltrados($empresa, ['modalidade' => $forjado]),
                "`?modalidade={$forjado}` deveria cair no default 'todas'",
            );
        }

        foreach (['unit', 'Combo', '2', 'combo; DROP TABLE x', 'todos'] as $forjado) {
            $this->assertSame(
                $ambas,
                $this->idsFiltrados($empresa, ['formato' => $forjado]),
                "`?formato={$forjado}` deveria cair no default 'todos'",
            );
        }

        // ⚠️ E as 3+3 OPÇÕES que a tela desenha: ordem e rótulos do protótipo (`:1276`).
        $this->assertSame([
            ['valor' => 'todas', 'rotulo' => 'Todas'],
            ['valor' => 'classico', 'rotulo' => 'Clássico'],
            ['valor' => 'premium', 'rotulo' => 'Premium'],
        ], $servico->opcoesDeModalidade());
        $this->assertSame([
            ['valor' => 'todos', 'rotulo' => 'Todos'],
            ['valor' => 'unidade', 'rotulo' => 'Unidade'],
            ['valor' => 'combo', 'rotulo' => 'Combo'],
        ], $servico->opcoesDeFormato());

        // ⚠️ O `listing_type_id` sai do mapa LIDO: literal `gold_special` no serviço seria a
        // terceira cópia da mesma verdade.
        $this->assertSame('gold_special', EstruturaPublicacao::LISTING_TYPES['classico']);
        $this->assertSame('gold_pro', EstruturaPublicacao::LISTING_TYPES['premium']);
    }

    public function test_formato_combo_casa_dois_ou_mais_e_unidade_casa_um_com_isca_187_06(): void
    {
        $empresa = Company::factory()->create();

        $umaUnidade = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['kit' => 1]);
        $comboDois = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['kit' => 2]);
        $comboSeis = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['kit' => 6]);

        // ISCA NAS DUAS DIREÇÕES: o que `combo` pega, `unidade` NÃO pega, e vice-versa.
        $this->assertSame(
            [(int) $comboDois->id, (int) $comboSeis->id],
            $this->idsFiltrados($empresa, ['formato' => 'combo']),
            '`combo` tem de casar `quantidade_kit >= 2` — e o 2 é a FRONTEIRA',
        );
        $this->assertSame(
            [(int) $umaUnidade->id],
            $this->idsFiltrados($empresa, ['formato' => 'unidade']),
        );
        $this->assertSame(
            [(int) $umaUnidade->id, (int) $comboDois->id, (int) $comboSeis->id],
            $this->idsFiltrados($empresa, ['formato' => 'todos']),
        );
    }

    public function test_os_quatro_filtros_compoem_por_INTERSECAO_e_nunca_por_OU_187_06(): void
    {
        $empresa = Company::factory()->create();
        $ana = User::factory()->create(['name' => 'Ana Ribeiro']);
        $bruno = User::factory()->create(['name' => 'Bruno Lima']);

        // A ÚNICA que casa os quatro ao mesmo tempo.
        $alvo = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'produto' => 'Poltrona Bény', 'tipo' => self::PREMIUM, 'kit' => 3, 'responsavel' => $ana,
        ]);
        // Cada uma falha em UM dos quatro — é isso que distingue E de OU.
        $outroNome = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'produto' => 'Mesa Nogueira', 'tipo' => self::PREMIUM, 'kit' => 3, 'responsavel' => $ana,
        ]);
        $outroTipo = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'produto' => 'Poltrona Beny', 'tipo' => self::CLASSICO, 'kit' => 3, 'responsavel' => $ana,
        ]);
        $outroKit = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'produto' => 'Poltrona Beny', 'tipo' => self::PREMIUM, 'kit' => 1, 'responsavel' => $ana,
        ]);
        $outroResp = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, [
            'produto' => 'Poltrona Beny', 'tipo' => self::PREMIUM, 'kit' => 3, 'responsavel' => $bruno,
        ]);

        $kinds = [
            (int) $alvo->id => ['frete_ausente'],
            (int) $outroNome->id => ['frete_ausente'],
            (int) $outroTipo->id => ['frete_ausente'],
            (int) $outroKit->id => ['frete_ausente'],
            (int) $outroResp->id => ['frete_ausente'],
        ];

        $this->assertSame(
            [(int) $alvo->id],
            $this->idsFiltrados($empresa, [
                'busca' => 'beny', 'modalidade' => 'premium', 'formato' => 'combo',
                'responsavel' => (string) $ana->id, 'motivo' => 'frete_ausente',
            ], $kinds),
            'os cinco filtros juntos devolveram mais de uma oferta — eles estão compondo por OU',
        );

        // ⚠️ ISCA: cada filtro, SOZINHO, pega MAIS de uma — então o recorte acima não veio de um
        // filtro só que por acaso isolou a oferta.
        $this->assertCount(4, $this->idsFiltrados($empresa, ['busca' => 'beny'], $kinds));
        $this->assertCount(4, $this->idsFiltrados($empresa, ['modalidade' => 'premium'], $kinds));
        $this->assertCount(4, $this->idsFiltrados($empresa, ['formato' => 'combo'], $kinds));
        $this->assertCount(4, $this->idsFiltrados($empresa, ['responsavel' => (string) $ana->id], $kinds));
        $this->assertCount(5, $this->idsFiltrados($empresa, ['motivo' => 'frete_ausente'], $kinds));

        // ⚠️ E DOIS motivos marcados é OU **entre eles** (`:1211`: `S.block.some(...)`), enquanto a
        // composição com os outros filtros segue sendo E. São dois níveis.
        $kindsMistos = [
            (int) $alvo->id => ['frete_ausente'],
            (int) $outroNome->id => ['resultado_negativo'],
            (int) $outroTipo->id => [],
        ];
        $this->assertSame(
            [(int) $alvo->id, (int) $outroNome->id],
            $this->idsFiltrados($empresa, ['motivo' => 'frete_ausente,resultado_negativo'], $kindsMistos),
        );
        $this->assertSame(
            [(int) $alvo->id],
            $this->idsFiltrados($empresa, ['motivo' => 'frete_ausente,resultado_negativo', 'busca' => 'bény'], $kindsMistos),
            'o segundo nível virou OU: a busca deixou de recortar os motivos',
        );
    }

    public function test_responsavel_e_motivo_forjados_sao_descartados_em_SILENCIO_187_06(): void
    {
        $empresa = Company::factory()->create();
        $ana = User::factory()->create(['name' => 'Ana Ribeiro']);
        $deOutraConta = User::factory()->create(['name' => 'Carlos Alheio']);

        $daAna = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['responsavel' => $ana]);
        $semResp = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $ambas = [(int) $daAna->id, (int) $semResp->id];

        // ⚠️ VALOR LEGÍTIMO PASSANDO primeiro.
        $this->assertSame([(int) $daAna->id], $this->idsFiltrados($empresa, ['responsavel' => (string) $ana->id]));

        // `?responsavel=abc`, id de outra conta, id inexistente, `0`, negativo e injeção: TODOS
        // descartados em silêncio, e NENHUM zera a lista.
        foreach (['abc', (string) $deOutraConta->id, '999999', '0', '-1', "1 OR 1=1", '1.5', ''] as $forjado) {
            $this->assertSame(
                $ambas,
                $this->idsFiltrados($empresa, ['responsavel' => $forjado]),
                "`?responsavel={$forjado}` deveria ser descartado e NÃO zerar a lista",
            );
        }

        // ⚠️ E um id legítimo junto de um forjado: o legítimo VALE e o forjado sai.
        $this->assertSame(
            [(int) $daAna->id],
            $this->idsFiltrados($empresa, ['responsavel' => $ana->id.',abc,999999']),
        );

        // `?motivo=inexistente`: mesma regra, com a lista fechada LIDA de `ROTULOS`.
        $kinds = [(int) $daAna->id => ['frete_ausente'], (int) $semResp->id => []];
        $this->assertSame([(int) $daAna->id], $this->idsFiltrados($empresa, ['motivo' => 'frete_ausente'], $kinds));

        foreach (['inexistente', 'sem_fotos', 'titulo_longo', 'em_preparacao', 'freight', 'FRETE_AUSENTE'] as $forjado) {
            $this->assertSame(
                $ambas,
                $this->idsFiltrados($empresa, ['motivo' => $forjado], $kinds),
                "`?motivo={$forjado}` deveria ser descartado — ⚠️ `sem_fotos`, `titulo_longo` e "
                .'`em_preparacao` NÃO são filtráveis, e as chaves inglesas do protótipo nunca são chave',
            );
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 3. OS TRÊS RECORTES DE CONTAGEM (FILA-06) — provados LADO A LADO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_as_contagens_dos_POPOVERS_sao_sobre_TODAS_as_ofertas_da_conta_187_06(): void
    {
        $empresa = Company::factory()->create();
        $outra = Company::factory()->create();
        $ana = User::factory()->create(['name' => 'Ana Ribeiro']);
        $bruno = User::factory()->create(['name' => 'Bruno Lima']);
        $servico = $this->servico();

        $um = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['responsavel' => $ana, 'produto' => 'Poltrona']);
        $dois = $this->oferta($empresa, PubAprovacaoOferta::PREPARANDO, ['responsavel' => $ana, 'produto' => 'Mesa']);
        $tres = $this->oferta($empresa, PubAprovacaoOferta::APROVADO, ['responsavel' => $bruno, 'produto' => 'Rack']);
        $this->oferta($outra, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['responsavel' => $ana]);

        $kinds = [
            (int) $um->id => ['frete_ausente', 'fotos_a_conferir'],
            (int) $dois->id => ['frete_ausente'],
            (int) $tres->id => [],
        ];
        $panorama = $this->panorama($empresa, $kinds);
        $servico = $this->servico();

        $semFiltro = $servico->responsaveisDaConta($this->daConta($empresa));
        $this->assertSame([
            ['id' => (int) $ana->id, 'nome' => 'Ana Ribeiro', 'total' => 2],
            ['id' => (int) $bruno->id, 'nome' => 'Bruno Lima', 'total' => 1],
        ], $semFiltro, 'a contagem por responsável não é a da CONTA (inclui preparando e aprovado)');

        $motivos = $servico->motivosDaConta($panorama);
        $porChave = array_column($motivos, 'total', 'chave');

        // ⚠️ Os OITO aparecem SEMPRE, inclusive com 0 — zero medido é FATO (divergência **D6**: o
        // template diz `hint-placeholder-count="6"` e `BLK` declara OITO; o DADO ganha).
        $this->assertCount(8, $motivos);
        $this->assertSame(array_keys(VocabularioDoPortao::ROTULOS), array_column($motivos, 'chave'));
        $this->assertSame(2, $porChave['frete_ausente']);
        $this->assertSame(1, $porChave['fotos_a_conferir']);
        $this->assertSame(0, $porChave['falha_na_publicacao']);

        // ⚠️⚠️ AS CONTAGENS NÃO MUDAM COM FILTRO NENHUM. O recorte dos popovers é `all` (`:1265`,
        // `:1266`), e é isso que torna o popover útil: ele diz quanto EXISTE, não quanto sobrou.
        //
        // ⚠️ E a prova é sobre o `$daConta` de verdade: o filtro é aplicado a um CLONE, então uma
        // implementação que mutasse a base compartilhada cairia aqui.
        $base = $servico->aplicar($this->daConta($empresa), $servico->saneados($this->pedido([
            'busca' => 'poltrona', 'modalidade' => 'classico', 'formato' => 'unidade',
            'responsavel' => (string) $ana->id, 'motivo' => 'frete_ausente',
        ]), [(int) $ana->id, (int) $bruno->id]), $panorama);

        $this->assertSame([(int) $um->id], array_map('intval', (clone $base)->pluck('id')->all()), 'ISCA CEGA: os filtros não recortaram nada');
        $this->assertSame($semFiltro, $servico->responsaveisDaConta($this->daConta($empresa)), 'a contagem do popover mudou por causa de um filtro');
        $this->assertSame($motivos, $servico->motivosDaConta($panorama), 'a contagem dos motivos mudou por causa de um filtro');

        // ⚠️ ISCA NA OUTRA DIREÇÃO: elas MUDAM quando a CONTA muda. Sem isto, uma contagem
        // cravada em zero passaria nas duas asserções acima.
        $this->assertSame([
            ['id' => (int) $ana->id, 'nome' => 'Ana Ribeiro', 'total' => 1],
        ], $servico->responsaveisDaConta($this->daConta($outra)));
    }

    public function test_os_TRES_escopos_de_contagem_lado_a_lado_187_06(): void
    {
        $empresa = Company::factory()->create();
        $ana = User::factory()->create(['name' => 'Ana Ribeiro']);
        $servico = $this->servico();

        // 4 ofertas: 2 "Poltrona" (uma aguardando, uma aprovada) e 2 "Mesa" (idem).
        $poltronaAguardando = $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['produto' => 'Poltrona', 'responsavel' => $ana]);
        $poltronaAprovada = $this->oferta($empresa, PubAprovacaoOferta::APROVADO, ['produto' => 'Poltrona', 'responsavel' => $ana]);
        $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['produto' => 'Mesa', 'responsavel' => $ana]);
        $this->oferta($empresa, PubAprovacaoOferta::APROVADO, ['produto' => 'Mesa', 'responsavel' => $ana]);

        $panorama = $this->panorama($empresa);
        $servico = $this->servico();

        $faixaDe = function (array $query) use ($servico, $empresa, $ana, $panorama): array {
            $base = $servico->aplicar(
                $this->daConta($empresa),
                $servico->saneados($this->pedido($query), [(int) $ana->id]),
                $panorama,
            );

            return array_column($this->fila()->faixa($base), 'total', 'chave');
        };

        $semNada = $faixaDe([]);
        $comEtapa = $faixaDe(['etapa' => 'aprovado']);
        $comBusca = $faixaDe(['busca' => 'poltrona']);

        // ═══ RECORTE 1 — a FAIXA é sobre a `base`: os DEMAIS filtros, NÃO a etapa ═══
        $this->assertSame(4, $semNada['todas']);
        $this->assertSame(2, $semNada['aguardando_aprovacao']);
        $this->assertSame(2, $semNada['aprovado']);

        // ⚠️ A ETAPA **NÃO MOVE** a faixa. Se movesse, filtrar por "Aprovado" mostraria 2 em
        // "Aprovado" e **ZERO** nos outros oito, e a pessoa perderia a navegação.
        $this->assertSame($semNada, $comEtapa, 'a etapa moveu a faixa — os outros oito cards vão zerar');

        // ⚠️ Um filtro da `base` **MOVE**. Sem esta direção, uma faixa cravada passaria acima.
        $this->assertSame(2, $comBusca['todas']);
        $this->assertSame(1, $comBusca['aguardando_aprovacao']);
        $this->assertSame(1, $comBusca['aprovado']);

        // ═══ RECORTE 2 — os POPOVERS são sobre a CONTA: nem a etapa nem a busca os movem ═══
        $popoverSemNada = $servico->responsaveisDaConta($this->daConta($empresa));
        $this->assertSame(4, $popoverSemNada[0]['total']);

        // ═══ RECORTE 3 — a LISTA é a `base` + a etapa, e o **N** do "N de M" é LIDO da faixa ═══
        $faixaDeQuery = fn (array $query): array => $this->fila()->faixa(
            $servico->aplicar($this->daConta($empresa), $servico->saneados($this->pedido($query), []), $panorama),
        );
        $this->assertSame(4, $servico->exibidasNaEtapa($faixaDeQuery([]), 'todas'));
        $this->assertSame(2, $servico->exibidasNaEtapa($faixaDeQuery([]), 'aprovado'));
        $this->assertSame(1, $servico->exibidasNaEtapa($faixaDeQuery(['busca' => 'poltrona']), 'aprovado'));

        // ⚠️ Etapa que não está na faixa devolve `null` — e `null` NÃO é zero: a tela não desenha
        // número inventado.
        $this->assertNull($servico->exibidasNaEtapa($this->fila()->faixa($this->daConta($empresa)), 'inexistente'));

        // ⚠️⚠️ E OS TRÊS, NO MESMO LUGAR, com `busca=poltrona&etapa=aprovado`: a faixa diz 2 em
        // "Todas" (a busca contou, a etapa não), o popover diz 4 (a conta inteira), e a lista diz
        // 1 (a busca E a etapa). **Três números diferentes de propósito.**
        $faixaFinal = $faixaDe(['busca' => 'poltrona', 'etapa' => 'aprovado']);
        $this->assertSame(2, $faixaFinal['todas'], 'FAIXA: a busca conta, a etapa não');
        $this->assertSame(4, $servico->responsaveisDaConta($this->daConta($empresa))[0]['total'], 'POPOVER: a conta inteira');
        $this->assertSame(1, $faixaFinal['aprovado'], 'LISTA: a busca E a etapa');
        $this->assertSame(4, $servico->totalDaConta($this->daConta($empresa)), 'o M do "N de M": a conta inteira');

        // ⚠️ Os três são NÚMEROS DIFERENTES — sem esta asserção, uma implementação que os
        // colapsasse num só passaria se o cenário tivesse os três iguais por acidente.
        $this->assertSame(3, count(array_unique([2, 4, 1])), 'o cenário deixou de distinguir os três recortes');
        $this->assertNotSame((int) $poltronaAguardando->id, (int) $poltronaAprovada->id);
    }

    public function test_o_M_do_N_de_M_inclui_preparando_e_publicado_187_06(): void
    {
        $empresa = Company::factory()->create();
        $servico = $this->servico();

        $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->oferta($empresa, PubAprovacaoOferta::EM_REVISAO_ECF);
        $this->oferta($empresa, PubAprovacaoOferta::AJUSTES_SOLICITADOS);
        $this->oferta($empresa, PubAprovacaoOferta::PREPARANDO);
        $this->oferta($empresa, PubAprovacaoOferta::PUBLICADO, ['atualizado' => '2026-10-01 10:00:00']);

        // ⚠️⚠️ **DIVERGÊNCIA D8, REPRODUZIDA:** o M é `all.length` (`:1276`) — TODAS as ofertas da
        // conta, inclusive `preparando` e a `publicado` de OUTRO dia. **NÃO** é o das ao vivo.
        $this->assertSame(5, $servico->totalDaConta($this->daConta($empresa)));

        // ⚠️ ISCA: as ao vivo são TRÊS. Se o M fosse "a fila ao vivo", ele daria 3 — e o número
        // ficaria incoerente com o card "Todas" da faixa, que também conta sem recorte de estado.
        $this->assertSame(3, $this->fila()->contadorDaAba($empresa->id));
        $this->assertSame(
            5,
            array_column($this->fila()->faixa($this->daConta($empresa)), 'total', 'chave')['todas'],
            'o card "Todas" e o M do "N de M" têm de ser o MESMO número',
        );

        // ⚠️ E a etapa `publicado` da faixa recorta "publicado HOJE" (`whereDate('updated_at',
        // hoje)`): a publicada em 01/10 **não** conta ali, embora conte no M. Conta e lista têm de
        // bater (decisões 9 e 16) — e é por isso que o N é LIDO da faixa, nunca recontado.
        $faixa = $this->fila()->faixa($this->daConta($empresa));
        $this->assertSame(0, array_column($faixa, 'total', 'chave')['publicado']);
        $this->assertSame(0, $servico->exibidasNaEtapa($faixa, 'publicado'));

        $this->oferta($empresa, PubAprovacaoOferta::PUBLICADO, ['atualizado' => '2026-10-16 08:00:00']);
        $faixaHoje = $this->fila()->faixa($this->daConta($empresa));
        $this->assertSame(1, $servico->exibidasNaEtapa($faixaHoje, 'publicado'), 'a publicada HOJE tem de contar');
        $this->assertSame(6, $servico->totalDaConta($this->daConta($empresa)));
    }

    public function test_algum_filtro_e_a_ausencia_dos_SEIS_187_06(): void
    {
        $servico = $this->servico();
        $vazio = $servico->saneados($this->pedido([]), []);

        // Sem nenhum: FALSE — e "Limpar filtros" não aparece.
        $this->assertFalse($servico->algumFiltro($vazio, false));

        // ⚠️ UM CASO POR FILTRO — os SEIS, incluindo a ETAPA (`anyFilter`, `:1270`, tem
        // `S.stage !== 'all'`, e `onClear` a zera junto com os outros cinco em `:1276`).
        $casos = [
            'busca' => [['busca' => 'x'], false],
            'modalidade' => [['modalidade' => 'premium'], false],
            'formato' => [['formato' => 'combo'], false],
            'responsavel' => [['responsavel' => '7'], false],
            'motivo' => [['motivo' => 'frete_ausente'], false],
            'etapa' => [[], true],
        ];

        foreach ($casos as $nome => [$query, $etapaFiltrada]) {
            $filtros = $servico->saneados($this->pedido($query), [7]);
            $this->assertTrue(
                $servico->algumFiltro($filtros, $etapaFiltrada),
                "`algum_filtro` ficou FALSO com o filtro `{$nome}` ativo — \"Limpar filtros\" não apareceria",
            );
        }

        // ⚠️ ISCA: valor FORJADO não acende "Limpar filtros". Um saneador que deixasse o lixo
        // passar faria o botão aparecer sem filtro nenhum.
        foreach ([['modalidade' => 'gold_special'], ['formato' => 'unit'], ['responsavel' => 'abc'], ['motivo' => 'sem_fotos'], ['busca' => '   ']] as $query) {
            $this->assertFalse(
                $servico->algumFiltro($servico->saneados($this->pedido($query), [7]), false),
                'valor forjado acendeu "Limpar filtros": '.json_encode($query),
            );
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 4. ⚠️⚠️ O CUSTO — O GATE MAIS IMPORTANTE DESTE PLANO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_CUSTO_uma_requisicao_por_trio_distinto_com_isca_nas_duas_direcoes_187_06(): void
    {
        $empresa = Company::factory()->create();
        $this->comDadosEfetivos(489.90);
        $this->comTarifa('MLB189007', self::CLASSICO);
        $this->comTarifa('MLB193945', self::CLASSICO);
        $this->comTarifa('MLB189007', self::PREMIUM);

        // ⚠️ DUAS ofertas com o MESMO trio `(categoria, tipo, preço)`.
        $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['categoria' => 'MLB189007', 'tipo' => self::CLASSICO]);
        $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['categoria' => 'MLB189007', 'tipo' => self::CLASSICO]);

        $this->chamadas = [];
        $mapa = $this->servico()->panoramaDaConta($this->daConta($empresa));

        $this->assertCount(2, $mapa, 'ISCA CEGA: o mapa não cobriu as duas ofertas');
        $this->assertSame(
            1,
            $this->requisicoesDeTarifa(),
            'DUAS ofertas com o MESMO trio produziram mais de UMA requisição ao ML — o cache de 1 h '
            .'(`aprovacao:tarifa:<categoria>:<tipo>:<preço×100>`) deixou de dedupar dentro do request',
        );

        // ⚠️⚠️ ISCA NA OUTRA DIREÇÃO — trio DIFERENTE tem de produzir requisição NOVA. Sem ela, um
        // `panoramaDaConta()` que nunca chamasse `para()` passaria na asserção acima.
        $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['categoria' => 'MLB193945', 'tipo' => self::CLASSICO]);
        $this->oferta($empresa, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['categoria' => 'MLB189007', 'tipo' => self::PREMIUM]);

        $this->chamadas = [];
        $this->servico()->panoramaDaConta($this->daConta($empresa));

        $this->assertSame(
            2,
            $this->requisicoesDeTarifa(),
            'os DOIS trios novos não produziram 2 requisições — a sonda está cega, ou o cache está '
            .'colapsando trios diferentes na MESMA chave (que seria tarifa ERRADA na tela)',
        );

        // ⚠️ E o trio é `(categoria, tipo, preço)` — a prova de que nenhum dos três sai da chave.
        $trios = array_map(
            static fn (array $c): string => ($c['query']['category_id'] ?? '').'|'.($c['query']['listing_type_id'] ?? '').'|'.($c['query']['price'] ?? ''),
            array_values(array_filter($this->chamadas, static fn (array $c): bool => $c['caminho'] === '/sites/MLB/listing_prices')),
        );
        $this->assertSame($trios, array_values(array_unique($trios)), 'saiu requisição repetida para o MESMO trio');

        // ⚠️ **ZERO CAP, ZERO DEGRADAÇÃO** (decisão do usuário de 15/10/2026): as 4 ofertas
        // recebem `kinds` de verdade, nenhuma volta "incompleta por timeout". "Limitar N consultas
        // por abertura" foi OFERECIDO e RECUSADO — incompleto BLOQUEIA a aprovação.
        $this->chamadas = [];
        $completo = $this->servico()->panoramaDaConta($this->daConta($empresa));
        $this->assertCount(4, $completo, 'alguma oferta ficou FORA do mapa — a tela degradou');
    }

    public function test_CUSTO_para_e_chamado_exatamente_uma_vez_por_oferta_187_06(): void
    {
        $empresa = Company::factory()->create();

        $ofertas = [];
        foreach ([PubAprovacaoOferta::AGUARDANDO_APROVACAO, PubAprovacaoOferta::EM_REVISAO_ECF, PubAprovacaoOferta::AJUSTES_SOLICITADOS, PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PREPARANDO, PubAprovacaoOferta::PUBLICADO] as $andamento) {
            $ofertas[] = $this->oferta($empresa, $andamento);
        }

        $ids = array_map(static fn ($o): int => (int) $o->id, $ofertas);
        $espiao = $this->espiaoDeBloqueios(array_fill_keys($ids, ['frete_ausente']));

        $servico = $this->servico();
        $mapa = $servico->panoramaDaConta($this->daConta($empresa));

        // ⚠️⚠️ EXATAMENTE uma por oferta. O modo de falhar que isto fecha: chamar `para()` DENTRO
        // do laço das 8 contagens multiplicaria por OITO as requisições ao ML.
        $this->assertSame(6, $espiao->chamadas, '`para()` foi chamado '.$espiao->chamadas.' vezes para 6 ofertas');
        // ⚠️ CONJUNTO, não ordem: `panoramaDaConta()` não promete ordem nenhuma (e não deve — a
        // ordem da fila é de `AndamentoDaOfertaService::ordenarFila()`, que é outro assunto). O
        // primeiro desenho deste caso exigia a ordem de inserção e reprovava o código CERTO.
        $vistas = $espiao->ofertasVistas;
        sort($vistas);
        $esperadas = $ids;
        sort($esperadas);
        $this->assertSame($esperadas, $vistas, 'o CONJUNTO de ofertas vistas não bate');
        $this->assertSame(count($ids), count(array_unique($espiao->ofertasVistas)), 'a MESMA oferta foi vista duas vezes');

        // ⚠️ E as duas consumidoras leem o MESMO mapa: nenhuma chamada NOVA sai delas.
        $antes = $espiao->chamadas;
        $servico->motivosDaConta($mapa);
        $servico->idsComMotivo($mapa, ['frete_ausente']);
        $servico->aplicar($this->daConta($empresa), $servico->saneados($this->pedido(['motivo' => 'frete_ausente']), []), $mapa)->count();
        $this->assertSame($antes, $espiao->chamadas, 'as contagens/o filtro chamaram `para()` de novo — o custo deixou de ser 1 por oferta');

        // ⚠️ ISCA: o espião SABE contar. Sem ela, um `chamadas` cravado em 6 passaria.
        $this->servico()->panoramaDaConta($this->daConta($empresa));
        $this->assertSame(12, $espiao->chamadas, 'o espião não está contando — todas as asserções acima são falsas');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 5. A URL COMO FONTE DO ESTADO (COL-03) — pelas PROPS da rota
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /** @return array{0: Company, 1: User} */
    private function contaComToken(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::factory()->create();

        MlToken::create([
            'company_id' => $company->id,
            'ml_user_id' => (string) random_int(100000000, 999999999),
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer',
            'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status' => 'active',
            'connected_at' => now(),
        ]);

        MlbEmpresa::create([
            'nome' => 'Empresa Filtros '.$company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $admin];
    }

    private function propsDaFila(User $admin, Company $company, array $query = []): array
    {
        return $this->actingAs($admin)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
            ])
            ->get(route('mlb.anuncios.meus.aprovacao', $company).($query === [] ? '' : '?'.http_build_query($query)))
            ->assertOk()
            ->json('props');
    }

    public function test_recarregar_com_a_querystring_devolve_o_MESMO_recorte_nas_props_187_06(): void
    {
        [$company, $admin] = $this->contaComToken();
        $ana = User::factory()->create(['name' => 'Ana Ribeiro']);

        // ⚠️⚠️ A FIXTURE DISCRIMINA DE PROPÓSITO, e isso é correção MEDIDA pela
        // CAMPANHA DE MUTAÇÃO de 16/10/2026 — duas mutações SOBREVIVERAM a este caso
        // quando ele tinha UMA oferta ao vivo só:
        //
        //   · trocar o `panoramaDaConta($daConta)` do controller por um recorte só das AO VIVO
        //     (as contagens dos popovers passariam a ser sobre o recorte, não sobre a conta);
        //   · trocar o M do "N de M" por `contadorDaAba()` (desfazendo a divergência D8).
        //
        // As duas sobreviveram porque, com UMA oferta ao vivo, "a conta inteira" e "as ao
        // vivo" davam o MESMO número — fixture que não distingue é gate CEGO, mesmo com a
        // asserção certa escrita.
        //
        // ➡️ Agora são TRÊS ofertas: uma ao vivo, uma `preparando` e uma `publicado`. Logo
        // **conta = 3** e **ao vivo = 1**, e as duas mutações morrem.
        $this->oferta($company, PubAprovacaoOferta::AGUARDANDO_APROVACAO, ['responsavel' => $ana]);
        $this->oferta($company, PubAprovacaoOferta::PREPARANDO, ['responsavel' => $ana]);
        $this->oferta($company, PubAprovacaoOferta::PUBLICADO, ['responsavel' => $ana, 'atualizado' => '2026-10-16 08:00:00']);

        $this->assertSame(1, $this->fila()->contadorDaAba($company->id), 'ISCA CEGA: a fixture deixou de distinguir conta × ao vivo');

        $props = $this->propsDaFila($admin, $company, [
            'busca' => 'poltrona', 'modalidade' => 'premium', 'formato' => 'combo',
            'responsavel' => (string) $ana->id, 'motivo' => 'frete_ausente', 'etapa' => 'aprovado',
        ]);

        // ⚠️ COL-03: os SEIS voltam, e voltam SANEADOS — é isso que faz recarregar e compartilhar
        // o link devolverem o mesmo recorte.
        $this->assertSame('poltrona', $props['filtros_ativos']['busca']);
        $this->assertSame('premium', $props['filtros_ativos']['modalidade']);
        $this->assertSame('combo', $props['filtros_ativos']['formato']);
        $this->assertSame([(int) $ana->id], $props['filtros_ativos']['responsavel']);
        $this->assertSame(['frete_ausente'], $props['filtros_ativos']['motivo']);
        $this->assertSame('aprovado', $props['etapa']);
        $this->assertTrue($props['algum_filtro']);

        // ⚠️ E as OPÇÕES + a CONTAGEM viajam com as props: a tela não soma nada no cliente.
        $this->assertCount(3, $props['opcoes']['modalidades']);
        $this->assertCount(3, $props['opcoes']['formatos']);
        $this->assertCount(8, $props['opcoes']['motivos']);
        $this->assertSame([(int) $ana->id], array_column($props['opcoes']['responsaveis'], 'id'));

        // ⚠️⚠️ **O M É A CONTA INTEIRA (3), NÃO AS AO VIVO (1)** — divergência D8, provada
        // aqui pelas PROPS e não só no serviço. Mata a mutação "o M vira o total ao vivo".
        $this->assertSame(3, $props['contagem']['total'], 'o M do "N de M" deixou de ser o total da CONTA (D8)');
        $this->assertSame(0, $props['contagem']['exibidas'], 'nada está aprovado: a etapa aprovado exibe 0');

        // ⚠️⚠️ **A CONTAGEM DO POPOVER É A CONTA INTEIRA (3)**, mesmo com os seis filtros
        // ativos na URL. Mata a mutação "popovers contados sobre o recorte": com o recorte
        // das ao vivo ela daria 1, e com o recorte FILTRADO daria 0.
        $this->assertSame(3, $props['opcoes']['responsaveis'][0]['total'], 'a contagem do popover deixou de ser sobre TODAS as ofertas da conta (FILA-06)');

        // ⚠️ E as 8 contagens de motivo também saem sobre a conta: a soma delas não pode ser
        // zero por recorte. (Sem tarifa no `Http::fake`, os `kinds` reais desta fixture
        // incluem `frete_ausente` — o que importa aqui é que o ESCOPO é a conta.)
        $totalDosMotivos = array_sum(array_column($props['opcoes']['motivos'], 'total'));
        $this->assertGreaterThan(0, $totalDosMotivos, 'ISCA CEGA: nenhum motivo foi contado — o escopo do popover não é mensurável nesta fixture');
        $this->assertLessThanOrEqual(3 * 8, $totalDosMotivos);

        // ⚠️ E o nome vem SEM `iniciais` (IDENT-08: quem as deriva é `identidade.js`).
        $this->assertSame(['id', 'nome', 'total'], array_keys($props['opcoes']['responsaveis'][0]), 'a forma da opção de responsável mudou');

        // ⚠️ SEM querystring: os seis voltam no default e `algum_filtro` é FALSO.
        $limpo = $this->propsDaFila($admin, $company);
        $this->assertSame('', $limpo['filtros_ativos']['busca']);
        $this->assertSame('todas', $limpo['filtros_ativos']['modalidade']);
        $this->assertSame('todos', $limpo['filtros_ativos']['formato']);
        $this->assertSame([], $limpo['filtros_ativos']['responsavel']);
        $this->assertSame([], $limpo['filtros_ativos']['motivo']);
        $this->assertSame('todas', $limpo['etapa']);
        $this->assertFalse($limpo['algum_filtro'], '"Limpar filtros" apareceria sem filtro nenhum');

        // ⚠️ SEM filtro, o N e o M são o MESMO número (as 3 da conta): é o recorte "Todas" da
        // faixa, que também conta sem recorte de estado. ⚠️ E a `publicado` de HOJE conta aqui,
        // porque na etapa `todas` não há recorte do dia.
        $this->assertSame(3, $limpo['contagem']['exibidas']);
        $this->assertSame(3, $limpo['contagem']['total']);

        // ⚠️ Valor FORJADO na URL não zera a lista nem derruba a tela — 200 com o default.
        $forjado = $this->propsDaFila($admin, $company, ['responsavel' => 'abc', 'motivo' => 'inexistente', 'busca' => ['x']]);
        $this->assertSame([], $forjado['filtros_ativos']['responsavel']);
        $this->assertSame([], $forjado['filtros_ativos']['motivo']);
        $this->assertSame('', $forjado['filtros_ativos']['busca']);
        $this->assertFalse($forjado['algum_filtro']);
        $this->assertSame(3, $forjado['contagem']['exibidas'], 'valor forjado ZEROU a lista');
    }

    public function test_a_oferta_na_url_NAO_conta_como_filtro_187_06(): void
    {
        [$company, $admin] = $this->contaComToken();
        $oferta = $this->oferta($company, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        // ⚠️ `?oferta=<id>` é o FOCO do plano 187-05, não um filtro. Se contasse, abrir uma oferta
        // faria "Limpar filtros" aparecer sem filtro nenhum.
        $props = $this->propsDaFila($admin, $company, ['oferta' => (string) $oferta->id]);
        $this->assertFalse($props['algum_filtro'], '`?oferta=` entrou em `algum_filtro`');
        $this->assertSame(1, $props['contagem']['exibidas'], '`?oferta=` recortou a lista');

        // ⚠️ ISCA: o MESMO pedido COM um filtro de verdade acende o botão.
        $comFiltro = $this->propsDaFila($admin, $company, ['oferta' => (string) $oferta->id, 'etapa' => 'aprovado']);
        $this->assertTrue($comFiltro['algum_filtro']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 6. TERRITÓRIO E AUSÊNCIAS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_territorio_e_ausencias_do_plano_187_06(): void
    {
        $servico = 'app/Services/Publicador/Aprovacao/Fila/FiltrosDaFilaService.php';
        $controller = 'app/Http/Controllers/MlbPublicadorAprovacaoController.php';

        $codigo = $this->semComentarios($servico).$this->semComentarios($controller);

        // ⚠️ O código é lido SEM COMENTÁRIO: palavra no docblock que explica *"não uso isso"* casa
        // com o gate que conta aquela palavra, e o conserto barato é apagar a explicação.
        $ausentes = [
            'localStorage' => 'COL-02: filtro não mora no navegador',
            'sessionStorage' => 'COL-02',
            'Str::ascii' => 'a busca normaliza pelo MAPA (uma fonte), não por transliteração de ~1000 caracteres',
            'COLLATION' => 'a busca NÃO depende de collation — era o furo declarado do precedente do módulo',
            'whereRaw' => 'a busca saiu do SQL: o teto MEDIDO do SQLite são 28 `REPLACE` encaixados',
            'REPLACE(' => 'a cadeia de `REPLACE` em SQL foi RECUSADA por medição — ver o docblock',
            'gold_special' => 'o `listing_type_id` vem de `EstruturaPublicacao::LISTING_TYPES`, LIDO',
            'gold_pro' => 'idem',
        ];

        foreach ($ausentes as $palavra => $porque) {
            $this->assertStringNotContainsString($palavra, $codigo, $porque);
        }

        // ⚠️ E o POSITIVO, que é o que torna as ausências acima significativas: a normalização da
        // busca está em PHP, nos DOIS lados, e a comparação é `str_contains` (não `LIKE`), então
        // `%` e `_` digitados pela pessoa são texto.
        $doServico = $this->semComentarios($servico);
        $this->assertStringContainsString('mb_strtolower', $doServico, 'a caixa deixou de ser rebaixada em PHP');
        $this->assertStringContainsString('str_contains', $doServico, 'a busca voltou a ser `LIKE` — `%` e `_` viram curinga');
        $this->assertStringContainsString('str_replace', $doServico, 'o mapa de acentos deixou de ser aplicado');

        // ⚠️ ISCA DO LEITOR: o docblock do serviço CITA `localStorage`, `Str::ascii`, `COLLATION`,
        // `whereRaw`, `REPLACE(` e `gold_special` de propósito — é lá que está escrito POR QUE cada
        // um não é usado. Se `semComentarios()` parasse de tirar comentário, as oito asserções
        // acima reprovariam, e o conserto barato seria apagar a explicação.
        //
        // ⚠️ Os DOIS arquivos brutos, porque a prosa está repartida: `localStorage` é explicado no
        // docblock do CONTROLLER (é lá que o filtro vira prop) e `whereRaw`/`REPLACE(`/`COLLATION`
        // no do SERVIÇO (é lá que a estratégia da busca foi decidida). Ler só um deixaria metade
        // da isca cega.
        $bruto = (string) file_get_contents($this->raiz().'/'.$servico)
            .(string) file_get_contents($this->raiz().'/'.$controller);

        foreach (array_keys($ausentes) as $palavra) {
            if ($palavra === 'sessionStorage' || $palavra === 'gold_pro') {
                continue; // esses dois não estão na prosa, e inventar prosa para a isca seria pior
            }
            $this->assertStringContainsString($palavra, $bruto, "a isca `{$palavra}` saiu do docblock — troque a isca");
        }

        // ⚠️ Esta tela APROVA (e aprovar ENFILEIRA); ela NÃO publica — a escrita no ML é da Fase
        // 193. E este plano só LÊ: nenhuma gravação, nenhum job.
        foreach (['->update(', '->save(', '->delete(', 'dispatch(', 'DB::statement'] as $escrita) {
            $this->assertStringNotContainsString($escrita, $codigo, "este plano só LÊ: `{$escrita}` não pertence a ele");
        }

        // ⚠️ `whereHas` e NUNCA `join`: `join` com `pub_produtos` deixaria `updated_at` ambíguo, e
        // `faixaDeAndamento()` faz `whereDate('updated_at', …)` SEM qualificar — erro no MySQL e
        // **não** no SQLite.
        $this->assertStringNotContainsString('->join(', $this->semComentarios($servico));
        $this->assertStringContainsString('whereHas', $this->semComentarios($servico));

        // ⚠️ TERRITÓRIO: por ESCOPO DO ASSUNTO, não por range aberto. Nesta árvore as sessões
        // rodam com o MESMO `user.name`, então o AUTOR não discrimina nada — e um gate por range
        // aberto já acusou a sessão paralela e deixou a suíte com 1 falha herdada (15/10/2026).
        $escopo = $this->git('git log --format=%h:%s '.self::PARTIDA.'..HEAD');

        $meus = [];
        foreach (explode("\n", $escopo) as $linha) {
            [$hash, $assunto] = array_pad(explode(':', $linha, 2), 2, '');
            if (preg_match(self::ESCOPO, $assunto) === 1) {
                $meus[] = $hash;
            }
        }

        // ⚠️ ISCA DO RECORTE: o escopo SABE discriminar. Se ele casasse tudo, o gate mediria a
        // árvore inteira e acusaria a sessão paralela.
        $this->assertMatchesRegularExpression(self::ESCOPO, 'feat(187-06): x');
        $this->assertDoesNotMatchRegularExpression(self::ESCOPO, 'feat(187-05): x');
        $this->assertDoesNotMatchRegularExpression(self::ESCOPO, 'feat(196): x');

        // ⚠️⚠️ **NENHUM RAMO DE SAÍDA ANTECIPADA AQUI, e isso é correção MEDIDA em 16/10/2026.**
        // A primeira redação devolvia cedo quando o range estava vazio (`addToAssertionCount(1)` e
        // `return`), com a razão "não há o que medir". Duas coisas erradas nisso:
        //   1. a contagem de asserções deste método passava a ter DOIS valores (um por ramo), e o
        //      manifesto só aceita um — o gate reprovava sozinho na primeira vez que o plano
        //      commitasse algo (medido: 33 no ramo curto, 63 no completo);
        //   2. território sem commit para medir é **gate VAZIO POR CONSTRUÇÃO**, e ele tem de
        //      REPROVAR, não passar com uma asserção de enfeite.
        // ➡️ O range tem de ter commit do escopo. Se não tiver, a asserção abaixo reprova com a
        // razão escrita, em vez de o gate ficar verde sem medir nada.
        $this->assertNotSame(
            [],
            $meus,
            'nenhum commit com o escopo '.self::ESCOPO.' no range '.self::PARTIDA.'..HEAD: '
            .'este gate de território rodaria sobre ZERO casos. Commite o trabalho do plano antes de medi-lo.',
        );

        $arquivos = array_values(array_filter(array_map(
            'trim',
            explode("\n", $this->git('git show --name-only --format= '.implode(' ', $meus))),
        ), static fn (string $a): bool => $a !== ''));

        $proibidos = [
            'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
            'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
            'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
            'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php',
            'resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/SeloDeAndamentoInterno.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/CabecalhoDaFila.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/FaixaDeAndamento.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/ProximoARevisar.jsx',
            'resources/js/Components/Mlb/Publicador/publicacoes',
            'resources/js/Components/Publicador/Mesa',
            'resources/js/Layouts/AppLayout.jsx',
            'app/Services/Creative',
            'app/Services/Publicador/Criativos',
            'app/Services/Publicador/ImagemAssetService.php',
            'app/Mcp',
            'tests/js/publicador-header-zindex-ordem.test.js',
            'tests/js/estrutura-meus-anuncios.test.js',
            'tests/js/publicador-fila-proximo-187.test.js',
            'tests/js/publicador-fila-cabecalho-187.test.js',
            'package.json',
            'package-lock.json',
            'composer.json',
            'composer.lock',
            'database/migrations',
        ];

        // ⚠️ `test -e` em CADA caminho: caminho fantasma "provaria" território intocado medindo
        // NADA — já aconteceu seis vezes nesta milestone.
        foreach ($proibidos as $caminho) {
            $this->assertFileExists($this->raiz().'/'.$caminho, "caminho inexistente: {$caminho} — o gate mediria NADA");
        }

        // Prefixo de SEGMENTO, nunca `includes`: `app/Mcp` não pode casar `app/McpOutraCoisa.php`.
        $tocados = array_values(array_filter($arquivos, static function (string $a) use ($proibidos): bool {
            foreach ($proibidos as $p) {
                if ($a === $p || str_starts_with($a, $p.'/')) {
                    return true;
                }
            }

            return false;
        }));

        $this->assertSame([], $tocados, 'este plano saiu do território declarado');

        // ⚠️ ISCA DO PREFIXO: a comparação SABE casar, e NÃO casa o irmão.
        $this->assertTrue(str_starts_with('app/Mcp/X.php', 'app/Mcp/'));
        $this->assertFalse(str_starts_with('app/McpOutraCoisa.php', 'app/Mcp/'));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 7. A BIJEÇÃO ids ↔ métodos — nos DOIS sentidos
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_todo_metodo_do_plano_06_tem_id_no_manifesto_e_todo_id_casa_um_metodo_187_06(): void
    {
        $manifesto = $this->raiz().'/tests/gates/contagens-187-06.txt';
        $this->assertFileExists($manifesto, 'manifesto ausente — os gates deste plano rodariam sem régua');

        $linhas = array_values(array_filter(
            array_map('trim', explode("\n", (string) file_get_contents($manifesto))),
            static fn (string $l): bool => $l !== '' && ! str_starts_with($l, '#'),
        ));

        // ⚠️ Esta contagem DEPENDE do número de linhas do manifesto, e isso é desconforto DE
        // PROPÓSITO: acrescentar um id SOBE este número, e o helper reprova até que ele seja
        // remedido. É o que impede acrescentar id sem rodar o gate.
        $this->assertGreaterThanOrEqual(5, count($linhas), 'o manifesto encolheu — algum id desapareceu');

        $filtrosDosIds = [];
        foreach ($linhas as $linha) {
            $partes = preg_split('/\s+/', $linha);
            if (($partes[1] ?? '') === 'php') {
                $filtrosDosIds[$partes[0]] = $partes[2];
            }
        }
        $this->assertNotSame([], $filtrosDosIds, 'nenhum id `php` no manifesto — a bijeção mediria NADA');

        // Todo método `test_*_187_06` DESTE arquivo.
        $metodos = array_values(array_filter(
            get_class_methods($this),
            static fn (string $m): bool => str_starts_with($m, 'test_') && str_ends_with($m, '_187_06'),
        ));
        $this->assertGreaterThanOrEqual(14, count($metodos), 'os métodos deste arquivo encolheram');

        // ─── SENTIDO 1: método SEM id NUNCA RODA ───
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

        // ─── SENTIDO 2: id ÓRFÃO é gate sobre ZERO casos ───
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

        // ⚠️ ISCA: a comparação SABE recusar. Sem ela, um `preg_match` que casasse tudo faria os
        // dois sentidos passarem medindo NADA.
        $this->assertSame(0, preg_match('/^'.reset($filtrosDosIds).'$/', 'test_metodo_que_ninguem_escreveu_187_06'));
    }
}
