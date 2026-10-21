<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Http\Controllers\MlbPublicadorAprovacaoController;
use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoOferta;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\PubValidacao;
use App\Models\User;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\PublicacaoDoPortaoService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\ConferenciaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Quick `261021-fix` — **O BECO DO RETRY (decisão 25) E O MOTIVO QUE VOLTAVA ATRÁS (decisão 26).**
 *
 * ⚠️⚠️ DE CADA CASO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: **"o que precisaria estar errado
 * no mundo para ele falhar?"**
 *
 *   · ⚠️⚠️ **O BECO (decisão 25).** A fila publica o **produto inteiro**, então num produto com
 *     **dois alvos ativos** uma publicação que falha deixa as **DUAS** ofertas em `falha`. Com
 *     `falha` fora de {@see PublicacaoDoPortaoService::JA_PASSARAM_PELO_PORTAO}, o *"Tentar de
 *     novo"* de **cada** uma recusava **nomeando a outra**, e `TRANSICOES[falha] = [publicando]` é
 *     a **única** saída de `falha`. ➡️ **Nenhuma das duas podia ser retentada pela tela.**
 *   · ⚠️ **A DECISÃO 23 AFROUXADA SEM NINGUÉM NOTAR.** Se incluir `falha` arrastasse de carona os
 *     estados em que **ninguém terminou de revisar**, o Premium iria ao ar sem revisão — o defeito
 *     exato que a decisão 23 fecha. ➡️ Por isso a prova é por **IGUALDADE DE CONJUNTO** da
 *     constante (piso `>= N` **não prova presença**) e os **quatro** estados não-aprovados são
 *     exercitados **um por um**.
 *   · ⚠️⚠️ **A RAZÃO ESCRITA NO DOCBLOCK CONTINUAR FALSA.** A razão antiga dizia que incluir
 *     `falha` *"faria uma oferta que falhou arrastar a irmã para a fila sem a irmã ter sido
 *     reconferida"*. ➡️ O caso **mede a alcançabilidade no próprio grafo** em vez de confiar na
 *     prosa: se algum dia `falha` passar a ser alcançável sem aprovação, a asserção cai e a razão
 *     deixa de valer **no mesmo instante**.
 *   · ⚠️⚠️ **O MOTIVO DA RECUSA VOLTANDO ATRÁS (decisão 26).** A action do retry roda `enfileirar()`
 *     e `tentarDeNovo()` **numa transação só** — e o `rollback` da recusa levava embora a linha de
 *     histórico que explicava o motivo. A pessoa via no `422` e **nada ficava registrado**,
 *     justamente no caso em que a oferta **fica parada**.
 *   · ⚠️ **A TRANSAÇÃO SENDO "CONSERTADA" POR QUEM LESSE A DECISÃO 26 DE RELANCE.** Dividi-la ou
 *     removê-la reintroduziria o estado *"`publicando` com o item terminado"*, que o plano 193-03
 *     pôde tratar como **impossível**. ➡️ Há asserção de que a action tem **UMA** `DB::transaction(`.
 *
 * ═══ ⚠️ O QUE SAI PELA REDE ════════════════════════════════════════════════════════════════════
 *
 * `Http::preventStrayRequests()` e `Queue::fake()` de pé em **todos** os casos. ⚠️ A régua é **zero
 * ESCRITA**, não "zero requisição": a leitura autorizada (o GET público da tarifa real, decisão 9 do
 * usuário) está em **lista CONSTANTE nomeada**. Prometer zero absoluto seria gate que o próprio
 * desenho contradiz — **aprovar** (Fase 188) lê a tarifa, e o executor seguinte "consertaria" o gate
 * pré-aquecendo cache.
 *
 * ⚠️⚠️ **NENHUM EXECUTOR DESTA QUICK VIU TELA RENDERIZADA, nem publicação real, nem o cron
 * rodando.** Não há navegador, nenhuma empresa tem `ml_token` de verdade e `pub_aprovacao_ofertas`
 * está **vazia em produção**. A prova aqui é **rota + `Http::fake` + `preventStrayRequests()`**.
 *
 * ⚠️ As quatro formas proibidas (*"confirmar publicação"*, *"segundo passo"*, *"desfazer
 * aprovação"*, *"cancelar aprovação"*) **não** são citadas neste arquivo de propósito: o gate de
 * ausência é dos planos 193-02 e 188-05, sobre as listas constantes **deles**, e acrescentar a
 * isca do leitor aqui não acrescentaria prova nenhuma.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class BecoDoRetryEMotivoRegistradoTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const CATEGORIA = 'MLB189007';

    /** ⚠️ A única leitura de rede autorizada — a tarifa real (decisão 9 do usuário). */
    private const LEITURAS_AUTORIZADAS = ['/sites/MLB/listing_prices'];

    /** O controller onde vive a action do retry. */
    private const ARQUIVO_DO_CONTROLLER = 'app/Http/Controllers/MlbPublicadorAprovacaoController.php';

    /** O serviço do portão, onde vivem a constante da decisão 25 e a gravação da decisão 26. */
    private const ARQUIVO_DO_PORTAO = 'app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php';

    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // ⚠️ Hora congelada em todo caso: teste dependente de hora reprova POR HORÁRIO, e é dessa
        // classe que são as duas falhas que oscilam nesta suíte (`CockpitDaContaTest`).
        Carbon::setTestNow('2026-10-21 11:30:00');

        Http::preventStrayRequests();
        Queue::fake();

        // ⚠️ Receita MEDIDA do 188-05: sem o app token pré-aquecido a `ComposicaoFinanceiraService`
        // chama `MlColetaService::getAppToken()` e a rota de aprovar devolve **500** — o caso
        // mediria erro de fixture em vez de comportamento.
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        Http::fake(function (Request $req) {
            $caminho = (string) parse_url($req->url(), PHP_URL_PATH);
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $query);

            if ($caminho === '/sites/MLB/listing_prices') {
                $tipo = (string) ($query['listing_type_id'] ?? '');
                $arquivo = base_path('tests/fixtures-ml/sondagem/publico/categorias/' . self::CATEGORIA . "/listing_prices_{$tipo}.json");

                return is_file($arquivo)
                    ? Http::response(json_decode((string) file_get_contents($arquivo), true)['resposta'], 200)
                    : Http::response(['message' => 'sem fixture para ' . $tipo], 404);
            }

            return Http::response(['message' => 'sem stub no teste'], 404);
        });

        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(
            app(\App\Services\MercadoLivreService::class),
            app(\App\Services\MlColetaService::class),
            function (int $s) {}
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{0: Company, 1: User} */
    private function conta(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Rafael Gusmão']);
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
            'nome' => 'Empresa do beco ' . $company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        // ⚠️ `ContasLiberadas` é FAIL-CLOSED: lista vazia = NINGUÉM. Sem esta linha a prontidão
        // recusa com *"A publicação ainda não foi liberada para esta conta"* e todo caso de
        // enfileiramento ficaria verde **por não ter exercitado nada**.
        config(['publicador.contas_liberadas.companies' => [(int) $company->id]]);

        return [$company, $admin];
    }

    /**
     * Um produto publicável, com **DUAS** modalidades ativas por default — que é o cenário do beco.
     *
     * @param  array<string, mixed>  $o  `so_classico` (bool) · `andamento_irma` (string) · `irma_ativa` (bool)
     * @return array<string, mixed>
     */
    private function cenario(Company $company, array $o = []): array
    {
        $this->sequencia++;
        $n = $this->sequencia;

        $produto = PubProduto::create([
            'company_id' => $company->id,
            'sku' => 'BEC-' . $n,
            'nome' => 'Poltrona do beco ' . $n,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => 1,
        ]);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id, 'categoria_id' => self::CATEGORIA]);

        // ⚠️ TÍTULOS DIFERENTES de propósito: título igual nos dois tipos é o bloqueio **V-TIT-04**
        // da prontidão, e a fila recusaria por outro motivo — o caso mediria a recusa ERRADA.
        $titulos = [
            self::CLASSICO => "Poltrona Beny decorativa veludo cinza {$n}",
            self::PREMIUM => "Poltrona Beny premium veludo cinza {$n}",
        ];
        $tipos = ($o['so_classico'] ?? false) ? [self::CLASSICO] : [self::CLASSICO, self::PREMIUM];

        $alvos = [];
        $ofertas = [];
        foreach ($tipos as $i => $tipo) {
            $alvos[$tipo] = PubRascunhoAlvo::create([
                'rascunho_id' => $rascunho->id,
                'listing_type_id' => $tipo,
                'titulo' => $titulos[$tipo],
                'posicao' => $i,
                'ativo' => $tipo === self::PREMIUM ? (bool) ($o['irma_ativa'] ?? true) : true,
            ]);
            $ofertas[$tipo] = PubAprovacaoOferta::create([
                'alvo_id' => $alvos[$tipo]->id,
                'rascunho_id' => $rascunho->id,
                'produto_id' => $produto->id,
                'company_id' => $company->id,
                // ⚠️ `preparando` é estado de NASCIMENTO: `TRANSICOES` não tem nenhuma entrada que
                // chegue nele, então a única forma honesta de exercitá-lo é criar a oferta nele.
                'andamento' => $tipo === self::PREMIUM
                    ? (string) ($o['andamento_irma'] ?? PubAprovacaoOferta::AGUARDANDO_APROVACAO)
                    : PubAprovacaoOferta::AGUARDANDO_APROVACAO,
                'entrou_na_fila_em' => '2026-10-21 08:00:00',
            ]);
        }

        // ⚠️⚠️ `fresh()` OBRIGATÓRIO: **o Eloquent não lê o default do banco.** `PubRascunho::
        // create()` sem `revisao` devolve `revisao` NULA em memória enquanto a linha nasce com `1`,
        // e a conferência nasceria com `revisao = 0` — a prontidão recusaria com *"O produto mudou
        // depois da conferência"* e o caso mediria a recusa ERRADA.
        $rascunho = $rascunho->fresh();

        PubValidacao::create([
            'rascunho_id' => $rascunho->id,
            'revisao' => (int) $rascunho->revisao,
            'camada' => 'L3',
            'resultado' => ConferenciaService::OK,
            'plano_hash' => str_repeat('a', 64),
            'issues' => [],
        ]);

        return [
            'produto' => $produto->fresh(),
            'rascunho' => $rascunho->fresh(),
            'alvos' => $alvos,
            'classico' => $ofertas[self::CLASSICO]->fresh(),
            'premium' => isset($ofertas[self::PREMIUM]) ? $ofertas[self::PREMIUM]->fresh() : null,
        ];
    }

    /**
     * A oferta levada ao andamento pedido **pela ÚNICA PORTA** — este teste nunca escreve a coluna
     * `andamento` depois do nascimento.
     *
     * ⚠️ `aprovado` passa por `mover()`, que dispara o gancho `aoAprovar()` do plano 193-02: é
     * assim que a vida real enfileira. Um `update()` cru pularia o gancho e mediria outro mundo.
     */
    private function levarPara(PubAprovacaoOferta $oferta, string $destino, User $quem, ?string $motivo = null): PubAprovacaoOferta
    {
        $porta = app(AndamentoDaOfertaService::class);
        $atual = $porta->mover($oferta, PubAprovacaoOferta::APROVADO, $quem);

        if ($destino === PubAprovacaoOferta::APROVADO) {
            return $atual;
        }

        $atual = $porta->mover($atual, PubAprovacaoOferta::PUBLICANDO, $quem);

        if ($destino === PubAprovacaoOferta::PUBLICANDO) {
            return $atual;
        }

        return $porta->mover($atual, $destino, $quem, $motivo);
    }

    /**
     * A oferta levada a `falha` **com o item da fila TERMINADO e sem item vivo** — que é o estado
     * real de quem acabou de falhar, e o único em que o retry chega a chamar `agendar()`.
     *
     * ⚠️ Sem matar o item (`produto_ativo` nulo), `enfileirar()` acharia o item **VIVO** e
     * **ANEXARIA** em vez de agendar: o caso de recusa mediria o ramo do anexo, que nunca recusa.
     */
    private function levarAFalhaSemItemVivo(PubAprovacaoOferta $oferta, User $admin, string $motivo): PubAprovacaoOferta
    {
        $aprovada = $this->levarPara($oferta, PubAprovacaoOferta::APROVADO, $admin);

        $item = $aprovada->fresh()->filaItem;
        $this->assertNotNull($item, 'a aprovação não enfileirou: a fixture deste caso não exercita o retry que importa');
        $item->update(['status' => PubFilaPublicacaoItem::PARCIAL, 'produto_ativo' => null]);

        $porta = app(AndamentoDaOfertaService::class);
        $emVoo = $porta->mover($aprovada->fresh(), PubAprovacaoOferta::PUBLICANDO, $admin);

        return $porta->mover($emVoo, PubAprovacaoOferta::FALHA, $admin, $motivo);
    }

    /** O POST da rota do retry, chamada DIRETO (sem a tela). */
    private function postarRetry(Company $company, PubAprovacaoOferta $oferta, User $quem): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($quem)->postJson(
            route('mlb.anuncios.meus.aprovacao.painel.tentar-de-novo', ['company' => $company->id, 'oferta' => $oferta->id])
        );
    }

    /** @return list<string> os textos do histórico desta oferta, em ordem */
    private function eventos(PubAprovacaoOferta $oferta): array
    {
        return PubAprovacaoEvento::query()
            ->where('oferta_id', $oferta->id)
            ->orderBy('ocorrido_em')
            ->orderBy('id')
            ->pluck('texto')
            ->all();
    }

    /** @return list<string> as requisições que NÃO são a leitura autorizada */
    private function escritasEDesvios(): array
    {
        return Http::recorded()
            ->reject(static fn ($par) => $par[0]->method() === 'GET'
                && in_array((string) parse_url($par[0]->url(), PHP_URL_PATH), self::LEITURAS_AUTORIZADAS, true))
            ->map(static fn ($par) => $par[0]->method() . ' ' . $par[0]->url())
            ->values()
            ->all();
    }

    /** A linha INTEIRA da oferta, crua do banco — para comparar byte a byte. @return array<string, mixed> */
    private function linhaCrua(PubAprovacaoOferta $oferta): array
    {
        return (array) DB::table('pub_aprovacao_ofertas')->where('id', $oferta->id)->first();
    }

    /** Quantos itens de fila VIVOS este produto ocupa. */
    private function itensVivos(int $produtoId): int
    {
        return PubFilaPublicacaoItem::query()
            ->where('produto_ativo', $produtoId)
            ->whereIn('status', PubFilaPublicacaoItem::VIVOS)
            ->count();
    }

    /**
     * O trecho de **UM MÉTODO** no código já sem comentário — da assinatura até a assinatura do
     * método **seguinte**.
     *
     * ⚠️⚠️ **ISTO É CONSERTO DE UM FURO QUE EU MEDI NA MINHA PRÓPRIA SONDA, não zelo.** A primeira
     * redação usava `strstr($limpo, 'public function tentarDeNovo(')`, que devolve **tudo da
     * assinatura até o FIM DO ARQUIVO** — e `tentarDeNovo` **não é o último método** do controller
     * (vêm `salvarPreco`, `simularPreco`, `conferirAlteracao`, `confirmarFrete`, `previaDoFrete`…).
     * ➡️ A sonda contava **3** `DB::transaction(` e acusava *"a transação foi dividida"* **no
     * trabalho correto** — exatamente a família *"gate mais largo que o invariante"*, que nesta
     * milestone tem **quatorze** ocorrências e já **barrou um deploy**.
     *
     * ⚠️ O conserto certo é **recortar o método**, nunca elevar o número esperado de 1 para 3: o 3
     * mudaria sozinho no próximo método que outra sessão acrescentasse ao controller.
     */
    private static function trechoDoMetodo(string $limpo, string $assinatura): string
    {
        $inicio = strpos($limpo, $assinatura);

        if ($inicio === false) {
            return '';
        }

        $depois = $inicio + strlen($assinatura);
        $fim = strlen($limpo);

        foreach (['public function ', 'private function ', 'protected function ', 'public const ', 'private const '] as $marca) {
            $proxima = strpos($limpo, $marca, $depois);
            if ($proxima !== false && $proxima < $fim) {
                $fim = $proxima;
            }
        }

        return substr($limpo, $inicio, $fim - $inicio);
    }

    /** O código PHP **sem comentário**, por `token_get_all` — nunca por regex sobre o texto cru. */
    private static function semComentarios(string $codigo): string
    {
        $saida = '';
        foreach (token_get_all($codigo) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $saida .= str_repeat("\n", substr_count($t[1], "\n"));

                    continue;
                }
                $saida .= $t[1];

                continue;
            }
            $saida .= $t;
        }

        return $saida;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) ⚠️⚠️ DECISÃO 25 — A CONSTANTE, POR IGUALDADE DE CONJUNTO, E O GRAFO QUE A JUSTIFICA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **IGUALDADE DE CONJUNTO, nunca piso.** Com `assertContains(FALHA, …)` a asserção passaria
     * igual se alguém acrescentasse `aguardando_aprovacao` por descuido — e aí a decisão 23 teria
     * sido afrouxada **sem nenhum gate acusar**. ➡️ O conjunto é exigido **exato**.
     *
     * ⚠️⚠️ **E A RAZÃO DO DOCBLOCK É MEDIDA NO GRAFO, não lida da prosa.** A razão antiga (*"incluir
     * `falha` arrastaria a irmã sem ela ter sido reconferida"*) era **falsa**, e só se descobre isso
     * percorrendo `TRANSICOES`. ➡️ Este caso mede a alcançabilidade: quem entra em `falha`, quem
     * entra em `publicando`, e qual é o único portão de entrada do ciclo. Se o grafo mudar, a
     * asserção cai **no mesmo instante** em que a razão deixa de valer.
     */
    public function test_a_constante_do_portao_por_igualdade_de_conjunto_e_o_grafo_261021_fix(): void
    {
        // ─── A CONSTANTE, POR IGUALDADE DE CONJUNTO ────────────────────────────────────────
        $esperado = [
            PubAprovacaoOferta::APROVADO,
            PubAprovacaoOferta::PUBLICANDO,
            PubAprovacaoOferta::PUBLICADO,
            PubAprovacaoOferta::FALHA,
        ];
        $atual = PublicacaoDoPortaoService::JA_PASSARAM_PELO_PORTAO;

        $this->assertEqualsCanonicalizing($esperado, $atual, 'decisão 25: o conjunto de "já passou pelo portão" mudou — estado a mais afrouxaria a decisão 23, estado a menos reabriria o beco do retry');
        // ⚠️ A contagem ao lado da igualdade canônica: `assertEqualsCanonicalizing` não acusa
        // duplicata, e duplicata na lista é sintoma de edição às cegas.
        $this->assertCount(4, $atual, 'a lista tem duplicata ou tamanho diferente do conjunto declarado');

        // ⚠️ ISCA (−) do piso: estes QUATRO **não** podem estar lá, e cada um é nomeado.
        foreach ([
            PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            PubAprovacaoOferta::EM_REVISAO_ECF,
            PubAprovacaoOferta::AJUSTES_SOLICITADOS,
            PubAprovacaoOferta::PREPARANDO,
        ] as $naoAprovado) {
            $this->assertNotContains($naoAprovado, $atual, "`{$naoAprovado}` entrou em JA_PASSARAM_PELO_PORTAO: ninguém terminou de revisar esta modalidade, e a decisão 23 foi afrouxada");
        }

        // ─── ⚠️⚠️ O GRAFO: QUEM CHEGA EM `falha` JÁ FOI APROVADO — MEDIDO, NÃO AFIRMADO ───
        $transicoes = PubAprovacaoOferta::TRANSICOES;

        $quemChegaEm = static function (string $destino) use ($transicoes): array {
            $origens = [];
            foreach ($transicoes as $de => $paras) {
                if (in_array($destino, $paras, true)) {
                    $origens[] = $de;
                }
            }
            sort($origens);

            return $origens;
        };

        // ⚠️ ISCA (+): o varredor acha origem de verdade — senão "só `publicando`" seria verdade
        // por ele não achar nada em lugar nenhum.
        $this->assertNotSame([], $quemChegaEm(PubAprovacaoOferta::APROVADO), 'ISCA: o varredor do grafo não acha origem nenhuma — ele está cego');
        // ⚠️ ISCA (−): um destino que não existe no grafo não pode ter origem.
        $this->assertSame([], $quemChegaEm('estado_que_nao_existe_9z7'), 'ISCA: o varredor inventa origem para destino inexistente');

        $this->assertSame([PubAprovacaoOferta::PUBLICANDO], $quemChegaEm(PubAprovacaoOferta::FALHA), 'o grafo mudou: `falha` passou a ser alcançável por outro estado, e a razão da decisão 25 deixou de valer');
        $this->assertEqualsCanonicalizing([PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::FALHA], $quemChegaEm(PubAprovacaoOferta::PUBLICANDO), 'o grafo mudou: `publicando` ganhou outra entrada, e "tudo em falha já foi aprovado" deixou de ser verdade');

        // ➡️ Logo o ÚNICO portão de entrada do ciclo {publicando, falha} vindo de FORA é `aprovado`.
        $deFora = array_values(array_diff(
            array_unique([...$quemChegaEm(PubAprovacaoOferta::FALHA), ...$quemChegaEm(PubAprovacaoOferta::PUBLICANDO)]),
            [PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::FALHA]
        ));
        $this->assertSame([PubAprovacaoOferta::APROVADO], $deFora, 'o ciclo {publicando, falha} ganhou uma entrada que não é `aprovado`: quem está em `falha` pode não ter sido aprovado');

        // ⚠️ E `publicado` segue TERMINAL por estrutura — a razão de ele estar na lista sem risco.
        $this->assertSame([], $transicoes[PubAprovacaoOferta::PUBLICADO], '`publicado` deixou de ser terminal por estrutura');

        // ─── A RAZÃO NOVA ESTÁ ESCRITA, E A ANTIGA NÃO FICOU SOLTA ────────────────────────
        $this->assertFileExists(base_path(self::ARQUIVO_DO_PORTAO), 'caminho fantasma: ' . self::ARQUIVO_DO_PORTAO);
        $cru = (string) file_get_contents(base_path(self::ARQUIVO_DO_PORTAO));
        $this->assertStringContainsString('DECISÃO 25 DO USUÁRIO', $cru, 'a razão da decisão 25 não está escrita onde o próximo executor vai ler');
        // ⚠️ A frase antiga **só** pode aparecer citada como falsa. A âncora é a sentença que a
        // marca como falsa, no MESMO parágrafo — não a ausência da frase (citá-la é o conserto).
        $this->assertSame(1, substr_count($cru, 'arrastar a irmã'), 'a razão antiga aparece mais de uma vez (ou desapareceu): ela tem de ficar UMA vez, marcada como falsa');
        $this->assertStringContainsString('ERA FALSA PARA ESTE ESTADO', $cru, 'a razão antiga ficou solta no docblock, sem a marca de que foi medida e é falsa — o próximo executor a leria e "consertaria" a lista de volta');

        // ⚠️ E o invariante de sempre: o portão **não** escreve `andamento` nem fala com o ML.
        $limpo = self::semComentarios($cru);
        $this->assertSame(0, substr_count($limpo, "'andamento' =>"), 'o portão ganhou a chave literal de andamento (a sonda larga do gate alheio `186-09` casa isso em QUALQUER array)');
        $this->assertSame(0, substr_count($limpo, 'Http::'), 'o portão ganhou requisição ao Mercado Livre');
        $this->assertGreaterThan(400, substr_count($limpo, "\n"), 'gate sobre quase nada: o leitor não leu o serviço');
    }

    /**
     * ⚠️⚠️ **O BECO, FECHADO — COM ISCA NAS DUAS DIREÇÕES, QUE É O QUE FAZ O CASO VALER.**
     *
     *   · irmã em **`falha`** → a oferta **ENFILEIRA** (decisão 25);
     *   · irmã em **`aguardando_aprovacao`** → **BARRA**, nomeando a modalidade (decisão 23).
     *
     * ⚠️ Só com a primeira metade, um `enfileirar()` que **nunca** barrasse passaria igual; só com a
     * segunda, um que **sempre** barrasse passaria igual.
     *
     * ⚠️ O caminho até `falha` é **pela única porta** (`aprovado → publicando → falha`), que é o
     * único que existe: montar `falha` por `update()` cru pularia a prova de que a irmã **foi**
     * aprovada, que é a razão inteira da decisão 25.
     */
    public function test_irma_em_falha_enfileira_e_irma_aguardando_barra_261021_fix(): void
    {
        [$company, $admin] = $this->conta();
        $portao = app(PublicacaoDoPortaoService::class);

        // ─── ⚠️ ISCA (+): A IRMÃ EM `falha` NÃO BARRA ──────────────────────────────────────
        $c = $this->cenario($company);
        $irma = $this->levarPara($c['premium'], PubAprovacaoOferta::FALHA, $admin, 'O Mercado Livre recusou o anúncio: título longo.');
        $this->assertSame(PubAprovacaoOferta::FALHA, $irma->andamento, 'a irmã não chegou a `falha`: a isca perdeu o sujeito');
        // ⚠️⚠️ **A PROVA DE QUE ELA FOI APROVADA** — é a razão da decisão 25, lida CRUA do banco:
        // `mover(FALHA)` preserva `versao_aprovada`/`aprovado_por`/`aprovado_em`.
        $prova = (array) DB::table('pub_aprovacao_ofertas')->where('id', $irma->id)->first(['versao_aprovada', 'aprovado_por', 'aprovado_em']);
        $this->assertNotNull($prova['aprovado_por'], 'a irmã em `falha` não tem quem a aprovou: a razão da decisão 25 não se aplicaria a ela');
        $this->assertNotNull($prova['aprovado_em']);
        $this->assertNotNull($prova['versao_aprovada']);

        // ⚠️ O item que a aprovação DELA criou tem de sair de cena, senão o Clássico ANEXARIA e o
        // caso mediria o ramo do anexo em vez da guarda.
        $itemDela = $irma->fresh()->filaItem;
        if ($itemDela !== null) {
            $itemDela->update(['status' => PubFilaPublicacaoItem::PARCIAL, 'produto_ativo' => null]);
        }
        $this->assertSame(0, $this->itensVivos((int) $c['produto']->id), 'sobrou item VIVO: o Clássico anexaria e a guarda não seria exercitada');

        $this->assertSame([], $portao->modalidadesAtivasNaoAprovadas($c['classico']->fresh()), 'decisão 25: a irmã em `falha` continua barrando — o beco do retry segue aberto');

        $classico = $this->levarPara($c['classico'], PubAprovacaoOferta::APROVADO, $admin);
        $this->assertNotNull($classico->fresh()->fila_item_id, 'decisão 25: a oferta não entrou na fila com a irmã em `falha`');
        $this->assertSame(1, $this->itensVivos((int) $c['produto']->id), 'nasceu item nenhum (ou mais de um) com a irmã em `falha`');

        // ─── ⚠️ ISCA (−): A IRMÃ EM `aguardando_aprovacao` CONTINUA BARRANDO ───────────────
        $d = $this->cenario($company);
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $d['premium']->andamento, 'gate sobre zero casos: a irmã já estaria fora de `aguardando_aprovacao`');

        $pendentes = $portao->modalidadesAtivasNaoAprovadas($d['classico']);
        $this->assertCount(1, $pendentes, 'decisão 23 AFROUXADA: a irmã que ninguém revisou deixou de barrar');
        $this->assertSame(self::PREMIUM, $pendentes[0]['listing_type_id']);
        $this->assertSame('Premium', $pendentes[0]['modalidade'], 'o jargão `gold_pro` foi para a frase, que é texto de tela');

        $barrado = $this->levarPara($d['classico'], PubAprovacaoOferta::APROVADO, $admin);
        $this->assertNull($barrado->fresh()->fila_item_id, 'decisão 23: o produto entrou na fila com o Premium sem revisão');
        $this->assertSame(0, $this->itensVivos((int) $d['produto']->id));

        // ⚠️ E o MOTIVO REAL no histórico NOMEIA a modalidade que falta, por IGUALDADE DE STRING
        // contra a frase montada pelo próprio serviço — e por PRESENÇA na lista, nunca por `end()`:
        // o número de eventos depende de quantas vezes o enfileiramento foi tentado.
        $this->assertContains(
            PublicacaoDoPortaoService::PREFIXO_NAO_ENFILEIRADA . PublicacaoDoPortaoService::MOTIVO_IRMA_NAO_APROVADA . 'Premium (Aguardando aprovação).',
            $this->eventos($barrado),
            'a recusa da decisão 23 não está no histórico nomeando a modalidade que falta'
        );

        $this->assertSame([], $this->escritasEDesvios(), 'a guarda escreveu no Mercado Livre');
        Queue::assertNothingPushed();
    }

    /**
     * ⚠️⚠️ **A DECISÃO 23 NÃO FOI AFROUXADA: os QUATRO estados não-aprovados, UM POR UM.**
     *
     * ⚠️ Testar só um deixaria os outros três livres para entrar na lista sem nenhum gate acusar —
     * e com 13 perguntas, perder uma deixava 12 e o piso passava. ➡️ Cada estado é exercitado **de
     * ponta a ponta** (predicado **e** enfileiramento), e cada recusa **nomeia** a modalidade com o
     * rótulo pt-BR daquele estado.
     *
     * ⚠️ `preparando` entra pelo **nascimento** da oferta, e é honesto: `TRANSICOES` não tem
     * nenhuma entrada que chegue nele, logo não existe caminho por `mover()`.
     */
    public function test_a_decisao_23_segue_barrando_os_quatro_estados_sem_aprovacao_261021_fix(): void
    {
        [$company, $admin] = $this->conta();
        $portao = app(PublicacaoDoPortaoService::class);
        $porta = app(AndamentoDaOfertaService::class);

        foreach ([
            PubAprovacaoOferta::PREPARANDO,
            PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            PubAprovacaoOferta::EM_REVISAO_ECF,
            PubAprovacaoOferta::AJUSTES_SOLICITADOS,
        ] as $estado) {
            $c = $this->cenario($company);

            // ⚠️ `em_revisao_ecf` e `ajustes_solicitados` são alcançados PELA ÚNICA PORTA, a partir
            // de `aguardando_aprovacao`; `preparando` nasce assim; `aguardando_aprovacao` já é o
            // default da fixture.
            if (in_array($estado, [PubAprovacaoOferta::EM_REVISAO_ECF, PubAprovacaoOferta::AJUSTES_SOLICITADOS], true)) {
                $porta->mover($c['premium'], $estado, $admin);
            } elseif ($estado === PubAprovacaoOferta::PREPARANDO) {
                $c = $this->cenario($company, ['andamento_irma' => PubAprovacaoOferta::PREPARANDO]);
            }

            $irma = $c['premium']->fresh();
            $this->assertSame($estado, $irma->andamento, "a fixture não pôs a irmã em `{$estado}`: o caso mediria outro estado");

            $pendentes = $portao->modalidadesAtivasNaoAprovadas($c['classico']);
            $this->assertCount(1, $pendentes, "decisão 23 AFROUXADA: irmã em `{$estado}` deixou de barrar, e ninguém terminou de revisar essa modalidade");
            $this->assertSame(PubAprovacaoOferta::ANDAMENTOS[$estado], $pendentes[0]['andamento_rotulo'], "a recusa não diz em que estado a irmã está (`{$estado}`)");

            $barrado = $this->levarPara($c['classico'], PubAprovacaoOferta::APROVADO, $admin);
            $this->assertNull($barrado->fresh()->fila_item_id, "decisão 23: o produto entrou na fila com a irmã em `{$estado}`");
            $this->assertSame(0, $this->itensVivos((int) $c['produto']->id), "nasceu item de fila VIVO com a irmã em `{$estado}`");
        }

        // ─── ⚠️ ISCA (+): o caminho que PRECISA continuar passando ────────────────────────
        //
        // Produto de **UM** alvo ativo — o único que o agente encaminha hoje — tem de enfileirar
        // normalmente. Sem esta ponta, *"barrou nos quatro"* seria indistinguível de *"barra
        // sempre"*, e a guarda prenderia para sempre o caminho que existe.
        $umSo = $this->cenario($company, ['so_classico' => true]);
        $this->assertNull($umSo['premium']);
        $this->assertSame([], $portao->modalidadesAtivasNaoAprovadas($umSo['classico']));
        $soClassico = $this->levarPara($umSo['classico'], PubAprovacaoOferta::APROVADO, $admin);
        $this->assertNotNull($soClassico->fresh()->fila_item_id, 'a guarda prendeu produto de UM alvo — o caminho do agente quebrou');

        $this->assertSame([], $this->escritasEDesvios());
        Queue::assertNothingPushed();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) ⚠️⚠️ DECISÃO 26 — O MOTIVO DA RECUSA SOBREVIVE AO ROLLBACK
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **O MOTIVO REAL FICA REGISTRADO, NÃO SÓ NUM AVISO QUE PASSA** (decisão 26 do usuário).
     *
     * **O que precisaria estar errado:** a linha ser escrita **DENTRO** da transação que volta
     * atrás — foi assim que o plano 193-04 a entregou, declarando a consequência e **devolvendo** a
     * pergunta. A pessoa via o motivo no `422` e, depois do `rollback`, **nada ficava registrado**
     * — justamente no caso em que a oferta **fica parada**.
     *
     * ⚠️⚠️ **FORMA COLETORA, DERIVADA DA LISTA DE ANTES — nunca contagem de eventos.** O número de
     * eventos depende de quantas vezes o enfileiramento foi tentado, e o 193-02 o mediu subindo
     * **26 → 27 sem defeito nenhum**. ➡️ Asseverar *"a lista de antes MAIS exatamente esta linha"*
     * prova de uma vez que a linha **existe**, que é **uma só**, que é a **última** e que **nada
     * mais** mudou — e **não depende de contagem**.
     *
     * ⚠️ **E A TRANSAÇÃO CONTINUA FAZENDO O SEU TRABALHO:** a oferta não se move **byte a byte** e
     * **nenhum** item de fila vivo nasce. O registro **acrescenta** um canal; não troca o `422`.
     */
    public function test_a_recusa_do_retry_fica_no_historico_depois_do_rollback_261021_fix(): void
    {
        [$company, $admin] = $this->conta();
        $c = $this->cenario($company, ['so_classico' => true]);
        $emFalha = $this->levarAFalhaSemItemVivo($c['classico'], $admin, 'O Mercado Livre recusou o anúncio: título longo.');
        $this->assertSame(PubAprovacaoOferta::FALHA, $emFalha->andamento, 'a fixture não chegou a `falha`: o caso mediria outra coisa');

        $antes = $this->linhaCrua($emFalha);
        $eventosAntes = $this->eventos($emFalha);
        $this->assertNotSame([], $eventosAntes, 'gate sobre zero casos: a oferta não tem histórico nenhum, e "a lista de antes mais uma linha" não discriminaria nada');

        // ⚠️ FAIL-CLOSED: lista vazia = NINGUÉM. É a trava de "ligar publicar" do PUB-06, e a
        // recusa escolhida é uma que o admin **pode resolver** — o que permite a isca (+) no fim.
        config(['publicador.contas_liberadas.companies' => []]);

        $resposta = $this->postarRetry($company, $emFalha, $admin);

        // ─── ⚠️ O `422` CONTINUA SAINDO: a tela não perdeu o aviso ────────────────────────
        $this->assertSame(422, $resposta->status(), 'decisão 26: o registro no histórico trocou o aviso da tela em vez de acrescentar um canal');
        $this->assertSame(MlbPublicadorAprovacaoController::REGRA_FILA_RECUSOU_O_RETRY, (string) $resposta->json('regra'), 'a recusa perdeu o id estável da regra — a tela não sabe onde marcar');
        $mensagem = (string) $resposta->json('message');
        $this->assertStringContainsString('fila de publicação', $mensagem);
        $this->assertStringContainsString('liberada', $mensagem, 'a recusa não traz o MOTIVO REAL — "algo deu errado" é o que PUB-05 proíbe');

        // ─── ⚠️⚠️ A LINHA SOBREVIVEU AO ROLLBACK, EM FORMA COLETORA ──────────────────────
        $esperada = PublicacaoDoPortaoService::PREFIXO_NAO_ENFILEIRADA . 'A publicação ainda não foi liberada para esta conta.';
        $this->assertSame(
            [...$eventosAntes, $esperada],
            $this->eventos($emFalha),
            'decisão 26: o motivo da recusa não sobreviveu ao rollback (ou entrou duplicado, ou veio com outro texto)'
        );
        // ⚠️ Ator **Sistema**, igual às decisões 21, 23 e 24: quem recusou foi a fila, não quem
        // clicou — e quem clicou já está no evento anterior.
        $ultimo = PubAprovacaoEvento::query()->where('oferta_id', $emFalha->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame($esperada, (string) $ultimo->texto);
        $this->assertNull($ultimo->autor_id, 'a recusa da fila entrou como ato da pessoa');
        $this->assertSame(PubAprovacaoEvento::SISTEMA, $ultimo->tipo, 'a recusa entrou como `error` — o rótulo dele na tela é "Falha na publicação", e nada falhou ao publicar');

        // ─── ⚠️ E A TRANSAÇÃO CONTINUA VOLTANDO ATRÁS O QUE TEM DE VOLTAR ────────────────
        $this->assertSame($antes, $this->linhaCrua($emFalha), 'a oferta se moveu (ou perdeu o erro) numa tentativa que a fila recusou');
        $this->assertSame(0, $this->itensVivos((int) $c['produto']->id), 'nasceu item de fila VIVO numa tentativa recusada');

        // ─── ⚠️ A DE-DUPLICAÇÃO É CONTRA A ÚLTIMA LINHA (item `193-H`), E CONTINUA ASSIM ──
        //
        // Clicar de novo, com o MESMO motivo e sem nenhum fato no meio, **não** escreve segunda
        // linha igual: sem isso, um botão insistente enche a linha do tempo que existe para ser
        // auditada. ⚠️ **E não é de-duplicação contra todo o histórico**: o mesmo motivo voltando
        // DEPOIS de outro fato é informação nova — é o que a segunda metade mede.
        $segunda = $this->postarRetry($company, $emFalha->fresh(), $admin);
        $this->assertSame(422, $segunda->status());
        $this->assertSame([...$eventosAntes, $esperada], $this->eventos($emFalha), 'a mesma recusa seguida escreveu linha repetida: a de-duplicação contra a última linha caiu');

        // ⚠️ Um fato no meio (outra linha, de outro texto) e a MESMA recusa volta a entrar.
        app(\App\Services\Publicador\Aprovacao\HistoricoDaOfertaService::class)->registrar(
            $emFalha->fresh(),
            null,
            PubAprovacaoEvento::SISTEMA,
            'Conferência refeita no Mercado Livre.',
            null
        );
        $terceira = $this->postarRetry($company, $emFalha->fresh(), $admin);
        $this->assertSame(422, $terceira->status());
        $this->assertSame(
            [...$eventosAntes, $esperada, 'Conferência refeita no Mercado Livre.', $esperada],
            $this->eventos($emFalha),
            'item `193-H`: a de-duplicação passou a valer contra TODO o histórico — o mesmo motivo depois de outro fato é informação nova'
        );

        $this->assertSame([], $this->escritasEDesvios(), 'a recusa do retry escreveu no Mercado Livre');
        Queue::assertNothingPushed();
    }

    /**
     * ⚠️⚠️ **A OUTRA DIREÇÃO, E ELA É O QUE FAZ O CASO ANTERIOR VALER:** no retry que **PASSA**,
     * **não** existe linha de recusa. Sem esta ponta, *"a linha existe"* seria indistinguível de
     * *"ele grava recusa sempre"* — e aí a linha do tempo mentiria no caminho normal.
     *
     * ⚠️⚠️ **E A TRANSAÇÃO NÃO FOI DIVIDIDA NEM REMOVIDA.** Quem lesse a decisão 26 de relance
     * poderia "resolver" o rollback partindo a transação em duas — e isso reintroduziria
     * *"`publicando` com `fila_item_id` no item TERMINADO"*, o estado que o plano 193-03 pôde tratar
     * como **impossível** (`Log::warning` e nada mais). ➡️ A asserção é **UMA** `DB::transaction(`
     * na action, lida **SEM COMENTÁRIO** (a prosa do docblock fala dela de propósito).
     */
    public function test_o_retry_que_passa_nao_grava_recusa_e_a_transacao_continua_uma_261021_fix(): void
    {
        [$company, $admin] = $this->conta();
        $c = $this->cenario($company, ['so_classico' => true]);
        $emFalha = $this->levarAFalhaSemItemVivo($c['classico'], $admin, 'O Mercado Livre não respondeu a tempo.');

        $eventosAntes = $this->eventos($emFalha);

        $resposta = $this->postarRetry($company, $emFalha, $admin);

        // ⚠️ MEDIDO pelo 193-03: `->json('regra')` numa resposta de REDIRECIONAMENTO falha como
        // asserção. ➡️ A aceitação é o **STATUS** `302` (`back()->with(...)`).
        $this->assertSame(302, $resposta->status(), 'o retry foi recusado: ' . (string) $resposta->getContent());
        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $emFalha->fresh()->andamento, 'gate sobre zero casos: o retry nem aconteceu');
        $this->assertSame(1, $this->itensVivos((int) $c['produto']->id), 'o retry não republicou: nenhum item VIVO ocupa o produto');

        // ─── ⚠️ ZERO linha de recusa no caminho que deu certo ─────────────────────────────
        $comRecusa = [];
        foreach (array_values(array_diff($this->eventos($emFalha), $eventosAntes)) as $texto) {
            if (str_contains($texto, PublicacaoDoPortaoService::PREFIXO_NAO_ENFILEIRADA)) {
                $comRecusa[] = $texto;
            }
        }
        $this->assertSame([], $comRecusa, 'decisão 26: o retry que PASSOU gravou linha de recusa — a linha do tempo passou a mentir no caminho normal');
        // ⚠️ ISCA (+): o coletor acha a forma que ele deve achar — senão `[]` seria verdade por
        // ele não ler nada.
        $this->assertSame(1, substr_count(PublicacaoDoPortaoService::PREFIXO_NAO_ENFILEIRADA . 'x', PublicacaoDoPortaoService::PREFIXO_NAO_ENFILEIRADA), 'ISCA: o coletor de recusa não casa o próprio prefixo');

        // ─── ⚠️⚠️ A TRANSAÇÃO: UMA, E A GRAVAÇÃO FICA FORA DELA ──────────────────────────
        $this->assertFileExists(base_path(self::ARQUIVO_DO_CONTROLLER), 'caminho fantasma: ' . self::ARQUIVO_DO_CONTROLLER);
        $limpo = self::semComentarios((string) file_get_contents(base_path(self::ARQUIVO_DO_CONTROLLER)));

        $pedaco = self::trechoDoMetodo($limpo, 'public function tentarDeNovo(');
        $this->assertNotSame('', $pedaco, 'a action `tentarDeNovo` desapareceu do controller');
        // ⚠️⚠️ **AS DUAS ISCAS DO RECORTE, e sem elas a contagem `1` seria falso positivo** — ver
        // {@see self::trechoDoMetodo()}: a primeira redação lia a CAUDA DO ARQUIVO e contava 3.
        //  (+) o recorte contém o que é DESTE método;
        //  (−) o recorte NÃO contém o método seguinte (senão ele volta a ser cauda de arquivo).
        $this->assertStringContainsString('REGRA_FILA_RECUSOU_O_RETRY', $pedaco, 'ISCA (+): o recorte do método está vazio ou pegou outro método — a contagem abaixo não mediria nada');
        $this->assertStringNotContainsString('function salvarPreco(', $pedaco, 'ISCA (−): o recorte vazou para o método seguinte — a sonda voltou a ser mais larga que o invariante');
        $this->assertSame(1, substr_count($pedaco, 'DB::transaction('), 'a transação do retry foi DIVIDIDA ou REMOVIDA: isso reintroduz "publicando com o item terminado", que o 193-03 trata como impossível');
        $this->assertSame(1, substr_count($pedaco, '->enfileirar('), 'a action deixou de enfileirar (ou enfileira duas vezes)');
        $this->assertSame(1, substr_count($pedaco, '->registrarRecusaForaDaTransacao('), 'a gravação do motivo da decisão 26 saiu da action (ou foi duplicada)');

        // ⚠️⚠️ **A POSIÇÃO: a gravação vem DEPOIS do `catch`, nunca dentro da transação.** Medida
        // por **posição de ocorrência** no código sem comentário — nunca por número de linha, que
        // outro dev desloca.
        $posTransacao = strpos($pedaco, 'DB::transaction(');
        $posCatch = strpos($pedaco, 'catch (RegraViolada');
        $posGravacao = strpos($pedaco, '->registrarRecusaForaDaTransacao(');
        $this->assertIsInt($posTransacao);
        $this->assertIsInt($posCatch);
        $this->assertIsInt($posGravacao);
        $this->assertGreaterThan($posTransacao, $posCatch, 'o `catch` está antes da transação: a leitura de posição perdeu o sujeito');
        $this->assertGreaterThan($posCatch, $posGravacao, 'decisão 26: a gravação do motivo está DENTRO da transação que volta atrás — ela não sobreviveria ao rollback');

        // ⚠️ E o invariante de sempre: a action não fala com o ML e não despacha job.
        $this->assertSame(0, substr_count($limpo, 'Http::'), 'o controller do painel ganhou requisição direta ao Mercado Livre');
        $this->assertSame(0, substr_count($limpo, 'dispatch('), 'o controller do painel ganhou despacho de job');

        $this->assertSame([], $this->escritasEDesvios(), 'o retry que passou escreveu no Mercado Livre');
        Queue::assertNothingPushed();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) A BIJEÇÃO ids ↔ MÉTODOS ↔ `<verify>`, COM EXPANSOR DE ALTERNÂNCIA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **MÉTODO SEM id NUNCA RODA** (foi o sobrevivente da mutação do plano 186-03) e **id
     * órfão não falha: ele simplesmente nunca roda**. As duas formas de "passar por omissão" são
     * conferidas aqui, por teste **durável**, não por rascunho de scratchpad.
     *
     * ⚠️⚠️ **O EXPANSOR DE ALTERNÂNCIA, ISCADO NAS DUAS DIREÇÕES ANTES DE VALER:** a regex ingênua
     * `/test_[A-Za-z0-9_]+/` casa **ZERO** em `test_(a|b|c)` — o furo que deu `SEM_ID=14`
     * **acusando trabalho correto**.
     *
     * ⚠️ E o **homônimo**: o `--filter` do PHPUnit é regex **NÃO ANCORADO**, e as Fases 186/187/188
     * estão **em produção**. Método de outra fase que seja superstring de um meu faria o id SOMAR e
     * reprovar.
     */
    public function test_o_manifesto_da_quick_tem_bijecao_e_nenhum_id_orfao_261021_fix(): void
    {
        $manifesto = base_path('tests/gates/contagens-261021-01.txt');
        $this->assertFileExists($manifesto, 'o manifesto desta quick não existe — o harness ABORTA sem ele');

        $linhas = array_values(array_filter(
            array_map('trim', explode("\n", (string) file_get_contents($manifesto))),
            static fn (string $l) => $l !== '' && ! str_starts_with($l, '#')
        ));
        $this->assertGreaterThanOrEqual(3, count($linhas), 'o manifesto tem menos ids do que esta quick declara');

        // ─── O EXPANSOR, iscado NAS DUAS DIREÇÕES antes de valer ──────────────────────────
        $expandir = static function (string $filtro): array {
            if (preg_match('/^test_\((.+)\)$/', $filtro, $m) === 1) {
                return array_map(static fn (string $p) => 'test_' . $p, explode('|', $m[1]));
            }

            return [$filtro];
        };
        $this->assertSame(['test_a', 'test_b', 'test_c'], $expandir('test_(a|b|c)'), 'ISCA (+): o expansor não abre a alternância');
        $this->assertSame(['test_abc'], $expandir('test_abc'), 'ISCA (−): o expansor inventou nomes num filtro sem alternância');
        $this->assertSame(0, preg_match_all('/test_[A-Za-z0-9_]+/', 'test_(a|b|c)'), 'a regex ingênua passou a casar alternância: a razão do expansor mudou');
        $this->assertSame(1, preg_match_all('/test_[A-Za-z0-9_]+/', 'test_abc'), 'a regex ingênua deixou de casar nome simples: a isca perdeu o sujeito');

        // ─── id → método ─────────────────────────────────────────────────────────────────
        $doManifesto = [];
        $ids = [];
        foreach ($linhas as $linha) {
            $partes = preg_split('/\s+::\s+/', $linha);
            $cabeca = preg_split('/\s+/', trim((string) $partes[0]));
            $ids[] = $cabeca[0];

            if (($cabeca[1] ?? '') !== 'php') {
                continue;
            }

            $metodos = $expandir(trim((string) $cabeca[2]));
            $this->assertLessThanOrEqual(3, count($metodos), "o id `{$cabeca[0]}` tem mais de TRÊS métodos: alternância grande equivale a filtro de classe e passa dos 120 s");

            $esperado = (int) (preg_match('/tests=(\d+)/', (string) ($partes[1] ?? ''), $m) === 1 ? $m[1] : 0);
            $this->assertSame(count($metodos), $esperado, "o id `{$cabeca[0]}` declara `tests={$esperado}` com " . count($metodos) . ' método(s) no filtro');

            foreach ($metodos as $metodo) {
                $doManifesto[] = $metodo;
            }
        }

        $this->assertSame(array_values(array_unique($ids)), $ids, 'id repetido no manifesto');

        $daClasse = array_values(array_filter(
            get_class_methods($this),
            static fn (string $m) => str_starts_with($m, 'test_')
        ));
        sort($daClasse);
        $doManifestoOrdenado = array_values(array_unique($doManifesto));
        sort($doManifestoOrdenado);

        $this->assertSame($daClasse, $doManifestoOrdenado, 'bijeção quebrada: há método sem id no manifesto (ele NUNCA roda) ou id apontando para método que não existe (ele também nunca roda)');

        // ⚠️ SUFIXO em TODO método, por asserção COLETORA (nunca `grep -c`, que conta LINHAS).
        $semSufixo = array_values(array_filter($daClasse, static fn (string $m) => ! str_ends_with($m, '_261021_fix')));
        $this->assertSame([], $semSufixo, 'método sem o sufixo `_261021_fix`: o `--filter` do PHPUnit é regex NÃO ANCORADO e as Fases 186/187/188 estão em produção');

        // ─── HOMÔNIMO em toda a suíte, com isca nas duas direções ────────────────────────
        $homonimos = [];
        foreach (self::arquivosDeTeste() as $arquivo) {
            if (str_contains($arquivo, 'BecoDoRetryEMotivoRegistradoTest')) {
                continue;
            }
            $texto = (string) file_get_contents($arquivo);
            foreach ($daClasse as $meu) {
                if (preg_match('/function\s+([A-Za-z0-9_]*' . preg_quote($meu, '/') . '[A-Za-z0-9_]*)\s*\(/', $texto, $m) === 1) {
                    $homonimos[] = basename($arquivo) . '::' . $m[1];
                }
            }
        }
        $this->assertSame([], $homonimos, 'há método de outra suíte que o `--filter` NÃO ANCORADO desta quick também casaria');

        // ⚠️ ISCA (+): o varredor de homônimo PEGA uma superstring de um nome meu.
        $umMeu = 'test_a_recusa_do_retry_fica_no_historico_depois_do_rollback_261021_fix';
        $this->assertContains($umMeu, $daClasse, 'o nome usado na isca do homônimo deixou de existir — a isca perdeu o sujeito');
        $this->assertSame(1, preg_match('/function\s+([A-Za-z0-9_]*' . preg_quote($umMeu, '/') . '[A-Za-z0-9_]*)\s*\(/', 'public function ' . $umMeu . '_extra() {}'), 'ISCA (+): o varredor de homônimo não pega superstring');
        // ⚠️⚠️ **O NOME ALHEIO ENTRA POR VARIÁVEL, E ISSO FOI MEDIDO — NÃO É ESTILO.** Escrito como
        // literal, o texto deste arquivo passaria a ter a FORMA DE UMA DECLARAÇÃO, e o gate alheio
        // `186-09 bijecao-e-homonimo` — cuja sonda é `/public function (test_[A-Za-z0-9_]+)\s*\(/`
        // sobre o código SEM COMENTÁRIO — o leria como *"este arquivo declara um método que já
        // existe"* e **REPROVARIA** (foi o FURO 1 da wave 193-04). ⚠️ O nome continua sendo o
        // **REAL** de outra fase, que é o que faz a isca valer.
        $alheioDeVerdade = 'test_tentar_de_novo_nao_publica_no_mercado_livre_com_isca_188_05';
        $this->assertSame(0, preg_match('/function\s+([A-Za-z0-9_]*' . preg_quote($umMeu, '/') . '[A-Za-z0-9_]*)\s*\(/', 'public function ' . $alheioDeVerdade . '() {}'), 'ISCA (−): o varredor acusa nome legítimo de outra fase');

        // ─── id → `<verify>` do artefato desta quick: id órfão nunca roda ────────────────
        $plano = base_path('.planning/quick/261021-fix-beco-do-retry-e-o-gate-188-05/PLAN.md');
        $this->assertFileExists($plano, 'o artefato de planejamento desta quick não existe');
        $texto = (string) file_get_contents($plano);
        $foraDoVerify = array_values(array_filter(
            $ids,
            static fn (string $id) => ! str_contains($texto, '261021-01 ' . $id)
        ));
        $this->assertSame([], $foraDoVerify, 'id do manifesto que o `<verify>` do artefato nunca invoca: ele não falha, ele simplesmente NUNCA RODA');
    }

    /** @return list<string> todo `*Test.php` da suíte */
    private static function arquivosDeTeste(): array
    {
        $saida = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('tests'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $arquivo) {
            if ($arquivo->isFile() && str_ends_with($arquivo->getFilename(), 'Test.php')) {
                $saida[] = $arquivo->getPathname();
            }
        }

        return $saida;
    }
}
