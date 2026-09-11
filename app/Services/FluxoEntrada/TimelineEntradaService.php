<?php

namespace App\Services\FluxoEntrada;

use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\ContratoAssinatura;
use App\Models\ContratoLiberacao;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TimelineEntradaService — a linha do tempo do fluxo de entrada de uma empresa
 * e o tempo que ela passou em cada etapa (Fase 156, HIST-01/02/03).
 *
 * **Só lê fontes DURÁVEIS** (D-A). O `activity_log` fica de fora de propósito:
 * `config/activitylog.php` tem `delete_records_older_than_days => 365`, e foi
 * exatamente por isso que a Fase 150 criou `company_etapa_transicoes` em vez de
 * usar o log. Ler o log aqui reintroduziria o problema pela porta dos fundos —
 * a timeline de uma empresa antiga mudaria sozinha com a poda, e o SLA mediria
 * períodos que somem.
 *
 * **Esta fase não grava nada.** Tudo o que a timeline mostra já é registrado
 * pelas Fases 150-155. Se um evento do §12 estivesse faltando, a correção seria
 * na fase que devia tê-lo gravado, não aqui.
 */
class TimelineEntradaService
{
    /**
     * Rótulos das 9 etapas — espelhados aqui porque o projeto não tem enum
     * compartilhado entre PHP e JS. Mesmos textos de `Comercial/Entrada.jsx`.
     */
    public const ETAPA_LABELS = [
        Company::ETAPA_AGUARDANDO_ADMINISTRATIVO => 'Aguardando Administrativo',
        Company::ETAPA_ADMINISTRATIVO_ANDAMENTO  => 'Administrativo em Andamento',
        Company::ETAPA_AGUARDANDO_ASSINATURA     => 'Aguardando Assinatura',
        Company::ETAPA_ADMINISTRATIVO_CONCLUIDO  => 'Administrativo Concluído',
        Company::ETAPA_AGUARDANDO_DISTRIBUICAO   => 'Aguardando Distribuição',
        Company::ETAPA_AGUARDANDO_ONBOARDING     => 'Aguardando Onboarding',
        Company::ETAPA_ONBOARDING_ANDAMENTO      => 'Onboarding em Andamento',
        Company::ETAPA_ONBOARDING_CONCLUIDO      => 'Onboarding Concluído',
        Company::ETAPA_EM_OPERACAO               => 'Em Operação',
    ];

    /**
     * A timeline completa, em ordem cronológica (HIST-01/HIST-02).
     *
     * @return array<int, array{
     *   em: string, acao: string, detalhe: ?string,
     *   usuario: ?string, autoria_registrada: bool, fonte: string
     * }>
     *
     * `autoria_registrada` é `false` quando a ORIGEM do evento não guarda quem
     * agiu (D-C) — a tela mostra "não registrado" em vez de deixar o campo
     * vazio, que se leria como "ninguém" ou como falha.
     */
    public function paraEmpresa(Company $company): array
    {
        $eventos = array_merge(
            $this->eventosDeEtapa($company),
            $this->eventosDeContrato($company),
            $this->eventosDeResponsavel($company),
        );

        usort($eventos, fn (array $a, array $b) => strcmp($a['em'], $b['em']));

        return $eventos;
    }

