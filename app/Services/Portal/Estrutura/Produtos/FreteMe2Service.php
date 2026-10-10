<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaPrecificacaoParametros;
use App\Models\EstruturaPublicacao;
use App\Services\MercadoLivreService;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\PrecificacaoEstrutura;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Frete ME2 de cada variação (D-16), por TIPO de anúncio: estimativa imediata pela
 * tabela de custos do ML e, com a conta do cliente conectada, cotação real pelo mesmo
 * endpoint do Publicador (GET /users/{seller}/shipping_options/free).
 *
 * "Seguir o ML em tudo" (09/10/2026):
 *  - cada tipo é cotado no PRÓPRIO preço: Clássico (gold_special) no preço do Clássico,
 *    Premium (gold_pro) no do Premium — o custo depende da faixa de preço, e o Premium,
 *    mais caro, pode cair em outra faixa;
 *  - `free_shipping` pelo preço (`frete.gratis_obrigatorio_a_partir`) e `logistic_type`
 *    pela modalidade de envio da conta ({@see ModalidadeDeEnvio}), que também decide os
 *    limites do ME2 de cada item;
 *  - teto do preço abaixo de R$ 19 ({@see TabelaFreteEcf::comTeto}).
 *
 * O frete muda a faixa de preço e o preço muda o frete: o valor de cada tipo é o PONTO
 * FIXO entre os dois ({@see self::pontoFixo}). Em cada faixa vale a cotação em cache,
 * quando há, e a tabela quando não há — a tabela é o palpite que diz qual faixa cotar.
 * A cotação fica em cache por PACOTE, com uma entrada por modalidade · tipo · faixa de
 * preço: 78,99 e 79 são faixas diferentes (a chave antiga, `round($preco)`, juntava os dois).
 *
 * D-19 revogada (09/10/2026, ADR PORTAL-02): este frete é SUGERIDO na Precificação e entra
 * no preço quando o cliente não digitou outro. Nada daqui grava em estrutura_precificacoes.
 * Só GET na conta do cliente.
 *
 * Por que não o `ClienteMlPublicador` em laço: ele dorme (até 16 s por tentativa)
 * dentro da requisição web. Nem o `MercadoLivreService::getMany`: ele refaz em SÉRIE
 * cada pedido que falhou no pool, com retry de 429 (`sleep` de até 8 s, 3 vezes) e
 * timeout de 30 s — com o ML degradado, um POST /fretes passava de 10 minutos
 * (BE-WR-06). Aqui o pool é próprio, com timeout curto e sem segunda tentativa: o
 * que falha cai na tabela marcado `falhou`, e a pessoa consulta de novo.
 *
 * Ressalva A2 da pesquisa: em algumas categorias o ML pode ignorar as dimensões
 * enviadas, por isso o rótulo é sempre "estimado" na tela.
 *
 * Contrato de item (chave = id da variação ou da oferta):
 *   ['pacote' => ?array, 'peso_faturado' => ?float, 'logistica' => string, 'custo' => ?float,
 *    'parametros' => ?array (percentuais da oferta; sem eles, os da empresa),
 *    'tipos' => ?list<'classico'|'premium'> (quais a cotação real pergunta ao ML; padrão, os dois)]
 * Contrato de frete devolvido: o Clássico no topo (o que Produtos e Sugestões mostram) e os
 * dois tipos em `por_tipo`, na mesma forma:
 *   ['valor', 'origem' => 'api'|'tabela_ecf'|null, 'preco_usado', 'preco_origem' => 'custo'|'referencia'|null,
 *    'alerta_faixa', 'instavel', 'falhou', 'cotado_em', 'gratis_obrigatorio',
 *    'por_tipo' => ['classico' => [...], 'premium' => [...]]]
 */
class FreteMe2Service
{
    public const CLASSICO = 'classico';
    public const PREMIUM  = 'premium';
    public const TIPOS    = [self::CLASSICO, self::PREMIUM];

    private const API_BASE = 'https://api.mercadolibre.com';

