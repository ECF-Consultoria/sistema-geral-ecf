---
tipo: quick
slug: t01-selecao-de-empresas-v2
data: 2026-10-09
status: complete
origem: stitch_ecf_marketplace_publisher_redesign/01_sele_o_de_empresas_modern_minimalist/
---

# Quick 261009-t01 — Seleção de Empresas v2 (tela 01 do pacote do Stitch)

A tela 01 recriada no vocabulário do projeto: ganhou **ERP declarado**, **contagem de kits**,
Acesso rápido em cartões e um rodapé de três cards — e **deixou de cair com `empresas: null`**.

## Commits

`f5f246d6` · `aa3c5df7` · `ae72c43f` · `5f5209ca` · `535b50cf` · `796d2c17`

**Criado:** `Components/Mlb/Publicador/CartaoAcessoRapido.jsx`, `tests/js/publicador-selecao-empresas-v2.test.js` (36 testes).
**Modificado:** `ProgramasPublicadorService.php`, `PainelVisaoGeralService.php` (desvio), `AnunciosEmpresas.jsx`, `ProgramaPublicadorTest.php`.

## Gates — reconferidos pelo orquestrador

| gate | antes | depois |
|---|---|---|
| PHP `tests/{Feature,Unit}/Publicador` | 1376 passed | **1382 passed**, 0 falhas |
| JS `npm run test:js` | 1777 / 1775 pass / 2 fail | **1813 / 1811 pass / 2 fail** |
| `npm run build` | — | verde; `assets/AnunciosEmpresas-BFIRd4n-.js` |
| `grep -c "bg-ecf-yellow[^/]"` na página | — | **0** (gate do amarelo) |
| `git diff --stat -- database` | — | **vazio** |

⚠️ O hash do chunk saiu **com hífen** (`BFIRd4n-`) — a armadilha avisada. Resolvido pelo JSON do
manifest, nunca por grep do nome.

## O que ficou diferente do mockup, e por quê

> ⚠️ Conferência feita contra o **PNG do mockup** e o HTML do render real. **Ninguém viu a tela
> renderizada no navegador** — a conferência visual é do usuário.

**Decisões que vencem o mockup:**

- **Coluna Portal FICA, ERP entra ao lado.** O mockup trocou Portal por "ERP & SYNC"; Portal é
  dado real e funcionando, ERP é o que não existe.
- **ERP é sempre "declarado", nunca "sincronizado há 14 min"** (decisão 8 do handoff). Carimbo de
  sincronização que nunca aconteceu afirmaria fato falso sobre a conta de um cliente.
- **Amarelo translúcido** no primário, não o sólido do mockup — gate `publicador-entrada.test.js:53`.
  O amarelo é o **mesmo** `#ffe600`.
- **"Conectar nova empresa" desabilitado com "Em breve"** — dois destinos possíveis, decisão pendente.
- **Barra lateral "MÓDULO PUBLICAÇÃO" não existe** — superada pela Etapa 1.
- **⌘K e pílula de contexto ficaram fora** — transversais às 4 telas, tarefa própria.

**Onde o dado não existe e o rótulo mudou:**

| Mockup | Ficou | Motivo |
|---|---|---|
| "12 Fase 2 · 4 em Revisão · 2 fiscais · Onboarding Incompleto" | "12 kits" + "4 prontos" | `quantidade_kit >= 2` inclui o kit de 3, que é Fase 3 — "Fase 2" seria rótulo errado. "Em revisão", "fiscais" e "onboarding incompleto" não existem |
| "MLB-109284" no cartão e na linha | CNPJ/CUST | O MLB não está no payload desta tela |
| "Ver histórico completo →" | fora | Não existe histórico de acessos |
| "Qualidade de Catálogo 98,4%" | "Prontos para publicar — N" | Não existe índice de qualidade |
| "Monitor de Token OAuth" | "Contas a reconectar — N", clicável | Número real, com o escopo dito |
| "Atalho Operacional ⌘K" | "Kits em jogo — N" | Não prometer atalho que não existe |
| "API Mercado Livre 99.9%" | fora | Não há medição de disponibilidade |
| "412 SKUs" | "148 produtos" | "SKU" como unidade é jargão; e o número vem da **linha viva**, nunca do localStorage |
| Dropdowns ERP e Setor | fora | Sem dado para filtrar sem inventar |
| "Mais Recente" | Nome (A–Z) / Mais produtos / Mais anúncios / Prontos primeiro | Não há data de acesso para ordenar |

## Desvio que vale ler

**`PainelVisaoGeralService::integracoes()` passou a usar a mesma estática** (fora da lista do
plano). A Visão geral tinha a **própria** resolução de ERP, lendo só o JSON da ficha; com a lista
lendo também a coluna `erp` da planilha de Polos, a MESMA conta poderia mostrar "Tiny" na lista e
"Não informado" na Visão geral. Agora as duas chamam `ProgramasPublicadorService::erpDeclarado()`.
Efeito colateral bem-vindo: o painel passou a mostrar o texto livre do "Outro" em vez da palavra
"Outro".

## Três bugs achados pelo caminho

1. **`empresas: null` derrubava a tela** — o default `= []` só cobre `undefined`, e o `useState`
   lia `filtros.busca` antes de qualquer guarda. Valia também para `paginacao`, `indicadores` e
   `programas`.
2. **Campos da linha renderizados crus** (`{e.nome}`, `{e.identificador}`) — um objeto ali é a
   tela preta de 07/10. Tudo passa por `textoSeguro`/`numeroSeguro` agora.
3. **ERP com duas implementações divergentes** (o desvio acima).

## Armadilha nova de teste, registrada

O arquivo montava o **segundo** bundle depois de registrar o primeiro bloco de testes. O
`node --test` dispara o `after()` quando os testes já registrados acabam — e o `after()` apagava
os stubs no meio do segundo `esbuild`. O arquivo inteiro morria com `'test failed'` **sem teste
nenhum falhando**, e **só na suíte completa**. Correção: montar todos os bundles **antes** do
primeiro `test()`. Vale para qualquer teste futuro com mais de um bundle.

## Pendências

- Busca global ⌘K e pílula de contexto do topo (transversais, tarefa própria).
- "Conectar nova empresa" — espera o destino (`comercial.empresas.novo` × `implementacao.conectar-ml`).
- MELI ID na linha — entraria como mais uma chave aditiva, se o usuário quiser.
- Conferência visual no navegador — do usuário.
