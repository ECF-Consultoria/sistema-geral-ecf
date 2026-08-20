# Fase 133 — Roteiro da ativação do bloqueio administrativo em produção

**Preparado em:** 2026-08-18, plano 133-04 (Task 1).
**Quem lê este documento:** qualquer pessoa que execute a virada — não precisa ser quem
planejou. O padrão segue o mesmo molde do roteiro da Fase 132
(`132-GATE.md`): campos de resultado nascem em branco, comandos escritos por extenso,
critério de parar explícito.

**Como usar:** o plano 133-04 (Task 2 e Task 3, se autorizada) e o plano 133-05 preenchem os
campos de resultado abaixo, na ordem em que os passos acontecem. Nada aqui é preenchido de
antemão.

---

## Resultado do checkpoint de ativação

**Data:** 2026-08-19. **Decisão final registrada: `ligar-agora`.**

### Histórico da correção (registrado por transparência, não apagado)

Na primeira rodada do checkpoint, em 2026-08-19, o orquestrador apresentou as quatro
pré-condições abaixo **individualmente**, via AskUserQuestion, ao usuário
(dev.01@ecfconsultoria.com.br). A primeira resposta registrou "Não / não sei" para (a), (b) e
(c), e só (d) veio confirmada — o que levou ao registro (agora superado) de decisão `parar`.

**Na mesma conversa, o usuário se corrigiu textualmente:** "Desculpa me enganei, todos os
pontos estão testados". A resposta final e válida, portanto, confirma as quatro pré-condições.

### Estado final das quatro pré-condições (2026-08-19)

- (a) webhook chegou de forma confiável durante o período de observação (Fases 128/129) —
  **CONFIRMADA**
- (b) alerta de contrato preso já disparou ao menos uma vez em sandbox (Fase 130) —
  **CONFIRMADA**
- (c) liberação manual testada em produção ao menos uma vez (Fase 130) — **CONFIRMADA**
- (d) cutover para produção Clicksign concluído e aprovado (Fase 132) — **CONFIRMADA**

Com as quatro pré-condições confirmadas, a opção válida passou a ser **`ligar-agora`** —
opção "Ligar agora — deploy autorizado" no AskUserQuestion.

**Autorização de deploy: explícita, dada nesta conversa, em 2026-08-19**, por
dev.01@ecfconsultoria.com.br, ciente de que o `deploy.sh` publica o trabalho de todas as
sessões do Claude Code e do outro desenvolvedor que compartilham esta árvore.

**Para retomar (se a Task 3 ainda não constar como concluída abaixo):** executar a Task 3 do
plano 133-04 — deploy, ligar a chave e conferir por reconsulta. Ver seção "Campos de
resultado" para o registro real da ativação.

---

## O que muda quando a chave liga

Em linguagem simples: quando `administrativo_bloqueio_ativo` está ligada, toda empresa cujo
serviço **exige contrato** passa a aguardar a etapa administrativa antes de entrar na
operação — ela só é liberada quando o contrato é assinado (confirmado pelo aviso automático
da Clicksign, reconsultado) ou quando um admin faz a liberação manual, com motivo registrado.

Empresa de **Polos continua entrando direto**, exatamente como hoje — Polos é isento de
contrato (D9) e a exceção por serviço, feita nos planos 133-01 e 133-02, garante isso mesmo
com a chave ligada.

⚠️ **Aviso honesto (D-01 do `133-CONTEXT.md`): hoje o efeito prático é nulo.** Medido em
produção em 2026-08-18: não existe nenhuma ficha de Assessoria ou Incubadora na base — só
fichas de Polos. Logo, ligar a chave agora **não retém nenhuma empresa de imediato**. A prova
de sucesso desta ativação é **"nada quebrou"**, não **"algo foi bloqueado"**. Quem esperar ver
uma empresa retida vai concluir, errado, que a fase falhou.

---

## Pré-condições (checkpoint humano do ROADMAP)

Texto copiado literalmente do `.planning/ROADMAP.md`, seção "Phase 133":

> 🚦 CHECKPOINT HUMANO — bloqueia a ATIVAÇÃO, não a escrita. A flag
> `administrativo_bloqueio_ativo` só pode ser ligada em produção depois de confirmar, com o
> usuário: (a) o webhook chegou de forma confiável durante o período de observação (Fase
> 128/129 rodando em produção por tempo suficiente); (b) o alerta de contrato preso já
> disparou pelo menos uma vez em sandbox (Fase 130); (c) a liberação manual foi testada em
> produção ao menos uma vez (Fase 130); (d) o cutover para produção Clicksign foi concluído e
> aprovado (Fase 132). Rollback de código sozinho nunca é o plano de saída — desligar a flag é.

