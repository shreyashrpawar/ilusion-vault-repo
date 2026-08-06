export async function deriveKey(password: string, salt: Uint8Array, iterations: number = 600000): Promise<CryptoKey> {
    const encoder = new TextEncoder();
    const baseKey = await window.crypto.subtle.importKey(
        'raw',
        encoder.encode(password),
        'PBKDF2',
        false,
        ['deriveKey']
    );

    return window.crypto.subtle.deriveKey(
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

function arrayBufferToBase64(buffer: ArrayBuffer): string {
    let binary = '';
    const bytes = new Uint8Array(buffer);
    const len = bytes.byteLength;

    for (let i = 0; i < len; i++) {
        binary += String.fromCharCode(bytes[i]);
    }

    return window.btoa(binary);
}

export function base64ToArrayBuffer(base64: string): ArrayBuffer {
    const binaryString = window.atob(base64);
    const len = binaryString.length;
    const bytes = new Uint8Array(len);

    for (let i = 0; i < len; i++) {
        bytes[i] = binaryString.charCodeAt(i);
    }

    return bytes.buffer;
}

export async function encryptText(text: string, password: string): Promise<string> {
    const salt = window.crypto.getRandomValues(new Uint8Array(16));
    const iv = window.crypto.getRandomValues(new Uint8Array(12));
    const iterations = 600000;
    const key = await deriveKey(password, salt, iterations);

    const encoder = new TextEncoder();
    const encrypted = await window.crypto.subtle.encrypt(
        { name: 'AES-GCM', iv: iv },
        key,
        encoder.encode(text)
    );

    const result = {
        ciphertext: arrayBufferToBase64(encrypted),
        salt: arrayBufferToBase64(salt.buffer),
        iv: arrayBufferToBase64(iv.buffer),
        iterations: iterations
    };

    return JSON.stringify(result);
}

export async function decryptText(jsonStr: string, password: string): Promise<string> {
    try {
        const { ciphertext, salt, iv, iterations } = JSON.parse(jsonStr);
        const saltBuffer = base64ToArrayBuffer(salt);
        const ivBuffer = base64ToArrayBuffer(iv);
        const ciphertextBuffer = base64ToArrayBuffer(ciphertext);

        const key = await deriveKey(password, new Uint8Array(saltBuffer), iterations || 10000);
        const decrypted = await window.crypto.subtle.decrypt(
            { name: 'AES-GCM', iv: new Uint8Array(ivBuffer) },
            key,
            ciphertextBuffer
        );

        const decoder = new TextDecoder();

        return decoder.decode(decrypted);
    } catch {
        throw new Error('Incorrect decryption key or corrupted payload.');
    }
}

// ── Worker helpers ─────────────────────────────────────────────────

function createWorker(): Worker {
    return new Worker(new URL('./crypto.worker.ts', import.meta.url), { type: 'module' });
}

/**
 * Create a persistent worker that pre-derives the key once.
 * Use the returned object to encrypt/decrypt multiple files
 * without repeating the expensive PBKDF2 derivation.
 *
 * Usage:
 *   const batch = await createBatchWorker(passphrase);
 *   for (const file of files) {
 *     const result = await batch.encryptFile(file);
 *   }
 *   batch.terminate();
 */
export async function createBatchWorker(password: string): Promise<{
    encryptFile: (file: File) => Promise<{ encryptedBlob: Blob; metadata: { encrypted_metadata: string; salt: string; iv: string } }>;
    decryptFile: (encryptedBuffer: ArrayBuffer, metaJsonStr: string, metaSaltStr: string, metaIvStr: string) => Promise<{ decryptedFile: File; name: string; type: string }>;
    terminate: () => void;
}> {
    const worker = createWorker();

    // Wait for INIT to complete (key derivation happens here, once)
    await new Promise<void>((resolve, reject) => {
        const handler = (e: MessageEvent) => {
            worker.removeEventListener('message', handler);

            if (e.data.type === 'INIT_SUCCESS') {
                resolve();
            } else {
                reject(new Error(e.data.payload?.message || 'INIT failed'));
            }
        };
        worker.addEventListener('message', handler);
        worker.onerror = (e) => reject(e);
        worker.postMessage({ type: 'INIT', payload: { password } });
    });

    return {
        encryptFile(file: File) {
            return new Promise((resolve, reject) => {
                const handler = (e: MessageEvent) => {
                    worker.removeEventListener('message', handler);
                    const { type, payload } = e.data;

                    if (type === 'ENCRYPT_FILE_SUCCESS') {
                        const encryptedBlob = new Blob([payload.encryptedBuffer], { type: 'application/octet-stream' });
                        resolve({ encryptedBlob, metadata: payload.metadata });
                    } else {
                        reject(new Error(payload?.message || 'Encrypt failed'));
                    }
                };
                worker.addEventListener('message', handler);

                file.arrayBuffer().then((fileBuffer) => {
                    worker.postMessage({
                        type: 'ENCRYPT_FILE',
                        payload: {
                            fileBuffer,
                            fileName: file.name,
                            fileType: file.type,
                            fileSize: file.size
                        }
                    }, [fileBuffer]);
                }).catch(reject);
            });
        },

        decryptFile(encryptedBuffer: ArrayBuffer, metaJsonStr: string, metaSaltStr: string, metaIvStr: string) {
            return new Promise((resolve, reject) => {
                const handler = (e: MessageEvent) => {
                    worker.removeEventListener('message', handler);
                    const { type, payload } = e.data;

                    if (type === 'DECRYPT_FILE_SUCCESS') {
                        const decryptedBlob = new Blob([payload.decryptedBuffer], { type: payload.type });
                        const decryptedFile = new File([decryptedBlob], payload.name, { type: payload.type });
                        resolve({ decryptedFile, name: payload.name, type: payload.type });
                    } else {
                        reject(new Error(payload?.message || 'Decrypt failed'));
                    }
                };
                worker.addEventListener('message', handler);

                const bufferCopy = encryptedBuffer.slice(0);
                worker.postMessage({
                    type: 'DECRYPT_FILE',
                    payload: {
                        encryptedBuffer: bufferCopy,
                        metaJsonStr,
                        metaSaltStr,
                        metaIvStr
                    }
                }, [bufferCopy]);
            });
        },

        terminate() {
            worker.terminate();
        }
    };
}

// ── One-off file operations (for non-batch use) ────────────────────
// These spawn a fresh worker per operation. Use createBatchWorker()
// instead when processing multiple files with the same password.

export async function encryptFile(
    file: File,
    password: string
): Promise<{
    encryptedBlob: Blob;
    metadata: {
        encrypted_metadata: string;
        salt: string;
        iv: string;
    };
}> {
    return new Promise((resolve, reject) => {
        const worker = createWorker();

        worker.onmessage = (e) => {
            const { type, payload } = e.data;

            if (type === 'ENCRYPT_FILE_SUCCESS') {
                const { encryptedBuffer, metadata } = payload;
                const encryptedBlob = new Blob([encryptedBuffer], { type: 'application/octet-stream' });
                resolve({ encryptedBlob, metadata });
                worker.terminate();
            } else if (type === 'ERROR') {
                reject(new Error(payload.message));
                worker.terminate();
            }
        };

        worker.onerror = (e) => {
            reject(e);
            worker.terminate();
        };

        file.arrayBuffer().then((fileBuffer) => {
            worker.postMessage({
                type: 'ENCRYPT_FILE',
                payload: {
                    fileBuffer,
                    fileName: file.name,
                    fileType: file.type,
                    fileSize: file.size,
                    password
                }
            }, [fileBuffer]);
        }).catch(reject);
    });
}

export async function decryptFile(
    encryptedBuffer: ArrayBuffer,
    password: string,
    metaJsonStr: string,
    metaSaltStr: string,
    metaIvStr: string
): Promise<{ decryptedFile: File; name: string; type: string }> {
    return new Promise((resolve, reject) => {
        const worker = createWorker();

        worker.onmessage = (e) => {
            const { type, payload } = e.data;

            if (type === 'DECRYPT_FILE_SUCCESS') {
                const { decryptedBuffer, name, type: fileType } = payload;
                const decryptedBlob = new Blob([decryptedBuffer], { type: fileType });
                const decryptedFile = new File([decryptedBlob], name, { type: fileType });
                resolve({ decryptedFile, name, type: fileType });
                worker.terminate();
            } else if (type === 'ERROR') {
                reject(new Error(payload.message));
                worker.terminate();
            }
        };

        worker.onerror = (e) => {
            reject(e);
            worker.terminate();
        };

        const bufferCopy = encryptedBuffer.slice(0);
        worker.postMessage({
            type: 'DECRYPT_FILE',
            payload: {
                encryptedBuffer: bufferCopy,
                password,
                metaJsonStr,
                metaSaltStr,
                metaIvStr
            }
        }, [bufferCopy]);
    });
}
