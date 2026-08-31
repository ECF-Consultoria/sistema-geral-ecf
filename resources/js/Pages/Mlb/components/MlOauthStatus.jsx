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

/** Pill compacto para tabela/card. */
export function MlOauthBadge({ oauth, className }) {
    const conectado = !!oauth?.conectado;

    return (
        <span
            title={
                conectado
                    ? `Cliente autorizou o Mercado Livre em ${oauth.autorizado_em}`
                    : 'O cliente ainda não abriu o link de autorização do Mercado Livre'
            }
            className={cn(
                'inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-full border whitespace-nowrap',
                conectado
                    ? 'text-emerald-300 bg-emerald-500/10 border-emerald-500/20'
                    : 'text-white/40 bg-white/[0.04] border-white/[0.08]',
                className
            )}
        >
            {conectado ? <ShieldCheck size={11} /> : <ShieldAlert size={11} />}
            {conectado ? 'ML autorizado' : 'Não autorizou'}
        </span>
    );
}

/** Bloco completo para o modal / ficha — badge + quando, qual conta, e o aviso de Cust ID trocado. */
export function MlOauthBloco({ oauth, className }) {
    const conectado = !!oauth?.conectado;

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
                    ) : (
                        <p className="text-white/30 text-[11px] mt-1.5 leading-relaxed">
                            O cliente ainda não abriu o link de autorização. Ele vai na mensagem de
                            boas-vindas — reenvie se já faz tempo.
                        </p>
                    )}
                </div>
            </div>

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
