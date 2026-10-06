<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaPrecificacaoParametros;
use App\Services\MercadoLivreService;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\PrecificacaoEstrutura;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Frete ME2 de cada variação (D-16): estimativa imediata pela tabela da ECF e,
 * com a conta do ML do cliente conectada, cotação real pelo mesmo endpoint do
 * Publicador (GET /users/{seller}/shipping_options/free).
 *
 * D-19: o valor é SÓ EXIBIDO. Nada daqui grava em estrutura_precificacoes nem
 * muda o preço efetivo que o Publicador herda. Só GET na conta do cliente.
 *
 * Por que não o `ClienteMlPublicador` em laço: ele dorme (até 16 s por tentativa)
 * dentro da requisição web. Nem o `MercadoLivreService::getMany`: ele refaz em SÉRIE
 * cada pedido que falhou no pool, com retry de 429 (`sleep` de até 8 s, 3 vezes) e
 * timeout de 30 s — com o ML degradado, um POST /fretes passava de 10 minutos
 * (BE-WR-06). Aqui o pool é próprio, com timeout curto e sem segunda tentativa: o
 * que falha cai na tabela da ECF marcado `falhou`, e a pessoa consulta de novo.
 *
 * Ressalva A2 da pesquisa: em algumas categorias o ML pode ignorar as dimensões
 * enviadas, por isso o rótulo é sempre "estimado" na tela.
 *
 * Contrato de item (chave = id da variação):
 *   ['pacote' => ?array, 'peso_faturado' => ?float, 'logistica' => string, 'custo' => ?float]
 * Contrato de frete devolvido (uma forma só):
 *   ['valor', 'origem' => 'api'|'tabela_ecf'|null, 'preco_usado', 'preco_origem' => 'custo'|'referencia'|null,
 *    'alerta_faixa', 'instavel', 'falhou', 'cotado_em', 'gratis_obrigatorio']
 */
class FreteMe2Service
{
    private const API_BASE = 'https://api.mercadolibre.com';

    /** Segundos: resposta e conexão de cada cotação (BE-WR-06). */
    private const TIMEOUT          = 8;
    private const TIMEOUT_CONEXAO  = 3;

    public function __construct(private MercadoLivreService $ml) {}

    /** Dimensões no formato do ML: AxLxC,peso em gramas (ordem A×L×C, não C×L×A). */
    public static function dimensions(array $pacote): string
    {
        return sprintf('%dx%dx%d,%d', round($pacote['a']), round($pacote['l']), round($pacote['c']), round($pacote['peso_real'] * 1000));
    }

    /**
     * Estimativa imediata, sem nenhuma requisição ao ML.
     *
     * @return array<int|string, array>
     */
    public function estimar(Company $empresa, array $itens): array
    {
        $parametros = EstruturaPrecificacaoParametros::daEmpresa($empresa->id);
        $saida = [];

        foreach ($itens as $id => $item) {
            if (! $this->ehMe2($item)) {
                $saida[$id] = $this->vazio();
                continue;
            }

            $saida[$id] = Cache::get($this->chaveFinal($empresa, $id, $item, $parametros))
                ?? $this->pelaTabela($parametros, $item);
        }

        return $saida;
    }

