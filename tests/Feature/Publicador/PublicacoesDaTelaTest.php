<?php

namespace Tests\Feature\Publicador;

use App\Http\Controllers\MlbAnuncioController;
use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Mlb\Acervo\AnuncioSaudeService;
use App\Services\Publicador\AcervoTriagemService;
use App\Services\Publicador\PublicacoesDaListaService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 180, plano 04 — o CONTRATO DE PROPS de `MlbAnuncioController::meus()`.
 *
 * ═══ O que este arquivo guarda (e uma asserção solta não guardaria) ══════════
 *
 * 1. **NADA REGREDIU.** As 13 props de hoje e as 24 chaves do item de hoje são
 *    percorridas a partir de uma LISTA LITERAL, não com `assertArrayHasKey` uma
 *    por uma: prop renomeada em silêncio é exatamente o que este caso existe
 *    para pegar. ⚠️ O plano dizia "22 chaves por item"; o número MEDIDO em
 *    `f5b898d2` é **24** (`ml_item_id … saude_ml_nao_se_aplica`) — a lista
 *    abaixo é a medida, não a do plano.
 * 2. **URL ↔ ESTADO (LIST-19).** Os 12 parâmetros vão na querystring e voltam
 *    na prop; valor inválido cai no default **sem lançar** e **sem** aparecer em
 *    nenhuma consulta. ⚠️ `?x[]=1` em parâmetro escalar já era 500 latente
 *    (`(string) []` vira `ErrorException` via `HandleExceptions`), e há um caso
 *    por parâmetro.
 * 3. **OS 5 DESTINOS DA VISÃO GERAL (Fase 179).**
 *    `CockpitDaContaService::resumoDePublicacoes()` monta
 *    `?status=ativos|pausados|em_revisao|encerrados|acionaveis`, e são CINCO
 *    asserções separadas para que desfazer qualquer arm reprove por motivo
 *    próprio. É isto que impede a Fase 180 de quebrar a Fase 179 em silêncio.
 * 4. **AS CONTAGENS SÃO SOBRE OS DEMAIS FILTROS (LIST-05)**, provado nos DOIS
 *    sentidos: com `tipo=premium` elas mudam; com `status=ativos` **não**. O
 *    segundo é o que prova que não é enfeite.
 * 5. **MARGEM NÃO EXISTE NO ITEM (LIST-12/EXMP-01)**, e a negativa roda com o
 *    item CHEIO (preço, vendas, estoque, produto, autor) — ausência provada com
 *    fixture vazia é vazia por construção.
 * 6. **O CUSTO ESTÁ TRAVADO (T-180-04-D).** O número de consultas da action é um
 *    literal justificado, e é o MESMO com 1 e com 50 linhas. É isso que faz um
 *    N+1 futuro reprovar — a Fase 179 deixou um N+1 conhecido por não ter este
 *    gate.
 *
 * @group phase180
 */
class PublicacoesDaTelaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * As 13 props que `meus()` manda HOJE. ⚠️ LISTA LITERAL de propósito:
     * nenhuma pode sair nem mudar de nome, e o laço acusa qual faltou.
     */
    private const PROPS_DE_HOJE = [
        'empresa', 'conta', 'cabecalho', 'sub', 'subTotais', 'anuncios',
        'rascunhos', 'triagem', 'filtros', 'skuNaoColetado', 'defasagem',
        'saudeMlDisponivel', 'rotacaoN',
    ];

    /** As 24 chaves do item de hoje, medidas em `f5b898d2`. */
    private const CHAVES_DO_ITEM_DE_HOJE = [
        'ml_item_id', 'titulo', 'thumbnail', 'permalink', 'origem', 'rascunho_id',
        'listing_tier', 'skus', 'status', 'estoque', 'vendas', 'visitas',
        'visitas_dias', 'buybox_status', 'buybox_nao_avaliado', 'nota_ecf',
        'nota_base', 'motivos', 'severidade', 'health_ml', 'performance_score',
        'performance_level', 'performance_acoes', 'saude_ml_nao_se_aplica',
    ];

    /** As 7 chaves de status que `$contagens` tem de trazer, + `rascunhos`. */
    private const CHAVES_DE_STATUS = [
        'todos', 'acionaveis', 'ativos', 'pausados', 'em_revisao', 'encerrados', 'com_erro',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // nenhuma chamada real ao ML nem ao ECF Drive
    }

    // ═══ Fixture ═════════════════════════════════════════════════════════════

    /** @return array{0: Company, 1: MlbEmpresa, 2: User} */
    private function conta(string $nome = 'Polo das Publicações'): array
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $company = Company::factory()->create(['name' => $nome]);

        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => (string) random_int(100000000, 999999999),
            'access_token'  => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_type'    => 'bearer',
            'scope'         => 'read write offline_access',
            'expires_at'    => now()->addDays(6),
            'status'        => 'active',
            'connected_at'  => now(),
        ]);

        $empresa = MlbEmpresa::create([
            'nome'       => $nome,
            'projeto'    => 'POLOS',
            'company_id' => $company->id,
        ]);

        return [$company->fresh(), $empresa->fresh(), $admin];
    }

    /** Uma linha do acervo. Os defaults são de item SAUDÁVEL e CHEIO. */
    private function acervo(Company $c, string $mlItemId, array $extra = []): MlAcervoItem
    {
        return MlAcervoItem::create($extra + [
            'company_id'         => $c->id,
            'ml_item_id'         => $mlItemId,
            'title'              => 'Anúncio '.$mlItemId,
            'status'             => 'active',
            'sub_status'         => [],
            'listing_type_id'    => 'gold_special',
            'catalog_listing'    => false,
            'available_quantity' => 10,
            'sold_quantity'      => 3,
            'price'              => 399.9,
            'skus'               => ['SKU-'.$mlItemId],
            'nota_ecf'           => 60,
            'motivos'            => [],
            'severidade'         => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'origem'             => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em'        => now(),
        ]);
    }

    private function produto(Company $c, MlbEmpresa $e, string $sku, string $nome, array $extra = []): PubProduto
    {
        return PubProduto::create($extra + [
            'company_id'     => $c->id,
            'mlb_empresa_id' => $e->id,
            'sku'            => $sku,
            'nome'           => $nome,
            'origem'         => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    /**
     * Uma publicação CONCLUÍDA do produto, com item CREATED no `ml_item_id`.
     *
     * ⚠️ `pub_rascunhos.produto_id` é UNIQUE: duas publicações do mesmo produto
     * são REVISÕES do mesmo rascunho, como no editor.
     */
    private function publicada(
        PubProduto $produto,
        string $mlItemId,
        string $listingType = 'gold_special',
        ?Carbon $concluidaEm = null,
        array $ator = ['equipe' => true, 'id' => 7, 'nome' => 'Ana Ribeiro'],
    ): PubPublicacao {
        $rascunho = PubRascunho::firstOrCreate(
            ['produto_id' => $produto->id],
            ['status' => PubRascunho::PUBLISHED, 'revisao' => 1],
        );

        $publicacao = PubPublicacao::create([
            'rascunho_id'        => $rascunho->id,
            'revisao'            => PubPublicacao::where('rascunho_id', $rascunho->id)->count() + 1,
            'modelo_publicacao'  => 'items',
            'status'             => PubPublicacao::PUBLISHED,
            'chave_idempotencia' => (string) Str::uuid(),
            'concluida_em'       => $concluidaEm ?? now()->subDay(),
            'ator'               => $ator,
        ]);

        PubPublicacaoItem::create([
            'publicacao_id'   => $publicacao->id,
            'indice'          => PubPublicacaoItem::where('publicacao_id', $publicacao->id)->count(),
            'listing_type_id' => $listingType,
            'variante_chave'  => ChaveCanonica::UNICA,
            'status'          => PubPublicacaoItem::CREATED,
            'ml_item_id'      => $mlItemId,
            'payload'         => [],
        ]);

        return $publicacao;
    }

    /** As props da tela, pela MESMA rota que o navegador usa. */
    private function props(User $admin, Company $company, array $query = []): array
    {
        $url = route('mlb.anuncios.meus', $company);
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get($url)
            ->assertOk()
            ->json('props');
    }

    private function inertiaVersion(): string
    {
        return (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    }

    /** Os `ml_item_id` que a listagem devolveu, na ordem. */
    private function idsDaLista(array $props): array
    {
        return array_map(fn (array $item) => $item['ml_item_id'], $props['anuncios']['data']);
    }

    // ═══ Caso 1 — o contrato de props ════════════════════════════════════════

    /** @group phase180 */
    public function test_contrato_de_props_intacto(): void
    {
        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB1000000001');

        $props = $this->props($admin, $company);

        foreach (self::PROPS_DE_HOJE as $prop) {
            $this->assertArrayHasKey(
                $prop,
                $props,
                "REGRESSÃO: a prop `{$prop}` existia antes da Fase 180 e saiu (ou foi renomeada). Toda mudança desta fase é ADITIVA",
            );
        }

        // E as novas, para que remover qualquer uma delas reprove aqui também.
        foreach (['abas', 'contagens', 'grupos', 'rodape', 'sincronizacao', 'faixaSucesso'] as $prop) {
            $this->assertArrayHasKey($prop, $props, "a prop nova `{$prop}` (Fase 180, plano 04) não chegou");
        }

        // `filtros` conserva as 4 chaves de hoje com o MESMO tipo.
        $this->assertIsString($props['filtros']['busca']);
        $this->assertIsString($props['filtros']['status']);
        $this->assertNull($props['filtros']['motivo']);
        $this->assertIsBool($props['filtros']['com_venda']);
    }

    /** @group phase180 */
    public function test_chaves_do_item_sao_aditivas(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-BENY', 'Poltrona Beny');
        $this->acervo($company, 'MLB1000000001');
        $this->publicada($produto, 'MLB1000000001');

        $item = $this->props($admin, $company)['anuncios']['data'][0];

        foreach (self::CHAVES_DO_ITEM_DE_HOJE as $chave) {
            $this->assertArrayHasKey(
                $chave,
                $item,
                "REGRESSÃO: a chave `{$chave}` do item existia antes da Fase 180. LIST-24 diz que `health_ml`/`performance_*`/`buybox_*`/`motivos`/`severidade` MIGRAM para o painel da Fase 181 — não que são apagados",
            );
        }

        foreach (['produto_id', 'produto_nome', 'produto_sku', 'fase', 'quantidade_kit', 'autor_nome', 'autor_tipo', 'publicada_em', 'tipo', 'catalogo'] as $chave) {
            $this->assertArrayHasKey($chave, $item, "a chave nova `{$chave}` não chegou no item");
        }

        // A metodologia vem do dado REAL, não de um placeholder.
        $this->assertSame($produto->id, $item['produto_id']);
        $this->assertSame('Poltrona Beny', $item['produto_nome']);
        $this->assertSame('SKU-BENY', $item['produto_sku']);
        $this->assertSame('Ana Ribeiro', $item['autor_nome']);
        $this->assertSame('pessoa', $item['autor_tipo']);
        $this->assertSame(AcervoTriagemService::TIPO_CLASSICO, $item['tipo']);
        $this->assertFalse($item['catalogo']);

        // ⚠️ Nenhum campo do item pode ser OBJETO: herança da tela preta de
        // 07/10/2026, em que um campo que chegou como objeto e foi renderizado
        // como texto derrubou a PÁGINA INTEIRA.
        foreach ($item as $chave => $valor) {
            $this->assertTrue(
                $valor === null || is_scalar($valor) || is_array($valor),
                "o campo `{$chave}` não é escalar/array/nulo — campo objeto já derrubou esta tela inteira",
            );
        }
    }

    /** @group phase180 */
    public function test_item_legado_vem_com_as_chaves_nulas(): void
    {
        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB1000000001'); // sem publicação no Publicador

        $item = $this->props($admin, $company)['anuncios']['data'][0];

        foreach (['produto_id', 'produto_nome', 'produto_sku', 'fase', 'quantidade_kit', 'autor_nome', 'autor_tipo', 'publicada_em'] as $chave) {
            $this->assertNull(
                $item[$chave],
                "item LEGADO tem de vir com `{$chave}` nulo — quem desenha a palavra \"Legado\" é a tela, e o servidor NUNCA manda a string nem uma `fase = 0`",
            );
        }

        // ⚠️ `estoque` do item NÃO é nulo por ser legado: ele vem do acervo.
        $this->assertSame(10, $item['estoque'], 'o `estoque` do item é `available_quantity` do acervo, independente do Publicador');
    }

    /** @group phase180 */
    public function test_nota_base_continua_de_oitenta_e_seis(): void
    {
        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB1000000001');

        $item = $this->props($admin, $company)['anuncios']['data'][0];

        $this->assertSame(AnuncioSaudeService::BASE, $item['nota_base']);
        // ⚠️ E o literal, também: se alguém mudar a constante, a tela continua
        // dizendo "de 86" (D-22/LIST-24) e este teste tem de acusar o
        // DESALINHAMENTO, não acompanhá-lo em silêncio.
        $this->assertSame(86, $item['nota_base'], 'a Nota ECF é "X de 86" e NUNCA é renormalizada para 100 (D-22/LIST-24)');
    }

    // ═══ Casos 4/5/7 — URL ↔ estado (LIST-19) ════════════════════════════════

    /** @group phase180 */
    public function test_filtros_voltam_na_prop(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-1', 'Poltrona', ['fase' => 2, 'quantidade_kit' => 2]);
        $this->acervo($company, 'MLB1000000001', ['listing_type_id' => 'gold_pro', 'catalog_listing' => true]);
        $this->publicada($produto, 'MLB1000000001', 'gold_pro');

        $props = $this->props($admin, $company, [
            'busca'       => 'Poltrona',
            'status'      => 'todos',
            'motivo'      => MlAcervoItem::MOTIVO_PAUSADO,
            'sub'         => 'rascunhos',
            'comVenda'    => '1',
            'fase'        => '2',
            'tipo'        => 'premium',
            'catalogo'    => '1',
            'responsavel' => [7],
            'periodo'     => '30d',
            'visao'       => 'lista',
            'sel'         => 'MLB1000000001',
        ]);

        // Os 11 que voltam em `filtros` + o 12º (`sub`), que tem prop própria
        // desde a Fase 134 e continua tendo.
        $this->assertSame('Poltrona', $props['filtros']['busca']);
        $this->assertSame('todos', $props['filtros']['status']);
        $this->assertSame(MlAcervoItem::MOTIVO_PAUSADO, $props['filtros']['motivo']);
        $this->assertTrue($props['filtros']['com_venda']);
        $this->assertSame(2, $props['filtros']['fase']);
        $this->assertSame('premium', $props['filtros']['tipo']);
        $this->assertTrue($props['filtros']['catalogo']);
        $this->assertSame([7], $props['filtros']['responsavel']);
        $this->assertSame('30d', $props['filtros']['periodo']);
        $this->assertSame('lista', $props['filtros']['visao']);
        $this->assertSame('MLB1000000001', $props['filtros']['sel']);
        $this->assertSame('rascunhos', $props['sub'], '`sub` continua em prop própria (D-14) — é o 12º parâmetro da URL');

        // E o default de `visao` é "Por produto" (LIST-09), não "Lista".
        $this->assertSame('grupo', $this->props($admin, $company)['filtros']['visao']);
    }

    /** @group phase180 */
    public function test_valor_invalido_cai_no_default_sem_lancar_e_sem_chegar_ao_sql(): void
    {
        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB1000000001');

        $sujeira = '; DROP TABLE ml_acervo_itens; --';

        DB::enableQueryLog();
        DB::flushQueryLog();

        $props = $this->props($admin, $company, [
            // escalar onde é escalar, mas valor fora da lista fechada
            'status'   => $sujeira,
            'tipo'     => $sujeira,
            'periodo'  => $sujeira,
            'visao'    => $sujeira,
            'sel'      => $sujeira,
            'fase'     => '-1',
            'motivo'   => $sujeira,
            // array onde é escalar — era 500 latente via `(string) []`
            'busca'    => ['a' => $sujeira],
            'sub'      => [$sujeira],
            'catalogo' => [$sujeira],
            // escalar onde é array, e com lixo dentro
            'responsavel' => ['0', '-3', '1.5', '1e3', $sujeira, ['aninhado'], null],
        ]);

        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        // Todos no default.
        $this->assertSame('acionaveis', $props['filtros']['status']);
        $this->assertNull($props['filtros']['tipo']);
        $this->assertNull($props['filtros']['periodo']);
        $this->assertSame('grupo', $props['filtros']['visao']);
        $this->assertNull($props['filtros']['sel'], 'sel só sobrevive com a FORMA /^MLB\d+$/');
        $this->assertNull($props['filtros']['fase'], 'fase é lista fechada de duas: 1 e 2');
        $this->assertNull($props['filtros']['motivo']);
        $this->assertSame('', $props['filtros']['busca'], 'array em `busca` cai na string vazia, não em `Array` nem em 500');
        $this->assertSame('publicados', $props['sub']);
        $this->assertFalse($props['filtros']['catalogo']);
        $this->assertSame([], $props['filtros']['responsavel'], 'nenhum dos 7 lixos é um id de usuário válido');

        // ⚠️ E a sujeira NÃO aparece em consulta nenhuma — nem como bind.
        $this->assertNotSame([], $consultas, 'o log de consultas está vazio: o gate seria vazio por construção');
        foreach ($consultas as $consulta) {
            $this->assertStringNotContainsStringIgnoringCase(
                'DROP TABLE',
                $consulta['query'],
                'termo do usuário chegou ao SQL — a lista fechada existe para que isso seja impossível (T-180-04-T)',
            );
            foreach ($consulta['bindings'] as $bind) {
                $this->assertNotSame($sujeira, $bind, 'o valor inválido virou BIND em vez de cair no default');
            }
        }
    }

    /** @group phase180 */
    public function test_sel_so_sobrevive_com_a_forma_de_ml_item_id(): void
    {
        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB1000000001');

        $this->assertSame('MLB123', $this->props($admin, $company, ['sel' => 'MLB123'])['filtros']['sel']);
        $this->assertNull($this->props($admin, $company, ['sel' => 'xxx'])['filtros']['sel']);
        $this->assertNull($this->props($admin, $company, ['sel' => 'MLB'])['filtros']['sel']);
        $this->assertNull($this->props($admin, $company, ['sel' => 'mlb123'])['filtros']['sel']);

        // ⚠️ `sel` NÃO é lido do banco nesta fase: um id que não existe volta
        // igual, porque a tela só precisa saber qual linha pintar. Ler para
        // validar custaria uma consulta por request, e quem vai ler de propósito
        // é o painel da Fase 181.
        $this->assertSame('MLB999999999', $this->props($admin, $company, ['sel' => 'MLB999999999'])['filtros']['sel']);
    }

    /** @group phase180 */
    public function test_paginacao_preserva_todos_os_filtros_nos_links(): void
    {
        [$company, , $admin] = $this->conta();
        for ($i = 1; $i <= 51; $i++) {
            $this->acervo($company, 'MLB'.str_pad((string) $i, 10, '0', STR_PAD_LEFT));
        }

        $props = $this->props($admin, $company, [
            'status'   => 'todos',
            'tipo'     => 'classico',
            'catalogo' => '0',
            'visao'    => 'lista',
            'sel'      => 'MLB0000000001',
            'page'     => '2',
        ]);

        $this->assertSame(2, $props['anuncios']['current_page']);
        $this->assertCount(1, $props['anuncios']['data'], 'a paginação de 50 continua');

        $links = implode(' ', array_filter(array_column($props['anuncios']['links'], 'url')));
        foreach (['status=todos', 'tipo=classico', 'visao=lista', 'sel=MLB0000000001'] as $pedaco) {
            $this->assertStringContainsString(
                $pedaco,
                $links,
                "`->withQueryString()` saiu do `paginate(50)`: o link do paginator perdeu `{$pedaco}` e trocar de página zeraria o recorte",
            );
        }
    }

    /** @group phase180 */
    public function test_page_fora_de_faixa_nao_explode(): void
    {
        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB1000000001');

        // O bug latente que esta fase tem de TOLERAR: filtrar na página 7 pode
        // cair num vazio. Zerar o `page` é do plano 180-11; o servidor só não
        // pode explodir.
        $props = $this->props($admin, $company, ['page' => '7']);

        $this->assertSame([], $props['anuncios']['data']);
        $this->assertSame(1, $props['anuncios']['total']);
        $this->assertSame([], $props['grupos'], 'página vazia não inventa grupo');
        $this->assertSame(0, $props['rodape']['visiveis']);
        $this->assertSame(1, $props['rodape']['total']);
    }

    // ═══ Caso 8 — os 5 destinos da Visão Geral (Fase 179) ════════════════════

    /** @group phase180 */
    public function test_destino_ativos_da_visao_geral(): void
    {
        [$company, , $admin] = $this->conta();
        $this->comUmItemDeCadaStatus($company);

        $this->assertSame(
            ['MLB-ATIVO'],
            $this->idsDaLista($this->props($admin, $company, ['status' => 'ativos'])),
            'o destino `?status=ativos` da Visão Geral (Fase 179) mudou de recorte',
        );
    }

    /** @group phase180 */
    public function test_destino_pausados_da_visao_geral(): void
    {
        [$company, , $admin] = $this->conta();
        $this->comUmItemDeCadaStatus($company);

        $this->assertSame(
            ['MLB-PAUSADO'],
            $this->idsDaLista($this->props($admin, $company, ['status' => 'pausados'])),
            'o destino `?status=pausados` da Visão Geral (Fase 179) mudou de recorte',
        );
    }

    /** @group phase180 */
    public function test_destino_em_revisao_da_visao_geral(): void
    {
        [$company, , $admin] = $this->conta();
        $this->comUmItemDeCadaStatus($company);

        $this->assertSame(
            ['MLB-REVISAO'],
            $this->idsDaLista($this->props($admin, $company, ['status' => 'em_revisao'])),
            'o destino `?status=em_revisao` (quick 261010-rie, "Em revisão quero poder isolar") mudou de recorte',
        );
    }

    /** @group phase180 */
    public function test_destino_encerrados_da_visao_geral(): void
    {
        [$company, , $admin] = $this->conta();
        $this->comUmItemDeCadaStatus($company);

        $this->assertSame(
            ['MLB-FECHADO'],
            $this->idsDaLista($this->props($admin, $company, ['status' => 'encerrados'])),
            'o destino `?status=encerrados` da Visão Geral (Fase 179) mudou de recorte — ⚠️ `encerrados` não tem ABA no layout novo, mas continua valor válido de status',
        );
    }

    /** @group phase180 */
    public function test_destino_acionaveis_da_visao_geral(): void
    {
        [$company, , $admin] = $this->conta();
        $this->comUmItemDeCadaStatus($company);

        $ids = $this->idsDaLista($this->props($admin, $company, ['status' => 'acionaveis']));
        sort($ids);

        $this->assertSame(
            ['MLB-ATIVO', 'MLB-PAUSADO', 'MLB-REVISAO'],
            $ids,
            'o "Pedem ação" da Visão Geral (Fase 179) mudou de recorte — e `acionaveis` é o DEFAULT da tela',
        );
    }

    /** @group phase180 */
    public function test_com_erro_e_o_setimo_valor_da_lista_fechada(): void
    {
        [$company, , $admin] = $this->conta();
        $this->comUmItemDeCadaStatus($company);
        $this->acervo($company, 'MLB-ERRO', ['status' => 'under_review', 'sub_status' => ['waiting_for_patch']]);

        $props = $this->props($admin, $company, ['status' => AcervoTriagemService::STATUS_COM_ERRO]);

        $this->assertSame(
            AcervoTriagemService::STATUS_COM_ERRO,
            $props['filtros']['status'],
            'sem o 7º valor na lista fechada do controller, `?status=com_erro` é COAGIDO para `acionaveis` em silêncio e a aba nova nunca chega ao serviço',
        );
        $this->assertSame(['MLB-ERRO'], $this->idsDaLista($props));
    }

    // ═══ Casos 9/10 — as contagens (LIST-05) ═════════════════════════════════

    /** @group phase180 */
    public function test_contagens_sao_sobre_os_demais_filtros(): void
    {
        [$company, , $admin] = $this->conta();
        $this->comUmItemDeCadaStatus($company);
        $this->acervo($company, 'MLB-PREMIUM', ['status' => 'active', 'listing_type_id' => 'gold_pro']);

        $semFiltro = $this->props($admin, $company)['contagens'];

        // ⚠️ SENTIDO 1 — um filtro que NÃO é status muda as contagens.
        $comTipo = $this->props($admin, $company, ['tipo' => 'premium'])['contagens'];
        $this->assertSame(2, $semFiltro['ativos'], 'a premissa do caso');
        $this->assertSame(1, $comTipo['ativos'], '`tipo=premium` tem de estreitar a contagem das abas');
        $this->assertSame(1, $comTipo['todos']);

        // ⚠️ SENTIDO 2 — o filtro de STATUS **não** muda nada. É este que prova
        // que as contagens não são enfeite: elas são sobre os DEMAIS filtros.
        foreach (['ativos', 'pausados', 'em_revisao', 'encerrados', 'acionaveis', 'todos', 'com_erro'] as $chave) {
            $this->assertSame(
                $semFiltro[$chave],
                $this->props($admin, $company, ['status' => 'ativos'])['contagens'][$chave],
                "a contagem de `{$chave}` mudou com `status=ativos` — o eixo de status NÃO entra nas próprias contagens (LIST-05)",
            );
        }
    }

    /**
     * ⚠️ O gate que guarda a DUPLICAÇÃO: a soma de `acionaveis` e os nomes de
     * coluna do agregado repetem o `match` de `AcervoTriagemService::escopo()`.
     * Aqui as 7 chaves são conferidas contra o próprio serviço — se o `match`
     * mudar e o agregado não, isto reprova.
     *
     * @group phase180
     */
    public function test_agregado_bate_com_o_servico_nas_sete_chaves(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $this->comUmItemDeCadaStatus($company);
        $this->acervo($company, 'MLB-ERRO', ['status' => 'under_review', 'sub_status' => ['forbidden']]);
        $this->acervo($company, 'MLB-INATIVO', ['status' => 'inactive']);
        $produto = $this->produto($company, $empresa, 'SKU-X', 'Produto X');
        $this->publicada($produto, 'MLB-ATIVO');

        $contagens = $this->props($admin, $company, ['status' => 'pausados'])['contagens'];

        $triagem = new AcervoTriagemService;
        $lista   = new PublicacoesDaListaService;

        foreach (self::CHAVES_DE_STATUS as $chave) {
            $doServico = $triagem->filtros($triagem->escopo($company, '', $chave, $lista), [])->count();

            $this->assertSame(
                $doServico,
                $contagens[$chave],
                "a contagem agregada de `{$chave}` divergiu do `filtros(escopo(…, '{$chave}'))->count()` do serviço — o `match` de `escopo()` é a FONTE ÚNICA e o agregado deste controller tem de acompanhá-lo",
            );
        }

        // E `todos` inclui status que nenhuma aba nomeia (`inactive`).
        $this->assertSame(6, $contagens['todos'], '`todos` é tudo, inclusive `inactive`, que não tem aba');
        $this->assertSame(
            ['todos', 'acionaveis', 'ativos', 'pausados', 'em_revisao', 'encerrados', 'com_erro', 'rascunhos'],
            array_keys($contagens),
            '`contagens` traz os 7 eixos de status + `rascunhos`. ⚠️ `encerrados` e `acionaveis` NÃO têm aba no layout novo, mas são destinos vivos da Visão Geral e `acionaveis` é o default — a contagem deles não pode desaparecer',
        );
    }

    /** @group phase180 */
    public function test_com_dot_so_acende_quando_com_erro_e_maior_que_zero(): void
    {
        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB-SADIO', ['status' => 'active', 'sub_status' => []]);

        $abas = collect($this->props($admin, $company, ['status' => 'todos'])['abas'])->keyBy('chave');

        $this->assertSame(0, $abas[AcervoTriagemService::STATUS_COM_ERRO]['total'], 'zero HONESTO: a contagem é 0, nunca `null`');
        $this->assertFalse($abas[AcervoTriagemService::STATUS_COM_ERRO]['comDot'], 'com 0 erros o ponto vermelho não acende — `>= 0` acenderia sempre');

        $this->acervo($company, 'MLB-ERRO', ['status' => 'under_review', 'sub_status' => ['held']]);

        $abas = collect($this->props($admin, $company, ['status' => 'todos'])['abas'])->keyBy('chave');
        $this->assertSame(1, $abas[AcervoTriagemService::STATUS_COM_ERRO]['total']);
        $this->assertTrue($abas[AcervoTriagemService::STATUS_COM_ERRO]['comDot']);

        // E nenhuma OUTRA aba acende o ponto.
        foreach ($abas as $chave => $aba) {
            if ($chave !== AcervoTriagemService::STATUS_COM_ERRO) {
                $this->assertFalse($aba['comDot'], "a aba `{$chave}` não tem ponto vermelho — ele é só do eixo `sub_status`");
            }
        }
    }

    /** @group phase180 */
    public function test_as_seis_abas_vem_na_ordem_do_servidor(): void
    {
        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB1000000001');
        MlAnuncioRascunho::create([
            'company_id' => $company->id,
            'user_id'    => $admin->id,
            'status'     => 'rascunho',
            'payload'    => ['title' => 'X'],
        ]);

        $props = $this->props($admin, $company);

        $this->assertSame(
            ['todos', 'ativos', 'pausados', 'com_erro', 'em_revisao', 'rascunhos'],
            array_column($props['abas'], 'chave'),
            'a ORDEM das 6 abas é contrato do plano 180-06 ("na ordem que o servidor manda")',
        );

        foreach ($props['abas'] as $aba) {
            $this->assertSame(['chave', 'rotulo', 'total', 'comDot'], array_keys($aba), 'o shape da aba é o que `AbasDeStatus.jsx` consome');
            $this->assertIsInt($aba['total']);
            $this->assertIsBool($aba['comDot']);
        }

        // "Rascunhos" é a 6ª (LIST-25) e seu contador é o `subTotais` de hoje.
        $this->assertSame($props['subTotais']['rascunhos'], $props['abas'][5]['total']);
        $this->assertSame(1, $props['abas'][5]['total']);
    }

    // ═══ Casos 11-15 — os grupos (LIST-09) ═══════════════════════════════════

    /** @group phase180 */
    public function test_cobertura_acende_as_celulas_reais(): void
    {
        [$company, $empresa, $admin] = $this->conta();

        // Produto com F1 Clássico + F2 Premium.
        $base = $this->produto($company, $empresa, 'SKU-BASE', 'Poltrona Beny', ['fase' => 1]);
        $kit  = $this->produto($company, $empresa, 'SKU-KIT', 'Kit 2 Poltronas Beny', ['fase' => 2, 'quantidade_kit' => 2]);

        $this->acervo($company, 'MLB-F1C', ['listing_type_id' => 'gold_special']);
        $this->acervo($company, 'MLB-F2P', ['listing_type_id' => 'gold_pro']);
        $this->publicada($base, 'MLB-F1C', 'gold_special');
        $this->publicada($kit, 'MLB-F2P', 'gold_pro');

        $grupos = collect($this->props($admin, $company, ['status' => 'todos'])['grupos'])->keyBy('produto_id');

        $this->assertTrue($grupos[$base->id]['cobertura']['f1_classico']);
        $this->assertFalse($grupos[$base->id]['cobertura']['f1_premium']);
        $this->assertFalse($grupos[$base->id]['cobertura']['f2_classico']);
        $this->assertFalse($grupos[$base->id]['cobertura']['f2_premium']);
        $this->assertTrue($grupos[$base->id]['sem_fase2'], 'o produto base não tem fase 2 — é o que acende o atalho "Criar combo"');

        $this->assertTrue($grupos[$kit->id]['cobertura']['f2_premium']);
        $this->assertFalse($grupos[$kit->id]['sem_fase2']);

        $this->assertSame(
            ['f1_classico', 'f1_premium', 'f2_classico', 'f2_premium'],
            array_keys($grupos[$base->id]['cobertura']),
            'as 4 chaves são EXATAMENTE as de `MapaDeCobertura.jsx` — nome diferente desenharia 4 células tracejadas em silêncio',
        );
    }

    /** @group phase180 */
    public function test_grupo_filtrado_mostra_x_de_n(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-TOAL', 'Jogo de Toalhas', ['fase' => 1]);

        $this->acervo($company, 'MLB-ATIVO-T', ['status' => 'active']);
        $this->acervo($company, 'MLB-FECHADO-T', ['status' => 'closed']);
        $this->publicada($produto, 'MLB-ATIVO-T');
        $this->publicada($produto, 'MLB-FECHADO-T');

        $semFiltro = $this->props($admin, $company, ['status' => 'todos'])['grupos'][0];
        $this->assertSame(2, $semFiltro['total_visivel']);
        $this->assertSame(2, $semFiltro['total_no_produto'], 'sem filtro, visível == total, e a tela não desenha o "x de N"');

        $comFiltro = $this->props($admin, $company, ['status' => 'ativos'])['grupos'][0];
        $this->assertSame(1, $comFiltro['total_visivel']);
        $this->assertSame(2, $comFiltro['total_no_produto'], 'o "x de N" só existe se `total_no_produto` contar TODAS as publicações do produto, não só as da página');
        $this->assertLessThan($comFiltro['total_no_produto'], $comFiltro['total_visivel']);
    }

    /** @group phase180 */
    public function test_grupo_legado_e_proprio_e_fica_no_fim(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-COM', 'Produto Com Dono', ['fase' => 1]);

        // O legado vem PRIMEIRO na ordenação por gravidade (severidade 2), para
        // que "fica no fim" não seja verdade por acidente de ordem.
        $this->acervo($company, 'MLB-LEGADO', [
            'status'     => 'paused',
            'motivos'    => [MlAcervoItem::MOTIVO_PAUSADO],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);
        $this->acervo($company, 'MLB-COM-DONO', ['status' => 'active']);
        $this->publicada($produto, 'MLB-COM-DONO');

        $props  = $this->props($admin, $company, ['status' => 'todos']);
        $grupos = $props['grupos'];

        $this->assertSame(
            'MLB-LEGADO',
            $props['anuncios']['data'][0]['ml_item_id'],
            'a premissa: na LISTA o legado vem primeiro (gravidade, D-12)',
        );

        $this->assertCount(2, $grupos);
        $this->assertSame($produto->id, $grupos[0]['produto_id'], 'o grupo com produto vem antes');
        $this->assertNull($grupos[1]['produto_id'], 'o grupo das publicações sem produto é PRÓPRIO e fica no FIM');
        $this->assertSame(MlbAnuncioController::GRUPO_SEM_PRODUTO, $grupos[1]['produto_nome']);
        $this->assertNotSame('—', $grupos[1]['produto_nome'], 'o grupo legado DIZ o que é; "—" não diz nada (EXMP-01)');
        $this->assertNull($grupos[1]['ver_produto_url']);
        $this->assertNull($grupos[1]['criar_combo_url']);
        $this->assertSame(['MLB-LEGADO'], $grupos[1]['ml_item_ids']);
    }

    /** @group phase180 */
    public function test_visao_lista_nao_manda_grupo(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-1', 'Produto');
        $this->acervo($company, 'MLB1000000001');
        $this->publicada($produto, 'MLB1000000001');

        $this->assertSame([], $this->props($admin, $company, ['visao' => 'lista'])['grupos'], 'na visão Lista a tela usa `anuncios.data` direto');
        $this->assertNotSame([], $this->props($admin, $company, ['visao' => 'grupo'])['grupos']);
    }

    /** @group phase180 */
    public function test_nenhum_botao_sem_destino(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $base = $this->produto($company, $empresa, 'SKU-BASE', 'Produto Base', ['fase' => 1]);
        $kit  = $this->produto($company, $empresa, 'SKU-KIT', 'Kit do Produto', ['fase' => 2, 'quantidade_kit' => 2]);

        $this->acervo($company, 'MLB-BASE');
        $this->acervo($company, 'MLB-KIT', ['listing_type_id' => 'gold_pro']);
        $this->publicada($base, 'MLB-BASE', 'gold_special');
        $this->publicada($kit, 'MLB-KIT', 'gold_pro');

        $grupos = collect($this->props($admin, $company, ['status' => 'todos'])['grupos'])->keyBy('produto_id');

        // "Ver produto" tem destino REAL e por produto: o editor.
        $this->assertSame(
            route('mlb.anuncios.publicador.editor', ['produto' => $base->id]),
            $grupos[$base->id]['ver_produto_url'],
        );

        // "Criar combo" só existe quando falta a fase 2.
        $this->assertNotNull($grupos[$base->id]['criar_combo_url']);
        $this->assertNull($grupos[$kit->id]['criar_combo_url'], 'quem já tem fase 2 não recebe o atalho de criar combo');

        // ⚠️ E NUNCA `'#'`: `null` é o que faz a tela não desenhar o link
        // (EXMP-01 — nenhum botão sem destino).
        foreach ($grupos as $grupo) {
            foreach (['ver_produto_url', 'criar_combo_url'] as $chave) {
                $this->assertNotSame('#', $grupo[$chave], "`{$chave}` virou um `#` — link morto é pior que link ausente");
                $this->assertTrue(
                    $grupo[$chave] === null || (is_string($grupo[$chave]) && str_starts_with($grupo[$chave], 'http')),
                    "`{$chave}` tem de ser `null` ou URL de verdade",
                );
            }
        }
    }

    /** @group phase180 */
    public function test_ordem_dentro_do_grupo_segue_o_prototipo(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $base = $this->produto($company, $empresa, 'SKU-B', 'Base', ['fase' => 1]);

        // Mesma fase, tipos diferentes: Premium antes de Clássico.
        $this->acervo($company, 'MLB-F1-CLASSICO', ['listing_type_id' => 'gold_special']);
        $this->acervo($company, 'MLB-F1-PREMIUM', ['listing_type_id' => 'gold_pro']);
        $this->publicada($base, 'MLB-F1-CLASSICO', 'gold_special', now()->subDays(5));
        $this->publicada($base, 'MLB-F1-PREMIUM', 'gold_pro', now()->subDays(9));

        $grupo = $this->props($admin, $company, ['status' => 'todos'])['grupos'][0];

        $this->assertSame(
            ['MLB-F1-PREMIUM', 'MLB-F1-CLASSICO'],
            $grupo['ml_item_ids'],
            'Premium antes de Clássico dentro da mesma fase (protótipo `Publicacoes v2.dc.html:566`) — e a data mais recente NÃO ganha do tipo',
        );
    }

    /** @group phase180 */
    public function test_rodape_bate_com_o_paginator(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-1', 'Produto');
        for ($i = 1; $i <= 51; $i++) {
            $this->acervo($company, 'MLB'.str_pad((string) $i, 10, '0', STR_PAD_LEFT));
        }
        $this->publicada($produto, 'MLB0000000001');

        $props = $this->props($admin, $company, ['status' => 'todos']);

        $this->assertSame(50, $props['rodape']['visiveis'], 'a paginação de 50 continua, e o rodapé a reflete');
        $this->assertSame(51, $props['rodape']['total']);
        $this->assertSame($props['anuncios']['total'], $props['rodape']['total'], '`total` é o do paginator — contar de novo seria pagar duas vezes');
        $this->assertSame(1, $props['rodape']['produtos'], 'contagem DISTINTA de `produto_id` da página; o Legado não tem produto que contar');
    }

    // ═══ Caso 16 — margem (LIST-12 / EXMP-01) ════════════════════════════════

    /** @group phase180 */
    public function test_item_nao_tem_margem_nem_lucro(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-1', 'Produto Cheio', ['fase' => 1]);

        // ⚠️ Item CHEIO: preço, vendas, estoque, visitas, produto e autor. A
        // negativa com fixture vazia é vazia por construção (o 10º dos dez
        // casos de asserção que casa sempre).
        $this->acervo($company, 'MLB1000000001', [
            'price'              => 1099.9,
            'sold_quantity'      => 61,
            'available_quantity' => 24,
            'visitas_30d'        => 2410,
            'nota_ecf'           => 72,
        ]);
        $this->publicada($produto, 'MLB1000000001');

        $item = $this->props($admin, $company)['anuncios']['data'][0];

        $this->assertSame(61, $item['vendas'], 'a premissa: o item está CHEIO');
        $this->assertSame('Produto Cheio', $item['produto_nome']);

        foreach (['margem', 'lucro', 'repasse', 'tarifa'] as $proibida) {
            $this->assertArrayNotHasKey(
                $proibida,
                $item,
                "a chave `{$proibida}` apareceu no item. LIST-12: a tarifa do ML não está no banco, a margem é ASSÍNCRONA (Fase 181) e até ela chegar a célula mostra \"—\" — nunca um número estimado, nunca zero",
            );
        }
    }

    /** @group phase180 */
    public function test_nenhum_segredo_sai_no_payload(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-1', 'Produto');
        $this->acervo($company, 'MLB1000000001');
        $this->publicada($produto, 'MLB1000000001');

        $json = json_encode($this->props($admin, $company), JSON_THROW_ON_ERROR);

        foreach (['fake-access-token', 'fake-refresh-token', 'access_token', 'refresh_token'] as $segredo) {
            $this->assertStringNotContainsString(
                $segredo,
                $json,
                "`{$segredo}` vazou no payload da tela — a empresa TEM `MlToken` neste fixture, então a negativa não é vazia por construção",
            );
        }

        // E nenhum id de usuário do ator sai junto com o nome (T-180-04-I).
        $item = $this->props($admin, $company)['anuncios']['data'][0];
        $this->assertArrayNotHasKey('autor_id', $item);
        $this->assertSame('Ana Ribeiro', $item['autor_nome'], 'sai o nome de exibição, nunca o id');
    }

    // ═══ Os filtros do Publicador: fase, responsável e período ═══════════════

    /** @group phase180 */
    public function test_fase_responsavel_e_periodo_compoem_por_intersecao(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $f1 = $this->produto($company, $empresa, 'SKU-F1', 'Base', ['fase' => 1]);
        $f2 = $this->produto($company, $empresa, 'SKU-F2', 'Kit', ['fase' => 2, 'quantidade_kit' => 2]);

        foreach (['MLB-F1-ANA', 'MLB-F2-ANA', 'MLB-F2-BRUNO'] as $id) {
            $this->acervo($company, $id);
        }

        $this->publicada($f1, 'MLB-F1-ANA', 'gold_special', now()->subDays(2), ['equipe' => true, 'id' => 7, 'nome' => 'Ana']);
        $this->publicada($f2, 'MLB-F2-ANA', 'gold_special', now()->subDays(2), ['equipe' => true, 'id' => 7, 'nome' => 'Ana']);
        $this->publicada($f2, 'MLB-F2-BRUNO', 'gold_special', now()->subDays(40), ['equipe' => true, 'id' => 9, 'nome' => 'Bruno']);

        $so = fn (array $q) => $this->idsDaLista($this->props($admin, $company, $q + ['status' => 'todos']));

        $this->assertSame(['MLB-F1-ANA'], $so(['fase' => '1']));
        $ids = $so(['fase' => '2']);
        sort($ids);
        $this->assertSame(['MLB-F2-ANA', 'MLB-F2-BRUNO'], $ids);

        // ⚠️ INTERSEÇÃO, não união: fase 2 E a Ana.
        $this->assertSame(
            ['MLB-F2-ANA'],
            $so(['fase' => '2', 'responsavel' => [7]]),
            'dois filtros ativos é E, nunca OU',
        );

        // Período: 30d deixa o do 40º dia de fora.
        $ids = $so(['periodo' => '30d']);
        sort($ids);
        $this->assertSame(['MLB-F1-ANA', 'MLB-F2-ANA'], $ids, '`periodo` sai de `pub_publicacoes.concluida_em` — `ml_acervo_itens` não tem coluna de data de publicação');

        // E os três juntos.
        $this->assertSame(['MLB-F2-ANA'], $so(['fase' => '2', 'responsavel' => [7], 'periodo' => '30d']));
    }

    /** @group phase180 */
    public function test_filtro_que_nao_casa_nada_mostra_zero_e_nao_o_acervo_inteiro(): void
    {
        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB1000000001');
        $this->acervo($company, 'MLB1000000002');

        // Nenhum produto, nenhuma publicação: o filtro de fase casa com ZERO.
        $props = $this->props($admin, $company, ['status' => 'todos', 'fase' => '2']);

        $this->assertSame(
            [],
            $this->idsDaLista($props),
            '`[]` significa "o filtro casou com ZERO publicações" e TEM de dar zero linhas. Tratá-lo como `null` ("sem filtro") mostraria o acervo INTEIRO justamente quando o recorte não achou nada',
        );
        $this->assertSame(0, $props['contagens']['todos']);
        $this->assertSame(2, $props['subTotais']['publicados'], 'a premissa: o acervo tem 2 linhas, então o vazio não é vazio por construção');
    }

    /** @group phase180 */
    public function test_responsavel_nunca_casa_ator_de_cliente(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-1', 'Produto', ['fase' => 1]);
        $this->acervo($company, 'MLB-CLIENTE');
        // `equipe => false` com o MESMO id 7: é o cliente pelo Portal, cujo id
        // vive em `portal_usuarios`. Casar por id sem olhar `equipe` confundiria
        // um cliente com um colega de mesmo id numérico (T-173-09).
        $this->publicada($produto, 'MLB-CLIENTE', 'gold_special', now()->subDay(), ['equipe' => false, 'id' => 7, 'nome' => 'Fulano Cliente']);

        $props = $this->props($admin, $company, ['status' => 'todos', 'responsavel' => [7]]);

        $this->assertSame([], $this->idsDaLista($props));

        // E o nome do cliente não sai do servidor (nem com o filtro desligado).
        $json = json_encode($this->props($admin, $company, ['status' => 'todos']), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Fulano Cliente', $json, 'nome de cliente NUNCA sai numa tela interna (T-173-09)');
    }

    /** @group phase180 */
    public function test_tipo_do_item_e_o_inverso_do_filtro_do_servico(): void
    {
        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB-CLASSICO', ['listing_type_id' => 'gold_special']);
        $this->acervo($company, 'MLB-PREMIUM', ['listing_type_id' => 'gold_pro']);
        $this->acervo($company, 'MLB-OUTRO', ['listing_type_id' => 'free']);

        // ⚠️ O gate que liga os dois mapas: o do serviço (tipo → coluna) e o
        // deste controller (coluna → tipo). Se um deles mudar sozinho, o filtro
        // devolve item cujo `tipo` não é o filtrado.
        foreach ([AcervoTriagemService::TIPO_CLASSICO, AcervoTriagemService::TIPO_PREMIUM] as $tipo) {
            $itens = $this->props($admin, $company, ['status' => 'todos', 'tipo' => $tipo])['anuncios']['data'];

            $this->assertNotSame([], $itens, "o filtro `tipo={$tipo}` não devolveu nada — a premissa do caso");
            foreach ($itens as $item) {
                $this->assertSame($tipo, $item['tipo'], "o filtro `tipo={$tipo}` devolveu item com `tipo` diferente: os dois mapas divergiram");
            }
        }

        // `listing_type_id` desconhecido não é promovido a Clássico.
        $todos = collect($this->props($admin, $company, ['status' => 'todos'])['anuncios']['data'])->keyBy('ml_item_id');
        $this->assertNull($todos['MLB-OUTRO']['tipo'], '`free` não pertence a tipo nenhum — nada cai no Clássico "porque é o mais comum" (LIST-20)');
        $this->assertSame('free', $todos['MLB-OUTRO']['listing_tier'], 'e o valor CRU continua chegando em `listing_tier`');
    }

    /** @group phase180 */
    public function test_catalogo_e_com_venda_exigem_bool_de_verdade(): void
    {
        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB-NORMAL', ['catalog_listing' => false, 'sold_quantity' => 0]);
        $this->acervo($company, 'MLB-CATALOGO', ['catalog_listing' => true, 'sold_quantity' => 5]);

        // ⚠️ A STRING `'false'` é TRUTHY em PHP. Com truthiness o filtro ligaria
        // ao contrário do que o usuário pediu — por isso o serviço compara com
        // `=== true` e aqui a leitura é `$request->boolean()`.
        $props = $this->props($admin, $company, ['status' => 'todos', 'catalogo' => 'false', 'comVenda' => 'false']);

        $ids = $this->idsDaLista($props);
        sort($ids);
        $this->assertSame(['MLB-CATALOGO', 'MLB-NORMAL'], $ids, "`catalogo=false`/`comVenda=false` NÃO ligam o filtro");
        $this->assertFalse($props['filtros']['catalogo']);
        $this->assertFalse($props['filtros']['com_venda']);

        // E com `1` ligam.
        $this->assertSame(['MLB-CATALOGO'], $this->idsDaLista($this->props($admin, $company, ['status' => 'todos', 'catalogo' => '1'])));
        $this->assertSame(['MLB-CATALOGO'], $this->idsDaLista($this->props($admin, $company, ['status' => 'todos', 'comVenda' => '1'])));
    }

    /**
     * O 4º argumento de `escopo()` está LIGADO: a busca acha o anúncio pelo
     * NOME DO PRODUTO (LIST-03, plano 180-03).
     *
     * ⚠️ Sem este caso, tirar `$publicacoes` da chamada a `escopo()` passaria
     * por todos os outros gates — o trabalho do plano 180-03 ficaria
     * inacessível pela tela e nada acusaria.
     *
     * @group phase180
     */
    public function test_busca_por_nome_de_produto_chega_a_tela(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-OSLO', 'Poltrona Oslo', ['fase' => 1]);

        // ⚠️⚠️ NENHUM dos outros três identificadores pode conter o termo —
        // nem o título, nem o SKU, nem **o próprio `ml_item_id`**. A primeira
        // versão deste caso usava `ml_item_id = 'MLB-OSLO'` e era VAZIA POR
        // CONSTRUÇÃO: o braço `ml_item_id like '%Oslo%'` casava sozinho e o caso
        // passava mesmo com o 4º argumento REMOVIDO. Quem acusou foi a mutação
        // (j), que sobreviveu na primeira rodada.
        $this->acervo($company, 'MLB7000000001', ['title' => 'Assento decorativo veludo cinza', 'skus' => ['XYZ-1']]);
        $this->acervo($company, 'MLB7000000002', ['title' => 'Mesa de centro', 'skus' => ['XYZ-2']]);
        $this->publicada($produto, 'MLB7000000001');

        $props = $this->props($admin, $company, ['status' => 'todos', 'busca' => 'Oslo']);

        $this->assertSame(
            ['MLB7000000001'],
            $this->idsDaLista($props),
            'a busca tem de achar o anúncio pelo NOME DO PRODUTO — é o 4º identificador do `escopo()`, e ele só existe quando o controller passa o `PublicacoesDaListaService` como 4º argumento',
        );
    }

    /**
     * Os chips contam o MESMO universo da lista (LIST-22).
     *
     * ⚠️ `com_vendas` é a ÚNICA exceção, e ela é antiga e testada: a decisão da
     * Fase 173-02 (`MeusAnunciosTest::com_venda_filtra_a_listagem_sem_mudar_a_triagem`)
     * é que `comVenda=1` não mexe nos chips. Os filtros NOVOS, sim.
     *
     * @group phase180
     */
    public function test_chips_contam_o_mesmo_universo_da_lista(): void
    {
        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB-CLASSICO-PAUSADO', [
            'status' => 'paused', 'listing_type_id' => 'gold_special',
            'motivos' => [MlAcervoItem::MOTIVO_PAUSADO], 'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);
        $this->acervo($company, 'MLB-PREMIUM-PAUSADO', [
            'status' => 'paused', 'listing_type_id' => 'gold_pro',
            'motivos' => [MlAcervoItem::MOTIVO_PAUSADO], 'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
        ]);

        $semFiltro = $this->props($admin, $company)['triagem'];
        $this->assertSame(2, $semFiltro['total'], 'a premissa do caso');

        $comTipo = $this->props($admin, $company, ['tipo' => 'premium']);
        $this->assertCount(1, $comTipo['anuncios']['data']);
        $this->assertSame(
            1,
            $comTipo['triagem']['total'],
            'com `tipo=premium` a lista mostra 1 e os chips tinham de dizer 1 — chips contando um universo MAIOR que a lista é o defeito que o plano 180-03 consertou ao dar `$filtros` à `triagem()`',
        );

        $chips = collect($comTipo['triagem']['chips'])->keyBy('chave');
        $this->assertSame(1, $chips[MlAcervoItem::MOTIVO_PAUSADO]['count']);
    }

    // ═══ "● Sincronizado X" e a faixa de sucesso ═════════════════════════════

    /** @group phase180 */
    public function test_sincronizacao_manda_rotulo_humano_e_nunca_data_crua(): void
    {
        Carbon::setTestNow('2026-10-13 12:00:00'); // ⚠️ teste com `now()` SEM âncora reprova por horário

        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB1000000001', ['coletado_em' => now()->subMinutes(25)]);

        $sincronizacao = $this->props($admin, $company)['sincronizacao'];

        $this->assertSame('há 25 min', $sincronizacao['rotulo']);
        $this->assertFalse($sincronizacao['nunca_coletado']);
        $this->assertDoesNotMatchRegularExpression(
            '/\d{4}-\d{2}-\d{2}/',
            (string) $sincronizacao['rotulo'],
            'o `rotulo` com cara de data crua é RECUSADO pelo componente, e com razão: "Sincronizado 2026-10-13T12:00:00Z" é jargão de máquina',
        );

        Carbon::setTestNow();
    }

    /** @group phase180 */
    public function test_acervo_nunca_coletado_nao_inventa_sincronizacao(): void
    {
        [$company, , $admin] = $this->conta(); // acervo VAZIO

        $sincronizacao = $this->props($admin, $company)['sincronizacao'];

        $this->assertTrue($sincronizacao['nunca_coletado']);
        $this->assertNull(
            $sincronizacao['rotulo'],
            '"nunca medimos" ≠ "medimos e é zero": sem coleta o bloco inteiro desaparece. Nunca "Sincronizado há —" nem "Sincronizado nunca"',
        );
        $this->assertNull($sincronizacao['coletado_em']);
        $this->assertNull($sincronizacao['horas']);
    }

    /** @group phase180 */
    public function test_faixa_de_sucesso_e_nula_e_nunca_um_exemplo(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-1', 'Poltrona Beny', ['fase' => 1]);
        $this->acervo($company, 'MLB1000000001');
        $this->publicada($produto, 'MLB1000000001');

        $props = $this->props($admin, $company);

        $this->assertNull(
            $props['faixaSucesso'],
            'MEDIDO no plano 180-05 e reconferido aqui: não existe fonte para o resumo da publicação (zero `session()->put/flash` de publicação em `app/`, a lista branca de `flash` do `HandleInertiaRequests` não tem chave de publicação, e a publicação é assíncrona — 202 + job). `null` SEMPRE até alguém LIGAR a fonte',
        );

        // ⚠️ E o dado MOCK do protótipo não aparece em lugar nenhum (EXMP-01).
        $json = json_encode($props, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('publicações criadas agora', $json, 'o resumo do protótipo é MOCK — nunca um exemplo no payload');
    }

    // ═══ Casos 17/18 — o custo (T-180-04-D) ══════════════════════════════════

    /**
     * ⚠️ O NÚMERO TRAVADO. Travá-lo é o que faz um N+1 futuro reprovar — a Fase
     * 179 deixou um N+1 conhecido por não ter este gate.
     *
     * **34 consultas, MEDIDAS em 13/10/2026 (33) e 15/10/2026 (a 34ª)** com 50
     * linhas em 9 produtos, e enumeradas uma a uma (`?status=todos`,
     * `visao=grupo`, sem busca):
     *
     *   1-5   · middleware/autenticação: a `Company` da rota, os setores e a
     *           liderança do usuário (`HandleInertiaRequests`)
     *   6     · `MlToken` do `loadMissing` — a trava T-134-01/02
     *   7-13  · `ProgramasPublicadorService::resolver()` + `empresaParaTela()` +
     *           `CabecalhoPublicadorService::para()`
     *   **14**  · o `count` do `paginate(50)` — é dele que sai `rodape.total`
     *   **15**  · o `select` da página (≤50 linhas)
     *   **16**  · `metodologiaPorMlItemId` — **UMA**, com os ≤50 ids da página
     *   **17**  · `triagem()` — UMA agregada
     *   **18-20** · `defasagem()`: `exists` + `max(coletado_em)` + o último erro
     *   **21**  · as abas: `status, count(*) … group by status` — **UMA** para
     *           SEIS das sete chaves
     *   **22**  · `com_erro` — consulta PRÓPRIA (é `sub_status`, não `status`)
     *   **23**  · `$totalDeRascunhos`, reaproveitado por `subTotais.rascunhos`
     *   **24**  · `coberturaPorProduto` — **UMA** para os 9 produtos da página
     *   25-33 · o resto do `cabecalho` e do middleware (`pub_produtos`,
     *           `pub_tarefas`, `mlb_implementacoes`, `sugadores`,
     *           `notifications`) e o `subTotais.publicados`
     *   **34**  · ⚠️ **ENTROU EM 15/10/2026, Fase 187 plano 02** — o contador da
     *           aba "Aguardando aprovação" (ABA-05):
     *           `select count(*) from pub_aprovacao_ofertas where company_id = ?
     *           and mlb_empresa_id = ? and andamento in (?, ?, ?)`, UMA, sobre o
     *           índice `pubapof_conta_andamento_ix` (`company_id`, `andamento`).
     *           É a prop `aguardando_aprovacao`, ADITIVA — nenhuma das 19 props
     *           anteriores mudou de nome ou de tipo, e a tela recebe o NÚMERO,
     *           nunca as linhas da fila (se recebesse, somar no cliente passaria
     *           a ser possível, e ABA-05 diz que o número vem do servidor).
     *           ⚠️ O número do contador **não cresce com a linha**: é um
     *           `count(*)` por conta, então o caso irmão (1 item × 50 itens
     *           custam o mesmo) continua valendo sem mudança.
     *
     * As **12** em negrito são as desta action; 22 são middleware/cabeçalho, que
     * já existiam antes da Fase 180.
     *
     * ⚠️ O valor é MEDIDO, não estimado, e a mensagem diz o que fazer quando
     * mudar de propósito. Em 15/10/2026 ele mudou de propósito **uma vez**: de
     * **33** para **34**, pela consulta 34 acima. A 34ª foi re-medida nesta
     * máquina depois da mudança, não estimada.
     *
     * @group phase180
     */
    public function test_custo_da_action_esta_travado(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $this->comCinquentaItensEmNoveProdutos($company, $empresa);

        // Aquece: cache vivo entre requisições faz a 2ª custar menos, e medir a
        // 1ª esconderia o teto real (na Fase 178, 12 produtos custaram menos
        // que 6 por causa disso).
        $this->props($admin, $company, ['status' => 'todos']);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->props($admin, $company, ['status' => 'todos']);
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        // ⚠️ O resumo vai na MENSAGEM: quando este gate reprovar, quem lê precisa
        // saber QUAL consulta entrou, não só que o número mudou. E o resumo é
        // montado com `substr` da string do SQL, nunca com `String()`/
        // `JSON_encode` de valor adverso.
        $resumo = implode("\n", array_map(
            static fn (array $c, int $i) => sprintf('%2d. %s', $i + 1, substr((string) $c['query'], 0, 110)),
            $consultas,
            array_keys($consultas),
        ));

        $this->assertCount(
            self::CONSULTAS_DA_ACTION,
            $consultas,
            "o número de consultas da action mudou. Se foi de PROPÓSITO, atualize CONSULTAS_DA_ACTION e escreva no docblock qual consulta entrou/saiu. Se não foi, é N+1 — o suspeito nº 1 é o serviço de metodologia ou de cobertura ter ido para DENTRO do `through()`.\n\nAs consultas desta rodada:\n{$resumo}",
        );

        // ⚠️ E o gate de VERDADE por trás do número, que não depende de o
        // middleware mudar: NENHUMA consulta repetida uma vez por linha. O
        // recorde legítimo medido é 3 (`count(*) from ml_acervo_itens where
        // company_id` aparece no `paginate`, no cabeçalho e no
        // `subTotais.publicados`); o teto de 5 dá folga para o cabeçalho crescer
        // e continua pegando um `where ml_item_id = ?` por linha, que daria 50.
        $repeticoes = array_count_values(array_map(static fn (array $c) => (string) $c['query'], $consultas));
        arsort($repeticoes);
        $maisRepetida = (string) array_key_first($repeticoes);

        $this->assertLessThanOrEqual(
            5,
            $repeticoes[$maisRepetida],
            'a consulta `'.substr($maisRepetida, 0, 110)."` rodou {$repeticoes[$maisRepetida]} vezes com 50 linhas na página — é N+1",
        );
    }

    /**
     * O número medido em 13/10/2026 (33) e re-medido em 15/10/2026 (34, pela
     * consulta do contador da aba "Aguardando aprovação") — ver o docblock do
     * caso acima, que enumera as 34 uma a uma.
     */
    private const CONSULTAS_DA_ACTION = 34;

    /**
     * A definição OPERACIONAL de "a página não cresce com a linha": a MESMA
     * action com 1 e com 50 itens custa o MESMO número de consultas.
     *
     * @group phase180
     */
    public function test_custo_nao_cresce_de_um_para_cinquenta_itens(): void
    {
        [$company, $empresa, $admin] = $this->conta();

        // ── 1 item, 1 produto ──
        $produto = $this->produto($company, $empresa, 'SKU-UNICO', 'Produto Único', ['fase' => 1]);
        $this->acervo($company, 'MLB0000000001');
        $this->publicada($produto, 'MLB0000000001');

        $this->props($admin, $company, ['status' => 'todos']); // aquece
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->props($admin, $company, ['status' => 'todos']);
        $comUm = count(DB::getQueryLog());
        DB::disableQueryLog();

        // ── +49 itens, +8 produtos ──
        $this->comCinquentaItensEmNoveProdutos($company, $empresa, 2);

        $this->props($admin, $company, ['status' => 'todos']); // aquece
        DB::enableQueryLog();
        DB::flushQueryLog();
        $props = $this->props($admin, $company, ['status' => 'todos']);
        $comCinquenta = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(50, count($props['anuncios']['data']), 'a premissa: a página está CHEIA');
        $this->assertGreaterThan(1, count($props['grupos']), 'a premissa: há vários grupos');

        $this->assertSame(
            $comUm,
            $comCinquenta,
            "a action custou {$comUm} consultas com 1 item e {$comCinquenta} com 50 — isso é N+1. O serviço de metodologia e o de cobertura têm de ser chamados UMA vez, FORA do `through()`",
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Fase 180, plano 04B — as TRÊS chaves que o plano 180-08 mediu faltando
    //
    // ⚠️ TODOS os casos abaixo são NOVOS. Nenhuma asserção dos 40 casos
    // anteriores foi alterada — a mudança no controller é aditiva, e se um caso
    // existente quebrasse isso seria a prova de que não é.
    // ═══════════════════════════════════════════════════════════════════════════

    /** As 3 chaves que o plano 04B acrescenta ao item. */
    private const CHAVES_DO_PLANO_04B = ['preco', 'nova', 'com_erro'];

    /**
     * ⚠️ Os TRÊS sub-status REAIS que ficam deliberadamente FORA de "Com erro",
     * medidos nos fixtures de produção da Fase 134 (docblock de
     * `AcervoTriagemService::SUB_STATUS_COM_ERRO`). Nenhum é recusa nem
     * suspensão — e é por isso que servem de controle negativo HONESTO: são
     * valores que o ML emite de verdade, não strings inventadas.
     */
    private const SUB_STATUS_QUE_NAO_SAO_ERRO = ['cart_eligible', 'paused_by_seller', 'invalid_kit'];

    /**
     * O PREÇO chega do acervo — e nulo continua nulo (LIST-12).
     *
     * O que precisaria estar errado para este caso falhar: `preco` ausente do
     * item (a célula "Preço" inteira em `—`, que era o estado medido pelo plano
     * 180-08); o cast perdendo as 2 casas; `null` virando `"0.00"` ou `0`; ou a
     * margem aparecendo junto.
     *
     * @group phase180
     */
    public function test_preco_chega_do_acervo_e_nulo_continua_nulo(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-1', 'Produto Cheio', ['fase' => 1]);

        // ⚠️ Item CHEIO, para que a negativa da margem não seja vazia por
        // construção (o 10º caso de asserção que casa sempre).
        $this->acervo($company, 'MLB1000000001', [
            'price'              => 1099.9,
            'sold_quantity'      => 61,
            'available_quantity' => 24,
            'visitas_30d'        => 2410,
            'nota_ecf'           => 72,
        ]);
        $this->publicada($produto, 'MLB1000000001');

        $item = $this->props($admin, $company)['anuncios']['data'][0];

        $this->assertArrayHasKey('preco', $item, 'sem `preco` a célula Preço mostra `—` em TODAS as linhas (plano 180-08 §6a)');

        // ⚠️ A FORMA, não o tipo: o cast `decimal:2` serializa STRING
        // (`"1099.90"`), e `numeroMedido()` (`CelulasDaLinha.jsx:132`) aceita
        // string numérica de forma fechada, com `RE_NUMERO = /^-?\d+(?:[.,]\d+)?$/`.
        // Esta asserção é a MESMA régua do consumidor: se o valor deixar de
        // casá-la, a tela volta para o `—` sem nenhum outro gate acusar.
        $this->assertMatchesRegularExpression(
            '/^-?\d+(?:\.\d+)?$/',
            (string) $item['preco'],
            'o `preco` tem de casar o `RE_NUMERO` de `CelulasDaLinha.jsx:117`, senão `numeroMedido()` devolve null e a célula volta para o `—`',
        );
        $this->assertSame(1099.9, (float) $item['preco'], 'o valor é o do acervo, sem arredondamento inventado');
        $this->assertSame(61, $item['vendas'], 'a premissa: o item está CHEIO');

        // ⚠️ A 2ª linha da célula continua SEM fonte: o plano 04B entrega o
        // valor, não a margem (LIST-12 — a margem é a camada assíncrona da
        // Fase 181 e até ela chegar a tela mostra `—`).
        foreach (['margem', 'lucro', 'repasse', 'tarifa'] as $proibida) {
            $this->assertArrayNotHasKey(
                $proibida,
                $item,
                "`{$proibida}` apareceu no item. O plano 04B entrega o PREÇO, nunca a margem estimada — e NUNCA a tarifa por percentual",
            );
        }

        // ── E o preço ausente ──
        $this->acervo($company, 'MLB1000000002', ['price' => null]);
        $semPreco = collect($this->props($admin, $company)['anuncios']['data'])
            ->firstWhere('ml_item_id', 'MLB1000000002');

        $this->assertNull(
            $semPreco['preco'],
            'preço ausente é `null` (a tela desenha `—`), nunca `"0.00"`: `decimal` está em `$primitiveCastTypes` e "nunca medimos" ≠ "medimos e é zero"',
        );
    }

    /**
     * Zero é MEDIDA, nulo é AUSÊNCIA — e os dois chegam diferentes (EXMP-01).
     *
     * O que precisaria estar errado: um `?? 0` no `preco`, um `(float)` que
     * transformasse `null` em `0.0`, ou o inverso (zero virando nulo e a tela
     * escrevendo `—` para um anúncio que realmente custa R$ 0,00).
     *
     * @group phase180
     */
    public function test_preco_zero_e_medida_e_nulo_e_ausencia(): void
    {
        [$company, , $admin] = $this->conta();
        $this->acervo($company, 'MLB1000000001', ['price' => 0]);
        $this->acervo($company, 'MLB1000000002', ['price' => null]);

        $itens = collect($this->props($admin, $company)['anuncios']['data'])->keyBy('ml_item_id');

        $this->assertNotNull($itens['MLB1000000001']['preco'], 'preço ZERO é medida: tem de chegar, para a tela escrever "R$ 0,00"');
        $this->assertSame(0.0, (float) $itens['MLB1000000001']['preco']);
        $this->assertNull($itens['MLB1000000002']['preco'], 'preço AUSENTE é nulo: a tela escreve `—`');
        $this->assertNotSame(
            $itens['MLB1000000001']['preco'],
            $itens['MLB1000000002']['preco'],
            'zero e nulo NÃO podem chegar iguais — é a distinção que LIST-12 inteira depende',
        );
    }

    /**
     * ⚠️ `nova` é "publicada HOJE", e NUNCA a data da coleta (LIST-11).
     *
     * A régua foi medida no protótipo (`Publicacoes v2.dc.html:433`/`:434`/
     * `:491`): os dois únicos itens com `isNew: true` são exatamente os dois
     * cujo `d` cai no dia que o mock trata como hoje, e `days(d) === 0` é a
     * mesma função que escreve "Hoje, hh:mm".
     *
     * ⚠️ Ancorado com `Carbon::setTestNow()`: teste dependente de `now()` deste
     * projeto já reprovou por HORÁRIO (learnings §7).
     *
     * O que precisaria estar errado: `nova` ausente (badge e fundo
     * `bg-pub-row-new` inalcançáveis); a régua virando `created_at` do acervo (e
     * aí uma publicação antiga recém-coletada apareceria como "nova"); a régua
     * virando janela de 24 h (e aí ontem às 23:59 acenderia); o Legado ganhando
     * badge; ou `nova` discordando do `publicada_em` que a mesma linha mostra.
     *
     * @group phase180
     */
    public function test_nova_e_publicada_hoje_e_nunca_a_data_da_coleta(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:05:00', 'America/Sao_Paulo'));

        [$company, $empresa, $admin] = $this->conta();

        $deHoje  = $this->produto($company, $empresa, 'SKU-HOJE', 'Produto de Hoje', ['fase' => 1]);
        $deOntem = $this->produto($company, $empresa, 'SKU-ONTEM', 'Produto de Ontem', ['fase' => 1]);
        $deTras  = $this->produto($company, $empresa, 'SKU-TRAS', 'Produto Antigo', ['fase' => 1]);

        $this->acervo($company, 'MLB1000000001');
        $this->publicada($deHoje, 'MLB1000000001', 'gold_special', Carbon::parse('2026-10-13 08:00:00', 'America/Sao_Paulo'));

        // ⚠️ Ontem às 23:59 — 9 h e 6 min atrás. Uma janela de 24 h acenderia;
        // o DIA CIVIL não.
        $this->acervo($company, 'MLB1000000002');
        $this->publicada($deOntem, 'MLB1000000002', 'gold_special', Carbon::parse('2026-10-12 23:59:00', 'America/Sao_Paulo'));

        // ⚠️ A RÉGUA RECUSADA: publicada há 40 dias, mas COLETADA agora. Se
        // `nova` saísse de `created_at`/`coletado_em` do acervo, esta linha
        // ganharia o badge — a tela chamaria de "nova" uma publicação antiga.
        $this->acervo($company, 'MLB1000000003', ['coletado_em' => now()]);
        $this->publicada($deTras, 'MLB1000000003', 'gold_special', Carbon::parse('2026-09-03 10:00:00', 'America/Sao_Paulo'));

        // Legado: sem publicação no Publicador.
        $this->acervo($company, 'MLB1000000004');

        $itens = collect($this->props($admin, $company)['anuncios']['data'])->keyBy('ml_item_id');

        foreach (self::CHAVES_DO_PLANO_04B as $chave) {
            $this->assertArrayHasKey($chave, $itens['MLB1000000001'], "a chave nova `{$chave}` não chegou no item");
        }

        $this->assertTrue($itens['MLB1000000001']['nova'], 'publicada HOJE é `nova` — é o `days(d) === 0` do protótipo');
        $this->assertFalse(
            $itens['MLB1000000002']['nova'],
            'ontem 23:59 NÃO é `nova`: a régua é o DIA CIVIL em America/Sao_Paulo, não uma janela de 24 h',
        );
        $this->assertFalse(
            $itens['MLB1000000003']['nova'],
            '⚠️ publicada há 40 dias e COLETADA hoje NÃO é `nova` — `ml_acervo_itens.created_at`/`coletado_em` é quando a COLETA viu o anúncio, régua medida e RECUSADA',
        );
        $this->assertFalse(
            $itens['MLB1000000004']['nova'],
            'Legado (sem `publicada_em`) nunca é `nova`: ausência de dado não é novidade',
        );

        // ⚠️ `nova` e a coluna "Publicada" saem da MESMA fonte: a linha não pode
        // dizer "NOVA" e a data dizer outro dia.
        foreach ($itens as $mlItemId => $item) {
            $this->assertSame(
                is_string($item['publicada_em']) && Carbon::parse($item['publicada_em'])->isSameDay(now()),
                $item['nova'],
                "`nova` e `publicada_em` discordaram em {$mlItemId} — as duas saem do MESMO valor e isso não pode acontecer",
            );
        }

        Carbon::setTestNow();
    }

    /**
     * ⚠️⚠️ O CASO DE CONSISTÊNCIA: a lista fechada de `sub_status` da LINHA é a
     * MESMA da ABA.
     *
     * Para cada um dos cinco valores de
     * `AcervoTriagemService::SUB_STATUS_COM_ERRO`, o item chega com
     * `com_erro === true` **e** aparece em `?status=com_erro`. Para os três
     * sub-status REAIS que ficam de fora, as duas coisas são falsas.
     *
     * ⚠️ Com ISCA: o próprio caso prova que `?status=com_erro` NÃO devolve
     * vazio por construção (asserção de que o item do erro está lá).
     *
     * O que precisaria estar errado: a função da linha com uma lista PRÓPRIA
     * diferente da constante; um valor a mais ou a menos; o `array_intersect`
     * virando `in_array` do array inteiro; ou o eixo da aba mudando sem a linha
     * mudar. ➡️ É o gate que impede a LINHA e a ABA de discordarem — e dois
     * números que discordam na mesma tela já custaram uma decisão do usuário.
     *
     * @group phase180
     */
    public function test_com_erro_da_linha_usa_a_mesma_lista_fechada_da_aba(): void
    {
        [$company, , $admin] = $this->conta();

        $this->assertCount(5, AcervoTriagemService::SUB_STATUS_COM_ERRO, 'a premissa medida: a lista fechada tem CINCO valores');

        $comErro = [];
        foreach (AcervoTriagemService::SUB_STATUS_COM_ERRO as $i => $sub) {
            $id           = 'MLB900000000'.$i;
            $comErro[$id] = $sub;
            $this->acervo($company, $id, ['sub_status' => [$sub]]);
        }

        $semErro = [];
        foreach (self::SUB_STATUS_QUE_NAO_SAO_ERRO as $i => $sub) {
            $id           = 'MLB800000000'.$i;
            $semErro[$id] = $sub;
            $this->acervo($company, $id, ['sub_status' => [$sub]]);
        }

        $itens  = collect($this->props($admin, $company, ['status' => 'todos'])['anuncios']['data'])->keyBy('ml_item_id');
        $naAba  = $this->idsDaLista($this->props($admin, $company, ['status' => 'com_erro']));

        $this->assertNotEmpty($naAba, 'ISCA: a aba "Com erro" não pode estar vazia neste fixture, senão as negativas abaixo são vazias por construção');

        foreach ($comErro as $id => $sub) {
            $this->assertTrue(
                $itens[$id]['com_erro'],
                "`sub_status = [{$sub}]` está em `AcervoTriagemService::SUB_STATUS_COM_ERRO` e a LINHA disse que não é erro — a linha usou uma lista diferente da aba",
            );
            $this->assertContains(
                $id,
                $naAba,
                "`{$sub}` entra na ABA mas a linha não — os dois têm de sair da MESMA constante",
            );
        }

        foreach ($semErro as $id => $sub) {
            $this->assertFalse(
                $itens[$id]['com_erro'],
                "`{$sub}` é sub-status REAL do ML deliberadamente FORA de \"Com erro\" (elegibilidade de carrinho / vendedor pausando / kit inválido) e a linha o carimbou como erro",
            );
            $this->assertNotContains($id, $naAba, "`{$sub}` não pode entrar na aba \"Com erro\"");
        }

        // E o acervo sem sub-status nenhum (o default do fixture) também não.
        $this->acervo($company, 'MLB7000000001', ['sub_status' => []]);
        $limpo = collect($this->props($admin, $company, ['status' => 'todos'])['anuncios']['data'])
            ->firstWhere('ml_item_id', 'MLB7000000001');
        $this->assertFalse($limpo['com_erro'], '`sub_status = []` é "medido e está limpo", não erro');
    }

    /**
     * ⚠️ "Com erro" NÃO vem de `severidade` nem de `motivos`.
     *
     * Derivar de `severidade` seria INFERIR: o docblock de
     * `AcervoTriagemService::SUB_STATUS_COM_ERRO` proíbe em três linhas, e o
     * plano 180-04 mediu que um anúncio MODERADO pode ter `severidade = 0`.
     *
     * O que precisaria estar errado: `com_erro` derivado de `severidade > 0`,
     * de `motivos !== []`, de `status === 'under_review'` ou do título.
     *
     * @group phase180
     */
    public function test_com_erro_nao_vem_de_severidade_nem_de_motivos(): void
    {
        [$company, , $admin] = $this->conta();

        // Gravíssimo pela régua ECF, LIMPO pelo eixo do ML.
        $this->acervo($company, 'MLB1000000001', [
            'status'     => 'under_review',
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
            'motivos'    => [MlAcervoItem::MOTIVO_PAUSADO, MlAcervoItem::MOTIVO_SEM_ESTOQUE],
            'sub_status' => [],
            'nota_ecf'   => 10,
            'title'      => 'Anúncio com erro grave reprovado forbidden held warning',
        ]);

        // ⚠️ O inverso: `severidade = 0` e moderado de verdade.
        $this->acervo($company, 'MLB1000000002', [
            'status'     => 'active',
            'severidade' => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'motivos'    => [],
            'sub_status' => ['waiting_for_patch'],
            'nota_ecf'   => 90,
        ]);

        $itens = collect($this->props($admin, $company, ['status' => 'todos'])['anuncios']['data'])->keyBy('ml_item_id');

        $this->assertSame(MlAcervoItem::SEVERIDADE_CRITICA, $itens['MLB1000000001']['severidade'], 'a premissa: a severidade ECF está no máximo');
        $this->assertNotEmpty($itens['MLB1000000001']['motivos'], 'a premissa: os motivos estão cheios');
        $this->assertFalse(
            $itens['MLB1000000001']['com_erro'],
            '⚠️ severidade crítica + motivos cheios + `under_review` + o TÍTULO cheio dos termos NÃO é "Com erro": o eixo é `sub_status`, e "Pausado"/"Sem estoque" é outra coisa',
        );

        $this->assertSame(MlAcervoItem::SEVERIDADE_SAUDAVEL, $itens['MLB1000000002']['severidade'], 'a premissa: anúncio MODERADO com severidade 0 (medido pelo plano 180-04)');
        $this->assertTrue(
            $itens['MLB1000000002']['com_erro'],
            'moderado com `severidade = 0` É "Com erro" — é exatamente o caso que prova que derivar de `severidade` perderia a informação',
        );
    }

    /**
     * As 3 chaves novas são ESCALARES, e nada mais vazou com elas.
     *
     * ⚠️ Nenhum campo do item pode ser objeto/array novo: é a herança da tela
     * preta de 07/10/2026 (um campo que chegou como objeto e foi renderizado
     * como texto derrubou a PÁGINA INTEIRA). É por isso que `sub_status` CRU
     * (cast `'array'`) não é mandado — o que vai é o bool derivado.
     *
     * O que precisaria estar errado: `'com_erro' => $item->sub_status` (array);
     * `preco` virando objeto por algum cast de value object; `sub_status`
     * aparecendo como chave do item; ou segredo do `MlToken` no payload.
     *
     * @group phase180
     */
    public function test_tres_chaves_novas_sao_escalares_e_nao_vazam_segredo(): void
    {
        [$company, $empresa, $admin] = $this->conta();
        $produto = $this->produto($company, $empresa, 'SKU-1', 'Produto', ['fase' => 2, 'quantidade_kit' => 2]);
        $this->acervo($company, 'MLB1000000001', ['sub_status' => ['waiting_for_patch'], 'price' => 1099.9]);
        $this->publicada($produto, 'MLB1000000001');

        $props = $this->props($admin, $company);
        $item  = $props['anuncios']['data'][0];

        foreach (self::CHAVES_DO_PLANO_04B as $chave) {
            $this->assertArrayHasKey($chave, $item, "a chave nova `{$chave}` não chegou");
            $this->assertTrue(
                $item[$chave] === null || is_scalar($item[$chave]),
                "o campo `{$chave}` não é escalar nem nulo — campo objeto/array já derrubou esta tela inteira (07/10/2026)",
            );
        }

        $this->assertIsBool($item['nova'], '`nova` é bool: o componente lê `item.nova === true`, e `1`/`"1"` não acenderiam o badge');
        $this->assertIsBool($item['com_erro'], '`com_erro` é bool, nunca o array de `sub_status`');

        $this->assertArrayNotHasKey(
            'sub_status',
            $item,
            '`sub_status` CRU não vai no item: é cast `array`, hoje ninguém o consome, e mandá-lo empurraria a lista fechada para o JS (a linha passaria a poder discordar da aba)',
        );

        $json = json_encode($props, JSON_THROW_ON_ERROR);
        foreach (['fake-access-token', 'fake-refresh-token', 'access_token', 'refresh_token'] as $segredo) {
            $this->assertStringNotContainsString(
                $segredo,
                $json,
                "`{$segredo}` vazou no payload — a empresa TEM `MlToken` neste fixture, então a negativa não é vazia por construção",
            );
        }
    }

    // ═══ Auxiliares de fixture ═══════════════════════════════════════════════

    private function comUmItemDeCadaStatus(Company $c): void
    {
        $this->acervo($c, 'MLB-ATIVO', ['status' => 'active']);
        $this->acervo($c, 'MLB-PAUSADO', ['status' => 'paused']);
        $this->acervo($c, 'MLB-REVISAO', ['status' => 'under_review']);
        $this->acervo($c, 'MLB-FECHADO', ['status' => 'closed']);
    }

    /** 50 linhas de acervo distribuídas em 9 produtos do Publicador. */
    private function comCinquentaItensEmNoveProdutos(Company $c, MlbEmpresa $e, int $de = 1): void
    {
        for ($i = $de; $i <= 50; $i++) {
            $grupo   = $i % 9;
            $sku     = 'SKU-G'.$grupo;
            $produto = PubProduto::where('company_id', $c->id)->where('sku', $sku)->first()
                ?? $this->produto($c, $e, $sku, 'Produto do grupo '.$grupo, ['fase' => $grupo % 2 === 0 ? 2 : 1]);

            $mlItemId = 'MLB'.str_pad((string) $i, 10, '0', STR_PAD_LEFT);
            $this->acervo($c, $mlItemId, ['listing_type_id' => $i % 2 === 0 ? 'gold_pro' : 'gold_special']);
            $this->publicada($produto, $mlItemId, $i % 2 === 0 ? 'gold_pro' : 'gold_special');
        }
    }
}
