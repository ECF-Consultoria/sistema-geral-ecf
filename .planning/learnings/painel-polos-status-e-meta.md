# Painel Polos — status, meta e as armadilhas de mexer nisso

Leitura obrigatória antes de tocar em **status de empresa**, **Distribuição de status**,
**meta por polo** ou no **flag de problema**. Concentra o que não é dedutível do código.

---

## 1. "Problema" não é mais sinônimo de "fora da meta" (quick 260805-dzu, 2026-08-05)

Até 05/08/2026 `mlb_empresas.problema = true` tinha **precedência máxima** em
`PolosController::calcularStatus()`: a empresa caía no status `Problema` e sumia da meta
do polo. Na prática o time usava o flag para qualquer coisa — inclusive problemas básicos
— e isso distorcia a Distribuição de status.

Hoje quem decide é **`problema_desconsidera_meta`** (bool, default `false`):

- `false` → a empresa **continua contando**: No alvo / Em progresso / Não, conforme faturamento.
- `true`  → status `Problema`, fora da meta (comportamento antigo).

Regras que **não** são óbvias lendo o código:

- **O default `false` é intencional e retroativo.** A migration fez as 30 empresas já
  marcadas voltarem a contar pra meta — foi decisão do usuário ("problemas básicos não
  deveriam desconsiderar da meta"), não descuido. Não "conserte" isso invertendo o default.
- **Só existe um ponto de decisão**: `PolosController::desconsideraDaMeta()`. Todo call site
  de `calcularStatus()` passa por ele. Se aparecer um cálculo novo de status, use o helper —
  ler `$ativo['problema']` direto reintroduz o bug.
- **Roster de mês fechado não tem os flags.** `montarAtivosDoMes()` reconstrói o histórico do
  CSV, que não traz `problema` nem `problema_desconsidera_meta` → ambos `false`. Mês fechado
  nunca mostra `Problema`, e isso é por desenho (o CSV não guarda esse estado).
- `statusAgregado()` (pior status do polo) continua com `Problema` no topo da prioridade —
  vale para quem realmente saiu da meta.

## 2. A suíte de Polos tem falhas antigas — não as confunda com regressão sua

Em 2026-08-05, rodando `origin/main` num worktree limpo, `tests/Feature/Phase38/PolosControllerTest.php`
e `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` já davam **10 falhas**:

- os testes montam faturamento pelo **CSV** (`TGMV_LC`), mas o cálculo migrou para a **Adman**
  (`gross_billing`, sem fallback CSV) — todo teste de status dá `Não` onde espera `Sim`;
- `SyncPolosFaturamentoJob` mudou de assinatura → `ArgumentCountError` nos 2 últimos.

Antes de atribuir qualquer falha dessas suítes ao seu trabalho, **rode o baseline** (worktree
de `origin/main` + junction do `vendor/`). E cuidado: `git worktree remove --force` sobre uma
junction do Windows já apagou o `vendor/` real do repo principal (incidente 260731) — remova a
junção com `rmdir` **antes** de remover o worktree.

## 3. "Empresa polo" é `MlbEmpresa`, não `Company`

Das **285** empresas do projeto POLOS ativas, apenas **3** têm `company_id` preenchido
(medido em 2026-08-05). Qualquer módulo novo que precise listar "as empresas polos" e for
amarrado a `companies` **nasce vazio**. Foi por isso que o PPA Polos passou a apontar para
`mlb_empresas` (`ppas.mlb_empresa_id`, com `company_id` nullable).

O recorte de POLOS é `projeto = 'POLOS'` com fallback em `MlbEmpresa::FASE_PARA_PROJETO`
(campo `projeto` é canônico desde a migration 000010) — e **sempre** com `scopeAtivas()`:
empresa arquivada não conta em meta, faturamento nem listagem.

## 4. Página React que só delega precisa ser componente de verdade

`export { default } from '../../Ppa/Index'` **não entra no manifest do Vite**: o bundler
elimina o módulo e a página morre em runtime com *"Unable to locate file in Vite manifest"*.
O erro não aparece no build — só quando a rota é acessada (ou num teste Inertia).

Use um wrapper real:

```jsx
import PpaIndex from '../../Ppa/Index';
export default function PolosPpaIndex(props) { return <PpaIndex {...props} />; }
```

Motivo de existir a página delegante: o menu (`AppLayout.isActive`) casa o item ativo por
**prefixo do nome do componente**. `Polos/Ppa/Index` não colide com o item PPA (`page: 'Ppa'`);
reusar `Ppa/Index` direto deixaria os dois itens acesos ao mesmo tempo.
