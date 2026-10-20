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

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    // O INVENTÁRIO DO CONTRATO REAL DA 188
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Os treze itens do inventário, como lista **CONSTANTE**. ⚠️ Nunca contagem: com 13 itens
     * obrigatórios, perder um deixaria 12 e um piso `>= 10` passaria — é a família de fragilidade
     * com nove ocorrências nesta milestone, e *"piso NÃO prova PRESENÇA"*.
     */
    private const ITENS_DO_INVENTARIO = [
        'INV-01', 'INV-02', 'INV-03', 'INV-04', 'INV-05', 'INV-06', 'INV-07',
        'INV-08', 'INV-09', 'INV-10', 'INV-11', 'INV-12', 'INV-13',
    ];

    /** O tamanho medido do protótipo. Ponteiro nu `:N` acima disto é erro de REFERÊNCIA. */
    private const LINHAS_DO_PROTOTIPO = 1432;

    /**
     * ⚠️⚠️ **POR QUE ESTE MÉTODO EXISTE.** Os planos 189-02..189-07 foram escritos contra os
     * **planos** da 188, não contra o código. O `189-CONTRATO-188.md` é o único documento que diz
     * o que de fato EXISTE — e um inventário que *"confirmasse o plano"* em vez de **medir o
     * disco** seria pior que nenhum: faria cinco executores confiarem num nome errado.
     *
     * ⚠️ A pergunta do GATE-01, respondida: *o que precisaria estar errado para este gate falhar?*
     *   · faltar um dos treze itens (ou ele aparecer **duas** vezes, com valores diferentes);
     *   · o **INV-04** não trazer o veredicto `DENTRO`/`FORA` — ele é a armadilha do contexto de
     *     empilhamento, e *"não medi"* é indistinguível de *"está fora"* sem a palavra escrita;
     *   · um `arquivo:linha` apontar para **arquivo que não existe** (caminho fantasma: DEZ nesta
     *     milestone, três vindos de briefing) ou para **linha fora do arquivo** (erro de
     *     REFERÊNCIA, o modo como esta classe de documento mais erra);
     *   · um ponteiro **nu** `:N` passar das 1432 linhas do protótipo;
     *   · o inventário ser citado por NOME NU de arquivo, sem caminho — que é precisamente a
     *     ambiguidade de onde saíram os caminhos fantasma. ⚠️ **Isto foi MEDIDO nesta wave:** a
     *     primeira redação do documento citava 22 ponteiros por nome nu, e **este gate os acusou**.
     *
     * ⚠️⚠️ **ISCA NAS DUAS DIREÇÕES:** a mesma validação roda sobre o documento REAL (tem de
     * PASSAR) e sobre cópias ADULTERADAS **em memória** (têm de REPROVAR).
     */
    public function test_o_inventario_da_188_tem_os_treze_itens_e_aponta_para_o_disco_189_01(): void
    {
        $caminho = base_path(self::DIR_FASE . '/189-CONTRATO-188.md');
        $this->assertFileExists($caminho, 'o 189-CONTRATO-188.md não existe — os planos 02 a 07 leem dele');
        $texto = (string) file_get_contents($caminho);

        $this->assertGreaterThan(
            5000,
            strlen($texto),
            'o 189-CONTRATO-188.md é curto demais para 13 itens medidos com arquivo:linha e os achados'
        );

        // ── 1. O documento REAL tem de PASSAR ───────────────────────────────────────────────────
        $this->assertSame([], $this->problemasDoInventario($texto), 'o 189-CONTRATO-188.md real reprovou a própria validação');

        // ── 2. ⚠️ ISCA INVERTIDA: SEM o INV-04 tem de REPROVAR ──────────────────────────────────
        $semInv04 = (string) preg_replace('/^INV-04:/m', 'INV-04-APAGADO-PELA-ISCA:', $texto);
        $this->assertNotSame($texto, $semInv04, 'a isca do INV-04 não alterou nada — ela não exerce a validação');
        $this->assertNotSame(
            [],
            $this->problemasDoInventario($semInv04),
            'a validação ACEITOU um inventário SEM o INV-04 — o item da armadilha do empilhamento não é cobrado'
        );

        // ── 3. ⚠️ ISCA INVERTIDA: ponteiro FORA do arquivo tem de REPROVAR ──────────────────────
        $foraDoArquivo = $texto . "\n\nlinha inventada apontando para "
            . '`resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx:99999`' . " fora do arquivo\n";
        $this->assertNotSame(
            [],
            $this->problemasDoInventario($foraDoArquivo),
            'a validação ACEITOU um ponteiro para além do fim do arquivo — erro de REFERÊNCIA passaria'
        );

        // ── 4. ⚠️ ISCA INVERTIDA: arquivo FANTASMA tem de REPROVAR ──────────────────────────────
        $fantasma = $texto . "\n\ncaminho fantasma "
            . '`tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php:10`' . " (ele vive FORA de Aprovacao/)\n";
        $this->assertNotSame(
            [],
            $this->problemasDoInventario($fantasma),
            'a validação ACEITOU um caminho FANTASMA — são dez nesta milestone, três vindos de briefing'
        );

        // ── 5. ⚠️ ISCA INVERTIDA: veredicto do INV-04 apagado tem de REPROVAR ───────────────────
        $semVeredicto = (string) preg_replace('/^(INV-04:[^\n]*?)\bFORA\b/m', '$1(não medido)', $texto);
        $this->assertNotSame($texto, $semVeredicto, 'a isca do veredicto não alterou nada');
        $this->assertNotSame(
            [],
            $this->problemasDoInventario($semVeredicto),
            'a validação ACEITOU um INV-04 sem veredicto DENTRO/FORA'
        );

        // ── 6. ⚠️ ISCA NÃO-CEGA: a leitura de ponteiros tem de VER algo ──────────────────────────
        // Zero ponteiros significaria regex quebrada, e aí todos os itens de 3 a 5 passariam por
        // construção. "Documento limpo" e "sonda cega" são indistinguíveis sem esta asserção.
        $this->assertGreaterThan(
            30,
            preg_match_all(self::RX_PONTEIRO, $texto),
            'o inventário tem menos de 30 ponteiros `arquivo:linha` — leitura suspeita de CEGA'
        );
    }

    /** A forma de um ponteiro: caminho RELATIVO com barras + `:linha`, entre acentos graves. */
    private const RX_PONTEIRO = '/`([A-Za-z0-9_\-.\/]+\.(?:jsx|js|php|md|txt)):(\d+)`/';

    /**
     * A validação de FORMA do `189-CONTRATO-188.md`. Devolve a lista de problemas — **vazia**
     * quando está tudo certo. ⚠️ Devolve LISTA (não lança) para as cinco iscas exercê-la.
     * ⚠️ **UMA** asserção coletando infratores no chamador, nunca uma por item.
     *
     * @return list<string>
     */
    private function problemasDoInventario(string $texto): array
    {
        $problemas = [];

        // (a) Os treze itens, cada um EXATAMENTE uma vez, em início de linha.
        foreach (self::ITENS_DO_INVENTARIO as $item) {
            $quantas = preg_match_all('/^' . preg_quote($item, '/') . ':/m', $texto);
            if ($quantas === 0) {
                $problemas[] = "{$item} ausente do inventário";
            } elseif ($quantas > 1) {
                $problemas[] = "{$item} aparece {$quantas} vezes — duas medições do mesmo item divergem";
            }
        }

        // (b) ⚠️ O INV-04 traz o VEREDICTO escrito. É o item da armadilha do contexto de
        // empilhamento: sem a palavra, "não medi" passa por "está fora".
        if (preg_match('/^INV-04:[^\n]*\b(DENTRO|FORA)\b/m', $texto) !== 1) {
            $problemas[] = 'o INV-04 não traz o veredicto DENTRO ou FORA do `<aside>`';
        }

        // (c) ⚠️⚠️ Todo `arquivo:linha` aponta para arquivo que EXISTE, na linha que EXISTE.
        // Ponteiro fora do arquivo é REFERÊNCIA; arquivo inexistente é FANTASMA.
        preg_match_all(self::RX_PONTEIRO, $texto, $m, PREG_SET_ORDER);
        $jaVistos = [];
        foreach ($m as $achado) {
            // ⚠️ Flag DENTRO do laço.
            $chave = $achado[1] . ':' . $achado[2];
            if (isset($jaVistos[$chave])) {
                continue;
            }
            $jaVistos[$chave] = true;

            $abs = base_path($achado[1]);
            if (! file_exists($abs)) {
                $problemas[] = "{$chave} => ARQUIVO NÃO EXISTE (caminho fantasma)";
                continue;
            }
            $total = count(explode("\n", str_replace("\r", '', (string) file_get_contents($abs))));
            $linha = (int) $achado[2];
            if ($linha < 1 || $linha > $total) {
                $problemas[] = "{$chave} => fora do arquivo (ele tem {$total} linhas) — erro de REFERÊNCIA";
            }
        }

        // (d) ⚠️ Ponteiro NU `:N` (sem arquivo) é do PROTÓTIPO, e tem de cair dentro das 1432.
        preg_match_all('/(?<![\w\/.])`:(\d+)`/', $texto, $mp);
        $foraDoProto = array_values(array_unique(array_filter(
            array_map('intval', $mp[1] ?? []),
            fn (int $n) => $n < 1 || $n > self::LINHAS_DO_PROTOTIPO
        )));
        if ($foraDoProto !== []) {
            $problemas[] = 'ponteiro nu fora do protótipo (> ' . self::LINHAS_DO_PROTOTIPO . '): ' . implode(', ', $foraDoProto);
        }

        // (e) ⚠️ O inventário declara que é MEDIÇÃO, não confirmação — e declara o que NÃO prova.
        // Sem isso, o próximo executor lê "named export" como garantia de comportamento.
        foreach ([
            'por COMANDO' => 'o inventário não declara que foi medido POR COMANDO',
            'não prova comportamento de navegador' => 'o inventário não declara que não há navegador',
        ] as $agulha => $erro) {
            if (! str_contains($texto, $agulha)) {
                $problemas[] = $erro;
            }
        }

        return $problemas;
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    // A TABELA FID-01 DO LIGHTBOX, RE-MEDIDA
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    /** As seções da `189-FID01.md` que são TABELA DE MEDIÇÃO e têm de declarar como foram medidas. */
    private const SECOES_MEDIDAS = ['B', 'C', 'D', 'E', 'F'];

    /** A lista FECHADA de classificações de divergência. Fora dela, reprova. */
    private const CLASSIFICACOES = ['OMISSÃO', 'REFERÊNCIA', 'VALOR'];

    /**
     * ⚠️⚠️ **POR QUE ESTE MÉTODO EXISTE.** O `<verify>` da tarefa 3, na primeira redação do plano,
     * rodava só os gates de TOKEN — que não medem **nada** da tabela: a `189-FID01.md` podia ficar
     * **intocada** e o verify passava verde. É *"gate sobre zero casos"* com outra roupa, e é o
     * mesmo defeito que o 188-01 já tinha corrigido no seu próprio plano.
     *
     * ⚠️ A pergunta do GATE-01, respondida: *o que precisaria estar errado para este gate falhar?*
     *   · uma seção de medição **deixar de dizer** se foi medida por `SCRIPT` ou por `LEITURA` —
     *     *"tabela derivada de SCRIPT é confiável; tabela derivada de LEITURA não é"*;
     *   · a **§I** desaparecer, ou ficar **vazia por construção** (que é indistinguível de "não
     *     re-mediu" — e foi exatamente o estado em que o documento chegou a esta wave);
     *   · uma divergência ser classificada **fora da lista fechada** de três valores;
     *   · uma das três classificações **nem ocorrer nem ser mencionada** — "não houve OMISSÃO"
     *     tem de estar ESCRITO, porque silêncio aqui é indistinguível de "não procurei";
     *   · um ponteiro nu `:N` apontar para **fora do protótipo** (> 1432) — e isso **pegou de
     *     verdade nesta wave**: três citações do `ROADMAP.md` escritas com a notação do protótipo
     *     (`:4717`, `:5025`) e a isca `:9999`. ⚠️ A notação `:N` é **EXCLUSIVA do protótipo** nesta
     *     milestone; linha de outro arquivo vai como `L<N>`;
     *   · a §A perder a declaração de que a tabela foi medida **por SCRIPT**.
     *
     * ⚠️⚠️ **RECORTE DE SEÇÃO SEM O PONTO SOB `/s`.** O cabeçalho usa classe negada de newline
     * (`[^\n]*`) e o corpo é `(?:[^\n]*\n)*?` com lookahead — **nunca** `.*` sob `/s`, que **engole
     * o documento inteiro** e devolve corpo VAZIO, fazendo toda asserção de ausência passar por
     * construção. ⚠️ **E `\z`, nunca `$`:** sob `/m` o `$` casa **fim de linha** e o corpo sai
     * vazio — medido nesta wave, na versão JS do mesmo recorte, e a isca pegou.
     *
     * ⚠️⚠️ **A DEGRADAÇÃO NÃO É `markTestSkipped`, por uma razão MEDIDA:** no modo `php` o harness
     * exige a linha `Tests: N passed (A assertions)`; um teste **pulado** imprime `1 skipped` e o
     * gate **REPROVARIA** — o "skip" derrubaria o gate em vez de degradá-lo. ➡️ A degradação é
     * **razão NOMEADA registrada numa lista**. Nunca "conferido".
     */
    public function test_a_fid01_do_lightbox_declara_como_mediu_e_lista_as_divergencias_189_01(): void
    {
        $caminho = base_path(self::DIR_FASE . '/189-FID01.md');
        $this->assertFileExists($caminho, 'o 189-FID01.md não existe — é o contrato de UI dos 8 planos desta fase');
        $texto = (string) file_get_contents($caminho);

        // ── 1. O documento REAL tem de PASSAR ───────────────────────────────────────────────────
        $this->assertSame([], $this->problemasDaFid01($texto), 'o 189-FID01.md real reprovou a própria validação de forma');

        // ── 2. ⚠️ ISCA INVERTIDA: §I **vazia por construção** tem de REPROVAR ───────────────────
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
        // pode induzir. ⚠️ O escape nunca é exercido pelo documento de hoje, então é exercido AQUI.
        $soEscape = (string) preg_replace(
            '/^### §I\.\d+ — .*$/mu',
            '### §I.1 — NENHUMA DIVERGÊNCIA NESTA RE-MEDIÇÃO',
            $texto
        );
        $this->assertNotContains(
            'a §I não traz classificação nenhuma E não traz o cabeçalho explícito de "nenhuma divergência" — vazia por construção',
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
        // ⚠️ Esta isca não é hipotética: ela PEGOU três citações reais nesta wave.
        $this->assertNotSame(
            [],
            $this->problemasDaFid01($texto . "\n\nlinha inventada apontando para `:9999` fora do protótipo\n"),
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
                . '(pacote de handoff NÃO versionado, e a outra cópia — new_layout_publicador/ — NÃO '
                . 'tem este arquivo). A conferência das 1432 linhas fica PULADA; a forma do documento '
                . 'continua cobrada pelos itens 1 a 5.';
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
     * A validação de FORMA do `189-FID01.md`. Devolve a lista de problemas — **vazia** quando está
     * tudo certo. ⚠️ Devolve LISTA (não lança) para as cinco iscas invertidas poderem exercê-la.
     * ⚠️ **UMA** asserção coletando infratores no chamador, nunca uma por seção.
     *
     * @return list<string>
     */
    private function problemasDaFid01(string $texto): array
    {
        $problemas = [];

        // (a) Toda seção de medição declara SCRIPT ou LEITURA.
        foreach (self::SECOES_MEDIDAS as $letra) {
            $rx = '/^## §' . $letra . ' [^\n]*\n((?:[^\n]*\n)*?)(?=^## |\z)/mu';
            if (preg_match($rx, $texto, $m) !== 1) {
                $problemas[] = "seção §{$letra} não encontrada (ou o recorte quebrou)";
                continue;
            }
            if (trim($m[1]) === '') {
                // ⚠️ Era exatamente este o sintoma do `.*` sob /s (e do `$` sob /m): corpo VAZIO, e
                // toda asserção de ausência passando por construção.
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
        if (trim($corpoI) === '') {
            $problemas[] = 'o corpo da §I saiu VAZIO — o recorte está quebrado';

            return $problemas;
        }

        // (c) ⚠️ A §I NÃO pode estar vazia por construção: ou traz as classificações, ou traz o
        // CABEÇALHO explícito de "nenhuma divergência". §I vazia SEM ele é indistinguível de "não
        // re-mediu" — é a ISCA INVERTIDA escrita como regra.
        $usadas = [];
        if (preg_match_all('/^### §I\.\d+ — `([^`]+)`/mu', $corpoI, $mc) >= 1) {
            $usadas = $mc[1];
        }
        // ⚠️⚠️ O ESCAPE É ESTRUTURAL (um CABEÇALHO), e não uma frase — porque a própria prosa da §I
        // que EXPLICA a regra cita a frase, e aí o escape ficaria ligado para sempre (é a regra 2 do
        // learnings: *palavra que explica "não uso isso" casa com o gate que conta isso*).
        $temEscape = (bool) preg_match('/^### §I\.\d+ — NENHUMA DIVERGÊNCIA NESTA RE-MEDIÇÃO/mu', $corpoI);
        if ($usadas === [] && ! $temEscape) {
            $problemas[] = 'a §I não traz classificação nenhuma E não traz o cabeçalho explícito de '
                . '"nenhuma divergência" — vazia por construção';
        }

        // (d) Toda classificação usada está na lista FECHADA.
        $fora = array_values(array_diff($usadas, self::CLASSIFICACOES));
        if ($fora !== []) {
            $problemas[] = 'classificação fora da lista fechada OMISSÃO/REFERÊNCIA/VALOR: ' . implode(', ', $fora);
        }

        // (e) Se há divergências, as três classificações têm de estar presentes OU mencionadas.
        // ⚠️ "Não houve OMISSÃO" tem de estar ESCRITO: silêncio é indistinguível de "não procurei".
        if ($usadas !== [] && ! $temEscape) {
            foreach (array_diff(self::CLASSIFICACOES, $usadas) as $a) {
                if (! str_contains($corpoI, $a)) {
                    $problemas[] = "a §I não usa nem menciona a classificação '{$a}' — ou ela não ocorreu, e isso tem de estar escrito";
                }
            }
        }

        // (f) ⚠️ A §I declara QUANDO e COMO foi re-medida. "Re-medida" sem data e sem método é
        // afirmação, não medição.
        if (! preg_match('/RE-MEDIDA por SCRIPT em \d{2}\/\d{2}\/\d{4}/u', $corpoI)) {
            $problemas[] = 'a §I não declara "RE-MEDIDA por SCRIPT em <data>"';
        }

        // (g) ⚠️⚠️ Todo ponteiro NU `:N` aponta para DENTRO do protótipo. A notação `:N` é
        // EXCLUSIVA do protótipo nesta milestone; linha de outro arquivo vai como `L<N>`.
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

        // (h) A §A declara que a tabela foi medida por SCRIPT, e o sha256 do protótipo está escrito.
        foreach ([
            'por SCRIPT' => 'a §A não declara que a tabela foi medida por SCRIPT',
            self::SHA_PROTOTIPO => 'o sha256 do protótipo não está escrito no documento',
            '1432' => 'o número de linhas do protótipo não está escrito',
        ] as $agulha => $erro) {
            if (! str_contains($texto, (string) $agulha)) {
                $problemas[] = $erro;
            }
        }

        // (i) ⚠️ As decisões TRAVADAS do usuário continuam nomeadas: sem elas, uma fase futura
        // "conserta de volta" uma derrota do protótipo que foi DECISÃO.
        foreach ([
            'PERGUNTA-189-A' => 'a PERGUNTA-189-A não é nomeada',
            'VERIFICAÇÃO AUTOMÁTICA · APOIO' => 'o literal decidido na 189-A não está escrito',
            'D-189-3' => 'a decisão D-189-3 (o que NÃO é desenhado) não é nomeada',
            'Ampliar 2,5×' => 'o literal do liga/desliga do zoom (D-189-3a) não está escrito',
        ] as $agulha => $erro) {
            if (! str_contains($texto, $agulha)) {
                $problemas[] = $erro;
            }
        }

        return $problemas;
    }
}
