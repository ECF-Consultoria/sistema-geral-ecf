<?php

namespace Tests\Feature\Publicador\Aprovacao;

use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 188, plano 13 — OS GATES DO PRÓPRIO FECHAMENTO.
 *
 * ⚠️⚠️ **O `188-FECHAMENTO.md` é MEDIÇÃO DATADA, não gate perene.** Cada número dele vale para o
 * commit escrito ao lado e **não deve ser "atualizado" por sessão futura** — quem quiser o número
 * de hoje, **mede hoje**. Por isso **nenhum método deste arquivo confere NÚMERO de teste, de
 * asserção, de build ou de manifest**: eles conferem a **FORMA** do documento. Régua que acompanha
 * o documento é régua que o executor seguinte ajusta.
 *
 * ⚠️ **A pergunta do GATE-01, respondida para o arquivo inteiro** — *o que precisaria estar errado
 * no mundo para estes gates falharem?*
 *   · alguém **apagar** o fechamento, ou deixá-lo como esqueleto (método 1);
 *   · alguém afirmar que **viu a tela**, ou escrever a forma *"em produção"* para uma fase que
 *     **não foi deployada** (método 2);
 *   · alguém citar, na lista de território, um **caminho que não existe** — e ler o silêncio do
 *     `git diff` como "intocado". **DEZ** caminhos fantasmas já foram medidos nesta milestone, e
 *     **TRÊS** vieram de briefing do orquestrador (método 3);
 *   · a tabela FID-01 deixar de dizer **como mediu** cada plano (método 3);
 *   · alguém **reescrever um critério** para ele caber, apagar um dos 20, ou deixar um `REPROVA`
 *     passar como se o fechamento "registrasse" reprovação em vez de parar (métodos 4 e 5);
 *   · uma **ressalva perder o dono**, ou uma pergunta virar `a definir` (métodos 5 e 6);
 *   · o **roteiro da conta 459** virar MENÇÃO em vez de roteiro, ou perder o aviso de que
 *     **aprovar não tem desfazer** (método 6) — foi exatamente a sobrevivente **M6** da Fase 187;
 *   · um **id de manifesto** nascer com `tests=0`, repetido, ou com mais de três métodos
 *     (método 7);
 *   · ⚠️⚠️ um id ficar **declarado no manifesto e fora de todo `<verify>`** — ele **não falha: ele
 *     nunca roda**, e é indistinguível de um gate verde. A revisão desta fase achou **nove** assim
 *     (método 8);
 *   · `vocabulario-tamanhos` ser embutido num **id composto**, ou ser rodado por uma wave que não
 *     o declara — e aí o harness **ABORTA** (método 9).
 *
 * ═══ ⚠️ O QUE ESTE ARQUIVO **NÃO** FAZ, E A RAZÃO MEDIDA ════════════════════════════════════════
 *
 * **1. NÃO usa range de git — nem git nenhum.** Medido nesta sessão: o range `db9b1ff9..HEAD` tem
 * **64** commits, dos quais **5 são de outras sessões** (escopos `coordenacao`, `189` e `186-01`).
 * Na Fase 187 o mesmo gate por range teria **12 vermelhos** por trabalho alheio. ➡️ A prova de
 * território é por **ESCOPO DO ASSUNTO**, em duas passadas, e mora no `188-FECHAMENTO.md` como
 * **medição datada**. O que fica aqui como gate **perene** é o **ANTI-FANTASMA** (`file_exists` em
 * cada `ALVO-INTOCADO:`), que não depende de git e por isso **não acusa o orquestrador nem a sessão
 * paralela**.
 *
 * **2. NÃO confere o AUTOR.** As três máquinas desta árvore commitam com o **mesmo** `user.name`
 * (`MB.ECF-100376`): um gate sobre `%an` é **vazio por construção** aqui.
 *
 * **3. NÃO varre `app/` inteiro à procura de vocabulário.** O gate transversal da Fase 186 fazia
 * isso e **BARROU UM DEPLOY** — o MCP da Shopee usa `'andamento'` como status de promoção da
 * Shopee, palavra comum do português. Aqui o recorte é a **lista CONSTANTE** dos arquivos desta
 * fase ({@see self::JSX_DA_FASE}, {@see self::PHP_DA_FASE}), com **isca do recorte**.
 *
 * **4. NÃO toca o banco.** Tudo é leitura de arquivo. Nenhuma asserção daqui pode ser satisfeita
 * por tabela vazia — e `pub_aprovacao_ofertas` está **vazia** nesta máquina, de propósito (quem
 * cria a primeira oferta é a **Fase 194**).
 *
 * **5. NENHUMA asserção dentro de laço.** Toda contagem é de **lista CONSTANTE** ou de infratores
 * **COLETADOS** numa asserção única — a família *"contagem que depende de quantos
 * arquivos/commits/linhas existem"* já tem **nove** ocorrências nesta milestone, todas consertadas
 * na RAIZ. ➡️ **A contagem de asserções deste arquivo é a MESMA com zero e com N commits no
 * escopo, e a mesma com 50 ou 500 ids nos manifestos.**
 *
 * ⚠️⚠️ **E O RECORTE DE SEÇÃO É `[^\n]*` NO CABEÇALHO E `[\s\S]*?` NO CORPO, NUNCA `.` COM `/s`.**
 * Medido no próprio fechamento da Fase 187: com `/s` o `.` casa `\n`, então o `.*$` do cabeçalho
 * **engole o documento inteiro**, o corpo sai **VAZIO** e o gate mede **NADA**, reprovando com
 * lista vazia. Há isca das **duas** direções: o recorte não pode estar vazio **e** tem de ser
 * **menor** que o documento.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class FechamentoDaFase188Test extends TestCase
{
    /** O diretório da fase. Constante, para o caminho não ser remontado em cada método. */
    private const DIR_FASE = '.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece';

    /** ⚠️ **São 20, contados no `ROADMAP.md`** — `15` é a Fase 187 e `17` a Fase 186. */
    private const CRITERIOS = 20;

    /** Os 13 planos da fase — e os 13 manifestos que lhes correspondem, um a um. */
    private const PLANOS = 13;

    /**
     * ⚠️ **LISTA CONSTANTE, NÃO `glob()`** — os **19** `.jsx` de produção da Fase 188. Medidos UMA
     * vez (no `188-FECHAMENTO.md` §3.6) e conferidos no disco por
     * {@see self::test_o_territorio_188_os_alvos_existem_e_a_fid01_declara_como_mediu_188_13}.
     * `glob()` aqui seria a armadilha 5 da Fase 186: a contagem subiria com a Fase 189, que
     * **edita** arquivos desta pasta.
     */
    private const JSX_DA_FASE = [
        'resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/TopoDoPainel.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/IdentificacaoDaOferta.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ColunaDeFotos.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ImagemGrandeDoPainel.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/GradeDeMiniaturas.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/BlocoDaImagemSelecionada.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ColunaDePreco.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/KpisDoPainel.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ComposicaoFinanceiraDoPainel.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/SimulacaoDePreco.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ComparacaoComAIrma.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/PendenciasDoPainel.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/RodapeDoPainel.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ModalDeAjuste.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ModalDeExcecao.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ModalDeFrete.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ModalDeReprovarImagem.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/AvisoDeVersaoMudou.jsx',
    ];

    /**
     * Os **3** arquivos PHP de **produção** da Fase 188. Lista constante, pela mesma razão.
     * (Os outros 9 `.php` novos da fase são **testes**, e estão nos manifestos.)
     */
    private const PHP_DA_FASE = [
        'app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php',
        'app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php',
        'app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php',
    ];

    /**
     * ⚠️⚠️ **LISTA CONSTANTE DAS PERGUNTAS OBRIGATÓRIAS — e isto NÃO é zelo.**
     *
     * Na Fase 187 a sobrevivente **M5** provou que **PISO não prova PRESENÇA**: tirar o `dono:` da
     * `PERGUNTA-17` (justamente a que carrega o bug latente da Fase 194) passava em **todas** as
     * asserções, porque elas eram `assertGreaterThanOrEqual` e, com 13 perguntas, perder **uma**
     * deixava 12 — acima de todos os pisos. ➡️ **O conserto NÃO foi elevar o piso**: é esta lista,
     * com os faltantes **COLETADOS** numa asserção única e **isca de id inventado**.
     */
    private const PERGUNTAS_OBRIGATORIAS = [
        '188-A', '188-B', '188-B3', '188-C', '188-E', '188-F',
        'VISUAL-188', 'VISUAL-187', '17', '13',
        'ROTULO-DE-VARIACAO', 'LER-SEM-COMENTARIOS', 'APROVADA-POR', 'OSCILANTES', '189',
    ];

    /**
     * As **6** linhas de forma fixa das baselines. ⚠️ Conferida a **FORMA**, nunca o número: o
     * documento é medição datada.
     */
    private const LINHAS_DE_BASELINE = ['COMMIT:', 'BUILD:', 'MANIFEST:', 'JS:', 'PHP:', 'PROTOTIPO'];

    /** Os veredictos possíveis de um critério. **Lista FECHADA** — fora dela é erro. */
    private const VEREDICTOS = ['OK', 'RESSALVA', 'REPROVA'];

    /** Como a FID-01 pode declarar que mediu. **Lista FECHADA.** */
    private const COMO_MEDIU = ['SCRIPT', 'LEITURA', 'SEM-PROTOTIPO'];

    // ───────────────────────────────────────────────────────────────────────────────────────────
    // Leitura e recorte — os dois helpers, com a disciplina do `/s` escrita no lugar onde ela
    // importa.
    // ───────────────────────────────────────────────────────────────────────────────────────────

    private function fechamento(): string
    {
        $caminho = base_path(self::DIR_FASE . '/188-FECHAMENTO.md');
        $this->assertFileExists($caminho, 'o 188-FECHAMENTO.md não existe — é o fechamento medido da fase');

        return (string) file_get_contents($caminho);
    }

    /**
     * Recorta a seção cujo cabeçalho de nível 2 contém `$marca`.
     *
     * ⚠️⚠️ **`[^\n]*` no cabeçalho e `[\s\S]*?` no corpo — NUNCA `.` com `/s`.** Com `/s` o `.`
     * casa `\n` e, por consequência, o `$` do cabeçalho deixa de ser fim de LINHA: o `.*$`
     * engoliria o documento inteiro e o corpo sairia **vazio**. Medido no fechamento da 187.
     */
    private function secao(string $texto, string $marca): string
    {
        $padrao = '/^##[^\n]*' . preg_quote($marca, '/') . '[^\n]*$([\s\S]*?)(?=^## |\z)/m';

        return preg_match($padrao, $texto, $m) === 1 ? $m[1] : '';
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────
    // id de gate `fechamento-territorio`
    // ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * O documento existe, não é esqueleto, e traz as **6** linhas de forma fixa.
     *
     * ⚠️ `file_exists` sozinho seria satisfeito por **arquivo vazio** — e esqueleto vazio é
     * exatamente o modo de falhar deste plano (⚠️ **três agentes caíram na Fase 187**, e um deixou
     * 2/3 de um plano sem commit). Por isso há piso de tamanho **e** a exigência das 6 linhas.
     */
    public function test_o_fechamento_188_existe_e_tem_as_seis_linhas_de_forma_fixa_188_13(): void
    {
        $texto = $this->fechamento();

        $this->assertGreaterThan(
            400,
            substr_count($texto, "\n"),
            'o 188-FECHAMENTO.md está curto demais para ser um fechamento de 20 critérios — esqueleto passa calado'
        );

        $ausentes = array_values(array_filter(
            self::LINHAS_DE_BASELINE,
            fn (string $linha) => preg_match('/^' . preg_quote($linha, '/') . '/m', $texto) !== 1
        ));
        $this->assertSame([], $ausentes, 'faltam linhas de baseline em forma fixa (ancoradas em início de linha)');

        // ⚠️ ISCA do ancoramento: uma linha de baseline INVENTADA não pode ser encontrada —
        // senão a sonda está casando o meio de uma frase em vez do começo da linha.
        $this->assertSame(
            0,
            preg_match('/^NAOEXISTE9Z7:/m', $texto),
            'a sonda das baselines aceitou uma linha inventada: ela não está ancorada'
        );

        // ⚠️ ISCA invertida: a palavra `PROTOTIPO` existe no documento também no MEIO de frases;
        // a sonda ancorada tem de distinguir, e por isso o controle positivo é medido.
        $this->assertGreaterThanOrEqual(
            1,
            preg_match_all('/^PROTOTIPO[^\n]*sha256/m', $texto),
            'a linha PROTOTIPO existe, mas sem o sha256 ao lado ela não prova que o documento não se moveu'
        );
    }

    /**
     * O documento **declara** que ninguém viu tela renderizada, e **não afirma deploy que não
     * houve**.
     *
     * ⚠️⚠️ As Fases 186 e 187 estão **em produção**; a **188 não**. A forma do Coverage Map tem de
     * ser *"Entregue no CÓDIGO — NÃO DEPLOYADO"*, **deliberadamente diferente** da das outras duas.
     * Um fechamento que copiasse a forma *"em produção"* delas afirmaria um deploy inexistente — e
     * isso é a ameaça **T-188-C5** (Repudiation) do próprio plano.
     *
     * ⚠️ As frases são procuradas com `\s+` entre as palavras, porque o documento é markdown de 100
     * colunas e **a frase quebra no meio**. Procurar com **espaço literal** foi o lado ESTREITO do
     * mesmo erro, medido no fechamento da 187 no mesmo dia.
     */
    public function test_o_fechamento_188_declara_que_ninguem_viu_tela_e_nao_afirma_deploy_188_13(): void
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
            'falta a frase "viu tela renderizada" — ela é o que impede ler as 12 SUMMARYs como relato de tela'
        );
        $this->assertMatchesRegularExpression(
            '/react-dom\/server/',
            $texto,
            'o documento não diz COMO o render foi feito (esbuild + react-dom/server), e sem isso a declaração é slogan'
        );

        $this->assertMatchesRegularExpression(
            '/Entregue\s+no\s+C[ÓO]DIGO/iu',
            $texto,
            'falta a forma "Entregue no CÓDIGO" do Coverage Map — a 188 NÃO foi deployada'
        );
        $this->assertMatchesRegularExpression(
            '/N[ÃA]O\s+DEPLOYADO/iu',
            $texto,
            'falta o "NÃO DEPLOYADO" — a forma "em produção" das Fases 186/187 afirmaria deploy inexistente'
        );

        // ⚠️ ISCA do quase-igual, na direção que importa: o documento NÃO pode dizer que a Fase 188
        // está em produção. A busca é pela afirmação sobre ESTA fase, não pela palavra "produção"
        // (que aparece legitimamente ao falar das Fases 186 e 187).
        $this->assertSame(
            0,
            preg_match('/Fase\s+188\s+est[áa]\s+em\s+produ/iu', $texto),
            'o documento afirma que a Fase 188 está em produção — ela NÃO foi deployada'
        );

        // ⚠️ A conferência visual da 187 continua ABERTA e SEPARADA. Presumi-la feita é o mesmo
        // erro com outra roupa.
        $this->assertMatchesRegularExpression(
            '/PERGUNTA-VISUAL-187:\s*ABERTA/u',
            $texto,
            'a conferência visual da Fase 187 (a FILA) tem de continuar ABERTA — esta fase não a substitui'
        );
    }

    /**
     * ⚠️⚠️ **O ANTI-FANTASMA — e é o gate PERENE deste arquivo.**
     *
     * `git diff -- <caminho que não existe>` sai **VAZIO**, e vazio é **indistinguível de
     * "intocado"**. São **DEZ** caminhos fantasmas medidos nesta milestone, e **TRÊS** vieram de
     * briefing do **orquestrador** — o mais recente escrito por uma wave **logo depois de ler o
     * aviso sobre ele**. ➡️ Cada linha `ALVO-INTOCADO:` do documento é conferida no disco.
     *
     * E, no mesmo método, a **FID-01 consolidada declara como mediu cada plano** — porque *"tabela
     * derivada de SCRIPT é confiável; tabela derivada de LEITURA não é"*, e um fechamento que não
     * diz qual das duas usou não permite julgar nada.
     */
    public function test_o_territorio_188_os_alvos_existem_e_a_fid01_declara_como_mediu_188_13(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^ALVO-INTOCADO: (.+)$/m', $texto, $m);
        $alvos = array_map('trim', $m[1]);

        $this->assertGreaterThanOrEqual(
            40,
            count($alvos),
            'a lista de alvos vetados encolheu — tirar alvo da lista é o conserto barato que o learnings manda evitar'
        );

        // ⚠️ UMA asserção, com os fantasmas COLETADOS: uma asserção por alvo faria a contagem do
        // gate crescer com a lista, que é a família de nove ocorrências desta milestone.
        $fantasmas = array_values(array_filter(
            $alvos,
            fn (string $a) => ! file_exists(base_path($a))
        ));
        $this->assertSame([], $fantasmas, 'alvos citados que NÃO existem no disco — "diff vazio" neles não prova nada');

        // ⚠️ ISCA do anti-fantasma: um caminho inventado TEM de ser visto como ausente, senão a
        // sonda é cega e `FANTASMAS=0` é indistinguível de sonda morta.
        $this->assertFalse(
            file_exists(base_path('app/Mcp/NaoExiste9z7/')),
            'a isca do anti-fantasma existe no disco: escolha outra'
        );

        // Os arquivos da fase, por lista CONSTANTE (nunca `glob()`), conferidos no disco.
        $semDisco = array_values(array_filter(
            array_merge(self::JSX_DA_FASE, self::PHP_DA_FASE),
            fn (string $a) => ! file_exists(base_path($a))
        ));
        $this->assertSame([], $semDisco, 'arquivos declarados como da Fase 188 que não estão no disco');

        // ⚠️ O invariante dos `.js` é "nenhuma PEÇA nova sob `resources/` é `.js`" — e NÃO
        // "nenhum arquivo novo é `.js`", que a 187-11 mediu ser **literalmente falso** (há 10
        // `.js` novos nesta fase, TODOS testes). Gate mais largo que o invariante reprova trabalho
        // certo.
        $pecasJs = array_values(array_filter(
            self::JSX_DA_FASE,
            fn (string $a) => ! str_ends_with($a, '.jsx')
        ));
        $this->assertSame([], $pecasJs, 'peça sob resources/ que não é .jsx — o content do Tailwind varre *.jsx e NÃO *.js');

        // A FID-01 consolidada: uma linha por plano, 01..13, com SCRIPT/LEITURA/SEM-PROTOTIPO.
        preg_match_all('/^FID-01-PLANO-(\d{2}): ([A-Z-]+)/m', $texto, $f);
        $planos = $f[1];
        $comos = $f[2];

        $this->assertSame(
            range(1, self::PLANOS),
            array_map('intval', $planos),
            'as linhas FID-01-PLANO-NN não cobrem 01..13 em ordem, sem buraco e sem repetição'
        );
        $forade = array_values(array_diff(array_unique($comos), self::COMO_MEDIU));
        $this->assertSame([], $forade, 'declaração de "como mediu" fora da lista fechada SCRIPT/LEITURA/SEM-PROTOTIPO');

        // ⚠️ ISCA do recorte: o documento NÃO pode declarar `LEITURA-RAPIDA` (ou qualquer veredicto
        // inventado) e passar — foi a mutação M4 do fechamento da 187.
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
     * ⚠️⚠️ **A FORMA FIXA É O QUE IMPEDE O "AJUSTE" SILENCIOSO.** São **20** critérios, numerados
     * `01`..`20` **sem buraco e sem repetição** — e `15` é a contagem da Fase 187, `17` a da 186.
     * As três entradas são vizinhas no `ROADMAP.md`, e um `awk` errado traz a fase errada **em
     * silêncio**.
     */
    public function test_os_vinte_criterios_188_estao_em_forma_fixa_sem_buraco_188_13(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^CRIT-(\d{2}): ([A-Z]+) — /m', $texto, $m);
        $numeros = array_map('intval', $m[1]);

        $this->assertSame(
            range(1, self::CRITERIOS),
            $numeros,
            'os veredictos não formam 01..20 em ordem, sem buraco e sem repetição (15 é a Fase 187, 17 é a 186)'
        );

        $forade = array_values(array_diff(array_unique($m[2]), self::VEREDICTOS));
        $this->assertSame([], $forade, 'veredicto fora da lista fechada OK/RESSALVA/REPROVA');

        // ⚠️ Cada linha tem de nomear o GATE que a prova. Veredicto sem gate é opinião.
        preg_match_all('/^CRIT-\d{2}: [^\n]*$/m', $texto, $linhas);
        $semGate = array_values(array_filter(
            $linhas[0],
            fn (string $l) => preg_match('/ gate /', $l) !== 1
        ));
        $this->assertSame([], $semGate, 'critérios cujo veredicto não nomeia o gate que o prova');

        // ⚠️ ISCA do ancoramento: `CRIT-99` no meio de uma frase NÃO pode entrar na contagem, e um
        // `CRIT-` ancorado inventado tem de ser visto. A primeira metade é provada pela igualdade
        // acima; esta é a segunda.
        $this->assertSame(
            0,
            preg_match('/^CRIT-99: /m', $texto),
            'a sonda dos critérios aceitou um número fora de 01..20'
        );
    }

    /**
     * Nenhum `REPROVA`, e **cada RESSALVA com DONO**.
     *
     * ⚠️ O fechamento **para** com `REPROVA` — ele não "registra" reprovação e segue. E ressalva
     * sem dono é dívida que ninguém herda: foi a mutação **M2** do fechamento da 187.
     *
     * ⚠️⚠️ **E o critério NUNCA é reescrito para caber.** Na Fase 186 o critério 7 **foi**
     * reescrito, com razão declarada (ele **contradizia o 6** e era **aritmeticamente
     * impossível**). Se algo dessa natureza aparecer, o fechamento **escreve e devolve ao
     * usuário** — não conserta por conta própria. Por isso este método também exige que o
     * documento carregue essa regra por escrito.
     */
    public function test_nenhum_criterio_188_reprovado_e_cada_ressalva_tem_dono_188_13(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^CRIT-(\d{2}): ([A-Z]+) — ([^\n]*)$/m', $texto, $m);

        $reprovados = [];
        $ressalvaSemDono = [];
        foreach ($m[1] as $i => $numero) {
            if ($m[2][$i] === 'REPROVA') {
                $reprovados[] = $numero;
            }
            if ($m[2][$i] === 'RESSALVA' && preg_match('/dono/iu', $m[3][$i]) !== 1) {
                $ressalvaSemDono[] = $numero;
            }
        }

        $this->assertSame([], $reprovados, 'há critério REPROVADO — o fechamento PARA com REPROVA, não o registra');
        $this->assertSame([], $ressalvaSemDono, 'RESSALVA sem dono nomeado na própria linha');

        // ⚠️ O critério 2 tem **ressalva obrigatória** por DECISÃO DO USUÁRIO (PERGUNTA-188-B3,
        // 18/10/2026): o botão "Abrir revisão detalhada ↗" NÃO é desenhado nesta fase. Escrevê-lo
        // como `OK` silencioso seria afirmar entrega que não houve.
        $this->assertMatchesRegularExpression(
            '/^CRIT-02: RESSALVA — /m',
            $texto,
            'o CRIT-02 tem de sair como RESSALVA: o quinto item do critério é da Fase 190/191 (PERGUNTA-188-B3)'
        );

        // A regra escrita, com `\s+` porque a frase quebra no markdown de 100 colunas.
        $this->assertMatchesRegularExpression(
            '/nunca\s+(é|e)\s+reescrito/iu',
            $texto,
            'falta a regra escrita de que critério que não passa recebe RESSALVA/REPROVA e NUNCA é reescrito para caber'
        );

        // O placar, por forma fixa — e ele tem de fechar em 20.
        $this->assertMatchesRegularExpression(
            '/PLACAR-188:\s*OK=(\d+)\s*RESSALVA=(\d+)\s*REPROVA=(\d+)/u',
            $texto,
            'falta a linha de forma fixa PLACAR-188: OK=N RESSALVA=N REPROVA=N'
        );
        preg_match('/PLACAR-188:\s*OK=(\d+)\s*RESSALVA=(\d+)\s*REPROVA=(\d+)/u', $texto, $p);
        $this->assertSame(
            self::CRITERIOS,
            (int) $p[1] + (int) $p[2] + (int) $p[3],
            'o placar não fecha em 20 — placar que não soma é placar que não foi contado'
        );
        $this->assertSame(
            count($m[1]) - count(array_filter($m[2], fn (string $v) => $v !== 'OK')),
            (int) $p[1],
            'o OK= do placar não bate com os veredictos OK em forma fixa'
        );
    }

    /**
     * As perguntas, **todas com DONO**; e o **roteiro da conta 459** como roteiro, não como menção.
     *
     * ⚠️⚠️ **`a definir` NÃO é dono.** E a sobrevivente **M6** da Fase 187 provou que provar uma
     * **MENÇÃO** a "conta 459" não prova o **ROTEIRO**: menção é *"lista de nomes"*, roteiro é
     * **comportamento**. ➡️ Aqui o roteiro é recortado à **sua seção** (nunca ao documento
     * inteiro — contar itens numerados no documento todo mediu **23** em vez de 8 na 187 e
     * **reprovou trabalho certo**), com itens numerados **sem buraco** e carregando o aviso de que
     * **aprovar não tem desfazer**.
     */
    public function test_as_perguntas_188_tem_dono_e_o_roteiro_da_conta_459_e_roteiro_188_13(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^PERGUNTA-([A-Z0-9-]+): ([A-Z]+)([^\n]*)$/m', $texto, $m);

        $semDono = [];
        foreach ($m[1] as $i => $id) {
            if (preg_match('/dono:\s*\S/u', $m[3][$i]) !== 1) {
                $semDono[] = $id;
            } elseif (preg_match('/dono:\s*a\s+definir/iu', $m[3][$i]) === 1) {
                $semDono[] = $id . ' (a definir NÃO é dono)';
            }
        }
        $this->assertSame([], $semDono, 'perguntas sem dono — ou com "a definir", que não é dono');

        // ⚠️ LISTA CONSTANTE, nunca piso: piso NÃO prova PRESENÇA (sobrevivente M5 da 187).
        $faltando = array_values(array_diff(self::PERGUNTAS_OBRIGATORIAS, $m[1]));
        $this->assertSame([], $faltando, 'perguntas obrigatórias ausentes do fechamento');

        // ⚠️ ISCA de id inventado: ele NÃO pode estar presente — senão a sonda casa qualquer coisa.
        $this->assertNotContains(
            'NAOEXISTE-9Z7',
            $m[1],
            'a sonda das perguntas aceitou um id inventado'
        );

        // ─── O roteiro, recortado à SUA seção ───────────────────────────────────────────────
        $roteiro = $this->secao($texto, 'ROTEIRO DA CONFERÊNCIA VISUAL');

        // ⚠️ ISCA do recorte, nas DUAS direções: ele não está vazio E é menor que o documento.
        $this->assertNotSame('', $roteiro, 'o recorte da seção do roteiro saiu VAZIO — recorte que não recorta mede NADA');
        $this->assertLessThan(
            strlen($texto),
            strlen($roteiro),
            'o recorte engoliu o documento inteiro — é o sintoma do `.` sob a flag /s'
        );

        preg_match_all('/^\s*(\d+)\. /m', $roteiro, $r);
        $itens = array_map('intval', $r[1]);
        $this->assertSame(
            range(1, count($itens)),
            $itens,
            'os itens do roteiro não são numerados de 1 a N sem buraco e sem repetição'
        );
        $this->assertGreaterThanOrEqual(10, count($itens), 'o roteiro é curto demais para cobrir os critérios que ele diz cobrir');

        $this->assertMatchesRegularExpression('/conta\s+459/u', $roteiro, 'o roteiro não nomeia a conta 459');

        // ⚠️ `\s+` entre as palavras: a frase QUEBRA no markdown de 100 colunas. Procurá-la com
        // espaço literal foi o lado ESTREITO do erro, medido na 187.
        $this->assertMatchesRegularExpression(
            '/n[ãa]o\s+h[áa]\s+desfazer/iu',
            $roteiro,
            'o roteiro não avisa que aprovar NÃO TEM DESFAZER — é o aviso que o usuário precisa ANTES de clicar'
        );

        // ⚠️ E o caminho alternativo para a fila VAZIA, que é o caso até a Fase 194.
        $this->assertMatchesRegularExpression(
            '/fila\s+est[áa]\s+vazia|fila\s+vazia|fila\s+nasce\s+vazia/iu',
            $roteiro,
            'o roteiro não traz o caminho alternativo para a fila VAZIA — e ela nasce vazia até a Fase 194'
        );

        // ⚠️ E ele NÃO pode mandar procurar o que esta fase não desenhou.
        $this->assertMatchesRegularExpression(
            '/N[ÃA]O\s+(existe|procure)/iu',
            $roteiro,
            'o roteiro não declara as ausências (o botão da revisão detalhada, o Ampliar, o Gerar novamente)'
        );
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────
    // id de gate `fechamento-manifestos`
    // ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Devolve, por manifesto, a lista de entradas `[id, tipo, filtro, tests]`.
     *
     * ⚠️ Leitura **CRUA**, sem helper de "tirar comentários": os manifestos são `.txt` com `#` de
     * comentário, e a forma dos ids é medida pela própria linha.
     */
    private function entradasDoManifesto(int $n): array
    {
        $caminho = base_path('tests/gates/contagens-188-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT) . '.txt');
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
     * Os **13** manifestos existem, e nenhum id nasce malformado.
     *
     * ⚠️ `tests=0` **não é contagem, é número de fora** — e o harness **ABORTA** com ele, de
     * propósito, para ninguém "deixar o número para depois". ⚠️ **Id repetido** no mesmo manifesto
     * é pior que ausente: o harness pega o **primeiro**, e o segundo **nunca roda**. ⚠️ **Id com
     * mais de TRÊS métodos** equivale a filtro de classe, passa dos 120 s do shell e soma
     * contagens de coisas sem relação — e aí a correção barata passa a ser *"elevar o número"*.
     */
    public function test_os_treze_manifestos_188_existem_com_ids_bem_formados_188_13(): void
    {
        $ausentes = [];
        $comZero = [];
        $repetidos = [];
        $acimaDeTres = [];
        $semTests = [];
        $total = 0;

        for ($n = 1; $n <= self::PLANOS; $n++) {
            $caminho = 'tests/gates/contagens-188-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT) . '.txt';
            if (! file_exists(base_path($caminho))) {
                $ausentes[] = $caminho;

                continue;
            }
            $entradas = $this->entradasDoManifesto($n);
            $total += count($entradas);
            $vistos = [];
            foreach ($entradas as $e) {
                if ($e['tests'] === '' ) {
                    $semTests[] = $caminho . ' / ' . $e['id'];
                } elseif ($e['tests'] === '0') {
                    $comZero[] = $caminho . ' / ' . $e['id'];
                }
                if (isset($vistos[$e['id']])) {
                    $repetidos[] = $caminho . ' / ' . $e['id'];
                }
                $vistos[$e['id']] = true;
                if ($e['tipo'] === 'php') {
                    $metodos = substr_count($e['filtro'], '|') + 1;
                    if ($metodos > 3) {
                        $acimaDeTres[] = $caminho . ' / ' . $e['id'] . ' (' . $metodos . ' métodos)';
                    }
                }
            }
        }

        $this->assertSame([], $ausentes, 'manifesto de plano da Fase 188 ausente — manifesto inexistente ABORTA o harness');
        $this->assertSame([], $semTests, 'id sem `tests=` — o harness ABORTA, e é de propósito');
        $this->assertSame([], $comZero, 'id com `tests=0` — não é contagem, é número de fora');
        $this->assertSame([], $repetidos, 'id repetido no mesmo manifesto — o harness pega o primeiro e o segundo NUNCA roda');
        $this->assertSame([], $acimaDeTres, 'id com mais de TRÊS métodos — equivale a filtro de classe e soma coisas sem relação');

        // ⚠️ Contagem de IDS é informativa e vai no documento como MEDIÇÃO. O que é gate aqui é a
        // FORMA. Mas a lista não pode estar vazia por construção: um manifesto sem id nenhum
        // passaria em todas as asserções acima.
        $this->assertGreaterThanOrEqual(
            40,
            $total,
            'os 13 manifestos juntos declaram quase nenhum id — gate sobre ZERO casos não prova nada'
        );
    }

    /**
     * ⚠️⚠️ **A TERCEIRA BIJEÇÃO — id ↔ `<verify>` — sobre os TREZE pares.**
     *
     * Um id pode estar **no manifesto, com contagem MEDIDA, apontando para métodos que EXISTEM — e
     * não ser rodado por `<verify>` nenhum**. ⚠️ **Ele não falha: ele nunca roda**, e é
     * **indistinguível de um gate verde**. A revisão desta fase achou **nove** assim
     * (`img-02-img-03-sem-regressao`, `tentar-de-novo`, `simular-sem-gravar`, `payload-dos-modais`
     * e cinco do `188-02`). ➡️ **O plano 188-13 é o único lugar que vê os treze pares.**
     *
     * ⚠️ **O `NN` é ANCORADO na forma `188-NN`**, porque o número nu resolve
     * `contagens-186-NN.txt` — **a fase errada, em silêncio** (compatibilidade DE PROPÓSITO: a Fase
     * 186 está em produção e seus 163 ids são chamados pelo número nu; os casos 15/16 do
     * `--autoteste` guardam isso).
     *
     * ⚠️ **Asserção ÚNICA por direção, com os infratores COLETADOS** — uma por id faria a contagem
     * crescer com o manifesto.
     */
    public function test_a_terceira_bijecao_id_verify_nos_treze_pares_188_13(): void
    {
        $orfaos = [];
        $naoDeclarados = [];
        $planosAusentes = [];
        $paresMedidos = 0;

        for ($n = 1; $n <= self::PLANOS; $n++) {
            $nn = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $plano = base_path(self::DIR_FASE . '/188-' . $nn . '-PLAN.md');
            if (! file_exists($plano)) {
                $planosAusentes[] = '188-' . $nn . '-PLAN.md';

                continue;
            }
            $paresMedidos++;
            $doManifesto = array_values(array_unique(array_column($this->entradasDoManifesto($n), 'id')));
            $doVerify = $this->idsDoVerify((string) file_get_contents($plano), $nn);

            foreach (array_diff($doManifesto, $doVerify) as $id) {
                $orfaos[] = '188-' . $nn . ' / ' . $id;
            }
            foreach (array_diff($doVerify, $doManifesto) as $id) {
                $naoDeclarados[] = '188-' . $nn . ' / ' . $id;
            }
        }

        $this->assertSame([], $planosAusentes, 'plano da Fase 188 ausente — não há como medir o par plano × manifesto');
        $this->assertSame(self::PLANOS, $paresMedidos, 'os treze pares plano × manifesto não foram todos medidos');
        $this->assertSame([], $orfaos, 'id DECLARADO no manifesto e fora de todo <verify>: ele nunca roda, e parece verde');
        $this->assertSame([], $naoDeclarados, 'id usado num <verify> e NÃO declarado no manifesto: o harness ABORTA');

        // ⚠️⚠️ ISCA NAS DUAS DIREÇÕES, sobre a sonda DE VERDADE.
        $falso = "<verify>sh tests/gates/verificar-testes.sh 188-13 id-injetado-9z7</verify>";
        $this->assertContains(
            'id-injetado-9z7',
            $this->idsDoVerify($falso, '13'),
            'a sonda do <verify> não vê um id injetado: ela está cega'
        );
        $this->assertSame(
            [],
            $this->idsDoVerify($falso, '12'),
            'a sonda do <verify> casou um id declarado para OUTRA wave — o NN não está ancorado'
        );

        // ⚠️ E o número NU tem de ser recusado: `verificar-testes.sh 13 <id>` resolve
        // `contagens-186-13.txt`, a fase ERRADA, em silêncio.
        $this->assertSame(
            [],
            $this->idsDoVerify('sh tests/gates/verificar-testes.sh 13 id-nu-9z7', '13'),
            'a sonda aceitou o número NU no 1º argumento — ele resolve a fase 186, em silêncio'
        );
    }

    /**
     * Os ids rodados por algum `<verify>` do plano `188-$nn`, com o **NN ANCORADO**.
     */
    private function idsDoVerify(string $plano, string $nn): array
    {
        $padrao = '/verificar-testes\.sh\s+188-' . preg_quote($nn, '/') . '\s+([a-z0-9][a-z0-9-]*)/';
        preg_match_all($padrao, $plano, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * `vocabulario-tamanhos` em **LINHA PRÓPRIA** em cada manifesto que o roda, e em **NENHUM id
     * composto**.
     *
     * ⚠️⚠️ **As duas coisas são OPOSTAS, e confundi-las foi erro de briefing nesta fase.**
     *   *(a)* todo `<verify>` que usa o id tem de ter a linha no manifesto da **sua** wave — senão
     *         o harness **ABORTA** (medido: `verificar-testes.sh 188-07 vocabulario-tamanhos` →
     *         *"ABORTOU: manifesto nao existe"*);
     *   *(b)* nenhum id que rode **OUTROS** arquivos pode incluí-lo — foi o **id COMPOSTO** que fez
     *         a wave 187-05 reprovar **dois ids de outras waves** e exigir consertar **quatro**
     *         manifestos.
     *
     * ⚠️ **E a forma é `>=`, com N MEDIDO naquela wave.** A contagem **cresce por construção** (um
     * sub-teste por arquivo varrido nos diretórios fechados) e **cada `.jsx` novo a aumenta**:
     * exigir igualdade seria a **nona** ocorrência de *"contagem que depende de quantos arquivos
     * existem"*. ⚠️ **Não é "quem roda copia o N da 187": é quem roda MEDE o seu.**
     */
    public function test_o_vocabulario_tamanhos_em_linha_propria_e_em_nenhum_id_composto_188_13(): void
    {
        $arquivo = 'tests/js/publicador-vocabulario-novo.test.js';
        $rodaSemDeclarar = [];
        $declaraSemRodar = [];
        $idComposto = [];
        $formaErrada = [];

        for ($n = 1; $n <= self::PLANOS; $n++) {
            $nn = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $plano = base_path(self::DIR_FASE . '/188-' . $nn . '-PLAN.md');
            $roda = file_exists($plano)
                && in_array('vocabulario-tamanhos', $this->idsDoVerify((string) file_get_contents($plano), $nn), true);

            $declara = false;
            foreach ($this->entradasDoManifesto($n) as $e) {
                if ($e['id'] === 'vocabulario-tamanhos') {
                    $declara = true;
                    if ($e['filtro'] !== $arquivo) {
                        $idComposto[] = '188-' . $nn . ': o id próprio aponta para ' . $e['filtro'];
                    }
                    if (! str_starts_with($e['tests'], '>=')) {
                        $formaErrada[] = '188-' . $nn . ': tests=' . $e['tests'] . ' (a forma é >=N, medido na wave)';
                    }
                } elseif (str_contains($e['filtro'], 'publicador-vocabulario-novo.test.js')) {
                    $idComposto[] = '188-' . $nn . ' / ' . $e['id'];
                }
            }

            if ($roda && ! $declara) {
                $rodaSemDeclarar[] = '188-' . $nn;
            }
            if ($declara && ! $roda) {
                $declaraSemRodar[] = '188-' . $nn;
            }
        }

        $this->assertSame([], $rodaSemDeclarar, 'wave que RODA o vocabulario-tamanhos sem declará-lo: o harness ABORTA');
        $this->assertSame([], $declaraSemRodar, 'wave que DECLARA o vocabulario-tamanhos e não o roda: id órfão');
        $this->assertSame([], $idComposto, 'o arquivo do vocabulário embutido em id COMPOSTO — foi o que reprovou dois ids alheios na 187-05');
        $this->assertSame([], $formaErrada, 'o id do vocabulário com contagem de igualdade em vez de `>=N` medido');

        // ⚠️ A regra é **quem roda, declara** — e NÃO *todos declaram*. Medido nesta fase: as waves
        // 02 a 06 são de SERVIDOR, não rodam o GATE-03, e **estão certas** em não declarar. Um
        // gate que exigisse a linha nos treze reprovaria cinco manifestos corretos.
        $this->assertGreaterThanOrEqual(
            1,
            count(array_filter(
                range(1, self::PLANOS),
                fn (int $n) => in_array('vocabulario-tamanhos', array_column($this->entradasDoManifesto($n), 'id'), true)
            )),
            'nenhum manifesto declara o vocabulario-tamanhos — a lista legítima não pode estar vazia (isca invertida)'
        );
    }
}
