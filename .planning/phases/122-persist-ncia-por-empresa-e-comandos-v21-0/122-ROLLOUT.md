---
phase: 122-persist-ncia-por-empresa-e-comandos-v21-0
plano: 05
tipo: runbook
status: NAO EXECUTADO — execução é do Plano 06
---

# Runbook de rollout — Fase 122 (persistência por empresa)

Roteiro em pt-BR, na ordem exata de execução. Nenhum passo deste documento
foi executado por este plano (122-05) — a execução em produção é o
**Plano 06**.

## 1. Pré-condições

- **Árvore git limpa.** A árvore de trabalho é compartilhada por várias
  sessões do Claude Code e por outro desenvolvedor. Antes de qualquer
  operação de deploy, confirmar que só os arquivos desta fase estão
  modificados (`git status --short`). Commitar SEMPRE por caminho explícito
  (`git commit -- <arquivos>`) — nunca `git add -A` ou `git add .`, sob
  pena de publicar trabalho de outra sessão junto.
- **A flag `metrics.performance_company_first_score` continua `false`.**
  Esta fase constrói persistência; nenhum plano dela liga a flag. Confirmar
  com `php artisan tinker --execute="var_dump(config('metrics.performance_company_first_score'));"`
  antes e depois do deploy — o resultado esperado é `false` nos dois
  momentos.
- **GATE MPP-04 segue `reprovado`.** O probe da Fase 121 mediu
  `cobertura_prev = 0.6415`, abaixo do limiar de 0,7 exigido para trocar a
  base do gate FIXMARG-03. Nenhum passo deste rollout reabre essa decisão.

## 2. Deploy

- `php artisan migrate --force` cria a tabela `desempenho_company_score_snapshots`
  (migration aditiva da Fase 122, Plano 01) — nenhuma tabela existente muda
  de shape.
- **`cache:clear` no VPS é PROIBIDO.** Incidente de 2026-07-30: `cache:clear`
  apaga o cache aquecido da Adman, o dashboard passa a esperar a API ao
  vivo, as requisições lentas ocupam os workers do `php-fpm` e até o login
  para. O bump de chave de cache do Plano 02 desta fase
  (`desempenho.compute.v15 → v16`) já torna qualquer `clear` desnecessário —
  a chave nova nunca colide com a antiga.
- Se algo precisar ser feito no cache/serviço depois do deploy, o caminho
  correto é:
  ```bash
  sudo systemctl reload php8.2-fpm
  php artisan adman:warm-diff
  ```

## 3. Backfill das competências fechadas

Rodar, uma competência por vez, **começando pela mais recente**:

```bash
php artisan desempenho:consolidar-mes --mes=2026-06
php artisan desempenho:consolidar-mes --mes=2026-05
php artisan desempenho:consolidar-mes --mes=2026-04
```

Estas três competências são as mesmas da rodada do gate aprovado na Fase 121
(`run_id=03787204-51a7-49fb-8478-da56a5b07e2a`, 11 profissionais, 0 falhas,
aceito com ressalvas — ressalva já investigada e encerrada, ver
`.planning/debug/resolved/residuo-delta-douglas-danilo.md`).

**Por que isto é obrigatório:** mês fechado é lido do **snapshot congelado**,
não recalculado ao vivo (memória `project_snapshot_congelado_mes_fechado`).
Sem reconsolidar, o ranking, o dashboard e o Relatório de Bonificação
continuariam sem o detalhe por empresa (`desempenho_company_score_snapshots`)
para essas três competências até que algo dispare uma nova gravação —
`desempenho:consolidar-mes --mes=` é o único caminho oficial para mês
fechado (a origem `consolidar_mes` é a única que sobrescreve competência já
congelada, D-122-02).

## 4. Verificação (a única que vale)

Para cada uma das três competências:

```bash
php artisan desempenho:verificar-consolidacao --mes=2026-06
php artisan desempenho:verificar-consolidacao --mes=2026-05
php artisan desempenho:verificar-consolidacao --mes=2026-04
```

**O veredito é o EXIT CODE** (`0` = sem inconsistências, diferente de `0` =
alguma das 5 inconsistências foi encontrada). Para auditoria/registro, usar
`--json` e guardar a saída.

