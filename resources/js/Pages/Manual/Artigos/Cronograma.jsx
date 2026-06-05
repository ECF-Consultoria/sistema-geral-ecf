// Phase 21 — Artigo "Cronograma de horários".
// Conteúdo autoritativo de CONTEXT.md / 21-01-PLAN.md. Linguagem revisada para zero jargão técnico.
// Última revisão: 2026-06-05.
import { Clock, Info } from 'lucide-react';

const ROTINAS = [
    // ── Madrugada ────────────────────────────────────────────────
    {
        bloco: 'Madrugada',
        horario: '03:00',
        nome: 'Atualiza grants do Mercado Livre',
        porQue: 'Busca a lista atualizada dos acessos que os clientes deram para o escritório operar a conta deles, vinda do nosso sistema parceiro ECF Drive.',
    },
    {
        bloco: 'Madrugada',
        horario: '03:20',
        nome: 'Limpa histórico antigo de sincronizações',
        porQue: 'Apaga registros de sincronizações de vendas com mais de 30 dias para a lista de histórico não ficar gigante.',
    },
    {
        bloco: 'Madrugada',
        horario: '04:00',
        nome: 'Limpa avisos antigos do sininho',
        porQue: 'Remove avisos do sininho (notificações) que já foram lidos há mais de 30 dias.',
    },
    // ── Manhã ────────────────────────────────────────────────────
    {
        bloco: 'Manhã',
        horario: '08:00',
        nome: 'Renova permissões do Mercado Livre',
        porQue: 'Verifica as permissões de acesso ao Mercado Livre que estão prestes a expirar e renova automaticamente — você não precisa pedir nova autorização ao cliente toda hora.',
    },
    {
        bloco: 'Manhã',
        horario: '11:00',
        nome: 'Busca dados da Adman',
        porQue: 'Traz os números do dia anterior (faturamento, gastos com anúncios, vendas) do nosso parceiro de dados Adman. É a base para tudo da Dashboard, dos Sugadores e do Fechamento. Se você abrir o sistema antes das 11h, ainda vai ver os números de ontem.',
    },
    {
        bloco: 'Manhã',
        horario: '11:05',
        nome: 'Busca dados direto do Mercado Livre',
        porQue: 'Para as contas com permissão direta do Mercado Livre (sem passar pela Adman), busca os números atualizados na sequência.',
    },
    {
        bloco: 'Manhã',
        horario: '11:30',
        nome: 'Atualiza faturamento mensal',
        porQue: 'Calcula o faturamento bruto consolidado do mês para cada empresa. É o que aparece na tela de Fechamento.',
    },
    {
        bloco: 'Manhã',
        horario: '11:45',
        nome: 'Recalcula metas individuais',
        porQue: 'Atualiza o progresso das metas pessoais (suas) e das metas de carteira (das empresas que você acompanha).',
    },
    {
        bloco: 'Manhã',
        horario: '11:55',
        nome: 'Recalcula metas de setor',
        porQue: 'Atualiza o progresso das metas de cada setor (publicações no mês, vendas, etc.). É o que aparece para o líder do setor.',
    },
    // ── Início da tarde ──────────────────────────────────────────
    {
        bloco: 'Início da tarde',
        horario: '12:00',
        nome: 'Detecta sugadores do dia',
        porQue: 'Procura campanhas e anúncios que estão gastando dinheiro sem gerar vendas correspondentes, para o analista investigar. Aparece na aba Sugadores logo depois.',
    },
    {
        bloco: 'Início da tarde',
        horario: '12:30',
        nome: 'Fecha sugadores resolvidos',
        porQue: 'Marca como resolvidos os sugadores cujas campanhas já foram pausadas ou movidas para quarentena pelo analista — para a lista de pendentes não ficar suja com casos já tratados.',
    },
    {
        bloco: 'Início da tarde',
        horario: '12:45',
        nome: 'Prepara dados da Dashboard',
        porQue: 'Antecipa os cálculos pesados de faturamento dos últimos 30 dias para a Dashboard carregar instantaneamente quando alguém abre. Sem isso, a primeira pessoa do dia a abrir esperaria vários minutos.',
    },
    // ── Em momentos variados do dia ──────────────────────────────
    {
        bloco: 'Em momentos variados do dia',
        horario: 'Uma vez por dia',
        nome: 'Limpa pesquisas NPS pendentes',
        porQue: 'Remove pesquisas de satisfação que ficaram mais de 2 dias sem resposta — para a lista de NPS pendentes refletir o que ainda vale a pena cobrar do cliente.',
    },
    // ── Configurável pelo admin ──────────────────────────────────
    {
        bloco: 'Configurável pelo admin',
        horario: 'Mensal — dia e hora configurados',
        nome: 'Envia relatório mensal de fechamento',
        porQue: 'Quando ativado pelo admin em Configurações → Financeiro, o sistema envia automaticamente o relatório financeiro mensal por e-mail no dia e hora escolhidos. Se estiver desativado, o envio só acontece quando o admin clicar manualmente.',
    },
];

