# Desempenho e Bonificação — o que já custou caro descobrir

Contexto durável para quem for mexer em nota de desempenho, ranking, carteira ou bônus.
Escrito em 2026-08-03, ao fim da Fase 122.

**Por que este arquivo existe:** boa parte deste conhecimento vivia só na memória local do Claude Code de uma máquina. Quem abrisse o projeto em outro computador começava sem nada disso e corria o risco de refazer análises caras ou desfazer decisões conscientes sem saber. Se você descobrir algo desta natureza, acrescente aqui — não deixe só na memória da sua sessão.

---

## 1. Regras de cálculo que divergem DE PROPÓSITO

**Faturamento usa MEDIANA. Margem usa MÉDIA.** Não uniformize.

`DesempenhoScoreService::computeVarFaturamento()` agrega por `Collection::median()` desde 31/07/2026; `computeVarMargem()` continua com `avg()`.

Por que a mediana no faturamento: uma empresa com faturamento quase-zero no mês-base fazia a Adman devolver variações de milhares por cento, e a média deixava isso dominar a carteira. Caso real: a empresa "Lojão do Bras" faturou R$ 79,98 em maio e R$ 16.666 em junho → `.diff` de +20.738% → a carteira do Douglas, que **encolheu 2,3% de verdade**, pontuou como +766,25% e ganhou nota máxima em crescimento.

Por que **não** na margem: foi simulado. Mediana na margem levaria o bônus de 6 para 1 em 10 profissionais, porque a distribuição é diferente — a maioria das empresas fica perto de zero e poucas puxam para cima. Detalhe completo em `.planning/todos/pending/margem-regua-decisao-2026-08-03.md`.

**As réguas não serão modificadas.** Decisão explícita do usuário em 2026-08-03. A régua de faturamento dá 5 pontos para qualquer coisa acima de +5%, e a de margem para qualquer coisa acima de +4% — as duas comprimem no topo. É calibragem de política de bônus, pauta de diretoria, não conserto de código.

## 2. A régua de margem é frágil na fronteira

Danilo perdeu o bônus de junho/2026 **sem nenhuma mudança de código**: estava a 0,24 ponto percentual da fronteira de 4% e a releitura da mesma competência fechada, 14 horas depois, deu 2,52% em vez de 4,24%. Margem caiu de 5 para 4 pontos, nota de 4,22 para 3,89, faixa de `basico` para `sem_bonus`.

Gustavo oscilou 7,8 pontos percentuais na mesma competência fechada.

Consequência prática: **qualquer recompute pode mexer em pagamento** de quem estiver perto de uma fronteira. Não recalcule competência fechada sem necessidade, e nunca sem registrar o estado anterior.

## 3. Mês fechado lê snapshot CONGELADO

Ranking, dashboard e Relatório de Bonificação leem `desempenho_score_snapshots`, não o cálculo ao vivo. Corrigir o código **não muda nada** numa competência já fechada — só `desempenho:consolidar-mes --mes=YYYY-MM` regrava.

Desde a Fase 122 existe também `desempenho_company_score_snapshots` (detalhe por empresa), com trava: escrita de `snapshot_diario` ou `warm_cache` **nunca** sobrescreve competência já gravada por `consolidar_mes`. Sem isso o `desempenho:warm-cache`, que roda a cada 8 minutos, reescreveria o mês congelado com leitura nova da Adman.

## 4. Conferência é por reconsulta ao banco, NUNCA por stdout

O gate `FIXMARG-03` recusa congelar quando a cobertura de margem fica abaixo de 0,7, e reporta **apenas uma contagem** na saída — os nomes vão só para `Log::error`. Uma consolidação pode parecer bem-sucedida na tela sem ter gravado o que deveria.

Use `desempenho:verificar-consolidacao --mes=YYYY-MM --json` (read-only, criado na Fase 122). **O veredito é o exit code.**

Armadilha de shell que já enganou nesta casa: `comando | tail -20; echo $?` devolve o exit code do `tail`, não do comando. Capture antes do pipe ou redirecione para arquivo.

## 5. Cache

`DesempenhoScoreService` cacheia por `desempenho.compute.vNN` (hoje `v16`). **Qualquer mudança de cálculo exige bump** — sem ele o dashboard serve a nota velha. O bump quebra strings hardcoded em testes (Phase96/V16/V18/Phase116/Phase110): atualize todas no mesmo commit.

