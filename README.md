<div align="center">

# 🔒 Ilusion Vault

**Open-source, zero-knowledge encrypted data vault for storing and sharing passwords, API keys, and files securely.**

[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel 11](https://img.shields.io/badge/Laravel-11-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![Vue 3](https://img.shields.io/badge/Vue.js-3-4FC08D?style=flat-square&logo=vuedotjs&logoColor=white)](https://vuejs.org)
[![TypeScript](https://img.shields.io/badge/TypeScript-Strict-3178C6?style=flat-square&logo=typescript&logoColor=white)](https://www.typescriptlang.org)
[![Tests](https://img.shields.io/badge/Tests-55%20Passed-brightgreen?style=flat-square&logo=pest)](https://pestphp.com)
[![Static Analysis](https://img.shields.io/badge/PHPStan-Level%200%20Errors-brightgreen)](https://phpstan.org)
[![License](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE)

[Live Application](https://ilusion.io) • [Documentation](CONTRIBUTING.md) • [Report Bug](https://github.com/shreyashrpawar/ilusion-vault-repo/issues)

</div>

---

## 🌟 Overview

**Ilusion Vault** is a high-performance, open-source zero-knowledge platform designed to eliminate plain-text credential leaks. Secrets and attached files are encrypted locally inside your browser using **AES-256-GCM WebCrypto APIs** and dedicated **Web Workers** prior to network transmission.

The server only stores encrypted ciphertexts and metadata — **your master encryption key never reaches the server or log files**.

---

## ✨ Features

- 🔐 **Zero-Knowledge Encryption** — AES-GCM (256-bit) client-side encryption powered by WebCrypto. Plaintext and encryption keys never leave your device.
- 📁 **Encrypted File Uploads** — Direct multi-file uploads with background worker batch encryption and Cloudflare R2 / S3 streaming.
- ⏱️ **Auto-Burn & Expiration** — Set secrets to self-destruct after 1 view, 1 Hour, 1 Day, 1 Week, or retain permanently in your encrypted vault.
- ⚡ **High-Performance Crypto Engine** — PBKDF2 key derivation and batch encryption handled seamlessly via background Web Workers for smooth UI responsiveness.
- 🔑 **Two-Factor Authentication** — Account security backed by Laravel Fortify 2FA and TOTP support.
- 📧 **Recipient Notifications** — Secure email dispatch notifying target recipients when encrypted payloads are ready.
- 📱 **QR Code Sharing** — Instant mobile decryption key access via generated QR codes.

---

## 🏗️ Architecture & Security Model

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              CLIENT BROWSER                             │
│                                                                         │
│  Plaintext Data / File ──► Web Worker (PBKDF2 / AES-256-GCM) ──► Ciphertext
└────────────────────────────────────┬────────────────────────────────────┘
                                     │ (Network Payload)
                                     ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                             LARAVEL BACKEND                             │
│                                                                         │
│  Stores: Ciphertext Metadata & Encrypted Objects (S3/R2)               │
│  NEVER Sees: Plaintext, Passphrases, or URL Hash (#key)                │
└─────────────────────────────────────────────────────────────────────────┘
```

1. **Browser Encryption**: Passphrase derivation uses 600,000-iteration PBKDF2 key hashing inside Web Workers.
2. **Zero-Knowledge Link Transmission**: Decryption keys travel inside the URL hash fragment (`#key`). RFC specifications guarantee browsers never send hash fragments to backend web servers.

---

## 🛠️ Tech Stack

| Layer | Technology & Standards |
|---|---|
| **Language & Runtime** | PHP 8.4+ (Strict Types, Nullable Types, Typed Properties) |
| **Backend Framework** | Laravel 11.x, Laravel Fortify |
| **Frontend Framework** | Vue 3 (Composition API `<script setup lang="ts">`), Inertia.js |
| **Type System** | TypeScript (Strict Mode), ESLint |
| **Database** | SQLite, MySQL 8+, PostgreSQL |
| **Object Storage** | S3-Compatible (Cloudflare R2, AWS S3) |
| **Testing & Quality** | Pest PHP (55 Tests), PHPStan Static Analysis |

---

## 🚀 Quick Start

### Prerequisites
- **PHP 8.4+**
- **Composer 2.x**
- **Node.js 18+** & **npm**
- **SQLite** or **MySQL**

### Setup Instructions

1. **Clone the repository**:
   ```bash
   git clone https://github.com/shreyashrpawar/ilusion-vault-repo.git
   cd ilusion-vault-repo
   ```

2. **Install PHP and Node dependencies**:
   ```bash
   composer install
   npm install
   ```

3. **Environment Setup**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Database & Migrations**:
   ```bash
   touch database/database.sqlite
   php artisan migrate --seed
   ```

5. **Start Development Servers**:
   ```bash
   # Run Laravel Backend
   php artisan serve

   # Run Vite Development Server
   npm run dev
   ```

---

## ⚙️ Encrypted Storage Configuration (Cloudflare R2 / S3)

To enable zero-knowledge encrypted file uploads, configure your S3 or Cloudflare R2 credentials in `.env`:

```env
FILESYSTEM_DISK=r2

R2_ACCESS_KEY_ID="your_access_key"
R2_SECRET_ACCESS_KEY="your_secret_key"
R2_BUCKET_NAME="your_bucket"
R2_URL="https://your-custom-domain.com"
R2_ENDPOINT="https://<ACCOUNT_ID>.r2.cloudflarestorage.com"
```

---

## 🧪 Testing & Code Quality

Maintaining high code quality and strict type safety is mandatory for Ilusion Vault.

```bash
# Run Pest test suite (55 tests)
vendor/bin/pest

# Run PHPStan static analysis (Level 0 errors)
vendor/bin/phpstan analyse --memory-limit=2G

# Run ESLint frontend linting (0 errors)
npm run lint

# Build production assets
npm run build
```

---

## 🤝 Contributing

We welcome community contributions! Please read our [CONTRIBUTING.md](CONTRIBUTING.md) guide before opening pull requests or reporting security issues.

---

## 📄 License

Ilusion Vault is open-source software licensed under the [MIT License](LICENSE).
