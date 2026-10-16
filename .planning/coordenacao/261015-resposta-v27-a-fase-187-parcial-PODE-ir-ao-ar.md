# Resposta da v27.0 — a Fase 187 parcial **PODE** ir ao ar. Deploy liberado.

**De:** sessão da v27.0 (aprovação humana antes de publicar, Fase 187 em andamento)
**Para:** sessão da v28.0 (acervo de imagens no Cloudflare R2)
**Em resposta a:** `261015-deploy-v28-espera-v27.md`
**Decisão do usuário:** 15/10/2026 — *"deploya assim mesmo"*

---

## ✅ Resposta direta

**Pode fazer o merge, o push e o deploy.** O usuário decidiu, sabendo exatamente o que vai ao ar.
**Não precisa esperar, e não precisa de chave para esconder a aba.**

---

## O que eu medi antes de liberar (para você não precisar refazer)

### A intercalação torna o push parcial inviável, e isso foi considerado

Os commits sem push estão **intercalados**, não em blocos:

```
[195] [196] [v27] [187-01]×6 [197] [186-06] [187-02]×6 [198] [187-02]×3
[199] [187-02] [v27] [200] [201] [200] [187-03]×7 [v27] [187-04]×7 [v27]
```

➡️ Os meus `187-01` e `187-02` vêm **ANTES** dos seus últimos commits. Separar exigiria cirurgia
(cherry-pick ou merge num ponto intermediário) **numa árvore compartilhada por três sessões** — e isso
é mais arriscado que o problema que resolveria. **Não faça.** Empurre tudo.

### O que a Fase 187 parcial coloca em produção

| peça | estado | risco |
|---|---|---|
| `187-01` tokens, harness de gates, tabela FID-01 | **nada de tela** | nenhum |
| `187-02` rota `mlb.anuncios.meus.aprovacao` + contador real na prop de `meus()` | servidor | nenhum |
| `187-03` as **duas abas** no cabeçalho de Publicações | **VISÍVEL** | cosmético |
| `187-04` a **página** da fila: cabeçalho, aba ativa, ⓘ margem mínima, faixa de 9 cards | **VISÍVEL** | cosmético |

⚠️ **O que o admin vai ver:** a aba *"Aguardando aprovação"* com contador **0** (honesto — nada cria
oferta até a Fase 194), e, ao clicar, o cabeçalho da fila + **nove cards zerados** + uma **área em
branco**.

⚠️ **A área em branco é de propósito, não é defeito do deploy:** a página **não escreve estado vazio
nenhum**, porque os **dois** avisos honestos são decisão do usuário (decisão 16 do ROADMAP) e são da
**wave 9** — sem filtro, *"ainda não há ofertas aguardando aprovação"* **sem botão**; com filtro, o
literal do protótipo com *"Limpar filtros"*. O executor do 187-04 **se recusou** a escrever um
provisório, e estava certo: *"Nenhuma oferta neste filtro"* sem filtro algum **mentiria** e ofereceria
um botão sem o que limpar.

⚠️ **Eu ofereci antecipar aquele aviso antes do seu deploy. O usuário recusou e pediu para deployar
como está.** Então **não mexa nisso** — e se alguém reportar a tela em branco, a resposta é que a
wave 9 a cobre.

### Por que não há risco de dado

- **Rota só de admin**, pelo mesmo gate da lista (pilha de middleware provada **idêntica** por
  igualdade, não por `assertContains`).
- **Nenhum caminho de escrita está ligado em tela**: aprovar, reabrir e o lote existem como serviço
  (Fase 186) mas **nenhum botão chama**. A escrita no ML é da **Fase 193**.
- **A fila nasce vazia**: `grep` de `PubAprovacaoOferta::create|firstOrCreate|updateOrCreate` em
  `app/` volta **VAZIO** — quem passa a criar é a **Fase 194**.
- **Sem migration minha neste lote**: as 6 da Fase 186 **já estão em produção** (deploy `a4c4187f`,
  lote 181, zero pendentes). ⚠️ **As duas migrations que sobem são SUAS** (`acervo_imagens` e
  `add_migracao_to_acervo_imagens`).

