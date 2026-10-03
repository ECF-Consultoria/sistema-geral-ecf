---
quick: 261003-l8o
subsystem: creative-engine
tags: [mlb, anunciar-ml, creative-engine, prompt-builder, rate-limiter, ux]
requires: [Fase 161 em produção]
provides: [variação obrigatória na regeneração, UI de um botão com confirmação de custo, 429 em pt-BR]
affects: [app/Services/Creative/CreativePromptBuilder.php, app/Jobs/GerarCriativoIaJob.php, app/Http/Controllers/MlbAnuncioController.php, app/Providers/AppServiceProvider.php, routes/mlb_anuncios.php, resources/js/Pages/Mlb/components/PainelCriativosIa.jsx, resources/js/Pages/Mlb/components/KitCriativosGrade.jsx]
tech-stack:
  added: []
  patterns: ["bloco de prompt ensanduichado entre regras MASTER e regras de fato", "histórico aditivo em coluna json (nunca sobrescrita)", "limitador nomeado com response() em pt-BR"]
key-files:
  created:
    - database/migrations/2026_10_03_170000_add_regenerar_motivos_to_ml_anuncio_criativos_table.php
    - tests/Unit/Quick261003L8o/CreativePromptBuilderVariacaoTest.php
    - tests/Feature/Quick261003L8o/RegeneracaoComVariacaoTest.php
    - tests/Feature/Quick261003L8o/LimiteDeCustoEmPtBrTest.php
  modified:
    - app/Models/MlAnuncioCriativo.php
    - app/Services/Creative/CreativePromptBuilder.php
    - app/Jobs/GerarCriativoIaJob.php
    - app/Http/Controllers/MlbAnuncioController.php
    - app/Providers/AppServiceProvider.php
    - routes/mlb_anuncios.php
    - resources/js/Pages/Mlb/components/PainelCriativosIa.jsx
    - resources/js/Pages/Mlb/components/KitCriativosGrade.jsx
decisions:
  - "Bloco de variação/ajuste do operador entra DEPOIS de CENA e ANTES de TEXTO no prompt — MASTER acima, FATOS/CONTAGENS/CLAIMS abaixo, nunca o inverso"
  - "regenerar_motivos é histórico aditivo (array), nunca sobrescrita — cada clique soma uma entrada, mesmo sem texto"
  - "Três limitadores nomeados por usuário (fallback IP), não por IP puro — rotas já são role:admin"
metrics:
  duration: "~2h"
  completed: "2026-10-03"
---

# Quick 261003-l8o: Correções de uso real do Creative Engine — Summary

Motor de regeneração ganhou variação obrigatória de prompt (eixo rotativo + ajuste opcional do
operador, sanitizado e nunca tratado como fato), a tela do publicador passou a ter um único botão
com confirmação de custo antes de gastar cota, e o teto de requisições do Creative Engine agora
responde em português dizendo quanto esperar.

## O que foi corrigido (e por quê)

Quatro problemas relatados pelo usuário em produção em 2026-10-03, depois de testar a Fase 161:

1. **Dois botões de gerar confundiam o operador.** O caminho de UI de "Gerar imagem com IA" (fluxo
   de 1 imagem, Fase 160) foi removido do painel — o endpoint `criativo.gerar` e o
   `GerarCriativoIaJob` continuam vivos (são o motor do kit e da regeneração), só não têm mais
   botão. A área de acompanhar/aprovar um criativo de 1 imagem que já estivesse em andamento ANTES
   deste deploy foi mantida de propósito, para não ficar órfã sem como ser aprovada.

2. **DEFEITO real: regenerar devolvia praticamente a mesma imagem.** Confirmado no código:
   `criativoRegenerar()` redisparava com o MESMO `slot_plano` e as MESMAS fotos (Decisão 10 da Fase
   161) — prompt idêntico produz imagem praticamente idêntica. A correção foi injetar um bloco
   `VARIAÇÃO OBRIGATÓRIA` no PROMPT (nunca no plano do slot nem nas fotos), com um eixo que troca a
   cada clique consecutivo (enquadramento → ângulo → composição → repete), sempre acompanhado da
   linha de fidelidade que proíbe variar o PRODUTO. Um campo opcional "o que não ficou bom?" deixa o
   operador escrever uma preferência visual, que entra sanitizada e com teto de 300 caracteres,
   cercada por um bloco de contenção que diz explicitamente que aquele texto NÃO é fato sobre o
   produto.

