# Coordenação Creative Engine — resposta sobre a ordem 165 × 162

**De:** quem mantém o Creative Engine (v24.0, Fases 160-163)
**Para:** ECF Dev (Publicador interno, Fases 164-165)
**Data:** 2026-10-05

Resposta ao recado que você deixou no `CLAUDE.md` (marcador `AVISO-COORDENACAO-164`) e à linha do
seu roadmap: *"Execução espera o ok do outro dev sobre a ordem com a Fase 162"*.

---

## ⚠️ ATUALIZAÇÃO 2026-10-05, FIM DO DIA — ASSUMIMOS A EXECUÇÃO DA FASE 165

**NÃO comece a 165 — ela está sendo executada por nós, agora, seguindo os SEUS 8 planos.**

Motivo: o usuário pediu o gerador dentro do card de Fotos do editor três vezes ao longo do dia, e
a 165 seguia com zero planos executados enquanto a 166 (Alavancas) foi para produção. Ele decidiu
que nós assumimos para destravar o uso dele. Não é crítica ao seu trabalho — é fila.

Estamos executando o seu desenho, não um nosso: migration aditiva `pub_rascunho_id`/`pub_grupo`/
`pub_imagem_id`, `ContextoCriativoDoPublicador`, ramo novo no `CreativeContextBuilder`, imagem
aprovada virando `pub_imagens`, endpoints `publicador.criativos.*`, o hook
`useCriativosDoPublicador` e o painel `Mesa/PainelCriativos.jsx`, com o "Gerar com IA" no
`BlocoDeFotos`. Seus planos 165-01 a 165-08 são o roteiro.

Isso significa que **nós entramos no seu território** (`Publicador/`, `PubProduto*`, os JSX do
Publicador) — exatamente o que a regra anterior proibia. Se você tiver trabalho local não
publicado nesses arquivos, avise AGORA para não haver atropelo.

A Fase 162 (validador) já está em produção e não tocou em nada do seu lado.

---

## ATUALIZAÇÃO 2026-10-05 (depois da nossa conversa por fora)

Você autorizou verbalmente a execução em paralelo e disse que já está numa etapa avançada da 165.
Então o que vale agora é isto, e NÃO a pausa total escrita mais abaixo:

**As duas fases correm em paralelo, separadas por ARQUIVO.** A Fase 162 (validador) se comprometeu a
não tocar em nada do seu território: `CreativeContextBuilder`, `ProductTruthBuilder`,
`CreativePermissao` (só usa `exigir()`), `CreativePromptBuilder`, e nada de `Publicador/`,
`PubProduto*`, `MlbPublicador*` ou `resources/js/**/Publicador/**`. O validador lê o Product Truth
das COLUNAS já gravadas em `ml_anuncio_criativos`, nunca pelos builders — o que, de quebra, faz ele
cobrir os dois caminhos de contexto da sua 165 sem adaptação nenhuma.

⚠️ **O único ponto de atrito que sobra é o `MlbAnuncioController.php`.** Você acrescenta métodos
novos; nós editamos dois já existentes (`criativoAprovar` e `criativoKitAprovar`). É seguro para o
merge de texto, mas é o arquivo onde um conflito pode aparecer. Se der conflito aí, o nosso lado
cede — é só avisar.

⚠️ **Colunas novas nossas** em `ml_anuncio_criativos`: `validacao_status`, `validacao`, `validacoes`,
`regeneracao_automatica`, `validacao_pedida_em`, `validacao_em`. No kit: `validacoes`,
`regeneracoes_automaticas`. Nenhuma colide com `pub_rascunho_id`/`pub_grupo`/`pub_imagem_id`.

---

## Decisão: a sua Fase 165 vai primeiro

**Pode executar.** Não vamos tocar em `app/Services/Creative/`, nos endpoints `criativo*` do
`MlbAnuncioController`, nem em `PainelCriativosIa.jsx` / `KitCriativosGrade.jsx` até a 165 estar
mergeada. A Fase 162 (validador automático) fica parada até lá.

Três razões, na ordem em que pesam:

**1. A 165 destrava uso real; a 162 melhora algo que quase ninguém alcança hoje.** Medido em
produção em 2026-10-05: dos 6 produtos do Publicador, 1 não tem acesso nenhum aos criativos, e nos
outros 5 o acesso é a linha de rodapé do `Produtos.jsx`. O usuário tentou gerar imagem duas vezes
pelo editor e não encontrou — foi o que originou esta conversa.

**2. A ordem inversa criaria retrabalho para você.** A 165 acrescenta um segundo caminho no
`CreativeContextBuilder`. Se o validador entrasse antes, ele nasceria conhecendo só o caminho do
`payload` e você teria que adaptá-lo. Com a 165 primeiro, construímos o validador já ciente dos
dois caminhos — e ele cobre o Publicador desde o primeiro dia.

**3. O seu escopo é aditivo.** Você escreveu *"só ACRESCENTA, nada do que existe muda de
comportamento"*, e isso é o que precisávamos ouvir: a Fase 161 (kit de 7), que está em produção com
dado real, não corre risco.

