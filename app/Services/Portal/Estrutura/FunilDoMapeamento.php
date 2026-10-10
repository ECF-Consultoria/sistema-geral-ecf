<?php

namespace App\Services\Portal\Estrutura;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\MlCategoriaSchema;
use App\Services\Portal\Estrutura\Geracao\SugestoesService;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Services\Portal\Estrutura\Produtos\PendenciasDoProduto;
use Illuminate\Support\Facades\Cache;

/**
 * O "funil" do Mapeamento (09/10/2026): o que está pendente, em números que o sistema JÁ
 * calcula, cada um com a tela certa para resolver. Pedido do usuário: "vou usar produtos,
 * planejamento e precificação — e o mapeamento para saber o que está pendente".
 *
 * - Produtos: os que têm pendência de cadastro ({@see PendenciasDoProduto}, a mesma régua da
 *   tela de Produtos) ou ficha técnica com obrigatório em branco (a mesma conta do preparo:
 *   categoria + obrigatórios, sem o Modelo). A ficha só é conferida com a definição da
 *   categoria já guardada (tabela do schema ou o cache de 7 dias): o funil nunca faz
 *   requisição externa.
 * - Planejamento: sugestões pendentes e produtos sem tipo — a mesma geração da tela
 *   ({@see SugestoesService}) e a mesma regra do "Sem tipo" de `ListaDeSugestoes`.
 * - Precificação: o resumo de {@see EstruturaPrecificacaoService::pagina} (sem custo, sem frete,
 *   preço impossível), lido sem mudar nada.
 * - À venda: ofertas com algo no ar e completas, pela régua do painel ({@see ReguaEstrutura}).
 *
 * Sigilo do Portal: a tela escreve tudo em termos neutros (nada de plataforma).
 */
class FunilDoMapeamento
{
    public function __construct(
        private SugestoesService $sugestoes,
        private EstruturaPrecificacaoService $precificacao,
    ) {}

    /**
     * @return array{produtos: array{total: int, pendentes: int, com_pendencia: int, ficha_incompleta: int}, planejamento: array{sugestoes: int, sem_tipo: int}, precificacao: array{total: int, precificadas: int, sem_custo: int, sem_frete: int, impossivel: int, pendentes: int}, venda: array{ofertas: int, a_venda: int, completas: int, sem_nada: int}}
     */
    public function daEmpresa(Company $empresa): array
    {
        $geracao = $this->sugestoes->gerar($empresa);
        $vigentes = count(array_filter($geracao['sugestoes'], fn ($s) => ! $s['descartada']));
        $semTipo = count(array_filter($geracao['retrato']['detalhes']['produtos'], fn ($d) => $d['tipo'] === null));

        $resumo = $this->precificacao->pagina($empresa, [])['resumo'];

        $ofertas = EstruturaConjunto::daEmpresa($empresa)->ofertas();
        $painel = ReguaEstrutura::painel(array_values($ofertas));
        $aVenda = count(array_filter($ofertas, fn ($o) => $o['classicos'] > 0 || $o['premiums'] > 0));

        return [
            'produtos'     => $this->produtos($empresa),
            'planejamento' => ['sugestoes' => $vigentes, 'sem_tipo' => $semTipo],
            'precificacao' => [
                'total'        => (int) $resumo['total'],
                'precificadas' => (int) $resumo['precificadas'],
                'sem_custo'    => (int) $resumo['sem_custo'],
                'sem_frete'    => (int) $resumo['sem_frete'],
                'impossivel'   => (int) $resumo['impossivel'],
                'pendentes'    => (int) $resumo['sem_custo'] + (int) $resumo['sem_frete'] + (int) $resumo['impossivel'],
            ],
            'venda' => [
                'ofertas'   => (int) $painel['ofertas'],
                'a_venda'   => $aVenda,
                'completas' => (int) $painel['completas'],
                'sem_nada'  => (int) $painel['ofertas'] - $aVenda,
            ],
        ];
    }

