import { Link } from '@inertiajs/react';
import { Table2 } from 'lucide-react';
// Fase 142 Plano 03 — a grade da tabela progressiva mudou de endereço para
// `Components/Fechamento/TabelaProgressivaFaixas`, componente compartilhado
// com a ficha nova da empresa (`Pages/Admin/TabelaEmpresa.jsx`). A Fase 139
// já pagou o preço de ter esse markup em duas cópias divergentes — criar uma
// quarta cópia dentro da página nova seria repetir o erro no mesmo mês.
import TabelaProgressivaFaixas from '@/Components/Fechamento/TabelaProgressivaFaixas';

/**
 * TabelaFaixasSection — bloco de EXIBIÇÃO da tabela de faixas dentro do
 * accordion da empresa, na tela de Fechamento.
 *
 * Extraído de `Financeiro.jsx` (arquivo já com ~1300 linhas antes desta
 * seção) por tamanho, conforme decisão deixada em aberto pelo UI-SPEC
 * (Fase 137 Plano 09).
 *
 * ⚠️ Fase 142 Plano 04 (D-04) — este componente deixou de gravar QUALQUER
 * coisa. Antes desta fase havia cinco formulários (`FaixaFormDialog`) e
 * `router.post`/`router.delete` para empresa, serviço e grupo; o usuário
 * pediu explicitamente que o fechamento "continue mostrando a tabela
 * progressiva de cada empresa e a faixa que a empresa está, só não deve ser
 * possível cadastrar ou editar as tabelas por ali". O cadastro/edição agora
 * mora na ficha exclusiva do contrato (`Pages/Admin/TabelaEmpresa.jsx`,
 * rota `admin.contratos.tabela.show`) — este componente só aponta para lá.
 * As rotas antigas (`admin.financeiro.faixas.*`) continuam vivas no backend
 * (rollback + suíte das Fases 137/138), só sem UI daqui.
 *
 * Quatro estados possíveis por empresa (`empresa.tabela_origem`), na mesma
 * ordem em que aparecem na tela:
 *  - bloco de GRUPO (`empresa.tipo === 'grupo'`), sempre ANTES dos três
 *    estados abaixo: com tabela própria do grupo (selo + grade) ou sem
 *    (frase nomeando de qual empresa a tabela foi herdada).
 *  - 'servico': herda a tabela do serviço — grade completa vinda de
 *    `faixasPorServico`.
 *  - 'propria': tem exceção própria (D-13) — vence sobre a do serviço.
 *    ⚠️ Fase 142 Plano 01 pagou a dívida documentada aqui desde 137-09: o
 *    backend agora expõe as LINHAS da tabela própria (`empresa.tabela_faixas`)
 *    — este bloco mostra a grade de verdade, não mais a frase solta "Tabela
 *    própria desta empresa". Ninguém deve reintroduzir um formulário aqui
 *    achando que a lacuna de dado ainda existe — ela foi fechada no plano 01.
 *  - null: nem exceção própria, nem serviço candidato com tabela — estado
 *    "A DEFINIR" (nunca R$ 0, nunca faixa aproximada).
 *
 * `faixaOrdemAtual` destaca a linha da faixa em que a empresa está. Num mês
 * já fechado (`empresa.tabela_faixas_e_de_hoje === true`) a grade mostrada é
 * sempre a tabela cadastrada HOJE — que pode já ter mudado desde então. Por
 * isso só destacamos quando a linha bate exatamente com o que foi congelado
 * naquele mês (ordem + valor + limite superior); quando não bate, nenhuma
 * linha é destacada e uma nota de rodapé avisa que a tabela mudou depois —
 * destacar a linha errada num mês já cobrado é pior do que não destacar
 * nenhuma.
 */

// Fase 142 Plano 04 — no mês fechado, a grade exibida é sempre a de hoje;
// só faz sentido destacar uma linha quando ela bate com o que foi congelado
// naquele mês (ordem, valor e limite superior). Fora do mês fechado, o
// destaque é sempre o da classificação atual, sem nota nenhuma.
function calcularDestaque(faixas, empresa, faixaOrdemAtual) {
    if (!empresa.tabela_faixas_e_de_hoje) {
        return { ordem: faixaOrdemAtual, nota: null };
    }

    const bateuComOCongelado = Array.isArray(faixas) && faixas.some(f =>
        f.ordem === faixaOrdemAtual
        && f.valor === empresa.valor_mensal
        && f.limite_superior === empresa.faixa_limite_superior
    );

    if (bateuComOCongelado) {
        return { ordem: faixaOrdemAtual, nota: null };
    }

    return {
        ordem: null,
        nota: 'A tabela mudou depois deste mês. Esta é a que está cadastrada hoje.',
    };
}

