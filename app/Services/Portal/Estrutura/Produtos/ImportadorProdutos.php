<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Portal\CoresDoGrupo;
use App\Support\Publicador\Variacao\ChaveCanonica;
use InvalidArgumentException;

/**
 * Importação da aba Produtos (D-13/D-14), no padrão "plano/aplicar" da colagem
 * de anúncios: stateless — o navegador não manda o que gravar; a confirmação
 * reenvia o arquivo e o plano é refeito do zero (T-167-36). A única coisa que a
 * confirmação traz além do arquivo é a ESCOLHA da pessoa: as categorias que ela
 * confirmou na prévia, por nome digitado (e o servidor confere cada uma).
 *
 * - `previa()` só LÊ: classifica cada linha em novo, atualizado, sem mudança ou
 *   erro, lista as famílias e ambientes que serão criados nas listas e agrupa as
 *   categorias "a confirmar" pelo nome digitado (24 nomes para 56 produtos).
 * - `aplicar()` refaz o plano e grava pelo MESMO serviço da grade
 *   (`ProdutoCadastroService::gravarLinhas` em MODO_IMPORTACAO): código que já
 *   existe atualiza, só com o que a linha trouxe; célula em branco não apaga;
 *   nada é apagado nunca (não existe "substituir"). "SEM MEDIDAS" é célula em
 *   branco e o nome da categoria não rebaixa uma categoria com id (BE-CR-02).
 *
 * ### Planilha de 09/10/2026 (modelo v2)
 * "Tipo de variação" vira o eixo e, com ele preenchido, "Variação" é o NOME da
 * variação ao pé da letra ("220" de voltagem não vira posição). Sem o tipo, a
 * coluna Variação segue a regra antiga (1, 2, única ou "Cor: Natural"), e o
 * produto com variações sem nome ganha um aviso: no cadastro da equipe elas não
 * ficariam juntas (a mesma régua de `CoresDoGrupo`). Estoque é da variação (0 ≠
 * vazio); a descrição é do produto e vale a primeira que vier nas linhas dele.
 *
 * ### Sigilo
 * Nada do que a prévia e o resultado dizem cita a plataforma de venda — nem o id
 * de categoria que a célula traga (ele continua aceito, só não é mencionado).
 */
class ImportadorProdutos
{
    public const DETALHE_MAXIMO = 200;

    /** Quantos nomes de categoria a prévia lista para confirmar (os mais usados primeiro). */
    public const CATEGORIAS_MAXIMO = 200;

    /** Quantos produtos cada aviso de variação cita pelo nome (o resto vira "e mais N"). */
    private const CITADOS = 5;

    /** Exemplos de produto por nome de categoria, na prévia. */
    private const EXEMPLOS_POR_CATEGORIA = 3;

    /** Tolerância (kg) entre o "Peso total" da planilha e a soma dos volumes. */
    private const TOLERANCIA_PESO = 0.05;

    public function __construct(
        private LeitorPlanilhaProdutos $leitor,
        private ProdutoCadastroService $cadastro,
        private ListasDaEmpresaService $listas,
        private CategoriaSugestaoService $categorias,
    ) {}

    /**
     * @return array{erro_geral: ?string, colunas: list<string>, totais: array{novos: int, atualizados: int, sem_mudanca: int, erros: int}, grupos: array{novos: list<array>, atualizados: list<array>, sem_mudanca: list<array>, erros: list<array>}, criar_listas: array{familias: list<string>, ambientes: list<string>}, avisos: list<string>, categorias_a_confirmar: array{nomes: list<array{chave: string, texto: string, produtos: int, exemplos: list<string>}>, total_nomes: int, total_produtos: int}}
     */
    public function previa(Company $empresa, string $caminho): array
    {
        $plano = $this->plano($empresa, $caminho);

        $grupos = [];
        foreach (['novos', 'atualizados', 'sem_mudanca', 'erros'] as $g) {
            $grupos[$g] = array_slice($plano[$g], 0, self::DETALHE_MAXIMO);
        }

        return [
            'erro_geral'   => $plano['erro_geral'],
            'colunas'      => $plano['colunas'],
            'totais'       => [
                'novos'       => count($plano['novos']),
                'atualizados' => count($plano['atualizados']),
                'sem_mudanca' => count($plano['sem_mudanca']),
                'erros'       => count($plano['erros']),
            ],
            'grupos'       => $grupos,
            'criar_listas' => $plano['criar_listas'],
            'avisos'       => $plano['avisos'],
            'categorias_a_confirmar' => [
                'nomes'          => array_slice($plano['categorias'], 0, self::CATEGORIAS_MAXIMO),
                'total_nomes'    => count($plano['categorias']),
                'total_produtos' => array_sum(array_column($plano['categorias'], 'produtos')),
            ],
        ];
    }

