<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\PubImagem;
use App\Models\PubImagemReferencia;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 186, plano 06 — O VÍNCULO DURÁVEL DA IMAGEM GERADA COM A ORIGINAL (DADO-15), e o
 * TERRITÓRIO do outro dev.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **N:N** — alguém transformar a tabela em coluna. DADO-15 aceita **mais de uma** original
 *     por imagem gerada; com uma coluna, o painel mostraria UMA das originais como se fosse a
 *     única — afirmação falsa sobre como a imagem nasceu.
 *   · **o par repetido** — a mesma original escolhida duas vezes para a mesma imagem. Não são
 *     duas referências, é um erro, e sem o unique o painel repetiria a mesma foto.
 *   · **`originaisDe()` sem vínculo** — alguém devolver vazio silencioso, zero, ou **a própria
 *     imagem de IA** no lugar da original. É o caso de TODO o acervo atual na estreia, e é
 *     exatamente o que IMG-13 proíbe (VOC-05: ícone **e** texto).
 *   · **o CASCADE** — apagar a imagem gerada deixar vínculo órfão apontando para `pub_imagens`
 *     que não existe mais (T-186-25); ou, pior, o CASCADE levar a ORIGINAL junto — ela é do
 *     cliente e **nada no Creative Engine a move ou apaga**.
 *   · **DADO-15 não é escrito nesta fase** — alguém começar a gravar aqui e a Fase 192 gravar
 *     outra vez, com duas rotinas livres para divergir. ⚠️ E a prova **NÃO é "a tabela tem 0
 *     linhas"**: com `RefreshDatabase` ela **nasce vazia em todo teste**, então aquela asserção é
 *     **VAZIA POR CONSTRUÇÃO** — passaria igual se o código escrevesse e o teste não exercitasse
 *     o caminho. A prova é a **ausência de ESCRITA no código**, com **isca de dois lados**.
 *   · **TERR-11** — alguém esticar a retenção de 48 h ou mexer no `creative:limpar-referencias`
 *     para "resolver" o vínculo efêmero. Resolveria o sintoma por algumas horas, continuaria
 *     perdendo o vínculo depois, e carregaria num requisito de produto uma decisão de custo e
 *     privacidade de OUTRA PESSOA (D-02 da v24.0).
 *   · **a bijeção manifesto ↔ métodos** — teste que existe no arquivo e **não tem id no
 *     manifesto NUNCA RODA**. Foi o sobrevivente da mutação do plano 186-03, e era **lacuna
 *     real**: é a forma nova de "passar por omissão".
 *
 * ⚠️ TODA SONDA TEM **ISCA**. E o diff de território usa **RANGE** (`<partida>..HEAD`): sem range,
 * `git diff -- <caminho>` depois de commitar compara a ÁRVORE e sai **VAZIO SEMPRE**
 * (`feedback_gate_vazio_por_construcao`).
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class VinculoDuravelDaOriginalTest extends TestCase
{
    use RefreshDatabase;

    /** A migration deste plano (Task 2). */
    private const MIGRATION = 'database/migrations/2026_10_15_100500_create_pub_imagem_referencias_table.php';

    /** ⚠️ ISCA DE CAMINHO: 5 caminhos fantasma em 4 fases deste projeto. */
    private const MIGRATION_FANTASMA = 'database/migrations/2026_10_15_999999_esta_migration_nao_existe.php';

    /** O commit de PARTIDA deste plano (wave 3), medido na PRIMEIRA ação do executor. */
    private const PARTIDA = '1c16d9e9';

    /**
     * ⚠️ TERR-11 — as peças da retenção de 48 h e o Creative Engine. **Diff VAZIO por range** em
     * todas. `app/Services/Creative` é DIRETÓRIO e entra na lista de propósito: diff por caminho
     * de diretório pega qualquer arquivo dentro dele.
     */
    private const TERRITORIO_DO_OUTRO_DEV = [
        'app/Services/Creative',
        'app/Services/Publicador/Criativos/PublicadorCriativoAprovacaoService.php',
        'app/Services/Publicador/Criativos/PublicadorCriativoReferenciaService.php',
        'app/Console/Commands/LimparReferenciasCriativos.php',
        'config/services.php',
    ];

    /**
     * ⚠️ TERR-01 — `App\Support\Publicador` é LIDO (o `ConferenciaDeFrete::ORIGENS` do plano
     * 186-06) e **nunca alterado**, nem com subdiretório novo.
     */
    private const SUPPORT_INTOCADO = 'app/Support/Publicador';

    /** A retenção MEDIDA em 14/10/2026 em `config/services.php:439`. */
    private const RETENCAO_HORAS = 48;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: PubImagem, 1: PubImagem, 2: PubImagem} [gerada, original A, original B] */
    private function tresImagens(): array
    {
        $empresa = Company::factory()->create();
        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => 'IMG-01',
            'nome' => 'Poltrona IMG-01',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
        $rascunho = PubRascunho::create(['produto_id' => $produto->id]);

        $cria = fn (string $nome) => PubImagem::create([
            'rascunho_id' => $rascunho->id,
            'caminho' => "pub/{$nome}.jpg",
            'sha256' => str_pad($nome, 64, '0'),
            'mime' => 'image/jpeg',
        ]);

        return [$cria('gerada'), $cria('origA'), $cria('origB')];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // DADO-15 — N:N, durável, NOSSO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_o_vinculo_aceita_duas_ou_mais_originais_e_recusa_o_par_repetido(): void
    {
        [$gerada, $a, $b] = $this->tresImagens();

        foreach ([[$a, 0], [$b, 1]] as $i => [$original, $posicao]) {
            PubImagemReferencia::create([
                'imagem_id' => $gerada->id,
                'referencia_imagem_id' => $original->id,
                'posicao' => $posicao,
                'gravado_em' => now(),
            ]);
        }

        // ⚠️ N:N DE PROPÓSITO (DADO-15: "aceita MAIS DE UMA original por imagem gerada"). É por
        // isto que é tabela e não coluna.
        $lido = PubImagemReferencia::originaisDe($gerada);
        $this->assertCount(2, $lido['originais'], 'o vínculo não comporta duas originais — com uma coluna, o painel mostraria UMA como se fosse a única');
        $this->assertNull($lido['estado'], 'com vínculo gravado, o estado declarado de IMG-13 não deve aparecer');
        $this->assertSame([$a->id, $b->id], $lido['originais']->pluck('id')->all(), 'a ORDEM de escolha das originais não foi preservada (`posicao`)');

        // ⚠️ E as duas pontas são `pub_imagens` — o vínculo é NOSSO, não do Creative Engine.
        $vinculo = PubImagemReferencia::where('imagem_id', $gerada->id)->orderBy('posicao')->first();
        $this->assertSame($gerada->id, $vinculo->imagem->id);
        $this->assertSame($a->id, $vinculo->original->id);
        $this->assertNull($vinculo->criativo_id, '`criativo_id` é opcional: imagem vinda de UPLOAD não tem criativo, e isso é correto');

        // ⚠️ O par repetido é ERRO, não duas referências: sem o unique o painel repetiria a foto.
        try {
            PubImagemReferencia::create([
                'imagem_id' => $gerada->id,
                'referencia_imagem_id' => $a->id,
                'posicao' => 9,
                'gravado_em' => now(),
            ]);
            $this->fail('o unique (imagem_id, referencia_imagem_id) não existe — o par repetido foi aceito');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('unique', $e->getMessage().' unique');
        }

        // ISCA: o par com a OUTRA imagem gerada passa — o unique é do PAR, não da original.
        $outraGerada = PubImagem::create(['rascunho_id' => $gerada->rascunho_id, 'caminho' => 'pub/g2.jpg', 'sha256' => str_pad('g2', 64, '0')]);
        PubImagemReferencia::create(['imagem_id' => $outraGerada->id, 'referencia_imagem_id' => $a->id, 'gravado_em' => now()]);
        $this->assertSame(3, PubImagemReferencia::count(), 'ISCA CEGA: o unique barrou a MESMA original em outra imagem gerada — ele está na coluna errada.');

        // ⚠️ E o caminho inverso existe: "em quais imagens geradas esta original foi usada".
        $this->assertSame(2, PubImagemReferencia::where('referencia_imagem_id', $a->id)->count());
    }

    public function test_originais_de_imagem_sem_vinculo_devolve_colecao_vazia_e_o_estado_declarado(): void
    {
        [$gerada] = $this->tresImagens();

        $lido = PubImagemReferencia::originaisDe($gerada);

        // ⚠️⚠️ IMG-13 — **nunca** painel vazio, **nunca** a imagem de IA repetida no lugar da
        // original, **nunca** zero. O estado é DECLARADO e tem nome.
        $this->assertTrue($lido['originais']->isEmpty());
        $this->assertSame(PubImagemReferencia::SEM_ORIGINAL, $lido['estado'], 'o estado declarado de IMG-13 não veio — a tela ficaria vazia sem dizer por quê');
        $this->assertNotContains($gerada->id, $lido['originais']->pluck('id')->all(), 'a própria imagem GERADA voltou como se fosse a original — é a afirmação falsa que IMG-13 proíbe');

        // ⚠️ VOC-05 — ÍCONE **E** TEXTO. Rótulo e frase de apoio não vazios, e a frase diz o
        // MOTIVO (ela é anterior ao registro), não um "não disponível" seco.
        $this->assertNotSame('', trim(PubImagemReferencia::SEM_ORIGINAL_ROTULO));
        $this->assertNotSame('', trim(PubImagemReferencia::SEM_ORIGINAL_APOIO));
        $this->assertNotSame('', trim(PubImagemReferencia::SEM_ORIGINAL_ICONE), 'VOC-05 exige ícone E texto — sem ícone a ausência parece erro de carregamento');
        $this->assertStringContainsString('referência', mb_strtolower(PubImagemReferencia::SEM_ORIGINAL_ROTULO));
        $this->assertMatchesRegularExpression('/anterior/u', PubImagemReferencia::SEM_ORIGINAL_APOIO, 'a frase de apoio não explica POR QUE a original não está lá');

        // ⚠️ A chave de dado é identificador e NUNCA é o rótulo (VOC-06).
        $this->assertMatchesRegularExpression('/^[a-z_]+$/', PubImagemReferencia::SEM_ORIGINAL);
        $this->assertNotSame(PubImagemReferencia::SEM_ORIGINAL, PubImagemReferencia::SEM_ORIGINAL_ROTULO);

        // ISCA: COM vínculo, o estado desaparece (senão o estado seria constante e não mediria nada).
        [, $a] = $this->tresImagens();
        PubImagemReferencia::create(['imagem_id' => $gerada->id, 'referencia_imagem_id' => $a->id, 'gravado_em' => now()]);
        $this->assertNull(PubImagemReferencia::originaisDe($gerada)['estado'], 'ISCA CEGA: o estado `sem_original` vem até COM vínculo gravado.');
    }

    public function test_apagar_a_imagem_gerada_apaga_o_vinculo_e_nao_apaga_a_original(): void
    {
        [$gerada, $a, $b] = $this->tresImagens();

        PubImagemReferencia::create(['imagem_id' => $gerada->id, 'referencia_imagem_id' => $a->id, 'gravado_em' => now()]);
        PubImagemReferencia::create(['imagem_id' => $gerada->id, 'referencia_imagem_id' => $b->id, 'gravado_em' => now()]);
        $this->assertSame(2, PubImagemReferencia::count());

        $gerada->delete();

        // ⚠️ T-186-25 — CASCADE: sem ele o vínculo ficaria órfão apontando para linha que não
        // existe mais, e o painel tentaria abrir uma imagem apagada.
        $this->assertSame(0, PubImagemReferencia::count(), 'o vínculo da imagem apagada ficou órfão (CASCADE ausente)');

        // ⚠️⚠️ E AS ORIGINAIS CONTINUAM: elas são do CLIENTE e **nada no Creative Engine as move
        // ou apaga** — o docblock de `PublicadorCriativoReferenciaService:19-32` é textual nisso,
        // e é essa durabilidade que faz o vínculo DADO-15 poder existir.
        $this->assertNotNull($a->fresh(), 'apagar a imagem GERADA levou a ORIGINAL do cliente junto');
        $this->assertNotNull($b->fresh());

        // ISCA do outro lado: apagar a ORIGINAL também derruba o vínculo (as duas FKs são
        // CASCADE), e a gerada sobrevive.
        [$g2, $a2] = $this->tresImagens();
        PubImagemReferencia::create(['imagem_id' => $g2->id, 'referencia_imagem_id' => $a2->id, 'gravado_em' => now()]);
        $a2->delete();
        $this->assertSame(0, PubImagemReferencia::count(), 'ISCA CEGA: a FK da ORIGINAL não é CASCADE — sobraria vínculo órfão pelo outro lado.');
        $this->assertNotNull($g2->fresh());

        // E a tela do caso resultante é o estado declarado, nunca vazio silencioso.
        $this->assertSame(PubImagemReferencia::SEM_ORIGINAL, PubImagemReferencia::originaisDe($g2)['estado']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ DADO-15: ESTA FASE **NÃO ESCREVE** — E A PROVA NÃO É "0 LINHAS"
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_dado_15_esta_fase_nao_escreve_o_vinculo_com_isca_de_dois_lados(): void
    {
        // ⚠️ `test -e` antes de acreditar em qualquer vazio: modelo ausente faria a sonda devolver
        // "nenhuma escrita" e o gate ficaria verde medindo NADA.
        $this->assertFileExists(base_path('app/Models/PubImagemReferencia.php'));

        $escritas = ['create', 'insert', 'insertGetId', 'updateOrCreate', 'firstOrCreate', 'upsert', 'forceCreate'];
        $sonda = fn (string $modelo) => '/\b'.preg_quote($modelo, '/').'::(?:'.implode('|', $escritas).')\s*\(/';

        // Varredura de TODO o `app/`, lendo o CÓDIGO sem comentários (o docblock da migration e do
        // modelo **fala** de escrita e das fases donas; um gate que conte a palavra no arquivo
        // inteiro reprovaria pelo PRÓPRIO CONSERTO).
        $arquivos = $this->phpDe(base_path('app'));
        $this->assertGreaterThan(300, count($arquivos), 'ISCA CEGA: a varredura de `app/` achou quase nada — o gate rodaria sobre lista vazia.');

        $achadosNoMeu = [];
        $achadosNaIsca = [];
        $viaTabela = [];
        foreach ($arquivos as $arquivo) {
            $codigo = $this->semComentarios(file_get_contents($arquivo));
            $rel = str_replace('\\', '/', substr($arquivo, strlen(base_path()) + 1));

            if (preg_match($sonda('PubImagemReferencia'), $codigo)) {
                $achadosNoMeu[] = $rel;
            }
            // ⚠️ ISCA DE DOIS LADOS (a): a MESMA sonda, apontada para um modelo que o `app/`
            // REALMENTE escreve, tem de ACHAR. Senão o padrão de busca está errado e o "zero"
            // acima significa só que a regex não casa nada.
            foreach (['PubProduto', 'PubRascunho'] as $iscaModelo) {
                if (preg_match($sonda($iscaModelo), $codigo)) {
                    $achadosNaIsca[$iscaModelo][] = $rel;
                }
            }
            // E a outra forma de escrever: `DB::table('pub_imagem_referencias')->insert(...)`.
            if (preg_match("/table\(\s*'pub_imagem_referencias'\s*\)/", $codigo)) {
                $viaTabela[] = $rel;
            }
        }

        // ⚠️ A PROVA: nenhuma escrita no código. **Não** "a tabela tem 0 linhas" — com
        // `RefreshDatabase` ela nasce vazia em todo teste, e aquela asserção passaria igual se o
        // código escrevesse e o teste não exercitasse o caminho (vazio por construção).
        $this->assertSame([], $achadosNoMeu, 'ESTA FASE NÃO ESCREVE `pub_imagem_referencias` — a escrita é das Fases 192/194. Apareceu escrita em: '.implode(', ', $achadosNoMeu));
        $this->assertSame([], $viaTabela, 'apareceu `DB::table(\'pub_imagem_referencias\')` em `app/`: '.implode(', ', $viaTabela));

        // ⚠️ ISCA DE DOIS LADOS (b): os dois modelos de controle TÊM de ter sido achados.
        foreach (['PubProduto', 'PubRascunho'] as $iscaModelo) {
            $this->assertNotEmpty(
                $achadosNaIsca[$iscaModelo] ?? [],
                "ISCA CEGA: a sonda não acha escrita de `{$iscaModelo}`, que o `app/` REALMENTE escreve — o padrão de busca está errado e o zero acima não prova nada."
            );
        }

        // ⚠️ ISCA DO TOKENIZADOR: um texto sintético prova que a sonda acha a escrita em CÓDIGO e
        // IGNORA a mesma frase em comentário.
        $emCodigo = "<?php PubImagemReferencia::create(['imagem_id' => 1]);";
        $emComentario = "<?php\n// A ESCRITA de PubImagemReferencia::create() é das Fases 192/194.\n\$x = 1;";
        $this->assertSame(1, preg_match($sonda('PubImagemReferencia'), $this->semComentarios($emCodigo)), 'ISCA CEGA: a sonda não acha a escrita nem no caso canônico.');
        $this->assertSame(0, preg_match($sonda('PubImagemReferencia'), $this->semComentarios($emComentario)), 'ISCA CEGA: a sonda casa a frase do COMENTÁRIO — o gate reprovaria pelo próprio conserto.');

        // ⚠️ E AS FASES DONAS **NOMEADAS** nos dois docblocks — é isso que faz "nada sem destino"
        // (PUB-10) ser mecânico em vez de promessa.
        foreach ([self::MIGRATION, 'app/Models/PubImagemReferencia.php'] as $arquivo) {
            $cru = file_get_contents(base_path($arquivo));
            $this->assertMatchesRegularExpression('/Fases?\s+192/u', $cru, "`{$arquivo}` não nomeia a Fase 192 como dona da escrita");
            $this->assertMatchesRegularExpression('/194/u', $cru, "`{$arquivo}` não nomeia a Fase 194 como dona da escrita");
        }
        $this->assertStringContainsString('ESCRITA NAS FASES 192/194', file_get_contents(base_path(self::MIGRATION)), 'o bloco (2) obrigatório do docblock não está em maiúsculas');

        // ⚠️ ISCA DA FASE DONA: a mesma sonda NÃO aceita uma fase inventada. Sem isto, um regex
        // frouxo (`/Fase \d+/`) daria "dona declarada" para qualquer número.
        $sintetico = '/** A escrita é da Fase 999. */';
        $this->assertDoesNotMatchRegularExpression('/Fases?\s+192/u', $sintetico, 'ISCA CEGA: a sonda da fase dona aceita "Fase 999" — ela não está medindo QUAL fase.');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ TERR-11 / TERR-01 — O TERRITÓRIO DO OUTRO DEV, POR RANGE
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_terr_11_a_retencao_de_48h_e_o_creative_engine_estao_intocados_por_range(): void
    {
        // ⚠️ `test -e` ANTES de acreditar no vazio: caminho fantasma já "provou" território
        // intocado 6 vezes neste projeto — o diff de um caminho que não existe sai vazio.
        foreach (self::TERRITORIO_DO_OUTRO_DEV as $caminho) {
            $this->assertFileExists(base_path($caminho), "caminho declarado e AUSENTE: o diff sairia vazio e o gate ficaria verde tendo apagado território alheio");
        }
        $this->assertDirectoryExists(base_path('app/Services/Creative'));
        $this->assertDirectoryExists(base_path(self::SUPPORT_INTOCADO));

        // ⚠️⚠️ COM **RANGE**. `git diff -- <caminho>` depois de commitar compara a ÁRVORE e sai
        // **VAZIO POR CONSTRUÇÃO** (`feedback_gate_vazio_por_construcao`).
        foreach (self::TERRITORIO_DO_OUTRO_DEV as $caminho) {
            $diff = $this->gitDiff($caminho);
            $this->assertSame('', $diff, "este plano mexeu em `{$diff}` — TERR-11: a retenção de 48 h e o Creative Engine são do OUTRO DEV, e a saída certa é o vínculo durável NOSSO, nunca esticar a retenção alheia");
        }

        // ⚠️ TERR-01 — `App\Support\Publicador` é LIDO e nunca alterado, **nem com subdiretório
        // novo** (por isso o diff é no DIRETÓRIO).
        $this->assertSame('', $this->gitDiff(self::SUPPORT_INTOCADO), 'este plano escreveu em `app/Support/Publicador/` — ele é LIDO (o `ConferenciaDeFrete::ORIGENS`) e nunca alterado');

        // ⚠️ ISCA DO DIFF: o MESMO comando, nos arquivos que ESTE plano criou, tem de sair NÃO
        // vazio. Sem ela, um `git` que falhasse em silêncio devolveria string vazia para TUDO e o
        // gate seria verde permanente — medindo nada.
        foreach ([self::MIGRATION, 'database/migrations/2026_10_15_100400_create_pub_aprovacao_financeiro_tables.php'] as $meu) {
            $this->assertNotSame('', $this->gitDiff($meu), "ISCA CEGA: o diff por range não acha nem `{$meu}`, que este plano criou.");
        }

        // ⚠️ E A RETENÇÃO EM SI, por VALOR: asserção positiva com o número medido em 14/10/2026.
        $this->assertSame(self::RETENCAO_HORAS, (int) config('services.creative.retencao_referencias_horas'), 'a janela de retenção mudou de valor — se parecer necessário mexer nela: PARAR e devolver a pergunta (D-02 da v24.0, custo e privacidade)');

        // ISCA DO LEITOR DE CONFIG: uma chave inexistente TEM de dar `null`. Sem ela, um `config()`
        // que devolvesse 48 para qualquer coisa faria a asserção acima passar medindo nada.
        $this->assertNull(config('services.creative.esta_chave_nao_existe_de_proposito'), 'ISCA CEGA: `config()` devolve valor para chave inventada.');

        // E o comando da varredura segue sendo o dono da janela — a assinatura dele não mudou.
        $comando = file_get_contents(base_path('app/Console/Commands/LimparReferenciasCriativos.php'));
        $this->assertStringContainsString('creative:limpar-referencias', $comando, 'o nome do comando da varredura mudou — ele é território do outro dev');
        $this->assertStringContainsString('retencao_referencias_horas', $comando.file_get_contents(base_path('config/services.php')));

        // ⚠️ NÃO conferir AUTOR de commit para distinguir sessões: as duas usam o MESMO
        // `user.name`. O discriminador é o ESCOPO DO ASSUNTO (`feat(186-06): …`).
        $meus = $this->gitLog();
        $this->assertNotSame('', $meus, 'ISCA CEGA: o log por range não acha nem os commits deste plano.');
        foreach (explode("\n", $meus) as $linha) {
            $this->assertMatchesRegularExpression('/\(186-06\)/', $linha, "o commit `{$linha}` está no range deste plano e NÃO tem o escopo 186-06 — ou o range está errado, ou este plano commitou assunto alheio");
        }

        // ⚠️ `ROADMAP.md`, `REQUIREMENTS-v27.md` e `STATE.md` não pertencem a plano nenhum e ficam
        // FORA do gate de território — por isso não estão na lista acima. Esta asserção registra
        // a decisão para ninguém "completar" a lista depois.
        foreach (['.planning/ROADMAP.md', '.planning/REQUIREMENTS-v27.md', '.planning/STATE.md'] as $fora) {
            $this->assertNotContains($fora, self::TERRITORIO_DO_OUTRO_DEV);
        }
    }

    /** `git diff <partida>..HEAD --name-only -- <caminho>`, com RANGE. */
    private function gitDiff(string $caminho): string
    {
        $saida = shell_exec(sprintf(
            'git -C %s diff %s..HEAD --name-only -- %s 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg(self::PARTIDA),
            escapeshellarg($caminho)
        ));

        return trim((string) $saida);
    }

    /** Os commits do range deste plano, um por linha (assunto só). */
    private function gitLog(): string
    {
        $saida = shell_exec(sprintf(
            'git -C %s log --format=%%s %s..HEAD 2>&1',
            escapeshellarg(base_path()),
            escapeshellarg(self::PARTIDA)
        ));

        return trim((string) $saida);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️⚠️ A BIJEÇÃO ids DO MANIFESTO ↔ MÉTODOS DOS ARQUIVOS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_todo_metodo_deste_plano_tem_id_no_manifesto_e_todo_id_casa_um_metodo(): void
    {
        // ⚠️⚠️ TESTE QUE EXISTE NO ARQUIVO E NÃO TEM id NO MANIFESTO **NUNCA RODA** em gate
        // nenhum. Foi o sobrevivente da campanha de mutação do plano 186-03 — o método que media
        // o nome DECLARADO do índice existia e nenhum gate o invocava, e era **lacuna real**. É a
        // forma nova de "passar por omissão", e é por isso que a bijeção é medida por SCRIPT.
        $manifesto = base_path('tests/gates/contagens-186-06.txt');
        $this->assertFileExists($manifesto);

        $arquivos = [
            base_path('tests/Feature/Publicador/Aprovacao/FreteInformadoTest.php'),
            base_path('tests/Feature/Publicador/Aprovacao/VinculoDuravelDaOriginalTest.php'),
        ];

        $metodos = [];
        foreach ($arquivos as $arquivo) {
            $this->assertFileExists($arquivo);
            preg_match_all('/public function (test_[a-z0-9_]+)\(/', file_get_contents($arquivo), $m);
            $metodos = array_merge($metodos, $m[1]);
        }
        sort($metodos);

        // ISCA: a sonda de métodos acha os testes de verdade (e me acha a mim).
        $this->assertGreaterThanOrEqual(15, count($metodos), 'ISCA CEGA: a sonda achou menos métodos do que os dois arquivos têm — a bijeção rodaria sobre lista curta.');
        $this->assertContains('test_todo_metodo_deste_plano_tem_id_no_manifesto_e_todo_id_casa_um_metodo', $metodos, 'ISCA CEGA: a sonda não acha nem o próprio método da bijeção.');

        // Os filtros do manifesto (3ª coluna das linhas `php`).
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

        // (1) ⚠️ TODO MÉTODO TEM DE SER COBERTO por pelo menos um filtro.
        $semId = [];
        foreach ($metodos as $metodo) {
            $coberto = false;
            foreach ($filtros as $filtro) {
                // O filtro do helper é um regex do PHPUnit (`test_(a|b|c)` ou `test_x`).
                if (preg_match('/^(?:'.$filtro.')$/', $metodo)) {
                    $coberto = true;
                    break;
                }
            }
            if (! $coberto) {
                $semId[] = $metodo;
            }
        }
        $this->assertSame([], $semId, 'MÉTODO SEM id NO MANIFESTO — ele NUNCA RODA em gate nenhum (o sobrevivente da mutação do 186-03): '.implode(', ', $semId));

        // (2) ⚠️ E TODO id TEM DE CASAR pelo menos um método — id órfão é gate que roda sobre
        // ZERO casos, e gate que roda sobre zero casos não prova nada.
        $orfaos = [];
        foreach ($filtros as $id => $filtro) {
            $casou = array_filter($metodos, fn ($m) => (bool) preg_match('/^(?:'.$filtro.')$/', $m));
            if ($casou === []) {
                $orfaos[] = $id;
            }
        }
        $this->assertSame([], $orfaos, 'id do manifesto que não casa método nenhum (gate sobre ZERO casos): '.implode(', ', $orfaos));

        // ⚠️ ISCA DO CASADOR, nos dois sentidos: ele casa um método de alternância e NÃO casa um
        // prefixo. Sem esta isca, um casador frouxo diria "tudo coberto" e um estrito diria "nada".
        $this->assertSame(1, preg_match('/^(?:test_(a|b))$/', 'test_b'), 'ISCA CEGA: o casador não entende alternância — a bijeção daria falso negativo.');
        $this->assertSame(0, preg_match('/^(?:test_a)$/', 'test_abc'), 'ISCA CEGA: o casador casa por PREFIXO — um id cobriria métodos que ele nunca roda.');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A DISCIPLINA DA MIGRATION (MIG-01..09)
    // ═════════════════════════════════════════════════════════════════════════════════════════

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

    /** @return list<string> todos os `.php` sob um diretório. */
    private function phpDe(string $raiz): array
    {
        $saida = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $arquivo) {
            if ($arquivo->isFile() && $arquivo->getExtension() === 'php') {
                $saida[] = $arquivo->getPathname();
            }
        }
        sort($saida);

        return $saida;
    }

    private function textoDaMigration(): string
    {
        $abs = base_path(self::MIGRATION);
        $this->assertFileExists($abs, 'migration declarada e ausente no disco — toda sonda de texto mediria NADA');
        $this->assertFileDoesNotExist(base_path(self::MIGRATION_FANTASMA), 'ISCA DE CAMINHO: `file_exists` passou a devolver `true` para caminho inventado');

        return file_get_contents($abs);
    }

    public function test_guarda_do_vinculo_nomes_de_64_e_as_duas_fks_para_a_mesma_tabela(): void
    {
        $texto = $this->textoDaMigration();

        preg_match_all("/->(?:unique|index|foreign)\(\s*(?:'[^']*'|\[[^\]]*\])\s*,\s*'([^']+)'\s*\)/", $texto, $m);
        $declarados = $m[1];

        // ISCA DA SONDA: os 5 nomes declarados (3 índices + 2 FKs).
        $this->assertCount(5, $declarados, 'ISCA CEGA: a sonda não achou os 5 nomes declarados (3 índices + 2 FKs).');
        $this->assertContains('pubimref_par_uq', $declarados);
        $this->assertContains('pubimref_imagem_fk', $declarados);
        $this->assertContains('pubimref_orig_fk', $declarados);

        foreach ($declarados as $nome) {
            $this->assertLessThanOrEqual(64, strlen($nome), "o nome DECLARADO `{$nome}` tem ".strlen($nome).' caracteres (erro 1059 do MariaDB)');
        }
        $maior = array_reduce($declarados, fn ($c, $n) => strlen($n) > strlen((string) $c) ? $n : $c, '');
        $this->assertSame('pubimref_criativo_ix', $maior);
        $this->assertSame(20, strlen($maior));

        // ⚠️ OS NOMES PADRÃO DO LARAVEL — e o maior deles é o que o plano escreveu com margem de
        // erro ("50 ou 51"). MEDIDO por `strlen`: **51**. É a razão de nada ser contado à mão.
        $tabela = 'pub_imagem_referencias';
        $this->assertSame(22, strlen($tabela));
        $padrao = [];
        foreach (['imagem_id', 'referencia_imagem_id', 'criativo_id'] as $coluna) {
            $this->assertTrue(Schema::hasColumn($tabela, $coluna), "a coluna `{$coluna}` sumiu — o cálculo do nome padrão mediria o nada");
            $padrao[$coluna] = strlen("{$tabela}_{$coluna}_foreign");
            $this->assertLessThanOrEqual(64, $padrao[$coluna]);
        }
        $this->assertSame(51, $padrao['referencia_imagem_id'], 'o nome PADRÃO da FK da original mudou de tamanho — o plano dizia "50 ou 51" e o MEDIDO é 51');
        $this->assertSame(51, max($padrao));

        // ⚠️ DUAS FKs PARA A MESMA TABELA, as duas nomeadas à mão e as duas CASCADE.
        $regexFk = "/->foreign\(\s*'([^']+)'\s*,\s*'([^']+)'\s*\)(.*?);/s";
        preg_match_all($regexFk, $this->semComentarios($texto), $fks, PREG_SET_ORDER);
        $this->assertCount(2, $fks, 'ISCA CEGA: a sonda não achou as 2 FKs.');
        $paraPubImagens = 0;
        foreach ($fks as $fk) {
            $this->assertStringContainsString("on('pub_imagens')", $fk[3], "a FK `{$fk[2]}` não aponta para `pub_imagens` — é dali que vem a durabilidade do vínculo");
            $this->assertStringContainsString('cascadeOnDelete', $fk[3], "a FK `{$fk[2]}` não é CASCADE (T-186-25)");
            $paraPubImagens++;
        }
        $this->assertSame(2, $paraPubImagens);

        // ⚠️ ISCA DO REGEX: um caso que DEVE casar (era o bug de `->foreignI` na wave 1).
        preg_match_all($regexFk, "<?php \$t->foreign('x_id', 'xx_fk')->references('id')->on('pub_imagens')->cascadeOnDelete();", $certo, PREG_SET_ORDER);
        $this->assertCount(1, $certo, 'ISCA CEGA: o regex de FK não casa nem o caso canônico.');

        // ⚠️ `criativo_id` É SEM FK (TERR-11 / T-186-23): DDL não encosta em `ml_anuncio_criativos`.
        $this->assertStringNotContainsString('ml_anuncio_criativos', $this->semComentarios($texto), 'esta migration menciona `ml_anuncio_criativos` no CÓDIGO — DDL encostando na tabela do outro dev (T-186-23)');
        $this->assertStringContainsString('ml_anuncio_criativos', $texto, 'ISCA: o docblock deixou de explicar por que `criativo_id` é sem FK — o gate acima ficaria verde por outro motivo');
        $this->assertTrue($this->anulavel('criativo_id'), '`criativo_id` tem de ser anulável: imagem vinda de UPLOAD não tem criativo');

        // ⚠️ E O SCHEMA VIVO: nenhum índice caiu em nome PADRÃO.
        $nomes = array_map(fn ($i) => $i['name'], Schema::getIndexes($tabela));
        $this->assertNotEmpty($nomes, 'ISCA CEGA: a varredura não achou índice nenhum.');
        foreach ($nomes as $nome) {
            $this->assertLessThanOrEqual(64, strlen($nome));
            $this->assertStringNotContainsString('_foreign', $nome, "o índice VIVO `{$nome}` caiu em nome PADRÃO — alguém usou `->constrained()`");
        }
        $this->assertStringNotContainsString('constrained(', $this->semComentarios($texto));
    }

    private function anulavel(string $coluna): bool
    {
        foreach (Schema::getColumns('pub_imagem_referencias') as $c) {
            if (($c['name'] ?? null) === $coluna) {
                return (bool) ($c['nullable'] ?? false);
            }
        }

        $this->fail("coluna `{$coluna}` não existe em `pub_imagem_referencias`");
    }

    public function test_guarda_do_vinculo_sem_enum_sem_try_e_a_sonda_do_1830_que_rodaria_sobre_zero_casos(): void
    {
        $cru = $this->textoDaMigration();
        $codigo = $this->semComentarios($cru);

        // ⚠️⚠️ Ler o CÓDIGO sem comentários: o docblock EXPLICA que não usa `enum` nem
        // `try/catch`, e um gate que conte a palavra no arquivo inteiro reprova o PRÓPRIO CONSERTO.
        $this->assertStringNotContainsString('enum', $codigo, 'MIG-04: `enum` no CÓDIGO — o CHECK é enforçado no SQLite e cegaria a suíte');
        $this->assertStringNotContainsString('try', $codigo, 'MIG-05: `try` no CÓDIGO');
        $this->assertStringNotContainsString('catch', $codigo);
        $this->assertStringContainsString('enum', $cru, 'ISCA: o docblock deixou de explicar por que não há `enum`');
        $this->assertStringContainsString('try/catch', $cru, 'ISCA: o docblock deixou de explicar por que não há try/catch');

        // ISCA DO TOKENIZADOR, nos dois sentidos.
        $soComentario = "<?php\n// Sem `enum()` e sem try/catch em volta de DDL.\n\$t->dateTime('gravado_em');\n";
        $this->assertStringNotContainsString('enum', $this->semComentarios($soComentario), 'ISCA CEGA: o tokenizador não tirou a palavra do comentário.');
        $this->assertStringContainsString("\$t->dateTime('gravado_em')", $this->semComentarios($soComentario), 'ISCA: o tokenizador comeu o CÓDIGO junto.');
        $this->assertStringContainsString('enum', $this->semComentarios("<?php \$t->enum('x', ['a']);"), 'ISCA CEGA: a palavra em CÓDIGO tem de sobreviver.');

        // ⚠️⚠️ A SONDA DO **1830** RODARIA SOBRE **ZERO CASOS** AQUI: não há `nullOnDelete` nesta
        // migration (as duas FKs são CASCADE e NOT NULL, e `criativo_id` não tem FK). **Gate que
        // roda sobre zero casos não prova nada**, então ela fica COM ISCA SINTÉTICA e o invariante
        // que VALE tem asserção própria.
        $this->assertSame(0, substr_count($codigo, 'nullOnDelete'), 'apareceu `nullOnDelete` nesta migration — então o par com `->nullable()` explícito passa a ser obrigatório (erro 1830, invisível no SQLite)');

        // ISCA SINTÉTICA do 1830: num texto aditivo a sonda TEM de achar o par e ver a violação.
        $sintetico = "<?php \$t->unsignedBigInteger('quem_id'); \$t->foreign('quem_id','x_fk')->references('id')->on('users')->nullOnDelete();";
        $this->assertSame(1, substr_count($sintetico, 'nullOnDelete'), 'ISCA CEGA: a sonda não acha `nullOnDelete` nem no caso canônico.');
        $this->assertDoesNotMatchRegularExpression("/->unsignedBigInteger\('quem_id'\)->nullable\(\)/", $sintetico, 'ISCA CEGA: a sonda do 1830 não acusa a coluna NOT NULL com FK `nullOnDelete`.');

        // ⚠️ O INVARIANTE QUE VALE AQUI: as duas colunas de FK são NOT NULL (vínculo sem uma das
        // pontas não é vínculo) e `criativo_id` é anulável e SEM FK.
        $this->assertFalse($this->anulavel('imagem_id'));
        $this->assertFalse($this->anulavel('referencia_imagem_id'));
        $this->assertTrue($this->anulavel('criativo_id'));

        // MIG-05 / MIG-07.
        $this->assertStringContainsString("Schema::hasTable('pub_imagem_referencias')", $codigo, 'MIG-05: o `up()` deixou de ser idempotente');
        $this->assertStringContainsString('DECISÃO DE SCHEMA', $cru);
        $this->assertLessThan(strpos($cru, 'Schema::create('), strpos($cru, 'DECISÃO DE SCHEMA'), 'a tabela de decisão de schema tem de vir ANTES do código');

        // ⚠️ OS TRÊS BLOCOS OBRIGATÓRIOS do docblock, cada um com o que o torna verificável.
        // (1) por que existe — os nomes MEDIDOS do vínculo efêmero.
        foreach (['ml_anuncio_criativos.referencias', 'criativoAprovar()', 'LimparReferenciasCriativos.php', 'retencao_referencias_horas', 'referencias_apagadas_em', 'referenciasVivas()'] as $nome) {
            $this->assertStringContainsString($nome, $cru, "o bloco (1) não cita `{$nome}` — sem os nomes medidos a afirmação não é reconferível");
        }
        // (2) a escrita é das Fases 192/194 (já medido no gate do DADO-15, aqui o literal).
        $this->assertStringContainsString('ESCRITA NAS FASES 192/194', $cru);
        // (3) TERR-11 com a RAZÃO.
        $this->assertStringContainsString('TERR-11', $cru);
        $this->assertMatchesRegularExpression('/D-02/u', $cru, 'o bloco (3) não cita a decisão D-02 da v24.0');
        $this->assertMatchesRegularExpression('/ECF Drive/u', $cru, 'o bloco (3) não cita o incidente do ECF Drive, que é o motivo do desenho');
        $this->assertMatchesRegularExpression('/PARAR e devolver a pergunta|PARE e devolva/u', $cru, 'o bloco (3) não diz o que fazer se parecer necessário mexer na retenção');

        // ⚠️ E o registro do fato de IMG-13: nenhum backfill é possível, e na estreia vale para
        // TODO o acervo.
        $this->assertMatchesRegularExpression('/NENHUM BACKFILL|backfill.{0,40}imposs/iu', $cru, 'o docblock não registra que nenhum backfill é possível');
        $this->assertStringContainsString('IMG-13', $cru);
        $this->assertMatchesRegularExpression('/TODO o acervo/u', $cru, 'o docblock não registra que na estreia isso vale para TODO o acervo');

        // MIG-09 e o `down()`.
        foreach (['->change(', 'renameColumn(', 'dropColumn(', '->delete(', 'truncate('] as $destrutivo) {
            $this->assertStringNotContainsString($destrutivo, $codigo, "MIG-09: `{$destrutivo}` numa migration que só CRIA");
        }
        $inicio = strpos($codigo, 'function down()');
        $this->assertNotFalse($inicio);
        $down = substr($codigo, $inicio);
        $this->assertSame(1, substr_count($down, 'dropIfExists('));
        $this->assertStringContainsString("Schema::dropIfExists('pub_imagem_referencias')", $down);
        $this->assertSame(0, substr_count($down, 'dropIndex('), 'apareceu `dropIndex` num `down()` de `create` (erro 1553 sem o par)');
        $this->assertSame(0, substr_count($down, 'dropForeign('));
        $this->assertStringNotContainsString("dropIfExists('pub_imagens')", $down, 'o `down()` derrubaria `pub_imagens`, que é a MÃE e é de outro plano');
        $this->assertTrue(Schema::hasTable('pub_imagens'), 'a tabela-mãe `pub_imagens` não existe — as duas FKs apontam para o vazio');
        $this->assertStringNotContainsString('ml_acervo_itens', $cru, 'esta migration encosta em `ml_acervo_itens` — PARAR e ler `.planning/debug/resolved/acervo-deadlock-upsert.md` antes');
    }
}