    /** Segundos: resposta e conexão de cada cotação (BE-WR-06). */
    private const TIMEOUT          = 8;
    private const TIMEOUT_CONEXAO  = 3;

    /**
     * Voltas do ponto fixo sobre o que já se sabe (cache e tabela, sem requisição). Pela
     * tabela ele para em poucas voltas (o custo cresce com a faixa); a margem é para o teto
     * abaixo de R$ 19, onde o frete acompanha o preço e chega aos poucos.
     */
    private const VOLTAS = 30;

    /** Diferença de frete (R$) abaixo da qual o ponto fixo parou de andar. */
    private const PRECISAO = 0.005;

    public function __construct(private MercadoLivreService $ml) {}

    /**
     * Dimensões no formato do ML: AxLxC,peso em gramas (ordem A×L×C, não C×L×A). O ML só
     * aceita inteiros e recusa lado 0 com 400: lado abaixo de 0,5 cm vai como 1 (BE-IN-03).
     */
    public static function dimensions(array $pacote): string
    {
        $inteiro = fn (float|int $n) => max(1, (int) round($n));

        return sprintf('%dx%dx%d,%d', $inteiro($pacote['a']), $inteiro($pacote['l']), $inteiro($pacote['c']), $inteiro($pacote['peso_real'] * 1000));
    }

    /**
     * Estimativa imediata, sem nenhuma requisição ao ML: em cada faixa, a cotação já
     * guardada e, sem ela, a tabela. A lista inteira lê o cache numa consulta só
     * (BE-WR-07). `$comCache = false` é só a tabela, sem ler nada — para quem só precisa
     * saber SE há frete (o resumo da Precificação sobre a empresa inteira).
     *
     * @return array<int|string, array>
     */
    public function estimar(Company $empresa, array $itens, bool $comCache = true): array
    {
        $parametros = EstruturaPrecificacaoParametros::daEmpresa($empresa->id);
        ['modalidade' => $modalidade, 'mapas' => $mapas] = $comCache
            ? $this->lerCache($empresa, $itens)
            : ['modalidade' => null, 'mapas' => []];

        $saida = [];
        foreach ($itens as $id => $item) {
            $saida[$id] = $this->ehMe2($item)
                ? $this->resultado($item, $parametros, $mapas[self::dimensions($item['pacote'])] ?? [], $modalidade)
                : $this->vazio();
        }

        return $saida;
    }

