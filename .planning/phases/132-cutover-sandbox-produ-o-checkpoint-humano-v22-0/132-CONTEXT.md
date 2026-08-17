# Phase 132: Cutover sandbox → produção (checkpoint humano) (v22.0) - Context

**Gathered:** 2026-08-17
**Status:** Ready for planning

<domain>
## Phase Boundary

Apontar o sistema para a Clicksign **de produção** de forma **checada por um humano**, não assumida
como "só trocar a URL". A partir daqui os contratos valem juridicamente e os clientes recebem
e-mails de verdade.

Inclui: conferência das variáveis de ambiente, cadastro da URL de webhook na conta de produção,
emissão do primeiro envelope real contra empresa controlada, confirmação do gate empírico #3, e a
aprovação explícita do usuário antes de qualquer contrato de cliente real.

**Fora desta fase:** ligar o bloqueio do roteamento (Fase 133), gerar contrato de cliente real.

⚠️ **Esta fase é quase toda humana.** Não tem REQ-ID novo. O trabalho de código é mínimo (ver D-01);
o resto é checklist, painel da Clicksign e julgamento.

</domain>

<decisions>
## Implementation Decisions

### Mecânica da virada

- **D-01: O código passa a aceitar as variantes de grafia de `CLICKSIGN_ENV`.**
  ⚠️ **Armadilha real encontrada durante este discuss:** o `config/services.php` (~linha 225)
  compara `env('CLICKSIGN_ENV') === 'producao'` (**português**), mas o **SC1 do ROADMAP manda
  escrever `production`** (**inglês**). Quem seguir o roadmap à risca faz o `painel_url` cair no
  ramo do sandbox — e o botão *"Registrar e ir para a Clicksign"*, construído na Fase 131, mandaria
  o Administrativo para o **painel de teste enquanto o sistema opera em produção**, em silêncio.
  A correção reconhece `producao`, `produção`, `production` e `prod`. Recusados: padronizar num
  lado só (mantém uma string exata como ponto único de falha, e o erro é silencioso — justamente na
  hora em que ninguém quer depender de memória).

- **D-02: Voltar atrás = restaurar as 4 linhas do `.env` e resolver à mão o que já saiu.**
  Devolver a configuração ao ambiente de teste interrompe a criação de novos contratos reais.
  O que já tiver saído é cancelado **no painel da Clicksign** — único caminho existente, medido —
  e o motivo é registrado pela tela da Fase 131.
  ⚠️ **Ressalva que o plano deve tornar explícita:** restaurar a variável **não impede um job já
  enfileirado de rodar**. A janela é curta (segundos a ~1 minuto, pelo limite de 1 envelope/min),
  mas existe. **Conferir o painel da Clicksign após reverter**, em vez de assumir que parou na hora.
  Recusado: acionar também o kill switch da Fase 128 — mais seguro no papel, mas acrescenta uma
  dependência a confirmar no pior momento possível.

### O primeiro contrato de produção

- **D-03: Empresa FICTÍCIA, com os e-mails reais da equipe.** Empresa de teste claramente marcada,
  criada na base de produção; os signatários são as pessoas de casa (Thiago, Emerson, Jessica, mais
  quem fizer o papel de cliente). O fluxo inteiro é exercitado sem envolver contrato jurídico nenhum.
  **Custo aceito: fica lixo na base de produção** — uma empresa e um contrato para limpar depois.
  Recusados: contrato real da própria ECF (aproveitaria o documento, mas mistura teste com ato
  jurídico) e cliente real avisado (quebra a regra do roadmap e um erro seria com quem paga).

- **D-04: O MESMO envelope prova também o SC1 da Fase 130.** Depois de assinar, impedir de propósito
  que o aviso automático chegue e rodar a varredura de reconciliação, para ver se ela corrige
  sozinha. **Um envelope fecha duas pendências.**
  Motivo: a reconciliação **nunca foi provada funcionando** — a sandbox não conclui assinatura nem
  envia e-mail (`130-GATE.md`), e produção é a **única** oportunidade de exercitá-la. A **D4 da
  milestone** exige a rede de segurança provada **antes** de ligar o bloqueio da Fase 133; sem isto,
  a 133 ficaria em cima de um mecanismo nunca exercitado.
  Recusados: envelope separado depois (mais controlado, custa outro envelope e outra janela) e
  adiar a rede de segurança (deixaria a D4 descumprida).

### Como saber que funcionou

- **D-05: Conferir por RECONSULTA AO BANCO depois de cada passo.** Após ativar e após assinar,
  consultar direto a tabela de eventos para ver se o aviso chegou. Nunca confiar na tela nem na
  mensagem de sucesso — é a disciplina que o projeto já aplica em consolidação financeira e que a
  Fase 130 repetiu.
  Recusado: só observar se a empresa é liberada (acusa tarde e não distingue "o aviso não chegou" de
  "chegou e o processamento falhou", que pedem ações diferentes).

