import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

export default function Login({ status, canResetPassword }) {
    const { asset_url } = usePage().props;
    const logoSrc = `${asset_url}/images/logo.png`;
    // Guard para evitar o 419 por race condition do autocomplete:
    // aguarda a sessão ser processada pelo browser antes de habilitar o submit
    const readyRef = useRef(false);
    useEffect(() => {
        const t = setTimeout(() => { readyRef.current = true; }, 300);
        return () => clearTimeout(t);
    }, []);

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        if (!readyRef.current) {
            // Sessão ainda não processada — tenta de novo em 200ms
            setTimeout(() => submit(e), 200);
            return;
        }
        post(route('login'), { onFinish: () => reset('password') });
    };

    return (
        <div className="min-h-screen bg-[#050507] flex items-center justify-center p-4 relative overflow-hidden">
            <Head title="Login — ECF Admin" />

            {/* Background glow */}
            <div className="absolute inset-0 pointer-events-none">
                <div className="absolute top-0 left-1/2 -translate-x-1/2 w-[600px] h-[300px] bg-ecf-yellow/[0.03] blur-[80px] rounded-full" />
                <div className="absolute bottom-0 left-1/4 w-[400px] h-[200px] bg-purple-500/[0.04] blur-[80px] rounded-full" />
            </div>

            <div className="w-full max-w-md relative">
                {/* Top gradient line */}
                <div className="h-[3px] bg-ecf-grad rounded-t-2xl" />

                <div className="bg-[#0f1116] border border-white/[0.07] border-t-0 rounded-b-2xl px-8 py-10 space-y-8">
                    {/* Brand */}
                    <div className="text-center space-y-3">
                        <img
                            src={logoSrc}
                            alt="ECF Consultoria"
                            className="h-10 w-auto object-contain mx-auto"
                            onError={e => {
                                e.currentTarget.replaceWith(Object.assign(document.createElement('span'), {
                                    textContent: 'ECF Admin',
                                    className: 'text-white font-display font-extrabold text-xl'
                                }));
                            }}
                        />
                    </div>

                    {status && (
                        <div className="text-sm text-green-400 bg-green-400/10 border border-green-400/20 rounded-xl px-4 py-3">
                            {status}
                        </div>
                    )}

                    <form onSubmit={submit} className="space-y-5">
                        <div className="space-y-2">
                            <label htmlFor="email" className="block text-[13px] font-semibold text-white/70 tracking-wide">
                                E-mail
                            </label>
                            <input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={e => setData('email', e.target.value)}
                                autoFocus
                                required
                                placeholder="seu@email.com"
                                className="w-full h-11 rounded-xl border border-white/[0.08] bg-white/[0.03] px-4 text-sm text-white placeholder:text-white/20 focus:outline-none focus:ring-2 focus:ring-ecf-yellow/40 focus:border-ecf-yellow/40 transition-all"
                            />
                            {errors.email && <p className="text-red-400 text-xs mt-1">{errors.email}</p>}
                        </div>

                        <div className="space-y-2">
                            <label htmlFor="password" className="block text-[13px] font-semibold text-white/70 tracking-wide">
                                Senha
                            </label>
                            <input
                                id="password"
                                type="password"
                                value={data.password}
                                onChange={e => setData('password', e.target.value)}
                                required
                                placeholder="••••••••"
                                className="w-full h-11 rounded-xl border border-white/[0.08] bg-white/[0.03] px-4 text-sm text-white placeholder:text-white/20 focus:outline-none focus:ring-2 focus:ring-ecf-yellow/40 focus:border-ecf-yellow/40 transition-all"
                            />
                            {errors.password && <p className="text-red-400 text-xs mt-1">{errors.password}</p>}
                        </div>

                        <div className="flex items-center justify-between">
                            <label className="flex items-center gap-2 cursor-pointer group">
                                <input
                                    type="checkbox"
                                    checked={data.remember}
                                    onChange={e => setData('remember', e.target.checked)}
                                    className="w-4 h-4 rounded accent-ecf-yellow"
                                />
                                <span className="text-[13px] text-white/40 group-hover:text-white/60 transition-colors">Lembrar-me</span>
                            </label>
                            {canResetPassword && (
                                <Link
                                    href={route('password.request')}
                                    className="text-[13px] text-ecf-yellow/70 hover:text-ecf-yellow transition-colors font-medium"
                                >
                                    Esqueci a senha
                                </Link>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full h-11 rounded-xl bg-yellow-grad font-display font-bold text-[#252525] text-sm tracking-wide shadow-lg shadow-ecf-yellow/20 hover:shadow-ecf-yellow/30 hover:-translate-y-0.5 transition-all duration-200 disabled:opacity-60 disabled:cursor-not-allowed disabled:transform-none"
                        >
                            {processing ? 'Entrando...' : 'Entrar'}
                        </button>
                    </form>
                </div>

                <p className="text-center text-white/20 text-xs mt-5">
                    ECF Consultoria · Acesso restrito
                </p>
            </div>
        </div>
    );
}