// Agrupa preservando a ordem de inserção
const blocos = ROTINAS.reduce((acc, r) => {
    if (!acc.find(b => b.nome === r.bloco)) acc.push({ nome: r.bloco, linhas: [] });
    acc.find(b => b.nome === r.bloco).linhas.push(r);
    return acc;
}, []);

export default function Cronograma() {
    return (
        <article className="space-y-8">
            {/* Cabeçalho do artigo */}
            <header className="space-y-4">
                <h1 className="text-white text-2xl font-display font-bold tracking-tight">
                    Cronograma de horários
                </h1>
                <div className="space-y-3 text-white/70 text-[14px] leading-relaxed max-w-3xl">
                    <p>
                        O sistema ECF Admin tem várias rotinas automáticas que rodam todo dia em horários fixos. Elas buscam dados externos, recalculam números, limpam listas antigas e preparam tudo para você abrir o painel sem esperar.
                    </p>
                    <p>
                        Esta página mostra todas essas rotinas em ordem de horário, com uma explicação simples de cada uma e por que ela importa para o seu dia a dia. Se você abriu o sistema cedo e algum número parece desatualizado, dá uma olhada aqui — provavelmente a rotina que atualiza aquele dado ainda não rodou.
                    </p>
                    <p>
                        Os horários estão no horário de Brasília. Todos os dados sobre vendas e investimento em anúncios são consolidados pelo nosso parceiro Adman no dia seguinte ao da venda (chamamos isso de D-1) — por isso, na Dashboard, você vê hoje os números fechados de ontem.
                    </p>
                </div>
            </header>

            {/* Tabela desktop */}
            <div className="hidden md:block rounded-xl border border-white/[0.06] overflow-hidden">
                <table className="w-full text-[13.5px]">
                    <thead className="bg-white/[0.03]">
                        <tr className="text-left text-white/60 text-[11.5px] uppercase tracking-wider">
                            <th className="px-4 py-3 w-[22%] font-semibold">Horário</th>
                            <th className="px-4 py-3 w-[28%] font-semibold">O que acontece</th>
                            <th className="px-4 py-3 font-semibold">Por que importa</th>
                        </tr>
                    </thead>
                    <tbody>
                        {blocos.map(bloco => (
                            <>
                                <tr key={`b-${bloco.nome}`} className="bg-ecf-yellow/[0.04] border-y border-white/[0.04]">
                                    <td colSpan={3} className="px-4 py-2 text-ecf-yellow text-[12px] font-semibold uppercase tracking-wider">
                                        <span className="flex items-center gap-2">
                                            <Clock size={12} />
                                            {bloco.nome}
                                        </span>
                                    </td>
                                </tr>
                                {bloco.linhas.map((r, idx) => (
                                    <tr
                                        key={`${bloco.nome}-${idx}`}
                                        className="border-t border-white/[0.04] hover:bg-white/[0.02] transition-colors"
                                    >
                                        <td className="px-4 py-3 text-white/80 font-mono text-[13px] align-top whitespace-nowrap">
                                            {r.horario}
                                        </td>
                                        <td className="px-4 py-3 text-white font-semibold align-top">
                                            {r.nome}
                                        </td>
                                        <td className="px-4 py-3 text-white/60 leading-relaxed align-top">
                                            {r.porQue}
                                        </td>
                                    </tr>
                                ))}
                            </>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Lista mobile (cards empilhados) */}
            <div className="md:hidden space-y-6">
                {blocos.map(bloco => (
                    <section key={`m-${bloco.nome}`} className="space-y-3">
                        <h3 className="flex items-center gap-2 text-ecf-yellow text-[11.5px] font-semibold uppercase tracking-wider">
                            <Clock size={12} />
                            {bloco.nome}
                        </h3>
                        <div className="space-y-2">
                            {bloco.linhas.map((r, idx) => (
                                <div
                                    key={`m-${bloco.nome}-${idx}`}
                                    className="rounded-lg border border-white/[0.06] bg-white/[0.02] p-4 space-y-2"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-white font-semibold text-[14px] leading-tight">
                                            {r.nome}
                                        </span>
                                        <span className="text-white/80 font-mono text-[12px] whitespace-nowrap">
                                            {r.horario}
                                        </span>
                                    </div>
                                    <p className="text-white/60 text-[13px] leading-relaxed">
                                        {r.porQue}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>
                ))}
            </div>

            {/* Rodapé do artigo */}
            <footer className="rounded-lg border border-white/[0.06] bg-white/[0.02] p-4 flex items-start gap-3 text-[12.5px] text-white/60 leading-relaxed">
                <Info size={14} className="text-white/40 shrink-0 mt-0.5" />
                <div className="space-y-1">
                    <p>
                        <strong className="text-white/80">Última revisão deste artigo:</strong> 2026-06-05.
                    </p>
                    <p>
                        Se algum horário acima divergir do que você observa no painel, avise o time de desenvolvimento — pode ter havido mudança recente que ainda não foi documentada aqui.
                    </p>
                </div>
            </footer>
        </article>
    );
}
