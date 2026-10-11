<?php

namespace App\Services\Portal\Estrutura;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\EstruturaPrecificacaoParametros;
use App\Services\Portal\Estrutura\Geracao\ConjuntoLogistico;
use App\Services\Portal\Estrutura\Produtos\FreteMe2Service;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Services\Portal\Estrutura\Produtos\ProdutoCustos;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Precificação do Mapeamento Estrutural: lê e grava o que o cliente informa
 * (custo, fretes, parâmetros) e entrega os preços já calculados pela
 * {@see PrecificacaoEstrutura}. ADR PORTAL-02.
 *
 * O resumo é sobre TODAS as ofertas da empresa; o detalhe, só das ofertas da
 * página — mesma divisão da `paginaOfertas` (ADR PORTAL-01 §Escala).
 *
 * Fase 167, D-10 — oferta ligada a produto usa o custo da variação (o custo
 * digitado aqui não vale para ela).
 *
 * Frete SUGERIDO (09/10/2026, revoga a D-19 da 167, "seguir o ML em tudo"): o tipo
 * sem frete digitado usa o frete do Mercado Envios do PRÓPRIO tipo — a cotação real
 * da conta em cache ou, sem ela, a tabela de custos do ML —, no ponto fixo com o
 * preço daquele tipo ({@see FreteMe2Service}). Oferta simples usa o pacote da
 * variação; composta, o pacote somado dos componentes ({@see ConjuntoLogistico},
 * provisório: "por enquanto deixa somando"). ME1, logística declarada fora do ME2 e
 * oferta sem medidas não têm sugestão — aí vale o frete do outro tipo ou a pendência
 * "sem frete". Carregar a página não faz requisição ao ML: a cotação real é o botão
 * "Cotar agora" ({@see self::cotarFretes}). Como o Publicador lê o preço daqui
 * (`DadosEfetivosService`), ele herda o mesmo frete.
 */
class EstruturaPrecificacaoService
{
    /** Logística declarada na Lista SKUs que tira a oferta do Mercado Envios: não há frete do ML a sugerir. */
    private const FORA_DO_ME2 = ['transportadora_me1', 'combinar'];

    public function __construct(private FreteMe2Service $frete) {}

    /**
     * @param  array<int>  $idsDaPagina
     * @return array{parametros: array<string, float>, padroes: array<string, float>, resumo: array{total: int, precificadas: int, sem_custo: int, sem_frete: int, impossivel: int}, por_oferta: array<int, array>}
     */
    public function pagina(Company $empresa, array $idsDaPagina): array
    {
        $base = $this->preparar($empresa);
        $daPagina = array_flip($idsDaPagina);

        // O frete sugerido: da página, com a cotação já guardada; do resto da empresa, só a
        // tabela — o resumo só precisa saber SE há sugestão, e a tabela sempre responde.
        ['pagina' => $itensDaPagina, 'resto' => $itensDoResto] = $this->itensDeFrete($empresa, $base, $daPagina);
        $sugeridos = ($itensDaPagina === [] ? [] : $this->frete->estimar($empresa, $itensDaPagina))
            + ($itensDoResto === [] ? [] : $this->frete->estimar($empresa, $itensDoResto, false));

        $resumo = ['total' => 0, 'precificadas' => 0, 'sem_custo' => 0, 'sem_frete' => 0, 'impossivel' => 0];
        $porOferta = [];

        foreach ($base['ofertas'] as $o) {
            $calculo = $this->calcular($base['contas'][$o['id']], $sugeridos[$o['id']] ?? null);

            $resumo['total']++;
            match ($calculo['pendencia']) {
                null => $resumo['precificadas']++,
                PrecificacaoEstrutura::PENDENCIA_SEM_FRETE => $resumo['sem_frete']++,
                PrecificacaoEstrutura::PENDENCIA_IMPOSSIVEL => $resumo['impossivel']++,
                default => $resumo['sem_custo']++,
            };

            if (isset($daPagina[$o['id']])) {
                $porOferta[$o['id']] = $calculo;
            }
        }

        return [
            'parametros' => $base['parametros'],
            'padroes'    => EstruturaPrecificacaoParametros::PADROES,
            'resumo'     => $resumo,
            'por_oferta' => $porOferta,
        ];
    }

