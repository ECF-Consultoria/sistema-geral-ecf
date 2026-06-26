import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    Settings, ArrowLeft, Building2, AlertTriangle, PlayCircle,
    DollarSign, MousePointer, Percent, Megaphone, Tag, ToggleRight,
} from 'lucide-react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';

const fmtBRL = (n) => 'R$ ' + Number(n ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function Field({ icon: Icon, label, hint, children }) {
    return (
        <div>
            <label className="flex items-center gap-2 text-white/80 text-[13px] font-semibold mb-1.5">
                {Icon && <Icon size={13} className="text-white/40" />}
                {label}
            </label>
            {children}
            {hint && <p className="text-white/40 text-[11px] mt-1.5">{hint}</p>}
        </div>
    );
}

/**
 * Field específico de critério de detecção — header tem o toggle E/OU à direita.
 * Disabled quando o threshold está vazio (não faz sentido escolher modo de um
 * critério desligado).
 */
function CriterioField({ icon: Icon, label, hint, logic, onLogicChange, disabledLogic, children }) {
    return (
        <div>
            <div className="flex items-center justify-between gap-2 mb-1.5">
                <label className="flex items-center gap-2 text-white/80 text-[13px] font-semibold">
                    {Icon && <Icon size={13} className="text-white/40" />}
                    {label}
                </label>
                <LogicToggle value={logic} onChange={onLogicChange} disabled={disabledLogic} />
            </div>
            {children}
            {hint && <p className="text-white/40 text-[11px] mt-1.5">{hint}</p>}
        </div>
    );
}

function NumberInput({ value, onChange, placeholder, suffix, step = '0.01', error }) {
    return (
        <div className="relative">
            <input
                type="number"
                step={step}
                value={value ?? ''}
                onChange={e => onChange(e.target.value === '' ? null : e.target.value)}
                placeholder={placeholder}
                className={cn(
                    'w-full h-9 px-3 rounded-lg border bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40',
                    error ? 'border-red-500/40' : 'border-white/[0.08]',
                    suffix && 'pr-12'
                )}
            />
            {suffix && (
                <span className="absolute right-3 top-1/2 -translate-y-1/2 text-white/40 text-xs pointer-events-none">{suffix}</span>
            )}
        </div>
    );
}

/**
 * Toggle de 2 estados (E / OU) para cada critério de detecção. Usado pra
 * decidir se o critério é obrigatório (AND) ou alternativo (OR) na avaliação.
 * Visualmente é um par de pílulas: E (required) ou OU (optional).
 * Desabilitado quando o critério está desligado (threshold null).
 */
function LogicToggle({ value, onChange, disabled }) {
    return (
        <div className={cn(
            'inline-flex items-center rounded-md border border-white/[0.08] bg-white/[0.02] p-0.5 text-[10px] font-bold uppercase tracking-wider',
            disabled && 'opacity-40'
        )}>
            <button
                type="button"
                disabled={disabled}
                onClick={() => onChange('required')}
                title="E (AND) — TEM que atender pra item ser flagado"
                className={cn(
                    'px-2 py-0.5 rounded-sm transition-colors',
                    value === 'required'
                        ? 'bg-ecf-yellow text-[#252525]'
                        : 'text-white/50 hover:text-white/80'
                )}
            >
                E
            </button>
            <button
                type="button"
                disabled={disabled}
                onClick={() => onChange('optional')}
                title="OU (OR) — atender já basta, mas não é obrigatório"
                className={cn(
                    'px-2 py-0.5 rounded-sm transition-colors',
                    value === 'optional'
                        ? 'bg-white/[0.15] text-white'
                        : 'text-white/50 hover:text-white/80'
                )}
            >
                OU
            </button>
        </div>
    );
}

function ToggleSwitch({ checked, onChange }) {
    return (
        <button
            type="button"
            onClick={() => onChange(!checked)}
            className={cn(
                'relative inline-flex h-5 w-9 items-center rounded-full transition-colors',
                checked ? 'bg-ecf-yellow' : 'bg-white/10'
            )}
        >
            <span className={cn(
                'inline-block h-4 w-4 rounded-full bg-white transition-transform',
                checked ? 'translate-x-[18px]' : 'translate-x-0.5'
            )} />
        </button>
    );
}

export default function SugadoresConfig({ company, config }) {
    const { data, setData, put, processing, errors } = useForm({
        dias_analise:                    config?.dias_analise ?? 30,
        gasto_minimo_sem_venda:          config?.gasto_minimo_sem_venda ?? 20.00,
        gasto_minimo_logic:              config?.gasto_minimo_logic ?? 'optional',
        cpc_maximo:                      config?.cpc_maximo ?? null,
        cpc_maximo_logic:                config?.cpc_maximo_logic ?? 'optional',
        // Phase 42 D-01: gate opcional — quando preenchido, CPC alto so flagra com X cliques minimos
        cpc_minimo_cliques:              config?.cpc_minimo_cliques ?? null,
        acos_maximo_pct:                 config?.acos_maximo_pct ?? null,
        acos_maximo_logic:               config?.acos_maximo_logic ?? 'optional',
        cliques_minimos_sem_venda:       config?.cliques_minimos_sem_venda ?? null,
        cliques_minimos_logic:           config?.cliques_minimos_logic ?? 'optional',
        pct_anuncios_para_flag_campanha: config?.pct_anuncios_para_flag_campanha ?? 50,
        incluir_campanhas:               config?.incluir_campanhas ?? false,
        incluir_anuncios:                config?.incluir_anuncios ?? true,
        ativo:                           config?.ativo ?? true,
    });

    const [analyzing, setAnalyzing] = useState(false);

    function submit(e) {
        e.preventDefault();
        put(route('sugadores.config.update', company.id), { preserveScroll: true });
    }

    function runAnalysis() {
        if (!confirm(`Rodar análise de Sugadores para ${company.name} agora?`)) return;
        setAnalyzing(true);
        router.post(route('sugadores.analyze-company', company.id), {}, {
            preserveScroll: true,
            onFinish: () => setAnalyzing(false),
        });
    }

    return (
        <AppLayout title="Configuração de Sugadores">
            <button
                onClick={() => window.history.back()}
                className="inline-flex items-center gap-2 text-white/50 hover:text-white text-sm mb-4"
            >
                <ArrowLeft size={14} />
                Voltar para Sugadores
            </button>

            <div className="flex items-center justify-between gap-4 mb-6 flex-wrap">
                <div>
                    <div className="flex items-center gap-2 mb-1">
                        <Settings size={18} className="text-white/40" />
                        <h1 className="text-white font-display font-bold text-2xl">Configuração de Sugadores</h1>
                    </div>
                    <p className="text-white/50 text-sm flex items-center gap-1.5">
                        <Building2 size={13} />
                        {company.name}
                        {company.adman_account_id && (
                            <span className="text-white/30 ml-1">· custId: {company.adman_account_id}</span>
                        )}
                    </p>
                </div>

                <button
                    onClick={runAnalysis}
                    disabled={analyzing || !data.ativo}
                    className="inline-flex items-center gap-2 h-10 px-4 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-60 text-[13px] font-bold"
                    title={!data.ativo ? 'Ative a config primeiro' : ''}
                >
                    <PlayCircle size={14} />
                    {analyzing ? 'Analisando...' : 'Rodar análise agora'}
                </button>
            </div>

            <form onSubmit={submit} className="space-y-4">
                {/* Card: ativação */}
                <div className="card-ecf rounded-xl p-5">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <h2 className="text-white font-semibold text-base flex items-center gap-2">
                                <ToggleRight size={15} className="text-white/40" />
                                Análise ativa para esta empresa
                            </h2>
                            <p className="text-white/50 text-xs mt-1">
                                Quando desligado, esta empresa é pulada na rotina diária e na análise global.
                            </p>
                        </div>
                        <ToggleSwitch checked={data.ativo} onChange={v => setData('ativo', v)} />
                    </div>
                </div>

                {/* Card: janela de análise */}
                <div className="card-ecf rounded-xl p-5 space-y-4">
                    <h2 className="text-white font-semibold text-base">Janela de análise</h2>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <Field label="Dias analisados" hint="Janela retroativa em dias (7 a 90).">
                            <NumberInput
                                value={data.dias_analise}
                                onChange={v => setData('dias_analise', v ? parseInt(v, 10) : '')}
                                step="1"
                                error={errors.dias_analise}
                            />
                            {errors.dias_analise && <p className="text-red-400 text-xs mt-1">{errors.dias_analise}</p>}
                        </Field>
                    </div>
                </div>

                {/* Card: critérios de detecção */}
                <div className="card-ecf rounded-xl p-5">
                    <h2 className="text-white font-semibold text-base mb-1">Critérios de detecção</h2>
                    <p className="text-white/50 text-xs mb-1">
                        Cada critério ativo tem um modo: <b className="text-ecf-yellow">E</b> (obrigatório — TEM que atender) ou{' '}
                        <b className="text-white/80">OU</b> (alternativo — basta um deles atender).
                        Deixe um campo em branco para desligar o critério.
                    </p>
                    <p className="text-white/40 text-[11px] mb-4 leading-relaxed">
                        Regra final: item é sugador se <b className="text-white/60">todos</b> os <b className="text-ecf-yellow">E</b> passarem
                        {' '}<b className="text-white/60">e</b> (não há nenhum <b className="text-white/80">OU</b> ou pelo menos um <b className="text-white/80">OU</b> passa).
                        Ex: cliques + gasto marcados como <b className="text-ecf-yellow">E</b> = "só flagar se gastou ≥ R$30 e teve ≥ 10 cliques sem vender".
                    </p>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <CriterioField
                            icon={DollarSign}
                            label="Gasto mínimo sem venda"
                            hint={`Adgroups (e campanhas, se habilitadas) que gastarem ≥ ${data.gasto_minimo_sem_venda ? fmtBRL(data.gasto_minimo_sem_venda) : 'X'} sem registrar nenhuma venda no período.`}
                            logic={data.gasto_minimo_logic}
                            onLogicChange={v => setData('gasto_minimo_logic', v)}
                            disabledLogic={data.gasto_minimo_sem_venda == null || data.gasto_minimo_sem_venda === ''}
                        >
                            <NumberInput
                                value={data.gasto_minimo_sem_venda}
                                onChange={v => setData('gasto_minimo_sem_venda', v)}
                                placeholder="ex: 20.00"
                                suffix="R$"
                                error={errors.gasto_minimo_sem_venda}
                            />
                        </CriterioField>

                        <CriterioField
                            icon={DollarSign}
                            label="CPC máximo (sem venda)"
                            hint="Se o CPC médio passar deste valor E não houver venda."
                            logic={data.cpc_maximo_logic}
                            onLogicChange={v => setData('cpc_maximo_logic', v)}
                            disabledLogic={data.cpc_maximo == null || data.cpc_maximo === ''}
                        >
                            <NumberInput
                                value={data.cpc_maximo}
                                onChange={v => setData('cpc_maximo', v)}
                                placeholder="ex: 1.50"
                                suffix="R$"
                                error={errors.cpc_maximo}
                            />
                        </CriterioField>

                        {/* Phase 42 D-01: modificador opcional do criterio cpc_alto (briefing §8 Opcao B) */}
                        {/* NAO eh criterio novo — eh gate de cliques minimos que reforca o CPC alto. */}
                        {/* Sem LogicToggle: campo simples (Field), nao CriterioField. */}
                        <Field
                            icon={MousePointer}
                            label="Cliques minimos para validar CPC"
                            hint="Vazio = sem gate. Quando preenchido, CPC alto so eh flagado se o anuncio tiver pelo menos X cliques no periodo (briefing Opcao B)."
                        >
                            <NumberInput
                                value={data.cpc_minimo_cliques}
                                onChange={v => setData('cpc_minimo_cliques', v ? parseInt(v, 10) : null)}
                                placeholder="ex: 5"
                                step="1"
                                error={errors.cpc_minimo_cliques}
                            />
                            {errors.cpc_minimo_cliques && <p className="text-red-400 text-xs mt-1">{errors.cpc_minimo_cliques}</p>}
                        </Field>

                        <CriterioField
                            icon={Percent}
                            label="ACOS máximo"
                            hint="Quando há venda mas o ACOS está acima deste %."
                            logic={data.acos_maximo_logic}
                            onLogicChange={v => setData('acos_maximo_logic', v)}
                            disabledLogic={data.acos_maximo_pct == null || data.acos_maximo_pct === ''}
                        >
                            <NumberInput
                                value={data.acos_maximo_pct}
                                onChange={v => setData('acos_maximo_pct', v)}
                                placeholder="ex: 50.00"
                                suffix="%"
                                error={errors.acos_maximo_pct}
                            />
                        </CriterioField>

                        <CriterioField
                            icon={MousePointer}
                            label="Cliques mínimos sem venda"
                            hint="Se acumulou X cliques sem nenhuma venda no período."
                            logic={data.cliques_minimos_logic}
                            onLogicChange={v => setData('cliques_minimos_logic', v)}
                            disabledLogic={data.cliques_minimos_sem_venda == null || data.cliques_minimos_sem_venda === ''}
                        >
                            <NumberInput
                                value={data.cliques_minimos_sem_venda}
                                onChange={v => setData('cliques_minimos_sem_venda', v ? parseInt(v, 10) : null)}
                                placeholder="ex: 50"
                                step="1"
                                error={errors.cliques_minimos_sem_venda}
                            />
                        </CriterioField>
                    </div>
                </div>

                {/* Card: granularidade */}
                <div className="card-ecf rounded-xl p-5 space-y-3">
                    <h2 className="text-white font-semibold text-base">Granularidade</h2>

                    <div className="flex items-center justify-between p-3 rounded-lg bg-white/[0.02] border border-white/[0.06]">
                        <div className="flex items-center gap-3">
                            <Tag size={14} className="text-white/40" />
                            <div>
                                <p className="text-white/80 text-sm font-medium">Analisar adgroups</p>
                                <p className="text-white/40 text-xs">Granularidade principal — é o nível acionável no painel do ML</p>
                            </div>
                        </div>
                        <ToggleSwitch checked={data.incluir_anuncios} onChange={v => setData('incluir_anuncios', v)} />
                    </div>

                    <div className="flex items-center justify-between p-3 rounded-lg bg-white/[0.02] border border-white/[0.06]">
                        <div className="flex items-center gap-3">
                            <Megaphone size={14} className="text-white/40" />
                            <div>
                                <p className="text-white/80 text-sm font-medium">Analisar campanhas (opcional)</p>
                                <p className="text-white/40 text-xs">Avalia métricas agregadas de campanha — útil só pra visão macro</p>
                            </div>
                        </div>
                        <ToggleSwitch checked={data.incluir_campanhas} onChange={v => setData('incluir_campanhas', v)} />
                    </div>

                    <Field
                        label="% de adgroups sugadores → flag campanha"
                        hint={`Se ${data.pct_anuncios_para_flag_campanha}% ou mais dos adgroups da campanha forem sugadores, a campanha mãe também é flagada (só se 'Analisar campanhas' estiver ativo).`}
                    >
                        <NumberInput
                            value={data.pct_anuncios_para_flag_campanha}
                            onChange={v => setData('pct_anuncios_para_flag_campanha', v ? parseInt(v, 10) : 0)}
                            placeholder="50"
                            step="1"
                            suffix="%"
                            error={errors.pct_anuncios_para_flag_campanha}
                        />
                    </Field>
                </div>

                {/* Aviso resumo */}
                <div className="rounded-xl border border-amber-500/20 bg-amber-500/[0.05] p-4 flex gap-3">
                    <AlertTriangle size={16} className="text-amber-400 shrink-0 mt-0.5" />
                    <div className="text-amber-200/80 text-xs leading-relaxed">
                        Mudanças aqui afetam apenas as <strong>próximas execuções</strong> da análise. Sugadores já detectados não são reavaliados retroativamente — eles permanecem com os motivos do dia em que foram detectados, e novos sugadores aparecerão conforme os critérios atualizados a partir da próxima análise.
                    </div>
                </div>

                {/* Footer: salvar */}
                <div className="sticky bottom-0 -mx-6 px-6 py-4 bg-[#050507]/95 backdrop-blur border-t border-white/[0.06] flex justify-end">
                    <button
                        type="submit"
                        disabled={processing}
                        className="px-5 h-10 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-50 text-[13px] font-bold"
                    >
                        {processing ? 'Salvando...' : 'Salvar configuração'}
                    </button>
                </div>
            </form>
        </AppLayout>
    );
}
