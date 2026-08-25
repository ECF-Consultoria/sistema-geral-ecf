---
quick_id: 260825-dap
slug: refazer-contrato
date: 2026-08-25
status: completed
---

# "Refazer contrato" — o Administrativo corrige um contrato errado sem pedir socorro

## O problema

O contrato é criado **sozinho** no ganho do HubSpot, com os dados que vieram de lá. A partir daí
os dados ficam **congelados no envelope da Clicksign**: e-mail, razão social, endereço, valor.

A tela deixa editar o cadastro, mas isso **não chega na Clicksign**. E a Clicksign não deixa
editar signatário de envelope criado por API (medido em 2026-08-24) — a API tem criar, listar,
ver e excluir signatário, mas **não tem editar**.

Relato do usuário (2026-08-25):

> "eu troquei email do cliente para o meu cliquei em salvar cadastro e não mudou nada no
> clicksign, ao tentar alterar o email do cliente pelo clicksign também não dá"

**Hoje não existe caminho de refazer.** Foi por isso que, ao longo de 24-25/08, o desenvolvedor
precisou apagar a empresa direto no banco **onze vezes** para permitir um contrato novo. Isso não
é acessível a quem opera o Administrativo.

## O que construir

Uma ação **Refazer contrato** na tela de detalhe: cancela o envelope atual na Clicksign e gera um
novo com os dados que estão no cadastro **agora**.

Resolve qualquer campo errado, não só o e-mail.

## Tarefa 1 — a ação no controller

Rota `POST /administrativo/contratos/{contratoAssinatura}/refazer`, no mesmo grupo/permissão das
demais (`permission:admin.contratos`), método `ContratoAdminController::refazer()`.

**Exige `motivo`** (`required|string|min:10|max:1000`) — mesma disciplina de
`registrarCancelamento()`. É ação para fora: o cliente pode já ter recebido o contrato anterior, e
quem refez precisa ficar registrado.

**Quando é permitido:** contrato em `STATUS_EM_ANDAMENTO` ou `STATUS_ERRO`.
⚠️ **Nunca** com `STATUS_ASSINADO` — contrato assinado é documento jurídico, não se refaz.
Recusar com `back()->with('error', ...)`, não `abort()` (precedente da quick `260819-guy`: a tela
mostra o problema em vez de dar tela de erro).

**A sequência:**

1. **Cancelar o envelope na Clicksign** (`ClicksignClient::cancelarEnvelope()`, que já existe e
   **não lança** — devolve `bool`).
   ⚠️ Se devolver `false`, **PARE**: não crie contrato novo. Um envelope antigo vivo significa que
   o cliente pode assinar o contrato **errado**. Volte com mensagem pedindo para cancelar pelo
   painel da Clicksign antes de tentar de novo.
   Se o contrato não tiver `clicksign_envelope_id` (rascunho que nunca chegou à Clicksign), pule
   este passo — não há o que cancelar.

2. **Fechar o contrato antigo** como `STATUS_CANCELADO`, gravando motivo, autor e data.
   ⚠️ Isto **difere de propósito** de `registrarCancelamento()`, cujo docblock diz "NÃO alterar
   `status`: quem fecha o estado é o webhook `cancel`". Aqui **nós mesmos** cancelamos pela API, e
   o status precisa fechar **agora** para liberar a trava composta
   `ca_empresa_servico_andamento_uniq` — senão o contrato novo não nasce. **Documente esse
   contraste no código**, senão parece incoerência.
   Use `fill()` + `save()` no model, **nunca** `updateQuietly()`/query builder — é o hook `saving`
   que alimenta a coluna sombra da trava.

3. **Gerar o novo** pelo caminho que já existe — o mesmo funil por onde passam o gatilho
   automático e o botão. Não duplicar lógica de criação.

⚠️ **Idempotência do webhook:** o evento `cancel` da Clicksign vai chegar depois, para um contrato
que já está `cancelado`. Verifique que o handler da Fase 129 aguenta isso sem estragar o registro
(e sem tocar no contrato NOVO). Se não aguentar, é parte deste plano.

## Tarefa 2 — a tela

`ContratoDetalhe.jsx`: ação com modal de confirmação pedindo o motivo, no padrão do modal de
cancelamento que já existe ali.

Copy **sem jargão**, deixando a consequência explícita — o cliente pode já ter recebido o anterior:

> Isto cancela o contrato atual na Clicksign e cria um novo com os dados que estão no cadastro
> agora. Se o cliente já recebeu o contrato anterior, o link dele deixa de funcionar.

O botão **não aparece** para contrato assinado.

## Tarefa 3 — rastro

Registrar no activity log (o projeto usa `spatie/laravel-activitylog` nos modelos principais):
quem refez, quando, o motivo, e a ligação entre o contrato antigo e o novo. Sem isso, ninguém
reconstrói depois por que existem dois contratos do mesmo serviço.

## Testes

- refazer com contrato em `rascunho` → envelope antigo cancelado, contrato antigo `cancelado`,
  contrato **novo** criado
- refazer com contrato em `aguardando_assinaturas` → idem
- **contrato `assinado` → recusado**, nada acontece (nem cancelamento, nem contrato novo)
- **`cancelarEnvelope()` devolve `false` → PARA**: contrato antigo intacto, nenhum contrato novo,
  mensagem de erro na tela (é a proteção contra o cliente assinar o contrato errado)
- contrato sem `clicksign_envelope_id` → pula o cancelamento e gera o novo
- o contrato novo nasce com o **e-mail atual** do cadastro, não com o que estava no antigo (é o
  caso que originou o pedido)
- a trava `ca_empresa_servico_andamento_uniq` não bloqueia a criação do novo
- `motivo` ausente ou curto demais → recusado
- webhook `cancel` chegando depois para o contrato já cancelado não estraga nada

## Gate

`C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` verde.

## Restrições operacionais

- ⚠️ **Árvore compartilhada** (outro dev ativo): nunca `git add -A` / `git add .` /
  `git commit -a` / `git stash`. Commitar só os próprios paths.
- ⚠️ **Antes de commitar:** `git status --porcelain app/ tests/ resources/ database/` **sem**
  `--untracked-files=no`, e conferir os `??`. `tests/Feature/CompanyPortfolioAccessTest.php`
  **não é seu**.
- ⛔ **Não fazer deploy.** Não mexer em `.env`, VPS, Clicksign, `servicos.clicksign_template_id`.
- `npm run build` ao final (mexe em JSX). Comentários e copy em pt-BR. Commits atômicos.