    /**
     * Cotação real pela conta do cliente: cada tipo, na faixa em que o ponto fixo cai.
     * Uma rodada pergunta, em paralelo, as faixas que ainda não estão em cache; quando a
     * cotação leva o preço para outra faixa, a rodada seguinte pergunta essa (até
     * `max_recotacoes`). O que não coube no lote fica `pendente` — a tela pede de novo.
     *
     * @return array{fretes: array, pendentes: int, conectado: bool, falhou: bool}
     */
    public function cotar(Company $empresa, array $itens): array
    {
        if (! AnunciosMercadoLivreService::conectado($empresa)) {
            return ['fretes' => $this->estimar($empresa, $itens), 'pendentes' => 0, 'conectado' => false, 'falhou' => false];
        }

        // Nada que caiba no Mercado Envios em modalidade nenhuma: nem a preferência da conta é lida.
        if (! $this->algumCabeNoMe2($itens)) {
            return ['fretes' => $this->estimar($empresa, $itens), 'pendentes' => 0, 'conectado' => true, 'falhou' => false];
        }

        $cfg        = config('estrutura_produtos.frete');
        $parametros = EstruturaPrecificacaoParametros::daEmpresa($empresa->id);
        $sellerId   = (string) $empresa->mlToken->ml_user_id;
        $orcamento  = (int) $cfg['max_por_requisicao'];

        // O token só é pedido (e renovado) quando alguma coisa vai mesmo ao ML.
        $token = null;
        $comToken = function () use (&$token, $empresa) {
            $token ??= $this->ml->ensureValidToken($empresa);
            if (! $token) {
                throw new \RuntimeException("empresa {$empresa->id} sem token válido do Mercado Livre");
            }

            return $token;
        };

        try {
            $modalidade = $this->modalidadeDaConta($empresa, $sellerId, $comToken);
        } catch (\RuntimeException $e) {
            // Sem token, ou o ML sem responder: nada de cotar no escuro — tudo na estimativa.
            Log::warning("[Estrutura Produtos] cotação de frete sem a modalidade da conta empresa {$empresa->id}: ".$e->getMessage());

            return ['fretes' => $this->comFalha($this->estimar($empresa, $itens)), 'pendentes' => 0, 'conectado' => true, 'falhou' => true];
        }

        // A logística segue a modalidade da conta: o que não cabe nos Correios pode caber na Coleta.
        foreach ($itens as $id => $item) {
            if (! empty($item['pacote'])) {
                $av = LogisticaProduto::avaliar($item['pacote'], null, $modalidade);
                $itens[$id]['logistica']     = $av['logistica'];
                $itens[$id]['peso_faturado'] = $av['peso_faturado'];
            }
        }

        $mapas  = $this->lerCache($empresa, $itens)['mapas'];
        $marcas = [];   // [id][tipo] => ['falhou' => true] | ['pendente' => true] | ['aberto' => true]
        $sujos  = [];   // pacotes com cotação nova, para gravar
        $falhou = false;

        $abertos = [];
        foreach ($itens as $id => $item) {
            if ($this->ehMe2($item)) {
                foreach (array_intersect(self::TIPOS, (array) ($item['tipos'] ?? self::TIPOS)) as $tipo) {
                    $abertos[] = [$id, $tipo];
                }
            }
        }

        try {
            for ($rodada = 0; $rodada <= (int) $cfg['max_recotacoes'] && $abertos !== []; $rodada++) {
                $pedidos = [];
                $esperando = [];

                foreach ($abertos as [$id, $tipo]) {
                    $item = $itens[$id];
                    $dims = self::dimensions($item['pacote']);
                    $r = $this->pontoFixo($item, $parametros, $tipo, fn (float $p) => $this->freteNaFaixa($item, $tipo, $p, $mapas[$dims] ?? [], $modalidade));

                    // O preço parou numa faixa já cotada (ou só anda entre faixas cotadas): fechado.
                    if ($r['cotar_em'] === null) {
                        continue;
                    }

                    $coluna = TabelaFreteEcf::coluna($r['cotar_em']);
                    $chave  = "{$dims}|{$tipo}|{$coluna}";
                    if (! isset($pedidos[$chave])) {
                        if ($orcamento <= 0) {
                            // Não coube nesta chamada: a tela pede de novo.
                            $marcas[$id][$tipo] = ['pendente' => true];
                            continue;
                        }
                        $orcamento--;
                        $pedidos[$chave] = ['dims' => $dims, 'tipo' => $tipo, 'coluna' => $coluna, 'preco' => $r['cotar_em']];
                    }
                    $esperando[] = [$id, $tipo, $chave];
                }

                if ($pedidos === []) {
                    break;
                }

                $respostas = $this->cotarEmParalelo($comToken(), $sellerId, $pedidos, $modalidade);
                $falhas = [];
                foreach ($respostas as $chave => $resp) {
                    $cot = is_array($resp) ? $this->daResposta($resp) : null;
                    if ($cot === null) {
                        $falhou = true;
                        $falhas[$chave] = true;
                        if ($resp instanceof \Throwable) {
                            Log::warning("[Estrutura Produtos] cotação de frete falhou empresa {$empresa->id}: ".$resp->getMessage());
                        }
                        continue;
                    }
                    $p = $pedidos[$chave];
                    $mapas[$p['dims']][$this->chaveNoMapa($modalidade, $p['tipo'], $p['coluna'])] = $cot + ['preco' => round($p['preco'], 2)];
                    $sujos[$p['dims']] = true;
                }

                $abertos = [];
                foreach ($esperando as [$id, $tipo, $chave]) {
                    if (isset($falhas[$chave])) {
                        $marcas[$id][$tipo] = ['falhou' => true];
                    } else {
                        $abertos[] = [$id, $tipo];
                        $marcas[$id][$tipo] = ['aberto' => true];
                    }
                }
            }
        } catch (\RuntimeException $e) {
            // Sem token válido: o que não foi cotado cai na estimativa.
            Log::warning("[Estrutura Produtos] cotação de frete sem token válido empresa {$empresa->id}: ".$e->getMessage());
            $falhou = true;
            foreach ($abertos as [$id, $tipo]) {
                $marcas[$id][$tipo] = ['falhou' => true];
            }
        }

        $this->gravarCotacoes($empresa, $mapas, $sujos);

        $fretes = [];
        $pendentes = 0;
        foreach ($itens as $id => $item) {
            if (! $this->ehMe2($item)) {
                $fretes[$id] = $this->vazio();
                continue;
            }
            $fretes[$id] = $this->resultado($item, $parametros, $mapas[self::dimensions($item['pacote'])] ?? [], $modalidade, $marcas[$id] ?? []);
            foreach (self::TIPOS as $tipo) {
                if (! empty($marcas[$id][$tipo]['pendente']) && $fretes[$id]['por_tipo'][$tipo]['origem'] !== 'api') {
                    $pendentes++;
                    break;
                }
            }
        }

        return ['fretes' => $fretes, 'pendentes' => $pendentes, 'conectado' => true, 'falhou' => $falhou];
    }

