<?php

namespace App\Services\Portal\Estrutura\Produtos\MercadoLivre;

use App\Jobs\Portal\JardinarProdutoDoMlJob;
use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoOrigemMl;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Services\Portal\Estrutura\Produtos\ImportadorFichasTecnicas;
use App\Services\Publicador\PreparoIaAgenda;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\Log;

/**
 * A "jardinagem" do produto trazido do Mercado Livre (28/10/2026): a IA completa os campos da
 * ficha que o anúncio deixou vazios e, no fim, o produto entra no preparo normal do Publicador
 * (`PreparoIaAgenda::aoSalvar`), que reescreve título, Modelo e descrição pela metodologia ECF.
 *
 * ### Só o que o anúncio sustenta
 * O prompt é o da ficha do "Anunciar por IA" (`AnaliseAnuncioService::ficha`): usar SOMENTE o que
 * título, descrição e campos do anúncio sustentam; na dúvida, omitir. Medida que não está escrita
 * em lugar nenhum fica vazia — número errado é pior que número nenhum (quick 261007-ifa). Cada
 * valor passa pela MESMA validação da ficha (`gravarParcial`): opção que não existe não entra.
 *
 * ### Não apaga o que existe
 * Só preenche campo VAZIO. O que veio do anúncio ou o que o cliente já mexeu fica.
 *
 * ### Registro
 * `origem.jardinagem` = {campos_ia, nomes, faltavam, em} ou {erro, em}. A tela de importação
 * mostra "a IA completou N campos".
 */
class JardinagemDaFicha
{
    /** Quantas opções de cada lista vão no prompt (as listas grandes do catálogo passam de 300). */
    private const MAX_OPCOES_NO_PROMPT = 40;

    private const MAX_DESCRICAO_NO_PROMPT = 3000;

    public function __construct(
        private AnaliseAnuncioService $ia,
        private FichaTecnicaDaCategoria $fichas,
        private FichaTecnicaDoProduto $fichaTecnica,
        private PreparoIaAgenda $preparo,
    ) {}

    /** Põe a jardinagem na fila da IA (a mesma do preparo do Publicador). */
    public static function agendar(EstruturaProdutoOrigemMl $origem): void
    {
        JardinarProdutoDoMlJob::dispatch((int) $origem->company_id, (int) $origem->id);
    }

    /** @return array registro gravado em `origem.jardinagem` */
    public function jardinar(Company $empresa, EstruturaProdutoOrigemMl $origem): array
    {
        $produto = EstruturaProduto::query()->where('company_id', $empresa->id)->find($origem->produto_id);
        if ($produto === null) {
            return [];
        }

        try {
            $registro = $this->completar($empresa, $produto, $origem);
        } catch (\Throwable $e) {
            Log::warning("[Estrutura Produtos ML] jardinagem do produto {$produto->id} falhou — empresa {$empresa->id} ({$empresa->name}): {$e->getMessage()}");
            $registro = ['erro' => 'A IA não respondeu agora.', 'em' => now()->toIso8601String()];
        }

        $origem->jardinagem = $registro;
        $origem->save();

        // Daqui para frente é o caminho de qualquer produto salvo: Publicador + título, Modelo e descrição.
        $this->preparo->aoSalvar((int) $empresa->id, [(int) $produto->id]);

        return $registro;
    }

    private function completar(Company $empresa, EstruturaProduto $produto, EstruturaProdutoOrigemMl $origem): array
    {
        $categoria = trim((string) $produto->categoria_ml_id);
        $definicao = $categoria === '' ? [] : $this->fichas->definicao($categoria);
        if ($definicao === []) {
            // Queda passageira do catálogo: o Job tenta de novo (`ficha_indisponivel`).
            return ['erro' => 'A ficha técnica da categoria não carregou.', 'ficha_indisponivel' => true, 'em' => now()->toIso8601String()];
        }
        $campos = FichaTecnicaDaCategoria::camposPorId(FichaTecnicaDaCategoria::doProduto($definicao, FichaTecnicaDoProduto::eixosDoProduto($produto)));
        $retrato = (array) ($origem->retrato ?? []);

        // 1. O que o ANÚNCIO tinha e ainda não está na ficha (a ficha da categoria pode não ter
        //    carregado na importação). Só campo vazio: o que o cliente já mexeu fica.
        $jaTem = $this->preenchidos($produto);
        $doAnuncio = [];
        foreach ((array) ($retrato['atributos_ml'] ?? []) as $id => $a) {
            if (isset($campos[$id]) && ! isset($jaTem[$id]) && is_array($a) && ($e = TradutorAnuncioMlParaPortal::entradaDaFicha($campos[$id], $a)) !== null) {
                $doAnuncio[$id] = $e;
            }
        }
        if ($doAnuncio !== []) {
            $this->fichaTecnica->gravarParcial($empresa, $produto, $doAnuncio, AtorDoPortal::sistema('portal', 'Importação do Mercado Livre'));
        }

        // 2. A IA, só no que continua vazio.
        $antes = $this->preenchidos($produto);
        $faltando = array_filter($campos, fn ($c, $id) => ! isset($antes[$id]), ARRAY_FILTER_USE_BOTH);
        if ($faltando === []) {
            return ['campos_ia' => [], 'nomes' => [], 'faltavam' => 0, 'em' => now()->toIso8601String()];
        }

        $r = $this->ia->ficha(
            (string) $produto->nome,
            $this->fatos($produto, $retrato, $campos, $antes),
            (string) ($retrato['titulo'] ?? $produto->nome),
            (string) ($produto->categoria_ml_caminho ?: $produto->categoria_ml_nome ?: $categoria),
            $this->catalogo($faltando),
        );

        $respostas = is_array($r['dados']['atributos'] ?? null) ? $r['dados']['atributos'] : [];
        $entradas = [];
        foreach ($respostas as $id => $valor) {
            $id = strtoupper(trim((string) $id));
            if (! isset($faltando[$id]) || ! is_scalar($valor) || trim((string) $valor) === '') {
                continue;
            }
            $entradas[$id] = ImportadorFichasTecnicas::entrada($faltando[$id], is_bool($valor) ? $valor : trim((string) $valor));
        }

        $gravados = [];
        if ($entradas !== []) {
            $this->fichaTecnica->gravarParcial($empresa, $produto, $entradas, AtorDoPortal::sistema('ia', 'IA da ECF'));
            $depois = $this->preenchidos($produto);
            foreach (array_keys($faltando) as $id) {
                if (isset($depois[$id])) {
                    $gravados[] = (string) $id;
                }
            }
        }

        return [
            'campos_ia' => $gravados,
            'nomes'     => array_values(array_map(fn ($id) => (string) $campos[$id]['nome'], $gravados)),
            'faltavam'  => count($faltando),
            'em'        => now()->toIso8601String(),
            'modelo_ia' => $r['meta']['modelo'] ?? $r['meta']['model'] ?? null,
        ];
    }

