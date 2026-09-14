---
phase: 143
slug: grupo-de-cobranca-acima-dos-subgrupos
created: 2026-09-14
origem: problema levantado pelo usuário em 2026-09-14, conferindo o fechamento
---

# Fase 143 — Contexto

O usuário abriu assim:

> "Minha ideia é que a criação dos grupos e os grupos criados fossem um só para o sistema todo, e
> foi isso que fiz. Porém eu não sabia da forma de uso disso por parte do pessoal da empresa: como
> até então o lugar onde o grupo tinha mais utilidade era no NPS, eles criaram os grupos pensando
> apenas em NPS. Ou seja, nem todos os grupos são realmente como era para ser."

> "Alguns grupos foram criados pensando em subgrupos e não no grupo inteiro, e por isso no
> fechamento a cobrança não é a real como deveria ser sobre o grupo inteiro."

---

## D-01 — O pessoal não errou. O sistema empurrou para lá.

⚠️ **Esta é a descoberta que reenquadra a fase.** O link de NPS de grupo tem no banco:

```
unique(company_group_id, template_id, month_reference)
```

**Um link por grupo, por modelo, por mês.** E `NpsGrupoCoberturaService::calcular()` já escolhe uma
empresa de referência e **exclui do link toda empresa cuidada por outra pessoa** (motivo
`responsavel_diferente`).

Num grupo com dois subgrupos, o link cobre **um** e deixa o outro de fora — e não dá para gerar um
segundo naquele mês. A única saída para mandar NPS aos dois era **cadastrar cada subgrupo como
grupo separado**. Foi o que fizeram.

> Não é erro de uso a corrigir com treinamento. Qualquer solução que force a desfazer os grupos de
> hoje quebra o NPS que eles usam.

---

## D-02 — O caso concreto, medido em produção (2026-09-14)

O usuário nomeou: **o grupo MPozenato de verdade é MPozenato + DRossi + Gran Belo + Lyam.**

| grupo hoje | empresas | faturamento (ago/2026) | cobrança |
|---|---|---|---|
| MPozenato | 2 | R$ 3.812.487,89 | R$ 9.500 |
| Gran Belo | 2 | R$ 5.977.697,79 | R$ 12.000 |
| DRossi | 4 | R$ 1.238.304,17 | R$ 6.000 |
| Lyam | 2 | R$ 1.650.921,98 | R$ 6.000 |
| **somado** | **10** | **R$ 12.679.411,83** | **R$ 33.500** |

Como **um grupo só**, R$ 12,68 milhões caem numa faixa só:

| tabela que governasse | mensalidade |
|---|---|
| a do MPozenato (7 faixas, origem **contrato**) | **R$ 21.000** |
| a de Gran Belo / DRossi / Lyam (**presumidas**, nunca conferidas) | R$ 12.000 |

⚠️ **A correção DERRUBA a cobrança**: de R$ 33.500 para R$ 21.000 ou R$ 12.000 — R$ 12.500 a
R$ 21.500 por mês, R$ 150 mil a R$ 258 mil por ano, **num cliente só**.

É a matemática da tabela progressiva: cobrar em quatro pedaços faz o cliente pagar como quatro
clientes médios, e o desconto por volume — a razão de existir da tabela — desaparece. O quadro
provável é que **hoje se cobra acima do que o contrato dá direito**.

**O usuário confirmou que conhece e aceita essa direção** (2026-09-14): *"Sim, após eu ter noção do
problema sabia que por os grupos estarem separados a cobrança era maior."*

---

## D-03 — A forma: grupo-pai, com os grupos de hoje virando subgrupos

Decidido com o usuário. **Aditivo**: os grupos que existem **ficam como estão, com os mesmos ids**.

- **NPS não muda nada** — mesma tabela, mesma restrição de unicidade, mesmos links gerados, mesma
  regra de cobertura. Cada subgrupo segue podendo ter o seu link no mês.
- **O fechamento passa a agregar pela RAIZ da árvore**, não por `company_group_id`.
- Empresa que paga junto mas não pertence a subgrupo nenhum pendura direto no pai.

