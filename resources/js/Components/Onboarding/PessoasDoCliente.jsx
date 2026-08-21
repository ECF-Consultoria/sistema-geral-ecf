import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Plus, UserPlus } from 'lucide-react';

/**
 * Formulário do PORTAL DO CLIENTE para ele informar quem acionamos no dia a
 * dia (§13.2) e quem participa das reuniões, com os Gmails (§16).
 *
 * Só adiciona. Editar e remover ficam do lado interno de propósito: este é um
 * link sem senha, e dar a ele poder de apagar o cadastro de outras pessoas
 * seria conceder bem mais do que "informe quem participa".
 *
 * O e-mail é obrigatório para participante e opcional para ponto de contato —
 * o objetivo declarado do §16 é mandar o convite, e participante sem e-mail
 * não recebe encontro nenhum. Para quem só vai ser acionado, telefone basta.
 *
 * `sugestoes` são as pessoas que o cliente já cadastrou no OUTRO papel e que
 * ainda não estão neste. Elas viram um seletor: o caso normal é o próprio
 * ponto de contato participar das reuniões, e antes disto ele tinha de digitar
 * de novo, no item seguinte, exatamente o que acabara de digitar no anterior.
 *
 * O ponto de contato COM e-mail já entra como participante sozinho (o backend
 * espelha em `garantirParticipante()`); o seletor cobre o resto — quem foi
 * cadastrado sem Gmail e agora precisa de um para receber o convite.
 */
export default function PessoasDoCliente({ token, papel, pessoas = [], sugestoes = [] }) {
    const [abrindo, setAbrindo] = useState(false);
    const [dados, setDados] = useState({ nome: '', email: '', funcao: '', telefone: '' });
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState(null);

    const ehParticipante = papel === 'participante_reuniao';
    const emailObrigatorio = ehParticipante;

    // Abre o formulário já preenchido com a pessoa escolhida. NÃO envia
    // direto: quando falta o Gmail (ponto de contato pode ter sido cadastrado
    // sem ele), o cliente precisa completar antes de salvar.
    const usarSugestao = (id) => {
        const p = sugestoes.find((x) => String(x.id) === String(id));
        if (!p) return;

        setDados({
            nome: p.nome ?? '',
            email: p.email ?? '',
            funcao: p.funcao ?? '',
            telefone: p.telefone ?? '',
        });
        setErro(null);
        setAbrindo(true);
    };

    const enviar = () => {
        if (!dados.nome.trim()) return;
        if (emailObrigatorio && !dados.email.trim()) {
            setErro('O Gmail é necessário para enviarmos o convite da reunião.');

            return;
        }

        setErro(null);
        setEnviando(true);

        router.post(
            route('onboarding.publico.pessoas', token),
            { papel, ...dados },
            {
                preserveScroll: true,
                onSuccess: () => setDados({ nome: '', email: '', funcao: '', telefone: '' }),
                onError: (e) => setErro(Object.values(e)[0] ?? 'Não foi possível salvar.'),
                onFinish: () => { setEnviando(false); setAbrindo(false); },
            }
        );
    };

    const campo = 'w-full rounded-lg border border-white/[0.10] bg-white/[0.04] px-3 py-2 text-[13px] text-white/85 placeholder:text-white/30 focus:outline-none focus:border-ecf-yellow/50';

    return (
        <div className="space-y-2">
            {pessoas.length > 0 && (
                <ul className="space-y-1">
                    {pessoas.map((p) => (
                        <li key={p.id} className="flex flex-wrap items-center gap-2 text-[13px]">
                            <span className="text-white/85">{p.nome}</span>
                            {p.funcao && <span className="text-white/35 text-[12px]">{p.funcao}</span>}
                            {p.email && <span className="text-white/55 text-[12px]">{p.email}</span>}
                        </li>
                    ))}
                </ul>
            )}

            {abrindo ? (
                <div className="space-y-2 rounded-lg border border-white/[0.10] bg-white/[0.02] p-3">
                    <input
                        autoFocus
                        value={dados.nome}
                        onChange={(e) => setDados({ ...dados, nome: e.target.value })}
                        placeholder="Nome"
                        className={campo}
                    />
                    <input
                        type="email"
                        value={dados.email}
                        onChange={(e) => setDados({ ...dados, email: e.target.value })}
                        placeholder={emailObrigatorio ? 'Gmail (para o convite)' : 'E-mail (opcional)'}
                        className={campo}
                    />
                    <div className="grid grid-cols-2 gap-2">
                        <input
                            value={dados.funcao}
                            onChange={(e) => setDados({ ...dados, funcao: e.target.value })}
                            placeholder="Cargo (opcional)"
                            className={campo}
                        />
                        <input
                            value={dados.telefone}
                            onChange={(e) => setDados({ ...dados, telefone: e.target.value })}
                            placeholder="Telefone (opcional)"
                            className={campo}
                        />
                    </div>

                    {erro && <p className="text-[12px] text-red-300">{erro}</p>}

                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={enviar}
                            disabled={enviando || !dados.nome.trim()}
                            className="rounded-lg bg-ecf-yellow px-3 py-1.5 text-[13px] font-semibold text-black disabled:opacity-40"
                        >
                            {enviando ? 'Salvando…' : 'Salvar'}
                        </button>
                        <button
                            type="button"
                            onClick={() => { setAbrindo(false); setErro(null); }}
                            className="rounded-lg border border-white/[0.10] px-3 py-1.5 text-[13px] text-white/60"
                        >
                            Cancelar
                        </button>
                    </div>
                </div>
            ) : (
                <div className="flex flex-wrap items-center gap-2">
                    {sugestoes.length > 0 && (
                        <select
                            value=""
                            onChange={(e) => usarSugestao(e.target.value)}
                            className="rounded-lg border border-white/[0.10] bg-white/[0.04] px-3 py-1.5 text-[13px] text-white/70 cursor-pointer focus:outline-none focus:border-ecf-yellow/50"
                        >
                            <option value="">
                                {ehParticipante ? 'Incluir alguém já cadastrado…' : 'Usar alguém já cadastrado…'}
                            </option>
                            {sugestoes.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.nome}{p.funcao ? ` — ${p.funcao}` : ''}
                                </option>
                            ))}
                        </select>
                    )}

                <button
                    type="button"
                    onClick={() => setAbrindo(true)}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-white/20 px-3 py-1.5 text-[13px] text-white/60 hover:text-white/90 hover:border-white/35 transition-colors"
                >
                    {pessoas.length > 0 ? <Plus size={14} /> : <UserPlus size={14} />}
                    {ehParticipante
                        ? (pessoas.length > 0 ? 'Adicionar outra pessoa' : 'Adicionar participante')
                        : (pessoas.length > 0 ? 'Indicar outro contato' : 'Indicar ponto de contato')}
                </button>
                </div>
            )}
        </div>
    );
}
