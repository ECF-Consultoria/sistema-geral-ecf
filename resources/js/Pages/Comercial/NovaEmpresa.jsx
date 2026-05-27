// Página /comercial/empresas/novo — cadastro de nova empresa pelo setor Comercial.
//
// Recebe `servicos_disponiveis` do ComercialController::create() — lista do
// catálogo de serviços ativos (Frente A). Também recebe flash.success via
// HandleInertiaRequests após POST bem-sucedido.
//
// Form via useForm do Inertia:
//   - nome:     obrigatório (texto livre)
//   - cnpj:     opcional (texto livre — sem máscara automática)
//   - notes:    opcional (texto livre)
//   - servicos: array de { servico_id, valor_contratado } — pelo menos 1 obrigatório
//
// Submit chama POST comercial.empresas.store — backend cria atomicamente:
//   - Polos:       Company + MlbEmpresa (tipo=POLO) + MlbImplementacao
//   - Assessoria:  Company + MlbEmpresa (tipo=ASSESSORIA)
//   - Incubadora:  Company + MlbEmpresa (tipo=INCUBADORA)
//   - Publicidade/Gestão/Publicação: apenas Company (sem mlb_empresas)
// E notifica líderes do setor de destino.
//
// Phase 14 Plan 14-04 (Frente B): substituiu os 3 checkboxes hardcoded
// ('publicacao'/'publicidade'/'gestao') por seletor multi do catálogo
// dinâmico. Cada serviço selecionado expõe input de valor_contratado
// (default = valor_padrao do catálogo).
import { Head, useForm, usePage } from '@inertiajs/react';
import { Building2, PlusCircle } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';

export default function NovaEmpresa({ servicos_disponiveis = [] }) {
    const { flash } = usePage().props;

    const { data, setData, post, processing, errors, reset } = useForm({
        nome:     '',
        cnpj:     '',
        notes:    '',
        servicos: [], // array de { servico_id, valor_contratado }
    });

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

                        {/* Serviços contratados — catálogo dinâmico (Frente A) */}
                        <div className="space-y-1.5">
                            <label className="block text-xs text-white/60 font-medium">
                                Serviços contratados <span className="text-ecf-yellow">*</span>
                            </label>
                            <div className={cn(
                                'rounded-lg border p-3 space-y-2 bg-ecf-bg',
                                errors.servicos ? 'border-red-500/50' : 'border-white/[0.08]'
                            )}>
                                {servicos_disponiveis.length === 0 ? (
                                    <p className="text-white/40 text-xs px-1">
                                        Nenhum serviço cadastrado no catálogo. Acesse <span className="text-ecf-yellow/70">/servicos</span> para adicionar.
                                    </p>
                                ) : (
                                    servicos_disponiveis.map(servico => {
                                        const selecionado = data.servicos.find(s => s.servico_id === servico.id);
                                        const checked     = !!selecionado;
                                        return (
                                            <div
                                                key={servico.id}
                                                className={cn(
                                                    'flex items-center gap-3 rounded-lg px-3 py-2 transition-colors',
                                                    checked
                                                        ? 'bg-ecf-yellow/10 border border-ecf-yellow/30'
                                                        : 'bg-white/[0.03] border border-white/[0.06]',
                                                )}
                                            >
                                                <input
                                                    type="checkbox"
                                                    id={`servico-${servico.id}`}
                                                    checked={checked}
                                                    onChange={() => toggleServico(servico.id)}
                                                    className="accent-ecf-yellow w-3.5 h-3.5"
                                                />
                                                <label
                                                    htmlFor={`servico-${servico.id}`}
                                                    className="flex-1 cursor-pointer flex items-center gap-2"
                                                >
                                                    <span className={cn(
                                                        'text-[12px] font-medium',
                                                        checked ? 'text-ecf-yellow' : 'text-white/70'
                                                    )}>
                                                        {servico.nome}
                                                    </span>
                                                    <span className="text-white/30 text-[10px]">
                                                        ({servico.tipo_cobranca})
                                                    </span>
                                                </label>
                                                {checked && (
                                                    <div className="flex items-center gap-1">
                                                        <span className="text-white/30 text-[10px]">R$</span>
                                                        <input
                                                            type="number"
                                                            min="0"
                                                            step="0.01"
                                                            value={selecionado.valor_contratado}
                                                            onChange={e => updateValor(servico.id, e.target.value)}
                                                            placeholder="0,00"
                                                            className="w-20 px-2 py-1 rounded bg-ecf-card border border-white/[0.08] text-white text-[11px] text-right focus:outline-none focus:border-ecf-yellow/40"
                                                        />
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })
                                )}
                            </div>
                            {errors.servicos && (
                                <p className="text-red-400 text-xs mt-1">{errors.servicos}</p>
                            )}
                            {data.servicos.length > 0 && (
                                <p className="text-white/40 text-[11px] leading-snug pt-1">
                                    {data.servicos.length} serviço(s) selecionado(s) — após o cadastro, líderes do setor de destino serão notificados.
                                </p>
                            )}
                        </div>

                        {/* Botão de submit */}
                        <div className="pt-1">
                            <button
                                type="submit"
                                disabled={processing || data.servicos.length === 0}
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
