<?php

namespace App\Services\Usuarios;

use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\Onboarding;
use App\Services\Desempenho\CompanyScoreSnapshotWriter;
use App\Services\DesempenhoScoreService;
use App\Services\Nps\NpsJanelaResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fase 159 Planos 159-05/159-06 (D-06/D-11) — junção das contas: NÚCLEO
 * (carteira, histórico de gestão, cargos, desativação — 159-05) e as etapas
 * de NPS, snapshots, PPAs e onboardings em aberto (159-06) sobre a mesma
 * estrutura.
 *
 * Por que existe: a conta de origem (`--de`) deixa de existir como perfil
 * ativo e todo o histórico vivo que ela carrega (carteira, cargos) passa
 * para a conta de destino (`--para`) a partir de uma competência. Esse dado
 * alimenta bônus — um erro aqui é irreversível se não houver backup, e pode
 * passar por "sucesso" se a conferência for só pelo texto impresso na tela
 * (learnings §4/§10.1: `consolidar-mes` já devolveu exit 0 falhando para 11
 * de 12 profissionais). Por isso:
 *  - `planejar()` é SOMENTE LEITURA — nunca escreve, serve tanto para o
 *    dry-run quanto para a reconsulta pós-`--apply`.
 *  - `aplicar()` grava o estado ANTERIOR em `unificacao_contas_backup` antes
 *    de cada UPDATE/DELETE (e o estado NOVO logo após cada INSERT), tudo
 *    dentro de uma única transação — e RELÊ cada linha lá dentro, recusando
 *    o lote inteiro se o banco mudou desde o plano (WR-02, TOCTOU).
 *  - `desfazer()` restaura um lote inteiro a partir do backup, em ordem
 *    inversa, sem `try/catch` — colisão ao restaurar derruba a transação
 *    inteira em vez de mascarar o erro.
 *
 * Por que cada tabela do núcleo entra ou fica de fora (D-06/CONTEXT.md):
 *  - `company_users` (etapa `carteira`) — carteira ATIVA; move porque é o
 *    que decide quem atende qual empresa hoje. Quando o destino já tem a
 *    mesma linha (mesma empresa, role e serviço), mover colidiria no unique
 *    — a linha da origem é apagada em vez disso (a de destino já cobre).
 *  - `company_manager_history` (etapa `historico_gestao`) — é log
 *    append-only (Fase 108). A junção NUNCA reescreve uma linha existente,
 *    só ACRESCENTA os eventos de saída/entrada que a troca de responsável
 *    gera — mesma disciplina que `CompanyController::registrarHistoricoGestao()`
 *    já segue para qualquer outra troca de responsável.
 *  - `user_setores` (etapa `cargos`) — o destino GANHA o cargo que só a
 *    origem tinha (usa D-01: duas linhas no mesmo setor já são permitidas).
 *    As linhas da origem NÃO são apagadas nem movidas — ficam como estão,
 *    o histórico de quem teve qual cargo continua no nome de quem teve.
 *  - `users.active` (etapa `desativar_origem`, sempre a ÚLTIMA) — a origem é
 *    DESATIVADA, nunca apagada: o histórico dos meses fechados continua
 *    apontando para o nome dela. Nenhuma outra coluna de `users` é tocada —
 *    em especial nunca `password`/`remember_token` vão para o backup.
 *
 * Por que as etapas de NPS/snapshots entram (159-06, D-06) — nesta ordem,
 * depois de `cargos` e antes de `desativar_origem`:
 *  - `nps_atribuicoes` — `nps_score_assignments` não tem coluna de mês; a
 *    competência é sempre o JOIN com `nps_responses`/`nps_surveys` (NUNCA
 *    `assigned_at`/`month_reference` — Pitfall 4 da pesquisa). A linha move
 *    quando `completed_at` do survey cai no mês de COLETA (M+1,
 *    `NpsJanelaResolver::mesDeColeta`) a partir do corte de `--a-partir`.
 *  - `nps_imputacoes` — `nps_imputed_assignments.competencia_nps` JÁ é o mês
 *    de coleta materializado (ver `NpsImputationService`) — comparação
 *    direta, sem JOIN.
 *  - Nas duas, colisão (destino já tem a mesma linha pelo grão da origem)
 *    vira `delete` da origem, nunca `update` que colidiria no unique/no
 *    mesmo `(response,role,servico)`.
 *  - `snapshots_diarios`/`snapshots_empresa` — só o que é CACHE (diário sem
 *    `mes_referencia`, ou detalhe por empresa com `origem` != consolidar_mes)
 *    a partir do corte é removido; o que já é competência FECHADA
 *    (`consolidar_mes`, ou mensal — bloqueado antes de chegar aqui) nunca é
 *    tocado. A janela de coleta do NPS abre DEPOIS do corte financeiro
 *    (01/10 para a competência 09) — por isso `planejar()` sempre acrescenta
 *    um aviso de re-execução (ver fim do método).
 *
 * Trava de competência consolidada (D-06): a junção nunca recua sobre uma
 * competência já fechada — nem por snapshot mensal (`desempenho_score_snapshots`)
 * nem por detalhe por empresa gravado por `desempenho:consolidar-mes`
 * (`desempenho_company_score_snapshots.origem = consolidar_mes`). "Fechada"
 * aqui é sempre >= o início de `--a-partir`: competências ANTERIORES a
 * `--a-partir` nem entram NESTA conta, porque a junção não pretende tocá-las.
 *
 * Trava da competência ANTERIOR (CR-02 da revisão): a junção também exige
 * que `--a-partir − 1` JÁ esteja consolidada (snapshot mensal) para origem e
 * destino que têm carteira — `company_users` não tem dimensão temporal, e
 * mover a carteira recalcularia ao vivo um mês fechado sem snapshot. Ver
 * {@see self::bloqueiosCompetenciaAnteriorSemConsolidar()}.
 *
 * Censo (D-11): antes de aceitar `--apply`, o comando levanta TODA coluna do
 * banco que referencia `users` (FK ou heurística de nome `user_id`) e conta
 * quantas linhas da origem existem em cada uma. Tabela sem regra conhecida E
 * com linhas da origem vira pendência — o operador decide com `--manter`,
 * nunca o comando sozinho.
 */
