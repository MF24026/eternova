import { reactive } from 'vue'
import type { ValidationRule } from '@/utils/validators'

/**
 * Validacion de formularios on-blur (capa de feedback rapido del cliente).
 *
 * En `@blur` se valida ese campo y, si falla, se muestra el error inline; al
 * corregir, se limpia. NO reemplaza la validacion del server (la respuesta 422
 * del API sigue siendo la fuente de verdad); esto es solo el feedback inmediato.
 *
 * Uso:
 *   const v = useFieldValidation({
 *     email: [required, email],
 *     password: [required, minLength(8)],
 *   })
 *   <AppInput v-model="form.email" :error="v.errors.email || serverErrors.email"
 *             @blur="v.validateField('email', form.email)" />
 *   // en submit: if (!v.validateAll(form)) return
 */
export type ValidationSchema = Record<string, ValidationRule[]>

export function useFieldValidation(schema: ValidationSchema) {
    const errors = reactive<Record<string, string>>({})
    const touched = reactive<Record<string, boolean>>({})

    const runRules = (field: string, value: unknown): string | null => {
        for (const rule of schema[field] ?? []) {
            const error = rule(value)
            if (error) {
                return error
            }
        }
        return null
    }

    const apply = (field: string, value: unknown): boolean => {
        const error = runRules(field, value)
        if (error) {
            errors[field] = error
        } else {
            delete errors[field]
        }
        return !error
    }

    const validateField = (field: string, value: unknown): boolean => {
        touched[field] = true
        return apply(field, value)
    }

    const validateAll = (values: Record<string, unknown> = {}): boolean => {
        let valid = true
        for (const field of Object.keys(schema)) {
            touched[field] = true
            if (!apply(field, values[field])) {
                valid = false
            }
        }
        return valid
    }

    const clearField = (field: string): void => {
        delete errors[field]
    }

    const reset = (): void => {
        Object.keys(errors).forEach((key) => delete errors[key])
        Object.keys(touched).forEach((key) => delete touched[key])
    }

    return { errors, touched, validateField, validateAll, clearField, reset }
}
