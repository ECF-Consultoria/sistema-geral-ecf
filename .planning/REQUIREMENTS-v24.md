# REQUIREMENTS — Milestone v24.0: Creative Engine (V0.2 → V1)

**Origem:** plano canônico `plano-incubadora-v1` (raiz do repo), §§7-20.
**Spike V0.1:** executado e fechado como quick task `261001-nkx`. As medições reais estão em
`.planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md`
(seções 13-20) e **corrigem o plano canônico em quatro pontos**. Leitura obrigatória antes de planejar.

**Objetivo da milestone:** transformar a etapa visual do publicador numa linha de produção assistida
por IA — o operador sobe as fotos originais do cliente, clica em gerar, revisa, regenera o que não
prestou, aprova, e as imagens aprovadas seguem para a publicação no Mercado Livre. A aprovação
humana continua obrigatória.

---

## O que o spike já entregou (NÃO replanejar)

| Entregue | Onde |
|---|---|
| Config centralizada do provedor | `config/services.php` bloco `creative` + `GEMINI_*` no `.env.example` |
| Contrato de provedor | `App\Services\Creative\Contracts\ImageGenerationProvider` |
| Implementação Gemini | `App\Services\Creative\GeminiImageProvider` (Interactions API, `x-goog-api-key`, troca de modelo sem retentar o mesmo, parser na forma MEDIDA `steps[] → model_output → content[]`) |
| DTOs | `CreativeGenerationRequest` (BYTES CRUS, nunca path) / `CreativeGenerationResult` |
| Exceção de falha trocável | `App\Services\Creative\FalhaDeGeracaoTrocavel` |
| Prova técnica manual | `php artisan creative:test-gemini` |
| Cobertura | `tests/Unit/GeminiImageProviderTest.php` — 11 testes, `Http::fake()` |

---

## Decisões travadas (NÃO reabrir)

- **D-01** — O Creative Engine mora dentro de `/mlb/anuncios` sob `role:admin`. Rotas em
  `routes/mlb_anuncios.php`, nome `mlb.anuncios.*`.
- **D-02** — A imagem de referência é **upload efêmero**. A foto original vive no Google Drive do
  cliente; o operador a sobe no sistema na hora de publicar; o sistema **não guarda acervo
  permanente**. Não há integração programática com o Google Drive (o OAuth do projeto é só Calendar).
- **D-03** — Começa em `FULL_AI`. Arquitetura preparada para `COMPOSITE`, mas **COMPOSITE não se
  constrói nesta milestone** — o spike mostrou que ele não é pré-requisito de confiabilidade.
- **D-04** — Modelo default `gemini-3.1-flash-image` em 2K. O `lite` está descartado (erra contagem
  de portas/gavetas de forma recorrente); o `pro` fica alcançável por env.
- **D-05** — Estrutura de fases: **fatia fina ponta a ponta primeiro**. A primeira fase entrega um
  criativo funcionando de verdade, do upload à aprovação; as seguintes enriquecem.
- **D-06** — O validador do §8.8 usa **o Gemini como juiz de visão**: recebe a imagem gerada + as
  fotos originais + o Product Truth e devolve JSON estruturado. Custo aceito (~+US$ 0,30 por kit).

---

## Fatos medidos que o planejamento deve respeitar

1. **O Gemini preserva o produto.** 21 imagens, 7 tipos de criativo, 3 fotos reais de cliente.
2. **Texto dado no prompt sai exato**; texto que o modelo precisa *ler de uma foto* corrompe. Badge e
   headline vêm do cadastro → caem no caso que funciona.
3. **Os modelos erram esporadicamente** mesmo o bom. Validação automática e regeneração individual
   são essenciais, não enfeite.
4. **Contagem de peça só entra no prompt vinda do cadastro ou de leitura conferida.** Número errado
   no prompt é pior que número nenhum: o modelo obedece com confiança e a imagem fica coerente
   consigo mesma, passando pela revisão. (Aprendido errando — notas §18.)
5. **Fila `high` é obrigatória** para trabalho interativo; a `default` congestiona (170 jobs
   represados, 395s de espera medidos em produção).
6. **Custo e tempo base:** US$ 0,101/imagem, US$ 0,71 por kit de 7, ~112s por kit — antes de
   validação e regeneração.

---

## Requisitos

### FOTO — Staging efêmero da imagem de referência (D-02)

- **FOTO-01** — O operador consegue subir uma ou mais fotos originais do produto na etapa de
  criativos, sem sair do publicador.
- **FOTO-02** — As fotos ficam em disco privado, nunca público, e nunca são servidas por URL
  adivinhável.
- **FOTO-03** — As fotos têm retenção curta e são apagadas automaticamente depois de cumprirem seu
  papel; o sistema não acumula acervo de imagem de cliente.
- **FOTO-04** — O upload valida tipo e tamanho antes de aceitar, com mensagem em pt-BR.
- **FOTO-05** — O escopo por empresa é conferido no servidor antes de aceitar qualquer upload
  (mesmo double-check do resto do módulo).

### CTX — Contexto criativo a partir do anúncio existente

- **CTX-01** — O contexto é construído a partir do que o publicador já tem (produto, marca, título,
  descrição, categoria, atributos, variações) — **sem formulário duplicado**.
