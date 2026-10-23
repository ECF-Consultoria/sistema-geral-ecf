---
quick_id: 261021-lli
status: planned
date: 2026-10-21
---

# Correções para a demo de 23/10 do Publicador

Origem: auditoria por código (sessão de 21/10) + teste de aceitação no navegador pelo Cowork
("Teste de aceitação — Publicador ECF (demo 23 10).md"). Base: `origin/main` em `b8adac85`, branch
`fix/demo-23-10` (o `main` local tem a Fase 193 pela metade e não entra).

Decisões do usuário (21/10): descrição da IA **sem saudação**; EAN gerado **fica como está**; capa
ambientada em móvel **fica**; deploy até quinta 14h.

## Tarefas
1. N2 — descrição da IA sem saudação nem nome da loja (prompt + filtro de saída).
2. N3 — termos sexuais fora dos termos mais buscados (um ponto só: `TermosMaisBuscadosService`).
3. H20/N13/H18 — botão "Ver no Mercado Livre" depois de publicar; "Conferido, com avisos" em âmbar;
   motivo do Publicar travado legível.
4. Textos — tempo estimado da IA, "conferência automática", etapa certa na Prévia (H16).
5. N16 — vitrine entra em "Todos"; Sincronizar sem erro em inglês.
6. N11/H3 — Salvar do Portal uma vez só, aviso junto do botão; criar família sem inglês.
7. H7 — Descartar sugestão discreto e com 2º clique.

Fora do código: N6 (renomear o tipo "Criado-mudo" na tela admin do catálogo), limpeza da vitrine da
#459, fechar os MLBs de teste, escolher produto de categoria que aceita o Mercado Envios da conta (N1).
