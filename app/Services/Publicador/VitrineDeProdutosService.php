<?php

namespace App\Services\Publicador;

use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use App\Services\Publicador\Fila\ResumoRapidoService;
use App\Support\Publicador\Imagem\OpcoesImagem;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\ComposicaoDoComposto;
use App\Support\Publicador\IaTrabalhando;
use App\Support\Publicador\ImagensReprovadas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * A VITRINE de produtos da conta (20/10/2026, protótipo em localhost atrás de `publicador.vitrine`): um cartão
 * por produto, com a foto grande, uma bolinha por cor (a foto de cada uma), os preços, os anúncios previstos e
 * um chip que diz O QUE falta — e de QUAL cor, quando é uma só ("Rosa: falta imagem").
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

    /** O chip de quem o limite diário de imagens por IA barrou (22/10/2026). */
    public const IMAGENS_AMANHA = 'Imagens amanhã';

    /** O chip do produto sem categoria e sem foto (23/10/2026, P-30): sem categoria, nada mais foi conferido. */
    public const FALTA_CATEGORIA_E_IMAGEM = 'Falta categoria e imagem';

    /** O chip de quem a geração automática de imagens falhou e as tentativas acabaram (23/10/2026, P-06). */
    public const IMAGENS_NAO_GERADAS = 'Imagens não geradas';

    private const ORDEM = [self::PRONTOS => 0, self::PENDENCIA => 1, self::PUBLICADOS => 2];

    /** As etapas do editor, como a tela as chama (o "o que falta" do cartão). */
    private const NOME_DA_ETAPA = ['produto' => 'Produto', 'detalhes' => 'Detalhes', 'imagens' => 'Imagens', 'condicoes' => 'Condições de venda', 'previa' => 'Prévia'];

    /** Quantos resumos do que falta a vitrine refaz por visita (~40–80 ms cada); o resto sai "A conferir" e vem na próxima. */
    private const RESUMOS_POR_VISITA = 40;

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
        // O cliente excluiu no Portal: as duas ligações viraram NULL (FK `nullOnDelete`) e o produto ficou aqui. Sai
        // pela exclusão da vitrine (`ExcluirProdutoService`, que só exclui o que nunca foi publicado).
        $saiuDoPortal = $p !== null && $p->origem === PubProduto::ORIGEM_PORTAL && $p->estrutura_produto_id === null && $p->oferta_id === null;
        // A IA mexendo nele agora (20/10/2026): no lugar da pendência, "Atualizando…" — e a tela se relê sozinha.
        $ia = $publicado || $saiuDoPortal ? null : IaTrabalhando::no($r, $p?->estrutura_produto_id !== null ? (int) $p->estrutura_produto_id : null);
        $naoPronto = $publicado || $falhou ? null : match (true) {
            $saiuDoPortal => ['texto' => 'Saiu do Portal', 'tom' => 'vermelho', 'detalhe' => 'Marque e exclua daqui também'],
            $ia !== null => self::chipDaIa($ia),
            default => self::naoEstaPronto($r, $status, $temFoto, $faltam),
        };
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
            'nome' => self::nomeDoCartao((string) $l['nome'], $p, $f['tipo'] ?? null),
            'sku' => (string) ($l['sku_do_anuncio'] ?? $l['sku']),
            'do_portal' => $p?->origem === PubProduto::ORIGEM_PORTAL && ! $saiuDoPortal,
            'saiu_do_portal' => $saiuDoPortal,
            'ia' => $ia,
            // As imagens da IA que o juiz reprovou, com o motivo (21/10/2026) — só depois que ela terminou.
            'ia_reprovada' => $publicado || $ia !== null ? null : ImagensReprovadas::do($r),
            // 21/10/2026: o chip de pendência abre O QUE falta, cada item levando à etapa do editor onde se resolve.
            'resolver' => $grupo === self::PENDENCIA && ! $saiuDoPortal && $ia === null ? self::oQueResolver($l, $r, $naoPronto, $falhou) : [],
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
            // 27/10/2026: quando o produto chegou aqui (do Portal, da planilha ou do "+ Produto") — a ordem "Mais
            // recentes primeiro" e o "Chegou há…" do cartão.
            'chegou_em' => $p?->created_at?->toIso8601String(),
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
    /**
     * O kit do MESMO produto em cores diferentes que nasceu no Planejamento com o nome antigo aparece com o nome do kit
     * (teste da empresa #459, 22/10/2026; a regra em {@see ComposicaoDoComposto::nomeParaMostrar()}). Nada é gravado.
     */
    private static function nomeDoCartao(string $nome, ?PubProduto $p, ?string $tipo): string
    {
        if (! in_array($tipo, [FasesDaVitrineService::KIT, FasesDaVitrineService::COMBIT], true)) {
            return $nome;
        }

        return ComposicaoDoComposto::doProduto($p)?->nomeParaMostrar($nome) ?? $nome;
    }

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
            // Sem categoria nada foi conferido (o resumo diz 0): o cartão do produto vazio dizia só "Falta imagem"
            // (23/10/2026, P-30 — o "QA22 Salvar Triplo" não tinha categoria, título, preço, estoque nem descrição).
            if ($r !== null && ! $r->categoria_id) {
                return ['texto' => self::FALTA_CATEGORIA_E_IMAGEM, 'tom' => 'ambar', 'detalhe' => 'O resto só é conferido com a categoria'];
            }

            return ['texto' => 'Falta imagem', 'tom' => 'ambar', 'detalhe' => $faltam > 1 ? "Faltam {$faltam} itens no total" : null];
        }
        // 22/10/2026: o limite diário barrou as imagens por IA — só com as fotos do cliente, ainda não está pronto. 23/10
        // (P-06): nem quando a geração falhou por erro técnico.
        $imagens = CriativosAutomaticosService::situacaoDasImagens($r);
        if ($imagens === 'adiado') {
            return ['texto' => self::IMAGENS_AMANHA, 'tom' => 'ambar', 'detalhe' => 'O limite de imagens por IA de hoje acabou'];
        }
        if ($imagens === 'nova_tentativa') {
            return ['texto' => self::IMAGENS_AMANHA, 'tom' => 'ambar', 'detalhe' => 'A geração das imagens falhou; o sistema tenta de novo amanhã cedo'];
        }
        if ($imagens === 'falhou') {
            return ['texto' => self::IMAGENS_NAO_GERADAS, 'tom' => 'ambar', 'detalhe' => 'A geração automática falhou duas vezes'];
        }
        if ($r === null || $status !== PubRascunho::DRAFT) {
            return null;
        }
        if (! self::resumoEmDia($r)) {
            return ['texto' => 'A conferir', 'tom' => 'ambar', 'detalhe' => 'Abra a prévia para conferir'];
        }
        if (($r->step_state['resumo']['validado'] ?? null) !== true) {
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
        $velhos = $rascunhos->filter(fn (PubRascunho $r) => $r->status === PubRascunho::DRAFT && ! self::resumoEmDia($r))
            ->take(self::RESUMOS_POR_VISITA);

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

    /** O resumo do que falta foi feito NESTA revisão, no formato de hoje (com `validado`)? */
    private static function resumoEmDia(PubRascunho $r): bool
    {
        $resumo = (array) ($r->step_state['resumo'] ?? []);

        return (int) ($resumo['revisao'] ?? -1) === (int) $r->revisao
            && array_key_exists('validado', $resumo)
            && ($resumo['versao'] ?? null) === EditorRascunhoService::VERSAO_DO_RESUMO;
    }

    /** O chip de quem a IA está atualizando agora: a pendência espera, e o detalhe diz o que ela faz. */
    private static function chipDaIa(string $ia): array
    {
        return ['texto' => 'Atualizando…', 'tom' => 'azul', 'detalhe' => match ($ia) {
            IaTrabalhando::AGENDADA => 'A IA começa em instantes',
            IaTrabalhando::ESCREVENDO => 'A IA está escrevendo título e descrição',
            default => 'A IA está gerando as imagens',
        }];
    }

    /**
     * O que falta, item por item, com o link para a etapa do editor onde se resolve (21/10/2026, o usuário: "nesses que
     * falam que faltam algum item, tem que ser clicável me induzindo a resolver"). `corrigir=1` faz o editor abrir a
     * etapa com os campos marcados. Os itens vêm do resumo (até 5); sem ele, o motivo do chip.
     *
     * @return list<array{texto: string, url: string}>
     */
    private static function oQueResolver(array $l, ?PubRascunho $r, ?array $naoPronto, bool $falhou): array
    {
        $editor = (string) ($l['url_editor'] ?? '');
        if ($editor === '') {
            return [];
        }
        $em = fn (string $etapa) => $editor.'?etapa='.$etapa.'&corrigir=1';
        if ($falhou) {
            return [['texto' => 'A publicação não foi concluída: veja o motivo na Prévia.', 'url' => $editor.'?etapa=previa']];
        }
        $chip = $naoPronto['texto'] ?? null;
        if ($chip === 'A conferir') {
            return [['texto' => 'Abra o anúncio para o sistema conferir o que falta.', 'url' => $editor.'?etapa=previa']];
        }
        if ($chip === 'Falta categoria') {
            return [['texto' => 'Escolha a categoria do produto.', 'url' => $em('produto')]];
        }
        if ($chip === self::FALTA_CATEGORIA_E_IMAGEM) {
            return [['texto' => 'Escolha a categoria do produto.', 'url' => $em('produto')],
                ['texto' => 'Adicione ao menos uma imagem do produto.', 'url' => $em('imagens')]];
        }
        if ($chip === self::IMAGENS_NAO_GERADAS) {
            return [['texto' => 'A geração automática das imagens falhou duas vezes. Gere na etapa Imagens.', 'url' => $em('imagens')]];
        }
        if ($chip === self::IMAGENS_AMANHA) {
            return [['texto' => 'O limite diário de imagens por IA acabou: o sistema pede de novo amanhã cedo. Para não esperar, gere na etapa Imagens.', 'url' => $em('imagens')]];
        }

        $itens = [];
        foreach ((array) ($r?->step_state['resumo']['itens'] ?? []) as $i) {
            $texto = trim((string) ($i['mensagem'] ?? ''));
            $etapa = (string) ($i['etapa'] ?? 'previa');
            $total = (int) ($i['total'] ?? 1);
            if ($texto !== '') {
                // Uma linha por etapa: o item, ou "Detalhes: 12 itens — o 1º".
                $itens[] = ['texto' => $total > 1 ? (self::NOME_DA_ETAPA[$etapa] ?? 'Prévia').": {$total} itens — {$texto}" : $texto, 'url' => $em($etapa)];
            }
        }
        if ($itens !== []) {
            return $itens;
        }
        if ($chip === 'Falta imagem') {
            return [['texto' => 'Adicione ao menos uma imagem do produto.', 'url' => $em('imagens')]];
        }
        // Bloqueio conhecido antes de conferir (título igual, preço sem frete), da visão rápida.
        foreach (array_slice((array) ($l['bloqueios'] ?? []), 0, 5) as $b) {
            $texto = trim((string) ($b['mensagem'] ?? ''));
            if ($texto !== '') {
                $itens[] = ['texto' => $texto, 'url' => $em('previa')];
            }
        }

        return $itens !== [] ? $itens : [['texto' => 'Ver o que falta no anúncio.', 'url' => $em('previa')]];
    }
}
