import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ArrowLeft, Moon, Sun } from 'lucide-react';
import { cn } from '@/lib/utils';
import '../../css/trilha.css';

// ─── Trilha de produtos — a moldura do subsistema ───────────────────────────
//
// A trilha é um subsistema: não usa o menu lateral do admin nem a moldura do
// Portal. É a mesma tela para a equipe (admin.) e para o cliente (cliente.), e
// por isso a moldura é uma só, com o "voltar" apontando para cada lado.
//
// ### Tema
// Claro por padrão (o protótipo do Erlon é claro) e escuro como opção. A chave
// do localStorage é PRÓPRIA (`ecf-trilha-tema`), não a `ecf-theme` do sistema:
// trocar o tema aqui não pode jogar o resto do sistema no modo claro dele, que
// é um remendo cheio de tela torta. As cores estão em `resources/css/trilha.css`.

const CHAVE_TEMA = 'ecf-trilha-tema';

function temaSalvo() {
    try {
        return localStorage.getItem(CHAVE_TEMA) === 'escuro' ? 'escuro' : 'claro';
    } catch {
        return 'claro';
    }
}

/**
 * @param {{
 *   titulo: string,
 *   empresa?: {nome: string, logo_url?: ?string} | null,
 *   voltar?: {url: string, rotulo: string} | null,
 *   children: React.ReactNode,
 * }} props
 */
export default function TrilhaLayout({ titulo, empresa = null, voltar = null, children }) {
    const [tema, setTema] = useState(temaSalvo);

    // O <body> do sistema é escuro: sem isto, a rolagem elástica do celular e a
    // borda de baixo de página curta mostrariam a faixa escura por trás.
    // A marca no <html> é para a barra de rolagem da PÁGINA (a do <html>, fora do
    // `.trilha`) ganhar as cores da trilha — ver `resources/css/trilha.css`.
    useEffect(() => {
        const raiz = document.documentElement;
        const antes = document.body.style.backgroundColor;
        document.body.style.backgroundColor = tema === 'escuro' ? '#111214' : '#ffffff';
        raiz.dataset.trilhaTema = tema;

        return () => {
            document.body.style.backgroundColor = antes;
            delete raiz.dataset.trilhaTema;
        };
    }, [tema]);

    const trocarTema = () => {
        const novo = tema === 'escuro' ? 'claro' : 'escuro';
        setTema(novo);
        try {
            localStorage.setItem(CHAVE_TEMA, novo);
        } catch {
            // Sem localStorage (aba anônima bloqueada): troca só nesta visita.
        }
    };

    return (
        <div className="trilha min-h-screen font-sans text-[14px] leading-normal" data-tema={tema}>
            <Head title={titulo} />
            <div className="trilha-gradiente fixed inset-x-0 top-0 z-30 h-[3px]" aria-hidden="true" />

            <header className="border-b border-trilha-line bg-trilha-surface">
                <div className="mx-auto flex max-w-[1360px] flex-wrap items-center justify-between gap-3 px-4 pb-3 pt-4 sm:px-6">
                    <div className="flex min-w-0 items-center gap-3">
                        <img
                            src={tema === 'escuro' ? '/images/logo-ecf-escuro.svg' : '/images/logo-ecf-claro.svg'}
                            alt="ECF"
                            className="h-7 w-auto shrink-0"
                        />
                        <span className="hidden h-6 w-px bg-trilha-line sm:block" aria-hidden="true" />
                        <div className="min-w-0">
                            <div className="truncate text-[13px] font-semibold text-trilha-fg">Trilha de produtos</div>
                            {empresa && <div className="truncate text-[12px] text-trilha-muted">{empresa.nome}</div>}
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={trocarTema}
                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-trilha-line bg-trilha-surface text-trilha-fg2 hover:border-trilha-muted focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-trilha-run"
                            aria-label={tema === 'escuro' ? 'Usar tema claro' : 'Usar tema escuro'}
                            title={tema === 'escuro' ? 'Tema claro' : 'Tema escuro'}
                        >
                            {tema === 'escuro' ? <Sun size={15} /> : <Moon size={15} />}
                        </button>
                        {voltar && (
                            <Link
                                href={voltar.url}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-trilha-line bg-trilha-surface px-3 py-1.5 text-[13px] font-semibold text-trilha-fg hover:border-trilha-muted"
                            >
                                <ArrowLeft size={14} />
                                {voltar.rotulo}
                            </Link>
                        )}
                    </div>
                </div>
            </header>

            <main className={cn('mx-auto max-w-[1360px] px-4 pb-16 pt-5 sm:px-6')}>{children}</main>
        </div>
    );
}
