<?php

namespace App\Services\Portal\Estrutura;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\DB;

/**
 * Colar anúncios — o passo 2 da aula ("Cole aqui o SKU e o tipo de cada
 * anúncio que você já tem no Mercado Livre"), com a reconciliação que a
 * planilha não tinha.
 *
 * ### Duas etapas, e a segunda não confia na primeira
 * {@see self::plano()} só lê: devolve o que VAI acontecer (novos, atualizados,
 * aguardando oferta, com erro e — no modo substituir — removidos). A
 * confirmação ({@see self::aplicar()}) recebe o MESMO texto e refaz o plano do
 * zero antes de gravar: o navegador não manda o que gravar, só o que foi
 * colado. Entre a prévia e o confirmar alguém pode ter criado uma oferta.
 *
 * ### A chave de cada linha (ADR §Identidade do anúncio)
 * - Com MLB: `(empresa, MLB)`. Já existe como anúncio → atualiza; na espera →
 *   atualiza lá, ou sai dela se agora casou com oferta.
 * - Sem MLB: `(oferta, tipo)` entre os anúncios sem MLB; se o SKU não casa,
 *   `(SKU, tipo)` entre as linhas sem MLB da espera.
 *
 * ### Qual oferta
 * O SKU colado, normalizado (sem caixa, sem espaço nas pontas), casado contra
 * as ofertas da empresa. Uma → é ela. Nenhuma → espera `sem_oferta`. Várias →
 * espera `sku_repetido`. Sem SKU (veio só o MLB) → espera `sem_sku`.
 *
 * Um anúncio JÁ cadastrado cujo SKU colado não casa mantém o vínculo que tem —
 * não é arrancado da oferta para a espera. Se casa com OUTRA oferta, única,
 * muda para ela: é o que a planilha faria (lá o vínculo era o próprio SKU).
 *
 * ### Substituir
 * Remove o que não está na colagem — anúncios e espera. É deliberado: um
 * anúncio que a agenda cadastrou e que está de fato no ar vem na exportação
 * com o mesmo MLB e é ATUALIZADO; só some o que não veio. A prévia lista quem
 * sai antes de confirmar.
 */
class ColagemAnunciosService
{
    public const MODO_ACRESCENTAR = 'acrescentar';
    public const MODO_SUBSTITUIR  = 'substituir';

    /** Quantos itens de cada grupo a prévia detalha — os totais vão sempre inteiros. */
    private const DETALHE_MAXIMO = 200;

    public function __construct(
        private LeitorColagemAnuncios $leitor,
        private EstruturaAnuncioService $anuncios,
    ) {
    }

    /**
     * A prévia. Não grava nada.
     *
     * @return array{erro_geral: ?string, cabecalho: bool, colunas: array, totais: array<string, int>, grupos: array<string, array>}
     */
    public function previa(Company $empresa, string $texto, string $modo, ?int $maxLinhas = null): array
    {
        $plano = $this->plano($empresa, $texto, $modo, $maxLinhas);

        $grupos = [];
        foreach (['novos', 'atualizados', 'espera', 'erros', 'removidos'] as $g) {
            $grupos[$g] = array_slice($plano[$g], 0, self::DETALHE_MAXIMO);
        }

        return [
            'erro_geral' => $plano['erro_geral'],
            'cabecalho'  => $plano['cabecalho'],
            'colunas'    => $plano['colunas'],
            'totais'     => array_map('count', array_intersect_key($plano, array_flip(['novos', 'atualizados', 'espera', 'erros', 'removidos']))),
            'grupos'     => $grupos,
        ];
    }