    /**
     * "Cotar agora": a cotação real, na conta do cliente, do frete das ofertas da página
     * que não têm frete digitado — cada tipo no próprio preço. Vai para o cache; a página
     * relida em seguida já mostra "sugerido pela sua conta". Só GET no ML.
     *
     * @param  array<int>  $idsDaPagina
     * @return array{conectado: bool, total: int, cotados: int, pendentes: int, falhou: bool}
     */
    public function cotarFretes(Company $empresa, array $idsDaPagina): array
    {
        if (! AnunciosMercadoLivreService::conectado($empresa)) {
            return ['conectado' => false, 'total' => 0, 'cotados' => 0, 'pendentes' => 0, 'falhou' => false];
        }

        $base = $this->preparar($empresa);
        $itens = [];
        foreach ($this->itensDeFrete($empresa, $base, array_flip($idsDaPagina), comMe1: true)['pagina'] as $id => $item) {
            $linha = $base['contas'][$id]['linha'];
            // O digitado vence a sugestão: não se gasta cotação com ele.
            $tipos = array_values(array_filter(FreteMe2Service::TIPOS, fn ($t) => $linha?->{"frete_{$t}"} === null));
            if ($tipos !== []) {
                $itens[$id] = $item + ['tipos' => $tipos];
            }
        }

        if ($itens === []) {
            return ['conectado' => true, 'total' => 0, 'cotados' => 0, 'pendentes' => 0, 'falhou' => false];
        }

        $r = $this->frete->cotar($empresa, $itens);

        $total = 0;
        $cotados = 0;
        foreach ($itens as $id => $item) {
            foreach ($item['tipos'] as $tipo) {
                $origem = $r['fretes'][$id]['por_tipo'][$tipo]['origem'] ?? null;
                if ($origem !== null) {
                    $total++;
                    $cotados += $origem === 'api' ? 1 : 0;
                }
            }
        }

        return ['conectado' => $r['conectado'], 'total' => $total, 'cotados' => $cotados, 'pendentes' => $r['pendentes'], 'falhou' => $r['falhou']];
    }

    /** @param  array<string, mixed>  $dados  percentuais em ponto percentual */
    public function salvarParametros(Company $empresa, array $dados, AtorDoPortal $ator): void
    {
        $valores = $this->percentuais($dados, array_keys(EstruturaPrecificacaoParametros::PADROES), permiteNulo: false);

        DB::transaction(function () use ($empresa, $valores, $ator) {
            $linha = EstruturaPrecificacaoParametros::updateOrCreate(['company_id' => $empresa->id], $valores);

            RegistroEstrutura::registrar($ator, $empresa, $linha, 'precificacao_parametros',
                'Parâmetros de preço da empresa salvos', ['valores' => $valores]);
        });
    }

    /** @param  array<string, mixed>  $dados */
    public function salvarOferta(EstruturaOferta $oferta, array $dados, AtorDoPortal $ator): EstruturaPrecificacao
    {
        $ligada = $oferta->ligadaAProduto();
        if ($ligada && isset($dados['custo']) && $dados['custo'] !== '') {
            throw ValidationException::withMessages(['custo' => 'O custo desta oferta vem do Produtos. Altere lá.']);
        }

        $valores = [
            'custo'          => $this->dinheiro($dados['custo'] ?? null, 'custo'),
            'frete_classico' => $this->dinheiro($dados['frete_classico'] ?? null, 'frete_classico'),
            'frete_premium'  => $this->dinheiro($dados['frete_premium'] ?? null, 'frete_premium'),
            ...$this->percentuais($dados, EstruturaPrecificacao::EXCECOES, permiteNulo: true),
        ];
        if ($ligada) {
            // Não sobrescreve a coluna antiga: ela é ignorada na leitura.
            unset($valores['custo']);
        }

        return DB::transaction(function () use ($oferta, $valores, $ator) {
            $linha = EstruturaPrecificacao::updateOrCreate(['oferta_id' => $oferta->id], $valores);

            RegistroEstrutura::registrar($ator, $oferta->company, $linha, 'precificacao_oferta',
                "Precificação de {$oferta->sku} salva", ['valores' => $valores]);

            return $linha;
        });
    }

