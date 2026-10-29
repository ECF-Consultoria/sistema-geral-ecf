import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { ArrowLeft, ChevronRight, Trash2 } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash } from '@/Components/Portal/Estrutura/comum';
import BarraDeSalvar from '@/Components/Portal/Estrutura/Produtos/BarraDeSalvar';
import CoresDoProduto from '@/Components/Portal/Estrutura/Produtos/CoresDoProduto';
import FichaDadosGerais from '@/Components/Portal/Estrutura/Produtos/FichaDadosGerais';
import FichaDescricao from '@/Components/Portal/Estrutura/Produtos/FichaDescricao';
import FichaTecnica from '@/Components/Portal/Estrutura/Produtos/FichaTecnica';
import FichaTitulo from '@/Components/Portal/Estrutura/Produtos/FichaTitulo';
import IndiceDaFicha from '@/Components/Portal/Estrutura/Produtos/IndiceDaFicha';
import MedidasEEnvio from '@/Components/Portal/Estrutura/Produtos/MedidasEEnvio';
import JanelaExcluirVariacao from '@/Components/Portal/Estrutura/Produtos/JanelaExcluirVariacao';
import JanelaExcluirProdutos from '@/Components/Portal/Estrutura/Produtos/JanelaExcluirProdutos';
import { QuadroFotoProduto } from '@/Components/Portal/Estrutura/Produtos/PecasDoProduto';
import PrecificacaoDoProduto from '@/Components/Portal/Estrutura/Produtos/PrecificacaoDoProduto';
import SeloImportadoDoMl from '@/Components/Portal/Estrutura/Produtos/SeloImportadoDoMl';
import useFichaProduto from '@/Components/Portal/Estrutura/Produtos/useFichaProduto';
import {
    assinaturaDasCaixas, assinaturaDoCusto, camposDaFicha, estadoDasSecoes, grupoComum, nomeDasVariacoes, valorPreenchido,
} from '@/lib/fichaLayout';
import { fotoDoProduto } from '@/lib/imagensVariacao';
import { contagemDoEixo, gruposPorCor, textoProdutoSalvo } from '@/lib/produtosEstrutura';
import { limiteDoTitulo } from '@/lib/tituloDoProduto';
import { definirGuardaDoVoltar } from '@/lib/guardaDoVoltar';
import { textoDaVariacao } from '@/lib/portalSubmodulos';
import {
    entradaAtual, esquecerAbertura, fichaAbertaPelaLista, marcarUltimoProduto, passosAte, podeVoltarNoHistorico, urlDeVolta,
    voltarParaLista, voltarPeloHistorico,
} from '@/lib/produtosNavegacao';

// ─── Ficha do produto em página inteira (REF-2, Fase 167-19) ────────────────
//
// D-27: página inteira com URL própria, nada de painel lateral, folha ou lista
// ao lado. D-28: só campos reais do sistema; os calculados vêm do servidor. D-29:
// o quadro da foto não tem upload. D-30: nada do que a referência mostra sem ter
// dado (sem "Ver no Mercado Livre", sino, avatar nem menu ⋮).
//
// A regra de gravação está no hook `useFichaProduto` (POST `linhas` do servidor)
// e a ida e volta da lista em `produtosNavegacao`. Aqui só se monta a página,
// se protege o que foi digitado e se decide para onde voltar.
//
// 29/10/2026 — reorganizada (protótipo aprovado pelo usuário, com ideias do Gemini): a
// largura toda, um índice com o estado de cada seção, a barra de salvar presa embaixo e
// as seções na ordem Produto → Título → Variações → Medidas e envio → Ficha técnica →
// Descrição → Precificação. Custo e caixa "iguais em todas" as variações são uma
// leitura da tela (`heranca`, lib/fichaLayout): o POST continua levando os de cada uma.

const CONFIRMA_SAIR = 'Há alterações não salvas neste produto. Sair sem salvar?';

