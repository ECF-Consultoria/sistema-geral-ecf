---
tipo: quick
slug: nps-responsaveis-na-pesquisa
criado_em: 2026-08-11
concluido_em: 2026-08-11
status: complete
---

# SUMMARY — Estrategista e Analista visíveis na pesquisa NPS

## O que foi entregue

Card **"Quem cuida da sua conta"** no topo do formulário público da pesquisa NPS
(`Nps/Respond.jsx`, fluxo v15), com avatar de iniciais, rótulo do papel e nome do
Estrategista e do Analista.

Uma única alteração de frontend. **Nenhuma mudança de backend** — os três campos
(`estrategista_name`, `analista_name`, `tem_analista`) já viajavam no payload
Inertia desde a Phase 31 e simplesmente não eram exibidos no redesenho v15
(2026-07-08), que só aproveitou `company_name`.

## Descoberta que definiu ONDE colocar

A pergunta era "mostrar no e-mail ou na página?". A medição respondeu:

1. **O disparo automático mensal está desligado desde 2026-07-29** (Fase 119.1
   D2 — `routes/console.php` não agenda mais `nps:disparar-mensal`). Hoje o link
   nasce manualmente em `/nps` e o responsável o envia pelo canal que quiser.
2. **O canal Digisac/WhatsApp está OFF em produção** (`nps_envio_digisac_ativo='0'`).
3. **O último e-mail NPS registrado é de 2026-07-16** — o `NpsMonthlyMail` só sai
   quando alguém invoca o comando na unha.

Conclusão: o texto do envio não é um lugar confiável para essa informação, porque
na prática não existe um "texto de envio" padronizado. O único artefato que
sempre chega ao cliente é a **página da pesquisa**. Por isso os nomes vivem lá.

## Bug pré-existente encontrado e NÃO corrigido (fora de escopo)

`configuracoes.nps_textos.email_corpo` em produção está com:

```
Seu estrategista é **{nome_estrategista}**{nome_analista}.
```

Alguém trocou o placeholder `{bloco_analista}` (que renderiza
`" e o analista é **Nome**"`) por `{nome_analista}` (que renderiza só o nome
cru). Efeito no e-mail: `Seu estrategista é **João Silva**Maria Souza.` — os
asteriscos saem literais (o `NpsTextRenderer::renderHtml` faz `e()` + `nl2br`,
não interpreta markdown) e os dois nomes ficam grudados sem dizer qual é qual.

É correção de **conteúdo**, não de código: resolve-se editando o texto em
`/nps/configuracao`. Fica registrado aqui porque só apareceu ao investigar esta
tarefa e volta a doer se o disparo automático for religado.

## Comportamento por cenário (verificado em navegador real)

| Cenário | Resultado |
|---|---|
| Empresa com estrategista + analista | Dois blocos lado a lado (desktop), empilhados (mobile) |
| Mentoria pura (`tem_analista=false`) | Só o bloco do Estrategista |
| Link de GRUPO | Card ausente — `NpsGrupoController` manda os três campos nulos de propósito (NPS de grupo cobre várias empresas, não tem responsável único) |
| Survey legado (sem template) | Inalterado — cai em `RespondLegado`, que já interpola os nomes nos textos das perguntas |

## Como foi verificado

Não por leitura de código nem por grep no bundle. Foi levantado um survey de
teste no banco local, servido pelo Apache do XAMPP, e a página foi aberta em
Chrome headless via puppeteer com asserção no DOM (`QUEM CUIDA DA SUA CONTA`) +
screenshot em 900px e 390px, nos três cenários. Dados de teste removidos do banco
local ao final.

`php artisan test --filter=NpsRespondRenderTest` passa. A falha em
`Phase31NpsSubmitTest > generate cria survey com auto generated false` é
**pré-existente e sem relação**: o teste espera `expires_at` = +7 dias, mas a
regra virou "fim do mês corrente" em 2026-07-20.

## Arquivos

- `resources/js/Pages/Nps/Respond.jsx` — componente `ResponsaveisCard` + helper
  `iniciaisDe` + render condicional em `RespondV15`.

## Pendente

Deploy. Nada foi enviado à VPS.
