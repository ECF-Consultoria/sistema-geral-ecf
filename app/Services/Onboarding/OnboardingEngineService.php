<?php

namespace App\Services\Onboarding;

use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\User;
use App\Support\Onboarding\DefinicaoOnboarding;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * OnboardingEngineService — motor do onboarding geral por serviço (Fase 135).
 *
 * Monta um onboarding a partir da versão ATIVA e CONGELADA do template do
 * serviço no momento da criação (D-07/SC-09) e sabe destravar, carimbar e
 * pular passo sozinho — os métodos de avaliação (`reavaliar()`,
 * `aplicarResultado()`, `avaliarCondicao()`, `concluirManualmente()`) são
 * acrescentados na Task 3 deste mesmo plano.
 *
 * Molde de estilo: AdmanService (métodos por responsabilidade, docblock
 * descrevendo o shape de retorno) e DiagnoseCustId (classificação por
 * resultado, sem booleano solto).
 */
class OnboardingEngineService
{
    /**
     * Cria o onboarding em rascunho para um contrato de serviço, montado da
     * versão ativa do template — ou devolve `null` quando o serviço não tem
     * template publicado (D-08: só Gestão nesta v1; os outros serviços do
     * catálogo ficam inertes até ganharem template próprio).
     *
     * Guard de duplicidade em DUAS camadas, sem lançar exceção — este
     * método roda dentro do Observer (Plano 05), por sua vez dentro do
     * loop SEM transação de `CompanyGroupController::atribuirServico()`; uma
     * exceção aqui derrubaria a request inteira:
     *  - já existe onboarding para este `contrato_servico_id` (também é
     *    constraint de banco, ver Plano 02) → devolve o existente;
     *  - já existe onboarding NÃO concluído para o par `company_id` ×
     *    `servico_id` (D-01: um por empresa × serviço) → devolve o
     *    existente, mesmo que o `contrato_servico_id` seja diferente.
     *
     * Todo o trabalho é banco local — nenhuma chamada de rede, nenhum
     * client HTTP, nenhum comando Artisan disparado a partir daqui.
     */
    public function criarParaContrato(ContratoServico $contrato): ?Onboarding
    {
        $existentePorContrato = Onboarding::where('contrato_servico_id', $contrato->id)->first();
        if ($existentePorContrato) {
            return $existentePorContrato;
        }

        $existentePorParEmpresaServico = Onboarding::where('company_id', $contrato->company_id)
            ->where('servico_id', $contrato->servico_id)
            ->naoConcluido()
            ->first();
        if ($existentePorParEmpresaServico) {
            return $existentePorParEmpresaServico;
        }

        $definicao = $contrato->servico
            ? DefinicaoOnboarding::paraServico($contrato->servico)
            : null;

        if ($definicao === null) {
            Log::info(
                "[Onboarding] serviço sem definição de onboarding — contrato {$contrato->id} "
                . "(servico_id {$contrato->servico_id}) não gera onboarding."
            );

            return null;
        }

        $onboarding = Onboarding::create([
            'company_id'          => $contrato->company_id,
            'servico_id'          => $contrato->servico_id,
            'contrato_servico_id' => $contrato->id,
            'definicao_versao'    => DefinicaoOnboarding::VERSAO,
            'status'              => Onboarding::STATUS_RASCUNHO,
        ]);

        $this->montarPassos($onboarding);

        // D-17: a sugestão nasce junto, mas sugerir não é confirmar (D-05) —
        // o onboarding continua em rascunho até a Coordenação confirmar pela
        // Tela 1 via confirmarResponsavel().
        $sugerido = $this->sugerirResponsavel($onboarding);
        if ($sugerido) {
            $onboarding->responsavel_id = $sugerido->id;
            $onboarding->save();
        }

        return $onboarding;
    }

