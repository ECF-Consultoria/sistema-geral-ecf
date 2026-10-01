---
phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo
reviewed: 2026-10-01T13:08:42Z
depth: standard
files_reviewed: 21
files_reviewed_list:
  - app/Services/Usuarios/UnificacaoContasService.php
  - app/Console/Commands/UnificarContas.php
  - app/Console/Commands/AuditarRamoLegadoNps.php
  - database/migrations/2026_09_30_100000_amplia_unique_user_setores_por_cargo.php
  - database/migrations/2026_09_30_110000_create_unificacao_contas_backup_table.php
  - app/Http/Controllers/UserController.php
  - app/Http/Controllers/Admin/SetorMembroController.php
  - app/Http/Controllers/Admin/SetorController.php
  - app/Http/Controllers/CompanyController.php
  - app/Http/Controllers/PerformanceController.php
  - app/Http/Controllers/RelatorioBonificacaoController.php
  - app/Http/Controllers/BonusAuditoriaController.php
  - app/Http/Controllers/PortfolioController.php
  - app/Http/Controllers/LiderancaController.php
  - app/Http/Controllers/NotificacaoController.php
  - app/Models/SetorGoal.php
  - app/Support/CargosDesempenho.php
  - resources/js/Layouts/AppLayout.jsx
  - resources/js/lib/visibilidadeMenu.js
  - resources/js/Pages/Admin/Setores/Show.jsx
  - resources/js/Pages/Users/Index.jsx
findings:
  critical: 2
  warning: 12
  info: 9
  total: 23
status: issues_found
fix_status: partial
fixed_at: 2026-10-01T13:42:16Z
fixes:
  CR-01: {status: fixed, commit: 31100713}
  CR-02: {status: fixed, commit: 998fae1d}
  WR-01: {status: fixed, commit: 2bc01002}
  WR-02: {status: fixed, commit: de2aa2d8}
  WR-03: {status: fixed, commit: 75f307a3}
  WR-04: {status: fixed, commit: 36e34ddb}
  WR-05: {status: fixed, commit: 181f6e78, nota: "só a listagem no dry-run; o escopo da etapa continua copiando de qualquer setor (decisão do usuário)"}
  WR-06: {status: not_fixed, motivo: "decisão do usuário / documentação do plano 159-08"}
  WR-07: {status: not_fixed, motivo: "decisão do usuário / documentação do plano 159-08"}
  WR-08: {status: not_fixed, motivo: "decisão do usuário / documentação do plano 159-08"}
  WR-09: {status: fixed, commit: 2d58a6e6}
  WR-10: {status: fixed, commit: ec73737f}
  WR-11: {status: fixed, commit: 6b3b7e47}
  WR-12: {status: fixed, commit: 3658aba7}
  IN-01: {status: not_fixed, motivo: "código morto, não é bug"}
  IN-02: {status: not_fixed, motivo: "frágil, não quebrado; driver de produção é mysql; não testável no SQLite"}
  IN-03: {status: not_fixed, motivo: "regra de histórico — decisão, não bug de 1 linha"}
  IN-04: {status: fixed, commit: 8e0596b6}
  IN-05: {status: not_fixed, motivo: "reorganiza o fluxo pós-commit do comando — não é trivial"}
  IN-06: {status: not_fixed, motivo: "muda o contrato do --json --apply — não é trivial"}
  IN-07: {status: not_fixed, motivo: "toca o desempate do motor (User::cargoDesempenhoSlug) — registrar em D-09, sem decisão"}
  IN-08: {status: not_fixed, motivo: "baixo impacto; mais de 1 linha"}
  IN-09: {status: not_fixed, motivo: "UI sem teste unitário possível dentro do componente"}
---

# Fase 159: Relatório de Code Review

**Revisado em:** 2026-10-01T13:08:42Z
**Profundidade:** standard
**Arquivos revisados:** 21
**Status:** issues_found

## Resumo

**Base do diff.** O `origin/main` andou depois que a branch foi criada: `git diff origin/main` mostra
como "removidos" arquivos que a fase nunca tocou (PolosController, RascunhoAnuncioIaService etc.).
Por isso a revisão usou o merge-base `5d0a3997` (`git diff 5d0a3997 -- <arquivo>`), que isola só os
commits da Fase 159 (`a1941779..a5b196b8`).

**O que está correto (conferido, sem achado):**
- **Migration de `user_setores`.** Cria o unique de 3 colunas ANTES de dropar o de 2 (o `user_id`
  continua prefixo à esquerda, então a FK nunca fica sem índice de apoio e o 1553 não acontece).
  Os nomes têm 36 e 45 caracteres. A idempotência é por `hasIndex()`, sem `try/catch`. O `down()`
  recusa quando há duplicidade e espelha a ordem. A tabela de backup não tem FK, e o nome do índice
  tem 35 caracteres.
- **IDOR em `destroyMembro`.** O `vinculo` é buscado preso a `setor_id` **e** `user_id` da rota,
  devolve 404 quando não casa, e a rota está no grupo `role:admin`. Não há IDOR.