    /**
     * Produtos com alguma pendência de cadastro numa variação, ou com a ficha técnica
     * incompleta. Número fixo de consultas: produtos (com família, ambientes, variações e
     * volumes), atributos gravados e as definições guardadas das categorias.
     *
     * @return array{total: int, pendentes: int, com_pendencia: int, ficha_incompleta: int}
     */
    private function produtos(Company $empresa): array
    {
        $produtos = EstruturaProduto::query()
            ->where('company_id', $empresa->id)
            ->with(['familia', 'ambientes', 'variacoes.volumes'])
            ->get();

        $salvos = EstruturaProdutoAtributo::query()
            ->where('company_id', $empresa->id)
            ->get(['produto_id', 'atributo_id', 'valor', 'valor_id'])
            ->groupBy('produto_id');

        // A mesma logística da tela de Produtos: limites da modalidade de envio já lida (cache).
        $modalidade = ModalidadeDeEnvio::emCache($empresa);
        $definicoes = [];
        $comPendencia = 0;
        $fichaIncompleta = 0;
        $pendentes = 0;

        foreach ($produtos as $p) {
            $pendencia = false;
            foreach ($p->variacoes as $v) {
                $volumes = $v->volumes
                    ->map(fn ($vol) => ['c' => (float) $vol->comprimento, 'l' => (float) $vol->largura, 'a' => (float) $vol->altura, 'kg' => (float) $vol->peso])
                    ->values()->all();
                $achadas = PendenciasDoProduto::daLinha([
                    'volumes'         => $volumes,
                    'custo'           => $v->custo,
                    'categoria_ml_id' => $p->categoria_ml_id,
                    'familia'         => $p->familia?->nome,
                    'ambientes'       => $p->ambientes->pluck('nome')->all(),
                    'logistica'       => LogisticaProduto::daVolumes($volumes, $modalidade)['logistica'],
                ]);
                if ($achadas !== []) {
                    $pendencia = true;
                    break;
                }
            }

            $categoria = trim((string) $p->categoria_ml_id);
            $incompleta = false;
            if ($categoria !== '') {
                $definicoes[$categoria] ??= $this->definicaoGuardada($categoria);
                if ($definicoes[$categoria] !== null) {
                    $eixos = $p->variacoes->pluck('eixo')->filter(fn ($e) => trim((string) $e) !== '')->unique()->values()->all();
                    $incompleta = ! $this->fichaCompleta($definicoes[$categoria], $eixos, $salvos->get($p->id, collect()));
                }
            }

            $comPendencia += $pendencia ? 1 : 0;
            $fichaIncompleta += $incompleta ? 1 : 0;
            $pendentes += ($pendencia || $incompleta) ? 1 : 0;
        }

        return [
            'total'            => $produtos->count(),
            'pendentes'        => $pendentes,
            'com_pendencia'    => $comPendencia,
            'ficha_incompleta' => $fichaIncompleta,
        ];
    }

    /**
     * Todos os obrigatórios da ficha (com o eixo do produto fora e sem o Modelo, que é gerado
     * depois) têm valor? Mesma régua do preparo no Publicador.
     */
    private function fichaCompleta(array $definicao, array $eixos, $salvos): bool
    {
        $porId = $salvos->keyBy('atributo_id');

        foreach (FichaTecnicaDaCategoria::camposPorId(FichaTecnicaDaCategoria::doProduto($definicao, $eixos)) as $id => $campo) {
            if (empty($campo['obrigatorio']) || $id === FichaTecnicaDaCategoria::ID_MODELO) {
                continue;
            }
            $s = $porId->get($id);
            $valorId = trim((string) $s?->valor_id);
            $preenchido = $s !== null && (trim((string) $s->valor) !== '' || ($valorId !== '' && $valorId !== FichaTecnicaDoProduto::NAO_SE_APLICA));
            if (! $preenchido) {
                return false;
            }
        }

        return true;
    }

    /**
     * A definição da ficha da categoria, só do que já está guardado: o schema da categoria
     * (tabela) ou os atributos no cache de 7 dias. Null = não dá para conferir sem buscar fora.
     */
    private function definicaoGuardada(string $categoria): ?array
    {
        $atributos = MlCategoriaSchema::query()->find($categoria)?->atributos;
        if (! is_array($atributos) || $atributos === []) {
            $emCache = Cache::get("ml_meta_atributos_{$categoria}");
            $atributos = is_array($emCache) ? $emCache : null;
        }

        if ($atributos === null || $atributos === []) {
            return null;
        }

        $definicao = FichaTecnicaDaCategoria::daAtributos($atributos);

        return $definicao === [] ? null : $definicao;
    }
}
