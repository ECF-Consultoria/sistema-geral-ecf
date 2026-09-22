<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Ppa extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['escopo', 'company_id', 'mlb_empresa_id', 'title', 'status', 'due_date', 'sent_at', 'completed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'PPA criado',
                'updated' => 'PPA atualizado',
                'deleted' => 'PPA excluído',
                default   => $eventName,
            });
    }

    /** PPA de carteira (módulo PPA original, alvo = Company). */
    public const ESCOPO_GERAL = 'geral';

    /** PPA Polos (quick 260805-dzu; alvo = MlbEmpresa do projeto POLOS). */
    public const ESCOPO_POLOS = 'polos';

    /**
     * As situações pelas quais a lista filtra.
     *
     * As três primeiras são os GRUPOS da régua de atenção — os mesmos valores
     * de `resources/js/lib/ppaAgrupamento.js`, e por isso strings iguais dos
     * dois lados. `vencido` não é grupo: atravessa os três (um plano vencido
     * está, ao mesmo tempo, em andamento ou a fazer) e por isso só existe como
     * filtro.
     */
    public const GRUPO_ANDAMENTO  = 'andamento';
    public const GRUPO_FAZER      = 'fazer';
    public const GRUPO_CONCLUIDO  = 'concluido';
    public const SITUACAO_VENCIDO = 'vencido';

    public const SITUACOES = [
        self::SITUACAO_VENCIDO,
        self::GRUPO_ANDAMENTO,
        self::GRUPO_FAZER,
        self::GRUPO_CONCLUIDO,
    ];

    protected $fillable = [
        'escopo', 'company_id', 'mlb_empresa_id', 'mentor_id', 'title', 'description', 'actions',
        'status', 'trello_board_url', 'workspace_token', 'due_date', 'sent_at', 'completed_at',
    ];

    protected $casts = [
        'actions' => 'array',
        'due_date' => 'date',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function mlbEmpresa() { return $this->belongsTo(MlbEmpresa::class, 'mlb_empresa_id'); }
    public function mentor() { return $this->belongsTo(User::class, 'mentor_id'); }
    public function tasks() { return $this->hasMany(PpaTask::class)->orderBy('order'); }

    /** Colunas EXTRAS do quadro. As três fixas são o ENUM `ppa_tasks.status`. */
    public function colunas() { return $this->hasMany(PpaColuna::class)->orderBy('posicao'); }

    /** Filtra por escopo ('geral' ou 'polos'); PPA antigo sem escopo conta como geral. */
    public function scopeDoEscopo($query, string $escopo)
    {
        return $escopo === self::ESCOPO_GERAL
            ? $query->where(fn ($q) => $q->where('escopo', self::ESCOPO_GERAL)->orWhereNull('escopo'))
            : $query->where('escopo', $escopo);
    }

    /**
     * As três contagens de tarefas que a LISTA de planos precisa, numa
     * subquery cada — e não uma consulta por linha.
     *
     * Antes, a lista fazia `$p->tasks()->count()` dentro do `through()`: duas
     * idas ao banco por plano, 40 numa página de 20. Como a ordenação passou a
     * depender dessas contagens, elas precisavam estar no SELECT de qualquer
     * forma.
     */
    public function scopeComContagemDeTarefas($query)
    {
        return $query->withCount([
            'tasks',
            'tasks as tasks_done_count'  => fn ($q) => $q->where('status', 'done'),
            'tasks as tasks_doing_count' => fn ($q) => $q->where('status', 'doing'),
        ]);
    }

    /**
     * A ordem em que os planos pedem atenção — o espelho, em SQL, da régua que
     * `resources/js/lib/ppaAgrupamento.js` aplica na tela.
     *
     * Os dois PRECISAM concordar: a tela agrupa o que recebe, e a lista é
     * paginada. Se o banco mandasse os planos em outra ordem, a página 1 traria
     * concluídos enquanto um plano em andamento esperaria na página 2 — e a
     * seção "Em andamento" apareceria vazia numa lista que tem planos andando.
     *
     * 1. grupo: em andamento (0) · a fazer (1) · concluído (2);
     * 2. dentro do grupo, prazo mais apertado primeiro, sem prazo por último;
     * 3. empate: o mais recente primeiro, como sempre foi.
     *
     * Os nomes usados no CASE são os aliases de {@see scopeComContagemDeTarefas},
     * e por isso ele tem de ser aplicado antes. Alias do SELECT em ORDER BY
     * funciona em MySQL/MariaDB e em SQLite — o ORDER BY é avaliado depois da
     * projeção, ao contrário do WHERE.
     */
    public function scopeOrdenadoPorAtencao($query)
    {
        return $query
            ->orderByRaw("CASE
                WHEN status = 'completed' THEN 2
                WHEN tasks_count > 0 AND tasks_done_count = tasks_count THEN 2
                WHEN tasks_doing_count > 0 THEN 0
                ELSE 1
            END")
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->orderByDesc('created_at');
    }

    /**
     * Filtra a lista por situação — a MESMA régua de {@see scopeOrdenadoPorAtencao},
     * agora no WHERE.
     *
     * Este é o QUARTO lugar em que a régua de agrupamento do PPA existe (os
     * outros três estão em `.planning/learnings/portal-do-cliente.md` §25), e
     * ele tem de concordar com os demais: a tela agrupa o que recebe, então um
     * filtro que discordasse da régua devolveria planos que a seção escolhida
     * não mostra — a lista viria "vazia" com o contador dizendo que há 7.
     *
     * Por que `whereHas` e não os aliases do `withCount`: alias de SELECT vale
     * em `ORDER BY` (que é avaliado depois da projeção) mas NÃO em `WHERE`.
     * Reaproveitar `tasks_doing_count` aqui estouraria no MariaDB — e passaria
     * no SQLite dos testes, que é permissivo com isso.
     *
     * Situação desconhecida (ou vazia) não filtra nada: o parâmetro vem da URL,
     * e lixo na query string deve devolver a lista inteira, não um erro.
     */
    public function scopeDaSituacao($query, ?string $situacao)
    {
        if (! in_array($situacao, self::SITUACOES, true)) {
            return $query;
        }

        // Vencido olha só o prazo, e ignora o plano que a equipe encerrou —
        // é a mesma regra de `diasAteOPrazo()`, que é quem pinta o selo
        // vermelho na tela. Sem esse recorte, "Vencidos" traria de volta todo
        // plano fechado com prazo antigo, que é ruído e não trabalho.
        if ($situacao === self::SITUACAO_VENCIDO) {
            return $query
                ->where('status', '!=', 'completed')
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString());
        }

        // "Concluído" = encerrado pela equipe OU com todas as tarefas em `done`.
        // O `has('tasks')` não é enfeite: sem ele, plano SEM tarefa nenhuma
        // entraria aqui por vacuidade (não existe tarefa pendente) — e a régua
        // do JS manda ele para "A fazer", de propósito.
        $concluido = fn ($q) => $q
            ->where('status', 'completed')
            ->orWhere(fn ($interno) => $interno
                ->has('tasks')
                ->whereDoesntHave('tasks', fn ($t) => $t->where('status', '!=', 'done')));

        return match ($situacao) {
            self::GRUPO_CONCLUIDO => $query->where($concluido),
            self::GRUPO_ANDAMENTO => $query->whereNot($concluido)
                ->whereHas('tasks', fn ($t) => $t->where('status', 'doing')),
            self::GRUPO_FAZER     => $query->whereNot($concluido)
                ->whereDoesntHave('tasks', fn ($t) => $t->where('status', 'doing')),
        };
    }

    /**
     * Recorte pela data em que o PPA foi criado.
     *
     * `whereDate` e não comparação direta com o timestamp: os dois extremos
     * chegam como DIA (o `<input type="date">` da tela). Comparar
     * `created_at <= '2026-09-22'` deixaria de fora tudo que foi criado depois
     * da meia-noite do próprio dia escolhido — o filtro perderia o dia final
     * inteiro, calado.
     */
    public function scopeCriadoEntre($query, ?string $de, ?string $ate)
    {
        return $query
            ->when($de,  fn ($q) => $q->whereDate('created_at', '>=', $de))
            ->when($ate, fn ($q) => $q->whereDate('created_at', '<=', $ate));
    }

    /**
     * Normaliza os filtros que chegam pela URL, para os dois controllers da
     * lista (carteira e Polos) aplicarem o mesmo critério.
     *
     * Devolve sempre as três chaves, com `null` no lugar do que não veio ou
     * não serve — é esse mesmo array que volta para a tela repovoar os campos.
     * Nada aqui aborta: filtro inválido vira "sem filtro", porque uma URL
     * colada pela metade tem de abrir a lista, não uma tela de erro.
     */
    public static function filtrosDaLista(array $entrada): array
    {
        $dia = fn ($valor) => is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)
            ? $valor
            : null;

        $situacao = $entrada['situacao'] ?? null;

        return [
            'situacao' => in_array($situacao, self::SITUACOES, true) ? $situacao : null,
            'de'       => $dia($entrada['de'] ?? null),
            'ate'      => $dia($entrada['ate'] ?? null),
        ];
    }

    /**
     * Dias entre hoje e `due_date` (negativo = passou). `null` quando não há
     * prazo ou quando o plano já foi encerrado.
     *
     * Calculado no servidor pela mesma razão de `PpaQuadroService::prazo()`: o
     * mesmo cálculo no navegador usaria o fuso de quem olha, e o plano ficaria
     * "atrasado" um dia antes para uns e não para outros. Plano encerrado
     * devolve `null` porque atraso de trabalho fechado não é atraso — é o que
     * apaga o selo vermelho nas duas telas.
     */
    public function diasAteOPrazo(): ?int
    {
        if (! $this->due_date || $this->status === 'completed') {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_date->startOfDay(), false);
    }

    /**
     * Nome da empresa dona do plano, seja ela Company (escopo geral) ou
     * MlbEmpresa (escopo polos). Evita espalhar o ?? pelas telas.
     */
    public function nomeEmpresa(): string
    {
        return $this->company?->name ?? $this->mlbEmpresa?->nome ?? '—';
    }
}
