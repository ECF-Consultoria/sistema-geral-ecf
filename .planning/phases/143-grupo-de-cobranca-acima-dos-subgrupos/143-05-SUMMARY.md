---
phase: 143
plan: "05"
slug: a-composicao-do-grupo-fica-honesta
status: complete
completed: 2026-09-15
commits:
  - 9a6aa06e
  - d8cb554c
---

# 143-05 — A composição do grupo fica honesta na tela e no aviso — SUMMARY

Fecha os itens **5** e **7** do `deferred-items.md`. Os dois estouram no mesmo momento: quando o
usuário montar o grupo MPozenato e abrir o fechamento para conferir se a cobrança passou de
R$ 33.500 para R$ 21.000.

---

## T1 — O aviso de faixa para de mentir · `9a6aa06e`

**Problema:** quando um subgrupo é pendurado entre duas competências, a linha do cliente muda de
faixa **porque passou a somar mais empresas**, não porque o faturamento cresceu. O Passo 8 do
`fechamento:consolidar-mes` avisava isso aos admins como crescimento — no caso MPozenato, dez
empresas de uma vez.

**O que mudou:**
- `FechamentoFaixaNotifier` compara o **conjunto de `company_id` congelado** sob o grupo de cobrança
  nas duas competências (lido de `fechamento_snapshots`) antes de emitir o aviso
- **composição diferente:** a linha sai do aviso de faixa e entra em `composicao_mudou`, com quantas
  empresas entraram e saíram — impresso no resumo do comando e registrado em log. **A supressão não é
  silenciosa**: trocar a mentira por uma omissão só mudaria o tipo de confusão
- **composição igual:** comportamento idêntico ao de antes — regressão zero enquanto ninguém montar
  grupo nenhum
- a idempotência por `notificado_em` / `notificado_faixa_ordem` segue intocada: linha suprimida não é
  carimbada, e "Refazer fechamento" não ressuscita aviso

Arquivos: `app/Services/Fechamento/FechamentoFaixaNotifier.php`,
`app/Console/Commands/ConsolidarMesFechamento.php`,
`tests/Feature/Phase143/Phase143AvisoComposicaoTest.php`.

---

## T2 — A tela diz o que está somando · `d8cb554c`

**Problema:** com o grupo montado, a linha do fechamento dizia "MPozenato, 10 empresas" sem avisar
que DRossi, Gran Belo e Lyam estão dentro. A pessoa abre a tela justamente para conferir a junção, e
a tela não mostra o que foi juntado.

**O que mudou:**
- a chave `subgrupos` sai nos **cinco** literais de linha de `AdminController::fechamento()` — vazia
  nos três ramos de empresa, calculada nos dois de grupo (conferido: `grep -c "'subgrupos' *=>"` → 5).
  Faltar em um é exatamente como nasceu a prop fantasma `cobranca_mensal_grupo` nesta tela
- o shape de cada item (`id`, `nome`, `eh_a_raiz`, `empresas`) é **o mesmo** de
  `SimuladorGrupoCobrancaService`: a prévia da montagem e o fechamento falam do mesmo dado
- grupo que não junta nenhum outro devolve **lista vazia** — repetir o nome do próprio grupo como
  "composição" seria ruído
- na tela: selo "Junta N grupos" na linha (nomes no tooltip) e a lista completa na composição do
  grupo, marcando qual é o grupo principal
- **mês já fechado não guarda a composição por subgrupo daquele mês** (não existe `subgrupo_id` no
  snapshot — item 3 do `deferred-items.md`, ainda aberto). Nesse ramo `subgrupos_sao_de_hoje` vai
  `true` e a tela diz: *"Esta é a divisão de hoje. O fechamento já registrado deste mês guarda o total
  do cliente, não quais grupos faziam parte dele na época."* Mostrar a divisão de hoje como se fosse a
  daquele mês é o único desfecho proibido

Arquivos: `app/Http/Controllers/AdminController.php`, `resources/js/Pages/Admin/Financeiro.jsx`,
`tests/Feature/Phase143/Phase143ComposicaoDoGrupoNaTelaTest.php`,
`tests/Feature/Phase143/Phase143ComposicaoUiTest.php`.

---

## Gates (exit capturado antes de qualquer pipe)

| filtro | resultado |
|---|---|
| `Phase143` | **137 passando** (687 asserções), exit 0 |
| `Phase122\|Phase136\|…\|Phase143\|Quick260909\|Quick260910\|Quick260911` | **821 passando** (3746 asserções), **0 falhas**, exit 0 — era 797 no 143-04 |
| `Phase74\|Phase110` | **39 passando** (170 asserções), **0 falhas**, exit 0 |
| `npm run build` | ✓ 45,31 s |

**NPS:** nenhum arquivo de NPS foi tocado pelos dois commits (`--stat` do T1: notifier, comando e um
teste; do T2: controller, JSX e dois testes). A suíte de NPS não foi rodada de novo nesta entrega.

---

## Incidente de execução — registrar para não repetir

O executor do plano **travou** (sem progresso por 600 s) logo depois de commitar o T1, com o T2
**pronto porém sem commit** — `AdminController.php` e `Financeiro.jsx` modificados e os dois testes
novos sem rastreio. O orquestrador revisou o diff, conferiu os cinco literais, rodou os três gates e o
build, e commitou.

⚠️ **A primeira tentativa de commit falhou inteira:** `git commit -- <caminhos>` só aceita arquivo
que o git já conhece, e os dois testes novos nunca tinham passado por `git add`. Commit é atômico,
então **nada foi gravado** — a falha não deixou meio-commit. Refeito com `git add -- <os dois testes>`
antes do `git commit -- <os quatro caminhos>`. Lição: **arquivo novo precisa de `git add` explícito
por caminho antes do `git commit --`**; o pathspec do commit não rastreia arquivo sozinho.

---

## Fora do escopo — continua aberto

- **item 3** — o snapshot não guarda em qual subgrupo cada empresa estava; é o que obriga a tela a
  dizer "divisão de hoje" em mês fechado
- **item 6** — prévia com competência única
- **item 8** — `FormularioFaixas` com duas definições (empresa e grupo)
- **item 9** — a página do grupo não mostra faturamento nem mensalidade resultante

## Estado em produção

**Nada deployado nesta entrega.** Em produção os 15 grupos seguem com `parent_id` nulo e
`grupo_faixas_faturamento` zerada — nenhum grupo foi montado, nenhuma cobrança mudou.
