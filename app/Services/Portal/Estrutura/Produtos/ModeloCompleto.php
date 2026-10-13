<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use App\Services\Publicador\ExplicacaoDeAtributos;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * O MODELO COMPLETO da planilha (13/10/2026): o produto e a ficha técnica dele na MESMA linha.
 *
 * O pedido do usuário: "preciso dela completa; o problema é que a ficha técnica vem de acordo com a
 * categoria". Até aqui eram dois arquivos em dois tempos — o de Produtos (sem ficha) e, só depois de
 * cadastrar e confirmar a categoria, o da Ficha técnica. Uma planilha não consulta nada sozinha e cada
 * categoria tem colunas diferentes; por isso a categoria é escolhida ANTES de baixar (pelo nome do
 * produto ou navegando), como nos modelos por tipo de produto de outros marketplaces.
 *
 * No mesmo dia o usuário fechou a regra: "todos têm que vir tudo completo, não ter opção de baixar
 * apenas modelo". Por isso há DOIS arquivos, os dois completos: o modelo vazio das categorias escolhidas
 * ({@see self::gerar()}) e "meus produtos" ({@see self::exportar()}), já preenchido com o que a empresa
 * tem — produto, variações e a ficha salva.
 *
 * ### O arquivo
 * Uma aba por categoria, com o nome dela. Em cada aba, as colunas do produto (as do modelo de Produtos,
 * sem a "Categoria" — a aba já é a categoria —, com o grupo na frente e com as medidas de cada volume
 * em COLUNAS SEPARADAS: comprimento, largura, altura e peso; pedido do usuário, "vir separado por
 * coluna", no lugar do texto "120×80×10 · 25,0") e, depois, uma coluna por campo
 * da ficha da categoria, com as mesmas listas, "*" e unidades da planilha da ficha
 * ({@see PlanilhaDasFichasTecnicas::colunasDaFicha()}). Acima do cabeçalho, uma faixa diz de que é cada
 * bloco de colunas (o produto, o produto EMBALADO, a ficha, as medidas FORA DA CAIXA) e, abaixo dele, uma
 * linha diz como preencher cada coluna ({@see self::apresentar()}). Mais "Instruções", "Guia dos campos"
 * (o que é cada coluna e as opções de cada lista) e duas ocultas: "Listas" (as
 * opções) e "Categorias" (qual categoria é cada aba). No exportado, o produto que ainda não tem categoria
 * vai para a aba "Sem categoria": só as colunas do produto, mais a "Categoria" por escrito.
 *
 * ### Sigilo
 * Nada cita a plataforma. A aba "Categorias" guarda só os NÚMEROS do código da categoria (sem as
 * letras do prefixo) e o nome dela; {@see self::categoriasDoArquivo()} devolve o código inteiro.
 *
 * A importação é {@see ImportadorModeloCompleto}: as duas que já existiam, na ordem certa.
 */
class ModeloCompleto
{
    public const ABA_CATEGORIAS = 'Categorias';
    public const ABA_INSTRUCOES = 'Instruções';
    public const ABA_SEM_CATEGORIA = 'Sem categoria';

    /** A1 da aba oculta: é por ela que a importação reconhece o modelo. */
    public const MARCA = 'modelo-completo-v1';

    /** Quantas categorias a pessoa escolhe por modelo vazio. */
    public const MAX_CATEGORIAS = 10;

    /** Teto de abas do exportado: categorias além disso ficam de fora, com aviso nas instruções (raro). */
    public const MAX_ABAS = 60;

    /** Prefixo do código da categoria, tirado na ida e devolvido na volta (o arquivo nunca o carrega). */
    private const PREFIXO = 'MLB';

    /** Na aba oculta, o que marca a aba sem categoria. */
    private const SEM_CODIGO = '-';

    /** As colunas do produto antes e depois das medidas: o grupo na frente, sem a Categoria. */
    public const CAMPOS_ANTES = ['grupo', 'codigo', 'eixo', 'variacao', 'nome', 'familia', 'ambientes'];
    public const CAMPOS_DEPOIS = ['custo', 'estoque', 'descricao'];

    /** As quatro medidas de cada volume, na ordem das colunas (as chaves são as de {@see VolumesTexto}). */
    public const MEDIDAS = ['c' => 'comprimento (cm)', 'l' => 'largura (cm)', 'a' => 'altura (cm)', 'kg' => 'peso (kg)'];

    /** Quantos volumes o modelo vazio oferece; o exportado abre quantos o produto com mais volumes tiver. */
    public const VOLUMES_NO_MODELO = 3;
    public const MAX_VOLUMES = 8;

    /**
     * As linhas de cada aba de produtos (13/10/2026): a faixa que diz de que é cada bloco de colunas, o
     * cabeçalho, a ajuda ("como preencher") e, a partir da 4, os produtos. A importação acha o cabeçalho
     * pela coluna "Produto (grupo)" e pula a ajuda pelo começo dela, então o arquivo antigo (cabeçalho na
     * linha 1) continua valendo.
     */
    public const LINHA_DA_FAIXA = 1;
    public const LINHA_DO_CABECALHO = 2;
    public const LINHA_DA_AJUDA = 3;
    public const PRIMEIRA_LINHA = 4;

    /** Quantas colunas ficam fixas ao rolar para o lado: o grupo e o código da variação. */
    private const COLUNAS_FIXAS = 2;

    /**
     * Os blocos de colunas: o título na faixa, o nome curto (no guia), a cor da faixa — que é também a
     * do cabeçalho OBRIGATÓRIO — e a clara, do cabeçalho opcional.
     */
    private const BLOCOS = [
        'produto'  => ['titulo' => 'PRODUTO · uma linha por variação (cada cor ou tamanho)', 'curto' => 'Produto', 'cor' => '1F2430', 'clara' => 'E5E7EB'],
        'embalado' => ['titulo' => 'PRODUTO EMBALADO', 'curto' => 'Produto embalado', 'cor' => '14532D', 'clara' => 'DCFCE7'],
        'venda'    => ['titulo' => 'CUSTO, ESTOQUE E DESCRIÇÃO', 'curto' => 'Custo, estoque e descrição', 'cor' => '374151', 'clara' => 'E5E7EB'],
        'ficha'    => ['titulo' => 'FICHA TÉCNICA · na 1ª linha de cada produto', 'curto' => 'Ficha técnica', 'cor' => '312E81', 'clara' => 'E0E7FF'],
        'fora'     => ['titulo' => 'MEDIDAS DO PRODUTO FORA DA CAIXA · montado, sem a embalagem', 'curto' => 'Medidas do produto fora da caixa', 'cor' => '7C2D12', 'clara' => 'FFEDD5'],
        'detalhes' => ['titulo' => 'MAIS DETALHES · opcionais', 'curto' => 'Mais detalhes', 'cor' => '4B5563', 'clara' => 'F3F4F6'],
    ];

    /**
     * O "como preencher" das colunas do produto (a linha de ajuda). O do grupo COMEÇA pela marca
     * {@see PlanilhaDasFichasTecnicas::MARCA_DA_AJUDA}: é por ela que a importação pula esta linha.
     */
    private const AJUDA_DO_PRODUTO = [
        'grupo'     => PlanilhaDasFichasTecnicas::MARCA_DA_AJUDA.' ↓  Obrigatório. O código que junta as variações do mesmo produto: repita em todas as linhas dele.',
        'codigo'    => 'Obrigatório. O código de cada variação. Não pode repetir.',
        'eixo'      => 'Opcional. Por onde o produto varia. Escolha da lista; em branco se não varia.',
        'variacao'  => 'A variação desta linha (ex.: Preto). Em branco se o produto não varia.',
        'nome'      => 'Obrigatório. O nome do produto, igual em todas as linhas dele.',
        'familia'   => 'Opcional. A linha ou coleção do produto.',
        'ambientes' => 'Opcional. Onde o produto é usado. Mais de um: separe com /.',
        'custo'     => 'Opcional. Quanto custa uma unidade, em reais. Só o número.',
        'estoque'   => 'Opcional. Quantas unidades você tem. Só o número.',
        'descricao' => 'Opcional. Texto livre sobre o produto.',
        'categoria' => 'O tipo do produto, por escrito (ex.: Poltronas).',
    ];

    /** O aviso, na faixa do último volume aberto, de que há mais volumes recolhidos ao lado. */
    private const COMO_ABRIR_OS_VOLUMES = 'Mais de uma caixa? Abra os outros volumes no + acima destas colunas.';

    private const EXPLICACAO_DO_GRUPO = 'O código do produto: junta as linhas que são variações (cores, tamanhos) do mesmo produto. Produto sem variação também precisa do seu.';

    /** O que nunca vai para uma célula ou nota: qualquer termo que conte para onde o cadastro vai. */
    private const NAO_VAI = '/mercado|an[uú]nci|publica|\bmlb|\bml\b/iu';

    private const LARGURAS = ['grupo' => 18, 'codigo' => 14, 'eixo' => 17, 'variacao' => 18, 'nome' => 32, 'familia' => 24, 'ambientes' => 28,
        'custo' => 13, 'estoque' => 13, 'descricao' => 50, 'categoria' => 28];

    public function __construct(
        private FichaTecnicaDaCategoria $definicao,
        private CategoriaSugestaoService $categorias,
        private PlanilhaDasFichasTecnicas $fichas,
        private PlanilhaDosProdutos $produtos,
        private ExplicacaoDeAtributos $explicacoes,
    ) {}

    /** "Volume 2: largura (cm)" — o cabeçalho de uma medida de um volume. */
    public static function tituloDoVolume(int $numero, string $medida): string
    {
        return "Volume {$numero}: ".self::MEDIDAS[$medida];
    }

    /**
     * As colunas do produto, na ordem: cada uma com a `chave` (o campo do modelo de Produtos, ou
     * `vol:N:medida`), o `titulo` do cabeçalho e a `largura`.
     *
     * @return list<array{chave: string, titulo: string, largura: int}>
     */
    public static function colunas(int $volumes = self::VOLUMES_NO_MODELO, bool $comCategoria = false): array
    {
        $porCampo = array_combine(ModeloProdutosXlsx::CAMPOS, ModeloProdutosXlsx::CABECALHOS);
        $campo = fn (string $c) => ['chave' => $c, 'titulo' => $porCampo[$c], 'largura' => self::LARGURAS[$c]];

        $saida = array_map($campo, self::CAMPOS_ANTES);
        for ($n = 1; $n <= max(1, min($volumes, self::MAX_VOLUMES)); $n++) {
            foreach (array_keys(self::MEDIDAS) as $m) {
                $saida[] = ['chave' => "vol:{$n}:{$m}", 'titulo' => self::tituloDoVolume($n, $m), 'largura' => 22];
            }
        }

        return [...$saida, ...array_map($campo, [...self::CAMPOS_DEPOIS, ...($comCategoria ? ['categoria'] : [])])];
    }

    /** Os cabeçalhos das colunas do produto, na ordem. */
    public static function cabecalhosDoProduto(int $volumes = self::VOLUMES_NO_MODELO, bool $comCategoria = false): array
    {
        return array_column(self::colunas($volumes, $comCategoria), 'titulo');
    }

    /**
     * Cabeçalho (sem caixa, acento nem "*") => chave da coluna, para QUALQUER quantidade de volumes e com
     * a Categoria: é por aqui que a importação sabe de que é cada coluna do produto.
     *
     * @return array<string, string>
     */
    public static function chavesDosCabecalhos(): array
    {
        $saida = [];
        foreach (self::colunas(self::MAX_VOLUMES, true) as $c) {
            $saida[PlanilhaDasFichasTecnicas::chave(str_replace('*', '', $c['titulo']))] = $c['chave'];
        }

        return $saida;
    }

    /**
     * O nome do arquivo do modelo, com o nome da categoria na frente (13/10/2026, "vir nomeado
     * nome da categoria, ex.: poltrona-modelo.xlsx"): quem baixa vários modelos precisa saber qual é
     * qual sem abrir. Até 3 categorias vão todas no nome; acima disso, as 2 primeiras e "e-mais-N".
     * Só letras sem acento, números e hífen, para o nome chegar igual em qualquer navegador.
     *
     * @param  list<array{nome: string}>  $abas  as categorias do arquivo, na ordem das abas
     */
    public static function nomeDoArquivo(array $abas): string
    {
        $nomes = array_values(array_unique(array_filter(array_map(fn ($a) => Str::slug((string) ($a['nome'] ?? '')), $abas))));
        if ($nomes === []) {
            return 'modelo.xlsx';
        }
        if (count($nomes) > 3) {
            $nomes = [$nomes[0], $nomes[1], 'e-mais-'.(count($nomes) - 2)];
        }

        return rtrim(Str::limit(implode('-', $nomes), 120, ''), '-').'-modelo.xlsx';
    }

    /**
     * O modelo VAZIO das categorias escolhidas.
     *
     * @param  list<string>  $ids  códigos das categorias escolhidas (só a última de cada ramo serve)
     * @return array{planilha: ?Spreadsheet, abas: list<array{id: string, nome: string, aba: string, campos: int}>, recusadas: list<array{id: string, motivo: string}>}
     */
    public function gerar(array $ids): array
    {
        $ids = array_slice(array_values(array_unique(array_map(fn ($i) => mb_strtoupper(trim((string) $i)), $ids))), 0, self::MAX_CATEGORIAS);
        $detalhes = $ids === [] ? [] : $this->categorias->detalhes($ids);

        $dados = [];
        $recusadas = [];
        foreach ($ids as $id) {
            $d = $detalhes[$id] ?? null;
            if ($d === null) {
                $recusadas[] = ['id' => $id, 'motivo' => 'Não achamos esta categoria agora.'];
                continue;
            }
            if (! $d['folha']) {
                $recusadas[] = ['id' => $id, 'motivo' => "“{$d['nome']}” ainda tem categorias dentro dela. Escolha uma mais específica."];
                continue;
            }
            $campos = $this->camposDa($id);
            if ($campos === []) {
                $recusadas[] = ['id' => $id, 'motivo' => "A ficha técnica de “{$d['nome']}” não está disponível agora. Tente de novo em instantes."];
                continue;
            }
            $dados[] = ['id' => $id, 'nome' => $d['nome'], 'caminho' => implode(' > ', array_column($d['caminho'], 'nome')), 'campos' => $campos, 'linhas' => []];
        }

        return ['recusadas' => $recusadas] + $this->montar($dados, [], false);
    }

    /**
     * "Meus produtos", completo: os produtos da empresa, cada um na aba da categoria dele, com as
     * variações e a ficha técnica que está salva. Produto sem categoria vai para a aba "Sem categoria".
     *
     * @return array{planilha: ?Spreadsheet, abas: list<array{id: ?string, nome: string, aba: string, campos: int}>, produtos: int}
     */
    public function exportar(Company $empresa): array
    {
        $porProduto = $this->produtos->porProduto($empresa);
        if ($porProduto === []) {
            return ['planilha' => null, 'abas' => [], 'produtos' => 0];
        }

        $salvos = [];
        EstruturaProdutoAtributo::query()
            ->where('company_id', $empresa->id)
            ->whereIn('produto_id', array_map(fn ($p) => (int) $p['produto']->id, $porProduto))
            ->get()
            ->each(function (EstruturaProdutoAtributo $a) use (&$salvos) {
                $salvos[(int) $a->produto_id][(string) $a->atributo_id] = $a;
            });

        $porCategoria = [];
        $semCategoria = [];
        foreach ($porProduto as $p) {
            $produto = $p['produto'];
            $id = mb_strtoupper(trim((string) $produto->categoria_ml_id));
            $nome = trim((string) $produto->categoria_ml_nome);
            // Só a categoria confirmada (código e nome) dá aba própria; o resto fica na "Sem categoria".
            if ($id === '' || $nome === '' || (! isset($porCategoria[$id]) && count($porCategoria) >= self::MAX_ABAS)) {
                foreach ($p['linhas'] as $linha) {
                    $semCategoria[] = ['produto' => $linha, 'ficha' => []];
                }
                continue;
            }
            $porCategoria[$id] ??= ['id' => $id, 'nome' => $nome, 'caminho' => '', 'campos' => $this->camposDa($id), 'linhas' => []];
            foreach ($p['linhas'] as $n => $linha) {
                $ficha = [];
                if ($n === 0) {
                    // A ficha é do produto: vai na 1ª linha dele.
                    foreach ($porCategoria[$id]['campos'] as $campo) {
                        $ficha[$campo['id']] = PlanilhaDasFichasTecnicas::textoDaCelula($campo, $salvos[(int) $produto->id][$campo['id']] ?? null);
                    }
                }
                $porCategoria[$id]['linhas'][] = ['produto' => $linha, 'ficha' => $ficha];
            }
        }
        uasort($porCategoria, fn ($a, $b) => strnatcasecmp($a['nome'], $b['nome']));

        return ['produtos' => count($porProduto)] + $this->montar(array_values($porCategoria), $semCategoria, true);
    }

    /**
     * As categorias de um arquivo do modelo completo: nome da aba => {id, nome}. `id` nulo = a aba dos
     * produtos sem categoria. Vazio = não é o modelo completo (ou a aba oculta foi apagada).
     *
     * @return array<string, array{id: ?string, nome: string}>
     */
    public static function categoriasDoArquivo(string $caminho): array
    {
        try {
            $leitor = IOFactory::createReader('Xlsx');
            $leitor->setReadDataOnly(true);
            $leitor->setLoadSheetsOnly([self::ABA_CATEGORIAS]);
            $planilha = $leitor->load($caminho);
            $folha = $planilha->getSheetByName(self::ABA_CATEGORIAS);
            if ($folha === null || trim((string) $folha->getCell('A1')->getValue()) !== self::MARCA) {
                return [];
            }

            $saida = [];
            $ultima = min((int) $folha->getHighestDataRow(), self::MAX_ABAS + 2);
            for ($linha = 2; $linha <= $ultima; $linha++) {
                $aba = trim((string) $folha->getCell("A{$linha}")->getValue());
                $numero = trim((string) $folha->getCell("B{$linha}")->getValue());
                if ($aba === '') {
                    continue;
                }
                if ($numero === self::SEM_CODIGO) {
                    $saida[$aba] = ['id' => null, 'nome' => ''];
                } elseif (preg_match('/^\d{1,17}$/D', $numero) === 1) {
                    $saida[$aba] = ['id' => self::PREFIXO.$numero, 'nome' => trim((string) $folha->getCell("C{$linha}")->getValue())];
                }
            }
            $planilha->disconnectWorksheets();

            return $saida;
        } catch (\Throwable) {
            return [];
        }
    }

    // ═══ Montagem ═══════════════════════════════════════════════════════════

    /**
     * Os campos da ficha da categoria, na ordem da tela, cada um com o `bloco` em que entra na faixa
     * (`ficha`, `fora` = medidas do produto fora da caixa, `detalhes`), o `grupo` e a `explicacao` (o
     * mesmo "o que é isto?" da tela). Vazio quando a categoria não respondeu.
     *
     * @return list<array<string, mixed>>
     */
    private function camposDa(string $id): array
    {
        try {
            $campos = [];
            foreach ($this->definicao->definicaoComExplicacoes($id) as $grupo) {
                $bloco = match (true) {
                    (bool) ($grupo['medidas_do_produto'] ?? false) => 'fora',
                    ($grupo['grupo'] ?? '') === FichaTecnicaDaCategoria::GRUPO_MAIS_DETALHES => 'detalhes',
                    default => 'ficha',
                };
                foreach ($grupo['campos'] as $campo) {
                    $campos[$campo['id']] = ['bloco' => $bloco, 'grupo' => (string) ($grupo['grupo'] ?? '')] + $campo;
                }
            }

            return array_values($campos);
        } catch (\Throwable $e) {
            Log::warning("[Estrutura Produtos] ficha da categoria {$id} indisponível para o modelo completo", ['erro' => $e->getMessage()]);

            return [];
        }
    }

    /** O bloco de uma coluna do produto: o produto em si, o produto embalado (volumes) ou custo/estoque/descrição. */
    private static function blocoDoProduto(string $chave): string
    {
        return str_starts_with($chave, 'vol:') ? 'embalado' : (in_array($chave, self::CAMPOS_ANTES, true) ? 'produto' : 'venda');
    }

    /** O "como preencher" curto de uma coluna do produto (a linha de ajuda). */
    private static function ajudaDoProduto(string $chave): string
    {
        if (str_starts_with($chave, 'vol:')) {
            [, $n, $medida] = explode(':', $chave);
            $caixa = (int) $n === 1 ? 'da caixa com o produto dentro' : "da caixa {$n} (só se o produto for em mais de uma caixa)";

            return match ($medida) {
                'kg'    => "Peso {$caixa}. Só o número, em kg.",
                'c'     => "Comprimento {$caixa}. Só o número, em cm.",
                'l'     => "Largura {$caixa}. Só o número, em cm.",
                default => "Altura {$caixa}. Só o número, em cm.",
            };
        }

        return self::AJUDA_DO_PRODUTO[$chave] ?? '';
    }

    /** A chave do glossário do Portal (`publicador_glossario.portal_campos`) que explica uma coluna do produto. */
    private static function chaveDoGlossario(string $chave): ?string
    {
        if (str_starts_with($chave, 'vol:')) {
            return ['c' => 'comprimento', 'l' => 'largura', 'a' => 'altura', 'kg' => 'peso'][explode(':', $chave)[2]] ?? null;
        }

        return ['codigo' => 'ref', 'eixo' => 'eixo', 'variacao' => 'valor', 'nome' => 'nome', 'familia' => 'familia', 'ambientes' => 'ambientes',
            'custo' => 'custo', 'estoque' => 'estoque', 'descricao' => 'descricao', 'categoria' => 'categoria'][$chave] ?? null;
    }

    /** O texto pode ir para a planilha do cliente? (nada que conte para onde o cadastro vai.) */
    private static function podeIr(?string $texto): bool
    {
        $texto = trim((string) $texto);

        return $texto !== '' && preg_match(self::NAO_VAI, $texto) !== 1 && ! ExplicacaoDeAtributos::revelaDestino($texto);
    }

    /**
     * As colunas de uma aba, já com tudo o que a apresentação precisa: de que bloco é, o título, a
     * ajuda curta ("como preencher"), a explicação longa ("o que é") e as opções.
     *
     * @param  list<array{chave: string, titulo: string, largura: int}>  $doProduto
     * @param  list<array<string, mixed>>  $campos
     * @return list<array{bloco: string, grupo: string, titulo: string, obrigatorio: bool, ajuda: string, explicacao: string, opcoes: string}>
     */
    private function colunasDaAba(array $doProduto, array $campos): array
    {
        $glossario = $this->explicacoes->camposDoPortal();
        $saida = [];

        foreach ($doProduto as $c) {
            $chave = self::chaveDoGlossario($c['chave']);
            $explicacao = $c['chave'] === 'grupo' ? self::EXPLICACAO_DO_GRUPO : (string) ($glossario[$chave] ?? '');
            $saida[] = [
                'bloco'       => self::blocoDoProduto($c['chave']),
                'grupo'       => str_starts_with($c['chave'], 'vol:') ? 'Volume '.explode(':', $c['chave'])[1] : '',
                'titulo'      => $c['titulo'],
                'obrigatorio' => str_contains($c['titulo'], '*'),
                'ajuda'       => self::ajudaDoProduto($c['chave']),
                'explicacao'  => self::podeIr($explicacao) ? $explicacao : '',
                'opcoes'      => $c['chave'] === 'eixo' ? implode(' · ', array_values(EstruturaProdutoVariacao::EIXOS)) : '',
            ];
        }

        foreach ($campos as $campo) {
            $opcoes = array_column($campo['valores'] ?? [], 'nome');
            if ($campo['tipo'] === FichaTecnicaDaCategoria::TIPO_SIM_NAO) {
                $opcoes = ['Sim', 'Não'];
            }
            if ($opcoes !== [] && ($campo['nao_se_aplica'] ?? false)) {
                $opcoes[] = PlanilhaDasFichasTecnicas::NAO_SE_APLICA;
            }
            $explicacao = (string) ($campo['explicacao'] ?? '');
            $saida[] = [
                'bloco'       => (string) $campo['bloco'],
                'grupo'       => in_array($campo['grupo'], ['', 'Outros', FichaTecnicaDaCategoria::GRUPO_PADRAO, FichaTecnicaDaCategoria::GRUPO_MAIS_DETALHES, FichaTecnicaDaCategoria::GRUPO_MEDIDAS_DO_PRODUTO], true) ? '' : (string) $campo['grupo'],
                'titulo'      => PlanilhaDasFichasTecnicas::cabecalho($campo),
                'obrigatorio' => (bool) $campo['obrigatorio'],
                'ajuda'       => PlanilhaDasFichasTecnicas::comoPreencher($campo, $campo['bloco'] === 'fora'),
                'explicacao'  => self::podeIr($explicacao) ? $explicacao : '',
                'opcoes'      => Str::limit(implode(' · ', $opcoes), 900, ' …'),
            ];
        }

        return $saida;
    }

    /**
     * @param  list<array{id: string, nome: string, caminho: string, campos: list<array>, linhas: list<array{produto: array<string, string>, ficha: array<string, string>}>}>  $categorias
     * @param  list<array{produto: array<string, string>, ficha: array}>  $semCategoria
     * @return array{planilha: ?Spreadsheet, abas: list<array{id: ?string, nome: string, aba: string, campos: int}>}
     */
    private function montar(array $categorias, array $semCategoria, bool $preenchido): array
    {
        if ($categorias === [] && $semCategoria === []) {
            return ['planilha' => null, 'abas' => []];
        }

        $planilha = new Spreadsheet();
        $planilha->getProperties()->setCreator('ECF')->setLastModifiedBy('ECF')->setTitle('Produtos e ficha técnica')
            ->setSubject('')->setDescription('')->setKeywords('')->setCategory('');
        $planilha->removeSheetByIndex(0);

        // Quantos volumes cabem em cada linha: os do modelo, ou mais, se algum produto exportado tiver mais.
        $volumes = self::VOLUMES_NO_MODELO;
        foreach ([...array_merge([], ...array_column($categorias, 'linhas')), ...$semCategoria] as $linha) {
            $volumes = max($volumes, count(VolumesTexto::interpretar($linha['produto']['volumes_texto'] ?? '')['volumes']));
        }
        $volumes = min($volumes, self::MAX_VOLUMES);
        $doProduto = self::colunas($volumes);

        $listas = new Worksheet($planilha, PlanilhaDasFichasTecnicas::ABA_LISTAS);
        $colunaDaLista = 0;
        $usados = [self::ABA_SEM_CATEGORIA, PlanilhaDasFichasTecnicas::ABA_GUIA];
        $abas = [];
        $mapa = [];
        $guia = [];
        $salto = self::PRIMEIRA_LINHA - 1;   // quantas linhas há acima do primeiro produto
        // Quantos volumes o produto com mais caixas de uma aba usa: são os que ficam abertos nela.
        $maisVolumes = fn (array $linhas) => array_reduce($linhas,
            fn (int $m, array $l) => max($m, count(VolumesTexto::interpretar($l['produto']['volumes_texto'] ?? '')['volumes'])), 0);

        foreach ($categorias as $c) {
            $nomeDaAba = PlanilhaDasFichasTecnicas::nomeDeAba($c['nome'], $usados);
            $usados[] = $nomeDaAba;
            $aba = new Worksheet($planilha, $nomeDaAba);
            $planilha->addSheet($aba);
            $fim = max(LeitorPlanilhaProdutos::MAX_LINHAS + 1, count($c['linhas']) + $salto);

            $this->colunasDoProduto($aba, $doProduto, $fim);
            $primeiraDaFicha = count($doProduto) + 1;
            if ($c['campos'] !== []) {
                $this->fichas->colunasDaFicha($aba, $c['campos'], $primeiraDaFicha, $fim, $listas, $colunaDaLista, self::LINHA_DO_CABECALHO, self::PRIMEIRA_LINHA);
            }
            foreach ($c['linhas'] as $n => $linha) {
                self::linhaDoProduto($aba, $doProduto, $n + self::PRIMEIRA_LINHA, $linha['produto']);
                foreach ($c['campos'] as $i => $campo) {
                    $texto = (string) ($linha['ficha'][$campo['id']] ?? '');
                    if ($texto !== '') {
                        PlanilhaDasFichasTecnicas::escreverValor($aba, Coordinate::stringFromColumnIndex($primeiraDaFicha + $i).($n + self::PRIMEIRA_LINHA), $campo, $texto);
                    }
                }
            }
            $colunas = $this->colunasDaAba($doProduto, $c['campos']);
            $abertos = max(1, $maisVolumes($c['linhas']));
            self::apresentar($aba, $colunas, $abertos);
            self::recolherVolumes($aba, $doProduto, $abertos);
            $guia[] = [$nomeDaAba, array_slice($colunas, count($doProduto))];

            $abas[] = ['id' => $c['id'], 'nome' => $c['nome'], 'aba' => $nomeDaAba, 'campos' => count($c['campos'])];
            $mapa[] = [$nomeDaAba, substr($c['id'], strlen(self::PREFIXO)), $c['nome'], $c['caminho']];
        }

        if ($semCategoria !== []) {
            $comCategoria = self::colunas($volumes, true);
            $aba = new Worksheet($planilha, self::ABA_SEM_CATEGORIA);
            $planilha->addSheet($aba);
            $this->colunasDoProduto($aba, $comCategoria, max(LeitorPlanilhaProdutos::MAX_LINHAS + 1, count($semCategoria) + $salto));
            foreach ($semCategoria as $n => $linha) {
                self::linhaDoProduto($aba, $comCategoria, $n + self::PRIMEIRA_LINHA, $linha['produto']);
            }
            $abertos = max(1, $maisVolumes($semCategoria));
            self::apresentar($aba, $this->colunasDaAba($comCategoria, []), $abertos);
            self::recolherVolumes($aba, $comCategoria, $abertos);
            $abas[] = ['id' => null, 'nome' => self::ABA_SEM_CATEGORIA, 'aba' => self::ABA_SEM_CATEGORIA, 'campos' => 0];
            $mapa[] = [self::ABA_SEM_CATEGORIA, self::SEM_CODIGO, '', ''];
        }

        $instrucoes = new Worksheet($planilha, self::ABA_INSTRUCOES);
        $planilha->addSheet($instrucoes);
        self::preencherInstrucoes($instrucoes, count($categorias), $preenchido, $semCategoria !== []);

        $doGuia = new Worksheet($planilha, PlanilhaDasFichasTecnicas::ABA_GUIA);
        $planilha->addSheet($doGuia);
        self::preencherGuia($doGuia, $this->colunasDaAba(self::colunas($volumes, $semCategoria !== []), []), $guia);

        $listas->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $planilha->addSheet($listas);

        $oculta = new Worksheet($planilha, self::ABA_CATEGORIAS);
        $planilha->addSheet($oculta);
        self::texto($oculta, 'A1', self::MARCA);
        foreach ($mapa as $n => $linha) {
            foreach ($linha as $i => $valor) {
                self::texto($oculta, Coordinate::stringFromColumnIndex($i + 1).($n + 2), (string) $valor);
            }
        }
        $oculta->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $planilha->setActiveSheetIndex(0);

        return ['planilha' => $planilha, 'abas' => $abas];
    }

    /** Os cabeçalhos, larguras e formatos das colunas do produto, e a lista do "Tipo de variação". */
    private function colunasDoProduto(Worksheet $aba, array $colunas, int $fim): void
    {
        $de = self::PRIMEIRA_LINHA;
        foreach ($colunas as $i => $c) {
            $coluna = Coordinate::stringFromColumnIndex($i + 1);
            self::texto($aba, $coluna.self::LINHA_DO_CABECALHO, $c['titulo']);
            $aba->getColumnDimension($coluna)->setWidth($c['largura']);
            if (in_array($c['chave'], ['grupo', 'codigo', 'variacao'], true)) {
                $aba->getStyle("{$coluna}{$de}:{$coluna}{$fim}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
            if ($c['chave'] === 'eixo') {
                $aba->setDataValidation("{$coluna}{$de}:{$coluna}{$fim}", self::listaDeTipos());
            }
        }
    }

    /** Uma linha do produto: os campos de sempre e, das medidas em texto, uma célula por medida de cada volume. */
    private static function linhaDoProduto(Worksheet $aba, array $colunas, int $numero, array $linha): void
    {
        $volumes = VolumesTexto::interpretar($linha['volumes_texto'] ?? '')['volumes'];
        foreach ($colunas as $i => $c) {
            if (str_starts_with($c['chave'], 'vol:')) {
                [, $n, $medida] = explode(':', $c['chave']);
                $bruto = $volumes[(int) $n - 1][$medida] ?? null;
                $valor = $bruto === null ? '' : str_replace('.', ',', rtrim(rtrim(number_format((float) $bruto, 3, '.', ''), '0'), '.'));
            } else {
                $valor = trim((string) ($linha[$c['chave']] ?? ''));
            }
            if ($valor !== '') {
                self::texto($aba, Coordinate::stringFromColumnIndex($i + 1).$numero, $valor);
            }
        }
    }

    /**
     * A apresentação da aba (13/10/2026, "tem muitas dimensões que pedem para preencher mas não é
     * especificado o que é: produto fora da caixa, embalado"):
     *
     *  - linha 1, a FAIXA: de que é cada bloco de colunas (o produto, o produto EMBALADO, a ficha técnica,
     *    as medidas FORA DA CAIXA, os detalhes opcionais), cada bloco numa cor. O título se repete a cada
     *    4 colunas para continuar à vista quando a pessoa rola para o lado;
     *  - linha 2, o cabeçalho: escuro e com "*" no obrigatório, claro no opcional — o campo que não vale
     *    para o produto (a medida do colchão numa poltrona) deixa de parecer cobrança;
     *  - linha 3, a AJUDA: como preencher cada coluna, e onde dá para responder "Não se aplica";
     *  - a explicação longa ("o que é") vai de nota no cabeçalho e na aba "Guia dos campos".
     *
     * @param  list<array{bloco: string, grupo: string, titulo: string, obrigatorio: bool, ajuda: string, explicacao: string, opcoes: string}>  $colunas
     */
    private static function apresentar(Worksheet $aba, array $colunas, int $volumesAbertos = self::MAX_VOLUMES): void
    {
        // Há volume recolhido depois do último aberto? A faixa desse último avisa como abrir os outros.
        $ultimoAberto = 'Volume '.$volumesAbertos;
        $haRecolhidos = in_array('Volume '.($volumesAbertos + 1), array_column($colunas, 'grupo'), true);
        $letra = fn (int $i) => Coordinate::stringFromColumnIndex($i + 1);
        $faixa = self::LINHA_DA_FAIXA;
        $cabecalho = self::LINHA_DO_CABECALHO;
        $ajuda = self::LINHA_DA_AJUDA;

        // A faixa: pedaços de até 4 colunas do mesmo bloco (e do mesmo grupo), cada um com o título.
        $inicio = 0;
        $total = count($colunas);
        while ($inicio < $total) {
            $c = $colunas[$inicio];
            $bloco = self::BLOCOS[$c['bloco']];
            // As duas primeiras colunas ficam fixas na tela: nenhuma mescla atravessa esse limite (há programa
            // de planilha que não fixa coluna cortando célula mesclada). Depois delas, o bloco do produto é um só.
            $teto = match (true) {
                $inicio < self::COLUNAS_FIXAS => self::COLUNAS_FIXAS - $inicio,
                $c['bloco'] === 'produto'      => $total,
                default                         => 4,
            };
            $fim = $inicio;
            while ($fim + 1 < $total && $fim + 1 - $inicio < $teto && $colunas[$fim + 1]['bloco'] === $c['bloco'] && $colunas[$fim + 1]['grupo'] === $c['grupo']) {
                $fim++;
            }
            $primeiroDoBloco = $inicio === 0 || $colunas[$inicio - 1]['bloco'] !== $c['bloco'];
            $titulo = match (true) {
                $c['bloco'] === 'embalado' => $bloco['titulo'].' · '.$c['grupo'].($c['grupo'] === 'Volume 1' ? ': a caixa com o produto dentro.' : ': só se for em mais de uma caixa.')
                    .($haRecolhidos && $c['grupo'] === $ultimoAberto ? ' '.self::COMO_ABRIR_OS_VOLUMES : ''),
                $c['grupo'] !== ''          => mb_strtoupper($bloco['curto']).' · '.$c['grupo'],
                $inicio < self::COLUNAS_FIXAS => mb_strtoupper($bloco['curto']),
                $primeiroDoBloco || $inicio === self::COLUNAS_FIXAS => $bloco['titulo'],
                default                     => mb_strtoupper($bloco['curto']).' · continua',
            };
            $area = $letra($inicio).$faixa.':'.$letra($fim).$faixa;
            self::texto($aba, $letra($inicio).$faixa, $titulo);
            if ($fim > $inicio) {
                $aba->mergeCells($area);
            }
            $aba->getStyle($area)->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bloco['cor']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
            ]);
            $inicio = $fim + 1;
        }

        foreach ($colunas as $i => $c) {
            $bloco = self::BLOCOS[$c['bloco']];
            $aba->getStyle($letra($i).$cabecalho)->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['rgb' => $c['obrigatorio'] ? 'FFFFFF' : '1F2937']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $c['obrigatorio'] ? $bloco['cor'] : $bloco['clara']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            if ($c['ajuda'] !== '') {
                self::texto($aba, $letra($i).$ajuda, $c['ajuda']);
            }
            if ($c['explicacao'] !== '') {
                $nota = $aba->getComment($letra($i).$cabecalho);
                $nota->setAuthor('ECF')->setWidth('280pt')->setHeight('110pt');
                $nota->getText()->createTextRun($c['explicacao']);
            }
        }

        $ultima = $letra($total - 1);
        $aba->getStyle("A{$ajuda}:{$ultima}{$ajuda}")->applyFromArray([
            'font'      => ['size' => 9, 'color' => ['rgb' => '374151']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '9CA3AF']]],
        ]);
        $aba->getRowDimension($faixa)->setRowHeight(34);
        $aba->getRowDimension($cabecalho)->setRowHeight(34);
        $aba->getRowDimension($ajuda)->setRowHeight(82);
        $aba->freezePane(Coordinate::stringFromColumnIndex(self::COLUNAS_FIXAS + 1).self::PRIMEIRA_LINHA);
    }

    /**
     * Deixa à vista só os primeiros volumes e recolhe o resto num grupo de colunas (13/10/2026, "em
     * produto embalado, deixa apenas um volume; o resto deixa minimizado"). A maioria dos produtos vai
     * numa caixa só, e doze colunas de medida abertas empurravam a ficha técnica para longe. Quem tem
     * mais caixas abre o grupo no botão "+" que fica acima das colunas, colado no último volume aberto.
     *
     * No "meus produtos" ficam abertos quantos volumes o produto com mais caixas daquela aba usa: medida
     * já preenchida nunca nasce escondida. Recolher não muda a importação — ela lê as colunas do mesmo jeito.
     *
     * @param  list<array{chave: string}>  $colunas  as colunas do produto, na ordem
     */
    private static function recolherVolumes(Worksheet $aba, array $colunas, int $abertos): void
    {
        $primeira = null;
        foreach ($colunas as $i => $c) {
            if (! str_starts_with($c['chave'], 'vol:') || (int) explode(':', $c['chave'])[1] <= max(1, $abertos)) {
                continue;
            }
            $primeira ??= $i;
            $aba->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setOutlineLevel(1)->setVisible(false);
        }
        if ($primeira === null) {
            return;
        }
        // O botão de abrir fica à ESQUERDA do grupo, na última coluna do volume aberto (e não lá no Custo).
        $aba->setShowSummaryRight(false);
        $aba->getColumnDimension(Coordinate::stringFromColumnIndex($primeira))->setCollapsed(true);
    }

    /**
     * A aba "Guia dos campos": uma linha por coluna das outras abas, dizendo o que é, como preencher e
     * quais são as opções da lista. As colunas do produto vêm uma vez (valem para todas as abas).
     *
     * @param  list<array<string, mixed>>  $doProduto
     * @param  list<array{0: string, 1: list<array<string, mixed>>}>  $porAba  nome da aba e as colunas da ficha dela
     */
    private static function preencherGuia(Worksheet $aba, array $doProduto, array $porAba): void
    {
        $titulos = ['Aba', 'Bloco', 'Coluna', 'Obrigatório', 'O que é', 'Como preencher', 'Opções da lista'];
        foreach ($titulos as $i => $titulo) {
            self::texto($aba, Coordinate::stringFromColumnIndex($i + 1).'1', $titulo);
        }
        foreach ([18, 34, 34, 13, 70, 60, 80] as $i => $largura) {
            $aba->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($largura);
        }

        $linha = 2;
        $escrever = function (string $nomeDaAba, array $c) use ($aba, &$linha) {
            // No guia a ajuda vai sem a marca que abre a linha de ajuda da aba (ela só serve lá).
            $comoPreencher = trim((string) preg_replace('/^'.preg_quote(PlanilhaDasFichasTecnicas::MARCA_DA_AJUDA, '/').'\s*↓\s*/u', '', $c['ajuda']));
            $valores = [$nomeDaAba, self::BLOCOS[$c['bloco']]['curto'].($c['grupo'] !== '' ? ' · '.$c['grupo'] : ''), $c['titulo'],
                $c['obrigatorio'] ? 'Sim' : 'Não', $c['explicacao'], $comoPreencher, $c['opcoes']];
            foreach ($valores as $i => $valor) {
                if ($valor !== '') {
                    self::texto($aba, Coordinate::stringFromColumnIndex($i + 1).$linha, $valor);
                }
            }
            $linha++;
        };
        foreach ($doProduto as $c) {
            $escrever('Todas', $c);
        }
        foreach ($porAba as [$nomeDaAba, $colunas]) {
            foreach ($colunas as $c) {
                $escrever($nomeDaAba, $c);
            }
        }

        $aba->getStyle('A1:G1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2430']],
        ]);
        $aba->getStyle('A2:G'.max(2, $linha - 1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $aba->freezePane('A2');
    }

    private static function listaDeTipos(): DataValidation
    {
        $v = new DataValidation();
        $v->setType(DataValidation::TYPE_LIST);
        $v->setAllowBlank(true);
        $v->setShowDropDown(true);
        $v->setShowInputMessage(true);
        $v->setPromptTitle('Tipo de variação');
        $v->setPrompt('Por onde o produto varia. Deixe em branco se não varia.');
        $v->setFormula1('"'.implode(',', array_values(EstruturaProdutoVariacao::EIXOS)).'"');

        return $v;
    }

    private static function preencherInstrucoes(Worksheet $aba, int $categorias, bool $preenchido, bool $temSemCategoria): void
    {
        $linhas = [
            'Como preencher',
            $preenchido
                ? 'Estes são os seus produtos, cada um na aba da categoria dele. Mude o que quiser e envie de novo: só o que mudou é atualizado.'
                : ($categorias === 1
                    ? 'A primeira aba é a categoria que você escolheu. Todos os produtos dela vão nessa aba.'
                    : 'Cada aba é uma das categorias que você escolheu. Ponha cada produto na aba da categoria dele.'),
            'Em cada aba: a linha 1 diz de que é cada bloco de colunas, a linha 2 é o nome de cada coluna e a linha 3 diz como preencher. Os produtos começam na linha '.self::PRIMEIRA_LINHA.'.',
            'Cabeçalho escuro e com * é obrigatório. Cabeçalho claro é opcional: preencha o que souber.',
            'Uma linha por variação do produto (cada cor ou tamanho é uma linha). Produto sem variação ocupa uma linha só.',
            'Produto (grupo)*: o código que junta as variações de um mesmo produto. Repita o mesmo código em todas as linhas dele.',
            'Ref*: o código de cada variação. Não pode repetir.',
            'PRODUTO EMBALADO (os Volumes): as medidas e o peso de cada CAIXA com o produto dentro. É por elas que o frete é calculado. Cada caixa tem quatro colunas — comprimento, largura e altura em cm e o peso em kg. Os volumes que não estão em uso vêm recolhidos: se o produto vai em mais de uma caixa, abra o Volume 2, o Volume 3… no botão + que fica acima das colunas, ao lado do último volume aberto, e preencha. Deixe em branco os que não usar.',
            'MEDIDAS DO PRODUTO FORA DA CAIXA: o produto montado, sem a embalagem. São diferentes das medidas do volume, que é a caixa.',
            'FICHA TÉCNICA: as características do produto. Preencha na PRIMEIRA linha do produto; nas outras variações pode ficar em branco.',
            'Onde houver lista, escolha uma opção pela seta da célula; em algumas listas você também pode escrever o seu valor. Para marcar mais de uma opção num campo, separe com | .',
            'Não se aplica: quando o produto não tem o que a coluna pede (por exemplo, a medida do colchão numa poltrona que não vira cama), escreva Não se aplica na célula. A linha 3 diz em quais colunas isso vale. Em branco quer dizer que você não informou.',
            'A aba "'.PlanilhaDasFichasTecnicas::ABA_GUIA.'" explica cada coluna: o que é, como preencher e as opções de cada lista. A mesma explicação aparece ao passar o mouse no nome da coluna.',
            'Célula em branco não apaga nada do que já está cadastrado. Enviar de novo atualiza pelo código da variação.',
            'Não mude o nome das abas nem dos cabeçalhos, e não apague as três primeiras linhas. As fotos entram depois, em "Enviar fotos em lote".',
            ...($temSemCategoria ? ['A aba "Sem categoria" traz os produtos que ainda não têm categoria: sem ela não há ficha técnica. Escolha a categoria de cada um na tela e baixe de novo para ver a ficha.'] : []),
            ...($preenchido ? ['Para cadastrar produto de uma categoria que ainda não está aqui, use "Baixar modelo" e escolha a categoria.'] : []),
        ];
        foreach ($linhas as $n => $texto) {
            self::texto($aba, 'A'.($n + 1), $texto);
        }
        $aba->getStyle('A1')->getFont()->setBold(true);
        $aba->getColumnDimension('A')->setWidth(140);
        $aba->getStyle('A1:A'.count($linhas))->getAlignment()->setWrapText(true);
    }

    private static function texto(Worksheet $folha, string $celula, string $valor): void
    {
        $folha->setCellValueExplicit($celula, $valor, DataType::TYPE_STRING);
    }
}
