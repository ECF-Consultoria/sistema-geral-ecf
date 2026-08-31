import { ShieldCheck, ShieldAlert, AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Estado da autorização do Mercado Livre pelo link do Onboarding — o
 * `{link_oauth}` da mensagem de boas-vindas.
 *
 * Fonte: `MlbImplementacao::oauthMl()`, carimbado no callback do OAuth. NÃO é
 * o Grant (aquele é um link por polo e não diz quem autorizou) e NÃO se deduz
 * de Cust ID preenchido — Cust ID entra à mão em muitas empresas.
 *
 * Empresa sem carimbo aparece como "Não autorizou" mesmo tendo conta ML: o
 * link só passou a existir em 27/08/2026 e quem autorizou antes disso não
 * deixou registro.
 */

/** Pill compacto para tabela/card. Três estados — divergente NÃO é autorizado. */
export function MlOauthBadge({ oauth, className }) {
    const divergente = !!oauth?.divergente;
    const conectado  = !!oauth?.conectado && !divergente;

    const titulo = divergente
        ? `Alguém clicou no link em ${oauth.autorizado_em}, mas autorizou com a conta ${oauth.nickname || oauth.cust_id} — que não é a desta empresa. O Cust ID cadastrado não foi alterado.`
        : conectado
            ? `Cliente autorizou o Mercado Livre em ${oauth.autorizado_em}`
            : 'O cliente ainda não abriu o link de autorização do Mercado Livre';

    return (
        <span
            title={titulo}
            className={cn(
                'inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-full border whitespace-nowrap',
                divergente
                    ? 'text-amber-300 bg-amber-500/10 border-amber-500/20'
                    : conectado
                        ? 'text-emerald-300 bg-emerald-500/10 border-emerald-500/20'
                        : 'text-white/40 bg-white/[0.04] border-white/[0.08]',
                className
            )}
        >
            {conectado ? <ShieldCheck size={11} /> : <ShieldAlert size={11} />}
            {divergente ? 'Conta divergente' : conectado ? 'ML autorizado' : 'Não autorizou'}
        </span>
    );
}

/** Bloco completo para o modal / ficha — badge + quando, qual conta, e o aviso de Cust ID trocado. */
export function MlOauthBloco({ oauth, className }) {
    const divergente = !!oauth?.divergente;
    const conectado  = !!oauth?.conectado && !divergente;

    return (
        <div className={cn('p-4 rounded-xl bg-white/[0.03] border border-white/[0.06] space-y-3', className)}>
            <p className="text-white/40 text-[11px] font-medium uppercase tracking-wider">
                Autorização do Mercado Livre
            </p>

            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <MlOauthBadge oauth={oauth} />
                    {conectado ? (
                        <div className="text-white/30 text-[11px] mt-1.5 space-y-0.5">
                            <p>Autorizado em <span className="text-white/60">{oauth.autorizado_em}</span></p>
                            {oauth.nickname && (
                                <p>Conta <span className="text-white/60">{oauth.nickname}</span></p>
                            )}
                            {oauth.cust_id && (
                                <p>Cust ID <span className="text-white/60">{oauth.cust_id}</span></p>
                            )}
                        </div>
                    ) : divergente ? (
                        <div className="text-white/30 text-[11px] mt-1.5 space-y-0.5">
                            <p>Clique em <span className="text-white/60">{oauth.autorizado_em}</span></p>
                            <p>
                                Autorizou com a conta{' '}
                                <span className="text-white/60">{oauth.nickname || oauth.cust_id}</span>
                                {oauth.nickname && oauth.cust_id ? ` (${oauth.cust_id})` : ''}
                            </p>
                        </div>
                    ) : (
                        <p className="text-white/30 text-[11px] mt-1.5 leading-relaxed">
                            O cliente ainda não abriu o link de autorização. Ele vai na mensagem de
                            boas-vindas — reenvie se já faz tempo.
                        </p>
                    )}
                </div>
            </div>

            {/* Divergência é pendência de conferência, não erro do cliente: o ML devolve
                o code sem tela quando o navegador já tem sessão ativa com o app, então
                um clique interno da ECF carimba a conta errada. Nada foi gravado. */}
            {divergente && (
                <p className="flex items-start gap-1.5 text-amber-300 text-[11px] bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2">
                    <AlertTriangle size={13} className="shrink-0 mt-px" />
                    <span>
                        Essa conta não é a cadastrada nesta empresa — o Cust ID{' '}
                        <strong className="font-semibold">não foi alterado</strong>. Confira com o
                        cliente e peça que ele abra o link deslogado do Mercado Livre (ou em aba
                        anônima), senão o ML devolve a conta já logada no navegador.
                    </span>
                </p>
            )}

            {/* A conta autorizada é a verdade canônica: quando ela discorda do que estava
                cadastrado, o cadastro é corrigido — e quem olha a ficha precisa saber. */}
            {conectado && oauth.cust_id_corrigido && (
                <p className="flex items-start gap-1.5 text-amber-300 text-[11px] bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2">
                    <AlertTriangle size={13} className="shrink-0 mt-px" />
                    <span>
                        Cust ID corrigido pela autorização: era{' '}
                        <strong className="font-semibold">{oauth.cust_id_anterior || '—'}</strong>, virou{' '}
                        <strong className="font-semibold">{oauth.cust_id}</strong>.
                    </span>
                </p>
            )}
        </div>
    );
}

export default MlOauthBadge;
