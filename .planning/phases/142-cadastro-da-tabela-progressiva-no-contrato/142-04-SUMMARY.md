---
phase: 142-cadastro-da-tabela-progressiva-no-contrato
plan: 04
subsystem: ui
tags: [react, inertia, tailwind, fechamento, tabela-progressiva, phpunit]

requires:
  - phase: 142-01
    provides: "prop tabela_faixas / tabela_faixas_e_de_hoje expostas pelo AdminController, GravarTabelaEmpresaService"
  - phase: 142-03
    provides: "componente compartilhado TabelaProgressivaFaixas em Components/Fechamento/, rota admin.contratos.tabela.show"
provides:
  - "TabelaFaixasSection.jsx só de leitura — nenhum router.post/router.delete/FaixaFormDialog sobra"
  - "estado 'propria' do fechamento renderiza a grade de faixas (empresa.tabela_faixas), não mais a frase solta"
  - "destaque da faixa atual respeita mês fechado: só marca quando bate com o congelado, senão nota de rodapé"
  - "link único de saída nos quatro estados para admin.contratos.tabela.show"
  - "Phase142FechamentoSomenteLeituraTest — trava das duas metades do D-04"
affects: [fechamento, contratos, tabela-progressiva]

tech-stack:
  added: []
  patterns:
    - "grep-de-arquivo-JSX como trava de contrato (sem test runner de JS) — ler o .jsx como texto puro"
    - "checagem de jargão via remoção de comentários + regex de fronteira de palavra unicode"

key-files:
  created:
    - tests/Feature/Phase142/Phase142FechamentoSomenteLeituraTest.php
  modified:
    - resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx
    - resources/js/Pages/Admin/Financeiro.jsx
    - tests/Feature/Phase138/Phase138FaixasGrupoCrudTest.php
    - tests/Feature/Phase139/Phase139TabelaProgressivaFielTest.php

key-decisions:
  - "Prop competenciaFechada removida de TabelaFaixasSection (não controlava mais botão nenhum depois do corte de escrita) — aviso de cadeado saiu junto"
  - "Destaque da faixa no mês fechado: só marca a linha quando ordem+valor+limite_superior batem com o congelado; senão nenhuma linha é destacada e uma nota de rodapé explica o porquê"
  - "Docblock do componente reescrito para paraphrasear identificadores removidos (router.post, FaixaFormDialog, admin.financeiro.faixas.*) em vez de citá-los literalmente — citação literal quebraria a própria trava de teste que verifica ausência desses vestígios"
  - "Abertura da tag <TabelaProgressivaFaixas mantida com o primeiro prop na mesma linha (não em bloco multi-linha) para preservar a convenção de busca por substring '<TabelaProgressivaFaixas ' já usada pelas suítes da Fase 139"

requirements-completed: [D-01, D-04]

duration: ~50min
completed: 2026-09-10
---

# Fase 142 Plano 04: Fechamento vira só leitura da tabela progressiva Summary

**`TabelaFaixasSection.jsx` perde os cinco diálogos de cadastro e passa a renderizar a grade real da tabela própria da empresa (D-01), com link único para a ficha do contrato — sem nenhum caminho de escrita restando no fechamento (D-04).**

## Performance

- **Tarefas de código:** 2/2 concluídas (Tarefa 3 é o checkpoint humano — aguardando aprovação)
- **Files modified:** 4 (2 código + 2 testes retargetados) + 1 teste novo criado

## Accomplishments

- `TabelaFaixasSection.jsx` (fechamento) não grava mais nada: os cinco `FaixaFormDialog`,
  `router.post`/`router.delete`, `linhaVaziaFaixa` e todos os botões de criar/substituir/apagar
  foram removidos.
- Estado `'propria'` (tabela própria da empresa) passa a renderizar `<TabelaProgressivaFaixas
  faixas={empresa.tabela_faixas} .../>` no lugar da frase solta "Tabela própria desta empresa" —
  a dívida documentada desde o plano `137-09` está paga também na tela de Fechamento (o plano
  `142-01` já tinha exposto a prop; este plano é quem finalmente a consome aqui).
- Destaque da faixa atual agora respeita mês fechado: quando `tabela_faixas_e_de_hoje === true`
  (competência congelada), só destaca a linha que bate exatamente com o que foi congelado
  (`ordem` + `valor` + `limite_superior`); quando não bate, nenhuma linha é destacada e aparece a
  nota "A tabela mudou depois deste mês. Esta é a que está cadastrada hoje."
