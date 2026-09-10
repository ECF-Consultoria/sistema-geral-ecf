---
phase: 140
slug: extrair-tabelas-progressivas-do-clicksign
created: 2026-09-08
origem: investigação de viabilidade feita em 2026-09-08, autorizada pelo usuário
---

# Fase 140 — Contexto

Depois da Fase 139, ficou claro que **127 empresas cobram por uma tabela que o sistema assumiu**, não
por uma que alguém confirmou. O cadastro manual existe desde a Fase 137 e **nunca foi usado** — zero
registros. Digitar ~124 tabelas à mão é o caminho óbvio e o mais caro.

O usuário levantou a alternativa: *"isso pode consultar no Clicksign"*. Esta fase nasce da
investigação de viabilidade dessa ideia, feita em 2026-09-08 com autorização dele.

⚠️ **Tudo abaixo foi MEDIDO contra a conta de produção. Não re-medir, não contradizer.**

---

## D-01 — O que existe na conta Clicksign

| | |
|---|---|
| endpoint | `https://app.clicksign.com/api/v3` (produção, não sandbox) |
| envelopes | **429** |
| com "gestão de ads" no nome | **123** |
| candidatos fechados | **85** |
| status | 298 `closed`, 92 `canceled`, 31 `draft`, 8 `running` |

**A conta não é um repositório de contratos de cliente** — é o cofre da empresa inteira. Convivem ali
contratos de funcionário ("CONTRATO PRESTAÇÃO DE SERVIÇOS - Jessica De Oliveira"), locação de
auditório, mentorias, memorandos. Filtrar por "prestação de serviços" pega funcionário; o filtro tem
que mirar gestão de ADS.

**Não existe vínculo estruturado com empresa.** `metadata` vem `[]`. O único identificador é o nome
livre do envelope, e ele muda de padrão ao longo do tempo:

```
contrato_gestao_ads_meli_WEHOUSE_SERVICOS_DIGITAIS_LTDA   (ago/2025)
Contrato Gestao de Ads ECF - KAITON COMERCIO LTDA         (set/2025)
Contrato Gestão de ADS _ ECF - ALUMEN COMERCIO            (2026)
```

---

## D-02 — Os PDFs são acessíveis e legíveis

`GET /envelopes/{id}/documents` devolve `links.files` com três URLs assinadas da S3 — `original`,
`signed` e `ziped` — **válidas por 299 segundos**. Download precisa acontecer logo após a listagem.

O texto extrai limpo e **contém CNPJ e razão social da contratante**.

⚠️ **3 dos 14 downloads da amostra vieram como ZIP** (header `PK`), não PDF — dois deles bem grandes
(2,2 MB e 12 MB). O parser precisa detectar e tratar, não assumir PDF.

⚠️ **Não há extrator de PDF no servidor:** `pdftotext`, `PyPDF2` e `pymupdf` todos ausentes. O
projeto tem `barryvdh/laravel-dompdf`, que **gera** PDF e não lê. É uma decisão de implementação:
adicionar uma biblioteca de leitura (ex.: `smalot/pdfparser`) ou instalar poppler no servidor.

---

## D-03 — Nem todo contrato de gestão tem tabela progressiva

Amostra de 14 contratos espalhados no tempo (11 legíveis):

| | |
|---|---|
| com tabela progressiva | **5** |
| valor fixo, sem tabela | **6** |
| ilegíveis (ZIP) | 3 |

Os de valor fixo dizem literalmente *"parcelas mensais e iguais de R$ 3.000,00 (três mil reais)"* —
nenhuma ocorrência de "faixa", "progressiv" ou "faturamento mensal".

**A virada foi por volta de dezembro/2025**: antes, valor fixo; depois, tabela.

> Isso confirma a correção que o usuário fez em 2026-09-04 — o sistema não deveria classificar em
> faixa quem tem contrato de valor fixo, e hoje classifica.

