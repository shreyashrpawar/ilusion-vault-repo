# Contributing to Ilusion Vault

Thank you for your interest in contributing to **Ilusion Vault**! We welcome contributions from developers of all skill levels to help make open-source zero-knowledge storage and secret sharing more accessible, reliable, and secure.

---

## 🔒 Security Model & Architectural Integrity

Ilusion Vault relies on a strict **Zero-Knowledge Architecture**:
- All encryption and decryption of secret contents and vault files occur **entirely client-side** in the browser using standard Web Crypto APIs and background Web Workers.
- Encryption keys and unencrypted payloads **must never be transmitted to or stored on the server**.
- The Laravel backend is responsible only for metadata management, rate-limiting, secure storage streaming (S3/R2 pre-signed URLs), and expiration enforcement.

> **Important**: Any proposed change or pull request that risks exposing client-side key material or unencrypted content to the server will be rejected immediately.

---

## 🛠️ Local Development Setup

### Prerequisites
- **PHP 8.4+** with required extensions (`bcmath`, `ctype`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`)
- **Composer 2.x**
- **Node.js 18+** & **npm**
- **Database**: SQLite (default for development/testing) or MySQL / PostgreSQL

### Quickstart

1. **Clone the repository**:
   ```bash
   git clone https://github.com/shreyashrpawar/ilusion-vault.git
   cd ilusion-vault
   ```

2. **Install PHP and Node dependencies**:
   ```bash
   composer install
   npm install
   ```

3. **Configure Environment**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Run Database Migrations**:
   ```bash
   touch database/database.sqlite # if using SQLite
   php artisan migrate --seed
   ```

5. **Start Development Servers**:
   ```bash
   # In terminal 1 (Laravel backend)
   php artisan serve

   # In terminal 2 (Vite frontend compiler)
   npm run dev
   ```

---

## 🧪 Testing & Code Quality Standards

We enforce strict quality standards for both backend and frontend code to maintain production reliability and long-term maintainability.

### Backend (PHP 8.4 & Pest)

- **Static Analysis (PHPStan)**: All PHP code must pass PHPStan analysis with **0 errors**.
  ```bash
  vendor/bin/phpstan analyse --memory-limit=2G
  ```
- **Automated Test Suite (Pest)**: Ensure all unit, feature, and architecture tests pass before submitting a PR.
  ```bash
  vendor/bin/pest
  ```
- **PHP Code Style**: Follow PSR-12 and Laravel Pint standards.
  ```bash
  vendor/bin/pint
  ```

### Frontend (Vue 3, TypeScript & ESLint)

- **TypeScript Strictness**: Use TypeScript for all scripts in `.vue` and `.ts` files (`<script setup lang="ts">`). Avoid using `any` where explicit types can be inferred or defined.
- **Linting**: Ensure there are no unused variables, unhandled promises, or linting warnings.
  ```bash
  npm run lint
  ```
- **Production Build Check**: Verify that Vite can compile without asset build errors.
  ```bash
  npm run build
  ```

---

## 🔀 Pull Request Guidelines

1. **Create a Feature Branch**:
   - `feature/description` for new features
   - `fix/description` for bug fixes
   - `docs/description` for documentation improvements

2. **Commit Messages**:
   - Write clear, imperative commit messages (e.g., `Add two-factor recovery code regeneration feedback`, `Fix vault file expiration query`).

3. **PR Checklist**:
   - [ ] PHPStan returns 0 errors (`vendor/bin/phpstan analyse --memory-limit=2G`).
   - [ ] All Pest tests pass (`vendor/bin/pest`).
   - [ ] ESLint returns 0 warnings/errors (`npm run lint`).
   - [ ] Production build succeeds (`npm run build`).
   - [ ] Relevant documentation or inline docblocks updated.

---

## 🛡️ Responsible Disclosure of Security Vulnerabilities

If you discover a security vulnerability within Ilusion Vault, please do **NOT** open a public GitHub issue. Instead, report the security concern directly to the maintainers via email or security advisory on GitHub. We will address and remediate verified security issues promptly.

Thank you for helping keep Ilusion Vault secure and developer-friendly!
