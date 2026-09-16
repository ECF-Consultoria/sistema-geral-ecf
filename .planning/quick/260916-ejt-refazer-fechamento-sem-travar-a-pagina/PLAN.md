---
quick_id: 260916-ejt
slug: refazer-fechamento-sem-travar-a-pagina
date: 2026-09-16
type: quick
status: pending
---

# Refazer o fechamento para de quebrar a página

## O defeito, medido em produção

`FechamentoController::refazerCompetencia()` (`app/Http/Controllers/FechamentoController.php:221`) roda
`Artisan::call('fechamento:consolidar-mes')` **dentro da requisição web**. A consolidação busca o
faturamento de ~200 empresas na Adman; o PHP do site tem `memory_limit = 512M` e estoura.

Evidência (nginx, VPS, 16/09/2026):

```
13:12:17 POST /administrativo/financeiro/competencia/refazer 422   (motivo curto — validação, ok)
13:14:49 POST /administrativo/financeiro/competencia/refazer 500
  PHP Fatal error: Allowed memory size of 536870912 bytes exhausted
  (tried to allocate 20480 bytes) in .../Http/Client/Response.php on line 105
```

**Consequência real:** o usuário clicou em "Refazer fechamento" de agosto, a tela mostrou a mensagem
de falha genérica, **nada foi gravado**, e ele passou a ver os números antigos achando que o cálculo
estava errado. As 200 linhas de agosto continuavam com `gerado_em = 2026-09-11 14:36`.
Na linha de comando não há limite de memória (`memory_limit = -1`) — foi assim que o orquestrador
refez agosto à mão.

⚠️ **O `set_time_limit(0)` da linha 238 não protege de nada aqui** — o que estoura é memória, não tempo.

---

## T1 — A consolidação sai da requisição e vai para a fila

Criar `app/Jobs/ConsolidarMesFechamentoJob.php`:

- recebe `mes` (YYYY-MM), `motivo`, `porUserId`
- no `handle()`, chama **o mesmo** `Artisan::call('fechamento:consolidar-mes', [...])` — ⛔ **não
  reimplementar a consolidação**, nem tocar em `ConsolidarMesFechamento`, `FechamentoRollupService`
  ou `FechamentoFaixaResolver::classificar()`
- `timeout` alto (ex.: 1800) e `tries = 1` — **refazer duas vezes sozinho é pior que falhar**: cada
  execução regrava a competência e escreve na trilha de auditoria
- grava andamento em cache seguindo o padrão que já existe em
  `app/Jobs/SyncCompanyAdgroupMlbsJob.php:97-190`: `status` (`running` / `ready` / `failed`),
  `started_at`, `completed_at`, `error`, TTL folgado; método estático
  `statusCacheKeyFor(string $mes)` para a chave, como no analogado
- `failed(\Throwable $e)` grava `status = failed` com a mensagem — hoje a falha some
- quando o comando devolve exit != 0, o job grava `failed` com a saída resumida (`Artisan::output()`
  truncada) e registra `Log::error` com o texto inteiro

⚠️ A fila é `database` e os workers (`ecf-worker:00/01`) já rodam em produção. Job comum, sem fila
especial.

---

## T2 — O controller responde na hora

Em `refazerCompetencia()`:

- mantém `abort_unless(isAdmin)`, a validação de `mes`/`motivo` (mínimo 10 caracteres) e as mensagens
- **troca** `Artisan::call(...)` por `ConsolidarMesFechamentoJob::dispatch(...)`
- **trava anti-duplo-disparo:** se o andamento daquele mês já estiver `running`, devolve **409** com
  mensagem pt-BR ("Este fechamento já está sendo refeito — aguarde terminar."). Dois disparos
  simultâneos regravariam a mesma competência em paralelo
- devolve **202** com `{ message, status: 'running' }` — a mensagem não pode mais dizer "refeito com
  sucesso", porque nada terminou ainda. Algo como *"Refazendo o fechamento de {mês}. Isso leva alguns
  minutos — a tela avisa quando terminar."*

Rota nova para consultar o andamento (mesma proteção de admin e mesmo grupo de rotas do refazer,
`routes/web.php:1429`):

```
GET /financeiro/competencia/refazer/status?mes=YYYY-MM  →  admin.financeiro.competencia.refazer.status
```

