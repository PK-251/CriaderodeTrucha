# dashboard — Next.js 14 · React 18 · TypeScript
# Entorno de desarrollo con recarga en caliente sobre el codigo montado.
# La construccion de produccion se verifica con `npm run build`.
# Debian y no Alpine: el runner de integracion continua usa glibc, y
# package-lock.json fija los binarios opcionales de rollup por plataforma. Con
# Alpine (musl) en desarrollo, el bloqueo generado aqui omite
# @rollup/rollup-linux-x64-gnu y el pipeline falla al ejecutar vitest.
# Compartir libc entre el contenedor y el CI elimina esa clase de fallo.
FROM node:20-slim

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install

COPY . /app

EXPOSE 3000
CMD ["npm", "run", "dev"]