3. **Fluxo de planejar/gerar confuso, sem saber se já gastava cota.** O botão único do painel
   ("Gerar as 7 imagens") agora só faz a chamada de TEXTO de planejamento (não gasta cota de
   imagem) e, depois de montado o plano, a `KitCriativosGrade` mostra um bloco de confirmação com o
   custo em dólar calculado (`slots × US$ 0,101`) antes do clique que de fato gera as imagens.
   "Agora não" só esconde a pergunta — não cancela nem apaga o kit; clicar de novo no botão do
   painel traz a pergunta de volta.

4. **O 429 do throttle aparecia em inglês, sem dizer quanto esperar.** Três limitadores nomeados
   (`creative-kit-planejar`, `creative-kit-gerar`, `creative-regenerar`) substituíram os
   `throttle:N,1` anônimos. A resposta agora é sempre pt-BR, no formato que o front já lê
   (`erros[0].mensagem`), nomeando a ação e os segundos até poder tentar de novo, com o header
   `Retry-After` preservado. Nenhum teto de CUSTO real foi alterado — `max_imagens` e os tetos de
   regeneração por asset/kit continuam exatamente os mesmos.

### Não é defeito — registro obrigatório

O usuário montou um anúncio com título de **CADERNO** e subiu 3 fotos de um **GABINETE**; a imagem
saiu com um caderno desenhado como armário. **Não há conserto a fazer aqui**: o sistema obedeceu
corretamente as duas fontes de verdade que tinha (o título do cadastro e as fotos de referência), e
nenhuma das duas é autoridade sobre a outra hoje. Quem vai pegar essa divergência é o **validador
automático da Fase 162** (compara imagem gerada × fotos originais × Product Truth, requisitos
VAL-*). Este plano não tenta resolver esse caso — é a razão de a correção não existir aqui.

## Tasks e commits

| Task | Nome | Commit |
|---|---|---|
| 1 | Variação obrigatória na regeneração + campo opcional do operador | `85100863` |
| 2 | Um botão só com confirmação de custo + fim do botão de 1 imagem | `05afabc8` |
| 3 | Teto de custo que fala português e diz quanto esperar | `41787915` |

## Decisões tomadas

- **Posição do bloco do operador no prompt**: depois de `CENA:`, antes de `TEXTO` — o MASTER fica
  acima e `TEXTO`/`FATOS PERMITIDOS`/`CONTAGENS`/`CLAIMS PROIBIDAS` ficam abaixo. As regras de
  fidelidade são a primeira e a última palavra do prompt; o texto do operador fica ensanduichado.
- **Coluna de histórico, não de estado**: `regenerar_motivos` (json nullable, aditiva) acrescenta
  uma entrada por clique, nunca sobrescreve — é o que impede o texto de uma regeneração anterior de
  vazar para a próxima e o que torna a métrica de motivos do §19 exata. Nunca entra na whitelist de
  `criativo.kit.status`.
- **Números novos dos limitadores**: `creative-kit-planejar` 6→12/min (chamada mais barata, todo
  clique do botão único passa por ela, inclusive os idempotentes); `creative-kit-gerar` 3→4/min
  (cobre o clique de confirmação depois de um "Agora não"); `creative-regenerar` mantém 12/min (só
  a mensagem mudou). Chave por usuário (fallback IP) — todas as rotas já são `role:admin`.
- **Botão único reaparece** quando o operador recusa a confirmação de custo: a idempotência do
  servidor (`planejarKitSobLock`, devolve o mesmo token) permite reusar o mesmo clique do painel
  como "trazer a pergunta de volta", sem criar um segundo kit.

## Desvios do plano

Nenhum desvio de Rule 1-4. Um ajuste de dois testes feito DURANTE a própria task, documentado aqui
por transparência:

