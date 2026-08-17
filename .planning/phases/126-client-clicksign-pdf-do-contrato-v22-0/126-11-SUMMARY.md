---
phase: 126-client-clicksign-pdf-do-contrato-v22-0
plan: 11
subsystem: integracao
tags: [clicksign, modelo, docx, gate-humano, medicao-real]

requires:
  - phase: 126 (plano 126-07)
    provides: "ClicksignClient com os metodos de modelo"
  - phase: 126 (plano 126-09)
    provides: "ContratoVariaveisModeloService — as variaveis que o codigo emite"
  - phase: 126 (plano 126-10)
    provides: "clicksign:sondar-modelo — o instrumento do gate"
provides:
  - "Caminho de modelo PROVADO ponta a ponta contra a API real"
  - "Divida D-16 fechada por medicao"
  - "GATE 126-11 aprovado — libera o plano 126-12"
affects: [126-12, 127, 129, 131]

key-files:
  created:
    - .planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md (secao 10 — 3a sessao de medicao)
  modified:
    - app/Services/Clicksign/ClicksignClient.php
    - app/Console/Commands/ClicksignSondarModelo.php
    - tests/Feature/Phase126/ClicksignClientModeloTest.php

requirements-completed: [CLICK-01, PDF-01, PDF-02, PDF-03]

duration: ~2h
completed: 2026-08-11
---

# Fase 126 Plano 11: Gate do caminho de modelo — APROVADO

**O contrato passou a sair de um modelo `.docx` cadastrado na Clicksign, provado ponta a ponta
contra a API real: modelo cadastrado, documento gerado com as variáveis preenchidas, arquivo
baixado e conferido, e a dívida D-16 fechada por medição.**

## O que foi feito

O `.docx` do contrato **não existia** quando este plano começou — o usuário não tem Word na máquina.
Foi gerado por código a partir do PDF do contrato real assinado que ele enviou: texto extraído com
`pdftotext -enc UTF-8`, OOXML montado à mão com `ZipArchive`, sem instalar biblioteca nova.

Decisão do usuário no meio do caminho, e ela melhorou o desenho: **um modelo por serviço**, em vez
de um modelo genérico com o nome do serviço em variável. O motivo é sólido — o escopo da cláusula
2.1 fala de ROAS, ACOS, Product Ads e Trello, que é Gestão de ADS e não "qualquer serviço". Um
contrato com escopo específico e serviço variável se contradiz. As seis generalizações que eu tinha
feito no texto foram desfeitas, e o modelo virou exclusivo de **Gestão de ADS Mercado Livre**, com
7 variáveis.

Efeito colateral bom: o texto voltou a ser transcrição pura do original, sem reescrita de cláusula.

## Resultado do gate

**GATE 126-11: aprovado**

O usuário abriu o `.docx` gerado pela Clicksign a partir do modelo, com dados de uma empresa
fictícia, e confirmou: *"O .docx arquivo de contrato parece estar certo"*.

Conferência automática do mesmo arquivo: **zero variáveis não substituídas**, e os cinco valores
verificados (`razao_social`, `cnpj`, `valor_mensal`, `data_assinatura`, placeholder `A DEFINIR`)
todos presentes no lugar certo.

## Quatro bugs achados — todos invisíveis para `Http::fake()`

Esta é a lição central da fase, e ela já custou caro o suficiente para virar regra escrita
(`CLICKSIGN-SANDBOX-EMPIRICO.md` §9.1): **nesta integração, forma de payload só é verdade depois de
medida.** Os quatro nasceram de modelar fixture a partir da RESPOSTA da API e presumir que o mesmo
shape vale na ENTRADA.

| # | Bug | Efeito se tivesse ido a produção | Commit |
|---|---|---|---|
| 1 | `communicate_by` enviado no signatário | 400 em **100% dos envelopes**, no primeiro signatário | `d5256f3a` |
| 2 | Cancelamento por `PATCH status=canceled` | rollback da D-12 nunca funcionaria; envelope órfão a cada falha | `d5256f3a` |
| 3 | `filename` com `.pdf` no caminho de modelo | 400 em toda geração de contrato por modelo | `3f7fe13a` |
| 4 | Download lido de `attributes.files.original` | nunca acharia o arquivo; e salvava `.docx` com extensão `.pdf` | `f271f49b` |

