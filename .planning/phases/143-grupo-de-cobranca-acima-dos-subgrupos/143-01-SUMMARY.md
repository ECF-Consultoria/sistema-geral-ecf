---
phase: 143-grupo-de-cobranca-acima-dos-subgrupos
plan: 01
subsystem: backend
tags: [fechamento, cobranca, company-groups, tabela-progressiva, migration]

requires:
  - phase: 137
    provides: "fechamento:consolidar-mes, FechamentoSnapshot/FechamentoGrupoSnapshot, FechamentoFaixaResolver"
  - phase: 138
    provides: "degrau de tabela do GRUPO (GrupoFaixaFaturamento), paraGrupo(), herdada_de_* visível"
  - phase: 141
    provides: "flag fechamento_tabela_por_empresa_ativa (LIGADA em produção desde 2026-09-09), CobrancaCalculator::mensalidade()"
provides:
  - "company_groups.parent_id — a árvore de UM nível que põe um grupo de cobrança acima dos subgrupos"
  - "CompanyGroup::pai()/subgrupos()/raiz()/raizId()/ehSubgrupo() + trava de um nível no saving()"
  - "FechamentoFaixaResolver — precedência raiz → subgrupo → empresa → serviço"
  - "fechamento:consolidar-mes — agregação da cobrança pela RAIZ da árvore"
affects: [143-02]

tech-stack:
  added: []
  patterns:
    - "raizId() derivado do atributo parent_id, sem query — roda dentro do laço de ~200 empresas do fechamento"
    - "Trava de integridade da árvore no saving() do model, não só na tela: vale para qualquer caminho de escrita, inclusive os que ainda não existem"
    - "Os dois degraus da árvore (raiz e subgrupo) resolvidos com UMA query (whereIn + filtro em memória)"
    - "Linha de empresa e linha de grupo gravam a MESMA chave de grupo (a raiz) — fechamento:verificar-consolidacao casa as duas por ela"

key-files:
  created:
    - database/migrations/2026_09_14_100000_add_parent_id_to_company_groups_table.php
    - tests/Feature/Phase143/Phase143ArvoreDeGruposTest.php
    - tests/Feature/Phase143/Phase143ResolverPrecedenciaTest.php
    - tests/Feature/Phase143/Phase143ConsolidarPelaRaizTest.php
    - .planning/phases/143-grupo-de-cobranca-acima-dos-subgrupos/deferred-items.md
  modified:
    - app/Models/CompanyGroup.php
    - app/Services/Fechamento/FechamentoFaixaResolver.php
    - app/Console/Commands/ConsolidarMesFechamento.php

key-decisions:
  - "fechamento_snapshots.company_group_id (linha de EMPRESA) passou a gravar a RAIZ, não só a linha de grupo. O plano só pedia a linha de grupo, mas VerificarConsolidacaoFechamento:143 casa membro com grupo por essa coluna — chaves diferentes fariam TODA linha de grupo virar LINHAS_ORFAS + DIVERGENCIA_CONTAGEM + DIVERGENCIA_SOMA_GRUPO. Coberto por teste que roda o verificador de verdade."
  - "paraGrupo() continua recebendo o grupo DIRETO da âncora (que pode ser subgrupo) e resolve a árvore por dentro. Passar a raiz na chamada perderia o 2º degrau quando só o subgrupo tem tabela."
  - "grupo_id/grupo_nome do shape identificam o DONO da tabela encontrada (a raiz quando foi dela que veio), não o grupo da empresa — é o que torna a herança visível, na linha da D-01 da Fase 138."
  - "A trava de um nível lança \\InvalidArgumentException com mensagem já em pt-BR, pronta para a tela do plano seguinte exibir sem tradução."

metrics:
  duration: "~2h"
  completed: 2026-09-14
  tarefas: 4
  testes_novos: 23
---

# Fase 143 Plano 01: A raiz do grupo passa a mandar na cobrança — Summary

O fechamento passou a agregar a cobrança pela **raiz** da árvore de grupos (`company_groups.parent_id`, um nível), em vez de por `company_group_id` cru — quatro grupos do mesmo cliente viram **uma** linha de cobrança, e a precedência da tabela virou **raiz → subgrupo → empresa → serviço**. Nada visível: é só o motor, e com `parent_id` nulo nos 15 grupos o comportamento é idêntico ao de hoje.

## O que foi feito

### T1 — `parent_id` em `company_groups`

Migration aditiva e idempotente (`Schema::hasColumn`). SQL compilado contra a gramática **MySQL** (não só a do SQLite dos testes) para conferir as três armadilhas já pagas neste projeto:

