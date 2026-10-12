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
use App\Models\User;
use App\Services\Publicador\JornadaDosProdutosService;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 179, plano 01 — o CONTRATO do servidor da "Jornada dos produtos": os
 * quatro estágios do anel de 176px, com contagem, percentual, lista de produtos,
 * cobertura F1/F2 × Clássico/Premium e destino real por item.
 *
 * ═══ O que este arquivo guarda (e uma asserção isolada não guardaria) ════════
 *
 * 1. **TOTALIDADE.** A soma dos quatro estágios é o total, em TODOS os cenários.
 *    Um quinto caso sem casa — um status novo caindo fora do default, ou o `erro`
 *    "promovido" a estágio próprio — reprova aqui. É o que impede o
 *    `N de M produtos` do meio do anel de discordar da soma dos quatro cartões.
 * 2. **EXCLUSIVIDADE.** Nenhum `produto_id` em dois estágios. Se um produto
 *    couber em dois, o percentual não fecha e a barra segmentada mente.
 * 3. **PARIDADE com a própria Visão geral.** `prontosParaFase2()` é `private` em
 *    `PainelVisaoGeralService` e aquele arquivo é do plano 179-02; aqui a regra
 *    é comparada contra a original **por reflexão**, nos cinco casos de
 *    fronteira. Sem este gate, dois números com o mesmo nome na mesma tela
 *    poderiam divergir em silêncio.
 * 4. **A divergência conhecida é MEDIDA, não escondida.** "No ar" tem uma
 *    definição só (status OU anúncio vivo) e `prontosParaFase2()` usa só o
 *    status: `test_divergencia_com_prontos_para_fase_2_e_medida_e_declarada()`
 *    cobra o tamanho exato da diferença. Se ela crescer, o teste reprova.
 * 5. **DIVISÃO POR ZERO é impossível.** Conta sem produto devolve `pct === 0` e
 *    nenhum `NAN` no payload — `NaN` na tela foi o risco que abriu este plano.
 * 6. **NENHUM RÓTULO DE TELA no payload**, por asserção NEGATIVA (chaves negadas
 *    + varredura do JSON pelos literais do protótipo). A asserção positiva
 *    deixaria uma fase futura acrescentar rótulo com o teste seguindo verde.
 * 7. **Todo campo do item é escalar**, menos `cobertura` (quatro booleanos). Foi
 *    um campo-objeto renderizado como texto que derrubou a página inteira em
 *    07/10 ("Objects are not valid as a React child").
 * 8. **CUSTO ZERO.** `jornada()` não faz consulta nenhuma — e o mesmo teste
 *    conta as consultas de `produtosParaTela()` e exige que esse número seja
 *    maior que zero (⚠️ **isca**: sem ela, um `enableQueryLog()` mal posicionado
 *    daria zero por construção e o gate passaria quebrado).
 *
 * ═══ Este arquivo foi PROVADO POR MUTAÇÃO ════════════════════════════════════
 *
 * Em 12/10/2026 rodaram **12 mutações** no serviço real. **Todas as 12
 * reprovaram**, cada uma no caso que devia cair. Antes de cada mutação a
 * CONTAGEM de ocorrências do alvo foi travada (`grep -c`) e, depois, a linha
 * alterada foi conferida — na Fase 178 três mutações foram recusadas porque o
 * alvo também existia em comentário e `sed` troca só a primeira. O arquivo foi
 * restaurado de cópia pristina conferida por `sha256`. O relato está em
 * `179-01-SUMMARY.md`.
 *
 * ⚠️ Nenhum teste aqui chama o Mercado Livre: `Http::fake()` sem handler
 * intercepta tudo. Este contrato só lê o que já está gravado.
 *
 * ⚠️ Os testes rodam em SQLite; produção é MySQL 8 e o dev local é MariaDB.
 * Este plano não cria migration (é tudo leitura).
 *
 * @group phase179
 */
class JornadaDosProdutosTest extends TestCase
{
    use RefreshDatabase;

    /** A ordem dos estágios é CONTRATO: o anel, a barra e os cartões dependem dela. */
    private const ORDEM_DOS_ESTAGIOS = ['none', 'draft', 'ready', 'done'];

    /** As chaves do topo, em ORDEM. */
    private const CAMPOS_DO_TOPO = ['total', 'publicados', 'pct', 'tem_produtos', 'proximo_passo', 'estagios'];

    /** As chaves de cada estágio, em ORDEM. */
    private const CAMPOS_DO_ESTAGIO = ['chave', 'total', 'share_pct', 'itens', 'ver_todos_url'];

    /** As chaves do item, em ORDEM. Campo a mais aqui é convite a inventar tela na wave 3. */
    private const CAMPOS_DO_ITEM = ['produto_id', 'nome', 'sku', 'nota', 'capa', 'cobertura', 'cta_chave', 'url'];

