# dashboard — Next.js 14 · React 18 · TypeScript
# Entorno de desarrollo con recarga en caliente sobre el codigo montado.
# La construccion de produccion se verifica con `npm run build`.
FROM node:20-alpine

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install

COPY . /app

EXPOSE 3000
CMD ["npm", "run", "dev"]
