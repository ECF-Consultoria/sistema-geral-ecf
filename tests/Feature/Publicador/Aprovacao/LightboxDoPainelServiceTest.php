<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\PubImagemReferencia;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Publicador\Aprovacao\Painel\FotosDoPainelService;
use App\Services\Publicador\Aprovacao\Painel\LightboxDoPainelService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 189, plano 02 — O SERVIDOR DO LIGHTBOX: a ORIGINAL DE REFERÊNCIA (AMPL-05) e o ESTADO
 * DECLARADO de IMG-13, mais a prop `lightbox` recortada ao `?oferta=`.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **a URL da lateral sendo a da PRÓPRIA imagem de IA** — é a afirmação falsa que IMG-13
 *     proíbe em palavras (*"nunca a imagem de IA repetida"*). A prova é que o parâmetro `imagem`
 *     da URL é o id da **ORIGINAL**, e que as duas URLs são DIFERENTES na mesma fixture;
 *   · **a original de OUTRO rascunho virando URL** — `arquivoFoto` daria 404, mas o **id** já
 *     teria vazado no payload (existência confirmada, T-189-10/DADO-14);
 *   · **o texto do estado IMG-13 redigitado** em vez de lido de `PubImagemReferencia` — duas
 *     cópias do mesmo rótulo divergem, e a divergente aparece como dois textos diferentes para a
 *     mesma ausência na mesma tela;
 *   · **N+1 por imagem** (`originaisDe()` consulta uma vez por imagem) — a coluna inteira;
 *   · **o efeito do `EditorEmUso` ficando escondido** — carregar a original SUSPENDE o
 *     agendamento e o preparo por IA do produto por 3 minutos, e quem vir a fila parada tem de
 *     achar a causa por `grep`, não por bisseção;
 *   · **a prop `lightbox` sendo montada SEM `?oferta=`** — custo puro em toda abertura da fila.
 *
 * ⚠️⚠️ **ESTE ARQUIVO NÃO ALTERA NADA DAS FASES 186/187/188.** `PubImagemReferencia`,
 * `FotosDoPainelService`, `PainelDeRevisaoService` e as rotas de arquivo são **LIDOS e CHAMADOS**.
 * ⚠️ **Nada aqui escreve `pub_imagem_referencias`** (a escrita é das Fases 192/194), e o gate da
 * 186 que varre `app/` inteiro (`186-06 dado-15-sem-escrita`) é rodado como não-regressão.
 *
 * ⚠️⚠️ **O CASO `test_o_docblock_nomeia_o_efeito_do_EditorEmUso_...` LÊ COMENTÁRIO DE PROPÓSITO,
 * E ISSO É UMA INVERSÃO DELIBERADA DA RÉGUA.** O artefato sob teste **é** o docblock: a exigência
 * é que a causa do agendamento parado seja ACHÁVEL por `grep`. ➡️ **"Consertá-lo" para
 * `semComentarios()` faria o gate medir NADA.** Fica declarado aqui para ninguém o "corrigir".
 * ⚠️ Os outros casos leem **sem** comentário, e há isca provando por que isso importa.
 *
 * ⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU ESTA TELA RENDERIZADA.** O `mysqld` local está parado, o
 * banco não tem empresa com `ml_token` (a rota dá **404** em produção local),
 * `pub_aprovacao_ofertas` está **vazia** e a fila só ganha oferta na **Fase 194**. Onde se lê *"a
 * tela mostra X"*, leia **"o payload contém X"**. ⚠️ E as conferências visuais das Fases **187 e
 * 188** seguem **ABERTAS** — nada daqui as substitui nem as presume feitas.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class LightboxDoPainelServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O commit de PARTIDA desta wave — o HEAD na primeira ação deste plano.
     *
     * ⚠️ **REFERÊNCIA DE LEITURA, nunca extremo de range:** a árvore é compartilhada com outra
     * sessão (a 189-03 roda em paralelo, e o outro dev commitou `927117b0` no meio desta wave).
     * O que discrimina território é o **ESCOPO DO ASSUNTO**, provado pelo `pi-189-02.sh`.
     */
    private const PARTIDA = '2444feea';

    private const CLASSICO = 'gold_special';

    /** O caminho de arquivo das fotos da fixture — o caso em que a rota serve BYTES. */
    private const CAMINHO = 'publicador/rascunhos/1/foto.jpg';

    /** O arquivo do serviço deste plano. */
    private const SERVICO = 'app/Services/Publicador/Aprovacao/Painel/LightboxDoPainelService.php';

    /** O arquivo do controller que este plano edita. */
    private const CONTROLLER = 'app/Http/Controllers/MlbPublicadorAprovacaoController.php';

    private int $sequencia = 0;

    /** @var list<array{host: string, caminho: string}> */
    private array $chamadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ `Carbon::setTestNow()` com o agora PARADO: as falhas herdadas desta suíte
        // (`tests/Feature/Publicador/CockpitDaContaTest.php`, Fase 179) são fronteira de dia.
        Carbon::setTestNow('2026-10-20 09:00:00');

        // ⚠️ O app token do `ClienteMlPublicador` é FINGIDO no cache. ⚠️ E nunca `Cache::flush()`.
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        // ⚠️ O GRAVADOR, e não só o `fake()` nu: é ele que permite dizer QUAIS requisições saíram
        // em vez de prometer um zero que o desenho contradiz (a abertura do painel LÊ a tarifa
        // real do ML, decisão do usuário de 15/10/2026).
        Http::preventStrayRequests();
        Http::fake(function ($req) {
            $this->chamadas[] = [
                'host' => (string) parse_url($req->url(), PHP_URL_HOST),
                'caminho' => (string) parse_url($req->url(), PHP_URL_PATH),
            ];

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

    /** @return array{0: Company, 1: MlbEmpresa, 2: User} */
    private function conta(): array
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

        $empresa = MlbEmpresa::create([
            'nome' => 'Empresa Lightbox '.$company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }

    /**
     * Uma oferta com produto, rascunho e alvo.
     *
     * ⚠️ `pub_produtos.sku` é **NOT NULL** no schema: fixture sem SKU estoura na inserção.
     * ⚠️ `mlb_empresa_id` é gravado de propósito: o recorte da fila usa as DUAS âncoras com `AND`,
     * e oferta sem a segunda sairia do recorte — o painel viria `null` e o caso mediria OUTRA
     * coisa.
     */
    private function oferta(Company $empresa, ?MlbEmpresa $mlbEmpresa = null, string $andamento = PubAprovacaoOferta::AGUARDANDO_APROVACAO): PubAprovacaoOferta
    {
        $this->sequencia++;

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => 'LBX-'.$this->sequencia,
            'nome' => 'Poltrona Beny',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => 1,
        ]);

        $rascunho = PubRascunho::create([
            'produto_id' => $produto->id,
            'categoria_id' => 'MLB189007',
        ]);

        $alvo = PubRascunhoAlvo::create([
            'rascunho_id' => $rascunho->id,
            'listing_type_id' => self::CLASSICO,
            'titulo' => 'Poltrona Beny Decorativa Veludo',
            'posicao' => 0,
        ]);

        $oferta = PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $empresa->id,
            'mlb_empresa_id' => $mlbEmpresa?->id,
            'andamento' => $andamento,
            'entrou_na_fila_em' => '2026-10-20 06:00:00',
        ]);

        return $oferta->fresh(['alvo', 'produto']);
    }

    private function conjunto(PubAprovacaoOferta $oferta, string $grupo = PubAprovacaoConjunto::GERAL): PubAprovacaoConjunto
    {
        return PubAprovacaoConjunto::create([
            'rascunho_id' => $oferta->rascunho_id,
            'grupo_chave' => $grupo,
            'grupo_hash' => ChaveCanonica::hash($grupo.'-'.$oferta->rascunho_id.'-'.$this->sequencia),
        ]);
    }

    /** Uma linha de revisão, com a `pub_imagens` crua. */
    private function imagem(PubAprovacaoConjunto $conjunto, ?int $posicao, array $o = []): PubAprovacaoImagem
    {
        $crua = PubImagem::create([
            'rascunho_id' => $conjunto->rascunho_id,
            'caminho' => array_key_exists('caminho', $o) ? $o['caminho'] : self::CAMINHO,
        ]);

        return PubAprovacaoImagem::create([
            'conjunto_id' => $conjunto->id,
            'imagem_id' => $crua->id,
            'tipo' => $o['tipo'] ?? PubAprovacaoImagem::TIPO_ORIGINAL,
            'estado' => $o['estado'] ?? PubAprovacaoImagem::APROVADA,
            'posicao' => $posicao,
            'fora' => $o['fora'] ?? false,
        ])->fresh();
    }

    /** Uma `pub_imagens` crua, sem revisão — é o que serve de ORIGINAL de referência. */
    private function crua(int $rascunhoId, ?string $caminho = self::CAMINHO): PubImagem
    {
        return PubImagem::create(['rascunho_id' => $rascunhoId, 'caminho' => $caminho]);
    }

    private function vincular(PubAprovacaoImagem $gerada, PubImagem $original, int $posicao): PubImagemReferencia
    {
        return PubImagemReferencia::create([
            'imagem_id' => $gerada->imagem_id,
            'referencia_imagem_id' => $original->id,
            'posicao' => $posicao,
            'gravado_em' => now(),
        ]);
    }

    /**
     * ⚠️⚠️ **A FIXTURE QUE DISCRIMINA — as CINCO formas, cada uma numa imagem da galeria.**
     *
     * Sem ela as regras deste plano **não se distinguem** (as duas sobreviventes da mutação do
     * 187-06 eram de fixture):
     *
     *   · `original_upload` — **Original** (upload, `de_ia === false`) → bloco **`null`**;
     *   · `ia_duas` — de IA com **DUAS** originais, inseridas **na ordem INVERTIDA** (`posicao` 1
     *     primeiro, `posicao` 0 depois) → a URL é a da `posicao` 0 e `quantas` é 2. ⚠️ Inverter a
     *     inserção é o que separa "ordenou por `posicao`" de "pegou a primeira que o banco deu";
     *   · `ia_sem_vinculo` — de IA **sem** vínculo → o estado declarado de IMG-13;
     *   · `ia_sem_caminho` — de IA com original cujo `caminho` é **nulo** → `disponivel: true` e
     *     `url_arquivo: null` (**nunca** `''`);
     *   · `ia_outro_rascunho` — de IA cuja "original" é de **outro rascunho de OUTRA empresa** →
     *     tratada como ausente, e o id dela **não aparece** no payload.
     *
     * @return array{oferta: PubAprovacaoOferta, imagens: array<string, PubAprovacaoImagem>, originais: array<string, PubImagem>}
     */
    private function cenarioDasCincoFormas(Company $empresa, ?MlbEmpresa $mlbEmpresa = null): array
    {
        $oferta = $this->oferta($empresa, $mlbEmpresa);
        $conjunto = $this->conjunto($oferta);
        $rascunho = (int) $oferta->rascunho_id;

        $imagens = [
            'original_upload' => $this->imagem($conjunto, 0, [
                'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
                'estado' => PubAprovacaoImagem::APROVADA,
            ]),
            'ia_duas' => $this->imagem($conjunto, 1, [
                'tipo' => PubAprovacaoImagem::TIPO_IA,
                'estado' => PubAprovacaoImagem::PENDENTE,
            ]),
            'ia_sem_vinculo' => $this->imagem($conjunto, 2, [
                'tipo' => PubAprovacaoImagem::TIPO_IA,
                'estado' => PubAprovacaoImagem::PENDENTE,
            ]),
            'ia_sem_caminho' => $this->imagem($conjunto, 3, [
                'tipo' => PubAprovacaoImagem::TIPO_IA,
                'estado' => PubAprovacaoImagem::PENDENTE,
            ]),
            'ia_outro_rascunho' => $this->imagem($conjunto, 4, [
                'tipo' => PubAprovacaoImagem::TIPO_IA,
                'estado' => PubAprovacaoImagem::PENDENTE,
            ]),
        ];

        $primeira = $this->crua($rascunho);
        $segunda = $this->crua($rascunho);
        $semCaminho = $this->crua($rascunho, null);

        // ⚠️ ORDEM DE INSERÇÃO INVERTIDA: a `posicao` 1 entra ANTES da `posicao` 0.
        $this->vincular($imagens['ia_duas'], $segunda, 1);
        $this->vincular($imagens['ia_duas'], $primeira, 0);

        $this->vincular($imagens['ia_sem_caminho'], $semCaminho, 0);

        // ⚠️ A "original" de OUTRA empresa, em OUTRO rascunho — o vazamento que T-189-10 fecha.
        $outraEmpresa = Company::factory()->create();
        $alheia = $this->oferta($outraEmpresa);
        $daOutra = $this->crua((int) $alheia->rascunho_id);
        $this->vincular($imagens['ia_outro_rascunho'], $daOutra, 0);

        return [
            'oferta' => $oferta->fresh(['alvo', 'produto']),
            'imagens' => $imagens,
            'originais' => [
                'primeira' => $primeira,
                'segunda' => $segunda,
                'sem_caminho' => $semCaminho,
                'da_outra_empresa' => $daOutra,
            ],
        ];
    }

    private function servico(): LightboxDoPainelService
    {
        return app(LightboxDoPainelService::class);
    }

    /** A galeria do painel, montada pelo serviço da 188-03 — **uma fonte só**. */
    private function galeria(PubAprovacaoOferta $oferta): array
    {
        return app(FotosDoPainelService::class)->para($oferta)['imagens'];
    }

    private function raiz(): string
    {
        return rtrim(str_replace('\\', '/', base_path()), '/');
    }

    /** O arquivo CRU, **com** comentário — usado só pelo gate do docblock (inversão declarada). */
    private function cru(string $relativo): string
    {
        $caminho = $this->raiz().'/'.$relativo;
        $this->assertFileExists($caminho, "arquivo inexistente: {$relativo} — o gate mediria NADA");

        return (string) file_get_contents($caminho);
    }

    /** O código PHP **sem comentário** — `token_get_all`, nunca regex. */
    private function semComentarios(string $relativo): string
    {
        $limpo = '';
        foreach (token_get_all($this->cru($relativo)) as $token) {
            // ⚠️ Flag DENTRO do laço.
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $limpo .= is_array($token) ? $token[1] : $token;
        }

        return $limpo;
    }

    /** O id que a URL de arquivo carrega no parâmetro `imagem` — ou `null`. */
    private function idNaUrl(?string $url): ?int
    {
        if ($url === null) {
            return null;
        }

        return preg_match('#/fotos/(\d+)/arquivo#', $url, $m) === 1 ? (int) $m[1] : null;
    }

    private function versaoInertia(): string
    {
        return (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    }

    /** As props da fila, pelo caminho Inertia — SEM resolver o componente. */
    private function props(User $admin, Company $company, ?string $query = null): array
    {
        $url = route('mlb.anuncios.meus.aprovacao', $company).($query === null ? '' : '?'.$query);

        return $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
            ->get($url)
            ->assertOk()
            ->json('props');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) A ORIGINAL DE REFERÊNCIA — a MESMA rota, a ordem de `posicao`, e NUNCA a imagem de IA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_a_original_sai_pela_MESMA_rota_na_ordem_de_posicao_e_NUNCA_e_a_de_ia_189_02(): void
    {
        $empresa = Company::factory()->create();
        $cenario = $this->cenarioDasCincoFormas($empresa);
        $oferta = $cenario['oferta'];

        $galeria = $this->galeria($oferta);
        $this->assertCount(5, $galeria, 'a fixture não produziu a galeria de 5 — o gate mediria outra coisa');

        $lido = $this->servico()->para($oferta, $galeria);

        // ⚠️ UMA entrada por imagem recebida, e as chaves casam por `id` com a galeria do painel.
        $this->assertSame(
            array_map(static fn (array $i): int => (int) $i['id'], $galeria),
            array_keys($lido['originais']),
            'as chaves de `originais` não casam, uma a uma, com os `id` de `painel.fotos.imagens`',
        );

        $porChave = static fn (array $imagens, string $chave): int => (int) $imagens[$chave]->id;

        // ─── Imagem **Original** (upload): o bloco NÃO existe ───────────────────────────────
        $this->assertNull(
            $lido['originais'][$porChave($cenario['imagens'], 'original_upload')],
            'a imagem Original (upload) ganhou bloco de original de referência — ela É uma original',
        );

        // ─── Imagem de IA com DUAS originais: a da `posicao` 0 e `quantas` = 2 ──────────────
        $duas = $lido['originais'][$porChave($cenario['imagens'], 'ia_duas')];
        $this->assertTrue($duas['disponivel']);
        $this->assertSame(2, $duas['quantas'], 'o número de originais não chegou — com 2, a lateral diria que há 1');
        $this->assertSame(
            (int) $cenario['originais']['primeira']->id,
            $this->idNaUrl($duas['url_arquivo']),
            'a URL não é a da PRIMEIRA original na ordem de `posicao` — a inserção foi INVERTIDA de propósito',
        );

        // ⚠️⚠️ **A URL É A DA ROTA QUE JÁ EXISTE**, com `produto` = `produto_id` da oferta.
        $this->assertSame(
            route('mlb.anuncios.publicador.fotos.arquivo', [
                'produto' => (int) $oferta->produto_id,
                'imagem' => (int) $cenario['originais']['primeira']->id,
            ]),
            $duas['url_arquivo'],
            'a URL da original não é a da rota `mlb.anuncios.publicador.fotos.arquivo`',
        );

        // ⚠️⚠️ **IMG-13: NUNCA a imagem de IA repetida.** A URL da revisão de IA e a da original
        // são DIFERENTES, e a isca prova que a da revisão EXISTE (senão "diferente" seria vazio).
        $daRevisao = null;
        foreach ($galeria as $item) {
            // ⚠️ Flag DENTRO do laço.
            if ((int) $item['id'] === $porChave($cenario['imagens'], 'ia_duas')) {
                $daRevisao = $item['url_arquivo'];
            }
        }
        $this->assertNotNull($daRevisao, 'ISCA CEGA: a imagem de IA da fixture não tem URL própria — "diferente" não provaria nada');
        $this->assertNotSame($daRevisao, $duas['url_arquivo'], 'a lateral mostraria a PRÓPRIA imagem de IA no lugar da original');
        $this->assertNotSame(
            $this->idNaUrl($daRevisao),
            $this->idNaUrl($duas['url_arquivo']),
            'o parâmetro `imagem` da URL é o da revisão de IA, não o da ORIGINAL',
        );

        // ─── Original com `caminho` NULO: `disponivel` SIM, URL `null` — nunca `''` ─────────
        $semCaminho = $lido['originais'][$porChave($cenario['imagens'], 'ia_sem_caminho')];
        $this->assertTrue($semCaminho['disponivel'], 'o vínculo existe: o estado de arquivo ausente não é o de vínculo ausente');
        $this->assertNull($semCaminho['url_arquivo'], '`<img src="">` faz o navegador recarregar a própria página — é `null`, nunca `\'\'`');
        $this->assertNotSame('', $semCaminho['url_arquivo']);
        $this->assertSame(1, $semCaminho['quantas']);

        // ⚠️⚠️ **A FORMA EXATA do bloco disponível** — é o contrato que a 189-05 consome.
        $this->assertSame(['disponivel', 'url_arquivo', 'quantas'], array_keys($duas), 'a forma do bloco disponível mudou — a 189-05 lê estas três chaves');

        // ⚠️⚠️ **E A FORMA DO JSON É OBJETO, não lista.** As chaves são ids (>= 1), nunca `0`, e é
        // isso que faz `json_encode` emitir `{"7":…}` em vez de um array posicional — se virasse
        // lista, a tela leria `originais[0]` e pegaria o bloco da foto ERRADA.
        $json = json_encode($lido['originais']);
        $this->assertStringStartsWith('{', (string) $json, 'as chaves deixaram de ser os ids — o JSON virou lista posicional');
        $this->assertNotContains(0, array_keys($lido['originais']), 'apareceu chave 0 em `originais` — `json_encode` passaria a emitir lista');

        // ⚠️⚠️ **NADA VAI AO MERCADO LIVRE nesta leitura** — aqui o zero é LITERAL (o serviço não
        // lê tarifa nenhuma), e a isca é uma requisição DELIBERADA que TEM de aparecer.
        $this->assertSame([], $this->chamadas, 'o serviço do lightbox fez requisição HTTP');
        Http::get('https://api.mercadolibre.com/items/MLB1');
        $this->assertNotSame([], $this->chamadas, 'ISCA: o gravador não registra nada — a prova por ausência estaria CEGA');
    }

    public function test_a_leitura_em_UMA_consulta_bate_com_originaisDe_e_o_custo_e_FIXO_189_02(): void
    {
        $empresa = Company::factory()->create();
        $cenario = $this->cenarioDasCincoFormas($empresa);
        $oferta = $cenario['oferta'];
        $galeria = $this->galeria($oferta);

        $lido = $this->servico()->para($oferta, $galeria);

        // ⚠️⚠️ **AS DUAS LEITURAS DA MESMA VERDADE TÊM DE BATER, imagem por imagem.** A do
        // serviço é UM `whereIn`; a do modelo é UMA CONSULTA POR IMAGEM. O que se compara é a
        // LEITURA (o conjunto e a ORDEM das originais do rascunho), não a formatação.
        $divergentes = [];
        foreach ($galeria as $item) {
            // ⚠️ Flag DENTRO do laço.
            if (($item['de_ia'] ?? false) !== true || ! is_int($item['imagem_id'] ?? null)) {
                continue;
            }

            $doModelo = PubImagemReferencia::originaisDe($item['imagem_id'])['originais']
                ->filter(static fn (PubImagem $o): bool => (int) $o->rascunho_id === (int) $oferta->rascunho_id)
                ->values();

            $bloco = $lido['originais'][(int) $item['id']];

            $quantasDoServico = $bloco['disponivel'] === true ? $bloco['quantas'] : 0;
            if ($quantasDoServico !== $doModelo->count()) {
                $divergentes[] = 'imagem '.$item['id'].': servico='.$quantasDoServico.' modelo='.$doModelo->count();

                continue;
            }

            if ($doModelo->isEmpty()) {
                continue;
            }

            $primeiraDoModelo = (int) $doModelo->first()->id;
            $naUrl = $this->idNaUrl($bloco['url_arquivo']);
            // Original sem `caminho` não tem URL — ali a comparação é só de contagem.
            if ($naUrl !== null && $naUrl !== $primeiraDoModelo) {
                $divergentes[] = 'imagem '.$item['id'].': url aponta para '.$naUrl.' e o modelo diz '.$primeiraDoModelo;
            }
        }
        // ⚠️ UMA asserção coletando os infratores.
        $this->assertSame([], $divergentes, 'o `whereIn` do serviço divergiu de `PubImagemReferencia::originaisDe()` — a do MODELO ganha: '.implode(' | ', $divergentes));

        // ⚠️ ISCA DO LAÇO: ele rodou sobre as QUATRO imagens de IA da fixture. Sem isto, "nenhum
        // divergente" seria indistinguível de laço que não rodou.
        $deIa = array_values(array_filter($galeria, static fn (array $i): bool => ($i['de_ia'] ?? false) === true));
        $this->assertCount(4, $deIa, 'ISCA CEGA: a fixture não tem as quatro imagens de IA — a comparação acima rodaria sobre menos casos');

        // ─── ⚠️⚠️ O CUSTO É FIXO: T-189-13 ──────────────────────────────────────────────────
        $contar = function (PubAprovacaoOferta $alvo, array $imagens): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->servico()->para($alvo, $imagens);
            $consultas = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $consultas;
        };

        $tres = array_slice($galeria, 0, 3);
        $custoDeTres = $contar($oferta, $tres);

        // ⚠️ ISCA DO CONTADOR: ele conta de verdade. Sem isto, "igual" seria indistinguível de
        // contador cego devolvendo sempre zero.
        $this->assertGreaterThan(0, $custoDeTres, 'ISCA: o contador de consultas devolveu ZERO — ele está cego');

        // Oito imagens de IA, todas com vínculo — se houvesse `originaisDe()` por imagem, o custo
        // subiria junto.
        $grande = $this->oferta($empresa);
        $conjuntoGrande = $this->conjunto($grande);
        for ($i = 0; $i < 8; $i++) {
            // ⚠️ Flag DENTRO do laço.
            $gerada = $this->imagem($conjuntoGrande, $i, [
                'tipo' => PubAprovacaoImagem::TIPO_IA,
                'estado' => PubAprovacaoImagem::PENDENTE,
            ]);
            $this->vincular($gerada, $this->crua((int) $grande->rascunho_id), 0);
        }
        $ofertaGrande = $grande->fresh(['alvo', 'produto']);
        $galeriaGrande = $this->galeria($ofertaGrande);
        $this->assertCount(8, $galeriaGrande, 'a fixture grande não produziu 8 imagens');

        $custoDeOito = $contar($ofertaGrande, $galeriaGrande);
        $this->assertSame($custoDeTres, $custoDeOito, 'o custo CRESCEU com o número de imagens — é o N+1 de T-189-13');

        // ⚠️ E galeria SEM imagem de IA não vai ao banco: `whereIn` com lista vazia ainda é uma
        // ida. A isca do contador acima prova que este zero não é cegueira.
        $this->assertSame(0, $contar($oferta, []), 'lista vazia disparou consulta');
        $soOriginais = array_values(array_filter($galeria, static fn (array $i): bool => ($i['de_ia'] ?? false) !== true));
        $this->assertNotSame([], $soOriginais, 'ISCA CEGA: a fixture não tem imagem de upload — o caso abaixo rodaria sobre lista vazia');
        $this->assertSame(0, $contar($oferta, $soOriginais), 'galeria só de uploads disparou consulta ao vínculo');
    }

    /**
     * ⚠️⚠️ **ESTE CASO LÊ O DOCBLOCK DE PROPÓSITO** — ver a inversão declarada no docblock da
     * classe. O artefato sob teste **é** o comentário: PERGUNTA-188-C exige que a causa do
     * agendamento parado seja achável por `grep`.
     *
     * ⚠️⚠️ **E A ÂNCORA É O NOME, NUNCA O NÚMERO DE LINHA.** O gate equivalente da 188-03 fixava
     * `PreparoIaDoRascunhoService:131` e **reprovou trabalho correto** em 20/10/2026, quando o
     * commit `cd582f83` (de outra sessão) acrescentou uma linha acima: as três chamadas desceram
     * para 132/181/367. ➡️ Aqui o docblock é cobrado por **arquivo + MÉTODO**, e há asserção
     * recusando que um ponteiro de LINHA volte a entrar.
     */
    public function test_o_docblock_nomeia_o_efeito_do_EditorEmUso_por_NOME_e_nao_por_linha_189_02(): void
    {
        $docblock = $this->cru(self::SERVICO);

        // ⚠️ LISTA CONSTANTE, na forma do gate da 188-03 (os mesmos fatos, achaveis por `grep`).
        $deveNomear = [
            'config/publicador.php',                    // de onde vêm os 3 minutos (SEM a linha)
            'editor_em_uso_min',
            '3 minutos',
            'MlbPublicadorController',                  // o arquivo onde o sinal é GRAVADO
            'EditorEmUso::marcar',
            'AgendadorDaFila',                          // os CINCO arquivos leitores, por NOME
            'EditorEmUso::emUso',
            'PreparoIaDoRascunhoService',
            'o preparo por IA do lote pula o produto',  // e os TRÊS pontos dele, por DESCRIÇÃO
            'no segundo laço',
            'na escrita do rascunho',
            'GarantiaPadrao',
            'ExcluirProdutoService',
            '19/10/2026',                               // a decisão, com a data
            'RECUSADO',                                 // e o que foi recusado
            'pub_imagem_referencias',                   // e que ESTA fase não escreve o vínculo
            'Fase 192',
            'Fase 194',
        ];
        $naoNomeados = array_values(array_filter(
            $deveNomear,
            static fn (string $alvo): bool => ! str_contains($docblock, $alvo),
        ));
        $this->assertSame([], $naoNomeados, 'PERGUNTA-188-C: o docblock do lightbox não nomeia o efeito do `EditorEmUso` de forma ACHÁVEL: '.implode(', ', $naoNomeados));

        // ⚠️ ISCA DO RECORTE: tirar um nome da lista só AFROUXA o gate — é esta contagem que faz
        // da remoção uma linha morta visível.
        $this->assertCount(18, $deveNomear, 'a lista do que o docblock deve NOMEAR mudou de tamanho — remeça e escreva a razão');

        // ⚠️⚠️ ISCA NAS DUAS DIREÇÕES, em memória (nenhum arquivo do repo é tocado).
        $mutilado = str_replace('AgendadorDaFila', 'o agendamento', $docblock);
        $this->assertNotSame($docblock, $mutilado, 'ISCA CEGA: o nome que a isca remove não estava no texto');
        $this->assertNotSame(
            [],
            array_values(array_filter($deveNomear, static fn (string $a): bool => ! str_contains($mutilado, $a))),
            'ISCA: tirar um dos leitores do docblock NÃO reprova — o gate mede nada',
        );

        // ─── ⚠️⚠️ NENHUM PONTEIRO DE LINHA PARA OS LEITORES — a mina de 20/10/2026 ──────────
        $sondaDeLinha = '/(?:AgendadorDaFila|PreparoIaDoRascunhoService|GarantiaPadrao|ExcluirProdutoService|MlbPublicadorController|FotosDoPainelService)(?:\.php)?:\d+/';
        $this->assertSame(
            0,
            preg_match($sondaDeLinha, $docblock),
            'voltou ponteiro de LINHA para um dos leitores — é a ancoragem que reprovou trabalho correto em 20/10/2026',
        );
        // ⚠️ ISCA DA SONDA: ela ACHA a forma proibida num texto sintético. Sem isto o zero acima
        // seria indistinguível de regex que não casa nada.
        $this->assertSame(1, preg_match($sondaDeLinha, 'veja PreparoIaDoRascunhoService:131 ali'), 'ISCA CEGA: a sonda de ponteiro de linha não acha nem o caso canônico');

        // ─── ⚠️ ANTI-FANTASMA POR NOME: os cinco arquivos existem e chamam o sinal ──────────
        //
        // ⚠️ LISTA CONSTANTE dos cinco (nunca um `grep` em `app/`: varrer o diretório reprovaria
        // leitor novo e legítimo de outra sessão — família "gate mais largo que o invariante",
        // que já BARROU UM DEPLOY). ⚠️ Lido SEM comentário, e a isca negativa prova por quê.
        $sinalPorArquivo = [
            'app/Services/Publicador/Fila/AgendadorDaFila.php' => 'checar',
            'app/Services/Publicador/PreparoIaDoRascunhoService.php' => 'preparar',
            'app/Support/Publicador/GarantiaPadrao.php' => 'aplicarNaConta',
            'app/Services/Publicador/ExcluirProdutoService.php' => 'impedimento',
            'app/Http/Controllers/MlbPublicadorController.php' => 'produto',
        ];
        $fantasmas = [];
        foreach ($sinalPorArquivo as $arquivo => $metodo) {
            // ⚠️ Flag DENTRO do laço.
            $codigo = $this->semComentarios($arquivo);
            if (! str_contains($codigo, 'EditorEmUso::')) {
                $fantasmas[] = $arquivo.' não chama `EditorEmUso::` (sem comentário)';
            }
            if (preg_match('/\bfunction\s+'.preg_quote($metodo, '/').'\s*\(/', $codigo) !== 1) {
                $fantasmas[] = $arquivo.' não tem o método `'.$metodo.'()` que o docblock nomeia';
            }
        }
        $this->assertSame([], $fantasmas, 'leitor do sinal FANTASMA ou renomeado: '.implode(' | ', $fantasmas));
        $this->assertCount(5, $sinalPorArquivo, 'a lista dos CINCO arquivos leitores mudou de tamanho — remeça por script e escreva a razão');

        // ⚠️⚠️ ISCA NEGATIVA, E ELA MEDE O QUE IMPORTA: o PRÓPRIO serviço do lightbox cita
        // `EditorEmUso::marcar` e `EditorEmUso::emUso` em PROSA e **não chama nenhum dos dois**.
        // ➡️ Este `false` é a prova de que a leitura SEM comentário é carga, e não zelo.
        $this->assertStringContainsString('EditorEmUso::marcar', $docblock, 'ISCA CEGA: o serviço não cita o sinal em prosa — a isca negativa abaixo não mediria nada');
        $this->assertStringNotContainsString('EditorEmUso::', $this->semComentarios(self::SERVICO), 'o serviço do lightbox passou a CHAMAR `EditorEmUso` — ele só reusa a rota, não grava o sinal');

        // ⚠️ E os 3 minutos vêm do VALOR resolvido, não da linha do `config/publicador.php`.
        $this->assertSame(3, (int) config('publicador.preparo_ia.editor_em_uso_min'), 'os 3 minutos do docblock mudaram de valor');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) O ESTADO IMG-13 LIDO DO MODELO, O RECORTE AO RASCUNHO E A ENTRADA ADVERSA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_o_estado_img13_e_LIDO_das_constantes_do_modelo_189_02(): void
    {
        $empresa = Company::factory()->create();
        $cenario = $this->cenarioDasCincoFormas($empresa);
        $oferta = $cenario['oferta'];

        $lido = $this->servico()->para($oferta, $this->galeria($oferta));
        $bloco = $lido['originais'][(int) $cenario['imagens']['ia_sem_vinculo']->id];

        // ⚠️⚠️ **UMA FONTE SÓ:** igualdade com as constantes do modelo, nunca com literal escrito
        // aqui. Na estreia TODA imagem de IA cai neste estado (DADO-15 só é escrito nas 192/194).
        $this->assertSame([
            'disponivel' => false,
            'estado' => PubImagemReferencia::SEM_ORIGINAL,
            'rotulo' => PubImagemReferencia::SEM_ORIGINAL_ROTULO,
            'apoio' => PubImagemReferencia::SEM_ORIGINAL_APOIO,
            'icone' => PubImagemReferencia::SEM_ORIGINAL_ICONE,
            'url_arquivo' => null,
        ], $bloco, 'o estado declarado de IMG-13 não saiu LIDO das constantes de `PubImagemReferencia`');

        // ⚠️⚠️ **A ISCA DA CÓPIA COM UMA LETRA TROCADA** — é ela que separa "o teste lê da
        // constante" de "o teste repete o literal que o serviço escreveu".
        $comUmaLetraTrocada = str_replace('não', 'nao', PubImagemReferencia::SEM_ORIGINAL_ROTULO);
        $this->assertNotSame($comUmaLetraTrocada, PubImagemReferencia::SEM_ORIGINAL_ROTULO, 'ISCA CEGA: a troca de letra não mudou nada — a isca não discrimina');
        $this->assertNotSame($comUmaLetraTrocada, $bloco['rotulo'], 'o rótulo do serviço é uma CÓPIA do texto, não a constante');

        // ⚠️ IMG-13 em palavras: nunca vazio, nunca zero, nunca a própria imagem de IA.
        $this->assertNotSame('', trim((string) $bloco['rotulo']));
        $this->assertNotSame('', trim((string) $bloco['apoio']));
        $this->assertNotSame('', trim((string) $bloco['icone']), 'VOC-05 exige ícone E texto — sem ícone a ausência parece erro de carregamento');
        $this->assertNotSame(0, $bloco['disponivel'], 'o estado virou `0` — zero é o que IMG-13 proíbe');
        $this->assertNull($bloco['url_arquivo'], 'imagem de IA sem vínculo ganhou URL — seria a própria imagem de IA repetida');

        // ⚠️ A chave de dado é identificador, nunca rótulo de tela (VOC-06).
        $this->assertNotSame($bloco['estado'], $bloco['rotulo']);
        $this->assertMatchesRegularExpression('/^[a-z_]+$/', (string) $bloco['estado']);

        // ⚠️ ISCA DO OUTRO LADO: COM vínculo o bloco NÃO tem `estado` — senão o estado declarado
        // seria constante e não mediria nada.
        $comVinculo = $lido['originais'][(int) $cenario['imagens']['ia_duas']->id];
        $this->assertArrayNotHasKey('estado', $comVinculo, 'ISCA CEGA: o bloco COM vínculo também traz `estado` — o estado declarado não discrimina');
        $this->assertTrue($comVinculo['disponivel']);
    }

    public function test_a_original_de_OUTRO_rascunho_fica_FORA_do_payload_189_02(): void
    {
        $empresa = Company::factory()->create();
        $cenario = $this->cenarioDasCincoFormas($empresa);
        $oferta = $cenario['oferta'];

        $lido = $this->servico()->para($oferta, $this->galeria($oferta));
        $alheia = $cenario['originais']['da_outra_empresa'];

        // ⚠️ ISCA CEGA (a fixture TEM o vínculo gravado): sem ela, "tratada como ausente" seria
        // indistinguível de vínculo que nunca existiu.
        $this->assertSame(
            1,
            PubImagemReferencia::where('imagem_id', $cenario['imagens']['ia_outro_rascunho']->imagem_id)->count(),
            'ISCA CEGA: o vínculo para a original de outra empresa não foi gravado — o caso mediria o caso trivial',
        );
        $this->assertNotSame(
            (int) $oferta->rascunho_id,
            (int) $alheia->rascunho_id,
            'ISCA CEGA: a "original alheia" é do MESMO rascunho — a fixture não discrimina',
        );

        // ⚠️⚠️ **T-189-10** — estado DECLARADO, e o id dela em lugar NENHUM do payload.
        $bloco = $lido['originais'][(int) $cenario['imagens']['ia_outro_rascunho']->id];
        $this->assertFalse($bloco['disponivel'], 'a original de OUTRO rascunho virou bloco disponível');
        $this->assertSame(PubImagemReferencia::SEM_ORIGINAL, $bloco['estado']);
        $this->assertNull($bloco['url_arquivo']);

        // ⚠️⚠️ **`JSON_UNESCAPED_SLASHES` NÃO É COSMÉTICO, E ISSO FOI MEDIDO AQUI EM 20/10/2026:**
        // `json_encode` por padrão escapa a barra (`\/`), então a sonda literal `/fotos/6/arquivo`
        // **não casa** no JSON cru — a ISCA DO MESMO LADO (abaixo) reprovou e foi ela que achou.
        // Sem a isca, a asserção de AUSÊNCIA teria passado por não casar NUNCA: gate vazio.
        $json = (string) json_encode($lido, JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('/fotos/'.$alheia->id.'/arquivo', $json, 'o id da original alheia apareceu numa URL do payload');

        // ⚠️ E a ausência também por ESTRUTURA, não só por string: nenhum bloco do payload tem
        // `url_arquivo` apontando para a original alheia. (Probar o id "nu" no JSON seria frágil:
        // as CHAVES do payload são ids de revisão e poderiam coincidir por acidente.)
        $idsNasUrls = [];
        foreach ($lido['originais'] as $qualquer) {
            // ⚠️ Flag DENTRO do laço.
            if (is_array($qualquer)) {
                $idsNasUrls[] = $this->idNaUrl($qualquer['url_arquivo'] ?? null);
            }
        }
        $this->assertNotContains((int) $alheia->id, $idsNasUrls, 'o id da original alheia saiu no parâmetro `imagem` de alguma URL');

        // ⚠️ ISCA DO MESMO LADO: o id da original LEGÍTIMA **aparece** — senão "não aparece"
        // seria satisfeito por um payload vazio (ou por uma sonda que não casa nada).
        $this->assertStringContainsString(
            '/fotos/'.$cenario['originais']['primeira']->id.'/arquivo',
            $json,
            'ISCA CEGA: nem a original legítima aparece no payload — a ausência acima não prova nada',
        );
        $this->assertContains((int) $cenario['originais']['primeira']->id, $idsNasUrls, 'ISCA CEGA: a sonda estrutural não acha nem a original legítima');

        // ⚠️ E o modelo, lido cru, **VÊ** o vínculo alheio: é o recorte do SERVIÇO que o remove,
        // não a ausência do dado.
        $doModelo = PubImagemReferencia::originaisDe($cenario['imagens']['ia_outro_rascunho']->imagem_id);
        $this->assertCount(1, $doModelo['originais'], 'ISCA CEGA: `originaisDe()` não vê o vínculo alheio — o recorte do serviço não estaria sendo medido');
        $this->assertNull($doModelo['estado']);
    }

    public function test_a_entrada_adversa_nao_lanca_e_nao_gera_bloco_189_02(): void
    {
        $empresa = Company::factory()->create();
        $oferta = $this->oferta($empresa);

        // ⚠️ Lista vazia: a forma, não uma exceção.
        $this->assertSame(['originais' => []], $this->servico()->para($oferta, []));

        $adversa = $this->servico()->para($oferta, [
            ['imagem_id' => 10, 'de_ia' => true],                 // sem `id` → sem entrada
            ['id' => '7', 'de_ia' => true, 'imagem_id' => 11],    // `id` string → sem entrada
            ['id' => 11, 'de_ia' => '1', 'imagem_id' => 12],      // `de_ia` string → não é IA
            ['id' => 12, 'de_ia' => 1, 'imagem_id' => 13],        // `de_ia` inteiro → não é IA
            ['id' => 13, 'de_ia' => true],                        // IA sem `imagem_id`
            ['id' => 14, 'de_ia' => true, 'imagem_id' => '9'],    // `imagem_id` string
            'nao-e-array',
            ['sem_id_nenhum' => 1],
        ]);

        $this->assertSame([11, 12, 13, 14], array_keys($adversa['originais']), 'a entrada adversa gerou (ou perdeu) entrada');

        // ⚠️ `de_ia` com truthiness genérica é RECUSADA: só `=== true` é "gerada por IA". Uma
        // string `'1'` virando IA faria a lateral cobrar original de uma foto de upload.
        $this->assertNull($adversa['originais'][11], '`de_ia` string `\'1\'` foi tratada como IA');
        $this->assertNull($adversa['originais'][12], '`de_ia` inteiro `1` foi tratado como IA');

        // ⚠️ IA sem a linha crua: o estado DECLARADO, nunca bloco escondido nem exceção.
        foreach ([13, 14] as $id) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertFalse($adversa['originais'][$id]['disponivel'], "imagem {$id} sem `imagem_id` utilizável virou bloco disponível");
            $this->assertSame(PubImagemReferencia::SEM_ORIGINAL, $adversa['originais'][$id]['estado']);
        }

        // ⚠️ ISCA: a MESMA forma, com os tipos CERTOS, produz bloco disponível — senão "não gera
        // bloco" seria satisfeito por um serviço que nunca gera nada.
        $conjunto = $this->conjunto($oferta);
        $gerada = $this->imagem($conjunto, 0, ['tipo' => PubAprovacaoImagem::TIPO_IA, 'estado' => PubAprovacaoImagem::PENDENTE]);
        $this->vincular($gerada, $this->crua((int) $oferta->rascunho_id), 0);
        $certa = $this->servico()->para($oferta->fresh(['alvo', 'produto']), [
            ['id' => (int) $gerada->id, 'de_ia' => true, 'imagem_id' => (int) $gerada->imagem_id],
        ]);
        $this->assertTrue($certa['originais'][(int) $gerada->id]['disponivel'], 'ISCA CEGA: nem com os tipos certos sai bloco disponível — os casos adversos acima não provam nada');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) A PROP `lightbox` NA ROTA, O TERRITÓRIO E A BIJEÇÃO DO MANIFESTO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_a_rota_monta_a_prop_lightbox_so_com_oferta_valida_da_conta_189_02(): void
    {
        [$company, $mlbEmpresa, $admin] = $this->conta();
        $cenario = $this->cenarioDasCincoFormas($company, $mlbEmpresa);
        $oferta = $cenario['oferta'];

        // ─── COM `?oferta=`: uma entrada por imagem de `painel.fotos.imagens` ───────────────
        $props = $this->props($admin, $company, 'oferta='.$oferta->id);

        $this->assertNotNull($props['painel'] ?? null, 'ISCA CEGA: o `painel` veio nulo — o `lightbox` abaixo seria nulo pelo motivo errado');
        $this->assertIsArray($props['lightbox'] ?? null, 'a prop `lightbox` não chegou ao payload');
        $this->assertArrayHasKey('originais', $props['lightbox']);

        $idsDoPainel = array_map(static fn (array $i): int => (int) $i['id'], $props['painel']['fotos']['imagens']);
        $this->assertSame(
            $idsDoPainel,
            array_map('intval', array_keys($props['lightbox']['originais'])),
            'as chaves de `lightbox.originais` não casam com os `id` de `painel.fotos.imagens`',
        );
        $this->assertNotSame([], $idsDoPainel, 'ISCA CEGA: a galeria do painel veio vazia');

        // ─── SEM `?oferta=`: `null`, e NENHUMA consulta a `pub_imagem_referencias` ──────────
        $contarVinculo = function (?string $query) use ($admin, $company): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $props = $this->props($admin, $company, $query);
            $doVinculo = array_values(array_filter(
                DB::getQueryLog(),
                static fn (array $q): bool => str_contains((string) $q['query'], 'pub_imagem_referencias'),
            ));
            DB::disableQueryLog();

            return [$props, count($doVinculo)];
        };

        [$semOferta, $consultasSemOferta] = $contarVinculo(null);
        $this->assertArrayHasKey('lightbox', $semOferta, 'a prop `lightbox` desapareceu do payload sem `?oferta=` — ela é `null`, não ausente');
        $this->assertNull($semOferta['lightbox'], 'a prop `lightbox` foi montada SEM `?oferta=` — custo puro em toda abertura da fila');
        $this->assertSame(0, $consultasSemOferta, 'sem `?oferta=` houve consulta a `pub_imagem_referencias`');

        // ⚠️ ISCA DO CONTADOR, na direção oposta: COM `?oferta=` a consulta ACONTECE. Sem isto o
        // zero acima seria indistinguível de sonda cega.
        [, $consultasComOferta] = $contarVinculo('oferta='.$oferta->id);
        $this->assertGreaterThan(0, $consultasComOferta, 'ISCA: a sonda não vê consulta a `pub_imagem_referencias` nem COM `?oferta=`');

        // ─── Oferta de OUTRA conta: a MESMA resposta que o `painel` já dá — `null` em silêncio ─
        [$outraCompany, $outraMlb] = $this->conta();
        $alheia = $this->cenarioDasCincoFormas($outraCompany, $outraMlb)['oferta'];
        $deOutraConta = $this->props($admin, $company, 'oferta='.$alheia->id);
        $this->assertNull($deOutraConta['painel'], 'ISCA CEGA: o painel de outra conta foi montado — a trava da 188-02 mudou');
        $this->assertNull($deOutraConta['lightbox'], 'oferta de OUTRA conta montou o `lightbox` — vazaria a original alheia');

        // ─── E as props existentes NÃO mudaram de forma ─────────────────────────────────────
        $this->assertIsArray($props['faixa'] ?? null);
        $this->assertArrayHasKey('proximo_a_revisar', $props);
        $this->assertArrayHasKey('contagem', $props);
        // ⚠️ `faixa` e `proximo_a_revisar` continuam INLINE no literal — o texto que o
        // `tests/js/publicador-fila-proximo-187.test.js` lê.
        $this->assertSame(
            1,
            preg_match("/'faixa' => \\\$fila->faixa\\(\\\$base\\)/", $this->cru(self::CONTROLLER)),
            'a `faixa` saiu de INLINE no literal — há gate em JS lendo esse texto',
        );
        $this->assertSame(
            1,
            preg_match("/'proximo_a_revisar' => \\\$proximo->para\\(\\\$base\\)/", $this->cru(self::CONTROLLER)),
            'o `proximo_a_revisar` saiu de INLINE no literal — há gate em JS lendo esse texto',
        );
    }

    public function test_a_prop_lightbox_nao_faz_requisicao_ao_mercado_livre_com_isca_189_02(): void
    {
        [$company, $mlbEmpresa, $admin] = $this->conta();
        $cenario = $this->cenarioDasCincoFormas($company, $mlbEmpresa);

        // ⚠️⚠️ **A RÉGUA HONESTA, E ELA TEM DUAS FORMAS.** O serviço do lightbox faz **zero
        // requisição LITERAL**; a ROTA, não — abrir o painel LÊ a tarifa real do Mercado Livre
        // (`BloqueiosDaOfertaService::para()` → `/sites/MLB/listing_prices`), decisão do usuário
        // de 15/10/2026. ➡️ Prometer zero absoluto na rota seria gate que o desenho contradiz.
        $props = $this->props($admin, $company, 'oferta='.$cenario['oferta']->id);
        $this->assertIsArray($props['lightbox'] ?? null, 'ISCA CEGA: a prop não foi montada — a medição abaixo seria do caso errado');

        $caminhosDoMl = array_values(array_unique(array_map(
            static fn (array $c): string => $c['caminho'],
            array_filter($this->chamadas, static fn (array $c): bool => str_contains($c['host'], 'mercadolibre') || str_contains($c['host'], 'mercadolivre')),
        )));

        // ⚠️ LISTA CONSTANTE, **MEDIDA** nesta máquina: o único caminho do ML nesta leitura é a
        // tarifa. Endpoint novo aparecendo aqui REPROVA — é o invariante de verdade.
        $permitidos = ['/sites/MLB/listing_prices'];
        $this->assertSame(
            [],
            array_values(array_diff($caminhosDoMl, $permitidos)),
            'apareceu caminho do Mercado Livre fora da lista medida: '.implode(', ', $caminhosDoMl),
        );
        $this->assertCount(1, $permitidos, 'a lista de caminhos permitidos mudou de tamanho — remeça e escreva a razão');

        // ⚠️⚠️ **O SERVIÇO, SOZINHO, FAZ ZERO — e aqui o zero é LITERAL.**
        $this->chamadas = [];
        $this->servico()->para($cenario['oferta'], $this->galeria($cenario['oferta']));
        $this->assertSame([], $this->chamadas, 'o `LightboxDoPainelService` fez requisição HTTP');

        // ⚠️ ISCA DO GRAVADOR: uma requisição DELIBERADA tem de aparecer.
        Http::get('https://api.mercadolibre.com/items/MLB1');
        $this->assertNotSame([], $this->chamadas, 'ISCA: o gravador está CEGO — a prova por ausência não vale nada');

        // ⚠️⚠️ **ISCA DA TRAVA:** `preventStrayRequests()` está ARMADO. Sem esta prova, o
        // `Http::fake()` catch-all do `setUp()` faria a trava passar sem nunca ter sido exercida.
        // ⚠️ Fica no FIM do caso de propósito: ela re-faqueia o cliente.
        Http::fake(['api.mercadolibre.com/*' => Http::response([], 200)]);
        $lancou = false;
        try {
            Http::get('https://nao-faqueado-189-02.test/x');
        } catch (\Illuminate\Http\Client\StrayRequestException $e) {
            $lancou = true;
        }
        $this->assertTrue($lancou, 'ISCA: `Http::preventStrayRequests()` não está armado — requisição não faqueada sairia de verdade');
    }

    public function test_territorio_e_bijecao_do_manifesto_do_lightbox_189_02(): void
    {
        $raiz = $this->raiz();

        // ─── ⚠️ TERRITÓRIO: a LISTA CONSTANTE dos arquivos DESTE plano ──────────────────────
        //
        // ⚠️ Recortada, nunca `app/` inteiro: `lucro_liquido` é campo legítimo do Portal e varrer
        // o diretório já BARROU UM DEPLOY na Fase 186.
        $meusArquivos = [self::SERVICO, self::CONTROLLER];
        foreach ($meusArquivos as $arquivo) {
            // ⚠️ Flag DENTRO do laço — e `test -e` antes de acreditar em qualquer vazio.
            $this->assertFileExists($raiz.'/'.$arquivo, "arquivo deste plano inexistente: {$arquivo}");
        }

        // ⚠️ O trecho NOVO do controller, recortado ao bloco deste plano: o arquivo inteiro é da
        // 187/188 e varrê-lo cobraria ausências que não são deste plano.
        $doServico = $this->semComentarios(self::SERVICO);
        $doControllerInteiro = $this->semComentarios(self::CONTROLLER);
        $trechoNovo = '';
        foreach (explode("\n", str_replace("\r", '', $doControllerInteiro)) as $linha) {
            // ⚠️ Flag DENTRO do laço.
            if (str_contains($linha, 'lightbox') || str_contains($linha, 'Lightbox')) {
                $trechoNovo .= $linha."\n";
            }
        }
        $this->assertNotSame('', $trechoNovo, 'ISCA CEGA: o trecho novo do controller não foi achado — as ausências abaixo rodariam sobre vazio');

        // ⚠️⚠️ AUSÊNCIA DE SEGUNDO PASSO (PUB-09/BLOQ-09): aprovar já enfileira, e não há
        // "confirmar publicação", "desfazer", "segundo passo" nem "cancelar aprovação".
        $proibidos = ['confirmar publica', 'desfazer', 'segundo passo', 'cancelar aprova'];
        $achados = [];
        foreach ($proibidos as $forma) {
            // ⚠️ Flag DENTRO do laço.
            foreach (['servico' => $doServico, 'controller(trecho)' => $trechoNovo] as $onde => $texto) {
                if (stripos($texto, $forma) !== false) {
                    $achados[] = $onde.' tem "'.$forma.'"';
                }
            }
            // ⚠️ ISCA DE CADA FORMA: a sonda acha a forma num texto sintético.
            $this->assertNotFalse(stripos('prefixo '.$forma.' sufixo', $forma), "ISCA CEGA: a sonda não acha a forma \"{$forma}\" nem no caso canônico");
        }
        $this->assertSame([], $achados, 'apareceu segundo passo de aprovação: '.implode(' | ', $achados));

        // ⚠️⚠️ TERRITÓRIO VETADO — o acervo e o `App\Support\Publicador` ficam intocados (o
        // bucket do R2 é PRIVADO desde 16/10/2026 e não serve URL pública; a rota serve BYTES).
        $vetados = ['ArquivosDeImagem', 'ImagemAssetService', 'AcervoImagem', 'App\\Support\\Publicador'];
        $encostados = [];
        foreach ($vetados as $vetado) {
            // ⚠️ Flag DENTRO do laço.
            if (str_contains($doServico, $vetado)) {
                $encostados[] = $vetado;
            }
        }
        $this->assertSame([], $encostados, 'o serviço do lightbox encostou em território vetado: '.implode(', ', $encostados));
        // ⚠️ ANTI-FANTASMA: os dois vetados que são ARQUIVO existem no disco — vetar o que não
        // existe é gate sobre zero casos.
        foreach ([
            'app/Support/Publicador/Imagem/ArquivosDeImagem.php',
            'app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php',
        ] as $alvo) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertFileExists($raiz.'/'.$alvo, "alvo vetado FANTASMA: {$alvo}");
        }
        // ⚠️ ISCA DA SONDA DE VETADO: ela ACHA o nome num texto que o contém.
        $this->assertTrue(str_contains('use App\\Support\\Publicador\\Imagem\\ArquivosDeImagem;', 'ArquivosDeImagem'), 'ISCA CEGA: a sonda de vetado não acha nem o caso canônico');

        // ⚠️ TOK-15: nenhum hex sai do PHP (o `content` do Tailwind não varre `.php`).
        $this->assertSame(0, preg_match('/#[0-9a-fA-F]{3,8}\b/', $doServico), 'apareceu hex no PHP do lightbox — TOK-15');

        // ⚠️ E NENHUMA ESCRITA: o serviço só lê.
        $sondaDeEscrita = '/\b(?:create|insert|insertGetId|updateOrCreate|firstOrCreate|upsert|forceCreate|update|save|delete|fill)\s*\(/';
        $this->assertSame(0, preg_match($sondaDeEscrita, $doServico), 'apareceu forma de ESCRITA no serviço do lightbox — ele só lê');
        $this->assertSame(1, preg_match($sondaDeEscrita, 'PubImagemReferencia::create([])'), 'ISCA CEGA: a sonda de escrita não acha nem o caso canônico');

        // ─── ⚠️⚠️ BIJEÇÃO id ↔ MÉTODO, NOS DOIS SENTIDOS, COM O EXPANSOR DE ALTERNÂNCIA ────
        $manifesto = $raiz.'/tests/gates/contagens-189-02.txt';
        $this->assertFileExists($manifesto, 'o manifesto deste plano não existe — o harness ABORTARIA');
        $linhas = array_values(array_filter(
            explode("\n", str_replace("\r", '', (string) file_get_contents($manifesto))),
            static fn (string $l): bool => trim($l) !== '' && ! str_starts_with(trim($l), '#'),
        ));
        $this->assertCount(3, $linhas, 'o manifesto deixou de ter os TRÊS ids deste plano');

        /**
         * ⚠️⚠️ **O EXPANSOR DE ALTERNÂNCIA, e ele não é zelo.** `/test_[A-Za-z0-9_]+/` casa
         * **ZERO** num filtro `test_(a|b)`, porque depois de `test_` vem `(`. Medido: foi esse
         * furo que produziu `SEM_ID=14` **acusando trabalho correto** na 189-01.
         */
        $expandir = static function (string $filtro): array {
            if (preg_match('/^(.*)\(([^()]*)\)(.*)$/', $filtro, $m) === 1) {
                return array_map(static fn (string $p): string => $m[1].$p.$m[3], explode('|', $m[2]));
            }

            return explode('|', $filtro);
        };
        // ⚠️ ISCA DO EXPANSOR, nas duas direções.
        $this->assertSame(['test_a_189_02', 'test_b_189_02'], $expandir('test_(a_189_02|b_189_02)'), 'ISCA: o expansor não abre a alternância');
        $this->assertSame(0, preg_match_all('/test_[A-Za-z0-9_]+/', 'test_(a_189_02|b_189_02)'), 'ISCA: a regex ingênua casaria a alternância — então o expansor não seria necessário');

        $doManifesto = [];
        foreach ($linhas as $linha) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertSame(1, preg_match('/^(\S+)\s+php\s+(\S+)\s+::\s+tests=(\d+)\s+assertions=(\d+)$/', trim($linha), $m), "linha do manifesto fora da forma: {$linha}");
            $metodos = $expandir($m[2]);
            $this->assertLessThanOrEqual(3, count($metodos), "o id `{$m[1]}` tem mais de TRÊS métodos — alternância grande equivale a filtro de classe");
            $this->assertSame((int) $m[3], count($metodos), "o id `{$m[1]}` declara tests={$m[3]} e tem ".count($metodos).' método(s)');
            foreach ($metodos as $metodo) {
                $doManifesto[] = $metodo;
            }
        }

        $meusMetodos = [];
        preg_match_all(
            '/public function (test_[A-Za-z0-9_]*_189_02)\(/',
            $this->cru('tests/Feature/Publicador/Aprovacao/LightboxDoPainelServiceTest.php'),
            $achadosNoArquivo,
        );
        $meusMetodos = $achadosNoArquivo[1];
        $this->assertNotSame([], $meusMetodos, 'ISCA CEGA: nenhum método `_189_02` foi achado no arquivo — a bijeção rodaria sobre vazio');

        sort($doManifesto);
        $ordenados = $meusMetodos;
        sort($ordenados);
        // ⚠️ UMA asserção por sentido, coletando os infratores.
        $this->assertSame([], array_values(array_diff($ordenados, $doManifesto)), 'método `_189_02` SEM id no manifesto — ele NUNCA roda: '.implode(', ', array_diff($ordenados, $doManifesto)));
        $this->assertSame([], array_values(array_diff($doManifesto, $ordenados)), 'id do manifesto SEM método — gate sobre ZERO casos: '.implode(', ', array_diff($doManifesto, $ordenados)));

        // ─── ⚠️⚠️ HOMÔNIMO: `--filter` é regex NÃO ANCORADO, e 186/187/188 estão EM PRODUÇÃO ──
        $todos = [];
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz.'/tests'));
        foreach ($iterador as $arquivo) {
            // ⚠️ Flag DENTRO do laço.
            if (! $arquivo->isFile() || $arquivo->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/function (test_[A-Za-z0-9_]+)\(/', (string) file_get_contents($arquivo->getPathname()), $m);
            foreach ($m[1] as $nome) {
                $todos[$nome] = true;
            }
        }
        $todos = array_keys($todos);
        $this->assertGreaterThan(5000, count($todos), 'ISCA CEGA: a varredura de `tests/` achou quase nada — o homônimo rodaria sobre lista vazia');

        $colisoes = [];
        foreach ($meusMetodos as $meu) {
            // ⚠️ Flag DENTRO do laço — nas DUAS direções, incluindo PREFIXO.
            foreach ($todos as $outro) {
                if ($outro !== $meu && (str_contains($outro, $meu) || str_contains($meu, $outro))) {
                    $colisoes[] = $meu.' x '.$outro;
                }
            }
        }
        $this->assertSame([], $colisoes, 'homônimo: o `--filter` casaria método de OUTRA fase e a contagem somaria: '.implode(' | ', $colisoes));

        // ⚠️⚠️ **A ISCA QUE PROVA QUE A SONDA VÊ** — sem ela, `HOMONIMOS=0` é indistinguível de
        // sonda cega. `test_territorio_e_ausencias_do_plano` é substring de métodos REAIS das
        // Fases 187/188.
        $iscaDoPrefixo = array_values(array_filter($todos, static fn (string $n): bool => str_contains($n, 'test_territorio_e_ausencias_do_plano')));
        $this->assertGreaterThanOrEqual(5, count($iscaDoPrefixo), 'ISCA CEGA: a sonda de homônimo não acha os métodos `test_territorio_e_ausencias_do_plano` que EXISTEM no disco');
        $this->assertSame([], array_values(array_filter($todos, static fn (string $n): bool => str_contains($n, 'test_metodo_que_ninguem_escreveu_189_02'))), 'controle do homônimo: o nome inventado casou algo');

        // ⚠️ A PARTIDA desta wave é referência de LEITURA, e está declarada — nunca extremo de
        // range (o território é provado pelo ESCOPO DO ASSUNTO, no `pi-189-02.sh`).
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', self::PARTIDA);
    }
}