```sql
alter table `company_groups` add `parent_id` bigint unsigned null after `color`
alter table `company_groups` add constraint `company_groups_parent_idx`
  foreign key (`parent_id`) references `company_groups` (`id`) on delete set null
```

- `nullable()` vem ANTES do `nullOnDelete()` — erro 1830 (Fase 79) evitado, e o SQL acima prova.
- Nome de índice explícito, **25 caracteres** — erro 1059 (Fase 122) evitado (o gerado automaticamente também caberia, mas o plano pediu explícito e o explícito é o que não depende de convenção futura).
- Nenhuma coluna de tipo enumerado.

⚠️ A coluna **nasce nula nos 15 grupos** e ninguém ganha pai nesta entrega.

### T2 — Um nível só, e sem ciclo

`CompanyGroup` ganhou `pai()`, `subgrupos()`, `raiz()`, `raizId()`, `ehSubgrupo()` e a trava.

**A trava roda no `saving()` do model**, não só na tela — assim vale para qualquer caminho de escrita, inclusive os que ainda não existem. Ela só consulta o banco quando `parent_id` está sujo e não nulo: o CRUD normal de nome/cor não paga query nenhuma. Recusa nos três sentidos:

| tentativa | resultado |
|---|---|
| apontar para si mesmo | recusado — "Um grupo não pode ser o próprio grupo-pai." |
| pendurar num grupo que já tem pai | recusado — "… já está dentro de outro grupo — a hierarquia tem um nível só." |
| dar pai a um grupo que já é pai | recusado — mesma mensagem, pelo outro lado |
| ciclo `A→B→A` | recusado por **duas** travas ao mesmo tempo |

`raizId()` é `parent_id ?? id` — **nenhuma query**, por contrato. Como a hierarquia tem um nível só, o pai É a raiz, e o atributo já veio no SELECT. Há teste com 10 empresas medindo `DB::getQueryLog()` vazio depois de chamar `raizId()` e `raiz()` nas dez.

### T3 — O fechamento agrega pela RAIZ

`ConsolidarMesFechamento`:
- Passo 5 agrupa por `$c->grupo?->raizId()` em vez de `'company_group_id'`.
- `'grupo.pai'` entrou no eager loading — sem ele, `raiz()` seria uma consulta por empresa de subgrupo.
- `grupo_name` da linha de grupo virou o nome da **raiz** (o grupo de cobrança), nunca o do subgrupo da âncora.
- `empresa_ancora_id` intocado — continua sendo a identidade da linha que `AdminController::fechamentoAgregarGruposCongelados` usa para reencontrá-la.

### T4 — Precedência raiz → subgrupo → empresa

`FechamentoFaixaResolver::degrauDaArvoreDeGrupos()` (novo, privado) resolve os dois degraus com **uma query só** (`whereIn` nos dois ids + filtro em memória) — o método roda no laço de ~200 empresas, e duas queries seriam o dobro do laço. `paraEmpresa()` e `paraGrupo()` passaram a chamá-lo.

⛔ **`classificar()` não foi tocado.** Há teste novo travando a régua (`limite_superior >= faturamento`) nos pontos de corte exatos, inclusive o valor do caso real.

## A prova que vale mais: o caso MPozenato

`Phase143ConsolidarPelaRaizTest` monta o caso do CONTEXT em factory — quatro grupos, 10 empresas, os números medidos em produção em ago/2026 — e roda o **mesmo fixture** dos dois lados:

| | linhas de cobrança | faturamento da linha | faixa |
|---|---|---|---|
| **sem pai** (hoje) | **4** | 3,81 mi / 5,98 mi / 1,24 mi / 1,65 mi | cada uma na sua régua |
| **com os subgrupos pendurados** | **1** | **R$ 12.679.411,83** | faixa 2 da tabela da raiz → **R$ 21.000** |

E, junto: `empresas_count = 10`, `grupo_name = "MPozenato"`, `tabela_origem = 'grupo'`, `tabelas_divergentes = false`, nenhum subgrupo gerando linha própria, as 10 linhas de empresa apontando para a raiz, e — com a flag da Fase 141 ligada, que é o estado de produção — `cobranca_mensal = R$ 21.000,00`.

Um quarto teste roda `fechamento:verificar-consolidacao` de verdade sobre a competência consolidada e exige exit 0.

## Regressão zero enquanto ninguém tiver pai

É o que permite deployar sem medo, e está provado em três lugares:

