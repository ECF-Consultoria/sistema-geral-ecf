<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\PubAprovacaoOferta;
use App\Models\PubFilaPublicacao;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 186, plano 04 — A OFERTA DO PORTÃO NO GRAFO REAL, e a disciplina da migration que a cria.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **andamento independente** — alguém gravar o andamento por PRODUTO. O Clássico e o Premium
 *     passariam a andar juntos, e aprovar um publicaria o outro sem conferência.
 *   · **DADO-14** — a fila de uma conta alcançar oferta de OUTRA empresa (T-186-14). ⚠️ A oferta
 *     da outra empresa está **PREENCHIDA** no fixture: asserção de ausência com fixture vazio é
 *     vazia por construção (GATE-13), e foi a classe de falha que a wave 1 mediu quatro vezes.
 *   · **DADO-03** — alguém copiar `validacao_id`/`plano_hash`/`revisao`/`ciente` para cá
 *     (T-186-15): seriam duas verdades sobre a MESMA conferência, livres para divergir.
 *   · **`precisa_revisar`** — alguém transformar em coluna o que é VALOR do `status` da fila.
 *     ⚠️ Aqui a asserção certa **não** é `hasColumn(...) === false`: aquela coluna nunca existiu
 *     em tabela nenhuma, então a negativa é verdadeira por ausência e **vazia por construção**.
 *     A prova que vale é que aquele `status` segue sendo a única fonte, **com isca em `ciente`**.
 *   · **achado S2 (`conjunto_id`)** — a oferta voltar a apontar para UM conjunto. As fotos dos
 *     outros grupos e as `GENERAL` sairiam do bloqueio e a oferta seria aprovável com foto que
 *     ninguém olhou — e **aprovar já publica, sem desfazer**. ⚠️ Esta negativa **NÃO** é vazia
 *     por construção: a tabela é criada POR ESTE PLANO, e a isca (`versao === true`) prova que a
 *     sonda enxerga.
 *   · **TERR-07** — alguém estender o gate D-11 à área nova ou afrouxá-lo na lista.
 *   · **a migration** — 1059 (nome acima de 64), 1830 (`nullOnDelete` sem `nullable`), `enum` (o
 *     CHECK é enforçado no SQLite e cega a suíte) e `try/catch` em volta de DDL. Os três
 *     primeiros o SQLite **não pega**, e o deploy quebra com a tabela JÁ criada.
 *
 * ⚠️ TODA SONDA TEM **ISCA**. E duas armadilhas específicas da wave 1 estão evitadas aqui por
 * escrito: (1) o gate de diff **sem range** compara a ÁRVORE e sai **vazio sempre** — por isso o
 * diff usa `<partida>..HEAD` e tem isca num arquivo que ESTE plano mudou; (2) a sonda de
 * `dropForeign` antes de `dropIndex` roda sobre **ZERO casos** numa migration de `create`, e gate
 * que roda sobre zero casos não prova nada — então o invariante medido é o do `down()` de
 * `create`.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class OfertaDoPortaoTest extends TestCase
{
    use RefreshDatabase;

    /** Clássico e Premium — `EstruturaPublicacao::LISTING_TYPES`. */
    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    /** A migration deste plano. */
    private const MIGRATION = 'database/migrations/2026_10_15_100200_create_pub_aprovacao_ofertas_table.php';

    /**
     * ⚠️ ISCA DE CAMINHO. 5 caminhos fantasma em 4 fases deste projeto: migration que não existe
     * faria TODA sonda de texto devolver "nenhuma violação" e o gate ficaria verde medindo NADA.
     */
    private const MIGRATION_FANTASMA = 'database/migrations/2026_10_15_999999_esta_migration_nao_existe.php';

    /** O commit de PARTIDA deste plano (wave 2), medido na primeira ação do executor. */
    private const PARTIDA = '3b790d21';

    /** O gate D-11/D-22 que este plano NÃO toca (TERR-07). */
    private const GATE_D11 = 'tests/js/estrutura-meus-anuncios.test.js';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture: produto -> rascunho -> 2 alvos -> 2 ofertas do portão.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{0: PubAprovacaoOferta, 1: PubAprovacaoOferta} [Clássico, Premium] */
    private function asDuasOfertas(Company $empresa, string $sku = 'CAD-01'): array
    {
        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => $sku,
            'nome' => 'Cadeira ' . $sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);

        $rascunho = PubRascunho::create(['produto_id' => $produto->id]);

        $ofertas = [];
        foreach ([self::CLASSICO, self::PREMIUM] as $i => $tipo) {
            $alvo = PubRascunhoAlvo::create(['rascunho_id' => $rascunho->id, 'listing_type_id' => $tipo, 'posicao' => $i]);

            $ofertas[] = PubAprovacaoOferta::create([
                'alvo_id' => $alvo->id,
                'rascunho_id' => $rascunho->id,
                'produto_id' => $produto->id,
                'company_id' => $empresa->id,
            ]);
        }

        return $ofertas;
    }

    private function servico(): AndamentoDaOfertaService
    {
        return app(AndamentoDaOfertaService::class);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // A granularidade, vista do grafo: UMA LINHA POR ALVO, e as duas ofertas são independentes
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_as_duas_ofertas_do_rascunho_tem_andamento_independente(): void
    {
        $empresa = Company::factory()->create();
        [$classico, $premium] = $this->asDuasOfertas($empresa);

        // A oferta do portão é a extensão 1:1 do ALVO que já existe.
        $this->assertSame(self::CLASSICO, $classico->alvo->listing_type_id);
        $this->assertSame(self::PREMIUM, $premium->alvo->listing_type_id);
        $this->assertSame($classico->rascunho_id, $premium->rascunho_id, 'as duas ofertas são do MESMO rascunho');

        $this->servico()->mover($classico, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        // ⚠️ Mover uma NÃO move a outra: é por oferta que se aprova e se publica.
        $this->assertSame(PubAprovacaoOferta::AGUARDANDO_APROVACAO, $classico->fresh()->andamento);
        $this->assertSame(PubAprovacaoOferta::PREPARANDO, $premium->fresh()->andamento, 'mover o Clássico moveu o Premium — o andamento virou por PRODUTO');

        // E `alvo_id` é UNIQUE: não há como existir duas ofertas do portão para o mesmo alvo.
        $this->assertTrue($this->temUniquePorAlvo(), 'o unique de `alvo_id` desapareceu — a extensão deixaria de ser 1:1');
        $this->assertSame(2, PubAprovacaoOferta::where('rascunho_id', $classico->rascunho_id)->count());
    }

    public function test_o_default_do_modelo_e_o_default_da_coluna_sao_o_mesmo_com_isca(): void
    {
        // ⚠️⚠️ ESTE GATE NASCEU DE UM DEFEITO REAL, medido em 14/10/2026 ao rodar o GREEN da
        // Task 3: o Eloquent **não lê o default do banco**, então `create([...])` sem `andamento`
        // devolvia um objeto com `andamento` **null** em memória enquanto a linha nascia
        // `'preparando'` no banco. A primeira transição lia origem `''` e era recusada como
        // ilegal. "A oferta nasce em preparando" só era verdade DEPOIS de um `fresh()`.
        //
        // O conserto repete o default em dois lugares (coluna e `$attributes`), e é por isso que
        // este gate existe: os dois podem divergir, e divergir aqui volta a produzir o mesmo bug.
        // O que precisaria estar errado para ele falhar: alguém mudar o default de um lado só.
        $empresa = Company::factory()->create();
        [$classico] = $this->asDuasOfertas($empresa);

        // EM MEMÓRIA, sem `fresh()` — é esta leitura que estava errada.
        $this->assertSame(PubAprovacaoOferta::PREPARANDO, $classico->andamento);
        $this->assertSame(PubAprovacaoOferta::ORIGEM_AUTO, $classico->origem);
        $this->assertSame(1, $classico->versao);
        $this->assertFalse($classico->tocado);

        // E NO BANCO, pela reconsulta: os dois concordam.
        $this->assertSame(PubAprovacaoOferta::PREPARANDO, $classico->fresh()->andamento);
        $this->assertSame(PubAprovacaoOferta::ORIGEM_AUTO, $classico->fresh()->origem);
        $this->assertSame(1, $classico->fresh()->versao);

        // ⚠️ E a comparação DIRETA contra o default declarado na COLUNA, lido do schema vivo —
        // não contra outra constante do PHP (comparar o PHP com o PHP não acusaria divergência
        // nenhuma).
        $esperados = ['andamento' => 'preparando', 'origem' => 'auto', 'versao' => '1', 'tocado' => '0'];
        $lidos = 0;
        foreach (Schema::getColumns('pub_aprovacao_ofertas') as $coluna) {
            if (! array_key_exists($coluna['name'], $esperados)) {
                continue;
            }
            $lidos++;
            $default = trim((string) ($coluna['default'] ?? ''), "'\"");
            // O SQLite guarda booleano como 0/1 e o MariaDB também; `versao` vem como texto.
            $this->assertSame($esperados[$coluna['name']], $default, "o default da COLUNA `{$coluna['name']}` divergiu do default do modelo");
        }

        // ISCA: a sonda leu os 4 defaults de verdade. Zero lidos faria o laço rodar sobre nada.
        $this->assertSame(4, $lidos, 'ISCA CEGA: a sonda não leu os 4 defaults da tabela.');

        // ISCA do outro lado: uma coluna SEM default continua sem default (a sonda não inventa).
        foreach (Schema::getColumns('pub_aprovacao_ofertas') as $coluna) {
            if ($coluna['name'] === 'ml_item_id') {
                $this->assertNotSame('preparando', trim((string) ($coluna['default'] ?? ''), "'\""), 'ISCA CEGA: a sonda devolve o mesmo default para qualquer coluna.');
            }
        }
    }

    /** O unique de `alvo_id`, lido do SCHEMA VIVO (SQLite dos testes). */
    private function temUniquePorAlvo(): bool
    {
        foreach (Schema::getIndexes('pub_aprovacao_ofertas') as $indice) {
            if (($indice['unique'] ?? false) && ($indice['columns'] ?? []) === ['alvo_id']) {
                return true;
            }
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // DADO-14 — a fila por conta NÃO alcança oferta de outra empresa (T-186-14)
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_fila_da_conta_nao_alcanca_oferta_de_outra_empresa_preenchida(): void
    {
        $minha = Company::factory()->create(['name' => 'Minha Empresa']);
        $outra = Company::factory()->create(['name' => 'Empresa da Outra Conta']);

        [$meuClassico, $meuPremium] = $this->asDuasOfertas($minha, 'MINHA-01');

        // ⚠️⚠️ A OFERTA DA OUTRA EMPRESA ESTÁ **PREENCHIDA**, e no MESMO andamento que eu vou
        // consultar. Asserção de ausência com fixture vazio é vazia por construção (GATE-13):
        // sem estas linhas, a consulta poderia estar devolvendo tudo e o teste passaria.
        [$alheioClassico, $alheioPremium] = $this->asDuasOfertas($outra, 'ALHEIA-01');
        foreach ([$alheioClassico, $alheioPremium] as $alheia) {
            $this->servico()->mover($alheia, PubAprovacaoOferta::AGUARDANDO_APROVACAO);
        }
        $this->servico()->mover($meuClassico, PubAprovacaoOferta::AGUARDANDO_APROVACAO);

        // ISCA: as 4 ofertas existem de verdade no banco.
        $this->assertSame(4, PubAprovacaoOferta::count(), 'ISCA CEGA: o fixture da outra empresa não entrou no banco.');
        $this->assertSame(2, PubAprovacaoOferta::where('company_id', $outra->id)->count());

        $minhaFila = $this->servico()->filaDaConta($minha->id)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$meuClassico->id, $meuPremium->id], $minhaFila, 'a fila da minha conta alcançou oferta de outra empresa');
        $this->assertNotContains($alheioClassico->id, $minhaFila);
        $this->assertNotContains($alheioPremium->id, $minhaFila);

        // E no sentido inverso — a fila da OUTRA conta não alcança a minha. Sem esta direção, uma
        // consulta que devolvesse sempre as ofertas da primeira empresa passaria acima.
        $filaAlheia = $this->servico()->filaDaConta($outra->id)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$alheioClassico->id, $alheioPremium->id], $filaAlheia);

        // A faixa de andamento usa a MESMA base recortada: o recorte não se perde no caminho.
        $faixa = array_column($this->servico()->faixaDeAndamento($this->servico()->filaDaConta($minha->id)), 'total', 'chave');
        $this->assertSame(2, $faixa['todas'], 'a faixa contou oferta de outra empresa');
        $this->assertSame(1, $faixa['aguardando_aprovacao']);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // DADO-03 — as 4 COLUNAS da fila não foram copiadas (T-186-15)
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_as_quatro_colunas_de_conferencia_da_fila_nao_estao_na_oferta_com_isca(): void
    {
        // ⚠️ ISCA DA SONDA: se `hasColumn` estiver cego (tabela ausente, driver mudo), ele acha
        // que NADA existe e os `=== false` abaixo passariam por engano. `versao` TEM de existir.
        $this->assertTrue(Schema::hasColumn('pub_aprovacao_ofertas', 'versao'), 'ISCA CEGA: a sonda não achou nem `versao`, que existe — os `=== false` abaixo não provariam nada.');
        $this->assertTrue(Schema::hasColumn('pub_aprovacao_ofertas', 'fila_item_id'), 'ISCA CEGA: a sonda não achou nem `fila_item_id`.');

        // São **4 COLUNAS**, e `precisa_revisar` NÃO entra nesta lista (ver o teste seguinte).
        foreach (['validacao_id', 'plano_hash', 'revisao', 'ciente'] as $daFila) {
            $this->assertFalse(
                Schema::hasColumn('pub_aprovacao_ofertas', $daFila),
                "`{$daFila}` foi COPIADO para `pub_aprovacao_ofertas`: são duas verdades sobre a MESMA conferência, livres para divergir (DADO-03)"
            );

            // ⚠️ E a ISCA DO OUTRO LADO: a coluna EXISTE na fila. Sem isto, "não está aqui"
            // poderia significar "não está em lugar nenhum", e a prova não seria de que a oferta
            // APONTA — seria de que ninguém guarda.
            $this->assertTrue(Schema::hasColumn('pub_fila_publicacao_itens', $daFila), "ISCA CEGA: `{$daFila}` não está nem na fila — a sonda de coluna não está medindo.");
        }

        // E o APONTAMENTO funciona de verdade: a oferta lê as 4 pelo `fila_item_id`.
        $empresa = Company::factory()->create();
        [$classico, $premium] = $this->asDuasOfertas($empresa);

        $fila = PubFilaPublicacao::create(['conta_chave' => 'company-' . $empresa->id, 'conta_ativa' => 'company-' . $empresa->id]);
        $item = PubFilaPublicacaoItem::create([
            'fila_id' => $fila->id,
            'produto_id' => $classico->produto_id,
            'rascunho_id' => $classico->rascunho_id,
            'produto_ativo' => $classico->produto_id,
            'posicao' => 1,
            'validacao_id' => null,
            'plano_hash' => str_repeat('a', 64),
            'revisao' => 7,
            'ciente' => true,
        ]);

        // ⚠️ As DUAS ofertas do produto apontam para o MESMO item de fila, e isso é CORRETO: a
        // fila publica o PRODUTO inteiro (unique `pubfilai_produto_ativo_uq` = 1 fila por produto).
        $classico->update(['fila_item_id' => $item->id]);
        $premium->update(['fila_item_id' => $item->id]);

        $this->assertSame(7, $classico->fresh()->filaItem->revisao);
        $this->assertTrue($classico->fresh()->filaItem->ciente);
        $this->assertSame($item->id, $premium->fresh()->fila_item_id);
        $this->assertSame($classico->fresh()->fila_item_id, $premium->fresh()->fila_item_id);
    }

    public function test_precisa_revisar_segue_sendo_valor_do_status_da_fila_com_isca_em_ciente(): void
    {
        // ⚠️⚠️ A ASSERÇÃO QUE **NÃO** SE FAZ AQUI: `hasColumn(..., 'precisa_revisar') === false`.
        // Aquela coluna **nunca existiu em tabela nenhuma** — a negativa é verdadeira por
        // ausência e **VAZIA POR CONSTRUÇÃO**. Conferido no schema vivo em 14/10/2026: a fila tem
        // `ciente`, `plano_hash`, `revisao`, `status`, `validacao_id`.
        //
        // ⚠️ ISCA: `ciente` É coluna de verdade e TEM de ser achada. Sem ela, "não é coluna"
        // seria indistinguível de "a sonda não acha coluna nenhuma".
        $this->assertTrue(Schema::hasColumn('pub_fila_publicacao_itens', 'ciente'), 'ISCA CEGA: a sonda não acha nem `ciente`, que é coluna — nada abaixo prova nada.');
        $this->assertTrue(Schema::hasColumn('pub_fila_publicacao_itens', 'status'), 'ISCA CEGA: a sonda não acha nem `status`.');

        // A PROVA QUE VALE (1): `precisa_revisar` é um VALOR do `status` da fila, e o `status` é
        // a única fonte dele.
        $this->assertSame('precisa_revisar', PubFilaPublicacaoItem::PRECISA_REVISAR);

        $empresa = Company::factory()->create();
        $produto = PubProduto::create(['company_id' => $empresa->id, 'sku' => 'PR-01', 'nome' => 'PR', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $fila = PubFilaPublicacao::create(['conta_chave' => 'company-' . $empresa->id, 'conta_ativa' => 'company-' . $empresa->id]);
        $item = PubFilaPublicacaoItem::create([
            'fila_id' => $fila->id,
            'produto_id' => $produto->id,
            'posicao' => 1,
            'status' => PubFilaPublicacaoItem::PRECISA_REVISAR,
        ]);

        $this->assertSame('precisa_revisar', $item->fresh()->status, 'o valor ficou guardado onde tem de ficar: no `status` da fila');
        $this->assertFalse($item->fresh()->vivo(), '`precisa_revisar` não ocupa o produto');
        $this->assertContains(PubFilaPublicacaoItem::PRECISA_REVISAR, PubFilaPublicacaoItem::SEM_PUBLICAR);

        // A PROVA QUE VALE (2): **nenhuma** coluna do portão guarda este valor — medido sobre
        // TODAS as colunas da tabela, não sobre uma lista escrita à mão.
        $colunas = Schema::getColumnListing('pub_aprovacao_ofertas');
        $this->assertNotEmpty($colunas, 'ISCA CEGA: a sonda não listou coluna nenhuma da oferta.');
        foreach ($colunas as $coluna) {
            $this->assertStringNotContainsString('revisar', $coluna, "a coluna `{$coluna}` do portão parece guardar `precisa_revisar` — ele é VALOR do status da fila");
            $this->assertStringNotContainsString('revisao', $coluna, "a coluna `{$coluna}` do portão copiou `revisao` da fila (DADO-03)");
        }

        // A PROVA QUE VALE (3): o literal aparece no CÓDIGO só como constante/valor da fila, e
        // nunca no modelo nem no serviço do portão.
        foreach (['app/Models/PubAprovacaoOferta.php', 'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php'] as $meu) {
            $codigo = $this->semComentarios(file_get_contents(base_path($meu)));
            $this->assertStringNotContainsString('precisa_revisar', $codigo, "`precisa_revisar` apareceu no CÓDIGO de {$meu} — o portão não guarda nem reescreve esse valor");
        }

        // ISCA do tokenizador neste par: o literal ESTÁ no docblock (a explicação de por que ele
        // não é coluna), e é por isso que a leitura tem de ser sem comentários.
        $this->assertStringContainsString('precisa_revisar', file_get_contents(base_path('app/Models/PubAprovacaoOferta.php')), 'ISCA: o docblock deixou de explicar o caso — e então o gate acima ficaria verde por outro motivo.');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Achado S2 — a oferta NÃO tem `conjunto_id`, e não há par (oferta_id, imagem_id)
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_oferta_nao_tem_conjunto_id_e_nenhuma_tabela_do_portao_tem_oferta_id_com_imagem_id(): void
    {
        // ⚠️ ISCA: a tabela é criada POR ESTE PLANO, então esta negativa **não** é vazia por
        // construção — mas só com a isca para provar que a sonda enxerga.
        $this->assertTrue(Schema::hasTable('pub_aprovacao_ofertas'), 'ISCA CEGA: a tabela da oferta não existe — tudo abaixo seria vazio por construção.');
        $this->assertTrue(Schema::hasColumn('pub_aprovacao_ofertas', 'versao'), 'ISCA CEGA: a sonda não achou nem `versao`.');

        $this->assertFalse(
            Schema::hasColumn('pub_aprovacao_ofertas', 'conjunto_id'),
            'a oferta voltou a ter `conjunto_id`: com uma FK só, as fotos dos outros grupos e as GERAIS ficam FORA do bloqueio e a oferta pode ser aprovada com foto não conferida — e aprovar JÁ PUBLICA, sem desfazer'
        );

        // E nada de pivô N:N disfarçado: nenhuma tabela do portão declara o par
        // (oferta_id, imagem_id), que é o que tornaria o compartilhamento Clássico↔Premium uma
        // COMBINAÇÃO em vez de uma estrutura.
        // ⚠️ REAPONTADO EM 14/10/2026 PELO ORQUESTRADOR — a asserção final era OVER-BROAD e
        // proibia o que o desenho EXIGE. Ela cobrava `$comOferta === []`, isto é, que NENHUMA
        // tabela do portão tivesse `oferta_id`. Mas DADO-10 (histórico por oferta) e DADO-11
        // (ajuste por oferta) PEDEM tabelas-filhas por oferta, e o schema vivo já tem CINCO
        // legítimas: ajustes, eventos, excecoes, fretes e composicoes.
        //
        // ⚠️ E o modo de falhar era duplo, o que a torna um caso de arquivo:
        //   · quando foi escrita (186-04), NÃO havia tabela-filha nenhuma ⇒ era VAZIA POR
        //     CONSTRUÇÃO, passava sem medir nada;
        //   · no instante em que o 186-05 criou as filhas, virou FALSO POSITIVO e reprovou
        //     trabalho correto.
        // Vazia primeiro, falso positivo depois — pelo MESMO texto, sem ninguém mexer nela.
        //
        // O invariante de verdade é o PAR `(oferta_id, imagem_id)`: é ele que faria a aprovação
        // da FOTO ser por oferta, deixando Clássico e Premium divergirem numa foto. Medido no
        // schema vivo: nenhuma das 11 tabelas tem o par.
        //
        // ⚠️ A contagem também era frágil: havia uma asserção POR tabela com `oferta_id`, logo o
        // número subia a cada filha nova (a mesma armadilha que o gate do glob em
        // `ConjuntoCompartilhadoTest`). Agora os infratores são COLETADOS e as asserções são
        // DUAS, de contagem fixa.
        $comOferta = [];
        $comPar    = [];

        foreach (Schema::getTableListing() as $tabela) {
            $nome = str_contains($tabela, '.') ? substr($tabela, strrpos($tabela, '.') + 1) : $tabela;
            if (! str_starts_with($nome, 'pub_aprovacao')) {
                continue;
            }
            $colunas = Schema::getColumnListing($nome);
            if (in_array('oferta_id', $colunas, true)) {
                $comOferta[] = $nome;
                if (in_array('imagem_id', $colunas, true)) {
                    $comPar[] = $nome;
                }
            }
        }

        // ⚠️ ISCA DA VARREDURA: ela enxerga as tabelas do portão de verdade. Sem isto, o laço
        // acima poderia rodar sobre ZERO tabelas e passar sem medir nada.
        $doPortao = array_values(array_filter(
            array_map(fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t, Schema::getTableListing()),
            fn ($t) => str_starts_with($t, 'pub_aprovacao')
        ));
        $this->assertGreaterThanOrEqual(6, count($doPortao), 'ISCA CEGA: a varredura não achou as tabelas do portão (as 5 do plano 186-03 + a deste).');
        $this->assertContains('pub_aprovacao_ofertas', $doPortao);
        $this->assertContains('pub_aprovacao_conjuntos', $doPortao);
        // O INVARIANTE DE VERDADE: ninguém tem o par. Uma asserção, contagem fixa, e a mensagem
        // nomeia TODOS os infratores de uma vez em vez de parar no primeiro.
        $this->assertSame(
            [],
            $comPar,
            'tabela(s) do portão com o par (oferta_id, imagem_id): ' . implode(', ', $comPar)
            . '. A aprovação da FOTO passaria a ser por oferta, e Clássico e Premium poderiam '
            . 'divergir numa foto — o compartilhamento deixaria de ser estrutural.'
        );

        // ⚠️ ISCA INVERTIDA, e ela é a parte que a asserção antiga tinha de cabeça para baixo:
        // tabela-filha POR OFERTA é LEGÍTIMA e EXIGIDA (DADO-10 histórico, DADO-11 ajuste). Se
        // esta lista vier vazia, não é "tudo certo": é a varredura cega, ou alguém apagou o
        // histórico/ajuste por oferta. As legítimas medidas no schema vivo em 14/10/2026:
        // ajustes, eventos, excecoes, fretes, composicoes.
        $this->assertNotEmpty(
            $comOferta,
            'ISCA INVERTIDA: nenhuma tabela do portão tem `oferta_id`. Ou a varredura está cega, '
            . 'ou o histórico/ajuste por oferta (DADO-10/DADO-11) desapareceu.'
        );

        // A relação é 1:N e é por `rascunho_id` nos DOIS lados — é daí que o compartilhamento
        // Clássico↔Premium sai ESTRUTURAL: não existe coluna onde as duas ofertas divirjam.
        $empresa = Company::factory()->create();
        [$classico, $premium] = $this->asDuasOfertas($empresa);
        $this->assertSame('rascunho_id', $classico->conjuntos()->getForeignKeyName());
        $this->assertSame($classico->rascunho_id, $premium->rascunho_id);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // TERR-07 — a fronteira do D-11: este plano não estende nem afrouxa o gate de Meus Anúncios
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_terr_07_o_gate_d11_de_meus_anuncios_nao_foi_tocado_por_este_plano(): void
    {
        // `test -e` naquele caminho: arquivo ausente faria o diff sair vazio e o gate ficaria
        // verde tendo APAGADO o gate alheio.
        $this->assertFileExists(base_path(self::GATE_D11));

        // ⚠️⚠️ COM **RANGE**. `git diff --stat -- <caminho>` depois de commitar compara a
        // ÁRVORE e sai **VAZIO POR CONSTRUÇÃO** — é a armadilha medida na wave 1
        // (`feedback_gate_vazio_por_construcao`).
        $diff = $this->gitDiff(self::GATE_D11);
        $this->assertSame('', $diff, "este plano mexeu em {$diff} — o gate D-11/D-22 é INTOCÁVEL (TERR-07), e o D-11 vale para a LISTA: a FILA escreve");

        // ⚠️ ISCA DO DIFF: o MESMO comando, no arquivo que ESTE plano criou, tem de sair NÃO
        // vazio. Sem esta isca, um `git` que falhasse em silêncio devolveria string vazia para
        // tudo e o gate seria verde permanente — medindo nada.
        $this->assertNotSame('', $this->gitDiff(self::MIGRATION), 'ISCA CEGA: o diff por range não acha nem a migration que este plano criou.');

        // E o gate não foi AFROUXADO: as asserções do D-11 seguem no arquivo, por SEMÂNTICA.
        $fonte = file_get_contents(base_path(self::GATE_D11));
        foreach (['router\.put\(', 'router\.delete\(', 'router\.patch\(', 'useForm', 'router\.visit\('] as $vetado) {
            $this->assertStringContainsString($vetado, $fonte, "a asserção do D-11 contra `{$vetado}` saiu do gate de Meus Anúncios");
        }
        $this->assertStringContainsString('de 86', $fonte, 'o D-22 (base 86, nunca renormalizada) saiu do gate');
        $this->assertStringContainsString("mlb\\.anuncios\\.meus\\.atualizar", $fonte);
    }

    /** `git diff <partida>..HEAD --name-only -- <caminho>`, com RANGE. */
    private function gitDiff(string $caminho): string
    {
        $saida = shell_exec(sprintf(
            'git -C %s diff %s..HEAD --name-only -- %s 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg(self::PARTIDA),
            escapeshellarg($caminho)
        ));

        return trim((string) $saida);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A DISCIPLINA DA MIGRATION (MIG-01..09)
    //
    // ⚠️ `MigrationDoPortaoGuardaTest` (plano 186-03) declara SÓ as duas migrations daquele
    // plano — conferido na constante `MIGRATIONS` dele. A migration deste plano fica FORA do
    // guarda dele, e por isso ela tem guarda PRÓPRIO aqui. Sem isto, a disciplina estaria
    // "coberta" por um gate que nunca a lê: a definição de passar por omissão.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /** O CÓDIGO sem os comentários, pelo tokenizador do PHP. */
    private function semComentarios(string $texto): string
    {
        $saida = '';

        foreach (token_get_all($texto) as $token) {
            if (! is_array($token)) {
                $saida .= $token;

                continue;
            }

            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $saida .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $saida .= $token[1];
        }

        return $saida;
    }

    private function textoDaMigration(): string
    {
        $abs = base_path(self::MIGRATION);
        $this->assertFileExists($abs, 'migration declarada e ausente no disco — toda sonda de texto abaixo mediria NADA');
        $this->assertFileDoesNotExist(base_path(self::MIGRATION_FANTASMA), 'ISCA DE CAMINHO: `file_exists` passou a devolver `true` para caminho inventado');

        return file_get_contents($abs);
    }

    public function test_guarda_nenhum_nome_de_indice_ou_fk_passa_de_64_medido_por_strlen_com_isca(): void
    {
        $texto = $this->textoDaMigration();

        // (1) Os nomes DECLARADOS no texto — `unique('col','nome')`, `index(...,'nome')`,
        // `foreign('col','nome')`. É o 1059 de verdade: nome acima de 64 deixa a migration
        // `Pending` com a tabela JÁ criada, o pior estado possível.
        preg_match_all("/->(?:unique|index|foreign)\(\s*(?:'[^']*'|\[[^\]]*\])\s*,\s*'([^']+)'\s*\)/", $texto, $m);
        $declarados = $m[1];

        // ⚠️ ISCA DA SONDA: ela tem de ACHAR os 13 nomes declarados. Zero achados faria o laço
        // rodar sobre lista vazia — verde, medindo nada (foi assim que `->foreignId?\(` casou
        // `foreignI` e não achou FK nenhuma na wave 1).
        $this->assertCount(13, $declarados, 'ISCA CEGA: a sonda não achou os 13 nomes declarados (7 índices + 6 FKs).');
        $this->assertContains('pubapof_alvo_uq', $declarados);
        $this->assertContains('pubapof_conta_andamento_ix', $declarados);
        $this->assertContains('pubapof_fila_item_fk', $declarados);

        foreach ($declarados as $nome) {
            $this->assertLessThanOrEqual(64, strlen($nome), "o nome DECLARADO `{$nome}` tem " . strlen($nome) . ' caracteres e estoura o limite do MariaDB (erro 1059)');
        }

        // ⚠️ O MAIOR, TRAVADO: mudar o schema sem reconferir o docblock reprova aqui. O 186-03
        // mediu o plano dele errando em 1 num número destes — por isso é `strlen`, nunca número
        // copiado à mão.
        $maior = array_reduce($declarados, fn ($c, $n) => strlen($n) > strlen((string) $c) ? $n : $c, '');
        $this->assertSame('pubapof_conta_andamento_ix', $maior);
        $this->assertSame(26, strlen($maior));

        // (2) Os nomes **PADRÃO DO LARAVEL** (`<tabela>_<coluna>_foreign`) — contados porque são
        // os que ESTOURAM. Nenhum é usado (toda FK é nomeada à mão), mas o cálculo trava o
        // crescimento do nome da tabela e das colunas.
        $tabela = 'pub_aprovacao_ofertas';
        $this->assertSame(21, strlen($tabela));
        $padrao = [];
        foreach (['alvo_id', 'rascunho_id', 'produto_id', 'responsavel_id', 'aprovado_por', 'fila_item_id'] as $coluna) {
            $this->assertTrue(Schema::hasColumn($tabela, $coluna), "a coluna `{$coluna}` sumiu — o cálculo do nome padrão mediria o nada");
            $padrao[$coluna] = strlen("{$tabela}_{$coluna}_foreign");
            $this->assertLessThanOrEqual(64, $padrao[$coluna]);
        }
        $this->assertSame(44, max($padrao), 'o maior nome PADRÃO do Laravel mudou — reconferir o docblock da migration');
        $this->assertSame(44, $padrao['responsavel_id']);

        // (3) `->constrained()` não é usado: ele é quem gera o nome padrão sem ninguém ver.
        $this->assertStringNotContainsString('constrained(', $this->semComentarios($texto), 'apareceu `->constrained()`: o nome da FK passaria a ser gerado, e é ele que estoura 64');

        // ISCA do limite: um nome de 65 TEM de ser acusado pela mesma régua.
        $this->assertGreaterThan(64, strlen(str_repeat('x', 65)), 'ISCA CEGA: a régua de 64 não acusa nem 65 caracteres.');
    }

    public function test_guarda_toda_fk_nullondelete_tem_coluna_nullable_explicito_com_isca(): void
    {
        $codigo = $this->semComentarios($this->textoDaMigration());

        // ⚠️ Nesta migration a coluna e a FK são statements SEPARADOS
        // (`unsignedBigInteger('x')->nullable();` e `foreign('x','nome')->...->nullOnDelete();`),
        // então o pareamento é pela SEMÂNTICA do nome da coluna — não por `explode(';')`.
        //
        // ⚠️⚠️ A ARMADILHA MEDIDA NA WAVE 1: `->foreignId?\(` casa `foreignI`, **não**
        // `foreign(`. O regex abaixo é testado contra um caso que DEVE casar, logo abaixo.
        $regexFk = "/->foreign\(\s*'([^']+)'\s*,\s*'[^']+'\s*\)(.*?);/s";
        preg_match_all($regexFk, $codigo, $m, PREG_SET_ORDER);

        // ISCA DA SONDA: as 6 FKs têm de ser achadas.
        $this->assertCount(6, $m, 'ISCA CEGA: a sonda não achou as 6 FKs — o pareamento rodaria sobre lista vazia.');

        $comNullOnDelete = [];
        foreach ($m as $fk) {
            if (str_contains($fk[2], 'nullOnDelete')) {
                $comNullOnDelete[] = $fk[1];
            }
        }

        // As 4 anuláveis: `produto_id`, `responsavel_id`, `aprovado_por`, `fila_item_id`.
        $this->assertEqualsCanonicalizing(['produto_id', 'responsavel_id', 'aprovado_por', 'fila_item_id'], $comNullOnDelete, 'ISCA CEGA: a sonda não separou as FKs `nullOnDelete` das CASCADE.');

        foreach ($comNullOnDelete as $coluna) {
            // ⚠️ O 1830 do MariaDB: `nullOnDelete` em coluna NOT NULL quebra o deploy, e o
            // SQLite **não pega**. O `nullable()` tem de estar EXPLÍCITO no texto.
            $this->assertMatchesRegularExpression(
                "/->unsignedBigInteger\('" . preg_quote($coluna, '/') . "'\)->nullable\(\)/",
                $codigo,
                "a FK `{$coluna}` é `nullOnDelete` e a coluna não tem `->nullable()` explícito (erro 1830, invisível no SQLite)"
            );

            // E no schema VIVO também é anulável — a leitura do texto e a do banco concordam.
            $this->assertTrue($this->colunaEAnulavel($coluna), "a coluna `{$coluna}` não é anulável no schema vivo");
        }

        // As 2 CASCADE são NOT NULL de propósito: oferta sem alvo não é oferta.
        foreach (['alvo_id', 'rascunho_id'] as $obrigatoria) {
            $this->assertFalse($this->colunaEAnulavel($obrigatoria), "`{$obrigatoria}` virou anulável — oferta sem alvo/rascunho não é oferta");
        }

        // ⚠️ ISCA DO REGEX, nas duas direções: um caso sintético que DEVE casar e um que NÃO.
        $deveCasar = "<?php \$t->foreign('quem_id', 'xx_fk')->references('id')->on('users')->nullOnDelete();";
        preg_match_all($regexFk, $deveCasar, $certo, PREG_SET_ORDER);
        $this->assertCount(1, $certo, 'ISCA CEGA: o regex de FK não casa nem o caso canônico — era o bug de `->foreignI`.');
        $this->assertSame('quem_id', $certo[0][1]);
        $this->assertStringContainsString('nullOnDelete', $certo[0][2]);

        $naoDeveCasar = "<?php \$t->index('quem_id', 'xx_ix');";
        preg_match_all($regexFk, $naoDeveCasar, $errado, PREG_SET_ORDER);
        $this->assertCount(0, $errado, 'ISCA: o regex de FK casou um `index()` — ele mediria a coisa errada.');
    }

    /** A coluna é anulável no SCHEMA VIVO (SQLite dos testes)? */
    private function colunaEAnulavel(string $coluna): bool
    {
        foreach (Schema::getColumns('pub_aprovacao_ofertas') as $c) {
            if (($c['name'] ?? null) === $coluna) {
                return (bool) ($c['nullable'] ?? false);
            }
        }

        $this->fail("coluna `{$coluna}` não existe em `pub_aprovacao_ofertas`");
    }

    public function test_guarda_a_migration_nao_usa_enum_nem_try_catch_e_o_up_e_idempotente(): void
    {
        $cru = $this->textoDaMigration();
        $codigo = $this->semComentarios($cru);

        // ⚠️⚠️ LER O CÓDIGO SEM COMENTÁRIOS NÃO É COSMÉTICO. O docblock desta migration
        // **explica** que não usa `enum` nem `try/catch` em volta de DDL. Um gate que conte a
        // palavra no arquivo inteiro casa com a frase que diz que a coisa não é usada e reprova
        // o PRÓPRIO CONSERTO (`feedback_gate_que_reprova_pelo_proprio_conserto`).
        $this->assertStringNotContainsString('enum', $codigo, 'MIG-04: `enum` no CÓDIGO — o CHECK é enforçado no SQLite dos testes e cegaria a suíte');
        $this->assertStringNotContainsString('try', $codigo, 'MIG-05: `try` no CÓDIGO — neste projeto um 1553 ficou engolido 2 meses dentro de um catch');
        $this->assertStringNotContainsString('catch', $codigo);

        // ISCA DO TOKENIZADOR (a sonda da qual as três asserções acima dependem): a palavra em
        // COMENTÁRIO desaparece e a em CÓDIGO sobrevive.
        $soComentario = "<?php\n// Sem `enum()` e sem try/catch em volta de DDL.\n\$t->string('andamento', 24);\n";
        $noCodigo = "<?php\n\$t->enum('andamento', ['a']);\n";
        $this->assertStringNotContainsString('enum', $this->semComentarios($soComentario), 'ISCA CEGA: o tokenizador não tirou a palavra que só aparecia no comentário.');
        $this->assertStringNotContainsString('try', $this->semComentarios($soComentario), 'ISCA CEGA: `try` do comentário sobreviveu — o gate reprovaria pelo próprio conserto.');
        $this->assertStringContainsString("\$t->string('andamento', 24)", $this->semComentarios($soComentario), 'ISCA: o tokenizador comeu o CÓDIGO junto com o comentário.');
        $this->assertStringContainsString('enum', $this->semComentarios($noCodigo), 'ISCA CEGA: a palavra em CÓDIGO tem de sobreviver, senão a sonda nunca acha nada.');

        // ⚠️ E a prova de que o docblock REALMENTE contém as palavras — senão "não achei no
        // código" seria verdade pelo motivo errado (o arquivo não falar do assunto).
        $this->assertStringContainsString('enum', $cru, 'ISCA: o docblock deixou de explicar por que não há `enum` — o gate acima ficaria verde por outro motivo');
        $this->assertStringContainsString('try/catch', $cru, 'ISCA: o docblock deixou de explicar por que não há try/catch');

        // MIG-05 — `up()` idempotente por `hasTable` (deploy que morre no meio completa o resto).
        $this->assertStringContainsString("Schema::hasTable('pub_aprovacao_ofertas')", $codigo, 'MIG-05: o `up()` deixou de ser idempotente');

        // MIG-07/DADO-01 — o docblock vem ANTES do código e TRAZ a decisão de granularidade e a
        // tabela de decisão de schema. É o lugar onde o critério 12 da fase fica registrado.
        $this->assertStringContainsString('DECISÃO DE SCHEMA', $cru);
        $this->assertStringContainsString('UMA LINHA POR `pub_rascunho_alvos`', $cru);
        $this->assertLessThan(strpos($cru, 'Schema::create('), strpos($cru, 'DECISÃO DE SCHEMA'), 'a tabela de decisão de schema tem de vir ANTES do código');

        // MIG-09 — nenhuma coluna renomeada, nenhum ALTER, nenhum backfill.
        foreach (['->change(', 'renameColumn(', 'dropColumn(', '->delete(', 'truncate('] as $destrutivo) {
            $this->assertStringNotContainsString($destrutivo, $codigo, "MIG-09: `{$destrutivo}` numa migration que só CRIA");
        }
    }

    public function test_guarda_o_down_derruba_a_tabela_e_a_sonda_de_dropforeign_rodaria_sobre_zero_casos(): void
    {
        $codigo = $this->semComentarios($this->textoDaMigration());

        $inicio = strpos($codigo, 'function down()');
        $this->assertNotFalse($inicio, 'ISCA CEGA: não achei o `down()` no código.');
        $down = substr($codigo, $inicio);

        // ⚠️⚠️ A SONDA QUE **NÃO** ENTRA SOZINHA: "`dropForeign([` antes de cada `dropIndex`"
        // roda sobre **ZERO casos** numa migration de `create` — o `dropIfExists` da tabela
        // inteira subsume o par, que é a receita de migration ADITIVA. **Gate que roda sobre
        // zero casos não prova nada**, então a sonda fica aqui COM ISCA e o invariante que VALE
        // tem asserção própria, abaixo.
        $this->assertSame(0, substr_count($down, 'dropIndex('), 'apareceu `dropIndex` num `down()` de `create` — então o par com `dropForeign` passa a ser obrigatório (erro 1553)');
        $this->assertSame(0, substr_count($down, 'dropForeign('), 'apareceu `dropForeign` sem `dropIndex`: a receita aditiva entrou numa migration de `create`');

        // ISCA DA SONDA DE ORDEM: num texto sintético ADITIVO ela TEM de achar os dois e ver a
        // ordem. Sem esta isca, os dois `assertSame(0, ...)` acima seriam "verdade por
        // construção" e ninguém saberia que a sonda nunca mediu nada.
        $aditivoSintetico = "function down() { \$t->dropForeign(['x_id']); \$t->dropIndex('x_ix'); }";
        $this->assertSame(1, substr_count($aditivoSintetico, 'dropForeign(['), 'ISCA CEGA: a sonda não acha `dropForeign([` nem no caso aditivo.');
        $this->assertSame(1, substr_count($aditivoSintetico, 'dropIndex('), 'ISCA CEGA: a sonda não acha `dropIndex(` nem no caso aditivo.');
        $this->assertLessThan(strpos($aditivoSintetico, 'dropIndex('), strpos($aditivoSintetico, 'dropForeign(['), 'ISCA CEGA: a sonda de ORDEM não distingue antes de depois.');

        // ⚠️ O INVARIANTE QUE VALE PARA `create`: o `down()` derruba a TABELA (por
        // `dropIfExists`, nunca `drop`), e não derruba coluna.
        $this->assertSame(1, substr_count($down, 'dropIfExists('));
        $this->assertStringContainsString("Schema::dropIfExists('pub_aprovacao_ofertas')", $down);
        $this->assertStringNotContainsString('dropColumn(', $down);

        // Uma tabela só: não há ordem filha→mãe a respeitar — e nenhuma das 5 MÃES é derrubada.
        foreach (['pub_rascunho_alvos', 'pub_rascunhos', 'pub_produtos', 'users', 'pub_fila_publicacao_itens'] as $mae) {
            $this->assertStringNotContainsString("dropIfExists('{$mae}')", $down, "o `down()` derrubaria `{$mae}`, que é tabela de OUTRO plano");
            $this->assertTrue(Schema::hasTable($mae), "a tabela-mãe `{$mae}` não existe — a FK aponta para o vazio");
        }
    }
}
