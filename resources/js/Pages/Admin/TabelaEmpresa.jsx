import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { CampoDinheiro } from '@/Components/ui/campo-dinheiro';
import TabelaProgressivaFaixas from '@/Components/Fechamento/TabelaProgressivaFaixas';
import { valorExibidoNoCampo, tetoGravado, indiceDeGravacao } from '@/lib/faixasFaturamento';
import { ArrowLeft, Building2, Plus, Trash2, AlertTriangle, FileCheck2 } from 'lucide-react';

/**
 * Admin/TabelaEmpresa.jsx — a FICHA PERMANENTE da tabela de cobrança de uma
 * empresa (Fase 142 Plano 03, D-03), dentro do módulo de contratos.
 *
 * "O usuário pediu que o cadastro da tabela progressiva por empresa ficasse
 * dentro de `/administrativo/contratos/empresa/{id}`" (142-CONTEXT.md §D-03)
 * — esta é a página exclusiva para a qual o botão "Tabela de cobrança" de
 * `Admin/ContratoDetalhe.jsx` leva.
 *
 * ⚠️ A dívida do 137-09 se paga EXPONDO o dado, não adivinhando: o formulário
 * abre preenchido com `tabela_empresa`/`tabela_grupo` — as linhas GRAVADAS,
 * vindas do banco — nunca com a tabela do serviço fazendo as vezes da tabela
 * da empresa. A tabela do serviço só entra por clique explícito no botão
 * "Começar a partir da tabela de X", sem salvar sozinha.
 *
 * Cada campo de valor usa `CampoDinheiro` (máscara `react-imask`, D-02) —
 * NUNCA `type="number"` cru, que é exatamente o que deixava o zero a mais
 * invisível.
 */
export default function TabelaEmpresa({
    company,
    tabela_empresa = [],
    procedencia_empresa = null,
    tabela_grupo = null,
    tabela_aplicada = null,
    modelos_de_partida = [],
    leitura_pendente = null,
}) {
    const { flash } = usePage().props;

    return (
        <AppLayout title={`Adm · Tabela de cobrança — ${company.name}`}>
            <main className="p-6">
                <div className="space-y-6 max-w-4xl">
                    {/* Cabeçalho */}
                    <div>
                        <Link
                            href={route('admin.contratos.show', company.id)}
                            className="inline-flex items-center gap-1 text-[12px] text-white/40 hover:text-white/70 mb-2"
                        >
                            <ArrowLeft size={12} /> Voltar para o contrato
                        </Link>
                        <h1 className="text-xl font-semibold font-display text-white flex items-center gap-2">
                            <Building2 size={20} className="text-ecf-yellow" />
                            {company.name}
                        </h1>
                    </div>

                    {flash?.success && (
                        <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-[13px] text-emerald-300">
                            {flash.success}
                        </div>
                    )}
                    {flash?.aviso && (
                        <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-[13px] text-amber-300">
                            {flash.aviso}
                        </div>
                    )}

                    {/* Bloco 1 — o que está cobrando esta empresa hoje. */}
                    <BlocoTabelaAplicada tabelaAplicada={tabela_aplicada} />

                    {/* Bloco 2 — como esta tabela chegou aqui. */}
                    <BlocoProcedencia procedencia={procedencia_empresa} />

                    {/* Bloco 3 — leitura de contrato esperando conferência. */}
                    {leitura_pendente && (
                        <Card className="border-white/10">
                            <CardContent className="p-4 space-y-2">
                                <div className="flex items-center gap-2">
                                    <FileCheck2 size={14} className="text-white/50 shrink-0" />
                                    <h2 className="text-white/85 text-[15px] font-semibold">Leitura de contrato aguardando conferência</h2>
                                </div>
                                <p className="text-[13px] text-white/60">
                                    Um contrato assinado que parece ser desta empresa foi lido e ainda não foi
                                    conferido. Conferir lá vale mais do que digitar aqui — o texto veio do
                                    contrato.
                                </p>
                                <p className="text-[12px] text-white/40">Envelope: {leitura_pendente.nome_envelope}</p>
                                <Link href={route('admin.contratos.tabelas.index')}>
                                    <Button type="button" variant="outline">
                                        Conferir na caixa de entrada
                                    </Button>
                                </Link>
                            </CardContent>
                        </Card>
                    )}

                    {/* Bloco 4 — editar a tabela desta empresa. */}
                    <Card>
                        <CardContent className="p-4 space-y-3">
                            <h2 className="text-white/85 text-[15px] font-semibold">Tabela desta empresa</h2>
                            <FormularioFaixas
                                linhasIniciais={tabela_empresa}
                                modelosDePartida={modelos_de_partida}
                                avisoAntesDeSalvar={
                                    procedencia_empresa === 'contrato'
                                        ? 'Esta tabela veio do contrato assinado. Salvar por cima substitui ela pelo que está na tela. Continuar?'
                                        : null
                                }
                                onSalvar={(faixas, { onSuccess, onError, onFinish }) => {
                                    router.post(route('admin.contratos.tabela.salvar', company.id), { faixas }, {
                                        preserveScroll: true,
                                        onSuccess,
                                        onError,
                                        onFinish,
                                    });
                                }}
                                onRemover={tabela_empresa.length > 0 ? () => {
                                    if (!confirm('Apagar a tabela de cobrança desta empresa?')) return;
                                    router.delete(route('admin.contratos.tabela.remover', company.id), { preserveScroll: true });
                                } : null}
                                rotuloRemover="Apagar a tabela desta empresa"
                            />
                        </CardContent>
                    </Card>

                    {/* Bloco 5 — tabela do grupo (Fase 138, vence sobre a da empresa). */}
                    {company.company_group_id != null && (
                        <Card>
                            <CardContent className="p-4 space-y-3">
                                <h2 className="text-white/85 text-[15px] font-semibold">
                                    Tabela do grupo{company.grupo_nome ? ` ${company.grupo_nome}` : ''}
                                </h2>
                                <p className="text-white/30 text-[12px]">
                                    Quem manda é a empresa do grupo que mais faturou no mês — se outra empresa
                                    passar na frente, a tabela muda junto.
                                </p>
                                {tabela_grupo != null && tabela_grupo.length === 0 && (
                                    <p className="text-white/40 text-[13px]">Este grupo não tem tabela própria cadastrada.</p>
                                )}
                                <FormularioFaixas
                                    linhasIniciais={tabela_grupo ?? []}
                                    onSalvar={(faixas, { onSuccess, onError, onFinish }) => {
                                        router.post(route('admin.contratos.tabela.grupo.salvar', company.company_group_id), { faixas }, {
                                            preserveScroll: true,
                                            onSuccess,
                                            onError,
                                            onFinish,
                                        });
                                    }}
                                    onRemover={(tabela_grupo ?? []).length > 0 ? () => {
                                        if (!confirm('Apagar a tabela deste grupo? O grupo volta a usar a tabela da empresa que mais faturou no mês.')) return;
                                        router.delete(route('admin.contratos.tabela.grupo.remover', company.company_group_id), { preserveScroll: true });
                                    } : null}
                                    rotuloRemover="Apagar a tabela deste grupo"
                                />
                            </CardContent>
                        </Card>
                    )}
                </div>
            </main>
        </AppLayout>
    );
}