    /**
     * Cotação real pela conta do cliente.
     *
     * @return array{fretes: array, pendentes: int, conectado: bool, falhou: bool}
     */
    public function cotar(Company $empresa, array $itens): array
    {
        if (! AnunciosMercadoLivreService::conectado($empresa)) {
            return ['fretes' => $this->estimar($empresa, $itens), 'pendentes' => 0, 'conectado' => false, 'falhou' => false];
        }

        $cfg        = config('estrutura_produtos.frete');
        $parametros = EstruturaPrecificacaoParametros::daEmpresa($empresa->id);
        $sellerId   = $empresa->mlToken->ml_user_id;
        $horas      = (int) $cfg['cache_horas'];
        $orcamento  = (int) $cfg['max_por_requisicao'];

        $fretes = [];
        $abertos = [];
        $pendentes = 0;
        $falhou = false;

        foreach ($itens as $id => $item) {
            if (! $this->ehMe2($item)) {
                $fretes[$id] = $this->vazio();
                continue;
            }
            $cache = Cache::get($this->chaveFinal($empresa, $id, $item, $parametros));
            if ($cache) {
                $fretes[$id] = $cache;
                continue;
            }
            $abertos[$id] = ['ultimo' => null, 'gratis' => null, 'chave' => null, 'coluna' => null, 'mudou' => false, 'preco' => null, 'origem' => null, 'cotado_em' => null];
        }

        $fechar = function ($id, array $est, string $origem, bool $instavel = false) use (&$fretes, &$abertos, $empresa, $itens, $parametros, $horas) {
            $item = $itens[$id];
            if ($origem === 'api') {
                $f = [
                    'valor' => $est['ultimo'], 'origem' => 'api', 'preco_usado' => $est['preco'], 'preco_origem' => $est['origem'],
                    'alerta_faixa' => $est['mudou'] || $instavel, 'instavel' => $instavel, 'falhou' => false,
                    'cotado_em' => $est['cotado_em'], 'gratis_obrigatorio' => (bool) $est['gratis'],
                ];
                Cache::put($this->chaveFinal($empresa, $id, $item, $parametros), $f, now()->addHours($horas));
            } else {
                $f = array_merge($this->pelaTabela($parametros, $item), ['falhou' => true]);
            }
            $fretes[$id] = $f;
            unset($abertos[$id]);
        };

        try {
            for ($rodada = 0; $rodada <= (int) $cfg['max_recotacoes'] && $abertos !== []; $rodada++) {
                $pedidos = [];
                $porItem = [];

                foreach ($abertos as $id => $est) {
                    $p    = $this->precoDeCotacao($parametros, $itens[$id]['custo'] ?? null, $est['ultimo']);
                    $dims = self::dimensions($itens[$id]['pacote']);
                    $chave = $this->chaveCotacao($empresa, $dims, $p['preco']);
                    $coluna = TabelaFreteEcf::faixas((float) $itens[$id]['peso_faturado'], $p['preco'])['coluna'];

                    // Já cotado e a faixa de preço não mudou: o frete confirmou, não há o que perguntar.
                    if ($est['ultimo'] !== null && ($chave === $est['chave'] || $coluna === $est['coluna'])) {
                        $fechar($id, $est, 'api');
                        continue;
                    }

                    $abertos[$id]['preco']  = $p['preco'];
                    $abertos[$id]['origem'] = $p['origem'];
                    $porItem[$id] = ['chave' => $chave, 'coluna' => $coluna, 'dims' => $dims];

                    if (Cache::has($chave) || isset($pedidos[$chave])) {
                        continue;
                    }
                    if ($orcamento <= 0) {
                        // Não coube nesta chamada.
                        unset($porItem[$id]);
                        if ($est['ultimo'] === null) {
                            $pendentes++;
                            $fretes[$id] = $this->pelaTabela($parametros, $itens[$id]);
                            unset($abertos[$id]);
                        } else {
                            $fechar($id, $abertos[$id], 'api', true);
                        }
                        continue;
                    }
                    $orcamento--;
                    $pedidos[$chave] = ["/users/{$sellerId}/shipping_options/free", [
                        'item_price'      => round($p['preco'], 2),
                        'listing_type_id' => 'gold_special', // o frete ME2 não muda entre Clássico e Premium (PORTAL-02)
                        'mode'            => 'me2',
                        'condition'       => 'new',
                        'logistic_type'   => 'drop_off',
                        'dimensions'      => $dims,
                        'verbose'         => 'true',
                    ]];
                }

                $respostas = $pedidos === [] ? [] : $this->cotarEmParalelo($empresa, $pedidos);

                foreach ($respostas as $chave => $resp) {
                    if (is_array($resp)) {
                        $cobertura = $resp['coverage']['all_country'] ?? [];
                        Cache::put($chave, [
                            'valor'     => isset($cobertura['list_cost']) ? (float) $cobertura['list_cost'] : null,
                            // O aviso vem da resposta da API, nunca de um limite no código.
                            'gratis'    => ($cobertura['discount']['type'] ?? null) === 'mandatory' && empty($cobertura['free_shipping_by_meli']),
                            'cotado_em' => now()->toIso8601String(),
                        ], now()->addHours($horas));
                    }
                }

                foreach ($porItem as $id => $info) {
                    if (! isset($abertos[$id])) {
                        continue;
                    }
                    $resp = $respostas[$info['chave']] ?? null;
                    $cot  = $resp === null ? Cache::get($info['chave']) : (is_array($resp) ? Cache::get($info['chave']) : null);

                    if (! is_array($cot) || $cot['valor'] === null) {
                        $falhou = true;
                        if ($resp instanceof \Throwable) {
                            Log::warning("[Estrutura Produtos] cotação de frete falhou empresa {$empresa->id}: ".$resp->getMessage());
                        }
                        $fechar($id, $abertos[$id], 'tabela_ecf');
                        continue;
                    }

                    $est = &$abertos[$id];
                    if ($est['ultimo'] !== null && $est['gratis'] !== $cot['gratis']) {
                        $est['mudou'] = true;
                    }
                    $anterior = $est['ultimo'];
                    $est['ultimo']    = $cot['valor'];
                    $est['gratis']    = $cot['gratis'];
                    $est['chave']     = $info['chave'];
                    $est['coluna']    = $info['coluna'];
                    $est['cotado_em'] = $cot['cotado_em'];
                    unset($est);

                    if ($anterior !== null && $anterior === $cot['valor']) {
                        $fechar($id, $abertos[$id], 'api');
                    }
                }
            }

            // Sobrou item sem convergir depois das re-cotações: instável.
            foreach ($abertos as $id => $est) {
                if ($est['ultimo'] === null) {
                    $fechar($id, $est, 'tabela_ecf');
                } else {
                    $fechar($id, $est, 'api', true);
                }
            }
        } catch (\RuntimeException $e) {
            // Sem token válido: tudo cai na estimativa da tabela.
            Log::warning("[Estrutura Produtos] cotação de frete sem token válido empresa {$empresa->id}: ".$e->getMessage());
            $falhou = true;
            foreach ($abertos as $id => $est) {
                $fretes[$id] = array_merge($this->pelaTabela($parametros, $itens[$id]), ['falhou' => true]);
            }
        }

        // Mantém a ordem dos itens.
        $ordenado = [];
        foreach ($itens as $id => $_) {
            $ordenado[$id] = $fretes[$id] ?? $this->vazio();
        }

        return ['fretes' => $ordenado, 'pendentes' => $pendentes, 'conectado' => true, 'falhou' => $falhou];
    }

