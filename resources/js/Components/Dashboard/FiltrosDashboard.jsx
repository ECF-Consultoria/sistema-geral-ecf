import { useEffect, useMemo, useState } from 'react';
import { ChevronDown, SlidersHorizontal, X } from 'lucide-react';
import { cn } from '@/lib/utils';

// Fase 97 Plan 03 (DASH-97-1) — opções de período do redesign (mockup do
// usuário). Substituem o antigo conjunto 1/7/180 dias no painel de filtros;
// o backend (DashboardController::getPeriodRange) continua aceitando 1/180
// por compatibilidade com links antigos, mas a UI só oferece este recorte.
export const PERIOD_OPTIONS = [
    { value: '7', label: 'Últimos 7 dias' },
    { value: '30', label: 'Últimos 30 dias' },
    { value: '60', label: 'Últimos 60 dias' },
    { value: '90', label: 'Últimos 90 dias' },
];

const DEFAULT_PERIOD = '30';

// Nomes legíveis dos campos de filtro — usados nos chips ("Empresa: Bella
// Verde") e comentários. Comentários em pt-BR conforme convenção do projeto.
const FIELD_LABELS = {
    period: 'Período',
    company_id: 'Empresa',
    group_id: 'Grupo',
    consultor_id: 'Analista',
    estrategista_id: 'Estrategista',
};

