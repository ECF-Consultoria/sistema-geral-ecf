import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Store, RefreshCw, Rocket, FileText, Search, PackageCheck, Loader2 } from 'lucide-react';

// ─── Estado do token ML da empresa ───
const TOKEN_BADGE = {
    ativo:    'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400',
    expirado: 'bg-red-500/10 border border-red-500/30 text-red-400',
};
const TOKEN_LABEL = {
    ativo:    'Conta ML ativa',
    expirado: 'Reconectar conta',
};

function TokenBadge({ tokenExpirado }) {
    const key = tokenExpirado ? 'expirado' : 'ativo';
    return (
        <span className={cn(
            'inline-block px-2 py-0.5 rounded text-[10px] font-medium border',
            TOKEN_BADGE[key],
        )}>
            {TOKEN_LABEL[key]}
        </span>
    );
}

/**
 * Painel de cards de empresas — Momento 1 do módulo "Anunciar ML".
 *
 * Fonte: `companies` com conta ML conectada (ml_token) — o que "pode publicar" de
 * fato. Um card por empresa conectada, com estado do token e nº de rascunhos abertos.
 * Clicar no card abre o wizard com a empresa (company) fixada.
 *
 * Escopo por publicador está deferido: sob o gate role:admin todas as conectadas
 * aparecem. `tem_dados_cliente` marca as que têm planilha do cliente vinculada
 * (habilita pré-preenchimento na Phase 76).
 */
export default function AnunciosEmpresas({ empresas = [] }) {
    const [busca, setBusca] = useState('');

    function abrirWizard(empresa) {
        router.get(route('mlb.anuncios.wizard', { company: empresa.id }));
    }

    const filtradas = useMemo(() => {
        const q = busca.trim().toLowerCase();
        if (!q) return empresas;
        return empresas.filter((e) => (e.nome ?? '').toLowerCase().includes(q));
    }, [empresas, busca]);

    return (
        <AppLayout title="Anunciar no Mercado Livre">
            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

                {/* Cabeçalho */}
                <div className="mb-6 flex items-center gap-3">
                    <Rocket className="h-6 w-6 text-ecf-yellow" />
                    <div>
                        <h1 className="text-xl font-semibold text-white">Anunciar no Mercado Livre</h1>
                        <p className="text-sm text-white/40">
                            Selecione a empresa (conta ML conectada) para criar ou continuar um anúncio.
                        </p>
                    </div>
                </div>

                {/* Busca */}
                {empresas.length > 0 && (
                    <div className="mb-5 flex items-center gap-2 rounded-xl border border-white/[0.08] bg-ecf-bg px-3 py-2">
                        <Search className="h-4 w-4 text-white/30" />
                        <input
                            value={busca}
                            onChange={(e) => setBusca(e.target.value)}
                            placeholder="Buscar empresa…"
                            className="w-full bg-transparent text-sm text-white placeholder-white/30 focus:outline-none"
                        />
                        <span className="shrink-0 text-[11px] text-white/30">
                            {filtradas.length} de {empresas.length}
                        </span>
                    </div>
                )}

                {/* Grid de cards */}
                {empresas.length === 0 ? (
                    <div className="card-ecf rounded-2xl p-10 text-center text-white/40">
                        Nenhuma empresa com conta ML conectada.
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {filtradas.map((e) => (
                            <button
                                key={e.id}
                                onClick={() => abrirWizard(e)}
                                className={cn(
                                    'text-left rounded-2xl border p-4 transition',
                                    'border-white/[0.06] bg-ecf-card/60',
                                    'hover:border-white/20 hover:bg-white/[0.04] cursor-pointer',
                                )}
                            >
                                {/* Nome + ícone */}
                                <div className="mb-2 flex items-start gap-2">
                                    <Store className="mt-0.5 h-4 w-4 shrink-0 text-white/40" />
                                    <span className="text-sm font-medium leading-tight text-white">
                                        {e.nome}
                                    </span>
                                </div>

                                {/* Badge de estado do token */}
                                <div className="mb-3 flex items-center gap-1">
                                    <TokenBadge tokenExpirado={e.token_expirado} />
                                    {e.token_expirado && (
                                        <RefreshCw className="h-3 w-3 text-red-400" />
                                    )}
                                </div>

                                {/* Marcadores */}
                                <div className="space-y-1">
                                    {e.tem_dados_cliente && (
                                        <div className="flex items-center gap-1 text-[11px] text-violet-300/80">
                                            <PackageCheck className="h-3 w-3" />
                                            <span>dados do cliente disponíveis</span>
                                        </div>
                                    )}
                                    {e.rascunhos_abertos > 0 && (
                                        <div className="flex items-center gap-1 text-[11px] text-white/50">
                                            <FileText className="h-3 w-3" />
                                            <span>{e.rascunhos_abertos} rascunho{e.rascunhos_abertos !== 1 ? 's' : ''} em aberto</span>
                                        </div>
                                    )}
                                    {/* BULK-04: contador de publicações em andamento — atualiza a cada reload do painel */}
                                    {e.publicando_count > 0 && (
                                        <div className="flex items-center gap-1 text-[11px] text-ecf-yellow/80">
                                            <Loader2 className="h-3 w-3 animate-spin" />
                                            <span>{e.publicando_count} publicando…</span>
                                        </div>
                                    )}
                                </div>

                                {/* CTA */}
                                <div className="mt-3">
                                    <span className="text-[11px] text-ecf-yellow/80">anunciar →</span>
                                </div>
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
