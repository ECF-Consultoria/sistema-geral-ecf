---
quick: 261007-etp
subsystem: Publicador (editor do anuncio MLB)
tags: [publicador, editor, regressao, etapas, fotos]
key-files:
  created:
    - resources/js/Components/Publicador/Mesa/CartaoFotosVariante.jsx
    - resources/js/Components/Publicador/Mesa/DadosDasVariacoes.jsx
  modified:
    - resources/js/Components/Publicador/Mesa/CartaoVariante.jsx
    - resources/js/Components/Publicador/Mesa/FotosEVariacoes.jsx
    - resources/js/Components/Publicador/Mesa/EtapaDetalhes.jsx
    - resources/js/Components/Publicador/Mesa/EtapaImagens.jsx
    - resources/js/Pages/Mlb/Publicador/Editor.jsx
    - tests/js/publicador-mesa.test.js
    - tests/js/publicador-ferramentas.test.js
metrics:
  completed: "2026-10-07"
---

# Quick 261007-etp: etapa Imagens do Publicador volta a ser so fotos - Summary

Corrige a regressao de producao da Fase 169 (169-04): a etapa Imagens do editor do Publicador
tinha herdado estoque/SKU/codigo universal (EAN)/AGID/MPN junto com as fotos. Divide o cartao de
variacao, que sempre foi misto, em dois cartoes irmaos - um de fotos (Imagens) e um de dados
(Detalhes, primeira secao) - reaproveitando um cabecalho comum em vez de duplicar JSX.

## O que foi feito

**Divisao dos componentes** (commit `b76c75ab`):

- `CartaoVariante.jsx` ficou DADOS-apenas: estoque, SKU, GTIN, atributos extras, tom de cor. Ganha
  `onTirar` como unica prop de acao e passa a exportar `corDaVariante` e `CabecalhoVariante`
  (nome, cor, badge "publicada", "Vender esta variacao", "Tirar" - reaproveitado pelo cartao de
  fotos, sem os controles de gestao).
- `CartaoFotosVariante.jsx` (novo) ficou FOTOS-apenas: importa `CabecalhoVariante`/`corDaVariante`
  de `CartaoVariante.jsx`, sem repetir o JSX do cabecalho.
- `FotosEVariacoes.jsx` virou fotos-apenas (fotos gerais + `CartaoFotosVariante` por variacao).
  Perdeu `acaoDeTirar`, `<NovaVariacao>`, a secao de orfas e o editor de eixos "avancado" - tudo
  isso e gestao da variacao, nao foto.
- `DadosDasVariacoes.jsx` (novo) reuniu a gestao da variacao (criar, tirar, trazer de volta, eixos
  avancado) e a lista de `CartaoVariante` (dados). Secao `id="variacoes"` (o antigo id de
  `FotosEVariacoes.jsx`, que agora usa `id="fotos-variacoes"`).
- `EtapaDetalhes.jsx`: `<DadosDasVariacoes m={m} />` entra como PRIMEIRA secao, antes de
  `<FichaTecnica m={m} />` - como pedido ("na primeira sessao").
- Comentarios de topo atualizados em `EtapaImagens.jsx` e `Editor.jsx`, registrando a causa raiz e
  a correcao.

**`apoio.js` nao foi tocado** - `etapaDoProblema` ja roteava corretamente antes desta correcao
(ver prova caso a caso abaixo); o bug era so de apresentacao.

**Testes** (commit `b7ecaab1`, conteudo preservado em `964c050b` - ver incidente abaixo):

- `tests/js/publicador-mesa.test.js`: `CARDS` ganhou `CartaoFotosVariante.jsx` e
  `DadosDasVariacoes.jsx`; assercoes de estoque/SKU/GTIN/NovaVariacao/acaoDeTirar migradas para os
  arquivos certos; guards de regressao (`doesNotMatch`) garantindo que `FotosEVariacoes.jsx` e
  `CartaoFotosVariante.jsx` nunca mais contenham campos de dados, e que `CartaoVariante.jsx`/
  `DadosDasVariacoes.jsx` nunca contenham `BlocoDeFotos`; teste novo de ORDEM garantindo que
  `DadosDasVariacoes` vem antes de `FichaTecnica` em `EtapaDetalhes.jsx`.
