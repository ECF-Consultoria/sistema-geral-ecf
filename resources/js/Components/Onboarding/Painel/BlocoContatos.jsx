import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Users, Plus, Trash2, Pencil, Check, X, AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Ponto de contato do cliente (§13.2) e participantes das reuniões (§16).
 *
 * Os dois vivem na mesma lista, distinguidos por `papel` — têm exatamente o
 * mesmo shape (nome, e-mail, função, telefone) e separá-los produziria duas
 * telas gêmeas.
 *
 * ⚠️ **Cada contato é gravado como LINHA PRÓPRIA, com id.** Nunca se reenvia a
 * lista inteira: adicionar usa POST de um contato, editar usa PUT daquele id,
 * remover usa DELETE daquele id. Isso não é preciosismo — noutro módulo deste
 * sistema, salvar a lista inteira a cada alteração colapsou N produtos num só e
 * apagou o custo do cliente sem volta. A mesma forma de erro cabia aqui.
 */
const PAPEIS = [
    ['ponto_de_contato', 'Ponto de contato', 'Quem acionamos para as demandas'],
    ['participante_reuniao', 'Participante das reuniões', 'Quem recebe o convite das reuniões'],
];

const VAZIO = { papel: 'participante_reuniao', nome: '', email: '', funcao: '', telefone: '' };

