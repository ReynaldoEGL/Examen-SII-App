FROM node:22-alpine
WORKDIR /app
COPY package*.json ./
RUN npm install --no-audit --no-fund
COPY . .
EXPOSE 3000
# The bind mount hides image files in development. Install/update dependencies
# in the named node_modules volume before starting Next.js.
CMD ["sh", "-lc", "npm install --no-audit --no-fund && npm run dev"]