    /**
     * Sugere quem deve atender o onboarding a partir do vínculo já existente
     * da empresa naquele serviço (D-17) — percorre
     * {@see Onboarding::ROLES_RESPONSAVEL_SUGERIDO} em ordem, consultando
     * `Company::responsavelDoServicoOuConsolidado()`, e devolve o primeiro
     * `User` da primeira coleção não-vazia. Sem vínculo em nenhum papel →
     * `null` (o onboarding nasce/permanece sem responsável).
     */
    public function sugerirResponsavel(Onboarding $onboarding): ?User
    {
        foreach (Onboarding::ROLES_RESPONSAVEL_SUGERIDO as $role) {
            $vinculados = $onboarding->company
                ->responsavelDoServicoOuConsolidado($role, $onboarding->servico_id);

            if ($vinculados->isNotEmpty()) {
                return $vinculados->first();
            }
        }

        return null;
    }

    /**
     * Confirma o responsável e transiciona o onboarding de `rascunho` para
     * `andamento` — o único caminho para essa transição (D-05/SC-04). Grava
     * `responsavel_id`/`iniciado_em` e chama `reavaliar()`, que é quem
     * carimba `disponivel_em` dos passos sem dependência.
     *
     * Só aceita onboarding em `rascunho`; qualquer outro status é erro de
     * uso (a Coordenação não confirma duas vezes nem reabre um onboarding já
     * em andamento por aqui).
     */
    public function confirmarResponsavel(Onboarding $onboarding, User $responsavel): Onboarding
    {
        if ($onboarding->status !== Onboarding::STATUS_RASCUNHO) {
            throw new \DomainException(
                "Onboarding {$onboarding->id} não está em rascunho (status atual: {$onboarding->status}) "
                . '— responsável só pode ser confirmado uma vez, a partir do rascunho (D-05).'
            );
        }

        // Um clique, um responsável: o slot sai do vínculo que a pessoa já tem
        // com a empresa — mesma regra do backfill da migration dos dois
        // responsáveis. Quem quiser preencher os dois papéis usa
        // definirResponsaveis() direto.
        $ehEstrategista = $this->papelNaEmpresa($onboarding, $responsavel) === 'estrategista';

        return $this->definirResponsaveis(
            $onboarding,
            $ehEstrategista ? $responsavel : null,
            $ehEstrategista ? null : $responsavel,
        );
    }

    /**
     * Define estrategista e/ou analista do onboarding (R-01) e, se ele ainda
     * estiver em `rascunho`, liga o SLA.
     *
     * **Qualquer um dos dois basta para ligar** (R-02): confirmar só o
     * estrategista, ou só o analista, já leva a `andamento`, carimba
     * `iniciado_em` e libera o portal do cliente. A tela cobra o papel que
     * faltar como pendência. Espelha a régua que `/companies` já aplica
     * (`em_operacao` = tem pelo menos um dos dois papéis), em vez de criar uma
     * segunda verdade sobre quem cuida da empresa.
     *
     * Diferente de {@see self::confirmarResponsavel()}, aceita onboarding já
     * em `andamento`: é o caminho para preencher depois o papel que faltava —
     * sem ele, "a tela cobra o que falta" seria cobrança sem botão. Passar
     * `null` num papel APAGA aquele slot; o guard é que os dois não podem
     * ficar vazios ao mesmo tempo.
     *
     * @param  ?User  $estrategista  null apaga o slot de estrategista
     * @param  ?User  $analista      null apaga o slot de analista
     */
    public function definirResponsaveis(
        Onboarding $onboarding,
        ?User $estrategista,
        ?User $analista,
    ): Onboarding {
        if ($estrategista === null && $analista === null) {
            throw new \DomainException(
                "Onboarding {$onboarding->id} precisa de ao menos um responsável — "
                . 'estrategista ou analista (R-02).'
            );
        }

        if ($onboarding->status === Onboarding::STATUS_CONCLUIDO) {
            throw new \DomainException(
                "Onboarding {$onboarding->id} já está concluído — responsável não muda mais."
            );
        }

        $onboarding->responsavel_estrategista_id = $estrategista?->id;
        $onboarding->responsavel_analista_id = $analista?->id;

        // Invariante do responsável PRINCIPAL (decisão de schema §2.2): se um
        // dos slots está preenchido, `responsavel_id` aponta para um deles.
        // Mantém quem já estava lá se ainda ocupar um slot — trocar o
        // principal sem necessidade mexeria no que o portal e o detalhe
        // mostram como "seu responsável".
        $slots = array_filter([$estrategista?->id, $analista?->id]);
        if (! in_array($onboarding->responsavel_id, $slots, true)) {
            $onboarding->responsavel_id = $estrategista?->id ?? $analista?->id;
        }

        $ligouAgora = $onboarding->status === Onboarding::STATUS_RASCUNHO;

        if ($ligouAgora) {
            $onboarding->status = Onboarding::STATUS_ANDAMENTO;
            $onboarding->iniciado_em = now();
        }

        $onboarding->save();

        // `reavaliar()` é quem carimba `disponivel_em` dos passos sem
        // dependência — só faz sentido na transição, não a cada ajuste de
        // papel num onboarding que já está correndo.
        if ($ligouAgora) {
            $this->reavaliar($onboarding);
        }

        activity('onboarding')
            ->performedOn($onboarding)
            ->withProperties([
                'responsavel_id'              => $onboarding->responsavel_id,
                'responsavel_estrategista_id' => $onboarding->responsavel_estrategista_id,
                'responsavel_analista_id'     => $onboarding->responsavel_analista_id,
            ])
            ->log($ligouAgora
                ? "Responsável confirmado — onboarding {$onboarding->id} em andamento"
                : "Responsáveis atualizados — onboarding {$onboarding->id}");

        return $onboarding;
    }

