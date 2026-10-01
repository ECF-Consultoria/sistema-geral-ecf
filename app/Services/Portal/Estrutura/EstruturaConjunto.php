<?php

namespace App\Services\Portal\Estrutura;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaOfertaComponente;

/**
 * O conjunto INTEIRO de ofertas de uma empresa, com anúncios e composição, já
 * medido pela {@see ReguaEstrutura} — de onde saem o painel, a página de
 * ofertas, a agenda e a proposta "agendar o que falta".
 *
 * ### Por que carregar tudo, e paginar só a entrega
 * O maior seller da carteira ECF tem 2.688 anúncios ativos (ADR §Escala). Três
 * consultas trazem ofertas, componentes e anúncios da empresa — alguns milhares
 * de linhas, barato em PHP — e o painel é somado sobre isso. A TELA recebe só
 * a página. Somar o painel sobre a página daria "4 publicados" para quem tem
 * 400; filtrar no navegador sobre a página daria contador dizendo 7 sobre lista
 * vazia (`portal-do-cliente.md` §25).
 *
 * ### A ordem é a da aula
 * Cada produto simples, seguido dos combos dele (por unidades); depois kits e
 * combits. É a ordem do exemplo da planilha (CAD-01, CB2…CB6, MSA-MR, kit,
 * combit) e a de "para cada produto, pergunte: dá combo? combina com qual?".
 */
final class EstruturaConjunto
{
    /** @var array<int, array> ofertas por id, já medidas */
    private array $porId = [];

    /** @var array<int, array{principal: int, ofertas: array<int>}> */
    private array $blocos = [];

    /** @var array<int, int>|null id → em quantas composições entra (preguiçoso) */
    private ?array $usos = null;

    private function __construct()
    {
    }

    public static function daEmpresa(Company $empresa): self
    {
        $conjunto = new self();

        $ofertas = EstruturaOferta::query()
            ->where('company_id', $empresa->id)
            ->orderBy('id')
            ->get(['id', 'sku', 'fase', 'nome', 'logistica', 'observacoes']);

        $componentes = EstruturaOfertaComponente::query()
            ->join('estrutura_ofertas as o', 'o.id', '=', 'estrutura_oferta_componentes.oferta_id')
            ->where('o.company_id', $empresa->id)
            ->orderBy('estrutura_oferta_componentes.id')
            ->get(['estrutura_oferta_componentes.oferta_id', 'estrutura_oferta_componentes.componente_id', 'estrutura_oferta_componentes.quantidade'])
            ->groupBy('oferta_id');

        $anuncios = EstruturaAnuncio::query()
            ->join('estrutura_ofertas as o', 'o.id', '=', 'estrutura_anuncios.oferta_id')
            ->where('o.company_id', $empresa->id)
            ->orderBy('estrutura_anuncios.id')
            ->get(['estrutura_anuncios.*'])
            ->groupBy('oferta_id');

        foreach ($ofertas as $o) {
            $comps = ($componentes[$o->id] ?? collect())->map(fn ($c) => [
                'id'         => (int) $c->componente_id,
                'quantidade' => (int) $c->quantidade,
            ])->values()->all();

            $ans = ($anuncios[$o->id] ?? collect())->map(fn (EstruturaAnuncio $a) => [
                'id'          => $a->id,
                'tipo'        => $a->tipo,
                'status'      => $a->status,
                'catalogo'    => (bool) $a->catalogo,
                'kit_virtual' => (bool) $a->kit_virtual,
                'codigo_mlb'  => $a->codigo_mlb,
                'titulo'      => $a->titulo,
            ])->values()->all();

            $contagens = ReguaEstrutura::contagens($ans);

            $conjunto->porId[$o->id] = [
                'id'          => $o->id,
                'sku'         => $o->sku,
                'fase'        => $o->fase,
                'nome'        => $o->nome,
                'logistica'   => $o->logistica,
                'observacoes' => $o->observacoes,
                'componentes' => $comps,
                'unidades'    => ReguaEstrutura::unidades($o->fase, $comps),
                'anuncios'    => $ans,
                ...$contagens,
                'situacao'    => ReguaEstrutura::situacao($contagens['classicos'], $contagens['premiums']),
            ];
        }

        $conjunto->montarBlocos();

        return $conjunto;
    }

