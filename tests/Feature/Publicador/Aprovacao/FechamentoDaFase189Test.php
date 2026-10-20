<?php

namespace Tests\Feature\Publicador\Aprovacao;

use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 189, plano 08 — **O GATE DO PRÓPRIO FECHAMENTO**: a forma fixa dos 8 veredictos, as linhas
 * de baseline, o anti-fantasma do território, os 8 manifestos e as três bijeções, e as perguntas
 * com dono.
 *
 * ⚠️⚠️ **POR QUE ESTE ARQUIVO EXISTE, e a resposta não é "porque o plano pediu".**
 *
 * O `<verify>` deste plano traz `--autoteste` e as duas suítes. A pergunta que desmascara todo
 * gate — *"o que precisaria estar errado no mundo para este gate falhar?"* — tem, para aqueles três
 * sozinhos, a resposta **"nada"**: um `189-FECHAMENTO.md` **vazio**, ou com os 8 critérios todos
 * escritos como `OK`, ou com uma ressalva **sem dono**, **PASSARIA**. ⚠️ **"Nada" é ACHADO**, não
 * tranquilidade (learnings §4).
 *
 * ➡️ Estes nove métodos leem o **documento** e cobram a **FORMA** dele. **A forma fixa é o que
 * impede o "ajuste" silencioso:** critério que não passa recebe **RESSALVA** ou **REPROVA** com a
 * razão e com o **dono** — e **nunca é reescrito para caber**.
 *
 * ═══ ⚠️ O QUE ESTE ARQUIVO **NÃO** FAZ, E A RAZÃO MEDIDA ═══════════════════════════════════════
 *
 * **1. NÃO confere o VALOR dos números de suíte.** Mesma disciplina do
 * `FechamentoDeBaselines189Test` (189-01) e do `FechamentoDaFase188Test` (188-13): *"o fechamento é
 * MEDIÇÃO DATADA, não gate perene"*. Um gate que cravasse `JS: 6012` aqui seria **régua que sobe a
 * cada wave** — a família de fragilidade com **QUINZE** consertos na RAIZ nesta milestone, e **zero**
 * por elevação de número. ➡️ **Cobra-se que o número esteja ESCRITO e tenha o comando ao lado, não
 * qual é.**
 *
 * **2. NÃO usa range de git, e NÃO confere o AUTOR.** O range nesta árvore traz trabalho de outras
 * sessões (a 187-11 mediu 111 commits, **43 alheios**, e o diff cru acusando **12 de 60** alvos
 * vetados). ⚠️ **E o autor quase não discrimina:** medido nesta wave, nos últimos 120 commits há
 * **dois** nomes (`MB.ECF-100376` ×118 e `ECF Dev` ×2), e **os 39 commits do escopo desta fase são
 * todos `MB.ECF-100376`** — o mesmo nome das três máquinas que compartilham esta árvore. ➡️ O que
 * discrimina é o **ESCOPO DO ASSUNTO**, e ele é medido no documento, não aqui.
 *
 * **3. NÃO lê o protótipo.** `design_handoff_publicador/` **não é versionado** (`??`) e não existe
 * na máquina de outro dev. O que se cobra é o **sha256 ESCRITO no documento**.
 *
 * **4. NÃO cria `REF_PHP_FALHAS`.** O harness declara, na própria fonte, que esse limiar não deve
 * passar a existir.
 *
 * ⚠️ **A disciplina de REGEX deste arquivo, e cada item é caso MEDIDO neste projeto:**
 *   · recorte de seção com **`[^\n]*` no cabeçalho e `[\s\S]*?` no corpo, NUNCA `.` com `/s`** — com
 *     `/s` o `$` do cabeçalho deixa de ser fim de LINHA e o recorte engole o documento inteiro;
 *   · **`\s+` entre as palavras** de toda frase procurada — o markdown é de 100 colunas e **a frase
 *     quebra no meio**; procurá-la com espaço literal foi o lado ESTREITO do mesmo erro, medido no
 *     fechamento da 187;
 *   · **asserção ÚNICA com os infratores COLETADOS** — uma asserção por item faria a contagem do
 *     gate crescer com a lista, que é a família consertada na raiz na 187-07;
 *   · **nenhum `return` antecipado** e **nenhuma chamada a git nas asserções**: a contagem deste
 *     gate é a MESMA com zero e com N commits no escopo.
 *
 * ⚠️ **Todo método leva `_189_08` no nome.** Conferido por script contra **todo** `test_*` de
 * `tests/`, nas DUAS direções (meu contém alheio / alheio contém o meu), **incluindo prefixo** e
 * **com EXPANSOR DE ALTERNÂNCIA** (`/test_[A-Za-z0-9_]+/` casa **ZERO** em `test_(a|b)`, furo que
 * reportou `SEM_ID=14` nesta milestone **acusando trabalho correto**): medido
 * `ARQUIVOS=1231 METODOS=7971 HOMONIMOS=0 PREFIXOS=0 SEM_ID=0`. O `--filter` do PHPUnit é regex
 * **NÃO ANCORADA** e as Fases **186, 187 e 188** estão EM PRODUÇÃO — superstring de um método delas
 * faria o id daquela fase SOMAR.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class FechamentoDaFase189Test extends TestCase
{
    private const DIR_FASE = '.planning/phases/189-imagem-ampliada-o-lightbox-com-zoom-de-2-5-que-segue-o-curso';

    /**
     * ⚠️ **CONTADOS pelo executor**, com o comando escrito no documento:
     * `awk '/^### Phase 189:/,/^### Phase 190:/' .planning/ROADMAP.md | grep -cE "^  [0-9]+\. "` → **8**.
     * ⚠️ `20` é a contagem da Fase **188**, `15` a da **187**, `17` a da **186** — as quatro entradas
     * são **vizinhas** no arquivo, e um `awk` com a fase errada traz a contagem errada **em silêncio**.
     */
    private const CRITERIOS = 8;

    /** As 8 waves desta fase (7 de entrega + o fechamento). */
    private const PLANOS = 8;

    /**
     * As linhas de forma fixa. ⚠️ `PARTIDA` e `COMMIT` são **duas** coisas: a primeira é referência
     * de LEITURA (a primeira ação da sessão), a segunda é o HEAD em que as medições rodaram.
     * Confundi-las é como se usa a PARTIDA como extremo de range, que é o que não serve.
     */
    private const LINHAS_DE_BASELINE = ['PARTIDA:', 'COMMIT:', 'BUILD:', 'MANIFEST:', 'JS:', 'PHP:', 'PROTOTIPO'];

    private const VEREDICTOS = ['OK', 'RESSALVA', 'REPROVA'];

    private const COMO_MEDIU = ['SCRIPT', 'LEITURA', 'SEM-PROTOTIPO'];

    /**
     * ⚠️ **LISTA CONSTANTE, nunca piso.** *"Piso `>= N` NÃO prova PRESENÇA"* — foi a sobrevivente M5
     * do fechamento da 187. ⚠️ **As conferências visuais das Fases 187 e 188 entram aqui de
     * propósito:** elas seguem **ABERTAS e SEPARADAS**, e presumi-las feitas é o mesmo erro com
     * outra roupa.
     */
    private const PERGUNTAS_OBRIGATORIAS = ['189-A', '189-B', '189-C', '189-D', 'VISUAL-189', 'VISUAL-188', 'VISUAL-187'];

    /**
     * Os arquivos de PRODUÇÃO que esta fase criou. Lista **CONSTANTE**, nunca `glob()`.
     *
     * ⚠️ **O invariante é *"nenhuma PEÇA nova sob `resources/` é `.js`"*** — e **não** *"nenhum
     * arquivo novo é `.js`"*, que a 187-11 mediu ser **literalmente FALSO** (os 6 `.test.js` desta
     * fase são `.js`, e o `content` do Tailwind não os varre nem precisa). Gate mais largo que o
     * invariante reprova trabalho certo — família com **CATORZE** ocorrências nesta milestone.
     */
    private const PRODUCAO_DA_FASE = [
        'resources/js/Components/Mlb/Publicador/aprovacao/LightboxDaImagem.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/LateralDoLightbox.jsx',
        'app/Services/Publicador/Aprovacao/Painel/LightboxDoPainelService.php',
    ];

    /** Os arquivos de TESTE que esta fase criou. Lista **CONSTANTE**. */
    private const TESTES_DA_FASE = [
        'tests/Feature/Publicador/Aprovacao/FechamentoDeBaselines189Test.php',
        'tests/Feature/Publicador/Aprovacao/LightboxDoPainelServiceTest.php',
        'tests/Feature/Publicador/Aprovacao/FechamentoDaFase189Test.php',
        'tests/js/publicador-lightbox-tokens-189.test.js',
        'tests/js/publicador-lightbox-casca-189.test.js',
        'tests/js/publicador-lightbox-camadas-189.test.js',
        'tests/js/publicador-lightbox-lateral-189.test.js',
        'tests/js/publicador-lightbox-painel-189.test.js',
        'tests/js/publicador-lightbox-acoes-189.test.js',
    ];

    /**
     * Os arquivos **ALHEIOS** (da Fase 188) que esta fase tocou, **com autorização da régua §8**.
     *
     * ⚠️ Eles **NÃO** são violação de território: entram na **§9 do fechamento**, declarados, com o
     * plano e a razão. O gate cobra que a §9 **nomeie cada um** — fechamento que esconde o que tocou
     * é pior que fechamento que não mediu.
     */
    private const ALHEIOS_TOCADOS = [
        'resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ImagemGrandeDoPainel.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ColunaDeFotos.jsx',
        'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx',
        'app/Http/Controllers/MlbPublicadorAprovacaoController.php',
        'tests/js/publicador-painel-fotos-188.test.js',
        'tests/js/publicador-painel-casca-188.test.js',
        'tests/js/publicador-fila-tokens-187.test.js',
        'tests/js/publicador-fila-filtros-187.test.js',
        'tests/js/publicador-fila-fechamento-187.test.js',
        'tests/js/publicador-vocabulario-novo.test.js',
    ];

    // ───────────────────────────────────────────────────────────────────────────────────────────
    // Leitura e recorte
    // ───────────────────────────────────────────────────────────────────────────────────────────

    private function fechamento(): string
    {
        $caminho = base_path(self::DIR_FASE.'/189-FECHAMENTO.md');
        $this->assertFileExists($caminho, 'o 189-FECHAMENTO.md não existe — é o fechamento medido da fase');

        return (string) file_get_contents($caminho);
    }

    /**
     * Recorta a seção cujo cabeçalho de nível 2 contém `$marca`.
     *
     * ⚠️⚠️ **`[^\n]*` no cabeçalho e `[\s\S]*?` no corpo — NUNCA `.` com `/s`.** Com `/s` o `.` casa
     * `\n` e o `$` do cabeçalho deixa de ser fim de LINHA: o `.*$` engoliria o documento inteiro e o
     * corpo sairia **vazio**. Medido no fechamento da 187.
     */
    private function secao(string $texto, string $marca): string
    {
        $padrao = '/^##[^\n]*'.preg_quote($marca, '/').'[^\n]*$([\s\S]*?)(?=^## |\z)/m';

        return preg_match($padrao, $texto, $m) === 1 ? $m[1] : '';
    }

    /** Entradas `[id, tipo, filtro, tests]` do manifesto da wave `$n`, lidas CRUAS. */
    private function entradasDoManifesto(int $n): array
    {
        $caminho = base_path('tests/gates/contagens-189-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT).'.txt');
        if (! file_exists($caminho)) {
            return [];
        }
        $saida = [];
        foreach (file($caminho, FILE_IGNORE_NEW_LINES) as $linha) {
            if (trim($linha) === '' || str_starts_with(ltrim($linha), '#')) {
                continue;
            }
            $partes = preg_split('/\s+/', trim($linha));
            if (count($partes) < 3) {
                continue;
            }
            preg_match('/tests=([^\s]+)/', $linha, $t);
            $saida[] = [
                'id' => $partes[0],
                'tipo' => $partes[1],
                'filtro' => $partes[2],
                'tests' => $t[1] ?? '',
            ];
        }

        return $saida;
    }

    /**
     * Os ids rodados por algum `<verify>` do plano `189-$nn`, com o **NN ANCORADO**.
     *
     * ⚠️ O número **nu** (`verificar-testes.sh 08 <id>`) resolve `contagens-186-08.txt` — **a fase
     * ERRADA, em silêncio**. A compatibilidade é DE PROPÓSITO (a Fase 186 está em produção e seus
     * 163 ids são chamados pelo número nu) e os casos **15 e 16** do `--autoteste` a guardam.
     */
    private function idsDoVerify(string $plano, string $nn): array
    {
        $padrao = '/verificar-testes\.sh\s+189-'.preg_quote($nn, '/').'\s+([a-z0-9][a-z0-9-]*)/';
        preg_match_all($padrao, $plano, $m);

        return array_values(array_unique($m[1]));
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────
    // id de gate `fechamento-territorio`
    // ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * O documento existe, **não é esqueleto**, e traz as linhas de forma fixa **com o comando ao
     * lado de cada uma**.
     *
     * ⚠️ `file_exists` sozinho seria satisfeito por **arquivo vazio** — e esqueleto vazio é
     * exatamente o modo de falhar deste plano: ⚠️ **três agentes caíram na Fase 187**, e um deixou
     * **2/3 de um plano sem commit**.
     *
     * ⚠️⚠️ **E "a linha existe" não basta: `baseline AFIRMADA` e `baseline MEDIDA` têm a mesma
     * forma.** O que as separa é o **comando** ao lado — por isso a tabela de procedência é cobrada
     * como presença das sete chaves dentro da sua própria seção.
     */
    public function test_o_fechamento_189_existe_e_tem_as_linhas_de_forma_fixa_189_08(): void
    {
        $texto = $this->fechamento();

        $this->assertGreaterThan(
            250,
            substr_count($texto, "\n"),
            'o 189-FECHAMENTO.md está curto demais para ser o fechamento medido de 8 critérios — esqueleto passa calado'
        );

        $ausentes = array_values(array_filter(
            self::LINHAS_DE_BASELINE,
            fn (string $linha) => preg_match('/^'.preg_quote($linha, '/').'/m', $texto) !== 1
        ));
        $this->assertSame([], $ausentes, 'faltam linhas de baseline em forma fixa (ancoradas em início de linha)');

        // ⚠️ ISCA do ancoramento: uma linha de baseline INVENTADA não pode ser encontrada — senão a
        // sonda está casando o meio de uma frase em vez do começo da linha.
        $this->assertSame(
            0,
            preg_match('/^NAOEXISTE9Z7:/m', $texto),
            'a sonda das baselines aceitou uma linha inventada: ela não está ancorada'
        );

        // ⚠️ ISCA invertida (controle positivo): a palavra `PROTOTIPO` aparece no meio de frases; a
        // sonda ancorada tem de distinguir, e a linha tem de trazer o `sha256` — sem ele ela não
        // prova que o documento medido não se moveu.
        $this->assertGreaterThanOrEqual(
            1,
            preg_match_all('/^PROTOTIPO[^\n]*sha256/m', $texto),
            'a linha PROTOTIPO existe, mas sem o sha256 ao lado ela não prova que o protótipo não se moveu'
        );

        // ⚠️ A PROCEDÊNCIA: o comando de cada linha, dentro da seção que a declara. Recorte à
        // SEÇÃO, nunca ao documento (contar no documento inteiro mediu 23 em vez de 8 na 187 e
        // REPROVOU TRABALHO CERTO).
        $procedencia = $this->secao($texto, 'PROCED');
        $this->assertNotSame('', $procedencia, 'o recorte da seção de procedência saiu VAZIO — recorte que não recorta mede NADA');
        $this->assertLessThan(
            strlen($texto),
            strlen($procedencia),
            'o recorte engoliu o documento inteiro — é o sintoma do `.` sob a flag /s'
        );
        $semComando = array_values(array_filter(
            self::LINHAS_DE_BASELINE,
            fn (string $linha) => ! str_contains($procedencia, rtrim($linha, ':'))
        ));
        $this->assertSame([], $semComando, 'linha de baseline sem o COMANDO declarado: baseline AFIRMADA tem a mesma forma da MEDIDA');
    }

    /**
     * O documento **declara** que ninguém viu tela renderizada, **não afirma deploy que não houve**,
     * e **não presume** as conferências visuais das fases anteriores.
     *
     * ⚠️⚠️ *"A página renderiza"* significa, nesta fase, *"o render de SERVIDOR executa o corpo e
     * devolve HTML sem lançar"* — e **nem isso** para o painel: por causa do portal, o HTML do painel
     * **não sai** no render de servidor (medido na quick `261020-tdz`: `len=28119` **igual** com
     * `painel: null` e com payload cheio).
     *
     * ⚠️ As frases são procuradas com `\s+` entre as palavras, porque o markdown é de 100 colunas e
     * **a frase quebra no meio**.
     */
    public function test_o_fechamento_189_declara_que_ninguem_viu_tela_e_nao_afirma_deploy_189_08(): void
    {
        $texto = $this->fechamento();

        $this->assertMatchesRegularExpression(
            '/nenhum\s+executor/iu',
            $texto,
            'o documento não declara que NENHUM EXECUTOR desta fase viu tela renderizada'
        );
        $this->assertMatchesRegularExpression(
            '/viu\s+tela\s+renderizada/iu',
            $texto,
            'falta a frase "viu tela renderizada" — ela é o que impede ler as 7 SUMMARYs como relato de tela'
        );
        $this->assertMatchesRegularExpression(
            '/react-dom\/server/',
            $texto,
            'o documento não diz COMO o render foi feito (esbuild + react-dom/server), e sem isso a declaração é slogan'
        );
        $this->assertMatchesRegularExpression(
            '/N[ÃA]O\s+DEPLOYAD[OA]/iu',
            $texto,
            'falta o "NÃO DEPLOYADO" — a forma "em produção" das Fases 186/187 afirmaria deploy inexistente'
        );

        // ⚠️ ISCA do quase-igual, na direção que importa: o documento NÃO pode dizer que a Fase 189
        // está em produção. A busca é pela afirmação sobre ESTA fase, não pela palavra "produção"
        // (que aparece legitimamente ao falar das Fases 186 e 187).
        $this->assertSame(
            0,
            preg_match('/Fase\s+189\s+est[áa]\s+em\s+produ/iu', $texto),
            'o documento afirma que a Fase 189 está em produção — ela NÃO foi deployada'
        );

        // ⚠️ A fila nasce VAZIA, e isso muda o que o roteiro pode pedir.
        $this->assertMatchesRegularExpression(
            '/fila\s+(est[áa]|nasce|segue)\s+vazia|fila\s+vazia/iu',
            $texto,
            'o documento não declara que a fila de produção está VAZIA — sem isso o roteiro promete tela que não há'
        );
    }

    /**
     * ⚠️⚠️ **O ANTI-FANTASMA — e é o gate PERENE deste arquivo.**
     *
     * `git diff -- <caminho que não existe>` sai **VAZIO**, e vazio é **indistinguível de
     * "intocado"**. São **DOZE** caminhos fantasmas medidos nesta milestone, **TRÊS** vindos de
     * briefing do orquestrador, e **DOIS deles achados por ESTE plano**
     * (`app/Support/Publicador/ArquivosDeImagem.php` e `.../ResolvedorGruposImagem.php`, que vivem
     * sob `Imagem/`). ➡️ **Cada linha `ALVO-INTOCADO:` do documento é conferida no disco.**
     *
     * ⚠️ **A contagem deste método NÃO depende de quantos commits existem nem de quantos alvos a
     * lista tem:** os fantasmas são **coletados numa asserção única**, e **git não é chamado aqui**.
     * Medido: a contagem é a mesma com zero e com N commits no escopo, porque nada neste método lê
     * o histórico.
     */
    public function test_o_territorio_189_os_alvos_existem_e_os_toques_alheios_estao_declarados_189_08(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^ALVO-INTOCADO: (.+)$/m', $texto, $m);
        $alvos = array_map('trim', $m[1]);

        $this->assertGreaterThanOrEqual(
            20,
            count($alvos),
            'a lista de alvos vetados encolheu — tirar alvo da lista é o conserto barato que o learnings manda evitar'
        );

        // ⚠️ UMA asserção, com os fantasmas COLETADOS.
        $fantasmas = array_values(array_filter($alvos, fn (string $a) => ! file_exists(base_path($a))));
        $this->assertSame([], $fantasmas, 'alvos citados que NÃO existem no disco — "diff vazio" neles não prova nada');

        // ⚠️ ISCA do anti-fantasma: um caminho inventado TEM de ser visto como ausente, senão
        // `FANTASMAS=0` é indistinguível de sonda morta.
        $this->assertFalse(
            file_exists(base_path('app/Mcp/NaoExiste9z7/')),
            'a isca do anti-fantasma existe no disco: escolha outra'
        );

        // Os arquivos DESTA fase, por lista CONSTANTE, conferidos no disco.
        $semDisco = array_values(array_filter(
            array_merge(self::PRODUCAO_DA_FASE, self::TESTES_DA_FASE),
            fn (string $a) => ! file_exists(base_path($a))
        ));
        $this->assertSame([], $semDisco, 'arquivo declarado como da Fase 189 que não está no disco');

        // ⚠️ O invariante dos `.js`: nenhuma PEÇA de PRODUÇÃO sob `resources/` é `.js`.
        $pecasErradas = array_values(array_filter(
            self::PRODUCAO_DA_FASE,
            fn (string $a) => str_starts_with($a, 'resources/') && ! str_ends_with($a, '.jsx')
        ));
        $this->assertSame([], $pecasErradas, 'peça de produção sob resources/ que não é .jsx — o content do Tailwind varre *.jsx e NÃO *.js');

        // ─── §9: os arquivos ALHEIOS tocados, DECLARADOS ────────────────────────────────────
        $declarados = $this->secao($texto, 'ALHEIOS');
        $this->assertNotSame('', $declarados, 'a §9 (arquivos e gates alheios tocados) não existe — fechamento que esconde o que tocou é pior que o que não mediu');

        $naoDeclarados = array_values(array_filter(
            self::ALHEIOS_TOCADOS,
            fn (string $a) => ! str_contains($declarados, basename($a))
        ));
        $this->assertSame([], $naoDeclarados, 'arquivo alheio tocado pela fase e NÃO declarado na §9');

        // ⚠️ ISCA do recorte da §9: um nome de arquivo que a fase NÃO tocou não pode aparecer como
        // declarado — senão a sonda está casando o documento inteiro em vez da seção.
        $this->assertFalse(
            str_contains($declarados, 'PublicadorHeader.jsx'),
            'a §9 declara como "tocado" o PublicadorHeader.jsx, que é INTOCÁVEL — ou o recorte vazou'
        );

        // ⚠️ O ESCOPO DO ASSUNTO tem de estar declarado como método, com as TRÊS faces — o range
        // cru e o autor NÃO servem, e dizer isso por escrito é o que impede o próximo de usá-los.
        $territorio = $this->secao($texto, 'TERRIT');
        $this->assertNotSame('', $territorio, 'a seção do território não existe');
        $this->assertMatchesRegularExpression(
            '/escopo\s+do\s+assunto/iu',
            $territorio,
            'o território não declara que foi provado por ESCOPO DO ASSUNTO'
        );
        $this->assertMatchesRegularExpression(
            '/duas\s+passadas/iu',
            $territorio,
            'o território não declara as DUAS passadas independentes'
        );

        // ─── A FID-01 consolidada DECLARA COMO MEDIU cada plano ─────────────────────────────
        // ⚠️ *"Tabela derivada de SCRIPT é confiável; tabela derivada de LEITURA não é"* — e um
        // fechamento que não diz qual das duas usou **não permite julgar nada**.
        preg_match_all('/^FID-01-PLANO-(\d{2}): ([A-Z-]+)/m', $texto, $f);

        $this->assertSame(
            range(1, self::PLANOS),
            array_map('intval', $f[1]),
            'as linhas FID-01-PLANO-NN não cobrem 01..08 em ordem, sem buraco e sem repetição'
        );
        $forade = array_values(array_diff(array_unique($f[2]), self::COMO_MEDIU));
        $this->assertSame([], $forade, 'declaração de "como mediu" fora da lista fechada SCRIPT/LEITURA/SEM-PROTOTIPO');

        // ⚠️ ISCA do recorte: um veredicto INVENTADO não pode passar — foi a mutação M4 do
        // fechamento da 187.
        $this->assertSame(
            0,
            preg_match('/^FID-01-PLANO-\d{2}: LEITURA-RAPIDA/m', $texto),
            'a sonda do "como mediu" aceitou um veredicto inventado'
        );
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────
    // id de gate `fechamento-criterios`
    // ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️⚠️ **A FORMA FIXA É O QUE IMPEDE O "AJUSTE" SILENCIOSO.** São **8** critérios, numerados
     * `01`..`08` **sem buraco e sem repetição** — e `20` é a contagem da Fase **188**, `15` a da
     * **187**, `17` a da **186**. As entradas são **vizinhas** no `ROADMAP.md`, e um `awk` com a
     * fase errada traz a contagem errada **em silêncio**.
     */
    public function test_os_oito_criterios_189_estao_em_forma_fixa_sem_buraco_189_08(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^CRIT-(\d{2}): ([A-Z]+) — /m', $texto, $m);
        $numeros = array_map('intval', $m[1]);

        $this->assertSame(
            range(1, self::CRITERIOS),
            $numeros,
            'os veredictos não formam 01..08 em ordem, sem buraco e sem repetição (20 é a Fase 188, 15 a 187, 17 a 186)'
        );

        $forade = array_values(array_diff(array_unique($m[2]), self::VEREDICTOS));
        $this->assertSame([], $forade, 'veredicto fora da lista fechada OK/RESSALVA/REPROVA');

        // ⚠️ Cada linha tem de nomear o GATE que a prova. **Veredicto sem gate é opinião.**
        preg_match_all('/^CRIT-\d{2}: [^\n]*$/m', $texto, $linhas);
        $semGate = array_values(array_filter(
            $linhas[0],
            fn (string $l) => preg_match('/ gate /', $l) !== 1
        ));
        $this->assertSame([], $semGate, 'critérios cujo veredicto não nomeia o gate que o prova');

        // ⚠️ ISCA do ancoramento: um `CRIT-` ancorado fora de 01..08 tem de ser visto (a primeira
        // metade da isca é a igualdade acima; esta é a segunda).
        $this->assertSame(
            0,
            preg_match('/^CRIT-99: /m', $texto),
            'a sonda dos critérios aceitou um número fora de 01..08'
        );
    }

    /**
     * Nenhum `REPROVA`, **cada RESSALVA com DONO**, e as **duas ressalvas que a decisão do usuário
     * já fixou** escritas como ressalva.
     *
     * ⚠️⚠️ **E o critério NUNCA é reescrito para caber.** Na Fase 186 o critério 7 **foi** reescrito,
     * com razão declarada (ele **contradizia o 6** e era **aritmeticamente impossível**). Se algo
     * dessa natureza aparecer, o fechamento **escreve e devolve ao usuário** — não conserta por conta
     * própria.
     *
     * ⚠️⚠️ **O CRIT-04 e o dono CANCELADO.** O plano desta wave (escrito em 19/10) manda
     * `dono: Fase 190`. Em **20/10** o usuário **cancelou as Fases 190 e 191** (decisão 20,
     * `0607f5a9`). ➡️ A linha do CRIT-04 carrega **as duas coisas**: a atribuição original **e** o
     * cancelamento, porque *"a medição ganha do briefing"* e esconder o cancelamento faria o leitor
     * **esperar entrega de uma fase que não existe**. ⚠️ **O que NÃO é aceito é o CRIT-04 sair `OK`.**
     */
    public function test_nenhum_criterio_189_reprovado_e_cada_ressalva_tem_dono_189_08(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^CRIT-(\d{2}): ([A-Z]+) — ([^\n]*)$/m', $texto, $m);

        $reprovados = [];
        $ressalvaSemDono = [];
        foreach ($m[1] as $i => $numero) {
            if ($m[2][$i] === 'REPROVA') {
                $reprovados[] = $numero;
            }
            if ($m[2][$i] === 'RESSALVA' && preg_match('/dono:\s*\S/iu', $m[3][$i]) !== 1) {
                $ressalvaSemDono[] = $numero;
            }
            if ($m[2][$i] === 'RESSALVA' && preg_match('/dono:\s*a\s+definir/iu', $m[3][$i]) === 1) {
                $ressalvaSemDono[] = $numero.' (a definir NÃO é dono)';
            }
        }

        $this->assertSame([], $reprovados, 'há critério REPROVADO — o fechamento PARA com REPROVA, não o registra e segue');
        $this->assertSame([], $ressalvaSemDono, 'RESSALVA sem dono nomeado na própria linha');

        // ⚠️ O CRIT-04 NÃO pode sair OK: "Comparar lado a lado na revisão detalhada" NÃO é desenhado
        // (D-189-3, decisão do usuário de 19/10; "desenhar desabilitado" foi RECUSADO na 188).
        $this->assertSame(
            0,
            preg_match('/^CRIT-04: OK/m', $texto),
            'o CRIT-04 saiu OK: o botão "Comparar lado a lado na revisão detalhada" NÃO é desenhado nesta fase'
        );
        $this->assertMatchesRegularExpression(
            '/^CRIT-04: RESSALVA — /m',
            $texto,
            'o CRIT-04 tem de sair como RESSALVA (D-189-3)'
        );

        // ⚠️ E ela carrega a atribuição ORIGINAL do plano **e** o cancelamento medido.
        $this->assertMatchesRegularExpression(
            '/^CRIT-04: RESSALVA — [^\n]*dono: Fase 190/m',
            $texto,
            'a linha do CRIT-04 não registra a atribuição original (dono: Fase 190) que o plano fixou'
        );
        $this->assertMatchesRegularExpression(
            '/^CRIT-04: RESSALVA — [^\n]*(CANCELAD|fora\s+de\s+escopo|SEM\s+DONO|sem\s+dono)/imu',
            $texto,
            'a linha do CRIT-04 não registra que a Fase 190 foi CANCELADA — o leitor esperaria entrega de fase inexistente'
        );

        // ⚠️ A ressalva de "Gerar novamente com IA" (AMPL-07) continua com dono VIVO: a Fase 192.
        $this->assertMatchesRegularExpression(
            '/Gerar\s+novamente[^\n]{0,200}dono:\s*Fase\s*192|dono:\s*Fase\s*192[^\n]{0,200}Gerar\s+novamente/iu',
            $texto,
            'falta a ressalva de "Gerar novamente com IA" com dono: Fase 192 (D-189-3)'
        );

        // A regra escrita, com `\s+` porque a frase QUEBRA no markdown de 100 colunas.
        $this->assertMatchesRegularExpression(
            '/nunca\s+(é|e)\s+reescrito/iu',
            $texto,
            'falta a regra escrita de que critério que não passa recebe RESSALVA/REPROVA e NUNCA é reescrito para caber'
        );

        // O placar, por forma fixa — e ele tem de FECHAR em 8.
        $this->assertMatchesRegularExpression(
            '/PLACAR-189:\s*OK=(\d+)\s*RESSALVA=(\d+)\s*REPROVA=(\d+)/u',
            $texto,
            'falta a linha de forma fixa PLACAR-189: OK=N RESSALVA=N REPROVA=N'
        );
        preg_match('/PLACAR-189:\s*OK=(\d+)\s*RESSALVA=(\d+)\s*REPROVA=(\d+)/u', $texto, $p);
        $this->assertSame(
            self::CRITERIOS,
            (int) $p[1] + (int) $p[2] + (int) $p[3],
            'o placar não fecha em 8 — placar que não soma é placar que não foi contado'
        );
        $this->assertSame(
            count(array_filter($m[2], fn (string $v) => $v === 'OK')),
            (int) $p[1],
            'o OK= do placar não bate com os veredictos OK em forma fixa'
        );
    }

    /**
     * As perguntas, **todas com DONO**; e o **roteiro da conta 459** como roteiro, não como menção.
     *
     * ⚠️⚠️ **`a definir` NÃO é dono.** E a sobrevivente **M6** do fechamento da 187 provou que provar
     * uma **MENÇÃO** a "conta 459" não prova o **ROTEIRO**: menção é *"lista de nomes"*, roteiro é
     * **comportamento**. ➡️ O roteiro é recortado à **sua seção** (nunca ao documento inteiro —
     * contar itens numerados no documento todo mediu **23** em vez de 8 na 187 e **reprovou trabalho
     * certo**).
     *
     * ⚠️ **A PERGUNTA-189-D tem de aparecer ABERTA.** Os defaults D1/D2 foram **aplicados** pelas
     * waves 06 e 07 — e apresentar pergunta aberta como decidida é a ameaça **T-189-65**.
     */
    public function test_as_perguntas_189_tem_dono_e_o_roteiro_da_conta_459_e_roteiro_189_08(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^PERGUNTA-([A-Z0-9-]+): ([A-Z]+)([^\n]*)$/m', $texto, $m);

        $semDono = [];
        foreach ($m[1] as $i => $id) {
            if (preg_match('/dono:\s*\S/u', $m[3][$i]) !== 1) {
                $semDono[] = $id;
            } elseif (preg_match('/dono:\s*a\s+definir/iu', $m[3][$i]) === 1) {
                $semDono[] = $id.' (a definir NÃO é dono)';
            }
        }
        $this->assertSame([], $semDono, 'perguntas sem dono — ou com "a definir", que não é dono');

        // ⚠️ LISTA CONSTANTE, nunca piso: piso NÃO prova PRESENÇA (sobrevivente M5 da 187).
        $faltando = array_values(array_diff(self::PERGUNTAS_OBRIGATORIAS, $m[1]));
        $this->assertSame([], $faltando, 'perguntas obrigatórias ausentes do fechamento');

        // ⚠️ ISCA de id inventado.
        $this->assertNotContains('NAOEXISTE-9Z7', $m[1], 'a sonda das perguntas aceitou um id inventado');

        // ⚠️ A 189-D ABERTA, e a VISUAL-189 ABERTA com dono usuário.
        $this->assertMatchesRegularExpression(
            '/^PERGUNTA-189-D: ABERTA/m',
            $texto,
            'a PERGUNTA-189-D não está registrada como ABERTA — apresentá-la decidida é a ameaça T-189-65'
        );
        $this->assertMatchesRegularExpression(
            '/^PERGUNTA-VISUAL-189: ABERTA — dono: usuário/mu',
            $texto,
            'falta a PERGUNTA-VISUAL-189 em forma fixa, ABERTA, com dono usuário'
        );

        // ─── O roteiro, recortado à SUA seção ───────────────────────────────────────────────
        $roteiro = $this->secao($texto, 'ROTEIRO DA CONFER');

        $this->assertNotSame('', $roteiro, 'o recorte da seção do roteiro saiu VAZIO — recorte que não recorta mede NADA');
        $this->assertLessThan(
            strlen($texto),
            strlen($roteiro),
            'o recorte engoliu o documento inteiro — é o sintoma do `.` sob a flag /s'
        );

        $this->assertMatchesRegularExpression('/conta\s+459/iu', $roteiro, 'o roteiro não nomeia a conta 459');

        // ⚠️ O caminho alternativo para a fila VAZIA tem de vir ANTES dos itens com oferta — é o caso
        // provável, e um roteiro que o deixe no fim manda o usuário procurar tela que não há.
        $posVazia = 0;
        if (preg_match('/[Ss]em\s+oferta\s+na\s+fila/u', $roteiro, $v, PREG_OFFSET_CAPTURE) === 1) {
            $posVazia = $v[0][1];
        }
        $posComOferta = 0;
        if (preg_match('/[Cc]om\s+oferta\s+na\s+fila/u', $roteiro, $c, PREG_OFFSET_CAPTURE) === 1) {
            $posComOferta = $c[0][1];
        }
        $this->assertGreaterThan(0, $posVazia, 'o roteiro não traz o caminho alternativo "Sem oferta na fila"');
        $this->assertGreaterThan(0, $posComOferta, 'o roteiro não traz o caminho "Com oferta na fila"');
        $this->assertLessThan($posComOferta, $posVazia, 'o caminho da fila VAZIA tem de vir ANTES dos itens com oferta');

        // ⚠️ `\s+` entre as palavras: a frase QUEBRA no markdown de 100 colunas.
        $this->assertMatchesRegularExpression(
            '/n[ãa]o\s+h[áa]\s+desfazer/iu',
            $roteiro,
            'o roteiro não avisa que aprovar NÃO TEM DESFAZER — é o aviso que o usuário precisa ANTES de clicar'
        );

        // ⚠️ E ele NÃO pode mandar procurar o que esta fase não desenhou.
        $this->assertMatchesRegularExpression(
            '/N[ÃA]O\s+(procure|existe)/iu',
            $roteiro,
            'o roteiro não declara as ausências ("Comparar lado a lado", "Gerar novamente com IA")'
        );

        // ⚠️⚠️ E ele NÃO pode afirmar que a tela foi vista ou conferida por executor.
        $this->assertSame(
            0,
            preg_match('/conferi\s+a\s+tela|vi\s+a\s+tela|tela\s+conferida/iu', $roteiro),
            'o roteiro afirma que a tela foi vista/conferida — nenhum executor desta fase viu tela'
        );
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────
    // id de gate `fechamento-manifestos`
    // ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Os **8** manifestos existem, e nenhum id nasce malformado.
     *
     * ⚠️ `tests=0` **não é contagem, é número de fora** — e o harness **ABORTA** com ele, de
     * propósito. ⚠️ **Id repetido** no mesmo manifesto é pior que ausente: o harness pega o
     * **primeiro**, e o segundo **nunca roda**. ⚠️ **Id com mais de TRÊS métodos** equivale a filtro
     * de classe, passa dos 120 s do shell e soma contagens de coisas sem relação — e aí a correção
     * barata passa a ser *"elevar o número"*.
     */
    public function test_os_oito_manifestos_189_existem_com_ids_bem_formados_189_08(): void
    {
        $ausentes = [];
        $comZero = [];
        $repetidos = [];
        $acimaDeTres = [];
        $semTests = [];
        $total = 0;

        for ($n = 1; $n <= self::PLANOS; $n++) {
            $caminho = 'tests/gates/contagens-189-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT).'.txt';
            if (! file_exists(base_path($caminho))) {
                $ausentes[] = $caminho;

                continue;
            }
            $entradas = $this->entradasDoManifesto($n);
            $total += count($entradas);
            $vistos = [];
            foreach ($entradas as $e) {
                if ($e['tests'] === '') {
                    $semTests[] = $caminho.' / '.$e['id'];
                } elseif ($e['tests'] === '0') {
                    $comZero[] = $caminho.' / '.$e['id'];
                }
                if (isset($vistos[$e['id']])) {
                    $repetidos[] = $caminho.' / '.$e['id'];
                }
                $vistos[$e['id']] = true;
                if ($e['tipo'] === 'php') {
                    $metodos = substr_count($e['filtro'], '|') + 1;
                    if ($metodos > 3) {
                        $acimaDeTres[] = $caminho.' / '.$e['id'].' ('.$metodos.' métodos)';
                    }
                }
            }
        }

        $this->assertSame([], $ausentes, 'manifesto de plano da Fase 189 ausente — manifesto inexistente ABORTA o harness');
        $this->assertSame([], $semTests, 'id sem `tests=` — o harness ABORTA, e é de propósito');
        $this->assertSame([], $comZero, 'id com `tests=0` — não é contagem, é número de fora');
        $this->assertSame([], $repetidos, 'id repetido no mesmo manifesto — o harness pega o primeiro e o segundo NUNCA roda');
        $this->assertSame([], $acimaDeTres, 'id com mais de TRÊS métodos — equivale a filtro de classe e soma coisas sem relação');

        // ⚠️ A lista não pode estar vazia por construção: manifesto sem id nenhum passaria em todas
        // as asserções acima. **Gate sobre ZERO casos não prova nada.**
        $this->assertGreaterThanOrEqual(
            15,
            $total,
            'os 8 manifestos juntos declaram quase nenhum id — gate sobre ZERO casos não prova nada'
        );
    }

    /**
     * ⚠️⚠️ **A TERCEIRA BIJEÇÃO — id ↔ `<verify>` — sobre os OITO pares.**
     *
     * Um id pode estar **no manifesto, com contagem MEDIDA, apontando para métodos que EXISTEM — e
     * não ser rodado por `<verify>` nenhum**. ⚠️ **Ele não falha: ele nunca roda**, e é
     * **indistinguível de um gate verde**. A revisão da Fase 188 achou **nove** assim. ➡️ **Este
     * plano é o único lugar que vê os oito pares.**
     *
     * ⚠️ **Asserção ÚNICA por direção, com os infratores COLETADOS** — uma por id faria a contagem
     * crescer com o manifesto.
     *
     * ⚠️ **Ids de OUTRAS fases rodados como não-regressão** (`186-09 transversais`,
     * `188-07 casca-do-painel`, …) **não entram nesta bijeção**: eles são conferidos por existência
     * no manifesto da **fase dona**, numa lista derivada do próprio `<verify>`.
     */
    public function test_a_terceira_bijecao_id_verify_nos_oito_pares_189_08(): void
    {
        $orfaos = [];
        $naoDeclarados = [];
        $planosAusentes = [];
        $naoRegressaoSemDono = [];
        $paresMedidos = 0;

        for ($n = 1; $n <= self::PLANOS; $n++) {
            $nn = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $plano = base_path(self::DIR_FASE.'/189-'.$nn.'-PLAN.md');
            if (! file_exists($plano)) {
                $planosAusentes[] = '189-'.$nn.'-PLAN.md';

                continue;
            }
            $paresMedidos++;
            $texto = (string) file_get_contents($plano);
            $doManifesto = array_values(array_unique(array_column($this->entradasDoManifesto($n), 'id')));
            $doVerify = $this->idsDoVerify($texto, $nn);

            foreach (array_diff($doManifesto, $doVerify) as $id) {
                $orfaos[] = '189-'.$nn.' / '.$id;
            }
            foreach (array_diff($doVerify, $doManifesto) as $id) {
                $naoDeclarados[] = '189-'.$nn.' / '.$id;
            }

            // As não-regressões: todo `verificar-testes.sh FFF-NN <id>` com `FFF != 189` tem de
            // estar declarado no manifesto da fase DONA — senão o harness ABORTA naquela chamada.
            preg_match_all('/verificar-testes\.sh\s+(\d{3})-(\d{2})\s+([a-z0-9][a-z0-9-]*)/', $texto, $alheios);
            foreach ($alheios[1] as $i => $fase) {
                if ($fase === '189') {
                    continue;
                }
                $manif = base_path('tests/gates/contagens-'.$fase.'-'.$alheios[2][$i].'.txt');
                $ok = file_exists($manif)
                    && preg_match('/^'.preg_quote($alheios[3][$i], '/').'\s/m', (string) file_get_contents($manif)) === 1;
                if (! $ok) {
                    $naoRegressaoSemDono[] = '189-'.$nn.' roda '.$fase.'-'.$alheios[2][$i].' '.$alheios[3][$i];
                }
            }
        }

        $this->assertSame([], $planosAusentes, 'plano da Fase 189 ausente — não há como medir o par plano × manifesto');
        $this->assertSame(self::PLANOS, $paresMedidos, 'os oito pares plano × manifesto não foram todos medidos');
        $this->assertSame([], $orfaos, 'id DECLARADO no manifesto e fora de todo <verify>: ele nunca roda, e parece verde');
        $this->assertSame([], $naoDeclarados, 'id usado num <verify> e NÃO declarado no manifesto: o harness ABORTA');
        $this->assertSame([], $naoRegressaoSemDono, 'não-regressão rodada cujo id NÃO existe no manifesto da fase dona');

        // ⚠️⚠️ ISCA NAS DUAS DIREÇÕES, sobre a sonda DE VERDADE.
        $falso = '<verify>sh tests/gates/verificar-testes.sh 189-08 id-injetado-9z7</verify>';
        $this->assertContains(
            'id-injetado-9z7',
            $this->idsDoVerify($falso, '08'),
            'a sonda do <verify> não vê um id injetado: ela está cega'
        );
        $this->assertSame(
            [],
            $this->idsDoVerify($falso, '07'),
            'a sonda do <verify> casou um id declarado para OUTRA wave — o NN não está ancorado'
        );

        // ⚠️ E o número NU tem de ser recusado: `verificar-testes.sh 08 <id>` resolve
        // `contagens-186-08.txt`, a fase ERRADA, em silêncio.
        $this->assertSame(
            [],
            $this->idsDoVerify('sh tests/gates/verificar-testes.sh 08 id-nu-9z7', '08'),
            'a sonda aceitou o número NU no 1º argumento — ele resolve a Fase 186, em silêncio'
        );
    }

    /**
     * `vocabulario-tamanhos` em **LINHA PRÓPRIA** em cada manifesto que o roda, e em **NENHUM id
     * composto**.
     *
     * ⚠️⚠️ **As duas exigências são OPOSTAS, e confundi-las foi erro de briefing na Fase 188:**
     *   *(a)* todo `<verify>` que usa o id tem de ter a linha no manifesto da **sua** wave — senão o
     *         harness **ABORTA**;
     *   *(b)* **nenhum** id que rode **outros** arquivos pode incluí-lo — foi o **id COMPOSTO** que
     *         fez a wave 187-05 reprovar **dois ids de outras waves** e exigir consertar **quatro**
     *         manifestos.
     *
     * ⚠️ **A regra é "quem RODA, declara" — não "todos declaram".** Medido nesta fase: as waves
     * **02**, **04** e **08** não rodam o GATE-03 (a 02 é de servidor, a 04 mede o CSS construído, a
     * 08 é o fechamento) e **estão certas** em não declarar: um gate que exigisse a linha nos oito
     * **reprovaria três manifestos corretos**.
     *
     * ⚠️ **E a forma é `>=`, com N MEDIDO naquela wave** (`>=681`, `>=688`, `>=695`, `>=695`,
     * `>=695`): a contagem **cresce por construção** (um sub-teste por arquivo varrido) e **cada
     * `.jsx` novo a aumenta**. Exigir igualdade seria mais uma ocorrência de *"contagem que depende
     * de quantos arquivos existem"*.
     */
    public function test_o_vocabulario_tamanhos_em_linha_propria_e_em_nenhum_id_composto_189_08(): void
    {
        $arquivo = 'tests/js/publicador-vocabulario-novo.test.js';
        $rodaSemDeclarar = [];
        $declaraSemRodar = [];
        $idComposto = [];
        $formaErrada = [];
        $quantosDeclaram = 0;

        for ($n = 1; $n <= self::PLANOS; $n++) {
            $nn = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $plano = base_path(self::DIR_FASE.'/189-'.$nn.'-PLAN.md');
            $roda = file_exists($plano)
                && in_array('vocabulario-tamanhos', $this->idsDoVerify((string) file_get_contents($plano), $nn), true);

            $declara = false;
            foreach ($this->entradasDoManifesto($n) as $e) {
                if ($e['id'] === 'vocabulario-tamanhos') {
                    $declara = true;
                    if ($e['filtro'] !== $arquivo) {
                        $idComposto[] = '189-'.$nn.': o id próprio aponta para '.$e['filtro'];
                    }
                    if (! str_starts_with($e['tests'], '>=')) {
                        $formaErrada[] = '189-'.$nn.': tests='.$e['tests'].' (a forma é >=N, medido na wave)';
                    }
                } elseif (str_contains($e['filtro'], 'publicador-vocabulario-novo.test.js')) {
                    $idComposto[] = '189-'.$nn.' / '.$e['id'];
                }
            }

            if ($roda && ! $declara) {
                $rodaSemDeclarar[] = '189-'.$nn;
            }
            if ($declara && ! $roda) {
                $declaraSemRodar[] = '189-'.$nn;
            }
            if ($declara) {
                $quantosDeclaram++;
            }
        }

        $this->assertSame([], $rodaSemDeclarar, 'wave que RODA o vocabulario-tamanhos sem declará-lo: o harness ABORTA');
        $this->assertSame([], $declaraSemRodar, 'wave que DECLARA o vocabulario-tamanhos e não o roda: id órfão');
        $this->assertSame([], $idComposto, 'o arquivo do vocabulário embutido em id COMPOSTO — foi o que reprovou dois ids alheios na 187-05');
        $this->assertSame([], $formaErrada, 'o id do vocabulário com contagem de igualdade em vez de `>=N` medido');

        // ⚠️ ISCA INVERTIDA: a lista legítima não pode estar VAZIA, senão as quatro asserções acima
        // são verdadeiras por construção.
        $this->assertGreaterThanOrEqual(
            1,
            $quantosDeclaram,
            'nenhum manifesto declara o vocabulario-tamanhos — a lista legítima não pode estar vazia'
        );
    }
}
