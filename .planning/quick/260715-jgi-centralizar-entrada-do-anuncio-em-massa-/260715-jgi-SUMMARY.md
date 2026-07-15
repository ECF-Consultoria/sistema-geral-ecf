---
id: 260715-jgi
slug: centralizar-entrada-do-anuncio-em-massa
date: 2026-07-15
status: pending-checkpoint
type: execute
one-liner: "Segmented control ModoAnuncioTabs centraliza a troca Individual/Em massa dentro das telas de anunciar, removendo o atalho pendurado no card de empresa"
files-modified:
  - resources/js/Pages/Mlb/AnunciosEmpresas.jsx
  - resources/js/Pages/Mlb/ModoAnuncioTabs.jsx (novo)
  - resources/js/Pages/Mlb/AnunciarML.jsx
  - resources/js/Pages/Mlb/AnunciarMassa.jsx
---

# Centralizar a entrada do anúncio em massa na tela de Anunciar — Summary

## O que foi feito

O card da empresa em `/mlb/anuncios` tinha dois destinos (wizard individual + atalho "em
massa" pendurado no canto). Agora tem **um destino só**: o clique sempre abre o wizard
individual. A troca entre "Individual" e "Em massa" passou a viver **dentro** das próprias
telas de anunciar, via um segmented control compartilhado (`ModoAnuncioTabs`) que troca de
rota preservando a empresa fixada.

### Tarefa 1 — Removido o atalho "em massa" do card (`0cce420`)

- `resources/js/Pages/Mlb/AnunciosEmpresas.jsx`: removida a função `abrirMassa`, o `<span
  role="button">` do atalho, e o import órfão de `Grid3x3`.
- O wrapper do CTA voltou a ser `<div className="mt-3">` (sem `flex justify-between`, já que
  agora só há um filho: "anunciar →").

### Tarefa 2 — Criado `ModoAnuncioTabs.jsx` (`f292b80`)

Novo componente compartilhado `resources/js/Pages/Mlb/ModoAnuncioTabs.jsx`:

- `export default function ModoAnuncioTabs({ empresaId, modo })`.
- Guarda: `if (!empresaId) return null` — sem empresa fixada não há para onde alternar.
- Dois itens (constante de módulo `MODOS`): `individual` → rota `mlb.anuncios.wizard`
  (ícone `FileText`); `massa` → rota `mlb.anuncios.massa` (ícone `Grid3x3`).
- Navegação via `router.get(route(...))` do Inertia — troca de **rota**, não de estado local.
- Modo ativo é inerte de fato: handler retorna cedo (`if (item.chave === modo) return`),
  botão tem `disabled` e `aria-current="page"`.
- Visual: segmented control preenchido (`bg-ecf-card` + `p-1`, pílula ativa
  `bg-ecf-yellow text-black font-semibold`) — **intencionalmente distinto** das cápsulas de
  categoria de `AnunciarMassa.jsx` (que usam borda `border-ecf-yellow` + fundo translúcido).
- `route()` não importado (global via Ziggy), conforme convenção do projeto.

### Tarefa 3 — Abas montadas no wizard e na grade + build (`ddaa2b0`)

- `AnunciarML.jsx`: import de `ModoAnuncioTabs` + montagem dentro do `<header>`, logo abaixo
  do chip "Publicando na conta: …" (`modo="individual"`). **Não** montado no early return de
  guarda `if (!empresa)` — conforme achado crítico do plano.
- `AnunciarMassa.jsx`: import de `ModoAnuncioTabs` + montagem entre o bloco de cabeçalho/chip
  e o comentário das "Cápsulas de aba por categoria" (`modo="massa"`).
- `npm run build` executado com sucesso (green) — ver seção Build abaixo.

## Build

```
✓ built in 23.66s
```

Único warning presente é pré-existente e não relacionado a esta mudança:
`The class 'duration-[900ms]' is ambiguous and matches multiple utilities.`

## Deviations from Plan

None - plano executado exatamente como escrito. Todas as linhas de montagem e a armadilha
visual das cápsulas de categoria (SHEET-03) foram respeitadas conforme documentado no plano.

## Checkpoint humano — verificação visual pendente

Este SUMMARY cobre a Tarefa 3, que é `checkpoint:human-verify`. O código foi implementado e o
build está verde, mas a verificação visual em navegador ainda não foi feita (não pode ser
automatizada pelo executor).

**A verificação NÃO é possível em localhost.** O painel `/mlb/anuncios` lista apenas
empresas com conta ML conectada (`ml_token`), e o banco local tem **zero** — confirmado via
`Company::whereHas('mlToken')->count()` = 0. Sem OAuth do ML local, o painel vem vazio e o
wizard/grade nem abrem. Mesma pendência registrada na quick `260714-kp4`.

Por isso a verificação acontece **em produção** (`https://admin.ecfconsultoria.com.br`), onde
as contas ML estão conectadas. O módulo é admin-only, sem menu, acessado por URL direta —
risco de exposição baixo.

**Passos exatos para verificar (em produção, após o deploy):**

1. Abrir `https://admin.ecfconsultoria.com.br/mlb/anuncios` (logado como admin).
2. Conferir que o card da empresa **não** tem mais o botãozinho "em massa" no canto; o CTA
   "anunciar →" está alinhado à esquerda, sem buraco à direita.
3. Clicar no card → deve abrir o **wizard individual**, com o segmented control
   `[Individual | Em massa]` logo abaixo do chip "Publicando na conta: …", com **Individual**
   ativo (pílula amarela sólida).
4. Clicar em **Em massa** → deve navegar para a grade (`/mlb/anuncios/massa/{id}`) **com a
   mesma empresa** — conferir o nome da empresa no chip do cabeçalho da grade.
5. Na grade, o segmented control deve aparecer com **Em massa** ativo, e ser **visualmente
   distinto** das cápsulas de categoria logo abaixo (pílula sólida vs. cápsulas de borda).
6. Clicar em **Em massa** de novo (modo já ativo) → nada deve acontecer (sem reload/flash).
7. Clicar em **Individual** → deve voltar pro wizard com a mesma empresa.

**Resume signal:** responder "aprovado" ou descrever o que ficou errado.

## Self-Check

```
FOUND: resources/js/Pages/Mlb/ModoAnuncioTabs.jsx
FOUND: commit 2e2196e
FOUND: commit 26dc6af
FOUND: commit 9f366f6
```

## Self-Check: PASSED

## Success Criteria

- [x] `AnunciosEmpresas.jsx` sem `Grid3x3` / `abrirMassa` / `anuncios.massa`; CTA sem layout torto
- [x] `ModoAnuncioTabs.jsx` existe, guarda `!empresaId`, modo ativo não navega
- [x] Wizard monta `modo="individual"`; grade monta `modo="massa"`
- [x] Empresa preservada na troca de modo (mesma `{company}` na URL, via `empresaId`)
- [x] Segmented control não se confunde com as cápsulas de categoria da grade (visual distinto)
- [x] `npm run build` verde
- [ ] Checkpoint humano aprovado — **pendente de verificação visual pelo usuário (em prod)**
- [x] Deploy PARCIAL autorizado e executado (só os 4 .jsx do módulo — ver seção abaixo)

## Deploy parcial (2026-07-15)

Autorizado explicitamente pelo usuário ("faz deploy parcial, apenas do modulo mlb/anuncios /
nao altera nada do que o outro dev subiu recentemente").

**Contexto que mudou o plano:** o outro dev (MB.ECF-100376) avançou 18 commits (Fase 80 —
bônus/relatórios lendo atribuições NPS) e **já deployou o pacote NPS em prod** (`b575cee`).
A VPS estava em `8298a95` = `origin/main`, limpa. O STATE.md local dizia (errado) que o NPS
aguardava deploy — o `git fetch` revelou o gap de 18 commits.

**Rebase:** os 3 commits foram rebasados sobre `origin/main` (8298a95) sem conflito — zero
sobreposição de arquivos com o trabalho dele (ele mexeu em NpsTemplateController,
PerformanceController, DesempenhoScoreService, Performance/Dashboard.jsx + testes/planning).

**Método — deploy cirúrgico (NÃO o `deploy.sh`):** o `deploy.sh` faz `git reset --hard
origin/main` + `migrate --force` + `supervisorctl restart` — tudo-ou-nada por design
("puxa exatamente o que está em origin/main — nada mais, nada menos", linha 7). A pedido do
usuário, foi feito o caminho parcial:

1. `pscp` dos 4 arquivos `.jsx` → `/var/www/ecf_admin/resources/js/Pages/Mlb/`
2. `npx vite build` na VPS (recompila o bundle — não existe buildar só um módulo; o JSX só
   vira efeito via build, o navegador consome `public/build`)
3. `chown -R www-data:www-data public/build resources/js/Pages/Mlb`

Sem `git reset`, sem `migrate --force`, sem `supervisorctl restart`.

**Isolamento provado:** HEAD da VPS segue intacto em `8298a95`; `git status` mostra apenas os
3 arquivos modificados + `ModoAnuncioTabs.jsx` novo. Build verde em 17.79s, com hashes
idênticos aos do build local (`app-RMjbuk1_.js`, `AnunciarML-cLlHrJHi.js`) — prova de que o
código em prod é igual ao local. Manifest aponta para o novo bundle; `ModoAnuncioTabs-CBUZjuBG.js`
gerado. Smoke test: `/` e `/mlb/anuncios` respondem HTTP 302 (redirect de login, esperado).

**Pendência — os 3 commits NÃO foram pushados para o GitHub.** A alteração está em prod mas
fora do controle de versão da VPS: o próximo `bash deploy.sh` que alguém rodar fará
`git reset --hard origin/main` e **apagará estas 4 telas de produção**. Fazer
`git push origin main` (fast-forward sobre 8298a95) antes que isso aconteça.
