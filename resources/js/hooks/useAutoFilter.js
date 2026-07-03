import { useCallback, useMemo, useState } from 'react';

/**
 * useAutoFilter — motor genérico de AutoFiltro estilo Excel (headless, reutilizável).
 *
 * Filtragem por coluna + cruzada, valores únicos dinâmicos, contadores recalculados,
 * ordenação por tipo e persistência. Toda a lógica roda client-side (instantânea).
 *
 * Modelo de estado = conjunto de valores DESMARCADOS por coluna (`unchecked[key]`), não
 * os marcados. Assim um valor NOVO que aparecer nos dados (ex.: criado inline) passa por
 * padrão — atende auto-update + valores dinâmicos sem tratamento especial.
 *
 * @param {Array<Object>} rows    linhas (dados já no front)
 * @param {Object|Array}  columns defs de coluna: { key, label, type:'text'|'number'|'date',
 *                                 filter?:bool, sortable?:bool, accessor:(row)=>val|val[], format?:(v)=>label }
 * @param {Object} opts { search?:string, matchSearch?:(row,qLower)=>bool, storageKey?:string }
 */

export const VAZIO = '__VAZIO__';

// Normaliza o retorno do accessor para um array de strings (chaves de comparação).
function valoresDaLinha(col, row) {
    const raw = col.accessor ? col.accessor(row) : row[col.key];
    if (Array.isArray(raw)) {
        const arr = raw.map((x) => (x == null || x === '' ? VAZIO : String(x)));
        return arr.length ? arr : [VAZIO];
    }
    if (raw == null || raw === '') return [VAZIO];
    return [String(raw)];
}

// Comparador por tipo — vazios SEMPRE por último (mesmo em ordem descendente).
// A direção é embutida aqui p/ NÃO inverter o sentinela de VAZIO junto com os valores.
function comparador(type, dir = 1) {
    return (a, b) => {
        const av = a === VAZIO;
        const bv = b === VAZIO;
        if (av && bv) return 0;
        if (av) return 1;
        if (bv) return -1;
        if (type === 'number') return dir * (Number(a) - Number(b));
        if (type === 'date') return dir * String(a).localeCompare(String(b)); // ISO Y-m-d ordena cronologicamente
        return dir * String(a).localeCompare(String(b), 'pt-BR', { sensitivity: 'base' });
    };
}

const buscaPadrao = (row, q) => String(row?.nome ?? '').toLowerCase().includes(q);

