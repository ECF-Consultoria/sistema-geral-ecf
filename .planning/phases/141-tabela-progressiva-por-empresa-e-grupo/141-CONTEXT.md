---
phase: 141
slug: tabela-progressiva-por-empresa-e-grupo
created: 2026-09-09
origem: correção de modelagem feita pelo usuário em 2026-09-09, olhando o fechamento em produção
---

# Fase 141 — Contexto

O usuário corrigiu uma premissa que atravessa as Fases 137, 138 e 139. Não é ajuste de tela: muda
**como a mensalidade é calculada**.

---

## D-01 — A tabela é da empresa ou do grupo. Nunca do serviço.

Palavras do usuário (2026-09-09):

> "A tabela progressiva não deve ser por serviço e sim por empresa ou grupo de empresas. Se a empresa
> tem mais um serviço — exemplo: gestão e gestão de ads shopee — deve ser cadastrada **uma** tabela
> progressiva, e o faturamento das duas plataformas deve ser **somado** para ser comparado à tabela e
> classificar a faixa."

> "Nos grupos é a mesma coisa: cada empresa do grupo soma o faturamento (de cada plataforma em que
> ela tem serviço contratado), o resultado é o faturamento do grupo, e esse faturamento é o parâmetro
> para definir a faixa na tabela cadastrada **no grupo**."

---

## D-02 — Só faturamento de serviço COM tabela entra na soma

O usuário confirmou em 2026-09-09 que **Mentoria não tem tabela progressiva**, e que:

> "o resultado de faturamento relacionado ao serviço de mentoria **não é somado**"

Ele também observou que provavelmente não existe empresa com Mentoria **e** Gestão/Shopee ao mesmo
tempo, por serem serviços parecidos — mas a regra vale de qualquer forma.

Setores medidos em produção (2026-09-09):

| setor | serviços | entra na soma? |
|---|---|---|
| `performance` | Gestão, Mentoria, Brigada | Gestão e Brigada sim; **Mentoria não** |
| `shopee` | Gestão de ADS Shopee | sim |
| `publicacao`, `polos`, `outros` | os demais | fora do fechamento |

---

## D-03 — A mensalidade é o valor da faixa

⚠️ **Assunção declarada ao usuário e não contestada.** A mensalidade passa a ser **o valor da faixa,
e só isso** — nada é acrescentado por a empresa ter dois serviços.

Foi o que motivou a reclamação que abriu esta fase:

**BARAOSHOP VARIEDADES, agosto/2026** — faturamento R$ 488.262,90, faixa 1, valor da faixa R$ 3.000.
A tela mostra **R$ 5.500**, porque a regra atual é *"faixa + soma dos contratos mensais"*
(`CobrancaCalculator`): R$ 3.000 da faixa mais R$ 2.500 do contrato de Shopee.

Pela regra nova: soma-se **faturamento**, não se soma **mensalidade**. O faturamento das duas
plataformas define uma faixa só, e é ela que se cobra.

> Empresa **sem** tabela progressiva — o caso de Mentoria — continua no valor fixo do contrato. A
> extração da Fase 140 identificou **29 contratos** de valor fixo entre os 85 lidos.

---

## D-04 — O que acontece com a tabela por serviço

`servico_faixas_faturamento` **deixa de ser régua aplicável**. Hoje ela é exatamente o que classifica
**127 das 201 empresas** no fechamento de agosto — todas herdam a do serviço, sem contrato nem
cadastro que confirme.

⚠️ **Aplicar a regra nova sem mais nada esvazia o fechamento**: nenhuma empresa tem tabela própria
cadastrada hoje (`empresa_faixas_faturamento` e `grupo_faixas_faturamento` estão em zero).

Uma saída para a tabela do serviço é virar **modelo de partida** do cadastro — a pessoa abre a tabela
da empresa já preenchida com o padrão do serviço e ajusta. Decidir no planejamento; o que não pode é
continuar sendo aplicada em silêncio.

---

## D-05 — O caminho de migração já existe

A **Fase 140** leu os contratos do Clicksign e produziu, em produção, **85 propostas**:

| | |
|---|---|
| com tabela progressiva lida | **49** |
| valor fixo | **29** |
| sem leitura | 7 |
| casaram com segurança | **0** |

As 49 tabelas lidas **viram tabelas de empresa** — que é a única forma que passa a valer. A tela de
conferência (`/administrativo/contratos/tabelas`) já está construída e commitada, com a confirmação
gravando a tabela da empresa.

> Sem a Fase 140, esta mudança de modelo seria impraticável: exigiria digitar ~130 tabelas à mão
> antes de o fechamento voltar a funcionar.

⚠️ **As 18 réguas distintas medidas** confirmam que a tabela é mesmo por empresa: duas delas divergem
já na terceira faixa, então quem fatura R$ 2,5 milhões paga R$ 6.000 numa e R$ 7.500 na outra.

---

## D-06 — Fora de escopo, por decisão

- **A tela de cadastro** (mostrar as faixas, máscara de dinheiro, mover para a página de contrato da
  empresa, fechamento só leitura) é **fase própria**, a pedido do usuário.
- O paliativo de legibilidade da mensalidade já foi feito no quick `260909-lge` — mostra a composição
  atual. Ele **sai** quando esta fase entrar, porque a composição deixa de existir.

---

## Restrições permanentes

- ⚠️ **Isto muda cobrança.** Toda mudança precisa de teste que compare o valor antes e depois num
  cenário concreto, e o mês já fechado **não** pode ser reescrito (disciplina D-11 da Fase 137).
- ⚠️ Executores não alcançam produção (`plink`/`pscp`/`deploy.sh` bloqueados em subagente).
- ⚠️ Banco local ~31 migrations atrás e vazio de dado real — testes em SQLite com factories.
- ⚠️ **Árvore compartilhada, e há outra sessão ativa** nos mesmos arquivos do fechamento
  (`AdminController`, `Financeiro.jsx`). Nunca `git add -A` / `git add .` / `git commit -a` /
  `git stash`.
- Copy e comentários em **pt-BR**, sem jargão na tela.
- Gate atual: `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Quick260909"` em
  **477 testes / 2318 asserções / 0 falhas**.