class UnificacaoContasService
{
    /**
     * Classificação fixa por `tabela.coluna` — quando a chave não aparece
     * aqui, a classificação cai nas regras heurísticas de
     * {@see self::classificarCenso()} (prefixo `dev_`/`chamado`, sufixo
     * `_by`/`_por`, nomes de autoria) e, por fim, em `sem_regra`.
     *
     * 'tratada'  — a junção MOVE o dado (as etapas deste plano cuidam disso;
     *              o 159-06 acrescenta as suas).
     * 'mantida'  — a junção DELIBERADAMENTE deixa com a origem, com o motivo
     *              documentado (D-11).
     *
     * @var array<string, array{0: string, 1: ?string}>
     */
    private const CLASSIFICACAO_CENSO = [
        'company_users.user_id' => ['tratada', null],
        'user_setores.user_id' => ['tratada', null],
        'nps_score_assignments.user_id' => [
            'tratada',
            'parcial: só competência >= corte; o resto fica com a origem de propósito',
        ],
        'nps_imputed_assignments.user_id' => [
            'tratada',
            'parcial: só competência >= corte; o resto fica com a origem de propósito',
        ],
        'desempenho_score_snapshots.user_id' => [
            'tratada',
            'parcial: só competência >= corte; o resto fica com a origem de propósito',
        ],
        'desempenho_company_score_snapshots.user_id' => [
            'tratada',
            'parcial: só competência >= corte; o resto fica com a origem de propósito',
        ],
        'ppas.mentor_id' => [
            'tratada',
            'parcial: só o que ainda está em aberto',
        ],
        'onboardings.responsavel_id' => [
            'tratada',
            'parcial: só o que ainda está em aberto',
        ],
        'onboardings.responsavel_analista_id' => [
            'tratada',
            'parcial: só o que ainda está em aberto',
        ],
        'onboardings.responsavel_estrategista_id' => [
            'tratada',
            'parcial: só o que ainda está em aberto',
        ],
        'company_manager_history.user_id' => [
            'mantida',
            'histórico — a junção acrescenta eventos, não reescreve',
        ],
        'company_manager_history.changed_by' => [
            'mantida',
            'histórico — a junção acrescenta eventos, não reescreve',
        ],
        'portfolio_goals.user_id' => [
            'mantida',
            'D-11: meta individual fica no perfil de origem; recriar no destino pela tela, se o usuário quiser',
        ],
        'sessions.user_id' => ['mantida', 'sessão de login expira sozinha'],
    ];

    private const TABELAS_CENSO_EXCLUIDAS = ['users', 'migrations', 'unificacao_contas_backup'];

    /**
     * Monta o plano da junção — SOMENTE LEITURA, nunca escreve nada. Usado
     * tanto para o dry-run quanto para a reconsulta que confere o `--apply`
     * (learnings §4: o veredito é por reconsulta ao banco, nunca por stdout).
     *
     * @return array{
     *   de: array{id:int,nome:string,ativo:bool},
     *   para: array{id:int,nome:string,ativo:bool},
     *   a_partir: string,
     *   bloqueios: list<string>,
     *   avisos: list<string>,
     *   etapas: list<array{chave:string,descricao:string,operacoes:list<array>}>,
     *   censo: list<array{tabela:string,coluna:string,linhas_origem:int,classificacao:string,motivo:?string}>,
     * }
     */
    public function planejar(int $deId, int $paraId, Carbon $aPartir): array
    {
        $agora = now();
        $aPartirInicio = $aPartir->copy()->startOfMonth();

        $de = $this->carregarUsuario($deId);
        $para = $this->carregarUsuario($paraId);

        $bloqueios = [];

        if ($deId === $paraId) {
            $bloqueios[] = 'A origem e o destino não podem ser o mesmo usuário.';
        }
        if (! $para['ativo']) {
            $bloqueios[] = "O destino (usuário {$paraId}) está inativo — junção recusada.";
        }

        $bloqueios = array_merge(
            $bloqueios,
            $this->bloqueiosCompetenciaConsolidada($deId, $paraId, $aPartirInicio),
            $this->bloqueiosCompetenciaAnteriorSemConsolidar([$de, $para], $aPartirInicio)
        );

        $etapaCarteira = $this->planejarCarteira($deId, $paraId);
        // historico_gestao precisa dos metadados internos (_company_id/_role)
        // de cada operação da carteira ANTES de limpá-los do contrato público.
        $etapaHistorico = $this->planejarHistoricoGestao($etapaCarteira['operacoes'], $deId, $paraId, $agora);
        $etapaCarteira['operacoes'] = $this->limparMetadadosInternos($etapaCarteira['operacoes']);
        $etapaCargos = $this->planejarCargos($deId, $paraId, $agora);

        // Mês de COLETA do NPS (M+1) da competência de corte — mesma régua do
        // motor (NpsJanelaResolver::mesDeColeta), nunca recalculada à mão.
        $inicioColeta = app(NpsJanelaResolver::class)->mesDeColeta($aPartirInicio->copy())->startOfMonth();

        $etapaNpsAtribuicoes = $this->planejarNpsAtribuicoes($deId, $paraId, $inicioColeta);
        $etapaNpsImputacoes = $this->planejarNpsImputacoes($deId, $paraId, $inicioColeta);
        $etapaSnapshotsDiarios = $this->planejarSnapshotsDiarios($deId, $aPartirInicio);
        $etapaSnapshotsEmpresa = $this->planejarSnapshotsEmpresa($deId, $aPartirInicio);

        $etapaPpas = $this->planejarPpas($deId, $paraId);
        $etapaOnboardings = $this->planejarOnboardings($deId, $paraId);

        $etapaDesativar = $this->planejarDesativarOrigem($de);

        $censo = $this->censo($deId);

        $avisos = [];
        foreach ($censo as $linha) {
            if ($linha['classificacao'] === 'mantida' && $linha['linhas_origem'] > 0) {
                $avisos[] = sprintf(
                    '%d linha(s) em %s.%s ficam com a origem (usuário %d) — %s.',
                    $linha['linhas_origem'],
                    $linha['tabela'],
                    $linha['coluna'],
                    $deId,
                    $linha['motivo']
                );
            }
        }

        // A janela de coleta do NPS da competência de corte ainda não abriu —
        // 0 atribuições/imputações é esperado, não um bug (D-06/CONTEXT.md).
        if ($agora->lt($inicioColeta)) {
            $avisos[] = sprintf(
                'A coleta do NPS da competência %s começa em %s; respostas e imputações dessa '
                . 'competência ainda não existem. Rode o comando de novo depois da coleta e antes '
                . 'do desempenho:consolidar-mes de %s às 14:00 (America/Sao_Paulo).',
                $aPartirInicio->format('Y-m'),
                $inicioColeta->format('Y-m-d'),
                $inicioColeta->copy()->endOfMonth()->format('Y-m-d')
            );
        }

        // Mesmo com a coleta já aberta: respostas que chegarem DEPOIS deste
        // --apply já nascem atribuídas ao destino (a carteira já moveu), mas
        // as que chegaram ANTES precisam de um segundo --apply para migrar.
        $avisos[] = sprintf(
            'Rode a etapa de NPS de novo antes do desempenho:consolidar-mes de %s às 14:00 '
            . '(America/Sao_Paulo) — respostas/imputações da competência %s que já chegaram ficam '
            . 'com a origem até o próximo --apply; as que chegarem depois já nascem no destino.',
            $inicioColeta->copy()->endOfMonth()->format('Y-m-d'),
            $aPartirInicio->format('Y-m')
        );

        return [
            'de' => $de,
            'para' => $para,
            'a_partir' => $aPartirInicio->format('Y-m'),
            'bloqueios' => $bloqueios,
            'avisos' => $avisos,
            'etapas' => [
                $etapaCarteira,
                $etapaHistorico,
                $etapaCargos,
                $etapaNpsAtribuicoes,
                $etapaNpsImputacoes,
                $etapaSnapshotsDiarios,
                $etapaSnapshotsEmpresa,
                $etapaPpas,
                $etapaOnboardings,
                $etapaDesativar,
            ],
            'censo' => $censo,
        ];
    }