- Link único de saída (`admin.contratos.tabela.show`) presente nos quatro estados — grupo (com/sem
  tabela própria), serviço, própria e "A DEFINIR" — com rótulo "Cadastrar tabela de cobrança" ou
  "Ajustar tabela de cobrança" conforme já existe ou não uma tabela própria naquele bloco.
- `Financeiro.jsx`: o botão de `AusenciaTabelaPendencia` (variant="full") trocou o `<a
  href="#tabela-faixas-{id}">` (âncora interna que pulava para o próprio accordion) por `<Link
  href={route('admin.contratos.tabela.show', empresa.id)}>`. Nada mais no arquivo foi tocado — o
  outro link de âncora interna ("Tabela presumida a partir do serviço contratado") continua
  intacto porque aponta para dentro do mesmo bloco, que agora só exibe.
- Duas suítes antigas (`Phase138FaixasGrupoCrudTest`, `Phase139TabelaProgressivaFielTest`)
  retargetadas: a exigência de `admin.financeiro.faixas.grupo` dentro do arquivo virou
  `admin.contratos.tabela.show` — preservando a intenção original (o bloco de grupo precisa ter
  caminho de cadastro) com o caminho novo. Nenhuma asserção foi apagada.
- Suíte nova `Phase142FechamentoSomenteLeituraTest` (7 testes) trava as duas metades do pedido do
  usuário: ausência de escrita e permanência da exibição, incluindo copy sem jargão e escala do
  Tailwind.

## Task Commits

1. **Tarefa 1: TabelaFaixasSection só de leitura, mostrando a tabela própria** - `80414a7e` (feat)
2. **Tarefa 2: retarget das travas antigas e a suíte da leitura** - `bc2fedfa` (test)

**Tarefa 3 (checkpoint humano):** não commitada — nenhum arquivo alterado, é conferência.

## Files Created/Modified

- `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx` - reescrito para só leitura; estado
  `'propria'` renderiza a grade; destaque respeita mês fechado; link único para a ficha do contrato
- `resources/js/Pages/Admin/Financeiro.jsx` - botão de `AusenciaTabelaPendencia` (variant="full")
  passa a linkar para `admin.contratos.tabela.show`; prop `competenciaFechada` não é mais passada
  para `TabelaFaixasSection`
- `tests/Feature/Phase138/Phase138FaixasGrupoCrudTest.php` - retarget da trava de rota
- `tests/Feature/Phase139/Phase139TabelaProgressivaFielTest.php` - retarget da trava de rota
- `tests/Feature/Phase142/Phase142FechamentoSomenteLeituraTest.php` - suíte nova (criado)

## Decisions Made

- **Prop `competenciaFechada` removida de `TabelaFaixasSection`** (e da chamada em
  `Financeiro.jsx`): ela só existia para desabilitar botões que não existem mais depois do corte
  de escrita. Manter o aviso de cadeado sem nenhum botão para travar teria sido copy confuso —
  "este mês está fechado, não pode alterar" quando NUNCA se pode alterar por ali, mês fechado ou
  não.
- **Destaque condicionado ao mês congelado** (não estava no arquivo antes desta fase, é
  comportamento novo pedido pelo plano): a grade sempre mostra a tabela de HOJE, mas num mês já
  fechado a linha "certa" para aquele mês pode já ter mudado — destacar a linha errada seria pior
  que não destacar nenhuma.
- **Docblock reescrito sem citar literalmente os identificadores removidos** (`FaixaFormDialog`,
  `router.post`, `admin.financeiro.faixas.*`): a suíte nova checa a AUSÊNCIA bruta dessas strings
  no arquivo (sem filtrar comentário, ao contrário de outras suítes da Fase 139/142 que filtram
  comentário antes de checar jargão). Citar os nomes na explicação histórica do docblock teria
  quebrado a própria trava que a explicação deveria documentar — a solução foi parafrasear
  ("diálogos de formulário", "chamadas de escrita ao backend") em vez de nomear o código morto.
- **Formatação da tag `<TabelaProgressivaFaixas` mantida em linha única com o primeiro prop**: a
  primeira tentativa quebrou os props em múltiplas linhas (`<TabelaProgressivaFaixas\n
  faixas={...}`), o que reduziu de 3 para 1 a contagem do substring `'<TabelaProgressivaFaixas '`
  (com espaço) em todo o projeto — quebrando silenciosamente
  `Phase139TabelaProgressivaFielTest::a_subcomponente_e_reaproveitada_nos_dois_blocos_grupo_e_servico`
  (exige ≥2) e minha própria checagem (c). Corrigido preservando a convenção já estabelecida pelas
  suítes anteriores.

