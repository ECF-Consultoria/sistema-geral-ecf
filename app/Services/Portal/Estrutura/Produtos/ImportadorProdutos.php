<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProdutoVariacao;
use App\Support\Portal\AtorDoPortal;
use InvalidArgumentException;

/**
 * Importação da aba Produtos (D-13/D-14), no padrão "plano/aplicar" da colagem
 * de anúncios: stateless — o navegador não manda o que gravar; a confirmação
 * reenvia o arquivo e o plano é refeito do zero (T-167-36).
 *
 * - `previa()` só LÊ: classifica cada linha em novo, atualizado, sem mudança ou
 *   erro, e lista as famílias e ambientes que serão criados nas listas.
 * - `aplicar()` refaz o plano e grava pelo MESMO serviço da grade
 *   (`ProdutoCadastroService::gravarLinhas` em MODO_IMPORTACAO): código que já
 *   existe atualiza, só com o que a linha trouxe; célula em branco não apaga;
 *   nada é apagado nunca (não existe "substituir").
 */
class ImportadorProdutos
{
    public const DETALHE_MAXIMO = 200;

    /** Tolerância (kg) entre o "Peso total" da planilha e a soma dos volumes. */
    private const TOLERANCIA_PESO = 0.05;

    public function __construct(
        private LeitorPlanilhaProdutos $leitor,
        private ProdutoCadastroService $cadastro,
        private ListasDaEmpresaService $listas,
    ) {}

    /**
     * @return array{erro_geral: ?string, colunas: list<string>, totais: array{novos: int, atualizados: int, sem_mudanca: int, erros: int}, grupos: array{novos: list<array>, atualizados: list<array>, sem_mudanca: list<array>, erros: list<array>}, criar_listas: array{familias: list<string>, ambientes: list<string>}, avisos: list<string>}
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
        ];
    }

    /**
     * Refaz o plano e grava. Os totais vêm do que o serviço de escrita de fato
     * fez (uma variação criada entre a prévia e a confirmação conta como atualizada).
     *
     * @return array{erro_geral?: string, novos: int, atualizados: int, sem_mudanca: int, erros: int}
     */
    public function aplicar(Company $empresa, string $caminho, AtorDoPortal $ator): array
    {
        $plano = $this->plano($empresa, $caminho);

        if ($plano['erro_geral'] !== null) {
            return ['erro_geral' => $plano['erro_geral'], 'novos' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'erros' => 0];
        }

        // Linhas válidas na ordem do arquivo (a 1ª linha de cada grupo define o produto).
        $linhas = array_column($plano['linhas'], 'bruta');

        $totais = ['criadas' => 0, 'atualizadas' => 0, 'sem_mudanca' => 0, 'com_erro' => 0];
        if ($linhas !== []) {
            $res = $this->cadastro->gravarLinhas($empresa, $linhas, $ator, ProdutoCadastroService::MODO_IMPORTACAO);
            $totais = $res['totais'];
        }

        return [
            'novos'       => $totais['criadas'],
            'atualizados' => $totais['atualizadas'],
            'sem_mudanca' => $totais['sem_mudanca'],
            'erros'       => count($plano['erros']) + $totais['com_erro'],
        ];
    }

    // ═══ Plano ══════════════════════════════════════════════════════════════

