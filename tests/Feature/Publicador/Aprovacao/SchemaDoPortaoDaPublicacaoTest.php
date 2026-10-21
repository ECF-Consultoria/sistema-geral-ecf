<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\PubAprovacaoOferta;
use App\Models\PubFilaPublicacaoItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * A PROVA DE QUE A FASE 193 **NÃO TEM MIGRATION** — plano `193-01`, tarefa 2.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * O planejamento da Fase 193 **mediu** que `pub_aprovacao_ofertas` já nasceu (Fase 186,
 * migration de 15/10/2026, **aplicada em produção**) com as três colunas de ligação de que a
 * 193 precisa. ⚠️ **Afirmação de planejador não é prova.** Este arquivo transforma a medição em
 * **asserção sobre o schema VIVO**, para que a decisão *"sem migration"* não dependa de alguém
 * ter lido direito — e para que apagar/afrouxar qualquer uma das três **reprove**.
 *
 * ⚠️⚠️ **A PERGUNTA QUE GUARDA ESTE ARQUIVO** (learnings, §4d.1): *"o que precisaria estar
 * errado no mundo para estes casos falharem?"* ➡️ alguém **apagar** `fila_item_id`, **mudar** a
 * nulabilidade, **tirar** o índice ou a FK, **copiar** uma das 4 colunas de conferência da fila
 * para a oferta, ou **escrever migration nova** mexendo nesta tabela. Nenhuma asserção daqui é
 * decorativa: todas falham em pelo menos um desses mundos.
 *
 * ── A FORMA DAS ASSERÇÕES (e por que ela é esta) ───────────────────────────────────────────────
 *
 * ⚠️ **TODAS são COLETORAS e de contagem FIXA.** Uma asserção **por coluna**, **por índice** ou
 * **por migration** é a armadilha §4b.2 do learnings: ela sobe a cada arquivo novo, e elevar
 * régua treina o executor seguinte a mexer em réguas. Aqui se **coletam** os infratores e se
 * fecha com `assertSame([], $infratores, <mensagem que NOMEIA todos>)`.
 *
 * ⚠️ **ISCA INVERTIDA em TODA negativa.** *"A coluna X não existe"* escrita contra uma varredura
 * **cega** é verde para sempre (§4b.1: *"nenhuma tabela do portão tem `oferta_id`"* foi escrita
 * vazia e depois reprovou trabalho correto). Então, de cada negativa, este arquivo prova também
 * **a outra ponta**: que a mesma sonda **ACUSA** quando o caso existe de verdade.
 *
 * ── ⚠️⚠️ A ARMADILHA DO DOCBLOCK, MEDIDA NESTA ÁRVORE E VIRADA EM ASSERÇÃO ─────────────────────
 *
 * `grep -rln pub_aprovacao_ofertas database/migrations/` devolve **CINCO** arquivos — e um deles
 * é `2026_10_20_100000_create_pub_agente_tables.php`, de **outra sessão** (commit `1253cc86`).
 * ⚠️ **Ele não toca a tabela:** as duas menções estão no **DOCBLOCK**, em prosa, dizendo que *"a
 * oferta continua sendo `pub_aprovacao_ofertas`"*. Uma sonda por `grep` concluiria que há
 * migration nova mexendo no portão — e estaria **errada**.
 *
 * ➡️ Por isso toda sonda de código aqui lê **SEM COMENTÁRIO** (`token_get_all`, nunca regex), e
 * {@see self::test_nenhuma_migration_nova_mexe_na_tabela_do_portao_193_01} prova a armadilha em
 * vez de supô-la: no arquivo do Agente o texto cru **tem** a menção e o código **tem ZERO**.
 *
 * ⚠️ E a contagem é por `substr_count`, **nunca `grep -c`** — `grep -c` conta **LINHAS**, não
 * ocorrências, e nesta árvore já devolveu `1` onde havia **2 na mesma linha** (a linha `:37` da
 * própria migration da oferta cita `ciente` **e** `precisa_revisar` juntas).
 *
 * ── AS ARMADILHAS DE MIGRATION, UMA POR UMA ───────────────────────────────────────────────────
 *
 * | armadilha                               | onde está endereçada                                  |
 * |-----------------------------------------|-------------------------------------------------------|
 * | **1059** (MariaDB recusa índice > 64)   | asserção **COLETORA** sobre o schema vivo, reportando  |
 * |                                         | o MAIOR nome medido                                   |
 * | **1830** (FK `nullOnDelete` exige       | o **par** é asserção: `fila_item_id` nullable **E**    |
 * | `->nullable()`)                         | `on_delete = set null`                                |
 * | **1553** (dropar índice usado por FK)   | ⚠️ **NÃO ENTRA, e isto é declarado:** esta fase não    |
 * |                                         | dropa índice nenhum, então o gate seria sobre **ZERO** |
 * |                                         | **casos** — verde para sempre, o oposto de prova       |
 * | **enum + CHECK do SQLite**              | `andamento` é `string`, nunca `enum` — asserção        |
 * | **`up()` idempotente**                  | `Schema::hasTable` na migration da 186 — asserção      |
 * | **default do banco × Eloquent**         | as três colunas **sem** default **e** fora de          |
 * |                                         | `$attributes`; a isca prova que a sonda distingue      |
 * | **dado em produção**                    | é **por isso** que as três são nullable; nenhuma linha |
 * |                                         | é alterada em massa por esta fase                      |
 * | **MySQL 8.0.46 × MariaDB 10.4 × SQLite**| ⚠️ sem DDL a divergência **não é exercida** — e isso é |
 * |                                         | **declarado** na `193-01-SUMMARY.md`, não omitido      |
 *
 * ⚠️ **LIMITAÇÕES DO MOTOR DE TESTE, MEDIDAS NO `vendor/` e DECLARADAS COMO ASSERÇÃO** — nunca
 * na forma *"se der para ler, confere"*, que é um gate que se desliga sozinho:
 *   · `SQLiteProcessor::processForeignKeys()` devolve **`'name' => null`** (o
 *     `pragma_foreign_key_list` não traz o nome). ➡️ o **nome** `pubapof_fila_item_fk` é provado
 *     **no texto da migration**, sem comentário; a limitação em si é `assertNull`;
 *   · `processIndexes()` **traz** o nome (`strtolower`), então o 1059 é medível no schema vivo;
 *   · `decimal(p,s)` sai como `numeric` SEM precisão no SQLite — ⚠️ **não morde aqui**, porque
 *     nenhuma das três colunas desta fase é decimal. Declarado para quem reusar este arquivo.
 *
 * ⚠️ Sufixo `_193_01` em TODO método: o `--filter` do PHPUnit é regex **NÃO ANCORADO** e as
 * Fases 186/187/188 estão **EM PRODUÇÃO** — método superstring faria o id *daquela* fase somar.
 */
