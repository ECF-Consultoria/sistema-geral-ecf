<?php

namespace App\Services\Trilha;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A Trilha de produtos de UMA empresa (1ª entrega, 30/10/2026): cada produto do catálogo do cliente e cada
 * oferta numa trilha de 6 etapas com dono — Lista de produtos (cliente) → Mapeamento estrutural → Planejamento
 * → Preparação automática (agente) → Revisão e publicação → Protocolo e Jardinagem.
 *
 * SÓ LEITURA, montada com o que já está no banco: nenhuma chamada ao Mercado Livre, nenhum job, nenhuma escrita.
 * Por isso nada aqui chama `EditorRascunhoService::estado()`, `VitrineDeProdutosService::montar()`,
 * `sincronizarAgora()` nem `FichaTecnicaDoProduto::planejarParcial()` — todos gravam ou vão ao ML.
 *
 * ### Número FIXO de consultas
 * Qualquer que seja o tamanho do catálogo: a geração das sugestões (a mesma da tela de Planejamento, que já é de
 * consultas fixas), os produtos com família, ambientes, variações e volumes, a ficha gravada, a contagem de fotos,
 * as definições guardadas das categorias, as ofertas, os componentes e o que o Publicador sabe delas
 * ({@see SituacaoDasOfertas}). Leituras de cache em lote (`Cache::many`).
 *
 * ### Regras de etapa (o porquê de cada uma está nas classes)
 * - Produto ({@see CompletudeDoProduto}): falta algo no cadastro → `lista`; senão, sugestão PENDENTE do
 *   Planejamento que envolve alguma cor dele → `mapa`; senão `null` (mapeado: segue pelas ofertas). Nada no
 *   sistema marca "mapeamento concluído" — até existir o botão, o produto sai do mapeamento quando não sobra
 *   sugestão esperando resposta.
 * - Oferta ({@see OfertasDaTrilha} agrupa, {@see SituacaoDasOfertas} decide): Kit, Combit, Combo e oferta sem
 *   produto aparecem SEMPRE (aceitar já foi uma decisão). A Simples de um produto ainda na lista ou no
 *   mapeamento só aparece se já "andou" (tem anúncio, o rascunho passou do DRAFT ou o agente já mexeu): senão
 *   ela é parte do produto e não aparece.
 *
 * Classe não final de propósito: o teste de acesso das portas troca este serviço por um dublê.
 */
class TrilhaDaEmpresa
{
    /** Quantas sugestões pendentes cada produto leva para a tela (o total vem à parte). */
    public const MAX_SUGESTOES = 30;

    private const ORDEM_DO_PRODUTO = ['lista' => 0, 'mapa' => 1, '' => 2];

    private const ORDEM_DA_OFERTA = ['plano' => 0, 'robo' => 1, 'revisao' => 2, 'lanc' => 3];

    private const ORDEM_DO_TIPO = ['Simples' => 0, 'Combo' => 1, 'Kit' => 2, 'Combit' => 3];

    public function __construct(private SugestoesService $sugestoes) {}

