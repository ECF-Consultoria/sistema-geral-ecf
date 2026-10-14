<?php

namespace Tests\Feature\Publicador\Aprovacao;

use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 * GATE-01 — O QUE PRECISARIA ESTAR ERRADO NO MUNDO PARA ESTE GATE FALHAR?
 *
 *   **Uma migration nova do portão usar `enum`, ou um `nullOnDelete()` numa coluna sem
 *   `nullable()` explícito, e o deploy quebrar no MariaDB com a tabela JÁ alterada** — a
 *   migration fica `Pending` sobre um schema meio mudado, que é o pior estado possível:
 *   `migrate` reclama de objeto duplicado e `rollback` não tem o que desfazer.
 *
 *   Os três erros que o SQLite dos testes **não pega** e que já quebraram deploy neste
 *   projeto (`project_mariadb_nome_indice_64`, `project_mysql_nullondelete_nullable`,
 *   `project_mysql_drop_index_fk` nos learnings):
 *     - **1059** — nome de índice/constraint acima de 64 caracteres.
 *     - **1830** — `nullOnDelete()` em coluna sem `nullable()` explícito.
 *     - **1553** — no `down()`, dropar o índice ANTES da FK que o usa.
 *   E o quarto, que o SQLite pega do jeito errado:
 *     - **`enum`** — o CHECK é enforçado no SQLite e cega a suíte inteira (T-186-09).
 *
 * ⚠️ Lê o TEXTO dos arquivos (não executa a migration): por isso roda em SQLite e ainda assim
 * prova erros de MariaDB. Molde: `tests/Unit/Phase165/PubColunasMigrationGuardaTest.php`.
 *
 * ⚠️⚠️ **TODA SONDA AQUI TEM ISCA** — um controle sintético que a sonda tem de ACUSAR. Sem
 * isca, "não achei violação" é indistinguível de "a sonda não casa nada", e um regex quebrado
 * vira gate verde permanente. A isca do `nullable` pegou de verdade: o molde da Fase 165 pareia
 * por `explode(';')` porque lá a FK e a coluna são o MESMO statement
 * (`foreignId('x')->nullable()->constrained()->nullOnDelete()`); nas migrations deste plano a
 * coluna e a FK são statements SEPARADOS (`unsignedBigInteger('quem_id')->nullable();` e
 * `foreign('quem_id', 'pubapim_quem_fk')->...->nullOnDelete();`), e o pareamento tem de ser
 * **por semântica do nome da coluna**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 */
class MigrationDoPortaoGuardaTest extends TestCase
{
    /** As duas migrations do plano 186-03. */
    private const MIGRATIONS = [
        'database/migrations/2026_10_15_100000_create_pub_aprovacao_conjuntos_tables.php',
        'database/migrations/2026_10_15_100100_create_pub_aprovacao_fidelidade_tables.php',
    ];

    /**
     * ⚠️ ISCA DE CAMINHO. 5 caminhos fantasma em 4 fases deste projeto: migration que não
     * existe faria toda sonda devolver "nenhuma violação" e o gate ficaria verde medindo NADA.
     * `test -e` não é zelo, é obrigatório — e o resultado é `skip` NOMEADO, nunca "passou".
     */
    private const MIGRATION_FANTASMA = 'database/migrations/2026_10_15_999999_esta_migration_nao_existe.php';

    /**
     * O CÓDIGO sem os comentários, pelo tokenizador do PHP.
     *
     * ⚠️⚠️ **ISTO NÃO É COSMÉTICO, E A ISCA PEGOU.** As duas migrations deste plano explicam no
     * docblock, em pt-BR, que **não** usam `try/catch` em volta de DDL e que **não** usam
     * `enum`. Um gate que conta a palavra no arquivo inteiro casa com a frase que diz que a
     * coisa não é usada e reprova o próprio conserto — é a classe de falha registrada em
     * `feedback_gate_que_reprova_pelo_proprio_conserto` e no `/disabled/` que casava com
     * `disabled:opacity-40`. As sondas de PROIBIÇÃO leem daqui; só as de PRESENÇA de docblock
     * leem o texto cru.
     *
     * As quebras de linha do comentário são preservadas para não colar dois statements.
     */
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

