<?php

namespace App\Services\Polos;

use App\Models\MlbEmpresa;
use App\Models\PolosPendenciaConta;
use App\Models\PolosPendenciaContaEvento;
use App\Models\User;
use App\Notifications\PendenciaContaNotification;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Pendências do Analista de Conta dos Polos (TKT-0035, DEV-58).
 *
 * O analista de Ads encaminha, pela fila de revisão, uma pendência que o Analista de Conta
 * resolve na conta do seller (publicar, central de promoção, ajustar produto/anúncio).
 *
 * O que não é óbvio:
 *  - **Paralelo, não divisão de carteira.** Nada aqui escreve em mlb_empresas,
 *    mlb_implementacoes, polos_revisoes ou polos_agenda_revisao: o responsável, a carteira e a
 *    revisão quinzenal (FilaRevisaoPolos) ficam iguais, com ou sem pendência.
 *  - **A empresa está na fila enquanto tiver pendência não encerrada** (encerrada = concluída
 *    ou cancelada). Devolvida e aguardando seller continuam na fila.
 *  - **Quem pode**: encaminhar = admin ou o responsável da empresa (a regra de marcar a
 *    revisão); operar (status, concluir) = admin ou `mlb.analista_conta` — admin faz o papel
 *    da coordenação (decisão de 11/11/2026); ver = quem opera ou quem abre a fila de revisão.
 *  - **Devolvida** pede o que falta. Quando quem pediu comenta numa devolvida, ela volta para
 *    Aberta sozinha — o mesmo que o ticket faz com a resposta do solicitante.
 *  - **Avisos** (sino): quem pediu é avisado na conclusão, na devolução, no cancelamento e no
 *    comentário de outra pessoa; o Analista de Conta que pegou, no comentário de quem pediu.
 *    Ninguém é avisado da própria ação.
 *  - **Duas réguas de atraso (fatia 2).** "Atrasada" = o prazo PEDIDO passou. "Ficou para
 *    trás" = o dia PLANEJADO pelo Analista de Conta passou sem concluir. A lista do dia mostra
 *    as planejadas para hoje, as que ficaram para trás e as atrasadas sem dia planejado.
 *  - **Planejar depois do prazo avisa quem pediu** — um aviso por pessoa a cada planejamento,
 *    mesmo quando várias pendências mudam de uma vez (seleção em massa).
 *  - **Acompanhamento (fatia 3)** é da coordenação e da liderança: admin ou líder de setor.
 */
class PendenciasContaPolos
{
    public const MAX_ANEXOS = 5;

    /** Status que se escolhem na lista; Concluída tem ação própria (pede o que foi feito). */
    public const STATUS_MANUAIS = [
        PolosPendenciaConta::STATUS_ABERTA,
        PolosPendenciaConta::STATUS_EM_ANDAMENTO,
        PolosPendenciaConta::STATUS_AGUARDANDO_SELLER,
        PolosPendenciaConta::STATUS_DEVOLVIDA,
        PolosPendenciaConta::STATUS_CANCELADA,
    ];

    /** Concluídas que a fila de revisão ainda mostra na empresa: "o que foi feito". */
    private const DIAS_CONCLUIDAS_NA_REVISAO = 15;

    /** Janela da visão "Encerradas" da fila do Analista de Conta. */
    private const DIAS_ENCERRADAS = 30;

    private const TZ = 'America/Sao_Paulo';

    // ═══ Quem pode ═══

    public static function podeOperar(User $u): bool
    {
        return $u->isAdmin() || $u->hasPermission(Permissions::MLB_ANALISTA_CONTA);
    }

    public static function podeVer(User $u): bool
    {
        return self::podeOperar($u) || $u->hasPermission(Permissions::MLB_FATURAMENTO_POLOS);
    }

    /** Admin, ou o responsável da empresa com acesso à fila de revisão. */
    public static function podeEncaminhar(User $u, MlbEmpresa $e): bool
    {
        return $u->isAdmin()
            || ($u->hasPermission(Permissions::MLB_FATURAMENTO_POLOS) && FilaRevisaoPolos::podeAtuar($u, $e));
    }

    /** Quem opera, quem pediu e o responsável da empresa. */
    public static function podeComentar(User $u, PolosPendenciaConta $p): bool
    {
        if (self::podeOperar($u) || (int) $p->solicitante_id === $u->id) {
            return true;
        }
        $p->loadMissing('empresa.implementacao:id,empresa_id,responsavel_id');

        return $p->empresa !== null && self::podeEncaminhar($u, $p->empresa);
    }

    /** Visão de acompanhamento: coordenação (admin) e liderança (líder de setor). */
    public static function podeAcompanhar(User $u): bool
    {
        return $u->isAdmin() || $u->hasPermission(Permissions::LIDERANCA_DASHBOARD_SETOR);
    }

    /** Opções dos formulários, na ordem da tela. */
    public static function opcoes(): array
    {
        $par = fn (array $mapa) => array_map(fn ($k, $v) => ['valor' => $k, 'rotulo' => $v], array_keys($mapa), $mapa);

        return [
            'tipos'          => $par(PolosPendenciaConta::TIPOS),
            'prioridades'    => $par(PolosPendenciaConta::PRIORIDADES),
            'status'         => $par(PolosPendenciaConta::STATUS),
            'status_manuais' => self::STATUS_MANUAIS,
        ];
    }

    // ═══ Gravação ═══