- **Caminho sem `--apply`.** `planejar()` é só leitura, o dry-run sai antes de `aplicar()` e o
  `--desfazer` sem `--apply` só conta. Nenhuma escrita escapa.
- **Linhas `consolidar_mes`.** Nunca são apagadas: há o filtro `origem != consolidar_mes` e a trava
  de bloqueio.
- **Corte do NPS por mês de COLETA.** As duas etapas usam `NpsJanelaResolver::mesDeColeta()`
  (competência + 1), coerente com `NpsPorEmpresaService` (`completed_at` / `competencia_nps` na
  janela M+1).
- **Exit code.** Bloqueio, pendência de censo e reconsulta com sobra devolvem FAILURE.
- **Nota, posição e faixa nos controllers.** Nada mudou para quem tem UM cargo: `cargo_label`, o
  filtro e o universo de usuários de Ranking/Relatório/Auditoria são idênticos ao `origin/main` nesse
  caso.

**O que não está correto:**
1. Um save comum em `/users` de um usuário Dev não-admin passou a dar 500 (regressão real).
2. A junção não confere que as competências **anteriores** ao corte estão consolidadas. Como
   `company_users` não tem dimensão temporal, mover a carteira altera em silêncio toda competência
   fechada que ainda não virou snapshot mensal.

O resto são fragilidades da junção em produção e do rollback (`--desfazer`), vazamento de nota no
`--json` e efeitos colaterais de D-01/D-08 fora do caso "dois cargos no mesmo setor".

## Situação das correções (2026-10-01)

Rodada de correção (gsd-code-fixer): **12 corrigidos** (CR-01, CR-02, WR-01, WR-02, WR-03, WR-04,
WR-05, WR-09, WR-10, WR-11, WR-12 e o informativo IN-04), **11 não corrigidos** (WR-06, WR-07,
WR-08 e os informativos restantes). Um commit atômico por achado, cada um com teste de regressão
que falhava antes da correção. O status de cada achado está na linha **Status** logo abaixo do
título e no frontmatter (`fixes`).

Conferência ao fim da rodada:
- `tests/Feature/Phase159/` inteiro verde: 109 testes, 599 asserções.
- `tests/Feature/Phase119/`: 17 de 29 falham — o mesmo número do baseline (D-12, gate de hash
  pré-existente). `DesempenhoScoreService.php` e `app/Services/Desempenho/` não foram tocados.
- `npm run test:js`: 474 passam e 2 falham (as herdadas: estrutura-grade-glide e polosEntrantes).
- `npm run build`: exit 0.

## Critical Issues

### CR-01: `/users` dá 500 ao salvar usuário Dev não-admin — o vínculo Dev vem no payload e vira INSERT duplicado

**Status:** fixed — `31100713`. `syncVinculos()` descarta do payload a linha do setor Dev,
`validateUser()` a ignora na contagem de setor repetido e o `openEdit()` não a copia mais para o
form. `update()`/`store()` gravam usuário, vínculos e cargo Dev na mesma transação. Teste monta o
payload REAL a partir da listagem (com a linha Dev).

**Arquivo:** `app/Http/Controllers/UserController.php:349-353` e `:391-410`; origem do payload em `resources/js/Pages/Users/Index.jsx:237-241` e `:301`

**Problema:**
- **O que a tela manda.** `UserController::index()` monta `u.setores` com **todas** as linhas de
  `user_setores`, inclusive a do setor `desenvolvimento` + cargo `dev`. O `openEdit()` copia todas
  para `data.vinculos`, e o `submit()` envia `vinculos: data.vinculos` sempre que `is_admin` é falso.
  O setor Dev é removido do dropdown, mas **não** do payload.
- **O que o backend faz agora.** O novo `syncVinculos()` exclui o setor Dev de `$atuais` (linha 351).
  Por isso o vínculo Dev que chega no payload nunca acha `$existente` e cai no `insert()` (linha 401).
  A linha `(user, setor Dev, cargo Dev)` já existe, então o unique novo
  `user_setores_user_id_setor_id_cargo_id_unique` estoura: 1062 no MariaDB, resposta 500.
- **Por que é regressão.** No `origin/main` era `updateOrInsert` por `(user_id, setor_id)`, que só
  atualizava a linha, sem erro.
- **Gravação parcial.** `$user->update($update)` (linha 137) roda ANTES e fora da transação. Nome,
  e-mail, senha, `active` e o `role` derivado dos vínculos novos ficam gravados, mas os vínculos não.
- **Por que os testes não pegaram.** `CargoDevNoUsuarioTest` e
  `UserSetoresDoisCargosTest::test_vinculo_dev_sobrevive_ao_put_com_dois_cargos_do_performance`
  mandam `vinculos` sem a linha Dev, ao contrário do que o front real envia.
- **Quem é afetado.** Todo usuário com `is_dev = 1` e `role <> 'admin'` editado pela tela. Admin não
  é afetado, porque nesse caso o front manda `vinculos: []`.

