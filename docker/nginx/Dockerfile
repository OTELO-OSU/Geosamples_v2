# Compiled Vue/Vuetify frontend, served by nginx which proxies /api to php-fpm
FROM node:26-alpine AS builder
WORKDIR /app
COPY front/package.json front/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY front/ ./
RUN npm run build

FROM nginx:1.31-alpine
COPY --from=builder /app/dist /var/www/frontend/dist
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
