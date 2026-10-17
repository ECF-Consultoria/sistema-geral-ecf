<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoDefeito;
use App\Models\PubAprovacaoFidelidade;
use App\Models\PubAprovacaoOferta;
use App\Services\Publicador\Aprovacao\VocabularioDoPortao;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 186, plano 09 — OS GATES TRANSVERSAIS QUE SÓ O FECHAMENTO PODE FECHAR.
 *
 * ⚠️⚠️ **A PERGUNTA DE GATE-01, RESPONDIDA UMA VEZ PARA O ARQUIVO E DE NOVO EM CADA MÉTODO:
 * "o que precisaria estar errado no mundo para este gate falhar?"**
 *
 * Para o arquivo inteiro: **alguém acrescentar, em QUALQUER das Fases 187-194, uma peça que
 * atravessa a fronteira que esta milestone inteira existe para manter** — um estado de espera de
 * autorização, um segundo clique antes de publicar, um rótulo do Mercado Livre dentro do portão,
 * uma chave inglesa indo à tela, um segundo escritor da coluna `andamento`, ou uma lista PHP que
 * silenciosamente deixa de casar com o módulo `.jsx` que a espelha.
 *
 * ⚠️ **É por isso que estes gates moram AQUI, no arquivo transversal, e não em cada plano.** Gate
 * recortado a um plano **morre com o plano**: o recorte "neste plano" não protege os futuros, e
 * foi exatamente assim que a lista de três nomes do plano 186-05 deixou `excecaoPendente` e
 * `aguardandoGestor` passarem. Este arquivo varre a **ÁREA INTEIRA** e segue valendo nas fases
 * seguintes.
 *
 * ═══ ⚠️ AS ONZE ARMADILHAS MEDIDAS NESTA FASE, E O QUE CADA UMA IMPÔS AQUI ══════════════════════
 *
 *  1. **Teste em arquivo sem id no manifesto NUNCA RODA** ⇒ todo método deste arquivo tem id em
 *     `tests/gates/contagens-186-09.txt`, e {@see self::test_os_nove_manifestos_nao_tem_homonimo_que_some_dois_arquivos}
 *     prova que nenhum `--filter` dos nove manifestos soma dois arquivos.
 *  2. **Palavra no DOCBLOCK que explica "não uso isso" faz o gate reprovar pelo próprio conserto**
 *     ⇒ as sondas de ausência leem o código **SEM COMENTÁRIO** ({@see self::semComentariosPhp()},
 *     {@see self::semComentariosJs()}), e há isca provando que o leitor **tira comentário E
 *     PRESERVA código**. Medido nesta fase: `grep -rnE "(duration|delay|ease)-\["` em
 *     `aprovacao/` casa **2 comentários** que avisam *"NÃO escrever `duration-[Nms]`"* — o gate
 *     cru reprovaria pela advertência contra o defeito.
 *  3. **Regex que não casa o que deveria é gate vazio** ⇒ **toda** sonda deste arquivo é testada
 *     contra um caso que **DEVE** casar, no mesmo método.
 *  4. **Gate que roda sobre ZERO casos não prova nada** ⇒ todo laço afirma primeiro que a
 *     contagem de casos é **maior que zero**, e o número está escrito.
 *  5. **Contagem que depende de QUANTOS ARQUIVOS EXISTEM é frágil** (três gates caíram nisso
 *     nesta fase) ⇒ aqui os infratores são **coletados numa lista** e a asserção é
 *     `assertSame([], $infratores)` — contagem fixa, imune a arquivo novo.
 *  6. **Asserção vazia quando escrita que vira falso positivo depois** ⇒ nenhuma negativa deste
 *     arquivo proíbe o que o desenho EXIGE; cada uma nomeia o que seria o defeito.
 *  7. **Sonda cujo silêncio depende de UMA LETRA é sorte** (`aprovad` não casa `aprovação`) ⇒ as
 *     sondas de forma ancoram no **SUJEITO** e trazem as variantes acentuadas.
 *  8. **Relação Eloquent com nome de propriedade do `Model` erra CALADO** ⇒ este arquivo não cria
 *     relação; o gate daquele risco é o `colisao-com-eloquent` do plano 186-06.
 *  9. **Nome de guarda genérico COLIDE entre planos e o `--filter` SOMA** ⇒ todo método aqui leva
 *     o sufixo/prefixo do fechamento, e o gate do item 1 mede a colisão nos nove manifestos.
 * 10. **Gate com range ABERTO cuja isca é "ninguém mais toca neste arquivo" tem validade de UM
 *     PLANO** ⇒ este arquivo **não usa range de git**; a prova de território por range vive no
 *     `186-FECHAMENTO.md`, que é medição datada, não gate perene.
 * 11. **`decimal(p,s)` não é legível no SQLite e o Eloquent não lê o default do banco** ⇒ sem
 *     relação com este arquivo; medido pelos gates `defaults-em-memoria` e `default-do-modelo`.
 *
 * ⚠️ **NENHUM MÉTODO DESTE ARQUIVO TOCA O BANCO.** São todos leitura de arquivo e de constante —
 * logo não há `RefreshDatabase`, e nenhuma asserção deste arquivo pode ser satisfeita por tabela
 * vazia. **Isso é deliberado:** a verificação de 14/10 reprovou uma asserção que exigia
 * `pub_imagem_referencias` com "0 linhas", que é **vazio por construção** com `RefreshDatabase`.
 * A prova certa de "esta fase não escreve" é **ausência de ESCRITA no código**, e é o que
 * {@see self::test_as_quatro_estruturas_nao_preenchidas_nao_tem_escrita_com_isca_de_dois_lados}
 * mede, com isca nos dois lados.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class FechamentoDaFase186Test extends TestCase
{
    /**
     * ⚠️ **LISTA CONSTANTE, NÃO GLOB** — e a razão é a armadilha 5 desta fase.
     *
     * Três gates desta fase tinham contagem de asserções proporcional a
     * `glob(app/Services/Publicador/Aprovacao/*.php)`, e **todo arquivo novo no portão movia o
     * número** — o que obriga a remedir o manifesto por motivo que não é mudança de comportamento.
     * Aqui a lista é fixa e {@see self::test_a_lista_constante_do_fechamento_tem_cada_entrada_no_disco}
     * prova que cada entrada **existe** (caminho fantasma já produziu seis "territórios intocados"
     * falsos neste projeto).
     *
     * ⚠️ Arquivo novo no portão **NÃO** entra aqui sozinho: entrar é ato deliberado de quem o
     * criou, e é esse o preço de não ter contagem frágil. O gate `unica-porta` do plano 186-04
     * (que **é** por glob, de propósito) continua pegando o arquivo novo pela camada dele.
     */
    private const PRODUCAO_PHP_DO_PORTAO = [
        'app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php',
        'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
        'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php',
        'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
        'app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php',
        'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
        'app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php',
        'app/Services/Publicador/Aprovacao/LinhaFinanceira.php',
        'app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php',
        'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
        'app/Models/PubAprovacaoAjuste.php',
        'app/Models/PubAprovacaoComposicao.php',
        'app/Models/PubAprovacaoConjunto.php',
        'app/Models/PubAprovacaoDefeito.php',
        'app/Models/PubAprovacaoEvento.php',
        'app/Models/PubAprovacaoExcecao.php',
        'app/Models/PubAprovacaoFidelidade.php',
        'app/Models/PubAprovacaoFrete.php',
        'app/Models/PubAprovacaoImagem.php',
        'app/Models/PubAprovacaoImagemAlerta.php',
        'app/Models/PubAprovacaoOferta.php',
        'app/Models/PubImagemReferencia.php',
    ];

    /** Os três módulos `.jsx` que o plano 186-02 abriu em `aprovacao/`. */
    private const PRODUCAO_JSX_DO_PORTAO = [
        'resources/js/Components/Mlb/Publicador/aprovacao/SeloDeAndamentoInterno.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx',
        'resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx',
    ];

    /** As 6 migrations por assunto (MIG-07), em ordem de carimbo. */
    private const MIGRATIONS_DO_PORTAO = [
        'database/migrations/2026_10_15_100000_create_pub_aprovacao_conjuntos_tables.php',
        'database/migrations/2026_10_15_100100_create_pub_aprovacao_fidelidade_tables.php',
        'database/migrations/2026_10_15_100200_create_pub_aprovacao_ofertas_table.php',
        'database/migrations/2026_10_15_100300_create_pub_aprovacao_ajustes_tables.php',
        'database/migrations/2026_10_15_100400_create_pub_aprovacao_financeiro_tables.php',
        'database/migrations/2026_10_15_100500_create_pub_imagem_referencias_table.php',
    ];

    /**
     * As **4** estruturas criadas e **não preenchidas** nesta fase, com a **fase dona** e o
     * **modelo** de cada uma. É a tabela do `<interfaces>` do plano, virada em dado conferível.
     *
     * ⚠️ O destino é aceito em **qualquer artefato versionado** (docblock da migration, docblock
     * do modelo, ou `186-FECHAMENTO.md`) — e isso é **monótono de propósito**: acrescentar a
     * declaração num segundo lugar **mantém o gate verde**. Gate que reprova quando alguém faz o
     * trabalho certo é o anti-padrão medido nesta fase ("o gate que reprova pelo próprio
     * conserto"), e seria um convite a afrouxá-lo.
     */
    private const NAO_PREENCHIDAS = [
        'pub_aprovacao_fidelidade' => ['fase' => 'Fase 191', 'modelo' => 'app/Models/PubAprovacaoFidelidade.php'],
        'pub_aprovacao_defeitos' => ['fase' => 'Fase 191', 'modelo' => 'app/Models/PubAprovacaoDefeito.php'],
        'pub_aprovacao_imagem_alertas' => ['fase' => 'Fase 192', 'modelo' => 'app/Models/PubAprovacaoImagemAlerta.php'],
        'pub_imagem_referencias' => ['fase' => 'Fase 192', 'modelo' => 'app/Models/PubImagemReferencia.php'],
    ];

    /** Onde o destino pode estar declarado. ⚠️ `test -e` em cada um antes de acreditar em vazio. */
    private const ARTEFATOS_DE_DESTINO = [
        '.planning/phases/186-o-portao-no-servidor-vocabulario-estados-bloqueios-financeiro/186-FECHAMENTO.md',
        '.planning/ROADMAP.md',
    ];

    /**
     * As chaves do protótipo que são **inglesas de verdade** e por isso **jargão proibido como
     * TEXTO DE TELA** (VOC-06). Elas seguem sendo **chave de dado legítima** nos mapas
     * `*_DO_PROTOTIPO`, que são de conferência — e é essa distinção que o gate (c) faz.
     */
    private const JARGAO_INGLES_PROIBIDO_COMO_TEXTO = [
        'awaiting', 'ecf_review', 'changes', 'photos', 'nophoto', 'freight', 'loss', 'margin',
        'change', 'adjust', 'title', 'conf', 'est', 'miss', 'offer', 'set', 'product',
        'deform', 'dup', 'prep', 'alert', 'failed',
    ];

    /**
     * ⚠️⚠️ **AS 4 CHAVES DO PROTÓTIPO QUE JÁ SÃO PALAVRA pt-BR — EXCLUSÃO DECLARADA, MEDIDA.**
     *
     * ⚠️ **Esta exclusão nasceu de o gate (c) REPROVAR O DESENHO CORRETO na primeira redação.**
     * Medido em 15/10/2026: com as 26 chaves varridas de uma vez, o gate acusou
     * `DEFEITOS[texto] = "Texto ilegível"`, `DEFEITOS[recorte] = "Recorte ruim"` e
     * `DEFEITOS[inventado] = "Elemento inventado pela IA"` — que são **exatamente os rótulos
     * pt-BR que VOC-06 EXIGE**. É a armadilha 6 desta fase em estado puro: *"o desenho PROÍBE
     * isso, ou eu só não vi ainda?"*.
     *
     * ➡️ `falta`, `texto`, `recorte` e `inventado` são chaves que o protótipo escreveu **já em
     * português**. Proibi-las como texto de tela proibiria "O que falta", "Texto ilegível",
     * "Recorte ruim" e "Elemento inventado pela IA" — e o conserto barato seria **afrouxar a
     * borda**, perdendo o gate inteiro. ⚠️ Elas continuam varridas **como CHAVE** pelos gates
     * dos planos 186-02 e 186-03; o que sai daqui é só a varredura do **lado do VALOR**.
     *
     * ⚠️ E há asserção POSITIVA no gate (c) provando que as quatro **aparecem** como rótulo
     * correto: se alguma sumisse, a exclusão teria deixado de ter motivo e precisaria cair.
     */
    private const CHAVES_DO_PROTOTIPO_QUE_SAO_PT_BR = ['falta', 'texto', 'recorte', 'inventado'];

    private function raiz(): string
    {
        return rtrim(base_path(), '/\\');
    }

    private function ler(string $relativo): string
    {
        $abs = $this->raiz().'/'.$relativo;
        $this->assertFileExists($abs, "ERRO DO GATE, não território intocado: {$relativo} não existe. Caminho fantasma já produziu seis 'vazios' falsos neste projeto.");

        return (string) file_get_contents($abs);
    }

    /**
     * O código PHP **sem comentário**, pelo tokenizador — nunca por regex.
     *
     * ⚠️ A prosa pt-BR deste projeto cita os próprios literais proibidos para explicar que **não**
     * os usa. Um `grep` cru reprova pelo comentário que EXPLICA o acerto, e foi o que aconteceu em
     * 5 dos 12 planos da Fase 184.
     */
    private function semComentariosPhp(string $conteudo): string
    {
        $fora = '';
        foreach (token_get_all($conteudo) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $fora .= $t[1];

                continue;
            }
            $fora .= $t;
        }

        return $fora;
    }

    /**
     * O código JS/JSX **sem comentário**.
     *
     * ⚠️ Não há tokenizador de JS no PHP, então é remoção por forma — e por isso há **isca**
     * ({@see self::test_os_dois_leitores_tiram_comentario_e_preservam_codigo_com_isca}) provando
     * que ela tira comentário **e preserva código**, inclusive um `https://` (cujo `//` não é
     * comentário) e uma string que **contém** `//`.
     */
    private function semComentariosJs(string $conteudo): string
    {
        // Blocos /* … */ primeiro (DOTALL, não-guloso).
        $sem = (string) preg_replace('#/\*.*?\*/#s', '', $conteudo);

        // Linha a linha: corta do `//` ao fim, mas só quando ele não está dentro de string
        // nem logo depois de `:` (o caso `https://`). A contagem de aspas antes do `//` decide.
        $saida = [];
        foreach (explode("\n", $sem) as $linha) {
            $pos = 0;
            $corte = null;
            while (($p = strpos($linha, '//', $pos)) !== false) {
                $antes = substr($linha, 0, $p);
                $simples = substr_count($antes, "'") - substr_count($antes, "\\'");
                $duplas = substr_count($antes, '"');
                $cravo = substr_count($antes, '`');
                $dentroDeString = ($simples % 2 === 1) || ($duplas % 2 === 1) || ($cravo % 2 === 1);
                $depoisDeDoisPontos = $p > 0 && $linha[$p - 1] === ':';
                if (! $dentroDeString && ! $depoisDeDoisPontos) {
                    $corte = $p;
                    break;
                }
                $pos = $p + 2;
            }
            $saida[] = $corte === null ? $linha : substr($linha, 0, $corte);
        }

        return implode("\n", $saida);
    }

    /**
     * Lê um mapa `export const NOME = { chave: 'valor', … }` de um módulo `.jsx`.
     *
     * ⚠️ **LIDO, nunca recopiado.** Recopiar as duas listas no teste cria **duas verdades livres
     * para divergir**, e a que divergisse seria a que a pessoa lê na tela.
     *
     * @return array<string,string>
     */
    private function mapaPlanoDoJsx(string $conteudo, string $nome): array
    {
        $this->assertMatchesRegularExpression(
            '/export const '.preg_quote($nome, '/').'\s*=\s*\{/',
            $conteudo,
            "ERRO DO GATE: o módulo não declara `export const {$nome} = {`. A sonda está cega, não há sincronia provada."
        );

        $i = strpos($conteudo, 'export const '.$nome);
        $abre = strpos($conteudo, '{', (int) $i);
        $fecha = strpos($conteudo, "\n};", (int) $abre);
        $corpo = substr($conteudo, (int) $abre, (int) $fecha - (int) $abre);

        $mapa = [];
        if (preg_match_all("/^\s{4}([A-Za-z_][A-Za-z0-9_]*)\s*:\s*'((?:[^'\\\\]|\\\\.)*)'\s*,/m", $corpo, $m, PREG_SET_ORDER)) {
            foreach ($m as $par) {
                $mapa[$par[1]] = str_replace("\\'", "'", $par[2]);
            }
        }

        return $mapa;
    }

    /**
     * Lê um mapa aninhado `export const NOME = { chave: { campo: 'valor', … }, … }`.
     *
     * @return array<string,array<string,string>>
     */
    private function mapaAninhadoDoJsx(string $conteudo, string $nome): array
    {
        $this->assertStringContainsString(
            'export const '.$nome.' = {',
            $conteudo,
            "ERRO DO GATE: `export const {$nome} = {` não está no módulo. Sonda cega."
        );

        $i = strpos($conteudo, 'export const '.$nome);
        $fecha = strpos($conteudo, "\n};", (int) $i);
        $corpo = substr($conteudo, (int) $i, (int) $fecha - (int) $i);

        $mapa = [];
        $chaveAtual = null;
        foreach (explode("\n", $this->semComentariosJs($corpo)) as $linha) {
            if (preg_match('/^\s{4}([A-Za-z_][A-Za-z0-9_]*)\s*:\s*\{\s*$/', $linha, $m)) {
                $chaveAtual = $m[1];
                $mapa[$chaveAtual] = [];

                continue;
            }
            if ($chaveAtual !== null && preg_match("/^\s{8}([A-Za-z_][A-Za-z0-9_]*)\s*:\s*'((?:[^'\\\\]|\\\\.)*)'/", $linha, $m)) {
                $mapa[$chaveAtual][$m[1]] = str_replace("\\'", "'", $m[2]);
            }
        }

        return $mapa;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (0) A LISTA CONSTANTE — cada entrada existe. ⚠️ Antes de qualquer outro gate deste arquivo.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** alguém renomear ou mover um arquivo do
     * portão e os 8 gates deste arquivo passarem a varrer **o nada**, reportando ausência perfeita
     * — o "caminho fantasma" que já produziu **seis** territórios intocados falsos neste projeto.
     *
     * @group phase186
     */
    public function test_a_lista_constante_do_fechamento_tem_cada_entrada_no_disco(): void
    {
        $todos = array_merge(
            self::PRODUCAO_PHP_DO_PORTAO,
            self::PRODUCAO_JSX_DO_PORTAO,
            self::MIGRATIONS_DO_PORTAO,
            self::ARTEFATOS_DE_DESTINO,
            array_column(self::NAO_PREENCHIDAS, 'modelo'),
        );

        $this->assertGreaterThan(0, count($todos), 'gate sobre ZERO casos não prova nada');
        $this->assertSame(22, count(self::PRODUCAO_PHP_DO_PORTAO), 'a lista PHP do portão tem 22 entradas MEDIDAS em 15/10/2026');
        $this->assertSame(3, count(self::PRODUCAO_JSX_DO_PORTAO), 'os 3 módulos `.jsx` do plano 186-02');
        $this->assertSame(6, count(self::MIGRATIONS_DO_PORTAO), 'as 6 migrations por assunto (MIG-07)');

        $fantasmas = [];
        foreach ($todos as $rel) {
            if (! file_exists($this->raiz().'/'.$rel)) {
                $fantasmas[] = $rel;
            }
        }
        $this->assertSame([], $fantasmas, 'caminho fantasma é ERRO DO GATE, nunca "território intocado"');

        // ISCA: um caminho que não existe TEM de ser detectado como fantasma. Sem isto o laço
        // acima poderia estar varrendo o nada e saindo verde.
        $this->assertFileDoesNotExist(
            $this->raiz().'/app/Services/Publicador/Aprovacao/NaoExiste9z7Service.php',
            'ISCA quebrada: o caminho sintético passou a existir, escolha outro'
        );
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** um dos dois leitores de comentário parar
     * de **preservar código** (e então todos os gates de ausência deste arquivo passariam por
     * varrer string vazia) ou parar de **tirar comentário** (e então eles reprovariam pelo
     * docblock que EXPLICA o acerto — o padrão de 5 dos 12 planos da Fase 184).
     *
     * @group phase186
     */
    public function test_os_dois_leitores_tiram_comentario_e_preservam_codigo_com_isca(): void
    {
        // ── PHP ──────────────────────────────────────────────────────────────────────────────
        $php = <<<'FIM'
        <?php
        /** @see duration-[200ms] no docblock, que é só advertência */
        $vivo = 'duration-200';
        // duration-[300ms] em comentário de linha
        $outro = 42; # duration-[400ms] em comentário de hash
        FIM;
        $limpoPhp = $this->semComentariosPhp($php);
        $this->assertStringNotContainsString('duration-[200ms]', $limpoPhp, 'o tokenizador não tirou o DOC_COMMENT');
        $this->assertStringNotContainsString('duration-[300ms]', $limpoPhp, 'o tokenizador não tirou o comentário de linha');
        $this->assertStringNotContainsString('duration-[400ms]', $limpoPhp, 'o tokenizador não tirou o comentário de hash');
        $this->assertStringContainsString("'duration-200'", $limpoPhp, 'o tokenizador COMEU O CÓDIGO — todo gate de ausência deste arquivo viraria vazio');
        $this->assertStringContainsString('42', $limpoPhp, 'o tokenizador comeu o código');

        // ── JS ───────────────────────────────────────────────────────────────────────────────
        $js = <<<'FIM'
        /* duration-[200ms] em bloco */
        export const A = 'duration-200';
        // duration-[300ms] em linha
        export const URL = 'https://exemplo.test/x';
        export const BARRAS = 'a//b';
        FIM;
        $limpoJs = $this->semComentariosJs($js);
        $this->assertStringNotContainsString('duration-[200ms]', $limpoJs, 'o leitor de JS não tirou o bloco');
        $this->assertStringNotContainsString('duration-[300ms]', $limpoJs, 'o leitor de JS não tirou o comentário de linha');
        $this->assertStringContainsString("'duration-200'", $limpoJs, 'o leitor de JS COMEU O CÓDIGO');
        $this->assertStringContainsString('https://exemplo.test/x', $limpoJs, 'o leitor de JS tratou `//` de URL como comentário');
        $this->assertStringContainsString("'a//b'", $limpoJs, 'o leitor de JS tratou `//` dentro de string como comentário');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (a) DESTINO DE CADA ESTRUTURA — PUB-10, "nada sem destino"
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** uma das 12 estruturas de dado desta fase
     * ficar **criada e esquecida** — tabela no banco de produção que ninguém escreve e ninguém
     * sabe quem vai escrever (T-186-42). Nas Fases 177-180 foi **no fechamento** que apareceram as
     * divergências que ninguém mais viu, e estrutura órfã é a mais silenciosa delas: ela não
     * quebra nada, só fica lá.
     *
     * ⚠️ **ISCA DE FASE INVENTADA:** uma "Fase 999" **não pode** contar como declaração. Sem ela o
     * gate poderia estar casando qualquer coisa que pareça o nome de uma fase.
     *
     * @group phase186
     */
    public function test_destino_das_quatro_estruturas_nao_preenchidas_esta_declarado_com_isca_de_fase_inventada(): void
    {
        $this->assertSame(4, count(self::NAO_PREENCHIDAS), 'são 4 estruturas não preenchidas — tabela do <interfaces> do plano');

        $corpus = '';
        foreach (array_merge(self::MIGRATIONS_DO_PORTAO, self::ARTEFATOS_DE_DESTINO, array_column(self::NAO_PREENCHIDAS, 'modelo')) as $rel) {
            $corpus .= "\n".$this->ler($rel);
        }
        $this->assertGreaterThan(10000, strlen($corpus), 'gate sobre corpus vazio não prova nada');

        $semDestino = [];
        foreach (self::NAO_PREENCHIDAS as $tabela => $dados) {
            // Exige a tabela E a fase dona no MESMO corpus de artefatos versionados.
            if (! str_contains($corpus, $tabela) || ! str_contains($corpus, $dados['fase'])) {
                $semDestino[] = $tabela.' => '.$dados['fase'];
            }
        }
        $this->assertSame([], $semDestino, 'estrutura criada sem fase dona declarada em artefato versionado (PUB-10 / T-186-42)');

        // ISCA: a fase inventada NÃO pode estar declarada em lugar nenhum. Se este assert
        // falhasse, a sonda acima estaria casando texto que não é nome de fase.
        $this->assertStringNotContainsString(
            'Fase 999',
            $corpus,
            'ISCA quebrada: "Fase 999" aparece no corpus — a sonda de fase dona não distingue fase real de inventada'
        );

        // ISCA DE DOIS LADOS: a fase dona de uma estrutura que ESTÁ declarada tem de ser ACHADA
        // pela mesma sonda. `pub_imagem_referencias` declara Fase 192/194 no docblock do modelo.
        $modelo = $this->ler('app/Models/PubImagemReferencia.php');
        $this->assertStringContainsString('Fase 192', $modelo, 'ISCA quebrada: a declaração que EXISTE não é achada pela sonda');
        $this->assertStringContainsString('pub_imagem_referencias', $modelo, 'ISCA quebrada: o nome da tabela não é achado no modelo dela');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** alguém começar a escrever uma das 4
     * estruturas **nesta fase**, sem a tela que a lê (Fases 190-192) — dado nascendo sem ninguém
     * para conferir, que é a definição de "nasce decorativo" (D4).
     *
     * ⚠️ **Por que NÃO se mede por "0 linhas na tabela":** com `RefreshDatabase` a tabela está
     * vazia **por construção**, e o assert passaria sem provar nada. Foi um achado da verificação
     * de 14/10 deste próprio plano. A prova certa é **ausência de ESCRITA no código**.
     *
     * @group phase186
     */
    public function test_as_quatro_estruturas_nao_preenchidas_nao_tem_escrita_com_isca_de_dois_lados(): void
    {
        $modelos = [
            'PubAprovacaoFidelidade', 'PubAprovacaoDefeito', 'PubAprovacaoImagemAlerta', 'PubImagemReferencia',
        ];
        $this->assertSame(4, count($modelos));

        // Sonda de ESCRITA: o modelo seguido de `::` e um verbo de persistência do Eloquent.
        $verbos = 'create|insert|updateOrCreate|firstOrCreate|upsert|forceCreate|insertGetId';

        $escritores = [];
        $varridos = 0;
        foreach ($this->todosOsPhpDeApp() as $rel => $conteudo) {
            $varridos++;
            $limpo = $this->semComentariosPhp($conteudo);
            foreach ($modelos as $m) {
                if (preg_match('/\b'.$m.'::(?:'.$verbos.')\s*\(/', $limpo)) {
                    $escritores[] = $rel.' escreve '.$m;
                }
            }
        }
        $this->assertGreaterThan(200, $varridos, 'o laço varreu poucos arquivos de app/ — gate sobre zero casos');
        $this->assertSame([], $escritores, 'DADO-15 e as 4 estruturas da Fase 191/192 não são escritas por esta fase');

        // ⚠️ ISCA DE DOIS LADOS: a MESMA sonda tem de ACHAR uma escrita que EXISTE. Sem isto o
        // laço acima poderia estar com a regex quebrada e saindo verde por nunca casar nada.
        $sintetico = 'PubAprovacaoFidelidade::create([\'criterio\' => \'cor\']);';
        $this->assertMatchesRegularExpression(
            '/\bPubAprovacaoFidelidade::(?:'.$verbos.')\s*\(/',
            $sintetico,
            'ISCA quebrada: a sonda de escrita não acha uma escrita evidente — o gate é vazio'
        );

        // ISCA do outro lado: um modelo que ESTA fase escreve tem de ser achado como escritor.
        $achouEscritorReal = false;
        foreach ($this->todosOsPhpDeApp() as $conteudo) {
            if (preg_match('/\bPubAprovacaoEvento::(?:'.$verbos.')\s*\(/', $this->semComentariosPhp($conteudo))) {
                $achouEscritorReal = true;
                break;
            }
        }
        $this->assertTrue(
            $achouEscritorReal,
            'ISCA quebrada: `PubAprovacaoEvento` É escrito nesta fase (HistoricoDaOfertaService) e a sonda não achou — ela está cega'
        );
    }

    /** @return array<string,string> caminho relativo => conteúdo */
    private function todosOsPhpDeApp(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $raiz = $this->raiz();
        $cache = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz.'/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($raiz) + 1));
                $cache[$rel] = (string) file_get_contents($f->getPathname());
            }
        }

        return $cache;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (b) OS DOIS EIXOS — AND-02 / critério 3, nas DUAS direções
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o andamento interno vazar para a lista de
     * Publicações (e o cliente ou o operador ver "Em revisão pela ECF" como se fosse estado do
     * anúncio no ar), ou um rótulo do Mercado Livre entrar no portão (e alguém aprovar lendo
     * "Pausada" como se fosse etapa da aprovação). São eixos **diferentes** — D2 da milestone —, e
     * misturá-los é o defeito que o rename do plano 186-01 existe para prevenir.
     *
     * ⚠️ **ISCA NAS DUAS DIREÇÕES:** um literal de cada eixo que **EXISTE** no lado certo tem de
     * ser **ACHADO** pela mesma sonda. Sem isso, as duas negativas poderiam estar lendo o nada.
     *
     * @group phase186
     */
    public function test_os_dois_eixos_nao_se_misturam_na_area_inteira_com_isca_nas_duas_direcoes(): void
    {
        // Rótulos do eixo do MERCADO LIVRE (a lista de Publicações).
        $doMercadoLivre = ['Em análise pelo Mercado Livre', 'Em análise pelo ML', 'Pausadas', 'Encerradas', 'Com erro'];
        // Rótulos do eixo de ANDAMENTO INTERNO (o portão).
        $doAndamentoInterno = ['Aguardando aprovação', 'Em revisão pela ECF', 'Ajustes solicitados'];

        $this->assertSame(5, count($doMercadoLivre));
        $this->assertSame(3, count($doAndamentoInterno));

        // ── Direção 1: nenhum rótulo do ML dentro do portão ──────────────────────────────────
        $vazamentos = [];
        $varridos = 0;
        foreach (self::PRODUCAO_PHP_DO_PORTAO as $rel) {
            $varridos++;
            $limpo = $this->semComentariosPhp($this->ler($rel));
            foreach ($doMercadoLivre as $r) {
                if (str_contains($limpo, $r)) {
                    $vazamentos[] = $rel.' traz o rótulo do ML "'.$r.'"';
                }
            }
        }
        $this->assertSame(22, $varridos, 'o laço tem de varrer os 22 arquivos PHP do portão');
        $this->assertSame([], $vazamentos, 'AND-02: rótulo do Mercado Livre dentro do portão mistura os dois eixos');

        // ── Direção 2: nenhum rótulo de andamento interno no que serve a LISTA ───────────────
        $queServemALista = [
            'app/Services/Publicador/AcervoTriagemService.php',
            'app/Services/Publicador/PublicacoesDaListaService.php',
            'resources/js/Components/Mlb/Publicador/visaogeral/acoes.js',
        ];
        $vazamentos2 = [];
        foreach ($queServemALista as $rel) {
            $conteudo = $this->ler($rel);
            $limpo = str_ends_with($rel, '.php') ? $this->semComentariosPhp($conteudo) : $this->semComentariosJs($conteudo);
            foreach ($doAndamentoInterno as $r) {
                if (str_contains($limpo, $r)) {
                    $vazamentos2[] = $rel.' traz o rótulo interno "'.$r.'"';
                }
            }
        }
        $this->assertSame(3, count($queServemALista));
        $this->assertSame([], $vazamentos2, 'AND-02: andamento interno não aparece no que serve a lista de Publicações');

        // ── ISCA direção 1: o rótulo INTERNO existe no portão e é achado ─────────────────────
        $oferta = $this->semComentariosPhp($this->ler('app/Models/PubAprovacaoOferta.php'));
        $this->assertStringContainsString('Em revisão pela ECF', $oferta, 'ISCA quebrada: o rótulo interno não é achado onde ELE MORA — a sonda está cega');

        // ── ISCA direção 2: o rótulo do ML existe na lista e é achado ────────────────────────
        $acoes = $this->semComentariosJs($this->ler('resources/js/Components/Mlb/Publicador/visaogeral/acoes.js'));
        $this->assertStringContainsString('Em análise pelo Mercado Livre', $acoes, 'ISCA quebrada: o rótulo do ML não é achado onde ELE MORA — a sonda está cega');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (c) JARGÃO — VOC-06. A distinção CHAVE × TEXTO é o gate.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** uma chave inglesa do protótipo escapar para
     * a **tela** — a pessoa lendo `nophoto` ou `margin` em vez de "sem fotos" / "Margem abaixo do
     * mínimo". A regra-mestra do projeto é explícita: *"se o termo não é auto-explicativo,
     * simplificar, explicar ou remover"*.
     *
     * ⚠️⚠️ **O GATE DISTINGUE CHAVE DE TEXTO, e isso é a dificuldade inteira.** `deform`, `dup`,
     * `falta`, `recorte`, `inventado`, `texto` **SÃO chaves de dado legítimas** de
     * {@see PubAprovacaoDefeito::DEFEITOS}; `photos`, `loss`, `margin` etc. são chaves do mapa de
     * conferência {@see VocabularioDoPortao::BLOQUEIO_DO_PROTOTIPO}. Varrê-las soltas reprovaria o
     * desenho correto. ➡️ O gate olha **só o lado do VALOR** dos mapas de rótulo, que é o que vai
     * à prop e à resposta HTTP.
     *
     * @group phase186
     */
    public function test_nenhuma_chave_inglesa_vai_a_tela_como_texto_so_o_lado_do_valor_com_isca(): void
    {
        $this->assertSame(22, count(self::JARGAO_INGLES_PROIBIDO_COMO_TEXTO), '22 chaves inglesas de verdade');
        $this->assertSame(4, count(self::CHAVES_DO_PROTOTIPO_QUE_SAO_PT_BR), '4 chaves que o protótipo já escreveu em português');
        $this->assertSame(
            26,
            count(self::JARGAO_INGLES_PROIBIDO_COMO_TEXTO) + count(self::CHAVES_DO_PROTOTIPO_QUE_SAO_PT_BR),
            'as 26 chaves do protótipo têm de estar TODAS numa das duas listas — chave fora das duas sai da medição em silêncio'
        );
        $this->assertSame(
            [],
            array_intersect(self::JARGAO_INGLES_PROIBIDO_COMO_TEXTO, self::CHAVES_DO_PROTOTIPO_QUE_SAO_PT_BR),
            'as duas listas não podem se sobrepor'
        );

        // Os mapas cujo lado do VALOR é TEXTO DE TELA. ⚠️ Lidos das constantes, não recopiados.
        $mapasDeTela = [
            'PubAprovacaoOferta::ANDAMENTOS' => PubAprovacaoOferta::ANDAMENTOS,
            'PubAprovacaoOferta::CTA' => PubAprovacaoOferta::CTA,
            'VocabularioDoPortao::ABREVIACOES' => VocabularioDoPortao::ABREVIACOES,
            'VocabularioDoPortao::ROTULOS' => VocabularioDoPortao::ROTULOS,
            'VocabularioDoPortao::CORRECOES' => VocabularioDoPortao::CORRECOES,
            'PubAprovacaoFidelidade::CRITERIOS' => PubAprovacaoFidelidade::CRITERIOS,
            'PubAprovacaoDefeito::DEFEITOS' => PubAprovacaoDefeito::DEFEITOS,
            'PubAprovacaoComposicao::ORIGENS_DA_LINHA' => PubAprovacaoComposicao::ORIGENS_DA_LINHA,
        ];
        $this->assertSame(8, count($mapasDeTela), '8 mapas de rótulo do portão');

        $infratores = [];
        $valoresVarridos = 0;
        foreach ($mapasDeTela as $nome => $mapa) {
            foreach ($mapa as $chave => $valor) {
                $valoresVarridos++;
                foreach (self::JARGAO_INGLES_PROIBIDO_COMO_TEXTO as $termo) {
                    // ⚠️ BORDA NOS DOIS LADOS: sem ela `set` casa "conjunto" em inglês dentro de
                    // qualquer palavra, e `est` casa "estimado". `\b` em UTF-8 com `/u`.
                    if (preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($termo, '/').'(?![\p{L}\p{N}_])/ui', (string) $valor)) {
                        $infratores[] = $nome.'['.$chave.'] = "'.$valor.'" traz o jargão "'.$termo.'"';
                    }
                }
            }
        }
        $this->assertGreaterThan(50, $valoresVarridos, 'gate sobre poucos valores não prova nada');
        $this->assertSame([], $infratores, 'VOC-06: chave inglesa do protótipo como TEXTO DE TELA');

        // ⚠️ ISCA: a mesma sonda, com borda, TEM de pegar o jargão num valor sintético — e TEM de
        // NÃO pegar quando o termo é só substring de palavra pt-BR legítima.
        $sonda = function (string $termo, string $valor): bool {
            return (bool) preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($termo, '/').'(?![\p{L}\p{N}_])/ui', $valor);
        };
        $this->assertTrue($sonda('margin', 'Margem baixa (margin)'), 'ISCA quebrada: a sonda não pega o jargão isolado');
        $this->assertTrue($sonda('set', 'Aprovar o set inteiro'), 'ISCA quebrada: a sonda não pega `set` com borda');
        $this->assertFalse($sonda('set', 'Conjunto de fotos'), 'ISCA quebrada: a sonda casa DENTRO de palavra pt-BR e reprovaria o desenho correto');
        $this->assertFalse($sonda('est', 'Estimado'), 'ISCA quebrada: `est` casando "Estimado" reprovaria o selo de origem correto');
        $this->assertFalse($sonda('conf', 'Fotos, preço e conteúdo conferidos'), 'ISCA quebrada: `conf` casando "conferidos" reprovaria o rodapé correto');
        $this->assertFalse($sonda('alert', 'Alerta em foto de IA'), 'ISCA quebrada: `alert` casando "Alerta" reprovaria o rótulo correto');

        // ⚠️⚠️ A ASSERÇÃO POSITIVA QUE SUSTENTA A EXCLUSÃO DAS 4 CHAVES pt-BR. Se uma delas
        // deixasse de aparecer como rótulo correto, a exclusão perderia o motivo e teria de cair
        // — e é só por isso que ela não é um buraco no gate.
        $rotulosDeDefeito = implode(' | ', PubAprovacaoDefeito::DEFEITOS);
        $this->assertStringContainsString('Texto ilegível', $rotulosDeDefeito, '`texto` é excluído porque "Texto ilegível" é o rótulo CORRETO — se ele sumiu, a exclusão perdeu o motivo');
        $this->assertStringContainsString('Recorte ruim', $rotulosDeDefeito, '`recorte` é excluído porque "Recorte ruim" é o rótulo CORRETO');
        $this->assertStringContainsString('Elemento inventado pela IA', $rotulosDeDefeito, '`inventado` é excluído porque este é o rótulo CORRETO');
        $this->assertStringContainsString('Peça ausente', $rotulosDeDefeito, '`falta` virou "Peça ausente" — o rótulo correto não traz a chave');

        // ⚠️ E a prova de que a exclusão é NECESSÁRIA: a mesma sonda, com as 4 pt-BR dentro,
        // reprovaria os rótulos corretos. Medido — não hipótese.
        $teriaReprovado = [];
        foreach (self::CHAVES_DO_PROTOTIPO_QUE_SAO_PT_BR as $termo) {
            foreach (PubAprovacaoDefeito::DEFEITOS as $chave => $valor) {
                if ($sonda($termo, (string) $valor)) {
                    $teriaReprovado[] = $termo.' em "'.$valor.'"';
                }
            }
        }
        $this->assertGreaterThanOrEqual(
            3,
            count($teriaReprovado),
            'a exclusão das 4 chaves pt-BR ficou SEM MOTIVO MEDIDO: nenhuma delas reprovaria um rótulo correto. Se isto falhar, reinclua-as em JARGAO_INGLES_PROIBIDO_COMO_TEXTO'
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (d) COR NUNCA É O ÚNICO SINAL — VOC-05
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** um estado, bloqueio ou selo de origem
     * nascer **só com cor** — invisível para quem não distingue vermelho de âmbar, e invisível em
     * print em escala de cinza. É a regra VOC-05, e ela é da milestone, não deste plano.
     *
     * ⚠️ A medida é **empate**: para cada mapa, o número de entradas com **rótulo textual** é
     * **igual** ao número com **cor**. Mapa com 8 cores e 7 rótulos tem um estado muda.
     *
     * @group phase186
     */
    public function test_cor_nunca_e_o_unico_sinal_rotulo_e_cor_empatam_em_todo_mapa(): void
    {
        $andamentoJsx = $this->ler('resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx');
        $vocabJsx = $this->ler('resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx');

        // ── ANDAMENTOS: todo estado tem `rotulo` E `dot`+`texto` (cor) ───────────────────────
        $andamentos = $this->mapaAninhadoDoJsx($andamentoJsx, 'ANDAMENTOS');
        $this->assertSame(8, count($andamentos), 'são 8 andamentos internos (AND-01)');

        $comRotulo = 0;
        $comCor = 0;
        $mudos = [];
        foreach ($andamentos as $chave => $campos) {
            $temRotulo = isset($campos['rotulo']) && trim($campos['rotulo']) !== '';
            $temCor = isset($campos['dot']) || isset($campos['texto']);
            $comRotulo += $temRotulo ? 1 : 0;
            $comCor += $temCor ? 1 : 0;
            if ($temCor && ! $temRotulo) {
                $mudos[] = $chave;
            }
        }
        $this->assertSame([], $mudos, 'VOC-05: estado com cor e SEM rótulo textual é estado mudo');
        $this->assertSame($comCor, $comRotulo, 'VOC-05: o empate rótulo × cor é a medida — divergir significa um estado só visível por cor');
        $this->assertSame(8, $comRotulo);

        // ── SELOS_DE_ORIGEM: os 3 selos têm rótulo E cor ─────────────────────────────────────
        $selos = $this->mapaAninhadoDoJsx($vocabJsx, 'SELOS_DE_ORIGEM');
        $this->assertSame(3, count($selos), 'são 3 selos de origem (VOC-04)');
        $selosMudos = [];
        $selosComCor = 0;
        foreach ($selos as $chave => $campos) {
            if (isset($campos['texto']) || isset($campos['borda'])) {
                $selosComCor++;
                if (! isset($campos['rotulo']) || trim($campos['rotulo']) === '') {
                    $selosMudos[] = $chave;
                }
            }
        }
        $this->assertSame([], $selosMudos, 'VOC-05: selo de origem com cor e sem rótulo');
        $this->assertSame(3, $selosComCor);

        // ── BLOQUEIOS: o glifo `!` acompanha a cor, nos 9 ────────────────────────────────────
        $this->assertSame('!', VocabularioDoPortao::GLIFO, 'VOC-05: o bloqueio carrega glifo junto da cor');
        $this->assertSame(9, count(VocabularioDoPortao::ABREVIACOES), '9 abreviações = 9 bloqueios com texto');
        $semTexto = [];
        foreach (VocabularioDoPortao::BLOQUEIOS_EM_ORDEM as $b) {
            $sev = VocabularioDoPortao::severidade($b);
            $abrev = VocabularioDoPortao::abreviacaoDe($b);
            if ($sev !== '' && ($abrev === null || trim($abrev) === '')) {
                $semTexto[] = $b;
            }
        }
        $this->assertSame([], $semTexto, 'VOC-05: bloqueio com severidade (cor) e sem abreviação (texto)');

        // ⚠️ ISCA: um mapa sintético com cor e SEM rótulo tem de ser pego pela mesma contagem.
        $sintetico = ['x' => ['dot' => 'bg-red-500'], 'y' => ['rotulo' => 'Y', 'dot' => 'bg-green-500']];
        $r = 0;
        $c = 0;
        foreach ($sintetico as $campos) {
            $r += (isset($campos['rotulo']) && trim($campos['rotulo']) !== '') ? 1 : 0;
            $c += isset($campos['dot']) ? 1 : 0;
        }
        $this->assertNotSame($c, $r, 'ISCA quebrada: a contagem empata num mapa com entrada MUDA — o gate (d) é vazio');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (f) A ESCRITA DE `andamento` MORA NUM LUGAR SÓ — na ÁREA INTEIRA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** um segundo lugar escrever a coluna
     * `andamento` sem passar por {@see \App\Services\Publicador\Aprovacao\AndamentoDaOfertaService},
     * e com isso **pular a validação de transição**. Numa máquina de estado cujo passo errado
     * **publica anúncio não conferido** (aprovar JÁ PUBLICA, sem desfazer), o segundo escritor é o
     * defeito mais caro possível.
     *
     * ⚠️ O gate do plano 186-04 (`unica-porta`) mede isso **dentro do diretório do portão**. Este
     * mede **`app/` inteiro**, porque o risco de amanhã é um controller, um job ou um command
     * novo — fora do diretório — escrevendo direto.
     *
     * @group phase186
     */
    public function test_a_escrita_de_andamento_mora_num_lugar_so_em_app_inteiro_com_isca(): void
    {
        // A única porta, e o único lugar onde o default da coluna é declarado.
        $porta = 'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php';
        $declaraDefault = 'app/Models/PubAprovacaoOferta.php';

        // ⚠️⚠️ OS EXPOSITORES — acrescentado pelo plano 187-08 (17/10/2026), e a exceção é
        // **PROVADA, não afirmada** (as três asserções no fim deste caso).
        //
        // `LinhaDaOfertaService` monta as 11 células da linha da fila e **EXPÕE** o andamento numa
        // chave de SAÍDA do payload — `'andamento' => ['chave' => (string) $oferta->andamento, …]`.
        // Ele **LÊ** a coluna; **não escreve nada** (o arquivo não tem `update`, `create`, `fill`,
        // `save` nem atribuição a `->andamento`).
        //
        // ⚠️ A sonda larga casa `'andamento' =>` **em qualquer array**, inclusive no de SAÍDA, e o
        // segundo filtro (referenciar o portão) casa porque o serviço recebe um
        // `PubAprovacaoOferta` por parâmetro — como tem de receber. ➡️ Era FALSO POSITIVO, da MESMA
        // família do `app/Mcp/Shopee/LeitorShopee.php` que reprovou o deploy em 15/10: *o gate é
        // mais largo que o invariante*.
        //
        // ⚠️⚠️ **O QUE NÃO FOI FEITO, e por que:** renomear a chave de saída para fugir da sonda
        // (`'estado_interno' =>`, por exemplo) seria dobrar a tela para o gate — e a régua do
        // projeto é UM VOCABULÁRIO SÓ, chaves pt-BR iguais nos dois lados (o `.jsx` lê
        // `andamento.chave`). Enfraquecer a sonda ou tirar o alcance de `app/` inteiro também está
        // fora: o valor deste gate é pegar escritor clandestino em qualquer canto.
        // ➡️ A saída é a LISTA, **com prova** — e a prova é mais forte que a exceção: para cada
        // expositor, este caso exige que ele **case a sonda larga** (senão a exceção não tem
        // sujeito) e que **NÃO contenha nenhuma forma de escrita**.
        //
        // ⚠️ `contagens-186-09.txt` foi **REMEDIDO** por causa desta mudança (ids `transversais` e
        // `andamento-um-lugar-so`). A Fase 186 está EM PRODUÇÃO: o número não foi "ajustado para
        // passar" — foi medido de novo, e as asserções novas só ACRESCENTAM prova.
        $expositores = [
            'app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php',
        ];

        // Sonda: a chave literal num array de update/create, ou a atribuição à propriedade.
        $sonda = "/'andamento'\s*=>|->andamento\s*=(?!=)/";

        // ⚠️ As formas de ESCRITA de verdade — é o que um expositor NÃO pode ter.
        $sondaDeEscrita = "/->andamento\s*=(?!=)|(?:update|updateOrCreate|create|fill|insert|forceFill)\s*\(\s*\[[^\]]*'andamento'\s*=>/s";

        $escritores = [];
        $varridos = 0;
        foreach ($this->todosOsPhpDeApp() as $rel => $conteudo) {
            $varridos++;
            if ($rel === $porta || $rel === $declaraDefault || in_array($rel, $expositores, true)) {
                continue;
            }
            $limpo = $this->semComentariosPhp($conteudo);

            // ⚠️ ESTREITADO EM 15/10/2026 PELO ORQUESTRADOR — era FALSO POSITIVO, e a causa vale
            // escrever: `andamento` é **palavra comum do português**, e a sonda varria o `app/`
            // INTEIRO. O merge da sessão paralela trouxe `app/Mcp/Shopee/LeitorShopee.php`, que usa
            // `['andamento' => 'ongoing', 'agendadas' => 'upcoming', …]` como **status de promoção
            // da Shopee** — outro domínio, uso legítimo, e o gate reprovou o deploy por isso.
            //
            // ⚠️ É a QUINTA ocorrência nesta fase da família "o gate é mais largo que o
            // invariante", e a mais perigosa delas: as outras quatro eram de CONTAGEM, esta
            // reprovava o merge de outra sessão por uma palavra do idioma.
            //
            // ➡️ O alcance de `app/` INTEIRO **fica** (é o valor do gate: um escritor clandestino
            // em qualquer canto é pego). O que entrou é o segundo filtro: só é escritor quem mexe
            // em `andamento` **E** referencia o portão — pelo MODELO ou pela TABELA, porque um
            // `DB::table('pub_aprovacao_ofertas')->update([...])` não nomeia o modelo.
            // Quem usa a palavra noutro domínio não casa, e continua livre.
            if (preg_match($sonda, $limpo) === 1 && preg_match('/PubAprovacaoOferta|pub_aprovacao_ofertas/', $limpo) === 1) {
                $escritores[] = $rel;
            }
        }
        $this->assertGreaterThan(200, $varridos, 'gate sobre poucos arquivos');
        $this->assertSame([], $escritores, 'AND-09: a escrita de `andamento` passa SEMPRE pela única porta');

        // ⚠️ ISCA 1 — o filtro novo NÃO pode deixar passar escritor de verdade: um arquivo que
        // escreve `andamento` E referencia o portão TEM de ser acusado. Sem esta isca, o segundo
        // filtro poderia estar sempre falso e o gate ficaria verde permanente.
        $clandestino = "<?php class X { public function f(\$o) { \$o->update(['andamento' => 'aprovado']); } } // PubAprovacaoOferta";
        $this->assertSame(1, preg_match($sonda, $clandestino), 'ISCA 1a: a sonda de escrita não acha a escrita.');
        $this->assertSame(1, preg_match('/PubAprovacaoOferta|pub_aprovacao_ofertas/', $clandestino), 'ISCA 1b: o filtro do portão não acha a referência ao portão.');

        // ⚠️ ISCA 2 — e o uso LEGÍTIMO da palavra noutro domínio NÃO pode ser acusado. É o caso
        // real que motivou o conserto: o status de promoção da Shopee.
        $outroDominio = "<?php class Y { const S = ['andamento' => 'ongoing', 'agendadas' => 'upcoming']; }";
        $this->assertSame(1, preg_match($sonda, $outroDominio), 'ISCA 2a: a sonda larga casa o uso de outro domínio — é por isso que o 2º filtro existe.');
        $this->assertSame(0, preg_match('/PubAprovacaoOferta|pub_aprovacao_ofertas/', $outroDominio), 'ISCA 2b: o filtro do portão casou um arquivo que não fala do portão — ele não está discriminando nada.');

        // ⚠️ ISCA 3 — o caso REAL, lido do disco: o arquivo que reprovou este gate em 15/10 usa a
        // palavra e NÃO fala do portão. Se um dia ele passar a falar, quero saber.
        $shopee = base_path('app/Mcp/Shopee/LeitorShopee.php');
        if (is_file($shopee)) {
            $conteudoShopee = $this->semComentariosPhp(file_get_contents($shopee));
            $this->assertSame(1, preg_match($sonda, $conteudoShopee), 'ISCA 3a: o caso real deixou de casar a sonda larga — o arquivo mudou, e a isca perdeu o sujeito.');
            $this->assertSame(0, preg_match('/PubAprovacaoOferta|pub_aprovacao_ofertas/', $conteudoShopee), 'ISCA 3b: o MCP da Shopee passou a referenciar o portão — isto não é mais falso positivo e precisa de análise.');
        }

        // ═══ ⚠️⚠️ A PROVA DE CADA EXPOSITOR (plano 187-08) ═══════════════════════════════════
        //
        // A exceção acima é um `continue` — e `continue` por lista é exatamente a forma de afrouxar
        // um gate sem ninguém ver. ➡️ Aqui ela é **PROVADA**, nos dois sentidos, com UMA asserção
        // por sentido coletando infratores (nunca uma por arquivo, que faria a contagem crescer
        // junto com a lista — a lição do 187-07).
        $semSujeito = [];
        $queEscrevem = [];
        foreach ($expositores as $rel) {
            $limpoExpositor = $this->semComentariosPhp($this->ler($rel));

            // (1) O expositor TEM de casar a sonda larga: se não casasse, a exceção não teria
            //     sujeito e seria linha morta abrindo um buraco para o futuro.
            if (preg_match($sonda, $limpoExpositor) !== 1) {
                $semSujeito[] = $rel;
            }

            // (2) E NÃO pode conter NENHUMA forma de escrita. É isto que distingue EXPOR de
            //     ESCREVER, e é o invariante que o gate existe para proteger.
            if (preg_match($sondaDeEscrita, $limpoExpositor) === 1) {
                $queEscrevem[] = $rel;
            }
        }
        $this->assertSame([], $semSujeito, 'expositor que não casa a sonda larga: a exceção dele é linha morta — tire da lista');
        $this->assertSame([], $queEscrevem, 'AND-09: um EXPOSITOR declarado está ESCREVENDO `andamento` — a exceção é inválida');

        // ⚠️ ISCA DA SONDA DE ESCRITA, nas duas direções. Sem ela, uma regex quebrada deixaria o
        // `continue` dos expositores passar qualquer coisa.
        $this->assertSame(1, preg_match($sondaDeEscrita, "\$o->andamento = 'aprovado';"), 'ISCA 4a: a sonda de escrita não acha a atribuição.');
        $this->assertSame(1, preg_match($sondaDeEscrita, "\$o->update(['andamento' => 'aprovado']);"), 'ISCA 4b: a sonda de escrita não acha o update.');
        $this->assertSame(0, preg_match($sondaDeEscrita, "return ['andamento' => ['chave' => (string) \$o->andamento]];"), 'ISCA 4c: a sonda de escrita acusa a chave de SAÍDA — ela não discrimina expor de escrever.');

        // ⚠️ ISCA 1: a própria porta TEM de ser achada pela sonda. Se não fosse, a sonda estaria
        // cega e o laço acima sairia verde por nunca casar nada.
        $this->assertMatchesRegularExpression(
            $sonda,
            $this->semComentariosPhp($this->ler($porta)),
            'ISCA quebrada: a sonda não acha a escrita DENTRO da única porta — ela está cega'
        );

        // ⚠️ ISCA 2: o modelo declara o default e TAMBÉM é achado — o que prova que a exclusão
        // dele acima é necessária, não decorativa.
        $this->assertMatchesRegularExpression(
            $sonda,
            $this->semComentariosPhp($this->ler($declaraDefault)),
            'ISCA quebrada: o default da coluna no modelo não é achado — a exclusão é decorativa'
        );

        // ⚠️ ISCA 3: a sonda NÃO pode casar `= 'andamento'` (uma constante com esse VALOR), que é
        // o que `Onboarding::STATUS_ANDAMENTO` e `Ppa::GRUPO_ANDAMENTO` fazem neste repositório.
        $this->assertDoesNotMatchRegularExpression(
            $sonda,
            "public const STATUS_ANDAMENTO = 'andamento';",
            'ISCA quebrada: a sonda casa constante cujo VALOR é "andamento" — reprovaria dois modelos alheios'
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (g) PHP × JSX EM SINCRONIA — e os DOIS LADOS existem
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** alguém acrescentar um estado, um critério,
     * um defeito ou um selo **em um só dos dois lados**. Não há enum compartilhado entre PHP e JS
     * neste projeto (a convenção do repositório diz isso em voz alta: *"No shared enum type
     * between PHP and JS — kept in sync manually"*), então **este é o único lugar onde a sincronia
     * é medida**. Sem ele, o servidor manda uma chave que a tela não conhece e a célula sai vazia
     * — sem erro, sem log, sem ninguém notar.
     *
     * ⚠️⚠️ **DOIS ACHADOS DA VERIFICAÇÃO DE 14/10 CONSERTADOS AQUI:**
     *  1. **As chaves comparadas têm de poder CASAR.** Na primeira redação o gate comparava as
     *     chaves **inglesas** do protótipo (lado 186-02) com as **pt-BR** do banco (lado 186-04) —
     *     duas listas que **não podem casar**, e cujo "conserto" óbvio seria afrouxar a comparação.
     *     ➡️ Resolvido pela decisão do 186-02: **um vocabulário só, pt-BR nos dois lados**; as
     *     inglesas vivem apenas nos mapas `*_DO_PROTOTIPO`, de conferência.
     *  2. **O lado PHP das abreviações EXISTE.** Elas viviam só num módulo `.jsx` e o consumidor
     *     (`resumoDoRodape()`) é PHP — sincronia sem um dos lados. ➡️ O plano 186-08 criou
     *     {@see VocabularioDoPortao} como **fonte**, e o `.jsx` espelha.
     *
     * ⚠️ **AS LISTAS SÃO LIDAS DOS DOIS ARQUIVOS, NUNCA RECOPIADAS.**
     *
     * @group phase186
     */
    public function test_php_e_jsx_em_sincronia_nas_cinco_listas_lidas_dos_dois_lados_com_isca(): void
    {
        $andamentoJsx = $this->ler('resources/js/Components/Mlb/Publicador/aprovacao/andamentoDoPortao.jsx');
        $vocabJsx = $this->ler('resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx');

        // ── 1) OS 8 ESTADOS: chaves pt-BR e rótulos, nos dois lados ──────────────────────────
        $jsAndamentos = $this->mapaAninhadoDoJsx($andamentoJsx, 'ANDAMENTOS');
        $jsRotulos = [];
        foreach ($jsAndamentos as $k => $campos) {
            $jsRotulos[$k] = $campos['rotulo'] ?? null;
        }
        $this->assertSame(8, count($jsRotulos), 'o `.jsx` tem de declarar 8 andamentos');
        $this->assertSame(8, count(PubAprovacaoOferta::ANDAMENTOS), 'o PHP tem de declarar 8 andamentos');

        // ⚠️ Igualdade de CONJUNTO de chaves (ordenada para a comparação, não para a semântica).
        $kPhp = array_keys(PubAprovacaoOferta::ANDAMENTOS);
        $kJs = array_keys($jsRotulos);
        sort($kPhp);
        sort($kJs);
        $this->assertSame($kPhp, $kJs, 'AND-01: o conjunto das 8 chaves pt-BR divergiu entre PHP e `.jsx`');

        // ⚠️ E por RÓTULO, não só por chave: chave igual com rótulo diferente é divergência que
        // só a pessoa na tela vê.
        $this->assertSame(PubAprovacaoOferta::ANDAMENTOS, array_intersect_key($jsRotulos, PubAprovacaoOferta::ANDAMENTOS), 'os 8 rótulos têm de ser espelho byte a byte');

        // ── 2) OS 7 CRITÉRIOS de fidelidade ──────────────────────────────────────────────────
        $jsCriterios = $this->mapaPlanoDoJsx($this->semComentariosJs($vocabJsx), 'CRITERIOS');
        $this->assertSame(7, count($jsCriterios), '7 critérios no `.jsx`');
        $this->assertSame(7, count(PubAprovacaoFidelidade::CRITERIOS), '7 critérios no PHP');
        $this->assertSame(PubAprovacaoFidelidade::CRITERIOS, $jsCriterios, 'DADO-07: os 7 critérios divergiram entre PHP e `.jsx`');

        // ── 3) OS 6 DEFEITOS ─────────────────────────────────────────────────────────────────
        $jsDefeitos = $this->mapaPlanoDoJsx($this->semComentariosJs($vocabJsx), 'DEFEITOS');
        $this->assertSame(6, count($jsDefeitos), '6 defeitos no `.jsx`');
        $this->assertSame(6, count(PubAprovacaoDefeito::DEFEITOS), '6 defeitos no PHP');
        $this->assertSame(PubAprovacaoDefeito::DEFEITOS, $jsDefeitos, 'DADO-08: os 6 defeitos divergiram entre PHP e `.jsx`');

        // ── 4) OS 3 SELOS DE ORIGEM ──────────────────────────────────────────────────────────
        $jsSelos = $this->mapaAninhadoDoJsx($vocabJsx, 'SELOS_DE_ORIGEM');
        $jsSelosRotulo = [];
        foreach ($jsSelos as $k => $campos) {
            $jsSelosRotulo[$k] = $campos['rotulo'] ?? null;
        }
        $this->assertSame(3, count($jsSelosRotulo), '3 selos no `.jsx`');
        $this->assertSame(PubAprovacaoComposicao::ORIGENS_DA_LINHA, $jsSelosRotulo, 'VOC-04: os 3 selos de origem divergiram entre PHP e `.jsx`');
        // ⚠️ E a classe do portão LÊ os selos do modelo, não os recopia.
        $this->assertSame(PubAprovacaoComposicao::ORIGENS_DA_LINHA, VocabularioDoPortao::selosDeOrigem(), 'o VocabularioDoPortao tem de LER os selos, não recopiá-los');

        // ── 5) AS 9 ABREVIAÇÕES e OS 8 RÓTULOS `BLK` ─────────────────────────────────────────
        $jsAbrev = $this->mapaPlanoDoJsx($this->semComentariosJs($vocabJsx), 'ABREVIACAO_DO_BLOQUEIO');
        $jsBlk = $this->mapaPlanoDoJsx($this->semComentariosJs($vocabJsx), 'ROTULO_DO_BLOQUEIO');
        $this->assertSame(9, count($jsAbrev), '9 abreviações no `.jsx` (`SHORT`)');
        $this->assertSame(8, count($jsBlk), '8 rótulos no `.jsx` (`BLK`)');
        $this->assertSame(VocabularioDoPortao::ABREVIACOES, $jsAbrev, 'BLOQ-12: as 9 abreviações divergiram entre PHP e `.jsx`');
        $this->assertSame(VocabularioDoPortao::ROTULOS, $jsBlk, 'BLOQ-12: os 8 rótulos `BLK` divergiram entre PHP e `.jsx`');

        // ── 6) AS 11 CHAVES DO PROTÓTIPO são bijetivas com as nossas ─────────────────────────
        $this->assertSame(11, count(VocabularioDoPortao::BLOQUEIO_DO_PROTOTIPO), '11 chaves de conferência (`SHORT` ∪ `BLK`)');
        $nossas = array_values(VocabularioDoPortao::BLOQUEIO_DO_PROTOTIPO);
        $uniao = array_unique(array_merge(array_keys(VocabularioDoPortao::ABREVIACOES), array_keys(VocabularioDoPortao::ROTULOS)));
        sort($nossas);
        sort($uniao);
        $this->assertSame($uniao, $nossas, 'o mapa de conferência tem de ser BIJETIVO com a união dos dois mapas');

        // ⚠️⚠️ ISCA: uma chave sintética inserida num dos lados TEM de REPROVAR a comparação.
        // Sem ela, `assertSame` entre duas leituras poderia estar comparando dois vazios.
        $forjado = $jsAbrev;
        $forjado['chave_sintetica_9z7'] = 'inventada';
        $this->assertNotSame(
            VocabularioDoPortao::ABREVIACOES,
            $forjado,
            'ISCA quebrada: a comparação não acusa chave a mais num dos lados — o gate (g) é vazio'
        );
        $forjado2 = $jsCriterios;
        unset($forjado2['cor']);
        $this->assertNotSame(
            PubAprovacaoFidelidade::CRITERIOS,
            $forjado2,
            'ISCA quebrada: a comparação não acusa chave a MENOS num dos lados'
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (g2) A AUSÊNCIA DE SEGUNDO PASSO — PUB-09 e BLOQ-09, na FORMA e na ÁREA INTEIRA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** alguém, em qualquer das Fases 187-194,
     * acrescentar um **segundo clique** antes de publicar, uma **janela de desfazer**, ou um
     * **estado de espera de autorização** — e com isso reabrir decisões que o usuário tomou e
     * **recusou explicitamente** em 14/10/2026 (decisões 3 e 5 do ROADMAP: *"aprovar JÁ PUBLICA,
     * sem segundo clique"* e *"basta REGISTRAR quem liberou"*). Até aqui as duas estavam **só em
     * prosa** nos planos.
     *
     * ⚠️ **A ÂNCORA É A FORMA, NÃO UMA LISTA DE NOMES.** A lista de três nomes do plano 186-05
     * **não era exaustiva** — `excecaoPendente` e `aguardandoGestor` passariam por ela. E o
     * recorte "neste plano" **não protege os futuros**: por isso a sonda ancora no padrão
     * *sujeito de espera + objeto de autorização* e varre a **ÁREA INTEIRA**.
     *
     * ⚠️ **E o rótulo do botão NÃO mudou:** continua "Aprovar para publicação" (mudou o
     * comportamento, não o texto) — então a sonda **não pode** reprovar a palavra `aprovar`, e há
     * isca provando que ela a **ACHA** sem reprovar.
     *
     * @group phase186
     */
    public function test_pub09_e_bloq09_a_ausencia_de_segundo_passo_na_forma_e_na_area_inteira(): void
    {
        $arquivos = array_merge(self::PRODUCAO_PHP_DO_PORTAO, self::PRODUCAO_JSX_DO_PORTAO);
        $this->assertSame(25, count($arquivos), '22 PHP + 3 JSX = a área nova inteira');

        // ── PUB-09: nada de segundo passo, desfazer ou cancelar aprovação ────────────────────
        //
        // ⚠️⚠️ **BORDA NOS DOIS LADOS, E ELA FOI MEDIDA COMO NECESSÁRIA.** Na primeira redação a
        // sonda trazia `undo` solto, e em 15/10/2026 ela acusou **três arquivos corretos**:
        // `undo` é substring de **"segundo"**, **"fundo"** e **"profundo"**, que são palavras
        // pt-BR comuns na prosa deste projeto. Era a armadilha 7 — *"sonda cujo silêncio depende
        // de uma letra é sorte"* — pelo avesso: sonda cujo RUÍDO depende de uma letra.
        // ⚠️⚠️ **A BORDA É SÓ NA ESQUERDA, E ISSO TAMBÉM FOI MEDIDO.** Com borda nos DOIS lados a
        // sonda ficou **cega para `desfazerAprovacao`** — a forma camelCase que o defeito teria de
        // verdade —, porque o `A` depois de `desfazer` é letra. Era a armadilha 7 pelo terceiro
        // lado: borda demais apaga o gate tão bem quanto borda de menos.
        // ➡️ O que gera o ruído é o que vem **ANTES** (`seg|undo`, `f|undo`, `prof|unda`); o que
        // vem **DEPOIS** é justamente o sufixo do defeito. Nenhuma palavra pt-BR **começa** com
        // `undo` nem **termina** em `desfazer`, então a borda esquerda sozinha basta — e há isca
        // nos dois sentidos provando as duas coisas.
        // ⚠️ `_` fica FORA da borda de propósito: `JANELA_DE_UNDO` tem de ser PEGO, e há isca.
        $sondaPub09 = '/(confirmar_publicacao|confirmarPublicacao|segundo_passo|segundoPasso'
            .'|(?<![\p{L}\p{N}])desfazer|(?<![\p{L}\p{N}])undo'
            .'|publicar_aprovado|publicarAprovado|cancelar_aprovacao|cancelarAprovacao)/ui';

        // ── BLOQ-09: nada de estado de espera de AUTORIZAÇÃO ────────────────────────────────
        //
        // ⚠️⚠️ **O OBJETO É `autorização`/`gestor`/`liberação` — NUNCA `aprovação`.** Na primeira
        // redação o lado direito trazia `aprovad|aprovaç`, e em 15/10/2026 ela acusou
        // `PubAprovacaoOferta` e `andamentoDoPortao.jsx` pelo rótulo **"Aguardando aprovação"** —
        // que é **o estado nº 2 do desenho**, o que a fase inteira existe para criar.
        // ⚠️ É a armadilha 6 em estado puro: *"o desenho PROÍBE isso, ou eu só não vi ainda?"*.
        // O que BLOQ-09 proíbe é esperar o **segundo aprovador** (a decisão 5 do usuário:
        // *"basta REGISTRAR quem liberou"*), não esperar a revisão da própria ECF.
        $sondaBloq09 = '/(aguardand|pendent|em[_ ]?an[aá]lise|solicit)[\p{L}\p{N}_ ]{0,24}(autoriza|gestor|liberaca|liberaç)/ui';
        $sondaBloq09Simetrica = '/(autoriza|gestor|liberaca|liberaç)[\p{L}\p{N}_ ]{0,24}(aguardand|pendent|em[_ ]?an[aá]lise)/ui';

        $infratores = [];
        $varridos = 0;
        foreach ($arquivos as $rel) {
            $varridos++;
            $conteudo = $this->ler($rel);
            $limpo = str_ends_with($rel, '.php') ? $this->semComentariosPhp($conteudo) : $this->semComentariosJs($conteudo);

            if (preg_match($sondaPub09, $limpo, $m)) {
                $infratores[] = $rel.' traz segundo passo/desfazer: "'.$m[0].'" (PUB-09)';
            }
            if (preg_match($sondaBloq09, $limpo, $m)) {
                $infratores[] = $rel.' traz estado de espera de autorização: "'.$m[0].'" (BLOQ-09)';
            }
            if (preg_match($sondaBloq09Simetrica, $limpo, $m)) {
                $infratores[] = $rel.' traz espera de autorização (forma simétrica): "'.$m[0].'" (BLOQ-09)';
            }
        }
        $this->assertSame(25, $varridos, 'o laço tem de varrer os 25 arquivos da área nova');
        $this->assertSame([], $infratores, 'PUB-09 e BLOQ-09: decisões 3 e 5 do usuário (14/10/2026) reabertas em código');

        // ⚠️ ISCA PUB-09: a sonda TEM de pegar um segundo passo sintético.
        $this->assertMatchesRegularExpression($sondaPub09, 'public function confirmarPublicacao() {}', 'ISCA quebrada: a sonda PUB-09 não pega um segundo passo evidente');
        $this->assertMatchesRegularExpression($sondaPub09, 'const JANELA_DE_UNDO = 5;', 'ISCA quebrada: a sonda PUB-09 não pega `undo` com `_` em volta');
        // ⚠️ AS FORMAS camelCase — as que o defeito teria de verdade. Com borda à DIREITA a sonda
        // ficava cega para as três, e o gate inteiro passaria por omissão.
        $this->assertMatchesRegularExpression($sondaPub09, 'function desfazerAprovacao() {}', 'ISCA quebrada: a sonda PUB-09 não pega `desfazerAprovacao` (camelCase)');
        $this->assertMatchesRegularExpression($sondaPub09, 'const undoWindowMs = 5000;', 'ISCA quebrada: a sonda PUB-09 não pega `undoWindow…` (camelCase)');
        $this->assertMatchesRegularExpression($sondaPub09, "'desfazer_aprovacao'", 'ISCA quebrada: a sonda PUB-09 não pega `desfazer_aprovacao` (snake_case)');

        // ⚠️⚠️ ISCA DA BORDA, MEDIDA: as três palavras pt-BR que a sonda SEM borda acusou.
        // Se uma delas voltar a casar, o gate recomeça a reprovar arquivo correto.
        foreach (['o segundo passo da conta', 'o fundo do card', 'de forma mais profunda'] as $prosaLegitima) {
            $this->assertDoesNotMatchRegularExpression(
                $sondaPub09,
                $prosaLegitima,
                'ISCA quebrada: a sonda PUB-09 voltou a casar "undo" dentro de palavra pt-BR — ela reprovaria arquivo correto ("'.$prosaLegitima.'")'
            );
        }

        // ⚠️ ISCA PUB-09 ao contrário: `aprovar` NÃO pode ser reprovado — o rótulo é do protótipo.
        $this->assertDoesNotMatchRegularExpression($sondaPub09, "'Aprovar para publicação'", 'ISCA quebrada: a sonda reprova o rótulo CORRETO do protótipo');
        $achouAprovar = false;
        foreach (self::PRODUCAO_PHP_DO_PORTAO as $rel) {
            if (preg_match('/\baprovar/i', $this->semComentariosPhp($this->ler($rel)))) {
                $achouAprovar = true;
                break;
            }
        }
        $this->assertTrue($achouAprovar, 'ISCA quebrada: `aprovar` não é achado na área nova — a varredura está lendo o nada');

        // ⚠️ ISCA BLOQ-09: as duas formas que a lista de nomes do 186-05 deixaria passar.
        $this->assertMatchesRegularExpression($sondaBloq09, 'private bool $excecaoPendenteDeAutorizacao;', 'ISCA quebrada: `excecaoPendente…Autorizacao` passaria');
        $this->assertMatchesRegularExpression($sondaBloq09, "const AGUARDANDO_GESTOR = 'aguardando_gestor';", 'ISCA quebrada: `aguardando_gestor` passaria');
        $this->assertMatchesRegularExpression($sondaBloq09, "'Aguardando autorização do gestor'", 'ISCA quebrada: a forma acentuada passaria (armadilha 7)');
        $this->assertMatchesRegularExpression($sondaBloq09, "'Pendente de liberação'", 'ISCA quebrada: a forma com `liberação` passaria');
        $this->assertMatchesRegularExpression($sondaBloq09Simetrica, "'Autorização pendente'", 'ISCA quebrada: a forma SIMÉTRICA passaria');

        // ⚠️⚠️ ISCA BLOQ-09 AO CONTRÁRIO, MEDIDA: o estado nº 2 do desenho — "Aguardando
        // aprovação" — NÃO pode ser reprovado. Ele é espera da **própria ECF**, que é o fluxo que
        // a fase cria; o que BLOQ-09 proíbe é esperar um **segundo aprovador**. A primeira redação
        // desta sonda trazia `aprovaç` no lado direito e acusou exatamente estes dois literais.
        foreach ([
            "const AGUARDANDO_APROVACAO = 'aguardando_aprovacao';",
            "'Aguardando aprovação'",
            'aguardando_aprovacao => Aguardando aprovação',
        ] as $legitimo) {
            $this->assertDoesNotMatchRegularExpression(
                $sondaBloq09,
                $legitimo,
                'ISCA quebrada: a sonda reprova o estado LEGÍTIMO do desenho ("'.$legitimo.'") — o gate reprovaria pelo próprio acerto'
            );
            $this->assertDoesNotMatchRegularExpression($sondaBloq09Simetrica, $legitimo, 'idem, na forma simétrica');
        }

        // ⚠️ E a exceção é IMEDIATA: ela registra quem liberou, sem travar a oferta.
        $this->assertTrue(
            method_exists(\App\Services\Publicador\Aprovacao\AjustesDaOfertaService::class, 'aplicarExcecao'),
            'a decisão 5 pede exceção que REGISTRA — `aplicarExcecao` tem de existir'
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (h) GATE-08 / GATE-09 NA ÁREA NOVA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:**
     *  · **GATE-08** — um mapa de classe do Tailwind nascer num módulo **`.js`**. O `content` do
     *    `tailwind.config.js` aponta para `*.jsx` e **NÃO** varre `*.js`: as classes **não entram
     *    no CSS construído** e a tela sai sem cor nenhuma, **sem erro de build**.
     *  · **GATE-09** — alguém escrever `duration-[200ms]`, `delay-[…]` ou `ease-[…]`. Valor
     *    arbitrário de transição **não compila** neste projeto, e o efeito é o mesmo: silêncio.
     *
     * ⚠️⚠️ **O GATE LÊ O CÓDIGO SEM COMENTÁRIO, E ISSO FOI MEDIDO AQUI.** Um `grep` cru de
     * `(duration|delay|ease)-\[` em `aprovacao/` casa **2 comentários** que avisam *"NÃO escrever
     * `duration-[Nms]`"* — o gate reprovaria **pela própria advertência contra o defeito**. É a
     * armadilha 2 desta fase, em estado puro.
     *
     * ⚠️ **`duration-200` é o CONTROLE:** a sonda tem de **NÃO** pegá-lo (ele compila) e o
     * repositório tem de tê-lo em algum lugar, senão a sonda poderia estar cega para a forma toda.
     *
     * @group phase186
     */
    public function test_gate08_e_gate09_na_area_nova_lidos_sem_comentario_com_controle(): void
    {
        $dir = $this->raiz().'/resources/js/Components/Mlb/Publicador/aprovacao';
        $this->assertDirectoryExists($dir, 'ERRO DO GATE: o diretório da área nova não existe');

        // ── GATE-08: nenhum módulo `.js` com mapa de classe do Tailwind ──────────────────────
        $jsPuros = glob($dir.'/*.js') ?: [];
        $comClasse = [];
        foreach ($jsPuros as $f) {
            $limpo = $this->semComentariosJs((string) file_get_contents($f));
            if (preg_match('/(?:bg|text|border|ring|from|to)-[a-z0-9-]+\//i', $limpo) || preg_match('/\bbg-pub-|\btext-pub-|\bborder-pub-/', $limpo)) {
                $comClasse[] = basename($f);
            }
        }
        $this->assertSame([], $comClasse, 'GATE-08: mapa de classe do Tailwind em `.js` não entra no CSS construído');
        // ⚠️ E o estado MEDIDO: não há `.js` nenhum em `aprovacao/` — os 3 módulos são `.jsx`.
        $this->assertSame([], array_map('basename', $jsPuros), 'em 15/10/2026 `aprovacao/` não tem nenhum módulo `.js` — os 3 são `.jsx`');

        // ⚠️ ISCA GATE-08: a sonda de classe TEM de pegar um mapa sintético.
        $this->assertMatchesRegularExpression('/\bbg-pub-|\btext-pub-|\bborder-pub-/', "export const M = { ok: 'bg-pub-ok/[0.10]' };", 'ISCA quebrada: a sonda de classe do Tailwind não pega um mapa evidente');

        // ── GATE-09: nenhuma classe arbitrária de transição, LIDA SEM COMENTÁRIO ─────────────
        $sondaArbitraria = '/\b(?:duration|delay|ease)-\[/';
        $arbitrarias = [];
        $varridos = 0;
        foreach (self::PRODUCAO_JSX_DO_PORTAO as $rel) {
            $varridos++;
            $cru = $this->ler($rel);
            $limpo = $this->semComentariosJs($cru);
            if (preg_match($sondaArbitraria, $limpo, $m)) {
                $arbitrarias[] = $rel.' => "'.$m[0].'"';
            }
        }
        $this->assertSame(3, $varridos, 'os 3 módulos `.jsx` da área nova');
        $this->assertSame([], $arbitrarias, 'GATE-09: `duration-[…]`/`delay-[…]`/`ease-[…]` não compilam neste projeto');

        // ⚠️⚠️ A MEDIDA QUE PROVA QUE O LEITOR SEM COMENTÁRIO É NECESSÁRIO, E NÃO ZELO:
        // no texto CRU a sonda CASA (são as advertências), e no texto limpo NÃO casa.
        $cruJuntado = '';
        foreach (self::PRODUCAO_JSX_DO_PORTAO as $rel) {
            $cruJuntado .= $this->ler($rel);
        }
        $this->assertMatchesRegularExpression(
            $sondaArbitraria,
            $cruJuntado,
            'ISCA quebrada: a advertência `duration-[Nms]` saiu dos comentários — então o leitor sem comentário virou decorativo e este gate perdeu a prova de que ele é necessário'
        );
        $this->assertDoesNotMatchRegularExpression(
            $sondaArbitraria,
            $this->semComentariosJs($cruJuntado),
            'GATE-09: há classe arbitrária no CÓDIGO (não em comentário)'
        );

        // ⚠️ CONTROLE: `duration-200` compila e a sonda NÃO o pega.
        $this->assertDoesNotMatchRegularExpression($sondaArbitraria, 'transition duration-200 ease-out', 'ISCA quebrada: a sonda pega `duration-200`, que é LEGÍTIMO — ela reprovaria o desenho correto');
        $controles = glob($this->raiz().'/resources/js/Components/Mlb/Publicador/*/*.jsx') ?: [];
        $achouControle = false;
        foreach ($controles as $f) {
            if (str_contains((string) file_get_contents($f), 'duration-200')) {
                $achouControle = true;
                break;
            }
        }
        $this->assertTrue($achouControle, 'CONTROLE ausente: `duration-200` não existe em nenhum componente do Publicador — a sonda poderia estar cega para a forma toda');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ARMADILHA 1 e 9 — A BIJEÇÃO E O HOMÔNIMO, NOS NOVE MANIFESTOS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** dois planos batizarem uma guarda com o
     * **mesmo nome de método**, e o `--filter` de um deles passar a **somar dois arquivos**. Medido
     * nesta fase: aconteceu uma vez, e o gate media **dois** arquivos achando que media **um** — a
     * contagem do manifesto fechava por soma, e nenhuma das duas suítes estava sendo medida
     * isoladamente. É o oposto do que o manifesto existe para garantir.
     *
     * ⚠️ E a armadilha 1: **teste em arquivo sem id no manifesto NUNCA RODA** — sobrevivente de
     * mutação que é lacuna real. Aqui se prova que **todo método deste arquivo** tem id.
     *
     * @group phase186
     */
    public function test_os_nove_manifestos_nao_tem_homonimo_que_some_dois_arquivos(): void
    {
        $raiz = $this->raiz();

        // ── 1) Todo método deste arquivo tem id no manifesto deste plano ─────────────────────
        $manifesto = $this->ler('tests/gates/contagens-186-09.txt');
        $meus = [];
        foreach (get_class_methods($this) as $m) {
            if (str_starts_with($m, 'test_')) {
                $meus[] = $m;
            }
        }
        $this->assertGreaterThan(5, count($meus), 'gate sobre poucos métodos');
        $semId = [];
        foreach ($meus as $m) {
            if (! str_contains($manifesto, $m)) {
                $semId[] = $m;
            }
        }
        $this->assertSame([], $semId, 'método sem id no manifesto NUNCA RODA — é lacuna, não cobertura');

        // ── 2) Nenhum nome de método de teste do portão é HOMÔNIMO entre arquivos ────────────
        $arquivosDeTeste = array_merge(
            glob($raiz.'/tests/Feature/Publicador/Aprovacao/*.php') ?: [],
            glob($raiz.'/tests/Unit/Publicador/Aprovacao/*.php') ?: [],
        );
        $this->assertGreaterThan(10, count($arquivosDeTeste), 'gate sobre poucos arquivos de teste');

        $onde = [];
        $metodos = 0;
        foreach ($arquivosDeTeste as $f) {
            $limpo = $this->semComentariosPhp((string) file_get_contents($f));
            if (preg_match_all('/public function (test_[A-Za-z0-9_]+)\s*\(/', $limpo, $m)) {
                foreach ($m[1] as $nome) {
                    $metodos++;
                    $onde[$nome][] = basename($f);
                }
            }
        }
        $this->assertGreaterThan(100, $metodos, 'gate sobre poucos métodos — a área do portão tem mais de 100');

        $homonimos = [];
        foreach ($onde as $nome => $arquivos) {
            if (count(array_unique($arquivos)) > 1) {
                $homonimos[] = $nome.' em '.implode(' + ', array_unique($arquivos));
            }
        }
        $this->assertSame([], $homonimos, 'homônimo EXATO entre arquivos faz o `--filter` SOMAR duas suítes e a contagem do manifesto fechar por acidente');

        // ── 2b) ⚠️⚠️ E O CASO QUE DE VERDADE ACONTECEU DUAS VEZES NESTA FASE: o PREFIXO ──────
        //
        // ⚠️ **O `--filter` do PHPUnit é regex NÃO ANCORADO**, então um nome que é **PREFIXO** de
        // outro casa os DOIS. Homônimo exato é o caso fácil; prefixo é o que escapa.
        //  · 15/10/2026, consertado à mão em `0f7e0013`: `…_e_o_up_e_idempotente` (186-04) é
        //    prefixo de `…_e_o_up_e_idempotente_por_tabela` (186-06) ⇒ o gate media **2/44** onde
        //    o manifesto esperava **1/20**. O número do manifesto estava CERTO; o filtro apontava
        //    para o lugar errado.
        //  · 15/10/2026, pego por esta própria medição: o primeiro nome que eu dei a um método
        //    deste arquivo era prefixo de um do 186-07 ⇒ `--filter` devolvia **2 passed**.
        //
        // ⚠️ A versão anterior deste gate só olhava nome IGUAL — ela teria passado nos DOIS
        // casos reais. Esta é a correção, e ela é a razão de o gate existir.
        $prefixos = [];
        foreach ($onde as $a => $arqA) {
            foreach ($onde as $b => $arqB) {
                if ($a === $b) {
                    continue;
                }
                if (str_starts_with($b, $a) && $arqA[0] !== $arqB[0]) {
                    $prefixos[] = $a.' ('.$arqA[0].') é PREFIXO de '.$b.' ('.$arqB[0].')';
                }
            }
        }
        $this->assertSame(
            [],
            $prefixos,
            'colisão de PREFIXO entre arquivos: o `--filter` do PHPUnit é regex NÃO ANCORADO e SOMA os dois. Dê sufixo próprio a um dos métodos — NUNCA eleve a contagem do manifesto'
        );

        // ⚠️ ISCA: a detecção de homônimo EXATO TEM de pegar um caso sintético.
        $sintetico = ['test_guarda' => ['A.php', 'B.php'], 'test_unico' => ['A.php']];
        $pegos = [];
        foreach ($sintetico as $nome => $arquivos) {
            if (count(array_unique($arquivos)) > 1) {
                $pegos[] = $nome;
            }
        }
        $this->assertSame(['test_guarda'], $pegos, 'ISCA quebrada: a detecção de homônimo não pega um caso evidente');

        // ⚠️⚠️ ISCA DO PREFIXO, com os DOIS casos REAIS desta fase, reproduzidos sinteticamente.
        $sinteticoPrefixo = [
            'test_guarda_x_e_o_up_e_idempotente' => ['OfertaDoPortaoTest.php'],
            'test_guarda_x_e_o_up_e_idempotente_por_tabela' => ['MigrationDoPortaoGuardaTest.php'],
            'test_sozinho_sem_colisao' => ['OutroTest.php'],
        ];
        $pegosPrefixo = [];
        foreach ($sinteticoPrefixo as $a => $arqA) {
            foreach ($sinteticoPrefixo as $b => $arqB) {
                if ($a !== $b && str_starts_with($b, $a) && $arqA[0] !== $arqB[0]) {
                    $pegosPrefixo[] = $a;
                }
            }
        }
        $this->assertSame(
            ['test_guarda_x_e_o_up_e_idempotente'],
            $pegosPrefixo,
            'ISCA quebrada: a detecção de PREFIXO não reproduz a colisão REAL que o commit 0f7e0013 teve de consertar à mão — o gate seria vazio justamente na classe que já quebrou duas vezes'
        );
        // ⚠️ E o contrário: nome igual a si mesmo no MESMO arquivo não pode contar.
        $this->assertNotContains('test_sozinho_sem_colisao', $pegosPrefixo, 'ISCA quebrada: a detecção acusa método sem colisão');

        // ⚠️ ISCA: a extração de método TEM de achar métodos num texto sintético, e NÃO achar num
        // docblock que os cite.
        $this->assertSame(
            1,
            preg_match_all('/public function (test_[A-Za-z0-9_]+)\s*\(/', 'public function test_isca_9z7() {}', $m2),
            'ISCA quebrada: a extração de método não acha uma declaração evidente'
        );
        $this->assertSame(
            0,
            preg_match_all('/public function (test_[A-Za-z0-9_]+)\s*\(/', $this->semComentariosPhp("<?php\n/** @see public function test_citado_no_docblock() */\n\$x = 1;")),
            'ISCA quebrada: a extração conta método CITADO em docblock — contaria homônimo que não existe'
        );

        // ── 3) Os NOVE manifestos existem e nenhum está vazio ───────────────────────────────
        $ids = 0;
        for ($i = 1; $i <= 9; $i++) {
            $rel = sprintf('tests/gates/contagens-186-%02d.txt', $i);
            $texto = $this->ler($rel);
            $linhas = preg_grep('/^\s*(#|$)/', explode("\n", $texto), PREG_GREP_INVERT) ?: [];
            $this->assertGreaterThan(0, count($linhas), "o manifesto {$rel} não tem nenhuma linha de gate");
            $ids += count($linhas);
        }
        $this->assertGreaterThan(140, $ids, 'os nove manifestos somam mais de 140 ids — medido em 15/10/2026');
    }
}
