// crypto.worker.ts
// Background thread for file encryption/decryption.
// Supports INIT + batch mode: derive the key once from the password,
// then process unlimited files using the cached key with unique IVs.
// Falls back to per-message password derivation for one-off operations.

function arrayBufferToBase64(buffer: ArrayBuffer): string {
    let binary = '';
    const bytes = new Uint8Array(buffer);
    const len = bytes.byteLength;

    for (let i = 0; i < len; i++) {
        binary += String.fromCharCode(bytes[i]);
    }

    return btoa(binary);
}

function base64ToArrayBuffer(base64: string): ArrayBuffer {
    const binaryString = atob(base64);
    const len = binaryString.length;
    const bytes = new Uint8Array(len);

    for (let i = 0; i < len; i++) {
        bytes[i] = binaryString.charCodeAt(i);
    }

    return bytes.buffer;
}

async function deriveKey(password: string, salt: Uint8Array, iterations: number = 600000): Promise<CryptoKey> {
    const encoder = new TextEncoder();
    const baseKey = await self.crypto.subtle.importKey(
        'raw',
        encoder.encode(password),
        'PBKDF2',
        false,
        ['deriveKey']
    );

    return self.crypto.subtle.deriveKey(
        {
            name: 'PBKDF2',
            salt: salt as any,
            iterations: iterations,
            hash: 'SHA-256'
        },
        baseKey,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt']
    );
}

// ── Batch mode state ───────────────────────────────────────────────
// After INIT, the worker caches a single derived key + its salt.
// All subsequent operations reuse this key (with unique IVs per file).
let cachedKey: CryptoKey | null = null;
let cachedSalt: Uint8Array | null = null;

self.onmessage = async (e: MessageEvent) => {
    const { type, payload } = e.data;

    try {
        // ── INIT ───────────────────────────────────────────────────────
        // Derive the key once. All future encrypt/decrypt operations
        // in this worker instance will reuse it.
        if (type === 'INIT') {
            const { password } = payload;
            const salt = self.crypto.getRandomValues(new Uint8Array(16));
            cachedKey = await deriveKey(password, salt);
            cachedSalt = salt;
            self.postMessage({
                type: 'INIT_SUCCESS',
                payload: { salt: arrayBufferToBase64(salt.buffer) }
            });
        } else if (type === 'ENCRYPT_FILE') {
            const { fileBuffer, fileName, fileType, fileSize, password } = payload;

            // Determine the key to use: cached (batch) or derive fresh (one-off)
            let fileKey: CryptoKey;
            let metaKey: CryptoKey;
            let fileSalt: Uint8Array;
            let metaSalt: Uint8Array;

            if (cachedKey && cachedSalt) {
                // Batch mode: reuse cached key, embed the cached salt in output
                fileKey = cachedKey;
                metaKey = cachedKey;
                fileSalt = cachedSalt;
                metaSalt = cachedSalt;
            } else {
                // One-off mode: derive fresh keys (original behavior)
                fileSalt = self.crypto.getRandomValues(new Uint8Array(16));
                metaSalt = self.crypto.getRandomValues(new Uint8Array(16));
                fileKey = await deriveKey(password, fileSalt);
                metaKey = await deriveKey(password, metaSalt);
            }

            // 1. Encrypt file content
            const fileIv = self.crypto.getRandomValues(new Uint8Array(12));
            const encryptedContent = await self.crypto.subtle.encrypt(
                { name: 'AES-GCM', iv: fileIv },
                fileKey,
                fileBuffer
            );

            // Self-contained header: Salt (16B) + IV (12B) + Ciphertext
            const header = new Uint8Array(16 + 12);
            header.set(fileSalt, 0);
            header.set(fileIv, 16);

            const combined = new Uint8Array(header.byteLength + encryptedContent.byteLength);
            combined.set(header, 0);
            combined.set(new Uint8Array(encryptedContent), header.byteLength);

            // 2. Encrypt metadata
            const metaIv = self.crypto.getRandomValues(new Uint8Array(12));
            const metaJson = JSON.stringify({
                name: fileName,
                type: fileType || 'application/octet-stream',
                size: fileSize
            });

            const encoder = new TextEncoder();
            const encryptedMeta = await self.crypto.subtle.encrypt(
                { name: 'AES-GCM', iv: metaIv },
                metaKey,
                encoder.encode(metaJson)
            );

            self.postMessage({
                type: 'ENCRYPT_FILE_SUCCESS',
                payload: {
                    encryptedBuffer: combined.buffer,
                    metadata: {
                        encrypted_metadata: arrayBufferToBase64(encryptedMeta),
                        salt: arrayBufferToBase64(metaSalt.buffer),
                        iv: arrayBufferToBase64(metaIv.buffer)
                    }
                }
            }, [combined.buffer]);
        } else if (type === 'DECRYPT_FILE') {
            const { encryptedBuffer, password, metaJsonStr, metaSaltStr, metaIvStr } = payload;

            // 1. Decrypt metadata
            const metaSalt = new Uint8Array(base64ToArrayBuffer(metaSaltStr));
            const metaIv = new Uint8Array(base64ToArrayBuffer(metaIvStr));
            const metaCiphertext = base64ToArrayBuffer(metaJsonStr);

            let metaKey: CryptoKey;

            if (cachedKey && cachedSalt && arrayBufferToBase64(metaSalt.buffer) === arrayBufferToBase64(cachedSalt.buffer)) {
                metaKey = cachedKey;
            } else {
                metaKey = await deriveKey(password, metaSalt);
            }

            const decryptedMeta = await self.crypto.subtle.decrypt(
                { name: 'AES-GCM', iv: metaIv },
                metaKey,
                metaCiphertext
            );

            const decoder = new TextDecoder();
            const { name, type } = JSON.parse(decoder.decode(decryptedMeta));

            // 2. Decrypt file content
            const fileSalt = new Uint8Array(encryptedBuffer.slice(0, 16));
            const fileIv = new Uint8Array(encryptedBuffer.slice(16, 28));
            const ciphertext = encryptedBuffer.slice(28);

            let fileKey: CryptoKey;

            if (cachedKey && cachedSalt && arrayBufferToBase64(fileSalt.buffer) === arrayBufferToBase64(cachedSalt.buffer)) {
                fileKey = cachedKey;
            } else {
                fileKey = await deriveKey(password, fileSalt);
            }

            const decryptedContent = await self.crypto.subtle.decrypt(
                { name: 'AES-GCM', iv: fileIv },
                fileKey,
                ciphertext
            );

            self.postMessage({
                type: 'DECRYPT_FILE_SUCCESS',
                payload: {
                    decryptedBuffer: decryptedContent,
                    name,
                    type
                }
            }, [decryptedContent]);
        }
    } catch (error: any) {
        self.postMessage({
            type: 'ERROR',
            payload: {
                message: error.message || 'Worker operation failed'
            }
        });
    }
};