    // ═══ Internos ═══

    /**
     * Os dois tipos de um item, cada um no seu ponto fixo. `$marcas` traz o que a cotação
     * real viu de cada tipo (falhou, pendente, ainda aberto depois das re-cotações).
     */
    private function resultado(array $item, array $parametrosEmpresa, array $mapa, ?string $modalidade, array $marcas = []): array
    {
        $porTipo = [];
        foreach (self::TIPOS as $tipo) {
            $r = $this->pontoFixo($item, $parametrosEmpresa, $tipo, fn (float $p) => $this->freteNaFaixa($item, $tipo, $p, $mapa, $modalidade));
            $porTipo[$tipo] = $this->forma($r, $marcas[$tipo] ?? []);
        }

        return $porTipo[self::CLASSICO] + ['por_tipo' => $porTipo];
    }

    /**
     * Ponto fixo frete ↔ preço de um tipo: parte do preço sem frete, põe o frete daquela
     * faixa, recalcula o preço e repete até o frete parar. Sem custo (ou com a conta
     * impossível), vale o preço de referência, sem iterar.
     *
     * `cotar_em` é o preço da última volta cujo frete veio da TABELA — a faixa que a
     * cotação real ainda precisa perguntar (null quando o caminho todo já é da API).
     * `gratis_mudou`: a API disse "frete grátis obrigatório" numa faixa do caminho e o
     * contrário em outra — o preço está na beira da regra.
     *
     * @param  callable(float): array{valor: ?float, origem: ?string, gratis: bool, cotado_em: ?string}  $frete
     * @return array{frete: array, preco: float, preco_origem: string, instavel: bool, gratis_mudou: bool, cotar_em: ?float}
     */
    private function pontoFixo(array $item, array $parametrosEmpresa, string $tipo, callable $frete): array
    {
        $p     = $item['parametros'] ?? $parametrosEmpresa;
        $custo = isset($item['custo']) ? (float) $item['custo'] : null;

        $precoCom = fn (?float $f) => $custo === null || $custo <= 0 ? null : PrecificacaoEstrutura::preco(
            $custo, $f, (float) $p["comissao_{$tipo}"], (float) $p['imposto'],
            (float) $p['margem_contribuicao'], (float) $p['lucro_liquido'], (float) $p['acrescimo'],
        )['minimo'];

        $preco = $precoCom(null);
        if ($preco === null) {
            $referencia = (float) config('estrutura_produtos.frete.preco_referencia');
            $f = $frete($referencia);

            return [
                'frete' => $f, 'preco' => $referencia, 'preco_origem' => 'referencia', 'instavel' => false,
                'gratis_mudou' => false, 'cotar_em' => $f['origem'] === 'api' ? null : $referencia,
            ];
        }

        $f = $frete($preco);
        $convergiu = false;
        $gratisMudou = false;
        $gratisDaApi = $f['origem'] === 'api' ? $f['gratis'] : null;
        $cotarEm = $f['origem'] === 'api' ? null : $preco;

        for ($i = 0; $i < self::VOLTAS; $i++) {
            $novo = $precoCom($f['valor']);
            $g = $frete($novo);
            if ($g['origem'] === 'api') {
                $gratisMudou = $gratisMudou || ($gratisDaApi !== null && $gratisDaApi !== $g['gratis']);
                $gratisDaApi = $g['gratis'];
            } else {
                $cotarEm = $novo;
            }
            $parou = abs(($g['valor'] ?? 0.0) - ($f['valor'] ?? 0.0)) < self::PRECISAO;
            $preco = $novo;
            $f = $g;
            if ($parou) {
                $convergiu = true;
                break;
            }
        }

        return [
            'frete' => $f, 'preco' => $preco, 'preco_origem' => 'custo', 'instavel' => ! $convergiu,
            'gratis_mudou' => $gratisMudou,
            // Parou numa faixa cotada: nada a perguntar. Senão, a faixa da última volta pela tabela.
            'cotar_em' => $convergiu && $f['origem'] === 'api' ? null : $cotarEm,
        ];
    }