    /**
     * Encaminha uma pendência ao Analista de Conta.
     *
     * @param  array{tipo:string, descricao:string, mlbs?:?string, prioridade:string, prazo?:?string}  $dados
     * @param  UploadedFile[]  $arquivos
     */
    public function abrir(MlbEmpresa $empresa, User $quem, array $dados, array $arquivos = []): PolosPendenciaConta
    {
        return DB::transaction(function () use ($empresa, $quem, $dados, $arquivos) {
            $p = PolosPendenciaConta::create([
                'mlb_empresa_id' => $empresa->id,
                'tipo'           => $dados['tipo'],
                'descricao'      => trim($dados['descricao']),
                'mlbs'           => self::mlbs($dados['mlbs'] ?? null) ?: null,
                'prioridade'     => $dados['prioridade'],
                'prazo'          => $dados['prazo'] ?? null,
                'status'         => PolosPendenciaConta::STATUS_ABERTA,
                'solicitante_id' => $quem->id,
            ]);

            $this->evento($p, $quem, PolosPendenciaContaEvento::CRIADA, para: PolosPendenciaConta::STATUS_ABERTA);
            $this->salvarAnexos($p, $quem, $arquivos);

            $this->log($quem, $empresa, 'Pendência encaminhada ao Analista de Conta', ['pendencia_id' => $p->id, 'tipo' => $p->tipo]);

            return $p;
        });
    }

    /**
     * Muda o status (exceto Concluída, que tem ação própria). Devolvida pede `texto`; sair de
     * Concluída (reabrir) limpa o que foi feito — o histórico guarda a conclusão anterior.
     */
    public function mudarStatus(PolosPendenciaConta $p, User $quem, string $status, ?string $texto = null): PolosPendenciaConta
    {
        $texto = trim((string) $texto);
        $de    = $p->status;
        if ($status === $de) {
            if ($texto !== '') {
                $this->comentar($p, $quem, $texto);
            }

            return $p;
        }

        return DB::transaction(function () use ($p, $quem, $status, $texto, $de) {
            $attrs = ['status' => $status];
            if ($de === PolosPendenciaConta::STATUS_CONCLUIDA) {
                $attrs += ['concluida_em' => null, 'conclusao' => null, 'mlbs_alterados' => null];
            }
            if ($status === PolosPendenciaConta::STATUS_EM_ANDAMENTO && $p->analista_id === null) {
                $attrs['analista_id'] = $quem->id;
            }
            $p->fill($attrs)->save();

            $this->evento($p, $quem, PolosPendenciaContaEvento::STATUS, de: $de, para: $status, texto: $texto !== '' ? $texto : null);
            $this->log($quem, $p->empresa, 'Pendência do Analista de Conta: ' . PolosPendenciaConta::STATUS[$de] . ' → ' . PolosPendenciaConta::STATUS[$status],
                ['pendencia_id' => $p->id, 'de' => $de, 'para' => $status]);

            $nome = $p->empresa?->nome ?? 'empresa';
            if ($status === PolosPendenciaConta::STATUS_DEVOLVIDA) {
                $this->avisar($p->solicitante, $quem, $p, "Pendência devolvida: {$nome}", "Falta informação: {$texto}");
            } elseif ($status === PolosPendenciaConta::STATUS_CANCELADA) {
                $this->avisar($p->solicitante, $quem, $p, "Pendência cancelada: {$nome}", $texto !== '' ? $texto : PolosPendenciaConta::TIPOS[$p->tipo] ?? $p->tipo);
            }

            return $p;
        });
    }

    /** Conclui com o que foi feito (texto obrigatório) e os MLBs alterados. Quem pediu é avisado. */
    public function concluir(PolosPendenciaConta $p, User $quem, string $texto, $mlbsAlterados = null): PolosPendenciaConta
    {
        $texto = trim($texto);
        $mlbs  = self::mlbs($mlbsAlterados);

        return DB::transaction(function () use ($p, $quem, $texto, $mlbs) {
            $de = $p->status;
            $p->fill([
                'status'         => PolosPendenciaConta::STATUS_CONCLUIDA,
                'conclusao'      => $texto,
                'mlbs_alterados' => $mlbs ?: null,
                'concluida_em'   => now(),
                'analista_id'    => $quem->id,
            ])->save();

            $this->evento($p, $quem, PolosPendenciaContaEvento::CONCLUIDA, de: $de, para: PolosPendenciaConta::STATUS_CONCLUIDA,
                texto: $texto, meta: $mlbs ? ['mlbs_alterados' => $mlbs] : null);
            $this->log($quem, $p->empresa, 'Pendência do Analista de Conta concluída', ['pendencia_id' => $p->id, 'mlbs_alterados' => $mlbs]);

            $this->avisar($p->solicitante, $quem, $p, 'Pendência concluída: ' . ($p->empresa?->nome ?? 'empresa'), $texto);

            return $p;
        });
    }

