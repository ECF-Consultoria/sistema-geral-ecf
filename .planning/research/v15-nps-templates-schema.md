# Research: v15.0 NPS Templates — decisões técnicas travadas

**Data:** 2026-07-07
**Escopo:** 5 decisões laser-focadas antes do roadmapper decompor v15.0 em fases
**Confidence:** HIGH (todas as decisões verificadas contra padrões existentes do repo)
**Consumidor:** `gsd-roadmapper` (próxima etapa)

---

## 1. Snapshot pattern — `nps_response_answers` por-row (Opção B refinada)

**Decisão:** JSON snapshot **por-row em `nps_response_answers`**, replicando o padrão já provado em `nps_respostas_customizadas` (`pergunta_texto_snapshot` + `tipo_snapshot`, ver migration `2026_06_12_100002_create_nps_respostas_customizadas_table.php:44-54`).

**Por que não A (`template_snapshot_json` em `nps_surveys`):** força re-parse do JSON toda vez que dashboard agrega uma dimensão; perde índice em `question_dimensao` e `option_peso`; e o roadmap já contempla queries frequentes (NPS-E-05).

**Por que não C (Spatie ActivityLog):** `activity_log.properties` é log de auditoria, não fonte de leitura — indexar dentro de JSON em `activity_log` seria tomar dívida.

**Colunas em `nps_response_answers`:**

| coluna | tipo | uso |
|---|---|---|
| `response_id` | FK cascade | dono |
| `template_question_id` | FK nullOnDelete | referência viva |
| `template_option_id` | FK nullOnDelete | referência viva |
| `question_texto_snapshot` | varchar 500 | congelado no submit |
| `question_dimensao_snapshot` | enum(estrategista/analista/empresa/geral) | congelado — dashboard NPS-E-05 filtra por isso |
| `option_label_snapshot` | varchar 200 | congelado |
| `option_peso_snapshot` | tinyint | congelado — `NpsScoreCalculator` faz `AVG` disso |
| `comentario` | text nullable | resposta livre opcional |

Índice `(response_id, question_dimensao_snapshot)` acelera `NpsScoreCalculator` (NPS-B-02). Snapshot é **verdade histórica**; FK vira audit-friendly mas nunca fonte de cálculo.

---

## 2. Unique index parcial — Generated column virtual + unique (portável MySQL 5.7+ e SQLite 3.31+)

**Decisão:** `nps_surveys` recebe **coluna virtual gerada** `dedup_key` (`NULL` quando survey não conta pra dedup) + unique index nela. NULL não conflita com NULL em unique — comportamento nativo em ambos os bancos.

```php
// database/migrations/YYYY_MM_DD_create_nps_survey_dedup_key.php
Schema::table('nps_surveys', function (Blueprint $t) {
    $driver = DB::connection()->getDriverName();
    if ($driver === 'sqlite') {
        // SQLite 3.31+: partial unique index direto — mais expressivo
        DB::statement("CREATE UNIQUE INDEX nps_surveys_dedup_uniq
            ON nps_surveys(company_id, month_reference, template_id)
            WHERE status = 'completed' AND completed_at IS NOT NULL");
    } else {
        // MySQL 5.7+/MariaDB 10.2+: virtual generated column + unique
        DB::statement("ALTER TABLE nps_surveys ADD COLUMN dedup_key VARCHAR(64)
            GENERATED ALWAYS AS (
              CASE WHEN status = 'completed' AND completed_at IS NOT NULL
                   THEN CONCAT(company_id, '|', DATE_FORMAT(month_reference, '%Y-%m'), '|', template_id)
              END
            ) VIRTUAL");
        DB::statement("ALTER TABLE nps_surveys ADD UNIQUE INDEX nps_surveys_dedup_uniq (dedup_key)");
    }
});
```

**Guard no controller** ainda vale (NPS-B-03): captura a exceção `QueryException` código `23000` e redireciona pra tela "Já respondida no mês" — DB garante integridade, controller garante UX.

**Rejeitado:** `->virtualAs()` do Schema builder — Laravel emite sintaxe incompatível com SQLite in-memory nesse caso; `DB::statement` por driver é mais previsível.

---

## 3. Drag-and-drop React — **sem library** (Up/Down + input `ordem`)