    /**
     * Colunas `tabela.coluna` classificadas `sem_regra` com linhas da origem
     * e fora de `$manter` — pendência que trava o `--apply` até o operador
     * decidir (D-11).
     *
     * @param  list<array{tabela:string,coluna:string,linhas_origem:int,classificacao:string,motivo:?string}>  $censo
     * @param  list<string>  $manter
     * @return list<string>
     */
    public function pendenciasCenso(array $censo, array $manter = []): array
    {
        $manterSet = array_flip($manter);
        $pendencias = [];

        foreach ($censo as $linha) {
            if ($linha['classificacao'] !== 'sem_regra' || $linha['linhas_origem'] <= 0) {
                continue;
            }

            $chave = "{$linha['tabela']}.{$linha['coluna']}";
            if (isset($manterSet[$chave])) {
                continue;
            }

            $pendencias[] = $chave;
        }

        return $pendencias;
    }

    /**
     * Aplica o plano: recusa (RuntimeException) se houver bloqueio ou
     * pendência de censo fora de `$manter`. Todo o núcleo roda em UMA
     * transação; o backup de cada linha é gravado ANTES do UPDATE/DELETE e
     * LOGO APÓS o INSERT (para capturar o id gerado).
     *
     * @param  list<string>  $manter
     * @return array{lote:string, operacoes:int}
     */
    public function aplicar(array $plano, array $manter = []): array
    {
        if (! empty($plano['bloqueios'])) {
            throw new \RuntimeException('Bloqueios impedem o --apply: ' . implode(' | ', $plano['bloqueios']));
        }

        $pendencias = $this->pendenciasCenso($plano['censo'], $manter);
        if (! empty($pendencias)) {
            throw new \RuntimeException(
                'Censo com coluna(s) sem regra e sem --manter (decida antes de aplicar): '
                . implode(', ', $pendencias)
            );
        }

        $lote = (string) Str::uuid();
        $deId = (int) $plano['de']['id'];
        $paraId = (int) $plano['para']['id'];
        $aPartirData = Carbon::parse($plano['a_partir'] . '-01');

        $operacoes = DB::transaction(function () use ($plano, $lote, $deId, $paraId, $aPartirData) {
            $total = 0;

            foreach ($plano['etapas'] as $etapa) {
                foreach ($etapa['operacoes'] as $operacao) {
                    $this->aplicarOperacao($operacao, $etapa['chave'], $lote, $deId, $paraId, $aPartirData);
                    $total++;
                }
            }

            return $total;
        });

        $this->bustarCache($deId, $paraId, $aPartirData);

        activity('usuarios')
            ->withProperties([
                'lote' => $lote,
                'de' => $deId,
                'para' => $paraId,
                'a_partir' => $plano['a_partir'],
                'operacoes' => $operacoes,
                'manter' => $manter,
            ])
            ->log("Unificação de contas aplicada — usuário {$deId} → usuário {$paraId}, lote {$lote}");

        return ['lote' => $lote, 'operacoes' => $operacoes];
    }

    /**
     * Desfaz um lote a partir do backup. Sem `$aplicar`, só devolve o
     * resumo (quantas operações seriam restauradas). Com `$aplicar`,
     * restaura em ordem INVERSA à da aplicação, dentro de uma transação,
     * sem `try/catch` — colisão ao restaurar (ex.: linha já existe de novo)
     * derruba a transação inteira em vez de mascarar o erro.
     *
     * @return array{lote:string, operacoes:int}
     */
    public function desfazer(string $lote, bool $aplicar): array
    {
        $registros = DB::table('unificacao_contas_backup')
            ->where('lote', $lote)
            ->orderByDesc('id')
            ->get();

        if ($registros->isEmpty()) {
            throw new \RuntimeException("Lote {$lote} não encontrado.");
        }

        if ($registros->contains(fn ($r) => $r->desfeito_em !== null)) {
            throw new \RuntimeException("Lote {$lote} já foi desfeito — recusado rodar duas vezes o mesmo lote.");
        }

        if (! $aplicar) {
            return ['lote' => $lote, 'operacoes' => $registros->count()];
        }

        $primeiro = $registros->first();
        $deId = (int) $primeiro->de_user_id;
        $paraId = (int) $primeiro->para_user_id;
        $aPartirData = Carbon::parse($primeiro->a_partir);

        DB::transaction(function () use ($registros, $lote) {
            foreach ($registros as $registro) {
                $antes = $registro->antes !== null ? json_decode($registro->antes, true) : null;

                if ($registro->acao === 'update') {
                    DB::table($registro->tabela)->where('id', $registro->linha_id)->update($antes);
                } elseif ($registro->acao === 'delete') {
                    DB::table($registro->tabela)->insert($antes);
                } else { // insert
                    DB::table($registro->tabela)->where('id', $registro->linha_id)->delete();
                }
            }

            DB::table('unificacao_contas_backup')->where('lote', $lote)->update(['desfeito_em' => now()]);
        });

        $this->bustarCache($deId, $paraId, $aPartirData);

        activity('usuarios')
            ->withProperties(['lote' => $lote, 'de' => $deId, 'para' => $paraId, 'operacoes' => $registros->count()])
            ->log("Unificação de contas DESFEITA — lote {$lote}");

        return ['lote' => $lote, 'operacoes' => $registros->count()];
    }

    // =========================================================================
    // Etapas do núcleo
    // =========================================================================

    /**
     * Etapa `carteira`: cada linha de `company_users` da origem move para o
     * destino (troca só `user_id`, preserva `assigned_at`) — a menos que o
     * destino já tenha a mesma linha (mesma empresa, role e serviço,
     * comparação null-safe), caso em que a linha da origem é apagada em vez
     * de colidir no unique.
     */
    private function planejarCarteira(int $deId, int $paraId): array
    {
        $linhasOrigem = DB::table('company_users')->where('user_id', $deId)->orderBy('id')->get();
        $operacoes = [];

        foreach ($linhasOrigem as $linha) {
            $colisao = $this->consultaColisaoCarteira($linha, $paraId)->exists();

            if ($colisao) {
                $operacoes[] = [
                    'tabela' => 'company_users',
                    'acao' => 'delete',
                    'linha_id' => $linha->id,
                    'antes' => (array) $linha,
                    'depois' => null,
                    // Metadado interno para a etapa `historico_gestao` — não
                    // faz parte do contrato `[tabela,acao,linha_id,antes,depois]`.
                    '_company_id' => $linha->company_id,
                    '_role' => $linha->role,
                ];
            } else {
                $operacoes[] = [
                    'tabela' => 'company_users',
                    'acao' => 'update',
                    'linha_id' => $linha->id,
                    'antes' => ['user_id' => $deId],
                    'depois' => ['user_id' => $paraId],
                    '_company_id' => $linha->company_id,
                    '_role' => $linha->role,
                ];
            }
        }

        return [
            'chave' => 'carteira',
            'descricao' => 'Vínculos de carteira (company_users) da origem passam ao destino.',
            'operacoes' => $operacoes,
        ];
    }

