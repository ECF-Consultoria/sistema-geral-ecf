<?php

namespace Tests\Feature\Publicador\Aprovacao;

use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 188, plano 01 — OS GATES DA ABERTURA: as baselines e a tabela FID-01.
 *
 * ⚠️⚠️ **POR QUE ESTE ARQUIVO EXISTE, e a resposta não é "porque o plano pediu".**
 *
 * O `<verify>` da tarefa 1 era, na primeira redação, **só** `verificar-testes.sh --autoteste`. A
 * pergunta que desmascara todo gate — *"o que precisaria estar errado no CÓDIGO para este gate
 * falhar?"* — tinha a resposta **"nada"**: para o `--autoteste` reprovar, o próprio harness teria de
 * estar quebrado, e um `188-BASELINES.md` **VAZIO, ou com números inventados, PASSAVA**. "Nada" é
 * **ACHADO**, não tranquilidade (learnings §4).
 *
 * ➡️ Estes métodos leem os DOCUMENTOS e cobram a **FORMA** deles.
 *
 * ═══ ⚠️ O QUE ESTE ARQUIVO **NÃO** FAZ, E A RAZÃO MEDIDA ═══════════════════════════════════════
 *
 * **1. NÃO confere o VALOR dos números de suíte.** É a mesma disciplina do
 * `FechamentoDaFase187Test`: *"o fechamento é MEDIÇÃO DATADA, não gate perene; os números valem
 * para o commit medido e não devem ser 'atualizados' por sessão futura"*. Um gate que cravasse
 * `JS: 5489` aqui seria **régua que sobe a cada wave** — a família de fragilidade com **nove**
 * ocorrências nesta milestone, e cada subida *treina o executor seguinte a ajustar réguas quando
 * elas incomodam*. ➡️ **Cobra-se que o número seja INTEIRO e esteja ESCRITO, não qual ele é.**
 *
 * **2. NÃO usa range de git.** A 187-11 mediu que range não passa nesta árvore: 111 commits, **43
 * da sessão paralela**, e o diff cru **acusava 12 dos 60 alvos vetados**. ⚠️ E **o AUTOR não
 * discrimina nada**: as três máquinas desta árvore commitam com o mesmo `user.name`.
 *
 * **3. NÃO lê o protótipo.** `design_handoff_publicador/` **não é versionado** (`??`) e não existe
 * na máquina de outro dev. O que se cobra é o **sha256 ESCRITO no documento**, que é verificável em
 * qualquer máquina. ⚠️ O gate que precisa do arquivo degrada para `markTestSkipped` **explícito e
 * nomeado** — nunca para "conferido".
 *
 * **4. NÃO cria `REF_PHP_FALHAS`.** O harness declara, na própria fonte, que esse limiar não deve
 * passar a existir. O teto de falhas conferido aqui é sobre o **texto do documento**, não sobre a
 * suíte, e serve para pegar baseline que REGISTRA regressão.
 *
 * ⚠️ **Todo método leva `_188_01` no nome.** Conferido por script contra **todo** `test_*` de
 * `tests/`, nas DUAS direções (meu contém alheio / alheio contém o meu), **incluindo prefixo**:
 * `ARQUIVOS=1208 METODOS=7755 HOMONIMOS=0`. O `--filter` do PHPUnit é regex NÃO ANCORADO e as
 * Fases 186 e 187 estão EM PRODUÇÃO — superstring de um método delas faria o id daquela fase SOMAR.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class FechamentoDeBaselines188Test extends TestCase
{
    private const DIR_FASE = '.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece';

    /** O sha256 curto do protótipo de autoridade. CONSTANTE: se ele mudar, a FID-01 mede outro documento. */
    private const SHA_PROTOTIPO = '68624883ce35547e';

    /** As 7 chaves de forma fixa do `188-BASELINES.md`. Lista CONSTANTE, nunca contagem. */
    private const CHAVES_DA_BASELINE = ['PARTIDA', 'COMMIT', 'BUILD', 'MANIFEST', 'JS', 'PHP', 'PROTOTIPO'];

    /**
     * ⚠️ A pergunta do GATE-01, respondida: *o que precisaria estar errado para este gate falhar?*
     *   · alguém **apagar** o `188-BASELINES.md`, ou deixá-lo como esqueleto;
     *   · alguém escrever uma das 7 linhas **vazia** ou com travessão em vez de número;
     *   · alguém pôr um sha de commit que não é sha, ou um número que não é inteiro;
     *   · alguém registrar **mais falhas** do que a régua herdada aceita, em vez de investigar;
     *   · alguém trocar o **sha256 do protótipo** (a tabela FID-01 inteira passaria a medir outro
     *     documento, e NADA mais no repositório pegaria isso);
     *   · alguém afirmar um número **sem escrever o comando** que o produziu — que é a diferença
     *     entre baseline medida e baseline afirmada (T-188-03, Repudiation).
     *
     * ⚠️⚠️ **ISCA NAS DUAS DIREÇÕES, e ela é o que impede este gate de ser decorativo:** a mesma
     * validação roda sobre o documento REAL (tem de PASSAR) e sobre uma cópia ADULTERADA em
     * memória, com os números trocados por travessões (tem de REPROVAR). Sem a segunda metade eu
     * não saberia se a validação mede algo — e "asserção de ausência rodada com dado vazio é vazia
     * por construção" (learnings §4, regra 4).
     * ⚠️ A adulteração é **em memória**: nenhum arquivo do repositório é tocado.
     */
    public function test_as_baselines_da_fase_188_estao_medidas_e_nao_afirmadas_188_01(): void
    {
        $caminho = base_path(self::DIR_FASE . '/188-BASELINES.md');
        $this->assertFileExists($caminho, 'o 188-BASELINES.md não existe — os outros 12 planos leem dele');
        $texto = (string) file_get_contents($caminho);

        $this->assertGreaterThan(
            3000,
            strlen($texto),
            'o 188-BASELINES.md é curto demais para conter as 7 linhas, a tabela mecânica e as falhas nomeadas'
        );

        // ── 1. O documento REAL tem de PASSAR a validação ───────────────────────────────────────
        $this->assertSame(
            [],
            $this->problemasDaBaseline($texto),
            'o 188-BASELINES.md real reprovou a própria validação de forma'
        );

        // ── 2. ⚠️ ISCA INVERTIDA: a cópia ADULTERADA tem de REPROVAR ────────────────────────────
        // Troca os números das linhas JS e PHP por travessões — exatamente o "deixa o número para
        // depois" que esta fase não aceita.
        $adulterado = preg_replace(
            ['/^JS:\s.*$/m', '/^PHP:\s.*$/m'],
            ['JS:        — / — pass / — fail / — skipped', 'PHP:       — / — / —'],
            $texto
        );
        $this->assertNotSame($texto, $adulterado, 'a adulteração da isca não alterou nada — a isca não exerce a validação');
        $this->assertNotSame(
            [],
            $this->problemasDaBaseline((string) $adulterado),
            'a validação ACEITOU uma baseline com os números trocados por travessões — ela não mede nada'
        );

        // ── 3. ⚠️ ISCA INVERTIDA 2: trocar o sha do protótipo tem de REPROVAR ───────────────────
        $outroSha = str_replace(self::SHA_PROTOTIPO, '0000000000000000', $texto);
        $this->assertNotSame($texto, $outroSha, 'a isca do sha não alterou nada');
        $this->assertNotSame(
            [],
            $this->problemasDaBaseline($outroSha),
            'a validação ACEITOU um sha256 de protótipo diferente — a FID-01 passaria a medir outro documento sem ninguém ver'
        );
    }

    /**
     * A validação de FORMA do `188-BASELINES.md`. Devolve a lista de problemas — **vazia** quando
     * está tudo certo.
     *
     * ⚠️ Devolve LISTA, e não lança, exatamente para a isca invertida poder exercê-la duas vezes.
     * ⚠️ **UMA** asserção coletando infratores no chamador, nunca uma por chave: asserção por chave
     * faria a contagem do gate crescer junto com a lista, que é a fragilidade de learnings §4b.2.
     *
     * @return list<string>
     */
    private function problemasDaBaseline(string $texto): array
    {
        $problemas = [];

        // (a) As 7 chaves presentes, cada uma com conteúdo NÃO-VAZIO depois dos dois-pontos.
        foreach (self::CHAVES_DA_BASELINE as $chave) {
            if (preg_match('/^' . preg_quote($chave, '/') . ':[ \t]*(\S.*)$/m', $texto, $m) !== 1) {
                $problemas[] = "linha '{$chave}:' ausente ou vazia";
                continue;
            }
            // Travessão, "a definir", "TODO" e afins não são medição.
            if (preg_match('/^(—|-|\?|a definir|TODO|FIXME|n\/a)/iu', trim($m[1])) === 1) {
                $problemas[] = "linha '{$chave}:' não traz medição ('" . trim($m[1]) . "')";
            }
        }

        // (b) PARTIDA e COMMIT casam sha curto de 7 a 40 hexas.
        foreach (['PARTIDA', 'COMMIT'] as $chave) {
            if (preg_match('/^' . $chave . ':[ \t]*([0-9a-f]{7,40})\b/m', $texto) !== 1) {
                $problemas[] = "'{$chave}:' não começa por um sha de 7 a 40 hexas minúsculos";
            }
        }

        // (c) Os 4 números do JS — INTEIROS, não "qual número".
        if (preg_match('/^JS:[ \t]*(\d+)[ \t]*\/[ \t]*(\d+) pass[ \t]*\/[ \t]*(\d+) fail[ \t]*\/[ \t]*(\d+) skipped/m', $texto, $js) !== 1) {
            $problemas[] = "'JS:' não está na forma '<testes> / <pass> pass / <fail> fail / <skipped> skipped'";
        } elseif ((int) $js[3] > 1) {
            // A única falha JS aceita é a pré-existente de `estrutura-grade-glide.test.js`
            // (Fase 87), que é o `REF_JS_FALHAS=1` cravado no harness.
            $problemas[] = "'JS:' registra {$js[3]} falhas — acima da 1 herdada; falha a mais é DA FASE";
        }

        // (d) Os 3 números do PHP — INTEIROS.
        if (preg_match('/^PHP:[ \t]*(\d+)[ \t]*\/[ \t]*([\d.]+)[ \t]*\/[ \t]*(\d+)/m', $texto, $php) !== 1) {
            $problemas[] = "'PHP:' não está na forma '<testes> / <asserções> / <falhas>'";
        } elseif ((int) $php[3] > 2) {
            // ⚠️ O teto é 2 porque é a oscilação herdada das DUAS de `CockpitDaContaTest`
            // (Fase 179, fronteira de dia). Hoje elas estão VERDES e a medida é 0 — o teto existe
            // para pegar baseline que REGISTRA regressão, não para perdoar falha nova.
            $problemas[] = "'PHP:' registra {$php[3]} falhas — acima das 2 da oscilação herdada; é DA FASE";
        }

        // (e) O sha256 do protótipo, IGUAL ao constante. Igualdade, não `includes` solto.
        if (preg_match('/^PROTOTIPO:.*\bsha256 ([0-9a-f]{16,64})\b/m', $texto, $sha) !== 1) {
            $problemas[] = "'PROTOTIPO:' não traz 'sha256 <hexas>'";
        } elseif (! str_starts_with($sha[1], self::SHA_PROTOTIPO)) {
            $problemas[] = "o sha256 do protótipo é '{$sha[1]}' e deveria começar por '" . self::SHA_PROTOTIPO . "'";
        }

        // (f) O número de linhas do protótipo — INTEIRO e escrito.
        if (preg_match('/^PROTOTIPO:.*\b(\d+) linhas\b/m', $texto) !== 1) {
            $problemas[] = "'PROTOTIPO:' não traz '<N> linhas'";
        }

        // (g) ⚠️ O COMANDO de cada chave escrito AO LADO dela. É o que separa baseline medida de
        // baseline afirmada (T-188-03). A forma é a linha de tabela `| `CHAVE` | … |`.
        foreach (self::CHAVES_DA_BASELINE as $chave) {
            if (preg_match('/^\|\s*`' . preg_quote($chave, '/') . '`\s*\|\s*(\S[^|]*)\|/m', $texto, $cmd) !== 1) {
                $problemas[] = "não há linha de tabela com o comando de '{$chave}'";
            } elseif (strlen(trim($cmd[1])) < 10) {
                $problemas[] = "o comando de '{$chave}' é curto demais para ser um comando";
            }
        }

        // (h) As falhas alheias NOMEADAS por semântica: o arquivo e os dois métodos.
        foreach ([
            'tests/Feature/Publicador/CockpitDaContaTest.php',
            'test_publicacoes_criadas_mede_as_duas_janelas_e_a_serie_de_trinta_dias',
            'test_evento_sem_data_vai_para_o_ultimo_grupo_nunca_para_o_topo',
            'estrutura-grade-glide.test.js',
        ] as $nome) {
            if (! str_contains($texto, $nome)) {
                $problemas[] = "a falha alheia '{$nome}' não está nomeada no documento";
            }
        }

        // (i) ⚠️ O caminho FANTASMA não pode ser citado como se existisse. Ele é o NONO desta
        // milestone, e o documento só pode mencioná-lo para dizer que NÃO existe.
        if (str_contains($texto, 'tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php')
            && ! preg_match('/Aprovacao\/CockpitDaContaTest\.php`?\*{0,2}\s*(NÃO|NAO)\s*EXISTE/u', $texto)) {
            $problemas[] = 'o caminho fantasma de CockpitDaConta é citado sem dizer que NÃO EXISTE';
        }

        return $problemas;
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    // A TABELA FID-01 RE-MEDIDA
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    /** As seções da FID-01 que são TABELA DE MEDIÇÃO e têm de declarar como foram medidas. */
    private const SECOES_MEDIDAS = ['B', 'C', 'D', 'E', 'F'];

    /** A lista FECHADA de classificações de divergência. Fora dela, reprova. */
    private const CLASSIFICACOES = ['OMISSÃO', 'REFERÊNCIA', 'VALOR'];

    /** O tamanho medido do protótipo. Ponteiro acima disto é erro de REFERÊNCIA. */
    private const LINHAS_DO_PROTOTIPO = 1432;

    /**
     * ⚠️⚠️ **POR QUE ESTE MÉTODO EXISTE.** A primeira redação do plano rodava, no `<verify>` da
     * tarefa 3, o gate `tokens-e-tamanhos` — **o gate da TAREFA 2**. Ele não media **nada** desta
     * tarefa: a tabela FID-01 podia ficar **intocada** e o verify passava verde. É "gate sobre zero
     * casos" com outra roupa.
     *
     * ⚠️ A pergunta do GATE-01, respondida: *o que precisaria estar errado para este gate falhar?*
     *   · uma seção de medição **deixar de dizer** se foi medida por `SCRIPT` ou por `LEITURA` —
     *     *"tabela derivada de SCRIPT é confiável; tabela derivada de LEITURA não é"*;
     *   · a **§I** desaparecer, ou ficar **vazia por construção** (que é indistinguível de "não
     *     re-mediu");
     *   · uma divergência ser classificada **fora da lista fechada** de três valores;
     *   · um ponteiro apontar para **fora do protótipo** (> 1432) — que é precisamente como esta
     *     tabela mais erra;
     *   · a **PERGUNTA-188-A** perder a resposta, a razão ou o registro da derrota do protótipo.
     *
     * ⚠️ **RECORTE DE SEÇÃO SEM O PONTO SOB `/s`.** O cabeçalho usa classe negada de newline
     * (`[^\n]*`) e o corpo é construído por repetição de `(?:[^\n]*\n)` com lookahead — **nunca**
     * `.*` sob `/s`, que **engole o documento inteiro** e devolve corpo VAZIO (medido no fechamento
     * da 187, learnings §4c.4). Um corpo vazio faria toda asserção de ausência passar, e há
     * asserção explícita contra isso dentro do validador.
     *
     * ⚠️⚠️ **E A DEGRADAÇÃO NÃO É `markTestSkipped`, por uma razão MEDIDA:** no modo `php` o harness
     * exige a linha `Tests: N passed (A assertions)`; um teste **pulado** imprime `1 skipped` e o
     * gate **REPROVARIA** — o "skip" derrubaria o gate em vez de degradá-lo. ➡️ A degradação é
     * **razão NOMEADA registrada numa lista**, e a asserção final exige que ela esteja nomeada.
     * Nunca "conferido".
     */
    public function test_a_tabela_fid01_declara_como_mediu_e_lista_as_divergencias_188_01(): void
    {
        $caminho = base_path(self::DIR_FASE . '/188-FID01.md');
        $this->assertFileExists($caminho, 'o 188-FID01.md não existe — é a tabela de fidelidade dos 13 planos');
        $texto = (string) file_get_contents($caminho);

        // ── 1. O documento REAL tem de PASSAR ───────────────────────────────────────────────────
        $this->assertSame([], $this->problemasDaFid01($texto), 'o 188-FID01.md real reprovou a própria validação de forma');

        // ── 2. ⚠️ ISCA INVERTIDA: §I **vazia por construção** tem de REPROVAR ───────────────────
        // Apaga as sub-seções de classificação. O que sobra é uma §I que existe e não diz nada —
        // exatamente o estado "não re-mediu" disfarçado de re-medição.
        $semDivergencias = (string) preg_replace('/^### §I\.\d+ — .*$/mu', '### §I.X — (apagado pela isca)', $texto);
        $this->assertNotSame($texto, $semDivergencias, 'a isca da §I não alterou nada — ela não exerce a validação');
        $this->assertNotSame(
            [],
            $this->problemasDaFid01($semDivergencias),
            'a validação ACEITOU uma §I sem classificação nenhuma — ela não distingue "re-mediu" de "não re-mediu"'
        );

        // ── 2b. ⚠️⚠️ O CAMINHO DE ESCAPE TEM DE FUNCIONAR ───────────────────────────────────────
        // Uma fase futura pode re-medir e **não achar divergência nenhuma** — e nesse caso ela
        // escreve o cabeçalho de escape. Se o regex do escape estivesse errado, aquela fase seria
        // **obrigada a inventar divergência** para o gate passar, que é a pior coisa que um gate
        // pode induzir. ⚠️ "Asserção VAZIA quando escrita vira FALSO POSITIVO depois": o escape
        // nunca é exercido pelo documento de hoje, então ele é exercido AQUI, de propósito.
        $soEscape = (string) preg_replace(
            '/^### §I\.\d+ — .*$/mu',
            '### §I.1 — NENHUMA DIVERGÊNCIA NESTA RE-MEDIÇÃO',
            $texto
        );
        $this->assertNotContains(
            'a §I não traz classificação nenhuma E não traz a linha explícita '
                . '"nenhuma divergência nesta re-medição" — vazia por construção',
            $this->problemasDaFid01($soEscape),
            'o caminho de ESCAPE não funciona: uma re-medição sem divergências seria obrigada a inventar uma'
        );

        // ── 3. ⚠️ ISCA INVERTIDA: seção sem declarar SCRIPT/LEITURA tem de REPROVAR ─────────────
        $semDeclaracao = (string) preg_replace('/\*\*COMO MEDIDA: `(SCRIPT|LEITURA)`\.\*\*/u', '(sem declarar)', $texto);
        $this->assertNotSame($texto, $semDeclaracao, 'a isca da declaração não alterou nada');
        $this->assertNotSame(
            [],
            $this->problemasDaFid01($semDeclaracao),
            'a validação ACEITOU seções de medição que não dizem se foram medidas por SCRIPT ou por LEITURA'
        );

        // ── 4. ⚠️ ISCA INVERTIDA: ponteiro FORA do protótipo tem de REPROVAR ────────────────────
        $this->assertNotSame(
            [],
            $this->problemasDaFid01($texto . "\n\nlinha inventada apontando para `:9999` fora do arquivo\n"),
            'a validação ACEITOU um ponteiro para além das ' . self::LINHAS_DO_PROTOTIPO . ' linhas do protótipo'
        );

        // ── 5. ⚠️ ISCA INVERTIDA: classificação FORA da lista fechada tem de REPROVAR ───────────
        $classeInventada = str_replace('### §I.2 — `VALOR`', '### §I.2 — `APROXIMADO`', $texto);
        $this->assertNotSame($texto, $classeInventada, 'a isca da classificação não alterou nada');
        $this->assertNotSame(
            [],
            $this->problemasDaFid01($classeInventada),
            'a validação ACEITOU uma classificação fora da lista fechada OMISSÃO/REFERÊNCIA/VALOR'
        );

        // ── 6. ⚠️ A DEGRADAÇÃO NOMEADA (nunca "conferido", nunca markTestSkipped) ───────────────
        $pulados = [];
        $proto = base_path('design_handoff_publicador/Aprovacao.dc.html');
        if (! file_exists($proto)) {
            $pulados[] = 'SKIP NOMEADO: design_handoff_publicador/Aprovacao.dc.html não está no disco '
                . '(pacote de handoff NÃO versionado). A conferência das 1432 linhas fica PULADA; a '
                . 'forma do documento continua cobrada pelos itens 1 a 5.';
        } else {
            $this->assertSame(
                self::LINHAS_DO_PROTOTIPO,
                substr_count((string) file_get_contents($proto), "\n"),
                'o protótipo mudou de tamanho — a tabela FID-01 inteira passaria a medir outro documento'
            );
        }
        foreach ($pulados as $motivo) {
            $this->assertStringContainsString('SKIP NOMEADO', $motivo, 'degradou sem nomear o skip');
        }
        $this->assertLessThanOrEqual(1, count($pulados), 'mais de uma degradação: o gate virou decorativo');
    }

    /**
     * A validação de FORMA do `188-FID01.md`. Devolve a lista de problemas — **vazia** quando está
     * tudo certo. ⚠️ Devolve LISTA (não lança) para as cinco iscas invertidas poderem exercê-la.
     * ⚠️ **UMA** asserção coletando infratores no chamador, nunca uma por seção: asserção por seção
     * faria a contagem do gate crescer junto com o número de seções (learnings §4b.2).
     *
     * @return list<string>
     */
    private function problemasDaFid01(string $texto): array
    {
        $problemas = [];

        // (a) Toda seção de medição declara SCRIPT ou LEITURA.
        // ⚠️ Recorte SEM `.` sob `/s`: cabeçalho por classe negada de newline, corpo por repetição
        // de linha com lookahead do próximo `## `.
        foreach (self::SECOES_MEDIDAS as $letra) {
            $rx = '/^## §' . $letra . ' [^\n]*\n((?:[^\n]*\n)*?)(?=^## |\z)/mu';
            if (preg_match($rx, $texto, $m) !== 1) {
                $problemas[] = "seção §{$letra} não encontrada (ou o recorte quebrou)";
                continue;
            }
            if (trim($m[1]) === '') {
                // ⚠️ Era exatamente este o sintoma do `.*` sob /s: corpo VAZIO, e toda asserção de
                // ausência passando por construção.
                $problemas[] = "o corpo da seção §{$letra} saiu VAZIO — o recorte está quebrado";
                continue;
            }
            if (preg_match('/\*\*COMO MEDIDA: `(SCRIPT|LEITURA)`\.\*\*/u', $m[1]) !== 1) {
                $problemas[] = "a seção §{$letra} não declara **COMO MEDIDA: `SCRIPT`** nem `LEITURA`";
            }
        }

        // (b) A §I existe.
        if (preg_match('/^## §I — [^\n]*\n((?:[^\n]*\n)*?)(?=^## |\z)/mu', $texto, $mi) !== 1) {
            $problemas[] = 'a §I das divergências não existe';

            return $problemas;
        }
        $corpoI = $mi[1];

        // (c) ⚠️ A §I NÃO pode estar vazia por construção: ou traz as classificações, ou traz a
        // linha explícita de "nenhuma divergência". §I vazia SEM essa linha é indistinguível de
        // "não re-mediu" — é a ISCA INVERTIDA escrita como regra.
        $usadas = [];
        if (preg_match_all('/^### §I\.\d+ — `([^`]+)`/mu', $corpoI, $mc) >= 1) {
            $usadas = $mc[1];
        }
        // ⚠️⚠️ O ESCAPE É ESTRUTURAL (um CABEÇALHO), e não uma frase — e isso foi medido doendo:
        // a primeira redação procurava a FRASE 'nenhuma divergência nesta re-medição' em qualquer
        // lugar do corpo, e a **própria prosa da §I que EXPLICA a regra cita a frase** — então o
        // escape ficava ligado para sempre e a isca invertida do item 2 reprovava. É exatamente a
        // regra 2 do learnings (*palavra que explica 'não uso isso' casa com o gate que conta
        // isso*), **dentro do meu próprio gate**. ➡️ A forma passou a ser um cabeçalho
        // `### §I.N — NENHUMA DIVERGÊNCIA NESTA RE-MEDIÇÃO`, que a prosa não consegue satisfazer
        // por acidente.
        $temEscape = (bool) preg_match('/^### §I\.\d+ — NENHUMA DIVERGÊNCIA NESTA RE-MEDIÇÃO/mu', $corpoI);
        if ($usadas === [] && ! $temEscape) {
            $problemas[] = 'a §I não traz classificação nenhuma E não traz a linha explícita '
                . '"nenhuma divergência nesta re-medição" — vazia por construção';
        }

        // (d) Toda classificação usada está na lista FECHADA.
        $fora = array_values(array_diff($usadas, self::CLASSIFICACOES));
        if ($fora !== []) {
            $problemas[] = 'classificação fora da lista fechada OMISSÃO/REFERÊNCIA/VALOR: ' . implode(', ', $fora);
        }

        // (e) Se há divergências, as três classificações têm de estar presentes ou mencionadas.
        // ⚠️ Lista CONSTANTE, não contagem.
        if ($usadas !== [] && ! $temEscape) {
            foreach (array_diff(self::CLASSIFICACOES, $usadas) as $a) {
                if (! str_contains($corpoI, $a)) {
                    $problemas[] = "a §I não usa nem menciona a classificação '{$a}' — ou ela não ocorreu, e isso tem de estar escrito";
                }
            }
        }

        // (f) ⚠️ Todo ponteiro `:N` do documento aponta para DENTRO do protótipo. Ponteiro fora do
        // arquivo é erro de REFERÊNCIA, que é como esta tabela mais erra.
        preg_match_all('/`:(\d+)`/u', $texto, $mp);
        $foraDoArquivo = array_values(array_unique(array_filter(
            array_map('intval', $mp[1] ?? []),
            fn (int $n) => $n < 1 || $n > self::LINHAS_DO_PROTOTIPO
        )));
        if ($foraDoArquivo !== []) {
            $problemas[] = 'ponteiros fora do protótipo (> ' . self::LINHAS_DO_PROTOTIPO . '): ' . implode(', ', $foraDoArquivo);
        }
        // ⚠️ E a leitura não pode ser cega: zero ponteiros significa regex quebrada, não documento limpo.
        if (count($mp[1] ?? []) < 50) {
            $problemas[] = 'o documento tem menos de 50 ponteiros `:N` — leitura suspeita de cega';
        }

        // (g) A PERGUNTA-188-A com a resposta, a razão e a derrota do protótipo registradas.
        foreach ([
            'PERGUNTA-188-A' => 'a PERGUNTA-188-A não é nomeada',
            'VERIFICAÇÃO AUTOMÁTICA · APOIO' => 'o literal decidido não está escrito',
            'TERCEIRA derrota' => 'a terceira derrota do protótipo não está registrada',
            'RESPOSTA DO USUÁRIO' => 'a §A não registra que a resposta é DO USUÁRIO',
        ] as $agulha => $erro) {
            if (! str_contains($texto, $agulha)) {
                $problemas[] = $erro;
            }
        }
        // E as DUAS derrotas anteriores, com ponteiro — para a terceira não ficar solta.
        // ⚠️ `:1054` (a função `wait`), e **não** `:1045`: a prosa herdada dizia `:1045`, que é
        // `pct(n)`. Medido por `grep -n 'function wait'` na re-medição do 188-01 e classificado
        // como REFERÊNCIA (R20) — foi ESTE gate que pegou, antes de a prosa errada ser propagada.
        foreach (['`:1054`', '`:1167`', '`:369`', '`:633`'] as $ponteiro) {
            if (! str_contains($texto, $ponteiro)) {
                $problemas[] = "a §A não cita {$ponteiro}, um dos quatro `arquivo:linha` das derrotas do protótipo";
            }
        }

        return $problemas;
    }
}