**Ignorar explicitamente a linha final do `consolidar-mes`**
(`OK: N · Falhas: N · Sem carteira: N · Degradados: N · Empresas: N linhas`)
como critério de sucesso — ela não nomeia quem o gate FIXMARG-03 recusou.
Essa disciplina já foi exercitada nesta sessão: em 2026-08-03 o comando
imprimiu `OK: 11 · Falhas: 0 · Degradados: 0` e a conferência que valeu foi
a reconsulta ao banco (122-CONTEXT.md item 5). O
`desempenho:verificar-consolidacao` formaliza essa reconsulta.

## 5. Se o verificador acusar `SEM_SNAPSHOT`

Significa que o gate FIXMARG-03 recusou congelar por cobertura de margem
abaixo de 0,7 (rate-limit da Adman ou amostra degradada). Ação:

1. Consultar o `Log::error` correspondente — traz `user_id`, `cobertura`,
   `base_gate`, `cobertura_pp` e `cobertura_legado`.
2. Re-rodar a competência mais tarde: `php artisan desempenho:consolidar-mes --mes=YYYY-MM`,
   quando o rate-limit da Adman tiver passado.
3. **Nunca inventar row placeholder.** Uma row fabricada esconderia a
   degradação em vez de expô-la (D-122-10, mesma razão pela qual o
   verificador não conserta nada sozinho).

As outras 4 inconsistências que o verificador pode acusar (`SEM_LINHAS`,
`LINHAS_ORFAS`, `DIVERGENCIA_EMPRESAS_SCORE`, `ORIGEM_NAO_CONGELADA`) têm a
ação operacional documentada no docblock de
`app/Console/Commands/VerificarConsolidacaoDesempenho.php` — a mesma fonte
que a saída `--json` referencia implicitamente pelo nome do `tipo`.

## 6. Evidência da troca de grandeza (SNAP-05), sem tocar na Adman

Consulta às tabelas do comparador da Fase 121, que já guardam
`margem_var_pp` por empresa da rodada aprovada — não dispara nenhuma
chamada à Adman:

```sql
SELECT user_id,
       COUNT(*)                                            AS empresas_adman,
       SUM(margem_var_pp IS NOT NULL)                      AS com_pp,
       ROUND(SUM(margem_var_pp IS NOT NULL) / COUNT(*), 4) AS cobertura_pp
  FROM desempenho_comparador_empresas
 WHERE periodo_key = '2026-06'
   AND (fonte_financeira IS NULL OR fonte_financeira <> 'shopee')
 GROUP BY user_id;
```

Anotar o resultado no runbook (nesta seção, numa execução real) após rodar.
É a leitura que diz, **antes de ligar a flag**, quantos profissionais o
gate FIXMARG-03 recusaria se a base virasse pontos percentuais — contexto:
o probe MPP-04 mediu `cobertura_prev = 0.6415`, abaixo do limiar de 0,7.

> Resultado (a preencher na execução do Plano 06):
>
> | user_id | empresas_adman | com_pp | cobertura_pp |
> |---------|-----------------|--------|--------------|
> | (pendente — nenhuma execução em produção ainda) | | | |

## 7. Rollback

- A migration da Fase 122 (Plano 01) é **aditiva** — cria a tabela
  `desempenho_company_score_snapshots` do zero, nenhuma tabela existente
  muda de shape. Reverter é:
  ```bash
  php artisan migrate:rollback --step=1
  ```
  seguido do revert dos commits desta fase.
- As linhas por empresa **não alimentam nenhuma tela nesta fase** — a UI
  que as consumiria é a Fase 123, ainda não construída. Perder essas linhas
  (rollback) não altera nota, ranking nem pagamento de ninguém; a fonte de
  verdade do bônus continua sendo `desempenho_score_snapshots` (modalidade
  mensal), intocada por esta fase.
- Se o rollback acontecer DEPOIS do backfill da seção 3, as três
  competências reconsolidadas continuam com o snapshot mensal agregado
  correto (o `updateOrCreate` de `DesempenhoScoreSnapshot` não muda nesta
  fase) — só o detalhe por empresa some junto com a tabela.

---

Referências: `.planning/phases/122-persist-ncia-por-empresa-e-comandos-v21-0/122-CONTEXT.md`,
`.planning/phases/122-persist-ncia-por-empresa-e-comandos-v21-0/122-05-PLAN.md`,
`app/Console/Commands/ConsolidarMesDesempenho.php`,
`app/Console/Commands/VerificarConsolidacaoDesempenho.php`.

