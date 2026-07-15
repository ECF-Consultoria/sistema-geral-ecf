# Phase 83: Planilha — correções de publicação em massa, avisos do ML visíveis e ganhos rápidos - Research

**Pesquisado em:** 2026-07-15
**Domínio:** Correções pontuais de estado assíncrono (polling), UX de erro/aviso e coerção de entrada numa grade React já existente em canvas (glide-data-grid, herdada da Phase 82)
**Confiança:** ALTA — quase todos os achados vêm de leitura direta do código-fonte do próprio projeto (não de documentação de terceiros) e de um precedente JÁ IMPLEMENTADO e funcionando no wizard (`AnunciarML.jsx`) para o MESMO problema.

## Project Constraints (from CLAUDE.md)

- Stack travada: Laravel 12 + Inertia.js + React — nenhuma mudança de stack.
- Design: tokens `ecf-*`, dark theme, `cn()` — mantidos; nenhuma nova dependência de UI necessária (todos os achados usam padrões já presentes no arquivo ou no wizard).
- Comentários em pt-BR.
- `npm run build` é gate obrigatório após qualquer mudança de frontend.
- Deploy só com autorização explícita.
- Convenção "reusar padrão existente antes de inventar um novo" — respeitada à risca nesta pesquisa: o achado mais importante (§1) É a descoberta de que o padrão de polling já existe no wizard e só não foi replicado na grade em massa.

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| FIX-83-1 | Loading eterno da publicação em massa | §1 (causa já confirmada no brief) + §Pitfall 1 — fix é adicionar `finally` E entender que "travar o botão" só deveria durar o dispatch, não o lote inteiro |
| FIX-83-2 | Resultado da publicação não chega na tela | §1 — reusar o EXATO padrão de polling já implementado em `AnunciarML.jsx:1366-1373`, adaptado para popular `abas[].linhas[]` via merge por `id` (que hoje não existe) |
| FIX-83-3 | Erro da API do ML ilegível | §2 — precedente `9e5a640` lido linha a linha; payload (`erro_resumo`/`erro_completo`) JÁ vem do backend em `massa()`; falta só UI + o merge do §1 |
| FIX-83-4 | Avisos do ML invisíveis na PublishBar | §3 — shape de `l.valida.erros` confirmado no controller (`{code, campo, mensagem}`); painel DOM expansível é a única opção viável (canvas não pendura popover) |
| FIX-83-5 | Preço não aceita vírgula | §4 — ponto único de coerção identificado: dentro de `onCellsEdited` da grade (não em `montarPayloadLinha`, que já é tarde demais — o autosave já teria persistido `price: null`) |
| FIX-83-6 | Delete + remover categoria | §5 — `onDelete` da lib JÁ teria efeito NENHUM hoje em 8 das 10 colunas base (verificado no código-fonte, não é hipótese); remover categoria não precisa de backend novo |

</phase_requirements>

## Summary

Esta fase é, no fundo, sobre **um estado que dois lugares divergentes tentam representar ao mesmo tempo**: o backend (via `status`/`validation_errors` no `ml_anuncio_rascunho`) e o frontend (via `abas[].linhas[]`, que hoje só lê `rascunhos` UMA VEZ, no mount). O achado mais importante desta pesquisa é que **o problema de FIX-83-2 já foi resolvido neste mesmo projeto, no wizard** (`AnunciarML.jsx:1366-1373`, commit anterior a `9e5a640`): um `useEffect` que faz `setInterval(() => router.reload({only:['rascunhos']}), 3000)` enquanto `rascunhos.some(r => r.status === 'publicando')`, com `clearInterval` no cleanup. Esse `useEffect` para sozinho porque, no wizard, `rascunhos` é um prop consumido DIRETAMENTE — a cada `router.reload` parcial, o React re-renderiza com o array atualizado e o efeito reavalia a condição.

Na grade em massa isso NÃO funciona hoje porque `AnunciarMassa.jsx` só lê a prop `rascunhos` dentro de um `useEffect(() => {...}, [])` de dependência vazia (linha 70-103) — ela constrói `abas[].linhas[]` UMA VEZ, no mount, e nunca mais sincroniza com atualizações da prop. Ou seja: mesmo implementando o polling idêntico ao do wizard, **nada mudaria na tela**, porque o polling atualizaria a prop `rascunhos`, mas `abas` (o que a grade de fato renderiza) ficaria surda a isso. Este é o achado que mais muda o plano: FIX-83-1 e FIX-83-2 não são dois bugs paralelos — são a mesma lacuna arquitetural (falta de um "merge" prop→estado) manifestada de duas formas.

O segundo achado importante, verificado lendo o código-fonte minificado da própria lib instalada (`node_modules/@glideapps/glide-data-grid/dist/esm/data-editor/data-editor.js`): a tecla Delete **já dispara** um mecanismo nativo mesmo sem `onDelete` no `<DataEditor>` — mas esse mecanismo delega, célula a célula, para o `onDelete` do CUSTOM CELL RENDERER (`cell-types.d.ts:67`), e nem `origemCellRenderer` (usado em 8 das 10 colunas base: title, sku, price, estoque, pesoG, alturaCm, larguraCm, comprimentoCm) nem `DropdownCell` (usado em `tier` e nos atributos `list`) implementam esse `onDelete`. Resultado: **hoje, apertar Delete na grade da Phase 82 não limpa NADA na maioria das colunas** — só funciona em `gtin`, `descricao` e atributos de texto livre (que usam `GridCellKind.Text` puro, cujo `onDelete` embutido da lib já limpa para `""`).

