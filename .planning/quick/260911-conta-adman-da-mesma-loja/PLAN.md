---
quick_id: 260911-jpx
slug: conta-adman-da-mesma-loja
date: 2026-09-11
type: quick
status: pending
---

# O corte certo não é o token ML — é a conta Adman apontar para a MESMA loja

## O que aconteceu

O quick `260911-eph` pôs o faturamento do mês fechado para vir do `/performance` da Adman, com um
corte: empresa `is_ml_driven` (token ML ativo) fica de fora. **Esse corte está errado** e o caso que
originou o trabalho — a DESK DESIGN, que o usuário conferiu contra a tela da Adman — ficou de fora
da correção.

Reconsolidação de agosto rodada em produção em 2026-09-11: 51 empresas pela API, 150 pela soma
diária, 1 fallback. A DESK DESIGN saiu como `soma_diaria`, em R$ 167.537,54, quando a Adman mostra
**R$ 170.363,19**.

## Por que o corte saiu errado

Eu (o orquestrador) inferi a regra de **um** caso: a LAURA LAR tem R$ 2,7 milhões na nossa base e a
conta Adman dela devolve R$ 12.966. Conclui "para `ml_driven` a conta Adman está abandonada".

O que a LAURA LAR tem de especial **não é o token ML**:

| empresa | `adman_account_id` | `ml_store_id` | |
|---|---|---|---|
| DESK DESIGN | 51493328 | **51493328** | mesma loja |
| LAURA LAR | 273196837 | **433720509** | contas diferentes |

Medição em produção das 60 empresas `ml_driven` que têm conta Adman:

| grupo | empresas | o que a API devolve |
|---|---|---|
| **ids IGUAIS** | **58** | 53 com diferença entre −1% e +15% — assinatura de ajuste retroativo |
| **ids DIFERENTES** | **2** | MAXIGOLD **+2118%**, LAURA LAR **−99,5%** |

As duas únicas anomalias são exatamente as de id trocado. O corte por `is_ml_driven` jogava fora 58
empresas boas para proteger-se de 2.

---

## O que fazer

Mexer **num método só**: `FechamentoRollupService::podeUsarApiDaAdman()` (~linha 405).

```php
private function podeUsarApiDaAdman(Company $company): bool
{
    if ($company->cust_id === null) {
        return false;                       // nada a chamar
    }

    if (! $company->is_ml_driven) {
        return true;                        // caminho Adman puro, inalterado
    }

    // ml_driven: só quando a conta Adman acompanha A MESMA loja do ML.
    return filled($company->adman_account_id)
        && filled($company->ml_store_id)
        && (string) $company->adman_account_id === (string) $company->ml_store_id;
}
```

⚠️ **Reescrever o docblock.** O texto de hoje ensina a regra ERRADA, com a LAURA LAR como prova —
e ela prova outra coisa. Quem ler daqui a seis meses tem de encontrar a tabela dos dois ids acima,
não a conclusão velha. Este é o entregável mais importante depois do código.

⚠️ **Comparar como string.** Os dois campos são texto; `==` com números em PHP faz coerção e
`'0051493328' == '51493328'` daria `true`. Use `===` sobre `(string)`.

⚠️ **`ml_store_id` vazio em empresa `ml_driven` → NÃO usa a API.** São 16 empresas assim em produção
(token ML, sem conta Adman própria). `filled()` nos dois lados cobre isso — não troque por
comparação solta, `null === null` não pode virar "pode usar".

⚠️ **Não mexer no ramo não-`ml_driven`.** Ele já funciona e são 53 empresas em cobrança viva.

---

## Testes

`tests/Feature/Quick260911/FaturamentoDaApiNoRollupTest.php` já existe e tem o caso
"empresa ml_driven não chama a API". **Esse teste agora está codificando a regra errada** — ele
precisa mudar, e é a única exceção à disciplina de não editar teste existente. Deixe claro no
commit que a mudança é intencional e por quê.

Casos novos, todos com `Http::fake()`:

| caso | espera |
|---|---|
| `ml_driven` + ids IGUAIS | **usa a API**, fonte `api` |
| `ml_driven` + ids DIFERENTES | **não** chama a API, fonte `soma_diaria` |
| `ml_driven` + `ml_store_id` vazio | não chama, fonte `soma_diaria` |
| `ml_driven` + `adman_account_id` vazio | não chama, fonte `soma_diaria` |
| não `ml_driven` + `cust_id` | usa a API (regressão — não pode mudar) |
| sem `cust_id` | não chama |
| ids iguais com espaço/zero à esquerda (`'051'` vs `'51'`) | **não** são a mesma loja |

---

## O que isto muda em produção (já medido, para conferir depois)

| | |
|---|---|
| empresas que passam a usar a API | 51 → **~109** |
| faturamento somado de agosto | **+R$ 1.016.802,46** |
| **mudam de faixa** | **2** |
| DESK DESIGN | R$ 167.537,54 → **R$ 170.363,19** |

As duas que mudam de faixa (ambas passaram de R$ 500.000 e estavam na faixa de baixo):

- **CAMILLO PARTS MATRIZ** — R$ 497.134,67 → R$ 514.349,65 · R$ 3.000 → **R$ 4.500**
- **LUCCAUTO.COM** — R$ 489.257,53 → R$ 519.207,42 · R$ 3.000 → **R$ 4.500**

Efeito na cobrança: **+R$ 3.000/mês**. Autorizado pelo usuário em 2026-09-11.

---

## Travas

⚠️ **Isto muda o que duas empresas pagam.** A régua de classificação **não se toca**:
`FechamentoFaixaResolver` e `CobrancaCalculator` ficam como estão. Muda só quem pode ler o
faturamento da API.

⚠️ **Não mexer na tela, no comando nem na migration** — `260911-eph` entregou tudo isso e está no
ar. Este quick é um método e o docblock dele, mais testes.

⚠️ **A chave `fechamento_faturamento_da_api_ativo` já está LIGADA em produção.** Não mexa nela.

⚠️ **Executores não alcançam produção nem a API da Adman** — tudo com `Http::fake()`.

⚠️ **Árvore compartilhada, e o outro dev acabou de integrar a milestone v23.0 (201 arquivos).**
Nunca `git add -A` / `git add .` / `git commit -a` / `git stash`. Commite só os seus caminhos e rode
`git status --porcelain app/ tests/` (sem `--untracked-files=no`) antes de cada commit.

⚠️ **A suíte está VERMELHA por um motivo que não é seu**: a migration nova
`2026_09_10_140000_seed_setor_performance.php` (do outro dev) cria o setor "Performance", e 42
arquivos de teste criam um setor com o mesmo `nome`, que é UNIQUE. **Não conserte isso aqui** — é
tarefa própria. Só não confunda essas falhas com regressão sua: filtre pelo erro
`UNIQUE constraint failed: setores.nome` ao ler o resultado.

⚠️ **Não use `gsd-sdk query state.advance-plan`.**

⛔ Sem deploy, sem `.env`.

PHP: `C:\xampp\php\php.exe`. Comentários e commits em **pt-BR**.

**Gate:** `--filter="Quick260911"` precisa ficar **verde**. Para o gate largo
(`Phase122|...|Quick260910|Quick260911`), reporte o número e **separe** as falhas de
`setores.nome` das demais — só as demais contam contra você.
