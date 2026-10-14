<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 * GATE-01 — O QUE PRECISARIA ESTAR ERRADO NO MUNDO PARA ESTE GATE FALHAR?
 *
 *   **Alguém gravar a aprovação da foto POR OFERTA** — acrescentando `conjunto_id` em
 *   `pub_aprovacao_ofertas`, ou criando uma tabela `(oferta_id, imagem_id)` — e o Premium
 *   passar a precisar de aprovação própria da MESMA foto que o Clássico já aprovou. A tela
 *   continuaria verde no Clássico, o Premium ficaria bloqueado sem motivo visível, e o
 *   atendimento aprovaria a foto duas vezes sem entender por quê.
 *
 *   **E o caso grave:** com `conjunto_id` anulável na oferta, o bloqueio de fotos leria UM
 *   grupo só — as fotos das outras cores e as da galeria geral ficariam FORA do bloqueio, e
 *   uma oferta poderia ser aprovada com foto não conferida. ⚠️ Como **aprovar já publica e
 *   não há desfazer** (decisão do usuário, 14/10/2026), isso é exatamente a falha que os
 *   bloqueios existem para impedir. É o achado S2, e é o que os testes de eixo variante pegam.
 *
 * ⚠️ NENHUMA asserção aqui chama rotina de propagação, porque não existe nenhuma: o
 * compartilhamento é ESTRUTURAL (a aprovação mora no conjunto, que é do rascunho, e o alvo não
 * é input de nada). Os testes `test_estrutural_*` provam a ausência de coluna onde divergir —
 * e provam COM ISCA, porque "a coluna não existe" é vazio por construção sem um controle que
 * tenha de reprovar.
 *
 * ⚠️ REND-08: todo `quando` é gravado sob `Carbon::setTestNow()`. Teste dependente de horário
 * já reprovou por horário neste projeto.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 */
class ConjuntoCompartilhadoTest extends TestCase
{
    use RefreshDatabase;