**Correção:** tirar o setor Dev do array antes de sincronizar (é o que o comentário da linha 341 já
promete):
```php
$setorDevId = Setor::where('slug', User::SETOR_DEV_SLUG)->value('id');
if ($setorDevId) {
    $vinculos = array_values(array_filter(
        $vinculos,
        fn ($v) => (int) $v['setor_id'] !== (int) $setorDevId
    ));
}
// ... só depois normalizar is_principal e abrir a transação
```
E também filtrar no `openEdit()`: `(u.setores || []).filter(s => setoresDisponiveis.some(d => d.id === s.id))`.
Acrescentar um teste que reproduza o payload REAL da tela, com a linha Dev incluída.

### CR-02: A junção não exige que a competência anterior ao corte esteja consolidada — a carteira movida reescreve em silêncio meses "fechados" sem snapshot

**Status:** fixed — `998fae1d`. Bloqueio (exit 1, nada gravado) quando `--a-partir − 1` não tem
snapshot mensal em `desempenho_score_snapshots` para a origem OU o destino que têm carteira; a
mensagem diz a competência, o usuário e manda consolidar e conferir por
`desempenho:verificar-consolidacao --json`. O caso simétrico (`--a-partir` depois da primeira
competência aberta) cai na mesma trava. Quem não tem carteira não precisa de snapshot.

**Arquivo:** `app/Services/Usuarios/UnificacaoContasService.php:190-193` (bloqueios), `:906-932` (`bloqueiosCompetenciaConsolidada`), `:434-481` (etapa `carteira`)

**Problema:**
- **Por que a carteira não respeita o corte.** `company_users` não tem dimensão temporal:
  `CarteiraContextService::forUser()` lê sempre a carteira ATUAL. Toda competência sem snapshot
  mensal é calculada ao vivo (`PerformanceController:220-230`, `RelatorioBonificacaoController:123-125`,
  `BonusAuditoriaController:106`). Logo, o UPDATE de `company_users` 35→15 não respeita
  `--a-partir`: vale para TODA competência não consolidada, inclusive as anteriores ao corte.
- **O que a trava cobre hoje.** Só olha `mes_referencia >= --a-partir`, ou seja, competências que a
  junção **pretende** tocar. Não confere que `--a-partir − 1` (2026-08) e as anteriores estejam
  consolidadas para origem **e** destino.
- **Por que o risco é concreto.**
  - learnings §10.1: `consolidar-mes` falhou para 11 de 12 profissionais por 2 meses e saiu com
    exit 0.
  - A consolidação de 2026-08 rodou ontem, 30/09 às 14:00. Se ela não gravou o snapshot mensal do 15
    ou do 35, rodar a junção hoje muda a nota de 2026-08 dos dois:
    - o 15 passa a carregar as lojas do 35;
    - o 35 cai para `sem_carteira`.
  - A mudança aparece quando o cache de 2026-08 expirar. Esse cache não é derrubado: o
    `bustarCache` só cobre `>= --a-partir`.
- **O caso simétrico.** Com `--a-partir` maior que a primeira competência aberta (ex.: `2026-10`), a
  carteira já vale para 2026-09, mas as atribuições e imputações de NPS de 2026-09 ficam com o 35.
  A nota de 2026-09 do 15 sai com carteira nova e NPS velho.
