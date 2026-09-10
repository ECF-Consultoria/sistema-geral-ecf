---
phase: 142-cadastro-da-tabela-progressiva-no-contrato
plan: 03
subsystem: frontend
tags: [react, inertia, react-imask, tabela-progressiva, dinheiro, contratos]

requires:
  - phase: 142-01
    provides: "tabela_faixas exposta, GravarTabelaEmpresaService (porta única de escrita)"
  - phase: 142-02
    provides: "rotas admin.contratos.tabela.*, TabelaEmpresaContratoController com as props achatadas da ficha"
provides:
  - "resources/js/lib/dinheiro.js — opções de máscara react-imask, formatarDinheiro, paraTextoDeCampo"
  - "CampoDinheiro — input mascarado que devolve número (mask.typedValue), nunca string"
  - "TabelaProgressivaFaixas — única definição da grade em todo o projeto, agora compartilhada"
  - "Admin/TabelaEmpresa.jsx — a página exclusiva de cadastro pedida em D-03"
affects: [142-04]

tech-stack:
  added: []
  patterns:
    - "CampoDinheiro lê mask.typedValue do próprio imask — nunca parseFloat de string mascarada no componente pai"
    - "Componente de grade compartilhado em Components/Fechamento/, importado por Pages/Admin/Financeiro e Pages/Admin — nunca redefinido por página"
    - "Formulário de faixas extraído como subcomponente local (FormularioFaixas) e reusado pelos blocos de empresa e de grupo na mesma página"
    - "Ponto de partida (tabela do serviço) preenche o formulário sem salvar sozinho — precisa de clique explícito + confirmação humana antes do POST"

key-files:
  created:
    - resources/js/lib/dinheiro.js
    - resources/js/Components/ui/campo-dinheiro.jsx
    - resources/js/Components/Fechamento/TabelaProgressivaFaixas.jsx
    - resources/js/Pages/Admin/TabelaEmpresa.jsx
    - tests/Feature/Phase142/Phase142FichaTabelaUiTest.php
  modified:
    - resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx
    - tests/Feature/Phase139/Phase139TabelaProgressivaFielTest.php

key-decisions:
  - "Lookbehind do teste de jargão (Phase142FichaTabelaUiTest) exclui '.' como caractere anterior à palavra banida — sem isso, `tabelaAplicada.origem` (acesso de propriedade à chave `origem` que o próprio 142-02-PLAN.md define, sem sufixo) seria falso positivo de 'jargão visível', quando na verdade é código nunca lido pela pessoa que usa a tela"
  - "Teste 'linhas tem padding 12px 18px e texto 13px' passou a ler TabelaFaixasSection.jsx + o arquivo novo da grade juntos — o 'text-[13px]' original nunca foi da grade em si (linhas usam text-[14px]), é do título 'Tabela progressiva' que continua no arquivo antigo; isolar a leitura só no arquivo novo teria quebrado o teste por um motivo alheio à extração"
  - "CampoDinheiro aceita valor null e devolve null quando o campo fica vazio (nunca 0 fingindo um valor) — mesma disciplina do fmtBRL/fmtValorFaixa já usada em TabelaFaixasSection"
  - "avisoAcimaDe (100.000) só no campo 'Valor da mensalidade' — é o campo onde um zero a mais tem o efeito prático mais direto sobre a fatura; não bloqueia envio, só chama atenção antes de salvar"

requirements-completed: [D-01, D-02, D-03]

duration: ~95min
completed: 2026-09-10
---

# Phase 142 Plan 03: Máscara de dinheiro, grade compartilhada e a página exclusiva da ficha Summary

**`react-imask` formatando os campos de valor com separador de milhar (o zero a mais fica visível), a grade `TabelaProgressivaFaixas` virando componente único do projeto, e `Admin/TabelaEmpresa.jsx` abrindo o formulário preenchido com o que está GRAVADO — a dívida do 137-09 paga por completo.**

## Performance

- **Duration:** ~95 min
- **Started:** 2026-09-10
- **Completed:** 2026-09-10
- **Tasks:** 3/3
- **Files modified:** 7 (5 criados, 2 modificados)

## Accomplishments

