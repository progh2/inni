# inni v2 — Node 22 + SQLite. 시놀로지 NAS(x86)·일반 PC 모두 같은 이미지.
FROM node:22-alpine AS deps
WORKDIR /app
# better-sqlite3 미리 빌드된 파일이 없을 때를 대비한 빌드 도구(최종 이미지에는 안 들어감)
RUN apk add --no-cache python3 make g++
COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

FROM node:22-alpine
RUN apk add --no-cache tzdata
ENV NODE_ENV=production TZ=Asia/Seoul PORT=3000 INNI_DATA_DIR=/app/data
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY package.json ./
COPY server ./server
COPY web ./web
VOLUME /app/data
EXPOSE 3000
HEALTHCHECK --interval=15s --timeout=5s --retries=5 --start-period=20s \
  CMD node -e "fetch('http://127.0.0.1:3000/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"
CMD ["node", "server/index.js"]
