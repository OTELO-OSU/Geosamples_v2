# Front Vue/Vuetify compilé, servi par nginx qui relaie /api vers php-fpm
FROM node:24-alpine AS builder
WORKDIR /app
COPY front/package.json front/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY front/ ./
RUN npm run build

FROM nginx:1.29-alpine
COPY --from=builder /app/dist /var/www/frontend/dist
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