- `resources/js/lib/dinheiro.js` — `MASCARA_DINHEIRO` (opções do imask: `mask: Number`, `scale: 2`, separador de milhar `.`, vírgula decimal, sem negativo), `formatarDinheiro()` (exibição, `null` vira `—`) e `paraTextoDeCampo()` (número → texto com vírgula, para alimentar o `value` do campo mascarado).
- `Components/ui/campo-dinheiro.jsx` — `CampoDinheiro` envolve `IMaskInput` e devolve o número já tipado por `onAccept={(_, mask) => onChange(mask.value === '' ? null : mask.typedValue)}` — nunca um `parseFloat` da string mascarada no componente pai. Prop `avisoAcimaDe` renderiza uma linha âmbar quando o valor passa do teto informado, sem bloquear nada.
- `TabelaProgressivaFaixas` saiu de dentro de `TabelaFaixasSection.jsx` (Fase 139) para `Components/Fechamento/TabelaProgressivaFaixas.jsx`, byte a byte na densidade (`grid-cols-[80px_1fr_160px]`, `px-[18px] py-2.5`/`py-3`, `text-[13px]`/`text-[14px]`, `bg-ecf-card-2`, `rounded-xl border border-white/[0.06]`) — agora é a ÚNICA definição em todo o projeto, importada por `TabelaFaixasSection.jsx` e por `TabelaEmpresa.jsx`. Ganhou prop `notaRodape` opcional, reservada para o plano 04.
- `Phase139TabelaProgressivaFielTest` retargetado e FORTALECIDO: "uma definição"/"dois usos"/"cabeçalho único" agora são medidos no PROJETO INTEIRO (varredura recursiva de `.jsx`), não só no arquivo antigo — o risco que o teste sempre perseguiu foi grade duplicada, não o nome de um arquivo.
- `Admin/TabelaEmpresa.jsx` (430 linhas): Bloco 1 mostra o que está cobrando a empresa hoje (`tabela_aplicada`, que pode ser do grupo); Bloco 2 traduz `procedencia_empresa` sem jargão (selo verde "Conferida pelo contrato assinado" / neutro "Cadastrada à mão no sistema" / neutro + aviso para tabela copiada do serviço, nunca a palavra "presumida" na tela); Bloco 3 só aparece quando há `leitura_pendente`, com a copy obrigatória "parece ser desta empresa" e link para a caixa de entrada da Fase 140; Bloco 4/5 (empresa/grupo) reusam a subcomponente local `FormularioFaixas` — formulário abre com `tabela_empresa`/`tabela_grupo` (as linhas gravadas), "Começar a partir da tabela de X" preenche sem salvar sozinho, e salvar sobre uma tabela vinda do contrato pede confirmação nomeando a consequência.
- `Phase142FichaTabelaUiTest` (11 testes): CampoDinheiro sem `type="number"` cru nos campos de valor (só "Ordem" pode usar), `mask.typedValue` presente e `parseFloat` ausente do código, `tabela_empresa` como estado inicial, o aviso antigo "Os valores atuais não são carregados aqui" ausente, a grade importada (nunca uma segunda `function TabelaProgressivaFaixas(`), as quatro rotas certas (nunca `admin.financeiro.faixas.*`), o link cruzado com a copy de palpite, as oito palavras banidas ausentes do texto visível, e a notação quebrada de decimais do Tailwind ausente nos quatro arquivos novos.
- CSS compilado conferido por script Node (nunca `grep` do shell — colchetes escapam mal e dão falso negativo): `grid-cols-[80px_1fr_160px]`, `px-[18px]`, `text-[13px]` e `border-white/[0.06]` confirmados presentes no build, depois de limpar `node_modules/.vite` + `public/build` para eliminar qualquer dúvida de cache.

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: máscara de dinheiro e a grade compartilhada** — `8b8ccb1f` feat(142-03): mascara de dinheiro + grade compartilhada da tabela progressiva
2. **Tarefa 2: a página exclusiva Admin/TabelaEmpresa.jsx** — `09ff8caf` feat(142-03): pagina exclusiva Admin/TabelaEmpresa.jsx (D-03)
3. **Tarefa 3: travas de arquivo da ficha + conferência do CSS compilado** — `bf0e454a` test(142-03): travas de arquivo da ficha + conferencia do CSS compilado

## Files Created/Modified

- `resources/js/lib/dinheiro.js` — `MASCARA_DINHEIRO`, `formatarDinheiro()`, `paraTextoDeCampo()`
- `resources/js/Components/ui/campo-dinheiro.jsx` — `CampoDinheiro`, input mascarado que devolve número
- `resources/js/Components/Fechamento/TabelaProgressivaFaixas.jsx` — a grade, extraída byte a byte, com `notaRodape` opcional
- `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx` — importa a grade em vez de definir localmente; `fmtBRL`/`fmtValorFaixa` locais removidos (só existiam para a grade)
- `resources/js/Pages/Admin/TabelaEmpresa.jsx` — a página exclusiva (D-03), com `BlocoTabelaAplicada`, `BlocoProcedencia` e `FormularioFaixas` como subcomponentes locais
- `tests/Feature/Phase139/Phase139TabelaProgressivaFielTest.php` — retarget para medir "uma definição"/"cabeçalho único" no projeto inteiro
- `tests/Feature/Phase142/Phase142FichaTabelaUiTest.php` — 11 testes de arquivo da ficha nova