class SchemaDoPortaoDaPublicacaoTest extends TestCase
{
    use RefreshDatabase;

    /** A tabela do portão — a que a Fase 186 criou e que a 193 **lê**, nunca altera. */
    private const TABELA_DA_OFERTA = 'pub_aprovacao_ofertas';

    /** A tabela da fila de publicação — o outro lado da ligação `fila_item_id`. */
    private const TABELA_DA_FILA = 'pub_fila_publicacao_itens';

    /**
     * As **TRÊS** colunas de ligação de que a Fase 193 precisa. Contagem FIXA: se um dia a fase
     * precisar de uma quarta, isso é **decisão**, com diff visível — nunca um número que sobe.
     */
    private const COLUNAS_DA_193 = ['fila_item_id', 'ml_item_id', 'erro_publicacao'];

    /**
     * ⚠️ A **ISCA INVERTIDA** da nulabilidade: estas são colunas da MESMA tabela que são
     * **NOT NULL**. Sem elas, *"as três são nullable"* passaria igual numa sonda que devolvesse
     * `nullable => true` para tudo.
     */
    private const COLUNAS_NOT_NULL_DA_MESMA_TABELA = ['id', 'alvo_id', 'rascunho_id'];

    /**
     * As **4** colunas de conferência que vivem na FILA e que DADO-03 manda **ler** por
     * `fila_item_id`, **nunca copiar** para a oferta.
     *
     * ⚠️ `precisa_revisar` **não entra aqui** porque **não é coluna em lugar nenhum** — é
     * **VALOR** do `status` da fila ({@see PubFilaPublicacaoItem::PRECISA_REVISAR}), e isso
     * também é asserção.
     */
    private const COLUNAS_DE_CONFERENCIA_DA_FILA = ['validacao_id', 'plano_hash', 'revisao', 'ciente'];

    /**
     * As **5** migrations de `*pub_aprovacao*`, todas da Fase 186 (carimbo `2026_10_15_`).
     *
     * ⚠️ A lista é **CONSTANTE** e a contagem **FIXA**: é ela que prova que esta fase não
     * acrescentou migration nenhuma de `pub_aprovacao`. ⚠️ **ISCA INVERTIDA:** a lista tem de vir
     * **NÃO-vazia** do disco — lista vazia não é *"tudo certo"*, é **varredura cega**.
     */
    private const MIGRATIONS_PUB_APROVACAO = [
        'database/migrations/2026_10_15_100000_create_pub_aprovacao_conjuntos_tables.php',
        'database/migrations/2026_10_15_100100_create_pub_aprovacao_fidelidade_tables.php',
        'database/migrations/2026_10_15_100200_create_pub_aprovacao_ofertas_table.php',
        'database/migrations/2026_10_15_100300_create_pub_aprovacao_ajustes_tables.php',
        'database/migrations/2026_10_15_100400_create_pub_aprovacao_financeiro_tables.php',
    ];

    /** A migration que criou as três colunas — a da Fase 186. */
    private const MIGRATION_DA_OFERTA = 'database/migrations/2026_10_15_100200_create_pub_aprovacao_ofertas_table.php';

    /**
     * ⚠️ O arquivo de **OUTRA SESSÃO** (Agente ECF Publicador, commit `1253cc86`) que cita
     * `pub_aprovacao_ofertas` **só no docblock**. É a prova viva da armadilha do comentário.
     */
    private const MIGRATION_ALHEIA_QUE_SO_CITA_NO_DOCBLOCK = 'database/migrations/2026_10_20_100000_create_pub_agente_tables.php';

    private function raiz(): string
    {
        return rtrim(base_path(), '/\\');
    }

    /**
     * O código PHP de um arquivo **SEM COMENTÁRIO**, por `token_get_all` — nunca por regex.
     *
     * ⚠️ É a diferença entre medir o que o programa FAZ e medir o que a prosa DIZ. Um gate que
     * lesse comentários **reprovaria pelo próprio conserto** (aconteceu três vezes nesta
     * milestone) e, pior, **passaria** com a linha de código apagada se o texto sobrevivesse num
     * comentário.
     */
    private function codigoSemComentario(string $rel): string
    {
        $abs = $this->raiz().'/'.$rel;
        $this->assertFileExists($abs, "caminho fantasma é ERRO DO GATE, nunca \"território intocado\": {$rel}");

        return $this->semComentario($abs);
    }

    /**
     * ⚠️⚠️ **A MESMA LEITURA, SEM ASSERÇÃO — e a separação é o conserto de um furo MEDIDO neste
     * próprio arquivo.**
     *
     * A primeira redação usava {@see self::codigoSemComentario()} também nos laços **em massa**
     * (as 363 migrations e os 1208 `*Test.php`). Cada chamada carrega um `assertFileExists`, que
     * **incrementa o contador de asserções** — então a contagem do manifesto ficava **refém do
     * número de arquivos do repositório**, que cresce a cada migration e a cada teste de
     * **qualquer** sessão. ⚠️ **Medido: 413 asserções para 3 casos, das quais 363 eram o laço**,
     * e o caso da bijeção somaria **mais 1208**.
     *
     * ➡️ É a armadilha §4b.2 (*"âncora em número que muda legitimamente"*) escondida num helper,
     * e o conserto certo é **a raiz, não o número**: o laço em massa varre `glob()`/iterator, onde
     * os arquivos **existem por construção** e a asserção anti-fantasma não prova nada. A versão
     * que **assevera** fica para os caminhos de **lista CONSTANTE**, onde o fantasma é risco real
     * e a contagem é fixa por construção.
     */
    private function semComentario(string $abs): string
    {
        $saida = '';
        foreach (token_get_all((string) file_get_contents($abs)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $saida .= $t[1];

                continue;
            }
            $saida .= $t;
        }

        return $saida;
    }