    /** Linha do DESTINO com a mesma (empresa, role, serviço) da linha `$linha` da origem — null-safe no serviço. */
    private function consultaColisaoCarteira(object $linha, int $paraId): \Illuminate\Database\Query\Builder
    {
        return DB::table('company_users')
            ->where('user_id', $paraId)
            ->where('company_id', $linha->company_id)
            ->where('role', $linha->role)
            ->when(
                $linha->servico_id === null,
                fn ($q) => $q->whereNull('servico_id'),
                fn ($q) => $q->where('servico_id', $linha->servico_id)
            );
    }

    /**
     * Etapa `historico_gestao`: por (company_id, papel) distinto tocado pela
     * etapa `carteira`, registra a saída da origem e — só quando a operação
     * foi `update` (sem colisão) — a entrada do destino. Em colisão
     * (`delete`) o destino já estava lá, então não há entrada nova.
     */
    private function planejarHistoricoGestao(array $operacoesCarteira, int $deId, int $paraId, Carbon $agora): array
    {
        $operacoes = [];
        $vistos = [];

        foreach ($operacoesCarteira as $op) {
            $papel = $op['_role'] === 'estrategista' ? 'estrategista' : 'analista';
            $chave = $op['_company_id'] . '|' . $papel;

            if (isset($vistos[$chave])) {
                continue;
            }
            $vistos[$chave] = true;

            $operacoes[] = [
                'tabela' => 'company_manager_history',
                'acao' => 'insert',
                'linha_id' => null,
                'antes' => null,
                'depois' => [
                    'company_id' => $op['_company_id'],
                    'user_id' => $deId,
                    'papel' => $papel,
                    'evento' => 'saida',
                    'changed_by' => null,
                    'created_at' => $agora->toDateTimeString(),
                ],
            ];

            if ($op['acao'] === 'update') {
                $operacoes[] = [
                    'tabela' => 'company_manager_history',
                    'acao' => 'insert',
                    'linha_id' => null,
                    'antes' => null,
                    'depois' => [
                        'company_id' => $op['_company_id'],
                        'user_id' => $paraId,
                        'papel' => $papel,
                        'evento' => 'entrada',
                        'changed_by' => null,
                        'created_at' => $agora->toDateTimeString(),
                    ],
                ];
            }
        }

        return [
            'chave' => 'historico_gestao',
            'descricao' => 'Saída da origem e entrada do destino em company_manager_history.',
            'operacoes' => $operacoes,
        ];
    }

    /**
     * Etapa `cargos`: cada (setor_id, cargo_id) não nulo que a origem tem e
     * o destino ainda não tem, o destino ganha via insert (is_principal
     * false — não mexe no cargo principal existente do destino). As linhas
     * da origem em `user_setores` NÃO são tocadas.
     */
    private function planejarCargos(int $deId, int $paraId, Carbon $agora): array
    {
        $cargosOrigem = DB::table('user_setores')
            ->where('user_id', $deId)
            ->whereNotNull('cargo_id')
            ->orderBy('id')
            ->get(['setor_id', 'cargo_id']);

        $cargosDestino = DB::table('user_setores')
            ->where('user_id', $paraId)
            ->whereNotNull('cargo_id')
            ->get(['setor_id', 'cargo_id'])
            ->map(fn ($r) => "{$r->setor_id}:{$r->cargo_id}")
            ->all();

        $operacoes = [];

        foreach ($cargosOrigem as $cargo) {
            $chave = "{$cargo->setor_id}:{$cargo->cargo_id}";
            if (in_array($chave, $cargosDestino, true)) {
                continue;
            }

            $operacoes[] = [
                'tabela' => 'user_setores',
                'acao' => 'insert',
                'linha_id' => null,
                'antes' => null,
                'depois' => [
                    'user_id' => $paraId,
                    'setor_id' => $cargo->setor_id,
                    'cargo_id' => $cargo->cargo_id,
                    'is_principal' => false,
                    'assigned_at' => $agora->toDateTimeString(),
                    'created_at' => $agora->toDateTimeString(),
                    'updated_at' => $agora->toDateTimeString(),
                ],
            ];
        }

        return [
            'chave' => 'cargos',
            'descricao' => 'Cargos da origem que o destino ainda não tem (D-01).',
            'operacoes' => $operacoes,
        ];
    }

    /**
     * Etapa `nps_atribuicoes` (D-06, 159-06): `nps_score_assignments` NÃO tem
     * coluna de mês — a competência é sempre o JOIN com
     * `nps_responses`/`nps_surveys` (`s.completed_at`), a MESMA régua que
     * `NpsPorEmpresaService::notasAtribuicaoPorEmpresa()` usa (Pitfall 4 da
     * pesquisa: NUNCA `assigned_at` nem `month_reference`). `$inicioColeta`
     * já é o mês de COLETA (M+1, `NpsJanelaResolver::mesDeColeta`), não o mês
     * financeiro do corte.
     *
     * Colisão = destino já tem linha com o MESMO `(nps_response_id, role,
     * servico_id)` (comparação null-safe) → `delete` da origem (o destino já
     * cobre); senão → `update` de `user_id`.
     */
    private function planejarNpsAtribuicoes(int $deId, int $paraId, Carbon $inicioColeta): array
    {
        $linhasOrigem = DB::table('nps_score_assignments as nsa')
            ->join('nps_responses as r', 'r.id', '=', 'nsa.nps_response_id')
            ->join('nps_surveys as s', 's.id', '=', 'r.survey_id')
            ->where('nsa.user_id', $deId)
            ->where('s.status', 'completed')
            ->where('s.completed_at', '>=', $inicioColeta)
            ->orderBy('nsa.id')
            ->select('nsa.*')
            ->get();

        $operacoes = [];

        foreach ($linhasOrigem as $linha) {
            $colisao = $this->consultaColisaoAtribuicao($linha, $paraId)->exists();

            $operacoes[] = $colisao
                ? [
                    'tabela' => 'nps_score_assignments',
                    'acao' => 'delete',
                    'linha_id' => $linha->id,
                    'antes' => (array) $linha,
                    'depois' => null,
                ]
                : [
                    'tabela' => 'nps_score_assignments',
                    'acao' => 'update',
                    'linha_id' => $linha->id,
                    'antes' => ['user_id' => $deId],
                    'depois' => ['user_id' => $paraId],
                ];
        }

        return [
            'chave' => 'nps_atribuicoes',
            'descricao' => 'Atribuições de NPS (nps_score_assignments) da origem cuja competência (completed_at do survey) é a partir do corte.',
            'operacoes' => $operacoes,
        ];
    }

    /** Linha do DESTINO com o mesmo (resposta, role, serviço) da atribuição `$linha` da origem — null-safe no serviço. */
    private function consultaColisaoAtribuicao(object $linha, int $paraId): \Illuminate\Database\Query\Builder
    {
        return DB::table('nps_score_assignments')
            ->where('user_id', $paraId)
            ->where('nps_response_id', $linha->nps_response_id)
            ->where('role', $linha->role)
            ->when(
                $linha->servico_id === null,
                fn ($q) => $q->whereNull('servico_id'),
                fn ($q) => $q->where('servico_id', $linha->servico_id)
            );
    }