    /** As quatro células da cobertura, em ORDEM — ausente NUNCA é resposta. */
    private const CELULAS_DA_COBERTURA = ['f1_classico', 'f1_premium', 'f2_classico', 'f2_premium'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // O HandleInertiaRequests busca os sinais do ECF Drive em toda página autenticada.
        Http::fake();
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Uma conta de Polos com token ativo. Sem Company (D23 — 535 de 539
     * `MlbEmpresa` não têm Company) o token fica na própria `MlbEmpresa`.
     *
     * @return array{0: MlbEmpresa, 1: ?Company}
     */
    private function conta(bool $comCompany = true, string $nome = 'Polo da Jornada'): array
    {
        $company = $comCompany ? Company::factory()->create(['name' => $nome]) : null;
        $empresa = MlbEmpresa::create(['nome' => $nome, 'projeto' => 'POLOS', 'company_id' => $company?->id]);
        MlToken::create([
            'company_id' => $company?->id,
            'mlb_empresa_id' => $company === null ? $empresa->id : null,
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

    /**
     * Anúncios CREATED no rascunho, com o `listing_type_id` de cada um — é daqui
     * que sai a cobertura Clássico/Premium.
     *
     * @param  list<string>  $listingTypes
     */
    private function anuncios(PubRascunho $r, array $listingTypes): void
    {
        $publicacao = PubPublicacao::create([
            'rascunho_id' => $r->id, 'revisao' => 1, 'modelo_publicacao' => 'items',
            'status' => PubPublicacao::PUBLISHED, 'chave_idempotencia' => (string) Str::uuid(),
            'concluida_em' => now()->subDay(), 'ator' => ['equipe' => true, 'id' => 1, 'nome' => 'Dev'],
        ]);
        foreach (array_values($listingTypes) as $i => $listing) {
            PubPublicacaoItem::create([
                'publicacao_id' => $publicacao->id, 'indice' => $i,
                'listing_type_id' => $listing,
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

    /** O `$alvo` canônico da conta, do MESMO `resolver()` que o controller usa. */
    private function alvo(MlbEmpresa $e): array
    {
        return $this->programas()->resolver('empresa-'.$e->id);
    }

    /** A Jornada da conta, da lista que o controller já carrega. */
    private function jornada(MlbEmpresa $e, ?Company $c): array
    {
        $alvo = $this->alvo($e);

        return $this->servico()->jornada($alvo, $this->programas()->produtosParaTela($e, $c, $alvo['chave']));
    }

    /** Um estágio pela chave. */
    private function estagio(array $saida, string $chave): array
    {
        foreach ($saida['estagios'] as $est) {
            if ($est['chave'] === $chave) {
                return $est;
            }
        }
        $this->fail("o estágio '{$chave}' não veio na saída");
    }

    /** chave => total, para comparar o anel inteiro de uma vez. */
    private function totais(array $saida): array
    {
        return array_combine(array_column($saida['estagios'], 'chave'), array_column($saida['estagios'], 'total'));
    }

    private function skus(array $saida, string $chave): array
    {
        return array_column($this->estagio($saida, $chave)['itens'], 'sku');
    }

    /**
     * ⚠️ O GATE CENTRAL, cobrado em todo cenário: a soma dos quatro estágios é o
     * total, e nenhum produto aparece em dois.
     */
    private function assertFecha(array $saida): void
    {
        $this->assertSame(
            $saida['total'],
            array_sum(array_column($saida['estagios'], 'total')),
            'a soma dos quatro estágios deixou de ser o total — algum produto caiu fora do anel',
        );
        // ⚠️ `publicados` é cobrado pelo OUTRO lado — `total` menos os dois
        // estágios que não estão no ar. Comparar com `ready + done` seria
        // comparar a fórmula do serviço com ela mesma: verdadeiro por construção.
        $this->assertSame(
            $saida['total'] - $this->estagio($saida, 'none')['total'] - $this->estagio($saida, 'draft')['total'],
            $saida['publicados'],
            '`publicados` tem de ser o que está no ar (ready + done), como no protótipo',
        );

        $ids = [];
        foreach ($saida['estagios'] as $est) {
            foreach ($est['itens'] as $item) {
                $ids[] = $item['produto_id'];
            }
        }
        $this->assertSame(count($ids), count(array_unique($ids)), 'o mesmo produto apareceu em dois estágios');
    }

    /**
     * Uma linha no shape de `produtosParaTela()`, montada à mão — para os casos
     * que o banco não produz: kit fora do escopo, `listing_type_id` desconhecido
     * e lista de shape antigo.
     */
    private function linha(array $extra = []): array
    {
        return $extra + [
            'id' => 1,
            'sku' => 'SIN-1',
            'nome' => 'Produto sintético',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'oferta_id' => null,
            'rascunho_id' => null,
            'capa' => null,
            'status' => ['chave' => 'rascunho', 'rotulo' => 'a preencher', 'faltam' => 0],
            'anuncios' => [],
            'parcial' => null,
            'atualizado_em' => '2026-10-12T10:00:00+00:00',
            'fase' => 1,
            'quantidade_kit' => 1,
            'produto_base_id' => null,
            'eh_kit' => false,
            'rotulo_fase' => '1 unidade',
            'composto' => null,
            'url_produto' => '/mlb/anuncios/publicador/empresas/empresa-1/produtos/1',
            'kits' => [],
        ];
    }

    private function anuncio(string $listing): array
    {
        return ['ml_item_id' => 'MLB'.random_int(1000, 9999), 'listing_type_id' => $listing];
    }

    // ═══ 1. O shape e os quatro estágios ═════════════════════════════════════

    public function test_conta_sem_produto_devolve_os_quatro_estagios_vazios_sem_dividir_por_zero(): void
    {
        [$e, $c] = $this->conta();

        $saida = $this->jornada($e, $c);

        $this->assertSame(self::CAMPOS_DO_TOPO, array_keys($saida));
        $this->assertSame(self::ORDEM_DOS_ESTAGIOS, array_column($saida['estagios'], 'chave'));
        $this->assertCount(4, $saida['estagios'], 'são EXATAMENTE quatro estágios, sempre');
        $this->assertSame(0, $saida['total']);
        $this->assertSame(0, $saida['publicados']);
        $this->assertSame(0, $saida['pct'], 'conta sem produto é 0%, nunca NaN');
        $this->assertFalse($saida['tem_produtos']);
        $this->assertSame('none', $saida['proximo_passo']);

        foreach ($saida['estagios'] as $est) {
            $this->assertSame(self::CAMPOS_DO_ESTAGIO, array_keys($est));
            $this->assertSame(0, $est['total']);
            $this->assertSame(0, $est['share_pct'], 'share de estágio sem produto é 0, nunca NaN');
            $this->assertSame([], $est['itens']);
        }
        $this->assertFecha($saida);
    }

    /** ⚠️ Nenhum valor do payload pode ser `NAN` nem a string `'NaN'` (VG-19). */
    public function test_nenhum_valor_do_payload_e_nan_na_conta_vazia(): void
    {
        [$e, $c] = $this->conta();

        $saida = $this->jornada($e, $c);
        $json = json_encode($saida, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsStringIgnoringCase('nan', $json);
        array_walk_recursive($saida, function ($valor, $chave) {
            if (is_float($valor)) {
                $this->assertFalse(is_nan($valor), "o campo '{$chave}' é NAN");
            }
        });
    }

    /**
     * O cenário do protótipo, número por número
     * (`new_layout_publicador/Visao Geral.dc.html:391-423` → `none=6, draft=2,
     * ready=13, done=11`, 32 produtos, 24 publicados, 75%).
     *
     * ⚠️ Os 11 kits dos produtos `done` existem no banco e NÃO entram no total:
     * é o que mantém o `32` igual ao do protótipo.
     */
    public function test_o_cenario_do_prototipo_devolve_6_2_13_11_e_75_por_cento(): void
    {
        [$e, $c] = $this->conta();

        for ($i = 1; $i <= 6; $i++) {
            $this->produto($e, $c, 'NONE-'.$i);
        }
        for ($i = 1; $i <= 2; $i++) {
            $this->rascunho($this->produto($e, $c, 'DRAFT-'.$i), PubRascunho::DRAFT);
        }
        for ($i = 1; $i <= 13; $i++) {
            $this->rascunho($this->produto($e, $c, 'READY-'.$i), PubRascunho::PUBLISHED);
        }
        for ($i = 1; $i <= 11; $i++) {
            $base = $this->produto($e, $c, 'DONE-'.$i);
            $this->rascunho($base, PubRascunho::PUBLISHED);
            $this->rascunho($this->kit($base, 2), PubRascunho::PUBLISHED);
        }

        $saida = $this->jornada($e, $c);

        $this->assertSame(['none' => 6, 'draft' => 2, 'ready' => 13, 'done' => 11], $this->totais($saida));
        $this->assertSame(32, $saida['total'], '32 bases — os 11 kits ficam fora do universo');
        $this->assertSame(24, $saida['publicados']);
        $this->assertSame(75, $saida['pct']);
        $this->assertTrue($saida['tem_produtos']);
        $this->assertSame('ready', $saida['proximo_passo']);
        // `share` do protótipo: 6/32=19, 2/32=6, 13/32=41, 11/32=34 (soma 100).
        $this->assertSame([19, 6, 41, 34], array_column($saida['estagios'], 'share_pct'));
        $this->assertFecha($saida);
    }

    public function test_kit_nao_entra_no_universo_porque_ele_e_a_fase_2_do_base(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'BASE');
        $this->rascunho($base, PubRascunho::PUBLISHED);
        $this->rascunho($this->kit($base, 2), PubRascunho::PUBLISHED);

        $saida = $this->jornada($e, $c);

        $this->assertSame(1, $saida['total'], 'o kit somaria o mesmo produto duas vezes no anel');
        $this->assertSame(['none' => 0, 'draft' => 0, 'ready' => 0, 'done' => 1], $this->totais($saida));
        $this->assertSame(['BASE'], $this->skus($saida, 'done'));
        $this->assertFecha($saida);
    }

    public function test_composto_do_planejamento_nao_entra_no_universo(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->composto($e, $c, 'combo', 'CB-PUB'), PubRascunho::PUBLISHED);
        $this->composto($e, $c, 'kit', 'KT-CRU');
        $this->produto($e, $c, 'BASE-DE-VERDADE');

        $saida = $this->jornada($e, $c);

        $this->assertSame(1, $saida['total'], 'KIT-06: composto do Portal não é base de Fase 1');
        $this->assertSame(['BASE-DE-VERDADE'], $this->skus($saida, 'none'));
        $this->assertFecha($saida);
    }

    public function test_sem_rascunho_vai_para_none_e_conferir_e_pronto_vao_para_draft(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'SEM-RASCUNHO');
        $this->rascunho($this->produto($e, $c, 'EM-PREENCHIMENTO'), PubRascunho::DRAFT);
        $this->rascunho($this->produto($e, $c, 'CONFERIDO'), PubRascunho::VALIDATED);

        $saida = $this->jornada($e, $c);

        $this->assertSame(['SEM-RASCUNHO'], $this->skus($saida, 'none'));
        $this->assertEqualsCanonicalizing(['EM-PREENCHIMENTO', 'CONFERIDO'], $this->skus($saida, 'draft'));
        $this->assertSame(['none' => 1, 'draft' => 2, 'ready' => 0, 'done' => 0], $this->totais($saida));
        $this->assertSame('none', $saida['proximo_passo'], 'sem ready, o próximo passo é publicar a Fase 1');
        $this->assertFecha($saida);
    }

    /**
     * ⚠️ `erro` em `draft` é a divergência DECLARADA do protótipo (que tem quatro
     * estágios e nenhum caso de erro). A restrição é a totalidade do anel.
     */
    public function test_erro_sem_anuncio_vivo_entra_em_draft_e_nao_desaparece_do_anel(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'FALHOU'), PubRascunho::FAILED);

        $saida = $this->jornada($e, $c);

        $this->assertSame(['none' => 0, 'draft' => 1, 'ready' => 0, 'done' => 0], $this->totais($saida));
        $this->assertSame(1, $saida['total'], 'o erro não pode sair do anel: o N de M mentiria');
        $this->assertSame(['FALHOU'], $this->skus($saida, 'draft'));
        $this->assertFecha($saida);
    }

    /** `publicando` é o outro caso do default: cai em `draft`, nunca fora dos quatro. */
    public function test_publicando_cai_no_default_e_fica_em_draft(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'EM-VOO'), PubRascunho::PUBLISHING);

        $saida = $this->jornada($e, $c);

        $this->assertSame(['EM-VOO'], $this->skus($saida, 'draft'));
        $this->assertFecha($saida);
    }

    /**
     * O segundo ramo de "no ar" (`anuncios !== []`): a base que falhou numa
     * republicação mas tem anúncio CREATED vivo está NO AR, não em rascunho.
     */
    public function test_erro_com_anuncio_vivo_conta_como_no_ar(): void
    {
        [$e, $c] = $this->conta();

        $semKit = $this->produto($e, $c, 'ERRO-NO-AR');
        $this->anuncios($this->rascunho($semKit, PubRascunho::FAILED), ['gold_special']);

        $comKit = $this->produto($e, $c, 'ERRO-NO-AR-COM-KIT');
        $this->anuncios($this->rascunho($comKit, PubRascunho::FAILED), ['gold_special']);
        $this->rascunho($this->kit($comKit, 2), PubRascunho::PUBLISHED);

        $saida = $this->jornada($e, $c);

        $this->assertSame(['ERRO-NO-AR'], $this->skus($saida, 'ready'));
        $this->assertSame(['ERRO-NO-AR-COM-KIT'], $this->skus($saida, 'done'));
        $this->assertSame(['none' => 0, 'draft' => 0, 'ready' => 1, 'done' => 1], $this->totais($saida));
        $this->assertFecha($saida);
    }

    public function test_parcial_esta_em_status_no_ar_e_vai_para_ready(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'PARCIAL'), PubRascunho::PARTIALLY_PUBLISHED);

        $saida = $this->jornada($e, $c);

        $this->assertSame(['PARCIAL'], $this->skus($saida, 'ready'));
        $this->assertSame('ready', $saida['proximo_passo']);
        $this->assertFecha($saida);
    }