/**
 * Bloco 1 — "O que está cobrando esta empresa hoje", lendo `tabela_aplicada`
 * (o retorno de `FechamentoFaixaResolver::paraEmpresa`). Não é
 * necessariamente a tabela da empresa — a do grupo vence (Fase 138).
 */
function BlocoTabelaAplicada({ tabelaAplicada }) {
    if (tabelaAplicada == null) {
        return (
            <Card className="border-amber-500/20">
                <CardContent className="p-4 space-y-2">
                    <div className="flex items-center gap-2">
                        <AlertTriangle size={14} className="text-amber-400" />
                        <h2 className="text-amber-300 text-[15px] font-semibold">Sem tabela de cobrança</h2>
                    </div>
                    <p className="text-[13px] text-white/60">
                        Esta empresa não tem tabela de cobrança — ela fica de fora do fechamento até alguém
                        cadastrar uma.
                    </p>
                </CardContent>
            </Card>
        );
    }

    const fraseOrigem = {
        grupo: `Quem manda aqui é a tabela do grupo ${tabelaAplicada.grupo_nome ?? ''}.`.trim(),
        propria: 'Esta é a tabela desta empresa.',
        servico: `Esta empresa ainda está usando a tabela do serviço ${tabelaAplicada.servico_nome ?? ''}.`.trim(),
    }[tabelaAplicada.origem] ?? null;

    return (
        <Card>
            <CardContent className="p-4 space-y-3">
                <div>
                    <h2 className="text-white/85 text-[15px] font-semibold">O que está cobrando esta empresa hoje</h2>
                    {fraseOrigem && <p className="text-[13px] text-white/60 mt-0.5">{fraseOrigem}</p>}
                </div>
                <TabelaProgressivaFaixas faixas={tabelaAplicada.faixas} />
            </CardContent>
        </Card>
    );
}

/**
 * Bloco 2 — "Como esta tabela chegou aqui", lendo `procedencia_empresa`.
 * Copy sem jargão (proibidas: snapshot, competência, reconsolidação,
 * rollup, âncora, origem, faixa piso, presumida — 142-CONTEXT.md).
 */
