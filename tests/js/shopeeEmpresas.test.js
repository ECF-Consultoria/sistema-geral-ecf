import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { filtrarPorGrupo, opcoesDeGrupo, SEM_GRUPO, estadoDoLink } from '../../resources/js/lib/shopeeEmpresas.js';

// ═══════════════════════════════════════════════════════════════════════
// Lente por grupo da aba Empresas da Shopee.
//
// As duas armadilhas que estes testes prendem:
//  1. o value do <option> é STRING e o company_group_id vem NÚMERO do backend —
//     comparar com === direto devolveria lista vazia em todo grupo;
//  2. a contagem do select tem de sair das empresas da TELA (Shopee), não do
//     companies_count do backend, que conta a carteira inteira (ML incluído).
// ═══════════════════════════════════════════════════════════════════════

const EMPRESAS = [
    { id: 1, name: 'CAMILLO MATRIZ', company_group_id: 3 },
    { id: 2, name: 'CAMILLO FILIAL', company_group_id: 3 },
    { id: 3, name: 'Utilarshop',     company_group_id: 10 },
    { id: 4, name: 'Ita Prime',      company_group_id: null },
    { id: 5, name: 'POZELAR' }, // sem a chave: também é "sem grupo"
];

const GRUPOS = [
    { id: 3,  name: 'Camillo Parts', color: '#fff', companies_count: 42 }, // 42 = carteira inteira
    { id: 10, name: 'Utilar',        color: '#fff', companies_count: 9 },
    { id: 15, name: 'Milani',        color: '#fff', companies_count: 4 }, // nenhuma empresa Shopee
];

describe('filtrarPorGrupo', () => {
    test('sem filtro devolve a lista inteira', () => {
        assert.equal(filtrarPorGrupo(EMPRESAS, '').length, 5);
    });

    test('id vindo do select (string) casa com company_group_id (número)', () => {
        const nomes = filtrarPorGrupo(EMPRESAS, '3').map(c => c.name);
        assert.deepEqual(nomes, ['CAMILLO MATRIZ', 'CAMILLO FILIAL']);
    });

    test('SEM_GRUPO pega null e chave ausente', () => {
        const nomes = filtrarPorGrupo(EMPRESAS, SEM_GRUPO).map(c => c.name);
        assert.deepEqual(nomes, ['Ita Prime', 'POZELAR']);
    });

    test('grupo sem empresa na lista devolve vazio, não a lista toda', () => {
        assert.deepEqual(filtrarPorGrupo(EMPRESAS, '15'), []);
    });
});

describe('opcoesDeGrupo', () => {
    test('conta as empresas DA TELA, não o companies_count do backend', () => {
        const { opcoes } = opcoesDeGrupo(EMPRESAS, GRUPOS);
        assert.deepEqual(opcoes.map(g => [g.name, g.qtd]), [
            ['Camillo Parts', 2],
            ['Utilar', 1],
        ]);
    });

    test('grupo sem empresa Shopee fica fora da lista', () => {
        const { opcoes } = opcoesDeGrupo(EMPRESAS, GRUPOS);
        assert.equal(opcoes.some(g => g.name === 'Milani'), false);
    });

    test('conta separadamente quem não está em grupo nenhum', () => {
        assert.equal(opcoesDeGrupo(EMPRESAS, GRUPOS).semGrupo, 2);
    });

    test('nao quebra sem grupos', () => {
        assert.deepEqual(opcoesDeGrupo(EMPRESAS, []).opcoes, []);
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Coluna "Conexão": o "Regerar" gravava o link novo, mas a célula seguia
// "Link expirado" porque a validade era lida só das props da página.
// ═══════════════════════════════════════════════════════════════════════

describe('estadoDoLink', () => {
    const AGORA = new Date('2026-10-14T12:00:00Z');
    const VENCIDA = {
        shopee_link_url: 'https://admin/shopee/conectar/7?expires=1&signature=velha',
        shopee_link_generated_at: '2026-09-23T12:00:00Z',
        shopee_link_expires_at: '2026-09-30T12:00:00Z',
    };

    test('link do banco vencido: marca vencido e NÃO oferece a url para copiar', () => {
        const link = estadoDoLink(VENCIDA, null, AGORA);
        assert.equal(link.vencido, true);
        assert.equal(link.pendente, false);
        assert.equal(link.url, null);
    });

    test('depois de regerar vale a validade devolvida pelo servidor, não a das props', () => {
        const gerado = {
            url: 'https://admin/shopee/conectar/7?expires=2&signature=nova',
            expires_at: '2026-10-21T12:00:00Z',
        };
        const link = estadoDoLink(VENCIDA, gerado, AGORA);
        assert.equal(link.vencido, false);
        assert.equal(link.pendente, true);
        assert.equal(link.dias, 7);
        assert.equal(link.url, gerado.url);
    });

    test('link regerado sem expires_at na resposta não herda a validade vencida', () => {
        const link = estadoDoLink(VENCIDA, { url: 'https://admin/nova' }, AGORA);
        assert.equal(link.vencido, false);
        assert.equal(link.pendente, true);
        assert.equal(link.url, 'https://admin/nova');
    });

    test('link do banco ainda válido: pendente com os dias restantes', () => {
        const link = estadoDoLink({
            shopee_link_url: 'https://admin/x',
            shopee_link_generated_at: '2026-10-12T12:00:00Z',
            shopee_link_expires_at: '2026-10-19T12:00:00Z',
        }, null, AGORA);
        assert.equal(link.pendente, true);
        assert.equal(link.vencido, false);
        assert.equal(link.dias, 5);
    });

    test('empresa sem link: nem pendente nem vencido (botão "Gerar link")', () => {
        const link = estadoDoLink({ shopee_link_url: null, shopee_link_generated_at: null, shopee_link_expires_at: null }, null, AGORA);
        assert.deepEqual(link, { url: null, dias: null, vencido: false, pendente: false });
    });
});
