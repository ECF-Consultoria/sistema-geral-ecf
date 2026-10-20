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
 * falhou) e `publicados`. Cada FASE é um cartão aqui (é um anúncio a publicar, com preço, foto e situação
 * próprios); a tela junta a família (`familia_id`) num cartão só, com as fases lado a lado. "Preparando" NÃO existe aqui de propósito: o preparo pela IA não grava início nem fim
 * (a marca do `PreparoIaAgenda` fica 24 h no cache), e um filtro que adivinha mente.
 */
final class VitrineDeProdutosService
{
    public const PRONTOS = 'prontos';
    public const PENDENCIA = 'pendencia';
    public const PUBLICADOS = 'publicados';

    private const ORDEM = [self::PRONTOS => 0, self::PENDENCIA => 1, self::PUBLICADOS => 2];

    public function __construct(
        private ResumoRapidoService $resumo,
        private RascunhoRepository $repo,
    ) {}

    /**
     * @param  array  $alvo  saída de `ProgramasPublicadorService::resolver`
     * @return array{cartoes: list<array<string, mixed>>, contagens: array{prontos: int, pendencia: int, publicados: int, todos: int}}
     */
    public function montar(array $alvo): array
    {
        $linhas = $this->resumo->linhas($alvo, null, true);
        $rascunhos = PubRascunho::query()->whereIn('id', array_values(array_filter(array_column($linhas, 'rascunho_id'))))->get()->keyBy('id');
        $produtos = PubProduto::query()->whereIn('id', array_column($linhas, 'produto_id'))->get()->keyBy('id');

        $cartoes = array_map(fn (array $l) => $this->cartao($l, $rascunhos->get($l['rascunho_id']), $produtos->get($l['produto_id'])), $linhas);
        usort($cartoes, fn (array $a, array $b) => [self::ORDEM[$a['grupo']], $b['atualizado_em'] ?? ''] <=> [self::ORDEM[$b['grupo']], $a['atualizado_em'] ?? '']);

        $contar = fn (string $g) => count(array_filter($cartoes, fn (array $c) => $c['grupo'] === $g));

        return [
            'cartoes' => $cartoes,
            'contagens' => [
                self::PRONTOS => $contar(self::PRONTOS),
                self::PENDENCIA => $contar(self::PENDENCIA),
                self::PUBLICADOS => $contar(self::PUBLICADOS),
                'todos' => count($cartoes),
            ],
        ];
    }

    /** @param  array<string, mixed>  $l  uma linha da visão rápida */
    private function cartao(array $l, ?PubRascunho $r, ?PubProduto $p): array
    {
        [$cores, $foto] = $r !== null ? $this->coresEFoto($r) : [[], null];
        $status = $l['status_rascunho'] ?? null;
        $publicado = in_array($status, [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED, PubRascunho::PUBLISHING], true);
        $faltam = (int) ($l['faltam'] ?? 0);
        $falhou = ($l['situacao']['chave'] ?? null) === 'erro';
        $grupo = $publicado ? self::PUBLICADOS : ($faltam > 0 || $falhou || ($l['bloqueios'] ?? []) !== [] ? self::PENDENCIA : self::PRONTOS);
        $fase = max(1, (int) ($p?->fase ?? 1));

        return [
            'produto_id' => (int) $l['produto_id'],
            // A FAMÍLIA do cartão (20/10/2026, pedido do usuário): o kit da Fase 2/3 é outro anúncio, mas do MESMO
            // produto — fica no cartão do produto base, e a pessoa passa de uma fase para outra pelo lado.
            // Kit cujo base sumiu (`pubprod_base_fk` é SET NULL) fica sozinho.
            'familia_id' => (int) ($p?->produto_base_id ?? $l['produto_id']),
            'fase_numero' => $fase,
            'nome' => (string) $l['nome'],
            'sku' => (string) $l['sku'],
            'do_portal' => $p?->origem === PubProduto::ORIGEM_PORTAL,
            'fase' => $fase >= 2 ? "Fase {$fase} · ".$l['rotulo_fase'] : 'Fase 1',
            'grupo' => $grupo,
            'chip' => self::chip($grupo, $status, $l, $cores, $faltam, $falhou),
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
        $foto = $cores[0]['foto'] ?? null;
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
}
