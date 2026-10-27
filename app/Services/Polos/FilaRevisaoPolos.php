<?php

namespace App\Services\Polos;

use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\PoloAdsBonificacao;
use App\Models\PoloAdsStatus;
use App\Models\PolosAgendaRevisao;
use App\Models\PolosComentario;
use App\Models\PolosRevisao;
use App\Models\User;
use App\Support\CustId;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fila de revisão quinzenal dos Polos (TKT-0027, DEV-53).
 *
 * Cada empresa ativa (M1–M4) é revisada pelo seu responsável a cada 10 dias úteis, num dia
 * fixo do ciclo: Seg A … Sex A, Seg B … Sex B. No dia dela, a empresa entra na fila; se não
 * for feita, fica como atrasada até ser marcada; sem dia definido, aparece todo dia como
 * "sem agenda". Sábado e domingo não têm fila.
 *
 * O que não é óbvio:
 *  - **Responsável = o do Painel Polos**: o da ficha (`mlb_implementacoes.responsavel_id`) e,
 *    sem ficha, o da empresa — a mesma regra da lista enxuta do painel (painelSellers).
 *  - **O ciclo é ancorado numa segunda** (`polos_ciclo_inicio` em configuracoes; padrão
 *    26/10/2026, a Seg A do protótipo). Semanas pares a partir dela = A, ímpares = B.
 *  - **Atrasada** = o dia dela já passou NESTE ciclo sem revisão, ou a última revisão tem mais
 *    de 15 dias (ver situacao()). A contagem de dias parte do PRIMEIRO dia previsto perdido
 *    depois da última revisão.
 *  - **Bônus = `manual-credits` da conta** (os R$ 3.000), da leitura diária da Adman
 *    (`polos:ads-bonus`, 08:15). A Adman só devolve bônus com saldo: bônus usado até o fim
 *    some da lista e a empresa aparece "sem bônus".
 *  - Sinais que dependem de dado que o sistema não tem (ACOS, vendas por Ads, anúncios
 *    ativos, promoções) ficaram de fora em 27/10 — exigiriam leitura nova da Adman.
 */
class FilaRevisaoPolos
{
    public const FASES = ['M1', 'M2', 'M3', 'M4'];

    /** Índice = `dia_ciclo`. */
    public const DIAS = ['Seg A', 'Ter A', 'Qua A', 'Qui A', 'Sex A', 'Seg B', 'Ter B', 'Qua B', 'Qui B', 'Sex B'];

    public const CICLO_INICIO_PADRAO = '2026-10-26';

    public const SITUACAO_ATRASADA   = 'atrasada';
    public const SITUACAO_AGENDA     = 'agenda_hoje';
    public const SITUACAO_SEM_AGENDA = 'sem_agenda';

    /** Programa da Adman do bônus mensal de R$ 3.000. */
    public const PROGRAMA_BONUS = 'manual-credits';

    private const TZ = 'America/Sao_Paulo';

    private const DIAS_SEMANA = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

