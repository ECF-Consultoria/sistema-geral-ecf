<?php

namespace App\Services\Onboarding;

use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\OnboardingTemplate;
use App\Models\Servico;
use App\Models\TemplatePasso;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * OnboardingTemplateVersionService — publica versões N+1 do template de um
 * serviço e migra onboardings vivos entre versões (D-04/D-07/SC-09, Fase 135).
 *
 * Versionamento é IMUTÁVEL POR LINHA NOVA: publicar nunca faz UPDATE em
 * `template_passos` de uma versão já publicada — sempre INSERT de linhas
 * novas com `template_id` novo. `onboardings.template_id` só muda por
 * {@see self::migrarOnboardings()}, ação explícita e separada de
 * {@see self::publicarNovaVersao()} (D-07) — publicar uma versão nova nunca
 * move sozinho quem já está em andamento.
 *
 * Molde de "invariantes de sistema decididos pelo servidor, nunca pelo
 * payload": {@see \App\Http\Controllers\NpsTemplateController::store()}
 * (linhas 107-119) — este service não versiona nada por lá, só reaproveita
 * a disciplina de onde ficam os invariantes.
 */
class OnboardingTemplateVersionService
{
    public function __construct(private readonly OnboardingEngineService $engine)
    {
    }

    /**
     * Publica a versão N+1 do template do serviço. Dentro de uma única
     * transação: calcula a próxima versão disponível para o `servico_id`,
     * desativa a versão vigente ANTES de inserir a nova (o índice único
     * parcial do Plano 02 só permite 1 linha `ativo=true` por `servico_id`
     * — inserir antes de desativar violaria a constraint) e cria os
     * `template_passos` da versão nova via `TemplatePasso::create()`
     * (INSERT — nunca UPDATE em passo de versão anterior).
     *
     * `$passos` é o array já validado por `StoreOnboardingTemplateRequest`
     * — cada item com `chave`/`titulo`/`dono` e os campos opcionais do shape
     * do plano (`descricao`, `setor_id`, `depende_de`, `sla_dias`,
     * `auto_fonte`, `condicao`, `obrigatorio`).
     *
     * @param array<int, array<string, mixed>> $passos
     */
    public function publicarNovaVersao(Servico $servico, array $passos, User $publicador): OnboardingTemplate
    {
        return DB::transaction(function () use ($servico, $passos, $publicador) {
            $proximaVersao = (int) (OnboardingTemplate::where('servico_id', $servico->id)->max('versao') ?? 0) + 1;

            // Desativa a versão vigente ANTES do INSERT da nova — ordem
            // obrigatória por causa do índice único parcial (1 ativo por
            // servico_id, ver migration do Plano 02). Query Builder puro.
            OnboardingTemplate::where('servico_id', $servico->id)
                ->where('ativo', true)
                ->update(['ativo' => false]);

            $template = OnboardingTemplate::create([
                'servico_id'    => $servico->id,
                'versao'        => $proximaVersao,
                'ativo'         => true,
                'publicado_em'  => now(),
                'publicado_por' => $publicador->id,
            ]);

            foreach (array_values($passos) as $indice => $passo) {
                TemplatePasso::create([
                    'template_id' => $template->id,
                    'ordem'       => $passo['ordem'] ?? ($indice + 1),
                    'chave'       => $passo['chave'],
                    'titulo'      => $passo['titulo'],
                    'descricao'   => $passo['descricao'] ?? null,
                    'dono'        => $passo['dono'],
                    'setor_id'    => $passo['setor_id'] ?? null,
                    'depende_de'  => $passo['depende_de'] ?? null,
                    'sla_dias'    => $passo['sla_dias'] ?? null,
                    'auto_fonte'  => $passo['auto_fonte'] ?? null,
                    'condicao'    => $passo['condicao'] ?? null,
                    'obrigatorio' => $passo['obrigatorio'] ?? true,
                ]);
            }

            return $template->fresh();
        });
    }