    // ═══ Internos ═══

    /**
     * Os GETs de uma rodada em paralelo, com timeout curto e SEM refazer o que falhou
     * (nem em série, nem com `sleep`): 429, 5xx, timeout e rede voltam como a exceção
     * daquele pedido, e `cotar()` usa a tabela da ECF com `falhou`.
     *
     * @param  array<string, array{0: string, 1: array}>  $pedidos  chave → [endpoint, query]
     * @return array<string, array|\Throwable>  chave → corpo da resposta, ou a falha daquele pedido
     *
     * @throws \RuntimeException sem token válido
     */
    private function cotarEmParalelo(Company $empresa, array $pedidos): array
    {
        $token = $this->ml->ensureValidToken($empresa);
        if (! $token) {
            throw new \RuntimeException("empresa {$empresa->id} sem token válido do Mercado Livre");
        }

        $respostas = Http::pool(function (Pool $pool) use ($pedidos, $token) {
            foreach ($pedidos as $chave => $pedido) {
                $pool->as((string) $chave)
                    ->withToken($token->access_token)
                    ->timeout(self::TIMEOUT)
                    ->connectTimeout(self::TIMEOUT_CONEXAO)
                    ->get(self::API_BASE.$pedido[0], $pedido[1] ?? []);
            }
        });

        $saida = [];
        foreach ($pedidos as $chave => $_) {
            $r = $respostas[(string) $chave] ?? null;
            $saida[$chave] = match (true) {
                $r instanceof Response && $r->successful() => $r->json() ?? [],
                $r instanceof \Throwable                   => $r,
                $r instanceof Response                     => new \RuntimeException("cotação respondeu HTTP {$r->status()}"),
                default                                    => new \RuntimeException('cotação sem resposta'),
            };
        }

        return $saida;
    }