Devolve o que está no cache: `{ status, started_at, completed_at, error }`, e `status: 'idle'` quando
não há nada (⚠️ cache vazio **não** é erro — é o estado normal de quem nunca refez).

---

## T3 — A tela acompanha

Em `resources/js/Pages/Admin/Financeiro.jsx`, no `confirmar()` (~linha 355):

- no sucesso (202), **não** fechar declarando vitória: fecha o diálogo, limpa o motivo e entra em
  estado "refazendo", com a mensagem do backend
- consulta o andamento a cada ~5 s (`axios.get` da rota nova), até `ready` ou `failed`
- `ready` → mensagem de sucesso (o componente já tem `setConfirmacao`) e `router.reload()`, como hoje
- `failed` → mostra o erro e diz que **o registro anterior continua valendo**
- enquanto "refazendo": botão desabilitado e indicação visível de que está em andamento
- ⚠️ parar a consulta ao desmontar o componente (limpar o intervalo) — senão a tela segue batendo no
  servidor depois de trocar de página
- ⚠️ se a pessoa recarregar a página no meio, a tela deve **voltar a acompanhar** ao ver `running` na
  primeira consulta (uma consulta ao montar resolve) — sem isso ela some com o aviso e a pessoa clica
  de novo, que é o incidente 260903-la4 de novo

⚠️ **Copy sem jargão** (há teste travando termos): nada de "job", "fila", "cache", "competência",
"snapshot", "dispatch". Fale de "refazendo o fechamento de agosto", "leva alguns minutos".
⚠️ Escala do Tailwind: `px-4.5`, `gap-4.5`, `py-5.5` não existem.

---

## Testes (`tests/Feature/Quick260916/`)

| caso | espera |
|---|---|
| admin dispara | 202, job na fila (`Queue::fake`), nada consolidado na hora |
| não-admin | 403 |
| motivo curto / mês inválido | 422, como hoje |
| disparo com um já em andamento | 409, **um único** job na fila |
| rota de andamento sem nada | `idle` |
| rota de andamento durante | `running` |
| job conclui | cache vira `ready` |
| comando devolve exit != 0 | cache vira `failed` com mensagem, e o registro anterior segue valendo |
| `failed()` do job | grava `failed` |
| rota de andamento para não-admin | 403 |
| copy | sem os termos proibidos |

⚠️ Nos testes, **nunca** deixar o job rodar a consolidação de verdade: `Queue::fake()` no teste do
controller e, no teste do job, `Artisan::shouldReceive('call')` (ou equivalente) devolvendo 0 e 1.

---

## Travas

⛔ **Não tocar** em `ConsolidarMesFechamento`, `FechamentoRollupService`, `FechamentoSnapshotWriter`,
`FechamentoEmpresasDoMes` nem em `FechamentoFaixaResolver::classificar()`. Este quick muda **como** o
refazer é disparado, nunca **o que** ele calcula.

⛔ **Sem deploy, sem `.env`, sem VPS, sem plink/pscp, sem alterar dado de produção.**
⚠️ **A produção está refazendo agosto agora**, pela linha de comando, enquanto este quick é escrito.
Não rode nada contra o servidor.

⚠️ Árvore compartilhada com outra sessão. Nunca `git add -A` / `git add .` / `git commit -a` /
`git stash`. `git status --porcelain app/ tests/ resources/ routes/` antes de cada commit. **Arquivo
novo precisa de `git add -- <caminho>` explícito antes do `git commit -- <caminhos>`.**
`tests/Feature/CompanyPortfolioAccessTest.php` não é seu.

⚠️ Não use `gsd-sdk query state.advance-plan`.

PHP: `C:\xampp\php\php.exe`. `npm run build` ao final (mexe em JSX). Comentários, copy e commits em
**pt-BR**.

**Gate (nenhum pode regredir, exit capturado antes de qualquer pipe):**
`--filter="Phase137|Phase140|Phase142|Phase143|Quick260915|Quick260916"` — rode ANTES de editar para
registrar a referência, e de novo ao final.

Ao final, grave `SUMMARY.md` nesta pasta; se a ferramenta recusar `.md`, devolva o conteúdo no
relatório final para o orquestrador gravar.