    /**
     * Papel do usuário NAQUELA empresa, lido de `company_users`: devolve
     * `'estrategista'` só quando é estrategista e não é consultor.
     *
     * Mesma regra do backfill da migration — quem tem os dois vínculos cai no
     * lado do analista, que é o papel operacional e o primeiro de
     * {@see Onboarding::ROLES_RESPONSAVEL_SUGERIDO}.
     */
    private function papelNaEmpresa(Onboarding $onboarding, User $usuario): string
    {
        $papeis = $onboarding->company
            ->users()
            ->where('users.id', $usuario->id)
            ->pluck('company_users.role')
            ->all();

        return in_array('estrategista', $papeis, true) && ! in_array('consultor', $papeis, true)
            ? 'estrategista'
            : 'analista';
    }

    // ─── Reunião de onboarding ───────────────────────────────────────────────

    /**
     * O CLIENTE pede a reunião pelo portal — sem data, que é o ponto: ele não
     * escolhe agenda nossa, só sinaliza que quer.
     *
     * Idempotente e não-regressivo: pedir duas vezes não move nada, e pedir
     * depois de já haver data marcada NÃO rebaixa o status para `solicitada`
     * (senão um clique acidental do cliente apagaria da tela dele a data que
     * já estava combinada).
     *
     * Só vale para onboarding em `andamento` — rascunho não tem portal
     * (D-05/SC-04).
     */
    public function solicitarReuniao(Onboarding $onboarding, ?string $ip = null): bool
    {
        if ($onboarding->status !== Onboarding::STATUS_ANDAMENTO) {
            return false;
        }

        if ($onboarding->reuniao_status === Onboarding::REUNIAO_AGENDADA) {
            return false;
        }

        if ($onboarding->reuniao_status === Onboarding::REUNIAO_SOLICITADA) {
            return false;
        }

        $onboarding->reuniao_status = Onboarding::REUNIAO_SOLICITADA;
        $onboarding->reuniao_solicitada_em = now();
        $onboarding->save();

        activity('onboarding')
            ->performedOn($onboarding)
            ->withProperties(['ip' => $ip])
            ->log('Cliente solicitou a reunião de onboarding pelo portal');

        return true;
    }

    /**
     * O RESPONSÁVEL marca data e hora. A partir daqui o cliente enxerga a data
     * no portal — é a volta da informação que ele pediu.
     *
     * Remarcar é só chamar de novo com outra data; o estado continua
     * `agendada` e o activity guarda as duas datas para reconstruir o
     * histórico sem precisar de tabela de remarcação.
     */
    public function agendarReuniao(Onboarding $onboarding, CarbonInterface $quando, User $por): Onboarding
    {
        if ($onboarding->status !== Onboarding::STATUS_ANDAMENTO) {
            throw new \DomainException('Só é possível agendar reunião de onboarding em andamento.');
        }

        $anterior = $onboarding->reuniao_agendada_para;

        $onboarding->reuniao_status = Onboarding::REUNIAO_AGENDADA;
        $onboarding->reuniao_agendada_para = $quando;
        $onboarding->reuniao_agendada_por = $por->id;
        $onboarding->save();

        activity('onboarding')
            ->performedOn($onboarding)
            ->withProperties([
                'agendada_para'   => $quando->toDateTimeString(),
                'anterior'        => $anterior?->toDateTimeString(),
                'agendada_por'    => $por->id,
            ])
            ->log($anterior
                ? "Reunião de onboarding remarcada para {$quando->format('d/m/Y H:i')}"
                : "Reunião de onboarding agendada para {$quando->format('d/m/Y H:i')}");

        return $onboarding;
    }