    /**
     * O frete de um tipo num preço: a cotação guardada daquela faixa, ou a tabela.
     *
     * @return array{valor: ?float, origem: ?string, gratis: bool, cotado_em: ?string}
     */
    private function freteNaFaixa(array $item, string $tipo, float $preco, array $mapa, ?string $modalidade): array
    {
        $cotado = $this->valida($mapa[$this->chaveNoMapa($modalidade, $tipo, TabelaFreteEcf::coluna($preco))] ?? null);
        if ($cotado !== null) {
            return ['valor' => TabelaFreteEcf::comTeto((float) $cotado['valor'], $preco), 'origem' => 'api', 'gratis' => (bool) $cotado['gratis'], 'cotado_em' => $cotado['cotado_em']];
        }

        $valor = TabelaFreteEcf::valor((float) $item['peso_faturado'], $preco);

        return ['valor' => $valor, 'origem' => $valor === null ? null : 'tabela_ecf', 'gratis' => TabelaFreteEcf::gratisObrigatorio($preco), 'cotado_em' => null];
    }

    /** O ponto fixo de um tipo no contrato de frete. */
    private function forma(array $r, array $marca): array
    {
        $f = $r['frete'];
        $daApi = $f['origem'] === 'api';
        $instavel = $r['instavel'] || (! empty($marca['aberto']) && ! $daApi);

        return [
            'valor'              => $f['valor'],
            'origem'             => $f['origem'],
            'preco_usado'        => round($r['preco'], 2),
            'preco_origem'       => $r['preco_origem'],
            'alerta_faixa'       => $instavel || $r['gratis_mudou'],
            'instavel'           => $instavel,
            'falhou'             => ! empty($marca['falhou']) && ! $daApi,
            'cotado_em'          => $f['cotado_em'],
            'gratis_obrigatorio' => (bool) $f['gratis'],
        ];
    }

    /** Marca `falhou` em tudo que não veio da API (a cotação nem começou). */
    private function comFalha(array $fretes): array
    {
        foreach ($fretes as $id => $f) {
            if (! isset($f['por_tipo'])) {
                continue;
            }
            foreach (self::TIPOS as $tipo) {
                $t = $f['por_tipo'][$tipo];
                $f['por_tipo'][$tipo]['falhou'] = $t['origem'] !== null && $t['origem'] !== 'api';
            }
            $fretes[$id] = $f['por_tipo'][self::CLASSICO] + ['por_tipo' => $f['por_tipo']];
        }

        return $fretes;
    }