- **D-06: Critério de abortar — se o aviso automático não chegar, para.** Se depois de ativar o
  contrato nenhum evento aparecer no banco, reverter para o ambiente de teste e investigar. É o
  **único item que sozinho invalida o cutover**: sem o aviso, nenhuma empresa é liberada
  automaticamente, e a Fase 133 ficaria ligando um bloqueio sobre um mecanismo quebrado.
  Recusados: tolerar e seguir (aceitaria produção com liberação não confirmada) e abortar a qualquer
  diferença (custaria a janela por detalhe cosmético).

### A janela aberta entre a troca e a aprovação (acrescentada em 2026-08-17, após a verificação)

> Esta decisão foi acrescentada **depois** dos planos serem escritos. O plan-checker encontrou um
> buraco estrutural que nenhum dos quatro planos fechava.

**O problema:** assim que as credenciais viram produção (wave 2), o sistema fica **vivo** contra a
Clicksign real. Mas a aprovação final (SC5) só acontece na wave 4 — e entre as duas há checkpoints
humanos e possibilidade de abortar, então a janela pode durar **horas ou dias**.

Nesse intervalo, qualquer pessoa com `admin.contratos` fazendo o **trabalho normal do dia**, numa
empresa real que já esteja com cadastro completo, geraria um **contrato de verdade** para um
**cliente de verdade** — e a API **não cancela** contrato em andamento (§15.2 do empírico). É
exatamente o risco que a **D1 da milestone** (LOCKED) existe para impedir: *"contrato errado enviado
ao cliente não tem desfazer bonito"*.

⚠️ **Agravante:** a **D-09 da Fase 131** concedeu `admin.contratos` a **todo `role:admin`**, pelo
curto-circuito de `User::hasPermission()`. Não é uma pessoa — é toda a equipe de admin.

⚠️ **O kill switch da Fase 128 NÃO serve.** `EmpresaOperacionalRouter::bloqueioAtivo()`
(`administrativo_bloqueio_ativo`) bloqueia `liberarEmpresa()` — a **liberação** da empresa para o
operacional — e não a **geração** do contrato. Verificado no código.

- **D-07: Um interruptor PRÓPRIO, checado em `gerarContrato()`.** Chave de configuração nova;
  enquanto ligada, ninguém gera contrato e a tela explica o motivo em linguagem simples. Ligada
  **antes** de trocar as credenciais, desligada **na aprovação final (SC5)**.
  Motivo: é a única opção que garante **estruturalmente**, não por combinado. As alternativas
  dependiam de todo mundo lembrar (um esquecimento = contrato real enviado) ou de fazer a virada de
  madrugada (encurta a janela mas tira a opção de abortar e retomar, e força dar a aprovação final
  fora de hora).
  **Ganho além desta fase:** serve para qualquer manutenção futura que precise congelar a emissão.
  Recusados: avisar a equipe e conferir depois; fazer fora do horário.

  ⚠️ **Isto vira a primeira task de código da fase, junto com a D-01** — as duas precisam estar
  publicadas antes de qualquer troca de credencial. E o desligamento do interruptor é o **último**
  passo, parte do SC5.

### Claude's Discretion

Ficam a critério do planejamento: a ordem exata dos passos do checklist, o nome da empresa fictícia,
como impedir o aviso de chegar no teste da D-04 (a Fase 130 fez sem túnel — sem exposição externa o
webhook não tem para onde ir), e o formato do registro dos gates.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### O que a API de fato permite (medido, não documentado)
- `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` — **§15** (cancelar envelope em `running` NÃO
  existe: `DELETE`→403, `POST /cancel`→404, `PATCH status`→400; corrigir e-mail de signatário NÃO
  existe: 404), **§16** (os rate limits do próprio app: `clicksign-envelope` 1/min GLOBAL,
  `clicksign-webhook` 3/min), **§14** (corpo do reenvio de notificação), **§7** (links de PDF
  expiram em 5 min).
  ⚠️ Estas medições são da **sandbox**. São de nível de API e devem valer igual em produção, mas
  **o plano não deve tratá-las como confirmadas lá** — o gate #3 existe justamente para isso.

### A pendência que esta fase fecha
- `.planning/phases/130-.../130-GATE.md` — o SC1 bloqueado, com o roteiro preservado. A D-04 desta
  fase o retoma. ⚠️ O envelope de teste citado lá (`f010d235-…`) **foi cancelado** em 2026-08-14
  durante a medição do CLICK-10 — será preciso um envelope novo.
- `.planning/phases/130-.../130-VERIFICATION.md` — `human_needed`, com a recomendação explícita de
  retomar o SC1 **antes** do cutover.

### Decisões da milestone que mandam aqui
- `.planning/REQUIREMENTS-v22.md` §"Decisões travadas (LOCKED)" — **D1** (geração e envio são SEMPRE
  manuais nesta milestone; contrato errado enviado ao cliente não tem desfazer bonito), **D4** (a
  rede de segurança é requisito e entra ANTES do bloqueio), **D9** (Polos é isento).