    /**
     * Grava o plano, refeito agora a partir do texto.
     *
     * @return array<string, int> os totais do que foi feito
     */
    public function aplicar(Company $empresa, string $texto, string $modo, AtorDoPortal $ator, ?int $maxLinhas = null): array
    {
        return DB::transaction(function () use ($empresa, $texto, $modo, $ator, $maxLinhas) {
            $plano = $this->plano($empresa, $texto, $modo, $maxLinhas);

            if ($plano['erro_geral'] !== null) {
                return ['erro_geral' => $plano['erro_geral']];
            }

            foreach ([...$plano['novos'], ...$plano['atualizados'], ...$plano['espera']] as $item) {
                $this->executar($empresa, $item);
            }

            foreach ($plano['removidos'] as $r) {
                if ($r['origem'] === 'anuncio') {
                    EstruturaAnuncio::whereKey($r['id'])->delete();
                } else {
                    EstruturaAnuncioEspera::whereKey($r['id'])->delete();
                }
            }

            $totais = array_map('count', array_intersect_key($plano, array_flip(['novos', 'atualizados', 'espera', 'erros', 'removidos'])));

            RegistroEstrutura::registrar($ator, $empresa, null, 'anuncios_colados',
                "Colagem de anúncios ({$modo}): {$totais['novos']} novos, {$totais['atualizados']} atualizados, "
                ."{$totais['espera']} aguardando oferta, {$totais['removidos']} removidos, {$totais['erros']} com erro",
                ['modo' => $modo, 'totais' => $totais]);

            return $totais;
        });
    }

