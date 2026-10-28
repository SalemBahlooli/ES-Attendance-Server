FROM node:20-alpine
WORKDIR /app
ENV NODE_ENV=production
COPY package.json package-lock.json ./
# Fail the build if the install silently broke (npm can exit 0 on network errors)
RUN npm ci --omit=dev && node -e "require('express'); require('pg'); require('exceljs')"
COPY . .
ENV PORT=8080
EXPOSE 8080
USER node
CMD ["node", "server.js"]
