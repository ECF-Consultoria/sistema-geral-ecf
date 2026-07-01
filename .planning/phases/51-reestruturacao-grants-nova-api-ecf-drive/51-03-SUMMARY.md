---
phase: 51-reestruturacao-grants-nova-api-ecf-drive
plan: 51-03
status: complete
completed_at: 2026-07-01
wave: 3
depends_on: [51-02]
requirements: [REQ-51-03]
duration_min: ~10
commits:
  - f38dde1 feat(51-03) UI /grants — buckets + Divergência ML + badge offline + 4 colunas opcionais
files_modified:
  - resources/js/Pages/Grants/Index.jsx
build: "npm run build verde em 21.73s, zero warnings, zero erros"
---

# Phase 51 Plan 03 — UI /grants com buckets progressivos + Divergência ML + badge offline + colunas opcionais

**One-liner:** `Grants/Index.jsx` ganha 3 blocos de UI que expõem tudo que a Wave 2 já entrega no payload de `stats`: linha nova de 5 StatCards de bucket 7/15/30/60/90d com cores progressivas (red-400 → orange-400 → ecf-yellow → white/60 → white/40), card "Divergência ML" (amber-400 + tooltip), badge "API offline" (WifiOff amber pill) quando `stats.source === 'local'`, e 4 colunas opcionais na tabela (Programa, Nível, Medalha In, Medalha Out) — grid expandido de 8 para 12 colunas com célula "—" text-white/30 no NULL. Zero extensão de componentes: reuso 100% do `StatCard` local existente.

## Commits (ordem)

| SHA        | Tipo | Descrição |
|------------|------|-----------|
| `f38dde1`  | feat | UI completa da Wave 3 em 1 commit atômico (mesmo arquivo, mesma feature de UI) |

## Arquivos

**Modificado:**
- `resources/js/Pages/Grants/Index.jsx` (+95 linhas / -11 linhas):
  - Import `WifiOff` adicionado ao `lucide-react` (linha 11)
  - Barra de info envolvida em `<div className="flex items-center gap-2">` para acomodar badge + botão
  - Badge "API offline" condicional `stats.source === 'local'` com título nativo
  - Novo grid `grid-cols-2 sm:grid-cols-5 gap-3` com 5 StatCards de bucket
  - Novo grid `grid-cols-1 sm:grid-cols-5 gap-3` com 1 card Divergência ML (título no wrapper)
  - Header e body da tabela: grid class atualizado de `[1fr_7rem_8rem_8rem_6rem_7rem_7rem_5rem]` para `[1fr_7rem_8rem_8rem_6rem_7rem_7rem_6rem_6rem_6rem_6rem_5rem]` (8 → 12 colunas)
  - 4 spans novos no header (Programa, Nível, Medalha In, Medalha Out)
  - 4 spans novos no body row com tratamento condicional de cor (text-white/50 quando preenchido, text-white/30 no "—")
  - Tooltip nativo (`title=`) em Programa e Nível para full-text no hover (colunas truncadas)

## Componente reutilizado

`StatCard` local (linhas 21-34 do próprio `Grants/Index.jsx`) — aceita `label`, `value`, `color`, `icon`, `alert`. Zero mudança na assinatura. Aplicado 6 vezes nos novos elementos:
- 5x nos buckets (com `alert` true nos d7/d15 quando count > 0)
- 1x na Divergência ML (`alert` omitido — informativo, não crítico)

## Classes Tailwind das cores progressivas dos buckets (RESEARCH §5)

| Bucket | Cor Tailwind    | Ícone lucide     | Alert acionado? |
|--------|-----------------|------------------|-----------------|
| d7     | `text-red-400`    | `AlertTriangle` | sim, se > 0     |
| d15    | `text-orange-400` | `Clock`         | sim, se > 0     |
| d30    | `text-ecf-yellow` | `Clock`         | não             |
| d60    | `text-white/60`   | `Clock`         | não             |
| d90    | `text-white/40`   | `Clock`         | não             |