    /**
     * Refaz o plano e grava. Os totais vêm do que o serviço de escrita de fato
     * fez (uma variação criada entre a prévia e a confirmação conta como atualizada).
     *
     * `nao_entraram` junta, na ordem do arquivo, as linhas recusadas pelo plano e
     * as que só falharam na GRAVAÇÃO (categoria que não é folha, código que bate no
     * unique do banco): sem elas a tela dizia "concluída" e o produto não existia
     * (BE-WR-04).
     *
     * `$confirmadas` são as categorias escolhidas na prévia, por nome digitado: valem
     * para todo produto do arquivo com aquele nome e sem categoria escolhida antes.
     * Id que o catálogo diz não ser folha é ignorado (o produto fica "a confirmar").
     *
     * @param  list<array{texto?: mixed, id?: mixed}>  $confirmadas
     * @return array{erro_geral?: string, novos: int, atualizados: int, sem_mudanca: int, erros: int, nao_entraram: list<array{linha: ?int, codigo: ?string, motivo: string}>, categorias_confirmadas: int, categorias_a_confirmar: int}
     */
    public function aplicar(Company $empresa, string $caminho, AtorDoPortal $ator, array $confirmadas = []): array
    {
        $plano = $this->plano($empresa, $caminho, $this->confirmadasValidas($confirmadas));

        if ($plano['erro_geral'] !== null) {
            return ['erro_geral' => $plano['erro_geral'], 'novos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0,
                'nao_entraram' => [], 'categorias_confirmadas' => 0, 'categorias_a_confirmar' => 0];
        }

        // Linhas válidas na ordem do arquivo (a 1ª linha de cada grupo define o produto).
        $linhas = array_column($plano['linhas'], 'bruta');

        $naoEntraram = array_map(
            fn (array $e) => ['linha' => $e['linha'], 'codigo' => $e['codigo'], 'motivo' => (string) $e['motivo']],
            $plano['erros'],
        );

        $totais = ['criadas' => 0, 'atualizadas' => 0, 'sem_mudanca' => 0, 'com_erro' => 0];
        $gravadas = [];
        if ($linhas !== []) {
            $res = $this->cadastro->gravarLinhas($empresa, $linhas, $ator, ProdutoCadastroService::MODO_IMPORTACAO);
            $totais = $res['totais'];
            $gravadas = $res['linhas'];

            foreach ($res['erros'] as $e) {
                $naoEntraram[] = [
                    'linha'  => $plano['linhas'][$e['indice']]['numero'] ?? null,
                    'codigo' => $e['codigo'],
                    'motivo' => $e['mensagem'],
                ];
            }
        }

        usort($naoEntraram, fn ($a, $b) => ($a['linha'] ?? PHP_INT_MAX) <=> ($b['linha'] ?? PHP_INT_MAX));

        // Categoria por PRODUTO, contada no que o banco ficou (as linhas devolvidas pela gravação).
        $estadoPorProduto = [];
        $produtoDaRef = [];
        foreach ($gravadas as $l) {
            $estadoPorProduto[(int) $l['produto_id']] = $l['categoria_estado'];
            $produtoDaRef[ProdutoCadastroService::chaveCodigo((string) $l['codigo'])] = (int) $l['produto_id'];
        }
        $confirmadasAgora = [];
        foreach ($plano['confirmadas_refs'] as $ref) {
            $produto = $produtoDaRef[ProdutoCadastroService::chaveCodigo($ref)] ?? null;
            if ($produto !== null && ($estadoPorProduto[$produto] ?? null) === EstruturaProduto::CATEGORIA_CONFIRMADA) {
                $confirmadasAgora[$produto] = true;
            }
        }

        return [
            'novos'                  => $totais['criadas'],
            'atualizados'            => $totais['atualizadas'],
            'sem_mudanca'            => $totais['sem_mudanca'],
            'erros'                  => count($plano['erros']) + $totais['com_erro'],
            'nao_entraram'           => $naoEntraram,
            'categorias_confirmadas' => count($confirmadasAgora),
            'categorias_a_confirmar' => count(array_filter($estadoPorProduto, fn ($e) => $e === EstruturaProduto::CATEGORIA_A_CONFIRMAR)),
        ];
    }

