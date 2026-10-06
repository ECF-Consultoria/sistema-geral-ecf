<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ÚNICO caminho de escrita do catálogo de Produtos do Mapeamento Estrutural:
 * grade, celular e importação de planilha gravam por aqui — uma regra, uma validação.
 *
 * ### Modelo (D-03, D-04)
 * Uma linha = uma variação. O produto (nome, família, ambientes, categoria) é
 * compartilhado pelas variações do mesmo `grupo`; código, eixo, valor, volumes e
 * custo são da variação. Variação nova de produto que já existia, sem volumes,
 * custo ou eixo na linha, copia os da primeira variação.
 *
 * ### Empresa (D-01)
 * A empresa vem SEMPRE do parâmetro (o controller passa a do contexto do portal);
 * `company_id` de dentro da linha é ignorado.
 *
 * ### Falha por linha (D-12)
 * Cada linha roda num savepoint: o erro de uma (código repetido, categoria que
 * não é folha, produto de outra empresa) não derruba as outras. Código repetido é
 * checado aqui, comparando no PHP sem caixa/acento/espaço (`chaveCodigo`), porque
 * o unique do MariaDB ignora caixa e acento e o SQLite dos testes não; o unique
 * do banco fica como rede de segurança (SQLSTATE 23000 vira erro da linha).
 *
 * ### Importação (D-14)
 * Em MODO_IMPORTACAO um código que já existe atualiza aquela variação; só os
 * campos que a linha trouxe (`presentes`) mudam. Em MODO_GRADE o mesmo código
 * é recusado (a grade devolve o `id` para editar).
 *
 * ### Grupo (BE-CR-01)
 * Em MODO_GRADE o `grupo` só junta linhas de produto criado no MESMO lote; grupo
 * que bate no código de um produto que já existia é erro da linha (o produto
 * existente se edita pelo `produto_id`). Em MODO_IMPORTACAO o grupo casa com o
 * produto existente — reimportar acrescenta variações a ele.
 *
 * ### Oferta ligada (D-08, D-09)
 * Cada variação gravada tem UMA oferta simples na Lista SKUs (`variacao_id`), com
 * SKU = código da variação e nome "Produto — Valor". Não há casamento por SKU com
 * as ofertas que já existiam (D-09): elas ficam como estão, sem produto, e o SKU
 * repetido é aviso que a Lista SKUs já dá. O vínculo é o que protege sku/nome/fase
 * contra edição direta na Lista SKUs (167-03); a invariante é garantida pelo unique
 * `eo_variacao_uq` e por `garantirOfertas`.
 *
 * ### Sem backfill
 * Nada aqui toca em produtos antigos do Onboarding/Precificação: o catálogo
 * começa vazio e é preenchido por quem grava.
 *
 * ### Categoria (D-06)
 * Só folha do ML vira categoria confirmada; texto colado fica "a confirmar" e
 * nunca vira id sozinho; ML fora do ar grava o id como "não validada".
 *
 * Auditoria (D-02): UM `produtos_gravados` por lote, com origem cliente/interno.
 */
class ProdutoCadastroService
{
    public const MODO_GRADE      = 'grade';
    public const MODO_IMPORTACAO = 'importacao';
    public const MAX_GRADE       = 200;
    public const MAX_IMPORTACAO  = 1000;

    private const MSG_NAO_ENCONTRADO = 'Produto não encontrado.';

    public function __construct(
        private ListasDaEmpresaService $listas,
        private CategoriaSugestaoService $categorias,
        private ProdutoLinhas $linhas,
        private EstruturaOfertaService $ofertas,
    ) {}

    /** Forma usada para comparar códigos: sem caixa, acento nem espaço nas pontas. */
    public static function chaveCodigo(string $codigo): string
    {
        return Str::lower(Str::ascii(trim($codigo)));
    }