- `tests/js/publicador-ferramentas.test.js`: os dois testes que liam o cartao misto antigo
  (secao 4 - Variacoes e "Fotos dentro de cada variacao") foram divididos entre os arquivos novos.

## Prova caso a caso: erro de estoque/SKU/EAN vai para Detalhes, erro de foto vai para Imagens

`etapaDoProblema` (`apoio.js`, nao alterado nesta correcao) ja roteava assim ANTES e DEPOIS desta
correcao - confirmado por teste existente (`tests/js/publicador-mesa.test.js`, "etapaDoProblema -
cada pendencia do servidor cai na etapa onde se resolve") e pela leitura do backend
(`ValidadorRascunho.php`):

| Origem do problema (backend) | alvo | etapaDoProblema | Onde renderiza agora |
|---|---|---|---|
| V-VAR-12 estoque | etapa E5, variante, campo estoque | detalhes | CartaoVariante.jsx -> erroEstoque, dentro de DadosDasVariacoes.jsx |
| V-VAR-13 SKU | etapa E5, variante, campo sku | detalhes | CartaoVariante.jsx -> erroSku |
| V-VAR-14/V-ATT-01 GTIN/EMPTY_GTIN_REASON | etapa E5, variante, atributo GTIN ou EMPTY_GTIN_REASON | detalhes | CartaoVariante.jsx -> erroGtin |
| V-ATT-01 atributo extra (AGID, MPN, etc, secao VARIANTE) | etapa E5, variante, atributo AGID/MPN/... | detalhes | CartaoVariante.jsx -> CampoExtra |
| ResolvedorGruposImagem/V-IMG-* foto | etapa E6, grupo OU etapa E6, imagem | imagens | CartaoFotosVariante.jsx -> erroFotos |

A regra em `etapaDoProblema`:

    if (alvo.grupo || alvo.imagem || e === 'E6') return 'imagens';          // foto, sempre
    if (...) return 'produto';
    if (['E3', 'E4', 'E5', 'E8', 'E9'].includes(e)) return 'detalhes';      // E5 = estoque/SKU/GTIN/extras

Nenhum problema de estoque/SKU/GTIN/atributo de variante carrega `alvo.grupo`/`alvo.imagem` nem
`etapa:'E6'` (confirmado lendo `ValidadorRascunho::variantes()` e `::estoque()` - os `alvo(...)`
que eles montam so tem `etapa`, `variante`, `campo`/`atributo`). Logo a primeira condicao nunca
intercepta esses problemas, e eles caem corretamente em 'detalhes' via E5. "Continuar" em Imagens
com erro de estoque pendente NAO bloqueia (o erro nao e da etapa Imagens); "Continuar" em Detalhes
com erro de foto pendente tambem NAO bloqueia - cada "Continuar" so ve os bloqueios da propria
etapa (`bloqueiosDaEtapa`). "Corrigir em..." (`Publicar.jsx`) leva a etapa certa pelo mesmo
`etapaDoProblema`.

## O que nao regrediu (checado)

- F5 na etapa Imagens e troca de produto: `?etapa=` + `sessionStorage` continuam intocados
  (`Editor.jsx` nao mudou essa logica).
- "Continuar" so marca vermelho depois de clicado (`ErrosDaEtapa`/`useErroDoCampo` nao mudaram).
- `EditorDeEixos`, `NovaVariacao`, "tirar variacao"/"trazer de volta", "Vender esta variacao", EAN
  automatico (`useEfeitosDasVariacoes`, continua em `FotosEVariacoes.jsx`, chamado sempre pelo
  Editor independente da etapa), "fotos por variacao" e a regra do frete - todos funcionando,
  confirmado pelos testes de `publicador-ferramentas.test.js`.
- Painel "Gerar com IA" (`FotosPorGrupo`/`BlocoDeFotos` -> `PainelCriativos.jsx`) continua na etapa
  Imagens via `CartaoFotosVariante.jsx`, "um painel por instancia do bloco" preservado (nenhuma
  mudanca em `FotosPorGrupo.jsx`).
- Bloco de pontos fortes/medidas digitados a mao NAO foi ressuscitado (nao existe em nenhum dos
  arquivos tocados).
- `usePublicador.js`, `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx` - nao
  tocados.

## Verificacao

- `node --test tests/js/publicador-mesa.test.js tests/js/publicador-editor.test.js tests/js/publicador-ferramentas.test.js` - 202/202 passaram.
- `npm run test:js` (suite completa) - 1149/1151; as 2 falhas sao as PRE-EXISTENTES documentadas
  (`estrutura-grade-glide.test.js` "Caracteristicas secundarias nasce recolhido",
  `polosEntrantes.test.js` "FASES_TERMINAIS cobre as tres fases de saida") - zero falha nova.
- `npm run build` - build OK (39.84s); confirmado por leitura do `public/build/manifest.json` que
  `resources/js/Pages/Mlb/Publicador/Editor.jsx` -> `assets/Editor-De_FQNG4.js` continua mapeado.
- `php artisan test tests/Feature/Publicador tests/Unit/Publicador` - 816 passed (4093 assertions),
  igual a baseline - nenhum arquivo PHP foi tocado nesta correcao.

## Deviations from Plan

Nenhuma no codigo do Publicador - a correcao seguiu exatamente a separacao pedida (fotos em
Imagens, dados como primeira secao de Detalhes, reaproveitando `CabecalhoVariante` em vez de
duplicar JSX).

## Incidente operacional durante a execucao - acao necessaria do usuario (nao e deviation de codigo)

Durante os commits desta correcao, outra sessao (mesmo usuario, arvore compartilhada - o padrao ja
documentado em `project_sessoes_paralelas_working_tree`) commitou
`6dc731ac` ("test(261007-m0t): trava os sete casos de CNPJ repetido") no `main` ENTRE meu segundo
commit (`b7ecaab1`) e uma tentativa minha de `git commit --amend` (so para corrigir um numero
errado na MINHA PROPRIA mensagem: "227/227" para "202/202" testes). Como `--amend` sempre amenda o
HEAD atual, ele amendou o commit `6dc731ac` da OUTRA sessao, substituindo a mensagem dela pela
minha - mantendo o conteudo (arquivo) dela intacto.

Confirmado SEM risco de perda de dados: `git diff 6dc731ac HEAD --stat` esta VAZIO - o commit atual
no `main` (`964c050b`) tem exatamente a mesma arvore de arquivos que `6dc731ac` (nenhum arquivo
foi perdido ou alterado); so a mensagem do commit ficou trocada. O commit original `6dc731ac`
continua intacto no object database (confirmado por `git cat-file -t` e `git show --stat`), so nao
e mais a ponta do `main`.

Tentei corrigir com `git update-ref refs/heads/main 6dc731ac` (restaura a mensagem certa, zero
mudanca de arquivo) - BLOQUEADO pelo classificador de auto-mode do Claude Code ("Git Destructive").
Nao tentei contornar.

O que fazer (qualquer um resolve, sem risco - arvore de arquivos ja e identica):

    git update-ref refs/heads/main 6dc731ac

Ou simplesmente deixar como esta - nenhum arquivo foi perdido, so a mensagem de um commit no
historico ficou com a descricao errada (a minha, sobre testes do Publicador, no lugar da descricao
real sobre CNPJ repetido). Confirme com `git show --stat 6dc731ac` vs `git show --stat HEAD` (ambos
devem imprimir o mesmo diffstat de arquivo).

## Known Stubs

Nenhum.

## Threat Flags

Nenhum novo - reorganizacao pura de componentes de apresentacao ja existentes; nenhum endpoint,
rota, campo de formulario ou trust boundary novo.

## Self-Check

- FOUND: resources/js/Components/Publicador/Mesa/CartaoFotosVariante.jsx
- FOUND: resources/js/Components/Publicador/Mesa/DadosDasVariacoes.jsx
- FOUND: commit b76c75ab (fix) - git log --oneline
- FOUND: commit b7ecaab1 (test, conteudo preservado em 964c050b) - git log --oneline
- FOUND: assets/Editor-De_FQNG4.js no public/build/manifest.json

## Self-Check: PASSED (com a ressalva do incidente de mensagem de commit documentada acima)
