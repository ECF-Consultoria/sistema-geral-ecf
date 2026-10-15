<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\PubAprovacaoComposicao;
use App\Services\Publicador\Aprovacao\ComposicaoDaOferta;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 186, plano 07 — O GATE DE AUSÊNCIA DAS TAXAS (FIN-02 / critério 6) e DO JARGÃO (VOC-03).
 *
 * ⚠️⚠️ **A PERGUNTA DO LEARNINGS, RESPONDIDA: "o que precisaria estar errado no código para este
 * gate falhar?"** → **alguém escrever a comissão como percentual fixo em vez de ler a API do
 * Mercado Livre, e o painel passar a discordar do card "Quanto você recebe" do editor sem ninguém
 * ver.** Não é hipótese: o README do desenho traz `Clássico 12%`, `Premium 17%`, `tarifa fixa
 * R$ 6,75` e `imposto 6%` como se fossem regra, e o próprio protótipo **rotula essas taxas como
 * ficção** (`Aprovacao.dc.html:1064` *"taxa de exemplo"*, `:1066` *"exemplo"*).
 *
 * ⚠️⚠️ **E A MEDIÇÃO DAS FIXTURES REFORÇA ESTE GATE:** o `percentage_fee` real assume **11, 12,
 * 14, 15 e 17** conforme a categoria e o tipo de anúncio (17 ocorrências medidas em 14/10/2026).
 * Os `12`/`17` do README **coincidem** com dois valores reais — o que torna o hardcode ainda mais
 * tentador e ainda mais errado: ele **acertaria** em algumas categorias e **erraria calado** nas
 * outras. **Nunca hardcode, nem como default, nem como "valor típico".**
 *
 * ═══ ⚠️⚠️ GATE DE AUSÊNCIA SÓ VALE SE O LITERAL FOR **EXCLUSIVO**, E NENHUM DESTES É ═══════════
 *
 * Medido neste repositório:
 *   · `6.75` casa **dentro de `26.754383`** — e há um CNPJ `26.754.383/0001-87` em
 *     `app/Support/Cnpj.php:112`;
 *   · `0.12` casa em `0.125`, em `10.12` e em `2026-10.12`;
 *   · `12` casa em `12px`, `120` e `2012` — **inutilizável sozinho**.
 *
 * ➡️ **A ESCOLHA, DECLARADA:** ancorar na **FORMA** do número decimal, com **borda nos DOIS
 * lados** (`(?<![\d.])6\.75(?![\d])`), e restringir a varredura à **lista CONSTANTE** de arquivos
 * de produção deste plano — onde qualquer decimal de taxa é, por definição, um literal proibido.
 * ⚠️ Para o `12` da margem mínima o gate é **POSITIVO**: as leituras de `margem_contribuicao`
 * **e** de `config('publicador.aprovacao.margem_minima_percentual')` têm de estar **AMBAS**
 * presentes (é o fallback), e a frase de tela **não** pode ter o número embutido.
 *
 * ═══ ⚠️ O GATE LÊ O CÓDIGO **SEM OS COMENTÁRIOS**, PELO TOKENIZADOR DO PHP ═════════════════════
 *
 * A prosa pt-BR deste projeto cita os próprios números do protótipo ("Clássico 12%, Premium 17%"),
 * e um `grep` cru **reprovaria o comentário que EXPLICA que o número não é usado**. Esse erro já
 * aconteceu em **5 dos 12 planos da Fase 184** (*"o grep que casa com o comentário"*). ⚠️ **E o
 * inverso também:** gate que só olha comentário não mede nada — por isso há uma isca provando que
 * o leitor **tira comentário E PRESERVA código**.
 *
 * ⚠️ **Exceção declarada: o jargão é varrido no texto COMPLETO**, comentários incluídos. VOC-03
 * proíbe o termo **na tela e na prosa**: um docblock que o escreva ensina o próximo leitor a usá-lo.
 *
 * ⚠️ **Os arquivos de TESTE ficam FORA das duas listas, e isto é deliberado:** eles **citam** as
 * strings proibidas justamente para afirmar a ausência delas (`assertStringNotContainsString`).
 * Incluí-los faria o gate **reprovar pelo próprio conserto** — o padrão medido na Fase 184.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class SemLiteralDeTaxaTest extends TestCase
{
    /**
     * ⚠️ **LISTA CONSTANTE, NÃO GLOB.** Arquivo fora dela faria o gate **passar por omissão** (o
     * mesmo defeito do `ARQUIVOS_NOVOS` do GATE-03). Por isso a existência de **cada** entrada é
     * asserida: um arquivo renomeado não pode sair da varredura em silêncio.
     *
     * ⚠️ E, além desta lista, o diretório inteiro do portão é varrido por glob — a lista garante
     * que estes quatro são medidos mesmo que o glob quebre, e o glob pega o que a lista não tem.
     */
    private const ARQUIVOS_NOVOS = [
        'config/publicador.php',
        'app/Services/Publicador/Aprovacao/LinhaFinanceira.php',
        'app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php',
        'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
    ];

    private const DIRETORIO_DO_PORTAO = 'app/Services/Publicador/Aprovacao';

    /** O serviço que lê a API do ML — é ele que tem de aparecer no lugar do percentual fixo. */
    private const SERVICO = 'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php';

    /** As quatro taxas do README do desenho. ⚠️ Nenhuma é exclusiva: ver o docblock da classe. */
    private const LITERAIS_DE_TAXA = ['0.12', '0.17', '6.75', '0.06'];

    /**
     * ⚠️ AS DUAS FORMAS do jargão proibido (VOC-03): o nome por extenso e a **CHAVE** de dado.
     *
     * O literal acentuado está certo e **não** reprova o parâmetro legítimo do Portal — mas ele
     * **não pegaria `lucro_liquido` vazando como chave** se o serviço repassasse o array inteiro de
     * `EstruturaPrecificacaoParametros::daEmpresa()` (que o tem) para a tela.
     */
    private const JARGAO_PROIBIDO = ['lucro líquido', 'lucro_liquido', 'lucro liquido'];

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // AS SONDAS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ Ancorada na FORMA, com borda nos DOIS lados. `preg_quote` escapa o ponto; o resto do
     * padrão é escrito com aspas SIMPLES e escape explícito.
     *
     * @return list<string> os literais de taxa achados
     */
    private function sondaDeTaxa(string $texto): array
    {
        $achados = [];
        foreach (self::LITERAIS_DE_TAXA as $literal) {
            if (preg_match('/(?<![\d.])'.preg_quote($literal, '/').'(?![\d])/', $texto) === 1) {
                $achados[] = $literal;
            }
        }

        return $achados;
    }

    /** @return list<string> as formas de jargão achadas */
    private function sondaDeJargao(string $texto): array
    {
        $achados = [];
        $normalizado = mb_strtolower($texto, 'UTF-8');
        foreach (self::JARGAO_PROIBIDO as $forma) {
            if (str_contains($normalizado, mb_strtolower($forma, 'UTF-8'))) {
                $achados[] = $forma;
            }
        }

        return $achados;
    }

    /** O CÓDIGO sem os comentários, pelo tokenizador do PHP (as linhas são preservadas). */
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

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️ OS CONTROLES DA SONDA — ela tem de REPROVAR e tem de PASSAR, cada um no seu caso
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_a_sonda_de_taxa_reprova_o_literal_sintetico_e_passa_no_cnpj_que_o_contem(): void
    {
        // ⚠️ CONTROLE NEGATIVO — o caso que o gate existe para pegar.
        $this->assertSame(['0.17'], $this->sondaDeTaxa('$taxa = 0.17;'), 'CONTROLE NEGATIVO: a sonda NÃO pega a taxa escrita à mão — o gate é decorativo');

        // ⚠️ CONTROLE POSITIVO DE NÃO-FALSO-POSITIVO: `6.75` casa DENTRO de `26.754383`.
        $this->assertSame([], $this->sondaDeTaxa('$x = 26.754383;'), '`6.75` casou dentro de `26.754383` — o gate reprovaria um CNPJ');
        $this->assertSame([], $this->sondaDeTaxa('26.754.383/0001-87'), 'o gate reprovaria o CNPJ de `app/Support/Cnpj.php:112`');

        // As outras colisões MEDIDAS.
        foreach (['$x = 0.125;', '$x = 10.12;', "'2026-10.12'", '12px', '120', '2012', '$x = 1.067;', '$x = 6.751;'] as $inocente) {
            $this->assertSame([], $this->sondaDeTaxa($inocente), "a sonda casou algo inocente em `{$inocente}`");
        }

        // ⚠️ ARMADILHA 3 DA FASE: **TODA regex é testada contra um caso que DEVE casar.** Na wave 1
        // um padrão não achava FK nenhuma e o gate ficou verde medindo nada.
        foreach (self::LITERAIS_DE_TAXA as $literal) {
            $this->assertSame([$literal], $this->sondaDeTaxa('$v = '.$literal.';'), "a sonda NÃO acha `{$literal}` nem quando ele está lá");
            $this->assertSame([$literal], $this->sondaDeTaxa("round(\$p * {$literal}, 2)"), "a sonda NÃO acha `{$literal}` dentro de uma conta");
        }

        // E as quatro juntas.
        $this->assertSame(self::LITERAIS_DE_TAXA, $this->sondaDeTaxa('[0.12, 0.17, 6.75, 0.06]'));
    }

    public function test_o_leitor_tira_os_comentarios_e_preserva_o_codigo(): void
    {
        // ⚠️ A ISCA DOS DOIS LADOS. Um leitor que não tira comentário faria o gate reprovar pelo
        // PRÓPRIO CONSERTO (a prosa explica que 12%/17% não são usados). Um leitor que apaga código
        // junto faria o gate passar sempre.
        $amostra = <<<'PHP'
        <?php
        // a comissao de exemplo do README era 0.17
        $semTaxa = 1;
        /* e a tarifa fixa de exemplo era 6.75 */
        /** e o imposto de exemplo era 0.06, com 0.12 no Clássico */
        $codigoVivo = 'preservado';
        PHP;

        $codigo = $this->semComentarios($amostra);

        foreach (self::LITERAIS_DE_TAXA as $literal) {
            $this->assertStringNotContainsString($literal, $codigo, "o leitor NÃO tirou o comentário que cita `{$literal}`");
        }
        $this->assertSame([], $this->sondaDeTaxa($codigo));

        // ⚠️ E PRESERVA O CÓDIGO — senão o gate mede uma string vazia.
        $this->assertStringContainsString('$semTaxa = 1;', $codigo, 'ISCA CEGA: o leitor apagou código junto com o comentário.');
        $this->assertStringContainsString("'preservado'", $codigo);
        $this->assertStringContainsString('<?php', $codigo);

        // ISCA do outro lado: no texto CRU a sonda acha os quatro — prova que a amostra os tinha.
        $this->assertSame(self::LITERAIS_DE_TAXA, $this->sondaDeTaxa($amostra), 'ISCA CEGA: a amostra não tinha os literais — o teste da remoção não mediu nada.');
    }

    public function test_a_sonda_de_jargao_pega_as_duas_formas_e_nao_o_que_e_vocabulario_correto(): void
    {
        // ⚠️ AS DUAS FORMAS: o nome por extenso e a CHAVE de dado.
        $this->assertSame(['lucro líquido'], $this->sondaDeJargao('o lucro líquido da venda'));
        $this->assertSame(['lucro_liquido'], $this->sondaDeJargao("\$p['lucro_liquido']"));
        $this->assertSame(['lucro liquido'], $this->sondaDeJargao('LUCRO LIQUIDO SEM ACENTO'), 'a sonda tem de pegar a forma sem acento também');

        // ⚠️ E NÃO pega o vocabulário CORRETO — senão o gate reprovaria o nome que o desenho exige.
        foreach ([PubAprovacaoComposicao::ROTULO_RESULTADO, PubAprovacaoComposicao::ROTULO_MARGEM, 'Resultado negativo', 'Margem abaixo do mínimo'] as $certo) {
            $this->assertSame([], $this->sondaDeJargao($certo), "a sonda reprovou o vocabulário CORRETO `{$certo}`");
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A VARREDURA
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_a_lista_de_arquivos_e_constante_e_cada_entrada_existe_com_isca_de_fantasma(): void
    {
        // ⚠️ A lista é CONSTANTE (não glob): um glob que mudasse de resultado faria o gate passar
        // por omissão. Em troca, a existência de CADA entrada é asserida.
        $this->assertGreaterThanOrEqual(4, count(self::ARQUIVOS_NOVOS));
        foreach (self::ARQUIVOS_NOVOS as $relativo) {
            $this->assertFileExists(base_path($relativo), "arquivo da lista constante não existe: {$relativo} — o gate passaria por omissão");
            $this->assertNotSame('', trim((string) file_get_contents(base_path($relativo))), "arquivo vazio: {$relativo}");
        }

        // ⚠️ ISCA DO CAMINHO FANTASMA: 5 caminhos fantasma em 4 fases deste projeto. Arquivo
        // ausente tem de ser FALHA, não "nenhuma violação".
        $fantasma = base_path('app/Services/Publicador/Aprovacao/EsteArquivoNaoExiste.php');
        $this->assertFileDoesNotExist($fantasma);
        $this->assertFalse(is_file($fantasma), 'ISCA CEGA: o caminho fantasma existe — a isca não mede nada.');

        // ⚠️ E o glob do diretório do portão não pode voltar vazio: gate sobre ZERO arquivos não
        // prova nada. A âncora é a própria lista constante, não uma contagem de quantos há.
        $doGlob = glob(base_path(self::DIRETORIO_DO_PORTAO).'/*.php') ?: [];
        $this->assertNotEmpty($doGlob, 'ISCA CEGA: o glob do diretório do portão voltou vazio.');
        foreach (self::ARQUIVOS_NOVOS as $relativo) {
            if (str_starts_with($relativo, self::DIRETORIO_DO_PORTAO)) {
                $this->assertContains(str_replace('\\', '/', base_path($relativo)),
                    array_map(fn (string $p) => str_replace('\\', '/', $p), $doGlob),
                    "o glob do portão não acha `{$relativo}` — a varredura larga está cega");
            }
        }
    }

    public function test_nenhum_arquivo_de_producao_deste_plano_tem_literal_de_taxa(): void
    {
        $violacoes = [];
        $medidos = 0;

        // (1) A lista CONSTANTE — garantida mesmo que o glob quebre.
        foreach (self::ARQUIVOS_NOVOS as $relativo) {
            $caminho = base_path($relativo);
            $this->assertFileExists($caminho);
            $codigo = $this->semComentarios((string) file_get_contents($caminho));
            $medidos++;
            foreach ($this->sondaDeTaxa($codigo) as $achado) {
                $violacoes[] = "{$relativo}: {$achado}";
            }
        }

        // (2) O diretório INTEIRO do portão, por glob — pega o arquivo que a lista não tem.
        foreach (glob(base_path(self::DIRETORIO_DO_PORTAO).'/*.php') ?: [] as $caminho) {
            $codigo = $this->semComentarios((string) file_get_contents($caminho));
            $medidos++;
            foreach ($this->sondaDeTaxa($codigo) as $achado) {
                $violacoes[] = basename($caminho).": {$achado}";
            }
        }

        $this->assertGreaterThanOrEqual(count(self::ARQUIVOS_NOVOS), $medidos, 'ISCA CEGA: o gate mediu menos arquivos que a lista constante.');
        $this->assertSame([], array_unique($violacoes),
            'TAXA ESCRITA À MÃO no código do portão — ela discordaria da API do Mercado Livre em silêncio: '.implode(' · ', array_unique($violacoes)));
    }

    public function test_nenhum_arquivo_de_producao_deste_plano_tem_o_jargao_nem_como_chave(): void
    {
        // ⚠️ Aqui o texto é o COMPLETO, comentários incluídos: VOC-03 proíbe o termo também na
        // prosa — um docblock que o escreva ensina o próximo leitor a usá-lo.
        $violacoes = [];
        foreach (self::ARQUIVOS_NOVOS as $relativo) {
            $caminho = base_path($relativo);
            $this->assertFileExists($caminho);
            foreach ($this->sondaDeJargao((string) file_get_contents($caminho)) as $achado) {
                $violacoes[] = "{$relativo}: {$achado}";
            }
        }
        foreach (glob(base_path(self::DIRETORIO_DO_PORTAO).'/*.php') ?: [] as $caminho) {
            foreach ($this->sondaDeJargao((string) file_get_contents($caminho)) as $achado) {
                $violacoes[] = basename($caminho).": {$achado}";
            }
        }

        $this->assertSame([], array_unique($violacoes),
            'JARGÃO PROIBIDO (VOC-03) no portão — nada aqui desconta despesa fixa nem devolução, então o nome seria afirmação falsa: '.implode(' · ', array_unique($violacoes)));

        // ⚠️ ISCA: o campo legítimo do Portal CONTINUA existindo e NÃO é reprovado — a sonda é
        // recortada à lista, não solta pelo repositório.
        $doPortal = base_path('app/Models/EstruturaPrecificacaoParametros.php');
        $this->assertFileExists($doPortal);
        $this->assertNotEmpty($this->sondaDeJargao((string) file_get_contents($doPortal)),
            'ISCA CEGA: a sonda não acha o campo legítimo nem no arquivo que o TEM — ela está cega, e o gate do portão não mede nada.');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️ OS CONTROLES POSITIVOS — sem eles a sonda pode estar simplesmente cega
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_o_servico_le_a_api_do_ml_e_os_dois_lados_do_fallback_estao_presentes(): void
    {
        $codigo = $this->semComentarios((string) file_get_contents(base_path(self::SERVICO)));

        // ⚠️ CONTROLE POSITIVO: no lugar do percentual fixo tem de estar o caminho da API.
        $this->assertStringContainsString('SimuladorVoceRecebe', $codigo, 'CONTROLE POSITIVO CEGO: a sonda não acha `SimuladorVoceRecebe` no serviço.');
        $this->assertStringContainsString('/sites/MLB/listing_prices', $codigo, 'o serviço não consulta a tarifa no Mercado Livre');
        $this->assertStringContainsString('sale_fee_details', $codigo, 'o serviço não lê o detalhamento da tarifa de VENDA');
        $this->assertStringContainsString('percentage_fee', $codigo);
        $this->assertStringContainsString('fixed_fee', $codigo);
        $this->assertStringContainsString('custosDoProduto', $codigo, 'o serviço não lê o custo da Precificação do Portal');
        $this->assertStringContainsString('fretesDoProduto', $codigo, 'o serviço não lê o frete da Precificação do Portal');

        // ⚠️ OS DOIS LADOS DO FALLBACK — achar só um significaria que alguém "simplificou", e
        // qualquer das duas simplificações é bug silencioso.
        $this->assertStringContainsString('margem_contribuicao', $codigo, 'o lado do PARÂMETRO DA EMPRESA desapareceu — só-config ignora quem configurou a própria exigência');
        $this->assertStringContainsString("config('publicador.aprovacao.margem_minima_percentual')", $codigo,
            'o lado da CONFIG desapareceu — só-parâmetro DESLIGA o bloqueio em toda empresa cujo `margem_contribuicao` é o default 0.0');

        // ⚠️ E `gross_amount` é lido para CONFERIR, nunca somado como terceira parcela.
        $this->assertStringContainsString('gross_amount', $codigo);
        $this->assertStringContainsString('sale_fee_amount', $codigo);
    }

    public function test_a_frase_da_margem_minima_nao_tem_o_numero_embutido(): void
    {
        $codigo = $this->semComentarios((string) file_get_contents(base_path('app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php')));

        // ⚠️ GATE POSITIVO para o `12`: ele é inutilizável como gate de ausência (`12px`, `120`,
        // `2012`). O que se prova é que a frase é MONTADA com o valor resolvido.
        $this->assertSame(0, preg_match('/m[íi]nimo de \d/u', $codigo),
            'a frase da margem mínima tem o número EMBUTIDO — a tela mentiria para a empresa que configurou outro mínimo (FIN-09)');
        $this->assertStringContainsString("'acima do mínimo de '.self::percentual(", $codigo, 'CONTROLE POSITIVO CEGO: a sonda não acha a frase montada.');
        $this->assertStringContainsString("'abaixo do mínimo de '.self::percentual(", $codigo);
        $this->assertStringContainsString('$margemMinima', $codigo, 'o mínimo não é parâmetro da composição');

        // ISCA DA SONDA: ela ACHA o número embutido quando ele está lá.
        $this->assertSame(1, preg_match('/m[íi]nimo de \d/u', "'abaixo do mínimo de 12%'"),
            'ISCA CEGA: a sonda do número embutido não acha nem o caso que ela existe para pegar.');

        // ⚠️ E o vocabulário travado é LIDO do modelo, não recopiado (VOC-03 / FIN-14).
        $this->assertStringContainsString('PubAprovacaoComposicao::ROTULO_RESULTADO', $codigo);
        $this->assertStringContainsString('PubAprovacaoComposicao::ROTULO_MARGEM', $codigo);
        $this->assertSame('Resultado estimado por venda', PubAprovacaoComposicao::ROTULO_RESULTADO);
        $this->assertSame('Margem de contribuição estimada', PubAprovacaoComposicao::ROTULO_MARGEM);
    }

    public function test_a_composicao_seleciona_a_chave_dos_parametros_em_vez_de_repassar_o_array(): void
    {
        $codigo = $this->semComentarios((string) file_get_contents(base_path(self::SERVICO)));

        // ⚠️ `daEmpresa()` devolve `array_map('floatval', …)` de TODAS as `PADROES`, inclusive o
        // campo homônimo do jargão. A ÚNICA chamada do serviço tem de ser indexada na hora.
        $this->assertSame(1, preg_match_all('/daEmpresa\(/', $codigo), 'o serviço chama `daEmpresa()` mais de uma vez — alguma das chamadas pode repassar o array inteiro');
        $this->assertSame(1, preg_match("/daEmpresa\(\\\$companyId\)\['margem_contribuicao'\]/", $codigo),
            'a chamada de `daEmpresa()` não está SELECIONANDO a chave — o array inteiro vazaria o campo homônimo para dentro de `linhas`');

        // ISCA: a sonda da seleção acha o caso certo e NÃO acha o caso adverso.
        $this->assertSame(1, preg_match("/daEmpresa\(\\\$companyId\)\['margem_contribuicao'\]/", "EstruturaPrecificacaoParametros::daEmpresa(\$companyId)['margem_contribuicao']"),
            'ISCA CEGA: a sonda da seleção não acha nem o caso certo.');
        $this->assertSame(0, preg_match("/daEmpresa\(\\\$companyId\)\['margem_contribuicao'\]/", 'EstruturaPrecificacaoParametros::daEmpresa($companyId)'),
            'ISCA CEGA: a sonda da seleção aceita o array sem índice — ela não mede a seleção.');

        // ⚠️ E as parcelas fixas moram em UM lugar só: duas listas da mesma verdade divergiriam.
        $daComposicao = $this->semComentarios((string) file_get_contents(base_path('app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php')));
        $this->assertStringContainsString('self::PARCELAS_FIXAS', $daComposicao);
        $this->assertSame(['custo', 'tarifa_fixa', 'frete', 'embalagem', 'outros'], ComposicaoDaOferta::PARCELAS_FIXAS);
        $this->assertStringNotContainsString('PARCELAS_FIXAS', $codigo, 'a lista das parcelas fixas foi copiada para o serviço — as duas ficariam livres para divergir');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️ A BIJEÇÃO ids ↔ MÉTODOS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_todo_metodo_do_plano_07_tem_id_no_manifesto_e_todo_id_casa_um_metodo(): void
    {
        // ⚠️ O SUFIXO `_do_plano_07` NÃO é estilo: o plano 186-06 tem um método de bijeção com o
        // nome GENÉRICO, e o `--filter` do PHPUnit casa por nome em TODOS os arquivos da suíte.
        // MEDIDO nesta máquina: o filtro genérico devolveu `2 passed (65 assertions)` somando os
        // dois planos — a contagem do manifesto deixaria de medir ESTE arquivo.
        // ⚠️⚠️ TESTE QUE EXISTE NO ARQUIVO E NÃO TEM id NO MANIFESTO **NUNCA RODA** em gate
        // nenhum. Foi o sobrevivente da campanha de mutação do plano 186-03, e era lacuna real.
        $manifesto = base_path('tests/gates/contagens-186-07.txt');
        $this->assertFileExists($manifesto);

        $arquivos = [
            base_path('tests/Unit/Publicador/Aprovacao/ComposicaoFinanceiraServiceTest.php'),
            base_path('tests/Feature/Publicador/Aprovacao/ComposicaoContraOPrototipoTest.php'),
            base_path('tests/Feature/Publicador/Aprovacao/SemLiteralDeTaxaTest.php'),
        ];

        $metodos = [];
        foreach ($arquivos as $arquivo) {
            $this->assertFileExists($arquivo);
            preg_match_all('/public function (test_[a-z0-9_]+)\(/', (string) file_get_contents($arquivo), $m);
            $metodos = array_merge($metodos, $m[1]);
        }
        sort($metodos);

        // ISCA: a sonda de métodos acha os testes de verdade (e me acha a mim).
        $this->assertGreaterThanOrEqual(40, count($metodos),
            'ISCA CEGA: a sonda achou menos métodos do que os três arquivos têm — a bijeção rodaria sobre lista curta.');
        $this->assertContains('test_todo_metodo_do_plano_07_tem_id_no_manifesto_e_todo_id_casa_um_metodo', $metodos,
            'ISCA CEGA: a sonda não acha nem o próprio método da bijeção.');

        $filtros = [];
        foreach (file($manifesto, FILE_IGNORE_NEW_LINES) as $linha) {
            if (preg_match('/^\s*(#|$)/', $linha)) {
                continue;
            }
            $campos = preg_split('/\s+/', trim($linha));
            $this->assertSame('php', $campos[1] ?? null, "a linha `{$linha}` do manifesto não é do modo `php` — este plano não tem gate de JS");
            $filtros[$campos[0]] = $campos[2];
        }
        $this->assertNotEmpty($filtros, 'ISCA CEGA: o manifesto não tem linha de gate nenhuma.');

        // (1) ⚠️ TODO MÉTODO coberto por pelo menos um filtro.
        $semId = [];
        foreach ($metodos as $metodo) {
            $coberto = false;
            foreach ($filtros as $filtro) {
                if (preg_match('/^(?:'.$filtro.')$/', $metodo) === 1) {
                    $coberto = true;
                    break;
                }
            }
            if (! $coberto) {
                $semId[] = $metodo;
            }
        }
        $this->assertSame([], $semId, 'MÉTODO SEM id NO MANIFESTO — ele NUNCA RODA em gate nenhum: '.implode(', ', $semId));

        // (2) ⚠️ TODO id casa pelo menos um método — id órfão é gate sobre ZERO casos.
        $orfaos = [];
        foreach ($filtros as $id => $filtro) {
            if (array_filter($metodos, fn (string $m) => preg_match('/^(?:'.$filtro.')$/', $m) === 1) === []) {
                $orfaos[] = $id;
            }
        }
        $this->assertSame([], $orfaos, 'id do manifesto que não casa método nenhum (gate sobre ZERO casos): '.implode(', ', $orfaos));

        // ⚠️ ISCA DO CASADOR, nos dois sentidos: ele casa alternância e NÃO casa por prefixo.
        $this->assertSame(1, preg_match('/^(?:test_(a|b))$/', 'test_b'), 'ISCA CEGA: o casador não entende alternância — a bijeção daria falso negativo.');
        $this->assertSame(0, preg_match('/^(?:test_a)$/', 'test_abc'), 'ISCA CEGA: o casador casa por PREFIXO — um id cobriria métodos que ele nunca roda.');
    }
}
