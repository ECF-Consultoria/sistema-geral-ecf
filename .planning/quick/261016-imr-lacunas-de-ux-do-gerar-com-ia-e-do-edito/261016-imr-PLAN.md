---
quick_id: 261016-imr
descricao: lacunas de UX do "Gerar com IA" e do editor do Publicador (G2–G7)
data: 2026-10-16
---

# Lacunas de UX do "Gerar com IA" e do editor do Publicador

Continuação do 261016-ibm. Achados G2–G7 de `relatorio-ux-gerar-imagens-ia/RELATORIO.md`.

1. **G2** — `PainelCriativos`: rolar para a vista quando nasce na parte de baixo da tela (atrás da
   barra fixa); `BlocoDeFotos`: botão aberto diz "Fechar IA".
2. **G3** — etapas no painel (Fotos de referência › Plano e custo › Imagens); "Gerar as imagens" →
   "Ver o custo e gerar"; aviso de que planejar é grátis.
3. **G4** — `mensagemDe` (apoio.js): 500/419/429/rede em pt; 502 intencional continua. Busca de
   categoria usa `mensagemDe` e não diz "não achou nada" quando falhou.
4. **G5** — `app.jsx`: `vite:preloadError` + `resolve().catch` recarregam UMA vez (trava 10 s em
   sessionStorage), no destino da visita GET.
5. **G6** — `Editor.jsx`: faixa mostra `estado.conta.erro`; "precisa ser reconectada" (texto fixado por
   WR-B04 em `publicador-editor.test.js`) só com token não ativo; "Tentar de novo" com token ativo.
6. **G7** — `AppLayout` manda `title` ao `<Head>`; `app.jsx` não gera "- ECF Admin" com título vazio.
7. Testes JS (fonte + render real com esbuild), prova inversa contra o HEAD, suíte JS inteira,
   `npm run build`, conferência no navegador com playwright-cli. **Sem deploy.**