    private const MESES = [1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho',
        7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro'];

    // ═══ Ciclo ═══

    /** Segunda-feira que abre a Semana A. */
    public static function cicloInicio(): CarbonImmutable
    {
        $raw = (string) Configuracao::get('polos_ciclo_inicio', self::CICLO_INICIO_PADRAO);
        $dia = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : self::CICLO_INICIO_PADRAO;

        return self::data($dia)->startOfWeek(CarbonInterface::MONDAY);
    }

    /** 0–9 (Seg A … Sex B), ou null no sábado e no domingo. */
    public static function diaDoCiclo(CarbonInterface $dia, ?CarbonInterface $inicio = null): ?int
    {
        $d   = self::data($dia->toDateString());
        $dow = $d->dayOfWeekIso;
        if ($dow > 5) {
            return null;
        }

        $inicio = self::data(($inicio ?? self::cicloInicio())->toDateString());
        // Datas em UTC à meia-noite: a diferença em segundos é múltiplo exato de um dia.
        $dias    = intdiv($d->getTimestamp() - $inicio->getTimestamp(), 86400);
        $semanas = (int) floor($dias / 7);
        $semanaB = (($semanas % 2) + 2) % 2 === 1;

        return ($semanaB ? 5 : 0) + ($dow - 1);
    }

    /** Último dia (≤ $hoje) que cai no dia do ciclo informado. */
    public static function ultimaOcorrencia(int $diaCiclo, CarbonInterface $hoje, ?CarbonInterface $inicio = null): CarbonImmutable
    {
        $d = self::data($hoje->toDateString());
        for ($i = 0; $i < 14; $i++) {
            if (self::diaDoCiclo($d, $inicio) === $diaCiclo) {
                return $d;
            }
            $d = $d->subDay();
        }

        return $d; // inalcançável: todo dia útil do ciclo aparece em 14 dias corridos
    }

    /** Primeiro dia DEPOIS de $depoisDe que cai no dia do ciclo informado. */
    public static function proximaOcorrencia(int $diaCiclo, CarbonInterface $depoisDe, ?CarbonInterface $inicio = null): CarbonImmutable
    {
        $d = self::data($depoisDe->toDateString())->addDay();
        for ($i = 0; $i < 14; $i++) {
            if (self::diaDoCiclo($d, $inicio) === $diaCiclo) {
                return $d;
            }
            $d = $d->addDay();
        }

        return $d;
    }

    /** "Seg · Semana A" — o rótulo do cabeçalho. */
    public static function rotuloDoDia(?int $diaCiclo): ?string
    {
        if ($diaCiclo === null) {
            return null;
        }

        return substr(self::DIAS[$diaCiclo], 0, 3) . ' · Semana ' . ($diaCiclo < 5 ? 'A' : 'B');
    }

    // ═══ Roster e responsável ═══

    /**
     * Empresas que entram na fila: ativas (não arquivadas), do projeto POLOS, em M1–M4.
     * Churn, Protocolo Churn e Encerrado são fases e por isso já ficam de fora.
     *
     * @return Collection<int, MlbEmpresa>
     */
    public function roster(): Collection
    {
        return MlbEmpresa::ativas()
            ->whereIn('fase', self::FASES)
            ->with(['responsavel:id,name', 'implementacao:id,empresa_id,responsavel_id,places', 'implementacao.responsavel:id,name'])
            ->orderBy('nome')
            ->get()
            // Projeto canônico: coluna `projeto`, com fallback por fase (empresas antigas).
            ->filter(fn (MlbEmpresa $e) => (($e->getAttributes()['projeto'] ?? null) ?: (MlbEmpresa::FASE_PARA_PROJETO[$e->fase ?? ''] ?? null)) === 'POLOS')
            ->values();
    }

    /** Responsável do Painel Polos: o da ficha; sem ficha, o da empresa. */
    public static function responsavelId(MlbEmpresa $e): ?int
    {
        $id = $e->implementacao ? $e->implementacao->responsavel_id : $e->responsavel_id;

        return $id === null ? null : (int) $id;
    }

    public static function responsavelNome(MlbEmpresa $e): ?string
    {
        return $e->implementacao ? $e->implementacao->responsavel?->name : $e->responsavel?->name;
    }

    /** Empresas do roster cujo responsável é o analista. */
    public function carteira(int $analistaId): Collection
    {
        return $this->roster()->filter(fn (MlbEmpresa $e) => self::responsavelId($e) === $analistaId)->values();
    }

    /**
     * Quem tem empresa atribuída no roster — o seletor de analista.
     *
     * @return array<int, array{id:int, nome:string, total:int}>
     */
    public function analistas(): array
    {
        return $this->roster()
            ->filter(fn (MlbEmpresa $e) => self::responsavelId($e) !== null)
            ->groupBy(fn (MlbEmpresa $e) => self::responsavelId($e))
            ->map(fn (Collection $grupo, $id) => [
                'id'    => (int) $id,
                'nome'  => self::responsavelNome($grupo->first()) ?? "Usuário {$id}",
                'total' => $grupo->count(),
            ])
            ->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Responsável de cada empresa, por id — a coluna "Responsável" de /polos/empresas.
     *
     * @param  array<int, int|null>  $ids  mlb_empresa_id
     * @return array<int, array{id:int, nome:string}>
     */
    public static function responsaveisPorEmpresa(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        return MlbEmpresa::whereIn('id', $ids)
            ->with(['responsavel:id,name', 'implementacao:id,empresa_id,responsavel_id', 'implementacao.responsavel:id,name'])
            ->get()
            ->mapWithKeys(function (MlbEmpresa $e) {
                $id = self::responsavelId($e);

                return $id === null ? [] : [$e->id => ['id' => $id, 'nome' => self::responsavelNome($e) ?? "Usuário {$id}"]];
            })
            ->all();
    }

    // ═══ A fila do dia ═══

    /**
     * Monta a tela "Minha fila de hoje" de um analista.
     *
     * @param  array<int, array<string,mixed>>  $faturamento  Linhas do Faturamento Polos do mês
     *         corrente por mlb_empresa_id (as MESMAS de /polos/empresas); [] = sem dado.
     * @return array{hoje: array, resumo: array, pendentes: array, feitas: array}
     */
    public function montar(int $analistaId, CarbonInterface $hoje, array $faturamento = []): array
    {
        $hoje     = self::data($hoje->toDateString());
        $inicio   = self::cicloInicio();
        $diaHoje  = self::diaDoCiclo($hoje, $inicio);
        $empresas = $this->carteira($analistaId);
        $cards    = $this->cards($empresas, $hoje, $faturamento);

        $ids      = $empresas->pluck('id')->all();
        $agenda   = PolosAgendaRevisao::whereIn('mlb_empresa_id', $ids)->pluck('dia_ciclo', 'mlb_empresa_id');
        $ultimas  = PolosRevisao::whereIn('mlb_empresa_id', $ids)
            ->selectRaw('mlb_empresa_id, MAX(data) as ultima')
            ->groupBy('mlb_empresa_id')
            ->get()
            ->pluck('ultima', 'mlb_empresa_id');
        $deHoje   = PolosRevisao::with(['usuario:id,name', 'comentario'])
            ->whereIn('mlb_empresa_id', $ids)
            ->whereDate('data', $hoje->toDateString())
            ->get()
            ->keyBy('mlb_empresa_id');

        $pendentes = [];
        $feitas    = [];

        foreach ($empresas as $e) {
            $card = $cards[$e->id];
            $dia  = isset($agenda[$e->id]) ? (int) $agenda[$e->id] : null;
            $card['dia_ciclo'] = $dia;
            $card['dia_label'] = $dia === null ? null : self::DIAS[$dia];

            $revisao = $deHoje[$e->id] ?? null;
            if ($revisao !== null) {
                $card['revisao'] = [
                    'por'        => $revisao->usuario?->name ?? 'Usuário removido',
                    'hora'       => $revisao->revisado_em?->setTimezone(self::TZ)->format('H:i'),
                    'comentario' => $revisao->comentario?->texto,
                    'proxima'    => $dia === null ? null : self::proximaOcorrencia($dia, $hoje, $inicio)->format('d/m'),
                ];
                $feitas[] = $card;
                continue;
            }

            // Fim de semana não tem fila.
            if ($diaHoje === null) {
                continue;
            }

            $situacao = $this->situacao($dia, $hoje, $inicio, $ultimas[$e->id] ?? null);
            if ($situacao === null) {
                continue;
            }

            $card['situacao']    = $situacao['situacao'];
            $card['dias_atraso'] = $situacao['dias_atraso'];
            $pendentes[]         = $card;
        }

        usort($pendentes, function (array $a, array $b) {
            $aAtr = $a['situacao'] === self::SITUACAO_ATRASADA;
            $bAtr = $b['situacao'] === self::SITUACAO_ATRASADA;
            if ($aAtr !== $bAtr) {
                return $aAtr ? -1 : 1;
            }
            if ($aAtr && $a['dias_atraso'] !== $b['dias_atraso']) {
                return $b['dias_atraso'] <=> $a['dias_atraso'];
            }
            $vermelhos = fn (array $c) => count(array_filter($c['sinais'], fn ($s) => $s['cor'] === 'vermelho'));
            if ($vermelhos($a) !== $vermelhos($b)) {
                return $vermelhos($b) <=> $vermelhos($a);
            }

            return ($a['pct'] ?? 0) <=> ($b['pct'] ?? 0) ?: strcasecmp($a['nome'], $b['nome']);
        });

        usort($feitas, fn ($a, $b) => strcmp((string) $b['revisao']['hora'], (string) $a['revisao']['hora']));

        $contar = fn (string $s) => count(array_filter($pendentes, fn ($c) => $c['situacao'] === $s));

        // Vencimento do bônus do mês: o mais próximo entre as empresas da carteira.
        $vence = collect($cards)->pluck('bonus')->filter()->sortBy('vence_em')->first();

        return [
            'hoje' => [
                'data'       => $hoje->format('d/m/Y'),
                'dia_semana' => self::DIAS_SEMANA[$hoje->dayOfWeekIso],
                'dia_ciclo'  => $diaHoje,
                'rotulo'     => self::rotuloDoDia($diaHoje),
            ],
            'resumo' => [
                'para_revisar'            => count($pendentes),
                'atrasadas'               => $contar(self::SITUACAO_ATRASADA),
                'agenda_hoje'             => $contar(self::SITUACAO_AGENDA),
                'sem_agenda'              => $contar(self::SITUACAO_SEM_AGENDA),
                'feitas'                  => count($feitas),
                'total_dia'               => count($pendentes) + count($feitas),
                'bonus_parado'            => round(array_sum(array_map(
                    fn ($c) => ($c['bonus']['ritmo']['tipo'] ?? null) === 'parado' ? $c['bonus']['saldo'] : 0,
                    $pendentes,
                )), 2),
                'comentarios_a_atualizar' => count(array_filter($pendentes, fn ($c) => $c['desatualizado'])),
                'bonus_vence'             => $vence ? ['data' => self::data($vence['vence_em'])->format('d/m'), 'dias' => $vence['dias']] : null,
                'carteira'                => $empresas->count(),
            ],
            'pendentes' => $pendentes,
            'feitas'    => $feitas,
        ];
    }

    /**
     * Por que a empresa está na fila hoje — ou null se não está.
     *
     * Atrasada, como a spec escreveu: o dia dela **já passou neste ciclo** sem revisão, ou a
     * última revisão tem **mais de 15 dias**. "Neste ciclo" importa: na terça da Semana A, a
     * empresa da Qui A ainda vai entrar na quinta — a Qui A do ciclo anterior não a atrasa
     * (o que acontecia com quem acabou de ganhar dia na agenda).
     *
     * @return array{situacao:string, dias_atraso:?int}|null
     */
    public function situacao(?int $dia, CarbonInterface $hoje, ?CarbonInterface $inicio = null, ?string $ultimaRevisao = null): ?array
    {
        $hoje    = self::data($hoje->toDateString());
        $diaHoje = self::diaDoCiclo($hoje, $inicio);
        if ($diaHoje === null) {
            return null;
        }

        if ($dia === null) {
            return ['situacao' => self::SITUACAO_SEM_AGENDA, 'dias_atraso' => null];
        }

        if ($dia === $diaHoje) {
            return ['situacao' => self::SITUACAO_AGENDA, 'dias_atraso' => null];
        }

        $prevista = self::ultimaOcorrencia($dia, $hoje, $inicio);
        $ultima   = $ultimaRevisao ? self::data(substr($ultimaRevisao, 0, 10)) : null;

        if ($ultima !== null && $ultima->greaterThanOrEqualTo($prevista)) {
            return null; // feita desde o último dia previsto: volta no próximo ciclo
        }

        $passouNesteCiclo = $dia < $diaHoje;
        $revisaoVelha     = $ultima !== null && intdiv($hoje->getTimestamp() - $ultima->getTimestamp(), 86400) > 15;
        if (! $passouNesteCiclo && ! $revisaoVelha) {
            return null; // o dia dela ainda vem neste ciclo
        }

        // Conta do primeiro dia previsto perdido depois da última revisão.
        $perdida = $ultima !== null ? self::proximaOcorrencia($dia, $ultima, $inicio) : $prevista;
        if ($perdida->greaterThan($prevista)) {
            $perdida = $prevista;
        }

        return [
            'situacao'    => self::SITUACAO_ATRASADA,
            'dias_atraso' => intdiv($hoje->getTimestamp() - $perdida->getTimestamp(), 86400),
        ];
    }

    /**
     * O cartão de cada empresa: números do mês, bônus, sinais e último comentário.
     *
     * @param  Collection<int, MlbEmpresa>  $empresas
     * @return array<int, array<string,mixed>>  por mlb_empresa_id
     */
    public function cards(Collection $empresas, CarbonInterface $hoje, array $faturamento = []): array
    {
        $hoje  = self::data($hoje->toDateString());
        $custs = $empresas->map(fn (MlbEmpresa $e) => CustId::normaliza((string) $e->cust_id))->filter()->unique()->values()->all();

        $bonus      = PoloAdsBonificacao::frescos()->whereIn('cust_id', $custs)->get()->keyBy('cust_id');
        $leiturasAd = PoloAdsStatus::frescos()->whereIn('cust_id', $custs)->get()->keyBy('cust_id');
        $vinculo    = VinculoAdmanPolos::carregar();
        $comentarios = PolosComentario::with('autor:id,name')
            ->whereIn('cust_id', $custs)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('cust_id');

        $mesAtual = $hoje->format('Ym');
        $mesNome  = self::MESES[$hoje->month];

        $cards = [];
        foreach ($empresas as $e) {
            $cust = CustId::normaliza((string) $e->cust_id);
            $fat  = $faturamento[$e->id] ?? null;

            $b = $cust !== '' ? self::bonusDoMes($bonus[$cust] ?? null, $hoje) : null;

            $campanha = $fat['ads_campanha']
                ?? PoloAdsStatus::situacaoDaEmpresa($leiturasAd[$cust] ?? null, $e->ads_desligado === null ? null : (bool) $e->ads_desligado);

            $coms    = $cust !== '' ? ($comentarios[$cust] ?? collect()) : collect();
            $ultimo  = $coms->first();
            $doMes   = $coms->filter(fn (PolosComentario $c) => (string) $c->mes === $mesAtual);

            $pct  = isset($fat['pct']) && ($fat['meta'] ?? 0) > 0 ? (float) $fat['pct'] : null;
            $card = [
                'id'          => $e->id,
                'nome'        => $e->nome,
                'polo'        => $fat['polo'] ?? ($e->polo ?: null),
                'fase'        => $e->fase,
                'cust_id'     => $cust !== '' ? $cust : null,
                'faturamento' => $fat !== null ? (float) ($fat['faturamento'] ?? 0) : null,
                'meta'        => $fat !== null ? (float) ($fat['meta'] ?? 0) : null,
                'pct'         => $pct,
                'investimento' => $fat !== null ? (float) ($fat['ads'] ?? 0) : null,
                'mes_nome'    => $mesNome,
                'bonus'       => $b,
                'campanha'    => $campanha,
                'conta_nao_vinculada' => $vinculo->status($e->fase, $e->cust_id) === VinculoAdmanPolos::NAO_VINCULADO,
                'ultimo_comentario' => $ultimo ? [
                    'texto'  => $ultimo->texto,
                    'autor'  => $ultimo->autorNome(),
                    'em'     => $ultimo->created_at?->setTimezone(self::TZ)->format('d/m/Y'),
                    'do_mes' => (string) $ultimo->mes === $mesAtual,
                ] : null,
                'desatualizado' => $ultimo !== null
                    && self::contem($ultimo->texto, 'sem bonus')
                    && ($b['saldo'] ?? 0) > 0,
                'situacao'    => null,
                'dias_atraso' => null,
                'revisao'     => null,
            ];

            $card['sinais'] = self::sinais($card, $e->implementacao?->places, $doMes->pluck('texto')->all());
            $cards[$e->id]  = $card;
        }

        return $cards;
    }

    /**
     * O bônus de R$ 3.000 do mês (manual-credits da conta) e o ritmo de gasto até vencer.
     *
     * @return array{valor:float, saldo:float, usado:float, pct_usado:float, vence_em:string, dias:int, ritmo:array{tipo:string, texto:string}}|null
     */
    public static function bonusDoMes(?PoloAdsBonificacao $leitura, CarbonInterface $hoje): ?array
    {
        if ($leitura === null) {
            return null;
        }

        $itens = array_values(array_filter($leitura->itens ?? [], function ($i) use ($hoje) {
            return ($i['programa'] ?? '') === self::PROGRAMA_BONUS
                && ($i['nivel'] ?? 'account') === 'account'
                && (float) ($i['saldo'] ?? 0) > 0
                && PoloAdsBonificacao::diasRestantes((string) ($i['vence_em'] ?? ''), CarbonImmutable::parse($hoje->toDateString(), self::TZ)) > 0;
        }));
        if ($itens === []) {
            return null;
        }

        usort($itens, fn ($a, $b) => $a['vence_em'] <=> $b['vence_em']);

        $valor = round(array_sum(array_map(fn ($i) => (float) ($i['valor'] ?? 0), $itens)), 2);
        $saldo = round(array_sum(array_map(fn ($i) => (float) ($i['saldo'] ?? 0), $itens)), 2);
        $usado = max(round($valor - $saldo, 2), 0.0);
        $pct   = $valor > 0 ? round($usado / $valor * 100, 1) : 0.0;
        $vence = (string) $itens[0]['vence_em'];
        $dias  = PoloAdsBonificacao::diasRestantes($vence, CarbonImmutable::parse($hoje->toDateString(), self::TZ));
        $ate   = self::data($vence)->format('d/m');
        $porDia = $dias > 0 ? $saldo / $dias : $saldo;

        $ritmo = match (true) {
            $pct < 20     => ['tipo' => 'parado', 'texto' => 'Parado: precisa de ' . self::brl($porDia) . "/dia até {$ate}"],
            $saldo <= 50  => ['tipo' => 'esgotado', 'texto' => 'Bônus esgotado: investimento agora é do seller'],
            default       => ['tipo' => 'ritmo', 'texto' => 'Ritmo ideal: ' . self::brl($porDia) . "/dia até {$ate}"],
        };

        return compact('valor', 'saldo', 'usado') + ['pct_usado' => $pct, 'vence_em' => $vence, 'dias' => $dias, 'ritmo' => $ritmo];
    }

    /**
     * Chips de atenção do cartão. Só os que o sistema tem dado para calcular.
     *
     * @param  array<int, string>  $comentariosDoMes
     * @return array<int, array{chave:string, cor:string, texto:string}>
     */
    public static function sinais(array $card, ?string $places, array $comentariosDoMes): array
    {
        $s     = [];
        $bonus = $card['bonus'];
        $saldo = (float) ($bonus['saldo'] ?? 0);

        if ($bonus !== null && $bonus['pct_usado'] < 20 && $saldo > 2000) {
            $s[] = ['chave' => 'bonus_parado', 'cor' => 'vermelho', 'texto' => 'Bônus parado'];
        }

        if ($card['campanha'] === PoloAdsStatus::CAMPANHA_DESLIGADA && $saldo > 0) {
            $s[] = ['chave' => 'campanha_desligada', 'cor' => 'vermelho', 'texto' => 'Campanha desligada'];
        }

        $p = self::normaliza((string) $places);
        if ($p !== '' && ! in_array($p, ['ativo', 'nao e necessario'], true)) {
            $s[] = ['chave' => 'places', 'cor' => 'vermelho', 'texto' => 'Places: ' . trim((string) $places)];
        }

        $temAcordo = collect($comentariosDoMes)->contains(fn ($t) => self::contem((string) $t, 'acordo'));
        if ($bonus === null && $card['campanha'] === PoloAdsStatus::CAMPANHA_ATIVA && ! $temAcordo) {
            $s[] = ['chave' => 'ads_sem_acordo', 'cor' => 'amarelo', 'texto' => 'Ads sem acordo'];
        }

        $pct = $card['pct'];
        if ($pct !== null && $pct >= 100) {
            $s[] = ['chave' => 'no_alvo', 'cor' => 'verde', 'texto' => 'No alvo'];
        } elseif ($pct !== null && $pct >= 60) {
            $falta = max((float) $card['meta'] - (float) $card['faturamento'], 0);
            $s[] = ['chave' => 'faltam', 'cor' => 'verde', 'texto' => 'Faltam ' . self::brl($falta)];
        }

        return $s;
    }

    // ═══ Gravação ═══

    /** Admin mexe em qualquer empresa; analista, só nas da própria carteira. */
    public static function podeAtuar(User $user, MlbEmpresa $empresa): bool
    {
        return $user->isAdmin() || self::responsavelId($empresa) === $user->id;
    }

    /**
     * Marca a revisão do dia. Com texto, grava o comentário no Faturamento Polos (mês atual)
     * e liga à revisão; sem texto, só a revisão (decisão de 27/10).
     */
    public function marcar(MlbEmpresa $empresa, User $user, ?string $texto, CarbonInterface $agora): PolosRevisao
    {
        $texto = trim((string) $texto);
        $dia   = $agora->copy()->setTimezone(self::TZ)->toDateString();

        return DB::transaction(function () use ($empresa, $user, $texto, $agora, $dia) {
            $comentarioId = null;
            if ($texto !== '') {
                $comentarioId = PolosComentario::create([
                    // Normaliza na escrita: é a mesma chave da lista de /polos/empresas.
                    'cust_id'    => CustId::normaliza((string) $empresa->cust_id),
                    'mes'        => $agora->copy()->setTimezone(self::TZ)->format('Ym'),
                    'user_id'    => $user->id,
                    'autor_nome' => $user->name,
                    'texto'      => $texto,
                ])->id;
            }

            $revisao = PolosRevisao::where('mlb_empresa_id', $empresa->id)->whereDate('data', $dia)->first()
                ?? new PolosRevisao(['mlb_empresa_id' => $empresa->id, 'data' => $dia]);
            $revisao->fill([
                'user_id'             => $user->id,
                'revisado_em'         => $agora,
                'polos_comentario_id' => $comentarioId ?? $revisao->polos_comentario_id,
            ])->save();

            activity('polos')
                ->causedBy($user)
                ->performedOn($empresa)
                ->withProperties(['revisado_em' => $agora->toDateTimeString(), 'user_id' => $user->id, 'comentario_id' => $comentarioId])
                ->log("[Polos] Revisão quinzenal feita (empresa {$empresa->id} — {$empresa->nome})");

            return $revisao;
        });
    }

    /** Desfaz a revisão do dia. O comentário gravado junto continua no Faturamento Polos. */
    public function desmarcar(MlbEmpresa $empresa, User $user, CarbonInterface $agora): bool
    {
        $dia = $agora->copy()->setTimezone(self::TZ)->toDateString();
        $apagadas = PolosRevisao::where('mlb_empresa_id', $empresa->id)->whereDate('data', $dia)->delete();

        if ($apagadas > 0) {
            activity('polos')
                ->causedBy($user)
                ->performedOn($empresa)
                ->withProperties(['data' => $dia])
                ->log("[Polos] Revisão quinzenal desfeita (empresa {$empresa->id} — {$empresa->nome})");
        }

        return $apagadas > 0;
    }

    /**
     * Grava a agenda de um analista. Só mexe nas empresas da carteira dele; dia null limpa.
     *
     * @param  array<int|string, int|null>  $dias  mlb_empresa_id → dia_ciclo (0–9) ou null
     * @return array{alteradas:int, ignoradas:int}
     */
    public function salvarAgenda(int $analistaId, array $dias, User $user): array
    {
        $carteira  = $this->carteira($analistaId)->keyBy('id');
        $atuais    = PolosAgendaRevisao::whereIn('mlb_empresa_id', $carteira->keys())->get()->keyBy('mlb_empresa_id');
        $alteradas = 0;
        $ignoradas = 0;

        DB::transaction(function () use ($dias, $carteira, $atuais, $user, &$alteradas, &$ignoradas) {
            foreach ($dias as $empresaId => $dia) {
                $empresaId = (int) $empresaId;
                if (! $carteira->has($empresaId)) {
                    $ignoradas++;
                    continue;
                }

                $atual = $atuais[$empresaId] ?? null;
                if ($dia === null) {
                    if ($atual) {
                        $atual->delete();
                        $alteradas++;
                    }
                    continue;
                }

                $dia = (int) $dia;
                if ($atual && (int) $atual->dia_ciclo === $dia) {
                    continue;
                }

                PolosAgendaRevisao::updateOrCreate(
                    ['mlb_empresa_id' => $empresaId],
                    ['dia_ciclo' => $dia, 'definido_por' => $user->id],
                );
                $alteradas++;
            }
        });

        if ($alteradas > 0) {
            activity('polos')
                ->causedBy($user)
                ->withProperties(['analista_id' => $analistaId, 'alteradas' => $alteradas])
                ->log("[Polos] Agenda da fila de revisão do analista {$analistaId}: {$alteradas} empresa(s) alterada(s)");
        }

        return ['alteradas' => $alteradas, 'ignoradas' => $ignoradas];
    }

    // ═══ Apoio ═══

    /** Data pura (meia-noite em UTC) — conta de dias sem fuso no caminho. */
    private static function data(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10), 'UTC');
    }

    /** Minúsculas e sem acento: "Sem Bônus" e "sem bonus" são a mesma coisa. */
    private static function normaliza(string $texto): string
    {
        return trim(Str::ascii(mb_strtolower($texto)));
    }

    private static function contem(string $texto, string $trecho): bool
    {
        return str_contains(self::normaliza($texto), $trecho);
    }

    private static function brl(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }
}
