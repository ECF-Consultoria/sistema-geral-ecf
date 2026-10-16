---
quick_id: 261016-ibm
descricao: corrigir clique duplo no "Gerar com IA" e foto de referência perdida
data: 2026-10-16
---

# Corrigir o "clicar duas vezes" em "Gerar agora" e a foto de referência perdida

Diagnóstico reproduzido com playwright-cli: `relatorio-ux-gerar-imagens-ia/RELATORIO.md`.

## Causa

`POST …/kit/{kit}/gerar` despacha os jobs e grava o kit como `gerando`. A tela relê o status logo em
seguida; `atualizarEstado()` → `MlAnuncioCriativoKit::recalcularStatus()` tem a regra
"todos os slots `pendente` ⇒ `planejado`" e GRAVA isso — antes de o worker pegar o primeiro job, o kit
volta para `planejado`, `em_andamento=false`, o polling não liga e a tela pede "Gerar agora" de novo.

## Tarefas

1. **Servidor** — `recalcularStatus()`: com todos os slots `pendente`, um kit já `gerando` continua
   `gerando` (despachado, esperando o worker). Kit `planejado` nunca despachado continua `planejado`.
   Travamento segue coberto por `encerrarSeTravado()` (conta do `created_at`, reiniciado a cada
   tentativa por `reiniciarRelogioDaTentativa`). Vale para o Publicador e para o assistente antigo.
2. **Front** — `useCriativosDoPublicador.js` `gerar()`: aplicar o `status` devolvido pelo POST e
   `em_andamento: true` antes de reler, para o polling ligar mesmo se a leitura vier atrasada.
3. **Front** — `PainelCriativos.jsx`: ler `Array.from(e.target.files)` ANTES do `setArquivos` (hoje é
   lido dentro do updater, depois de `e.target.value = ''` ter esvaziado a lista).
4. **Teste de regressão** (Feature, `Queue::fake`): despachar e consultar o status do Publicador antes
   de qualquer job rodar devolve `gerando` / `em_andamento: true`; kit `planejado` nunca despachado
   continua `planejado`; unidade de `recalcularStatus()` para os dois casos.
5. Rodar a suíte de criativos (Phase161, Phase165) e `npm run build`. **Sem deploy.**
