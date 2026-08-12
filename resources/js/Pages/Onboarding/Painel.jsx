import { useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import StatChip from '@/Components/StatChip';
import EmpresaCard from '@/Components/Onboarding/Painel/EmpresaCard';

// Contadores clicáveis do topo (SC-11: nenhum deles é o elemento visual
// central — são atalhos de filtro, não a resposta da tela). `tone` usa a
// paleta genérica do StatChip (red/amber/green/yellow/neutral) — não precisa
// bater 1:1 com a cor do SituacaoChip por onboarding, que tem paleta própria.
const CONTADORES = [
    { key: 'vencido', label: 'Vencidos', tone: 'red' },
    { key: 'aguardando_cliente', label: 'Aguardando cliente', tone: 'amber' },
    { key: 'aguardando_interno', label: 'Aguardando interno', tone: 'amber' },
    { key: 'coletando', label: 'Coletando', tone: 'neutral' },
    { key: 'rascunho', label: 'Rascunhos', tone: 'neutral' },
];

/**
 * Onboarding/Painel — Tela 1 (Fase 135, Plano 12). Responde "o que está
 * travando, há quantos dias e de quem é a bola" (SC-11) — rejeição explícita
 * do `feitos/total` do Polos. Nenhuma barra de andamento é usada aqui.
 *
 * Payload vem pronto do `OnboardingController::index()` (Plano 09): situação
 * já classificada, dias já contados de `disponivel_em`. Este componente é
 * apresentação + filtro local, nenhum cálculo de domínio.
 */
export default function Painel({ empresas, usuarios }) {
    const [filtro, setFiltro] = useState(null);

    const contagem = useMemo(() => {
        const c = { vencido: 0, aguardando_cliente: 0, aguardando_interno: 0, coletando: 0, rascunho: 0 };
        empresas.forEach((e) => e.onboardings.forEach((o) => {
            if (Object.prototype.hasOwnProperty.call(c, o.situacao)) c[o.situacao] += 1;
        }));
        return c;
    }, [empresas]);

    const empresasFiltradas = useMemo(() => {
        if (!filtro) return empresas;
        return empresas
            .map((e) => ({ ...e, onboardings: e.onboardings.filter((o) => o.situacao === filtro) }))
            .filter((e) => e.onboardings.length > 0);
    }, [empresas, filtro]);

    const toggleFiltro = (key) => setFiltro((f) => (f === key ? null : key));

    return (
        <AppLayout title="Onboarding">
            <Head title="Onboarding" />

            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <h2 className="text-white font-display font-bold text-2xl tracking-tight flex items-center gap-2">
                            <ListChecks size={22} className="text-ecf-yellow" />
                            Onboarding
                        </h2>
                        <p className="text-white/40 text-[13px] mt-1">
                            O que está travando, há quantos dias e de quem é a bola.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {CONTADORES.map((c) => (
                            <StatChip
                                key={c.key}
                                label={c.label}
                                count={contagem[c.key]}
                                tone={c.tone}
                                active={filtro === c.key}
                                onClick={() => toggleFiltro(c.key)}
                            />
                        ))}
                    </div>
                </div>

                {empresas.length === 0 ? (
                    <div className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-12 text-center">
                        <h3 className="text-white font-display font-bold text-lg">Nenhum onboarding em andamento</h3>
                        <p className="text-white/40 text-[13px] mt-2 max-w-md mx-auto">
                            Onboardings nascem automaticamente quando um contrato de serviço é criado. Assim que o
                            primeiro chegar, ele aparece aqui.
                        </p>
                    </div>
                ) : empresasFiltradas.length === 0 ? (
                    <div className="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-8 text-center text-white/40 text-[13px]">
                        Nenhum onboarding nesta situação.
                    </div>
                ) : (
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        {empresasFiltradas.map((e) => (
                            <EmpresaCard
                                key={e.empresa.id}
                                empresa={e.empresa}
                                onboardings={e.onboardings}
                                usuarios={usuarios}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
