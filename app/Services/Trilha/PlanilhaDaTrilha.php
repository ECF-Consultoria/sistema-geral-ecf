<?php

namespace App\Services\Trilha;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Services\Portal\Estrutura\Produtos\ImportadorFichasTecnicas;
use App\Services\Portal\Estrutura\Produtos\LimiteDoTitulo;
use App\Services\Portal\Estrutura\Produtos\ModeloCompleto;
use App\Services\Portal\Estrutura\Produtos\PlanilhaDasFichasTecnicas;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * A PLANILHA POR CATEGORIA da trilha (3ª entrega, fatia F7, desenho fechado em 03/11/2026 — `ENTREGA-3-DESENHO.md` §17).
 *
 * Baixar ({@see modelo()}): o arquivo do `ModeloCompleto` no perfil da trilha — uma aba por categoria (1 a 10), o
 * produto e a ficha na mesma linha, as quatro colunas de variação (cor E tamanho) e a marca `trilha-planilha-v1`. Zero
 * IA: a ficha sai de `definicao()`, nunca de `definicaoComExplicacoes()`.
 *
 * Voltar ({@see previa()}, {@see gravar()}): o arquivo é lido pelo leitor de sempre (`ImportadorFichasTecnicas::ler`),
 * as linhas viram produtos pelo "Produto (grupo)" e cada produto é executado COMO UMA GAVETA SALVA
 * ({@see executarProduto()}): `CadastroNaTrilha::criar` (o novo) → `gravar` (com `conferirLinhas`, `conferirCategoria` e a
 * conferência das cores pelo resultado) → a ficha por `FichaTecnicaDoProduto::gravarParcial`. Nada passa pelo
 * `ImportadorModeloCompleto` do Portal: o arquivo de passagem dele perde o 2º eixo e não conhece trava, anunciável nem a
 * régua das cores (D22).
 *
 * ### As regras da volta (respostas do usuário de 03/11)
 * - **Uma transação por produto** (D23). O produto com QUALQUER erro fica inteiro de fora (nada dele grava, nem a ficha);
 *   os outros gravam. Campo da ficha inválido é AVISO: só ele fica de fora, como no `gravarParcial`.
 * - **A prévia é o gravar desfeito** (D24): as mesmas transações, com rollback, sem o evento da trilha e sem o gatilho do
 *   Publicador — conferir o resultado, não simular o pedido (learnings §33). Por isso `faltas` sai de dentro da
 *   transação, antes de desfazer.
 * - **O produto existente casa pela Ref** (sem caixa, acento nem espaço), só na empresa. Branco não apaga; nada é
 *   excluído; Ref nova num grupo que já existe é uma cor/tamanho novo; a Ref nunca é trocada pela planilha (é a chave:
 *   vai ao serviço a do banco). Recusa o produto travado (andou), o que está em outra categoria, o que já está no
 *   Publicador sem categoria (D12, pela `conferirCategoria`) e o que mudou depois da prévia (a versão da F6).
 * - **Caches antes de tudo** (D25): os detalhes das categorias das abas em lote, a definição de cada uma e o limite do
 *   título quando há título longo. Dentro das transações nenhuma chamada sai (o `criar` prende a linha da empresa).
 * - **Efeitos só no gravar** (D26): um `trilha_cadastro_salvo` (passo `planilha`) por produto gravado, dentro da
 *   transação dele, e no fim UM `aoSalvar` com os que cabem (`soOsQueCabem`): o novo sem foto não vai.
 *
 * ### O arquivo (recusa inteira, 422 `errors.arquivo`)
 * Não abre, outra marca (o do Portal), nenhuma aba de categoria da marca, aba com dados que não é de categoria dela,
 * nenhuma linha preenchida, mais de {@see MAX_LINHAS} linhas de dados, ou dado fora da área que o leitor lê (abaixo da
 * linha {@see ImportadorFichasTecnicas::MAX_LINHAS}+1 ou depois da coluna {@see ImportadorFichasTecnicas::MAX_COLUNAS}) —
 * o leitor cortaria em silêncio, e aqui nunca se corta em silêncio.
 */
class PlanilhaDaTrilha
{
    /** Linhas de dados por arquivo (o gravar é síncrono; o tempo de 500 está no teste de custo). */
    public const MAX_LINHAS = ModeloCompleto::MAX_LINHAS_DA_TRILHA;

    public const MAX_CATEGORIAS = ModeloCompleto::MAX_CATEGORIAS;

    // ─── O arquivo inteiro (422 `errors.arquivo`) ───

    public const MSG_NAO_RECONHECIDO = 'Não reconhecemos este arquivo. Baixe a planilha de novo, escolhendo as categorias, e preencha nela.';

    public const MSG_OUTRO_MODELO = 'Esta planilha é de outro modelo. Baixe a planilha aqui, escolhendo as categorias, e preencha nela.';

    public const MSG_SEM_ABA = 'Nenhuma aba desta planilha é de uma das categorias dela. Não mude o nome das abas.';

    public const MSG_ABA_ESTRANHA = 'A aba “%s” não é de uma das categorias desta planilha. Não mude o nome das abas; para outra categoria, baixe uma planilha com ela.';

    public const MSG_VAZIA = 'A planilha não tem nenhum produto preenchido.';

    public const MSG_LINHAS = 'A planilha tem %d linhas de produtos, e o limite é %d por envio. Divida em arquivos menores.';

    public const MSG_ALEM_DO_CORTE = 'A aba “%s” tem dados fora da área lida (abaixo da linha %d ou depois da coluna %d). Tire esses dados ou divida em arquivos menores.';

    public const MSG_CATEGORIA_FORA = 'Não conseguimos ler a categoria “%s” agora. Tente de novo em instantes.';

    // ─── Um produto (erro: ele fica inteiro de fora) ───

    public const MSG_REF_REPETIDA = 'A Ref %s já está na linha %d do arquivo. Cada variação precisa de uma Ref só.';

    public const MSG_DUAS_ABAS = 'O produto %s também está na aba “%s”. Um produto fica numa aba só, a da categoria dele.';

    public const MSG_PRODUTOS_DIFERENTES = 'As Refs deste grupo são de produtos diferentes já cadastrados (%s). Use um grupo para cada produto.';

    public const MSG_MESMO_PRODUTO = 'Este produto já veio no grupo %s (linha %d). Junte as linhas dele num grupo só.';

