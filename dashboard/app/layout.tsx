import type { Metadata, Viewport } from 'next';

import './globals.css';

export const metadata: Metadata = {
  title: 'SIPPT · Tablero de estanques',
  description:
    'Monitoreo continuo de oxígeno disuelto, temperatura y pH con alertas por umbral — Primer Incremento (PMV).',
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  // El operador consulta el tablero en campo: el zoom no se bloquea, porque
  // impedirlo dejaría fuera a quien necesita ampliar para leer.
  maximumScale: 5,
  themeColor: [
    { media: '(prefers-color-scheme: light)', color: '#f9f9f7' },
    { media: '(prefers-color-scheme: dark)', color: '#0d0d0d' },
  ],
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="es-PE">
      <body>{children}</body>
    </html>
  );
}