    // ═══ 2. A paridade com a regra que a Visão geral já usa ══════════════════

    /**
     * ⚠️ GATE DE CONSISTÊNCIA. `PainelVisaoGeralService::prontosParaFase2()`
     * (`app/Services/Publicador/PainelVisaoGeralService.php:592`) é `private` e
     * aquele arquivo é do plano 179-02 — então a regra é comparada contra a
     * original **por reflexão**, com os cinco casos de fronteira juntos:
     * base no ar sem kit · base no ar com kit · base composto no ar · base fora
     * do ar · (o quinto, shape antigo, tem teste próprio logo abaixo).
     *
     * Sem este gate, a Visão geral poderia discordar de si mesma: dois números
     * com o mesmo nome, na mesma tela, divergindo em silêncio.
     */
    public function test_ready_bate_com_prontos_para_fase_2_nos_casos_de_fronteira(): void
    {
        [$e, $c] = $this->conta();

        $this->rascunho($this->produto($e, $c, 'PUB'), PubRascunho::PUBLISHED);            // entra
        $this->rascunho($this->produto($e, $c, 'PARC'), PubRascunho::PARTIALLY_PUBLISHED); // entra
        $comKit = $this->produto($e, $c, 'COM-KIT');
        $this->rascunho($comKit, PubRascunho::PUBLISHED);
        $this->rascunho($this->kit($comKit, 2), PubRascunho::PUBLISHED);                     // sai (tem kit)
        $this->rascunho($this->composto($e, $c, 'combo', 'CB-PUB'), PubRascunho::PUBLISHED); // sai (composto)
        $this->rascunho($this->produto($e, $c, 'DRAFT'), PubRascunho::DRAFT);                // sai (fora do ar)

        $produtos = $this->programas()->produtosParaTela($e, $c, 'empresa-'.$e->id);
        $daVisaoGeral = $this->prontosParaFase2($produtos);

        $saida = $this->servico()->jornada($this->alvo($e), $produtos);

        $this->assertSame(2, $daVisaoGeral, 'a regra original mudou — reler PainelVisaoGeralService:592');
        $this->assertSame(
            $daVisaoGeral,
            $this->estagio($saida, 'ready')['total'],
            'a Jornada e a Visão geral discordam sobre quantos produtos esperam a Fase 2',
        );
        $this->assertEqualsCanonicalizing(['PUB', 'PARC'], $this->skus($saida, 'ready'));
        $this->assertFecha($saida);
    }

