---
phase: 142
slug: cadastro-da-tabela-progressiva-no-contrato
created: 2026-09-10
origem: pedido do usuário em 2026-09-09, olhando o fechamento em produção
---

# Fase 142 — Contexto

O usuário pediu esta fase com estas palavras:

> "A parte do cadastro de tabela progressiva pelo sistema deve melhorar bastante, acho que deve
> haver uma fase só para isso."

---

## D-01 — A tabela própria da empresa não aparece

> "Ao cadastrar uma tabela progressiva apenas para a empresa, a tabela não mostra as faixas e etc,
> mostra apenas uma frase escrita 'Tabela própria desta empresa'. Deve mostrar a tabela."

⚠️ **Isto não é defeito novo — é uma lacuna registrada e conhecida desde a Fase 137.** O executor do
plano `137-09` a documentou no próprio componente e no SUMMARY:

> `AdminController::fechamento()` não expõe as faixas da tabela própria de uma empresa — só a origem
> e o nome do serviço. O `TabelaFaixasSection` trata isso honestamente: "Substituir tabela própria"
> abre um formulário em branco com aviso explícito, em vez de fingir valores pré-preenchidos que
> poderiam sobrescrever preço real com número errado.

Foi decisão consciente na época, com o custo aceito: quem edita uma tabela existente **redigita tudo**.
Esta fase paga essa dívida.

⚠️ **A urgência mudou.** Depois da Fase 141, existem **168 tabelas de empresa marcadas como
presumidas** em produção, todas esperando conferência contra o contrato real. A tela de cadastro
deixou de ser caso raro e virou ferramenta de uso frequente.

---

## D-02 — Máscara de dinheiro

> "Ao editar ou cadastrar uma tabela progressiva, o valor numérico, por não ter formatação de dinheiro
> real, fica difícil de entender. Aplique uma máscara de dinheiro."

⚠️ Os campos guardam valores como `499999.99` e `12000.00`. Sem separador, `1500000` e `150000` são
quase indistinguíveis a olho — e um zero a mais numa faixa muda a cobrança de uma empresa inteira.

---

## D-03 — O cadastro muda de lugar

> "Acho que o local onde deve ser possível cadastrar a tabela progressiva por empresa deve ser outro:
> deve ser dentro da página de contrato `/administrativo/contratos/empresa/{id}`. Nessa página deve
> ser um botão relacionado ao cadastro da tabela progressiva que leva para uma página exclusiva
> disso. Acho que no contrato fica mais adequado, por se tratar de algo relacionado ao contrato e não
> ser algo que muda com frequência."

O destino já existe: `admin.contratos.show` → `ContratoAdminController::show()` →
`resources/js/Pages/Admin/ContratoDetalhe.jsx`, sob a permissão `admin.contratos`.

---

## D-04 — O fechamento fica só de leitura

> "No fechamento deve continuar mostrando a tabela progressiva de cada empresa e a faixa que a
> empresa está, só não deve ser possível cadastrar ou editar as tabelas por ali."

⚠️ **Continuar mostrando é parte do pedido.** A tabela e a faixa permanecem visíveis no fechamento —
o que sai é a capacidade de cadastrar e editar.

---

## O que já existe e deve ser reusado

| peça | onde |
|---|---|
| `TabelaFaixasSection` com 4 ramos + `TabelaProgressivaFaixas` | `resources/js/Pages/Admin/Financeiro/` |
| validação de sobreposição e buraco entre faixas | `SalvarFaixasFaturamentoRequest` |
| CRUD por serviço, empresa e grupo | `FechamentoController` |
| origem da tabela (`manual` / `contrato` / `presumida_servico`) | `EmpresaFaixaFaturamento` (Fase 141) |
| tela de conferência das tabelas lidas do Clicksign | `/administrativo/contratos/tabelas` (Fase 140) |

⚠️ A tela da Fase 140 **já vive no módulo de contratos** e faz coisa parecida — confirmar tabela lida
do contrato. Vale decidir no planejamento se as duas convivem, se uma leva à outra, ou se viram a
mesma coisa. Duas telas que cadastram tabela em lugares diferentes é como o problema começou.

---

## Estado medido em produção (2026-09-10)

| | |
|---|---|
| empresas com tabela própria | **169** |
| — origem `presumida_servico` | 168 |
| — origem `manual` | 1 |
| tabelas de grupo | 0 |
| propostas lidas do Clicksign, aguardando conferência | 85 (49 com tabela, 29 de valor fixo) |
| empresas sem tabela nenhuma | 32 (quase todas teste ou cadastro incompleto) |

---

## Restrições permanentes

- ⚠️ **Isto edita tabela de cobrança.** Um zero a mais vira fatura errada. Toda escrita precisa de
  trilha de auditoria, e a validação de faixas existente não pode ser afrouxada.
- ⚠️ **Não sobrescrever tabela confirmada com dado presumido.** As três origens precisam continuar
  distinguíveis, e confirmar contra contrato manda sobre o que foi herdado.
- ⚠️ Executores não alcançam produção; banco local ~31 migrations atrás e vazio de dado real.
- ⚠️ Árvore compartilhada com outra sessão ativa: nunca `git add -A` / `git add .` / `git commit -a` /
  `git stash`.
- ⚠️ Escala do Tailwind: `px-4.5`, `gap-4.5`, `py-5.5` não existem — o build passa e nenhum CSS é
  gerado.
- Copy e comentários em **pt-BR**, sem jargão na tela.
- Gate atual: `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Quick260909"`
  em **553 testes / 2642 asserções / 0 falhas**.