    /**
     * A modalidade de envio da conta: do cache ou, uma vez, de GET shipping_preferences.
     * O ML sem responder (rede, 429, 5xx) interrompe a cotação — as cotações cairiam do
     * mesmo jeito, e cada uma esperaria o seu timeout. Outro 4xx vale como "sem modalidade
     * reconhecida" (limites dos Correios) por uma hora.
     *
     * @param  callable(): object  $comToken
     *
     * @throws \RuntimeException
     */
    private function modalidadeDaConta(Company $empresa, string $sellerId, callable $comToken): ?string
    {
        $guardado = Cache::get(ModalidadeDeEnvio::chave((int) $empresa->id));
        if (is_array($guardado)) {
            return ModalidadeDeEnvio::doGuardado($guardado);
        }

        $token = $comToken();
        try {
            $r = Http::withToken($token->access_token)
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::TIMEOUT_CONEXAO)
                ->get(self::API_BASE."/users/{$sellerId}/shipping_preferences");
        } catch (\Throwable $e) {
            throw new \RuntimeException('preferência de envio sem resposta: '.$e->getMessage());
        }

        if ($r->status() === 429 || $r->serverError()) {
            throw new \RuntimeException("preferência de envio respondeu HTTP {$r->status()}");
        }

        $tipo = $r->successful() ? ModalidadeDeEnvio::daPreferencia((array) $r->json()) : null;
        ModalidadeDeEnvio::guardar((int) $empresa->id, $tipo, $r->successful() ? null : 1);