    /**
     * O quinto caso de fronteira: lista de **shape antigo** (sem `eh_kit`,
     * sem `kits`). Lá o método cai no fallback por `contagemProdutos['publicados']`;
     * aqui a linha entra no universo como base. Os dois números têm de bater.
     */
    public function test_ready_bate_com_prontos_para_fase_2_na_lista_de_shape_antigo(): void
    {
        $antigas = [
            ['id' => 1, 'sku' => 'A-PUB', 'nome' => 'A', 'oferta_id' => null, 'status' => ['chave' => 'publicado', 'rotulo' => 'publicado', 'faltam' => 0]],
            ['id' => 2, 'sku' => 'B-PUB', 'nome' => 'B', 'oferta_id' => null, 'status' => ['chave' => 'publicado', 'rotulo' => 'publicado', 'faltam' => 0]],
            ['id' => 3, 'sku' => 'C-RAS', 'nome' => 'C', 'oferta_id' => null, 'status' => ['chave' => 'rascunho', 'rotulo' => 'a preencher', 'faltam' => 0]],
        ];

        $daVisaoGeral = $this->prontosParaFase2($antigas);

        $saida = $this->servico()->jornada(['chave' => null], $antigas);

        $this->assertSame(2, $daVisaoGeral, 'o fallback por contagemProdutos mudou — reler PainelVisaoGeralService:592');
        $this->assertSame($daVisaoGeral, $this->estagio($saida, 'ready')['total']);
        $this->assertSame(3, $saida['total'], 'a linha de shape antigo não pode desaparecer do anel');
        $this->assertFecha($saida);
    }

