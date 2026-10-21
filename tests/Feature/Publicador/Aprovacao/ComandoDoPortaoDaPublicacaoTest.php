<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoOferta;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\PubValidacao;
use App\Models\User;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\PublicacaoDoPortaoService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\ConferenciaService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as Rotas;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 193, plano 04 — **O CICLO ANDA SOZINHO E O "TENTAR DE NOVO" REPUBLICA DE VERDADE.**
 *
 * ⚠️⚠️ DE CADA CASO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: **"o que precisaria estar errado
 * no mundo para ele falhar?"**
 *
 *   · **O `reconciliar()` existir e ninguém chamá-lo** — era a fronteira declarada pelo 193-03:
 *     oferta aprovada ficaria `aprovado` para sempre e `publicando` nunca viraria `publicado`.
 *   · ⚠️ **LÓGICA DE DOMÍNIO DENTRO DO COMMAND** (T-193-31): um `mover()` ali ficaria **longe de
 *     todo gate** do portão — o `186-04 unica-porta` é por **glob** de
 *     `app/Services/Publicador/Aprovacao/*.php` e **não alcança** `app/Console/Commands/`.
 *   · **Duas passadas do minuto se sobrepondo** (T-193-32): a mesma oferta movida duas vezes, ou
 *     1.440 linhas iguais por dia na linha do tempo que existe para ser auditada.
 *   · ⚠️⚠️ **O RETRY MOVENDO SEM ENFILEIRAR** (T-193-33): a oferta ficaria `publicando` com
 *     `fila_item_id` apontando para o item **TERMINADO** da tentativa anterior, e o reconciliador
 *     a fecharia como `falha` na passada seguinte — *"falhou"* **sem nunca ter tentado**.
 *   · **A guarda "só a partir de `falha`" enfraquecida** (T-193-34): `TRANSICOES[aprovado]`
 *     **inclui** `publicando`, então o serviço **não recusaria** — a trava é da ACTION.
 *   · **O botão fingindo que tentou** (T-193-35): enfileiramento recusado e a oferta indo para
 *     `publicando` de qualquer jeito.
 *
 * ═══ ⚠️ O QUE SAI PELA REDE ════════════════════════════════════════════════════════════════════
 *
 * ⚠️⚠️ **NEM O COMANDO NEM A ACTION DO RETRY FALAM COM O MERCADO LIVRE.**
 * `Http::preventStrayRequests()` e `Queue::fake()` estão de pé em **todos** os casos. A régua
 * honesta é **zero ESCRITA**: a leitura autorizada (o GET público da tarifa real, decisão 9 do
 * usuário) está em **lista CONSTANTE nomeada** — prometer "zero requisição" seria gate que o
 * próprio desenho contradiz, porque **aprovar** (Fase 188) lê a tarifa.
 *
 * ⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU ESTA TELA RENDERIZADA, nem publicação real, nem o cron de
 * verdade rodando.** Não há navegador, nenhuma empresa tem `ml_token` de verdade,
 * `pub_aprovacao_ofertas` está **vazia em produção** e **o scheduler do sistema operacional não é
 * exercido nesta máquina**: o que se prova aqui é (a) o **comando**, chamado por `artisan`, e (b)
 * o **registro** dele no `Schedule`, lido do container **por nome**.
 *
 * ⚠️ **As quatro formas proibidas** (*"confirmar publicação"*, *"segundo passo"*, *"desfazer
 * aprovação"*, *"cancelar aprovação"*) são citadas na prosa desta fase **de propósito**: é a
 * **isca do leitor** do gate de ausência, que lê o código **SEM COMENTÁRIO** (`token_get_all`).
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class ComandoDoPortaoDaPublicacaoTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const CATEGORIA = 'MLB189007';

    /** O comando desta wave — escrito UMA vez, usado em todo lugar. */
    private const COMANDO = 'publicador:portao-publicacao';

    /** O comando que JÁ EXISTIA e que o desta wave segue — é o controle positivo das sondas. */
    private const COMANDO_DA_FILA = 'publicador:fila-publicacao';

    /** ⚠️ A única leitura de rede autorizada nesta fase — a tarifa real (decisão 9 do usuário). */
    private const LEITURAS_AUTORIZADAS = ['/sites/MLB/listing_prices'];

    /** O arquivo do comando desta wave. */
    private const ARQUIVO_DO_COMANDO = 'app/Console/Commands/PublicadorPortaoPublicacao.php';

    /** O controller onde vive a action do retry. */
    private const ARQUIVO_DO_CONTROLLER = 'app/Http/Controllers/MlbPublicadorAprovacaoController.php';

    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // ⚠️ `Carbon::setTestNow()` em todo caso: teste dependente de hora reprova POR HORÁRIO, e
        // é essa a classe das duas falhas que oscilam nesta suíte (`CockpitDaContaTest`).
        Carbon::setTestNow('2026-10-21 11:30:00');

        // ⚠️ De pé em TODOS os casos: é o que transforma "o passo do minuto não fala com o Mercado
        // Livre" de afirmação de docblock em medição.
        Http::preventStrayRequests();

        // ⚠️ Idem para job: "este comando NÃO é fila e não despacha nada" é medição, não promessa.
        Queue::fake();

        // ⚠️ A receita MEDIDA do 188-05: sem o app token pré-aquecido,
        // `ComposicaoFinanceiraService` chama `MlColetaService::getAppToken()` e a rota de aprovar
        // devolve **500** — e o caso mediria um erro de fixture em vez do comportamento.
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
            'nome' => 'Empresa do portão ' . $company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        // ⚠️ `ContasLiberadas` é FAIL-CLOSED: lista vazia = NINGUÉM. Sem esta linha a prontidão da
        // fila recusa com *"A publicação ainda não foi liberada para esta conta"* e todo caso de
        // enfileiramento ficaria verde por não ter exercitado nada.
        config(['publicador.contas_liberadas.companies' => [(int) $company->id]]);

        return [$company, $admin];
    }

    /**
     * Um produto do portão, com **UMA** modalidade ativa por default.
     *
     * ⚠️ Uma modalidade só é o caminho que o agente encaminha hoje, e é também o que mantém a
     * guarda da **decisão 23** fora do caminho destes casos: com dois alvos ativos, aprovar um
     * deles **não** enfileira (a irmã ativa e não aprovada barra), e o caso mediria aquela guarda
     * em vez do comando ou do retry.
     *
     * @param  array<string, mixed>  $o  `premium` (bool) — nasce com as DUAS modalidades ativas
     * @return array<string, mixed>
     */
    private function cenario(Company $company, array $o = []): array
    {
        $this->sequencia++;
        $n = $this->sequencia;

        $produto = PubProduto::create([
            'company_id' => $company->id,
            'sku' => 'CMD-' . $n,
            'nome' => 'Poltrona do comando ' . $n,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => 1,
        ]);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id, 'categoria_id' => self::CATEGORIA]);

        // ⚠️ TÍTULOS DIFERENTES de propósito: título igual nos dois tipos é o bloqueio V-TIT-04 da
        // prontidão, e a fila recusaria por outro motivo — o caso mediria a recusa errada.
        $titulos = [
            self::CLASSICO => "Poltrona Beny decorativa veludo cinza {$n}",
            self::PREMIUM => "Poltrona Beny premium veludo cinza {$n}",
        ];
        $tipos = ($o['premium'] ?? false) ? [self::CLASSICO, self::PREMIUM] : [self::CLASSICO];

        $alvos = [];
        $ofertas = [];
        foreach ($tipos as $i => $tipo) {
            $alvos[$tipo] = PubRascunhoAlvo::create([
                'rascunho_id' => $rascunho->id,
                'listing_type_id' => $tipo,
                'titulo' => $titulos[$tipo],
                'posicao' => $i,
                'ativo' => true,
            ]);
            $ofertas[$tipo] = PubAprovacaoOferta::create([
                'alvo_id' => $alvos[$tipo]->id,
                'rascunho_id' => $rascunho->id,
                'produto_id' => $produto->id,
                'company_id' => $company->id,
                'andamento' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
                'entrou_na_fila_em' => '2026-10-21 08:00:00',
            ]);
        }

        // ⚠️⚠️ `fresh()` OBRIGATÓRIO: **o Eloquent não lê o default do banco.** `PubRascunho::
        // create()` sem `revisao` devolve `revisao` nula em memória enquanto a linha nasce com `1`,
        // e a conferência nasceria com `revisao = 0` — a prontidão recusaria com *"O produto mudou
        // depois da conferência"* e o caso mediria a recusa ERRADA.
        $rascunho = $rascunho->fresh();

        $validacao = PubValidacao::create([
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
            'validacao' => $validacao,
            'alvos' => $alvos,
            'classico' => $ofertas[self::CLASSICO]->fresh(),
            'premium' => isset($ofertas[self::PREMIUM]) ? $ofertas[self::PREMIUM]->fresh() : null,
        ];
    }

    /**
     * A oferta levada ao andamento pedido **pela ÚNICA PORTA** — este teste nunca escreve a coluna
     * `andamento`.
     *
     * ⚠️ `aprovado` passa por `mover()`, que dispara o gancho `aoAprovar()` do 193-02 (é assim que
     * a vida real enfileira). Um `update()` cru pularia o gancho e mediria outro mundo.
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
     * O item que **a própria aprovação** criou, posto no status pedido.
     *
     * ⚠️⚠️ **E ISSO NÃO É CONVENIÊNCIA: É O UNIQUE DO BANCO.** `pubfilai_produto_ativo_uq` deixa UM
     * item **vivo** por produto, então criar um segundo item vivo para um produto que a aprovação
     * já enfileirou **estoura `UniqueConstraintViolationException`** (medido pelo 193-03). ➡️ Para
     * status VIVO o caminho honesto é **mover o item que existe**, que é o que a fila faz na vida
     * real.
     *
     * @param  array<string, mixed>  $extra
     */
    private function itemDaAprovacao(PubAprovacaoOferta $oferta, string $status, array $extra = []): PubFilaPublicacaoItem
    {
        $item = $oferta->fresh()->filaItem;
        $this->assertNotNull($item, 'a aprovação não enfileirou: a fixture deste caso não tem item para mover');

        $item->update([
            'status' => $status,
            'produto_ativo' => in_array($status, PubFilaPublicacaoItem::VIVOS, true) ? $item->produto_id : null,
            ...$extra,
        ]);

        return $item->fresh();
    }

    /**
     * Uma publicação com os itens pedidos.
     *
     * @param  array<string, mixed>  $c
     * @param  list<array<string, mixed>>  $itens
     */
    private function publicacao(array $c, string $status, array $itens, ?string $motivoDaConta = null): PubPublicacao
    {
        $p = PubPublicacao::create([
            'rascunho_id' => $c['rascunho']->id,
            'revisao' => (int) $c['rascunho']->revisao,
            'modelo_publicacao' => 'user_products',
            'plano_hash' => str_repeat('a', 64),
            'status' => $status,
            'chave_idempotencia' => (string) Str::uuid(),
            'iniciada_em' => now()->subMinutes(3),
            'conta_snapshot' => $motivoDaConta === null ? null : ['motivo' => $motivoDaConta],
        ]);

        foreach ($itens as $i => $item) {
            PubPublicacaoItem::create([
                'publicacao_id' => $p->id,
                'indice' => $item['indice'] ?? $i,
                'listing_type_id' => $item['tipo'],
                'variante_chave' => $item['variante'] ?? ('cor=' . $i),
                'status' => $item['status'],
                'ml_item_id' => $item['mlb'] ?? null,
                'avisos' => $item['avisos'] ?? null,
            ]);
        }

        return $p->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Apoio
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** O comando rodado por `artisan`, com a saída capturada. @return array{0: int, 1: string} */
    private function rodarOComando(): array
    {
        $codigo = Artisan::call(self::COMANDO);

        return [$codigo, trim((string) Artisan::output())];
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

    /** @return array<string, mixed> os quatro campos da prova da aprovação, crus do banco */
    private function provaDaAprovacao(PubAprovacaoOferta $oferta): array
    {
        return (array) DB::table('pub_aprovacao_ofertas')->where('id', $oferta->id)
            ->first(['versao', 'versao_aprovada', 'aprovado_por', 'aprovado_em']);
    }

    /** O evento agendado cujo comando contém `$assinatura`, achado **POR NOME** e nunca por linha. */
    private function eventoAgendado(string $assinatura): ?\Illuminate\Console\Scheduling\Event
    {
        foreach (app(Schedule::class)->events() as $evento) {
            if (str_contains((string) $evento->command, $assinatura)) {
                return $evento;
            }
        }

        return null;
    }

    /** O código PHP **sem comentário**, por `token_get_all` — nunca por regex sobre o texto cru. */
    private static function semComentarios(string $codigo): string
    {
        $saida = '';
        foreach (token_get_all($codigo) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    // Preserva as quebras de linha: a contagem de linhas continua honesta.
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
    // (1) O COMANDO DO MINUTO — RODAR À MÃO É SEGURO, E O SILÊNCIO É A RESPOSTA CERTA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **Sem nada esperando a fila, o comando não faz nada e não imprime nada.**
     *
     * ⚠️⚠️ **E A ISCA É O QUE FAZ O CASO VALER:** *"não imprimiu"* seria verdade **por o comando
     * não existir**, ou por ele imprimir só quando quer. ➡️ A segunda metade do caso tem uma oferta
     * de verdade esperando, e aí o resumo **aparece** — e o `exit` continua `0`.
     */
    public function test_o_comando_do_minuto_roda_sem_fila_viva_e_nao_faz_nada_193_04(): void
    {
        // ─── Nada no banco: silêncio e `exit 0` ─────────────────────────────────────────────
        $this->assertSame(0, PubAprovacaoOferta::count(), 'a fixture deste trecho tem de estar vazia');

        [$codigo, $saida] = $this->rodarOComando();

        $this->assertSame(0, $codigo, 'rodar à mão não é seguro: o comando saiu diferente de 0 sem nada na fila');
        $this->assertSame('', $saida, 'o comando imprimiu ruído sem ter nada para contar');

        // ⚠️ Nem job, nem requisição — "este comando não é fila" é medição.
        Queue::assertNothingPushed();
        $this->assertSame([], $this->escritasEDesvios(), 'o passo do minuto falou com o Mercado Livre');
        $this->assertSame([], Http::recorded()->all(), 'o passo do minuto fez requisição — nem leitura ele faz');

        // ─── ⚠️ ISCA: com oferta esperando, o resumo APARECE ────────────────────────────────
        [$company, $admin] = $this->conta();
        $c = $this->cenario($company);
        $oferta = $this->levarPara($c['classico'], PubAprovacaoOferta::APROVADO, $admin);
        $this->itemDaAprovacao($oferta, PubFilaPublicacaoItem::PUBLICANDO);

        [$codigoComFila, $saidaComFila] = $this->rodarOComando();

        $this->assertSame(0, $codigoComFila);
        $this->assertNotSame('', $saidaComFila, 'ISCA: o comando ficou calado até COM oferta esperando — o silêncio acima não provava nada');
        $this->assertStringContainsString('Portão de publicação:', $saidaComFila);
        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $oferta->fresh()->andamento, 'o comando não chamou a reconciliação');

        // ⚠️ E nem aqui ele despacha ou fala com o ML.
        Queue::assertNothingPushed();
        $this->assertSame([], $this->escritasEDesvios());
    }

    /**
     * ⚠️⚠️ **ZERO LÓGICA PRÓPRIA NO COMMAND (T-193-31), LIDO SEM COMENTÁRIO.**
     *
     * **Por que isso importa mais aqui que em qualquer outro arquivo:** o gate `186-04 unica-porta`
     * é por **glob** de `app/Services/Publicador/Aprovacao/*.php`. Um Command vive em
     * `app/Console/Commands/` e **não cai no glob** — então lógica de andamento ali ficaria **fora
     * do alcance** do gate que existe para pegar segundo escritor. ➡️ A trava é esta sonda.
     *
     * ⚠️ **CONTAGEM DE OCORRÊNCIAS** (`substr_count`/`preg_match_all`), nunca `grep -c` (conta
     * LINHAS) e nunca `preg_match(...) === 1` (passa com a linha de código **apagada** quando o
     * texto também vive em comentário).
     *
     * ⚠️ **A sonda é iscada ANTES de valer**, nas duas direções: ela **acha** um texto sintético
     * que chame `mover(` e **não acha** o comando limpo. Sem a isca (+), o `assertSame([], …)`
     * seria vazio por construção.
     */
    public function test_o_comando_nao_tem_logica_propria_lido_sem_comentario_com_isca_193_04(): void
    {
        $arquivos = [self::ARQUIVO_DO_COMANDO, self::ARQUIVO_DO_CONTROLLER];

        // ⚠️ ANTI-FANTASMA: `test -e` em CADA caminho ANTES de medir — são DEZOITO fantasmas nesta
        // milestone, e o fechamento da 189 pegou 6 de 32. Caminho inexistente faria o laço rodar
        // sobre nada e o gate ficaria verde sem ler uma linha.
        foreach ($arquivos as $rel) {
            $this->assertFileExists(base_path($rel), "caminho fantasma no gate: {$rel}");
        }

        $comandoLimpo = self::semComentarios((string) file_get_contents(base_path(self::ARQUIVO_DO_COMANDO)));

        // ─── (a) O QUE O COMMAND NÃO PODE CONTER ────────────────────────────────────────────
        //
        // ⚠️ A lista é por **SIGNIFICADO**: transição, modelo de domínio, consulta, rede e fila.
        // ⚠️⚠️ E ela NÃO inclui os nomes dos estados (`publicando`, `publicado`, `falha`), e isso é
        // deliberado: eles são **chaves do resumo que o serviço devolve**, e uma sonda que os
        // proibisse seria **mais larga que o invariante** — ela reprovaria o comando por imprimir
        // corretamente o que leu. (É a família que já barrou um deploy nesta milestone.) ➡️ O
        // comando imprime as chaves **sem escrevê-las**, lendo-as do próprio retorno.
        $proibidos = [
            '->mover(' => 'transição de andamento dentro do Command',
            'PubAprovacaoOferta' => 'modelo de domínio dentro do Command',
            'PubFilaPublicacaoItem' => 'modelo da fila dentro do Command',
            'PubPublicacaoItem' => 'modelo da publicação dentro do Command',
            "'andamento'" => 'chave literal de andamento (a sonda larga do gate 186-09)',
            '->andamento' => 'leitura/escrita direta da coluna de andamento',
            'DB::' => 'consulta crua dentro do Command',
            '->where(' => 'consulta de domínio dentro do Command',
            'Http::' => 'requisição dentro do Command',
            'dispatch(' => 'despacho de job dentro do Command',
            'ClienteMlPublicador' => 'cliente do Mercado Livre dentro do Command',
        ];

        // ⚠️⚠️ ISCA (+) — a sonda TEM de pegar cada forma, e vem ANTES da varredura.
        $sintetico = <<<'PHP'
        <?php
        $o = PubAprovacaoOferta::query()->where('andamento', 'aprovado')->first();
        $p->mover($o, 'publicando');
        $o->andamento;
        DB::table('x')->get();
        Http::get('https://api.mercadolibre.com/items');
        dispatch(new PublicarRascunhoJob($r));
        PubFilaPublicacaoItem::find(1);
        PubPublicacaoItem::find(1);
        app(ClienteMlPublicador::class);
        PHP;
        $naoPegos = [];
        foreach ($proibidos as $forma => $porque) {
            if (substr_count($sintetico, $forma) < 1) {
                $naoPegos[] = $forma;
            }
        }
        $this->assertSame([], $naoPegos, 'ISCA (+): a sonda não casa alguma das formas que ela existe para pegar');

        // ─── A VARREDURA ────────────────────────────────────────────────────────────────────
        $infratores = [];
        foreach ($proibidos as $forma => $porque) {
            $quantas = substr_count($comandoLimpo, $forma);
            if ($quantas > 0) {
                $infratores[] = $forma . ' × ' . $quantas . ' (' . $porque . ')';
            }
        }
        $this->assertSame([], $infratores, 'T-193-31: o Command do minuto ganhou lógica própria, longe de todo gate do portão');

        // ⚠️ GATE SOBRE QUASE NADA: o leitor tem de ter lido código de verdade.
        $this->assertGreaterThan(15, substr_count($comandoLimpo, "\n"), 'gate sobre quase nada: o leitor não leu o Command');

        // ─── (b) UM CAMINHO SÓ: `reconciliar()` chamado UMA vez ─────────────────────────────
        $this->assertSame(1, substr_count($comandoLimpo, '->reconciliar('), 'o Command chama a reconciliação mais de uma vez (ou nenhuma) — o passo do minuto é UM');
        $this->assertSame(1, substr_count($comandoLimpo, 'PublicacaoDoPortaoService $'), 'o serviço do portão tem de entrar por injeção no `handle()`, uma vez');
        // ⚠️ A fronteira que o 193-03 declarou: o método é PÚBLICO e é o que esta wave passa a
        // chamar a cada minuto. Se ele desaparecer, o Command fica apontando para o vazio.
        $this->assertTrue(method_exists(PublicacaoDoPortaoService::class, 'reconciliar'), 'o `reconciliar()` do 193-03 desapareceu — o passo do minuto não tem o que chamar');

        // ─── (c) ⚠️ AS QUATRO FORMAS PROIBIDAS, PELA **UNIÃO** DAS DUAS SONDAS QUE EXISTEM ──
        //
        // ⚠️⚠️ MEDIDO pelas waves 02 e 03: a `sondaPub09` do gate **186-09 não** casa a forma pt-BR
        // acentuada com espaço (*"Confirmar publicação"*) — só `confirmar_publicacao`/
        // `confirmarPublicacao` —, e a sonda do **188-05** faz o INVERSO (casa a prosa acentuada e
        // **não** casa `undo` nem `publicar_aprovado`). **Nenhuma das duas, isolada, cobre as nove
        // formas.** ➡️ Aqui vai a UNIÃO, que é estritamente mais forte que qualquer uma.
        //
        // ⚠️ **BORDA À ESQUERDA SÓ** em `desfazer`/`undo`, e é medição: `undo` é substring de
        // *segundo*, *fundo* e *profundo* (três arquivos corretos já foram acusados por isso), e
        // borda nos DOIS lados cega a sonda para `desfazerAprovacao`, que é a forma do defeito.
        $sonda = '/(confirmar[\s_\-]*publica(?:ção|cao|tion)|confirmarPublicacao'
            . '|segundo[\s_\-]*passo|segundoPasso|second[\s_\-]*step'
            . '|(?<![\p{L}\p{N}])desfazer|(?<![\p{L}\p{N}])undo'
            . '|publicar[\s_\-]*aprovado|publicarAprovado'
            . '|cancelar[\s_\-]*(?:a[\s_\-]*)?aprova(?:ção|cao)|cancelarAprovacao)/ui';

        $devemCasar = [
            'confirmar publicação (prosa acentuada)' => "'rotulo' => 'Confirmar publicação'",
            'confirmarPublicacao (camelCase)' => 'public function confirmarPublicacao() {}',
            'segundo_passo (snake_case)' => "const SEGUNDO_PASSO = 'x';",
            'segundoPasso (camelCase)' => 'private bool $segundoPasso = true;',
            'a forma de voltar atrás (camelCase)' => 'function desfazerAprovacao() {}',
            'undo com _ em volta' => 'const JANELA_DE_UNDO = 5;',
            'publicar_aprovado' => "Route::post('/publicar_aprovado');",
            'publicarAprovado' => 'public function publicarAprovado() {}',
            'cancelar aprovação (prosa acentuada)' => "'rotulo' => 'Cancelar aprovação'",
        ];
        foreach ($devemCasar as $comoSeChama => $texto) {
            $this->assertSame(1, preg_match($sonda, $texto), "ISCA (+): a sonda não casa `{$comoSeChama}`");
        }
        // ⚠️ ISCA (−): o rótulo LEGÍTIMO (inalterado por decisão) e as palavras pt-BR com `undo`.
        foreach (['Aprovar para publicação', 'o fundo do card', 'de forma mais profunda'] as $legitimo) {
            $this->assertSame(0, preg_match($sonda, $legitimo), "ISCA (−): a sonda reprovaria `{$legitimo}`, que é legítimo");
        }

        $comSegundoPasso = [];
        $iscaDoLeitor = 0;
        foreach ($arquivos as $rel) {
            $cru = (string) file_get_contents(base_path($rel));
            if (preg_match_all($sonda, self::semComentarios($cru)) > 0) {
                $comSegundoPasso[] = $rel;
            }
            $iscaDoLeitor += preg_match_all($sonda, $cru);
        }
        $this->assertSame([], $comSegundoPasso, 'PUB-09 / decisão 1: o segundo passo da aprovação reapareceu em código');

        // ⚠️⚠️ A PROVA DE QUE O GATE LÊ SEM COMENTÁRIO: no texto CRU a sonda **acha** (a prosa do
        // Command cita as formas de propósito) e no limpo não acha nada. Sem esta ponta, "zero
        // infratores" seria indistinguível de "a sonda não lê nada".
        $this->assertGreaterThan(0, $iscaDoLeitor, 'a prosa destes arquivos deixou de citar as formas proibidas — a isca do leitor perdeu o sujeito');
    }

    /**
     * ⚠️⚠️ **IDEMPOTÊNCIA É REQUISITO, NÃO ZELO: o comando roda 60 VEZES POR HORA.**
     *
     * Duas passadas seguidas têm de dar o **MESMO** estado, sem evento de histórico novo e sem
     * reenviar nada. ⚠️ **A comparação é por RECONSULTA AO BANCO — a linha INTEIRA, byte a byte,
     * inclusive `updated_at` — e nunca por stdout:** a disciplina do projeto é essa, e foi violada
     * por quem a conhecia (uma wave leu um log compartilhado e reportou `0 falhas` quando eram 11).
     *
     * ⚠️ E há **isca**: a 1ª passada **move de verdade** (o estado antes e depois dela é
     * diferente), senão *"nada mudou na 2ª"* seria verdade por nada ter mudado nunca.
     */
    public function test_duas_passadas_do_comando_dao_o_mesmo_estado_sem_evento_novo_193_04(): void
    {
        [$company, $admin] = $this->conta();
        $c = $this->cenario($company);
        $oferta = $this->levarPara($c['classico'], PubAprovacaoOferta::APROVADO, $admin);
        $item = $this->itemDaAprovacao($oferta, PubFilaPublicacaoItem::PUBLICANDO);

        $antesDeTudo = $this->linhaCrua($oferta);
        $this->assertSame(PubAprovacaoOferta::APROVADO, $antesDeTudo['andamento']);

        // ─── 1ª passada: move de verdade ────────────────────────────────────────────────────
        [$codigo1, ] = $this->rodarOComando();
        $this->assertSame(0, $codigo1);

        $depoisDa1a = $this->linhaCrua($oferta);
        $eventosDa1a = $this->eventos($oferta);
        $itemDepoisDa1a = (array) DB::table('pub_fila_publicacao_itens')->where('id', $item->id)->first();

        $this->assertSame(PubAprovacaoOferta::PUBLICANDO, $depoisDa1a['andamento'], 'a 1ª passada não moveu nada: a isca da idempotência perdeu o sujeito');
        $this->assertNotSame($antesDeTudo['andamento'], $depoisDa1a['andamento'], 'ISCA: a 1ª passada tem de MUDAR algo, senão "nada mudou na 2ª" não prova nada');

        // ─── 2ª passada: NO-OP COMPLETO, por reconsulta ao banco ────────────────────────────
        [$codigo2, ] = $this->rodarOComando();
        $this->assertSame(0, $codigo2, 'a 2ª passada do minuto saiu diferente de 0 — a lista fechada TRANSICOES recusaria e o erro seria contado');

        $this->assertSame($depoisDa1a, $this->linhaCrua($oferta), 'T-193-32: a 2ª passada mexeu na linha da oferta (inclusive `updated_at`)');
        $this->assertSame($eventosDa1a, $this->eventos($oferta), 'T-193-32: a 2ª passada duplicou linha do histórico — a 1.440 linhas iguais por dia');

        // ⚠️ E o ITEM DA FILA também não se moveu — byte a byte, crua do banco: o comando só **LÊ**
        // a fila. Mover item de fila é do `AgendadorDaFila`, que esta fase reusa INTOCADO.
        $this->assertSame($itemDepoisDa1a, (array) DB::table('pub_fila_publicacao_itens')->where('id', $item->id)->first(), 'o passo do minuto mexeu no item da fila — ele só LÊ a fila');
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $item->fresh()->status);

        Queue::assertNothingPushed();
        $this->assertSame([], Http::recorded()->all(), 'o passo do minuto fez requisição ao Mercado Livre');

        // ─── ⚠️ 3ª passada, agora com o item TERMINADO: o ciclo FECHA e segue idempotente ───
        //
        // ⚠️ `publicacao_id` no item é OBRIGATÓRIO na fixture, e isto eu MEDI: sem ele o
        // `desfecho()` lê publicação nula, conclui *"nada foi criado"* e a oferta vai a **`falha`**
        // — o caso mediria o ramo errado e eu teria "consertado" trocando a expectativa.
        $terminado = $this->itemDaAprovacao($oferta, PubFilaPublicacaoItem::PUBLICADO);
        $p = $this->publicacao($c, PubPublicacao::PUBLISHED, [
            ['tipo' => self::CLASSICO, 'status' => PubPublicacaoItem::CREATED, 'mlb' => 'MLB912345678', 'indice' => 0],
        ]);
        $terminado->update(['publicacao_id' => $p->id]);

        [$codigo3, ] = $this->rodarOComando();
        $this->assertSame(0, $codigo3);

        $publicada = $this->linhaCrua($oferta);
        $this->assertSame(PubAprovacaoOferta::PUBLICADO, $publicada['andamento']);
        $this->assertSame('MLB912345678', $publicada['ml_item_id'], 'o MLB confirmado não foi gravado');

        [$codigo4, ] = $this->rodarOComando();
        $this->assertSame(0, $codigo4);
        $this->assertSame($publicada, $this->linhaCrua($oferta), 'a passada seguinte mexeu na oferta já publicada (estado terminal por estrutura)');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) O AGENDAMENTO — POR NOME, NUNCA POR LINHA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O agendamento é achado no `Schedule` do container PELO NOME do comando**, nunca por
     * número de linha: um gate ancorado em `'Servico:131'` quebrou nesta base quando outro dev
     * deslocou a linha em +1.
     *
     * ⚠️⚠️ **E O QUE ESTE CASO **NÃO** PROVA, declarado:** o cron do sistema operacional **não é
     * exercido nesta máquina**. O que se mede é o **registro** (expressão, mutex e `onOneServer`) e,
     * no caso seguinte, a **ordem** no arquivo. Rodar o minuto de verdade é prova de produção.
     *
     * ⚠️ Isca nas DUAS direções: o controle positivo (`publicador:fila-publicacao`, que existe) é
     * achado pela mesma sonda, e um nome sintético inexistente **não** é.
     */
    public function test_o_agendamento_esta_no_scheduler_por_nome_com_overlapping_e_um_servidor_193_04(): void
    {
        // ⚠️ ISCA (+) — o controle positivo: se a sonda não achar o comando que JÁ ESTÁ agendado,
        // ela está cega e o "achei o meu" abaixo não valeria nada.
        $daFila = $this->eventoAgendado(self::COMANDO_DA_FILA);
        $this->assertNotNull($daFila, 'ISCA (+): a sonda não acha nem o `publicador:fila-publicacao`, que já existe — ela está cega');

        // ⚠️ ISCA (−) — e um nome que não existe NÃO pode ser achado.
        $this->assertNull($this->eventoAgendado('publicador:nao-existe-9z7'), 'ISCA (−): a sonda acha comando inexistente');

        $meu = $this->eventoAgendado(self::COMANDO);
        $this->assertNotNull($meu, 'o `' . self::COMANDO . '` não está agendado: o `reconciliar()` existiria sem ninguém chamá-lo a cada minuto');

        // ─── A FORMA, por igualdade de CONJUNTO (piso `>= N` não prova presença) ────────────
        $this->assertSame([
            'expressao' => '* * * * *',
            'nome' => 'publicador-portao-publicacao',
            'sem_sobreposicao' => true,
            'minutos_do_mutex' => 10,
            'um_servidor_so' => true,
        ], [
            'expressao' => (string) $meu->expression,
            'nome' => (string) $meu->description,
            'sem_sobreposicao' => (bool) $meu->withoutOverlapping,
            'minutos_do_mutex' => (int) $meu->expiresAt,
            'um_servidor_so' => (bool) $meu->onOneServer,
        ], 'o agendamento do portão perdeu a expressão de minuto, o mutex de 10 min ou o `onOneServer`');

        // ⚠️ ISCA: o vizinho que JÁ EXISTIA tem a MESMA forma — é dele que a minha foi copiada, e
        // sem esta linha "10 e true" poderiam ser default do framework (não são: o default de
        // `expiresAt` é **1440** e de `onOneServer` é **false**).
        $this->assertSame(
            ['* * * * *', true, 10, true],
            [(string) $daFila->expression, (bool) $daFila->withoutOverlapping, (int) $daFila->expiresAt, (bool) $daFila->onOneServer],
            'ISCA: o vizinho mudou de forma — a minha deixou de espelhar o precedente'
        );
        // ⚠️⚠️ E A PROVA DE QUE `10` E `true` NÃO SÃO DEFAULT DO FRAMEWORK — sem isto as duas
        // asserções acima seriam **vazias por construção**: um `Schedule::command()->everyMinute()`
        // pelado passaria se os defaults fossem esses. Medido por reflexão na própria classe.
        $this->assertSame(1440, (int) (new \ReflectionProperty(\Illuminate\Console\Scheduling\Event::class, 'expiresAt'))->getDefaultValue(), 'o default de `expiresAt` deixou de ser 1440: o `10` acima pode ter virado default, e a asserção, vazia');
        $this->assertFalse((bool) (new \ReflectionProperty(\Illuminate\Console\Scheduling\Event::class, 'onOneServer'))->getDefaultValue(), 'o default de `onOneServer` deixou de ser false: a asserção dele virou vazia');
        $this->assertFalse((bool) (new \ReflectionProperty(\Illuminate\Console\Scheduling\Event::class, 'withoutOverlapping'))->getDefaultValue(), 'o default de `withoutOverlapping` deixou de ser false: a asserção dele virou vazia');

        // ⚠️ E o comando agendado é o MEU, com a assinatura exata (nunca um homônimo por prefixo).
        $this->assertStringContainsString(self::COMANDO, (string) $meu->command);
        $this->assertNotSame((string) $daFila->command, (string) $meu->command, 'o evento achado é o do vizinho, não o meu');
    }

    /**
     * ⚠️ **A ordem relativa no arquivo: o portão roda DEPOIS da fila** — provado por **posição de
     * ocorrência** das duas assinaturas no código **sem comentário**, nunca por número de linha.
     *
     * ⚠️ **Ler sem comentário não é zelo aqui:** a prosa do bloco novo **cita** o
     * `publicador:fila-publicacao` para explicar a ordem, e uma sonda sobre o texto cru casaria o
     * comentário e mediria a prosa em vez do código.
     *
     * ⚠️ **E por que a ordem é barata:** o portão espelha o que a fila acabou de fechar. Por o
     * passo ser **idempotente**, inverter a ordem só **atrasa um minuto** — nunca corrompe. É por
     * isso que este caso é de ordem **declarada**, não de invariante de corretude.
     */
    public function test_a_ordem_relativa_no_arquivo_poe_o_portao_depois_da_fila_193_04(): void
    {
        $rel = 'routes/console.php';
        $this->assertFileExists(base_path($rel), "caminho fantasma: {$rel}");

        $cru = (string) file_get_contents(base_path($rel));
        $limpo = self::semComentarios($cru);

        $chamadaDaFila = "Schedule::command('" . self::COMANDO_DA_FILA . "')";
        $chamadaDoPortao = "Schedule::command('" . self::COMANDO . "')";

        // ⚠️ UMA ocorrência de cada no código: duas seriam agendamento duplicado (o minuto rodando
        // duas vezes), zero seria o bloco ausente.
        $this->assertSame(1, substr_count($limpo, $chamadaDaFila), 'o agendamento da fila aparece um número diferente de 1 vez no código');
        $this->assertSame(1, substr_count($limpo, $chamadaDoPortao), 'o agendamento do portão aparece um número diferente de 1 vez no código');

        $posFila = strpos($limpo, $chamadaDaFila);
        $posPortao = strpos($limpo, $chamadaDoPortao);
        $this->assertNotFalse($posFila);
        $this->assertNotFalse($posPortao);
        $this->assertGreaterThan($posFila, $posPortao, 'o bloco do portão foi para ANTES do da fila: ele espelha o que aquela passada acabou de fechar');

        // ⚠️⚠️ ISCA DO LEITOR: no texto CRU a prosa do bloco novo **cita** o comando da fila mais
        // vezes que o código. Sem esta ponta, ler "sem comentário" seria zelo sem sujeito.
        $this->assertGreaterThan(
            substr_count($limpo, self::COMANDO_DA_FILA),
            substr_count($cru, self::COMANDO_DA_FILA),
            'a prosa deixou de citar o comando vizinho — ler sem comentário perdeu o sujeito'
        );

        // ⚠️ E os blocos de OUTRA SESSÃO que vivem aqui continuam de pé: este plano acrescenta o
        // seu bloco e **não reordena nem edita** o que é alheio (há sessão paralela nesta árvore).
        foreach (['publicador:agente-encaminhar', 'publicador:agente-varrer'] as $alheio) {
            $this->assertSame(1, substr_count($limpo, "Schedule::command('" . $alheio . "')"), "o bloco alheio `{$alheio}` foi mexido — ele é de outra sessão");
        }
    }
}
