<?php

namespace App\Services\Publicador\Jardinagem;

use App\Contracts\ContaMercadoLivre;
use App\Jobs\Publicador\GerarPropostaDaJardinagemJob;
use App\Jobs\Publicador\RelerEstadoDoPublicadoJob;
use App\Models\PubJardinagem;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\MercadoLivreService;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\ReguaDaJardinagem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A Jardinagem mensal dos anúncios criados pelo Publicador (23/10/2026), pela árvore do MAG — ver
 * {@see ReguaDaJardinagem} para os ramos e o model {@see PubJardinagem} para o que cada um manda fazer.
 *
 * ### O ciclo
 * O 1º mês de um anúncio começa no dia em que ele foi criado; cada mês seguinte começa no dia da análise
 * anterior. A rodada diária analisa só o anúncio cujo mês FECHOU (`dias_do_ciclo` dias inteiros desde o
 * começo): cada anúncio uma vez por mês, no seu próprio dia. Rodar de novo no mesmo dia não duplica — o
 * mês recém-gravado reabre o relógio.
 *
 * ### Por que a leitura é no ML, na hora
 * O acervo do "Meus anúncios" (Fase 134) NÃO serve: as visitas vieram nulas em 100% das linhas de
 * produção (10–11/10/2026), e as vendas de lá são vitalícias. Aqui, por anúncio:
 * - visitas: `/items/{id}/visits/time_window?last=N&unit=day` (o mesmo da Jardinagem do Portal, que
 *   funciona em produção), somadas só nos dias inteiros da janela;
 * - vendas: o `sold_quantity` do ML agora MENOS o gravado no mês anterior (no 1º mês, zero — o anúncio
 *   nasceu no Publicador). Exato para qualquer âncora (Company ou MlbEmpresa), sem ler pedidos.
 *
 * Só leitura (GET), só na conta liberada do MESMO vendedor que publicou — a régua de
 * {@see RelerEstadoDoPublicadoJob::contaQueLe()}. Anúncio pausado, em revisão ou encerrado não entra: sem
 * estar no ar, "zero visita" seria mentira da régua.
 */
class JardinagemService
{
    public const FUSO = 'America/Sao_Paulo';

    /** O `time_window` do ML: janelas mais longas que isto ficam com as visitas dos últimos N dias. */
    private const DIAS_MAX_DE_VISITAS = 90;

    /** Multiget do ML: até 20 ids por chamada. */
    private const IDS_POR_MULTIGET = 20;

    public const MOTIVO_MAX = 300;

    public const OBSERVACAO_MAX = 2000;

    /** A prévia fica guardada este tanto (segundos): abrir e fechar a tela não refaz as leituras no ML. */
    public const PREVIA_TTL = 300;

    /** No máximo estes anúncios por prévia (cada um = 2 GETs, numa requisição da tela). */
    private const PREVIA_TETO = 60;

    public function __construct(
        private MercadoLivreService $ml,
    ) {}

    public function regua(): ReguaDaJardinagem
    {
        return ReguaDaJardinagem::daConfig();
    }

    public static function diasDoCiclo(): int
    {
        return max(1, (int) config('publicador.jardinagem.dias_do_ciclo', 30));
    }

    // ═══ Quem entra na rodada ═══════════════════════════════════════════════