**NUNCA rodar `php artisan cache:clear` no VPS.** Em 30/07/2026 isso derrubou o site inteiro: apaga o cache aquecido da Adman, o dashboard passa a esperar a API, as requisições lentas ocupam os workers do php-fpm e até o login para. Depois de um bump de chave o clear é desnecessário. Se precisar recarregar config: `systemctl reload php8.2-fpm` e reaquecer com `adman:warm-diff`.

Em controllers use `DesempenhoScoreService::computeCached()`; `compute()` puro só em jobs e commands. Sem cache o dashboard levava 70 segundos, 99% esperando HTTP da Adman.

## 6. Armadilhas de banco que o SQLite dos testes não pega

Os testes rodam em SQLite; produção é MariaDB. Estas três já quebraram deploy:

- **Nome de índice acima de 64 caracteres** (erro 1059). Tabela de nome longo precisa de índice nomeado à mão. Pior: a falha não é limpa — cria a tabela, morre no índice, e deixa a migration como `Pending` com a tabela existente e sem o índice.
- **`nullOnDelete` exige coluna `nullable()`** (erro 1830).
- **Dropar índice usado por FK falha** (erro 1553). Adicione o índice novo ANTES de dropar o antigo, e faça a migration idempotente.

Enum em migration precisa de branch SQLite (`string()->change()` sem CHECK) ou os testes quebram.

## 7. Carteira e vínculos

`company_users` tem **várias linhas por (empresa, papel)** — uma por serviço, desde a Fase 76. Quem filtra só por `role` pega a pessoa errada e conta a mesma empresa na carteira de dois profissionais (dupla contagem no bônus). Use `consultorDoServico` / `estrategistaDoServico`, e `distinct` ao contar empresas.

Analista tem taxonomia **dupla**: o cargo vive em `user_setores → cargos.slug = 'analista'`; o papel na pivot `company_users` nunca é `analista` (só `consultor` ou `estrategista`).

Elegível pelo cargo **não** significa ter carteira. Quem tem zero empresas vinculadas não gera row mensal, e isso é correto — não inconsistência.

## 8. Fontes financeiras

Adman e Shopee. `shopee_metrics` tem faturamento e investimento, **não tem margem** — carteira só-Shopee usa placeholder de margem 1.0, que puxa a nota para baixo. A integração Shopee começou em 01/06/2026, então não há baseline antes disso.

O histórico de `adman_metrics` começa por volta de 21/05/2026, e `companies.created_at` é artefato de reimport em massa — filtrar "empresa nova" por data zera todo mundo.

## 9. Estado da milestone v21.0 (nota individual por empresa)

Fases 117 a 123. A 123 (telas e relatórios) é a única pendente.

A flag `metrics.performance_company_first_score` está **`false`** e não deve ser ligada sem os dois gates:
- **ROLL** (delta antigo × novo): aprovado com ressalva, ressalva já resolvida.
- **MPP-04** (estabilidade): continua **reprovado**, faltam 2 rodadas.

Número que precisa estar na mesa antes de qualquer ativação: pelo método atual, 8 de 11 profissionais recebiam bônus na competência 2026-06; **pelo método novo, 1 de 11**. Isso não vem de bug — vem da régua passar a ser aplicada empresa por empresa. Evidência reconsultável com `desempenho:comparar-score-empresa --run=03787204-51a7-49fb-8478-da56a5b07e2a` (0,3s, sem custo de API).

## 10. Árvore de trabalho compartilhada

Várias sessões de Claude Code e mais de um dev editam a **mesma** árvore. Sempre `git commit -- <caminhos>`; **nunca** `git add -A` ou `git add .`.

`deploy.sh` publica exatamente `origin/main` — ou seja, **quem deploya publica o trabalho de todo mundo** que já estiver lá, tenha essa pessoa escolhido o momento ou não. Confira `git log HEAD..origin/main` antes.

`.planning/REQUIREMENTS.md` na raiz parou na v17.0; os IDs das milestones novas só existem em `REQUIREMENTS-vNN.md`, e o `phase.complete` não os alcança — marque os checkboxes à mão ao fechar fase.
