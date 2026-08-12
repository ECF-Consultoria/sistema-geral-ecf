import { AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/utils';

// Chip único de situação (SC-11) — 6 valores do vocabulário próprio desta
// fase, nunca porcentagem. Nenhum cálculo de situação aqui: o backend já
// classifica (`OnboardingController::situacaoDe()`, Plano 09) — este
// componente só apresenta o tom certo para `situacao`/`situacao_label` que
// chegam prontos do payload. Molde de FORMA inspirado em
// `Polos/Painel.jsx:107-126` (situacaoDe()/SITUACAO_LABEL), rótulos e regras
// são outros (D-02 não permite reuso de código de Polos).
const TONS = {
    rascunho: 'text-white/30 bg-white/[0.04] border-dashed border-white/15',
    vencido: 'text-red-300 bg-red-500/10 border-red-500/20',
    aguardando_cliente: 'text-amber-300 bg-amber-500/10 border-amber-500/20',
    aguardando_interno: 'text-amber-300 bg-amber-500/10 border-amber-500/20',
    aguardando_sistema: 'text-amber-300 bg-amber-500/10 border-amber-500/20',
    coletando: 'text-sky-300 bg-sky-500/10 border-sky-500/20',
    // "esmeralda com aviso" — mesmo tom de concluído, mas com o ícone de
    // atenção para nunca ser confundido com "Concluído" na varredura visual
    // (D-15: bloqueio administrativo é uma categoria própria).
    pronto_para_concluir: 'text-emerald-300 bg-emerald-500/10 border-emerald-500/20',
    concluido: 'text-emerald-300 bg-emerald-500/10 border-emerald-500/20',
};

export default function SituacaoChip({ situacao, label }) {
    const tom = TONS[situacao] ?? TONS.coletando;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold whitespace-nowrap',
                tom
            )}
        >
            {situacao === 'pronto_para_concluir' && <AlertTriangle size={11} className="shrink-0" />}
            {label}
        </span>
    );
}
