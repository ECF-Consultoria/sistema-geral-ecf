import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Store, RefreshCw, Rocket, FileText, Search, PackageCheck, Loader2, Copy, Check } from 'lucide-react';

// ─── Estado do token ML da empresa ───
const TOKEN_BADGE = {
    ativo:         'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400',
    expirado:      'bg-red-500/10 border border-red-500/30 text-red-400',
    // Empresa de Polos que autorizou ANTES de 21/09/2026, quando o callback
    // descartava o token. Autorizou de verdade — só não sobrou a credencial.
    sem_token:     'bg-amber-500/10 border border-amber-500/30 text-amber-300',
};
const TOKEN_LABEL = {
    ativo:     'Conta ML ativa',
    expirado:  'Reconectar conta',
    sem_token: 'Autorizada — falta reconectar',
};

function TokenBadge({ tokenExpirado, temToken = true }) {
    const key = !temToken ? 'sem_token' : (tokenExpirado ? 'expirado' : 'ativo');
    return (
        <span className={cn(
            'inline-block px-2 py-0.5 rounded text-[10px] font-medium border',
            TOKEN_BADGE[key],
        )}>
            {TOKEN_LABEL[key]}
        </span>
    );
}

// Data do carimbo de autorização (ISO) em pt-BR curto.
function fmtData(iso) {
    if (!iso) return null;
    const d = new Date(iso);
    return Number.isNaN(d.getTime())
        ? null
        : d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/**
 * Card da empresa. Vira <button> só quando dá para publicar de verdade.
 *
 * Empresa de Polos ainda não abre o wizard: o controller resolve `{company}`
 * por binding de Company, e o acervo segue ancorado em company_id. Card que
 * abre quebrado é pior que card que se explica.
 */
function CardEmpresa({ empresa, onAbrir, children }) {
    const base = 'text-left rounded-2xl border p-4 transition border-white/[0.06] bg-ecf-card/60';

    if (!empresa.pode_publicar) {
        return <div className={cn(base, 'opacity-90')}>{children}</div>;
    }

    return (
        <button onClick={onAbrir} className={cn(base, 'hover:border-white/20 hover:bg-white/[0.04] cursor-pointer')}>
            {children}
        </button>
    );
}

/**
 * Rodapé do card de Polos: quando autorizou e como reconectar.
 *
 * O link é o mesmo link público do Onboarding que o cliente já recebeu — não
 * é um caminho novo. E precisa ser o NAVEGADOR DO CLIENTE: quem clica é quem
 * tem a sessão do ML, e um clique interno da ECF carimbaria a conta errada
 * (foi o que aconteceu com a Masitto em 27/08).
 */
function RodapePolos({ empresa }) {
    const [copiado, setCopiado] = useState(false);
    const data = fmtData(empresa.autorizado_em);

    async function copiar() {
        try {
            await navigator.clipboard.writeText(empresa.link_reconexao);
            setCopiado(true);
            setTimeout(() => setCopiado(false), 2000);
        } catch {
            setCopiado(false);
        }
    }

    return (
        <div className="space-y-1.5">
            {data && (
                <div className="text-[11px] text-white/35">
                    autorizou em {data}
                </div>
            )}
            {empresa.tem_token ? (
                <div className="text-[11px] text-white/35">publicação em breve</div>
            ) : empresa.link_reconexao ? (
                <button
                    type="button"
                    onClick={copiar}
                    className="flex items-center gap-1 text-[11px] text-ecf-yellow/80 hover:text-ecf-yellow"
                >
                    {copiado ? <Check className="h-3 w-3" /> : <Copy className="h-3 w-3" />}
                    <span>{copiado ? 'link copiado' : 'copiar link de reconexão'}</span>
                </button>
            ) : (
                <div className="text-[11px] text-white/35">sem ficha — não há link</div>
            )}
        </div>
    );
}

/**
 * Painel de cards de empresas — Momento 1 do módulo "Anunciar ML".
 *
 * DUAS fontes: `companies` com token (publicam de fato) e empresas de Polos que
 * autorizaram o OAuth do ML. As de Polos que autorizaram antes de 21/09/2026
 * aparecem como "Autorizada — falta reconectar": naquela época o callback
 * descartava o token, então a autorização foi real mas a credencial não existe.
 *
 * Só o card que pode publicar é clicável (`pode_publicar`) — ver `CardEmpresa`.
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
                            Selecione a empresa para criar ou continuar um anúncio. Empresas de Polos
                            que autorizaram o Mercado Livre aparecem aqui com o estado da conexão.
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
                            <CardEmpresa
                                key={e.id}
                                empresa={e}
                                onAbrir={() => abrirWizard(e)}
                            >
                                {/* Nome + ícone */}
                                <div className="mb-2 flex items-start gap-2">
                                    <Store className="mt-0.5 h-4 w-4 shrink-0 text-white/40" />
                                    <span className="text-sm font-medium leading-tight text-white">
                                        {e.nome}
                                    </span>
                                    {e.origem === 'polos' && (
                                        <span className="ml-auto shrink-0 rounded bg-white/[0.06] px-1.5 py-0.5 text-[9px] uppercase tracking-wide text-white/40">
                                            Polos
                                        </span>
                                    )}
                                </div>

                                {/* Badge de estado do token */}
                                <div className="mb-3 flex items-center gap-1">
                                    <TokenBadge tokenExpirado={e.token_expirado} temToken={e.tem_token} />
                                    {e.token_expirado && e.tem_token && (
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
                                    {e.pode_publicar ? (
                                        <span className="text-[11px] text-ecf-yellow/80">anunciar →</span>
                                    ) : (
                                        <RodapePolos empresa={e} />
                                    )}
                                </div>
                            </CardEmpresa>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