    /**
     * A trilha da empresa, montada só com o que já está no banco (nenhuma chamada ao ML, nenhum job).
     * Número FIXO de consultas, qualquer que seja o tamanho do catálogo.
     *
     * @return array{produtos: list<array>, ofertas: list<array>, ondas: list<array>, gerado_em: string}
     */
    public function montar(Company $empresa): array
    {
        $empresaId = (int) $empresa->id;

        // Planejamento: a mesma geração da tela de sugestões (consultas fixas, nunca o ML).
        $pendentes = self::sugestoesPendentes($this->sugestoes->gerar($empresa)['sugestoes']);

        $produtos = EstruturaProduto::query()
            ->where('company_id', $empresaId)
            ->with(['familia', 'ambientes', 'variacoes.volumes'])
            ->orderBy('id')
            ->get();

        $salvos = EstruturaProdutoAtributo::query()
            ->where('company_id', $empresaId)
            ->get(['produto_id', 'atributo_id', 'valor', 'valor_id', 'unidade'])
            ->groupBy('produto_id');

        $fotos = self::fotosPorVariacao($empresaId);
        $definicoes = CompletudeDoProduto::definicoesGuardadas($produtos->pluck('categoria_ml_id'));
        // A mesma logística da tela de Produtos: limites da modalidade de envio já lida (só cache).
        $modalidade = ModalidadeDeEnvio::emCache($empresa);

        $ofertas = EstruturaOferta::query()
            ->where('company_id', $empresaId)
            ->orderBy('id')
            ->get(['id', 'variacao_id', 'sku', 'fase', 'nome']);

        // Componentes com a empresa na ponta da oferta (o componente é sempre uma simples da mesma empresa).
        $componentes = DB::table('estrutura_oferta_componentes as c')
            ->join('estrutura_ofertas as o', 'o.id', '=', 'c.oferta_id')
            ->where('o.company_id', $empresaId)
            ->orderBy('c.id')
            ->get(['c.oferta_id', 'c.componente_id', 'c.quantidade']);

        $situacao = SituacaoDasOfertas::daEmpresa($empresa, array_map('intval', $produtos->modelKeys()));
        $itens = OfertasDaTrilha::agrupar($produtos, $ofertas, $componentes);

        // ─── Produtos ───
        $saidaProdutos = [];
        foreach ($produtos as $p) {
            $c = CompletudeDoProduto::avaliar($p, $salvos->get($p->id, collect()), $fotos, $definicoes, $modalidade);
            $sugestoes = $pendentes[(int) $p->id] ?? [];
            $codigo = trim((string) $p->codigo);
            $categoria = trim((string) $p->categoria_ml_nome);
            $ambientes = $p->ambientes->pluck('nome')->map(fn ($n) => (string) $n)->sort(fn ($a, $b) => self::chave($a) <=> self::chave($b))->values()->all();

            $saidaProdutos[(int) $p->id] = [
                'id'                        => (int) $p->id,
                'nome'                      => (string) $p->nome,
                'codigo'                    => $codigo !== '' ? $codigo : null,
                'skus'                      => $p->variacoes->map(fn ($v) => (string) $v->codigo)->values()->all(),
                'familia'                   => $p->familia?->nome,
                'ambientes'                 => $ambientes,
                'categoria'                 => $categoria !== '' ? $categoria : null,
                'variacoes'                 => $p->variacoes->count(),
                'custo_min'                 => $c['custo_min'],
                'custo_max'                 => $c['custo_max'],
                'medidas'                   => $c['medidas'],
                'embalagem'                 => $c['embalagem'],
                'fotos_min'                 => $c['fotos_min'],
                'tem_descricao'             => $c['tem_descricao'],
                'ficha'                     => $c['ficha'],
                'faltas'                    => $c['faltas'],
                'etapa'                     => $c['faltas'] !== [] ? 'lista' : ($sugestoes !== [] ? 'mapa' : null),
                'sugestoes_pendentes'       => array_slice($sugestoes, 0, self::MAX_SUGESTOES),
                'sugestoes_pendentes_total' => count($sugestoes),
                'pub_produto_id'            => null,
            ];
        }

        // ─── Ofertas: as Simples primeiro (o Combo espera a Simples dele ir ao ar) ───
        $saidaOfertas = [];
        $andou = [];
        $pubsDaSimples = [];
        foreach ($itens['simples'] as $produtoId => $item) {
            $pubsDaSimples[$produtoId] = $situacao->pubsDaSimples((int) $produtoId, $item['oferta_ids']);
            [$saidaOfertas[$item['id']], $andou[$item['id']]] = self::item($item, $situacao->doItem($pubsDaSimples[$produtoId], $item['oferta_ids'], false));
            // O link do produto para o Publicador é o da Simples dele (o grupo das cores; sem grupo, o legado).
            $saidaProdutos[(int) $produtoId]['pub_produto_id'] = $pubsDaSimples[$produtoId]['principal'];
        }
        foreach ($itens['sem_produto'] as $item) {
            [$saidaOfertas[$item['id']]] = self::item($item, $situacao->doItem($situacao->pubsDaOferta($item['oferta_ids'][0]), $item['oferta_ids'], false));
        }

        $simplesNoAr = function (array $dependeDe) use (&$saidaOfertas): bool {
            foreach ($dependeDe as $id) {
                if (($saidaOfertas[$id]['etapa'] ?? null) !== 'lanc') {
                    return false;
                }
            }

            return true;
        };
        foreach ($itens['combos'] as $item) {
            $pubs = $situacao->pubsDoCombo($pubsDaSimples[$item['produto_id']] ?? ['principal' => null, 'todos' => []], (int) $item['quantidade'], $item['oferta_ids']);
            [$saidaOfertas[$item['id']]] = self::item($item, $situacao->doItem($pubs, $item['oferta_ids'], ! $simplesNoAr($item['depende_de'])));
        }
        foreach ($itens['avulsas'] as $item) {
            $espera = $item['depende_de'] !== [] && ! $simplesNoAr($item['depende_de']);
            [$saidaOfertas[$item['id']]] = self::item($item, $situacao->doItem($situacao->pubsDaOferta($item['oferta_ids'][0]), $item['oferta_ids'], $espera));
        }

        // Quem aparece: a Simples de produto ainda na lista/no mapeamento só se já andou.
        foreach ($itens['simples'] as $produtoId => $item) {
            if (($saidaProdutos[(int) $produtoId]['etapa'] ?? null) !== null && ! $andou[$item['id']]) {
                unset($saidaOfertas[$item['id']]);
            }
        }

        $listaProdutos = array_values($saidaProdutos);
        usort($listaProdutos, fn (array $a, array $b) => [self::ORDEM_DO_PRODUTO[$a['etapa'] ?? ''], self::chave($a['nome']), $a['id']]
            <=> [self::ORDEM_DO_PRODUTO[$b['etapa'] ?? ''], self::chave($b['nome']), $b['id']]);

        $listaOfertas = array_values($saidaOfertas);
        usort($listaOfertas, fn (array $a, array $b) => [self::ORDEM_DA_OFERTA[$a['etapa']] ?? 9, self::ORDEM_DO_TIPO[$a['tipo']] ?? 9, self::chave($a['nome']), $a['id']]
            <=> [self::ORDEM_DA_OFERTA[$b['etapa']] ?? 9, self::ORDEM_DO_TIPO[$b['tipo']] ?? 9, self::chave($b['nome']), $b['id']]);

        return [
            'produtos'  => $listaProdutos,
            'ofertas'   => $listaOfertas,
            // Ondas e "robô na data" são da próxima entrega.
            'ondas'     => [],
            'gerado_em' => now()->setTimezone('America/Sao_Paulo')->toIso8601String(),
        ];
    }

