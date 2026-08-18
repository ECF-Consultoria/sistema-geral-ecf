---
criado: 2026-08-18
origem: Fase 132 — medido em produção com contrato real de 3 assinaturas
severidade: ALTA — o sistema nunca sabe quem assinou
area: contratos / clicksign
---

# Nenhum código cria linha em `contrato_assinatura_signatarios`

## Medido

```
App\Models\ContratoAssinaturaSignatario::count()   →  0   (base de produção inteira)
grep -rn "ContratoAssinaturaSignatario::create|signatarios()->create" app/   →  nada
```

A tabela existe, tem model, tem constantes de papel e de situação — e **nada escreve nela**.

## Como apareceu

Contrato 1 do cutover: envelope `running`, **três pessoas assinaram de verdade**. Os 11
eventos chegaram, validaram o HMAC e foram processados. Mas
`ContratoSignatariosSyncService::aplicar()` percorreu os três `sign` e mandou todos para
`$naoReconhecidos`, porque `localizarSignatario()` não achou linha nenhuma.

O sync **nunca cria** signatário — e isso é deliberado (T-129-16: *"Nunca criar signatário a
partir de webhook"*, para o webhook não conseguir inventar quem assina). A decisão está certa:
o problema é que **o lado que deveria criar as linhas não existe**.

`GerarContratoAssinaturaJob` monta os signatários no payload que vai para a Clicksign e usa o
model só pela constante `PAPEL_CONTRATANTE` — não persiste nada.

## Consequências

1. **O sistema nunca sabe quem já assinou nem quem falta.** A situação individual só existe
   no painel da Clicksign.
2. **A ação "reenviar aviso" da Fase 131 não pode funcionar.** A rota é
   `POST /administrativo/contratos/{contratoAssinatura}/signatarios/{signatario}/reenviar` —
   o binding de `{signatario}` nunca resolve, porque não há linha.
3. **A evidência de assinatura se perde.** `ip_address`, `auths` e `evidencia_signer` só são
   gravados em cima de uma linha existente; sem ela, o bloco `signer` do evento é descartado.

## O que fazer

Persistir os signatários no momento em que o envelope é criado, a partir da mesma lista que
já é montada para a Clicksign — guardando a **chave do signer devolvida pela API**, que é o
que `localizarSignatario()` usa para casar depois.

## Fixture pronta para validar

Não é preciso gastar outro envelope. O contrato 1 (empresa 424) está `running` com **3
assinaturas reais e 1 pendente** — exatamente o cenário. Depois da correção:

1. criar as linhas de signatário para o contrato 1;
2. reprocessar os 11 eventos (respeitando o limite de 3/min do bucket `clicksign-webhook`);
3. conferir que 3 ficam `assinou` com data e evidência, e 1 fica pendente.
