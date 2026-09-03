---
quick_id: 260903-la4
slug: refazer-fechamento-sem-aviso
date: 2026-09-03
type: quick
status: in-progress
files_modified:
  - resources/js/Pages/Admin/Financeiro.jsx
  - tests/Feature/Phase137/Phase137CompetenciaUiTest.php
---

# Refazer fechamento salva, mas a tela não avisa

## Incidente (produção, 2026-09-03)

O usuário tentou "Refazer fechamento" de agosto em `/financeiro`, escreveu o motivo, confirmou, e
concluiu que **não salvou**. Medido no servidor: **funcionou as três vezes**.

- Access log: 3× `POST /administrativo/financeiro/competencia/refazer` → **HTTP 200**
- `fechamento_reconsolidacoes`: 3 linhas (ids 1, 2, 3), `reconsolidado_por=2`, motivo digitado,
  `snapshot_anterior` preenchido
- Os 201 `fechamento_snapshots` reescritos (`updated_at 2026-09-03 14:59:06`)

O usuário clicou três vezes **porque a tela não deu sinal**, e cada clique gravou uma linha na
trilha de auditoria da cobrança de agosto.

## Causa raiz (já diagnosticada — não re-investigar)

Em `resources/js/Pages/Admin/Financeiro.jsx`, o handler de sucesso do diálogo de refazer é:

```js
axios.post(route('admin.financeiro.competencia.refazer'), { mes, motivo })
    .then(() => router.reload())
```

No sucesso ele **não fecha o diálogo, não limpa o motivo e não mostra confirmação**. E como os
números da competência continuam idênticos (nada mudou na origem do dado), o `router.reload()`
devolve a tela visualmente igual. Sucesso e falha ficam indistinguíveis.

> Um sucesso silencioso é pior que um erro visível: o erro pelo menos informa. Aqui a pessoa repete
> a ação achando que não funcionou — e a ação tem efeito colateral registrado.

## Tarefas

### 1. Confirmação visível no sucesso do refazer

- Fechar o diálogo e limpar o campo de motivo.
- Mostrar a confirmação usando a `message` que o backend **já devolve** no JSON 200
  (`"Fechamento de {mês} refeito com sucesso."`) — não inventar copy nova.
- ⚠️ A confirmação precisa **sobreviver ao `router.reload()`**. Estado local do componente é apagado
  pelo reload antes de a pessoa ler. Escolher UM caminho (flash do Inertia, toast, ou dado vindo nas
  props) e ser consistente com o que a tela já faz em `SyncFaturamentoBtn` e no envio de relatório
  por email (~linha 1029) — ler esses dois antes de decidir.

### 2. Mesmo tratamento no botão de FECHAR competência

`axios.post(route('admin.financeiro.competencia.fechar'), { mes })` (~linha 260) tem o mesmo
formato. **Ler o código antes** — se terminar sem feedback, corrigir junto. Não presumir.

### 3. O carimbo precisa mudar

O cabeçalho mostra "Fechado em {data}". Depois de refazer, ele tem que refletir o novo horário.
Conferir que a prop `competencia_fechada_em` realmente muda — se não mudar, a pessoa continua sem
evidência de que algo aconteceu, mesmo com a mensagem.

### 4. Nada de confirmar duas vezes

O botão já fica `disabled` enquanto `enviando`. Conferir que continua assim e que não há janela para
o duplo envio que gerou as 3 linhas.

## Fora de escopo

- **Não** mexer no backend (`FechamentoController::refazerCompetencia`/`fecharCompetencia`) — ele
  está correto: 200 com `message`, 409/422 com `message`. O defeito é só de interface.
- **Não** apagar nem alterar as 3 linhas de `fechamento_reconsolidacoes` em produção — são registro
  fiel do que aconteceu.
- **Não** mexer em cálculo, faixas ou snapshots.

## Copy

pt-BR, **sem jargão** (regra do projeto — quem lê é o time Administrativo). Nada de "snapshot",
"reconsolidação", "competência refeita com exit 0".

## Testes

- Trava de contrato: o handler de sucesso do refazer fecha o diálogo e emite confirmação. O padrão
  de teste de UI da fase lê o JSX como texto — ver `Phase137CompetenciaUiTest.php` e
  `Phase137FinanceiroUiContratoTest.php`.
- Não regredir `Phase137CompetenciaUiTest`.

**Gate:** `--filter="Phase122|Phase136|Phase137"` está em **239 testes / 1214 asserções / 0 falhas**.

## Restrições

- **Árvore compartilhada:** nunca `git add -A` / `git add .` / `git commit -a` / `git stash`. Só os
  próprios paths, `git status --porcelain` conferido antes **sem** `--untracked-files=no`.
  Não são seus: `tests/Feature/CompanyPortfolioAccessTest.php`, `public/images/*`, `.docx` da raiz.
- `npm run build` ao final (mexe em JSX), com timeout generoso.
- PHP: `C:\xampp\php\php.exe`. Comentários e commits em pt-BR. Commits atômicos.
- ⛔ Não fazer deploy. Não mexer no `.env`.