    /**
     * O item no formato do contrato, e se ele "andou" (para a regra de quem aparece).
     *
     * @return array{0: array, 1: bool}
     */
    private static function item(array $item, array $s): array
    {
        return [[
            'id'             => $item['id'],
            'tipo'           => $item['tipo'],
            'nome'           => $item['nome'],
            'sku'            => $item['sku'],
            'skus'           => $item['skus'],
            'componentes'    => $item['componentes'],
            'etapa'          => $s['etapa'],
            'status'         => $s['status'],
            'alerta'         => $s['alerta'],
            'anuncios'       => $s['anuncios'],
            'preparo'        => $s['preparo'],
            'onda_id'        => null,
            'sem_produto'    => $item['sem_produto'],
            'pub_produto_id' => $s['pub_produto_id'],
        ], $s['andou']];
    }

    /**
     * As sugestões PENDENTES (não descartadas) de cada produto envolvido nelas, na ordem da tela de
     * Planejamento. Aceita, a sugestão vira oferta e sai da geração; descartada, vem marcada.
     *
     * @param  list<array<string, mixed>>  $sugestoes  saída de `SugestoesService::gerar()['sugestoes']`
     * @return array<int, list<array{fase: string, nome: string, sku: ?string}>>
     */
    private static function sugestoesPendentes(array $sugestoes): array
    {
        $porProduto = [];
        foreach ($sugestoes as $s) {
            if (! empty($s['descartada'])) {
                continue;
            }
            $produtos = [];
            foreach ((array) ($s['itens'] ?? []) as $i) {
                if (isset($i['produto_id'])) {
                    $produtos[(int) $i['produto_id']] = true;
                }
            }
            $sku = trim((string) ($s['sku'] ?? ''));
            foreach (array_keys($produtos) as $produtoId) {
                $porProduto[$produtoId][] = ['fase' => (string) $s['fase'], 'nome' => (string) $s['nome'], 'sku' => $sku !== '' ? $sku : null];
            }
        }

        return $porProduto;
    }

    /**
     * Quantas fotos cada variação tem, numa consulta agrupada.
     *
     * @return array<int, int>
     */
    private static function fotosPorVariacao(int $empresaId): array
    {
        $saida = [];
        $linhas = DB::table('estrutura_produto_variacao_imagens')
            ->where('company_id', $empresaId)
            ->groupBy('variacao_id')
            ->select('variacao_id', DB::raw('count(*) as total'))
            ->get();
        foreach ($linhas as $l) {
            $saida[(int) $l->variacao_id] = (int) $l->total;
        }

        return $saida;
    }

    /** Chave de ordem do nome: sem acento e sem caixa ("Ábaco" junto de "abacate"). */
    private static function chave(string $nome): string
    {
        return Str::lower(Str::ascii($nome));
    }
}
