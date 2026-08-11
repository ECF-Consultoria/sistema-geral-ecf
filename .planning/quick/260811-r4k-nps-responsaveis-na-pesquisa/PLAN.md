---
tipo: quick
slug: nps-responsaveis-na-pesquisa
criado_em: 2026-08-11
status: em-execucao
---

# Quick: mostrar Estrategista e Analista na pesquisa NPS

## Problema

Quando o link do NPS chega ao cliente, ele não sabe **quem** é o Estrategista e o
Analista responsáveis por aquela pesquisa. As perguntas falam de "Meu Estrategista"
e "Meu Analista" de forma genérica — o cliente avalia pessoas que não estão
nomeadas em lugar nenhum da tela.

## Por que na página da pesquisa (e não no texto do envio)

O disparo automático mensal foi DESLIGADO em 2026-07-29 (`routes/console.php`,
Fase 119.1 D2). Hoje o link nasce manualmente em `/nps` e alguém copia e envia
ao cliente pelo canal que quiser (WhatsApp à mão, e-mail, chat). O e-mail
`NpsMonthlyMail` só sai quando alguém invoca `nps:disparar-mensal` na unha —
o último envio registrado é de 2026-07-16.

Logo, o único artefato que **sempre** chega ao cliente é a própria página da
pesquisa. Colocar os nomes ali cobre 100% dos envios, independente do canal.

## Escopo

`resources/js/Pages/Nps/Respond.jsx` — fluxo v15 (`RespondV15`), único usado por
surveys com template (todos os modelos ativos em produção têm template).

Card "Quem cuida da sua conta" entre o cabeçalho de marca e o card de intro,
com avatar de iniciais + rótulo do papel + nome.

## Dados — já existem, nada de backend

`NpsController::respond()` já envia no payload Inertia:

- `survey.estrategista_name` (string|null)
- `survey.analista_name` (string|null)
- `survey.tem_analista` (bool)

Nenhuma query nova, nenhuma mudança de controller.

## Degradação

- **Mentoria pura** (empresa sem analista): `tem_analista=false` → só o
  Estrategista aparece.
- **Link de grupo** (`NpsGrupoController::respond`): manda os três campos como
  `null`/`false` de propósito, porque um NPS de grupo cobre várias empresas e
  não tem responsável único → o card inteiro não renderiza. Comportamento
  correto, sem tratamento especial.
- **Survey legado** (sem template) → cai em `RespondLegado`, que já interpola os
  nomes nos textos das perguntas. Fora do escopo.

## Tarefas

1. Componente `ResponsaveisCard` em `Respond.jsx` (local ao arquivo, padrão da
   página — `BrandHeader`/`IntroCard` também são locais).
2. Render condicional dentro de `RespondV15`, antes do `IntroCard`.
3. `npm run build`.

## Verificação

- Cliente com estrategista + analista → dois blocos.
- Empresa de mentoria → só estrategista.
- Link de grupo → card ausente, tela idêntica à de hoje.
