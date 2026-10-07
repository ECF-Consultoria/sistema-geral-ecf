import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate dos componentes da aba Sugestões (Fase 168-14; sem planilha na tela, D-23 da 167).
//
// POR QUE EXISTE: as sugestões são decididas item a item, em CARTÕES. Nenhuma regra de
// combinação, chave, logística ou frete mora no navegador (T-168-46): o servidor manda
// pronto. Aqui: o contrato de cada peça e a barreira contra planilha e regra duplicada.
// ═══════════════════════════════════════════════════════════════════════

const dir = 'resources/js/Components/Portal/Estrutura/Sugestoes';
const ler = (nome) => lerSemComentarios(`${dir}/${nome}.jsx`);

const ARQUIVOS = {
    CartaoSugestao: ['data-chave', 'aria-labelledby', 'podeAceitar(', 'valorDoCampo(', 'Não aceitamos esta sugestão', 'PilulaLogistica', 'Desfazer edição'],
    CabecalhoFamilia: ['(continua)', 'nesta página'],
    FiltrosSugestoes: ['Buscar produto, código ou nome…', 'Consultar fretes desta página no Mercado Livre', 'chaves_filtradas'],
    BarraDeMarcadas: ['role="region"', 'aria-live="polite"', 'variante'],
    ExplicacaoDasOfertas: [
        'ecf.sugestoes.explicacao',
        'o mesmo produto em mais unidades — Kit 4 cadeiras.',
        'produtos diferentes juntos — mesa + banco.',
        'um kit com mais unidades de um item — mesa + 4 cadeiras.',
    ],
    AvisoSugestoes: ['role="status"', 'role="alert"'],
};

for (const [nome, ancoras] of Object.entries(ARQUIVOS)) {
    test(`${nome}: export default, sem planilha nem regra de negócio, com as âncoras do contrato`, () => {
        const fonte = ler(nome);
        assert.match(fonte, /export default function /);
        assert.ok(! fonte.includes('SpreadsheetGrid'), 'sem planilha na tela');
        assert.ok(! fonte.includes('<table'), 'sem tabela');
        assert.ok(! fonte.includes('uppercase'), 'sem caixa-alta');
        assert.ok(! fonte.includes('fator_cubagem'), 'sem fator de cubagem no navegador');
        assert.ok(! fonte.includes('daVolumes'), 'sem cálculo de volumes no navegador');
        assert.doesNotMatch(fonte, /v\d+\*/, 'sem a regex da chave');
        for (const a of ancoras) assert.ok(fonte.includes(a), `${nome} deve conter: ${a}`);
    });
}