    /**
     * Etapa `nps_imputacoes` (D-06, 159-06): `nps_imputed_assignments.competencia_nps`
     * JÁ é o mês de COLETA materializado (ver `NpsImputationService`) —
     * comparação direta, sem JOIN.
     *
     * Colisão pelo grão do unique `nps_imput_grao_uniq`
     * (`survey_id`, `dimensao`, `role`, `servico_id`, null-safe) MAIS o grão
     * de link de grupo (`group_survey_id`, `company_id` — WR-01) com
     * `user_id` = destino → `delete`; senão → `update` de `user_id`. Ver
     * {@see self::consultaColisaoImputacao()}.
     */
    private function planejarNpsImputacoes(int $deId, int $paraId, Carbon $inicioColeta): array
    {
        $linhasOrigem = DB::table('nps_imputed_assignments')
            ->where('user_id', $deId)
            ->whereDate('competencia_nps', '>=', $inicioColeta->toDateString())
            ->orderBy('id')
            ->get();

        $operacoes = [];

        foreach ($linhasOrigem as $linha) {
            $colisao = $this->consultaColisaoImputacao($linha, $paraId)->exists();

            $operacoes[] = $colisao
                ? [
                    'tabela' => 'nps_imputed_assignments',
                    'acao' => 'delete',
                    'linha_id' => $linha->id,
                    'antes' => (array) $linha,
                    'depois' => null,
                ]
                : [
                    'tabela' => 'nps_imputed_assignments',
                    'acao' => 'update',
                    'linha_id' => $linha->id,
                    'antes' => ['user_id' => $deId],
                    'depois' => ['user_id' => $paraId],
                ];
        }

        return [
            'chave' => 'nps_imputacoes',
            'descricao' => 'Imputações de NPS (nps_imputed_assignments) da origem com competencia_nps (mês de coleta) a partir do corte.',
            'operacoes' => $operacoes,
        ];
    }

    /**
     * Linha do DESTINO que ocupa o mesmo grão da imputação `$linha` da
     * origem — o mesmo grão do guard `exists()` de `NpsImputationService`
     * e de `NpsImputedAssignment::chaveDeDedupe()`.
     *
     * WR-01 (revisão da Fase 159): a linha de link de GRUPO tem
     * `survey_id = NULL` desde a migration 2026_08_26_150000, e o grão real é
     * `(group_survey_id, company_id)`. Comparar só `survey_id IS NULL`
     * casava a linha de OUTRO link ou de OUTRA empresa do destino como
     * "colisão" e APAGAVA a da origem — o piso 1 daquela empresa sumia da
     * carteira do destino sem nenhum aviso.
     */
    private function consultaColisaoImputacao(object $linha, int $paraId): \Illuminate\Database\Query\Builder
    {
        return DB::table('nps_imputed_assignments')
            ->where('user_id', $paraId)
            ->where('dimensao', $linha->dimensao)
            ->where('company_id', $linha->company_id)
            ->when($linha->role === null, fn ($q) => $q->whereNull('role'), fn ($q) => $q->where('role', $linha->role))
            ->when(
                $linha->survey_id === null,
                fn ($q) => $q->whereNull('survey_id'),
                fn ($q) => $q->where('survey_id', $linha->survey_id)
            )
            ->when(
                ($linha->group_survey_id ?? null) === null,
                fn ($q) => $q->whereNull('group_survey_id'),
                fn ($q) => $q->where('group_survey_id', $linha->group_survey_id)
            )
            ->when(
                $linha->servico_id === null,
                fn ($q) => $q->whereNull('servico_id'),
                fn ($q) => $q->where('servico_id', $linha->servico_id)
            );
    }

    /**
     * Etapa `snapshots_diarios` (D-06, 159-06): `desempenho_score_snapshots`
     * da origem na modalidade DIÁRIA (`mes_referencia` NULL — é cache, D-02
     * da Fase 74) com `ref_date >= início do corte` (financeiro, NÃO o mês de
     * coleta do NPS) é removida. A modalidade MENSAL nunca é tocada aqui —
     * se existisse uma mensal >= corte, `bloqueiosCompetenciaConsolidada()`
     * já teria recusado a junção antes de chegar nesta etapa.
     */
    private function planejarSnapshotsDiarios(int $deId, Carbon $corteInicio): array
    {
        $linhasOrigem = DB::table('desempenho_score_snapshots')
            ->where('user_id', $deId)
            ->whereNull('mes_referencia')
            ->whereDate('ref_date', '>=', $corteInicio->toDateString())
            ->orderBy('id')
            ->get();

        $operacoes = [];

        foreach ($linhasOrigem as $linha) {
            $operacoes[] = [
                'tabela' => 'desempenho_score_snapshots',
                'acao' => 'delete',
                'linha_id' => $linha->id,
                'antes' => (array) $linha,
                'depois' => null,
            ];
        }

        return [
            'chave' => 'snapshots_diarios',
            'descricao' => 'Snapshots diários (cache) da origem a partir do corte são removidos; o mensal nunca.',
            'operacoes' => $operacoes,
        ];
    }

    /**
     * Etapa `snapshots_empresa` (D-06, 159-06): `desempenho_company_score_snapshots`
     * da origem com `mes_referencia >= início do corte` e `origem` diferente
     * de `CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES` (cache —
     * `snapshot_diario`/`warm_cache`) é removida. Uma linha `consolidar_mes`
     * >= corte já teria bloqueado a junção antes (mesma trava de
     * `bloqueiosCompetenciaConsolidada()`); o filtro aqui é defesa em
     * profundidade, nunca o único guarda-chuva.
     */
    private function planejarSnapshotsEmpresa(int $deId, Carbon $corteInicio): array
    {
        $linhasOrigem = DB::table('desempenho_company_score_snapshots')
            ->where('user_id', $deId)
            ->whereDate('mes_referencia', '>=', $corteInicio->toDateString())
            ->where('origem', '!=', CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES)
            ->orderBy('id')
            ->get();

        $operacoes = [];

        foreach ($linhasOrigem as $linha) {
            $operacoes[] = [
                'tabela' => 'desempenho_company_score_snapshots',
                'acao' => 'delete',
                'linha_id' => $linha->id,
                'antes' => (array) $linha,
                'depois' => null,
            ];
        }

        return [
            'chave' => 'snapshots_empresa',
            'descricao' => 'Detalhe por empresa da origem a partir do corte, exceto consolidar_mes, é removido.',
            'operacoes' => $operacoes,
        ];
    }

    /**
     * Etapa `ppas` (D-11, 159-06): PPAs em aberto (`draft`/`sent`) com
     * `mentor_id` = origem passam ao destino — é trabalho VIVO e a origem
     * vai ficar inativa (não pode continuar "dona" de um PPA em andamento).
     * PPA `completed` é histórico e fica com a origem.
     */
    private function planejarPpas(int $deId, int $paraId): array
    {
        $linhasOrigem = DB::table('ppas')
            ->where('mentor_id', $deId)
            ->whereIn('status', ['draft', 'sent'])
            ->orderBy('id')
            ->get(['id']);

        $operacoes = [];

        foreach ($linhasOrigem as $linha) {
            $operacoes[] = [
                'tabela' => 'ppas',
                'acao' => 'update',
                'linha_id' => $linha->id,
                'antes' => ['mentor_id' => $deId],
                'depois' => ['mentor_id' => $paraId],
            ];
        }

        return [
            'chave' => 'ppas',
            'descricao' => 'PPAs em aberto (draft/sent) da origem passam ao destino; concluído fica com a origem.',
            'operacoes' => $operacoes,
        ];
    }