function ECFSelect({ value, onChange, placeholder, options }) {
    return (
        <div className="relative">
            <select
                value={value || ''}
                onChange={e => onChange(e.target.value)}
                className="w-full appearance-none h-10 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40 focus:border-ecf-yellow/40 transition-all cursor-pointer"
            >
                {placeholder && <option value="">{placeholder}</option>}
                {options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            <ChevronDown size={13} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
        </div>
    );
}

function Field({ label, children }) {
    return (
        <div className="flex flex-col gap-1.5 min-w-[160px] flex-1">
            <label className="text-[10.5px] font-bold uppercase tracking-wide text-white/40">{label}</label>
            {children}
        </div>
    );
}

/**
 * FiltrosDashboard — painel de filtros do Dashboard/Admin (Fase 97, DASH-97-1).
 *
 * Resolve a reclamação central do usuário ("os filtros não propagam de forma
 * previsível") trocando o padrão antigo (cada select disparava navegação na
 * hora) pelo padrão RASCUNHO → APLICAR do mockup:
 *   - Mudar um select só altera o estado local `draft` — nada é enviado ao
 *     backend ainda.
 *   - O recorte só passa a valer quando o usuário clica em "Aplicar", que
 *     chama `onApply(draft)` (o pai decide como navegar).
 *   - O botão "Aplicar" destaca em amarelo (`ecf-yellow`) quando `draft`
 *     diverge do recorte ATIVO (`applied`) — sinaliza mudança pendente.
 *
 * Os CHIPS abaixo sempre refletem o recorte ATIVO (nunca o rascunho) e são
 * removíveis individualmente — remover um chip é uma mudança definitiva de
 * recorte, então já chama `onApply` na hora (não fica em rascunho).
 *
 * `filters`/`period` vêm do backend (recorte aplicado); `onApply` é chamado
 * com o objeto completo `{ period, company_id, group_id, consultor_id,
 * estrategista_id }` sempre que o recorte deve mudar de verdade.
 */
export default function FiltrosDashboard({
    period,
    filters = {},
    companiesList = [],
    gruposList = [],
    analistas = [],
    estrategistas = [],
    combinacoes = [],
    onApply,
}) {
    // Recorte ATIVO normalizado (strings vazias em vez de undefined/null —
    // simplifica a comparação com o draft e o preenchimento dos selects).
    const applied = useMemo(() => ({
        period: period || DEFAULT_PERIOD,
        company_id: filters.company_id ? String(filters.company_id) : '',
        group_id: filters.group_id ? String(filters.group_id) : '',
        consultor_id: filters.consultor_id ? String(filters.consultor_id) : '',
        estrategista_id: filters.estrategista_id ? String(filters.estrategista_id) : '',
    }), [period, filters.company_id, filters.group_id, filters.consultor_id, filters.estrategista_id]);

    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(applied);

    // Ressincroniza o rascunho sempre que o recorte ativo mudar "por fora"
    // (navegação concluída após Aplicar, remoção de chip, Limpar tudo, ou o
    // usuário voltando/avançando no navegador) — evita o draft ficar preso
    // em valores que já não refletem o backend.
    useEffect(() => {
        setDraft(applied);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [applied.period, applied.company_id, applied.group_id, applied.consultor_id, applied.estrategista_id]);

    const setDraftField = (key, value) => setDraft(d => ({ ...d, [key]: value }));

    // Cross-filter analista <-> estrategista via `combinacoes` (preservado do
    // Admin.jsx original) — mas agora lê o RASCUNHO, já que o usuário está
    // compondo o recorte antes de aplicar.
    const analistasFiltrados = draft.estrategista_id
        ? analistas.filter(a => combinacoes.some(c =>
            String(c.analista_id) === String(a.id) &&
            String(c.estrategista_id) === String(draft.estrategista_id)
          ))
        : analistas;

    const estrategistasFiltrados = draft.consultor_id
        ? estrategistas.filter(e => combinacoes.some(c =>
            String(c.estrategista_id) === String(e.id) &&
            String(c.analista_id) === String(draft.consultor_id)
          ))
        : estrategistas;

    const dirty = JSON.stringify(draft) !== JSON.stringify(applied);

    const handleApply = () => {
        onApply(draft);
        setOpen(false);
    };

    const handleClearDraft = () => {
        setDraft({ period: DEFAULT_PERIOD, company_id: '', group_id: '', consultor_id: '', estrategista_id: '' });
    };

    const handleClearAll = () => {
        const reset = { period: DEFAULT_PERIOD, company_id: '', group_id: '', consultor_id: '', estrategista_id: '' };
        setDraft(reset);
        onApply(reset);
    };

    // Chips do recorte ATIVO — cada resolve() busca o rótulo legível a partir
    // das listas recebidas (nome da empresa/grupo/pessoa em vez do id cru).
    const chipResolvers = {
        company_id: v => companiesList.find(c => String(c.id) === v)?.name,
        group_id: v => gruposList.find(g => String(g.id) === v)?.name,
        consultor_id: v => analistas.find(a => String(a.id) === v)?.name,
        estrategista_id: v => estrategistas.find(e => String(e.id) === v)?.name,
    };

    // Flags/labels de cada chip computados DENTRO do callback do .map() —
    // pitfall Rollup (Fase 97): nunca herdar de variável de escopo externo.
    const chips = Object.keys(chipResolvers)
        .filter(key => applied[key])
        .map(key => {
            const rawValue = applied[key];
            const label = chipResolvers[key](rawValue) ?? rawValue;
            return {
                key,
                field: FIELD_LABELS[key],
                label,
                onRemove: () => {
                    const next = { ...applied, [key]: '' };
                    setDraft(next);
                    onApply(next);
                },
            };
        });

    if (applied.period !== DEFAULT_PERIOD) {
        const periodLabel = PERIOD_OPTIONS.find(p => p.value === applied.period)?.label ?? `${applied.period} dias`;
        chips.unshift({
            key: 'period',
            field: FIELD_LABELS.period,
            label: periodLabel,
            onRemove: () => {
                const next = { ...applied, period: DEFAULT_PERIOD };
                setDraft(next);
                onApply(next);
            },
        });
    }

    return (
        <div className="flex flex-col gap-2.5">
            {/* Botão de abrir/fechar o painel + resumo textual */}
            <div className="flex items-center gap-2.5 flex-wrap">
                <button
                    type="button"
                    onClick={() => setOpen(o => !o)}
                    className={cn(
                        'flex items-center gap-2 h-10 px-3.5 rounded-xl border text-[13px] font-bold transition-all',
                        open
                            ? 'border-ecf-yellow/30 bg-ecf-yellow/[0.08] text-white'
                            : 'border-white/[0.08] bg-white/[0.03] text-white/70 hover:border-white/20',
                    )}
                >
                    <SlidersHorizontal size={14} />
                    Filtros
                    {chips.length > 0 && (
                        <span className="min-w-[18px] h-[18px] px-1 rounded-full bg-ecf-yellow text-[#252525] text-[11px] font-extrabold flex items-center justify-center">
                            {chips.length}
                        </span>
                    )}
                    <ChevronDown size={13} className={cn('transition-transform text-white/40', open && 'rotate-180')} />
                </button>
                <span className="text-white/30 text-[12px]">
                    {chips.length === 0
                        ? (open ? 'Selecione o recorte e clique em Aplicar' : 'Nenhum filtro aplicado — todo o setor')
                        : ''}
                </span>
            </div>

            {/* Painel colapsável — rascunho */}
            {open && (
                <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4">
                    <div className="flex flex-wrap items-end gap-3">
                        <Field label="Período">
                            <ECFSelect
                                value={draft.period}
                                onChange={v => setDraftField('period', v)}
                                options={PERIOD_OPTIONS}
                            />
                        </Field>
                        <Field label="Empresa">
                            <ECFSelect
                                value={draft.company_id}
                                onChange={v => setDraftField('company_id', v)}
                                placeholder="Todas as empresas"
                                options={companiesList.map(c => ({ value: String(c.id), label: c.name }))}
                            />
                        </Field>
                        {gruposList.length > 0 && (
                            <Field label="Grupo de empresas">
                                <ECFSelect
                                    value={draft.group_id}
                                    onChange={v => setDraftField('group_id', v)}
                                    placeholder="Todos os grupos"
                                    options={gruposList.map(g => ({ value: String(g.id), label: g.name }))}
                                />
                            </Field>
                        )}
                        <Field label="Analista">
                            <ECFSelect
                                value={draft.consultor_id}
                                onChange={v => setDraftField('consultor_id', v)}
                                placeholder="Todos analistas"
                                options={analistasFiltrados.map(u => ({ value: String(u.id), label: u.name }))}
                            />
                        </Field>
                        <Field label="Estrategista">
                            <ECFSelect
                                value={draft.estrategista_id}
                                onChange={v => setDraftField('estrategista_id', v)}
                                placeholder="Todos estrategistas"
                                options={estrategistasFiltrados.map(u => ({ value: String(u.id), label: u.name }))}
                            />
                        </Field>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={handleApply}
                                className={cn(
                                    'h-10 px-4 rounded-xl text-[13px] font-bold transition-all',
                                    dirty
                                        ? 'bg-ecf-yellow text-[#252525] hover:-translate-y-0.5 hover:shadow-lg hover:shadow-ecf-yellow/20'
                                        : 'bg-white/[0.06] text-white/60',
                                )}
                            >
                                Aplicar
                            </button>
                            <button
                                type="button"
                                onClick={handleClearDraft}
                                className="h-10 px-4 rounded-xl border border-white/[0.08] bg-white/[0.02] text-white/50 text-[13px] font-semibold hover:text-white/70 transition-colors"
                            >
                                Limpar
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Chips do recorte ativo */}
            <div className="flex items-center gap-2 flex-wrap min-h-[26px]">
                <span className="text-white/30 text-[12px] font-semibold">Filtros ativos:</span>
                {chips.map(chip => (
                    <span
                        key={chip.key}
                        className="inline-flex items-center gap-1.5 rounded-full border border-white/[0.08] bg-white/[0.04] pl-3 pr-1.5 py-1 text-[12px] font-semibold text-white/80"
                    >
                        <span className="text-white/40 font-medium">{chip.field}:</span> {chip.label}
                        <button
                            type="button"
                            onClick={chip.onRemove}
                            aria-label={`Remover filtro ${chip.field}`}
                            className="w-4 h-4 rounded-full bg-white/[0.08] hover:bg-white/[0.16] text-white/60 flex items-center justify-center transition-colors"
                        >
                            <X size={10} />
                        </button>
                    </span>
                ))}
                {chips.length === 0 && (
                    <span className="text-white/25 text-[12px] italic">
                        Nenhum — mostrando todo o setor (últimos 30 dias)
                    </span>
                )}
                {chips.length > 0 && (
                    <button
                        type="button"
                        onClick={handleClearAll}
                        className="text-[12px] font-semibold text-blue-400 hover:text-blue-300 transition-colors"
                    >
                        Limpar tudo
                    </button>
                )}
            </div>
        </div>
    );
}