    public const MSG_OUTRA_CATEGORIA = 'Este produto já está em outra categoria (%s). Ponha as linhas dele na aba dessa categoria, ou troque a categoria no cadastro dele.';

    public const MSG_GRADE = 'Um produto pode ter no máximo %d variações.';

    public const MSG_FALHA = 'Não deu para gravar este produto agora. Tente de novo; se continuar, fale com a equipe.';

    // ─── Avisos (o resto do produto entra) ───

    public const MSG_COLUNA_IGNORADA = 'Esta coluna não é um campo desta planilha e foi ignorada.';

    public const MSG_VOLUME_INCOMPLETO = 'O Volume %d está incompleto (preencha comprimento, largura, altura e peso). As medidas desta linha não foram alteradas.';

    public const MSG_DO_PRODUTO = 'Vale %s da 1ª linha do produto (linha %d).';

    public const MSG_ABSORVIDOS = 'Anúncio(s) que esperavam por estas Refs ficaram ligados ao produto: %d.';

    /** Os campos do PRODUTO (não da variação): o 1º valor preenchido entre as linhas dele vale; outro diferente avisa. */
    private const DO_PRODUTO = ['nome' => 'o nome', 'titulo' => 'o título', 'familia' => 'a família', 'ambientes' => 'os ambientes', 'descricao' => 'a descrição'];

    /** Os campos da VARIAÇÃO que a linha leva quando preenchidos (branco não mexe). */
    private const DA_VARIACAO = ['eixo', 'valor', 'eixo_2', 'valor_2', 'custo', 'estoque'];

    /** {@see colunasDoProduto()}, montado uma vez (é só função das constantes do `ModeloCompleto`). */
    private static ?array $colunasDoProduto = null;

    public function __construct(
        private ModeloCompleto $modeloCompleto,
        private ImportadorFichasTecnicas $leitor,
        private CadastroNaTrilha $cadastro,
        private FichaTecnicaDoProduto $ficha,
        private FichaTecnicaDaCategoria $definicao,
        private CategoriaSugestaoService $categorias,
        private LimiteDoTitulo $limiteDoTitulo,
    ) {}

    // ═══ Baixar ═════════════════════════════════════════════════════════════

    /**
     * O modelo vazio das categorias, no perfil da trilha. Recusada a categoria que não existe, não é folha, não é
     * anunciável, não tem ficha agora ou não cabe nas colunas que o leitor lê — com o motivo de cada uma.
     *
     * @param  list<string>  $ids
     * @return array{planilha: ?Spreadsheet, abas: list<array{id: string, nome: string, aba: string, campos: int}>, recusadas: list<array{id: string, motivo: string}>}
     */
    public function modelo(array $ids): array
    {
        return $this->modeloCompleto->gerar($ids, ModeloCompleto::PERFIL_TRILHA);
    }

    // ═══ A volta ════════════════════════════════════════════════════════════

    /**
     * O que o arquivo faria, sem gravar nada: o gravar desfeito (D24). Nenhum evento, nenhum Job.
     *
     * @return array{gravado: bool, abas: list<array>, produtos: list<array>, totais: array<string, int>, mensagem: string}
     *
     * @throws ValidationException 422 `arquivo` (o arquivo inteiro)
     */
    public function previa(Company $empresa, string $caminho, AtorDoPortal $ator): array
    {
        return $this->processar($empresa, $caminho, $ator, false, []);
    }

    /**
     * Grava: cada produto na sua transação; o recusado fica inteiro de fora e os outros entram. `$versoes` (produto →
     * versão) é o que a prévia mostrou: diferente da atual, aquele produto é recusado com a frase da F6.
     *
     * @param  array<int|string, mixed>  $versoes
     * @return array{gravado: bool, abas: list<array>, produtos: list<array>, totais: array<string, int>, mensagem: string}
     *
     * @throws ValidationException 422 `arquivo`
     */
    public function gravar(Company $empresa, string $caminho, AtorDoPortal $ator, array $versoes): array
    {
        return $this->processar($empresa, $caminho, $ator, true, $versoes);
    }

    /**
     * Lê o arquivo — só o arquivo, sem a empresa: as abas de categoria da marca da trilha, cada uma com os cabeçalhos e
     * as linhas de dados (só as células com valor, como o leitor devolve). Recusa o arquivo inteiro (422 `arquivo`) pelo
     * que está na doc da classe.
     *
     * @return list<array{nome: string, categoria_id: string, categoria_nome: string, cabecalhos: array<int, string>, linhas: list<array>}>
     *
     * @throws ValidationException
     */
    public function ler(string $caminho): array
    {
        $lido = $this->leitor->ler($caminho);
        if ($lido['erro_geral'] !== null) {
            throw self::doArquivo($lido['erro_geral'] === ImportadorFichasTecnicas::MSG_ILEGIVEL ? self::MSG_NAO_RECONHECIDO : $lido['erro_geral']);
        }

        $marca = ModeloCompleto::marcaDoArquivo($caminho);
        if ($marca !== ModeloCompleto::MARCA_DA_TRILHA) {
            throw self::doArquivo($marca === null ? self::MSG_NAO_RECONHECIDO : self::MSG_OUTRO_MODELO);
        }
        $categorias = ModeloCompleto::categoriasDoArquivo($caminho, ModeloCompleto::MARCA_DA_TRILHA);

        $abas = [];
        $total = 0;
        foreach ($lido['abas'] as $aba) {
            $categoria = $categorias[$aba['nome']] ?? null;
            if ($categoria === null || $categoria['id'] === null) {
                // Aba que não é de categoria da marca: com dado, é produto que se perderia — o arquivo volta.
                if ($aba['linhas'] !== []) {
                    throw self::doArquivo(sprintf(self::MSG_ABA_ESTRANHA, $aba['nome']));
                }

                continue;
            }
            $total += count($aba['linhas']);
            $abas[] = [
                'nome'           => (string) $aba['nome'],
                'categoria_id'   => (string) $categoria['id'],
                'categoria_nome' => (string) ($categoria['nome'] !== '' ? $categoria['nome'] : $aba['nome']),
                'cabecalhos'     => $aba['cabecalhos'],
                'linhas'         => $aba['linhas'],
            ];
        }

        if ($abas === []) {
            throw self::doArquivo(self::MSG_SEM_ABA);
        }
        if ($total === 0) {
            throw self::doArquivo(self::MSG_VAZIA);
        }
        if ($total > self::MAX_LINHAS) {
            throw self::doArquivo(sprintf(self::MSG_LINHAS, $total, self::MAX_LINHAS));
        }
        self::recusarDadoForaDoCorte($caminho, array_column($abas, 'nome'));

        return $abas;
    }

