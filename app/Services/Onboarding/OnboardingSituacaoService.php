<?php

namespace App\Services\Onboarding;

use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use Illuminate\Support\Collection;

/**
 * A leitura operacional de um onboarding: **o que está travando, há quantos
 * dias e de quem é a bola** (SC-11) — nunca `feitos/total`.
 *
 * Extraído de `OnboardingController`, onde nasceu privado, quando a mesma
 * leitura passou a ser exibida também na listagem de `/companies`. O motivo da
 * extração é o que importa: duas telas calculando "quem está travado" por
 * conta própria viram duas verdades, e a pergunta "qual delas está certa?"
 * aparece meses depois, quando ninguém lembra que eram dois códigos. Aqui há
 * um só.
 *
 * Sem I/O: tudo é calculado sobre passos já carregados em memória. Quem chama
 * é responsável pelo eager loading — em listagem, carregar passo dentro de
 * laço é o que derruba a página.
 */
class OnboardingSituacaoService
{
    /**
     * Chave do único passo administrativo do onboarding de Gestão (D-15).
     *
     * A v10 da definição REMOVEU este passo da régua. A regra continua aqui de
     * propósito: os onboardings que nasceram antes da v10 carregam o passo
     * (cada um guarda a definição com que nasceu) e para eles a situação ainda
     * precisa distinguir "só falta o administrativo" de "aguardando interno".
     * Para onboarding novo, {@see self::prontoParaConcluir()} devolve `false`
     * na primeira linha — a chave não existe — e a situação nunca aparece.
     */
    public const CHAVE_PASSO_ADMINISTRATIVO = 'confirmacao_pagamento';

    /**
     * Rótulo pt-BR de cada valor de situação. Nenhum deles é porcentagem
     * (SC-11).
     */
    public const SITUACAO_LABELS = [
        'rascunho'             => 'Rascunho — aguardando responsável',
        'vencido'              => 'Vencido',
        'aguardando_cliente'   => 'Aguardando cliente',
        'aguardando_interno'   => 'Aguardando interno',
        'aguardando_sistema'   => 'Aguardando sistema',
        'coletando'            => 'Coletando dados',
        'pronto_para_concluir' => 'Pronto para concluir',
        'concluido'            => 'Concluído',
    ];

    /**
     * Ordem de gravidade — a régua para responder "qual destes N onboardings
     * a pessoa deve olhar primeiro?".
     *
     * `rascunho` é o mais grave de propósito: um onboarding parado em rascunho
     * não corre SLA e não expõe portal nenhum ao cliente (D-05/SC-04) — ele
     * está invisível para todo mundo, inclusive para quem contratou. É o único
     * estado em que o tempo passa sem que ninguém seja cobrado.
     */
    public const GRAVIDADE = [
        'rascunho'             => 0,
        'vencido'              => 1,
        'aguardando_cliente'   => 2,
        'aguardando_interno'   => 3,
        'aguardando_sistema'   => 4,
        'coletando'            => 5,
        'pronto_para_concluir' => 6,
        'concluido'            => 7,
    ];

    /**
     * Situação de um onboarding a partir dos passos JÁ CARREGADOS.
     *
     * Devolve sempre um valor do catálogo fechado de
     * {@see self::SITUACAO_LABELS}.
     */
    public function situacao(Onboarding $onboarding, ?Collection $passos = null): string
    {
        $passos ??= $onboarding->passos;

        if ($onboarding->status === Onboarding::STATUS_RASCUNHO) {
            return 'rascunho';
        }

        if ($onboarding->status === Onboarding::STATUS_CONCLUIDO) {
            return 'concluido';
        }

        if ($this->prontoParaConcluir($passos)) {
            return 'pronto_para_concluir';
        }

        $trava = $this->passoQueTrava($passos);

        if ($trava) {
            $diasParado = $this->diasParado($trava);
            $slaDias = $trava->sla_dias;

            if ($diasParado !== null && $slaDias !== null && $diasParado > $slaDias) {
                return 'vencido';
            }

            return 'aguardando_'.$trava->dono;
        }

        if ($passos->contains(fn (OnboardingPasso $p) => in_array(
            $p->status,
            [OnboardingPasso::STATUS_AGUARDANDO_COLETA, OnboardingPasso::STATUS_INDETERMINADO],
            true
        ))) {
            return 'coletando';
        }

        // Sem passo aberto, sem coleta pendente e ainda não concluído — não
        // deveria acontecer com dados consistentes. 'coletando' é o estado
        // neutro mais seguro para nunca devolver valor fora do catálogo.
        return 'coletando';
    }

    public function label(string $situacao): string
    {
        return self::SITUACAO_LABELS[$situacao] ?? $situacao;
    }

    /**
     * D-15: `true` só quando TODOS os passos não-administrativos estão
     * `concluido`/`nao_aplicavel` e o passo administrativo ainda não está — a
     * pendência que resta é administrativa, não mapeamento parado.
     */
    public function prontoParaConcluir(Collection $passos): bool
    {
        $administrativo = $passos->firstWhere('chave', self::CHAVE_PASSO_ADMINISTRATIVO);

        if (! $administrativo) {
            return false;
        }

        $administrativoPendente = ! in_array(
            $administrativo->status,
            [OnboardingPasso::STATUS_CONCLUIDO, OnboardingPasso::STATUS_NAO_APLICAVEL],
            true
        );

        if (! $administrativoPendente) {
            return false;
        }

        return $passos
            ->reject(fn (OnboardingPasso $p) => $p->chave === self::CHAVE_PASSO_ADMINISTRATIVO)
            ->every(fn (OnboardingPasso $p) => in_array(
                $p->status,
                [OnboardingPasso::STATUS_CONCLUIDO, OnboardingPasso::STATUS_NAO_APLICAVEL],
                true
            ));
    }

