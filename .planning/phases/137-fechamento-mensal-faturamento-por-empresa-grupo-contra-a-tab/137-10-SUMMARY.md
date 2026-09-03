# 137-10 — SUMMARY

**Plano:** 10 — Estado da competência, fechar/refazer, fim do "acumulado" e checkpoint humano
**Status:** tarefas 1 e 2 concluídas; **Tarefa 3 (checkpoint) PARCIALMENTE fechada** — ver "Pendente" no fim
**Data:** 2026-09-03

---

## Tarefas 1 e 2 (código)

| Tarefa | Commit |
|---|---|
| Status da competência, botão de fechar e dialog de refazer | `b92a4bab` |
| `ProgressaoModal` sem a coluna Acumulado + teste de contrato | `6cb2d08c` |

A palavra "acumulado" saiu da interface — era o último lugar onde ela vivia, e é o que fecha o D-06
(pedido original do usuário: "não deve existir acumulativo"). Há trava de teste para ela não voltar.

---

## Tarefa 3 — checkpoint humano

### O que o usuário decidiu (2026-09-03)

**1. As tabelas semeadas batem com os contratos publicados.** Confirmado item a item:
`Gestão` (id 6) e `Brigada` (id 10) com as 7 faixas idênticas entre si (primeira R$ 3.000, última
aberta "a partir de R$ 12.000"); `Gestão de ADS Shopee` (id 9) com as 8 faixas de R$ 1.500 a
R$ 5.000. Nenhuma divergência relatada.

**2. O gap da Shopee é real e foi fechado.** A tabela não tinha faixa aberta — empresa Shopee acima
de R$ 3.000.000 não casava com faixa nenhuma e caía em `A DEFINIR`. O usuário decidiu fechar
copiando o desenho da Gestão (dois degraus fechados + um aberto), mantendo o passo de R$ 500:

| ordem | limite | valor |
|---|---|---|
| 9 | R$ 4.000.000 | R$ 5.500 |
| 10 | R$ 5.000.000 | R$ 6.000 |
| 11 | aberta | a partir de R$ 6.500 |

**3. A convenção de teto foi unificada.** A Shopee usava teto cheio (`50_000.00`) e a Gestão/Brigada
usam `X - 0,01`. O usuário decidiu unificar **na convenção da Gestão**: todos os tetos da Shopee
passaram para `,99`.

> ⚠️ Isso muda cobrança: empresa Shopee que faturar **exatamente** R$ 50.000 deixa de cair na faixa 1
> (R$ 1.500) e passa para a faixa 2 (R$ 2.000). O usuário foi avisado explicitamente antes de decidir
> e confirmou. Não é bug — é a convenção escolhida, e agora as duas tabelas se comportam igual.

**4. `MOVELOVEOFICIAL` não terá grupo (D-09 confirmado).** Ela é "pai" pelo mecanismo legado mas não
tem grupo no Comercial, e o usuário confirmou que **só valem os grupos do Comercial**. Deixa de somar
com as três filhas. Se um dia precisar somar, o conserto é criar o grupo no Comercial — nunca mexer
no fechamento.

### O que foi implementado a partir dessas decisões

| Arquivo | Commit |
|---|---|
| `database/migrations/2026_09_03_100000_ajustar_faixas_shopee.php` (novo) | `47a80941` |
| `tests/Feature/Phase137/Phase137FaixasSchemaTest.php` | `00dc2eb9` |

A migration é **nova**, não uma edição da `100003`. Motivo: a `100003` é idempotente por
`updateOrInsert(['servico_id','ordem'])`, então quem já a rodou não re-executaria a versão editada e
ficaria com os valores antigos. Migration nova garante que todo ambiente converge.

O teste que travava o estado antigo (`shopee_tem_8_faixas_todas_fechadas`) foi **renomeado**, não
adaptado — ele afirmava como correto justamente o gap que o usuário mandou fechar.

**Modelo de contrato:** `modelo-contrato-shopee-v2-FAIXAS-ALTAS.docx` gerado na raiz, com as 11
linhas e XML validado antes da gravação. **Ainda não foi publicado na Clicksign** — ação do usuário.

> ⚠️ Enquanto se comparava os modelos, constatou-se que o `.docx` **local** de Gestão está
> desatualizado: primeira faixa em R$ 3.500,00 contra R$ 3.000,00 do modelo publicado, que é o que
> vale e é de onde o seed veio. Os `.docx` na raiz são rascunhos de geração, não fonte da verdade.
> Republicar o arquivo local reverteria a primeira faixa da Gestão sem aviso.

### Gate

`--filter="Phase122|Phase136|Phase137"` → **239 testes, 1214 asserções, 0 falhas** (conferido na
árvore limpa após os commits).

---

## Pendente — exige produção

O roteiro do checkpoint assumia que os comandos Artisan rodariam localmente. **O ambiente local não
serve para isso**, e isso foi medido: o banco está 31 migrations atrás (a tabela
`servico_faixas_faturamento` sequer existe nele) e vazio do que importa — 0 `adman_metrics`,
0 `shopee_metrics`, 0 `company_groups`, 8 companies de teste. Não há competência para fechar nem
grupo Lyam para somar.

Continuam em aberto, e **só produção fecha**:

- [ ] `fechamento:consolidar-mes` + `fechamento:verificar-consolidacao --json` com exit code 0
- [ ] Grupo **Lyam** como uma linha só, com a soma das duas empresas, conferido por `SELECT` em
      `fechamento_grupo_snapshots` contra `fechamento_snapshots` — nunca pela tela
- [ ] Faixa-piso exibida como `a partir de R$ 12.000`, nunca `R$ 12.000` seco
- [ ] `Refazer fechamento` com motivo, e a linha correspondente em `fechamento_reconsolidacoes`
      com `snapshot_anterior` preenchido
- [ ] Publicação do `modelo-contrato-shopee-v2-FAIXAS-ALTAS.docx` na Clicksign

O deploy ainda **não foi autorizado** — nenhum comando de produção foi executado nesta sessão.

> Disciplina de privacidade respeitada: este SUMMARY registra decisões, contagens e nomes
> estruturais, e **não pareia nome de empresa com valor de mensalidade**.
