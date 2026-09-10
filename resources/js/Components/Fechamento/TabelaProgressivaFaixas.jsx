import { cn } from '@/lib/utils';

/**
 * `TabelaProgressivaFaixas` — a ÚNICA definição da grade da tabela
 * progressiva em todo o projeto (Fase 142 Plano 03).
 *
 * Extraída de `Pages/Admin/Financeiro/TabelaFaixasSection.jsx` (Fase 139
 * Plano 06, correção 260904-jpn) para cá, como componente compartilhado
 * entre o Fechamento e a ficha nova da empresa (`Pages/Admin/TabelaEmpresa.jsx`).
 * A Fase 139 já pagou o preço de ter esse markup em DUAS cópias divergentes
 * (bloco do grupo e bloco do serviço, dentro do mesmo arquivo); criar uma
 * QUARTA cópia dentro da página nova seria repetir o erro no mesmo mês —
 * por isso a extração, byte a byte, sem reinterpretar o desenho.
 *
 * Densidade fiel a `design_handoff_fechamento/Fechamento.dc.html`: grade
 * `80px 1fr 160px` com gap 16px (não tabela HTML de larguras automáticas),
 * linhas 12px/18px de padding a 13px de texto, cabeçalho 10px/18px sobre a
 * superfície interna (`ecf-card-2`), caixa com raio 12px. Fonte e paleta
 * continuam as do projeto (font-mono do Tailwind, tokens ecf-*) — decisão
 * do usuário, não a fonte mono do handoff.
 */

const fmtBRL = (n) => n == null ? '—'
    : Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL',
        minimumFractionDigits: 0, maximumFractionDigits: 0 });

// Prefixa "a partir de" quando a faixa é piso — mesma disciplina de
// Financeiro.jsx (137-09 Tarefa 1): nunca mostrar o valor seco de uma
// faixa aberta, isso faria o Administrativo cobrar a menos.
const fmtValorFaixa = (valor, isPiso) => valor == null ? null
    : (isPiso ? `a partir de ${fmtBRL(valor)}` : fmtBRL(valor));

export default function TabelaProgressivaFaixas({ faixas, faixaOrdemAtual, notaRodape }) {
    const colunas = 'grid grid-cols-[80px_1fr_160px] gap-4';

    return (
        <div>
            <div className="rounded-xl border border-white/[0.06] overflow-hidden">
                <div className={cn(colunas, 'px-[18px] py-2.5 bg-ecf-card-2 text-white/40 text-[12px] font-semibold uppercase tracking-[0.05em]')}>
                    <span>Faixa</span>
                    <span>Faturamento até</span>
                    <span className="text-right">Mensalidade</span>
                </div>
                {faixas.map((f, i) => {
                    const atual = faixaOrdemAtual != null && f.ordem === faixaOrdemAtual;
                    return (
                        <div
                            key={f.ordem}
                            className={cn(
                                colunas,
                                'items-center px-[18px] py-3 font-mono text-[14px]',
                                i > 0 && 'border-t border-white/[0.03]',
                                atual && 'bg-ecf-yellow/10',
                            )}
                        >
                            <span className={cn('font-semibold', atual ? 'text-ecf-yellow' : 'text-white/70')}>{f.ordem}ª</span>
                            <span className={atual ? 'text-ecf-yellow' : 'text-white/70'}>
                                {f.limite_superior != null ? fmtBRL(f.limite_superior) : 'acima'}
                            </span>
                            <span className={cn('text-right font-semibold', atual ? 'text-ecf-yellow' : 'text-emerald-400/80')}>
                                {fmtValorFaixa(f.valor, f.valor_e_piso)}
                            </span>
                        </div>
                    );
                })}
            </div>
            {/* Plano 142-04 usa esta nota, num mês fechado, para dizer que a
                tabela mostrada é a cadastrada hoje (não necessariamente a que
                cobrou naquele mês). */}
            {notaRodape && (
                <p className="text-white/30 text-[12px] mt-1.5">{notaRodape}</p>
            )}
        </div>
    );
}
