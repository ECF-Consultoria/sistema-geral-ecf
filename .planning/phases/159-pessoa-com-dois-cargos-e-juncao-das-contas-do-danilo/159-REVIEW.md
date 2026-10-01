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

## Critical Issues

### CR-01: `/users` dá 500 ao salvar usuário Dev não-admin — o vínculo Dev vem no payload e vira INSERT duplicado

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

**Arquivo:** `app/Http/Controllers/PortfolioController.php:993`
**Problema:** atribuída e nunca lida; só `$cargoLabel` é usado.
**Correção:** remover.

### IN-02: Checagem de driver só reconhece `'mysql'` (migration e censo)

**Arquivo:** `database/migrations/2026_09_30_100000_amplia_unique_user_setores_por_cargo.php:122`; `app/Services/Usuarios/UnificacaoContasService.php:991`
**Problema:** com `DB_CONNECTION=mariadb` (driver `mariadb`, que existe em `config/database.php:67`
e é tratado em `ConsolidarMesDesempenho:351`), o `hasIndex()` e o censo caem no ramo SQLite
(`PRAGMA`/`sqlite_master`) e quebram. O molde de julho usa o mesmo padrão e rodou em produção, então
hoje o driver deve ser `mysql`. É frágil, não quebrado.
**Correção:** `in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)`.

### IN-03: `historico_gestao` rotula qualquer papel ≠ estrategista como "analista" e pode omitir a entrada

**Arquivo:** `app/Services/Usuarios/UnificacaoContasService.php:495-501`
**Problema:**
- Uma linha `role = mentor` vira `papel = analista` no histórico.
- O `$vistos` por `(empresa, papel)` usa só a 1ª operação. Se ela for `delete` (colisão) e outra do
  mesmo par for `update`, a "entrada" do destino não é registrada.
- Também registra slots que não são de Performance (Shopee), o que `CompanyController` não faz.
**Correção:** mapear só `consultor/estrategista`, decidir a entrada por "alguma operação do par é
update" e documentar o escopo.

### IN-04: `json_encode` do backup sem `JSON_THROW_ON_ERROR`

**Arquivo:** `app/Services/Usuarios/UnificacaoContasService.php:1110`, `:1122`, `:1136`
**Problema:** se a codificação falhar, `false` vai para o backup em silêncio e o DELETE segue, sem
backup restaurável. A probabilidade é baixa com utf8mb4, mas é o único ponto em que o backup pode
sair incompleto sem erro.
**Correção:** `json_encode($x, JSON_THROW_ON_ERROR)`.

### IN-05: Falha pós-commit (cache ou activity log) derruba o comando sem imprimir o lote nem rodar a reconsulta

**Arquivo:** `app/Services/Usuarios/UnificacaoContasService.php:349-360`; `app/Console/Commands/UnificarContas.php:94-106`
**Problema:** `bustarCache` (Redis em produção) e `activity()` rodam depois do commit. Uma exceção
ali, que não é `RuntimeException`, sai com exit ≠ 0 sem mostrar o UUID do lote nem o comando de
desfazer.
**Correção:** devolver o lote antes dos efeitos pós-commit e envolver cache e activity em
`try/catch` que só registra no log.

### IN-06: `--json --apply` mistura JSON e texto na mesma saída

**Arquivo:** `app/Console/Commands/UnificarContas.php:76-83`, `:102-106`, `:119-134`
**Problema:** a saída deixa de ser JSON parseável.
**Correção:** com `--json`, emitir um único objeto final `{plano, lote, reconsulta}`.

### IN-07: Desempate de cargo da tela ≠ desempate do motor

**Arquivo:** `app/Support/CargosDesempenho.php:51-52` vs `app/Models/User.php:125`
**Problema:** `CargosDesempenho` ordena por `is_principal DESC, id ASC`, e
`User::cargoDesempenhoSlug()`, que o motor usa via `dimensaoNpsDesempenho()`, ordena só por
`is_principal`. Quando nenhum dos dois cargos de Desempenho é o principal (a principal está em outro
setor), a tela pode mostrar um cargo e o ramo legado usar outro.
**Correção:** registrar em D-09. Não mexer em `User` sem decisão, porque afeta o motor.

### IN-08: `destroyMembro` — a principal pode ir para o setor Desenvolvimento, e `vinculo` inválido cai no caminho legado

**Arquivo:** `app/Http/Controllers/Admin/SetorMembroController.php:130`, `:161-173`
**Problema:**
- A nova principal é a linha de menor id de QUALQUER setor, inclusive a do Dev, que `syncCargoDev`
  declara "nunca principal".
- `?vinculo=abc` vira 0, cai no caminho sem `vinculo` e apaga a linha única.
**Correção:** excluir o setor Dev da escolha da nova principal e devolver 404/422 quando `vinculo`
vier preenchido mas não for inteiro positivo.

### IN-09: `proximoVinculoLivre` sugere um par que o backend vai recusar

**Arquivo:** `resources/js/Pages/Users/Index.jsx:250-263`
**Problema:** se o setor já tem vínculo "sem cargo", a função sugere um 2º vínculo com cargo no mesmo
setor, e a validação nova responde "cada vínculo precisa de um cargo".
**Correção:** pular setores em que algum vínculo existente tem `cargo_id == null`.

---

_Revisado em: 2026-10-01T13:08:42Z_
_Revisor: Claude (gsd-code-reviewer)_
_Profundidade: standard_