    // ═══ Plano ══════════════════════════════════════════════════════════════

    /**
     * Três passadas: (1) cada linha sozinha — leitura, validação, Ref única no arquivo;
     * (2) cada PRODUTO — descrição, categoria a confirmar, nomes e variações que não
     * ficariam juntas; (3) a classificação de cada linha contra o que já existe.
     *
     * @param  array<string, string>  $confirmadas  chave do nome digitado => id da categoria escolhida
     */
    private function plano(Company $empresa, string $caminho, array $confirmadas = []): array
    {
        $plano = [
            'erro_geral' => null, 'colunas' => [], 'linhas' => [],
            'novos' => [], 'atualizados' => [], 'sem_mudanca' => [], 'erros' => [],
            'criar_listas' => ['familias' => [], 'ambientes' => []], 'avisos' => [],
            'categorias' => [], 'confirmadas_refs' => [],
        ];

        $lido = $this->leitor->ler($caminho);
        $plano['colunas'] = $lido['colunas'];
        if ($lido['erro_geral'] !== null) {
            $plano['erro_geral'] = $lido['erro_geral'];

            return $plano;
        }

        // ─── O que já existe, só lendo ───
        $existentes = [];          // chave da Ref => variação
        $variacoesDoProduto = [];  // produto_id => list<variação>, na ordem do produto
        $produtos = [];            // produto_id => produto
        EstruturaProdutoVariacao::query()
            ->where('company_id', $empresa->id)
            ->with(['produto.familia', 'produto.ambientes', 'volumes'])
            ->orderBy('produto_id')->orderBy('ordem')->orderBy('id')
            ->get()
            ->each(function (EstruturaProdutoVariacao $v) use (&$existentes, &$variacoesDoProduto, &$produtos) {
                $existentes[ProdutoCadastroService::chaveCodigo($v->codigo)] = $v;
                $variacoesDoProduto[(int) $v->produto_id][] = $v;
                $produtos[(int) $v->produto_id] ??= $v->produto;
            });
        $produtosPorCodigo = [];
        foreach ($produtos as $p) {
            if (trim((string) $p->codigo) !== '') {
                $produtosPorCodigo[ProdutoCadastroService::chaveCodigo($p->codigo)] ??= (int) $p->id;
            }
        }

        $familias = [];
        $ambientes = [];
        $refsVistas = [];   // chaveCodigo => número da 1ª linha com aquela Ref
        $codigosNovos = []; // códigos de produto que o arquivo cria (para saber quem junta com quem)
        $validas = [];

        // ─── 1ª passada: cada linha, sozinha ───
        foreach ($lido['linhas'] as $linha) {
            $numero = $linha['numero'];

            // A linha de exemplo do modelo, que o cliente não apagou: não vira produto,
            // oferta, família "Linha Exemplo" nem ambientes (BE-IN-06).
            if (self::ehLinhaDeExemplo($linha['bruta'])) {
                $plano['avisos'][] = "linha {$numero}: é a linha de exemplo do modelo e foi ignorada.";
                continue;
            }

            // O leitor já recusou a linha (fórmula sem valor salvo em coluna de texto).
            if (isset($linha['erro'])) {
                $plano['erros'][] = [
                    'linha'  => $numero,
                    'codigo' => is_string($linha['bruta']['codigo'] ?? null) ? $linha['bruta']['codigo'] : null,
                    'nome'   => is_string($linha['bruta']['nome'] ?? null) ? $linha['bruta']['nome'] : null,
                    'motivo' => $linha['erro'],
                ];
                continue;
            }

            [$bruta, $avisosDaEntrada] = $this->paraEntrada($linha['bruta']);
            $lida = NormalizadorDeLinha::normalizar($bruta);
            $campos = $lida['campos'];

            if ($lida['erros'] !== []) {
                $plano['erros'][] = [
                    'linha'  => $numero,
                    'codigo' => $campos['codigo'] !== '' ? $campos['codigo'] : null,
                    'nome'   => $campos['nome'] !== '' ? $campos['nome'] : null,
                    'motivo' => reset($lida['erros']),
                ];
                continue;
            }

            // Ref repetida no arquivo: a 2ª sobrescreveria a 1ª na gravação. Vale a 1ª;
            // a repetida vai para os erros na prévia e na aplicação (BE-WR-04).
            $chaveRef = ProdutoCadastroService::chaveCodigo($campos['codigo']);
            if (isset($refsVistas[$chaveRef])) {
                $plano['erros'][] = [
                    'linha'  => $numero,
                    'codigo' => $campos['codigo'],
                    'nome'   => $campos['nome'] !== '' ? $campos['nome'] : null,
                    'motivo' => "A Ref {$campos['codigo']} já está na linha {$refsVistas[$chaveRef]} do arquivo. Deixe uma linha só para cada Ref.",
                ];
                continue;
            }
            $refsVistas[$chaveRef] = $numero;

            foreach ([...$avisosDaEntrada, ...$lida['avisos']] as $a) {
                $plano['avisos'][] = "linha {$numero}: {$a}";
            }
            foreach ($this->avisosDeVolumes($numero, $linha['bruta'], $campos, $lida['presentes']) as $a) {
                $plano['avisos'][] = $a;
            }

            if (in_array('familia', $lida['presentes'], true) && $campos['familia'] !== null) {
                $familias[] = $campos['familia'];
            }
            if (in_array('ambientes', $lida['presentes'], true)) {
                array_push($ambientes, ...$campos['ambientes']);
            }

            $validas[] = [
                'numero'    => $numero,
                'bruta'     => $bruta,
                'campos'    => $campos,
                'presentes' => $lida['presentes'],
                'atual'     => $existentes[$chaveRef] ?? null,
                'produto'   => self::chaveDoProduto($campos, $numero, $existentes, $produtosPorCodigo, $codigosNovos),
            ];
        }

        // ─── 2ª passada: cada produto do arquivo ───
        $porProduto = [];
        foreach ($validas as $i => $v) {
            $porProduto[$v['produto']][] = $i;
        }

        $categorias = [];
        $separadas = ['sem_nome' => [], 'repetida' => [], 'tipos' => []];
        foreach ($porProduto as $chave => $indices) {
            $primeira = $indices[0];
            $existente = str_starts_with($chave, 'p:') ? ($produtos[(int) substr($chave, 2)] ?? null) : null;
            $rotulo = $validas[$primeira]['campos']['grupo'] ?? $validas[$primeira]['campos']['codigo'];
            $nomeDoProduto = $validas[$primeira]['campos']['nome'];

            $nomes = [];
            foreach ($indices as $i) {
                $nomes[ListasDaEmpresaService::chave($validas[$i]['campos']['nome'])] = true;
            }
            if (count($nomes) > 1) {
                $plano['avisos'][] = "Produto (grupo) {$rotulo}: nomes diferentes, usamos o da primeira linha.";
            }

            $this->descricaoNaPrimeiraLinha($validas, $indices, $rotulo, $plano['avisos']);
            $this->categoriaDoProduto($validas, $indices, $existente, $confirmadas, $categorias, $plano['confirmadas_refs']);

            foreach ($this->variacoesSeparadas($validas, $indices, $existente ? ($variacoesDoProduto[$existente->id] ?? []) : []) as $problema) {
                $separadas[$problema][] = $nomeDoProduto;
            }
        }
        foreach ($this->avisosDeVariacoes($separadas) as $a) {
            $plano['avisos'][] = $a;
        }

        usort($categorias, fn ($a, $b) => [$b['produtos'], $a['texto']] <=> [$a['produtos'], $b['texto']]);
        $plano['categorias'] = array_values($categorias);

        // ─── 3ª passada: classificação de cada linha ───
        foreach ($validas as $v) {
            $plano['linhas'][] = ['numero' => $v['numero'], 'bruta' => $v['bruta']];

            $item = ['linha' => $v['numero'], 'codigo' => $v['campos']['codigo'], 'nome' => $v['campos']['nome']];
            if ($v['atual'] === null) {
                $plano['novos'][] = $item;
                continue;
            }

            $mudou = $this->diferencas($v['atual'], $v['campos'], $v['presentes']);
            if ($mudou === []) {
                $plano['sem_mudanca'][] = $item;
            } else {
                $plano['atualizados'][] = $item + ['mudou' => $mudou];
            }
        }

        $plano['criar_listas'] = [
            'familias'  => $this->listas->resolverNomes($empresa, ListasDaEmpresaService::FAMILIA, $familias, null)['novos'],
            'ambientes' => $this->listas->resolverNomes($empresa, ListasDaEmpresaService::AMBIENTE, $ambientes, null)['novos'],
        ];

        return $plano;
    }