    /**
     * Migra onboardings `rascunho`/`andamento` para a versão `$destino`.
     * Ids inexistentes ou de onboarding já `concluido` são ignorados
     * silenciosamente (não é erro de uso — a Tela 1 pode oferecer uma lista
     * que ficou desatualizada entre o carregamento e o clique).
     *
     * Passo de mesma `chave` MANTÉM status/valor/feito_por/feito_em/auto_em/
     * disponivel_em — só reaponta `template_passo_id` para a linha nova (o
     * UI-SPEC promete ao usuário "passos já concluídos permanecem como
     * estão"). Passo do template de destino sem correspondente no
     * onboarding nasce `bloqueado`. Passo do onboarding cuja `chave` saiu do
     * template novo vira `nao_aplicavel` — NUNCA é apagado (preserva
     * histórico). Ao fim de cada onboarding migrado, chama `reavaliar()`.
     *
     * Devolve a contagem de onboardings efetivamente migrados.
     *
     * @param array<int, int> $onboardingIds
     */
    public function migrarOnboardings(OnboardingTemplate $destino, array $onboardingIds): int
    {
        $migrados = 0;

        foreach ($onboardingIds as $onboardingId) {
            $onboarding = Onboarding::whereIn('status', [Onboarding::STATUS_RASCUNHO, Onboarding::STATUS_ANDAMENTO])
                ->find($onboardingId);

            if (! $onboarding) {
                continue;
            }

            DB::transaction(function () use ($onboarding, $destino) {
                $templateOrigemId = $onboarding->template_id;

                $onboarding->template_id = $destino->id;
                $onboarding->save();

                $passosDestino = TemplatePasso::where('template_id', $destino->id)
                    ->orderBy('ordem')
                    ->get();
                $chavesDestino = $passosDestino->pluck('chave')->all();

                $passosAtuais = OnboardingPasso::where('onboarding_id', $onboarding->id)
                    ->get()
                    ->keyBy('chave');

                foreach ($passosDestino as $templatePasso) {
                    $passoAtual = $passosAtuais->get($templatePasso->chave);

                    if ($passoAtual === null) {
                        OnboardingPasso::create([
                            'onboarding_id'     => $onboarding->id,
                            'template_passo_id' => $templatePasso->id,
                            'chave'             => $templatePasso->chave,
                            'status'            => OnboardingPasso::STATUS_BLOQUEADO,
                        ]);

                        continue;
                    }

                    // Mesma chave — mantém status/valor/disponivel_em, só
                    // reaponta para o TemplatePasso da versão nova.
                    $passoAtual->template_passo_id = $templatePasso->id;
                    $passoAtual->save();
                }

                foreach ($passosAtuais as $chave => $passoAtual) {
                    if (in_array($chave, $chavesDestino, true)) {
                        continue;
                    }

                    if ($passoAtual->status === OnboardingPasso::STATUS_NAO_APLICAVEL) {
                        continue;
                    }

                    $passoAtual->status = OnboardingPasso::STATUS_NAO_APLICAVEL;
                    $passoAtual->ultimo_erro = "Passo saiu do template na versão {$destino->versao} — histórico preservado.";
                    $passoAtual->save();
                }

                activity('onboarding')
                    ->performedOn($onboarding)
                    ->withProperties([
                        'template_origem_id'  => $templateOrigemId,
                        'template_destino_id' => $destino->id,
                    ])
                    ->log("Onboarding {$onboarding->id} migrado para a versão {$destino->versao} do template");
            });

            $this->engine->reavaliar($onboarding->fresh());

            $migrados++;
        }

        return $migrados;
    }

    /**
     * DFS de 3 cores (branco/cinza/preto) sobre o mapa `chave => depende_de[]`.
     * Devolve `null` sem ciclo, ou o caminho fechado (`['a','b','a']`) para a
     * mensagem de erro do FormRequest (SC-08). Referência a chave inexistente
     * NÃO é ciclo — é erro de shape tratado pelo FormRequest; arestas para
     * chaves ausentes do payload são ignoradas aqui.
     *
     * @param array<int, array{chave?: string, depende_de?: array<int, string>|null}> $passos
     * @return array<int, string>|null
     */
    public function detectarCiclo(array $passos): ?array
    {
        $chavesValidas = [];
        foreach ($passos as $passo) {
            if (isset($passo['chave'])) {
                $chavesValidas[$passo['chave']] = true;
            }
        }

        $grafo = [];
        foreach ($passos as $passo) {
            if (! isset($passo['chave'])) {
                continue;
            }

            $dependeDe = $passo['depende_de'] ?? [];
            $grafo[$passo['chave']] = array_values(array_filter(
                is_array($dependeDe) ? $dependeDe : [],
                static fn ($dependencia) => isset($chavesValidas[$dependencia])
            ));
        }

        $cor = array_fill_keys(array_keys($grafo), 0); // 0=branco, 1=cinza, 2=preto

        foreach (array_keys($grafo) as $chave) {
            if ($cor[$chave] === 0) {
                $caminho = $this->dfsDetectarCiclo($chave, $grafo, $cor, []);
                if ($caminho !== null) {
                    return $caminho;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, array<int, string>> $grafo
     * @param array<string, int> $cor
     * @param array<int, string> $pilha
     * @return array<int, string>|null
     */
    private function dfsDetectarCiclo(string $chave, array $grafo, array &$cor, array $pilha): ?array
    {
        $cor[$chave] = 1;
        $pilha[] = $chave;

        foreach ($grafo[$chave] ?? [] as $vizinho) {
            if (($cor[$vizinho] ?? 0) === 1) {
                // Ciclo: o caminho fechado começa onde $vizinho já apareceu
                // na pilha atual e termina revisitando $vizinho.
                $indice = array_search($vizinho, $pilha, true);
                $cicloFechado = array_slice($pilha, $indice);
                $cicloFechado[] = $vizinho;

                return $cicloFechado;
            }

            if (($cor[$vizinho] ?? 0) === 0) {
                $resultado = $this->dfsDetectarCiclo($vizinho, $grafo, $cor, $pilha);
                if ($resultado !== null) {
                    return $resultado;
                }
            }
        }

        $cor[$chave] = 2;

        return null;
    }

    /**
     * Contagem de onboardings ainda não concluídos rodando nesta versão —
     * alimenta o aviso do dialog de publicar (o UI-SPEC só mostra o dialog
     * quando há impacto real).
     */
    public function contarOnboardingsNaVersao(OnboardingTemplate $template): int
    {
        return Onboarding::where('template_id', $template->id)
            ->naoConcluido()
            ->count();
    }
}