---

## Evidência da execução

Rollout executado em **2026-08-03, 14:20–15:05 BRT** (janela limpa, fora do bloco de crons 11:00–13:30).

### Escopo executado

Apenas **2026-06**. O runbook previa também 2026-05 e 2026-04, mas a conferência mostrou que essas competências **nunca tiveram snapshot mensal** (0 linhas cada) — reconsolidá-las não seria acrescentar detalhe por empresa, e sim criar 22 registros de bônus que nunca existiram, calculados com os dados e regras de hoje. Decisão do usuário em 2026-08-03: **só junho**.

### Resultado por competência

| mês | exit_code | profissionais com snapshot | linhas por empresa | SEM_SNAPSHOT |
|---|---|---|---|---|
| 2026-06 | **0** | 11 | 286 (todas `origem=consolidar_mes`) | nenhum |
| 2026-05 | não executado | — | — | — |
| 2026-04 | não executado | — | — | — |

As 286 linhas serem **todas** de origem `consolidar_mes` prova a trava de congelamento (D-122-02): o `desempenho:warm-cache`, que roda a cada 8 minutos e aquece o último mês fechado, não sobrescreveu a competência congelada.

### Notas de junho — inalteradas pelo rollout

| Profissional | nota | faixa |
|---|---|---|
| Gustavo | 2,05 | sem_bonus |
| Douglas | 3,03 | sem_bonus |
| Felipe | 3,24 | sem_bonus |
| Matheus Estrela | 3,31 | sem_bonus |
| Nathalia Martins | 3,73 | sem_bonus |
| Danilo | 3,89 | sem_bonus |
| Gabriela Aguiar | 4,16 | basico |
| Stefani | 4,28 | basico |
| Luiz Henrique | 4,36 | basico |
| Ana Julia | 4,37 | basico |
| Rubens | 4,91 | intermediario |

Idênticas às de antes do rollout — a fase não mudou nota, faixa nem pagamento, como projetado.

### Cobertura em pontos percentuais (cenário de quando a flag ligar)

| Profissional | cobertura pp | cobertura legado |
|---|---|---|
| Felipe | 0,7500 | 0,7500 |
| Douglas | 0,7586 | 0,8621 |
| Gustavo | 0,7778 | 0,8889 |
| Gabriela Aguiar | 0,8750 | 0,8750 |
| Stefani | 0,9048 | 0,9524 |
| Rubens | 0,9200 | 1,0000 |
| Luiz Henrique | 0,9231 | 0,9231 |
| Danilo | 0,9333 | 1,0000 |
| Ana Julia | 0,9583 | 0,9583 |
| Matheus Estrela | 1,0000 | 1,0000 |
| Nathalia Martins | 1,0000 | 1,0000 |

**Leitura em uma frase: zero dos 11 profissionais ficariam abaixo de 0,7 se o gate FIXMARG-03 passasse a medir em pontos percentuais** — o risco que motivou D-122-04/05 não se concretiza nos dados reais de junho/2026 (a cobertura vai de 0,75 a 1,00).

### Flag

`config('metrics.performance_company_first_score')` conferido em produção após o rollout: **`false`**.

### Dois defeitos encontrados PELO rollout (e corrigidos)

1. **Migration quebrou no MariaDB (erro 1059).** O nome auto-gerado do índice único tinha 75 caracteres, acima do limite de 64. O SQLite dos testes aceita, então passou verde local e só apareceu no deploy — deixando a tabela criada **sem** o índice e a migration como `Pending`. Corrigido nomeando os 3 índices explicitamente (`dcss_*`). A tabela órfã (314 linhas de `warm_cache`, **zero duplicatas** — a lógica do writer se sustentou mesmo sem o índice) foi dropada e recriada pela migration corrigida, provando o fix contra o MariaDB de verdade.

2. **O verificador reprovaria para sempre.** `SEM_SNAPSHOT` era acusado para quem é elegível pelo cargo mas tem carteira vazia — caso do Jhonathan (user 25, 0 empresas), que o `consolidar-mes` pula de propósito. Isso deixaria o exit code em 1 permanentemente, inutilizando como gate o comando criado para ser gate. Corrigido em D-122-12 (checagem por `company_users`, read-only), com teste do caso real.