- **[Ajuste de teste] `grep -c enum` na migration nova.** O docblock original explicava a decisão
  de não usar `enum` citando a palavra "enum" dentro do próprio comentário — isso fazia o grep de
  verificação (`grep -c enum` esperando 0) encontrar 1 ocorrência, mesmo sem nenhuma coluna `enum`
  de verdade. Reescrita a frase para "sem lista fixa de valores" sem perder a explicação.
- **[Ajuste de teste] falso-positivo de unicode no teste de ausência do id do kit.** O teste que
  prova a mensagem do 429 não vaza o id do kit comparava contra `json_encode($resposta->json())`
  inteiro; `json_encode` escapa acentuação em sequências `\uXXXX` (ex.: "—" vira `—`), que por
  acidente CONTÊM o dígito do id de teste (`1`). Corrigido para comparar contra a string da
  mensagem já decodificada (`$resposta->json('erros.0.mensagem')`), que é o que de fato importa.

Nenhum dos dois ajustes mudou código de produção — só a precisão dos próprios testes.

## Resultados de verificação (literais)

```
tests/Unit/Quick261003L8o:                                           OK (8 tests, 32 assertions)
tests/Feature/Quick261003L8o/RegeneracaoComVariacaoTest:              OK (4 tests, 26 assertions)
tests/Feature/Quick261003L8o/LimiteDeCustoEmPtBrTest:                 OK (4 tests, 25 assertions)

Suíte pinada (Phase160 + Phase161 + GeminiImageProviderTest):         OK (159 tests, 682 assertions)
--filter=Criativo:                                                     OK (116 tests, 538 assertions)
--filter="UploadImagem|AnuncioIaAnalise|MeusAnuncios|RascunhosMeusAnuncios":
                                                                        OK (48 tests, 163 assertions)

grep -c "enum" migration nova:                                         0
grep -cE 'public $queue|protected $queue' GerarCriativoIaJob.php:       0
grep -c "onQueue('creative')" GerarCriativoIaJob.php:                   1
grep -v comentário | grep -c "regenerar_motivos" controller:            2
migrate --pretend (2x seguidas): mesmo SQL nas duas rodadas —
  alter table `ml_anuncio_criativos` add `regenerar_motivos` json null after `regeneracoes`

npm run build:                                                          sucesso (13m4s, sem erro)
git diff --stat resources/js/Pages/Mlb/AnunciarML.jsx:                  vazio
git diff --stat composer.json composer.lock package.json package-lock.json: vazio
grep -rn "payload" app/Services/Creative/ | grep -v CreativeContextBuilder:
                                                                         6 (todas em CreativeKitPublicacao.php, pré-existentes, fora do escopo deste plano)
route:list --name=mlb.anuncios.criativo:                               11 rotas (mesmas de antes)

PainelCriativosIa.jsx: "criativo.gerar" = 0, "function gerar(" = 0, "Gerar as 7 imagens" = 1
routes/mlb_anuncios.php: "criativo.gerar" = 1 (motor vivo), "throttle:creative-" = 3,
  "throttle:6,1|throttle:3,1" = 0 (sobrou só o `throttle:6,1` de `criativo.gerar`, não tocado)
KitCriativosGrade.jsx: onMotivoChange/CUSTO_POR_IMAGEM_USD/onCancelarConfirmacao presentes
```

## Self-Check

- `database/migrations/2026_10_03_170000_add_regenerar_motivos_to_ml_anuncio_criativos_table.php` — FOUND
- `tests/Unit/Quick261003L8o/CreativePromptBuilderVariacaoTest.php` — FOUND
- `tests/Feature/Quick261003L8o/RegeneracaoComVariacaoTest.php` — FOUND
- `tests/Feature/Quick261003L8o/LimiteDeCustoEmPtBrTest.php` — FOUND
- commit `85100863` — FOUND
- commit `05afabc8` — FOUND
- commit `41787915` — FOUND

## Self-Check: PASSED

## Known Stubs

Nenhum. Todas as três correções e a cobertura de UI foram implementadas de ponta a ponta (backend +
frontend + testes), sem dado mockado nem caminho decorativo.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano (T-L8O-01 a T-L8O-SC) — todas as
mitigações planejadas (sanitização em três camadas do texto do operador, whitelist do
`criativo.kit.status`, campo único aceito do corpo, log sem texto/prompt, limitadores por usuário)
foram implementadas e cobertas por teste.