## Decisions Made

- **Lookbehind do teste de jargão ampliado para excluir `.`** — ver `key-decisions` acima. Sem esse ajuste, o acesso `tabelaAplicada.origem` (código, não copy) seria confundido com a palavra banida "origem" aparecendo como texto visível.
- **Teste de densidade "texto 13px" lê dois arquivos, não um** — o valor `text-[13px]` nunca esteve dentro da grade (linhas da grade usam `text-[14px]`); está no título "Tabela progressiva" de `TabelaFaixasSection.jsx`, que a extração não moveu. Isolar a leitura só no arquivo novo da grade teria quebrado esse teste por um motivo que nada tem a ver com a extração em si.
- **`CampoDinheiro` sempre devolve `null` para campo vazio, nunca `0`** — um valor ausente não é zero (mesma disciplina do `fmtBRL` já existente); evita gravar mensalidade R$ 0,00 por engano de um campo em branco.
- **`avisoAcimaDe={100000}` só no campo "Valor da mensalidade"**, não no "Faturamento até" — é o campo cujo erro de dígito afeta a fatura diretamente; o teto de faturamento tem faixas naturalmente maiores e um aviso ali teria alta taxa de falso positivo.

## Deviations from Plan

Nenhuma de comportamento — plano executado como escrito. Um ponto de investigação, sem alteração de plano:

- **[Investigação, não Rule] Falso negativo inicial na conferência do CSS compilado.** A primeira checagem usou `node -e` inline via Bash, cujo escaping de barra invertida através das camadas de shell (Bash → PowerShell/cmd subjacente) corrompeu os padrões `\[`/`\/` usados para procurar os seletores CSS escapados — resultado: "FALTA" para as quatro classes, inclusive `px-[22px]` de um arquivo (`ContratoDetalhe.jsx`) que nem foi tocado por este plano, o que já indicava problema na ferramenta de checagem, não no CSS. Refeita como script de arquivo (`.js` gravado no scratchpad, executado com `node caminho.js`) — sem a cadeia de escaping do `-e`, as quatro classes apareceram confirmadas. `node_modules/.vite` + `public/build` foram limpos e o projeto reconstruído do zero antes da checagem final, para eliminar qualquer dúvida de cache. Nenhum código de produção foi alterado por causa disso — o problema era só no script de verificação.

## Issues Encountered

Nenhum além do já documentado em Deviations.

## User Setup Required

None — nenhuma configuração de serviço externo. `react-imask` já estava em `package.json` desde a Fase 34, nenhum pacote novo instalado.

## Next Phase Readiness

- A grade compartilhada, a máscara e a página exclusiva estão prontas para o plano 04 (fechamento passa a só mostrar, nunca editar) reaproveitar — `notaRodape` já existe em `TabelaProgressivaFaixas` para o plano 04 dizer, num mês fechado, que a tabela exibida é a cadastrada hoje.
- **Gate de testes:** `587 → 598 testes` (11 novos), `2777 → 2809 asserções`, **0 falhas**.
- Falhas pré-existentes listadas no prompt de execução (`Phase138AvisoMudancaFaixaTest`, `Phase13ComercialTest`, `Phase14VerificarCobrancaTest`, `FechamentoMigrationTest`, `AdminFechamentoControllerTest`, `Phase14MigrationTest`, `Phase14MlbControllerFiltroTest`) não fazem parte do filtro do gate desta fase e não foram tocadas.

---
*Phase: 142-cadastro-da-tabela-progressiva-no-contrato*
*Completed: 2026-09-10*

## Self-Check: PASSED

Arquivos criados confirmados em disco: `resources/js/lib/dinheiro.js`, `resources/js/Components/ui/campo-dinheiro.jsx`,
`resources/js/Components/Fechamento/TabelaProgressivaFaixas.jsx`, `resources/js/Pages/Admin/TabelaEmpresa.jsx`,
`tests/Feature/Phase142/Phase142FichaTabelaUiTest.php`. Os três hashes de commit (`8b8ccb1f`, `09ff8caf`, `bf0e454a`)
confirmados via `git log --oneline`. Gate final rodado com árvore limpa nos caminhos deste plano: 598 testes / 2809
asserções / 0 falhas.
