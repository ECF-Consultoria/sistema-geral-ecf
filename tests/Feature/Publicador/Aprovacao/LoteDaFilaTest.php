<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
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
use App\Services\Publicador\Aprovacao\AprovacaoDaOfertaService;
use App\Services\Publicador\Aprovacao\BloqueiosDaOfertaService;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\Fila\GaleriaDaOfertaService;
use App\Services\Publicador\Aprovacao\Fila\LinhaDaOfertaService;
use App\Services\Publicador\Aprovacao\Fila\LoteDaFilaService;
use App\Services\Publicador\Aprovacao\Fila\ProximoARevisarService;
use App\Services\Publicador\Aprovacao\HistoricoDaOfertaService;
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
 * Fase 187, plano 09 — O LOTE DA FILA: o veredicto de TODAS as selecionadas, a aprovação só das
 * aptas, a rota POST e a FRONTEIRA (aprovar ENFILEIRA; a escrita no ML é da Fase 193).
 *
 * ⚠️⚠️ **DE CADA GATE, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que precisaria estar
 * errado no mundo para ele falhar?"**
 *
 *   · **o veredicto** — esconder quem ficou de fora (que o usuário RECUSOU: a escolha dele foi
 *     *"tudo de uma vez, e só libera com todas conferidas"*), ou mostrar `×` sem motivo, o que
 *     manda a pessoa clicar de novo sem saber o que consertar.
 *   · **os DOIS caminhos do motivo** — tratar "qualquer inapta" igual. A inapta **ao vivo** tem
 *     bloqueios a listar; a inapta **fora do ao vivo** não tem bloqueio nenhum, e o motivo dela é
 *     o andamento. Um serviço que só soubesse o primeiro caminho passaria em metade dos casos.
 *   · **a escrita** — aprovar as inaptas (T-187-92), ou derrubar o lote inteiro por causa de uma.
 *   · **a versão** — aprovar na versão ATUAL em vez da que a pessoa viu (COL-04). ⚠️ E isto é
 *     **medição**, não precaução: `AprovacaoDaOfertaService:258` passa `$oferta->versao` para
 *     `aprovar()`, então no caminho do POST esperado e atual são sempre iguais e a trava nunca
 *     dispararia. Ver {@see self::test_a_versao_divergente_recusa_SO_ELA_e_as_outras_passam_187_09}.
 *   · **a conta** — oferta de outra empresa no corpo do POST ser aprovada (T-187-91/T-187-96).
 *   · **o ML** — uma requisição de publicação sair desta fase. Aprovar **enfileira**; o gancho
 *     `aoAprovar` está VAZIO e é da **Fase 193**. Provado por **ausência de requisição**.
 *   · **o sucesso falso** — lote vazio ou só com inaptas respondendo "pronto".
 *   · **o segundo passo** — alguém criar "confirmar publicação", "desfazer" ou "cancelar
 *     aprovação" (T-187-93). Decisão 3 do usuário: **aprovar JÁ PUBLICA**, e o rótulo é
 *     **"Aprovar para publicação"**, inalterado.
 *
 * ⚠️ **O GATE DE TERRITÓRIO MEDE OS COMMITS DO PRÓPRIO ESCOPO, NÃO A ÁRVORE.** Nesta árvore há
 * TRÊS sessões e `git log --format=%an` devolve **um** valor para todas — o autor **não
 * discrimina nada**. O discriminador é o **escopo do assunto** (`(187-09)`).
 *
 * ⚠️ **Nenhuma chamada à API real do Mercado Livre:** `Http::fake()` + `preventStrayRequests()`.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class LoteDaFilaTest extends TestCase
{
    use RefreshDatabase;

    /** ⚠️ O commit de PARTIDA **deste plano**, capturado na PRIMEIRA ação do executor. */
    private const PARTIDA = 'f75864d0';

    /** O escopo de assunto deste plano. O que discrimina, já que o autor não discrimina. */
    private const ESCOPO = '/\(187-09\)/';

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const CATEGORIA = 'MLB189007';

    private ?DadosEfetivosService $efetivos = null;

    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 10:00:00');

        Http::preventStrayRequests();
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        // ⚠️ O `fake` responde SÓ `/sites/MLB/listing_prices` com a fixture real. Qualquer outro
        // caminho devolve 404 **registrado**, e é isso que faz a asserção de ZERO requisição de
        // publicação ter sentido: a tarifa é esperada, a publicação não.
        Http::fake(function (Request $req) {
            $caminho = (string) parse_url($req->url(), PHP_URL_PATH);
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $query);

            if ($caminho === '/sites/MLB/listing_prices') {
                $tipo = (string) ($query['listing_type_id'] ?? '');
                $arquivo = base_path('tests/fixtures-ml/sondagem/publico/categorias/'.self::CATEGORIA."/listing_prices_{$tipo}.json");

                return is_file($arquivo)
                    ? Http::response(json_decode((string) file_get_contents($arquivo), true)['resposta'], 200)
                    : Http::response(['message' => 'sem fixture para '.$tipo], 404);
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
    // Fixture
    // ═════════════════════════════════════════════════════════════════════════════════════════

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
            'nome' => 'Empresa Lote '.$company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }

    /**
     * Uma oferta com a conta financeira FOLGADA e as fotos APROVADAS — ou seja, **apta** — a
     * menos que o chamador peça outra coisa.
     *
     * ⚠️⚠️ **O DUBLÊ DE `DadosEfetivosService` É POR PRODUTO**, e isso é conserto de um falso
     * negativo MEDIDO na Fase 186: um conjunto único de custos fazia o ÚLTIMO cenário montado
     * sobrescrever os anteriores, e a oferta "com bloqueio" era avaliada com a conta da oferta
     * limpa — **virava apta**. Num teste de LOTE, que monta três cenários e só então avalia os
     * três, é exatamente o erro que mataria o gate em silêncio.
     *
     * @param  array{preco?: float, custo?: ?float, imposto?: float, frete?: ?float, fotos?: string, titulo?: string}  $o
     */
    private function oferta(Company $empresa, array $o = []): PubAprovacaoOferta
    {
        $this->sequencia++;

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => 'LOTE-'.$this->sequencia,
            'nome' => 'Poltrona do lote '.$this->sequencia,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => 1,
        ]);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id, 'categoria_id' => self::CATEGORIA]);
        $alvo = PubRascunhoAlvo::create([
            'rascunho_id' => $rascunho->id,
            'listing_type_id' => self::CLASSICO,
            'titulo' => $o['titulo'] ?? 'Poltrona Beny Decorativa Veludo Cinza '.$this->sequencia,
            'posicao' => 0,
        ]);

        $oferta = PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $empresa->id,
            'entrou_na_fila_em' => '2026-10-16 08:00:00',
        ]);

        $conjunto = PubAprovacaoConjunto::create([
            'rascunho_id' => $rascunho->id,
            'grupo_chave' => PubAprovacaoConjunto::GERAL,
            'grupo_hash' => ChaveCanonica::hash(PubAprovacaoConjunto::GERAL),
        ]);
        $estado = $o['fotos'] ?? PubAprovacaoImagem::APROVADA;
        for ($i = 0; $i < 2; $i++) {
            $this->imagem($conjunto, $estado, $i);
        }

        if ($this->efetivos === null) {
            $this->efetivos = new class(app(EstruturaPrecificacaoService::class)) extends DadosEfetivosService
            {
                /** @var array<int, array<string, mixed>> */
                public array $custosPorProduto = [];

                /** @var array<int, array<string, mixed>> */
                public array $fretesPorProduto = [];

                /** @var array<int, array<string, mixed>> */
                public array $precosPorProduto = [];

                public function custosDoProduto(PubProduto $produto): array
                {
                    return $this->custosPorProduto[(int) $produto->id] ?? ['custo' => null, 'origem' => null, 'imposto' => null, 'por_variante' => []];
                }

                public function fretesDoProduto(PubProduto $produto): array
                {
                    return $this->fretesPorProduto[(int) $produto->id] ?? ['por_tipo' => [], 'por_variante' => []];
                }

                public function daProduto(PubProduto $produto): array
                {
                    return $this->precosPorProduto[(int) $produto->id] ?? ['precos' => []];
                }
            };
            $this->app->instance(DadosEfetivosService::class, $this->efetivos);
        }

        // ⚠️ `array_key_exists`, NUNCA `??`: `['custo' => null]` é "a Precificação está sem custo",
        // que é dado, não ausência de pedido.
        $this->efetivos->custosPorProduto[(int) $produto->id] = [
            'custo' => array_key_exists('custo', $o) ? $o['custo'] : 20.0,
            'origem' => 'digitado',
            'imposto' => $o['imposto'] ?? 10.0,
            'por_variante' => [],
        ];
        $frete = array_key_exists('frete', $o) ? $o['frete'] : 10.0;
        $preco = $o['preco'] ?? 100.0;
        $this->efetivos->fretesPorProduto[(int) $produto->id] = [
            'por_tipo' => [
                self::CLASSICO => ['valor' => $frete, 'origem' => $frete === null ? null : 'tabela', 'oferta_id' => null],
                self::PREMIUM => ['valor' => $frete, 'origem' => $frete === null ? null : 'tabela', 'oferta_id' => null],
            ],
            'por_variante' => [],
        ];
        $this->efetivos->precosPorProduto[(int) $produto->id] = [
            'precos' => [self::CLASSICO => $preco, self::PREMIUM => $preco],
        ];

        return $oferta->fresh();
    }

    private function imagem(PubAprovacaoConjunto $c, string $estado, ?int $posicao): PubAprovacaoImagem
    {
        $crua = PubImagem::create(['rascunho_id' => $c->rascunho_id, 'caminho' => 'fotos/lote-'.PubImagem::count().'.jpg']);
        $img = PubAprovacaoImagem::create([
            'conjunto_id' => $c->id,
            'imagem_id' => $crua->id,
            'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
            'estado' => $estado,
            'posicao' => $posicao,
        ]);

        PubAprovacaoImagemAlerta::create([
            'aprovacao_imagem_id' => $img->id,
            'severidade' => PubAprovacaoImagemAlerta::OK,
            'texto' => 'Formato e cor compatíveis',
        ]);

        return $img;
    }

    private function composicaoReal(): ComposicaoFinanceiraService
    {
        return new ComposicaoFinanceiraService(
            $this->efetivos ?? app(DadosEfetivosService::class),
            app(ClienteMlPublicador::class),
        );
    }

    /**
     * ⚠️ O cenário monta o dublê de `DadosEfetivosService` e **só então** o serviço pode nascer —
     * o PHP avalia o receptor ANTES do argumento, e construir antes dava falso negativo
     * silencioso no plano 186-07.
     */
    private function servicoDoLote(): LoteDaFilaService
    {
        $composicao = $this->composicaoReal();
        $bloqueios = new BloqueiosDaOfertaService($composicao, app(AjustesDaOfertaService::class));

        return new LoteDaFilaService(
            $bloqueios,
            new LinhaDaOfertaService(
                $bloqueios,
                $composicao,
                app(GaleriaDaOfertaService::class),
                app(ProximoARevisarService::class),
            ),
            new AprovacaoDaOfertaService(
                app(AndamentoDaOfertaService::class),
                app(HistoricoDaOfertaService::class),
                $bloqueios,
                $composicao,
            ),
        );
    }

    /** O andamento é movido pela ÚNICA PORTA — este teste nunca escreve `andamento` direto. */
    private function levarAo(PubAprovacaoOferta $oferta, string $destino): PubAprovacaoOferta
    {
        $porta = app(AndamentoDaOfertaService::class);

        if ($destino === PubAprovacaoOferta::PREPARANDO) {
            return $oferta;
        }

        $oferta = $porta->mover($oferta, PubAprovacaoOferta::AGUARDANDO_APROVACAO, null);

        $caminho = match ($destino) {
            PubAprovacaoOferta::AGUARDANDO_APROVACAO => [],
            PubAprovacaoOferta::EM_REVISAO_ECF => [PubAprovacaoOferta::EM_REVISAO_ECF],
            PubAprovacaoOferta::AJUSTES_SOLICITADOS => [PubAprovacaoOferta::AJUSTES_SOLICITADOS],
            PubAprovacaoOferta::APROVADO => [PubAprovacaoOferta::APROVADO],
            PubAprovacaoOferta::PUBLICANDO => [PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO],
            PubAprovacaoOferta::PUBLICADO => [PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::PUBLICADO],
            PubAprovacaoOferta::FALHA => [PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::FALHA],
            default => [],
        };

        foreach ($caminho as $passo) {
            // ⚠️⚠️ AND-04: `publicado` EXIGE MLB confirmado (`podeSerPublicado()`), e a porta
            // recusa sem ele. Gravar o `ml_item_id` antes do passo não é atalho — é o que o
            // Mercado Livre faria na Fase 193, e sem isso o fixture nem chega ao estado.
            if ($passo === PubAprovacaoOferta::PUBLICADO) {
                $oferta->update(['ml_item_id' => 'MLB'.(44000000 + (int) $oferta->id)]);
                $oferta = $oferta->fresh();
            }

            $oferta = $porta->mover($oferta, $passo, null);
        }

        return $oferta->fresh();
    }

    private function versaoInertia(): string
    {
        return (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    }

    private function raiz(): string
    {
        return rtrim(str_replace('\\', '/', base_path()), '/');
    }

    /** O código PHP **sem comentário** — `token_get_all`, nunca regex. */
    private function semComentariosPhp(string $conteudo): string
    {
        $fora = '';
        foreach (token_get_all($conteudo) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $fora .= $t[1];

                continue;
            }
            $fora .= $t;
        }

        return $fora;
    }

    private function git(string $comando): string
    {
        $saida = shell_exec('cd '.escapeshellarg($this->raiz()).' && '.$comando.' 2>&1');

        return trim((string) $saida);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) O VEREDICTO — TODAS as selecionadas, e os DOIS caminhos do motivo
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o veredicto omitir as inaptas (o que o
     * usuário RECUSOU), ou a frase da apta perder o preço/resultado — que é o que torna a
     * conferência possível ANTES do clique, porque depois não há desfazer.
     *
     * @group phase187
     */
    public function test_o_veredicto_lista_TODAS_as_selecionadas_com_glifo_e_motivo_187_09(): void
    {
        [$company] = $this->conta();

        $apta = $this->levarAo($this->oferta($company), PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $comBloqueio = $this->levarAo($this->oferta($company, ['fotos' => PubAprovacaoImagem::PENDENTE]), PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $foraDoAoVivo = $this->levarAo($this->oferta($company), PubAprovacaoOferta::PUBLICADO);

        $linhas = $this->servicoDoLote()->veredicto([$apta, $comBloqueio, $foraDoAoVivo]);

        // ⚠️ **TRÊS entradas para TRÊS selecionadas.** Nenhuma omitida.
        $this->assertCount(3, $linhas, 'o veredicto escondeu alguma selecionada — foi explicitamente RECUSADO pelo usuário');
        $this->assertSame(
            [(int) $apta->id, (int) $comBloqueio->id, (int) $foraDoAoVivo->id],
            array_map(static fn (array $l): int => $l['id'], $linhas),
            'a ordem do veredicto não é a da entrada',
        );

        // A APTA: glifo ✓ e a frase do dinheiro, com os DOIS números.
        $this->assertTrue($linhas[0]['apta']);
        $this->assertSame(LoteDaFilaService::GLIFO_APTA, $linhas[0]['glifo']);
        $this->assertStringStartsWith(LoteDaFilaService::APTA_PREFIXO, $linhas[0]['motivo']);
        $this->assertStringContainsString(LoteDaFilaService::APTA_RESULTADO, $linhas[0]['motivo']);
        $this->assertStringContainsString('R$ ', $linhas[0]['motivo'], 'a frase da apta não tem dinheiro formatado');
        $this->assertSame(LoteDaFilaService::COR_APTA, $linhas[0]['motivo_cor']);

        // As DUAS inaptas: glifo × e o MOTIVO, nunca vazio.
        foreach ([1, 2] as $i) {
            $this->assertFalse($linhas[$i]['apta']);
            $this->assertSame(LoteDaFilaService::GLIFO_FORA, $linhas[$i]['glifo']);
            $this->assertStringStartsWith(LoteDaFilaService::FORA_PREFIXO, $linhas[$i]['motivo']);
            $this->assertNotSame(
                LoteDaFilaService::FORA_PREFIXO,
                $linhas[$i]['motivo'],
                'o motivo ficou só no prefixo — "Fica de fora: " sozinho é frase truncada na tela',
            );
            $this->assertSame(LoteDaFilaService::COR_FORA, $linhas[$i]['motivo_cor']);
        }

        // ⚠️ O nome e a versão de TODAS, nunca vazios: elemento vazio passa em render e
        // DESAPARECE na tela.
        foreach ($linhas as $linha) {
            $this->assertNotSame('', $linha['nome']);
            $this->assertSame(LoteDaFilaService::VERSAO_PREFIXO.$linha['versao'], $linha['versao_rotulo']);
            $this->assertGreaterThanOrEqual(1, $linha['versao']);
        }
    }

    /**
     * ⚠️⚠️ **O que precisaria estar errado para falhar:** tratar "qualquer inapta" igual. São
     * DOIS caminhos de motivo e eles não são variação cosmética:
     *   · inapta **AO VIVO** → a lista das ABREVIAÇÕES dos bloqueios dela, por ", ";
     *   · inapta **FORA do ao vivo** → o RÓTULO DO ANDAMENTO em minúsculas.
     * Uma oferta `publicado` não tem bloqueio nenhum: um serviço que só soubesse o primeiro
     * caminho mostraria `×` sem motivo exatamente nela. **A isca são os dois lados no MESMO
     * caso**, e as abreviações são LIDAS de `VocabularioDoPortao::ABREVIACOES`.
     *
     * @group phase187
     */
    public function test_o_motivo_tem_DOIS_caminhos_abreviacoes_e_andamento_minusculo_187_09(): void
    {
        [$company] = $this->conta();

        // AO VIVO com foto pendente: o bloqueio é `fotos_a_conferir`.
        $aoVivo = $this->levarAo($this->oferta($company, ['fotos' => PubAprovacaoImagem::PENDENTE]), PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        // FORA do ao vivo: `publicado`.
        $fora = $this->levarAo($this->oferta($company), PubAprovacaoOferta::PUBLICADO);

        $linhas = $this->servicoDoLote()->veredicto([$aoVivo, $fora]);

        // ─── Caminho 1: as ABREVIAÇÕES, LIDAS do vocabulário ───
        $esperada = VocabularioDoPortao::ABREVIACOES[VocabularioDoPortao::FOTOS_A_CONFERIR];
        $this->assertSame(
            LoteDaFilaService::FORA_PREFIXO.$esperada,
            $linhas[0]['motivo'],
            'o motivo da inapta AO VIVO não é a abreviação lida de VocabularioDoPortao::ABREVIACOES',
        );

        // ⚠️ ISCA DO CAMINHO 1: a abreviação NÃO é a chave crua. Jargão não vai para a tela.
        $this->assertStringNotContainsString(
            VocabularioDoPortao::FOTOS_A_CONFERIR,
            $linhas[0]['motivo'],
            'a chave de dado `fotos_a_conferir` foi para a tela — jargão sem explicação é vetado',
        );

        // ─── Caminho 2: o RÓTULO DO ANDAMENTO em minúsculas, LIDO do modelo ───
        $rotulo = PubAprovacaoOferta::ANDAMENTOS[PubAprovacaoOferta::PUBLICADO];
        $this->assertSame(
            LoteDaFilaService::FORA_PREFIXO.mb_strtolower($rotulo, 'UTF-8'),
            $linhas[1]['motivo'],
            'o motivo da inapta FORA do ao vivo não é o rótulo do andamento em minúsculas',
        );

        // ⚠️ ISCA DO CAMINHO 2: a minusculização é `mb_`, não `strtolower` de byte único — o
        // rótulo "Aguardando aprovação" tem `ç` e `ã`. O caso usa um andamento ACENTUADO para
        // provar, e compara com o resultado `mb_`.
        $comAcento = $this->levarAo($this->oferta($company), PubAprovacaoOferta::PUBLICANDO);
        $linhaAcento = $this->servicoDoLote()->veredicto([$comAcento])[0];
        $this->assertStringContainsString(
            'publicando',
            $linhaAcento['motivo'],
            'o rótulo de `publicando` não desceu para minúsculas',
        );

        // ⚠️ E as DUAS formas são DIFERENTES entre si: se um refactor unificasse os caminhos,
        // os dois motivos ficariam iguais e este assert acusaria.
        $this->assertNotSame($linhas[0]['motivo'], $linhas[1]['motivo'], 'os dois caminhos do motivo colapsaram num só');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** os literais do modal e da barra serem
     * parafraseados, ou o PLURAL sair errado. **Medidos** em `Aprovacao.dc.html`: `:1282` (barra),
     * `:1425` (modal) e `:885` (a nota). ⚠️ **Zero sai no SINGULAR** ("Aprovar 0 oferta"), porque
     * o protótipo escreve `> 1 ? ' ofertas' : ' oferta'` — não foi arredondado para o que soa
     * melhor.
     *
     * @group phase187
     */
    public function test_os_literais_do_painel_batem_com_o_prototipo_e_o_plural_187_09(): void
    {
        // As funções PURAS, sem banco.
        $this->assertSame('1 selecionada', LoteDaFilaService::rotuloDaSelecao(1));
        $this->assertSame('2 selecionadas', LoteDaFilaService::rotuloDaSelecao(2));
        $this->assertSame('0 selecionada', LoteDaFilaService::rotuloDaSelecao(0), '`> 1` é plural: zero é singular, medido em `:1282`');

        $this->assertSame('Aprovar 3 para publicação', LoteDaFilaService::rotuloDeAprovar(3));
        $this->assertSame('Aprovar 0 para publicação', LoteDaFilaService::rotuloDeAprovar(0));

        $this->assertSame('2 de 3 ofertas selecionadas podem ser aprovadas', LoteDaFilaService::subDoModal(2, 3));

        $this->assertSame('Aprovar 2 ofertas', LoteDaFilaService::rotuloDoBotao(2));
        $this->assertSame('Aprovar 1 oferta', LoteDaFilaService::rotuloDoBotao(1));
        $this->assertSame('Aprovar 0 oferta', LoteDaFilaService::rotuloDoBotao(0), 'zero no SINGULAR — é o literal de `:1425`');

        // ⚠️⚠️ "Aprovar para publicação" **INALTERADO** (decisão 3 do usuário): o rótulo da barra
        // é esse e não ganha segundo passo.
        $this->assertStringContainsString('para publicação', LoteDaFilaService::rotuloDeAprovar(1));
        $this->assertSame('Aprovar para publicação em lote', LoteDaFilaService::TITULO_DO_MODAL);

        // ⚠️ A nota, BYTE A BYTE, com o ✓ que é U+2713.
        $this->assertSame(
            'Somente as ofertas marcadas com ✓ serão aprovadas, cada uma na versão indicada.',
            LoteDaFilaService::NOTA_DO_MODAL,
        );
        $this->assertSame("\u{2713}", LoteDaFilaService::GLIFO_APTA, 'o ✓ não é U+2713');
        $this->assertSame("\u{00D7}", LoteDaFilaService::GLIFO_FORA, 'o × não é U+00D7 (é a letra x?)');

        // ⚠️ ISCA: a nota do PROTÓTIPO é a mesma string. Se o protótipo não estiver na árvore
        // (ele NÃO é versionado), o caso degrada para asserção explícita, nunca para silêncio.
        $prototipo = $this->raiz().'/design_handoff_publicador/Aprovacao.dc.html';
        if (is_file($prototipo)) {
            $this->assertStringContainsString(
                LoteDaFilaService::NOTA_DO_MODAL,
                (string) file_get_contents($prototipo),
                'a nota divergiu do protótipo (`:885`)',
            );
        } else {
            $this->assertFalse(is_file($prototipo), 'o protótipo não está na árvore (não é versionado) — a conferência byte a byte ficou acima');
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) A ESCRITA — só as aptas, na versão indicada, e as inaptas INTOCADAS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **O que precisaria estar errado para falhar:** aprovar as inaptas (T-187-92), ou
     * derrubar o lote inteiro por causa de uma. As inaptas são conferidas **campo a campo**
     * (andamento, versão, `tocado`, `aprovado_por`) — "não mudou o andamento" é mais fraco que
     * "ficou exatamente como estava".
     *
     * @group phase187
     */
    public function test_o_lote_aprova_SO_as_aptas_e_as_inaptas_ficam_INTOCADAS_187_09(): void
    {
        [$company, , $admin] = $this->conta();

        $apta1 = $this->levarAo($this->oferta($company), PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $apta2 = $this->levarAo($this->oferta($company), PubAprovacaoOferta::EM_REVISAO_ECF);
        $inapta = $this->levarAo($this->oferta($company, ['fotos' => PubAprovacaoImagem::PENDENTE]), PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $fora = $this->levarAo($this->oferta($company), PubAprovacaoOferta::PUBLICADO);

        $antes = [
            (int) $inapta->id => [$inapta->andamento, (int) $inapta->versao, (bool) $inapta->tocado, $inapta->aprovado_por],
            (int) $fora->id => [$fora->andamento, (int) $fora->versao, (bool) $fora->tocado, $fora->aprovado_por],
        ];

        $resumo = $this->servicoDoLote()->aprovar([$apta1, $apta2, $inapta, $fora], $admin);

        $this->assertSame(4, $resumo['selecionadas']);
        $this->assertSame(2, $resumo['aprovadas'], 'o lote aprovou um número diferente de 2 aptas');

        // As DUAS aptas moveram.
        foreach ([$apta1, $apta2] as $oferta) {
            $fresca = $oferta->fresh();
            $this->assertSame(PubAprovacaoOferta::APROVADO, $fresca->andamento);
            $this->assertSame((int) $admin->id, (int) $fresca->aprovado_por);
            $this->assertTrue((bool) $fresca->tocado);
            $this->assertSame((int) $fresca->versao, (int) $fresca->versao_aprovada, 'aprovou numa versão diferente da da oferta');
        }

        // ⚠️ As inaptas, CAMPO A CAMPO como estavam.
        foreach ([$inapta, $fora] as $oferta) {
            $fresca = $oferta->fresh();
            $this->assertSame(
                $antes[(int) $oferta->id],
                [$fresca->andamento, (int) $fresca->versao, (bool) $fresca->tocado, $fresca->aprovado_por],
                'T-187-92: uma oferta inapta foi tocada pelo lote',
            );
        }
    }

    /**
     * ⚠️⚠️ **O que precisaria estar errado para falhar — e esta é a MEDIÇÃO que o plano pediu.**
     *
     * `AprovacaoDaOfertaService:258` faz `$versao = (int) $oferta->versao` e passa isso para
     * `aprovar()`: a versão **do próprio modelo que ele acabou de receber**. No caminho do POST
     * o modelo é recém-carregado, então esperado e atual são **sempre iguais** e
     * `COL-04-VERSAO-DIVERGENTE` **nunca dispararia**. ➡️ A versão que importa é a que a pessoa
     * **VIU NO MODAL**, e ela é conferida em `LoteDaFilaService::aprovar()`.
     *
     * ⚠️ E a recusa é **SÓ DELA**: as outras passam. Um `catch` no laço inteiro, ou uma
     * transação por fora, derrubaria o lote.
     *
     * @group phase187
     */
    public function test_a_versao_divergente_recusa_SO_ELA_e_as_outras_passam_187_09(): void
    {
        [$company, , $admin] = $this->conta();

        $mexida = $this->levarAo($this->oferta($company), PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $intacta = $this->levarAo($this->oferta($company), PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $versaoDoVeredicto = max(1, (int) $mexida->versao);

        // Alguém mexeu na oferta DEPOIS do veredicto: a versão subiu.
        $mexida->update(['versao' => $versaoDoVeredicto + 1]);
        $mexida = $mexida->fresh();

        $resumo = $this->servicoDoLote()->aprovar(
            [$mexida, $intacta],
            $admin,
            [(int) $mexida->id => $versaoDoVeredicto, (int) $intacta->id => max(1, (int) $intacta->versao)],
        );

        $this->assertSame(1, $resumo['aprovadas'], 'o lote aprovou a oferta cuja versão mudou, ou derrubou a outra');
        $this->assertSame([(int) $mexida->id], $resumo['recusadas_por_versao']);

        // A mexida NÃO foi aprovada; a intacta FOI.
        $this->assertNotSame(PubAprovacaoOferta::APROVADO, $mexida->fresh()->andamento, 'COL-04: a oferta com versão divergente foi aprovada');
        $this->assertSame(PubAprovacaoOferta::APROVADO, $intacta->fresh()->andamento, 'a oferta intacta foi derrubada pela vizinha');

        // ⚠️ A chave da regra é a da Fase 186, LIDA, nunca uma string nova.
        $daMexida = array_values(array_filter($resumo['veredicto'], static fn (array $v): bool => $v['oferta_id'] === (int) $mexida->id));
        $this->assertSame(AprovacaoDaOfertaService::REGRA_VERSAO_DIVERGENTE, $daMexida[0]['motivo']);

        // ⚠️⚠️ ISCA NA OUTRA DIREÇÃO: **sem** o mapa de versões as DUAS passam. Sem esta isca,
        // uma implementação que recusasse TUDO passaria no caso de cima.
        $a = $this->levarAo($this->oferta($company), PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $b = $this->levarAo($this->oferta($company), PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $semMapa = $this->servicoDoLote()->aprovar([$a, $b], $admin);
        $this->assertSame(2, $semMapa['aprovadas'], 'sem afirmação de versão não há o que divergir — as duas tinham de passar');
        $this->assertSame([], $semMapa['recusadas_por_versao']);
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** lote vazio ou só com inaptas responder
     * "pronto". **Sucesso falso é pior que erro**: com aprovar-já-publica e sem desfazer, quem
     * lê "aprovadas: 2" acredita que duas ofertas foram para o ML.
     *
     * @group phase187
     */
    public function test_lote_vazio_e_lote_so_com_inaptas_gravam_NADA_e_dizem_ZERO_187_09(): void
    {
        [$company, , $admin] = $this->conta();

        // Lote VAZIO.
        $vazio = $this->servicoDoLote()->aprovar([], $admin);
        $this->assertSame(0, $vazio['selecionadas']);
        $this->assertSame(0, $vazio['aprovadas']);
        $this->assertSame([], $vazio['veredicto']);

        // Lote só com INAPTAS.
        $inapta = $this->levarAo($this->oferta($company, ['fotos' => PubAprovacaoImagem::PENDENTE]), PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $fora = $this->levarAo($this->oferta($company), PubAprovacaoOferta::APROVADO);

        $antes = [$inapta->fresh()->andamento, $fora->fresh()->andamento];

        $soInaptas = $this->servicoDoLote()->aprovar([$inapta, $fora], $admin);
        $this->assertSame(2, $soInaptas['selecionadas']);
        $this->assertSame(0, $soInaptas['aprovadas'], 'lote só com inaptas devolveu sucesso falso');

        $this->assertSame($antes, [$inapta->fresh()->andamento, $fora->fresh()->andamento], 'o lote só com inaptas gravou algo');

        // ⚠️ ISCA: o MESMO serviço aprova quando há apta. Sem isto, um serviço que nunca
        // aprovasse nada passaria neste caso.
        $apta = $this->levarAo($this->oferta($company), PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $this->assertSame(1, $this->servicoDoLote()->aprovar([$apta], $admin)['aprovadas'], 'ISCA CEGA: o serviço não aprova nem a apta');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) A ROTA — a conta, o middleware, a trava de token e a FRONTEIRA com o ML
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **O que precisaria estar errado para falhar:** oferta de OUTRA conta no corpo do POST
     * ser aprovada (T-187-91/T-187-96). É a elevação de privilégio mais direta desta fase: a
     * aprovação **publica** e **não tem desfazer**. ⚠️ O teste prova que **NADA** da oferta
     * alheia foi gravado, campo a campo — não só que "a resposta não falou dela".
     *
     * @group phase187
     */
    public function test_oferta_de_OUTRA_conta_no_POST_nao_e_aprovada_nem_gravada_187_09(): void
    {
        [$minha, , $admin] = $this->conta();
        [$alheia] = $this->conta();

        $daMinha = $this->levarAo($this->oferta($minha), PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $daAlheia = $this->levarAo($this->oferta($alheia), PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $antes = [$daAlheia->andamento, (int) $daAlheia->versao, (bool) $daAlheia->tocado, $daAlheia->aprovado_por, $daAlheia->aprovado_em];

        $this->actingAs($admin)
            ->post(route('mlb.anuncios.meus.aprovacao.lote', $minha), ['ofertas' => [(int) $daMinha->id, (int) $daAlheia->id]])
            ->assertRedirect();

        // A minha aprovou.
        $this->assertSame(PubAprovacaoOferta::APROVADO, $daMinha->fresh()->andamento);

        // ⚠️ A alheia ficou EXATAMENTE como estava.
        $fresca = $daAlheia->fresh();
        $this->assertSame(
            $antes,
            [$fresca->andamento, (int) $fresca->versao, (bool) $fresca->tocado, $fresca->aprovado_por, $fresca->aprovado_em],
            'T-187-96: a oferta de outra conta foi tocada pelo POST do lote',
        );

        // E a resposta conta só uma.
        $this->assertSame(1, session('lote_aprovado')['aprovadas']);
        $this->assertSame(1, session('lote_aprovado')['selecionadas'], 'a oferta alheia entrou no lote (devia nem chegar lá)');
        $this->assertSame(2, session('lote_aprovado')['pedidas']);
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** a rota ficar FORA do grupo
     * `['auth','verified','role:admin']`, ganhar middleware novo, ou esquecer a trava do
     * `ml_token` (T-187-91). As duas pontas, com isca de que o admin PASSA pela mesma rota.
     *
     * @group phase187
     */
    public function test_a_rota_do_lote_exige_admin_e_conta_conectada_187_09(): void
    {
        [$company, , $admin] = $this->conta();
        $apta = $this->levarAo($this->oferta($company), PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        // Quem não é admin não passa.
        $consultor = User::factory()->create(['role' => 'consultor']);
        $this->actingAs($consultor)
            ->post(route('mlb.anuncios.meus.aprovacao.lote', $company), ['ofertas' => [(int) $apta->id]])
            ->assertForbidden();

        // ⚠️ E nada foi gravado pelo 403.
        $this->assertNotSame(PubAprovacaoOferta::APROVADO, $apta->fresh()->andamento);

        // Empresa SEM conta ML: 404, com a MESMA mensagem das outras actions do módulo.
        $semToken = Company::factory()->create();
        $resposta = $this->actingAs($admin)
            ->post(route('mlb.anuncios.meus.aprovacao.lote', $semToken), ['ofertas' => [(int) $apta->id]]);
        $resposta->assertNotFound();
        $this->assertStringContainsString('Empresa sem conta ML conectada.', $resposta->exception?->getMessage() ?? '');

        // ⚠️ ISCA — a mensagem é BYTE A BYTE a da rota GET da fila. Divergir aqui faria a tela
        // dizer outra coisa para o mesmo fato.
        $daFila = $this->actingAs($admin)->get(route('mlb.anuncios.meus.aprovacao', $semToken));
        $daFila->assertNotFound();
        $this->assertSame($daFila->exception?->getMessage(), $resposta->exception?->getMessage(), 'a trava do lote divergiu da trava da fila');

        // ⚠️ ISCA DO ADMIN: ele PASSA pela MESMA rota, na empresa conectada. Sem isto, uma rota
        // inexistente (404 para todos) faria as negativas acima passar por ausência.
        $this->actingAs($admin)
            ->post(route('mlb.anuncios.meus.aprovacao.lote', $company), ['ofertas' => [(int) $apta->id]])
            ->assertRedirect();
        $this->assertSame(PubAprovacaoOferta::APROVADO, $apta->fresh()->andamento);

        // ⚠️ E `"3abc"` é 422, nunca convertido em `3` em silêncio; `ofertas` vazio também.
        $this->actingAs($admin)
            ->post(route('mlb.anuncios.meus.aprovacao.lote', $company), ['ofertas' => ['3abc']])
            ->assertSessionHasErrors('ofertas.0');
        $this->actingAs($admin)
            ->post(route('mlb.anuncios.meus.aprovacao.lote', $company), ['ofertas' => []])
            ->assertSessionHasErrors('ofertas');
    }

    /**
     * ⚠️⚠️ **O que precisaria estar errado para falhar:** uma requisição de PUBLICAÇÃO sair desta
     * fase. **Aprovar ENFILEIRA** — move o andamento e congela a composição, no nosso banco. O
     * gancho `aoAprovar`, que fala com o Mercado Livre, está **VAZIO** e é da **Fase 193**.
     *
     * ⚠️ **A prova é por AUSÊNCIA DE REQUISIÇÃO**, não por leitura de código: `Http::fake()` com
     * `preventStrayRequests()` registra tudo, e a asserção varre o histórico. ⚠️ A tarifa
     * (`/sites/MLB/listing_prices`) é **esperada** — ela é leitura pública de preço e é o que faz
     * a composição existir; o que não pode existir é POST/PUT em `/items`, `/items/{id}` ou
     * qualquer caminho de escrita.
     *
     * ⚠️ **E O D-11 NÃO É ESTICADO NEM AFROUXADO:** `estrutura-meus-anuncios.test.js` prova que
     * **a LISTA** de Publicações não escreve no ML, e isso continua verdade (este plano não toca
     * os arquivos da lista). **A FILA escreve** — no banco.
     *
     * @group phase187
     */
    public function test_aprovar_em_lote_NAO_faz_requisicao_de_publicacao_ao_ML_187_09(): void
    {
        [$company, , $admin] = $this->conta();

        $apta1 = $this->levarAo($this->oferta($company), PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $apta2 = $this->levarAo($this->oferta($company), PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $this->actingAs($admin)
            ->post(route('mlb.anuncios.meus.aprovacao.lote', $company), ['ofertas' => [(int) $apta1->id, (int) $apta2->id]])
            ->assertRedirect();

        $this->assertSame(PubAprovacaoOferta::APROVADO, $apta1->fresh()->andamento, 'o lote nem aprovou — o gate mediria ZERO casos');

        $escritas = [];
        foreach (Http::recorded() as [$requisicao]) {
            $metodo = strtoupper((string) $requisicao->method());
            $caminho = (string) parse_url($requisicao->url(), PHP_URL_PATH);

            // ⚠️ Flag DENTRO do laço.
            $ehEscrita = in_array($metodo, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
            if ($ehEscrita) {
                $escritas[] = $metodo.' '.$caminho;
            }
        }

        $this->assertSame(
            [],
            $escritas,
            'saiu requisição de ESCRITA ao Mercado Livre nesta fase. Aprovar ENFILEIRA; publicar é a Fase 193.',
        );

        // ⚠️⚠️ ISCA NAS DUAS DIREÇÕES: o histórico **não está vazio** (a tarifa foi consultada),
        // então a negativa acima não passou por "nada foi registrado" — que é o jeito clássico de
        // um gate de ausência virar decoração.
        $lidas = [];
        foreach (Http::recorded() as [$requisicao]) {
            $lidas[] = (string) parse_url($requisicao->url(), PHP_URL_PATH);
        }
        $this->assertNotSame([], $lidas, 'ISCA CEGA: o espião de HTTP não registrou NADA — a negativa acima não prova nada');
        $this->assertContains('/sites/MLB/listing_prices', $lidas, 'a tarifa não foi consultada — o espião está medindo outra coisa');
    }

    /**
     * ⚠️⚠️ **T-187-96 / COL-03 — `?sel=` COM ID DE OUTRA CONTA É DESCARTADO, E ESTE CASO NASCEU
     * DE UMA MUTAÇÃO SOBREVIVENTE.**
     *
     * **MEDIDO na campanha de mutação desta sessão:** trocar
     * `$fila->paraAConta(...)->whereIn('id', $pedidos)` por `PubAprovacaoOferta::query()
     * ->whereIn('id', $pedidos)` **dentro de `selecaoSaneada()`** (o caminho do GET)
     * **SOBREVIVEU** ao gate `lote-rota`. A razão é exata: `lote-rota` exercita o **POST**, e a
     * conferência que eu tinha para o GET era um **proxy de FONTE** (um `assert.match` sobre o
     * texto do controller) que casava a ocorrência do POST e ficava verde.
     *
     * ➡️ Proxy de fonte não prova comportamento. Este caso é a prova de COMPORTAMENTO: a oferta
     * de outra conta **não entra em `selecionados`**, **não acende a linha como marcada** e
     * **não entra no `lote`** que a tela recebe. Sem isso, a tela pintaria como selecionada uma
     * oferta que o POST depois recusaria — duas verdades sobre a mesma seleção — e o painel do
     * lote exibiria o NOME de uma oferta de outra empresa (vazamento de informação).
     *
     * ⚠️ É a classe de defeito mais caras desta fase: a aprovação **publica** e **não tem
     * desfazer**.
     *
     * @group phase187
     */
    public function test_sel_da_URL_com_id_de_OUTRA_conta_e_descartado_187_09(): void
    {
        [$minha, , $admin] = $this->conta();
        [$alheia] = $this->conta();

        $daMinha = $this->levarAo($this->oferta($minha), PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        $daAlheia = $this->levarAo($this->oferta($alheia), PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        $props = $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
            ->get(route('mlb.anuncios.meus.aprovacao', $minha).'?sel='.$daMinha->id.','.$daAlheia->id)
            ->assertOk()
            ->json('props');

        // ⚠️ SÓ a minha sobrevive ao saneamento.
        $this->assertSame([(int) $daMinha->id], $props['selecionados'], 'T-187-96: id de outra conta sobreviveu ao `?sel=`');

        // ⚠️ E o painel do lote conta UMA, não duas — nem o NOME da alheia aparece.
        $this->assertNotNull($props['lote'], 'o painel do lote não foi montado para a seleção legítima');
        $this->assertSame(1, $props['lote']['selecionadas'], 'a oferta alheia entrou na contagem do lote');
        $this->assertCount(1, $props['lote']['linhas']);
        $this->assertSame((int) $daMinha->id, $props['lote']['linhas'][0]['id']);

        $payload = json_encode($props, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(
            (string) $daAlheia->alvo->titulo,
            (string) $payload,
            'T-187-96: o título da oferta de OUTRA conta vazou para as props da tela',
        );

        // ⚠️⚠️ ISCA NA OUTRA DIREÇÃO: a seleção LEGÍTIMA passa. Sem ela, um saneamento que
        // descartasse TUDO passaria nas asserções acima — e a seleção em lote nunca funcionaria.
        $this->assertNotSame([], $props['selecionados'], 'ISCA CEGA: o saneamento descartou a oferta da PRÓPRIA conta');

        // ⚠️ E `?sel=` com lixo não vira 500 nem id 0: `(int) '3abc'` é 3 e `(int) ''` é 0, e os
        // dois entrariam num `whereIn` se o saneamento usasse cast em vez de `ctype_digit`.
        $comLixo = $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
            ->get(route('mlb.anuncios.meus.aprovacao', $minha).'?sel=abc,,0,-4,'.$daMinha->id.'x,'.$daMinha->id)
            ->assertOk()
            ->json('props');
        $this->assertSame([(int) $daMinha->id], $comLixo['selecionados'], 'o saneamento aceitou lixo da querystring');

        // ⚠️ E sem `?sel=` o painel do lote é `null` — nenhuma chamada extra de bloqueios
        // acontece no caso comum (o custo é recortado à SELEÇÃO, nunca à fila).
        $semSel = $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
            ->get(route('mlb.anuncios.meus.aprovacao', $minha))
            ->assertOk()
            ->json('props');
        $this->assertSame([], $semSel['selecionados']);
        $this->assertNull($semSel['lote'], 'sem seleção o painel do lote tem de ser `null`');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (4) TRANSVERSAIS — território, ausências e bijeção
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **TERRITÓRIO POR ESCOPO DO ASSUNTO, NUNCA POR RANGE ABERTO.** Nesta árvore as TRÊS
     * sessões commitam com o MESMO `user.name`, então o AUTOR **não discrimina NADA**. O
     * discriminador é `(187-09)` no assunto, e há isca nas duas direções.
     *
     * ⚠️ **A CONTAGEM DESTE CASO NÃO DEPENDE DE QUANTOS COMMITS EXISTEM NO ESCOPO:** nenhuma
     * asserção mora dentro de condicional, o `test -e` dos caminhos proibidos é UMA asserção
     * coletando fantasmas, e a prova de que o range não está vazio é asserção COLETADA, nunca
     * `return` antecipado. (Era a NONA ocorrência da família *"contagem que depende de quantos
     * arquivos/commits existem"* nesta milestone, consertada na raiz no plano 187-07.)
     *
     * ⚠️ **AS AUSÊNCIAS SÃO LIDAS DO CÓDIGO SEM COMENTÁRIO** (`token_get_all`): palavra no
     * docblock que EXPLICA *"não uso isso"* casa com o gate que conta isso, e a wave 4 teve TRÊS
     * mutações abortando por esse motivo. ⚠️ **HÁ ISCA DO LEITOR:** a prosa deste arquivo cita
     * `localStorage`, "lucro líquido" e "confirmar publicação" **de propósito**.
     *
     * ⚠️ **O gate de "lucro líquido" é RECORTADO À LISTA CONSTANTE dos arquivos deste plano:**
     * `lucro_liquido` é campo LEGÍTIMO do Portal, e varrer o `app/` inteiro reprovaria trabalho de
     * outra sessão — foi a 5ª ocorrência de *"gate mais largo que o invariante"* na Fase 186, e
     * ela **barrou um deploy**.
     *
     * @group phase187
     */
    public function test_territorio_e_ausencias_do_plano_187_09(): void
    {
        $raiz = $this->raiz();

        // ─── (a) O range do escopo não está VAZIO — asserção COLETADA ───
        $assuntos = array_values(array_filter(
            explode("\n", $this->git('git log --format=%s '.self::PARTIDA.'..HEAD')),
            static fn (string $l): bool => trim($l) !== '',
        ));
        $doEscopo = array_values(array_filter($assuntos, static fn (string $s): bool => preg_match(self::ESCOPO, $s) === 1));

        // ⚠️ Nunca `return` antecipado quando o range está vazio: isso faria TODAS as asserções
        // seguintes desaparecerem e o caso passar medindo nada. A informação é COLETADA.
        $this->assertIsArray($doEscopo);

        // ⚠️ ISCA NAS DUAS DIREÇÕES: o escopo NÃO casa a wave vizinha nem uma quick task.
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(187-08): a linha da oferta'), 'o escopo casou a wave 08');
        $this->assertSame(0, preg_match(self::ESCOPO, 'fix(261016-mwe): outra coisa'), 'o escopo casou uma quick task');
        $this->assertSame(1, preg_match(self::ESCOPO, 'feat(187-09): o lote'), 'ISCA CEGA: o escopo não casa nem o próprio assunto');

        // ─── (b) OS CAMINHOS PROIBIDOS — `test -e` em UMA asserção, coletando fantasmas ───
        //
        // ⚠️ UMA asserção para a lista inteira, e não uma por caminho: uma por caminho faria a
        // contagem de asserções deste caso crescer junto com a lista, que é a fragilidade por
        // construção já consertada na raiz.
        $proibidos = [
            'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
            'app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php',
            'app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
            'app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php',
            'app/Services/Publicador/Aprovacao/LinhaFinanceira.php',
            // ⚠️ CAMINHO CORRIGIDO: ele NÃO está em `app/Services/Publicador/`, e isto foi
            // MEDIDO — o `test -e` deste próprio caso o acusou como FANTASMA, que é
            // exatamente o motivo de ele existir. A v26.0 teve SEIS caminhos fantasma
            // "provando" território intocado medindo NADA.
            'app/Services/Mlb/Publicacao/MlPublicacaoService.php',
            'app/Services/Creative',
            'app/Support/Publicador',
            'app/Mcp',
            'resources/js/Layouts/AppLayout.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/SeloDeAndamentoInterno.jsx',
            'tests/js/publicador-header-zindex-ordem.test.js',
            'tests/js/estrutura-meus-anuncios.test.js',
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json',
        ];

        // ⚠️ `test -e` ANTES de acreditar em `numstat` vazio: caminho FANTASMA dá diff vazio e o
        // gate fica verde medindo NADA. A v26.0 teve SEIS caminhos fantasma.
        $fantasmas = array_values(array_filter($proibidos, static fn (string $c): bool => ! file_exists($raiz.'/'.$c)));
        $this->assertSame([], $fantasmas, 'caminho(s) proibido(s) inexistente(s) — o gate mediria NADA neles');

        // ─── (c) Os commits DO ESCOPO não tocaram caminho proibido nenhum ───
        $tocados = [];
        foreach ($doEscopo as $assunto) {
            $hash = $this->git('git log --format=%H -1 --grep='.escapeshellarg(preg_quote($assunto, '/')).' --fixed-strings '.self::PARTIDA.'..HEAD');
            // ⚠️ Flag DENTRO do laço.
            $temHash = $hash !== '' && preg_match('/^[0-9a-f]{40}$/', explode("\n", $hash)[0]) === 1;
            if (! $temHash) {
                continue;
            }
            foreach (explode("\n", $this->git('git show --numstat --format= '.explode("\n", $hash)[0])) as $linha) {
                $partes = preg_split('/\t/', trim($linha));
                $arquivo = is_array($partes) && count($partes) === 3 ? $partes[2] : null;
                if ($arquivo !== null && in_array($arquivo, $proibidos, true)) {
                    $tocados[] = $arquivo;
                }
            }
        }
        $this->assertSame([], array_values(array_unique($tocados)), 'um commit do escopo (187-09) tocou caminho PROIBIDO');

        // ─── (d) AS AUSÊNCIAS, sobre a LISTA CONSTANTE dos arquivos deste plano ───
        $meus = [
            'app/Services/Publicador/Aprovacao/Fila/LoteDaFilaService.php',
            'resources/js/Components/Mlb/Publicador/aprovacao/BarraDeSelecao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/CascaDeModal.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/ModalDeLote.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/ToastDaFila.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/RodapeDaFila.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/VazioDaFila.jsx',
        ];
        $faltando = array_values(array_filter($meus, static fn (string $c): bool => ! file_exists($raiz.'/'.$c)));
        $this->assertSame([], $faltando, 'arquivo deste plano não existe — as ausências abaixo mediriam NADA');

        $corpos = '';
        foreach ($meus as $caminho) {
            $bruto = (string) file_get_contents($raiz.'/'.$caminho);
            // ⚠️ PHP sai por `token_get_all`; JSX sai por remoção de bloco e de `//`.
            $corpos .= str_ends_with($caminho, '.php')
                ? $this->semComentariosPhp($bruto)
                : preg_replace('#//.*$#m', '', (string) preg_replace('#/\*[\s\S]*?\*/#', '', $bruto));
            $corpos .= "\n";
        }

        // ⚠️⚠️ T-187-93 — O GATE DE AUSÊNCIA DO SEGUNDO PASSO, ancorado no SUJEITO e com os
        // acentos. **Aprovar JÁ PUBLICA** (decisão 3). ⚠️ `aprovad` NÃO casa "aprovação", e o par
        // solto `(aguardand|pendent) × (autoriza|aprovad)` casaria 10 ocorrências LEGÍTIMAS da
        // área ("Aguardando aprovação" é vocabulário correto).
        $segundoPasso = '/(confirmar\s+publica|confirme\s+a\s+publica|desfazer\s+(a\s+)?aprova|cancelar\s+(a\s+)?aprova|segundo\s+passo|revisar\s+e\s+aprovar)/iu';
        $this->assertSame(0, preg_match($segundoPasso, $corpos), 'T-187-93: nasceu um segundo passo na aprovação — a decisão 3 do usuário é que aprovar JÁ PUBLICA');

        // ⚠️ ISCA POR FORMA, nas DUAS direções: a sonda PEGA um "Confirmar publicação" sintético
        // e NÃO pega "Aprovar para publicação".
        $this->assertSame(1, preg_match($segundoPasso, 'Confirmar publicação'), 'ISCA CEGA: a sonda do segundo passo não pega nem a forma sintética');
        $this->assertSame(1, preg_match($segundoPasso, 'Cancelar aprovação'), 'ISCA CEGA: a sonda não pega "Cancelar aprovação"');
        $this->assertSame(0, preg_match($segundoPasso, 'Aprovar para publicação'), 'a sonda larga demais: ela pega o rótulo CERTO');
        $this->assertSame(0, preg_match($segundoPasso, 'Aguardando aprovação'), 'a sonda larga demais: ela pega o vocabulário correto da área');

        // ⚠️⚠️ **E O RÓTULO CERTO EXISTE**, porque negativa sozinha vira falso positivo: o dia em
        // que alguém apagasse a barra inteira, as negativas acima passariam por AUSÊNCIA.
        $temORotulo = str_contains($corpos, 'para publicação') || str_contains($corpos, 'APROVAR_SUFIXO');
        $this->assertTrue($temORotulo, 'o rótulo "Aprovar … para publicação" não está em arquivo nenhum deste plano — as negativas acima passariam por ausência');

        // ⚠️ VOC-03 — "lucro líquido" PROIBIDO, nas duas formas, **só nos meus arquivos**.
        $this->assertSame(0, preg_match('/lucro\s*l[íi]quido|lucro_liquido/iu', $corpos), 'VOC-03: "lucro líquido" entrou num arquivo deste plano');

        // ⚠️ Critério 13 — ZERO `localStorage`/`sessionStorage`: a seleção e os filtros vivem na
        // URL. (O `KEY='ecf-aprovacao-v1'` do protótipo, `:914`, é recurso de protótipo.)
        $this->assertSame(0, preg_match('/localStorage|sessionStorage/', $corpos), 'critério 13: `localStorage` entrou num arquivo deste plano');

        // ⚠️ FILA-16 — "Restaurar dados de exemplo" (`:200`) NÃO EXISTE no produto.
        $this->assertSame(0, preg_match('/Restaurar dados de exemplo/u', $corpos), 'FILA-16: "Restaurar dados de exemplo" é recurso de protótipo');

        // ⚠️ ISCA DO LEITOR SEM COMENTÁRIO: esta prosa cita `localStorage`, "lucro líquido" e
        // "Restaurar dados de exemplo" de propósito, e o arquivo BRUTO dos meus `.jsx` também —
        // se a remoção de comentário parasse de funcionar, as três negativas acima reprovariam.
        $bruto = (string) file_get_contents($raiz.'/resources/js/Components/Mlb/Publicador/aprovacao/RodapeDaFila.jsx');
        $this->assertMatchesRegularExpression('/Restaurar dados de exemplo/u', $bruto, 'a isca do leitor saiu do docblock do rodapé — troque a isca');
    }

    /**
     * ⚠️ **A BIJEÇÃO ids ↔ métodos, nos DOIS sentidos:** método sem id **NUNCA RODA** (foi o
     * sobrevivente da mutação do plano 186-03) e id órfão é gate sobre ZERO casos.
     *
     * ⚠️ A contagem deste caso é de forma FIXA (dois `assertSame([])` coletando infratores e a
     * isca do nome que ninguém escreveu), então acrescentar id ao manifesto **NÃO** mexe neste
     * número.
     *
     * ⚠️⚠️ **E A COLISÃO DE HOMÔNIMO CONTRA A FASE 186:** o `--filter` do PHPUnit é regex **NÃO
     * ANCORADO**, e todo plano `_0N` desta fase tem homônimo possível na 186 (que está EM
     * PRODUÇÃO). Método meu que seja SUPERSTRING de um da 186 faz o id daquela fase **SOMAR** e
     * reprovar. ➡️ Varrido por script, nas DUAS direções, contra todo `test_*` de `tests/`.
     *
     * @group phase187
     */
    public function test_bijecao_do_manifesto_do_lote_187_09(): void
    {
        $raiz = $this->raiz();
        $manifesto = $raiz.'/tests/gates/contagens-187-09.txt';
        $this->assertFileExists($manifesto, 'o manifesto deste plano não existe — nenhum gate deste plano roda');

        $linhas = array_values(array_filter(
            array_map('trim', explode("\n", (string) file_get_contents($manifesto))),
            static fn (string $l): bool => $l !== '' && ! str_starts_with($l, '#'),
        ));
        $this->assertGreaterThanOrEqual(4, count($linhas), 'o manifesto tem menos ids do que este plano precisa');

        // Os filtros PHP do manifesto, concatenados.
        $filtros = '';
        foreach ($linhas as $linha) {
            $partes = preg_split('/\s+/', $linha);
            if (is_array($partes) && count($partes) >= 3 && $partes[1] === 'php') {
                $filtros .= $partes[2].' ';
            }
        }
        $this->assertNotSame('', $filtros, 'o manifesto não tem id php nenhum');

        // Os meus métodos.
        $meus = [];
        foreach (get_class_methods($this) as $metodo) {
            if (str_starts_with($metodo, 'test_')) {
                $meus[] = $metodo;
            }
        }
        $this->assertGreaterThanOrEqual(8, count($meus), 'este arquivo tem menos métodos do que o plano prescreve');

        // ─── Sentido 1: TODO método meu casa ALGUM filtro do manifesto ───
        $semId = [];
        foreach ($meus as $metodo) {
            // ⚠️ Flag DENTRO do laço.
            $casou = false;
            foreach (preg_split('/\s+/', trim($filtros)) ?: [] as $filtro) {
                if ($filtro !== '' && preg_match('/'.$filtro.'/', $metodo) === 1) {
                    $casou = true;
                    break;
                }
            }
            if (! $casou) {
                $semId[] = $metodo;
            }
        }
        $this->assertSame([], $semId, 'método(s) sem id no manifesto — eles NUNCA RODAM num gate');

        // ─── Sentido 2: TODO filtro php do manifesto casa ALGUM método meu ───
        $orfaos = [];
        foreach (preg_split('/\s+/', trim($filtros)) ?: [] as $filtro) {
            if ($filtro === '') {
                continue;
            }
            $casou = false;
            foreach ($meus as $metodo) {
                if (preg_match('/'.$filtro.'/', $metodo) === 1) {
                    $casou = true;
                    break;
                }
            }
            if (! $casou) {
                $orfaos[] = $filtro;
            }
        }
        $this->assertSame([], $orfaos, 'filtro(s) do manifesto sem método nenhum — gate sobre ZERO casos');

        // ⚠️ ISCA: o primeiro filtro php NÃO casa um nome que ninguém escreveu.
        $primeiro = preg_split('/\s+/', trim($filtros))[0];
        $this->assertSame(0, preg_match('/'.$primeiro.'/', 'test_metodo_que_ninguem_escreveu_187_09'), 'o filtro casa nome inexistente — ele está largo demais');

        // ─── ⚠️⚠️ HOMÔNIMO CONTRA A 186 (e contra qualquer outro teste da árvore) ───
        $todosOsOutros = [];
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz.'/tests'));
        foreach ($iterador as $arquivo) {
            if (! $arquivo->isFile() || $arquivo->getExtension() !== 'php') {
                continue;
            }
            $conteudo = (string) file_get_contents($arquivo->getPathname());
            if (preg_match_all('/function\s+(test_[A-Za-z0-9_]+)\s*\(/', $conteudo, $m) > 0) {
                foreach ($m[1] as $nome) {
                    // ⚠️ Flag DENTRO do laço: os MEUS não contam como alheios.
                    $ehMeu = in_array($nome, $meus, true);
                    if (! $ehMeu) {
                        $todosOsOutros[] = $nome;
                    }
                }
            }
        }
        $todosOsOutros = array_values(array_unique($todosOsOutros));
        $this->assertGreaterThan(100, count($todosOsOutros), 'a varredura de métodos alheios achou pouco — ela está cega');

        $colisoes = [];
        foreach ($meus as $meu) {
            foreach ($todosOsOutros as $alheio) {
                // ⚠️ As DUAS direções: o meu contém o alheio (meu filtro somaria o dele) OU o
                // alheio contém o meu (o filtro dele somaria o meu).
                $colide = str_contains($meu, $alheio) || str_contains($alheio, $meu);
                if ($colide) {
                    $colisoes[] = $meu.' × '.$alheio;
                }
            }
        }
        $this->assertSame([], $colisoes, 'HOMÔNIMO: o `--filter` NÃO é ancorado e as contagens SOMARIAM. O conserto é sufixo no método NOVO, nunca elevar a contagem do manifesto alheio.');
    }
}
