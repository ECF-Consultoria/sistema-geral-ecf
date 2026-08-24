import { useEffect, useRef, useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, ArrowRight, Mail, ShieldCheck } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── A porta da frente do Portal do Cliente ─────────────────────────────────
//
// Dois passos na MESMA tela: o e-mail, depois os seis dígitos. Sem link de
// entrada no e-mail, de propósito — é o que faz o e-mail encaminhado não dar
// acesso a ninguém (o código só vale no navegador que o pediu).
//
// ### Por que a tela não diz se o e-mail existe
// Depois de pedir o código, a mensagem é a mesma para e-mail cadastrado e não
// cadastrado. Variar aqui transformaria esta página num verificador de quem é
// cliente da ECF — bastaria tentar o e-mail do dono de uma empresa e ler a
// diferença.
//
// Nada de dado de empresa nesta tela: ela é servida a qualquer visitante da
// internet, antes de qualquer autenticação.

export default function Entrada({ aviso }) {
    const { flash = {}, errors = {} } = usePage().props;

    // O passo é derivado do flash do servidor, não de estado local: assim um
    // F5 depois de pedir o código não joga a pessoa de volta ao começo.
    const [passo, setPasso] = useState(flash.portal_codigo_enviado ? 'codigo' : 'email');

    const form = useForm({ email: flash.portal_email ?? '', codigo: '' });
    const campoCodigo = useRef(null);

    useEffect(() => {
        if (flash.portal_codigo_enviado) {
            setPasso('codigo');
            form.setData('email', flash.portal_email ?? form.data.email);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flash.portal_codigo_enviado, flash.portal_email]);

    useEffect(() => {
        if (passo === 'codigo') campoCodigo.current?.focus();
    }, [passo]);

    const pedirCodigo = (e) => {
        e.preventDefault();
        form.post(route('portal.codigo'), { preserveScroll: true });
    };

    const entrar = (e) => {
        e.preventDefault();
        form.post(route('portal.validar'), { preserveScroll: true });
    };

    return (
        <div className="min-h-screen bg-ecf-bg flex items-center justify-center px-4 py-12">
            <Head title="Entrar · Portal do Cliente" />

            <div className="w-full max-w-[400px]">
                <div className="text-center">
                    <p className="text-ecf-yellow font-display font-extrabold text-xl leading-none">ECF</p>
                    <p className="text-white/35 text-[10px] tracking-[0.18em] uppercase mt-1">Consultoria</p>
                </div>

                <div className="mt-8 rounded-2xl bg-white/[0.03] ring-1 ring-inset ring-white/[0.06] p-7">
                    <h1 className="text-white font-display font-bold text-[21px] tracking-tight text-center">
                        Portal do Cliente
                    </h1>

                    {aviso && (
                        <p className="mt-4 flex items-start gap-2 rounded-xl bg-amber-500/10 ring-1 ring-inset ring-amber-500/20 px-3.5 py-3 text-amber-200/90 text-[12.5px] leading-relaxed">
                            <AlertTriangle size={14} className="shrink-0 mt-0.5" /> {aviso}
                        </p>
                    )}

                    {passo === 'email' ? (
                        <form onSubmit={pedirCodigo} className="mt-6 space-y-4">
                            <p className="text-white/45 text-[13px] leading-relaxed text-center">
                                Informe o seu e-mail. Enviamos um código de acesso para ele.
                            </p>

                            <div className="space-y-1.5">
                                <label htmlFor="email" className="block text-white/50 text-[12px] font-medium">
                                    E-mail
                                </label>
                                <input
                                    id="email"
                                    type="email"
                                    autoComplete="email"
                                    autoFocus
                                    required
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                    placeholder="voce@suaempresa.com.br"
                                    className="w-full h-11 rounded-xl bg-white/[0.04] ring-1 ring-inset ring-white/[0.08] px-3.5 text-white text-[14px] placeholder:text-white/25 outline-none focus:ring-ecf-yellow/40 transition-shadow"
                                />
                                {errors.email && (
                                    <p className="text-rose-300 text-[12px]">{errors.email}</p>
                                )}
                            </div>

                            <button
                                type="submit"
                                disabled={form.processing}
                                className="w-full h-11 rounded-xl bg-ecf-yellow text-ecf-bg font-semibold text-[14px] flex items-center justify-center gap-2 hover:bg-ecf-yellow/90 transition-colors disabled:opacity-60"
                            >
                                {form.processing ? 'Enviando…' : <>Receber código <ArrowRight size={15} /></>}
                            </button>
                        </form>
                    ) : (
                        <form onSubmit={entrar} className="mt-6 space-y-4">
                            <div className="flex items-start gap-2.5 rounded-xl bg-white/[0.03] p-3.5">
                                <Mail size={15} className="text-ecf-yellow shrink-0 mt-0.5" />
                                <p className="text-white/50 text-[12.5px] leading-relaxed">
                                    Se este e-mail tiver acesso ao portal, o código chegou em{' '}
                                    <span className="text-white/80 font-medium break-all">{form.data.email}</span>.
                                    Ele vale por 10 minutos.
                                </p>
                            </div>

                            <div className="space-y-1.5">
                                <label htmlFor="codigo" className="block text-white/50 text-[12px] font-medium">
                                    Código de 6 dígitos
                                </label>
                                <input
                                    id="codigo"
                                    ref={campoCodigo}
                                    type="text"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={6}
                                    required
                                    value={form.data.codigo}
                                    onChange={(e) => form.setData('codigo', e.target.value.replace(/\D/g, ''))}
                                    placeholder="000000"
                                    className={cn(
                                        'w-full h-14 rounded-xl bg-white/[0.04] ring-1 ring-inset px-3.5 outline-none transition-shadow',
                                        'text-white text-[26px] font-display font-bold tracking-[0.3em] text-center tabular-nums',
                                        'placeholder:text-white/15 placeholder:tracking-[0.3em]',
                                        errors.codigo ? 'ring-rose-400/40' : 'ring-white/[0.08] focus:ring-ecf-yellow/40',
                                    )}
                                />
                                {errors.codigo && (
                                    <p className="text-rose-300 text-[12px]">{errors.codigo}</p>
                                )}
                            </div>

                            <button
                                type="submit"
                                disabled={form.processing || form.data.codigo.length < 6}
                                className="w-full h-11 rounded-xl bg-ecf-yellow text-ecf-bg font-semibold text-[14px] hover:bg-ecf-yellow/90 transition-colors disabled:opacity-40"
                            >
                                {form.processing ? 'Entrando…' : 'Entrar'}
                            </button>

                            <div className="flex items-center justify-between pt-1">
                                <button
                                    type="button"
                                    onClick={() => { setPasso('email'); form.setData('codigo', ''); }}
                                    className="flex items-center gap-1.5 text-white/35 hover:text-white/70 text-[12.5px] transition-colors"
                                >
                                    <ArrowLeft size={12} /> Trocar e-mail
                                </button>
                                <button
                                    type="button"
                                    onClick={() => form.post(route('portal.codigo'), { preserveScroll: true })}
                                    disabled={form.processing}
                                    className="text-white/35 hover:text-white/70 text-[12.5px] transition-colors disabled:opacity-40"
                                >
                                    Reenviar código
                                </button>
                            </div>
                        </form>
                    )}
                </div>

                {/* Explica por que o código não funciona se for repassado — o
                    cliente que tentar encaminhar precisa entender que não é bug. */}
                <p className="mt-5 flex items-start gap-2 text-white/25 text-[11.5px] leading-relaxed px-1">
                    <ShieldCheck size={13} className="shrink-0 mt-0.5" />
                    O código só funciona neste navegador. Se você encaminhar o e-mail, ele não dará acesso a mais ninguém.
                </p>
            </div>
        </div>
    );
}