## Medições que fecharam gates

- **`POST /templates` aceita `.docx` montado por código** (§10.1). Pacote mínimo de 4 entradas
  basta. Vantagem colateral registrada: variável gerada por código fica num único run de XML,
  enquanto o Word costuma quebrar `{{nome}}` em vários runs e o motor não reconhece — armadilha
  clássica de quem monta modelo à mão.
- **`template.data` é hash, confirmado com modelo real** (§10.3). A divergência entre duas páginas
  da doc oficial está resolvida a favor do objeto.
- **Não há link de download com o envelope em `draft`** (§10.4). A Clicksign só materializa o
  arquivo na ativação. Explica o *known stub* do `--baixar` do plano 126-10: não era código
  incompleto.
- **Dívida D-16 RESOLVIDA** (§10.6). Excluir o modelo **não** derruba documento já gerado: após
  `DELETE /templates/{id}`, o documento segue 200, com link presente e arquivo idêntico. Fecha o
  buraco que a D-16 tinha em relação à D-02 original.

## ⚠️ O achado que muda o desenho da Fase 127

**Variável faltando vira BRANCO no contrato, silenciosamente** (§10.5). Mandando 3 de 7 variáveis, a
API devolve 201 e gera o documento — e nenhum `{{marcador}}` cru sobra: o motor substitui o que
falta por vazio. Variável sobrando também passa.

Um erro de digitação em nome de variável não dá erro em lugar nenhum. O contrato sai com campo em
branco e vai para assinatura assim. **Não existe resposta HTTP que denuncie isso** — a tabela de
confronto do `clicksign:sondar-modelo` é a única rede. Regra para a Fase 127: recadastrou o `.docx`,
roda o confronto antes de gerar contrato de cliente.

**Segundo achado de produto:** não existe pré-visualizar sem enviar. Ver o contrato preenchido exige
ativar o envelope, o que dispara e-mail para os signatários. Se o Comercial quiser conferir antes do
envio, o caminho é a interface web da Clicksign, não o nosso sistema.

## Pendências declaradas (não fechadas neste plano)

- **`GET /templates` contra produção** — não autorizado, não temos credencial de produção aqui. O
  usuário cadastrou o modelo na conta de produção (chave `dbf44e04-…`); o cutover é preencher
  `CLICKSIGN_TEMPLATE_ID` no `.env` do servidor, sem mudança de código.
- **Migration da Fase 126 no MariaDB de produção** — segue pendente desde o `126-06-CHECKPOINT.md`,
  sem autorização. Ação de deploy.
- **Um modelo por serviço exige escolha de modelo por serviço no código.** Hoje existe um único
  `CLICKSIGN_TEMPLATE_ID`. Empresa com Mercado Livre e Shopee vai precisar de dois contratos, e o
  sistema ainda não sabe escolher. **Isto altera a D-19** (que previa serviços concatenados num
  contrato só) e é entrada obrigatória da Fase 127.
- **Faltam os modelos dos demais serviços.** Este cobre Gestão de ADS Mercado Livre; Shopee e os
  outros precisam cada um do seu `.docx`, com o escopo da cláusula 2.1 reescrito para o que aquele
  serviço entrega.
- **Rodapé de assinatura** — o `.docx` foi entregue com `[NOME DO SÓCIO 1]`, `[NOME DO SÓCIO 2]` e
  `[NOME DO COMERCIAL]` em colchetes, para o usuário preencher com atenção ao papel de cada um
  (no contrato antigo um sócio aparecia como testemunha; no arranjo da D-08 ele assina como parte
  CONTRATADA).
- **Revisão jurídica da transcrição** — o `.docx` é reconstrução do PDF. O usuário aprovou o
  resultado, mas a conferência linha a linha contra o original continua recomendada antes do uso
  em cliente real.

## Testes

`tests/Feature/Phase126/` + `tests/Feature/Phase125/`: **158 verdes** (153 baseline + 5 novos do
guard de `filename`).

---

GATE 126-11: aprovado