    /**
     * @param  array<int, array>  $linhas  linhas de entrada (contrato do NormalizadorDeLinha)
     * @return array{linhas: list<array>, erros: list<array{indice: int, chave: ?string, codigo: ?string, mensagem: string, campos: array<string, string>}>, avisos: list<string>, criadas_nas_listas: array{familias: list<string>, ambientes: list<string>}, totais: array{criadas: int, atualizadas: int, sem_mudanca: int, com_erro: int}}
     */
    public function gravarLinhas(Company $empresa, array $linhas, AtorDoPortal $ator, string $modo = self::MODO_GRADE): array
    {
        $limite = $modo === self::MODO_IMPORTACAO ? self::MAX_IMPORTACAO : self::MAX_GRADE;
        if (count($linhas) > $limite) {
            throw ValidationException::withMessages(['linhas' => "Envie no máximo {$limite} linhas por vez."]);
        }

        $erros = [];
        $avisos = [];
        $criadasNasListas = ['familias' => [], 'ambientes' => []];
        $totais = ['criadas' => 0, 'atualizadas' => 0, 'sem_mudanca' => 0, 'com_erro' => 0, 'absorvidos_da_espera' => 0];
        $chaves = [];           // variacao_id => chave da linha no navegador
        $produtosTocados = [];  // produto_id => true

        $estado = $this->carregar($empresa);

        // A leitura das linhas é pura; com ela, as categorias do lote são validadas no ML
        // de uma vez e ANTES de abrir a transação — nenhuma chamada HTTP segura lock (BE-WR-05).
        $lidas = array_map(fn ($bruta) => NormalizadorDeLinha::normalizar(is_array($bruta) ? $bruta : []), array_values($linhas));
        $estado['categorias'] = $this->categoriasDoLote($lidas);

        DB::transaction(function () use ($empresa, $lidas, $ator, $modo, &$erros, &$avisos, &$criadasNasListas, &$totais, &$chaves, &$produtosTocados, &$estado) {
            $skusParaVarrer = [];

            foreach ($lidas as $indice => $lida) {
                $campos = $lida['campos'];

                if ($lida['erros'] !== []) {
                    $erros[] = $this->erro($indice, $campos, reset($lida['erros']), $lida['erros']);
                    $totais['com_erro']++;
                    continue;
                }

                try {
                    $res = DB::transaction(fn () => $this->gravarLinha($empresa, $campos, $lida['presentes'], $ator, $modo, $estado));
                } catch (ValidationException $e) {
                    $mensagens = array_map(fn ($m) => (string) $m[0], $e->errors());
                    $erros[] = $this->erro($indice, $campos, (string) reset($mensagens), $mensagens);
                    $totais['com_erro']++;
                    continue;
                } catch (QueryException $e) {
                    if ((string) $e->getCode() !== '23000') {
                        throw $e;
                    }
                    $msg = "O código {$campos['codigo']} já existe em outro produto. Use outro código.";
                    $erros[] = $this->erro($indice, $campos, $msg, ['codigo' => $msg]);
                    $totais['com_erro']++;
                    continue;
                }

                // Só depois de gravar a linha o estado em memória passa a valer.
                $this->aplicarNoEstado($estado, $res);

                foreach ($lida['avisos'] as $a) {
                    $avisos[] = "{$campos['codigo']}: {$a}";
                }
                foreach ($res['avisos'] as $a) {
                    $avisos[] = $a;
                }
                foreach ($res['criadas_nas_listas'] as $tipo => $nomes) {
                    $criadasNasListas[$tipo] = array_values(array_unique([...$criadasNasListas[$tipo], ...$nomes]));
                }

                $totais[$res['resultado']]++;
                $produtosTocados[$res['produto']->id] = true;
                $skusParaVarrer = [...$skusParaVarrer, ...$res['skus']];
                if ($campos['chave'] !== null) {
                    $chaves[$res['variacao']->id] = $campos['chave'];
                }
            }

            // A espera é varrida UMA vez, com todos os SKUs que o lote criou ou mudou, em vez
            // de uma leitura da espera inteira por oferta criada (BE-WR-05).
            if ($skusParaVarrer !== []) {
                $totais['absorvidos_da_espera'] = $this->ofertas->varrerEspera($empresa, array_values(array_unique($skusParaVarrer)));
            }

            $gravadas = $totais['criadas'] + $totais['atualizadas'];
            if ($gravadas > 0) {
                // Rede de segurança, na mesma transação: variação nunca fica sem oferta.
                $this->garantirOfertas($empresa, $ator);

                RegistroEstrutura::registrar($ator, $empresa, null, 'produtos_gravados',
                    "{$gravadas} variação(ões) gravada(s) no Produtos",
                    ['modo' => $modo, 'totais' => $totais, 'criadas_nas_listas' => $criadasNasListas]);
            }
        });

        $saida = $this->linhas->paraProdutos($empresa, array_keys($produtosTocados));
        foreach ($saida as &$l) {
            if (isset($chaves[$l['id']])) {
                $l['chave'] = $chaves[$l['id']];
            }
        }
        unset($l);

        return [
            'linhas'             => $saida,
            'erros'              => $erros,
            'avisos'             => $avisos,
            'criadas_nas_listas' => $criadasNasListas,
            'totais'             => $totais,
        ];
    }

