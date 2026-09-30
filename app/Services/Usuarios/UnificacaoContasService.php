<?php

namespace App\Services\Usuarios;

use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\DesempenhoScoreSnapshot;
use App\Services\Desempenho\CompanyScoreSnapshotWriter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fase 159 Plano 159-05 (D-06) — junção das contas: NÚCLEO (carteira,
 * histórico de gestão, cargos, desativação). O plano 159-06 acrescenta NPS,
 * snapshots, PPAs e onboardings sobre a mesma estrutura.
 *
 * Por que existe: a conta de origem (`--de`) deixa de existir como perfil
 * ativo e todo o histórico vivo que ela carrega (carteira, cargos) passa
 * para a conta de destino (`--para`) a partir de uma competência. Esse dado
 * alimenta bônus — um erro aqui é irreversível se não houver backup, e pode
 * passar por "sucesso" se a conferência for só pelo texto impresso na tela
 * (learnings §4/§10.1: `consolidar-mes` já devolveu exit 0 falhando para 11
 * de 12 profissionais). Por isso:
 *  - `planejar()` é SOMENTE LEITURA — nunca escreve, serve tanto para o
 *    dry-run quanto para a reconsulta pós-`--apply` (Task 2).
 *  - A Task 2 deste plano acrescenta `aplicar()`/`desfazer()`, que gravam o
 *    estado ANTERIOR em `unificacao_contas_backup` antes de cada
 *    UPDATE/DELETE (e o estado NOVO logo após cada INSERT), tudo dentro de
 *    uma única transação.
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
 * Trava de competência consolidada (D-06): a junção nunca recua sobre uma
 * competência já fechada — nem por snapshot mensal (`desempenho_score_snapshots`)
 * nem por detalhe por empresa gravado por `desempenho:consolidar-mes`
 * (`desempenho_company_score_snapshots.origem = consolidar_mes`). "Fechada"
 * aqui é sempre >= o início de `--a-partir`: competências ANTERIORES a
 * `--a-partir` nem entram na conta, porque a junção não pretende tocá-las.
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
            $this->bloqueiosCompetenciaConsolidada($deId, $paraId, $aPartirInicio)
        );

        $etapaCarteira = $this->planejarCarteira($deId, $paraId);
        // historico_gestao precisa dos metadados internos (_company_id/_role)
        // de cada operação da carteira ANTES de limpá-los do contrato público.
        $etapaHistorico = $this->planejarHistoricoGestao($etapaCarteira['operacoes'], $deId, $paraId, $agora);
        $etapaCarteira['operacoes'] = $this->limparMetadadosInternos($etapaCarteira['operacoes']);
        $etapaCargos = $this->planejarCargos($deId, $paraId, $agora);
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

        return [
            'de' => $de,
            'para' => $para,
            'a_partir' => $aPartirInicio->format('Y-m'),
            'bloqueios' => $bloqueios,
            'avisos' => $avisos,
            'etapas' => [$etapaCarteira, $etapaHistorico, $etapaCargos, $etapaDesativar],
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
            $colisao = DB::table('company_users')
                ->where('user_id', $paraId)
                ->where('company_id', $linha->company_id)
                ->where('role', $linha->role)
                ->when(
                    $linha->servico_id === null,
                    fn ($q) => $q->whereNull('servico_id'),
                    fn ($q) => $q->where('servico_id', $linha->servico_id)
                )
                ->exists();

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
}