- **CTX-02** — O builder é a única fronteira entre o publicador e o Creative Engine: mudança de nome
  de campo ou relação no publicador deve exigir ajuste só nele.
- **CTX-03** — O contexto inclui as fotos de referência enviadas (FOTO-01) como bytes, não caminho.

### TRUTH — Product Truth

- **TRUTH-01** — Existe uma representação dos fatos **verificados** do produto, derivada do cadastro.
- **TRUTH-02** — Contagem de peça (portas, gavetas, itens do kit, acessórios) só entra no Product
  Truth quando vier do cadastro ou de leitura conferida — **nunca de inferência**.
- **TRUTH-03** — O que não está no Product Truth fica **fora do prompt**, em vez de ser preenchido
  por suposição.
- **TRUTH-04** — O Product Truth carrega uma lista explícita de claims proibidas.

### PLAN — Planejamento dos criativos

- **PLAN-01** — O sistema planeja no mínimo 7 criativos antes de gerar qualquer imagem.
- **PLAN-02** — O slot 1 é a imagem principal; os slots 2-7 são **escolhidos dinamicamente** conforme
  produto, categoria e fatos disponíveis — não uma lista fixa.
- **PLAN-03** — O planner só propõe slot cujos fatos o Product Truth sustenta.
- **PLAN-04** — O plano fica gravado para auditoria e depuração.

### GEN — Geração assíncrona

- **GEN-01** — Nenhuma imagem é gerada dentro da request HTTP.
- **GEN-02** — Os jobs rodam na fila `high`, nunca na `default`.
- **GEN-03** — Os assets são gerados em paralelo controlado, respeitando limite de custo e cota.
- **GEN-04** — O operador acompanha o progresso por asset, e falha de um asset não trava os demais.
- **GEN-05** — Toda geração registra provider, modelo, tentativa, latência e status — sem expor
  chave, payload inteiro ou base64 em log.
- **GEN-06** — Geração acidental repetida é barrada (duplo clique não cria projeto duplicado).

### VAL — Validação automática (D-06)

- **VAL-01** — Toda imagem gerada passa por validação automática antes de chegar à revisão humana.
- **VAL-02** — A validação compara a imagem gerada contra as fotos originais, o Product Truth e o
  objetivo do slot.
- **VAL-03** — A saída é estruturada e **explica o problema** — não é só nota numérica.
- **VAL-04** — Fidelidade ao produto é critério **eliminatório**, não ponderado.
- **VAL-05** — Asset reprovado é regenerado automaticamente uma vez, usando o motivo da rejeição.
- **VAL-06** — As tentativas são limitadas; nunca há loop infinito.

### APROV — Revisão e aprovação humana

- **APROV-01** — O operador vê as fotos originais usadas como referência ao lado do resultado.
- **APROV-02** — O operador consegue regenerar uma imagem individualmente.
- **APROV-03** — O operador consegue aprovar imagem por imagem e aprovar o kit inteiro.
- **APROV-04** — A tela alerta quando a validação apontou risco, com o motivo legível em pt-BR.
- **APROV-05** — Nenhuma imagem não aprovada chega ao Mercado Livre.
- **APROV-06** — A UI é componente separado com polling, no padrão do `PainelAnunciarIa` — não
  código inline no `AnunciarML.jsx`.

### PUB — Integração com a publicação

- **PUB-01** — As imagens aprovadas entram no rascunho como imagens do anúncio, respeitando a ordem
  dos slots.
- **PUB-02** — O envio ao Mercado Livre reusa `MlImagemService::enviar()` — nenhum caminho novo de
  upload ao ML.
- **PUB-03** — Antes de publicar, o sistema confere que existe kit aprovado e que o mínimo de
  imagens foi atingido.
- **PUB-04** — O limite de imagens da categoria do ML é respeitado.

### OPS — Custo, segurança e operação

- **OPS-01** — Chave de API só via `.env`; nunca em código, nunca em `.env.example` preenchido.
- **OPS-02** — É possível medir custo por projeto: quantidade de chamadas de planejamento, geração e
  validação, e tentativas por asset.
- **OPS-03** — O Creative Engine nasce atrás de chave desligada e pode ser desligado sem deploy.
- **OPS-04** — Permissão explícita para gerar, regenerar e aprovar.

---

## Fora do escopo desta milestone

1. Modo `COMPOSITE` / template engine de texto e badges (§9.2) — não é pré-requisito (notas §17).
2. Integração programática com o Google Drive (D-02).
3. Segmentação/recorte de produto para Product Lock mais forte (§Pós-V1).
4. Comparação com outros provedores de imagem (§Pós-V1).
5. Variantes A/B, geração em lote e biblioteca de templates por categoria (§Pós-V1).
6. Refazer qualquer parte do publicador existente.

---

## Métricas de aceite do POC (§19)

Medir, com anúncios reais já produzidos à mão pela equipe como referência:

- percentual de imagens aprovadas sem regeneração;
- média de regenerações por kit;
- tempo médio até o kit ficar revisável;
- custo médio por kit;
- motivos mais comuns de rejeição;
- tempo economizado pela equipe.
