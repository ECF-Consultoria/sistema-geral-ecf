---
tipo: quick
slug: div-estoque-calculado-do-cartao
data: 2026-10-09
files_modified:
  - app/Services/Publicador/FamiliaDeFasesService.php
  - tests/Feature/Publicador/TelaDoProdutoTest.php
autonomous: true
---

<objective>
O cartão da fase promete um número e o botão grava outro, em conta multidepósito.

`FamiliaDeFasesService::fases()` calcula `estoque_calculado_valor` como
`floor($estoqueBase / $quantidade)` sobre o **total** do base. A adoção
(`PreviaDaFaseService::estoqueDoKit()`, usada pelo `RecalculoEstoqueDoKitService`) divide
**cada depósito** e só depois soma. Com `{A:5, B:5}` e N=3 o cartão mostra **3** e o botão
grava **2**.

Veio com a 175-10 (em produção desde 09/10) e ficou visível quando o botão "Usar estoque
calculado" passou a existir, no quick `261009-uec`. É número errado numa tela em produção,
contra a regra-mestra de acertividade do projeto.

Conta de UM depósito não muda nada — os dois caminhos já dão o mesmo valor.
</objective>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: o cartão passa a usar a MESMA regra da adoção</name>
  <files>app/Services/Publicador/FamiliaDeFasesService.php, tests/Feature/Publicador/TelaDoProdutoTest.php</files>
  <action>
  `estoqueTotal()` (L308) já consulta as variantes ativas do base — só pede `['estoque']`.
  Acrescentar `estoque_depositos` ao select e passar a calcular o valor do kit com a MESMA
  regra da adoção, reusando `PreviaDaFaseService::estoqueDoKit()` por variante e somando.
  Nenhuma consulta nova: é uma coluna a mais no select que já existe.

  Manter `estoque_total` do base como é hoje (soma dos estoques das variantes ativas) — essa
  parte está certa e aparece no cabeçalho.

  Teste (RED antes): base com duas variantes ativas, uma com `estoque_depositos = {A:5, B:5}`,
  kit de N=3 ⇒ `estoque_calculado_valor` tem de ser **2**, não 3. E um teste de não-regressão:
  base de um depósito só (ou sem depósitos) continua com o mesmo valor de antes.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && C:/xampp/php/php.exe artisan test tests/Feature/Publicador/TelaDoProdutoTest.php tests/Feature/Publicador/VinculoDeKitTest.php</automated>
  </verify>
  <done>O valor do cartão é igual ao que a adoção grava, nos dois cenários. Nenhuma consulta nova.</done>
</task>

</tasks>

<verification>
1. O número do cartão == o número que `usarEstoqueCalculado()` grava, em multidepósito e em depósito único.
2. Baseline PHP: 1374 verdes; sem falha nova.
3. `git diff --stat -- resources/js database` → vazio (é só servidor, sem migration).
</verification>
