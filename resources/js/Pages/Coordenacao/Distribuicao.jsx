import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Users, Info, Inbox } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Coordenacao/Distribuicao.jsx — a fila da Coordenação (Fase 154).
 *
 * Componente REAL, nunca re-export puro — arquivo que só reexporta sai do
 * manifest do Vite e a rota morre em runtime sem falhar o build.
 */

/**
 * Select nativo, não Radix: `value=""` em Radix Select deixa a tela PRETA
 * (registrado em `project_radix_select_empty_value`), e aqui o estado inicial é
 * justamente "nada escolhido".
 */
function SelectPessoa({ label, valor, aoMudar, opcoes, disabled }) {
    return (
        <label className="block">
            <span className="text-[11px] uppercase tracking-wide text-white/40">{label}</span>
            <select
                value={valor}
                onChange={(e) => aoMudar(e.target.value)}
                disabled={disabled || opcoes.length === 0}
                className={cn(
                    'mt-1 w-full h-9 px-3 rounded-lg border border-white/10 bg-white/[0.03]',
                    'text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40',
                    'disabled:opacity-50'
                )}
            >
                <option value="" className="bg-[#0f1116]">
                    {opcoes.length === 0 ? 'Ninguém habilitado' : 'Selecione…'}
                </option>
                {opcoes.map((o) => (
                    <option key={o.id} value={o.id} className="bg-[#0f1116]">
                        {o.name}
                    </option>
                ))}
            </select>
        </label>
    );
}

function LinhaEmpresa({ empresa }) {
    const [analista, setAnalista] = useState('');
    const [estrategista, setEstrategista] = useState('');
    const [enviando, setEnviando] = useState(false);

    const podeConfirmar = analista !== '' && estrategista !== '' && !enviando;

    const confirmar = () => {
        setEnviando(true);
        router.post(
            route('coordenacao.distribuicao.distribuir', empresa.id),
            { analista_id: analista, estrategista_id: estrategista },
            { preserveScroll: true, onFinish: () => setEnviando(false) }
        );
    };

    return (
        <Card>
            <CardContent className="p-4 space-y-3">
                <div>
                    <h3 className="text-white font-semibold text-[14px]">{empresa.name}</h3>
                    <p className="text-[12px] text-white/40">
                        {empresa.cnpj ?? 'sem CNPJ'}
                        {empresa.servicos.length > 0 && ` · ${empresa.servicos.join(', ')}`}
                    </p>
                </div>

                {/* A lista foi ABERTA para todos os setores — dizer por quê é
                    obrigatório: select que muda de universo em silêncio faz o
                    coordenador achar que aquele é o time daquele serviço. */}
                {empresa.motivo_abertura && (
                    <div className="flex items-start gap-2 rounded-lg border border-amber-500/20 bg-amber-500/[0.07] px-3 py-2">
                        <Info size={13} className="text-amber-300 shrink-0 mt-0.5" />
                        <p className="text-[12px] text-amber-300/90">{empresa.motivo_abertura}</p>
                    </div>
                )}

                <div className="grid gap-3 sm:grid-cols-2">
                    <SelectPessoa
                        label="Analista"
                        valor={analista}
                        aoMudar={setAnalista}
                        opcoes={empresa.analistas}
                        disabled={enviando}
                    />
                    <SelectPessoa
                        label="Estrategista"
                        valor={estrategista}
                        aoMudar={setEstrategista}
                        opcoes={empresa.estrategistas}
                        disabled={enviando}
                    />
                </div>

                <div className="flex items-center justify-between gap-3 flex-wrap pt-1">
                    <p className="text-[11px] text-white/30">
                        Confirmar vincula os dois em todos os serviços ativos e move a empresa para
                        Aguardando Onboarding.
                    </p>
                    <Button size="sm" onClick={confirmar} disabled={!podeConfirmar}>
                        {enviando ? 'Distribuindo…' : 'Confirmar distribuição'}
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

export default function Distribuicao({ empresas = [] }) {
    const { flash } = usePage().props;

    return (
        <AppLayout title="Coordenação · Distribuição">
            <main className="p-6">
                <div className="space-y-6 max-w-4xl">
                    <div>
                        <h1 className="text-xl font-semibold font-display text-white flex items-center gap-2">
                            <Users size={20} className="text-ecf-yellow" />
                            Distribuição
                        </h1>
                        <p className="text-[13px] text-white/50 mt-1">
                            Empresas que concluíram o Administrativo e ainda não têm analista e estrategista definidos.
                        </p>
                    </div>

                    {flash?.success && (
                        <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-[13px] text-emerald-300">
                            {flash.success}
                        </div>
                    )}
                    {flash?.error && (
                        <div className="rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-[13px] text-red-300">
                            {flash.error}
                        </div>
                    )}

                    {empresas.length === 0 ? (
                        <Card>
                            <CardContent className="p-8 text-center">
                                <Inbox size={28} className="text-white/20 mx-auto mb-3" />
                                <p className="text-[13px] text-white/60 font-semibold">
                                    Nenhuma empresa aguardando distribuição.
                                </p>
                                <p className="text-[12px] text-white/30 mt-1">
                                    Elas aparecem aqui assim que o Administrativo finaliza a entrada.
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="space-y-4">
                            {empresas.map((e) => (
                                <LinhaEmpresa key={e.id} empresa={e} />
                            ))}
                        </div>
                    )}
                </div>
            </main>
        </AppLayout>
    );
}