export default function EstruturaProdutoFicha({ empresa, modulos = [], produto, linhas = [], listas: listasIniciais, vocabulario, limites, ficha_tecnica: fichaTecnica, descricao, explicacoes_campos: explicacoes, escopo_rascunho: escopoRascunho = null }) {
    const ficha = useFichaProduto({ linhas, produto, vocabulario, limites, fichaTecnica, descricao, explicacoes, escopoRascunho });
    const [listas, setListas] = useState(listasIniciais ?? { familias: [], ambientes: [] });
    const [exclusao, setExclusao] = useState(null);   // { linha, ultima } | null
    const [excluindoProduto, setExcluindoProduto] = useState(false);   // janela "Excluir produto" (o produto inteiro, 10/10/2026)
    // Premium com o preço igual ao do Clássico (28/10/2026): o Salvar não passa; a Precificação diz quais e abre o Premium.
    const [premiumPendente, setPremiumPendente] = useState([]);
    const [avisoPremium, setAvisoPremium] = useState(null);
    const [abrirPremium, setAbrirPremium] = useState(0);
    const liberado = useRef(false);
    const alteradoRef = useRef(ficha.alterado);
    alteradoRef.current = ficha.alterado;
    const fichaRef = useRef(ficha);
    fichaRef.current = ficha;
    const esperaVolta = useRef(null);   // saída pelo histórico à espera do popstate (FE-WR-05)
    // D-32: o produto que esta ficha representa — a lista destaca o cartão dele na volta, saia como sair.
    const ultimoRef = useRef(produto?.id ?? null);
    useEffect(() => () => marcarUltimoProduto(ultimoRef.current), []);

    const primeira = ficha.primeira;
    const nome = primeira.nome || 'Novo produto';
    // "Cada variação vira uma oferta…": na Lista SKUs só para quem a vê (10/10/2026).
    const variacaoVira = textoDaVariacao(modulos);

    // ─── Variações abertas e o que é "igual em todas" (29/10/2026) ──────────
    //
    // Cada variação é uma linha que abre; a nova nasce aberta. Custo e caixa comuns são
    // os mais frequentes entre as variações (`grupoComum`); "próprio desta" tira a variação
    // da conta mesmo com o valor igual (estado só da tela, não vai ao servidor).
    const [abertas, setAbertas] = useState(() => new Set());
    const alternarVariacao = (k) => setAbertas((atual) => { const n = new Set(atual); if (n.has(k)) n.delete(k); else n.add(k); return n; });
    const abrirVariacao = (k) => setAbertas((atual) => new Set(atual).add(k));
    const [proprias, setProprias] = useState(() => ({ custo: new Set(), caixa: new Set() }));
    const marcarPropria = (tipo, k, sim) => setProprias((p) => {
        const n = new Set(p[tipo]);
        if (sim) n.add(k); else n.delete(k);

        return { ...p, [tipo]: n };
    });

    const vars = ficha.vars;
    const comumCusto = grupoComum(vars, assinaturaDoCusto, proprias.custo);
    const comumCaixa = grupoComum(vars, (v) => assinaturaDasCaixas(ficha.caixasEdit(v)), proprias.caixa);
    const refCusto = vars.find((v) => v._k === comumCusto.chaves[0]) ?? vars[0];
    const refCaixa = vars.find((v) => v._k === comumCaixa.chaves[0]) ?? vars[0];
    const herdeirosDaCaixa = vars.filter((v) => comumCaixa.chaves.includes(v._k));
    const caixasComuns = ficha.caixasEdit(refCaixa);
    const primeiraCaixa = caixasComuns.find((c) => [c.c, c.l, c.a, c.kg].some((m) => String(m ?? '').trim() !== ''));
    const custoComumTexto = String(refCusto?.custo ?? '').trim() === '' ? 'sem custo' : `R$ ${String(refCusto.custo).replace('.', ',')}`;

    const heranca = {
        varias: vars.length > 1,
        nomeDasVariacoes: nomeDasVariacoes(primeira.eixo_rotulo),
        custoComum: refCusto?.custo ?? '',
        custoComumTexto,
        herdeirosDoCusto: comumCusto.chaves,
        herdaCusto: (v) => comumCusto.chaves.includes(v._k),
        mudarCustoComum: (valor) => ficha.alterarVarias(comumCusto.chaves.length ? comumCusto.chaves : vars.map((v) => v._k), 'custo', valor),
        usarCustoProprio: (v) => marcarPropria('custo', v._k, true),
        usarCustoComum: (v) => { marcarPropria('custo', v._k, false); ficha.alterar(v._k, 'custo', refCusto?.custo ?? ''); },
        herdeirosDaCaixa: comumCaixa.chaves,
        herdaCaixa: (v) => comumCaixa.chaves.includes(v._k),
        caixasComuns,
        caixaComumTexto: primeiraCaixa ? `${primeiraCaixa.c || '?'} × ${primeiraCaixa.l || '?'} × ${primeiraCaixa.a || '?'} cm · ${primeiraCaixa.kg || '?'} kg` : 'ainda sem medidas',
        referenciaDaCaixa: refCaixa,
        mudarCaixaComum: (i, campo, valor) => herdeirosDaCaixa.forEach((v) => ficha.mudarCaixa(v, i, campo, valor)),
        removerCaixaComum: (i) => herdeirosDaCaixa.forEach((v) => ficha.removerCaixa(v, i)),
        adicionarCaixaComum: () => herdeirosDaCaixa.forEach((v) => ficha.adicionarVolume(v)),
        usarCaixaPropria: (v) => marcarPropria('caixa', v._k, true),
        usarCaixaComum: (v) => { marcarPropria('caixa', v._k, false); ficha.definirCaixas(v, caixasComuns); },
    };

    // ─── Estado de cada seção para o índice (só leitura) ────────────────────
    const tecnica = ficha.tecnica;
    const contagemTecnica = camposDaFicha(tecnica.grupos ?? [], tecnica.valores).contagem;
    const estoqueTotal = vars.reduce((s, v) => s + (Number.parseInt(String(v.estoque ?? '').replace(/\D/g, ''), 10) || 0), 0);
    const ambientes = String(primeira.ambientes_texto ?? '').split(',').map((s) => s.trim()).filter(Boolean);
    // Com cor E tamanho: "2 cores · 4 tamanhos" (contar só as variações dizia "4 cores").
    const variacoesTexto = ficha.segundoEixo
        ? `${contagemDoEixo(gruposPorCor(vars).length, primeira.eixo_rotulo)} · ${contagemDoEixo(vars.length, ficha.segundoEixo)}`
        : contagemDoEixo(vars.length, primeira.eixo_rotulo);
    const estados = estadoDasSecoes({
        nome: primeira.nome, temCategoria: !! (primeira.categoria_ml_id || primeira.categoria), familia: primeira.familia, ambientes: ambientes.length,
        tituloTamanho: String(primeira.titulo ?? '').length, tituloLimite: limiteDoTitulo(ficha.termosDoTitulo.limite),
        variacoesTexto, variacoesComErro: Object.keys(ficha.erros ?? {}).length, semReferencia: vars.filter((v) => String(v.codigo ?? '').trim() === '').length, estoque: estoqueTotal,
        semCaixa: ! primeiraCaixa, medidasFaltando: (tecnica.medidas ?? []).filter((c) => c.obrigatorio && ! valorPreenchido(tecnica.valores[c.id])).length,
        caixaTexto: primeiraCaixa ? `caixa ${primeiraCaixa.c}×${primeiraCaixa.l}×${primeiraCaixa.a}` : '',
        tecnicaCarregando: tecnica.carregando, tecnicaComErro: Object.keys(tecnica.erros ?? {}).length > 0 || !! tecnica.erroGeral,
        obrigatoriasFaltando: contagemTecnica.obrigatoriasFaltando, obrigatorias: contagemTecnica.obrigatorias, outrasPreenchidas: contagemTecnica.outrasPreenchidas,
        descricaoTamanho: String(ficha.descricao.texto ?? '').trim().length, premiumPendente: premiumPendente.length > 0,
    });
    const secoes = [
        ['secao-produto', 'Produto'], ['secao-titulo', 'Título de venda'], ['secao-cores', heranca.nomeDasVariacoes], ['secao-medidas', 'Medidas e envio'],
        ['secao-ficha-tecnica', 'Ficha técnica'], ['secao-descricao', 'Descrição'], ['secao-preco', 'Precificação'],
    ].map(([id, nomeDaSecao]) => ({ id, nome: nomeDaSecao, tipo: estados[id][0], sub: estados[id][1] }));

    // O motivo de não ter salvado, na barra do Salvar, com o atalho para o campo.
    const errosDasVariacoes = Object.keys(ficha.erros ?? {});
    let mensagemAoSalvar = null;
    let irPara = null;
    if (avisoPremium) {
        mensagemAoSalvar = avisoPremium;
        irPara = 'secao-preco';
    } else if (ficha.aviso) {
        mensagemAoSalvar = ficha.aviso;
        irPara = /título/i.test(ficha.aviso) ? 'ficha-titulo' : (/ficha técnica/i.test(ficha.aviso) ? 'secao-ficha-tecnica' : (/descrição/i.test(ficha.aviso) ? 'descricao-produto' : null));
    } else if (errosDasVariacoes.length > 0) {
        mensagemAoSalvar = errosDasVariacoes.length === 1
            ? 'Não salvamos: confira a variação marcada em vermelho.'
            : `Não salvamos: confira as ${errosDasVariacoes.length} variações marcadas em vermelho.`;
        irPara = `variacao-${errosDasVariacoes[0]}`;
    }
    const meta = [primeira.familia, ambientes.join(', '), variacoesTexto, `${estoqueTotal} un. em estoque`].filter(Boolean);

    // ─── Proteção do que foi digitado (padrão do usePublicador) ─────────────

    useEffect(() => {
        const aoFecharAba = (e) => {
            if (liberado.current || ! alteradoRef.current) return undefined;
            e.preventDefault();
            e.returnValue = '';

            return '';
        };
        window.addEventListener('beforeunload', aoFecharAba);
        const tirarGuarda = router.on('before', (event) => {
            const visita = event.detail.visit;
            if (liberado.current || visita.prefetch || visita.only?.length || visita.except?.length) return true;
            if (! alteradoRef.current) return true;
            if (window.confirm(CONFIRMA_SAIR)) { liberado.current = true; fichaRef.current.esquecerRascunho(); return true; }

            return false;
        });
        // A visita liberada terminou e a ficha continua aqui (a rede caiu, a sessão expirou, outra visita
        // cancelou a primeira): a guarda volta a valer, como no usePublicador (FE-WR-03). Saindo de
        // verdade a ficha desmonta e este ouvinte vai junto.
        const tirarFim = router.on('finish', () => { liberado.current = false; });

        return () => {
            window.removeEventListener('beforeunload', aoFecharAba);
            tirarGuarda();
            tirarFim();
        };
    }, []);

    // Voltar do navegador (botão, Alt+←, gesto do celular): o Inertia troca a página sem o evento
    // `before`. A guarda entra pelo ouvinte de `guardaDoVoltar`, registrado no app.jsx ANTES do Inertia
    // (no próprio window a captura não passa à frente; vale a ordem de registro): com alteração não
    // salva, pergunta; quem fica tem o histórico devolvido à entrada da ficha, e nenhum dos dois
    // popstates chega ao Inertia. Se algo falhar, ainda há o rascunho (FE-CR-02).
    const entradaDaFicha = useRef(entradaAtual());
    const ignorarVolta = useRef(false);
    useEffect(() => {
        const aoNavegarNoHistorico = (e) => {
            clearTimeout(esperaVolta.current);   // a saída pelo histórico chegou (FE-WR-05)
            if (ignorarVolta.current) {
                ignorarVolta.current = false;
                e.stopImmediatePropagation();

                return;
            }
            if (liberado.current || ! alteradoRef.current) return;
            if (window.confirm(CONFIRMA_SAIR)) {
                liberado.current = true;
                fichaRef.current.esquecerRascunho();

                return;
            }
            e.stopImmediatePropagation();
            ignorarVolta.current = true;
            setTimeout(() => { ignorarVolta.current = false; }, 1000);
            window.history.go(passosAte(entradaDaFicha.current));
        };
        return definirGuardaDoVoltar(aoNavegarNoHistorico);
    }, []);

    // ─── Saída para a lista (D-27; revisão FE-WR-05) ────────────────────────
    //
    // Aberta pela lista, a ficha sai voltando no histórico: fica UMA entrada da lista, que se
    // recarrega ao montar. Sem a lista atrás (URL direta, outra aba), visita a lista trocando a
    // entrada da ficha. A entrada da ficha que fica à frente recebe antes os dados de agora (`entrada`),
    // para o "avançar" do navegador não abrir a ficha velha.

    const [abertaPelaLista] = useState(() => fichaAbertaPelaLista());
    useEffect(() => { esquecerAbertura(); }, []);
    useEffect(() => () => clearTimeout(esperaVolta.current), []);

    const irParaLista = ({ aviso = null, produtoId = null, entrada = null } = {}) => {
        if (! podeVoltarNoHistorico(abertaPelaLista)) {
            voltarParaLista({ aviso, produtoId, replace: true });

            return;
        }
        const voltar = () => {
            // Se o popstate não vier (histórico diferente do esperado), a visita comum leva para a lista.
            esperaVolta.current = setTimeout(() => voltarParaLista({ aviso, produtoId, replace: true }), 1500);
            voltarPeloHistorico({ aviso, produtoId });
        };
        if (entrada) router.replace({ ...entrada, preserveState: true, preserveScroll: true, onFinish: voltar });
        else voltar();
    };

    /** A entrada da ficha com o que o servidor acabou de devolver: URL do produto e as variações de agora. */
    const entradaDoProduto = (id, data, salvosDaFicha = null) => {
        const porId = new Map();
        (data?.linhas ?? []).filter((l) => l.produto_id === id).forEach((l) => porId.set(l.id, l));
        const atuais = [...porId.values()];

        return {
            url: route('portal.auth.estrutura.produtos.ficha', id, false),
            props: (props) => ({ ...props, produto: { id, nome: atuais[0]?.nome ?? props.produto?.nome ?? '' }, linhas: atuais, listas: data?.listas ?? props.listas,
                ...(salvosDaFicha ? { ficha_tecnica: { salvos: salvosDaFicha } } : {}),
            }),
        };
    };

    // ─── Ações ──────────────────────────────────────────────────────────────

    /** Cancelar e "← Produtos": com alteração não salva, a confirmação de sempre. */
    const sair = () => {
        if (alteradoRef.current && ! liberado.current) {
            if (! window.confirm(CONFIRMA_SAIR)) return;
            liberado.current = true;
            ficha.esquecerRascunho();
        }
        irParaLista();
    };

    const aoClicarProdutos = (e) => {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
        e.preventDefault();
        sair();
    };

    const salvarProduto = async () => {
        const novo = ficha.novoProduto;
        if (! novo && premiumPendente.length > 0) {
            setAvisoPremium(`Não salvamos: o preço do Premium está igual ao do Clássico${premiumPendente.length > 1 ? ` (${premiumPendente.join(', ')})` : ''}. Informe a comissão do Premium na Precificação.`);
            setAbrirPremium((n) => n + 1);

            return;
        }
        setAvisoPremium(null);
        const r = await ficha.salvar();
        if (r.data?.listas) setListas(r.data.listas);
        // Produto novo ganha id no 1º lote gravado, mesmo se outro lote falhar: a volta já sabe qual destacar.
        const idGravado = (r.data?.linhas ?? []).find((l) => l.produto_id)?.produto_id;
        if (idGravado) ultimoRef.current = idGravado;
        if (! r.ok) {
            // Erros nos blocos; a gravação parcial já foi aplicada pelo hook. Produto novo que passou a
            // existir deixa de estar em /novo: recarregar abre o produto, com o rascunho do resto (FE-IN-10).
            if (novo && idGravado) router.replace({ ...entradaDoProduto(idGravado, r.data), preserveState: true, preserveScroll: true });

            return;
        }

        liberado.current = true;
        irParaLista({
            aviso: textoProdutoSalvo(r.data),
            // Produto novo: a lista rola até o cartão dele; editado, volta à mesma rolagem.
            produtoId: novo ? (idGravado ?? null) : null,
            entrada: idGravado ? entradaDoProduto(idGravado, r.data, r.fichaTecnica) : null,
        });
    };

    const excluir = (variacao) => {
        if (! variacao.id) { ficha.removerVariacao(variacao._k); return; }   // ainda não gravada: some sem confirmação
        const ultima = ficha.vars.filter((x) => x.id).length <= 1;
        // Última gravada com variação nova na ficha: excluir levaria o produto e a nova junto (FE-WR-04).
        setExclusao({ linha: variacao, ultima, novasNaoSalvas: ultima && ficha.vars.some((x) => ! x.id) });
    };

    const aoExcluida = (resposta) => {
        const linha = exclusao.linha;
        setExclusao(null);
        const sobraram = ficha.vars.filter((x) => x.id && x._k !== linha._k).length;
        if (resposta?.produto_excluido || sobraram === 0) {
            sairComProdutoExcluido(resposta?.mensagem ?? null);

            return;
        }
        ficha.removerVariacao(linha._k);
    };

    /** O produto deixou de existir (a última variação o levou, ou "Excluir produto"): volta à lista sem nada a destacar. */
    const sairComProdutoExcluido = (aviso) => {
        liberado.current = true;
        ultimoRef.current = null;
        ficha.esquecerRascunho();
        irParaLista({
            aviso,
            // A entrada à frente deixa de apontar para o produto excluído: o "avançar" abre uma ficha em branco.
            entrada: { url: route('portal.auth.estrutura.produtos.novo', {}, false), props: (props) => ({ ...props, produto: null, linhas: [] }) },
        });
    };

    /** O produto inteiro saiu (10/10/2026): a mesma saída de quando a última variação o leva. */
    const aoExcluirProduto = (resposta) => {
        setExcluindoProduto(false);
        sairComProdutoExcluido(resposta?.mensagem ?? null);
    };

    const novaVariacao = () => {
        const k = ficha.novaVariacao();
        abrirVariacao(k);
        requestAnimationFrame(() => {
            document.getElementById(`valor-${k}`)?.focus();
            document.getElementById(`variacao-${k}`)?.scrollIntoView({ block: 'nearest' });
        });
    };

    // Cor E tamanho (28/10/2026): o novo tamanho nasce dentro da cor, copiando custo e caixa do último dela.
    const novoNaCor = (cor, chaveBase) => {
        const k = ficha.novaVariacao({ valor: cor }, chaveBase);
        abrirVariacao(k);
        requestAnimationFrame(() => {
            document.getElementById(`valor2-${k}`)?.focus();
            document.getElementById(`variacao-${k}`)?.scrollIntoView({ block: 'nearest' });
        });
    };

    // A cor nova nasce com o 1º tamanho; o foco vai ao nome da cor.
    const novaCor = () => {
        const k = ficha.novaVariacao({ valor: '' });
        abrirVariacao(k);
        requestAnimationFrame(() => {
            document.getElementById(`cor-${k}`)?.focus();
            document.getElementById(`cor-${k}`)?.scrollIntoView({ block: 'nearest' });
        });
    };

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo={nome}>
            {/* A largura toda (29/10/2026: "não gostei desses espaços sem nada" dos lados). */}
            <div className="w-full px-4 pt-5 sm:px-6 lg:px-8" data-ficha-produto>
                <nav aria-label="Caminho" className="flex items-center gap-2 text-[13.5px] text-white/65">
                    <ArrowLeft size={17} aria-hidden="true" />
                    <a href={urlDeVolta()} onClick={aoClicarProdutos} className="hover:text-white">Produtos</a>
                    <ChevronRight size={14} aria-hidden="true" className="text-white/35" />
                    <span className="truncate text-white">{nome}</span>
                </nav>

                {/* Cabeçalho compacto: a foto, o nome e o resumo do produto numa linha (o H1 de 42 px e a
                    frase de abertura empurravam a ficha para baixo). "Excluir produto" sai do rodapé. */}
                <header className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                    <QuadroFotoProduto nome={nome} foto={fotoDoProduto(ficha.vars)} tamanho="mini" />
                    <div className="min-w-0">
                        <h1 className="font-display text-[26px] font-bold leading-tight text-white">{nome}</h1>
                        <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-[13px] text-white/55" data-resumo-produto>
                            {meta.map((m, i) => <span key={i} className="flex items-center gap-2">{i > 0 && <span aria-hidden="true" className="text-white/25">·</span>}{m}</span>)}
                        </p>
                    </div>
                    {primeira.origem_ml && <SeloImportadoDoMl origem={primeira.origem_ml} />}
                    {! ficha.novoProduto && (
                        <button type="button" onClick={() => setExcluindoProduto(true)} disabled={ficha.salvando} data-acao="excluir-produto"
                            className="ml-auto inline-flex h-9 items-center gap-2 rounded-lg px-3 text-[13px] text-red-300/85 hover:bg-red-500/10 hover:text-red-200 disabled:opacity-60">
                            <Trash2 size={15} /> Excluir produto
                        </button>
                    )}
                </header>

                {ficha.rascunho && (
                    <div role="status" className="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[12.5px] text-white/60" data-rascunho>
                        <span>Você tinha alterações não salvas neste produto.</span>
                        <span className="flex items-center gap-3">
                            <button type="button" onClick={ficha.recuperarRascunho} disabled={ficha.salvando} data-acao="recuperar-rascunho" className="font-medium text-white/85 hover:text-white hover:underline">Recuperar</button>
                            <button type="button" onClick={ficha.descartarRascunho} disabled={ficha.salvando} data-acao="descartar-rascunho" className="text-white/50 hover:text-white">Descartar</button>
                        </span>
                    </div>
                )}

                {/* Produto novo: o que estava digitado antes de recarregar voltou sozinho (demo 23/10, N12). */}
                {ficha.recuperado && (
                    <div role="status" className="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-white/20 bg-white/[0.06] px-3 py-2 text-[13px] text-white/85" data-rascunho-recuperado>
                        <span>Recuperamos o que você tinha digitado neste produto. Ele ainda não foi salvo.</span>
                        <button type="button" onClick={ficha.recomecar} disabled={ficha.salvando} data-acao="comecar-do-zero" className="text-white/60 hover:text-white hover:underline">Começar do zero</button>
                    </div>
                )}

                <div className="mt-5 lg:grid lg:grid-cols-[248px_minmax(0,1fr)] lg:items-start lg:gap-7">
                    {/* O mapa da ficha: o estado de cada seção e o que falta (só no computador). */}
                    <aside className="hidden lg:sticky lg:top-4 lg:block">
                        <IndiceDaFicha secoes={secoes} />
                    </aside>

                    <div className="flex min-w-0 flex-col gap-4">
                        {/* Durante o "Salvando…" nada se edita: o envio usa a foto do clique e o que se digitasse se perderia (FE-WR-02). */}
                        <fieldset disabled={ficha.salvando} className="flex min-w-0 flex-col gap-4 border-0 p-0" data-campos-ficha>
                            <FichaDadosGerais ficha={ficha} listas={listas} onListas={setListas} />
                            {/* O título logo depois do nome e da categoria: os termos dele dependem dos dois (19/10/2026). */}
                            <FichaTitulo ficha={ficha} />
                        </fieldset>

                        {/* As variações: o miolo do cadastro (Ref, estoque, fotos), com custo e caixa iguais em todas. */}
                        <CoresDoProduto ficha={ficha} heranca={heranca} abertas={abertas} onAlternar={alternarVariacao} onExcluir={excluir}
                            onNovaVariacao={novaVariacao} onNovaNaCor={novoNaCor} onNovaCor={novaCor} frase={variacaoVira.frase || null} />

                        {/* O produto montado e a caixa de envio lado a lado, na mesma ordem de medidas. */}
                        <MedidasEEnvio ficha={ficha} heranca={heranca} />

                        <FichaTecnica tecnica={ficha.tecnica} salvando={ficha.salvando} />
                        <FichaDescricao descricao={ficha.descricao} salvando={ficha.salvando} explicacao={ficha.explicacoes.descricao} />

                        {/* A Precificação no fim (29/10/2026): o custo é das variações, o frete das caixas; o preço sai
                            daqui mesmo, sem trocar de tela. O frete e os percentuais gravam na hora; o custo, com "Salvar produto". */}
                        <div id="secao-preco" className="scroll-mt-4 [&>section]:mt-0">
                            <PrecificacaoDoProduto ficha={ficha} produtoId={produto?.id ?? null} nomeDoProduto={produto?.nome ?? ''} linhas={linhas}
                                onPremiumPendente={(nomes) => { setPremiumPendente(nomes); if (nomes.length === 0) setAvisoPremium(null); }} abrirPremium={abrirPremium} />
                        </div>
                    </div>
                </div>

                {/* O Salvar sempre à mão, com o motivo de não ter salvado ao lado (demo 23/10, H3). */}
                <BarraDeSalvar alterado={ficha.alterado} mensagem={mensagemAoSalvar} irPara={irPara} salvando={ficha.salvando}
                    onCancelar={sair} onSalvar={salvarProduto} data-aviso-premium={avisoPremium ? 'sim' : undefined} />
            </div>

            <JanelaExcluirVariacao aberta={!! exclusao} linha={exclusao?.linha} ultima={exclusao?.ultima} novasNaoSalvas={exclusao?.novasNaoSalvas}
                onFechar={() => setExclusao(null)} onExcluida={aoExcluida} />
            <JanelaExcluirProdutos aberta={excluindoProduto} ids={ficha.primeira.produto_id ? [ficha.primeira.produto_id] : []}
                onFechar={() => setExcluindoProduto(false)} onExcluidos={aoExcluirProduto} />
            <AvisoFlash />
        </PortalClienteLayout>
    );
}
