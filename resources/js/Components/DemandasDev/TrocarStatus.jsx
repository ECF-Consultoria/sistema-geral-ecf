import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ChevronDown, Loader2 } from 'lucide-react';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { comecaria, STATUS_LABELS } from '@/lib/demandasDev';
import { STATUS_COR, StatusSelo } from './Selos';

/**
 * Status em um clique — a linha do tempo sai das mudanças de status, então mudar
 * precisa ser barato e acontecer no dia. Grava uma atualização com a data de hoje,
 * mantendo a próxima ação vigente.
 *
 * Quando a mudança pede algo que só a pessoa sabe, abre o formulário já no status
 * escolhido: concluir (o que foi entregue), bloquear (o motivo), cancelar e começar
 * pela primeira vez (o prazo de entrega).
 */
export default function TrocarStatus({ demanda, hoje, podeAtualizar, onFormulario, className }) {
    const [enviando, setEnviando] = useState(false);

    if (!podeAtualizar) return <StatusSelo status={demanda.status} className={className} />;

    const precisaFormulario = (status) =>
        ['concluido', 'cancelado', 'bloqueado'].includes(status) || comecaria(demanda, status);

    const escolher = (status) => {
        if (precisaFormulario(status)) {
            onFormulario(demanda, status);
            return;
        }
        router.post(route('dev.demandas.atualizacoes.store', demanda.id), {
            data:              hoje,
            status,
            feito:             null,
            proxima_acao:      demanda.proxima_acao ?? null,
            // Mudar para um status que não é bloqueio destrava.
            bloqueado:         false,
            motivo_bloqueio:   null,
            previsao_revisada: null,
        }, {
            preserveScroll: true,
            preserveState:  true,
            onStart:        () => setEnviando(true),
            onFinish:       () => setEnviando(false),
        });
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    onClick={(e) => e.stopPropagation()}
                    disabled={enviando}
                    title="Mudar o status hoje"
                    className={cn(
                        '-mx-1.5 inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 transition-colors hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40',
                        className,
                    )}
                >
                    <StatusSelo status={demanda.status} />
                    {enviando ? <Loader2 size={12} className="animate-spin text-white/40" /> : <ChevronDown size={12} className="text-white/40" />}
                </button>
            </DropdownMenuTrigger>
            {/* O conteúdo vai para um portal, mas o clique ainda borbulha pela árvore React até a linha. */}
            <DropdownMenuContent align="start" onClick={(e) => e.stopPropagation()} className="min-w-[13rem] border-white/[0.08] bg-ecf-card-2">
                <DropdownMenuLabel className="text-[11px] font-medium uppercase tracking-wider text-white/40">Mudar status hoje</DropdownMenuLabel>
                {Object.entries(STATUS_LABELS).map(([valor, rotulo]) => {
                    // Mesmo status: só faz sentido para destravar uma demanda bloqueada pela caixinha.
                    const atual = valor === demanda.status && !(demanda.bloqueado && valor !== 'bloqueado');
                    return (
                        <DropdownMenuItem
                            key={valor}
                            disabled={atual}
                            onSelect={() => escolher(valor)}
                            className="gap-2 text-[13px] text-white/80 focus:bg-white/[0.06] focus:text-white"
                        >
                            <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', STATUS_COR[valor])} />
                            {rotulo}
                            {atual && <span className="ml-auto text-[11px] text-white/35">atual</span>}
                            {!atual && precisaFormulario(valor) && <span className="ml-auto text-[11px] text-white/35">…</span>}
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