    /**
     * A que produto a linha vai, pela MESMA ordem do `ProdutoCadastroService` em
     * MODO_IMPORTACAO: Ref já cadastrada → o produto dela; grupo → o produto com esse
     * código (ou o da variação com essa Ref); sem grupo → produto novo com o código da
     * Ref, sozinho se esse código já for de outro produto. 'p:' é produto que existe;
     * 'n:' é produto que o arquivo cria; 'u:' é um produto novo de uma linha só.
     */
    private static function chaveDoProduto(array $campos, int $numero, array $existentes, array $produtosPorCodigo, array &$codigosNovos): string
    {
        $ref = ProdutoCadastroService::chaveCodigo($campos['codigo']);
        if (isset($existentes[$ref])) {
            return 'p:'.$existentes[$ref]->produto_id;
        }

        if ($campos['grupo'] !== null) {
            $grupo = ProdutoCadastroService::chaveCodigo($campos['grupo']);
            if (isset($produtosPorCodigo[$grupo])) {
                return 'p:'.$produtosPorCodigo[$grupo];
            }
            if (isset($existentes[$grupo])) {
                return 'p:'.$existentes[$grupo]->produto_id;
            }
            $codigosNovos[$grupo] = true;

            return 'n:'.$grupo;
        }

        if (isset($produtosPorCodigo[$ref]) || isset($codigosNovos[$ref])) {
            return 'u:'.$numero;
        }
        $codigosNovos[$ref] = true;

        return 'n:'.$ref;
    }

