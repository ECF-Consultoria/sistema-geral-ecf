import AppLayout from '@/Layouts/AppLayout';
import { Banknote, Wrench } from 'lucide-react';

export default function AdminFinanceiro() {
    return (
        <AppLayout title="Financeiro">
            <EmDesenvolvimento
                icon={Banknote}
                titulo="Financeiro"
                descricao="Controle financeiro, fluxo de caixa, faturamento e pagamentos."
            />
        </AppLayout>
    );
}

function EmDesenvolvimento({ icon: Icon, titulo, descricao }) {
    return (
        <div className="flex items-center justify-center min-h-[60vh]">
            <div className="text-center space-y-5 max-w-sm">
                <div className="relative inline-flex">
                    <div className="w-20 h-20 rounded-2xl bg-white/[0.04] border border-white/[0.08] flex items-center justify-center">
                        <Icon size={32} className="text-white/20" />
                    </div>
                    <div className="absolute -top-2 -right-2 w-7 h-7 rounded-full bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center">
                        <Wrench size={13} className="text-ecf-yellow/60" />
                    </div>
                </div>
                <div className="space-y-1.5">
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-ecf-yellow/10 text-ecf-yellow border border-ecf-yellow/20 uppercase tracking-wider">
                        Em desenvolvimento
                    </span>
                    <h2 className="text-white font-display font-bold text-xl">{titulo}</h2>
                    <p className="text-white/40 text-[13px] leading-relaxed">{descricao}</p>
                </div>
                <p className="text-white/20 text-[12px]">Esta área estará disponível em breve.</p>
            </div>
        </div>
    );
}