    // ═══ Oferta ligada à variação (D-08) ════════════════════════════════════

    /** Nome da oferta: "Produto — Valor", ou só o produto quando a variação não tem valor. Diferencia V1/V2 na Lista SKUs e no "Sincronizar do Portal". */
    public static function nomeDaOferta(string $nomeProduto, ?string $valor): string
    {
        $valor = trim((string) $valor);
        $nome = $valor === '' ? $nomeProduto : "{$nomeProduto} — {$valor}";

        return mb_substr($nome, 0, 255);
    }

    /**
     * Com `$skusDoLote`, a espera NÃO é varrida aqui: o SKU entra na lista e o lote
     * varre uma vez no fim (BE-WR-05). Sem ela (reconciliador avulso), varre na hora.
     *
     * @return int quantos anúncios da espera a oferta nova absorveu (0 quando o lote varre)
     */
    private function criarOferta(Company $empresa, EstruturaProduto $produto, EstruturaProdutoVariacao $variacao, AtorDoPortal $ator, ?array &$skusDoLote = null): int
    {
        [, $absorvidos] = $this->ofertas->criar($empresa, [
            'sku'         => $variacao->codigo,
            'fase'        => EstruturaOferta::FASE_SIMPLES,
            'nome'        => self::nomeDaOferta($produto->nome, $variacao->valor),
            'variacao_id' => $variacao->id,
        ], $ator, varrerEspera: $skusDoLote === null);

        if ($skusDoLote !== null) {
            $skusDoLote[] = $variacao->codigo;
        }

        return $absorvidos;
    }

    /** Acompanha código/nome na oferta ligada; se ela não existe (não deveria), cria. */
    private function sincronizarOferta(Company $empresa, EstruturaProduto $produto, EstruturaProdutoVariacao $variacao, AtorDoPortal $ator, ?array &$skusDoLote = null): int
    {
        $oferta = EstruturaOferta::query()->where('variacao_id', $variacao->id)->first();
        if (! $oferta) {
            return $this->criarOferta($empresa, $produto, $variacao, $ator, $skusDoLote);
        }

        if ($skusDoLote !== null && EstruturaOferta::normalizarSku($oferta->sku) !== EstruturaOferta::normalizarSku($variacao->codigo)) {
            // O SKU antigo pode deixar de ser repetido e o novo pode absorver da espera.
            $skusDoLote[] = $oferta->sku;
            $skusDoLote[] = $variacao->codigo;
        }

        return $this->ofertas->sincronizarDaVariacao($oferta, $variacao->codigo, self::nomeDaOferta($produto->nome, $variacao->valor), $ator,
            varrerEspera: $skusDoLote === null);
    }

    /**
     * Detalhe (folha, nome, caminho) de cada categoria do ML pedida no lote, numa
     * leitura só e antes da transação. Linha com erro de leitura não conta.
     *
     * @param  list<array>  $lidas  saídas do NormalizadorDeLinha
     * @return array<string, ?array> id MLB => detalhe | null (ML não respondeu)
     */
    private function categoriasDoLote(array $lidas): array
    {
        $ids = [];
        foreach ($lidas as $lida) {
            if ($lida['erros'] === [] && in_array('categoria', $lida['presentes'], true) && $lida['campos']['categoria_ml_id'] !== null) {
                $ids[$lida['campos']['categoria_ml_id']] = true;
            }
        }

        return $ids === [] ? [] : $this->categorias->detalhes(array_keys($ids));
    }