    /**
     * A descrição é do produto e só a 1ª linha dele a grava: a primeira descrição que vier
     * nas linhas do produto é levada para a 1ª linha (quem escreveu na 2ª não a perde) e as
     * outras saem. Descrições diferentes: vale a primeira, com aviso.
     *
     * @param  list<int>  $indices
     */
    private function descricaoNaPrimeiraLinha(array &$validas, array $indices, string $rotulo, array &$avisos): void
    {
        $escolhida = null;
        $deOnde = null;
        $diferentes = false;
        foreach ($indices as $i) {
            $texto = in_array('descricao', $validas[$i]['presentes'], true) ? $validas[$i]['campos']['descricao'] : null;
            if ($texto === null) {
                continue;
            }
            if ($escolhida === null) {
                [$escolhida, $deOnde] = [$texto, $validas[$i]['numero']];
            } elseif ($texto !== $escolhida) {
                $diferentes = true;
            }
        }

        foreach ($indices as $i) {
            unset($validas[$i]['bruta']['descricao']);
            $validas[$i]['campos']['descricao'] = null;
            $validas[$i]['presentes'] = array_values(array_diff($validas[$i]['presentes'], ['descricao']));
        }
        if ($escolhida !== null) {
            $p = $indices[0];
            $validas[$p]['bruta']['descricao'] = $escolhida;
            $validas[$p]['campos']['descricao'] = $escolhida;
            $validas[$p]['presentes'][] = 'descricao';
        }
        if ($diferentes) {
            $avisos[] = "Produto (grupo) {$rotulo}: descrições diferentes, usamos a da linha {$deOnde}.";
        }
    }

    /**
     * Categoria do produto pela 1ª linha dele (como a gravação). Nome digitado em produto
     * sem categoria escolhida: com a confirmação da pessoa vira a categoria escolhida em
     * todas as linhas do produto; sem ela, entra no grupo "a confirmar" daquele nome.
     * Produto que já tem categoria escolhida fica como está (BE-CR-02).
     *
     * @param  list<int>  $indices
     */
    private function categoriaDoProduto(array &$validas, array $indices, ?EstruturaProduto $existente, array $confirmadas, array &$categorias, array &$confirmadasRefs): void
    {
        $primeira = $validas[$indices[0]];
        $texto = in_array('categoria', $primeira['presentes'], true) ? $primeira['campos']['categoria_texto'] : null;
        if ($texto === null || $primeira['campos']['categoria_ml_id'] !== null) {
            return;
        }
        if ($existente && trim((string) $existente->categoria_ml_id) !== '') {
            return;
        }

        $chave = ListasDaEmpresaService::chave($texto);
        if ($chave === '') {
            return;
        }

        if (isset($confirmadas[$chave])) {
            foreach ($indices as $i) {
                if (! in_array('categoria', $validas[$i]['presentes'], true)) {
                    continue;
                }
                unset($validas[$i]['bruta']['categoria_texto']);
                $validas[$i]['bruta']['categoria_ml_id'] = $confirmadas[$chave];
                $validas[$i]['campos']['categoria_texto'] = null;
                $validas[$i]['campos']['categoria_ml_id'] = $confirmadas[$chave];
            }
            $confirmadasRefs[] = $primeira['campos']['codigo'];

            return;
        }

        $categorias[$chave] ??= ['chave' => $chave, 'texto' => $texto, 'produtos' => 0, 'exemplos' => []];
        $categorias[$chave]['produtos']++;
        if (count($categorias[$chave]['exemplos']) < self::EXEMPLOS_POR_CATEGORIA) {
            $categorias[$chave]['exemplos'][] = $primeira['campos']['nome'];
        }
    }