- `.planning/REQUIREMENTS-v22.md` §"Gates empíricos" — **#3** (URL base de produção, mapeado para
  esta fase), **#8** e **#8b** (fechados como impossíveis, ver §15 do empírico).

### O que a Fase 131 construiu e será usado aqui
- `.planning/phases/131-.../131-CONTEXT.md` — **D-13** (o registro de cancelamento: a tela registra
  autor+motivo e instrui a concluir no painel; é o que o rollback da D-02 usa).

</canonical_refs>

<code_context>
## Existing Code Insights

### ⚠️ Achado que muda o checklist: as variáveis `_PROD_` são MORTAS

`.env` tem `CLICKSIGN_PROD_TOKEN` e `CLICKSIGN_PROD_WEBHOOK_SECRET`, mas **nada em
`config/services.php` as lê**. O config lê `CLICKSIGN_ACCESS_TOKEN` e `CLICKSIGN_WEBHOOK_SECRET`
direto.

Elas são um **estacionamento** das credenciais de produção até o cutover. Consequências que o plano
precisa endereçar:
- A virada é **substituir valores**, não trocar uma chave de ambiente.
- Ter dois pares de credenciais no mesmo arquivo é convite a confusão **na hora errada**.
- O plano deve decidir o que fazer com as `_PROD_` depois da virada (remover? renomear para
  `_SANDBOX_` guardando as antigas?) — hoje elas viram lixo silencioso.

### O que lê `CLICKSIGN_ENV` (relevante para a D-01)
- `config/services.php:206` — `env` (só armazena)
- `config/services.php:225` — **`painel_url`**, comparando `=== 'producao'` ⚠️ ver D-01
- `config/services.php:209` — `base_url` (variável própria, não derivada)
- `app/Console/Commands/ClicksignSondarModelo.php:120` — guarda de segurança de sondagem
- `app/Http/Controllers/ContratoAdminController.php:280` — expõe `painel_url` como prop

### Assets prontos que esta fase usa
- Rota do webhook: `POST api/webhooks/clicksign` → `webhooks.clicksign` →
  `Api\ClicksignWebhookController@receive` (é esta URL que precisa ser cadastrada no painel de
  produção — SC2)
- `app/Console/Commands/ClicksignReconciliar.php` — a varredura que a D-04 vai exercitar
- `contrato_assinatura_eventos` — a tabela que a D-05 manda reconsultar
- A tela de registro de cancelamento (Fase 131) — usada no rollback da D-02

### Established Patterns
- Conferir por **reconsulta ao banco**, nunca por stdout nem pela tela — disciplina do projeto,
  reforçada nas Fases 130 e 131.
- ⚠️ **`artisan test --filter=ClasseInexistente` sai com EXIT_CODE 0** e varre a suíte inteira;
  `No tests found` é FALHA. Idem `route:list`, que sai 0 sem casar rota nenhuma.
- **Deploy exige árvore de trabalho limpa**, e a árvore é compartilhada com outro desenvolvedor e
  outras sessões de Claude Code.

</code_context>

<specifics>
## Specific Ideas

- **O usuário pediu explicitamente linguagem simples** quando o termo "cutover" apareceu sem
  explicação. Vale para todo texto que esta fase gerar, inclusive o checklist: quem executar pode
  não ser quem planejou. "Virar a chave para a Clicksign de verdade" comunica melhor que "cutover".
- **O `.env` foi restaurado em 2026-08-17** — os três signatários da ECF voltaram aos e-mails reais
  (`thiago@`, `emerson@`, `comercial@`). ⚠️ Isso significa que **qualquer envelope emitido daqui
  manda e-mail de verdade para essas três pessoas**, mesmo em sandbox. Só não aconteceu antes porque
  a sandbox não estava enviando e-mail (§15).
- **`CLICKSIGN_API_USER_EMAIL` é `adm@` e deve continuar** — é o usuário da API, não signatário.
- Ambiente: PHP não está no PATH (`C:\xampp\php\php.exe`); MariaDB local instável; testes em SQLite;
  **nunca** rodar a suíte sem filtro.

</specifics>

<deferred>
## Deferred Ideas

- **Ligar o bloqueio do roteamento** — Fase 133, e ela depende de a rede de segurança estar provada
  (D-04 desta fase).
- **Limpar o lixo de produção** — a empresa fictícia e o contrato da D-03 precisam ser removidos
  depois; não é trabalho desta fase, mas alguém precisa lembrar.
- **Automatizar a troca de ambiente** — hoje é edição manual de `.env`. Um comando artisan que
  fizesse a virada com validação seria mais seguro, mas é escopo próprio e não deve atrasar o
  cutover.
- **Kill switch no rollback** — recusado na D-02 por acrescentar dependência a confirmar no pior
  momento. Se o cutover mostrar que a janela de jobs enfileirados incomoda, vira melhoria própria.

</deferred>

---

*Phase: 132-Cutover sandbox → produção (checkpoint humano) (v22.0)*
*Context gathered: 2026-08-17*
