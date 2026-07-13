import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Store, AlertTriangle, RefreshCw, Rocket, FileText } from 'lucide-react';

// ─── Estado do token ML da empresa ───
const TOKEN_BADGE = {
    ativo:     'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400',
    expirado:  'bg-red-500/10 border border-red-500/30 text-red-400',
    sem_conta: 'bg-white/5 border border-white/15 text-white/40',
};
const TOKEN_LABEL = {
    ativo:     'Conta ML ativa',
    expirado:  'Reconectar conta',
    sem_conta: 'sem conta ML',
};

function TokenBadge({ temToken, tokenExpirado }) {
    const key = !temToken ? 'sem_conta' : tokenExpirado ? 'expirado' : 'ativo';
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
 * Painel de cards de empresas MLB — Momento 1 do módulo "Anunciar ML".
 *
 * Exibe um card por empresa com estado da conta ML (tem_token / token_expirado)
 * e número de rascunhos abertos. Clicar no card abre o wizard com a empresa fixada.
 * Empresas sem token aparecem com badge "sem conta ML" e card desabilitado.
 *
 * SEL-01: painel separado do wizard
 * SEL-02: escopo imposto no backend por responsavel_id (publicador vê só as suas)
 * SEL-06: empresa sem token exibida com tem_token=false, não ocultada
 */
export default function AnunciosEmpresas({ empresas }) {
    function abrirWizard(empresa) {
        router.get(route('mlb.anuncios.wizard', { mlbEmpresa: empresa.id }));
    }

    return (
        <AppLayout title="Anunciar no Mercado Livre">
            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

                {/* Cabeçalho */}
                <div className="mb-6 flex items-center gap-3">
                    <Rocket className="h-6 w-6 text-ecf-yellow" />
                    <div>
                        <h1 className="text-xl font-semibold text-white">Anunciar no Mercado Livre</h1>
                        <p className="text-sm text-white/40">
                            Selecione a empresa para criar ou continuar um anúncio.
                        </p>
                    </div>
                </div>

                {/* Grid de cards */}
                {empresas.length === 0 ? (
                    <div className="card-ecf rounded-2xl p-10 text-center text-white/40">
                        Nenhuma empresa encontrada.
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {empresas.map((e) => (
                            <button
                                key={e.id}
                                onClick={() => abrirWizard(e)}
                                disabled={!e.tem_token}
                                className={cn(
                                    'text-left rounded-2xl border p-4 transition',
                                    'border-white/[0.06] bg-ecf-card/60',
                                    e.tem_token && !e.token_expirado
                                        ? 'hover:border-white/20 hover:bg-white/[0.04] cursor-pointer'
                                        : 'opacity-60 cursor-not-allowed',
                                )}
                            >
                                {/* Nome + ícone */}
                                <div className="mb-2 flex items-start justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        <Store className="h-4 w-4 shrink-0 text-white/40" />
                                        <span className="text-sm font-medium text-white leading-tight">
                                            {e.nome}
                                        </span>
                                    </div>
                                </div>

                                {/* Badge de estado do token */}
                                <div className="mb-3">
                                    <TokenBadge temToken={e.tem_token} tokenExpirado={e.token_expirado} />
                                    {e.token_expirado && (
                                        <RefreshCw className="inline-block ml-1 h-3 w-3 text-red-400" />
                                    )}
                                    {!e.tem_token && (
                                        <AlertTriangle className="inline-block ml-1 h-3 w-3 text-white/30" />
                                    )}
                                </div>

                                {/* Rascunhos abertos */}
                                {e.rascunhos_abertos > 0 && (
                                    <div className="mb-2 flex items-center gap-1 text-[11px] text-white/50">
                                        <FileText className="h-3 w-3" />
                                        <span>{e.rascunhos_abertos} rascunho{e.rascunhos_abertos !== 1 ? 's' : ''} em aberto</span>
                                    </div>
                                )}

                                {/* CTA */}
                                {e.tem_token && !e.token_expirado && (
                                    <div className="mt-3 text-[11px] text-ecf-yellow/80">anunciar →</div>
                                )}
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
