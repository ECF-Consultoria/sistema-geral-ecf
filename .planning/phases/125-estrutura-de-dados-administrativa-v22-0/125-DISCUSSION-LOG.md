# Phase 125: Estrutura de dados administrativa (v22.0) - Discussion Log

> **Somente trilha de auditoria.** Não usar como entrada para agentes de planejamento, pesquisa
> ou execução. As decisões estão no `125-CONTEXT.md` — este log preserva as alternativas
> consideradas e o que foi recusado.

**Data:** 2026-08-07
**Fase:** 125-estrutura-de-dados-administrativa-v22-0
**Áreas discutidas:** Gate empírico #9, Ciclo de vida do contrato, Como o estado é gravado, Quem é o signatário, Congelar serviços e valores

---

## Gate empírico #9 — certificado de autenticação do signatário

Levantado pelo orquestrador, não pelo usuário: o Success Criteria 4 do ROADMAP exige o gate #9
resolvido, e não existe **nenhuma** credencial Clicksign no `.env` nem no `.env.example`.

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Coluna JSON flexível agora | Coluna JSON sem formato fixo; a fase fecha sem sandbox e o critério 4 vira "o schema comporta o certificado" (era a recomendação do Claude) | |
| Montar o sandbox antes | Para a fase até existir conta sandbox + token de API; modela o campo com o formato real | ✓ |
| Empurrar o critério 4 para a 126 | Duas tabelas sem o campo de certificado; o critério migra para a fase que terá sandbox de qualquer jeito | |

**Escolha do usuário:** Montar o sandbox antes.
**Notas:** O Claude apontou, antes e depois da escolha, que isso bloqueia `/gsd:plan-phase 125`
por tempo indefinido. O usuário manteve a decisão. Registrado como `<blockers>` no CONTEXT.md com
a lista exata do que precisa existir para destravar.

---

## Ciclo de vida do contrato

### Unicidade — dois contratos "em andamento" ao mesmo tempo

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Banco + código | Coluna auxiliar preenchida só enquanto em andamento + índice único; guard no código para mensagem amigável | ✓ |
| Só no código | Guard no service; duas requisições simultâneas passam pelas duas checagens | |
| Só no banco | Índice único apenas; usuário vê erro 500 cru | |

**Escolha do usuário:** Banco + código.
**Notas:** Coincide com a recomendação. O truque do `NULL` em índice único funciona igual em
MariaDB e SQLite, então não abre divergência entre produção e testes.

### Prazo de assinatura (`expira_em`)

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Entra agora, sem uso | Coluna nullable já na criação da tabela; preenchida só na Fase 127 (era a recomendação do Claude) | |
| Só na Fase 127 | Tabela nasce enxuta; o prazo chega com a lógica que o preenche | ✓ |

**Escolha do usuário:** Só na Fase 127.
**Notas:** O Claude registrou na hora o efeito colateral: sem `expira_em`, o estado `expirado`
existe (a D5 da milestone exige) mas fica sem data que o calcule até a Fase 127. Anotado no
CONTEXT.md como D-03. Custo aceito: uma migration e um deploy MariaDB a mais depois.

---

## Como o estado é gravado

### Tipo da coluna

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Texto + constantes no model | `string` + constantes públicas, padrão `Sugador::STATUS_*` / `HubspotEvento` | ✓ |
| Enum no banco | Banco recusa valor inválido, mas adicionar estado exige migration com branch SQLite | |

**Escolha do usuário:** Texto + constantes no model.
**Notas:** Recomendação baseada em cicatriz real do projeto — `enum` + SQLite já quebrou a suíte
de testes, e a migration de enum da tabela `servicos` pulou o branch SQLite.

### `liberado` — estado ou data?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Só data sobre `assinado` | Contrato para em `assinado`; `liberado_em` é data preenchida na soltura | ✓ |
| Estado próprio | `liberado` como estado final depois de `assinado` | |

