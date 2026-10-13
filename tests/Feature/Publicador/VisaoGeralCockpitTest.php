<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\AcoesDaContaService;
use App\Services\Publicador\AdiamentoDeAcoes;
use App\Services\Publicador\CabecalhoPublicadorService;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 179, plano 05 — o CONTRATO da action `visaoGeral()` depois do cockpit, e
 * a rota que faz o adiamento de uma ação persistir.
 *
 * ═══ O que este arquivo guarda (e uma asserção isolada não guardaria) ════════
 *
 *  1. **NADA do contrato de hoje regrediu.** As props que a tela já recebia
 *     estão numa constante (`PROPS_DE_HOJE`) e são cobradas uma a uma, e TRÊS
 *     delas são cobradas por **valor** (`assertSame` contra o que o serviço
 *     devolve), não só por presença de chave. É a prova de que o cockpit
 *     **somou** em vez de substituir (REG-01, VG-20).
 *  2. ⚠️ **`produtosPorFase` e `quemPublicou` estão nessa lista DE PROPÓSITO**,
 *     mesmo com os blocos de tela removidos pelo usuário em **12/10/2026**. A
 *     decisão do usuário é de **TELA**, e o payload não é a tela: quem perde o
 *     consumidor é o **render** (plano 179-09), não o contrato. Mantê-las custa
 *     **zero** consulta (as duas são passthrough sobre dado já calculado) e
 *     **zero** cirurgia nos 2 arquivos de teste que as asseguram hoje — um deles
 *     é o `test_visao_geral_entrega_produtos_por_fase_sem_perder_nenhuma_prop`,
 *     cujo propósito inteiro é provar que o contrato não perde prop. **Não
 *     "limpe" isto.**
 *  3. **A INTEGRAÇÃO que nenhum plano da wave 1 pôde provar:** a saída **real**
 *     de `jornada()` entra em `acoes()`. Os dois serviços foram testados com
 *     *fixture*; aqui a saída real passa pela entrada real, cobrada por
 *     **igualdade de número** entre o estágio `none` do anel e a ação
 *     `sem_publicacao` (VG-19).
 *  4. **A rota de adiar nos cinco casos**, e no 422 cobra-se também que o cache
 *     **não foi escrito** — sem essa asserção o 422 poderia estar vindo *depois*
 *     da escrita (T-179-16).
 *  5. **O `{conta}` fora do padrão morre na ROTA, antes do controller** — provado
 *     por `shouldNotReceive('resolver')`, não pelo código 404: sem o
 *     `->where('conta', …)` o `resolver()` devolveria `null` e o 404 viria
 *     igual. O gate pelo status seria **vazio por construção** para essa mutação.
 *  6. **O adiamento é gravado com a chave CANÔNICA**, não com a grafia do
 *     request: adia-se por `company-N` e confere-se por `empresa-N` (as duas
 *     grafias da MESMA conta). Com a chave do request, seriam dois sacos.
 *  7. **CUSTO.** A página inteira é medida com 3 e com 12 produtos: teto folgado
 *     (o objetivo não é cravar número bonito) **mais** estabilidade, que é o que
 *     pega N+1. ⚠️ Medido na **segunda** visita (a primeira aquece o que o
 *     processo cacheia) e com **isca** (`assertGreaterThan(0, …)`): um
 *     `enableQueryLog()` mal posicionado daria zero e o teto passaria por
 *     construção.
 *  8. **`produtosParaTela()` e `CabecalhoPublicadorService::para()` uma vez
 *     cada.** São as duas coisas mais caras da tela; o cockpit reusa o que já
 *     está em memória em vez de pedir de novo.
 *  9. **D23 (sem Company — 535 de 539 `MlbEmpresa` não têm):** a página responde
 *     200 e as nove props novas vêm honestas. Nada lança.
 * 10. **Nenhum segredo no payload**, varrido no array de props **e** no HTML
 *     renderizado.
 *
 * ⚠️ Nenhum teste aqui chama o Mercado Livre: `Http::fake()` sem handler
 * intercepta tudo. Esta tela só lê o que já está gravado (D-05/REG-06).
 *
 * ⚠️ Os testes rodam em SQLite; produção é MySQL 8.0 e o dev local é MariaDB
 * 10.4. Este plano **não cria migration** (é controller + rota + teste), então
 * as armadilhas de enum/índice/FK não se aplicam. O adiamento vive no **cache**,
 * por decisão da wave 1 — nos testes o store é `array`.
 *
 * @group phase179
 */
class VisaoGeralCockpitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * As props que a Visão geral JÁ entregava antes desta fase. **Nenhuma pode
     * sair.**
     *
     * ⚠️ São **14**, não 13 — o `179-05-PLAN.md` diz "13 props" no
     * `<interfaces>` e em seguida lista as 14. A lista aqui é a do
     * `Inertia::render` real, conferida no código.
     */
    private const PROPS_DE_HOJE = [
        'empresa', 'liberada', 'indicadores', 'oQueFazerAgora', 'alertas', 'situacaoProdutos',
        'produtosPorFase', 'ultimasPublicacoes', 'atividadeEquipe', 'integracoes', 'identidadeResumo',
        'quemPublicou', 'abas', 'cabecalho',
    ];

    /** As nove props que esta fase acrescenta. */
    private const PROPS_NOVAS = [
        'jornada', 'acoes', 'banner', 'saude', 'publicacoesMl', 'acervoImagens', 'desempenho',
        'recentes', 'atividade',
    ];

    /**
     * As chaves de topo de cada prop nova, na ORDEM do contrato das waves 1 e 2.
     * `banner` fica fora: ele é `?array` e vale `null` com token ativo (tem teste
     * próprio).
     *
     * ⚠️ São o PREFIXO obrigatório, não a lista completa: `publicacoesMl` ganha
     * `pedem_acao` **quando, e só quando, o número foi medido** (D-9 da wave 1 —
     * chave ausente ≠ chave nula). A única chave opcional do contrato está em
     * `OPCIONAIS_DAS_NOVAS`.
     */
    private const TOPO_DAS_NOVAS = [
        'jornada' => ['total', 'publicados', 'pct', 'tem_produtos', 'proximo_passo', 'estagios'],
        'acoes' => ['acoes', 'contagens', 'adiadas', 'volta_em_iso'],
        'saude' => ['geral', 'linhas'],
        'publicacoesMl' => ['disponivel', 'total', 'faixas', 'outras', 'ativas_pct'],
        'acervoImagens' => ['imagens', 'packs'],
        'desempenho' => ['kpis', 'dias', 'url'],
        'recentes' => ['disponivel', 'itens'],
        'atividade' => ['disponivel', 'grupos'],
    ];

    /**
     * As ÚNICAS chaves de topo que o contrato permite além do prefixo acima.
     * Lista fechada: chave nova aqui tem de ser decisão, não acidente.
     */
    private const OPCIONAIS_DAS_NOVAS = ['publicacoesMl' => ['pedem_acao']];

    /**
     * ⚠️ **Inventário REG-01: bloco de hoje → onde ele mora depois desta fase.**
     *
     * É o que permite ao plano 179-09 recompor a página sem perder nada. A prop
     * da esquerda tem de continuar chegando; a da direita é o lugar novo (ou
     * `null` quando o bloco continua sendo servido pela própria prop antiga).
     */
    private const INVENTARIO_REG01 = [
        // prop de hoje => prop nova que a substitui na tela nova (ou null = continua sendo ela mesma)
        'indicadores' => 'desempenho',
        'oQueFazerAgora' => 'acoes',
        'ultimasPublicacoes' => 'recentes',
        'atividadeEquipe' => 'atividade',
        'integracoes' => 'saude',
        'situacaoProdutos' => 'jornada',
        // Sem substituto: seguem servindo o próprio bloco (ou nenhum — ver o item 2 do docblock).
        'alertas' => null,
        'identidadeResumo' => null,
        'produtosPorFase' => null,
        'quemPublicou' => null,
    ];

    /**
     * O acréscimo MÁXIMO de consultas entre a conta de 3 produtos e a de 12,
     * **com o número de publicações FIXO**.
     *
     * ⚠️ **MEDIDO nesta execução (SQLite, 2ª visita): 3 produtos = 65 consultas,
     * 12 produtos = 65 — acréscimo real ZERO.** A folga de 4 existe para não
     * transformar uma consulta legítima a mais em reprovação; o que o gate guarda
     * é a ORDEM DE GRANDEZA: com N+1 por produto o acréscimo seria 9 ou mais.
     */
    private const ACRESCIMO_MAXIMO = 4;

    /**
     * O teto absoluto da página com publicações FIXAS. Medido: **65**. (O teto
     * medido na Fase 177-03, em outra tela, era 50.)
     */
    private const TETO_DE_CONSULTAS = 80;

    /**
     * O teto da MESMA página quando o que cresce são as **publicações**.
     *
     * ⚠️ **Medido: 3 produtos/2 publicações = 65; 12 produtos/12 publicações =
     * 91.** O crescimento é por PUBLICAÇÃO, não por produto, e a causa é
     * **pré-existente a esta fase**: `PainelVisaoGeralService::resolverAtor()`
     * (`app/Services/Publicador/PainelVisaoGeralService.php:136`) faz um
     * `User::find()` **por linha**, e ele é chamado por `publicadosRecentes()` e
     * por `ultimasPublicacoes()` — as duas existem desde a Fase 173 e são
     * chamadas **uma vez cada** antes e depois deste plano. O cockpit **não**
     * acrescentou nenhuma: `recentes()` e `atividade()` recebem os arrays já
     * calculados. Fora do escopo deste plano (o arquivo é do plano 179-02 e está
     * commitado); registrado em `deferred-items.md`.
     */
    private const TETO_COM_PUBLICACOES = 120;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Uma conta de Polos com token ativo. Sem Company (D23) o token fica na
     * própria `MlbEmpresa`.
     *
     * @return array{0: MlbEmpresa, 1: ?Company}
     */
    private function conta(bool $comCompany = true, string $nome = 'Polo do Cockpit', string $tokenStatus = 'active'): array
    {
        $company = $comCompany ? Company::factory()->create(['name' => $nome]) : null;
        $empresa = MlbEmpresa::create(['nome' => $nome, 'projeto' => 'POLOS', 'company_id' => $company?->id]);
        MlToken::create([
            'company_id' => $company?->id,
            'mlb_empresa_id' => $company === null ? $empresa->id : null,
            'ml_user_id' => '1555596317',
            'access_token' => 'APP_USR-segredo',
            'refresh_token' => 'TG-segredo',
            'expires_at' => $tokenStatus === 'expired' ? now()->subHours(2) : now()->addHours(5),
            'status' => $tokenStatus === 'expired' ? 'expired' : 'active',
        ]);

        return [$empresa->fresh(), $company];
    }

    private function produto(MlbEmpresa $e, ?Company $c, string $sku, array $extra = []): PubProduto
    {
        return PubProduto::create($extra + [
            'mlb_empresa_id' => $e->id,
            'company_id' => $c?->id,
            'sku' => $sku,
            'nome' => 'Cadeira '.$sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    private function acervo(Company $c, string $mlItemId, string $status = 'active'): MlAcervoItem
    {
        return MlAcervoItem::create([
            'company_id' => $c->id,
            'ml_item_id' => $mlItemId,
            'title' => 'Item '.$mlItemId,
            'status' => $status,
            'available_quantity' => 10,
            'sold_quantity' => 0,
            'motivos' => [],
            'severidade' => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'origem' => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em' => now(),
        ]);
    }

    /** Uma publicação CONCLUÍDA com anúncio CREATED — o que `ultimasPublicacoes()` lê. */
    private function publicada(PubProduto $p): PubPublicacao
    {
        // ⚠️ `pub_rascunhos.produto_id` é UNIQUE: duas publicações do mesmo produto
        // são REVISÕES do mesmo rascunho, como no editor (lição da wave 1).
        $rascunho = PubRascunho::firstOrCreate(
            ['produto_id' => $p->id],
            ['status' => PubRascunho::PUBLISHED, 'revisao' => 1],
        );

        $publicacao = PubPublicacao::create([
            'rascunho_id' => $rascunho->id,
            'revisao' => PubPublicacao::where('rascunho_id', $rascunho->id)->count() + 1,
            'modelo_publicacao' => 'items',
            'status' => PubPublicacao::PUBLISHED,
            'chave_idempotencia' => (string) Str::uuid(),
            'concluida_em' => now()->subHours(3),
            'ator' => ['equipe' => true, 'id' => 1, 'nome' => 'Dev'],
        ]);

        PubPublicacaoItem::create([
            'publicacao_id' => $publicacao->id,
            'indice' => 0,
            'listing_type_id' => 'gold_special',
            'variante_chave' => ChaveCanonica::UNICA,
            'status' => PubPublicacaoItem::CREATED,
            'ml_item_id' => 'MLB'.(9000000 + $publicacao->id),
            'payload' => ['family_name' => 'Anúncio de '.$p->nome],
        ]);

        return $publicacao;
    }

    private function url(string $conta): string
    {
        return "/mlb/anuncios/publicador/empresas/{$conta}/visao-geral";
    }

    private function urlAdiar(string $conta): string
    {
        return "/mlb/anuncios/publicador/empresas/{$conta}/acoes/adiar";
    }

    /**
     * As props Inertia da Visão geral. Mesmo padrão do resto da suíte do
     * Publicador (`viewData('page')['props']`).
     */
    private function props(string $conta, ?User $user = null): array
    {
        $r = $this->actingAs($user ?? $this->admin())->get($this->url($conta))->assertOk();

        return $r->viewData('page')['props'];
    }

    /** A ação de uma `chave` na prop `acoes`, ou `null`. */
    private function acao(array $props, string $chave): ?array
    {
        foreach ($props['acoes']['acoes'] as $acao) {
            if ($acao['chave'] === $chave) {
                return $acao;
            }
        }

        return null;
    }

    /** O estágio de uma `chave` na prop `jornada`. */
    private function estagio(array $props, string $chave): array
    {
        foreach ($props['jornada']['estagios'] as $estagio) {
            if ($estagio['chave'] === $chave) {
                return $estagio;
            }
        }

        $this->fail("o estágio '{$chave}' não veio na prop jornada");
    }

    /**
     * Quantas consultas UMA visita à Visão geral custa.
     *
     * ⚠️ Mede a **segunda** visita: na Fase 178-01 mediu-se que a primeira paga
     * aquecimentos de processo e 12 produtos custaram MENOS que 6. O usuário é
     * criado fora da janela (o `INSERT` dele não é da tela).
     */
    private function consultasDaVisaoGeral(User $admin, string $conta): int
    {
        $this->actingAs($admin)->get($this->url($conta))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get($this->url($conta))->assertOk();
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $consultas;
    }

    // ═══ 1. O contrato de hoje não regrediu (REG-01, VG-20) ══════════════════

    public function test_as_quatorze_props_de_hoje_continuam_todas_no_contrato(): void
    {
        [$e, $c] = $this->conta();
        $p = $this->produto($e, $c, 'CAD-01');
        $this->publicada($p);

        $props = $this->props('empresa-'.$e->id);

        foreach (self::PROPS_DE_HOJE as $chave) {
            $this->assertArrayHasKey(
                $chave,
                $props,
                "a prop '{$chave}' saiu do contrato da Visão geral. ⚠️ `produtosPorFase` e `quemPublicou` "
                .'estão nesta lista DE PROPÓSITO, mesmo com os blocos de tela removidos em 12/10/2026: a '
                .'decisão do usuário é de TELA (quem perde o consumidor é o render, plano 179-09), as duas '
                .'custam ZERO consulta e tirá-las exigiria cirurgia em 14 asserções de ListaPorFaseTest e '
                .'VisaoGeralTest. Leia o docblock desta classe antes de "limpar" o contrato.'
            );
        }
    }

    public function test_tres_props_de_hoje_valem_exatamente_o_que_o_servico_devolve(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD-01');
        $this->produto($e, $c, 'CAD-KIT3', ['produto_base_id' => $base->id, 'quantidade_kit' => 3, 'fase' => 2]);
        $this->publicada($base);

        $props = $this->props('empresa-'.$e->id);

        $programas = app(ProgramasPublicadorService::class);
        $painel = app(PainelVisaoGeralService::class);
        $alvo = $programas->resolver('empresa-'.$e->id);
        $produtos = $programas->produtosParaTela($alvo['mlb_empresa'], $alvo['company'], $alvo['chave']);
        $contagem = $programas->contagemProdutos($produtos);
        $publicados = $painel->publicadosRecentes($alvo);

        // Presença de chave não prova que o valor não mudou — estas três provam
        // que o cockpit SOMOU em vez de substituir.
        $this->assertSame($painel->situacaoProdutos($contagem), $props['situacaoProdutos'], 'situacaoProdutos mudou de valor');
        $this->assertSame($painel->produtosPorFase($contagem), $props['produtosPorFase'], 'produtosPorFase mudou de valor (e ela FICA — ver o docblock)');
        $this->assertSame([
            'equipe' => $publicados['equipe'],
            'cliente' => $publicados['cliente'],
            'origem_antiga' => $publicados['origem_antiga'],
        ], $props['quemPublicou'], 'quemPublicou mudou de valor (e ela FICA — ver o docblock)');

        // Isca: sem produto nenhum as três seriam vazias e a igualdade passaria por construção.
        $this->assertCount(5, $props['produtosPorFase']);
        $this->assertGreaterThan(0, (int) $contagem['todos']);
    }

    public function test_inventario_reg01_todo_bloco_de_hoje_tem_onde_morar(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'CAD-01');

        $props = $this->props('empresa-'.$e->id);

        foreach (self::INVENTARIO_REG01 as $antiga => $nova) {
            $this->assertArrayHasKey($antiga, $props, "o bloco '{$antiga}' perdeu a prop que o alimenta");
            if ($nova !== null) {
                $this->assertArrayHasKey($nova, $props, "o bloco '{$antiga}' aponta para a prop nova '{$nova}', que não chegou");
            }
        }

        // O inventário cobre TODAS as props de hoje — uma prop fora dele seria um
        // bloco sem destino declarado na tela nova.
        $this->assertSame(
            [],
            array_values(array_diff(self::PROPS_DE_HOJE, array_keys(self::INVENTARIO_REG01), ['empresa', 'liberada', 'abas', 'cabecalho'])),
            'há prop de hoje fora do inventário REG-01 — declare onde ela mora na tela nova'
        );
    }

    // ═══ 2. As nove props novas ══════════════════════════════════════════════

    public function test_as_nove_props_novas_chegam_com_as_chaves_de_topo_do_contrato(): void
    {
        [$e, $c] = $this->conta();
        $p = $this->produto($e, $c, 'CAD-01');
        $this->publicada($p);
        $this->acervo($c, 'MLB1000000001');

        $props = $this->props('empresa-'.$e->id);

        foreach (self::PROPS_NOVAS as $chave) {
            $this->assertArrayHasKey($chave, $props, "a prop nova '{$chave}' não chegou na Visão geral");
        }

        foreach (self::TOPO_DAS_NOVAS as $prop => $topo) {
            $chaves = array_keys($props[$prop]);
            $this->assertSame(
                $topo,
                array_slice($chaves, 0, count($topo)),
                "a prop '{$prop}' mudou as chaves de topo do contrato"
            );
            $this->assertSame(
                [],
                array_values(array_diff(array_slice($chaves, count($topo)), self::OPCIONAIS_DAS_NOVAS[$prop] ?? [])),
                "a prop '{$prop}' ganhou chave de topo fora do contrato"
            );
        }

        // Com acervo coletado, a legenda "Pedem ação" FOI medida e tem de existir —
        // o par com o caso D23 (chave ausente) é o que prova a disciplina.
        $this->assertArrayHasKey('pedem_acao', $props['publicacoesMl']);

        // Fonte única: o total da barra é o contador do header, e a coleta da Saúde
        // é a coleta do header. Duas fontes para o mesmo número discordariam.
        $this->assertSame($props['cabecalho']['contadores']['publicacoes'], $props['publicacoesMl']['total']);
        $this->assertSame(
            $props['cabecalho']['integracoes']['coleta']['nunca_coletado'],
            $props['saude']['linhas'][2]['nunca_coletado']
        );
        $this->assertSame(
            $props['cabecalho']['integracoes']['coleta']['coletado_em'],
            $props['saude']['linhas'][2]['coletado_em']
        );

        // `banner` é `?array` e com token ativo vale null — é o caso alcançável
        // mais comum, e `null` aqui é informação, não ausência.
        $this->assertNull($props['banner'], 'com token ativo o banner é null');
    }

    public function test_a_visao_geral_entrega_as_vinte_e_tres_props_e_nenhuma_a_mais(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'CAD-01');

        $props = $this->props('empresa-'.$e->id);

        // As props compartilhadas do Inertia (`auth`, `flash`, `csrf_token`…) vêm
        // do HandleInertiaRequests e não são do contrato desta action — por isso a
        // conferência é de CONTENÇÃO, não de igualdade do array inteiro.
        $daAction = array_values(array_intersect(array_keys($props), array_merge(self::PROPS_DE_HOJE, self::PROPS_NOVAS)));
        $this->assertCount(14 + 9, $daAction, 'a action deixou de entregar uma das 23 props (14 de hoje + 9 novas)');
    }

    public function test_banner_nasce_com_token_expirado_e_leva_o_link_de_reconexao(): void
    {
        [$e, $c] = $this->conta(true, 'Polo expirado', 'expired');
        $this->produto($e, $c, 'CAD-01');

        $props = $this->props('empresa-'.$e->id);

        $this->assertIsArray($props['banner'], 'token fora de ativo tem de produzir banner');
        $this->assertSame(['chave', 'link_reconexao'], array_keys($props['banner']));
        $this->assertSame('ml', $props['banner']['chave']);
        // E a Saúde conta a MESMA coisa — uma fonte, dois blocos.
        $this->assertSame('requer_acao', $props['saude']['geral']);
    }

    // ═══ 3. A integração jornada → ações (VG-19) ═════════════════════════════

    public function test_a_saida_real_da_jornada_alimenta_as_acoes(): void
    {
        [$e, $c] = $this->conta();
        // Três produtos base, nenhum publicado: os três caem no estágio `none`.
        foreach (['CAD-01', 'CAD-02', 'CAD-03'] as $sku) {
            $this->produto($e, $c, $sku);
        }

        $props = $this->props('empresa-'.$e->id);

        $none = $this->estagio($props, 'none');
        $acao = $this->acao($props, 'sem_publicacao');

        // Isca: com o estágio zerado a ação não nasce e a igualdade abaixo passaria
        // por construção. O número tem de ser medido e maior que zero.
        $this->assertSame(3, $none['total'], 'o cenário tem de ter produto no estágio `none`');
        $this->assertNotNull($acao, 'a ação `sem_publicacao` não nasceu da jornada real — o shape real divergiu da fixture da wave 1');
        $this->assertSame($none['total'], $acao['numero'], 'a ação `sem_publicacao` tem de contar o MESMO número do estágio `none` do anel');
        $this->assertSame('oportunidade', $acao['severidade']);

        // A sub-lista da ação vem do MESMO estágio: os nomes da amostra do anel e
        // os da ação são a mesma lista, na mesma ordem. Se uma fase futura trocar
        // a fonte dos itens da ação, reprova aqui.
        $this->assertNotEmpty($acao['itens'], 'a ação tem de trazer a sub-lista do estágio');
        $this->assertSame(
            array_column($none['itens'], 'nome'),
            array_column($acao['itens'], 'nome'),
            'os itens da ação saem do estágio `none` da jornada, na ordem dele'
        );
    }

    public function test_a_jornada_conta_o_mesmo_universo_que_a_lista_de_produtos(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'CAD-01');
        // O kit É a Fase 2 do base: ele NÃO entra no universo do anel (contá-lo
        // somaria o mesmo produto duas vezes — decisão da wave 1).
        $this->produto($e, $c, 'CAD-KIT3', ['produto_base_id' => $base->id, 'quantidade_kit' => 3, 'fase' => 2]);

        $props = $this->props('empresa-'.$e->id);

        $this->assertSame(1, $props['jornada']['total'], 'o universo do anel são os produtos base não compostos');
        $this->assertTrue($props['jornada']['tem_produtos']);
    }

    // ═══ 4. Custo: `->once()` e o detector de N+1 (T-179-19) ═════════════════

    public function test_produtos_para_tela_e_o_cabecalho_sao_montados_uma_unica_vez(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'CAD-01');

        // `produtosParaTela()` é a lista pesada: a Jornada, as Ações e as
        // Recentes TODAS a consomem, e nenhuma pode pedir de novo.
        $this->partialMock(ProgramasPublicadorService::class, function ($mock) {
            $mock->shouldReceive('produtosParaTela')->once()->passthru();
        });
        // E o cabeçalho é montado uma vez e reusado pelas props `publicacoesMl` e
        // `saude` — remontá-lo dentro do `Inertia::render` pagaria duas vezes pelos
        // contadores do header.
        //
        // ⚠️ Aqui NÃO serve `partialMock()` nem `passthru()`:
        // `CabecalhoPublicadorService` tem dois serviços PROMOVIDOS no construtor, e
        // o `passthru()` do Mockery chama o método na subclasse do mock — cujas
        // propriedades tipadas nunca foram inicializadas, porque o mock parcial não
        // roda construtor. O erro é "Typed property … must not be accessed before
        // initialization", e vale também para o mock proxiado. A delegação
        // EXPLÍCITA para a instância real do container é o único caminho que conta
        // a chamada sem perder o comportamento.
        $real = app(CabecalhoPublicadorService::class);
        $espiao = \Mockery::mock(CabecalhoPublicadorService::class);
        $espiao->shouldReceive('para')->once()->andReturnUsing(fn (...$args) => $real->para(...$args));
        $this->instance(CabecalhoPublicadorService::class, $espiao);

        $props = $this->props('empresa-'.$e->id);

        $this->assertArrayHasKey('contadores', $props['cabecalho']);
        $this->assertArrayHasKey('total', $props['publicacoesMl']);
    }

    public function test_a_pagina_tem_teto_de_consultas_e_nao_cresce_com_a_quantidade_de_produto(): void
    {
        $admin = $this->admin();

        // ⚠️ As PUBLICAÇÕES ficam FIXAS em 2 nas duas contas, e isto é desenho do
        // gate, não conveniência: a página tem um N+1 **por publicação** que é
        // pré-existente a esta fase (ver `TETO_COM_PUBLICACOES`). Variar as duas
        // coisas juntas misturaria a dívida alheia com a minha e o gate não diria
        // de quem é a culpa. Aqui varia só o que ESTE plano ligou: os produtos.
        [$pequena, $cp] = $this->conta(true, 'Conta de tres');
        for ($i = 1; $i <= 3; $i++) {
            $p = $this->produto($pequena, $cp, 'P3-'.$i);
            if ($i <= 2) {
                $this->publicada($p);
            }
            $this->acervo($cp, 'MLB30000000'.$i);
        }

        [$grande, $cg] = $this->conta(true, 'Conta de doze');
        for ($i = 1; $i <= 12; $i++) {
            $p = $this->produto($grande, $cg, 'P12-'.$i);
            if ($i <= 2) {
                $this->publicada($p);
            }
            $this->acervo($cg, 'MLB1200000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $comTres = $this->consultasDaVisaoGeral($admin, 'empresa-'.$pequena->id);
        $comDoze = $this->consultasDaVisaoGeral($admin, 'empresa-'.$grande->id);

        // Isca: sem ela um `enableQueryLog()` mal posicionado daria 0 e o teto
        // passaria por construção.
        $this->assertGreaterThan(0, $comTres, 'o medidor não contou nada — a janela do query log está errada');

        $this->assertLessThan(self::TETO_DE_CONSULTAS, $comTres, "3 produtos custaram {$comTres} consultas");
        $this->assertLessThan(self::TETO_DE_CONSULTAS, $comDoze, "12 produtos custaram {$comDoze} consultas");

        // O gate que REALMENTE pega N+1 do que este plano ligou: quadruplicar os
        // produtos não pode acrescentar consulta.
        $this->assertLessThanOrEqual(
            $comTres + self::ACRESCIMO_MAXIMO,
            $comDoze,
            "N+1 por PRODUTO: 3 produtos = {$comTres} consultas, 12 produtos = {$comDoze}. "
            .'O acréscimo máximo tolerado é '.self::ACRESCIMO_MAXIMO
        );
    }

    public function test_a_pagina_ainda_cresce_com_as_publicacoes_e_isso_e_divida_pre_existente(): void
    {
        $admin = $this->admin();

        // ⚠️ Este gate NÃO é uma meta, é um MARCADOR: ele trava a dívida no
        // tamanho em que ela está hoje e aponta a causa. `resolverAtor()`
        // (`PainelVisaoGeralService.php:136`) faz um `User::find()` por linha, e
        // `publicadosRecentes()` varre TODAS as publicações da conta. Se uma fase
        // futura resolver isso, este teto cai junto — e se alguém piorar, reprova
        // aqui com o endereço na mensagem.
        [$e, $c] = $this->conta(true, 'Conta com doze publicacoes');
        for ($i = 1; $i <= 12; $i++) {
            $this->publicada($this->produto($e, $c, 'PUB-'.$i));
        }

        $consultas = $this->consultasDaVisaoGeral($admin, 'empresa-'.$e->id);

        $this->assertGreaterThan(0, $consultas, 'o medidor não contou nada');
        $this->assertLessThan(
            self::TETO_COM_PUBLICACOES,
            $consultas,
            "12 publicações custaram {$consultas} consultas. O crescimento é por PUBLICAÇÃO e vem do "
            .'`User::find()` por linha de `PainelVisaoGeralService::resolverAtor()` (:136), anterior a esta '
            .'fase. Se este número passou de '.self::TETO_COM_PUBLICACOES.', alguém acrescentou consulta por linha.'
        );
        // E a prova de que é por publicação, não por produto: o mesmo cenário com
        // 2 publicações custa MENOS. Sem este par, "cresce por publicação" seria
        // afirmação sem medida.
        [$e2, $c2] = $this->conta(true, 'Conta com duas publicacoes');
        for ($i = 1; $i <= 12; $i++) {
            $p = $this->produto($e2, $c2, 'POUCO-'.$i);
            if ($i <= 2) {
                $this->publicada($p);
            }
        }
        $this->assertGreaterThan(
            $this->consultasDaVisaoGeral($admin, 'empresa-'.$e2->id),
            $consultas,
            'a MESMA quantidade de produtos com menos publicações tem de custar menos'
        );
    }

    // ═══ 5. D23 — sem Company, nada lança e nada mente ═══════════════════════

    public function test_conta_sem_company_responde_duzentos_com_as_nove_props_honestas(): void
    {
        [$e] = $this->conta(false, 'Polo sem Company');
        $this->produto($e, null, 'CAD-01');
        $this->produto($e, null, 'CAD-02');

        $props = $this->props('empresa-'.$e->id);

        foreach (self::PROPS_NOVAS as $chave) {
            $this->assertArrayHasKey($chave, $props, "a prop '{$chave}' faltou na conta sem Company (D23)");
        }

        // Sem Company não há acervo para coletar: `null`, nunca `0`.
        $this->assertFalse($props['publicacoesMl']['disponivel']);
        $this->assertNull($props['publicacoesMl']['total'], 'sem Company o total é null — zero seria mentira');
        $this->assertNull($props['publicacoesMl']['ativas_pct']);
        $this->assertArrayNotHasKey('pedem_acao', $props['publicacoesMl'], 'a legenda só existe quando foi medida');

        $this->assertFalse($props['recentes']['disponivel']);
        $this->assertSame([], $props['recentes']['itens']);

        // A Saúde traz as 3 linhas SEMPRE; o que falta é o destino do "Atualizar agora".
        $this->assertCount(3, $props['saude']['linhas']);
        $this->assertSame(['mercado_livre', 'erp', 'sincronizacao'], array_column($props['saude']['linhas'], 'chave'));
        $this->assertNull($props['saude']['linhas'][2]['atualizar_url'], 'sem Company não há rota de coleta para oferecer');
        // ⚠️ A coleta da Saúde é a MESMA do cabeçalho — é o que impede o chrome
        // dizer "nunca coletado" e a Saúde dizer "tudo conectado" sobre a MESMA
        // conta. Ler o `$defasagem` cru da action daria `nunca_coletado: false`
        // (stub pré-existente, que `indicadores()` também lê e que este plano NÃO
        // mexe). Ver o comentário na action.
        $this->assertTrue($props['saude']['linhas'][2]['nunca_coletado'], 'sem Company a ausência de coleta é EXPLÍCITA');
        $this->assertSame(
            $props['cabecalho']['integracoes']['coleta']['nunca_coletado'],
            $props['saude']['linhas'][2]['nunca_coletado'],
            'a Saúde e o header não podem discordar sobre a coleta da mesma conta'
        );
        $this->assertSame('atencao', $props['saude']['geral'], 'sem Company a coleta nunca aconteceu — âmbar, e sem exceção escondida para D23');

        // O KPI de venda vem do acervo: sem Company ele é null nos dois campos.
        $this->assertCount(3, $props['desempenho']['kpis']);
        $this->assertSame('publicacoes_com_venda', $props['desempenho']['kpis'][2]['chave']);
        $this->assertNull($props['desempenho']['kpis'][2]['valor']);
        $this->assertNull($props['desempenho']['kpis'][2]['de']);
        $this->assertNull($props['desempenho']['url'], 'a tela Desempenho é a Fase 182 — link para tela inexistente é botão sem destino');

        // ⚠️ DIVERGÊNCIA MEDIDA do `<action>` do plano, que previu
        // `atividade.disponivel=false` em D23: a **4ª fonte** da Atividade
        // (produtos recebidos) é escopada por `mlb_empresa_id` OU `company_id` e
        // **não precisa de Company**. Com produto recebido nos últimos 30 dias a
        // Atividade tem conteúdo real, e afirmar "indisponível" aqui seria
        // esconder evento que existe. O caso vazio tem teste próprio, abaixo.
        $this->assertTrue($props['atividade']['disponivel'], 'a 4ª fonte da Atividade não depende de Company');
        $tipos = [];
        foreach ($props['atividade']['grupos'] as $grupo) {
            foreach ($grupo['eventos'] as $evento) {
                $tipos[] = $evento['tipo'];
            }
        }
        $this->assertSame(['produto_recebido', 'produto_recebido'], $tipos, 'sem Company só a 4ª fonte tem o que contar');
    }

    public function test_conta_sem_company_e_sem_produto_nao_afirma_atividade(): void
    {
        [$e] = $this->conta(false, 'Polo vazio');

        $props = $this->props('empresa-'.$e->id);

        $this->assertFalse($props['atividade']['disponivel'], 'sem nenhuma das quatro fontes a Atividade é indisponível, nunca "zero eventos"');
        $this->assertSame([], $props['atividade']['grupos']);
        $this->assertFalse($props['jornada']['tem_produtos']);
        $this->assertSame(0, $props['jornada']['pct'], 'nenhum produto não divide por zero');
        $this->assertNull($this->acao($props, 'sem_publicacao'), '"nenhum produto sem publicação" não é uma tarefa');
    }

    // ═══ 6. A rota de adiar (VG-11, T-179-16, T-179-17) ══════════════════════

    public function test_a_rota_de_adiar_existe_com_o_where_da_conta_e_o_throttle_nomeado(): void
    {
        $rota = Route::getRoutes()->getByName('mlb.anuncios.publicador.acoes.adiar');

        $this->assertNotNull($rota, 'a rota nomeada publicador.acoes.adiar não existe');
        $this->assertSame(['POST'], array_values(array_diff($rota->methods(), ['HEAD'])));
        $this->assertSame('mlb/anuncios/publicador/empresas/{conta}/acoes/adiar', $rota->uri());
        $this->assertSame('(empresa|company)-[0-9]+', $rota->wheres['conta'] ?? null, 'sem o where a conta fora do padrão chegaria ao controller');
        $this->assertContains('throttle:60,1,publicador.acoes.adiar', $rota->gatherMiddleware());
        foreach (['auth', 'verified', 'role:admin'] as $middleware) {
            $this->assertContains($middleware, $rota->gatherMiddleware(), "a rota de adiar tem de exigir {$middleware}, como as vizinhas");
        }
    }

    public function test_adiar_esconde_a_acao_na_visita_seguinte(): void
    {
        [$e, $c] = $this->conta();
        foreach (['CAD-01', 'CAD-02'] as $sku) {
            $this->produto($e, $c, $sku);
        }
        $admin = $this->admin();
        $conta = 'empresa-'.$e->id;

        $antes = $this->props($conta, $admin);
        $this->assertNotNull($this->acao($antes, 'sem_publicacao'), 'a ação tem de existir ANTES do adiamento — senão o gate é vazio');
        $contagemAntes = $antes['acoes']['contagens']['todas'];

        $this->actingAs($admin)
            ->from($this->url($conta))
            ->post($this->urlAdiar($conta), ['chave' => 'sem_publicacao'])
            ->assertRedirect($this->url($conta));

        $depois = $this->props($conta, $admin);
        $this->assertNull($this->acao($depois, 'sem_publicacao'), 'o adiamento tem de sobreviver à recarga — é o servidor que esconde, não o cliente');
        $this->assertContains('sem_publicacao', $depois['acoes']['adiadas']);
        $this->assertSame($contagemAntes - 1, $depois['acoes']['contagens']['todas'], 'as contagens são do que a tela mostra');
        $this->assertNotSame('', $depois['acoes']['volta_em_iso']);
    }

    public function test_o_adiamento_e_de_quem_adiou_e_nao_vaza_para_outro_admin(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'CAD-01');
        $conta = 'empresa-'.$e->id;

        // ⚠️ A ORDEM destes dois importa, e foi uma mutação sobrevivente que
        // mostrou: quem adia é o SEGUNDO usuário criado. Com `$quemAdia` sendo o
        // primeiro, trocar `$request->user()?->id` por um `1` fixo passaria —
        // o id coincidiria com o do adiador e o teste não notaria nada. Não era
        // equivalência, era coincidência de id.
        $espectador = $this->admin();
        $quemAdia = $this->admin();
        $this->assertNotSame($espectador->id, $quemAdia->id);

        $this->actingAs($quemAdia)->post($this->urlAdiar($conta), ['chave' => 'sem_publicacao'])->assertRedirect();

        $this->assertSame(
            ['sem_publicacao'],
            app(AdiamentoDeAcoes::class)->adiadas($quemAdia->id, $conta),
            'o adiamento tem de ficar no saco de QUEM adiou'
        );
        $this->assertSame(
            [],
            app(AdiamentoDeAcoes::class)->adiadas($espectador->id, $conta),
            'e em mais nenhum'
        );
        $this->assertNull($this->acao($this->props($conta, $quemAdia), 'sem_publicacao'));
        $this->assertNotNull(
            $this->acao($this->props($conta, $espectador), 'sem_publicacao'),
            'o adiamento é por usuário: esconder para o outro admin seria decidir pela agenda dele'
        );
    }

    public function test_o_adiamento_e_gravado_com_a_chave_canonica_da_conta(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'CAD-01');
        $admin = $this->admin();

        // Duas grafias da MESMA conta: `company-N` resolve para a canônica
        // `empresa-N`. Gravar pelo `{conta}` do request faria dois sacos.
        $this->actingAs($admin)->post($this->urlAdiar('company-'.$c->id), ['chave' => 'sem_publicacao'])->assertRedirect();

        $this->assertNull(
            $this->acao($this->props('empresa-'.$e->id, $admin), 'sem_publicacao'),
            'adiado por company-N e conferido por empresa-N: o saco tem de ser o MESMO (chave canônica)'
        );
        $this->assertSame(
            ['sem_publicacao'],
            app(AdiamentoDeAcoes::class)->adiadas($admin->id, 'empresa-'.$e->id),
            'o cache tem de estar escrito na chave canônica'
        );
    }

    public function test_chave_fora_da_lista_fechada_da_422_e_nao_escreve_no_cache(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'CAD-01');
        $admin = $this->admin();
        $conta = 'empresa-'.$e->id;

        $this->actingAs($admin)
            ->post($this->urlAdiar($conta), ['chave' => 'reconectar; DROP TABLE'])
            ->assertStatus(422);

        // ⚠️ Sem esta asserção o 422 poderia estar vindo DEPOIS da escrita.
        $this->assertSame([], app(AdiamentoDeAcoes::class)->adiadas($admin->id, $conta), 'chave desconhecida não pode virar entrada de cache (T-179-16)');
        $this->assertNotNull($this->acao($this->props($conta, $admin), 'sem_publicacao'), 'nada foi adiado');
    }

    public function test_as_doze_chaves_da_lista_fechada_sao_aceitas_e_nenhuma_outra(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'CAD-01');
        $admin = $this->admin();
        $conta = 'empresa-'.$e->id;

        // A lista é a constante do serviço, nunca um literal reescrito aqui: duas
        // listas divergiriam na primeira chave nova.
        $this->assertCount(12, AcoesDaContaService::SEVERIDADE);
        foreach (array_keys(AcoesDaContaService::SEVERIDADE) as $chave) {
            $this->actingAs($admin)->post($this->urlAdiar($conta), ['chave' => $chave])->assertRedirect();
        }
        $this->assertSame(
            array_keys(AcoesDaContaService::SEVERIDADE),
            app(AdiamentoDeAcoes::class)->adiadas($admin->id, $conta),
            'as doze chaves do mapa têm de ser aceitas — a borda não pode ser mais estreita que o contrato'
        );

        // ⚠️ Chave VAZIA cai antes, na validação (`required`), e validação de
        // request não-JSON volta 302 com erro de sessão — não 422. São dois
        // caminhos de recusa diferentes de propósito, e os dois são cobrados.
        $this->actingAs($admin)->post($this->urlAdiar($conta), ['chave' => ''])->assertSessionHasErrors('chave');

        foreach (['acao_inventada', 'SEM_PUBLICACAO', 'reconectar;', 'sem_publicacao.'] as $invalida) {
            $this->actingAs($admin)->post($this->urlAdiar($conta), ['chave' => $invalida])->assertStatus(422);
        }

        // ⚠️ MEDIDO nesta execução: `'sem_publicacao '` (com espaço à direita) é
        // **aceita**, e está certo — o middleware global `TrimStrings` apara a
        // entrada antes de ela chegar à action, então é literalmente a MESMA chave.
        // Documentado porque a intuição diz o contrário: não é furo da lista
        // fechada, é normalização de borda do framework, a mesma que vale para
        // todo formulário do sistema.
        $this->actingAs($admin)->post($this->urlAdiar($conta), ['chave' => 'sem_publicacao '])->assertRedirect();
        // E `' '` (só espaço) vira `''` pelo mesmo caminho e cai no `required`.
        $this->actingAs($admin)->post($this->urlAdiar($conta), ['chave' => '  '])->assertSessionHasErrors('chave');
    }

    public function test_conta_fora_do_padrao_morre_na_rota_antes_do_controller(): void
    {
        // ⚠️ O status 404 NÃO prova nada aqui: sem o `->where('conta', …)` o
        // `resolver()` devolveria null e o `abort_if` daria o MESMO 404. O que
        // prova é o controller nunca ter sido alcançado.
        $this->partialMock(ProgramasPublicadorService::class, function ($mock) {
            $mock->shouldNotReceive('resolver');
        });

        $this->actingAs($this->admin())
            ->post($this->urlAdiar('empresa-abc'), ['chave' => 'sem_publicacao'])
            ->assertNotFound();
    }

    public function test_conta_inexistente_da_404(): void
    {
        $this->actingAs($this->admin())
            ->post($this->urlAdiar('empresa-999999'), ['chave' => 'sem_publicacao'])
            ->assertNotFound();
    }

    public function test_nao_admin_nao_adia_e_nao_ve_a_visao_geral(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'CAD-01');
        $conta = 'empresa-'.$e->id;
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)->post($this->urlAdiar($conta), ['chave' => 'sem_publicacao'])->assertForbidden();
        $this->actingAs($consultor)->get($this->url($conta))->assertForbidden();
        $this->assertSame([], app(AdiamentoDeAcoes::class)->adiadas($consultor->id, $conta));
    }

    public function test_visitante_nao_autenticado_e_mandado_para_o_login(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'CAD-01');

        $this->post($this->urlAdiar('empresa-'.$e->id), ['chave' => 'sem_publicacao'])->assertRedirect('/login');
    }

    public function test_adiar_sem_chave_no_corpo_e_recusado_na_validacao(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'CAD-01');
        $conta = 'empresa-'.$e->id;

        $this->actingAs($this->admin())
            ->post($this->urlAdiar($conta), [])
            ->assertSessionHasErrors('chave');
    }

    // ═══ 7. Nenhum segredo, nenhum campo-objeto inesperado ═══════════════════

    public function test_o_payload_da_visao_geral_nao_vaza_segredo_da_conta(): void
    {
        [$e, $c] = $this->conta();
        $p = $this->produto($e, $c, 'CAD-01');
        $this->publicada($p);
        $this->acervo($c, 'MLB1000000002');

        $r = $this->actingAs($this->admin())->get($this->url('empresa-'.$e->id))->assertOk();
        $props = $r->viewData('page')['props'];
        $json = (string) json_encode($props);

        // Isca: payload vazio passaria por construção.
        $this->assertGreaterThan(2000, strlen($json), 'o payload tem de ter conteúdo para a varredura valer algo');

        foreach (['APP_USR', 'TG-', 'access_token', 'refresh_token', 'client_secret'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $json, "o payload vazou '{$segredo}'");
            $this->assertStringNotContainsString($segredo, $r->getContent(), "o HTML renderizado vazou '{$segredo}'");
        }
    }

    public function test_nenhuma_prop_nova_traz_campo_objeto_fora_dos_conteineres(): void
    {
        [$e, $c] = $this->conta();
        $p = $this->produto($e, $c, 'CAD-01');
        $this->publicada($p);

        $props = $this->props('empresa-'.$e->id);

        // Herança da tela preta de 07/10/2026: campo-objeto renderizado como texto
        // derrubou a página inteira. A ÚNICA exceção declarada é `quem`
        // (`{tipo, nome}`), que a tela já lê campo a campo.
        foreach (['jornada', 'saude', 'publicacoesMl', 'acervoImagens', 'desempenho', 'recentes', 'atividade', 'acoes'] as $prop) {
            $this->assertIsArray($props[$prop], "a prop '{$prop}' tem de ser array");
            $this->assertNotEmpty($props[$prop], "a prop '{$prop}' vazia tornaria a varredura vazia por construção");
        }

        foreach ($props['recentes']['itens'] as $item) {
            foreach ($item as $campo => $valor) {
                if ($campo === 'quem') {
                    $this->assertSame(['tipo', 'nome'], array_keys($valor), 'a ÚNICA exceção de campo-objeto é `quem`');

                    continue;
                }
                $this->assertFalse(is_array($valor) || is_object($valor), "o campo '{$campo}' de recentes.itens não é escalar");
            }
        }
    }
}