    /**
     * Comentário (com anexos). Quem pediu respondendo uma Devolvida a devolve para a fila.
     *
     * @param  UploadedFile[]  $arquivos
     */
    public function comentar(PolosPendenciaConta $p, User $quem, string $texto, array $arquivos = []): PolosPendenciaContaEvento
    {
        $texto = trim($texto);

        return DB::transaction(function () use ($p, $quem, $texto, $arquivos) {
            $ev = $this->evento($p, $quem, PolosPendenciaContaEvento::COMENTARIO, texto: $texto);
            $this->salvarAnexos($p, $quem, $arquivos);

            $ehSolicitante = (int) $p->solicitante_id === $quem->id;
            if ($ehSolicitante && $p->status === PolosPendenciaConta::STATUS_DEVOLVIDA) {
                $p->update(['status' => PolosPendenciaConta::STATUS_ABERTA]);
                $this->evento($p, $quem, PolosPendenciaContaEvento::STATUS,
                    de: PolosPendenciaConta::STATUS_DEVOLVIDA, para: PolosPendenciaConta::STATUS_ABERTA, meta: ['automatico' => true]);
            }

            $nome = $p->empresa?->nome ?? 'empresa';
            $ehSolicitante
                ? $this->avisar($p->analista, $quem, $p, "{$quem->name} comentou a pendência: {$nome}", $texto)
                : $this->avisar($p->solicitante, $quem, $p, "{$quem->name} comentou a pendência: {$nome}", $texto);

            return $ev;
        });
    }

    /**
     * Agenda (fatia 2): define o dia planejado de uma ou várias pendências em aberto — arrastar
     * um cartão ou a seleção em massa. `$data` nulo tira o planejamento ("Sem data"). Encerradas
     * e as que já estão nesse dia são ignoradas. Quem planeja vira o Analista de Conta da
     * pendência se ninguém tinha pegado.
     *
     * Quem pediu é avisado quando o dia cai depois do prazo pedido — um aviso por pessoa, juntando
     * as pendências dela, para a seleção em massa não virar 30 avisos.
     *
     * @param  array<int, int>  $ids
     * @return int  quantas mudaram
     */
    public function planejar(array $ids, ?string $data, User $quem): int
    {
        $data = $data === null || $data === '' ? null : CarbonImmutable::parse($data, self::TZ)->toDateString();
        $ids  = array_values(array_unique(array_map('intval', $ids)));

        return DB::transaction(function () use ($ids, $data, $quem) {
            $depoisDoPrazo = [];
            $mudaram       = [];

            $pendencias = PolosPendenciaConta::with(['empresa:id,nome', 'solicitante'])->abertas()->whereIn('id', $ids)->orderBy('id')->get();
            foreach ($pendencias as $p) {
                $de = $p->data_planejada?->toDateString();
                if ($de === $data) {
                    continue;
                }

                $attrs = ['data_planejada' => $data];
                if ($data !== null && $p->analista_id === null) {
                    $attrs['analista_id'] = $quem->id;
                }
                $p->fill($attrs)->save();

                $this->evento($p, $quem, PolosPendenciaContaEvento::DATA_PLANEJADA, de: $de, para: $data);
                $mudaram[] = $p->id;

                if ($p->planejadaDepoisDoPrazo() && $p->solicitante) {
                    $depoisDoPrazo[$p->solicitante->id][] = $p;
                }
            }

            if ($mudaram !== []) {
                $this->log($quem, null, 'Agenda do Analista de Conta: ' . count($mudaram) . ' pendência(s) ' . ($data ? "planejada(s) para {$data}" : 'sem data'),
                    ['pendencias' => $mudaram, 'data_planejada' => $data]);
            }

            foreach ($depoisDoPrazo as $lista) {
                $this->avisarPlanejadaDepoisDoPrazo($lista, $quem);
            }

            return count($mudaram);
        });
    }

    /**
     * Prioridade (fatia 2): quem pediu sobe ou desce para pedir antecipação — o Analista de Conta
     * é avisado e replaneja. Quando é o Analista de Conta que muda, quem pediu é avisado.
     */
    public function mudarPrioridade(PolosPendenciaConta $p, User $quem, string $prioridade): PolosPendenciaConta
    {
        $de = $p->prioridade;
        if ($de === $prioridade) {
            return $p;
        }

        return DB::transaction(function () use ($p, $quem, $prioridade, $de) {
            $p->update(['prioridade' => $prioridade]);
            $this->evento($p, $quem, PolosPendenciaContaEvento::PRIORIDADE, de: $de, para: $prioridade);
            $this->log($quem, $p->empresa, 'Prioridade da pendência do Analista de Conta: ' . (PolosPendenciaConta::PRIORIDADES[$de] ?? $de)
                . ' → ' . PolosPendenciaConta::PRIORIDADES[$prioridade], ['pendencia_id' => $p->id]);

            $nome   = $p->empresa?->nome ?? 'empresa';
            $titulo = "Prioridade mudou: {$nome}";
            $texto  = 'Agora ' . PolosPendenciaConta::PRIORIDADES[$prioridade] . ' (era ' . (PolosPendenciaConta::PRIORIDADES[$de] ?? $de) . ") — {$quem->name}.";
            (int) $p->solicitante_id === $quem->id
                ? $this->avisar($p->analista, $quem, $p, $titulo, $texto)
                : $this->avisar($p->solicitante, $quem, $p, $titulo, $texto);

            return $p;
        });
    }

    // ═══ Leitura ═══

