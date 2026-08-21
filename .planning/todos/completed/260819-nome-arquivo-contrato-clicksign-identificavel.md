---
created: 2026-08-19T14:55:41.768Z
title: Nome do arquivo na Clicksign vem como contrato-N.docx, impossível identificar
area: contratos
files:
  - app/Jobs/GerarContratoAssinaturaJob.php:175
  - app/Services/Clicksign/ClicksignClient.php:154-172
---

# Nome do arquivo na Clicksign vem como "contrato-N.docx" — impossível identificar

**Criado:** 2026-08-19
**Origem:** teste ponta-a-ponta do fluxo completo, com os setores responsáveis
**Criticidade:** média — atrapalha a operação diária de quem envia os contratos

## Problema

Na lista de **Rascunhos** do painel da Clicksign, o que aparece é o `filename` do DOCUMENTO,
não o nome do envelope. E o filename é hardcoded em
[GerarContratoAssinaturaJob.php:175](app/Jobs/GerarContratoAssinaturaJob.php#L175):

```php
$nomeArquivo = "contrato-{$contrato->id}.docx";
```

Resultado: a lista mostra `contrato-4.docx`, `contrato-5.docx`... e quem vai enviar não
consegue saber de que empresa e de que serviço se trata sem abrir um por um.

**O nome do ENVELOPE já está correto** — confirmado por consulta à API em 2026-08-19:
`"Contrato — Gestão — Embralumi - Novo(a) Deal"`. O problema é só o filename do documento.

## Solução

Montar o filename a partir de razão social (ou `company.name` enquanto a razão social não
existir — ver todo `260819-contrato-campos-razao-social-endereco-parcelas`) + nome do serviço.

⚠️ **NÃO subir sem medir no sandbox.** A Clicksign valida o formato do filename. Já foi
medido no plano 126-11 (guarda local em `ClicksignClient::anexarDocumentoPorModelo()`):

```
"contrato.docx"           => 201 ACEITO
"contrato_sondagem.docx"  => 201 ACEITO
"Sondagem-modelo.pdf"     => 400 "filename não está em um formato válido"
"contrato" (sem extensão) => 400, mesma mensagem
```

O que **nunca foi medido** é quais CARACTERES o filename aceita. Nome de empresa real tem
espaço, hífen, parênteses e acento — `"Embralumi - Novo(a) Deal"` é um caso vivo na base.
Se parêntese ou acento devolver 400, a geração de contrato quebra para essas empresas.

Portanto o trabalho é: sanitizador (slug conservador, ASCII, sem pontuação exótica, limite de
tamanho) + **medição em sandbox** dos casos de fronteira, registrada em
`.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` junto das medições que já existem.

Lembrar que a mesma guarda exige terminar em `.docx` — o sanitizador não pode comer a extensão.
