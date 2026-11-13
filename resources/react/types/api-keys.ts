/** Uma chave de API na lista (App\Http\Controllers\Panel\ApiKeysController) — nunca a secreta. */
export type ApiKeyItem = {
    uuid: string;
    name: string;
    publicKey: string;
    status: string;
    statusLabel: string;
    usable: boolean;
    restricted: boolean;
    projects: ProjectOption[];
    lastUsedAt: string | null;
    expiresAt: string | null;
};

export type ProjectOption = {
    uuid: string;
    name: string;
};

/** A validade escolhida na criação: sem validade, N horas ou N dias. */
export type ApiKeyExpiryUnit = 'none' | 'hours' | 'days';

/** As unidades e os tetos da validade (`expiryOptions` do controller). */
export type ApiKeyExpiryOptions = {
    units: ApiKeyExpiryUnit[];
    maxHours: number;
    maxDays: number;
};

/**
 * Os dados do formulário de criação (os nomes que o servidor valida). Com
 * `expiryOptions` (controller da 2.3.0 em diante), a validade vai em
 * `expiry_unit`/`expiry_amount`; sem ela (cópia anterior do controller), em
 * `expires_at`.
 */
export type ApiKeyFormData = {
    name: string;
    expires_at: string;
    expiry_unit: ApiKeyExpiryUnit;
    expiry_amount: string;
    all_scopes: boolean;
    scopes: string[];
    project_uuids: string[];
};