---

## O que você precisa saber do nosso lado

### Houve mudança no Creative Engine depois do seu merge

Em 2026-10-03 entrou a quick task `261003-l8o`, com quatro correções de uso real. **Três delas
afetam o que a 165 vai reaproveitar:**

- **`CreativePromptBuilder::paraSlot()` ganhou dois parâmetros opcionais no fim**
  (`$regeneracao`, `$ajusteOperador`). Assinatura antiga segue funcionando. Eles injetam um bloco de
  variação e o texto livre do operador — porque regenerar devolvia **sempre a mesma imagem** (prompt
  idêntico + mesmas fotos = mesma saída do Gemini). Se o seu painel nativo tiver "regenerar", ele
  precisa passar esses parâmetros, senão o defeito volta.
- **Coluna nova `regenerar_motivos`** (json anulável) em `ml_anuncio_criativos`, histórico de toda
  regeneração. Não entra na whitelist do status.
- **Três limitadores nomeados** (`creative-kit-planejar` 12/min, `creative-kit-gerar` 4/min,
  `creative-regenerar` 12/min, por usuário) com resposta 429 em pt-BR e `Retry-After`. Se os seus
  endpoints novos forem outra rota, vão precisar dos próprios limitadores — o teto de custo não pode
  depender só do front.

### O botão de gerar UMA imagem saiu da UI

O endpoint `criativo.gerar` e o `GerarCriativoIaJob` continuam vivos (são o motor do kit e da
regeneração). Só o caminho de UI que disparava uma imagem avulsa foi removido. A partir da 161,
geração é sempre kit.

### A fila mudou de `high` para `creative`

Desde a Fase 161, `GerarCriativoIaJob` e `PlanejarKitCriativosJob` usam `onQueue('creative')`.
Motivo medido: a `high` tem `numprocs=1` e é a fila do trabalho interativo; um kit de 7 a ocuparia
por ~90s.

⚠️ **O worker `ecf-worker-creative` (numprocs=3) está na VPS mas NÃO está no git**, igual ao
`pm.max_children`. Arquivo: `/etc/supervisor/conf.d/ecf-worker-creative.conf`. Se a VPS for
reconstruída, precisa ser recriado — senão os jobs ficam enfileirados sem consumidor e a tela espera
para sempre.

⚠️ **O `deploy.sh` reinicia apenas `ecf-worker:*`.** Nem o `high` nem o `creative` são reiniciados
por ele, então ficam com código antigo em memória. Pior: em 2026-10-03 a conexão SSH do deploy caiu
**depois** das migrations e **antes** do bloco final, e os três workers da `creative` ficaram
`STOPPED` com `exit code 0` no script. **Conferir `supervisorctl status` depois de todo deploy.**

---

## Dois achados nossos que podem te poupar tempo na 165

**Contagem de peça nunca pode ser inferida.** Está em TRUTH-02/03 e custou caro aprender: num teste,
afirmamos "exatamente quatro pés" sem conferir o produto — e o produto tinha cinco. O modelo
**obedeceu com confiança** e a imagem saiu coerente consigo mesma, passando pela revisão humana sem
levantar suspeita. Número errado no prompt é pior que número nenhum. Se o adaptador do Publicador
derivar fatos do rascunho, só vale o que o cadastro sustenta.

**Texto dado no prompt sai exato; texto que o modelo precisa ler de uma foto, não.** Medimos com 21
imagens em 3 modelos. Badge e headline vindos do cadastro funcionam. Isso é o que torna o modo
`COMPOSITE` desnecessário — e vale para o seu painel também.

---

## Depois da 165

Assim que ela estiver mergeada, executamos a **Fase 162** (validador Gemini-como-juiz, regeneração
automática por motivo de rejeição, alerta de risco na tela). Ela vai cobrir **os dois caminhos** de
contexto, então os criativos gerados dentro do Publicador nascem validados.

Um caso real que justifica o validador: o usuário montou um anúncio com título de **caderno** e
subiu 3 fotos de um **gabinete**; a imagem saiu com um caderno desenhado como armário. O sistema
obedeceu as duas fontes corretamente — não é defeito. É exatamente a classe de divergência que o
validador pega, comparando a imagem gerada com as fotos originais e o Product Truth.

---

## Pendência pequena

`ml_anuncio_criativos` id **12** está `pendente` desde 2026-10-03 18:02 — órfão do deploy em que os
workers caíram. Não atrapalha nada; limpamos quando voltarmos ao Creative Engine. Se atrapalhar a
sua 165, pode apagar.

---

## Quando terminar

Quando a 165 entrar, dá para apagar o bloco `AVISO-COORDENACAO-164` do `CLAUDE.md`, o hook
`.claude/hooks/aviso-coordenacao.mjs` e a entrada dele no `.claude/settings.json` — como você mesmo
deixou escrito no comentário do marcador. Se preferir, fazemos isso do nosso lado; é só avisar.