    /**
     * Etapa `onboardings` (D-11, 159-06): para cada um dos três slots de
     * responsável (`responsavel_id` principal, `responsavel_analista_id`,
     * `responsavel_estrategista_id`), onboardings NÃO CONCLUÍDOS
     * (`Onboarding::scopeNaoConcluido()` — rascunho/andamento) com aquela
     * coluna = origem passam ao destino. Uma operação por (linha, coluna) —
     * `antes`/`depois` só carregam a coluna tocada, nunca mexem em outra
     * coluna da mesma linha. Onboarding `concluido` é histórico e fica com
     * a origem.
     */
    private function planejarOnboardings(int $deId, int $paraId): array
    {
        $colunas = ['responsavel_id', 'responsavel_analista_id', 'responsavel_estrategista_id'];
        $statusAbertos = [Onboarding::STATUS_RASCUNHO, Onboarding::STATUS_ANDAMENTO];

        $operacoes = [];

        foreach ($colunas as $coluna) {
            $linhasOrigem = DB::table('onboardings')
                ->where($coluna, $deId)
                ->whereIn('status', $statusAbertos)
                ->orderBy('id')
                ->get(['id']);

            foreach ($linhasOrigem as $linha) {
                $operacoes[] = [
                    'tabela' => 'onboardings',
                    'acao' => 'update',
                    'linha_id' => $linha->id,
                    'antes' => [$coluna => $deId],
                    'depois' => [$coluna => $paraId],
                ];
            }
        }

        return [
            'chave' => 'onboardings',
            'descricao' => 'Responsáveis (principal/analista/estrategista) de onboardings em aberto da origem passam ao destino; concluído fica.',
            'operacoes' => $operacoes,
        ];
    }

    /**
     * Etapa `desativar_origem`, SEMPRE a última: se a origem estiver ativa,
     * desativa (users.active = false). Nunca apaga a conta, e nunca copia
     * outra coluna de users para o backup (senha, remember_token).
     */
    private function planejarDesativarOrigem(array $de): array
    {
        $operacoes = [];

        if ($de['ativo']) {
            $operacoes[] = [
                'tabela' => 'users',
                'acao' => 'update',
                'linha_id' => $de['id'],
                'antes' => ['active' => true],
                'depois' => ['active' => false],
            ];
        }

        return [
            'chave' => 'desativar_origem',
            'descricao' => 'Desativa a conta de origem sem apagar (histórico dos meses fechados continua no nome dela).',
            'operacoes' => $operacoes,
        ];
    }

    // =========================================================================
    // Bloqueios
    // =========================================================================

    /**
     * Bloqueia quando origem OU destino já têm a competência (>= início de
     * `--a-partir`) consolidada — por snapshot mensal
     * (`desempenho_score_snapshots`) ou por detalhe por empresa gravado com
     * `origem = consolidar_mes` (`desempenho_company_score_snapshots`).
     * Competência fechada fica intocada (D-06).
     *
     * @return list<string>
     */
    private function bloqueiosCompetenciaConsolidada(int $deId, int $paraId, Carbon $inicio): array
    {
        $bloqueios = [];
        $vistos = [];

        $mensais = DesempenhoScoreSnapshot::query()
            ->mensal()
            ->whereIn('user_id', [$deId, $paraId])
            ->whereDate('mes_referencia', '>=', $inicio->toDateString())
            ->get(['user_id', 'mes_referencia']);

        foreach ($mensais as $snapshot) {
            $this->registrarBloqueioCompetencia($bloqueios, $vistos, $snapshot->user_id, $snapshot->mes_referencia);
        }

        $porEmpresa = DesempenhoCompanyScoreSnapshot::query()
            ->whereIn('user_id', [$deId, $paraId])
            ->where('origem', CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES)
            ->whereDate('mes_referencia', '>=', $inicio->toDateString())
            ->get(['user_id', 'mes_referencia']);

        foreach ($porEmpresa as $snapshot) {
            $this->registrarBloqueioCompetencia($bloqueios, $vistos, $snapshot->user_id, $snapshot->mes_referencia);
        }

        return $bloqueios;
    }

    /**
     * CR-02 (revisão da Fase 159): bloqueia quando a competência
     * IMEDIATAMENTE ANTERIOR ao corte (`--a-partir − 1`) não tem snapshot
     * mensal (`desempenho_score_snapshots` com `mes_referencia`, o que
     * `desempenho:consolidar-mes` grava) para origem ou destino que TÊM
     * carteira.
     *
     * Por quê: `company_users` não tem dimensão temporal — a carteira é
     * sempre a ATUAL, e toda competência sem snapshot mensal é calculada ao
     * vivo (Ranking, Relatório de Bonificação, Auditoria). Mover a carteira
     * reescreveria em silêncio um mês "fechado" que ainda não virou snapshot
     * — exatamente o que D-06 proíbe (learnings §2 e §10.1: `consolidar-mes`
     * já falhou com exit 0 para 11 de 12 profissionais).
     *
     * Cobre também o caso simétrico: com `--a-partir` posterior à primeira
     * competência ainda aberta, `--a-partir − 1` é essa competência aberta,
     * sem snapshot — bloqueia do mesmo jeito.
     *
     * Quem não tem carteira não precisa de snapshot: não há o que recalcular.
     *
     * @param  list<array{id:int,nome:string,ativo:bool}>  $usuarios
     * @return list<string>
     */
    private function bloqueiosCompetenciaAnteriorSemConsolidar(array $usuarios, Carbon $inicio): array
    {
        $anterior = $inicio->copy()->subMonthNoOverflow()->startOfMonth();
        $bloqueios = [];
        $vistos = [];

        foreach ($usuarios as $usuario) {
            $userId = (int) $usuario['id'];
            if (isset($vistos[$userId])) {
                continue;
            }
            $vistos[$userId] = true;

            if (! DB::table('company_users')->where('user_id', $userId)->exists()) {
                continue;
            }

            $temMensal = DesempenhoScoreSnapshot::query()
                ->mensal()
                ->where('user_id', $userId)
                ->whereDate('mes_referencia', $anterior->toDateString())
                ->exists();

            if (! $temMensal) {
                $bloqueios[] = sprintf(
                    'competência %s (anterior ao corte %s) sem snapshot mensal para o usuário %d (%s), que tem '
                    . 'carteira — mover a carteira recalcularia esse mês fechado. Consolide antes '
                    . '(desempenho:consolidar-mes --mes=%s) e confira por desempenho:verificar-consolidacao '
                    . '--mes=%s --json (o veredito é o exit code)',
                    $anterior->format('Y-m'),
                    $inicio->format('Y-m'),
                    $userId,
                    $usuario['nome'],
                    $anterior->format('Y-m'),
                    $anterior->format('Y-m')
                );
            }
        }

        return $bloqueios;
    }