    /**
     * Só o frete, de um ou dos dois tipos, sem tocar no custo nem nas exceções (11/10/2026). É por aqui que
     * a equipe leva para cá o frete que o Mercado Livre respondeu na conferência (`FreteParaAPrecificacaoService`):
     * o `salvarOferta` grava a linha inteira e apagaria o que não viesse.
     *
     * @param  array<string, float|int|string>  $fretes  `classico` e/ou `premium` → valor em reais
     */
    public function salvarFrete(EstruturaOferta $oferta, array $fretes, AtorDoPortal $ator): EstruturaPrecificacao
    {
        $valores = [];
        foreach (FreteMe2Service::TIPOS as $tipo) {
            if (array_key_exists($tipo, $fretes) && $fretes[$tipo] !== null && $fretes[$tipo] !== '') {
                $valores["frete_{$tipo}"] = $this->dinheiro($fretes[$tipo], "frete_{$tipo}");
            }
        }
        if ($valores === []) {
            throw ValidationException::withMessages(['frete' => 'Informe o frete de pelo menos um tipo.']);
        }

        return DB::transaction(function () use ($oferta, $valores, $ator) {
            $linha = EstruturaPrecificacao::updateOrCreate(['oferta_id' => $oferta->id], $valores);

            RegistroEstrutura::registrar($ator, $oferta->company, $linha, 'precificacao_frete',
                "Frete de {$oferta->sku} trazido da conferência", ['valores' => $valores]);

            return $linha;
        });
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /**
     * O que toda conta precisa, da empresa inteira: ofertas, parâmetros, o que foi
     * digitado e, de cada oferta, custo e percentuais efetivos (o frete sugerido depende
     * dos dois).
     *
     * @return array{conjunto: EstruturaConjunto, ofertas: array, parametros: array<string, float>, contas: array<int, array>}
     */
    private function preparar(Company $empresa): array
    {
        $conjunto = EstruturaConjunto::daEmpresa($empresa);
        $parametros = EstruturaPrecificacaoParametros::daEmpresa($empresa->id);

        $linhas = EstruturaPrecificacao::query()
            ->join('estrutura_ofertas as o', 'o.id', '=', 'estrutura_precificacoes.oferta_id')
            ->where('o.company_id', $empresa->id)
            ->get(['estrutura_precificacoes.*'])
            ->keyBy('oferta_id');

        $custos = $linhas->map(fn (EstruturaPrecificacao $l) => $l->custo)->all();

        // Ligada ⇒ o custo é o do produto, inclusive null. O mesmo mapa alimenta os
        // combos via PrecificacaoEstrutura::custo, então a função pura não muda.
        $doProduto = ProdutoCustos::daEmpresa($empresa);
        foreach ($doProduto as $id => $c) {
            $custos[$id] = $c;
        }

        $ofertas = $conjunto->ofertas();
        $contas = [];
        foreach ($ofertas as $o) {
            $linha = $linhas[$o['id']] ?? null;
            $excecoes = array_combine(
                EstruturaPrecificacao::EXCECOES,
                array_map(fn ($k) => $linha?->{$k}, EstruturaPrecificacao::EXCECOES),
            );
            $ligada = array_key_exists($o['id'], $doProduto);
            $custo = PrecificacaoEstrutura::custo($ligada ? $doProduto[$o['id']] : $linha?->custo, $o['componentes'], $custos);
            if ($ligada && $custo['valor'] !== null) {
                $custo['origem'] = 'produto';
            }

            $contas[$o['id']] = [
                'oferta'   => $o,
                'linha'    => $linha,
                'excecoes' => $excecoes,
                'p'        => PrecificacaoEstrutura::parametros($parametros, $excecoes),
                'custo'    => $custo,
                'ligada'   => $ligada,
            ];
        }

        return ['conjunto' => $conjunto, 'ofertas' => $ofertas, 'parametros' => $parametros, 'contas' => $contas];
    }

    /**
     * Os itens do frete sugerido, no contrato do {@see FreteMe2Service}: só ofertas com
     * pacote no Mercado Envios (`$comMe1` inclui as ME1 com pacote, para a cotação real
     * reavaliar pelos limites da modalidade da conta).
     *
     * @return array{pagina: array<int, array>, resto: array<int, array>}
     */
    private function itensDeFrete(Company $empresa, array $base, array $daPagina, bool $comMe1 = false): array
    {
        $logisticas = $this->logisticas($empresa, $base['conjunto'], $base['ofertas']);
        $aceitas = [LogisticaProduto::ME2, LogisticaProduto::ME2_FULL, ...($comMe1 ? [LogisticaProduto::ME1] : [])];
        $saida = ['pagina' => [], 'resto' => []];

        foreach ($base['contas'] as $id => $conta) {
            $log = $logisticas[$id] ?? null;
            if ($log === null || $log['pacote'] === null || ! in_array($log['logistica'], $aceitas, true)
                || in_array($conta['oferta']['logistica'], self::FORA_DO_ME2, true)) {
                continue;
            }

            $saida[isset($daPagina[$id]) ? 'pagina' : 'resto'][$id] = [
                'pacote'        => $log['pacote'],
                'peso_faturado' => $log['peso_faturado'],
                'logistica'     => $log['logistica'],
                'custo'         => $conta['custo']['valor'],
                'parametros'    => $conta['p'],
            ];
        }

        return $saida;
    }

    /**
     * A logística de cada oferta, pelos limites da modalidade da conta (cache): a simples
     * pelo pacote da variação; a composta pelo pacote somado dos componentes. Uma
     * consulta para os volumes da empresa inteira.
     *
     * @return array<int, ?array> oferta_id => retorno de LogisticaProduto (null = sem variação nem componentes)
     */
    private function logisticas(Company $empresa, EstruturaConjunto $conjunto, array $ofertas): array
    {
        $modalidade = ModalidadeDeEnvio::emCache($empresa);
        $variacoes = array_values(array_unique(array_filter(array_column($ofertas, 'variacao_id'))));

        $volumes = [];
        foreach (array_chunk($variacoes, 500) as $lote) {
            DB::table('estrutura_produto_volumes as vol')
                ->join('estrutura_produto_variacoes as v', 'v.id', '=', 'vol.variacao_id')
                ->where('v.company_id', $empresa->id)
                ->whereIn('vol.variacao_id', $lote)
                ->orderBy('vol.variacao_id')->orderBy('vol.ordem')->orderBy('vol.id')
                ->get(['vol.variacao_id', 'vol.comprimento', 'vol.largura', 'vol.altura', 'vol.peso'])
                ->each(function ($x) use (&$volumes) {
                    $volumes[(int) $x->variacao_id][] = ['c' => (float) $x->comprimento, 'l' => (float) $x->largura, 'a' => (float) $x->altura, 'kg' => (float) $x->peso];
                });
        }

        $saida = [];
        foreach ($ofertas as $o) {
            if ($o['componentes'] !== []) {
                $itens = [];
                foreach ($o['componentes'] as $c) {
                    $comp = $conjunto->oferta($c['id']);
                    $itens[] = [
                        'produto_id'   => (int) $c['id'],
                        'produto_nome' => (string) ($comp['sku'] ?? ''),
                        'quantidade'   => (int) $c['quantidade'],
                        'volumes'      => $volumes[$comp['variacao_id'] ?? 0] ?? [],
                        'custo'        => null,
                    ];
                }
                $saida[$o['id']] = ConjuntoLogistico::avaliar($itens, $modalidade);
            } elseif ($o['variacao_id'] !== null) {
                $saida[$o['id']] = LogisticaProduto::daVolumes($volumes[$o['variacao_id']] ?? [], $modalidade);
            } else {
                $saida[$o['id']] = null;
            }
        }

        return $saida;
    }

    /**
     * A precificação de uma oferta, pronta para a tela.
     *
     * @param  array{oferta: array, linha: ?EstruturaPrecificacao, excecoes: array, p: array<string, float>, custo: array, ligada: bool}  $conta
     * @param  ?array  $frete  o frete sugerido da oferta (contrato do FreteMe2Service), ou null
     */
    private function calcular(array $conta, ?array $frete): array
    {
        $p = $conta['p'];
        $custo = $conta['custo'];
        $linha = $conta['linha'];

        $sugestoes = [];
        foreach (FreteMe2Service::TIPOS as $tipo) {
            $f = $frete['por_tipo'][$tipo] ?? null;
            $sugestoes[$tipo] = $f !== null && $f['valor'] !== null ? [
                'valor'              => (float) $f['valor'],
                'fonte'              => $f['origem'] === 'api' ? 'conta' : 'tabela',
                'cotado_em'          => $f['cotado_em'],
                'gratis_obrigatorio' => (bool) $f['gratis_obrigatorio'],
                'instavel'           => (bool) $f['instavel'],
            ] : null;
        }

        $fretes = PrecificacaoEstrutura::fretes($linha?->frete_classico, $linha?->frete_premium,
            array_map(fn ($s) => $s['valor'] ?? null, $sugestoes));

        $tipos = [];
        foreach ($fretes as $tipo => $f) {
            $tipos[$tipo] = [
                'comissao'       => $p["comissao_{$tipo}"],
                'frete'          => $f['valor'],
                'frete_origem'   => $f['origem'],
                'frete_sugerido' => $sugestoes[$tipo],
                ...PrecificacaoEstrutura::preco($custo['valor'], $f['valor'], $p["comissao_{$tipo}"], $p['imposto'], $p['margem_contribuicao'], $p['lucro_liquido'], $p['acrescimo']),
            ];
        }

        $pendencia = match (true) {
            $custo['valor'] === null => PrecificacaoEstrutura::PENDENCIA_SEM_CUSTO,
            $tipos['classico']['impossivel'] || $tipos['premium']['impossivel'] => PrecificacaoEstrutura::PENDENCIA_IMPOSSIVEL,
            $tipos['classico']['sem_frete'] || $tipos['premium']['sem_frete'] => PrecificacaoEstrutura::PENDENCIA_SEM_FRETE,
            default => null,
        };

        return [
            'custo'          => $custo,
            'do_produto'     => $conta['ligada'],
            'frete_classico' => $linha?->frete_classico,
            'frete_premium'  => $linha?->frete_premium,
            'excecoes'       => $conta['excecoes'],
            'classico'       => $tipos['classico'],
            'premium'        => $tipos['premium'],
            'pendencia'      => $pendencia,
        ];
    }

    /** Valor em reais: vazio vira null; negativo é recusado. */
    private function dinheiro(mixed $valor, string $campo): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (! is_numeric($valor) || (float) $valor < 0 || (float) $valor > 9_999_999_999) {
            throw ValidationException::withMessages([$campo => 'Informe um valor em reais, sem sinal de menos.']);
        }

        return round((float) $valor, 2);
    }

    /**
     * Percentuais em ponto percentual, de 0 a 99,99 — `0.19` quando se queria
     * 19% é o erro do onboarding (§2), e aqui ele vira um valor válido mas
     * visível: a tela sempre mostra "%" ao lado.
     *
     * @return array<string, ?float>
     */
    private function percentuais(array $dados, array $chaves, bool $permiteNulo): array
    {
        $r = [];
        foreach ($chaves as $k) {
            $v = $dados[$k] ?? null;
            if ($v === null || $v === '') {
                if (! $permiteNulo) {
                    throw ValidationException::withMessages([$k => 'Informe o percentual.']);
                }
                $r[$k] = null;
                continue;
            }
            if (! is_numeric($v) || (float) $v < 0 || (float) $v >= 100) {
                throw ValidationException::withMessages([$k => 'Informe um percentual entre 0 e 99,99.']);
            }
            $r[$k] = round((float) $v, 2);
        }

        return $r;
    }
}