**Escolha do usuário:** Só data sobre `assinado`.
**Notas:** Separa "o contrato terminou" de "a empresa foi solta". Efeito de segunda ordem que
puxou a decisão: a liberação manual da REDE-03 (Fase 130) preenche a mesma data sem inventar
estado paralelo, e "assinado com `liberado_em` nulo" é exatamente o caso que o alerta da REDE-02
precisa enxergar.

### Lista de estados (não perguntada — discricionário do Claude)

Proposta apresentada ao usuário no resumo e não objetada: `rascunho`, `aguardando_assinaturas`,
`assinado`, `recusado`, `expirado`, `cancelado`, `erro`. Derivada da D5 da milestone.

---

## Quem é o signatário

### Vínculo com `users`

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| FK nullable + dados copiados | `user_id` nullable quando interno; nome/e-mail/CPF sempre copiados | ✓ |
| Só campos livres | Sem FK nenhuma; a tela não consegue oferecer "assinar como usuário logado" | |
| FK sem cópia | Dados saem de `users` na exibição; contrato passado muda se o usuário for editado | |

**Escolha do usuário:** FK nullable + dados copiados.
**Notas:** O argumento que decidiu foi evidência jurídica não poder depender de FK viva. Puxa a
armadilha #2 do projeto: `nullOnDelete` exige `nullable()` no MariaDB (erro 1830), que o SQLite
não pega.

### Papéis

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Lista fixa em constantes | `contratante`, `contratada`, `testemunha` | ✓ |
| Texto livre | Flexível, mas o PDF não posiciona assinatura por papel | |

**Escolha do usuário:** Lista fixa em constantes.

### Situação individual do signatário

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Lista própria, curta | `pendente`, `assinou`, `recusou` | ✓ |
| Mesma lista do contrato | Menos código, mas permite gravar estado impossível num signatário | |

**Escolha do usuário:** Lista própria, curta.

---

## Congelar serviços e valores

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Congelar em JSON no contrato | Coluna JSON no próprio `contrato_assinaturas`, no instante da geração | ✓ |
| Congelar em tabela filha | `contrato_assinatura_itens`, uma linha por serviço; permite relatório por SQL | |
| Ler ao vivo | Valores saem de `contratos_servico` na hora; nada duplicado | |

**Escolha do usuário:** Congelar em JSON no contrato.
**Notas:** O argumento decisivo foi um incidente real do projeto — um `hs_mrr = 0` do HubSpot
zerou 3 contratos de R$ 3.000. Se o valor mudar depois de assinado, o PDF assinado (que vale
juridicamente) e o banco divergem. Precedente de implementação já existe em
`contratos_servico.hubspot_snapshot`.

---

## Claude's Discretion

- Lista exata dos 7 estados do contrato — apresentada no resumo, não objetada
- `LogsActivity` (spatie) no `ContratoAssinatura` — convenção do projeto
- Nomes finais de colunas, ordem das migrations, estrutura das factories e formato dos testes
- Comportamento de delete entre contrato e signatários (provável `cascadeOnDelete`)
- Aplicação das 3 armadilhas de schema do projeto sem perguntar (enum+SQLite, FK nullable no
  MariaDB, nome de índice em 64 chars)

## Deferred Ideas

- Tabela `contrato_assinatura_itens` — recusada aqui; fase própria se o relatório por serviço for pedido
- `expira_em` e o cálculo de expiração — Fase 127 (DADOS-06)
- Painel de taxa de assinatura e tempo médio — já em Future Requirements
- Reemissão de contrato expirado com revisão humana — Future Requirements

## Todos revisados e não dobrados

`todo.match-phase 125` devolveu 7 candidatos, todos com score 0.4–0.6 por casamento de palavras
genéricas ("phase", "api", "dados", "por"). Nenhum trata de contrato, assinatura ou Clicksign —
são de sugadores, carteira/desempenho e sync de grants ML. Nenhum apresentado ao usuário, para
não gastar o tempo dele com ruído de keyword.