---

## D-04 — Existem tabelas fora do padrão, e uma já foi encontrada

**ALUMEN (mar/2026)** — idêntica à que foi semeada na Fase 137:
`Até R$ 500.000 → R$ 3.000` … `Acima de R$ 5.000.000 → a partir de R$ 12.000` (7 faixas).

**DESK DESIGN (dez/2025)** — **12 faixas**, formato e valores diferentes:

```
até 100 mil    R$  2.250      ← faixa que o padrão não tem
+100 mil       R$  3.000
+500 mil       R$  4.500
+1 milhão      R$  6.000
…
+15 milhões    R$ 25.000      ← o padrão para em R$ 12.000
```

Hoje o sistema aplica a tabela padrão a essa empresa. Abaixo de 100 mil, **cobra R$ 3.000 onde o
contrato diz R$ 2.250**. É a "tabela antiga fora do padrão" que o usuário citou no brief da Fase 137
— achada, com nome e valores.

⚠️ **São dois formatos de notação diferentes**, e o parser precisa dos dois:
- antigo: `-100M / mês`, `+1MM / mês` (M = mil, MM = milhão)
- novo: `Até R$ 500.000,00`, `A partir de R$ 1.000.000,00`, `Acima de R$ 5.000.000,00`

---

## D-05 — O casamento com a empresa é o elo fraco

**A chave exata não existe no lado do sistema:**

| | de 201 empresas |
|---|---|
| com CNPJ preenchido | **10** |
| com razão social | **4** |

Casamento por nome, medido com normalizador simples na amostra de 14: **9 casaram** (≥70%). Os 5 que
falharam são o perigo, porque falham parecendo acerto:

| contrato | melhor palpite | veredito |
|---|---|---|
| GRAFICA ADHARA | Filipe **Adada** (50%) | errado, e parecido |
| DACOTEX | D.A DECOR (50%) | errado |
| M G MOVEIS | PARMAMOVEIS (66%) | errado |
| GRUPO LUCCAUTO | LUCCAUTO.COM (61%) | **certo**, abaixo do corte |

**Por isso escrita automática está fora de escopo por decisão.** Um vínculo errado grava a tabela de
cobrança de uma empresa em outra, e ninguém revisa depois. O caminho é leitura automática com
**confirmação humana** antes de qualquer escrita.

---

## D-06 — Estratégia acordada com o usuário (2026-09-08)

Começar pelo **comando de leitura que gera só um relatório** — sem tela, sem escrita. Com ele o
usuário vê as 123 linhas, confere a qualidade real do casamento e decide se vale construir o resto.

Só depois, se o relatório se mostrar bom: a tela de conferência e a escrita auditada, que ao
confirmar grava a tabela **e** preenche CNPJ e razão social (resolvendo de carona o vazio de D-05).

---

## Restrições permanentes

- ⚠️ **Executores não têm acesso a produção** — `plink`/`pscp`/`deploy.sh` bloqueados em subagentes.
  Qualquer passo que exija a VPS ou a API real é checkpoint humano, ou roda pelo orquestrador.
- ⚠️ **O banco local está ~31 migrations atrás e vazio de dado real.** Testes usam SQLite com
  factories; a API do Clicksign precisa ser mockada nos testes, nunca chamada de verdade.
- Copy e comentários em **pt-BR**, sem jargão na interface.
- Árvore compartilhada: nunca `git add -A` / `git add .` / `git commit -a` / `git stash`.
- Deploy só com autorização explícita.
- Gate atual: `--filter="Phase122|Phase136|Phase137|Phase138|Phase139"` em **348 testes / 1765
  asserções / 0 falhas**.
- ⚠️ `Phase138AvisoMudancaFaixaTest::refazer_e_mudar_a_faixa...` é flaky pré-existente, registrado em
  `.planning/todos/pending/260904-teste-flaky-aviso-mudanca-faixa.md`.
