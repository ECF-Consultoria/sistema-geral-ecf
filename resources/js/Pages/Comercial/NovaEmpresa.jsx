import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm } from '@inertiajs/react';

/**
 * Tela de cadastro de nova empresa pelo setor Comercial.
 *
 * Permite ao Comercial registrar uma nova empresa e definir o service_type.
 * O controller ComercialController::store() cria os registros necessários
 * de forma atômica (Company + MlbEmpresa + MlbImplementacao quando aplicável)
 * e notifica os líderes do setor de destino.
 *
 * TODO (Wave 4): implementar o formulário completo com todos os campos
 * e feedback visual. Esta é a versão MVP funcional para testes de integração.
 */
export default function NovaEmpresa() {
    const { data, setData, post, processing, errors, reset } = useForm({
        nome: '',
        cnpj: '',
        service_type: '',
    });

    function handleSubmit(e) {
        e.preventDefault();
        post(route('comercial.empresas.store'), {
            onSuccess: () => reset(),
        });
    }

    return (
        <AppLayout>
            <Head title="Novo Cadastro de Empresa" />

            <div className="p-6 text-white">
                <h1 className="text-xl font-semibold mb-6">Cadastrar Nova Empresa</h1>

                <form onSubmit={handleSubmit} className="max-w-md space-y-4">
                    <div>
                        <label className="block text-sm text-white/60 mb-1">Nome da Empresa *</label>
                        <input
                            type="text"
                            value={data.nome}
                            onChange={e => setData('nome', e.target.value)}
                            className="w-full bg-white/[0.06] border border-white/10 rounded px-3 py-2 text-white text-sm"
                            required
                        />
                        {errors.nome && <p className="text-red-400 text-xs mt-1">{errors.nome}</p>}
                    </div>

                    <div>
                        <label className="block text-sm text-white/60 mb-1">CNPJ</label>
                        <input
                            type="text"
                            value={data.cnpj}
                            onChange={e => setData('cnpj', e.target.value)}
                            className="w-full bg-white/[0.06] border border-white/10 rounded px-3 py-2 text-white text-sm"
                        />
                        {errors.cnpj && <p className="text-red-400 text-xs mt-1">{errors.cnpj}</p>}
                    </div>

                    <div>
                        <label className="block text-sm text-white/60 mb-1">Tipo de Serviço *</label>
                        <select
                            value={data.service_type}
                            onChange={e => setData('service_type', e.target.value)}
                            className="w-full bg-white/[0.06] border border-white/10 rounded px-3 py-2 text-white text-sm"
                            required
                        >
                            <option value="">Selecione...</option>
                            <option value="polos">Publicação — POLOS</option>
                            <option value="assessoria">Publicação — Assessoria</option>
                            <option value="publicidade">Publicidade</option>
                            <option value="gestao">Gestão</option>
                        </select>
                        {errors.service_type && <p className="text-red-400 text-xs mt-1">{errors.service_type}</p>}
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-ecf-yellow text-black font-semibold px-4 py-2 rounded text-sm hover:bg-yellow-300 disabled:opacity-50"
                    >
                        {processing ? 'Cadastrando...' : 'Cadastrar Empresa'}
                    </button>
                </form>
            </div>
        </AppLayout>
    );
}