    /**
     * As variações do produto como ele vai ficar (as do arquivo, com o que já está gravado
     * onde a célula veio em branco, mais as cadastradas que o arquivo não cita) passadas
     * pela régua do Sincronizar (`CoresDoGrupo`): o que ela deixaria fora do produto vira
     * um problema — sem nome, nome repetido ou tipo de variação diferente.
     *
     * @param  list<int>  $indices
     * @param  list<EstruturaProdutoVariacao>  $cadastradas
     * @return list<string> 'sem_nome' | 'repetida' | 'tipos', sem repetir
     */
    private function variacoesSeparadas(array $validas, array $indices, array $cadastradas): array
    {
        $lista = [];
        $citadas = [];
        // Variação nova de produto que já existe herda o eixo da 1ª (a regra da gravação).
        $eixoHerdado = $cadastradas[0]->eixo ?? null;
        foreach ($indices as $i) {
            $v = $validas[$i];
            $atual = $v['atual'];
            $lista[] = [
                'id'    => count($lista) + 1,
                'eixo'  => in_array('eixo', $v['presentes'], true) ? $v['campos']['eixo'] : ($atual ? $atual->eixo : $eixoHerdado),
                'valor' => in_array('valor', $v['presentes'], true) ? $v['campos']['valor'] : $atual?->valor,
            ];
            if ($atual) {
                $citadas[(int) $atual->id] = true;
            }
        }
        foreach ($cadastradas as $x) {
            if (! isset($citadas[(int) $x->id])) {
                $lista[] = ['id' => count($lista) + 1, 'eixo' => $x->eixo, 'valor' => $x->valor];
            }
        }

        $fora = CoresDoGrupo::separar($lista)['fora'];
        if ($fora === []) {
            return [];
        }

        $problemas = [];
        $vistos = [];
        foreach ($lista as $v) {
            $valor = trim((string) $v['valor']);
            $chave = $valor === '' ? '' : ChaveCanonica::texto($valor);
            if (isset($fora[$v['id']])) {
                $problemas[match (true) {
                    $valor === ''          => 'sem_nome',
                    isset($vistos[$chave]) => 'repetida',
                    default                => 'tipos',
                }] = true;
            }
            if ($chave !== '') {
                $vistos[$chave] = true;
            }
        }

        return array_keys($problemas);
    }

    /**
     * @param  array{sem_nome: list<string>, repetida: list<string>, tipos: list<string>}  $separadas  nomes de produto por problema
     * @return list<string>
     */
    private function avisosDeVariacoes(array $separadas): array
    {
        $textos = [
            'sem_nome' => fn (string $q, string $quais) => "{$q} com variação sem nome ({$quais}): dê nome a cada variação (ex.: a cor) para elas ficarem juntas no mesmo produto.",
            'repetida' => fn (string $q, string $quais) => "{$q} com duas variações de mesmo nome ({$quais}): use um nome diferente para cada variação.",
            'tipos'    => fn (string $q, string $quais) => "{$q} com mais de um tipo de variação ({$quais}): use um tipo de variação só em cada produto.",
        ];

        $avisos = [];
        foreach ($textos as $problema => $texto) {
            $nomes = array_values(array_unique($separadas[$problema]));
            if ($nomes === []) {
                continue;
            }
            $quantos = count($nomes) === 1 ? '1 produto' : count($nomes).' produtos';
            $citados = array_slice($nomes, 0, self::CITADOS);
            $quais = implode(', ', $citados).(count($nomes) > self::CITADOS ? ' e mais '.(count($nomes) - self::CITADOS) : '');
            $avisos[] = $texto($quantos, $quais);
        }

        return $avisos;
    }