    /**
     * A fila do Analista de Conta: empresas com pendência na fila (ordem: prazo pedido, depois
     * prioridade) e as encerradas nos últimos 30 dias (mais recente primeiro).
     *
     * @return array{resumo: array<string,int>, abertas: list<array>, encerradas: list<array>}
     */
    public function fila(CarbonInterface $hoje): array
    {
        $hoje  = CarbonImmutable::parse($hoje->toDateString(), self::TZ);
        $carga = ['empresa:id,nome,cust_id,fase,polo,responsavel_id', 'empresa.responsavel:id,name',
            'empresa.implementacao:id,empresa_id,responsavel_id', 'empresa.implementacao.responsavel:id,name',
            'solicitante:id,name', 'analista:id,name', 'eventos.usuario:id,name'];

        $abertas = PolosPendenciaConta::with($carga)->abertas()->get()->filter(fn ($p) => $p->empresa !== null);

        $encerradas = PolosPendenciaConta::with($carga)
            ->whereIn('status', PolosPendenciaConta::ENCERRADAS)
            ->where('updated_at', '>=', $hoje->subDays(self::DIAS_ENCERRADAS))
            ->get()
            ->filter(fn ($p) => $p->empresa !== null)
            ->sortByDesc(fn ($p) => ($p->concluida_em ?? $p->updated_at)->getTimestamp());

        $conta = fn (callable $f) => $abertas->filter($f)->count();

        return [
            'resumo' => [
                'em_aberto'       => $abertas->count(),
                'empresas'        => $abertas->pluck('mlb_empresa_id')->unique()->count(),
                // Lista do dia (fatia 2): planejadas para hoje + as que ficaram para trás + atrasadas sem dia.
                'para_hoje'       => $conta(fn ($p) => self::paraHoje($p, $hoje)),
                'para_tras'       => $conta(fn ($p) => $p->paraTras($hoje)),
                'sem_data'        => $conta(fn ($p) => $p->data_planejada === null),
                'atrasadas'       => $conta(fn ($p) => $p->atrasada($hoje)),
                'aguardando'      => $conta(fn ($p) => $p->status === PolosPendenciaConta::STATUS_AGUARDANDO_SELLER),
                'devolvidas'      => $conta(fn ($p) => $p->status === PolosPendenciaConta::STATUS_DEVOLVIDA),
                'concluidas_hoje' => $encerradas->filter(fn ($p) => $p->status === PolosPendenciaConta::STATUS_CONCLUIDA
                    && $p->concluida_em?->copy()->setTimezone(self::TZ)->toDateString() === $hoje->toDateString())->count(),
            ],
            'abertas'    => $this->porEmpresa($abertas->sort(fn ($a, $b) => self::comparar($a, $b)), $hoje),
            'encerradas' => $this->porEmpresa($encerradas, $hoje),
        ];
    }

    /**
     * Pendências de cada empresa para o cartão da fila de revisão: as que estão na fila e as
     * concluídas nos últimos 15 dias ("o que foi feito"). Sem histórico — ele mora na fila do
     * Analista de Conta e na ficha da empresa.
     *
     * @param  array<int, int>  $ids  mlb_empresa_id
     * @return array<int, list<array>>
     */
    public function daEmpresas(array $ids, CarbonInterface $hoje): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $hoje = CarbonImmutable::parse($hoje->toDateString(), self::TZ);

