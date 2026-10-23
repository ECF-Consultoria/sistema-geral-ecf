<?php

namespace Tests\Feature\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAprovacaoOferta;
use App\Services\Publicador\Agente\ConfiguracaoDoAgenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A REVISÃO HUMANA É TRAVADA (E1, 23/10/2026) — *"revisão obrigatória travada"*, da linha
 * **Configuração/Pessoas** do plano do Agente.
 *
 * ⚠️⚠️ **O QUE ESTE ARQUIVO IMPEDE, E POR QUE ELE VALE MAIS QUE UMA FRASE NA TELA.**
 *
 * A gestão pediu, em 14/10/2026, que o sistema publicasse *"de forma autônoma… mas que tenha uma
 * aprovação antes de ir para ar"*. E o usuário decidiu que **aprovar JÁ PUBLICA, sem segundo
 * clique e sem desfazer**. As duas decisões juntas significam que **a revisão humana é a ÚNICA
 * barreira** entre o agente e um anúncio no ar.
 *
 * ⚠️ Já existe gate para a FRASE na tela (*"Toda oferta precisa de aprovação humana antes de
 * publicar"*, no cabeçalho da fila, 187). **Frase não é mecanismo.** Este arquivo prova o
 * mecanismo: que **não há chave, opção ou caminho** que faça o agente aprovar ou publicar.
 *
 * ⚠️⚠️ **ESTE ARQUIVO PROVA O MECANISMO; A PROVA DE COMPORTAMENTO JÁ EXISTE E FICA ONDE ESTÁ.**
 * `EncaminharParaAprovacaoTest` roda o fluxo do agente de ponta a ponta e assevera que a oferta
 * termina em `AGUARDANDO_APROVACAO` (`:58`, `:80` e `:177`). Eu havia escrito aqui um quinto caso
 * "a oferta nasce esperando gente" que só olhava um modelo NÃO SALVO — **decoração com cara de
 * prova**, e teste que parece provar e não prova é pior que teste ausente. Ele foi **removido**, e
 * esta linha existe para o próximo leitor saber que os dois lados estão cobertos: o comportamento
 * lá, o mecanismo aqui.
 *
 * ⚠️⚠️ **E ele prova por AUSÊNCIA sobre o CÓDIGO SEM COMENTÁRIO.** Os docblocks do agente falam de
 * aprovação o tempo todo (*"nada vai ao Mercado Livre sem uma pessoa"*, *"não aprova nem publica
 * nada"*) — ler com comentário faria o gate **reprovar pelo próprio conserto**, que é a armadilha
 * mais frequente deste projeto. O tokenizador do PHP tira os comentários.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class RevisaoObrigatoriaTravadaTest extends TestCase
{
    use CenarioDoAgente;
    use RefreshDatabase;

    /** Todo o código do agente — é o recorte em que a ausência tem de valer. */
    private const DIR_AGENTE = 'app/Services/Publicador/Agente';

    private const CONTROLLER = 'app/Http/Controllers/MlbPublicadorAgenteController.php';

    /**
     * ⚠️ As formas de ESCRITA que aprovam ou publicam. Lista CONSTANTE de nomes, nunca uma
     * contagem: contagem sobe quando o trabalho certo é feito, e treina o executor seguinte a
     * ajustar a régua.
     */
    private const FORMAS_DE_APROVAR = [
        '->aprovar(',
        '->aprovarEmLote(',
        '->enfileirar(',
        'AprovacaoDaOfertaService',
        'PublicacaoDoPortaoService',
        'PublicacaoService',
    ];

    /**
     * ⚠️ Os andamentos que só uma pessoa pode causar. `FALHA` e `PUBLICADO` entram porque escrever
     * qualquer um deles a partir do agente significaria que ele passou pelo portão.
     */
    private const ANDAMENTOS_DE_GENTE = [
        PubAprovacaoOferta::APROVADO,
        PubAprovacaoOferta::PUBLICANDO,
        PubAprovacaoOferta::PUBLICADO,
    ];

    /**
     * ⚠️⚠️ A ÚNICA EXCEÇÃO, e ela é **PROVADA** no caso, não afirmada aqui.
     *
     * `LeituraDoAgente` **cita** `APROVADO` e `PUBLICANDO` — numa lista de LEITURA, para contar
     * quantas ofertas estão na publicação. É expositor, não escritor. A técnica é a da wave 187-08:
     * o expositor **tem de casar a sonda larga** (senão a exceção é linha morta) **e não pode ter
     * nenhuma forma de escrita**.
     */
    private const EXPOSITOR_DE_LEITURA = 'app/Services/Publicador/Agente/LeituraDoAgente.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarAgente();
    }

    /** @return array<string,string> caminho relativo => código SEM comentário */
    private function fontesDoAgente(): array
    {
        $fontes = [];
        foreach (array_merge(glob(base_path(self::DIR_AGENTE).'/*.php') ?: [], [base_path(self::CONTROLLER)]) as $abs) {
            $this->assertFileExists($abs, "arquivo do agente ausente no disco — toda sonda de texto mediria NADA: {$abs}");
            $rel = str_replace('\\', '/', substr($abs, strlen(base_path()) + 1));
            $fontes[$rel] = $this->semComentarios(file_get_contents($abs));
        }

        // ⚠️ ISCA DO RECORTE: o agente tem mais de um arquivo. Com a lista vazia (um `glob` que
        // falha, um caminho que mudou), toda asserção de ausência abaixo passaria CALADA.
        $this->assertGreaterThanOrEqual(9, count($fontes), 'o recorte do agente veio curto — as ausências abaixo passariam por lista vazia');
        $this->assertArrayHasKey(self::EXPOSITOR_DE_LEITURA, $fontes, 'o expositor de leitura saiu do recorte');

        return $fontes;
    }

    /** O CÓDIGO sem os comentários, pelo tokenizador do PHP. */
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

    public function test_nenhum_codigo_do_agente_aprova_ou_publica_travada_e1(): void
    {
        $fontes = $this->fontesDoAgente();

        // ⚠️ UMA asserção coletando os infratores: a contagem deste gate não cresce com o agente.
        $infratores = [];
        foreach ($fontes as $rel => $codigo) {
            // ⚠️ Flag DENTRO do laço.
            foreach (self::FORMAS_DE_APROVAR as $forma) {
                if (str_contains($codigo, $forma)) {
                    $infratores[] = "{$rel} => {$forma}";
                }
            }
        }

        $this->assertSame([], $infratores, '⚠️⚠️ código do agente chama uma porta de APROVAÇÃO ou PUBLICAÇÃO. A revisão humana é a ÚNICA barreira antes do ar, e aprovar JÁ PUBLICA sem desfazer: '.implode(' | ', $infratores));

        // ⚠️ ISCA: a sonda acha cada forma quando ela existe. Sem isto, um `str_contains` com a
        // string errada daria `[]` para sempre — "nenhum achado" não significaria nada.
        $CEBO = '$x = app(AprovacaoDaOfertaService::class); $x->aprovar($o, $u); $x->aprovarEmLote([]); '
            .'app(PublicacaoDoPortaoService::class)->enfileirar($o, $u); app(PublicacaoService::class);';
        $cegas = array_values(array_filter(self::FORMAS_DE_APROVAR, fn (string $f) => ! str_contains($CEBO, $f)));
        $this->assertSame([], $cegas, 'forma que não casa o próprio cebo — a sonda está CEGA: '.implode(', ', $cegas));
    }

    public function test_o_agente_nunca_ESCREVE_andamento_de_gente_e_o_expositor_e_provado_travada_e1(): void
    {
        $fontes = $this->fontesDoAgente();

        // ⚠️⚠️ A SONDA LARGA: qualquer MENÇÃO aos andamentos de gente.
        $mencionam = [];
        foreach ($fontes as $rel => $codigo) {
            foreach (self::ANDAMENTOS_DE_GENTE as $andamento) {
                $constante = 'PubAprovacaoOferta::'.strtoupper($andamento);
                if (str_contains($codigo, $constante) || str_contains($codigo, "'{$andamento}'")) {
                    $mencionam[$rel] = true;
                }
            }
        }

        // ⚠️ Só o expositor de LEITURA pode mencionar.
        $foraDaExcecao = array_values(array_diff(array_keys($mencionam), [self::EXPOSITOR_DE_LEITURA]));
        $this->assertSame([], $foraDaExcecao, 'código do agente menciona um andamento que só uma pessoa causa: '.implode(', ', $foraDaExcecao));

        // ⚠️⚠️ **A EXCEÇÃO É PROVADA, NÃO AFIRMADA** (técnica da wave 187-08): o expositor **tem de
        // casar a sonda larga**. Se ele não casar, a linha de exceção é LINHA MORTA — e o dia em
        // que alguém de fato escrever ali, ninguém verá.
        $this->assertArrayHasKey(self::EXPOSITOR_DE_LEITURA, $mencionam, 'o expositor declarado NÃO menciona andamento nenhum — a exceção virou linha morta');

        // ⚠️ E ele não pode ter NENHUMA forma de escrita: expositor que escreve não é expositor.
        $codigoDoExpositor = $fontes[self::EXPOSITOR_DE_LEITURA];
        foreach ([...self::FORMAS_DE_APROVAR, '->mover(', '->update(', '->save(', '::create('] as $escrita) {
            $this->assertStringNotContainsString($escrita, $codigoDoExpositor, "o expositor de leitura ganhou a escrita `{$escrita}` — ele deixou de ser só leitura");
        }
    }

    public function test_o_preparador_para_em_aguardando_aprovacao_e_so_nisso_travada_e1(): void
    {
        $rel = 'app/Services/Publicador/Agente/PreparadorDaAprovacao.php';
        $codigo = $this->semComentarios(file_get_contents(base_path($rel)));

        // ⚠️ O ÚNICO destino de `mover()` no preparador é `AGUARDANDO_APROVACAO`. Aqui a prova é
        // por EXTRAÇÃO, não por ausência: eu leio todos os destinos e comparo o CONJUNTO. Ausência
        // sozinha não pegaria um `mover($o, $outraCoisa)` montado em variável.
        preg_match_all('/->mover\(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*,\s*([^,\)]+)/', $codigo, $m);
        $destinos = array_values(array_unique(array_map('trim', $m[1])));

        $this->assertNotSame([], $destinos, 'nenhum `->mover(` encontrado no preparador — a sonda está CEGA ou o arquivo mudou de forma');
        $this->assertSame(
            ['PubAprovacaoOferta::AGUARDANDO_APROVACAO'],
            $destinos,
            'o preparador do agente move a oferta para outro andamento além de "aguardando aprovação": '.implode(', ', $destinos),
        );

        // ⚠️ ISCA da regex: ela casa a forma que deve casar.
        preg_match_all('/->mover\(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*,\s*([^,\)]+)/', '$this->andamento->mover($oferta, PubAprovacaoOferta::APROVADO, null);', $cebo);
        $this->assertSame(['PubAprovacaoOferta::APROVADO'], array_map('trim', $cebo[1]), 'a regex do destino de `mover()` está CEGA');
    }

    public function test_nenhuma_chave_de_configuracao_desliga_a_revisao_travada_e1(): void
    {
        // ⚠️⚠️ A configuração da loja NÃO pode ter uma opção de "publicar sem revisar". O docblock
        // do modelo já diz isso (*"a aprovação humana NÃO é configurável aqui: é invariante do
        // portão, não uma opção da loja"*) — este caso transforma a frase em prova.
        $PALAVRAS_PROIBIDAS = [
            'auto_aprovar', 'aprovar_automatico', 'sem_revisao', 'sem_aprovacao', 'pular_revisao',
            'publicar_direto', 'aprovacao_automatica', 'revisao_opcional', 'dispensar_revisao',
        ];

        $achadas = array_values(array_filter(
            $PALAVRAS_PROIBIDAS,
            fn (string $p) => in_array($p, PubAgenteConfiguracao::CHAVES_DA_VERSAO, true),
        ));
        $this->assertSame([], $achadas, 'a configuração do agente ganhou uma chave que desliga a revisão humana: '.implode(', ', $achadas));

        // ⚠️ E a VALIDAÇÃO recusa: mandar a chave não a grava. Esta é a prova que importa, porque o
        // `Validator` descarta chave desconhecida em SILÊNCIO — então "não está na lista" e "não
        // entra no banco" são duas perguntas, e esta é a segunda.
        $this->ligar();
        $cfg = app(ConfiguracaoDoAgenteService::class)->daConta($this->conta());

        $tentativa = ['habilitado' => true];
        foreach ($PALAVRAS_PROIBIDAS as $p) {
            $tentativa[$p] = true;
        }
        app(ConfiguracaoDoAgenteService::class)->salvar($this->conta(), $tentativa, (int) $cfg->versao, $this->admin);

        $depois = app(ConfiguracaoDoAgenteService::class)->daConta($this->conta())->fresh();
        $dadosDaVersao = $depois->dadosDaVersao();
        foreach ($PALAVRAS_PROIBIDAS as $p) {
            $this->assertArrayNotHasKey($p, $dadosDaVersao, "a chave `{$p}` entrou no snapshot da versão");
            $this->assertNull($depois->getAttribute($p), "a chave `{$p}` foi gravada no modelo");
        }

        // ⚠️ ISCA: uma chave LEGÍTIMA passa pelo mesmo caminho. Sem ela, um `salvar()` que não
        // gravasse nada faria todas as asserções acima passarem por engano.
        $this->assertTrue((bool) $depois->habilitado, 'o `salvar()` não gravou nem a chave legítima — as ausências acima passariam por engano');
    }
}