    /**
     * ⚠️ A DIVERGÊNCIA CONHECIDA, medida em vez de escondida.
     *
     * "No ar" tem UMA definição (status em `STATUS_NO_AR` **ou** anúncio CREATED
     * vivo) e `prontosParaFase2()` usa só o status. Então a base com
     * `status.chave = 'erro'` e anúncio vivo, sem kit, entra em `ready` aqui e
     * NÃO conta lá — diferença de exatamente 1 neste cenário. Se ela crescer
     * (ou se alguém "consertar" o segundo ramo), este teste reprova e a
     * divergência volta para a mesa.
     */
    public function test_divergencia_com_prontos_para_fase_2_e_medida_e_declarada(): void
    {
        [$e, $c] = $this->conta();

        $this->rascunho($this->produto($e, $c, 'PUB'), PubRascunho::PUBLISHED);
        $erro = $this->produto($e, $c, 'ERRO-COM-ANUNCIO');
        $this->anuncios($this->rascunho($erro, PubRascunho::FAILED), ['gold_special']);

        $produtos = $this->programas()->produtosParaTela($e, $c, 'empresa-'.$e->id);
        $daVisaoGeral = $this->prontosParaFase2($produtos);
        $saida = $this->servico()->jornada($this->alvo($e), $produtos);

        $this->assertSame(1, $daVisaoGeral, 'prontosParaFase2() não vê o anúncio vivo: só o status');
        $this->assertSame(2, $this->estagio($saida, 'ready')['total'], 'a Jornada vê os dois ramos de "no ar"');
        $this->assertSame(
            1,
            $this->estagio($saida, 'ready')['total'] - $daVisaoGeral,
            'o tamanho da divergência declarada mudou — reler o docblock de jornada()',
        );
        $this->assertFecha($saida);
    }

    /** A regra original, por reflexão — ela é `private` e o arquivo é do plano 179-02. */
    private function prontosParaFase2(array $produtos): int
    {
        $metodo = new \ReflectionMethod(PainelVisaoGeralService::class, 'prontosParaFase2');
        $metodo->setAccessible(true);

        return $metodo->invoke(
            app(PainelVisaoGeralService::class),
            $this->programas()->contagemProdutos($produtos),
            $produtos,
        );
    }

    // ═══ 3. Os cenários do anel (VG-19) ══════════════════════════════════════

    /** "Produtos sem publicação": o anel inteiro em 0%, com produto na conta. */
    public function test_cenario_produtos_sem_publicacao_da_anel_de_zero_por_cento(): void
    {
        [$e, $c] = $this->conta();
        for ($i = 1; $i <= 8; $i++) {
            $this->produto($e, $c, 'NOVO-'.$i);
        }

        $saida = $this->jornada($e, $c);

        $this->assertSame(0, $saida['pct']);
        $this->assertSame(0, $saida['publicados']);
        $this->assertSame(8, $saida['total']);
        $this->assertTrue($saida['tem_produtos'], 'tem produto: o empty state é outro cenário');
        $this->assertSame(['none' => 8, 'draft' => 0, 'ready' => 0, 'done' => 0], $this->totais($saida));
        $this->assertSame([100, 0, 0, 0], array_column($saida['estagios'], 'share_pct'));
        $this->assertSame('none', $saida['proximo_passo']);
        $this->assertFecha($saida);
    }

    /** "Sem produtos": o empty state, com a lista literalmente vazia. */
    public function test_cenario_sem_produtos_com_a_lista_vazia_nao_lanca(): void
    {
        $saida = $this->servico()->jornada(['chave' => 'empresa-1'], []);

        $this->assertSame(0, $saida['total']);
        $this->assertSame(0, $saida['pct']);
        $this->assertFalse($saida['tem_produtos']);
        $this->assertSame('none', $saida['proximo_passo']);
        $this->assertFecha($saida);
    }

    /**
     * D23 — conta sem Company e `$alvo` sem chave: nada lança, as quatro
     * `ver_todos_url` são nulas, os itens de `ready`/`done` ficam FORA (sem
     * `url_produto`) e os de `none`/`draft` continuam (o editor é endereçado por
     * `{produto}`, não pela conta).
     *
     * ⚠️ E o `total` do estágio NÃO muda por isso: ele conta o universo, não a
     * amostra. É o que mantém o anel fechado quando o painel não tem o que abrir.
     */
    public function test_d23_alvo_sem_chave_nao_lanca_e_so_perde_os_itens_sem_destino(): void
    {
        [$e] = $this->conta(comCompany: false);
        $this->produto($e, null, 'SEM-RASCUNHO');
        $this->rascunho($this->produto($e, null, 'EM-PREENCHIMENTO'), PubRascunho::DRAFT);
        $this->rascunho($this->produto($e, null, 'NO-AR'), PubRascunho::PUBLISHED);
        $comKit = $this->produto($e, null, 'NO-AR-COM-KIT');
        $this->rascunho($comKit, PubRascunho::PUBLISHED);
        $this->rascunho($this->kit($comKit, 2), PubRascunho::PUBLISHED);

        // A lista SEM a chave da conta: é assim que `url_produto` vem nula.
        $produtos = $this->programas()->produtosParaTela($e, null, null);
        $saida = $this->servico()->jornada(['chave' => null], $produtos);

        $this->assertSame(['none' => 1, 'draft' => 1, 'ready' => 1, 'done' => 1], $this->totais($saida));
        $this->assertSame(4, $saida['total']);
        foreach (self::ORDEM_DOS_ESTAGIOS as $chave) {
            $this->assertNull($this->estagio($saida, $chave)['ver_todos_url'], "ver_todos_url de {$chave} tinha de ser nula");
        }
        $this->assertSame([], $this->estagio($saida, 'ready')['itens'], 'nunca um clique mudo');
        $this->assertSame([], $this->estagio($saida, 'done')['itens'], 'nunca um clique mudo');
        $this->assertSame(['SEM-RASCUNHO'], $this->skus($saida, 'none'), 'o editor não depende da conta');
        $this->assertSame(['EM-PREENCHIMENTO'], $this->skus($saida, 'draft'));
        $this->assertFecha($saida);
    }