    /**
     * Reconciliador (D-11): toda variação da empresa sem oferta ligada ganha a sua.
     * Idempotente — rodado de novo não cria nada.
     *
     * @return int quantas ofertas criou
     */
    public function garantirOfertas(Company $empresa, AtorDoPortal $ator): int
    {
        return DB::transaction(function () use ($empresa, $ator) {
            $criadas = 0;
            $faltam = EstruturaProdutoVariacao::query()
                ->where('company_id', $empresa->id)
                ->whereDoesntHave('oferta')
                ->with('produto')
                ->get();

            foreach ($faltam as $v) {
                $this->criarOferta($empresa, $v->produto, $v, $ator);
                $criadas++;
            }

            return $criadas;
        });
    }

    /**
     * Exclui uma variação pela MESMA regra da Lista SKUs (D-22): componente de
     * combo/kit bloqueia (ValidationException em 'oferta', desfaz tudo); anúncios
     * voltam para a espera; o item do Publicador fica solto (D27). A última
     * variação leva o produto junto — produto sem variação não existe.
     *
     * @return array{produto_excluido: bool, anuncios_para_espera: int, sku: ?string}
     */
    public function excluirVariacao(Company $empresa, int $variacaoId, AtorDoPortal $ator): array
    {
        $variacao = EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->findOrFail($variacaoId);

        return DB::transaction(function () use ($empresa, $variacao, $ator) {
            $sku = $variacao->codigo;
            $produtoId = $variacao->produto_id;
            $oferta = EstruturaOferta::query()->where('variacao_id', $variacao->id)->first();
            $paraEspera = $oferta ? $oferta->anuncios()->count() : 0;

            if ($oferta) {
                // O restrict de componente sobe como ValidationException e desfaz tudo.
                $this->ofertas->excluir($oferta, $ator, viaProduto: true);
            }

            $variacao->delete();

            $produto = EstruturaProduto::query()->where('company_id', $empresa->id)->find($produtoId);
            $produtoExcluido = false;
            if ($produto && ! EstruturaProdutoVariacao::query()->where('produto_id', $produtoId)->exists()) {
                $produto->ambientes()->detach();
                $produto->delete();
                $produtoExcluido = true;
            }

            RegistroEstrutura::registrar($ator, $empresa, null, 'variacao_excluida',
                "Variação {$sku} excluída do Produtos",
                ['sku' => $sku, 'produto_id' => $produtoId, 'anuncios_para_espera' => $paraEspera]);

            if ($produtoExcluido) {
                RegistroEstrutura::registrar($ator, $empresa, null, 'produto_excluido',
                    'Produto excluído com a última variação', ['produto_id' => $produtoId, 'sku' => $sku]);
            }

            return ['produto_excluido' => $produtoExcluido, 'anuncios_para_espera' => $paraEspera, 'sku' => $sku];
        });
    }

    // ═══ Estado do lote ═════════════════════════════════════════════════════

    /**
     * Fotografia do que a empresa já tem, para achar código repetido e produto do
     * grupo sem uma consulta por linha. `definidos` guarda o que a 1ª linha de
     * cada produto pediu (as seguintes só avisam se divergirem).
     */
    private function carregar(Company $empresa): array
    {
        $produtos = EstruturaProduto::query()->where('company_id', $empresa->id)->get();
        $variacoes = EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->get();

        $estado = [
            'produtos'          => [],   // id => EstruturaProduto
            'produtos_codigo'   => [],   // chave => id
            'variacoes'         => [],   // id => EstruturaProdutoVariacao
            'variacoes_codigo'  => [],   // chave => id
            'existiam'          => [],   // produto_id => true (já existia antes do lote)
            'definidos'         => [],   // produto_id => campos pedidos pela 1ª linha
            'categorias'        => [],   // id MLB => detalhe|null (memo do lote)
            'listas'            => [ListasDaEmpresaService::FAMILIA => null, ListasDaEmpresaService::AMBIENTE => null], // chave => item, carregado 1x por lote
        ];

        foreach ($produtos as $p) {
            $estado['produtos'][$p->id] = $p;
            $estado['existiam'][$p->id] = true;
            if ($p->codigo !== null && trim($p->codigo) !== '') {
                $estado['produtos_codigo'][self::chaveCodigo($p->codigo)] = $p->id;
            }
        }
        foreach ($variacoes as $v) {
            $estado['variacoes'][$v->id] = $v;
            $estado['variacoes_codigo'][self::chaveCodigo($v->codigo)] = $v->id;
        }

        return $estado;
    }