1. `sem_nenhum_pai_o_fechamento_sai_exatamente_como_hoje_quatro_linhas` — mesmo fixture, quatro linhas, cada subgrupo com a sua soma, e o que não alcança tabela de grupo continua caindo na régua do serviço.
2. `grupo_sem_pai_resolve_exatamente_como_antes` / `grupo_sem_pai_e_sem_tabela_continua_caindo_na_empresa` — o resolver.
3. O gate inteiro: as 6 suítes de Fase 137/138/139/141/142 que cobrem fechamento continuam passando sem uma única falha nova.

Grupo sem pai **é a própria raiz** — `raizId()` devolve o próprio id, e todas as chaves de agregação ficam iguais às de antes.

## O NPS não sentiu nada

`nps_group_surveys`, a unicidade `(company_group_id, template_id, month_reference)` e `NpsGrupoCoberturaService` **não foram abertos** — nem para leitura de edição. A árvore é aditiva: `companies.company_group_id` continua apontando para o subgrupo, que é o que o link de NPS de grupo usa (há asserção explícita disso no teste do MPozenato).

⚠️ Rodei a suíte de NPS inteira por garantia (`--filter="Nps|NPS"`): **614 testes, 47 errors, 4 failures**. Os 47 errors são todos `UNIQUE constraint failed: setores.nome` (a migration da outra sessão). As 4 failures são **alheias a este plano** e estão detalhadas na seção do gate abaixo — nenhuma delas encosta em grupo.

## Desvios do plano

### [Regra 2 — funcionalidade crítica ausente] A linha de EMPRESA também precisava gravar a raiz

- **Achado em:** T3, lendo `VerificarConsolidacaoFechamento` antes de mexer.
- **Problema:** o plano mandava a linha de GRUPO gravar a raiz e não falava da linha de empresa. Mas `VerificarConsolidacaoFechamento:143` faz `$snapshotsEmpresa->where('company_group_id', $grupoSnap->company_group_id)` para casar membro com grupo. Com a empresa guardando o subgrupo e o grupo guardando a raiz, **toda** linha de grupo viraria `LINHAS_ORFAS`, e as que escapassem viriam como `DIVERGENCIA_SOMA_GRUPO` + `DIVERGENCIA_CONTAGEM`.
- **Correção:** `'company_group_id' => $company->grupo?->raizId() ?? $company->company_group_id` na linha de empresa, com comentário explicando por que as duas chaves têm de casar.
- **Custo aceito:** o snapshot deixa de registrar em qual subgrupo a empresa estava (item 3 do `deferred-items.md`).
- **Commit:** `ecc5b26c`.

Fora isso, o plano foi executado como escrito.

## Decisões que tomei sozinho

1. **`paraGrupo()` recebe o grupo direto, não a raiz.** Passar a raiz na chamada (o que seria o reflexo óbvio) perderia o 2º degrau: um cliente cuja raiz não tem tabela mas cujo subgrupo tem ficaria sem régua. Como o helper resolve a árvore por dentro, a linha da chamada nem mudou — só ganhou comentário.
2. **`grupo_id`/`grupo_nome` identificam o dono da tabela encontrada.** O plano dizia "preencha com a raiz"; implementei "com quem realmente tem a tabela" — que dá a raiz quando a tabela é dela (o caso do plano) e o subgrupo quando é dele. Herança invisível é o defeito que a D-01 da Fase 138 veio corrigir; dizer "veio do MPozenato" quando veio do DRossi reintroduziria o mesmo defeito ao contrário.
3. **Exceção de validação:** `\InvalidArgumentException` com mensagem já em pt-BR e sem jargão, para a tela do plano seguinte exibir sem traduzir.
4. **`parent_id` entrou no `$fillable`.** Conferi que `CompanyGroupController::store()/update()` montam arrays explícitos (`name`, `color`) — não há mass-assignment aberto para requisição nenhuma.
5. **Teste do MPozenato com os números literais de produção** em constantes de classe, decompostos por empresa de modo a somar exatamente R$ 12.679.411,83 — para o teste falar a mesma língua do CONTEXT.

## O que decidi NÃO fazer

Tudo em `deferred-items.md`, com o porquê. Em resumo, e em ordem de risco:

1. ⚠️ **`AdminController::fechamentoAgregarGruposAoVivo()` continua agrupando por `company_group_id` cru.** Fora do escopo declarado do plano (que nomeia `ConsolidarMesFechamento`), e inofensivo hoje. **No dia em que a UI permitir montar a árvore, a tela mostrará quatro linhas e o comando congelará uma** — divergência silenciosa entre o que a pessoa confere e o que vira cobrança. É a pendência de maior risco desta entrega.
2. **`CompararMensalidadeFechamento` idem** — e ele é justamente o comando do comparativo ANTES × DEPOIS que o CONTEXT exige antes de montar a árvore em produção. Precisa ser ajustado **antes** de servir de base para aprovação.
3. **Coluna de subgrupo no snapshot** — nenhuma tela pede hoje.
4. **UI para definir `parent_id`** — objeto do plano seguinte; o model já aceita e já valida.