    // ═══ 4. Nenhum rótulo de tela no payload ═════════════════════════════════

    /**
     * ⚠️ Asserção NEGATIVA, e rodada com os quatro estágios PREENCHIDOS — uma
     * varredura com dado vazio seria vazia por construção. A positiva deixaria
     * uma fase futura acrescentar rótulo com o teste seguindo verde.
     */
    public function test_payload_nao_carrega_rotulo_de_tela_nem_cor(): void
    {
        [$e, $c] = $this->conta();
        $this->quatroEstagios($e, $c);
        $saida = $this->jornada($e, $c);

        $negadas = ['rotulo', 'sub', 'cta', 'passo', 'cor', 'color', 'label', 'step'];
        foreach ($saida['estagios'] as $est) {
            foreach ($negadas as $chave) {
                $this->assertArrayNotHasKey($chave, $est, "o estágio não pode carregar '{$chave}'");
            }
            foreach ($est['itens'] as $item) {
                foreach ($negadas as $chave) {
                    $this->assertArrayNotHasKey($chave, $item, "o item não pode carregar '{$chave}'");
                }
            }
        }

        $json = json_encode($saida, JSON_UNESCAPED_UNICODE);
        foreach (['Sem publicação', 'Em andamento', 'Fase 1 publicada', 'Fase 1 + Fase 2', 'Criar combo',
            'PRÓXIMO PASSO', 'Publicar Fase 1', 'Ver publicações', 'Aguardando a Fase 1', 'Metodologia completa'] as $literal) {
            $this->assertStringNotContainsStringIgnoringCase($literal, $json, "o literal de tela '{$literal}' vazou para o payload");
        }

        // ⚠️ E a varredura ENCONTRA o que existe: sem esta isca, um `json_encode`
        // quebrado faria todas as negativas passarem por construção.
        $this->assertStringContainsString('criar_combo', $json, 'isca: a chave do CTA tem de estar no JSON varrido');
        $this->assertStringContainsString('"chave":"done"', $json, 'isca: a varredura está olhando o payload de verdade');
    }

    /** Os quatro estágios preenchidos — para as varreduras não serem vazias por construção. */
    private function quatroEstagios(MlbEmpresa $e, ?Company $c): array
    {
        $this->produto($e, $c, 'NONE-1', ['origem' => PubProduto::ORIGEM_PORTAL]);
        $this->rascunho($this->produto($e, $c, 'DRAFT-1'), PubRascunho::DRAFT);
        $this->anuncios($this->rascunho($this->produto($e, $c, 'READY-1'), PubRascunho::PUBLISHED), ['gold_special']);
        $base = $this->produto($e, $c, 'DONE-1');
        $this->anuncios($this->rascunho($base, PubRascunho::PUBLISHED), ['gold_special', 'gold_pro']);
        $this->anuncios($this->rascunho($this->kit($base, 2), PubRascunho::PUBLISHED), ['gold_pro']);

        return [];
    }

    public function test_payload_nao_vaza_segredo_da_conta(): void
    {
        [$e, $c] = $this->conta();
        $this->quatroEstagios($e, $c);

        $json = json_encode($this->jornada($e, $c), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('APP_USR', $json);
        $this->assertStringNotContainsString('TG-', $json);
        $this->assertStringNotContainsString('access_token', $json);
        $this->assertStringNotContainsString('refresh_token', $json);
    }

    // ═══ 5. O item: shape, escalares, cobertura, nota, CTA e destino ═════════

    public function test_as_chaves_do_item_vem_na_ordem_do_contrato(): void
    {
        [$e, $c] = $this->conta();
        $this->quatroEstagios($e, $c);

        $saida = $this->jornada($e, $c);

        $vistos = 0;
        foreach ($saida['estagios'] as $est) {
            foreach ($est['itens'] as $item) {
                $this->assertSame(self::CAMPOS_DO_ITEM, array_keys($item));
                $this->assertSame(self::CELULAS_DA_COBERTURA, array_keys($item['cobertura']));
                $vistos++;
            }
        }
        $this->assertSame(4, $vistos, 'isca: os quatro estágios tinham de ter um item cada');
    }

    /** ⚠️ Campo-objeto renderizado como texto derrubou a página inteira em 07/10. */
    public function test_todo_campo_do_item_e_escalar_menos_a_cobertura(): void
    {
        [$e, $c] = $this->conta();
        $this->quatroEstagios($e, $c);

        $saida = $this->jornada($e, $c);

        $vistos = 0;
        foreach ($saida['estagios'] as $est) {
            foreach ($est['itens'] as $item) {
                foreach ($item as $chave => $valor) {
                    if ($chave === 'cobertura') {
                        $this->assertCount(4, $valor);
                        foreach ($valor as $celula => $ligada) {
                            $this->assertIsBool($ligada, "a célula '{$celula}' não é booleana");
                        }

                        continue;
                    }
                    $this->assertFalse(is_array($valor), "o campo '{$chave}' é array — foi assim que a tela ficou preta em 07/10");
                    $this->assertFalse(is_object($valor), "o campo '{$chave}' é objeto — a wave 3 renderiza isso como texto");
                }
                $this->assertIsInt($item['produto_id']);
                $this->assertNotSame('', $item['nota'], 'a nota nunca é vazia');
                $this->assertNotSame('—', $item['nota'], 'travessão não é informação');
                $vistos++;
            }
        }
        $this->assertSame(4, $vistos, 'isca: a varredura tinha de ver quatro itens');
    }

    public function test_cobertura_le_f1_do_base_e_f2_dos_kits(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'COBERTURA');
        $this->anuncios($this->rascunho($base, PubRascunho::PUBLISHED), ['gold_special']);
        $this->anuncios($this->rascunho($this->kit($base, 2), PubRascunho::PUBLISHED), ['gold_pro']);

        $item = $this->estagio($this->jornada($e, $c), 'done')['itens'][0];

        $this->assertSame(
            ['f1_classico' => true, 'f1_premium' => false, 'f2_classico' => false, 'f2_premium' => true],
            $item['cobertura'],
        );
    }

