/**
 * Validadores puros para feedback de formularios on-blur.
 *
 * Cada regla es `(value) => string | null`: devuelve el mensaje de error
 * (en espanol, tono de marca) o `null` si el valor es valido. Las reglas con
 * parametros (minLength, matches) son factories que devuelven una regla.
 *
 * Convencion clave: las reglas NO opinan sobre el vacio (eso lo cubre
 * `required`), asi se componen sin que un campo opcional vacio dispare
 * "formato invalido".
 *
 * Esta es la capa de feedback rapido del cliente; NO reemplaza la validacion
 * del server, que sigue siendo la fuente de verdad (ver useFieldValidation).
 *
 * Portado del patron de POSLatam, adaptado a tokens semanticos de Eternova.
 */

export type ValidationRule = (value: unknown) => string | null

const isEmpty = (value: unknown): boolean =>
    value === null || value === undefined || String(value).trim() === ''

export const required: ValidationRule = (value) =>
    isEmpty(value) ? 'Este campo es obligatorio.' : null

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export const email: ValidationRule = (value) => {
    if (isEmpty(value)) {
        return null
    }
    return EMAIL_RE.test(String(value).trim())
        ? null
        : 'Ingresá un correo electrónico válido.'
}

export const minLength = (n: number): ValidationRule => (value) => {
    if (isEmpty(value)) {
        return null
    }
    return String(value).length >= n ? null : `Debe tener al menos ${n} caracteres.`
}

/**
 * Compara contra un getter (no un valor fijo) para que la regla siga
 * reflejando el otro campo aunque este cambie despues de crear la regla.
 * Pensado para la confirmacion de contrasena.
 */
export const matches = (
    getOther: () => unknown,
    message = 'Los valores no coinciden.',
): ValidationRule => (value) => {
    if (isEmpty(value)) {
        return null
    }
    return value === getOther() ? null : message
}

export interface PasswordStrength {
    score: number // 0..4
    label: string
    percent: number // 0..100
    color: string // clase de token semantico (bg-error/bg-warning/bg-success)
}

/**
 * Medidor de fuerza de contrasena (advisory, NO bloquea el submit).
 * Devuelve { score, label, percent, color } para el meter. Los colores usan
 * tokens semanticos de Eternova, nunca hex/palette literal (regla de brand tokens).
 */
export function passwordStrength(value: unknown): PasswordStrength {
    const str = value == null ? '' : String(value)

    if (str.length === 0) {
        return { score: 0, label: '', percent: 0, color: '' }
    }

    let points = 0
    if (str.length >= 8) points += 1
    if (str.length >= 12) points += 1
    if (/[a-z]/.test(str) && /[A-Z]/.test(str)) points += 1
    if (/\d/.test(str)) points += 1
    if (/[^A-Za-z0-9]/.test(str)) points += 1

    const score = points >= 4 ? 4 : points

    const labels = ['Muy débil', 'Débil', 'Aceptable', 'Buena', 'Fuerte']
    const colors = ['bg-error', 'bg-error', 'bg-warning', 'bg-success', 'bg-success']

    return {
        score,
        label: labels[score],
        percent: Math.round((score / 4) * 100),
        color: colors[score],
    }
}
