---
created: 2026-08-19T14:55:41.768Z
title: Tela de contrato esconde o motivo do erro e o próximo passo real
area: contratos
files:
  - app/Http/Controllers/ContratoAdminController.php:310-338
  - app/Http/Controllers/ContratoAdminController.php:439
  - app/Http/Controllers/ContratoAdminController.php:359
  - resources/js/Pages/Admin/ContratoDetalhe.jsx:163
  - resources/js/Pages/Admin/ContratoDetalhe.jsx:553-590
---

# Tela de contrato esconde o motivo do erro e o próximo passo real

**Criado:** 2026-08-19
**Origem:** sessão de diagnóstico durante o teste ponta-a-ponta — quatro defeitos de UX que
travaram uma pessoa real por cerca de 20 minutos na tela `/administrativo/contratos/{company}`
**Criticidade:** ALTA para o item 1 (o próximo passo é invisível), média para os demais

## Problema

Quatro defeitos independentes, todos na mesma tela, que juntos produziram um beco sem saída.

### 1. Nada diz que o envelope precisa ser enviado PELO PAINEL da Clicksign

Por decisão de projeto (D-02 da Fase 127-05,
`app/Jobs/GerarContratoAssinaturaJob.php:120-127`), o sistema monta o envelope e **para**:
`ativar: false`, "a ativação acontece FORA do sistema". Mas a tela não diz isso em lugar nenhum
e não tem link para o painel. O único link para a Clicksign que existe é o do fluxo de
cancelamento (CLICK-10/D-13).

O badge diz **"Não enviado"** — literalmente verdade, mas lê como falha. A pessoa fica
esperando o sistema enviar sozinho, ou clica "Gerar contrato" de novo.

### 2. O 422 vira página de erro crua

`ContratoAdminController.php:439` usa `abort(422, ...)` quando já existe contrato em andamento
— o navegador mostra a página branca do Symfony ("Oops! An Error Occurred"), fora da aplicação.

Dez linhas acima, no MESMO método, o caso de emissão congelada faz
`back()->with('error', ...)` e rende uma faixa dentro da tela. Os dois deveriam se comportar
igual. A checagem no servidor está certa (o `disabled` do client não é controle,
T-131-04-03) — o que está errado é só a apresentação.

### 3. O motivo do erro nunca chega na tela

A coluna `erro_mensagem` guarda o texto exato da recusa da Clicksign (caso real medido:
`[Clicksign] name não está em um formato válido`), mas **não é enviada como prop** — o
mapeamento em `ContratoAdminController.php:310-338` não a inclui.

Pior: o "Ver detalhes técnicos" do bloco de erro lê `flash?.error`, e só aparece quando
`tentativas > 0` — sendo `tentativas` o `useState({})` de `ContratoDetalhe.jsx:163`, que
**zera a cada reload**. Na prática: recarregou a página, o motivo sumiu para sempre e a tela
repete "Tente novamente — na maioria das vezes resolve".

Foi exatamente o que aconteceu: a pessoa ia tentar de novo com o mesmo nome inválido
indefinidamente, sem nunca saber o porquê.

### 4. Nada valida que o nome do signatário tem sobrenome

A validação de `nome_contato` é `['nullable', 'string', 'max:255']`
(`ContratoAdminController.php:359`) — aceita palavra única. A Clicksign exige nome completo e
devolve `400 "name não está em um formato válido"` ao adicionar o signatário. O custo de
descobrir tarde: dois round-trips e cerca de 6 minutos, com o registro terminando em
`status = erro`.

Ver todo irmão `260819-validacao-cnpj-no-contrato` — mesmo padrão de problema, mesmo bloco
de formulário.

## Solução

TBD. Provavelmente um plano só, porque é tudo a mesma tela:

1. Expor `erro_mensagem` como prop e derivar o "já tentou antes" do backend, não de estado
   local do React
2. Trocar o `abort(422)` por `back()->with('error', ...)`, igual ao ramo do congelamento
3. Contar na tela que o envelope precisa ser enviado pelo painel da Clicksign, com link
   (`painel_clicksign_url` já é uma prop existente) — e revisar a copy de "Não enviado" para
   um estado que descreva a ação pendente, não uma falha
4. Validação de nome completo (mínimo duas palavras) no servidor

Regra de UI do projeto: evitar jargão. Nada de "envelope", "flag", "roteamento" no texto
visível — a pessoa que usa a tela não sabe o que é envelope da Clicksign.
