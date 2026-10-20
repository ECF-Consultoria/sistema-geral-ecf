<?php

namespace App\Services\Publicador;

use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\Fila\ResumoRapidoService;
use App\Support\Publicador\Imagem\OpcoesImagem;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\Variacao\Eixo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * A VITRINE de produtos da conta (20/10/2026, protótipo em localhost atrás de `publicador.vitrine`): um cartão
 * por produto, com a foto grande, uma bolinha por cor (a foto de cada uma), os preços, os anúncios previstos e
 * um chip que diz O QUE falta — e de QUAL cor, quando é uma só ("Rosa: falta foto").
 *
 * Parte da visão rápida do lote (`ResumoRapidoService::linhas`, com os publicados): preços mín–máx por tipo,
 * anúncios, se está na fila. Acrescenta só o que ela não tem: as fotos de cada cor, na ordem final do plano
 * (`ResolvedorGruposImagem`), e as pendências por cor que o editor grava no resumo (`step_state.resumo.por_cor`).
 *
 * Grupos (os filtros da tela): `prontos` (não publicado e sem bloqueio), `pendencia` (falta algo ou a publicação
 * falhou) e `publicados`. "Preparando" NÃO existe aqui de propósito: o preparo pela IA não grava início nem fim
 * (a marca do `PreparoIaAgenda` fica 24 h no cache), e um filtro que adivinha mente.
 *
 * Cada produto é um cartão aqui (é um anúncio a publicar, com preço, foto e situação próprios); a tela junta as
 * FASES de um produto num cartão só, lado a lado: Fase 1 Simples, Fase 2 Combo, Fase 3 Kit, Fase 4 Combit
 * ({@see FasesDaVitrineService}, que diz em quais cartões cada um aparece). `planejados` são os combos do Portal
 * que ainda não têm anúncio, uma face de espera na Fase 2.
 */
final class VitrineDeProdutosService
{
    public const PRONTOS = 'prontos';
    public const PENDENCIA = 'pendencia';
    public const PUBLICADOS = 'publicados';

    private const ORDEM = [self::PRONTOS => 0, self::PENDENCIA => 1, self::PUBLICADOS => 2];

    /** Quantos resumos do que falta a vitrine refaz por visita (~40 ms cada); o resto sai "A conferir" e vem na próxima. */
    private const RESUMOS_POR_VISITA = 20;

    public function __construct(
        private ResumoRapidoService $resumo,
        private RascunhoRepository $repo,
        private FasesDaVitrineService $fases,
    ) {}