Terceiro achado: o payload que o backend já devolve em `massa()` (`erro_resumo`/`erro_completo` por rascunho, linhas 173-175 do controller) e o precedente do wizard (commit `9e5a640`, um `<details>/<summary>` DOM simples) já resolvem 90% de FIX-83-3 — não falta dado, falta canalizar esse dado para dentro de `abas[].linhas[]` (mesmo merge do achado #1) e desenhar o `<details>` (que é DOM puro, funciona sem tocar o canvas) num painel abaixo da grade.

**Recomendação primária:** resolver FIX-83-1/2/3 com UMA mudança arquitetural central — um `useEffect([rascunhos])` em `AnunciarMassa.jsx` que faz merge por `id` das linhas atualizadas dentro de `abas[].linhas[]` (preservando os campos que só existem no cliente: `uid`, `salvando`, `attrs` não confirmados etc.), combinado com o padrão de polling já existente no wizard. Nenhuma rota nova é necessária — `router.reload({only:['rascunhos']})` já invoca `massa()`, que já devolve tudo que falta.

## Architectural Responsibility Map

| Capacidade | Tier primário | Tier secundário | Motivo |
|------------|---------------|-----------------|--------|
| Merge de status/erro pós-polling em `abas[].linhas[]` | Browser / Client (`AnunciarMassa.jsx`) | — | Puramente local — o dado já chega pronto do backend, falta só reconciliar com o estado existente |
| Polling condicional (start/stop) | Browser / Client (`useEffect` + `setInterval`) | API / Backend (`massa()` via `router.reload`) | Backend não muda; é reaproveitamento do mesmo endpoint de render da página |
| Exibição de erro completo expansível | Browser / Client (DOM — fora do canvas) | API / Backend (`erro_completo` já computado) | Canvas não hospeda `<details>`; o painel fica FORA do `<DataEditor>`, como um bloco DOM comum |
| Exibição de avisos do ML por linha | Browser / Client (DOM — painel abaixo da grade) | — | `l.valida.erros` já existe em memória (gravado por `validarTudo`); só falta UI |
| Coerção de preço com vírgula | Browser / Client (`onCellsEdited` da grade) | — | Ponto único de escrita já estabelecido pela Phase 82; corrigir aqui evita persistir `price: null` no autosave |
| Delete de células selecionadas | Browser / Client (`onDelete` no `DataEditor` OU nos custom cell renderers) | — | 100% cliente; a lib já tem o mecanismo, falta plugá-lo nos 2 custom renderers usados nesta grade |
| Remover categoria (aba) | Browser / Client (loop de `onRemoverLinha` já existente) | API / Backend (`rascunho.destroy`, reaproveitado N vezes) | Nenhum endpoint novo — a função de remover 1 linha já existe e já double-checka empresa no backend |

## Standard Stack

Nenhuma dependência nova é necessária nesta fase — tudo é reaproveitamento de:
- `@glideapps/glide-data-grid@6.0.3` (já instalado, Phase 82) — props `onDelete` (nível DataEditor) e `onDelete` (nível custom cell renderer), ambos já existentes na API instalada, apenas não usados ainda.
- `@inertiajs/react` `router.reload({ only: [...] })` — já em uso em 5 páginas do projeto (ver §Common Pitfalls / precedentes).
- `lucide-react` — ícone `Copy` já usado no precedente do wizard (`9e5a640`), reaproveitável aqui.

**Nenhum pacote a instalar. Nenhuma migration. Nenhuma rota nova (ver §5 para a única alternativa que exigiria endpoint — descartada em favor do reaproveitamento).**

## Package Legitimacy Audit

Não aplicável — esta fase não introduz nenhum pacote novo (frontend puro sobre dependências já auditadas na Phase 82).

## Architecture Patterns

### System Architecture Diagram (fluxo de status pós-publicação — o que falta)

```
┌──────────────────────────────────────────────────────────────────────────┐
│ publicarLote() dispara N jobs (delay 3s×posição) via publicarLote (BULK-02)│
└──────────────────────────────┬───────────────────────────────────────────┘
                                │ (assíncrono, servidor)
                                ▼
        PublicarAnuncioMlJob → MlPublicacaoService::publicar()
                                │ grava status=publicado|erro + validation_errors
                                ▼
┌──────────────────────────────────────────────────────────────────────────┐
│ HOJE: 1 único router.reload({only:['rascunhos']}) após 1500ms             │
│       → atualiza a PROP rascunhos, mas AnunciarMassa.jsx NUNCA relê essa  │
│       prop depois do mount (useEffect com deps [] na linha 70) — o merge │
│       para abas[].linhas[] SÓ acontece uma vez, no carregamento da página │
└──────────────────────────────┬───────────────────────────────────────────┘
                                │ ✗ gap — nada volta pra tela
                                ▼
                     grade fica "publicando" pra sempre (FIX-83-1/2)

┌──────────────────────────────────────────────────────────────────────────┐
│ PROPOSTO: useEffect([rascunhos]) faz merge por id em abas[].linhas[]     │
│   + polling condicional idêntico ao já existente em AnunciarML.jsx       │
│   (setInterval 3s enquanto algum id despachado ainda está 'publicando')  │
└──────────────────────────────┬───────────────────────────────────────────┘
                                ▼
        cada linha da grade reflete status real (✓ publicado / ! erro / ↑ publicando)
        + painel DOM abaixo da grade mostra erro_completo expansível (FIX-83-3)
```

### Pattern 1: Merge de prop atualizada em estado derivado por `id` (NOVO — não existe hoje)

**O quê:** um `useEffect` com dependência em `[rascunhos]` (a prop, atualizada a cada `router.reload({only:['rascunhos']})`) que percorre `abas` e, para cada `linha` com `l.id` não-nulo, encontra o rascunho correspondente na prop atualizada e faz `patch` SÓ dos campos que vêm do servidor (`status`, `erro_resumo`, `erro_completo`) — nunca dos campos de edição local (`title`, `price`, `attrs`, etc.), para não sobrescrever o que o usuário está digitando.

**Por que é necessário:** [VERIFICADO no código-fonte de `AnunciarMassa.jsx:70-103`] o único lugar que lê a prop `rascunhos` tem `// eslint-disable-next-line react-hooks/exhaustive-deps` seguido de `}, [])` — dependência vazia, deliberada (comentário: "Inicializa as abas agrupando os rascunhos por category_id"). Isso é CORRETO para o mount inicial (não se quer re-agrupar abas a cada re-render), mas significa que hoje NENHUM mecanismo sincroniza atualizações de status.

**Exemplo:**
```jsx
// NOVO — não existe hoje. Roda a cada atualização da prop rascunhos (via polling).
// Só atualiza status/erro; nunca toca em campos que o usuário pode estar editando.
useEffect(() => {
    if (!rascunhos?.length) return;
    const porId = new Map(rascunhos.map((r) => [r.id, r]));
    setAbas((prev) => prev.map((a) => ({
        ...a,
        linhas: a.linhas.map((l) => {
            if (!l.id) return l; // linha ainda não salva — nada a sincronizar
            const r = porId.get(l.id);
            if (!r) return l; // rascunho pode ter sido removido/movido de aba
            // Só sincroniza campos de SERVIDOR — nunca title/price/attrs (edição local)
            if (l.status === r.status && l.erro_completo === r.erro_completo) return l; // sem mudança, evita re-render
            return {
                ...l,
                status: r.status,
                erro_resumo: r.erro_resumo,
                erro_completo: r.erro_completo,
                publicando: r.status === MlAnuncioRascunho_STATUS_PUBLICANDO, // string 'publicando' — ver nota abaixo
            };
        }),
    })));
}, [rascunhos]);
```

**Nota:** `MlAnuncioRascunho::STATUS_PUBLICANDO` é a string `'publicando'` (`app/Models/MlAnuncioRascunho.php:22`) — pode ser comparado como string literal no frontend (não há enum JS espelhado hoje neste módulo; seguir a convenção do projeto de "sem enum compartilhado, sincronizado manualmente" já documentada no CLAUDE.md).

### Pattern 2: Polling condicional — reaproveitamento EXATO do precedente do wizard

**O quê:** [VERIFICADO — código já existe e funciona em produção] `AnunciarML.jsx:1366-1373`:
```jsx
// Fonte: resources/js/Pages/Mlb/AnunciarML.jsx:1366-1373 (código JÁ EXISTENTE, não hipotético)
useEffect(() => {
    const temPublicando = rascunhos.some(r => r.status === 'publicando');
    if (!temPublicando) return;
    const id = setInterval(() => router.reload({ only: ['rascunhos'] }), 3000);
    return () => clearInterval(id);
}, [rascunhos]);
```
**Critério de parada:** o próprio array `rascunhos` — quando nenhum item tem `status === 'publicando'`, o efeito não recria o `setInterval` (o `return` antecipado). É o mecanismo mais simples possível: **não precisa de contador de tentativas nem de timeout explícito**, porque o backend GARANTE uma transição final de estado (o job sempre termina em `publicado` ou `erro` — `PublicarAnuncioMlJob::failed()` já grava `STATUS_ERRO` em qualquer falha definitiva, incluindo timeout/exceção do job). Isto responde diretamente a pergunta do brief sobre "critério de parada".

**Adaptação necessária para `AnunciarMassa.jsx`:** o wizard consome `rascunhos` DIRETAMENTE no JSX; a grade em massa precisa do merge do Pattern 1 ANTES que esse polling tenha efeito visível. Copiar o `useEffect` de polling é necessário mas NÃO suficiente sozinho — sem o Pattern 1, o polling atualizaria a prop e nada mudaria na tela (esse é o próprio bug de hoje, comprovado).

**Timeout de segurança (recomendado, não copiado do wizard):** o wizard não tem timeout de segurança no polling (roda enquanto houver `publicando`, indefinidamente). Para o lote (que pode ter até 50 rascunhos × 3s = 150s de fan-out + tempo de processamento de cada job), recomenda-se um teto de segurança semelhante ao usado em `Grants/Index.jsx`/`Coleta.jsx` (`deadline = Date.now() + N minutos`) para parar de pollar e mostrar aviso "verifique manualmente" se algo ficar preso além do esperado — **isto é uma melhoria sobre o precedente do wizard, não uma cópia dele**, recomendada porque o lote pode ter volume bem maior que 1 rascunho.

### Pattern 3: Erro completo expansível — precedente `9e5a640` adaptado (FIX-83-3)

**O quê:** [VERIFICADO — `git show 9e5a640`] o wizard já resolveu exatamente este problema com um `<details>/<summary>` DOM simples, sem nenhuma lib nova:
```jsx
// Fonte: resources/js/Pages/Mlb/AnunciarML.jsx (commit 9e5a640, adaptado)
{r.status === 'erro' && r.erro_resumo && (
    <details className="mt-1 pl-6">
        <summary className="cursor-pointer text-[11px] text-red-400 marker:text-red-400/60">
            {r.erro_resumo}
        </summary>
        <div className="mt-1">
            <pre className="max-h-48 overflow-auto whitespace-pre-wrap break-words rounded-md border border-red-500/20 bg-red-500/[0.05] p-2 text-[10px] leading-relaxed text-red-300/80">
                {r.erro_completo || r.erro_resumo}
            </pre>
            <button
                type="button"
                onClick={() => {
                    const txt = r.erro_completo || r.erro_resumo || '';
                    if (navigator.clipboard?.writeText) navigator.clipboard.writeText(txt).catch(() => fallbackCopiar(txt));
                    else fallbackCopiar(txt);
                }}
                className="mt-1 flex items-center gap-1 rounded-md border border-white/10 bg-white/[0.03] px-2 py-0.5 text-[10px] text-white/60 hover:text-white/90"
            >
                <Copy size={10} /> Copiar erro
            </button>
        </div>
    </details>
)}
```
**Onde encaixar na grade em massa:** o canvas NÃO pode hospedar `<details>` (é DOM, não desenho). O lugar natural é um **painel DOM abaixo do `<DataEditor>`**, dentro de `GradeAnuncioGlide.jsx` (ou em `AnunciarMassa.jsx`, decisão do planner) — uma lista das linhas com `status === 'erro'`, cada uma com o mesmo padrão `<details>`. `erro_resumo`/`erro_completo` já vêm prontos do backend (linhas 174-175 do controller, `massa()`) — depois do Pattern 1 (merge), já estarão em `l.erro_resumo`/`l.erro_completo`.

**`fallbackCopiar`:** já existe como função privada em `AnunciarML.jsx` (linha 54) — não exportada. Se for reaproveitada em `AnunciarMassa.jsx`, precisa ser duplicada ou movida para `gradeMassaUtils.js` (recomendado: mover para `gradeMassaUtils.js`, já que este módulo existe exatamente para compartilhar helpers puros entre página e grade — ver comentário de cabeçalho do arquivo).

### Pattern 4: Coerção de vírgula — ponto único em `onCellsEdited` (FIX-83-5)

**O quê:** [VERIFICADO — `GradeAnuncioGlide.jsx:401-462`, comentário da própria função: "ÚNICO ponto de escrita da grade"] a grade já centraliza TODA escrita (digitação, fill handle, paste) em `onCellsEdited`. O `montarPayloadLinha` (`AnunciarMassa.jsx:709-743`, `price: l.price ? Number(l.price) : null`) já é tarde demais: `salvarLinha` chama `montarPayloadLinha` e persiste via `axios.post/put` a cada autosave (debounce 600ms) — se `l.price` for `"129,99"`, `Number("129,99")` é `NaN`, `JSON.stringify(NaN)` vira `null`, e **o autosave já grava `price: null` no banco antes mesmo do usuário tentar publicar**. Corrigir só em `montarPayloadLinha` deixaria o autosave continuar corrompendo dados no meio do caminho.

**Onde exatamente:** dentro de `onCellsEdited`, no branch final que trata células de origem (`origem-cell`, que é o que a coluna `price` usa — `COLS_COM_ORIGEM` inclui `'price'`), antes de chamar `onEditarCelula`:
```jsx
// Dentro de onCellsEdited, GradeAnuncioGlide.jsx — trecho existente (linhas ~434-437)
const valor = (cel?.kind === GridCellKind.Custom && cel.data?.kind === 'origem-cell')
    ? String(cel.data.texto ?? '')
    : String(cel?.data ?? '');

// ADICIONAR: normaliza vírgula decimal SÓ na coluna price, antes do onEditarCelula
const valorNormalizado = coluna.id === 'price'
    ? valor.replace(',', '.')
    : valor;
```
E então usar `valorNormalizado` no lugar de `valor` na chamada final `onEditarCelula(linha.uid, coluna.id, valorNormalizado, ...)`. Isso garante que `l.price` no estado do React SEMPRE fica no formato `"129.99"` — nenhuma outra função (`errosLocaisLinha`, `montarPayloadLinha`, `linhaDeRascunho`) precisa mudar, porque todas já esperam esse formato.

**Cobre digitação E paste do Excel:** como `coercePasteValue` roda ANTES de `onCellsEdited` (célula a célula, durante o parse do paste) mas apenas normaliza trim/limites — a normalização de vírgula em `onCellsEdited` cobre AMBOS os fluxos (paste cai em `onCellsEdited` como um lote de edições; digitação cai em `onCellsEdited` como uma edição única) sem duplicar lógica.

**Validação server-side:** [VERIFICADO — `MlbAnuncioController::atualizarRascunho`, linhas 283-305] não há regra de validação para `payload.price` — só `payload.title` é validado. Ou seja, o backend aceita qualquer shape de `price` sem reclamar (incluindo `null`), então a correção 100% no frontend é suficiente e não deixa brecha: sem correção no ponto certo, o dado já estaria corrompido antes de chegar ao backend.

### Pattern 5: `onDelete` — nível certo para implementar (FIX-83-6a)

**O quê:** [VERIFICADO lendo `node_modules/@glideapps/glide-data-grid/dist/esm/data-editor/data-editor.js`, função `deleteRange` linhas ~1766-1797] o mecanismo de Delete da lib funciona assim:
1. Tecla Delete pressionada → lib chama `onDelete(gridSelection)` (prop do `<DataEditor>`, HOJE NÃO IMPLEMENTADA nesta grade — ausente do JSX de `GradeAnuncioGlide.jsx`). Se não fornecido, o comportamento default retorna `true` (deleta tudo na seleção).
2. Para CADA célula dentro da seleção resultante, a lib chama `getCellContent` e, SE a célula for `GridCellKind.Custom`, chama `toDelete.onDelete(cellValue)` — o `onDelete` DEFINIDO NO CUSTOM CELL RENDERER (`cell-types.d.ts:67`, `readonly onDelete?: (cell: T) => T | undefined`), não no `<DataEditor>`.
3. **`origemCellRenderer` (`GradeAnuncioGlide.jsx:112-151`) NÃO define `onDelete`.** `DropdownCell` (`@glideapps/glide-data-grid-cells`, verificado em `dropdown-cell.js`) também NÃO define `onDelete`.
4. Resultado: `newVal` fica `undefined` para essas células → a lib NÃO as inclui no `editList` → `onCellsEdited` nunca é chamado para elas → **nada acontece visualmente**.
5. Para `GridCellKind.Text` puro (usado só em `gtin`, `descricao`, atributos texto livre da ficha técnica), a lib TEM um `onDelete` embutido (`text-cell.js:45-48`, `data: ""`) — Delete JÁ funciona nessas colunas hoje, sem nenhuma mudança.

**Duas formas de corrigir (escolher UMA, não as duas):**

**Opção A — recomendada:** adicionar `onDelete` no `origemCellRenderer` e no wrapper do `DropdownCell`, retornando uma célula com dado vazio (segue o padrão nativo da lib, delegando à mesma engrenagem de `deleteRange`):
```jsx
// origemCellRenderer — adicionar:
onDelete: (cell) => ({ ...cell, data: { ...cell.data, texto: '' } }),

// DropdownCell não é um objeto que se possa mutar diretamente (é import de pacote externo) —
// envolver antes de colocar em RENDERERS:
const dropdownComDelete = { ...DropdownCell, onDelete: (cell) => ({ ...cell, data: { ...cell.data, value: undefined } }) };
const RENDERERS = [dropdownComDelete, origemCellRenderer];
```
**Opção B — mais controle, mais código:** implementar `onDelete` no nível do `<DataEditor>`, interceptando a seleção inteira e chamando `onCellsEdited` manualmente com os valores vazios apropriados por coluna (reaproveitando a mesma lógica de "o que é vazio para esta coluna" que já existe em `onCellsEdited`), retornando `false` para impedir que a lib tente seu próprio `deleteRange` em paralelo (evitaria dupla-escrita).

**Recomendação:** Opção A — é o padrão que a lib foi desenhada para usar (o mecanismo `deleteRange` já existe e funciona; só falta plugar os 2 renderers customizados desta grade nele), e é ortogonal ao restante da arquitetura (`onCellsEdited` continua sendo chamado do mesmo jeito de sempre pelo `mangledOnCellsEdited` interno — nenhum código de `AnunciarMassa.jsx` muda).

**Autosave após Delete:** como o `onDelete` da Opção A resulta em um `editList` normal passado para `onCellsEdited` (o handler já existente da grade), o fluxo de `onEditarCelula` → `agendarSalvar` dispara automaticamente — **nenhuma mudança adicional necessária no autosave**.

### Pattern 6: Remover categoria — reaproveitar `removerLinha`, sem endpoint novo (FIX-83-6b)

**O quê:** não existe endpoint de bulk-destroy (`rascunho.destroy` é só singular). Duas opções, ambas 100% frontend:

**Opção A — Apagar os rascunhos da aba (destrutivo):** iterar `aba.linhas` e chamar `onRemoverLinha(uid)` (já existe em `AnunciarMassa.jsx:275-287`, já faz `axios.delete(route('mlb.anuncios.rascunho.destroy', ...))` com double-check de empresa no backend) para cada linha com `l.id`. Requer `window.confirm` (mesmo padrão já usado em `removerEmLote` de `GradeAnuncioGlide.jsx:489-498`) — perda de dado é irreversível (o `excluirRascunho` do controller é um `delete()` físico, não soft-delete).

**Opção B — Mover para "Sem categoria" (não-destrutivo):** para cada linha com `l.id`, chamar o autosave (`salvarLinha`/`atualizarRascunho`) com `category_id: null`. A UI então re-agrupa a linha na aba `SEM_CATEGORIA` na próxima leitura de `abas`. Menos arriscado, mas exige uma pequena adaptação: hoje `salvarLinha` deriva `category_id` de `a.category_id` (a aba de origem) — mover de aba precisaria ou (i) uma chamada direta a `atualizarRascunho` com `category_id: null` fora do fluxo de `salvarLinha`, ou (ii) mover a linha para o array `linhas` da aba `SEM_CATEGORIA` no estado local E então deixar o autosave natural (`agendarSalvar`) persistir, já que `salvarLinha` lê `a.category_id` da aba ATUAL da linha no momento do save.

**Recomendação:** oferecer a Opção A como comportamento do botão "Remover categoria", com o `window.confirm` deixando claro que os rascunhos serão apagados (não os anúncios já publicados no ML — mesma ressalva já documentada em `excluirRascunho`, linha 331: "apagar o rascunho NÃO remove o anúncio no ML"). A Opção B é mais segura para dados mas adiciona complexidade de reindexação de abas que foge do escopo de "ganho rápido" pedido nesta fase — **decisão do usuário a confirmar no /gsd-discuss-phase**, listada em §Open Questions.

**Reindexação de `abaAtiva` após remover a aba:** ao remover a categoria ativa, `abas` perde 1 elemento — replicar o padrão já usado em `adicionarCategoria` (`setAbaAtiva(abas.length)` ao adicionar) e a garantia já existente no `useEffect` inicial ("Sempre garante ao menos uma aba") para nunca deixar `abaAtiva` apontando para um índice inexistente ou a lista de abas vazia.

## Don't Hand-Roll

| Problema | Não construa | Use em vez disso | Por quê |
|----------|---------------|-------------------|---------|
| Saber quando a fila de publicação terminou | WebSocket, Server-Sent Events, endpoint de status dedicado | `router.reload({only:['rascunhos']})` em `setInterval`, condicionado a `status === 'publicando'` — EXATAMENTE como já feito em `AnunciarML.jsx` | Projeto usa `queue=database`, `broadcast=log` (sem Redis/Pusher) — introduzir push exigiria infraestrutura nova só para esta fase; o polling já é o padrão estabelecido no módulo MLB inteiro (5+ precedentes: `NotificationBell`, `EmpresaListagem`, `Grants/Index`, `Coleta`, `Performance/Index`, `ImplementacaoPublicador`, `AnunciarML`) |
| Exibir erro completo da API do ML | Modal customizado, tooltip flutuante posicionado manualmente | `<details>/<summary>` (precedente `9e5a640`) | Já resolvido, já testado em produção no wizard; zero dependência nova |
| Deletar bulk de rascunhos ao remover categoria | Endpoint novo `DELETE /mlb/anuncios/categoria/{id}` | Loop de `onRemoverLinha(uid)` já existente, com `window.confirm` | O endpoint singular já faz double-check de empresa; criar um endpoint bulk duplicaria essa lógica de autorização sem necessidade |
| Coerção de vírgula decimal | Máscara de input (`react-number-format` ou similar) | `String.replace(',', '.')` dentro de `onCellsEdited` (já é o ponto único de escrita) | Trivial — não justifica dependência nova para um `replace` de 1 linha |

**Key insight:** todos os 6 requisitos desta fase se resolvem **sem nenhuma dependência nova e sem nenhuma rota de backend nova** — o padrão de resolução em 5 dos 6 casos é "código que já existe em outro arquivo do mesmo módulo (wizard) e não foi replicado na grade em massa quando ela foi criada na Phase 82". O trabalho da fase é majoritariamente de **sincronização e reaproveitamento**, não de construção de mecanismo novo.

## Common Pitfalls

### Pitfall 1: Corrigir só o `finally` sem corrigir o merge de estado (FIX-83-1 isolado de FIX-83-2)

**O que dá errado:** adicionar um `finally { setPublicandoLote(false); }` em `publicarLote` destrava o BOTÃO, mas as linhas continuam mostrando `publicando: true` para sempre (esse flag é setado manualmente via `setAbas` logo após o dispatch, e só seria limpo por uma releitura de `rascunhos` que, como visto no achado principal, não é consumida depois do mount).
**Por que acontece:** são dois estados diferentes (`publicandoLote` — trava o botão; `linha.publicando` — ícone `↑` por linha) atualizados por caminhos diferentes, e só o segundo depende do merge de prop que falta.
**Como evitar:** implementar o Pattern 1 (merge por id) ANTES ou JUNTO da correção do `finally`. Adicionar só o `finally` resolve a QUEIXA "trava pra sempre" (botão destrava), mas não resolve "não vejo o resultado" (FIX-83-2) — os dois requisitos do brief precisam do merge para ficarem 100% resolvidos.
**Sinais de alerta:** depois do fix, o botão "Publicar" volta a ficar clicável, mas as linhas publicadas continuam com o glifo `↑` amarelo indefinidamente.

### Pitfall 2: `setPublicandoLote(false)` cedo demais destrava o botão mas o usuário perde a noção de "ainda processando"

**O que dá errado:** se `setPublicandoLote(false)` for chamado logo após o dispatch (como o comentário do código sugere: "trava imediatamente (anti-duplo-clique)"), o botão "Publicar N em lote" volta a ficar clicável enquanto os jobs ainda rodam em background (até `N×3s` depois). Isso é o comportamento CORRETO segundo o próprio comentário do código (a trava é só anti-duplo-clique do dispatch, não do processamento assíncrono) — mas se a UI não tiver NENHUM outro indicador de "ainda processando", o usuário pode clicar em Publicar de novo achando que nada aconteceu.
**Por que acontece:** o feedback de "processando" precisa vir do `resumoLote`/glifos por linha (que already existem: `↑` amarelo), não do botão. Sem o merge do Pattern 1, esse feedback nunca aparece — reforça por que os dois problemas (botão travado E falta de feedback por linha) precisam ser resolvidos juntos.
**Como evitar:** manter o `avisoLote` (`"N anúncio(s) enfileirado(s)"`) visível até que o polling termine (não limpar em `setPublicandoLote(false)`), e complementar com o `resumoLote` recalculando `publicaveis`/`comErroLocal` a cada merge do Pattern 1.
**Sinais de alerta:** usuário reclama de "cliquei duas vezes e publicou dobrado" (verificar: `ShouldBeUnique` no job já previne isso no backend — `PublicarAnuncioMlJob:25`, `uniqueId()` retorna o `rascunhoId` — mas o clique duplo no frontend ainda gera 2 requisições HTTP para `publicarLote`, que o backend trata corretamente pulando rascunhos já em `STATUS_PUBLICANDO`/`STATUS_PUBLICADO`, linhas 1328-1331 do controller — dado isso, o duplo-clique é inofensivo no pior caso, mas ainda vale destravar o botão de forma que o usuário confie no feedback).

### Pitfall 3: Confundir `l.valida.erros` (FIX-83-4, orientativo) com `validation_errors`/`erro_completo` (FIX-83-3, real)

**O que dá errado:** implementar UM painel só para "avisos" que mistura os dois tipos de erro — o do `/items/validate` (dry-run, PODE ser falso-positivo, NUNCA bloqueia) e o do POST `/items` real (que É o resultado definitivo da publicação).
**Por que acontece:** ambos têm o shape `{code, campo, mensagem}` [VERIFICADO — `MlItemPayloadValidator::traduzir()` linhas 59-63 gera esse shape para `l.valida.erros`; `MlPublicacaoService.php:144` gera o mesmo shape `{code, campo, mensagem}` para `validation_errors`, mas com semânticas MUITO diferentes: um é "pode estar errado", o outro é "FALHOU de fato"].
**Como evitar:** manter a distinção visual já estabelecida em `resumoLote`/`PublishBar` (vermelho = erro local bloqueante; âmbar = aviso ML orientativo) e adicionar uma TERCEIRA categoria visual para "erro real de publicação" (`status === 'erro'`, que é o que vem de `erro_resumo`/`erro_completo`) — não reaproveitar a cor âmbar para os dois.
**Sinais de alerta:** usuário vê "aviso do ML" numa linha que na verdade JÁ FALHOU ao publicar de verdade (situação mais grave que um aviso orientativo).

### Pitfall 4: Aplicar a coerção de vírgula em `montarPayloadLinha` em vez de `onCellsEdited`

**O que dá errado:** [confirmado no brief e nesta pesquisa] corrigir só na saída (`Number(l.price.replace(',','.'))` dentro de `montarPayloadLinha`) resolve a PUBLICAÇÃO, mas o autosave (que chama a MESMA função ANTES de qualquer tentativa de publicar) continuaria persistindo dados errados se o usuário nunca chegasse a publicar — e mais importante, `errosLocaisLinha` (`Number(l.price) > 0`) continuaria vendo `NaN` e marcando a linha como "erro local: preço" mesmo com o campo visualmente preenchido com `"129,99"`, confundindo o usuário (o campo parece preenchido, mas o sistema diz que falta).
**Por que acontece:** `l.price` no ESTADO do React precisa estar sempre no formato que `Number()` entende — se a correção for só na borda de saída, o estado intermediário fica inconsistente com o que a UI de validação local já lê.
**Como evitar:** normalizar em `onCellsEdited` (Pattern 4) — mantém `l.price` sempre limpo no estado, e nenhuma outra função precisa de defesa redundante.
**Sinais de alerta:** grade mostra "preço: 129,99" preenchido mas o glifo de status mostra `!` (erro local) dizendo que falta preço.

### Pitfall 5: Esquecer que `onDelete` do `<DataEditor>` (nível grade) e `onDelete` do custom cell renderer (nível célula) são APIs DIFERENTES com o MESMO nome

**O que dá errado:** implementar só `onDelete` no `<DataEditor>` (prop) esperando que isso baste — mas se a prop retornar `true` (comportamento default), a lib ainda delega célula a célula para o `onDelete` dos custom renderers, que continuam faltando.
**Por que acontece:** [VERIFICADO no código-fonte, `data-editor.js:1836-1849` vs `cell-types.d.ts:67`] são duas assinaturas de mesmo nome em níveis diferentes da API — fácil de implementar uma e achar que resolveu as duas.
**Como evitar:** para esta grade especificamente, a Opção A do Pattern 5 (adicionar `onDelete` nos 2 custom renderers) é suficiente e MAIS simples que reimplementar tudo no nível `<DataEditor>` — não confundir as duas opções, escolher uma só.
**Sinais de alerta:** Delete funciona em `gtin`/`descricao` (texto puro) mas continua sem efeito em `title`/`price`/`tier`/etc. mesmo depois de "implementar onDelete".

### Pitfall 6: `router.reload` durante uma edição em andamento pode gerar "flicker" ou perda de foco na célula

**O que dá errado:** o polling a cada 3s, se disparar enquanto o usuário está editando OUTRA linha da mesma aba, pode causar re-render da grade inteira (já que `getCellContent`/`aba.linhas` mudam de referência a cada merge do Pattern 1).
**Por que acontece:** o merge do Pattern 1 sempre cria um novo array `linhas` via `.map()`, mesmo quando nada mudou de fato para uma linha específica — isso força `getCellContent`/`onCellsEdited` (que dependem de `aba.linhas`) a serem recriados, o que a Phase 82 já identificou como risco de performance (Pitfall 3 do research da Phase 82: "getCellContent instável causa redraw completo").
**Como evitar:** o guard já incluído no Pattern 1 (`if (l.status === r.status && l.erro_completo === r.erro_completo) return l;`) evita recriar objetos de linha que não mudaram — mas isso só evita a recriação DA LINHA, não do array `linhas` inteiro (`.map()` sempre retorna array novo). Se instabilidade visual for observada no checkpoint, considerar recriar `abas` só quando `.some()` detectar mudança real antes de chamar `setAbas`.
**Sinais de alerta:** cursor de edição "pula" ou perde foco durante os ~3s de uma publicação em andamento; scroll da grade reseta sozinho.

## Code Examples

Já cobertos inline em cada Pattern (§Architecture Patterns 1-6), todos com fonte citada (código-fonte do próprio projeto ou da lib instalada). Resumo do que muda por arquivo:

### `AnunciarMassa.jsx` — mudanças propostas
```jsx
// 1. Merge de rascunhos atualizados em abas[].linhas[] (Pattern 1) — NOVO useEffect
// 2. Polling condicional (Pattern 2) — NOVO useEffect, adaptado do wizard
// 3. publicarLote: adicionar finally (ou setPublicandoLote(false) fora do catch)
const publicarLote = useCallback(async () => {
    // ... (código existente até o try)
    try {
        // ... (dispatch existente)
        setTimeout(() => router.reload({ only: ['rascunhos'] }), 1500);
        // O polling do Pattern 2 assume a partir daqui — este primeiro reload
        // pode até ser removido, já que o useEffect de polling detectará
        // status='publicando' assim que o merge (Pattern 1) rodar pela primeira vez.
    } catch (err) {
        setErrosLote(err.response?.data?.erros ?? [{ mensagem: 'Erro ao enfileirar publicação em lote.' }]);
    } finally {
        setPublicandoLote(false); // FIX-83-1: agora SEMPRE executa
    }
}, [resumoLote, empresa.id]);
```

### `GradeAnuncioGlide.jsx` — mudanças propostas
```jsx
// FIX-83-6a: onDelete nos 2 custom renderers (Pattern 5, Opção A)
const origemCellRenderer = {
    // ... (resto igual)
    onDelete: (cell) => ({ ...cell, data: { ...cell.data, texto: '' } }),
};
const dropdownComDelete = { ...DropdownCell, onDelete: (cell) => ({ ...cell, data: { ...cell.data, value: undefined } }) };
const RENDERERS = [dropdownComDelete, origemCellRenderer];

// FIX-83-5: normalização de vírgula em onCellsEdited (Pattern 4)
// (trecho já mostrado em §Pattern 4)
```

## State of the Art

| Abordagem antiga | Abordagem atual | Quando mudou | Impacto |
|--------------------|-------------------|----------------|---------|
| `AnunciarMassa.jsx` lê `rascunhos` só no mount (Phase 82) | `AnunciarMassa.jsx` também reage a atualizações de `rascunhos` via merge por id (esta fase) | Nesta fase | Corrige a causa raiz de FIX-83-1/2/3 de uma vez |
| Delete sem efeito em 8/10 colunas base (Phase 82, não intencional — gap descoberto nesta pesquisa) | `onDelete` implementado nos 2 custom cell renderers | Nesta fase | Delete passa a funcionar em TODAS as colunas, não só nas de texto puro |
| Preço só aceita `.` como separador decimal | Preço aceita `,` e `.`, normalizado no ponto único de escrita | Nesta fase | Autosave para de persistir `price: null` silenciosamente quando o usuário digita padrão BR |

**Deprecated/outdated:** nenhum — esta fase não substitui nenhuma lib nem remove nenhum padrão, só completa lacunas deixadas pela Phase 82.

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|-------|-------------------|
| A1 | Um timeout de segurança no polling (além do critério "nenhum status=publicando") é uma melhoria recomendada, não uma cópia do precedente do wizard (que não tem timeout) | §Pattern 2 | Baixo — se não implementado, o pior caso é o polling continuar rodando indefinidamente enquanto qualquer rascunho estiver "preso" em `publicando` (situação que hoje só aconteceria por um bug de infraestrutura, já que o job sempre transiciona para `erro`/`publicado`) |
| A2 | Recomendação de "Opção A" (apagar) sobre "Opção B" (mover para Sem categoria) para "remover categoria" é uma recomendação de simplicidade de implementação, não uma certeza sobre a preferência do usuário | §Pattern 6 | Médio — se o usuário preferir "mover" em vez de "apagar", a implementação muda substancialmente (ver Open Questions) |
| A3 | O guard `if (l.status === r.status && l.erro_completo === r.erro_completo) return l` no merge (Pattern 1) é suficiente para evitar instabilidade de `getCellContent` — não testado em runtime real desta grade (canvas não é testável em localhost, conforme constraint da fase) | §Pitfall 6 | Baixo/médio — só descoberto no checkpoint visual humano em produção; se houver flicker, a mitigação (recriar `abas` só quando houver mudança real) é simples de aplicar depois |

**Nenhuma claim desta pesquisa depende de fonte não-oficial ou não-verificada** — os achados centrais vêm de: (a) leitura direta do código-fonte deste mesmo repositório, (b) leitura direta do código-fonte minificado da lib já instalada em `node_modules`, (c) `git show` do commit de precedente citado no brief.

## Open Questions

1. **"Remover categoria" deve APAGAR os rascunhos ou MOVER para "Sem categoria"?**
   - O que sabemos: apagar é tecnicamente mais simples (reusa `onRemoverLinha` sem mudança) e tem precedente (`removerEmLote` já usa `window.confirm` + apagar). Mover é mais seguro para o dado mas exige lidar com a re-derivação de `category_id` no autosave de linhas já persistidas.
   - O que não está claro: se o usuário considera aceitável perder rascunhos ao remover uma categoria, ou se espera que os dados sejam preservados em algum lugar.
   - Recomendação: perguntar explicitamente no `/gsd-discuss-phase` — apresentar as duas opções com o trade-off acima. É uma decisão de produto, não técnica.

2. **O timeout de segurança do polling (Pattern 2) deve existir nesta fase ou é over-engineering?**
   - O que sabemos: o wizard (1 rascunho por vez) não tem timeout e funciona bem em produção. O lote pode ter até 50 rascunhos.
   - O que não está claro: se vale a complexidade extra (um `deadline` + aviso de "tempo esgotado, verifique manualmente") para um caso que, teoricamente, sempre se resolve sozinho (o job sempre termina em `erro` ou `publicado`).
   - Recomendação: incluir um teto conservador (ex.: 5 minutos, mesmo valor usado em `Grants/Index.jsx`) por segurança de UX — é barato de implementar e evita a impressão de "travado" numa rede lenta ou pico de fila.

3. **O painel de avisos/erros (FIX-83-3/83-4) fica dentro de `GradeAnuncioGlide.jsx` (perto do canvas) ou em `AnunciarMassa.jsx` (perto da `PublishBar`)?**
   - O que sabemos: tecnicamente pode ficar em qualquer um dos dois — ambos são DOM puro fora do canvas.
   - O que não está claro: preferência de organização de código (a `PublishBar` já é o lugar onde o resumo agregado do lote aparece; um painel de detalhe por linha poderia vivar ali do lado, ou ficar mais perto da grade que ele descreve).
   - Recomendação: colocar em `GradeAnuncioGlide.jsx`, logo abaixo do `<DataEditor>` — mantém a relação "detalhe de uma linha específica" fisicamente perto da grade que a renderiza, e seguindo a mesma convenção da toolbar de seleção que já vive ali.

## Environment Availability

Não aplicável — esta fase não introduz nenhuma dependência de infraestrutura, biblioteca ou serviço externo novo. Todos os mecanismos usados (queue database, polling via Inertia, canvas grid) já estão em produção neste mesmo módulo.

## Validation Architecture

### Test Framework

| Propriedade | Valor |
|-------------|-------|
| Framework (backend) | PHPUnit 11.x — `phpunit.xml` |
| Framework (frontend/JS) | Nenhum instalado (mesma lacuna identificada no research da Phase 82 — `vitest` não adotado) |
| Comando rápido (backend) | `php artisan test --filter=Phase82` (cobre as rotas que esta fase reaproveita sem mudar) |
| Comando completo (backend) | `php artisan test` |

### Phase Requirements → Test Map

| Req ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|--------|----------------|----------------|------------------------|-------------------|
| FIX-83-1 | Botão destrava após publicar em lote com sucesso | manual-only | — | ❌ estado de UI (`publicandoLote`), sem framework JS |
| FIX-83-2 | Grade reflete status real após polling | manual-only (canvas + timing assíncrono) | — | ❌ não testável em localhost (0 empresas com `ml_token`, conforme constraint da fase) |
| FIX-83-3 | Erro completo expansível mostra o texto de `erro_completo` | manual-only | — | ❌ idem |
| FIX-83-4 | Avisos do ML aparecem expandidos por linha | manual-only | — | ❌ idem |
| FIX-83-5 | Preço com vírgula é aceito e publicado corretamente | **unit (se `vitest` for adotado) OU manual** | seria: testar a função de normalização isoladamente | ❌ Wave 0 gap se `vitest` for adotado — função pura, fácil de isolar |
| FIX-83-6a | Delete limpa células selecionadas de qualquer coluna | manual-only | — | ❌ interação de canvas |
| FIX-83-6b | Remover categoria apaga/move os rascunhos da aba | Feature (PHPUnit) SE reaproveitar `rascunho.destroy` (já testado) | `php artisan test --filter=GradeMassaTest` (rotas já cobertas) | ✅ a rota já tem teste; o NOVO comportamento (loop no frontend) não tem teste de UI |

**Justificativa manual-only:** idêntica à Phase 82 — canvas não expõe DOM inspecionável, e esta fase explicitamente NÃO pode ser verificada em localhost (0 empresas com `ml_token` — checkpoint sempre em produção, conforme constraint do brief).

### Sampling Rate
- **Por commit de task:** `php artisan test --filter=Phase82` (as rotas reaproveitadas não mudam de contrato, mas rodar mesmo assim como rede de segurança)
- **Por merge de wave:** `php artisan test` (suíte completa)
- **Phase gate:** suíte completa verde + `npm run build` sem erros + **checkpoint visual humano EM PRODUÇÃO** cobrindo os 6 requisitos (publicar em lote de verdade, ver polling drenar, expandir erro, expandir aviso, testar vírgula no preço, testar Delete e remover categoria)

### Wave 0 Gaps
- [ ] Nenhum framework de teste JS existe — a função de normalização de vírgula (Pattern 4) É uma boa candidata a teste unitário isolado (função pura, sem DOM/canvas) SE o planner decidir introduzir `vitest` nesta fase ou adiar para depois (mesma decisão em aberto herdada do research da Phase 82).
- [ ] Nenhum teste PHPUnit novo é estritamente necessário — nenhuma rota nem controller muda nesta fase (100% frontend). Considerar apenas se o planner optar por um endpoint de bulk-destroy em vez do loop reaproveitado (não recomendado por esta pesquisa).

## Security Domain

`security_enforcement` não está `false` em `.planning/config.json` — enforcement habilitado por padrão.

### Applicable ASVS Categories

| Categoria ASVS | Aplica | Controle padrão |
|------------------|--------|-------------------|
| V2 Autenticação | Não | Sem mudança |
| V4 Controle de acesso | Não (mesmas rotas, mesmo double-check por empresa já existente) | `abort_unless` por `responsavel_id`/`isAdmin` já cobre `rascunho.destroy` (reaproveitado no loop de "remover categoria") |
| V5 Validação de entrada | Sim — preço com vírgula é entrada de usuário | Normalização feita client-side (Pattern 4); servidor não valida `payload.price` hoje (nenhuma regressão de segurança — já era assim antes desta fase) |
| V6 Criptografia | Não aplicável | — |

### Known Threat Patterns for este stack

| Padrão | STRIDE | Mitigação padrão |
|--------|--------|--------------------|
| Loop de "remover categoria" disparando N `DELETE` simultâneos sem rate-limit no frontend | Denial of Service (auto-infligido, baixo risco) | Nenhuma mitigação nova necessária — mesmo padrão de `removerEmLote` já existente, sem relatos de problema; volume esperado é baixo (dezenas de linhas por categoria, não milhares) |
| Polling indefinido sem timeout consumindo requisições ao servidor | Denial of Service (auto-infligido) | Timeout de segurança recomendado no Pattern 2 (Open Question #2) mitiga isso |

## Sources

### Primary (ALTA confiança — lido diretamente neste sessão)
- `resources/js/Pages/Mlb/AnunciarMassa.jsx` (777 linhas, lido integralmente) — estado, autosave, `publicarLote`, `PublishBar`, `montarPayloadLinha`, `linhaDeRascunho`
- `resources/js/Pages/Mlb/GradeAnuncioGlide.jsx` (591 linhas, lido integralmente) — `onCellsEdited`, `coercePasteValue`, `origemCellRenderer`, `COLS_COM_ORIGEM`, `RENDERERS`
- `resources/js/Pages/Mlb/gradeMassaUtils.js` (127 linhas, lido integralmente) — `errosLocaisLinha`, `linhaPublicavel`, `linhaVazia`
- `app/Http/Controllers/MlbAnuncioController.php` (1451 linhas, lido em 2 passes) — `massa()`, `publicarLote()`, `resumoErro()`, `erroCompleto()`, `atualizarRascunho()`
- `app/Jobs/PublicarAnuncioMlJob.php` (88 linhas, lido integralmente) — `handle()`, `failed()`, `ShouldBeUnique`
- `app/Models/MlAnuncioRascunho.php` (74 linhas, lido integralmente) — constantes `STATUS_*`, casts
- `app/Services/Mlb/Publicacao/MlItemPayloadValidator.php` — shape `{code, campo, mensagem}` de `traduzir()`
- `git show 9e5a640` — precedente exato do `<details>/<summary>` expansível
- `resources/js/Pages/Mlb/AnunciarML.jsx` (grep + trechos) — precedente EXATO do polling condicional (linhas 1366-1373) e `fallbackCopiar` (linha 54)
- `node_modules/@glideapps/glide-data-grid/dist/esm/data-editor/data-editor.js` (lido via grep com contexto) — mecanismo real de `onDelete`/`deleteRange`
- `node_modules/@glideapps/glide-data-grid/dist/dts/cells/cell-types.d.ts` + `data-editor.d.ts` — assinaturas exatas de `onDelete` (nível célula vs. nível grade)
- `node_modules/@glideapps/glide-data-grid/API.md` — documentação oficial de `onDelete`/`onDeleteRows`
- `node_modules/@glideapps/glide-data-grid/dist/esm/cells/text-cell.js` + `number-cell.js` — confirmação de `onDelete` embutido nas células nativas
- `node_modules/@glideapps/glide-data-grid-cells/dist/esm/cells/dropdown-cell.js` — confirmação de AUSÊNCIA de `onDelete` no `DropdownCell`
- `.env.example` + `config/queue.php` — confirmação `QUEUE_CONNECTION=database`, `BROADCAST_CONNECTION=log` (sem Redis/websockets)
- `routes/mlb_anuncios.php` (grep) — confirmação de que não existe rota de bulk-destroy
- Grep de precedentes de polling: `resources/js/Components/NotificationBell.jsx`, `resources/js/Pages/Sugadores/EmpresaListagem.jsx`, `resources/js/Pages/Grants/Index.jsx`, `resources/js/Pages/Mlb/Coleta.jsx`, `resources/js/Pages/Performance/Index.jsx`, `resources/js/Pages/Mlb/ImplementacaoPublicador.jsx`

### Secondary / Tertiary
Nenhuma — esta pesquisa não precisou de WebSearch/WebFetch: todas as respostas estavam no próprio código do repositório ou no código-fonte da lib já instalada.

## Metadata

**Confidence breakdown:**
- Causa raiz de FIX-83-1/2/3 (merge de estado ausente): ALTA — confirmado lendo o `useEffect` com deps vazias linha a linha, e confirmando que não existe NENHUM outro lugar que releia `rascunhos`
- Precedente de polling (Pattern 2): ALTA — código já existe e roda em produção no wizard, não é hipótese
- Mecanismo de `onDelete` (Pattern 5): ALTA — verificado lendo o bundle JS real da lib instalada, não documentação de terceiros
- Ponto de coerção de vírgula (Pattern 4): ALTA — confirmado que `montarPayloadLinha` já é chamado pelo autosave, não só na publicação
- Recomendação "apagar vs. mover" para remover categoria (Pattern 6): MÉDIA — é uma recomendação de simplicidade, decisão final é de produto (ver Open Questions)

**Data da pesquisa:** 2026-07-15
**Válido até:** ~2026-08-14 (30 dias — nenhuma dependência externa nova; validade limitada apenas pela possibilidade de mudanças no próprio código do projeto entre esta pesquisa e a execução do plano)