    private function aplicarNoEstado(array &$estado, array $res): void
    {
        $produto = $res['produto'];
        $variacao = $res['variacao'];

        $estado['produtos'][$produto->id] = $produto;
        if ($produto->codigo !== null && trim($produto->codigo) !== '') {
            $estado['produtos_codigo'][self::chaveCodigo($produto->codigo)] = $produto->id;
        }
        $estado['variacoes'][$variacao->id] = $variacao;

        foreach ($estado['variacoes_codigo'] as $chave => $id) {
            if ($id === $variacao->id) {
                unset($estado['variacoes_codigo'][$chave]);
            }
        }
        $estado['variacoes_codigo'][self::chaveCodigo($variacao->codigo)] = $variacao->id;

        if (! isset($estado['definidos'][$produto->id])) {
            $estado['definidos'][$produto->id] = $res['pedido'];
        }
        $estado['categorias'] = $res['categorias'];
        $estado['listas'] = $res['listas'];
    }

    // ═══ Uma linha ══════════════════════════════════════════════════════════

    /**
     * Grava UMA linha dentro do savepoint do chamador. Lança ValidationException
     * para a recusa da linha (nada foi gravado: o savepoint desfaz).
     *
     * @return array{resultado: string, skus: list<string>, produto: EstruturaProduto, variacao: EstruturaProdutoVariacao, avisos: list<string>, criadas_nas_listas: array, pedido: array, categorias: array}
     */
    private function gravarLinha(Company $empresa, array $campos, array $presentes, AtorDoPortal $ator, string $modo, array $estado): array
    {
        $codigo = $campos['codigo'];
        $chaveCodigo = self::chaveCodigo($codigo);

        // ─── Quem é a variação e o produto ───
        $variacao = null;
        $produto = null;

        if ($campos['id'] !== null) {
            $variacao = $estado['variacoes'][$campos['id']] ?? null;
            $produto = $variacao ? ($estado['produtos'][$variacao->produto_id] ?? null) : null;
            if (! $variacao || ! $produto) {
                throw ValidationException::withMessages(['id' => self::MSG_NAO_ENCONTRADO]);
            }
        } elseif ($campos['produto_id'] !== null) {
            $produto = $estado['produtos'][$campos['produto_id']] ?? null;
            if (! $produto) {
                throw ValidationException::withMessages(['produto_id' => self::MSG_NAO_ENCONTRADO]);
            }
        }

        $donoDoCodigo = $estado['variacoes_codigo'][$chaveCodigo] ?? null;

        if ($variacao === null && $modo === self::MODO_IMPORTACAO && $donoDoCodigo !== null) {
            $variacao = $estado['variacoes'][$donoDoCodigo];
            $produto = $estado['produtos'][$variacao->produto_id];
        }

        if ($produto === null && $campos['grupo'] !== null) {
            $idDoGrupo = $estado['produtos_codigo'][self::chaveCodigo($campos['grupo'])] ?? null;

            // BE-CR-01: na ficha (MODO_GRADE) o grupo só junta as linhas DESTE lote. Produto que já
            // existia antes do lote se edita pelo `produto_id`; casar pelo código renomeava o produto
            // de outra pessoa e apagava a categoria dele (o `codigo` do produto fica "órfão" quando a
            // Ref da 1ª variação muda). A importação continua casando pelo grupo de propósito (D-14).
            if ($idDoGrupo !== null && $modo === self::MODO_GRADE && isset($estado['existiam'][$idDoGrupo])) {
                throw ValidationException::withMessages([
                    'codigo' => "Já existe um produto com o código {$campos['grupo']}. Abra a ficha dele para adicionar a variação.",
                ]);
            }

            $produto = $idDoGrupo !== null ? $estado['produtos'][$idDoGrupo] : null;
        }

        // ─── Código repetido (D-12) ───
        $repetido = $donoDoCodigo !== null && ($variacao === null || $donoDoCodigo !== $variacao->id);
        if ($repetido) {
            throw ValidationException::withMessages(['codigo' => "O código {$codigo} já existe em outro produto. Use outro código."]);
        }

        $produtoNovo = $produto === null;
        $primeiraDoProduto = $produtoNovo || ! isset($estado['definidos'][$produto->id]);
        $avisos = [];
        $criadas = ['familias' => [], 'ambientes' => []];
        $categorias = $estado['categorias'];

        // ─── Campos do produto: só a 1ª linha de cada produto define ───
        $dadosProduto = [];
        $familiaId = null;
        $mudarFamilia = false;
        $ambienteIds = null;

        if ($primeiraDoProduto) {
            $dadosProduto['nome'] = $campos['nome'];

            if (in_array('categoria', $presentes, true)) {
                $dadosProduto += $this->resolverCategoria($campos, $produto, $categorias, $modo);
                if (isset($dadosProduto['__aviso'])) {
                    $avisos[] = $dadosProduto['__aviso'];
                    unset($dadosProduto['__aviso']);
                }
            }
            if (in_array('familia', $presentes, true)) {
                $mudarFamilia = true;
            }
        } else {
            $avisos = [...$avisos, ...$this->divergencias($produto, $estado['definidos'][$produto->id], $campos, $presentes)];
        }

        // ─── Produto: criar ou atualizar ───
        $mudouProduto = false;

        if ($primeiraDoProduto) {
            if ($mudarFamilia && $campos['familia'] === null) {
                // `familia: null` explícito: o produto fica sem família (BE-IN-05).
                $dadosProduto['familia_id'] = null;
            } elseif ($mudarFamilia) {
                $r = $this->listas->resolverNomes($empresa, ListasDaEmpresaService::FAMILIA, [$campos['familia']], $ator, $estado['listas'][ListasDaEmpresaService::FAMILIA]);
                $familiaId = $r['ids'][0] ?? null;
                $criadas['familias'] = $r['novos'];
                $dadosProduto['familia_id'] = $familiaId;
            }

            if (in_array('ambientes', $presentes, true)) {
                $r = $this->listas->resolverNomes($empresa, ListasDaEmpresaService::AMBIENTE, $campos['ambientes'], $ator, $estado['listas'][ListasDaEmpresaService::AMBIENTE]);
                $ambienteIds = $r['ids'];
                $criadas['ambientes'] = $r['novos'];
            }
        }

        if ($produtoNovo) {
            $codigoProduto = $campos['grupo'] ?? $codigo;
            if (isset($estado['produtos_codigo'][self::chaveCodigo($codigoProduto)])) {
                $codigoProduto = null;
            }

            $produto = new EstruturaProduto(['company_id' => $empresa->id, 'codigo' => $codigoProduto, ...$dadosProduto]);
            $produto->company_id = $empresa->id;
            $produto->save();
            $mudouProduto = true;
        } elseif ($primeiraDoProduto) {
            $produto->fill($dadosProduto);
            if ($produto->isDirty()) {
                $produto->save();
                $mudouProduto = true;
            }
        }

        if ($ambienteIds !== null) {
            $atuais = $produtoNovo ? [] : $produto->ambientes()->pluck('estrutura_ambientes.id')->map(fn ($i) => (int) $i)->all();
            $novos = array_map('intval', $ambienteIds);
            sort($atuais);
            sort($novos);
            if ($atuais !== $novos) {
                $produto->ambientes()->sync($novos);
                $mudouProduto = true;
            }
        }

        // ─── Variação ───
        $variacaoNova = $variacao === null;
        $mudouVariacao = false;
        $volumesParaGravar = null;

        if ($variacaoNova) {
            $copiar = null;
            if (! $produtoNovo && isset($estado['existiam'][$produto->id])) {
                $copiar = $produto->variacoes()->with('volumes')->first();
            }

            $eixo = in_array('eixo', $presentes, true) ? $campos['eixo'] : $copiar?->eixo;
            $custo = in_array('custo', $presentes, true) ? $campos['custo'] : $copiar?->custo;
            $ordem = $campos['ordem'] ?? ((int) $produto->variacoes()->max('ordem') + 1);

            $variacao = new EstruturaProdutoVariacao([
                'produto_id' => $produto->id,
                'company_id' => $empresa->id,
                'ordem'      => $ordem,
                'codigo'     => $codigo,
                'eixo'       => $eixo,
                'valor'      => $campos['valor'],
                'custo'      => $custo,
            ]);
            $variacao->company_id = $empresa->id;
            $variacao->save();

            if (in_array('volumes', $presentes, true)) {
                $volumesParaGravar = $campos['volumes'];
            } elseif ($copiar) {
                $volumesParaGravar = $copiar->volumes
                    ->map(fn ($v) => ['c' => (float) $v->comprimento, 'l' => (float) $v->largura, 'a' => (float) $v->altura, 'kg' => (float) $v->peso])
                    ->all();
            }
            $mudouVariacao = true;
        } else {
            $novo = ['codigo' => $codigo];
            foreach (['eixo', 'valor', 'ordem', 'custo'] as $c) {
                if (in_array($c, $presentes, true)) {
                    $novo[$c] = $campos[$c];
                }
            }
            $variacao->fill($novo);
            if ($variacao->isDirty()) {
                $variacao->save();
                $mudouVariacao = true;
            }

            if (in_array('volumes', $presentes, true)) {
                $atuais = $variacao->volumes()->get()
                    ->map(fn ($v) => [round((float) $v->comprimento, 2), round((float) $v->largura, 2), round((float) $v->altura, 2), round((float) $v->peso, 3)])
                    ->all();
                $pedidos = array_map(fn ($v) => [round($v['c'], 2), round($v['l'], 2), round($v['a'], 2), round($v['kg'], 3)], $campos['volumes']);
                if ($atuais !== $pedidos) {
                    $volumesParaGravar = $campos['volumes'];
                    $mudouVariacao = true;
                }
            }
        }

        if ($volumesParaGravar !== null) {
            EstruturaProdutoVolume::query()->where('variacao_id', $variacao->id)->delete();
            foreach (array_values($volumesParaGravar) as $i => $v) {
                EstruturaProdutoVolume::create([
                    'variacao_id' => $variacao->id, 'ordem' => $i + 1,
                    'comprimento' => $v['c'], 'largura' => $v['l'], 'altura' => $v['a'], 'peso' => $v['kg'],
                ]);
            }
        }

        // ─── Oferta simples ligada (D-08): nasce com a variação e acompanha código/valor/nome ───
        // A espera não é varrida aqui: os SKUs tocados voltam em `skus` e o lote varre uma vez (BE-WR-05).
        $skus = [];
        if ($variacaoNova) {
            $this->criarOferta($empresa, $produto, $variacao, $ator, $skus);
        }
        if ($mudouProduto && ! $produtoNovo) {
            // Produto mudou: TODAS as ofertas dele acompanham — inclusive quando a linha que o
            // renomeou também criou uma variação (BE-WR-03); a oferta da nova já nasceu certa.
            $irmas = EstruturaProdutoVariacao::query()
                ->where('produto_id', $produto->id)
                ->when($variacaoNova, fn ($q) => $q->whereKeyNot($variacao->id))
                ->get();
            foreach ($irmas as $v) {
                $this->sincronizarOferta($empresa, $produto, $v, $ator, $skus);
            }
        } elseif (! $variacaoNova && $mudouVariacao) {
            $this->sincronizarOferta($empresa, $produto, $variacao, $ator, $skus);
        }

        $resultado = $variacaoNova ? 'criadas' : (($mudouProduto || $mudouVariacao) ? 'atualizadas' : 'sem_mudanca');

        return [
            'resultado'          => $resultado,
            'skus'               => $skus,
            'produto'            => $produto,
            'variacao'           => $variacao,
            'avisos'             => $avisos,
            'criadas_nas_listas' => $criadas,
            'pedido'             => [
                'nome'      => $campos['nome'],
                'familia'   => in_array('familia', $presentes, true) ? $campos['familia'] : null,
                'ambientes' => in_array('ambientes', $presentes, true) ? $campos['ambientes'] : null,
                'categoria' => in_array('categoria', $presentes, true) ? ($campos['categoria_ml_id'] ?? $campos['categoria_texto'] ?? '') : null,
            ],
            'categorias'         => $categorias,
            // Mapas das listas com o que esta linha criou: só passam a valer se a linha gravar.
            'listas'             => $estado['listas'],
        ];
    }