    /**
     * `true` só quando há template congelado, todos os passos do template
     * já foram montados e existe um responsável disponível — confirmado ou
     * (ainda) apenas sugerido (D-17). Usado pela Tela 1 para decidir se o
     * botão de confirmar responsável fica habilitado.
     */
    public function podeIniciar(Onboarding $onboarding): bool
    {
        $definicao = $onboarding->servico
            ? DefinicaoOnboarding::paraServico($onboarding->servico)
            : null;

        if ($definicao === null) {
            return false;
        }

        $totalPassosMontados = OnboardingPasso::where('onboarding_id', $onboarding->id)->count();

        if ($totalPassosMontados === 0 || $totalPassosMontados !== count($definicao)) {
            return false;
        }

        // Os dois slots contam junto com o principal (R-01): um onboarding
        // com analista definido e `responsavel_id` vazio por qualquer motivo
        // continua podendo iniciar.
        return $onboarding->responsavel_id !== null
            || $onboarding->temAlgumResponsavel()
            || $this->sugerirResponsavel($onboarding) !== null;
    }

    /**
     * Cria 1 OnboardingPasso por entrada da {@see DefinicaoOnboarding} do
     * serviço, ordenado por `ordem`, COPIANDO a definição inteira (título,
     * dono, setor, dependências, SLA, fonte automática e condição) para dentro
     * da linha — é a cópia, e não uma referência, que congela o onboarding
     * contra mudanças futuras da receita.
     *
     * Todos nascem `status = bloqueado` e `disponivel_em = null` —
     * inclusive os passos sem `depende_de` — porque o onboarding nasce em
     * `rascunho` e rascunho não corre SLA (D-05/SC-04); só `reavaliar()`
     * (Task 3) destrava, e só quando o onboarding estiver em `andamento`.
     *
     * Inserção em lote (`insert()` com timestamps explícitos) para manter o
     * custo baixo no cenário do `CompanyGroupController` (loop sem
     * transação, pode rodar N vezes numa única request).
     */
    public function montarPassos(Onboarding $onboarding): void
    {
        $definicao = $onboarding->servico
            ? DefinicaoOnboarding::paraServico($onboarding->servico)
            : null;

        if (empty($definicao)) {
            return;
        }

        $agora = now();

        // A definição é COPIADA para dentro do passo, não referenciada. É isso
        // que congela o onboarding: mudar a receita em código não mexe em quem
        // já nasceu.
        $linhas = collect($definicao)
            ->sortBy('ordem')
            ->map(fn (array $passo) => [
                'onboarding_id' => $onboarding->id,
                'ordem'         => $passo['ordem'],
                'etapa'         => $passo['etapa'] ?? null,
                // Estrutural como `etapa`: copiada, não referenciada. Default
                // `acao` para que uma entrada de definição que esqueça o campo
                // nasça no valor mais conservador em vez de `null`.
                'natureza'      => $passo['natureza'] ?? OnboardingPasso::NATUREZA_ACAO,
                'chave'         => $passo['chave'],
                'titulo'        => $passo['titulo'],
                'dono'          => $passo['dono'],
                'setor_id'      => $passo['setor_id'] ?? null,
                'depende_de'    => isset($passo['depende_de']) ? json_encode($passo['depende_de']) : null,
                'sla_dias'      => $passo['sla_dias'] ?? null,
                'auto_fonte'    => $passo['auto_fonte'] ?? null,
                'condicao'      => isset($passo['condicao']) ? json_encode($passo['condicao']) : null,
                'status'        => OnboardingPasso::STATUS_BLOQUEADO,
                'disponivel_em' => null,
                'created_at'    => $agora,
                'updated_at'    => $agora,
            ])
            ->values()
            ->all();

        OnboardingPasso::insert($linhas);
    }