**Decisão:** Zero dep nova. Padrão do projeto (v13.0/v14.0) evita adicionar libs. `NpsTemplateEdit.jsx` usa botões ⬆⬇ + input `type="number"` de ordem, mesmo padrão de `NpsPerguntasCustomizadasController` atual. Verificado: `package.json` (linhas 27-51) não tem `@dnd-kit` nem `react-beautiful-dnd`.

**Trade-off aceito:** DX levemente pior pro admin, mas: (a) admin reordena raramente após criar template, (b) mobile UX de dnd exige toque preciso, (c) evita ~15KB no bundle + risco de regressão de acessibilidade.

**Se surgir demanda depois:** promover pra `@dnd-kit/core` (moderna, tree-shakeable, mantida). Registrar em NPS-FUTURE.

---

## 4. `NpsTemplateService::resolveForCompany` — priority column + default fallback (Opção B com C embutido)

**Decisão:** `nps_templates` recebe `priority` (int, default `0`) e `is_default` (bool). Regra:

```php
public function resolveForCompany(Company $company): NpsTemplate
{
    $servicoIds = $company->contratosServico()->ativo()->pluck('servico_id');
    return NpsTemplate::query()
        ->where('active', true)
        ->whereHas('serviceScopes', fn($q) => $q->whereIn('servico_id', $servicoIds))
        ->orderByDesc('priority')
        ->orderBy('id')
        ->first()
        ?? NpsTemplate::where('is_default', true)->firstOrFail();
}
```

Empresa com [Gestão, Mentoria] recebe o template de **maior priority** entre os aplicáveis; empate → menor id (determinístico); zero aplicável → seed "NPS Padrão" (`is_default=true`, criado pela migration NPS-A-03). Exatamente 1 template pode ter `is_default=true` (unique index parcial nele também).

**Rejeitado A:** `pivot.created_at` é frágil (import bulk cria tudo no mesmo segundo). **Rejeitado D:** blocker no dispatch quebra idempotência do `nps:disparar-mensal` (NPS-B-04).

---

## 5. `escala` como açúcar sintático — Opção A refinada

**Decisão:** Backend tem UMA tabela `nps_template_options`. Tipo de pergunta é enum `['escala', 'opcoes']` no `nps_template_questions.tipo`, mas **serve só como hint pra UI admin**. `escala` = quando o admin escolhe esse tipo, backend auto-cria 5 options com labels `"1"…"5"` e pesos `1..5` (editáveis depois). Renderizador do form público trata os dois tipos **igualmente** (radio group; se todas as labels são numéricas curtas, aplica estilo picker compacto).

**Vantagem sobre C (unificar tudo em opcoes):** admin cria template rápido — 1 clique gera pergunta de escala pronta. Vantagem sobre B (escala sem options): backend uniforme — `NpsScoreCalculator` faz `AVG(option_peso_snapshot)` sem branch por tipo; snapshot per-row funciona igual pros 2.

**Regra de validação (NPS-B-05):** peso 1-5 hardcoded como limite; admin pode ajustar 1..5, UI bloqueia `> 5` até nova REQ.

---

## RESEARCH COMPLETE

**5 decisões travadas para o roadmapper:**

1. **Snapshot per-row em `nps_response_answers`** — replica padrão `nps_respostas_customizadas`; colunas `question_*_snapshot` + `option_*_snapshot` + índice `(response_id, question_dimensao_snapshot)`
2. **Unique index parcial via generated column virtual (MySQL) / partial index (SQLite)** — split por `DB::connection()->getDriverName()`; NULL não colide em unique; controller trata `QueryException 23000` pra UX
3. **Drag-and-drop = sem library** — Up/Down + input `ordem`, mantém padrão zero-deps v13/v14
4. **Precedência via `priority` DESC + `is_default` fallback** — determinístico, sem depender de pivot.created_at
5. **`escala` = 5 opções auto-geradas + editáveis** — 1 tabela `nps_template_options`, `NpsScoreCalculator` uniforme via `AVG(option_peso_snapshot)`

**Arquivo:** `C:\xampp\htdocs\ecf_admin\ecf_admin\.planning\research\v15-nps-templates-schema.md`

**Impacto imediato nas 2 primeiras fases (roadmapper):**
- Fase 1 (schema): 5 tabelas + migração de seed + coluna virtual dedup + snapshot columns
- Fase 2 (backend): `NpsTemplateService::resolveForCompany`, `NpsScoreCalculator::compute()` unificado, controller guard 23000

**Sem gaps abertos** — todas as 5 perguntas fechadas com recomendação única.
