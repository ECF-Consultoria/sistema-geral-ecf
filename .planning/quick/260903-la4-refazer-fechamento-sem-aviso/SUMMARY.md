---
quick_id: 260903-la4
slug: refazer-fechamento-sem-aviso
date: 2026-09-03
type: quick
status: done
commits:
  - b2c78cb0
  - 3f6c7d9e
files_modified:
  - resources/js/Pages/Admin/Financeiro.jsx
  - tests/Feature/Phase137/Phase137CompetenciaUiTest.php
---

# Refazer/Fechar competencia agora avisam quando funcionam

## O que estava quebrado

"Refazer fechamento" e "Fechar" (tela `/financeiro`) salvavam certinho no backend (200, mensagem
no JSON) mas a tela terminava exatamente igual -- sem fechar o dialogo, sem limpar o campo, sem
nenhum sinal. Em producao, em 2026-09-03, o usuario clicou "Refazer fechamento" 3 vezes achando
que nao tinha funcionado; as tres funcionaram, e cada clique gravou uma linha na trilha de
auditoria da cobranca de agosto (`fechamento_reconsolidacoes` ids 1/2/3).

## Causa raiz (medida, nao re-investigada)

`RefazerFechamentoDialog` e `FecharCompetenciaButton`, em `Financeiro.jsx`, tratavam o sucesso do
`axios.post()` so com `.then(() => router.reload())` -- nenhum estado de confirmacao, dialogo
continuava aberto com o motivo antigo preenchido.

## O que foi feito

**Tarefa 1 -- Refazer fechamento:** no sucesso, o handler agora fecha o dialogo (`setOpen(false)`),
limpa o motivo (`setMotivo('')`) e guarda a mensagem que o backend devolve (`r.data.message`) num
estado local, exibido como confirmacao (mesmo estilo visual do toast global do `AppLayout` --
verde, borda, icone de fechar) posicionada perto do botao. A mensagem some sozinha depois de 4,5s
(mesma janela do toast do `AppLayout`) ou ao clicar no X.

**Tarefa 2 -- Fechar competencia:** `FecharCompetenciaButton` tinha exatamente o mesmo formato sem
feedback -- recebeu o mesmo tratamento (nao presumi, li o codigo antes: confirmado que tambem nao
tinha nenhum sinal de sucesso).

**Sobre "sobreviver ao `router.reload()`":** verifiquei no codigo-fonte de
`node_modules/@inertiajs/core` que `router.reload()` chama `visit()` com `preserveState: true` por
padrao -- ou seja, o componente da pagina NAO E REMONTADO entre o clique e o fim do reload, e o
estado local (`useState`) sobrevive naturalmente. Por isso a implementacao guarda a mensagem em
estado local ANTES de chamar `router.reload()`, sem precisar de flash do Inertia nem de mudanca no
backend (que exigiria `session()->flash()` no controller -- fora do escopo/travas do plano).

**Por que nao reusei o toast global do `AppLayout` diretamente:** ele so le `flash.success` /
`flash.error` (props do Inertia setadas via `session()->flash()` num redirect). As duas acoes daqui
respondem via `axios.post()` puro (JSON, nao redirect), entao nao passam por `flash`. Criei um
componente `ConfirmacaoInline` com o MESMO visual do toast do `AppLayout` (cores, borda, blur,
botao de fechar), so reposicionado perto do botao em vez do canto da tela -- sem tocar em
`AppLayout.jsx` (fora do `files_modified` do plano).

**Tarefa 3 -- O carimbo "Fechado em {data}":** nao precisou de mudanca nenhuma. Li
`AdminController.php` (linhas ~156-167): `competencia_fechada_em` e recalculado do banco
(`FechamentoSnapshot::max('gerado_em')`) em TODA request, sem cache. Como `router.reload()` refaz
a mesma requisicao GET, o carimbo ja atualiza sozinho depois de qualquer refazer.

**Tarefa 4 -- Duplo envio:** confirmei que `disabled={enviando || !motivo.trim()}` (dialog) e
`disabled={loading}` (fechar) ja existiam e continuam intactos -- nao precisei mexer. As 3 linhas de
producao vieram de 3 cliques separados no botao "Refazer fechamento" (reabrindo o dialogo cada
vez), nao de um duplo-clique dentro da mesma submissao.

## Testes

Adicionei 2 testes de contrato em `Phase137CompetenciaUiTest.php` (mesmo padrao de "ler o JSX como
texto" ja usado no arquivo, porque o projeto nao roda test runner de JS):

- `refazer_fechamento_fecha_o_dialogo_limpa_o_motivo_e_guarda_confirmacao_no_sucesso` -- garante que
  o bloco de `RefazerFechamentoDialog` contem `setOpen(false)`, `setMotivo('')` e `setConfirmacao(`.
- `fechar_competencia_tambem_guarda_confirmacao_no_sucesso` -- garante o mesmo em
  `FecharCompetenciaButton`.

## Gate

`C:/xampp/php/php.exe vendor/bin/phpunit --filter="Phase122|Phase136|Phase137"`

**Antes (medido pelo orquestrador):** 239 testes / 1214 assercoes / 0 falhas
**Depois (medido por mim):** **241 testes / 1220 assercoes / 0 falhas** -- diferenca de +2 testes /
+6 assercoes bate exatamente com os 2 testes novos (4 assercoes no primeiro, 2 no segundo). Nenhuma
regressao.

`npm run build` rodou sem erro (`Financeiro-Bni821gR.js` gerado).

## Fora de escopo (nao tocado, por trava do plano)

- `FechamentoController::refazerCompetencia`/`fecharCompetencia` -- backend nao mudou.
- As 3 linhas de `fechamento_reconsolidacoes` em producao -- registro fiel, nao apagado.
- Calculo, faixas, snapshots -- nao tocados.
- Deploy -- nao feito. Sem acesso a `.env`/VPS.

## Deviations from Plan

Nenhuma. O plano previa investigar se `FecharCompetenciaButton` tambem precisava do tratamento
(Tarefa 2) -- confirmei que sim (nao tinha feedback) e corrigi junto, como o proprio plano autorizava.

## Commits

- `b2c78cb0` -- `fix(financeiro): refazer e fechar competencia dao confirmacao visivel no sucesso`
- `3f6c7d9e` -- `test(financeiro): trava a confirmacao de sucesso no refazer/fechar competencia`

## Self-Check

- `resources/js/Pages/Admin/Financeiro.jsx` -- modificado, contem `ConfirmacaoInline`,
  `useConfirmacaoTemporaria`, `setOpen(false)` no handler de sucesso do refazer.
- `tests/Feature/Phase137/Phase137CompetenciaUiTest.php` -- modificado, 2 testes novos.
- Commits `b2c78cb0` e `3f6c7d9e` existem em `git log --oneline`.
- Gate rodado e medido: 241/1220/0 falhas.

## Self-Check: PASSED
