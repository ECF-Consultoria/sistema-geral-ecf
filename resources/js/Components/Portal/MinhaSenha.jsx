import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Check, KeyRound, X } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Senha do cliente — opcional, e por isso discreta ───────────────────────
//
// O caminho padrão do portal é o código por e-mail. Este bloco existe para quem
// entra com frequência e prefere não esperar o e-mail toda vez.
//
// ### Por que fica fechado por padrão
// A maioria nunca vai definir senha, e está certo. Um formulário aberto no meio
// do hub diria o contrário — que falta configurar alguma coisa. Fechado, é uma
// oferta; aberto por padrão, seria uma cobrança.
//
// ### Não há "esqueci minha senha"
// Quem esquecer entra pelo código, que é o mesmo caminho de sempre e já está na
// tela de entrada. É por isso que o texto do botão de remover fala em "voltar a
// entrar pelo código": para a pessoa saber que não está se trancando para fora.

export default function MinhaSenha() {
    const { usuario, flash = {} } = usePage().props;
    const [aberto, setAberto] = useState(false);

    const form = useForm({ senha: '', senha_confirmation: '' });

    // Sessão de equipe não tem senha para mudar: a conta não é de quem está
    // olhando. E no modo por token não há conta nenhuma.
    if (! usuario || usuario.equipe) return null;

    const temSenha = !! usuario.tem_senha;

    const salvar = (e) => {
        e.preventDefault();
        form.put(route('portal.auth.senha'), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); setAberto(false); },
        });
    };

    const remover = () => {
        if (! confirm('Remover a senha? Você volta a entrar pelo código enviado por e-mail.')) return;

        form.transform(() => ({ senha: null }));
        form.put(route('portal.auth.senha'), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); setAberto(false); },
        });
    };

    return (
        <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4">
            <button
                type="button"
                onClick={() => setAberto((v) => ! v)}
                className="w-full flex items-center gap-2.5 text-left"
            >
                <span className={cn(
                    'grid place-items-center h-8 w-8 rounded-lg shrink-0',
                    temSenha ? 'bg-emerald-400/12 text-emerald-300' : 'bg-white/[0.05] text-white/35',
                )}>
                    <KeyRound size={14} />
                </span>

                <span className="min-w-0 flex-1">
                    <span className="block text-white text-[13px] font-semibold">
                        {temSenha ? 'Sua senha está ativa' : 'Entrar mais rápido'}
                    </span>
                    <span className="block text-white/35 text-[11.5px] mt-0.5 leading-snug">
                        {temSenha
                            ? 'Você pode entrar com ela em vez de esperar o código.'
                            : 'Defina uma senha e entre sem esperar o e-mail.'}
                    </span>
                </span>
            </button>

            {flash.portal_sucesso && (
                <p className="mt-3 flex items-start gap-1.5 text-emerald-300 text-[12px] leading-relaxed">
                    <Check size={13} className="shrink-0 mt-0.5" /> {flash.portal_sucesso}
                </p>
            )}

            {aberto && (
                <form onSubmit={salvar} className="mt-4 space-y-3">
                    <div className="space-y-1.5">
                        <label htmlFor="nova-senha" className="block text-white/50 text-[11.5px] font-medium">
                            {temSenha ? 'Nova senha' : 'Senha'}
                        </label>
                        <input
                            id="nova-senha"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.senha ?? ''}
                            onChange={(e) => form.setData('senha', e.target.value)}
                            placeholder="Pelo menos 8 caracteres"
                            className="w-full h-10 rounded-lg bg-white/[0.04] ring-1 ring-inset ring-white/[0.08] px-3 text-white text-[13px] placeholder:text-white/25 outline-none focus:ring-ecf-yellow/40 transition-shadow"
                        />
                        {form.errors.senha && (
                            <p className="text-rose-300 text-[11.5px]">{form.errors.senha}</p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <label htmlFor="repetir-senha" className="block text-white/50 text-[11.5px] font-medium">
                            Repita a senha
                        </label>
                        <input
                            id="repetir-senha"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.senha_confirmation ?? ''}
                            onChange={(e) => form.setData('senha_confirmation', e.target.value)}
                            className="w-full h-10 rounded-lg bg-white/[0.04] ring-1 ring-inset ring-white/[0.08] px-3 text-white text-[13px] outline-none focus:ring-ecf-yellow/40 transition-shadow"
                        />
                    </div>

                    <div className="flex items-center gap-2 pt-0.5">
                        <button
                            type="submit"
                            disabled={form.processing || ! form.data.senha}
                            className="h-9 px-3.5 rounded-lg bg-ecf-yellow text-ecf-bg font-semibold text-[12.5px] hover:bg-ecf-yellow/90 transition-colors disabled:opacity-40"
                        >
                            {form.processing ? 'Salvando…' : 'Salvar senha'}
                        </button>

                        {temSenha && (
                            <button
                                type="button"
                                onClick={remover}
                                className="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg text-white/40 hover:text-rose-300 hover:bg-rose-400/10 text-[12.5px] transition-colors"
                            >
                                <X size={13} /> Remover
                            </button>
                        )}
                    </div>
                </form>
            )}
        </div>
    );
}
