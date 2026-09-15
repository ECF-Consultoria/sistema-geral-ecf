---
quick_id: 260915-mtj
slug: confirmacao-de-contrato-mostra-e-confere
date: 2026-09-15
type: quick
status: pending
---

# A confirmação de contrato mostra e confere o CNPJ antes de gravar

## O defeito

Na tela `/administrativo/contratos/tabelas`, ao confirmar a leitura de um contrato do Clicksign,
`TabelasContratoController::confirmar()` (~linhas 195-221, T-140-21 "de carona") grava `cnpj_lido` e
`razao_social_lida` na empresa escolhida quando ela está com o campo vazio. **A pessoa não vê isso
acontecer:** `PainelConferencia` (`resources/js/Pages/Admin/TabelasContrato.jsx`, ~linha 89) mostra o
CNPJ lido do contrato, mas não mostra o CNPJ da empresa escolhida, não compara os dois e não avisa o
que vai ser gravado.

Medido em produção em 2026-09-15:

| | |
|---|---|
| confirmações | 57 |
| gravaram CNPJ na empresa de carona | **44** |
| dessas, o nome do contrato não bate com o da empresa | **11** (a conferir pelo usuário — alguns são razão social de pessoa física/nome antigo, outros podem ser erro) |
| CNPJ do contrato diferente do da empresa | 3 (uma legítima: CAMILLO filial `93.734.150/0005-33` × contrato `93734150000100`) |

Caso real que motivou: o contrato da BORIM (CNPJ 08040574000103) foi confirmado na "ByMobille -
Teste" e gravou nela o CNPJ e a razão social da BORIM. Já corrigido à mão em produção.

⚠️ **CNPJ diferente não pode ser bloqueio absoluto** — há operações legítimas com CNPJs de outra
empresa do mesmo dono (ex.: contrato "Renovação K2" confirmado na RODRICALHAS 2R).

---

## T1 — Uma regra só de comparação

Criar um helper puro (sugestão: `app/Support/Cnpj.php`, ou método estático em classe de suporte já
existente se houver uma de CNPJ — procure antes com `grep -ri cnpj app/Support`):

- `digitos(?string): string` — só dígitos
- `raiz(?string): ?string` — 8 primeiros dígitos, `null` se não houver 14 dígitos
- `comparar(?string $contrato, ?string $empresa): string` → um de:
  - `igual` — 14 dígitos iguais
  - `mesma_empresa_outra_unidade` — raízes iguais, dígitos diferentes
  - `diferente` — raízes diferentes
  - `empresa_sem_cnpj` — empresa vazia, contrato preenchido
  - `contrato_sem_cnpj` — contrato vazio (independe da empresa)

A tela precisa do resultado **antes** do clique para cada empresa escolhível. Opção mais simples,
e é a pedida: o JSX espelha a mesma regra em ~10 linhas (só dígitos, raiz = 8 primeiros), com
comentário apontando o helper PHP como fonte, e **um teste trava que os estados e rótulos batem**
(ex.: teste de UI que lê o JSX e confere as cinco chaves). O servidor decide de verdade no
`confirmar()`. Documente a escolha no SUMMARY.

---

## T2 — O servidor não confia na tela

Em `confirmar()`:

1. Validar campo novo `confirmo_cnpj_diferente` (`sometimes|boolean`).
2. **Antes de qualquer escrita** (junto da validação de faixas do quick 260910-l7k, antes do
   `DB::transaction`): se `comparar(cnpj_lido, company.cnpj) === 'diferente'` e o campo não vier
   `true` → `abort(422, ...)` com mensagem pt-BR sem jargão, ex.:
   *"O CNPJ deste contrato é de outra empresa, diferente do CNPJ cadastrado em {nome}. Nada foi
   gravado. Se o contrato é mesmo desta empresa, marque que você conferiu e confirme de novo."*
   Nada gravado: nem faixa, nem company, nem proposta (segue `pendente`).
3. A gravação de carona **continua** (útil em 44 casos), só quando a empresa está vazia, como hoje.
   A mensagem flash passa a dizer o que foi gravado, ex.: *"… CNPJ e razão social do contrato
   gravados em {nome}."* Se só um dos dois foi gravado, dizer só esse.
4. Guarda de unicidade (~linha 205): comparar **por dígitos** dos dois lados. Hoje
   `where cnpj = cnpj_lido OR cnpj = normalizado` não pega CNPJ gravado com máscara em outra empresa.
   Em MariaDB e SQLite, `REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','')` funciona nos dois
   (conferir que o teste roda em SQLite). Documente no SUMMARY.