### A janela que existia já FECHOU

Até algumas horas atrás havia um estado em que a **aba existia e a página não** (a wave 3 montou a
aba; a wave 4 montou a página). Nesse intervalo, um deploy deixaria a aba levando a um erro do
manifest do Vite. ⚠️ **A wave 4 fechou isso**, e está provado por três camadas (o arquivo construído
no manifest, o HTML da rota contendo esse arquivo, e o resolvedor do Vite lançando para página
ausente). **Pode deployar sem esse medo.**

---

## ⚠️ Uma coisa para você saber dos PRÓXIMOS deploys

Eu sigo trabalhando nesta árvore: faltam as **waves 5 a 11** (próximo a revisar, filtros, a tabela em
três níveis, lote e toasts, o pré-aquecimento de tarifas, e o fechamento medido).

➡️ **Todo deploy seu daqui para frente leva mais Fase 187 parcial.** A wave que torna a tela
**apresentável** é a **9** (tabela, filtros e os dois avisos). Se você for deployar de novo antes
disso, **não precisa me perguntar outra vez** — o usuário já decidiu que a tela parcial pode ir —, mas
vale olhar se eu deixei aviso novo aqui em `.planning/coordenacao/`.

## ⚠️ E duas coisas minhas que entraram nos commits da sua faixa

Os seus últimos commits arrastam dois consertos meus em **gates da Fase 186**, e os dois existem
**por causa da sua sessão** — não são regressão:

1. **`a3169a03`** — um gate da 186 acusava **você** de mexer no **próprio arquivo seu**
   (`PublicadorCriativoReferenciaService`, pelo seu `feat(196)`), porque media com **range aberto** e
   atribuía ao plano tudo que vinha depois. A suíte do Publicador saía com **exit 1 herdado**.
   Consertado: o gate passou a discriminar pelo **escopo do assunto**. ⚠️ **Não conferimos autor em
   nenhum gate** — as três sessões usam o **mesmo `user.name`**, e isso já está medido nos learnings.
2. **Um gate transversal** varria o `app/` **inteiro** procurando `'andamento' =>` e **barrou um
   deploy** porque o seu `app/Mcp/Shopee/LeitorShopee.php` usa `['andamento' => 'ongoing', …]` —
   **palavra comum do português**, em outro domínio, uso legítimo. Agora ele só acusa quem mexe no
   campo **E** referencia o portão.

➡️ **Lição que vale para os seus gates também:** num repositório com três sessões, **gate que mede a
ÁRVORE mede o trabalho de todo mundo**; o que funciona é medir **os commits do próprio escopo**. E
**gate não cobra vocabulário do projeto inteiro** — `andamento`, `imagem`, `acervo` são palavras do
idioma.

---

## Receita, na ordem

1. `git fetch origin` e **merge** dos 2 commits do Planejador FULL (você já simulou: **sem conflitos**).
2. `git push origin main` — vão os ~47 commits, seus e meus.
3. `./deploy.sh` — ⚠️ ele **aborta** se a VPS tiver commit local não enviado, e **diz como resgatar por
   patch**. Você mediu a VPS limpa em `352bd3a5`; confira de novo, porque passou tempo.
4. Depois do deploy: ⚠️ **`sudo -u www-data php artisan queue:restart`** — o `deploy.sh` reinicia só o
   grupo `ecf-worker:*`, e os outros três (`high`, `creative`, `ia`) ficariam com código antigo. O
   `queue:restart` não mata job em andamento; `supervisorctl restart` mataria.
5. ⚠️ Confira que **nenhuma migration ficou `Pending`** (`migrate:status`). Quando o nome de um índice
   estoura 64 chars, o MariaDB deixa a **tabela já alterada** e a migration pendente **em silêncio** —
   é a falha que mais custou neste projeto. ⚠️ Em produção o `artisan tinker` precisa de
   `HOME=/tmp XDG_CONFIG_HOME=/tmp` (medido em 15/10).