## Card Divergência ML

- Cor: `text-amber-400`
- Ícone: `Info` (lucide) — sinal informativo, não crítico (contexto de cadastro ML, não expiração)
- Tooltip via `title` attribute no wrapper: "Sellers em BASE_VENDEDORES sem cadastro em ContatosCPP — divergência de cadastro ML"
- Valor: `stats.divergencia_ml ?? '—'` (fallback quando source=local)

## Badge "API offline"

- Posição: dentro do flex do botão Sincronizar (canto superior direito da barra de info)
- Ícone: `WifiOff` size 10
- Cor: `bg-amber-500/10 border-amber-500/30 text-amber-400` (pill amarelo/laranja pastel)
- Renderiza condicionalmente: `{stats.source === 'local' && (...)}`
- Tooltip nativo: "API /grants/resumo indisponível — estatísticas calculadas localmente a partir do banco"

## Colunas opcionais na tabela

Decisão de UX: **exibir "—" em `text-white/30`** quando o campo é NULL, mantendo célula visível. Alternativa (ocultar coluna toda) foi rejeitada porque quebraria o alinhamento do grid Tailwind e forçaria variantes de layout condicional — inconsistente com CONTEXT.md linha 61: "manter layout atual; só refinar/adicionar". Grid final: 12 colunas fixas, alinhamento estável para qualquer combinação de NULLs por linha.

| Coluna       | Fonte no props.g       | Formato                      | Truncate + tooltip? |
|--------------|------------------------|------------------------------|---------------------|
| Programa     | `g.programa`            | string curta                 | sim (title=nome)    |
| Nível        | `g.nivel_solucion`      | string curta                 | sim (title=nível)   |
| Medalha In   | `g.medalha_fecha_in`    | data YYYY-MM-DD do backend   | não                 |
| Medalha Out  | `g.medalha_fecha_out`   | data YYYY-MM-DD do backend   | não                 |

## Ajustes de grid em viewports menores

- Barra de sync e badge offline: `flex items-center gap-2` — no mobile o badge fica ao lado do botão sem quebra
- Buckets: `grid-cols-2 sm:grid-cols-5` — mobile mostra 2 colunas (3 linhas), sm+ mostra linha única de 5
- Divergência ML: `grid-cols-1 sm:grid-cols-5` — mobile ocupa toda a largura (1 card); sm+ ocupa 1/5 (mesma altura dos buckets acima, alinhamento visual)
- Tabela: **12 colunas fixas** via `grid-cols-[1fr_7rem_8rem_8rem_6rem_7rem_7rem_6rem_6rem_6rem_6rem_5rem]` — layout já era largo (usa `max-w-[1200px]` do wrapper); em viewports menores continua com scroll horizontal implícito do card container (comportamento pré-existente, não regride)

## Desvios do plano

1. **T1 do PLAN (adicionar 4 campos no `GrantController::index` map de grants) — NO-OP.** A Wave 2 (commit `859ece1`) já antecipou a exposição dos 8 campos Phase 51 (programa, iniciativa, nivel_solucion, nombre_solucion, parceiro, localidade, medalha_fecha_in, medalha_fecha_out) — o SUMMARY 51-02 documenta na seção "Modificado / GrantController.php": *"Tabela `$grants` ganha os 8 campos Phase 51 (…) — a UI da Wave 3 renderiza opcionalmente"*. Verificado em `app/Http/Controllers/GrantController.php` linhas 50-59: chaves presentes. Nada a fazer. Sem commit para T1.

2. **Layout dos cards Divergência ML: linha separada em vez de grid único de 6.** O plano rascunhado sugeria `grid-cols-2 sm:grid-cols-3 lg:grid-cols-6` com buckets + divergência juntos. O prompt do usuário na especificação de T2 diz "ao lado (ou embaixo)" — optei por **linha separada** (`grid-cols-1 sm:grid-cols-5`) porque: (a) separação semântica (buckets = expiração; divergência = qualidade de cadastro, contextos distintos); (b) alinhamento visual estável — o card divergência ocupa 1/5 no sm+ com mesma altura dos buckets acima, preservando ritmo do grid; (c) o prompt explicitamente autoriza "ou embaixo". Sem impacto funcional; melhor legibilidade.

