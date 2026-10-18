# Cobertura da Fase 188 — os 20 critérios e os 31 REQ-IDs, rastreáveis

**Medido pelo planejador em 18/10/2026, por script.** O plano **188-13** (fechamento) **re-mede** e dá
o veredicto de cada critério em forma fixa.

## Quantos critérios são — CONTADOS, não presumidos

```
awk '/^### Phase 188:/,/^### Phase 189:/' .planning/ROADMAP.md | grep -cE "^  [0-9]+\. "
→ 20
números extraídos: 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20   (sem buraco, sem repetição)
```

⚠️ **São 20.** **15 é a contagem da Fase 187 e 17 a da Fase 186** — as duas entradas são vizinhas no
`ROADMAP.md` e o `awk` errado traz a contagem da fase errada **em silêncio**.

## Os 20 critérios × os 13 planos

| # | critério (resumo) | servidor | tela | fechamento |
|---|---|---|---|---|
| 1 | painel 1140px, **por PORTAL**, acima do header sem alterá-lo, z-index intacto | — | **07** | 13 |
| 2 | Esc · clique fora · ↑/↓ (pulando `preparando`) · ←/→ · topo de 54px | 02 (`pos`, navegação) | **07** | 13 |
| 3 | **duas colunas de mesmo peso**, não reequilibradas | — | **07** | 13 |
| 4 | fotos: `x de y`, 1:1, #n, ★, ✦, estado, **marcadores x/y**, grade de 6, verificações | **03** | **08** | 13 |
| 5 | **"Aprovar N sem alerta"** aprova só as pendentes SEM alerta, e o toast diz isso | **03** (contagem) · **05** (ação) | **08** | 13 |
| 6 | preço: 3 KPIs, aviso, composição com origem, frete, simulação, irmã | **04** | **09** | 13 |
| 7 | simulação **nunca persistida**, salvável só no `live`, versão++ | **04** (ler) · **06** (gravar) | **09** | 13 |
| 8 | **`disabled=`** com bloqueio; só com zero bloqueios **e** `live` | **02** (veredicto) · **05** (trava real) | **10** | 13 |
| 9 | **cada pendência com o seu botão**, e ele leva ao lugar certo | **02** (os 9 destinos) | **10** | 13 |
| 10 | rodapé: literal verde, ou o motivo com **o primeiro abreviado** | **02** (`resumoDoRodape`) | **10** | 13 |
| 11 | exceção libera **só** `loss`/`margin`, 10+ caracteres, histórico | **06** | **11** | 13 |
| 12 | ajuste move para `changes` **por escopo**; resolver o último devolve a `awaiting` | **06** | **11** | 13 |
| 13 | reprovar imagem: motivo, sai na hora, promove a próxima, reabre todas | **05** | **12** | 13 |
| 14 | frete: valor + origem, **prévia antes**, versão++ | **06** | **12** | 13 |
| 15 | casca comum **IMPORTADA** (560px/96px/…) e a fonte declarada | — | **11** + **12** | 13 |
| 16 | revisão parcial **no SERVIDOR**; `awaiting → ecf_review` + registro | **05** | **10** | 13 |
| 17 | concorrência: **avisa e reabre**, nunca sobrescreve em silêncio | **02** (versão) · **05**/**06** (422) | **12** | 13 |
| 18 | **nunca** status do ML, **nunca** escreve no ML nesta fase | **02** · **05** · **06** | todos | **13** |
| 19 | render adverso de todo campo novo, com guarda de tipo | — | **07**–**12** | 13 |
| 20 | a tabela **FID-01** do painel e dos 4 modais, declarando como mediu | **01** | — | **13** |

⚠️ **Nenhum critério ficou sem plano.** Os critérios 18 e 19 são **transversais** e cobrados em **todos**
os planos que tocam o assunto, além do fechamento.

## Os 31 REQ-IDs × os planos — **conferido por script**

```
para cada id: grep -l "^requirements:.*\<id\>" 188-*-PLAN.md
resultado: 31 de 31 COBERTOS, nenhum órfão
```

| REQ-ID | planos | REQ-ID | planos |
|---|---|---|---|
| REVS-01 | 07 | MOD-01 | 06, 11 |
| REVS-02 | 07 | MOD-02 | 06, 11 |
| REVS-03 | 07 | MOD-03 | 06, 12 |
| REVS-04 | 02, 07 | MOD-04 | 05, 12 |
| REVS-05 | 02, 07 | MOD-05 | 11, 12 |
| REVS-06 | 07 | IMG-01 | 05, 12 |
| REVS-07 | 03, 08 | IMG-02 | 05 |
| REVS-08 | 03, 08 | IMG-03 | 05 |
| REVS-09 | 03, 08 | IMG-04 | 05, 08 |
| REVS-10 | 03, 08 | IMG-05 | 03, 05, 08 |
| REVS-11 | 04, 09 | IMG-06 | 05, 12 |
| REVS-12 | 02, 10 | COL-01 | 05, 10 |
| REVS-13 | 02, 10 | COL-02 | 07 |
| REVS-14 | 02, 05, 13 | COL-03 | 02, 07 |
| | | COL-04 | 06, 12 |
| | | COL-05 | 02, 05, 06 |
| | | COL-06 | 02 |

⚠️⚠️ **IMG-02 e IMG-03 são REQ-IDs desta fase, mas o CAMINHO DE TELA deles é de outra.** `MOD-06`
(substituir por original) e `MOD-07..10` (gerar novamente com IA) pertencem às Fases **189/192**
(Coverage Map do `ROADMAP.md`). ➡️ Nesta fase eles são cobertos pelo plano **188-05**, por **gate de
não-regressão** sobre `RevisaoDeImagemService::substituirPorOriginal()`, `recolocarOriginal()` e
`concluirRegeneracao()`, **com a fronteira DECLARADA** — ⚠️ **nunca** inventando UI que o desenho põe
noutra fase (D4/D14: nenhuma fase deixa botão sem destino na sua fronteira).

## As DECISÕES do usuário × os planos

| decisão | onde ela é cobrada |
|---|---|
| 1 · **aprovar JÁ PUBLICA**, sem segundo clique nem desfazer | **05** (gate de ausência dos 4 rótulos) · **10** (rodapé) · 13 |
| 2 · preço por variação é **PIOR CASO**, e a tela **NOMEIA** | **04** (ler do DTO) · **09** (frase **visível**) |
| 3 · **embalagem** `Pendente` não anula; **outros** R$ 0,00 `Confirmado` | **02** · **04** (par embalagem × frete, nos dois sentidos) |
| 4 · **duas margens de propósito**; a tela nomeia a tarifa real do ML | **04** · **09** (substring testada) |
| 5 · bloqueio de fotos cobre **TODOS os grupos** | **03** (`contagemDeFotos`, com 2+ grupos) |
| 6 · a exceção só **REGISTRA** quem liberou; nada "aguardando autorização" | **06** · **11** (gate ancorado no **sujeito**) |
| 7 · vocabulário travado; **nunca "lucro líquido"**; cor nunca é único sinal | **04** · **09** · **10** (recorte à lista constante, com isca) |
| 8 · "tudo de uma vez, e a tela **diz quem ficou de fora**" | **03** (`com_alerta_restantes`) · **08** |
| P1 (15/10) · a frase do pior caso é **visível no painel** | **09** |
| P3 (15/10) · **a fila nasce vazia até a 194** — honesto, não defeito | **13** (RESSALVA declarada) |
| P6 (18/10) · vocabulário de toast travado ganha do protótipo | **05** · **08** (PARAR e devolver se faltar frase) |

## As PERGUNTAS, e quem bloqueia o quê

| pergunta | dono | bloqueia |
|---|---|---|
| **PERGUNTA-188-A** — o rótulo `VERIFICAÇÃO AUTOMÁTICA · APOIO` no painel | **usuário** | o rótulo da tarefa 3 do **188-03** e da tarefa 2 do **188-08** (checkpoints bloqueantes) |
| **PERGUNTA-188-B** — destino de `ajuste`/`titulo` sem a rota da revisão detalhada | Fase 190/191 (decisão do plano **declarada**) | **nada** — o botão não é desenhado e o texto continua |
| PERGUNTA-VISUAL-187 — a conferência visual da **FILA**, conta 459 | usuário | nada nesta fase; **não presumir feita** |
| PERGUNTA-17 — `mlb_empresa_id` anulável no recorte da fila | Fase 194 | nada nesta fase |
| PERGUNTA-13 — editar foto de anúncio **já no ar** | Fase 193 | nada nesta fase |

## As WAVES, e por que são sequenciais

**13 planos em 13 waves.** Dois motivos **medidos**, nenhum deles preferência:

1. ⚠️ **`tests/js/publicador-vocabulario-novo.test.js` tem `aprovacao/` em `DIRETORIOS_FECHADOS` com
   `assert.deepEqual(noDisco, declarados)`** — então **todo plano que cria um `.jsx` tem de editar
   aquele arquivo**, e **pré-declarar não é possível** (`FONTES` lê todos os caminhos e **LANÇA** com
   arquivo inexistente). Dois planos na mesma wave **compartilhariam** esse arquivo. É o mesmo motivo
   que fez a Fase 187 ter 11 planos em 11 waves.
2. ⚠️ Os planos de servidor (**02**–**06**) compartilham `MlbPublicadorAprovacaoController.php`,
   `routes/mlb_anuncios.php` e `PainelDeRevisaoService.php`.

| wave | plano | o que entrega | depende de |
|---|---|---|---|
| 1 | **188-01** | baselines ANTES · `21` liberado · FID-01 por script · `--autoteste` | — |
| 2 | **188-02** | SERVIDOR: view-model do painel + a prop `painel` no `?oferta=` | 01 |
| 3 | **188-03** | SERVIDOR: coluna de fotos (x/y, grade, sem-alerta, todos os grupos) ⚠️ checkpoint | 02 |
| 4 | **188-04** | SERVIDOR: coluna de preço (KPIs, composição, simulação, irmã) | 02, 03 |
| 5 | **188-05** | SERVIDOR: escrita de IMAGEM + salvar revisão + **aprovar** | 03 |
| 6 | **188-06** | SERVIDOR: escrita de preço/frete/ajuste/exceção | 04, 05 |
| 7 | **188-07** | TELA: casca do painel **por PORTAL** + teclado + duas colunas | 02 |
| 8 | **188-08** | TELA: coluna de fotos ⚠️ checkpoint (PERGUNTA-188-A) | 03, 07 |
| 9 | **188-09** | TELA: coluna de preço | 04, 07 |
| 10 | **188-10** | TELA: pendências + rodapé (`disabled=`) | 05, 07 |
| 11 | **188-11** | TELA: modais de **ajuste** e **exceção** (importando a casca) | 06, 10 |
| 12 | **188-12** | TELA: modais de **frete** e **reprovar** + aviso de concorrência | 06, 11 |
| 13 | **188-13** | FECHAMENTO medido dos 20 critérios ⚠️ checkpoint humano | todos |

⚠️ **Por que 13 planos e não 6:** **três agentes caíram na Fase 187** (travamento de 600 s, erro de
rede `ENOTFOUND`, turno esgotado), e **um deixou 2/3 de um plano sem commit**. ➡️ Planos **menores e
mais numerosos**, tarefa curta, **commit atômico por tarefa**, e em cada plano a instrução explícita
*"se o turno pesar, PARE, commite o provado e diga em que tarefa parou"*.