// Único link de saída, presente nos quatro estados — o rótulo muda conforme
// já existir ou não uma tabela própria (da empresa ou do grupo) para ajustar.
function LinkCadastro({ empresaId, temTabela }) {
    return (
        <div className="pt-0.5">
            <Link
                href={route('admin.contratos.tabela.show', empresaId)}
                className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-ecf-yellow bg-ecf-yellow/10 hover:bg-ecf-yellow/20 border border-ecf-yellow/20 px-3 h-7 rounded-lg transition-colors w-fit"
            >
                {temTabela ? 'Ajustar tabela de cobrança' : 'Cadastrar tabela de cobrança'}
            </Link>
            <p className="text-white/30 text-[12px] mt-1">O cadastro fica na página de contrato desta empresa.</p>
        </div>
    );
}

export default function TabelaFaixasSection({ empresa, faixasPorServico = [], faixasPorGrupo = [], faixaOrdemAtual = null }) {
    const servicoAplicado = empresa.tabela_origem === 'servico'
        ? faixasPorServico.find(s => s.nome === empresa.tabela_servico_nome)
        : null;

    // Fase 138 (D-01) — tabela própria do grupo, só existe quando a linha é
    // de grupo e a origem já resolveu para 'grupo'.
    const grupoAplicado = (empresa.tipo === 'grupo' && empresa.tabela_origem === 'grupo')
        ? faixasPorGrupo.find(g => g.id === empresa.company_group_id)
        : null;

    // Melhor esforço para nomear o serviço substituído quando a empresa já
    // tem tabela própria — o resolver (FechamentoFaixaResolver::paraEmpresa)
    // corta a resolução assim que encontra a exceção (D-13) e não devolve
    // "qual serviço seria o dono"; então inferimos pelo cruzamento entre os
    // serviços contratados desta empresa e o catálogo de serviços com
    // tabela cadastrada. Só para exibição.
    const nomesComTabela = new Set(faixasPorServico.map(s => s.nome));
    const servicoInferido = empresa.tabela_origem === 'propria'
        ? (empresa.servicos_contratados || [])
            .map(c => c.servico_nome)
            .find(nome => nomesComTabela.has(nome))
        : null;

    // Fase 139 Tarefa 2 — título do bloco no formato do handoff ("TABELA
    // PROGRESSIVA · <serviço/grupo>"), nomeando de onde a tabela vem quando
    // isso é conhecido. Sem nome (exceção própria ou "A DEFINIR") o título
    // fica sem sufixo — não há serviço/grupo dono para citar.
    const nomeTabela = empresa.tabela_grupo_nome
        ? empresa.tabela_grupo_nome
        : (empresa.tabela_origem === 'servico' ? empresa.tabela_servico_nome : null);

    const temTabelaPropriaEmpresa = empresa.tabela_origem === 'propria'
        && Array.isArray(empresa.tabela_faixas) && empresa.tabela_faixas.length > 0;

    const destaqueGrupo = grupoAplicado
        ? calcularDestaque(grupoAplicado.faixas, empresa, faixaOrdemAtual)
        : null;
    const destaqueServico = servicoAplicado
        ? calcularDestaque(servicoAplicado.faixas, empresa, faixaOrdemAtual)
        : null;
    const destaquePropria = temTabelaPropriaEmpresa
        ? calcularDestaque(empresa.tabela_faixas, empresa, faixaOrdemAtual)
        : null;

    return (
        <div id={`tabela-faixas-${empresa.id}`} className="rounded-lg border border-white/[0.06] overflow-hidden">
            <div className="px-3 py-1.5 bg-white/[0.02] border-b border-white/[0.04] flex items-center gap-1.5">
                <Table2 size={12} className="text-white/40 shrink-0" />
                <span className="text-[13px] font-semibold uppercase tracking-[0.05em] text-white/40">
                    Tabela progressiva{nomeTabela ? ` · ${nomeTabela}` : ''}
                </span>
            </div>

            <div className="p-3 space-y-3">
                {/* Fase 138 (D-01) — bloco exclusivo de linha de grupo, sempre
                    ANTES dos três estados abaixo. */}
                {empresa.tipo === 'grupo' && (
                    <div className="space-y-2 pb-3 border-b border-white/[0.06]">
                        {grupoAplicado ? (
                            <>
                                <span className="inline-block text-[12px] font-semibold px-2 py-0.5 rounded-full bg-ecf-yellow/10 text-ecf-yellow border border-ecf-yellow/20">
                                    Tabela deste grupo
                                </span>
                                <TabelaProgressivaFaixas
                                    faixas={grupoAplicado.faixas}
                                    faixaOrdemAtual={destaqueGrupo.ordem}
                                    notaRodape={destaqueGrupo.nota}
                                />
                                <LinkCadastro empresaId={empresa.id} temTabela />
                            </>
                        ) : (
                            <>
                                <p className="text-white/60 text-[13px]">
                                    {empresa.tabela_herdada_de_nome
                                        ? <>Este grupo está usando a tabela da empresa <span className="font-semibold text-white/80">{empresa.tabela_herdada_de_nome}</span>.</>
                                        : 'Este grupo está usando a tabela de uma das empresas dele.'}
                                </p>
                                <p className="text-white/30 text-[12px]">
                                    Quem manda é a empresa do grupo que mais faturou no mês — se outra empresa passar
                                    na frente, a tabela muda junto.
                                </p>
                                <LinkCadastro empresaId={empresa.id} temTabela={false} />
                            </>
                        )}
                    </div>
                )}

                {/* Estado 1 — herda a tabela do serviço */}
                {empresa.tabela_origem === 'servico' && (
                    <div className="space-y-2">
                        <p className="text-white/70 text-[14px] font-semibold">
                            Tabela do serviço {empresa.tabela_servico_nome}
                        </p>

                        {servicoAplicado && (
                            <TabelaProgressivaFaixas
                                faixas={servicoAplicado.faixas}
                                faixaOrdemAtual={destaqueServico.ordem}
                                notaRodape={destaqueServico.nota}
                            />
                        )}

                        <LinkCadastro empresaId={empresa.id} temTabela={false} />
                    </div>
                )}

                {/* Estado 2 — exceção própria já cadastrada (D-13) */}
                {empresa.tabela_origem === 'propria' && (
                    <div className="space-y-2">
                        <span className="inline-block text-[12px] font-semibold px-2 py-0.5 rounded-full bg-white/[0.06] text-white/60 border border-white/10">
                            Tabela própria desta empresa
                        </span>
                        <p className="text-white/40 text-[12px]">
                            Substitui completamente a tabela do serviço {servicoInferido ? `"${servicoInferido}"` : 'vinculado a este contrato'}.
                        </p>

                        {temTabelaPropriaEmpresa ? (
                            <TabelaProgressivaFaixas
                                faixas={empresa.tabela_faixas}
                                faixaOrdemAtual={destaquePropria.ordem}
                                notaRodape={destaquePropria.nota}
                            />
                        ) : (
                            // Guarda defensiva — não deve acontecer depois do
                            // plano 142-01, mas nunca renderizar grade vazia.
                            <p className="text-white/40 text-[12px]">
                                As faixas desta tabela não puderam ser carregadas agora.
                            </p>
                        )}

                        <LinkCadastro empresaId={empresa.id} temTabela />
                    </div>
                )}

                {/* Estado 3 — nem exceção própria, nem serviço candidato com tabela */}
                {!empresa.tabela_origem && (
                    <div className="space-y-2">
                        <p className="text-amber-400 text-[14px] font-semibold">Tabela de faixas: A DEFINIR</p>
                        <p className="text-white/40 text-[12px]">
                            Cadastre a tabela de faturamento desta empresa para ela entrar no fechamento.
                        </p>
                        <LinkCadastro empresaId={empresa.id} temTabela={false} />
                    </div>
                )}
            </div>
        </div>
    );
}
