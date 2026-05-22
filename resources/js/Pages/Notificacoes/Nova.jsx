// Página /notificacoes/nova — envio manual de notificação (Phase 12, ENVIO-01..04).
//
// Recebe props do NotificacaoController::nova:
//   - setores: lista [{id, nome}] ordenada por nome (para select de público=setor)
//
// Form via useForm do Inertia:
//   - titulo:     max 100 chars (validação client maxLength + backend ENVIO-03)
//   - mensagem:   max 1000 chars (idem)
//   - publico:    'todos' (default) | 'lideres' | 'setor' | 'usuario'
//   - usuario_id: ID numérico (visível quando publico === 'usuario')
//   - setor_id:   select dos setores (visível quando publico === 'setor')
//
// Submit chama POST notificacoes.criar — backend resolve destinatários
// (ENVIO-02), dispatcha, loga em activity_log (POLL-05) e flash sucesso com
// contagem (ENVIO-04).
import { Head, useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import FormErrorBanner from '@/Components/FormErrorBanner';

export default function Nova({ setores }) {
    const { data, setData, post, processing, errors } = useForm({
        titulo:     '',
        mensagem:   '',
        publico:    'todos',
        usuario_id: '',
        setor_id:   '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('notificacoes.criar'));
    };

    return (
        <AppLayout title="Enviar notificação">
            <Head title="Enviar notificação" />

            <form onSubmit={submit} className="max-w-xl mx-auto space-y-4 p-4">
                <h1 className="text-xl font-bold text-white">Enviar notificação</h1>

                {/* Banner global de erros — visível mesmo quando o campo problemático está oculto. */}
                <FormErrorBanner errors={errors} />

                {/* Título — max 100 chars (ENVIO-03) */}
                <div>
                    <label className="text-xs text-white/60 block mb-1">Título</label>
                    <input
                        type="text"
                        maxLength={100}
                        value={data.titulo}
                        onChange={(e) => setData('titulo', e.target.value)}
                        className="w-full bg-ecf-card border border-white/[0.08] rounded px-3 py-2 text-sm text-white"
                    />
                    <div className="text-[10px] text-white/40 mt-1">{data.titulo.length}/100</div>
                    {errors.titulo && <div className="text-xs text-red-400 mt-1">{errors.titulo}</div>}
                </div>

                {/* Mensagem — max 1000 chars (ENVIO-03) */}
                <div>
                    <label className="text-xs text-white/60 block mb-1">Mensagem</label>
                    <textarea
                        maxLength={1000}
                        rows={5}
                        value={data.mensagem}
                        onChange={(e) => setData('mensagem', e.target.value)}
                        className="w-full bg-ecf-card border border-white/[0.08] rounded px-3 py-2 text-sm text-white"
                    />
                    <div className="text-[10px] text-white/40 mt-1">{data.mensagem.length}/1000</div>
                    {errors.mensagem && <div className="text-xs text-red-400 mt-1">{errors.mensagem}</div>}
                </div>

                {/* Público — define quem recebe (ENVIO-02) */}
                <div>
                    <label className="text-xs text-white/60 block mb-1">Público</label>
                    <select
                        value={data.publico}
                        onChange={(e) => setData('publico', e.target.value)}
                        className="w-full bg-ecf-card border border-white/[0.08] rounded px-3 py-2 text-sm text-white"
                    >
                        <option value="todos">Todos os usuários ativos</option>
                        <option value="lideres">Todos os líderes</option>
                        <option value="setor">Um setor específico</option>
                        <option value="usuario">Um usuário específico</option>
                    </select>
                </div>

                {/* Setor — só aparece quando publico === 'setor' */}
                {data.publico === 'setor' && (
                    <div>
                        <label className="text-xs text-white/60 block mb-1">Setor</label>
                        <select
                            value={data.setor_id}
                            onChange={(e) => setData('setor_id', e.target.value)}
                            className="w-full bg-ecf-card border border-white/[0.08] rounded px-3 py-2 text-sm text-white"
                        >
                            <option value="">— Escolha o setor —</option>
                            {setores.map((s) => (
                                <option key={s.id} value={s.id}>{s.nome}</option>
                            ))}
                        </select>
                        {errors.setor_id && <div className="text-xs text-red-400 mt-1">{errors.setor_id}</div>}
                    </div>
                )}

                {/* Usuário — ID numérico no MVP (busca por nome fica para fase futura) */}
                {data.publico === 'usuario' && (
                    <div>
                        <label className="text-xs text-white/60 block mb-1">ID do usuário</label>
                        <input
                            type="number"
                            value={data.usuario_id}
                            onChange={(e) => setData('usuario_id', e.target.value)}
                            className="w-full bg-ecf-card border border-white/[0.08] rounded px-3 py-2 text-sm text-white"
                            placeholder="ID numérico (MVP — futuro: busca por nome)"
                        />
                        {errors.usuario_id && <div className="text-xs text-red-400 mt-1">{errors.usuario_id}</div>}
                    </div>
                )}

                <button
                    type="submit"
                    disabled={processing}
                    className="bg-ecf-yellow text-ecf-bg font-bold px-4 py-2 rounded text-sm hover:opacity-90 disabled:opacity-50 flex items-center gap-2"
                >
                    <Send size={14} /> Enviar
                </button>
            </form>
        </AppLayout>
    );
}