    private function plano(Company $empresa, string $caminho): array
    {
        $plano = [
            'erro_geral' => null, 'colunas' => [], 'linhas' => [],
            'novos' => [], 'atualizados' => [], 'sem_mudanca' => [], 'erros' => [],
            'criar_listas' => ['familias' => [], 'ambientes' => []], 'avisos' => [],
        ];

        $lido = $this->leitor->ler($caminho);
        $plano['colunas'] = $lido['colunas'];
        if ($lido['erro_geral'] !== null) {
            $plano['erro_geral'] = $lido['erro_geral'];

            return $plano;
        }

        // O que já existe, só lendo.
        $existentes = [];
        EstruturaProdutoVariacao::query()
            ->where('company_id', $empresa->id)
            ->with(['produto.familia', 'produto.ambientes', 'volumes'])
            ->get()
            ->each(function (EstruturaProdutoVariacao $v) use (&$existentes) {
                $existentes[ProdutoCadastroService::chaveCodigo($v->codigo)] = $v;
            });

        $familias = [];
        $ambientes = [];
        $nomesPorGrupo = [];

        foreach ($lido['linhas'] as $linha) {
            $numero = $linha['numero'];
            $bruta = $this->paraEntrada($linha['bruta']);
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

            $plano['linhas'][] = ['numero' => $numero, 'bruta' => $bruta];

            foreach ($lida['avisos'] as $a) {
                $plano['avisos'][] = "linha {$numero}: {$a}";
            }
            foreach ($this->avisosDeVolumes($numero, $linha['bruta'], $campos, $lida['presentes']) as $a) {
                $plano['avisos'][] = $a;
            }

            if ($campos['grupo'] !== null) {
                $chaveGrupo = ProdutoCadastroService::chaveCodigo($campos['grupo']);
                $nomesPorGrupo[$chaveGrupo]['rotulo'] ??= $campos['grupo'];
                $nomesPorGrupo[$chaveGrupo]['nomes'][ListasDaEmpresaService::chave($campos['nome'])] ??= $campos['nome'];
            }

            if (in_array('familia', $lida['presentes'], true) && $campos['familia'] !== null) {
                $familias[] = $campos['familia'];
            }
            if (in_array('ambientes', $lida['presentes'], true)) {
                array_push($ambientes, ...$campos['ambientes']);
            }

            // ─── Classificação ───
            $item = ['linha' => $numero, 'codigo' => $campos['codigo'], 'nome' => $campos['nome']];
            $atual = $existentes[ProdutoCadastroService::chaveCodigo($campos['codigo'])] ?? null;

            if ($atual === null) {
                $plano['novos'][] = $item;
                continue;
            }

            $mudou = $this->diferencas($atual, $campos, $lida['presentes']);
            if ($mudou === []) {
                $plano['sem_mudanca'][] = $item;
            } else {
                $plano['atualizados'][] = $item + ['mudou' => $mudou];
            }
        }

        foreach ($nomesPorGrupo as $g) {
            if (count($g['nomes']) > 1) {
                $plano['avisos'][] = "Grupo {$g['rotulo']}: nomes diferentes, usamos o da primeira linha.";
            }
        }

        $plano['criar_listas'] = [
            'familias'  => $this->listas->resolverNomes($empresa, ListasDaEmpresaService::FAMILIA, $familias, null)['novos'],
            'ambientes' => $this->listas->resolverNomes($empresa, ListasDaEmpresaService::AMBIENTE, $ambientes, null)['novos'],
        ];

        return $plano;
    }

    /** Linha lida da planilha -> contrato da linha de entrada (a categoria crua vira id ou texto). */
    private function paraEntrada(array $bruta): array
    {
        // Célula em branco NÃO entra na linha: null explícito em `custo`/`categoria` limparia o dado.
        $entrada = array_filter($bruta, fn ($v) => $v !== null && $v !== '');
        unset($entrada['n_volumes'], $entrada['peso_total'], $entrada['categoria']);

        $cat = $bruta['categoria'] ?? null;
        if (is_string($cat) && $cat !== '') {
            if (preg_match('/^MLB\d+$/i', trim($cat))) {
                $entrada['categoria_ml_id'] = trim($cat);
            } else {
                $entrada['categoria_texto'] = $cat;
            }
        }

        return $entrada;
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
                $campos['categoria_texto'] !== null => trim((string) $produto->categoria_ml_id) === ''
                    && trim((string) $produto->categoria_ml_nome) === $campos['categoria_texto'],
                default => trim((string) $produto->categoria_ml_id) === '' && trim((string) $produto->categoria_ml_nome) === '',
            };
            if (! $igual) {
                $mudou[] = 'categoria';
            }
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