    private function ehMe2(array $item): bool
    {
        return in_array($item['logistica'] ?? null, [LogisticaProduto::ME2, LogisticaProduto::ME2_FULL], true)
            && ! empty($item['pacote']) && isset($item['peso_faturado']);
    }

    private function vazio(): array
    {
        return [
            'valor' => null, 'origem' => null, 'preco_usado' => null, 'preco_origem' => null,
            'alerta_faixa' => false, 'instavel' => false, 'falhou' => false, 'cotado_em' => null, 'gratis_obrigatorio' => false,
        ];
    }

    /**
     * O preço pelo qual se VENDE (sem acréscimo), usado para escolher a faixa do frete.
     *
     * @return array{preco: float, origem: string}
     */
    private function precoDeCotacao(array $parametros, ?float $custo, ?float $frete): array
    {
        $referencia = ['preco' => (float) config('estrutura_produtos.frete.preco_referencia'), 'origem' => 'referencia'];

        if ($custo === null || $custo <= 0) {
            return $referencia;
        }

        $minimo = PrecificacaoEstrutura::preco(
            $custo, $frete, $parametros['comissao_classico'], $parametros['imposto'],
            $parametros['margem_contribuicao'], $parametros['lucro_liquido'], $parametros['acrescimo'],
        )['minimo'];

        return $minimo === null ? $referencia : ['preco' => (float) $minimo, 'origem' => 'custo'];
    }

    /** Ponto fixo pela tabela da ECF: o frete muda a faixa de preço, que muda o frete. */
    private function pelaTabela(array $parametros, array $item): array
    {
        $custo = $item['custo'] ?? null;
        $peso  = (float) $item['peso_faturado'];
        $max   = (int) config('estrutura_produtos.frete.max_recotacoes');

        $p = $this->precoDeCotacao($parametros, $custo, null);
        $f = TabelaFreteEcf::valor($peso, $p['preco']);
        $instavel = false;

        if ($p['origem'] === 'custo' && $f !== null) {
            $convergiu = false;
            for ($i = 0; $i < $max; $i++) {
                $novo = $this->precoDeCotacao($parametros, $custo, $f);
                $mesma = TabelaFreteEcf::faixas($peso, $novo['preco'])['coluna'] === TabelaFreteEcf::faixas($peso, $p['preco'])['coluna'];
                $p = $novo;
                if ($mesma) {
                    $convergiu = true;
                    break;
                }
                $f = TabelaFreteEcf::valor($peso, $p['preco']);
            }
            $instavel = ! $convergiu;
        }

        return [
            'valor' => $f, 'origem' => $f === null ? null : 'tabela_ecf', 'preco_usado' => $p['preco'], 'preco_origem' => $p['origem'],
            'alerta_faixa' => $instavel, 'instavel' => $instavel, 'falhou' => false, 'cotado_em' => null, 'gratis_obrigatorio' => false,
        ];
    }

    private function chaveFinal(Company $empresa, int|string $id, array $item, array $parametros): string
    {
        $assinatura = self::dimensions($item['pacote']).'|'.($item['custo'] ?? '').'|'.json_encode($parametros);

        return "estrutura:frete:v1:{$empresa->id}:var:{$id}:".md5($assinatura);
    }

    private function chaveCotacao(Company $empresa, string $dims, float $preco): string
    {
        return "estrutura:frete:v1:{$empresa->id}:{$dims}:".round($preco);
    }
}
