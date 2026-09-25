/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  // El tablero se sirve desde el equipo de borde de la piscigranja, sin CDN:
  // la salida autónoma reduce la imagen y el tiempo de arranque.
  output: 'standalone',
  logging: { fetches: { fullUrl: false } },
};

export default nextConfig;
