---
phase: 168-capa-alternada-entre-classico-e-premium-ambiente-brasileiro
plan: 02
subsystem: creative-engine
tags: [prompt, ambiente, mercado-livre, configuravel]
dependency-graph:
  requires: []
  provides:
    - "bloco AMBIENTE condicional por tipo de slot em CreativePromptBuilder"
    - "chave de configuração creative_ambiente_brasileiro_prompt"
  affects:
    - "app/Services/Creative/CreativePromptBuilder.php"
tech-stack:
  added: []
  patterns:
    - "Configuracao::get()/set() chave→valor, memoizado na instância (mesmo padrão de FechamentoRegraTabela::$memoria)"
key-files:
  created:
    - "tests/Unit/Phase168/CreativePromptBuilderAmbienteTest.php"
  modified:
    - "app/Services/Creative/CreativePromptBuilder.php"
decisions:
  - "Texto do bloco AMBIENTE fica como constante (AMBIENTE_BRASILEIRO_PADRAO) lida através de Configuracao::get(), não uma string fixa — recalibrável sem deploy (decisão travada pelo próprio PLAN.md)."
metrics:
  duration: "~45 min"
  completed: "2026-10-07"
---

# Fase 168 Plano 02: Ambiente brasileiro subentendido nos slots ambientados Summary

Bloco AMBIENTE condicional (`lifestyle`/`lifestyle_uso`/`composicao` apenas) injetado em
`CreativePromptBuilder::paraSlot()`, com texto configurável via `Configuracao` e proibição
explícita de bandeira/verde-amarelo/símbolo nacional/futebol — `hero`/`white_background` e o
catálogo de slots ficam byte-idênticos a antes.

## O que foi feito

**Task 1 (TDD completo — RED → GREEN):**

1. RED: criado `tests/Unit/Phase168/CreativePromptBuilderAmbienteTest.php` com 10 testes cobrindo
   AMB-01 (texto nos 3 slots), AMB-02 (proibição explícita), AMB-03 (hero/white_background
   intocados), AMB-04 (regressão de `elegiveis()`), configurabilidade via `Configuracao::set()`,
   off-switch (texto vazio) e memoização na instância. Rodado ANTES da implementação — 6 das 10
   falharam como esperado (RED confirmado), commit `a82ff773`.
2. GREEN: em `CreativePromptBuilder.php` — constante `AMBIENTADOS` (`lifestyle`,
   `lifestyle_uso`, `composicao`), constante `CHAVE_CONFIG_AMBIENTE`
   (`creative_ambiente_brasileiro_prompt`), constante heredoc `AMBIENTE_BRASILEIRO_PADRAO` com o
   texto aprovado pelo usuário, propriedade `$ambienteCache` para memoização, métodos privados
   `textoAmbiente()` (lê `Configuracao::get()` com fallback ao padrão, memoizado) e
   `linhasAmbiente(string $tipo)` (devolve `[]` fora de `AMBIENTADOS` ou quando o texto calibrado
   está vazio). Wiring em `paraSlot()` logo depois da linha `CENA:`, antes do bloco
   `linhasVariacao()`, no mesmo padrão condicional já usado para aquele bloco. Docblock de
   `paraSlot()` atualizado com o novo passo "(2a) AMBIENTE". `paraSlotHero()` e
   `CreativeSlotCatalog` não foram tocados. Commit `7748c1a5`.
3. Baseline do Creative Engine (`tests/Feature/Phase160/161/162/Quick261003L8o` +
   `tests/Unit/Phase160/161/162/Quick261003L8o`, 228 testes) rodado 3 vezes — ver "Deviations"
   abaixo sobre flakiness pré-existente de filesystem no Windows, não causada por esta mudança.

**Task 2 (checkpoint):** não executada por este agente — ver seção "Checkpoint" abaixo. Fica
pendente para quem conduzir a verificação com o usuário.

## Texto final do bloco AMBIENTE (para o usuário calibrar)

Texto padrão (`AMBIENTE_BRASILEIRO_PADRAO`, usado quando `Configuracao::get('creative_ambiente_brasileiro_prompt', ...)` não tem override):

```
Ambiente residencial contemporâneo, sem citar o nome de nenhum país: luz
quente e abundante de clima tropical entrando pela janela; pé-direito e
esquadrias de apartamento brasileiro; acabamentos e plantas comuns por
aqui, como costela-de-adão e jiboia, ao fundo, sem serem o foco da cena;
paleta de madeira clara com branco nos móveis e paredes.
```

Linhas de proibição explícita, sempre junto (fixas, não calibráveis por `Configuracao` — só o
texto descritivo acima é):

```
PROIBIDO: qualquer bandeira, verde-amarelo, símbolo nacional ou referência a futebol
na cena — o ambiente deve ser reconhecível sem citar ou simbolizar o país explicitamente.
```

No prompt final, o bloco aparece assim (exemplo para `lifestyle`):

```
AMBIENTE: Ambiente residencial contemporâneo, sem citar o nome de nenhum país: luz quente e abundante de clima tropical entrando pela janela; pé-direito e esquadrias de apartamento brasileiro; acabamentos e plantas comuns por aqui, como costela-de-adão e jiboia, ao fundo, sem serem o foco da cena; paleta de madeira clara com branco nos móveis e paredes.
PROIBIDO: qualquer bandeira, verde-amarelo, símbolo nacional ou referência a futebol
na cena — o ambiente deve ser reconhecível sem citar ou simbolizar o país explicitamente.
```