    /**
     * Os anúncios do Publicador e onde cada um está no seu mês. Nada vai ao ML aqui.
     *
     * - `devidos`: o mês fechou — entram na análise (os mais atrasados primeiro);
     * - `em_observacao`: ainda dentro do mês (com quantos dias faltam);
     * - `encerrados` / `fora_do_ar`: o último estado relido diz encerrado / pausado ou em revisão;
     * - `fora`: conta não liberada ou outro vendedor (nada se lê).
     *
     * @return array{devidos: list<array>, em_observacao: list<array>, encerrados: int, fora_do_ar: int, fora: int, por_conta: array<string, int>}
     */
    public function situacao(?string $soConta = null, ?int $dias = null, ?CarbonImmutable $hoje = null): array
    {
        $dias = max(0, $dias ?? self::diasDoCiclo());
        $hoje = ($hoje ?? CarbonImmutable::now(self::FUSO))->setTimezone(self::FUSO)->startOfDay();

        $itens = PubPublicacaoItem::query()
            ->where('status', PubPublicacaoItem::CREATED)
            ->whereNotNull('ml_item_id')
            ->orderByDesc('id')
            ->get(['id', 'publicacao_id', 'listing_type_id', 'ml_item_id', 'avisos', 'payload', 'criado_em', 'created_at'])
            // O mesmo MLB em duas publicações (reconciliado): conta uma vez, pelo item mais novo.
            ->unique('ml_item_id')
            ->values();

        // O último mês gravado de cada MLB (ordem crescente de ciclo: o último sobrescreve).
        $ultimos = PubJardinagem::query()
            ->whereIn('ml_item_id', $itens->pluck('ml_item_id')->all())
            ->orderBy('ciclo')
            ->get(['id', 'ml_item_id', 'ciclo', 'janela_fim', 'vendidos_total'])
            ->keyBy('ml_item_id');

        $publicacoes = [];
        $r = ['devidos' => [], 'em_observacao' => [], 'encerrados' => 0, 'fora_do_ar' => 0, 'fora' => 0, 'por_conta' => []];
        foreach ($itens as $item) {
            $pid = (int) $item->publicacao_id;
            if (! array_key_exists($pid, $publicacoes)) {
                $p = PubPublicacao::find($pid);
                $chave = (string) ($p?->ator['conta']['chave'] ?? '');
                $publicacoes[$pid] = [
                    'publicacao' => $p,
                    'chave' => $chave,
                    'filtrada' => $soConta !== null && $chave !== $soConta,
                    'conta' => $p !== null && ($soConta === null || $chave === $soConta) ? RelerEstadoDoPublicadoJob::contaQueLe($p) : null,
                ];
            }
            $pub = $publicacoes[$pid];
            if ($pub['filtrada']) {
                continue;
            }
            if ($pub['conta'] === null) {
                $r['fora']++;

                continue;
            }

            $estado = (string) ($item->avisos['estado']['status'] ?? '');
            if ($estado === 'closed') {
                $r['encerrados']++;

                continue;
            }
            if ($estado !== '' && $estado !== 'active') {
                $r['fora_do_ar']++;

                continue;
            }

            $ultimo = $ultimos->get($item->ml_item_id);
            $inicio = $ultimo !== null
                ? CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $ultimo->janela_fim, 0, 10), self::FUSO)
                : CarbonImmutable::parse($item->criado_em ?? $item->created_at)->setTimezone(self::FUSO)->startOfDay();
            $passados = (int) $inicio->diffInDays($hoje, false);

            $linha = [
                'item' => $item,
                'publicacao' => $pub['publicacao'],
                'conta' => $pub['conta'],
                'conta_chave' => $pub['chave'],
                'ciclo' => ($ultimo?->ciclo ?? 0) + 1,
                'inicio' => $inicio->format('Y-m-d'),
                'base_vendidos' => (int) ($ultimo?->vendidos_total ?? 0),
                'dias_passados' => max(0, $passados),
                'faltam' => max(0, $dias - $passados),
            ];