5. Ao gravar de carona, gravar o CNPJ **como já é gravado hoje** (não mudar formato armazenado).

---

## T3 — O painel mostra antes do clique

Em `PainelConferencia`, quando há empresa escolhida, um bloco "Conferência do CNPJ" com contrato ×
empresa lado a lado (razão social + CNPJ de cada) e o estado:

| estado | visual | texto (sugestão, sem jargão) |
|---|---|---|
| `igual` | verde | "Mesmo CNPJ — o contrato é desta empresa." |
| `mesma_empresa_outra_unidade` | neutro/azul | "Mesma empresa, outra unidade (matriz e filial)." |
| `diferente` | âmbar forte | "O CNPJ do contrato é de outra empresa." + checkbox "Conferi: este contrato é mesmo desta empresa" — **Confirmar só libera marcado** |
| `empresa_sem_cnpj` | âmbar leve | "Esta empresa está sem CNPJ. Ao confirmar, o CNPJ {x} e a razão social {y} do contrato serão gravados nela." (omitir a razão social se não houver lida, ou se a empresa já tiver) |
| `contrato_sem_cnpj` | neutro | "Não foi possível ler o CNPJ deste contrato — confira pelo nome." |

- enviar `confirmo_cnpj_diferente` no `router.post`
- trocar de empresa **desmarca** o checkbox
- mostrar a mensagem do 422 do servidor no `erro` (já existe)
- o `flash.success` já é exibido pela página? Confirmar; se o texto novo não aparecer, não crie
  mecanismo novo — só garanta que o flash existente mostra

⚠️ Vocabulário da tela (T-140-11/T-140-23, há teste travando termos): nada de "proposta", "parser",
"envelope", "score", "palpite", "raiz", "carona" como rótulo.
⚠️ Escala do Tailwind: `px-4.5`, `gap-4.5`, `py-5.5` não existem. Conferir CSS com `grep -F` do
seletor completo.

---

## Testes (`tests/Feature/Quick260915/ConfirmacaoConfereCnpjTest.php` + unit do helper)

| caso | espera |
|---|---|
| mesmo CNPJ | confirma sem o campo novo |
| mesma raiz (filial) | confirma sem o campo novo |
| CNPJ diferente sem o campo | 422; nada gravado (faixas, company, proposta pendente) |
| CNPJ diferente com o campo `true` | confirma |
| tipo `tabela` + CNPJ diferente sem o campo | 422 **antes** de gravar faixa (D-13 all-or-nothing) |
| empresa sem CNPJ | grava de carona como hoje; flash diz o que gravou |
| empresa com CNPJ mascarado × contrato só dígitos | tratado como `igual` |
| CNPJ lido já pertence a outra empresa gravado **com máscara** | não grava, avisa (guarda por dígitos) |
| contrato sem CNPJ | confirma; nada de CNPJ gravado |
| helper `comparar()` | os cinco estados |
| copy | sem os termos proibidos |
| regressão | validações do 260910-l7k intactas |

---

## Travas

⛔ `FechamentoFaixaResolver::classificar()` não se toca. ⛔ Sem deploy, sem `.env`, sem VPS, sem
alterar dado de produção (os 11 casos suspeitos são conferidos pelo usuário, não por este quick).

⚠️ Árvore compartilhada com outra sessão. Nunca `git add -A` / `git add .` / `git commit -a` /
`git stash`. `git status --porcelain app/ tests/ resources/` antes de cada commit. **Arquivo novo
precisa de `git add -- <caminho>` explícito antes do `git commit -- <caminhos>`.**
`tests/Feature/CompanyPortfolioAccessTest.php` não é seu.

⚠️ Não use `gsd-sdk query state.advance-plan`.

PHP: `C:\xampp\php\php.exe`. `npm run build` ao final. Comentários, copy e commits em **pt-BR**.

**Gates (nenhum pode regredir, exit capturado antes de pipe):**
`--filter="Phase140|Phase142|Quick260910|Quick260911|Quick260915"`.
Rode o gate ANTES de começar para registrar a referência, e de novo ao final.

Ao final, grave `SUMMARY.md` nesta pasta (se a ferramenta recusar `.md`, devolva o conteúdo no
relatório final para o orquestrador gravar).
