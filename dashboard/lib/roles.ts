import type { Rol } from './tipos';

/**
 * Cómo se presenta el tablero a cada rol.
 *
 * Lo que cambia entre roles es el ACENTO (cabecera, botones, foco) y el
 * CONTENIDO (qué secciones ve y en qué orden). Lo que NO cambia es la paleta
 * de estado: el rojo crítico significa lo mismo en el celular del operador que
 * en el del técnico, y si cada rol tuviera la suya, quien mira la pantalla de
 * otro tendría que reaprender el semáforo.
 */
export const ROLES: Record<Rol, { nombre: string; vista: string; enfoque: string }> = {
  operador: {
    nombre: 'Operador',
    vista: 'Vista de campo',
    enfoque: 'Alertas primero: lo que exige ir al estanque ahora.',
  },
  tecnico: {
    nombre: 'Técnico acuícola',
    vista: 'Vista técnica',
    enfoque: 'Estado, tendencias y configuración de estanques y umbrales.',
  },
  veterinario: {
    nombre: 'Veterinario',
    vista: 'Vista sanitaria',
    enfoque: 'Consulta del estado y de las alertas; la intervención la registra el operador.',
  },
};
