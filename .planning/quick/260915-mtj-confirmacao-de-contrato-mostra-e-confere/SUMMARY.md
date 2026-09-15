---
quick_id: 260915-mtj
slug: confirmacao-de-contrato-mostra-e-confere
date: 2026-09-15
status: complete
commits:
  - 133ef1c2
  - a531b8a7
---

# Quick 260915-mtj — A confirmação de contrato mostra e confere o CNPJ antes de gravar

> O executor não conseguiu gravar este arquivo (a ferramenta recusou `.md` em subagente). O
> orquestrador gravou a partir do relatório final e conferiu os commits e o gate de novo.

A confirmação da leitura de contrato passa a comparar o CNPJ do contrato com o da empresa escolhida
por uma regra só (`App\Support\Cnpj::comparar()`, cinco estados). O servidor recusa (422, antes de
qualquer escrita) contrato com CNPJ de outra empresa sem a marcação "Conferi"; o painel mostra a
comparação antes do clique; a guarda de CNPJ duplicado passa a comparar por dígitos; e a mensagem de
sucesso diz o que foi gravado na empresa.

## Commits

| tarefa | commit | arquivos |
|---|---|---|
| T1 + T2 (backend + testes) | `133ef1c2` | `app/Support/Cnpj.php`, `app/Http/Controllers/TabelasContratoController.php`, `tests/Unit/Quick260915/CnpjCompararTest.php`, `tests/Feature/Quick260915/ConfirmacaoConfereCnpjTest.php` |
| T3 (tela + teste da tela) | `a531b8a7` | `resources/js/Pages/Admin/TabelasContrato.jsx`, `tests/Feature/Quick260915/ConferenciaCnpjTelaTest.php` |

## Decisões

**1. Onde vive a regra.** Fonte: `App\Support\Cnpj` (classe que já existia, com dígito verificador),
que ganhou `digitos()`, `raiz()`, `comparar()` e as constantes `COMPARACAO_*` / `COMPARACOES`. O JSX
espelha em ~10 linhas (`digitosCnpj`, `inicioCnpj`, `compararCnpj` — `inicio` para não usar "raiz"
na tela), com comentário apontando o helper. `ConferenciaCnpjTelaTest` trava que as chaves de
`CONFERENCIA_CNPJ` são exatamente `Cnpj::COMPARACOES`, que `compararCnpj` devolve os cinco estados e
que a régua é a mesma (só dígitos, 14 dígitos, 8 primeiros). Quem decide de verdade é o `confirmar()`.

Ordem em `comparar()`:
1. contrato sem 14 dígitos → `contrato_sem_cnpj` (CNPJ lido incompleto conta como não lido; não bloqueia)
2. empresa vazia → `empresa_sem_cnpj`
3. dígitos iguais → `igual`
4. 8 primeiros iguais → `mesma_empresa_outra_unidade`
5. resto → `diferente` (inclui CNPJ malformado na empresa, que exige marcar "Conferi")

**2. Guarda de unicidade por dígitos.** Era `cnpj = lido OR cnpj = normalizado`; passou a
`cnpj = lido OR REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','') = dígitos`, igual em MariaDB e
SQLite. Teste `cnpj_lido_ja_gravado_com_mascara_em_outra_empresa_nao_grava_e_avisa`. O formato
armazenado não mudou (continua gravando o texto lido do contrato).

**3. `ValidationException` em vez de `abort(422)`** (desvio consciente do plano). Numa visita Inertia,
`abort(422)` vira modal de página de erro e não chega ao `onError` do painel.
`ValidationException::withMessages(['confirmo_cnpj_diferente' => ...])` continua 422 em JSON
(testado com `postJson`) e chega ao `onError`. A checagem roda antes da validação de faixas do
260910-l7k e antes do `DB::transaction`: nada gravado, proposta segue pendente.

## Tela (T3)

- bloco "Conferência do CNPJ": contrato × empresa lado a lado, estado em cinco visuais
- `diferente`: checkbox "Conferi: este contrato é mesmo desta empresa"; Confirmar só libera marcado;
  trocar de empresa (atalho ou busca) desmarca, via `escolherEmpresa()`
- `empresa_sem_cnpj`: diz o que será gravado (CNPJ + razão social, ou só CNPJ se a empresa já tem
  razão social); se o CNPJ já está em outra empresa da lista, avisa que não será gravado (mesma
  guarda do servidor, melhor esforço — a lista só tem ativas)
- razão social vazia na empresa com razão lida no contrato: avisa que será gravada
- `router.post` envia `confirmo_cnpj_diferente`
- `flash.success` já saía no toast do `AppLayout`; `flash.aviso` **não aparecia nesta tela** — foi
  adicionado no mesmo molde de `Admin/TabelaEmpresa.jsx`, sem mecanismo novo

## Gate `--filter="Phase140|Phase142|Quick260910|Quick260911|Quick260915"`

| | passando | falhas | asserções | exit |
|---|---|---|---|---|
| antes | 258 | 0 | 1008 | 0 |
| depois (executor) | 280 | 0 | 1108 | 0 |
| **reconferido pelo orquestrador** | **280** | **0** | **1108** | **0** |

22 testes novos (5 unit do helper, 13 feature da confirmação, 4 da tela).

**Comportamento que mudou em teste existente:**
`Phase140ConfirmacaoTabelaTest::confirmar_nunca_sobrescreve_cnpj_ou_razao_social_ja_preenchidos` usa
CNPJs diferentes e agora recebe erro de validação em vez de confirmar; as asserções (empresa intacta)
continuam verdadeiras e o teste passa. `Phase141ProcedenciaTabelaTest` confirma com `cnpj_lido` nulo
(`contrato_sem_cnpj`) e não é afetado.

## Build

`npm run build` exit 0; `Pages/Admin/TabelasContrato.jsx` no `manifest.json`. Classes novas conferidas
no CSS com `grep -F`: `border-sky-500/30`, `bg-sky-500/10`, `text-sky-300`, `border-amber-500/60`,
`bg-amber-500/15`, `text-amber-200`, `accent-amber-400`, `md:grid-cols-2`.

## Fora do escopo — registrar

- A recusa de tabela malformada do 260910-l7k continua em `abort(422)` e aparece como modal de erro no
  Inertia, não no `erro` do painel. Trocar por `ValidationException` num próximo quick.
- **11 confirmações antigas** gravaram CNPJ de carona com nome do contrato que não bate com o da
  empresa (medido em produção 2026-09-15): ADVANZ → AVF2K COMERCIAL, ZM DISTRIBUIDORA →
  ZMDISTRIBUIDORA, ISABEL C. DO CARMO → KAPRAKAZA.COM, Renovação OXON → CENTRO OESTE INOXX,
  Renovação K2 → RODRICALHAS 2R, ISABELLA BARCELOS CRUVINEL → Tuki Pet, RALEN → DEUSA COSMÉTICOS,
  Mercoimport → Marc ecom, Brendha Spinelli → TSOCKS BRASIL, ALUMEN → ALCOMERCIOEIMPORTACAO,
  RESILFER → Fortexstil. Mais duas com CNPJ diferente do da empresa: D.A. BONFANTE → WEHOUSE e
  K2 → RODRICALHAS. Conferência com o usuário; nenhum dado alterado por este quick.

## Estado em produção

**Nada deployado.** Os commits estão só no repositório.