    /**
     * O passo que mais trava: entre os `aberto`, o de maior `dias_parado`
     * (empate → menor `ordem`). Nenhum aberto → `null`. `aguardando_coleta`
     * nunca é escolhido — não é pendência humana (D-11).
     */
    public function passoQueTrava(Collection $passos): ?OnboardingPasso
    {
        $abertos = $passos->filter(fn (OnboardingPasso $p) => $p->status === OnboardingPasso::STATUS_ABERTO);

        if ($abertos->isEmpty()) {
            return null;
        }

        return $abertos->reduce(function (?OnboardingPasso $atual, OnboardingPasso $candidato) {
            if ($atual === null) {
                return $candidato;
            }

            $diasAtual = $this->diasParado($atual) ?? 0;
            $diasCandidato = $this->diasParado($candidato) ?? 0;

            if ($diasCandidato > $diasAtual) {
                return $candidato;
            }

            if ($diasCandidato === $diasAtual && $candidato->ordem < $atual->ordem) {
                return $candidato;
            }

            return $atual;
        });
    }

    public function passoTravaPayload(OnboardingPasso $passo): array
    {
        $diasParado = $this->diasParado($passo);
        $slaDias = $passo->sla_dias;

        return [
            'chave'       => $passo->chave,
            'titulo'      => $passo->titulo,
            'dono'        => $passo->dono,
            'setor'       => $passo->setor?->nome,
            'dias_parado' => $diasParado,
            'sla_dias'    => $slaDias,
            'vencido'     => $diasParado !== null && $slaDias !== null && $diasParado > $slaDias,
        ];
    }

    /** @return array{abertos:int,bloqueados:int,aguardando_coleta:int,indeterminados:int,concluidos:int,nao_aplicaveis:int} */
    public function contadores(Collection $passos): array
    {
        return [
            'abertos'           => $passos->where('status', OnboardingPasso::STATUS_ABERTO)->count(),
            'bloqueados'        => $passos->where('status', OnboardingPasso::STATUS_BLOQUEADO)->count(),
            'aguardando_coleta' => $passos->where('status', OnboardingPasso::STATUS_AGUARDANDO_COLETA)->count(),
            'indeterminados'    => $passos->where('status', OnboardingPasso::STATUS_INDETERMINADO)->count(),
            'concluidos'        => $passos->where('status', OnboardingPasso::STATUS_CONCLUIDO)->count(),
            'nao_aplicaveis'    => $passos->where('status', OnboardingPasso::STATUS_NAO_APLICAVEL)->count(),
        ];
    }

    /**
     * Dias inteiros desde `disponivel_em` até agora. `null` (nunca `0`)
     * quando o passo ainda não abriu — contar do `created_at` do onboarding
     * mentiria sobre um passo que ficou bloqueado (D-11).
     */
    public function diasParado(OnboardingPasso $passo): ?int
    {
        if ($passo->disponivel_em === null) {
            return null;
        }

        return (int) $passo->disponivel_em->diffInDays(now());
    }

    /**
     * Payload de 1 onboarding, comum ao painel `/onboarding` e à listagem de
     * `/companies`. Contrato travado por teste nas duas telas.
     *
     * `responsavel_sugerido` NÃO entra aqui: depende do engine e só faz
     * sentido no rascunho — quem monta a tela adiciona.
     */
    public function resumo(Onboarding $onboarding): array
    {
        $passos = $onboarding->passos;
        $situacao = $this->situacao($onboarding, $passos);
        $trava = $this->passoQueTrava($passos);

        return [
            'id'      => $onboarding->id,
            'servico' => ['id' => $onboarding->servico->id, 'nome' => $onboarding->servico->nome],
            'status'  => $onboarding->status,
            'situacao'       => $situacao,
            'situacao_label' => $this->label($situacao),
            'responsavel'    => $this->usuario($onboarding->responsavel),
            // Os dois papéis (R-01). `responsavel` acima continua sendo o
            // principal, que é o nome que o portal e o detalhe mostram.
            'responsavel_estrategista' => $this->usuario($onboarding->responsavelEstrategista),
            'responsavel_analista'     => $this->usuario($onboarding->responsavelAnalista),
            'passo_que_trava' => $trava ? $this->passoTravaPayload($trava) : null,
            'contadores'      => $this->contadores($passos),
            'definicao_versao' => $onboarding->definicao_versao,
            // Data de chegada da empresa ao onboarding = `created_at` da linha,
            // gravada pelo Observer no `created` do contrato. NÃO usar
            // `iniciado_em`: ele é null enquanto o onboarding está em rascunho,
            // e é justamente o rascunho recém-chegado que se quer enxergar.
            'chegou_em'        => $onboarding->created_at?->toISOString(),
        ];
    }

    /** @param  \App\Models\User|null  $usuario */
    private function usuario($usuario): ?array
    {
        return $usuario ? ['id' => $usuario->id, 'name' => $usuario->name] : null;
    }
}
