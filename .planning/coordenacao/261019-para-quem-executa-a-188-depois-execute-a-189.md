# Para quem está executando a Fase 188: ao terminar, execute a 189 — e 3 avisos ANTES do 188-08 e do 188-12

**De:** a sessão que planejou a Fase 189 (o lightbox)
**Em:** 19/10/2026
**Para:** a sessão que está executando a **Fase 188** (painel de 1140px)

> Respondendo à nota `261019-para-quem-planejar-a-fase-189-o-que-nao-esta-no-codigo.md`: a 189 foi
> **planejada e NÃO executada**, justamente porque executar duas fases ao mesmo tempo não dá
> (`ARQUIVOS_NOVOS` por igualdade, `npm run build` global, corrida de índice do git).

---

## 1. O pedido: quando a 188 fechar, rode a 189 na SUA sessão

- Os planos estão commitados em `44d67a46`:
  `.planning/phases/189-imagem-ampliada-o-lightbox-com-zoom-de-2-5-que-segue-o-curso/`
  — **8 planos em 7 waves** (`189-01` … `189-08`), mais `189-REGUA.md`, `189-FID01.md` e
  `189-COBERTURA.md`.
- **Depois do fechamento da 188** (o 188-13 com a SUMMARY e o `188-FECHAMENTO.md`), rode:
  `/gsd-execute-phase 189`
- ⚠️ **O 189-01 tem gate de entrada:** ele confere por `assertFileExists` os 13 SUMMARY da 188 e o
  `188-FECHAMENTO.md`, e **PARA** se faltar algum. Se ele parar, a 188 não está fechada — não contorne.
- ⚠️ **O 189-01 também faz o inventário do que a 188 entregou DE FATO** (`189-CONTRATO-188.md`). Os
  planos 189 foram escritos contra os **planos** da 188, não contra o código. Se a sua execução
  divergiu do plano em algum ponto (nome de componente, prop, rota, lugar de montagem do modal), o
  inventário é onde isso aparece — e os planos seguintes se adaptam a ele.
- A 189 **edita arquivos da 188** (não serviços): `PainelDeRevisao.jsx`, `ImagemGrandeDoPainel.jsx`,
  `ColunaDeFotos.jsx`, `Pages/.../Aprovacao.jsx`, `publicador-painel-fotos-188.test.js`, o controller e,
  só no caso descrito no 189-07, `ModalDeReprovarImagem.jsx`. ⚠️ **Inclui inverter o gate de ausência
  do "⤢ Ampliar" do 188-08** — só a parte do Ampliar; o do "✦ Gerar novamente com IA" continua.

### Decisões do usuário já tomadas para a 189 (19/10) — não reabra

Estão no §6 da `189-REGUA.md`:

- **Sem CONTEXT nem UI-SPEC:** a `189-FID01.md` é o contrato de tela.
- **"Comparar lado a lado na revisão detalhada" (Fase 190) e "✦ Gerar novamente com IA" (Fase 192)
  NÃO são desenhados** — o mesmo precedente da PERGUNTA-188-B3. Ficam como RESSALVA com dono.
- **189-A:** o cabeçalho "VERIFICAÇÃO AUTOMÁTICA · APOIO" entra **uma vez** na lateral do lightbox.
- **189-B:** fiel ao protótipo — ↑/↓ navegam a fila por baixo com o lightbox aberto, e **o lightbox
  guarda a própria oferta e versão**; toda ação dele grava na oferta que está sendo vista.
- **189-C:** ao fechar, o painel volta à foto de onde o "⤢ Ampliar" foi clicado.
- **O tamanho de fonte `18`** é liberado numa **linha própria da 189** em `LIBERADOS_DEPOIS_DA_187`,
  sem tocar na linha do `21` da 188.
- **189-D continua ABERTA e não bloqueia:** defaults D1/D2 declarados no 189-06/189-07; o roteiro da
  conta 459 pede a opinião do usuário.

---

## 2. ⚠️ Três avisos que valem AGORA — antes de o 188-08 e o 188-12 executarem

Achados durante o planejamento da 189, medidos no código de hoje:

1. **A borda de seleção das miniaturas vem fixa na foto 0.** `FotosDoPainelService::para()` é chamado
   **sem índice** (`PainelDeRevisaoService:301`), então a `borda` do servidor sai sempre `selecionada`
   na imagem 0. Como ←/→ trocam a foto **no cliente**, a grade do **188-08** não pode usar essa
   `borda` crua para a seleção — a foto 0 ficaria amarela sem estar selecionada.
2. **Não existe rótulo legível de variação no payload.** Só vêm `grupo` (chave canônica, tipo
   `cor=preto`) e `grupo_geral`. O rótulo humano só existe em
   `ResolvedorGruposImagem::rotuloDoGrupo`, que é **privado** e está em território vetado
   (`App\Support\Publicador`). O **188-08** precisa dele para "tipo · variação" (`:623`); a 189 vai
   usar o que a 188 usar — se a decisão for devolver a pergunta ao usuário, melhor agora.
3. **Onde montar o `ModalDeReprovarImagem`.** Se o **188-12** montá-lo **dentro** do `<aside>`
   (`z-[40]`), o `z-[42]` dele só vale dentro do painel, e o modal aberto a partir do lightbox
   (`z-[41]`) fica **escondido atrás** dele. **Montar como irmão do `<aside>`** evita retrabalho no
   189-07. Se não for possível, o 189-07 mede pela árvore do HTML e move a montagem — mas é mais
   barato acertar na 188.

---

## 3. Se precisar responder

Escreva um arquivo nesta pasta, com data no nome, como de costume.
