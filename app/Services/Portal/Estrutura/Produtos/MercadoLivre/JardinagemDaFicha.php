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
            true,
        );

        $entradas = self::entradasDaIa($r['dados']['atributos'] ?? null, $faltando);

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

    /**
     * As respostas da IA no formato de entrada da ficha, só para os campos que faltam. `NAO_SE_APLICA`
     * vira "Não se aplica" SÓ onde o campo aceita (29/10/2026); onde não aceita, é descartado.
     *
     * @param  array<string, array>  $faltando  id => campo da definição
     * @return array<string, array>
     */
    private static function entradasDaIa(mixed $respostas, array $faltando): array
    {
        $entradas = [];
        foreach (is_array($respostas) ? $respostas : [] as $id => $valor) {
            $id = strtoupper(trim((string) $id));
            if (! isset($faltando[$id]) || ! is_scalar($valor) || trim((string) $valor) === '') {
                continue;
            }
            // "Não sei" da IA (regra 9 do prompt): descartado AQUI — senão, num campo que aceita texto livre,
            // "DESCONHECIDO" entraria como valor.
            if (is_string($valor) && strtoupper(trim($valor)) === 'DESCONHECIDO') {
                continue;
            }
            if (is_string($valor) && strtoupper(trim($valor)) === 'NAO_SE_APLICA') {
                if ($faltando[$id]['nao_se_aplica'] ?? false) {
                    $entradas[$id] = ['nao_se_aplica' => true];
                }
                continue;
            }
            $entradas[$id] = ImportadorFichasTecnicas::entrada($faltando[$id], is_bool($valor) ? $valor : trim((string) $valor));
        }

        return $entradas;
    }

    // ═══ "Preencher com IA" na ficha (29/10/2026) ═══════════════════════════

    /** Quanto o botão espera a IA, no total (com a troca de modelo): a tela fica esperando. */
    private const PRAZO_DO_BOTAO_S = 50;

    /**
     * O botão "Preencher com IA" da ficha técnica: o que a IA sugere para os campos que estão VAZIOS
     * na tela agora (o que a pessoa digitou e ainda não salvou conta como preenchido). NÃO grava nada:
     * as sugestões voltam no formato das linhas salvas e a tela as aplica para a pessoa revisar e salvar.
     * Cada sugestão passa pela mesma validação da ficha (`planejarParcial`): opção que não existe e
     * "Não se aplica" onde o campo não aceita ficam de fora.
     *
     * @param  array<string, array>  $atuais  id => entrada da tela ({valor, unidade, nao_se_aplica, …})
     * @return array{sugestoes: list<array{id: string, nome: string, valor: ?string, valor_id: ?string, unidade: ?string}>, faltavam: int}
     */
    public function sugerir(EstruturaProduto $produto, array $atuais, string $titulo = '', string $descricao = ''): array
    {
        $categoria = trim((string) $produto->categoria_ml_id);
        $definicao = $categoria === '' ? [] : $this->fichas->definicao($categoria);
        if ($definicao === []) {
            throw new \RuntimeException('A ficha técnica da categoria não carregou agora. Tente de novo.');
        }
        $campos = FichaTecnicaDaCategoria::camposPorId(FichaTecnicaDaCategoria::doProduto($definicao, FichaTecnicaDoProduto::eixosDoProduto($produto)));

        $preenchidos = array_filter($atuais, fn ($e, $id) => isset($campos[$id]) && self::entradaPreenchida(is_array($e) ? $e : []), ARRAY_FILTER_USE_BOTH);
        $faltando = array_diff_key($campos, $preenchidos);
        if ($faltando === []) {
            return ['sugestoes' => [], 'faltavam' => 0];
        }

        $origem = EstruturaProdutoOrigemMl::query()->where('company_id', $produto->company_id)->where('produto_id', $produto->id)->first();
        $retrato = (array) ($origem?->retrato ?? []);
        $titulo = trim($titulo) !== '' ? trim($titulo) : (string) ($retrato['titulo'] ?? '');

        $r = $this->ia->comPrazo(microtime(true) + self::PRAZO_DO_BOTAO_S)->ficha(
            (string) $produto->nome,
            $this->fatosDaTela($retrato, $titulo, $campos, $preenchidos, trim($descricao) !== '' ? $descricao : (string) $produto->descricao),
            $titulo !== '' ? $titulo : (string) $produto->nome,
            (string) ($produto->categoria_ml_caminho ?: $produto->categoria_ml_nome ?: $categoria),
            $this->catalogo($faltando),
            true,
        );

        $plano = $this->fichaTecnica->planejarParcial($produto, self::entradasDaIa($r['dados']['atributos'] ?? null, $faltando));
        $sugestoes = [];
        foreach ($plano['validos'] as $id => $v) {
            $sugestoes[] = ['id' => (string) $id, 'nome' => (string) $v['campo']['nome'], 'valor' => $v['valor'], 'valor_id' => $v['valor_id'], 'unidade' => $v['unidade']];
        }

        return ['sugestoes' => $sugestoes, 'faltavam' => count($faltando)];
    }

    /** A entrada da tela tem algo (valor, opções ou "Não se aplica")? */
    private static function entradaPreenchida(array $e): bool
    {
        if (filter_var($e['nao_se_aplica'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        $v = $e['valor'] ?? null;

        return is_array($v) ? $v !== [] : trim((string) $v) !== '';
    }

    /** Os fatos para a IA a partir do que está NA TELA (pode ainda não estar salvo). */
    private function fatosDaTela(array $retrato, string $titulo, array $campos, array $preenchidos, string $descricao): string
    {
        $linhas = [];
        if ($titulo !== '') {
            $linhas[] = 'Título: '.$titulo;
        }
        if (! empty($retrato['modelo'])) {
            $linhas[] = 'Modelo informado pelo vendedor: '.$retrato['modelo'];
        }
        if (! empty($retrato['marca'])) {
            $linhas[] = 'Marca: '.$retrato['marca'];
        }
        if ($preenchidos !== []) {
            $linhas[] = 'Características já preenchidas:';
            foreach ($preenchidos as $id => $e) {
                $linhas[] = '- '.$campos[$id]['nome'].': '.self::textoDaEntrada($campos[$id], $e);
            }
        }
        $descricao = trim($descricao);
        if ($descricao !== '') {
            $linhas[] = "Descrição:\n".mb_substr($descricao, 0, self::MAX_DESCRICAO_NO_PROMPT);
        }

        return implode("\n", $linhas);
    }

    /** O valor de uma entrada da tela em texto (o id de opção vira o nome dela). */
    private static function textoDaEntrada(array $campo, array $e): string
    {
        if (filter_var($e['nao_se_aplica'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return 'Não se aplica';
        }
        $nomes = [];
        foreach ((array) ($campo['valores'] ?? []) as $o) {
            $nomes[(string) ($o['id'] ?? '')] = (string) ($o['nome'] ?? '');
        }
        $valores = array_map(fn ($v) => $nomes[(string) $v] ?? (string) $v, is_array($e['valor'] ?? null) ? $e['valor'] : [(string) ($e['valor'] ?? '')]);

        return trim(implode(', ', $valores).' '.(string) ($e['unidade'] ?? ''));
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
            // [N/A]: o campo aceita "Não se aplica" (opcional que a plataforma mostra) — a IA só pode responder
            // NAO_SE_APLICA nesses (29/10/2026).
            $linhas[] = $id.(($c['obrigatorio'] ?? false) ? '*' : '').' | '.$c['nome'].' | '.$formato.(($c['nao_se_aplica'] ?? false) ? ' [N/A]' : '');
        }

        return implode("\n", $linhas);
    }
}