    /**
     * @param  array  $alvo  saída de `ProgramasPublicadorService::resolver`
     * @return array{cartoes: list<array<string, mixed>>, planejados: list<array<string, mixed>>, contagens: array{prontos: int, pendencia: int, publicados: int, todos: int}}
     */
    public function montar(array $alvo): array
    {
        $linhas = $this->resumo->linhas($alvo, null, true);
        $rascunhos = PubRascunho::query()->whereIn('id', array_values(array_filter(array_column($linhas, 'rascunho_id'))))->get()->keyBy('id');
        // O "Pronto" sai do resumo do que falta: o rascunho que nunca o teve (planilha, kit, Portal sem abrir o
        // editor) ou que mudou por fora ganha o resumo agora — e a visão rápida é relida com ele.
        if ($this->refazerResumos($rascunhos) > 0) {
            $linhas = $this->resumo->linhas($alvo, null, true);
            $rascunhos = PubRascunho::query()->whereIn('id', array_values(array_filter(array_column($linhas, 'rascunho_id'))))->get()->keyBy('id');
        }
        $produtos = PubProduto::query()->with('oferta:id,fase')->whereIn('id', array_column($linhas, 'produto_id'))->get()->keyBy('id');
        $fases = $this->fases->classificar($produtos);

        $cartoes = array_map(fn (array $l) => $this->cartao($l, $rascunhos->get($l['rascunho_id']), $produtos->get($l['produto_id']),
            $fases[(int) $l['produto_id']] ?? null), $linhas);
        usort($cartoes, fn (array $a, array $b) => [self::ORDEM[$a['grupo']], $b['atualizado_em'] ?? ''] <=> [self::ORDEM[$b['grupo']], $a['atualizado_em'] ?? '']);

        $contar = fn (string $g) => count(array_filter($cartoes, fn (array $c) => $c['grupo'] === $g));

        return [
            'cartoes' => $cartoes,
            'planejados' => $this->fases->planejados($produtos, $fases, array_column($cartoes, null, 'produto_id')),
            'contagens' => [
                self::PRONTOS => $contar(self::PRONTOS),
                self::PENDENCIA => $contar(self::PENDENCIA),
                self::PUBLICADOS => $contar(self::PUBLICADOS),
                'todos' => count($cartoes),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $l  uma linha da visão rápida
     * @param  array{tipo: string, numero: int, quantidade: ?int, familias: list<int>, composicao: ?string, selo: string}|null  $f  a fase do produto
     */
    private function cartao(array $l, ?PubRascunho $r, ?PubProduto $p, ?array $f): array
    {
        [$cores, $foto] = $r !== null ? $this->coresEFoto($r) : [[], null];
        $status = $l['status_rascunho'] ?? null;
        $publicado = in_array($status, [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED, PubRascunho::PUBLISHING], true);
        $faltam = (int) ($l['faltam'] ?? 0);
        $falhou = ($l['situacao']['chave'] ?? null) === 'erro';
        $temFoto = $foto !== null || collect($cores)->contains(fn (array $c) => $c['foto'] !== null);
        $naoPronto = $publicado || $falhou ? null : self::naoEstaPronto($r, $status, $temFoto, $faltam);
        $grupo = $publicado ? self::PUBLICADOS
            : ($naoPronto !== null || $faltam > 0 || $falhou || ($l['bloqueios'] ?? []) !== [] ? self::PENDENCIA : self::PRONTOS);
        $familias = $f['familias'] ?? [(int) $l['produto_id']];

        return [
            'produto_id' => (int) $l['produto_id'],
            // As FAMÍLIAS do cartão (20/10/2026, decisões do usuário): o combo fica no cartão do produto; o Kit e o
            // Combit, no de cada componente. A pessoa passa de uma fase para outra pelo lado.
            'familia_id' => $familias[0],
            'familias' => $familias,
            'tipo' => $f['tipo'] ?? FasesDaVitrineService::SIMPLES,
            'fase_numero' => $f['numero'] ?? 1,
            'quantidade' => $f['quantidade'] ?? null,
            'composicao' => $f['composicao'] ?? null,
            'nome' => (string) $l['nome'],
            'sku' => (string) $l['sku'],
            'do_portal' => $p?->origem === PubProduto::ORIGEM_PORTAL,
            'fase' => $f['selo'] ?? 'Fase 1',
            'grupo' => $grupo,
            'chip' => $naoPronto ?? self::chip($grupo, $status, $l, $cores, $faltam, $falhou),
            // Só produto com mais de uma cor ativa ganha bolinhas; o de uma cor só não tem o que escolher.
            'cores' => count($cores) > 1 ? $cores : [],
            'foto' => $foto,
            'precos' => [
                'gold_special' => self::faixa($l['precos']['gold_special'] ?? null),
                'gold_pro' => self::faixa($l['precos']['gold_pro'] ?? null),
            ],
            'anuncios' => (int) ($l['anuncios'] ?? 0),
            'atualizado_em' => ($r?->updated_at ?? $p?->updated_at)?->toIso8601String(),
            'url_previa' => $l['url_editor'].'?etapa=previa',
            // Marca para publicar quem ainda vai ao ML e não está na fila; quem decide se PODE é a fila (confere antes).
            'selecionavel' => ! $publicado && ($l['fila'] ?? null) === null,
        ];
    }

    /**
     * As cores ativas com a 1ª foto de cada uma (a ordem final do plano: grupo da cor, depois a galeria geral) e
     * a foto da capa do cartão. Foto que ainda não subiu ao ML aparece pela cópia guardada (D26).
     *
     * @return array{0: list<array{chave: string, rotulo: string, foto: ?string, pendencias: list<string>}>, 1: ?string}
     */
    private function coresEFoto(PubRascunho $r): array
    {
        $s = $this->repo->snapshot($r);
        $eixos = Eixo::ordenar($s->eixos);
        $porVariante = ResolvedorGruposImagem::resolver($s->variantes, $eixos, $s->imagens,
            new OpcoesImagem(OpcoesImagem::UP, null, null, $s->fotosPorVariante, $s->incluirGeral))->porVariante;
        $urls = self::urls($r, $r->imagens);
        $porCor = (array) ($r->step_state['resumo']['por_cor'] ?? []);

        $cores = [];
        foreach ($s->variantesAtivas() as $v) {
            $primeira = $porVariante[$v->chave][0] ?? null;
            $cores[] = [
                'chave' => $v->chave,
                'rotulo' => $v->rotulo($eixos),
                'foto' => $primeira !== null ? ($urls[(string) $primeira] ?? null) : null,
                'pendencias' => array_values(array_map('strval', (array) ($porCor[$v->chave] ?? []))),
            ];
        }
        $foto = collect($cores)->first(fn (array $c) => $c['foto'] !== null)['foto'] ?? null;
        if ($foto === null) {
            // Sem cor com foto: a 1ª da galeria geral, se houver.
            $geral = collect($s->imagens)->where('grupo', ResolvedorGruposImagem::GERAL)->sortBy('posicao')->first();
            $foto = $geral !== null ? ($urls[(string) $geral['imagem']] ?? null) : null;
        }

        return [$cores, $foto];
    }

    /**
     * @param  Collection<int, PubImagem>  $imagens
     * @return array<string, string> id da foto → url
     */
    private static function urls(PubRascunho $r, Collection $imagens): array
    {
        $saida = [];
        foreach ($imagens as $i) {
            $url = $i->ml_url ?: ($i->caminho !== null ? route('mlb.anuncios.publicador.fotos.arquivo', ['produto' => $r->produto_id, 'imagem' => $i->id]) : null);
            if ($url !== null) {
                $saida[(string) $i->id] = $url;
            }
        }

        return $saida;
    }

    /**
     * O chip do cartão: o que a pessoa precisa saber numa frase.
     *
     * @param  list<array{rotulo: string, pendencias: list<string>}>  $cores
     * @return array{texto: string, tom: string, detalhe: ?string}
     */
    private static function chip(string $grupo, ?string $status, array $l, array $cores, int $faltam, bool $falhou): array
    {
        if ($grupo === self::PUBLICADOS) {
            return match ($status) {
                PubRascunho::PUBLISHING => ['texto' => 'Publicando', 'tom' => 'azul', 'detalhe' => null],
                PubRascunho::PARTIALLY_PUBLISHED => ['texto' => 'Parte publicada', 'tom' => 'ambar', 'detalhe' => null],
                default => ['texto' => 'Publicado', 'tom' => 'verde', 'detalhe' => null],
            };
        }
        if ($grupo === self::PRONTOS) {
            return ($l['situacao']['chave'] ?? null) === 'pronto'
                ? ['texto' => 'Conferido', 'tom' => 'verde', 'detalhe' => null]
                : ['texto' => 'Pronto', 'tom' => 'verde', 'detalhe' => null];
        }
        if ($falhou) {
            return ['texto' => 'Não publicado', 'tom' => 'vermelho', 'detalhe' => null];
        }

        $total = $faltam === 1 ? 'Falta 1 item' : "Faltam {$faltam} itens";
        $comPendencia = array_values(array_filter($cores, fn (array $c) => $c['pendencias'] !== []));
        if (count($cores) > 1 && count($comPendencia) === 1) {
            $c = $comPendencia[0];
            $mais = count($c['pendencias']) > 1 ? ' e mais '.(count($c['pendencias']) - 1) : '';

            return ['texto' => "{$c['rotulo']}: {$c['pendencias'][0]}{$mais}", 'tom' => 'ambar', 'detalhe' => $faltam > 0 ? "{$total} no total" : null];
        }
        if (count($cores) > 1 && count($comPendencia) > 1) {
            return ['texto' => count($comPendencia).' de '.count($cores).' cores com pendência', 'tom' => 'ambar', 'detalhe' => $faltam > 0 ? "{$total} no total" : null];
        }
        if ($faltam > 0) {
            return ['texto' => $total, 'tom' => 'ambar', 'detalhe' => null];
        }

        // Sem número no resumo, mas com bloqueio conhecido antes de conferir (título igual, preço sem frete).
        return ['texto' => (string) ($l['bloqueios'][0]['mensagem'] ?? 'Com pendência'), 'tom' => 'ambar', 'detalhe' => null];
    }

    /** @return array{min: ?float, max: ?float} */
    private static function faixa(?array $p): array
    {
        return ['min' => isset($p['min']) ? (float) $p['min'] : null, 'max' => isset($p['max']) ? (float) $p['max'] : null];
    }

    /**
     * A vitrine vale para esta conta? `publicador.vitrine` liga para todas; `publicador.vitrine_contas` liga só para
     * as contas listadas, pela chave da URL (20/10/2026: "só na #459 primeiro", até o usuário aprovar para o time).
     */
    public static function ligadaPara(string $chave): bool
    {
        return (bool) config('publicador.vitrine') || in_array($chave, (array) config('publicador.vitrine_contas', []), true);
    }

    /**
     * Por que o cartão NÃO está pronto, quando o resumo não basta para dizer — ou null. "Pronto" é afirmação
     * (20/10/2026, o usuário: "está dizendo que está pronto, mas nem imagem tem"): sem foto nenhuma não é pronto;
     * rascunho com o resumo de outra revisão (ou sem resumo) está "A conferir"; e sem categoria nada foi validado.
     * O conferido (`validated`) já passou pelo Mercado Livre e só precisa da foto.
     *
     * @return ?array{texto: string, tom: string, detalhe: ?string}
     */
    private static function naoEstaPronto(?PubRascunho $r, ?string $status, bool $temFoto, int $faltam): ?array
    {
        if (! $temFoto) {
            return ['texto' => 'Falta foto', 'tom' => 'ambar', 'detalhe' => $faltam > 1 ? "Faltam {$faltam} itens no total" : null];
        }
        if ($r === null || $status !== PubRascunho::DRAFT) {
            return null;
        }
        $resumo = (array) ($r->step_state['resumo'] ?? []);
        if ((int) ($resumo['revisao'] ?? -1) !== (int) $r->revisao || ! array_key_exists('validado', $resumo)) {
            return ['texto' => 'A conferir', 'tom' => 'ambar', 'detalhe' => 'Abra a prévia para conferir'];
        }
        if ($resumo['validado'] !== true) {
            return ['texto' => 'Falta categoria', 'tom' => 'ambar', 'detalhe' => null];
        }

        return null;
    }

    /**
     * Refaz o resumo do que falta (o MESMO do editor, {@see EditorRascunhoService::estado()}) dos rascunhos em
     * preenchimento que não o têm em dia — até {@see RESUMOS_POR_VISITA} por visita. Falhar num não derruba a tela:
     * o cartão dele sai "A conferir".
     *
     * @param  Collection<int, PubRascunho>  $rascunhos
     * @return int quantos foram refeitos
     */
    private function refazerResumos(Collection $rascunhos): int
    {
        $velhos = $rascunhos->filter(function (PubRascunho $r) {
            $resumo = (array) ($r->step_state['resumo'] ?? []);

            return $r->status === PubRascunho::DRAFT
                && ((int) ($resumo['revisao'] ?? -1) !== (int) $r->revisao || ! array_key_exists('validado', $resumo));
        })->take(self::RESUMOS_POR_VISITA);

        $feitos = 0;
        foreach ($velhos as $r) {
            try {
                app(EditorRascunhoService::class)->estado($r);
                $feitos++;
            } catch (\Throwable $e) {
                Log::warning("[Publicador] Vitrine: o resumo do rascunho {$r->id} não foi refeito: {$e->getMessage()}");
            }
        }

        return $feitos;
    }
}