function Linha({ contato, onboardingId }) {
    const [editando, setEditando] = useState(false);
    const [dados, setDados] = useState(contato);
    const [enviando, setEnviando] = useState(false);

    const salvar = () => {
        setEnviando(true);
        router.put(route('onboarding.contatos.atualizar', contato.id), {
            nome: dados.nome,
            email: dados.email || null,
            funcao: dados.funcao || null,
            telefone: dados.telefone || null,
        }, { preserveScroll: true, onFinish: () => { setEnviando(false); setEditando(false); } });
    };

    const remover = () => {
        if (!confirm(`Remover ${contato.nome}?`)) return;
        router.delete(route('onboarding.contatos.remover', contato.id), { preserveScroll: true });
    };

    const campo = 'rounded-md border border-white/[0.08] bg-white/[0.03] px-2 py-1 text-[12px] text-white/85 focus:outline-none focus:border-ecf-yellow/40';

    if (editando) {
        return (
            <div className="flex flex-wrap items-center gap-1.5 py-1.5">
                <input value={dados.nome} onChange={(e) => setDados({ ...dados, nome: e.target.value })} placeholder="Nome" className={`${campo} w-36`} />
                <input value={dados.email || ''} onChange={(e) => setDados({ ...dados, email: e.target.value })} placeholder="E-mail" className={`${campo} w-52`} />
                <input value={dados.funcao || ''} onChange={(e) => setDados({ ...dados, funcao: e.target.value })} placeholder="Função" className={`${campo} w-28`} />
                <input value={dados.telefone || ''} onChange={(e) => setDados({ ...dados, telefone: e.target.value })} placeholder="Telefone" className={`${campo} w-32`} />
                <button onClick={salvar} disabled={enviando} className="text-emerald-300 hover:text-emerald-200 p-1"><Check size={14} /></button>
                <button onClick={() => { setDados(contato); setEditando(false); }} className="text-white/40 hover:text-white/70 p-1"><X size={14} /></button>
            </div>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-2 py-1.5 group">
            <span className="text-[13px] text-white/85">{contato.nome}</span>
            {contato.funcao && <span className="text-[11px] text-white/35">{contato.funcao}</span>}
            {contato.email ? (
                <span className="text-[12px] text-white/55">{contato.email}</span>
            ) : (
                <span
                    className="inline-flex items-center gap-1 text-[11px] text-amber-300"
                    title="Sem e-mail, este participante não recebe o convite — e o item do checklist não fecha"
                >
                    <AlertTriangle size={11} /> sem e-mail
                </span>
            )}
            {contato.telefone && <span className="text-[12px] text-white/40">{contato.telefone}</span>}

            <span className="ml-auto flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                <button onClick={() => setEditando(true)} className="text-white/40 hover:text-white/80 p-1"><Pencil size={13} /></button>
                <button onClick={remover} className="text-red-400/70 hover:text-red-300 p-1"><Trash2 size={13} /></button>
            </span>
        </div>
    );
}

export default function BlocoContatos({ onboardingId, contatos = [] }) {
    const [novo, setNovo] = useState(VAZIO);
    const [abrindo, setAbrindo] = useState(false);
    const [enviando, setEnviando] = useState(false);

    const adicionar = () => {
        if (!novo.nome.trim()) return;
        setEnviando(true);
        router.post(route('onboarding.contatos.salvar', onboardingId), {
            papel: novo.papel,
            nome: novo.nome,
            email: novo.email || null,
            funcao: novo.funcao || null,
            telefone: novo.telefone || null,
        }, {
            preserveScroll: true,
            onSuccess: () => setNovo(VAZIO),
            onFinish: () => { setEnviando(false); setAbrindo(false); },
        });
    };

    const campo = 'rounded-md border border-white/[0.08] bg-white/[0.03] px-2 py-1.5 text-[12px] text-white/85 placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/40';

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 space-y-3">
            <div className="flex items-center gap-2">
                <Users size={15} className="text-ecf-yellow/70" />
                <h3 className="text-[13px] font-semibold text-white/80">Contatos do cliente</h3>
            </div>

            {PAPEIS.map(([papel, rotulo, ajuda]) => {
                const doPapel = contatos.filter((c) => c.papel === papel);

                return (
                    <div key={papel}>
                        <div className="text-[11px] uppercase tracking-wide text-white/35 mb-1" title={ajuda}>
                            {rotulo}
                        </div>
                        {doPapel.length === 0 ? (
                            <p className="text-[12px] text-white/25 py-1">Ninguém cadastrado.</p>
                        ) : (
                            <div className="divide-y divide-white/[0.05]">
                                {doPapel.map((c) => (
                                    <Linha key={c.id} contato={c} onboardingId={onboardingId} />
                                ))}
                            </div>
                        )}
                    </div>
                );
            })}

            {abrindo ? (
                <div className="rounded-lg border border-white/[0.08] bg-white/[0.02] p-3 space-y-2">
                    <select
                        value={novo.papel}
                        onChange={(e) => setNovo({ ...novo, papel: e.target.value })}
                        className={`${campo} w-full cursor-pointer`}
                    >
                        {PAPEIS.map(([p, rotulo]) => <option key={p} value={p}>{rotulo}</option>)}
                    </select>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <input value={novo.nome} onChange={(e) => setNovo({ ...novo, nome: e.target.value })} placeholder="Nome *" className={campo} />
                        <input value={novo.email} onChange={(e) => setNovo({ ...novo, email: e.target.value })} placeholder="E-mail (Gmail, para o convite)" className={campo} />
                        <input value={novo.funcao} onChange={(e) => setNovo({ ...novo, funcao: e.target.value })} placeholder="Função" className={campo} />
                        <input value={novo.telefone} onChange={(e) => setNovo({ ...novo, telefone: e.target.value })} placeholder="Telefone" className={campo} />
                    </div>
                    <div className="flex gap-1.5">
                        <button
                            onClick={adicionar}
                            disabled={enviando || !novo.nome.trim()}
                            className="rounded-md border border-ecf-yellow/40 bg-ecf-yellow/15 px-3 py-1.5 text-[12px] font-semibold text-ecf-yellow disabled:opacity-40"
                        >
                            Adicionar
                        </button>
                        <button onClick={() => { setNovo(VAZIO); setAbrindo(false); }} className="rounded-md border border-white/[0.08] px-3 py-1.5 text-[12px] text-white/50">
                            Cancelar
                        </button>
                    </div>
                </div>
            ) : (
                <button
                    onClick={() => setAbrindo(true)}
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-lg border border-dashed border-white/15',
                        'px-3 py-1.5 text-[12px] text-white/50 hover:text-white/80 hover:border-white/30 transition-colors'
                    )}
                >
                    <Plus size={13} /> Adicionar pessoa
                </button>
            )}
        </div>
    );
}
