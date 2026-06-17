// Phase 36 Plan 36-02 (D-03) — Página dedicada do Comercial para atribuir
// contrato de serviço a uma empresa existente.
//
// Migrada do modal de /administrativo/empresas (Admin/Empresas.jsx — D-06).
// Quem sabe o que o cliente fechou é o time Comercial. A página recebe a
// empresa pré-resolvida via route model binding (rota
// /comercial/atribuir-servico/{company}) e renderiza:
//   - Header contextual com info da empresa (nome, cust_id, nicho/segment,
//     contato).
//   - Lista de contratos existentes (ativos em destaque + inativos como
//     referência).
//   - Form de NOVO contrato com:
//       * Select de serviço (de servicos_disponiveis) — auto-fill do
//         valor_contratado com valor_padrao do serviço selecionado.
//       * Input valor_contratado com IMaskInput Number BRL (R$ X.XXX,XX) —
//         submit envia mask.unmaskedValue numérico (D-07).
//       * Input date data_contratacao (default hoje) — onChange recalcula
//         data_vencimento +1 ano automaticamente (D-08).
//       * Input date data_vencimento (auto-fill mas editável).
//       * Textarea observacoes.
//       * Checkbox ativo (default true).
//   - Botões Salvar (POST /empresas/{id}/contratos-servico) e Cancelar
//     (volta pra /companies).
import { useState, useMemo } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { IMaskInput } from 'react-imask';
import {
    Briefcase, Building2, Calendar, FileText, Mail, Phone, Save, X,
    CheckCircle2, AlertCircle, Tag,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn, formatCurrency, formatDate } from '@/lib/utils';

/**
 * Calcula a data +1 ano a partir de uma string ISO YYYY-MM-DD.
 * Retorna string ISO YYYY-MM-DD ou '' se entrada inválida (D-08).
 */
function maisUmAno(isoDate) {
    if (!isoDate) return '';
    const d = new Date(isoDate);
    if (Number.isNaN(d.getTime())) return '';
    d.setFullYear(d.getFullYear() + 1);
    return d.toISOString().slice(0, 10);
}

/**
 * Badge ativo/inativo padronizado para listagem de contratos.
 */
function StatusBadge({ ativo }) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-full border shrink-0',
                ativo
                    ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30'
                    : 'bg-white/[0.04] text-white/40 border-white/[0.08]',
            )}
        >
            {ativo ? <CheckCircle2 className="h-3 w-3" /> : <X className="h-3 w-3" />}
            {ativo ? 'Ativo' : 'Inativo'}
        </span>
    );
}

/**
 * Card individual de contrato existente (read-only nesta versão).
 */
