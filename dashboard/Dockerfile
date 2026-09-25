# dashboard — Next.js 14 · React 18 · TypeScript
# Fase 0: entorno base Node 20 y servidor de salud.
# La pantalla operativa del PMV (tarjetas, panel de alertas, tabla de lecturas
# y suscripción WebSocket) se implementa en la Fase 5.
FROM node:20-alpine

WORKDIR /app
COPY package.json ./
COPY . /app

EXPOSE 3000
CMD ["node", "server-andamiaje.mjs"]
