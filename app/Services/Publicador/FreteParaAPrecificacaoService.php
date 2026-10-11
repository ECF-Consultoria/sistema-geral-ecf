<?php

namespace App\Services\Publicador;

use App\Models\EstruturaOferta;
use App\Models\EstruturaPublicacao;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\ConferenciaDeFrete;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Facades\DB;

/**
 * Leva para a Precificação do Portal o frete que o Mercado Livre respondeu na conferência (11/10/2026).
 *
 * O usuário, sobre a diferença grande de frete: "senão, nós teríamos que reprecificar". Aprovou um botão
 * que leva o valor do Mercado Livre para a Precificação, em vez de só avisar.
 *
 * O número NUNCA vem da tela: vale o que ficou gravado em `respostas_ml.frete` da última conferência DESTA
 * revisão do rascunho. Só as linhas que divergiram (acima da tolerância) são levadas, cada uma para a oferta
 * e o tipo dela, e só para oferta da mesma empresa do produto.
 *
 * O frete vira o DIGITADO da Precificação. O preço do rascunho que vem do Portal muda junto (ele é lido na
 * hora, nunca gravado), então a conferência deixa de valer: o rascunho é "tocado" e pede conferir de novo.
 */
class FreteParaAPrecificacaoService
{
    public function __construct(
        private EstruturaPrecificacaoService $precificacao,
        private RascunhoRepository $repo,
    ) {}

    /**
     * @return array{aplicadas: int, ofertas: list<string>}
     */
    public function aplicar(PubRascunho $r, User $quem): array
    {
        $porOferta = $this->oQueLevar($r);
        if ($porOferta === []) {
            throw new RegraViolada('FRT-01', 'Não há frete do Mercado Livre para levar: confira o anúncio de novo.');
        }

        $empresaId = $r->produto->company_id;
        $ofertas = EstruturaOferta::query()->where('company_id', $empresaId)->whereIn('id', array_keys($porOferta))->get()->keyBy('id');
        if ($empresaId === null || $ofertas->isEmpty()) {
            throw new RegraViolada('FRT-02', 'Este produto não está ligado à Precificação do Portal.');
        }

        $ator = AtorDoPortal::daEquipe($quem);
        $skus = [];
        $aplicadas = 0;
        DB::transaction(function () use ($ofertas, $porOferta, $ator, $r, &$skus, &$aplicadas) {
            foreach ($ofertas as $id => $oferta) {
                $this->precificacao->salvarFrete($oferta, $porOferta[$id], $ator);
                $skus[] = (string) $oferta->sku;
                $aplicadas += count($porOferta[$id]);
            }
            // O preço que vem do Portal mudou: a conferência anterior não vale mais.
            $this->repo->tocar($r);
        });

        return ['aplicadas' => $aplicadas, 'ofertas' => $skus];
    }

    /**
     * O que a última conferência mediu e divergiu: `oferta_id → [tipo => frete do Mercado Livre]`.
     *
     * @return array<int, array<string, float>>
     */
    public function oQueLevar(PubRascunho $r): array
    {
        $v = $r->validacoes()->orderByDesc('id')->first();
        $frete = (array) ($v?->respostas_ml['frete'] ?? []);
        if ($v === null || (int) $v->revisao !== (int) $r->revisao || empty($frete['aplicavel'])) {
            return [];
        }

        $tipoDe = array_flip(EstruturaPublicacao::LISTING_TYPES);
        $saida = [];
        foreach ((array) ($frete['linhas'] ?? []) as $l) {
            $tipo = $tipoDe[$l['listing_type'] ?? ''] ?? null;
            if ($tipo === null || empty($l['oferta_id']) || ! is_numeric($l['ml'] ?? null) || ($l['nivel'] ?? ConferenciaDeFrete::IGUAL) === ConferenciaDeFrete::IGUAL) {
                continue;
            }
            $saida[(int) $l['oferta_id']][$tipo] = round((float) $l['ml'], 2);
        }

        return $saida;
    }
}