function ContratoCard({ contrato }) {
    return (
        <div
            className={cn(
                'rounded-lg border p-3 space-y-2',
                contrato.ativo
                    ? 'border-white/[0.08] bg-white/[0.02]'
                    : 'border-white/[0.05] bg-white/[0.01] opacity-60',
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="text-white font-semibold text-[13px]">
                            {contrato.servico_nome ?? 'Serviço'}
                        </span>
                        <StatusBadge ativo={contrato.ativo} />
                        {contrato.tipo_cobranca && (
                            <span className="text-[10px] text-white/50 border border-white/[0.08] px-1.5 py-0.5 rounded-full">
                                {contrato.tipo_cobranca === 'mensal' ? 'Mensal' : 'Única'}
                            </span>
                        )}
                    </div>
                </div>
                <div className="text-right shrink-0">
                    <div className="text-ecf-yellow font-mono font-semibold text-[14px]">
                        {formatCurrency(contrato.valor_contratado)}
                    </div>
                </div>
            </div>
            <div className="flex items-center gap-4 text-[12px] text-white/50">
                <span className="inline-flex items-center gap-1">
                    <Calendar className="h-3 w-3" />
                    Contratação: {contrato.data_contratacao ? formatDate(contrato.data_contratacao) : '—'}
                </span>
                {contrato.data_vencimento && (
                    <span className="inline-flex items-center gap-1">
                        <Calendar className="h-3 w-3" />
                        Vencimento: {formatDate(contrato.data_vencimento)}
                    </span>
                )}
            </div>
            {contrato.observacoes && (
                <p className="text-[12px] text-white/60 italic border-t border-white/[0.05] pt-2">
                    {contrato.observacoes}
                </p>
            )}
        </div>
    );
}

export default function AtribuirServico({ company, contratos = [], servicos_disponiveis = [] }) {
    // errors compartilhados via Inertia shared props (não usamos useForm aqui
    // porque o submit é simples e queremos controlar máscara BRL manualmente).
    const { errors = {} } = usePage().props;

    // Defaults iniciais do form (D-08):
    //   - data_contratacao = hoje
    //   - data_vencimento  = hoje + 1 ano
    const hojeIso = useMemo(() => new Date().toISOString().slice(0, 10), []);
    const vencimentoIso = useMemo(() => maisUmAno(hojeIso), [hojeIso]);

    const [form, setForm] = useState({
        servico_id: '',
        valor_contratado: '',
        data_contratacao: hojeIso,
        data_vencimento: vencimentoIso,
        observacoes: '',
        ativo: true,
    });
    const [salvando, setSalvando] = useState(false);

    // Separa contratos em ativos e histórico para destacar o que importa.
    const contratosAtivos = contratos.filter(c => c.ativo);
    const contratosHistorico = contratos.filter(c => !c.ativo);

    // onChange do select de serviço — auto-fill do valor_contratado com o
    // valor_padrao do serviço escolhido (UX consistente com modal antigo).
    function escolherServico(servicoId) {
        const servico = servicos_disponiveis.find(s => String(s.id) === String(servicoId));
        setForm(prev => ({
            ...prev,
            servico_id: servicoId,
            valor_contratado: servico ? String(servico.valor_padrao ?? '') : prev.valor_contratado,
        }));
    }

    // onChange da data de contratação — recalcula automaticamente
    // data_vencimento para +1 ano (D-08). Usuário pode override manualmente
    // depois.
    function handleDataContratacaoChange(novaData) {
        setForm(prev => ({
            ...prev,
            data_contratacao: novaData,
            data_vencimento: maisUmAno(novaData),
        }));
    }

    function submit(e) {
        e.preventDefault();
        setSalvando(true);

        router.post(`/empresas/${company.id}/contratos-servico`, form, {
            preserveScroll: false,
            onSuccess: () => {
                router.visit('/companies');
            },
            onFinish: () => setSalvando(false),
        });
    }

    function cancelar() {
        router.visit('/companies');
    }

    const servicoSelecionado = servicos_disponiveis.find(s => String(s.id) === String(form.servico_id));

    return (
        <AppLayout title={`Atribuir serviço — ${company.name}`}>
            <Head title={`Atribuir serviço — ${company.name}`} />
            <main className="p-6 max-w-5xl mx-auto space-y-6">
                {/* Header — info contextual da empresa */}
                <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-5">
                    <div className="flex items-start gap-3">
                        <div className="h-10 w-10 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center shrink-0">
                            <Building2 className="h-5 w-5 text-ecf-yellow" />
                        </div>
                        <div className="flex-1 min-w-0">
                            <h1 className="text-xl font-semibold font-display text-white truncate">
                                {company.name}
                            </h1>
                            <div className="mt-1 flex flex-wrap items-center gap-2 text-[12px] text-white/50">
                                {company.cust_id && (
                                    <span className="inline-flex items-center gap-1">
                                        <Tag className="h-3 w-3" /> cust_id {company.cust_id}
                                    </span>
                                )}
                                {company.cnpj && (
                                    <span className="inline-flex items-center gap-1">
                                        <FileText className="h-3 w-3" /> {company.cnpj}
                                    </span>
                                )}
                            </div>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {company.nicho && (
                                    <span className="text-[11px] font-medium px-2 py-0.5 rounded-full bg-fuchsia-500/10 text-fuchsia-300 border border-fuchsia-500/20">
                                        {company.nicho}
                                    </span>
                                )}
                                {company.segment && (
                                    <span className="text-[11px] font-medium px-2 py-0.5 rounded-full bg-cyan-500/10 text-cyan-300 border border-cyan-500/20">
                                        {company.segment}
                                    </span>
                                )}
                            </div>
                            {(company.email_cliente || company.telefone) && (
                                <div className="mt-3 flex flex-wrap items-center gap-4 text-[12px] text-white/60">
                                    {company.email_cliente && (
                                        <span className="inline-flex items-center gap-1.5">
                                            <Mail className="h-3.5 w-3.5 text-white/40" />
                                            {company.email_cliente}
                                        </span>
                                    )}
                                    {company.telefone && (
                                        <span className="inline-flex items-center gap-1.5">
                                            <Phone className="h-3.5 w-3.5 text-white/40" />
                                            {company.telefone}
                                        </span>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Bloco contratos existentes */}
                <section className="space-y-3">
                    <div className="flex items-center gap-2">
                        <Briefcase className="h-4 w-4 text-ecf-yellow/80" />
                        <h2 className="text-[14px] font-semibold text-white/90">
                            Contratos existentes
                        </h2>
                        <span className="text-[12px] text-white/40">
                            ({contratos.length})
                        </span>
                    </div>
                    {contratos.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-white/[0.08] bg-white/[0.01] p-6 text-center">
                            <Briefcase className="h-6 w-6 text-white/20 mx-auto mb-2" />
                            <p className="text-[13px] text-white/40">
                                Nenhum contrato cadastrado ainda.
                            </p>
                            <p className="text-[12px] text-white/30 mt-1">
                                Use o formulário abaixo para atribuir o primeiro serviço.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {contratosAtivos.length > 0 && (
                                <div className="space-y-2">
                                    {contratosAtivos.map(c => (
                                        <ContratoCard key={c.id} contrato={c} />
                                    ))}
                                </div>
                            )}
                            {contratosHistorico.length > 0 && (
                                <details className="rounded-lg border border-white/[0.05] bg-white/[0.01]">
                                    <summary className="cursor-pointer px-3 py-2 text-[12px] text-white/50 hover:text-white/70">
                                        Histórico ({contratosHistorico.length}{' '}
                                        contrato{contratosHistorico.length === 1 ? '' : 's'} inativo{contratosHistorico.length === 1 ? '' : 's'})
                                    </summary>
                                    <div className="p-3 pt-0 space-y-2">
                                        {contratosHistorico.map(c => (
                                            <ContratoCard key={c.id} contrato={c} />
                                        ))}
                                    </div>
                                </details>
                            )}
                        </div>
                    )}
                </section>

                {/* Bloco novo contrato */}
                <section className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-5">
                    <div className="flex items-center gap-2 mb-4">
                        <CheckCircle2 className="h-4 w-4 text-ecf-yellow" />
                        <h2 className="text-[14px] font-semibold text-white">
                            Novo contrato
                        </h2>
                    </div>

                    <form onSubmit={submit} className="space-y-4">
                        {/* Select de serviço */}
                        <div className="space-y-1.5">
                            <label className="block text-xs text-white/60 font-medium">
                                Serviço *
                            </label>
                            <select
                                value={form.servico_id}
                                onChange={e => escolherServico(e.target.value)}
                                required
                                className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none"
                            >
                                <option value="">Selecionar serviço...</option>
                                {servicos_disponiveis.map(s => (
                                    <option key={s.id} value={s.id}>
                                        {s.nome}
                                        {s.tipo_cobranca ? ` — ${s.tipo_cobranca === 'mensal' ? 'Mensal' : 'Única'}` : ''}
                                        {s.valor_padrao != null ? ` — ${formatCurrency(s.valor_padrao)}` : ''}
                                    </option>
                                ))}
                            </select>
                            {errors.servico_id && (
                                <p className="text-red-400 text-xs flex items-center gap-1">
                                    <AlertCircle className="h-3 w-3" /> {errors.servico_id}
                                </p>
                            )}
                        </div>

                        {/* Valor contratado com máscara BRL (D-07) */}
                        <div className="space-y-1.5">
                            <label className="block text-xs text-white/60 font-medium">
                                Valor contratado *
                            </label>
                            <IMaskInput
                                mask={Number}
                                scale={2}
                                thousandsSeparator="."
                                radix=","
                                mapToRadix={['.']}
                                padFractionalZeros
                                normalizeZeros
                                value={String(form.valor_contratado ?? '')}
                                onAccept={(_value, mask) =>
                                    setForm(prev => ({ ...prev, valor_contratado: mask.unmaskedValue }))
                                }
                                placeholder="R$ 0,00"
                                inputMode="decimal"
                                className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none font-mono"
                            />
                            {servicoSelecionado && servicoSelecionado.valor_padrao != null && (
                                <p className="text-[11px] text-white/40">
                                    Sugestão do catálogo: {formatCurrency(servicoSelecionado.valor_padrao)}
                                </p>
                            )}
                            {errors.valor_contratado && (
                                <p className="text-red-400 text-xs flex items-center gap-1">
                                    <AlertCircle className="h-3 w-3" /> {errors.valor_contratado}
                                </p>
                            )}
                        </div>

                        {/* Datas (lado a lado em desktop) */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <label className="block text-xs text-white/60 font-medium">
                                    Data de contratação *
                                </label>
                                <input
                                    type="date"
                                    value={form.data_contratacao}
                                    onChange={e => handleDataContratacaoChange(e.target.value)}
                                    required
                                    className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none"
                                />
                                {errors.data_contratacao && (
                                    <p className="text-red-400 text-xs flex items-center gap-1">
                                        <AlertCircle className="h-3 w-3" /> {errors.data_contratacao}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <label className="block text-xs text-white/60 font-medium">
                                    Data de vencimento
                                </label>
                                <input
                                    type="date"
                                    value={form.data_vencimento || ''}
                                    onChange={e => setForm(prev => ({ ...prev, data_vencimento: e.target.value }))}
                                    className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none"
                                />
                                <p className="text-[11px] text-white/40">
                                    Auto-preenchido (+1 ano da contratação). Edite se necessário.
                                </p>
                                {errors.data_vencimento && (
                                    <p className="text-red-400 text-xs flex items-center gap-1">
                                        <AlertCircle className="h-3 w-3" /> {errors.data_vencimento}
                                    </p>
                                )}
                            </div>
                        </div>

                        {/* Observações */}
                        <div className="space-y-1.5">
                            <label className="block text-xs text-white/60 font-medium">
                                Observações
                            </label>
                            <textarea
                                rows={3}
                                value={form.observacoes}
                                onChange={e => setForm(prev => ({ ...prev, observacoes: e.target.value }))}
                                placeholder="Notas internas, condições especiais, etc."
                                className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white resize-none focus:border-ecf-yellow/40 focus:outline-none placeholder:text-white/20"
                            />
                            {errors.observacoes && (
                                <p className="text-red-400 text-xs flex items-center gap-1">
                                    <AlertCircle className="h-3 w-3" /> {errors.observacoes}
                                </p>
                            )}
                        </div>

                        {/* Switch ativo (checkbox simples — sem Switch primitive) */}
                        <label className="flex items-center gap-2 text-[13px] text-white/80 cursor-pointer select-none">
                            <input
                                type="checkbox"
                                checked={!!form.ativo}
                                onChange={e => setForm(prev => ({ ...prev, ativo: e.target.checked }))}
                                className="h-4 w-4 accent-ecf-yellow"
                            />
                            Contrato ativo
                        </label>

                        {/* Botões */}
                        <div className="flex items-center justify-end gap-2 pt-3 border-t border-white/[0.05]">
                            <button
                                type="button"
                                onClick={cancelar}
                                className="h-9 px-4 rounded-lg border border-white/[0.08] text-white/60 hover:text-white/90 text-[13px] inline-flex items-center gap-1.5"
                            >
                                <X className="h-3.5 w-3.5" /> Cancelar
                            </button>
                            <button
                                type="submit"
                                disabled={salvando}
                                className="h-9 px-4 rounded-lg bg-ecf-yellow text-black font-semibold text-[13px] disabled:opacity-50 inline-flex items-center gap-1.5"
                            >
                                <Save className="h-3.5 w-3.5" />
                                {salvando ? 'Salvando...' : 'Salvar contrato'}
                            </button>
                        </div>
                    </form>
                </section>
            </main>
        </AppLayout>
    );
}