        return PolosPendenciaConta::with(['solicitante:id,name', 'analista:id,name'])
            ->whereIn('mlb_empresa_id', $ids)
            ->where(fn ($q) => $q->whereNotIn('status', PolosPendenciaConta::ENCERRADAS)
                ->orWhere(fn ($q2) => $q2->where('status', PolosPendenciaConta::STATUS_CONCLUIDA)
                    ->where('concluida_em', '>=', $hoje->subDays(self::DIAS_CONCLUIDAS_NA_REVISAO))))
            ->get()
            ->sort(fn ($a, $b) => ($a->encerrada() <=> $b->encerrada()) ?: self::comparar($a, $b))
            ->groupBy('mlb_empresa_id')
            ->map(fn (Collection $g) => $g->map(fn ($p) => $this->linha($p, $hoje, false))->values()->all())
            ->all();
    }

    /** Histórico completo da empresa — a ficha no Painel Polos. Mais recente primeiro. */
    public function historico(MlbEmpresa $empresa, CarbonInterface $hoje): array
    {
        $hoje = CarbonImmutable::parse($hoje->toDateString(), self::TZ);

        return PolosPendenciaConta::with(['solicitante:id,name', 'analista:id,name', 'eventos.usuario:id,name'])
            ->where('mlb_empresa_id', $empresa->id)
            ->orderByDesc('id')
            ->get()
            ->sort(fn ($a, $b) => ($a->encerrada() <=> $b->encerrada()) ?: $b->id <=> $a->id)
            ->map(fn ($p) => $this->linha($p, $hoje))
            ->values()
            ->all();
    }

    /**
     * A agenda semanal (fatia 2): as pendências em aberto em colunas de segunda a sexta da semana
     * de `$segunda`, mais "Sem data" e "Ficaram para trás" (dia planejado antes de hoje e fora da
     * semana exibida). As planejadas para outras semanas só entram na contagem.
     *
     * @return array{semana: array, dias: list<array>, sem_data: list<array>, para_tras: list<array>, outras_semanas: int, total: int}
     */
    public function agenda(CarbonInterface $segunda, CarbonInterface $hoje): array
    {
        $hoje    = CarbonImmutable::parse($hoje->toDateString(), self::TZ);
        $segunda = CarbonImmutable::parse($segunda->toDateString(), self::TZ)->startOfWeek(CarbonInterface::MONDAY);
        $datas   = collect(range(0, 4))->map(fn ($i) => $segunda->addDays($i)->toDateString());

        $abertas = PolosPendenciaConta::with(['empresa:id,nome,cust_id,fase,polo', 'solicitante:id,name', 'analista:id,name'])
            ->abertas()->get()->filter(fn ($p) => $p->empresa !== null)
            ->sort(fn ($a, $b) => self::comparar($a, $b))->values();

        $porDia   = array_fill_keys($datas->all(), []);
        $semData  = [];
        $paraTras = [];
        $outras   = 0;
        foreach ($abertas as $p) {
            $dia = $p->data_planejada?->toDateString();
            if ($dia === null) {
                $semData[] = $this->cartao($p, $hoje);
            } elseif (isset($porDia[$dia])) {
                $porDia[$dia][] = $this->cartao($p, $hoje);
            } elseif ($dia < $hoje->toDateString()) {
                $paraTras[] = $this->cartao($p, $hoje);
            } else {
                $outras++;
            }
        }

        $rotulos = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex'];
        $sexta   = $segunda->addDays(4);

        return [
            'semana' => [
                'inicio'   => $segunda->toDateString(),
                'rotulo'   => $segunda->format('d/m') . ' a ' . $sexta->format('d/m/Y'),
                'anterior' => $segunda->subWeek()->toDateString(),
                'proxima'  => $segunda->addWeek()->toDateString(),
                'atual'    => $segunda->toDateString() === self::segundaDaAgenda($hoje)->toDateString(),
            ],
            'dias' => $datas->map(function (string $d) use ($porDia, $rotulos, $hoje) {
                $data = CarbonImmutable::parse($d, self::TZ);

                return [
                    'data'     => $d,
                    'rotulo'   => $rotulos[$data->dayOfWeekIso],
                    'dia'      => $data->format('d/m'),
                    'hoje'     => $d === $hoje->toDateString(),
                    'passado'  => $d < $hoje->toDateString(),
                    'cartoes'  => $porDia[$d],
                    // Capacidade do dia: empresas distintas (o ticket conta empresas) e pendências.
                    'empresas' => count(array_unique(array_column($porDia[$d], 'mlb_empresa_id'))),
                ];
            })->all(),
            'sem_data'       => $semData,
            'para_tras'      => $paraTras,
            'outras_semanas' => $outras,
            'total'          => $abertas->count(),
        ];
    }

    /** A semana que a agenda abre por padrão: a atual; no fim de semana, a próxima. */
    public static function segundaDaAgenda(CarbonInterface $hoje): CarbonImmutable
    {
        $hoje = CarbonImmutable::parse($hoje->toDateString(), self::TZ);

        return $hoje->dayOfWeekIso >= 6 ? $hoje->next(CarbonInterface::MONDAY) : $hoje->startOfWeek(CarbonInterface::MONDAY);
    }

    /**
     * Acompanhamento da coordenação (fatia 3), sobre os últimos `$dias` dias: pendências por
     * status e por quem pediu, as atrasadas, o tempo entre abrir e concluir e o volume concluído
     * por dia e por semana. "Em aberto" é o estado de agora; criadas, concluídas e canceladas
     * contam no período.
     *
     * @return array<string, mixed>
     */
    public function acompanhamento(CarbonInterface $hoje, int $dias = 30): array
    {
        $hoje  = CarbonImmutable::parse($hoje->toDateString(), self::TZ);
        $desde = $hoje->subDays($dias - 1)->startOfDay();
        // As 8 semanas do gráfico podem ir além do período escolhido.
        $desdeSemanas = $hoje->startOfWeek(CarbonInterface::MONDAY)->subWeeks(7);
        $corte        = $desde->lessThan($desdeSemanas) ? $desde : $desdeSemanas;

        $pendencias = PolosPendenciaConta::with(['empresa:id,nome,cust_id,fase', 'solicitante:id,name', 'analista:id,name'])
            ->where(fn ($q) => $q->whereNotIn('status', PolosPendenciaConta::ENCERRADAS)
                ->orWhere('concluida_em', '>=', $corte)
                ->orWhere('updated_at', '>=', $corte)
                ->orWhere('created_at', '>=', $corte))
            ->get();

        $abertas     = $pendencias->reject(fn ($p) => $p->encerrada());
        $noPeriodo   = fn ($quando) => $quando !== null && $quando->greaterThanOrEqualTo($desde);
        $concluidas  = $pendencias->filter(fn ($p) => $p->status === PolosPendenciaConta::STATUS_CONCLUIDA && $noPeriodo($p->concluida_em));
        $canceladas  = $pendencias->filter(fn ($p) => $p->status === PolosPendenciaConta::STATUS_CANCELADA && $noPeriodo($p->updated_at));
        $horas       = fn (PolosPendenciaConta $p) => max(0, $p->created_at->diffInMinutes($p->concluida_em, true)) / 60;
        $media       = fn ($lista) => $lista->isEmpty() ? null : round($lista->avg($horas), 1);

        // Por status: o estado atual das abertas + as encerradas no período.
        $porStatus = collect(PolosPendenciaConta::STATUS)->map(fn ($rotulo, $status) => [
            'status' => $status,
            'rotulo' => $rotulo,
            'total'  => match ($status) {
                PolosPendenciaConta::STATUS_CONCLUIDA => $concluidas->count(),
                PolosPendenciaConta::STATUS_CANCELADA => $canceladas->count(),
                default                               => $abertas->where('status', $status)->count(),
            },
        ])->values()->all();

        $noRecorte      = $concluidas->pluck('id')->merge($canceladas->pluck('id'))->flip();
        $porSolicitante = $pendencias
            ->filter(fn ($p) => ! $p->encerrada() || isset($noRecorte[$p->id]))
            ->groupBy(fn ($p) => (int) $p->solicitante_id)
            ->map(function ($grupo, $id) use ($hoje, $concluidas, $media) {
                $suas = $concluidas->whereIn('id', $grupo->pluck('id')->all());

                return [
                    'id'                => $id ?: null,
                    'nome'              => $grupo->first()->solicitante?->name ?? 'Usuário removido',
                    'em_aberto'         => $grupo->reject(fn ($p) => $p->encerrada())->count(),
                    'atrasadas'         => $grupo->filter(fn ($p) => $p->atrasada($hoje))->count(),
                    'concluidas'        => $suas->count(),
                    'tempo_medio_horas' => $media($suas),
                ];
            })
            ->sortBy([['em_aberto', 'desc'], ['nome', 'asc']])
            ->values()->all();

        $atrasadas = $abertas->filter(fn ($p) => $p->atrasada($hoje))
            ->sort(fn ($a, $b) => self::comparar($a, $b))
            ->take(30)
            ->map(fn ($p) => $this->cartao($p, $hoje))
            ->values()->all();

        $diaDe     = fn (PolosPendenciaConta $p) => $p->concluida_em->copy()->setTimezone(self::TZ)->toDateString();
        $todasConc = $pendencias->filter(fn ($p) => $p->status === PolosPendenciaConta::STATUS_CONCLUIDA && $p->concluida_em !== null);
        $contaDia  = $todasConc->countBy($diaDe);

        $porDia = collect(range($dias - 1, 0))->map(function ($i) use ($hoje, $contaDia) {
            $d = $hoje->subDays($i);

            return ['data' => $d->toDateString(), 'rotulo' => $d->format('d/m'), 'concluidas' => (int) ($contaDia[$d->toDateString()] ?? 0)];
        })->all();

        $porSemana = collect(range(7, 0))->map(function ($i) use ($hoje, $todasConc, $diaDe) {
            $inicio = $hoje->startOfWeek(CarbonInterface::MONDAY)->subWeeks($i);
            $fim    = $inicio->addDays(6)->toDateString();

            return [
                'inicio'     => $inicio->toDateString(),
                'rotulo'     => $inicio->format('d/m'),
                'concluidas' => $todasConc->filter(fn ($p) => $diaDe($p) >= $inicio->toDateString() && $diaDe($p) <= $fim)->count(),
            ];
        })->all();

        return [
            'periodo' => ['dias' => $dias, 'desde' => $desde->format('d/m/Y'), 'ate' => $hoje->format('d/m/Y')],
            'resumo'  => [
                'em_aberto'         => $abertas->count(),
                'atrasadas'         => $abertas->filter(fn ($p) => $p->atrasada($hoje))->count(),
                'para_tras'         => $abertas->filter(fn ($p) => $p->paraTras($hoje))->count(),
                'sem_data'          => $abertas->filter(fn ($p) => $p->data_planejada === null)->count(),
                'criadas'           => $pendencias->filter(fn ($p) => $noPeriodo($p->created_at))->count(),
                'concluidas'        => $concluidas->count(),
                'canceladas'        => $canceladas->count(),
                'tempo_medio_horas' => $media($concluidas),
            ],
            'por_status'      => $porStatus,
            'por_solicitante' => $porSolicitante,
            'atrasadas'       => $atrasadas,
            'por_dia'         => $porDia,
            'por_semana'      => $porSemana,
        ];
    }

    /** Pendências na fila que a pessoa encaminhou — o atalho no topo da fila de revisão. */
    public function abertasDe(User $u): int
    {
        return PolosPendenciaConta::abertas()->where('solicitante_id', $u->id)->count();
    }

    /**
     * MLBs de um texto livre ("MLB123, mlb-456" ou uma por linha) ou de uma lista: maiúsculas,
     * sem hífen depois de MLB/MLBU, sem repetir. O que não parece MLB fica como foi digitado —
     * melhor guardar do que perder o que a pessoa escreveu.
     *
     * @return list<string>
     */
    public static function mlbs($valor): array
    {
        $texto  = is_array($valor) ? implode(' ', $valor) : (string) $valor;
        $tokens = preg_split('/[\s,;]+/u', mb_strtoupper(trim($texto)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_map(fn ($t) => preg_replace('/^(MLBU?)-(\d+)$/', '$1$2', mb_substr($t, 0, 40)), $tokens);

        return array_slice(array_values(array_unique($tokens)), 0, 100);
    }

    // ═══ Apoio ═══

    /** Ordem da fila: prazo pedido (sem prazo por último), prioridade, mais antiga. */
    private static function comparar(PolosPendenciaConta $a, PolosPendenciaConta $b): int
    {
        $pa = $a->prazo?->toDateString() ?? '9999-12-31';
        $pb = $b->prazo?->toDateString() ?? '9999-12-31';

        return strcmp($pa, $pb)
            ?: (PolosPendenciaConta::PESO_PRIORIDADE[$a->prioridade] ?? 9) <=> (PolosPendenciaConta::PESO_PRIORIDADE[$b->prioridade] ?? 9)
            ?: $a->id <=> $b->id;
    }

    /**
     * Agrupa por empresa mantendo a ordem de chegada: a empresa vai para a posição da sua
     * primeira pendência na lista já ordenada.
     *
     * @param  Collection<int, PolosPendenciaConta>  $pendencias
     */
    private function porEmpresa(Collection $pendencias, CarbonImmutable $hoje): array
    {
        $empresas = [];
        foreach ($pendencias as $p) {
            $e = $p->empresa;
            $empresas[$e->id] ??= [
                'id'          => $e->id,
                'nome'        => $e->nome,
                'cust_id'     => $e->cust_id,
                'fase'        => $e->fase,
                'polo'        => $e->polo,
                'responsavel'    => FilaRevisaoPolos::responsavelNome($e),
                'responsavel_id' => FilaRevisaoPolos::responsavelId($e),
                'atrasada'    => false,
                'pendencias'  => [],
            ];
            $linha = $this->linha($p, $hoje);
            $empresas[$e->id]['pendencias'][] = $linha;
            $empresas[$e->id]['atrasada']   = $empresas[$e->id]['atrasada'] || $linha['atrasada'];
        }

        return array_values($empresas);
    }

    private function linha(PolosPendenciaConta $p, CarbonImmutable $hoje, bool $comHistorico = true): array
    {
        $atrasada = $p->atrasada($hoje);

        return [
            'id'               => $p->id,
            'mlb_empresa_id'   => $p->mlb_empresa_id,
            'tipo'             => $p->tipo,
            'tipo_label'       => PolosPendenciaConta::TIPOS[$p->tipo] ?? $p->tipo,
            'descricao'        => $p->descricao,
            'mlbs'             => $p->mlbs ?? [],
            'prioridade'       => $p->prioridade,
            'prioridade_label' => PolosPendenciaConta::PRIORIDADES[$p->prioridade] ?? $p->prioridade,
            'prazo'            => $p->prazo?->format('d/m/Y'),
            'prazo_iso'        => $p->prazo?->toDateString(),
            'atrasada'         => $atrasada,
            'dias_atraso'      => $atrasada ? (int) abs(CarbonImmutable::parse($p->prazo->toDateString())->diffInDays($hoje)) : 0,
            'data_planejada'   => $p->data_planejada?->format('d/m'),
            'data_planejada_iso' => $p->data_planejada?->toDateString(),
            // Fatia 2: o dia planejado passou sem concluir; vai para a lista do dia.
            'para_tras'        => $p->paraTras($hoje),
            'para_hoje'        => self::paraHoje($p, $hoje),
            'depois_do_prazo'  => ! $p->encerrada() && $p->planejadaDepoisDoPrazo(),
            'status'           => $p->status,
            'status_label'     => PolosPendenciaConta::STATUS[$p->status] ?? $p->status,
            'encerrada'        => $p->encerrada(),
            'solicitante'      => ['id' => $p->solicitante_id === null ? null : (int) $p->solicitante_id, 'nome' => $p->solicitante?->name ?? 'Usuário removido'],
            'analista'         => $p->analista?->name,
            'aberta_em'        => $p->created_at?->copy()->setTimezone(self::TZ)->format('d/m/Y H:i'),
            'conclusao'        => $p->conclusao,
            'mlbs_alterados'   => $p->mlbs_alterados ?? [],
            'concluida_em'     => $p->concluida_em?->copy()->setTimezone(self::TZ)->format('d/m/Y H:i'),
            'historico'        => $comHistorico ? $this->historicoDe($p) : null,
        ];
    }

    /** Uma pendência com a empresa junto — o cartão da agenda e a lista de atrasadas. */
    private function cartao(PolosPendenciaConta $p, CarbonImmutable $hoje): array
    {
        return $this->linha($p, $hoje, false) + [
            'empresa' => [
                'id'      => $p->empresa?->id,
                'nome'    => $p->empresa?->nome,
                'cust_id' => $p->empresa?->cust_id,
                'fase'    => $p->empresa?->fase,
            ],
        ];
    }

    /** Lista do dia: planejada para hoje, ficou para trás, ou atrasada (prazo) sem dia planejado. */
    private static function paraHoje(PolosPendenciaConta $p, CarbonImmutable $hoje): bool
    {
        if ($p->encerrada()) {
            return false;
        }
        if ($p->data_planejada === null) {
            return $p->atrasada($hoje);
        }

        return $p->data_planejada->toDateString() <= $hoje->toDateString();
    }

    private function historicoDe(PolosPendenciaConta $p): array
    {
        return $p->eventos->map(fn (PolosPendenciaContaEvento $ev) => [
            'id'     => $ev->id,
            'tipo'   => $ev->tipo,
            'por'    => $ev->usuario?->name ?? 'Usuário removido',
            'em'     => $ev->created_at?->copy()->setTimezone(self::TZ)->format('d/m/Y H:i'),
            'resumo' => self::resumoEvento($ev),
            'texto'  => $ev->texto,
            'mlbs'   => $ev->meta['mlbs_alterados'] ?? null,
            'anexo'  => $ev->tipo === PolosPendenciaContaEvento::ANEXO && isset($ev->meta['anexo_id'])
                ? ['nome' => $ev->para, 'url' => route('mlb.polos-painel.pendencias-conta.anexo', [$p->id, $ev->meta['anexo_id']], false)]
                : null,
        ])->values()->all();
    }

    private static function resumoEvento(PolosPendenciaContaEvento $ev): string
    {
        $rotulo = fn (?string $s) => PolosPendenciaConta::STATUS[$s] ?? (string) $s;

        return match ($ev->tipo) {
            PolosPendenciaContaEvento::CRIADA     => 'encaminhou ao Analista de Conta',
            PolosPendenciaContaEvento::STATUS     => ($ev->meta['automatico'] ?? false)
                ? 'respondeu: voltou para ' . $rotulo($ev->para)
                : 'mudou de ' . $rotulo($ev->de) . ' para ' . $rotulo($ev->para),
            PolosPendenciaContaEvento::COMENTARIO => 'comentou',
            PolosPendenciaContaEvento::ANEXO      => 'anexou ' . $ev->para,
            PolosPendenciaContaEvento::CONCLUIDA  => 'concluiu',
            PolosPendenciaContaEvento::DATA_PLANEJADA => match (true) {
                $ev->para === null => 'tirou a data planejada' . ($ev->de ? ' (era ' . self::diaMes($ev->de) . ')' : ''),
                $ev->de === null   => 'planejou para ' . self::diaMes($ev->para),
                default            => 'replanejou de ' . self::diaMes($ev->de) . ' para ' . self::diaMes($ev->para),
            },
            PolosPendenciaContaEvento::PRIORIDADE => 'mudou a prioridade de ' . (PolosPendenciaConta::PRIORIDADES[$ev->de] ?? $ev->de)
                . ' para ' . (PolosPendenciaConta::PRIORIDADES[$ev->para] ?? $ev->para),
            default                               => $ev->tipo,
        };
    }

    /** AAAA-MM-DD → dd/mm. */
    private static function diaMes(string $ymd): string
    {
        return CarbonImmutable::parse($ymd, self::TZ)->format('d/m');
    }

    /** @param  list<PolosPendenciaConta>  $lista  pendências de UMA pessoa, planejadas depois do prazo */
    private function avisarPlanejadaDepoisDoPrazo(array $lista, User $quem): void
    {
        $para = $lista[0]->solicitante;
        if (count($lista) === 1) {
            $p = $lista[0];
            $this->avisar($para, $quem, $p, 'Pendência planejada depois do prazo: ' . ($p->empresa?->nome ?? 'empresa'),
                "{$quem->name} vai fazer em " . $p->data_planejada->format('d/m') . '; o prazo pedido era ' . $p->prazo->format('d/m')
                . '. Se precisar antes, suba a prioridade ou comente na pendência.');

            return;
        }

        $empresas = collect($lista)->map(fn ($p) => ($p->empresa?->nome ?? 'empresa') . ' (' . $p->data_planejada->format('d/m') . ')')->unique()->implode(', ');
        $this->avisar($para, $quem, null, count($lista) . ' pendências planejadas depois do prazo',
            "{$quem->name} planejou para depois do prazo pedido: {$empresas}.",
            route('mlb.polos-painel.analista-conta', ['minhas' => 1], false));
    }

    /** @param  UploadedFile[]  $arquivos */
    private function salvarAnexos(PolosPendenciaConta $p, User $quem, array $arquivos): void
    {
        $arquivos = array_values(array_filter($arquivos, fn ($a) => $a instanceof UploadedFile));
        foreach (array_slice($arquivos, 0, self::MAX_ANEXOS) as $arquivo) {
            $nome    = mb_substr(basename(str_replace('\\', '/', $arquivo->getClientOriginalName())), 0, 255);
            $caminho = $arquivo->store("polos-pendencias/{$p->id}", 'local');
            $ev      = $this->evento($p, $quem, PolosPendenciaContaEvento::ANEXO, para: $nome);
            $anexo   = $p->anexos()->create([
                'evento_id'     => $ev->id,
                'enviado_por'   => $quem->id,
                'nome_original' => $nome,
                'caminho'       => $caminho,
                // Tipo detectado pelo servidor (conteúdo), não o que o navegador declarou.
                'mime'          => $arquivo->getMimeType() ?: 'application/octet-stream',
                'tamanho'       => (int) $arquivo->getSize(),
            ]);
            $ev->update(['meta' => ['anexo_id' => $anexo->id]]);
        }
    }

    private function evento(PolosPendenciaConta $p, User $quem, string $tipo, ?string $de = null, ?string $para = null,
        ?string $texto = null, ?array $meta = null): PolosPendenciaContaEvento
    {
        return PolosPendenciaContaEvento::create([
            'pendencia_id' => $p->id,
            'user_id'      => $quem->id,
            'tipo'         => $tipo,
            'de'           => $de,
            'para'         => $para === null ? null : mb_substr($para, 0, 255),
            'texto'        => $texto,
            'meta'         => $meta,
        ]);
    }

    /** `$p` nulo = aviso de várias pendências; aí `$url` leva à lista de quem pediu. */
    private function avisar(?User $para, User $ator, ?PolosPendenciaConta $p, string $titulo, string $mensagem, ?string $url = null): void
    {
        if ($para === null || $para->id === $ator->id) {
            return;
        }
        $url  ??= route('mlb.polos-painel.analista-conta', ['pendencia' => $p?->id], false);
        $meta   = $p ? ['pendencia_id' => $p->id, 'mlb_empresa_id' => $p->mlb_empresa_id] : [];

        DB::afterCommit(fn () => Notification::send($para, new PendenciaContaNotification(
            $titulo, mb_substr($mensagem, 0, 200), $url, $ator->id, $meta,
        )));
    }

    private function log(User $quem, ?MlbEmpresa $empresa, string $mensagem, array $props = []): void
    {
        $a = activity('polos')->causedBy($quem)->withProperties($props);
        if ($empresa) {
            $a->performedOn($empresa);
        }
        $a->log("[Polos] {$mensagem}" . ($empresa ? " (empresa {$empresa->id} — {$empresa->nome})" : ''));
    }
}