function BlocoProcedencia({ procedencia }) {
    if (procedencia == null) return null;

    const conteudo = {
        contrato: {
            selo: 'Conferida pelo contrato assinado',
            classeSelo: 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20',
        },
        manual: {
            selo: 'Cadastrada à mão no sistema',
            classeSelo: 'bg-white/[0.06] text-white/60 border-white/10',
        },
        presumida_servico: {
            selo: 'Copiada do serviço contratado',
            classeSelo: 'bg-white/[0.06] text-white/60 border-white/10',
            aviso: 'Esta tabela foi copiada do serviço contratado quando o sistema passou a cobrar por empresa. Ninguém conferiu ela contra o contrato ainda.',
        },
    }[procedencia];

    if (!conteudo) return null;

    return (
        <Card>
            <CardContent className="p-4 space-y-2">
                <h2 className="text-white/85 text-[15px] font-semibold">Como esta tabela chegou aqui</h2>
                <span className={`inline-block text-[12px] font-semibold px-2 py-0.5 rounded-full border ${conteudo.classeSelo}`}>
                    {conteudo.selo}
                </span>
                {conteudo.aviso && (
                    <p className="text-[13px] text-white/60">{conteudo.aviso}</p>
                )}
            </CardContent>
        </Card>
    );
}

function linhaVaziaFaixa(ordem) {
    return { ordem, limite_superior: null, valor: null, valor_e_piso: false };
}

/**
 * `FormularioFaixas` — o formulário de cadastro/edição da tabela, extraído
 * como subcomponente local e reaproveitado pelos blocos 4 (empresa) e 5
 * (grupo) — duas cópias do mesmo formulário nesta página seria o mesmo erro
 * da grade duplicada, em escala menor.
 *
 * Estado inicial das linhas vem de `linhasIniciais` (o que está GRAVADO,
 * nunca uma reconstrução) — uma linha vazia quando a tabela não existe.
 * `modelosDePartida` (só usado pelo bloco 4) preenche as linhas com o
 * catálogo de um serviço, SEM salvar — é só ponto de partida, precisa ser
 * conferido contra o contrato antes de salvar.
 *
 * ⚠️ Quick 260910 — o campo de Faturamento de cada linha mostra e recebe o
 * valor REDONDO do contrato ("até 500.000" / "a partir de 500.000"), nunca
 * mais o teto cru gravado (",99"). `linhas` continua guardando o valor
 * gravado (`limite_superior` sempre ",99") — a conversão mora inteira em
 * `lib/faixasFaturamento.js` e acontece só na exibição/digitação deste
 * campo (`aoMudarFaturamento`). A primeira linha grava nela mesma; todas as
 * outras (inclusive a última, sem teto) gravam o valor digitado na LINHA
 * ANTERIOR — é a única que funciona ao contrário das outras, e por isso é a
 * mais fácil de errar (`indiceDeGravacao`, testado isolado).
 */
