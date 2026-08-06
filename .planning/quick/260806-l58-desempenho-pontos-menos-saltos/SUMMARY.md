---
id: 260806-l58
slug: desempenho-pontos-menos-saltos
status: complete
date: 2026-08-06
commits: [995aba6e, 07aeeadc, ca3db475, 34a59b16]
---

# Quick 260806-l58 — Desempenho: pontos como unidade principal, menos saltos de tela

## Natureza

**100% apresentação.** Nenhum arquivo em `app/Services/`, `app/Console/` ou
`database/migrations/` no diff. `desempenho.compute.v17` intocado, nenhuma
consolidação rodada, nenhuma competência recalculada, flag
`metrics.performance_company_first_score` continua `false`.

## O que mudou

### `/performance/{id}` — cards em PONTOS

Os cards exibiam a variação em % como valor principal enquanto a nota já vinha
dos pontos, sem que a ligação entre os dois aparecesse em lugar nenhum.
Invertido: ponto grande (2 casas, `4,18`), % / p.p. na linha de apoio. É a mesma
inversão que o ranking recebeu em `PontosToneCell` em 2026-08-05.

**A armadilha do NPS era real.** O card lia `componentes.nps_medio`, que **não é**
o número que entra na conta — quem compõe a nota é `pontos_componentes.nps`
(média dos PONTOS por loja). O card não fechava com a conta
`(nps+fat+margem)/n = nota` exibida no card de bônus ao lado. Corrigido e travado
por teste.

**Card "Absenteísmo" removido.** Exibia `—` com selo "Em breve" desde a criação
da tela, sem fonte de dados definida — ocupava 1/4 da grade para não informar
nada. Grade 4 → 3 colunas.

### `/admin/users/{id}/portfolio` — ponto do mês como âncora

KPI "Margem média (percentageMargin)" saiu; entrou o **ponto do mês** (nota 0–5,
faixa de bônus e a conta, no formato do ranking). A margem média é *um insumo* da
nota, e exibi-la sozinha ali obrigava a abrir uma terceira tela para saber o que
ela virou.

A tabela "Empresas em carteira" foi mantida e ganhou coluna **NPS**, pontos sob
faturamento, pontos sob margem e coluna **Nota** — sempre em texto pequeno abaixo
do número operacional, nunca como valor principal.

### `/performance` — o drawer estava morto

`onSelectUser` era passado ao `RankingConsultoria` e **nunca chamado lá dentro**:
a linha inteira navegava para `performance.show`. O `EvolucaoDrawer` existia, com
fetch e gráfico, e **nada o abria** — ficou órfão no ajuste de 2026-07-13 que
tornou a linha clicável.

Religado: a linha abre o drawer, o chevron virou a saída explícita para a tela
cheia. O drawer ganhou o bloco **"Por que essa nota"** (pontos por indicador, a
conta e a faixa), a **custo zero de requisição** — `pontos_componentes` e
`componentes` já vêm na linha do ranking. Era isso que obrigava a abrir a tela
cheia; agora o "por quê" resolve sobre a própria tela, e a navegação fica só para
a tabela por empresa, que o drawer não tem como mostrar.

Armadilha local: `formatContaNota()` do `Index.jsx` devolve a **sentinela
`'/ 5,00'`** quando não há ponto algum, nunca `null` — sem guarda o drawer
renderizaria `"/ 5,00 = —"`. (É outra função, de mesmo nome e assinatura
diferente, da que vive em `desempenhoLabels.js`.)

### Menos repetição

`PeriodoBanner` novo: o parágrafo de contexto de período ocupava 4 linhas no topo
da `Show` e da `AdminCarteira` (a `Index` **já** era compacta — foi de lá que o
padrão saiu). Virou uma linha, com a explicação completa no `title`.

Os 4 tiles de metadados **mais** o card "Info carteira" viraram uma linha com
tooltip: eram dois blocos sobre o mesmo assunto, e o ranking já põe esses números
em tooltip.

Cada tela passou a apontar para o que a outra tem de exclusivo, em vez de para
"mais detalhes" genérico: da nota vai-se para *"Ver operação da carteira
(faturamento, ADS, serviços)"*; da carteira volta-se para *"Ver como a nota foi
formada"*.

## Decisões

**A precedência de leitura da nota não podia ser `computeCached()` direto.**
`PerformanceController::show()` tenta o snapshot mensal **congelado** antes de
calcular. O portfólio espelha isso. Ler ao vivo faria as duas telas mostrarem
números diferentes para a mesma competência fechada — e §2 do learning registra
0,24 p.p. de oscilação entre duas leituras da mesma competência tirando o bônus
de alguém. Efeito colateral bom: mês fechado nem chega a computar. `compute()`
puro nunca entra em controller (§5).

