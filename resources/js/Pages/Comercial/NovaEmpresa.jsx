// Página /comercial/empresas/novo — cadastro de nova empresa pelo setor Comercial.
//
// Recebe `servicos_disponiveis` do ComercialController::create() — lista do
// catálogo de serviços ativos (Frente A). Também recebe flash.success via
// HandleInertiaRequests após POST bem-sucedido.
//
// WIZARD DE 2 PASSOS (Quick 260612-jyx): o cadastro foi dividido em dois passos
// numa única página (estado interno `step`, mesma rota — sem rota nova):
//   - Passo 1 "Dados da empresa": nome*, cnpj, email_cliente, telefone, notes.
//   - Passo 2 "Serviços + onboarding": multi-select do catálogo (cards) + bloco
//     condicional de onboarding Polos.
//
// Form via useForm do Inertia:
//   - nome:              obrigatório (texto livre)
//   - cnpj:              opcional (texto livre — sem máscara automática)
//   - email_cliente:     opcional — destinatário do NPS mensal (Quick 260611-eml)
//   - telefone:          opcional — contato comercial (Quick 260611-eml)
//   - notes:             opcional (observações)
//   - servicos:          array de { servico_id, valor_contratado } — pelo menos 1 obrigatório
//   - polo:              opcional — só enviado/visível quando Polos selecionado
//   - nome_contato:      opcional — nome da pessoa de contato (não o nome da loja)
//   - gmail_colaborador: opcional — gmail para onboarding
//   - grupo_whatsapp:    opcional — checkbox se o grupo WA já foi criado
//
// Submit chama POST comercial.empresas.store — backend cria atomicamente:
//   - Polos:       Company + MlbEmpresa (tipo=POLO, polo, fase=M0) + MlbImplementacao (com handoff)
//   - Assessoria:  Company + MlbEmpresa (tipo=ASSESSORIA)
//   - Incubadora:  Company + MlbEmpresa (tipo=INCUBADORA)
//   - Publicidade/Gestão/Publicação: apenas Company (sem mlb_empresas)
// E notifica líderes do setor de destino.
import { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { IMaskInput } from 'react-imask';
import {
    ArrowLeft, ArrowRight, Building2, Check, CheckCircle2, FileText,
    Mail, MapPin, MessageCircle, Phone, PlusCircle, ShoppingBag,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn, formatCurrency } from '@/lib/utils';

// Regiões de atuação dos Polos — espelham MlbImplementacao::ONB_POLO_OPCOES.
const POLO_REGIOES = ['Arapongas', 'S. J. Rio Preto', 'Bento Gonçalves', 'São Bento do Sul'];

// Campos que pertencem ao passo 1. Usado para, em caso de erro do servidor
// (ex: nome duplicado, cnpj/email inválidos), voltar ao passo 1 e exibir o erro.
const STEP1_FIELDS = ['nome', 'cnpj', 'email_cliente', 'telefone', 'notes'];

// ─── Phase 34 Plan 02 — campos do close comercial ───────────────────────────
// Marketplaces extras (além do ML) — D-09 fixa as 5 opções relevantes hoje
// para evitar texto livre que vira poluição de dados (Shein/Carrefour fora
// porque presença mínima na carteira). Backend valida o slug via Rule::in.
const MARKETPLACES_EXTRAS = [
    { value: 'shopee', label: 'Shopee' },
    { value: 'amazon', label: 'Amazon' },
    { value: 'magalu', label: 'Magalu' },
    { value: 'temu',   label: 'Temu' },
    { value: 'tiktok', label: 'TikTok Shop' },
];

// vende_ml é tinyint nullable (null = "não sei", 1 = sim, 0 = não). No form
// trabalhamos com strings ('', 'true', 'false') e convertemos no submit via
// transform() do useForm — useForm não aceita null como valor inicial coerente
// para selects controlados.
const VENDE_ML_OPCOES = [
    { value: '',      label: 'Não sei' },
    { value: 'true',  label: 'Sim' },
    { value: 'false', label: 'Não' },
];

const STEPS = [
    { n: 1, titulo: 'Dados da empresa',     desc: 'Identificação e contato do cliente.' },
    { n: 2, titulo: 'Serviços & onboarding', desc: 'Serviços contratados e dados de entrada.' },
];

// Phase 35 Plan 35-01 (D-07) — Reusa o helper canonico do projeto pra garantir
// 2 casas decimais consistentes em toda a UI (Companies/Show, Comercial/Empresas).
const fmtBRL = (n) => formatCurrency(n);

export default function NovaEmpresa({ servicos_disponiveis = [] }) {
    const { flash } = usePage().props;

    // Passo atual do wizard (1 = dados da empresa, 2 = serviços + onboarding).
    const [step, setStep] = useState(1);

    const { data, setData, post, processing, errors, reset, transform } = useForm({
        nome:              '',
        cnpj:              '',
        notes:             '',
        // Quick 260611-eml — contato do cliente (NPS mensal + comercial).
        email_cliente:     '',
        telefone:          '',
        servicos:          [], // array de { servico_id, valor_contratado }
        // Phase 34 Plan 02 — campos do "close" comercial (todos opcionais).
        // Capturados pelo Comercial no fechamento e usados pelo estrategista/analista
        // depois. vende_ml usa 3 estados '/true/false → null/true/false no submit.
        nicho:               '',
        dor:                 '',
        vende_ml:            '',
        faturamento_mensal:  '',
        marketplaces_extras: [],
        email_colaborador:   '',
        // Campos de handoff Polos (enviados sempre; backend usa só quando tipo=Polos)
        polo:              '',
        nome_contato:      '',
        gmail_colaborador: '',
        grupo_whatsapp:    false,
    });

    // Phase 34 Plan 02 — converte vende_ml string ('' / 'true' / 'false') para
    // null/true/false antes de mandar no POST. faturamento_mensal vai como string
    // do <input type=number> e o backend faz cast via validation `numeric`.
    transform((d) => ({
        ...d,
        vende_ml: d.vende_ml === '' ? null : d.vende_ml === 'true',
    }));

    // ─── Helpers de manipulação do array servicos[] ──────────────────────────

    function toggleServico(servicoId) {
        const cur = data.servicos;
        const existe = cur.find(s => s.servico_id === servicoId);
        if (existe) {
            setData('servicos', cur.filter(s => s.servico_id !== servicoId));
        } else {
            const cat = servicos_disponiveis.find(s => s.id === servicoId);
            setData('servicos', [...cur, {
                servico_id:       servicoId,
                valor_contratado: cat?.valor_padrao ?? 0,
            }]);
        }
    }

    function updateValor(servicoId, valor) {
        setData('servicos', data.servicos.map(s =>
            s.servico_id === servicoId ? { ...s, valor_contratado: valor } : s
        ));
    }

    // Phase 34 Plan 02 — toggle de checkbox para o array marketplaces_extras.
    function handleToggleMarketplace(val) {
        const cur = data.marketplaces_extras || [];
        setData(
            'marketplaces_extras',
            cur.includes(val) ? cur.filter(x => x !== val) : [...cur, val],
        );
    }

    // Detecta se o serviço Polos está entre os selecionados — controla visibilidade
    // do bloco de handoff. Cruza data.servicos com servicos_disponiveis pelo nome.
    const poloSelecionado = data.servicos.some(s => {
        const cat = servicos_disponiveis.find(c => c.id === s.servico_id);
        return cat?.nome?.includes('Polos');
    });

    // Total mensal estimado dos serviços selecionados — exibido no resumo.
    const totalServicos = data.servicos.reduce((acc, s) => acc + Number(s.valor_contratado || 0), 0);

    // Validações por passo — espelham o disabled dos botões.
    const passo1Valido = !!data.nome.trim();
    const passo2Valido = data.servicos.length > 0 && (!poloSelecionado || (!!data.nome_contato && !!data.polo));

    function irPara(n) {
        // Só permite ir ao passo 2 com o passo 1 válido.
        if (n === 2 && !passo1Valido) return;
        setStep(n);
    }

    // onSubmit único do <form>: no passo 1 apenas avança; no passo 2 envia.
    // Tratar via submit (e não só no clique) faz o Enter no input funcionar
    // de forma previsível em ambos os passos.
    function handleFormSubmit(e) {
        e.preventDefault();

        if (step === 1) {
            if (passo1Valido) setStep(2);
            return;
        }

        post(route('comercial.empresas.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setStep(1);
            },
            onError: (errs) => {
                // Erro num campo do passo 1 (ex: nome duplicado) → volta pra exibir.
                if (Object.keys(errs).some(k => STEP1_FIELDS.includes(k))) {
                    setStep(1);
                }
            },
        });
    }

    const stepAtual = STEPS[step - 1];

    return (
        <AppLayout title="Cadastro de Empresa">
            <Head title="Novo Cadastro de Empresa" />

            <div className="max-w-2xl mx-auto space-y-7">
                {/* Cabeçalho */}
                <div className="flex items-center gap-3.5">
                    <div className="flex items-center justify-center w-11 h-11 rounded-2xl bg-yellow-grad shadow-[0_0_24px_-6px_rgba(255,230,0,0.5)] shrink-0">
                        <Building2 size={20} className="text-black" />
                    </div>
                    <div>
                        <h1 className="text-white font-display font-bold text-xl leading-tight tracking-tight">Cadastrar Nova Empresa</h1>
                        <p className="text-white/40 text-[13px]">Cadastro centralizado do Comercial — o setor responsável completa o restante.</p>
                    </div>
                </div>

                {/* Feedback de sucesso */}
                {flash?.success && (
                    <div className="flex items-center gap-2.5 rounded-xl border border-green-500/20 bg-green-950/40 px-4 py-3 text-green-300 text-sm animate-fade-in">
                        <CheckCircle2 size={16} className="shrink-0" />
                        {flash.success}
                    </div>
                )}

                {/* Stepper */}
                <Stepper step={step} onJump={irPara} podeAvancar={passo1Valido} />

                {/* Card do formulário */}
                <div className="rounded-2xl border border-white/[0.08] bg-ecf-card overflow-hidden">
                    {/* Faixa de título do passo */}
                    <div className="px-6 sm:px-7 pt-5 pb-4 border-b border-white/[0.06] bg-white/[0.015]">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <p className="text-ecf-yellow/80 text-[11px] font-semibold uppercase tracking-wider">Passo {step} de 2</p>
                                <h2 className="text-white font-display font-semibold text-[15px] mt-0.5">{stepAtual.titulo}</h2>
                            </div>
                            <p className="text-white/35 text-[12px] text-right max-w-[55%] hidden sm:block">{stepAtual.desc}</p>
                        </div>
                    </div>

                    <form onSubmit={handleFormSubmit}>
                        {/* Conteúdo do passo — key={step} faz a animação rodar a cada troca */}
                        <div key={step} className="px-6 sm:px-7 py-6 space-y-5 animate-fade-in">

                            {/* ───────────────────────── PASSO 1 ───────────────────────── */}
                            {step === 1 && (
                                <>
                                    <Field label="Nome da Empresa" required error={errors.nome} icon={Building2}>
                                        <input
                                            type="text"
                                            value={data.nome}
                                            onChange={e => setData('nome', e.target.value)}
                                            placeholder="Ex: Empresa XYZ Ltda"
                                            className={inputCls(errors.nome, true)}
                                            autoFocus
                                        />
                                    </Field>

                                    <Field label="CNPJ" hint="opcional" error={errors.cnpj} icon={FileText}>
                                        {/* Phase 34 Plan 02 — máscara CNPJ via react-imask (D-08). */}
                                        <IMaskInput
                                            mask="00.000.000/0000-00"
                                            value={data.cnpj}
                                            onAccept={(v) => setData('cnpj', v)}
                                            placeholder="00.000.000/0001-00"
                                            className={inputCls(errors.cnpj, true)}
                                        />
                                    </Field>

                                    {/* Email + Telefone lado a lado em telas médias */}
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                        <Field
                                            label="Email do cliente"
                                            hint="opcional"
                                            error={errors.email_cliente}
                                            icon={Mail}
                                            note="Destinatário do NPS mensal."
                                        >
                                            <input
                                                type="email"
                                                value={data.email_cliente}
                                                onChange={e => setData('email_cliente', e.target.value)}
                                                placeholder="cliente@empresa.com.br"
                                                className={inputCls(errors.email_cliente, true)}
                                            />
                                        </Field>

                                        <Field label="Telefone" hint="opcional" error={errors.telefone} icon={Phone}>
                                            {/* Phase 34 Plan 02 — máscara dinâmica 10/11 dígitos (D-08).
                                                react-imask alterna entre os dois formatos conforme o usuário digita. */}
                                            <IMaskInput
                                                mask={[
                                                    { mask: '(00) 0000-0000' },
                                                    { mask: '(00) 00000-0000' },
                                                ]}
                                                value={data.telefone}
                                                onAccept={(v) => setData('telefone', v)}
                                                placeholder="(11) 99999-9999"
                                                className={inputCls(errors.telefone, true)}
                                            />
                                        </Field>
                                    </div>

                                    <Field label="Observações" hint="opcional" error={errors.notes}>
                                        <textarea
                                            value={data.notes}
                                            onChange={e => setData('notes', e.target.value)}
                                            rows={3}
                                            placeholder="Notas internas sobre a empresa..."
                                            className={cn(inputCls(errors.notes, false), 'resize-y leading-relaxed')}
                                        />
                                    </Field>
                                </>
                            )}

                            {/* ───────────────────────── PASSO 2 ───────────────────────── */}
                            {step === 2 && (
                                <>
                                    {/* Catálogo de serviços — cards selecionáveis */}
                                    <div className="space-y-2.5">
                                        <div className="flex items-center justify-between">
                                            <label className="block text-xs text-white/60 font-medium">
                                                Serviços contratados <span className="text-ecf-yellow">*</span>
                                            </label>
                                            {data.servicos.length > 0 && (
                                                <span className="text-[11px] text-white/40">
                                                    {data.servicos.length} selecionado(s) · <span className="text-ecf-yellow/80 font-semibold">{fmtBRL(totalServicos)}</span>
                                                </span>
                                            )}
                                        </div>

                                        {servicos_disponiveis.length === 0 ? (
                                            <div className="rounded-xl border border-white/[0.08] bg-ecf-bg px-4 py-6 text-center">
                                                <ShoppingBag size={20} className="text-white/20 mx-auto mb-2" />
                                                <p className="text-white/40 text-xs">
                                                    Nenhum serviço no catálogo. Acesse <span className="text-ecf-yellow/70">/servicos</span> para adicionar.
                                                </p>
                                            </div>
                                        ) : (
                                            <div className="grid grid-cols-1 gap-2">
                                                {servicos_disponiveis.map(servico => (
                                                    <ServiceCard
                                                        key={servico.id}
                                                        servico={servico}
                                                        selecionado={data.servicos.find(s => s.servico_id === servico.id)}
                                                        onToggle={() => toggleServico(servico.id)}
                                                        onValor={(v) => updateValor(servico.id, v)}
                                                    />
                                                ))}
                                            </div>
                                        )}

                                        {errors.servicos && (
                                            <p className="text-red-400 text-xs">{errors.servicos}</p>
                                        )}
                                    </div>

                                    {/* Bloco de onboarding Polos — revelação animada (ONB-07) */}
                                    {poloSelecionado && (
                                        <div className="rounded-xl border border-ecf-yellow/25 bg-ecf-yellow/[0.04] overflow-hidden animate-in fade-in slide-in-from-top-2 duration-300">
                                            <div className="flex items-center gap-2 px-4 py-3 border-b border-ecf-yellow/15 bg-ecf-yellow/[0.04]">
                                                <MapPin size={14} className="text-ecf-yellow" />
                                                <p className="text-xs text-ecf-yellow font-semibold tracking-wide">Onboarding — Polos</p>
                                            </div>

                                            <div className="p-4 space-y-4">
                                                <Field label="Nome do cliente (contato)" required error={errors.nome_contato}>
                                                    <input
                                                        type="text"
                                                        value={data.nome_contato}
                                                        onChange={e => setData('nome_contato', e.target.value)}
                                                        placeholder="Pessoa responsável pela empresa"
                                                        className={inputCls(errors.nome_contato, false)}
                                                    />
                                                </Field>

                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                    <Field label="Gmail colaborador" error={errors.gmail_colaborador} icon={Mail}>
                                                        <input
                                                            type="email"
                                                            value={data.gmail_colaborador}
                                                            onChange={e => setData('gmail_colaborador', e.target.value)}
                                                            placeholder="exemplo@gmail.com"
                                                            className={inputCls(errors.gmail_colaborador, true)}
                                                        />
                                                    </Field>

                                                    <Field label="Região" required error={errors.polo} icon={MapPin}>
                                                        <select
                                                            value={data.polo}
                                                            onChange={e => setData('polo', e.target.value)}
                                                            className={cn(inputCls(errors.polo, true), 'cursor-pointer')}
                                                        >
                                                            <option value="">Selecionar região...</option>
                                                            {POLO_REGIOES.map(p => (
                                                                <option key={p} value={p}>{p}</option>
                                                            ))}
                                                        </select>
                                                    </Field>
                                                </div>

                                                {/* Grupo WhatsApp criado? — toggle estilizado */}
                                                <label className="flex items-center justify-between gap-3 rounded-lg border border-white/[0.08] bg-ecf-bg/60 px-3.5 py-3 cursor-pointer hover:border-white/[0.14] transition-colors">
                                                    <span className="flex items-center gap-2.5">
                                                        <MessageCircle size={15} className="text-green-400/80" />
                                                        <span className="text-white/80 text-sm">Grupo WhatsApp já criado?</span>
                                                    </span>
                                                    <input
                                                        type="checkbox"
                                                        checked={!!data.grupo_whatsapp}
                                                        onChange={e => setData('grupo_whatsapp', e.target.checked)}
                                                        className="h-4 w-4 accent-ecf-yellow shrink-0"
                                                    />
                                                </label>
                                            </div>
                                        </div>
                                    )}

                                    {/* ─── Phase 34 Plan 02 — Informações do close (opcional) ─── */}
                                    {/* Bloco capturado pelo Comercial NO fechamento do contrato. Todos opcionais —
                                        servem para que estrategista/analista entendam a empresa sem reentrevistar
                                        o cliente. Mantido como bloco neutro (sem destaque amarelo) pra deixar claro
                                        que NÃO é onboarding (diferente do bloco Polos acima). */}
                                    <div className="space-y-4 rounded-xl border border-white/[0.06] bg-white/[0.015] p-4">
                                        <div>
                                            <h3 className="text-white font-semibold text-sm flex items-center gap-2">
                                                Informações do close
                                                <span className="text-white/40 text-[11px] font-normal">(opcional)</span>
                                            </h3>
                                            <p className="text-white/45 text-[11px] mt-0.5 leading-snug">
                                                Capturadas pelo Comercial no fechamento — ajudam o estrategista/analista a entender a empresa.
                                            </p>
                                        </div>

                                        <Field label="Nicho" error={errors.nicho}>
                                            <input
                                                type="text"
                                                value={data.nicho}
                                                onChange={e => setData('nicho', e.target.value)}
                                                placeholder="Ex: Moda feminina, Auto peças"
                                                className={inputCls(errors.nicho, false)}
                                            />
                                        </Field>

                                        <Field label="Dor principal" error={errors.dor}>
                                            <textarea
                                                value={data.dor}
                                                onChange={e => setData('dor', e.target.value)}
                                                rows={3}
                                                placeholder="Principal dificuldade do cliente — capturada no close"
                                                className={cn(inputCls(errors.dor, false), 'resize-y leading-relaxed')}
                                            />
                                        </Field>

                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <Field label="Vende no Mercado Livre?" error={errors.vende_ml}>
                                                <select
                                                    value={data.vende_ml}
                                                    onChange={e => setData('vende_ml', e.target.value)}
                                                    className={cn(inputCls(errors.vende_ml, false), 'cursor-pointer')}
                                                >
                                                    {VENDE_ML_OPCOES.map(o => (
                                                        <option key={o.value} value={o.value}>{o.label}</option>
                                                    ))}
                                                </select>
                                            </Field>

                                            <Field label="Faturamento mensal (R$)" error={errors.faturamento_mensal}>
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    value={data.faturamento_mensal}
                                                    onChange={e => setData('faturamento_mensal', e.target.value)}
                                                    placeholder="0,00"
                                                    className={inputCls(errors.faturamento_mensal, false)}
                                                />
                                            </Field>
                                        </div>

                                        {/* Marketplaces extras — 5 checkboxes em grid responsivo (D-09 fixa as opções).
                                            Phase 35 Plan 35-01 (D-08) — label reformulado pra deixar claro que sao
                                            marketplaces que o CLIENTE ja opera (nao servicos da ECF). */}
                                        <div className="space-y-2">
                                            <label className="block text-xs text-white/60 font-medium">
                                                Em quais outros marketplaces o cliente já vende?
                                            </label>
                                            <p className="text-[11px] text-white/40 -mt-1">
                                                Marketplaces que o cliente já opera por conta própria. Não confundir com serviços que vamos prestar.
                                            </p>
                                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                                {MARKETPLACES_EXTRAS.map(mp => {
                                                    const selecionado = data.marketplaces_extras.includes(mp.value);
                                                    return (
                                                        <label
                                                            key={mp.value}
                                                            className={cn(
                                                                'flex items-center gap-2 cursor-pointer rounded-lg border px-3 py-2 text-sm transition-colors',
                                                                selecionado
                                                                    ? 'border-ecf-yellow/50 bg-ecf-yellow/[0.08] text-white'
                                                                    : 'border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.05]',
                                                            )}
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                checked={selecionado}
                                                                onChange={() => handleToggleMarketplace(mp.value)}
                                                                className="h-3.5 w-3.5 accent-ecf-yellow shrink-0"
                                                            />
                                                            <span>{mp.label}</span>
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                            {errors.marketplaces_extras && (
                                                <p className="text-red-400 text-xs">{errors.marketplaces_extras}</p>
                                            )}
                                        </div>

                                        {/* Email colaborador ECF — DIFERENTE de email_cliente (NPS).
                                            Este é o gmail/google workspace criado pela ECF para acessar o ML do cliente. */}
                                        <Field
                                            label="Email colaborador ECF"
                                            error={errors.email_colaborador}
                                            icon={Mail}
                                            note="Conta criada pela ECF para acesso ao ML do cliente. Diferente do email do cliente (NPS)."
                                        >
                                            <input
                                                type="email"
                                                value={data.email_colaborador}
                                                onChange={e => setData('email_colaborador', e.target.value)}
                                                placeholder="colab@ecfconsultoria.com.br"
                                                className={inputCls(errors.email_colaborador, true)}
                                            />
                                        </Field>
                                    </div>

                                    <p className="text-white/30 text-[11px] leading-snug flex items-start gap-1.5">
                                        <CheckCircle2 size={13} className="text-white/25 mt-px shrink-0" />
                                        Após o cadastro, os líderes do setor de destino serão notificados automaticamente.
                                    </p>
                                </>
                            )}
                        </div>

                        {/* Rodapé de navegação */}
                        <div className="flex items-center justify-between gap-3 px-6 sm:px-7 py-4 border-t border-white/[0.06] bg-white/[0.015]">
                            {step === 2 ? (
                                <button
                                    type="button"
                                    onClick={() => setStep(1)}
                                    className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium text-white/70 border border-white/[0.08] hover:bg-white/[0.05] hover:text-white transition-colors"
                                >
                                    <ArrowLeft size={15} />
                                    Voltar
                                </button>
                            ) : (
                                <span className="text-white/25 text-[11px]">
                                    <span className="text-ecf-yellow/60">*</span> campos obrigatórios
                                </span>
                            )}

                            {step === 1 ? (
                                <button
                                    type="submit"
                                    disabled={!passo1Valido}
                                    className={cn(
                                        'inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-bold transition-all',
                                        'bg-yellow-grad text-black hover:brightness-105 disabled:opacity-40 disabled:cursor-not-allowed'
                                    )}
                                >
                                    Próximo
                                    <ArrowRight size={15} />
                                </button>
                            ) : (
                                <button
                                    type="submit"
                                    disabled={processing || !passo2Valido}
                                    className={cn(
                                        'inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-bold transition-all',
                                        'bg-yellow-grad text-black hover:brightness-105 disabled:opacity-40 disabled:cursor-not-allowed'
                                    )}
                                >
                                    <PlusCircle size={15} />
                                    {processing ? 'Cadastrando...' : 'Cadastrar Empresa'}
                                </button>
                            )}
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}

// ─── Estilos / subcomponentes locais ────────────────────────────────────────

// Classe base dos inputs/selects. `comIcone` adiciona padding à esquerda para
// o ícone posicionado em absolute pelo <Field>.
function inputCls(erro, comIcone) {
    return cn(
        'w-full bg-white/[0.04] border rounded-lg py-2.5 text-white text-sm transition-colors',
        'placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/50 focus:bg-white/[0.06]',
        comIcone ? 'pl-9 pr-3' : 'px-3',
        erro ? 'border-red-500/50' : 'border-white/[0.08]'
    );
}

// Campo padronizado: label (+ obrigatório/opcional), ícone opcional, slot do
// input, nota auxiliar e mensagem de erro.
function Field({ label, required, hint, error, icon: Icon, note, children }) {
    return (
        <div className="space-y-1.5">
            <label className="block text-xs text-white/60 font-medium">
                {label}
                {required && <span className="text-ecf-yellow"> *</span>}
                {hint && <span className="text-white/30 text-[11px] font-normal"> ({hint})</span>}
            </label>
            <div className="relative">
                {Icon && (
                    <Icon size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
                )}
                {children}
            </div>
            {note && !error && <p className="text-white/30 text-[11px]">{note}</p>}
            {error && <p className="text-red-400 text-xs">{error}</p>}
        </div>
    );
}

// Card selecionável de serviço. Quando marcado, revela o input de valor.
function ServiceCard({ servico, selecionado, onToggle, onValor }) {
    const checked = !!selecionado;
    return (
        <div
            onClick={onToggle}
            className={cn(
                'group flex items-center gap-3 rounded-xl px-3.5 py-3 cursor-pointer transition-all',
                checked
                    ? 'bg-ecf-yellow/[0.07] border border-ecf-yellow/40 shadow-[0_0_0_1px_rgba(255,230,0,0.06)]'
                    : 'bg-white/[0.025] border border-white/[0.07] hover:border-white/[0.16] hover:bg-white/[0.04]',
            )}
        >
            {/* Indicador de seleção */}
            <span className={cn(
                'flex items-center justify-center w-5 h-5 rounded-md border shrink-0 transition-all',
                checked ? 'bg-ecf-yellow border-ecf-yellow' : 'border-white/20 group-hover:border-white/35'
            )}>
                {checked && <Check size={13} className="text-black" strokeWidth={3} />}
            </span>

            {/* Nome + tipo de cobrança */}
            <div className="flex-1 min-w-0">
                <p className={cn('text-[13px] font-medium truncate', checked ? 'text-white' : 'text-white/75')}>
                    {servico.nome}
                </p>
                <span className="text-white/30 text-[10px] uppercase tracking-wide">{servico.tipo_cobranca}</span>
            </div>

            {/* Valor contratado — só quando selecionado; clique não propaga p/ o card */}
            {checked && (
                <div
                    className="flex items-center gap-1 shrink-0"
                    onClick={(e) => e.stopPropagation()}
                >
                    <span className="text-white/30 text-[11px]">R$</span>
                    <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={selecionado.valor_contratado}
                        onChange={e => onValor(e.target.value)}
                        placeholder="0,00"
                        className="w-24 px-2 py-1.5 rounded-lg bg-ecf-bg border border-white/[0.1] text-white text-[12px] text-right focus:outline-none focus:border-ecf-yellow/50"
                    />
                </div>
            )}
        </div>
    );
}

// Indicador de passos — dois nós conectados por uma trilha que se preenche.
function Stepper({ step, onJump, podeAvancar }) {
    return (
        <div className="flex items-start">
            {STEPS.map((s, i) => {
                const ativo     = step === s.n;
                const concluido = step > s.n;
                const clicavel  = s.n === 1 || podeAvancar;
                return (
                    <div key={s.n} className="flex items-start flex-1 last:flex-none">
                        {/* Nó */}
                        <button
                            type="button"
                            onClick={() => clicavel && onJump(s.n)}
                            disabled={!clicavel}
                            className={cn('flex flex-col items-center gap-2 shrink-0 w-32 text-center', clicavel ? 'cursor-pointer' : 'cursor-not-allowed')}
                        >
                            <span className={cn(
                                'flex items-center justify-center w-9 h-9 rounded-full text-[13px] font-bold transition-all duration-300',
                                ativo
                                    ? 'bg-yellow-grad text-black shadow-[0_0_18px_-4px_rgba(255,230,0,0.6)]'
                                    : concluido
                                        ? 'bg-ecf-yellow/15 text-ecf-yellow border border-ecf-yellow/40'
                                        : 'bg-white/[0.04] text-white/40 border border-white/[0.1]'
                            )}>
                                {concluido ? <Check size={16} strokeWidth={3} /> : s.n}
                            </span>
                            <span className={cn(
                                'text-[12px] font-medium leading-tight transition-colors',
                                ativo ? 'text-white' : concluido ? 'text-white/60' : 'text-white/35'
                            )}>
                                {s.titulo}
                            </span>
                        </button>

                        {/* Conector (entre nós) */}
                        {i < STEPS.length - 1 && (
                            <div className="flex-1 h-0.5 mt-[18px] mx-1 rounded-full bg-white/[0.08] relative overflow-hidden">
                                <div
                                    className="absolute inset-y-0 left-0 bg-ecf-yellow rounded-full transition-all duration-500 ease-out"
                                    style={{ width: step > s.n ? '100%' : '0%' }}
                                />
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
