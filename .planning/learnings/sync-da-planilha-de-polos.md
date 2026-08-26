# Sync da planilha de Polos — o que já custou caro

Leitura obrigatória antes de rodar `polos:sync-planilha` contra produção. Escrito em
2026-08-26, depois do sync da `MAPEAMENTOPOLOSV2.xlsx` (474 lojas; a anterior, de 31/07,
tinha 394). Complementa [`painel-polos-status-e-meta.md`](painel-polos-status-e-meta.md),
que trata de status/meta — aqui é só o **sync**.

A planilha é a fonte de verdade do cadastro de Polos e o time trabalha nela quando para de
usar o sistema. Isso significa que ela chega **editada à mão**, com tudo que isso implica.

## 1. Confira o CABEÇALHO antes de qualquer coisa

Na planilha de 26/08 a coluna `Fase` chegou com o título **`Loja 2`** — alguém digitou por
cima do cabeçalho; os dados de fase seguiam intactos embaixo. A célula `A1`, que era
`Data de entrada`, virou **`Artmobilia`** (um nome de loja). O comando exige `Fase` e
**abortava na largada** por causa de um título trocado.

Hoje existe `SyncPolosPlanilha::COL_ALIASES` (`Fase` aceita `Loja 2` como alias, com o nome
canônico mantendo precedência), mas o alias é rede de segurança, não solução: o próximo
cabeçalho torto vai ter outro nome. **Antes de rodar, liste o cabeçalho e compare com a
versão anterior.** Uma coluna que não casa não gera erro — ela só some do relatório
"Campos alterados", em silêncio. Foi assim que `reuniao_onboarding` ficou meses sem ser
gravado (ver §"Bug corrigido 260731" no histórico do comando).

## 2. A ordem é RECONCILIAR → sync. Nunca sync direto

O match do sync é **cust_id-first e não cai para nome quando a linha TEM cust_id**: se o
cust não casa, ele **cria**. Mas o cadastro nasce por duas portas — a sync de Entrantes cria
registros **sem** cust_id (fase "Aceite no Projeto"/M0), e quando a planilha depois traz o
mesmo seller **com** cust_id, o sync não acha o registro name-only e **duplica**.

Em 31/07 seriam 38 duplicatas; em 26/08, 13. Reconciliar antes converte cada uma num update
in-place e **preserva a ficha que o cliente preencheu** (`dados` JSON, `ultimo_acesso`).

`--arquivar-ausentes` **não** limpa essas duplicatas: o nome do registro antigo casa com
alguma linha da planilha, então ele não conta como ausente.

Desde 26/08 isso é o comando `polos:reconciliar-planilha` (antes era feito à mão a cada
sync). Casa por token do `Link da Planilha` — a chave mais confiável, aponta para o registro
exato — e, na falta, por nome normalizado + gmail colaborador.

## 3. Preview que não bate com a execução é pior que não ter preview

A primeira versão do `polos:reconciliar-planilha` mostrava, no dry-run, as **duas** linhas de
"KL Móveis" fazendo backfill para o **mesmo** registro. No `--apply` o comportamento era
outro: a primeira gravava o cust e a segunda caía no ramo de divergência. O resultado final
até era o desejado — **por acidente de ordenação**.

Isso é grave porque é justamente o dry-run que autoriza o `--apply`. Hoje o registro alvo é
**reservado** pela primeira linha que o reivindica e as seguintes são reportadas como
ambíguas. Ao mexer no comando, mantenha a invariante: **o mesmo caminho de código decide em
dry-run e em apply**; só o `save()` fica atrás do `if ($apply)`.

## 4. Sujeiras recorrentes da planilha (não são bugs do código)

- **Mesma loja em duas linhas com fases conflitantes** — vence a **última** linha. Em 26/08:
  Kafer, Natture, Wood Prime, Casa Nova Web, TREO MADE, SS6STORE, KL Móveis. Por isso
  "Encaminhar Comercial" tinha 12 linhas na planilha e o banco terminou com 11 (Wood Prime
  reaparece depois como Desistência).