    /**
     * Chave do passo "Anúncios ativos / inativos" — alvo fixo da condição
     * {@see OnboardingPasso::CONDICAO_ANUNCIOS_INATIVOS}.
     */
    private const CHAVE_PASSO_ANUNCIOS_ATIVOS_INATIVOS = 'anuncios_ativos_inativos';

    /**
     * Recalcula o estado de TODOS os passos do onboarding: destrava quem
     * pode, carimba `disponivel_em`, aplica condição e conclui o onboarding
     * quando cabe (regras 1 a 10b do plano).
     *
     * Onboarding em `rascunho` não destrava nada e retorna cedo (regra 1 —
     * D-05/SC-04, rascunho não corre SLA). Itera em laço até estabilizar
     * (destravar um passo pode destravar outro na mesma passada), com um
     * limite de passadas igual ao número de passos — defesa contra um
     * `depende_de` cíclico que escapasse da guarda de ciclo do CRUD de
     * template (Plano 08); o cenário normal nunca chega perto do limite.
     */
    public function reavaliar(Onboarding $onboarding): void
    {
        if ($onboarding->status === Onboarding::STATUS_RASCUNHO) {
            return;
        }

        $passos = OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->get()
            ->keyBy('chave');

        if ($passos->isEmpty()) {
            return;
        }

        $limiteDePassadas = $passos->count();

        for ($passada = 0; $passada < $limiteDePassadas; $passada++) {
            $algumPassoMudou = false;

            foreach ($passos as $passo) {
                if ($passo->status !== OnboardingPasso::STATUS_BLOQUEADO) {
                    continue;
                }

                $dependeDe = $passo->depende_de ?? [];

                $todasAsDependenciasResolvidas = collect($dependeDe)->every(
                    fn (string $chave) => $this->dependenciaResolvida($passos->get($chave))
                );

                if (! $todasAsDependenciasResolvidas) {
                    continue;
                }

                $novoStatus = OnboardingPasso::STATUS_ABERTO;
                $novoAutoEm = null;

                if ($passo->condicao) {
                    $avaliacao = $this->avaliarCondicao($passo);

                    if ($avaliacao === null) {
                        // Regra 5: ainda não dá para saber — segue bloqueado,
                        // sem carimbar disponivel_em (o passo não destravou).
                        continue;
                    }

                    if ($avaliacao === false) {
                        $novoStatus = OnboardingPasso::STATUS_NAO_APLICAVEL;
                        $novoAutoEm = now();
                    }
                }

                // Regra 4: disponivel_em é gravado uma única vez.
                if ($passo->disponivel_em === null) {
                    $passo->disponivel_em = now();
                }

                $passo->status = $novoStatus;
                if ($novoAutoEm !== null) {
                    $passo->auto_em = $novoAutoEm;
                }
                $passo->save();

                $algumPassoMudou = true;
            }

            if (! $algumPassoMudou) {
                break;
            }
        }

        $this->avaliarConclusaoDoOnboarding($onboarding);
    }

    /** Uma dependência está resolvida quando o passo referenciado concluiu ou não se aplica (regra 3). */
    private function dependenciaResolvida(?OnboardingPasso $passoDependido): bool
    {
        return $passoDependido !== null && in_array(
            $passoDependido->status,
            [OnboardingPasso::STATUS_CONCLUIDO, OnboardingPasso::STATUS_NAO_APLICAVEL],
            true
        );
    }