    /**
     * O plano completo. Cada item de `novos`/`atualizados`/`espera` carrega a
     * ação a executar; `removidos` só existe no modo substituir.
     */
    private function plano(Company $empresa, string $texto, string $modo, ?int $maxLinhas = null): array
    {
        $leitura = $this->leitor->ler($texto, $maxLinhas);

        $plano = [
            'erro_geral'  => $leitura['erro_geral'],
            'cabecalho'   => $leitura['cabecalho'],
            'colunas'     => $leitura['colunas'],
            'novos'       => [],
            'atualizados' => [],
            'espera'      => [],
            'erros'       => $leitura['erros'],
            'removidos'   => [],
        ];

        if ($leitura['erro_geral'] !== null) {
            return $plano;
        }

        // ── O que já existe, indexado pelas duas chaves ──────────────────
        $ofertas = EstruturaOferta::where('company_id', $empresa->id)->get(['id', 'sku']);
        $ofertasPorSku = $ofertas->groupBy(fn ($o) => EstruturaOferta::normalizarSku($o->sku));
        $skuDaOferta = $ofertas->pluck('sku', 'id');

        $anuncios = EstruturaAnuncio::query()
            ->join('estrutura_ofertas as o', 'o.id', '=', 'estrutura_anuncios.oferta_id')
            ->where('o.company_id', $empresa->id)
            ->get(['estrutura_anuncios.*']);
        $anuncioPorMlb = $anuncios->whereNotNull('codigo_mlb')->keyBy('codigo_mlb');
        $anuncioSemMlb = $anuncios->whereNull('codigo_mlb')->keyBy(fn ($a) => $a->oferta_id.'|'.$a->tipo);

        $espera = EstruturaAnuncioEspera::where('company_id', $empresa->id)->get();
        $esperaPorMlb = $espera->whereNotNull('codigo_mlb')->keyBy('codigo_mlb');
        $esperaSemMlb = $espera->whereNull('codigo_mlb')
            ->keyBy(fn ($l) => EstruturaOferta::normalizarSku($l->sku_colado).'|'.$l->tipo);

        $tocadosAnuncio = [];
        $tocadosEspera = [];

        foreach ($leitura['linhas'] as $linha) {
            $dados = array_intersect_key($linha, array_flip(['tipo', 'status', 'catalogo', 'kit_virtual', 'codigo_mlb', 'titulo']));

            // Qual oferta este SKU aponta — e, se nenhuma, por quê.
            $candidatas = $linha['sku'] === null ? collect() : ($ofertasPorSku[EstruturaOferta::normalizarSku($linha['sku'])] ?? collect());
            $ofertaId = $candidatas->count() === 1 ? $candidatas->first()->id : null;
            $motivo = match (true) {
                $linha['sku'] === null => EstruturaAnuncioEspera::MOTIVO_SEM_SKU,
                $candidatas->isEmpty() => EstruturaAnuncioEspera::MOTIVO_SEM_OFERTA,
                $candidatas->count() > 1 => EstruturaAnuncioEspera::MOTIVO_SKU_REPETIDO,
                default => null,
            };

            $resumo = [
                'numero'     => $linha['numero'],
                'sku'        => $linha['sku'],
                'codigo_mlb' => $linha['codigo_mlb'],
                'titulo'     => $linha['titulo'],
                'tipo'       => $linha['tipo'],
                'status'     => $linha['status'],
            ];

            // 1) Anúncio já cadastrado (pela chave dele).
            $existente = $linha['codigo_mlb'] !== null
                ? $anuncioPorMlb[$linha['codigo_mlb']] ?? null
                : ($ofertaId ? $anuncioSemMlb[$ofertaId.'|'.$linha['tipo']] ?? null : null);

            if ($existente) {
                $destino = $ofertaId ?? $existente->oferta_id;
                $tocadosAnuncio[$existente->id] = true;
                $plano['atualizados'][] = [...$resumo, 'acao' => 'atualizar', 'anuncio_id' => $existente->id,
                    'oferta_id' => $destino, 'oferta_sku' => $skuDaOferta[$destino] ?? null,
                    'mudou_de_oferta' => $destino !== $existente->oferta_id, 'dados' => $dados];
                continue;
            }

            // 2) Linha já na espera (pela chave dela).
            $naEspera = $linha['codigo_mlb'] !== null
                ? $esperaPorMlb[$linha['codigo_mlb']] ?? null
                : ($linha['sku'] !== null ? $esperaSemMlb[EstruturaOferta::normalizarSku($linha['sku']).'|'.$linha['tipo']] ?? null : null);

            if ($naEspera) {
                $tocadosEspera[$naEspera->id] = true;
            }

            // 3) Casou com uma oferta: vira anúncio (saindo da espera, se estava).
            if ($ofertaId !== null) {
                $plano['novos'][] = [...$resumo, 'acao' => 'criar', 'oferta_id' => $ofertaId,
                    'oferta_sku' => $skuDaOferta[$ofertaId], 'espera_id' => $naEspera?->id, 'dados' => $dados];
                continue;
            }

            // 4) Não casou: espera, com o motivo.
            $plano['espera'][] = [...$resumo, 'acao' => 'esperar', 'motivo' => $motivo,
                'motivo_texto' => EstruturaAnuncioEspera::MOTIVOS[$motivo], 'dados' => $dados];
        }

        if ($modo === self::MODO_SUBSTITUIR) {
            foreach ($anuncios as $a) {
                if (! isset($tocadosAnuncio[$a->id])) {
                    $plano['removidos'][] = ['origem' => 'anuncio', 'id' => $a->id, 'oferta_sku' => $skuDaOferta[$a->oferta_id] ?? null,
                        'codigo_mlb' => $a->codigo_mlb, 'titulo' => $a->titulo, 'tipo' => $a->tipo, 'status' => $a->status];
                }
            }
            foreach ($espera as $l) {
                if (! isset($tocadosEspera[$l->id])) {
                    $plano['removidos'][] = ['origem' => 'espera', 'id' => $l->id, 'oferta_sku' => null, 'sku' => $l->sku_colado,
                        'codigo_mlb' => $l->codigo_mlb, 'titulo' => $l->titulo, 'tipo' => $l->tipo, 'status' => $l->status];
                }
            }
        }

        return $plano;
    }

    private function executar(Company $empresa, array $item): void
    {
        switch ($item['acao']) {
            case 'atualizar':
                EstruturaAnuncio::whereKey($item['anuncio_id'])->update([...$item['dados'], 'oferta_id' => $item['oferta_id']]);
                break;

            case 'criar':
                if ($item['espera_id']) {
                    EstruturaAnuncioEspera::whereKey($item['espera_id'])->delete();
                }
                EstruturaAnuncio::create([...$item['dados'], 'oferta_id' => $item['oferta_id']]);
                break;

            case 'esperar':
                $this->anuncios->guardarNaEspera($empresa, $item['sku'], $item['motivo'], $item['dados']);
                break;
        }
    }
}