    public function test_isca_o_tokenizador_tira_comentario_e_preserva_codigo(): void
    {
        $soNoComentario = "<?php\n// Sem `enum()` e sem try/catch em volta de DDL.\n\$t->string('estado', 14);\n";
        $noCodigo = "<?php\n\$t->enum('estado', ['a']);\n";

        $this->assertStringNotContainsString('enum', $this->semComentarios($soNoComentario), 'ISCA CEGA: o tokenizador não tirou a palavra que só aparecia no comentário.');
        $this->assertStringNotContainsString('try', $this->semComentarios($soNoComentario), 'ISCA CEGA: `try` do comentário sobreviveu — o gate reprovaria pelo próprio conserto.');
        $this->assertStringContainsString("\$t->string('estado', 14)", $this->semComentarios($soNoComentario), 'ISCA: o tokenizador comeu o CÓDIGO junto com o comentário.');
        $this->assertStringContainsString('enum', $this->semComentarios($noCodigo), 'ISCA CEGA: a palavra em CÓDIGO tem de sobreviver, senão a sonda nunca acha nada.');
    }

    /** @return array<string, string> caminho relativo => conteúdo CRU (com comentários) */
    private function conteudos(): array
    {
        $fora = [];
        $textos = [];

        foreach (self::MIGRATIONS as $rel) {
            $abs = base_path($rel);
            if (! file_exists($abs)) {
                $fora[] = $rel;

                continue;
            }
            $textos[$rel] = file_get_contents($abs);
        }

        if ($fora !== []) {
            $this->markTestSkipped('SKIP NOMEADO: migration declarada e ausente no disco — ' . implode(', ', $fora));
        }

        return $textos;
    }