- **Mesmo `Link da Planilha` colado em lojas diferentes** — em 26/08: O Mestre Marceneiro/H B
  Cadeiras, Aurea Curadoria/Clodi IDolc, Casa Mais Decor/RW Decor. O comando trata token
  repetido como ambíguo e **pula**; sem isso o backfill escolheria o registro errado.
- **Duas lojas com nome, gmail e link idênticos e custs diferentes** (KL Móveis, 26/08). Não
  é sempre erro — às vezes é o mesmo cliente com duas contas. **Pergunte**; não desempate no
  código.
- **Troca de conta ML**: o token casa mas o cust_id diverge (Amo Eletros, 26/08:
  1164686271 → 3615090602). Repontar re-aponta faturamento/snapshots, então fica atrás de
  `--trocar-cust`. **Confira quantas divergências o dry-run acusa antes de usar a flag — ela
  é global.**
- **Valores de `Publicação` fora do `ESTAGIO_MAP`** (26/08: `AnuncioTeste` ×4, `Sim` ×1).
  O estágio fica intocado e o comando reporta. Não invente mapeamento.

## 5. Deploy: o `main` local costuma estar MUITO atrás

Em 26/08 o `main` desta máquina estava **603 commits atrás** do `origin/main`. Commitar e
deployar a partir dele destruiria features de produção. E `deploy.sh` exige árvore limpa e
`HEAD == origin/main` — a árvore compartilhada **nunca** está limpa, porque há trabalho de
outra sessão em curso.

Receita que funcionou:

1. `git worktree add -b <branch> c:/tmp/<dir> origin/main`
2. `git cherry-pick <seu commit>` — confira depois que **as duas** contribuições convivem no
   arquivo (a sua e a que veio de `origin/main`)
3. copiar `plink.exe`/`pscp.exe` para o worktree (não são versionados; `deploy.sh` os procura
   ao lado de si)
4. `git push origin <branch>:main` e rodar `./deploy.sh` **de dentro do worktree**

O worktree não tem `vendor/`: para rodar a suíte, espelhe os arquivos no repo principal.
Ao remover o worktree, **cuidado com junction do Windows** — `git worktree remove --force`
sobre uma junction já apagou o `vendor/` real do repositório principal (incidente 260731).

## 6. Commit por caminho arrasta o trabalho da outra sessão

Vários arquivos deste módulo (`MlbImplementacao.php`, `MlbImplementacaoController.php`,
`Painel.jsx`) estavam **já modificados** por outra sessão quando comecei. `git commit -- <arq>`
teria levado junto uma precificação inacabada para produção.

O que fazer: gerar o patch por arquivo (`git diff -- <arq>`), **manter só os seus hunks**,
`git apply --cached` e commitar **sem passar caminhos** (com caminhos o git commita a versão
da árvore, ignorando o índice). Valide a versão *staged*, não a do disco:
`git show :<arq> > /tmp/x.php && php -l /tmp/x.php`.

Detalhe: `git show :<arq> | php -l` num arquivo grande dá "Unterminated comment" falso pelo
pipe. Grave em arquivo antes de lintar.

## 7. Depois do sync

`polos:warm` cobre só `fase IN (M1,M2,M3,M4)` + `projeto=POLOS` + não arquivada — **M0 e
"Aceite no Projeto" ficam sem faturamento por desenho**. Tem orçamento de 1500s e ~7s de
throttle por empresa, então **uma execução não fecha a fila**; rode 2× seguidas.

`pgrep -f 'polos:warm'` **se auto-detecta** (o `bash -c` que o plink abre contém a string no
próprio cmdline), então um `until` de polling nunca termina. Use marcador no log
(`... ; echo __FIM__ >> log`) e `grep -q __FIM__`.

Adman devolvendo **NULL ≠ faturamento zero**: conta registrada sem venda devolve `gross=0,00`;
**NULL = cust_id que a Adman não conhece** (ID errado na planilha ou conta ainda não cadastrada).

## 8. Confira por reconsulta ao banco, nunca pelo stdout do comando

O relatório do sync diz o que ele *pretendia* fazer. Depois do `--apply`, reconsulte:
contagem por fase, por polo, campos novos preenchidos e **nomes duplicados entre ativas** —
é aí que duplicata criada por engano aparece.