        return $tipo;
    }

    /**
     * Os GETs de uma rodada em paralelo, com timeout curto e SEM refazer o que falhou
     * (nem em série, nem com `sleep`): 429, 5xx, timeout e rede voltam como a exceção
     * daquele pedido, e `cotar()` usa a tabela com `falhou`.
     *
     * @param  array<string, array{dims: string, tipo: string, coluna: int, preco: float}>  $pedidos
     * @return array<string, array|\Throwable>  chave → corpo da resposta, ou a falha daquele pedido
     */
    private function cotarEmParalelo(object $token, string $sellerId, array $pedidos, ?string $modalidade): array
    {
        $respostas = Http::pool(function (Pool $pool) use ($pedidos, $token, $sellerId, $modalidade) {
            foreach ($pedidos as $chave => $pedido) {
                $pool->as((string) $chave)
                    ->withToken($token->access_token)
                    ->timeout(self::TIMEOUT)
                    ->connectTimeout(self::TIMEOUT_CONEXAO)
                    ->get(self::API_BASE."/users/{$sellerId}/shipping_options/free", $this->consulta($pedido, $modalidade));
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

    /** A query da cotação de um tipo, no preço dele. */
    private function consulta(array $pedido, ?string $modalidade): array
    {
        return [
            'item_price'      => round($pedido['preco'], 2),
            'listing_type_id' => EstruturaPublicacao::LISTING_TYPES[$pedido['tipo']],
            'mode'            => 'me2',
            'condition'       => 'new',
            'logistic_type'   => $this->logisticType($modalidade),
            'free_shipping'   => TabelaFreteEcf::gratisObrigatorio($pedido['preco']) ? 'true' : 'false',
            'dimensions'      => $pedido['dims'],
            'verbose'         => 'true',
        ];
    }

    /** O custo e o aviso de frete grátis de uma resposta; null = resposta sem custo (vale como falha). */
    private function daResposta(array $corpo): ?array
    {
        $cobertura = $corpo['coverage']['all_country'] ?? null;
        if (! is_array($cobertura) || ! isset($cobertura['list_cost']) || ! is_numeric($cobertura['list_cost'])) {
            return null;
        }

        return [
            'valor'     => (float) $cobertura['list_cost'],
            // O aviso vem da resposta da API, nunca de um limite no código (learnings publicador-ml.md §10).
            'gratis'    => ($cobertura['discount']['type'] ?? null) === 'mandatory' && empty($cobertura['free_shipping_by_meli']),
            'cotado_em' => now()->toIso8601String(),
        ];
    }

    /**
     * As cotações guardadas de cada pacote dos itens ME2 e a modalidade da conta, numa
     * leitura só (`Cache::many`): com o store `database`, uma leitura por variação era
     * uma consulta por variação em toda lista de 100 produtos (BE-WR-07).
     *
     * @return array{modalidade: ?string, mapas: array<string, array>}
     */
    private function lerCache(Company $empresa, array $itens): array
    {
        $chaves = [];
        foreach ($itens as $item) {
            if ($this->ehMe2($item)) {
                $dims = self::dimensions($item['pacote']);
                $chaves[$dims] = $this->chaveDoPacote($empresa, $dims);
            }
        }
        if ($chaves === []) {
            return ['modalidade' => null, 'mapas' => []];
        }

        $chaveModalidade = ModalidadeDeEnvio::chave((int) $empresa->id);
        $lidos = Cache::many([$chaveModalidade, ...array_values($chaves)]);

        $mapas = [];
        foreach ($chaves as $dims => $chave) {
            $mapas[$dims] = is_array($lidos[$chave] ?? null) ? $lidos[$chave] : [];
        }

        return ['modalidade' => ModalidadeDeEnvio::doGuardado($lidos[$chaveModalidade] ?? null), 'mapas' => $mapas];
    }

    /** Uma escrita por pacote com cotação nova, sem as entradas vencidas. */
    private function gravarCotacoes(Company $empresa, array $mapas, array $sujos): void
    {
        $horas = (int) config('estrutura_produtos.frete.cache_horas');
        foreach (array_keys($sujos) as $dims) {
            $vivas = array_filter($mapas[$dims] ?? [], fn ($e) => $this->valida($e) !== null);
            Cache::put($this->chaveDoPacote($empresa, (string) $dims), $vivas, now()->addHours($horas));
        }
    }

    /** A entrada do mapa, se ainda vale (cotada há menos de `cache_horas`). */
    private function valida(mixed $entrada): ?array
    {
        if (! is_array($entrada) || ! isset($entrada['valor'], $entrada['cotado_em']) || ! is_numeric($entrada['valor'])) {
            return null;
        }
        $limite = now()->subHours((int) config('estrutura_produtos.frete.cache_horas'));

        return \Illuminate\Support\Carbon::parse($entrada['cotado_em'])->greaterThan($limite) ? $entrada : null;
    }

    /** Algum item com pacote cabe no ME2 de alguma modalidade (a da conta ainda pode não ter sido lida)? */
    private function algumCabeNoMe2(array $itens): bool
    {
        foreach ($itens as $item) {
            if (empty($item['pacote'])) {
                continue;
            }
            foreach (array_keys((array) config('estrutura_produtos.modalidades')) as $modalidade) {
                if (in_array(LogisticaProduto::avaliar($item['pacote'], null, $modalidade)['logistica'], [LogisticaProduto::ME2, LogisticaProduto::ME2_FULL], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function ehMe2(array $item): bool
    {
        return in_array($item['logistica'] ?? null, [LogisticaProduto::ME2, LogisticaProduto::ME2_FULL], true)
            && ! empty($item['pacote']) && isset($item['peso_faturado']);
    }

    private function vazio(): array
    {
        $base = [
            'valor' => null, 'origem' => null, 'preco_usado' => null, 'preco_origem' => null,
            'alerta_faixa' => false, 'instavel' => false, 'falhou' => false, 'cotado_em' => null, 'gratis_obrigatorio' => false,
        ];

        return $base + ['por_tipo' => [self::CLASSICO => $base, self::PREMIUM => $base]];
    }

    /** O `logistic_type` que vai na cotação: o da conta, ou o padrão (Correios). */
    private function logisticType(?string $modalidade): string
    {
        return $modalidade ?? (string) config('estrutura_produtos.modalidade_padrao');
    }

    private function chaveDoPacote(Company $empresa, string $dims): string
    {
        return "estrutura:frete:v2:{$empresa->id}:{$dims}";
    }

    /** Dentro do pacote: modalidade · tipo de anúncio · faixa de preço (coluna da tabela). */
    private function chaveNoMapa(?string $modalidade, string $tipo, int $coluna): string
    {
        return $this->logisticType($modalidade).'|'.EstruturaPublicacao::LISTING_TYPES[$tipo]."|{$coluna}";
    }
}
