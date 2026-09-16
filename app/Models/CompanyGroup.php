<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo nomeado de empresas (tipo carteira com nome livre) usado em /companies.
 *
 * Uma empresa pertence a no máximo um grupo via companies.company_group_id.
 * Independente da hierarquia parent_company_id (matriz/filiais).
 *
 * ## Fase 143 — a árvore de UM nível (`parent_id`)
 *
 * Os grupos de hoje foram criados pensando em NPS (143-CONTEXT, D-01), e
 * por isso alguns são SUBGRUPOS de um mesmo cliente — o que faz o
 * fechamento cobrar em pedaços e perder o desconto por volume da tabela
 * progressiva. `parent_id` pendura um grupo em outro: quem manda na
 * COBRANÇA passa a ser a RAIZ da árvore (`raizId()`), nunca o subgrupo.
 *
 * ⛔ O NPS continua olhando só `company_group_id` — a unicidade
 * `(company_group_id, template_id, month_reference)` de `nps_group_surveys`
 * e `NpsGrupoCoberturaService` ficam INTOCADOS. Cada subgrupo segue podendo
 * ter o seu link no mês; é essa condição que torna a Fase 143 possível.
 *
 * ⚠️ **Um nível só, e é regra de negócio, não enfeite** (D-05 do CONTEXT):
 * grupo que TEM pai não pode SER pai. Sem essa trava a agregação do
 * fechamento vira caminhada de profundidade desconhecida, e um ciclo
 * (`A→B→A`) trava o laço de consolidação para sempre. A trava é aplicada
 * no `saving` do model (ver `booted()`), não só na tela — assim vale para
 * qualquer caminho de escrita, inclusive o que ainda não existe.
 */
class CompanyGroup extends Model
{
    protected $fillable = [
        'name', 'color', 'parent_id',
        // Quick 260916-onn — "não participa do fechamento". O model não usa
        // `LogsActivity`: quem grava (`ForaDoFechamentoController`) registra a
        // trilha à mão. `_por` vem SEMPRE da sessão.
        'fora_do_fechamento', 'fora_do_fechamento_motivo', 'fora_do_fechamento_por', 'fora_do_fechamento_em',
    ];

    protected $casts = [
        'parent_id'              => 'int',
        'fora_do_fechamento'     => 'boolean',
        'fora_do_fechamento_por' => 'integer',
        'fora_do_fechamento_em'  => 'datetime',
    ];