    public function test_isca_de_caminho_migration_inexistente_vira_skip_nomeado(): void
    {
        // A sonda de caminho, isolada: declarada e ausente => `false`, e é isso que dispara o
        // `markTestSkipped` em `conteudos()`. Se um dia `file_exists` passar a devolver `true`
        // para caminho inventado, este teste cai e todas as outras sondas ficam suspeitas.
        $this->assertFileDoesNotExist(base_path(self::MIGRATION_FANTASMA));

        // E o contrapositivo: as duas declaradas EXISTEM. Sem isto, o `skip` seria permanente
        // e silencioso.
        foreach (self::MIGRATIONS as $rel) {
            $this->assertFileExists(base_path($rel), "migration declarada e ausente: {$rel}");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // MIG-04 — sem `enum`
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_nenhuma_migration_do_portao_usa_enum(): void
    {
        $sonda = fn (string $t): bool => (bool) preg_match('/->enum\s*\(/', $t);

        // ISCA: a MESMA sonda tem de ACUSAR o texto sintético que usa enum.
        $this->assertTrue($sonda("\$t->enum('estado', ['pending', 'approved']);"), 'ISCA CEGA: a sonda não achou `->enum(` nem no texto que o TEM.');
        $this->assertFalse($sonda("\$t->string('estado', 14)->default('pending');"), 'ISCA 2: `string()` não é enum.');

        foreach ($this->conteudos() as $rel => $texto) {
            $this->assertFalse($sonda($this->semComentarios($texto)), "{$rel} usa ->enum(): o CHECK é enforçado no SQLite dos testes e cegaria a suíte (MIG-04).");
        }
    }

    public function test_nenhuma_migration_do_portao_usa_change(): void
    {
        foreach ($this->conteudos() as $rel => $texto) {
            $this->assertDoesNotMatchRegularExpression('/->change\s*\(/', $this->semComentarios($texto), "{$rel} usa ->change(), fora da convenção aditiva deste módulo.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // MIG-05 — `up()` idempotente, sem try/catch em volta de DDL
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_nenhuma_migration_do_portao_tem_try_catch(): void
    {
        $sonda = fn (string $t): bool => (bool) preg_match('/\btry\b/', $t);

        // ISCA.
        $this->assertTrue($sonda("try {\n    Schema::create('x', fn () => null);\n} catch (\\Throwable \$e) {}"), 'ISCA CEGA: a sonda não achou `try` nem no texto que o TEM.');

        foreach ($this->conteudos() as $rel => $texto) {
            $this->assertFalse(
                $sonda($this->semComentarios($texto)),
                "{$rel} tem try/catch em volta de DDL: neste projeto um erro 1553 ficou ENGOLIDO por 2 meses dentro de um catch (MIG-05)."
            );
        }
    }

    public function test_todo_bloco_de_create_e_guardado_por_hastable(): void
    {
        foreach ($this->conteudos() as $rel => $texto) {
            preg_match('/public function up\(\).*?public function down\(\)/s', $this->semComentarios($texto), $up);
            $this->assertNotEmpty($up, "não achei o corpo do up() em {$rel}.");

            $creates = preg_match_all('/Schema::create\(/', $up[0]);
            $guardas = preg_match_all('/Schema::hasTable\(/', $up[0]);

            $this->assertGreaterThan(0, $creates, "{$rel} não cria tabela nenhuma — a sonda rodaria sobre nada.");
            $this->assertSame(
                $creates,
                $guardas,
                "{$rel}: {$creates} Schema::create e {$guardas} Schema::hasTable — todo bloco precisa de guarda, "
                . 'senão a re-execução depois de um deploy morto no meio falha com tabela duplicada (MIG-05).'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // MIG-03 — `nullOnDelete()` exige `nullable()` EXPLÍCITO, pareado por SEMÂNTICA da coluna
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return list<string> as colunas com `nullOnDelete()` cuja declaração NÃO tem `nullable()`
     */
    private function violacoesDeNullable(string $texto): array
    {
        $violacoes = [];

        foreach (array_filter(explode(';', $texto), fn ($s) => str_contains($s, 'nullOnDelete()')) as $statement) {
            // A coluna vem do `foreign('col', ...)` ou do `foreignId('col')` do MESMO statement.
            if (! preg_match('/->foreign(?:Id)?\(\s*[\'"]([a-z0-9_]+)[\'"]/', $statement, $m)) {
                $violacoes[] = '(statement com nullOnDelete sem coluna identificável): ' . trim(preg_replace('/\s+/', ' ', $statement));

                continue;
            }
            $coluna = $m[1];

            // `foreignId('col')->nullable()->constrained()->nullOnDelete()` resolve no próprio
            // statement; `foreign('col', 'nome')` exige achar a DECLARAÇÃO da coluna, que é
            // outro statement — é este o pareamento por semântica do nome.
            if (str_contains($statement, 'nullable()')) {
                continue;
            }

            $declaracao = '/\$\w+->(?:unsignedBigInteger|unsignedInteger|bigInteger|integer|foreignId)\(\s*[\'"]'
                . preg_quote($coluna, '/') . '[\'"]\s*\)([^;]*)/';

            if (! preg_match($declaracao, $texto, $d) || ! str_contains($d[1], '->nullable()')) {
                $violacoes[] = $coluna;
            }
        }

        return $violacoes;
    }

    public function test_toda_fk_nullondelete_tem_coluna_nullable_explicito_com_isca(): void
    {
        // ISCA 1 — statements SEPARADOS, coluna SEM nullable: tem de ACUSAR.
        $semNullable = <<<'PHP'
            $t->unsignedBigInteger('quem_id');
            $t->foreign('quem_id', 'x_quem_fk')->references('id')->on('users')->nullOnDelete();
            PHP;
        $this->assertSame(['quem_id'], $this->violacoesDeNullable($semNullable), 'ISCA CEGA 1: a sonda não acusou nullOnDelete sem nullable() (erro 1830).');

        // ISCA 2 — o MESMO par, agora COM nullable: tem de passar. Senão a sonda acusa tudo.
        $comNullable = <<<'PHP'
            $t->unsignedBigInteger('quem_id')->nullable();
            $t->foreign('quem_id', 'x_quem_fk')->references('id')->on('users')->nullOnDelete();
            PHP;
        $this->assertSame([], $this->violacoesDeNullable($comNullable), 'ISCA 2: a sonda acusou um par correto — ela acusaria tudo.');

        // ISCA 3 — a forma encadeada do molde da Fase 165, num statement só.
        $encadeado = "\$t->foreignId('pub_imagem_id')->nullable()->constrained('pub_imagens')->nullOnDelete();";
        $this->assertSame([], $this->violacoesDeNullable($encadeado), 'ISCA 3: a sonda não entende a forma encadeada do `foreignId`.');

        // ISCA 4 — a mesma forma encadeada SEM nullable.
        $encadeadoRuim = "\$t->foreignId('pub_imagem_id')->constrained('pub_imagens')->nullOnDelete();";
        $this->assertSame(['pub_imagem_id'], $this->violacoesDeNullable($encadeadoRuim), 'ISCA CEGA 4: a sonda não acusou a forma encadeada sem nullable().');

        foreach ($this->conteudos() as $rel => $texto) {
            $texto = $this->semComentarios($texto);
            $this->assertNotEmpty(
                array_filter(explode(';', $texto), fn ($s) => str_contains($s, 'nullOnDelete()')),
                "{$rel} não tem nenhuma FK com nullOnDelete() — a sonda rodaria sobre nada."
            );
            $this->assertSame(
                [],
                $this->violacoesDeNullable($texto),
                "{$rel}: FK com nullOnDelete() em coluna sem ->nullable() explícito — erro 1830 no MariaDB, invisível no SQLite (MIG-03)."
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // MIG-02 — nenhum nome acima de 64, INCLUSIVE os nomes PADRÃO do Laravel
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, int> nome declarado à mão => comprimento */
    private function nomesDeclarados(string $texto): array
    {
        $nomes = [];

        // `index`/`unique`/`dropIndex`: o nome é a ÚLTIMA string do argumento.
        preg_match_all('/->(?:index|unique|dropIndex)\(((?:[^()]|\([^()]*\))*)\)/', $texto, $chamadas);
        foreach ($chamadas[1] as $args) {
            preg_match_all("/'([a-zA-Z0-9_]+)'/", $args, $strings);
            if ($strings[1] !== []) {
                $nomes[end($strings[1])] = strlen(end($strings[1]));
            }
        }

        // `foreign('coluna', 'nome_da_fk')`: o nome é o 2º argumento.
        preg_match_all('/->foreign\(\s*[\'"][a-z0-9_]+[\'"]\s*,\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\)/', $texto, $fks);
        foreach ($fks[1] as $nome) {
            $nomes[$nome] = strlen($nome);
        }

        return $nomes;
    }

    public function test_nenhum_nome_de_indice_ou_fk_declarado_passa_de_64_com_isca(): void
    {
        $longo = str_repeat('a', 65);

        // ISCA: a sonda tem de ENCONTRAR o nome gigante, nas duas formas.
        $this->assertArrayHasKey($longo, $this->nomesDeclarados("\$t->index('coluna', '{$longo}');"), 'ISCA CEGA: a sonda não achou o nome de índice gigante.');
        $this->assertArrayHasKey($longo, $this->nomesDeclarados("\$t->foreign('coluna', '{$longo}')->references('id')->on('x');"), 'ISCA CEGA: a sonda não achou o nome de FK gigante.');

        foreach ($this->conteudos() as $rel => $texto) {
            $nomes = $this->nomesDeclarados($this->semComentarios($texto));
            $this->assertNotEmpty($nomes, "{$rel} não declara nome de índice/FK nenhum — a sonda rodaria sobre nada.");

            foreach ($nomes as $nome => $tamanho) {
                $this->assertLessThanOrEqual(
                    64,
                    $tamanho,
                    "{$rel}: o nome '{$nome}' tem {$tamanho} caracteres — o MariaDB recusa acima de 64 (erro 1059) e deixa a migration Pending com a tabela JÁ criada."
                );
            }
        }
    }

    public function test_os_nomes_padrao_do_laravel_tambem_cabem_em_64_e_toda_fk_e_nomeada_a_mao(): void
    {
        // ⚠️ São os nomes PADRÃO (`<tabela>_<coluna>_foreign`) que estouram, não os à mão — e
        // eles não aparecem no texto, têm de ser COMPUTADOS. Calculados por `strlen`, nunca por
        // número copiado: foi assim que se mediu que o 186-03-PLAN.md errava em 1 o mais longo
        // (dizia 57 para `pub_aprovacao_imagem_alertas_aprovacao_imagem_id_foreign`, são 56).
        $pares = [
            ['pub_aprovacao_conjuntos', 'rascunho_id'],
            ['pub_aprovacao_conjuntos', 'principal_imagem_id'],
            ['pub_aprovacao_imagens', 'conjunto_id'],
            ['pub_aprovacao_imagens', 'imagem_id'],
            ['pub_aprovacao_imagens', 'quem_id'],
            ['pub_aprovacao_imagem_alertas', 'aprovacao_imagem_id'],
            ['pub_aprovacao_fidelidade', 'aprovacao_imagem_id'],
            ['pub_aprovacao_fidelidade', 'quem_id'],
            ['pub_aprovacao_defeitos', 'aprovacao_imagem_id'],
            ['pub_aprovacao_defeitos', 'quem_id'],
        ];

        $maior = 0;
        foreach ($pares as [$tabela, $coluna]) {
            $padrao = "{$tabela}_{$coluna}_foreign";
            $maior = max($maior, strlen($padrao));
            $this->assertLessThanOrEqual(64, strlen($padrao), "o nome padrão '{$padrao}' teria " . strlen($padrao) . ' caracteres (erro 1059).');
        }
        $this->assertSame(56, $maior, 'o nome padrão mais longo das 5 tabelas mudou — reconferir a tabela de decisão no docblock das migrations.');

        // ISCA: um par sintético longo tem de ESTOURAR. Senão o `assertLessThanOrEqual` acima
        // passaria mesmo com a régua quebrada.
        $gigante = str_repeat('t', 50) . '_' . str_repeat('c', 20) . '_foreign';
        $this->assertGreaterThan(64, strlen($gigante), 'ISCA CEGA: a régua de 64 não reprova nem um nome de 71 caracteres.');

        // E a convenção do módulo: NENHUMA FK fica com o nome padrão. Toda `foreign()` tem o
        // 2º argumento, e nenhuma usa a forma `foreignId()->constrained()` (que gera o padrão).
        foreach ($this->conteudos() as $rel => $texto) {
            $texto = $this->semComentarios($texto);
            $todas = preg_match_all('/->foreign\(/', $texto);
            $nomeadas = preg_match_all('/->foreign\(\s*[\'"][a-z0-9_]+[\'"]\s*,\s*[\'"][a-zA-Z0-9_]+[\'"]\s*\)/', $texto);

            $this->assertGreaterThan(0, $todas, "{$rel} não declara FK nenhuma — a sonda rodaria sobre nada.");
            $this->assertSame($todas, $nomeadas, "{$rel}: alguma ->foreign() ficou sem nome explícito e cairia no nome padrão do Laravel.");
            $this->assertDoesNotMatchRegularExpression('/->constrained\(/', $texto, "{$rel}: ->constrained() gera o nome PADRÃO da FK, fora da convenção deste módulo.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // 1553 — a ordem no `down()`
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return list<string> os `dropIndex` que NÃO são precedidos de um `dropForeign([...])`
     */
    private function dropIndexSemDropForeignAntes(string $texto): array
    {
        preg_match('/public function down\(\).*$/s', $texto, $down);
        if ($down === []) {
            return ['(sem corpo de down())'];
        }

        $faltando = [];
        preg_match_all('/->dropIndex\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\)/', $down[0], $indices, PREG_OFFSET_CAPTURE);

        foreach ($indices[1] as $i => [$nome, $_]) {
            $posicao = $indices[0][$i][1];
            $antes = substr($down[0], 0, $posicao);

            // ⚠️ `dropForeign([` em ARRAY: por NOME o SQLite recusa (learnings §9).
            if (! preg_match('/->dropForeign\(\s*\[/', $antes)) {
                $faltando[] = $nome;
            }
        }

        return $faltando;
    }

    public function test_quando_houver_dropindex_ele_vem_depois_do_dropforeign_em_array_com_isca(): void
    {
        // ISCA 1 — ordem ERRADA (dropIndex antes do dropForeign): tem de ACUSAR.
        $ordemErrada = <<<'PHP'
            public function down(): void
            {
                Schema::table('x', function (Blueprint $t) {
                    $t->dropIndex('x_col_ix');
                    $t->dropForeign(['col']);
                });
            }
            PHP;
        $this->assertSame(['x_col_ix'], $this->dropIndexSemDropForeignAntes($ordemErrada), 'ISCA CEGA 1: a sonda não acusou dropIndex ANTES do dropForeign (erro 1553).');

        // ISCA 2 — ordem CERTA: tem de passar.
        $ordemCerta = <<<'PHP'
            public function down(): void
            {
                Schema::table('x', function (Blueprint $t) {
                    $t->dropForeign(['col']);
                    $t->dropIndex('x_col_ix');
                });
            }
            PHP;
        $this->assertSame([], $this->dropIndexSemDropForeignAntes($ordemCerta), 'ISCA 2: a sonda acusou a ordem correta — ela acusaria tudo.');

        // ISCA 3 — `dropForeign` por NOME (string) não conta: o SQLite recusa essa forma.
        $porNome = <<<'PHP'
            public function down(): void
            {
                Schema::table('x', function (Blueprint $t) {
                    $t->dropForeign('x_col_fk');
                    $t->dropIndex('x_col_ix');
                });
            }
            PHP;
        $this->assertSame(['x_col_ix'], $this->dropIndexSemDropForeignAntes($porNome), 'ISCA CEGA 3: `dropForeign` por NOME passou como se fosse a forma de ARRAY.');

        foreach ($this->conteudos() as $rel => $texto) {
            $this->assertSame([], $this->dropIndexSemDropForeignAntes($this->semComentarios($texto)), "{$rel}: dropIndex antes do dropForeign correspondente (erro 1553 no MariaDB).");
        }
    }

    /**
     * ⚠️ As duas migrations deste plano só CRIAM, então o `down()` delas **não tem `dropIndex`
     * nenhum** e a sonda acima roda sobre zero casos — o que seria um gate vazio por
     * construção se parasse aqui. O invariante que VALE para migration de `create` é outro, e
     * é este teste que o mede: o `down()` derruba TABELAS (não colunas), **filha antes da mãe**.
     */
    public function test_o_down_das_migrations_de_create_derruba_tabelas_filha_antes_da_mae(): void
    {
        // Filha => mãe. A ordem no texto do `down()` tem de ser esta.
        $ordemEsperada = [
            self::MIGRATIONS[0] => ['pub_aprovacao_imagem_alertas', 'pub_aprovacao_imagens', 'pub_aprovacao_conjuntos'],
            self::MIGRATIONS[1] => ['pub_aprovacao_defeitos', 'pub_aprovacao_fidelidade'],
        ];

        foreach ($this->conteudos() as $rel => $texto) {
            preg_match('/public function down\(\).*$/s', $this->semComentarios($texto), $down);
            $this->assertNotEmpty($down, "não achei o corpo do down() em {$rel}.");

            preg_match_all('/Schema::dropIfExists\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\)/', $down[0], $drops);

            $this->assertSame(
                $ordemEsperada[$rel],
                $drops[1],
                "{$rel}: o down() deveria derrubar as tabelas na ordem filha->mãe. No MariaDB o drop da mãe com a filha viva FALHA."
            );

            // E não derruba coluna: migration de `create` desfaz a tabela inteira.
            $this->assertDoesNotMatchRegularExpression('/->dropColumn\(/', $down[0], "{$rel}: down() de migration de `create` não derruba coluna, derruba a tabela.");
        }

        // ISCA: a sonda de ordem tem de ACUSAR a ordem invertida.
        $invertida = "public function down(): void\n{\n    Schema::dropIfExists('mae');\n    Schema::dropIfExists('filha');\n}";
        preg_match_all('/Schema::dropIfExists\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\)/', $invertida, $d);
        $this->assertSame(['mae', 'filha'], $d[1], 'ISCA CEGA: a sonda de ordem não lê a sequência real dos drops.');
        $this->assertNotSame(['filha', 'mae'], $d[1], 'ISCA: a ordem invertida tem de ser distinguível da correta.');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // MIG-07 / MIG-09 — a tabela de decisão existe, e a migration só CRIA
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_cada_migration_traz_a_tabela_de_decisao_de_schema_no_docblock_antes_do_codigo(): void
    {
        foreach ($this->conteudos() as $rel => $texto) {
            $this->assertStringContainsString('DECISÃO DE SCHEMA', $texto, "{$rel} não traz a tabela de decisão de schema (MIG-07).");

            $posDecisao = strpos($texto, 'DECISÃO DE SCHEMA');
            $posCodigo = strpos($texto, 'return new class');
            $this->assertNotFalse($posCodigo, "não achei o `return new class` em {$rel}.");
            $this->assertLessThan($posCodigo, $posDecisao, "{$rel}: a tabela de decisão tem de vir ANTES do código, não depois.");

            // A tabela é markdown: pelo menos uma linha de cabeçalho com separador.
            $this->assertMatchesRegularExpression('/\|\s*coluna\s*\|\s*tipo\s*\|/', $texto, "{$rel}: a decisão de schema não está em tabela (coluna · tipo · regra · por quê).");
        }
    }

    public function test_as_migrations_do_portao_so_criam_nao_apagam_nem_renomeiam(): void
    {
        foreach ($this->conteudos() as $rel => $texto) {
            preg_match('/public function up\(\).*?public function down\(\)/s', $this->semComentarios($texto), $up);
            $this->assertNotEmpty($up, "não achei o corpo do up() em {$rel}.");

            foreach (['->dropColumn(', '->renameColumn(', 'Schema::rename(', 'Schema::drop(', '::table(', 'DB::statement', '->delete(', '->truncate('] as $proibido) {
                $this->assertStringNotContainsString(
                    $proibido,
                    $up[0],
                    "{$rel}: o up() usa `{$proibido}` — esta migration só CRIA; nenhuma linha é apagada e nenhuma coluna é renomeada (MIG-09)."
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // O precedente do texto de 600: indexa-se o HASH, nunca o texto
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_grupo_chave_de_600_chars_nunca_entra_num_indice(): void
    {
        foreach ($this->conteudos() as $rel => $texto) {
            preg_match_all('/->(?:index|unique)\(((?:[^()]|\([^()]*\))*)\)/', $this->semComentarios($texto), $chamadas);

            foreach ($chamadas[1] as $args) {
                $this->assertStringNotContainsString(
                    'grupo_chave',
                    $args,
                    "{$rel}: `grupo_chave` entrou num índice — string(600) em utf8mb4 são até 2400 bytes e estouram "
                    . 'o limite de 3072 bytes de chave do InnoDB. Indexa-se o HASH (`grupo_hash`), nunca o texto.'
                );
            }
        }

        // E o hash ESTÁ indexado — senão a consulta por grupo faria varredura.
        $codigo = $this->semComentarios($this->conteudos()[self::MIGRATIONS[0]]);
        $this->assertStringContainsString("'grupo_hash'", $codigo);
        $this->assertMatchesRegularExpression(
            '/->unique\(\[\s*[\'"]rascunho_id[\'"]\s*,\s*[\'"]grupo_hash[\'"]\s*\]/',
            $codigo,
            'o unique (rascunho_id, grupo_hash) é o que garante UM conjunto por grupo de fotos do rascunho.'
        );
    }
}
