import { describe, expect, it } from 'vitest';
import { DEFAULT_ISSUE_LABEL, defaultIssueLabels, issueBody, issueTitle } from './issue';

describe('issueTitle', () => {
    it('starts with the request key and the task key', () => {
        expect(issueTitle('GIA-139', { key: 'T4', title: 'Consulta de cartera por asesor desde SAP B1' })).toBe(
            'GIA-139 · T4 Consulta de cartera por asesor desde SAP B1',
        );
    });

    it('works for a request without a task', () => {
        expect(issueTitle('gia-148', null, 'Avisar al asesor cuando el pedido quede bloqueado')).toBe(
            'GIA-148 · Avisar al asesor cuando el pedido quede bloqueado',
        );
    });

    it('trims whitespace and caps the length GitHub accepts', () => {
        const long = 'x'.repeat(300);
        const title = issueTitle('GIA-1', { key: 'T1', title: `  ${long}  ` });
        expect(title.startsWith('GIA-1 · T1 x')).toBe(true);
        expect(title.length).toBeLessThanOrEqual(256);
    });
});

describe('issueBody', () => {
    const body = issueBody({
        description: 'Traer de SAP B1 la cartera de cada asesor con su zona.',
        criteria: ['Ver el total vencido por asesor y por rango de días', '  ', 'Cargar en menos de 3 segundos con toda la cartera'],
        link: { label: 'Ver T4 en el Gestor', url: 'https://gestor.example/ai/projects/P-07/tasks/T4' },
        requestKey: 'GIA-139',
    });

    it('has the description, the criteria as a checklist and the link back', () => {
        expect(body).toContain('Traer de SAP B1 la cartera de cada asesor con su zona.');
        expect(body).toContain('## Criterios de aceptación');
        expect(body).toContain('- [ ] Ver el total vencido por asesor y por rango de días');
        expect(body).toContain('- [ ] Cargar en menos de 3 segundos con toda la cartera');
        expect(body).toContain('[Ver T4 en el Gestor](https://gestor.example/ai/projects/P-07/tasks/T4)');
        expect(body).toContain('solicitud GIA-139');
    });

    it('skips blank criteria', () => {
        expect(body.match(/- \[ \]/g)).toHaveLength(2);
    });

    it('ends with the privacy footer', () => {
        expect(body.trimEnd().endsWith('No incluye costos ni notas internas.')).toBe(true);
    });

    it('omits the criteria heading when there are none', () => {
        const plain = issueBody({ description: 'Algo', criteria: [], link: { label: 'Ver', url: 'https://x.test' }, requestKey: 'GIA-1' });
        expect(plain).not.toContain('## Criterios');
    });
});

describe('defaultIssueLabels', () => {
    it('always starts with gestor-ia and adds the task labels as slugs, without repeats', () => {
        expect(DEFAULT_ISSUE_LABEL).toBe('gestor-ia');
        expect(defaultIssueLabels(['SAP', 'Backend'])).toEqual(['gestor-ia', 'sap', 'backend']);
        expect(defaultIssueLabels(['Documentación', 'gestor-ia', 'SAP', 'sap'])).toEqual(['gestor-ia', 'documentacion', 'sap']);
        expect(defaultIssueLabels()).toEqual(['gestor-ia']);
    });
});
