// Página /comercial/empresas/novo — cadastro de nova empresa pelo setor Comercial.
//
// Recebe flash.success do ComercialController::store() via shared props do HandleInertiaRequests.
// Sem props adicionais do controller além do flash.
//
// Form via useForm do Inertia:
//   - nome:         obrigatório (texto livre)
//   - cnpj:         opcional (texto livre — sem máscara automática)
//   - service_type: obrigatório (polos | assessoria | publicidade | gestao)
//
// Submit chama POST comercial.empresas.store — backend cria atomicamente:
//   - polos:       Company + MlbEmpresa + MlbImplementacao
//   - assessoria:  Company + MlbEmpresa
//   - publicidade: Company
//   - gestao:      Company
// E notifica líderes do setor de destino.
import { Head, useForm, usePage } from '@inertiajs/react';
import { Building2, PlusCircle } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';

const TIPOS = [
    { value: 'polos',       label: 'Publicação — POLOS',
      hint: 'Cria empresa + registro MLB (POLOS) + implementação automaticamente.' },
    { value: 'assessoria',  label: 'Publicação — Assessoria',
      hint: 'Cria empresa + registro MLB (Assessoria). Implementação não é criada automaticamente.' },
    { value: 'publicidade', label: 'Publicidade',
      hint: 'Cria empresa no módulo de Publicidade. Dados de conta Adman são preenchidos depois.' },
    { value: 'gestao',      label: 'Gestão',
      hint: 'Cria empresa no módulo de Gestão. Consultor/estrategista atribuídos depois.' },
];