    /**
     * A sonda de *"esta migration ACRESCENTA coluna à tabela do portão"*, escrita sobre uma
     * **STRING** justamente para poder ser **iscada** com texto sintético.
     *
     * ⚠️ Sonda que só sabe ler arquivo real não tem como provar que **não está cega**.
     */
    private function acrescentaColunaNaOferta(string $codigo): bool
    {
        foreach (["Schema::table('".self::TABELA_DA_OFERTA."'", 'Schema::table("'.self::TABELA_DA_OFERTA.'"'] as $forma) {
            if (substr_count($codigo, $forma) > 0) {
                return true;
            }
        }

        return false;
    }

    /** As colunas do schema VIVO, indexadas por nome. */
    private function colunasVivas(string $tabela): array
    {
        $colunas = [];
        foreach (Schema::getColumns($tabela) as $c) {
            $colunas[$c['name']] = $c;
        }

        return $colunas;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // CASO 1 — as três colunas existem, são nullable, não têm default, e o model não as semeia
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **O caso central: a Fase 193 NÃO precisa de migration porque as três colunas JÁ
     * ESTÃO NO SCHEMA VIVO.**
     *
     * Prova, numa asserção coletora por eixo:
     *   1. as três existem — e a varredura **não está cega** (a tabela vem com colunas);
     *   2. as três são **NULLABLE** — ⚠️ com **ISCA INVERTIDA**: `id`/`alvo_id`/`rascunho_id`,
     *      da MESMA tabela, têm de vir **NOT NULL**. Sem essa ponta, uma sonda que devolvesse
     *      `nullable => true` para tudo passaria igual;
     *   3. as três **não têm default no banco** — ⚠️ com **ISCA INVERTIDA**: `andamento`,
     *      `origem`, `versao` e `tocado` **TÊM** default. É o que distingue *"não tem default"*
     *      de *"a sonda não lê default"*;
     *   4. o model **não as declara em `$attributes`** — ⚠️ com **ISCA INVERTIDA**: `andamento`
     *      **está** lá. O Eloquent **não lê** o default do banco (defeito real medido em
     *      14/10/2026), e é por isso que `$attributes` espelha exatamente as colunas COM
     *      default — e as três desta fase, que não têm, ficam **de fora**;
     *   5. `andamento` é **string**, nunca `enum` — a armadilha enum + CHECK do SQLite.
     */
    public function test_as_tres_colunas_de_ligacao_existem_nullable_e_sem_default_193_01(): void
    {
        $colunas = $this->colunasVivas(self::TABELA_DA_OFERTA);

        // ── ISCA DE NÃO-VACUIDADE: varredura vazia não é "tudo certo", é cegueira ───────────
        $this->assertGreaterThan(
            10,
            count($colunas),
            'varredura CEGA: o schema vivo de '.self::TABELA_DA_OFERTA.' voltou com '.count($colunas).' colunas — gate sobre ZERO casos não prova nada'
        );
        $this->assertSame(3, count(self::COLUNAS_DA_193), 'as TRÊS colunas de ligação da Fase 193 — contagem FIXA, nunca um número que sobe');

        // ── 1 + 2: existem e são NULLABLE (coletora) ────────────────────────────────────────
        $ausentes = [];
        $naoNullable = [];
        foreach (self::COLUNAS_DA_193 as $nome) {
            if (! isset($colunas[$nome])) {
                $ausentes[] = $nome;

                continue;
            }
            if ($colunas[$nome]['nullable'] !== true) {
                $naoNullable[] = $nome.' (nullable='.var_export($colunas[$nome]['nullable'], true).')';
            }
        }
        $this->assertSame(
            [],
            $ausentes,
            'PARE E DEVOLVA A PERGUNTA: coluna de ligação AUSENTE do schema vivo — '.implode(', ', $ausentes)
            .'. Isso contradiz a medição do planejamento (193-REGUA.md §1-M1) e significaria que a Fase 193 TEM migration. '
            .'NÃO escreva migration numa tabela com dado em produção sem devolver a decisão ao usuário.'
        );
        $this->assertSame(
            [],
            $naoNullable,
            'as três colunas de ligação TÊM de ser nullable (a tabela tem dado em produção desde a Fase 186): '.implode(', ', $naoNullable)
        );

        // ── ISCA INVERTIDA da nulabilidade: a MESMA sonda tem de ver NOT NULL ───────────────
        $iscaNullable = [];
        foreach (self::COLUNAS_NOT_NULL_DA_MESMA_TABELA as $nome) {
            if (! isset($colunas[$nome]) || $colunas[$nome]['nullable'] !== false) {
                $iscaNullable[] = $nome;
            }
        }
        $this->assertSame(
            [],
            $iscaNullable,
            'ISCA INVERTIDA FUROU: estas colunas da MESMA tabela deveriam vir NOT NULL, e a sonda não as distinguiu — '
            .implode(', ', $iscaNullable).'. Sem esta ponta, "as três são nullable" seria verde até numa sonda cega.'
        );

        // ── 3: sem default no banco (coletora) + ISCA INVERTIDA de quem TEM default ─────────
        $comDefaultIndevido = [];
        foreach (self::COLUNAS_DA_193 as $nome) {
            if ($colunas[$nome]['default'] !== null) {
                $comDefaultIndevido[] = $nome.' => '.var_export($colunas[$nome]['default'], true);
            }
        }
        $this->assertSame(
            [],
            $comDefaultIndevido,
            'as três colunas desta fase não têm default no banco: '.implode(', ', $comDefaultIndevido)
        );

        $semDefaultQueDeveriaTer = [];
        foreach (['andamento', 'origem', 'versao', 'tocado'] as $nome) {
            if (! isset($colunas[$nome]) || $colunas[$nome]['default'] === null) {
                $semDefaultQueDeveriaTer[] = $nome;
            }
        }
        $this->assertSame(
            [],
            $semDefaultQueDeveriaTer,
            'ISCA INVERTIDA FUROU: estas colunas TÊM default na migration da Fase 186 e a sonda não o leu — '
            .implode(', ', $semDefaultQueDeveriaTer).'. Sem esta ponta, "as três não têm default" não distinguiria '
            .'"não tem" de "a sonda não lê default".'
        );

        // ── 4: o model não semeia as três; e a isca prova que ele semeia quem tem default ───
        $semeadas = array_keys((new PubAprovacaoOferta)->getAttributes());
        $this->assertContains(
            'andamento',
            $semeadas,
            'ISCA INVERTIDA FUROU: $attributes do model deveria semear `andamento` (ele TEM default no banco e o Eloquent não lê default do banco — defeito real medido em 14/10/2026)'
        );
        $semeadasIndevidamente = array_values(array_intersect(self::COLUNAS_DA_193, $semeadas));
        $this->assertSame(
            [],
            $semeadasIndevidamente,
            'o model NÃO deve semear as colunas desta fase em $attributes (elas não têm default no banco): '.implode(', ', $semeadasIndevidamente)
        );

        // ── 5: enum + CHECK do SQLite — `andamento` é string, nunca enum ────────────────────
        $this->assertStringNotContainsStringIgnoringCase(
            'enum',
            (string) $colunas['andamento']['type'],
            'armadilha enum + CHECK do SQLite: `andamento` é string() + constantes no model, nunca enum (type medido: '.$colunas['andamento']['type'].')'
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // CASO 2 — o índice e a FK de `fila_item_id`, e o 1059 por asserção coletora
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * A ligação oferta → fila está **de pé no schema vivo**, com índice nomeado e FK
     * `on delete set null` — o **par** que a armadilha **1830** exige (FK `nullOnDelete` só vale
     * com a coluna `nullable()`; o SQLite dos testes não pegaria o par errado, mas a asserção
     * documenta qual é o par certo).
     *
     * E o **1059** entra como asserção **COLETORA** sobre o schema vivo, reportando o MAIOR nome
     * medido — em vez de uma asserção por índice, que subiria a cada índice novo.
     *
     * ⚠️⚠️ **LIMITAÇÃO DO MOTOR, MEDIDA NO `vendor/` E DECLARADA COMO ASSERÇÃO:**
     * `SQLiteProcessor::processForeignKeys()` devolve **`'name' => null`** — o
     * `pragma_foreign_key_list` do SQLite **não traz o nome da FK**. ➡️ aqui isso não é
     * contornado com um `if` que se desliga sozinho: a limitação é `assertNull`, e o **nome**
     * `pubapof_fila_item_fk` é provado **no texto da migration, sem comentário**.
     */
    public function test_o_indice_e_a_fk_de_fila_item_id_estao_de_pe_e_nenhum_indice_passa_de_64_193_01(): void
    {
        $indices = Schema::getIndexes(self::TABELA_DA_OFERTA);
        $fks = Schema::getForeignKeys(self::TABELA_DA_OFERTA);

        // ── ISCAS DE NÃO-VACUIDADE nas duas listas ─────────────────────────────────────────
        $this->assertNotSame([], $indices, 'varredura CEGA: nenhum índice lido em '.self::TABELA_DA_OFERTA);
        $this->assertNotSame([], $fks, 'varredura CEGA: nenhuma FK lida em '.self::TABELA_DA_OFERTA);

        $nomesDeIndice = array_map(static fn ($i) => (string) $i['name'], $indices);

        // ── os DOIS índices que a Fase 193 lê (coletora) ───────────────────────────────────
        $indicesAusentes = array_values(array_diff(['pubapof_fila_item_ix', 'pubapof_mlb_ix'], $nomesDeIndice));
        $this->assertSame(
            [],
            $indicesAusentes,
            'índice de ligação AUSENTE do schema vivo: '.implode(', ', $indicesAusentes)
            .'. Índices medidos: '.implode(', ', $nomesDeIndice)
        );

        // ── ISCA INVERTIDA: um nome de índice que NÃO existe não pode ser "encontrado" ──────
        $this->assertNotContains(
            'pubapof_naoexiste9z7_ix',
            $nomesDeIndice,
            'ISCA FUROU: a sonda de índice "encontrou" um nome sintético — ela está casando qualquer coisa'
        );

        // ── 1059: COLETORA, com o MAIOR nome reportado ─────────────────────────────────────
        $longos = [];
        $maior = '';
        foreach ($nomesDeIndice as $n) {
            if (strlen($n) > strlen($maior)) {
                $maior = $n;
            }
            if (strlen($n) > 64) {
                $longos[] = $n.' ('.strlen($n).' chars)';
            }
        }
        $this->assertSame(
            [],
            $longos,
            'MariaDB recusa nome de índice acima de 64 chars (erro 1059) — infratores: '.implode(', ', $longos)
        );
        $this->assertNotSame('', $maior, 'ISCA: a medição do MAIOR nome de índice voltou vazia — varredura cega');
        $this->assertLessThanOrEqual(
            64,
            strlen($maior),
            'o MAIOR nome de índice medido em '.self::TABELA_DA_OFERTA.' é `'.$maior.'` com '.strlen($maior).' chars'
        );

        // ── a FK de `fila_item_id`: alvo e regra de delete ─────────────────────────────────
        $daFila = null;
        foreach ($fks as $fk) {
            if ($fk['columns'] === ['fila_item_id']) {
                $daFila = $fk;
                break;
            }
        }
        $this->assertNotNull(
            $daFila,
            'PARE E DEVOLVA A PERGUNTA: não há FK saindo de `fila_item_id` no schema vivo. '
            .'Isso contradiz 193-REGUA.md §1-M1 e significaria que a Fase 193 TEM migration.'
        );
        $this->assertSame(
            self::TABELA_DA_FILA,
            $daFila['foreign_table'],
            'a FK de `fila_item_id` aponta para a tabela da fila de publicação'
        );
        $this->assertSame(
            ['id'],
            $daFila['foreign_columns'],
            'a FK de `fila_item_id` aponta para a PK da fila'
        );
        $this->assertSame(
            'set null',
            $daFila['on_delete'],
            'armadilha 1830: o par correto é coluna `nullable()` + FK `nullOnDelete`. '
            .'on_delete medido: '.var_export($daFila['on_delete'], true)
        );

        // ── ⚠️ A LIMITAÇÃO DO MOTOR, COMO ASSERÇÃO (nunca como `if` que se desliga) ─────────
        $this->assertSame(
            'sqlite',
            Schema::getConnection()->getDriverName(),
            'esta suíte roda em SQLite in-memory (phpunit.xml: DB_CONNECTION=sqlite, DB_DATABASE=:memory:)'
        );
        $this->assertNull(
            $daFila['name'],
            'LIMITAÇÃO MEDIDA DO MOTOR: SQLiteProcessor::processForeignKeys() devolve `name => null` '
            .'(pragma_foreign_key_list não traz o nome da FK). Se um dia este motor passar a expor o nome, '
            .'o conserto é FORTALECER esta asserção para o nome `pubapof_fila_item_fk` — NUNCA apagá-la.'
        );

        // ── e por isso o NOME da FK é provado no TEXTO da migration, SEM COMENTÁRIO ─────────
        $codigo = $this->codigoSemComentario(self::MIGRATION_DA_OFERTA);
        $this->assertSame(
            1,
            substr_count($codigo, "'pubapof_fila_item_fk'"),
            'o nome da FK tem de aparecer EXATAMENTE uma vez no código (sem comentário) da migration da Fase 186 '
            .'— contagem por substr_count, nunca por `grep -c`, que conta LINHAS'
        );
        // ⚠️⚠️ ÂNCORA NO INVARIANTE, NUNCA NUM NÚMERO QUE CRESCE — e este caso foi MEDIDO.
        //
        // A primeira redação deste gate asseverava `substr_count($codigo, 'nullOnDelete') === 1`.
        // ⚠️ **Rodei e ela REPROVOU: o valor medido é 4** (`produto_fk`, `responsavel_fk`,
        // `aprovado_por_fk` e `fila_item_fk`, linhas `:307`-`:310`). Era a armadilha *"âncora em
        // número que muda legitimamente"*: toda FK `nullOnDelete` nova na tabela faria o número
        // subir, e o conserto "fácil" seria **elevar a régua** — o hábito que esta milestone já
        // pagou caro quinze vezes.
        //
        // ➡️ A âncora certa é a **CADEIA EXATA desta FK**: qual coluna, com qual nome, para qual
        // tabela, com qual regra de delete. Ela não se move quando outra FK nasce, e **se move**
        // exatamente quando o invariante é violado.
        $cadeia = "->foreign('fila_item_id', 'pubapof_fila_item_fk')->references('id')->on('pub_fila_publicacao_itens')->nullOnDelete()";
        $this->assertSame(
            1,
            substr_count($codigo, $cadeia),
            'armadilha 1830, pelo INVARIANTE: a FK de `fila_item_id` tem de ser exatamente '
            .'`'.$cadeia.'` no código (sem comentário) da migration da Fase 186'
        );

        // ⚠️ ISCA DO QUASE-IGUAL: a MESMA cadeia com a regra de delete ERRADA tem de dar ZERO.
        // Sem esta ponta, a asserção acima seria tautologia de literal — ela não provaria que a
        // sonda distingue `nullOnDelete` de `cascadeOnDelete` numa tabela com dado em produção.
        $this->assertSame(
            0,
            substr_count($codigo, str_replace('nullOnDelete', 'cascadeOnDelete', $cadeia)),
            'ISCA DO QUASE-IGUAL FUROU: a FK de `fila_item_id` NÃO pode ser `cascadeOnDelete` — '
            .'apagar o item da fila apagaria a OFERTA APROVADA, e a tabela tem dado em produção'
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // CASO 3 — nenhuma migration nova, e as 4 colunas da fila NÃO foram copiadas
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **A asserção de que a Fase 193 não escreveu migration**, nas duas formas que importam:
     *
     *   (a) as migrations de `*pub_aprovacao*` são as **CINCO** da Fase 186 (carimbo
     *       `2026_10_15_`), todas no disco — ⚠️ **ISCA INVERTIDA:** a lista do disco vem
     *       **NÃO-vazia**, senão a varredura está cega;
     *   (b) **nenhuma** migration do repositório inteiro faz `Schema::table('pub_aprovacao_ofertas'`
     *       — ⚠️ com **ISCA POSITIVA**: a mesma sonda, alimentada com texto **sintético** que faz
     *       isso, tem de **ACUSAR**. Negativa sem essa ponta é verde para sempre.
     *
     * ⚠️⚠️ **E A ARMADILHA DO DOCBLOCK, PROVADA EM VEZ DE SUPOSTA:** a migration do Agente
     * (outra sessão) **cita** `pub_aprovacao_ofertas` no texto cru e tem **ZERO** menções no
     * código. Este caso mede as duas coisas — é o que separa *"o programa faz"* de *"a prosa
     * diz"*, e é por isso que uma sonda por `grep` concluiria errado aqui.
     *
     * E o outro lado de DADO-03: as **4** colunas de conferência da fila **não foram copiadas**
     * para a oferta (são **LIDAS** por `fila_item_id`), com **ISCA INVERTIDA** — elas **existem**
     * na tabela da fila, provando que a sonda sabe achá-las quando elas estão lá.
     */
    public function test_nenhuma_migration_nova_mexe_na_tabela_do_portao_193_01(): void
    {
        // ── (a) as 5 migrations da 186, com test -e em cada uma ────────────────────────────
        $this->assertSame(5, count(self::MIGRATIONS_PUB_APROVACAO), 'as CINCO migrations de *pub_aprovacao* — contagem FIXA');

        $doDisco = glob($this->raiz().'/database/migrations/*pub_aprovacao*.php') ?: [];
        $this->assertNotSame(
            [],
            $doDisco,
            'ISCA INVERTIDA FUROU: o glob de *pub_aprovacao* voltou VAZIO. Lista vazia não é "tudo certo", é VARREDURA CEGA.'
        );
        $this->assertSame(
            5,
            count($doDisco),
            'migration de *pub_aprovacao* a MAIS ou a MENOS no disco. Medidas: '
            .implode(', ', array_map('basename', $doDisco))
            .'. ⚠️ Se a Fase 193 criou migration, PARE E DEVOLVA: 193-REGUA.md §2 mediu que ela não precisa de nenhuma.'
        );

        $fantasmas = [];
        $foraDa186 = [];
        foreach (self::MIGRATIONS_PUB_APROVACAO as $rel) {
            if (! file_exists($this->raiz().'/'.$rel)) {
                $fantasmas[] = $rel;

                continue;
            }
            if (! str_starts_with(basename($rel), '2026_10_15_')) {
                $foraDa186[] = basename($rel);
            }
        }
        $this->assertSame([], $fantasmas, 'caminho fantasma é ERRO DO GATE, nunca "território intocado": '.implode(', ', $fantasmas));
        $this->assertSame(
            [],
            $foraDa186,
            'migration de *pub_aprovacao* com carimbo POSTERIOR à Fase 186 (15/10/2026): '.implode(', ', $foraDa186)
        );

        // ── (b) ISCA POSITIVA primeiro: a sonda tem de ACUSAR texto sintético ──────────────
        $sintetico = "<?php Schema::table('".self::TABELA_DA_OFERTA."', function (Blueprint \$t) { \$t->string('coluna_nova'); });";
        $this->assertTrue(
            $this->acrescentaColunaNaOferta($sintetico),
            'ISCA POSITIVA FUROU: a sonda não acusou um texto que ACRESCENTA coluna à tabela do portão. '
            .'Sem esta ponta, a varredura abaixo é vazia por construção — verde para sempre.'
        );
        $this->assertFalse(
            $this->acrescentaColunaNaOferta("<?php Schema::table('outra_tabela', function (\$t) {});"),
            'ISCA NEGATIVA FUROU: a sonda acusou uma tabela que não é a do portão — ela está mais larga que o invariante'
        );

        // ── (b) agora a varredura real, em TODAS as migrations, SEM COMENTÁRIO ─────────────
        $todas = glob($this->raiz().'/database/migrations/*.php') ?: [];
        $this->assertGreaterThan(
            50,
            count($todas),
            'varredura CEGA: só '.count($todas).' migrations encontradas no repositório'
        );

        // ⚠️ laço em massa → leitor SEM asserção (ver o docblock de `semComentario()`): aqui os
        // arquivos vêm do `glob()` e existem por construção, e um `assertFileExists` por arquivo
        // faria a contagem do manifesto subir a cada migration nova de QUALQUER sessão.
        $infratoras = [];
        foreach ($todas as $abs) {
            if ($this->acrescentaColunaNaOferta($this->semComentario($abs))) {
                $infratoras[] = basename($abs);
            }
        }
        $this->assertSame(
            [],
            $infratoras,
            'migration ACRESCENTANDO coluna a '.self::TABELA_DA_OFERTA.': '.implode(', ', $infratoras)
            .'. ⚠️ A Fase 193 LÊ essa tabela; ela não a altera.'
        );

        // ── ⚠️ A ARMADILHA DO DOCBLOCK, MEDIDA NAS DUAS PONTAS ─────────────────────────────
        $cruAlheia = (string) file_get_contents($this->raiz().'/'.self::MIGRATION_ALHEIA_QUE_SO_CITA_NO_DOCBLOCK);
        $this->assertGreaterThan(
            0,
            substr_count($cruAlheia, self::TABELA_DA_OFERTA),
            'ISCA FUROU: a migration do Agente deveria CITAR `'.self::TABELA_DA_OFERTA.'` no texto cru '
            .'(é ela que prova que uma sonda por grep concluiria errado). Se a citação saiu, esta prova ficou vazia.'
        );
        $this->assertSame(
            0,
            substr_count($this->codigoSemComentario(self::MIGRATION_ALHEIA_QUE_SO_CITA_NO_DOCBLOCK), self::TABELA_DA_OFERTA),
            'a migration do Agente cita a tabela do portão APENAS no docblock — se ela passou a tocá-la em CÓDIGO, '
            .'isso é mudança de território de outra sessão e tem de ser devolvido, não absorvido'
        );

        // ── DADO-03: as 4 colunas de conferência NÃO foram copiadas para a oferta ──────────
        $this->assertSame(4, count(self::COLUNAS_DE_CONFERENCIA_DA_FILA), 'as QUATRO colunas de conferência — contagem FIXA');

        $naOferta = $this->colunasVivas(self::TABELA_DA_OFERTA);
        $naFila = $this->colunasVivas(self::TABELA_DA_FILA);
        $this->assertGreaterThan(10, count($naFila), 'varredura CEGA: o schema vivo de '.self::TABELA_DA_FILA.' voltou com '.count($naFila).' colunas');

        $copiadas = [];
        $faltandoNaFila = [];
        foreach (self::COLUNAS_DE_CONFERENCIA_DA_FILA as $nome) {
            if (isset($naOferta[$nome])) {
                $copiadas[] = $nome;
            }
            if (! isset($naFila[$nome])) {
                $faltandoNaFila[] = $nome;
            }
        }
        $this->assertSame(
            [],
            $faltandoNaFila,
            'ISCA INVERTIDA FUROU: estas colunas deveriam existir em '.self::TABELA_DA_FILA.' e a sonda não as achou — '
            .implode(', ', $faltandoNaFila).'. Sem esta ponta, "não foram copiadas" seria verde numa varredura cega.'
        );
        $this->assertSame(
            [],
            $copiadas,
            'DADO-03: as colunas de conferência são LIDAS por `fila_item_id`, NUNCA copiadas para a oferta. Copiadas: '.implode(', ', $copiadas)
        );

        // ── `precisa_revisar` não é coluna em NENHUMA das duas — é VALOR do status ─────────
        $this->assertArrayNotHasKey('precisa_revisar', $naOferta, '`precisa_revisar` não é coluna de '.self::TABELA_DA_OFERTA);
        $this->assertArrayNotHasKey('precisa_revisar', $naFila, '`precisa_revisar` não é coluna de '.self::TABELA_DA_FILA);
        $this->assertSame(
            'precisa_revisar',
            PubFilaPublicacaoItem::PRECISA_REVISAR,
            '`precisa_revisar` é VALOR do `status` da fila — e a constante é a prova de que ele existe em algum lugar '
            .'(sem ela, a dupla ausência acima poderia ser só erro de digitação)'
        );
        $this->assertContains(
            PubFilaPublicacaoItem::PRECISA_REVISAR,
            PubFilaPublicacaoItem::SEM_PUBLICAR,
            '`precisa_revisar` é um dos estados SEM_PUBLICAR da fila'
        );

        // ── `up()` idempotente na migration que criou tudo (MIG) ───────────────────────────
        $this->assertSame(
            1,
            substr_count($this->codigoSemComentario(self::MIGRATION_DA_OFERTA), "Schema::hasTable('".self::TABELA_DA_OFERTA."')"),
            '`up()` idempotente: a migration da Fase 186 guarda o `Schema::create` com `hasTable` '
            .'(a tabela já está aplicada em produção)'
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // CASO 4 — a BIJEÇÃO do manifesto, nos DOIS sentidos, com EXPANSOR DE ALTERNÂNCIA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **Teste sem id NUNCA RODA, e id órfão não falha: ele simplesmente não existe.** As
     * duas falhas são **silenciosas** — e já aconteceram nesta milestone (um método sem id foi o
     * sobrevivente da mutação do 186-03; dois ids nasceram órfãos no 188-05).
     *
     * Este caso fecha as **três** bijeções por **script**, não por leitura:
     *   1. **método → id**: todo `test_*_193_01` deste arquivo está em algum id (`SEM_ID=0`);
     *   2. **id → método**: todo método declarado nos ids existe de verdade (`ORFAOS=0`);
     *   3. **id → `<verify>`**: todo id do manifesto é invocado no `<verify>` do plano, e o
     *      `<verify>` não invoca id que não esteja no manifesto.
     * Mais `IDS_REPETIDOS=0` e `HOMONIMOS=0` (o `--filter` do PHPUnit é regex **NÃO ANCORADO**).
     *
     * ⚠️⚠️ **O EXPANSOR DE ALTERNÂNCIA é o ponto do caso.** A regex ingênua
     * `/test_[A-Za-z0-9_]+/` casa **ZERO** em `test_(a|b|c)` — e esse furo exato produziu
     * `SEM_ID=14` numa wave desta milestone, **acusando trabalho correto**. Por isso o expansor
     * é **iscado nas duas direções** antes de valer.
     */
    public function test_o_manifesto_tem_bijecao_com_os_metodos_e_nenhum_id_orfao_193_01(): void
    {
        $manifesto = 'tests/gates/contagens-193-01.txt';
        $absManifesto = $this->raiz().'/'.$manifesto;
        $this->assertFileExists($absManifesto, 'manifesto incompleto ABORTA o harness — ele tem de nascer ANTES do verify');

        // ── O EXPANSOR, iscado ANTES de valer ──────────────────────────────────────────────
        $this->assertSame(
            ['test_a_193_01', 'test_b_193_01', 'test_c_193_01'],
            $this->expandirAlternancia('test_(a_193_01|b_193_01|c_193_01)'),
            'ISCA POSITIVA DO EXPANSOR: a forma com alternância TEM de virar três nomes. '
            .'A regex ingênua /test_[A-Za-z0-9_]+/ casa ZERO aqui — foi o furo que deu SEM_ID=14 acusando trabalho correto.'
        );
        $this->assertSame(
            ['test_sozinho_193_01'],
            $this->expandirAlternancia('test_sozinho_193_01'),
            'ISCA NEGATIVA DO EXPANSOR: filtro sem alternância devolve ele mesmo, uma entrada'
        );

        // ── Lê o manifesto ─────────────────────────────────────────────────────────────────
        $ids = [];
        $repetidos = [];
        foreach (preg_split('/\R/', (string) file_get_contents($absManifesto)) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha === '' || str_starts_with($linha, '#')) {
                continue;
            }
            if (preg_match('/^(\S+)\s+php\s+(\S+)\s+::\s+tests=(\d+)\s+assertions=(\d+)$/', $linha, $m) !== 1) {
                $this->fail("linha de manifesto fora do formato esperado: {$linha}");
            }
            if (isset($ids[$m[1]])) {
                $repetidos[] = $m[1];
            }
            $ids[$m[1]] = ['metodos' => $this->expandirAlternancia($m[2]), 'tests' => (int) $m[3]];
        }

        $this->assertNotSame([], $ids, 'ISCA DE NÃO-VACUIDADE: o manifesto não declarou id nenhum — gate sobre ZERO casos');
        $this->assertSame([], $repetidos, "IDS_REPETIDOS: ".implode(', ', $repetidos));

        // ── nenhum id com mais de TRÊS métodos (alternância grande = filtro de classe) ─────
        $grandes = [];
        $contagemDivergente = [];
        foreach ($ids as $id => $d) {
            if (count($d['metodos']) > 3) {
                $grandes[] = $id.' ('.count($d['metodos']).' métodos)';
            }
            if (count($d['metodos']) !== $d['tests']) {
                $contagemDivergente[] = $id.' ('.count($d['metodos']).' métodos × tests='.$d['tests'].')';
            }
        }
        $this->assertSame(
            [],
            $grandes,
            'nenhum id pode ter mais de TRÊS métodos: alternância grande equivale a filtro de CLASSE e passa dos 120 s do shell — '.implode(', ', $grandes)
        );
        $this->assertSame(
            [],
            $contagemDivergente,
            '`tests=N` tem de bater com o número de métodos do id: '.implode(', ', $contagemDivergente)
        );

        // ── os métodos deste arquivo ───────────────────────────────────────────────────────
        $meus = $this->metodosDeTeste((new \ReflectionClass(self::class))->getFileName());
        $this->assertGreaterThan(0, count($meus), 'ISCA DE NÃO-VACUIDADE: nenhum método de teste lido no próprio arquivo');

        $semSufixo = array_values(array_filter($meus, static fn ($m) => ! str_ends_with($m, '_193_01')));
        $this->assertSame(
            [],
            $semSufixo,
            'o `--filter` do PHPUnit é regex NÃO ANCORADO e as Fases 186/187/188 estão EM PRODUÇÃO: '
            .'todo método precisa do sufixo `_193_01` — '.implode(', ', $semSufixo)
        );

        $declarados = [];
        foreach ($ids as $d) {
            foreach ($d['metodos'] as $m) {
                $declarados[] = $m;
            }
        }

        // 1. SEM_ID — método que não está em id nenhum NUNCA RODA
        $semId = array_values(array_diff($meus, $declarados));
        $this->assertSame([], $semId, 'SEM_ID (método que NUNCA RODA porque nenhum id o invoca): '.implode(', ', $semId));

        // 2. ORFAOS — id apontando para método que não existe é gate sobre ZERO casos
        $orfaos = array_values(array_diff($declarados, $meus));
        $this->assertSame([], $orfaos, 'ORFAOS (id que aponta para método inexistente — não falha, simplesmente nunca roda): '.implode(', ', $orfaos));

        // ── ISCAS da bijeção, nas duas direções ────────────────────────────────────────────
        $this->assertSame(
            ['sintetico_que_nao_existe_193_01'],
            array_values(array_diff(array_merge($declarados, ['sintetico_que_nao_existe_193_01']), $meus)),
            'ISCA POSITIVA DA BIJEÇÃO FUROU: um id sintético apontando para método inexistente TEM de ser acusado como órfão'
        );
        $this->assertSame(
            [],
            array_values(array_diff($declarados, array_merge($meus, ['metodo_de_outra_fase_186_04']))),
            'ISCA NEGATIVA DA BIJEÇÃO: nenhum método meu deve depender de nome de outra fase'
        );

        // 3. HOMONIMOS — o `--filter` não é ancorado: método de OUTRO arquivo pode somar
        $homonimos = [];
        foreach ($this->todosOsArquivosDeTeste() as $arq) {
            if (realpath($arq) === realpath((new \ReflectionClass(self::class))->getFileName())) {
                continue;
            }
            foreach ($this->metodosDeTeste($arq) as $m) {
                foreach ($declarados as $meu) {
                    if ($m !== $meu && (str_contains($m, $meu) || str_contains($meu, $m))) {
                        $homonimos[] = basename($arq).'::'.$m.' colide com '.$meu;
                    }
                }
            }
        }
        $this->assertSame([], $homonimos, 'HOMONIMOS (o --filter do PHPUnit é regex NÃO ANCORADO): '.implode(' | ', $homonimos));

        // ── ISCA do varredor de homônimos: ele tem de ACUSAR um nome sintético superstring ──
        $umMeu = $declarados[0];
        $this->assertTrue(
            str_contains($umMeu.'_extra', $umMeu),
            'ISCA POSITIVA DO VARREDOR DE HOMÔNIMOS: um nome superstring do meu TEM de ser detectado como colisão'
        );
        $this->assertFalse(
            str_contains($umMeu, 'test_aprovar_e_enfileirar_188_05') || str_contains('test_aprovar_e_enfileirar_188_05', $umMeu),
            'ISCA NEGATIVA DO VARREDOR DE HOMÔNIMOS: um método real de outra fase NÃO pode ser acusado'
        );

        // 4. a TERCEIRA bijeção: id ↔ `<verify>` do plano
        $plano = '.planning/phases/193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo/193-01-PLAN.md';
        $absPlano = $this->raiz().'/'.$plano;
        $this->assertFileExists($absPlano, 'o plano desta wave tem de existir para a terceira bijeção valer');
        $textoDoPlano = (string) file_get_contents($absPlano);

        $naoInvocados = [];
        foreach (array_keys($ids) as $id) {
            if (! str_contains($textoDoPlano, '193-01 '.$id)) {
                $naoInvocados[] = $id;
            }
        }
        $this->assertSame(
            [],
            $naoInvocados,
            'id ÓRFÃO DE <verify>: declarado no manifesto e invocado por NENHUM verify — ele nunca roda. '.implode(', ', $naoInvocados)
        );

        preg_match_all('/verificar-testes\.sh 193-01 ([a-z0-9][a-z0-9-]*)/', $textoDoPlano, $mv);
        $this->assertNotSame([], $mv[1], 'ISCA DE NÃO-VACUIDADE: nenhum `verificar-testes.sh 193-01 <id>` lido no plano');
        $invocadosSemManifesto = array_values(array_diff(array_unique($mv[1]), array_keys($ids)));
        $this->assertSame(
            [],
            $invocadosSemManifesto,
            'o <verify> invoca id que NÃO está no manifesto (o harness ABORTA): '.implode(', ', $invocadosSemManifesto)
        );
    }

    /**
     * O **EXPANSOR DE ALTERNÂNCIA**: `test_(a|b|c)` → `[test_a, test_b, test_c]`.
     *
     * ⚠️ A regex ingênua `/test_[A-Za-z0-9_]+/` casa **ZERO** nessa forma, e esse furo deu
     * `SEM_ID=14` **acusando trabalho correto** numa wave desta milestone.
     */
    private function expandirAlternancia(string $filtro): array
    {
        if (preg_match('/^([A-Za-z0-9_]*)\(([^()]+)\)$/', $filtro, $m) === 1) {
            return array_values(array_map(
                static fn ($parte) => $m[1].trim($parte),
                explode('|', $m[2])
            ));
        }

        return [$filtro];
    }

    /**
     * Os nomes de método `test_*` de um arquivo, lidos **SEM COMENTÁRIO**.
     *
     * ⚠️ Método citado em docblock (*"ver `test_x`"*) não é método declarado — ler com comentário
     * inventaria métodos que não existem e produziria `ORFAOS` falsos.
     *
     * ⚠️ Usa o leitor **sem asserção**: isto roda 1208 vezes na varredura de homônimo.
     */
    private function metodosDeTeste(string $abs): array
    {
        preg_match_all('/function\s+(test_[A-Za-z0-9_]+)\s*\(/', $this->semComentario($abs), $m);

        return array_values(array_unique($m[1]));
    }

    /** Todos os arquivos `*Test.php` da suíte — a varredura do homônimo. */
    private function todosOsArquivosDeTeste(): array
    {
        $achados = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->raiz().'/tests'));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), 'Test.php')) {
                $achados[] = $f->getPathname();
            }
        }
        sort($achados);

        return $achados;
    }
}
