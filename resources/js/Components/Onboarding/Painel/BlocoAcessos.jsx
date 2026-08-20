import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, Link2, Loader2, Mail } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

/**
 * BlocoAcessos — o link do App ECF e o e-mail do colaborador, que a ECF
 * configura e o CLIENTE consome no portal.
 *
 * ### Por EMPRESA, sem padrão global
 * A primeira versão tinha os dois escopos, com um padrão que valia para toda a
 * base. O negócio corrigiu: cada cliente tem o seu link e o seu e-mail. Um
 * padrão único mandaria a base inteira para o mesmo endereço, e o erro só
 * apareceria dias depois — quando o acesso não chegasse a ninguém.
 *
 * Por isso campo vazio aqui significa **não configurado**, e não "usa outro
 * valor". O portal do cliente mostra um aviso nesse caso, em vez de um campo em
 * branco que pareceria instrução incompleta.
 */
function Campo({ icone: Icone, rotulo, valor, aoMudar, placeholder, tipo = 'text', ajuda }) {
    return (
        <div>
            <label className="flex items-center gap-1.5 text-[12px] text-white/60 mb-1.5">
                <Icone size={13} className="text-white/40" />
                {rotulo}
            </label>

            <input
                type={tipo}
                value={valor}
                onChange={(e) => aoMudar(e.target.value)}
                placeholder={placeholder}
                className="w-full h-10 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/25 transition-colors"
            />

            {ajuda && <p className="text-white/30 text-[11px] mt-1">{ajuda}</p>}
        </div>
    );
}

export default function BlocoAcessos({ rota, valores, titulo, ajuda }) {
    const [link, setLink] = useState(valores.app_ecf_link ?? '');
    const [email, setEmail] = useState(valores.email_colaborador ?? '');
    const [salvando, setSalvando] = useState(false);
    const [salvo, setSalvo] = useState(false);

    const salvar = () => {
        if (salvando) return;

        setSalvando(true);
        router.put(
            rota,
            { app_ecf_link: link || null, email_colaborador: email || null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSalvo(true);
                    setTimeout(() => setSalvo(false), 2500);
                },
                onFinish: () => setSalvando(false),
            }
        );
    };

    return (
        <section className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 space-y-4">
            <div>
                <h3 className="text-white font-display font-bold text-[15px]">{titulo}</h3>
                {ajuda && <p className="text-white/40 text-[12px] mt-0.5">{ajuda}</p>}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Campo
                    icone={Link2}
                    rotulo="Link do App ECF"
                    valor={link}
                    aoMudar={setLink}
                    tipo="url"
                    placeholder="https://..."
                    ajuda="Vazio: o cliente vê um aviso em vez do link."
                />

                <Campo
                    icone={Mail}
                    rotulo="E-mail do colaborador"
                    valor={email}
                    aoMudar={setEmail}
                    tipo="email"
                    placeholder="acessos.cliente@ecfconsultoria.com.br"
                    ajuda="É o endereço que o cliente convida no Mercado Livre."
                />
            </div>

            <div className="flex items-center gap-3">
                <Button size="sm" onClick={salvar} disabled={salvando}>
                    {salvando && <Loader2 size={14} className="mr-1.5 animate-spin" />}
                    {salvando ? 'Salvando…' : 'Salvar'}
                </Button>

                {salvo && (
                    <span className={cn('inline-flex items-center gap-1.5 text-[12px] font-semibold text-emerald-300')}>
                        <Check size={14} /> Salvo — o cliente já vê no portal
                    </span>
                )}
            </div>
        </section>
    );
}
