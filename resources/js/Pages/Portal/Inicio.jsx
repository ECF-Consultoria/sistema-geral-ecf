import { Link } from '@inertiajs/react';
import { ArrowRight, ClipboardList, LayoutGrid, ListChecks } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import LogoEmpresa from '@/Components/Portal/LogoEmpresa';
import ResponsaveisCliente from '@/Components/Onboarding/Portal/ResponsaveisCliente';
import { cn } from '@/lib/utils';

// ─── Início — o hub do Portal do Cliente ────────────────────────────────────
//
// A porta de entrada de `/portal-cliente/{token}`: um cartão por módulo,
// dizendo em uma linha o que espera o cliente lá dentro.
//
// ### O que NÃO está aqui, e por quê
// Nenhuma métrica de operação — faturamento, ACOS, evolução de vendas. O
// negócio ainda vai definir o que o cliente deve enxergar, e inventar um
// dashboard agora seria o pior dos dois mundos: número no portal do cliente
// vira conversa com o cliente, e cada gráfico publicado aqui é um compromisso
// de que aquele número está certo, atualizado e é explicável por quem atende.
// O hub entrega hoje o que já é verdadeiro e verificável — onde ele está e o
// que falta. Quando o negócio definir os indicadores, eles entram nesta
// coluna, acima ou abaixo dos cartões de módulo.
//
// ### Por que os cartões contam pendências e não progresso
// A barra de progresso do Onboarding tem régua própria (passos + mapeamentos
// visíveis + reuniões) e ela vive em `Onboarding/Publico.jsx`. Repetir esse
// cálculo aqui criaria uma segunda régua para o mesmo número — e as duas
// divergiriam na primeira mudança feita em uma só. O cartão usa a contagem de
// pendências acionáveis, que é a mesma régua do badge do menu, vinda pronta do
// `PortalClienteService`.

const ICONES = {
    'list-checks':    ListChecks,
    'clipboard-list': ClipboardList,
};

/**
 * O resumo de uma linha de cada módulo.
 *
 * Texto escrito do ponto de vista do cliente: ele quer saber se a bola está
 * com ele ou conosco, não quantos registros existem no banco.
 */
function resumoDoModulo(modulo, resumo) {
    if (modulo.chave === 'onboarding') {
        return modulo.badge > 0
            ? { texto: `${modulo.badge} ${modulo.badge === 1 ? 'item aguarda' : 'itens aguardam'} você`, acao: true }
            : { texto: 'Nada pendente da sua parte', acao: false };
    }

    if (modulo.chave === 'ppa') {
        const ativos = resumo?.ppa?.planos_ativos ?? 0;
        const total  = resumo?.ppa?.planos_total ?? 0;

        if (total === 0) {
            // Estado vazio explícito. O módulo continua no menu de propósito:
            // sumir faria o cliente que ouviu "seu plano está no portal"
            // concluir que o sistema está quebrado.
            return { texto: 'Nenhum plano por aqui ainda', acao: false };
        }
        if (modulo.badge > 0) {
            return { texto: `${modulo.badge} ${modulo.badge === 1 ? 'tarefa em aberto' : 'tarefas em aberto'}`, acao: true };
        }
        return {
            texto: ativos > 0 ? 'Tudo em dia no seu plano' : 'Plano concluído',
            acao: false,
        };
    }

    return { texto: null, acao: false };
}

function CartaoModulo({ modulo, resumo }) {
    const Icone = ICONES[modulo.icone] ?? LayoutGrid;
    const { texto, acao } = resumoDoModulo(modulo, resumo);

    return (
        <Link
            href={modulo.url}
            className={cn(
                'group block rounded-2xl border p-5 transition-colors min-w-0',
                acao
                    ? 'border-ecf-yellow/25 bg-ecf-yellow/[0.04] hover:bg-ecf-yellow/[0.07]'
                    : 'border-white/[0.08] bg-white/[0.02] hover:bg-white/[0.04]',
            )}
        >
            <div className="flex items-start gap-3">
                <span className={cn(
                    'grid place-items-center h-10 w-10 rounded-xl shrink-0 border',
                    acao
                        ? 'border-ecf-yellow/25 bg-ecf-yellow/10 text-ecf-yellow'
                        : 'border-white/[0.08] bg-white/[0.03] text-white/50',
                )}>
                    <Icone size={18} />
                </span>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <h2 className="text-white font-display font-bold text-[15px]">{modulo.rotulo}</h2>
                        {modulo.badge > 0 && (
                            <span className="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-ecf-yellow/15 text-ecf-yellow text-[11px] font-bold">
                                {modulo.badge}
                            </span>
                        )}
                    </div>
                    <p className="text-white/40 text-[12.5px] mt-1 leading-relaxed">{modulo.descricao}</p>

                    {texto && (
                        <p className={cn(
                            'text-[12.5px] mt-2.5 font-medium',
                            acao ? 'text-ecf-yellow' : 'text-white/50',
                        )}>
                            {texto}
                        </p>
                    )}
                </div>

                <ArrowRight
                    size={16}
                    className="text-white/20 group-hover:text-white/50 transition-colors shrink-0 mt-1"
                />
            </div>
        </Link>
    );
}

export default function Inicio({ empresa, modulos = [], resumo = {}, responsaveis = [] }) {
    // O próprio Início não vira cartão — o cliente já está nele.
    const cartoes = modulos.filter((m) => m.chave !== 'inicio');

    // Quantos módulos têm algo esperando o cliente. É o que decide entre "você
    // tem coisas para ver" e "está tudo em dia" no cabeçalho.
    const comPendencia = cartoes.filter((m) => m.badge > 0);

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Início">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 py-6 sm:py-8 space-y-6">
                {/* ─── Boas-vindas ──────────────────────────────────────────── */}
                <header className="rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.05] to-white/[0.01] p-6">
                    <div className="flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-5">
                        {/* A marca do cliente também aqui: o topo do menu é
                            pequeno, e é neste cartão que o portal de fato "se
                            apresenta" como ambiente da empresa dele. */}
                        <LogoEmpresa empresa={empresa} tamanho="hub" />

                        <div className="min-w-0 flex-1">
                            <h1 className="text-white font-display font-bold text-2xl sm:text-3xl tracking-tight">
                                Bem-vindo, {empresa?.nome}!
                            </h1>
                            <p className="text-white/45 text-[14px] mt-1.5 leading-relaxed">
                                {comPendencia.length > 0
                                    ? 'Este é o portal da sua empresa. Abaixo está o que precisa da sua atenção agora.'
                                    : 'Este é o portal da sua empresa. No momento não há nada pendente da sua parte.'}
                            </p>
                        </div>
                    </div>
                </header>

                {/* ─── Os módulos ───────────────────────────────────────────── */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                    <div className="lg:col-span-2 min-w-0 space-y-4">
                        <h2 className="text-white/70 font-semibold text-[12px] uppercase tracking-wider">
                            Seus módulos
                        </h2>

                        <div className="space-y-3">
                            {cartoes.map((modulo) => (
                                <CartaoModulo key={modulo.chave} modulo={modulo} resumo={resumo} />
                            ))}
                        </div>
                    </div>

                    <aside className="space-y-5 min-w-0">
                        <ResponsaveisCliente
                            responsaveis={responsaveis}
                            titulo="Quem atende você"
                            ajuda="É com estas pessoas que você fala no dia a dia."
                        />
                    </aside>
                </div>
            </div>
        </PortalClienteLayout>
    );
}
