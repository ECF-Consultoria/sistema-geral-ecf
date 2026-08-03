# Phase 123: Telas e relatórios (v21.0) - Discussion Log

> **Trilha de auditoria apenas.** Não usar como insumo de planejamento, pesquisa ou execução.
> As decisões estão no CONTEXT.md — este log preserva as alternativas consideradas.

**Date:** 2026-08-03
**Phase:** 123-telas-e-relat-rios-v21-0
**Areas discussed:** Onde a lista por empresa aparece, Rótulo da margem na transição, Empresas sem nota fechada, Profundidade nas telas admin

---

## Onde a lista por empresa aparece

| Option | Description | Selected |
|--------|-------------|----------|
| Só mês fechado | Lê `desempenho_company_score_snapshots` — leitura de banco, custo zero. Em curso segue como hoje. | ✓ |
| Fechado + em curso | Em curso passa a chamar compute com `incluirEmpresasScore=true`, disparando o dispatcher por empresa. | |
| Fechado sempre, em curso sob demanda | Botão "Ver detalhe por empresa" busca sob demanda; endpoint novo + estado de loading. | |

**User's choice:** Só mês fechado
**Notes:** Motivação registrada — o módulo já teve página de 70s por fan-out de API por empresa.

---

| Option | Description | Selected |
|--------|-------------|----------|
| Derivar o corte dos dados | Dropdown lista as competências que realmente têm snapshot, sem data fixa. | ✓ |
| Baixar o corte para 2026-06-01 | Mantém data fixa, só desce o valor. | |
| Não mexer — aceitar que estreia em 31/08 | Fase entrega código e a lista só aparece depois do congelamento. | |

**User's choice:** Derivar o corte dos dados
**Notes:** Decisão tomada depois de constatar, por leitura de código e do `MetricPeriodResolver`, que o filtro `>= '2026-08-01'` deixa o dropdown **vazio em produção** e o botão "Mês fechado" desabilitado — e continuaria vazio até 30/09/2026, já que `consolidar-mes` congela sempre o mês anterior. Sem a mudança, o critério 2 do ROADMAP não seria demonstrável.

---

| Option | Description | Selected |
|--------|-------------|----------|
| Aviso explicando por quê | Seção aparece com linha curta dizendo que a competência não foi congelada. | ✓ |
| Esconder a seção inteira | Sem dado, sem seção — tela idêntica à de hoje. | |

**User's choice:** Aviso explicando por quê
**Notes:** Evita que a ausência em 2026-07 seja lida como quebra depois de a lista ter aparecido em 2026-06.

---

## Rótulo da margem na transição

| Option | Description | Selected |
|--------|-------------|----------|
| Só o legado no topo | Card agregado segue na variação relativa, que é o que produz a nota. pp só na lista por empresa. | ✓ |
| pp no topo, relativo como secundário | Headline vira pontos percentuais, relativo em letra menor. | |
| Dois cards lado a lado | Um "vale hoje", outro "vai valer". | |

**User's choice:** Só o legado no topo
**Notes:** O risco levantado foi o de exibir em destaque um número que não gerou a `nota_final` mostrada ao lado — leitura errada de bônus.

---

| Option | Description | Selected |
|--------|-------------|----------|
| Antes → depois + variação | `14,1% → 12,0%   −2,1`, usando os dois absolutos do snapshot. | ✓ |
| Só a variação, com a unidade escrita | "subiu 4,7 pontos percentuais" — mais compacto. | |
| Variação com "pp" e tooltip | Coluna enxuta com explicação no hover. | |

**User's choice:** Antes → depois + variação
**Notes:** A opção com "pp" foi descartada por ser exatamente a sigla que UIEM-01 pede evitar, e porque tooltip não sobrevive ao PDF.

---

## Empresas sem nota fechada

| Option | Description | Selected |
|--------|-------------|----------|
| Duas seções, denominador explícito | "entraram na conta (N)" × "não entraram (M)", com motivo. | ✓ |
| Lista única com selo e motivo | Todas numa tabela; as que não contam com nota "—". | |
| Só as que contam + rodapé | Tabela limpa, ausência resumida no pé. | |

**User's choice:** "o recomendado" — interpretado como a primeira opção (duas seções, denominador explícito) e confirmado em voz alta antes de seguir.
**Notes:** Caso concreto que motiva — Felipe tem margem sobre 3 de 30 empresas.

---

| Option | Description | Selected |
|--------|-------------|----------|
| Marcar como valor provisório | Selo "Shopee: sem dado de margem" na célula; empresa segue em "entraram na conta". | ✓ |
| Mostrar só o número, sem selo | Margem aparece como qualquer outra. | |
| Selo + total no rodapé da seção | Selo por linha mais resumo "N de M pontuam por placeholder". | |

**User's choice:** Marcar como valor provisório
**Notes:** Pergunta só existiu porque uma afirmação anterior da discussão — de que empresa só-Shopee ficaria fora do denominador — foi conferida no código e **corrigida**: pela D-02 do `CompanyScoreService`, Shopee entra como `complete` com `margem_pontos = 1.0`. A correção mudou a natureza da decisão de "como excluir" para "como sinalizar".

---

## Profundidade nas telas admin

| Option | Description | Selected |
|--------|-------------|----------|
| Linha expansível por pessoa | Tabela segue por profissional; clique abre as empresas com nota e componentes. | ✓ |
| Só link para o detalhe do profissional | Relatório ganha link para a tela de Desempenho. | |
| Coluna resumo, sem lista | "6 de 11 empresas na conta" na linha da pessoa. | |

**User's choice:** Linha expansível por pessoa
**Notes:** A opção "coluna resumo" foi apresentada já com a ressalva de não cumprir o critério 4 do ROADMAP.

---

| Option | Description | Selected |
|--------|-------------|----------|
| PDF continua resumo | Uma linha por profissional, como hoje. | ✓ |
| PDF com detalhe por empresa | Bloco por profissional; ~10-20 páginas. | |
| PDF resumo + anexo opcional | Segunda rota e segundo template dompdf. | |

**User's choice:** PDF continua resumo

---

## Claude's Discretion

- Ordenação dentro de cada seção, densidade da tabela e escolha de componente (tabela vs cards responsivos).
- Texto exato dos selos e do aviso de ausência.
- Tradução dos slugs de `quality.motivos` para frases legíveis.
- Tratamento da Auditoria de Bônus — não foi discutida separadamente; as decisões das outras áreas a resolvem (D-10).

## Deferred Ideas

- Ligar a flag `performance_company_first_score` — depende do gate MPP-04, ainda `reprovado`.
- Calibrar as réguas / reduzir fragilidade de fronteira — decisão de diretoria, já registrada em todo próprio.
- Reconsolidar 2026-07 antes de 31/08 para antecipar dado — descartado por risco de mexer em pagamento.
- Débora Lima "sem carteira" na consolidação — todo próprio, sintoma de outro problema.