    /**
     * As confirmações que a tela mandou, prontas para o plano: chave do nome => id. O id
     * que o catálogo diz não ser folha sai (o produto fica "a confirmar"); o que ele não
     * respondeu fica, e a gravação o guarda como "não validada" (a regra do D-06).
     *
     * @param  list<array{texto?: mixed, id?: mixed}>  $confirmadas
     * @return array<string, string>
     */
    private function confirmadasValidas(array $confirmadas): array
    {
        $mapa = [];
        foreach ($confirmadas as $c) {
            $texto = is_array($c) && is_scalar($c['texto'] ?? null) ? trim((string) $c['texto']) : '';
            $id = is_array($c) && is_scalar($c['id'] ?? null) ? strtoupper(trim((string) $c['id'])) : '';
            $chave = ListasDaEmpresaService::chave($texto);
            if ($chave !== '' && preg_match('/^MLB\d{1,17}$/', $id)) {
                $mapa[$chave] = $id;
            }
        }
        if ($mapa === []) {
            return [];
        }

        $detalhes = $this->categorias->detalhes(array_values(array_unique($mapa)));

        return array_filter($mapa, fn (string $id) => ($detalhes[$id]['folha'] ?? null) !== false);
    }

    /** Idêntica, coluna a coluna (sem diferença de espaços), a uma linha de exemplo do modelo (o de hoje ou o antigo). */
    private static function ehLinhaDeExemplo(array $bruta): bool
    {
        $comparavel = fn (mixed $v) => trim((string) preg_replace('/\s+/u', ' ', is_scalar($v) ? (string) $v : ''));

        foreach ([...ModeloProdutosXlsx::EXEMPLOS, ModeloProdutosXlsx::EXEMPLO_ANTIGO] as $exemplo) {
            $igual = true;
            foreach (array_unique([...array_keys($exemplo), ...array_keys($bruta)]) as $campo) {
                if ($comparavel($bruta[$campo] ?? null) !== $comparavel($exemplo[$campo] ?? null)) {
                    $igual = false;
                    break;
                }
            }
            if ($igual) {
                return true;
            }
        }

        return false;
    }

    /**
     * Linha lida da planilha -> contrato da linha de entrada (a categoria crua vira id ou
     * texto; o tipo de variação vira o eixo, e com ele a Variação é o nome ao pé da letra).
     *
     * @return array{0: array, 1: list<string>} [linha de entrada, avisos da linha]
     */
    private function paraEntrada(array $bruta): array
    {
        $avisos = [];

        // Célula em branco NÃO entra na linha: null explícito em `custo`/`categoria` limparia o dado.
        $entrada = array_filter($bruta, fn ($v) => $v !== null && $v !== '');
        unset($entrada['n_volumes'], $entrada['peso_total'], $entrada['categoria'], $entrada['eixo']);

        $tipo = trim((string) ($bruta['eixo'] ?? ''), " \t:");
        if ($tipo !== '') {
            $eixo = NormalizadorDeLinha::eixo($tipo);
            if ($eixo === null) {
                $eixo = 'outro';
                $avisos[] = "o tipo de variação “{$tipo}” não está na lista, usamos “Outro”.";
            }
            $entrada['eixo'] = $eixo;
            unset($entrada['variacao']);

            $nome = trim((string) ($bruta['variacao'] ?? ''));
            // "Cor: Natural" na coluna Variação com o tipo Cor ao lado: o nome é "Natural".
            if (preg_match('/^([^:]+):\s*(.+)$/u', $nome, $m) && NormalizadorDeLinha::eixo(trim($m[1])) === $eixo) {
                $nome = trim($m[2]);
            }
            if ($nome !== '') {
                $entrada['valor'] = $nome;
            }
        }

        $cat = $bruta['categoria'] ?? null;
        if (is_string($cat) && $cat !== '') {
            if (preg_match('/^MLB\d+$/i', trim($cat))) {
                $entrada['categoria_ml_id'] = trim($cat);
            } else {
                $entrada['categoria_texto'] = $cat;
            }
        }

        return [$entrada, $avisos];
    }

