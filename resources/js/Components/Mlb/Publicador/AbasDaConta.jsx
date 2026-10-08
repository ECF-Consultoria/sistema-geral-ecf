import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';

// D23: sem Company (MlbEmpresa solta), a aba Publicações fica desabilitada,
// NUNCA escondida — mesmo texto literal usado em ModoAnuncioTabs.jsx (há
// teste de fonte que procura essa string; não variar).
const TITLE_SEM_COMPANY = 'Disponível só para empresas cadastradas no sistema';

/**
 * Um só nível de abas da conta do Publicador — Produtos · Publicações ·
 * Alavancas — no lugar de `AreaTabs` + `ModoAnuncioTabs`. Troca de ROTA
 * (router.get), como o padrão atual; Publicações decide sozinha se está
 * habilitada (D23). Este plano só cria o componente, sem consumidor ainda.
 */
export default function AbasDaConta({ aba, conta, companyId = null, contagemProdutos = null, subPublicacoes = null }) {
    const contaSegura = conta ?? null;
    const companyIdSeguro = companyId ?? null;

    const ABAS = [
        { chave: 'produtos', rotulo: 'Produtos', rota: 'mlb.anuncios.publicador.produtos', parametros: { conta: contaSegura } },
        { chave: 'publicacoes', rotulo: 'Publicações', rota: 'mlb.anuncios.meus', parametros: { company: companyIdSeguro } },
        { chave: 'alavancas', rotulo: 'Alavancas', rota: 'mlb.anuncios.publicador.alavancas.index', parametros: { conta: contaSegura } },
    ];

    return (
        <div>
            <nav aria-label="Abas da conta" className="flex gap-1 border-b border-white/[0.08]">
                {ABAS.map((item) => {
                    // Flags calculadas DENTRO do callback do map — variável de
                    // escopo do componente usada só dentro de .map() já foi
                    // eliminada pelo Rollup no bundle de produção neste projeto.
                    const ativa = item.chave === aba;
                    const desabilitada = item.chave === 'publicacoes' && companyIdSeguro === null;

                    function aoClicar() {
                        if (ativa || desabilitada) return;
                        router.get(route(item.rota, item.parametros));
                    }

                    return (
                        <button
                            key={item.chave}
                            type="button"
                            aria-current={ativa ? 'page' : undefined}
                            aria-disabled={desabilitada ? 'true' : undefined}
                            title={desabilitada ? TITLE_SEM_COMPANY : undefined}
                            onClick={aoClicar}
                            className={cn(
                                'inline-flex h-11 items-center gap-2 rounded-t-lg px-4 text-[15px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                                desabilitada && 'cursor-not-allowed opacity-40 hover:bg-transparent',
                                !desabilitada && ativa && 'border-b-2 border-ecf-yellow bg-white/[0.08] font-bold text-white',
                                !desabilitada && !ativa && 'font-normal text-white/70 hover:bg-white/[0.06]',
                            )}
                        >
                            {item.rotulo}
                            {item.chave === 'produtos' && contagemProdutos !== null && (
                                <span className="font-mono text-[11px] tabular-nums">{contagemProdutos}</span>
                            )}
                        </button>
                    );
                })}
            </nav>

            {aba === 'publicacoes' && companyIdSeguro !== null && (
                <div
                    role="radiogroup"
                    aria-label="Modo de publicações"
                    className="mt-2 inline-flex h-10 items-center gap-1 rounded-lg border border-white/[0.08] bg-ecf-card p-1"
                >
                    <button
                        type="button"
                        role="radio"
                        aria-checked={subPublicacoes === 'meus'}
                        onClick={() => router.get(route('mlb.anuncios.meus', { company: companyIdSeguro }))}
                        className={cn(
                            'inline-flex h-8 items-center rounded-md border px-3 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                            subPublicacoes === 'meus'
                                ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                : 'border-transparent text-white/70 hover:bg-white/[0.04]',
                        )}
                    >
                        No ar
                    </button>
                    <button
                        type="button"
                        role="radio"
                        aria-checked={subPublicacoes === 'historico'}
                        onClick={() => router.get(route('mlb.anuncios.historico', { company: companyIdSeguro }))}
                        className={cn(
                            'inline-flex h-8 items-center rounded-md border px-3 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                            subPublicacoes === 'historico'
                                ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                : 'border-transparent text-white/70 hover:bg-white/[0.04]',
                        )}
                    >
                        Histórico
                    </button>
                </div>
            )}
        </div>
    );
}