    /** @return array<string, true> ids já preenchidos na ficha do produto */
    private function preenchidos(EstruturaProduto $produto): array
    {
        return EstruturaProdutoAtributo::query()
            ->where('company_id', $produto->company_id)->where('produto_id', $produto->id)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('valor')->where('valor', '<>', ''))->orWhereNotNull('valor_id'))
            ->pluck('atributo_id')->mapWithKeys(fn ($id) => [(string) $id => true])->all();
    }

    /** O que o anúncio diz do produto: título, Modelo do vendedor, descrição e os campos já preenchidos. */
    private function fatos(EstruturaProduto $produto, array $retrato, array $campos, array $preenchidos): string
    {
        $linhas = [];
        if (! empty($retrato['titulo'])) {
            $linhas[] = 'Título do anúncio: '.$retrato['titulo'];
        }
        if (! empty($retrato['modelo'])) {
            $linhas[] = 'Modelo informado pelo vendedor: '.$retrato['modelo'];
        }
        if (! empty($retrato['marca'])) {
            $linhas[] = 'Marca: '.$retrato['marca'];
        }

        $salvos = EstruturaProdutoAtributo::query()
            ->where('company_id', $produto->company_id)->where('produto_id', $produto->id)
            ->whereIn('atributo_id', array_keys($preenchidos))->get();
        if ($salvos->isNotEmpty()) {
            $linhas[] = 'Características já preenchidas:';
            foreach ($salvos as $s) {
                $valor = $s->valor_id === FichaTecnicaDoProduto::NAO_SE_APLICA ? 'Não se aplica' : trim($s->valor.' '.($s->unidade ?? ''));
                $linhas[] = '- '.($campos[$s->atributo_id]['nome'] ?? $s->atributo_nome).': '.$valor;
            }
        }

        $descricao = trim((string) $produto->descricao);
        if ($descricao !== '') {
            $linhas[] = "Descrição do anúncio:\n".mb_substr($descricao, 0, self::MAX_DESCRICAO_NO_PROMPT);
        }

        return implode("\n", $linhas);
    }

    /** Os campos que faltam, no formato "ID | nome | formato" do prompt da ficha (* = obrigatório). */
    private function catalogo(array $faltando): string
    {
        $linhas = [];
        foreach ($faltando as $id => $c) {
            $formato = match ($c['tipo']) {
                FichaTecnicaDaCategoria::TIPO_LISTA => 'opções: '.implode('; ', array_slice(array_column((array) $c['valores'], 'nome'), 0, self::MAX_OPCOES_NO_PROMPT))
                    .(($c['multivalor'] ?? false) ? ' (pode mais de uma, separadas por |)' : '')
                    .(($c['aceita_outro'] ?? false) ? ' (ou outro valor)' : ''),
                FichaTecnicaDaCategoria::TIPO_NUMERO_UNIDADE => 'número + unidade ('.implode(', ', array_slice(array_column((array) $c['unidades'], 'id'), 0, 8)).')',
                FichaTecnicaDaCategoria::TIPO_NUMERO => 'número',
                FichaTecnicaDaCategoria::TIPO_SIM_NAO => 'Sim/Não',
                default => 'texto',
            };
            $linhas[] = $id.(($c['obrigatorio'] ?? false) ? '*' : '').' | '.$c['nome'].' | '.$formato;
        }

        return implode("\n", $linhas);
    }
}
