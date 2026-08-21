---
created: 2026-08-19T14:55:41.768Z
title: Clicksign devolve erro ao salvar posicionamento de assinatura e rubrica
area: contratos
files:
  - app/Services/Clicksign/ClicksignClient.php:230-290
  - app/Services/Clicksign/ClicksignClient.php:660-700
  - app/Jobs/GerarContratoAssinaturaJob.php:175-195
  - .planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md
---

# Clicksign: "Ocorreu um erro ao salvar os posicionamentos!"

**Criado:** 2026-08-19
**Origem:** teste ponta-a-ponta do fluxo completo, com os setores responsáveis
**Criticidade:** ALTA — bloqueia posicionar assinatura/rubrica antes de enviar o contrato
**Status:** causa NÃO determinada — pode ser da Clicksign, nossa, ou erro de uso

## Problema

No painel da Clicksign, antes de enviar o contrato, ao marcar **"Posicionar campos de
assinatura e rubrica"** e posicionar as rubricas/assinaturas no documento, a tela devolve:

> Ocorreu um erro ao salvar os posicionamentos!

Reproduzido em 2026-08-19 no envelope `b10fb100-f966-4b95-9a68-19a2034a34b3`
(empresa 407, `ContratoAssinatura` id=4, documento `contrato-4.docx`, status `draft`),
por Leticia Moura.

**Não está determinado** se é bug da Clicksign, erro de uso, ou consequência de como nós
montamos o envelope via API.

## O que já se sabe do NOSSO lado (medido)

- O documento é instanciado a partir de **MODELO** (`.docx`), via
  `POST /envelopes/{id}/documents` com `template.key` — não é upload de PDF binário
- Criamos **4 signatários** (1 do cliente + 3 da ECF: thiago@, emerson@, comercial@)
- Para cada signatário criamos **DOIS requisitos**:
  - `action: "agree"` + `role` (qualificação — `ClicksignClient::criarRequisitoQualificacao()`)
  - `action: "provide_evidence"` + `auth: "email"` (autenticação — `criarRequisitoAutenticacao()`)
- **NUNCA criamos requisito de posicionamento** — nenhum `action` com coordenadas
- `rubric_enabled` é `true` por default da API (CLICKSIGN-SANDBOX-EMPIRICO.md secao 5)
- O envelope fica em `draft` de propósito (D-02 da Fase 127-05): a ativação acontece FORA do
  sistema, no painel

## Hipóteses a testar (nenhuma confirmada)

1. **Renderização assíncrona do modelo** — documento vindo de `.docx` de modelo pode ser
   convertido para PDF de forma assíncrona; posicionar antes da renderização terminar falharia
   ao salvar. Testável: esperar N minutos após a criação e tentar de novo
2. **Conflito com os requisitos criados via API** — a UI de posicionamento pode tentar criar
   requisitos de assinatura que colidem com os `agree`/`provide_evidence` que já criamos
3. **Bug ou limitação da própria Clicksign** para documentos instanciados de modelo
4. **Erro de uso** na tela de posicionamento

## Solução

TBD — precisa de **teste controlado em sandbox** antes de qualquer mudança de código.
Roteiro sugerido:

- Criar um envelope de sandbox pelo MESMO caminho (modelo + 4 signers + 2 requisitos cada) e
  tentar posicionar — reproduz?
- Criar um envelope de sandbox com upload de PDF binário (`anexarDocumento()`, não modelo) e
  tentar posicionar — o problema é do caminho de modelo?
- Criar um envelope de sandbox com modelo mas SEM os requisitos criados via API e tentar
  posicionar — o problema são os requisitos?
- Esperar alguns minutos e repetir no envelope original — é timing de renderização?

Se nenhuma variação nossa reproduzir a diferença, **abrir chamado com o suporte da Clicksign**
com o `envelope_id` e o horário — não gastar mais tempo tentando adivinhar de fora.

Registrar o resultado em `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md`, no mesmo formato
das medições que já estão lá.