    /** Clássico e Premium — `EstruturaPublicacao::LISTING_TYPES`. */
    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture: o grafo mínimo REAL — produto -> rascunho -> 2 alvos.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function rascunhoComOsDoisAlvos(string $sku = 'CAD-01', ?int $baseId = null, int $qtdKit = 1): PubRascunho
    {
        $empresa = Company::factory()->create();

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => $sku,
            'nome' => 'Cadeira ' . $sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $baseId,
            'quantidade_kit' => $qtdKit,
        ]);

        $rascunho = PubRascunho::create(['produto_id' => $produto->id]);

        foreach ([self::CLASSICO, self::PREMIUM] as $i => $tipo) {
            PubRascunhoAlvo::create(['rascunho_id' => $rascunho->id, 'listing_type_id' => $tipo, 'posicao' => $i]);
        }

        return $rascunho;
    }

    private function conjunto(PubRascunho $r, string $grupoChave = PubAprovacaoConjunto::GERAL): PubAprovacaoConjunto
    {
        return PubAprovacaoConjunto::create([
            'rascunho_id' => $r->id,
            'grupo_chave' => $grupoChave,
            'grupo_hash' => ChaveCanonica::hash($grupoChave),
        ]);
    }

    private function imagemNaRevisao(PubAprovacaoConjunto $c, string $estado = PubAprovacaoImagem::PENDENTE, ?int $posicao = 0): PubAprovacaoImagem
    {
        $crua = PubImagem::create(['rascunho_id' => $c->rascunho_id]);

        return PubAprovacaoImagem::create([
            'conjunto_id' => $c->id,
            'imagem_id' => $crua->id,
            'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
            'estado' => $estado,
            'posicao' => $posicao,
        ]);
    }

    /**
     * O estado da foto COMO A OFERTA O LÊ. ⚠️ Repare no que NÃO entra: `$alvo` é recebido e
     * **não é usado na consulta**, porque não há por onde usá-lo — é esta assinatura que é a
     * prova do compartilhamento. Se um dia a aprovação passar a ser por oferta, este método
     * deixa de compilar sem mudar a consulta, e o teste quebra.
     */
    private function comoAOfertaLe(PubRascunhoAlvo $alvo, PubAprovacaoImagem $img): ?string
    {
        $conjuntos = PubAprovacaoConjunto::doRascunho($alvo->rascunho_id)->pluck('id');

        return PubAprovacaoImagem::query()
            ->whereIn('conjunto_id', $conjuntos)
            ->where('imagem_id', $img->imagem_id)
            ->value('estado');
    }

    /**
     * O bloqueio `photos` da oferta: a UNIÃO das fotos não aprovadas de **TODOS** os conjuntos
     * do rascunho, numa consulta só (`whereIn`), nunca uma por grupo.
     *
     * ⚠️ Decisão do usuário (14/10/2026): a oferta só fica aprovável com as fotos de todas as
     * variações **e** as gerais conferidas. Aprovar cor por cor foi RECUSADO.
     *
     * @return list<int> ids de `pub_aprovacao_imagens` ainda pendentes
     */
    private function pendentesDaOferta(PubRascunhoAlvo $alvo): array
    {
        $conjuntos = PubAprovacaoConjunto::doRascunho($alvo->rascunho_id)->pluck('id');

        return PubAprovacaoImagem::query()
            ->whereIn('conjunto_id', $conjuntos)
            ->where('estado', PubAprovacaoImagem::PENDENTE)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function aprovavel(PubRascunhoAlvo $alvo): bool
    {
        return $this->pendentesDaOferta($alvo) === [];
    }

    private function alvo(PubRascunho $r, string $tipo): PubRascunhoAlvo
    {
        return PubRascunhoAlvo::where('rascunho_id', $r->id)->where('listing_type_id', $tipo)->firstOrFail();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Critério 10 — aprovar UMA vez vale para os DOIS alvos, sem propagação
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_aprovar_a_foto_uma_vez_vale_para_classico_e_premium_sem_propagacao(): void
    {
        $r = $this->rascunhoComOsDoisAlvos();
        $c = $this->conjunto($r);
        $img = $this->imagemNaRevisao($c);
        $quem = User::factory()->create();

        $classico = $this->alvo($r, self::CLASSICO);
        $premium = $this->alvo($r, self::PREMIUM);

        $this->assertSame(PubAprovacaoImagem::PENDENTE, $this->comoAOfertaLe($classico, $img));
        $this->assertSame(PubAprovacaoImagem::PENDENTE, $this->comoAOfertaLe($premium, $img));

        // UMA escrita, num lugar só. Nenhuma rotina de propagação é chamada.
        $img->update(['estado' => PubAprovacaoImagem::APROVADA, 'quem_id' => $quem->id, 'quando' => now()]);

        $this->assertSame(PubAprovacaoImagem::APROVADA, $this->comoAOfertaLe($classico, $img), 'o Clássico não viu a aprovação');
        $this->assertSame(PubAprovacaoImagem::APROVADA, $this->comoAOfertaLe($premium, $img), 'o Premium precisou de aprovação própria — o compartilhamento quebrou');

        // Uma linha só foi escrita: se houvesse propagação, haveria duas.
        $this->assertSame(1, PubAprovacaoImagem::count());
        $this->assertEquals(Carbon::parse('2026-10-15 09:00:00'), $img->fresh()->quando);
    }

    public function test_estrutural_pub_aprovacao_ofertas_nao_tem_conjunto_id_com_isca(): void
    {
        // ⚠️ ISCA DE CAMINHO. `pub_aprovacao_ofertas` é criada pelo plano 186-04. Sem a tabela,
        // `hasColumn(...) === false` seria VERDADE por ausência — vazio por construção — e a
        // isca (`versao === true`) reprovaria sem medir nada. Então: SKIP NOMEADO, nunca
        // "passou". A prova que VALE AGORA é a textual, no teste seguinte.
        if (! Schema::hasTable('pub_aprovacao_ofertas')) {
            $this->markTestSkipped(
                'SKIP NOMEADO: `pub_aprovacao_ofertas` ainda não existe (é do plano 186-04). '
                . 'A prova estrutural disponível neste plano é a TEXTUAL — ver '
                . 'test_estrutural_nenhuma_tabela_do_portao_tem_o_par_oferta_id_imagem_id.'
            );
        }

        // ISCA: se a sonda estiver cega (tabela sem colunas, driver mudo), ela acha que NADA
        // existe e o `=== false` abaixo passaria por engano. `versao` TEM de existir.
        $this->assertTrue(
            Schema::hasColumn('pub_aprovacao_ofertas', 'versao'),
            'ISCA CEGA: a sonda não achou nem `versao`, que existe — o `=== false` abaixo não provaria nada.'
        );

        $this->assertFalse(
            Schema::hasColumn('pub_aprovacao_ofertas', 'conjunto_id'),
            'pub_aprovacao_ofertas voltou a ter `conjunto_id`: com uma FK só, as fotos dos outros '
            . 'grupos e as GERAIS ficam FORA do bloqueio e a oferta pode ser aprovada com foto não conferida.'
        );
    }

    public function test_estrutural_nenhuma_tabela_do_portao_tem_o_par_oferta_id_imagem_id(): void
    {
        // Um `Schema::create` que declare AS DUAS colunas é uma tabela de aprovação por
        // (oferta × imagem) — e é ela que tornaria o compartilhamento uma combinação em vez de
        // uma estrutura.
        //
        // ⚠️ A sonda divide o texto em BLOCOS por `Schema::create(` em vez de casar o fim do
        // bloco por indentação. A primeira redação exigia 12 espaços antes do `});` e a ISCA
        // abaixo a pegou CEGA na hora — era a classe de gate que não falha porque nunca casa.
        $sonda = function (string $texto): bool {
            $blocos = preg_split('/Schema::create\(/', $texto);
            array_shift($blocos); // o que vem ANTES do primeiro create não é bloco de tabela

            foreach ($blocos as $bloco) {
                if (str_contains($bloco, "'oferta_id'") && str_contains($bloco, "'imagem_id'")) {
                    return true;
                }
            }

            return false;
        };

        // ISCA 1: a MESMA sonda, apontada para um texto que TEM o par, precisa ACHAR. Sem isto,
        // "não achei nada" pode ser sonda cega (regex que nunca casa) em vez de ausência real.
        $temOPar = <<<'PHP'
            Schema::create('pub_aprovacao_por_oferta_e_imagem', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('oferta_id');
                $t->unsignedBigInteger('imagem_id');
                $t->timestamps();
            });
            PHP;
        $this->assertTrue($sonda($temOPar), 'ISCA CEGA 1: a sonda não achou o par nem no texto sintético que o TEM.');

        // ISCA 2: as duas colunas em tabelas DIFERENTES não são o par — a sonda tem de olhar
        // POR BLOCO. Sem isto ela acusaria qualquer arquivo que mencione as duas palavras.
        $parSeparado = <<<'PHP'
            Schema::create('tabela_a', function (Blueprint $t) {
                $t->unsignedBigInteger('oferta_id');
            });
            Schema::create('tabela_b', function (Blueprint $t) {
                $t->unsignedBigInteger('imagem_id');
            });
            PHP;
        $this->assertFalse($sonda($parSeparado), 'ISCA 2: colunas em tabelas diferentes não são o par (oferta_id, imagem_id).');

        $arquivos = glob(base_path('database/migrations/*pub_aprovacao*.php'));
        $this->assertNotEmpty($arquivos, 'Nenhuma migration do portão encontrada — a sonda rodaria sobre nada.');

        // ⚠️ A CONTAGEM DE ASSERÇÕES NÃO PODE DEPENDER DE QUANTOS ARQUIVOS EXISTEM.
        // Antes era uma asserção POR arquivo do glob, e isso fez o gate
        // `sem-par-oferta-imagem` REPROVAR duas vezes por CONTAGEM, com zero teste
        // falhando: 5→6 quando o 186-04 criou a 3ª migration do portão, e ia virar
        // 7 e 8 nas waves 3 e 4 (as migrations de ajustes e de financeiro casam o
        // mesmo glob). Número que muda quando o trabalho CERTO é feito é a armadilha
        // `feedback_gate_que_reprova_pelo_proprio_conserto`: ela treina o executor
        // seguinte a ajustar a régua, e um dia ele ajusta uma que importava.
        // ➡️ Agora os infratores são COLETADOS e a asserção é UMA, de contagem fixa.
        // Não se perde nada e se ganha: a mensagem nomeia TODOS os infratores de uma
        // vez, em vez de parar no primeiro. O `assertNotEmpty` acima segue sendo a
        // trava contra a sonda rodar sobre nada.
        $infratores = [];

        foreach ($arquivos as $caminho) {
            if ($sonda(file_get_contents($caminho))) {
                $infratores[] = basename($caminho);
            }
        }

        $this->assertSame(
            [],
            $infratores,
            'Estas migrations declaram uma tabela com o par (oferta_id, imagem_id): '
            . implode(', ', $infratores) . '. A aprovação passaria a ser por oferta e o '
            . 'compartilhamento Clássico↔Premium deixaria de ser estrutural. '
            . '(' . count($arquivos) . ' migration(s) do portão varrida(s).)'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // O EIXO VARIANTE — 3 cores que definem foto rendem 4 conjuntos, e TODOS entram no bloqueio
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{0: PubRascunho, 1: array<string, PubAprovacaoConjunto>} */
    private function rascunhoComTresCoresMaisGeral(): array
    {
        $valores = [new ValorEixo('52049', 'Preto'), new ValorEixo('52050', 'Branco'), new ValorEixo('52051', 'Azul')];
        $eixo = new Eixo('COLOR', 'Cor', 0, true, $valores);

        $variantes = array_map(
            fn (ValorEixo $v) => new Variante(ChaveCanonica::combinacao(['COLOR' => $v->chave()]), ['COLOR' => $v]),
            $valores
        );
        // A variante sem valores é a que cai na galeria geral.
        $variantes[] = new Variante(ChaveCanonica::UNICA, []);

        $chaves = [];
        foreach ($variantes as $v) {
            $chaves[ResolvedorGruposImagem::chaveDoGrupo($v, [$eixo], false)] = true;
        }
        $chaves = array_keys($chaves);

        // ⚠️ A conta do plano, conferida contra o resolvedor REAL: 3 + o GENERAL = 4.
        $this->assertCount(4, $chaves, '3 valores de eixo que definem foto deveriam render 4 grupos (3 + GENERAL).');
        $this->assertContains(ResolvedorGruposImagem::GERAL, $chaves, 'o grupo GENERAL não apareceu.');

        $r = $this->rascunhoComOsDoisAlvos();
        $conjuntos = [];
        foreach ($chaves as $chave) {
            $conjuntos[$chave] = $this->conjunto($r, $chave);
        }

        return [$r, $conjuntos];
    }

    public function test_tres_cores_que_definem_foto_rendem_quatro_conjuntos_e_as_duas_ofertas_veem_os_quatro(): void
    {
        [$r, $conjuntos] = $this->rascunhoComTresCoresMaisGeral();

        $this->assertSame(4, PubAprovacaoConjunto::doRascunho($r->id)->count());

        foreach ([self::CLASSICO, self::PREMIUM] as $tipo) {
            $vistos = PubAprovacaoConjunto::doRascunho($this->alvo($r, $tipo)->rascunho_id)->pluck('grupo_chave')->sort()->values()->all();
            $this->assertSame(
                collect(array_keys($conjuntos))->sort()->values()->all(),
                $vistos,
                "a oferta {$tipo} não vê os 4 conjuntos do rascunho — o bloqueio leria um grupo só"
            );
        }

        // ⚠️ A união sai em DUAS consultas (os conjuntos do rascunho + as imagens por
        // `whereIn`), e esse número NÃO cresce com o número de grupos — é isso que a asserção
        // mede. Com 4 grupos, uma consulta por grupo daria 5.
        //
        // ⚠️ O `alvo()` fica FORA da janela de log: na primeira redação ele estava dentro e
        // somava uma terceira consulta, fazendo o gate reprovar por um motivo que não era o
        // código medido.
        $classico = $this->alvo($r, self::CLASSICO);

        DB::enableQueryLog();
        $this->pendentesDaOferta($classico);
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(2, $consultas, 'a união deveria sair em 2 consultas (os conjuntos + as imagens), nunca uma por grupo');
        $this->assertStringContainsString('in (', strtolower($consultas[1]['query']), 'a 2ª consulta deveria usar `whereIn` sobre TODOS os conjuntos do rascunho');
    }

    public function test_uma_foto_pendente_num_grupo_que_nao_e_o_primeiro_bloqueia_as_duas_ofertas_com_isca(): void
    {
        [$r, $conjuntos] = $this->rascunhoComTresCoresMaisGeral();

        $chaves = array_keys($conjuntos);
        $ultimoGrupoDeCor = null;
        foreach ($chaves as $chave) {
            if ($chave !== ResolvedorGruposImagem::GERAL) {
                $ultimoGrupoDeCor = $chave;
            }
        }
        $this->assertNotSame($chaves[0], $ultimoGrupoDeCor, 'o grupo escolhido tem de NÃO ser o primeiro — é aí que o achado S2 mora');

        // Tudo aprovado, MENOS uma foto num grupo que não é o primeiro.
        $pendente = null;
        foreach ($conjuntos as $chave => $c) {
            $estado = $chave === $ultimoGrupoDeCor ? PubAprovacaoImagem::PENDENTE : PubAprovacaoImagem::APROVADA;
            $img = $this->imagemNaRevisao($c, $estado);
            if ($chave === $ultimoGrupoDeCor) {
                $pendente = $img;
            }
        }

        $classico = $this->alvo($r, self::CLASSICO);
        $premium = $this->alvo($r, self::PREMIUM);

        $this->assertSame([$pendente->id], $this->pendentesDaOferta($classico));
        $this->assertSame([$pendente->id], $this->pendentesDaOferta($premium));
        $this->assertFalse($this->aprovavel($classico), 'o Clássico ficou aprovável com foto não conferida no grupo ' . $ultimoGrupoDeCor);
        $this->assertFalse($this->aprovavel($premium), 'o Premium ficou aprovável com foto não conferida no grupo ' . $ultimoGrupoDeCor);

        // ISCA: aprovar a última faz o bloqueio DESAPARECER nas duas. Sem isto, `aprovavel()`
        // poderia devolver `false` sempre (por um bug) e o teste passaria sem medir nada.
        $pendente->update(['estado' => PubAprovacaoImagem::APROVADA, 'quando' => now()]);

        $this->assertSame([], $this->pendentesDaOferta($classico));
        $this->assertTrue($this->aprovavel($classico), 'ISCA CEGA: aprovar a última não liberou o Clássico — `aprovavel()` pode estar sempre falso');
        $this->assertTrue($this->aprovavel($premium), 'ISCA CEGA: aprovar a última não liberou o Premium');
    }

    public function test_uma_foto_pendente_no_grupo_GERAL_bloqueia_as_duas_ofertas_com_isca(): void
    {
        [$r, $conjuntos] = $this->rascunhoComTresCoresMaisGeral();

        $pendente = null;
        foreach ($conjuntos as $chave => $c) {
            $ehGeral = $chave === ResolvedorGruposImagem::GERAL;
            $img = $this->imagemNaRevisao($c, $ehGeral ? PubAprovacaoImagem::PENDENTE : PubAprovacaoImagem::APROVADA);
            if ($ehGeral) {
                $pendente = $img;
            }
        }

        $classico = $this->alvo($r, self::CLASSICO);
        $premium = $this->alvo($r, self::PREMIUM);

        // ⚠️ As GERAIS entram no bloqueio: é o caso que uma FK `conjunto_id` única deixaria de fora.
        $this->assertTrue($pendente->conjunto->ehGeral());
        $this->assertSame([$pendente->id], $this->pendentesDaOferta($classico));
        $this->assertFalse($this->aprovavel($classico), 'foto GERAL não conferida não bloqueou o Clássico');
        $this->assertFalse($this->aprovavel($premium), 'foto GERAL não conferida não bloqueou o Premium');

        // ISCA: aprovar a GERAL libera as duas.
        $pendente->update(['estado' => PubAprovacaoImagem::APROVADA, 'quando' => now()]);
        $this->assertTrue($this->aprovavel($classico), 'ISCA CEGA: aprovar a GERAL não liberou o Clássico');
        $this->assertTrue($this->aprovavel($premium), 'ISCA CEGA: aprovar a GERAL não liberou o Premium');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // A fronteira "mesmo produto E formato": base × kit são rascunhos diferentes
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_aprovar_a_foto_do_produto_base_nao_afeta_o_conjunto_do_kit(): void
    {
        $base = $this->rascunhoComOsDoisAlvos('CAD-01');
        $kit = $this->rascunhoComOsDoisAlvos('CAD-01-K2', $base->produto_id, 2);

        $imgBase = $this->imagemNaRevisao($this->conjunto($base));
        $imgKit = $this->imagemNaRevisao($this->conjunto($kit));

        $imgBase->update(['estado' => PubAprovacaoImagem::APROVADA, 'quando' => now()]);

        $this->assertSame(PubAprovacaoImagem::APROVADA, $imgBase->fresh()->estado);
        $this->assertSame(
            PubAprovacaoImagem::PENDENTE,
            $imgKit->fresh()->estado,
            'aprovar a foto do base aprovou a do kit: "Combo ×N" é pub_produtos próprio, com rascunho e fotos próprias'
        );
        $this->assertFalse($this->aprovavel($this->alvo($kit, self::PREMIUM)), 'o kit ficou aprovável de carona no base');
        $this->assertTrue($this->aprovavel($this->alvo($base, self::PREMIUM)));

        // E a travessia não cruza rascunho: o conjunto do kit não aparece para o base.
        $this->assertSame(1, PubAprovacaoConjunto::doRascunho($base->id)->count());
        $this->assertSame(1, PubAprovacaoConjunto::doRascunho($kit->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // DADO-05 — os dois eixos não se misturam, NAS DUAS DIREÇÕES
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_o_eixo_de_upload_e_o_de_revisao_nao_se_misturam_nas_duas_direcoes(): void
    {
        $r = $this->rascunhoComOsDoisAlvos();
        $img = $this->imagemNaRevisao($this->conjunto($r));
        $crua = PubImagem::findOrFail($img->imagem_id);

        $this->assertSame(PubImagem::PENDENTE, $crua->upload_status);

        // Direção 1: aprovar na REVISÃO não mexe no upload.
        $img->update(['estado' => PubAprovacaoImagem::APROVADA, 'quando' => now()]);
        $this->assertSame(PubImagem::PENDENTE, $crua->fresh()->upload_status, 'aprovar na revisão mexeu no eixo de UPLOAD');

        // Direção 2: subir a foto ao ML não mexe na REVISÃO.
        $crua->update(['upload_status' => PubImagem::ENVIADA]);
        $this->assertSame(PubAprovacaoImagem::APROVADA, $img->fresh()->estado, 'o upload mexeu no eixo de REVISÃO');

        // E uma foto pode estar ENVIADA e REPROVADA ao mesmo tempo — isso é correto, não bug.
        $img->update(['estado' => PubAprovacaoImagem::REPROVADA, 'motivo' => 'A cor não confere com o produto real.']);
        $this->assertSame([PubImagem::ENVIADA, PubAprovacaoImagem::REPROVADA], [$crua->fresh()->upload_status, $img->fresh()->estado]);

        // Os dois conjuntos de literais são disjuntos — nenhum valor serve nos dois eixos.
        $this->assertSame(
            [],
            array_intersect(
                [PubImagem::PENDENTE, PubImagem::ENVIADA, PubImagem::FALHOU],
                [PubAprovacaoImagem::APROVADA, PubAprovacaoImagem::REPROVADA, PubAprovacaoImagem::REGENERANDO, PubAprovacaoImagem::SUBSTITUIDA]
            ),
            'um literal de upload virou literal de revisão — os eixos começaram a se misturar'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // IMG-07 / IMG-09 — posição nula, `fora`, e a ação recusada em REGENERANDO
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_posicao_nula_ou_fora_tira_a_imagem_do_conjunto_final(): void
    {
        $c = $this->conjunto($this->rascunhoComOsDoisAlvos());

        $dentro = $this->imagemNaRevisao($c, PubAprovacaoImagem::APROVADA, 0);
        $semPosicao = $this->imagemNaRevisao($c, PubAprovacaoImagem::APROVADA, null);
        $foraDaLista = $this->imagemNaRevisao($c, PubAprovacaoImagem::APROVADA, 3);
        $foraDaLista->update(['fora' => true]);

        $this->assertTrue($dentro->noConjuntoFinal());
        $this->assertTrue($dentro->ehPrincipal(), 'a principal é sempre a posicao 0 (IMG-07)');

        $this->assertFalse($semPosicao->fresh()->noConjuntoFinal(), 'posicao NULL deveria significar FORA da publicação');
        $this->assertFalse($foraDaLista->fresh()->noConjuntoFinal(), '`fora = true` deveria tirar a foto da publicação');

        // Reprovada e substituída saem do conjunto final mesmo com posição boa.
        foreach ([PubAprovacaoImagem::REPROVADA, PubAprovacaoImagem::SUBSTITUIDA] as $estado) {
            $dentro->update(['estado' => $estado]);
            $this->assertFalse($dentro->fresh()->noConjuntoFinal(), "estado {$estado} deveria sair do conjunto final");
        }

        // ISCA: voltar ao estado vivo recoloca — senão `noConjuntoFinal()` seria sempre falso.
        $dentro->update(['estado' => PubAprovacaoImagem::APROVADA]);
        $this->assertTrue($dentro->fresh()->noConjuntoFinal(), 'ISCA CEGA: noConjuntoFinal() pode estar sempre falso');
    }

    public function test_imagem_regenerando_nao_aceita_acao(): void
    {
        $c = $this->conjunto($this->rascunhoComOsDoisAlvos());
        $img = $this->imagemNaRevisao($c);

        $this->assertTrue($img->aceitaAcao(), 'ISCA CEGA: aceitaAcao() pode estar sempre falso');

        $img->update(['estado' => PubAprovacaoImagem::REGENERANDO]);
        $this->assertFalse($img->fresh()->aceitaAcao(), 'IMG-09: imagem sendo regerada não aceita ação');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // DADO-06 — a verificação automática, e o que conta como "com alerta"
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_alerta_alto_em_imagem_pendente_conta_como_com_alerta_e_em_aprovada_nao(): void
    {
        $c = $this->conjunto($this->rascunhoComOsDoisAlvos());
        $img = $this->imagemNaRevisao($c, PubAprovacaoImagem::PENDENTE);

        $alerta = PubAprovacaoImagemAlerta::create([
            'aprovacao_imagem_id' => $img->id,
            'severidade' => PubAprovacaoImagemAlerta::ALTO,
            'texto' => 'A quantidade de peças na foto não confere com a do kit.',
            'x' => 42.50,
            'y' => 61.25,
        ]);

        $this->assertTrue($alerta->ehAlerta());
        $this->assertTrue($alerta->temPonto());
        $this->assertSame([42.5, 61.25], [$alerta->fresh()->x, $alerta->fresh()->y], 'x/y deveriam ser % da imagem, castados para float');
        $this->assertSame(PubAprovacaoImagemAlerta::ORIGEM_AUTOMATICA, $alerta->fresh()->origem);
        $this->assertTrue($img->temAlerta());
        $this->assertSame(1, $c->comAlerta(), 'alerta `high` em imagem pendente deveria contar como "com alerta"');

        // O MESMO alerta, com a imagem já aprovada, NÃO conta: quem olhou já decidiu.
        $img->update(['estado' => PubAprovacaoImagem::APROVADA, 'quando' => now()]);
        $this->assertSame(1, PubAprovacaoImagemAlerta::count(), 'o alerta não deveria ser apagado — só deixa de contar');
        $this->assertSame(0, $c->comAlerta(), 'alerta `high` em imagem APROVADA não deveria contar como "com alerta"');
    }

    public function test_severidade_ok_nao_e_alerta(): void
    {
        $c = $this->conjunto($this->rascunhoComOsDoisAlvos());
        $img = $this->imagemNaRevisao($c);

        $ok = PubAprovacaoImagemAlerta::create([
            'aprovacao_imagem_id' => $img->id,
            'severidade' => PubAprovacaoImagemAlerta::OK,
            'texto' => 'Nada a apontar nesta foto.',
        ]);

        $this->assertFalse($ok->ehAlerta(), '`ok` é a verificação que PASSOU, não um alerta');
        $this->assertFalse($ok->temPonto(), 'o protótipo só manda x/y quando a severidade é diferente de ok');
        $this->assertFalse($img->temAlerta());
        $this->assertSame(0, $c->comAlerta());

        // ISCA: um `med` na mesma imagem passa a contar — senão comAlerta() seria sempre 0.
        PubAprovacaoImagemAlerta::create([
            'aprovacao_imagem_id' => $img->id,
            'severidade' => PubAprovacaoImagemAlerta::MEDIO,
            'texto' => 'A textura parece diferente da do produto real.',
            'x' => 10.00,
            'y' => 90.00,
        ]);
        $this->assertSame(1, $c->comAlerta(), 'ISCA CEGA: comAlerta() pode estar sempre 0');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // O conjunto conta, nunca lê contador denormalizado
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_o_conjunto_conta_as_fotos_e_nao_guarda_contador(): void
    {
        $c = $this->conjunto($this->rascunhoComOsDoisAlvos());
        $this->imagemNaRevisao($c, PubAprovacaoImagem::APROVADA);
        $this->imagemNaRevisao($c, PubAprovacaoImagem::APROVADA);
        $this->imagemNaRevisao($c, PubAprovacaoImagem::PENDENTE);

        $this->assertSame([2, 1], [$c->aprovadas(), $c->pendentes()]);

        // As colunas de contador NÃO existem — contador denormalizado é a classe de bug que
        // faz a tela dizer "3 de 4" com 5 fotos no banco.
        foreach (['fotos_aprovadas', 'fotos_no_conjunto'] as $coluna) {
            $this->assertFalse(
                Schema::hasColumn('pub_aprovacao_conjuntos', $coluna),
                "pub_aprovacao_conjuntos ganhou o contador denormalizado `{$coluna}`: ele pode divergir da contagem real."
            );
        }
        // ISCA da sonda de coluna: `tocado` EXISTE. Sem isto, `hasColumn === false` acima
        // passaria igual numa sonda cega.
        $this->assertTrue(Schema::hasColumn('pub_aprovacao_conjuntos', 'tocado'), 'ISCA CEGA: a sonda de coluna não acha nem `tocado`.');
    }

    public function test_o_unique_impede_dois_conjuntos_para_o_mesmo_grupo_do_mesmo_rascunho(): void
    {
        $r = $this->rascunhoComOsDoisAlvos();
        $this->conjunto($r, 'COLOR=id:52049');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->conjunto($r, 'COLOR=id:52049');
    }
}
