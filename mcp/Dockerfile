FROM node:20-alpine

WORKDIR /app

# Install dependencies first (better layer caching).
COPY package.json ./
RUN npm install --omit=dev

# App source
COPY server.js tools.js ./

ENV NODE_ENV=production
EXPOSE 3000

CMD ["node", "server.js"]