    /**
     * Campos de categoria do produto (D-06). Id novo é validado no ML (memoizado
     * no lote); só folha vira confirmada.
     *
     * Na importação, texto só PREENCHE: produto que já tem `categoria_ml_id` fica
     * como está (BE-CR-02, D-14 "nada é apagado"). O D-06 proíbe texto virar id,
     * então deixar o texto valer só conseguiria rebaixar a categoria confirmada.
     *
     * @return array<string, mixed>
     */
    private function resolverCategoria(array $campos, ?EstruturaProduto $produto, array &$memo, string $modo): array
    {
        $id = $campos['categoria_ml_id'];

        if ($id === null) {
            if ($modo === self::MODO_IMPORTACAO && $produto && trim((string) $produto->categoria_ml_id) !== '') {
                return [];
            }
            if ($campos['categoria_texto'] !== null) {
                return ['categoria_ml_id' => null, 'categoria_ml_nome' => $campos['categoria_texto'], 'categoria_ml_caminho' => null];
            }

            return ['categoria_ml_id' => null, 'categoria_ml_nome' => null, 'categoria_ml_caminho' => null];
        }

        // A grade devolve a categoria já confirmada em toda linha: não revalida o que não mudou.
        if ($produto && $produto->categoria_ml_id === $id && trim((string) $produto->categoria_ml_nome) !== '') {
            return [];
        }

        // O lote já validou as suas categorias antes da transação (`categoriasDoLote`); aqui
        // dentro não há chamada ao ML. Um id fora do memo (não deveria) fica "não validado".
        $detalhe = $memo[$id] ?? null;

        if ($detalhe === null) {
            return [
                'categoria_ml_id' => $id, 'categoria_ml_nome' => null, 'categoria_ml_caminho' => null,
                '__aviso' => "Categoria {$id} não validada agora.",
            ];
        }

        if (! $detalhe['folha']) {
            throw ValidationException::withMessages(['categoria_ml_id' => 'Escolha uma categoria mais específica (a última do caminho).']);
        }

        return [
            'categoria_ml_id'      => $detalhe['id'],
            'categoria_ml_nome'    => $detalhe['nome'],
            'categoria_ml_caminho' => implode(' > ', array_column($detalhe['caminho'], 'nome')),
        ];
    }