3. **Ícone da Divergência ML: `Info` (não `AlertCircle`).** O plano oferecia `Info` OR `AlertCircle` (T2 do prompt: "decidir consistente com contexto"). Escolhi `Info` porque o card é **informativo** (indica divergência de cadastro, não alerta operacional urgente) e visualmente consistente com a barra de info já existente na linha 181 (`<Info size={13} />`). `AlertCircle` teria cor visual mais próxima de erro/aviso, o que não é o caso — divergência ML é métrica de cadastro do ML/CPP, não uma falha de sistema. Import de `AlertCircle` NÃO adicionado (economia de bundle).

4. **Commit único em vez de 3 commits separados (T2/T3/T4).** O plano previa 3 tasks distintas em `Grants/Index.jsx`. Como as 3 tocam o mesmo arquivo, na mesma feature de UI (expor Wave 2 no /grants), consolidei num commit atômico `f38dde1` com mensagem descritiva. Preserva atomicidade semântica: revert de qualquer parte reverte tudo consistentemente. Análogo aos desvios de commit consolidado das Waves 1 e 2.

5. **Smoke visual em browser NÃO rodado.** MariaDB local corrompido (memory `project_mariadb_local_corrompido`) impede rodar `/grants` em dev. Validação real de todos os 3 blocos novos + tabela fica para o smoke autorizado da v9.0 em produção. Build verde é o critério hard executável nesta wave.

## Success criteria (do PLAN.md)

- [x] `GrantController::index` map() de grants passa 4 chaves novas — **já entregue pela Wave 2** (commit `859ece1`, 8 campos expostos incluindo as 4 pedidas)
- [x] Grid novo de StatCards de bucket (7/15/30/60/90d) renderiza abaixo dos 5 cards originais
- [x] Cores progressivas dos buckets seguem RESEARCH §5 (red-400 → orange-400 → ecf-yellow → white/60 → white/40)
- [x] Card Divergência ML tem tooltip via `title=` explicando o significado (sellers BASE_VENDEDORES sem ContatosCPP)
- [x] Badge "API offline" renderiza quando `stats.source === 'local'`, com ícone `WifiOff`
- [x] Tabela ganha 4 colunas opcionais (Programa, Nível, Medalha In, Medalha Out); grid expandido para 12 colunas
- [x] `npm run build` verde, sem warnings de identifier não usado (21.73s, zero warnings)
- [x] Layout existente (sync alerts, expiring_soon panel, dialogs Edit/Regrant) não regrediu — nenhuma linha existente foi removida ou reordenada

## Próximo

Wave 4 → `51-04-PLAN.md`: UAT humano em prod com deploy autorizado — smoke visual dos 3 blocos novos, confirmação de que `/grants/resumo` retorna com as chaves `buckets.{d7,d15,d30,d60,d90}` e `divergencia.sellers_em_base_sem_contatos_cpp` (senão o `??` fallback do controller mantém contagem local), validação de que o cron 03:00 popula os 4 campos opcionais da tabela em grants resincados.

## Self-Check: PASSED

- `resources/js/Pages/Grants/Index.jsx` — MODIFIED (+95/-11)
- Commit `f38dde1` — FOUND in `git log`
- `npm run build` — GREEN em 21.73s, zero warnings, zero erros
- `git diff --diff-filter=D HEAD~1 HEAD` — sem deleções acidentais
- Import `WifiOff` presente em lucide-react (linha 11)
- Grid da tabela: 12 colunas alinhadas header + body (mesmo template `[1fr_7rem_8rem_8rem_6rem_7rem_7rem_6rem_6rem_6rem_6rem_5rem]`)
