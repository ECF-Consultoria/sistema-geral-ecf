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
