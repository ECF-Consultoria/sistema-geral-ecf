<?php

namespace Tests\Feature\Publicador\Aprovacao;

use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 187, plano 11 — OS GATES DO FECHAMENTO.
 *
 * ⚠️⚠️ **O `187-FECHAMENTO.md` é MEDIÇÃO DATADA, não gate perene.** Os números dele valem para o
 * commit medido e **não devem ser "atualizados" por sessão futura** — quem quiser o número de hoje,
 * mede hoje. Por isso **nenhum método deste arquivo confere NÚMERO de teste, de asserção ou de
 * build**: eles conferem a **FORMA** do documento, que é o que impede o "ajuste" silencioso.
 *
 * ⚠️ **A pergunta do GATE-01, respondida para o arquivo inteiro:** *o que precisaria estar errado no
 * mundo para estes gates falharem?*
 *   · alguém **apagar** o fechamento, ou deixá-lo como esqueleto (método 1);
 *   · alguém **reescrever um critério** para ele caber, ou deixar um `REPROVA` passar como se o
 *     fechamento "registrasse" reprovação em vez de parar (método 2);
 *   · alguém citar, na lista de território, um **caminho que não existe** — e ler o silêncio do
 *     `git diff` como "intocado". **Oito** caminhos fantasmas já foram medidos nesta milestone
 *     (método 3);
 *   · a tabela FID-01 deixar de dizer **como mediu** cada plano (método 6);
 *   · esta fase passar a **instalar pacote** ou a usar namespace/vendor que não existia (método 5);
 *   · as perguntas em aberto perderem o **dono** (métodos 7 a 9).
 *
 * ═══ ⚠️ O QUE ESTE ARQUIVO **NÃO** FAZ, E A RAZÃO MEDIDA ════════════════════════════════════════
 *
 * **1. NÃO usa range de git.** É o item 10 da lista do `FechamentoDaFase186Test`, e nesta fase ele
 * foi **medido**, não herdado: o range `75b9f82f..HEAD` tem **111** commits, dos quais **43 são da
 * SESSÃO PARALELA** (escopos `197`-`201`, `v27`, `v28`, `portal`, `quick-261016-*`, `186-06`). Um
 * gate de diff vazio por range **REPROVA por trabalho alheio** — medido: **11** dos alvos vetados
 * aparecem "tocados" no range, e **0** deles por commit desta fase. A prova de território por range
 * e commit por commit é **medição**, e mora no `187-FECHAMENTO.md`.
 *
 * **2. NÃO varre `app/` inteiro à procura de vocabulário.** O gate transversal da Fase 186 fazia
 * isso e **barrou um deploy**: o MCP da Shopee usa `'andamento'` como status de promoção — palavra
 * comum do português. Aqui o recorte é a **lista CONSTANTE** dos arquivos desta fase
 * ({@see self::JSX_DA_FASE}, {@see self::PHP_DA_FASE}), e o recorte é **provado com isca**.
 *
 * **3. NÃO toca o banco.** Tudo é leitura de arquivo. Nenhuma asserção daqui pode ser satisfeita
 * por tabela vazia — e `pub_aprovacao_ofertas` está vazia nesta máquina, de propósito (quem cria
 * oferta é a Fase 194).
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class FechamentoDaFase187Test extends TestCase
{
    /** O diretório da fase. Uma constante, para o caminho não ser remontado em cada método. */
    private const DIR_FASE = '.planning/phases/187-a-fila-aguardando-aprovacao-e-a-aba-que-leva-a-ela';

    /**
     * ⚠️ **LISTA CONSTANTE, NÃO `glob()`** — a armadilha 5 da Fase 186 (contagem que depende de
     * quantos arquivos existem). Os **20** `.jsx` de produção da Fase 187, conferidos no disco por
     * {@see self::test_territorio_187_os_alvos_citados_existem_no_disco}.
     */
    private const JSX_DA_FASE = [
        'resources/js/Components/Mlb/Publicador/publicacoes/AbasDaPagina.jsx',
        'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/CabecalhoDaFila.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/FaixaDeAndamento.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ProximoARevisar.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/FiltrosDaFila.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/PopoverDeFiltro.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/TabelaDaFila.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/LinhaDeProdutoDaFila.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/SubLinhaDeConjunto.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/QuadradosDeFoto.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/LinhaDaOferta.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/AnelDeProgresso.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/BarraDeMargem.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/BarraDeSelecao.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/CascaDeModal.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ModalDeLote.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/ToastDaFila.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/RodapeDaFila.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/VazioDaFila.jsx',
    ];

    /** Os **11** arquivos PHP de produção da Fase 187. Lista constante, pela mesma razão. */
    private const PHP_DA_FASE = [
        'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
        'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php',
        'app/Services/Publicador/Aprovacao/Fila/FiltrosDaFilaService.php',
        'app/Services/Publicador/Aprovacao/Fila/GaleriaDaOfertaService.php',
        'app/Services/Publicador/Aprovacao/Fila/GrupoDaFilaService.php',
        'app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php',
        'app/Services/Publicador/Aprovacao/Fila/LoteDaFilaService.php',
        'app/Services/Publicador/Aprovacao/Fila/AquecimentoDeTarifasService.php',
        'app/Jobs/AquecerTarifasDaFilaJob.php',
        'app/Console/Commands/WarmTarifasDaFila.php',
        'app/Http/Controllers/MlbPublicadorAprovacaoController.php',
    ];

    /**
     * ⚠️ Os namespaces de topo que o projeto **já usava antes desta fase**. T-187-SC pela FORMA:
     * a fase não instalou pacote, e a prova que **não depende de git** é que nenhum `use` dos 11
     * arquivos novos sai desta lista. Medido em 18/10/2026: `App` (47), `Illuminate` (22),
     * `Inertia` (1) — e nada mais.
     */
    private const NAMESPACES_JA_DECLARADOS = ['App', 'Illuminate', 'Inertia'];

    /**
     * ⚠️ Os pacotes npm que os 20 `.jsx` podem importar. Medido: só `react` e `@inertiajs/react`.
     * Qualquer outro nome de pacote num arquivo desta fase significa **instalação nova** — que é
     * exatamente o que o T-187-SC proíbe, e a razão de a régua ser por FORMA e não por `numstat`:
     * o `numstat` do range **não discrimina a sessão paralela**, o import discrimina.
     */
    private const PACOTES_PERMITIDOS = ['react', '@inertiajs/react'];

    // ───────────────────────────────────────────────────────────────────────────────────────────
    // id de gate `fechamento-15-criterios`
    // ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * O documento existe — e **só isso**, porque é medição datada.
     *
     * ⚠️ `file_exists` sozinho seria satisfeito por **arquivo vazio**, e esqueleto vazio é
     * exatamente o modo de falhar deste plano (três agentes caíram nesta fase; um fechamento
     * truncado passaria calado). Por isso há piso de tamanho e a exigência das 8 linhas de forma
     * fixa, que é o que distingue "medido" de "a medir".
     */
    public function test_o_fechamento_187_existe_e_tem_as_oito_linhas_de_forma_fixa(): void
    {
        $caminho = base_path(self::DIR_FASE . '/187-FECHAMENTO.md');
        $this->assertFileExists($caminho, 'o 187-FECHAMENTO.md nao existe — o fechamento da fase nao foi escrito');

        $texto = (string) file_get_contents($caminho);
        $linhas = substr_count($texto, "\n");
        $this->assertGreaterThanOrEqual(
            200,
            $linhas,
            "o 187-FECHAMENTO.md tem {$linhas} linhas — abaixo do piso de 200 do plano, logo e esqueleto, nao medicao"
        );

        // As 8 linhas de forma fixa. O prefixo e ancorado no COMECO DA LINHA de propósito: a prosa
        // do documento cita `COMMIT:` e `MANIFEST:` no meio de frase, e um gate sem ancora casaria
        // a explicação do formato em vez da medida.
        $prefixos = [
            'COMMIT: ',
            'BUILD: ',
            'MANIFEST: ',
            'JS: ',
            'PHP: ',
            'PROTOTIPO design_handoff_publicador/Aprovacao.dc.html: ',
            'PROTOTIPO design_handoff_publicador/Publicacoes v2.dc.html: ',
            'PROTOTIPO new_layout_publicador/Publicacoes v2.dc.html: ',
        ];
        $faltando = [];
        foreach ($prefixos as $p) {
            if (! preg_match('/^' . preg_quote($p, '/') . '\S/m', $texto)) {
                $faltando[] = $p;
            }
        }
        $this->assertSame([], $faltando, 'linha(s) de forma fixa ausente(s) ou sem valor no 187-FECHAMENTO.md');

        // ⚠️ ISCA DO `^`: a MESMA sonda tem de RECUSAR a mesma palavra no meio da linha. Sem esta
        // isca eu nao saberia se o gate le a MEDIDA ou a prosa que explica a medida.
        $this->assertSame(
            0,
            preg_match('/^COMMIT: \S/m', "a linha COMMIT: deve vir no comeco\ne nao no meio\n"),
            'a sonda das 8 linhas casou texto no MEIO da linha — ela esta lendo a prosa, nao a medida'
        );
        // ⚠️ ISCA NA DIRECAO POSITIVA: ela casa o que DEVE casar.
        $this->assertSame(
            1,
            preg_match('/^COMMIT: \S/m', "bla\nCOMMIT: 7791d7aa\nbla\n"),
            'a sonda das 8 linhas nao casa a propria forma que exige — gate vazio'
        );
    }

    /**
     * ⚠️⚠️ **OS 15 CRITÉRIOS EM LINHA DE FORMA FIXA — e nenhum `REPROVA`.**
     *
     * A forma fixa é o que impede o **"ajuste" silencioso**. Na Fase 186 a primeira redação do
     * fechamento deixava os 17 critérios atrás de um `grep -c` de uma frase de cabeçalho, e as
     * baselines **podiam ser inventadas** sem nenhum gate notar.
     *
     * ⚠️ **São 15, não 17.** Medido no `ROADMAP.md` em 18/10/2026:
     * `awk '/^### Phase 187:/,/^### Phase 188:/' | grep -cE "^  [0-9]+\. "` devolve **15**,
     * numerados 1..15 sem buraco. **17 é a contagem da Fase 186.**
     *
     * ⚠️ **Cada linha nomeia o GATE e a CONTAGEM.** Linha sem gate é prosa — e prosa é o que o
     * fechamento da 186 teve de refazer.
     */
    public function test_o_fechamento_187_tem_os_quinze_criterios_sem_nenhum_reprova(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^CRIT-(\d{2}): (OK|RESSALVA|REPROVA) — (.+)$/m', $texto, $m, PREG_SET_ORDER);

        $this->assertCount(15, $m, 'o 187-FECHAMENTO.md nao tem exatamente 15 linhas CRIT-NN em forma fixa');

        // Numerados 01..15, sem buraco e sem repetição.
        $numeros = array_map(static fn (array $l): int => (int) $l[1], $m);
        $this->assertSame(range(1, 15), $numeros, 'os CRIT-NN nao vao de 01 a 15 em ordem, sem buraco');

        // ⚠️ Nenhum `REPROVA`: o fechamento nao "registra" reprovacao, ele PARA.
        $reprovados = array_values(array_filter($m, static fn (array $l): bool => $l[2] === 'REPROVA'));
        $this->assertSame(
            [],
            array_map(static fn (array $l): string => 'CRIT-' . $l[1], $reprovados),
            'ha CRIT-NN com veredicto REPROVA — o fechamento PARA, nao registra'
        );

        // ⚠️ Cada linha nomeia gate E contagem. Infratores COLETADOS numa lista (armadilha 5 da
        // 186: asserção que para no primeiro esconde os outros).
        $semGate = [];
        foreach ($m as $l) {
            if (! preg_match('/gate [a-z0-9][a-z0-9-]*, \d/', $l[3])) {
                $semGate[] = 'CRIT-' . $l[1];
            }
        }
        $this->assertSame([], $semGate, 'CRIT-NN sem "gate <id>, <N>" na linha — linha sem gate e prosa');

        // ⚠️ ISCA NAS DUAS DIRECOES da sonda de gate.
        $this->assertSame(
            1,
            preg_match('/gate [a-z0-9][a-z0-9-]*, \d/', 'medido pelo gate fechamento-15-criterios, 3/41'),
            'a sonda de "gate <id>, <N>" nao casa a propria forma que exige'
        );
        $this->assertSame(
            0,
            preg_match('/gate [a-z0-9][a-z0-9-]*, \d/', 'conferido pelo gate da wave anterior'),
            'a sonda de "gate <id>, <N>" aceita prosa sem contagem — ela nao mede nada'
        );
        // ⚠️ ISCA DO VEREDICTO: a sonda tem de RECUSAR veredicto inventado.
        $this->assertSame(
            0,
            preg_match('/^CRIT-(\d{2}): (OK|RESSALVA|REPROVA) — /m', "CRIT-01: QUASE — nao vale\n"),
            'a sonda dos criterios aceita veredicto fora dos tres — a forma fixa nao esta fixa'
        );
    }

    /**
     * ⚠️⚠️ **O GATE ANTI-FANTASMA — e é o mais importante deste arquivo.**
     *
     * `git diff -- <caminho que nao existe>` sai **VAZIO**, e vazio é indistinguível de
     * "intocado". Esta milestone acumulou **OITO** caminhos fantasmas em briefings e planos, e
     * **quatro deles foram medidos neste plano** — todos os quatro com o arquivo REAL em outro
     * lugar, o que é pior que a ausência, porque a conclusão "território intocado" é tirada sobre
     * o arquivo errado:
     *
     *   · `app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php` → o real é
     *     `app/Services/Publicador/Fila/ResumoRapidoService.php` (**e ele NÃO é o
     *     `FilaDeAprovacaoService`** — é o serviço da visão rápida do lote, o do
     *     `ResumoRapidoService:384-391` da decisão 9 do usuário);
     *   · `app/Services/Publicador/SimuladorVoceRecebe.php` → `app/Support/Publicador/Validacao/`;
     *   · `app/Services/Publicador/AnaliseAlavancasService.php` → `app/Services/Publicador/Alavancas/`;
     *   · `resources/js/Pages/Mlb/Publicador/useCriativosDoPublicador.js` →
     *     `resources/js/Components/Publicador/useCriativosDoPublicador.js`.
     *
     * ➡️ Por isso a lista de território do fechamento é **declarada linha a linha** com o prefixo
     * `ALVO-INTOCADO:` e **cada linha é conferida no disco aqui**.
     */
    public function test_territorio_187_os_alvos_citados_existem_no_disco(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^ALVO-INTOCADO: (\S.*)$/m', $texto, $m);
        $alvos = array_map('trim', $m[1]);

        // Gate sobre ZERO casos nao prova nada — e o piso e a medida de 18/10/2026 (60 alvos).
        // ⚠️ PISO, nao igualdade: a lista pode CRESCER (fase futura acrescenta territorio) e nunca
        // deve ENCOLHER. Igualdade exata treinaria o executor seguinte a mexer na regua.
        $this->assertGreaterThanOrEqual(
            60,
            count($alvos),
            'a lista ALVO-INTOCADO do fechamento ENCOLHEU — alvo de territorio nao se tira, se acrescenta'
        );

        $fantasmas = [];
        foreach ($alvos as $alvo) {
            $limpo = rtrim($alvo, '/');
            if (! file_exists(base_path($limpo))) {
                $fantasmas[] = $alvo;
            }
        }
        $this->assertSame(
            [],
            $fantasmas,
            'caminho FANTASMA na lista de territorio: `git diff` sai vazio nele e o vazio seria lido como "intocado"'
        );

        // ⚠️ ISCA: a conferencia de existencia TEM de reprovar um caminho inventado. Sem ela eu
        // nao saberia se o `file_exists` esta olhando para onde eu penso.
        $this->assertFileDoesNotExist(
            base_path('app/Services/Publicador/Aprovacao/Fila/NaoExiste9z7Service.php'),
            'a isca do fantasma EXISTE no disco — troque o nome da isca'
        );
        // ⚠️ ISCA DO `^`: mencao de `ALVO-INTOCADO` no meio de uma frase NAO conta como alvo.
        $this->assertSame(
            0,
            preg_match('/^ALVO-INTOCADO: (\S.*)$/m', "o prefixo ALVO-INTOCADO: abre cada linha\n"),
            'a sonda de territorio casa mencao no MEIO da linha — ela esta contando a prosa'
        );
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────
    // id de gate `territorio-e-fid`
    // ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ **T-187-SC pela FORMA, não por `numstat`.** O `numstat` do range não discrimina a sessão
     * paralela (43 dos 111 commits são dela); o **import** discrimina, porque ele está nos
     * arquivos **desta** fase.
     *
     * Se alguém instalar pacote para esta área, o sinal aparece aqui — e aparece **antes** de o
     * `composer.lock` entrar em diff.
     */
    public function test_territorio_187_nenhum_import_novo_fora_do_que_ja_estava_declarado(): void
    {
        // ─── PHP: todo `use` dos 11 arquivos novos cai nos 3 namespaces ja declarados ───
        $forasteirosPhp = [];
        $totalUse = 0;
        foreach (self::PHP_DA_FASE as $rel) {
            $codigo = $this->semComentariosPhp(base_path($rel));
            preg_match_all('/^use\s+([A-Za-z0-9_]+)\\\\/m', $codigo, $u);
            foreach ($u[1] as $topo) {
                $totalUse++;
                if (! in_array($topo, self::NAMESPACES_JA_DECLARADOS, true)) {
                    $forasteirosPhp[] = "{$rel}: {$topo}";
                }
            }
        }
        $this->assertGreaterThan(50, $totalUse, 'a varredura de `use` leu quase nada — sonda cega');
        $this->assertSame([], $forasteirosPhp, 'namespace de topo NOVO nos arquivos PHP da fase (T-187-SC)');

        // ─── JS: todo import nao-relativo e nao-alias dos 20 .jsx e pacote ja permitido ───
        $pacotesDoProjeto = json_decode((string) file_get_contents(base_path('package.json')), true);
        $declarados = array_merge(
            array_keys($pacotesDoProjeto['dependencies'] ?? []),
            array_keys($pacotesDoProjeto['devDependencies'] ?? [])
        );
        // Os dois permitidos TEM de estar no package.json — senao a lista `PACOTES_PERMITIDOS`
        // seria a propria porta dos fundos (learnings §4c: "o que impede alguem de entrar nela?").
        foreach (self::PACOTES_PERMITIDOS as $p) {
            $this->assertContains($p, $declarados, "o pacote permitido `{$p}` nao esta no package.json");
        }

        $forasteirosJs = [];
        $totalImport = 0;
        foreach (self::JSX_DA_FASE as $rel) {
            $codigo = (string) file_get_contents(base_path($rel));
            preg_match_all('/\bfrom\s+\'([^\']+)\'/', $codigo, $i);
            foreach ($i[1] as $alvo) {
                $totalImport++;
                if (str_starts_with($alvo, '.') || str_starts_with($alvo, '@/')) {
                    continue;
                }
                if (! in_array($alvo, self::PACOTES_PERMITIDOS, true)) {
                    $forasteirosJs[] = "{$rel}: {$alvo}";
                }
            }
        }
        $this->assertGreaterThan(30, $totalImport, 'a varredura de import leu quase nada — sonda cega');
        $this->assertSame([], $forasteirosJs, 'pacote npm NOVO nos arquivos .jsx da fase (T-187-SC)');

        // ⚠️ ISCA NAS DUAS DIRECOES da sonda de import.
        $this->assertSame(
            1,
            preg_match('/\bfrom\s+\'([^\']+)\'/', "import x from 'biblioteca-nova';", $prova),
            'a sonda de import nao casa a propria forma que exige'
        );
        $this->assertSame('biblioteca-nova', $prova[1], 'a sonda de import extrai o alvo errado');
        $this->assertSame(
            0,
            preg_match('/\bfrom\s+\'([^\']+)\'/', 'const from = 1;'),
            'a sonda de import casa `from` que nao e import'
        );
    }

    /**
     * ⚠️ **FID-01/FID-02: a tabela consolidada diz COMO MEDIU cada plano.**
     *
     * *"Tabela derivada de SCRIPT é confiável; tabela derivada de LEITURA não é"* — e os ponteiros
     * dos planos desta fase erraram **wave por wave** (9/16 · 3 · 7/12 · 8/9 · 13/20 · 11/17 ·
     * 19/25). Uma linha por plano, **01 a 10**, com `SCRIPT` ou `LEITURA` declarado.
     */
    public function test_territorio_187_a_fid01_consolidada_declara_script_ou_leitura_por_plano(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^FID-01-PLANO-(\d{2}): (SCRIPT|LEITURA|SCRIPT\+LEITURA|SEM-PROTOTIPO) — (.+)$/m', $texto, $m, PREG_SET_ORDER);

        $this->assertCount(10, $m, 'a FID-01 consolidada nao tem exatamente uma linha por plano (01 a 10)');
        $numeros = array_map(static fn (array $l): int => (int) $l[1], $m);
        $this->assertSame(range(1, 10), $numeros, 'as linhas FID-01-PLANO-NN nao vao de 01 a 10 em ordem');

        // Cada linha diz onde foi medido (arquivo:linha ou o nome do .dc.html) — FID-03: a tabela
        // nao erra VALOR, erra por OMISSAO e por REFERENCIA.
        $semReferencia = [];
        foreach ($m as $l) {
            if (! preg_match('/\.(dc\.html|jsx|php)/', $l[3])) {
                $semReferencia[] = 'FID-01-PLANO-' . $l[1];
            }
        }
        $this->assertSame([], $semReferencia, 'linha da FID-01 sem referencia a arquivo medido (FID-03: erra por REFERENCIA)');

        // ⚠️ ISCA: `LEITURA-RAPIDA` nao e um dos quatro veredictos aceitos.
        $this->assertSame(
            0,
            preg_match('/^FID-01-PLANO-(\d{2}): (SCRIPT|LEITURA|SCRIPT\+LEITURA|SEM-PROTOTIPO) — /m', "FID-01-PLANO-01: LEITURA-RAPIDA — nao vale\n"),
            'a sonda da FID-01 aceita veredicto fora da lista — a forma fixa nao esta fixa'
        );
        $this->assertSame(
            1,
            preg_match('/^FID-01-PLANO-(\d{2}): (SCRIPT|LEITURA|SCRIPT\+LEITURA|SEM-PROTOTIPO) — /m', "FID-01-PLANO-07: SCRIPT — Aprovacao.dc.html\n"),
            'a sonda da FID-01 nao casa a propria forma que exige'
        );
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────
    // id de gate `fechamento-perguntas`
    // ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ **Toda pergunta aberta nomeada, com o DONO.** Sem dono, pergunta aberta é pergunta
     * perdida: a decisão **13** e a **17** desta milestone só sobreviveram porque alguém escreveu
     * o dono ao lado.
     */
    public function test_perguntas_187_toda_pergunta_tem_veredicto_e_dono(): void
    {
        $texto = $this->fechamento();

        preg_match_all('/^PERGUNTA-(\S+): (RESPONDIDA|ABERTA) — dono: ([^—]+) — (.+)$/m', $texto, $m, PREG_SET_ORDER);

        // 4 respondidas nesta fase + 4 herdadas em aberto = 8, no minimo.
        $this->assertGreaterThanOrEqual(8, count($m), 'o fechamento tem menos de 8 linhas PERGUNTA-* em forma fixa');

        $respondidas = array_values(array_filter($m, static fn (array $l): bool => $l[2] === 'RESPONDIDA'));
        $abertas = array_values(array_filter($m, static fn (array $l): bool => $l[2] === 'ABERTA'));
        $this->assertGreaterThanOrEqual(4, count($respondidas), 'as 4 perguntas do planejamento desta fase nao estao registradas como RESPONDIDA');
        $this->assertGreaterThanOrEqual(4, count($abertas), 'as 4 perguntas herdadas nao estao registradas como ABERTA');

        // Dono nao-vazio e nao-generico. "a definir" e o jeito de parecer ter dono sem ter.
        $semDono = [];
        foreach ($m as $l) {
            $dono = trim($l[3]);
            if ($dono === '' || preg_match('/^(a definir|ningu|tbd|\?)/i', $dono)) {
                $semDono[] = 'PERGUNTA-' . $l[1] . ' (dono: ' . $dono . ')';
            }
        }
        $this->assertSame([], $semDono, 'pergunta sem dono real — "a definir" nao e dono');

        // ⚠️ ISCA nas duas direcoes.
        $re = '/^PERGUNTA-(\S+): (RESPONDIDA|ABERTA) — dono: ([^—]+) — (.+)$/m';
        $this->assertSame(1, preg_match($re, "PERGUNTA-13: ABERTA — dono: Fase 193 — editar foto no ar\n"), 'a sonda de pergunta nao casa a forma que exige');
        $this->assertSame(0, preg_match($re, "PERGUNTA-13: ABERTA — editar foto no ar\n"), 'a sonda de pergunta aceita linha SEM dono');
    }

    /**
     * ⚠️ **As dívidas desta fase estão no `deferred-items.md`, com dono.** A régua: dívida que não
     * tem onde morar vira dívida que ninguém herda.
     */
    public function test_perguntas_187_as_dividas_da_fase_estao_no_deferred_items(): void
    {
        $caminho = base_path(self::DIR_FASE . '/deferred-items.md');
        $this->assertFileExists($caminho, 'o deferred-items.md da fase nao existe');

        $texto = (string) file_get_contents($caminho);

        // As cinco dívidas que o plano 187-11 nomeia, cada uma por um identificador EXCLUSIVO —
        // nunca por frase solta. ⚠️ `CascaDeModal` e `ResumoRapidoService` sao nomes de arquivo,
        // logo nao casam prosa por acidente.
        $esperadas = [
            'custo da composição' => '/custo da composi(ç|c)(ã|a)o/iu',
            'CascaDeModal (Fase 188 REUSA)' => '/CascaDeModal/',
            'legenda do rodapé sem falha/regenerando' => '/legenda do rodap(é|e)/iu',
            'D7' => '/\bD7\b/',
            'D9' => '/\bD9\b/',
            'DIV-01 da Fase 186' => '/DIV-01/',
        ];
        $faltando = [];
        foreach ($esperadas as $rotulo => $re) {
            if (! preg_match($re, $texto)) {
                $faltando[] = $rotulo;
            }
        }
        $this->assertSame([], $faltando, 'divida da Fase 187 ausente do deferred-items.md');

        // ⚠️ Cada divida tem DONO escrito. A forma fixa `DONO:` e ancorada no comeco da linha.
        preg_match_all('/^DONO: (\S.*)$/m', $texto, $d);
        $this->assertGreaterThanOrEqual(6, count($d[1]), 'o deferred-items.md tem menos de 6 linhas `DONO:` — divida sem dono nao e herdada');

        // ⚠️ ISCA: mencao no meio da linha nao conta.
        $this->assertSame(0, preg_match('/^DONO: (\S.*)$/m', "a linha DONO: abre cada divida\n"), 'a sonda de DONO casa mencao no MEIO da linha');
        $this->assertSame(1, preg_match('/^DONO: (\S.*)$/m', "DONO: Fase 188\n"), 'a sonda de DONO nao casa a forma que exige');
    }

    /**
     * ⚠️⚠️ **O HOMÔNIMO — e a razão de ele ser perigoso AGORA.**
     *
     * O `--filter` do PHPUnit é **regex NÃO ANCORADO**. A Fase 186 está **em produção** (deploy
     * `a4c4187f`) e seus **163 ids** continuam valendo. Se um `--filter` desta fase casasse um
     * método da 186 (ou o contrário), a contagem do manifesto mudaria em silêncio e o gate certo
     * passaria a medir o arquivo errado.
     *
     * Este método confere **nas DUAS direções**, por script, sobre os **11** manifestos da Fase 187
     * e os **9** da Fase 186.
     */
    public function test_perguntas_187_nenhum_filtro_dos_manifestos_tem_homonimo_nas_duas_direcoes(): void
    {
        $manifestos = glob(base_path('tests/gates/contagens-18[67]-*.txt'));
        sort($manifestos);
        $this->assertGreaterThanOrEqual(20, count($manifestos), 'achei menos de 20 manifestos (9 da 186 + 11 da 187)');

        // Todos os 11 da Fase 187 existem, e nenhum id com `tests=0`.
        $faltando = [];
        for ($i = 1; $i <= 11; $i++) {
            $nn = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            if (! file_exists(base_path("tests/gates/contagens-187-{$nn}.txt"))) {
                $faltando[] = "contagens-187-{$nn}.txt";
            }
        }
        $this->assertSame([], $faltando, 'manifesto da Fase 187 ausente — teste sem id no manifesto NUNCA roda');

        // Coleta os filtros PHP e os ids, de todos os manifestos.
        $filtros = [];   // id => filtro
        $zerados = [];
        foreach ($manifestos as $arq) {
            foreach (preg_split('/\R/', (string) file_get_contents($arq)) ?: [] as $linha) {
                if ($linha === '' || preg_match('/^\s*#/', $linha)) {
                    continue;
                }
                $campos = preg_split('/\s+/', trim($linha));
                if (count($campos) < 3) {
                    continue;
                }
                if (str_contains($linha, 'tests=0') && ! preg_match('/tests=0\d/', $linha)) {
                    $zerados[] = basename($arq) . ': ' . $campos[0];
                }
                if ($campos[1] === 'php') {
                    $filtros[basename($arq) . '#' . $campos[0]] = $campos[2];
                }
            }
        }
        $this->assertSame([], $zerados, "id com `tests=0` num manifesto — 'tests=0' nao e contagem, e numero de fora");
        $this->assertGreaterThan(40, count($filtros), 'colhi menos de 40 filtros PHP dos manifestos — sonda cega');

        // Os metodos DESTE arquivo.
        $meus = $this->metodosDesteArquivo();
        $this->assertCount(10, $meus, 'o FechamentoDaFase187Test nao tem exatamente 10 metodos de teste');

        // ⚠️ **ARMADILHA 1 da Fase 186: teste em arquivo sem id no manifesto NUNCA RODA.** Todo
        // metodo deste arquivo tem de ter id em `contagens-187-11.txt` — senao ele e lacuna
        // apresentada como cobertura.
        $meuManifesto = (string) file_get_contents(base_path('tests/gates/contagens-187-11.txt'));
        $semId = [];
        foreach ($meus as $metodo) {
            if (! str_contains($meuManifesto, $metodo)) {
                $semId[] = $metodo;
            }
        }
        $this->assertSame([], $semId, 'metodo sem id no manifesto NUNCA RODA — e lacuna, nao cobertura');

        // Os metodos do fechamento da Fase 186 (o vizinho mais provavel de colidir).
        $da186 = [];
        preg_match_all(
            '/function (test_[a-z0-9_]+)/',
            (string) file_get_contents(base_path('tests/Feature/Publicador/Aprovacao/FechamentoDaFase186Test.php')),
            $m186
        );
        $da186 = $m186[1];
        $this->assertGreaterThan(5, count($da186), 'nao li os metodos do FechamentoDaFase186Test — sonda cega');

        // DIRECAO 1 — nenhum filtro de OUTRO manifesto casa um metodo MEU.
        $colisoes = [];
        foreach ($filtros as $onde => $filtro) {
            if (str_starts_with($onde, 'contagens-187-11.txt#')) {
                continue;   // os meus proprios, de propósito
            }
            foreach ($meus as $metodo) {
                if (preg_match('/' . $filtro . '/', $metodo)) {
                    $colisoes[] = "{$onde} casa o MEU {$metodo}";
                }
            }
        }

        // DIRECAO 2 — nenhum filtro MEU casa metodo da Fase 186.
        foreach ($filtros as $onde => $filtro) {
            if (! str_starts_with($onde, 'contagens-187-11.txt#')) {
                continue;
            }
            foreach ($da186 as $metodo) {
                if (preg_match('/' . $filtro . '/', $metodo)) {
                    $colisoes[] = "{$onde} casa o da 186 {$metodo}";
                }
            }
        }
        $this->assertSame([], $colisoes, 'HOMONIMO entre manifestos: o --filter do PHPUnit e regex NAO ancorado e somaria dois arquivos');

        // ⚠️ ISCA: a conferencia de homonimo TEM de achar colisao quando ela existe. Sem isto eu
        // nao saberia se o laco acima roda. `_187_` e substring dos nomes desta classe.
        $achou = 0;
        foreach ($meus as $metodo) {
            if (preg_match('/test_perguntas_187_/', $metodo)) {
                $achou++;
            }
        }
        $this->assertGreaterThanOrEqual(3, $achou, 'a isca do homonimo nao casou nada — o detector esta cego');
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────
    // Auxiliares
    // ───────────────────────────────────────────────────────────────────────────────────────────

    private function fechamento(): string
    {
        $caminho = base_path(self::DIR_FASE . '/187-FECHAMENTO.md');
        $this->assertFileExists($caminho, 'o 187-FECHAMENTO.md nao existe');

        return (string) file_get_contents($caminho);
    }

    /**
     * ⚠️ **Leitura SEM COMENTÁRIO.** É a armadilha 2 da Fase 186, e a mesma que o briefing desta
     * sessão repete: *palavra no docblock que explica "não uso isso" faz o gate reprovar pelo
     * próprio conserto.* Cinco dos doze planos da Fase 184 caíram nela.
     */
    private function semComentariosPhp(string $caminho): string
    {
        $this->assertFileExists($caminho);
        $codigo = (string) file_get_contents($caminho);
        $codigo = preg_replace('#/\*.*?\*/#s', '', $codigo) ?? $codigo;

        return preg_replace('#^\s*//.*$#m', '', $codigo) ?? $codigo;
    }

    /** @return list<string> */
    private function metodosDesteArquivo(): array
    {
        preg_match_all(
            '/function (test_[a-z0-9_]+)/',
            (string) file_get_contents(base_path('tests/Feature/Publicador/Aprovacao/FechamentoDaFase187Test.php')),
            $m
        );

        return $m[1];
    }

    /**
     * ⚠️ **O documento declara que a conferência VISUAL é do USUÁRIO, e que NENHUM executor desta
     * fase viu tela renderizada.** Sem esta seção o fechamento afirmaria "a tela mostra X" quando o
     * que foi medido é "o HTML gerado contém X" — e a fase está **em produção desde 18/10/2026**,
     * então a diferença entre as duas frases é a diferença entre medido e suposto.
     */
    public function test_o_fechamento_187_declara_que_ninguem_viu_tela_renderizada(): void
    {
        $texto = $this->fechamento();

        $this->assertMatchesRegularExpression(
            '/NENHUM EXECUTOR .{0,40}VIU TELA RENDERIZADA/u',
            $texto,
            'falta a secao "NENHUM EXECUTOR VIU TELA RENDERIZADA" — sem ela o fechamento afirma ter visto a tela'
        );

        // Os cinco fatos do ambiente, cada um nomeado. Infratores coletados.
        $fatos = [
            'banco sem ml_token' => '/ml_token/',
            'ml_acervo_itens vazia' => '/ml_acervo_itens/',
            'pub_aprovacao_ofertas vazia' => '/pub_aprovacao_ofertas/',
            'sem navegador' => '/react-dom\/server|sem navegador/u',
            'leitura de producao bloqueada' => '/leitura de produ(ç|c)(ã|a)o/iu',
        ];
        $faltando = [];
        foreach ($fatos as $rotulo => $re) {
            if (! preg_match($re, $texto)) {
                $faltando[] = $rotulo;
            }
        }
        $this->assertSame([], $faltando, 'fato do ambiente nao declarado na secao de tela renderizada');

        // ⚠️ A conferencia visual do usuario e PENDENCIA NOMEADA, com a conta.
        $this->assertMatchesRegularExpression(
            '/conta 459/',
            $texto,
            'o roteiro da conferencia visual na conta 459 nao esta no fechamento'
        );
    }

    /**
     * ⚠️ **A fase APROVA; a escrita no Mercado Livre é da Fase 193.** A fronteira tem de estar
     * declarada no fechamento, porque é a tensão que o `ROADMAP.md` já registra: o **D-11** prova
     * que a LISTA não escreve, e a FILA escreve — e sem a fronteira escrita o primeiro executor da
     * Fase 188 lê contradição.
     */
    public function test_o_fechamento_187_declara_a_fronteira_de_escrita_no_ml(): void
    {
        $texto = $this->fechamento();

        $this->assertMatchesRegularExpression(
            '/Fase 193/',
            $texto,
            'o fechamento nao nomeia a Fase 193 como dona da escrita no Mercado Livre'
        );
        $this->assertMatchesRegularExpression(
            '/Fase 188/',
            $texto,
            'o fechamento nao nomeia a Fase 188 — e `CascaDeModal.jsx` tem de ser REUSADA, nunca recriada'
        );
        $this->assertMatchesRegularExpression(
            '/Fase 194/',
            $texto,
            'o fechamento nao nomeia a Fase 194 — e a decisao 17 (mlb_empresa_id nulo) e dela'
        );

        // ⚠️ ISCA DO RECORTE: a lista constante desta classe nao inclui nenhum arquivo do Creative
        // Engine nem do MCP — e e por isso que este arquivo NAO repete o gate que barrou um deploy
        // na Fase 186 (varredura de `app/` inteiro casando `'andamento'` do MCP da Shopee).
        $forade = array_filter(
            array_merge(self::JSX_DA_FASE, self::PHP_DA_FASE),
            static fn (string $c): bool => str_contains($c, 'Creative')
                || str_contains($c, 'app/Mcp')
                || str_contains($c, 'Criativos'),
        );
        $this->assertSame([], array_values($forade), 'a lista constante da fase invadiu territorio alheio');
        $this->assertFileExists(base_path('app/Mcp/Shopee/LeitorShopee.php'), 'o arquivo do MCP da Shopee mudou de lugar — reconferir o recorte');
    }
}