    /**
     * UM produto do arquivo, como uma gaveta salva, na transação dele. Na prévia (`$gravar = false`) a transação é
     * desfeita depois de medir o resultado — inclusive as faltas da Lista. As guardas, nesta ordem: o erro do próprio
     * arquivo (Ref repetida, grupo em duas abas…), a trava (andou), a outra categoria; dentro da transação, a categoria
     * da aba (`conferirCategoria`: anunciável, D12), as linhas (o erro de qualquer uma recusa o produto), as cores pelo
     * resultado e, por fim, a versão da prévia — só para o produto que MUDARIA (o sem mudança não escreve nada).
     * Erro inesperado também recusa só este produto (e vai para o log).
     *
     * @param  array<string, mixed>  $produto  um item do plano ({@see planejar()})
     * @param  array{travas: array<int, string>, versoes: array<int, string>}  $contexto
     * @return array<string, mixed> o item de `produtos` do contrato (§17.4)
     */
    public function executarProduto(Company $empresa, array $produto, AtorDoPortal $ator, bool $gravar, array $contexto): array
    {
        /** @var ?EstruturaProduto $existente */
        $existente = $produto['existente'];
        $primeira = (int) $produto['linhas'][0]['numero'];
        $saida = [
            'chave'      => $produto['chave'],
            'aba'        => $produto['aba'],
            'linhas'     => array_map(fn (array $l) => (int) $l['numero'], $produto['linhas']),
            'produto_id' => $existente !== null ? (int) $existente->id : null,
            'nome'       => $produto['nome'] !== '' ? $produto['nome'] : ($existente?->nome !== null ? (string) $existente->nome : null),
            'codigo'     => $existente !== null ? $existente->codigo : ($produto['linhas'][0]['ref'] !== '' ? $produto['linhas'][0]['ref'] : null),
            'situacao'   => 'recusado',
            'variacoes'  => ['novas' => 0, 'atualizadas' => 0],
            'erros'      => [],
            'avisos'     => $produto['avisos'],
            'faltas'     => [],
            'versao'     => $existente !== null ? VersaoDoCadastro::de($empresa, $existente) : null,
        ];

        if ($produto['erros'] !== []) {
            return ['erros' => $produto['erros']] + $saida;
        }
        if ($existente !== null) {
            $trava = $contexto['travas'][(int) $existente->id] ?? null;
            if ($trava !== null) {
                return ['erros' => [self::erro($primeira, null, $trava)]] + $saida;
            }
            $categoria = strtoupper(trim((string) $existente->categoria_ml_id));
            if ($categoria !== '' && $categoria !== $produto['aba']) {
                $nome = trim((string) $existente->categoria_ml_nome);

                return ['erros' => [self::erro($primeira, null, sprintf(self::MSG_OUTRA_CATEGORIA, $nome !== '' ? $nome : $categoria))]] + $saida;
            }
        }

        DB::beginTransaction();
        try {
            $r = $this->naTransacao($empresa, $produto, $ator);
            if ($r['erros'] !== []) {
                DB::rollBack();

                return ['erros' => $r['erros'], 'avisos' => [...$saida['avisos'], ...$r['avisos']]] + $saida;
            }

            /** @var EstruturaProduto $gravado */
            $gravado = $r['produto'];
            $mudou = $r['novo'] || $r['variacoes']['novas'] + $r['variacoes']['atualizadas'] > 0 || $r['ficha'] > 0;

            // A versão da prévia (F6) só vale para quem VAI gravar: a tela devolve a de todo produto que tinha, inclusive
            // o recusado e o sem mudança — esses não escrevem nada, então não há o que proteger.
            $daTela = $existente !== null ? trim((string) ($contexto['versoes'][(int) $existente->id] ?? '')) : '';
            if ($mudou && $daTela !== '' && ! hash_equals((string) $saida['versao'], $daTela)) {
                DB::rollBack();

                return ['erros' => [self::erro($primeira, null, CadastroNaTrilha::MSG_VERSAO)]] + $saida;
            }
            $saida['situacao'] = $r['novo'] ? 'novo' : ($mudou ? 'atualizado' : 'sem_mudanca');
            $saida['variacoes'] = $r['variacoes'];
            $saida['avisos'] = [...$saida['avisos'], ...$r['avisos']];
            $saida['nome'] = (string) $gravado->nome;
            $saida['codigo'] = $gravado->codigo;
            // A Lista do produto como ficaria: dentro da transação, antes de desfazer (na prévia).
            $saida['faltas'] = TrilhaDaEmpresa::faltasDoProduto($empresa, (int) $gravado->id);

            if (! $gravar) {
                DB::rollBack();

                return $saida;
            }

            if ($mudou) {
                $this->cadastro->registrar($ator, $empresa, $gravado, CadastroNaTrilha::PASSO_PLANILHA, [
                    'novo'      => $r['novo'],
                    'aba'       => $produto['aba'],
                    'linhas'    => $saida['linhas'],
                    'variacoes' => $r['variacoes'],
                    'ficha'     => $r['ficha'],
                ]);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("[Trilha] Planilha: o produto {$produto['chave']} (linha {$primeira}) da empresa {$empresa->id} ({$empresa->name}) falhou: {$e->getMessage()}");

            return ['situacao' => 'recusado', 'erros' => [self::erro($primeira, null, self::MSG_FALHA)]] + $saida;
        }

        $saida['produto_id'] = (int) $gravado->id;
        $saida['versao'] = VersaoDoCadastro::de($empresa, $gravado);

        return $saida;
    }

    // ═══ Internos: o fluxo ══════════════════════════════════════════════════

    /**
     * @param  array<int|string, mixed>  $versoes
     * @return array{gravado: bool, abas: list<array>, produtos: list<array>, totais: array<string, int>, mensagem: string}
     */
    private function processar(Company $empresa, string $caminho, AtorDoPortal $ator, bool $gravar, array $versoes): array
    {
        $abas = $this->ler($caminho);
        // D25: os caches das categorias antes de qualquer transação (nenhuma chamada sai lá dentro).
        $campos = $this->aquecer($abas);
        $plano = $this->planejar($empresa, $abas, $campos);

        $contexto = [
            // A régua da trava UMA vez por arquivo (a mesma da gaveta e do 409).
            'travas'  => $this->cadastro->motivosDasTravas($empresa),
            'versoes' => self::versoes($versoes),
        ];

        $produtos = [];
        $gravados = [];
        foreach ($plano as $p) {
            $resultado = $this->executarProduto($empresa, $p, $ator, $gravar, $contexto);
            $produtos[] = $resultado;
            if ($gravar && in_array($resultado['situacao'], ['novo', 'atualizado'], true)) {
                $gravados[] = (int) $resultado['produto_id'];
            }
        }

        // D26: UM agendamento no fim, só com quem cabe (completo ou já no Publicador) — o novo sem foto não vai.
        if ($gravados !== []) {
            $cabem = CadastroNaTrilha::soOsQueCabem((int) $empresa->id, $gravados);
            if ($cabem !== []) {
                app(PreparoIaAgenda::class)->aoSalvar((int) $empresa->id, $cabem);
            }
        }

        $totais = ['novos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'recusados' => 0];
        foreach ($produtos as $p) {
            $totais[match ($p['situacao']) {
                'novo'        => 'novos',
                'atualizado'  => 'atualizados',
                'sem_mudanca' => 'sem_mudanca',
                default       => 'recusados',
            }]++;
        }

        return [
            'gravado'  => $gravar,
            'abas'     => array_map(fn (array $a) => [
                'categoria_id'   => $a['categoria_id'],
                'categoria_nome' => $a['categoria_nome'],
                'linhas'         => count($a['linhas']),
            ], $abas),
            'produtos' => $produtos,
            'totais'   => $totais,
            'mensagem' => self::mensagem($totais, $gravar),
        ];
    }

    /**
     * D25 — antes de qualquer transação: os detalhes das categorias das abas EM LOTE, a definição da ficha de cada uma e,
     * havendo título acima do padrão, o limite da categoria. A categoria que não responde recusa o arquivo (gravar sem
     * ela deixaria o produto com a categoria "não validada" e sem ficha).
     *
     * @param  list<array>  $abas
     * @return array<string, array<string, array>> categoria → os campos da ficha pela chave do nome
     *
     * @throws ValidationException
     */
    private function aquecer(array $abas): array
    {
        $comDados = array_values(array_filter($abas, fn (array $a) => $a['linhas'] !== []));
        $detalhes = $this->categorias->detalhes(array_values(array_unique(array_column($comDados, 'categoria_id'))));

        $campos = [];
        foreach ($comDados as $aba) {
            $id = $aba['categoria_id'];
            if (isset($campos[$id])) {
                continue;
            }
            $definicao = ($detalhes[$id] ?? null) === null ? [] : $this->definicao->definicao($id);
            if ($definicao === []) {
                throw self::doArquivo(sprintf(self::MSG_CATEGORIA_FORA, $aba['categoria_nome']));
            }
            $campos[$id] = [];
            foreach (FichaTecnicaDaCategoria::camposPorId($definicao) as $campo) {
                $campos[$id][PlanilhaDasFichasTecnicas::chave((string) $campo['nome'])] ??= $campo;
            }
            if (self::temTituloLongo($aba)) {
                $this->limiteDoTitulo->daCategoria($id);
            }
        }

        return $campos;
    }

    /**
     * As linhas viram produtos: pelo "Produto (grupo)" (sem ele, pela Ref; sem as duas, a linha sozinha), em todas as
     * abas. Aqui moram os erros que o próprio arquivo já diz (Ref repetida, grupo em duas abas, mais variações que o
     * teto, Refs de produtos diferentes, o mesmo produto em dois grupos), o casamento com o que existe (pela Ref) e os
     * avisos de leitura (volume pela metade, coluna que não é campo, campo do produto diferente entre as linhas).
     *
     * @param  list<array>  $abas
     * @param  array<string, array<string, array>>  $campos
     * @return list<array<string, mixed>>
     */
    private function planejar(Company $empresa, array $abas, array $campos): array
    {
        [$doProduto, $titulos] = self::colunasDoProduto();

        $grupos = [];
        $refs = []; // chave da Ref => linha em que apareceu primeiro
        foreach ($abas as $aba) {
            $colunas = self::colunasDaAba($aba['cabecalhos'], $doProduto, $campos[$aba['categoria_id']] ?? []);
            foreach ($aba['linhas'] as $bruta) {
                $linha = self::linha($bruta, $colunas);
                $numero = $linha['numero'];
                $grupo = $linha['campos']['grupo'] ?? '';
                $chave = match (true) {
                    $grupo !== ''        => 'g:'.ProdutoCadastroService::chaveCodigo($grupo),
                    $linha['ref'] !== '' => 'r:'.ProdutoCadastroService::chaveCodigo($linha['ref']),
                    default              => 'l:'.$aba['nome'].':'.$numero,
                };

                $grupos[$chave] ??= [
                    'chave'      => $grupo !== '' ? $grupo : ($linha['ref'] !== '' ? $linha['ref'] : "Linha {$numero}"),
                    'aba'        => $aba['categoria_id'],
                    'aba_nome'   => $aba['nome'],
                    'linhas'     => [],
                    'erros'      => [],
                    'avisos'     => [],
                    'existente'  => null,
                ];
                $g = &$grupos[$chave];
                $g['linhas'][] = $linha;
                foreach ($linha['avisos'] as $a) {
                    $g['avisos'][] = $a;
                }

                if ($g['aba_nome'] !== $aba['nome']) {
                    $g['erros'][] = self::erro($numero, $titulos['grupo'], sprintf(self::MSG_DUAS_ABAS, $g['chave'], $g['aba_nome']));
                }
                if ($linha['ref'] !== '') {
                    $chaveRef = ProdutoCadastroService::chaveCodigo($linha['ref']);
                    if (isset($refs[$chaveRef])) {
                        $g['erros'][] = self::erro($numero, $titulos['codigo'], sprintf(self::MSG_REF_REPETIDA, $linha['ref'], $refs[$chaveRef]));
                    } else {
                        $refs[$chaveRef] = $numero;
                    }
                }
                unset($g);
            }
        }

        // O que já existe, lido UMA vez: as variações da empresa pela chave da Ref, e os produtos delas.
        $porRef = [];
        foreach (EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->get(['id', 'produto_id', 'codigo']) as $v) {
            $porRef[ProdutoCadastroService::chaveCodigo((string) $v->codigo)] ??= $v;
        }
        $produtosDaEmpresa = EstruturaProduto::query()->where('company_id', $empresa->id)->get()->keyBy('id');

        $plano = [];
        $vistos = []; // produto existente => [chave do grupo, 1ª linha]
        foreach ($grupos as $g) {
            $primeira = (int) $g['linhas'][0]['numero'];
            if (count($g['linhas']) > ProdutoCadastroService::MAX_GRADE) {
                $g['erros'][] = self::erro($primeira, null, sprintf(self::MSG_GRADE, ProdutoCadastroService::MAX_GRADE));
            }

            $donos = [];
            foreach ($g['linhas'] as $i => $l) {
                $v = $l['ref'] !== '' ? ($porRef[ProdutoCadastroService::chaveCodigo($l['ref'])] ?? null) : null;
                $g['linhas'][$i]['variacao'] = $v;
                if ($v !== null) {
                    $donos[(int) $v->produto_id] = true;
                }
            }
            if (count($donos) > 1) {
                $nomes = array_map(fn (int $id) => (string) ($produtosDaEmpresa->get($id)?->codigo ?? $produtosDaEmpresa->get($id)?->nome ?? $id), array_keys($donos));
                $g['erros'][] = self::erro($primeira, null, sprintf(self::MSG_PRODUTOS_DIFERENTES, implode(', ', $nomes)));
            } elseif ($donos !== []) {
                $id = (int) array_key_first($donos);
                $g['existente'] = $produtosDaEmpresa->get($id);
                if (isset($vistos[$id])) {
                    $g['erros'][] = self::erro($primeira, null, sprintf(self::MSG_MESMO_PRODUTO, $vistos[$id][0], $vistos[$id][1]));
                } else {
                    $vistos[$id] = [$g['chave'], $primeira];
                }
            }

            [$g['nome'], $g['do_produto'], $avisos] = self::doProduto($g['linhas'], $titulos);
            $g['avisos'] = [...$g['avisos'], ...$avisos];
            $g['ficha'] = self::fichaDoProduto($g['linhas']);
            $plano[] = $g;
        }

        return $plano;
    }

    /**
     * Dentro da transação do produto: criar (o novo), as linhas e a ficha. Nada aqui chama o ML (D25). Devolve os erros
     * (o produto sai inteiro: quem chama desfaz) ou o que mudou.
     *
     * @return array{erros: list<array>, avisos: list<array>, produto?: EstruturaProduto, novo?: bool, variacoes?: array{novas: int, atualizadas: int}, ficha?: int}
     */
    private function naTransacao(Company $empresa, array $p, AtorDoPortal $ator): array
    {
        [, $titulos] = self::colunasDoProduto();
        $primeira = (int) $p['linhas'][0]['numero'];
        $numeros = array_map(fn (array $l) => (int) $l['numero'], $p['linhas']);
        $avisos = [];

        try {
            $novo = $p['existente'] === null;
            $absorvidos = 0;
            if ($novo) {
                $criado = $this->cadastro->criar($empresa, $p['nome'], $p['linhas'][0]['ref'], $ator, efeitos: false);
                $produto = $criado['produto'];
                $absorvidos += (int) $criado['absorvidos_da_espera'];
                $avisos = [...$avisos, ...self::avisosDoServico($criado['avisos'], $p['linhas'], $primeira)];
                $idDaPrimeira = (int) EstruturaProdutoVariacao::query()->where('produto_id', $produto->id)->value('id');
            } else {
                $produto = $p['existente'];
                $idDaPrimeira = null;
            }

            $linhas = [];
            foreach ($p['linhas'] as $i => $l) {
                $linha = ['codigo' => $l['ref'], 'nome' => $p['nome'] !== '' ? $p['nome'] : (string) $produto->nome];
                if ($i === 0 && $idDaPrimeira !== null) {
                    $linha['id'] = $idDaPrimeira;
                } elseif ($l['variacao'] !== null) {
                    // A Ref é a chave: vai a do banco, nunca a da célula (caixa ou acento diferente trocaria o SKU).
                    $linha['id'] = (int) $l['variacao']->id;
                    $linha['codigo'] = (string) $l['variacao']->codigo;
                }
                foreach (self::DA_VARIACAO as $c) {
                    if (isset($l['campos'][$c])) {
                        $linha[$c] = $l['campos'][$c];
                    }
                }
                if ($l['volumes'] !== null) {
                    $linha['volumes'] = $l['volumes'];
                }
                if ($i === 0) {
                    foreach ($p['do_produto'] as $c => $valor) {
                        $linha[$c] = $valor;
                    }
                    // A aba é a categoria (a `conferirCategoria` confere anunciável e o D12).
                    $linha['categoria_ml_id'] = $p['aba'];
                }
                $linhas[] = $linha;
            }

            $linhas = $this->cadastro->conferirLinhas($empresa, $produto, $linhas);
            $linhas = $this->cadastro->conferirCategoria($empresa, $produto, $linhas);
            $r = $this->cadastro->gravar($empresa, $produto, $linhas, $ator, efeitos: false);
        } catch (ValidationException $e) {
            return ['erros' => self::errosDaValidacao($e->errors(), $numeros, $p['linhas'], $titulos), 'avisos' => $avisos];
        } catch (HttpResponseException $e) {
            // A conferência das cores (422): os erros por linha, no formato do serviço.
            $corpo = (array) json_decode((string) $e->getResponse()->getContent(), true);

            return ['erros' => self::errosDoServico((array) ($corpo['erros'] ?? []), $numeros, $p['linhas'], $titulos, $primeira, (string) ($corpo['message'] ?? '')), 'avisos' => $avisos];
        }

        if ($r['erros'] !== []) {
            return ['erros' => self::errosDoServico($r['erros'], $numeros, $p['linhas'], $titulos, $primeira, ''), 'avisos' => $avisos];
        }
        $avisos = [...$avisos, ...self::avisosDoServico($r['avisos'], $p['linhas'], $primeira)];
        $absorvidos += (int) ($r['totais']['absorvidos_da_espera'] ?? 0);
        if ($absorvidos > 0) {
            $avisos[] = self::erro($primeira, null, sprintf(self::MSG_ABSORVIDOS, $absorvidos));
        }

        // A ficha: mesclando (branco não apaga); o campo inválido é AVISO e só ele fica de fora (D23).
        $gravadosNaFicha = 0;
        if ($p['ficha'] !== []) {
            $entradas = [];
            foreach ($p['ficha'] as $id => $f) {
                $entradas[$id] = ImportadorFichasTecnicas::entrada($f['campo'], $f['bruto']);
            }
            try {
                $f = $this->ficha->gravarParcial($empresa, $produto->fresh() ?? $produto, $entradas, $ator);
                $gravadosNaFicha = (int) $f['gravados'];
                foreach ($f['erros'] as $id => $mensagem) {
                    $origem = $p['ficha'][$id] ?? null;
                    $avisos[] = self::erro($origem['linha'] ?? $primeira, $origem['coluna'] ?? null, (string) $mensagem);
                }
            } catch (ValidationException $e) {
                $avisos[] = self::erro($primeira, null, (string) collect($e->errors())->flatten()->first());
            }
        }

        $criadas = (int) $r['totais']['criadas'];
        $atualizadas = (int) $r['totais']['atualizadas'];

        return [
            'erros'     => [],
            'avisos'    => $avisos,
            'produto'   => $produto->fresh() ?? $produto,
            'novo'      => $novo,
            // O novo: todas as linhas são variações novas (a 1ª nasceu no `criar` e foi nomeada no `gravar`).
            'variacoes' => $novo ? ['novas' => 1 + $criadas, 'atualizadas' => 0] : ['novas' => $criadas, 'atualizadas' => $atualizadas],
            'ficha'     => $gravadosNaFicha,
        ];
    }

    // ═══ Internos: o arquivo ════════════════════════════════════════════════

    /**
     * O leitor lê até a linha {@see ImportadorFichasTecnicas::MAX_LINHAS}+1 e a coluna
     * {@see ImportadorFichasTecnicas::MAX_COLUNAS} e corta o resto EM SILÊNCIO. A aba cuja última linha (ou coluna) do XML
     * passa disso é relida só fora da área; havendo qualquer valor lá, o arquivo volta. A 1ª conta é barata
     * (`listWorksheetInfo` só percorre o XML); a releitura só acontece quando há o que conferir (o modelo formata até a
     * linha do corte, nunca além).
     *
     * @param  list<string>  $nomes  as abas de categoria
     *
     * @throws ValidationException
     */
    private static function recusarDadoForaDoCorte(string $caminho, array $nomes): void
    {
        $maxLinha = ImportadorFichasTecnicas::MAX_LINHAS + 1;
        $maxColuna = ImportadorFichasTecnicas::MAX_COLUNAS;

        try {
            $suspeitas = [];
            foreach (IOFactory::createReader('Xlsx')->listWorksheetInfo($caminho) as $info) {
                if (in_array($info['worksheetName'], $nomes, true) && ((int) $info['totalRows'] > $maxLinha || (int) $info['totalColumns'] > $maxColuna)) {
                    $suspeitas[] = (string) $info['worksheetName'];
                }
            }
            if ($suspeitas === []) {
                return;
            }

            $leitor = IOFactory::createReader('Xlsx');
            $leitor->setReadDataOnly(true);
            $leitor->setReadEmptyCells(false);
            $leitor->setLoadSheetsOnly($suspeitas);
            $leitor->setReadFilter(new class($maxLinha, $maxColuna) implements IReadFilter
            {
                public function __construct(private int $maxLinha, private int $maxColuna) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row > $this->maxLinha || Coordinate::columnIndexFromString($columnAddress) > $this->maxColuna;
                }
            });
            $planilha = $leitor->load($caminho);
            $fora = null;
            foreach ($planilha->getAllSheets() as $folha) {
                foreach ($folha->getCellCollection()->getCoordinates() as $coordenada) {
                    $valor = $folha->getCell($coordenada)->getValue();
                    if ($valor !== null && trim((string) (is_scalar($valor) ? $valor : 'x')) !== '') {
                        $fora = $folha->getTitle();
                        break 2;
                    }
                }
            }
            $planilha->disconnectWorksheets();
        } catch (\Throwable $e) {
            Log::warning('[Trilha] Planilha: não deu para conferir a área fora do corte: '.$e->getMessage());

            throw self::doArquivo(self::MSG_NAO_RECONHECIDO);
        }

        if ($fora !== null) {
            throw self::doArquivo(sprintf(self::MSG_ALEM_DO_CORTE, $fora, $maxLinha, $maxColuna));
        }
    }

    /**
     * As colunas do produto no perfil da trilha: cabeçalho (sem caixa, acento nem "*") → chave da linha do serviço (a
     * "Variação" é o VALOR — nunca o ordinal do modelo antigo), e chave → o título, para dizer a coluna de cada erro.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private static function colunasDoProduto(): array
    {
        if (self::$colunasDoProduto !== null) {
            return self::$colunasDoProduto;
        }
        $porCabecalho = [];
        foreach (ModeloCompleto::chavesDosCabecalhos(doisEixos: true) as $cabecalho => $chave) {
            if ($chave !== 'categoria') {
                $porCabecalho[$cabecalho] = $chave === 'variacao' ? 'valor' : $chave;
            }
        }
        $titulos = [];
        foreach (ModeloCompleto::colunas(ModeloCompleto::MAX_VOLUMES, false, true) as $c) {
            $titulos[$c['chave'] === 'variacao' ? 'valor' : $c['chave']] = $c['titulo'];
        }

        return self::$colunasDoProduto = [$porCabecalho, $titulos];
    }

    /**
     * De que é cada coluna da aba: do produto (o 1º cabeçalho de cada campo; um repetido cai na ficha), da ficha (pelo
     * nome do campo, como no leitor da ficha: sem o "*" e sem a unidade) ou de nada (ignorada, com aviso onde tem valor).
     *
     * @param  array<int, string>  $cabecalhos
     * @param  array<string, string>  $doProduto
     * @param  array<string, array>  $campos
     * @return array<int, array{tipo: string, titulo: string, chave?: string, campo?: array}>
     */
    private static function colunasDaAba(array $cabecalhos, array $doProduto, array $campos): array
    {
        $saida = [];
        $usadas = [];
        foreach ($cabecalhos as $coluna => $titulo) {
            $chave = $doProduto[PlanilhaDasFichasTecnicas::chave(str_replace('*', '', $titulo))] ?? null;
            if ($chave !== null && ! isset($usadas[$chave])) {
                $usadas[$chave] = true;
                $saida[$coluna] = ['tipo' => 'produto', 'titulo' => $titulo, 'chave' => $chave];
                continue;
            }
            $campo = null;
            foreach (PlanilhaDasFichasTecnicas::chavesDoCabecalho($titulo) as $k) {
                $campo ??= $campos[$k] ?? null;
            }
            $saida[$coluna] = $campo !== null
                ? ['tipo' => 'ficha', 'titulo' => $titulo, 'campo' => $campo]
                : ['tipo' => 'nada', 'titulo' => $titulo];
        }

        return $saida;
    }

    /**
     * Uma linha lida: os campos do produto (texto como a pessoa digitaria), os volumes completos (o incompleto vira
     * aviso e a linha não mexe nas medidas, como no Portal), a ficha (o valor CRU da célula, com a linha e a coluna de
     * onde veio) e os avisos de coluna ignorada.
     *
     * @param  array<int|string, mixed>  $bruta  a linha do leitor (`__linha` + coluna → valor)
     * @param  array<int, array>  $colunas
     * @return array{numero: int, ref: string, campos: array<string, string>, volumes: ?list<array<string, string>>, ficha: array<string, array>, avisos: list<array>}
     */
    private static function linha(array $bruta, array $colunas): array
    {
        $numero = (int) $bruta['__linha'];
        $campos = [];
        $volumes = [];
        $ficha = [];
        $avisos = [];
        foreach ($bruta as $coluna => $valor) {
            if ($coluna === '__linha' || self::vazio($valor)) {
                continue;
            }
            $destino = $colunas[$coluna] ?? ['tipo' => 'nada', 'titulo' => 'Coluna '.Coordinate::stringFromColumnIndex((int) $coluna)];
            if ($destino['tipo'] === 'produto') {
                $texto = self::comoTexto($valor);
                if ($texto === '') {
                    continue;
                }
                if (str_starts_with($destino['chave'], 'vol:')) {
                    [, $n, $medida] = explode(':', $destino['chave']);
                    $volumes[(int) $n][$medida] = $texto;
                } else {
                    $campos[$destino['chave']] = $texto;
                }
            } elseif ($destino['tipo'] === 'ficha') {
                $ficha[(string) $destino['campo']['id']] = ['campo' => $destino['campo'], 'bruto' => $valor, 'linha' => $numero, 'coluna' => $destino['titulo']];
            } else {
                $avisos[] = self::erro($numero, $destino['titulo'], self::MSG_COLUNA_IGNORADA);
            }
        }

        ksort($volumes);
        $completos = [];
        $incompleto = false;
        foreach ($volumes as $n => $v) {
            if (count(array_intersect_key($v, ModeloCompleto::MEDIDAS)) < count(ModeloCompleto::MEDIDAS)) {
                $incompleto = true;
                $avisos[] = self::erro($numero, ModeloCompleto::tituloDoVolume($n, (string) array_key_first(array_diff_key(ModeloCompleto::MEDIDAS, $v))),
                    sprintf(self::MSG_VOLUME_INCOMPLETO, $n));
                continue;
            }
            $completos[] = ['c' => $v['c'], 'l' => $v['l'], 'a' => $v['a'], 'kg' => $v['kg']];
        }

        return [
            'numero'  => $numero,
            'ref'     => $campos['codigo'] ?? '',
            'campos'  => $campos,
            'volumes' => $incompleto || $completos === [] ? null : $completos,
            'ficha'   => $ficha,
            'avisos'  => $avisos,
        ];
    }

    /**
     * Os campos do PRODUTO entre as linhas dele: o 1º preenchido vale (vai na 1ª linha ao serviço), outro diferente
     * avisa. O nome volta à parte (toda linha o leva: o serviço o exige).
     *
     * @param  list<array>  $linhas
     * @param  array<string, string>  $titulos
     * @return array{0: string, 1: array<string, string>, 2: list<array>}
     */
    private static function doProduto(array $linhas, array $titulos): array
    {
        $valores = [];
        $de = [];
        $avisos = [];
        foreach ($linhas as $l) {
            foreach (self::DO_PRODUTO as $campo => $rotulo) {
                $valor = $l['campos'][$campo] ?? '';
                if ($valor === '') {
                    continue;
                }
                if (! isset($valores[$campo])) {
                    $valores[$campo] = $valor;
                    $de[$campo] = (int) $l['numero'];
                } elseif (self::comparavel($valor) !== self::comparavel($valores[$campo])) {
                    $avisos[] = self::erro((int) $l['numero'], $titulos[$campo] ?? null, sprintf(self::MSG_DO_PRODUTO, $rotulo, $de[$campo]));
                }
            }
        }
        $nome = $valores['nome'] ?? '';
        unset($valores['nome']);

        return [$nome, $valores, $avisos];
    }

    /**
     * A ficha do produto: a da 1ª linha; as outras só completam o que ela deixou em branco (como no leitor da ficha).
     *
     * @param  list<array>  $linhas
     * @return array<string, array{campo: array, bruto: mixed, linha: int, coluna: string}>
     */
    private static function fichaDoProduto(array $linhas): array
    {
        $ficha = [];
        foreach ($linhas as $l) {
            $ficha += $l['ficha'];
        }

        return $ficha;
    }

    private static function temTituloLongo(array $aba): bool
    {
        foreach ($aba['linhas'] as $l) {
            foreach ($l as $coluna => $valor) {
                if ($coluna !== '__linha' && is_string($valor) && mb_strlen($valor) > LimiteDoTitulo::PADRAO
                    && PlanilhaDasFichasTecnicas::chave(str_replace('*', '', (string) ($aba['cabecalhos'][$coluna] ?? ''))) === PlanilhaDasFichasTecnicas::chave('Título')) {
                    return true;
                }
            }
        }

        return false;
    }

    // ═══ Internos: erros e avisos no formato do contrato ════════════════════

    /** @return array{linha: int, coluna: ?string, mensagem: string} */
    private static function erro(int $linha, ?string $coluna, string $mensagem): array
    {
        return ['linha' => $linha, 'coluna' => $coluna, 'mensagem' => $mensagem];
    }

    /**
     * Os erros de uma `ValidationException` (o `criar`: `nome`, `codigo`…; a `conferirCategoria`:
     * `linhas.{i}.categoria_ml_id`) na linha e na coluna do arquivo. A categoria não é coluna (a aba é a categoria).
     *
     * @param  array<string, list<string>>  $erros
     * @param  list<int>  $numeros
     * @return list<array>
     */
    private static function errosDaValidacao(array $erros, array $numeros, array $linhas, array $titulos): array
    {
        $saida = [];
        foreach ($erros as $chave => $mensagens) {
            $indice = 0;
            $campo = $chave;
            if (preg_match('/^linhas\.(\d+)\.(\w+)$/', (string) $chave, $m)) {
                $indice = (int) $m[1];
                $campo = $m[2];
            }
            $saida[] = self::erro($numeros[$indice] ?? $numeros[0], self::colunaDoCampo($campo, $linhas[$indice] ?? null, $titulos),
                (string) (is_array($mensagens) ? reset($mensagens) : $mensagens));
        }

        return $saida;
    }

    /**
     * Os `erros` do serviço (por linha do pedido: `indice`, `campos`…; ou os da conferência das cores, com `indice` nulo
     * na variação que nenhuma linha tocou) na linha e na coluna do arquivo.
     *
     * @param  list<array>  $erros
     * @param  list<int>  $numeros
     * @return list<array>
     */
    private static function errosDoServico(array $erros, array $numeros, array $linhas, array $titulos, int $primeira, string $geral): array
    {
        $saida = [];
        foreach ($erros as $e) {
            $indice = isset($e['indice']) && $e['indice'] !== null ? (int) $e['indice'] : null;
            $numero = $indice !== null ? ($numeros[$indice] ?? $primeira) : $primeira;
            $campos = (array) ($e['campos'] ?? []);
            if ($campos === []) {
                $saida[] = self::erro($numero, null, (string) ($e['mensagem'] ?? $geral));
                continue;
            }
            foreach ($campos as $campo => $mensagem) {
                $saida[] = self::erro($numero, $indice !== null ? self::colunaDoCampo((string) $campo, $linhas[$indice] ?? null, $titulos) : null, (string) $mensagem);
            }
        }

        return $saida !== [] ? $saida : [self::erro($primeira, null, $geral !== '' ? $geral : self::MSG_FALHA)];
    }

    /**
     * Os avisos do serviço (texto; o de uma variação começa por "{Ref}: ") na linha da Ref, ou na 1ª do produto.
     *
     * @param  list<string>  $avisos
     * @return list<array>
     */
    private static function avisosDoServico(array $avisos, array $linhas, int $primeira): array
    {
        $saida = [];
        foreach ($avisos as $aviso) {
            $numero = $primeira;
            foreach ($linhas as $l) {
                $ref = $l['variacao'] !== null ? (string) $l['variacao']->codigo : $l['ref'];
                if ($ref !== '' && str_starts_with((string) $aviso, $ref.': ')) {
                    $numero = (int) $l['numero'];
                    break;
                }
            }
            $saida[] = self::erro($numero, null, (string) $aviso);
        }

        return $saida;
    }

    /** O título da coluna de um campo da linha do serviço (as caixas: o 1º volume preenchido daquela linha). */
    private static function colunaDoCampo(string $campo, ?array $linha, array $titulos): ?string
    {
        if ($campo === 'volumes') {
            return ModeloCompleto::tituloDoVolume(1, 'c');
        }

        return $titulos[$campo] ?? null;
    }

    /** O 422 do arquivo inteiro (`errors.arquivo`), para quem chama lançar. */
    private static function doArquivo(string $mensagem): ValidationException
    {
        return ValidationException::withMessages(['arquivo' => $mensagem]);
    }

    // ═══ Internos: valores ══════════════════════════════════════════════════

    /**
     * `versoes[produto_id] = hash` como a tela mandou: só id inteiro positivo e texto.
     *
     * @param  array<int|string, mixed>  $versoes
     * @return array<int, string>
     */
    private static function versoes(array $versoes): array
    {
        $saida = [];
        foreach ($versoes as $id => $versao) {
            if (preg_match('/^\d{1,18}$/', (string) $id) && is_string($versao) && trim($versao) !== '') {
                $saida[(int) $id] = trim($versao);
            }
        }

        return $saida;
    }

    private static function vazio(mixed $valor): bool
    {
        return $valor === null || (is_string($valor) && trim($valor) === '');
    }

    /** O valor da célula como a pessoa o digitaria: número com vírgula decimal, inteiro sem casas (como o do Portal). */
    private static function comoTexto(mixed $valor): string
    {
        if ($valor === null || is_bool($valor)) {
            return '';
        }
        if (is_int($valor)) {
            return (string) $valor;
        }
        if (is_float($valor)) {
            return floor($valor) === $valor
                ? (string) (int) $valor
                : str_replace('.', ',', rtrim(rtrim(number_format($valor, 4, '.', ''), '0'), '.'));
        }

        return trim((string) $valor);
    }

    private static function comparavel(string $texto): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $texto)));
    }

    /** "1 produto novo, nenhum recusado." — no gravar, com "Gravado:" na frente. */
    private static function mensagem(array $totais, bool $gravado): string
    {
        $partes = [];
        foreach ([['novos', 'novo', 'novos'], ['atualizados', 'atualizado', 'atualizados'], ['sem_mudanca', 'sem mudança', 'sem mudança']] as [$chave, $um, $varios]) {
            $n = (int) $totais[$chave];
            if ($n === 0) {
                continue;
            }
            $nome = $partes === [] ? ($n === 1 ? 'produto ' : 'produtos ') : '';
            $partes[] = "{$n} {$nome}".($n === 1 ? $um : $varios);
        }
        $recusados = (int) $totais['recusados'];
        if ($partes === []) {
            return ($gravado ? 'Nada gravado: ' : '').($recusados === 1 ? '1 produto recusado.' : "{$recusados} produtos recusados.");
        }
        $texto = implode(', ', $partes).', '.match ($recusados) {
            0       => 'nenhum recusado',
            1       => '1 recusado',
            default => "{$recusados} recusados",
        }.'.';

        return $gravado ? "Gravado: {$texto}" : $texto;
    }
}