**Sem exposição nova de dado de bônus.** Os guards das duas telas são idênticos
(`isAdmin() || self || líder do setor do usuário`) — verificado antes de levar
nota e faixa para o portfólio.

**Detalhe por empresa nunca é calculado ao vivo.** Só é lido quando
`periodo.is_closed`, e `tem_detalhe_empresas` deriva da **existência de linhas**,
nunca de `is_closed` isolado (mês fechado sem consolidação, ou anterior à Fase
122, não tem linha gravada). Em mês em curso a tela mostra o aviso compartilhado.
Acionar cálculo por empresa aqui reabriria o fan-out de HTTP que já produziu
página de 70 segundos (§5).

**Margem: o secundário do card virou pontos percentuais** (`var_margem_pp`), não
mais a variação relativa (`var_margem_pct`). São grandezas diferentes; mudança
confirmada pelo usuário no checkpoint. `MARGEM_CARD_SUBLABEL` foi reescrito
porque descrevia a variação relativa, que deixou de estar no card.

## Um gate estrutural foi reescrito, não apagado

`estrutura-performance-show.test.js` exigia `formatPercent(c.var_margem_pct)` no
card. Ele codificava a D-04 tal como escrita na Fase 123, cujo raciocínio era
explícito no plano: *"quem produz a `nota_final` exibida ao lado é o número
relativo"*.

**Isso deixou de valer em 2026-08-05**, quando a nota passou a ser a média dos
PONTOS por indicador (`computeNotaFinalPorIndicador()`, learning §0). Desde
então o número relativo não produz mais a nota, e mantê-lo como destaque é que
colocava dois números que não se explicam lado a lado — exatamente o que a D-04
proíbe. Mostrar o ponto **restaura** a intenção da D-04.

O invariante real sobrevive e agora tem dois testes: o destaque tem de ser o que
produz a nota, e **nunca** pode ser derivado das linhas por empresa (a flag
`performance_company_first_score` segue `false`). Mais um teste trava a armadilha
do NPS e outro garante que o Absenteísmo não voltou.

## Gates

- `npm run test:js` — **136 passa / 1 falha**, contra baseline **133/1** medido
  com as mudanças em `git stash`. A falha é a mesma nos dois lados
  (`Características secundárias nasce recolhido`, do módulo Anunciar em massa):
  **zero regressão**, e os 3 testes novos entraram verdes.
- `php artisan test tests/Feature/Phase123 tests/Feature/Portfolio
  tests/Feature/PortfolioShopeeCarteiraTest.php` — **74/74**, incluindo as que
  exercitam o `renderCarteiraProfissional` alterado.
- `npm run build` verde.
- Checkpoint visual aprovado pelo usuário nas duas telas, competência 2026-06.

## Checkpoint local — como o banco foi preparado

O localhost tinha `company_users` **zerada**, então a carteira vinha vazia e o
score saía como "sem carteira", apesar dos 286 registros de detalhe por empresa
já presentes. Os pares (profissional, empresa) estavam justamente nesses
registros, então a pivot foi **reconstruída a partir do que já existia no banco
local** — zero movimentação de dado de produção.

O script vive **fora do repositório** (scratchpad da sessão), de propósito: um
comando capaz de fabricar vínculo de carteira não pode existir no repo, porque
carteira decide bônus. As linhas criadas são marcadas com
`assigned_at = 2001-01-01` e o próprio script as remove com `$REVERTER = true`.

Contadores do checkpoint (dado que a tela exibe, §11): 11 profissionais com
carteira, de 15 a 34 empresas cada, 286 vínculos no total.

**`adman_metrics` e `shopee_metrics` continuam zeradas no local**, então as
colunas *operacionais* do portfólio (Faturamento, Margem %) só podem ser
conferidas em produção. As colunas novas (NPS, pontos, Nota) vêm do snapshot e
foram conferidas.

## NÃO deployado

Parado antes do deploy, conforme pedido.

## Fora de escopo

- O **layout** do ranking segue intocado (é a referência aprovada); mudou só o
  destino do clique e o conteúdo do drawer que já existia.
- `resumo.margem_media_pct` continua no payload sem leitor. Remover é churn
  adjacente ao cálculo, sem ganho.
- `fraseVarMargemPp()` ficou sem uso na aplicação (segue coberta por teste
  próprio) — a frase em prosa repetia a linha de destaque do card.