- **O que fica em jogo.** É exatamente o que D-06 proíbe ("competências já fechadas ficam
  intocadas"), e o dinheiro em jogo é o da competência 2026-08, em pagamento agora.

**Correção:** bloquear quando a competência imediatamente anterior ao corte não está consolidada
para quem tem carteira:
```php
$anterior = $aPartirInicio->copy()->subMonthNoOverflow()->startOfMonth();
foreach ([$deId, $paraId] as $uid) {
    $temCarteira = DB::table('company_users')->where('user_id', $uid)->exists();
    $temMensal = DesempenhoScoreSnapshot::mensal()->where('user_id', $uid)
        ->whereDate('mes_referencia', $anterior->toDateString())->exists();
    if ($temCarteira && ! $temMensal) {
        $bloqueios[] = "competência {$anterior->format('Y-m')} SEM snapshot mensal para o usuário {$uid} — "
            . 'mover a carteira recalcularia esse mês fechado; consolide antes (desempenho:verificar-consolidacao).';
    }
}
// e recusar --a-partir posterior ao mês corrente/à 1ª competência não consolidada
```
Antes do `--apply` em produção, conferir com `desempenho:verificar-consolidacao --mes=2026-08 --json`
e decidir pelo exit code (learnings §4).

## Warnings

### WR-01: Colisão de imputação de GRUPO usa a chave errada — `survey_id IS NULL` casa linha de outro link/empresa e APAGA a da origem

**Status:** fixed — `2bc01002`. A consulta de colisão (`consultaColisaoImputacao()`) compara também
`group_survey_id` (null-safe) e `company_id`.

**Arquivo:** `app/Services/Usuarios/UnificacaoContasService.php:679-693`

**Problema:**
- **Como a linha de grupo é identificada.** Desde a migration `2026_08_26_150000`, a linha imputada
  de link de GRUPO tem `survey_id = NULL`, e o grão real é `(group_survey_id, company_id)` (ver
  `NpsImputedAssignment::chaveDeDedupe()`).
- **O que o código compara.** A checagem de colisão compara só `dimensao`, `role`, `survey_id`
  (null-safe) e `servico_id`. Para uma linha de grupo, qualquer linha de grupo do destino com o mesmo
  papel e serviço, mesmo de OUTRO link ou de OUTRA empresa, conta como "colisão".
- **O efeito.** A linha da origem vira `delete` em vez de `update`, e o piso 1 daquela empresa
  desaparece da carteira do 15.
- **Quando isso morde.**
  - O aviso do comando manda rodar de novo "antes do consolidar-mes de 31/10 às 14:00". Rodado no
    último dia depois das 09:30, nenhuma materialização roda antes do congelamento para recriar a
    linha do 15.
  - A reconsulta não vê a perda: não sobra operação pendente, então o exit code é 0.

**Correção:** comparar pelo grão de verdade:
```php
->when($linha->survey_id !== null,
    fn ($q) => $q->where('survey_id', $linha->survey_id),
    fn ($q) => $q->whereNull('survey_id')
                 ->where('group_survey_id', $linha->group_survey_id)
                 ->where('company_id', $linha->company_id))
```
E acrescentar um teste com duas linhas de grupo de links diferentes.

### WR-02: O plano é calculado FORA da transação e aplicado às cegas (TOCTOU)

**Status:** fixed — `de2aa2d8`. Dentro da transação, cada update/delete relê a linha
(`lockForUpdate`) e confere o estado planejado: colunas de `antes`, dono da linha, snapshot ainda
cache, colisão ainda existente (delete) ou ainda inexistente (update). A escrita é condicionada a
esse estado e exige exatamente 1 linha afetada. Qualquer divergência causa rollback do lote e exit 1.

**Arquivo:** `app/Services/Usuarios/UnificacaoContasService.php:336-347` e `:1106-1127`; `app/Console/Commands/UnificarContas.php:73-95`

**Problema:**
- **Onde está o intervalo.** `planejar()` roda antes de imprimir o relatório. `aplicar()` executa a
  lista congelada sem reler nada:
  - o UPDATE é `where('id', …)` sem `where('user_id', $deId)`;
  - o DELETE por colisão foi decidido no plano.
- **Quem pode escrever no meio.** Entre os dois momentos rodam o `desempenho:warm-cache` (a cada
  8 minutos), `CompanyController::update` (`limparSlotPerformance` apaga e regrava o slot) e outros.
- **Casos concretos.**
  - A linha do destino que motivou a "colisão" some no intervalo: a linha da origem é apagada mesmo
    assim e a empresa fica sem aquele responsável.
  - Uma linha de `company_users` ou de PPA reatribuída a um terceiro no intervalo é sobrescrita para
    o 15.
- **Por que nada avisa.** Nenhuma operação confere linhas afetadas.

**Correção:** dentro da transação, reler cada linha com `lockForUpdate()` e conferir o estado
esperado. Exemplo para o UPDATE:
```php
$n = DB::table($t)->where('id', $id)->where($antesCol, $antesVal)->update($depois);
if ($n !== 1) throw new \RuntimeException("linha {$t}#{$id} mudou desde o plano — replaneje");
```
Mesma coisa no DELETE (exigir 1 linha afetada). Na colisão, confirmar dentro da transação que a
linha do destino ainda existe.

### WR-03: `--desfazer` restaura sem conferir o estado atual — sobrescreve mudanças posteriores e reporta sucesso sem ter restaurado

**Status:** fixed — `75f307a3`. Só restaura linha no estado que o `--apply` deixou. Cada escrita
exige 1 linha afetada, e qualquer divergência derruba a transação inteira, listando as linhas. Lote
com lote POSTERIOR vivo do mesmo par é recusado. O dry-run já lista as divergências, o comando
imprime "R de N restaurada(s)" e sai ≠ 0 se R ≠ N.

**Arquivo:** `app/Services/Usuarios/UnificacaoContasService.php:398-412`

**Problema:**
- **Como restaura.** O `update` faz `where('id', linha_id)->update($antes)` sem checar que a coluna
  ainda vale `depois`, e o `insert` é desfeito com DELETE por id, sem contar linhas.
- **Sobrescrita.** Se depois da junção alguém reatribuiu um PPA a um terceiro, o `--desfazer`
  devolve o PPA ao 35, que está inativo.
- **Restauração fantasma.** Se a linha de `company_users` foi apagada e recriada (o
  `limparSlotPerformance` faz isso em qualquer edição da empresa), o UPDATE afeta 0 linhas e a
  carteira não volta. Mesmo assim o comando imprime "N operação(ões) restaurada(s)".
- **Lotes fora de ordem.** Também não detecta que existe um lote POSTERIOR para o mesmo par (o 2º
  `--apply` de NPS). Desfazer o lote 1 com o lote 2 vivo deixa a carteira com o 35 e o NPS com o 15.

**Correção:**
- UPDATE condicionado a `depois`, exigindo exatamente 1 linha afetada; senão `throw`, o que derruba a
  transação.
- DELETE do insert exigindo 1 linha afetada.
- Recusar o lote quando existir lote mais novo, não desfeito, para o mesmo `(de_user_id, para_user_id)`.

### WR-04: `--json` imprime nota e faixa de bônus — viola learnings §11 e o próprio docblock

**Status:** fixed — `36e34ddb`. O `--json` sai por `planoParaSaida()`: `tabela/acao/linha_id` + as
colunas tocadas e, no delete, uma `identificacao` por whitelist. O backup no banco continua com a
linha inteira. O teste usa valores sentinela e confere `--json`, texto e `--json --apply`.

**Arquivo:** `app/Console/Commands/UnificarContas.php:76-80`; conteúdo vindo de `UnificacaoContasService.php:740-745` e `:776-783`

**Problema:**
- **O que vaza.** As operações `delete` das etapas `snapshots_diarios`/`snapshots_empresa` carregam
  `antes = (array) $linha`, a linha inteira. Em `desempenho_score_snapshots` isso inclui `score`,
  `classificacao` e `breakdown_json` (`nota_final`, `faixa_bonus`). Em
  `desempenho_company_score_snapshots`, inclui `nota_empresa` e os pontos.
- **O que o `--json` faz.** Despeja o `$plano` inteiro.
- **O que o docblock promete.** "NUNCA imprime nota, faixa ou valor de bônus".
- **Por que importa.** O plano 159-07 deve rodar isso em produção e é natural colar a saída num
  VERIFICATION. É o incidente do §11: nome pareado com faixa no histórico do git.

**Correção:** no `--json`, serializar as operações só com `tabela/acao/linha_id` (e as colunas
tocadas nos `update`). Nunca o `antes` completo de tabelas de snapshot. O backup no banco continua
com a linha inteira.

### WR-05: A etapa `cargos` copia TODO cargo da origem, de QUALQUER setor, e o dry-run em texto não mostra quais

**Status:** fixed (parcial) — `181f6e78`. O dry-run em texto lista cada par que o destino ganha
(`+ setor <nome> (id N) / cargo <nome> (id M)`). O escopo da etapa NÃO mudou: ela continua copiando
de qualquer setor. Restringir aos cargos de Desempenho ou exigir `--cargos=` é decisão do usuário.

**Arquivo:** `app/Services/Usuarios/UnificacaoContasService.php:549-594`; `app/Console/Commands/UnificarContas.php:213-216`

**Problema:**
- **Escopo maior que D-06.** D-06 pede "dar ao user 15 o cargo estrategista no Performance". O
  código insere no destino todo `(setor_id, cargo_id)` da origem: Shopee, Polos, Comercial, até o
  cargo Dev, sem o espelho `users.is_dev`. Cargo em setor concede as permissões do setor, então isso
  é ampliação de acesso.
- **O dry-run esconde.** O relatório só imprime `linha_id`, que é `null` em `insert` e é filtrado
  pelo `array_filter`. O operador vê "2× insert" sem saber quais setores e cargos o 15 vai ganhar.

**Correção:**
- Restringir a etapa aos cargos de Desempenho (`CargosDesempenho::SLUGS`), ou exigir
  `--cargos=setor:cargo` explícito.
- No relatório, imprimir `setor/cargo` de cada insert, e também `company_id/papel` da etapa
  `historico_gestao`.

### WR-06: As respostas de NPS de 2026-09 que chegarem antes do 1º `--apply` dependem de um 2º `--apply` MANUAL, sem nenhuma trava

**Status:** not_fixed. É decisão do usuário: trava no `consolidar-mes`/`verificar-consolidacao` ou
reexecução agendada. O plano 159-08 documenta. Com o WR-03, o 2º `--apply` gera um lote posterior,
e o `--desfazer` passa a exigir a ordem do mais novo para o mais antigo.

**Arquivo:** `app/Services/Usuarios/UnificacaoContasService.php:245-254`

**Problema:**
- **O que acontece no meio-tempo.** A coleta de 2026-09 abriu em 01/10. Toda atribuição gravada
  antes do 1º `--apply` nasce com o 35. Depois dele, o 35 está inativo, e
  `ConsolidarMesDesempenho` só consolida `active = true`.
- **O efeito se ninguém reaplicar.** Se ninguém rodar o comando de novo até 31/10 às 14:00, essas
  notas somem da competência 2026-09 do 15: o papel de estrategista naquelas lojas cai para
  imputação ou `sem_nps`. A nota de bônus muda.
- **Única proteção.** Um aviso de texto que se repete em todo dry-run.

**Correção:** uma trava objetiva. Por exemplo, `desempenho:consolidar-mes` (ou
`desempenho:verificar-consolidacao`) falhar quando existir
`nps_score_assignments`/`nps_imputed_assignments` na janela de coleta da competência com `user_id` de
usuário inativo que tenha lote de `unificacao_contas_backup` como origem. Ou agendar a reexecução do
comando no dia 30 do mês de coleta.

### WR-07: Desativar a origem tira o 35 das telas de competências JÁ FECHADAS (Ranking, Relatório de Bonificação, Auditoria)

**Status:** not_fixed. É decisão do usuário: mudar o universo das telas de pagamento, ou exportar o
relatório de 2026-08 antes do `--apply` como passo obrigatório. O plano 159-08 documenta.

**Arquivo:** `app/Services/Usuarios/UnificacaoContasService.php:872-891`; consumidores em `RelatorioBonificacaoController.php:95`, `BonusAuditoriaController.php:59`, `PerformanceController.php:78`

**Problema:**
- **O que D-06 garante e o que acontece.** D-06 justifica "desativar sem apagar" com "o histórico
  dos meses fechados continua no nome dele". No banco continua. Mas as três telas usadas para conferir
  e pagar bônus filtram `User::where('active', true)` ANTES de ler o snapshot mensal.
- **O efeito.** Assim que a junção rodar, o Relatório de Bonificação de 2026-08 (e de 06/07) deixa de
  listar o 35, e com ele qualquer linha de bônus que ele tenha nessas competências. A tela de
  pagamento muda sem nenhuma mudança de nota.

**Correção:**
- Nessas três telas, incluir usuário inativo que tenha snapshot mensal da competência pedida, por
  exemplo `whereIn('id', $snapshots->keys())` OU `active`.
- Ou, no mínimo, exportar o relatório de 2026-08 antes do `--apply` e registrar isso como passo
  obrigatório do 159-07.

### WR-08: Depois da junção, reconsolidar ou invalidar empresa numa competência anterior ao corte recalcula o 15 com a carteira unificada

**Status:** not_fixed. É decisão do usuário, documentada no plano 159-08 (learning "junção de
contas" e mudança em `bustarCacheDaEmpresa`).

**Arquivo:** consequência de `UnificacaoContasService.php:434-481`; gatilho em `BonusAuditoriaController.php:241-261`

**Problema:**
- **A invalidação.** `bustarCacheDaEmpresa()` resolve os profissionais da empresa por
  `company_users` ATUAL. Invalidar uma ex-loja do 35 em 2026-08 apaga o snapshot mensal de 2026-08 do
  **15**, não o do 35. O recálculo, por `consolidar-mes --mes=2026-08` ou ao vivo, sai com a carteira
  unificada: lojas do 35 entram no mês fechado do 15.
- **O lado do 35.** O snapshot dele continua contando a loja invalidada, e ele nem é reconsolidado,
  porque está inativo.
- **Cobertura.** Nenhum teste nem learning registra isso.

**Correção:**
- Registrar em `.planning/learnings/desempenho-bonificacao.md` (novo § "junção de contas").
- Mudar `bustarCacheDaEmpresa` para resolver os usuários pelos snapshots daquela competência
  (`desempenho_company_score_snapshots.user_id where company_id = X and mes_referencia = M`), não pela
  pivot atual.

### WR-09: `AuditarRamoLegadoNps` aceita competência com a janela de coleta ainda aberta e devolve "sem exposição" com exit 0

**Status:** fixed — `2d58a6e6`. Por competência, `NpsJanelaResolver::fechada(mesDeColeta)` decide.
Com a janela aberta, o resultado marca `inconclusivo` e `janela_coleta_aberta_ate`, o texto mostra
"inconclusivo (janela de coleta aberta até AAAA-MM-DD)" e o exit é 2, nunca 0. Quando há exposição
encontrada, o exit 1 prevalece: resposta que já caiu no ramo legado é exposição real.

**Arquivo:** `app/Console/Commands/AuditarRamoLegadoNps.php:142-146`

**Problema:**
- **O que a guarda recusa.** Só o mês FINANCEIRO corrente.
- **O caso 2026-09.** É exatamente a competência que D-09 manda medir. Rodado em outubro, passa pela
  guarda, mas a coleta (outubro) mal começou: `por_ramo['legado']` sai 0 por falta de resposta, não
  por ausência de exposição.
- **O resultado.** O veredito "sem exposição do ramo legado" com SUCCESS é exatamente o gatilho de D-09
  para "documentar e não tocar o motor". É uma decisão de motor de bônus tomada sobre medição
  prematura.

**Correção:** recusar, ou rebaixar para "inconclusivo" com FAILURE, quando
`! app(NpsJanelaResolver::class)->fechada($janela->mesDeColeta($mes))`. A mensagem deve dizer em
que data a medição passa a valer.

### WR-10: D-08 muda a visibilidade para quem tem cargos em SETORES diferentes — publicador com qualquer outro cargo passa a ver "Alertas Estratégicos"

**Status:** fixed — `ec73737f`. Volta a regra "o item some se o papel do sistema OU QUALQUER cargo
estiver em `excludeRoles`", com uma única exceção: entre `analista` e `estrategista`, o cargo com
acesso compensa o outro, que está excluído. A exceção olha o slug, então vale também se os dois
cargos de Desempenho estiverem em setores diferentes (Performance e Shopee). Isso precisa ser
reportado ao usuário.

**Arquivo:** `resources/js/lib/visibilidadeMenu.js:22-44`; `resources/js/Layouts/AppLayout.jsx:470-472`, `:502`; rota em `routes/web.php:1406`

**Problema:**
- **O que o código afirma.** `pubCargos` traz os cargos de TODOS os setores (`auth.setores`), inclusive
  slugs fora de `CARGO_SHORT` (`estrategista`, cargos de Polos/Shopee/Comercial etc.). A regra nova
  só esconde quando TODOS os cargos estão excluídos. O docblock diz "não muda nada para quem tem um
  cargo só", mas a mudança vale para qualquer pessoa com vínculo em mais de um setor, que já existia
  antes da fase.
- **O caso concreto.** O publicador tem `role = consultor`. A rota `alertas.*` aceita
  `admin,consultor,mentor`, e o `excludeRoles` do menu era o ÚNICO gate (ver comentário em
  `AppLayout.jsx:458-463`). Com qualquer cargo adicional fora da lista, o publicador volta a ver e
  abrir "Alertas Estratégicos": o vazamento que esse código existia para fechar.

**Correção:**
- Considerar só os cargos que o `excludeRoles` conhece. Ignorar slugs que não estão no universo de
  papéis da `NAV_TREE` (`publicador, analista, gestor, lider, estrategista`) ao decidir "algum cargo
  tem acesso".
- Reportar ao usuário que a regra vale também para cargos em setores diferentes.

### WR-11: `storeMembro` converte a linha "sem cargo" e ZERA o `is_principal` quando ela era a principal

**Status:** fixed — `6b3b7e47`. A conversão grava `$isPrincipal || $linhaSemCargo->is_principal`.

**Arquivo:** `app/Http/Controllers/Admin/SetorMembroController.php:70-93`

**Problema:**
- **O cálculo.** `$jaTemPrincipal` conta a própria `$linhaSemCargo`. Se ela é a principal e o admin
  não marca o checkbox, `$isPrincipal = false`.
- **O efeito.** O `update` grava `is_principal = false` na única linha principal da pessoa, que fica
  sem nenhuma principal.
- **Por que é regressão.** O caminho de conversão é novo: antes, `storeMembro` nunca tocava linha
  existente.

**Correção:**
```php
'is_principal' => $isPrincipal || (bool) $linhaSemCargo->is_principal,
```
(ou excluir `$linhaSemCargo` do cálculo de `$jaTemPrincipal`).

### WR-12: D-01 libera dois cargos em QUALQUER setor, mas leitores "um cargo por setor" sem `ORDER BY` continuam ativos

**Status:** fixed — `3658aba7`. Três leitores passam a ordenar por
`user_setores.is_principal DESC, id ASC`:
- `PerformanceController::indexPolos` (`cargo_slug`);
- `PerformanceController::metaParaMes`;
- `MlbController::metaParaMes`. Este está fora da lista de arquivos da fase, mas é o fallback
  "canônico" que o do Performance espelha.

Não foi encontrado outro leitor sem ordem nos arquivos da fase. `User::cargoDesempenhoSlug()` é o
IN-07 e não foi tocado.

**Arquivo:** `app/Http/Controllers/PerformanceController.php:1312-1317` (`->value('cargos.meta_publicacoes')`) e `:1166-1173` (`cargo_slug` com `limit(1)`); idem `MlbController.php:254-259`

**Problema:**
- **O que a tela permite.** A validação e a UI de `/users` e de `/admin/setores` passaram a aceitar
  dois cargos em qualquer setor, não só Performance. Ex.: Publicador + Líder de Publicação.
- **Quem quebra.** Os leitores acima resolvem "o cargo da pessoa no setor Publicação" com
  `value()`/`limit(1)` sem ordenação. No MariaDB, a meta de publicações de fallback e o rótulo de
  papel ficam não determinísticos entre requisições.
- **Área de risco.** Metas de Publicação estão na lista "GSD obrigatório" do CLAUDE.md.

**Correção:**
- Ou restringir D-01 aos setores em que o dois-cargos foi decidido: validar no `UserController` e no
  `SetorMembroController` que a repetição de setor só é aceita para cargos de
  `CargosDesempenho::SLUGS`.
- Ou acrescentar `orderByDesc('user_setores.is_principal')->orderBy('user_setores.id')` nesses
  leitores.

## Info

### IN-01: Variável `$cargoSlug` sem uso em `renderCarteiraProfissional`

**Status:** not_fixed. É código morto, não bug, e fica sem efeito.

**Arquivo:** `app/Http/Controllers/PortfolioController.php:993`
**Problema:** atribuída e nunca lida; só `$cargoLabel` é usado.
**Correção:** remover.

### IN-02: Checagem de driver só reconhece `'mysql'` (migration e censo)

**Status:** not_fixed. É frágil, não quebrado: o driver de produção é `mysql`. O SQLite dos testes
também não permite um teste de regressão.

**Arquivo:** `database/migrations/2026_09_30_100000_amplia_unique_user_setores_por_cargo.php:122`; `app/Services/Usuarios/UnificacaoContasService.php:991`
**Problema:** com `DB_CONNECTION=mariadb` (driver `mariadb`, que existe em `config/database.php:67`
e é tratado em `ConsolidarMesDesempenho:351`), o `hasIndex()` e o censo caem no ramo SQLite
(`PRAGMA`/`sqlite_master`) e quebram. O molde de julho usa o mesmo padrão e rodou em produção, então
hoje o driver deve ser `mysql`. É frágil, não quebrado.
**Correção:** `in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)`.

### IN-03: `historico_gestao` rotula qualquer papel ≠ estrategista como "analista" e pode omitir a entrada

**Status:** not_fixed. Mexe na regra do histórico (escopo e papéis), então é decisão, não bug de 1
linha.

**Arquivo:** `app/Services/Usuarios/UnificacaoContasService.php:495-501`
**Problema:**
- Uma linha `role = mentor` vira `papel = analista` no histórico.
- O `$vistos` por `(empresa, papel)` usa só a 1ª operação. Se ela for `delete` (colisão) e outra do
  mesmo par for `update`, a "entrada" do destino não é registrada.
- Também registra slots que não são de Performance (Shopee), o que `CompanyController` não faz.
**Correção:** mapear só `consultor/estrategista`, decidir a entrada por "alguma operação do par é
update" e documentar o escopo.

### IN-04: `json_encode` do backup sem `JSON_THROW_ON_ERROR`

**Status:** fixed — `8e0596b6`. Os três `json_encode` do backup usam `JSON_THROW_ON_ERROR`, e a
falha derruba a transação do `--apply`. O teste grava bytes que não são UTF-8 válido numa linha de
cache apagada.

**Arquivo:** `app/Services/Usuarios/UnificacaoContasService.php:1110`, `:1122`, `:1136`
**Problema:** se a codificação falhar, `false` vai para o backup em silêncio e o DELETE segue, sem
backup restaurável. A probabilidade é baixa com utf8mb4, mas é o único ponto em que o backup pode
sair incompleto sem erro.
**Correção:** `json_encode($x, JSON_THROW_ON_ERROR)`.

### IN-05: Falha pós-commit (cache ou activity log) derruba o comando sem imprimir o lote nem rodar a reconsulta

**Status:** not_fixed. Reorganiza o fluxo pós-commit do comando, então não é trivial.

**Arquivo:** `app/Services/Usuarios/UnificacaoContasService.php:349-360`; `app/Console/Commands/UnificarContas.php:94-106`
**Problema:** `bustarCache` (Redis em produção) e `activity()` rodam depois do commit. Uma exceção
ali, que não é `RuntimeException`, sai com exit ≠ 0 sem mostrar o UUID do lote nem o comando de
desfazer.
**Correção:** devolver o lote antes dos efeitos pós-commit e envolver cache e activity em
`try/catch` que só registra no log.

### IN-06: `--json --apply` mistura JSON e texto na mesma saída

**Status:** not_fixed. Muda o contrato do `--json --apply`, então não é trivial. Depois do WR-04, a
parte JSON já não carrega nota nem faixa.

**Arquivo:** `app/Console/Commands/UnificarContas.php:76-83`, `:102-106`, `:119-134`
**Problema:** a saída deixa de ser JSON parseável.
**Correção:** com `--json`, emitir um único objeto final `{plano, lote, reconsulta}`.

### IN-07: Desempate de cargo da tela ≠ desempate do motor

**Status:** not_fixed. Toca `User::cargoDesempenhoSlug()`, que alimenta o motor. Fica registrado em
D-09 e não muda sem decisão.

**Arquivo:** `app/Support/CargosDesempenho.php:51-52` vs `app/Models/User.php:125`
**Problema:** `CargosDesempenho` ordena por `is_principal DESC, id ASC`, e
`User::cargoDesempenhoSlug()`, que o motor usa via `dimensaoNpsDesempenho()`, ordena só por
`is_principal`. Quando nenhum dos dois cargos de Desempenho é o principal (a principal está em outro
setor), a tela pode mostrar um cargo e o ramo legado usar outro.
**Correção:** registrar em D-09. Não mexer em `User` sem decisão, porque afeta o motor.

### IN-08: `destroyMembro` — a principal pode ir para o setor Desenvolvimento, e `vinculo` inválido cai no caminho legado

**Status:** not_fixed. O impacto é baixo: um `vinculo` inválido cai no caminho legado, que só apaga
quando há exatamente uma linha. A correção passa de 1 linha.

**Arquivo:** `app/Http/Controllers/Admin/SetorMembroController.php:130`, `:161-173`
**Problema:**
- A nova principal é a linha de menor id de QUALQUER setor, inclusive a do Dev, que `syncCargoDev`
  declara "nunca principal".
- `?vinculo=abc` vira 0, cai no caminho sem `vinculo` e apaga a linha única.
**Correção:** excluir o setor Dev da escolha da nova principal e devolver 404/422 quando `vinculo`
vier preenchido mas não for inteiro positivo.

### IN-09: `proximoVinculoLivre` sugere um par que o backend vai recusar

**Status:** not_fixed. É UI dentro do componente, sem teste unitário possível. O backend já recusa
com mensagem clara.

**Arquivo:** `resources/js/Pages/Users/Index.jsx:250-263`
**Problema:** se o setor já tem vínculo "sem cargo", a função sugere um 2º vínculo com cargo no mesmo
setor, e a validação nova responde "cada vínculo precisa de um cargo".
**Correção:** pular setores em que algum vínculo existente tem `cargo_id == null`.

---

_Revisado em: 2026-10-01T13:08:42Z_
_Revisor: Claude (gsd-code-reviewer)_
_Profundidade: standard_
_Correções: 2026-10-01T13:42:16Z — Claude (gsd-code-fixer), iteração 1_
