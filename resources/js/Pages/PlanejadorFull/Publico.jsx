import { Head } from '@inertiajs/react';
import Planejador from '@/Components/PlanejadorFull/Planejador';

// ─── Planejador FULL pelo link aberto do cliente ────────────────────────────
// Sem login e sem o menu do sistema: o cliente vê só a empresa dele (a do token) e
// opera tudo, igual à equipe. Mesma moldura das outras páginas públicas (PPA por link).
export default function Publico(props) {
    return (
        <div className="min-h-screen bg-[#050507] text-white">
            <Head title={`Planejador FULL · ${props.empresa.nome}`}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <div className="h-[3px] bg-gradient-to-r from-yellow-400 via-yellow-300 to-yellow-500" />
            <header className="border-b border-white/[0.06]">
                <div className="mx-auto flex max-w-[1440px] items-center gap-3 px-4 py-3">
                    <span className="inline-flex h-8 w-8 items-center justify-center rounded-xl border border-ecf-yellow/20 bg-ecf-yellow/10 text-[15px] font-bold text-ecf-yellow">E</span>
                    <p className="min-w-0 truncate text-[13px] text-white/55">
                        <b className="text-white">{props.empresa.nome}</b> · ECF Consultoria
                    </p>
                </div>
            </header>
            <main className="px-4 py-6">
                <Planejador modo="cliente" {...props} />
            </main>
        </div>
    );
}