    private function registrarBloqueioCompetencia(array &$bloqueios, array &$vistos, int $userId, $mesReferencia): void
    {
        $mes = Carbon::parse($mesReferencia)->format('Y-m');
        $chave = "{$userId}|{$mes}";

        if (isset($vistos[$chave])) {
            return;
        }
        $vistos[$chave] = true;

        $bloqueios[] = "competência {$mes} já consolidada para o usuário {$userId} — a junção a partir dela "
            . 'está recusada; competência fechada fica intocada';
    }

    // =========================================================================
    // Censo (D-11)
    // =========================================================================

    /**
     * @return list<array{tabela:string,coluna:string,linhas_origem:int,classificacao:string,motivo:?string}>
     */
    private function censo(int $deId): array
    {
        $linhas = [];

        foreach ($this->tabelasColunasComUser() as [$tabela, $coluna]) {
            $total = (int) DB::table($tabela)->where($coluna, $deId)->count();
            [$classificacao, $motivo] = $this->classificarCenso($tabela, $coluna);

            $linhas[] = [
                'tabela' => $tabela,
                'coluna' => $coluna,
                'linhas_origem' => $total,
                'classificacao' => $classificacao,
                'motivo' => $motivo,
            ];
        }

        usort($linhas, fn ($a, $b) => [$a['tabela'], $a['coluna']] <=> [$b['tabela'], $b['coluna']]);

        return $linhas;
    }

