---
quick_id: 260824-ish
slug: papel-signatario-invertido
date: 2026-08-24
status: complete
---

# Os papéis de signatário estavam invertidos na Clicksign

## O que foi corrigido

`ClicksignClient::PAPEL_PARA_CLICKSIGN_ROLE` mandava o papel trocado para a Clicksign:

| papel interno | antes | agora | como a Clicksign exibe |
|---|---|---|---|
| `PAPEL_CONTRATADA` (ECF) | `contractor` | `party` | Parte |
| `PAPEL_CONTRATANTE` (cliente) | `party` | `contractor` | Contratante |
| `PAPEL_TESTEMUNHA` | `sign` | `sign` | inalterado |

## Como chegou a produção

O docblock do próprio mapa avisava:

> ⚠️ **NÃO MEDIDO** — confirmar no checkpoint humano do plano 126-06.

**Esse checkpoint nunca aconteceu.** A suposição foi para produção sem confirmação e só foi
medida em 2026-08-24, quando o usuário conferiu um contrato de teste (Mons Bike) já gerado e
reportou que o prestador (ECF) aparecia como "Contratante" e o cliente como "Parte".

A raiz do erro é linguística: a leitura em inglês de `contractor` — "quem executa o trabalho sob
contrato" — sugere a ECF, mas a Clicksign **rotula `contractor` como "Contratante"** na interface
em português. O docblock foi reescrito registrando isso, com aviso explícito para quem vier
depois não reverter por parecer intuitivo.

## Por que a correção não precisou de valor novo

Só `sign`, `party` e `contractor` foram medidos no sandbox. A troca acontece **dentro** desse
conjunto — nenhum quarto valor (`contractee` e afins) entrou. Mandar um `role` inválido para a
Clicksign só falha **depois** que o envelope já existe (~6 min até `status = erro`), que é o modo
de falha caro desta integração.

## Verificado antes de codificar

- O mapa é consumido em **um único lugar**: `criarRequisitoQualificacao()`.
- `ContratoSignatariosSyncService` casa signatário de volta por `clicksign_signer_key` e `email`,
  **nunca** por `sign_as` — o campo vindo da Clicksign é só armazenado bruto em `evidencia_signer`.
  Logo **o sync não depende do mapa** e não exigia coerência bidirecional.
- Os `'contractor'` em `Phase125`, `Phase129` e `ClicksignSandboxFixtures` são dado bruto de
  round-trip/evidência, não derivação do papel — não precisaram mudar.

## Testes

`tests/Feature/Phase126/ClicksignClientEnvelopeTest.php` — data provider invertido para o mapa
novo. A guarda que lança `ClicksignException` para papel fora do mapa, antes de qualquer HTTP,
segue coberta e intacta.

## Gate

`--filter="Phase125|Phase126|Phase127|Phase129|Phase131|Phase132|Phase133"` →
**507 testes, 1692 asserções**, verde.

## Commits

| Commit | Mensagem |
|---|---|
| `649b980b` | inverte o mapa de papel do signatário para o role da Clicksign |

## Consequência operacional

Contrato **já gerado** na Clicksign não se corrige sozinho — o envelope carrega os papéis
gravados no momento da criação. Para valer, é preciso apagar o rascunho e gerar de novo.

## Fora de escopo

`PAPEL_TESTEMUNHA`, lista de signatários da ECF (`config/services.php`), modelo `.docx`,
contratos já gerados, deploy, `.env`, VPS, `servicos.clicksign_template_id`.