            if ($passados >= $dias) {
                $r['devidos'][] = $linha;
                $r['por_conta'][$pub['chave'] !== '' ? $pub['chave'] : 'sem chave'] = ($r['por_conta'][$pub['chave'] !== '' ? $pub['chave'] : 'sem chave'] ?? 0) + 1;
            } else {
                $r['em_observacao'][] = $linha;
            }
        }

        // O mês mais antigo primeiro (é o mais atrasado); empate pelo item mais antigo.
        usort($r['devidos'], fn ($a, $b) => [$a['inicio'], $a['item']->id] <=> [$b['inicio'], $b['item']->id]);
        usort($r['em_observacao'], fn ($a, $b) => [$a['faltam'], $a['item']->id] <=> [$b['faltam'], $b['item']->id]);
        ksort($r['por_conta']);

        return $r;
    }

    // ═══ A análise ══════════════════════════════════════════════════════════

    /**
     * Lê no ML os anúncios devidos e aplica a régua. Com `gravar`, cada mês vira uma linha (a aberta do
     * mês anterior sai como `substituida`) e o anúncio sem tração ganha o pedido do título novo.
     *
     * @param  list<array>  $devidos  linhas de {@see situacao()}
     * @return list<array{ml_item_id: string, conta: string, ciclo: int, resultado: string, visitas?: int, vendas?: int, conversao?: ?float, diagnostico?: string, id?: int, erro?: string}>
     */
    public function analisar(array $devidos, bool $gravar, ?CarbonImmutable $hoje = null): array
    {
        $hoje = ($hoje ?? CarbonImmutable::now(self::FUSO))->setTimezone(self::FUSO)->startOfDay();
        $regua = $this->regua();
        $saida = [];

        // Uma leva de leitura por conta (o token é dela).
        $porConta = [];
        foreach ($devidos as $d) {
            $porConta[$d['conta_chave']][] = $d;
        }

        foreach ($porConta as $chave => $linhas) {
            /** @var ContaMercadoLivre $conta */
            $conta = $linhas[0]['conta'];
            $lidos = $this->lerNoMl($conta, $linhas, $hoje);

            foreach ($linhas as $d) {
                $mlb = (string) $d['item']->ml_item_id;
                $base = ['ml_item_id' => $mlb, 'conta' => (string) $chave, 'ciclo' => (int) $d['ciclo']];
                $lido = $lidos[$mlb] ?? ['erro' => 'O Mercado Livre não respondeu.'];

                if (isset($lido['erro'])) {
                    Log::warning("[Publicador Jardinagem] {$mlb} ({$chave}) não foi lido: {$lido['erro']}");
                    $saida[] = [...$base, 'resultado' => 'erro', 'erro' => $lido['erro']];

                    continue;
                }
                if ($lido['status'] !== 'active') {
                    $saida[] = [...$base, 'resultado' => 'fora_do_ar', 'erro' => "Está \"{$lido['status']}\" no Mercado Livre."];

                    continue;
                }

                $vendas = max(0, $lido['vendidos'] - (int) $d['base_vendidos']);
                $diagnostico = $regua->diagnosticar($lido['visitas'], $vendas);
                $analise = [...$base, 'resultado' => 'analisado', 'visitas' => $lido['visitas'], 'vendas' => $vendas,
                    'conversao' => $regua->conversao($lido['visitas'], $vendas), 'diagnostico' => $diagnostico];

                if ($gravar) {
                    $analise['id'] = $this->gravar($d, $lido, $analise, $hoje)->id;
                }
                $saida[] = $analise;
            }
        }

        return $saida;
    }

    /**
     * A PRÉVIA de hoje (23/10/2026, pedido do usuário: "queria ver os dados" antes de ligar a Jardinagem): cada
     * anúncio no ar, com as visitas e vendas do mês ATÉ AGORA (parcial) e o ramo em que cairia se fosse analisado
     * hoje. Só leitura no ML e nada gravado — vale com a Jardinagem desligada. Guardada `PREVIA_TTL` segundos
     * por recorte de conta, para abrir e fechar a tela não refazer as leituras.
     *
     * @return array{lida_em: string, dias_do_ciclo: int, itens: list<array>, fora_do_ar: int, encerrados: int, fora: int, acima_do_teto: int}
     */
    public function previa(?string $soConta = null): array
    {
        return Cache::remember('publicador:jardinagem:previa:'.($soConta ?? 'todas'), self::PREVIA_TTL, function () use ($soConta) {
            $s = $this->situacao($soConta);
            $todos = [...$s['devidos'], ...$s['em_observacao']];
            $linhas = array_slice($todos, 0, self::PREVIA_TETO);
            $lidos = collect($this->analisar($linhas, gravar: false))->keyBy('ml_item_id');

            $itens = array_map(function (array $l) use ($lidos) {
                /** @var PubPublicacaoItem $item */
                $item = $l['item'];
                $r = (array) ($lidos->get((string) $item->ml_item_id) ?? ['resultado' => 'erro', 'erro' => 'O Mercado Livre não respondeu.']);

                return [
                    'ml_item_id' => (string) $item->ml_item_id,
                    'listing_type_id' => (string) $item->listing_type_id,
                    'titulo' => $item->payload['family_name'] ?? $item->payload['title'] ?? null,
                    'permalink' => $item->avisos['estado']['permalink'] ?? null,
                    'conta' => $l['conta_chave'],
                    'mes' => $l['ciclo'],
                    'inicio' => $l['inicio'],
                    'dias_passados' => $l['dias_passados'],
                    'resultado' => (string) ($r['resultado'] ?? 'erro'),
                    'visitas' => $r['visitas'] ?? null,
                    'vendas' => $r['vendas'] ?? null,
                    'conversao' => $r['conversao'] ?? null,
                    'diagnostico' => $r['diagnostico'] ?? null,
                    'erro' => $r['erro'] ?? null,
                ];
            }, $linhas);

            return [
                'lida_em' => now()->toIso8601String(),
                'dias_do_ciclo' => self::diasDoCiclo(),
                'itens' => $itens,
                'fora_do_ar' => $s['fora_do_ar'],
                'encerrados' => $s['encerrados'],
                'fora' => $s['fora'],
                'acima_do_teto' => max(0, count($todos) - count($linhas)),
            ];
        });
    }

    /**
     * Os dois GETs por anúncio: o multiget (status, `sold_quantity`, título) e as visitas da janela.
     *
     * @return array<string, array{status: string, vendidos: int, visitas: int, titulo: ?string, listing_type_id: ?string}|array{erro: string}>
     */
    private function lerNoMl(ContaMercadoLivre $conta, array $linhas, CarbonImmutable $hoje): array
    {
        $saida = [];
        $porMlb = [];
        foreach ($linhas as $d) {
            $porMlb[(string) $d['item']->ml_item_id] = $d;
        }

        // 1) multiget, em pedaços de 20.
        $corpos = [];
        foreach (array_chunk(array_keys($porMlb), self::IDS_POR_MULTIGET) as $ids) {
            try {
                $resposta = $this->ml->get($conta, '/items', ['ids' => implode(',', $ids), 'attributes' => 'id,status,sold_quantity,title,listing_type_id']);
                foreach ((array) $resposta as $r) {
                    $corpo = (array) ($r['body'] ?? []);
                    if ((int) ($r['code'] ?? 0) === 200 && isset($corpo['id'])) {
                        $corpos[(string) $corpo['id']] = $corpo;
                    }
                }
            } catch (\Throwable $e) {
                foreach ($ids as $id) {
                    $saida[$id] = ['erro' => 'Leitura do anúncio falhou: '.$e->getMessage()];
                }
            }
        }

        // 2) visitas: só de quem está no ar (o resto nem precisa).
        $pedidos = [];
        foreach ($porMlb as $mlb => $d) {
            if (isset($corpos[$mlb]) && ($corpos[$mlb]['status'] ?? null) === 'active') {
                $dias = min(self::DIAS_MAX_DE_VISITAS, max(1, (int) CarbonImmutable::createFromFormat('!Y-m-d', $d['inicio'], self::FUSO)->diffInDays($hoje) + 1));
                $pedidos[$mlb] = ["/items/{$mlb}/visits/time_window", ['last' => $dias, 'unit' => 'day']];
            }
        }
        $visitas = $pedidos === [] ? [] : $this->ml->getMany($conta, $pedidos);

        foreach ($porMlb as $mlb => $d) {
            if (isset($saida[$mlb])) {
                continue;
            }
            $corpo = $corpos[$mlb] ?? null;
            if ($corpo === null) {
                $saida[$mlb] = ['erro' => 'O Mercado Livre não devolveu o anúncio.'];

                continue;
            }
            $status = (string) ($corpo['status'] ?? '');
            $base = ['status' => $status, 'titulo' => isset($corpo['title']) ? (string) $corpo['title'] : null,
                'listing_type_id' => isset($corpo['listing_type_id']) ? (string) $corpo['listing_type_id'] : null];
            if ($status !== 'active') {
                $saida[$mlb] = [...$base, 'vendidos' => 0, 'visitas' => 0];

                continue;
            }
            if (! isset($corpo['sold_quantity']) || ! is_numeric($corpo['sold_quantity'])) {
                $saida[$mlb] = ['erro' => 'O Mercado Livre não informou as vendas do anúncio.'];

                continue;
            }
            $v = $visitas[$mlb] ?? null;
            if (! is_array($v)) {
                $saida[$mlb] = ['erro' => 'Leitura das visitas falhou'.($v instanceof \Throwable ? ': '.$v->getMessage() : '.')];

                continue;
            }

            // Só os dias INTEIROS da janela: do começo do mês até ontem (hoje, pela metade, fica para o próximo).
            $deHoje = $hoje->format('Y-m-d');
            $soma = 0;
            foreach (AnunciosMercadoLivreService::serieDeVisitas($v) as $dia) {
                if ($dia['data'] >= $d['inicio'] && $dia['data'] < $deHoje) {
                    $soma += $dia['visitas'];
                }
            }

            $saida[$mlb] = [...$base, 'vendidos' => (int) $corpo['sold_quantity'], 'visitas' => $soma];
        }

        return $saida;
    }

    /** Grava o mês do anúncio (idempotente pelo unique `ml_item_id + ciclo`). */
    private function gravar(array $d, array $lido, array $analise, CarbonImmutable $hoje): PubJardinagem
    {
        /** @var PubPublicacaoItem $item */
        $item = $d['item'];
        $publicacao = $d['publicacao'];
        $rascunho = PubRascunho::with('produto:id,company_id,mlb_empresa_id')->find($publicacao?->rascunho_id);
        $produto = $rascunho?->produto;
        $titulo = $lido['titulo'] ?? ($item->payload['family_name'] ?? $item->payload['title'] ?? null);
        $semTracao = $analise['diagnostico'] === PubJardinagem::SEM_TRACAO;

        $linha = DB::transaction(function () use ($d, $item, $publicacao, $rascunho, $produto, $titulo, $lido, $analise, $hoje, $semTracao) {
            $existente = PubJardinagem::query()->where('ml_item_id', $item->ml_item_id)->where('ciclo', $d['ciclo'])->first();
            if ($existente !== null) {
                return $existente;
            }

            // O mês anterior ainda aberto sai da fila: o diagnóstico novo é o que vale.
            PubJardinagem::query()->where('ml_item_id', $item->ml_item_id)->abertas()->update(['status' => PubJardinagem::SUBSTITUIDA]);

            return PubJardinagem::create([
                'ml_item_id' => (string) $item->ml_item_id,
                'ciclo' => (int) $d['ciclo'],
                'status' => PubJardinagem::PENDENTE,
                'diagnostico' => $analise['diagnostico'],
                'publicacao_item_id' => $item->id,
                'publicacao_id' => $publicacao?->id,
                'produto_id' => $produto?->id,
                'rascunho_id' => $rascunho?->id,
                'company_id' => $produto?->company_id,
                'mlb_empresa_id' => $produto?->mlb_empresa_id,
                'conta_chave' => mb_substr((string) $d['conta_chave'], 0, 60),
                'listing_type_id' => $lido['listing_type_id'] ?? $item->listing_type_id,
                'titulo' => $titulo !== null ? mb_substr((string) $titulo, 0, 255) : null,
                'janela_inicio' => $d['inicio'],
                'janela_fim' => $hoje->format('Y-m-d'),
                'visitas' => (int) $analise['visitas'],
                'vendas' => (int) $analise['vendas'],
                'vendidos_total' => (int) $lido['vendidos'],
                'conversao' => $analise['conversao'],
                'checklist' => PubJardinagem::checklistInicial($analise['diagnostico']),
                'proposta' => $semTracao ? ['estado' => 'gerando', 'titulo_atual' => $titulo, 'titulo_novo' => null,
                    'termos_novos' => [], 'em' => now()->toIso8601String(), 'erro' => null] : null,
            ]);
        });

        if ($linha->wasRecentlyCreated) {
            Log::info("[Publicador Jardinagem] {$linha->ml_item_id} ciclo {$linha->ciclo}: {$linha->diagnostico} "
                ."({$linha->visitas} visitas, {$linha->vendas} vendas, janela {$linha->janela_inicio} a {$linha->janela_fim}).");
            if ($semTracao) {
                // Sai só depois do commit: o Job acha a linha gravada.
                DB::afterCommit(fn () => GerarPropostaDaJardinagemJob::dispatch($linha->id));
            }
        }

        return $linha;
    }

    // ═══ Ações da fila ══════════════════════════════════════════════════════

    /** Marca um item do checklist: feito, não se aplica (motivo obrigatório) ou de volta a pendente. */
    public function marcar(PubJardinagem $j, string $chave, string $estado, ?string $motivo, User $u): PubJardinagem
    {
        if (! in_array($estado, PubJardinagem::ESTADOS_DO_ITEM, true)) {
            throw new RegraViolada('JARD-ESTADO', 'Escolha feito, não se aplica ou pendente.');
        }
        $motivo = trim((string) $motivo);
        if ($estado === PubJardinagem::ITEM_NAO_SE_APLICA && $motivo === '') {
            throw new RegraViolada('JARD-MOTIVO', 'Diga por que não se aplica.');
        }

        return $this->sobTrava($j, function (PubJardinagem $travada) use ($chave, $estado, $motivo, $u) {
            $this->exigirAberta($travada);
            $checklist = $travada->checklistCompleto();
            if (! array_key_exists($chave, $checklist)) {
                throw new RegraViolada('JARD-ITEM', 'Este item não é do diagnóstico deste anúncio.');
            }
            $checklist[$chave] = $estado === PubJardinagem::ITEM_PENDENTE
                ? ['estado' => PubJardinagem::ITEM_PENDENTE, 'motivo' => null, 'por' => null, 'em' => null]
                : [
                    'estado' => $estado,
                    'motivo' => $estado === PubJardinagem::ITEM_NAO_SE_APLICA ? mb_substr($motivo, 0, self::MOTIVO_MAX) : null,
                    'por' => ['id' => $u->id, 'nome' => (string) $u->name],
                    'em' => now()->toIso8601String(),
                ];

            $campos = ['checklist' => $checklist];
            if ($estado !== PubJardinagem::ITEM_PENDENTE) {
                if ($travada->status === PubJardinagem::PENDENTE) {
                    $campos['status'] = PubJardinagem::EM_ANDAMENTO;
                }
                $campos['iniciada_em'] = $travada->iniciada_em ?? now();
                $campos['responsavel_id'] = $travada->responsavel_id ?? $u->id;
            }
            $travada->update($campos);

            return $travada;
        });
    }

    /** "Concluir": só com todos os itens feitos ou "não se aplica". */
    public function concluir(PubJardinagem $j, User $u): PubJardinagem
    {
        return $this->sobTrava($j, function (PubJardinagem $travada) use ($u) {
            $this->exigirAberta($travada);
            if (! $travada->checklistResolvido()) {
                throw new RegraViolada('JARD-INCOMPLETA', 'Para concluir, marque cada item como feito ou "não se aplica".');
            }
            $travada->update([
                'status' => PubJardinagem::FEITA,
                'concluida_em' => now(),
                'iniciada_em' => $travada->iniciada_em ?? now(),
                'responsavel_id' => $travada->responsavel_id ?? $u->id,
            ]);

            return $travada;
        });
    }

    /** A observação vale em qualquer status (é o registro do que foi feito no mês). */
    public function observar(PubJardinagem $j, ?string $texto): PubJardinagem
    {
        $texto = trim((string) $texto);

        return $this->sobTrava($j, function (PubJardinagem $travada) use ($texto) {
            $travada->update(['observacao' => $texto === '' ? null : mb_substr($texto, 0, self::OBSERVACAO_MAX)]);

            return $travada;
        });
    }

    /** Pede outro título para a nova oferta (só no anúncio sem tração e com a linha aberta). */
    public function pedirOutraProposta(PubJardinagem $j): PubJardinagem
    {
        $j = $this->sobTrava($j, function (PubJardinagem $travada) {
            $this->exigirAberta($travada);
            if ($travada->diagnostico !== PubJardinagem::SEM_TRACAO) {
                throw new RegraViolada('JARD-PROPOSTA', 'O título novo é só para o anúncio sem tração (nova oferta).');
            }
            if (($travada->proposta['estado'] ?? null) === 'gerando') {
                throw new RegraViolada('JARD-GERANDO', 'O título novo já está sendo gerado.');
            }
            $travada->update(['proposta' => [...(array) $travada->proposta, 'estado' => 'gerando', 'em' => now()->toIso8601String(), 'erro' => null]]);

            return $travada;
        });
        DB::afterCommit(fn () => GerarPropostaDaJardinagemJob::dispatch($j->id));

        return $j;
    }

    private function exigirAberta(PubJardinagem $j): void
    {
        if (! $j->aberta()) {
            throw new RegraViolada('JARD-FECHADA', $j->status === PubJardinagem::SUBSTITUIDA
                ? 'Este mês já foi substituído pela análise seguinte.'
                : 'Esta Jardinagem já foi concluída.');
        }
    }

    /** Lê e grava a linha na mesma transação, sob `FOR UPDATE` (o checklist é um JSON só). */
    private function sobTrava(PubJardinagem $j, \Closure $fn): PubJardinagem
    {
        return DB::transaction(function () use ($j, $fn) {
            $travada = PubJardinagem::query()->whereKey($j->id)->lockForUpdate()->firstOrFail();

            return $fn($travada);
        });
    }
}