export function useAutoFilter(rows, columns, opts = {}) {
    const { search = '', matchSearch = buscaPadrao, storageKey = null, visibleKeys = null } = opts;

    const colList = useMemo(() => (Array.isArray(columns) ? columns : Object.values(columns)), [columns]);
    const colByKey = useMemo(() => {
        const m = {};
        colList.forEach((c) => { m[c.key] = c; });
        return m;
    }, [colList]);

    // Estado persistido: { unchecked: {key:[valores]}, sort: {key,dir}|null }
    const [state, setState] = useState(() => {
        if (storageKey && typeof window !== 'undefined') {
            try {
                const raw = window.sessionStorage.getItem(storageKey);
                if (raw) {
                    const p = JSON.parse(raw);
                    return { unchecked: p.unchecked ?? {}, sort: p.sort ?? null };
                }
            } catch (_) { /* ignora storage corrompido */ }
        }
        return { unchecked: {}, sort: null };
    });

    const update = useCallback((fn) => setState((s) => {
        const next = fn(s);
        if (storageKey && typeof window !== 'undefined') {
            try { window.sessionStorage.setItem(storageKey, JSON.stringify(next)); } catch (_) { /* quota/priv */ }
        }
        return next;
    }), [storageKey]);

    // Sets de desmarcados por coluna (derivado).
    const uSets = useMemo(() => {
        const m = {};
        colList.forEach((c) => { m[c.key] = new Set(state.unchecked[c.key] ?? []); });
        return m;
    }, [colList, state.unchecked]);

    const q = (search ?? '').trim().toLowerCase();

    // Uma linha passa numa coluna sse tiver ao menos um valor que NÃO está desmarcado.
    const passaColuna = useCallback((col, row, uSet) => {
        if (!uSet || uSet.size === 0) return true;
        return valoresDaLinha(col, row).some((v) => !uSet.has(v));
    }, []);

    // ── Lista final: busca global + todas as colunas + ordenação ──
    const filtered = useMemo(() => {
        let out = rows.filter((row) => {
            if (q && !matchSearch(row, q)) return false;
            for (const c of colList) {
                if (c.filter === false) continue; // colunas sort-only não filtram
                if (!passaColuna(c, row, uSets[c.key])) return false;
            }
            return true;
        });
        // Só aplica o sort se a coluna ordenada estiver VISÍVEL na lente ativa — evita a
        // tabela "reordenar do nada" por uma coluna oculta (o estado do sort é preservado).
        if (state.sort && colByKey[state.sort.key] && (!visibleKeys || visibleKeys.includes(state.sort.key))) {
            const col = colByKey[state.sort.key];
            const dir = state.sort.dir === 'desc' ? -1 : 1;
            const cmp = comparador(col.type, dir);
            const chave = (row) => valoresDaLinha(col, row)[0];
            out = [...out].sort((a, b) => cmp(chave(a), chave(b)));
        }
        return out;
    }, [rows, colList, colByKey, uSets, q, matchSearch, passaColuna, state.sort, visibleKeys]);

    // ── Opções de uma coluna: contadores CRUZADOS (todas as outras colunas + busca) ──
    const optionsFor = useCallback((key) => {
        const col = colByKey[key];
        if (!col) return [];
        const base = rows.filter((row) => {
            if (q && !matchSearch(row, q)) return false;
            for (const c of colList) {
                if (c.key === key) continue;      // exclui a própria coluna (comportamento Excel)
                if (c.filter === false) continue;
                if (!passaColuna(c, row, uSets[c.key])) return false;
            }
            return true;
        });
        const counts = new Map();
        for (const row of base) {
            for (const v of valoresDaLinha(col, row)) counts.set(v, (counts.get(v) ?? 0) + 1);
        }
        const uSet = uSets[key] ?? new Set();
        uSet.forEach((v) => { if (!counts.has(v)) counts.set(v, 0); }); // desmarcados sumidos → (0) re-marcáveis
        return [...counts.keys()]
            .sort(comparador(col.type))
            .map((v) => ({
                value: v,
                label: col.format ? col.format(v) : (v === VAZIO ? '(Vazios)' : v),
                count: counts.get(v) ?? 0,
                checked: !uSet.has(v),
            }));
    }, [rows, colList, colByKey, uSets, q, matchSearch, passaColuna]);

    // ── Mutadores ──
    const gravarUnchecked = (s, key, set) => {
        const unchecked = { ...s.unchecked };
        if (set.size) unchecked[key] = [...set]; else delete unchecked[key];
        return unchecked;
    };

    const toggleValue = useCallback((key, value) => update((s) => {
        const set = new Set(s.unchecked[key] ?? []);
        if (set.has(value)) set.delete(value); else set.add(value);
        return { ...s, unchecked: gravarUnchecked(s, key, set) };
    }), [update]);

    const selectAll = useCallback((key, visibleValues, checkAll) => update((s) => {
        const set = new Set(s.unchecked[key] ?? []);
        visibleValues.forEach((v) => (checkAll ? set.delete(v) : set.add(v)));
        return { ...s, unchecked: gravarUnchecked(s, key, set) };
    }), [update]);

    const clearColumn = useCallback((key) => update((s) => {
        const unchecked = { ...s.unchecked };
        delete unchecked[key];
        const sort = (s.sort && s.sort.key === key) ? null : s.sort;
        return { ...s, unchecked, sort };
    }), [update]);

    const clearAll = useCallback(() => update(() => ({ unchecked: {}, sort: null })), [update]);

    const setSort = useCallback((key, dir) => update((s) => ({ ...s, sort: dir ? { key, dir } : null })), [update]);

    // Deixa marcado só `value` (desmarca todos os outros valores presentes) — usado no cross-link.
    const setOnly = useCallback((key, value) => update((s) => {
        const col = colByKey[key];
        if (!col) return s;
        const todos = new Set();
        rows.forEach((row) => valoresDaLinha(col, row).forEach((v) => todos.add(v)));
        const alvo = String(value);
        // Se o alvo do cross-link não casar com nenhuma linha (nome de polo divergente entre
        // cockpit/CSV e MlbEmpresa), limpa a coluna em vez de zerar a grade.
        if (!todos.has(alvo)) {
            const unchecked = { ...s.unchecked };
            delete unchecked[key];
            return { ...s, unchecked };
        }
        todos.delete(alvo);
        return { ...s, unchecked: { ...s.unchecked, [key]: [...todos] } };
    }), [update, colByKey, rows]);

    const isColumnActive = useCallback((key) => (uSets[key]?.size ?? 0) > 0, [uSets]);
    const activeCount = useMemo(
        () => Object.values(state.unchecked).filter((a) => a && a.length).length,
        [state.unchecked],
    );

    // Verdadeiro quando o filtro da coluna isola EXATAMENTE `value` (todos os outros valores
    // presentes estão desmarcados). Usado pelos indicadores acionáveis p/ realce + toggle.
    const isOnly = useCallback((key, value) => {
        const uSet = uSets[key];
        const col = colByKey[key];
        if (!uSet || uSet.size === 0 || !col) return false;
        const alvo = String(value);
        if (uSet.has(alvo)) return false;
        const presentes = new Set();
        rows.forEach((r) => valoresDaLinha(col, r).forEach((v) => presentes.add(v)));
        if (!presentes.has(alvo)) return false;
        for (const v of presentes) { if (v !== alvo && !uSet.has(v)) return false; }
        return true;
    }, [uSets, colByKey, rows]);

    // Resumo dos filtros ativos p/ breadcrumb: por coluna ativa, os valores AINDA mostrados.
    const activeSummary = useCallback(() => {
        const out = [];
        for (const col of colList) {
            const uSet = uSets[col.key];
            if (!uSet || uSet.size === 0) continue;
            const presentes = new Set();
            rows.forEach((r) => valoresDaLinha(col, r).forEach((v) => presentes.add(v)));
            const mostrados = [...presentes].filter((v) => !uSet.has(v));
            out.push({
                key: col.key,
                label: col.label,
                valores: mostrados.map((v) => (col.format ? col.format(v) : (v === VAZIO ? '(Vazios)' : v))),
                nMostrados: mostrados.length,
                nPresentes: presentes.size,
            });
        }
        return out;
    }, [colList, uSets, rows]);

    return {
        filtered, optionsFor, sort: state.sort, activeCount,
        toggleValue, selectAll, clearColumn, clearAll, setSort, setOnly, isColumnActive,
        isOnly, activeSummary,
    };
}