export default function NovaEmpresa() {
    const { flash } = usePage().props;

    const { data, setData, post, processing, errors, reset } = useForm({
        nome:         '',
        cnpj:         '',
        service_type: [],
    });

    function toggleTipo(val) {
        const cur = data.service_type;
        // polos e assessoria são mutuamente exclusivos (ambos criam mlb_empresa)
        if (val === 'polos'      && cur.includes('assessoria')) return;
        if (val === 'assessoria' && cur.includes('polos'))      return;
        setData('service_type', cur.includes(val) ? cur.filter(t => t !== val) : [...cur, val]);
    }

    function handleSubmit(e) {
        e.preventDefault();
        post(route('comercial.empresas.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    }

    return (
        <AppLayout title="Cadastro de Empresa">
            <Head title="Novo Cadastro de Empresa" />

            <div className="max-w-xl space-y-6">
                {/* Cabeçalho */}
                <div className="flex items-center gap-3">
                    <div className="flex items-center justify-center w-10 h-10 rounded-xl bg-ecf-yellow/10 border border-ecf-yellow/20 shrink-0">
                        <Building2 size={18} className="text-ecf-yellow" />
                    </div>
                    <div>
                        <h1 className="text-white font-bold text-lg leading-tight">Cadastrar Nova Empresa</h1>
                        <p className="text-white/40 text-[13px]">Preencha os dados básicos — o setor responsável completará as informações.</p>
                    </div>
                </div>

                {/* Feedback de sucesso */}
                {flash?.success && (
                    <div className="rounded-xl border border-green-500/20 bg-green-950/40 px-4 py-3 text-green-300 text-sm">
                        {flash.success}
                    </div>
                )}

                {/* Formulário */}
                <div className="rounded-2xl border border-white/[0.08] bg-ecf-card p-6 space-y-5">
                    <form onSubmit={handleSubmit} className="space-y-5">

                        {/* Nome da Empresa */}
                        <div className="space-y-1.5">
                            <label className="block text-xs text-white/60 font-medium">
                                Nome da Empresa <span className="text-ecf-yellow">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.nome}
                                onChange={e => setData('nome', e.target.value)}
                                placeholder="Ex: Empresa XYZ Ltda"
                                className={cn(
                                    'w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm',
                                    'placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors',
                                    errors.nome ? 'border-red-500/50' : 'border-white/[0.08]'
                                )}
                                required
                            />
                            {errors.nome && (
                                <p className="text-red-400 text-xs mt-1">{errors.nome}</p>
                            )}
                        </div>

                        {/* CNPJ */}
                        <div className="space-y-1.5">
                            <label className="block text-xs text-white/60 font-medium">
                                CNPJ <span className="text-white/30 text-[11px] font-normal">(opcional)</span>
                            </label>
                            <input
                                type="text"
                                value={data.cnpj}
                                onChange={e => setData('cnpj', e.target.value)}
                                placeholder="00.000.000/0001-00"
                                className={cn(
                                    'w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm',
                                    'placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors',
                                    errors.cnpj ? 'border-red-500/50' : 'border-white/[0.08]'
                                )}
                            />
                            {errors.cnpj && (
                                <p className="text-red-400 text-xs mt-1">{errors.cnpj}</p>
                            )}
                        </div>

                        {/* Tipo de Serviço */}
                        <div className="space-y-1.5">
                            <label className="block text-xs text-white/60 font-medium">
                                Tipo de Serviço <span className="text-ecf-yellow">*</span>
                            </label>
                            <div className={cn(
                                'rounded-lg border p-3 grid grid-cols-2 gap-2',
                                errors.service_type ? 'border-red-500/50' : 'border-white/[0.08]'
                            )}>
                                {TIPOS.map(tipo => {
                                    const checked    = data.service_type.includes(tipo.value);
                                    const bloqueado  = (tipo.value === 'polos'      && data.service_type.includes('assessoria')) ||
                                                       (tipo.value === 'assessoria' && data.service_type.includes('polos'));
                                    return (
                                        <label
                                            key={tipo.value}
                                            className={cn(
                                                'flex items-center gap-2 rounded-lg px-3 py-2 cursor-pointer transition-colors',
                                                checked    ? 'bg-ecf-yellow/10 border border-ecf-yellow/30' : 'bg-white/[0.03] border border-white/[0.06]',
                                                bloqueado  && 'opacity-40 cursor-not-allowed'
                                            )}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                disabled={bloqueado}
                                                onChange={() => toggleTipo(tipo.value)}
                                                className="accent-ecf-yellow w-3.5 h-3.5"
                                            />
                                            <span className={cn('text-[12px] font-medium', checked ? 'text-ecf-yellow' : 'text-white/60')}>
                                                {tipo.label}
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                            {errors.service_type && (
                                <p className="text-red-400 text-xs mt-1">{errors.service_type}</p>
                            )}
                            {/* Dica contextual para cada tipo selecionado */}
                            {TIPOS.filter(t => data.service_type.includes(t.value)).map(t => (
                                <p key={t.value} className="text-white/30 text-[11px] leading-snug">
                                    <span className="text-white/50 font-medium">{t.label}:</span> {t.hint}
                                </p>
                            ))}
                            {data.service_type.includes('polos') && data.service_type.includes('assessoria') && (
                                <p className="text-red-400 text-[11px]">POLOS e Assessoria não podem ser combinados.</p>
                            )}
                        </div>

                        {/* Botão de submit */}
                        <div className="pt-1">
                            <button
                                type="submit"
                                disabled={processing || data.service_type.length === 0}
                                className={cn(
                                    'inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-bold transition-all',
                                    'bg-ecf-yellow text-black hover:bg-yellow-300 disabled:opacity-50 disabled:cursor-not-allowed'
                                )}
                            >
                                <PlusCircle size={15} />
                                {processing ? 'Cadastrando...' : 'Cadastrar Empresa'}
                            </button>
                        </div>
                    </form>
                </div>

                {/* Nota informativa */}
                <p className="text-white/25 text-[12px] leading-relaxed">
                    Após o cadastro, o setor responsável será notificado e verá a empresa como pendente
                    em seu painel até completar os dados necessários.
                </p>
            </div>
        </AppLayout>
    );
}
