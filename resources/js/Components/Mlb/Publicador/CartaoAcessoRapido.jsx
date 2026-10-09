import { cn } from '@/lib/utils';
import { ChevronRight } from 'lucide-react';
import SeloConta from './SeloConta';

/**
 * Cartão do bloco "Acesso rápido" da tela A (quick 261009-t01).
 *
 * `item` é o que está gravado em `localStorage` (`publicador.recentes.{user_id}`):
 * `{ chave, nome, identificador, programa }` e NADA além disso. Um item gravado por
 * uma versão antiga da tela pode não ter nem isso — por isso todo campo passa por
 * `textoSeguro()` e o cartão continua desenhando com o que sobrar.
 *
 * `dados` é a LINHA viva do servidor (`empresas[]`) daquela mesma conta, quando ela
 * está na página exibida. É dali — e só dali — que saem os números e o estado da
 * conta: nada de número vindo do `localStorage`, que envelhece sem avisar. Sem a
 * linha viva o cartão mostra só o nome e o botão, dizendo que os números aparecem ao
 * abrir. Isso é de propósito: cartão com número velho é pior que cartão sem número.
 *
 * ⚠️ A tela preta de 07/10 nasceu de um campo que chegou como OBJETO e foi
 * renderizado cru ("Objects are not valid as a React child"). `String(valor ?? '—')`
 * NÃO cobre esse caso — vira "[object Object]". Daí `textoSeguro`/`numeroSeguro`.
 */

/** Só string/number do servidor; qualquer outra forma (objeto, array…) cai no fallback. */
export function textoSeguro(valor, fallback = '—') {
    return (typeof valor === 'string' || typeof valor === 'number') ? String(valor) : fallback;
}

/** Número de verdade ou `null` — `NaN`, string e objeto não viram contagem. */
export function numeroSeguro(valor) {
    return (typeof valor === 'number' && Number.isFinite(valor)) ? valor : null;
}

/** Até duas letras do nome para o quadradinho do cartão (e da linha da tabela). */
export function iniciaisDe(nome) {
    const limpo = (typeof nome === 'string' ? nome : '').trim();
    if (limpo === '') return '—';
    const partes = limpo.split(/\s+/).filter(Boolean);
    const letras = partes.length >= 2 ? partes[0].charAt(0) + partes[1].charAt(0) : limpo.slice(0, 2);

    return letras.toUpperCase();
}

export default function CartaoAcessoRapido({ item, dados = null, onAbrir }) {
    const chave = textoSeguro(item?.chave, '');
    const nomeBruto = textoSeguro(dados?.nome, '') || textoSeguro(item?.nome, '');
    const nome = nomeBruto !== '' ? nomeBruto : (chave !== '' ? chave : 'Empresa');
    const identificador = textoSeguro(dados?.identificador, '') || textoSeguro(item?.identificador, '');
    const erp = textoSeguro(dados?.erp?.nome, '');
    const produtos = numeroSeguro(dados?.produtos);
    const anuncios = numeroSeguro(dados?.publicados);
    const temNumeros = produtos !== null || anuncios !== null;
    const podeAbrir = chave !== '';

    return (
        <div className="flex min-w-0 flex-col justify-between gap-4 rounded-xl border border-white/[0.08] bg-ecf-card p-4">
            <div className="flex min-w-0 items-start gap-3">
                <span
                    aria-hidden="true"
                    className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-ecf-yellow/20 bg-ecf-yellow/[0.12] font-mono text-[13px] font-bold text-ecf-yellow"
                >
                    {iniciaisDe(nome)}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-[15px] font-bold text-white" title={nome}>{nome}</p>
                    <p className="truncate font-mono text-[11px] font-normal text-white/40">
                        {identificador !== '' ? identificador : 'Sem identificador'}
                        {erp !== '' && (
                            <span title="ERP declarado no onboarding — não existe integração ativa com o ERP.">
                                {' · ERP '}{erp}
                            </span>
                        )}
                    </p>
                </div>
                {dados ? <SeloConta token={dados.token} /> : null}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-[13px] font-normal tabular-nums text-white/55">
                    {temNumeros
                        ? `${produtos ?? 0} ${(produtos ?? 0) === 1 ? 'produto' : 'produtos'} · ${anuncios ?? 0} ${(anuncios ?? 0) === 1 ? 'anúncio' : 'anúncios'}`
                        : 'Números aparecem ao abrir a conta.'}
                </p>
                <button
                    type="button"
                    disabled={!podeAbrir}
                    onClick={() => onAbrir?.()}
                    className={cn(
                        'inline-flex h-9 items-center gap-1 whitespace-nowrap rounded-lg border border-ecf-yellow/40 bg-ecf-yellow/10 px-3 text-[13px] font-normal text-ecf-yellow',
                        'hover:bg-ecf-yellow/[0.16] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                        'disabled:cursor-not-allowed disabled:opacity-40',
                    )}
                >
                    Acessar
                    <ChevronRight className="h-4 w-4" aria-hidden="true" />
                </button>
            </div>
        </div>
    );
}