    /**
     * Toda (tabela, coluna) que referencia `users` — no MySQL/MariaDB via
     * `information_schema.KEY_COLUMN_USAGE` (FK) + `information_schema.COLUMNS`
     * (heurística de nome `user_id`, cobre coluna sem FK declarada); no
     * SQLite via `sqlite_master` + `PRAGMA foreign_key_list` +
     * `PRAGMA table_info`. Exclui `users`, `migrations` e
     * `unificacao_contas_backup`.
     *
     * @return list<array{0:string,1:string}>
     */
    private function tabelasColunasComUser(): array
    {
        $pares = [];

        if (DB::getDriverName() === 'mysql') {
            $porFk = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('REFERENCED_TABLE_SCHEMA', DB::getDatabaseName())
                ->where('REFERENCED_TABLE_NAME', 'users')
                ->get(['TABLE_NAME', 'COLUMN_NAME']);

            foreach ($porFk as $row) {
                $pares["{$row->TABLE_NAME}.{$row->COLUMN_NAME}"] = [$row->TABLE_NAME, $row->COLUMN_NAME];
            }

            $porNome = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('COLUMN_NAME', 'user_id')
                ->get(['TABLE_NAME', 'COLUMN_NAME']);

            foreach ($porNome as $row) {
                $pares["{$row->TABLE_NAME}.{$row->COLUMN_NAME}"] = [$row->TABLE_NAME, $row->COLUMN_NAME];
            }
        } else {
            $tabelas = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

            foreach ($tabelas as $t) {
                $tabela = $t->name;

                foreach (DB::select('PRAGMA foreign_key_list(' . DB::getPdo()->quote($tabela) . ')') as $fk) {
                    if (($fk->table ?? null) === 'users') {
                        $pares["{$tabela}.{$fk->from}"] = [$tabela, $fk->from];
                    }
                }

                foreach (DB::select('PRAGMA table_info(' . DB::getPdo()->quote($tabela) . ')') as $col) {
                    if (($col->name ?? null) === 'user_id') {
                        $pares["{$tabela}.{$col->name}"] = [$tabela, $col->name];
                    }
                }
            }
        }

        return array_values(array_filter(
            $pares,
            fn ($par) => ! in_array($par[0], self::TABELAS_CENSO_EXCLUIDAS, true)
        ));
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function classificarCenso(string $tabela, string $coluna): array
    {
        $chave = "{$tabela}.{$coluna}";

        if (isset(self::CLASSIFICACAO_CENSO[$chave])) {
            return self::CLASSIFICACAO_CENSO[$chave];
        }

        if (str_starts_with($tabela, 'dev_') || str_contains($tabela, 'chamado')) {
            return ['mantida', 'D-11: Demandas Dev/Chamados registram quem pediu/atendeu'];
        }

        $colunasDeAutoria = ['autor_id', 'ator_id', 'solicitante_id', 'enviado_por'];
        if (str_ends_with($coluna, '_by') || str_ends_with($coluna, '_por') || in_array($coluna, $colunasDeAutoria, true)) {
            return ['mantida', 'autoria — reescrever falsificaria a trilha, mesmo motivo do activity_log'];
        }

        return ['sem_regra', null];
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * Remove as chaves internas (`_company_id`, `_role`) que a etapa
     * `carteira` carrega só para alimentar `planejarHistoricoGestao()` —
     * o contrato público de operação é sempre `[tabela,acao,linha_id,antes,depois]`.
     */
    private function limparMetadadosInternos(array $operacoes): array
    {
        $chavesPublicas = ['tabela', 'acao', 'linha_id', 'antes', 'depois'];

        return array_map(
            fn ($op) => array_intersect_key($op, array_flip($chavesPublicas)),
            $operacoes
        );
    }

    private function carregarUsuario(int $id): array
    {
        $user = DB::table('users')->where('id', $id)->first(['id', 'name', 'active']);

        return [
            'id' => $id,
            'nome' => $user->name ?? "usuário #{$id} (não encontrado)",
            'ativo' => (bool) ($user->active ?? false),
        ];
    }

    /**
     * Aplica UMA operação do plano: update/delete gravam o backup ANTES da
     * escrita; insert escreve primeiro (para capturar o id gerado) e grava
     * o backup logo em seguida.
     *
     * WR-02 (revisão da Fase 159): o plano é calculado FORA da transação e,
     * no intervalo, `desempenho:warm-cache`, `CompanyController::update` e
     * afins podem escrever nas mesmas linhas. Por isso update/delete RELEEM
     * a linha aqui dentro ({@see self::conferirEstadoPlanejado()}), escrevem
     * condicionados ao estado planejado e exigem exatamente 1 linha afetada.
     * Qualquer divergência lança RuntimeException — `aplicar()` está dentro
     * de `DB::transaction`, então o lote inteiro volta atrás e o comando sai
     * com FAILURE.
     */
    private function aplicarOperacao(array $operacao, string $etapa, string $lote, int $deId, int $paraId, Carbon $aPartir): void
    {
        $baseBackup = [
            'lote' => $lote,
            'de_user_id' => $deId,
            'para_user_id' => $paraId,
            'a_partir' => $aPartir->toDateString(),
            'etapa' => $etapa,
            'tabela' => $operacao['tabela'],
            'created_at' => now(),
        ];

        if ($operacao['acao'] === 'update' || $operacao['acao'] === 'delete') {
            $atual = DB::table($operacao['tabela'])
                ->where('id', $operacao['linha_id'])
                ->lockForUpdate()
                ->first();

            $this->conferirEstadoPlanejado($operacao, $etapa, $atual, $paraId);
        }

        if ($operacao['acao'] === 'update') {
            DB::table('unificacao_contas_backup')->insert($baseBackup + [
                'acao' => 'update',
                'linha_id' => $operacao['linha_id'],
                'antes' => json_encode($operacao['antes']),
                'depois' => json_encode($operacao['depois']),
            ]);

            $afetadas = $this->restringirAoEstadoPlanejado(
                DB::table($operacao['tabela'])->where('id', $operacao['linha_id']),
                $operacao
            )->update($operacao['depois']);
            $this->exigirUmaLinhaAfetada($afetadas, $operacao);

            return;
        }

        if ($operacao['acao'] === 'delete') {
            DB::table('unificacao_contas_backup')->insert($baseBackup + [
                'acao' => 'delete',
                'linha_id' => $operacao['linha_id'],
                'antes' => json_encode($operacao['antes']),
                'depois' => null,
            ]);

            $afetadas = $this->restringirAoEstadoPlanejado(
                DB::table($operacao['tabela'])->where('id', $operacao['linha_id']),
                $operacao
            )->delete();
            $this->exigirUmaLinhaAfetada($afetadas, $operacao);

            return;
        }

        // insert — escreve primeiro para capturar o id gerado.
        $novoId = DB::table($operacao['tabela'])->insertGetId($operacao['depois']);
        DB::table('unificacao_contas_backup')->insert($baseBackup + [
            'acao' => 'insert',
            'linha_id' => $novoId,
            'antes' => null,
            'depois' => json_encode($operacao['depois']),
        ]);
    }

    /**
     * WR-02: confere, DENTRO da transação, que a linha relida ainda está no
     * estado em que o plano a viu. Lança RuntimeException (derruba o lote)
     * quando:
     *  - a linha não existe mais;
     *  - `update`: alguma coluna de `antes` mudou (ex.: carteira ou PPA
     *    reatribuído a um terceiro no intervalo);
     *  - `delete`: a linha não é mais da origem, ou deixou de ser cache
     *    (snapshot regravado por `consolidar_mes` — competência fechada NUNCA
     *    é apagada);
     *  - etapas com colisão (`carteira`, `nps_atribuicoes`, `nps_imputacoes`):
     *    o `delete` exige que a linha do destino que motivou a colisão AINDA
     *    exista (senão a empresa ficaria sem o responsável); o `update` exige
     *    que ela continue NÃO existindo (senão viraria duplicata).
     */
    private function conferirEstadoPlanejado(array $operacao, string $etapa, ?object $atual, int $paraId): void
    {
        $rotulo = "{$operacao['tabela']}#{$operacao['linha_id']}";

        if ($atual === null) {
            $this->recusarPorMudanca($rotulo, 'a linha não existe mais');
        }

        if ($operacao['acao'] === 'update') {
            foreach ($operacao['antes'] as $coluna => $valor) {
                if (! $this->mesmoValor($atual->{$coluna} ?? null, $valor)) {
                    $this->recusarPorMudanca($rotulo, "{$coluna} não é mais o do plano");
                }
            }
        } else { // delete
            if (! $this->mesmoValor($atual->user_id ?? null, $operacao['antes']['user_id'] ?? null)) {
                $this->recusarPorMudanca($rotulo, 'a linha não é mais da origem');
            }
            if ($operacao['tabela'] === 'desempenho_score_snapshots' && $atual->mes_referencia !== null) {
                $this->recusarPorMudanca($rotulo, 'o snapshot virou MENSAL (competência fechada)');
            }
            if ($operacao['tabela'] === 'desempenho_company_score_snapshots'
                && $atual->origem === CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES) {
                $this->recusarPorMudanca($rotulo, 'o detalhe por empresa foi regravado por consolidar_mes');
            }
        }

        $consultaColisao = match ($etapa) {
            'carteira' => $this->consultaColisaoCarteira($atual, $paraId),
            'nps_atribuicoes' => $this->consultaColisaoAtribuicao($atual, $paraId),
            'nps_imputacoes' => $this->consultaColisaoImputacao($atual, $paraId),
            default => null,
        };

        if ($consultaColisao === null) {
            return;
        }

        $colide = $consultaColisao->exists();

        if ($operacao['acao'] === 'delete' && ! $colide) {
            $this->recusarPorMudanca($rotulo, 'a linha do destino que motivou a colisão não existe mais');
        }
        if ($operacao['acao'] === 'update' && $colide) {
            $this->recusarPorMudanca($rotulo, 'o destino passou a ter a mesma linha (viraria duplicata)');
        }
    }

    /**
     * WR-02: condiciona a escrita ao estado planejado (defesa em
     * profundidade, além da releitura): `update` só casa se as colunas de
     * `antes` continuam iguais; `delete` só casa se a linha ainda é da
     * origem e, nas tabelas de snapshot, ainda é cache.
     */
    private function restringirAoEstadoPlanejado(\Illuminate\Database\Query\Builder $query, array $operacao): \Illuminate\Database\Query\Builder
    {
        $condicoes = $operacao['acao'] === 'update'
            ? $operacao['antes']
            : ['user_id' => $operacao['antes']['user_id'] ?? null];

        foreach ($condicoes as $coluna => $valor) {
            $valor === null ? $query->whereNull($coluna) : $query->where($coluna, $valor);
        }

        if ($operacao['acao'] === 'delete' && $operacao['tabela'] === 'desempenho_score_snapshots') {
            $query->whereNull('mes_referencia');
        }
        if ($operacao['acao'] === 'delete' && $operacao['tabela'] === 'desempenho_company_score_snapshots') {
            $query->where('origem', '!=', CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES);
        }

        return $query;
    }

    private function exigirUmaLinhaAfetada(int $afetadas, array $operacao): void
    {
        if ($afetadas !== 1) {
            $this->recusarPorMudanca(
                "{$operacao['tabela']}#{$operacao['linha_id']}",
                "{$operacao['acao']} afetou {$afetadas} linha(s), esperado 1"
            );
        }
    }

    private function recusarPorMudanca(string $rotulo, string $motivo): never
    {
        throw new \RuntimeException(
            "linha {$rotulo} mudou desde o plano ({$motivo}) — nada foi gravado; rode o comando de novo para replanejar."
        );
    }

    /** Comparação null-safe e tolerante ao tipo que o driver devolve (int/string/bool). */
    private function mesmoValor(mixed $atual, mixed $esperado): bool
    {
        if ($atual === null || $esperado === null) {
            return $atual === null && $esperado === null;
        }
        if (is_bool($esperado)) {
            return (bool) $atual === $esperado;
        }

        return (string) $atual === (string) $esperado;
    }

    /**
     * Derruba a chave de cache do desempenho dos dois usuários, mês a mês,
     * do início de `--a-partir` até o mês corrente (inclusive). NUNCA
     * `cache:clear` (learnings §5 — já derrubou o site inteiro em produção).
     */
    private function bustarCache(int $deId, int $paraId, Carbon $aPartir): void
    {
        $scoreService = app(DesempenhoScoreService::class);
        $mes = $aPartir->copy()->startOfMonth();
        $fim = now()->copy()->startOfMonth();

        while ($mes->lte($fim)) {
            Cache::forget($scoreService->cacheKey($deId, $mes));
            Cache::forget($scoreService->cacheKey($paraId, $mes));
            $mes = $mes->copy()->addMonthNoOverflow();
        }
    }
}