Para recalibrar sem deploy: `App\Models\Configuracao::set('creative_ambiente_brasileiro_prompt', '<novo texto>')`. Texto `''` (vazio) desliga o bloco inteiro.

## Deviations from Plan

### Auto-fixed Issues

Nenhum desvio de Rule 1/2/3/4 — plano executado como escrito. Um único ajuste de redação durante
o GREEN: a frase "madeira clara combinada com branco" (minha primeira tentativa) foi trocada por
"madeira clara com branco", para casar literalmente com a frase do requisito AMB-01 e do teste
(`assertStringContainsString`) — não é um desvio de regra, é a própria iteração normal do TDD
(RED → ajuste de redação → GREEN).

### Flakiness pré-existente encontrada (fora de escopo, documentada, não corrigida)

Durante a verificação do baseline de 228 testes do Creative Engine, 3 rodadas seguidas (sem
nenhuma mudança de código entre elas) produziram resultados diferentes: 1ª rodada com 3
testes falhando (`CriativoRetencaoTest` + 2 em `CriativoKitAprovacaoTest`), 2ª rodada 100% verde,
3ª rodada com 1 teste diferente falhando. Confirmado por isolamento (`--filter` rodando só os
testes que falharam, com e sem minha mudança em `CreativePromptBuilder.php`) que:
- Nenhum dos arquivos afetados referencia `CreativePromptBuilder`.
- Os mesmos testes passam isoladamente tanto com o código ANTES quanto DEPOIS desta mudança.
- As mensagens de erro são todas de filesystem (`FilesystemIterator` não encontra diretório,
  disco fake não encontra arquivo, contagem de `pictures` zerada) com paths misturando `/` e `\`
  — típico de disco fake do Laravel não isolado de forma confiável entre testes no Windows.

Documentado em `.planning/phases/168-capa-alternada-entre-classico-e-premium-ambiente-brasileiro/deferred-items.md` (não commitado, por instrução do executor desta sessão). Não é regressão desta task — não corrigido, por estar fora do escopo de `CreativePromptBuilder.php`.

## Checkpoint (Task 2) — NÃO executado por este agente

Este agente NÃO chama a API da Gemini em nenhuma circunstância. A Task 2 é um
`checkpoint:human-verify` com `gate="blocking"` que custa dinheiro real (~R$ 0,50-0,60) — a sessão
do orquestrador com o usuário deve conduzi-la. Resumo para quem conduzir:

**O que foi construído:** `CreativePromptBuilder` agora injeta, só nos slots `lifestyle`
(rótulo "Ambientação"), `lifestyle_uso` (rótulo "Em uso") e `composicao` (rótulo "Composição"), o
texto de ambiente brasileiro subentendido acima, com a proibição explícita de bandeira/
verde-amarelo/símbolo nacional/futebol. `hero` ("Imagem principal") e `white_background` ("Fundo
branco") continuam exatamente como antes desta fase.

**Qual slot regenerar, de qual kit:** no Publicador, abrir um produto que já tenha um kit de
criativos com os 7 slots gerados (ou gerar um novo, com autorização do usuário, custo R$ 3,40) e,
na grade do kit, clicar **"Regenerar"** em UM dos três slots ambientados — **Ambientação**
(`lifestyle`) é a recomendação mais direta para avaliar o ambiente, mas **Em uso**
(`lifestyle_uso`) ou **Composição** (`composicao`) servem igualmente. Custo estimado: ~R$ 0,50-0,60
(1/7 do kit), não R$ 3,40 (kit novo).

**O que olhar na imagem resultante:** cenário com luz quente, acabamentos/plantas claras típicas
de apartamento brasileiro (ex.: costela-de-adão, jiboia visíveis ao fundo, sem serem o foco),
paleta de madeira clara com branco — e a AUSÊNCIA de qualquer bandeira, cor verde-amarela
evidente, símbolo nacional ou referência a futebol. Não é necessário regenerar `hero` ou
`white_background` do mesmo kit para confirmar que nada mudou neles — o código não os toca
(confirmado por `git diff` vazio no diff de `paraSlotHero()`).

**Se o texto não agradar:** calibrar via
`php artisan tinker --execute="App\Models\Configuracao::set('creative_ambiente_brasileiro_prompt', '<novo texto>');"`
no VPS (pelo orquestrador, não por subagente) e regenerar o mesmo slot de novo para comparar.

Nenhuma resposta do usuário foi coletada por este agente — esta seção registra só o que precisa
ser verificado, não um resultado de verificação.

## Self-Check

- `app/Services/Creative/CreativePromptBuilder.php` — FOUND (modificado, commit `7748c1a5`)
- `tests/Unit/Phase168/CreativePromptBuilderAmbienteTest.php` — FOUND (criado, commit `a82ff773`)
- `.planning/phases/168-capa-alternada-entre-classico-e-premium-ambiente-brasileiro/deferred-items.md` — FOUND (criado, não commitado por instrução)
- Commit `a82ff773` (test RED) — FOUND em `git log`
- Commit `7748c1a5` (feat GREEN) — FOUND em `git log`
- `git diff app/Services/Creative/CreativeSlotCatalog.php` — vazio, confirmado
- `paraSlotHero()` — nenhuma alteração no corpo do método, confirmado por diff (só linha de
  contexto de hunk, não mudança real)

## Self-Check: PASSED