Também não mexi em `NpsGrupoCoberturaService`, `NpsGroupSurvey` nem na migration de `nps_group_surveys` (proibição explícita), não rodei deploy, não toquei em `.env` nem na flag `fechamento_faturamento_da_api_ativo`, e não rodei `state.advance-plan`.

## Gate

Comando (exit code capturado ANTES de qualquer pipe — `EXIT=2`):

```
--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Phase143|Quick260909|Quick260910|Quick260911"
Tests: 707, Assertions: 3019, Errors: 28, PHPUnit Deprecations: 465.
```

**Separação exigida:**

| | quantidade | origem |
|---|---|---|
| passando | **679** | 656 do baseline + **23 testes novos** desta entrega |
| errors por `UNIQUE constraint failed: setores.nome` | **28** | migration `seed_setor_performance` da OUTRA sessão — **alheios, não consertados** |
| errors por qualquer outro motivo | **0** | — |
| **failures** | **0** | — |
| falhas novas atribuíveis a este plano | **0** | — |

Conferência: `grep -cE "^[0-9]+\) Tests"` = 28 blocos de erro; `grep -A1` desses 28 blocos casando `setores.nome` = 28. Os dois números batem, então **todos** os 28 errors são a colisão alheia. Nenhum teste `Phase143` aparece na lista de erros. 707 − 28 = 679 = 656 + 23. ✔

**Suíte de NPS (conferência extra, fora do gate):** `--filter="Nps|NPS"` → 614 testes, 47 errors, 4 failures. Todos os 47 errors são `setores.nome`. As 4 failures:

| teste | sintoma |
|---|---|
| `Phase119\CompanyScoreServiceStatusTest` (×2) | hash de token divergente |
| `Phase31NpsSubmitTest::test_generate_cria_survey_com_auto_generated_false` | `expires_at` fora de `now()+6..8d` |
| `Phase69\NpsPhase69IntegrationTest::test_fluxo_2_...` | `expires_at` esperado `2026-09-21` (hoje+7d), obtido `2026-09-30` (fim do mês) |

⚠️ **Nenhuma delas é causada por este plano**, e a causalidade está excluída por construção: os três arquivos têm **zero** referências a `CompanyGroup`/`company_group` (conferido por grep), e os sintomas são política de `expires_at` e hash de token — nada que passe por grupo, fechamento ou tabela de faixas. Este plano não tem um único ponto de contato com expiração de pesquisa NPS. As duas primeiras dependem de valor aleatório/tempo; as duas de `expires_at` batem com uma mudança de regra para fim-de-mês feita fora desta fase. **Não consertei nenhuma** — o plano manda parar e reportar, e é o que este parágrafo faz. Não havia número de referência prévio da suíte de NPS no PLAN (o baseline documentado cobre só o filtro do gate), então não pude comparar contagem contra contagem; comparei causalidade.

## Commits

| hash | assunto |
|---|---|
| `2bc791f6` | `feat(143-01): parent_id em company_groups, o grupo de cobranca acima dos subgrupos` |
| `4f9dfeca` | `feat(143-01): arvore de grupos com trava de um nivel e sem ciclo` |
| `38f6676b` | `feat(143-01): precedencia da tabela vira raiz -> subgrupo -> empresa` |
| `ecc5b26c` | `feat(143-01): o fechamento agrega a cobranca pela raiz do grupo` |

Todos com `git add` por caminho (árvore compartilhada com outra sessão ativa na v23.0) — nunca `git add -A`/`.`, nunca `git commit -a`, nunca `git stash`. `git status --porcelain app/ tests/ database/` conferido antes de cada commit; o único item alheio na área (`tests/Feature/CompanyPortfolioAccessTest.php`, não rastreado, da outra sessão) ficou intocado.

## Próximo passo

O plano 143-02 (a UI de montagem da árvore) precisa começar pelos itens 1 e 2 do `deferred-items.md` — sem eles, montar a árvore pela tela cria divergência entre a tela e a cobrança congelada.

## Self-Check: PASSED

Os 9 arquivos declarados existem em disco e os 4 commits existem no histórico (conferidos um a um por `git log --oneline --all`).