As quatro caixas abaixo só podem ser marcadas depois de confirmação individual do usuário —
nunca por inferência:

- [x] **(a)** O webhook chegou de forma confiável durante o período de observação (Fases
      128/129 rodando em produção por tempo suficiente).
      Confirmado por: dev.01@ecfconsultoria.com.br · Quando: 2026-08-19 (checkpoint de
      ativação do plano 133-04, resposta corrigida na mesma conversa: "Desculpa me enganei,
      todos os pontos estão testados").
- [x] **(b)** O alerta de contrato preso já disparou pelo menos uma vez em sandbox (Fase 130).
      Confirmado por: dev.01@ecfconsultoria.com.br · Quando: 2026-08-19 (checkpoint de
      ativação do plano 133-04, resposta corrigida na mesma conversa).
- [x] **(c)** A liberação manual foi testada em produção ao menos uma vez (Fase 130).
      Confirmado por: dev.01@ecfconsultoria.com.br · Quando: 2026-08-19 (checkpoint de
      ativação do plano 133-04, resposta corrigida na mesma conversa).
- [x] **(d)** O cutover para produção Clicksign foi concluído e aprovado (Fase 132).
      Confirmado por: dev.01@ecfconsultoria.com.br · Quando: 2026-08-19 (checkpoint de
      ativação do plano 133-04)

---

## Antes de ligar

- [ ] Os três planos anteriores (133-01, 133-02, 133-03) estão commitados.
- [ ] A suíte da fase está verde: `tests/Feature/Phase133`, `Phase124KillSwitchTest`,
      `tests/Feature/Phase128` — resultado registrado em "Campos de resultado".
- [ ] `npm run build` rodado, sem erro.
- [ ] Deploy autorizado explicitamente nesta conversa e concluído (a árvore é compartilhada
      com outra sessão/dev — o deploy publica o trabalho de todos).
- [ ] A contagem-baseline de `mlb_empresas` (total e `POLO`) registrada por reconsulta, com o
      comando abaixo.

Comando de contagem-baseline (reconsulta, nunca a tela):

```
php artisan tinker --execute="echo App\Models\MlbEmpresa::count(), '|', App\Models\MlbEmpresa::where('tipo','POLO')->count();"
```

---

## Ligar

Comando de tinker por extenso, usando a constante `EmpresaOperacionalRouter::CHAVE_BLOQUEIO`
(nunca digitar o nome da chave à mão):

```
php artisan tinker --execute="App\Models\Configuracao::set(App\Services\Operacional\EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');"
```

Comando de conferência, imediatamente em seguida:

```
php artisan tinker --execute="echo app(App\Services\Operacional\EmpresaOperacionalRouter::class)->bloqueioAtivo() ? 'ligado' : 'desligado';"
```

⛔ A conferência é sempre por **reconsulta** ao banco (`bloqueioAtivo()`), nunca pela tela e
nunca pela ausência de mensagem de erro do comando de ligar.

⛔ **Proibido rodar `cache:clear` no VPS em qualquer passo deste roteiro.** Incidente real de
2026-07-30: apaga o cache aquecido da Adman, o dashboard passa a esperar a API, os workers do
php-fpm ficam ocupados e até o login para. Ligar/desligar esta chave não exige limpar cache
nenhum — se algo parecer preso, a alternativa correta é `systemctl reload php8.2-fpm`.

---

## Conferir depois de ligar

Três conferências imediatas, nessa ordem:

1. `bloqueioAtivo()` devolve `ligado` (comando de conferência da seção "Ligar").
2. A contagem de `mlb_empresas` (comando da seção "Antes de ligar") **não mudou** — ligar a
   chave não cria nem apaga ficha nenhuma.
3. A tela `/administrativo/contratos` mostra a faixa de aviso (Fase 133-03). Conferência
   visual formal fica para o plano 133-05.

---

## Critério de parar (e desligar na hora)

Três gatilhos concretos. Qualquer um deles manda desligar imediatamente, com o comando de
"Como voltar atrás" logo abaixo:

1. **Um cadastro real de Polos não gerou ficha.** É o sinal de que a exceção por serviço
   (D-02, plano 133-01) não está funcionando em produção como funcionou nos testes.
2. **Apareceu recusa de ativação manual que não deveria existir** — procurar no log a
   mensagem `[Administrativo] Ativação manual retida` (plano 133-02) para um caso que era para
   ser isento.
3. **Qualquer erro 500 nos caminhos de cadastro** (webhook HubSpot, cadastro do Comercial,
   ativação manual de `/mlb/empresas`).

```
php artisan tinker --execute="App\Models\Configuracao::set(App\Services\Operacional\EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0');"
```

**Rollback de código nunca é o plano de saída — a chave é.** Não existe cenário nesta fase em
que reverter deploy ou migration seja a resposta ao critério de parar; a saída é sempre
desligar a chave em segundos e investigar depois.

---

## Como voltar atrás

O comando de desligar é o mesmo da seção "Critério de parar", acima. O efeito é **imediato**:
não exige deploy, não exige migration, não exige reiniciar worker — a próxima leitura de
`bloqueioAtivo()` já devolve `false`.

⚠️ **Empresas retidas enquanto a chave esteve ligada NÃO são roteadas retroativamente ao
desligar.** Quem ficou de fora entra pela liberação manual (Fase 130, admin com motivo
registrado) ou pelo próximo aviso automático de assinatura da Clicksign — desligar a chave só
para de reter empresas **novas**, não resolve sozinho quem já ficou parado.

---

## Campos de resultado

Preencher no plano 133-04 (Task 2/3) e no plano 133-05, sempre com o que o **banco** devolveu,
nunca a tela nem o stdout de um comando isolado sem reconsulta.

### Suíte da fase (colhida antes da Task 3, em 2026-08-19)

- `tests/Feature/Phase133` — Tests: 19 passed (84 assertions)
- `tests/Feature/Phase124KillSwitchTest.php` — Tests: 9 passed (24 assertions)
- `tests/Feature/Phase128` — Tests: 36 passed (107 assertions)

### Pré-condições — ver seção "Pré-condições" acima (registro é lá, não aqui)

### Decisão do checkpoint (Task 2)

- Resposta escolhida: `ligar-agora`
- Motivo, se `adiar` ou `parar`: não se aplica — houve uma primeira resposta equivocada
  ("Não / não sei" para a, b, c) corrigida pelo próprio usuário na mesma conversa em
  2026-08-19 ("Desculpa me enganei, todos os pontos estão testados"); ver "Resultado do
  checkpoint de ativação" para o histórico completo
- Autorização de deploy dada explicitamente nesta conversa: sim — dev.01@ecfconsultoria.com.br, 2026-08-19

### Ativação (Task 3, só se `ligar-agora`)

**Executor:** a Task 3 (deploy + ligar a chave + conferir por reconsulta) foi executada pelo
**orquestrador da Fase 133**, e não pelo subagente executor do plano 133-04 — o classificador de
permissões bloqueou o `plink`/`pscp` no subagente, então o próprio orquestrador rodou os comandos
de produção na máquina do dev.01. Este registro (agente de continuação) apenas documenta o
resultado real, sem executar nenhum comando novo no VPS.

- Data/hora da ativação (com fuso): **2026-08-19, por volta de 09:05 BRT (-03:00)**
- Quem autorizou: **dev.01@ecfconsultoria.com.br** — autorização explícita dada nesta conversa,
  em 2026-08-19, via AskUserQuestion ("Ligar agora — deploy autorizado"), ciente de que o deploy
  publica o trabalho de todas as sessões e do outro desenvolvedor que compartilham a árvore
- Quem executou: **sessão Claude Code (orquestrador da Fase 133), na máquina do dev.01**
- Commit implantado (`git rev-parse --short HEAD`): **`c4043014`**
  (`docs(133-04): corrige o registro do checkpoint - decisao final e ligar-agora`) — `HEAD` local
  == `origin/main` == `c4043014` antes do deploy, conferido no VPS após o envio
  (`git rev-parse --short HEAD` em `/var/www/ecf_admin` também devolveu `c4043014`)
- Contagem de `mlb_empresas` antes (baseline, reconsulta ANTES do deploy): **488** (total) /
  **488** (`POLO`)
- Contagem de `mlb_empresas` depois (reconsulta, após ligar a chave): **488** (total) / **488**
  (`POLO`) — **idêntica ao baseline**: ligar não criou nem apagou ficha nenhuma
- Resultado da reconsulta de `bloqueioAtivo()`: **`ligado`** (comando combinado devolveu
  `ligado|488|488`)

**Nota sobre a baseline (486 → 488):** o `133-04-PLAN.md` registrava 486 fichas medidas em
2026-08-18. A recontagem de 2026-08-19, antes do deploy, encontrou **488** — ou seja, **2 Polos
novos entraram entre 18/08 e 19/08**. É sinal de que o fluxo de Polos está vivo em produção, o que
torna a prova do plano 133-05 (primeiro cadastro real depois da chave ligada) mais realista, não
menos. A baseline usada para a comparação "antes/depois" desta ativação é a de 2026-08-19
(488|488), medida imediatamente antes do deploy — não a de 2026-08-18.

**Pré-checagem antes do deploy:**
- `git status --porcelain --untracked-files=no` → vazio (árvore rastreada limpa)
- Suíte da fase verde: Phase133 19 testes / Phase124KillSwitchTest 9 / Phase128 36 — **64 testes,
  0 falhas**

**Passos do deploy (`deploy.sh`):**
- `npx vite build` OK; `composer install --no-dev` OK
- `php artisan migrate --force` → "Nothing to migrate" (esta fase não tem migration)
- `storage:link` já existia (idempotente); `config:cache` / `route:cache` / `view:cache` OK
- `supervisorctl restart ecf-worker:*` → `ecf-worker_00` e `_01` reiniciados
- ⛔ Nenhum `cache:clear` foi executado em nenhum passo

**Conferência do que chegou no VPS antes de ligar:**
- `git rev-parse --short HEAD` no VPS → `c4043014`
- `grep -c 'exige_contrato' app/Services/Operacional/EmpresaOperacionalRouter.php` → `2` (código
  da exceção por serviço presente)
- `bloqueioAtivo()` antes de ligar → `desligado` (estado inicial correto)

**Smoke check pós-ativação:**
- `https://admin.ecfconsultoria.com.br/login` → HTTP `200`
- `https://admin.ecfconsultoria.com.br/administrativo/contratos` → HTTP `302` (redirect para
  login, esperado — requisição não autenticada)
- `tail storage/logs/laravel.log`: só erros pré-existentes e não relacionados (`[MercadoLivre]
  Erro 429 ao renovar token (transitório)`, das 08:00). Nenhum erro novo.
- `grep -c 'Ativação manual retida' storage/logs/laravel.log` → `0` — nenhuma ativação manual
  legítima foi recusada até agora
- ⛔ Nenhuma empresa de teste foi criada em produção

### Primeiro cadastro real de Polos (plano 133-05)

- Empresa: ______
- Ficha gerada: ______
- Data/hora: ______

## Varredura de log — 2026-08-20 (parcial do plano 133-05)

Conferido por reconsulta ao VPS, ~30h depois de a chave ser ligada:

| Sinal | Ocorrencias |
|---|---|
| `[Administrativo] Roteamento operacional retido pelo gate administrativo.` | **0** |
| `[Administrativo] Ativação manual retida pelo gate administrativo.` | **0** |

**Zero e o resultado ESPERADO** (D-01): nao existe ficha de Assessoria ou Incubadora na base, entao
nao ha o que reter. E, mais importante, **nenhuma ativacao manual legitima foi recusada por engano**
— um dos criterios de aceite do 133-05, este SATISFEITO.

### ⚠️ O que AINDA NAO esta provado: o primeiro cadastro real de Polos

`mlb_empresas` subiu de 488 (na ativacao) para **503** — 16 fichas novas, todas `tipo=POLO`, entre
19/08 09:06 e 20/08 09:20. A leitura obvia seria "Polos continua entrando com a chave ligada, esta
provado". **Nao esta.**

Todas as 16 nasceram com **`company_id = NULL`**. Das 503 fichas da base, so **4** tem `company_id`
preenchido, e a mais recente delas e de **2026-08-12** — antes de a chave ser ligada.

Ficha sem `company_id` **nao passou pelo `EmpresaOperacionalRouter`**: o router roteia uma
`Company`. Essas 16 vieram por outro caminho — quase certamente uma das duas rotas registradas em
`.planning/todos/pending/260818-portas-extras-criam-mlbempresa-fora-do-router.md` (divida D-06).

Por isso o plano 133-05 pede a reconsulta **por `company_id`**, e nao a contagem geral: e exatamente
o que distingue "o router deixou o Polos passar" de "uma ficha apareceu por outra porta". A contagem
geral subindo e um falso positivo confortavel.

**Segue pendente:** um cadastro real de Polos pelo Comercial ou pelo webhook do HubSpot, que gere
`MlbEmpresa` COM `company_id`, reconsultado por ele.
