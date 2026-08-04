---
task: Nova opção de Integrador Logístico + troca de "Banida" por "Não iniciado" na Publicação
date: 2026-08-04
slug: mek-integrador-me-e-publicacao-nao-iniciado
status: complete
commit: 8dd5f1ce
deployed: true
---

# Resumo

## O que mudou

1. **Link do cliente** (`/implementacao/{token}`, item *Integrador Logístico*):
   novo valor **"Trabalhar apenas com Mercado Envios"**, entre `Correios` e `Outro`.
   Antes, a empresa que não contrata integrador tinha de escolher "Outro" e digitar.
2. **Ficha de onboarding** (`/implementacao/{empresa}/ficha`, bloco Produtos, campo *Publicação*):
   sai **"Banida"**, entra **"Não iniciado"** — primeiro da lista, por ser o estado inicial.

## Arquivos

- `app/Models/MlbImplementacao.php` — `INTEGRADOR_OPCOES` e `ONB_PUBLICACAO_OPCOES` (fonte de verdade).
- `resources/js/Pages/Mlb/ImplementacaoPublica.jsx` — o select do checklist público tem a lista
  **hardcoded** e ignora a prop `integrador_opcoes` que o controller manda; por isso precisou de
  edição própria. Ficou um comentário apontando o espelhamento manual.
- `resources/js/Pages/Mlb/OnboardingFicha.jsx` — constante de fallback (usada quando `opcoes`
  não chega via props).

## Por que não quebra nada

- Nenhum dos dois campos passa por `Rule::in`. `salvarItem` (checklist público) não valida `valor`;
  `salvarBlocoProdutos` valida `publicacao` como `string|max:150`. Desde a quick `260803-kv2` as
  `ONB_*_OPCOES` são **sugestões do select**, não enum fechado — remover uma opção não invalida
  registro existente nem devolve 422 silencioso.
- Consulta em produção **antes** de remover "Banida": zero registros com esse valor. O que existe
  em `publicacao` já estava fora do catálogo (vem da planilha via `polos:sync-planilha`):
  `Não` 210 · `Concluido` 96 · `1º Anúncio` 31 · `Estagio 1` 25 · `Estágio 2` 15 · `Estágio 3` 13 ·
  `Sim` 1 · nulo 25. Nada a migrar.
- `'Não iniciado'` fica neutro em `corStatus` (ficha) e `VAL_*` (Painel Polos), de propósito.
  `'Banida'` foi mantida nas listas de cor para não descolorir registro legado que apareça.

## Deploy

Deploy **isolado**: a `main` local estava 19 commits à frente de `origin/main` com a Fase 123
(Auditoria de Bônus) de outra sessão ativa no mesmo working tree. Para não levar essa fase ao ar,
o commit foi para produção sozinho:

1. worktree destacada em `origin/main`;
2. `git cherry-pick` só deste commit;
3. `git push origin HEAD:main` (`7ecf449f..8dd5f1ce`);
4. `bash deploy.sh` a partir da worktree (o `plink.exe` não é versionado — foi copiado para lá);
5. worktree removida.

A VPS estava com o tree limpo (só arquivos untracked) antes do `reset --hard` — checado como
comando separado, conforme o incidente 260731.

**Verificado em produção após o deploy**: `HEAD = 8dd5f1c`, o JSX com a opção nova, e as duas
constantes lidas via tinker com o conteúdo esperado.

## Pendências

- A `main` local segue 20 commits à frente / 1 atrás de `origin/main`. O commit desta quick está
  nas duas pontas com conteúdo idêntico (cherry-pick) — o próximo `rebase`/`merge` da outra
  sessão resolve por patch-id sem conflito. **Não foi feito rebase de propósito**: outra sessão
  estava commitando no mesmo tree.
- `STATE.md` e este `SUMMARY.md` ficaram só no commit local (não vão a produção — artefato de
  planejamento).
- Observação para o usuário: produção já usa `'Não'` (210 registros) como "não começou". Se
  `'Não iniciado'` for para substituí-lo, cabe um backfill — não foi feito, não foi pedido.