⛔ **Recusada** a alternativa de criar grupos próprios do administrativo só para o fechamento: é
voltar atrás na decisão deliberada do usuário de ter uma lista só, e este projeto já tem a cicatriz
— a **Fase 137 substituiu a função de grupos antiga do fechamento pela do Comercial exatamente para
parar de manter duas**. Com duas listas, toda empresa que entra ou sai precisa ser lembrada nos dois
lugares, e o dia em que alguém esquecer a cobrança sai errada em silêncio.

---

## D-04 — De onde vem a tabela do grupo

Palavras do usuário:

> "A tabela do grupo é a que vamos cadastrar no administrativo, na tela de atribuição de tabela
> progressiva. Ou então pela função do sistema identificar o contrato no Clicksign de alguma empresa
> que faz parte do grupo — provavelmente o contrato dessa empresa está tratando do grupo inteiro.
> Nesse caso será necessária a conferência humana para ver se está batendo, na tela de match."

Hoje **`grupo_faixas_faturamento` está zerada** — a tabela existe no schema e nunca teve UI nem uma
linha gravada. Os dois caminhos acima precisam ser construídos.

⚠️ A precedência atual do resolver é **grupo → empresa → serviço**. Com a árvore, passa a ser
**pai → subgrupo → empresa**.

---

## D-05 — Assunções que estou declarando (não vieram do usuário)

1. **Um nível só de hierarquia.** Grupo com pai não pode ser pai de ninguém. `parent_id` permite
   mais no futuro, mas a validação trava em um nível — evita ciclo e mantém a agregação legível.
2. **Subgrupo deixa de ser linha cobrável no fechamento.** Quem cobra é a raiz; os subgrupos
   aparecem como composição dentro dela, do mesmo jeito que as empresas-filhas aparecem hoje.
3. **Competência já fechada não muda sozinha.** Julho e agosto ficam como estão; montar a hierarquia
   não reescreve mês fechado. Quem quiser aplicar retroativo reconsolida de propósito, com `--motivo`.

---

## D-06 — Escala desconhecida, e a UI é que resolve

> "Por enquanto só tenho conhecimento desse caso. Vamos desenvolver a correção para que, caso
> existam outros, seja possível resolver pela UI."

⚠️ **Não dá para descobrir os outros casos por dado:** tentei pela raiz do CNPJ e **145 das 203
empresas estão sem CNPJ cadastrado**. A montagem da hierarquia é curadoria humana — e por isso a
tela tem de ser boa o bastante para o pessoal resolver sozinho, sem pedir migration.

Medição de apoio (2026-09-14): 15 grupos, 46 empresas em grupo, 156 fora. Divergência real de
responsável (mesma função **e** mesmo serviço, pessoas diferentes) em **4 grupos** — Camillo Parts,
Utilar, Future e Luccauto, sempre em `consultor`. A primeira leitura acusou 9, mas `company_users`
tem **uma linha por serviço** e a maioria era só serviço diferente, não subgrupo.

---

## Restrições permanentes

- ⚠️ **Isto muda cobrança, para baixo e em escala.** Nenhuma mudança entra sem um comparativo
  antes×depois por grupo, com gente conferindo antes de valer.
- ⚠️ **O NPS não pode sentir nada.** `nps_group_surveys`, a unicidade e a cobertura ficam intocados.
- ⚠️ Executores não alcançam produção (`plink`/`pscp`/`deploy.sh` bloqueados em subagente).
- ⚠️ Banco local ~31 migrations atrás e vazio de dado real — testes em SQLite com factories.
- ⚠️ MariaDB: nome de índice acima de 64 caracteres é recusado (1059); `nullOnDelete()` exige
  `nullable()` antes (1830); coluna de tipo enumerado quebra o SQLite dos testes.
- ⚠️ Árvore compartilhada com outra sessão ativa (milestone v23.0, fases 150-157). Nunca
  `git add -A` / `git add .` / `git commit -a` / `git stash`.
- Copy e comentários em **pt-BR**, sem jargão na tela.
- Gate atual: `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Quick260909|Quick260910|Quick260911"`
  em **656 passando**, com **28 falhas alheias** (`setores.nome`, migration da outra sessão).
