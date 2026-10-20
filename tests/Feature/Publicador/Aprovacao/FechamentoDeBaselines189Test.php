<?php

namespace Tests\Feature\Publicador\Aprovacao;

use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 189, plano 01 — OS GATES DA ABERTURA: o gate de ENTRADA, as baselines, o inventário do
 * contrato real da 188 e a tabela FID-01 re-medida.
 *
 * ⚠️⚠️ **POR QUE ESTE ARQUIVO EXISTE, e a resposta não é "porque o plano pediu".**
 *
 * O `<verify>` da tarefa 1 traz `verificar-testes.sh --autoteste`. A pergunta que desmascara todo
 * gate — *"o que precisaria estar errado no CÓDIGO para este gate falhar?"* — tem, para o
 * `--autoteste` sozinho, a resposta **"nada"**: para ele reprovar, o próprio harness teria de estar
 * quebrado, e um `189-BASELINES.md` **VAZIO, ou com números inventados, PASSAVA**. **"Nada" é
 * ACHADO**, não tranquilidade (learnings §4).
 *
 * ➡️ Estes métodos leem os DOCUMENTOS desta fase e cobram a **FORMA** deles.
 *
 * ═══ ⚠️ O QUE ESTE ARQUIVO **NÃO** FAZ, E A RAZÃO MEDIDA ═══════════════════════════════════════
 *
 * **1. NÃO confere o VALOR dos números de suíte.** Mesma disciplina do
 * `FechamentoDeBaselines188Test`: *"o fechamento é MEDIÇÃO DATADA, não gate perene"*. Um gate que
 * cravasse `JS: 5871` aqui seria **régua que sobe a cada wave** — a família de fragilidade com
 * NOVE ocorrências nesta milestone, e cada subida *treina o executor seguinte a ajustar réguas
 * quando elas incomodam*. ➡️ **Cobra-se que o número seja INTEIRO e esteja ESCRITO, não qual é.**
 *
 * **2. NÃO usa range de git.** A 187-11 mediu que range não passa nesta árvore: 111 commits, **43
 * da sessão paralela**, e o diff cru **acusava 12 dos 60 alvos vetados**. ⚠️ E **o AUTOR não
 * discrimina nada**: as três máquinas desta árvore commitam com o mesmo `user.name`.
 *
 * **3. NÃO lê o protótipo para CONFERIR a tabela.** `design_handoff_publicador/` **não é
 * versionado** (`??`) e não existe na máquina de outro dev. O que se cobra é o **sha256 ESCRITO no
 * documento**, verificável em qualquer máquina. ⚠️ A conferência que precisa do arquivo degrada
 * para **razão NOMEADA numa lista** — nunca para "conferido".
 *
 * **4. NÃO cria `REF_PHP_FALHAS`.** O harness declara, na própria fonte, que esse limiar não deve
 * passar a existir. O teto conferido aqui é sobre o **texto do documento**, não sobre a suíte, e
 * serve para pegar baseline que REGISTRA regressão.
 *
 * ⚠️ **Todo método leva `_189_01` no nome.** Conferido por script contra **todo** `test_*` de
 * `tests/`, nas DUAS direções (meu contém alheio / alheio contém o meu), **incluindo prefixo**:
 * `ARQUIVOS=1667 METODOS=7876 HOMONIMOS=0 PREFIXOS=0`, com a isca provando que a sonda VÊ (os
 * mesmos nomes sem o sufixo acusam **9** métodos reais das Fases 187/188). O `--filter` do PHPUnit
 * é regex NÃO ANCORADA e as Fases **186, 187 e 188** estão EM PRODUÇÃO — superstring de um método
 * delas faria o id daquela fase SOMAR.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class FechamentoDeBaselines189Test extends TestCase
{
    private const DIR_FASE = '.planning/phases/189-imagem-ampliada-o-lightbox-com-zoom-de-2-5-que-segue-o-curso';

    private const DIR_188 = '.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece';

    /** O sha256 curto do protótipo de autoridade. CONSTANTE: se ele mudar, a FID-01 mede outro documento. */
    private const SHA_PROTOTIPO = '68624883ce35547e';

    /** As 9 chaves de forma fixa do `189-BASELINES.md`. Lista CONSTANTE, nunca contagem. */
    private const CHAVES_DA_BASELINE = [
        'PARTIDA', 'COMMIT', 'BUILD', 'MANIFEST', 'JS', 'PHP', 'PROTOTIPO', 'ENTRADA-188', 'PERGUNTAS',
    ];

    /**
     * ⚠️⚠️ **O GATE DE ENTRADA — os 14 arquivos que provam a 188 FECHADA.**
     *
     * Lista **CONSTANTE**, nunca `glob` nem contagem: `glob` que devolvesse 13 por um arquivo
     * renomeado passaria por omissão, e contagem é a família de fragilidade com nove ocorrências
     * nesta milestone. ⚠️ **Os infratores são COLETADOS numa asserção ÚNICA** — uma
     * `assertFileExists` por arquivo faria a contagem do gate crescer junto com a lista, que é
     * exatamente a armadilha que a 186-09 deixou em produção e o 188-13 recusou replicar.
     */
    private const ENTRADA_DA_188 = [
        self::DIR_188 . '/188-01-SUMMARY.md',
        self::DIR_188 . '/188-02-SUMMARY.md',
        self::DIR_188 . '/188-03-SUMMARY.md',
        self::DIR_188 . '/188-04-SUMMARY.md',
        self::DIR_188 . '/188-05-SUMMARY.md',
        self::DIR_188 . '/188-06-SUMMARY.md',
        self::DIR_188 . '/188-07-SUMMARY.md',
        self::DIR_188 . '/188-08-SUMMARY.md',
        self::DIR_188 . '/188-09-SUMMARY.md',
        self::DIR_188 . '/188-10-SUMMARY.md',
        self::DIR_188 . '/188-11-SUMMARY.md',
        self::DIR_188 . '/188-12-SUMMARY.md',
        self::DIR_188 . '/188-13-SUMMARY.md',
        self::DIR_188 . '/188-FECHAMENTO.md',
    ];

    /**
     * ⚠️ A pergunta do GATE-01, respondida: *o que precisaria estar errado para este gate falhar?*
     *   · a 189 começar com a **188 ABERTA** — faltando qualquer um dos 13 SUMMARY ou o
     *     `188-FECHAMENTO.md` (a colisão que a nota de coordenação §1 MEDE: `ARQUIVOS_NOVOS` por
     *     igualdade, `npm run build` global, corrida de índice do git);
     *   · alguém **apagar** o `189-BASELINES.md`, ou deixá-lo como esqueleto;
     *   · alguém escrever uma das 9 linhas **vazia** ou com travessão em vez de número;
     *   · alguém pôr um sha de commit que não é sha, ou um número que não é inteiro;
     *   · alguém registrar **mais falhas** do que a régua herdada aceita, em vez de investigar;
     *   · alguém trocar o **sha256 do protótipo** (a `189-FID01.md` inteira passaria a medir outro
     *     documento, e NADA mais no repositório pegaria isso);
     *   · alguém afirmar um número **sem escrever o comando** que o produziu — que é a diferença
     *     entre baseline medida e baseline afirmada (T-189-02, Repudiation).
     *
     * ⚠️⚠️ **ISCA NAS DUAS DIREÇÕES, e ela é o que impede este gate de ser decorativo:** a mesma
     * validação roda sobre o documento REAL (tem de PASSAR) e sobre cópias ADULTERADAS **em
     * memória** (têm de REPROVAR). Sem a segunda metade eu não saberia se a validação mede algo —
     * *"asserção de ausência rodada com dado vazio é vazia por construção"* (learnings §4, regra 4).
     * ⚠️ A adulteração é **em memória**: nenhum arquivo do repositório é tocado.
     */
    public function test_a_entrada_e_as_baselines_estao_medidas_e_nao_afirmadas_189_01(): void
    {
        // ── 0. ⚠️⚠️ O GATE DE ENTRADA, ANTES de tudo: a 188 tem de estar FECHADA ────────────────
        $faltando = [];
        foreach (self::ENTRADA_DA_188 as $rel) {
            // ⚠️ Flag DENTRO do laço.
            if (! file_exists(base_path($rel))) {
                $faltando[] = $rel;
            }
        }
        $this->assertSame(
            [],
            $faltando,
            'a Fase 188 NÃO está fechada — a 189 edita PainelDeRevisao.jsx/ImagemGrandeDoPainel.jsx e '
                . 'inverte um gate do 188-08; executar em paralelo é a colisão medida na nota de coordenação §1'
        );
        // ⚠️ ISCA DO RECORTE: a lista não pode estar vazia nem ter encolhido. Um `glob` que
        // devolvesse 13 por renomeação passaria por omissão; a lista constante não.
        $this->assertCount(14, self::ENTRADA_DA_188, 'a lista de entrada da 188 mudou de tamanho — remeça');

        // ── 1. O documento REAL tem de PASSAR a validação ───────────────────────────────────────
        $caminho = base_path(self::DIR_FASE . '/189-BASELINES.md');
        $this->assertFileExists($caminho, 'o 189-BASELINES.md não existe — os outros 7 planos leem dele');
        $texto = (string) file_get_contents($caminho);

        $this->assertGreaterThan(
            3000,
            strlen($texto),
            'o 189-BASELINES.md é curto demais para conter as 9 linhas, a tabela mecânica e as falhas nomeadas'
        );

        $this->assertSame(
            [],
            $this->problemasDaBaseline($texto),
            'o 189-BASELINES.md real reprovou a própria validação de forma'
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

        // ── 4. ⚠️ ISCA INVERTIDA 3: apagar uma das 9 chaves tem de REPROVAR ─────────────────────
        // ⚠️ A chave escolhida é a `ENTRADA-188`, que é NOVA nesta fase (a 188 tinha 7 chaves):
        // se o validador só cobrasse as 7 herdadas, esta isca passaria e ninguém veria.
        $semEntrada = (string) preg_replace('/^ENTRADA-188:.*$/m', '', $texto);
        $this->assertNotSame($texto, $semEntrada, 'a isca da ENTRADA-188 não alterou nada');
        $this->assertNotSame(
            [],
            $this->problemasDaBaseline($semEntrada),
            'a validação ACEITOU uma baseline SEM a linha ENTRADA-188 — a chave nova desta fase não é cobrada'
        );
    }

    /**
     * A validação de FORMA do `189-BASELINES.md`. Devolve a lista de problemas — **vazia** quando
     * está tudo certo.
     *
     * ⚠️ Devolve LISTA, e não lança, exatamente para as iscas invertidas poderem exercê-la.
     * ⚠️ **UMA** asserção coletando infratores no chamador, nunca uma por chave: asserção por
     * chave faria a contagem do gate crescer junto com a lista (learnings §4b.2).
     *
     * @return list<string>
     */
    private function problemasDaBaseline(string $texto): array
    {
        $problemas = [];

        // (a) As 9 chaves presentes, cada uma com conteúdo NÃO-VAZIO depois dos dois-pontos.
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
            // (Fase 179, fronteira de dia). O teto existe para pegar baseline que REGISTRA
            // regressão, não para perdoar falha nova.
            $problemas[] = "'PHP:' registra {$php[3]} falhas — acima das 2 da oscilação herdada; é DA FASE";
        }

        // (e) O sha256 do protótipo, IGUAL ao constante. Igualdade de prefixo, não `includes` solto.
        if (preg_match('/^PROTOTIPO:.*\bsha256 ([0-9a-f]{16,64})\b/m', $texto, $sha) !== 1) {
            $problemas[] = "'PROTOTIPO:' não traz 'sha256 <hexas>'";
        } elseif (! str_starts_with($sha[1], self::SHA_PROTOTIPO)) {
            $problemas[] = "o sha256 do protótipo é '{$sha[1]}' e deveria começar por '" . self::SHA_PROTOTIPO . "'";
        }

        // (f) O número de linhas do protótipo — INTEIRO e escrito.
        if (preg_match('/^PROTOTIPO:.*\b(\d+) linhas\b/m', $texto) !== 1) {
            $problemas[] = "'PROTOTIPO:' não traz '<N> linhas'";
        }

        // (g) ⚠️ A `ENTRADA-188:` diz quantos arquivos foram conferidos, e o número é o 14 da
        // lista CONSTANTE — não "alguns".
        if (preg_match('/^ENTRADA-188:.*\b14\b/m', $texto) !== 1) {
            $problemas[] = "'ENTRADA-188:' não declara os 14 arquivos conferidos";
        }

        // (h) ⚠️ A `PERGUNTAS:` nomeia as quatro perguntas da fase, com o estado de cada uma.
        // Pergunta sem dono não é pergunta registrada — e os planos 05/06 PARAM sem A/B.
        foreach (['PERGUNTA-189-A', 'PERGUNTA-189-B', 'PERGUNTA-189-C', 'PERGUNTA-189-D'] as $pergunta) {
            if (! str_contains($texto, $pergunta)) {
                $problemas[] = "a {$pergunta} não está registrada na baseline";
            }
        }

        // (i) ⚠️ O COMANDO de cada chave escrito AO LADO dela. É o que separa baseline medida de
        // baseline afirmada (T-189-02). A forma é a linha de tabela `| `CHAVE` | … |`.
        foreach (self::CHAVES_DA_BASELINE as $chave) {
            if (preg_match('/^\|\s*`' . preg_quote($chave, '/') . '`\s*\|\s*(\S[^|]*)\|/m', $texto, $cmd) !== 1) {
                $problemas[] = "não há linha de tabela com o comando de '{$chave}'";
            } elseif (strlen(trim($cmd[1])) < 10) {
                $problemas[] = "o comando de '{$chave}' é curto demais para ser um comando";
            }
        }

        // (j) As falhas alheias NOMEADAS por semântica: o arquivo e os dois métodos do PHP.
        // ⚠️ Lista CONSTANTE. ⚠️ Identificadas por SEMÂNTICA, não por número de linha.
        foreach ([
            'tests/Feature/Publicador/CockpitDaContaTest.php',
            'estrutura-grade-glide.test.js',
            'Características secundárias nasce recolhido',
        ] as $nome) {
            if (! str_contains($texto, $nome)) {
                $problemas[] = "a falha alheia '{$nome}' não está nomeada no documento";
            }
        }

        // (k) ⚠️⚠️ O caminho FANTASMA não pode ser citado como se existisse. Ele é um dos dez
        // desta milestone, e o documento só pode mencioná-lo para dizer que NÃO existe.
        if (str_contains($texto, 'tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php')
            && ! preg_match('/Aprovacao\/CockpitDaContaTest\.php`?\*{0,2}[^\n]{0,40}(NÃO|NAO)\s*EXISTE/u', $texto)) {
            $problemas[] = 'o caminho fantasma de CockpitDaConta é citado sem dizer que NÃO EXISTE';
        }

        // (l) ⚠️ A régua "qualquer OUTRA falha é DA FASE" escrita, não presumida.
        if (! preg_match('/qualquer\s+(OUTRA|outra)[^\n]*(É|E|e)\s*DA\s*FASE/u', $texto)) {
            $problemas[] = 'a régua "qualquer outra falha é DA FASE" não está escrita';
        }

        return $problemas;
    }
}