    /**
     * Linhas seguintes do mesmo produto que pedem outro nome/família/ambientes/
     * categoria: vale a 1ª linha e o resto só avisa.
     *
     * @return list<string>
     */
    private function divergencias(EstruturaProduto $produto, array $primeira, array $campos, array $presentes): array
    {
        $avisos = [];
        $rotulo = $produto->codigo ?? $campos['codigo'];
        $diverge = function (string $campo) use (&$avisos, $rotulo) {
            $avisos[] = "Produto {$rotulo}: usamos {$campo} da primeira linha.";
        };

        if ($campos['nome'] !== $primeira['nome']) {
            $diverge('o nome');
        }
        if (in_array('familia', $presentes, true) && $primeira['familia'] !== null
            && ListasDaEmpresaService::chave((string) $campos['familia']) !== ListasDaEmpresaService::chave((string) $primeira['familia'])) {
            $diverge('a família');
        }
        if (in_array('ambientes', $presentes, true) && $primeira['ambientes'] !== null) {
            $a = array_map([ListasDaEmpresaService::class, 'chave'], $campos['ambientes']);
            $b = array_map([ListasDaEmpresaService::class, 'chave'], $primeira['ambientes']);
            sort($a);
            sort($b);
            if ($a !== $b) {
                $diverge('os ambientes');
            }
        }
        if (in_array('categoria', $presentes, true) && $primeira['categoria'] !== null
            && (string) ($campos['categoria_ml_id'] ?? $campos['categoria_texto'] ?? '') !== (string) $primeira['categoria']) {
            $diverge('a categoria');
        }

        return $avisos;
    }

    private function erro(int $indice, array $campos, string $mensagem, array $porCampo): array
    {
        return [
            'indice'   => $indice,
            'chave'    => $campos['chave'] ?? null,
            'codigo'   => $campos['codigo'] !== '' ? $campos['codigo'] : null,
            'mensagem' => $mensagem,
            'campos'   => $porCampo,
        ];
    }
}