    /**
     * Traduz o resultado de 3 estados de um resolver automático (Planos
     * 03/06/07) para `status`/`valor`/`auto_em`/`coleta_iniciada_em` —
     * regras 10 e 10b. Não recebe o resolver: o único canal de "coleta
     * disparada" é a chave reservada dentro de `$resultado`, lida via
     * {@see \App\Services\Onboarding\OnboardingResolverResultado::sinalizouColetaEmAndamento()}.
     *
     * Em nenhum ramo de `nao_coletado`/`indeterminado` o `valor` do passo é
     * escrito (D-11 — só `concluido` grava valor numérico definitivo); e a
     * chave reservada nunca é copiada para `onboarding_passos.valor` (é
     * canal de controle, não dado de negócio).
     */
    public function aplicarResultado(OnboardingPasso $passo, OnboardingResolverResultado $resultado): void
    {
        if ($resultado->ehConcluido()) {
            $passo->status = OnboardingPasso::STATUS_CONCLUIDO;
            $passo->valor = $resultado->valor;
            $passo->auto_em = now();
        } elseif ($resultado->ehIndeterminado()) {
            $passo->status = OnboardingPasso::STATUS_INDETERMINADO;
            $passo->tentativas = $passo->tentativas + 1;
            $passo->ultimo_erro = $resultado->motivo;
        } else {
            // nao_coletado — regra 10: só vira aguardando_coleta quando o
            // resolver sinalizou coleta em curso; senão segue aberto (passo
            // travado por pendência humana continua visível — SC-11/D-11).
            $passo->ultimo_erro = $resultado->motivo;

            if ($resultado->sinalizouColetaEmAndamento()) {
                $passo->status = OnboardingPasso::STATUS_AGUARDANDO_COLETA;

                // Regra 10b: carimbo de DISPARO, gravado uma única vez —
                // reconfirmação da mesma coleta em curso não reescreve.
                if ($passo->coleta_iniciada_em === null) {
                    $passo->coleta_iniciada_em = now();
                }
            } else {
                $passo->status = OnboardingPasso::STATUS_ABERTO;
            }
        }

        $passo->save();

        $this->reavaliar($passo->onboarding);
    }

    /**
     * `null` quando o passo não tem `condicao` (equivale a "sempre aplica")
     * ou quando ainda não dá para decidir (regra 5). Condição fora do
     * catálogo fechado ({@see OnboardingPasso::CONDICOES}) lança
     * `\RuntimeException` — nunca interpreta expressão livre (D-09/D-12).
     */
    public function avaliarCondicao(OnboardingPasso $passo): ?bool
    {
        $condicao = $passo->condicao ?? null;

        if (! $condicao) {
            return null;
        }

        $tipo = $condicao['tipo'] ?? null;

        return match ($tipo) {
            OnboardingPasso::CONDICAO_ANUNCIOS_INATIVOS => $this->avaliarCondicaoAnunciosInativos($passo),
            default => throw new \RuntimeException(
                "Condição \"{$tipo}\" fora do catálogo fechado (OnboardingPasso::CONDICOES) — D-09/D-12."
            ),
        };
    }

    /**
     * D-12: só se aplica se o passo "Anúncios ativos / inativos" do MESMO
     * onboarding apurou inativos > 0. Passo ainda não concluído → `null`
     * (regra 6 — não decide por omissão).
     */
    private function avaliarCondicaoAnunciosInativos(OnboardingPasso $passo): ?bool
    {
        $passoAnuncios = OnboardingPasso::where('onboarding_id', $passo->onboarding_id)
            ->where('chave', self::CHAVE_PASSO_ANUNCIOS_ATIVOS_INATIVOS)
            ->first();

        if (! $passoAnuncios || $passoAnuncios->status !== OnboardingPasso::STATUS_CONCLUIDO) {
            return null;
        }

        return (($passoAnuncios->valor['inativos'] ?? 0)) > 0;
    }

    /**
     * Conclusão manual de um passo pelo dono humano. Lança `\DomainException`
     * quando o passo tem `auto_fonte` preenchido (D-19) — nem cliente nem
     * interno fecha na mão um passo que só o resolver fecha.
     */
    public function concluirManualmente(OnboardingPasso $passo, User $usuario, bool $forcar = false): void
    {
        // D-19 continua valendo para o CLIENTE: pelo portal público nunca se
        // fecha na mão um passo que o sistema verifica sozinho.
        //
        // Para quem opera por dentro, `$forcar` abre a exceção — e ela existe
        // porque a regra sem escape criava beco sem saída real: "Planilha de
        // custos ADMAN" só fecha quando `companies.adman_account_id` está
        // preenchido, e empresa conectada só por OAuth não tem esse campo.
        // O passo não fechava sozinho e não podia ser fechado à mão: ficava
        // travado para sempre, segurando tudo que dependia dele.
        //
        // O override fica REGISTRADO em `valor` — quem olhar depois vê que
        // aquele "concluído" foi decisão de gente, não apuração do sistema.
        if ($passo->auto_fonte !== null && ! $forcar) {
            throw new \DomainException(
                "O passo \"{$passo->titulo}\" tem verificação automática — conclusão manual não é permitida (D-19)."
            );
        }

        if ($passo->auto_fonte !== null) {
            $passo->valor = array_merge($passo->valor ?? [], [
                'concluido_manualmente' => true,
                'override_por'          => $usuario->id,
                'override_em'           => now()->toISOString(),
            ]);
        }

        $passo->status = OnboardingPasso::STATUS_CONCLUIDO;
        $passo->feito_por = $usuario->id;
        $passo->feito_em = now();
        $passo->save();

        $this->reavaliar($passo->onboarding);
    }