## Deviations from Plan

None - plano executado como escrito. As duas suítes antigas foram retargetadas exatamente como o
plano descreveu (linha e justificativa), e a suíte nova cobre os sete pontos (a)-(g) listados na
Tarefa 2.

## Issues Encountered

- **Falso negativo de duas voltas na formatação JSX** (documentado acima em "Decisions Made") —
  resolvido ajustando a formatação, sem mudar comportamento.
- **Docblock citando literalmente os termos banidos pela própria suíte que eu estava escrevendo**
  — resolvido parafraseando o texto explicativo, sem perder a informação histórica para quem ler o
  código depois.

## Gate ampliado — antes × depois

⚠️ O número de referência escrito no `142-04-PLAN.md` (553 testes / 2642 asserções) já estava
desatualizado no momento da execução — os planos `142-01`/`142-02`/`142-03` (executados antes desta
sessão) já tinham acrescentado testes. O número real medido pelo usuário no início desta sessão
(ver git status inicial da conversa) era **598 testes / 2809 asserções / 0 falhas**.

Rodado nesta sessão, árvore com os dois commits deste plano aplicados:

```
C:/xampp/php/php.exe vendor/bin/phpunit --filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Quick260909"

Tests: 605, Assertions: 2833, PHPUnit Deprecations: 465.
Time: 04:22.721
```

**605 testes / 2833 asserções / 0 falhas.** Cresceu 7 testes / 24 asserções (a suíte
`Phase142FechamentoSomenteLeituraTest` nova) sobre o baseline de 598/2809 medido no início da
sessão. Nenhuma falha nova.

## Build e conferência do CSS compilado

- `npm run build`: limpo, sem erros, `✓ built in ~18-21s` (rodado duas vezes, uma por tarefa).
- Script Node (`fs.readFileSync` + `includes`, nunca grep de shell) lendo as linhas efetivamente
  ADICIONADAS pelos dois commits deste plano (não o arquivo inteiro — `Financeiro.jsx` tem ~2000
  linhas pré-existentes com colchetes de JS puro, como `cnt[nome]`/`router[method]`, que dariam
  falso positivo se o arquivo inteiro fosse escaneado) contra `public/build/assets/*.css`:
  - Única classe com colchetes nova: `text-[12px]` — já presente e amplamente usada no CSS
    compilado (classe pré-existente no projeto, reaproveitada nos novos elementos).
  - Nenhuma classe fora da escala real do Tailwind (`px-4.5`, `gap-4.5`, `py-5.5` etc.) foi
    introduzida — confirmado também pela suíte automatizada, teste (g).

## Falhas pré-existentes (não são regressão desta fase, não perseguidas)

`Phase138AvisoMudancaFaixaTest::refazer_e_mudar_a_faixa...` (flaky), `Phase13ComercialTest` (10),
`Phase14VerificarCobrancaTest`, `FechamentoMigrationTest`, `AdminFechamentoControllerTest` (4/16),
`Phase14MigrationTest`, `Phase14MlbControllerFiltroTest` — não estavam no filtro do gate ampliado
rodado acima, então nem entraram na contagem. Citadas aqui só para registro, conforme o plano pediu.

## User Setup Required

None - nenhuma configuração de serviço externo.

## Next Phase Readiness

**Checkpoint humano pendente (Tarefa 3).** Nada foi deployado. O executor não alcança produção
(bloqueado em subagente) e o banco local está ~31 migrations atrás e vazio de dado real — a
conferência visual pedida no checkpoint só é significativa contra produção, feita pelo usuário ou
pelo orquestrador com acesso ao VPS. Depois da aprovação: `state.advance-plan` **não deve ser
rodado por este executor** (trava registrada no prompt — a última vez que rodou avançou o contador
de outra sessão/fase por engano); a atualização de `STATE.md`/`ROADMAP.md`/`REQUIREMENTS.md` deve
ser conferida manualmente pelo orquestrador antes de qualquer edição automática.

---
*Phase: 142-cadastro-da-tabela-progressiva-no-contrato*
*Completed: 2026-09-10 (tarefas de código; checkpoint aguardando aprovação)*