    /**
     * Trava de integridade da árvore (Fase 143, D-05): valida SEMPRE que
     * `parent_id` está sendo definido para um valor não nulo.
     *
     * Só consulta o banco nesse caso — escrita de nome/cor (o CRUD normal
     * de grupos) não paga query nenhuma.
     */
    protected static function booted(): void
    {
        static::saving(function (self $grupo) {
            if (! $grupo->isDirty('parent_id') || $grupo->parent_id === null) {
                return;
            }

            $grupo->validarPaiOuFalhar((int) $grupo->parent_id);
        });
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * Grupo-pai (o grupo de COBRANÇA) — `null` quando este grupo já é a
     * raiz. Fase 143.
     */
    public function pai(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Subgrupos pendurados neste grupo. Pela trava de um nível, um subgrupo
     * NUNCA tem subgrupos próprios — esta relação é sempre folha. Fase 143.
     */
    public function subgrupos(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Id da RAIZ da árvore — a chave pela qual o fechamento agrega a
     * cobrança (Fase 143, T3).
     *
     * ⚠️ **Sem query, por contrato.** É chamado dentro do laço de ~200
     * empresas de `fechamento:consolidar-mes`; uma query aqui seriam 200
     * consultas por consolidação. Como a hierarquia tem UM nível só (a
     * trava de `booted()` garante), a raiz é derivável do atributo
     * `parent_id` que já veio no SELECT: quem tem pai, o pai É a raiz; quem
     * não tem, é a própria raiz.
     */
    public function raizId(): int
    {
        return (int) ($this->parent_id ?? $this->id);
    }

    /**
     * A RAIZ da árvore como model — `$this` quando o grupo não tem pai.
     *
     * Custa uma query só quando `parent_id` não é nulo E a relação `pai`
     * não veio carregada. Com `parent_id` nulo (os 15 grupos de hoje) o
     * Eloquent nem consulta: `BelongsTo` com chave estrangeira nula devolve
     * `null` sem ir ao banco. Quem chama dentro de laço deve carregar
     * `grupo.pai` no eager loading.
     */
    public function raiz(): self
    {
        if ($this->parent_id === null) {
            return $this;
        }

        // Defensivo: pai apagado entre o SELECT e esta chamada — o grupo
        // volta a ser raiz de si mesmo, que é o que `nullOnDelete()` fará.
        return $this->pai ?? $this;
    }

    /** Este grupo está pendurado em outro? Fase 143. */
    public function ehSubgrupo(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * Valida a trava de UM nível antes de gravar `parent_id` (Fase 143,
     * D-05). Recusa nos três sentidos, cada um cobrindo um jeito de criar
     * profundidade ou ciclo:
     *
     * 1. apontar para si mesmo (`A→A`);
     * 2. pôr como pai um grupo que JÁ tem pai (viraria nível 3);
     * 3. pôr pai num grupo que JÁ é pai de alguém (viraria nível 3 por
     *    baixo — e é o que fecha o ciclo `A→B→A`).
     *
     * @throws \InvalidArgumentException mensagem já em pt-BR, pronta para a
     *         tela do plano seguinte exibir sem tradução.
     */
    public function validarPaiOuFalhar(int $parentId): void
    {
        if ($this->exists && (int) $this->id === $parentId) {
            throw new \InvalidArgumentException('Um grupo não pode ser o próprio grupo-pai.');
        }

        $pai = static::query()->find($parentId);

        if ($pai === null) {
            throw new \InvalidArgumentException("Grupo-pai {$parentId} não existe.");
        }

        if ($pai->parent_id !== null) {
            throw new \InvalidArgumentException(
                "\"{$pai->name}\" já está dentro de outro grupo — a hierarquia tem um nível só."
            );
        }

        if ($this->exists && static::query()->where('parent_id', $this->id)->exists()) {
            throw new \InvalidArgumentException(
                "\"{$this->name}\" já é o grupo-pai de outros grupos — a hierarquia tem um nível só."
            );
        }
    }

    /**
     * Links de NPS de GRUPO gerados para este grupo (Fase 119.1 Plan 05).
     */
    public function npsGroupSurveys(): HasMany
    {
        return $this->hasMany(NpsGroupSurvey::class);
    }

    /**
     * Grupos que este usuário pode ver e usar (hoje: o seletor "Um grupo de
     * empresas" do NPS e a autorização de `NpsGrupoController`). Admin vê
     * todos.
     *
     * Régua para não-admin — 2026-08-18, bug reportado: uma estrategista não
     * conseguia gerar NPS do grupo MaxiGold, que o admin via normalmente.
     *
     *  (a) pelo menos UMA empresa do grupo tem que estar na carteira dele —
     *      senão um grupo de outra pessoa apareceria para todo mundo;
     *  (b) nenhuma empresa COM responsável pode estar fora da carteira dele.
     *
     * O (b) preserva a intenção original (tudo-ou-nada, para não vazar nem a
     * existência de um grupo que é de outra pessoa). O que mudou é que
     * empresa SEM NENHUM responsável atribuído não conta mais nesse teste:
     * ela é um cadastro ainda não distribuído, não a carteira de outra
     * pessoa, e travar o grupo inteiro por causa dela punia quem cuida das
     * demais. Foi exatamente o caso medido: o grupo tinha 5 empresas, 4 dela
     * e 1 órfã (duplicata recém-importada) — e o grupo sumia para TODOS os
     * não-admins (0 pessoas o enxergavam).
     *
     * A órfã não entra no link por tabela: `NpsGrupoCoberturaService` a
     * exclui com o motivo `sem_responsavel`, visível na prévia de cobertura.
     */
    public function scopeVisivelPara($query, User $user)
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $daCarteira = $user->companies()->pluck('companies.id');

        return $query
            ->whereHas('companies', fn ($q) => $q->whereIn('companies.id', $daCarteira))
            ->whereDoesntHave('companies', fn ($q) => $q
                ->whereNotIn('companies.id', $daCarteira)
                ->whereHas('users'));
    }

    /**
     * Versão por instância da mesma régua de `scopeVisivelPara()` — as duas
     * NUNCA podem divergir (uma lista o seletor, a outra autoriza o POST;
     * divergir significa oferecer um grupo que devolve 403).
     */
    public function visivelPara(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $daCarteira = $user->companies()->pluck('companies.id');
        $doGrupo    = $this->companies()->pluck('companies.id');

        if ($doGrupo->intersect($daCarteira)->isEmpty()) {
            return false;
        }

        return $this->companies()
            ->whereNotIn('companies.id', $daCarteira)
            ->whereHas('users')
            ->doesntExist();
    }
}