    /** @return list<string> */
    private function avisosDeVolumes(int $numero, array $bruta, array $campos, array $presentes): array
    {
        if (! in_array('volumes', $presentes, true) || $campos['volumes'] === []) {
            return [];
        }

        $avisos = [];
        $qtd = count($campos['volumes']);

        $n = $bruta['n_volumes'] ?? null;
        if ($n !== null && $n !== '' && is_numeric($n) && (int) $n !== $qtd) {
            $avisos[] = "linha {$numero}: o Nº volumes ({$n}) é diferente da quantidade de volumes informados ({$qtd}).";
        }

        $peso = $bruta['peso_total'] ?? null;
        if ($peso !== null && $peso !== '') {
            try {
                $informado = NumeroBr::interpretar($peso, NumeroBr::MEDIDA);
            } catch (InvalidArgumentException) {
                $informado = null;
            }
            if ($informado !== null) {
                $soma = array_sum(array_column($campos['volumes'], 'kg'));
                if (abs($soma - $informado) > self::TOLERANCIA_PESO) {
                    $avisos[] = 'linha '.$numero.': o Peso total ('.$this->num($informado).' kg) é diferente da soma dos volumes ('.$this->num($soma).' kg).';
                }
            }
        }

        return $avisos;
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, ',', ''), '0'), ',');
    }

    /** A descrição como a ficha a compara: quebras de linha unificadas, sem espaço nas pontas. */
    private static function descricaoComparavel(?string $texto): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $texto));
    }

    /**
     * Campo a campo, só do que a linha trouxe. Lista vazia = sem mudança.
     *
     * @return list<string>
     */
    private function diferencas(EstruturaProdutoVariacao $v, array $campos, array $presentes): array
    {
        $produto = $v->produto;
        $tem = fn (string $c) => in_array($c, $presentes, true);
        $mudou = [];

        if ($tem('nome') && $produto->nome !== $campos['nome']) {
            $mudou[] = 'nome';
        }

        if ($tem('familia')
            && ListasDaEmpresaService::chave((string) $campos['familia']) !== ListasDaEmpresaService::chave((string) $produto->familia?->nome)) {
            $mudou[] = 'família';
        }

        if ($tem('ambientes')) {
            $a = $produto->ambientes->map(fn ($x) => ListasDaEmpresaService::chave($x->nome))->sort()->values()->all();
            $b = collect($campos['ambientes'])->map(fn ($x) => ListasDaEmpresaService::chave($x))->sort()->values()->all();
            if ($a !== $b) {
                $mudou[] = 'ambientes';
            }
        }

        if ($tem('categoria')) {
            $igual = match (true) {
                $campos['categoria_ml_id'] !== null => strtoupper((string) $produto->categoria_ml_id) === $campos['categoria_ml_id'],
                // Texto não substitui categoria com id (BE-CR-02): para a prévia, não há mudança.
                $campos['categoria_texto'] !== null => trim((string) $produto->categoria_ml_id) !== ''
                    || trim((string) $produto->categoria_ml_nome) === $campos['categoria_texto'],
                default => trim((string) $produto->categoria_ml_id) === '' && trim((string) $produto->categoria_ml_nome) === '',
            };
            if (! $igual) {
                $mudou[] = 'categoria';
            }
        }

        if ($tem('descricao') && self::descricaoComparavel($produto->descricao) !== self::descricaoComparavel($campos['descricao'])) {
            $mudou[] = 'descrição';
        }

        if ($tem('eixo') && (string) $v->eixo !== (string) $campos['eixo']) {
            $mudou[] = 'eixo';
        }
        if ($tem('valor') && (string) $v->valor !== (string) $campos['valor']) {
            $mudou[] = 'valor';
        }
        if ($tem('ordem') && (int) $v->ordem !== (int) $campos['ordem']) {
            $mudou[] = 'ordem';
        }
        if ($tem('custo')) {
            $atual = $v->custo === null ? null : round((float) $v->custo, 2);
            $novo = $campos['custo'] === null ? null : round((float) $campos['custo'], 2);
            if ($atual !== $novo) {
                $mudou[] = 'custo';
            }
        }
        if ($tem('estoque') && ($v->estoque === null ? null : (int) $v->estoque) !== $campos['estoque']) {
            $mudou[] = 'estoque';
        }

        if ($tem('volumes')) {
            $atuais = $v->volumes->map(fn ($x) => [round((float) $x->comprimento, 2), round((float) $x->largura, 2), round((float) $x->altura, 2), round((float) $x->peso, 3)])->all();
            $pedidos = array_map(fn ($x) => [round($x['c'], 2), round($x['l'], 2), round($x['a'], 2), round($x['kg'], 3)], $campos['volumes']);
            if ($atuais !== $pedidos) {
                $mudou[] = 'volumes';
            }
        }

        return $mudou;
    }
}