    public function test_base_sem_anuncio_tem_as_quatro_celulas_false_e_nunca_ausentes(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'SEM-ANUNCIO');

        $item = $this->estagio($this->jornada($e, $c), 'none')['itens'][0];

        $this->assertSame(
            ['f1_classico' => false, 'f1_premium' => false, 'f2_classico' => false, 'f2_premium' => false],
            $item['cobertura'],
            '"nunca publicamos" é false medido, nunca célula ausente',
        );
    }

    /** ⚠️ A cobertura de F2 soma TODOS os kits do base, não só o primeiro. */
    public function test_cobertura_de_f2_soma_os_anuncios_de_todos_os_kits(): void
    {
        [$e, $c] = $this->conta();
        $base = $this->produto($e, $c, 'FAMILIA');
        $this->anuncios($this->rascunho($base, PubRascunho::PUBLISHED), ['gold_special', 'gold_pro']);
        $this->anuncios($this->rascunho($this->kit($base, 2), PubRascunho::PUBLISHED), ['gold_special']);
        $this->anuncios($this->rascunho($this->kit($base, 3), PubRascunho::PUBLISHED), ['gold_pro']);

        $item = $this->estagio($this->jornada($e, $c), 'done')['itens'][0];

        $this->assertSame(
            ['f1_classico' => true, 'f1_premium' => true, 'f2_classico' => true, 'f2_premium' => true],
            $item['cobertura'],
            'o Premium do Kit 3 só aparece se a varredura passar por TODOS os kits',
        );
    }

    /** Família quebrada (`SET NULL`) não pode derrubar a tela. */
    public function test_id_de_kit_fora_da_lista_e_ignorado_sem_lancar(): void
    {
        $linhas = [$this->linha([
            'id' => 10, 'sku' => 'ORFAO', 'kits' => [999], 'anuncios' => [$this->anuncio('gold_special')],
            'status' => ['chave' => 'publicado', 'rotulo' => 'publicado', 'faltam' => 0],
        ])];

        $saida = $this->servico()->jornada(['chave' => 'empresa-1'], $linhas);
        $item = $this->estagio($saida, 'done')['itens'][0];

        $this->assertSame(['none' => 0, 'draft' => 0, 'ready' => 0, 'done' => 1], $this->totais($saida));
        $this->assertTrue($item['cobertura']['f1_classico']);
        $this->assertFalse($item['cobertura']['f2_classico'], 'kit fora do escopo não liga célula de F2');
        $this->assertFalse($item['cobertura']['f2_premium']);
    }

    /** `listing_type_id` novo do ML não liga célula nenhuma — inventar Clássico seria afirmar o não medido. */
    public function test_listing_type_desconhecido_nao_liga_celula_e_nao_lanca(): void
    {
        $linhas = [$this->linha([
            'id' => 11, 'sku' => 'TIPO-NOVO',
            'anuncios' => [$this->anuncio('gold_platinum'), ['ml_item_id' => 'MLB1', 'listing_type_id' => null]],
            'status' => ['chave' => 'publicado', 'rotulo' => 'publicado', 'faltam' => 0],
        ])];

        $item = $this->estagio($this->servico()->jornada(['chave' => 'empresa-1'], $linhas), 'ready')['itens'][0];

        $this->assertSame(
            ['f1_classico' => false, 'f1_premium' => false, 'f2_classico' => false, 'f2_premium' => false],
            $item['cobertura'],
        );
    }

    public function test_cada_estagio_devolve_no_maximo_seis_itens_com_o_total_intacto(): void
    {
        [$e, $c] = $this->conta();
        for ($i = 1; $i <= 9; $i++) {
            $this->produto($e, $c, 'NONE-'.$i);
        }

        $estagio = $this->estagio($this->jornada($e, $c), 'none');

        $this->assertCount(6, $estagio['itens'], 'o painel do protótipo mostra 6; o resto vai no "ver todos"');
        $this->assertSame(9, $estagio['total'], 'o total conta o universo, não a amostra');
    }

    public function test_cta_chave_por_estagio_sem_rotulo_nenhum(): void
    {
        [$e, $c] = $this->conta();
        $this->quatroEstagios($e, $c);

        $saida = $this->jornada($e, $c);

        $this->assertSame('publicar_fase1', $this->estagio($saida, 'none')['itens'][0]['cta_chave']);
        $this->assertSame('continuar', $this->estagio($saida, 'draft')['itens'][0]['cta_chave']);
        $this->assertSame('criar_combo', $this->estagio($saida, 'ready')['itens'][0]['cta_chave']);
        $this->assertSame('ver_publicacoes', $this->estagio($saida, 'done')['itens'][0]['cta_chave']);
    }

    public function test_url_do_item_e_o_produto_em_ready_e_done_e_o_editor_em_none_e_draft(): void
    {
        [$e, $c] = $this->conta();
        $this->quatroEstagios($e, $c);

        $saida = $this->jornada($e, $c);
        $chave = 'empresa-'.$e->id;

        foreach (['none', 'draft'] as $estagio) {
            $item = $this->estagio($saida, $estagio)['itens'][0];
            $this->assertSame(
                route('mlb.anuncios.publicador.editor', ['produto' => $item['produto_id']]),
                $item['url'],
                "o item de {$estagio} tem de abrir o editor",
            );
        }
        foreach (['ready', 'done'] as $estagio) {
            $item = $this->estagio($saida, $estagio)['itens'][0];
            $this->assertSame(
                route('mlb.anuncios.publicador.produto', ['conta' => $chave, 'produto' => $item['produto_id']]),
                $item['url'],
                "o item de {$estagio} tem de abrir a tela do Produto",
            );
        }
    }

    public function test_ver_todos_url_leva_para_produtos_filtrada_por_estagio(): void
    {
        [$e, $c] = $this->conta();
        $this->produto($e, $c, 'UM');

        $saida = $this->jornada($e, $c);
        $chave = 'empresa-'.$e->id;

        $this->assertSame(
            route('mlb.anuncios.publicador.produtos', ['conta' => $chave, 'filtro' => 'rascunho']),
            $this->estagio($saida, 'none')['ver_todos_url'],
        );
        $this->assertSame(
            route('mlb.anuncios.publicador.produtos', ['conta' => $chave, 'filtro' => 'rascunho']),
            $this->estagio($saida, 'draft')['ver_todos_url'],
        );
        // ⚠️ `fase=so_base` é o MESMO par da linha "Prontos para a Fase 2" de hoje.
        $this->assertSame(
            route('mlb.anuncios.publicador.produtos', ['conta' => $chave, 'filtro' => 'publicados', 'fase' => 'so_base']),
            $this->estagio($saida, 'ready')['ver_todos_url'],
        );
        $this->assertStringContainsString('fase=so_base', $this->estagio($saida, 'ready')['ver_todos_url']);
        $this->assertSame(
            route('mlb.anuncios.publicador.produtos', ['conta' => $chave, 'filtro' => 'publicados']),
            $this->estagio($saida, 'done')['ver_todos_url'],
        );
        $this->assertStringNotContainsString('fase=', $this->estagio($saida, 'done')['ver_todos_url']);
    }

    /**
     * A nota é montada só de campo MEDIDO. ⚠️ Nada de "parado em Imagens há 3
     * dias": não existe coluna de etapa parada (divergência D-1, Fase 178).
     */
    public function test_nota_por_estagio_sai_de_campo_medido(): void
    {
        [$e, $c] = $this->conta();

        $this->produto($e, $c, 'DO-PORTAL', ['origem' => PubProduto::ORIGEM_PORTAL]);
        $this->produto($e, $c, 'MANUAL');
        $this->rascunho($this->produto($e, $c, 'FALTAM'), PubRascunho::DRAFT, [
            'step_state' => ['resumo' => ['bloqueios' => 3]],
        ]);
        $this->rascunho($this->produto($e, $c, 'CONFERIDO'), PubRascunho::VALIDATED);
        $this->rascunho($this->produto($e, $c, 'FALHOU'), PubRascunho::FAILED);
        $this->anuncios($this->rascunho($this->produto($e, $c, 'NO-AR'), PubRascunho::PUBLISHED), ['gold_special', 'gold_pro']);

        $saida = $this->jornada($e, $c);
        $notas = [];
        foreach ($saida['estagios'] as $est) {
            foreach ($est['itens'] as $item) {
                $notas[$item['sku']] = $item['nota'];
            }
        }

        $this->assertSame('Recebido do Portal do Cliente', $notas['DO-PORTAL']);
        $this->assertSame('Cadastro manual no Publicador', $notas['MANUAL']);
        $this->assertSame('Rascunho em preenchimento · faltam 3', $notas['FALTAM']);
        $this->assertSame('Rascunho conferido, pronto para publicar', $notas['CONFERIDO']);
        $this->assertSame('Tentativa de publicação não concluída', $notas['FALHOU']);
        $this->assertSame('1 unidade · 2 no ar', $notas['NO-AR']);

        // ⚠️ E o que o protótipo inventa NÃO pode aparecer.
        $json = json_encode($notas, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsStringIgnoringCase('parado em', $json);
        $this->assertStringNotContainsStringIgnoringCase('há 3 dias', $json);
        $this->assertStringNotContainsStringIgnoringCase('vendas na', $json);
    }

    /** "Medimos e é zero" não é "está fora do ar" (VG-18): `0 no ar` não é afirmado. */
    public function test_no_ar_sem_anuncio_gravado_nao_afirma_zero_no_ar(): void
    {
        [$e, $c] = $this->conta();
        $this->rascunho($this->produto($e, $c, 'PUB-SEM-ITEM'), PubRascunho::PUBLISHED);

        $item = $this->estagio($this->jornada($e, $c), 'ready')['itens'][0];

        $this->assertSame('1 unidade', $item['nota']);
        $this->assertStringNotContainsString('0 no ar', $item['nota']);
    }

    // ═══ 6. O custo ══════════════════════════════════════════════════════════

    /**
     * ⚠️ `jornada()` não faz consulta NENHUMA — aqui o número certo é zero, não
     * "menos que N".
     *
     * ⚠️ **ISCA**: o mesmo teste conta as consultas de `produtosParaTela()` e
     * exige que esse número seja maior que zero. Sem ela, um `enableQueryLog()`
     * mal posicionado daria zero por construção e este gate passaria quebrado.
     */
    public function test_jornada_nao_faz_consulta_nenhuma(): void
    {
        [$e, $c] = $this->conta();
        for ($i = 1; $i <= 6; $i++) {
            $base = $this->produto($e, $c, 'P-'.$i);
            $this->rascunho($base, PubRascunho::PUBLISHED);
            $this->kit($base, 2);
        }
        $alvo = $this->alvo($e);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $produtos = $this->programas()->produtosParaTela($e, $c, $alvo['chave']);
        $consultasDaLista = count(DB::getQueryLog());

        DB::flushQueryLog();
        $saida = $this->servico()->jornada($alvo, $produtos);
        $consultasDaJornada = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(12, $produtos, 'isca: a lista tinha de ter os 12 produtos');
        $this->assertGreaterThan(0, $consultasDaLista, 'ISCA: o contador está quebrado — a lista pesada não pode custar zero');
        $this->assertSame(0, $consultasDaJornada, 'a lista chega pronta: a Jornada não consulta nada');
        $this->assertSame(6, $saida['total']);
        $this->assertFecha($saida);
    }
}
