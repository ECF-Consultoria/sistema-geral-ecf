---
phase: 160-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 12
subsystem: frontend
tags: [publicador, hook, react, derivados, ia]
requires: [160-04, 160-08, 160-09]
provides: [usePublicador, useIaDoPublicador, derivados, criarRota]
affects: [160-13]
key-files:
  created:
    - resources/js/Components/Publicador/derivados.js
    - resources/js/Components/Publicador/usePublicador.js
    - resources/js/Components/Publicador/useIaDoPublicador.js
    - tests/js/publicador-editor.test.js
  modified:
    - resources/js/Components/Publicador/apoio.js
decisions:
  - "criarRota parametriza o prefixo; `rota` do Portal continua exportada (sai em 160-15)"
  - "Conferência só local (D26) tem estados 'local' e 'local_bloqueado' e nunca libera publicar"
metrics:
  completed: 2026-10-02
---

# Fase 160 Plano 12: Hook do editor do Publicador Summary

Lógica do piloto (estado, salvamento com espera de 900 ms, ordem das respostas, fila a 2,5 s por até 4 min) extraída para `usePublicador`, com derivados puros testados e o hook `useIaDoPublicador` (polling a 2,5 s, até 15 min, sobrevive a F5 via sessionStorage).

## Commits

- `fc33d720` hook do editor, `criarRota`, derivados e testes
- `136bdef8` hook do "Anunciar por IA" e testes

## Contrato `m` exportado (levantado dos cards de `Mesa/`)

Campos: `estado`, `rasc`, `variantes` (estado mesclado com a cópia local), `alvos` (idem), `schema`, `disabled`, `aviso`, `enviandoFoto`, `simulacao`, `simulando`.
Métodos: `problemasDaSecao(chave)`, `problemasDoAtributo(id, variante = null)`, `mudarRasc(mudanca)`, `mudarVar(chave, patch)`, `mudarAtributo(id, valor|null)`, `escolherCategoria(id)`, `buscarCategorias(texto)` (devolve a lista), `salvarEixos(eixos)`, `enviarFotos(arquivos, grupo)`, `atribuirFotos(atribuicoes)`, `removerFoto(imagemId)`, `reenviarFoto(imagemId)`, `simular()`, `copiarTituloDo(de, para)`.

Todos os `m.*` usados pelos cards foram conferidos por grep e estão exportados.

## Retorno do `usePublicador({ produtoId, onPublicou })`

`m, carregando, erroCarga, erro, setErro, aviso, setAviso, salvando, salvoEm, aguardando, ciente, setCiente, conferir, publicar, podeConferir, podePublicar, conferencia {estado, texto, pendencias, avisos, local}, secoes, prontas, totalSecoes, totalAnuncios, resumo {modoLogistico, classico, premium, total}, publicacao, liberada, recarregar, descarregar`.

`useIaDoPublicador({ produtoId, nomeProduto, onConcluiu })` devolve `{ estado ('parado'|'andamento'|'concluido'|'erro'), etapa, textoEtapa, resumo, erro, disparar(substituir), tentarDeNovo }`.

## Verificação

- `npm run test:js`: 611 testes, 609 passam, 2 falham (as da baseline: "Características secundárias nasce recolhido..." e "FASES_TERMINAIS cobre as três fases de saída..."). `publicador-editor.test.js`: 15/15.
- esbuild nos 3 módulos: ok. `npm run build`: exit 0, manifest com mtime novo.

## Deviations from Plan

- `estadoDaConferencia` não recebe `doMl` (desnecessário; o N de pendências vai direto para `textoDaConferencia`).
- Acrescentados `totalDeAnuncios` (derivados) e `totalSecoes` (hook), usados pelo resumo e pela barra.
- Ao trocar de produto, o hook descarrega no cleanup (PUT fire-and-forget com o id antigo) o que ficou por salvar.

## Known Stubs

Nenhum.

## Self-Check: PASSED