    /** Ofertas na ordem da tela (bloco a bloco). */
    public function ofertas(): array
    {
        $ordem = [];

        foreach ($this->blocos as $bloco) {
            foreach ($bloco['ofertas'] as $id) {
                $ordem[] = $this->porId[$id];
            }
        }

        return $ordem;
    }

    public function oferta(int $id): ?array
    {
        return $this->porId[$id] ?? null;
    }

    /** @return array<int, array{principal: int, ofertas: array<int>}> */
    public function blocos(): array
    {
        return $this->blocos;
    }

    public function painel(): array
    {
        return ReguaEstrutura::painel(array_values($this->porId));
    }

    /**
     * SKUs (normalizados) que aparecem em mais de uma oferta. A planilha conta
     * as duas; a tela avisa, e a colagem não sabe em qual delas pôr o anúncio.
     *
     * @return array<string, int> sku normalizado → quantas ofertas
     */
    public function skusRepetidos(): array
    {
        $contagem = [];

        foreach ($this->porId as $o) {
            $sku = EstruturaOferta::normalizarSku($o['sku']);
            if ($sku !== null) {
                $contagem[$sku] = ($contagem[$sku] ?? 0) + 1;
            }
        }

        return array_filter($contagem, fn ($n) => $n > 1);
    }

    /**
     * Kits e combits em que cada produto simples entra — o "Também em" da tela.
     *
     * @return array<int, array<int>> id da simples → ids de kits/combits
     */
    public function usoEmKits(): array
    {
        $uso = [];

        foreach ($this->porId as $o) {
            if (! in_array($o['fase'], [EstruturaOferta::FASE_KIT, EstruturaOferta::FASE_COMBIT], true)) {
                continue;
            }

            foreach ($o['componentes'] as $c) {
                $uso[$c['id']][] = $o['id'];
            }
        }

        return $uso;
    }

    /**
     * Em quantas variações (combos, kits, combits) esta oferta entra. A gaveta
     * avisa antes de excluir — o FK `restrict` recusaria de qualquer jeito.
     */
    public function vezesUsadaComoComponente(int $id): int
    {
        if ($this->usos === null) {
            $this->usos = [];
            foreach ($this->porId as $o) {
                foreach ($o['componentes'] as $c) {
                    $this->usos[$c['id']] = ($this->usos[$c['id']] ?? 0) + 1;
                }
            }
        }

        return $this->usos[$id] ?? 0;
    }

    /**
     * Um bloco por produto simples (ele + os combos dele) e um por kit/combit.
     *
     * Combo tem exatamente um componente simples (regra da composição), então
     * sempre tem um produto a que pertencer. O `?? null` abaixo só existiria
     * para dado que a regra não deixa gravar — e aí o combo vira bloco próprio
     * em vez de sumir da tela.
     */
    private function montarBlocos(): void
    {
        $combosDe = [];
        $avulsos = [];

        foreach ($this->porId as $o) {
            if ($o['fase'] === EstruturaOferta::FASE_COMBO) {
                $base = $o['componentes'][0]['id'] ?? null;

                if ($base !== null && isset($this->porId[$base])) {
                    $combosDe[$base][] = $o['id'];
                    continue;
                }

                $avulsos[] = $o['id'];
            }
        }

        foreach ($this->porId as $o) {
            if ($o['fase'] !== EstruturaOferta::FASE_SIMPLES) {
                continue;
            }

            $combos = $combosDe[$o['id']] ?? [];
            usort($combos, fn ($a, $b) => [$this->porId[$a]['unidades'], $a] <=> [$this->porId[$b]['unidades'], $b]);

            $this->blocos[] = ['principal' => $o['id'], 'ofertas' => [$o['id'], ...$combos]];
        }

        foreach ($avulsos as $id) {
            $this->blocos[] = ['principal' => $id, 'ofertas' => [$id]];
        }

        foreach ($this->porId as $o) {
            if (in_array($o['fase'], [EstruturaOferta::FASE_KIT, EstruturaOferta::FASE_COMBIT], true)) {
                $this->blocos[] = ['principal' => $o['id'], 'ofertas' => [$o['id']]];
            }
        }
    }
}
