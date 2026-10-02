---
plan: 160-05
phase: 160
milestone: v24.0
status: complete
type: checkpoint:human-verify
executed_at: 2026-10-02
environment: produção (admin.ecfconsultoria.com.br)
---

# 160-05 — Prova real ponta a ponta: APROVADA

O checkpoint humano rodou **em produção**, a pedido do usuário, com foto de produto de cliente e
cota paga. Os valores abaixo são LITERAIS, lidos por reconsulta ao banco de produção — nunca de
stdout de tela.

## Decisão de ambiente

A prova estava prevista para rodar local, mas o banco local tem **zero tokens do Mercado Livre**
(8 empresas, todas fixtures de fases anteriores) e a rota do wizard faz
`abort_unless($company->mlToken !== null, 404)`. Sem token, a tela nem abre — e com token falso a
aprovação morreria no `MlImagemService::enviar()`, justamente a metade mais arriscada do desenho.

O usuário optou por testar direto em produção. Antes do deploy foi conferido que:
- produção já estava no commit `675e6c49`, o mais recente do outro dev (deployado 2026-10-02 10:22),
  então o deploy publicaria **apenas** os 27 commits do Creative Engine — nada do colega pegaria
  carona;
- os arquivos dos dois trabalhos **não se cruzam** (`comm -12` entre os dois conjuntos = vazio) e o
  `AnunciarML.jsx` em `origin/main` ainda tem o stepper de 5 etapas com a "Imagem e frete", ou seja,
  o ponto de montagem do painel sobreviveu ao "Redesign Focado" dele (que é tela nova, não alteração
  da nossa);
- `git merge-tree` acusou **zero conflitos**; depois do merge os 66 testes da fase seguiram verdes.

## Ajustes de produção que o deploy NÃO faz sozinho

1. **`deploy.sh` reinicia só `ecf-worker:*`** — o worker da fila `high`, que é o nosso, ficou com o
   código antigo em memória. Reiniciado à mão (`supervisorctl restart ecf-worker-high:`).
   ⚠️ Vale para qualquer fase futura que ponha job na `high`.
2. `GEMINI_API_KEY` não existia no `.env` de produção; gravada com backup do arquivo antes.
3. `creative_engine_ativo` ligada por `Configuracao::set` — sem deploy, como o OPS-03 exige.

## Números medidos (criativo id 1, produção)

| campo | valor |
|---|---|
| `slot` | `hero` |
| `provider` / `modelo` | `gemini` / `gemini-3.1-flash-image` |
| `render_mode` | `full_ai` (D-03) |
| `latencia_ms` | **12.452** (12,5s — abaixo dos 16,9s medidos no spike) |
| `tentativas` | **1** (nenhuma retentativa) |
| `referencias` | **3 fotos** enviadas numa chamada |
| `imagem_bytes` / mime | 907.422 (~886 KB) / `image/jpeg` |
| `status` | `aprovado` |
| `ml_picture_id` | `612452-MLB117061604264_102026` |
| criado → aprovado | 17:20:29 → 17:21:03 = **34s** no total |

## Critérios do checkpoint

| o que | resultado |
|---|---|
| Geração real pelo Gemini dentro do pipeline (não pelo comando) | ✅ |
| **PUB-02** — imagem ao ML só por `MlImagemService::enviar()` | ✅ `ml_picture_id` real devolvido |
| **PUB-01 / armadilha do autosave** | ✅ `payload.pictures` = `[{"source":"https://http2.mlstatic.com/D_NQ_NP_612452-MLB117061604264_102026-F.jpg"}]` |
| **FOTO-03** — retenção | ✅ `referencias_apagadas_em` = 17:21:03 (mesmo segundo da aprovação); diretório `creative-referencias/{token}` **apagado**; imagem **gerada ainda presente** |
| **TRUTH-02** — contagem nunca inferida | ✅ `truth.contagens = []` — o cadastro deste produto não tinha atributo de contagem, e **nada foi minerado do título**. O prompt não declarou número algum. |
| **OPS-01 / GEN-05** — segredo em log | ✅ `grep -cE 'AIza\|AQ\.[…]\|base64'` no log de produção = **0** |
| **GEN-02** — fila `high` | ✅ job consumido pelo `ecf-worker-high_00` |
| Produto preservado (§16) | ✅ aprovado pelo usuário |

## Achado para a Fase 163 (OPS-02)

Produção roda com **`LOG_LEVEL=error`**, então os `Log::info('[Creative] …')` do job e do provider
**não aparecem** no log de lá — o grep por `[Creative]` volta vazio mesmo tendo dado tudo certo.
Não é defeito: é o nível do ambiente. Mas significa que **a trilha de medição do §19 não pode
depender de log**; tem que vir da tabela, que já guarda `provider`, `modelo`, `latencia_ms`,
`tentativas` e `imagem_bytes` por criativo. A Fase 163 deve ler dali.

## Pendência conhecida (esperada, não é defeito)

O sistema gerou **uma** imagem. É o escopo declarado da Fase 160 (fatia fina, D-05). O kit de 7 com
slots escolhidos dinamicamente é a **Fase 161**.

## Estado deixado em produção

Módulo **ligado** (`creative_engine_ativo = 1`), atrás de `role:admin` e sem item de menu — só por
URL. A chave do Gemini está no `.env` de produção, ou seja, **produção pode gastar cota paga**.
Desligar é `Configuracao::set('creative_engine_ativo','0')`, sem deploy.