function FormularioFaixas({ linhasIniciais, onSalvar, onRemover, rotuloRemover = 'Apagar tabela', modelosDePartida = [], avisoAntesDeSalvar = null }) {
    const [linhas, setLinhas] = useState(() =>
        linhasIniciais && linhasIniciais.length > 0
            ? linhasIniciais.map(f => ({ ...f }))
            : [linhaVaziaFaixa(1)]
    );
    const [partidaEscolhida, setPartidaEscolhida] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState(null);

    function atualizarLinha(idx, campo, valor) {
        setLinhas(prev => prev.map((l, i) => {
            if (i !== idx) return l;
            const nova = { ...l, [campo]: valor };
            // Backend recusa "valor é piso" numa faixa com teto — some o
            // checkbox assim que o campo de teto deixa de estar vazio.
            if (campo === 'limite_superior' && valor !== null) nova.valor_e_piso = false;
            return nova;
        }));
    }

    // Quick 260910 — a pessoa digita o valor REDONDO na linha `idx`
    // (lib/faixasFaturamento.js decide se isso é "até" — só a primeira linha
    // — ou "a partir de", todas as outras); `indiceDeGravacao` diz em QUAL
    // linha do array esse valor efetivamente é gravado (a própria, só na
    // primeira; a linha ANTERIOR, em todas as outras). Reaproveita
    // `atualizarLinha` pra manter a mesma regra de limpar "valor é piso"
    // quando a linha-alvo deixa de estar sem teto.
    function aoMudarFaturamento(idx, valorRedondo) {
        atualizarLinha(indiceDeGravacao(idx), 'limite_superior', tetoGravado(valorRedondo));
    }

    function adicionarLinha() {
        const proximaOrdem = linhas.length > 0
            ? Math.max(...linhas.map(l => Number(l.ordem) || 0)) + 1
            : 1;
        setLinhas(prev => [...prev, linhaVaziaFaixa(proximaOrdem)]);
    }

    function removerLinha(idx) {
        const linha = linhas[idx];
        if (!confirm(`Remover a faixa "${linha.ordem}ª faixa" desta tabela?`)) return;
        setLinhas(prev => prev.filter((_, i) => i !== idx));
    }

    function comecarAPartirDoModelo(modelo) {
        setLinhas(modelo.faixas.map(f => ({ ...f })));
        setPartidaEscolhida(modelo.nome);
    }

    function extrairErro(errors) {
        const primeiro = Object.values(errors ?? {})[0];
        if (Array.isArray(primeiro)) return primeiro[0];
        return primeiro ?? 'Não foi possível salvar a tabela.';
    }

    function salvar() {
        if (avisoAntesDeSalvar && !confirm(avisoAntesDeSalvar)) return;

        const payload = linhas.map(l => ({
            ordem: Number(l.ordem),
            limite_superior: l.limite_superior === null || l.limite_superior === '' ? null : Number(l.limite_superior),
            valor: Number(l.valor),
            valor_e_piso: !!l.valor_e_piso,
        }));

        setSalvando(true);
        setErro(null);
        onSalvar(payload, {
            onSuccess: () => setPartidaEscolhida(null),
            onError: (errors) => setErro(extrairErro(errors)),
            onFinish: () => setSalvando(false),
        });
    }

    return (
        <div className="space-y-3">
            {modelosDePartida.length > 0 && (
                <div className="space-y-1.5 pb-2 border-b border-white/[0.06]">
                    <p className="text-[12px] text-white/40">Começar a partir da tabela de um serviço já cadastrado:</p>
                    <div className="flex flex-wrap gap-2">
                        {modelosDePartida.map(modelo => (
                            <button
                                key={modelo.id}
                                type="button"
                                onClick={() => comecarAPartirDoModelo(modelo)}
                                className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-white/70 bg-white/[0.05] hover:bg-white/[0.09] border border-white/15 px-3 h-7 rounded-lg transition-colors"
                            >
                                Começar a partir da tabela de {modelo.nome}
                            </button>
                        ))}
                    </div>
                    {partidaEscolhida && (
                        <p className="text-amber-400 text-[12px]">
                            Isto é um ponto de partida — confira contra o contrato antes de salvar.
                        </p>
                    )}
                </div>
            )}

            {erro && <p className="text-red-400 text-[12px]">{erro}</p>}

            <div className="space-y-3">
                {linhas.map((linha, idx) => (
                    <div key={idx} className="border-b border-white/[0.06] pb-3 space-y-2 last:border-0">
                        <div className="grid grid-cols-[64px_1fr_1fr_auto] gap-2 items-end">
                            <div className="space-y-1">
                                <Label className="text-[12px]">Ordem</Label>
                                <Input
                                    type="number"
                                    min="1"
                                    value={linha.ordem}
                                    onChange={e => atualizarLinha(idx, 'ordem', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-[12px]">{idx === 0 ? 'Faturamento até' : 'Faturamento a partir de'}</Label>
                                <CampoDinheiro
                                    valor={valorExibidoNoCampo(linhas, idx)}
                                    onChange={(v) => aoMudarFaturamento(idx, v)}
                                    placeholder={idx === 0 ? 'Sem limite superior' : 'Digite o valor'}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-[12px]">Valor da mensalidade</Label>
                                <CampoDinheiro
                                    valor={linha.valor}
                                    onChange={(v) => atualizarLinha(idx, 'valor', v)}
                                    avisoAcimaDe={100000}
                                />
                            </div>
                            <button
                                type="button"
                                onClick={() => removerLinha(idx)}
                                title="Remover faixa"
                                className="text-white/40 hover:text-red-400 p-2 rounded transition-colors"
                            >
                                <Trash2 size={14} />
                            </button>
                        </div>
                        {linha.limite_superior === null && (
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={!!linha.valor_e_piso}
                                    onChange={e => atualizarLinha(idx, 'valor_e_piso', e.target.checked)}
                                    className="h-4 w-4 rounded border-white/20 bg-white/5 accent-ecf-yellow"
                                />
                                <span className="text-[12px] text-white/60">Valor é um piso (&quot;a partir de&quot;)</span>
                            </label>
                        )}
                    </div>
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onClick={adicionarLinha}
                    className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-ecf-yellow bg-ecf-yellow/10 hover:bg-ecf-yellow/20 border border-ecf-yellow/20 px-3 h-7 rounded-lg transition-colors w-fit"
                >
                    <Plus size={12} /> Adicionar faixa
                </button>
                <Button type="button" onClick={salvar} disabled={salvando || linhas.length === 0}>
                    {salvando ? 'Salvando...' : 'Salvar tabela'}
                </Button>
                {onRemover && (
                    <Button type="button" variant="outline" onClick={onRemover}>
                        {rotuloRemover}
                    </Button>
                )}
            </div>
        </div>
    );
}