    /**
     * Quanto tempo a empresa passou em cada etapa (HIST-03, D-D).
     *
     * A duração de uma etapa é o intervalo até a transição SEGUINTE; a etapa
     * corrente fica **em aberto**, medida contra agora. Nada é gravado — a
     * duração é sempre derivada, e por isso nunca diverge do histórico.
     *
     * @return array<int, array{
     *   etapa: string, etapa_label: string, entrou_em: string,
     *   saiu_em: ?string, horas: float, em_aberto: bool
     * }>
     */
    public function duracaoPorEtapa(Company $company): array
    {
        $transicoes = CompanyEtapaTransicao::where('company_id', $company->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($transicoes->isEmpty()) {
            // Empresa legada: nunca teve transição gravada. Devolver vazio é
            // honesto — o backfill da Fase 150 gravou a etapa SEM histórico
            // exatamente para não fabricar duração fictícia.
            return [];
        }

        $saida = [];

        foreach ($transicoes as $i => $t) {
            $seguinte = $transicoes[$i + 1] ?? null;
            $entrou   = Carbon::parse($t->created_at);
            $saiu     = $seguinte ? Carbon::parse($seguinte->created_at) : null;

            $saida[] = [
                'etapa'       => $t->etapa_nova,
                'etapa_label' => self::ETAPA_LABELS[$t->etapa_nova] ?? $t->etapa_nova,
                'entrou_em'   => $entrou->toIso8601String(),
                'saiu_em'     => $saiu?->toIso8601String(),
                // Arredonda em 2 casas: a etapa 8 atravessa no mesmo ato da 7→8
                // (D-C da Fase 155) e mede ~0. É correto — ela é um marco, não
                // um período de trabalho.
                'horas'       => round($entrou->diffInMinutes($saiu ?? now()) / 60, 2),
                'em_aberto'   => $saiu === null,
            ];
        }

        return $saida;
    }

    /**
     * Eventos 1, 4 e 7 do §12 (e todas as demais transições).
     *
     * A transição de NASCIMENTO tem `etapa_anterior` nulo — é a "recebida do
     * HubSpot" do §12, ou o cadastro manual pelo Comercial. O texto distingue
     * as duas pelo ator, não por adivinhação.
     *
     * @return array<int, array<string, mixed>>
     */
    private function eventosDeEtapa(Company $company): array
    {
        $transicoes = CompanyEtapaTransicao::where('company_id', $company->id)
            ->orderBy('created_at')
            ->get();

        $nomes = User::whereIn('id', $transicoes->pluck('user_id')->filter()->unique())
            ->pluck('name', 'id');

        return $transicoes->map(function (CompanyEtapaTransicao $t) use ($nomes) {
            $label = self::ETAPA_LABELS[$t->etapa_nova] ?? $t->etapa_nova;

            return [
                'em'      => Carbon::parse($t->created_at)->toIso8601String(),
                'acao'    => $t->etapa_anterior === null
                    ? 'Empresa recebida no fluxo de entrada'
                    : "Avançou para {$label}",
                'detalhe' => $t->etapa_anterior === null
                    ? $label
                    : (self::ETAPA_LABELS[$t->etapa_anterior] ?? $t->etapa_anterior).' → '.$label,
                'usuario'            => $t->user_id ? ($nomes[$t->user_id] ?? null) : null,
                'autoria_registrada' => $t->user_id !== null,
                'fonte'              => 'etapa',
            ];
        })->all();
    }

    /**
     * Eventos 2 e 3 do §12.
     *
     * ⚠️ O evento 3 tem DUAS fontes. A D-16 da Fase 152 estabeleceu que
     * "contrato assinado" também fecha por `ContratoLiberacao` — liberação
     * manual, registrada sem envelope assinado. Ler só `assinado_em` faria uma
     * empresa liberada manualmente aparecer sem o evento 3, e o SLA a mostraria
     * presa numa etapa que ela já venceu.
     *
     * Sem ator: enviar e assinar são atos do Clicksign/cliente, não de alguém
     * da ECF. A liberação manual, essa sim, tem quem liberou.
     *
     * @return array<int, array<string, mixed>>
     */
    private function eventosDeContrato(Company $company): array
    {
        $eventos = [];

        $envelopes = ContratoAssinatura::where('company_id', $company->id)
            ->with('servico')
            ->get();

        foreach ($envelopes as $c) {
            $servico = $c->servico?->nome;

            if ($c->enviado_em) {
                $eventos[] = [
                    'em'                 => Carbon::parse($c->enviado_em)->toIso8601String(),
                    'acao'               => 'Contrato enviado para assinatura',
                    'detalhe'            => $servico,
                    'usuario'            => null,
                    'autoria_registrada' => false,
                    'fonte'              => 'contrato',
                ];
            }

            if ($c->assinado_em) {
                $eventos[] = [
                    'em'                 => Carbon::parse($c->assinado_em)->toIso8601String(),
                    'acao'               => 'Contrato assinado',
                    'detalhe'            => $servico,
                    'usuario'            => null,
                    'autoria_registrada' => false,
                    'fonte'              => 'contrato',
                ];
            }
        }

        $liberacoes = ContratoLiberacao::where('company_id', $company->id)
            ->where('via', ContratoLiberacao::VIA_MANUAL)
            ->get();

        $nomes = User::whereIn('id', $liberacoes->pluck('liberado_por_user_id')->filter()->unique())
            ->pluck('name', 'id');

        foreach ($liberacoes as $l) {
            $eventos[] = [
                'em'                 => Carbon::parse($l->liberado_em ?? $l->created_at)->toIso8601String(),
                'acao'               => 'Contrato liberado manualmente',
                'detalhe'            => $l->motivo_slug,
                'usuario'            => $l->liberado_por_user_id ? ($nomes[$l->liberado_por_user_id] ?? null) : null,
                'autoria_registrada' => $l->liberado_por_user_id !== null,
                'fonte'              => 'contrato',
            ];
        }

        return $eventos;
    }

    /**
     * Eventos 5 e 6 do §12 — analista e estrategista definidos.
     *
     * ⚠️ **Sem usuário, e isso é decisão (D-C), não lacuna.** `company_users`
     * não tem coluna de "quem atribuiu". Existem 287 vínculos `consultor` e 1
     * `estrategista` criados antes da Fase 154, que não nasceram de
     * distribuição nenhuma — atribuí-los ao coordenador da transição 5→6 mais
     * próxima seria inventar autoria, o histórico falso que a D-14 desta
     * milestone proíbe. E é justamente esta timeline que alguém vai usar para
     * cobrar alguém.
     *
     * O evento 7, logo ao lado, mostra o coordenador de verdade.
     *
     * @return array<int, array<string, mixed>>
     */
    private function eventosDeResponsavel(Company $company): array
    {
        $vinculos = DB::table('company_users as cu')
            ->leftJoin('users as u', 'u.id', '=', 'cu.user_id')
            ->leftJoin('servicos as s', 's.id', '=', 'cu.servico_id')
            ->where('cu.company_id', $company->id)
            ->whereIn('cu.role', [DistribuicaoService::ROLE_ANALISTA, DistribuicaoService::ROLE_ESTRATEGISTA])
            ->select('cu.role', 'cu.created_at', 'u.name as responsavel', 's.nome as servico')
            ->get();

        return $vinculos->map(fn ($v) => [
            'em'      => Carbon::parse($v->created_at)->toIso8601String(),
            'acao'    => $v->role === DistribuicaoService::ROLE_ANALISTA
                ? 'Analista definido'
                : 'Estrategista definido',
            'detalhe' => trim(($v->responsavel ?? '—').($v->servico ? " · {$v->servico}" : '')),
            'usuario' => null,
            // A origem não guarda quem atribuiu — a tela diz isso na letra.
            'autoria_registrada' => false,
            'fonte'   => 'responsavel',
        ])->all();
    }
}
