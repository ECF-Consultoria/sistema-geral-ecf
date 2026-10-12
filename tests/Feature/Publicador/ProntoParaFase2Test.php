<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\JornadaDosProdutosService;
use App\Services\Publicador\NovaPublicacaoService;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 179, plano 02B — "pronto para a Fase 2" tem UMA fonte de verdade.
 *
 * ═══ ⚠️ A DECISÃO DO USUÁRIO — 12/10/2026 ════════════════════════════════════
 *
 * **Pergunta levada a ele:** *"Um produto cuja publicação falhou, mas que JÁ TEM
 * anúncio vivo no ar e sem kit, conta como 'Pronto para Fase 2'?"*
 *
 * **Opções apresentadas:** (1) sim, alinhar os três lugares; (2) não, erro é
 * erro e fica fora de todo lugar; (3) ver o número real de produção primeiro.
 * Apresentada **com o aviso explícito** de que alinhar MUDA o modal da Fase 178
 * que ele já aprovou em produção.
 *
 * ➡️ **Escolha dele: a opção 1 — alinhar os três lugares.**
 *
 * ⚠️ **Isso SUPERA a "Exclusão 2" do `178-01-SUMMARY.md`** (*"erro de publicação
 * é triagem, e a casa dela é o bloco 'O que fazer agora'"*). A contradição é
 * resolvida pela decisão do usuário, que é **posterior e mais informada**.
 * **Nenhuma sessão futura deve "restaurar" a Exclusão 2 achando que foi
 * regressão** — se este arquivo começar a reprovar porque alguém tirou o 2º ramo
 * de "no ar", é a decisão que está sendo desfeita, não um bug sendo consertado.
 *
 * ═══ O QUE ESTE ARQUIVO GUARDA ═══════════════════════════════════════════════
 *
 * Antes deste plano a regra existia em **TRÊS CÓPIAS**, e foi exatamente a cópia
 * que divergiu: a Jornada (plano 179-01) usava os dois ramos de "no ar" e as
 * outras duas só o status. Na mesma tela o anel dizia um número e a ação dizia
 * outro; **dentro da mesma ação** a sub-lista mostrava 2 itens sob um cabeçalho
 * que dizia 1.
 *
 * Depois deste plano há **uma** definição —
 * `ProgramasPublicadorService::prontoParaFase2()` — e os três consumidores a
 * chamam:
 *
 * | # | Consumidor | Onde |
 * |---|---|---|
 * | 1 | o anel (estágio `ready`) | `JornadaDosProdutosService::estagioDe()` |
 * | 2 | a linha/ação "Prontos para a Fase 2" | `PainelVisaoGeralService::prontosParaFase2()` |
 * | 3 | o grupo `prontos_fase2` do modal | `NovaPublicacaoService::grupos()` |
 *
 * ⚠️ **O gate central compara CONJUNTOS DE SKU, não contagens.** Dois
 * consumidores podem acertar o número errando o produto — e aí a tela mostraria
 * "2" nos dois lugares apontando para produtos diferentes. Contagem igual não é
 * conjunto igual.
 *
 * ⚠️ **E o cenário NÃO é vazio por construção**: `test_a_classe_de_fronteira_
 * estava_fora_antes_do_alinhamento()` reimplementa a regra ANTIGA aqui dentro e
 * prova que ela devolve 2 onde a nova devolve 3. Sem esse caso, um cenário sem
 * produto da classe faria o gate central passar sempre — foi exatamente essa a
 * armadilha do `NovaPublicacaoDoHeaderTest:335`, que a 179-02 mediu que NÃO
 * quebraria com o alinhamento porque o cenário dele não tinha produto em `erro`.
 *
 * ⚠️ Nenhum teste aqui chama o Mercado Livre: `Http::fake()` sem handler
 * intercepta tudo (tudo é leitura do que já está gravado).
 *
 * ⚠️ Os testes rodam em SQLite; produção é MySQL 8 e o dev local é MariaDB.
 * Este plano **não cria migration** — não há nada a validar no VPS.
 *
 * ⚠️ **O número de produção NÃO foi medido**: leitura de produção (plink,
 * tinker remoto, MCP) está bloqueada. A classe é estreita — base cujo rascunho
 * está `FAILED` **e** que ainda tem item `CREATED` vivo **e** sem kit **e** não
 * composta — mas quantos produtos ela tem na conta 459 é desconhecido.
 *
 * @group phase179
 */
class ProntoParaFase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    // ═══ 1. O GATE CENTRAL: os três consumidores sobre o MESMO conjunto ══════

    /**
     * ⚠️ O GATE CENTRAL DESTE PLANO.
     *
     * Uma lista com os **seis** casos de fronteira juntos, e os três consumidores
     * medidos sobre ela. Compara **conjunto de SKU**, não contagem.
     *
     * O que precisaria estar errado para este gate falhar: qualquer um dos três
     * consumidores deixar de chamar a fonte única e voltar a ter a sua cópia da
     * regra. É exatamente o que as mutações M01, M06, M07 e M08 fazem.
     */
    public function test_a_classe_de_fronteira_entra_nos_tres_consumidores(): void
    {
        [$e, $c] = $this->conta();
        $this->fronteira($e, $c);

        $produtos = $this->programas()->produtosParaTela($e, $c, 'empresa-'.$e->id);

        // Consumidor 1 — o anel.
        $doAnel = $this->estagio($this->jornada($e, $c), 'ready');
        // Consumidor 2 — a linha/ação (private, por reflexão).
        $daAcao = $this->prontosParaFase2($produtos);
        // Consumidor 3 — o grupo do modal.
        $doModal = $this->skusDoModal($e);

        $esperado = ['PUB', 'PARC', 'ERRO-COM-ANUNCIO'];

        $this->assertEqualsCanonicalizing(
            $esperado,
            array_column($doAnel['itens'], 'sku'),
            'o anel (estágio `ready`) deixou de concordar com a fonte única',
        );
        $this->assertEqualsCanonicalizing(
            $esperado,
            $doModal,
            'o grupo `prontos_fase2` do modal deixou de concordar com a fonte única — '
            .'reler a decisão de 12/10/2026 no topo deste arquivo',
        );
        $this->assertEqualsCanonicalizing(
            array_column($doAnel['itens'], 'sku'),
            $doModal,
            'o anel e o modal discordam sobre QUAIS produtos esperam Fase 2',
        );

        // E a contagem da linha bate com o tamanho dos conjuntos.
        $this->assertSame(3, $daAcao, 'era 2 antes do alinhamento de 12/10/2026 (a base em `erro` com anúncio vivo ficava fora)');
        $this->assertSame($daAcao, $doAnel['total']);
        $this->assertSame($daAcao, count($doModal));
    }

    /**
     * ⚠️ A PROVA DE QUE O GATE CENTRAL NÃO É VAZIO POR CONSTRUÇÃO.
     *
     * A regra ANTIGA (só `STATUS_NO_AR`) é reimplementada **aqui dentro**, de
     * propósito: se o cenário não tivesse um produto da classe de fronteira, o
     * gate central passaria com qualquer uma das duas definições e não provaria
     * nada. Aqui a antiga devolve **2** e a nova devolve **3** — a diferença é o
     * produto que a decisão do usuário mandou incluir.
     */
    public function test_a_classe_de_fronteira_estava_fora_antes_do_alinhamento(): void
    {
        [$e, $c] = $this->conta();
        $this->fronteira($e, $c);

        $produtos = $this->programas()->produtosParaTela($e, $c, 'empresa-'.$e->id);

        $pelaRegraAntiga = [];
        $pelaRegraNova = [];
        foreach ($produtos as $p) {
            if (! array_key_exists('eh_kit', $p)) {
                continue;
            }
            // A regra ANTIGA, byte a byte como estava em PainelVisaoGeralService:655
            // e em NovaPublicacaoService:128-133 — só o STATUS, sem o anúncio vivo.
            if ($p['eh_kit'] === false
                && ! ProgramasPublicadorService::ehComposto($p)
                && in_array($p['status']['chave'] ?? null, ProgramasPublicadorService::STATUS_NO_AR, true)
                && ($p['kits'] ?? []) === []) {
                $pelaRegraAntiga[] = $p['sku'];
            }
            if (ProgramasPublicadorService::prontoParaFase2($p)) {
                $pelaRegraNova[] = $p['sku'];
            }
        }

        $this->assertEqualsCanonicalizing(['PUB', 'PARC'], $pelaRegraAntiga, 'o cenário deixou de ter a classe de fronteira — o gate central virou decorativo');
        $this->assertEqualsCanonicalizing(['PUB', 'PARC', 'ERRO-COM-ANUNCIO'], $pelaRegraNova);
        $this->assertSame(
            ['ERRO-COM-ANUNCIO'],
            array_values(array_diff($pelaRegraNova, $pelaRegraAntiga)),
            'o produto que a decisão de 12/10/2026 mandou INCLUIR é exatamente a base em `erro` com anúncio vivo',
        );
    }

    // ═══ 2. O predicado caso a caso, em shape de array (sem banco) ═══════════

    /**
     * Cada condição da regra, isolada. Os `assertFalse` dizem o que a regra
     * continua recusando — o alinhamento abriu UM caso, não a porteira.
     */
    public function test_prontoParaFase2_caso_a_caso(): void
    {
        // Entra: base no ar pelo STATUS, sem kit.
        $this->assertTrue(ProgramasPublicadorService::prontoParaFase2($this->linha(['status' => ['chave' => 'publicado']])));
        $this->assertTrue(ProgramasPublicadorService::prontoParaFase2($this->linha(['status' => ['chave' => 'parcial']])));

        // ⚠️ ENTRA desde 12/10/2026: base em `erro` com anúncio CREATED vivo, sem kit.
        $this->assertTrue(
            ProgramasPublicadorService::prontoParaFase2($this->linha([
                'status' => ['chave' => 'erro'],
                'anuncios' => [['ml_item_id' => 'MLB1', 'listing_type_id' => 'gold_special']],
            ])),
            'a decisão de 12/10/2026 mandou INCLUIR este caso — reler o topo deste arquivo antes de "consertar"',
        );

        // Fica fora: erro SEM anúncio vivo (a Exclusão 2 do 178-01 sobrevive para ele).
        $this->assertFalse(ProgramasPublicadorService::prontoParaFase2($this->linha(['status' => ['chave' => 'erro']])));
        // Fica fora: no ar mas JÁ TEM kit — não espera mais nada.
        $this->assertFalse(ProgramasPublicadorService::prontoParaFase2($this->linha([
            'status' => ['chave' => 'publicado'],
            'kits' => [['id' => 9, 'quantidade_kit' => 2]],
        ])));
        // Fica fora: o próprio kit (ele É a Fase 2 de alguém).
        $this->assertFalse(ProgramasPublicadorService::prontoParaFase2($this->linha([
            'eh_kit' => true,
            'status' => ['chave' => 'publicado'],
        ])));
        // Fica fora: composto do Planejamento (KIT-06).
        $this->assertFalse(ProgramasPublicadorService::prontoParaFase2($this->linha([
            'composto' => 'combo',
            'status' => ['chave' => 'publicado'],
        ])));
        // Fica fora: não está no ar de jeito nenhum.
        $this->assertFalse(ProgramasPublicadorService::prontoParaFase2($this->linha(['status' => ['chave' => 'rascunho']])));
        $this->assertFalse(ProgramasPublicadorService::prontoParaFase2($this->linha(['status' => ['chave' => 'conferir']])));

        // Shape antigo (sem `eh_kit`) entra como base — é como `bucketDaFase()` o trata.
        $antiga = ['sku' => 'VELHA', 'status' => ['chave' => 'publicado']];
        $this->assertTrue(ProgramasPublicadorService::prontoParaFase2($antiga), 'sumir com a linha de shape antigo seria pior que contá-la como base');
    }

    /**
     * "No ar" tem UMA definição, e os três lugares que a usam concordam.
     *
     * ⚠️ Com ISCA: o produto em `erro` **sem** anúncio é `false` nos três. Sem a
     * isca, uma função que devolvesse `true` sempre passaria.
     */
    public function test_no_ar_tem_uma_definicao_so(): void
    {
        $comAnuncio = $this->linha([
            'status' => ['chave' => 'erro'],
            'anuncios' => [['ml_item_id' => 'MLB1', 'listing_type_id' => 'gold_special']],
        ]);
        $semAnuncio = $this->linha(['status' => ['chave' => 'erro']]);

        $this->assertTrue(ProgramasPublicadorService::noAr($comAnuncio), 'o 2º ramo de "no ar" (anúncio CREATED vivo) desapareceu');
        $this->assertFalse(ProgramasPublicadorService::noAr($semAnuncio), 'ISCA: `erro` sem anúncio nunca está no ar');

        // O estágio da Jornada usa a MESMA definição.
        $jornada = $this->servico()->jornada(['chave' => null], [$comAnuncio, $semAnuncio]);
        $this->assertSame(1, $this->estagio($jornada, 'ready')['total'], 'o que está no ar sem kit é `ready`');
        $this->assertSame(1, $this->estagio($jornada, 'draft')['total'], 'ISCA: o que não está no ar cai em `draft`');

        // E `noArPorFase()` também (private, por reflexão).
        $metodo = new \ReflectionMethod(PainelVisaoGeralService::class, 'noArPorFase');
        $metodo->setAccessible(true);
        $porFase = $metodo->invoke(app(PainelVisaoGeralService::class), [$comAnuncio, $semAnuncio]);
        $this->assertSame(['fase1' => 1, 'kits' => 0], $porFase, 'noArPorFase() deixou de usar a definição única de "no ar"');
    }

    // ═══ 3. O que o alinhamento NÃO podia quebrar ════════════════════════════

    /**
     * ⚠️ O fallback de shape antigo de `prontosParaFase2()` sobreviveu.
     *
     * Aquele método tem o ramo `$cienteDeFase ? … : contagemProdutos['publicados']`
     * porque recebe listas sem as chaves de fase. Matá-lo ao delegar faria a linha
     * "Prontos para a Fase 2" **desaparecer** por falta de dado em vez de contar
     * como antes. A mutação M09 remove o fallback e tem de reprovar aqui.
     */
    public function test_o_fallback_de_shape_antigo_de_prontosParaFase2_sobreviveu(): void
    {
        $antigas = [
            ['sku' => 'V1', 'status' => ['chave' => 'publicado']],
            ['sku' => 'V2', 'status' => ['chave' => 'publicado']],
            ['sku' => 'V3', 'status' => ['chave' => 'rascunho']],
        ];

        $this->assertSame(
            2,
            $this->prontosParaFase2($antigas, ['publicados' => 2]),
            'o fallback por contagemProdutos morreu no alinhamento — a linha desapareceria por falta de dado',
        );
    }

    /**
     * A fonte única não consulta o banco — ela recebe a linha pronta.
     *
     * ⚠️ Com ISCA: `produtosParaTela()` numa conta real consulta de verdade. Sem
     * a isca, um `enableQueryLog()` mal posicionado daria zero por construção.
     */
    public function test_a_regra_nao_consulta_o_banco(): void
    {
        [$e, $c] = $this->conta();
        $this->fronteira($e, $c);

        DB::enableQueryLog();
        $produtos = $this->programas()->produtosParaTela($e, $c, 'empresa-'.$e->id);
        $daIsca = count(DB::getQueryLog());

        DB::flushQueryLog();
        foreach ($produtos as $p) {
            ProgramasPublicadorService::prontoParaFase2($p);
            ProgramasPublicadorService::noAr($p);
        }
        $daRegra = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $daIsca, 'ISCA: produtosParaTela() tem de consultar — se ela deu zero, o log não estava ligado');
        $this->assertSame(0, $daRegra, 'a fonte única passou a consultar o banco');
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /**
     * Os SEIS casos de fronteira, numa conta só. É este cenário que faz o gate
     * central valer alguma coisa.
     */
    private function fronteira(MlbEmpresa $e, Company $c): void
    {
        $this->rascunho($this->produto($e, $c, 'PUB'), PubRascunho::PUBLISHED);            // entra
        $this->rascunho($this->produto($e, $c, 'PARC'), PubRascunho::PARTIALLY_PUBLISHED); // entra

        // ⚠️ A CLASSE DE FRONTEIRA da decisão de 12/10/2026: rascunho FAILED e
        // anúncio CREATED vivo, sem kit, não composta. ENTRA desde o alinhamento.
        $erro = $this->produto($e, $c, 'ERRO-COM-ANUNCIO');
        $this->anuncios($this->rascunho($erro, PubRascunho::FAILED), 1);

        $comKit = $this->produto($e, $c, 'COM-KIT');
        $this->rascunho($comKit, PubRascunho::PUBLISHED);
        $this->rascunho($this->kit($comKit, 2), PubRascunho::PUBLISHED);                   // base sai (tem kit)
        $this->rascunho($this->composto($e, $c, 'combo', 'CB-PUB'), PubRascunho::PUBLISHED); // sai (composto)
        $this->rascunho($this->produto($e, $c, 'DRAFT'), PubRascunho::DRAFT);              // sai (não está no ar)
    }

    /** Uma linha de `produtosParaTela()` em shape mínimo, para o predicado direto. */
    private function linha(array $extra = []): array
    {
        return $extra + [
            'id' => 1,
            'sku' => 'SKU-1',
            'nome' => 'Cadeira',
            'eh_kit' => false,
            'kits' => [],
            'anuncios' => [],
            'status' => ['chave' => 'rascunho'],
        ];
    }

    /** A regra da linha/ação, `private` naquele serviço. */
    private function prontosParaFase2(array $produtos, ?array $contagem = null): int
    {
        $metodo = new \ReflectionMethod(PainelVisaoGeralService::class, 'prontosParaFase2');
        $metodo->setAccessible(true);

        return $metodo->invoke(
            app(PainelVisaoGeralService::class),
            $contagem ?? $this->programas()->contagemProdutos($produtos),
            $produtos,
        );
    }

    /** Os SKUs do grupo `prontos_fase2` do modal da Fase 178. */
    private function skusDoModal(MlbEmpresa $e): array
    {
        $saida = app(NovaPublicacaoService::class)->grupos($this->programas()->resolver('empresa-'.$e->id));
        foreach ($saida['grupos'] as $g) {
            if ($g['chave'] === 'prontos_fase2') {
                return array_column($g['itens'], 'sku');
            }
        }
        $this->fail("o grupo 'prontos_fase2' não veio na saída do modal");
    }

    private function jornada(MlbEmpresa $e, ?Company $c): array
    {
        return $this->servico()->jornada(
            $this->programas()->resolver('empresa-'.$e->id),
            $this->programas()->produtosParaTela($e, $c, 'empresa-'.$e->id),
        );
    }

    private function estagio(array $saida, string $chave): array
    {
        foreach ($saida['estagios'] as $est) {
            if ($est['chave'] === $chave) {
                return $est;
            }
        }
        $this->fail("o estágio '{$chave}' não veio na saída");
    }

    /**
     * Uma conta de Polos com token ativo.
     *
     * @return array{0: MlbEmpresa, 1: Company}
     */
    private function conta(string $nome = 'Polo do Pronto para Fase 2'): array
    {
        $company = Company::factory()->create(['name' => $nome]);
        $empresa = MlbEmpresa::create(['nome' => $nome, 'projeto' => 'POLOS', 'company_id' => $company->id]);
        MlToken::create([
            'company_id' => $company->id,
            'ml_user_id' => '1555596317',
            'access_token' => 'APP_USR-segredo',
            'refresh_token' => 'TG-segredo',
            'expires_at' => now()->addHours(5),
            'status' => 'active',
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

    private function rascunho(PubProduto $p, string $status, array $extra = []): PubRascunho
    {
        return PubRascunho::create($extra + ['produto_id' => $p->id, 'status' => $status, 'revisao' => 1]);
    }

    /** Um kit da Fase N do produto base: mesmas âncoras, `quantidade_kit >= 2`. */
    private function kit(PubProduto $base, int $quantidade = 2): PubProduto
    {
        return $this->produto(
            $base->mlbEmpresa,
            $base->company,
            $base->sku.'-K'.$quantidade,
            ['produto_base_id' => $base->id, 'quantidade_kit' => $quantidade, 'fase' => $quantidade],
        );
    }

    /** Um produto ligado a uma oferta Combo/Kit/Combit do Portal — composto, nunca base de Fase 1 (KIT-06). */
    private function composto(MlbEmpresa $e, Company $c, string $tipo, string $sku): PubProduto
    {
        $oferta = EstruturaOferta::create(['company_id' => $c->id, 'sku' => $sku, 'fase' => $tipo, 'nome' => $sku]);

        return $this->produto($e, $c, $sku, ['oferta_id' => $oferta->id, 'origem' => PubProduto::ORIGEM_PORTAL]);
    }

    /** N anúncios CREATED no rascunho. */
    private function anuncios(PubRascunho $r, int $quantos): void
    {
        $publicacao = PubPublicacao::create([
            'rascunho_id' => $r->id, 'revisao' => 1, 'modelo_publicacao' => 'items',
            'status' => PubPublicacao::PUBLISHED, 'chave_idempotencia' => (string) Str::uuid(),
            'concluida_em' => now()->subDay(), 'ator' => ['equipe' => true, 'id' => 1, 'nome' => 'Dev'],
        ]);
        for ($i = 0; $i < $quantos; $i++) {
            PubPublicacaoItem::create([
                'publicacao_id' => $publicacao->id, 'indice' => $i,
                'listing_type_id' => $i === 0 ? 'gold_special' : 'gold_pro',
                'variante_chave' => ChaveCanonica::UNICA, 'status' => PubPublicacaoItem::CREATED,
                'ml_item_id' => 'MLB'.(7770000 + $i + ($r->id * 100)), 'payload' => [],
            ]);
        }
    }

    private function programas(): ProgramasPublicadorService
    {
        return app(ProgramasPublicadorService::class);
    }

    private function servico(): JornadaDosProdutosService
    {
        return app(JornadaDosProdutosService::class);
    }
}