    /**
     * Desfaz a conclusão de um passo — o "desmarcar" que faltava.
     *
     * Sem isto, um clique errado era definitivo: não havia caminho de volta em
     * tela nenhuma, e a única saída era mexer no banco.
     *
     * Volta para `aberto` e deixa a reavaliação decidir o estado final (pode
     * virar `bloqueado` de novo se a dependência não estiver cumprida). Limpa
     * `feito_por`/`feito_em` e a marca de override — o passo deixa de alegar
     * que alguém o concluiu.
     *
     * Passo automático volta a ser do resolver: na próxima passada ele reapura
     * e conclui de novo se o dado estiver lá. Reabrir não é "negar o dado", é
     * "recomeçar a apuração".
     */
    public function reabrirPasso(OnboardingPasso $passo, User $usuario): void
    {
        if ($passo->status !== OnboardingPasso::STATUS_CONCLUIDO) {
            throw new \DomainException(
                "O passo \"{$passo->titulo}\" não está concluído — não há o que desmarcar."
            );
        }

        $passo->status = OnboardingPasso::STATUS_ABERTO;
        $passo->feito_por = null;
        $passo->feito_em = null;

        if (is_array($passo->valor)) {
            $passo->valor = array_diff_key($passo->valor, array_flip([
                'concluido_manualmente', 'override_por', 'override_em',
            ]));
        }

        $passo->save();

        activity('onboarding')
            ->performedOn($passo->onboarding)
            ->withProperties(['passo_id' => $passo->id, 'chave' => $passo->chave, 'por' => $usuario->id])
            ->log("Passo \"{$passo->titulo}\" desmarcado");

        $this->reavaliar($passo->onboarding);
    }

    /**
     * Fecha o onboarding (`status=concluido`, `concluido_em`) quando TODO passo
     * está em `concluido` ou `nao_aplicavel` (regra 8). Registrado via activity
     * log, mesma disciplina do `MlbEmpresaObserver`.
     *
     * O eixo `obrigatorio` do template antigo foi removido junto com as tabelas
     * de definição: nenhum passo real jamais nasceu opcional, e "opcional" já é
     * expresso melhor por `condicao` (que produz `nao_aplicavel` e sai do
     * denominador). Passo que não deve travar a conclusão ganha condição, não
     * uma flag.
     */
    private function avaliarConclusaoDoOnboarding(Onboarding $onboarding): void
    {
        if ($onboarding->status === Onboarding::STATUS_CONCLUIDO) {
            return;
        }

        $passos = OnboardingPasso::where('onboarding_id', $onboarding->id)->get();

        if ($passos->isEmpty()) {
            return;
        }

        $temObrigatorioPendente = $passos
            ->contains(fn (OnboardingPasso $p) => ! in_array(
                $p->status,
                [OnboardingPasso::STATUS_CONCLUIDO, OnboardingPasso::STATUS_NAO_APLICAVEL],
                true
            ));

        if ($temObrigatorioPendente) {
            return;
        }

        $onboarding->status = Onboarding::STATUS_CONCLUIDO;
        $onboarding->concluido_em = now();
        $onboarding->save();

        Log::info(
            "[Onboarding] onboarding {$onboarding->id} concluído — todos os passos obrigatórios "
            . 'em concluido/nao_aplicavel.'
        );

        activity('onboarding')
            ->performedOn($onboarding)
            ->withProperties(['status' => Onboarding::STATUS_CONCLUIDO])
            ->log('Onboarding concluído');
    }
}
